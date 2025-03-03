<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\EchoEventPresentationModel;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\UserLocator;

class WriteManualNotification implements
	\MediaWiki\Extension\Notifications\Hooks\BeforeCreateEchoEventHook
	{

	/** @inheritDoc */
	public function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$notificationIcons
	) {
		$notificationCategories['write-manual-notification'] = [
			'priority' => 9,
			'tooltip' => 'echo-pref-tooltip-write-manual-notification',
		];
		$notifications['write-manual-notification'] = [
			'category' => 'write-manual-notification',
			'section' => 'message',
			'presentation-model' => EchoEventPresentationModel::class,
			'canNotifyAgent' => true,
			'bundle' => [
				'web' => false,
				'email' => false,
				'expandable' => false,
			],
			'user-locators' => [
				[ [ UserLocator::class, 'locateUsersInGroup' ] ],
			],
		];
	}
}
