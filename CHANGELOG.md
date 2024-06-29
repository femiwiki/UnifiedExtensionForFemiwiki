# Changelog

Versions and bullets are arranged chronologically from latest to oldest.

## v4.0.0 (Unreleased)

- Dropped SpecialOrderedWhatLinksHere because of the hardness of the maintaining. Please upvote https://phabricator.wikimedia.org/T4306 if you still want to the functionality.
- Dropped `$UnifiedExtensionForFemiwikiSoftDefaultOptions` configuration variable and the related feature.
- Added `$wgUnifiedExtensionForFemiwikiBlockByEmail` configuration variable (default is true) which provides various email blocking funtionalities.

## v3.0.2

- Fixed the errors happened with RelatedArticlesUseLinks.

## v3.0.1

- Localisations update from https://translatewiki.net.

## v3.0.0

- BREAKING CHANGE: Disabled the additional pre-authentication step by default. Set `$wgUnifiedExtensionForFemiwikiPreAuth` to true to enable it again.
- Introduces `$wgUnifiedExtensionForFemiwikiSoftDefaultOptions` which is used to change the default options only for the new users or reset options.
- Makes HelpPanel and Homepage are set when after reset if configured for new users.

## v2.0.0

- Queries more fields of RelatedArticles to fix cache.
- Drops Google Analytics. Consider installing Extension:PageViewInfoGA instead.

## v1.2.2

- Adds a configuration for disabling adding links as RelatedArticles.

## v1.2.1

- Limits related articles order by category asc and adds a configuration variable `$wgUnifiedExtensionForFemiwikiRelatedArticlesTargetNamespaces` to scope target namespaces.
- Group requests of GoogleAnalytics to 5 to reduce the latency.
- Filters empty strings from RelatedArticles. (https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/42)

## v1.2.0

- Implements GoogleAnalyticsPageViewService.
- Uses $wgRelatedArticlesCardLimit for RelatedArticles.
- Shows links on a page as RelatedArticles.

## v1.1.1

- Adds omitted prefix in RelatedArticles.

## v1.1.0

- Adds backlinks as RelatedArticles.

## v1.0.7

- Fixes Call to a member function on null (https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/39)

## v1.0.6

- Updates copied code to REL1_36.

## v1.0.5

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

## v1.0.4

- Fix "Undefined variable: nextNumber". (https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/30)

## v1.0.3

- Fix "Undefined variable: queryLimit".

## v1.0.2

- Improve CI.
