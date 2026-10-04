<?php
/**
 * Settings tab view.
 *
 * @package ODriveConnector
 * @var ODrive_Admin $odrive_admin Admin instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$schedule = get_option( 'odrive_backup_schedule', 'none' );
?>
<div class="odrive-section">

	<form method="post" action="">
		<?php wp_nonce_field( 'odrive_settings_save', 'odrive_settings_nonce' ); ?>
		<input type="hidden" name="odrive_settings_submit" value="1" />

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="odrive-backup-schedule">
						<?php esc_html_e( 'Automatic Backup Schedule', 'odrive-connector' ); ?>
					</label>
				</th>
				<td>
					<select id="odrive-backup-schedule" name="odrive_backup_schedule">
						<option value="none"   <?php selected( $schedule, 'none' ); ?>>
							<?php esc_html_e( 'None (manual only)',  'odrive-connector' ); ?>
						</option>
						<option value="daily"  <?php selected( $schedule, 'daily' ); ?>>
							<?php esc_html_e( 'Daily',  'odrive-connector' ); ?>
						</option>
						<option value="weekly" <?php selected( $schedule, 'weekly' ); ?>>
							<?php esc_html_e( 'Weekly', 'odrive-connector' ); ?>
						</option>
					</select>
					<p class="description">
						<?php esc_html_e( 'ODrive can also initiate scheduled backups directly. This setting controls WordPress-side scheduling.', 'odrive-connector' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<div class="odrive-section odrive-section-info">
			<h3><?php esc_html_e( 'Plugin Information', 'odrive-connector' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Plugin Version', 'odrive-connector' ); ?></th>
					<td><?php echo esc_html( ODRIVE_VERSION ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Site ID', 'odrive-connector' ); ?></th>
					<td><code><?php echo esc_html( get_option( 'odrive_site_id', __( 'Not generated yet', 'odrive-connector' ) ) ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'REST Endpoint', 'odrive-connector' ); ?></th>
					<td><code><?php echo esc_html( rest_url( 'odrive/v1/' ) ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'PHP Version', 'odrive-connector' ); ?></th>
					<td><?php echo esc_html( PHP_VERSION ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'WordPress Version', 'odrive-connector' ); ?></th>
					<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'Save Settings', 'odrive-connector' ) ); ?>
	</form>

</div>
