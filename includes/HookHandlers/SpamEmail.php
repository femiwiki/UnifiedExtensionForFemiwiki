<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use Config;
use MediaWiki\Block\DatabaseBlockStore;
use MediaWiki\Extension\SpamBlacklist\BaseBlacklist;
use MediaWiki\User\User;
use WANObjectCache;
use Wikimedia\AtEase\AtEase;
use Wikimedia\Rdbms\ILoadBalancer;

class SpamEmail implements
	\MediaWiki\Hook\IsValidEmailAddrHook
	{

	/** @var Config */
	private $config;

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var DatabaseBlockStore */
	private $databaseBlockStore;

	/** @var WANObjectCache */
	private $wanCache;

	/**
	 * @param Config $config
	 * @param ILoadBalancer $loadBalancer
	 * @param DatabaseBlockStore $databaseBlockStore
	 * @param WANObjectCache $wanCache
	 *
	 */
	public function __construct(
		Config $config,
		ILoadBalancer $loadBalancer,
		DatabaseBlockStore $databaseBlockStore,
		WANObjectCache $wanCache
		) {
		$this->config = $config;
		$this->loadBalancer = $loadBalancer;
		$this->databaseBlockStore = $databaseBlockStore;
		$this->wanCache = $wanCache;
	}

	/** @inheritDoc */
	public function onIsValidEmailAddr( $addr, &$result ) {
		if ( !$this->config->get( 'UnifiedExtensionForFemiwikiBlockByEmail' ) ) {
			return true;
		}

		// Check againt MediaWiki:Email-blacklist
		$denylist = BaseBlacklist::getEmailBlacklist()->getBlacklists();
		foreach ( $denylist as $regex ) {
			AtEase::suppressWarnings();
			$match = preg_match( $regex, $addr );
			AtEase::restoreWarnings();
			if ( $match ) {
				return false;
			}
		}

		// Check email addresses of block users
		if ( version_compare( '1.42', MW_VERSION, '<=' ) ) {
			$emails = array_filter( array_unique( array_map(
				static fn( $block ) => User::newFromIdentity( $block->getBlocker() )->getEmail(),
				$this->databaseBlockStore->newListFromConds( [ 'bt_user IS NOT NULL' ] )
			) ) );
		} else {
			$loadBalancer = $this->loadBalancer;
			$emails = $this->wanCache->getWithSetCallback(
				$this->wanCache->makeKey( 'unified-femiwiki-extension-blocked-email' ),
				WANObjectCache::TTL_HOUR,
				static function ( $old, &$ttl, array &$setOpts ) use ( $loadBalancer ) {
					$dbr = $loadBalancer->getConnectionRef( ILoadBalancer::DB_REPLICA );
					return $dbr->newSelectQueryBuilder()
						->distinct()
						->fields( [ 'user_email' ] )
						->tables( [ 'ipblocks' ] )
						->join( 'user', 'user_id = ipb_user' )
						->conds( [
							'ipb_user IS NOT NULL',
							'user_email != ' . $dbr->addQuotes( '' ),
							'ipb_expiry > ' . $dbr->addQuotes( $dbr->timestamp() ),
						] )
						->fetchFieldValues();
				}
			);
			if ( !is_array( $emails ) ) {
				$emails = [];
			}
		}
		if ( in_array( $addr, $emails ) ) {
			return false;
		}
	}
}
