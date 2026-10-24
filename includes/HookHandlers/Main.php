<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use Config;
use ExtensionRegistry;
use MediaWiki\Extension\Notifications\Formatters\EchoHtmlEmailFormatter;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;
use RequestContext;
use Skin;
use Wikibase\Client\WikibaseClient;

class Main implements
	\MediaWiki\Linker\Hook\LinkerMakeExternalLinkHook,
	\MediaWiki\Hook\SidebarBeforeOutputHook,
	\MediaWiki\Hook\SkinAddFooterLinksHook,
	\MediaWiki\Hook\UserMailerTransformContentHook,
	\MediaWiki\Linker\Hook\HtmlPageLinkRendererBeginHook
	{

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @param Config $config
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Add a few links to the footer.
	 *
	 * @inheritDoc
	 */
	public function onSkinAddFooterLinks( Skin $skin, string $key, array &$footerItems ) {
		if ( $key !== 'places' ) {
			return true;
		}

		$termsDestination = Skin::makeInternalOrExternalUrl(
			$skin->msg( 'femiwiki-terms-page' )->inContentLanguage()->text()
		);
		$footerItems['femiwiki-terms'] = Html::element(
			'a',
			[ 'href' => $termsDestination ],
			$skin->msg( 'femiwiki-terms-label' )->text()
		);

		$infringementDestination = Skin::makeInternalOrExternalUrl(
			$skin->msg( 'femiwiki-support-page' )->inContentLanguage()->text()
		);
		$footerItems['femiwiki-support'] = Html::element(
			'a',
			[ 'href' => $infringementDestination ],
			$skin->msg( 'femiwiki-support-label' )->text()
		);
	}

	/**
	 * Treat external links to FemiWiki as internal links.
	 *
	 * @inheritDoc
	 */
	public function onLinkerMakeExternalLink( &$url, &$text, &$link, &$attribs,
		$linkType
	) {
		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			return true;
		}
		// Parsoid (DataAccess::getExternalUrlInfo) passes no class and ignores $link
		if ( !isset( $attribs['class'] ) ) {
			return true;
		}
		$canonicalServer = RequestContext::getMain()->getConfig()->get( 'CanonicalServer' );
		if ( strpos( $canonicalServer, parse_url( $url, PHP_URL_HOST ) ) === false ) {
			return true;
		}

		$attribs['class'] = str_replace( 'external', '', $attribs['class'] );
		$attribs['href'] = $url;
		unset( $attribs['target'] );

		$link = Html::rawElement( 'a', $attribs, $text );
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		$this->addWikibaseNewItemLink( $skin, $sidebar );
		$this->sidebarConvertLinks( $sidebar );
	}

	/**
	 * Add a link to create new Wikibase item in toolbox when the title is not linked with any item.
	 *
	 * - Wikibase\Client\Hooks\SidebarHookHandler::onSidebarBeforeOutput (REL1_35)
	 * - Wikibase\Client\ClientHooks::onBaseTemplateToolbox (REL1_35)
	 * - Wikibase\Client\RepoItemLinkGenerator::getNewItemUrl (REL1_35)
	 *
	 * @param Skin $skin
	 * @param array &$sidebar
	 * @return void
	 */
	private function addWikibaseNewItemLink( $skin, &$sidebar ): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'WikibaseClient' ) ||
			$this->pageHasWikibaseItem( $skin ) ) {
			return;
		}
		$title = $skin->getTitle();
		$repoLinker = WikibaseClient::getRepoLinker();

		$params = [
			'site' => WikibaseClient::getSettings()->getSetting( 'siteGlobalID' ),
			'page' => $title->getPrefixedText()
		];

		$url = $repoLinker->getPageUrl( 'Special:NewItem' );
		$url = $repoLinker->addQueryParams( $url, $params );

		$sidebar['TOOLBOX']['wikibase'] = [
			'text' => $skin->msg( 'wikibase-dataitem' )->text(),
			'href' => $url,
			'id' => 't-wikibase'
		];
	}

	/**
	 * Whether the current page is already connected to a Wikibase item.
	 *
	 * Replaces Wikibase\Client\ClientHooks::buildWikidataItemLink(), which was
	 * removed from Wikibase on master. This replicates its check using only the
	 * WikibaseClient service accessors and EntityIdLookup, which are present and
	 * signature-identical on both REL1_46 and master.
	 *
	 * @param Skin $skin
	 * @return bool
	 */
	private function pageHasWikibaseItem( $skin ): bool {
		// The rendered page already carries the connected item id.
		if ( $skin->getOutput()->getProperty( 'wikibase_item' ) !== null ) {
			return true;
		}
		// Non-view actions: look it up from the store, as upstream did.
		$title = $skin->getTitle();
		if ( $title && $skin->getActionName() !== 'view' && $title->exists() ) {
			return WikibaseClient::getEntityIdLookup()->getEntityIdForTitle( $title ) !== null;
		}
		return false;
	}

	/**
	 * Treat external links to FemiWiki as internal links in the Sidebar.
	 * @param array &$bar
	 * @return void
	 */
	private function sidebarConvertLinks( &$bar ): void {
		$canonicalServer = $this->config->get( 'CanonicalServer' );

		foreach ( $bar as $heading => $content ) {
			foreach ( $content as $key => $item ) {
				if ( !isset( $item['href'] ) ) {
					continue;
				}
				$href = strval( parse_url( $item['href'], PHP_URL_HOST ) );
				if ( $href && strpos( $canonicalServer, $href ) !== false ) {
					unset( $bar[$heading][$key]['rel'] );
					unset( $bar[$heading][$key]['target'] );
				}
			}
		}
	}

	/**
	 * Do not show edit page when user clicks red link
	 * @inheritDoc
	 */
	public function onHtmlPageLinkRendererBegin( $linkRenderer, $target, &$text,
		&$customAttribs, &$query, &$ret
	) {
		// See https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/23
		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			return true;
		}

		$title = Title::newFromLinkTarget( $target );
		if ( !$title->isKnown() ) {
			$query['action'] = 'view';
			$query['redlink'] = '1';
		}

		return false;
	}

	/**
	 * Echo's footer link style, written inline in EchoHtmlEmailFormatter::getFooter()
	 */
	private const FOOTER_PREFERENCE_LINK_STYLE = 'text-decoration: none; color: #36C;';

	/**
	 * White on dark purple, because mail clients' dark modes rewrite light backgrounds and dark text.
	 */
	private const FEMIWIKI_PRIMARY_LINK_COLORS = [
		'background: #36C;' => 'background: #5144a3;',
	];
	private const FEMIWIKI_FOOTER_PREFERENCE_LINK_STYLE = 'text-decoration: none; color: #5144a3;';

	/**
	 * Overrides the colors of core's confirmation and notification mails
	 * (resources/templates/ConfirmEmail/EmailCreated.mustache), with the same colors as Echo's.
	 */
	private const FEMIWIKI_CONFIRM_EMAIL_STYLE = '<style>'
		. '#mw-confirm-email .mw-confirm-email__button, #mw-confirm-email .mw-confirm-email__button:hover, '
		. '#mw-confirm-email .mw-confirm-email__button:active { background: #5144a3; color: #fff; }'
		. '#mw-confirm-email .mw-confirm-email__button:focus { border-color: #5144a3; '
		. 'box-shadow: inset 0 0 0 1px #5144a3,inset 0 0 0 2px #fff; }'
		. '#mw-confirm-email .mw-confirm-email__footer a { color: #5144a3; }'
		. '</style>';

	/**
	 * Modifying HTML mails sent from core and Echo.
	 * @inheritDoc
	 */
	public function onUserMailerTransformContent( $to, $from, &$body, &$error ) {
		if (
			!$this->config->get( 'UnifiedExtensionForFemiwikiModifyEmailTheme' ) ||
			!is_array( $body ) ||
			!isset( $body['html'] )
		) {
			return;
		}

		if ( str_contains( $body['html'], 'id="mw-confirm-email"' ) ) {
			$body['html'] = preg_replace(
				'/<\/head>/i',
				self::FEMIWIKI_CONFIRM_EMAIL_STYLE . '</head>',
				$body['html'],
				1
			);
		}

		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) ) {
			return;
		}

		$primaryLinkStyle = EchoHtmlEmailFormatter::PRIMARY_LINK_STYLE;
		$body['html'] = strtr( $body['html'], [
			$primaryLinkStyle => strtr( $primaryLinkStyle, self::FEMIWIKI_PRIMARY_LINK_COLORS ),
			self::FOOTER_PREFERENCE_LINK_STYLE => self::FEMIWIKI_FOOTER_PREFERENCE_LINK_STYLE,
		] );
	}
}
