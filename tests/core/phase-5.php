<?php
/**
 * Phase 5 acceptance checks for Places, clearances, Place sponsors, and export.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-5.php
 *
 * The script creates its own users, Spots, an organization, a mission, Places, and
 * records, and removes everything it created. It exits non-zero when a check
 * fails.
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Blocks;
use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Export;
use PackOut\Core\App\Geo;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Places;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Sponsors;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Teams;

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
$packout_files    = array();
$packout_fired    = array();

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

$call = static function ( string $method, string $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, '/packout/v1' . $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
};

$user = static function ( string $role ) use ( &$packout_users ): int {
	$login           = 'pos-test-' . $role . '-' . wp_generate_password( 6, false );
	$id              = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);
	$packout_users[] = $id;
	return $id;
};

$logo = static function () use ( &$packout_files ): array {
	$path  = wp_tempnam( 'pos-logo.png' );
	$image = imagecreatetruecolor( 64, 48 );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 37, 72, 107 ) );
	imagepng( $image, $path );
	$packout_files[] = $path;
	return array(
		'name'     => 'logo.png',
		'type'     => 'image/png',
		'tmp_name' => $path,
		'error'    => 0,
		'size'     => filesize( $path ),
	);
};

$stage      = static fn( int $spot ) => Spots::field( $spot, 'status', 'spotted' );
$listen     = static function ( string $name ) use ( &$packout_fired ) {
	return static function () use ( $name, &$packout_fired ): void {
		$packout_fired[] = $name;
	};
};
$on_adopted = $listen( 'adopted' );
$on_cleared = $listen( 'cleared' );
add_action( 'packout_place_adopted', $on_adopted );
add_action( 'packout_spot_cleared', $on_cleared );

// A corridor about 3 km long and 600 m wide, well away from the seeded Spots.
$corridor = array(
	array( 34.4000, -112.6100 ),
	array( 34.4000, -112.6040 ),
	array( 34.4300, -112.6040 ),
	array( 34.4300, -112.6100 ),
);
$lines    = implode( "\n", array_map( static fn( $point ) => $point[0] . ', ' . $point[1], $corridor ) );
$geojson  = wp_json_encode(
	array(
		'type'     => 'Feature',
		'geometry' => array(
			'type'        => 'Polygon',
			'coordinates' => array(
				array_map( static fn( $point ) => array( $point[1], $point[0] ), array_merge( $corridor, array( $corridor[0] ) ) ),
				// A hole, which is dropped.
				array( array( -112.607, 34.41 ), array( -112.606, 34.41 ), array( -112.606, 34.411 ), array( -112.607, 34.41 ) ),
			),
		),
	)
);

try {
	$coordinator = $user( 'pos_coordinator' );
	$lead        = $user( 'pos_lead' );
	$crew        = $user( 'pos_crew' );
	$reporter    = $user( 'pos_reporter' );

	$spot = static function ( string $what, ?float $lat, ?float $lng, int $author, bool $publish = true ) use ( &$packout_posts ): int {
		$input = array(
			'category'    => 'cleanup-waste',
			'description' => $what,
		);
		if ( null !== $lat ) {
			$input['lat'] = $lat;
			$input['lng'] = $lng;
		} else {
			$input['landmark'] = 'By the cattle guard at the corridor gate';
		}
		$id              = Spots::create( $input, $author );
		$packout_posts[] = $id;
		if ( $publish ) {
			Spots::publish( $id );
		}
		return $id;
	};

	$in_a        = $spot( 'Mattress in the corridor.', 34.4050, -112.6070, $reporter );
	$in_b        = $spot( 'Tires in the corridor.', 34.4150, -112.6065, $reporter );
	$coarse      = $spot( 'Dumped drums in the corridor.', 34.4212, -112.6071, $reporter );
	$waiting     = $spot( 'Not reviewed, in the corridor.', 34.4100, -112.6080, $reporter, false );
	$outside     = $spot( 'Cans outside the corridor.', 34.4500, -112.6070, $reporter );
	$landmark    = $spot( 'Fence wire by the gate.', null, null, $reporter );
	$elsewhere   = $spot( 'Pallets in the corridor, already in another Place.', 34.4250, -112.6075, $reporter );
	$exact_title = get_post( $coarse )->post_title;
	$exact_name  = get_post( $coarse )->post_name;
	Spots::set_precision( $coarse, 'coarse' );

	$team            = Teams::save(
		0,
		array(
			'name'   => 'Corridor Test Crew',
			'lead'   => $lead,
			'listed' => true,
		)
	);
	$packout_posts[] = $team;
	Teams::add_member( $team, get_userdata( $crew )->user_login );
	$quiet           = Teams::save(
		0,
		array(
			'name'   => 'Unlisted Test Crew',
			'lead'   => $lead,
			'listed' => false,
		)
	);
	$packout_posts[] = $quiet;

	WP_CLI::log( 'Install' );
	$type = get_post_type_object( Schema::PLACE );
	if ( $hub ) {
		$check( 'the Place type is registered with a public page and no editing screen', $type && $type->public && ! $type->show_ui && ! $type->show_in_rest );
		$check( 'a coordinator keeps Places and exports, and a lead does neither', Places::can_manage( $coordinator ) && Export::can( $coordinator ) && ! Places::can_manage( $lead ) && ! Export::can( $lead ) );
		$check( 'the Place list block is registered', WP_Block_Type_Registry::get_instance()->is_registered( 'packout/places' ) );
	} else {
		$check( 'on a partner site a Place has no public page and nobody keeps Places', $type && ! $type->public && ! $type->show_ui && ! Places::can_manage( $coordinator ) && ! Places::can_manage( $lead ) );
		$check( 'a coordinator still exports, and a lead does not', Export::can( $coordinator ) && ! Export::can( $lead ) );
		$check( 'and the Place list block is not registered', ! WP_Block_Type_Registry::get_instance()->is_registered( 'packout/places' ) );
		$refused = Places::save( 0, array( 'name' => 'A Place on a partner site' ), $coordinator );
		$check( 'a coordinator cannot make a Place here', is_wp_error( $refused ) );
	}
	if ( $hub ) {
		$check( 'Places are stored in a Toolkit table with their fields', in_array( Schema::PLACE, (array) apply_filters( 'vectra_tables_post_types', array() ), true ) && in_array( '_pos_shape', (array) apply_filters( 'vectra_tables_meta_keys', array(), Schema::PLACE ), true ) );
	}

	WP_CLI::log( 'A coarse Spot' );
	$check( 'a generated title and address name the pin of an exact Spot', false !== strpos( $exact_title, '34.4212' ) && false !== strpos( $exact_name, '34-4212' ), array( $exact_title, $exact_name ) );
	$check( 'marking the Spot coarse takes the pin out of its title', false === strpos( get_post( $coarse )->post_title, '34.42' ) && false === strpos( get_post( $coarse )->post_title, '112.6' ), get_post( $coarse )->post_title );
	$check( 'and out of its address, with no redirect from the old one', false === strpos( get_post( $coarse )->post_name, '34-42' ) && false === strpos( (string) get_permalink( $coarse ), '112-6' ) && array() === get_post_meta( $coarse, '_wp_old_slug', false ), get_post( $coarse )->post_name );
	Spots::update( $coarse, array( 'description' => 'Dumped drums in the corridor, two of them leaking.' ) );
	$check( 'an edit keeps it out', false === strpos( get_post( $coarse )->post_title, '34.42' ) && false === strpos( get_post( $coarse )->post_name, '34-42' ), get_post( $coarse )->post_title );
	$named = $spot( 'Coarse with a landmark.', null, null, $reporter );
	Spots::set_precision( $named, 'coarse' );
	$check( 'a coarse Spot\'s title does not name its landmark either', false === strpos( get_post( $named )->post_title, 'cattle guard' ), get_post( $named )->post_title );
	Spots::update( $named, array( 'title' => 'Wire at the gate' ) );
	Spots::set_precision( $named, 'exact' );
	Spots::set_precision( $named, 'coarse' );
	$check( 'a title a person typed is left as they typed it', 'Wire at the gate' === get_post( $named )->post_title );

	WP_CLI::log( 'Outlines' );
	$ring = Places::parse_shape( $lines );
	$check( 'an outline is read from latitude and longitude lines', is_array( $ring ) && 4 === count( $ring ) && array( -112.61, 34.4 ) === $ring[0], $ring );
	$from_json = Places::parse_shape( (string) $geojson );
	$check( 'and from GeoJSON, without the closing point or the hole', is_array( $from_json ) && $from_json === $ring, $from_json );
	$check( 'nothing typed is no outline', array() === Places::parse_shape( "  \n " ) );
	$line = Places::parse_shape( '{"type":"LineString","coordinates":[[-112.61,34.4],[-112.60,34.41],[-112.61,34.43]]}' );
	$check( 'a line is refused, because it has no inside', is_wp_error( $line ) && false !== strpos( $line->get_error_message(), 'no inside' ) );
	$check( 'two points are refused', is_wp_error( Places::parse_shape( "34.4, -112.61\n34.41, -112.60" ) ) );
	$check( 'a point off the globe is refused', is_wp_error( Places::parse_shape( "134.4, -112.61\n34.41, -112.60\n34.42, -112.61" ) ) );
	$check( 'words are refused', is_wp_error( Places::parse_shape( 'around the old corral' ) ) );
	$many = array();
	for ( $i = 0; $i <= Places::SHAPE_LIMIT; $i++ ) {
		$many[] = round( 34.4 + 0.01 * sin( $i / 80 ), 6 ) . ', ' . round( -112.6 + 0.01 * cos( $i / 80 ) + $i / 1e6, 6 );
	}
	$check( 'an outline of more than 500 points is refused', is_wp_error( Places::parse_shape( implode( "\n", $many ) ) ) );
	$check( 'a point inside the outline is inside', Places::contains( $ring, 34.4150, -112.6065 ) );
	$check( 'a point outside is not', ! Places::contains( $ring, 34.4500, -112.6070 ) && ! Places::contains( $ring, 34.4150, -112.6200 ) );

	if ( $hub ) {
		WP_CLI::log( 'A Place' );
		$refused = Places::save( 0, array( 'name' => 'Lead\'s own Place' ), $lead );
		$check( 'a lead cannot create a Place', is_wp_error( $refused ) && 'packout_forbidden' === $refused->get_error_code() );
		$check( 'a Place needs a name', is_wp_error( Places::save( 0, array( 'name' => '  ' ), $coordinator ) ) );
		$bad = Places::save(
			0,
			array(
				'name'  => 'Bad outline',
				'shape' => 'around the old corral',
			),
			$coordinator
		);
		$check(
			'a Place with an unreadable outline is not created',
			is_wp_error( $bad ) && ! get_posts(
				array(
					'post_type'   => Schema::PLACE,
					'post_status' => 'any',
					's'           => 'Bad outline',
					'fields'      => 'ids',
				)
			)
		);

		$place           = Places::save(
			0,
			array(
				'name'        => 'Test Road 9713 Corridor',
				'kind'        => 'road',
				'description' => 'Three kilometers of forest road with dispersed campsites.',
				'shape'       => $lines,
				'listed'      => false,
			),
			$coordinator
		);
		$packout_posts[] = $place;
		$check( 'a coordinator draws a road corridor', is_int( $place ) && 'road' === Places::kind( $place ) && 4 === count( Places::shape( $place ) ) && 'private' === get_post_status( $place ), $place );
		$other           = Places::save(
			0,
			array(
				'name'   => 'Test Trailhead',
				'kind'   => 'trailhead',
				'listed' => true,
			),
			$coordinator
		);
		$packout_posts[] = $other;
		$check( 'a Place can have no outline', is_int( $other ) && array() === Places::shape( $other ) && 'trailhead' === Places::kind( $other ) );
		$check( 'a Place with no outline has nothing to attach in bulk', is_wp_error( Places::attach_inside( $other, $coordinator ) ) );

		WP_CLI::log( 'Spots in a Place' );
		Places::attach( $elsewhere, $other, $coordinator );
		$inside = Places::inside( $place );
		$free   = wp_list_pluck( $inside['free'], 'ID' );
		sort( $free );
		$expect = array( $in_a, $in_b, $coarse, $waiting );
		sort( $expect );
		$check( 'the Spots with a pin inside the outline are found, reviewed or not, by their stored pins', $free === $expect, $free );
		$check( 'a Spot of another Place is named and left out', array( $elsewhere ) === wp_list_pluck( $inside['elsewhere'], 'ID' ) );
		$check( 'a lead cannot attach Spots', is_wp_error( Places::attach_inside( $place, $lead ) ) && is_wp_error( Places::attach( $landmark, $place, $lead ) ) && ! Places::of_spot( $in_a ) );
		$check( 'the coordinator attaches the four in one step', 4 === Places::attach_inside( $place, $coordinator ) && Places::of_spot( $in_a )->ID === $place && Places::of_spot( $coarse )->ID === $place );
		$check( 'the Spot of the other Place stays there', Places::of_spot( $elsewhere )->ID === $other );
		$check( 'the Spot outside stays in no Place', null === Places::of_spot( $outside ) );
		$check( 'a second pass attaches nothing', 0 === Places::attach_inside( $place, $coordinator ) );
		$check( 'a Spot with only a landmark is attached by hand', true === Places::attach( $landmark, $place, $coordinator ) && 5 === count( Places::spots( $place ) ) );
		$check( 'and removed by hand', true === Places::attach( $landmark, 0, $coordinator ) && null === Places::of_spot( $landmark ) && 4 === count( Places::spots( $place ) ) );
		Places::attach( $landmark, $place, $coordinator );

		WP_CLI::log( 'Adoption' );
		$pending = Teams::propose(
			$reporter,
			array(
				'name'        => 'Waiting Test Crew',
				'description' => 'A crew that is waiting for approval.',
			)
		);
		if ( is_int( $pending ) ) {
			$packout_posts[] = $pending;
		}
		$check( 'an organization that is waiting for approval cannot adopt', is_int( $pending ) && is_wp_error( Places::save( $place, array( 'team' => $pending ), $coordinator ) ) && null === Places::adoption( $place ) );
		$saved    = Places::save(
			$place,
			array(
				'team'          => $team,
				'adopted_on'    => '2026-04-18',
				'adoption_note' => 'Walked on the first Saturday of each month.',
			),
			$coordinator
		);
		$adoption = Places::adoption( $place );
		$check( 'the coordinator names the adopting organization, with a date and a note', $place === $saved && $adoption && $team === $adoption['team'] && '2026-04-18' === $adoption['since'] && 'Walked on the first Saturday of each month.' === $adoption['note'], $adoption );
		$check( 'the adoption is announced once', array( 'adopted' ) === $packout_fired, $packout_fired );
		$check( 'saving the adoption kept the name, the kind, and the outline', 'Test Road 9713 Corridor' === get_post( $place )->post_title && 'road' === Places::kind( $place ) && 4 === count( Places::shape( $place ) ), array( get_post( $place )->post_title, Places::kind( $place ), count( Places::shape( $place ) ) ) );
		$check( 'the organization gains no capability by adopting', ! Places::can_manage( $lead ) && ! Capabilities::can( Capabilities::MODERATE, $lead ) && ! Capabilities::can( Capabilities::MODERATE, $crew ) );
		$check( 'the organization\'s Places are known to it', array( $place ) === wp_list_pluck( Places::of_team( $team ), 'ID' ) && array() === Places::of_team( $team, true ) );

		WP_CLI::log( 'The public Place' );
		$check( 'a Place that is not listed is not in the public list or on the map', ! in_array( $place, wp_list_pluck( Places::listed(), 'id' ), true ) && ! in_array( $place, wp_list_pluck( Places::outlines(), 'id' ), true ) );
		$check( 'and the public is not told a Spot belongs to it', null === Spots::payload( Spots::get( $in_a ), 'public' )['place'] && Spots::payload( Spots::get( $in_a ), 'exact' )['place']['id'] === $place );
		Places::save( $place, array( 'listed' => true ), $coordinator );
		$check( 'listing it kept the adoption', 'publish' === get_post_status( $place ) && Places::adoption( $place ) && Places::adoption( $place )['team'] === $team );
		$public = Places::public_payload( Places::get( $place ) );
		$ids    = wp_list_pluck( $public['spots'], 'id' );
		sort( $ids );
		$expect = array( $in_a, $in_b, $coarse, $landmark );
		sort( $expect );
		$check( 'the public Place has its published Spots and not the one waiting for review', $ids === $expect, $ids );
		$check( 'with the adopting organization named and linked', $public['adoption'] && 'Corridor Test Crew' === $public['adoption']['team'] && get_permalink( $team ) === $public['adoption']['url'] );
		$shown = current( array_filter( $public['spots'], static fn( $row ) => $row['id'] === $coarse ) );
		$check( 'a coarse Spot is at its coarse point, not its pin', Geo::coarse( 34.4212, -112.6071 ) === array( $shown['location']['lat'], $shown['location']['lng'] ) && 34.4212 !== $shown['location']['lat'], $shown['location'] );
		$check( 'the public is told a Spot is in the listed Place', Spots::payload( Spots::get( $in_a ), 'public' )['place']['id'] === $place && get_permalink( $place ) === Spots::payload( Spots::get( $in_a ), 'public' )['place']['url'] );
		$check( 'the map gets the outline and the name', in_array( $place, wp_list_pluck( Places::outlines(), 'id' ), true ) && in_array( $place, wp_list_pluck( Blocks::map_config()['outlines'], 'id' ), true ) );
		$check( 'a Place with no outline is listed and has no outline on the map', in_array( $other, wp_list_pluck( Places::listed(), 'id' ), true ) && ! in_array( $other, wp_list_pluck( Places::outlines(), 'id' ), true ) );
		$check( 'the organization\'s public Places include it', array( $place ) === wp_list_pluck( Places::of_team( $team, true ), 'ID' ) );

		Places::save( $other, array( 'team' => $quiet ), $coordinator );
		$hidden = Places::public_payload( Places::get( $other ) );
		$check( 'an organization that is not listed is not named on the public Place', $hidden['adoption'] && '' === $hidden['adoption']['team'] && '' === $hidden['adoption']['url'] && false === strpos( Places::adoption_line( $hidden['adoption'] ), 'Unlisted' ) );

		$list = Blocks::render_places();
		$check( 'the Place list block shows the Place, its kind, its open Spots, and the organization', false !== strpos( $list, 'Test Road 9713 Corridor' ) && false !== strpos( $list, 'Road corridor' ) && false !== strpos( $list, '4 open Spots' ) && false !== strpos( $list, 'Corridor Test Crew' ), $list );
		$check( 'and never the unlisted organization', false === strpos( $list, 'Unlisted Test Crew' ) );

		WP_CLI::log( 'Clearance' );
		Spots::set_hazard( $in_b, 'needs_authorization' );
		Spots::set_hazard( $coarse, 'do_not_touch' );
		$as( $lead );
		$m               = Missions::save(
			0,
			array(
				'title' => 'Corridor test mission',
				'team'  => $team,
				'spots' => array( $in_a, $in_b ),
			),
			$lead
		);
		$packout_posts[] = $m;
		$check( 'the Spot is blocked', Spots::blocked( $in_b ) && true === Spots::payload( Spots::get( $in_b ), 'exact' )['needs_authorization'] && false === Spots::payload( Spots::get( $in_b ), 'exact' )['authorized'] );

		$as( $crew );
		list( $code, $data ) = $call(
			'PUT',
			'/missions/' . $m . '/progress',
			array(
				'revision' => Missions::revision( $m ),
				'outcomes' => array( $in_b => 'cleaned' ),
			)
		);
		$check( 'a crew member cannot mark it cleaned before a clearance', 200 === $code && 1 === count( $data['refused'] ) && 'cleaned' !== $stage( $in_b ), $data );

		$check( 'a lead cannot record a clearance', is_wp_error( Spots::clear( $in_b, array( 'reference' => 'Lead says so' ), $lead ) ) && Spots::blocked( $in_b ) );
		$empty = Spots::clear( $in_b, array( 'scope' => 'Remove the tires.' ), $coordinator );
		$check( 'a clearance needs a reference', is_wp_error( $empty ) && 'packout_clearance_reference' === $empty->get_error_code() && Spots::blocked( $in_b ) );
		$none = Spots::clear( $coarse, array( 'reference' => 'Permit 12' ), $coordinator );
		$check( 'a do-not-touch Spot has no clearance', is_wp_error( $none ) && 'packout_no_clearance' === $none->get_error_code() && Spots::blocked( $coarse ) );
		$check( 'nor does a Spot with no flag', is_wp_error( Spots::clear( $in_a, array( 'reference' => 'Permit 12' ), $coordinator ) ) );

		$revision  = Spots::revision( $in_b );
		$cleared   = Spots::clear(
			$in_b,
			array(
				'reference' => 'District email of 2 May 2026 from the recreation staff officer',
				'scope'     => 'Remove the tires by hand. No vehicles off the road.',
				'on'        => '2026-05-02',
			),
			$coordinator
		);
		$clearance = Spots::clearance( $in_b );
		$check( 'the coordinator records a clearance with a reference and a scope', true === $cleared && $clearance && $coordinator === $clearance['by'] && '2026-05-02' === $clearance['on'] && false === $clearance['public'], $clearance );
		$check( 'the Spot is no longer blocked, the change reaches the field app, and it is announced once', ! Spots::blocked( $in_b ) && Spots::revision( $in_b ) > $revision && array( 'cleared' ) === array_values( array_diff( $packout_fired, array( 'adopted' ) ) ), array( Spots::blocked( $in_b ), $revision, Spots::revision( $in_b ), $packout_fired ) );
		$crew_view = Spots::payload( Spots::get( $in_b ), 'exact' );
		$check( 'the crew sees the reference and the scope', false === $crew_view['needs_authorization'] && true === $crew_view['authorized'] && 'Remove the tires by hand. No vehicles off the road.' === $crew_view['clearance']['scope'] && false !== strpos( $crew_view['clearance']['reference'], 'District email' ) );
		$public_view = Spots::payload( Spots::get( $in_b ), 'public' );
		$check( 'the public is still told the work needs permission, and sees nothing of the clearance', true === $public_view['needs_authorization'] && false === $public_view['authorized'] && ! isset( $public_view['clearance'] ) && false === strpos( (string) wp_json_encode( $public_view ), 'District email' ) );
		$check( 'the mission\'s Spot list says it is authorized', false !== strpos( Spots::hazard_fact( $in_b ), 'Authorized: District email' ) );

		Spots::clear(
			$in_b,
			array(
				'reference' => 'District email of 2 May 2026 from the recreation staff officer',
				'scope'     => 'Remove the tires by hand. No vehicles off the road.',
				'on'        => '2026-05-02',
				'public'    => true,
			),
			$coordinator
		);
		$public_view = Spots::payload( Spots::get( $in_b ), 'public' );
		$check( 'with the public line on, the public is told the work is authorized and nothing more', false === $public_view['needs_authorization'] && true === $public_view['authorized'] && ! isset( $public_view['clearance'] ) && false === strpos( (string) wp_json_encode( $public_view ), 'District email' ) && false !== strpos( $public_view['guidance'], 'authorized' ) );

		list( $code, $data ) = $call(
			'PUT',
			'/missions/' . $m . '/progress',
			array(
				'revision' => Missions::revision( $m ),
				'outcomes' => array( $in_b => 'cleaned' ),
			)
		);
		$check( 'the crew member marks the cleared Spot cleaned', 200 === $code && array() === $data['refused'] && 'cleaned' === $stage( $in_b ), $data );

		Spots::clear( $in_b, array( 'reference' => 'To be withdrawn' ), $coordinator );
		$check( 'a lead cannot withdraw a clearance', is_wp_error( Spots::unclear( $in_b, $lead ) ) && null !== Spots::clearance( $in_b ) );
		$check( 'the coordinator can', true === Spots::unclear( $in_b, $coordinator ) && null === Spots::clearance( $in_b ) && Spots::blocked( $in_b ) );
		Spots::clear( $in_b, array( 'reference' => 'Permit 12' ), $coordinator );
		Spots::set_hazard( $in_b, 'none' );
		Spots::set_hazard( $in_b, 'needs_authorization' );
		$check( 'changing the flag deletes the clearance, so the next one starts from nothing', null === Spots::clearance( $in_b ) && '' === Spots::field( $in_b, 'clear_ref' ) && Spots::blocked( $in_b ) );

		$stuck           = Missions::save(
			0,
			array(
				'title' => 'All flagged test mission',
				'team'  => $team,
				'spots' => array( $landmark ),
			),
			$lead
		);
		$packout_posts[] = $stuck;
		Spots::set_hazard( $landmark, 'needs_authorization' );
		$refusal = Missions::set_status( $stuck, 'completed' );
		$check( 'a mission whose Spots all wait for a clearance cannot be completed', is_wp_error( $refusal ) && 'packout_mission_hazard' === $refusal->get_error_code() );
		Spots::clear( $landmark, array( 'reference' => 'Grazing permittee agreed by phone, 3 May' ), $coordinator );
		$check( 'and can once one is cleared', true === Missions::set_status( $stuck, 'completed' ) );

		WP_CLI::log( 'History and sponsors' );
		$as( $crew );
		$call(
			'PUT',
			'/missions/' . $m . '/progress',
			array(
				'revision' => Missions::revision( $m ),
				'outcomes' => array( $in_a => 'cleaned' ),
			)
		);
		$as( $lead );
		$record = Impact::save_cleanup(
			$in_a,
			array(
				'narrative'     => 'Hauled the mattress out.',
				'hours'         => 3,
				'people'        => 2,
				'bags'          => 4,
				'bags_source'   => 'measured',
				'pounds'        => 60,
				'pounds_source' => 'self_reported',
			),
			$lead
		);
		if ( ! is_wp_error( $record ) ) {
			$packout_posts[] = $record;
		}
		$check( 'a cleanup at a Spot of the Place is recorded', is_int( $record ), $record );
		$check( 'the Place counts it for coordinators and not yet for the public', 1 === Places::totals( $place )['records']['cleanups'] && 0 === Places::totals( $place, true )['records']['cleanups'] && array() === Places::history( $place ) );
		Impact::verify( $record, $coordinator );
		Impact::set_visible( $record, true, $coordinator );
		$history = Places::history( $place );
		$totals  = Places::totals( $place, true );
		$check( 'once a coordinator counts it, the public history has it with the organization and no person', 1 === count( $history ) && 'Corridor Test Crew' === $history[0]['team'] && $history[0]['verified'] && false === strpos( (string) wp_json_encode( $history ), get_userdata( $lead )->display_name ), $history );
		$check( 'with measured and self-reported figures apart', 4 === $totals['outputs']['bags_measured'] && 0 === $totals['outputs']['bags_self_reported'] && 60.0 === (float) $totals['outputs']['pounds_self_reported'] && 0.0 === (float) $totals['outputs']['pounds_measured'], $totals['outputs'] );
		$check( 'the other Place has no history', array() === Places::history( $other ) && 0 === Places::totals( $other )['records']['cleanups'] );

		$check( 'a lead cannot name a sponsor of a Place', is_wp_error( Sponsors::save( $place, 0, array( 'name' => 'Lead\'s Friend' ), $lead ) ) && array() === Sponsors::all( $place ) );
		$sponsor = Sponsors::save(
			$place,
			0,
			array(
				'name' => 'Test Tire Shop',
				'url'  => 'https://tires.example.test/',
			),
			$coordinator,
			$logo()
		);
		$listed  = Sponsors::all( $place );
		$check( 'a coordinator names a sponsor of the Place, with a logo', is_int( $sponsor ) && 1 === count( $listed ) && 'Test Tire Shop' === $listed[0]['name'] && $listed[0]['logo'] > 0, $sponsor );
		$check( 'the sponsor is on the public Place', 'Test Tire Shop' === Places::public_payload( Places::get( $place ) )['sponsors'][0]['name'] );
		$check( 'an organization\'s sponsors are still kept by its lead', is_int( Sponsors::save( $team, 0, array( 'name' => 'Organization Sponsor' ), $lead ) ) && is_wp_error( Sponsors::save( $team, 0, array( 'name' => 'Stranger' ), $reporter ) ) );
		$check( 'a sponsor is a credit and holds no capability', ! get_user_by( 'login', 'Test Tire Shop' ) && ! Capabilities::can( Capabilities::MODERATE, $lead ) );
		$check( 'a lead cannot remove it, and a coordinator can', is_wp_error( Sponsors::remove( $place, (int) $sponsor, $lead ) ) && true === Sponsors::remove( $place, (int) $sponsor, $coordinator ) && array() === Sponsors::all( $place ) );
		Sponsors::save( $place, 0, array( 'name' => 'Test Tire Shop' ), $coordinator );

		WP_CLI::log( 'The public page' );
		$as( 0 );
		// The page as the theme asks for it: the main query, inside the loop.
		$render = static function ( int $id, string $status = 'publish' ): string {
			global $wp_query, $wp_the_query, $post;
			$keep = array( $wp_query, $wp_the_query, $post );
			// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Renders one page for a check, then restores the globals.
			$wp_query     = new WP_Query(
				array(
					'post_type'   => Schema::PLACE,
					'p'           => $id,
					'post_status' => $status,
				)
			);
			$wp_the_query = $wp_query;
			$html         = 'none';
			while ( $wp_query->have_posts() ) {
				$wp_query->the_post();
				$html = (string) apply_filters( 'the_content', get_the_content() );
			}
			list( $wp_query, $wp_the_query, $post ) = $keep;
			// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
			return $html;
		};
		$html   = $render( $place );
		$check( 'the page shows the adoption, with the organization', false !== strpos( $html, 'Adopted by' ) && false !== strpos( $html, 'Corridor Test Crew' ) && false !== strpos( $html, 'April 18, 2026' ), substr( wp_strip_all_tags( $html ), 0, 300 ) );
		$check( 'and says what adoption is not', false !== strpos( $html, 'transfers no ownership' ) );
		$check( 'the history, with its figures', false !== strpos( $html, 'Work recorded here' ) && false !== strpos( $html, '4 bags measured' ) && false !== strpos( $html, 'Verified' ) );
		$check( 'the open Spots and the sponsor', false !== strpos( $html, 'Open Spots' ) && false !== strpos( $html, get_the_title( $coarse ) ) && false !== strpos( $html, 'Test Tire Shop' ) );
		$check( 'a map with the outline', false !== strpos( $html, 'data-pos-map' ) && false !== strpos( $html, '&quot;place&quot;:' . $place ) );
		$check( 'the exact pin of the coarse Spot is nowhere on the page', false === strpos( $html, '34.4212' ) && false === strpos( $html, '-112.6071' ) );
		$check( 'no person is named', false === strpos( $html, get_userdata( $lead )->display_name ) && false === strpos( $html, get_userdata( $reporter )->display_name ) && false === strpos( $html, get_userdata( $coordinator )->display_name ) );
		Places::save( $place, array( 'listed' => false ), $coordinator );
		$check( 'the page of a Place that is not listed is empty', '' === $render( $place, 'private' ) );
		Places::save( $place, array( 'listed' => true ), $coordinator );

		WP_CLI::log( 'Export' );
		$check( 'a lead cannot export', is_wp_error( Export::file( 'spots_internal', array(), $lead ) ) && is_wp_error( Export::file( 'spots_public', array(), $lead ) ) );
		$check( 'an unknown file is refused', is_wp_error( Export::file( 'everything', array(), $coordinator ) ) );
		$internal = Export::spots( array( 'place' => $place ), true );
		$outward  = Export::spots( array( 'place' => $place ), false );
		$feature  = static function ( array $collection, int $id ) {
			foreach ( $collection['features'] as $row ) {
				if ( $row['id'] === $id ) {
					return $row;
				}
			}
			return null;
		};
		$check( 'the internal file has every Spot of the Place, reviewed or waiting', 5 === count( $internal['features'] ) && null !== $feature( $internal, $waiting ), count( $internal['features'] ) );
		$check( 'the public file has the published ones only', 4 === count( $outward['features'] ) && null === $feature( $outward, $waiting ), count( $outward['features'] ) );
		$check( 'the internal file contains the exact pin', array( -112.6071, 34.4212 ) === $feature( $internal, $coarse )['geometry']['coordinates'], $feature( $internal, $coarse )['geometry'] );
		$coarse_point = Geo::coarse( 34.4212, -112.6071 );
		$check( 'the public file contains the coarse location for the same Spot', array( $coarse_point[1], $coarse_point[0] ) === $feature( $outward, $coarse )['geometry']['coordinates'] && false === strpos( (string) wp_json_encode( $outward ), '34.4212' ), $feature( $outward, $coarse )['geometry'] );
		$check( 'a Spot with no pin is in the file with no geometry', null === $feature( $internal, $landmark )['geometry'] && null === $feature( $outward, $landmark )['geometry'] );
		$check( 'the internal file carries the clearance, and the public file does not', false !== strpos( $feature( $internal, $landmark )['properties']['clearance_reference'], 'Grazing permittee' ) && ! isset( $feature( $outward, $landmark )['properties']['clearance_reference'] ) && false === strpos( (string) wp_json_encode( $outward ), 'Grazing permittee' ) );
		$check( 'neither file names the reporter', false === strpos( (string) wp_json_encode( $internal ), get_userdata( $reporter )->user_login ) && ! isset( $feature( $internal, $in_a )['properties']['reporter'] ) );
		$check( 'both name the Place', 'Test Road 9713 Corridor' === $feature( $internal, $in_a )['properties']['place'] && 'Test Road 9713 Corridor' === $feature( $outward, $in_a )['properties']['place'] );
		$all = Export::spots( array(), true );
		$check( 'without a Place, the file has Spots of every Place and of none', null !== $feature( $all, $outside ) && null !== $feature( $all, $elsewhere ) && null !== $feature( $all, $in_a ) );
		$check(
			'a date range before any of them is empty',
			array() === Export::spots(
				array(
					'from' => '2001-01-01',
					'to'   => '2001-12-31',
				),
				true
			)['features']
		);
		$today = wp_date( 'Y-m-d' );
		$check(
			'a date range of today has them, whichever way round it is typed',
			null !== $feature(
				Export::spots(
					array(
						'from'  => $today,
						'to'    => $today,
						'place' => $place,
					),
					true
				),
				$in_a
			) && null !== $feature(
				Export::spots(
					array(
						'from'  => '2099-01-01',
						'to'    => $today,
						'place' => $place,
					),
					true
				),
				$in_a
			)
		);

		$rows   = Export::impact( array( 'place' => $place ) );
		$header = array_flip( $rows[0] );
		$check( 'the impact file has one row for the Place\'s record', 2 === count( $rows ) && (string) $record === $rows[1][ $header['record'] ], $rows );
		$check( 'with verification, the public count, and the source of each figure', 'verified' === $rows[1][ $header['verification'] ] && 'yes' === $rows[1][ $header['counted_publicly'] ] && 'measured' === $rows[1][ $header['bags_source'] ] && 'self_reported' === $rows[1][ $header['pounds_source'] ] && '4' === $rows[1][ $header['bags'] ], $rows[1] );
		$check( 'and the Place and the organization, with no person', 'Test Road 9713 Corridor' === $rows[1][ $header['place'] ] && 'Corridor Test Crew' === $rows[1][ $header['organization'] ] && ! isset( $header['person'] ) && ! isset( $header['narrative'] ) );
		$check( 'the other Place has no rows', 1 === count( Export::impact( array( 'place' => $other ) ) ) );
		$check(
			'a date range before the record has no rows',
			1 === count(
				Export::impact(
					array(
						'place' => $place,
						'from'  => '2001-01-01',
						'to'    => '2001-12-31',
					)
				)
			)
		);
		$csv = Export::csv( array( array( 'a', 'b' ), array( '=HYPERLINK("x")', 'say "hi"' ), array( '-5', '@home' ) ) );
		$check( 'a cell a spreadsheet would run is written as text', false !== strpos( $csv, "\"'=HYPERLINK(\"\"x\"\")\"" ) && false !== strpos( $csv, '"say ""hi"""' ) && false !== strpos( $csv, '"-5"' ) && false !== strpos( $csv, "\"'@home\"" ), $csv );
		$file = Export::file( 'spots_public', array( 'place' => $place ), $coordinator );
		$check( 'the coordinator gets a GeoJSON file named for the Place', ! is_wp_error( $file ) && false !== strpos( $file['name'], 'packout-spots-public-test-road-9713-corridor' ) && '.geojson' === substr( $file['name'], -8 ) && 'FeatureCollection' === json_decode( $file['body'], true )['type'], is_wp_error( $file ) ? $file->get_error_message() : $file['name'] );
		$file = Export::file( 'impact', array(), $coordinator );
		$check( 'and a CSV of impact records', ! is_wp_error( $file ) && '.csv' === substr( $file['name'], -4 ) && 0 === strpos( $file['body'], '"record","kind","date"' ) );

		WP_CLI::log( 'Claims' );
		// The map names the source of its Forest Service layers. That credit is not a claim that this project is their program.
		$prose = preg_replace( '/ data-pos-map="[^"]*"/', '', $html );
		$copy  = strtolower( $prose . $list . wp_json_encode( $public ) . wp_json_encode( Spots::payload( Spots::get( $landmark ), 'public' ) ) . Schema::hazard_guidance( 'needs_authorization' ) . Schema::hazard_guidance( 'do_not_touch' ) );
		$check( 'nothing the plugin writes calls the project an official program or names an agency as a partner', false === strpos( $copy, 'usfs' ) && false === strpos( $copy, 'blm' ) && false === strpos( $copy, 'forest service' ) && false === strpos( $copy, 'official program' ) && false === strpos( $copy, 'endorse' ) );

		WP_CLI::log( 'Removing a Place' );
		$check( 'a lead cannot remove a Place', is_wp_error( Places::remove( $place, $lead ) ) && null !== Places::get( $place ) );
		$check( 'the coordinator can, and its Spots stay and belong to no Place', true === Places::remove( $place, $coordinator ) && null === Places::get( $place ) && null === Places::of_spot( $in_a ) && null !== Spots::get( $in_a ) && 'publish' === get_post_status( $in_a ) );
		$check( 'the organization no longer has it', array() === Places::of_team( $team ) );
		$check( 'the public is no longer told of it', null === Spots::payload( Spots::get( $in_a ), 'public' )['place'] && ! in_array( $place, wp_list_pluck( Places::outlines(), 'id' ), true ) );
	} else {
		$skip( 'Places are kept on packout.spot, with their clearances, sponsors, and export. A partner site’s own checks cover clearing a Spot and the export.' );
	}
} catch ( \Throwable $error ) {
	$packout_failures[] = 'The run stopped: ' . $error->getMessage();
	WP_CLI::log( '  FAIL  ' . $error->getMessage() . ' at ' . basename( $error->getFile() ) . ':' . $error->getLine() );
} finally {
	wp_set_current_user( 0 );
	remove_action( 'packout_place_adopted', $on_adopted );
	remove_action( 'packout_spot_cleared', $on_cleared );

	$ids     = array_filter( $packout_posts, 'is_int' );
	$records = $ids ? get_posts(
		array(
			'post_type'   => Schema::IMPACT,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Cleanup of this run's records.
			'meta_query'  => array(
				array(
					'key'     => '_pos_spot',
					'value'   => $ids,
					'compare' => 'IN',
				),
			),
		)
	) : array();
	foreach ( array_merge( $records, $ids ) as $post_id ) {
		foreach ( get_children(
			array(
				'post_parent' => $post_id,
				'post_type'   => 'attachment',
				'fields'      => 'ids',
			)
		) as $media ) {
			wp_delete_attachment( $media, true );
		}
		wp_delete_post( (int) $post_id, true );
	}
	foreach ( $packout_files as $path ) {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
	Teams::flush();
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $packout_users as $id ) {
		is_multisite() ? wpmu_delete_user( $id ) : wp_delete_user( $id );
	}
}

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( count( $packout_failures ) . ' failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
