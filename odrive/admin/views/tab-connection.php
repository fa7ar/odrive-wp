<?php
/**
 * Connection tab view.
 *
 * @package ODriveConnector
 * @var ODrive_Admin $odrive_admin Admin instance (from page-main.php context).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$data      = $odrive_admin->get_connection_data();
$connected = $data['connected'];
?>
<div class="odrive-section">

	<?php if ( $connected ) : ?>
		<!-- ── Connected State ─────────────────────────────────────────────── -->
		<div class="odrive-status-banner odrive-status-connected" role="status">
			<span class="odrive-status-dot" aria-hidden="true"></span>
			<strong><?php esc_html_e( 'Connected', 'odrive-connector' ); ?></strong>
		</div>

		<table class="form-table odrive-info-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'ODrive URL', 'odrive-connector' ); ?></th>
				<td><?php echo esc_html( $data['odrive_url'] ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Token', 'odrive-connector' ); ?></th>
				<td>
					<code><?php echo esc_html( $data['masked_token'] ); ?></code>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Registration', 'odrive-connector' ); ?></th>
				<td><?php echo esc_html( ucfirst( $data['registration_status'] ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Last Health Check', 'odrive-connector' ); ?></th>
				<td>
					<?php
					if ( $data['last_health_check'] ) {
						echo esc_html(
							wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $data['last_health_check'] ) )
						);
						echo ' &mdash; <span class="odrive-health-status odrive-health-' . esc_attr( $data['health_status'] ) . '">';
						echo esc_html( ucfirst( $data['health_status'] ) );
						echo '</span>';
					} else {
						esc_html_e( 'Never', 'odrive-connector' );
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Site ID', 'odrive-connector' ); ?></th>
				<td><code><?php echo esc_html( $data['site_id'] ); ?></code></td>
			</tr>
		</table>

		<div class="odrive-action-row">
			<a href="<?php echo esc_url( $data['odrive_url'] ); ?>"
			   target="_blank"
			   rel="noopener noreferrer"
			   class="button button-primary odrive-btn-dashboard">
				<?php esc_html_e( 'Open ODrive Dashboard', 'odrive-connector' ); ?>
				<span class="dashicons dashicons-external" aria-hidden="true"></span>
			</a>

			<button type="button"
			        id="odrive-btn-disconnect"
			        class="button button-secondary odrive-btn-disconnect">
				<?php esc_html_e( 'Disconnect', 'odrive-connector' ); ?>
			</button>
		</div>

		<div id="odrive-disconnect-result" class="odrive-ajax-result" aria-live="polite"></div>

	<?php else : ?>
		<!-- ── Disconnected / Setup State ──────────────────────────────────── -->
		<div class="odrive-status-banner odrive-status-disconnected" role="status">
			<span class="odrive-status-dot" aria-hidden="true"></span>
			<strong><?php esc_html_e( 'Not Connected', 'odrive-connector' ); ?></strong>
		</div>

		<p class="description">
			<?php esc_html_e( 'Enter your ODrive URL and API token to connect this WordPress site.', 'odrive-connector' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="odrive-url"><?php esc_html_e( 'ODrive URL', 'odrive-connector' ); ?></label>
				</th>
				<td>
					<input type="url"
					       id="odrive-url"
					       name="odrive_url"
					       class="regular-text"
					       value="<?php echo esc_attr( $data['odrive_url'] ); ?>"
					       placeholder="https://odrive.yourdomain.com"
					       autocomplete="off"
					       required />
					<p class="description"><?php esc_html_e( 'Base URL of your ODrive installation.', 'odrive-connector' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="odrive-token"><?php esc_html_e( 'API Token', 'odrive-connector' ); ?></label>
				</th>
				<td>
					<input type="password"
					       id="odrive-token"
					       name="odrive_token"
					       class="regular-text"
					       value=""
					       autocomplete="new-password"
					       required />
					<?php if ( $data['has_token'] ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: masked token */
								esc_html__( 'Token stored: %s', 'odrive-connector' ),
								'<code>' . esc_html( $data['masked_token'] ) . '</code>'
							);
							?>
						</p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Scoped API token from your ODrive dashboard.', 'odrive-connector' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<div class="odrive-action-row">
			<button type="button" id="odrive-btn-test" class="button button-secondary">
				<?php esc_html_e( 'Test Connection', 'odrive-connector' ); ?>
			</button>
			<button type="button" id="odrive-btn-connect" class="button button-primary">
				<?php esc_html_e( 'Connect', 'odrive-connector' ); ?>
			</button>
		</div>

		<div id="odrive-connection-result" class="odrive-ajax-result" aria-live="polite"></div>

	<?php endif; ?>

</div>
