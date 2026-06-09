<?php

namespace CrmConnect\Capture\Source;

defined( 'ABSPATH' ) || exit;

final class FormFieldDescriptor {

	/** @param string[] $options */
	public function __construct(
		public string $id,
		public string $label,
		public string $type = 'text',
		public array $options = []
	) {}

	public function to_array(): array {
		return [
			'id'      => $this->id,
			'label'   => $this->label,
			'type'    => $this->type,
			'options' => $this->options,
		];
	}
}
