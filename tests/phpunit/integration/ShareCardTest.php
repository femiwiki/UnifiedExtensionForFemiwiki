<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\ShareCard;
use MediaWiki\Output\OutputPage;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Database
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\ShareCard
 */
class ShareCardTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'UnifiedExtensionForFemiwikiShareCard' => true,
			'CanonicalServer' => 'https://femiwiki.example',
			'ScriptPath' => '',
		] );
	}

	private function view( Title $title ): OutputPage {
		$context = new RequestContext();
		$context->setTitle( $title );
		$out = $context->getOutput();
		$out->setArticleFlag( true );
		$services = $this->getServiceContainer();
		( new ShareCard(
			$services->getMainConfig(),
			$services->getPageProps(),
			$services->getNamespaceInfo(),
			$services->getUrlUtils()
		) )->onBeforePageDisplay( $out, $context->getSkin() );
		return $out;
	}

	private function getMeta( OutputPage $out ): array {
		$meta = [];
		foreach ( $out->getMetaTags() as [ $name, $content ] ) {
			$meta[$name] = $content;
		}
		return $meta;
	}

	public function testPageWithoutImage() {
		$status = $this->editPage( 'Share card page', 'First paragraph.' );
		$revId = $status->getNewRevision()->getId();

		$meta = $this->getMeta( $this->view( Title::newFromText( 'Share card page' ) ) );

		$this->assertSame( "https://femiwiki.example/rest.php/femiwiki/v1/share-card/$revId", $meta['og:image'] );
		$this->assertSame( '1200', $meta['og:image:width'] );
		$this->assertSame( '630', $meta['og:image:height'] );
		$this->assertSame( 'Share card page', $meta['og:image:alt'] );
	}

	public function testPageWithImage() {
		$page = $this->editPage( 'Share card page with image', 'Text' )->getNewRevision()->getPage();
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'page_props' )
			->row( [ 'pp_page' => $page->getId(), 'pp_propname' => 'page_image_free', 'pp_value' => 'Example.jpg' ] )
			->caller( __METHOD__ )
			->execute();

		$meta = $this->getMeta( $this->view( Title::newFromText( 'Share card page with image' ) ) );

		$this->assertArrayNotHasKey( 'og:image', $meta );
	}

	public function testNotContentPage() {
		$this->editPage( 'Talk:Share card page', 'Text' );

		$meta = $this->getMeta( $this->view( Title::newFromText( 'Talk:Share card page' ) ) );

		$this->assertArrayNotHasKey( 'og:image', $meta );
	}

	public function testDisabled() {
		$this->overrideConfigValue( 'UnifiedExtensionForFemiwikiShareCard', false );
		$this->editPage( 'Share card page', 'Text' );

		$meta = $this->getMeta( $this->view( Title::newFromText( 'Share card page' ) ) );

		$this->assertArrayNotHasKey( 'og:image', $meta );
	}
}
