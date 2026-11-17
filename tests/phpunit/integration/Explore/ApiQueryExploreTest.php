<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration\Explore;

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
		$this->overrideConfigValue( 'MoreToExploreTopicPrefix', 'Topic/' );
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
			'list' => 'moretoexplore',
			'mtetitle' => 'Center',
			'mtelimit' => 1,
		] );

		$this->assertSame( [ [
			'index' => 1,
			'source' => 'link',
			'topics' => [ 'People' ],
			'ns' => NS_MAIN,
			'title' => 'Linked',
			'pageid' => $this->getExistingTestPage( 'Linked' )->getId(),
		] ], $result['query']['moretoexplore'] );
		$this->assertSame( 1, $result['continue']['mteoffset'] );
	}

	public function testGeneratorWithTopicsToSeeMoreOf() {
		$user = $this->getTestUser()->getUser();
		$this->getServiceContainer()->getUserOptionsManager()
			->setOption( $user, ApiQueryExplore::MORE_OPTION, '["People"]' );

		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'generator' => 'moretoexplore',
			'gmtetitle' => 'Center',
			'gmtelimit' => 2,
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
			'modules' => 'query+moretoexplore',
		] );

		$this->assertTrue( $result['paraminfo']['modules'][0]['internal'] ?? false );
	}

	public function testHelp() {
		[ $result ] = $this->doApiRequest( [
			'action' => 'help',
			'modules' => 'query+moretoexplore',
			'wrap' => true,
		] );

		// The examples
		$this->assertStringContainsString( 'gmtetitle=Main', $result['help']['help'] );
	}

	public function testMissingTitle() {
		$this->expectException( ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'query',
			'list' => 'moretoexplore',
			'mtetitle' => 'Does not exist',
		] );
	}
}
