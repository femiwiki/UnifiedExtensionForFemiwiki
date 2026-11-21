<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use MediaWiki\Cache\Hook\MessageCacheFetchOverridesHook;

/**
 * Gives the wiki's name in the browser title in the interface language.
 *
 * $wgSiteName has one value, so core's "$1 - {{SITENAME}}" shows the Korean name even to readers
 * of an English page. Like WikimediaMessages, the per-language name lives in this extension's
 * messages instead of on-wiki MediaWiki: subpages.
 */
class SiteNameMessages implements MessageCacheFetchOverridesHook {

	/** Core messages that print the wiki's name */
	private const KEYS = [
		'pagetitle',
		'pagetitle-view-mainpage',
	];

	/**
	 * @param string[] &$keys
	 */
	public function onMessageCacheFetchOverrides( array &$keys ): void {
		foreach ( self::KEYS as $key ) {
			$keys[$key] = 'unifiedextensionforfemiwiki-' . $key;
		}
	}
}
