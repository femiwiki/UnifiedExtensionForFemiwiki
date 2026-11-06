<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\RankingAchievement;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Database
 * @group Standalone
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\RankingAchievement
 */
class RankingAchievementTest extends MediaWikiIntegrationTestCase {

	private const PAGE = 'Project:Ranking';

	protected function setUp(): void {
		parent::setUp();
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'AchievementBadges' ) ) {
			$this->markTestSkipped( 'AchievementBadges is not installed' );
		}
		$this->overrideConfigValues( [
			'UnifiedExtensionForFemiwikiRankingPages' => [ self::PAGE ],
			'AchievementBadgesEnableBetaFeature' => false,
			// The registry is built once at setup, so add the badge as the hook would have
			'AchievementBadgesAchievements' => [
				RankingAchievement::ACHIEVEMENT_KEY => [ 'type' => 'instant' ],
			],
		] );
	}

	private function saveRanking( User $author, User ...$users ): void {
		$rows = array_map(
			static fn ( User $u ) => "|-\n| 1 || [[Special:Contributions/{$u->getName()}|{$u->getName()}]]",
			$users
		);
		// Links to other special pages are ignored
		$rows[] = '[[Special:RecentChanges]] [[Special:Contributions]]';
		$this->editPage( self::PAGE, implode( "\n", $rows ) . "\n" . microtime(), '', NS_MAIN, $author );
		$this->runDeferredUpdates();
	}

	private function countBadges( User $user ): int {
		return $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'logging' )
			->where( [
				'log_type' => 'achievementbadges',
				'log_action' => RankingAchievement::ACHIEVEMENT_KEY,
				'log_actor' => $user->getActorId(),
			] )
			->caller( __METHOD__ )
			->fetchRowCount();
	}

	public function testRegistersBadgeOnlyWhenConfigured() {
		$achievements = [];
		$this->getServiceContainer()->getHookContainer()->run( 'BeforeCreateAchievement', [ &$achievements ] );
		$this->assertArrayHasKey( RankingAchievement::ACHIEVEMENT_KEY, $achievements );

		// Overriding resets the services, so the handler below sees the new value
		$this->overrideConfigValue( 'UnifiedExtensionForFemiwikiRankingPages', [] );
		$achievements = [];
		$this->getServiceContainer()->getHookContainer()->run( 'BeforeCreateAchievement', [ &$achievements ] );
		$this->assertArrayNotHasKey( RankingAchievement::ACHIEVEMENT_KEY, $achievements );
	}

	public function testBotRankingAwardsOnce() {
		$bot = $this->getTestUser( [ 'bot' ] )->getUser();
		$first = $this->getMutableTestUser()->getUser();
		$second = $this->getMutableTestUser()->getUser();

		$this->saveRanking( $bot, $first );
		$this->assertSame( 1, $this->countBadges( $first ) );
		$this->assertSame( 0, $this->countBadges( $second ) );

		$this->saveRanking( $bot, $second, $first );
		$this->assertSame( 1, $this->countBadges( $first ), 'A user earns the badge only once' );
		$this->assertSame( 1, $this->countBadges( $second ) );
	}

	public function testEditsByOthersAwardNothing() {
		$editor = $this->getMutableTestUser()->getUser();

		$this->saveRanking( $editor, $editor );
		$this->assertSame( 0, $this->countBadges( $editor ) );
	}

	public function testOtherPagesAwardNothing() {
		$bot = $this->getTestUser( [ 'bot' ] )->getUser();
		$user = $this->getMutableTestUser()->getUser();

		$this->editPage( 'Project:Elsewhere', "[[Special:Contributions/{$user->getName()}]]", '', NS_MAIN, $bot );
		$this->runDeferredUpdates();
		$this->assertSame( 0, $this->countBadges( $user ) );
	}
}
