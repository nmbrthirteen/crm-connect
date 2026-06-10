<?php

namespace CrmConnect\Admin;

use CrmConnect\Capture\Trackables;
use CrmConnect\Crm\CrmField;
use CrmConnect\Crm\Exception\ApiException;
use CrmConnect\Mapping\Profile;
use CrmConnect\Plugin;
use CrmConnect\Support\SchemaCache;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class RestController {

	private const NS = 'crm-connect/v1';

	private SchemaCache $cache;

	public function __construct( private Plugin $plugin ) {
		$this->cache = new SchemaCache();
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		$perm = [ $this, 'can_manage' ];

		register_rest_route(
			self::NS,
			'/forms',
			[
				'methods'             => 'GET',
				'callback'            => $this->cb( 'list_forms' ),
				'permission_callback' => $perm,
			]
		);

		register_rest_route(
			self::NS,
			'/forms/(?P<id>[^/]+)/fields',
			[
				'methods'             => 'GET',
				'callback'            => $this->cb( 'form_fields' ),
				'permission_callback' => $perm,
			]
		);

		register_rest_route(
			self::NS,
			'/crm/objects',
			[
				'methods'             => 'GET',
				'callback'            => $this->cb( 'crm_objects' ),
				'permission_callback' => $perm,
			]
		);

		register_rest_route(
			self::NS,
			'/crm/accounts',
			[
				'methods'             => 'GET',
				'callback'            => $this->cb( 'list_accounts' ),
				'permission_callback' => $perm,
			]
		);

		register_rest_route(
			self::NS,
			'/crm/objects/(?P<object>[a-z_]+)/fields',
			[
				[
					'methods'             => 'GET',
					'callback'            => $this->cb( 'crm_fields' ),
					'permission_callback' => $perm,
				],
			]
		);

		register_rest_route(
			self::NS,
			'/profiles',
			[
				[
					'methods'             => 'GET',
					'callback'            => $this->cb( 'list_profiles' ),
					'permission_callback' => $perm,
				],
				[
					'methods'             => 'POST',
					'callback'            => $this->cb( 'save_profile' ),
					'permission_callback' => $perm,
				],
			]
		);

		register_rest_route(
			self::NS,
			'/profiles/(?P<id>[A-Za-z0-9\-]+)',
			[
				'methods'             => 'DELETE',
				'callback'            => $this->cb( 'delete_profile' ),
				'permission_callback' => $perm,
			]
		);

		register_rest_route(
			self::NS,
			'/connection/test',
			[
				'methods'             => 'POST',
				'callback'            => $this->cb( 'test_connection' ),
				'permission_callback' => $perm,
			]
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	private function cb( string $method ): callable {
		return function ( WP_REST_Request $request ) use ( $method ) {
			try {
				return $this->$method( $request );
			} catch ( ApiException $e ) {
				return $this->error( $e );
			} catch ( \Throwable $e ) {
				return new WP_Error(
					'crm_connect_error',
					$e->getMessage() !== '' ? $e->getMessage() : __( 'Unexpected server error.', 'crm-connect' ),
					[ 'status' => 500 ]
				);
			}
		};
	}

	public function list_forms(): WP_REST_Response {
		$forms = [];
		foreach ( $this->plugin->sources()->all() as $source ) {
			foreach ( $source->list_forms() as $form ) {
				$forms[] = $form->to_array() + [ 'source_label' => $source->label() ];
			}
		}
		return new WP_REST_Response( $forms );
	}

	public function form_fields( WP_REST_Request $request ): WP_REST_Response {
		$source = $this->plugin->sources()->get( (string) $request->get_param( 'source' ) )
			?? $this->plugin->sources()->all()[0] ?? null;

		$fields = [];
		if ( $source ) {
			foreach ( $source->get_form_fields( (string) $request['id'] ) as $field ) {
				$fields[] = $field->to_array();
			}
		}

		return new WP_REST_Response(
			[
				'form'       => $fields,
				'trackables' => Trackables::all(),
			]
		);
	}

	public function crm_objects(): WP_REST_Response {
		$objects = array_map(
			static fn ( $object ) => $object->to_array(),
			$this->plugin->providers()->get()->list_objects()
		);
		return new WP_REST_Response( $objects );
	}

	public function crm_fields( WP_REST_Request $request ) {
		$object   = (string) $request['object'];
		$provider = $this->plugin->providers()->get();

		if ( ! $request->get_param( 'refresh' ) ) {
			$cached = $this->cache->get( $provider->key(), $object );
			if ( $cached !== null ) {
				return new WP_REST_Response( $cached );
			}
		}

		$fields = array_map(
			static fn ( CrmField $field ) => $field->to_array(),
			$provider->discover_fields( $object )
		);

		$this->cache->set( $provider->key(), $object, $fields );
		return new WP_REST_Response( $fields );
	}

	public function list_accounts(): WP_REST_Response {
		$provider = $this->plugin->providers()->get();
		if ( ! method_exists( $provider, 'list_sales_accounts' ) ) {
			return new WP_REST_Response( [] );
		}

		$cached = $this->cache->get( $provider->key(), '__accounts' );
		if ( $cached !== null ) {
			return new WP_REST_Response( $cached );
		}

		$list = $provider->list_sales_accounts();
		$this->cache->set( $provider->key(), '__accounts', $list );
		return new WP_REST_Response( $list );
	}

	public function list_profiles(): WP_REST_Response {
		$profiles = array_map(
			static fn ( Profile $profile ) => $profile->to_array(),
			$this->plugin->profiles()->all()
		);
		return new WP_REST_Response( array_values( $profiles ) );
	}

	public function save_profile( WP_REST_Request $request ): WP_REST_Response {
		$payload = (array) $request->get_json_params();
		if ( empty( $payload['id'] ) ) {
			$payload['id'] = $this->generate_id();
		}

		$profile = Profile::from_array( $payload );
		$this->plugin->profiles()->save( $profile );

		return new WP_REST_Response( $profile->to_array() );
	}

	public function delete_profile( WP_REST_Request $request ): WP_REST_Response {
		$this->plugin->profiles()->delete( (string) $request['id'] );
		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	public function test_connection() {
		$this->plugin->providers()->get()->discover_fields( 'contacts' );
		return new WP_REST_Response( [ 'ok' => true ] );
	}

	private function error( ApiException $e ): WP_Error {
		$status = $e->status >= 400 ? $e->status : 502;
		return new WP_Error( 'crm_connect_api', $e->getMessage(), [ 'status' => $status ] );
	}

	private function generate_id(): string {
		return 'p-' . substr( md5( uniqid( 'crmc', true ) ), 0, 12 );
	}
}
