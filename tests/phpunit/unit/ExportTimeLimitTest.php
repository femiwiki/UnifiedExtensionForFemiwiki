<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Unit;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\Export\ExportTimeLimit;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\Export\ExportTimeLimitException;
use MediaWikiUnitTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\Export\ExportTimeLimit
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\Export\ExportTimeLimitException
 */
class ExportTimeLimitTest extends MediaWikiUnitTestCase {

	public function testPassesBeforeTheDeadline() {
		$limit = new ExportTimeLimit( 10.0, static fn () => 9.0 );
		$limit->check( true );
		$this->addToAssertionCount( 1 );
	}

	public function testThrowsAtTheDeadline() {
		$limit = new ExportTimeLimit( 10.0, static fn () => 10.0 );
		try {
			$limit->check( true );
			$this->fail( 'No exception' );
		} catch ( ExportTimeLimitException $e ) {
			$this->assertTrue( $e->inPage );
		}
	}

	public static function provideClosing() {
		yield 'between pages' => [ false, '<mediawiki><page><title>A</title></page>' ];
		yield 'inside a page' => [ true, '<mediawiki><page><title>A</title>' ];
	}

	/**
	 * @dataProvider provideClosing
	 */
	public function testClosingIsWellFormed( bool $inPage, string $head ) {
		$xml = $head . ExportTimeLimit::closing( $inPage, 'Stopped -- see https://example.org/-' );
		$doc = simplexml_load_string( $xml );
		$this->assertNotFalse( $doc );
		$this->assertCount( 1, $doc->page );
		$this->assertStringContainsString( '<!-- Stopped - - see https://example.org/- -->', $xml );
	}
}
