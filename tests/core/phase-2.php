<?php
/**
 * Phase 2 acceptance checks for organizations and missions.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-2.php
 *
 * The script creates its own users, Spots, organizations, and missions, exercises the
 * REST routes as those users, and removes everything it created. It exits
 * non-zero when a check fails.
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Teams;
use PackOut\Core\App\Post_Access;

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
$packout_fired    = array(
	'opened'    => 0,
	'completed' => 0,
);

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

$stage = static fn( int $spot ) => Spots::field( $spot, 'status', 'spotted' );

$count_opened    = static function () use ( &$packout_fired ): void {
	++$packout_fired['opened'];
};
$count_completed = static function () use ( &$packout_fired ): void {
	++$packout_fired['completed'];
};
add_action( 'packout_mission_opened', $count_opened );
add_action( 'packout_mission_completed', $count_completed );

try {
	$coordinator = $user( 'pos_coordinator' );
	$lead        = $user( 'pos_lead' );
	$other_lead  = $user( 'pos_lead' );
	$crew_a      = $user( 'pos_crew' );
	$crew_b      = $user( 'pos_reporter' );
	$newcomer    = $user( 'subscriber' );
	$loner       = $user( 'pos_reporter' );
	$outsider    = $user( 'pos_reporter' );

	// A reviewed Spot, as a coordinator would leave it.
	$spot    = static function ( string $what, float $lat, float $lng, int $author, bool $publish = true ) use ( &$packout_posts ): int {
		$id              = Spots::create(
			array(
				'category'    => 'cleanup-waste',
				'description' => $what,
				'lat'         => $lat,
				'lng'         => $lng,
			),
			$author
		);
		$packout_posts[] = $id;
		if ( $publish ) {
			Spots::publish( $id );
		}
		return $id;
	};
	$mission = static function ( array $fields, int $by, int $id = 0 ) use ( &$packout_posts ) {
		$saved = Missions::save( $id, $fields, $by );
		if ( ! is_wp_error( $saved ) ) {
			$packout_posts[] = $saved;
		}
		return $saved;
	};

	$s1 = $spot( 'Fire ring full of cans.', 35.2001, -111.6501, $loner );
	$s2 = $spot( 'Dumped tires at the turnout.', 35.2102, -111.6402, $loner );
	$s3 = $spot( 'Trash scattered near a sensitive site.', 35.2203, -111.6303, $loner );
	$s4 = $spot( 'Sealed drums behind the corral.', 35.2304, -111.6204, $loner );
	Spots::set_precision( $s3, 'coarse' );
	Spots::set_hazard( $s4, 'do_not_touch' );
	$pending = $spot( 'Not reviewed yet.', 35.3, -111.7, $loner, false );

	WP_CLI::log( 'Install' );
	$check( 'mission and organization post types are registered', post_type_exists( Schema::MISSION ) && post_type_exists( Schema::TEAM ) );
	$check( 'a lead and a coordinator can open the Pack-Out Spot menu', user_can( $lead, Capabilities::SCREENS ) && user_can( $coordinator, Capabilities::SCREENS ) );
	$check( 'a reporter cannot', ! user_can( $loner, Capabilities::SCREENS ) );

	WP_CLI::log( 'Organizations' );
	$as( $lead );
	$check( 'a lead cannot create an organization', ! Teams::can_edit() );
	$as( $coordinator );
	$refused = Teams::save(
		0,
		array(
			'name' => 'Test Crew',
			'lead' => $loner,
		)
	);
	$check( 'an organization needs a lead who may lead missions', is_wp_error( $refused ) && 'packout_team_lead' === $refused->get_error_code() );
	$team            = Teams::save(
		0,
		array(
			'name'        => 'Test Trail Crew',
			'description' => 'A crew for the acceptance checks.',
			'lead'        => $lead,
		)
	);
	$packout_posts[] = $team;
	$check( 'a coordinator creates an organization with a lead', is_int( $team ) && Teams::lead( $team ) === $lead && 'publish' === get_post_status( $team ) );

	$check( 'the lead adds a crew member by username', Teams::add_member( $team, get_userdata( $crew_a )->user_login ) === $crew_a );
	$check( 'and one by email address', Teams::add_member( $team, get_userdata( $crew_b )->user_email ) === $crew_b );
	$check( 'a person who could not report is given the Crew role', Teams::add_member( $team, get_userdata( $newcomer )->user_login ) === $newcomer && user_can( $newcomer, Capabilities::REPORT ) && in_array( 'subscriber', get_userdata( $newcomer )->roles, true ) );
	$again = Teams::add_member( $team, get_userdata( $crew_a )->user_login );
	$check( 'the same person is not added twice', is_wp_error( $again ) && 3 === count( Teams::members( $team ) ) );
	$check( 'an unknown person is refused', is_wp_error( Teams::add_member( $team, 'nobody-' . wp_generate_password( 8, false ) ) ) );
	$check( 'the lead manages members of their own organization', Teams::can_manage_members( $team, $lead ) );
	$check( 'another lead does not', ! Teams::can_manage_members( $team, $other_lead ) );

	WP_CLI::log( 'Planning' );
	$plan = array(
		'title'        => 'Schultz Pass cleanup',
		'objective'    => 'Every fire ring on the pass is empty and the turnout is clear.',
		'team'         => $team,
		'spots'        => array( $s1, $s2, $s3, $s4 ),
		'start'        => '2027-05-08',
		'end'          => '2027-05-09',
		'meeting_note' => 'Gravel lot at the Schultz Tank trailhead',
		'meeting_lat'  => 35.2850,
		'meeting_lng'  => -111.6370,
		'gear'         => 'Gloves, grabbers, a truck for the tires.',
		'new_lines'    => "Sign in at the truck\nSort recyclables\nWeigh the bags",
	);
	$check( 'a mission needs a title', is_wp_error( $mission( array_merge( $plan, array( 'title' => '' ) ), $lead ) ) );
	$check( 'a mission needs at least one Spot', is_wp_error( $mission( array_merge( $plan, array( 'spots' => array() ) ), $lead ) ) );
	$check( 'a Spot that has not been reviewed cannot join a mission', is_wp_error( $mission( array_merge( $plan, array( 'spots' => array( $pending ) ) ), $lead ) ) );
	$check( 'the window cannot end before it starts', is_wp_error( $mission( array_merge( $plan, array( 'end' => '2027-05-01' ) ), $lead ) ) );
	$check( 'a lead cannot plan for an organization they do not lead', is_wp_error( $mission( $plan, $other_lead ) ) );
	$check( 'a refused plan moves no Spot', 'spotted' === $stage( $s1 ) && 0 === $packout_fired['opened'] );

	$m = $mission( $plan, $lead );
	$check( 'a lead builds a mission on four Spots', is_int( $m ) && 4 === count( Missions::spots( $m ) ) && 'planned' === Missions::status( $m ), is_wp_error( $m ) ? $m->get_error_message() : '' );
	$check( 'linked Spots that were spotted become planned', array( 'planned', 'planned', 'planned', 'planned' ) === array_map( $stage, array( $s1, $s2, $s3, $s4 ) ) );
	$check( 'the checklist has the three lines, none done', 3 === count( Missions::checklist( $m ) ) && ! array_filter( wp_list_pluck( Missions::checklist( $m ), 'done' ) ) );
	$check( 'packout_mission_opened fired once', 1 === $packout_fired['opened'] );
	$check( 'a new mission is not public', 'private' === get_post_status( $m ) && ! Post_Access::can_read( get_post( $m ), 0 ) );
	$check( 'another lead cannot manage it', ! Missions::can_manage( $m, $other_lead ) && Missions::can_manage( $m, $lead ) && Missions::can_manage( $m, $coordinator ) );

	WP_CLI::log( 'What the crew sees' );
	$as( $crew_a );
	list( $code, $data ) = $call( 'GET', '/missions' );
	$mine                = $data['missions'][0] ?? array();
	$pins                = array();
	foreach ( $mine['spots'] ?? array() as $row ) {
		$pins[ $row['id'] ] = array( $row['location']['lat'], $row['location']['lng'] );
	}
	$check( 'a crew member receives the mission', 200 === $code && 1 === count( $data['missions'] ) && $m === $mine['id'] && false === $mine['can_manage'], $data );
	$check( 'with the exact pin of every Spot, including the one the public sees approximately', 4 === count( $pins ) && array( 35.2203, -111.6303 ) === $pins[ $s3 ] && array( 35.2001, -111.6501 ) === $pins[ $s1 ], $pins );
	$check( 'with the checklist, the meeting place, and its pin', 3 === count( $mine['checklist'] ) && 'Gravel lot at the Schultz Tank trailhead' === $mine['meeting']['note'] && 35.285 === $mine['meeting']['lat'] );
	$check( 'with the leave-it-in-place flag on the hazard Spot', 'do_not_touch' === current( array_filter( $mine['spots'], static fn( $row ) => $row['id'] === $s4 ) )['hazard'] );
	list( , $data ) = $call( 'GET', '/spots' );
	$coarse         = current( array_filter( $data['spots'], static fn( $row ) => $row['id'] === $s3 ) );
	$check( 'the map shows the crew the exact pin too', $coarse && 35.2203 === $coarse['location']['lat'] );

	$as( $outsider );
	list( $code, $data ) = $call( 'GET', '/missions' );
	$check( 'a reporter in no organization receives no missions', 200 === $code && array() === $data['missions'] );
	list( , $data ) = $call( 'GET', '/spots' );
	$coarse         = current( array_filter( $data['spots'], static fn( $row ) => $row['id'] === $s3 ) );
	$check( 'and still sees the approximate location', $coarse && 35.2203 !== $coarse['location']['lat'] );
	list( $code ) = $call( 'PUT', '/missions/' . $m . '/progress', array( 'outcomes' => array( $s1 => 'cleaned' ) ) );
	$check( 'and cannot record progress on another organization\'s mission', 403 === $code && 'planned' === $stage( $s1 ) );
	$as( 0 );
	list( $code ) = $call( 'GET', '/missions' );
	$check( 'a visitor is refused the mission list', 401 === $code );

	WP_CLI::log( 'Progress from the field' );
	$lines = wp_list_pluck( Missions::checklist( $m ), 'id' );
	$seen  = Missions::revision( $m );
	$as( $crew_a );
	list( $code, $data ) = $call(
		'PUT',
		'/missions/' . $m . '/progress',
		array(
			'revision' => $seen,
			'lines'    => array(
				$lines[0] => true,
				$lines[1] => true,
			),
			'outcomes' => array(
				$s1 => 'cleaned',
				$s2 => 'cleaned',
			),
		)
	);
	$check( 'a crew member ticks two lines and marks two Spots cleaned in one sync', 200 === $code && 4 === $data['applied'] && array() === $data['conflicts'] && array() === $data['refused'], $data );
	$check( 'those two Spots are cleaned', 'cleaned' === $stage( $s1 ) && 'cleaned' === $stage( $s2 ) );
	$check( 'the other two stay open', 'in_progress' === $stage( $s3 ) && 'in_progress' === $stage( $s4 ) );
	$check( 'the first record from the field starts the mission', 'in_progress' === Missions::status( $m ) && 'in_progress' === $data['mission']['status'] );
	$check( 'the Spot records who cleaned it and on which mission', (int) Spots::field( $s1, 'cleaned_by' ) === $crew_a && (int) Spots::field( $s1, 'cleaned_mission' ) === $m );
	$check( 'the answer carries the mission as the server holds it', 2 === count( array_filter( wp_list_pluck( $data['mission']['checklist'], 'done' ) ) ) && $data['mission']['revision'] > $seen );

	list( $code, $data ) = $call(
		'PUT',
		'/missions/' . $m . '/progress',
		array(
			'revision' => $seen,
			'outcomes' => array( $s4 => 'cleaned' ),
		)
	);
	$check( 'a do-not-touch Spot cannot be marked cleaned', 200 === $code && 0 === $data['applied'] && 1 === count( $data['refused'] ) && 'in_progress' === $stage( $s4 ), $data );
	$check( 'and the refusal carries the leave-it-in-place guidance', false !== strpos( $data['refused'][0]['message'] ?? '', 'Leave this material where it is' ) );
	list( , $data ) = $call(
		'PUT',
		'/missions/' . $m . '/progress',
		array(
			'revision' => Missions::revision( $m ),
			'outcomes' => array( $s3 => 'reopen' ),
		)
	);
	$check( 'a crew member cannot send a Spot back to spotted', 1 === count( $data['refused'] ) && 'in_progress' === $stage( $s3 ) );

	WP_CLI::log( 'Two phones disagree' );
	// The second phone last saw the mission before the first one synced.
	$as( $crew_b );
	list( $code, $data ) = $call(
		'PUT',
		'/missions/' . $m . '/progress',
		array(
			'revision' => $seen,
			'lines'    => array( $lines[0] => true ),
		)
	);
	$check( 'the same tick from a second phone is not a conflict', 200 === $code && array() === $data['conflicts'] );
	list( $code, $data ) = $call(
		'PUT',
		'/missions/' . $m . '/progress',
		array(
			'revision' => $seen,
			'lines'    => array(
				$lines[1] => false,
				$lines[2] => true,
			),
			'outcomes' => array( $s2 => 'open' ),
		)
	);
	$check( 'a different answer to something changed since is a conflict', 200 === $code && 2 === count( $data['conflicts'] ), $data );
	$check( 'the server keeps the earlier answers', true === Missions::checklist( $m )[1]['done'] && 'cleaned' === $stage( $s2 ) );
	$check( 'a change nobody else touched still applies', 1 === $data['applied'] && true === Missions::checklist( $m )[2]['done'] );
	$check( 'the phone\'s copy is kept for the lead', 2 === count( Missions::proposals( $m ) ) && 2 === count( $data['mission']['waiting'] ) );
	$as( $crew_a );
	list( , $data ) = $call( 'GET', '/missions' );
	$check( 'other crew members are not shown someone else\'s pending change', array() === $data['missions'][0]['waiting'] );
	$as( $lead );
	list( , $data ) = $call( 'GET', '/missions' );
	$check( 'the lead is', 2 === count( $data['missions'][0]['waiting'] ) && true === $data['missions'][0]['can_manage'] );
	$check( 'the lead keeps what is recorded for the Spot', true === Missions::decide( $m, 'spot:' . $s2, $crew_b, false, $lead ) && 'cleaned' === $stage( $s2 ) );
	$check( 'and uses the phone\'s copy for the line', true === Missions::decide( $m, 'line:' . $lines[1], $crew_b, true, $lead ) && false === Missions::checklist( $m )[1]['done'] );
	$check( 'nothing is left waiting', array() === Missions::proposals( $m ) );
	$check( 'a crew member cannot decide', is_wp_error( Missions::decide( $m, 'line:' . $lines[1], $crew_b, true, $crew_a ) ) );

	// The lead's own phone is behind too. The lead is told and chooses there; nothing waits for anyone.
	$as( $lead );
	list( $code, $data ) = $call(
		'PUT',
		'/missions/' . $m . '/progress',
		array(
			'revision' => $seen,
			'lines'    => array( $lines[2] => false ),
		)
	);
	$check( 'the lead\'s stale answer is reported as a conflict and not applied', 200 === $code && 1 === count( $data['conflicts'] ) && 0 === $data['applied'] && true === Missions::checklist( $m )[2]['done'], $data );
	$check( 'and is not stored as a proposal', array() === Missions::proposals( $m ) );
	list( $code, $data ) = $call(
		'PUT',
		'/missions/' . $m . '/progress',
		array(
			'revision' => $seen,
			'lines'    => array( $lines[2] => false ),
			'force'    => true,
		)
	);
	$check( 'the lead can choose their own copy', 200 === $code && 1 === $data['applied'] && false === Missions::checklist( $m )[2]['done'], $data );
	$as( $crew_a );
	list( $code, $data ) = $call(
		'PUT',
		'/missions/' . $m . '/progress',
		array(
			'revision' => 0,
			'lines'    => array( $lines[2] => true ),
			'force'    => true,
		)
	);
	$check( 'a crew member cannot force their copy over someone else\'s', 200 === $code && 0 === $data['applied'] && 1 === count( $data['conflicts'] ) && false === Missions::checklist( $m )[2]['done'], $data );
	Missions::decide( $m, 'line:' . $lines[2], $crew_a, false, $lead );

	WP_CLI::log( 'Finishing' );
	$as( $crew_a );
	list( $code ) = $call( 'POST', '/missions/' . $m . '/status', array( 'status' => 'completed' ) );
	$check( 'a crew member cannot complete the mission', 403 === $code && Missions::is_open( $m ) );
	$as( $lead );
	list( $code, $data ) = $call( 'POST', '/missions/' . $m . '/status', array( 'status' => 'completed' ) );
	$done                = array_keys( array_filter( Missions::recorded( $m ), static fn( $row ) => 'cleaned' === $row['outcome'] ) );
	sort( $done );
	$check( 'the lead completes it', 200 === $code && 'completed' === Missions::status( $m ), $data );
	$check( 'the done list is the two Spots that were marked cleaned', array( $s1, $s2 ) === $done );
	$check( 'completing marks nothing cleaned by itself', 'in_progress' === $stage( $s3 ) && 'in_progress' === $stage( $s4 ) );
	$check( 'the open Spots are recorded as still open', 'open' === Missions::recorded( $m )[ $s3 ]['outcome'] && 'open' === Missions::recorded( $m )[ $s4 ]['outcome'] );
	$check( 'packout_mission_completed fired once', 1 === $packout_fired['completed'] );
	$as( $crew_a );
	list( $code, $data ) = $call(
		'PUT',
		'/missions/' . $m . '/progress',
		array(
			'revision' => 1,
			'outcomes' => array( $s3 => 'cleaned' ),
		)
	);
	$check( 'nothing more can be recorded on a finished mission', 409 === $code && 'packout_mission_closed' === $data['code'] && 'completed' === $data['data']['mission']['status'] && 'in_progress' === $stage( $s3 ), $data );
	Missions::flush();
	list( , $data ) = $call( 'GET', '/spots' );
	$coarse         = current( array_filter( $data['spots'], static fn( $row ) => $row['id'] === $s3 ) );
	$check( 'the crew\'s exact view ends with the mission', $coarse && 35.2203 !== $coarse['location']['lat'] );

	WP_CLI::log( 'Hazards and authorization' );
	$flagged = $mission(
		array(
			'title' => 'Corral drums',
			'team'  => $team,
			'spots' => array( $s4 ),
		),
		$lead
	);
	$check( 'a mission whose Spots are all flagged can be planned', is_int( $flagged ) );
	$stuck = Missions::set_status( $flagged, 'completed' );
	$check( 'and cannot be completed', is_wp_error( $stuck ) && 'packout_mission_hazard' === $stuck->get_error_code() && Missions::is_open( $flagged ) );
	Spots::set_hazard( $s4, 'none' );
	$as( $crew_a );
	list( , $data ) = $call(
		'PUT',
		'/missions/' . $flagged . '/progress',
		array(
			'revision' => Missions::revision( $flagged ),
			'outcomes' => array( $s4 => 'cleaned' ),
		)
	);
	$check( 'once a coordinator clears the flag, the Spot can be marked cleaned', 1 === $data['applied'] && 'cleaned' === $stage( $s4 ) );
	$check( 'and the mission can be completed', true === Missions::set_status( $flagged, 'completed' ) );

	$s5             = $spot( 'Abandoned camp.', 35.25, -111.61, $loner );
	$left           = $mission(
		array(
			'title' => 'Abandoned camp',
			'team'  => $team,
			'spots' => array( $s5 ),
		),
		$lead
	);
	list( , $data ) = $call(
		'PUT',
		'/missions/' . $left . '/progress',
		array(
			'revision' => Missions::revision( $left ),
			'outcomes' => array( $s5 => 'left' ),
		)
	);
	$check( 'a Spot left for authorization is flagged and goes back to spotted', 1 === $data['applied'] && 'needs_authorization' === Spots::field( $s5, 'hazard' ) && 'spotted' === $stage( $s5 ) );
	Missions::set_status( $left, 'called_off' );

	WP_CLI::log( 'Calling off and sharing a Spot' );
	$s6     = $spot( 'Shared Spot.', 35.26, -111.60, $loner );
	$s7     = $spot( 'Only on the first mission.', 35.27, -111.59, $loner );
	$first  = $mission(
		array(
			'title' => 'First',
			'team'  => $team,
			'spots' => array( $s6, $s7 ),
		),
		$lead
	);
	$second = $mission(
		array(
			'title' => 'Second',
			'team'  => $team,
			'spots' => array( $s6 ),
		),
		$coordinator
	);
	$check( 'two open missions can share a Spot', is_int( $first ) && is_int( $second ) && 'planned' === $stage( $s6 ) );
	$check( 'calling the first one off', true === Missions::set_status( $first, 'called_off' ) && 'called_off' === Missions::status( $first ) );
	$check( 'leaves the shared Spot planned', 'planned' === $stage( $s6 ) );
	$check( 'and returns the Spot only it held to spotted', 'spotted' === $stage( $s7 ) );
	$check( 'calling the second one off returns the shared Spot to spotted', true === Missions::set_status( $second, 'called_off' ) && 'spotted' === $stage( $s6 ) );
	$check( 'a called-off mission cannot be called off again', is_wp_error( Missions::set_status( $second, 'called_off' ) ) );

	$edit = $mission(
		array(
			'title' => 'Edited',
			'team'  => $team,
			'spots' => array( $s6, $s7 ),
		),
		$lead
	);
	$mission( array( 'spots' => array( $s7 ) ), $lead, $edit );
	$check( 'taking a Spot off a mission returns it to spotted', array( $s7 ) === Missions::spots( $edit ) && 'spotted' === $stage( $s6 ) && 'planned' === $stage( $s7 ) );
	Missions::set_status( $edit, 'in_progress' );
	$check( 'starting a mission moves its planned Spots to in progress', 'in_progress' === $stage( $s7 ) );
	$check( 'completing with open Spots sent back returns them to spotted', true === Missions::set_status( $edit, 'completed', 'spotted' ) && 'spotted' === $stage( $s7 ) );

	WP_CLI::log( 'A cleanup with no mission' );
	$own = $spot( 'Cans at my own campsite.', 35.4, -111.5, $loner, false );
	$as( $loner );
	list( $code, $data ) = $call( 'POST', '/spots/' . $own . '/cleaned' );
	$check( 'a reporter marks their own Spot cleaned', 200 === $code && 'cleaned' === $stage( $own ) && 'cleaned' === $data['spot']['status'], $data );
	$check( 'it is recorded without a mission', (int) Spots::field( $own, 'cleaned_by' ) === $loner && 0 === (int) Spots::field( $own, 'cleaned_mission', '0' ) );
	$check( 'and the reporter still publishes nothing', 'pending' === get_post_status( $own ) && ! user_can( $loner, Capabilities::MODERATE ) );
	$as( $crew_b );
	list( $code ) = $call( 'POST', '/spots/' . $s6 . '/cleaned' );
	$check( 'a reporter cannot mark someone else\'s Spot cleaned', 403 === $code && 'spotted' === $stage( $s6 ) );
	$as( $loner );
	list( $code ) = $call( 'POST', '/spots/' . $s5 . '/cleaned' );
	$check( 'a flagged Spot is refused, even to its reporter', 409 === $code && 'spotted' === $stage( $s5 ) );

	WP_CLI::log( 'What the public sees' );
	$check( 'a mission that is not public is not on the organization page', array() === Missions::public_of_team( $team ) );
	$mission(
		array(
			'public'        => true,
			'meeting_share' => false,
		),
		$lead,
		$m
	);
	$public = Missions::public_of_team( $team );
	$check( 'a mission the lead marked public is', 1 === count( $public ) && 'Schultz Pass cleanup' === $public[0]['title'] && 4 === $public[0]['spots'] && 'Test Trail Crew' === $public[0]['team'] && 'completed' === $public[0]['status'] );
	$check( 'with its dates', false !== strpos( $public[0]['window'], '2027' ) );
	$check( 'without the meeting place, the objective, the checklist, or any pin', '' === $public[0]['landmark'] && ! array_intersect( array( 'objective', 'checklist', 'meeting', 'gear', 'spots_list', 'lat', 'lng' ), array_keys( $public[0] ) ) && false === strpos( (string) wp_json_encode( $public ), '35.2' ) );
	$mission( array( 'meeting_share' => true ), $lead, $m );
	$check( 'the meeting landmark appears once the lead marks it shareable', 'Gravel lot at the Schultz Tank trailhead' === Missions::public_of_team( $team )[0]['landmark'] );
	$check( 'a visitor may read the public mission and the listed organization', Post_Access::can_read( get_post( $m ), 0 ) && Post_Access::can_read( get_post( $team ), 0 ) );
	$listed = current( array_filter( Teams::listed(), static fn( $row ) => $row['id'] === $team ) );
	$check( 'the organization list counts its public missions', $listed && 1 === $listed['completed'] && 0 === $listed['planned'] );
	Teams::save( $team, array( 'listed' => false ) );
	$check( 'an organization that is not listed leaves the public list', ! array_filter( Teams::listed(), static fn( $row ) => $row['id'] === $team ) );
} finally {
	wp_set_current_user( 0 );
	remove_action( 'packout_mission_opened', $count_opened );
	remove_action( 'packout_mission_completed', $count_completed );
	foreach ( array_filter( $packout_posts, 'is_int' ) as $post ) {
		wp_delete_post( $post, true );
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
