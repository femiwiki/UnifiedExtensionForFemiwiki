<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\Notifications\Formatters\EchoHtmlEmailFormatter;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\Main;
use MediaWikiIntegrationTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Standalone
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\Main::onUserMailerTransformContent
 */
class EmailThemeTest extends MediaWikiIntegrationTestCase {

	private function transform( bool $enabled, string $html ): string {
		$main = new Main( new HashConfig( [ 'UnifiedExtensionForFemiwikiModifyEmailTheme' => $enabled ] ) );
		$body = [ 'text' => '', 'html' => $html ];
		$error = false;
		$main->onUserMailerTransformContent( [], null, $body, $error );
		return $body['html'];
	}

	public function testRecolorsEchoLinks() {
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );
		$html = '<a style="' . EchoHtmlEmailFormatter::PRIMARY_LINK_STYLE . '">a</a>'
			. '<a style="text-decoration: none; color: #36C;">b</a>';

		$themed = $this->transform( true, $html );

		$this->assertStringContainsString( 'color: #bad75b;background: #414f0a;', $themed );
		$this->assertStringContainsString( 'text-decoration: none; color: #5144a3;', $themed );
		$this->assertStringNotContainsString( '#36C', $themed );
	}

	public function testDisabled() {
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );
		$html = '<a style="' . EchoHtmlEmailFormatter::PRIMARY_LINK_STYLE . '">a</a>';

		$this->assertSame( $html, $this->transform( false, $html ) );
	}
}
