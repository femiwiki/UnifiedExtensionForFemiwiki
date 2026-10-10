<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use MediaWiki\Extension\Notifications\Hooks\BeforeCreateEchoEventHook;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\ManualNotification\ManualNotificationPresentationModel;

class ManualNotification implements BeforeCreateEchoEventHook {

	/** @inheritDoc */
	public function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$notificationIcons
	) {
		$type = ManualNotificationPresentationModel::TYPE;
		$notificationCategories[$type] = [
			'priority' => 9,
			'tooltip' => 'echo-pref-tooltip-write-manual-notification',
		];
		// Recipients come from the event (see ManualNotificationJob), so no user-locators
		$notifications[$type] = [
			'category' => $type,
			'group' => 'neutral',
			'section' => 'message',
			'presentation-model' => ManualNotificationPresentationModel::class,
			'canNotifyAgent' => true,
			'bundle' => [
				'web' => false,
				'email' => false,
			],
		];
	}
}
