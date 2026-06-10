<?php

namespace CrmConnect\Crm\Freshsales;

use CrmConnect\Crm\CrmField;
use CrmConnect\Crm\CrmObjectType;
use CrmConnect\Crm\CrmProvider;
use CrmConnect\Crm\CrmResult;
use CrmConnect\Crm\Exception\RateLimitException;

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
	private array $fields_cache    = [];
	private array $form_ids        = [];

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
		$body     = [ $singular => $data ];

		if ( $unique ) {
			$body['unique_identifier'] = $unique;
			$response = $this->client->post( "{$object}/upsert", $body );
		} else {
			$response = $this->client->post( $object, $body );
		}

		$entity = $response[ $singular ] ?? [];
		$id     = isset( $entity['id'] ) ? (string) $entity['id'] : null;
		$status = ! empty( $response['updated'] ) ? CrmResult::UPDATED : CrmResult::CREATED;

		return new CrmResult( $status, $id, $response, $body );
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

	/** @return string[] human-readable report, one line per field */
	public function ensure_choices( string $object, array $field_choices ): array {
		$field_choices = array_filter( $field_choices );
		if ( ! $field_choices ) {
			return [];
		}

		$fields = $this->raw_fields( $object );
		$report = [];
		foreach ( $field_choices as $name => $options ) {
			$name  = (string) $name;
			$field = $fields[ $name ] ?? null;
			if ( ! is_array( $field ) ) {
				$report[] = sprintf( '%s: not found in CRM', $name );
				continue;
			}
			if ( ! $this->is_choice_field( (string) ( $field['type'] ?? '' ) ) ) {
				$report[] = sprintf( '%s: not a list field in CRM (type: %s)', $name, (string) ( $field['type'] ?? '?' ) );
				continue;
			}
			$added    = $this->append_choices( $object, $field, (array) $options );
			$report[] = $added
				? sprintf( '%s: added %s', $name, implode( ', ', $added ) )
				: sprintf( '%s: already in sync', $name );
		}
		return $report;
	}

	/** @return string[] values actually added */
	private function append_choices( string $object, array $field, array $options ): array {
		$id = $field['id'] ?? null;
		if ( $id === null ) {
			return [];
		}

		$existing = [];
		$known    = [];
		foreach ( $this->raw_choices( $field ) as $choice ) {
			$value = is_array( $choice ) ? (string) ( $choice['value'] ?? $choice['name'] ?? $choice['label'] ?? '' ) : (string) $choice;
			if ( $value === '' ) {
				continue;
			}
			$known[ strtolower( $value ) ] = true;
			$entry                         = [ 'value' => $value, 'label' => $value ];
			if ( is_array( $choice ) && isset( $choice['id'] ) ) {
				$entry['id'] = $choice['id'];
			}
			$existing[] = $entry;
		}

		$additions = [];
		$added     = [];
		foreach ( $options as $option ) {
			$option = trim( (string) $option );
			if ( $option === '' || isset( $known[ strtolower( $option ) ] ) ) {
				continue;
			}
			$known[ strtolower( $option ) ] = true;
			$additions[]                    = [ 'value' => $option, 'label' => $option ];
			$added[]                        = $option;
		}
		if ( ! $additions ) {
			return [];
		}

		$body = [
			'field' => [
				'label'   => (string) ( $field['label'] ?? '' ),
				'type'    => (string) ( $field['type'] ?? 'dropdown' ),
				'choices' => array_merge( $existing, $additions ),
			],
		];

		$form_id = $this->default_form_id( $object );
		$paths   = [
			"settings/{$object}/forms/{$form_id}/fields/{$id}",
			"settings/{$object}/fields/{$id}",
		];

		$last = null;
		foreach ( $paths as $path ) {
			try {
				$this->client->put( $path, $body );
				unset( $this->fields_cache[ $object ] );
				return $added;
			} catch ( RateLimitException $e ) {
				throw $e;
			} catch ( \Throwable $e ) {
				$last = $e;
			}
		}
		if ( $last ) {
			throw $last;
		}
		return $added;
	}

	private function raw_fields( string $object ): array {
		if ( isset( $this->fields_cache[ $object ] ) ) {
			return $this->fields_cache[ $object ];
		}
		$response = $this->client->get( "settings/{$object}/fields" );
		$raw      = $response['fields'] ?? $response;

		$map = [];
		foreach ( (array) $raw as $field ) {
			if ( is_array( $field ) && isset( $field['name'] ) ) {
				$map[ (string) $field['name'] ] = $field;
			}
		}
		$this->fields_cache[ $object ] = $map;
		return $map;
	}

	private function raw_choices( array $field ): array {
		foreach ( [ 'choices', 'field_options', 'picklist_values' ] as $key ) {
			if ( isset( $field[ $key ] ) && is_array( $field[ $key ] ) ) {
				return $field[ $key ];
			}
		}
		return [];
	}

	private function is_choice_field( string $type ): bool {
		$type = strtolower( $type );
		foreach ( [ 'dropdown', 'select', 'radio', 'multiselect', 'multi_select' ] as $needle ) {
			if ( strpos( $type, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}

	private function default_form_id( string $object ): int {
		if ( isset( $this->form_ids[ $object ] ) ) {
			return $this->form_ids[ $object ];
		}

		$id = 0;
		try {
			$response = $this->client->get( "settings/{$object}/forms" );
			$forms    = $response['forms'] ?? $response;
			$first    = is_array( $forms ) ? ( $forms[0] ?? null ) : null;
			if ( is_array( $first ) && isset( $first['id'] ) ) {
				$id = (int) $first['id'];
			}
		} catch ( \Throwable $e ) {
			$id = 0;
		}

		$this->form_ids[ $object ] = $id;
		return $id;
	}

	private function choice_payload( array $choices ): array {
		$payload = [];
		foreach ( $choices as $choice ) {
			$value = is_array( $choice ) ? (string) ( $choice['value'] ?? '' ) : (string) $choice;
			if ( $value !== '' ) {
				$payload[] = [ 'value' => $value, 'label' => $value ];
			}
		}
		return $payload;
	}

	public function create_field( string $object, CrmField $field ): CrmField {
		$spec = [
			'label' => $field->label,
			'type'  => $this->denormalize_type( $field->type ),
		];
		if ( $field->choices ) {
			$spec['choices'] = $this->choice_payload( $field->choices );
		}

		$form_id  = $this->default_form_id( $object );
		$response = $this->client->post( "settings/{$object}/forms/{$form_id}/fields", [ 'field' => $spec ] );
		$created  = (array) ( $response['field'] ?? [] );

		return new CrmField(
			(string) ( $created['name'] ?? $field->name ),
			(string) ( $created['label'] ?? $field->label ),
			$field->type,
			false,
			true
		);
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

	private function denormalize_type( string $type ): string {
		return match ( $type ) {
			CrmField::TYPE_NUMBER      => 'number',
			CrmField::TYPE_DATE        => 'date',
			CrmField::TYPE_CHECKBOX    => 'checkbox',
			CrmField::TYPE_DROPDOWN    => 'dropdown',
			CrmField::TYPE_MULTISELECT => 'multiselect',
			default                    => 'text',
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
