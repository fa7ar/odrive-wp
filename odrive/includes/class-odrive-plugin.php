<?php
/**
 * Core Plugin Class
 *
 * @package ODriveConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton orchestrator for the ODrive Connector plugin.
 *
 * Instantiates all sub-modules and wires them together.
 */
final class ODrive_Plugin {

	/** @var ODrive_Plugin|null */
	private static ?ODrive_Plugin $instance = null;

	/** @var ODrive_Auth */
	public ODrive_Auth $auth;

	/** @var ODrive_API_Client */
	public ODrive_API_Client $client;

	/** @var ODrive_Site_Registration */
	public ODrive_Site_Registration $registration;

	/** @var ODrive_Activity_Log */
	public ODrive_Activity_Log $activity_log;

	/** @var ODrive_Health */
	public ODrive_Health $health;

	/** @var ODrive_Backup */
	public ODrive_Backup $backup;

	/** @var ODrive_Restore */
	public ODrive_Restore $restore;

	/** @var ODrive_Media */
	public ODrive_Media $media;

	/** @var ODrive_REST_API */
	public ODrive_REST_API $rest;

	/** @var ODrive_Scheduler */
	public ODrive_Scheduler $scheduler;

	/** @var ODrive_Admin */
	public ODrive_Admin $admin;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {
		$this->init_modules();
		$this->register_hooks();
	}

	// -------------------------------------------------------------------------
	// Initialisation
	// -------------------------------------------------------------------------

	/**
	 * Instantiate all sub-modules in dependency order.
	 *
	 * @return void
	 */
	private function init_modules(): void {
		$this->activity_log = new ODrive_Activity_Log();
		$this->auth         = new ODrive_Auth();
		$this->client       = new ODrive_API_Client( $this->auth );
		$this->registration = new ODrive_Site_Registration( $this->client, $this->activity_log );
		$this->health       = new ODrive_Health( $this->client, $this->activity_log );
		$this->backup       = new ODrive_Backup( $this->client, $this->activity_log );
		$this->restore      = new ODrive_Restore( $this->client, $this->activity_log );
		$this->media        = new ODrive_Media( $this->client, $this->activity_log );
		$this->rest         = new ODrive_REST_API(
			$this->auth,
			$this->health,
			$this->backup,
			$this->restore,
			$this->activity_log
		);
		$this->scheduler    = new ODrive_Scheduler( $this->backup, $this->health, $this->activity_log );

		if ( is_admin() ) {
			$this->admin = new ODrive_Admin(
				$this->auth,
				$this->client,
				$this->registration,
				$this->backup,
				$this->health,
				$this->activity_log
			);
		}
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'init',             array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init',    array( $this->rest,      'register_routes' ) );
		add_action( 'init',             array( $this->scheduler, 'register' ) );
		add_action( 'init',             array( $this->media,     'register_hooks' ) );

		if ( is_admin() && isset( $this->admin ) ) {
			$this->admin->register();
		}
	}

	/**
	 * Load the plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'odrive-connector',
			false,
			dirname( ODRIVE_PLUGIN_BASENAME ) . '/languages'
		);
	}

	// -------------------------------------------------------------------------
	// Lifecycle hooks
	// -------------------------------------------------------------------------

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Ensure the activity log table exists.
		$log = new ODrive_Activity_Log();
		$log->create_table();

		// Generate a stable site ID on first activation.
		if ( '' === get_option( 'odrive_site_id', '' ) ) {
			$reg = new ODrive_Site_Registration(
				new ODrive_API_Client( new ODrive_Auth() ),
				$log
			);
			$reg->get_site_id(); // Side-effect: creates and persists the UUID.
		}

		// Default options.
		add_option( 'odrive_connected',            false );
		add_option( 'odrive_registration_status',  'unregistered' );

		// Schedule cron events.
		if ( ! wp_next_scheduled( 'odrive_health_check' ) ) {
			wp_schedule_event( time(), 'hourly', 'odrive_health_check' );
		}
		if ( ! wp_next_scheduled( 'odrive_cleanup_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'odrive_cleanup_logs' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'odrive_health_check' );
		wp_clear_scheduled_hook( 'odrive_cleanup_logs' );
		wp_clear_scheduled_hook( 'odrive_scheduled_backup' );

		flush_rewrite_rules();
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \RuntimeException Always.
	 */
	public function __wakeup(): void {
		throw new \RuntimeException( 'Cannot unserialize singleton.' );
	}
}
