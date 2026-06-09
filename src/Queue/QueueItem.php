<?php

namespace CrmConnect\Queue;

use CrmConnect\Capture\Submission;

defined( 'ABSPATH' ) || exit;

final class QueueItem {

	public function __construct(
		public int $id,
		public string $status,
		public int $attempts,
		public Submission $submission,
		public ?string $last_error = null,
		public int $completed = 0
	) {}

	public static function from_row( object $row ): self {
		$payload = json_decode( (string) $row->payload, true );

		return new self(
			(int) $row->id,
			(string) $row->status,
			(int) $row->attempts,
			Submission::from_array( is_array( $payload ) ? $payload : [] ),
			isset( $row->last_error ) ? (string) $row->last_error : null,
			isset( $row->completed ) ? (int) $row->completed : 0
		);
	}
}
