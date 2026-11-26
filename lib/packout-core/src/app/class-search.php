<?php
/**
 * Searching a map.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One search box on every map answers with three kinds of result, kept apart:
 *
 * - Locations: places by name, from a geocoder. OpenStreetMap's Nominatim by
 *   default. The browser asks this site and this site asks the geocoder, so
 *   the geocoder sees the site and not the visitor.
 * - Spots: the Spots the person may see, by title, category, landmark, and description.
 * - Places: listed Places, by name.
 *
 * Nominatim's usage policy asks for an identifying User-Agent, at most one
 * request a second, cached answers, no search-as-you-type, and credit to
 * OpenStreetMap. Each is kept here: the request names the site, a lock spaces
 * the requests, every answer is kept for a day, the form searches when it is
 * sent, and the credit travels with the results.
 */
class Search {

	/**
	 * How many results of each kind are returned.
	 */
	private const LIMIT = 6;

	/**
	 * How long an answer from the geocoder is kept.
	 */
	private const KEEP = DAY_IN_SECONDS;

	/**
	 * The script and the style of the search box. Call when the assets are registered.
	 */
	public static function register_assets(): void {
		wp_register_style( 'packout-search', plugins_url( 'assets/css/search.css', PACKOUT_CORE_FILE ), array(), PACKOUT_CORE_VERSION );
		wp_register_script( 'packout-search', plugins_url( 'assets/js/search.js', PACKOUT_CORE_FILE ), array(), PACKOUT_CORE_VERSION, true );
		wp_localize_script( 'packout-search', 'packoutSearchSettings', self::settings() );
	}

	/**
	 * What the search box needs: where to ask, and its words.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		return array(
			'url'  => rest_url( Rest::NAMESPACE . '/search' ),
			'text' => array(
				'label'       => __( 'Search the map', 'packout-core' ),
				'placeholder' => __( 'A town, a road, a Spot, a Place, or a feature', 'packout-core' ),
				'search'      => __( 'Search', 'packout-core' ),
				'clear'       => __( 'Clear the search', 'packout-core' ),
				'searching'   => __( 'Searching…', 'packout-core' ),
				'short'       => __( 'Type at least two characters.', 'packout-core' ),
				'none'        => __( 'Nothing matches. Try a town, a road, or fewer words.', 'packout-core' ),
				'failed'      => __( 'The search could not be reached. Check your connection and try again.', 'packout-core' ),
				'busy'        => __( 'Too many searches in a short time. Wait a minute and try again.', 'packout-core' ),
				'offline'     => __( 'Search needs a connection.', 'packout-core' ),
				'no_places'   => __( 'Locations are not available right now. Spots, Places, and Features are listed.', 'packout-core' ),
				'locations'   => __( 'Locations', 'packout-core' ),
				'spots'       => __( 'Spots', 'packout-core' ),
				'places'      => __( 'Places', 'packout-core' ),
				'features'    => __( 'Features', 'packout-core' ),
				'results'     => __( 'Search results', 'packout-core' ),
				'page'        => __( 'Open its page', 'packout-core' ),
				/* translators: %d: number of results. */
				'found'       => __( '%d results', 'packout-core' ),
				'found_one'   => __( '1 result', 'packout-core' ),
			),
		);
	}

	/**
	 * Search for a location, a Spot, or a Place.
	 *
	 * @param string                         $query What the person typed.
	 * @param array{0: float, 1: float}|null $near  Latitude and longitude of the middle of their map.
	 * @param int                            $user  The person, or 0 for a visitor.
	 * @return array{query: string, locations: array<int, array<string, mixed>>, spots: array<int, array<string, mixed>>, places: array<int, array<string, mixed>>, features: array<int, array<string, mixed>>, located: bool, attribution: string}
	 */
	public static function find( string $query, ?array $near, int $user ): array {
		$query     = self::clean( $query );
		$locations = self::locations( $query, $near );
		return array(
			'query'       => $query,
			'locations'   => is_array( $locations ) ? $locations : array(),
			'spots'       => self::spots( $query, $user ),
			'places'      => self::places( $query ),
			'features'    => self::features( $query ),
			// False when the geocoder could not be asked. The Spots, Places, and Features are still listed.
			'located'     => is_array( $locations ),
			'attribution' => self::attribution(),
		);
	}

	/**
	 * A query as it is searched: one line, at most 120 characters.
	 *
	 * @param string $query What the person typed.
	 */
	public static function clean( string $query ): string {
		$query = trim( (string) preg_replace( '/\s+/', ' ', sanitize_text_field( $query ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $query, 0, 120 ) : substr( $query, 0, 120 );
	}

	/**
	 * The credit the geocoder asks for.
	 */
	public static function attribution(): string {
		/**
		 * The credit shown under location results.
		 *
		 * @param string $credit Plain text.
		 */
		return (string) apply_filters( 'packout_spot_geocoder_credit', __( 'Locations © OpenStreetMap contributors', 'packout-core' ) );
	}

	/**
	 * Places by name, from the geocoder. Null when it could not be asked.
	 *
	 * @param string                         $query Cleaned query.
	 * @param array{0: float, 1: float}|null $near  Latitude and longitude to prefer results around.
	 * @return array<int, array{name: string, detail: string, lat: float, lng: float, bbox: array<int, float>|null}>|null
	 */
	public static function locations( string $query, ?array $near ) {
		if ( strlen( $query ) < 2 ) {
			return array();
		}
		/**
		 * Answer a location search without the geocoder.
		 *
		 * Return a list of results, each with name, detail, lat, lng, and
		 * optionally bbox (west, south, east, north), to replace the request.
		 *
		 * @param array|null $results Null to ask the geocoder.
		 * @param string     $query   What is being searched for.
		 * @param array|null $near    Latitude and longitude to prefer results around.
		 */
		$given = apply_filters( 'packout_spot_geocode', null, $query, $near );
		if ( is_array( $given ) ) {
			return self::tidy( $given );
		}

		// Rounded to about ten kilometers, so nearby maps share an answer.
		$around = $near ? sprintf( '%.1F,%.1F', $near[0], $near[1] ) : '';
		$key    = 'pos_geo_' . md5( strtolower( $query ) . '|' . $around );
		$kept   = get_transient( $key );
		if ( is_array( $kept ) ) {
			return $kept;
		}

		// One request a second, across every visitor. A search that finds the geocoder busy waits its turn once.
		if ( ! self::turn() ) {
			return null;
		}

		$args = array(
			'q'               => $query,
			'format'          => 'jsonv2',
			'limit'           => self::LIMIT,
			'addressdetails'  => 0,
			'accept-language' => str_replace( '_', '-', (string) get_locale() ),
		);
		if ( $near ) {
			// Prefer what is near the map, without leaving out what is far.
			$args['viewbox'] = implode( ',', array( $near[1] - 1.5, $near[0] + 1.5, $near[1] + 1.5, $near[0] - 1.5 ) );
			$args['bounded'] = 0;
		}
		/**
		 * The address of the geocoder: a service that answers as Nominatim's /search does.
		 *
		 * @param string $url Address without the query.
		 */
		$base     = (string) apply_filters( 'packout_spot_geocoder_url', 'https://nominatim.openstreetmap.org/search' );
		$response = wp_safe_remote_get(
			add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $base ),
			array(
				'timeout'    => 6,
				'user-agent' => 'PackOutSpot/' . \PackOut\Core\Kernel::instance()->site()->version() . ' (' . home_url( '/' ) . ')',
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$rows = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $rows ) ) {
			return null;
		}

		$found = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label   = (string) ( $row['display_name'] ?? '' );
			$name    = (string) ( $row['name'] ?? '' );
			$name    = '' !== $name ? $name : trim( (string) strtok( $label, ',' ) );
			$detail  = 0 === strpos( $label, $name ) ? ltrim( substr( $label, strlen( $name ) ), ', ' ) : $label;
			$box     = isset( $row['boundingbox'] ) && is_array( $row['boundingbox'] ) && 4 === count( $row['boundingbox'] ) ? array_map( 'floatval', array_values( $row['boundingbox'] ) ) : null;
			$found[] = array(
				'name'   => $name,
				'detail' => $detail,
				'lat'    => $row['lat'] ?? null,
				'lng'    => $row['lon'] ?? null,
				// Nominatim gives south, north, west, east. The map takes west, south, east, north.
				'bbox'   => $box ? array( $box[2], $box[0], $box[3], $box[1] ) : null,
			);
		}
		$found = self::tidy( $found );
		set_transient( $key, $found, self::KEEP );
		return $found;
	}

	/**
	 * The road or trail at a point, from the geocoder, at a zoom that still has its name.
	 *
	 * The map tiles omit a road's name until the label is drawn, so a click far out
	 * would otherwise show the line as unnamed. This asks for the highway itself.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return array{name: string, ref: string, class: string, surface: string}|null Null when the geocoder could not be asked.
	 */
	public static function line( float $lat, float $lng ): ?array {
		$point = Geo::point( $lat, $lng );
		if ( ! $point ) {
			return null;
		}
		// About ten meters. Clicks along the same stretch share one answer.
		$key  = 'pos_line_' . md5( sprintf( '%.4F,%.4F', $point[0], $point[1] ) );
		$kept = get_transient( $key );
		if ( is_array( $kept ) ) {
			return $kept;
		}

		/**
		 * The road at this point, when a site already knows it.
		 *
		 * @param array{name: string, ref: string, class: string, surface: string}|null $line Empty until a site sets it.
		 * @param float                                                                  $lat  Latitude.
		 * @param float                                                                  $lng  Longitude.
		 */
		$given = apply_filters( 'packout_spot_reverse', null, $point[0], $point[1] );
		if ( is_array( $given ) ) {
			$line = self::line_from( $given );
			set_transient( $key, $line, self::KEEP );
			return $line;
		}

		if ( ! self::turn() ) {
			return null;
		}

		/**
		 * The address of the reverse geocoder: a service that answers as Nominatim's /reverse does.
		 *
		 * @param string $url Address without the query.
		 */
		$base     = (string) apply_filters( 'packout_spot_reverse_url', 'https://nominatim.openstreetmap.org/reverse' );
		$response = wp_safe_remote_get(
			add_query_arg(
				array_map(
					'rawurlencode',
					array_map(
						'strval',
						array(
							'format'          => 'jsonv2',
							'lat'             => $point[0],
							'lon'             => $point[1],
							'zoom'            => 16,
							'addressdetails'  => 1,
							'extratags'       => 1,
							'accept-language' => str_replace( '_', '-', (string) get_locale() ),
						)
					)
				),
				$base
			),
			array(
				'timeout'    => 6,
				'user-agent' => 'PackOutSpot/' . \PackOut\Core\Kernel::instance()->site()->version() . ' (' . home_url( '/' ) . ')',
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$row = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$line = self::line_from( $row );
		set_transient( $key, $line, self::KEEP );
		return $line;
	}

	/**
	 * A highway from a Nominatim reverse answer. An empty name when the point is not a road.
	 *
	 * @param array<string, mixed> $row The geocoder's answer.
	 * @return array{name: string, ref: string, class: string, surface: string}
	 */
	private static function line_from( array $row ): array {
		$empty = array(
			'name'    => '',
			'ref'     => '',
			'class'   => '',
			'surface' => '',
		);
		// A search result is a list. A reverse result is one place.
		if ( array_is_list( $row ) ) {
			return $empty;
		}
		$address = isset( $row['address'] ) && is_array( $row['address'] ) ? $row['address'] : array();
		$extra   = isset( $row['extratags'] ) && is_array( $row['extratags'] ) ? $row['extratags'] : array();
		$name    = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
		if ( '' === $name ) {
			foreach ( array( 'road', 'path', 'footway', 'pedestrian', 'cycleway', 'track', 'bridleway' ) as $part ) {
				if ( ! empty( $address[ $part ] ) ) {
					$name = sanitize_text_field( (string) $address[ $part ] );
					break;
				}
			}
		}
		$highway = 'highway' === (string) ( $row['category'] ?? '' );
		if ( ! $highway && '' === $name ) {
			return $empty;
		}
		return array(
			'name'    => $name,
			'ref'     => sanitize_text_field( (string) ( $extra['ref'] ?? '' ) ),
			'class'   => $highway ? sanitize_key( (string) ( $row['type'] ?? '' ) ) : '',
			'surface' => sanitize_text_field( (string) ( $extra['surface'] ?? '' ) ),
		);
	}

	/**
	 * Take the next turn with the geocoder, a second after the last one.
	 */
	private static function turn(): bool {
		for ( $try = 0; $try < 2; $try++ ) {
			$last = (float) get_transient( 'pos_geo_turn' );
			$wait = $last + 1.05 - microtime( true );
			if ( $wait <= 0 ) {
				set_transient( 'pos_geo_turn', microtime( true ), MINUTE_IN_SECONDS );
				return true;
			}
			if ( $wait > 1.2 || $try > 0 ) {
				return false;
			}
			usleep( (int) ( $wait * 1000000 ) );
		}
		return false;
	}

	/**
	 * Results in the form the browser is given, with anything unusable left out.
	 *
	 * @param array<int|string, mixed> $rows Results.
	 * @return array<int, array{name: string, detail: string, lat: float, lng: float, bbox: array<int, float>|null}>
	 */
	private static function tidy( array $rows ): array {
		$found = array();
		foreach ( $rows as $row ) {
			$point = is_array( $row ) ? Geo::point( $row['lat'] ?? null, $row['lng'] ?? null ) : null;
			$name  = is_array( $row ) ? sanitize_text_field( (string) ( $row['name'] ?? '' ) ) : '';
			if ( ! $point || '' === $name ) {
				continue;
			}
			$box     = isset( $row['bbox'] ) && is_array( $row['bbox'] ) && 4 === count( $row['bbox'] ) ? array_map( 'floatval', array_values( $row['bbox'] ) ) : null;
			$found[] = array(
				'name'   => $name,
				'detail' => sanitize_text_field( (string) ( $row['detail'] ?? '' ) ),
				'lat'    => $point[0],
				'lng'    => $point[1],
				'bbox'   => $box && $box[0] < $box[2] && $box[1] < $box[3] ? $box : null,
			);
			if ( count( $found ) >= self::LIMIT ) {
				break;
			}
		}
		return $found;
	}

	/**
	 * Whether every word of a query is somewhere in a text.
	 *
	 * @param string $query Cleaned query.
	 * @param string $text  Text to look in.
	 */
	private static function matches( string $query, string $text ): bool {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
		foreach ( array_filter( explode( ' ', function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query ) ) ) as $word ) {
			if ( false === strpos( $text, $word ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Spots the person may see that match, at the location they are given.
	 *
	 * @param string $query Cleaned query.
	 * @param int    $user  The person, or 0 for a visitor.
	 * @return array<int, array{id: int, name: string, detail: string, lat: float, lng: float, url: string}>
	 */
	public static function spots( string $query, int $user ): array {
		if ( strlen( $query ) < 2 ) {
			return array();
		}
		$statuses = Schema::statuses();
		$titled   = array();
		$others   = array();
		foreach ( Spots::visible( $user ) as $spot ) {
			if ( null === $spot['location']['lat'] || null === $spot['location']['lng'] ) {
				continue;
			}
			$place = is_array( $spot['place'] ) ? (string) $spot['place']['name'] : '';
			$text  = implode( ' ', array( $spot['title'], $spot['category']['name'], (string) $spot['location']['landmark'], $place, (string) $spot['description'] ) );
			if ( ! self::matches( $query, $text ) ) {
				continue;
			}
			$row = array(
				'id'     => (int) $spot['id'],
				'name'   => (string) $spot['title'],
				'detail' => implode( ' · ', array_filter( array( $spot['category']['name'], $statuses[ $spot['status'] ] ?? '', $place ) ) ),
				'lat'    => (float) $spot['location']['lat'],
				'lng'    => (float) $spot['location']['lng'],
				'url'    => (string) $spot['url'],
			);
			// A match in the title comes first.
			if ( self::matches( $query, (string) $spot['title'] ) ) {
				$titled[] = $row;
			} else {
				$others[] = $row;
			}
		}//end foreach
		return array_slice( array_merge( $titled, $others ), 0, self::LIMIT );
	}

	/**
	 * Listed Places whose name matches, with the box around each outline.
	 *
	 * @param string $query Cleaned query.
	 * @return array<int, array{id: int, name: string, detail: string, lat: float, lng: float, bbox: array<int, float>|null, url: string}>
	 */
	public static function places( string $query ): array {
		if ( strlen( $query ) < 2 ) {
			return array();
		}
		$kinds = Places::kinds();
		$found = array();
		foreach ( Places::all( true ) as $place ) {
			$kind = $kinds[ Places::kind( $place->ID ) ] ?? '';
			if ( ! self::matches( $query, $place->post_title . ' ' . $kind ) ) {
				continue;
			}
			$ring = Places::shape( $place->ID );
			if ( ! $ring ) {
				continue;
			}
			$lngs    = array_column( $ring, 0 );
			$lats    = array_column( $ring, 1 );
			$found[] = array(
				'id'     => $place->ID,
				'name'   => $place->post_title,
				'detail' => $kind,
				'lat'    => ( min( $lats ) + max( $lats ) ) / 2,
				'lng'    => ( min( $lngs ) + max( $lngs ) ) / 2,
				'bbox'   => array( min( $lngs ), min( $lats ), max( $lngs ), max( $lats ) ),
				'url'    => (string) get_permalink( $place ),
			);
			if ( count( $found ) >= self::LIMIT ) {
				break;
			}
		}//end foreach
		return $found;
	}

	/**
	 * Public map features whose name matches.
	 *
	 * @param string $query Cleaned query.
	 * @return array<int, array{id: int, name: string, detail: string, lat: float, lng: float, bbox: array<int, float>|null}>
	 */
	public static function features( string $query ): array {
		if ( strlen( $query ) < 2 ) {
			return array();
		}
		$kinds = Features::kinds();
		$found = array();
		foreach ( Features::all() as $post ) {
			$view = Features::payload( $post );
			if ( ! $view || ! self::matches( $query, $view['name'] . ' ' . $view['kind_label'] . ' ' . $view['description'] ) ) {
				continue;
			}
			$center = self::feature_center( $view['geometry'] );
			if ( ! $center ) {
				continue;
			}
			$found[] = array(
				'id'     => $post->ID,
				'name'   => $view['name'],
				'detail' => $kinds[ $view['kind'] ] ?? $view['kind'],
				'lat'    => $center['lat'],
				'lng'    => $center['lng'],
				'bbox'   => $center['bbox'],
			);
			if ( count( $found ) >= self::LIMIT ) {
				break;
			}
		}//end foreach
		return $found;
	}

	/**
	 * The middle of a feature, and a box around it when it is a line or an area.
	 *
	 * @param array{type: string, coordinates: mixed} $geometry GeoJSON geometry.
	 * @return array{lat: float, lng: float, bbox: array<int, float>|null}|null
	 */
	private static function feature_center( array $geometry ): ?array {
		$pairs  = array();
		$type   = $geometry['type'] ?? '';
		$coords = $geometry['coordinates'] ?? null;
		if ( 'Point' === $type && is_array( $coords ) ) {
			$pairs[] = $coords;
		} elseif ( 'LineString' === $type && is_array( $coords ) ) {
			$pairs = $coords;
		} elseif ( 'Polygon' === $type && is_array( $coords[0] ?? null ) ) {
			$pairs = $coords[0];
		}
		$lngs = array();
		$lats = array();
		foreach ( $pairs as $pair ) {
			if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) && is_numeric( $pair[0] ) && is_numeric( $pair[1] ) ) {
				$lngs[] = (float) $pair[0];
				$lats[] = (float) $pair[1];
			}
		}
		if ( ! $lngs ) {
			return null;
		}
		return array(
			'lat'  => ( min( $lats ) + max( $lats ) ) / 2,
			'lng'  => ( min( $lngs ) + max( $lngs ) ) / 2,
			'bbox' => count( $lngs ) > 1 ? array( min( $lngs ), min( $lats ), max( $lngs ), max( $lats ) ) : null,
		);
	}

	/**
	 * GET /search.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest( \WP_REST_Request $request ) {
		$query = self::clean( (string) $request->get_param( 'q' ) );
		if ( strlen( $query ) < 2 ) {
			return new \WP_Error( 'packout_search_short', __( 'Type at least two characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$user = get_current_user_id();
		// Thirty searches in five minutes for one person or one address.
		$who = $user > 0 ? 'u' . $user : 'a' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		if ( ! Accounts::allow( 'search_' . $who, 30, 5 * MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'packout_search_busy', __( 'Too many searches in a short time. Wait a minute and try again.', 'packout-core' ), array( 'status' => 429 ) );
		}
		$near     = explode( ',', (string) $request->get_param( 'near' ) );
		$near     = 2 === count( $near ) ? Geo::point( $near[0], $near[1] ) : null;
		$response = new \WP_REST_Response( self::find( $query, $near, $user ) );
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		return $response;
	}

	/**
	 * GET /line. The road or trail at a latitude and longitude.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rest_line( \WP_REST_Request $request ): \WP_REST_Response {
		$lat      = $request->get_param( 'lat' );
		$lng      = $request->get_param( 'lng' );
		$line     = self::line( is_numeric( $lat ) ? (float) $lat : 999.0, is_numeric( $lng ) ? (float) $lng : 999.0 );
		$body     = is_array( $line ) ? $line : array(
			'name'    => '',
			'ref'     => '',
			'class'   => '',
			'surface' => '',
		);
		$response = new \WP_REST_Response( $body );
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		return $response;
	}
}
