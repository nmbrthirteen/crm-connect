<?php

namespace CrmConnect\Support;

defined( 'ABSPATH' ) || exit;

final class EventLog {

	private const OPTION = 'crm_connect_events';
	private const MAX    = 200;

	public static function error( string $message ): void {
		self::add( 'error', $message );
	}

	public static function warning( string $message ): void {
		self::add( 'warning', $message );
	}

	public static function info( string $message ): void {
		self::add( 'info', $message );
	}

	/** @return array<int,array{time:string,level:string,message:string}> */
	public static function all(): array {
		$events = get_option( self::OPTION, [] );
		return is_array( $events ) ? $events : [];
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	private static function add( string $level, string $message ): void {
		$events = self::all();
		array_unshift(
			$events,
			[
				'time'    => gmdate( 'Y-m-d H:i:s' ),
				'level'   => $level,
				'message' => $message,
			]
		);
		if ( count( $events ) > self::MAX ) {
			$events = array_slice( $events, 0, self::MAX );
		}
		update_option( self::OPTION, $events, false );
	}
}
