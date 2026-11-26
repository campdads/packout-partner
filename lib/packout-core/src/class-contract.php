<?php
/**
 * The contract between a partner site and the hub: which version each
 * speaks, and whether a payload has the shape its schema gives.
 *
 * The schemas are the JSON files in `contract/v1/`. Both plugins carry the
 * same files, so the two ends check a payload against the same words. A
 * schema lists every field. A field it does not list is a fault, which is
 * how a person's email address in an unknown field is refused.
 *
 * The check understands the part of JSON Schema those files use: `type`,
 * `properties`, `required`, `additionalProperties`, `propertyNames` with a
 * pattern, `maxProperties`, `items`, `minItems`, `maxItems`, `enum`,
 * `minLength`, `maxLength`, `pattern`, `minimum`, `maximum`, and `$ref` to a
 * definition in the same file.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Contract versions and payload shapes.
 */
final class Contract {

	/**
	 * The most items one batch may hold.
	 */
	public const BATCH = 200;

	/**
	 * The most Spots one batch may hold.
	 */
	public const SPOTS = 50;

	/**
	 * The most bytes an image may be.
	 */
	public const IMAGE = 26214400;

	/**
	 * The most bytes a JSON body may be.
	 */
	public const BODY = 1048576;

	/**
	 * How deep a payload may nest. Deeper is a fault, so a payload cannot
	 * make the check run away.
	 */
	private const DEPTH = 12;

	/**
	 * Schemas already read, by name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $schemas = array();

	/**
	 * The version this build speaks.
	 */
	public static function version(): int {
		return defined( 'PACKOUT_CORE_CONTRACT' ) ? (int) PACKOUT_CORE_CONTRACT : 1;
	}

	/**
	 * Whether the hub accepts a site's version: the current one and the one before it.
	 *
	 * @param mixed    $theirs  The version a request names.
	 * @param int|null $current The hub's version. This build's when left out.
	 */
	public static function accepts( $theirs, ?int $current = null ): bool {
		$current = null === $current ? self::version() : $current;
		return is_int( $theirs ) && $theirs <= $current && $theirs >= $current - 1 && $theirs >= 1;
	}

	/**
	 * The directory that holds a version's schemas.
	 *
	 * @param int $version The contract version.
	 */
	public static function directory( int $version = 1 ): string {
		return dirname( __DIR__ ) . '/contract/v' . $version;
	}

	/**
	 * A schema, by name.
	 *
	 * @param string $name `register`, `profile`, `listings`, `stats`, or `key`.
	 * @return array<string, mixed>|null
	 */
	public static function schema( string $name ): ?array {
		if ( 1 !== preg_match( '/^[a-z-]+$/', $name ) ) {
			return null;
		}
		if ( ! isset( self::$schemas[ $name ] ) ) {
			$file = self::directory() . '/' . $name . '.json';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file the library carries.
			$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
			if ( ! is_array( $data ) ) {
				return null;
			}
			self::$schemas[ $name ] = $data;
		}
		return self::$schemas[ $name ];
	}

	/**
	 * Check a payload against a schema.
	 *
	 * @param string $name    The schema's name.
	 * @param mixed  $payload The decoded payload, objects as arrays.
	 * @return string Empty when it fits. Otherwise the path of the first fault, as `items/3/title`, or `/` for the payload itself.
	 */
	public static function fault( string $name, $payload ): string {
		$schema = self::schema( $name );
		if ( null === $schema ) {
			return '/';
		}
		return self::fault_in( $schema, $payload );
	}

	/**
	 * Check a payload against a schema that is given, and not one this build carries.
	 *
	 * The compatibility test uses it to ask what an earlier version of the hub would have said.
	 *
	 * @param array<string, mixed> $schema  The whole schema.
	 * @param mixed                $payload The decoded payload, objects as arrays.
	 * @return string Empty when it fits. Otherwise the path of the first fault, or `/` for the payload itself.
	 */
	public static function fault_in( array $schema, $payload ): string {
		$fault = self::check( $schema, $payload, $schema, '', 0 );
		return null === $fault ? '' : ( '' === $fault ? '/' : $fault );
	}

	/**
	 * Check one value against one schema node.
	 *
	 * @param array<string, mixed> $node  The schema node.
	 * @param mixed                $value The value.
	 * @param array<string, mixed> $root  The whole schema, for `$ref`.
	 * @param string               $path  Where the value is.
	 * @param int                  $depth How deep the value is.
	 * @return string|null The path of the first fault, or null.
	 */
	private static function check( array $node, $value, array $root, string $path, int $depth ): ?string {
		if ( $depth > self::DEPTH ) {
			return $path;
		}
		if ( isset( $node['$ref'] ) ) {
			$name = (string) preg_replace( '#^\#/definitions/#', '', (string) $node['$ref'] );
			$next = $root['definitions'][ $name ] ?? null;
			return is_array( $next ) ? self::check( $next, $value, $root, $path, $depth ) : $path;
		}
		// One field of an object says which of several shapes the object has.
		if ( isset( $node['select'], $node['variants'] ) ) {
			$which = is_array( $value ) ? ( $value[ (string) $node['select'] ] ?? null ) : null;
			$next  = is_string( $which ) ? ( $node['variants'][ $which ] ?? null ) : null;
			if ( ! is_array( $value ) ) {
				return $path;
			}
			return is_array( $next ) ? self::check( $next, $value, $root, $path, $depth ) : self::join( $path, (string) $node['select'] );
		}
		if ( isset( $node['type'] ) && ! self::is_type( $node['type'], $value ) ) {
			return $path;
		}
		if ( isset( $node['enum'] ) && ! in_array( $value, (array) $node['enum'], true ) ) {
			return $path;
		}
		if ( is_string( $value ) ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
			if ( ( isset( $node['maxLength'] ) && $length > $node['maxLength'] ) || ( isset( $node['minLength'] ) && $length < $node['minLength'] ) ) {
				return $path;
			}
			if ( isset( $node['pattern'] ) && 1 !== preg_match( '/' . str_replace( '/', '\/', (string) $node['pattern'] ) . '/u', $value ) ) {
				return $path;
			}
			return null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			if ( ( isset( $node['minimum'] ) && $value < $node['minimum'] ) || ( isset( $node['maximum'] ) && $value > $node['maximum'] ) ) {
				return $path;
			}
			return null;
		}
		if ( ! is_array( $value ) ) {
			return null;
		}

		$as_list = self::is_type( 'array', $value ) && ( ! self::is_type( 'object', $value ) || ! self::wants( $node, 'object' ) );
		if ( $as_list ) {
			$count = count( $value );
			if ( ( isset( $node['maxItems'] ) && $count > $node['maxItems'] ) || ( isset( $node['minItems'] ) && $count < $node['minItems'] ) ) {
				return $path;
			}
			if ( isset( $node['items'] ) && is_array( $node['items'] ) ) {
				foreach ( $value as $index => $item ) {
					$fault = self::check( $node['items'], $item, $root, self::join( $path, (string) $index ), $depth + 1 );
					if ( null !== $fault ) {
						return $fault;
					}
				}
			}
			return null;
		}

		if ( isset( $node['maxProperties'] ) && count( $value ) > $node['maxProperties'] ) {
			return $path;
		}
		foreach ( (array) ( $node['required'] ?? array() ) as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				return self::join( $path, (string) $key );
			}
		}
		$properties = (array) ( $node['properties'] ?? array() );
		$others     = $node['additionalProperties'] ?? true;
		$names      = isset( $node['propertyNames']['pattern'] ) ? '/' . str_replace( '/', '\/', (string) $node['propertyNames']['pattern'] ) . '/' : '';
		foreach ( $value as $key => $item ) {
			$key   = (string) $key;
			$where = self::join( $path, $key );
			if ( '' !== $names && 1 !== preg_match( $names, $key ) ) {
				return $where;
			}
			if ( isset( $properties[ $key ] ) && is_array( $properties[ $key ] ) ) {
				$fault = self::check( $properties[ $key ], $item, $root, $where, $depth + 1 );
			} elseif ( false === $others ) {
				return $where;
			} elseif ( is_array( $others ) ) {
				$fault = self::check( $others, $item, $root, $where, $depth + 1 );
			} else {
				$fault = null;
			}
			if ( null !== $fault ) {
				return $fault;
			}
		}
		return null;
	}

	/**
	 * Whether a schema node allows a type.
	 *
	 * @param array<string, mixed> $node The schema node.
	 * @param string               $type The type.
	 */
	private static function wants( array $node, string $type ): bool {
		return isset( $node['type'] ) && in_array( $type, (array) $node['type'], true );
	}

	/**
	 * Whether a value is of a type, or of one of several.
	 *
	 * A decoded JSON object and a decoded JSON array are both PHP arrays. An
	 * empty one is either. A list is an array whose keys are 0, 1, 2, and so on.
	 *
	 * @param string|array<int, string> $type  The type or types.
	 * @param mixed                     $value The value.
	 */
	private static function is_type( $type, $value ): bool {
		foreach ( (array) $type as $one ) {
			switch ( $one ) {
				case 'object':
					$fits = is_array( $value ) && ( array() === $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) );
					break;
				case 'array':
					$fits = is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
					break;
				case 'string':
					$fits = is_string( $value );
					break;
				case 'integer':
					$fits = is_int( $value );
					break;
				case 'number':
					$fits = is_int( $value ) || is_float( $value );
					break;
				case 'boolean':
					$fits = is_bool( $value );
					break;
				case 'null':
					$fits = null === $value;
					break;
				default:
					$fits = false;
			}//end switch
			if ( $fits ) {
				return true;
			}
		}//end foreach
		return false;
	}

	/**
	 * Add a step to a path.
	 *
	 * @param string $path The path so far.
	 * @param string $step The next step.
	 */
	private static function join( string $path, string $step ): string {
		return '' === $path ? $step : $path . '/' . $step;
	}
}
