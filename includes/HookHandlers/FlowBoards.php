<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use MediaWiki\Content\Content;
use MediaWiki\Content\Hook\ContentAlterParserOutputHook;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\Title;

class FlowBoards implements ContentAlterParserOutputHook {

	/**
	 * Mark Flow boards noindex, which also keeps them out of the sitemap.
	 * Their topics now live on wikitext talk pages.
	 *
	 * @param Content $content
	 * @param Title $title
	 * @param ParserOutput $parserOutput
	 */
	public function onContentAlterParserOutput( $content, $title, $parserOutput ) {
		if ( $content->getModel() !== 'flow-board' ) {
			return;
		}
		$parserOutput->setIndexPolicy( 'noindex' );
		$parserOutput->setUnsortedPageProperty( 'noindex' );
	}
}
