<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\ManualNotification\RecipientFinder;
use MediaWiki\User\UserIdentity;
use MediaWikiIntegrationTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Database
 * @group Standalone
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\ManualNotification\RecipientFinder
 */
class RecipientFinderTest extends MediaWikiIntegrationTestCase {

	private function newFinder(): RecipientFinder {
		return new RecipientFinder( $this->getServiceContainer()->getConnectionProvider() );
	}

	/**
	 * @param UserIdentity[] $users
	 * @return int[]
	 */
	private static function ids( array $users ): array {
		return array_map( static fn ( UserIdentity $u ) => $u->getId(), $users );
	}

	public function testEmptyGroupMeansEveryUserEvenWithoutAGroup() {
		$this->getMutableTestUser()->getUser();
		$this->getMutableTestUser( [ 'sysop' ] )->getUser();
		$total = (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'user' )->caller( __METHOD__ )->fetchField();

		$this->assertSame( $total, $this->newFinder()->count( '' ) );
		$this->assertCount( $total, $this->newFinder()->getBatch( '', 0, $total + 10 ) );
	}

	public function testGroupSkipsExpiredMemberships() {
		$current = $this->getMutableTestUser()->getUser();
		$expired = $this->getMutableTestUser()->getUser();
		$groupManager = $this->getServiceContainer()->getUserGroupManager();
		$groupManager->addUserToGroup( $current, 'fwtest-expiry' );
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'user_groups' )
			->row( [
				'ug_user' => $expired->getId(),
				'ug_group' => 'fwtest-expiry',
				'ug_expiry' => $this->getDb()->timestamp( '20000101000000' ),
			] )
			->caller( __METHOD__ )
			->execute();

		$finder = $this->newFinder();
		$this->assertSame( 1, $finder->count( 'fwtest-expiry' ) );
		$this->assertSame( [ $current->getId() ], self::ids( $finder->getBatch( 'fwtest-expiry', 0, 10 ) ) );
	}

	public function testBatchesWalkUserIdsInOrder() {
		$users = [];
		$groupManager = $this->getServiceContainer()->getUserGroupManager();
		for ( $i = 0; $i < 5; $i++ ) {
			$users[] = $user = $this->getMutableTestUser()->getUser();
			$groupManager->addUserToGroup( $user, 'fwtest-batch' );
		}
		$expected = self::ids( $users );
		sort( $expected );

		$finder = $this->newFinder();
		$first = self::ids( $finder->getBatch( 'fwtest-batch', 0, 2 ) );
		$second = self::ids( $finder->getBatch( 'fwtest-batch', end( $first ), 2 ) );
		$third = self::ids( $finder->getBatch( 'fwtest-batch', end( $second ), 2 ) );
		$this->assertSame( $expected, array_merge( $first, $second, $third ) );
		$this->assertSame( [], $finder->getBatch( 'fwtest-batch', end( $third ), 2 ) );
	}
}
