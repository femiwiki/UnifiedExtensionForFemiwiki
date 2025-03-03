<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki;

use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use MediaWiki\User\UserArrayFromResult;

class UserLocator {
	/**
	 * @param Event $event
	 * @return array
	 */
	public static function locateUsersInGroup( Event $event ) {
		$services = MediaWikiServices::getInstance();
		$dbr = $services->getConnectionProvider()->getReplicaDatabase();
		$queryBuilder = User::newQueryBuilder( $dbr )
			->caller( __METHOD__ )
			->join( 'user_groups', null, [ 'ug_user = user_id' ] );
		$group = $event->getExtraParam( 'usergroup' );
		if ( $group != '' ) {
			$queryBuilder->where( [ 'ug_group' => $group ] );
		}
		$res = $queryBuilder->fetchResultSet();
		$users = new UserArrayFromResult( $res );
		return $users;
	}
}
