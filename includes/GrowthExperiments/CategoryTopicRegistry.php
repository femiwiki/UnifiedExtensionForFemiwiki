<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments;

use GrowthExperiments\NewcomerTasks\Topic\ITopicRegistry;
use MediaWiki\Extension\CommunityConfiguration\Provider\IConfigurationProvider;
use MediaWiki\Title\MalformedTitleException;
use MediaWiki\Title\TitleParser;

/**
 * Topics from the wiki's topic configuration page.
 */
class CategoryTopicRegistry implements ITopicRegistry {

	/** @var CategoryTopic[]|null */
	private ?array $topics = null;

	/** @var string[]|null */
	private ?array $defaultTopicIds = null;

	public function __construct(
		private IConfigurationProvider $provider,
		private TitleParser $titleParser
	) {
	}

	/**
	 * @return CategoryTopic[]
	 */
	public function getTopics(): array {
		$this->load();
		return $this->topics;
	}

	/** @inheritDoc */
	public function getTopicsMap(): array {
		$topics = [];
		foreach ( $this->getTopics() as $topic ) {
			$topics[$topic->getId()] = $topic;
		}
		return $topics;
	}

	/**
	 * @return string[] Topic IDs selected for users who have not chosen any
	 */
	public function getDefaultTopicIds(): array {
		$this->load();
		return $this->defaultTopicIds;
	}

	private function load(): void {
		if ( $this->topics !== null ) {
			return;
		}
		$this->topics = [];
		$this->defaultTopicIds = [];
		$status = $this->provider->loadValidConfiguration();
		if ( !$status->isOK() ) {
			// The provider logs the reason
			return;
		}
		$config = $status->getValue();

		$groupLabels = [];
		foreach ( $config->Groups ?? [] as $group ) {
			$groupLabels[$group->id] = $group->label;
		}
		foreach ( $config->Topics ?? [] as $topic ) {
			$categories = [];
			foreach ( $topic->categories as $category ) {
				try {
					$title = $this->titleParser->parseTitle( $category, NS_CATEGORY );
				} catch ( MalformedTitleException ) {
					continue;
				}
				if ( $title->getNamespace() === NS_CATEGORY ) {
					$categories[] = $title->getDBkey();
				}
			}
			$this->topics[] = new CategoryTopic( $topic->id, $topic->group, $topic->label,
				$groupLabels[$topic->group] ?? $topic->group, $categories );
		}
		$this->defaultTopicIds = array_values( array_intersect(
			$config->DefaultTopics ?? [],
			array_keys( $this->getTopicsMap() )
		) );
	}
}
