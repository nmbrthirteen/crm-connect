<?php

namespace CrmConnect\Crm\Exception;

defined( 'ABSPATH' ) || exit;

final class RateLimitException extends ApiException {

	public int $retry_after;

	public function __construct( int $retry_after = 60, array $response = [] ) {
		parent::__construct( 'Rate limit exceeded', 429, $response );
		$this->retry_after = $retry_after;
	}
}
