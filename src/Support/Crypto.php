<?php

namespace CrmConnect\Support;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	private const PREFIX        = 'v2:';
	private const LEGACY_METHOD = 'aes-256-cbc';

	/**
	 * Authenticated encryption (XSalsa20-Poly1305). The key comes from AUTH_KEY and
	 * AUTH_SALT in wp-config.php, so a database leak alone does not expose the value.
	 *
	 * @throws \RuntimeException When wp-config.php has no usable AUTH_KEY and AUTH_SALT.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( $plaintext === '' ) {
			return '';
		}

		$key    = self::key();
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		sodium_memzero( $key );

		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	public static function decrypt( string $payload ): string {
		if ( ! self::is_current( $payload ) ) {
			return self::decrypt_legacy( $payload );
		}

		$raw = base64_decode( substr( $payload, strlen( self::PREFIX ) ), true );
		if ( $raw === false || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		try {
			$key = self::key();
		} catch ( \RuntimeException $e ) {
			return '';
		}

		$plaintext = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$key
		);
		sodium_memzero( $key );

		return $plaintext === false ? '' : $plaintext;
	}

	public static function is_current( string $payload ): bool {
		return strncmp( $payload, self::PREFIX, strlen( self::PREFIX ) ) === 0;
	}

	/**
	 * Reads values saved before v2: AES-CBC without a MAC, or plain base64 when
	 * OpenSSL was missing. Kept only so existing installs can decrypt and re-save.
	 */
	private static function decrypt_legacy( string $payload ): string {
		$raw = base64_decode( $payload, true );
		if ( $raw === false ) {
			return '';
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $raw;
		}

		$ivlen = openssl_cipher_iv_length( self::LEGACY_METHOD );
		if ( strlen( $raw ) <= $ivlen ) {
			return '';
		}

		$salt      = defined( 'AUTH_KEY' ) ? AUTH_KEY : __DIR__;
		$plaintext = openssl_decrypt( substr( $raw, $ivlen ), self::LEGACY_METHOD, hash( 'sha256', $salt, true ), OPENSSL_RAW_DATA, substr( $raw, 0, $ivlen ) );

		return $plaintext === false ? '' : $plaintext;
	}

	private static function key(): string {
		foreach ( [ 'AUTH_KEY', 'AUTH_SALT' ] as $name ) {
			$value = defined( $name ) ? (string) constant( $name ) : '';
			if ( $value === '' || $value === 'put your unique phrase here' ) {
				throw new \RuntimeException( sprintf( '%s is missing or still the default in wp-config.php.', $name ) );
			}
		}

		return sodium_crypto_generichash( AUTH_KEY . AUTH_SALT, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
