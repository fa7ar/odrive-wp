<?php
/**
 * Admin UI
 *
 * @package ODriveConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the WordPress admin pages and handles all admin AJAX actions.
 */
class ODrive_Admin {

	/** @var ODrive_Auth */
	private ODrive_Auth $auth;

	/** @var ODrive_API_Client */
	private ODrive_API_Client $client;

	/** @var ODrive_Site_Registration */
	private ODrive_Site_Registration $registration;

	/** @var ODrive_Backup */
	private ODrive_Backup $backup;

	/** @var ODrive_Health */
	private ODrive_Health $health;

	/** @var ODrive_Activity_Log */
	private ODrive_Activity_Log $log;

	/**
	 * Constructor.
	 *
	 * @param ODrive_Auth              $auth         Auth instance.
	 * @param ODrive_API_Client        $client       API client.
	 * @param ODrive_Site_Registration $registration Registration instance.
	 * @param ODrive_Backup            $backup       Backup instance.
	 * @param ODrive_Health            $health       Health instance.
	 * @param ODrive_Activity_Log      $log          Activity log.
	 */
	public function __construct(
		ODrive_Auth $auth,
		ODrive_API_Client $client,
		ODrive_Site_Registration $registration,
		ODrive_Backup $backup,
		ODrive_Health $health,
		ODrive_Activity_Log $log
	) {
		$this->auth         = $auth;
		$this->client       = $client;
		$this->registration = $registration;
		$this->backup       = $backup;
		$this->health       = $health;
		$this->log          = $log;
	}

	/**
	 * Register all admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu',             array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts',  array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init',             array( $this, 'register_settings' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_odrive_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_odrive_connect',         array( $this, 'ajax_connect' ) );
		add_action( 'wp_ajax_odrive_disconnect',      array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_odrive_run_backup',      array( $this, 'ajax_run_backup' ) );
		add_action( 'wp_ajax_odrive_backup_status',   array( $this, 'ajax_backup_status' ) );
	}

	/**
	 * Register the admin menu item.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'ODrive Connector', 'odrive-connector' ),
			__( 'ODrive', 'odrive-connector' ),
			'manage_options',
			'odrive-connector',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS and JS.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_odrive-connector' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'odrive-admin',
			ODRIVE_PLUGIN_URL . 'admin/assets/odrive-admin.css',
			array(),
			ODRIVE_VERSION
		);

		wp_enqueue_script(
			'odrive-admin',
			ODRIVE_PLUGIN_URL . 'admin/assets/odrive-admin.js',
			array( 'jquery' ),
			ODRIVE_VERSION,
			true
		);

		wp_localize_script( 'odrive-admin', 'odriveAdmin', array(
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'odrive_admin_nonce' ),
			'connected'       => (bool) get_option( 'odrive_connected', false ),
			'strings'         => array(
				'testing'        => __( 'Testing…', 'odrive-connector' ),
				'connecting'     => __( 'Connecting…', 'odrive-connector' ),
				'disconnecting'  => __( 'Disconnecting…', 'odrive-connector' ),
				'success'        => __( 'Success', 'odrive-connector' ),
				'error'          => __( 'Error', 'odrive-connector' ),
				'backupQueued'   => __( 'Backup queued', 'odrive-connector' ),
				'confirm_disconnect' => __( 'Disconnect this site from ODrive? This will remove the site registration.', 'odrive-connector' ),
			),
		) );
	}

	/**
	 * Register plugin settings with the WP Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'odrive_settings_group',
			'odrive_backup_schedule',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'none',
			)
		);
	}

	/**
	 * Render the main admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'odrive-connector' ) );
		}

		// Handle settings form POST.
		if ( isset( $_POST['odrive_settings_submit'] ) ) {
			check_admin_referer( 'odrive_settings_save', 'odrive_settings_nonce' );
			$this->save_settings();
		}

		require_once ODRIVE_PLUGIN_DIR . 'admin/views/page-main.php';
	}

	/**
	 * Save settings from the settings tab POST.
	 *
	 * @return void
	 */
	private function save_settings(): void {
		if ( isset( $_POST['odrive_backup_schedule'] ) ) {
			update_option( 'odrive_backup_schedule', sanitize_text_field( wp_unslash( $_POST['odrive_backup_schedule'] ) ) );
		}
		add_settings_error( 'odrive_messages', 'odrive_saved', __( 'Settings saved.', 'odrive-connector' ), 'updated' );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Test the ODrive connection.
	 *
	 * @return void
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'odrive_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'odrive-connector' ) ), 403 );
		}

		// Temporarily use posted URL/token if provided (before saving).
		$url   = isset( $_POST['odrive_url'] )   ? esc_url_raw( wp_unslash( $_POST['odrive_url'] ) )   : $this->auth->get_odrive_url();
		$token = isset( $_POST['odrive_token'] ) ? sanitize_text_field( wp_unslash( $_POST['odrive_token'] ) ) : $this->auth->get_token();

		if ( '' === $url || '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'ODrive URL and Token are required.', 'odrive-connector' ) ) );
		}

		// Temporarily override for this request.
		$orig_url   = $this->auth->get_odrive_url();
		$orig_token = $this->auth->get_token();

		$this->auth->set_odrive_url( $url );
		$this->auth->set_token( $token );

		$result = $this->client->authenticate();

		// Restore originals.
		$this->auth->set_odrive_url( $orig_url );
		if ( $orig_token ) {
			$this->auth->set_token( $orig_token );
		}

		if ( is_wp_error( $result ) ) {
			$this->log->log( 'test_connection_failed', $result->get_error_message() );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$this->log->log( 'test_connection_ok', __( 'Connection test successful.', 'odrive-connector' ) );
		wp_send_json_success( array(
			'message'  => __( 'Connection successful.', 'odrive-connector' ),
			'instance' => $result['instance'] ?? '',
		) );
	}

	/**
	 * AJAX: Save credentials and register the site.
	 *
	 * @return void
	 */
	public function ajax_connect(): void {
		check_ajax_referer( 'odrive_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'odrive-connector' ) ), 403 );
		}

		$url   = isset( $_POST['odrive_url'] )   ? esc_url_raw( wp_unslash( $_POST['odrive_url'] ) )   : '';
		$token = isset( $_POST['odrive_token'] ) ? sanitize_text_field( wp_unslash( $_POST['odrive_token'] ) ) : '';

		if ( '' === $url || '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'ODrive URL and Token are required.', 'odrive-connector' ) ) );
		}

		$this->auth->set_odrive_url( $url );
		$this->auth->set_token( $token );

		$result = $this->registration->register();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Run initial health check.
		$health = $this->health->run_check();

		wp_send_json_success( array(
			'message'     => __( 'Connected and registered successfully.', 'odrive-connector' ),
			'masked_token'=> $this->auth->get_masked_token(),
			'health'      => $health['odrive_reachable'] ? 'ok' : 'unreachable',
		) );
	}

	/**
	 * AJAX: Disconnect from ODrive.
	 *
	 * @return void
	 */
	public function ajax_disconnect(): void {
		check_ajax_referer( 'odrive_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'odrive-connector' ) ), 403 );
		}

		$this->registration->unregister();
		$this->auth->delete_token();
		$this->auth->delete_odrive_url();

		wp_send_json_success( array(
			'message' => __( 'Disconnected from ODrive.', 'odrive-connector' ),
		) );
	}

	/**
	 * AJAX: Queue a backup.
	 *
	 * @return void
	 */
	public function ajax_run_backup(): void {
		check_ajax_referer( 'odrive_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'odrive-connector' ) ), 403 );
		}

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

		if ( ! in_array( $type, ODrive_Backup::TYPES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid backup type.', 'odrive-connector' ) ) );
		}

		$job_id = $this->backup->start_async( $type );

		if ( is_wp_error( $job_id ) ) {
			wp_send_json_error( array( 'message' => $job_id->get_error_message() ) );
		}

		wp_send_json_success( array(
			'job_id'  => $job_id,
			'message' => __( 'Backup queued.', 'odrive-connector' ),
		) );
	}

	/**
	 * AJAX: Poll backup progress.
	 *
	 * @return void
	 */
	public function ajax_backup_status(): void {
		check_ajax_referer( 'odrive_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'odrive-connector' ) ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		if ( '' === $job_id ) {
			wp_send_json_error( array( 'message' => __( 'Job ID required.', 'odrive-connector' ) ) );
		}

		$progress = $this->backup->get_progress( $job_id );
		if ( null === $progress ) {
			wp_send_json_error( array( 'message' => __( 'Backup job not found.', 'odrive-connector' ) ), 404 );
		}

		wp_send_json_success( $progress );
	}

	// -------------------------------------------------------------------------
	// View data helpers (used by view templates)
	// -------------------------------------------------------------------------

	/**
	 * Get connection status data for the connection tab.
	 *
	 * @return array
	 */
	public function get_connection_data(): array {
		$connected  = (bool) get_option( 'odrive_connected', false );
		$health     = $this->health->get_last_result();

		return array(
			'connected'           => $connected,
			'odrive_url'          => $this->auth->get_odrive_url(),
			'masked_token'        => $this->auth->get_masked_token(),
			'has_token'           => $this->auth->has_token(),
			'registration_status' => $this->registration->get_registration_status(),
			'last_health_check'   => $health['timestamp'],
			'health_status'       => $health['status'],
			'site_id'             => $this->registration->get_site_id(),
		);
	}

	/**
	 * Get backup status data for the backup tab.
	 *
	 * @return array
	 */
	public function get_backup_data(): array {
		return array(
			'types'              => ODrive_Backup::TYPES,
			'last_backup_time'   => get_option( 'odrive_last_backup_time', null ),
			'last_backup_status' => get_option( 'odrive_last_backup_status', null ),
		);
	}
}
