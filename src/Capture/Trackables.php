<?php

namespace CrmConnect\Capture;

defined( 'ABSPATH' ) || exit;

final class Trackables {

	private const PARAMS = [
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'gclid',
		'fbclid',
		'msclkid',
		'gad_source',
	];

	/** @return array<string,string> flatten-key => human label */
	public static function all(): array {
		$fields = [];

		foreach ( [ 'first' => 'First touch', 'last' => 'Last touch' ] as $touch => $label ) {
			foreach ( self::PARAMS as $param ) {
				$fields[ "_attr_{$touch}_params_{$param}" ] = "{$label}: {$param}";
			}
			$fields[ "_attr_{$touch}_referrer" ]     = "{$label}: referrer";
			$fields[ "_attr_{$touch}_landing_page" ] = "{$label}: landing page";
			$fields[ "_attr_{$touch}_timestamp" ]    = "{$label}: timestamp";
		}

		$fields['_meta_page_url']     = 'Submission page URL';
		$fields['_meta_page_title']   = 'Submission page title';
		$fields['_meta_remote_ip']    = 'IP address';
		$fields['_meta_user_agent']   = 'User agent';
		$fields['_meta_submitted_at'] = 'Submitted at';

		return apply_filters( 'crm_connect_trackables', $fields );
	}
}
