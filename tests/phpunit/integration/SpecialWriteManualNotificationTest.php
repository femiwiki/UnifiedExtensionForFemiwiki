<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use MediaWiki\Exception\PermissionsError;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\ManualNotification\ManualNotificationJob;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\Special\SpecialWriteManualNotification;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Specials\SpecialPageTestBase;
use MediaWiki\Title\Title;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Database
 * @group Standalone
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\Special\SpecialWriteManualNotification
 */
class SpecialWriteManualNotificationTest extends SpecialPageTestBase {

	/** @inheritDoc */
	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()
			->getPage( 'WriteManualNotification' );
	}

	private function countQueuedJobs(): int {
		return $this->getServiceContainer()->getJobQueueGroup()
			->get( ManualNotificationJob::COMMAND )->getSize();
	}

	private function countLogEntries(): int {
		return (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'logging' )
			->where( [ 'log_type' => SpecialWriteManualNotification::LOG_TYPE ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	private function submit( array $data ): string {
		[ $html ] = $this->executeSpecialPage(
			'', new FauxRequest( $data, true ), 'qqx', $this->getTestSysop()->getAuthority()
		);
		return $html;
	}

	public function testPreviewsBeforeSendingAndRefusesDuplicates() {
		$this->setGroupPermissions( 'fwtest-special', 'read', true );
		$member = $this->getMutableTestUser()->getUser();
		$this->getServiceContainer()->getUserGroupManager()->addUserToGroup( $member, 'fwtest-special' );
		$page = $this->getExistingTestPage( 'Manual notification page' )->getTitle();
		$data = [
			'wpTargetGroup' => 'fwtest-special',
			'wpHeader' => 'Header',
			'wpBody' => 'Body',
			'wpPage' => $page->getPrefixedText(),
		];

		$html = $this->submit( $data );
		$this->assertStringContainsString( '(writemanualnotification-confirm: 1, ', $html );
		$this->assertSame( 0, $this->countQueuedJobs() );

		$hash = SpecialWriteManualNotification::hash( 'fwtest-special', 'Header', 'Body', $page );
		$changed = [ 'wpHeader' => 'Other header', 'wpPreviewHash' => $hash ] + $data;
		$this->assertStringContainsString( '(writemanualnotification-confirm:', $this->submit( $changed ) );
		$this->assertSame( 0, $this->countQueuedJobs() );

		$html = $this->submit( [ 'wpPreviewHash' => $hash ] + $data );
		$this->assertStringContainsString( '(writemanualnotification-queued: 1)', $html );
		$this->assertSame( 1, $this->countQueuedJobs() );
		$this->assertSame( 1, $this->countLogEntries() );

		$html = $this->submit( [ 'wpPreviewHash' => $hash ] + $data );
		$this->assertStringContainsString( '(writemanualnotification-duplicate)', $html );
		$this->assertSame( 1, $this->countQueuedJobs() );
		$this->assertSame( 1, $this->countLogEntries() );
	}

	public function testNeedsTheRight() {
		$this->expectException( PermissionsError::class );
		$this->executeSpecialPage(
			'', new FauxRequest(), 'qqx', $this->getTestUser()->getAuthority()
		);
	}

	public function testHashChangesWithEveryField() {
		$page = Title::makeTitle( NS_MAIN, 'A' );
		$base = SpecialWriteManualNotification::hash( '', 'h', 'b', $page );
		$this->assertNotSame( $base, SpecialWriteManualNotification::hash( 'sysop', 'h', 'b', $page ) );
		$this->assertNotSame( $base, SpecialWriteManualNotification::hash( '', 'h2', 'b', $page ) );
		$this->assertNotSame( $base, SpecialWriteManualNotification::hash( '', 'h', 'b2', $page ) );
		$this->assertNotSame(
			$base, SpecialWriteManualNotification::hash( '', 'h', 'b', Title::makeTitle( NS_MAIN, 'B' ) )
		);
	}
}
