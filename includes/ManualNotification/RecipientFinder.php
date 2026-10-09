<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\ManualNotification;

use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Finds who a manual notification goes to: every registered user when the group
 * is empty, otherwise the unexpired members of that group.
 */
class RecipientFinder {

	public function __construct(
		private IConnectionProvider $dbProvider
	) {
	}

	public function count( string $group ): int {
		$dbr = $this->dbProvider->getReplicaDatabase();
		return (int)$this->newQueryBuilder( $dbr, $group )
			->select( 'COUNT(*)' )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * @param string $group
	 * @param int $afterId Only users with a larger ID
	 * @param int $limit
	 * @return UserIdentity[] Ordered by user ID
	 */
	public function getBatch( string $group, int $afterId, int $limit ): array {
		$dbr = $this->dbProvider->getReplicaDatabase();
		$res = $this->newQueryBuilder( $dbr, $group )
			->select( [ 'user_id', 'user_name' ] )
			->andWhere( $dbr->expr( 'user_id', '>', $afterId ) )
			->orderBy( 'user_id' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();
		$users = [];
		foreach ( $res as $row ) {
			$users[] = new UserIdentityValue( (int)$row->user_id, $row->user_name );
		}
		return $users;
	}

	private function newQueryBuilder( IReadableDatabase $dbr, string $group ): SelectQueryBuilder {
		$qb = $dbr->newSelectQueryBuilder()->from( 'user' );
		if ( $group !== '' ) {
			$qb->join( 'user_groups', null, 'ug_user = user_id' )
				->where( [ 'ug_group' => $group ] )
				->andWhere(
					$dbr->expr( 'ug_expiry', '=', null )
						->or( 'ug_expiry', '>', $dbr->timestamp() )
				);
		}
		return $qb;
	}
}
