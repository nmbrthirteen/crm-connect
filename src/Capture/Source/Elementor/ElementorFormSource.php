<?php

namespace CrmConnect\Capture\Source\Elementor;

use CrmConnect\Capture\Attribution;
use CrmConnect\Capture\Source\FormSource;
use CrmConnect\Capture\Submission;

defined( 'ABSPATH' ) || exit;

final class ElementorFormSource implements FormSource {

	private ElementorFormParser $parser;

	public function __construct( ?ElementorFormParser $parser = null ) {
		$this->parser = $parser ?? new ElementorFormParser();
	}

	public function key(): string {
		return 'elementor';
	}

	public function label(): string {
		return __( 'Elementor Forms', 'crm-connect' );
	}

	public function register( callable $on_submission ): void {
		add_action(
			'elementor_pro/forms/new_record',
			function ( $record, $handler ) use ( $on_submission ) {
				unset( $handler );
				try {
					$on_submission( $this->build_submission( $record ) );
				} catch ( \Throwable $e ) {
					do_action( 'crm_connect_capture_error', $e );
				}
			},
			20,
			2
		);

		add_action( 'elementor/document/after_save', [ ElementorFormParser::class, 'clear_cache' ] );
		add_action( 'save_post', [ ElementorFormParser::class, 'clear_cache' ] );
		add_action( 'deleted_post', [ ElementorFormParser::class, 'clear_cache' ] );
	}

	public function list_forms(): array {
		return $this->parser->list_forms();
	}

	public function get_form_fields( string $form_id ): array {
		return $this->parser->get_form_fields( $form_id );
	}

	private function build_submission( $record ): Submission {
		$form_name = (string) $record->get_form_settings( 'form_name' );
		$form_id   = (string) $record->get_form_settings( 'id' );

		$options = [];
		foreach ( $this->parser->get_form_fields( $form_id ) as $descriptor ) {
			if ( $descriptor->options ) {
				$options[ $descriptor->id ] = $descriptor->options;
			}
		}

		$fields = [];
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			if ( ! is_array( $field ) ) {
				$field = [ 'value' => $field ];
			}
			$fields[ (string) $id ] = [
				'value'   => $field['value'] ?? '',
				'label'   => $field['title'] ?? (string) $id,
				'type'    => $field['type'] ?? 'text',
				'options' => $options[ (string) $id ] ?? [],
			];
		}

		$meta = (array) $record->get( 'meta' );

		return new Submission(
			$this->key(),
			$form_id,
			$form_name,
			$fields,
			[
				'page_url'     => $this->meta_value( $meta, 'page_url' ),
				'page_title'   => $this->meta_value( $meta, 'page_title' ),
				'remote_ip'    => $this->meta_value( $meta, 'remote_ip' ),
				'user_agent'   => $this->meta_value( $meta, 'user_agent' ),
				'submitted_at' => current_time( 'mysql' ),
			],
			Attribution::from_request()
		);
	}

	private function meta_value( array $meta, string $key ): string {
		if ( ! isset( $meta[ $key ] ) ) {
			return '';
		}
		$entry = $meta[ $key ];
		return is_array( $entry ) ? (string) ( $entry['value'] ?? '' ) : (string) $entry;
	}
}
