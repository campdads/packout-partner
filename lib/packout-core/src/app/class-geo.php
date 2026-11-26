<?php
/**
 * Coordinate helpers.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Distance, bounding boxes, and the coarse public location.
 */
class Geo {

	/**
	 * Size of the grid a coarse location snaps to, in degrees.
	 *
	 * 0.02 degrees of latitude is about 2.2 kilometers.
	 */
	public const COARSE_GRID = 0.02;

	/**
	 * Keep a number inside a range.
	 *
	 * @param float $value Number.
	 * @param float $min   Lowest allowed value.
	 * @param float $max   Highest allowed value.
	 */
	public static function clamp( float $value, float $min, float $max ): float {
		return max( $min, min( $max, $value ) );
	}

	/**
	 * A latitude and longitude pair, or null when either is missing or out of range.
	 *
	 * @param mixed $lat Latitude.
	 * @param mixed $lng Longitude.
	 * @return array{0: float, 1: float}|null
	 */
	public static function point( $lat, $lng ): ?array {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}
		$lat = (float) $lat;
		$lng = (float) $lng;
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}
		return array( round( $lat, 6 ), round( $lng, 6 ) );
	}

	/**
	 * Distance between two points in meters.
	 *
	 * @param float $lat1 First latitude.
	 * @param float $lng1 First longitude.
	 * @param float $lat2 Second latitude.
	 * @param float $lng2 Second longitude.
	 */
	public static function distance( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$radius = 6371000.0;
		$d_lat  = deg2rad( $lat2 - $lat1 );
		$d_lng  = deg2rad( $lng2 - $lng1 );
		$a      = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lng / 2 ) ** 2;
		return $radius * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	/**
	 * The public location of a coarse Spot.
	 *
	 * The point snaps to the center of a fixed grid cell. The same pin always
	 * gives the same answer, so repeated requests cannot be averaged back to
	 * the stored location.
	 *
	 * @param float $lat Stored latitude.
	 * @param float $lng Stored longitude.
	 * @return array{0: float, 1: float}
	 */
	public static function coarse( float $lat, float $lng ): array {
		$grid = self::COARSE_GRID;
		return array(
			round( ( floor( $lat / $grid ) + 0.5 ) * $grid, 4 ),
			round( ( floor( $lng / $grid ) + 0.5 ) * $grid, 4 ),
		);
	}

	/**
	 * Parse "west,south,east,north".
	 *
	 * @param mixed $raw Bounding box text.
	 * @return array{west: float, south: float, east: float, north: float}|null
	 */
	public static function bbox( $raw ): ?array {
		$parts = is_string( $raw ) ? explode( ',', $raw ) : array();
		if ( 4 !== count( $parts ) ) {
			return null;
		}
		foreach ( $parts as $part ) {
			if ( ! is_numeric( trim( $part ) ) ) {
				return null;
			}
		}
		list( $west, $south, $east, $north ) = array_map( 'floatval', $parts );
		if ( $west >= $east || $south >= $north ) {
			return null;
		}
		return array(
			'west'  => $west,
			'south' => $south,
			'east'  => $east,
			'north' => $north,
		);
	}

	/**
	 * The box around a shape: a ring, a line, or any nesting of longitude and latitude pairs.
	 *
	 * @param mixed $coordinates Pairs of longitude and latitude, nested to any depth.
	 * @param int   $depth       How deep the nesting is so far.
	 * @return array{west: float, south: float, east: float, north: float}|null
	 */
	public static function bounds( $coordinates, int $depth = 0 ): ?array {
		if ( ! is_array( $coordinates ) || $depth > 6 ) {
			return null;
		}
		if ( isset( $coordinates[0], $coordinates[1] ) && is_numeric( $coordinates[0] ) && is_numeric( $coordinates[1] ) ) {
			return array(
				'west'  => (float) $coordinates[0],
				'south' => (float) $coordinates[1],
				'east'  => (float) $coordinates[0],
				'north' => (float) $coordinates[1],
			);
		}
		$box = null;
		foreach ( $coordinates as $part ) {
			$inner = self::bounds( $part, $depth + 1 );
			if ( $inner ) {
				$box = $box ? array(
					'west'  => min( $box['west'], $inner['west'] ),
					'south' => min( $box['south'], $inner['south'] ),
					'east'  => max( $box['east'], $inner['east'] ),
					'north' => max( $box['north'], $inner['north'] ),
				) : $inner;
			}
		}
		return $box;
	}

	/**
	 * Whether a shape reaches into a bounding box, by the box around it.
	 *
	 * @param mixed                                                       $coordinates Pairs of longitude and latitude, nested to any depth.
	 * @param array{west: float, south: float, east: float, north: float} $bbox        Bounding box.
	 */
	public static function reaches( $coordinates, array $bbox ): bool {
		$box = self::bounds( $coordinates );
		return null !== $box && $box['west'] <= $bbox['east'] && $box['east'] >= $bbox['west'] && $box['south'] <= $bbox['north'] && $box['north'] >= $bbox['south'];
	}

	/**
	 * Whether a point is inside a bounding box.
	 *
	 * @param float                                                       $lat  Latitude.
	 * @param float                                                       $lng  Longitude.
	 * @param array{west: float, south: float, east: float, north: float} $bbox Bounding box.
	 */
	public static function inside( float $lat, float $lng, array $bbox ): bool {
		return $lat >= $bbox['south'] && $lat <= $bbox['north'] && $lng >= $bbox['west'] && $lng <= $bbox['east'];
	}
}
