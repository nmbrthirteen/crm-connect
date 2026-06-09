<?php

namespace CrmConnect\Support;

defined( 'ABSPATH' ) || exit;

final class Url {

	public static function host( string $value ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}
		$with_scheme = preg_match( '#^https?://#i', $value ) ? $value : 'https://' . $value;
		$host        = wp_parse_url( $with_scheme, PHP_URL_HOST );
		if ( ! $host ) {
			$host = explode( '/', (string) preg_replace( '#^https?://#i', '', $value ) )[0];
		}
		return (string) $host;
	}
}
