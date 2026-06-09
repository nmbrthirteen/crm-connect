<?php

namespace CrmConnect\Crm\Freshsales;

use CrmConnect\Crm\Exception\ApiException;
use CrmConnect\Crm\Exception\RateLimitException;

defined( 'ABSPATH' ) || exit;

final class FreshsalesClient {

	public function __construct(
		private string $bundle_alias,
		private string $api_key
	) {}

	public function get( string $path, array $query = [] ): array {
		return $this->request( 'GET', $path, null, $query );
	}

	public function post( string $path, array $body ): array {
		return $this->request( 'POST', $path, $body );
	}

	public function put( string $path, array $body ): array {
		return $this->request( 'PUT', $path, $body );
	}

	private function request( string $method, string $path, ?array $body, array $query = [] ): array {
		if ( $this->bundle_alias === '' || $this->api_key === '' ) {
			throw new ApiException( 'Freshsales credentials are not configured.' );
		}

		$url = $this->base_url() . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Token token=' . $this->api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
		];
		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new ApiException( $response->get_error_message() );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = $raw !== '' ? json_decode( $raw, true ) : [];
		$is_json = is_array( $decoded );
		if ( ! $is_json ) {
			$decoded = [];
		}

		if ( $status === 429 ) {
			$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			throw new RateLimitException( $retry > 0 ? $retry : 60, $decoded );
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new ApiException( $this->error_message( $decoded, $status ), $status, $decoded );
		}

		if ( ! $is_json && $raw !== '' ) {
			throw new ApiException(
				__( 'Freshsales returned a non-JSON response. Check that the domain is just your bundle host (e.g. yourco.myfreshworks.com) with no path.', 'crm-connect' ),
				$status
			);
		}

		return $decoded;
	}

	private function base_url(): string {
		return $this->host() . '/crm/sales/api/';
	}

	private function host(): string {
		$host = \CrmConnect\Support\Url::host( $this->bundle_alias );
		return $host === '' ? '' : 'https://' . $host;
	}

	private function error_message( array $decoded, int $status ): string {
		$message = $decoded['errors']['message'] ?? $decoded['message'] ?? null;
		if ( is_array( $message ) ) {
			return implode( '; ', array_map( 'strval', $message ) );
		}
		if ( is_string( $message ) && $message !== '' ) {
			return $message;
		}
		if ( $status === 401 || $status === 403 ) {
			return __( 'Freshsales rejected the API key. Re-copy it from Freshsales → Profile Settings → API Settings.', 'crm-connect' );
		}
		if ( $status === 404 ) {
			return __( 'Freshsales returned 404 - the bundle domain or API key does not match. The domain should be just your CRM host (e.g. yourco.myfreshworks.com), taken from Freshsales → Profile Settings → API Settings, with the matching key.', 'crm-connect' );
		}
		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'Freshsales API error (HTTP %d)', 'crm-connect' ),
			$status
		);
	}
}
