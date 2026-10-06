<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments;

use GrowthExperiments\NewcomerTasks\Topic\Topic;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Language\RawMessage;
use MediaWiki\Message\Message;
use MediaWiki\Title\TitleValue;

/**
 * A topic made of categories, named in the wiki's topic configuration instead of in messages.
 */
class CategoryTopic extends Topic {

	/**
	 * @param string $id
	 * @param string $groupId
	 * @param string $label
	 * @param string $groupLabel
	 * @param string[] $categories Category names in database key form, without the namespace
	 */
	public function __construct(
		string $id,
		string $groupId,
		private string $label,
		private string $groupLabel,
		private array $categories
	) {
		parent::__construct( $id, $groupId );
	}

	/** @inheritDoc */
	public function getName( MessageLocalizer $messageLocalizer ): Message {
		return new RawMessage( '$1', [ Message::plaintextParam( $this->label ) ] );
	}

	/** @inheritDoc */
	public function getGroupName( MessageLocalizer $messageLocalizer ): Message {
		return new RawMessage( '$1', [ Message::plaintextParam( $this->groupLabel ) ] );
	}

	/**
	 * @return TitleValue[]
	 */
	public function getCategories(): array {
		return array_map( static function ( string $category ) {
			return new TitleValue( NS_CATEGORY, $category );
		}, $this->categories );
	}

	/** @inheritDoc */
	public function toJsonArray(): array {
		return parent::toJsonArray() + [
			'label' => $this->label,
			'groupLabel' => $this->groupLabel,
			'categories' => $this->categories,
		];
	}

	/** @inheritDoc */
	public static function newFromJsonArray( array $json ): self {
		return new self( $json['id'], $json['groupId'], $json['label'], $json['groupLabel'],
			$json['categories'] );
	}
}
