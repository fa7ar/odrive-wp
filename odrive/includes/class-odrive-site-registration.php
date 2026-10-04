<?php
/**
 * Site Registration
 *
 * @package ODriveConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles registering and unregistering this WordPress site with ODrive.
 */
class ODrive_Site_Registration {

	const OPTION_SITE_ID = 'odrive_site_id';
	const OPTION_STATUS  = 'odrive_registration_status';

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
	 * Get (or generate) the stable local site ID.
	 *
	 * @return string UUID v4.
	 */
	public function get_site_id(): string {
		$id = get_option( self::OPTION_SITE_ID, '' );

		if ( '' === $id ) {
			$id = $this->generate_uuid();
			update_option( self::OPTION_SITE_ID, $id );
		}

		return $id;
	}

	/**
	 * Collect site metadata to send to ODrive.
	 *
	 * @return array
	 */
	public function get_site_metadata(): array {
		global $wp_version;

		return array(
			'site_id'        => $this->get_site_id(),
			'site_url'       => get_site_url(),
			'site_name'      => get_bloginfo( 'name' ),
			'wp_version'     => $wp_version,
			'plugin_version' => ODRIVE_VERSION,
			'php_version'    => PHP_VERSION,
			'capabilities'   => $this->get_capabilities(),
			'callback_url'   => rest_url( 'odrive/v1/' ),
		);
	}

	/**
	 * Register this site with ODrive.
	 *
	 * @return true|WP_Error
	 */
	public function register() {
		$metadata = $this->get_site_metadata();
		$response = $this->client->register_site( $metadata );

		if ( is_wp_error( $response ) ) {
			$this->log->log( 'registration_failed', $response->get_error_message() );
			return $response;
		}

		update_option( self::OPTION_STATUS, 'registered' );
		update_option( 'odrive_connected', true );

		$this->log->log( 'registered', __( 'Site registered with ODrive.', 'odrive-connector' ) );

		return true;
	}

	/**
	 * Unregister / disconnect this site from ODrive.
	 *
	 * @return true|WP_Error
	 */
	public function unregister() {
		$site_id  = $this->get_site_id();
		$response = $this->client->unregister_site( $site_id );

		// Even if ODrive returns an error we clean up locally.
		$this->clear_local_state();

		if ( is_wp_error( $response ) ) {
			$this->log->log( 'disconnect_warning', $response->get_error_message() );
			// Return true anyway — local disconnect always succeeds.
		} else {
			$this->log->log( 'disconnected', __( 'Site disconnected from ODrive.', 'odrive-connector' ) );
		}

		return true;
	}

	/**
	 * Get the current registration status string.
	 *
	 * @return string 'registered' | 'unregistered'
	 */
	public function get_registration_status(): string {
		return (string) get_option( self::OPTION_STATUS, 'unregistered' );
	}

	/**
	 * Return whether the site is currently registered.
	 *
	 * @return bool
	 */
	public function is_registered(): bool {
		return 'registered' === $this->get_registration_status();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Capabilities this WordPress install supports.
	 *
	 * @return array
	 */
	private function get_capabilities(): array {
		$caps = array( 'backup', 'restore', 'health' );

		if ( class_exists( 'ZipArchive' ) ) {
			$caps[] = 'zip';
		}

		if ( function_exists( 'gzopen' ) ) {
			$caps[] = 'gzip';
		}

		$caps[] = 'rest_api';

		return apply_filters( 'odrive_site_capabilities', $caps );
	}

	/**
	 * Clear local connection state (does not call ODrive API).
	 *
	 * @return void
	 */
	private function clear_local_state(): void {
		update_option( self::OPTION_STATUS, 'unregistered' );
		update_option( 'odrive_connected', false );
	}

	/**
	 * Generate a random UUID v4.
	 *
	 * @return string
	 */
	private function generate_uuid(): string {
		$data = random_bytes( 16 );

		// Set version bits (v4).
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		// Set variant bits.
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

		return vsprintf(
			'%s%s-%s-%s-%s-%s%s%s',
			str_split( bin2hex( $data ), 4 )
		);
	}
}
