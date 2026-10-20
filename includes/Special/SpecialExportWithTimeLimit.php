<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Special;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\Export\ExportTimeLimit;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\Export\ExportTimeLimitException;
use MediaWiki\Specials\SpecialExport;

/**
 * Special:Export that ends the document at a time limit and points to the
 * public dump, instead of being cut mid-stream by PHP's own limit.
 */
class SpecialExportWithTimeLimit extends SpecialExport {

	private float $started;

	/** @inheritDoc */
	public function execute( $par ) {
		$this->started = microtime( true );
		parent::execute( $par );
	}

	/** @inheritDoc */
	protected function doExport( $page, $history, $list_authors, $exportall ) {
		$limit = $this->getConfig()->get( 'UnifiedExtensionForFemiwikiExportTimeLimit' );
		if ( !$limit ) {
			parent::doExport( $page, $history, $list_authors, $exportall );
			return;
		}

		( new ExportTimeLimit( $this->started + $limit ) )->register( $this->getHookContainer() );
		try {
			parent::doExport( $page, $history, $list_authors, $exportall );
		} catch ( ExportTimeLimitException $e ) {
			$note = $this->msg( 'unifiedextensionforfemiwiki-export-time-limit' )->numParams( $limit )->text();
			print ExportTimeLimit::closing( $e->inPage, $note );
		}
	}
}
