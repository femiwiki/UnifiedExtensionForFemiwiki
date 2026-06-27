See https://github.com/femiwiki/UnifiedExtensionForFemiwiki/releases

# Old Changelog

Versions and bullets are arranged chronologically from latest to oldest.

## [5.0.0](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/compare/v3.0.1...v5.0.0) (2026-06-27)


### ⚠ BREAKING CHANGES

* drop related articles tweak ([#185](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/185))

### Features

* Block users by blocked emails ([#140](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/140)) ([082b86c](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/commit/082b86c00d26ff45c7d881d1920ad70671ad1cc7))
* drop related articles tweak ([#185](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/185)) ([08e6c8c](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/commit/08e6c8c24b336d2b2ef6842ea0937f090d200394))
* Install Release please ([#180](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/180)) ([78065c7](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/commit/78065c7fd93c39181707080beac747561e285ba8))


### Bug Fixes

* Callback is the first ([6141035](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/commit/6141035378ce5b3f94bbb73e95f31e5d77947072))
* Parse error ([0d89531](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/commit/0d895317d3dc851ec664400c3608e70fa3330df1))
* Replace removed Wikibase ClientHooks with stable API ([#220](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/220)) ([7f6e7fe](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/commit/7f6e7fe226602ef65627b11948f22de66b4455a4))


### Miscellaneous Chores

* bump release-please baseline past legacy v4.x tags ([#223](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/223)) ([e145438](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/commit/e1454387472b9c2e4ca3ce6322fb0376c92b52ff))

## 4.0.0

- Dropped SpecialOrderedWhatLinksHere because of the hardness of the maintaining. Please upvote https://phabricator.wikimedia.org/T4306 if you still want to the functionality.
- Dropped `$UnifiedExtensionForFemiwikiSoftDefaultOptions` configuration variable and the related feature.
- Added `$wgUnifiedExtensionForFemiwikiBlockByEmail` configuration variable (default is true) which provides various email blocking funtionalities.

## 3.0.2

- Fixed the errors happened with RelatedArticlesUseLinks.

## 3.0.1

- Localisations update from https://translatewiki.net.

## 3.0.0

- BREAKING CHANGE: Disabled the additional pre-authentication step by default. Set `$wgUnifiedExtensionForFemiwikiPreAuth` to true to enable it again.
- Introduces `$wgUnifiedExtensionForFemiwikiSoftDefaultOptions` which is used to change the default options only for the new users or reset options.
- Makes HelpPanel and Homepage are set when after reset if configured for new users.

## 2.0.0

- Queries more fields of RelatedArticles to fix cache.
- Drops Google Analytics. Consider installing Extension:PageViewInfoGA instead.

## 1.2.2

- Adds a configuration for disabling adding links as RelatedArticles.

## 1.2.1

- Limits related articles order by category asc and adds a configuration variable `$wgUnifiedExtensionForFemiwikiRelatedArticlesTargetNamespaces` to scope target namespaces.
- Group requests of GoogleAnalytics to 5 to reduce the latency.
- Filters empty strings from RelatedArticles. (https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/42)

## 1.2.0

- Implements GoogleAnalyticsPageViewService.
- Uses $wgRelatedArticlesCardLimit for RelatedArticles.
- Shows links on a page as RelatedArticles.

## 1.1.1

- Adds omitted prefix in RelatedArticles.

## 1.1.0

- Adds backlinks as RelatedArticles.

## 1.0.7

- Fixes Call to a member function on null (https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/39)

## 1.0.6

- Updates copied code to REL1_36.

## 1.0.5

Note: this version requires MediaWiki 1.36+. Earlier versions are no longer supported.
If you still use those versions of MediaWiki, please use REL1_35 branch instead of this release.

In addition, your LocalSettings.php must be update due to changes of [Dependency Injection] in MediaWiki 1.36:

```php
$wgSpecialPages['Whatlinkshere'] = [
	'class' => 'SpecialOrderedWhatLinksHere',
	'services' => [
		'DBLoadBalancer',
		'LinkBatchFactory',
		'ContentHandlerFactory',
		'SearchEngineFactory',
		'NamespaceInfo',
	]
];
```

ENHANCEMENTS:

- Localisation updates from https://translatewiki.net.

## 1.0.4

- Fix "Undefined variable: nextNumber". (https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/30)

## 1.0.3

- Fix "Undefined variable: queryLimit".

## 1.0.2

- Improve CI.
