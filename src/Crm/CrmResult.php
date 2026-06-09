<?php

namespace CrmConnect\Crm;

defined( 'ABSPATH' ) || exit;

final class CrmResult {

	public const CREATED = 'created';
	public const UPDATED = 'updated';

	public function __construct(
		public string $status,
		public ?string $id,
		public array $response = [],
		public array $request = []
	) {}
}
