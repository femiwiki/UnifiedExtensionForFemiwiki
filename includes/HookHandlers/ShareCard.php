<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\ShareCard\ShareCardHandler;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\ShareCard\ShareCardRenderer;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Page\PageProps;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Utils\UrlUtils;

/**
 * Makes the share card the og:image of a content page without a page image. A page with one
 * keeps the image PageImages gives it, as a card would have to credit the image's own license.
 * $wgPageImagesOpenGraphFallbackImage should be off, or both would be given.
 */
class ShareCard implements BeforePageDisplayHook {

	public function __construct(
		private Config $config,
		private PageProps $pageProps,
		private NamespaceInfo $namespaceInfo,
		private UrlUtils $urlUtils
	) {
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !$this->config->get( 'UnifiedExtensionForFemiwikiShareCard' ) || !$out->isArticle() ) {
			return;
		}
		$title = $out->getTitle();
		if ( !$title || !$title->exists() || $title->isRedirect()
			|| !$this->namespaceInfo->isContent( $title->getNamespace() )
		) {
			return;
		}
		// The page_props PageImages saves
		if ( $this->pageProps->getProperties( $title, [ 'page_image', 'page_image_free' ] ) ) {
			return;
		}
		$url = $this->urlUtils->expand(
			wfScript( 'rest' ) . ShareCardHandler::PATH . $title->getLatestRevID(),
			PROTO_CANONICAL
		);
		if ( !$url ) {
			return;
		}
		$out->addMeta( 'og:image', $url );
		$out->addMeta( 'og:image:width', (string)ShareCardRenderer::WIDTH );
		$out->addMeta( 'og:image:height', (string)ShareCardRenderer::HEIGHT );
		$out->addMeta( 'og:image:alt', $title->getPrefixedText() );
	}
}
