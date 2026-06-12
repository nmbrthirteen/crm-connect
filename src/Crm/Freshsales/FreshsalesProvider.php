<?php

namespace CrmConnect\Crm\Freshsales;

use CrmConnect\Crm\CrmField;
use CrmConnect\Crm\CrmObjectType;
use CrmConnect\Crm\CrmProvider;
use CrmConnect\Crm\CrmResult;
use CrmConnect\Crm\Exception\ApiException;

defined( 'ABSPATH' ) || exit;

final class FreshsalesProvider implements CrmProvider {

	private const OBJECTS = [
		'contacts'       => 'Contacts',
		'sales_accounts' => 'Sales Accounts',
		'deals'          => 'Deals',
	];

	private const SINGULAR = [
		'contacts'       => 'contact',
		'sales_accounts' => 'sales_account',
		'deals'          => 'deal',
	];

	public const ACCOUNT_FIELD = '__sales_account';
	public const NO_SPLIT      = '__no_split';

	private ?array $accounts_cache = null;

	public function __construct( private FreshsalesClient $client ) {}

	public function key(): string {
		return 'freshsales';
	}

	public function list_objects(): array {
		$objects = [];
		foreach ( self::OBJECTS as $key => $label ) {
			$objects[] = new CrmObjectType( $key, $label );
		}
		return $objects;
	}

	public function discover_fields( string $object ): array {
		$response = $this->client->get( "settings/{$object}/fields" );
		$raw      = $response['fields'] ?? $response;

		$fields = [];
		foreach ( (array) $raw as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['name'] ) ) {
				continue;
			}
			$choices = $this->normalize_choices(
				(array) ( $field['choices'] ?? $field['field_options'] ?? $field['picklist_values'] ?? [] )
			);
			$type = $this->normalize_type( (string) ( $field['type'] ?? 'text' ) );
			$fields[] = new CrmField(
				(string) $field['name'],
				(string) ( $field['label'] ?? $field['name'] ),
				$type,
				! empty( $field['required'] ),
				empty( $field['is_default'] ),
				$choices,
				isset( $field['field_group_id'] ) ? (string) $field['field_group_id'] : null
			);
		}
		return $fields;
	}

	public function upsert_record( string $object, array $data, array $unique = [] ): CrmResult {
		$account  = $data[ self::ACCOUNT_FIELD ] ?? null;
		$no_split = isset( $data[ self::NO_SPLIT ] );
		unset( $data[ self::ACCOUNT_FIELD ], $data[ self::NO_SPLIT ] );

		if ( $object === 'contacts' ) {
			if ( ! $no_split ) {
				$data = $this->split_name( $data );
			}
			$name = $account === null ? '' : trim( (string) $account );
			if ( $name !== '' ) {
				$account_id = $this->ensure_sales_account( $name );
				if ( $account_id ) {
					$data['sales_accounts'] = [ [ 'id' => $account_id, 'is_primary' => true ] ];
				}
			}
		}

		$singular = self::SINGULAR[ $object ] ?? rtrim( $object, 's' );

		$response = null;
		$limits   = array_values( (array) apply_filters( 'crm_connect_field_max_lengths', [ 800, 255 ] ) );
		for ( $attempt = 0; ; $attempt++ ) {
			try {
				$response = $this->post_record( $object, $singular, $data, $unique );
				break;
			} catch ( ApiException $e ) {
				$overlong = $this->overlong_fields( $e->getMessage() );
				if ( ! $overlong || ! isset( $limits[ $attempt ] ) ) {
					throw $e;
				}
				foreach ( $overlong as $field ) {
					if ( is_string( $data[ $field ] ?? null ) ) {
						$data[ $field ] = $this->truncate_value( $data[ $field ], (int) $limits[ $attempt ] );
					}
				}
			}
		}

		$body = [ $singular => $this->with_custom_fields( $data ) ];
		if ( $unique ) {
			$body['unique_identifier'] = $unique;
		}

		$entity = $response[ $singular ] ?? [];
		$id     = isset( $entity['id'] ) ? (string) $entity['id'] : null;
		$status = ! empty( $response['updated'] ) ? CrmResult::UPDATED : CrmResult::CREATED;

		return new CrmResult( $status, $id, $response, $body );
	}

	private function post_record( string $object, string $singular, array $data, array $unique ): array {
		$body = [ $singular => $this->with_custom_fields( $data ) ];
		if ( $unique ) {
			$body['unique_identifier'] = $unique;
			return $this->client->post( "{$object}/upsert", $body );
		}
		return $this->client->post( $object, $body );
	}

	/** @return string[] field names Freshsales rejected for being too long */
	private function overlong_fields( string $message ): array {
		if ( ! preg_match_all( '/value for (\S+?) is not in required length/i', $message, $matches ) ) {
			return [];
		}
		return array_values( array_unique( $matches[1] ) );
	}

	private function truncate_value( string $value, int $limit ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
	}

	/**
	 * Freshsales expects custom fields under custom_field.
	 */
	private function with_custom_fields( array $data ): array {
		$custom = [];
		foreach ( $data as $key => $value ) {
			if ( strpos( (string) $key, 'cf_' ) === 0 ) {
				$custom[ $key ] = $value;
				unset( $data[ $key ] );
			}
		}
		if ( $custom ) {
			$data['custom_field'] = $custom;
		}
		return $data;
	}

	public function list_sales_accounts(): array {
		if ( $this->accounts_cache !== null ) {
			return $this->accounts_cache;
		}
		$this->accounts_cache = $this->fetch_sales_accounts();
		return $this->accounts_cache;
	}

	private function fetch_sales_accounts(): array {
		try {
			$filters = $this->client->get( 'sales_accounts/filters' );
			$views   = $filters['filters'] ?? [];
			if ( ! $views ) {
				return [];
			}

			$view_id = $views[0]['id'] ?? null;
			foreach ( $views as $view ) {
				if ( isset( $view['name'] ) && stripos( (string) $view['name'], 'all' ) !== false ) {
					$view_id = $view['id'];
					break;
				}
			}
			if ( ! $view_id ) {
				return [];
			}

			$response = $this->client->get( "sales_accounts/view/{$view_id}", [ 'per_page' => '100' ] );
			$accounts = $response['sales_accounts'] ?? [];

			$out = [];
			foreach ( (array) $accounts as $account ) {
				if ( isset( $account['name'] ) && $account['name'] !== '' ) {
					$out[] = [
						'id'   => (string) ( $account['id'] ?? '' ),
						'name' => (string) $account['name'],
					];
				}
			}
			return $out;
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	private function split_name( array $data ): array {
		if ( empty( $data['first_name'] ) || ! empty( $data['last_name'] ) || ! is_string( $data['first_name'] ) ) {
			return $data;
		}
		$parts = preg_split( '/\s+/', trim( $data['first_name'] ) );
		if ( $parts && count( $parts ) > 1 ) {
			$data['first_name'] = array_shift( $parts );
			$data['last_name']  = implode( ' ', $parts );
		}
		return $data;
	}

	private function ensure_sales_account( string $name ): ?int {
		$found = $this->find_sales_account( $name );
		if ( $found ) {
			return $found;
		}

		try {
			$response = $this->client->post( 'sales_accounts', [ 'sales_account' => [ 'name' => $name ] ] );
			$account  = $response['sales_account'] ?? [];
			$id       = isset( $account['id'] ) ? (int) $account['id'] : null;
			if ( $id && is_array( $this->accounts_cache ) ) {
				$this->accounts_cache[] = [ 'id' => (string) $id, 'name' => $name ];
			}
			return $id;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function find_sales_account( string $name ): ?int {
		foreach ( $this->list_sales_accounts() as $account ) {
			if ( isset( $account['name'] ) && strcasecmp( (string) $account['name'], $name ) === 0 ) {
				return (int) $account['id'];
			}
		}
		return null;
	}

	private function normalize_type( string $type ): string {
		return match ( $type ) {
			'number', 'decimal', 'currency' => CrmField::TYPE_NUMBER,
			'date', 'datetime'              => CrmField::TYPE_DATE,
			'email'                         => CrmField::TYPE_EMAIL,
			'phone', 'phone_number'         => CrmField::TYPE_PHONE,
			'checkbox'                      => CrmField::TYPE_CHECKBOX,
			'dropdown', 'radio'             => CrmField::TYPE_DROPDOWN,
			'multiselect'                   => CrmField::TYPE_MULTISELECT,
			'lookup', 'relationship'        => CrmField::TYPE_LOOKUP,
			default                         => CrmField::TYPE_TEXT,
		};
	}

	private function normalize_choices( array $choices ): array {
		$result = [];
		foreach ( $choices as $choice ) {
			if ( is_array( $choice ) ) {
				$value = (string) ( $choice['value'] ?? $choice['name'] ?? $choice['label'] ?? $choice['id'] ?? '' );
				$label = (string) ( $choice['value'] ?? $choice['name'] ?? $choice['label'] ?? $value );
			} else {
				$value = (string) $choice;
				$label = (string) $choice;
			}
			if ( $value !== '' ) {
				$result[] = [
					'value' => $value,
					'label' => $label,
				];
			}
		}
		return $result;
	}
}
