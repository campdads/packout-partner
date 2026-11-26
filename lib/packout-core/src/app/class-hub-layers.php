<?php
/**
 * What packout.spot knows about a partner site's area, for the site's own map.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

defined( 'ABSPATH' ) || exit;

/**
 * The hub's Spots, Places, and map features inside a region the site chose.
 *
 * It is off until an administrator turns it on and names a region. The
 * site's server asks the hub every fifteen minutes and keeps the answer,
 * so a visitor's browser never calls the hub and the hub sees one caller
 * for the site. The requests are not signed: they ask for what any
 * visitor to packout.spot is shown.
 *
 * Nothing here is stored as a record of the site, and nothing on the site
 * can change it. A Spot the site itself sent is left out, and so is one a
 * crew of the site has taken on, which the site shows as its own copy.
 */
final class Hub_Layers {

	/**
	 * The setting: whether the layers are on, and the region.
	 */
	public const OPTION = 'packout_hub_layers';

	/**
	 * What the hub last answered.
	 */
	public const DATA = 'packout_hub_layers_data';

	/**
	 * The longest side of the region, in kilometres.
	 */
	public const SIDE = 500;

	/**
	 * Seconds between two askings.
	 */
	public const EVERY = 900;

	/**
	 * The most of each kind that is kept.
	 */
	private const MOST = 1000;

	/**
	 * Hook the layers. A partner site only.
	 */
	public static function register(): void {
		if ( App::is_hub() ) {
			return;
		}
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( Outbox::RUN, array( self::class, 'refresh_if_due' ), 20 );
		add_action( 'packout_connection_screen', array( self::class, 'section' ) );
		add_action( 'admin_post_packout_hub_layers', array( self::class, 'action' ) );
	}

	/**
	 * The route the site's own map reads.
	 */
	public static function routes(): void {
		register_rest_route(
			Rest::NAMESPACE,
			'/hub-layers',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'answer' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The setting.
	 *
	 * @return array{on: bool, box: array{west: float, south: float, east: float, north: float}|null}
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$box    = Geo::bbox( (string) ( $stored['box'] ?? '' ) );
		return array(
			'on'  => ! empty( $stored['on'] ) && null !== $box,
			'box' => $box,
		);
	}

	/**
	 * Whether the site shows the hub's layers.
	 */
	public static function on(): bool {
		return ! App::is_hub() && Connection::connected() && self::settings()['on'];
	}

	/**
	 * The sides of a region, in kilometres.
	 *
	 * @param array{west: float, south: float, east: float, north: float} $box The region.
	 * @return array{0: float, 1: float} Across and up.
	 */
	public static function sides( array $box ): array {
		$middle = deg2rad( ( $box['south'] + $box['north'] ) / 2 );
		return array(
			( $box['east'] - $box['west'] ) * 111.32 * cos( $middle ),
			( $box['north'] - $box['south'] ) * 110.57,
		);
	}

	/**
	 * Keep the setting.
	 *
	 * @param bool   $on  Whether the layers are shown.
	 * @param string $box The region, as "west,south,east,north".
	 * @return true|\WP_Error
	 */
	public static function save( bool $on, string $box ) {
		$parsed = Geo::bbox( $box );
		if ( $on && ! $parsed ) {
			return new \WP_Error( 'packout_region', __( 'Name the region as four numbers: west, south, east, north. West is less than east, and south is less than north.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( $parsed ) {
			if ( $parsed['west'] < -180 || $parsed['east'] > 180 || $parsed['south'] < -90 || $parsed['north'] > 90 ) {
				return new \WP_Error( 'packout_region', __( 'The region is not on the map: longitudes run from -180 to 180 and latitudes from -90 to 90.', 'packout-core' ), array( 'status' => 400 ) );
			}
			$sides = self::sides( $parsed );
			if ( max( $sides ) > self::SIDE ) {
				/* translators: %d: a number of kilometres. */
				return new \WP_Error( 'packout_region', sprintf( __( 'The region can be at most %d kilometres on a side.', 'packout-core' ), self::SIDE ), array( 'status' => 400 ) );
			}
		}
		update_option(
			self::OPTION,
			array(
				'on'  => $on && null !== $parsed,
				'box' => $parsed ? implode( ',', array_map( static fn( float $value ): string => rtrim( rtrim( sprintf( '%.4F', $value ), '0' ), '.' ), $parsed ) ) : '',
			),
			false
		);
		// A new region is a new question. What was kept answered the old one.
		delete_option( self::DATA );
		return true;
	}

	/**
	 * What the hub last answered, as the map draws it.
	 *
	 * @return array{fetched: int, spots: array<int, array<string, mixed>>, places: array<int, array<string, mixed>>, features: array<int, array<string, mixed>>}
	 */
	public static function data(): array {
		$kept = get_option( self::DATA, array() );
		$kept = is_array( $kept ) ? $kept : array();
		return array(
			'fetched'  => (int) ( $kept['fetched'] ?? 0 ),
			'spots'    => (array) ( $kept['spots'] ?? array() ),
			'places'   => (array) ( $kept['places'] ?? array() ),
			'features' => (array) ( $kept['features'] ?? array() ),
		);
	}

	/**
	 * Take one of the hub's Spots out of what was kept: a crew here took it on, and the site's own copy is on the map now.
	 *
	 * @param int $hub_spot The hub's number for the Spot.
	 */
	public static function drop_spot( int $hub_spot ): void {
		$kept = get_option( self::DATA, array() );
		if ( ! is_array( $kept ) || empty( $kept['spots'] ) ) {
			return;
		}
		$spots = array_values( array_filter( (array) $kept['spots'], static fn( $spot ): bool => is_array( $spot ) && (int) ( $spot['id'] ?? 0 ) !== $hub_spot ) );
		if ( count( $spots ) !== count( $kept['spots'] ) ) {
			update_option( self::DATA, array( 'spots' => $spots ) + $kept, false );
		}
	}

	/**
	 * Ask the hub again when the last answer is fifteen minutes old. The queue's timer calls this, so no visitor waits for it.
	 */
	public static function refresh_if_due(): void {
		if ( self::on() && time() - self::data()['fetched'] >= self::EVERY ) {
			self::refresh();
		}
	}

	/**
	 * Ask the hub what it shows in the region, and keep the answer.
	 *
	 * @return true|\WP_Error
	 */
	public static function refresh() {
		$settings = self::settings();
		if ( App::is_hub() || ! Connection::connected() || ! $settings['box'] ) {
			return new \WP_Error( 'packout_layers_off', __( 'Name a region first.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$query = array( 'bbox' => implode( ',', $settings['box'] ) );
		$kept  = array( 'fetched' => time() );
		foreach ( array( 'spots', 'places', 'features' ) as $kind ) {
			$answer = Connection::ask( '/' . $kind, $query );
			if ( is_wp_error( $answer ) ) {
				// The last answer stays. The hub is asked again on the next run.
				return $answer;
			}
			$kept[ $kind ] = array_slice( (array) ( $answer[ $kind ] ?? array() ), 0, self::MOST );
		}
		$mine   = Adoptions::hub_spots();
		$home   = untrailingslashit( home_url() );
		$spots  = array();
		$number = static fn( $value ): ?float => is_numeric( $value ) ? (float) $value : null;
		$words  = static fn( $value, int $limit ): string => mb_substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, $limit );
		$link   = static function ( $value ): string {
			// Only an address on the hub is kept. A card links nowhere else.
			$url = esc_url_raw( is_scalar( $value ) ? (string) $value : '', array( 'https', 'http' ) );
			return '' !== $url && 0 === strpos( $url, Connection::hub() ) ? $url : '';
		};
		foreach ( $kept['spots'] as $spot ) {
			$spot    = (array) $spot;
			$through = (array) ( $spot['through'] ?? array() );
			$lat     = $number( $spot['location']['lat'] ?? null );
			$lng     = $number( $spot['location']['lng'] ?? null );
			// Left out: a Spot with no point, a report nobody has reviewed, a Spot this site sent, and one a crew here has taken on.
			if ( null === $lat || null === $lng || ! empty( $through['awaiting'] ) || 0 === strpos( (string) ( $through['site'] ?? '' ), $home ) || isset( $mine[ (int) ( $spot['id'] ?? 0 ) ] ) ) {
				continue;
			}
			$spots[] = array(
				'id'       => (int) ( $spot['id'] ?? 0 ),
				'title'    => $words( $spot['title'] ?? '', 200 ),
				'url'      => $link( $spot['url'] ?? '' ),
				'category' => $words( $spot['category']['name'] ?? '', 80 ),
				'status'   => sanitize_key( (string) ( $spot['status'] ?? 'spotted' ) ),
				'hazard'   => sanitize_key( (string) ( $spot['hazard'] ?? 'none' ) ),
				'lat'      => $lat,
				'lng'      => $lng,
				'coarse'   => 'coarse' === ( $spot['location']['precision'] ?? '' ),
				'through'  => $words( $through['name'] ?? '', 120 ),
			);
		}//end foreach
		$places = array();
		foreach ( $kept['places'] as $place ) {
			$place = (array) $place;
			$shape = array();
			foreach ( array_slice( (array) ( $place['shape'] ?? array() ), 0, 2000 ) as $point ) {
				if ( is_array( $point ) && isset( $point[0], $point[1] ) && is_numeric( $point[0] ) && is_numeric( $point[1] ) ) {
					$shape[] = array( (float) $point[0], (float) $point[1] );
				}
			}
			if ( count( $shape ) < 3 ) {
				continue;
			}
			$places[] = array(
				'id'       => (int) ( $place['id'] ?? 0 ),
				'name'     => $words( $place['name'] ?? '', 200 ),
				'url'      => $link( $place['url'] ?? '' ),
				'kind'     => $words( $place['kind_label'] ?? '', 80 ),
				'shape'    => $shape,
				'adopters' => array_values( array_filter( array_map( static fn( $adopter ): string => $words( is_array( $adopter ) ? ( $adopter['name'] ?? '' ) : '', 120 ), array_slice( (array) ( $place['adopters'] ?? array() ), 0, 12 ) ) ) ),
			);
		}
		$features = array();
		foreach ( $kept['features'] as $feature ) {
			$feature  = (array) $feature;
			$geometry = (array) ( $feature['geometry'] ?? array() );
			$type     = (string) ( $geometry['type'] ?? '' );
			if ( ! in_array( $type, array( 'Point', 'LineString', 'Polygon', 'MultiLineString', 'MultiPolygon' ), true ) || ! Geo::bounds( $geometry['coordinates'] ?? null ) ) {
				continue;
			}
			$features[] = array(
				'id'       => (int) ( $feature['id'] ?? 0 ),
				'name'     => $words( $feature['name'] ?? '', 200 ),
				'url'      => $link( $feature['url'] ?? '' ),
				'kind'     => $words( $feature['kind_label'] ?? '', 80 ),
				'geometry' => array(
					'type'        => $type,
					'coordinates' => self::numbers( $geometry['coordinates'] ),
				),
			);
		}
		update_option(
			self::DATA,
			array(
				'fetched'  => $kept['fetched'],
				'spots'    => $spots,
				'places'   => $places,
				'features' => $features,
			),
			false
		);
		return true;
	}

	/**
	 * Coordinates with nothing in them but numbers.
	 *
	 * @param mixed $value Nested pairs.
	 * @param int   $depth How deep the nesting is so far.
	 * @return array<int, mixed>
	 */
	private static function numbers( $value, int $depth = 0 ): array {
		$out = array();
		if ( ! is_array( $value ) || $depth > 6 ) {
			return $out;
		}
		foreach ( array_slice( $value, 0, 5000 ) as $part ) {
			if ( is_array( $part ) ) {
				$out[] = self::numbers( $part, $depth + 1 );
			} elseif ( is_numeric( $part ) ) {
				$out[] = (float) $part;
			}
		}
		return $out;
	}

	/**
	 * GET /hub-layers: what the site's map draws.
	 */
	public static function answer(): \WP_REST_Response {
		$data     = self::on() ? self::data() : array(
			'fetched'  => 0,
			'spots'    => array(),
			'places'   => array(),
			'features' => array(),
		);
		$response = new \WP_REST_Response( $data );
		// The same for every visitor, and fifteen minutes old at most by design.
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * What the map block is told: where to read the layers, and their names.
	 *
	 * @return array<string, mixed>|null Null when the layers are off.
	 */
	public static function config(): ?array {
		if ( ! self::on() ) {
			return null;
		}
		return array(
			'rest'     => esc_url_raw( rest_url( Rest::NAMESPACE . '/hub-layers' ) ),
			'group'    => __( 'On packout.spot', 'packout-core' ),
			'spots'    => __( 'Spots', 'packout-core' ),
			'places'   => __( 'Places', 'packout-core' ),
			'features' => __( 'Map features', 'packout-core' ),
			'badge'    => __( 'On packout.spot', 'packout-core' ),
			'open'     => __( 'See it on packout.spot', 'packout-core' ),
			/* translators: %s: a number. A lead types it on the Missions screen to take the Spot on. */
			'number'   => __( 'Spot %s on packout.spot. A lead can take it on from the Missions screen.', 'packout-core' ),
			/* translators: %s: the name of a partner. */
			'through'  => __( 'Reported through %s', 'packout-core' ),
			/* translators: %s: names of organizations. */
			'adopted'  => __( 'Adopted by %s', 'packout-core' ),
			'coarse'   => __( 'Approximate location.', 'packout-core' ),
		);
	}

	/**
	 * The section of the Connection screen.
	 */
	public static function section(): void {
		$settings = self::settings();
		$stored   = get_option( self::OPTION, array() );
		$box      = is_array( $stored ) ? (string) ( $stored['box'] ?? '' ) : '';
		$data     = self::data();
		echo '<h2>' . esc_html__( 'packout.spot on this site\'s map', 'packout-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'The map on this site can show what packout.spot knows about your area: its Spots, its Places, and its map features. They are drawn in a quieter style under the heading "On packout.spot", each one off until a visitor turns it on, and nobody on this site can change them. This site asks packout.spot every fifteen minutes; your visitors\' browsers never do.', 'packout-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-connection__layers">';
		echo '<input type="hidden" name="action" value="packout_hub_layers" />';
		wp_nonce_field( 'packout_hub_layers' );
		echo '<p><label><input type="checkbox" name="on" value="1"' . checked( $settings['on'], true, false ) . ' /> ' . esc_html__( 'Show packout.spot\'s Spots, Places, and map features on this site\'s map', 'packout-core' ) . '</label></p>';
		echo '<p><label for="packout-hub-box">' . esc_html__( 'Region', 'packout-core' ) . '</label><br />';
		echo '<input type="text" id="packout-hub-box" name="box" class="regular-text" value="' . esc_attr( $box ) . '" placeholder="-112.2,34.2,-111.3,35.0" aria-describedby="packout-hub-box-help" /></p>';
		/* translators: %d: a number of kilometres. */
		echo '<p class="description" id="packout-hub-box-help">' . esc_html( sprintf( __( 'Four numbers, separated by commas: the west and south edges, then the east and north edges, as longitude and latitude. At most %d kilometres on a side.', 'packout-core' ), self::SIDE ) ) . '</p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'packout-core' ) . '</button></p>';
		echo '</form>';
		if ( $settings['on'] ) {
			echo '<p>';
			if ( $data['fetched'] > 0 ) {
				/* translators: 1: a time span, 2-4: numbers. */
				echo esc_html( sprintf( __( 'Last read %1$s ago: %2$s Spots, %3$s Places, %4$s map features.', 'packout-core' ), human_time_diff( $data['fetched'] ), number_format_i18n( count( $data['spots'] ) ), number_format_i18n( count( $data['places'] ) ), number_format_i18n( count( $data['features'] ) ) ) );
			} else {
				esc_html_e( 'Not read yet. It is read within five minutes.', 'packout-core' );
			}
			echo '</p>';
		}
	}

	/**
	 * Keep what the section's form sent.
	 */
	public static function action(): void {
		Admin::require( ! App::is_hub() && current_user_can( 'manage_options' ) );
		check_admin_referer( 'packout_hub_layers' );
		$box   = isset( $_POST['box'] ) ? sanitize_text_field( wp_unslash( $_POST['box'] ) ) : '';
		$saved = self::save( ! empty( $_POST['on'] ), $box );
		if ( is_wp_error( $saved ) ) {
			Admin::back(
				Connection_Screen::PAGE,
				array(
					'error'  => 'detail',
					'detail' => $saved->get_error_message(),
				)
			);
		}
		if ( self::on() ) {
			// Read now, so the administrator sees what the region holds. A hub that does not answer is asked again by the timer.
			self::refresh();
		}
		Admin::back( Connection_Screen::PAGE, array( 'updated' => 'layers' ) );
	}
}
