<?php

namespace CrmConnect\Capture\Source\Elementor;

use CrmConnect\Capture\Source\FormDescriptor;
use CrmConnect\Capture\Source\FormFieldDescriptor;

defined( 'ABSPATH' ) || exit;

final class ElementorFormParser {

	private const CACHE = 'crm_connect_elementor_forms_v2';

	private ?array $index = null;

	private static function cache_key(): string {
		return self::CACHE . '_' . CRM_CONNECT_VERSION;
	}

	public static function clear_cache(): void {
		delete_transient( self::cache_key() );
	}

	/** @return FormDescriptor[] */
	public function list_forms(): array {
		$forms = [];
		foreach ( $this->index() as $entry ) {
			$forms[] = new FormDescriptor( 'elementor', $entry['id'], $entry['name'], $entry['title'] );
		}
		return $forms;
	}

	/** @return FormFieldDescriptor[] */
	public function get_form_fields( string $form_id ): array {
		$entry  = $this->index()[ $form_id ] ?? null;
		$fields = [];
		foreach ( $entry['fields'] ?? [] as $field ) {
			$fields[] = new FormFieldDescriptor( $field['id'], $field['label'], $field['type'], $field['options'] ?? [] );
		}
		return $fields;
	}

	/** @return array<string,array{id:string,name:string,title:string,fields:array}> */
	private function index(): array {
		if ( $this->index !== null ) {
			return $this->index;
		}
		$cached = get_transient( self::cache_key() );
		if ( is_array( $cached ) ) {
			$this->index = $cached;
			return $cached;
		}

		$index = [];
		foreach ( $this->stored_documents() as $post_id => $elements ) {
			$this->walk(
				$elements,
				function ( array $widget ) use ( $post_id, &$index ) {
					$id = (string) ( $widget['id'] ?? '' );
					if ( $id === '' ) {
						return;
					}
					$settings = (array) ( $widget['settings'] ?? [] );
					$fields   = $this->extract_fields( $settings );

					if ( isset( $index[ $id ] ) && count( $index[ $id ]['fields'] ) >= count( $fields ) ) {
						return;
					}

					$index[ $id ] = [
						'id'     => $id,
						'name'   => (string) ( $settings['form_name'] ?? '' ),
						'title'  => (string) get_the_title( $post_id ),
						'fields' => $fields,
					];
				}
			);
		}

		set_transient( self::cache_key(), $index, 10 * MINUTE_IN_SECONDS );
		$this->index = $index;
		return $index;
	}

	/** @return array<int,array> post_id => decoded _elementor_data */
	private function stored_documents(): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '"widgetType":"form"' ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
				$like
			)
		);

		$documents = [];
		foreach ( (array) $rows as $row ) {
			$data = json_decode( (string) $row->meta_value, true );
			if ( is_array( $data ) ) {
				$documents[ (int) $row->post_id ] = $data;
			}
		}
		return $documents;
	}

	private function walk( array $elements, callable $visitor ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( ( $element['widgetType'] ?? '' ) === 'form' ) {
				$visitor( $element );
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk( $element['elements'], $visitor );
			}
		}
	}

	private function extract_fields( array $settings ): array {
		$fields = [];
		foreach ( (array) ( $settings['form_fields'] ?? [] ) as $field ) {
			$id = (string) ( $field['custom_id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			$label = trim( (string) ( $field['field_label'] ?? '' ) );
			if ( $label === '' ) {
				$label = trim( (string) ( $field['placeholder'] ?? '' ) );
			}
			$type     = (string) ( $field['field_type'] ?? 'text' );
			$fields[] = [
				'id'      => $id,
				'label'   => $label !== '' ? $label : $id,
				'type'    => $type,
				'options' => $this->extract_options( $type, $field ),
			];
		}
		return $fields;
	}

	/** @return string[] */
	private function extract_options( string $type, array $field ): array {
		if ( ! in_array( $type, [ 'select', 'radio', 'checkbox' ], true ) ) {
			return [];
		}

		$raw   = $field['field_options'] ?? '';
		$lines = is_array( $raw ) ? $raw : preg_split( '/\r\n|\r|\n/', (string) $raw );

		$options = [];
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( $line === '' ) {
				continue;
			}
			$pipe  = strpos( $line, '|' );
			$value = $pipe === false ? $line : trim( substr( $line, $pipe + 1 ) );
			if ( $value !== '' ) {
				$options[] = $value;
			}
		}
		return $options;
	}
}
