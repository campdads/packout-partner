<?php
/**
 * The public page of a Spot.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The page of a published Spot opens the way the page of a Place does: a
 * header with the first photo behind the title, the description, and the facts.
 * Under it, the guidance, the location, and the photos. Then the activity: the report, the missions
 * that took the Spot on, who cleaned it and when, the visits since, and the
 * updates people have left. Someone signed in can add an update.
 *
 * The page shows what the public may see and nothing else: the display
 * location, the credit the reporter asked for, and the photos a coordinator
 * lets everyone see.
 */
class Spot_Page {

	/**
	 * Register the hooks.
	 */
	public static function register(): void {
		add_filter( 'the_content', array( self::class, 'content' ) );
	}

	/**
	 * Build the page.
	 *
	 * @param string $content Post content.
	 */
	public static function content( $content ) {
		if ( ! is_singular( Schema::SPOT ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}
		wp_enqueue_style( 'packout-workspace' );
		wp_enqueue_script( 'packout-workspace' );
		wp_enqueue_style( 'packout-blocks' );

		$spot   = Spots::payload( $post, 'public' );
		$photos = Spots::gallery( $post );
		$labels = Schema::statuses();
		$status = $labels[ $spot['status'] ] ?? $spot['status'];
		$by     = Spots::reported_by( $post );
		$date   = mysql2date( get_option( 'date_format' ), $post->post_date );
		$place  = $spot['place'] ? '<a href="' . esc_url( $spot['place']['url'] ) . '">' . esc_html( $spot['place']['name'] ) . '</a>' : '';
		// On the hub: the partners whose own report of this a coordinator linked to it.
		$also = '';
		foreach ( (array) ( $spot['also'] ?? array() ) as $other ) {
			/* translators: %s: the name of a partner, linked. */
			$also .= '<span class="pos-spot-page__also">' . sprintf( esc_html__( 'Also reported through %s', 'packout-core' ), '<a href="' . esc_url( '' !== $other['site'] ? $other['site'] : $other['url'] ) . '">' . esc_html( $other['name'] ) . '</a>' ) . '</span> ';
		}

		foreach ( (array) ( $spot['notes'] ?? array() ) as $note ) {
			$name  = '' !== (string) $note['url'] ? '<a href="' . esc_url( (string) $note['url'] ) . '">' . esc_html( (string) $note['name'] ) . '</a>' : esc_html( (string) $note['name'] );
			$also .= '<span class="pos-spot-page__also pos-spot-page__note">' . str_replace( '%s', $name, esc_html( (string) $note['text'] ) ) . '</span>';
		}

		$html  = '<div class="pos-spot-page pos-place-page pos-place pos-event">';
		$html .= Page_Header::render(
			array(
				'cover'       => $photos ? $photos[0]['id'] : 0,
				'kind'        => $spot['category']['name'],
				'title'       => $post->post_title,
				'description' => trim( wp_strip_all_tags( $post->post_content ) ),
				'facts'       => array(
					'<span class="pos-spot-page__stage">' . esc_html( $status ) . '</span>',
					$place,
					/* translators: 1: date, 2: who reported the Spot. */
					'<span class="pos-spot-page__reported">' . sprintf( esc_html__( 'Reported %1$s by %2$s', 'packout-core' ), esc_html( $date ), Credit::html( $by ) ) . '</span>',
					trim( $also ),
				),
			)
		);
		$html .= '<div class="pos-place__body">';
		$html .= self::detail( $post, $spot, $photos );
		$html .= self::activity( $post );
		$html .= Claims::section( $post );
		return $html . '</div></div>';
	}

	/**
	 * Guidance, where to find the Spot, and its photos. The description and the
	 * other facts are in the header.
	 *
	 * @param \WP_Post                         $post   Spot.
	 * @param array<string, mixed>             $spot   Public payload.
	 * @param array<int, array<string, mixed>> $photos From Spots::gallery().
	 */
	private static function detail( \WP_Post $post, array $spot, array $photos ): string {
		$location = $spot['location'];
		$html     = '<section class="pos-place__section pos-spot-page__detail"><div class="pos-spot-page__split">';
		$html    .= '<div class="pos-spot-page__about">';

		if ( '' !== $spot['guidance'] ) {
			$html .= '<p class="pos-spot-page__hazard"><strong>' . esc_html( $spot['authorized'] ? __( 'Work is authorized', 'packout-core' ) : Schema::hazards()[ $spot['hazard'] ] ) . '.</strong> ' . esc_html( $spot['guidance'] ) . '</p>';
		}

		$facts = array();
		if ( null !== $location['lat'] ) {
			$facts[ 'coarse' === $location['precision'] ? __( 'Approximate location', 'packout-core' ) : __( 'Location', 'packout-core' ) ] = '<span class="pos-spot-page__location">' . esc_html(
				'coarse' === $location['precision']
					/* translators: 1: latitude, 2: longitude. */
					? sprintf( __( 'Near %1$s, %2$s. The exact place is shared with the crew that takes it on.', 'packout-core' ), $location['lat'], $location['lng'] )
					: $location['lat'] . ', ' . $location['lng']
			) . '</span>';
		}
		if ( '' !== $location['landmark'] ) {
			$facts[ __( 'Landmark', 'packout-core' ) ] = '<span class="pos-spot-page__landmark">' . esc_html( $location['landmark'] ) . '</span>';
		}
		if ( $facts ) {
			$html .= '<dl class="pos-event__facts pos-spot-page__facts">';
			foreach ( $facts as $label => $value ) {
				$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd>';
			}
			$html .= '</dl>';
		}

		// The ways to act on the Spot, which are further down the page.
		$html .= '<p class="pos-spot-page__actions pos-ws">';
		if ( true === Claims::open( $post ) ) {
			$html .= '<a class="pos-ws__button pos-ws__button--primary" href="#pos-claim">' . esc_html__( 'I cleaned this Spot', 'packout-core' ) . '</a> ';
		}
		if ( Updates::open( $post ) ) {
			$html .= '<a class="pos-ws__button" href="#pos-update">' . esc_html__( 'Share an update', 'packout-core' ) . '</a>';
		}
		$html .= '</p></div>';

		$html .= '<div class="pos-spot-page__photos">';
		if ( $photos ) {
			wp_enqueue_script( 'packout-slider' );
			$html .= self::slider( $photos, $post->post_title );
		} else {
			$html .= '<div class="pos-spot-page__no-photos"><p>' . esc_html(
				'coarse' === $location['precision']
					? __( 'Photos of this Spot are shared with the crew that takes it on.', 'packout-core' )
					: __( 'No photos of this Spot are shown.', 'packout-core' )
			) . '</p></div>';
		}
		return $html . '</div></div></section>';
	}

	/**
	 * The photos as a row that scrolls sideways. The slider script adds the arrows, the count, and the thumbnails.
	 *
	 * @param array<int, array<string, mixed>> $photos From Spots::gallery().
	 * @param string                           $title  Spot title.
	 */
	public static function slider( array $photos, string $title ): string {
		$count = count( $photos );
		/* translators: %s: Spot title. */
		$html  = '<div class="pos-slider pos-slider--page" role="group" aria-roledescription="carousel" aria-label="' . esc_attr( sprintf( __( 'Photos of %s', 'packout-core' ), $title ) ) . '" data-pos-thumbs>';
		$html .= '<div class="pos-slider__stage"><div class="pos-slider__track" tabindex="0">';
		foreach ( $photos as $index => $photo ) {
			/* translators: 1: number of this photo, 2: number of photos. */
			$alt   = '' !== (string) ( $photo['alt'] ?? '' ) ? (string) $photo['alt'] : sprintf( __( 'Photo %1$s of %2$s from the report', 'packout-core' ), number_format_i18n( $index + 1 ), number_format_i18n( $count ) );
			$html .= '<figure class="pos-slider__slide"><a href="' . esc_url( $photo['full'] ) . '">';
			$html .= '<img src="' . esc_url( $photo['large'] ) . '" data-thumb="' . esc_url( $photo['url'] ) . '" alt="' . esc_attr( $alt ) . '"' . ( $photo['width'] > 0 ? ' width="' . esc_attr( (string) $photo['width'] ) . '" height="' . esc_attr( (string) $photo['height'] ) . '"' : '' ) . ' loading="' . ( 0 === $index ? 'eager' : 'lazy' ) . '" decoding="async" />';
			$html .= '</a>' . ( '' !== (string) ( $photo['caption'] ?? '' ) ? '<figcaption class="pos-slider__caption">' . esc_html( (string) $photo['caption'] ) . '</figcaption>' : '' ) . '</figure>';
		}
		return $html . '</div></div></div>';
	}

	/**
	 * Everything that happened at the Spot, newest first, and the place to add to it.
	 *
	 * @param \WP_Post $post Spot.
	 */
	private static function activity( \WP_Post $post ): string {
		$html  = '<section class="pos-place__section pos-activity pos-ws" id="pos-activity"><h2>' . esc_html__( 'Activity', 'packout-core' ) . '</h2>';
		$html .= Updates::notices();
		$html .= '<ol class="pos-activity__list">';
		foreach ( self::events( $post ) as $event ) {
			$html .= '<li class="pos-activity__item pos-activity__item--' . esc_attr( $event['kind'] ) . '"' . ( ! empty( $event['id'] ) ? ' id="' . esc_attr( $event['id'] ) . '"' : '' ) . '>';
			$html .= '<p class="pos-activity__head"><span class="pos-activity__what">' . $event['what'] . '</span> <time class="pos-activity__when" datetime="' . esc_attr( $event['day'] ) . '">' . esc_html( Schema::span( $event['day'], $event['day'] ) ) . '</time></p>';
			if ( '' !== $event['detail'] ) {
				$html .= '<div class="pos-activity__detail">' . $event['detail'] . '</div>';
			}
			$html .= ( $event['tail'] ?? '' ) . '</li>';
		}
		$html .= '</ol>';
		return $html . Updates::form( $post ) . '</section>';
	}

	/**
	 * When something a post records happened: the moment it was written.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{0: int, 1: string} The moment, and its day on the site's calendar.
	 */
	private static function written( \WP_Post $post ): array {
		return array( (int) strtotime( $post->post_date_gmt . ' UTC' ), substr( $post->post_date, 0, 10 ) );
	}

	/**
	 * When something that is known only by its day happened: the middle of that day.
	 *
	 * @param string $day A date, or a date and time.
	 * @return array{0: int, 1: string}
	 */
	private static function on_day( string $day ): array {
		$day = substr( $day, 0, 10 );
		return array( (int) strtotime( $day . ' 12:00:00 UTC' ) - (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), $day );
	}

	/**
	 * When something stamped with a moment happened.
	 *
	 * @param string $moment A moment in ISO 8601.
	 * @return array{0: int, 1: string}
	 */
	private static function at( string $moment ): array {
		$time = (int) strtotime( $moment );
		return array( $time, wp_date( 'Y-m-d', $time ) );
	}

	/**
	 * The events of a Spot, newest first.
	 *
	 * Each has a kind, the moment it happened (for the order), its day on the
	 * site's calendar (which is what the page shows), what happened as escaped
	 * HTML, and a detail as escaped HTML.
	 *
	 * @param \WP_Post $post Spot.
	 * @return array<int, array<string, mixed>>
	 */
	public static function events( \WP_Post $post ): array {
		$id     = $post->ID;
		$events = array();

		// The report.
		list( $time, $day ) = self::written( $post );
		$events[]           = array(
			'kind'   => 'report',
			'time'   => $time,
			'day'    => $day,
			/* translators: %s: who reported the Spot. */
			'what'   => sprintf( esc_html__( 'Reported by %s', 'packout-core' ), Credit::html( Spots::reported_by( $post ) ) ),
			'detail' => '',
		);

		// The missions that took the Spot on.
		$missions = get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 50,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The missions that include one Spot.
				'meta_query'  => array(
					array(
						'key'   => '_pos_spot',
						'value' => $id,
					),
				),
			)
		);
		foreach ( $missions as $mission ) {
			$events[] = self::mission_event( $mission );
		}

		// The cleanups and the visits since. One cleaning may have no record yet: the Spot itself says who and when.
		$cycles = array();
		foreach ( Impact::of_spot( $id ) as $record ) {
			$kind = Impact::kind( $record->ID );
			if ( Impact::CLEANUP === $kind ) {
				$cycles[] = Impact::field( $record->ID, 'cycle' );
			}
			$events[] = self::record_event( $record, $kind );
		}
		$cleaned = Spots::field( $id, 'cleaned_at' );
		if ( '' !== $cleaned && 'cleaned' === Spots::field( $id, 'status', 'spotted' ) && ! in_array( $cleaned, $cycles, true ) ) {
			$mission            = (int) Spots::field( $id, 'cleaned_mission', '0' );
			list( $time, $day ) = self::at( $cleaned );
			$events[]           = array(
				'kind'   => 'cleanup',
				'time'   => $time,
				'day'    => $day,
				/* translators: %s: who cleaned the Spot. */
				'what'   => sprintf( esc_html__( 'Cleaned by %s', 'packout-core' ), Credit::html( self::worker( $mission > 0 ? Missions::team( $mission ) : 0, false, (int) Spots::field( $id, 'cleaned_by', '0' ) ) ) ),
				'detail' => self::on_mission( $mission ),
			);
		}

		// What people have said, oldest first.
		foreach ( array_reverse( Updates::of( $id ) ) as $update ) {
			list( $time, $day ) = self::written( $update );
			$events[]           = array(
				'kind'   => 'update',
				'id'     => 'pos-update-' . $update->ID,
				'time'   => $time,
				'day'    => $day,
				/* translators: %s: who wrote the update. */
				'what'   => sprintf( esc_html__( 'Update from %s', 'packout-core' ), Credit::html( Updates::from( $update ) ) ),
				'detail' => wpautop( esc_html( $update->post_content ) ),
				'tail'   => Updates::remove_form( $update ),
			);
		}

		// Newest first. Of two things at the same moment, the one found later is shown first.
		// The report is found first, so it stays at the foot, and updates are gathered oldest first.
		foreach ( $events as $index => $event ) {
			$events[ $index ]['found'] = $index;
		}
		usort(
			$events,
			static fn( array $a, array $b ): int => array( $b['time'], $b['found'] ) <=> array( $a['time'], $a['found'] )
		);
		return $events;
	}

	/**
	 * A mission that includes the Spot.
	 *
	 * A public mission is named and linked, with the organization when it is
	 * listed. A mission that is not public is only said to exist, which the
	 * Spot's stage already tells.
	 *
	 * @param \WP_Post $mission Mission.
	 */
	private static function mission_event( \WP_Post $mission ): array {
		$id       = $mission->ID;
		$status   = Missions::status( $id );
		$label    = Missions::statuses()[ $status ] ?? $status;
		$finished = Missions::field( $id, 'completed_at' );
		$start    = Missions::field( $id, 'start' );
		$public   = 'publish' === $mission->post_status && Post_Access::can_read( $mission, 0 );
		$bits     = array( esc_html( $label ) );

		if ( '' !== $finished ) {
			list( $time, $day ) = self::at( $finished );
		} elseif ( $public && '' !== $start ) {
			list( $time, $day ) = self::on_day( $start );
		} else {
			// The day a mission that is not public is planned for is its crew's to know.
			list( $time, $day ) = self::written( $mission );
		}

		if ( $public ) {
			$shown = Missions::public_payload( $mission );
			/* translators: %s: mission title, linked. */
			$what = sprintf( esc_html__( 'Mission: %s', 'packout-core' ), '<a href="' . esc_url( $shown['url'] ) . '">' . esc_html( $shown['title'] ) . '</a>' );
			if ( '' !== $shown['team'] ) {
				$bits[] = '<a href="' . esc_url( $shown['team_url'] ) . '">' . esc_html( $shown['team'] ) . '</a>';
			}
			// The day is beside the title. The window is added when it says more than that day.
			if ( '' !== $shown['window'] && Schema::span( $day, $day ) !== $shown['window'] ) {
				$bits[] = esc_html( $shown['window'] );
			}
		} else {
			$what = esc_html__( 'A mission took this Spot on', 'packout-core' );
		}
		return array(
			'kind'   => 'mission',
			'time'   => $time,
			'day'    => $day,
			'what'   => $what,
			'detail' => '<p>' . implode( ' <span aria-hidden="true">·</span> ', $bits ) . '</p>',
		);
	}

	/**
	 * A cleanup, or a visit since.
	 *
	 * Who cleaned and when is shown for every cleanup. The figures are shown
	 * only for a record a coordinator counts in the public totals. A visit
	 * says what was found, and not who looked.
	 *
	 * @param \WP_Post $record Impact record.
	 * @param string   $kind   Kind of record.
	 */
	private static function record_event( \WP_Post $record, string $kind ): array {
		$id     = $record->ID;
		$date   = Impact::field( $id, 'date' );
		$detail = '';
		// A record written on the day of the work is in the order it was written. One written later is placed on the day of the work.
		list( $time, $day ) = '' === $date || substr( $record->post_date, 0, 10 ) === $date ? self::written( $record ) : self::on_day( $date );

		if ( Impact::CLEANUP === $kind ) {
			$mission = (int) Impact::field( $id, 'mission', '0' );
			$who     = self::worker( (int) Impact::field( $id, 'team', '0' ), '1' === Impact::field( $id, 'named' ), (int) Impact::field( $id, 'user', (string) $record->post_author ) );
			/* translators: %s: who cleaned the Spot. */
			$what   = sprintf( esc_html__( 'Cleaned by %s', 'packout-core' ), Credit::html( $who ) );
			$detail = self::on_mission( $mission );
			if ( Impact::visible( $id ) ) {
				$bits   = array();
				$people = (int) Impact::field( $id, 'people', '0' );
				$bags   = (float) Impact::field( $id, 'bags', '0' );
				$pounds = (float) Impact::field( $id, 'pounds', '0' );
				if ( $people > 0 ) {
					/* translators: %s: number of people. */
					$bits[] = sprintf( _n( '%s person', '%s people', $people, 'packout-core' ), number_format_i18n( $people ) );
				}
				if ( $bags > 0 ) {
					/* translators: %s: number of bags. */
					$bits[] = sprintf( _n( '%s bag', '%s bags', (int) ceil( $bags ), 'packout-core' ), number_format_i18n( $bags, floor( $bags ) === $bags ? 0 : 1 ) );
				}
				if ( $pounds > 0 ) {
					/* translators: %s: weight in pounds. */
					$bits[] = sprintf( __( '%s lb', 'packout-core' ), number_format_i18n( $pounds, floor( $pounds ) === $pounds ? 0 : 1 ) );
				}
				if ( $bits ) {
					$detail .= '<p class="pos-activity__figures">' . esc_html( implode( ' · ', $bits ) ) . '</p>';
				}
			}//end if
		} elseif ( Impact::RETURNED === $kind ) {
			$what = esc_html__( 'A return visit found it needs cleaning again', 'packout-core' );
		} else {
			$what = esc_html__( 'A return visit found it still clear', 'packout-core' );
		}//end if
		return array(
			'kind'   => Impact::CLEANUP === $kind ? 'cleanup' : 'visit',
			'time'   => $time,
			'day'    => $day,
			'what'   => $what,
			'detail' => $detail,
		);
	}

	/**
	 * Who did a piece of work, as the public reads it.
	 *
	 * The organization of the work when there is one. Otherwise the person,
	 * by name only when they asked to be named.
	 *
	 * @param int  $team  Team ID, or 0.
	 * @param bool $named Whether the person asked to be named.
	 * @param int  $user  The person.
	 * @return array{kind: string, label: string, url: string}
	 */
	private static function worker( int $team, bool $named, int $user ): array {
		return Credit::line( $team, $named, $user );
	}

	/**
	 * The line that names the mission a cleanup was part of, when the mission is public.
	 *
	 * @param int $mission Mission ID, or 0.
	 */
	private static function on_mission( int $mission ): string {
		$post = $mission > 0 ? Missions::get( $mission ) : null;
		if ( ! $post || 'publish' !== $post->post_status || ! Post_Access::can_read( $post, 0 ) ) {
			return '';
		}
		/* translators: %s: mission title, linked. */
		return '<p>' . sprintf( esc_html__( 'On the mission %s', 'packout-core' ), '<a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( $post->post_title ) . '</a>' ) . '</p>';
	}
}
