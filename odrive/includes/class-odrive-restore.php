<?php
/**
 * Restore Connector
 *
 * @package ODriveConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles controlled restore operations initiated by ODrive.
 *
 * All destructive operations require a confirmed restore token.
 * No arbitrary command execution is ever allowed.
 */
class ODrive_Restore {

	/** Valid restore types. */
	const TYPES = array( 'database', 'uploads', 'themes', 'plugins', 'full' );

	/** Transient prefix for restore progress. */
	const TRANSIENT_PROGRESS = 'odrive_restore_progress_';

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

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Initiate a restore from ODrive.
	 *
	 * @param string $job_id           ODrive restore job ID.
	 * @param string $type             Restore type.
	 * @param string $confirm_token    One-time confirmation token from ODrive request.
	 * @param array  $options          Additional options.
	 * @return true|WP_Error
	 */
	public function start( string $job_id, string $type, string $confirm_token, array $options = [] ) {
		// Validate type.
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'odrive_invalid_type', __( 'Invalid restore type.', 'odrive-connector' ) );
		}

		// Validate confirmation token (stored as transient when ODrive initiates the request).
		if ( ! $this->validate_confirm_token( $job_id, $confirm_token ) ) {
			$this->log->log( 'restore_rejected', sprintf(
				/* translators: %s: job ID */
				__( 'Restore rejected: invalid confirmation token (job: %s)', 'odrive-connector' ),
				$job_id
			) );
			return new WP_Error( 'odrive_invalid_confirm', __( 'Invalid restore confirmation token.', 'odrive-connector' ) );
		}

		do_action( 'odrive_before_restore', $type );

		$this->set_progress( $job_id, array(
			'status'  => 'running',
			'type'    => $type,
			'percent' => 5,
			'message' => __( 'Restore starting…', 'odrive-connector' ),
		) );

		// Tell ODrive we're starting.
		$ack = $this->client->restore( $job_id, array( 'type' => $type ) );
		if ( is_wp_error( $ack ) ) {
			$this->fail( $job_id, $ack->get_error_message() );
			return $ack;
		}

		$this->set_progress( $job_id, array( 'percent' => 15, 'message' => __( 'Downloading artifact…', 'odrive-connector' ) ) );

		// Download the artifact URL provided by ODrive.
		$artifact_url = $ack['artifact_url'] ?? '';
		if ( ! $artifact_url ) {
			$err = new WP_Error( 'odrive_no_artifact', __( 'ODrive did not provide an artifact URL.', 'odrive-connector' ) );
			$this->fail( $job_id, $err->get_error_message() );
			return $err;
		}

		$local_path = $this->download_artifact( $artifact_url, $job_id );
		if ( is_wp_error( $local_path ) ) {
			$this->fail( $job_id, $local_path->get_error_message() );
			return $local_path;
		}

		$this->set_progress( $job_id, array( 'percent' => 50, 'message' => __( 'Applying restore…', 'odrive-connector' ) ) );

		// Apply restore.
		$apply = $this->apply_restore( $type, $local_path, $job_id, $options );

		// Cleanup downloaded file.
		if ( file_exists( $local_path ) ) {
			wp_delete_file( $local_path );
		}

		if ( is_wp_error( $apply ) ) {
			$this->fail( $job_id, $apply->get_error_message() );
			return $apply;
		}

		$this->set_progress( $job_id, array(
			'status'  => 'complete',
			'percent' => 100,
			'message' => __( 'Restore complete.', 'odrive-connector' ),
		) );

		$this->log->log( 'restore_complete', sprintf(
			/* translators: %s: restore type */
			__( 'Restore complete: %s', 'odrive-connector' ),
			$type
		), array( 'job_id' => $job_id ) );

		$this->client->report_progress( $job_id, 100, 'Restore complete' );

		do_action( 'odrive_after_restore', $type, array( 'success' => true ) );

		return true;
	}

	/**
	 * Register a one-time confirmation token for a restore job.
	 *
	 * Called when ODrive first sends the /restore REST request, before
	 * the user/system acknowledges the destructive operation.
	 *
	 * @param string $job_id ODrive job ID.
	 * @param string $token  One-time token to store.
	 * @return void
	 */
	public function register_confirm_token( string $job_id, string $token ): void {
		set_transient( 'odrive_restore_token_' . $job_id, hash( 'sha256', $token ), 30 * MINUTE_IN_SECONDS );
	}

	/**
	 * Get the progress of a restore job.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null
	 */
	public function get_progress( string $job_id ): ?array {
		$data = get_transient( self::TRANSIENT_PROGRESS . $job_id );
		return is_array( $data ) ? $data : null;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Validate the one-time confirmation token.
	 *
	 * @param string $job_id ODrive job ID.
	 * @param string $token  Token from request.
	 * @return bool
	 */
	private function validate_confirm_token( string $job_id, string $token ): bool {
		$stored = get_transient( 'odrive_restore_token_' . $job_id );
		if ( ! $stored ) {
			return false;
		}

		// Constant-time comparison.
		$valid = hash_equals( $stored, hash( 'sha256', $token ) );

		// One-time use.
		delete_transient( 'odrive_restore_token_' . $job_id );

		return $valid;
	}

	/**
	 * Download the restore artifact to a safe temp location.
	 *
	 * @param string $url     Artifact URL.
	 * @param string $job_id  Job ID (used for temp filename).
	 * @return string|WP_Error Absolute path to downloaded file.
	 */
	private function download_artifact( string $url, string $job_id ) {
		// Validate URL.
		$validated = filter_var( $url, FILTER_VALIDATE_URL );
		if ( ! $validated ) {
			return new WP_Error( 'odrive_invalid_url', __( 'Invalid artifact URL.', 'odrive-connector' ) );
		}

		$tmp_dir  = sys_get_temp_dir() . '/odrive-connector';
		if ( ! is_dir( $tmp_dir ) ) {
			wp_mkdir_p( $tmp_dir );
		}
		$local = $tmp_dir . '/odrive-restore-' . sanitize_file_name( $job_id ) . '.zip';

		$response = wp_remote_get( $validated, array(
			'timeout'  => 120,
			'stream'   => true,
			'filename' => $local,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'odrive_download_failed', sprintf(
				/* translators: %d: HTTP status code */
				__( 'Artifact download failed with HTTP %d.', 'odrive-connector' ),
				$code
			) );
		}

		return $local;
	}

	/**
	 * Apply a restore from a ZIP artifact.
	 *
	 * @param string $type     Restore type.
	 * @param string $zip_path Absolute path to ZIP artifact.
	 * @param string $job_id   Job ID.
	 * @param array  $options  Additional options.
	 * @return true|WP_Error
	 */
	private function apply_restore( string $type, string $zip_path, string $job_id, array $options ) {
		switch ( $type ) {
			case 'database':
				return $this->restore_database( $zip_path );
			case 'uploads':
				return $this->restore_directory( $zip_path, wp_upload_dir()['basedir'] );
			case 'themes':
				return $this->restore_directory( $zip_path, get_theme_root() );
			case 'plugins':
				return $this->restore_directory( $zip_path, WP_PLUGIN_DIR );
			case 'full':
				return $this->restore_full( $zip_path, $options );
			default:
				return new WP_Error( 'odrive_invalid_type', __( 'Unknown restore type.', 'odrive-connector' ) );
		}
	}

	/**
	 * Restore the database from a SQL file inside a ZIP.
	 *
	 * @param string $zip_path Path to ZIP containing a .sql file.
	 * @return true|WP_Error
	 */
	private function restore_database( string $zip_path ) {
		global $wpdb;

		$tmp_dir = sys_get_temp_dir() . '/odrive-connector/restore-db-' . uniqid();
		wp_mkdir_p( $tmp_dir );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'odrive_zip_error', __( 'Cannot open restore archive.', 'odrive-connector' ) );
		}

		$zip->extractTo( $tmp_dir );
		$zip->close();

		// Find the SQL file.
		$sql_files = glob( $tmp_dir . '/*.sql' );
		if ( empty( $sql_files ) ) {
			$this->rmdir_recursive( $tmp_dir );
			return new WP_Error( 'odrive_no_sql', __( 'No SQL file found in restore archive.', 'odrive-connector' ) );
		}

		$sql_file = $sql_files[0];

		// Validate path is inside tmp_dir (path traversal check).
		if ( 0 !== strpos( realpath( $sql_file ), realpath( $tmp_dir ) ) ) {
			$this->rmdir_recursive( $tmp_dir );
			return new WP_Error( 'odrive_security', __( 'Path traversal detected in restore archive.', 'odrive-connector' ) );
		}

		$sql = file_get_contents( $sql_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->rmdir_recursive( $tmp_dir );

		if ( false === $sql ) {
			return new WP_Error( 'odrive_fs_error', __( 'Cannot read SQL restore file.', 'odrive-connector' ) );
		}

		// Execute SQL statements.
		$statements = array_filter( array_map( 'trim', preg_split( '/;\s*\n/', $sql ) ) );
		foreach ( $statements as $statement ) {
			if ( '' === $statement ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $statement );
		}

		return true;
	}

	/**
	 * Restore a directory from a ZIP.
	 *
	 * @param string $zip_path   Path to ZIP.
	 * @param string $target_dir Target directory.
	 * @return true|WP_Error
	 */
	private function restore_directory( string $zip_path, string $target_dir ) {
		$real_target = realpath( $target_dir );
		if ( ! $real_target ) {
			wp_mkdir_p( $target_dir );
			$real_target = realpath( $target_dir );
		}

		if ( ! $real_target ) {
			return new WP_Error( 'odrive_fs_error', __( 'Cannot resolve restore target directory.', 'odrive-connector' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'odrive_zip_error', __( 'Cannot open restore archive.', 'odrive-connector' ) );
		}

		// Validate all entries before extraction.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry    = $zip->getNameIndex( $i );
			$resolved = realpath( $real_target . '/' . $entry );

			// Path traversal check.
			if ( $resolved && 0 !== strpos( $resolved, $real_target ) ) {
				$zip->close();
				return new WP_Error( 'odrive_security', __( 'Path traversal detected in restore archive.', 'odrive-connector' ) );
			}
		}

		$zip->extractTo( $real_target );
		$zip->close();

		return true;
	}

	/**
	 * Full-site restore (database + directories).
	 *
	 * @param string $zip_path Full backup archive path.
	 * @param array  $options  Options (which components to restore).
	 * @return true|WP_Error
	 */
	private function restore_full( string $zip_path, array $options ) {
		$tmp_dir = sys_get_temp_dir() . '/odrive-connector/restore-full-' . uniqid();
		wp_mkdir_p( $tmp_dir );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			$this->rmdir_recursive( $tmp_dir );
			return new WP_Error( 'odrive_zip_error', __( 'Cannot open full restore archive.', 'odrive-connector' ) );
		}

		$zip->extractTo( $tmp_dir );
		$zip->close();

		// Restore database.
		$db_files = glob( $tmp_dir . '/database/*.zip' );
		if ( $db_files ) {
			$result = $this->restore_database( $db_files[0] );
			if ( is_wp_error( $result ) ) {
				$this->rmdir_recursive( $tmp_dir );
				return $result;
			}
		}

		// Restore directories.
		$dir_map = array(
			'uploads' => wp_upload_dir()['basedir'],
			'themes'  => get_theme_root(),
			'plugins' => WP_PLUGIN_DIR,
		);

		foreach ( $dir_map as $label => $target ) {
			$sub_zip = $tmp_dir . '/' . $label . '.zip';
			if ( ! file_exists( $sub_zip ) ) {
				continue;
			}
			$result = $this->restore_directory( $sub_zip, $target );
			if ( is_wp_error( $result ) ) {
				$this->rmdir_recursive( $tmp_dir );
				return $result;
			}
		}

		$this->rmdir_recursive( $tmp_dir );
		return true;
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Mark a restore job as failed.
	 *
	 * @param string $job_id Job ID.
	 * @param string $reason Failure reason.
	 * @return void
	 */
	private function fail( string $job_id, string $reason ): void {
		$this->set_progress( $job_id, array(
			'status'  => 'failed',
			'message' => $reason,
		) );
		$this->client->report_failure( $job_id, $reason );
		$this->log->log( 'restore_failed', $reason, array( 'job_id' => $job_id ) );
		do_action( 'odrive_after_restore', 'unknown', array( 'success' => false, 'reason' => $reason ) );
	}

	/**
	 * Merge and persist restore progress.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $data   Data to merge.
	 * @return void
	 */
	private function set_progress( string $job_id, array $data ): void {
		$existing = get_transient( self::TRANSIENT_PROGRESS . $job_id );
		$merged   = is_array( $existing ) ? array_merge( $existing, $data ) : $data;
		set_transient( self::TRANSIENT_PROGRESS . $job_id, $merged, 6 * HOUR_IN_SECONDS );
	}
}
