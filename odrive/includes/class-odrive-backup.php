<?php
/**
 * Backup Connector
 *
 * @package ODriveConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles generating and uploading WordPress backup artifacts to ODrive.
 */
class ODrive_Backup {

	/** Valid backup types. */
	const TYPES = array( 'database', 'uploads', 'themes', 'plugins', 'full' );

	/** Transient prefix for job progress. */
	const TRANSIENT_PROGRESS = 'odrive_backup_progress_';

	/** Transient prefix for job cancel flag. */
	const TRANSIENT_CANCEL = 'odrive_backup_cancel_';

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
	 * Start a backup job asynchronously (schedules a cron event).
	 *
	 * @param string $type  One of self::TYPES.
	 * @param array  $extra Optional extra params forwarded to ODrive.
	 * @return string|WP_Error Local job transient key or error.
	 */
	public function start_async( string $type, array $extra = [] ) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'odrive_invalid_type', __( 'Invalid backup type.', 'odrive-connector' ) );
		}

		$local_job_id = uniqid( 'odrvbk_', true );

		$this->set_progress( $local_job_id, array(
			'status'    => 'queued',
			'type'      => $type,
			'percent'   => 0,
			'message'   => __( 'Backup queued.', 'odrive-connector' ),
			'started'   => time(),
		) );

		// Fire immediately via cron.
		wp_schedule_single_event( time(), 'odrive_run_backup', array( $local_job_id, $type, $extra ) );

		$this->log->log( 'backup_queued', sprintf(
			/* translators: %1$s: backup type, %2$s: job ID */
			__( 'Backup queued: %1$s (job: %2$s)', 'odrive-connector' ),
			$type,
			$local_job_id
		) );

		return $local_job_id;
	}

	/**
	 * Run a backup synchronously (called from WP-Cron or direct trigger).
	 *
	 * @param string $local_job_id Local job ID.
	 * @param string $type         Backup type.
	 * @param array  $extra        Extra params.
	 * @return true|WP_Error
	 */
	public function run( string $local_job_id, string $type, array $extra = [] ) {
		do_action( 'odrive_before_backup', $type );

		$this->set_progress( $local_job_id, array(
			'status'  => 'running',
			'percent' => 5,
			'message' => __( 'Starting backup…', 'odrive-connector' ),
		) );

		// Tell ODrive we are starting a backup.
		$odrive_job = $this->client->create_backup( array_merge(
			array(
				'type'          => $type,
				'local_job_id'  => $local_job_id,
				'site_id'       => get_option( 'odrive_site_id', '' ),
			),
			$extra
		) );

		if ( is_wp_error( $odrive_job ) ) {
			$this->fail( $local_job_id, null, $odrive_job->get_error_message() );
			return $odrive_job;
		}

		$odrive_job_id = $odrive_job['job_id'] ?? '';

		// Generate the artifact.
		$this->set_progress( $local_job_id, array( 'percent' => 15, 'message' => __( 'Generating backup artifact…', 'odrive-connector' ) ) );

		$artifact = $this->generate_artifact( $type, $local_job_id );

		if ( is_wp_error( $artifact ) ) {
			$this->fail( $local_job_id, $odrive_job_id, $artifact->get_error_message() );
			return $artifact;
		}

		// Stream upload.
		$this->set_progress( $local_job_id, array( 'percent' => 60, 'message' => __( 'Uploading to ODrive…', 'odrive-connector' ) ) );

		$result = $this->stream_upload( $local_job_id, $odrive_job_id, $artifact['path'], $artifact['filename'] );

		// Cleanup temp file.
		if ( file_exists( $artifact['path'] ) ) {
			wp_delete_file( $artifact['path'] );
		}

		if ( is_wp_error( $result ) ) {
			$this->fail( $local_job_id, $odrive_job_id, $result->get_error_message() );
			return $result;
		}

		// Done.
		$this->set_progress( $local_job_id, array(
			'status'  => 'complete',
			'percent' => 100,
			'message' => __( 'Backup complete.', 'odrive-connector' ),
		) );

		update_option( 'odrive_last_backup_time',   current_time( 'mysql', true ) );
		update_option( 'odrive_last_backup_status', 'success' );

		$this->log->log( 'backup_complete', sprintf(
			/* translators: %s: backup type */
			__( 'Backup complete: %s', 'odrive-connector' ),
			$type
		), array( 'odrive_job_id' => $odrive_job_id ) );

		do_action( 'odrive_after_backup', $type, array( 'success' => true, 'odrive_job_id' => $odrive_job_id ) );

		return true;
	}

	/**
	 * Cancel a running backup job.
	 *
	 * @param string $local_job_id Local job ID.
	 * @return void
	 */
	public function cancel( string $local_job_id ): void {
		set_transient( self::TRANSIENT_CANCEL . $local_job_id, true, HOUR_IN_SECONDS );
	}

	/**
	 * Get the progress of a backup job.
	 *
	 * @param string $local_job_id Local job ID.
	 * @return array|null Progress array or null if not found.
	 */
	public function get_progress( string $local_job_id ): ?array {
		$data = get_transient( self::TRANSIENT_PROGRESS . $local_job_id );
		return is_array( $data ) ? $data : null;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Generate backup artifact for a given type.
	 *
	 * @param string $type         Backup type.
	 * @param string $local_job_id Job ID (used for cancel checks).
	 * @return array|WP_Error { path, filename, size }
	 */
	private function generate_artifact( string $type, string $local_job_id ) {
		switch ( $type ) {
			case 'database':
				return $this->backup_database( $local_job_id );
			case 'uploads':
				return $this->backup_directory( wp_upload_dir()['basedir'], 'uploads', $local_job_id );
			case 'themes':
				return $this->backup_directory( get_theme_root(), 'themes', $local_job_id );
			case 'plugins':
				return $this->backup_directory( WP_PLUGIN_DIR, 'plugins', $local_job_id );
			case 'full':
				return $this->backup_full( $local_job_id );
			default:
				return new WP_Error( 'odrive_invalid_type', __( 'Unknown backup type.', 'odrive-connector' ) );
		}
	}

	/**
	 * Export the WordPress database to a SQL file, then ZIP it.
	 *
	 * Uses wpdb — no shell exec / mysqldump.
	 *
	 * @param string $local_job_id Job ID.
	 * @return array|WP_Error
	 */
	private function backup_database( string $local_job_id ) {
		global $wpdb;

		$tmp_dir  = $this->get_temp_dir();
		$sql_file = $tmp_dir . "/odrive-db-{$local_job_id}.sql";
		$zip_file = $tmp_dir . "/odrive-db-{$local_job_id}.zip";

		$fh = fopen( $sql_file, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return new WP_Error( 'odrive_fs_error', __( 'Cannot create temporary SQL file.', 'odrive-connector' ) );
		}

		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( $tables as $table ) {
			if ( $this->is_cancelled( $local_job_id ) ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				@unlink( $sql_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new WP_Error( 'odrive_cancelled', __( 'Backup cancelled.', 'odrive-connector' ) );
			}

			$this->write_table_sql( $fh, $table );
		}

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// Compress.
		$zip = $this->create_zip( $zip_file, array( $sql_file => basename( $sql_file ) ) );
		@unlink( $sql_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( is_wp_error( $zip ) ) {
			return $zip;
		}

		return array(
			'path'     => $zip_file,
			'filename' => "odrive-db-{$local_job_id}.zip",
			'size'     => filesize( $zip_file ),
		);
	}

	/**
	 * Write CREATE TABLE + INSERT statements for one table.
	 *
	 * @param resource $fh    File handle.
	 * @param string   $table Table name.
	 * @return void
	 */
	private function write_table_sql( $fh, string $table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
		if ( $create ) {
			fwrite( $fh, "\n\n-- Table: {$table}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fwrite( $fh, "DROP TABLE IF EXISTS `{$table}`;\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fwrite( $fh, $create[1] . ";\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$batch  = 500;
		$offset = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch, $offset ), ARRAY_A );

			if ( $rows ) {
				$columns = '`' . implode( '`, `', array_keys( $rows[0] ) ) . '`';
				foreach ( $rows as $row ) {
					$values = array_map( function ( $val ) use ( $wpdb ) {
						return null === $val ? 'NULL' : "'" . esc_sql( $val ) . "'";
					}, $row );
					fwrite( $fh, "INSERT INTO `{$table}` ({$columns}) VALUES (" . implode( ', ', $values ) . ");\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			}

			$offset += $batch;
		} while ( count( $rows ) === $batch );
	}

	/**
	 * Compress a directory into a ZIP file.
	 *
	 * @param string $source_dir  Source directory.
	 * @param string $type_label  Label used in filename.
	 * @param string $local_job_id Job ID.
	 * @return array|WP_Error
	 */
	private function backup_directory( string $source_dir, string $type_label, string $local_job_id ) {
		$tmp_dir  = $this->get_temp_dir();
		$zip_file = $tmp_dir . "/odrive-{$type_label}-{$local_job_id}.zip";

		if ( ! is_dir( $source_dir ) ) {
			return new WP_Error( 'odrive_dir_missing', sprintf(
				/* translators: %s: directory path */
				__( 'Source directory not found: %s', 'odrive-connector' ),
				$source_dir
			) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'odrive_zip_error', __( 'Cannot create ZIP archive.', 'odrive-connector' ) );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $this->is_cancelled( $local_job_id ) ) {
				$zip->close();
				@unlink( $zip_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new WP_Error( 'odrive_cancelled', __( 'Backup cancelled.', 'odrive-connector' ) );
			}

			$real = realpath( $file->getPathname() );
			if ( false === $real ) {
				continue;
			}

			// Path traversal check.
			if ( 0 !== strpos( $real, realpath( $source_dir ) ) ) {
				continue;
			}

			$relative = substr( $real, strlen( realpath( $source_dir ) ) + 1 );

			if ( $file->isDir() ) {
				$zip->addEmptyDir( $relative );
			} else {
				$zip->addFile( $real, $relative );
			}
		}

		$zip->close();

		return array(
			'path'     => $zip_file,
			'filename' => "odrive-{$type_label}-{$local_job_id}.zip",
			'size'     => filesize( $zip_file ),
		);
	}

	/**
	 * Full-site backup: database + uploads + themes + plugins in one archive.
	 *
	 * @param string $local_job_id Job ID.
	 * @return array|WP_Error
	 */
	private function backup_full( string $local_job_id ) {
		$tmp_dir  = $this->get_temp_dir();
		$zip_file = $tmp_dir . "/odrive-full-{$local_job_id}.zip";

		// Database dump to a temp SQL file first.
		$db_artifact = $this->backup_database( $local_job_id . '_db' );
		if ( is_wp_error( $db_artifact ) ) {
			return $db_artifact;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'odrive_zip_error', __( 'Cannot create full backup archive.', 'odrive-connector' ) );
		}

		// Add DB artifact.
		$zip->addFile( $db_artifact['path'], 'database/' . $db_artifact['filename'] );

		// Add directories.
		$dirs = apply_filters( 'odrive_full_backup_dirs', array(
			'uploads' => wp_upload_dir()['basedir'],
			'themes'  => get_theme_root(),
			'plugins' => WP_PLUGIN_DIR,
		) );

		foreach ( $dirs as $label => $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);

			$real_base = realpath( $dir );

			foreach ( $iterator as $file ) {
				if ( $this->is_cancelled( $local_job_id ) ) {
					$zip->close();
					@unlink( $zip_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					@unlink( $db_artifact['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					return new WP_Error( 'odrive_cancelled', __( 'Backup cancelled.', 'odrive-connector' ) );
				}

				$real = realpath( $file->getPathname() );
				if ( false === $real || 0 !== strpos( $real, $real_base ) ) {
					continue;
				}

				$relative = $label . '/' . substr( $real, strlen( $real_base ) + 1 );

				if ( $file->isDir() ) {
					$zip->addEmptyDir( $relative );
				} else {
					$zip->addFile( $real, $relative );
				}
			}
		}

		$zip->close();
		@unlink( $db_artifact['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return array(
			'path'     => $zip_file,
			'filename' => "odrive-full-{$local_job_id}.zip",
			'size'     => filesize( $zip_file ),
		);
	}

	/**
	 * Stream a file to ODrive in chunks.
	 *
	 * @param string $local_job_id  Local job ID (for progress updates).
	 * @param string $odrive_job_id ODrive job ID.
	 * @param string $file_path     Absolute path to file.
	 * @param string $filename      Remote filename.
	 * @return true|WP_Error
	 */
	private function stream_upload( string $local_job_id, string $odrive_job_id, string $file_path, string $filename ) {
		$filesize = filesize( $file_path );
		$handle   = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $handle ) {
			return new WP_Error( 'odrive_fs_error', __( 'Cannot open backup file for upload.', 'odrive-connector' ) );
		}

		$result = $this->client->upload_artifact( $odrive_job_id, $handle, $filesize, $filename );

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->set_progress( $local_job_id, array( 'percent' => 90, 'message' => __( 'Upload complete.', 'odrive-connector' ) ) );

		return true;
	}

	/**
	 * Create a ZIP archive from an array of file => archive-name pairs.
	 *
	 * @param string $zip_path  Destination ZIP file path.
	 * @param array  $files     { abs_path => archive_name }
	 * @return true|WP_Error
	 */
	private function create_zip( string $zip_path, array $files ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'odrive_zip_error', __( 'Cannot create ZIP.', 'odrive-connector' ) );
		}

		foreach ( $files as $abs_path => $archive_name ) {
			if ( file_exists( $abs_path ) ) {
				$zip->addFile( $abs_path, $archive_name );
			}
		}

		$zip->close();
		return true;
	}

	/**
	 * Get or create a safe temporary directory for backup artifacts.
	 *
	 * @return string Absolute path.
	 */
	private function get_temp_dir(): string {
		$dir = sys_get_temp_dir() . '/odrive-connector';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Drop an .htaccess just in case temp dir is web-accessible.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	/**
	 * Check if a cancel flag has been set for a job.
	 *
	 * @param string $local_job_id Job ID.
	 * @return bool
	 */
	private function is_cancelled( string $local_job_id ): bool {
		return (bool) get_transient( self::TRANSIENT_CANCEL . $local_job_id );
	}

	/**
	 * Merge and persist progress data for a job.
	 *
	 * @param string $local_job_id Job ID.
	 * @param array  $data         Data to merge.
	 * @return void
	 */
	private function set_progress( string $local_job_id, array $data ): void {
		$existing = get_transient( self::TRANSIENT_PROGRESS . $local_job_id );
		$merged   = is_array( $existing ) ? array_merge( $existing, $data ) : $data;
		set_transient( self::TRANSIENT_PROGRESS . $local_job_id, $merged, 6 * HOUR_IN_SECONDS );
	}

	/**
	 * Mark a job as failed, report to ODrive, and log the error.
	 *
	 * @param string      $local_job_id  Local job ID.
	 * @param string|null $odrive_job_id ODrive job ID (may be null if ODrive call failed early).
	 * @param string      $reason        Failure reason.
	 * @return void
	 */
	private function fail( string $local_job_id, ?string $odrive_job_id, string $reason ): void {
		$this->set_progress( $local_job_id, array(
			'status'  => 'failed',
			'message' => $reason,
		) );

		update_option( 'odrive_last_backup_status', 'failed' );

		if ( $odrive_job_id ) {
			$this->client->report_failure( $odrive_job_id, $reason );
		}

		$this->log->log( 'backup_failed', $reason );
		do_action( 'odrive_after_backup', 'unknown', array( 'success' => false, 'reason' => $reason ) );
	}
}
