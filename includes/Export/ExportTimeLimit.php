<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Export;

use MediaWiki\HookContainer\HookContainer;

/**
 * Stops an XML export at a deadline, between two pages or two revisions, so
 * the output can still be closed into a well-formed document.
 */
class ExportTimeLimit {

	/** @var callable Returns the current Unix time in seconds */
	private $clock;

	/**
	 * @param float $deadline Unix time in seconds
	 * @param callable|null $clock Returns the current Unix time; for tests
	 */
	public function __construct(
		private readonly float $deadline,
		?callable $clock = null
	) {
		$this->clock = $clock ?? static fn () => microtime( true );
	}

	/**
	 * Both hooks run before the writer's output is printed, so throwing from
	 * one leaves the page or revision it was about to write out entirely.
	 */
	public function register( HookContainer $hooks ): void {
		$hooks->register( 'XmlDumpWriterOpenPage', fn () => $this->check( false ) );
		$hooks->register( 'XmlDumpWriterWriteRevision', fn () => $this->check( true ) );
	}

	/**
	 * @param bool $inPage Whether a <page> element is open at this point
	 * @throws ExportTimeLimitException
	 */
	public function check( bool $inPage ): void {
		if ( ( $this->clock )() >= $this->deadline ) {
			throw new ExportTimeLimitException( $inPage );
		}
	}

	/**
	 * The end of the document after an export was stopped, with a note to the reader.
	 *
	 * @param bool $inPage Whether a <page> element was left open
	 * @param string $note Plain text
	 * @return string
	 */
	public static function closing( bool $inPage, string $note ): string {
		// An XML comment may not contain "--"
		$note = str_replace( '--', '- -', htmlspecialchars( $note, ENT_NOQUOTES ) );
		return ( $inPage ? "  </page>\n" : '' ) . "  <!-- $note -->\n</mediawiki>\n";
	}
}
