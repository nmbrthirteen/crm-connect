<?php

namespace CrmConnect\Admin;

use CrmConnect\Capture\Trackables;
use CrmConnect\Plugin;
use CrmConnect\Queue\QueueWorker;
use CrmConnect\Support\EventLog;

defined( 'ABSPATH' ) || exit;

final class Admin {

	private const SLUG = 'crm-connect';

	/** @var string[] page hook suffixes for our screens */
	private array $hooks = [];

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_crm_connect_save_settings', [ $this, 'handle_save_settings' ] );
		add_action( 'admin_post_crm_connect_retry', [ $this, 'handle_retry' ] );
		add_action( 'admin_post_crm_connect_retry_all', [ $this, 'handle_retry_all' ] );
		add_action( 'admin_post_crm_connect_resume', [ $this, 'handle_resume' ] );
		add_action( 'admin_post_crm_connect_clear_log', [ $this, 'handle_clear_log' ] );
	}

	public function menu(): void {
		$brand = $this->plugin->settings()->brand_name();

		$this->hooks[] = add_menu_page(
			$brand,
			$brand,
			'manage_options',
			self::SLUG,
			[ $this, 'render_settings' ],
			'dashicons-randomize',
			58
		);

		$this->hooks[] = add_submenu_page(
			self::SLUG,
			__( 'Settings', 'crm-connect' ),
			__( 'Settings', 'crm-connect' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render_settings' ]
		);

		$this->hooks[] = add_submenu_page(
			self::SLUG,
			__( 'Mappings', 'crm-connect' ),
			__( 'Mappings', 'crm-connect' ),
			'manage_options',
			self::SLUG . '-mappings',
			[ $this, 'render_mappings' ]
		);

		$this->hooks[] = add_submenu_page(
			self::SLUG,
			__( 'Submissions', 'crm-connect' ),
			__( 'Submissions', 'crm-connect' ),
			'manage_options',
			self::SLUG . '-submissions',
			[ $this, 'render_submissions' ]
		);
	}

	public function assets( string $hook ): void {
		if ( ! in_array( $hook, $this->hooks, true ) && strpos( $hook, self::SLUG ) === false ) {
			return;
		}

		wp_enqueue_style( 'crm-connect-admin', CRM_CONNECT_URL . 'assets/css/admin.css', [], $this->asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'crm-connect-admin', CRM_CONNECT_URL . 'assets/js/admin.js', [], $this->asset_version( 'assets/js/admin.js' ), true );

		$settings = $this->plugin->settings();

		wp_localize_script(
			'crm-connect-admin',
			'CrmConnectAdmin',
			[
				'root'        => esc_url_raw( rest_url( 'crm-connect/v1/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'configured'  => $settings->bundle_alias() !== '' && $settings->api_key() !== '',
				'settingsUrl' => admin_url( 'admin.php?page=' . self::SLUG ),
				'crmUrl'      => $settings->bundle_alias() !== '' ? 'https://' . $settings->bundle_alias() . '/crm/sales/settings' : '',
				'i18n'        => [
					'saved'         => __( 'Saved.', 'crm-connect' ),
					'saving'        => __( 'Saving…', 'crm-connect' ),
					'saveFailed'    => __( 'Could not save.', 'crm-connect' ),
					'testing'       => __( 'Testing…', 'crm-connect' ),
					'connected'     => __( 'Connection OK.', 'crm-connect' ),
					'confirmDelete' => __( 'Delete this mapping?', 'crm-connect' ),
					'loadingFields' => __( 'Loading fields…', 'crm-connect' ),
					'notConnected'  => __( 'Connect your Freshsales account in Settings before mapping fields.', 'crm-connect' ),
					'goToSettings'  => __( 'Open Settings', 'crm-connect' ),
					'pickFormFirst' => __( 'Select a form first.', 'crm-connect' ),
					'noFields'      => __( 'No fields found for this form. You can still map trackables (UTMs, etc.).', 'crm-connect' ),
					'badResponse'   => __( 'The server returned an unexpected response. The REST API may be blocked by a security plugin, or the CRM credentials may be wrong.', 'crm-connect' ),
				],
			]
		);
	}

	public function render_settings(): void {
		$this->render( 'settings', [ 'plugin' => $this->plugin ] );
	}

	public function render_mappings(): void {
		$this->render( 'profiles', [ 'plugin' => $this->plugin ] );
	}

	public function render_submissions(): void {
		$queue    = $this->plugin->queue();
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per_page = 25;

		$this->render(
			'log',
			[
				'rows'     => $queue->list_rows( $status ?: null, $per_page, ( $paged - 1 ) * $per_page ),
				'total'    => $queue->total( $status ?: null ),
				'counts'   => $queue->status_counts(),
				'status'   => $status,
				'paged'    => $paged,
				'per_page' => $per_page,
				'paused'   => QueueWorker::is_worker_paused(),
				'events'   => EventLog::all(),
			]
		);
	}

	public function handle_save_settings(): void {
		$this->guard( 'crm_connect_save_settings' );

		$settings = $this->plugin->settings();
		$settings->update(
			[
				'bundle_alias'        => $this->normalize_alias( sanitize_text_field( wp_unslash( $_POST['bundle_alias'] ?? '' ) ) ),
				'brand_name'          => sanitize_text_field( wp_unslash( $_POST['brand_name'] ?? '' ) ),
				'alert_email'         => sanitize_email( wp_unslash( $_POST['alert_email'] ?? '' ) ),
				'slack_webhook'       => esc_url_raw( wp_unslash( $_POST['slack_webhook'] ?? '' ) ),
				'retention_days'      => max( 0, (int) ( $_POST['retention_days'] ?? 180 ) ),
				'autopause_threshold' => max( 0, (int) ( $_POST['autopause_threshold'] ?? 10 ) ),
			]
		);

		$api_key = trim( (string) wp_unslash( $_POST['api_key'] ?? '' ) );
		if ( $api_key !== '' ) {
			$settings->set_api_key( $api_key );
		}

		$this->redirect( self::SLUG, 'saved' );
	}

	public function handle_retry(): void {
		$id = (int) ( $_REQUEST['id'] ?? 0 );
		$this->guard( 'crm_connect_retry_' . $id );

		$this->plugin->queue()->requeue( $id );
		do_action( 'crm_connect_dispatch_queue' );

		$this->redirect( self::SLUG . '-submissions', 'retried' );
	}

	public function handle_retry_all(): void {
		$this->guard( 'crm_connect_retry_all' );

		$this->plugin->queue()->requeue_failed();
		do_action( 'crm_connect_dispatch_queue' );

		$this->redirect( self::SLUG . '-submissions', 'retried' );
	}

	public function handle_resume(): void {
		$this->guard( 'crm_connect_resume' );

		QueueWorker::resume();
		do_action( 'crm_connect_dispatch_queue' );

		$this->redirect( self::SLUG . '-submissions', 'resumed' );
	}

	public function handle_clear_log(): void {
		$this->guard( 'crm_connect_clear_log' );

		EventLog::clear();

		$this->redirect( self::SLUG . '-submissions', 'log_cleared' );
	}

	private function asset_version( string $relative ): string {
		$path  = CRM_CONNECT_PATH . $relative;
		$mtime = file_exists( $path ) ? filemtime( $path ) : false;
		return $mtime ? (string) $mtime : CRM_CONNECT_VERSION;
	}

	private function normalize_alias( string $alias ): string {
		return \CrmConnect\Support\Url::host( $alias );
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'crm-connect' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( $action );
	}

	private function redirect( string $page, string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'        => $page,
					'crmc_notice' => $notice,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function render( string $view, array $context = [] ): void {
		$context['trackables'] = Trackables::all();
		extract( $context, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		require CRM_CONNECT_PATH . 'src/Admin/views/' . $view . '.php';
	}
}
