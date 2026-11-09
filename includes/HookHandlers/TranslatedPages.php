<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use MediaWiki\Extension\Translate\PageTranslation\TranslatablePage;
use MediaWiki\Language\LanguageCode;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

/**
 * Tells search engines how the language versions of a translatable page fit together.
 *
 * Translate copies the source text to a subpage named after the source language (Foo/ko), which
 * duplicates Foo, so that subpage gets Foo as its canonical URL. Every version also gets
 * rel=alternate hreflang links in <head>: Translate gives hreflang only to the sidebar links in
 * the body, and core leaves interlanguage alternates to the body too (T326829), but search
 * engines read hreflang from <head>.
 */
class TranslatedPages implements BeforePageDisplayHook {

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !$out->isArticle() || !ExtensionRegistry::getInstance()->isLoaded( 'Translate' ) ) {
			return;
		}
		$title = $out->getTitle();
		if ( !$title || !$title->exists() ) {
			return;
		}

		$code = null;
		if ( TranslatablePage::isSourcePage( $title ) ) {
			$page = TranslatablePage::newFromTitle( $title );
		} elseif ( $title->isSubpage() ) {
			$page = TranslatablePage::isTranslationPage( $title );
			if ( !$page ) {
				return;
			}
			$code = $title->getSubpageText();
		} else {
			return;
		}

		self::addLinks(
			$out,
			$page->getTitle(),
			$code,
			$page->getSourceLanguageCode(),
			$page->getTranslationPercentages()
		);
	}

	/**
	 * @param OutputPage $out
	 * @param Title $root The translatable page
	 * @param ?string $code Language of the subpage shown, null for the translatable page itself
	 * @param string $sourceCode Source language of the translatable page
	 * @param array<string,float|string> $percentages As for getLanguageVersions()
	 */
	public static function addLinks(
		OutputPage $out, Title $root, ?string $code, string $sourceCode, array $percentages
	): void {
		if ( $code === $sourceCode ) {
			$out->setCanonicalUrl( $root->getCanonicalURL() );
		}

		foreach ( self::getLanguageVersions( $sourceCode, $percentages ) as $hreflang => $subpage ) {
			$target = $subpage === null ? $root : $root->getSubpage( $subpage );
			if ( !$target ) {
				continue;
			}
			$out->addLink( [
				'rel' => 'alternate',
				'hreflang' => $hreflang,
				'href' => $target->getCanonicalURL(),
			] );
		}
	}

	/**
	 * @param string $sourceCode Source language of the translatable page
	 * @param array<string,float|string> $percentages Translated share of each translation page,
	 *  as TranslatablePage::getTranslationPercentages() gives it
	 * @return array<string,?string> Subpage of each hreflang value, null for the translatable page
	 *  itself, which stands in for the source language and x-default. Empty when nothing is
	 *  translated yet.
	 */
	public static function getLanguageVersions( string $sourceCode, array $percentages ): array {
		$versions = [];
		foreach ( $percentages as $code => $percentage ) {
			$code = (string)$code;
			// A page with no unit translated shows only the source text
			if ( $code !== $sourceCode && (float)$percentage > 0 ) {
				$versions[LanguageCode::bcp47( $code )] = $code;
			}
		}
		if ( !$versions ) {
			return [];
		}
		$versions[LanguageCode::bcp47( $sourceCode )] = null;
		ksort( $versions );
		$versions['x-default'] = null;
		return $versions;
	}
}
