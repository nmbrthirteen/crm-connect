<?php

namespace CrmConnect\Mapping;

defined( 'ABSPATH' ) || exit;

final class MappingPlan {

	public function __construct(
		public string $object,
		public array $data,
		public array $unique = []
	) {}
}
