<?php

namespace CrmConnect\Support;

defined( 'ABSPATH' ) || exit;

final class Json {

	public static function pretty( $json ): string {
		$decoded = json_decode( (string) $json, true );
		if ( $decoded === null ) {
			return (string) $json;
		}
		return (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
