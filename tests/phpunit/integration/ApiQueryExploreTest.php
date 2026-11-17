<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use MediaWiki\Api\ApiUsageException;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\Explore\ApiQueryExplore;
use MediaWiki\Tests\Api\ApiTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group API
 * @group Database
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\Explore\ApiQueryExplore
 */
class ApiQueryExploreTest extends ApiTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'UnifiedExtensionForFemiwikiExploreTopicPrefix', 'Topic/' );
	}

	public function addDBDataOnce() {
		$this->editPage( 'Center', '[[Linked]]' );
		$this->editPage( 'Linked', 'x [[Category:Topic/People]]' );
		$this->editPage( 'Person', 'x [[Category:Topic/People]]' );
		$this->editPage( 'Other', 'x' );
	}

	public function testList() {
		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'list' => 'femiwikiexplore',
			'fwetitle' => 'Center',
			'fwelimit' => 1,
		] );

		$this->assertSame( [ [
			'index' => 1,
			'source' => 'link',
			'topics' => [ 'People' ],
			'ns' => NS_MAIN,
			'title' => 'Linked',
			'pageid' => $this->getExistingTestPage( 'Linked' )->getId(),
		] ], $result['query']['femiwikiexplore'] );
		$this->assertSame( 1, $result['continue']['fweoffset'] );
	}

	public function testGeneratorWithTopicsToSeeMoreOf() {
		$user = $this->getTestUser()->getUser();
		$this->getServiceContainer()->getUserOptionsManager()
			->setOption( $user, ApiQueryExplore::MORE_OPTION, '["People"]' );

		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'generator' => 'femiwikiexplore',
			'gfwetitle' => 'Center',
			'gfwelimit' => 2,
			'formatversion' => 2,
		], null, false, $user );

		$pages = $result['query']['pages'];
		usort( $pages, static fn ( $a, $b ) => $a['index'] <=> $b['index'] );
		$this->assertSame( [ 'Linked', 'Person' ], array_column( $pages, 'title' ) );
		$this->assertSame( [ 'link', 'topic' ], array_column( $pages, 'source' ) );
	}

	public function testIsInternal() {
		[ $result ] = $this->doApiRequest( [
			'action' => 'paraminfo',
			'modules' => 'query+femiwikiexplore',
		] );

		$this->assertTrue( $result['paraminfo']['modules'][0]['internal'] ?? false );
	}

	public function testMissingTitle() {
		$this->expectException( ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'query',
			'list' => 'femiwikiexplore',
			'fwetitle' => 'Does not exist',
		] );
	}
}
