<?php
/**
 * How a partner site signs what it sends to packout.spot, and how the hub
 * checks it.
 *
 * A site makes an Ed25519 key pair when it connects. The secret key never
 * leaves the site. The hub keeps the public key and accepts a request only
 * when its signature verifies against it.
 *
 * What is signed is five lines: the method, the path and query exactly as
 * sent, the SHA-256 of the body, the time, and a number used once. A request
 * changed on the way, sent again later, or sent twice does not pass.
 *
 * This class is rules only. It reads no option and sends nothing.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Signed requests between a partner site and the hub.
 */
final class Signing {

	/**
	 * The header that names the site: the id the hub issued.
	 */
	public const KEY = 'X-PackOut-Key';

	/**
	 * The header that holds the time of the request, in seconds, UTC.
	 */
	public const TIMESTAMP = 'X-PackOut-Timestamp';

	/**
	 * The header that holds the number used once.
	 */
	public const NONCE = 'X-PackOut-Nonce';

	/**
	 * The header that holds the signature, base64.
	 */
	public const SIGNATURE = 'X-PackOut-Signature';

	/**
	 * How far a request's time may be from the hub's clock, in seconds.
	 */
	public const WINDOW = 300;

	/**
	 * How long the hub remembers a number used once, in seconds. Longer than
	 * the window on both sides of the clock, so a number cannot come back.
	 */
	public const REMEMBER = 600;

	/**
	 * A new key pair.
	 *
	 * @return array{public: string, secret: string} Both hexadecimal.
	 */
	public static function keypair(): array {
		$pair = sodium_crypto_sign_keypair();
		return array(
			'public' => sodium_bin2hex( sodium_crypto_sign_publickey( $pair ) ),
			'secret' => sodium_bin2hex( sodium_crypto_sign_secretkey( $pair ) ),
		);
	}

	/**
	 * Whether a value is a public key as the hub stores one.
	 *
	 * @param mixed $value The value.
	 */
	public static function is_public_key( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $value );
	}

	/**
	 * Whether a value is a secret key as a site stores one.
	 *
	 * @param mixed $value The value.
	 */
	public static function is_secret_key( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{128}$/', $value );
	}

	/**
	 * A number to use once: 16 random bytes, hexadecimal.
	 */
	public static function nonce(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Whether a value is a number used once as this class makes them.
	 *
	 * @param mixed $value The value.
	 */
	public static function is_nonce( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $value );
	}

	/**
	 * The text that is signed.
	 *
	 * @param string $method    The request's method.
	 * @param string $path      The request's path and query, exactly as sent.
	 * @param string $body      The request's body. Empty for a request without one.
	 * @param int    $timestamp Seconds since the epoch, UTC.
	 * @param string $nonce     The number used once.
	 */
	public static function text( string $method, string $path, string $body, int $timestamp, string $nonce ): string {
		return implode( "\n", array( strtoupper( $method ), $path, hash( 'sha256', $body ), (string) $timestamp, $nonce ) );
	}

	/**
	 * Sign a text.
	 *
	 * @param string $text   From `text()`.
	 * @param string $secret The site's secret key, hexadecimal.
	 * @return string The signature, base64. Empty when the key is not a key.
	 */
	public static function sign( string $text, string $secret ): string {
		if ( ! self::is_secret_key( $secret ) ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A signature is sent as base64.
		return base64_encode( sodium_crypto_sign_detached( $text, sodium_hex2bin( $secret ) ) );
	}

	/**
	 * Whether a signature is that of a text, by a key.
	 *
	 * @param string $text      From `text()`.
	 * @param string $signature The signature, base64.
	 * @param string $key       The site's public key, hexadecimal.
	 */
	public static function verify( string $text, string $signature, string $key ): bool {
		if ( ! self::is_public_key( $key ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A signature is sent as base64.
		$raw = base64_decode( $signature, true );
		if ( false === $raw || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $raw ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $raw, $text, sodium_hex2bin( $key ) );
		} catch ( \SodiumException $e ) {
			return false;
		}
	}

	/**
	 * Whether a request's time is close enough to the clock.
	 *
	 * @param int $timestamp The request's time.
	 * @param int $now       The clock.
	 */
	public static function fresh( int $timestamp, int $now ): bool {
		return abs( $now - $timestamp ) <= self::WINDOW;
	}

	/**
	 * The four headers of a signed request.
	 *
	 * @param string $site      The id the hub issued.
	 * @param string $secret    The site's secret key, hexadecimal.
	 * @param string $method    The request's method.
	 * @param string $path      The request's path and query, exactly as sent.
	 * @param string $body      The request's body.
	 * @param int    $timestamp The time to sign.
	 * @param string $nonce     The number used once.
	 * @return array<string, string>
	 */
	public static function headers( string $site, string $secret, string $method, string $path, string $body, int $timestamp, string $nonce ): array {
		return array(
			self::KEY       => $site,
			self::TIMESTAMP => (string) $timestamp,
			self::NONCE     => $nonce,
			self::SIGNATURE => self::sign( self::text( $method, $path, $body, $timestamp, $nonce ), $secret ),
		);
	}

	/**
	 * The path and query of an address, as they are signed.
	 *
	 * @param string $url A full address.
	 */
	public static function path( string $url ): string {
		// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Rules only: this class runs without WordPress in the library's tests.
		$path  = (string) parse_url( $url, PHP_URL_PATH );
		$query = (string) parse_url( $url, PHP_URL_QUERY );
		// phpcs:enable WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return ( '' !== $path ? $path : '/' ) . ( '' !== $query ? '?' . $query : '' );
	}
}
