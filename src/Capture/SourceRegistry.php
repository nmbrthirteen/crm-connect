<?php

namespace CrmConnect\Capture;

use CrmConnect\Capture\Source\Elementor\ElementorFormSource;
use CrmConnect\Capture\Source\FormSource;

defined( 'ABSPATH' ) || exit;

final class SourceRegistry {

	/** @var FormSource[]|null */
	private ?array $sources = null;

	/** @return FormSource[] */
	public function all(): array {
		if ( $this->sources === null ) {
			$default       = [ new ElementorFormSource() ];
			$sources       = apply_filters( 'crm_connect_form_sources', $default );
			$this->sources = array_values(
				array_filter(
					is_array( $sources ) ? $sources : $default,
					static fn ( $source ) => $source instanceof FormSource
				)
			);
		}
		return $this->sources;
	}

	public function get( string $key ): ?FormSource {
		foreach ( $this->all() as $source ) {
			if ( $source->key() === $key ) {
				return $source;
			}
		}
		return null;
	}
}
