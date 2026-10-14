<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Unit;

use MediaWiki\Content\Content;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\FlowBoards;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\Title;
use MediaWikiUnitTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\FlowBoards
 */
class FlowBoardsTest extends MediaWikiUnitTestCase {

	public static function provideModels() {
		yield 'Flow board' => [ 'flow-board', 'noindex', '' ];
		yield 'wikitext' => [ CONTENT_MODEL_WIKITEXT, '', null ];
	}

	/**
	 * @dataProvider provideModels
	 */
	public function testNoindex( string $model, string $policy, ?string $prop ) {
		$content = $this->createMock( Content::class );
		$content->method( 'getModel' )->willReturn( $model );
		$output = new ParserOutput();

		( new FlowBoards() )->onContentAlterParserOutput(
			$content, $this->createMock( Title::class ), $output );

		$this->assertSame( $policy, $output->getIndexPolicy() );
		$this->assertSame( $prop, $output->getPageProperty( 'noindex' ) );
	}
}
