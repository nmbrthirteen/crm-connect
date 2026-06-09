<?php
/**
 * @var object[] $rows
 * @var int      $total
 * @var array    $counts
 * @var string   $status
 * @var int      $paged
 * @var int      $per_page
 * @var bool     $paused
 * @var array    $events
 */

defined( 'ABSPATH' ) || exit;

use CrmConnect\Support\Json;
use CrmConnect\Support\FieldDiff;

$base   = admin_url( 'admin.php?page=crm-connect-submissions' );
$post   = admin_url( 'admin-post.php' );
$notice = isset( $_GET['crmc_notice'] ) ? sanitize_key( wp_unslash( $_GET['crmc_notice'] ) ) : '';

$labels = [
	'queued'      => __( 'Queued', 'crm-connect' ),
	'sending'     => __( 'Sending', 'crm-connect' ),
	'sent'        => __( 'Sent', 'crm-connect' ),
	'failed'      => __( 'Retrying', 'crm-connect' ),
	'dead_letter' => __( 'Failed', 'crm-connect' ),
];

$pages = (int) ceil( $total / max( 1, $per_page ) );
?>
<div class="wrap crm-connect" data-crm-page="submissions">
	<h1><?php esc_html_e( 'Submissions', 'crm-connect' ); ?></h1>

	<?php if ( $notice === 'retried' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Re-queued for delivery.', 'crm-connect' ); ?></p></div>
	<?php elseif ( $notice === 'resumed' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Delivery resumed.', 'crm-connect' ); ?></p></div>
	<?php elseif ( $notice === 'log_cleared' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'System log cleared.', 'crm-connect' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $paused ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'Delivery is paused after repeated failures.', 'crm-connect' ); ?>
				<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( $post . '?action=crm_connect_resume', 'crm_connect_resume' ) ); ?>"><?php esc_html_e( 'Resume', 'crm-connect' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<ul class="subsubsub">
		<?php
		$filters = array_merge( [ '' => __( 'All', 'crm-connect' ) ], $labels );
		$last    = array_key_last( $filters );
		foreach ( $filters as $key => $label ) :
			$count = $key === '' ? array_sum( $counts ) : (int) ( $counts[ $key ] ?? 0 );
			$url   = $key === '' ? $base : add_query_arg( 'status', $key, $base );
			?>
			<li>
				<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>">
					<?php echo esc_html( $label ); ?> <span class="count">(<?php echo (int) $count; ?>)</span>
				</a><?php echo $key === $last ? '' : ' |'; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( (int) ( $counts['dead_letter'] ?? 0 ) > 0 || (int) ( $counts['failed'] ?? 0 ) > 0 ) : ?>
		<a class="button" href="<?php echo esc_url( wp_nonce_url( $post . '?action=crm_connect_retry_all', 'crm_connect_retry_all' ) ); ?>"><?php esc_html_e( 'Retry all failed', 'crm-connect' ); ?></a>
	<?php endif; ?>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:60px;"><?php esc_html_e( 'ID', 'crm-connect' ); ?></th>
				<th><?php esc_html_e( 'Form', 'crm-connect' ); ?></th>
				<th style="width:90px;"><?php esc_html_e( 'Status', 'crm-connect' ); ?></th>
				<th style="width:70px;"><?php esc_html_e( 'Tries', 'crm-connect' ); ?></th>
				<th style="width:160px;"><?php esc_html_e( 'Created', 'crm-connect' ); ?></th>
				<th style="width:110px;"><?php esc_html_e( 'Data', 'crm-connect' ); ?></th>
				<th style="width:90px;"></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $rows ) : ?>
			<tr class="crm-connect-emptyrow"><td colspan="7"><?php esc_html_e( 'No submissions yet. They’ll appear here the moment a mapped form is filled out.', 'crm-connect' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $rows as $row ) : ?>
			<tr>
				<td><?php echo (int) $row->id; ?></td>
				<td>
					<?php echo esc_html( $row->form_name !== '' ? $row->form_name : $row->form_id ); ?>
					<?php if ( $row->last_error ) : ?>
						<div class="crm-connect-error"><?php echo esc_html( $row->last_error ); ?></div>
					<?php endif; ?>
					<?php
					$req     = json_decode( (string) $row->crm_request, true );
					$res     = json_decode( (string) $row->crm_response, true );
					$dropped = ( is_array( $req ) && is_array( $res ) ) ? FieldDiff::dropped( $req, $res ) : [];
					?>
					<?php if ( $dropped ) : ?>
						<div class="crm-connect-warn">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: field list */
									__( '⚠ Not stored by Freshsales: %s', 'crm-connect' ),
									implode( ', ', $dropped )
								)
							);
							?>
						</div>
					<?php endif; ?>
				</td>
				<td><span class="crm-connect-badge crm-connect-badge--<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $labels[ $row->status ] ?? $row->status ); ?></span></td>
				<td><?php echo (int) $row->attempts; ?></td>
				<td><?php echo esc_html( get_date_from_gmt( $row->created_at, 'Y-m-d H:i' ) ); ?></td>
				<td><button type="button" class="button-link crm-connect-view"><?php esc_html_e( 'View data', 'crm-connect' ); ?></button></td>
				<td>
					<?php if ( in_array( $row->status, [ 'failed', 'dead_letter', 'sent' ], true ) ) : ?>
						<a class="crmc-btn crmc-btn--ghost crmc-btn--sm" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'crm_connect_retry', 'id' => (int) $row->id ], $post ), 'crm_connect_retry_' . (int) $row->id ) ); ?>"><?php esc_html_e( 'Retry', 'crm-connect' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
			<tr class="crm-connect-detailrow" hidden>
				<td colspan="7">
					<div class="crm-connect-detail__cols">
						<div class="crm-connect-detail__col">
							<h4><?php esc_html_e( 'Captured', 'crm-connect' ); ?></h4>
							<pre class="crm-connect-pre" data-copy="captured"><?php echo esc_html( Json::pretty( $row->payload ) ); ?></pre>
						</div>
						<div class="crm-connect-detail__col">
							<h4><?php esc_html_e( 'Sent to Freshsales', 'crm-connect' ); ?></h4>
							<pre class="crm-connect-pre" data-copy="sent"><?php echo esc_html( $row->crm_request ? Json::pretty( $row->crm_request ) : '-' ); ?></pre>
						</div>
						<div class="crm-connect-detail__col">
							<h4><?php esc_html_e( 'Freshsales response', 'crm-connect' ); ?></h4>
							<pre class="crm-connect-pre" data-copy="response"><?php echo esc_html( $row->crm_response ? Json::pretty( $row->crm_response ) : '-' ); ?></pre>
						</div>
					</div>
					<button type="button" class="crmc-btn crmc-btn--ghost crmc-btn--sm crm-connect-copy" data-id="<?php echo (int) $row->id; ?>"><?php esc_html_e( 'Copy all (for debugging)', 'crm-connect' ); ?></button>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					[
						'base'    => add_query_arg( 'paged', '%#%', $status ? add_query_arg( 'status', $status, $base ) : $base ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					]
				)
			);
			?>
		</div></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'System log', 'crm-connect' ); ?></h2>
	<?php if ( $events ) : ?>
		<a class="button" href="<?php echo esc_url( wp_nonce_url( $post . '?action=crm_connect_clear_log', 'crm_connect_clear_log' ) ); ?>"><?php esc_html_e( 'Clear log', 'crm-connect' ); ?></a>
		<table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
			<thead>
				<tr>
					<th style="width:160px;"><?php esc_html_e( 'When', 'crm-connect' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Level', 'crm-connect' ); ?></th>
					<th><?php esc_html_e( 'Message', 'crm-connect' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $events as $event ) : ?>
				<tr>
					<td><?php echo esc_html( get_date_from_gmt( (string) ( $event['time'] ?? '' ), 'Y-m-d H:i' ) ); ?></td>
					<td><span class="crm-connect-badge crm-connect-badge--<?php echo esc_attr( $event['level'] === 'error' ? 'dead_letter' : 'failed' ); ?>"><?php echo esc_html( $event['level'] ?? '' ); ?></span></td>
					<td><?php echo esc_html( $event['message'] ?? '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No system events logged. Capture failures, auto-pause and permanent delivery failures appear here.', 'crm-connect' ); ?></p>
	<?php endif; ?>
</div>
