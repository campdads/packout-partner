<?php
/**
 * The rules of a calendar.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which days a calendar shows, and where an event sits on them.
 *
 * A calendar is one block with six forms. This class holds what is the same
 * wherever it is drawn: the forms, the range of days each one covers, the
 * step to the next range, and the place of an event in a day. Days are
 * `Y-m-d` strings. It reads nothing from WordPress.
 */
final class Calendar {

	/**
	 * The six forms.
	 */
	public const VIEWS = array( 'day', 'week', 'month', 'agenda', 'upcoming', 'past' );

	/**
	 * The forms a visitor may switch among.
	 */
	public const SWITCHABLE = array( 'day', 'week', 'month', 'agenda' );

	/**
	 * What a calendar may show.
	 */
	public const KINDS = array( 'missions', 'campaigns', 'programs' );

	/**
	 * The most days one request may ask for.
	 */
	public const MAX_DAYS = 62;

	/**
	 * How many events a list shows when the block does not say.
	 */
	public const COUNT = 10;

	/**
	 * The most events a list shows.
	 */
	public const MAX_COUNT = 50;

	/**
	 * The hours a day's scale always covers: 8 am to 6 pm.
	 */
	public const SCALE = array( 8, 18 );

	/**
	 * How many days ahead Upcoming looks, and how many back Past looks.
	 */
	public const REACH = 366;

	/**
	 * A form, or `month` when what was given names none.
	 *
	 * @param mixed $value What was given.
	 */
	public static function view( $value ): string {
		return is_string( $value ) && in_array( $value, self::VIEWS, true ) ? $value : 'month';
	}

	/**
	 * How many events a list shows.
	 *
	 * @param mixed $value What was given.
	 */
	public static function count( $value ): int {
		$count = is_numeric( $value ) ? (int) $value : 0;
		return $count < 1 ? self::COUNT : min( self::MAX_COUNT, $count );
	}

	/**
	 * What a calendar shows, from a list of kinds.
	 *
	 * @param mixed $value A list, or a comma-separated string.
	 * @return array<int, string> Every kind when none that is known was named.
	 */
	public static function kinds( $value ): array {
		$named = is_string( $value ) ? explode( ',', $value ) : ( is_array( $value ) ? $value : array() );
		$kept  = array();
		foreach ( self::KINDS as $kind ) {
			if ( in_array( $kind, $named, true ) ) {
				$kept[] = $kind;
			}
		}
		return $kept ? $kept : self::KINDS;
	}

	/**
	 * The day a week starts on, as PHP numbers it: 0 for Sunday to 6 for Saturday.
	 *
	 * @param mixed $value `sunday`, `monday`, or anything else for the site's own.
	 * @param int   $site  The site's first day of the week.
	 */
	public static function week_start( $value, int $site ): int {
		if ( 'sunday' === $value ) {
			return 0;
		}
		if ( 'monday' === $value ) {
			return 1;
		}
		return $site >= 0 && $site <= 6 ? $site : 0;
	}

	/**
	 * A day moved by a number of days.
	 *
	 * @param string $day  `Y-m-d`.
	 * @param int    $days How many days, either way.
	 */
	public static function shift( string $day, int $days ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + $days * 86400 );
	}

	/**
	 * How many days from one day to another.
	 *
	 * @param string $from `Y-m-d`.
	 * @param string $to   `Y-m-d`.
	 */
	public static function between( string $from, string $to ): int {
		return (int) round( ( (int) strtotime( $to . ' 00:00:00 UTC' ) - (int) strtotime( $from . ' 00:00:00 UTC' ) ) / 86400 );
	}

	/**
	 * The day of the week of a day: 0 for Sunday to 6 for Saturday.
	 *
	 * @param string $day `Y-m-d`.
	 */
	public static function weekday( string $day ): int {
		return (int) gmdate( 'w', (int) strtotime( $day . ' 00:00:00 UTC' ) );
	}

	/**
	 * The first day of the week a day is in.
	 *
	 * @param string $day        `Y-m-d`.
	 * @param int    $week_start 0 for Sunday to 6 for Saturday.
	 */
	public static function week_of( string $day, int $week_start ): string {
		return self::shift( $day, -( ( self::weekday( $day ) - $week_start + 7 ) % 7 ) );
	}

	/**
	 * The days a form covers when it is asked for a day.
	 *
	 * A month covers whole weeks, so it starts before the first of the month
	 * and ends after the last. An agenda runs forward from its day.
	 *
	 * @param string $view       A form a visitor may switch among.
	 * @param string $day        `Y-m-d`.
	 * @param int    $week_start 0 for Sunday to 6 for Saturday.
	 * @return array{from: string, to: string}
	 */
	public static function range( string $view, string $day, int $week_start = 0 ): array {
		switch ( $view ) {
			case 'day':
				return array(
					'from' => $day,
					'to'   => $day,
				);
			case 'week':
				$from = self::week_of( $day, $week_start );
				return array(
					'from' => $from,
					'to'   => self::shift( $from, 6 ),
				);
			case 'agenda':
				return array(
					'from' => $day,
					'to'   => self::shift( $day, self::MAX_DAYS - 1 ),
				);
			default:
				$first = substr( $day, 0, 8 ) . '01';
				$last  = gmdate( 'Y-m-t', (int) strtotime( $first . ' 00:00:00 UTC' ) );
				$from  = self::week_of( $first, $week_start );
				return array(
					'from' => $from,
					'to'   => self::shift( self::week_of( $last, $week_start ), 6 ),
				);
		}//end switch
	}

	/**
	 * The day the next or the previous range is asked for.
	 *
	 * @param string $view A form a visitor may switch among.
	 * @param string $day  `Y-m-d`.
	 * @param int    $way  1 for the next, -1 for the previous.
	 */
	public static function step( string $view, string $day, int $way ): string {
		$way = $way < 0 ? -1 : 1;
		switch ( $view ) {
			case 'day':
				return self::shift( $day, $way );
			case 'week':
				return self::shift( $day, 7 * $way );
			case 'agenda':
				return self::shift( $day, self::MAX_DAYS * $way );
			default:
				$first = substr( $day, 0, 8 ) . '01';
				return gmdate( 'Y-m-d', (int) strtotime( $first . ' 00:00:00 UTC ' . ( $way > 0 ? '+1 month' : '-1 month' ) ) );
		}
	}

	/**
	 * Why a range may not be asked for, or an empty string when it may.
	 *
	 * @param mixed $from The first day.
	 * @param mixed $to   The last day.
	 * @return string Empty, `invalid`, `order`, or `long`.
	 */
	public static function refusal( $from, $to ): string {
		$from = Schedule::day( $from );
		$to   = Schedule::day( $to );
		if ( '' === $from || '' === $to ) {
			return 'invalid';
		}
		if ( $to < $from ) {
			return 'order';
		}
		return self::between( $from, $to ) > self::MAX_DAYS ? 'long' : '';
	}

	/**
	 * Every day of a range.
	 *
	 * @param string $from `Y-m-d`.
	 * @param string $to   `Y-m-d`.
	 * @return array<int, string>
	 */
	public static function days( string $from, string $to ): array {
		$days = array();
		$left = 400;
		for ( $day = $from; $day <= $to && $left > 0; $day = self::shift( $day, 1 ) ) {
			$days[] = $day;
			--$left;
		}
		return $days;
	}

	/**
	 * A range as weeks of seven days.
	 *
	 * @param string $from The first day of a week.
	 * @param string $to   The last day of a week.
	 * @return array<int, array<int, string>>
	 */
	public static function weeks( string $from, string $to ): array {
		return array_chunk( self::days( $from, $to ), 7 );
	}

	/**
	 * Whether an event has any part in a range of days.
	 *
	 * @param string $first The event's first day.
	 * @param string $last  The event's last day.
	 * @param string $from  The first day of the range.
	 * @param string $to    The last day of the range.
	 */
	public static function overlaps( string $first, string $last, string $from, string $to ): bool {
		return $first <= $to && $last >= $from;
	}

	/**
	 * Where a timed event sits on one of its days, in minutes from midnight.
	 *
	 * @param \DateTimeImmutable $start The event's start, in its own zone.
	 * @param \DateTimeImmutable $end   The event's end, in its own zone.
	 * @param string             $day   `Y-m-d`, a day the event covers.
	 * @return array{0: int, 1: int} Where it starts and where it ends on that day. The end is at least 30 minutes after the start, so a short event can be read.
	 */
	public static function segment( \DateTimeImmutable $start, \DateTimeImmutable $end, string $day ): array {
		$from = $start->format( 'Y-m-d' ) < $day ? 0 : (int) $start->format( 'G' ) * 60 + (int) $start->format( 'i' );
		$to   = $end->format( 'Y-m-d' ) > $day ? 1440 : (int) $end->format( 'G' ) * 60 + (int) $end->format( 'i' );
		$to   = max( $to, min( 1440, $from + 30 ) );
		return array( min( $from, 1410 ), $to );
	}

	/**
	 * The hours a day's scale covers.
	 *
	 * From the earliest event to the latest, and never less than 8 am to 6 pm.
	 *
	 * @param array<int, array{0: int, 1: int}> $segments From segment().
	 * @return array{0: int, 1: int} The first hour and the hour the scale ends at.
	 */
	public static function hours( array $segments ): array {
		$first = self::SCALE[0];
		$last  = self::SCALE[1];
		foreach ( $segments as $segment ) {
			$first = min( $first, intdiv( $segment[0], 60 ) );
			$last  = max( $last, (int) ceil( $segment[1] / 60 ) );
		}
		return array( max( 0, $first ), min( 24, $last ) );
	}

	/**
	 * Put the events of one day side by side where they overlap.
	 *
	 * @param array<int|string, array{0: int, 1: int}> $segments From segment(), keyed however the caller likes.
	 * @return array<int|string, array{column: int, of: int}> For each key, its column from zero and how many columns its group has.
	 */
	public static function columns( array $segments ): array {
		$order = array_keys( $segments );
		usort(
			$order,
			static function ( $a, $b ) use ( $segments ) {
				return $segments[ $a ][0] <=> $segments[ $b ][0] ? $segments[ $a ][0] <=> $segments[ $b ][0] : $segments[ $b ][1] <=> $segments[ $a ][1];
			}
		);

		$placed = array();
		$group  = array();
		$ends   = array();
		$until  = -1;
		$close  = static function () use ( &$placed, &$group, &$ends ): void {
			foreach ( $group as $key ) {
				$placed[ $key ]['of'] = count( $ends );
			}
			$group = array();
			$ends  = array();
		};
		foreach ( $order as $key ) {
			if ( $group && $segments[ $key ][0] >= $until ) {
				$close();
			}
			$column = count( $ends );
			foreach ( $ends as $index => $end ) {
				if ( $end <= $segments[ $key ][0] ) {
					$column = $index;
					break;
				}
			}
			$ends[ $column ] = $segments[ $key ][1];
			$until           = max( $until, $segments[ $key ][1] );
			$group[]         = $key;
			$placed[ $key ]  = array(
				'column' => $column,
				'of'     => 1,
			);
		}
		$close();
		return $placed;
	}

	/**
	 * Events in the order a calendar lists them.
	 *
	 * By start, then spans before events, then by title, then by id, so the
	 * order never depends on how the events were found.
	 *
	 * @param array<int, array<string, mixed>> $events Each with `start` as a Unix time, `kind`, `title`, and `id`.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sorted( array $events ): array {
		usort(
			$events,
			static function ( array $a, array $b ): int {
				$kinds = array(
					'program'  => 0,
					'campaign' => 1,
					'mission'  => 2,
				);
				return array( $a['start'], $kinds[ $a['kind'] ] ?? 3, (string) $a['title'], (int) $a['id'] ) <=> array( $b['start'], $kinds[ $b['kind'] ] ?? 3, (string) $b['title'], (int) $b['id'] );
			}
		);
		return $events;
	}
}
