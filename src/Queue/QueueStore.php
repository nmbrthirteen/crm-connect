<?php

namespace CrmConnect\Queue;

use CrmConnect\Capture\Submission;
use CrmConnect\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class QueueStore {

	private const STALE_CLAIM_MINUTES = 5;

	private function table(): string {
		return Schema::table();
	}

	/**
	 * @throws \RuntimeException When the row cannot be written, so the caller can raise the capture alert.
	 */
	public function enqueue( Submission $submission, string $status = Schema::STATUS_QUEUED ): int {
		global $wpdb;
		$now = $this->utc_now();

		$inserted = $wpdb->insert(
			$this->table(),
			[
				'form_id'         => $submission->form_id,
				'form_name'       => $submission->form_name,
				'entry_id'        => $submission->entry_id,
				'status'          => $status,
				'payload'         => wp_json_encode( $submission->to_array() ),
				'attempts'        => 0,
				'next_attempt_at' => $status === Schema::STATUS_QUEUED ? $now : null,
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);

		if ( $inserted === false ) {
			throw new \RuntimeException( 'Could not store submission: ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/** @return QueueItem[] */
	public function claim_due( int $limit ): array {
		global $wpdb;
		$table = $this->table();
		$now   = $this->utc_now();
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::STALE_CLAIM_MINUTES * MINUTE_IN_SECONDS );
		$token = wp_generate_uuid4();

		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, claim_token = %s, updated_at = %s
				 WHERE ( ( status IN ( %s, %s ) AND ( next_attempt_at IS NULL OR next_attempt_at <= %s ) )
				         OR ( status = %s AND updated_at <= %s ) )
				 ORDER BY id ASC
				 LIMIT %d",
				Schema::STATUS_SENDING,
				$token,
				$now,
				Schema::STATUS_QUEUED,
				Schema::STATUS_FAILED,
				$now,
				Schema::STATUS_SENDING,
				$stale,
				$limit
			)
		);

		if ( ! $claimed ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE claim_token = %s ORDER BY id ASC", $token )
		);

		return array_map( [ QueueItem::class, 'from_row' ], $rows ?: [] );
	}

	public function mark_sent( int $id, array $request, array $response ): void {
		$this->update(
			$id,
			[
				'status'       => Schema::STATUS_SENT,
				'crm_request'  => wp_json_encode( $request ),
				'crm_response' => wp_json_encode( $response ),
				'last_error'   => null,
				'claim_token'  => null,
				'completed'    => 0,
				'completed_at' => $this->utc_now(),
			]
		);
	}

	public function mark_retry( int $id, int $attempts, string $error, string $next_attempt_at, int $completed = 0 ): void {
		$this->update(
			$id,
			[
				'status'          => Schema::STATUS_FAILED,
				'attempts'        => $attempts,
				'completed'       => $completed,
				'last_error'      => $error,
				'claim_token'     => null,
				'next_attempt_at' => $next_attempt_at,
			]
		);
	}

	/** A rate limit is the CRM asking us to wait, not a failed attempt, so attempts stay unchanged. */
	public function mark_rate_limited( int $id, string $error, string $next_attempt_at, int $completed = 0 ): void {
		$this->update(
			$id,
			[
				'status'          => Schema::STATUS_FAILED,
				'completed'       => $completed,
				'last_error'      => $error,
				'claim_token'     => null,
				'next_attempt_at' => $next_attempt_at,
			]
		);
	}

	public function mark_unmapped( int $id ): void {
		$this->update(
			$id,
			[
				'status'          => Schema::STATUS_UNMAPPED,
				'next_attempt_at' => null,
				'claim_token'     => null,
			]
		);
	}

	public function mark_dead_letter( int $id, int $attempts, string $error, int $completed = 0 ): void {
		$this->update(
			$id,
			[
				'status'      => Schema::STATUS_DEAD_LETTER,
				'attempts'    => $attempts,
				'completed'   => $completed,
				'last_error'  => $error,
				'claim_token' => null,
			]
		);
	}

	/** @param int[] $ids */
	public function release( array $ids, int $delay = 0 ): void {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return;
		}

		$table        = $this->table();
		$next         = gmdate( 'Y-m-d H:i:s', time() + max( 0, $delay ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, claim_token = NULL, next_attempt_at = %s, updated_at = %s
				 WHERE id IN ( {$placeholders} )",
				array_merge( [ Schema::STATUS_QUEUED, $next, $this->utc_now() ], $ids )
			)
		);
	}

	/**
	 * Keeps claimed rows from looking stale while a long batch is still working through them.
	 *
	 * @param int[] $ids
	 */
	public function touch( array $ids ): void {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return;
		}

		$table        = $this->table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET updated_at = %s WHERE status = %s AND id IN ( {$placeholders} )",
				array_merge( [ $this->utc_now(), Schema::STATUS_SENDING ], $ids )
			)
		);
	}

	public function requeue( int $id ): void {
		$this->update(
			$id,
			[
				'status'          => Schema::STATUS_QUEUED,
				'attempts'        => 0,
				'next_attempt_at' => $this->utc_now(),
				'last_error'      => null,
				'claim_token'     => null,
			]
		);
	}

	public function get( int $id ): ?QueueItem {
		global $wpdb;
		$table = $this->table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		return $row ? QueueItem::from_row( $row ) : null;
	}

	/** @return object[] raw rows for display */
	public function list_rows( ?string $status, int $limit, int $offset ): array {
		global $wpdb;
		$table = $this->table();

		if ( $status !== null && $status !== '' ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d",
					$status,
					$limit,
					$offset
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
					$limit,
					$offset
				)
			);
		}

		return $rows ?: [];
	}

	public function total( ?string $status ): int {
		global $wpdb;
		$table = $this->table();

		if ( $status !== null && $status !== '' ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
			);
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/** @return array<string,int> status => count */
	public function status_counts(): array {
		global $wpdb;
		$table = $this->table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	public function requeue_failed(): int {
		global $wpdb;
		$table = $this->table();

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, attempts = 0, next_attempt_at = %s, last_error = NULL, updated_at = %s
				 WHERE status IN ( %s, %s )",
				Schema::STATUS_QUEUED,
				$this->utc_now(),
				$this->utc_now(),
				Schema::STATUS_FAILED,
				Schema::STATUS_DEAD_LETTER
			)
		);
	}

	public function count_by_status( string $status ): int {
		global $wpdb;
		$table = $this->table();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
		);
	}

	/** Dead letters hold the only copy of a lead the CRM rejected, so they go only when the caller opts in. */
	public function purge_older_than( int $days, bool $include_dead_letters = false ): int {
		global $wpdb;
		$table    = $this->table();
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$statuses = $include_dead_letters
			? [ Schema::STATUS_SENT, Schema::STATUS_DEAD_LETTER ]
			: [ Schema::STATUS_SENT ];
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status IN ( {$placeholders} ) AND created_at < %s",
				array_merge( $statuses, [ $cutoff ] )
			)
		);
	}

	/** Removes the stored submission, payload and CRM exchange. A row being sent is left alone. */
	public function erase( int $id ): bool {
		global $wpdb;
		$table = $this->table();

		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND status <> %s", $id, Schema::STATUS_SENDING )
		);

		return $deleted === 1;
	}

	private function update( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = $this->utc_now();
		$wpdb->update( $this->table(), $data, [ 'id' => $id ] );
	}

	private function utc_now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
