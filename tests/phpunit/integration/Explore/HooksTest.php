<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration\Explore;

use MediaWiki\Config\HashConfig;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\Explore\ApiQueryExplore;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\Explore\Hooks;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Database
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\Explore\Hooks
 */
class HooksTest extends MediaWikiIntegrationTestCase {

	public function addDBDataOnce() {
		$this->editPage( 'Article', 'x' );
		$this->editPage( 'Talk:Article', 'x' );
	}

	private function newHooks( bool $enabled ): Hooks {
		return new Hooks( new HashConfig( [
			'MoreToExploreEnabled' => $enabled,
			'MoreToExploreTopicPrefix' => '문서_주제/',
		] ) );
	}

	private function newOutput( string $title, array $query = [] ): OutputPage {
		$context = new RequestContext();
		$context->setTitle( Title::newFromText( $title ) );
		$context->setRequest( new FauxRequest( $query ) );
		$out = $context->getOutput();
		$out->setArticleFlag( true );
		return $out;
	}

	public static function provideBeforePageDisplay() {
		yield 'article' => [ true, 'Article', [], true ];
		yield 'turned off' => [ false, 'Article', [], false ];
		yield 'talk page' => [ true, 'Talk:Article', [], false ];
		yield 'missing page' => [ true, 'Missing', [], false ];
		yield 'history' => [ true, 'Article', [ 'action' => 'history' ], false ];
		yield 'old revision' => [ true, 'Article', [ 'oldid' => '1' ], false ];
	}

	/**
	 * @dataProvider provideBeforePageDisplay
	 */
	public function testBeforePageDisplay( bool $enabled, string $title, array $query, bool $shown ) {
		$out = $this->newOutput( $title, $query );

		$this->newHooks( $enabled )->onBeforePageDisplay( $out, $out->getSkin() );

		$this->assertSame( $shown, in_array( 'ext.moreToExplore', $out->getModules() ) );
		$this->assertSame( $shown, in_array( 'ext.moreToExplore.styles', $out->getModuleStyles() ) );
	}

	public function testGetPreferences() {
		$preferences = [];

		$this->newHooks( true )->onGetPreferences( $this->getTestUser()->getUser(), $preferences );

		$this->assertSame( [
			ApiQueryExplore::MORE_OPTION => [ 'type' => 'api' ],
			ApiQueryExplore::LESS_OPTION => [ 'type' => 'api' ],
		], $preferences );
	}

	public function testResourceLoaderGetConfigVars() {
		$vars = [];
		$this->newHooks( true )->onResourceLoaderGetConfigVars( $vars, 'femiwiki', new HashConfig() );
		$this->assertSame( [ 'wgMoreToExploreTopicPrefix' => '문서 주제/' ], $vars );

		$vars = [];
		$this->newHooks( false )->onResourceLoaderGetConfigVars( $vars, 'femiwiki', new HashConfig() );
		$this->assertSame( [], $vars );
	}
}
