/**
 * ODrive Connector — Admin JavaScript
 *
 * Handles AJAX interactions for the admin UI.
 * Uses jQuery (bundled with WordPress).
 */
/* global odriveAdmin, jQuery */
( function ( $, cfg ) {
	'use strict';

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Show a result message inside a container element.
	 *
	 * @param {jQuery} $container Target element.
	 * @param {string} message    Text to display.
	 * @param {bool}   success    true = success style, false = error style.
	 */
	function showResult( $container, message, success ) {
		var cls = success ? 'odrive-msg-success' : 'odrive-msg-error';
		$container.html(
			'<span class="odrive-msg ' + cls + '">' +
			'<span class="dashicons dashicons-' + ( success ? 'yes' : 'no' ) + '" aria-hidden="true"></span>' +
			$( '<span>' ).text( message ).html() +
			'</span>'
		);
	}

	/**
	 * Get the current URL and token values from the form fields.
	 *
	 * @returns {{ url: string, token: string }}
	 */
	function getFormValues() {
		return {
			url:   $( '#odrive-url' ).val()   || '',
			token: $( '#odrive-token' ).val() || ''
		};
	}

	// ── Connection tab ────────────────────────────────────────────────────────

	$( '#odrive-btn-test' ).on( 'click', function () {
		var $btn    = $( this );
		var $result = $( '#odrive-connection-result' );
		var vals    = getFormValues();

		if ( ! vals.url || ! vals.token ) {
			showResult( $result, 'Please enter ODrive URL and Token first.', false );
			return;
		}

		$btn.prop( 'disabled', true ).text( cfg.strings.testing );

		$.post( cfg.ajaxUrl, {
			action:       'odrive_test_connection',
			nonce:        cfg.nonce,
			odrive_url:   vals.url,
			odrive_token: vals.token
		} )
		.done( function ( res ) {
			if ( res.success ) {
				showResult( $result, res.data.message, true );
			} else {
				showResult( $result, res.data.message || cfg.strings.error, false );
			}
		} )
		.fail( function () {
			showResult( $result, cfg.strings.error, false );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( 'Test Connection' );
		} );
	} );

	$( '#odrive-btn-connect' ).on( 'click', function () {
		var $btn    = $( this );
		var $result = $( '#odrive-connection-result' );
		var vals    = getFormValues();

		if ( ! vals.url || ! vals.token ) {
			showResult( $result, 'Please enter ODrive URL and Token.', false );
			return;
		}

		$btn.prop( 'disabled', true ).text( cfg.strings.connecting );

		$.post( cfg.ajaxUrl, {
			action:       'odrive_connect',
			nonce:        cfg.nonce,
			odrive_url:   vals.url,
			odrive_token: vals.token
		} )
		.done( function ( res ) {
			if ( res.success ) {
				// Reload to show connected state.
				window.location.reload();
			} else {
				showResult( $result, res.data.message || cfg.strings.error, false );
				$btn.prop( 'disabled', false ).text( 'Connect' );
			}
		} )
		.fail( function () {
			showResult( $result, cfg.strings.error, false );
			$btn.prop( 'disabled', false ).text( 'Connect' );
		} );
	} );

	$( '#odrive-btn-disconnect' ).on( 'click', function () {
		if ( ! window.confirm( cfg.strings.confirm_disconnect ) ) {
			return;
		}

		var $btn    = $( this );
		var $result = $( '#odrive-disconnect-result' );

		$btn.prop( 'disabled', true ).text( cfg.strings.disconnecting );

		$.post( cfg.ajaxUrl, {
			action: 'odrive_disconnect',
			nonce:  cfg.nonce
		} )
		.done( function ( res ) {
			if ( res.success ) {
				window.location.reload();
			} else {
				showResult( $result, res.data.message || cfg.strings.error, false );
				$btn.prop( 'disabled', false ).text( 'Disconnect' );
			}
		} )
		.fail( function () {
			showResult( $result, cfg.strings.error, false );
			$btn.prop( 'disabled', false ).text( 'Disconnect' );
		} );
	} );

	// ── Backup tab ────────────────────────────────────────────────────────────

	/**
	 * Active polling intervals keyed by type.
	 * @type {Object<string, number>}
	 */
	var pollIntervals = {};

	/**
	 * Update the progress bar UI for a backup type.
	 *
	 * @param {string} type    Backup type.
	 * @param {number} percent 0-100.
	 * @param {string} message Status message.
	 */
	function updateProgress( type, percent, message ) {
		var $wrap = $( '#odrive-progress-' + type );
		$wrap.show();
		$wrap.find( '.odrive-progress-fill' ).css( 'width', percent + '%' );
		$wrap.find( '.odrive-progress-bar' ).attr( 'aria-valuenow', percent );
		$wrap.find( '.odrive-progress-msg' ).text( message );
	}

	/**
	 * Poll backup progress until job is done/failed.
	 *
	 * @param {string} type   Backup type (for UI).
	 * @param {string} jobId  Local job ID.
	 */
	function pollProgress( type, jobId ) {
		if ( pollIntervals[ type ] ) {
			clearInterval( pollIntervals[ type ] );
		}

		pollIntervals[ type ] = setInterval( function () {
			$.post( cfg.ajaxUrl, {
				action: 'odrive_backup_status',
				nonce:  cfg.nonce,
				job_id: jobId
			} )
			.done( function ( res ) {
				if ( ! res.success ) {
					return;
				}

				var d = res.data;
				updateProgress( type, d.percent || 0, d.message || '' );

				if ( d.status === 'complete' || d.status === 'failed' ) {
					clearInterval( pollIntervals[ type ] );
					delete pollIntervals[ type ];

					// Re-enable the button.
					$( '.odrive-btn-backup[data-type="' + type + '"]' ).prop( 'disabled', false ).text( 'Run Now' );

					var $result = $( '#odrive-backup-result' );
					if ( d.status === 'complete' ) {
						showResult( $result, cfg.strings.success + ': ' + type + ' backup complete.', true );
					} else {
						showResult( $result, ( d.message || cfg.strings.error ), false );
					}
				}
			} );
		}, 3000 );
	}

	$( '.odrive-btn-backup' ).on( 'click', function () {
		var $btn  = $( this );
		var type  = $btn.data( 'type' );

		$btn.prop( 'disabled', true ).text( 'Running…' );
		updateProgress( type, 0, cfg.strings.backupQueued );

		$.post( cfg.ajaxUrl, {
			action: 'odrive_run_backup',
			nonce:  cfg.nonce,
			type:   type
		} )
		.done( function ( res ) {
			if ( res.success ) {
				pollProgress( type, res.data.job_id );
			} else {
				showResult( $( '#odrive-backup-result' ), res.data.message || cfg.strings.error, false );
				$btn.prop( 'disabled', false ).text( 'Run Now' );
			}
		} )
		.fail( function () {
			showResult( $( '#odrive-backup-result' ), cfg.strings.error, false );
			$btn.prop( 'disabled', false ).text( 'Run Now' );
		} );
	} );

} )( jQuery, odriveAdmin );
