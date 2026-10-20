<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Export\DumpStringOutput;
use MediaWiki\Export\WikiExporter;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\Export\ExportTimeLimit;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\Export\ExportTimeLimitException;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\Special\SpecialExportWithTimeLimit;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Database
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\Export\ExportTimeLimit
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\Special\SpecialExportWithTimeLimit
 */
class ExportTimeLimitTest extends MediaWikiIntegrationTestCase {

	public function testReplacesSpecialExport() {
		$page = $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'Export' );
		$this->assertInstanceOf( SpecialExportWithTimeLimit::class, $page );
	}

	public static function provideLimits() {
		yield 'past the limit' => [ 20, 0, true ];
		yield 'no limit' => [ 0, 1, false ];
	}

	/**
	 * @dataProvider provideLimits
	 */
	public function testSpecialPageEndsAtTheLimit( int $limit, int $pages, bool $noted ) {
		$this->overrideConfigValue( 'UnifiedExtensionForFemiwikiExportTimeLimit', $limit );
		$title = Title::makeTitle( NS_MAIN, 'ExportTimeLimit Special' );
		$this->editPage( $title, 'text' );

		$special = $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'Export' );
		$special->setContext( RequestContext::getMain() );
		// Started long enough ago that any limit has passed
		( new \ReflectionProperty( $special, 'started' ) )->setValue( $special, 0.0 );
		// Set by execute(), which would also reset the output buffers
		( new \ReflectionProperty( $special, 'templates' ) )->setValue( $special, false );
		( new \ReflectionProperty( $special, 'pageLinkDepth' ) )->setValue( $special, 0 );
		$doExport = new \ReflectionMethod( $special, 'doExport' );

		ob_start();
		$doExport->invoke( $special, $title->getPrefixedText(), WikiExporter::FULL, false, false );
		$xml = ob_get_clean();

		$doc = simplexml_load_string( $xml );
		$this->assertNotFalse( $doc, $xml );
		$this->assertCount( $pages, $doc->xpath( '//*[local-name()="page"]' ) );
		$this->assertSame( $noted, str_contains( $xml, 'archive.org' ) );
	}

	public static function provideCuts() {
		// Checks run at each page's opening and before each revision
		yield 'inside the first page' => [ 2, 1, 1 ];
		yield 'at the second page' => [ 4, 1, 3 ];
	}

	/**
	 * @dataProvider provideCuts
	 */
	public function testStopsWithAWellFormedDocument( int $checksAllowed, int $pages, int $revisions ) {
		$titles = [
			Title::makeTitle( NS_MAIN, 'ExportTimeLimit A' ),
			Title::makeTitle( NS_MAIN, 'ExportTimeLimit B' ),
		];
		foreach ( $titles as $title ) {
			foreach ( [ 'one', 'two', 'three' ] as $text ) {
				$this->editPage( $title, $text );
			}
		}

		$checks = 0;
		$clock = static function () use ( &$checks, $checksAllowed ) {
			return ++$checks > $checksAllowed ? 2.0 : 0.0;
		};
		( new ExportTimeLimit( 1.0, $clock ) )->register( $this->getServiceContainer()->getHookContainer() );

		$exporter = $this->getServiceContainer()->getWikiExporterFactory()
			->getWikiExporter( $this->getDb(), WikiExporter::FULL );
		$sink = new DumpStringOutput();
		$exporter->setOutputSink( $sink );
		$exporter->openStream();
		try {
			foreach ( $titles as $title ) {
				$exporter->pageByTitle( $title );
			}
			$this->fail( 'The export was not stopped' );
		} catch ( ExportTimeLimitException $e ) {
			$xml = $sink . ExportTimeLimit::closing( $e->inPage, 'note' );
		}

		$doc = simplexml_load_string( $xml );
		$this->assertNotFalse( $doc, $xml );
		$this->assertCount( $pages, $doc->xpath( '//*[local-name()="page"]' ) );
		$this->assertCount( $revisions, $doc->xpath( '//*[local-name()="revision"]' ) );
		$this->assertStringEndsWith( "<!-- note -->\n</mediawiki>\n", $xml );
	}
}
