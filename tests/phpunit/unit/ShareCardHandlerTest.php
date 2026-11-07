<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Unit;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\ShareCard\ShareCardHandler;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\ShareCard\ShareCardHandler
 */
class ShareCardHandlerTest extends MediaWikiUnitTestCase {

	public static function provideLeadFromPages() {
		yield 'internal result, keyed by page ID' => [
			[ 29840 => [ 'pageid' => 29840, 'extract' => [ '*' => "첫 문단.\n둘째 문단." ] ] ],
			'첫 문단.',
		];
		yield 'plain string' => [ [ 7 => [ 'extract' => "  첫 문단.  \n둘째" ] ], '첫 문단.' ];
		yield 'empty extract' => [ [ 7 => [ 'extract' => [ '*' => '' ] ] ], '' ];
		yield 'no extract' => [ [ 7 => [ 'pageid' => 7 ] ], '' ];
		yield 'no pages' => [ null, '' ];
	}

	/**
	 * @dataProvider provideLeadFromPages
	 */
	public function testLeadFromPages( $pages, string $expected ) {
		$this->assertSame( $expected, ShareCardHandler::leadFromPages( $pages ) );
	}
}
