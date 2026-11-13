<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\LoggedOutEditing;

use MediaWiki\Extension\CommunityConfiguration\Schema\JsonSchema;

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase

/**
 * Limits on editing without an account, set on the wiki.
 */
class LoggedOutEditingSchema extends JsonSchema {
	public const VERSION = '1.0.0';

	/** Edits a temporary account, or an IP address through its temporary accounts, may save in 24 hours */
	public const DailyEditLimit = [
		self::TYPE => self::TYPE_INTEGER,
		self::MINIMUM => 0,
		self::DEFAULT => 1,
	];
}
