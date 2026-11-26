<?php
/**
 * Place records.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A Place is a standing area that collects Spots over time: a trail, a
 * trailhead, a road corridor, a cluster of campsites, a stretch of creek.
 *
 * The name is the post title and the description is the post content. A
 * listed Place is published and has a public page. A Place can have a cover
 * image, a logo, an outline, teams that adopted it, and sponsors. A
 * coordinator keeps all of it. Adoption is a promise to keep watching. It
 * gives a team no authority over the land.
 */
class Places {

	/**
	 * Most points an outline may have.
	 */
	public const SHAPE_LIMIT = 500;

	/**
	 * Register the public page of a Place, and free the Spots of a removed one.
	 */
	public static function register(): void {
		add_filter( 'the_content', array( self::class, 'page' ) );
		add_action( 'trashed_post', array( self::class, 'trashed' ) );
	}

	/**
	 * Kinds of Place and their labels.
	 *
	 * @return array<string, string>
	 */
	public static function kinds(): array {
		return Vocabularies::labels( Vocabularies::PLACE_KIND );
	}

	/**
	 * A Place post, or null.
	 *
	 * @param int $id Post ID.
	 */
	public static function get( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && Schema::PLACE === $post->post_type && in_array( $post->post_status, array( 'publish', 'private' ), true ) ? $post : null;
	}

	/**
	 * A text field of a Place.
	 *
	 * @param int    $id  Place ID.
	 * @param string $key Field name without the prefix.
	 */
	public static function field( int $id, string $key ): string {
		$value = get_post_meta( $id, '_pos_' . $key, true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The kind of a Place.
	 *
	 * @param int $id Place ID.
	 */
	public static function kind( int $id ): string {
		$kind = self::field( $id, 'kind' );
		return isset( self::kinds()[ $kind ] ) ? $kind : 'other';
	}

	/**
	 * Every Place, by name.
	 *
	 * @param bool $listed_only Only the Places a visitor may read.
	 * @return array<int, \WP_Post>
	 */
	public static function all( bool $listed_only = false ): array {
		$places = get_posts(
			array(
				'post_type'   => Schema::PLACE,
				'post_status' => $listed_only ? array( 'publish' ) : array( 'publish', 'private' ),
				'numberposts' => 300,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		return $listed_only ? array_values( array_filter( $places, static fn( \WP_Post $place ): bool => Post_Access::can_read( $place, 0 ) ) ) : $places;
	}

	/**
	 * Whether a user keeps the Places.
	 *
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_manage( int $user_id = -1 ): bool {
		// Places are the hub's shared geography. A partner site keeps none.
		return App::is_hub() && Capabilities::can( Capabilities::MODERATE, $user_id );
	}

	/**
	 * The outline of a Place: longitude and latitude pairs around it, not closed.
	 *
	 * @param int $id Place ID.
	 * @return array<int, array{0: float, 1: float}>
	 */
	public static function shape( int $id ): array {
		$ring = array();
		foreach ( Schema::stored_list( $id, 'shape' ) as $point ) {
			if ( is_array( $point ) && isset( $point[0], $point[1] ) && is_numeric( $point[0] ) && is_numeric( $point[1] ) ) {
				$ring[] = array( (float) $point[0], (float) $point[1] );
			}
		}
		return count( $ring ) >= 3 ? $ring : array();
	}

	/**
	 * Read an outline a coordinator drew or pasted.
	 *
	 * Accepts GeoJSON (a Polygon, the first polygon of a MultiPolygon, or a
	 * Feature or FeatureCollection holding one) and plain text with one
	 * "latitude, longitude" pair on each line. Holes are dropped.
	 *
	 * @param string $raw What was typed.
	 * @return array<int, array{0: float, 1: float}>|\WP_Error Longitude and latitude pairs. Empty when nothing was typed.
	 */
	public static function parse_shape( string $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}
		$unreadable = new \WP_Error( 'packout_place_shape', __( 'The outline could not be read. Draw it on the map, paste GeoJSON, or type one “latitude, longitude” pair on each line.', 'packout-core' ), array( 'status' => 400 ) );
		$pairs      = array();

		if ( '{' === $raw[0] || '[' === $raw[0] ) {
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) ) {
				return $unreadable;
			}
			$found = self::ring_of( $data );
			if ( null === $found ) {
				return new \WP_Error( 'packout_place_shape', __( 'That is not an area. A line or a point has no inside. Give the outline as a polygon.', 'packout-core' ), array( 'status' => 400 ) );
			}
			foreach ( $found as $point ) {
				if ( ! is_array( $point ) || ! isset( $point[0], $point[1] ) ) {
					return $unreadable;
				}
				$pairs[] = array( $point[1], $point[0] );
			}
		} else {
			foreach ( preg_split( '/\R/', $raw ) as $line ) {
				$line = trim( (string) $line );
				if ( '' === $line ) {
					continue;
				}
				$parts = preg_split( '/[\s,;]+/', $line );
				if ( ! is_array( $parts ) || 2 !== count( $parts ) ) {
					return $unreadable;
				}
				$pairs[] = array( $parts[0], $parts[1] );
			}
		}//end if

		$ring = array();
		foreach ( $pairs as $pair ) {
			$point = Geo::point( $pair[0], $pair[1] );
			if ( ! $point ) {
				return $unreadable;
			}
			$next = array( $point[1], $point[0] );
			if ( $ring && $ring[ count( $ring ) - 1 ] === $next ) {
				continue;
			}
			$ring[] = $next;
		}
		// GeoJSON repeats the first point at the end.
		if ( count( $ring ) > 1 && $ring[0] === $ring[ count( $ring ) - 1 ] ) {
			array_pop( $ring );
		}
		if ( count( $ring ) < 3 ) {
			return new \WP_Error( 'packout_place_shape', __( 'An outline needs at least three points.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( count( $ring ) > self::SHAPE_LIMIT ) {
			/* translators: %d: number of points. */
			return new \WP_Error( 'packout_place_shape', sprintf( __( 'An outline can have up to %d points. Simplify it and try again.', 'packout-core' ), self::SHAPE_LIMIT ), array( 'status' => 400 ) );
		}
		return $ring;
	}

	/**
	 * The outer ring inside a piece of GeoJSON, or null when it holds no area.
	 *
	 * @param array<int|string, mixed> $data Decoded JSON.
	 * @return array<int, mixed>|null
	 */
	private static function ring_of( array $data ): ?array {
		$type = isset( $data['type'] ) && is_string( $data['type'] ) ? $data['type'] : '';
		if ( 'FeatureCollection' === $type ) {
			foreach ( (array) ( $data['features'] ?? array() ) as $feature ) {
				$ring = is_array( $feature ) ? self::ring_of( $feature ) : null;
				if ( null !== $ring ) {
					return $ring;
				}
			}
			return null;
		}
		if ( 'Feature' === $type ) {
			return is_array( $data['geometry'] ?? null ) ? self::ring_of( $data['geometry'] ) : null;
		}
		$coordinates = $data['coordinates'] ?? null;
		if ( 'Polygon' === $type ) {
			return is_array( $coordinates ) && is_array( $coordinates[0] ?? null ) ? $coordinates[0] : null;
		}
		if ( 'MultiPolygon' === $type ) {
			return is_array( $coordinates ) && is_array( $coordinates[0][0] ?? null ) ? $coordinates[0][0] : null;
		}
		// A bare list of [longitude, latitude] pairs, as the map on the Places screen writes it.
		if ( '' === $type && array_is_list( $data ) && is_array( $data[0] ?? null ) && is_numeric( $data[0][0] ?? null ) ) {
			return $data;
		}
		return null;
	}

	/**
	 * Whether a point is inside an outline.
	 *
	 * @param array<int, array{0: float, 1: float}> $ring Longitude and latitude pairs.
	 * @param float                                 $lat  Latitude.
	 * @param float                                 $lng  Longitude.
	 */
	public static function contains( array $ring, float $lat, float $lng ): bool {
		$count  = count( $ring );
		$inside = false;
		if ( $count < 3 ) {
			return false;
		}
		for ( $i = 0, $j = $count - 1; $i < $count; $j = $i++ ) {
			list( $xi, $yi ) = $ring[ $i ];
			list( $xj, $yj ) = $ring[ $j ];
			if ( ( $yi > $lat ) !== ( $yj > $lat ) && $lng < ( $xj - $xi ) * ( $lat - $yi ) / ( $yj - $yi ) + $xi ) {
				$inside = ! $inside;
			}
		}
		return $inside;
	}

	/**
	 * The Place a Spot belongs to, or null.
	 *
	 * @param int $spot_id Spot ID.
	 */
	public static function of_spot( int $spot_id ): ?\WP_Post {
		return self::get( (int) get_post_meta( $spot_id, '_pos_place', true ) );
	}

	/**
	 * The Spots of a Place, newest first.
	 *
	 * @param int $id Place ID.
	 * @return array<int, \WP_Post>
	 */
	public static function spots( int $id ): array {
		return get_posts(
			array(
				'post_type'   => Schema::SPOT,
				'post_status' => Spots::POST_STATUSES,
				'numberposts' => 1000,
				'orderby'     => 'date',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The Spots of one Place.
				'meta_key'    => '_pos_place',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The Spots of one Place.
				'meta_value'  => (string) $id,
			)
		);
	}

	/**
	 * The Spots of a Place a visitor may see, as public payloads.
	 *
	 * @param int $id Place ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function public_spots( int $id ): array {
		$spots = array();
		foreach ( self::spots( $id ) as $spot ) {
			if ( 'public' === Spots::view_for( $spot, 0 ) ) {
				$spots[] = Spots::payload( $spot, 'public' );
			}
		}
		return $spots;
	}

	/**
	 * Spots whose stored pin is inside the outline and that are not in this Place.
	 *
	 * @param int $id Place ID.
	 * @return array{free: array<int, \WP_Post>, elsewhere: array<int, \WP_Post>} Spots with no Place, and Spots of another Place.
	 */
	public static function inside( int $id ): array {
		$found = array(
			'free'      => array(),
			'elsewhere' => array(),
		);
		$ring  = self::shape( $id );
		if ( ! $ring ) {
			return $found;
		}
		$spots = get_posts(
			array(
				'post_type'   => Schema::SPOT,
				'post_status' => Spots::POST_STATUSES,
				'numberposts' => 2000,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Spots that have a pin.
				'meta_query'  => array(
					array(
						'key'     => '_pos_lat',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		foreach ( $spots as $spot ) {
			$pin = Spots::pin( $spot->ID );
			if ( ! $pin || ! self::contains( $ring, $pin[0], $pin[1] ) ) {
				continue;
			}
			$place = self::of_spot( $spot->ID );
			if ( $place && $place->ID === $id ) {
				continue;
			}
			$found[ $place ? 'elsewhere' : 'free' ][] = $spot;
		}
		return $found;
	}

	/**
	 * Attach every Spot inside the outline that has no Place.
	 *
	 * @param int $id      Place ID.
	 * @param int $user_id The coordinator.
	 * @return int|\WP_Error How many were attached.
	 */
	public static function attach_inside( int $id, int $user_id ) {
		if ( ! self::get( $id ) || ! self::can_manage( $user_id ) ) {
			return self::refusal();
		}
		if ( ! self::shape( $id ) ) {
			return new \WP_Error( 'packout_place_shape', __( 'This Place has no outline yet. Draw one, or attach Spots by hand.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$free = self::inside( $id )['free'];
		foreach ( $free as $spot ) {
			update_post_meta( $spot->ID, '_pos_place', $id );
		}
		return count( $free );
	}

	/**
	 * Put a Spot in a Place, or in none.
	 *
	 * @param int $spot_id Spot ID.
	 * @param int $id      Place ID, or 0 for no Place.
	 * @param int $user_id The coordinator.
	 * @return true|\WP_Error
	 */
	public static function attach( int $spot_id, int $id, int $user_id ) {
		if ( ! self::can_manage( $user_id ) ) {
			return self::refusal();
		}
		if ( ! Spots::get( $spot_id ) || ( $id > 0 && ! self::get( $id ) ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That Spot or Place does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( $id > 0 ) {
			update_post_meta( $spot_id, '_pos_place', $id );
		} else {
			delete_post_meta( $spot_id, '_pos_place' );
		}
		return true;
	}

	/**
	 * The teams that adopted a Place, in the order a coordinator recorded them.
	 *
	 * @param int $id Place ID.
	 * @return array<int, array{team: int, name: string, listed: bool, url: string, since: string, note: string}>
	 */
	public static function adopters( int $id ): array {
		$rows = Schema::stored_list( $id, 'adopters' );
		if ( ! $rows && (int) self::field( $id, 'team' ) > 0 ) {
			// A Place saved when a Place had one adopter.
			$rows = array(
				array(
					'team'  => (int) self::field( $id, 'team' ),
					'since' => self::field( $id, 'adopted_on' ),
					'note'  => self::field( $id, 'adoption_note' ),
				),
			);
		}
		$adopters = array();
		foreach ( $rows as $row ) {
			$team = is_array( $row ) ? Teams::get( (int) ( $row['team'] ?? 0 ) ) : null;
			if ( ! $team || isset( $adopters[ $team->ID ] ) ) {
				continue;
			}
			$listed                = 'publish' === $team->post_status && Post_Access::can_read( $team, 0 );
			$adopters[ $team->ID ] = array(
				'team'   => $team->ID,
				'name'   => $team->post_title,
				'listed' => $listed,
				'url'    => $listed ? (string) get_permalink( $team ) : '',
				'since'  => Schema::day( $row['since'] ?? '' ),
				'note'   => is_string( $row['note'] ?? null ) ? $row['note'] : '',
			);
		}
		return array_values( $adopters );
	}

	/**
	 * The first team that adopted a Place, or null.
	 *
	 * @param int $id Place ID.
	 * @return array{team: int, name: string, listed: bool, url: string, since: string, note: string}|null
	 */
	public static function adoption( int $id ): ?array {
		return self::adopters( $id )[0] ?? null;
	}

	/**
	 * Store the adopters of a Place.
	 *
	 * The first is also kept in the fields a Place had when it could have one adopter.
	 *
	 * @param int                                                          $id   Place ID.
	 * @param array<int, array{team: int, since: string, note: string}> $rows Adopters.
	 */
	/**
	 * Add or update an adopter without a capability check. Callers have already checked.
	 *
	 * @param int                  $id    Place ID.
	 * @param int                  $team  Organization ID.
	 * @param array<string, mixed> $input adopted_on, adoption_note.
	 */
	private static function put_adopter( int $id, int $team, array $input ): void {
		$rows  = self::adopter_rows( $id );
		$index = array_search( $team, array_column( $rows, 'team' ), true );
		$since = Schema::day( $input['adopted_on'] ?? '' );
		$row   = false === $index ? array(
			'team'  => $team,
			'since' => wp_date( 'Y-m-d' ),
			'note'  => '',
		) : $rows[ $index ];
		if ( '' !== $since ) {
			$row['since'] = $since;
		}
		if ( array_key_exists( 'adoption_note', $input ) ) {
			$row['note'] = mb_substr( sanitize_text_field( (string) $input['adoption_note'] ), 0, 300 );
		}
		if ( false === $index ) {
			$rows[] = $row;
		} else {
			$rows[ $index ] = $row;
		}
		self::write_adopters( $id, $rows );
	}

	/**
	 * Remove an adopter without a capability check.
	 *
	 * @param int $id   Place ID.
	 * @param int $team Organization ID.
	 */
	private static function drop_adopter( int $id, int $team ): void {
		self::write_adopters( $id, array_filter( self::adopter_rows( $id ), static fn( array $row ): bool => $row['team'] !== $team ) );
	}

	/**
	 * Mark matching pending requests when a coordinator records or ends an adoption.
	 *
	 * @param int    $id      Place ID.
	 * @param int    $team    Organization ID.
	 * @param string $status  accepted or declined.
	 * @param int    $user_id Who decided.
	 */
	private static function mark_request( int $id, int $team, string $status, int $user_id ): void {
		$rows  = Schema::stored_list( $id, 'adoption_requests' );
		$wrote = false;
		foreach ( $rows as $position => $row ) {
			if ( ! is_array( $row ) || (int) ( $row['team_id'] ?? 0 ) !== $team ) {
				continue;
			}
			if ( 'accepted' === $status && 'accepted' === ( $row['status'] ?? '' ) ) {
				continue;
			}
			if ( 'declined' === $status && 'pending' !== ( $row['status'] ?? '' ) && 'accepted' !== ( $row['status'] ?? '' ) ) {
				continue;
			}
			$rows[ $position ]['status']     = $status;
			$rows[ $position ]['decided_on'] = gmdate( 'c' );
			$rows[ $position ]['decided_by'] = $user_id;
			$wrote                           = true;
		}
		if ( $wrote ) {
			Schema::write_list( $id, 'adoption_requests', $rows );
		}
	}

	/**
	 * A stored adoption request with names filled in, or null.
	 *
	 * @param int                  $place_id Place ID.
	 * @param array<string, mixed> $row      Stored row.
	 * @return array<string, mixed>|null
	 */
	private static function tidy_request( int $place_id, array $row ): ?array {
		$id   = (string) ( $row['id'] ?? '' );
		$team = Teams::get( (int) ( $row['team_id'] ?? 0 ) );
		if ( '' === $id || ! $team ) {
			return null;
		}
		$who     = (int) ( $row['user_id'] ?? 0 );
		$person  = $who > 0 ? get_userdata( $who ) : null;
		$feature = (int) ( $row['feature_id'] ?? 0 );
		$held    = $feature > 0 ? Features::get( $feature ) : null;
		$place   = self::get( $place_id );
		return array(
			'id'         => $id,
			'place_id'   => $place_id,
			'place'      => $place ? $place->post_title : '',
			'place_url'  => $place && 'publish' === $place->post_status ? (string) get_permalink( $place ) : '',
			'team_id'    => $team->ID,
			'team'       => $team->post_title,
			'user_id'    => $who,
			'user'       => $person ? $person->display_name : '',
			'feature_id' => $held ? $held->ID : 0,
			'feature'    => $held ? $held->post_title : '',
			'note'       => is_string( $row['note'] ?? null ) ? $row['note'] : '',
			'status'     => in_array( $row['status'] ?? '', array( 'pending', 'accepted', 'declined' ), true ) ? $row['status'] : 'pending',
			'created'    => (string) ( $row['created'] ?? '' ),
			'decided_on' => (string) ( $row['decided_on'] ?? '' ),
			'decided_by' => (int) ( $row['decided_by'] ?? 0 ),
		);
	}

	/**
	 * Store a Place's adoption requests.
	 *
	 * @param int                              $id   Place ID.
	 * @param array<int, array<string, mixed>> $rows Requests.
	 */
	private static function write_adopters( int $id, array $rows ): void {
		$rows = array_values( $rows );
		Schema::write_list( $id, 'adopters', $rows );
		if ( $rows ) {
			update_post_meta( $id, '_pos_team', (int) $rows[0]['team'] );
			update_post_meta( $id, '_pos_adopted_on', $rows[0]['since'] );
			update_post_meta( $id, '_pos_adoption_note', wp_slash( $rows[0]['note'] ) );
		} else {
			foreach ( array( 'team', 'adopted_on', 'adoption_note' ) as $key ) {
				delete_post_meta( $id, '_pos_' . $key );
			}
		}
	}

	/**
	 * The stored adopters of a Place, as write_adopters() takes them.
	 *
	 * @param int $id Place ID.
	 * @return array<int, array{team: int, since: string, note: string}>
	 */
	private static function adopter_rows( int $id ): array {
		return array_map(
			static fn( array $adopter ): array => array(
				'team'  => $adopter['team'],
				'since' => $adopter['since'],
				'note'  => $adopter['note'],
			),
			self::adopters( $id )
		);
	}

	/**
	 * Record that a team adopted a Place, or change the date and note of an adoption.
	 *
	 * A Place can have several adopters. Each is a promise to keep watching it.
	 *
	 * @param int                  $id      Place ID.
	 * @param int                  $team    Team ID.
	 * @param array<string, mixed> $input   adopted_on, adoption_note.
	 * @param int                  $user_id The coordinator.
	 * @return true|\WP_Error
	 */
	public static function adopt( int $id, int $team, array $input, int $user_id ) {
		if ( ! self::get( $id ) || ! self::can_manage( $user_id ) ) {
			return self::refusal();
		}
		$rows  = self::adopter_rows( $id );
		$index = array_search( $team, array_column( $rows, 'team' ), true );
		if ( false === $index && ( ! Teams::get( $team ) || ! Teams::active( $team ) ) ) {
			return new \WP_Error( 'packout_place_team', __( 'A Place is adopted by an organization a coordinator has approved.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$since = Schema::day( $input['adopted_on'] ?? '' );
		$row   = false === $index ? array(
			'team'  => $team,
			'since' => wp_date( 'Y-m-d' ),
			'note'  => '',
		) : $rows[ $index ];
		if ( '' !== $since ) {
			$row['since'] = $since;
		}
		if ( array_key_exists( 'adoption_note', $input ) ) {
			$row['note'] = mb_substr( sanitize_text_field( (string) $input['adoption_note'] ), 0, 300 );
		}
		if ( false === $index ) {
			$rows[] = $row;
		} else {
			$rows[ $index ] = $row;
		}
		self::write_adopters( $id, $rows );
		if ( false === $index ) {
			/** This action is documented in save(). */
			do_action( 'packout_place_adopted', $id, $team );
			self::mark_request( $id, $team, 'accepted', $user_id );
		}
		return true;
	}

	/**
	 * Record that a team no longer adopts a Place.
	 *
	 * @param int $id      Place ID.
	 * @param int $team    Team ID.
	 * @param int $user_id The coordinator.
	 * @return true|\WP_Error
	 */
	public static function release( int $id, int $team, int $user_id ) {
		if ( ! self::get( $id ) || ! self::can_manage( $user_id ) ) {
			return self::refusal();
		}
		self::write_adopters( $id, array_filter( self::adopter_rows( $id ), static fn( array $row ): bool => $row['team'] !== $team ) );
		self::mark_request( $id, $team, 'declined', $user_id );
		return true;
	}

	/**
	 * Adoption requests on a Place.
	 *
	 * @param int $id Place ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function adoption_requests( int $id ): array {
		$list = array();
		foreach ( Schema::stored_list( $id, 'adoption_requests' ) as $row ) {
			$tidy = self::tidy_request( $id, is_array( $row ) ? $row : array() );
			if ( $tidy ) {
				$list[] = $tidy;
			}
		}
		return $list;
	}

	/**
	 * Adoption requests that name an organization, newest first, across Places.
	 *
	 * @param int $team_id Organization ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function adoption_requests_for_team( int $team_id ): array {
		$found = array();
		foreach ( self::all() as $place ) {
			foreach ( self::adoption_requests( $place->ID ) as $row ) {
				if ( $row['team_id'] === $team_id ) {
					$found[] = $row;
				}
			}
		}
		usort( $found, static fn( $a, $b ) => strcmp( (string) $b['created'], (string) $a['created'] ) );
		return $found;
	}

	/**
	 * Ask that an organization adopt a Place.
	 *
	 * @param int                  $id      Place ID.
	 * @param int                  $team    Organization ID.
	 * @param int                  $user_id The member asking.
	 * @param array<string, mixed> $input   note, feature_id.
	 * @return string|\WP_Error Request id.
	 */
	public static function request_adoption( int $id, int $team, int $user_id, array $input = array() ) {
		// A partner site asks the hub, through the partner interface.
		if ( ! App::is_hub() ) {
			return App::hub_only();
		}
		if ( ! self::get( $id ) ) {
			return self::refusal();
		}
		if ( ! Teams::get( $team ) || ! Teams::active( $team ) || ! Teams::includes( $team, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'Ask for an organization you belong to, that a coordinator has approved.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$feature = (int) ( $input['feature_id'] ?? 0 );
		if ( $feature > 0 && ( ! Features::get( $feature ) || Features::place( $feature ) !== $id ) ) {
			$feature = 0;
		}
		$rows = Schema::stored_list( $id, 'adoption_requests' );
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && (int) ( $row['team_id'] ?? 0 ) === $team && 'pending' === ( $row['status'] ?? '' ) ) {
				return new \WP_Error( 'packout_place_request', __( 'That organization already has a request waiting for this Place.', 'packout-core' ), array( 'status' => 409 ) );
			}
		}
		if ( in_array( $team, array_column( self::adopters( $id ), 'team' ), true ) ) {
			return new \WP_Error( 'packout_place_request', __( 'That organization already adopts this Place.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$request = array(
			'id'         => wp_generate_uuid4(),
			'team_id'    => $team,
			'user_id'    => $user_id,
			'feature_id' => $feature,
			'note'       => mb_substr( sanitize_text_field( (string) ( $input['note'] ?? '' ) ), 0, 300 ),
			'status'     => 'pending',
			'created'    => gmdate( 'c' ),
			'decided_on' => '',
			'decided_by' => 0,
		);
		$rows[]  = $request;
		Schema::write_list( $id, 'adoption_requests', $rows );
		do_action( 'packout_place_adoption_requested', $id, $team, $user_id, $request['id'] );
		foreach ( array_filter( array_map( 'get_userdata', Teams::leads( $team ) ) ) as $lead ) {
			if ( (int) $lead->ID === $user_id ) {
				continue;
			}
			Approvals::send(
				$lead->user_email,
				'adopt',
				(int) $lead->ID,
				array(
					'place'   => $id,
					'request' => $request['id'],
				)
			);
		}
		return $request['id'];
	}

	/**
	 * Keep a request from a partner site that one of its organizations adopt a Place. The hub only.
	 *
	 * The organization's listing has no lead here, so a coordinator answers.
	 *
	 * @param int    $id   Place ID. A Place the public sees.
	 * @param int    $team The organization's listing on the hub.
	 * @param string $note What the organization wrote.
	 * @return string|\WP_Error Request id.
	 */
	public static function request_from_partner( int $id, int $team, string $note ) {
		$place = self::get( $id );
		if ( ! App::is_hub() || ! $place || 'publish' !== $place->post_status || '' !== (string) $place->post_password ) {
			return new \WP_Error( 'unknown_place', __( 'That Place is not listed on packout.spot.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$listing = get_post( $team );
		if ( ! $listing instanceof \WP_Post || Schema::TEAM !== $listing->post_type || 'publish' !== $listing->post_status || ! Remote::is( $team ) ) {
			return new \WP_Error( 'missing_reference', __( 'That organization is not listed on packout.spot.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$rows = Schema::stored_list( $id, 'adoption_requests' );
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && (int) ( $row['team_id'] ?? 0 ) === $team && 'pending' === ( $row['status'] ?? '' ) ) {
				return (string) ( $row['id'] ?? '' );
			}
		}
		if ( in_array( $team, array_column( self::adopters( $id ), 'team' ), true ) ) {
			return new \WP_Error( 'already_adopted', __( 'That organization already adopts this Place.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$request = array(
			'id'         => wp_generate_uuid4(),
			'team_id'    => $team,
			'user_id'    => 0,
			'feature_id' => 0,
			'note'       => mb_substr( sanitize_text_field( $note ), 0, 300 ),
			'status'     => 'pending',
			'created'    => gmdate( 'c' ),
			'decided_on' => '',
			'decided_by' => 0,
		);
		$rows[]  = $request;
		Schema::write_list( $id, 'adoption_requests', $rows );
		/** This action is documented in request_adoption(). */
		do_action( 'packout_place_adoption_requested', $id, $team, 0, $request['id'] );
		return $request['id'];
	}

	/**
	 * Accept, decline, or change an adoption request. A lead may edit the note.
	 *
	 * @param int                  $id      Place ID.
	 * @param string               $request Request id.
	 * @param int                  $user_id The person answering.
	 * @param array<string, mixed> $input   status (accepted|declined), note.
	 * @return true|\WP_Error
	 */
	public static function decide_request( int $id, string $request, int $user_id, array $input = array() ) {
		if ( ! App::is_hub() ) {
			return App::hub_only();
		}
		if ( ! self::get( $id ) ) {
			return self::refusal();
		}
		$rows  = Schema::stored_list( $id, 'adoption_requests' );
		$index = null;
		foreach ( $rows as $position => $row ) {
			if ( is_array( $row ) && (string) ( $row['id'] ?? '' ) === $request ) {
				$index = $position;
				break;
			}
		}
		if ( null === $index ) {
			return new \WP_Error( 'packout_not_found', __( 'That request is not on this Place.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$team = (int) ( $rows[ $index ]['team_id'] ?? 0 );
		if ( ! Teams::can_run( $team, $user_id ) && ! self::can_manage( $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead answers adoption requests.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( array_key_exists( 'note', $input ) ) {
			$rows[ $index ]['note'] = mb_substr( sanitize_text_field( (string) $input['note'] ), 0, 300 );
		}
		$status = sanitize_key( (string) ( $input['status'] ?? '' ) );
		if ( '' !== $status ) {
			if ( ! in_array( $status, array( 'accepted', 'declined' ), true ) ) {
				return new \WP_Error( 'packout_place_request', __( 'Accept or decline the request.', 'packout-core' ), array( 'status' => 400 ) );
			}
			$was                          = (string) ( $rows[ $index ]['status'] ?? 'pending' );
			$rows[ $index ]['status']     = $status;
			$rows[ $index ]['decided_on'] = gmdate( 'c' );
			$rows[ $index ]['decided_by'] = $user_id;
			Schema::write_list( $id, 'adoption_requests', $rows );
			if ( 'accepted' === $status && 'accepted' !== $was ) {
				self::put_adopter( $id, $team, array( 'adoption_note' => (string) ( $rows[ $index ]['note'] ?? '' ) ) );
				do_action( 'packout_place_adopted', $id, $team );
			} elseif ( 'declined' === $status && 'accepted' === $was ) {
				self::drop_adopter( $id, $team );
			}
			if ( $status !== $was ) {
				/**
				 * A request to adopt a Place was answered.
				 *
				 * @param int    $id     Place ID.
				 * @param int    $team   The organization.
				 * @param string $status accepted or declined.
				 */
				do_action( 'packout_place_request_decided', $id, $team, $status );
			}
			return true;
		}//end if
		Schema::write_list( $id, 'adoption_requests', $rows );
		return true;
	}

	/**
	 * The Places a team has adopted, by name.
	 *
	 * @param int  $team_id     Team ID.
	 * @param bool $listed_only Only the Places a visitor may read.
	 * @return array<int, \WP_Post>
	 */
	public static function of_team( int $team_id, bool $listed_only = false ): array {
		return array_values( array_filter( self::all( $listed_only ), static fn( \WP_Post $place ): bool => in_array( $team_id, array_column( self::adopters( $place->ID ), 'team' ), true ) ) );
	}

	/**
	 * The cover image of a Place: an attachment ID, or 0.
	 *
	 * @param int $id Place ID.
	 */
	public static function cover( int $id ): int {
		return self::image( $id, 'cover' );
	}

	/**
	 * The logo of a Place: an attachment ID, or 0.
	 *
	 * @param int $id Place ID.
	 */
	public static function logo( int $id ): int {
		return self::image( $id, 'logo' );
	}

	/**
	 * An image of a Place.
	 *
	 * @param int    $id  Place ID.
	 * @param string $key `cover` or `logo`.
	 */
	private static function image( int $id, string $key ): int {
		$image = (int) self::field( $id, $key );
		return $image > 0 && 'attachment' === get_post_type( $image ) ? $image : 0;
	}

	/**
	 * Upload, replace, or remove the cover image or the logo of a Place.
	 *
	 * @param int                       $id      Place ID.
	 * @param string                    $key     `cover` or `logo`.
	 * @param array<string, mixed>|null $file    One entry from the request files, or null to remove the image.
	 * @param int                       $user_id The coordinator.
	 * @return int|\WP_Error Attachment ID, or 0 when the image was removed.
	 */
	public static function set_image( int $id, string $key, ?array $file, int $user_id ) {
		if ( ! in_array( $key, array( 'cover', 'logo' ), true ) || ! self::get( $id ) || ! self::can_manage( $user_id ) ) {
			return self::refusal();
		}
		$stored = 0;
		if ( null !== $file ) {
			$stored = Photos::store( $id, $file, false, $key );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
		}
		$old = self::image( $id, $key );
		if ( $old > 0 ) {
			wp_delete_attachment( $old, true );
		}
		if ( $stored > 0 ) {
			update_post_meta( $id, '_pos_' . $key, $stored );
		} else {
			delete_post_meta( $id, '_pos_' . $key );
		}
		return $stored;
	}

	/**
	 * The missions a visitor may know about that are planned or under way at a Place, soonest first.
	 *
	 * @param int $id Place ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function missions( int $id ): array {
		$spots = wp_list_pluck( self::spots( $id ), 'ID' );
		if ( ! $spots ) {
			return array();
		}
		$found = get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => 'publish',
				'numberposts' => 200,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Missions that include a Spot of one Place.
				'meta_query'  => array(
					array(
						'key'     => '_pos_spot',
						'value'   => array_map( 'strval', $spots ),
						'compare' => 'IN',
					),
				),
			)
		);
		$rows  = array();
		foreach ( $found as $mission ) {
			if ( Missions::is_open( $mission->ID ) && Post_Access::can_read( $mission, 0 ) ) {
				$row          = Missions::public_payload( $mission );
				$row['start'] = Missions::field( $mission->ID, 'start' );
				$rows[]       = $row;
			}
		}
		// A mission with no date goes last.
		usort( $rows, static fn( $a, $b ) => strcmp( '' !== $a['start'] ? $a['start'] : '9999', '' !== $b['start'] ? $b['start'] : '9999' ) );
		return $rows;
	}

	/**
	 * Create or change a Place.
	 *
	 * @param int                  $id      Place ID, or 0 for a new one.
	 * @param array<string, mixed> $input   name, description, kind, shape, listed, team, adopted_on, adoption_note.
	 * @param int                  $user_id The coordinator.
	 * @return int|\WP_Error Place ID.
	 */
	public static function save( int $id, array $input, int $user_id ) {
		$existing = $id > 0 ? self::get( $id ) : null;
		if ( $id > 0 && ! $existing ) {
			return new \WP_Error( 'packout_not_found', __( 'That Place does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_manage( $user_id ) ) {
			return self::refusal();
		}
		$name = sanitize_text_field( (string) ( $input['name'] ?? ( $existing ? $existing->post_title : '' ) ) );
		if ( '' === $name || mb_strlen( $name ) > 120 ) {
			return new \WP_Error( 'packout_place_name', __( 'Give the Place a name of up to 120 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$ring = null;
		if ( array_key_exists( 'shape', $input ) ) {
			$ring = self::parse_shape( (string) $input['shape'] );
			if ( is_wp_error( $ring ) ) {
				return $ring;
			}
		}
		// `team` names the only adopter. A Place with several is kept with adopt() and release().
		$sole   = array_key_exists( 'team', $input );
		$before = $existing ? array_column( self::adopters( $existing->ID ), 'team' ) : array();
		$team   = $sole ? (int) $input['team'] : 0;
		if ( $team > 0 && ! in_array( $team, $before, true ) && ( ! Teams::get( $team ) || ! Teams::active( $team ) ) ) {
			return new \WP_Error( 'packout_place_team', __( 'A Place is adopted by an organization a coordinator has approved.', 'packout-core' ), array( 'status' => 400 ) );
		}

		$listed = array_key_exists( 'listed', $input ) ? ! empty( $input['listed'] ) : ( $existing ? 'publish' === $existing->post_status : false );
		$post   = array(
			'post_type'    => Schema::PLACE,
			'post_status'  => $listed ? 'publish' : 'private',
			'post_title'   => wp_slash( $name ),
			'post_content' => wp_slash( sanitize_textarea_field( (string) ( $input['description'] ?? ( $existing ? $existing->post_content : '' ) ) ) ),
		);
		if ( $existing ) {
			$post['ID'] = $existing->ID;
			$saved      = wp_update_post( $post, true );
		} else {
			$post['post_author'] = $user_id;
			$saved               = wp_insert_post( $post, true );
		}
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$saved = (int) $saved;

		if ( array_key_exists( 'kind', $input ) || ! $existing ) {
			$kind = sanitize_key( (string) ( $input['kind'] ?? '' ) );
			$kind = isset( self::kinds()[ $kind ] ) ? $kind : 'other';
			update_post_meta( $saved, '_pos_kind', $kind );
			Vocabularies::assign( $saved, Vocabularies::PLACE_KIND, $kind );
		}
		if ( null !== $ring ) {
			Schema::write_list( $saved, 'shape', $ring );
		}

		if ( $sole && $team > 0 ) {
			$kept  = array_values( array_filter( self::adopters( $saved ), static fn( array $adopter ): bool => $adopter['team'] === $team ) );
			$since = Schema::day( $input['adopted_on'] ?? ( $kept ? $kept[0]['since'] : '' ) );
			if ( '' === $since ) {
				$since = wp_date( 'Y-m-d' );
			}
			self::write_adopters(
				$saved,
				array(
					array(
						'team'  => $team,
						'since' => $since,
						'note'  => array_key_exists( 'adoption_note', $input ) ? mb_substr( sanitize_text_field( (string) $input['adoption_note'] ), 0, 300 ) : ( $kept ? $kept[0]['note'] : '' ),
					),
				)
			);
		} elseif ( $sole ) {
			self::write_adopters( $saved, array() );
		}

		if ( $team > 0 && ! in_array( $team, $before, true ) ) {
			/**
			 * A coordinator recorded that a team adopted a Place.
			 *
			 * @param int $saved Place ID.
			 * @param int $team  Team ID.
			 */
			do_action( 'packout_place_adopted', $saved, $team );
		}
		return $saved;
	}

	/**
	 * Move a Place to the trash. Its Spots stay, in no Place.
	 *
	 * @param int $id      Place ID.
	 * @param int $user_id The coordinator.
	 * @return true|\WP_Error
	 */
	public static function remove( int $id, int $user_id ) {
		if ( ! self::get( $id ) || ! self::can_manage( $user_id ) ) {
			return self::refusal();
		}
		wp_trash_post( $id );
		return true;
	}

	/**
	 * Free the Spots of a Place that went to the trash, however it got there.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public static function trashed( $post_id ): void {
		$id = (int) $post_id;
		if ( Schema::PLACE !== get_post_type( $id ) ) {
			return;
		}
		foreach ( self::spots( $id ) as $spot ) {
			delete_post_meta( $spot->ID, '_pos_place' );
		}
	}

	/**
	 * Impact totals for the Spots of a Place.
	 *
	 * @param int  $id      Place ID.
	 * @param bool $visible Only the records a coordinator counts publicly.
	 * @return array<string, array<string, int|float>>
	 */
	public static function totals( int $id, bool $visible = false ): array {
		return Impact::totals(
			array(
				'spots'   => wp_list_pluck( self::spots( $id ), 'ID' ),
				'visible' => $visible,
			)
		);
	}

	/**
	 * The cleanups at a Place that a coordinator counts publicly, newest first.
	 *
	 * No person is named. A team is named when it is listed.
	 *
	 * @param int $id Place ID.
	 * @return array<int, array{date: string, spot: string, url: string, team: string, summary: string, verified: bool}>
	 */
	public static function history( int $id ): array {
		$rows = array();
		foreach ( Impact::found(
			array(
				'spots' => wp_list_pluck( self::spots( $id ), 'ID' ),
				'kind'  => Impact::CLEANUP,
			)
		) as $record ) {
			$spot = Spots::get( (int) Impact::field( $record->ID, 'spot', '0' ) );
			if ( 'publish' !== $record->post_status || ! $spot || 'public' !== Spots::view_for( $spot, 0 ) ) {
				continue;
			}
			$team   = Teams::get( (int) Impact::field( $record->ID, 'team', '0' ) );
			$rows[] = array(
				'date'     => Impact::field( $record->ID, 'date' ),
				'spot'     => $spot->post_title,
				'url'      => (string) get_permalink( $spot ),
				'team'     => $team && 'publish' === $team->post_status ? $team->post_title : '',
				'summary'  => Impact::summary( $record->ID ),
				'verified' => Impact::verified( $record->ID ),
			);
		}
		usort( $rows, static fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );
		return $rows;
	}

	/**
	 * What the public may know about a listed Place.
	 *
	 * @param \WP_Post $place Place.
	 * @return array<string, mixed>
	 */
	public static function public_payload( \WP_Post $place ): array {
		$id       = $place->ID;
		$spots    = self::public_spots( $id );
		$adopters = array();
		foreach ( self::adopters( $id ) as $adopter ) {
			// A team that is not listed is not named, described, or shown by its logo.
			$team       = $adopter['listed'] ? Teams::get( $adopter['team'] ) : null;
			$adopters[] = array(
				'team'  => $team ? $adopter['name'] : '',
				'url'   => $adopter['url'],
				'since' => $adopter['since'],
				'note'  => $adopter['note'],
				'about' => $team ? wp_trim_words( $team->post_content, 36 ) : '',
				'logo'  => $team ? Teams::logo( $team->ID ) : 0,
			);
		}
		$adoption = $adopters[0] ?? null;
		$open     = array_values( array_filter( $spots, static fn( $spot ) => 'cleaned' !== $spot['status'] ) );
		return array(
			'id'          => $id,
			'name'        => $place->post_title,
			'url'         => (string) get_permalink( $place ),
			'description' => $place->post_content,
			'kind'        => self::kind( $id ),
			'kind_label'  => self::kinds()[ self::kind( $id ) ],
			'shape'       => self::shape( $id ),
			'cover'       => self::cover( $id ),
			'logo'        => self::logo( $id ),
			// The first adopter, and all of them.
			'adoption'    => $adoption,
			'adopters'    => $adopters,
			'spots'       => $spots,
			'open'        => count( $open ),
			'cleaned'     => count( $spots ) - count( $open ),
			'sponsors'    => Sponsors::all( $id ),
		);
	}

	/**
	 * Listed Places for the Place list block.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function listed(): array {
		return array_map( array( self::class, 'public_payload' ), self::all( true ) );
	}

	/**
	 * The outlines of listed Places, for the public map.
	 *
	 * @return array<int, array{id: int, name: string, url: string, ring: array<int, array{0: float, 1: float}>}>
	 */
	public static function outlines(): array {
		$outlines = array();
		foreach ( self::all( true ) as $place ) {
			$ring = self::shape( $place->ID );
			if ( $ring ) {
				$outlines[] = array(
					'id'   => $place->ID,
					'name' => $place->post_title,
					'url'  => (string) get_permalink( $place ),
					'ring' => $ring,
				);
			}
		}
		return $outlines;
	}

	/**
	 * The adoption of a Place in one sentence, as the public is told it.
	 *
	 * @param array{team: string, url: string, since: string, note: string} $adoption From public_payload().
	 * @param bool                                                          $link     Whether to link the team's name.
	 */
	public static function adoption_line( array $adoption, bool $link = true ): string {
		$since = '' !== $adoption['since'] ? Schema::span( $adoption['since'], '' ) : '';
		if ( '' === $adoption['team'] ) {
			return esc_html(
				'' !== $since
					/* translators: %s: date. */
					? sprintf( __( 'Adopted by an organization since %s.', 'packout-core' ), $since )
					: __( 'Adopted by an organization.', 'packout-core' )
			);
		}
		$team = $link && '' !== $adoption['url'] ? '<a href="' . esc_url( $adoption['url'] ) . '">' . esc_html( $adoption['team'] ) . '</a>' : esc_html( $adoption['team'] );
		return '' !== $since
			/* translators: 1: organization name, 2: date. */
			? sprintf( esc_html__( 'Adopted by %1$s since %2$s.', 'packout-core' ), $team, esc_html( $since ) )
			/* translators: %s: organization name. */
			: sprintf( esc_html__( 'Adopted by %s.', 'packout-core' ), $team );
	}

	/**
	 * The adopters of a Place in one sentence, as the public is told it.
	 *
	 * A listed team is named and linked. A team that is not listed is counted and not named.
	 *
	 * @param array<int, array{team: string, url: string, since: string, note: string}> $adopters From public_payload().
	 * @param bool                                                                      $link     Whether to link each team's name.
	 */
	public static function adopters_line( array $adopters, bool $link = true ): string {
		if ( ! $adopters ) {
			return '';
		}
		if ( 1 === count( $adopters ) ) {
			return self::adoption_line( $adopters[0], $link );
		}
		$names  = array();
		$hidden = 0;
		foreach ( $adopters as $adopter ) {
			if ( '' === $adopter['team'] ) {
				++$hidden;
				continue;
			}
			$names[] = $link && '' !== $adopter['url'] ? '<a href="' . esc_url( $adopter['url'] ) . '">' . esc_html( $adopter['team'] ) . '</a>' : esc_html( $adopter['team'] );
		}
		if ( ! $names ) {
			/* translators: %s: number of organizations. */
			return esc_html( sprintf( _n( 'Adopted by %s organization.', 'Adopted by %s organizations.', $hidden, 'packout-core' ), number_format_i18n( $hidden ) ) );
		}
		if ( $hidden > 0 ) {
			/* translators: %s: number of organizations. */
			$names[] = esc_html( sprintf( _n( '%s other organization', '%s other organizations', $hidden, 'packout-core' ), number_format_i18n( $hidden ) ) );
		}
		/* translators: %s: list of organization names. */
		return sprintf( esc_html__( 'Adopted by %s.', 'packout-core' ), wp_sprintf( '%l', $names ) );
	}

	/**
	 * The public page of a listed Place.
	 *
	 * A header with the cover image, or a dark ground when there is none,
	 * holds the name and description on one side and the sponsors on the
	 * other. Below it come the adopting teams, the impact recorded here, the
	 * missions that are planned, the Spots on a map, and the history of the
	 * work. A coarse Spot is shown at its coarse point, and no person is named.
	 *
	 * @param string $content Post content.
	 */
	public static function page( $content ) {
		if ( ! is_singular( Schema::PLACE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return '';
		}

		wp_enqueue_style( 'packout-workspace' );
		wp_enqueue_style( 'packout-blocks' );
		$place  = self::public_payload( $post );
		$labels = Schema::statuses();
		$open   = array_values( array_filter( $place['spots'], static fn( $spot ) => 'cleaned' !== $spot['status'] ) );

		// The header.
		$facts = array(
			esc_html(
				sprintf(
					/* translators: 1: number of open Spots, 2: number of cleaned Spots. */
					__( '%1$s open · %2$s cleaned', 'packout-core' ),
					number_format_i18n( $place['open'] ),
					number_format_i18n( $place['cleaned'] )
				)
			),
		);
		if ( $place['adopters'] ) {
			$facts[] = self::adopters_line( $place['adopters'] );
		}
		$html  = '<div class="pos-campaign-page pos-place-page pos-place">';
		$html .= Page_Header::render(
			array(
				'cover'       => $place['cover'],
				'logo'        => $place['logo'],
				/* translators: %s: Place name. */
				'logo_alt'    => sprintf( __( 'Logo of %s', 'packout-core' ), $place['name'] ),
				'kind'        => $place['kind_label'],
				'title'       => $place['name'],
				'description' => $place['description'],
				'facts'       => $facts,
				'sponsors'    => $place['sponsors'],
			)
		);
		$html .= '<div class="pos-place__body">';

		// The adopting teams.
		$html .= '<section class="pos-place__section"><h2>' . esc_html__( 'Adopted by', 'packout-core' ) . '</h2>';
		if ( ! $place['adopters'] ) {
			$html .= '<p>' . esc_html__( 'No organization has adopted this Place yet. A member of an organization can ask its lead to adopt it.', 'packout-core' ) . '</p>';
		} else {
			$html .= '<ul class="pos-place__adopters">';
			foreach ( $place['adopters'] as $adopter ) {
				$named = '' !== $adopter['team'];
				$html .= '<li class="pos-place__adopter">';
				if ( $adopter['logo'] > 0 ) {
					$html .= wp_get_attachment_image(
						$adopter['logo'],
						'medium',
						false,
						array(
							'class'   => 'pos-place__adopter-logo',
							'alt'     => '',
							'loading' => 'lazy',
						)
					);
				}
				$html .= '<div class="pos-place__adopter-text"><h3>';
				if ( $named && '' !== $adopter['url'] ) {
					$html .= '<a href="' . esc_url( $adopter['url'] ) . '">' . esc_html( $adopter['team'] ) . '</a>';
				} else {
					$html .= esc_html( $named ? $adopter['team'] : __( 'An organization that is not listed', 'packout-core' ) );
				}
				$html .= '</h3>';
				if ( '' !== $adopter['since'] ) {
					/* translators: %s: date. */
					$html .= '<p class="pos-place__since">' . esc_html( sprintf( __( 'Since %s', 'packout-core' ), Schema::span( $adopter['since'], '' ) ) ) . '</p>';
				}
				if ( '' !== $adopter['about'] ) {
					$html .= '<p>' . esc_html( $adopter['about'] ) . '</p>';
				}
				if ( '' !== $adopter['note'] ) {
					$html .= '<p class="pos-place__promise">' . esc_html( $adopter['note'] ) . '</p>';
				}
				if ( $named && '' !== $adopter['url'] ) {
					/* translators: %s: organization name. */
					$html .= '<p><a class="pos-place__more" href="' . esc_url( $adopter['url'] ) . '" aria-label="' . esc_attr( sprintf( __( 'The page of %s', 'packout-core' ), $adopter['team'] ) ) . '">' . esc_html__( 'See the organization', 'packout-core' ) . '</a></p>';
				}
				$html .= '</div></li>';
			}//end foreach
			$html .= '</ul>';
			$html .= '<p class="pos-place-page__note">' . esc_html__( 'Adopting a Place is a promise to keep watching it. It transfers no ownership and none of the authority of whoever manages the land.', 'packout-core' ) . '</p>';
		}//end if
		$html .= '</section>';

		// Impact.
		$totals  = self::totals( $post->ID, true );
		$history = self::history( $post->ID );
		$html   .= '<section class="pos-place__section"><h2>' . esc_html__( 'Impact', 'packout-core' ) . '</h2>';
		if ( ! $history ) {
			$html .= '<p>' . esc_html__( 'No cleanup at this Place has been counted yet.', 'packout-core' ) . '</p>';
		} else {
			$figures = array(
				__( 'Spots with a cleanup', 'packout-core' ) => number_format_i18n( (int) $totals['outputs']['spots'] ),
				__( 'Hours, verified', 'packout-core' )   => Impact::number( (float) $totals['effort']['hours_verified'] ),
				__( 'Hours, unverified', 'packout-core' ) => Impact::number( (float) $totals['effort']['hours_unverified'] ),
				__( 'Bags, measured', 'packout-core' )    => number_format_i18n( (int) $totals['outputs']['bags_measured'] ),
				__( 'Bags, self-reported', 'packout-core' ) => number_format_i18n( (int) $totals['outputs']['bags_self_reported'] ),
				__( 'Pounds, measured', 'packout-core' )  => Impact::number( (float) $totals['outputs']['pounds_measured'] ),
				__( 'Pounds, self-reported', 'packout-core' ) => Impact::number( (float) $totals['outputs']['pounds_self_reported'] ),
			);
			$html   .= '<dl class="pos-place__figures">';
			foreach ( $figures as $label => $value ) {
				$html .= '<div class="pos-place__figure"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
			}
			$html .= '</dl>';
			if ( (int) $totals['outcomes']['still_clear'] > 0 || (int) $totals['outcomes']['returned'] > 0 ) {
				$html .= '<p>' . esc_html(
					sprintf(
						/* translators: 1: number of Spots, 2: number of Spots. */
						__( 'On later visits, %1$s Spots were still clear and the problem had come back at %2$s.', 'packout-core' ),
						number_format_i18n( (int) $totals['outcomes']['still_clear'] ),
						number_format_i18n( (int) $totals['outcomes']['returned'] )
					)
				) . '</p>';
			}
			$html .= '<p class="pos-place-page__note">' . esc_html__( 'Measured means counted or weighed. Self-reported figures are estimates from the people who did the work, and the two are never added together.', 'packout-core' ) . '</p>';
		}//end if
		$html .= '</section>';

		// Missions that are planned or under way.
		$missions = self::missions( $post->ID );
		// The ones with a day are drawn by the calendar. The list under them is the rest.
		$coming   = $missions ? Calendar_View::upcoming(
			array(
				'only'  => array_map( 'intval', wp_list_pluck( $missions, 'id' ) ),
				'count' => 12,
			)
		) : array(
			'html'     => '',
			'missions' => array(),
		);
		$missions = array_values( array_filter( $missions, static fn( array $mission ): bool => ! in_array( (int) $mission['id'], $coming['missions'], true ) ) );
		$html    .= '<section class="pos-place__section"><h2>' . esc_html__( 'Upcoming missions', 'packout-core' ) . '</h2>' . $coming['html'];
		if ( ! $missions && '' === $coming['html'] ) {
			$html .= '<p>' . esc_html__( 'No organization has shared a mission planned for this Place.', 'packout-core' ) . '</p>';
		} elseif ( $missions ) {
			$html .= '<ul class="pos-team-page__missions">';
			foreach ( $missions as $mission ) {
				$html .= '<li><a href="' . esc_url( $mission['url'] ) . '">' . esc_html( $mission['title'] ) . '</a>';
				$html .= '<span class="pos-team-page__facts">' . esc_html( implode( ' · ', array_filter( array( $mission['window'], $mission['team'], $mission['status_label'], $mission['spots_label'] ) ) ) ) . '</span></li>';
			}
			$html .= '</ul>';
		}
		$html .= '</section>';

		$linked = Features::all( $post->ID );
		$html  .= '<section class="pos-place__section"><h2>' . esc_html__( 'Map features', 'packout-core' ) . '</h2>';
		if ( ! $linked ) {
			$html .= '<p>' . esc_html__( 'No points, routes, or boundaries are linked to this Place yet.', 'packout-core' ) . '</p>';
		} else {
			$html .= '<ul class="pos-team-page__missions">';
			foreach ( $linked as $feature ) {
				$html .= '<li><a href="' . esc_url( (string) get_permalink( $feature ) ) . '">' . esc_html( $feature->post_title ) . '</a>';
				$html .= '<span class="pos-team-page__facts">' . esc_html( Features::kinds()[ Features::kind( $feature->ID ) ] ?? '' ) . '</span></li>';
			}
			$html .= '</ul>';
		}
		$html .= '</section>';

		// Spots.
		$html .= '<section class="pos-place__section"><h2>' . esc_html__( 'Spots', 'packout-core' ) . '</h2>';
		if ( $place['shape'] || $place['spots'] || $linked ) {
			$config            = Blocks::map_config();
			$config['filters'] = false;
			$config['preset']  = array(
				'category' => '',
				'status'   => '',
			);
			$config['place']   = $post->ID;
			$html             .= Blocks::map_markup( $config, 420, 'class="pos-map pos-map--place"' );
		}
		$html .= '<h3>' . esc_html__( 'Open Spots', 'packout-core' ) . '</h3>';
		if ( ! $open ) {
			$html .= '<p>' . esc_html__( 'No open Spot is on the map for this Place.', 'packout-core' ) . '</p>';
		} else {
			$html .= '<ul class="pos-team-page__missions">';
			foreach ( $open as $spot ) {
				$html .= '<li><a href="' . esc_url( $spot['url'] ) . '">' . esc_html( $spot['title'] ) . '</a>';
				$html .= '<span class="pos-team-page__facts">' . esc_html( implode( ' · ', array_filter( array( $spot['category']['name'], $labels[ $spot['status'] ] ?? $spot['status'] ) ) ) ) . '</span></li>';
			}
			$html .= '</ul>';
		}
		// On the hub: what partners' sites reported inside the outline. These are not attached to the Place and are in none of its figures.
		$theirs = Remote_Spots::inside( $place['shape'] );
		if ( $theirs ) {
			$html .= '<h3 class="pos-place__partner-spots">' . esc_html__( 'From partner sites', 'packout-core' ) . '</h3>';
			$html .= '<p>' . esc_html__( 'Reported on a partner\'s own site, inside this Place. Each is kept there.', 'packout-core' ) . '</p><ul class="pos-team-page__missions">';
			foreach ( array_slice( $theirs, 0, 50 ) as $spot ) {
				$html .= '<li><a href="' . esc_url( '' !== $spot['url'] ? $spot['url'] : $spot['through']['site'] ) . '">' . esc_html( $spot['title'] ) . '</a>';
				/* translators: %s: the name of a partner. */
				$html .= '<span class="pos-team-page__facts">' . esc_html( implode( ' · ', array_filter( array( $spot['category']['name'], $labels[ $spot['status'] ] ?? $spot['status'], sprintf( __( 'through %s', 'packout-core' ), $spot['through']['name'] ) ) ) ) ) . '</span></li>';
			}
			$html .= '</ul>';
		}
		$report = (int) Settings::get( 'report_page' );
		if ( $report > 0 && 'publish' === get_post_status( $report ) ) {
			$html .= '<p class="pos-place__report">' . esc_html__( 'Seen something here that needs attention?', 'packout-core' ) . ' <a href="' . esc_url( (string) get_permalink( $report ) ) . '">' . esc_html__( 'Report a Spot', 'packout-core' ) . '</a></p>';
		}
		$html .= '</section>';

		// The history of the work.
		$html .= '<section class="pos-place__section"><h2>' . esc_html__( 'Work recorded here', 'packout-core' ) . '</h2>';
		if ( ! $history ) {
			$html .= '<p>' . esc_html__( 'The cleanups a coordinator counts are listed here, newest first.', 'packout-core' ) . '</p>';
		} else {
			$html .= '<p>' . esc_html( Campaigns::totals_line( $totals ) ) . '</p><ul class="pos-team-page__missions">';
			foreach ( $history as $row ) {
				$facts = array_filter(
					array(
						Schema::span( $row['date'], '' ),
						$row['team'],
						$row['summary'],
						$row['verified'] ? __( 'Verified', 'packout-core' ) : __( 'Not yet verified', 'packout-core' ),
					)
				);
				$html .= '<li><a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['spot'] ) . '</a>';
				$html .= '<span class="pos-team-page__facts">' . esc_html( implode( ' · ', $facts ) ) . '</span></li>';
			}
			$html .= '</ul>';
		}
		return $html . '</section></div></div>';
	}

	/**
	 * The refusal a person who is not a coordinator gets.
	 */
	private static function refusal(): \WP_Error {
		return new \WP_Error( 'packout_forbidden', __( 'A coordinator keeps the Places.', 'packout-core' ), array( 'status' => 403 ) );
	}

	/**
	 * The pins of every Spot, for the map a coordinator draws an outline on.
	 *
	 * @return array<int, array{0: float, 1: float}> Longitude and latitude pairs.
	 */
	public static function pins(): array {
		$pins  = array();
		$spots = get_posts(
			array(
				'post_type'   => Schema::SPOT,
				'post_status' => Spots::POST_STATUSES,
				'numberposts' => 2000,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Spots that have a pin.
				'meta_query'  => array(
					array(
						'key'     => '_pos_lat',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		foreach ( $spots as $spot ) {
			$pin = Spots::pin( (int) $spot );
			if ( $pin ) {
				$pins[] = array( $pin[1], $pin[0] );
			}
		}
		return $pins;
	}
}
