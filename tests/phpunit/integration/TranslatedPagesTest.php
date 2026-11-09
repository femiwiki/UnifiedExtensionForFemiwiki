<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\TranslatedPages;
use MediaWiki\Output\OutputPage;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Database
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\TranslatedPages
 */
class TranslatedPagesTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'CanonicalServer' => 'https://femiwiki.example',
			'ArticlePath' => '/w/$1',
		] );
	}

	private function newOutput( Title $title ): OutputPage {
		$context = new RequestContext();
		$context->setTitle( $title );
		$out = $context->getOutput();
		$out->setArticleFlag( true );
		return $out;
	}

	private function getAlternates( OutputPage $out ): array {
		$alternates = [];
		foreach ( $out->getLinkTags() as $link ) {
			if ( ( $link['rel'] ?? null ) === 'alternate' && isset( $link['hreflang'] ) ) {
				$alternates[$link['hreflang']] = $link['href'];
			}
		}
		return $alternates;
	}

	public static function provideAddLinks() {
		yield 'translatable page' => [ 'Blog/22', null, false ];
		yield 'source language subpage' => [ 'Blog/22/ko', 'ko', true ];
		yield 'translation page' => [ 'Blog/22/en', 'en', false ];
	}

	/**
	 * @dataProvider provideAddLinks
	 */
	public function testAddLinks( string $shown, ?string $code, bool $canonicalToRoot ) {
		$out = $this->newOutput( Title::newFromText( $shown ) );

		TranslatedPages::addLinks(
			$out, Title::newFromText( 'Blog/22' ), $code, 'ko', [ 'ko' => 1.0, 'en' => '1.00' ]
		);

		$this->assertSame( [
			'en' => 'https://femiwiki.example/w/Blog/22/en',
			'ko' => 'https://femiwiki.example/w/Blog/22',
			'x-default' => 'https://femiwiki.example/w/Blog/22',
		], $this->getAlternates( $out ) );
		$this->assertSame(
			$canonicalToRoot ? 'https://femiwiki.example/w/Blog/22' : false,
			$out->getCanonicalUrl()
		);
	}

	public function testNothingTranslated() {
		$out = $this->newOutput( Title::newFromText( 'Blog/22/ko' ) );

		TranslatedPages::addLinks( $out, Title::newFromText( 'Blog/22' ), 'ko', 'ko', [ 'ko' => 1.0 ] );

		$this->assertSame( [], $this->getAlternates( $out ) );
		$this->assertSame( 'https://femiwiki.example/w/Blog/22', $out->getCanonicalUrl() );
	}

	public function testPageThatIsNotTranslatable() {
		$this->editPage( 'Not translatable', 'Text.' );
		$out = $this->newOutput( Title::newFromText( 'Not translatable' ) );

		( new TranslatedPages() )->onBeforePageDisplay( $out, $out->getSkin() );

		$this->assertSame( [], $this->getAlternates( $out ) );
		$this->assertFalse( $out->getCanonicalUrl() );
	}
}
