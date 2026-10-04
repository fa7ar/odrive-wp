<?php
/**
 * REST API Endpoints
 *
 * @package ODriveConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the narrowly-scoped /wp-json/odrive/v1/* REST endpoints.
 *
 * Every privileged request is authenticated via Bearer token + nonce replay protection.
 */
class ODrive_REST_API {

	const NAMESPACE = 'odrive/v1';

	/** Replay-protection nonce TTL in seconds. */
	const NONCE_TTL = 300; // 5 minutes.

	/** @var ODrive_Auth */
	private ODrive_Auth $auth;

	/** @var ODrive_Health */
	private ODrive_Health $health;

	/** @var ODrive_Backup */
	private ODrive_Backup $backup;

	/** @var ODrive_Restore */
	private ODrive_Restore $restore;

	/** @var ODrive_Activity_Log */
	private ODrive_Activity_Log $log;

	/**
	 * Constructor.
	 *
	 * @param ODrive_Auth         $auth    Auth instance.
	 * @param ODrive_Health       $health  Health instance.
	 * @param ODrive_Backup       $backup  Backup instance.
	 * @param ODrive_Restore      $restore Restore instance.
	 * @param ODrive_Activity_Log $log     Activity log.
	 */
	public function __construct(
		ODrive_Auth $auth,
		ODrive_Health $health,
		ODrive_Backup $backup,
		ODrive_Restore $restore,
		ODrive_Activity_Log $log
	) {
		$this->auth    = $auth;
		$this->health  = $health;
		$this->backup  = $backup;
		$this->restore = $restore;
		$this->log     = $log;
	}

	/**
	 * Register all REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/health', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_health' ),
			'permission_callback' => array( $this, 'authenticate_request' ),
		) );

		register_rest_route( self::NAMESPACE, '/backup', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_backup' ),
			'permission_callback' => array( $this, 'authenticate_request' ),
			'args'                => array(
				'type' => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => ODrive_Backup::TYPES,
					'sanitize_callback' => 'sanitize_key',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/backup/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_backup_status' ),
			'permission_callback' => array( $this, 'authenticate_request' ),
			'args'                => array(
				'job_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/restore', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_restore' ),
			'permission_callback' => array( $this, 'authenticate_request' ),
			'args'                => array(
				'job_id'        => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'type'          => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => ODrive_Restore::TYPES,
					'sanitize_callback' => 'sanitize_key',
				),
				'confirm_token' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/restore/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_restore_status' ),
			'permission_callback' => array( $this, 'authenticate_request' ),
			'args'                => array(
				'job_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
	}

	// -------------------------------------------------------------------------
	// Route handlers
	// -------------------------------------------------------------------------

	/**
	 * GET /health
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_health( WP_REST_Request $request ): WP_REST_Response {
		$this->log->log( 'rest_health', __( 'Health check requested via REST.', 'odrive-connector' ) );
		return rest_ensure_response( $this->health->get_rest_payload() );
	}

	/**
	 * POST /backup
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_backup( WP_REST_Request $request ) {
		$type  = $request->get_param( 'type' );
		$extra = (array) ( $request->get_param( 'options' ) ?? array() );

		$job_id = $this->backup->start_async( $type, $extra );

		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		$this->log->log( 'rest_backup_triggered', sprintf(
			/* translators: %s: backup type */
			__( 'Backup triggered via REST: %s', 'odrive-connector' ),
			$type
		) );

		return rest_ensure_response( array(
			'job_id'  => $job_id,
			'status'  => 'queued',
			'message' => __( 'Backup queued.', 'odrive-connector' ),
		) );
	}

	/**
	 * GET /backup/status
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_backup_status( WP_REST_Request $request ) {
		$job_id   = $request->get_param( 'job_id' );
		$progress = $this->backup->get_progress( $job_id );

		if ( null === $progress ) {
			return new WP_Error( 'odrive_not_found', __( 'Backup job not found.', 'odrive-connector' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $progress );
	}

	/**
	 * POST /restore
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_restore( WP_REST_Request $request ) {
		$job_id        = $request->get_param( 'job_id' );
		$type          = $request->get_param( 'type' );
		$confirm_token = $request->get_param( 'confirm_token' );
		$options       = (array) ( $request->get_param( 'options' ) ?? array() );

		// Store confirm token before validation in start().
		$this->restore->register_confirm_token( $job_id, $confirm_token );

		$result = $this->restore->start( $job_id, $type, $confirm_token, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array(
			'job_id'  => $job_id,
			'status'  => 'started',
			'message' => __( 'Restore started.', 'odrive-connector' ),
		) );
	}

	/**
	 * GET /restore/status
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_restore_status( WP_REST_Request $request ) {
		$job_id   = $request->get_param( 'job_id' );
		$progress = $this->restore->get_progress( $job_id );

		if ( null === $progress ) {
			return new WP_Error( 'odrive_not_found', __( 'Restore job not found.', 'odrive-connector' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $progress );
	}

	// -------------------------------------------------------------------------
	// Authentication
	// -------------------------------------------------------------------------

	/**
	 * Authenticate an incoming REST request from ODrive.
	 *
	 * Checks:
	 *  1. Authorization: Bearer <token> header matches stored token.
	 *  2. X-ODrive-Timestamp header is within ±5 min of server time.
	 *  3. X-ODrive-Nonce has not been seen in the last 5 min (replay protection).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function authenticate_request( WP_REST_Request $request ) {
		// 1. Token check.
		$auth_header = $request->get_header( 'authorization' );
		if ( ! $auth_header || 0 !== strpos( $auth_header, 'Bearer ' ) ) {
			return new WP_Error( 'odrive_unauthorized', __( 'Missing Bearer token.', 'odrive-connector' ), array( 'status' => 401 ) );
		}

		$provided_token = substr( $auth_header, 7 );
		$stored_token   = $this->auth->get_token();

		if ( '' === $stored_token || ! hash_equals( $stored_token, $provided_token ) ) {
			$this->log->log( 'rest_auth_failed', __( 'REST authentication failed: invalid token.', 'odrive-connector' ) );
			return new WP_Error( 'odrive_unauthorized', __( 'Invalid token.', 'odrive-connector' ), array( 'status' => 401 ) );
		}

		// 2. Timestamp check.
		$timestamp = (int) $request->get_header( 'x-odrive-timestamp' );
		if ( abs( time() - $timestamp ) > self::NONCE_TTL ) {
			return new WP_Error( 'odrive_unauthorized', __( 'Request timestamp out of range.', 'odrive-connector' ), array( 'status' => 401 ) );
		}

		// 3. Nonce replay protection.
		$nonce = sanitize_text_field( (string) $request->get_header( 'x-odrive-nonce' ) );
		if ( $nonce ) {
			$nonce_key = 'odrive_nonce_' . md5( $nonce );
			if ( get_transient( $nonce_key ) ) {
				return new WP_Error( 'odrive_replay', __( 'Request nonce already used.', 'odrive-connector' ), array( 'status' => 401 ) );
			}
			set_transient( $nonce_key, 1, self::NONCE_TTL );
		}

		return true;
	}
}
