<?php

namespace CrmConnect\Capture;

use CrmConnect\Mapping\ProfileRepository;
use CrmConnect\Queue\QueueStore;

defined( 'ABSPATH' ) || exit;

final class CaptureService {

	public function __construct(
		private QueueStore $queue,
		private ProfileRepository $profiles,
		private SourceRegistry $sources
	) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_attribution_script' ] );

		foreach ( $this->sources->all() as $source ) {
			$source->register( fn ( Submission $submission ) => $this->handle( $submission ) );
		}
	}

	public function enqueue_attribution_script(): void {
		if ( ! apply_filters( 'crm_connect_attribution_enabled', true ) ) {
			return;
		}
		$path = CRM_CONNECT_PATH . 'assets/js/attribution.js';
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : CRM_CONNECT_VERSION;

		wp_enqueue_script(
			'crm-connect-attribution',
			CRM_CONNECT_URL . 'assets/js/attribution.js',
			[],
			$ver,
			true
		);
		wp_localize_script(
			'crm-connect-attribution',
			'CrmConnectAttr',
			[
				'cookie' => Attribution::COOKIE,
				'days'   => (int) apply_filters( 'crm_connect_attribution_lifetime_days', 90 ),
			]
		);
	}

	private function handle( Submission $submission ): void {
		if ( ! $this->profiles->has_for_form( $submission->form_id, $submission->form_name ) ) {
			return;
		}
		$this->queue->enqueue( $submission );
		do_action( 'crm_connect_dispatch_queue' );
	}
}
