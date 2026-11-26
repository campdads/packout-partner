<?php
/**
 * Phase 3 acceptance checks for impact records, verification, and return visits.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-3.php
 *
 * The script creates its own users, Spots, an organization, missions, records, and
 * photos, exercises the REST routes as those users, and removes everything it
 * created. It exits non-zero when a check fails.
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Blocks;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Photos;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Settings;
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
$packout_line     = Settings::get( 'celebration' );

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

// A small real JPEG, as a phone would send after shrinking a photo.
$photo = static function () use ( &$packout_files ): array {
	$path  = wp_tempnam( 'pos-after.jpg' );
	$image = imagecreatetruecolor( 64, 48 );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 65, 88, 64 ) );
	imagejpeg( $image, $path, 80 );
	$packout_files[] = $path;
	return array(
		'name'     => 'after.jpg',
		'type'     => 'image/jpeg',
		'tmp_name' => $path,
		'error'    => 0,
		'size'     => filesize( $path ),
	);
};

$stage  = static fn( int $spot ) => Spots::field( $spot, 'status', 'spotted' );
$uuid   = static fn() => wp_generate_uuid4();
$listen = static function ( $id, $state ) use ( &$packout_fired ): void {
	$packout_fired[] = array( (int) $id, (string) $state );
};
add_action( 'packout_impact_recorded', $listen, 10, 2 );

try {
	$coordinator = $user( 'pos_coordinator' );
	$lead        = $user( 'pos_lead' );
	$other_lead  = $user( 'pos_lead' );
	$crew        = $user( 'pos_crew' );
	$loner       = $user( 'pos_reporter' );
	$visitor     = $user( 'pos_reporter' );

	$spot    = static function ( string $what, float $lat, int $author, bool $publish = true ) use ( &$packout_posts ): int {
		$id              = Spots::create(
			array(
				'category'    => 'cleanup-waste',
				'description' => $what,
				'lat'         => $lat,
				'lng'         => -111.65,
			),
			$author
		);
		$packout_posts[] = $id;
		if ( $publish ) {
			Spots::publish( $id );
		}
		return $id;
	};
	$mission = static function ( array $fields, int $by ) use ( &$packout_posts ) {
		$saved = Missions::save( 0, $fields, $by );
		if ( ! is_wp_error( $saved ) ) {
			$packout_posts[] = $saved;
		}
		return $saved;
	};

	$s1      = $spot( 'Tires and construction debris.', 35.21, $visitor );
	$s2      = $spot( 'Fire ring full of cans.', 35.22, $visitor );
	$own     = $spot( 'Cans at my own campsite.', 35.23, $loner );
	$flagged = $spot( 'Sealed drums.', 35.24, $loner );
	$hidden  = $spot( 'Not reviewed yet.', 35.25, $loner, false );
	$open    = $spot( 'Still there.', 35.26, $loner );
	Spots::set_hazard( $flagged, 'do_not_touch' );

	$team            = Teams::save(
		0,
		array(
			'name'   => 'Impact Test Crew',
			'lead'   => $lead,
			'listed' => true,
		)
	);
	$packout_posts[] = $team;
	Teams::add_member( $team, get_userdata( $crew )->user_login );

	$baseline = Impact::totals();
	$public0  = Impact::totals( array( 'visible' => true ) );
	$spots0   = (int) wp_count_posts( Schema::SPOT )->publish;

	WP_CLI::log( 'Install' );
	$type = get_post_type_object( Schema::IMPACT );
	$check( 'the impact record type is registered and has no public page', $type && ! $type->public && ! $type->publicly_queryable );
	$check( 'a lead and a coordinator can review impact', Impact::can_review( $lead ) && Impact::can_review( $coordinator ) && Impact::coordinates( $coordinator ) && ! Impact::coordinates( $lead ) );
	$check( 'a reporter cannot', ! Impact::can_review( $loner ) );

	WP_CLI::log( 'A mission cleanup' );
	$as( $lead );
	$m = $mission(
		array(
			'title' => 'Impact test mission',
			'team'  => $team,
			'spots' => array( $s1, $s2 ),
		),
		$lead
	);
	$as( $crew );
	$call(
		'PUT',
		'/missions/' . $m . '/progress',
		array(
			'revision' => Missions::revision( $m ),
			'outcomes' => array(
				$s1 => 'cleaned',
				$s2 => 'cleaned',
			),
		)
	);
	$as( $lead );
	$call( 'POST', '/missions/' . $m . '/status', array( 'status' => 'completed' ) );
	$check( 'the mission is completed with two cleaned Spots', 'completed' === Missions::status( $m ) && 'cleaned' === $stage( $s1 ) && 'cleaned' === $stage( $s2 ) );
	$check( 'both are listed as cleaned with no record yet', 2 === count( array_filter( Impact::unrecorded( $lead ), static fn( $post ) => in_array( $post->ID, array( $s1, $s2 ), true ) ) ) );

	$as( $crew );
	list( $code ) = $call(
		'PUT',
		'/impact/client/' . $uuid(),
		array(
			'spot'   => $s1,
			'pounds' => 999,
		)
	);
	$check( 'a crew member cannot record the mission\'s impact', 403 === $code && ! Impact::current( $s1 ) );
	$check( 'nor can the lead of another organization', is_wp_error( Impact::save_cleanup( $s1, array( 'pounds' => 999 ), $other_lead ) ) && ! Impact::current( $s1 ) );

	$record = Impact::save_cleanup(
		$s1,
		array(
			'narrative'      => 'Hauled six tires and a truck bed of debris.',
			'hours'          => 12,
			'people'         => 4,
			'persons'        => array( $crew, $visitor ),
			'bags'           => 6,
			'bags_source'    => 'self_reported',
			'pounds'         => 180,
			'pounds_source'  => 'measured',
			'come_back'      => true,
			'come_back_note' => 'One tire is still wedged in the wash.',
		),
		$lead
	);
	$check( 'the lead records the work with weight', is_int( $record ) && $record > 0, is_wp_error( $record ) ? $record->get_error_message() : '' );
	$added = Impact::add_photo( $record, $photo(), $lead, 'after-1' );
	$again = Impact::add_photo( $record, $photo(), $lead, 'after-1' );
	$check( 'and attaches a photo taken afterward, once', is_int( $added ) && $added === $again && 1 === count( Photos::for_spot( $record ) ), is_wp_error( $added ) ? $added->get_error_message() : '' );
	$row = Impact::payload( Impact::get( $record ) );
	$check( 'the record is tied to that Spot, mission, and organization', $row['spot'] === $s1 && $row['mission'] === $m && $row['team'] === $team, $row );
	$check( 'it holds the figures and where each came from', 12.0 === $row['hours'] && 4 === $row['people'] && 6 === $row['bags'] && 'self_reported' === $row['bags_source'] && 180.0 === $row['pounds'] && 'measured' === $row['pounds_source'] && $row['come_back'], $row );
	$check( 'the people named are members of the organization only', array( (string) $crew ) === array_map( 'strval', get_post_meta( $record, '_pos_person', false ) ) );
	$check( 'it starts unverified and out of the public totals', 'unverified' === $row['verification'] && ! $row['visible'] );
	$check( 'saving it announces the record once, as unverified', array( array( $record, 'unverified' ) ) === $packout_fired, $packout_fired );
	$same = Impact::save_cleanup( $s1, array( 'bags' => 7 ), $lead );
	$check( 'recording the same cleaning again changes the same record', $same === $record && 7 === Impact::payload( Impact::get( $record ) )['bags'] && 180.0 === Impact::payload( Impact::get( $record ) )['pounds'] );
	$check( 'and a later edit is not announced again', 1 === count( $packout_fired ) );
	$check( 'the Spot is no longer listed as unrecorded', ! array_filter( Impact::unrecorded( $lead ), static fn( $post ) => $post->ID === $s1 ) && 1 === count( array_filter( Impact::unrecorded( $lead ), static fn( $post ) => $post->ID === $s2 ) ) );

	$as( $lead );
	list( , $data ) = $call( 'GET', '/missions' );
	$mine           = current( array_filter( $data['missions'], static fn( $item ) => $item['id'] === $m ) );
	$first          = current( array_filter( $mine['spots'], static fn( $item ) => $item['id'] === $s1 ) );
	$check( 'the lead\'s field app is given the record', true === $first['record'] && is_array( $first['impact'] ) && $first['impact']['id'] === $record );
	$as( $crew );
	list( , $data ) = $call( 'GET', '/missions' );
	$mine           = current( array_filter( $data['missions'], static fn( $item ) => $item['id'] === $m ) );
	$first          = current( array_filter( $mine['spots'], static fn( $item ) => $item['id'] === $s1 ) );
	$check( 'a crew member\'s is not', false === $first['record'] && null === $first['impact'] );

	WP_CLI::log( 'A cleanup with no mission' );
	$as( $loner );
	$client              = $uuid();
	list( $code, $data ) = $call(
		'PUT',
		'/impact/client/' . $client,
		array(
			'spot'      => $own,
			'narrative' => 'Packed out two bags.',
			'hours'     => 2,
			'people'    => 1,
			'bags'      => 2,
			'pounds'    => 50,
		)
	);
	$solo                = $data['impact']['id'] ?? 0;
	$check( 'a reporter records their own cleanup, which marks the Spot cleaned', 201 === $code && $solo > 0 && 'cleaned' === $stage( $own ), $data );
	$check( 'the record has no mission and no organization, and is unverified', 0 === $data['impact']['mission'] && 0 === $data['impact']['team'] && 'unverified' === $data['impact']['verification'] );
	$check( 'an unstated source is self-reported', 'self_reported' === $data['impact']['pounds_source'] && 'self_reported' === $data['impact']['bags_source'] );
	$check( 'the answer carries the line to show', is_string( $data['line'] ) && '' !== $data['line'] );
	$check( 'the reporter attaches a photo taken afterward', is_int( Impact::add_photo( $solo, $photo(), $loner ) ) && 1 === Impact::payload( Impact::get( $solo ) )['photos'] );
	list( $code, $data ) = $call(
		'PUT',
		'/impact/client/' . $client,
		array(
			'spot'  => $own,
			'hours' => 3,
		)
	);
	$check( 'sending it again changes the same record', 200 === $code && $data['impact']['id'] === $solo && 3.0 === $data['impact']['hours'] && 2 === $data['impact']['bags'], $data );
	list( , $data ) = $call( 'GET', '/sync' );
	$check( 'the field app is told about the reporter\'s own records', 1 === count( $data['work'] ) && $data['work'][0]['id'] === $solo && 'unverified' === $data['work'][0]['verification'] );

	list( $code ) = $call( 'PUT', '/impact/client/' . $uuid(), array( 'spot' => $flagged ) );
	$check( 'a flagged Spot is refused and stays as it was', 409 === $code && 'spotted' === $stage( $flagged ) && ! Impact::current( $flagged ) );
	$as( $visitor );
	list( $code ) = $call( 'PUT', '/impact/client/' . $uuid(), array( 'spot' => $open ) );
	$check( 'a reporter cannot record someone else\'s Spot', 403 === $code && 'spotted' === $stage( $open ) );
	list( $code ) = $call(
		'PUT',
		'/impact/client/' . $uuid(),
		array(
			'spot'   => $own,
			'pounds' => 5000,
		)
	);
	$check( 'or change someone else\'s record', 403 === $code && 50.0 === Impact::payload( Impact::get( $solo ) )['pounds'] );

	WP_CLI::log( 'Verification' );
	$check( 'the reporter cannot verify their own record', is_wp_error( Impact::verify( $solo, $loner ) ) && ! Impact::verified( $solo ) );
	$check( 'a lead sees a record with no organization, and their own organization\'s', Impact::can_see( $solo, $lead ) && Impact::can_see( $record, $lead ) );
	$check( 'a lead does not see another organization\'s record', ! Impact::can_see( $record, $other_lead ) && is_wp_error( Impact::verify( $record, $other_lead ) ) );
	$fired = count( $packout_fired );
	$check( 'a lead verifies the reporter\'s record', true === Impact::verify( $solo, $other_lead ) && Impact::verified( $solo ) );
	$row = Impact::payload( Impact::get( $solo ) );
	$check( 'the record says who verified it, and that they were not part of the work', get_userdata( $other_lead )->display_name === $row['verified_by'] && '' !== $row['verified_at'] && false === $row['own_work'], $row );
	$check( 'verifying announces the record again, as verified', count( $packout_fired ) === $fired + 1 && array( $solo, 'verified' ) === end( $packout_fired ) );
	$check( 'verifying twice changes nothing', true === Impact::verify( $solo, $other_lead ) && count( $packout_fired ) === $fired + 1 );
	$as( $loner );
	list( $code ) = $call(
		'PUT',
		'/impact/client/' . $client,
		array(
			'spot'   => $own,
			'pounds' => 500,
		)
	);
	$check( 'a verified record is closed to the reporter', 409 === $code && 50.0 === Impact::payload( Impact::get( $solo ) )['pounds'] );
	$check( 'a lead may verify their own crew\'s record, and the record says so', true === Impact::verify( $record, $lead ) && true === Impact::payload( Impact::get( $record ) )['own_work'] );
	$check( 'a coordinator can return it to unverified, which is announced', true === Impact::unverify( $record, $coordinator ) && ! Impact::verified( $record ) && array( $record, 'unverified' ) === end( $packout_fired ) );
	Impact::verify( $record, $coordinator );
	$check( 'a coordinator who was not there verifies it', Impact::verified( $record ) && false === Impact::payload( Impact::get( $record ) )['own_work'] );

	WP_CLI::log( 'Totals' );
	$all = Impact::totals();
	$check( 'measured and self-reported weight are separate sums', 180.0 === (float) $all['outputs']['pounds_measured'] - (float) $baseline['outputs']['pounds_measured'] && 50.0 === (float) $all['outputs']['pounds_self_reported'] - (float) $baseline['outputs']['pounds_self_reported'], $all['outputs'] );
	$check( 'bags are summed by source too', 9 === (int) $all['outputs']['bags_self_reported'] - (int) $baseline['outputs']['bags_self_reported'] && (int) $all['outputs']['bags_measured'] === (int) $baseline['outputs']['bags_measured'], $all['outputs'] );
	$check( 'effort counts hours, people, the completed mission, and the organization', 15.0 === (float) $all['effort']['hours_verified'] - (float) $baseline['effort']['hours_verified'] && 5 === (int) $all['effort']['people'] - (int) $baseline['effort']['people'] && 1 === (int) $all['effort']['missions'] - (int) $baseline['effort']['missions'] && 1 === (int) $all['effort']['teams'] - (int) $baseline['effort']['teams'], $all['effort'] );
	$check( 'two Spots count as cleaned', 2 === (int) $all['outputs']['spots'] - (int) $baseline['outputs']['spots'] );
	$of_team = Impact::totals( array( 'team' => $team ) );
	$check( 'an organization\'s totals hold only its own records', 180.0 === (float) $of_team['outputs']['pounds_measured'] && 0.0 === (float) $of_team['outputs']['pounds_self_reported'] && 1 === (int) $of_team['outputs']['spots'], $of_team );
	$past = Impact::totals(
		array(
			'from' => '2001-01-01',
			'to'   => '2001-12-31',
		)
	);
	$check( 'a date range with no work has no totals', 0 === (int) $past['outputs']['spots'] && 0.0 === (float) $past['effort']['hours_verified'] );
	$check( 'the public totals count nothing a coordinator has not marked', Impact::totals( array( 'visible' => true ) ) === $public0 );
	$check( 'a lead cannot mark a record public', is_wp_error( Impact::set_visible( $record, true, $lead ) ) && ! Impact::visible( $record ) );
	$check( 'a coordinator can', true === Impact::set_visible( $record, true, $coordinator ) && Impact::visible( $record ) );
	$shown = Impact::totals( array( 'visible' => true ) );
	$check( 'the public totals then hold that record and not the other', 180.0 === (float) $shown['outputs']['pounds_measured'] - (float) $public0['outputs']['pounds_measured'] && (float) $shown['outputs']['pounds_self_reported'] === (float) $public0['outputs']['pounds_self_reported'] && 1 === (int) $shown['outputs']['spots'] - (int) $public0['outputs']['spots'], $shown );

	WP_CLI::log( 'The public block and the line' );
	Settings::save( array( 'celebration' => 'public' ) );
	$html = Blocks::render_impact( array( 'showLine' => true ) );
	$check( 'the Impact summary shows the public line and the figures, by source', false !== strpos( $html, 'Spot it. Pack it out. Leave it better.' ) && false !== strpos( $html, 'Pounds, measured' ) && false !== strpos( $html, 'Pounds, self-reported' ) && false !== strpos( $html, '>180<' ), $html );
	$check( 'and no names, narrative, or photos', false === strpos( $html, 'Hauled six tires' ) && false === strpos( $html, '<img' ) && false === strpos( $html, get_userdata( $lead )->display_name ) );
	Settings::save( array( 'celebration' => 'pos' ) );
	$check( 'turning the setting on shows the POS line in the block and in the app', false !== strpos( Blocks::render_impact( array( 'showLine' => true ) ), 'One Less POS on the Trail.' ) && 'One Less POS on the Trail.' === Settings::celebration_line() );
	Settings::save( array( 'celebration' => 'public' ) );
	$check( 'turning it off removes the POS line from both', false === strpos( Blocks::render_impact( array( 'showLine' => true ) ), 'POS' ) && 'Spot it. Pack it out. Leave it better.' === Settings::celebration_line() );
	$check( 'the block can leave the line out', false === strpos( Blocks::render_impact( array( 'showLine' => false ) ), 'pos-impact__line' ) );

	WP_CLI::log( 'Return visits' );
	$as( $visitor );
	list( $code ) = $call(
		'PUT',
		'/impact/client/' . $uuid(),
		array(
			'spot' => $s1,
			'kind' => 'still_clear',
		)
	);
	$check( 'a return visit needs a description', 400 === $code );
	list( $code ) = $call(
		'PUT',
		'/impact/client/' . $uuid(),
		array(
			'spot'      => $open,
			'kind'      => 'returned',
			'narrative' => 'Never cleaned.',
		)
	);
	$check( 'a return visit is for a cleaned Spot', 409 === $code && 'spotted' === $stage( $open ) );
	list( $code ) = $call(
		'PUT',
		'/impact/client/' . $uuid(),
		array(
			'spot'      => $hidden,
			'kind'      => 'returned',
			'narrative' => 'Cannot see this one.',
		)
	);
	$check( 'and for a Spot the visitor can see', 404 === $code );
	list( $code, $data ) = $call(
		'PUT',
		'/impact/client/' . $uuid(),
		array(
			'spot'      => $s1,
			'kind'      => 'still_clear',
			'narrative' => 'Drove past in March. Still clean.',
		)
	);
	$clear               = $data['impact']['id'] ?? 0;
	$check( 'any reporter can say a cleaned Spot is still clear', 201 === $code && $clear > 0 && 'still_clear' === $data['impact']['kind'], $data );
	$check( 'the Spot stays cleaned and no new Spot is created', 'cleaned' === $stage( $s1 ) && (int) wp_count_posts( Schema::SPOT )->publish === $spots0 );
	$check( 'the visit carries its own photo', is_int( Impact::add_photo( $clear, $photo(), $visitor ) ) && 1 === count( Photos::for_spot( $clear ) ) && 1 === count( Photos::for_spot( $record ) ) );
	$after = Impact::totals();
	$check( 'the still-clear count goes up by one Spot', 1 === (int) $after['outcomes']['still_clear'] - (int) $baseline['outcomes']['still_clear'] && (int) $after['outcomes']['returned'] === (int) $baseline['outcomes']['returned'] );
	$check( 'and the cleanup figures do not move', $after['outputs'] === $all['outputs'] && $after['effort'] === $all['effort'] );
	$check( 'the public totals count the visit because the Spot is on the map', 1 === (int) Impact::totals( array( 'visible' => true ) )['outcomes']['still_clear'] - (int) $public0['outcomes']['still_clear'] );

	$as( $loner );
	$back                = $uuid();
	list( $code, $data ) = $call(
		'PUT',
		'/impact/client/' . $back,
		array(
			'spot'      => $s1,
			'kind'      => 'returned',
			'narrative' => 'Someone dumped a couch.',
		)
	);
	$returned            = $data['impact']['id'] ?? 0;
	$check( 'a visit of "it\'s back" sets the Spot to watch', 201 === $code && 'watch' === $stage( $s1 ) && 'watch' === Spots::waiting_status( $s1 ), $data );
	$check( 'and leaves the earlier pounds on the old record', 180.0 === Impact::payload( Impact::get( $record ) )['pounds'] && Impact::verified( $record ) && 3 === count( Impact::of_spot( $s1 ) ) );
	$check( 'the returned count goes up and nothing else changes', 1 === (int) Impact::totals()['outcomes']['returned'] - (int) $baseline['outcomes']['returned'] && Impact::totals()['outputs'] === $all['outputs'] );
	list( $code, $data ) = $call(
		'PUT',
		'/impact/client/' . $back,
		array(
			'spot'      => $s1,
			'kind'      => 'returned',
			'narrative' => 'Someone dumped a couch.',
		)
	);
	$check( 'sending the same visit again is the same record', 200 === $code && $data['impact']['id'] === $returned && 3 === count( Impact::of_spot( $s1 ) ) );
	$as( $visitor );
	list( $code ) = $call(
		'PUT',
		'/impact/client/' . $uuid(),
		array(
			'spot'      => $s1,
			'kind'      => 'still_clear',
			'narrative' => 'Looks fine to me.',
		)
	);
	$check( 'a Spot on watch takes no more visits until it is cleaned again', 409 === $code && 3 === count( Impact::of_spot( $s1 ) ) );
	$check( 'visits belong to the organization whose cleanup they follow', (int) Impact::field( $returned, 'team' ) === $team && 1 === (int) Impact::totals( array( 'team' => $team ) )['outcomes']['returned'] );

	WP_CLI::log( 'A Spot that came back' );
	$as( $lead );
	$m2 = $mission(
		array(
			'title' => 'Impact test mission, second visit',
			'team'  => $team,
			'spots' => array( $s1 ),
		),
		$lead
	);
	$check( 'it can go on a new mission, which plans it', is_int( $m2 ) && 'planned' === $stage( $s1 ) );
	Missions::set_status( $m2, 'called_off' );
	$check( 'calling the mission off returns it to watch, not to spotted', 'watch' === $stage( $s1 ) );
	$m3 = $mission(
		array(
			'title' => 'Impact test mission, third visit',
			'team'  => $team,
			'spots' => array( $s1 ),
		),
		$lead
	);
	// One cleaning is told from the next by the second it was recorded. A fast site reaches this line in the same one.
	sleep( 1 );
	Missions::progress(
		$m3,
		$lead,
		array(
			'revision' => Missions::revision( $m3 ),
			'outcomes' => array( $s1 => 'cleaned' ),
		)
	);
	Impact::flush();
	$check( 'cleaned again, it is a new cleaning with no record yet', 'cleaned' === $stage( $s1 ) && 'spotted' === Spots::waiting_status( $s1 ) && null === Impact::current( $s1 ) );
	$second = Impact::save_cleanup( $s1, array( 'pounds' => 40 ), $lead );
	$check( 'the second cleaning gets its own record', is_int( $second ) && $second !== $record && (int) Impact::field( $second, 'mission' ) === $m3 );
	$check( 'and the first record still holds its own figures', 180.0 === Impact::payload( Impact::get( $record ) )['pounds'] && 40.0 === Impact::payload( Impact::get( $second ) )['pounds'] && 4 === count( Impact::of_spot( $s1 ) ) );
	$check( 'the older record can still be corrected on its own', Impact::update( $record, array( 'bags' => 8 ), $coordinator ) === $record && 8 === Impact::payload( Impact::get( $record ) )['bags'] && 0 === Impact::payload( Impact::get( $second ) )['bags'] );
} finally {
	wp_set_current_user( 0 );
	remove_action( 'packout_impact_recorded', $listen, 10 );
	Settings::save( array( 'celebration' => $packout_line ) );

	$spots   = array_filter( $packout_posts, 'is_int' );
	$records = $spots ? get_posts(
		array(
			'post_type'   => Schema::IMPACT,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Cleanup of this run's records.
			'meta_query'  => array(
				array(
					'key'     => '_pos_spot',
					'value'   => $spots,
					'compare' => 'IN',
				),
			),
		)
	) : array();
	foreach ( $records as $record_id ) {
		foreach ( get_children(
			array(
				'post_parent' => $record_id,
				'post_type'   => 'attachment',
				'fields'      => 'ids',
			)
		) as $media ) {
			wp_delete_attachment( $media, true );
		}
		wp_delete_post( $record_id, true );
	}
	foreach ( $spots as $post ) {
		wp_delete_post( $post, true );
	}
	foreach ( $packout_files as $path ) {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
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
