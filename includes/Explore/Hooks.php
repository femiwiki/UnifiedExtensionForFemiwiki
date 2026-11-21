<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Explore;

use MediaWiki\Config\Config;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderGetConfigVarsHook;

/**
 * Puts "더 둘러보기" below articles and project pages. See ExploreFeed.
 */
class Hooks implements BeforePageDisplayHook, GetPreferencesHook, ResourceLoaderGetConfigVarsHook {

	public function __construct(
		private Config $config
	) {
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !$this->config->get( 'MoreToExploreEnabled' ) ) {
			return;
		}
		$title = $out->getTitle();
		$request = $out->getRequest();
		if ( !$title || !$out->isArticle() || ( $request->getRawVal( 'action' ) ?? 'view' ) !== 'view'
			|| $request->getCheck( 'diff' ) || $request->getCheck( 'oldid' )
			// Opened straight into VisualEditor. The script hides the feed when it opens later.
			|| $request->getCheck( 'veaction' )
			|| !$title->inNamespaces( NS_MAIN, NS_PROJECT ) || $title->isMainPage() || !$title->exists()
		) {
			return;
		}
		$out->addModuleStyles( [ 'ext.moreToExplore.styles' ] );
		$out->addModules( [ 'ext.moreToExplore' ] );
	}

	/** @inheritDoc */
	public function onGetPreferences( $user, &$preferences ) {
		// Set from the topic tags under the cards, not from Special:Preferences
		$preferences[ApiQueryExplore::MORE_OPTION] = [ 'type' => 'api' ];
		$preferences[ApiQueryExplore::LESS_OPTION] = [ 'type' => 'api' ];
	}

	/** @inheritDoc */
	public function onResourceLoaderGetConfigVars( array &$vars, $skin, $config ): void {
		if ( $this->config->get( 'MoreToExploreEnabled' ) ) {
			$vars['wgMoreToExploreTopicPrefix'] = str_replace( '_', ' ',
				$this->config->get( 'MoreToExploreTopicPrefix' ) );
		}
	}
}
