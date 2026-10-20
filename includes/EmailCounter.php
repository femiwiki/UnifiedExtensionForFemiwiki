<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki;

use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Counts the distinct email addresses of registered users, as an estimate of how many
 * people have joined that multiple accounts of one person do not inflate.
 */
class EmailCounter {

	/** As long as the parser cache keeps core's NUMBEROFUSERS */
	public const TTL = 3600;

	public function __construct(
		private IConnectionProvider $dbProvider,
		private WANObjectCache $cache
	) {
	}

	/**
	 * @param bool $authenticatedOnly Count only the addresses whose owners confirmed them
	 * @return int
	 */
	public function count( bool $authenticatedOnly ): int {
		$fname = __METHOD__;
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey(
				'unifiedextensionforfemiwiki-numberofemails',
				$authenticatedOnly ? 'authenticated' : 'all'
			),
			self::TTL,
			function () use ( $authenticatedOnly, $fname ) {
				$dbr = $this->dbProvider->getReplicaDatabase();
				$query = $dbr->newSelectQueryBuilder()
					->select( 'COUNT(DISTINCT user_email)' )
					->from( 'user' )
					->where( $dbr->expr( 'user_email', '!=', '' ) );
				if ( $authenticatedOnly ) {
					$query->andWhere( $dbr->expr( 'user_email_authenticated', '!=', null ) );
				}
				return (int)$query->caller( $fname )->fetchField();
			}
		);
	}
}
