<?php

namespace CrmConnect\Crm\Exception;

defined( 'ABSPATH' ) || exit;

class ApiException extends \RuntimeException {

	public function __construct(
		string $message,
		public int $status = 0,
		public array $response = []
	) {
		parent::__construct( $message, $status );
	}
}
