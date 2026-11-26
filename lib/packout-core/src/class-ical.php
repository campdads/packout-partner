<?php
/**
 * A calendar file.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes events as an iCalendar file (RFC 5545).
 *
 * The caller decides which events a file may carry. This class only writes
 * them down: it escapes text, folds lines at 75 octets, and describes each
 * time zone the events use. It reads nothing from WordPress.
 */
final class Ical {

	/**
	 * The media type of a calendar file.
	 */
	public const TYPE = 'text/calendar; charset=utf-8';

	/**
	 * Text, with the characters a calendar file gives a meaning to escaped.
	 *
	 * @param string $text Plain text.
	 */
	public static function escape( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = (string) preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]/', '', $text );
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\\;', '\\,', '\\n' ), $text );
	}

	/**
	 * One content line, folded so that no line is longer than 75 octets.
	 *
	 * A character of several octets is never split.
	 *
	 * @param string $line A content line with no line break in it.
	 */
	public static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$lines = array();
		$now   = '';
		$limit = 75;
		foreach ( (array) preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $character ) {
			if ( strlen( $now ) + strlen( $character ) > $limit ) {
				$lines[] = $now;
				$now     = '';
				// A continuation line starts with a space, which counts.
				$limit = 74;
			}
			$now .= $character;
		}
		$lines[] = $now;
		return implode( "\r\n ", $lines );
	}

	/**
	 * A calendar file.
	 *
	 * @param array<int, array<string, mixed>> $events Each with `uid`, `stamp` (a Unix time), `start` and `end`
	 *                                                 (`DateTimeImmutable` in the event's zone), `all_day`, `zone`,
	 *                                                 `summary`, and optionally `description`, `url`, `location`,
	 *                                                 and `cancelled`.
	 * @param array<string, string>            $about  `product` for PRODID and `name` for the calendar's name.
	 */
	public static function document( array $events, array $about ): string {
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:' . self::escape( $about['product'] ?? '-//Pack-Out Spot//Calendar//EN' ),
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
		);
		if ( '' !== (string) ( $about['name'] ?? '' ) ) {
			$lines[] = 'X-WR-CALNAME:' . self::escape( (string) $about['name'] );
		}

		// Each named zone the timed events use, across the time they cover.
		$zones = array();
		foreach ( $events as $event ) {
			if ( ! empty( $event['all_day'] ) || ! Schedule::is_named( (string) $event['zone'] ) ) {
				continue;
			}
			$zone           = (string) $event['zone'];
			$zones[ $zone ] = array(
				min( $zones[ $zone ][0] ?? PHP_INT_MAX, $event['start']->getTimestamp() ),
				max( $zones[ $zone ][1] ?? 0, $event['end']->getTimestamp() ),
			);
		}
		ksort( $zones );
		foreach ( $zones as $zone => $span ) {
			$lines = array_merge( $lines, self::zone_lines( $zone, $span[0], $span[1] ) );
		}

		foreach ( $events as $event ) {
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . self::escape( (string) $event['uid'] );
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', (int) $event['stamp'] );
			if ( ! empty( $event['all_day'] ) ) {
				$lines[] = 'DTSTART;VALUE=DATE:' . $event['start']->format( 'Ymd' );
				$lines[] = 'DTEND;VALUE=DATE:' . $event['end']->format( 'Ymd' );
			} elseif ( Schedule::is_named( (string) $event['zone'] ) ) {
				$lines[] = 'DTSTART;TZID=' . $event['zone'] . ':' . $event['start']->format( 'Ymd\THis' );
				$lines[] = 'DTEND;TZID=' . $event['zone'] . ':' . $event['end']->format( 'Ymd\THis' );
			} else {
				$lines[] = 'DTSTART:' . gmdate( 'Ymd\THis\Z', $event['start']->getTimestamp() );
				$lines[] = 'DTEND:' . gmdate( 'Ymd\THis\Z', $event['end']->getTimestamp() );
			}
			$lines[] = 'SUMMARY:' . self::escape( (string) $event['summary'] );
			foreach ( array(
				'description' => 'DESCRIPTION',
				'location'    => 'LOCATION',
			) as $key => $name ) {
				if ( '' !== (string) ( $event[ $key ] ?? '' ) ) {
					$lines[] = $name . ':' . self::escape( (string) $event[ $key ] );
				}
			}
			if ( '' !== (string) ( $event['url'] ?? '' ) ) {
				// A URI is not text: it is written as it is, without a line break.
				$lines[] = 'URL:' . (string) preg_replace( '/[\x00-\x20\x7F]/', '', (string) $event['url'] );
			}
			$lines[] = 'STATUS:' . ( ! empty( $event['cancelled'] ) ? 'CANCELLED' : 'CONFIRMED' );
			$lines[] = 'TRANSP:' . ( ! empty( $event['all_day'] ) ? 'TRANSPARENT' : 'OPAQUE' );
			$lines[] = 'END:VEVENT';
		}//end foreach
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * The lines that describe one time zone across a stretch of time.
	 *
	 * Each change of offset in the stretch is written out as its own
	 * observance, with the state the stretch starts in first. No recurrence
	 * rule is written, so nothing has to be guessed about years to come.
	 *
	 * @param string $zone An IANA name.
	 * @param int    $from A Unix time at or before the first event.
	 * @param int    $to   A Unix time at or after the last event.
	 * @return array<int, string>
	 */
	public static function zone_lines( string $zone, int $from, int $to ): array {
		$tz    = new \DateTimeZone( $zone );
		$moves = $tz->getTransitions( $from - 86400, $to + 86400 );
		if ( ! is_array( $moves ) || ! $moves ) {
			return array();
		}
		$lines = array( 'BEGIN:VTIMEZONE', 'TZID:' . $zone );
		$was   = (int) $moves[0]['offset'];
		foreach ( $moves as $index => $move ) {
			$offset = (int) $move['offset'];
			$kind   = ! empty( $move['isdst'] ) ? 'DAYLIGHT' : 'STANDARD';
			// The first row is the state the stretch starts in, not a change. A change is given in the time on the clock before it.
			$starts  = 0 === $index ? '19700101T000000' : gmdate( 'Ymd\THis', (int) $move['ts'] + $was );
			$lines[] = 'BEGIN:' . $kind;
			$lines[] = 'DTSTART:' . $starts;
			$lines[] = 'TZOFFSETFROM:' . self::offset( $was );
			$lines[] = 'TZOFFSETTO:' . self::offset( $offset );
			$lines[] = 'TZNAME:' . self::escape( (string) $move['abbr'] );
			$lines[] = 'END:' . $kind;
			$was     = $offset;
		}
		$lines[] = 'END:VTIMEZONE';
		return $lines;
	}

	/**
	 * An offset from UTC as a calendar file writes it: `-0500`.
	 *
	 * @param int $seconds The offset in seconds.
	 */
	public static function offset( int $seconds ): string {
		$minutes = intdiv( abs( $seconds ), 60 );
		return ( $seconds < 0 ? '-' : '+' ) . str_pad( (string) intdiv( $minutes, 60 ), 2, '0', STR_PAD_LEFT ) . str_pad( (string) ( $minutes % 60 ), 2, '0', STR_PAD_LEFT );
	}
}
