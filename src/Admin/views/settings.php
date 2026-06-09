<?php
/**
 * @var \CrmConnect\Plugin $plugin
 */

defined( 'ABSPATH' ) || exit;

$settings   = $plugin->settings();
$counts     = $plugin->queue()->status_counts();
$has_key    = $settings->get( 'api_key', '' ) !== '';
$notice     = isset( $_GET['crmc_notice'] ) ? sanitize_key( wp_unslash( $_GET['crmc_notice'] ) ) : '';
?>
<div class="wrap crm-connect" data-crm-page="settings">
	<h1><?php echo esc_html( $settings->brand_name() ); ?></h1>

	<?php if ( $notice === 'saved' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'crm-connect' ); ?></p></div>
	<?php endif; ?>

	<div class="crm-connect-stats">
		<?php
		foreach (
			[
				'queued'      => __( 'Queued', 'crm-connect' ),
				'sent'        => __( 'Sent', 'crm-connect' ),
				'failed'      => __( 'Retrying', 'crm-connect' ),
				'dead_letter' => __( 'Failed', 'crm-connect' ),
			] as $key => $label
		) :
			?>
			<div class="crm-connect-stat crm-connect-stat--<?php echo esc_attr( $key ); ?>">
				<span class="crm-connect-stat__value"><?php echo (int) ( $counts[ $key ] ?? 0 ); ?></span>
				<span class="crm-connect-stat__label"><?php echo esc_html( $label ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crm-connect-card">
		<input type="hidden" name="action" value="crm_connect_save_settings">
		<?php wp_nonce_field( 'crm_connect_save_settings' ); ?>

		<h2><?php esc_html_e( 'CRM connection', 'crm-connect' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bundle_alias"><?php esc_html_e( 'Freshsales domain', 'crm-connect' ); ?></label></th>
				<td>
					<input name="bundle_alias" id="bundle_alias" type="text" class="regular-text"
						value="<?php echo esc_attr( $settings->bundle_alias() ); ?>"
						placeholder="yourcompany.myfreshworks.com">
					<p class="description"><?php esc_html_e( 'Your Freshsales web address, e.g. yourcompany.myfreshworks.com', 'crm-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="api_key"><?php esc_html_e( 'API key', 'crm-connect' ); ?></label></th>
				<td>
					<input name="api_key" id="api_key" type="password" class="regular-text" autocomplete="off"
						placeholder="<?php echo $has_key ? esc_attr__( '•••••••• (stored - leave blank to keep)', 'crm-connect' ) : ''; ?>">
					<p class="description"><?php esc_html_e( 'Find it in Freshsales under Profile Settings → API Settings. We store it encrypted.', 'crm-connect' ); ?></p>
					<button type="button" class="button" id="crm-connect-test"><?php esc_html_e( 'Test connection', 'crm-connect' ); ?></button>
					<span class="crm-connect-test-result"></span>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Branding', 'crm-connect' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="brand_name"><?php esc_html_e( 'Display name', 'crm-connect' ); ?></label></th>
				<td>
					<input name="brand_name" id="brand_name" type="text" class="regular-text"
						value="<?php echo esc_attr( $settings->get( 'brand_name', '' ) ); ?>"
						placeholder="<?php esc_attr_e( 'CRM Connect', 'crm-connect' ); ?>">
					<p class="description"><?php esc_html_e( 'Shown as the plugin name and menu label.', 'crm-connect' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Notifications', 'crm-connect' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="alert_email"><?php esc_html_e( 'Alert email', 'crm-connect' ); ?></label></th>
				<td><input name="alert_email" id="alert_email" type="email" class="regular-text" value="<?php echo esc_attr( $settings->alert_email() ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="slack_webhook"><?php esc_html_e( 'Slack webhook', 'crm-connect' ); ?></label></th>
				<td><input name="slack_webhook" id="slack_webhook" type="url" class="regular-text" value="<?php echo esc_attr( $settings->slack_webhook() ); ?>" placeholder="https://hooks.slack.com/…"></td>
			</tr>
			<tr>
				<th scope="row"><label for="autopause_threshold"><?php esc_html_e( 'Pause after this many failures', 'crm-connect' ); ?></label></th>
				<td><input name="autopause_threshold" id="autopause_threshold" type="number" min="0" class="small-text" value="<?php echo (int) $settings->autopause_threshold(); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="retention_days"><?php esc_html_e( 'Keep history for (days)', 'crm-connect' ); ?></label></th>
				<td>
					<input name="retention_days" id="retention_days" type="number" min="0" class="small-text" value="<?php echo (int) $settings->retention_days(); ?>">
					<p class="description"><?php esc_html_e( 'Older entries are removed automatically. Use 0 to keep everything.', 'crm-connect' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
