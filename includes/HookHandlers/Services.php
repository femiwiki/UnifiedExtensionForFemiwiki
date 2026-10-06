<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use GrowthExperiments\GrowthExperimentsServices;
use MediaWiki\Extension\CommunityConfiguration\CommunityConfigurationServices;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\CategoryTopicRegistry;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\DatabaseTaskSuggesterFactory;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\FeatureManager;
use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

// Only GrowthExperiments after 1.46 needs the suppression below
// @phan-file-suppress UnusedPluginSuppression, UnusedPluginFileSuppression

class Services implements MediaWikiServicesHook {

	/** CommunityConfiguration provider of the suggested edit topics */
	public const TOPICS_PROVIDER = 'FemiwikiSuggestedEditsTopics';

	/**
	 * Let GrowthExperiments suggest edits without CirrusSearch or WikimediaMessages.
	 *
	 * @param MediaWikiServices $services
	 */
	public function onMediaWikiServices( $services ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'GrowthExperiments' )
			// Older GrowthExperiments releases have no FeatureManager
			|| !$services->hasService( 'GrowthExperimentsFeatureManager' )
		) {
			return;
		}

		// Manipulators rather than redefinitions, so the configuration is read only once the
		// service is needed
		$services->addServiceManipulator(
			'GrowthExperimentsFeatureManager',
			static function ( $featureManager, MediaWikiServices $services ): ?FeatureManager {
				if ( !self::isEnabled( $services ) ) {
					return null;
				}
				$growthServices = GrowthExperimentsServices::wrap( $services );
				// The same arguments GrowthExperiments' own wiring passes
				if ( method_exists( $featureManager, 'setExperimentManager' ) ) {
					// GrowthExperiments 1.46
					$featureManager = new FeatureManager(
						$services->getExtensionRegistry(),
						$growthServices->getGrowthConfig()
					);
					// @phan-suppress-next-line PhanUndeclaredMethod Gone after GrowthExperiments 1.46
					$featureManager->setExperimentManager( $growthServices->getExperimentUserManager() );
					return $featureManager;
				}
				$hasTestKitchen = $services->has( 'TestKitchen.ExperimentManager' );
				return new FeatureManager(
					$services->getExtensionRegistry(),
					$growthServices->getGrowthConfig(),
					$services->getUserRegistrationLookup(),
					$growthServices->getLogger(),
					$hasTestKitchen ? $services->get( 'TestKitchen.ExperimentManager' ) : null,
					$hasTestKitchen ? $services->get( 'TestKitchen.ExperimentCoordinator' ) : null
				);
			}
		);
		$services->addServiceManipulator(
			'GrowthExperimentsTopicRegistry',
			static function ( $registry, MediaWikiServices $services ): ?CategoryTopicRegistry {
				if ( !self::isEnabled( $services ) ) {
					return null;
				}
				return new CategoryTopicRegistry(
					CommunityConfigurationServices::wrap( $services )->getConfigurationProviderFactory()
						->newProvider( self::TOPICS_PROVIDER ),
					$services->getTitleParser()
				);
			}
		);
		$services->addServiceManipulator(
			'GrowthExperimentsTaskSuggesterFactory',
			static function ( $factory, MediaWikiServices $services ): ?DatabaseTaskSuggesterFactory {
				if ( !self::isEnabled( $services ) ) {
					return null;
				}
				$growthServices = GrowthExperimentsServices::wrap( $services );
				return new DatabaseTaskSuggesterFactory(
					$growthServices->getNewcomerTasksConfigurationLoader(),
					$growthServices->getNewcomerTasksUserOptionsLookup(),
					$services->getConnectionProvider(),
					$services->getLinkTargetLookup(),
					$growthServices->getTopicRegistry(),
					$growthServices->getLogger()
				);
			}
		);
	}

	private static function isEnabled( MediaWikiServices $services ): bool {
		return $services->getMainConfig()->get( 'UnifiedExtensionForFemiwikiSuggestedEdits' );
	}
}
