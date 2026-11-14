<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use MediaWiki\Extension\CommunityConfiguration\Provider\ConfigurationProviderFactory;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsExpensiveHook;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\TempUser\TempUserConfig;
use MediaWiki\User\User;
use Wikimedia\Message\MessageValue;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Turns away an edit without an account outside the main namespace, or once the day's
 * allowance is spent.
 *
 * The check is a permission, so the editor says why before anything is typed: the wikitext
 * editor shows it in place of the edit form and VisualEditor shows it when it loads. A rate
 * limit or an edit filter would only say so on save, after the text is written.
 *
 * Edits are counted over the last 24 hours in recentchanges, both by the temporary account and
 * by the IP address across all temporary accounts, so clearing cookies for a new temporary
 * account does not reset the count.
 */
class LoggedOutEditLimit implements BeforePageDisplayHook, GetUserPermissionsErrorsExpensiveHook {

	/** CommunityConfiguration provider of the limit */
	public const PROVIDER = 'FemiwikiLoggedOutEditing';

	public const LIMITED = 'unifiedextensionforfemiwiki-logged-out-edit-limit';

	public const CLOSED = 'unifiedextensionforfemiwiki-logged-out-editing-closed';

	public const NAMESPACE_CLOSED = 'unifiedextensionforfemiwiki-logged-out-editing-namespace';

	private const PERIOD = 86400;

	public function __construct(
		private IConnectionProvider $connectionProvider,
		private ActorNormalization $actorNormalization,
		private TempUserConfig $tempUserConfig,
		private ?ConfigurationProviderFactory $providerFactory = null
	) {
	}

	/**
	 * Checks the limit when an edit link is followed, so a dialog says so before the editor opens.
	 *
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !$out->getUser()->isNamed() && $this->tempUserConfig->isEnabled()
			&& $out->getActionName() === 'view'
		) {
			$out->addModules( 'ext.unifiedExtensionForFemiwiki.loggedOutEditLimit' );
		}
	}

	/** @inheritDoc */
	public function onGetUserPermissionsErrorsExpensive( $title, $user, $action, &$result ) {
		if ( $action !== 'edit' || $user->isNamed() || !$this->tempUserConfig->isEnabled()
			// Already refused, as when logged-out editing is closed; no need to count
			|| !$user->isAllowed( 'edit' )
		) {
			return true;
		}
		$limit = $this->getLimit();
		if ( $limit === 0 ) {
			// Closed on the wiki, without a deploy
			$result = MessageValue::new( self::CLOSED );
			return false;
		}
		if ( !$title->inNamespace( NS_MAIN ) ) {
			// Talk pages, templates, project pages and the rest need an account
			$result = MessageValue::new( self::NAMESPACE_CLOSED );
			return false;
		}
		if ( $limit === null ) {
			return true;
		}
		if ( $this->countEdits( $user ) < $limit ) {
			return true;
		}
		$result = MessageValue::new( self::LIMITED )
			->numParams( $limit );
		return false;
	}

	/**
	 * @return int|null Edits allowed in 24 hours, 0 when logged-out editing is closed, or null
	 *  for no limit
	 */
	private function getLimit(): ?int {
		if ( !$this->providerFactory || !$this->providerFactory->isProviderSupported( self::PROVIDER ) ) {
			return null;
		}
		$status = $this->providerFactory->newProvider( self::PROVIDER )->loadValidConfiguration();
		if ( !$status->isOK() ) {
			// The provider logs the reason
			return null;
		}
		$limit = $status->getValue()->DailyEditLimit ?? null;
		return $limit === null ? null : (int)$limit;
	}

	private function countEdits( User $user ): int {
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$actorId = $user->isRegistered() ? $this->actorNormalization->findActorId( $user, $dbr ) : null;
		$ip = $user->getRequest()->getIP();

		$byWho = [
			$dbr->expr( 'rc_ip', '=', $ip )
				->andExpr( $this->tempUserConfig->getMatchCondition( $dbr, 'actor_name', IExpression::LIKE ) ),
		];
		if ( $actorId ) {
			$byWho[] = $dbr->expr( 'rc_actor', '=', $actorId );
		}
		return (int)$dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'recentchanges' )
			->join( 'actor', null, 'actor_id = rc_actor' )
			->where( [
				'rc_source' => [ RecentChange::SRC_EDIT, RecentChange::SRC_NEW ],
				$dbr->expr( 'rc_timestamp', '>=',
					$dbr->timestamp( ConvertibleTimestamp::time() - self::PERIOD ) ),
				$dbr->orExpr( $byWho ),
			] )
			->caller( __METHOD__ )
			->fetchField();
	}
}
