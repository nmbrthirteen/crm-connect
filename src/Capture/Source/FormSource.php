<?php

namespace CrmConnect\Capture\Source;

use CrmConnect\Capture\Submission;

defined( 'ABSPATH' ) || exit;

interface FormSource {

	public function key(): string;

	public function label(): string;

	public function register( callable $on_submission ): void;

	/** @return FormDescriptor[] */
	public function list_forms(): array;

	/** @return FormFieldDescriptor[] */
	public function get_form_fields( string $form_id ): array;
}
