<?php
/**
 * Activity Log
 *
 * @package ODriveConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles audit/activity logging to a custom DB table.
 */
class ODrive_Activity_Log {

	/**
	 * Table name (without prefix).
	 */
	const TABLE = 'odrive_activity';

	/**
	 * Current DB schema version.
	 */
	const DB_VERSION = '1.0';

	/**
	 * Get full table name.
	 *
	 * @return string
	 */
	private function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create (or upgrade) the activity log table.
	 *
	 * @return void
	 */
	public function create_table(): void {
		global $wpdb;

		$table      = $this->table_name();
		$charset    = $wpdb->get_charset_collate();
		$db_version = get_option( 'odrive_db_version', '' );

		if ( $db_version === self::DB_VERSION ) {
			return;
		}

		$sql = "CREATE TABLE {$table} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			event_type  VARCHAR(64)         NOT NULL DEFAULT '',
			message     TEXT                NOT NULL,
			metadata    LONGTEXT                     DEFAULT NULL,
			user_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			ip_address  VARCHAR(45)                  DEFAULT NULL,
			PRIMARY KEY (id),
			KEY event_type (event_type),
			KEY timestamp  (timestamp)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'odrive_db_version', self::DB_VERSION );
	}

	/**
	 * Write a log entry.
	 *
	 * @param string $event_type Short machine-readable event key.
	 * @param string $message    Human-readable message (no secrets).
	 * @param array  $metadata   Optional structured data (no secrets).
	 * @return int|false Inserted row ID or false on failure.
	 */
	public function log( string $event_type, string $message, array $metadata = [] ) {
		global $wpdb;

		// Strip any obviously sensitive keys from metadata before persisting.
		$safe_meta = $this->redact_metadata( $metadata );

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table_name(),
			array(
				'timestamp'  => current_time( 'mysql', true ),
				'event_type' => sanitize_key( $event_type ),
				'message'    => sanitize_text_field( $message ),
				'metadata'   => ! empty( $safe_meta ) ? wp_json_encode( $safe_meta ) : null,
				'user_id'    => get_current_user_id(),
				'ip_address' => $this->get_ip_address(),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Retrieve log entries.
	 *
	 * @param array $args {
	 *     @type int    $per_page   Entries per page. Default 50.
	 *     @type int    $paged      Page number. Default 1.
	 *     @type string $event_type Filter by event type.
	 *     @type string $order      ASC|DESC. Default DESC.
	 * }
	 * @return array { rows, total }
	 */
	public function get_logs( array $args = [] ): array {
		global $wpdb;

		$defaults = array(
			'per_page'   => 50,
			'paged'      => 1,
			'event_type' => '',
			'order'      => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		$per_page = absint( $args['per_page'] );
		$offset   = ( absint( $args['paged'] ) - 1 ) * $per_page;
		$order    = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$table    = $this->table_name();

		$where  = '';
		$values = array();

		if ( ! empty( $args['event_type'] ) ) {
			$where    = ' WHERE event_type = %s';
			$values[] = sanitize_key( $args['event_type'] );
		}

		// Total count.
		if ( $values ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}{$where}", ...$values ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		// Rows.
		$limit_clause = $wpdb->prepare( ' ORDER BY id %1s LIMIT %d OFFSET %d', $order, $per_page, $offset );

		if ( $values ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}{$where}", ...$values ) . $limit_clause );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$table}" . $limit_clause );
		}

		return array(
			'rows'  => $rows ?: array(),
			'total' => $total,
		);
	}

	/**
	 * Delete log entries older than N days.
	 *
	 * @param int $days Entries older than this many days are deleted.
	 * @return int Number of rows deleted.
	 */
	public function clear_old_logs( int $days = 90 ): int {
		global $wpdb;

		$table = $this->table_name();
		$date  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE timestamp < %s", $date ) );

		return (int) $deleted;
	}

	/**
	 * Remove potentially sensitive keys from metadata array.
	 *
	 * @param array $metadata Raw metadata.
	 * @return array Sanitized metadata.
	 */
	private function redact_metadata( array $metadata ): array {
		$sensitive_keys = array(
			'token', 'api_token', 'password', 'secret', 'key',
			'auth', 'credential', 'salt', 'hash',
		);

		array_walk_recursive(
			$metadata,
			function ( &$value, $key ) use ( $sensitive_keys ) {
				$key_lower = strtolower( (string) $key );
				foreach ( $sensitive_keys as $s ) {
					if ( strpos( $key_lower, $s ) !== false ) {
						$value = '[REDACTED]';
						break;
					}
				}
			}
		);

		return $metadata;
	}

	/**
	 * Get the current visitor's IP address.
	 *
	 * @return string
	 */
	private function get_ip_address(): string {
		$ip = '';

		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
