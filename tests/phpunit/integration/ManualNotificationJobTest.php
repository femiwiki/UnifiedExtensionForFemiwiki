<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Extension\Notifications\Mapper\EventMapper;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\ManualNotification\ManualNotificationJob;
use MediaWiki\User\UserIdentity;
use MediaWikiIntegrationTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Database
 * @group Standalone
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\ManualNotification\ManualNotificationJob
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\ManualNotification
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\ManualNotification\ManualNotificationPresentationModel
 */
class ManualNotificationJobTest extends MediaWikiIntegrationTestCase {

	private function countNotifications( UserIdentity $user ): int {
		return (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'echo_notification' )
			->join( 'echo_event', null, 'event_id = notification_event' )
			->where( [
				'notification_user' => $user->getId(),
				'event_type' => 'write-manual-notification',
			] )
			->caller( __METHOD__ )
			->fetchField();
	}

	public function testDeliversEveryBatchAndRespectsPreferences() {
		$this->overrideConfigValue( 'EchoUseJobQueue', false );
		$services = $this->getServiceContainer();
		$groupManager = $services->getUserGroupManager();
		$users = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$users[] = $user = $this->getMutableTestUser()->getUser();
			$groupManager->addUserToGroup( $user, 'fwtest-notify' );
		}
		$outsider = $this->getMutableTestUser()->getUser();
		$optedOut = $users[2];
		$userOptionsManager = $services->getUserOptionsManager();
		$userOptionsManager->setOption( $optedOut, 'echo-subscriptions-web-write-manual-notification', false );
		$userOptionsManager->saveOptions( $optedOut );

		$page = $this->getExistingTestPage( 'Manual notification target' )->getTitle();
		$sysop = $this->getTestSysop()->getUser();
		$services->getJobQueueGroup()->push( ManualNotificationJob::newSpec( [
			'group' => 'fwtest-notify',
			'header' => '<b>Hello</b> [[Link]]',
			'body' => 'Body',
			'namespace' => $page->getNamespace(),
			'title' => $page->getDBkey(),
			'agentId' => $sysop->getId(),
			'agentName' => $sysop->getName(),
			'afterId' => 0,
			'batchSize' => 2,
		] ) );
		$this->runJobs( [ 'minJobs' => 3 ], [ 'type' => ManualNotificationJob::COMMAND ] );

		foreach ( $users as $user ) {
			$this->assertSame( $user === $optedOut ? 0 : 1, $this->countNotifications( $user ), $user->getName() );
		}
		$this->assertSame( 0, $this->countNotifications( $outsider ) );

		$eventId = (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'MAX(event_id)' )
			->from( 'echo_event' )
			->where( [ 'event_type' => 'write-manual-notification' ] )
			->caller( __METHOD__ )
			->fetchField();
		$event = ( new EventMapper() )->fetchById( $eventId );
		$model = EchoEventPresentationModel::factory(
			$event, $services->getLanguageFactory()->getLanguage( 'en' ), $users[0]
		);
		$this->assertTrue( $model->canRender() );
		$this->assertStringContainsString( '&lt;b&gt;Hello&lt;/b&gt; [[Link]]', $model->getHeaderMessage()->parse() );
		$this->assertSame( $page->getFullURL(), $model->getPrimaryLink()['url'] );
	}
}
