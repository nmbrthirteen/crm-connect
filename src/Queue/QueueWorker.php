<?php

namespace CrmConnect\Queue;

use CrmConnect\Crm\Exception\RateLimitException;
use CrmConnect\Crm\ProviderFactory;
use CrmConnect\Mapping\FieldMapper;
use CrmConnect\Mapping\ProfileRepository;
use CrmConnect\Settings;
use CrmConnect\Support\EventLog;
use CrmConnect\Support\FieldDiff;

defined( 'ABSPATH' ) || exit;

final class QueueWorker {

	private const HOOK         = 'crm_connect_process_queue';
	private const BATCH        = 20;
	private const MAX_ATTEMPTS = 6;
	private const PAUSE_KEY    = 'crm_connect_paused';

	public function __construct(
		private QueueStore $queue,
		private ProfileRepository $profiles,
		private ProviderFactory $providers,
		private Settings $settings
	) {}

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'crm_connect_dispatch_queue', [ $this, 'nudge' ] );
		add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
		add_action( 'wp_ajax_nopriv_crm_connect_run', [ $this, 'ajax_run' ] );
		add_action( 'wp_ajax_crm_connect_run', [ $this, 'ajax_run' ] );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'crm_connect_minute', self::HOOK );
		}
	}

	public function add_cron_interval( array $schedules ): array {
		$schedules['crm_connect_minute'] = [
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (CRM Connect)', 'crm-connect' ),
		];
		return $schedules;
	}

	public function nudge(): void {
		if ( get_transient( 'crm_connect_nudge_lock' ) ) {
			return;
		}
		set_transient( 'crm_connect_nudge_lock', 1, 5 );

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			[
				'blocking' => false,
				'timeout'  => 0.01,
				'body'     => [ 'action' => 'crm_connect_run', 'token' => $this->run_token() ],
			]
		);
	}

	public function ajax_run(): void {
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! hash_equals( $this->run_token(), $token ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}
		$this->run();
		wp_die( 'ok', '', [ 'response' => 200 ] );
	}

	private function run_token(): string {
		$token = get_option( 'crm_connect_run_token' );
		if ( ! $token ) {
			$token = wp_generate_password( 32, false );
			update_option( 'crm_connect_run_token', $token, false );
		}
		return (string) $token;
	}

	public function run( $context = '' ): void {
		unset( $context );
		if ( $this->is_paused() ) {
			return;
		}

		$provider = $this->providers->get();
		$mapper   = new FieldMapper( $provider );
		$items    = $this->queue->claim_due( self::BATCH );

		foreach ( $items as $i => $item ) {
			$pause = $this->process( $item, $provider, $mapper );
			if ( $pause !== null ) {
				$rest = array_slice( $items, $i + 1 );
				$this->queue->release( array_map( static fn ( $it ) => $it->id, $rest ), $pause );
				break;
			}
		}

		$this->maybe_autopause();
	}

	private function process( QueueItem $item, $provider, FieldMapper $mapper ): ?int {
		$attempts = $item->attempts + 1;
		$plans    = [];
		foreach ( $this->profiles->for_form( $item->submission->form_id, $item->submission->form_name ) as $profile ) {
			foreach ( $profile->destinations as $destination ) {
				$plans[] = $destination;
			}
		}

		$done = $item->completed;

		try {
			$request  = [];
			$response = [];
			$choices  = [];

			for ( $i = $done; $i < count( $plans ); $i++ ) {
				$plan    = $mapper->build( $plans[ $i ], $item->submission );
				$choices = array_merge( $choices, $plan->choices );
				$result  = $provider->upsert_record( $plan->object, $plan->data, $plan->unique );

				$request[ $plan->object ]  = $result->request ?: $plan->data;
				$response[ $plan->object ] = $result->response;
				$done                      = $i + 1;
			}

			$this->queue->mark_sent( $item->id, $request, $response );

			$dropped = FieldDiff::dropped( $request, $response );
			if ( $dropped ) {
				EventLog::warning( $this->dropped_message( $item->id, $dropped, $choices ) );
			}
			return null;
		} catch ( RateLimitException $e ) {
			$delay = max( $this->backoff( $attempts ), $e->retry_after );
			$this->fail( $item, $attempts, $e, $delay, $done );
			return $delay;
		} catch ( \Throwable $e ) {
			$this->fail( $item, $attempts, $e, null, $done );
			return null;
		}
	}

	/**
	 * Freshsales silently discards a dropdown value that isn't already a choice on the field,
	 * and its API exposes field choices as read-only. So when a list value doesn't land, tell
	 * the admin exactly which choices to add in Freshsales.
	 *
	 * @param array<string,string[]> $choices crm field => the form's list options
	 */
	private function dropped_message( int $id, array $dropped, array $choices ): string {
		$parts = [];
		foreach ( $dropped as $field ) {
			$parts[] = ! empty( $choices[ $field ] )
				? sprintf( '%s (add these choices in Freshsales: %s)', $field, implode( ', ', $choices[ $field ] ) )
				: $field;
		}

		return sprintf(
			/* translators: 1: queue id, 2: field list, each with the choices to add */
			__( 'Submission #%1$d delivered, but Freshsales did not store: %2$s. For dropdowns, add the listed choices to the field in Freshsales (Admin Settings) or switch it to a Text field.', 'crm-connect' ),
			$id,
			implode( '; ', $parts )
		);
	}

	private function fail( QueueItem $item, int $attempts, \Throwable $e, ?int $delay = null, int $completed = 0 ): void {
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$this->queue->mark_dead_letter( $item->id, $attempts, $e->getMessage(), $completed );
			do_action( 'crm_connect_dead_letter', $item, $e );
		} else {
			$this->queue->mark_retry( $item->id, $attempts, $e->getMessage(), $this->future( $delay ?? $this->backoff( $attempts ) ), $completed );
		}
	}

	private function backoff( int $attempts ): int {
		return (int) min( HOUR_IN_SECONDS, 30 * ( 2 ** ( $attempts - 1 ) ) );
	}

	private function future( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', time() + max( 1, $seconds ) );
	}

	private function maybe_autopause(): void {
		$threshold = $this->settings->autopause_threshold();
		if ( $threshold > 0 && $this->queue->consecutive_dead_letters( $threshold ) >= $threshold ) {
			set_transient( self::PAUSE_KEY, 1, DAY_IN_SECONDS );
			do_action( 'crm_connect_worker_paused', $threshold );
		}
	}

	private function is_paused(): bool {
		return (bool) get_transient( self::PAUSE_KEY );
	}

	public static function is_worker_paused(): bool {
		return (bool) get_transient( self::PAUSE_KEY );
	}

	public static function resume(): void {
		delete_transient( self::PAUSE_KEY );
	}
}
