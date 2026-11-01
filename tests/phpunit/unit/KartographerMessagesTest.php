<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Unit;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\KartographerMessages;
use MediaWikiUnitTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\KartographerMessages
 */
class KartographerMessagesTest extends MediaWikiUnitTestCase {

	public function testOverridesTheAttribution() {
		$keys = [ 'other-message' => 'other-override' ];
		( new KartographerMessages() )->onMessageCacheFetchOverrides( $keys );
		$this->assertSame( [
			'other-message' => 'other-override',
			'kartographer-attribution' => 'unifiedextensionforfemiwiki-kartographer-attribution',
		], $keys );
	}
}
