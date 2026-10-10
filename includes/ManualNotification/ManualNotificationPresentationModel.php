<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\ManualNotification;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;

/**
 * Shows the header and body a sysop wrote as plain text, linking to the page
 * they chose.
 */
class ManualNotificationPresentationModel extends EchoEventPresentationModel {

	/** The notification type, also used as its category */
	public const TYPE = 'write-manual-notification';

	/** @inheritDoc */
	public function canRender() {
		return $this->event->getTitle() !== null;
	}

	/** @inheritDoc */
	public function getIconType() {
		return 'site';
	}

	/** @inheritDoc */
	public function getHeaderMessage() {
		return $this->msg( 'notification-header-write-manual-notification' )
			->plaintextParams( (string)$this->event->getExtraParam( 'header', '' ) );
	}

	/** @inheritDoc */
	public function getBodyMessage() {
		$body = (string)$this->event->getExtraParam( 'body', '' );
		if ( $body === '' ) {
			return false;
		}
		return $this->msg( 'notification-body-write-manual-notification' )
			->plaintextParams( $body );
	}

	/** @inheritDoc */
	public function getPrimaryLink() {
		$title = $this->event->getTitle();
		return [
			'url' => $title->getFullURL(),
			'label' => $title->getPrefixedText(),
		];
	}
}
