<?php
/**
 * Media Integration
 *
 * @package ODriveConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prepares media workflow hooks for future ODrive media offload/backup.
 *
 * Offload is disabled by default and can be enabled via the
 * `odrive_media_offload_enabled` filter.
 */
class ODrive_Media {

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
	 * Register all WordPress hooks required for media integration.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Backup hooks.
		add_action( 'odrive_media_backup',   array( $this, 'backup_media' ),   10, 1 );

		// Offload hooks (only fire when offload is enabled).
		add_filter( 'wp_handle_upload',      array( $this, 'handle_upload' ),  10, 2 );
		add_filter( 'wp_get_attachment_url', array( $this, 'map_external_url' ), 10, 2 );
		add_action( 'delete_attachment',     array( $this, 'on_delete_attachment' ), 10, 1 );

		// Restore hooks.
		add_action( 'odrive_media_restore',  array( $this, 'restore_media' ),  10, 2 );

		// Import hook.
		add_action( 'odrive_media_import',   array( $this, 'import_from_odrive' ), 10, 2 );
	}

	/**
	 * Backup media (uploads directory) to ODrive.
	 *
	 * @param array $args Optional arguments.
	 * @return true|WP_Error
	 */
	public function backup_media( array $args = [] ) {
		/**
		 * Allow third-party code to handle media backup.
		 *
		 * @param null  $pre  Return a non-null value to short-circuit.
		 * @param array $args Backup arguments.
		 */
		$pre = apply_filters( 'odrive_pre_media_backup', null, $args );
		if ( null !== $pre ) {
			return $pre;
		}

		$this->log->log( 'media_backup_started', __( 'Media backup initiated via ODrive.', 'odrive-connector' ) );

		do_action( 'odrive_media_backup_started', $args );

		// Actual implementation deferred to the Backup connector.
		return apply_filters( 'odrive_media_backup_handler', true, $args );
	}

	/**
	 * Intercept a newly uploaded file for optional offload.
	 *
	 * @param array  $upload    Upload data from WP.
	 * @param string $context   Upload context ('upload' | 'sideload').
	 * @return array Potentially modified upload data.
	 */
	public function handle_upload( array $upload, string $context ): array {
		if ( ! $this->is_offload_enabled() ) {
			return $upload;
		}

		/**
		 * Allow offloading a newly uploaded file to ODrive.
		 *
		 * @param array  $upload  WP upload data.
		 * @param string $context Upload context.
		 */
		return apply_filters( 'odrive_offload_upload', $upload, $context );
	}

	/**
	 * Offload a media file to ODrive storage.
	 *
	 * Stub — extend via `odrive_offload_media` filter.
	 *
	 * @param int    $attachment_id WP attachment post ID.
	 * @param string $file_path     Absolute local path to the file.
	 * @return true|WP_Error
	 */
	public function offload_media( int $attachment_id, string $file_path ) {
		if ( ! $this->is_offload_enabled() ) {
			return new WP_Error( 'odrive_offload_disabled', __( 'ODrive media offload is not enabled.', 'odrive-connector' ) );
		}

		/**
		 * Handle the actual upload of a local file to ODrive.
		 *
		 * @param null   $result        Return WP_Error or true from your handler.
		 * @param int    $attachment_id WP attachment ID.
		 * @param string $file_path     Absolute path.
		 */
		$result = apply_filters( 'odrive_offload_media', null, $attachment_id, $file_path );

		if ( null === $result ) {
			return new WP_Error( 'odrive_no_offload_handler', __( 'No ODrive offload handler registered.', 'odrive-connector' ) );
		}

		return $result;
	}

	/**
	 * Restore media from ODrive.
	 *
	 * @param string $job_id  ODrive restore job ID.
	 * @param array  $options Restore options.
	 * @return true|WP_Error
	 */
	public function restore_media( string $job_id, array $options = [] ) {
		/**
		 * Handle media restore.
		 *
		 * @param null   $result  Return a value to short-circuit.
		 * @param string $job_id  ODrive job ID.
		 * @param array  $options Options.
		 */
		$result = apply_filters( 'odrive_restore_media_handler', null, $job_id, $options );

		if ( null !== $result ) {
			return $result;
		}

		$this->log->log( 'media_restore_started', sprintf(
			/* translators: %s: job ID */
			__( 'Media restore started (job: %s)', 'odrive-connector' ),
			$job_id
		) );

		do_action( 'odrive_media_restore_started', $job_id, $options );

		return true;
	}

	/**
	 * Import media from ODrive into the WordPress Media Library.
	 *
	 * @param string $odrive_file_id ODrive file identifier.
	 * @param array  $options        Import options (title, alt text, etc.).
	 * @return int|WP_Error New attachment ID or error.
	 */
	public function import_from_odrive( string $odrive_file_id, array $options = [] ) {
		/**
		 * Handle importing a file from ODrive into the media library.
		 *
		 * @param null   $result         Return attachment ID (int) or WP_Error.
		 * @param string $odrive_file_id ODrive file identifier.
		 * @param array  $options        Import options.
		 */
		$result = apply_filters( 'odrive_import_from_odrive_handler', null, $odrive_file_id, $options );

		if ( null !== $result ) {
			return $result;
		}

		$this->log->log( 'media_import_requested', sprintf(
			/* translators: %s: file ID */
			__( 'Import from ODrive requested: %s', 'odrive-connector' ),
			$odrive_file_id
		) );

		do_action( 'odrive_import_requested', $odrive_file_id, $options );

		return new WP_Error( 'odrive_no_import_handler', __( 'No ODrive import handler registered.', 'odrive-connector' ) );
	}

	/**
	 * Map a local attachment URL to an ODrive external URL when offload is active.
	 *
	 * @param string $url           Current attachment URL.
	 * @param int    $attachment_id WP attachment post ID.
	 * @return string Possibly replaced URL.
	 */
	public function map_external_url( string $url, int $attachment_id ): string {
		if ( ! $this->is_offload_enabled() ) {
			return $url;
		}

		/**
		 * Replace a local attachment URL with the ODrive-served URL.
		 *
		 * @param string $url           Original WP URL.
		 * @param int    $attachment_id Attachment post ID.
		 */
		return (string) apply_filters( 'odrive_external_attachment_url', $url, $attachment_id );
	}

	/**
	 * React to an attachment being deleted (clean up ODrive-side if offloaded).
	 *
	 * @param int $attachment_id WP attachment post ID.
	 * @return void
	 */
	public function on_delete_attachment( int $attachment_id ): void {
		if ( ! $this->is_offload_enabled() ) {
			return;
		}

		/**
		 * Fires when an offloaded attachment is deleted in WordPress.
		 *
		 * Consumers should clean up the remote copy.
		 *
		 * @param int $attachment_id Attachment post ID.
		 */
		do_action( 'odrive_attachment_deleted', $attachment_id );
	}

	/**
	 * Check whether media offload is enabled.
	 *
	 * @return bool
	 */
	public function is_offload_enabled(): bool {
		return (bool) apply_filters( 'odrive_media_offload_enabled', false );
	}
}
