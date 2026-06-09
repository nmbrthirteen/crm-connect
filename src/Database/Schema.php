<?php

namespace CrmConnect\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const TABLE = 'crm_connect_queue';

	public const STATUS_QUEUED      = 'queued';
	public const STATUS_SENDING     = 'sending';
	public const STATUS_SENT        = 'sent';
	public const STATUS_FAILED      = 'failed';
	public const STATUS_DEAD_LETTER = 'dead_letter';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id varchar(64) NOT NULL DEFAULT '',
			form_name varchar(191) NOT NULL DEFAULT '',
			entry_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			payload longtext NOT NULL,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			completed smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error text DEFAULT NULL,
			crm_request longtext DEFAULT NULL,
			crm_response longtext DEFAULT NULL,
			claim_token varchar(36) DEFAULT NULL,
			next_attempt_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY entry_id (entry_id),
			KEY claim_token (claim_token),
			KEY next_attempt_at (next_attempt_at)
		) {$collate};";

		dbDelta( $sql );
		update_option( 'crm_connect_db_version', CRM_CONNECT_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'crm_connect_db_version' ) !== CRM_CONNECT_VERSION ) {
			self::install();
		}
	}

	public static function uninstall(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		delete_option( 'crm_connect_db_version' );
		delete_option( 'crm_connect_settings' );
		delete_option( 'crm_connect_profiles' );
		delete_option( 'crm_connect_events' );
		delete_option( 'crm_connect_run_token' );
	}
}
