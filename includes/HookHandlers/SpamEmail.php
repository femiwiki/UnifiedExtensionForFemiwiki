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
				$result = false;
				return false;
			}
		}

		// Check email addresses of block users
		$emails = array_filter( array_unique( array_map(
			static function ( $block ) {
				$id = $block->getBlocker();
				if ( $id ) {
					return User::newFromIdentity( $id )->getEmail();
				}
				return null;
			},
			$this->databaseBlockStore->newListFromConds( [] )
		) ) );
		if ( in_array( $addr, $emails ) ) {
			$result = false;
			return false;
		}
	}
}
