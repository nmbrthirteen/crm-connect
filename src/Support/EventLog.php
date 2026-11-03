<?php

namespace CrmConnect\Support;

defined( 'ABSPATH' ) || exit;

final class EventLog {

	private const OPTION = 'crm_connect_events';
	private const MAX    = 200;

	public static function error( string $message, ?int $submission_id = null ): void {
		self::add( 'error', $message, $submission_id );
	}

	public static function warning( string $message, ?int $submission_id = null ): void {
		self::add( 'warning', $message, $submission_id );
	}

	public static function info( string $message, ?int $submission_id = null ): void {
		self::add( 'info', $message, $submission_id );
	}

	/** Drops log rows that quote a submission, which can carry its field values. */
	public static function forget_submission( int $submission_id ): void {
		$events = self::all();
		$kept   = array_values(
			array_filter(
				$events,
				static fn ( $event ): bool => (int) ( $event['submission_id'] ?? 0 ) !== $submission_id
			)
		);

		if ( count( $kept ) !== count( $events ) ) {
			update_option( self::OPTION, $kept, false );
		}
	}

	/** @return array<int,array{time:string,level:string,message:string,submission_id?:int}> */
	public static function all(): array {
		$events = get_option( self::OPTION, [] );
		return is_array( $events ) ? $events : [];
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	private static function add( string $level, string $message, ?int $submission_id = null ): void {
		$events = self::all();
		$event  = [
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'level'   => $level,
			'message' => $message,
		];
		if ( $submission_id !== null ) {
			$event['submission_id'] = $submission_id;
		}
		array_unshift( $events, $event );
		if ( count( $events ) > self::MAX ) {
			$events = array_slice( $events, 0, self::MAX );
		}
		update_option( self::OPTION, $events, false );
	}
}
