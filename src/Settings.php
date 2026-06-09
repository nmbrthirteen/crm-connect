<?php

namespace CrmConnect;

use CrmConnect\Support\Crypto;
use CrmConnect\Support\Url;

defined( 'ABSPATH' ) || exit;

final class Settings {

	private const OPTION = 'crm_connect_settings';

	private array $data;

	public function __construct() {
		$stored     = get_option( self::OPTION, [] );
		$this->data = is_array( $stored ) ? $stored : [];
	}

	public function all(): array {
		return $this->data;
	}

	public function get( string $key, $default = null ) {
		return $this->data[ $key ] ?? $default;
	}

	public function set( string $key, $value ): void {
		$this->data[ $key ] = $value;
		update_option( self::OPTION, $this->data );
	}

	public function update( array $values ): void {
		$this->data = array_merge( $this->data, $values );
		update_option( self::OPTION, $this->data );
	}

	public function bundle_alias(): string {
		return Url::host( (string) $this->get( 'bundle_alias', '' ) );
	}

	public function api_key(): string {
		$stored = (string) $this->get( 'api_key', '' );
		return $stored === '' ? '' : Crypto::decrypt( $stored );
	}

	public function set_api_key( string $key ): void {
		$this->set( 'api_key', $key === '' ? '' : Crypto::encrypt( $key ) );
	}

	public function provider_key(): string {
		return (string) $this->get( 'provider', 'freshsales' );
	}

	public function brand_name(): string {
		$name = trim( (string) $this->get( 'brand_name', '' ) );
		return $name !== '' ? $name : __( 'CRM Connect', 'crm-connect' );
	}

	public function retention_days(): int {
		return (int) $this->get( 'retention_days', 180 );
	}

	public function autopause_threshold(): int {
		return (int) $this->get( 'autopause_threshold', 10 );
	}

	public function alert_email(): string {
		$email = (string) $this->get( 'alert_email', '' );
		return $email !== '' ? $email : (string) get_option( 'admin_email' );
	}

	public function slack_webhook(): string {
		return (string) $this->get( 'slack_webhook', '' );
	}
}
