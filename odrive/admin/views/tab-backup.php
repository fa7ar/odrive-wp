<?php
/**
 * Backup tab view.
 *
 * @package ODriveConnector
 * @var ODrive_Admin $odrive_admin Admin instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$data      = $odrive_admin->get_backup_data();
$connected = (bool) get_option( 'odrive_connected', false );

$type_labels = array(
	'database' => __( 'Database',    'odrive-connector' ),
	'uploads'  => __( 'Uploads',     'odrive-connector' ),
	'themes'   => __( 'Themes',      'odrive-connector' ),
	'plugins'  => __( 'Plugins',     'odrive-connector' ),
	'full'     => __( 'Full Site',   'odrive-connector' ),
);
?>
<div class="odrive-section">

	<?php if ( ! $connected ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'Connect to ODrive first before running backups.', 'odrive-connector' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $data['last_backup_time'] ) : ?>
		<p class="odrive-last-backup">
			<?php
			printf(
				/* translators: 1: date/time, 2: status */
				esc_html__( 'Last backup: %1$s &mdash; %2$s', 'odrive-connector' ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $data['last_backup_time'] ) ) ),
				'<strong class="odrive-backup-status-' . esc_attr( $data['last_backup_status'] ) . '">' . esc_html( ucfirst( (string) $data['last_backup_status'] ) ) . '</strong>'
			);
			?>
		</p>
	<?php endif; ?>

	<table class="widefat odrive-backup-table" role="grid">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Backup Type', 'odrive-connector' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Action', 'odrive-connector' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Progress', 'odrive-connector' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $type_labels as $type => $label ) : ?>
			<tr data-backup-type="<?php echo esc_attr( $type ); ?>">
				<td><strong><?php echo esc_html( $label ); ?></strong></td>
				<td>
					<button type="button"
					        class="button button-secondary odrive-btn-backup"
					        data-type="<?php echo esc_attr( $type ); ?>"
					        <?php echo $connected ? '' : 'disabled'; ?>>
						<?php esc_html_e( 'Run Now', 'odrive-connector' ); ?>
					</button>
				</td>
				<td>
					<div class="odrive-progress-wrap" id="odrive-progress-<?php echo esc_attr( $type ); ?>" style="display:none;">
						<div class="odrive-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
							<div class="odrive-progress-fill"></div>
						</div>
						<span class="odrive-progress-msg"></span>
					</div>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<div id="odrive-backup-result" class="odrive-ajax-result" aria-live="polite"></div>

</div>
