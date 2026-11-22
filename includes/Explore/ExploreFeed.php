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
 * Topics are the categories whose names start with a prefix, such as `문서_주제/`. The third
 * part is endless.
 *
 * Every "random" choice is seeded by the page and a twelve hour window, so the feed under a page
 * stays the same through the window for everyone with the same preferences: a reader who goes
 * back to a page finds the feed they left, and logged-out responses can be cached.
 */
class ExploreFeed {

	public const SOURCE_LINK = 'link';
	public const SOURCE_TOPIC = 'topic';
	public const SOURCE_RANDOM = 'random';

	/** How long the feed under a page stays the same */
	public const WINDOW = 12 * WANObjectCache::TTL_HOUR;

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
	 * @param int $time Unix time
	 * @return string The window $time falls in, for getPages()
	 */
	public static function getWindow( int $time ): string {
		return (string)intdiv( $time, self::WINDOW );
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
	 * @param string $window Seeds the random choices with the page; see getWindow()
	 * @return array<int,string> Sources keyed by page ID, in order
	 */
	public function getPages(
		PageIdentity $page,
		int $offset,
		int $limit,
		array $more,
		array $less,
		array $excludeIds = [],
		string $window = ''
	): array {
		$more = $this->normalizeTopics( $more );
		$less = array_diff( $this->normalizeTopics( $less ), $more );
		$seed = $page->getId() . '|' . $window;
		$fullHead = $this->getHead( $page, $more, $less, $seed );
		$head = array_slice( $fullHead, $offset, $limit, true );
		if ( count( $head ) >= $limit ) {
			return $head;
		}
		$exclude = array_merge( $excludeIds, array_keys( $fullHead ), [ $page->getId() ] );
		$skip = max( 0, $offset - count( $fullHead ) );
		foreach ( $this->getRandomPageIds( $skip, $limit - count( $head ), $less, $exclude, $seed ) as $id ) {
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
	 * @param string $seed
	 * @return array<int,string> Sources keyed by page ID, in order
	 */
	private function getHead( PageIdentity $page, array $more, array $less, string $seed ): array {
		sort( $more );
		sort( $less );
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey(
				'moretoexplore', 'v2', $page->getId(), md5( json_encode( [ $more, $less, $seed ] ) )
			),
			self::WINDOW,
			function () use ( $page, $more, $less, $seed ) {
				$head = [];
				foreach ( $this->getLinkedPageIds( $page, $seed ) as $id ) {
					$head[$id] = self::SOURCE_LINK;
				}
				$exclude = array_merge( array_keys( $head ), [ $page->getId() ] );
				foreach ( $this->getTopicPageIds( $more, $less, $exclude, $seed ) as $id ) {
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
	 * @param string $seed
	 * @return int[]
	 */
	private function getLinkedPageIds( PageIdentity $page, string $seed ): array {
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
		return array_slice( self::shuffle( $ids, $seed ), 0, self::MAX_LINKS );
	}

	/**
	 * Articles from the topics to see more of, a few from each, shuffled together.
	 *
	 * @param string[] $more
	 * @param string[] $less
	 * @param int[] $exclude
	 * @param string $seed
	 * @return int[]
	 */
	private function getTopicPageIds( array $more, array $less, array $exclude, string $seed ): array {
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
			$ids = array_merge( $ids, $this->fetchRandomIds(
				$dbr, $queryBuilder, 0, self::PER_TOPIC, "$seed|$categoryId"
			) );
		}
		$ids = array_values( array_unique( $ids ) );
		return array_slice( self::shuffle( $ids, $seed ), 0, self::MAX_TOPIC_PAGES );
	}

	/**
	 * @param int $skip Random pages already shown
	 * @param int $limit
	 * @param string[] $less
	 * @param int[] $exclude
	 * @param string $seed
	 * @return int[]
	 */
	private function getRandomPageIds(
		int $skip,
		int $limit,
		array $less,
		array $exclude,
		string $seed
	): array {
		if ( $limit <= 0 ) {
			return [];
		}
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$queryBuilder = $this->newArticleQueryBuilder( $dbr, $this->getCategoryIds( $less ) );
		if ( $exclude ) {
			$queryBuilder->andWhere( $dbr->expr( 'page_id', '!=', array_values( array_unique( $exclude ) ) ) );
		}
		return $this->fetchRandomIds( $dbr, $queryBuilder, $skip, $limit, $seed );
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
	 * Random rows the way Special:Random picks them: start at a page_random and wrap around,
	 * which avoids ORDER BY RAND(). The start comes from the seed, so the same seed walks the
	 * same rows, and $skip goes further along that walk.
	 *
	 * @param IReadableDatabase $dbr
	 * @param SelectQueryBuilder $queryBuilder
	 * @param int $skip Rows of the walk to pass over
	 * @param int $limit
	 * @param string $seed
	 * @return int[]
	 */
	private function fetchRandomIds(
		IReadableDatabase $dbr,
		SelectQueryBuilder $queryBuilder,
		int $skip,
		int $limit,
		string $seed
	): array {
		// DISTINCT needs the column it orders by
		$queryBuilder->select( [ 'page_id', 'page_random' ] )->caller( __METHOD__ );
		$start = self::unitInterval( $seed );
		$after = ( clone $queryBuilder )->andWhere( $dbr->expr( 'page_random', '>=', $start ) );
		$before = ( clone $queryBuilder )->andWhere( $dbr->expr( 'page_random', '<', $start ) );

		if ( $skip > 0 ) {
			$afterCount = ( clone $after )->clearFields()->select( 'page_id' )->fetchRowCount();
			$total = $afterCount + ( clone $before )->clearFields()->select( 'page_id' )->fetchRowCount();
			if ( $total === 0 ) {
				return [];
			}
			// An endless feed goes round again
			$skip %= $total;
			if ( $skip >= $afterCount ) {
				// The walk is already past the end and into the rows before the start
				$skip -= $afterCount;
				$segments = [ $before, $after ];
			} else {
				$segments = [ $after, $before ];
			}
		} else {
			$segments = [ $after, $before ];
		}

		$ids = [];
		foreach ( $segments as $segment ) {
			$rows = $segment
				->orderBy( 'page_random' )
				->offset( $skip )
				->limit( $limit - count( $ids ) )
				->fetchResultSet();
			$skip = 0;
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
	 * @param string $seed
	 * @return float In [0, 1), the same for the same seed
	 */
	private static function unitInterval( string $seed ): float {
		return hexdec( substr( md5( $seed ), 0, 8 ) ) / 0x100000000;
	}

	/**
	 * @param int[] $ids
	 * @param string $seed
	 * @return int[] The same IDs in an order that only the seed decides
	 */
	private static function shuffle( array $ids, string $seed ): array {
		$keys = [];
		foreach ( $ids as $id ) {
			$keys[$id] = md5( "$seed|$id" );
		}
		asort( $keys );
		return array_keys( $keys );
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
