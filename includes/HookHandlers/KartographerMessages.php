<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use MediaWiki\Cache\Hook\MessageCacheFetchOverridesHook;

class KartographerMessages implements MessageCacheFetchOverridesHook {

	/**
	 * Kartographer credits Wikimedia's map server, which this wiki doesn't use
	 *
	 * @param string[] &$keys
	 */
	public function onMessageCacheFetchOverrides( array &$keys ): void {
		$keys['kartographer-attribution'] = 'unifiedextensionforfemiwiki-kartographer-attribution';
	}
}
