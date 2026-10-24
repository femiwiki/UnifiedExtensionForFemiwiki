<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\EmailCounter;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IDatabase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Database
 * @group Standalone
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\EmailCounter
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\NumberOfEmails
 */
class NumberOfEmailsTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Test users all share one address, so start from none
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [ 'user_email' => '', 'user_email_authenticated' => null ] )
			->where( IDatabase::ALL_ROWS )
			->caller( __METHOD__ )
			->execute();
	}

	private function addUser( string $email, bool $authenticated ): void {
		$user = $this->getMutableTestUser()->getUser();
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [
				'user_email' => $email,
				'user_email_authenticated' => $authenticated ? $this->getDb()->timestamp() : null,
			] )
			->where( [ 'user_id' => $user->getId() ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Three addresses, two of them authenticated
	 */
	private function addUsers(): void {
		$this->addUser( 'a@example.org', true );
		$this->addUser( 'a@example.org', false );
		$this->addUser( 'b@example.org', false );
		$this->addUser( 'c@example.org', true );
		$this->addUser( '', false );
	}

	private function newCounter(): EmailCounter {
		return new EmailCounter(
			$this->getServiceContainer()->getConnectionProvider(),
			$this->getServiceContainer()->getMainWANObjectCache()
		);
	}

	public function testCountsDistinctAddresses() {
		$this->addUsers();

		$this->assertSame( 3, $this->newCounter()->count( false ) );
		$this->assertSame( 2, $this->newCounter()->count( true ) );
	}

	public function testCountIsCached() {
		$this->addUser( 'a@example.org', true );
		$counter = $this->newCounter();
		$this->assertSame( 1, $counter->count( true ) );

		$this->addUser( 'b@example.org', true );

		$this->assertSame( 1, $counter->count( true ) );
		$this->assertSame( 2, $counter->count( false ) );
	}

	public static function provideWikitext() {
		yield 'variable' => [ '{{NUMBEROFEMAILS}}', '3' ];
		yield 'raw' => [ '{{NUMBEROFEMAILS:R}}', '3' ];
		yield 'authenticated' => [ '{{NUMBEROFEMAILS:authenticated}}', '2' ];
		yield 'authenticated and raw' => [ '{{NUMBEROFEMAILS:authenticated|R}}', '2' ];
		yield 'raw and authenticated' => [ '{{NUMBEROFEMAILS: R | authenticated }}', '2' ];
	}

	/**
	 * @dataProvider provideWikitext
	 */
	public function testMagicWord( string $wikitext, string $expected ) {
		$this->addUsers();
		$parser = $this->getServiceContainer()->getParserFactory()->create();

		$output = $parser->preprocess(
			$wikitext,
			Title::makeTitle( NS_MAIN, 'NumberOfEmailsTest' ),
			ParserOptions::newFromAnon()
		);

		$this->assertSame( $expected, $output );
		$this->assertLessThanOrEqual( EmailCounter::TTL, $parser->getOutput()->getCacheExpiry() );
	}

	public function testFormatsUnlessRaw() {
		$this->overrideConfigValue( 'LanguageCode', 'fa' );
		$this->addUsers();
		$parser = $this->getServiceContainer()->getParserFactory()->create();
		$title = Title::makeTitle( NS_MAIN, 'NumberOfEmailsTest' );

		$this->assertSame( '۳', $parser->preprocess( '{{NUMBEROFEMAILS}}', $title, ParserOptions::newFromAnon() ) );
		$this->assertSame( '3', $parser->preprocess( '{{NUMBEROFEMAILS:R}}', $title, ParserOptions::newFromAnon() ) );
	}

	public function testKoreanAliases() {
		$this->overrideConfigValue( 'LanguageCode', 'ko' );
		$this->addUsers();
		$parser = $this->getServiceContainer()->getParserFactory()->create();

		$this->assertSame( '2', $parser->preprocess(
			'{{이메일수:인증됨}}',
			Title::makeTitle( NS_MAIN, 'NumberOfEmailsTest' ),
			ParserOptions::newFromAnon()
		) );
	}

	public function testSpecialStatistics() {
		$this->addUsers();
		$extraStats = [];

		$this->getServiceContainer()->getHookContainer()->run(
			'SpecialStatsAddExtra',
			[ &$extraStats, RequestContext::getMain() ]
		);

		$this->assertSame(
			2,
			$extraStats['statistics-header-hooks']['unifiedextensionforfemiwiki-statistics-emails-authenticated']
		);
	}
}
