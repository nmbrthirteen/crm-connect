<?php

namespace CrmConnect\Notifications;

use CrmConnect\Settings;
use CrmConnect\Support\EventLog;

defined( 'ABSPATH' ) || exit;

final class Alerter {

	private const THROTTLE_SECONDS = 5 * MINUTE_IN_SECONDS;

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'crm_connect_dead_letter', [ $this, 'on_dead_letter' ], 10, 2 );
		add_action( 'crm_connect_worker_paused', [ $this, 'on_paused' ] );
		add_action( 'crm_connect_capture_error', [ $this, 'on_capture_error' ] );
	}

	public function on_capture_error( $error ): void {
		$message = $error instanceof \Throwable ? $error->getMessage() : (string) $error;

		EventLog::error( sprintf( /* translators: %s: error */ __( 'Capture failed: %s', 'crm-connect' ), $message ) );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[CRM Connect] capture error: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		$this->notify(
			'capture_error',
			sprintf(
				/* translators: %s: brand name */
				__( '[%s] A form submission could not be captured', 'crm-connect' ),
				$this->settings->brand_name()
			),
			$message
		);
	}

	public function on_dead_letter( $item, $exception ): void {
		EventLog::error(
			sprintf(
				/* translators: 1: queue id, 2: form name, 3: error */
				__( 'Submission #%1$d (%2$s) failed permanently: %3$s', 'crm-connect' ),
				$item->id,
				$item->submission->form_name,
				$exception->getMessage()
			)
		);
		$this->notify(
			'dead_letter',
			sprintf(
				/* translators: %s: brand name */
				__( '[%s] A submission failed permanently', 'crm-connect' ),
				$this->settings->brand_name()
			),
			sprintf(
				"Form: %s\nQueue ID: %d\nError: %s",
				$item->submission->form_name,
				$item->id,
				$exception->getMessage()
			)
		);
	}

	public function on_paused( $threshold = 0 ): void {
		EventLog::warning(
			sprintf(
				/* translators: %d: failure threshold */
				__( 'Delivery auto-paused after %d consecutive failures.', 'crm-connect' ),
				(int) $threshold
			)
		);
		$this->notify(
			'paused',
			sprintf(
				/* translators: %s: brand name */
				__( '[%s] CRM delivery paused', 'crm-connect' ),
				$this->settings->brand_name()
			),
			sprintf(
				/* translators: %d: failure threshold */
				__( 'Delivery auto-paused after %d consecutive failures. Resume it from the Submissions screen once resolved.', 'crm-connect' ),
				(int) $threshold
			)
		);
	}

	private function notify( string $kind, string $subject, string $body ): void {
		$throttle = 'crm_connect_alert_' . $kind;
		if ( get_transient( $throttle ) ) {
			return;
		}
		set_transient( $throttle, 1, self::THROTTLE_SECONDS );

		$email = $this->settings->alert_email();
		if ( $email !== '' ) {
			wp_mail( $email, $subject, $body );
		}

		$webhook = $this->settings->slack_webhook();
		if ( $webhook !== '' && $this->is_slack_url( $webhook ) ) {
			wp_remote_post(
				$webhook,
				[
					'timeout' => 10,
					'headers' => [ 'Content-Type' => 'application/json' ],
					'body'    => wp_json_encode( [ 'text' => $subject . "\n" . $body ] ),
				]
			);
		}
	}

	private function is_slack_url( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return $host === 'hooks.slack.com' || str_ends_with( strtolower( $host ), '.slack.com' );
	}
}
