<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AchievementBadges\Achievement;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;

/**
 * AchievementBadges badges for the users that a ranking bot lists on a ranking page, and for
 * the user it lists first.
 *
 * The bot saves each ranked user as a link to their Special:Contributions, so the users are
 * read from the special page links of the saved revision. Only revisions by users with the
 * `bot` right count, because the ranking pages are not protected.
 *
 * This does not implement AchievementBadges' hook interface, so that the class still loads
 * when AchievementBadges is not installed.
 */
class RankingAchievement implements \MediaWiki\Deferred\Hook\LinksUpdateCompleteHook {

	public const ACHIEVEMENT_KEY = 'femiwiki-ranking';

	public const FIRST_ACHIEVEMENT_KEY = 'femiwiki-ranking-first';

	private const IMAGES = 'extensions/UnifiedExtensionForFemiwiki/images';

	private const CONFIG_KEY = 'UnifiedExtensionForFemiwikiRankingPages';

	public function __construct(
		private Config $config,
		private PermissionManager $permissionManager,
		private SpecialPageFactory $specialPageFactory,
		private TitleFactory $titleFactory,
		private UserFactory $userFactory
	) {
	}

	/**
	 * Handler of AchievementBadges' BeforeCreateAchievement hook
	 *
	 * @param array &$achievements
	 */
	public function onBeforeCreateAchievement( array &$achievements ) {
		if ( !$this->config->get( self::CONFIG_KEY ) ) {
			return;
		}
		foreach ( [ self::ACHIEVEMENT_KEY => 600, self::FIRST_ACHIEVEMENT_KEY => 610 ] as $key => $priority ) {
			$achievements[$key] = [
				'type' => 'instant',
				// After the badges for everyday editing, which are 500 at most
				'priority' => $priority,
				'icon' => self::IMAGES . "/achievement-$key.svg",
				'og-image' => self::IMAGES . "/achievement-$key.png",
			];
		}
	}

	/** @inheritDoc */
	public function onLinksUpdateComplete( $linksUpdate, $ticket ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'AchievementBadges' )
			|| !$this->isRankingPage( $linksUpdate->getTitle()->getPrefixedText() )
		) {
			return;
		}
		$revision = $linksUpdate->getRevisionRecord();
		$author = $revision ? $revision->getUser( RevisionRecord::RAW ) : null;
		if ( !$author || !$this->permissionManager->userHasRight( $author, 'bot' ) ) {
			return;
		}

		$links = $linksUpdate->getParserOutput()->getLinkList( ParserOutputLinkTypes::SPECIAL );
		// The links keep the order of the page, so the first user is the first place
		$first = true;
		foreach ( $links as [ 'link' => $link ] ) {
			[ $name, $subpage ] = $this->specialPageFactory->resolveAlias( $link->getDBkey() );
			if ( $name !== 'Contributions' || $subpage === null ) {
				continue;
			}
			$user = $this->userFactory->newFromName( $subpage );
			if ( !$user || !$user->isRegistered() ) {
				continue;
			}
			// AchievementBadges skips users who already have the badge
			Achievement::achieve( [ 'key' => self::ACHIEVEMENT_KEY, 'user' => $user ] );
			if ( $first ) {
				Achievement::achieve( [ 'key' => self::FIRST_ACHIEVEMENT_KEY, 'user' => $user ] );
				$first = false;
			}
		}
	}

	private function isRankingPage( string $title ): bool {
		foreach ( $this->config->get( self::CONFIG_KEY ) as $page ) {
			if ( $this->titleFactory->newFromText( $page )?->getPrefixedText() === $title ) {
				return true;
			}
		}
		return false;
	}
}
