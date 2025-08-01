<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Special;

use MediaWiki\Context\ContextSource;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserGroupManager;

class SpecialWriteManualNotification extends FormSpecialPage {

	public const PAGE_NAME = 'WriteManualNotification';
	private UserGroupManager $userGroupManager;

	public function __construct(
		UserGroupManager $userGroupManager
	) {
		parent::__construct( self::PAGE_NAME, 'writemanualnotification-write' );
		$this->userGroupManager = $userGroupManager;
	}

	/** @inheritDoc */
	public function getDescription() {
		return $this->msg( 'special-write-manual-notification' );
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'other';
	}

	/** @inheritDoc */
	protected function getDisplayFormat() {
		return 'ooui';
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}

	/** @inheritDoc */
	protected function getFormFields() {
		$options = [ '' => '' ];
		foreach ( $this->userGroupManager->listAllGroups() as $g ) {
			$options[$g] = $g;
			// TODO: Use getLocalizedGroupNames
		}
		return [
			'TargetGroup' => [
				'type' => 'select',
				'name' => 'target-group',
				'options' => $options,
				'label-message' => 'writemanualnotification-label-target-group',
			],
			'Header' => [
				'type' => 'text',
				'name' => 'header',
				'label-message' => 'writemanualnotification-label-header',
			],
			'Body' => [
				'type' => 'textarea',
				'name' => 'body',
				'label-message' => 'writemanualnotification-label-body',
			],
			'PrimaryTitle' => [
				'type' => 'title',
				'name' => 'primary-title',
				'exists' => true,
				'label-message' => 'writemanualnotification-label-title',
			],
		];
	}

	/** @inheritDoc */
	public function onSubmit( array $data, ?ContextSource $form = null ) {
		$event = Event::create( [
			'type' => 'write-manual-notification',
			'agent' => $this->getUser(),
			'title' => Title::newFromText( $data['PrimaryTitle'] ),
			'extra' => [
				'usergroup' => $data['TargetGroup'],
				'header' => $data['Header'],
				'body' => $data['Body'],
			],
		] );

		return $event != false;
	}

	/** @inheritDoc */
	public function onSuccess() {
		$res = $this->getRequest();
		$output = $this->getOutput();
		$output->addWikiMsg( 'success' );
	}
}
