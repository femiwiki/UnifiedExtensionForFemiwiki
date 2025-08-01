<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki;

use MediaWiki\Language\RawMessage;
use Message;

class EchoEventPresentationModel extends \MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel {
	/** @inheritDoc */
	public function canRender() {
		return true;
	}

	/** @inheritDoc */
	public function getPrimaryLink() {
		return [
			'url' => $this->event->getTitle()->getFullURL(),
			'label' => $this->msg( 'writemanualnotification-label-primary-link' )->text(),
		];
	}

	/** @inheritDoc */
	public function getIconType() {
		return 'site';
	}

	/** @inheritDoc */
	public function getHeaderMessage(): Message {
		$header = $this->event->getExtraParam( 'header' );
		return new RawMessage( is_string( $header ) ? $header : '' );
	}

	/** @inheritDoc */
	public function getBodyMessage() {
		$body = $this->event->getExtraParam( 'body' );
		return new RawMessage( is_string( $body ) ? $body : '' );
	}
}
