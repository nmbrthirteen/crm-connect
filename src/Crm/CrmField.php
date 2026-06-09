<?php

namespace CrmConnect\Crm;

defined( 'ABSPATH' ) || exit;

final class CrmField {

	public const TYPE_TEXT        = 'text';
	public const TYPE_NUMBER      = 'number';
	public const TYPE_DATE        = 'date';
	public const TYPE_EMAIL       = 'email';
	public const TYPE_PHONE       = 'phone';
	public const TYPE_CHECKBOX    = 'checkbox';
	public const TYPE_DROPDOWN    = 'dropdown';
	public const TYPE_MULTISELECT = 'multiselect';
	public const TYPE_LOOKUP      = 'lookup';

	/**
	 * @param array<int,array{value:string,label:string}> $choices
	 */
	public function __construct(
		public string $name,
		public string $label,
		public string $type = self::TYPE_TEXT,
		public bool $required = false,
		public bool $is_custom = false,
		public array $choices = [],
		public ?string $group = null
	) {}

	public function to_array(): array {
		return [
			'name'      => $this->name,
			'label'     => $this->label,
			'type'      => $this->type,
			'required'  => $this->required,
			'is_custom' => $this->is_custom,
			'choices'   => $this->choices,
			'group'     => $this->group,
		];
	}
}
