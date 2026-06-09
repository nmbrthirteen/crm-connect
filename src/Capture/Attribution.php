<?php

namespace CrmConnect\Capture;

defined( 'ABSPATH' ) || exit;

final class Attribution {

	public const COOKIE = 'crmc_attr';

	public static function from_request(): array {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return [];
		}
		$decoded = json_decode( stripslashes( (string) $_COOKIE[ self::COOKIE ] ), true ); // phpcs:ignore WordPress.Security
		return is_array( $decoded ) ? self::sanitize( $decoded ) : [];
	}

	private static function sanitize( array $data ): array {
		$clean = [];
		foreach ( $data as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( is_array( $value ) ) {
				$clean[ $key ] = self::sanitize( $value );
			} else {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}
}
