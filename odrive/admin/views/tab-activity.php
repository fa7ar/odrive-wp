<?php
/**
 * Activity log tab view.
 *
 * @package ODriveConnector
 * @var ODrive_Admin $odrive_admin Admin instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$log     = ODrive_Plugin::instance()->activity_log;
$paged   = isset( $_GET['log_page'] ) ? max( 1, (int) $_GET['log_page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
$result  = $log->get_logs( array( 'paged' => $paged, 'per_page' => 25 ) );
$rows    = $result['rows'];
$total   = $result['total'];
$pages   = (int) ceil( $total / 25 );
?>
<div class="odrive-section">

	<div class="odrive-section-header">
		<h2><?php esc_html_e( 'Activity Log', 'odrive-connector' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: %d: total log entries */
				esc_html__( '%d entries total.', 'odrive-connector' ),
				(int) $total
			);
			?>
		</p>
	</div>

	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'No activity recorded yet.', 'odrive-connector' ); ?></p>
	<?php else : ?>
		<table class="widefat odrive-activity-table" role="grid">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'odrive-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event', 'odrive-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'odrive-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'User', 'odrive-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'IP', 'odrive-connector' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td>
						<?php
						echo esc_html(
							wp_date(
								get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
								strtotime( $row->timestamp )
							)
						);
						?>
					</td>
					<td><code><?php echo esc_html( $row->event_type ); ?></code></td>
					<td><?php echo esc_html( $row->message ); ?></td>
					<td>
						<?php
						$user = $row->user_id ? get_userdata( (int) $row->user_id ) : null;
						echo $user ? esc_html( $user->user_login ) : esc_html__( 'System', 'odrive-connector' );
						?>
					</td>
					<td><?php echo esc_html( $row->ip_address ?: '—' ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
		<div class="odrive-pagination tablenav">
			<div class="tablenav-pages">
				<?php
				$page_url = admin_url( 'options-general.php?page=odrive-connector&tab=activity' );
				if ( $paged > 1 ) {
					echo '<a class="button" href="' . esc_url( $page_url . '&log_page=' . ( $paged - 1 ) ) . '">&laquo; ' . esc_html__( 'Previous', 'odrive-connector' ) . '</a> ';
				}
				echo '<span class="paging-input">';
				printf(
					/* translators: 1: current page, 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'odrive-connector' ),
					(int) $paged,
					(int) $pages
				);
				echo '</span>';
				if ( $paged < $pages ) {
					echo ' <a class="button" href="' . esc_url( $page_url . '&log_page=' . ( $paged + 1 ) ) . '">' . esc_html__( 'Next', 'odrive-connector' ) . ' &raquo;</a>';
				}
				?>
			</div>
		</div>
		<?php endif; ?>

	<?php endif; ?>

</div>
