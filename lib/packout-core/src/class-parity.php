<?php
/**
 * The parity check.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares the capability list with the handlers a plugin has.
 *
 * Both plugins run the same check in their tests. That check is what the
 * promise "the partner plugin keeps up with the hub" rests on.
 */
class Parity {

	public const SURFACES = array( 'both', 'hub', 'partner' );

	/**
	 * Check the plugin that is running.
	 *
	 * @param Contracts\Site $site The site adapter.
	 * @return array<int, array{code: string, id: string, message: string}> Problems. Empty when the plugin keeps the list.
	 */
	public static function check_site( Contracts\Site $site ): array {
		return self::check( Capability_Map::rows(), Capability_Map::handlers(), $site->surface(), $site->phase() );
	}

	/**
	 * Compare rows with handlers.
	 *
	 * @param array<string, array<string, mixed>> $rows     The capability rows.
	 * @param array<string, mixed>                $handlers Row id to callable.
	 * @param string                              $surface  The plugin's side: `hub` or `partner`.
	 * @param int                                 $phase    The last phase the plugin has reached.
	 * @return array<int, array{code: string, id: string, message: string}>
	 */
	public static function check( array $rows, array $handlers, string $surface, int $phase ): array {
		$problems = self::malformed( $rows );
		if ( 'hub' !== $surface && 'partner' !== $surface ) {
			$problems[] = self::problem( 'surface', '', 'A plugin is the hub or a partner site, not “' . $surface . '”.' );
			return $problems;
		}
		$other = 'hub' === $surface ? 'partner' : 'hub';

		foreach ( $rows as $id => $row ) {
			$id    = (string) $id;
			$where = (string) ( $row['surface'] ?? '' );
			$has   = array_key_exists( $id, $handlers );

			if ( $where === $other && $has ) {
				$problems[] = self::problem( 'foreign', $id, 'This act belongs to the ' . $other . ' alone, and this plugin has a handler for it.' );
				continue;
			}
			if ( 'both' !== $where && $where !== $surface ) {
				continue;
			}
			$since = $row['since'][ $surface ] ?? null;
			if ( ! $has && is_int( $since ) && $since <= $phase ) {
				$problems[] = self::problem( 'missing', $id, 'No handler. The list says this plugin has had this act since phase ' . $since . '.' );
			}
		}

		foreach ( $handlers as $id => $handler ) {
			$id = (string) $id;
			if ( ! isset( $rows[ $id ] ) ) {
				$problems[] = self::problem( 'unknown', $id, 'A handler names an act that is not in the list. Add the row first.' );
				continue;
			}
			if ( ! is_callable( $handler ) ) {
				$problems[] = self::problem( 'uncallable', $id, 'The handler is not something that can be called.' );
			}
		}

		return $problems;
	}

	/**
	 * Rows that are not well formed.
	 *
	 * @param array<string, array<string, mixed>> $rows The capability rows.
	 * @return array<int, array{code: string, id: string, message: string}>
	 */
	public static function malformed( array $rows ): array {
		$problems = array();
		foreach ( $rows as $id => $row ) {
			$id = (string) $id;
			if ( 1 !== preg_match( '/^[a-z]+(?:\.[a-z]+)+$/', $id ) ) {
				$problems[] = self::problem( 'id', $id, 'An id is lowercase words joined by dots.' );
			}
			if ( ! is_array( $row ) ) {
				$problems[] = self::problem( 'row', $id, 'A row is an array.' );
				continue;
			}
			foreach ( array( 'group', 'act', 'who' ) as $field ) {
				if ( ! isset( $row[ $field ] ) || ! is_string( $row[ $field ] ) || '' === trim( $row[ $field ] ) ) {
					$problems[] = self::problem( 'field', $id, 'The row has no ' . $field . '.' );
				}
			}
			$surface = $row['surface'] ?? '';
			if ( ! in_array( $surface, self::SURFACES, true ) ) {
				$problems[] = self::problem( 'surface', $id, 'The surface is both, hub, or partner.' );
				continue;
			}
			$reason = isset( $row['reason'] ) && is_string( $row['reason'] ) ? trim( $row['reason'] ) : '';
			if ( 'both' !== $surface && '' === $reason ) {
				$problems[] = self::problem( 'reason', $id, 'An act only one plugin has needs a reason.' );
			}
			$since = isset( $row['since'] ) && is_array( $row['since'] ) ? $row['since'] : array();
			foreach ( array( 'hub', 'partner' ) as $side ) {
				$expected = 'both' === $surface || $side === $surface;
				$given    = array_key_exists( $side, $since );
				if ( $expected && ( ! $given || ! is_int( $since[ $side ] ) || $since[ $side ] < 0 || $since[ $side ] > 12 ) ) {
					$problems[] = self::problem( 'since', $id, 'The row does not say which phase gives the ' . $side . ' this act.' );
				}
				if ( ! $expected && $given ) {
					$problems[] = self::problem( 'since', $id, 'The row gives a phase for the ' . $side . ', which never has this act.' );
				}
			}
		}//end foreach
		return $problems;
	}

	/**
	 * How many rows each surface has.
	 *
	 * @param array<string, array<string, mixed>> $rows The capability rows.
	 * @return array{total: int, both: int, hub: int, partner: int}
	 */
	public static function counts( array $rows ): array {
		$counts = array(
			'total'   => count( $rows ),
			'both'    => 0,
			'hub'     => 0,
			'partner' => 0,
		);
		foreach ( $rows as $row ) {
			$surface = is_array( $row ) ? ( $row['surface'] ?? '' ) : '';
			if ( isset( $counts[ $surface ] ) ) {
				++$counts[ $surface ];
			}
		}
		return $counts;
	}

	/**
	 * One problem.
	 *
	 * @param string $code    What kind of problem.
	 * @param string $id      The row or handler id.
	 * @param string $message What is wrong, for a developer.
	 * @return array{code: string, id: string, message: string}
	 */
	private static function problem( string $code, string $id, string $message ): array {
		return array(
			'code'    => $code,
			'id'      => $id,
			'message' => $message,
		);
	}
}
