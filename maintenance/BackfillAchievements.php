<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Maintenance;

use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\SpecialPage\SpecialPage;
use Wikimedia\Timestamp\ConvertibleTimestamp;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Records instant AchievementBadges achievements that users earned before the achievement
 * existed, at the time they earned them. Sends no notifications.
 *
 * The input is a JSON file such as
 * {"femiwiki-ranking": {"Alice": "2016-08-29T21:00:02Z"}, "femiwiki-ranking-first": {}}
 */
class BackfillAchievements extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Record achievements users earned in the past, at the time they earned them' );
		$this->addArg( 'file', 'JSON file mapping achievement keys to {user name: timestamp}' );
		$this->addOption( 'dry-run', 'Only print what would change' );
		$this->requireExtension( 'UnifiedExtensionForFemiwiki' );
		$this->requireExtension( 'AchievementBadges' );
	}

	public function execute() {
		$data = json_decode( (string)file_get_contents( $this->getArg( 0 ) ), true );
		if ( !is_array( $data ) ) {
			$this->fatalError( 'The file is not a JSON object.' );
		}
		$dryRun = $this->hasOption( 'dry-run' );
		$services = $this->getServiceContainer();
		$registry = $services->getMainConfig()->get( 'AchievementBadgesAchievements' );
		$userFactory = $services->getUserFactory();
		$dbw = $this->getPrimaryDB();

		foreach ( $data as $key => $earned ) {
			if ( !isset( $registry[$key] ) || ( $registry[$key]['type'] ?? 'instant' ) !== 'instant' ) {
				$this->fatalError( "$key is not an instant achievement of this wiki." );
			}
			$counts = [ 'added' => 0, 'moved' => 0, 'kept' => 0, 'skipped' => 0 ];
			foreach ( $earned as $name => $time ) {
				$timestamp = ConvertibleTimestamp::convert( TS_MW, $time );
				$user = $userFactory->newFromName( (string)$name );
				if ( $timestamp === false || !$user || !$user->isRegistered() ) {
					$this->output( "Skipped $name ($time)\n" );
					$counts['skipped']++;
					continue;
				}
				$row = $user->getActorId() ? $dbw->newSelectQueryBuilder()
					->select( [ 'log_id', 'log_timestamp' ] )
					->from( 'logging' )
					->where( [
						'log_type' => 'achievementbadges',
						'log_action' => $key,
						'log_actor' => $user->getActorId(),
					] )
					->orderBy( 'log_timestamp' )
					->caller( __METHOD__ )
					->fetchRow() : false;

				if ( $row && $row->log_timestamp <= $dbw->timestamp( $timestamp ) ) {
					$counts['kept']++;
				} elseif ( $row ) {
					// Earned again since the achievement existed; move it back to the first time
					if ( !$dryRun ) {
						$dbw->newUpdateQueryBuilder()
							->update( 'logging' )
							->set( [ 'log_timestamp' => $dbw->timestamp( $timestamp ) ] )
							->where( [ 'log_id' => $row->log_id ] )
							->caller( __METHOD__ )
							->execute();
					}
					$counts['moved']++;
				} else {
					if ( !$dryRun ) {
						$entry = new ManualLogEntry( 'achievementbadges', $key );
						$entry->setPerformer( $user );
						$entry->setTarget( SpecialPage::getTitleFor( 'Achievements' ) );
						$entry->setParameters( [ '4::key' => $key ] );
						$entry->setTimestamp( $timestamp );
						$entry->insert( $dbw );
					}
					$counts['added']++;
				}
			}
			$this->output( "$key: " . json_encode( $counts ) . ( $dryRun ? ' (dry run)' : '' ) . "\n" );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = BackfillAchievements::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
