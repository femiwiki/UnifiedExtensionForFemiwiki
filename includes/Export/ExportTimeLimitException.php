<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Export;

use RuntimeException;

/**
 * Thrown from an export hook once the export has run out of time.
 */
class ExportTimeLimitException extends RuntimeException {

	/**
	 * @param bool $inPage Whether a <page> element was left open
	 */
	public function __construct( public readonly bool $inPage ) {
		parent::__construct( 'Export time limit reached' );
	}
}
