<?php
/**
 * When a mission or a campaign happens.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

use PackOut\Core\Schedule as Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, checks, stores, and words the window of a record.
 *
 * A window is two days and, optionally, a start time, an end time, and a
 * time zone. The rules are in the library. This class is where they meet
 * the stored fields, the forms, and the site's date and time formats.
 */
class Schedule {

	/**
	 * The site's own time zone: an IANA name, or a fixed offset when the site names none.
	 */
	public static function site_zone(): string {
		return Rules::zone( wp_timezone_string(), 'UTC' );
	}

	/**
	 * The stored window of a record.
	 *
	 * @param int $id Post ID.
	 * @return array{start: string, end: string, start_time: string, end_time: string, timezone: string}
	 */
	public static function fields( int $id ): array {
		return array(
			'start'      => (string) get_post_meta( $id, '_pos_start', true ),
			'end'        => (string) get_post_meta( $id, '_pos_end', true ),
			'start_time' => (string) get_post_meta( $id, Rules::START_TIME_META, true ),
			'end_time'   => (string) get_post_meta( $id, Rules::END_TIME_META, true ),
			'timezone'   => (string) get_post_meta( $id, Rules::ZONE_META, true ),
		);
	}

	/**
	 * A record's window as moments in time, or null when it has no day.
	 *
	 * @param int|\WP_Post $post A mission, a campaign, or a program.
	 * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable, all_day: bool, open_end: bool, zone: string, first_day: string, last_day: string}|null
	 */
	public static function of( $post ): ?array {
		$id = $post instanceof \WP_Post ? $post->ID : (int) $post;
		return $id > 0 ? Rules::of( self::fields( $id ), self::site_zone() ) : null;
	}

	/**
	 * A record's window in words.
	 *
	 * @param int $id Post ID.
	 */
	public static function label( int $id ): string {
		$fields = self::fields( $id );
		return Schema::span( $fields['start'], $fields['end'], $fields );
	}

	/**
	 * A timed window in words: "March 14, 2026, 8:00 am – 2:00 pm MDT".
	 *
	 * The day and the time are in the site's formats, on the record's own
	 * clock, with the zone named.
	 *
	 * @param array{start: \DateTimeImmutable, end: \DateTimeImmutable, all_day: bool, open_end: bool, zone: string, first_day: string, last_day: string} $schedule From of().
	 */
	public static function words( array $schedule ): string {
		$zone = new \DateTimeZone( $schedule['zone'] );
		$day  = static fn( \DateTimeImmutable $at ): string => (string) wp_date( (string) get_option( 'date_format', 'F j, Y' ), $at->getTimestamp(), $zone );
		$time = static fn( \DateTimeImmutable $at ): string => (string) wp_date( (string) get_option( 'time_format', 'g:i a' ), $at->getTimestamp(), $zone );
		$name = Rules::abbreviation( $schedule['start'] );
		$from = $day( $schedule['start'] ) . ', ' . $time( $schedule['start'] );

		if ( $schedule['open_end'] ) {
			$words = $from . ' ' . $name;
			// With no stated end, a window of several days still names its last day.
			return $schedule['last_day'] > $schedule['first_day'] ? $words . ' – ' . $day( new \DateTimeImmutable( $schedule['last_day'] . ' 12:00:00', $zone ) ) : $words;
		}
		if ( $schedule['last_day'] === $schedule['first_day'] ) {
			return $from . ' – ' . $time( $schedule['end'] ) . ' ' . $name;
		}
		return $from . ' – ' . $day( $schedule['end'] ) . ', ' . $time( $schedule['end'] ) . ' ' . Rules::abbreviation( $schedule['end'] );
	}

	/**
	 * A time of day in the site's format, on a record's own clock.
	 *
	 * @param int    $at   A Unix time.
	 * @param string $zone The record's zone.
	 */
	public static function time( int $at, string $zone ): string {
		return (string) wp_date( (string) get_option( 'time_format', 'g:i a' ), $at, new \DateTimeZone( Rules::zone( $zone, self::site_zone() ) ) );
	}

	/**
	 * The window a form or a request asks for, checked.
	 *
	 * A field the input does not name keeps what is stored, so a caller that
	 * knows nothing of times changes none.
	 *
	 * @param array<string, mixed> $input    May hold `start`, `end`, `start_time`, `end_time`, and `timezone`.
	 * @param int                  $existing The record being changed, or 0 for a new one.
	 * @param string               $what     `mission` or `campaign`, for the wording and the error code.
	 * @return array{start: string, end: string, start_time: string, end_time: string, timezone: string}|\WP_Error
	 */
	public static function read( array $input, int $existing, string $what ) {
		$stored = $existing > 0 ? self::fields( $existing ) : array();
		$asked  = static fn( string $key ) => array_key_exists( $key, $input ) && null !== $input[ $key ] ? $input[ $key ] : ( $stored[ $key ] ?? '' );
		$window = Rules::window( $asked( 'start' ), $asked( 'end' ), $asked( 'start_time' ), $asked( 'end_time' ) );

		if ( '' !== $window['refusal'] ) {
			$messages = array(
				'order'            => 'campaign' === $what ? __( 'The campaign cannot end before it starts.', 'packout-core' ) : __( 'The mission cannot end before it starts.', 'packout-core' ),
				'end_time_alone'   => __( 'Give a start time as well as an end time.', 'packout-core' ),
				'time_without_day' => __( 'Give the day it starts as well as the time.', 'packout-core' ),
			);
			return new \WP_Error( 'packout_' . $what . '_window', $messages[ $window['refusal'] ], array( 'status' => 400 ) );
		}

		// A zone is kept only with a time. It is the one asked for, the one stored, or the site's, in that order.
		$zone = '';
		if ( '' !== $window['start_time'] ) {
			$zone = Rules::is_zone( $input['timezone'] ?? '' ) ? (string) $input['timezone'] : Rules::zone( $stored['timezone'] ?? '', self::site_zone() );
		}
		unset( $window['refusal'] );
		return $window + array( 'timezone' => $zone );
	}

	/**
	 * Store a window that read() passed.
	 *
	 * @param int                   $id     Post ID.
	 * @param array<string, string> $window From read().
	 */
	public static function write( int $id, array $window ): void {
		foreach ( array(
			'_pos_start'           => $window['start'],
			'_pos_end'             => $window['end'],
			Rules::START_TIME_META => $window['start_time'],
			Rules::END_TIME_META   => $window['end_time'],
			Rules::ZONE_META       => $window['timezone'],
		) as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, $key, $value );
			}
		}
	}

	/**
	 * The time zones a form offers, by name.
	 *
	 * @param string $current The zone the record has, so it is always offered.
	 * @return array<string, string>
	 */
	public static function zone_choices( string $current = '' ): array {
		$choices = array();
		foreach ( array_unique( array_filter( array( $current, self::site_zone() ) ) ) as $zone ) {
			if ( ! Rules::is_named( $zone ) && Rules::is_zone( $zone ) ) {
				/* translators: %s: an offset from UTC, such as +05:30. */
				$choices[ $zone ] = 'UTC' === $zone ? 'UTC' : sprintf( __( 'UTC%s', 'packout-core' ), $zone );
			}
		}
		foreach ( \DateTimeZone::listIdentifiers() as $zone ) {
			$choices[ $zone ] = str_replace( array( '_', '/' ), array( ' ', ' / ' ), $zone );
		}
		return $choices;
	}

	/**
	 * The start time, end time, and time zone fields of a form.
	 *
	 * The zone is offered once a start time is set. Without script it is always there.
	 *
	 * @param array<string, string> $values `start_time`, `end_time`, and `timezone`, as the form should show them.
	 */
	public static function inputs( array $values ): string {
		$zone  = Rules::zone( $values['timezone'] ?? '', self::site_zone() );
		$html  = '<div class="pos-ws__times" data-pos-times><div class="pos-ws__pair">';
		$html .= Workspace::input( 'start_time', __( 'Start time', 'packout-core' ), (string) ( $values['start_time'] ?? '' ), array( 'type' => 'time' ), __( 'Leave empty for all day.', 'packout-core' ) );
		$html .= Workspace::input( 'end_time', __( 'End time', 'packout-core' ), (string) ( $values['end_time'] ?? '' ), array( 'type' => 'time' ) );
		$html .= '</div><div data-pos-zone>';
		$html .= Workspace::choose( 'timezone', __( 'Time zone', 'packout-core' ), self::zone_choices( $zone ), $zone, __( 'The times above are on this clock. A calendar shows them with the zone named.', 'packout-core' ) );
		return $html . '</div></div>';
	}
}
