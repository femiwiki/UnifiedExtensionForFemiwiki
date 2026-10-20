<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\Maintenance\SendNoticeEmail;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\User;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Database
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\Maintenance\SendNoticeEmail
 */
class SendNoticeEmailTest extends MaintenanceBaseTestCase {
	use MockAuthorityTrait;

	/** @var array[] Recipient address and body of each email sent */
	private array $sent = [];

	protected function getMaintenanceClass() {
		return SendNoticeEmail::class;
	}

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( MainConfigNames::EnableEmail, true );
		$this->setTemporaryHook( 'AlternateUserMailer',
			function ( $headers, $to, $from, $subject, $body ) {
				$this->sent[] = [ 'to' => $to[0]->address, 'body' => $body ];
				return false;
			}
		);
	}

	private function makeUser( string $email, bool $confirmed = true ): User {
		$user = $this->getMutableTestUser()->getUser();
		$user->setEmail( $email );
		if ( $confirmed ) {
			$user->setEmailAuthenticationTimestamp( wfTimestampNow() );
		}
		$user->saveSettings();
		return $user;
	}

	private function runScript( array $options ): void {
		$body = $this->getNewTempFile();
		file_put_contents( $body, "Hello, \$1.\n" );
		$options += [ 'subject' => 'Notice', 'body' => $body, 'rate' => 1000 ];
		foreach ( $options as $name => $value ) {
			$this->maintenance->setOption( $name, $value );
		}
		$this->maintenance->execute();
	}

	/** @return array<string,string> Body sent to each of the given addresses */
	private function sentTo( array $addresses ): array {
		$bodies = [];
		foreach ( $this->sent as $mail ) {
			if ( in_array( $mail['to'], $addresses, true ) ) {
				$this->assertArrayNotHasKey( $mail['to'], $bodies, 'One email per address' );
				$bodies[$mail['to']] = $mail['body'];
			}
		}
		return $bodies;
	}

	public function testSendsOncePerConfirmedAddress() {
		$older = $this->makeUser( 'shared@example.org' );
		$this->makeUser( 'shared@example.org' );
		$this->makeUser( 'unconfirmed@example.org', false );

		$this->runScript( [] );

		$this->assertSame(
			[ 'shared@example.org' => "Hello, {$older->getName()}.\n" ],
			$this->sentTo( [ 'shared@example.org', 'unconfirmed@example.org' ] )
		);
	}

	public function testDryRunSendsNothing() {
		$this->makeUser( 'dry@example.org' );

		$this->runScript( [ 'dry-run' => true ] );

		$this->assertSame( [], $this->sent );
	}

	public function testTouchedFilters() {
		$stale = $this->makeUser( 'stale@example.org' );
		$this->makeUser( 'recent@example.org' );
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [ 'user_touched' => $this->getDb()->timestamp( '20200101000000' ) ] )
			->where( [ 'user_id' => $stale->getId() ] )
			->caller( __METHOD__ )
			->execute();
		$addresses = [ 'stale@example.org', 'recent@example.org' ];

		$this->runScript( [ 'touched-after' => '20250101000000' ] );
		$this->assertSame( [ 'recent@example.org' ], array_keys( $this->sentTo( $addresses ) ) );

		$this->sent = [];
		$this->maintenance = $this->createMaintenance();
		$this->runScript( [ 'touched-before' => '20250101000000' ] );
		$this->assertSame( [ 'stale@example.org' ], array_keys( $this->sentTo( $addresses ) ) );
	}

	public function testSkipBlocked() {
		$blocked = $this->makeUser( 'blocked@example.org' );
		$this->getServiceContainer()->getBlockUserFactory()
			->newBlockUser( $blocked, $this->mockRegisteredUltimateAuthority(), 'infinite', 'test' )
			->placeBlock();
		$this->makeUser( 'open@example.org' );

		$this->runScript( [ 'skip-blocked' => true ] );

		$this->assertSame(
			[ 'open@example.org' ],
			array_keys( $this->sentTo( [ 'blocked@example.org', 'open@example.org' ] ) )
		);
	}
}
