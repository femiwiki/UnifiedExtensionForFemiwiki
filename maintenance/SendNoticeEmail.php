<?php
/**
 * Sends one plain text email to every confirmed address, such as a notice that
 * the privacy policy changes. It goes through UserMailer, so the mail carries the
 * usual VERP Return-Path and bounces reach BounceHandler.
 *
 * Usage:
 *   php maintenance/run.php UnifiedExtensionForFemiwiki:SendNoticeEmail \
 *     --subject 'Subject' --body body.txt [--touched-after 20250101000000] \
 *     [--limit 100] [--dry-run]
 *
 * Accounts sharing an address get one email, named after the oldest of them.
 * The script prints the last user ID it reached; pass the next one as --start-id
 * to resume an interrupted run.
 *
 * @file
 */

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Maintenance;

use MediaWiki\Mail\MailAddress;
use MediaWiki\Mail\UserMailer;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat as TS;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class SendNoticeEmail extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Send one plain text email to every confirmed address' );
		$this->addOption( 'subject', 'Subject of the email', true, true );
		$this->addOption( 'body', 'UTF-8 text file holding the body; $1 becomes the user name', true, true );
		$this->addOption( 'touched-after', 'Only addresses with an account touched since this time', false, true );
		$this->addOption( 'touched-before', 'Only addresses with no account touched since this time', false, true );
		$this->addOption( 'start-id', 'Skip addresses whose oldest account has a lower user ID', false, true );
		$this->addOption( 'limit', 'Send at most this many emails', false, true );
		$this->addOption( 'rate', 'Emails per second, below the SES sending rate (default: 1)', false, true );
		$this->addOption( 'dry-run', 'Count the recipients without sending' );
		$this->addOption( 'skip-blocked', 'Skip addresses whose oldest account is blocked sitewide' );
		$this->requireExtension( 'UnifiedExtensionForFemiwiki' );
	}

	public function execute() {
		$body = file_get_contents( $this->getOption( 'body' ) );
		if ( $body === false || trim( $body ) === '' ) {
			$this->fatalError( 'The body file is missing or empty' );
		}
		$rate = (float)$this->getOption( 'rate', 1 );
		if ( $rate <= 0 ) {
			$this->fatalError( '--rate must be positive' );
		}
		$limit = $this->hasOption( 'limit' ) ? (int)$this->getOption( 'limit' ) : null;

		$recipients = $this->getRecipients();
		$this->output( count( $recipients ) . " addresses match\n" );
		if ( $limit !== null ) {
			$recipients = array_slice( $recipients, 0, $limit );
		}
		if ( $this->hasOption( 'dry-run' ) ) {
			if ( $recipients ) {
				$this->output( count( $recipients ) . ' would be sent, user IDs '
					. $recipients[0]->id . ' to ' . end( $recipients )->id . "\n" );
			}
			return;
		}

		$services = $this->getServiceContainer();
		$from = new MailAddress(
			$this->getConfig()->get( MainConfigNames::PasswordSender ),
			$this->getConfig()->get( MainConfigNames::Sitename )
		);
		$blockManager = $services->getBlockManager();
		$userFactory = $services->getUserFactory();
		$subject = $this->getOption( 'subject' );
		$sent = 0;
		$failed = 0;
		$skipped = 0;
		$lastId = null;
		foreach ( $recipients as $row ) {
			$lastId = (int)$row->id;
			$user = $userFactory->newFromId( $lastId );
			if ( $this->hasOption( 'skip-blocked' ) ) {
				$block = $blockManager->getBlock( $user, null );
				if ( $block && $block->isSitewide() ) {
					$skipped++;
					continue;
				}
			}
			$status = UserMailer::send(
				new MailAddress( $row->user_email, $user->getName(), $user->getRealName() ),
				$from,
				$subject,
				str_replace( '$1', $user->getName(), $body )
			);
			if ( $status->isOK() ) {
				$sent++;
			} else {
				$failed++;
				$this->error( "User ID $lastId failed: " . $status->getWikiText( false, false, 'en' ) );
			}
			usleep( (int)( 1e6 / $rate ) );
		}
		$this->output( "Sent $sent, failed $failed, skipped $skipped"
			. ( $lastId === null ? '' : ", last user ID $lastId" ) . "\n" );
	}

	/**
	 * One row per confirmed address: the address, the ID of its oldest account and
	 * when any of its accounts was last touched, ordered by that ID.
	 *
	 * @return \stdClass[]
	 */
	private function getRecipients(): array {
		$dbr = $this->getReplicaDB();
		$query = $dbr->newSelectQueryBuilder()
			->select( [ 'user_email', 'id' => 'MIN(user_id)', 'touched' => 'MAX(user_touched)' ] )
			->from( 'user' )
			->where( [
				$dbr->expr( 'user_email', '!=', '' ),
				$dbr->expr( 'user_email_authenticated', '!=', null ),
			] )
			->groupBy( 'user_email' )
			->orderBy( 'id' )
			->caller( __METHOD__ );
		if ( $this->hasOption( 'start-id' ) ) {
			$query->having( 'MIN(user_id) >= ' . (int)$this->getOption( 'start-id' ) );
		}
		foreach ( [ 'touched-after' => '>=', 'touched-before' => '<' ] as $option => $op ) {
			if ( $this->hasOption( $option ) ) {
				$time = ConvertibleTimestamp::convert( TS::MW, $this->getOption( $option ) );
				if ( $time === false ) {
					$this->fatalError( "--$option is not a timestamp" );
				}
				$query->having( 'MAX(user_touched) ' . $op . ' ' . $dbr->addQuotes( $dbr->timestamp( $time ) ) );
			}
		}
		return iterator_to_array( $query->fetchResultSet(), false );
	}
}

// @codeCoverageIgnoreStart
$maintClass = SendNoticeEmail::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
