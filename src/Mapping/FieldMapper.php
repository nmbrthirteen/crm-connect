<?php

namespace CrmConnect\Mapping;

use CrmConnect\Capture\Submission;
use CrmConnect\Crm\CrmProvider;

defined( 'ABSPATH' ) || exit;

final class FieldMapper {

	public function __construct( private CrmProvider $provider ) {}

	public function build( array $destination, Submission $submission ): MappingPlan {
		$flat          = $submission->flatten();
		$data          = [];
		$mapped_source = [];

		foreach ( (array) ( $destination['field_map'] ?? [] ) as $crm_field => $rule ) {
			$rule  = (array) $rule;
			$value = $this->resolve_value( $rule, $flat );
			if ( $value === null || $value === '' ) {
				continue;
			}
			$data[ $crm_field ] = $value;
			if ( ( $rule['source'] ?? 'field' ) !== 'static' && ! empty( $rule['key'] ) ) {
				$mapped_source[] = (string) $rule['key'];
			}
		}

		$catch_all = (string) ( $destination['catch_all'] ?? '' );
		if ( $catch_all !== '' ) {
			$text = $this->catch_all_text( $flat, $mapped_source );
			if ( $text !== '' ) {
				$data[ $catch_all ] = $text;
			}
		}

		return new MappingPlan(
			(string) ( $destination['object'] ?? 'contacts' ),
			$data,
			$this->resolve_unique( (array) ( $destination['unique'] ?? [] ), $flat )
		);
	}

	private function resolve_value( array $rule, array $flat ) {
		if ( ( $rule['source'] ?? 'field' ) === 'static' ) {
			return $rule['value'] ?? null;
		}

		$value = $flat[ (string) ( $rule['key'] ?? '' ) ] ?? null;
		if ( $value === null ) {
			return null;
		}

		if ( ! empty( $rule['choice_map'] ) ) {
			$needle = strtolower( trim( (string) $value ) );
			foreach ( (array) $rule['choice_map'] as $from => $to ) {
				if ( strtolower( trim( (string) $from ) ) === $needle ) {
					return $to;
				}
			}
		}

		if ( ! empty( $rule['choices'] ) ) {
			$needle = strtolower( trim( (string) $value ) );
			foreach ( (array) $rule['choices'] as $choice ) {
				if ( strtolower( trim( (string) $choice ) ) === $needle ) {
					return $choice;
				}
			}
			return null;
		}

		return $value;
	}

	private function resolve_unique( array $unique, array $flat ): array {
		$by  = (string) ( $unique['by'] ?? '' );
		$key = (string) ( $unique['key'] ?? '' );
		if ( $by === '' || $key === '' ) {
			return [];
		}
		$value = (string) ( $flat[ $key ] ?? '' );
		return $value === '' ? [] : [ $by => $value ];
	}

	private function catch_all_text( array $flat, array $mapped_source ): string {
		$lines = [];
		foreach ( $flat as $key => $value ) {
			if ( in_array( (string) $key, $mapped_source, true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}
			if ( $value === '' || $value === null ) {
				continue;
			}
			$lines[] = sprintf( '%s: %s', $key, $value );
		}
		return implode( "\n", $lines );
	}
}
