<?php
/**
 * Spots of packout.spot that a mission of a partner site took on.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

defined( 'ABSPATH' ) || exit;

/**
 * A partner's crew takes on a Spot that was reported on the hub.
 *
 * The lead of a mission names a Spot of the hub's. The site asks the hub,
 * and when the hub agrees it makes a copy of what the hub shows everyone
 * about the Spot and adds the copy to the mission. The copy is worked
 * like any Spot of the site. It is never sent to the hub as a Spot of the
 * site's own, and the work recorded on it is left out of the totals the
 * site sends, because the hub counts that work itself, once, from the
 * record it writes.
 *
 * What the hub is told follows from how the copy stands here, so telling
 * it twice changes nothing: the mission started, the Spot was cleaned and
 * its work recorded, or the Spot is no longer the mission's.
 */
final class Adoptions {

	/**
	 * On the copy: the hub's number for the adoption.
	 */
	public const ID = '_pos_hub_adoption';

	/**
	 * On the copy: how the adoption stands. `open`, `started`, `reported`, `done`, or `released`.
	 */
	public const STATE = '_pos_hub_state';

	/**
	 * On the copy: the Spot's page on the hub.
	 */
	public const URL = '_pos_hub_url';

	/**
	 * On the copy: why the hub ended the adoption.
	 */
	public const NOTE = '_pos_hub_note';

	/**
	 * The most photographs sent with a report of the work.
	 */
	public const PHOTOS = 12;

	/**
	 * Whether the copies were looked at in this request.
	 *
	 * @var bool
	 */
	private static bool $looked = false;

	/**
	 * Whether a copy is being made now. A Spot made while this is set is a copy from its first moment, before its fields say so.
	 *
	 * @var bool
	 */
	private static bool $making = false;

	/**
	 * Hook the adoptions. A partner site only.
	 */
	public static function register(): void {
		if ( App::is_hub() ) {
			return;
		}
		add_filter( 'packout_outbox_send', array( self::class, 'send' ), 10, 2 );
		add_filter( 'packout_outbox_image', array( self::class, 'image' ), 10, 2 );
		add_action( 'packout_exchange_pulled', array( self::class, 'pulled' ) );
		add_filter( 'packout_spot_notes', array( self::class, 'notes' ), 10, 2 );
		add_action( 'packout_missions_pane', array( self::class, 'pane' ) );
		add_action( 'admin_post_packout_adopt_hub_spot', array( self::class, 'action' ) );
		add_action( 'packout_teams_pane', array( self::class, 'place_pane' ) );
		add_action( 'admin_post_packout_ask_hub_place', array( self::class, 'place_action' ) );
	}

	/**
	 * Whether a copy of a hub Spot is being made in this request.
	 */
	public static function making(): bool {
		return self::$making;
	}

	/**
	 * Whether a Spot here is a copy of one of the hub's.
	 *
	 * @param int $spot The Spot.
	 */
	public static function is( int $spot ): bool {
		return '' !== (string) get_post_meta( $spot, Exchange::ADOPTED, true );
	}

	/**
	 * The hub Spots this site holds a copy of, whose adoption is still going.
	 *
	 * @return array<int, int> The hub's Spot to the copy here.
	 */
	public static function hub_spots(): array {
		$map = array();
		foreach ( self::going() as $spot ) {
			$map[ (int) get_post_meta( $spot, Exchange::ADOPTED, true ) ] = $spot;
		}
		return $map;
	}

	/**
	 * The copies whose adoption is still going.
	 *
	 * @return array<int, int>
	 */
	public static function going(): array {
		$found = get_posts(
			array(
				'post_type'   => Schema::SPOT,
				'post_status' => array( 'publish', 'private', 'pending', 'trash' ),
				'numberposts' => 500,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The few Spots taken on from the hub.
				'meta_query'  => array(
					array(
						'key'     => self::STATE,
						'value'   => array( 'open', 'started', 'reported' ),
						'compare' => 'IN',
					),
				),
			)
		);
		return array_map( 'intval', $found );
	}

	/**
	 * Read the hub's number for a Spot from what a person typed: the number, or the address of the Spot's page on the hub.
	 *
	 * @param string $typed What was typed.
	 * @return int The number, or 0.
	 */
	public static function number( string $typed ): int {
		$typed = trim( $typed );
		if ( ctype_digit( $typed ) && strlen( $typed ) <= 18 ) {
			return (int) $typed;
		}
		foreach ( self::layer() as $spot ) {
			if ( '' !== $spot['url'] && untrailingslashit( $spot['url'] ) === untrailingslashit( $typed ) ) {
				return (int) $spot['id'];
			}
		}
		return 0;
	}

	/**
	 * The hub's Spots in the site's region that wait for a crew, from the layer the site keeps.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function layer(): array {
		if ( ! Hub_Layers::on() ) {
			return array();
		}
		return array_values( array_filter( Hub_Layers::data()['spots'], static fn( $spot ): bool => is_array( $spot ) && '' === (string) $spot['through'] && in_array( $spot['status'], array( 'spotted', 'watch' ), true ) && 'none' === $spot['hazard'] ) );
	}

	/**
	 * Take a Spot of the hub's on for a mission.
	 *
	 * The hub is asked now, in the lead's own request, because the lead needs the answer: another crew may have the Spot.
	 *
	 * @param int $mission  The mission.
	 * @param int $hub_spot The hub's number for the Spot.
	 * @param int $user     The person asking.
	 * @return int|\WP_Error The copy made here.
	 */
	public static function adopt( int $mission, int $hub_spot, int $user ) {
		if ( App::is_hub() ) {
			return App::hub_only();
		}
		$post = Missions::get( $mission );
		if ( ! $post || ! Missions::can_manage( $mission, $user ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The lead of a mission adds Spots to it.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! Missions::is_open( $mission ) ) {
			return new \WP_Error( 'packout_mission_closed', __( 'That mission is over. Plan a new one for this Spot.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( ! Connection::sending() ) {
			return new \WP_Error( 'packout_not_connected', __( 'This site is not sending to packout.spot now. See the Connection screen.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( $hub_spot <= 0 ) {
			return new \WP_Error( 'packout_hub_spot', __( 'Give the Spot\'s number on packout.spot, or the address of its page there.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( isset( self::hub_spots()[ $hub_spot ] ) ) {
			return new \WP_Error( 'packout_hub_spot', __( 'A mission on this site already has that Spot.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$about = array(
			'remote_id' => (string) $mission,
			'title'     => mb_substr( '' !== trim( $post->post_title ) ? trim( $post->post_title ) : __( 'Mission', 'packout-core' ), 0, 200 ),
			'url'       => (string) get_permalink( $post ),
		);
		foreach ( array(
			'starts' => 'start',
			'ends'   => 'end',
		) as $field => $key ) {
			$day = substr( Missions::field( $mission, $key ), 0, 10 );
			if ( 1 === preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $day ) ) {
				$about[ $field ] = $day;
			}
		}
		$body = array(
			'act'     => 'adopt',
			'spot'    => (string) $hub_spot,
			'mission' => $about,
		);
		$team = Missions::team( $mission );
		if ( $team > 0 && Listings::listable( 'organization', (string) $team ) ) {
			$body['organization'] = (string) $team;
		}
		$answer = Connection::send( 'POST', '/adoptions', $body + Connection::versions() );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		$adoption = (int) ( $answer['id'] ?? 0 );
		$copy     = $adoption > 0 ? self::copy( (array) ( $answer['spot'] ?? array() ), $hub_spot, $adoption, $user ) : new \WP_Error( 'packout_hub', __( 'packout.spot did not say which adoption this is.', 'packout-core' ) );
		if ( ! is_wp_error( $copy ) ) {
			$linked = Missions::save( $mission, array( 'spots' => array_merge( Missions::spots( $mission ), array( $copy ) ) ), $user );
			if ( is_wp_error( $linked ) ) {
				wp_delete_post( $copy, true );
				$copy = $linked;
			}
		}
		if ( is_wp_error( $copy ) ) {
			// The hub agreed and this site could not keep its side. The hub is told to let the Spot go.
			if ( $adoption > 0 ) {
				Connection::send(
					'POST',
					'/adoptions',
					array(
						'act'    => 'release',
						'id'     => (string) $adoption,
						'reason' => 'removed',
					) + Connection::versions()
				);
			}
			return $copy;
		}
		Hub_Layers::drop_spot( $hub_spot );
		if ( 'in_progress' === Missions::status( $mission ) ) {
			self::queue( $copy );
		}
		return $copy;
	}

	/**
	 * Make the copy of a hub Spot: what the hub shows everyone, and no more.
	 *
	 * @param array<string, mixed> $spot     The hub's public record of the Spot.
	 * @param int                  $hub_spot The hub's number for it.
	 * @param int                  $adoption The hub's number for the adoption.
	 * @param int                  $user     The person who took it on.
	 * @return int|\WP_Error
	 */
	private static function copy( array $spot, int $hub_spot, int $adoption, int $user ) {
		$hub   = Connection::hub();
		$url   = esc_url_raw( (string) ( $spot['url'] ?? '' ), array( 'https', 'http' ) );
		$url   = 0 === strpos( $url, $hub ) ? $url : '';
		$said  = trim( sanitize_textarea_field( (string) ( $spot['description'] ?? '' ) ) );
		$title = sanitize_text_field( (string) ( $spot['title'] ?? '' ) );
		$slug  = sanitize_title( (string) ( $spot['category']['slug'] ?? '' ) );
		if ( ! term_exists( $slug, Schema::CATEGORY ) ) {
			$slug = (string) Settings::get( 'default_category' );
			if ( ! term_exists( $slug, Schema::CATEGORY ) ) {
				$slug = (string) ( array_keys( Schema::categories() )[0] ?? '' );
			}
		}
		$input = array(
			'category'    => $slug,
			'title'       => '' !== $title ? $title : __( 'A Spot on packout.spot', 'packout-core' ),
			'description' => '' !== $said ? $said : __( 'Reported on packout.spot.', 'packout-core' ),
			'landmark'    => sanitize_text_field( (string) ( $spot['location']['landmark'] ?? '' ) ),
			'hazard'      => sanitize_key( (string) ( $spot['hazard'] ?? 'none' ) ),
		);
		if ( is_numeric( $spot['location']['lat'] ?? null ) && is_numeric( $spot['location']['lng'] ?? null ) ) {
			$input['lat'] = (float) $spot['location']['lat'];
			$input['lng'] = (float) $spot['location']['lng'];
		} elseif ( '' === $input['landmark'] ) {
			$input['landmark'] = __( 'See the Spot\'s page on packout.spot.', 'packout-core' );
		}
		// The Spot is a copy from the moment it is made, so it is never queued as a report of this site's.
		self::$making = true;
		try {
			$id = Spots::create( $input, $user );
			if ( ! is_wp_error( $id ) ) {
				update_post_meta( $id, Exchange::ADOPTED, (string) $hub_spot );
			}
		} finally {
			self::$making = false;
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, self::ID, (string) $adoption );
		update_post_meta( $id, self::STATE, 'open' );
		update_post_meta( $id, self::URL, $url );
		if ( 'coarse' === ( $spot['location']['precision'] ?? '' ) ) {
			// The hub gave an approximate point. It is shown as one here too.
			Spots::set_precision( $id, 'coarse' );
		}
		Spots::publish( $id );
		return $id;
	}

	/**
	 * Tell the hub, with the next run of the queue, how a copy stands.
	 *
	 * @param int $spot The copy.
	 */
	public static function queue( int $spot ): void {
		if ( App::is_hub() || ! Connection::connected() || '' === (string) get_post_meta( $spot, self::ID, true ) ) {
			return;
		}
		if ( in_array( (string) get_post_meta( $spot, self::STATE, true ), array( 'open', 'started', 'reported' ), true ) ) {
			Outbox::enqueue( 'adoption', (string) $spot );
		}
	}

	/**
	 * A mission changed. Every copy whose adoption is still going is looked at again: one may have been taken off it.
	 */
	public static function mission_changed(): void {
		// Once in a request: saving a mission changes many of its fields.
		if ( self::$looked ) {
			return;
		}
		self::$looked = true;
		foreach ( self::going() as $spot ) {
			self::queue( $spot );
		}
	}

	/**
	 * The mission a copy is on: an open one first, then the newest.
	 *
	 * @param int $spot The copy.
	 */
	private static function mission( int $spot ): int {
		$missions = get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 20,
				'orderby'     => 'ID',
				'order'       => 'DESC',
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Missions that include one Spot.
				'meta_query'  => array(
					array(
						'key'   => '_pos_spot',
						'value' => $spot,
					),
				),
			)
		);
		foreach ( $missions as $mission ) {
			if ( Missions::is_open( (int) $mission ) ) {
				return (int) $mission;
			}
		}
		return (int) ( $missions[0] ?? 0 );
	}

	/**
	 * What to tell the hub about a copy now, from how it stands here.
	 *
	 * @param int $spot The copy.
	 * @return array<string, mixed>|null The request, without its versions. Null when there is nothing to say.
	 */
	public static function act( int $spot ): ?array {
		$adoption = (string) get_post_meta( $spot, self::ID, true );
		$state    = (string) get_post_meta( $spot, self::STATE, true );
		if ( '' === $adoption || ! in_array( $state, array( 'open', 'started', 'reported' ), true ) ) {
			return null;
		}
		$release = static fn( string $reason ): array => array(
			'act'    => 'release',
			'id'     => $adoption,
			'reason' => $reason,
		);
		$post    = Spots::get( $spot );
		if ( ! $post || 'trash' === $post->post_status ) {
			return $release( 'removed' );
		}
		$record = 'cleaned' === Spots::field( $spot, 'status', 'spotted' ) ? Impact::current( $spot ) : null;
		if ( $record ) {
			if ( 'reported' === $state ) {
				return null;
			}
			$sources = Impact::sources();
			$source  = static fn( string $value ): string => isset( $sources[ $value ] ) && 'measured' === $value ? 'measured' : 'self_reported';
			$day     = Impact::field( $record->ID, 'date' );
			return array(
				'act'           => 'complete',
				'id'            => $adoption,
				'date'          => 1 === preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $day ) ? $day : wp_date( 'Y-m-d' ),
				'hours'         => min( 1000000, max( 0, (float) Impact::field( $record->ID, 'hours', '0' ) ) ),
				'people'        => min( 100000, max( 0, (int) Impact::field( $record->ID, 'people', '0' ) ) ),
				'bags'          => min( 1000000, max( 0, (float) Impact::field( $record->ID, 'bags', '0' ) ) ),
				'bags_source'   => $source( Impact::field( $record->ID, 'bags_source' ) ),
				'pounds'        => min( 1000000, max( 0, (float) Impact::field( $record->ID, 'pounds', '0' ) ) ),
				'pounds_source' => $source( Impact::field( $record->ID, 'pounds_source' ) ),
				// What the crew wrote about the work. It names no one unless the crew did.
				'narrative'     => mb_substr( trim( sanitize_textarea_field( $record->post_content ) ), 0, 2000 ),
				'photos'        => array_map( 'strval', array_keys( self::photos( $spot ) ) ),
			);
		}//end if
		$mission = self::mission( $spot );
		if ( $mission <= 0 ) {
			return $release( 'removed' );
		}
		$status = Missions::status( $mission );
		if ( 'called_off' === $status ) {
			return $release( 'called_off' );
		}
		if ( 'completed' === $status ) {
			return $release( 'still_open' );
		}
		if ( 'in_progress' === $status && 'open' === $state ) {
			return array(
				'act' => 'start',
				'id'  => $adoption,
			);
		}
		return null;
	}

	/**
	 * The photographs of the work on a copy that the public here sees.
	 *
	 * @param int $spot The copy.
	 * @return array<int, string> The attachment to its file.
	 */
	private static function photos( int $spot ): array {
		$post = Spots::get( $spot );
		if ( ! $post || ! Exchange::photos_allowed( $spot ) ) {
			return array();
		}
		$files = array();
		foreach ( Spots::gallery( $post ) as $photo ) {
			if ( 'cleanup' !== $photo['kind'] || count( $files ) >= self::PHOTOS ) {
				continue;
			}
			$path = (string) get_attached_file( (int) $photo['id'] );
			if ( '' !== $path && is_readable( $path ) ) {
				$files[ (int) $photo['id'] ] = $path;
			}
		}
		return $files;
	}

	/**
	 * Send the adoption rows of the queue.
	 *
	 * @param bool                                            $alive Whether the hub is answering.
	 * @param array<string, array<int, array<string, mixed>>> $by    The rows that are due, by kind.
	 * @return bool Whether the hub is answering.
	 */
	public static function send( $alive, $by ): bool {
		$alive = (bool) $alive;
		foreach ( (array) ( $by['adoption'] ?? array() ) as $row ) {
			if ( ! $alive ) {
				break;
			}
			$spot = (int) $row['subject'];
			$body = self::act( $spot );
			if ( null === $body ) {
				Outbox::finish( array( $row ), array(), '' );
				continue;
			}
			$answer = Connection::send( 'POST', '/adoptions', $body + Connection::versions() );
			if ( is_wp_error( $answer ) ) {
				$data = (array) $answer->get_error_data();
				if ( 404 === (int) ( $data['status'] ?? 0 ) && 'unknown_adoption' === $answer->get_error_code() ) {
					// The hub no longer has it. There is nothing more to tell.
					update_post_meta( $spot, self::STATE, 'released' );
					Outbox::finish( array( $row ), array(), 'adoption' );
					continue;
				}
				$alive = Outbox::finish( array( $row ), $answer, 'adoption' );
				continue;
			}
			self::learn( $spot, (array) $answer );
			if ( 'complete' === $body['act'] ) {
				foreach ( array_slice( (array) ( $answer['photos'] ?? array() ), 0, self::PHOTOS ) as $photo ) {
					if ( (int) $photo > 0 ) {
						Outbox::enqueue( 'image', 'adoption:' . $spot . ':' . (int) $photo );
					}
				}
			}
			Outbox::finish( array( $row ), array(), 'adoption' );
		}//end foreach
		return $alive;
	}

	/**
	 * Keep how the hub says an adoption stands.
	 *
	 * @param int                  $spot   The copy.
	 * @param array<string, mixed> $answer What the hub said: `state` and `reason`.
	 */
	private static function learn( int $spot, array $answer ): void {
		$state = sanitize_key( (string) ( $answer['state'] ?? '' ) );
		if ( ! in_array( $state, array( 'open', 'started', 'reported', 'done', 'released' ), true ) ) {
			return;
		}
		update_post_meta( $spot, self::STATE, $state );
		$reason = mb_substr( sanitize_text_field( (string) ( $answer['reason'] ?? '' ) ), 0, 300 );
		if ( '' !== $reason ) {
			update_post_meta( $spot, self::NOTE, $reason );
		} else {
			delete_post_meta( $spot, self::NOTE );
		}
	}

	/**
	 * The file an image row of the queue stands for, when the row is a photograph of work on a copy.
	 *
	 * @param array{about: array<string, mixed>, path: string}|null $file    What another part of the plugin said.
	 * @param string                                                $subject The row's subject, as `adoption:12:340`.
	 * @return array{about: array<string, mixed>, path: string}|null
	 */
	public static function image( $file, $subject ) {
		$parts = explode( ':', (string) $subject );
		if ( 3 !== count( $parts ) || 'adoption' !== $parts[0] ) {
			return $file;
		}
		$spot     = (int) $parts[1];
		$adoption = (string) get_post_meta( $spot, self::ID, true );
		$path     = self::photos( $spot )[ (int) $parts[2] ] ?? '';
		if ( '' === $adoption || '' === $path || ! in_array( (string) get_post_meta( $spot, self::STATE, true ), array( 'reported', 'done' ), true ) ) {
			return null;
		}
		return array(
			'about' => array(
				'of'    => 'adoption',
				'id'    => $adoption,
				'photo' => (string) (int) $parts[2],
				'about' => 'cleanup',
			),
			'path'  => $path,
		);
	}

	/**
	 * The hub said what changed there: an adoption it ended, or a report it confirmed.
	 *
	 * @param array<string, mixed> $answer The hub's answer to `/changes`.
	 */
	public static function pulled( $answer ): void {
		$changed = array_slice( (array) ( ( (array) $answer )['adoptions'] ?? array() ), 0, 200 );
		if ( ! $changed ) {
			return;
		}
		$by = array();
		foreach ( get_posts(
			array(
				'post_type'   => Schema::SPOT,
				'post_status' => array( 'publish', 'private', 'pending' ),
				'numberposts' => 500,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The few Spots taken on from the hub.
				'meta_key'    => self::ID,
			)
		) as $spot ) {
			$by[ (string) get_post_meta( (int) $spot, self::ID, true ) ] = (int) $spot;
		}
		foreach ( $changed as $row ) {
			$spot = $by[ (string) ( $row['id'] ?? '' ) ] ?? 0;
			if ( $spot > 0 && (string) get_post_meta( $spot, self::STATE, true ) !== (string) ( $row['state'] ?? '' ) ) {
				self::learn( $spot, (array) $row );
			}
		}
	}

	/**
	 * The line a copy's card and page show: where the Spot is from.
	 *
	 * @param array<int, array{text: string, name: string, url: string}> $notes Lines so far.
	 * @param int                                                        $spot  The Spot.
	 * @return array<int, array{text: string, name: string, url: string}>
	 */
	public static function notes( $notes, $spot ): array {
		$notes = (array) $notes;
		if ( self::is( (int) $spot ) ) {
			$notes[] = array(
				/* translators: %s: packout.spot, linked to the Spot's page there. */
				'text' => __( 'This Spot was reported on %s. Work on it is counted there.', 'packout-core' ),
				'name' => 'packout.spot',
				'url'  => (string) get_post_meta( (int) $spot, self::URL, true ),
			);
		}
		return $notes;
	}

	/**
	 * What to tell a coordinator here about a copy, as sentences.
	 *
	 * @param int $spot The Spot.
	 * @return array<int, array{text: string, url: string, link: string, intent: string}>
	 */
	public static function lines( int $spot ): array {
		if ( App::is_hub() || ! self::is( $spot ) ) {
			return array();
		}
		$words = array(
			'open'     => __( 'This Spot is packout.spot\'s. A mission here has taken it on, and packout.spot shows it as planned.', 'packout-core' ),
			'started'  => __( 'This Spot is packout.spot\'s. packout.spot shows that a crew from this site is working on it.', 'packout-core' ),
			'reported' => __( 'This Spot is packout.spot\'s. The work recorded here was sent, and packout.spot counts it.', 'packout-core' ),
			'done'     => __( 'This Spot is packout.spot\'s. The work recorded here is on packout.spot, which counts it.', 'packout-core' ),
			'released' => __( 'This Spot is packout.spot\'s, and no mission here has it now.', 'packout-core' ),
		);
		$state = (string) get_post_meta( $spot, self::STATE, true );
		$note  = (string) get_post_meta( $spot, self::NOTE, true );
		$url   = (string) get_post_meta( $spot, self::URL, true );
		return array(
			array(
				'text'   => ( $words[ $state ] ?? $words['open'] ) . ( 'released' === $state && '' !== $note ? ' ' . $note : '' ),
				'url'    => $url,
				'link'   => '' !== $url ? __( 'See it on packout.spot', 'packout-core' ) : '',
				'intent' => 'released' === $state ? 'warning' : 'info',
			),
		);
	}

	/**
	 * Ask the hub that an organization of this site adopt a Place the hub lists.
	 *
	 * @param int    $place The hub's number for the Place.
	 * @param int    $team  The organization here.
	 * @param string $note  What the organization says about it.
	 * @param int    $user  The person asking: a lead of the organization, or a coordinator.
	 * @return true|\WP_Error
	 */
	public static function request_place( int $place, int $team, string $note, int $user ) {
		if ( App::is_hub() ) {
			return App::hub_only();
		}
		if ( ! Teams::get( $team ) || ! Teams::can_run( $team, $user ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'A lead of the organization asks for it.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! Connection::sending() ) {
			return new \WP_Error( 'packout_not_connected', __( 'This site is not sending to packout.spot now. See the Connection screen.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( ! Listings::listable( 'organization', (string) $team ) ) {
			return new \WP_Error( 'packout_not_listed', __( 'packout.spot lists an organization once it is approved and public here. This one is not yet.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( $place <= 0 ) {
			return new \WP_Error( 'packout_hub_place', __( 'Choose the Place, or give its number on packout.spot.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$answer = Connection::send(
			'POST',
			'/place-requests',
			array(
				'place'        => (string) $place,
				'organization' => (string) $team,
				'note'         => mb_substr( sanitize_text_field( $note ), 0, 600 ),
			) + Connection::versions()
		);
		return is_wp_error( $answer ) ? $answer : true;
	}

	/**
	 * On an organization's pane, for its lead: ask to adopt a Place of the hub's.
	 *
	 * @param \WP_Post $team The organization.
	 */
	public static function place_pane( $team ): void {
		if ( ! $team instanceof \WP_Post || ! Connection::sending() || ! Teams::can_run( $team->ID ) || ! Listings::listable( 'organization', (string) $team->ID ) ) {
			return;
		}
		$field  = 'packout-hub-place-' . $team->ID;
		$places = Hub_Layers::on() ? array_slice( Hub_Layers::data()['places'], 0, 200 ) : array();
		echo '<section class="packout-pane__section packout-pane__adopt"><h3>' . esc_html__( 'Adopt a Place on packout.spot', 'packout-core' ) . '</h3>';
		echo '<p>' . esc_html__( 'This organization can ask to adopt a Place that packout.spot lists. A coordinator there answers, and the answer appears on the Connection screen.', 'packout-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-pane__form">';
		echo '<input type="hidden" name="action" value="packout_ask_hub_place" /><input type="hidden" name="team" value="' . esc_attr( (string) $team->ID ) . '" />';
		wp_nonce_field( 'packout_ask_hub_place_' . $team->ID );
		if ( $places ) {
			echo '<p><label for="' . esc_attr( $field ) . '-near">' . esc_html__( 'A Place in this site\'s region', 'packout-core' ) . '</label><br /><select id="' . esc_attr( $field ) . '-near" name="near"><option value="">' . esc_html__( 'Choose a Place', 'packout-core' ) . '</option>';
			foreach ( $places as $place ) {
				echo '<option value="' . esc_attr( (string) $place['id'] ) . '">' . esc_html( $place['name'] ) . '</option>';
			}
			echo '</select></p>';
		}
		echo '<p><label for="' . esc_attr( $field ) . '">' . esc_html__( 'Or the Place\'s number on packout.spot', 'packout-core' ) . '</label><br /><input type="text" id="' . esc_attr( $field ) . '" name="hub_place" class="small-text" inputmode="numeric" /></p>';
		echo '<p><label for="' . esc_attr( $field ) . '-note">' . esc_html__( 'A note for the coordinator', 'packout-core' ) . '</label><br /><textarea id="' . esc_attr( $field ) . '-note" name="note" rows="3" class="large-text" maxlength="600"></textarea></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Ask to adopt this Place', 'packout-core' ) . '</button></p>';
		echo '</form></section>';
	}

	/**
	 * Handle the form of place_pane().
	 */
	public static function place_action(): void {
		Admin::require( ! App::is_hub() && is_user_logged_in() );
		$team = isset( $_POST['team'] ) ? (int) $_POST['team'] : 0;
		check_admin_referer( 'packout_ask_hub_place_' . $team );
		$near  = isset( $_POST['near'] ) ? (int) $_POST['near'] : 0;
		$typed = isset( $_POST['hub_place'] ) ? (int) $_POST['hub_place'] : 0;
		$note  = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$asked = self::request_place( $typed > 0 ? $typed : $near, $team, $note, get_current_user_id() );
		Admin::back(
			Teams_Screen::PAGE,
			( is_wp_error( $asked ) ? array(
				'error'  => 'detail',
				'detail' => $asked->get_error_message(),
			) : array( 'updated' => 'place_asked' ) ) + array( 'open' => (string) $team )
		);
	}

	/**
	 * On a mission's pane, for its lead: take a Spot of the hub's on.
	 *
	 * @param \WP_Post $mission The mission.
	 */
	public static function pane( $mission ): void {
		if ( ! $mission instanceof \WP_Post || ! Connection::sending() || ! Missions::is_open( $mission->ID ) || ! Missions::can_manage( $mission->ID ) ) {
			return;
		}
		$field = 'packout-hub-spot-' . $mission->ID;
		echo '<section class="packout-pane__section packout-pane__adopt"><h3>' . esc_html__( 'Take on a Spot from packout.spot', 'packout-core' ) . '</h3>';
		echo '<p>' . esc_html__( 'A Spot reported on packout.spot can join this mission. packout.spot then shows that your crew plans to work on it, and counts the work when you record it here.', 'packout-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-pane__form">';
		echo '<input type="hidden" name="action" value="packout_adopt_hub_spot" /><input type="hidden" name="mission" value="' . esc_attr( (string) $mission->ID ) . '" />';
		wp_nonce_field( 'packout_adopt_hub_spot_' . $mission->ID );
		$layer = array_slice( self::layer(), 0, 100 );
		if ( $layer ) {
			echo '<p><label for="' . esc_attr( $field ) . '-near">' . esc_html__( 'A Spot in this site\'s region', 'packout-core' ) . '</label><br /><select id="' . esc_attr( $field ) . '-near" name="near"><option value="">' . esc_html__( 'Choose a Spot', 'packout-core' ) . '</option>';
			foreach ( $layer as $spot ) {
				echo '<option value="' . esc_attr( (string) $spot['id'] ) . '">' . esc_html( $spot['title'] . ( '' !== $spot['category'] ? ' (' . $spot['category'] . ')' : '' ) ) . '</option>';
			}
			echo '</select></p>';
		}
		echo '<p><label for="' . esc_attr( $field ) . '">' . esc_html__( 'Or the Spot\'s number on packout.spot, or the address of its page there', 'packout-core' ) . '</label><br /><input type="text" id="' . esc_attr( $field ) . '" name="hub_spot" class="regular-text" /></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Take this Spot on', 'packout-core' ) . '</button></p>';
		echo '</form></section>';
	}

	/**
	 * Handle the form of pane().
	 */
	public static function action(): void {
		Admin::require( ! App::is_hub() && Missions::can_lead() );
		$mission = isset( $_POST['mission'] ) ? (int) $_POST['mission'] : 0;
		check_admin_referer( 'packout_adopt_hub_spot_' . $mission );
		$near  = isset( $_POST['near'] ) ? sanitize_text_field( wp_unslash( $_POST['near'] ) ) : '';
		$typed = isset( $_POST['hub_spot'] ) ? sanitize_text_field( wp_unslash( $_POST['hub_spot'] ) ) : '';
		$made  = self::adopt( $mission, self::number( '' !== $typed ? $typed : $near ), get_current_user_id() );
		if ( is_wp_error( $made ) ) {
			Admin::back(
				Missions_Screen::PAGE,
				array(
					'error'  => 'detail',
					'detail' => $made->get_error_message(),
					'open'   => (string) $mission,
				)
			);
		}
		Admin::back(
			Missions_Screen::PAGE,
			array(
				'updated' => 'adopted',
				'open'    => (string) $mission,
			)
		);
	}
}
