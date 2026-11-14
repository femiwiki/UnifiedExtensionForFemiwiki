<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\LoggedOutEditLimit;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Database
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\LoggedOutEditLimit
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\LoggedOutEditing\LoggedOutEditingSchema
 */
class LoggedOutEditLimitTest extends MediaWikiIntegrationTestCase {
	use TempUserTestTrait;

	private const MESSAGE = 'unifiedextensionforfemiwiki-logged-out-edit-limit';

	/** Makes every edit change the text, since saving the same text again is a null edit */
	private int $edits = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'CommunityConfiguration' );
		$this->enableAutoCreateTempUser();
		$this->setGroupPermissions( '*', 'edit', true );
		// Edits made here would otherwise be throttled long before the limit
		$this->overrideConfigValue( 'TempAccountCreationThrottle', [] );
		$this->overrideConfigValue( 'RateLimits', [] );
	}

	private function setLimit( int $limit ): void {
		$this->editPage(
			'MediaWiki:FemiwikiLoggedOutEditing.json',
			json_encode( [ 'DailyEditLimit' => $limit ] ),
			'',
			NS_MAIN,
			$this->getTestSysop()->getUser()
		);
		$this->resetServices();
	}

	private function newTempUser(): User {
		$status = $this->getServiceContainer()->getTempUserCreator()
			->create( null, new FauxRequest() );
		$this->assertStatusGood( $status );
		return $status->getUser();
	}

	private function edit( User $user, int $times ): void {
		for ( $i = 0; $i < $times; $i++ ) {
			$this->editPage( 'LoggedOutEditLimitTest', 'text ' . $this->edits++, '', NS_MAIN, $user );
		}
		$this->runDeferredUpdates();
	}

	private function canEdit( User $user ): bool {
		$status = $this->getServiceContainer()->getPermissionManager()->getPermissionStatus(
			'edit', $user, Title::makeTitle( NS_MAIN, 'LoggedOutEditLimitTest' ),
			PermissionManager::RIGOR_FULL
		);
		if ( $status->hasMessage( self::MESSAGE ) ) {
			return false;
		}
		$this->assertStatusGood( $status );
		return true;
	}

	public function testTemporaryAccountReachesTheLimit() {
		$this->setLimit( 2 );
		$user = $this->newTempUser();

		$this->edit( $user, 1 );
		$this->assertTrue( $this->canEdit( $user ), 'one edit of two' );

		$this->edit( $user, 1 );
		$this->assertFalse( $this->canEdit( $user ), 'two edits of two' );
	}

	public function testNewTemporaryAccountFromTheSameAddressIsCounted() {
		$this->setLimit( 2 );
		$this->edit( $this->newTempUser(), 2 );

		$this->assertFalse( $this->canEdit( $this->newTempUser() ) );
	}

	public function testLoggedOutReaderIsCountedByAddress() {
		$this->setLimit( 2 );
		$this->edit( $this->newTempUser(), 2 );

		$this->assertFalse( $this->canEdit( $this->getServiceContainer()->getUserFactory()->newAnonymous() ) );
	}

	public function testRegisteredUserIsNotLimited() {
		$this->setLimit( 1 );
		$user = $this->getTestUser()->getUser();
		$this->edit( $user, 2 );

		$this->assertTrue( $this->canEdit( $user ) );
	}

	public function testOtherNamespacesNeedAnAccount() {
		$this->setLimit( 5 );
		$talk = Title::makeTitle( NS_TALK, 'LoggedOutEditLimitTest' );
		$permissionManager = $this->getServiceContainer()->getPermissionManager();

		$this->assertStatusError(
			'unifiedextensionforfemiwiki-logged-out-editing-namespace',
			$permissionManager->getPermissionStatus(
				'edit', $this->newTempUser(), $talk, PermissionManager::RIGOR_FULL
			)
		);
		$this->assertStatusGood( $permissionManager->getPermissionStatus(
			'edit', $this->getTestUser()->getUser(), $talk, PermissionManager::RIGOR_FULL
		) );
	}

	public function testZeroClosesLoggedOutEditing() {
		$this->setLimit( 0 );
		$status = $this->getServiceContainer()->getPermissionManager()->getPermissionStatus(
			'edit', $this->getServiceContainer()->getUserFactory()->newAnonymous(),
			Title::makeTitle( NS_MAIN, 'LoggedOutEditLimitTest' ), PermissionManager::RIGOR_FULL
		);

		$this->assertStatusError( 'unifiedextensionforfemiwiki-logged-out-editing-closed', $status );
	}

	public function testZeroLeavesRegisteredUsersAlone() {
		$this->setLimit( 0 );

		$this->assertTrue( $this->canEdit( $this->getTestUser()->getUser() ) );
	}

	public function testWithoutTheProviderNothingIsRefused() {
		$services = $this->getServiceContainer();
		$handler = new LoggedOutEditLimit(
			$services->getConnectionProvider(),
			$services->getActorNormalization(),
			$services->getTempUserConfig()
		);
		$result = null;

		$this->assertTrue( $handler->onGetUserPermissionsErrorsExpensive(
			Title::makeTitle( NS_TALK, 'LoggedOutEditLimitTest' ), $this->newTempUser(), 'edit', $result
		) );
		$this->assertNull( $result );
	}
}
