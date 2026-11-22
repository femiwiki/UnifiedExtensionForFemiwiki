<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration\Explore;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\Explore\ExploreFeed;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Database
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\Explore\ExploreFeed
 */
class ExploreFeedTest extends MediaWikiIntegrationTestCase {

	public function addDBDataOnce() {
		$this->editPage( 'Category:Topic/People', 'x' );
		$this->editPage( 'Category:Topic/Places', 'x' );
		$this->editPage(
			'Center',
			'[[Linked]] [[Linked redirect]] [[Linked disambiguation]] [[Talk:Linked]] [[Linked/styles.css]]'
		);
		$this->editPage( 'Linked', 'x [[Category:Topic/People]] [[Category:Topic/People/Women]]' );
		$this->editPage( 'Linking', '[[Center]] [[Category:Topic/Places]]' );
		$this->editPage( 'Linked redirect', '#REDIRECT [[Linked]]' );
		$this->editPage( 'Linked disambiguation', 'x' );
		$this->editPage( 'Talk:Linked', 'x' );
		$this->editPage( 'Linked/styles.css', 'x' );
		$this->editPage( 'Person', 'x [[Category:Topic/People]]' );
		$this->editPage( 'Place', 'x [[Category:Topic/Places]]' );
		$this->editPage( 'Unrelated', 'x [[Category:Other]]' );

		// What Disambiguator saves for __DISAMBIG__
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'page_props' )
			->row( [
				'pp_page' => Title::newFromText( 'Linked disambiguation' )->getId(),
				'pp_propname' => 'disambiguation',
				'pp_value' => '',
			] )
			->caller( __METHOD__ )
			->execute();
		// Such as a TemplateStyles style sheet
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'page' )
			->set( [ 'page_content_model' => CONTENT_MODEL_CSS ] )
			->where( [ 'page_id' => Title::newFromText( 'Linked/styles.css' )->getId() ] )
			->caller( __METHOD__ )
			->execute();
	}

	private function newFeed( string $prefix = 'Topic/' ): ExploreFeed {
		return new ExploreFeed(
			$this->getServiceContainer()->getConnectionProvider(),
			$this->getServiceContainer()->getLinkTargetLookup(),
			new WANObjectCache( [ 'cache' => new HashBagOStuff() ] ),
			$prefix
		);
	}

	private static function titles( array $pages ): array {
		$titles = [];
		foreach ( $pages as $id => $source ) {
			$titles[Title::newFromID( $id )->getPrefixedText()] = $source;
		}
		return $titles;
	}

	public function testLinksComeFirst() {
		$pages = self::titles( $this->newFeed()->getPages( Title::newFromText( 'Center' ), 0, 2, [], [] ) );

		$this->assertEqualsCanonicalizing(
			[ 'Linked' => ExploreFeed::SOURCE_LINK, 'Linking' => ExploreFeed::SOURCE_LINK ],
			$pages,
			'Articles linked either way, without redirects, disambiguation pages or talk pages'
		);
	}

	public function testOnlyWikitext() {
		$pages = self::titles( $this->newFeed()->getPages( Title::newFromText( 'Center' ), 0, 20, [], [] ) );

		$this->assertArrayNotHasKey( 'Linked/styles.css', $pages );
	}

	public function testRandomPagesFollow() {
		$center = Title::newFromText( 'Center' );
		$pages = self::titles( $this->newFeed()->getPages( $center, 2, 10, [], [] ) );

		$this->assertNotContains( ExploreFeed::SOURCE_LINK, $pages );
		$this->assertArrayNotHasKey( 'Center', $pages, 'Not the page itself' );
		$this->assertArrayNotHasKey( 'Linked disambiguation', $pages );
		$this->assertArrayNotHasKey( 'Linked redirect', $pages );
		$this->assertArrayHasKey( 'Unrelated', $pages );
	}

	public function testSameWindowSameFeed() {
		$center = Title::newFromText( 'Center' );
		$first = $this->newFeed()->getPages( $center, 0, 10, [ 'People' ], [], [], 'w1' );
		$again = $this->newFeed()->getPages( $center, 0, 10, [ 'People' ], [], [], 'w1' );

		$this->assertSame( $first, $again, 'The same order, even with a cold cache' );
	}

	public function testRandomPagesWalkOn() {
		$center = Title::newFromText( 'Center' );
		$feed = $this->newFeed();
		// After the two linked articles, one random article a batch, from Person, Place and Unrelated
		$ids = [];
		foreach ( [ 2, 3, 4 ] as $offset ) {
			$batch = $feed->getPages( $center, $offset, 1, [], [], [], 'w1' );
			$this->assertSame( [ ExploreFeed::SOURCE_RANDOM ], array_values( $batch ) );
			$ids[] = array_key_first( $batch );
		}

		$this->assertCount( 3, array_unique( $ids ), 'Each batch goes on along the walk' );
	}

	public function testMoreTopicsComeAfterLinks() {
		$pages = self::titles(
			$this->newFeed()->getPages( Title::newFromText( 'Center' ), 0, 3, [ 'People' ], [] )
		);

		$this->assertSame( ExploreFeed::SOURCE_TOPIC, $pages['Person'] ?? null );
		$this->assertSame( ExploreFeed::SOURCE_TOPIC, array_values( $pages )[2] );
	}

	public function testLessTopicsAreLeftOutOfRandomPages() {
		$pages = self::titles(
			$this->newFeed()->getPages( Title::newFromText( 'Center' ), 2, 10, [], [ 'Places' ] )
		);

		$this->assertArrayNotHasKey( 'Place', $pages );
		$this->assertArrayHasKey( 'Person', $pages );
	}

	public function testTopicsOff() {
		$feed = $this->newFeed( '' );
		$pages = self::titles( $feed->getPages( Title::newFromText( 'Center' ), 2, 10, [ 'People' ], [ 'Places' ] ) );

		$this->assertNotContains( ExploreFeed::SOURCE_TOPIC, $pages );
		$this->assertArrayHasKey( 'Place', $pages );
		$this->assertSame( [], $feed->getTopics( [ Title::newFromText( 'Linked' )->getId() ] ) );
	}

	public function testGetTopics() {
		$linked = Title::newFromText( 'Linked' )->getId();
		$unrelated = Title::newFromText( 'Unrelated' )->getId();

		$this->assertSame(
			[ $linked => [ 'People' ] ],
			$this->newFeed()->getTopics( [ $linked, $unrelated ] ),
			'Only topics, not the categories under them or other categories'
		);
	}
}
