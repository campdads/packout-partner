<?php
/**
 * When a record with a window happens.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The window of a mission, a campaign, or a program, as moments in time.
 *
 * A window is stored as two days, and optionally a start time, an end time,
 * and a time zone. A record with no start time is all-day, which is every
 * record made before times were added. The stored day is the local day in
 * the record's own zone. This class reads nothing from WordPress.
 */
final class Schedule {

	/**
	 * The meta key of the start time, `H:i`.
	 */
	public const START_TIME_META = '_pos_start_time';

	/**
	 * The meta key of the end time, `H:i`.
	 */
	public const END_TIME_META = '_pos_end_time';

	/**
	 * The meta key of the time zone, an IANA name.
	 */
	public const ZONE_META = '_pos_timezone';

	/**
	 * How long an event with a start time and no end time is drawn, in seconds.
	 */
	public const OPEN_LENGTH = 3600;

	/**
	 * A day as `Y-m-d`, or an empty string.
	 *
	 * @param mixed $value What was given.
	 */
	public static function day( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
			return '';
		}
		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $value : '';
	}

	/**
	 * A time of day as `H:i` on a 24-hour clock, or an empty string.
	 *
	 * Seconds are accepted and dropped, since a browser's time field may send them.
	 *
	 * @param mixed $value What was given.
	 */
	public static function time( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( 1 !== preg_match( '/^(\d{2}):(\d{2})(?::\d{2})?$/', $value, $parts ) ) {
			return '';
		}
		return (int) $parts[1] < 24 && (int) $parts[2] < 60 ? $parts[1] . ':' . $parts[2] : '';
	}

	/**
	 * Whether a string names a time zone a record may keep.
	 *
	 * That is an IANA name, `UTC`, or a fixed offset such as `+05:30`, which
	 * is what a site with no named zone has.
	 *
	 * @param mixed $value What was given.
	 */
	public static function is_zone( $value ): bool {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		if ( 'UTC' === $value || 1 === preg_match( '/^[+-](?:0\d|1[0-4]):[0-5]\d$/', $value ) ) {
			return true;
		}
		return in_array( $value, \DateTimeZone::listIdentifiers(), true );
	}

	/**
	 * Whether a zone is one a calendar program knows by name.
	 *
	 * A fixed offset is not: a calendar file gives its times in UTC instead.
	 *
	 * @param string $zone A zone that passed is_zone().
	 */
	public static function is_named( string $zone ): bool {
		return 'UTC' !== $zone && 1 !== preg_match( '/^[+-]/', $zone ) && self::is_zone( $zone );
	}

	/**
	 * A time zone, or the fallback when what was given names none.
	 *
	 * @param mixed  $value    What was given.
	 * @param string $fallback The site's zone.
	 */
	public static function zone( $value, string $fallback = 'UTC' ): string {
		if ( self::is_zone( $value ) ) {
			return (string) $value;
		}
		return self::is_zone( $fallback ) ? $fallback : 'UTC';
	}

	/**
	 * A window as it may be stored, and why it may not be.
	 *
	 * @param mixed $start      The first day.
	 * @param mixed $end        The last day.
	 * @param mixed $start_time The start time.
	 * @param mixed $end_time   The end time.
	 * @return array{start: string, end: string, start_time: string, end_time: string, refusal: string}
	 *         `refusal` is empty, `order` when the end is before the start, `time_without_day`
	 *         when a time is given with no first day, or `end_time_alone` when an end time
	 *         is given with no start time.
	 */
	public static function window( $start, $end, $start_time = '', $end_time = '' ): array {
		$window = array(
			'start'      => self::day( $start ),
			'end'        => self::day( $end ),
			'start_time' => self::time( $start_time ),
			'end_time'   => self::time( $end_time ),
			'refusal'    => '',
		);
		if ( '' !== $window['start'] && '' !== $window['end'] && $window['end'] < $window['start'] ) {
			$window['refusal'] = 'order';
		} elseif ( '' !== $window['end_time'] && '' === $window['start_time'] ) {
			$window['refusal'] = 'end_time_alone';
		} elseif ( '' !== $window['start_time'] && '' === $window['start'] ) {
			$window['refusal'] = 'time_without_day';
		} elseif ( '' !== $window['end_time'] && $window['end_time'] < $window['start_time'] && ( '' === $window['end'] || $window['end'] === $window['start'] ) ) {
			$window['refusal'] = 'order';
		}
		return $window;
	}

	/**
	 * A record's window as moments in time, or null when it has no day at all.
	 *
	 * @param array<string, mixed> $fields    `start`, `end`, `start_time`, `end_time`, and `timezone`, as stored.
	 * @param string               $site_zone The site's zone, for a record that names none.
	 * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable, all_day: bool, open_end: bool, zone: string, first_day: string, last_day: string}|null
	 *         `end` of an all-day window is the start of the day after its last day.
	 *         `open_end` says a start time was given and no end time.
	 */
	public static function of( array $fields, string $site_zone = 'UTC' ): ?array {
		$first = self::day( $fields['start'] ?? '' );
		$last  = self::day( $fields['end'] ?? '' );
		if ( '' === $first && '' === $last ) {
			return null;
		}
		$first = '' === $first ? $last : $first;
		$last  = '' === $last || $last < $first ? $first : $last;

		$from = self::time( $fields['start_time'] ?? '' );
		$to   = '' === $from ? '' : self::time( $fields['end_time'] ?? '' );
		$zone = self::zone( $fields['timezone'] ?? '', $site_zone );
		$tz   = new \DateTimeZone( $zone );

		if ( '' === $from ) {
			return array(
				'start'     => new \DateTimeImmutable( $first . ' 00:00:00', $tz ),
				'end'       => ( new \DateTimeImmutable( $last . ' 00:00:00', $tz ) )->modify( '+1 day' ),
				'all_day'   => true,
				'open_end'  => false,
				'zone'      => $zone,
				'first_day' => $first,
				'last_day'  => $last,
			);
		}

		$start = new \DateTimeImmutable( $first . ' ' . $from . ':00', $tz );
		$end   = '' === $to ? null : new \DateTimeImmutable( $last . ' ' . $to . ':00', $tz );
		if ( null !== $end && $end < $start ) {
			$end = null;
		}
		$open = null === $end;
		if ( $open ) {
			// With no stated end, a window of several days runs to the end of its last day.
			$end = $last > $first
				? ( new \DateTimeImmutable( $last . ' 00:00:00', $tz ) )->modify( '+1 day' )
				: $start->modify( '+' . self::OPEN_LENGTH . ' seconds' );
		}
		return array(
			'start'     => $start,
			'end'       => $end,
			'all_day'   => false,
			'open_end'  => $open,
			'zone'      => $zone,
			'first_day' => $first,
			'last_day'  => $last,
		);
	}

	/**
	 * The short name of a zone at a moment, such as `CDT`.
	 *
	 * Where the zone has no letters of its own, the offset from UTC: `UTC+5:30`.
	 *
	 * @param \DateTimeInterface $at A moment in the zone.
	 */
	public static function abbreviation( \DateTimeInterface $at ): string {
		$short = $at->format( 'T' );
		if ( 1 === preg_match( '/^[A-Za-z]{2,6}$/', $short ) ) {
			return $short;
		}
		$offset = $at->getOffset();
		if ( 0 === $offset ) {
			return 'UTC';
		}
		$hours   = intdiv( abs( $offset ), 3600 );
		$minutes = intdiv( abs( $offset ) % 3600, 60 );
		return 'UTC' . ( $offset < 0 ? '-' : '+' ) . $hours . ( $minutes > 0 ? ':' . str_pad( (string) $minutes, 2, '0', STR_PAD_LEFT ) : '' );
	}

	/**
	 * Whether a window is over at a moment.
	 *
	 * @param array{end: \DateTimeImmutable} $schedule From of().
	 * @param int                            $now      A Unix time.
	 */
	public static function is_over( array $schedule, int $now ): bool {
		return $schedule['end']->getTimestamp() <= $now;
	}
}
