<?php
/**
 * Scheduler
 *
 * @package ODriveConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages WP-Cron events for ODrive scheduled operations.
 */
class ODrive_Scheduler {

	/** @var ODrive_Backup */
	private ODrive_Backup $backup;

	/** @var ODrive_Health */
	private ODrive_Health $health;

	/** @var ODrive_Activity_Log */
	private ODrive_Activity_Log $log;

	/**
	 * @param ODrive_Backup       $backup Backup instance.
	 * @param ODrive_Health       $health Health instance.
	 * @param ODrive_Activity_Log $log    Activity log.
	 */
	public function __construct( ODrive_Backup $backup, ODrive_Health $health, ODrive_Activity_Log $log ) {
		$this->backup = $backup;
		$this->health = $health;
		$this->log    = $log;
	}

	/**
	 * Register all cron action hooks and schedule recurring events.
	 *
	 * @return void
	 */
	public function register(): void {
		// Cron callbacks.
		add_action( 'odrive_health_check',    array( $this, 'run_health_check' ) );
		add_action( 'odrive_cleanup_logs',    array( $this, 'run_log_cleanup' ) );
		add_action( 'odrive_scheduled_backup', array( $this, 'run_scheduled_backup' ), 10, 2 );
		add_action( 'odrive_run_backup',      array( $this, 'run_backup_job' ),       10, 3 );

		// Schedule recurring events if not already scheduled.
		if ( ! wp_next_scheduled( 'odrive_health_check' ) ) {
			wp_schedule_event( time(), 'hourly', 'odrive_health_check' );
		}

		if ( ! wp_next_scheduled( 'odrive_cleanup_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'odrive_cleanup_logs' );
		}
	}

	/**
	 * Unschedule all plugin cron events.
	 *
	 * Called on plugin deactivation.
	 *
	 * @return void
	 */
	public function unregister(): void {
		$hooks = array(
			'odrive_health_check',
			'odrive_cleanup_logs',
			'odrive_scheduled_backup',
		);

		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	// -------------------------------------------------------------------------
	// Cron callbacks
	// -------------------------------------------------------------------------

	/**
	 * Run the ODrive health check.
	 *
	 * @return void
	 */
	public function run_health_check(): void {
		$this->health->run_check();
	}

	/**
	 * Perform periodic activity log cleanup.
	 *
	 * @return void
	 */
	public function run_log_cleanup(): void {
		$days    = (int) apply_filters( 'odrive_log_retention_days', 90 );
		$deleted = $this->log->clear_old_logs( $days );

		if ( $deleted > 0 ) {
			$this->log->log( 'log_cleanup', sprintf(
				/* translators: %d: number of deleted entries */
				__( 'Cleaned up %d old activity log entries.', 'odrive-connector' ),
				$deleted
			) );
		}
	}

	/**
	 * Run a scheduled backup initiated by ODrive.
	 *
	 * @param string $type  Backup type.
	 * @param array  $extra Extra parameters from ODrive.
	 * @return void
	 */
	public function run_scheduled_backup( string $type, array $extra = [] ): void {
		$local_job_id = uniqid( 'odrvsch_', true );
		$this->backup->run( $local_job_id, $type, $extra );
	}

	/**
	 * Run an async backup job (triggered via wp_schedule_single_event).
	 *
	 * @param string $local_job_id Local job transient key.
	 * @param string $type         Backup type.
	 * @param array  $extra        Extra parameters.
	 * @return void
	 */
	public function run_backup_job( string $local_job_id, string $type, array $extra = [] ): void {
		$this->backup->run( $local_job_id, $type, $extra );
	}
}
