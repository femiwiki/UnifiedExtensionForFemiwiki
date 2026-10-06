<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments;

use GrowthExperiments\FeatureManager as GrowthFeatureManager;
use MediaWiki\Config\Config;
use MediaWiki\Registration\ExtensionRegistry;

// Each GrowthExperiments release needs only one of the suppressions below
// @phan-file-suppress UnusedPluginSuppression, UnusedPluginFileSuppression

/**
 * GrowthExperiments turns suggested edits off unless WikimediaMessages is loaded, which
 * Femiwiki does not load. The task types and their messages come from GrowthExperiments itself.
 */
class FeatureManager extends GrowthFeatureManager {

	/** @var Config */
	private $growthConfig;

	/**
	 * @param ExtensionRegistry $extensionRegistry
	 * @param Config $growthConfig
	 * @param mixed ...$args What else the GrowthExperiments release takes; 1.46 takes nothing more
	 */
	public function __construct( ExtensionRegistry $extensionRegistry, Config $growthConfig, ...$args ) {
		// @phan-suppress-next-line PhanParamTooManyUnpack, PhanParamTooFewUnpack
		parent::__construct( $extensionRegistry, $growthConfig, ...$args );
		$this->growthConfig = $growthConfig;
	}

	/** @inheritDoc */
	public function isNewcomerTasksAvailable(): bool {
		return $this->growthConfig->get( 'GEHomepageSuggestedEditsEnabled' );
	}
}
