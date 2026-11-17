<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Explore;

use MediaWiki\Config\Config;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderGetConfigVarsHook;

/**
 * Puts "더 둘러보기" below articles. See ExploreFeed.
 */
class Hooks implements BeforePageDisplayHook, GetPreferencesHook, ResourceLoaderGetConfigVarsHook {

	public function __construct(
		private Config $config
	) {
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !$this->config->get( 'UnifiedExtensionForFemiwikiExplore' ) ) {
			return;
		}
		$title = $out->getTitle();
		$request = $out->getRequest();
		if ( !$title || !$out->isArticle() || ( $request->getRawVal( 'action' ) ?? 'view' ) !== 'view'
			|| $request->getCheck( 'diff' ) || $request->getCheck( 'oldid' )
			|| !$title->inNamespace( NS_MAIN ) || $title->isMainPage() || !$title->exists()
		) {
			return;
		}
		$out->addModuleStyles( [ 'ext.unifiedExtensionForFemiwiki.explore.styles' ] );
		$out->addModules( [ 'ext.unifiedExtensionForFemiwiki.explore' ] );
	}

	/** @inheritDoc */
	public function onGetPreferences( $user, &$preferences ) {
		// Set from the topic tags under the cards, not from Special:Preferences
		$preferences[ApiQueryExplore::MORE_OPTION] = [ 'type' => 'api' ];
		$preferences[ApiQueryExplore::LESS_OPTION] = [ 'type' => 'api' ];
	}

	/** @inheritDoc */
	public function onResourceLoaderGetConfigVars( array &$vars, $skin, $config ): void {
		if ( $this->config->get( 'UnifiedExtensionForFemiwikiExplore' ) ) {
			$vars['wgFemiwikiExploreTopicPrefix'] = str_replace( '_', ' ',
				$this->config->get( 'UnifiedExtensionForFemiwikiExploreTopicPrefix' ) );
		}
	}
}
