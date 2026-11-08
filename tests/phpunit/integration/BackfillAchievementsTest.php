<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\RankingAchievement;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\Maintenance\BackfillAchievements;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\User\User;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Database
 * @group Standalone
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\Maintenance\BackfillAchievements
 */
class BackfillAchievementsTest extends MaintenanceBaseTestCase {

	private const KEY = RankingAchievement::ACHIEVEMENT_KEY;

	protected function getMaintenanceClass() {
		return BackfillAchievements::class;
	}

	protected function setUp(): void {
		parent::setUp();
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'AchievementBadges' ) ) {
			$this->markTestSkipped( 'AchievementBadges is not installed' );
		}
		$this->overrideConfigValue( 'AchievementBadgesAchievements', [
			self::KEY => [ 'type' => 'instant' ],
		] );
	}

	private function backfill( array $data, string ...$options ): void {
		$file = $this->getNewTempFile();
		file_put_contents( $file, json_encode( $data ) );
		$this->maintenance->loadWithArgv( [ ...$options, $file ] );
		$this->maintenance->execute();
	}

	private function timestamps( User $user ): array {
		return $this->getDb()->newSelectQueryBuilder()
			->select( 'log_timestamp' )
			->from( 'logging' )
			->where( [ 'log_action' => self::KEY, 'log_actor' => $user->getActorId() ] )
			->caller( __METHOD__ )
			->fetchFieldValues();
	}

	public function testAddsAtTheGivenTime() {
		$user = $this->getMutableTestUser()->getUser();
		$this->backfill( [ self::KEY => [
			$user->getName() => '2016-08-29T21:00:02Z',
			'No such user' => '2017-01-01T00:00:00Z',
		] ] );
		$this->assertSame( [ '20160829210002' ], $this->timestamps( $user ) );

		$this->backfill( [ self::KEY => [ $user->getName() => '2016-08-29T21:00:02Z' ] ] );
		$this->assertSame( [ '20160829210002' ], $this->timestamps( $user ), 'Nothing is added twice' );
	}

	public function testMovesALaterAwardBack() {
		$user = $this->getMutableTestUser()->getUser();
		$entry = new ManualLogEntry( 'achievementbadges', self::KEY );
		$entry->setPerformer( $user );
		$entry->setTarget( SpecialPage::getTitleFor( 'Achievements' ) );
		$entry->setParameters( [ '4::key' => self::KEY ] );
		$entry->insert();

		$this->backfill( [ self::KEY => [ $user->getName() => '2018-03-01T00:00:00Z' ] ] );
		$this->assertSame( [ '20180301000000' ], $this->timestamps( $user ) );
	}

	public function testDryRunChangesNothing() {
		$user = $this->getMutableTestUser()->getUser();
		$this->backfill( [ self::KEY => [ $user->getName() => '2016-08-29T21:00:02Z' ] ], '--dry-run' );
		$this->assertSame( [], $this->timestamps( $user ) );
	}

	public function testRejectsUnknownAchievements() {
		$this->expectCallToFatalError();
		$this->backfill( [ 'no-such-achievement' => [] ] );
	}
}
