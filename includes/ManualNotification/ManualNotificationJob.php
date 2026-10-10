<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\ManualNotification;

use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\Notification\Types\WikiNotification;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Delivers a manual notification to one batch of recipients, then queues the
 * next batch. No single request or job ever reads or notifies every user.
 *
 * Params: group, header, body, agentId, agentName, afterId, batchSize, and the
 * linked page as namespace and title.
 */
class ManualNotificationJob extends Job {

	public const COMMAND = 'writeManualNotification';

	private RecipientFinder $recipientFinder;

	public function __construct(
		array $params,
		private NotificationService $notificationService,
		IConnectionProvider $dbProvider,
		private JobQueueGroup $jobQueueGroup
	) {
		parent::__construct( self::COMMAND, $params );
		$this->recipientFinder = new RecipientFinder( $dbProvider );
	}

	public static function newSpec( array $params ): JobSpecification {
		return new JobSpecification( self::COMMAND, $params );
	}

	/**
	 * A retry would notify part of a batch twice
	 * @inheritDoc
	 */
	public function allowRetries() {
		return false;
	}

	/** @inheritDoc */
	public function run() {
		$batchSize = (int)$this->params['batchSize'];
		$users = $this->recipientFinder->getBatch(
			$this->params['group'], (int)$this->params['afterId'], $batchSize
		);
		if ( !$users ) {
			return true;
		}

		// Queued before delivering, so a failing batch does not stop the rest
		if ( count( $users ) === $batchSize ) {
			$this->jobQueueGroup->push( self::newSpec(
				[ 'afterId' => end( $users )->getId() ] + $this->params
			) );
		}

		$notification = new WikiNotification(
			ManualNotificationPresentationModel::TYPE,
			$this->getTitle(),
			new UserIdentityValue( (int)$this->params['agentId'], $this->params['agentName'] ),
			[
				'header' => $this->params['header'],
				'body' => $this->params['body'],
			]
		);
		$this->notificationService->notify( $notification, new RecipientSet( $users ) );
		return true;
	}
}
