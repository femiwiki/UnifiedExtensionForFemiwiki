<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use GrowthExperiments\GrowthExperimentsServices;
use GrowthExperiments\HomepageModules\SuggestedEdits;
use GrowthExperiments\NewcomerTasks\ConfigurationLoader\ConfigurationLoader;
use GrowthExperiments\NewcomerTasks\NewcomerTasksUserOptionsLookup;
use GrowthExperiments\NewcomerTasks\Task\Task;
use GrowthExperiments\NewcomerTasks\Task\TaskSet;
use GrowthExperiments\NewcomerTasks\Task\TaskSetFilters;
use GrowthExperiments\NewcomerTasks\TaskSuggester\ErrorForwardingTaskSuggester;
use GrowthExperiments\NewcomerTasks\TaskType\TaskType;
use GrowthExperiments\NewcomerTasks\TaskType\TemplateBasedTaskType;
use GrowthExperiments\NewcomerTasks\Topic\ITopicRegistry;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\CategoryTopic;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\CategoryTopicRegistry;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\DatabaseTaskSuggester;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\DatabaseTaskSuggesterFactory;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\FeatureManager;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;
use Psr\Log\LoggerInterface;
use StatusValue;

/**
 * @group UnifiedExtensionForFemiwiki
 * @group Database
 * @group Standalone
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\DatabaseTaskSuggester
 */
class DatabaseTaskSuggesterTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'GrowthExperiments' )
			|| !$this->getServiceContainer()->hasService( 'GrowthExperimentsFeatureManager' )
		) {
			$this->markTestSkipped( 'Needs GrowthExperiments from MediaWiki 1.46 or later' );
		}
	}

	public function addDBDataOnce() {
		$this->editPage( 'Template:Copyedit', 'x' );
		$this->editPage( 'Template:Stub', 'x' );
		$this->editPage( 'Template:Ignore', 'x' );
		$this->editPage( 'Plain', '{{Copyedit}}[[Category:Feminism]]' );
		$this->editPage( 'Both', '{{Copyedit}}{{Stub}}[[Category:Games]]' );
		$this->editPage( 'Stub only', '{{Stub}}' );
		$this->editPage( 'Excluded by template', '{{Copyedit}}{{Ignore}}' );
		$this->editPage( 'Excluded by category', '{{Copyedit}}[[Category:Ignore]]' );
		$this->editPage( 'Redirect', "#REDIRECT [[Plain]]\n{{Copyedit}}" );
		$this->editPage( 'Talk:Plain', '{{Copyedit}}' );
		$this->editPage( 'Nothing', 'text' );
	}

	private function newTaskType( string $id, string $template ): TemplateBasedTaskType {
		return new TemplateBasedTaskType( $id, TaskType::DIFFICULTY_EASY, [],
			[ new TitleValue( NS_TEMPLATE, $template ) ],
			[ new TitleValue( NS_TEMPLATE, 'Ignore' ) ],
			[ new TitleValue( NS_CATEGORY, 'Ignore' ) ] );
	}

	/**
	 * @param CategoryTopic[] $topics
	 * @return DatabaseTaskSuggester
	 */
	private function newSuggester( array $topics = [] ): DatabaseTaskSuggester {
		$userOptionsLookup = $this->createMock( NewcomerTasksUserOptionsLookup::class );
		$userOptionsLookup->method( 'filterTaskTypes' )->willReturnArgument( 0 );
		return new DatabaseTaskSuggester(
			$userOptionsLookup,
			$this->getServiceContainer()->getConnectionProvider(),
			$this->getServiceContainer()->getLinkTargetLookup(),
			[ $this->newTaskType( 'copyedit', 'Copyedit' ), $this->newTaskType( 'expand', 'Stub' ) ],
			$topics
		);
	}

	private function getTitles( TaskSet $taskSet ): array {
		$titles = array_map( static function ( Task $task ) {
			return $task->getTaskType()->getId() . ':' . $task->getTitle()->getText();
		}, iterator_to_array( $taskSet ) );
		sort( $titles );
		return $titles;
	}

	public function testSuggest() {
		$user = new UserIdentityValue( 1, 'User' );
		$taskSet = $this->newSuggester()->suggest( $user, new TaskSetFilters() );
		$this->assertInstanceOf( TaskSet::class, $taskSet );
		// Each page appears once, under whichever task type reached it first
		$this->assertCount( 3, $taskSet );
		$this->assertSame( 4, $taskSet->getTotalCount() );
		$titles = array_map( static function ( Task $task ) {
			return $task->getTitle()->getText();
		}, iterator_to_array( $taskSet ) );
		sort( $titles );
		$this->assertSame( [ 'Both', 'Plain', 'Stub only' ], $titles );

		$taskSet = $this->newSuggester()->suggest( $user, new TaskSetFilters( [ 'copyedit' ] ) );
		$this->assertSame( [ 'copyedit:Both', 'copyedit:Plain' ], $this->getTitles( $taskSet ) );
		$this->assertSame( 2, $taskSet->getTotalCount() );
	}

	public function testSuggestLimitAndExclude() {
		$user = new UserIdentityValue( 1, 'User' );
		$suggester = $this->newSuggester();
		$this->assertCount( 1, $suggester->suggest( $user, new TaskSetFilters( [ 'copyedit' ] ), 1 ) );

		$plainId = $this->getServiceContainer()->getPageStore()
			->getPageByText( 'Plain' )->getId();
		$taskSet = $suggester->suggest( $user, new TaskSetFilters( [ 'copyedit' ] ), null, null,
			[ 'excludePageIds' => [ $plainId ] ] );
		$this->assertSame( [ 'copyedit:Both' ], $this->getTitles( $taskSet ) );
	}

	public function testSuggestTopics() {
		$user = new UserIdentityValue( 1, 'User' );
		$suggester = $this->newSuggester( [
			new CategoryTopic( 'feminism', 'society', 'Feminism', 'Society', [ 'Feminism' ] ),
			new CategoryTopic( 'games', 'culture', 'Games', 'Culture', [ 'Games' ] ),
			new CategoryTopic( 'empty', 'culture', 'Empty', 'Culture', [ 'Nothing' ] ),
		] );

		$taskSet = $suggester->suggest( $user, new TaskSetFilters( [ 'copyedit' ], [ 'feminism' ] ) );
		$this->assertSame( [ 'copyedit:Plain' ], $this->getTitles( $taskSet ) );
		$this->assertSame( 1, $taskSet->getTotalCount() );

		$taskSet = $suggester->suggest( $user, new TaskSetFilters( [ 'copyedit' ], [ 'feminism', 'games' ] ) );
		$this->assertSame( [ 'copyedit:Both', 'copyedit:Plain' ], $this->getTitles( $taskSet ) );

		$taskSet = $suggester->suggest( $user, new TaskSetFilters( [ 'copyedit' ], [ 'empty' ] ) );
		$this->assertCount( 0, $taskSet );

		// A topic the wiki has since removed filters nothing
		$taskSet = $suggester->suggest( $user, new TaskSetFilters( [ 'copyedit' ], [ 'removed' ] ) );
		$this->assertSame( [ 'copyedit:Both', 'copyedit:Plain' ], $this->getTitles( $taskSet ) );
	}

	public function testSuggestInvalidTaskType() {
		$taskSet = $this->newSuggester()->suggest( new UserIdentityValue( 1, 'User' ),
			new TaskSetFilters( [ 'link-recommendation' ] ) );
		$this->assertInstanceOf( StatusValue::class, $taskSet );
		$this->assertFalse( $taskSet->isOK() );
	}

	public function testFilter() {
		$user = new UserIdentityValue( 1, 'User' );
		$suggester = $this->newSuggester();
		$copyedit = $this->newTaskType( 'copyedit', 'Copyedit' );
		$taskSet = new TaskSet( [
			new Task( $copyedit, new TitleValue( NS_MAIN, 'Plain' ) ),
			new Task( $copyedit, new TitleValue( NS_MAIN, 'Stub_only' ) ),
			new Task( $copyedit, new TitleValue( NS_MAIN, 'Missing' ) ),
		], 10, 0, new TaskSetFilters( [ 'copyedit' ] ) );

		$filtered = $suggester->filter( $user, $taskSet );
		$this->assertSame( [ 'copyedit:Plain' ], $this->getTitles( $filtered ) );
		$this->assertSame( 8, $filtered->getTotalCount() );
	}

	/**
	 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\DatabaseTaskSuggesterFactory
	 */
	public function testFactoryError() {
		$configurationLoader = $this->createMock( ConfigurationLoader::class );
		$configurationLoader->method( 'loadTaskTypes' )
			->willReturn( StatusValue::newFatal( 'unifiedextensionforfemiwiki-desc' ) );
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'error' );
		$factory = new DatabaseTaskSuggesterFactory(
			$configurationLoader,
			$this->createNoOpMock( NewcomerTasksUserOptionsLookup::class ),
			$this->getServiceContainer()->getConnectionProvider(),
			$this->getServiceContainer()->getLinkTargetLookup(),
			$this->createNoOpMock( ITopicRegistry::class ),
			$logger
		);
		$this->assertInstanceOf( ErrorForwardingTaskSuggester::class, $factory->create() );
	}

	/**
	 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\Services
	 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\DatabaseTaskSuggesterFactory
	 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\FeatureManager
	 */
	public function testServices() {
		$this->overrideConfigValues( [
			'UnifiedExtensionForFemiwikiSuggestedEdits' => true,
			'GEHomepageSuggestedEditsEnabled' => true,
		] );
		$growthServices = GrowthExperimentsServices::wrap( $this->getServiceContainer() );
		$factory = $growthServices->getTaskSuggesterFactory();
		$this->assertInstanceOf( DatabaseTaskSuggesterFactory::class, $factory );
		$this->assertInstanceOf( DatabaseTaskSuggester::class, $factory->create() );
		$featureManager = $growthServices->getFeatureManager();
		$this->assertInstanceOf( FeatureManager::class, $featureManager );
		$this->assertTrue( $featureManager->isNewcomerTasksAvailable() );
		$this->assertInstanceOf( CategoryTopicRegistry::class, $growthServices->getTopicRegistry() );
	}

	/**
	 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\CategoryTopicRegistry
	 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\CategoryTopic
	 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments\TopicsSchema
	 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\GrowthTopics
	 */
	public function testTopicsFromWiki() {
		$this->overrideConfigValue( 'UnifiedExtensionForFemiwikiSuggestedEdits', true );
		$this->editPage( 'MediaWiki:FemiwikiSuggestedEditsTopics.json', json_encode( [
			'Groups' => [ [ 'id' => 'society', 'label' => 'Society' ] ],
			'Topics' => [
				[
					'id' => 'feminism',
					'label' => 'Feminism',
					'group' => 'society',
					'categories' => [ 'Category:Feminism', 'Women' ],
				],
			],
			'DefaultTopics' => [ 'feminism', 'removed' ],
		] ) );
		// Saving the page already loaded the registry, still empty, for the default options
		$this->resetServices();

		$growthServices = GrowthExperimentsServices::wrap( $this->getServiceContainer() );
		$topics = $growthServices->getTopicRegistry()->getTopics();
		$this->assertCount( 1, $topics );
		$this->assertInstanceOf( CategoryTopic::class, $topics[0] );
		$this->assertSame( [
			'id' => 'feminism',
			'name' => 'Feminism',
			'groupId' => 'society',
			'groupName' => 'Society',
		], $topics[0]->getViewData( RequestContext::getMain() ) );
		$this->assertSame( [ 'Feminism', 'Women' ], array_map( static function ( $category ) {
			return $category->getDBkey();
		}, $topics[0]->getCategories() ) );

		$taskSet = $growthServices->getTaskSuggesterFactory()->create()->suggest(
			new UserIdentityValue( 1, 'User' ), new TaskSetFilters( [ 'copyedit' ], [ 'feminism' ] ) );
		$this->assertInstanceOf( TaskSet::class, $taskSet );

		$this->assertSame( '["feminism"]', $this->getServiceContainer()->getUserOptionsLookup()
			->getDefaultOption( SuggestedEdits::TOPICS_ORES_PREF ) );
	}

	/**
	 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers\Services
	 */
	public function testServicesDisabled() {
		$this->overrideConfigValue( 'UnifiedExtensionForFemiwikiSuggestedEdits', false );
		$growthServices = GrowthExperimentsServices::wrap( $this->getServiceContainer() );
		$this->assertNotInstanceOf( DatabaseTaskSuggesterFactory::class,
			$growthServices->getTaskSuggesterFactory() );
		$this->assertNotInstanceOf( FeatureManager::class, $growthServices->getFeatureManager() );
	}
}
