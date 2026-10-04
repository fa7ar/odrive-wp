<?php
/**
 * ODrive API Client
 *
 * @package ODriveConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central HTTP client for all ODrive API communication.
 *
 * All outbound requests go through this class. Never scatter raw
 * wp_remote_* calls elsewhere.
 */
class ODrive_API_Client {

	/** API version path segment. */
	const API_VERSION = 'api/v1';

	/** Default request timeout in seconds. */
	const TIMEOUT = 30;

	/** Maximum retry attempts for transient errors. */
	const MAX_RETRIES = 3;

	/** Chunk size for streaming uploads (8 MB). */
	const UPLOAD_CHUNK_SIZE = 8 * 1024 * 1024;

	/** @var ODrive_Auth */
	private ODrive_Auth $auth;

	/**
	 * Constructor.
	 *
	 * @param ODrive_Auth $auth Auth instance.
	 */
	public function __construct( ODrive_Auth $auth ) {
		$this->auth = $auth;
	}

	// -------------------------------------------------------------------------
	// Public API methods
	// -------------------------------------------------------------------------

	/**
	 * Verify authentication against ODrive.
	 *
	 * @return array|WP_Error Response body or WP_Error.
	 */
	public function authenticate() {
		return $this->get( 'auth/verify' );
	}

	/**
	 * Register this WordPress site with ODrive.
	 *
	 * @param array $metadata Site metadata.
	 * @return array|WP_Error
	 */
	public function register_site( array $metadata ) {
		return $this->post( 'sites/register', $metadata );
	}

	/**
	 * Unregister / disconnect this site from ODrive.
	 *
	 * @param string $site_id Local site identifier.
	 * @return array|WP_Error
	 */
	public function unregister_site( string $site_id ) {
		return $this->delete( "sites/{$site_id}" );
	}

	/**
	 * Perform a health-check ping against ODrive.
	 *
	 * @return array|WP_Error
	 */
	public function health() {
		return $this->get( 'health' );
	}

	/**
	 * Instruct ODrive to create a backup job.
	 *
	 * @param array $params Backup parameters.
	 * @return array|WP_Error  { job_id, ... }
	 */
	public function create_backup( array $params ) {
		return $this->post( 'backups', $params );
	}

	/**
	 * Upload a backup artifact to ODrive (streamed in chunks).
	 *
	 * @param string   $job_id   ODrive backup job ID.
	 * @param resource $handle   Opened file handle positioned at start.
	 * @param int      $filesize Total byte size of the file.
	 * @param string   $filename Remote filename hint.
	 * @return array|WP_Error
	 */
	public function upload_artifact( string $job_id, $handle, int $filesize, string $filename ) {
		$chunk_size = apply_filters( 'odrive_upload_chunk_size', self::UPLOAD_CHUNK_SIZE );
		$offset     = 0;
		$part       = 0;
		$last_resp  = array();

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, $chunk_size );
			if ( false === $chunk ) {
				return new WP_Error( 'odrive_read_error', __( 'Failed to read backup file chunk.', 'odrive-connector' ) );
			}

			$content_range = sprintf( 'bytes %d-%d/%d', $offset, $offset + strlen( $chunk ) - 1, $filesize );

			$response = $this->request(
				'PUT',
				"backups/{$job_id}/artifact",
				array(
					'headers' => array(
						'Content-Range'       => $content_range,
						'X-ODrive-Filename'   => sanitize_file_name( $filename ),
						'X-ODrive-Part'       => (string) $part,
					),
					'body'    => $chunk,
					'timeout' => 120,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$offset   += strlen( $chunk );
			$last_resp = $response;
			++$part;
		}

		return $last_resp;
	}

	/**
	 * Report backup/restore progress to ODrive.
	 *
	 * @param string $job_id   ODrive job ID.
	 * @param int    $percent  0-100.
	 * @param string $message  Optional status message.
	 * @return array|WP_Error
	 */
	public function report_progress( string $job_id, int $percent, string $message = '' ) {
		return $this->post(
			"jobs/{$job_id}/progress",
			array(
				'percent' => max( 0, min( 100, $percent ) ),
				'message' => sanitize_text_field( $message ),
			)
		);
	}

	/**
	 * Report a job failure to ODrive.
	 *
	 * @param string $job_id  ODrive job ID.
	 * @param string $reason  Human-readable failure reason (no secrets).
	 * @return array|WP_Error
	 */
	public function report_failure( string $job_id, string $reason ) {
		return $this->post(
			"jobs/{$job_id}/failure",
			array(
				'reason' => sanitize_text_field( $reason ),
			)
		);
	}

	/**
	 * Acknowledge and start a restore job.
	 *
	 * @param string $job_id ODrive restore job ID.
	 * @param array  $params Additional parameters.
	 * @return array|WP_Error
	 */
	public function restore( string $job_id, array $params = [] ) {
		return $this->post( "restores/{$job_id}/start", $params );
	}

	// -------------------------------------------------------------------------
	// HTTP helpers
	// -------------------------------------------------------------------------

	/**
	 * GET request.
	 *
	 * @param string $endpoint Relative endpoint (no leading slash).
	 * @param array  $args     Extra wp_remote_get args.
	 * @return array|WP_Error
	 */
	public function get( string $endpoint, array $args = [] ) {
		return $this->request( 'GET', $endpoint, $args );
	}

	/**
	 * POST request.
	 *
	 * @param string $endpoint Relative endpoint.
	 * @param array  $body     JSON-serialisable body.
	 * @param array  $args     Extra wp_remote_post args.
	 * @return array|WP_Error
	 */
	public function post( string $endpoint, array $body = [], array $args = [] ) {
		$args['body'] = wp_json_encode( $body );
		return $this->request( 'POST', $endpoint, $args );
	}

	/**
	 * DELETE request.
	 *
	 * @param string $endpoint Relative endpoint.
	 * @param array  $args     Extra args.
	 * @return array|WP_Error
	 */
	public function delete( string $endpoint, array $args = [] ) {
		return $this->request( 'DELETE', $endpoint, $args );
	}

	/**
	 * Core request dispatcher with retry logic.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint Relative endpoint.
	 * @param array  $args     wp_remote_request args.
	 * @return array|WP_Error Decoded response body array or WP_Error.
	 */
	private function request( string $method, string $endpoint, array $args = [] ) {
		$base_url = rtrim( $this->auth->get_odrive_url(), '/' );
		if ( '' === $base_url ) {
			return new WP_Error( 'odrive_no_url', __( 'ODrive URL is not configured.', 'odrive-connector' ) );
		}

		$token = $this->auth->get_token();
		if ( '' === $token ) {
			return new WP_Error( 'odrive_no_token', __( 'ODrive API token is not configured.', 'odrive-connector' ) );
		}

		$url = trailingslashit( $base_url ) . self::API_VERSION . '/' . ltrim( $endpoint, '/' );

		$default_headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'X-WP-Site'     => get_option( 'odrive_site_id', '' ),
			'X-Plugin-Ver'  => ODRIVE_VERSION,
		);

		$args['method']  = $method;
		$args['timeout'] = $args['timeout'] ?? self::TIMEOUT;
		$args['headers'] = array_merge( $default_headers, $args['headers'] ?? array() );

		$attempt  = 0;
		$last_err = null;

		while ( $attempt < self::MAX_RETRIES ) {
			++$attempt;

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last_err = $response;
				$this->maybe_wait( $attempt );
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );

			// Rate limiting — honour Retry-After.
			if ( 429 === $code ) {
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				sleep( max( 1, min( $retry_after, 60 ) ) );
				continue;
			}

			// Transient server errors.
			if ( $code >= 500 ) {
				$last_err = new WP_Error(
					'odrive_server_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'ODrive returned HTTP %d.', 'odrive-connector' ),
						$code
					)
				);
				$this->maybe_wait( $attempt );
				continue;
			}

			// Parse body.
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( $code >= 400 ) {
				$error_msg = isset( $data['message'] ) ? $data['message'] : wp_remote_retrieve_response_message( $response );
				return new WP_Error(
					'odrive_api_error',
					sanitize_text_field( $error_msg ),
					array( 'status' => $code )
				);
			}

			return is_array( $data ) ? $data : array( 'raw' => $body );
		}

		return $last_err ?? new WP_Error( 'odrive_request_failed', __( 'ODrive request failed after retries.', 'odrive-connector' ) );
	}

	/**
	 * Exponential back-off between retries.
	 *
	 * @param int $attempt Current attempt number (1-based).
	 * @return void
	 */
	private function maybe_wait( int $attempt ): void {
		if ( $attempt < self::MAX_RETRIES ) {
			sleep( (int) min( 2 ** ( $attempt - 1 ), 8 ) );
		}
	}
}
