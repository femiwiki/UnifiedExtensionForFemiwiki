<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Unit;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\GrowthMessages;
use MediaWikiUnitTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\GrowthMessages
 */
class GrowthMessagesTest extends MediaWikiUnitTestCase {

	public function testEveryOverrideHasAnEnglishMessage() {
		$keys = [];
		( new GrowthMessages() )->onMessageCacheFetchOverrides( $keys );
		$this->assertNotEmpty( $keys );

		$en = json_decode( file_get_contents( __DIR__ . '/../../../i18n/en.json' ), true );
		foreach ( $keys as $key => $override ) {
			$this->assertStringStartsWith( 'growthexperiments-', $key );
			$this->assertArrayHasKey( $override, $en );
		}
	}
}
