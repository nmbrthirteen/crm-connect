<?php

namespace CrmConnect\Crm;

defined( 'ABSPATH' ) || exit;

final class CrmObjectType {

	public function __construct(
		public string $key,
		public string $label
	) {}

	public function to_array(): array {
		return [
			'key'   => $this->key,
			'label' => $this->label,
		];
	}
}
