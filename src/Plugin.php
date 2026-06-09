<?php

namespace CrmConnect;

use CrmConnect\Admin\Admin;
use CrmConnect\Admin\RestController;
use CrmConnect\Capture\CaptureService;
use CrmConnect\Capture\SourceRegistry;
use CrmConnect\Crm\ProviderFactory;
use CrmConnect\Maintenance\RetentionCron;
use CrmConnect\Mapping\ProfileRepository;
use CrmConnect\Notifications\Alerter;
use CrmConnect\Queue\QueueStore;
use CrmConnect\Queue\QueueWorker;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private Settings $settings;
	private QueueStore $queue;
	private ProfileRepository $profiles;
	private ProviderFactory $providers;
	private SourceRegistry $sources;

	public static function instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings  = new Settings();
		$this->queue     = new QueueStore();
		$this->profiles  = new ProfileRepository();
		$this->providers = new ProviderFactory( $this->settings );
		$this->sources   = new SourceRegistry();
	}

	public function boot(): void {
		load_plugin_textdomain( 'crm-connect', false, dirname( plugin_basename( CRM_CONNECT_FILE ) ) . '/languages' );

		\CrmConnect\Database\Schema::maybe_upgrade();

		( new CaptureService( $this->queue, $this->profiles, $this->sources ) )->register();
		( new QueueWorker( $this->queue, $this->profiles, $this->providers, $this->settings ) )->register();
		( new Alerter( $this->settings ) )->register();
		( new RetentionCron( $this->queue, $this->settings ) )->register();
		( new RestController( $this ) )->register();

		if ( is_admin() ) {
			( new Admin( $this ) )->register();
		}

		do_action( 'crm_connect_booted', $this );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'crm_connect_process_queue' );
		wp_clear_scheduled_hook( 'crm_connect_retention' );
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function queue(): QueueStore {
		return $this->queue;
	}

	public function profiles(): ProfileRepository {
		return $this->profiles;
	}

	public function providers(): ProviderFactory {
		return $this->providers;
	}

	public function sources(): SourceRegistry {
		return $this->sources;
	}
}
