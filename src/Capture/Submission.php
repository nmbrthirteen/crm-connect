<?php

namespace CrmConnect\Capture;

defined( 'ABSPATH' ) || exit;

final class Submission {

	/**
	 * @param array<string,array{value:mixed,label:string,type:string}> $fields
	 */
	public function __construct(
		public string $source,
		public string $form_id,
		public string $form_name,
		public array $fields,
		public array $meta = [],
		public array $attribution = [],
		public ?int $entry_id = null
	) {}

	public function to_array(): array {
		return [
			'source'      => $this->source,
			'form_id'     => $this->form_id,
			'form_name'   => $this->form_name,
			'fields'      => $this->fields,
			'meta'        => $this->meta,
			'attribution' => $this->attribution,
			'entry_id'    => $this->entry_id,
		];
	}

	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['source'] ?? '' ),
			(string) ( $data['form_id'] ?? '' ),
			(string) ( $data['form_name'] ?? '' ),
			(array) ( $data['fields'] ?? [] ),
			(array) ( $data['meta'] ?? [] ),
			(array) ( $data['attribution'] ?? [] ),
			isset( $data['entry_id'] ) ? (int) $data['entry_id'] : null
		);
	}

	public function flatten(): array {
		$flat = [];
		foreach ( $this->fields as $key => $field ) {
			$value = is_array( $field ) ? ( $field['value'] ?? '' ) : $field;
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$flat[ (string) $key ] = $value;
		}
		$this->flatten_into( $flat, $this->attribution, '_attr' );
		$this->flatten_into( $flat, $this->meta, '_meta' );
		return $flat;
	}

	private function flatten_into( array &$flat, array $data, string $prefix ): void {
		foreach ( $data as $key => $value ) {
			$compound = $prefix . '_' . $key;
			if ( is_array( $value ) ) {
				$this->flatten_into( $flat, $value, $compound );
			} else {
				$flat[ $compound ] = $value;
			}
		}
	}
}
