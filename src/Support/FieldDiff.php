<?php

namespace CrmConnect\Support;

defined( 'ABSPATH' ) || exit;

final class FieldDiff {

	private const SINGULAR = [
		'contacts'       => 'contact',
		'sales_accounts' => 'sales_account',
		'deals'          => 'deal',
	];

	public static function dropped( array $request, array $response ): array {
		$dropped = [];

		foreach ( $request as $object => $sent ) {
			if ( ! is_array( $sent ) ) {
				continue;
			}

			$singular = self::SINGULAR[ $object ] ?? rtrim( (string) $object, 's' );
			$fields   = is_array( $sent[ $singular ] ?? null ) ? $sent[ $singular ] : $sent;
			$entity   = $response[ $object ][ $singular ] ?? [];
			$custom   = is_array( $entity['custom_field'] ?? null ) ? $entity['custom_field'] : [];

			foreach ( $fields as $field => $value ) {
				if ( strpos( (string) $field, 'cf_' ) !== 0 ) {
					continue;
				}
				if ( $value === '' || $value === null || is_array( $value ) ) {
					continue;
				}

				$stored = $custom[ $field ] ?? null;
				if ( $stored === null || $stored === '' ) {
					$dropped[] = (string) $field;
				}
			}
		}

		return $dropped;
	}
}
