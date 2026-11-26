<?php
/**
 * Repeating a mission.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The days the copies of a repeated mission fall on, and what they keep.
 *
 * Repeating makes a chosen number of ordinary missions at an interval.
 * Nothing links them afterward and no recurrence rule is stored. This class
 * reads nothing from WordPress.
 */
final class Repeat {

	/**
	 * The most copies one repeat makes.
	 */
	public const MAX_COPIES = 12;

	/**
	 * The longest interval, in weeks.
	 */
	public const MAX_WEEKS = 12;

	/**
	 * What a copy may carry over from the mission it repeats.
	 */
	public const CARRY = array( 'checklist', 'gear', 'meeting', 'teams', 'campaign', 'program', 'resources' );

	/**
	 * An interval: a number of weeks from `1` to `12`, or `month`. Empty when it is neither.
	 *
	 * @param mixed $value What was given.
	 */
	public static function every( $value ): string {
		if ( 'month' === $value ) {
			return 'month';
		}
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$weeks = (int) $value;
			return $weeks >= 1 && $weeks <= self::MAX_WEEKS ? (string) $weeks : '';
		}
		return '';
	}

	/**
	 * A number of copies from 1 to 12, or zero when it is not one.
	 *
	 * @param mixed $value What was given.
	 */
	public static function copies( $value ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return 0;
		}
		$copies = (int) $value;
		return $copies >= 1 && $copies <= self::MAX_COPIES ? $copies : 0;
	}

	/**
	 * What a copy carries over, from what was ticked.
	 *
	 * @param mixed $value A list of names.
	 * @return array<int, string>
	 */
	public static function carry( $value ): array {
		$named = is_array( $value ) ? $value : array();
		return array_values( array_filter( self::CARRY, static fn( string $part ): bool => in_array( $part, $named, true ) ) );
	}

	/**
	 * Why a mission cannot be repeated as asked, or an empty string when it can.
	 *
	 * @param string $start  The mission's first day, as stored.
	 * @param string $every  From every().
	 * @param int    $copies From copies().
	 * @return string Empty, `undated`, `every`, or `copies`.
	 */
	public static function refusal( string $start, string $every, int $copies ): string {
		if ( '' === Schedule::day( $start ) ) {
			return 'undated';
		}
		if ( '' === $every ) {
			return 'every';
		}
		return $copies < 1 ? 'copies' : '';
	}

	/**
	 * The window of each copy.
	 *
	 * A copy lasts as many days as the mission it repeats. Monthly copies
	 * fall on the same day of the month, or on the month's last day when it
	 * has no such day: the 31st becomes the 30th in a month of thirty days.
	 *
	 * @param string $start  The mission's first day.
	 * @param string $end    Its last day, or empty.
	 * @param string $every  From every().
	 * @param int    $copies From copies().
	 * @return array<int, array{start: string, end: string}> Empty when the mission cannot be repeated as asked.
	 */
	public static function dates( string $start, string $end, string $every, int $copies ): array {
		if ( '' !== self::refusal( $start, $every, $copies ) ) {
			return array();
		}
		$end    = Schedule::day( $end );
		$length = '' !== $end && $end >= $start ? Calendar::between( $start, $end ) : -1;
		$dates  = array();
		for ( $copy = 1; $copy <= $copies; $copy++ ) {
			$first   = 'month' === $every ? self::months_on( $start, $copy ) : Calendar::shift( $start, 7 * (int) $every * $copy );
			$dates[] = array(
				'start' => $first,
				'end'   => $length < 0 ? '' : Calendar::shift( $first, $length ),
			);
		}
		return $dates;
	}

	/**
	 * The same day of the month, a number of months later.
	 *
	 * @param string $day    `Y-m-d`.
	 * @param int    $months How many months later.
	 */
	private static function months_on( string $day, int $months ): string {
		list( $year, $month, $date ) = array_map( 'intval', explode( '-', $day ) );
		$count                       = $month + $months - 1;
		$year                       += intdiv( $count, 12 );
		$month                       = $count % 12 + 1;
		$last                        = (int) gmdate( 't', (int) gmmktime( 0, 0, 0, $month, 1, $year ) );
		return sprintf( '%04d-%02d-%02d', $year, $month, min( $date, $last ) );
	}
}
