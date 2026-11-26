<?php
/**
 * The capability list and the handlers a plugin has for it.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every act a person can take in Pack-Out Spot, and the code that performs it.
 *
 * The rows are in `capabilities.php`. A plugin names a handler for a row
 * where it registers the code that does the act:
 *
 *     Capability_Map::handle( 'mission.complete', array( Missions::class, 'complete' ) );
 *
 * `Parity::check()` reads both and says where the plugin falls short of the
 * list.
 */
class Capability_Map {

	/**
	 * The rows, once read.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $rows = null;

	/**
	 * Handlers by row id.
	 *
	 * @var array<string, mixed>
	 */
	private static $handlers = array();

	/**
	 * Every row, keyed by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function rows(): array {
		if ( null === self::$rows ) {
			$rows       = require PACKOUT_CORE_DIR . '/capabilities.php';
			self::$rows = is_array( $rows ) ? $rows : array();
		}
		return self::$rows;
	}

	/**
	 * One row, or null when there is no such id.
	 *
	 * @param string $id Row id.
	 * @return array<string, mixed>|null
	 */
	public static function row( string $id ): ?array {
		$rows = self::rows();
		return $rows[ $id ] ?? null;
	}

	/**
	 * Name the code that performs an act.
	 *
	 * @param string $id      Row id.
	 * @param mixed  $handler A callable. It is not called here.
	 */
	public static function handle( string $id, $handler ): void {
		self::$handlers[ $id ] = $handler;
	}

	/**
	 * Name the handlers for several acts at once.
	 *
	 * @param array<string, mixed> $handlers Row id to callable.
	 */
	public static function handle_all( array $handlers ): void {
		foreach ( $handlers as $id => $handler ) {
			self::handle( (string) $id, $handler );
		}
	}

	/**
	 * The handler for an act, or null.
	 *
	 * @param string $id Row id.
	 * @return mixed
	 */
	public static function handler( string $id ) {
		return self::$handlers[ $id ] ?? null;
	}

	/**
	 * Every handler, keyed by row id.
	 *
	 * @return array<string, mixed>
	 */
	public static function handlers(): array {
		return self::$handlers;
	}

	/**
	 * Remove one handler.
	 *
	 * @param string $id Row id.
	 */
	public static function forget( string $id ): void {
		unset( self::$handlers[ $id ] );
	}

	/**
	 * Remove every handler and read the rows again next time. For tests.
	 */
	public static function reset(): void {
		self::$handlers = array();
		self::$rows     = null;
	}
}
