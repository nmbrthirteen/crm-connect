<?php

namespace CrmConnect\Capture\Source;

defined( 'ABSPATH' ) || exit;

final class FormDescriptor {

	public function __construct(
		public string $source,
		public string $id,
		public string $name,
		public string $title = ''
	) {}

	public function to_array(): array {
		return [
			'source' => $this->source,
			'id'     => $this->id,
			'name'   => $this->name,
			'title'  => $this->title,
		];
	}
}
