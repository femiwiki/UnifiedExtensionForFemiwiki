<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Unit;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\ShareCard\ShareCardRenderer;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\ShareCard\ShareCardRenderer
 */
class ShareCardRendererTest extends MediaWikiUnitTestCase {

	public static function provideWrap() {
		yield 'fits on one line' => [ '나이 주의', 10, 2, [ '나이 주의' ] ];
		yield 'breaks at spaces' => [ '가나다 라마 바사아', 5, 3, [ '가나다', '라마', '바사아' ] ];
		yield 'keeps words whole' => [ '가나 다라마바', 5, 3, [ '가나', '다라마바' ] ];
		yield 'splits a word longer than a line' => [ '가나다라마바사', 3, 3, [ '가나다', '라마바', '사' ] ];
		yield 'ends with an ellipsis' => [ '가나 다라 마바 사아', 4, 2, [ '가나', '다라…' ] ];
		yield 'makes room for the ellipsis' => [ '가나다 라마바 사아', 3, 2, [ '가나다', '라마…' ] ];
		yield 'collapses whitespace' => [ "  가나\n\n다라  ", 10, 2, [ '가나 다라' ] ];
		yield 'nothing' => [ '', 10, 2, [] ];
	}

	/**
	 * @dataProvider provideWrap
	 */
	public function testWrap( string $text, float $maxWidth, int $maxLines, array $expected ) {
		$this->assertSame(
			$expected,
			ShareCardRenderer::wrap( $text, $maxWidth, $maxLines, static fn ( $s ) => mb_strlen( $s ) )
		);
	}
}
