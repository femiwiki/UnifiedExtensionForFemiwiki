<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments;

use GrowthExperiments\NewcomerTasks\NewcomerTasksUserOptionsLookup;
use GrowthExperiments\NewcomerTasks\Task\Task;
use GrowthExperiments\NewcomerTasks\Task\TaskSet;
use GrowthExperiments\NewcomerTasks\Task\TaskSetFilters;
use GrowthExperiments\NewcomerTasks\TaskSuggester\TaskSuggester;
use GrowthExperiments\NewcomerTasks\TaskType\TemplateBasedTaskType;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Linker\LinkTargetLookup;
use MediaWiki\Message\Message;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\UserIdentity;
use StatusValue;
use Wikimedia\Message\ListType;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Finds template-based tasks with database queries instead of CirrusSearch.
 * A topic matches articles directly in one of its categories; FacetedCategory already puts
 * articles in the parent categories of `/`-named ones.
 */
class DatabaseTaskSuggester implements TaskSuggester {

	/** Same as SearchTaskSuggester::DEFAULT_LIMIT, which the front end relies on */
	private const DEFAULT_LIMIT = 15;

	/** @var TemplateBasedTaskType[] Keyed by task type ID */
	private array $taskTypes = [];

	/** @var CategoryTopic[] Keyed by topic ID */
	private array $topics = [];

	/**
	 * @param NewcomerTasksUserOptionsLookup $newcomerTasksUserOptionsLookup
	 * @param IConnectionProvider $connectionProvider
	 * @param LinkTargetLookup $linkTargetLookup
	 * @param \GrowthExperiments\NewcomerTasks\TaskType\TaskType[] $taskTypes
	 * @param \GrowthExperiments\NewcomerTasks\Topic\Topic[] $topics
	 */
	public function __construct(
		private NewcomerTasksUserOptionsLookup $newcomerTasksUserOptionsLookup,
		private IConnectionProvider $connectionProvider,
		private LinkTargetLookup $linkTargetLookup,
		array $taskTypes,
		array $topics = []
	) {
		foreach ( $taskTypes as $taskType ) {
			// The other task types need services only Wikimedia runs
			if ( $taskType instanceof TemplateBasedTaskType ) {
				$this->taskTypes[$taskType->getId()] = $taskType;
			}
		}
		foreach ( $topics as $topic ) {
			if ( $topic instanceof CategoryTopic ) {
				$this->topics[$topic->getId()] = $topic;
			}
		}
	}

	/** @inheritDoc */
	public function suggest(
		UserIdentity $user,
		TaskSetFilters $taskSetFilters,
		?int $limit = null,
		?int $offset = null,
		array $options = []
	) {
		if ( !$taskSetFilters->getTaskTypeFilters() ) {
			$taskSetFilters->setTaskTypeFilters(
				$this->newcomerTasksUserOptionsLookup->filterTaskTypes( array_keys( $this->taskTypes ), $user )
			);
		}
		$taskTypes = [];
		$invalidTaskTypes = [];
		foreach ( $taskSetFilters->getTaskTypeFilters() as $taskTypeId ) {
			if ( isset( $this->taskTypes[$taskTypeId] ) ) {
				$taskTypes[] = $this->taskTypes[$taskTypeId];
			} else {
				$invalidTaskTypes[] = $taskTypeId;
			}
		}
		if ( !$taskTypes ) {
			return StatusValue::newFatal( wfMessage( 'growthexperiments-newcomertasks-invalid-tasktype',
				Message::listParam( $invalidTaskTypes, ListType::COMMA ) ) );
		}

		$limit ??= self::DEFAULT_LIMIT;
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$excludePageIds = $options['excludePageIds'] ?? [];
		$topicCategoryIds = $this->getTopicCategoryIds( $taskSetFilters->getTopicFilters() );
		if ( $topicCategoryIds === [] ) {
			// None of the chosen topics' categories has any page
			return new TaskSet( [], 0, 0, $taskSetFilters );
		}
		$totalCount = 0;
		$rowsByTaskType = [];
		foreach ( $taskTypes as $taskType ) {
			$queryBuilder = $this->newQueryBuilder( $dbr, $taskType, $topicCategoryIds );
			if ( !$queryBuilder ) {
				continue;
			}
			if ( $excludePageIds ) {
				$queryBuilder->andWhere( $dbr->expr( 'page_id', '!=', $excludePageIds ) );
			}
			$totalCount += (int)( clone $queryBuilder )
				->clearFields()
				->select( 'COUNT(DISTINCT page_id)' )
				->fetchField();
			$rowsByTaskType[$taskType->getId()] = $this->fetchRandomRows( $dbr, $queryBuilder, $limit );
		}

		// Take one task from each task type in turn, as GrowthExperiments does
		$tasks = [];
		for ( $i = 0; $rowsByTaskType && count( $tasks ) < $limit; $i++ ) {
			foreach ( $rowsByTaskType as $taskTypeId => $rows ) {
				if ( !isset( $rows[$i] ) ) {
					unset( $rowsByTaskType[$taskTypeId] );
					continue;
				}
				$key = $rows[$i]->page_namespace . ':' . $rows[$i]->page_title;
				$tasks[$key] ??= new Task( $this->taskTypes[$taskTypeId],
					new TitleValue( (int)$rows[$i]->page_namespace, $rows[$i]->page_title ) );
				if ( count( $tasks ) >= $limit ) {
					break;
				}
			}
		}

		// Offsets mean little for random results; GrowthExperiments ignores them too
		return new TaskSet( array_values( $tasks ), $totalCount, 0, $taskSetFilters );
	}

	/** @inheritDoc */
	public function filter( UserIdentity $user, TaskSet $taskSet ) {
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$titlesByTaskType = [];
		foreach ( $taskSet as $task ) {
			$title = $task->getTitle();
			$titlesByTaskType[$task->getTaskType()->getId()][$title->getNamespace()][$title->getDBkey()] = true;
		}
		$valid = [];
		foreach ( $titlesByTaskType as $taskTypeId => $titles ) {
			$taskType = $this->taskTypes[$taskTypeId] ?? null;
			$queryBuilder = $taskType ? $this->newQueryBuilder( $dbr, $taskType ) : null;
			if ( !$queryBuilder ) {
				continue;
			}
			$rows = $queryBuilder
				->andWhere( $dbr->makeWhereFrom2d( $titles, 'page_namespace', 'page_title' ) )
				->fetchResultSet();
			foreach ( $rows as $row ) {
				$valid[$taskTypeId][$row->page_namespace . ':' . $row->page_title] = true;
			}
		}

		$tasks = array_filter( iterator_to_array( $taskSet ), static function ( Task $task ) use ( $valid ) {
			$title = $task->getTitle();
			return isset( $valid[$task->getTaskType()->getId()][$title->getNamespace() . ':' . $title->getDBkey()] );
		} );
		$removed = $taskSet->count() - count( $tasks );
		$filteredTaskSet = new TaskSet( $tasks, $taskSet->getTotalCount() - $removed, $taskSet->getOffset(),
			$taskSet->getFilters(), $taskSet->getInvalidTasks() );
		$filteredTaskSet->setDebugData( $taskSet->getDebugData() );
		return $filteredTaskSet;
	}

	/**
	 * @param string[] $topicIds
	 * @return int[]|null Link target IDs of the topics' categories, or null to match any page.
	 *   Topics that no longer exist are ignored, so stale preferences don't hide every task.
	 */
	private function getTopicCategoryIds( array $topicIds ): ?array {
		$categories = [];
		foreach ( $topicIds as $topicId ) {
			if ( isset( $this->topics[$topicId] ) ) {
				$categories = array_merge( $categories, $this->topics[$topicId]->getCategories() );
			}
		}
		return $categories ? $this->getLinkTargetIds( $categories ) : null;
	}

	/**
	 * Articles that use one of the task type's templates and none of its excluded templates
	 * or categories.
	 *
	 * @param IReadableDatabase $dbr
	 * @param TemplateBasedTaskType $taskType
	 * @param int[]|null $topicCategoryIds Pages in any of these categories, or any page if null
	 * @return SelectQueryBuilder|null Null if none of the templates is used anywhere
	 */
	private function newQueryBuilder(
		IReadableDatabase $dbr,
		TemplateBasedTaskType $taskType,
		?array $topicCategoryIds = null
	): ?SelectQueryBuilder {
		$templateIds = $this->getLinkTargetIds( $taskType->getTemplates() );
		if ( !$templateIds ) {
			return null;
		}
		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( [ 'page_id', 'page_namespace', 'page_title', 'page_random' ] )
			->distinct()
			->from( 'page' )
			->join( 'templatelinks', 'tl', 'tl.tl_from = page_id' )
			->where( [
				'page_namespace' => NS_MAIN,
				'page_is_redirect' => 0,
				'tl.tl_target_id' => $templateIds,
			] )
			->caller( __METHOD__ );
		if ( $topicCategoryIds !== null ) {
			$queryBuilder
				->join( 'categorylinks', 'topic_cl', 'topic_cl.cl_from = page_id' )
				->andWhere( [ 'topic_cl.cl_target_id' => $topicCategoryIds ] );
		}

		$excludedTemplateIds = $this->getLinkTargetIds( $taskType->getExcludedTemplates() );
		if ( $excludedTemplateIds ) {
			$queryBuilder
				->leftJoin( 'templatelinks', 'excluded_tl', [
					'excluded_tl.tl_from = page_id',
					'excluded_tl.tl_target_id' => $excludedTemplateIds,
				] )
				->andWhere( [ 'excluded_tl.tl_from' => null ] );
		}
		$excludedCategoryIds = $this->getLinkTargetIds( $taskType->getExcludedCategories() );
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
	 * @return \stdClass[]
	 */
	private function fetchRandomRows(
		IReadableDatabase $dbr,
		SelectQueryBuilder $queryBuilder,
		int $limit
	): array {
		$random = wfRandom();
		$rows = iterator_to_array( ( clone $queryBuilder )
			->andWhere( $dbr->expr( 'page_random', '>=', $random ) )
			->orderBy( 'page_random' )
			->limit( $limit )
			->fetchResultSet(), false );
		if ( count( $rows ) < $limit ) {
			$rows = array_merge( $rows, iterator_to_array( ( clone $queryBuilder )
				->andWhere( $dbr->expr( 'page_random', '<', $random ) )
				->orderBy( 'page_random' )
				->limit( $limit - count( $rows ) )
				->fetchResultSet(), false ) );
		}
		return $rows;
	}

	/**
	 * @param LinkTarget[] $linkTargets
	 * @return int[] IDs of the link targets that some page links to
	 */
	private function getLinkTargetIds( array $linkTargets ): array {
		$ids = [];
		foreach ( $linkTargets as $linkTarget ) {
			$id = $this->linkTargetLookup->getLinkTargetId( $linkTarget );
			if ( $id !== null ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
}
