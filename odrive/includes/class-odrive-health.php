<?php
/**
 * Health Check
 *
 * @package ODriveConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs periodic health checks and reports status to ODrive.
 */
class ODrive_Health {

	const OPTION_LAST_CHECK  = 'odrive_last_health_check';
	const OPTION_LAST_STATUS = 'odrive_health_status';

	/** @var ODrive_API_Client */
	private ODrive_API_Client $client;

	/** @var ODrive_Activity_Log */
	private ODrive_Activity_Log $log;

	/**
	 * @param ODrive_API_Client   $client API client.
	 * @param ODrive_Activity_Log $log    Activity log.
	 */
	public function __construct( ODrive_API_Client $client, ODrive_Activity_Log $log ) {
		$this->client = $client;
		$this->log    = $log;
	}

	/**
	 * Run a full health check.
	 *
	 * @return array Health status report.
	 */
	public function run_check(): array {
		global $wpdb;

		$report = array(
			'timestamp'         => current_time( 'mysql', true ),
			'odrive_reachable'  => false,
			'db_ok'             => false,
			'filesystem_ok'     => false,
			'last_backup_time'  => get_option( 'odrive_last_backup_time', null ),
			'last_backup_status'=> get_option( 'odrive_last_backup_status', null ),
			'registered'        => 'registered' === get_option( 'odrive_registration_status', 'unregistered' ),
		);

		// DB check.
		try {
			$report['db_ok'] = ( null !== $wpdb->get_var( 'SELECT 1' ) );
		} catch ( \Exception $e ) {
			$report['db_ok'] = false;
		}

		// Filesystem writable.
		$upload_dir            = wp_upload_dir();
		$report['filesystem_ok'] = is_writable( $upload_dir['basedir'] );

		// ODrive reachability.
		if ( get_option( 'odrive_connected', false ) ) {
			$ping = $this->client->health();
			$report['odrive_reachable'] = ! is_wp_error( $ping );
		}

		// Persist results.
		update_option( self::OPTION_LAST_CHECK, $report['timestamp'] );
		update_option( self::OPTION_LAST_STATUS, $report['odrive_reachable'] ? 'ok' : 'unreachable' );

		$this->log->log(
			'health_check',
			$report['odrive_reachable'] ? __( 'Health check passed.', 'odrive-connector' ) : __( 'Health check: ODrive unreachable.', 'odrive-connector' ),
			array(
				'db_ok'         => $report['db_ok'],
				'filesystem_ok' => $report['filesystem_ok'],
			)
		);

		do_action( 'odrive_health_check_complete', $report );

		return $report;
	}

	/**
	 * Get the last health check result.
	 *
	 * @return array { timestamp, status }
	 */
	public function get_last_result(): array {
		return array(
			'timestamp' => get_option( self::OPTION_LAST_CHECK, null ),
			'status'    => get_option( self::OPTION_LAST_STATUS, 'unknown' ),
		);
	}

	/**
	 * Build the health payload for the REST /health endpoint.
	 *
	 * @return array
	 */
	public function get_rest_payload(): array {
		global $wp_version;

		$last = $this->get_last_result();

		return array(
			'wp_version'        => $wp_version,
			'plugin_version'    => ODRIVE_VERSION,
			'php_version'       => PHP_VERSION,
			'last_check'        => $last['timestamp'],
			'last_check_status' => $last['status'],
			'last_backup_time'  => get_option( 'odrive_last_backup_time', null ),
			'last_backup_status'=> get_option( 'odrive_last_backup_status', null ),
			'site_url'          => get_site_url(),
		);
	}
}
