<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments;

use GrowthExperiments\NewcomerTasks\ConfigurationLoader\ConfigurationLoader;
use GrowthExperiments\NewcomerTasks\NewcomerTasksUserOptionsLookup;
use GrowthExperiments\NewcomerTasks\TaskSuggester\ErrorForwardingTaskSuggester;
use GrowthExperiments\NewcomerTasks\TaskSuggester\TaskSuggesterFactory;
use GrowthExperiments\NewcomerTasks\Topic\ITopicRegistry;
use MediaWiki\Linker\LinkTargetLookup;
use MediaWiki\Status\Status;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Rdbms\IConnectionProvider;

class DatabaseTaskSuggesterFactory extends TaskSuggesterFactory {

	public function __construct(
		private ConfigurationLoader $configurationLoader,
		private NewcomerTasksUserOptionsLookup $newcomerTasksUserOptionsLookup,
		private IConnectionProvider $connectionProvider,
		private LinkTargetLookup $linkTargetLookup,
		private ITopicRegistry $topicRegistry,
		LoggerInterface $logger
	) {
		// GrowthExperiments 1.46 sets the logger with setLogger(), later releases in the
		// constructor; both keep it in this property
		$this->logger = $logger;
	}

	/** @inheritDoc */
	public function create( ?ConfigurationLoader $customConfigurationLoader = null ) {
		$configurationLoader = $customConfigurationLoader ?? $this->configurationLoader;
		$taskTypes = $configurationLoader->loadTaskTypes();
		if ( $taskTypes instanceof StatusValue ) {
			// Like TaskSuggesterFactory::createError(), which later releases moved away
			$this->logger->error( Status::wrap( $taskTypes )->getWikiText( false, false, 'en' ) );
			return new ErrorForwardingTaskSuggester( $taskTypes );
		}
		return new DatabaseTaskSuggester(
			$this->newcomerTasksUserOptionsLookup,
			$this->connectionProvider,
			$this->linkTargetLookup,
			$taskTypes,
			$this->topicRegistry->getTopics()
		);
	}
}
