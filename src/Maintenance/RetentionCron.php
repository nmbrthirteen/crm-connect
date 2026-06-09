<?php

namespace CrmConnect\Maintenance;

use CrmConnect\Queue\QueueStore;
use CrmConnect\Settings;

defined( 'ABSPATH' ) || exit;

final class RetentionCron {

	public const HOOK = 'crm_connect_retention';

	public function __construct(
		private QueueStore $queue,
		private Settings $settings
	) {}

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public function run(): void {
		$days = $this->settings->retention_days();
		if ( $days > 0 ) {
			$this->queue->purge_older_than( $days );
		}
	}
}
