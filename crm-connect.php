<?php
/**
 * Plugin Name: CRM Connect
 * Plugin URI:  https://github.com/nmbrthirteen/crm-connect
 * Description: Captures website form submissions - every field, UTM and trackable - and reliably forwards them server-side to a CRM via configurable per-form mapping.
 * Version:     0.4.2
 * Author:      Nika Siradze
 * Author URI:  https://nikusha.com
 * Text Domain: crm-connect
 * Requires PHP: 8.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'CRM_CONNECT_VERSION', '0.4.2' );
define( 'CRM_CONNECT_FILE', __FILE__ );
define( 'CRM_CONNECT_PATH', plugin_dir_path( __FILE__ ) );
define( 'CRM_CONNECT_URL', plugin_dir_url( __FILE__ ) );

$crm_connect_composer = CRM_CONNECT_PATH . 'vendor/autoload.php';
if ( is_readable( $crm_connect_composer ) ) {
	require $crm_connect_composer;
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'CrmConnect\\';
			$len    = strlen( $prefix );
			if ( strncmp( $class, $prefix, $len ) !== 0 ) {
				return;
			}
			$relative = substr( $class, $len );
			$file     = CRM_CONNECT_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	);
}

$crm_connect_puc = CRM_CONNECT_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
if ( is_readable( $crm_connect_puc ) ) {
	require $crm_connect_puc;

	$crm_connect_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/nmbrthirteen/crm-connect/',
		CRM_CONNECT_FILE,
		'crm-connect'
	);
	$crm_connect_updater->setBranch( 'main' );
	$crm_connect_updater->getVcsApi()->enableReleaseAssets();
}

register_activation_hook( __FILE__, [ CrmConnect\Database\Schema::class, 'install' ] );
register_deactivation_hook( __FILE__, [ CrmConnect\Plugin::class, 'deactivate' ] );

add_action(
	'init',
	static function () {
		if ( PHP_VERSION_ID < 80000 ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'CRM Connect requires PHP 8.0 or newer.', 'crm-connect' ) .
						'</p></div>';
				}
			);
			return;
		}
		try {
			CrmConnect\Plugin::instance()->boot();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[CRM Connect] boot failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		}
	}
);
