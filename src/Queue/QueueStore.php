<?php

namespace CrmConnect\Queue;

use CrmConnect\Capture\Submission;
use CrmConnect\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class QueueStore {

	private function table(): string {
		return Schema::table();
	}

	public function enqueue( Submission $submission ): int {
		global $wpdb;
		$now = $this->utc_now();

		$wpdb->insert(
			$this->table(),
			[
				'form_id'         => $submission->form_id,
				'form_name'       => $submission->form_name,
				'entry_id'        => $submission->entry_id,
				'status'          => Schema::STATUS_QUEUED,
				'payload'         => wp_json_encode( $submission->to_array() ),
				'attempts'        => 0,
				'next_attempt_at' => $this->utc_now(),
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);

		return (int) $wpdb->insert_id;
	}

	/** @return QueueItem[] */
	public function claim_due( int $limit ): array {
		global $wpdb;
		$table = $this->table();
		$now   = $this->utc_now();
		$stale = gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS );
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

	public function requeue( int $id ): void {
		$this->update(
			$id,
			[
				'status'          => Schema::STATUS_QUEUED,
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
				"UPDATE {$table} SET status = %s, next_attempt_at = %s, last_error = NULL, updated_at = %s
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

	public function consecutive_dead_letters( int $window ): int {
		global $wpdb;
		$table  = $this->table();
		$states = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT status FROM {$table}
				 WHERE status IN ( %s, %s )
				 ORDER BY updated_at DESC
				 LIMIT %d",
				Schema::STATUS_SENT,
				Schema::STATUS_DEAD_LETTER,
				$window
			)
		);

		$count = 0;
		foreach ( $states as $status ) {
			if ( $status === Schema::STATUS_DEAD_LETTER ) {
				$count++;
			} else {
				break;
			}
		}
		return $count;
	}

	public function purge_older_than( int $days ): int {
		global $wpdb;
		$table  = $this->table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status IN ( %s, %s ) AND created_at < %s",
				Schema::STATUS_SENT,
				Schema::STATUS_DEAD_LETTER,
				$cutoff
			)
		);
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
