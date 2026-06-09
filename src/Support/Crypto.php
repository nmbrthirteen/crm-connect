<?php

namespace CrmConnect\Support;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	private const METHOD = 'aes-256-cbc';

	public static function encrypt( string $plaintext ): string {
		if ( $plaintext === '' || ! function_exists( 'openssl_encrypt' ) ) {
			return base64_encode( $plaintext );
		}
		$iv     = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::METHOD ) );
		$cipher = openssl_encrypt( $plaintext, self::METHOD, self::key(), OPENSSL_RAW_DATA, $iv );
		return base64_encode( $iv . $cipher );
	}

	public static function decrypt( string $payload ): string {
		$raw = base64_decode( $payload, true );
		if ( $raw === false ) {
			return '';
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $raw;
		}
		$ivlen = openssl_cipher_iv_length( self::METHOD );
		if ( strlen( $raw ) <= $ivlen ) {
			return '';
		}
		$iv        = substr( $raw, 0, $ivlen );
		$cipher    = substr( $raw, $ivlen );
		$plaintext = openssl_decrypt( $cipher, self::METHOD, self::key(), OPENSSL_RAW_DATA, $iv );
		return $plaintext === false ? '' : $plaintext;
	}

	private static function key(): string {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : __DIR__;
		return hash( 'sha256', $salt, true );
	}
}
