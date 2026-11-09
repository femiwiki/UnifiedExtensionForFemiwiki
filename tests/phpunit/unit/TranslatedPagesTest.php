<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Unit;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\TranslatedPages;
use MediaWikiUnitTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\TranslatedPages
 */
class TranslatedPagesTest extends MediaWikiUnitTestCase {

	public function testTranslatedPage() {
		$this->assertSame(
			[ 'en' => 'en', 'ko' => null, 'zh-Hans' => 'zh-hans', 'x-default' => null ],
			TranslatedPages::getLanguageVersions( 'ko', [ 'zh-hans' => '0.40', 'ko' => 1.0, 'en' => '1.00' ] )
		);
	}

	public function testUntranslatedPagesLeftOut() {
		$this->assertSame(
			[ 'en' => 'en', 'ko' => null, 'x-default' => null ],
			TranslatedPages::getLanguageVersions( 'ko', [ 'ja' => '0.00', 'ko' => 1.0, 'en' => '0.50' ] )
		);
	}

	public function testNothingTranslated() {
		$this->assertSame( [], TranslatedPages::getLanguageVersions( 'ko', [ 'ko' => 1.0 ] ) );
		$this->assertSame( [], TranslatedPages::getLanguageVersions( 'ko', [ 'ko' => 1.0, 'en' => '0.00' ] ) );
	}
}
