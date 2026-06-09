<?php

namespace CrmConnect\Mapping;

defined( 'ABSPATH' ) || exit;

final class Profile {

	public function __construct(
		public string $id,
		public string $source,
		public string $form_id,
		public string $form_name,
		public array $destinations,
		public bool $enabled = true,
		public string $created_at = ''
	) {}

	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['id'] ?? '' ),
			(string) ( $data['source'] ?? 'elementor' ),
			(string) ( $data['form_id'] ?? '' ),
			(string) ( $data['form_name'] ?? '' ),
			array_values( (array) ( $data['destinations'] ?? [] ) ),
			! isset( $data['enabled'] ) || (bool) $data['enabled'],
			(string) ( $data['created_at'] ?? '' )
		);
	}

	public function to_array(): array {
		return [
			'id'           => $this->id,
			'source'       => $this->source,
			'form_id'      => $this->form_id,
			'form_name'    => $this->form_name,
			'destinations' => $this->destinations,
			'enabled'      => $this->enabled,
			'created_at'   => $this->created_at,
		];
	}
}
