<?php
/**
 * Phase 14 acceptance checks: public map features, geometry, GPX import,
 * adoption requests, My contributions, and search.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-14.php
 *
 * The script creates its own users, a Place, an organization, and features,
 * and removes everything it created. It exits non-zero when a check fails.
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Feature_Icons;
use PackOut\Core\App\Feature_Page;
use PackOut\Core\App\Features;
use PackOut\Core\App\Photos;
use PackOut\Core\App\Places;
use PackOut\Core\App\Profile;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Search;
use PackOut\Core\App\Sponsors;
use PackOut\Core\App\Teams;
use PackOut\Core\App\Updates;
use PackOut\Core\App\Workspace_Views;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

// What only packout.spot does is checked there. On a partner site it is left out, and the run says so.
$hub  = \PackOut\Core\App\App::is_hub();
$skip = static function ( string $what ): void {
	WP_CLI::log( '  --  ' . $what );
};

$packout_failures = array();
$packout_passed   = 0;
$packout_posts    = array();
$packout_users    = array();
$packout_fired    = array();
$packout_run      = strtolower( wp_generate_password( 6, false ) );

$check = static function ( string $label, bool $ok, $detail = '' ) use ( &$packout_failures, &$packout_passed ): void {
	if ( $ok ) {
		++$packout_passed;
		WP_CLI::log( '  ok    ' . $label );
		return;
	}
	$packout_failures[] = $label;
	WP_CLI::log( '  FAIL  ' . $label . ( '' !== $detail ? ' — ' . ( is_scalar( $detail ) ? $detail : wp_json_encode( $detail ) ) : '' ) );
};

$as = static function ( int $user_id ): void {
	wp_set_current_user( $user_id );
};

$code = static fn( $thing ): string => is_wp_error( $thing ) ? (string) $thing->get_error_code() : '';

$user = static function ( string $role, string $name ) use ( &$packout_users, $packout_run ): int {
	$login = 'pos-test-' . sanitize_key( $name ) . '-' . $packout_run;
	$id    = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => $login . '@example.test',
			'display_name' => $name,
			'role'         => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	$packout_users[] = (int) $id;
	return (int) $id;
};

$call = static function ( string $method, string $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, '/packout/v1' . $route );
	if ( 'GET' === $method || 'DELETE' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
};

$listen = static function ( string $name ) use ( &$packout_fired ) {
	return static function () use ( $name, &$packout_fired ): void {
		$packout_fired[] = $name;
	};
};
add_action( 'packout_feature_created', $listen( 'created' ) );
add_action( 'packout_feature_updated', $listen( 'updated' ) );
add_action( 'packout_place_adoption_requested', $listen( 'requested' ) );
add_action( 'packout_place_adopted', $listen( 'adopted' ) );

$point = array(
	'type'        => 'Point',
	'coordinates' => array( -111.8, 34.6 ),
);
$line  = array(
	'type'        => 'LineString',
	'coordinates' => array(
		array( -111.81, 34.60 ),
		array( -111.79, 34.62 ),
		array( -111.78, 34.63 ),
	),
);
$poly  = array(
	'type'        => 'Polygon',
	'coordinates' => array(
		array(
			array( -111.82, 34.59 ),
			array( -111.78, 34.59 ),
			array( -111.78, 34.64 ),
			array( -111.82, 34.64 ),
			array( -111.82, 34.59 ),
		),
	),
);

try {
	$coordinator = $user( 'pos_coordinator', 'Coord Fourteen' );
	$lead        = $user( 'pos_lead', 'Lead Fourteen' );
	$reporter    = $user( 'pos_reporter', 'Rep Fourteen' );
	$other       = $user( 'pos_reporter', 'Other Fourteen' );

	WP_CLI::log( 'Geometry' );
	$parsed = Features::parse_geometry( 'point', $point );
	$check( 'a point is one coordinate', is_array( $parsed ) && 'Point' === ( $parsed['type'] ?? '' ) && array( -111.8, 34.6 ) === $parsed['coordinates'] );
	$parsed = Features::parse_geometry( 'route', $line );
	$check( 'a route is a line of at least two points', is_array( $parsed ) && 'LineString' === ( $parsed['type'] ?? '' ) && 3 === count( $parsed['coordinates'] ) );
	$parsed = Features::parse_geometry( 'boundary', $poly );
	$check( 'a boundary is a closed ring, holes dropped', is_array( $parsed ) && 'Polygon' === ( $parsed['type'] ?? '' ) && 5 === count( $parsed['coordinates'][0] ) );
	$check( 'a line is refused as a Place outline', is_wp_error( Places::parse_shape( (string) wp_json_encode( $line ) ) ) && false !== strpos( Places::parse_shape( (string) wp_json_encode( $line ) )->get_error_message(), 'no inside' ) );
	$check( 'the same line is a route as a feature', is_array( Features::parse_geometry( 'route', $line ) ) );
	$check( 'a point is refused as a route', is_wp_error( Features::parse_geometry( 'route', $point ) ) );
	$check(
		'one point is refused as a route',
		is_wp_error(
			Features::parse_geometry(
				'route',
				array(
					'type'        => 'LineString',
					'coordinates' => array( array( -111.8, 34.6 ) ),
				)
			)
		)
	);
	$check(
		'two points are refused as a boundary',
		is_wp_error(
			Features::parse_geometry(
				'boundary',
				array(
					'type'        => 'Polygon',
					'coordinates' => array( array( array( -111.8, 34.6 ), array( -111.7, 34.6 ), array( -111.8, 34.6 ) ) ),
				)
			)
		)
	);
	$check( 'nothing drawn is empty, not an error', array() === Features::parse_geometry( 'point', '' ) );

	if ( $hub ) {
		WP_CLI::log( 'Permissions and CRUD' );
		$as( 0 );
		$denied = Features::save(
			0,
			array(
				'name'     => 'Visitor Spring',
				'kind'     => 'point',
				'geometry' => $point,
			),
			0
		);
		$check( 'a visitor cannot create a feature', is_wp_error( $denied ) && 'packout_forbidden' === $code( $denied ) );

		$as( $reporter );
		$feature         = Features::save(
			0,
			array(
				'name'        => 'Fourteen Spring ' . $packout_run,
				'description' => 'Water year-round.',
				'kind'        => 'point',
				'color'       => '#bb5e28',
				'icon'        => 'water',
				'geometry'    => $point,
			),
			$reporter
		);
		$packout_posts[] = $feature;
		$check( 'a reporter can create a point', is_int( $feature ) && Features::KIND_POINT === Features::kind( $feature ) && 'mdi:water' === Features::icon( $feature ) && '#bb5e28' === Features::color( $feature ) );
		$check( 'the color palette names the swatches', 'Rust' === ( Features::colors()['#bb5e28'] ?? '' ) && 'mdi:map-marker' === ( Features::icon_aliases()['trailhead'] ?? '' ) && str_contains( Feature_Icons::svg( 'mdi:map-marker' ), 'currentColor' ) );
		$check( 'creating it is announced', array( 'created' ) === $packout_fired, $packout_fired );
		$packout_fired = array();

		$route           = Features::save(
			0,
			array(
				'name'     => 'Fourteen Rim ' . $packout_run,
				'kind'     => 'route',
				'geometry' => $line,
			),
			$reporter
		);
		$packout_posts[] = $route;
		$boundary        = Features::save(
			0,
			array(
				'name'     => 'Fourteen Mesa ' . $packout_run,
				'kind'     => 'boundary',
				'geometry' => $poly,
			),
			$reporter
		);
		$packout_posts[] = $boundary;
		$check( 'a reporter can draw a route and a boundary', is_int( $route ) && 'route' === Features::kind( $route ) && is_int( $boundary ) && 'boundary' === Features::kind( $boundary ) && '' === Features::icon( $route ) );

		$as( $other );
		$check( 'another reporter cannot change it', is_wp_error( Features::save( $feature, array( 'name' => 'Stolen' ), $other ) ) );
		$check( 'or trash it', is_wp_error( Features::trash( $feature, $other ) ) );

		$as( $coordinator );
		$renamed = Features::save( $feature, array( 'name' => 'Fourteen Spring renamed ' . $packout_run ), $coordinator );
		$check( 'a coordinator can change any feature', $feature === $renamed && get_post( $feature )->post_title === 'Fourteen Spring renamed ' . $packout_run );
		$check( 'updating it is announced', array( 'created', 'created', 'updated' ) === $packout_fired || in_array( 'updated', $packout_fired, true ), $packout_fired );
		$kept = Features::save( $feature, array( 'description' => 'Still water.' ), $coordinator );
		$check( 'omitting geometry on an update keeps the drawing', $feature === $kept && is_array( Features::geometry( $feature ) ) && 'Point' === Features::geometry( $feature )['type'] );
		$check( 'and keeps the name', get_post( $feature )->post_title === 'Fourteen Spring renamed ' . $packout_run );

		WP_CLI::log( 'Places, the public map, and search' );
		$as( $coordinator );
		$place           = Places::save(
			0,
			array(
				'name'   => 'Fourteen Corridor ' . $packout_run,
				'kind'   => 'road',
				'listed' => true,
				'shape'  => "34.59, -111.82\n34.59, -111.78\n34.64, -111.78\n34.64, -111.82",
			),
			$coordinator
		);
		$packout_posts[] = $place;
		$as( $reporter );
		$linked = Features::save( $route, array( 'place' => $place ), $reporter );
		$check( 'the author can link a feature to a listed Place', $route === $linked && Features::place( $route ) === $place );

		$hidden          = Places::save(
			0,
			array(
				'name'   => 'Hidden Fourteen Place ' . $packout_run,
				'kind'   => 'trail',
				'listed' => false,
			),
			$coordinator
		);
		$packout_posts[] = $hidden;
		$check( 'a reporter cannot link to a Place that is not listed', is_wp_error( Features::save( $route, array( 'place' => $hidden ), $reporter ) ) );
		$as( $coordinator );
		$check( 'a coordinator can', Features::save( $route, array( 'place' => $hidden ), $coordinator ) === $route && Features::place( $route ) === $hidden );
		Features::save( $route, array( 'place' => $place ), $coordinator );

		$list = Features::public_list();
		$ids  = array_column( $list, 'id' );
		$check( 'public features are on the map payload', in_array( $feature, $ids, true ) && in_array( $route, $ids, true ) && in_array( $boundary, $ids, true ) );
		$as( $reporter );
		$mine = Features::payload( get_post( $feature ) );
		$check( 'a feature carries its name as the map label, a page, and a gallery', is_array( $mine ) && $mine['label'] === $mine['name'] && '' !== $mine['url'] && array() === $mine['gallery'] && ! empty( $mine['editable'] ) );
		$as( 0 );
		$visitor = Features::payload( get_post( $feature ) );
		$check( 'a visitor cannot edit it from the map', is_array( $visitor ) && empty( $visitor['editable'] ) );
		$of_place = Features::public_list( $place );
		$check( 'and can be limited to one Place', array( $route ) === array_column( $of_place, 'id' ) || ( in_array( $route, array_column( $of_place, 'id' ), true ) && 1 === count( $of_place ) ), array_column( $of_place, 'id' ) );

		$found = Search::features( 'fourteen spring' );
		$check( 'search finds a feature by name', 1 === count( $found ) && $feature === $found[0]['id'], $found );
		$as( 0 );
		$bundle = Search::find( 'fourteen rim', null, 0 );
		$check( 'the search bundle has a Features group', isset( $bundle['features'] ) && in_array( $route, array_column( $bundle['features'], 'id' ), true ) );

		$as( 0 );
		$page_of = static function ( int $id, string $type ): string {
			global $wp_query, $wp_the_query, $post;
			$keep = array( $wp_query, $wp_the_query, $post );
			// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Renders one page for a check, then restores the globals.
			$wp_query     = new WP_Query(
				array(
					'post_type' => $type,
					'p'         => $id,
				)
			);
			$wp_the_query = $wp_query;
			$html         = '';
			while ( $wp_query->have_posts() ) {
				$wp_query->the_post();
				$html = (string) apply_filters( 'the_content', get_the_content() );
			}
			list( $wp_query, $wp_the_query, $post ) = $keep;
			// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
			return $html;
		};
		$html    = $page_of( $place, Schema::PLACE );
		$as( $reporter );
		$image = static function ( string $name ): array {
			$raw  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
			$path = wp_tempnam( $name );
			file_put_contents( $path, $raw );
			return array(
				'name'     => $name,
				'tmp_name' => $path,
				'type'     => 'image/png',
				'error'    => 0,
				'size'     => strlen( (string) $raw ),
			);
		};
		$one   = Photos::attach_upload( $feature, $image( 'one.png' ) );
		$two   = Photos::attach_upload( $feature, $image( 'two.png' ) );
		$check( 'a feature holds more than one photo, like a Spot', is_int( $one ) && is_int( $two ) && array( (int) $one, (int) $two ) === array_column( Features::gallery( get_post( $feature ) ), 'id' ), array( is_wp_error( $one ) ? $one->get_error_message() : $one, is_wp_error( $two ) ? $two->get_error_message() : $two ) );
		$check( 'the author can put a later photo first', true === Features::set_photo_order( $feature, array( (int) $two, (int) $one ), $reporter ) && array( (int) $two, (int) $one ) === array_column( Features::gallery( get_post( $feature ) ), 'id' ) );
		$as( $other );
		$check( 'another reporter cannot reorder the photos', is_wp_error( Features::set_photo_order( $feature, array( (int) $one, (int) $two ), $other ) ) );
		$as( $reporter );
		Features::set_photo_order( $feature, array( (int) $one, (int) $two ), $reporter );
		Sponsors::save(
			$feature,
			0,
			array(
				'name' => 'Fourteen Outfitters ' . $packout_run,
				'url'  => 'https://example.org',
			),
			$reporter
		);
		$feature_html = $page_of( $feature, Schema::FEATURE );
		$check( 'the Place page lists linked features', false !== strpos( $html, 'Map features' ) && false !== strpos( $html, get_the_title( $route ) ) );
		$check(
			'the feature page reads like a Spot page and offers the author an edit link',
			false === strpos( $feature_html, 'About this feature' )
			&& false !== strpos( $feature_html, 'Still water.' )
			&& false !== strpos( $feature_html, '34.6, -111.8' )
			&& false !== strpos( $feature_html, 'Adopted by' )
			&& false !== strpos( $feature_html, 'Supported by' )
			&& false !== strpos( $feature_html, 'Fourteen Outfitters ' . $packout_run )
			&& false !== strpos( $feature_html, 'Activity' )
			&& false !== strpos( $feature_html, 'Share an update' )
			&& false !== strpos( $feature_html, 'Edit this Point of interest' )
			&& false !== strpos( $feature_html, 'Only the person who added this, and a coordinator, can edit it.' )
			&& 2 === substr_count( $feature_html, 'pos-slider__slide' )
			&& false !== strpos( $feature_html, get_the_title( $feature ) )
		);
		$as( $other );
		$other_html = $page_of( $feature, Schema::FEATURE );
		$check( 'another reporter is offered Suggest changes, not the edit link', false === strpos( $other_html, 'Edit this Point of interest' ) && false !== strpos( $other_html, 'Suggest changes' ) && false === strpos( $other_html, 'Only the person who added this' ) );
		$mail    = array();
		$capture = static function ( $short, array $atts ) use ( &$mail ) {
			$mail[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 99, 2 );
		$suggestion = Feature_Page::suggest( $feature, $other, 'The spring is further east.' );
		$check( 'a suggestion is stored and sent to the coordinators', is_int( $suggestion ) && 1 === count( $mail ) && false !== strpos( (string) ( $mail[0]['subject'] ?? '' ), 'Suggested change to' ), array( $suggestion, $mail ) );
		$as( $reporter );
		$check( 'the author edits instead of suggesting', is_wp_error( Feature_Page::suggest( $feature, $reporter, 'Move the pin.' ) ) );
		$as( $other );
		$update = Updates::add( $feature, $other, 'Water is low this week.', 0, false );
		$check( 'a signed-in person can leave an update on a feature', is_int( $update ) && false !== strpos( $page_of( $feature, Schema::FEATURE ), 'Water is low this week.' ), $update );
		remove_filter( 'pre_wp_mail', $capture, 99 );

		WP_CLI::log( 'GPX and GeoJSON import' );
		$as( $reporter );
		$gpx    = '<?xml version="1.0"?><gpx xmlns="http://www.topografix.com/GPX/1/1" version="1.1"><wpt lat="34.55" lon="-111.77"><name>Fourteen Waypoint</name><desc>A spring.</desc></wpt><trk><name>Fourteen Track</name><trkseg><trkpt lat="34.55" lon="-111.77"/><trkpt lat="34.56" lon="-111.76"/><trkpt lat="34.57" lon="-111.75"/></trkseg></trk></gpx>';
		$drafts = Features::parse_file( $gpx, 'rim.gpx' );
		$check( 'a namespaced GPX file becomes a point and a route', is_array( $drafts ) && 2 === count( $drafts ) && 'point' === $drafts[0]['kind'] && 'Fourteen Waypoint' === $drafts[0]['name'] && 'route' === $drafts[1]['kind'] && 'Fourteen Track' === $drafts[1]['name'], $drafts );
		$imported = Features::import(
			$drafts,
			array(
				'color' => '#25486b',
				'place' => $place,
			),
			$reporter
		);
		$check( 'import creates features the person owns', is_array( $imported ) && 2 === count( $imported ) );
		foreach ( is_array( $imported ) ? $imported : array() as $id ) {
			$packout_posts[] = $id;
		}
		if ( is_array( $imported ) ) {
			$check( 'imported features use the default color and Place', '#25486b' === Features::color( $imported[0] ) && Features::place( $imported[0] ) === $place && (int) get_post( $imported[0] )->post_author === $reporter );
		}
		$geojson = Features::parse_file(
			(string) wp_json_encode(
				array(
					'type'     => 'FeatureCollection',
					'features' => array(
						array(
							'type'       => 'Feature',
							'properties' => array( 'name' => 'Fourteen Geo Point' ),
							'geometry'   => $point,
						),
						array(
							'type'       => 'Feature',
							'properties' => array( 'name' => 'Fourteen Geo Line' ),
							'geometry'   => $line,
						),
					),
				)
			),
			'rim.geojson'
		);
		$check( 'GeoJSON FeatureCollection becomes a point and a route', is_array( $geojson ) && 2 === count( $geojson ) && 'point' === $geojson[0]['kind'] && 'route' === $geojson[1]['kind'] );
		$check( 'a file that is neither is refused', is_wp_error( Features::parse_file( 'not a map', 'notes.txt' ) ) );
		$as( $reporter );
		$partial = Features::import(
			array(
				array(
					'name'     => 'Fourteen Keep ' . $packout_run,
					'kind'     => 'point',
					'geometry' => $point,
				),
				array(
					'name'     => '',
					'kind'     => 'point',
					'geometry' => $point,
				),
			),
			array(),
			$reporter
		);
		$check( 'a failed import saves none of the file', is_wp_error( $partial ) && array() === array_filter( Features::of_user( $reporter ), static fn( $post ) => 'Fourteen Keep ' . $packout_run === $post->post_title ) );

		WP_CLI::log( 'Adoption requests' );
		$as( $coordinator );
		$team            = Teams::save(
			0,
			array(
				'name'   => 'Fourteen Crew ' . $packout_run,
				'lead'   => $lead,
				'listed' => true,
			)
		);
		$packout_posts[] = $team;
		Teams::add_member( $team, get_userdata( $reporter )->user_login );
		$as( $other );
		$check( 'a stranger cannot request adoption', is_wp_error( Places::request_adoption( $place, $team, $other ) ) );
		$as( $reporter );
		$request = Places::request_adoption(
			$place,
			$team,
			$reporter,
			array(
				'note'       => 'We walk it every month.',
				'feature_id' => $route,
			)
		);
		$check( 'a member can request adoption for an organization they belong to', is_string( $request ) && 36 === strlen( $request ) );
		$check( 'requesting it is announced', in_array( 'requested', $packout_fired, true ), $packout_fired );
		$again = Places::request_adoption( $place, $team, $reporter );
		$check( 'a second pending request for the same organization is refused', is_wp_error( $again ) );
		$rows = Places::adoption_requests_for_team( $team );
		$check( 'the organization can list the request', 1 === count( $rows ) && $request === $rows[0]['id'] && 'pending' === $rows[0]['status'] && $route === $rows[0]['feature_id'] && 'We walk it every month.' === $rows[0]['note'] );

		$as( $reporter );
		$check( 'a member cannot accept it', is_wp_error( Places::decide_request( $place, $request, $reporter, array( 'status' => 'accepted' ) ) ) );
		$as( $lead );
		$check( 'a lead can edit the note', true === Places::decide_request( $place, $request, $lead, array( 'note' => 'First Saturday.' ) ) && 'First Saturday.' === Places::adoption_requests( $place )[0]['note'] );
		$check( 'and accept it', true === Places::decide_request( $place, $request, $lead, array( 'status' => 'accepted' ) ) );
		$check( 'accepting records the adopter', ( Places::adopters( $place )[0]['team'] ?? 0 ) === $team );
		$as( 0 );
		$route_html = $page_of( $route, Schema::FEATURE );
		$check( 'the feature page names the organization that adopted its Place', false !== strpos( $route_html, 'Adopted by' ) && false !== strpos( $route_html, 'Fourteen Crew ' . $packout_run ) );
		$check( 'and is announced as an adoption', in_array( 'adopted', $packout_fired, true ), $packout_fired );
		$check( 'a lead can decline after accepting, which releases the Place', true === Places::decide_request( $place, $request, $lead, array( 'status' => 'declined' ) ) && array() === Places::adopters( $place ) );
		$check( 'and accept again', true === Places::decide_request( $place, $request, $lead, array( 'status' => 'accepted' ) ) && ( Places::adopters( $place )[0]['team'] ?? 0 ) === $team );

		$as( $lead );
		$html = Workspace_Views::team( Teams::get( $team ), 'submissions', '', $lead );
		$check( 'Submissions lists the request', false !== strpos( $html, 'Fourteen Corridor' ) && false !== strpos( $html, 'Accepted' ) && false !== strpos( $html, 'Started from the map feature' ) );

		WP_CLI::log( 'My contributions and REST' );
		$as( $reporter );
		$done = Profile::contributions( $reporter );
		$check( 'My contributions includes the features the person added', isset( $done['features'] ) && count( $done['features'] ) >= 3 );
		$html = Profile::render( array( 'view' => 'contributions' ) );
		$check( 'My contributions shows the list and the large map editor', false !== strpos( $html, 'pos-account-features' ) && false !== strpos( $html, 'Add a map feature' ) && false !== strpos( $html, get_the_title( $route ) ) );

		$as( $reporter );
		list( $status, $body ) = $call(
			'POST',
			'/features',
			array(
				'name'     => 'Fourteen REST Point ' . $packout_run,
				'kind'     => 'point',
				'geometry' => $point,
				'icon'     => 'trailhead',
			)
		);
		$rest_id               = (int) ( $body['feature']['id'] ?? 0 );
		if ( $rest_id > 0 ) {
			$packout_posts[] = $rest_id;
		}
		$check( 'REST creates a feature for the reporter', 201 === $status && $rest_id > 0 && 'mdi:map-marker' === ( $body['feature']['icon'] ?? '' ), array( $status, $body ) );
		list( $status, $body ) = $call( 'PUT', '/features/' . $rest_id, array( 'name' => 'Fourteen REST Point renamed ' . $packout_run ) );
		$check( 'REST updates it', 200 === $status && ( $body['feature']['name'] ?? '' ) === 'Fourteen REST Point renamed ' . $packout_run );
		$as( $other );
		list( $status ) = $call( 'DELETE', '/features/' . $rest_id );
		$check( 'REST refuses another reporter who trashes it', $status >= 400 );
		$as( $reporter );
		list( $status, $body ) = $call( 'DELETE', '/features/' . $rest_id );
		$check( 'REST trashes the author\'s feature', 200 === $status && ! empty( $body['deleted'] ) && null === Features::get( $rest_id ) );
		list( $status, $body ) = $call( 'GET', '/features' );
		$check( 'REST lists public features', 200 === $status && isset( $body['features'] ) && in_array( $feature, array_column( $body['features'], 'id' ), true ) );

		$as( $reporter );
		$trashed = Features::trash( $boundary, $reporter );
		$check( 'trashing a feature removes it from the map', true === $trashed && null === Features::get( $boundary ) && ! in_array( $boundary, array_column( Features::public_list(), 'id' ), true ) );
	} else {
		$skip( 'Map features and requests to adopt a Place are kept on packout.spot.' );
		$check( 'on a partner site nobody draws a map feature', ! Features::can_create( $reporter ) && ! Features::can_create( $lead ) && ! Features::can_create( $coordinator ) );
		$refused = Features::save(
			0,
			array(
				'name'     => 'A point on a partner site ' . $packout_run,
				'kind'     => 'point',
				'geometry' => $point,
			),
			$coordinator
		);
		$check( 'saving one is refused', is_wp_error( $refused ) );
		$check( 'and a feature has no public page there', ! get_post_type_object( Schema::FEATURE )->public );
	}
} finally {
	foreach ( $packout_posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	foreach ( $packout_users as $user_id ) {
		if ( is_multisite() ) {
			wpmu_delete_user( $user_id );
		} else {
			wp_delete_user( $user_id );
		}
	}
}

WP_CLI::log( '' );
WP_CLI::log( $packout_passed . ' checks passed, ' . count( $packout_failures ) . ' failed.' );
if ( $packout_failures ) {
	WP_CLI::error( 'Phase 14 failed: ' . implode( '; ', $packout_failures ) );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
