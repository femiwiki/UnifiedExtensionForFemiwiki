<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use GrowthExperiments\GrowthExperimentsServices;
use GrowthExperiments\HomepageModules\SuggestedEdits;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\CategoryTopicRegistry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\Hook\UserGetDefaultOptionsHook;

class GrowthTopics implements UserGetDefaultOptionsHook {

	/**
	 * Select the topics the wiki's topic configuration names for users who have chosen none.
	 *
	 * @param array &$defaultOptions
	 */
	public function onUserGetDefaultOptions( &$defaultOptions ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'GrowthExperiments' ) ) {
			return;
		}
		$registry = GrowthExperimentsServices::wrap( MediaWikiServices::getInstance() )->getTopicRegistry();
		if ( !$registry instanceof CategoryTopicRegistry ) {
			return;
		}
		$topicIds = $registry->getDefaultTopicIds();
		if ( $topicIds ) {
			$defaultOptions[SuggestedEdits::TOPICS_ORES_PREF] = json_encode( $topicIds );
		}
	}
}
