<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Unit;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\SiteNameMessages;
use MediaWikiUnitTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\SiteNameMessages
 */
class SiteNameMessagesTest extends MediaWikiUnitTestCase {

	public function testEveryOverrideHasEnglishAndKoreanMessages() {
		$keys = [];
		( new SiteNameMessages() )->onMessageCacheFetchOverrides( $keys );
		$this->assertNotEmpty( $keys );

		$i18n = __DIR__ . '/../../../i18n/';
		$en = json_decode( file_get_contents( $i18n . 'en.json' ), true );
		$ko = json_decode( file_get_contents( $i18n . 'ko.json' ), true );
		foreach ( $keys as $override ) {
			$this->assertArrayHasKey( $override, $en );
			$this->assertArrayHasKey( $override, $ko );
		}
		$this->assertStringContainsString( '$1', $en[$keys['pagetitle']] );
	}
}
