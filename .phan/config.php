<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/CategoryTree',
		'../../extensions/SpamBlacklist',
		'../../extensions/Wikibase',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/CategoryTree',
		'../../extensions/SpamBlacklist',
		'../../extensions/Wikibase',
	]
);

// CI checks out GrowthExperiments from REL1_46 on; elsewhere skip the code that uses it
if ( is_dir( '../../extensions/GrowthExperiments' ) ) {
	$cfg['directory_list'][] = '../../extensions/CommunityConfiguration';
	$cfg['directory_list'][] = '../../extensions/GrowthExperiments';
	$cfg['exclude_analysis_directory_list'][] = '../../extensions/CommunityConfiguration';
	$cfg['exclude_analysis_directory_list'][] = '../../extensions/GrowthExperiments';
} else {
	$cfg['exclude_file_list'] = array_merge( $cfg['exclude_file_list'], [
		'includes/GrowthExperiments/DatabaseTaskSuggester.php',
		'includes/GrowthExperiments/DatabaseTaskSuggesterFactory.php',
		'includes/GrowthExperiments/FeatureManager.php',
		'includes/HookHandlers/Services.php',
		'tests/phpunit/integration/DatabaseTaskSuggesterTest.php',
	] );
}

return $cfg;
