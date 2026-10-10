<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Special;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\ManualNotification\ManualNotificationJob;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\ManualNotification\RecipientFinder;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Logging\LogEntryBase;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\UserGroupManager;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Sends a notification to every user or to one group. A first submission only
 * previews the recipient count; the same data submitted again queues delivery.
 */
class SpecialWriteManualNotification extends FormSpecialPage {

	public const LOG_TYPE = 'manualnotification';

	/** Seconds in which the same notification to the same group is refused */
	private const DUPLICATE_WINDOW = 86400;

	private const HEADER_MAX = 200;

	private const BODY_MAX = 1000;

	private RecipientFinder $recipientFinder;

	private int $sentCount = 0;

	public function __construct(
		private UserGroupManager $userGroupManager,
		private IConnectionProvider $dbProvider,
		private JobQueueGroup $jobQueueGroup,
		private BagOStuff $stash
	) {
		parent::__construct( 'WriteManualNotification' );
		$this->recipientFinder = new RecipientFinder( $dbProvider );
	}

	/** @inheritDoc */
	public function getRestriction(): string {
		return 'writemanualnotification';
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'users';
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
	protected function getMessagePrefix() {
		return 'writemanualnotification';
	}

	/** @inheritDoc */
	protected function getFormFields() {
		$options = [ $this->msg( 'writemanualnotification-group-all' )->text() => '' ];
		foreach ( $this->userGroupManager->listAllGroups() as $group ) {
			$options[$this->getLanguage()->getGroupName( $group )] = $group;
		}
		return [
			'TargetGroup' => [
				'type' => 'select',
				'options' => $options,
				'label-message' => 'writemanualnotification-label-group',
			],
			'Header' => [
				'type' => 'text',
				'required' => true,
				'maxlength' => self::HEADER_MAX,
				'label-message' => 'writemanualnotification-label-header',
			],
			'Body' => [
				'type' => 'textarea',
				'rows' => 4,
				'maxlength' => self::BODY_MAX,
				'label-message' => 'writemanualnotification-label-body',
			],
			'Page' => [
				'type' => 'title',
				'exists' => true,
				'required' => true,
				'label-message' => 'writemanualnotification-label-page',
				'help-message' => 'writemanualnotification-help-page',
			],
		];
	}

	/** @inheritDoc */
	protected function alterForm( HTMLForm $form ) {
		$form->setSubmitTextMsg( 'writemanualnotification-preview' );
	}

	/**
	 * @param array $data
	 * @param HTMLForm|null $form
	 * @return bool|Status
	 */
	public function onSubmit( array $data, ?HTMLForm $form = null ) {
		$group = (string)$data['TargetGroup'];
		$header = trim( $data['Header'] );
		$body = trim( $data['Body'] );
		$title = Title::newFromText( $data['Page'] );
		if ( $title === null ) {
			return Status::newFatal( 'writemanualnotification-bad-page' );
		}
		if ( mb_strlen( $header ) > self::HEADER_MAX || mb_strlen( $body ) > self::BODY_MAX ) {
			return Status::newFatal(
				'writemanualnotification-too-long',
				Message::numParam( self::HEADER_MAX ),
				Message::numParam( self::BODY_MAX )
			);
		}
		$hash = self::hash( $group, $header, $body, $title );
		$count = $this->recipientFinder->count( $group );

		if ( $this->getRequest()->getVal( 'wpPreviewHash' ) !== $hash ) {
			$this->showPreview( $header, $body, $title );
			if ( $form ) {
				$form->addHiddenField( 'wpPreviewHash', $hash );
				$form->setSubmitTextMsg( 'writemanualnotification-send' );
				$form->setSubmitDestructive();
			}
			return Status::newGood()->warning(
				'writemanualnotification-confirm',
				Message::numParam( $count ),
				$this->groupLabel( $group )
			);
		}
		if ( $count === 0 ) {
			return Status::newFatal( 'writemanualnotification-no-recipients' );
		}

		// Two sysops, or one double click, sending at once: only one gets through
		$lock = $this->stash->getScopedLock(
			$this->stash->makeKey( 'unifiedextensionforfemiwiki-manualnotification' ), 0, 60
		);
		if ( !$lock ) {
			return Status::newFatal( 'writemanualnotification-busy' );
		}
		if ( $this->wasSentRecently( $hash ) ) {
			return Status::newFatal( 'writemanualnotification-duplicate' );
		}

		$this->jobQueueGroup->push( ManualNotificationJob::newSpec( [
			'group' => $group,
			'header' => $header,
			'body' => $body,
			'namespace' => $title->getNamespace(),
			'title' => $title->getDBkey(),
			'agentId' => $this->getUser()->getId(),
			'agentName' => $this->getUser()->getName(),
			'afterId' => 0,
			'batchSize' => max( 1, (int)$this->getConfig()->get(
				'UnifiedExtensionForFemiwikiManualNotificationBatchSize'
			) ),
		] ) );
		// Logged only once queued, so a failed push leaves nothing to call a duplicate
		$this->log( $group, $header, $title, $count, $hash );
		$this->sentCount = $count;
		return true;
	}

	/** @inheritDoc */
	public function onSuccess() {
		$this->getOutput()->addWikiMsg(
			'writemanualnotification-queued',
			Message::numParam( $this->sentCount )
		);
	}

	public static function hash( string $group, string $header, string $body, Title $title ): string {
		return sha1( json_encode( [ $group, $header, $body, $title->getPrefixedDBkey() ] ) );
	}

	private function groupLabel( string $group ): string {
		return $group === ''
			? $this->msg( 'writemanualnotification-group-all' )->text()
			: $this->getLanguage()->getGroupName( $group );
	}

	private function showPreview( string $header, string $body, Title $title ): void {
		$this->getOutput()->addHTML( Html::rawElement(
			'div',
			[ 'class' => 'mw-writemanualnotification-preview' ],
			Html::element( 'strong', [], $header )
			. ( $body === '' ? '' : Html::element( 'p', [], $body ) )
			. Html::element( 'p', [], $title->getPrefixedText() )
		) );
	}

	private function wasSentRecently( string $hash ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$res = $dbw->newSelectQueryBuilder()
			->select( 'log_params' )
			->from( 'logging' )
			->where( [ 'log_type' => self::LOG_TYPE ] )
			->andWhere( $dbw->expr(
				'log_timestamp', '>', $dbw->timestamp( time() - self::DUPLICATE_WINDOW )
			) )
			->caller( __METHOD__ )
			->fetchFieldValues();
		foreach ( $res as $blob ) {
			$params = LogEntryBase::extractParams( $blob );
			if ( ( $params['hash'] ?? null ) === $hash ) {
				return true;
			}
		}
		return false;
	}

	private function log( string $group, string $header, Title $title, int $count, string $hash ): void {
		$entry = new ManualLogEntry( self::LOG_TYPE, $group === '' ? 'all' : 'group' );
		$entry->setPerformer( $this->getUser() );
		$entry->setTarget( $title );
		$entry->setParameters( [
			'4::header' => $header,
			'5:number:count' => $count,
			'hash' => $hash,
		] + ( $group === '' ? [] : [ '6:msg:group' => "group-$group" ] ) );
		$entry->publish( $entry->insert() );
	}
}
