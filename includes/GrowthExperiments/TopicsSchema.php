<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\GrowthExperiments;

use MediaWiki\Extension\CommunityConfiguration\Schema\JsonSchema;
use MediaWiki\Extension\CommunityConfiguration\Schemas\MediaWiki\MediaWikiDefinitions;

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase

/**
 * Suggested edit topics. Empty by default; the wiki fills them in.
 */
class TopicsSchema extends JsonSchema {
	public const VERSION = '1.0.0';

	/** Topic IDs end up in API parameters and user options */
	private const ID = [
		self::TYPE => self::TYPE_STRING,
		self::PATTERN => '^[a-z0-9-]+$',
	];

	public const Groups = [
		self::TYPE => self::TYPE_ARRAY,
		self::ITEMS => [
			self::TYPE => self::TYPE_OBJECT,
			self::PROPERTIES => [
				'id' => self::ID,
				'label' => [ self::TYPE => self::TYPE_STRING ],
			],
			self::REQUIRED => [ 'id', 'label' ],
			self::ADDITIONAL_PROPERTIES => false,
		],
		self::DEFAULT => [],
	];

	public const Topics = [
		self::TYPE => self::TYPE_ARRAY,
		self::ITEMS => [
			self::TYPE => self::TYPE_OBJECT,
			self::PROPERTIES => [
				'id' => self::ID,
				'label' => [ self::TYPE => self::TYPE_STRING ],
				'group' => self::ID,
				'categories' => [
					self::REF => [ 'class' => MediaWikiDefinitions::class, 'field' => 'PageTitles' ],
				],
			],
			self::REQUIRED => [ 'id', 'label', 'group', 'categories' ],
			self::ADDITIONAL_PROPERTIES => false,
		],
		self::DEFAULT => [],
	];

	public const DefaultTopics = [
		self::TYPE => self::TYPE_ARRAY,
		self::ITEMS => self::ID,
		self::DEFAULT => [],
	];
}
