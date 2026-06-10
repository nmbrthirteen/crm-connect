<?php

namespace CrmConnect\Support;

defined( 'ABSPATH' ) || exit;

final class SchemaCache {

	private const PREFIX  = 'crm_connect_fields_';
	private const VERSION = '5';
	private const TTL     = 6 * HOUR_IN_SECONDS;

	public function get( string $provider, string $object ): ?array {
		$cached = get_transient( $this->key( $provider, $object ) );
		return is_array( $cached ) ? $cached : null;
	}

	public function set( string $provider, string $object, array $fields ): void {
		set_transient( $this->key( $provider, $object ), $fields, self::TTL );
	}

	public function forget( string $provider, string $object ): void {
		delete_transient( $this->key( $provider, $object ) );
	}

	private function key( string $provider, string $object ): string {
		return self::PREFIX . md5( self::VERSION . ':' . $provider . ':' . $object );
	}
}
