<?php

namespace CrmConnect\Crm;

defined( 'ABSPATH' ) || exit;

interface CrmProvider {

	public function key(): string;

	/** @return CrmObjectType[] */
	public function list_objects(): array;

	/** @return CrmField[] */
	public function discover_fields( string $object ): array;

	public function upsert_record( string $object, array $data, array $unique = [] ): CrmResult;
}
