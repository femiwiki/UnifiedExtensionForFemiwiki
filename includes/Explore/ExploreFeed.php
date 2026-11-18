<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Explore;

use MediaWiki\Linker\LinkTargetLookup;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Title\TitleValue;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * The pages "더 둘러보기" shows below an article, in this order:
 *
 * 1. Articles the page links to and articles that link to it, shuffled.
 * 2. Articles in the topics the reader asked to see more of, at random.
 * 3. Any article at random, except those in the topics the reader asked to see less of.
 *
 * Topics are the categories whose names start with a prefix, such as `문서_주제/`. The first two
 * parts are cached for each page and set of preferences; the third is endless and is drawn
 * anew for every request.
 */
class ExploreFeed {

	public const SOURCE_LINK = 'link';
	public const SOURCE_TOPIC = 'topic';
	public const SOURCE_RANDOM = 'random';

	/** How long the first two parts are kept */
	private const TTL = 12 * WANObjectCache::TTL_HOUR;

	/** Most linked and linking articles taken in */
	private const MAX_LINKS = 60;

	/** Articles taken from each topic the reader asked to see more of */
	private const PER_TOPIC = 30;

	/** Most articles taken from the topics together */
	private const MAX_TOPIC_PAGES = 60;

	public function __construct(
		private IConnectionProvider $connectionProvider,
		private LinkTargetLookup $linkTargetLookup,
		private WANObjectCache $cache,
		private string $topicPrefix
	) {
	}

	/**
	 * @return string Topic category names start with this, as a DB key; empty if topics are off
	 */
	public function getTopicPrefix(): string {
		return $this->topicPrefix;
	}

	/**
	 * Some pages of the feed.
	 *
	 * @param PageIdentity $page The page the feed is under
	 * @param int $offset Pages of the feed already shown
	 * @param int $limit
	 * @param string[] $more Topics to see more of, without the prefix
	 * @param string[] $less Topics to see less of, without the prefix
	 * @param int[] $excludeIds Pages not to show again in the random part
	 * @return array<int,string> Sources keyed by page ID, in order
	 */
	public function getPages(
		PageIdentity $page,
		int $offset,
		int $limit,
		array $more,
		array $less,
		array $excludeIds = []
	): array {
		$more = $this->normalizeTopics( $more );
		$less = array_diff( $this->normalizeTopics( $less ), $more );
		$head = array_slice( $this->getHead( $page, $more, $less ), $offset, $limit, true );
		if ( count( $head ) >= $limit ) {
			return $head;
		}
		$exclude = array_merge( $excludeIds, array_keys( $head ), [ $page->getId() ] );
		foreach ( $this->getRandomPageIds( $limit - count( $head ), $less, $exclude ) as $id ) {
			$head[$id] = self::SOURCE_RANDOM;
		}
		return $head;
	}

	/**
	 * The linked articles and the articles from the topics to see more of.
	 *
	 * @param PageIdentity $page
	 * @param string[] $more
	 * @param string[] $less
	 * @return array<int,string> Sources keyed by page ID, in order
	 */
	private function getHead( PageIdentity $page, array $more, array $less ): array {
		sort( $more );
		sort( $less );
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey(
				'moretoexplore', $page->getId(), md5( json_encode( [ $more, $less ] ) )
			),
			self::TTL,
			function () use ( $page, $more, $less ) {
				$head = [];
				foreach ( $this->getLinkedPageIds( $page ) as $id ) {
					$head[$id] = self::SOURCE_LINK;
				}
				$exclude = array_merge( array_keys( $head ), [ $page->getId() ] );
				foreach ( $this->getTopicPageIds( $more, $less, $exclude ) as $id ) {
					$head[$id] = self::SOURCE_TOPIC;
				}
				return $head;
			}
		);
	}

	/**
	 * @param string[] $topics
	 * @return string[] DB keys, without the prefix, of topics that could be categories
	 */
	private function normalizeTopics( array $topics ): array {
		if ( $this->topicPrefix === '' ) {
			return [];
		}
		$keys = [];
		foreach ( $topics as $topic ) {
			$key = str_replace( ' ', '_', trim( (string)$topic ) );
			if ( $key !== '' ) {
				$keys[] = $key;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Articles the page links to and articles that link to it, shuffled.
	 *
	 * @param PageIdentity $page
	 * @return int[]
	 */
	private function getLinkedPageIds( PageIdentity $page ): array {
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$ids = $this->newArticleQueryBuilder( $dbr )
			->join( 'linktarget', null, [ 'lt_namespace = page_namespace', 'lt_title = page_title' ] )
			->join( 'pagelinks', null, 'pl_target_id = lt_id' )
			->andWhere( [ 'pl_from' => $page->getId() ] )
			->limit( self::MAX_LINKS )
			->caller( __METHOD__ )
			->fetchFieldValues();

		$targetId = $this->linkTargetLookup->getLinkTargetId(
			new TitleValue( $page->getNamespace(), $page->getDBkey() )
		);
		if ( $targetId !== null ) {
			$ids = array_merge( $ids, $this->newArticleQueryBuilder( $dbr )
				->join( 'pagelinks', null, 'pl_from = page_id' )
				->andWhere( [ 'pl_target_id' => $targetId ] )
				->limit( self::MAX_LINKS )
				->caller( __METHOD__ )
				->fetchFieldValues() );
		}

		$ids = array_values( array_diff( array_unique( array_map( 'intval', $ids ) ), [ $page->getId() ] ) );
		shuffle( $ids );
		return array_slice( $ids, 0, self::MAX_LINKS );
	}

	/**
	 * Articles from the topics to see more of, a few from each, shuffled together.
	 *
	 * @param string[] $more
	 * @param string[] $less
	 * @param int[] $exclude
	 * @return int[]
	 */
	private function getTopicPageIds( array $more, array $less, array $exclude ): array {
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$lessIds = $this->getCategoryIds( $less );
		$ids = [];
		foreach ( $this->getCategoryIds( $more ) as $categoryId ) {
			$queryBuilder = $this->newArticleQueryBuilder( $dbr, $lessIds )
				->join( 'categorylinks', 'topic_cl', 'topic_cl.cl_from = page_id' )
				->andWhere( [ 'topic_cl.cl_target_id' => $categoryId ] );
			if ( $exclude ) {
				$queryBuilder->andWhere( $dbr->expr( 'page_id', '!=', $exclude ) );
			}
			$ids = array_merge( $ids, $this->fetchRandomIds( $dbr, $queryBuilder, self::PER_TOPIC ) );
		}
		$ids = array_values( array_unique( $ids ) );
		shuffle( $ids );
		return array_slice( $ids, 0, self::MAX_TOPIC_PAGES );
	}

	/**
	 * @param int $limit
	 * @param string[] $less
	 * @param int[] $exclude
	 * @return int[]
	 */
	private function getRandomPageIds( int $limit, array $less, array $exclude ): array {
		if ( $limit <= 0 ) {
			return [];
		}
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$queryBuilder = $this->newArticleQueryBuilder( $dbr, $this->getCategoryIds( $less ) );
		if ( $exclude ) {
			$queryBuilder->andWhere( $dbr->expr( 'page_id', '!=', array_values( array_unique( $exclude ) ) ) );
		}
		return $this->fetchRandomIds( $dbr, $queryBuilder, $limit );
	}

	/**
	 * Wikitext articles that are neither redirects nor disambiguation pages, and in none of the
	 * given categories. Style sheets and data pages under articles are left out.
	 *
	 * @param IReadableDatabase $dbr
	 * @param int[] $excludedCategoryIds
	 * @return SelectQueryBuilder
	 */
	private function newArticleQueryBuilder(
		IReadableDatabase $dbr,
		array $excludedCategoryIds = []
	): SelectQueryBuilder {
		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( 'page_id' )
			->distinct()
			->from( 'page' )
			->leftJoin( 'page_props', null, [ 'pp_page = page_id', 'pp_propname' => 'disambiguation' ] )
			->where( [
				'page_namespace' => NS_MAIN,
				'page_is_redirect' => 0,
				'pp_page' => null,
			] )
			// Rows from before content models were stored may hold none: those are wikitext
			->andWhere( $dbr->expr( 'page_content_model', '=', CONTENT_MODEL_WIKITEXT )
				->or( 'page_content_model', '=', null ) );
		if ( $excludedCategoryIds ) {
			$queryBuilder
				->leftJoin( 'categorylinks', 'excluded_cl', [
					'excluded_cl.cl_from = page_id',
					'excluded_cl.cl_target_id' => $excludedCategoryIds,
				] )
				->andWhere( [ 'excluded_cl.cl_from' => null ] );
		}
		return $queryBuilder;
	}

	/**
	 * Random rows the way Special:Random picks them: start at a random page_random and wrap
	 * around, which avoids ORDER BY RAND().
	 *
	 * @param IReadableDatabase $dbr
	 * @param SelectQueryBuilder $queryBuilder
	 * @param int $limit
	 * @return int[]
	 */
	private function fetchRandomIds(
		IReadableDatabase $dbr,
		SelectQueryBuilder $queryBuilder,
		int $limit
	): array {
		// DISTINCT needs the column it orders by
		$queryBuilder->select( [ 'page_id', 'page_random' ] )->caller( __METHOD__ );
		$random = wfRandom();
		$ids = [];
		foreach ( [ '>=', '<' ] as $op ) {
			$rows = ( clone $queryBuilder )
				->andWhere( $dbr->expr( 'page_random', $op, $random ) )
				->orderBy( 'page_random' )
				->limit( $limit - count( $ids ) )
				->fetchResultSet();
			foreach ( $rows as $row ) {
				$ids[] = (int)$row->page_id;
			}
			if ( count( $ids ) >= $limit ) {
				break;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param string[] $topics DB keys without the prefix
	 * @return int[] Link target IDs of the topics' categories that some page is in
	 */
	private function getCategoryIds( array $topics ): array {
		$ids = [];
		foreach ( $topics as $topic ) {
			$id = $this->linkTargetLookup->getLinkTargetId(
				new TitleValue( NS_CATEGORY, $this->topicPrefix . $topic )
			);
			if ( $id !== null ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * @param int[] $pageIds
	 * @return array<int,string[]> Topics of each page, without the prefix, keyed by page ID
	 */
	public function getTopics( array $pageIds ): array {
		if ( !$pageIds || $this->topicPrefix === '' ) {
			return [];
		}
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'cl_from', 'lt_title' ] )
			->from( 'categorylinks' )
			->join( 'linktarget', null, 'lt_id = cl_target_id' )
			->where( [
				'cl_from' => $pageIds,
				'lt_namespace' => NS_CATEGORY,
				$dbr->expr( 'lt_title', IExpression::LIKE,
					new LikeValue( $this->topicPrefix, $dbr->anyString() ) ),
			] )
			->orderBy( [ 'cl_from', 'lt_title' ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$topics = [];
		$length = strlen( $this->topicPrefix );
		foreach ( $rows as $row ) {
			$topic = substr( $row->lt_title, $length );
			// Only the topics themselves, not the categories under them
			if ( $topic !== '' && !str_contains( $topic, '/' ) ) {
				$topics[(int)$row->cl_from][] = $topic;
			}
		}
		return $topics;
	}
}
