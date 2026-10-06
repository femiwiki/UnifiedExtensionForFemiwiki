<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use MediaWiki\Cache\Hook\MessageCacheFetchOverridesHook;

class GrowthMessages implements MessageCacheFetchOverridesHook {

	/** GrowthExperiments messages that name Wikipedia instead of this wiki */
	private const KEYS = [
		'growthexperiments-homepage-startediting-dialog-intro-header',
		'growthexperiments-homepage-startediting-dialog-intro-subheader',
		'growthexperiments-homepage-startediting-subheader-other',
		'growthexperiments-homepage-startediting-mobilesummary-body-variant-d',
		'growthexperiments-homepage-startediting-dialog-difficulty-level-hard-description-header',
		'growthexperiments-homepage-suggestededits-tasktype-shortdescription-references',
		'growthexperiments-homepage-suggestededits-tasktype-description-copyedit',
		'growthexperiments-homepage-suggestededits-tasktype-description-references',
		'growthexperiments-homepage-suggestededits-tasktype-description-update',
		'growthexperiments-homepage-suggestededits-tasktype-description-expand',
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
