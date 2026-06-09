<?php

namespace CrmConnect\Crm;

use CrmConnect\Crm\Freshsales\FreshsalesClient;
use CrmConnect\Crm\Freshsales\FreshsalesProvider;
use CrmConnect\Settings;

defined( 'ABSPATH' ) || exit;

final class ProviderFactory {

	private ?CrmProvider $resolved = null;

	public function __construct( private Settings $settings ) {}

	public function get(): CrmProvider {
		if ( $this->resolved === null ) {
			$this->resolved = $this->make( $this->settings->provider_key() );
		}
		return $this->resolved;
	}

	private function make( string $key ): CrmProvider {
		$provider = apply_filters( 'crm_connect_provider', null, $key, $this->settings );
		if ( $provider instanceof CrmProvider ) {
			return $provider;
		}

		$client = new FreshsalesClient( $this->settings->bundle_alias(), $this->settings->api_key() );
		return new FreshsalesProvider( $client );
	}
}
