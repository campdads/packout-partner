<?php
/**
 * The keys a Pack-Out Spot release is signed with.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Says where releases come from and checks that a package is one of them.
 *
 * A release is signed with an Ed25519 key. The signature covers the bytes of
 * the package file and is written as 128 hexadecimal characters. The public
 * half of the key is below; the secret half never leaves the release
 * pipeline.
 */
class Release {

	/**
	 * The site whose catalog publishes Pack-Out Spot's plugins.
	 */
	public const HOST = 'packout.spot';

	/**
	 * That catalog's address.
	 */
	public const ORIGIN = 'https://packout.spot';

	/**
	 * Public keys, as hexadecimal. A package signed by any of them is
	 * accepted.
	 *
	 * The list has room for a second key so that a key can be replaced
	 * without stranding a site: a release that lists both goes out first,
	 * and later releases are signed with the new one. The pipeline signs
	 * with the first.
	 */
	public const PUBLIC_KEYS = array(
		'f5ca2b1da9adaaea5e130c2574769cca399d411a1c9632fde1963f09a7f2f018',
	);

	/**
	 * The keys this site accepts.
	 *
	 * On a site whose environment type is `local`, the
	 * `packout_release_public_keys` filter can add a key, so a test can sign
	 * a package of its own. Everywhere else the list is fixed.
	 *
	 * @return array<int, string>
	 */
	public static function public_keys(): array {
		$keys = self::PUBLIC_KEYS;
		if ( self::is_local() && function_exists( 'apply_filters' ) ) {
			/**
			 * Public keys accepted on a local site.
			 *
			 * @param array<int, string> $keys Hexadecimal Ed25519 public keys.
			 */
			$filtered = apply_filters( 'packout_release_public_keys', $keys );
			if ( is_array( $filtered ) ) {
				$keys = $filtered;
			}
		}
		$valid = array();
		foreach ( $keys as $key ) {
			if ( is_string( $key ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $key ) ) {
				$valid[] = $key;
			}
		}
		return array_values( array_unique( $valid ) );
	}

	/**
	 * Whether a signature over these bytes was made by one of the keys.
	 *
	 * @param string $bytes     The package file's contents.
	 * @param string $signature 128 hexadecimal characters.
	 */
	public static function verify( string $bytes, string $signature ): bool {
		$signature = strtolower( trim( $signature ) );
		if ( '' === $bytes || 1 !== preg_match( '/^[0-9a-f]{128}$/', $signature ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		$raw = sodium_hex2bin( $signature );
		foreach ( self::public_keys() as $key ) {
			try {
				if ( sodium_crypto_sign_verify_detached( $raw, $bytes, sodium_hex2bin( $key ) ) ) {
					return true;
				}
			} catch ( \Throwable $error ) {
				continue;
			}
		}
		return false;
	}

	/**
	 * Whether these bytes have this SHA-256.
	 *
	 * @param string $bytes    The package file's contents.
	 * @param string $checksum 64 hexadecimal characters.
	 */
	public static function checksum_matches( string $bytes, string $checksum ): bool {
		$checksum = strtolower( trim( $checksum ) );
		return 1 === preg_match( '/^[0-9a-f]{64}$/', $checksum ) && hash_equals( $checksum, hash( 'sha256', $bytes ) );
	}

	/**
	 * Whether this site is a developer's own.
	 */
	public static function is_local(): bool {
		return function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type();
	}
}
