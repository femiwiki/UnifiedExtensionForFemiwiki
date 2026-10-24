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

		$this->assertStringContainsString( 'background: #5144a3;', $themed );
		$this->assertStringContainsString( 'text-decoration: none; color: #5144a3;', $themed );
		$this->assertStringNotContainsString( '#36C', $themed );
	}

	public function testRecolorsConfirmEmail() {
		$html = '<html><head><style>#mw-confirm-email .mw-confirm-email__button { background: #36C; }</style></head>'
			. '<body><div id="mw-confirm-email"></div></body></html>';

		$themed = $this->transform( true, $html );

		$this->assertMatchesRegularExpression( '/#36C;.*background: #5144a3;.*<\/head>/s', $themed );
	}

	public function testLeavesOtherMails() {
		$html = '<html><head></head><body></body></html>';

		$this->assertSame( $html, $this->transform( true, $html ) );
	}

	public function testDisabled() {
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );
		$html = '<a style="' . EchoHtmlEmailFormatter::PRIMARY_LINK_STYLE . '">a</a>';

		$this->assertSame( $html, $this->transform( false, $html ) );
	}
}
