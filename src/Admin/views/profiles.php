<?php
/** @var \CrmConnect\Plugin $plugin */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap crm-connect" data-crm-page="mappings">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Send forms to Freshsales', 'crm-connect' ); ?></h1>
	<button type="button" class="page-title-action" id="crm-connect-add-profile"><?php esc_html_e( '+ Connect a form', 'crm-connect' ); ?></button>

	<div id="crm-connect-profiles" class="crm-connect-profiles" aria-live="polite">
		<p class="crm-connect-loading"><?php esc_html_e( 'Loading…', 'crm-connect' ); ?></p>
	</div>

	<template id="crm-connect-profile-template">
		<div class="crm-connect-profile" data-profile-id="">
			<div class="crm-connect-profile__head">
				<div class="crm-connect-field">
					<span class="crm-connect-label"><?php esc_html_e( 'When someone submits', 'crm-connect' ); ?></span>
					<select class="crm-connect-form-select"></select>
				</div>
				<div class="crm-connect-profile__actions">
					<button type="button" class="crmc-btn crmc-btn--primary crm-connect-save"><?php esc_html_e( 'Save', 'crm-connect' ); ?></button>
					<button type="button" class="crmc-btn crmc-btn--danger crm-connect-delete"><?php esc_html_e( 'Delete', 'crm-connect' ); ?></button>
				</div>
			</div>
			<div class="crm-connect-destinations"></div>
			<button type="button" class="crmc-btn crmc-btn--ghost crm-connect-add-destination"><?php esc_html_e( '+ Add object', 'crm-connect' ); ?></button>
			<p class="crm-connect-profile__status" role="status"></p>
		</div>
	</template>
</div>
