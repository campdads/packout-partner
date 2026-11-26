<?php
/**
 * Phase 18 acceptance checks: times on a mission and a campaign, and the calendar.
 *
 * A window may have a time of day and a zone. The calendar shows each person
 * what the rest of the site would show them, in six forms, as a route, and
 * as a calendar file. A mission can be repeated.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-18.php
 *
 * The checks create their own users and records and delete them. The site's
 * time zone is set to America/Chicago while they run and put back afterward.
 *
 * @package PackOut_Core
 */

use PackOut\Core\Calendar as Rules;
use PackOut\Core\Ical;
use PackOut\Core\Repeat;
use PackOut\Core\App\Calendar;
use PackOut\Core\App\Calendar_View;
use PackOut\Core\App\Campaigns;
use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Overview;
use PackOut\Core\App\Partner_Workspace;
use PackOut\Core\App\Partners;
use PackOut\Core\App\Programs;
use PackOut\Core\App\Resources;
use PackOut\Core\App\Schedule;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Signups;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Subteams;
use PackOut\Core\App\Teams;
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
$packout_run      = strtolower( wp_generate_password( 6, false ) );
$packout_zone     = array( get_option( 'timezone_string' ), get_option( 'gmt_offset' ) );

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

$said = static fn( $thing ): string => is_wp_error( $thing ) ? $thing->get_error_code() . ': ' . $thing->get_error_message() : ( is_scalar( $thing ) ? (string) $thing : (string) wp_json_encode( $thing ) );

$user = static function ( string $role, string $name ) use ( &$packout_users, $packout_run ): int {
	$login           = 'pos-test-' . sanitize_key( $name ) . '-' . $packout_run;
	$id              = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => $login . '@example.test',
			'display_name' => $name . ' Calperson',
			'role'         => $role,
		)
	);
	$packout_users[] = $id;
	return $id;
};

// Mail stays in memory. Nothing leaves the machine.
$keep_mail = static fn() => true;
add_filter( 'pre_wp_mail', $keep_mail, 99 );

// The calendar route, as the block's script asks for it.
$route = static function ( array $params, int $who = 0 ) use ( $as ): array {
	$as( $who );
	$request = new WP_REST_Request( 'GET', '/packout/v1/calendar' );
	$request->set_query_params( array_map( 'strval', $params ) );
	$response = rest_do_request( $request );
	$data     = $response->get_data();
	return array( $response->get_status(), is_array( $data ) ? $data : array(), $response->get_headers() );
};
$ids   = static fn( array $answer ): array => array_map( 'intval', array_column( $answer[1]['events'] ?? array(), 'id' ) );

// The block, as a page draws it for one person at one address.
$draw = static function ( array $attributes, array $get = array(), int $who = 0 ) use ( $as ): string {
	$as( $who );
	$_GET = array_map( 'strval', $get );
	$html = render_block(
		array(
			'blockName'    => 'packout/calendar',
			'attrs'        => $attributes,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		)
	);
	$_GET = array();
	return $html;
};

// A calendar file as its lines, unfolded, and its events by UID.
$unfold  = static fn( string $ics ): array => explode( "\r\n", str_replace( "\r\n ", '', $ics ) );
$vevents = static function ( string $ics ) use ( $unfold ): array {
	$events = array();
	$open   = null;
	foreach ( $unfold( $ics ) as $line ) {
		if ( 'BEGIN:VEVENT' === $line ) {
			$open = array();
		} elseif ( 'END:VEVENT' === $line && null !== $open ) {
			$uid = '';
			foreach ( $open as $row ) {
				if ( 0 === strpos( $row, 'UID:' ) ) {
					$uid = substr( $row, 4 );
				}
			}
			$events[ $uid ] = $open;
			$open           = null;
		} elseif ( null !== $open ) {
			$open[] = $line;
		}
	}
	return $events;
};

// A page of the site over HTTP, as a visitor's browser asks for it.
$home     = untrailingslashit( home_url() );
$host     = (string) preg_replace( '#^https?://#', '', $home );
$loopback = untrailingslashit( (string) ( getenv( 'PACKOUT_LOOPBACK' ) ? getenv( 'PACKOUT_LOOPBACK' ) : $home ) );
$fetch    = static function ( string $url ) use ( $home, $host, $loopback ): array {
	$response = wp_remote_get(
		$loopback . substr( $url, strlen( $home ) ),
		array(
			'headers'     => array( 'Host' => $host ),
			'redirection' => 0,
			'timeout'     => 30,
		)
	);
	if ( is_wp_error( $response ) ) {
		return array( 0, '', $response->get_error_message() );
	}
	return array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_header( $response, 'content-type' ), (string) wp_remote_retrieve_body( $response ) );
};

try {
	Capabilities::install();
	Schema::register();
	update_option( 'timezone_string', 'America/Chicago' );
	flush_rewrite_rules( false );

	$today = Calendar::today();
	$d     = static fn( int $days ): string => Rules::shift( $today, $days );

	$coordinator = $user( 'pos_coordinator', 'Coordinator' );
	$ana         = $user( 'pos_lead', 'Ana' );
	$bob         = $user( 'pos_lead', 'Bob' );
	$cat         = $user( 'pos_lead', 'Cat' );
	$mem         = $user( 'pos_reporter', 'Mem' );
	$sam         = $user( 'pos_reporter', 'Sam' );
	$out         = $user( 'pos_reporter', 'Out' );

	$org  = static function ( string $name, int $lead, bool $listed = true ) use ( &$packout_posts, $coordinator, $packout_run, $as ): int {
		$as( $coordinator );
		$id = Teams::save(
			0,
			array(
				'name'        => $name . ' ' . $packout_run,
				'description' => $name . ' looks after its own stretch.',
				'lead'        => $lead,
				'listed'      => $listed,
			)
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $name . ': ' . $id->get_error_message() );
		}
		$packout_posts[] = $id;
		return (int) $id;
	};
	$spot = static function ( string $what, float $lat, int $by, string $category = 'cleanup-waste' ) use ( &$packout_posts, $packout_run ): int {
		$id              = Spots::create(
			array(
				'category'    => $category,
				'description' => $what . ' ' . $packout_run,
				'lat'         => $lat,
				'lng'         => -111.8,
			),
			$by
		);
		$packout_posts[] = $id;
		Spots::publish( $id );
		return (int) $id;
	};
	// Plan a mission and keep it for cleanup.
	$plan = static function ( int $team, string $title, array $spots, int $who, array $more = array() ) use ( &$packout_posts, $packout_run ) {
		$id = Missions::save(
			0,
			$more + array(
				'title'  => $title . ' ' . $packout_run,
				'team'   => $team,
				'spots'  => $spots,
				'public' => true,
			),
			$who
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $title . ': ' . $id->get_error_message() );
		}
		$packout_posts[] = (int) $id;
		return (int) $id;
	};

	$alpha = $org( 'Caltest Alpha', $ana );
	$beta  = $org( 'Caltest Beta', $bob );
	$gamma = $org( 'Caltest Gamma', $cat, false );
	foreach ( array( $mem, $sam ) as $person ) {
		Teams::add_member( $alpha, get_userdata( $person )->user_login );
	}
	Teams::add_member( $beta, get_userdata( $out )->user_login );
	$north = (int) Subteams::save( $alpha, 0, array( 'name' => 'Caltest North ' . $packout_run ), $ana );
	$south = (int) Subteams::save( $alpha, 0, array( 'name' => 'Caltest South ' . $packout_run ), $ana );
	Subteams::set_member( $alpha, $north, $mem, true, $ana );
	Subteams::set_member( $alpha, $south, $sam, true, $ana );

	if ( $hub ) {
		$partner         = Partners::save(
			0,
			array(
				'name'        => 'Caltest Council ' . $packout_run,
				'description' => 'A council of crews.',
				'lead'        => $ana,
				'listed'      => true,
			),
			$coordinator
		);
		$packout_posts[] = $partner;
		if ( is_wp_error( $partner ) ) {
			WP_CLI::error( 'Partner: ' . $partner->get_error_message() );
		}
		$partner = (int) $partner;
		Partners::ask( $alpha, $partner, $ana );
		if ( array( $alpha ) !== array_values( Partners::organization_ids( $partner ) ) ) {
			WP_CLI::error( 'The fixture partner does not have its organization.' );
		}
	} else {
		// A partner site is one partner, and every organization on it is the partner's. For the run it is led by the
		// test's lead and listed. It is put back at the end.
		$partner      = Partners::site();
		$packout_site = array(
			'lead'   => Partners::lead( $partner ),
			'listed' => 'publish' === get_post_status( $partner ),
		);
		$led          = Partners::save(
			$partner,
			array(
				'lead'   => $ana,
				'listed' => true,
			),
			$coordinator
		);
		if ( is_wp_error( $led ) || ! in_array( $alpha, Partners::organization_ids( $partner ), true ) ) {
			WP_CLI::error( 'The site\'s partner could not be used for the run.' );
		}
	}

	$program         = Programs::save(
		0,
		array(
			'owner_kind' => 'partner',
			'owner'      => $partner,
			'title'      => 'Caltest Adopt-a-Road ' . $packout_run,
			'start'      => $d( 1 ),
			'end'        => $d( 40 ),
			'orgs'       => array( $alpha ),
			'public'     => true,
		),
		$ana
	);
	$packout_posts[] = $program;
	if ( is_wp_error( $program ) ) {
		WP_CLI::error( 'Program: ' . $program->get_error_message() );
	}
	$program = (int) $program;

	$campaign        = Campaigns::save(
		0,
		array(
			'team'    => $alpha,
			'title'   => 'Caltest spring sweep ' . $packout_run,
			'start'   => $d( 8 ),
			'end'     => $d( 16 ),
			'public'  => true,
			'program' => $program,
		),
		$ana
	);
	$packout_posts[] = $campaign;
	if ( is_wp_error( $campaign ) ) {
		WP_CLI::error( 'Campaign: ' . $campaign->get_error_message() );
	}
	$campaign = (int) $campaign;

	$a1 = $spot( 'Caltest tires at the turnout.', 34.71, $mem );
	$a2 = $spot( 'Caltest fire ring at the camp.', 34.72, $mem, 'campsites-recreation' );
	$a3 = $spot( 'Caltest glass by the gate.', 34.73, $mem );
	$a4 = $spot( 'Caltest couch in the wash.', 34.74, $mem );
	$a5 = $spot( 'Caltest cans at the ford.', 34.75, $mem );
	$b1 = $spot( 'Caltest wire by the tank.', 34.76, $out );
	$b2 = $spot( 'Caltest drum at the pit.', 34.77, $out );
	$g1 = $spot( 'Caltest pallet at the gate.', 34.78, $out );

	// A mission as every mission was before times: a day, and nothing else.
	$m_legacy  = $plan(
		$alpha,
		'Caltest legacy day',
		array( $a1 ),
		$ana,
		array(
			'start'    => $d( 10 ),
			'end'      => $d( 10 ),
			'subteams' => array( $north ),
		)
	);
	$m_timed   = $plan(
		$alpha,
		'Caltest timed morning',
		array( $a2 ),
		$ana,
		array(
			'start'         => $d( 12 ),
			'end'           => $d( 12 ),
			'start_time'    => '08:00',
			'end_time'      => '14:00',
			'timezone'      => 'America/Denver',
			'campaign'      => $campaign,
			'meeting_note'  => 'The kiosk at the trailhead',
			'meeting_share' => true,
			'meeting_lat'   => 34.123456,
			'meeting_lng'   => -111.654321,
			'gear'          => 'Gloves and a grabber.',
			'new_lines'     => "Count the bags\nWeigh the haul",
			'subteams'      => array( $north ),
		)
	);
	$m_private = $plan(
		$alpha,
		'Caltest quiet day',
		array( $a3 ),
		$ana,
		array(
			'start'  => $d( 10 ),
			'end'    => $d( 10 ),
			'public' => false,
		)
	);
	$m_north   = $plan(
		$alpha,
		'Caltest north only',
		array( $a4 ),
		$ana,
		array(
			'start'    => $d( 13 ),
			'end'      => $d( 13 ),
			'public'   => false,
			'subteams' => array( $north ),
		)
	);
	$m_beta    = $plan(
		$beta,
		'Caltest beta day',
		array( $b1 ),
		$bob,
		array(
			'start' => $d( 10 ),
			'end'   => $d( 10 ),
		)
	);
	$m_off     = $plan(
		$beta,
		'Caltest rained out',
		array( $b2 ),
		$bob,
		array(
			'start' => $d( 14 ),
			'end'   => $d( 14 ),
		)
	);
	Missions::set_status( $m_off, 'called_off' );
	$m_gamma = $plan(
		$gamma,
		'Caltest unlisted crew day',
		array( $g1 ),
		$cat,
		array(
			'start' => $d( 11 ),
			'end'   => $d( 11 ),
		)
	);
	$m_past  = $plan(
		$alpha,
		'Caltest done last week',
		array( $a5 ),
		$ana,
		array(
			'start' => $d( -5 ),
			'end'   => $d( -5 ),
		)
	);
	Missions::set_status( $m_past, 'in_progress' );
	Spots::mark_cleaned( $a5, $ana, $m_past );
	Missions::set_status( $m_past, 'completed' );

	// The stored title: get_the_title() puts "Private:" before a mission that is not public.
	$title = static fn( int $id ): string => (string) get_post_field( 'post_title', $id );
	$range = array(
		'from' => $d( -10 ),
		'to'   => $d( 45 ),
	);

	/* ---------- Times ---------- */

	WP_CLI::log( 'Times on a mission and a campaign' );
	$of = Schedule::of( get_post( $m_legacy ) );
	$check( 'a mission with a day and no time is all-day', is_array( $of ) && true === $of['all_day'] && $d( 10 ) === $of['first_day'] && $d( 10 ) === $of['last_day'], $of ? array( $of['all_day'], $of['first_day'] ) : null );
	$check( 'and stores no time and no zone', '' === Missions::field( $m_legacy, 'start_time' ) && '' === Missions::field( $m_legacy, 'end_time' ) && '' === Missions::field( $m_legacy, 'timezone' ) );
	$check( 'its window reads as it did before', Schema::span( $d( 10 ), $d( 10 ) ) === Missions::window_label( $m_legacy ) && false === strpos( Missions::window_label( $m_legacy ), ':' ), Missions::window_label( $m_legacy ) );

	$of       = Schedule::of( get_post( $m_timed ) );
	$expected = ( new DateTimeImmutable( $d( 12 ) . ' 08:00:00', new DateTimeZone( 'America/Denver' ) ) )->getTimestamp();
	$check( 'a mission from 8:00 to 14:00 in Denver starts at that instant', is_array( $of ) && false === $of['all_day'] && $expected === $of['start']->getTimestamp() && $expected + 6 * HOUR_IN_SECONDS === $of['end']->getTimestamp() && 'America/Denver' === $of['zone'], $of ? array( $of['start']->format( 'c' ), $of['end']->format( 'c' ), $of['zone'] ) : null );
	$label = Missions::window_label( $m_timed );
	$check( 'on a site in Chicago it shows 8:00 am and names Mountain time', 1 === preg_match( '/8:00 am – 2:00 pm M[SD]T$/', $label ), $label );
	update_option( 'timezone_string', 'America/New_York' );
	$moved = Schedule::of( get_post( $m_timed ) );
	$check( 'and the instant is the same whatever the site\'s zone is', is_array( $moved ) && $expected === $moved['start']->getTimestamp() && Missions::window_label( $m_timed ) === $label, Missions::window_label( $m_timed ) );
	update_option( 'timezone_string', 'America/Chicago' );
	$check( 'the stored day is still the local day in the mission\'s zone', $d( 12 ) === Missions::field( $m_timed, 'start' ) && '08:00' === Missions::field( $m_timed, 'start_time' ) && '14:00' === Missions::field( $m_timed, 'end_time' ) );
	$payload = Missions::payload( get_post( $m_timed ), $ana );
	$check( 'the field app is told the times and the zone', '08:00' === ( $payload['window']['start_time'] ?? '' ) && '14:00' === ( $payload['window']['end_time'] ?? '' ) && 'America/Denver' === ( $payload['window']['zone'] ?? '' ), $payload['window'] ?? null );

	$refused = Missions::save(
		$m_timed,
		array(
			'start_time' => '14:00',
			'end_time'   => '08:00',
		),
		$ana
	);
	$check( 'an end before a start on the same day is refused', is_wp_error( $refused ) && 'packout_mission_window' === $refused->get_error_code() && '14:00' === Missions::field( $m_timed, 'end_time' ), $said( $refused ) );
	$refused = Missions::save( $m_timed, array( 'end' => $d( 11 ) ), $ana );
	$check( 'and so is a last day before the first', is_wp_error( $refused ) && 'packout_mission_window' === $refused->get_error_code(), $said( $refused ) );
	$refused = Schedule::read(
		array(
			'start'    => $d( 3 ),
			'end_time' => '10:00',
		),
		0,
		'mission'
	);
	$check( 'an end time needs a start time', is_wp_error( $refused ) && 'packout_mission_window' === $refused->get_error_code(), $said( $refused ) );
	$refused = Schedule::read( array( 'start_time' => '10:00' ), 0, 'campaign' );
	$check( 'a time needs a day, on a campaign as on a mission', is_wp_error( $refused ) && 'packout_campaign_window' === $refused->get_error_code(), $said( $refused ) );
	$same = Schedule::read(
		array(
			'start'      => $d( 3 ),
			'end'        => $d( 4 ),
			'start_time' => '22:00',
			'end_time'   => '02:00',
		),
		0,
		'mission'
	);
	$check( 'an earlier time on a later day is allowed', is_array( $same ) && '02:00' === $same['end_time'], $said( $same ) );
	$check( 'a time first set with no zone takes the site\'s', is_array( $same ) && 'America/Chicago' === $same['timezone'], $said( $same ) );
	$odd = Schedule::read(
		array(
			'start'      => $d( 3 ),
			'start_time' => '09:30',
			'timezone'   => 'Mars/Olympus_Mons',
		),
		0,
		'mission'
	);
	$check( 'a zone that is not one is not kept', is_array( $odd ) && 'America/Chicago' === $odd['timezone'], $said( $odd ) );
	$none = Schedule::read(
		array(
			'start'    => $d( 3 ),
			'timezone' => 'America/Denver',
		),
		0,
		'mission'
	);
	$check( 'a zone is kept only with a time', is_array( $none ) && '' === $none['timezone'] && '' === $none['start_time'], $said( $none ) );

	$c_timed         = Campaigns::save(
		0,
		array(
			'team'       => $beta,
			'title'      => 'Caltest evening push ' . $packout_run,
			'start'      => $d( 20 ),
			'end'        => $d( 20 ),
			'start_time' => '17:30',
			'public'     => true,
		),
		$bob
	);
	$packout_posts[] = $c_timed;
	$of              = is_int( $c_timed ) ? Schedule::of( get_post( $c_timed ) ) : null;
	$check( 'a campaign takes a time too', is_int( $c_timed ) && '17:30' === Campaigns::field( (int) $c_timed, 'start_time' ) && 'America/Chicago' === Campaigns::field( (int) $c_timed, 'timezone' ), $said( $c_timed ) );
	$check( 'a start with no end is one hour long, and is labeled with its start alone', is_array( $of ) && true === $of['open_end'] && HOUR_IN_SECONDS === $of['end']->getTimestamp() - $of['start']->getTimestamp() && 1 === preg_match( '/5:30 pm C[SD]T$/', Schedule::label( (int) $c_timed ) ), is_int( $c_timed ) ? Schedule::label( $c_timed ) : '' );
	$cleared = Campaigns::save(
		(int) $c_timed,
		array(
			'start_time' => '',
			'end_time'   => '',
		),
		$bob
	);
	$check( 'clearing the time makes it all-day again and drops the zone', ! is_wp_error( $cleared ) && '' === Campaigns::field( (int) $c_timed, 'start_time' ) && '' === Campaigns::field( (int) $c_timed, 'timezone' ) && true === Schedule::of( get_post( (int) $c_timed ) )['all_day'], $said( $cleared ) );
	$html = Workspace_Views::mission_pane( get_post( $m_timed ), $ana ) ? \PackOut\Core\App\Workspace_Lists::panes() : '';
	$check( 'the mission form has a start time, an end time, and a zone, filled in', false !== strpos( $html, 'name="start_time"' ) && false !== strpos( $html, 'value="08:00"' ) && false !== strpos( $html, 'name="end_time"' ) && false !== strpos( $html, 'value="14:00"' ) && 1 === preg_match( '#<option value="America/Denver"[^>]*selected#', $html ) );
	$check( 'and the pane shows the window with its times', false !== strpos( $html, esc_html( $label ) ), $label );

	/* ---------- Who sees what ---------- */

	WP_CLI::log( 'What is on the calendar, and for whom' );
	Calendar::settle();
	$visitor = $route( $range );
	$seen    = $ids( $visitor );
	$check( 'the route answers a visitor', 200 === $visitor[0] && $range['from'] === $visitor[1]['from'] && $range['to'] === $visitor[1]['to'], $visitor[0] );
	$check( 'with public missions of listed organizations', ! array_diff( array( $m_legacy, $m_timed, $m_beta, $m_off, $m_past ), $seen ), $seen );
	$check( 'and public campaigns and programs as spans', in_array( $campaign, $seen, true ) && in_array( $program, $seen, true ) );
	$check( 'a private mission is absent for a visitor', ! in_array( $m_private, $seen, true ) && ! in_array( $m_north, $seen, true ) );
	$check( 'and so is a public mission of an organization that is not listed', ! in_array( $m_gamma, $seen, true ) );
	$by_id = array_column( $visitor[1]['events'], null, 'id' );
	$keys  = array( 'id', 'kind', 'title', 'url', 'start', 'end', 'all_day', 'zone', 'status', 'organization', 'partner', 'spots', 'remote' );
	sort( $keys );
	$got = array_keys( $by_id[ $m_timed ] ?? array() );
	sort( $got );
	$check( 'each event has the fields the plan names and no others', $keys === $got, $got );
	$check(
		'a timed mission says when, in its own zone',
		isset( $by_id[ $m_timed ] ) && $d( 12 ) . 'T08:00:00' === substr( $by_id[ $m_timed ]['start'], 0, 19 ) && $d( 12 ) . 'T14:00:00' === substr( $by_id[ $m_timed ]['end'], 0, 19 ) && false === $by_id[ $m_timed ]['all_day'] && 'America/Denver' === $by_id[ $m_timed ]['zone'],
		$by_id[ $m_timed ] ?? null
	);
	$check( 'an all-day mission ends at the start of the day after', isset( $by_id[ $m_legacy ] ) && true === $by_id[ $m_legacy ]['all_day'] && $d( 10 ) === substr( $by_id[ $m_legacy ]['start'], 0, 10 ) && $d( 11 ) === substr( $by_id[ $m_legacy ]['end'], 0, 10 ), $by_id[ $m_legacy ] ?? null );
	$check( 'it names the organization and the partner, counts the Spots, and opens the public page', isset( $by_id[ $m_legacy ] ) && $title( $alpha ) === $by_id[ $m_legacy ]['organization'] && $title( $partner ) === $by_id[ $m_legacy ]['partner'] && 1 === $by_id[ $m_legacy ]['spots'] && get_permalink( $m_legacy ) === $by_id[ $m_legacy ]['url'], $by_id[ $m_legacy ] ?? null );
	$check( 'a called-off mission is present and marked', isset( $by_id[ $m_off ] ) && 'called_off' === $by_id[ $m_off ]['status'], $by_id[ $m_off ]['status'] ?? null );
	$check( 'a campaign and a program are spans of whole days', isset( $by_id[ $campaign ], $by_id[ $program ] ) && 'campaign' === $by_id[ $campaign ]['kind'] && 'program' === $by_id[ $program ]['kind'] && true === $by_id[ $campaign ]['all_day'] && $d( 8 ) === substr( $by_id[ $campaign ]['start'], 0, 10 ) && $d( 17 ) === substr( $by_id[ $campaign ]['end'], 0, 10 ), array( $by_id[ $campaign ] ?? null ) );
	$starts = array_map( 'strtotime', array_column( $visitor[1]['events'], 'start' ) );
	$sorted = $starts;
	sort( $sorted );
	$check( 'events come sorted by start', $starts === $sorted );
	$check( 'nothing says where a mission meets or where its Spots are', false === strpos( (string) wp_json_encode( $visitor[1] ), '34.123456' ) && false === strpos( (string) wp_json_encode( $visitor[1] ), 'kiosk' ) );
	$check( 'a visitor\'s answer may be kept five minutes', 'public, max-age=300' === ( $visitor[2]['Cache-Control'] ?? '' ), $visitor[2] );

	$member = $route( $range, $mem );
	$seen   = $ids( $member );
	$check( 'a member of its organization is shown a private mission', in_array( $m_private, $seen, true ) && in_array( $m_north, $seen, true ), $seen );
	$check( 'and opens a mission where they work on it', false !== strpos( (string) ( array_column( $member[1]['events'], null, 'id' )[ $m_private ]['url'] ?? '' ), 'item=' . $m_private ) || false !== strpos( (string) ( array_column( $member[1]['events'], null, 'id' )[ $m_private ]['url'] ?? '' ), (string) $m_private ), array_column( $member[1]['events'], null, 'id' )[ $m_private ]['url'] ?? '' );
	$check( 'a signed-in person\'s answer is not kept', 'private, no-store' === ( $member[2]['Cache-Control'] ?? '' ), $member[2] );
	$seen = $ids( $route( $range, $sam ) );
	$check( 'a mission assigned to one team is absent for a member of another team', in_array( $m_private, $seen, true ) && ! in_array( $m_north, $seen, true ), $seen );
	$seen = $ids( $route( $range, $out ) );
	$check( 'a member of another organization is not shown either', ! in_array( $m_private, $seen, true ) && ! in_array( $m_north, $seen, true ) && in_array( $m_legacy, $seen, true ), $seen );
	$seen = $ids( $route( $range, $ana ) );
	$check( 'the organization\'s lead is shown all of its missions', ! array_diff( array( $m_legacy, $m_timed, $m_private, $m_north, $m_past ), $seen ), $seen );
	$seen = $ids( $route( $range, $coordinator ) );
	$check( 'a coordinator is shown everything', ! array_diff( array( $m_legacy, $m_timed, $m_private, $m_north, $m_beta, $m_off, $m_gamma, $m_past ), $seen ), $seen );
	$check( 'Calendar::events gives the route its answer', $ids( $visitor ) === array_map( 'intval', array_column( Calendar::events( $range, 0 ), 'id' ) ) );

	/* ---------- Filters ---------- */

	WP_CLI::log( 'Filters and the range' );
	$only = static function ( array $filter, int $who = 0 ) use ( $route, $ids, $range ): array {
		$found = $ids( $route( $range + $filter, $who ) );
		sort( $found );
		return $found;
	};
	$set  = static function ( int ...$members ): array {
		sort( $members );
		return $members;
	};
	$check( 'organization', $set( $m_beta, $m_off, (int) $c_timed ) === $only( array( 'organization' => $beta ) ), $only( array( 'organization' => $beta ) ) );
	if ( $hub ) {
		$check( 'partner: its organizations\' missions and campaigns, and its programs', $set( $m_legacy, $m_timed, $m_past, $campaign, $program ) === $only( array( 'partner' => $partner ) ), $only( array( 'partner' => $partner ) ) );
	} else {
		$skip( 'On a partner site every organization is the partner’s, so the partner filter is the whole calendar.' );
	}
	$check(
		'team: a team of the organization',
		$set( $m_legacy, $m_timed ) === $only(
			array(
				'organization' => $alpha,
				'team'         => $north,
			)
		) && $set( $m_legacy, $m_timed, $m_north ) === $only(
			array(
				'organization' => $alpha,
				'team'         => $north,
			),
			$mem
		),
		$only(
			array(
				'organization' => $alpha,
				'team'         => $north,
			)
		)
	);
	$check( 'program: what is filed under it, and itself', $set( $m_timed, $campaign, $program ) === $only( array( 'program' => $program ) ), $only( array( 'program' => $program ) ) );
	$check( 'campaign: its missions, and itself', $set( $m_timed, $campaign ) === $only( array( 'campaign' => $campaign ) ), $only( array( 'campaign' => $campaign ) ) );
	$check(
		'category: missions with a Spot of it, and no spans',
		$set( $m_timed ) === $only(
			array(
				'category'     => 'campsites-recreation',
				'organization' => $alpha,
			)
		),
		$only(
			array(
				'category'     => 'campsites-recreation',
				'organization' => $alpha,
			)
		)
	);
	$check(
		'show: one kind alone',
		$set( $campaign ) === $only(
			array(
				'show'         => 'campaigns',
				'organization' => $alpha,
			)
		) && $set( $m_legacy, $m_timed, $m_past ) === $only(
			array(
				'show'         => 'missions',
				'organization' => $alpha,
			)
		)
	);
	$check( 'a record that does not exist leaves nothing', array() === $only( array( 'organization' => 999999999 ) ) && array() === $only( array( 'program' => $alpha ) ) );
	$check(
		'only: the missions a page names',
		array( $m_beta ) === array_map(
			'intval',
			array_column(
				Calendar::events(
					$range + array( 'only' => array( $m_beta, $m_private ) ),
					0
				),
				'id'
			)
		)
	);

	$long = $route(
		array(
			'from' => $d( 0 ),
			'to'   => $d( 63 ),
		)
	);
	$check( 'a range of more than 62 days is refused', 400 === $long[0] && 'packout_calendar_range' === ( $long[1]['code'] ?? '' ), array( $long[0], $long[1]['code'] ?? '' ) );
	$edge = $route(
		array(
			'from' => $d( 0 ),
			'to'   => $d( 62 ),
		)
	);
	$check( 'and one of 62 is answered', 200 === $edge[0], $edge[0] );
	$back = $route(
		array(
			'from' => $d( 5 ),
			'to'   => $d( 1 ),
		)
	);
	$none = $route( array( 'from' => 'soon' ) );
	$check( 'a range that ends before it starts, or is not dates, is refused', 400 === $back[0] && 400 === $none[0], array( $back[0], $none[0] ) );
	$named = $route(
		array(
			'view' => 'week',
			'date' => $d( 12 ),
		)
	);
	$check( 'a view and a day name the range that view covers', 200 === $named[0] && 6 === Rules::between( $named[1]['from'], $named[1]['to'] ) && $named[1]['from'] <= $d( 12 ) && $named[1]['to'] >= $d( 12 ) && in_array( $m_timed, $ids( $named ), true ), array( $named[1]['from'] ?? '', $named[1]['to'] ?? '' ) );

	/* ---------- The cache ---------- */

	WP_CLI::log( 'A visitor\'s stored answer' );
	global $wpdb;
	Calendar::settle();
	$before  = Calendar::version();
	$first   = $route( $range );
	$renamed = 'Caltest renamed behind its back ' . $packout_run;
	// Changed where no hook hears it, so only a stored answer can still have the old title.
	$wpdb->update( $wpdb->posts, array( 'post_title' => $renamed ), array( 'ID' => $m_beta ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A change that fires no hook.
	clean_post_cache( $m_beta );
	$second = $route( $range );
	$check( 'a visitor\'s answer is kept: a change no hook heard is not in the next one', $first[1] === $second[1] && Calendar::version() === $before );
	$kept = Missions::save( $m_beta, array( 'title' => 'Caltest beta moved ' . $packout_run ), $bob );
	$check( 'saving a mission counts a change', ! is_wp_error( $kept ) && Calendar::version() > $before, $said( $kept ) );
	$during = array_column( $route( $range )[1]['events'], 'title', 'id' );
	$check( 'the request that made it reads no stored answer', ( $during[ $m_beta ] ?? '' ) === 'Caltest beta moved ' . $packout_run, $during[ $m_beta ] ?? '' );
	Calendar::settle();
	$after = array_column( $route( $range )[1]['events'], 'title', 'id' );
	$check( 'and the next request has the new title', ( $after[ $m_beta ] ?? '' ) === 'Caltest beta moved ' . $packout_run, $after[ $m_beta ] ?? '' );
	$at = Calendar::version();
	wp_trash_post( $m_gamma );
	$trashed = Calendar::version();
	Calendar::settle();
	wp_untrash_post( $m_gamma );
	wp_update_post(
		array(
			'ID'          => $m_gamma,
			'post_status' => 'publish',
		)
	);
	Calendar::settle();
	$check( 'trashing a mission counts a change too', $trashed > $at, array( $at, $trashed ) );
	$at = Calendar::version();
	wp_update_post(
		array(
			'ID'         => (int) get_option( 'page_on_front' ) > 0 ? (int) get_option( 'page_on_front' ) : $a1,
			'post_title' => get_the_title( (int) get_option( 'page_on_front' ) > 0 ? (int) get_option( 'page_on_front' ) : $a1 ),
		)
	);
	$check( 'saving something that is not on a calendar does not', Calendar::version() === $at, array( $at, Calendar::version() ) );
	Calendar::settle();

	/* ---------- The six forms ---------- */

	WP_CLI::log( 'The block in its six forms' );
	$registry = WP_Block_Type_Registry::get_instance();
	$type     = $registry->get_registered( Calendar_View::BLOCK );
	$wanted   = array( 'view', 'switcher', 'partner', 'organization', 'team', 'program', 'campaign', 'category', 'show', 'count', 'feed', 'weekStart' );
	$check( 'the block is registered with the attributes the plan names', $type && ! array_diff( $wanted, array_keys( $type->attributes ) ) && 'month' === $type->attributes['view']['default'] && Rules::VIEWS === $type->attributes['view']['enum'], $type ? array_keys( $type->attributes ) : null );
	$check( 'its stylesheet is registered', wp_style_is( 'packout-calendar', 'registered' ) );

	$legacy = esc_html( $title( $m_legacy ) );
	$on     = array( 'cal-date' => $d( 10 ) );
	foreach ( array( 'day', 'week', 'month', 'agenda' ) as $form ) {
		$html = $draw( array( 'view' => $form ), $on );
		$check( 'a mission with no time is on its day in ' . ucfirst( $form ), false !== strpos( $html, $legacy ) && false !== strpos( $html, 'pos-cal--' . $form ), strlen( $html ) );
	}
	$html = $draw(
		array(
			'view'         => 'upcoming',
			'organization' => $alpha,
		)
	);
	$check( 'and in Upcoming', false !== strpos( $html, $legacy ) && false !== strpos( $html, 'pos-cal__cards' ) && false === strpos( $html, esc_html( $title( $m_past ) ) ) );
	$html = $draw(
		array(
			'view'         => 'past',
			'organization' => $alpha,
		)
	);
	$check( 'a finished one is in Past, with how many Spots it cleaned', false !== strpos( $html, esc_html( $title( $m_past ) ) ) && false !== strpos( $html, '1 Spot cleaned' ) && false === strpos( $html, $legacy ), strlen( $html ) );
	$check( 'Upcoming and Past have no controls', false === strpos( $html, 'data-cal-go' ) && false === strpos( $html, 'pos-cal__views' ) );

	$html = $draw(
		array(
			'view'         => 'month',
			'organization' => $alpha,
		),
		$on
	);
	$check( 'Month is a table with a caption and a header for each day of the week', 1 === preg_match( '#<table class="pos-cal__month"><caption#', $html ) && 7 === preg_match_all( '#<th scope="col">#', $html ) );
	$check( 'with one cell for each day of whole weeks', 0 === preg_match_all( '#<td class="pos-cal__day#', $html ) % 7 && preg_match_all( '#<td class="pos-cal__day#', $html ) >= 28 && preg_match_all( '#<td class="pos-cal__day#', $html ) === preg_match_all( '#data-cal-day="\d{4}-\d{2}-\d{2}"#', $html ), preg_match_all( '#<td class="pos-cal__day#', $html ) );
	$check( 'one day holds the place in the tab order', 1 === preg_match_all( '#data-cal-day="[^"]+" tabindex="0"#', $html ), preg_match_all( '#data-cal-day="[^"]+" tabindex="0"#', $html ) );
	$check( 'the same days are drawn as an agenda for a narrow block', 1 === preg_match( '#<div class="pos-cal__narrow"><ol class="pos-cal__agenda">#', $html ) && 2 <= substr_count( $html, $legacy ) );
	$check( 'a visitor sees three events on that day, so none are held back', false === strpos( $html, 'pos-cal__more' ) );
	$html = $draw( array( 'view' => 'month' ), array(), 0 );
	$check( 'today is marked for a screen reader as well as by color', 1 === preg_match( '#data-cal-day="' . $today . '" tabindex="0" aria-label="Today, [^"]+" aria-current="date"#', $html ), $today );
	$html = $draw(
		array(
			'view'         => 'month',
			'organization' => $alpha,
		),
		$on,
		$ana
	);
	$check( 'a fourth event on a day becomes "1 more", which opens that day as Agenda', 1 === preg_match( '#<a href="[^"]*cal-view=agenda[^"]*cal-date=' . $d( 10 ) . '[^"]*"[^>]*class="pos-cal__more">1 more</a>#', $html ) );
	$check( 'a mission that is not public says so to the member who sees it', false !== strpos( $html, 'Not public' ) );
	$html = $draw(
		array(
			'view'         => 'month',
			'organization' => $alpha,
			'switcher'     => false,
		),
		$on + array( 'cal-view' => 'day' ),
		$ana
	);
	$check( 'with the switcher off, Month shows every event of a day', false === strpos( $html, 'pos-cal__more' ) && false !== strpos( $html, esc_html( $title( $m_private ) ) ) );
	$check( 'and stays a month whatever the address names, with no way to change it', false !== strpos( $html, 'pos-cal--month' ) && false === strpos( $html, 'pos-cal__views' ) && false === strpos( $html, 'pos-cal__filters' ) && false !== strpos( $html, 'rel="next"' ) );

	$html = $draw(
		array( 'view' => 'month' ),
		array(
			'cal-view' => 'week',
			'cal-date' => $d( 12 ),
		)
	);
	$check( 'with the switcher on, the address names the form and the day', false !== strpos( $html, 'pos-cal--week' ) && 1 === preg_match( '#data-cal-view="week"[^>]*aria-current="true"#', $html ) );
	$timed = esc_html( $title( $m_timed ) );
	$check( 'Week puts a timed mission on the hour scale, at its own time, with its zone named', 1 === preg_match( '#<li class="pos-cal__event[^"]*" style="--pos-cal-top:[\d.]+%;--pos-cal-height:[\d.]+%;[^"]*"><a[^>]*><span class="pos-cal__event-title">' . preg_quote( $timed, '#' ) . '</span>#', $html ) && 1 === preg_match( '#8:00 am – 2:00 pm M[SD]T#', $html ) );
	$check( 'the scale runs at least from 8 am to 6 pm', false !== strpos( $html, '<li>8 am</li>' ) && false !== strpos( $html, '<li>5 pm</li>' ) && 1 === preg_match( '#--pos-cal-hours:(\d+)#', $html, $hours ) && (int) $hours[1] >= 10, $hours[1] ?? '' );
	$check( 'and spans and all-day events are in a band above it', 1 === preg_match( '#pos-cal__row--band.*' . preg_quote( esc_html( $title( $campaign ) ), '#' ) . '.*pos-cal__row--scale#s', $html ) );
	$check( 'the time carries its offset, so the reader\'s own time can be added', 1 === preg_match( '#<time class="pos-cal__time" datetime="' . $d( 12 ) . 'T08:00:00-0[67]:00" data-cal-offset="-2(52|16)00">#', $html ) );
	$html = $draw( array( 'view' => 'day' ), array( 'cal-date' => $d( 14 ) ) );
	$check( 'a called-off mission stays, with the word', false !== strpos( $html, esc_html( $title( $m_off ) ) ) && false !== strpos( $html, 'pos-cal__flag--called-off">Called off</span>' ) && false !== strpos( $html, 'pos-cal__is-called-off' ) );

	$html = $draw(
		array(
			'view'         => 'agenda',
			'organization' => $alpha,
			'count'        => 2,
		),
		array( 'cal-date' => $d( 10 ) )
	);
	$check( 'Agenda lists whole days until it has as many as asked', false !== strpos( $html, $legacy ) && false !== strpos( $html, esc_html( $title( $campaign ) ) ) && false === strpos( $html, $timed ), strlen( $html ) );
	$check( 'and pages forward from the next day that has something', 1 === preg_match( '#data-cal-view="agenda" data-cal-date="' . $d( 12 ) . '"[^>]*rel="next"#', $html ), $d( 12 ) );
	$html = $draw(
		array(
			'view'         => 'agenda',
			'organization' => $alpha,
			'count'        => 2,
		),
		array( 'cal-date' => $d( 12 ) )
	);
	$check( 'the next page starts there, and pages back', false !== strpos( $html, $timed ) && 1 === preg_match( '#data-cal-view="agenda" data-cal-date="\d{4}-\d{2}-\d{2}"[^>]*rel="prev"#', $html ) );

	$html = $draw( array( 'view' => 'month' ) );
	$check( 'the block offers the calendar file, to subscribe to and as a plain address', 1 === preg_match( '#<a href="webcal://[^"]*calendar\.ics[^"]*">Subscribe in a calendar app</a>#', $html ) && 1 === preg_match( '#<a href="https?://[^"]*calendar\.ics[^"]*">Calendar file</a>#', $html ) );
	$check( 'a visitor may filter by category and by organization', false !== strpos( $html, 'name="cal-cat"' ) && false !== strpos( $html, 'name="cal-org"' ) && false !== strpos( $html, '<option value="' . $alpha . '"' ) && false === strpos( $html, '<option value="' . $gamma . '"' ) );
	$html = $draw(
		array(
			'view'         => 'month',
			'organization' => $alpha,
			'category'     => 'cleanup-waste',
			'feed'         => false,
		)
	);
	$check( 'a block limited to one of each offers neither, and with the feed off has no link', false === strpos( $html, 'name="cal-cat"' ) && false === strpos( $html, 'name="cal-org"' ) && false === strpos( $html, 'calendar.ics' ) );
	$html = $draw( array( 'view' => 'month' ), $on + array( 'cal-org' => (string) $beta ) );
	$check( 'a visitor\'s choice narrows it, and travels with every link', false !== strpos( $html, esc_html( 'Caltest beta moved ' . $packout_run ) ) && false === strpos( $html, $legacy ) && 1 === preg_match( '#rel="next"#', $html ) && 0 === preg_match( '#<a href="(?![^"]*cal-org=' . $beta . ')[^"]*" data-cal-go#', $html ) );
	$html = $draw(
		array(
			'view'         => 'month',
			'organization' => $alpha,
		),
		$on + array( 'cal-org' => (string) $beta )
	);
	$check( 'but cannot widen a block that is limited', false !== strpos( $html, $legacy ) && false === strpos( $html, esc_html( 'Caltest beta moved ' . $packout_run ) ) );
	$drawn = $route(
		array(
			'view'         => 'month',
			'date'         => $d( 10 ),
			'html'         => '1',
			'switcher'     => '1',
			'feed'         => '1',
			'organization' => (string) $alpha,
			'fixed'        => 'organization',
			'base'         => home_url( '/calendar/' ),
		)
	);
	$check( 'the route draws the same view for the script to put in place', 200 === $drawn[0] && is_string( $drawn[1]['html'] ?? null ) && false !== strpos( $drawn[1]['html'], $legacy ) && false !== strpos( $drawn[1]['html'], 'pos-cal__month' ) && false === strpos( $drawn[1]['html'], 'name="cal-org"' ) && false !== strpos( $drawn[1]['html'], esc_url( home_url( '/calendar/' ) ) . '?cal-view=' ), strlen( $drawn[1]['html'] ?? '' ) );
	$away = $route(
		array(
			'view' => 'month',
			'date' => $d( 10 ),
			'html' => '1',
			'base' => 'https://elsewhere.example/calendar/',
		)
	);
	$check( 'and its links never lead to another site', 200 === $away[0] && false === strpos( $away[1]['html'] ?? 'elsewhere.example', 'elsewhere.example' ) );
	$html = $draw( array( 'view' => 'week' ), array( 'cal-date' => $d( 12 ) ), $coordinator );
	$check( 'no form shows where a crew meets unless the lead shared it, and never coordinates', false === strpos( $html, '34.123456' ) && false === strpos( $html, '-111.654321' ) );

	$html = Workspace_Views::team( get_post( $alpha ), 'calendar', '', $ana );
	$check( 'the Organization workspace has a Calendar view, limited to the organization', 1 === preg_match( '#<a href="[^"]*view=calendar[^"]*" aria-current="page">Calendar</a>#', $html ) && false !== strpos( $html, 'pos-cal--month' ) && false === strpos( $html, 'name="cal-org"' ) && false === strpos( $html, 'wp-block-packout-workspace' ) );
	$html = Partner_Workspace::partner( $partner, 'calendar', '', $ana );
	$check( 'and so has the Partner workspace', 1 === preg_match( '#aria-current="page">Calendar</a>#', $html ) && false !== strpos( $html, 'pos-cal--month' ) );

	/* ---------- The calendar file ---------- */

	WP_CLI::log( 'The calendar file' );
	$as( $coordinator );
	$request  = new WP_REST_Request( 'GET', '/packout/v1/calendar.ics' );
	$response = rest_do_request( $request );
	$ics      = (string) $response->get_data();
	$events   = $vevents( $ics );
	$public   = Calendar::events(
		array(
			'from' => $d( -Calendar::FILE_REACH[0] ),
			'to'   => $d( Calendar::FILE_REACH[1] ),
		),
		0
	);
	$check( 'the file is a calendar', 200 === $response->get_status() && Ical::TYPE === ( $response->get_headers()['Content-Type'] ?? '' ) && 0 === strpos( $ics, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" ) && "END:VCALENDAR\r\n" === substr( $ics, -15 ), $response->get_headers() );
	$check( 'with one event for each public event, whoever asks', count( $public ) === substr_count( $ics, 'BEGIN:VEVENT' ) && count( $public ) === count( $events ) && substr_count( $ics, 'BEGIN:VEVENT' ) === substr_count( $ics, 'END:VEVENT' ), array( count( $public ), substr_count( $ics, 'BEGIN:VEVENT' ) ) );
	$check( 'and nothing from a private mission', false === strpos( $ics, 'Caltest quiet day' ) && false === strpos( $ics, 'Caltest north only' ) && false === strpos( $ics, 'Caltest unlisted crew day' ) );
	$longest = max( array_map( 'strlen', explode( "\r\n", $ics ) ) );
	$check( 'every line is folded at 75 octets and ends with CRLF', $longest <= 75 && false === strpos( str_replace( "\r\n", '', $ics ), "\n" ), $longest );
	$uid     = static fn( string $kind, int $id ): string => $kind . '-' . $id . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
	$compact = static fn( string $day ): string => str_replace( '-', '', $day );
	$all_day = $events[ $uid( 'mission', $m_legacy ) ] ?? array();
	$check( 'an all-day event is given as days, the end day exclusive', in_array( 'DTSTART;VALUE=DATE:' . $compact( $d( 10 ) ), $all_day, true ) && in_array( 'DTEND;VALUE=DATE:' . $compact( $d( 11 ) ), $all_day, true ), $all_day );
	$with_time = $events[ $uid( 'mission', $m_timed ) ] ?? array();
	$check( 'a timed event has its zone', in_array( 'DTSTART;TZID=America/Denver:' . $compact( $d( 12 ) ) . 'T080000', $with_time, true ) && in_array( 'DTEND;TZID=America/Denver:' . $compact( $d( 12 ) ) . 'T140000', $with_time, true ), $with_time );
	$check( 'and the file defines each zone it uses', 1 === substr_count( $ics, "BEGIN:VTIMEZONE\r\nTZID:America/Denver\r\n" ) && substr_count( $ics, 'BEGIN:VTIMEZONE' ) === count( array_unique( array_filter( array_map( static fn( array $event ): string => $event['all_day'] || ! \PackOut\Core\Schedule::is_named( $event['zone'] ) ? '' : $event['zone'], $public ) ) ) ), substr_count( $ics, 'BEGIN:VTIMEZONE' ) );
	$check( 'an event has a stamp, a summary, an address, and the meeting place the lead shared', (bool) preg_grep( '/^DTSTAMP:\d{8}T\d{6}Z$/', $with_time ) && in_array( 'SUMMARY:' . $title( $m_timed ), $with_time, true ) && in_array( 'URL:' . get_permalink( $m_timed ), $with_time, true ) && in_array( 'LOCATION:The kiosk at the trailhead', $with_time, true ) && in_array( 'STATUS:CONFIRMED', $with_time, true ), $with_time );
	$check( 'a called-off mission is CANCELLED', in_array( 'STATUS:CANCELLED', $events[ $uid( 'mission', $m_off ) ] ?? array(), true ), $events[ $uid( 'mission', $m_off ) ] ?? null );
	$check( 'a campaign and a program are in it as spans', isset( $events[ $uid( 'campaign', $campaign ) ], $events[ $uid( 'program', $program ) ] ) && in_array( 'DTEND;VALUE=DATE:' . $compact( $d( 17 ) ), $events[ $uid( 'campaign', $campaign ) ], true ) );
	$check( 'nothing in it is a coordinate', false === strpos( $ics, '34.123456' ) && false === strpos( $ics, 'GEO:' ) );
	$request = new WP_REST_Request( 'GET', '/packout/v1/calendar.ics' );
	$request->set_query_params( array( 'organization' => (string) $beta ) );
	$narrow = $vevents( (string) rest_do_request( $request )->get_data() );
	$check( 'the file takes the same filters', isset( $narrow[ $uid( 'mission', $m_beta ) ], $narrow[ $uid( 'mission', $m_off ) ] ) && ! isset( $narrow[ $uid( 'mission', $m_legacy ) ] ) );
	$check( 'and the block links to the file for its own filters', false !== strpos( Calendar::file_url( array( 'organization' => $beta ) ), 'organization=' . $beta ) && 0 === strpos( Calendar::file_url( array( 'organization' => $beta ), 'webcal' ), 'webcal://' ) );

	$one = $fetch( Calendar::mission_file_url( $m_timed ) );
	$check( 'a public mission has a file of its own at its address', 200 === $one[0] && 0 === strpos( $one[1], 'text/calendar' ) && 1 === substr_count( $one[2], 'BEGIN:VEVENT' ) && false !== strpos( $one[2], 'UID:' . $uid( 'mission', $m_timed ) ), array( $one[0], $one[1], substr( $one[2], 0, 120 ) ) );
	$one = $fetch( Calendar::mission_file_url( $m_gamma ) );
	$check( 'whether or not its organization is listed, as its page is', 200 === $one[0] && 1 === substr_count( $one[2], 'BEGIN:VEVENT' ), $one[0] );
	$one = $fetch( trailingslashit( home_url( '/mission/' . get_post_field( 'post_name', $m_private ) ) ) . 'calendar.ics' );
	$check( 'a private mission has none', 404 === $one[0] && false === strpos( $one[2], 'BEGIN:VEVENT' ), $one[0] );
	$page = $fetch( (string) get_permalink( $m_timed ) );
	$check( 'the mission\'s page links to its file', 200 === $page[0] && false !== strpos( $page[2], esc_url( Calendar::mission_file_url( $m_timed ) ) ) && false !== strpos( $page[2], 'Add to my calendar' ), $page[0] );
	$check( 'and carries its event data', 1 === preg_match( '#<script type="application/ld\+json">(\{"@context":"https://schema\.org","@type":"Event".*?)</script>#s', $page[2], $found ) && is_array( json_decode( $found[1], true ) ), $found[1] ?? '' );
	$page = $fetch( (string) get_permalink( $alpha ) );
	$check( 'the organization\'s page lists what is coming up from the calendar', 200 === $page[0] && false !== strpos( $page[2], 'pos-cal--upcoming' ) && false !== strpos( $page[2], $legacy ) && false !== strpos( $page[2], $timed ) && false === strpos( $page[2], 'Caltest quiet day' ), $page[0] );
	$check( 'and what is over under it, once', 1 === substr_count( $page[2], esc_html( $title( $m_past ) ) ) && 1 === substr_count( $page[2], '>' . $legacy . '</a>' ), array( substr_count( $page[2], esc_html( $title( $m_past ) ) ), substr_count( $page[2], '>' . $legacy . '</a>' ) ) );
	$page = $fetch( (string) get_permalink( $partner ) );
	$check( 'the partner\'s page does too', 200 === $page[0] && false !== strpos( $page[2], 'pos-cal--upcoming' ) && false !== strpos( $page[2], $legacy ) && false !== strpos( $page[2], esc_html( $title( $campaign ) ) ), $page[0] );
	$page = $fetch( (string) get_permalink( $program ) );
	$check( 'and the program\'s', 200 === $page[0] && false !== strpos( $page[2], 'pos-cal--upcoming' ) && false !== strpos( $page[2], $timed ) && false === strpos( $page[2], $legacy ), $page[0] );

	/* ---------- Event data ---------- */

	WP_CLI::log( 'Event data on a mission\'s page' );
	$data = Calendar::event_schema( $m_timed );
	$check( 'a public mission is a schema.org Event', is_array( $data ) && 'https://schema.org' === $data['@context'] && 'Event' === $data['@type'] && $title( $m_timed ) === $data['name'] && get_permalink( $m_timed ) === $data['url'], $data );
	$check( 'with its start and end as instants', is_array( $data ) && strtotime( $data['startDate'] ) === $expected && strtotime( $data['endDate'] ) === $expected + 6 * HOUR_IN_SECONDS && 1 === preg_match( '/T08:00:00-0[67]:00$/', $data['startDate'] ), array( $data['startDate'] ?? '', $data['endDate'] ?? '' ) );
	$check( 'its status, and the organization as the organizer', is_array( $data ) && 'https://schema.org/EventScheduled' === $data['eventStatus'] && 'Organization' === ( $data['organizer']['@type'] ?? '' ) && $title( $alpha ) === ( $data['organizer']['name'] ?? '' ) && get_permalink( $alpha ) === ( $data['organizer']['url'] ?? '' ), $data['organizer'] ?? null );
	$check( 'the place by name, because the lead shared it', is_array( $data ) && 'Place' === ( $data['location']['@type'] ?? '' ) && 'The kiosk at the trailhead' === ( $data['location']['name'] ?? '' ) && array( '@type', 'name' ) === array_keys( $data['location'] ) );
	$json = (string) wp_json_encode( $data );
	$check( 'and no coordinates', false === strpos( $json, '34.12' ) && false === strpos( $json, '111.65' ) && false === stripos( $json, 'geo' ) && false === stripos( $json, 'latitude' ) );
	$data = Calendar::event_schema( $m_legacy );
	$check( 'an all-day mission gives days, and no place when none was shared', is_array( $data ) && $d( 10 ) === $data['startDate'] && $d( 10 ) === $data['endDate'] && ! isset( $data['location'] ), $data );
	$data = Calendar::event_schema( $m_off );
	$check( 'a called-off mission is EventCancelled', is_array( $data ) && 'https://schema.org/EventCancelled' === $data['eventStatus'], $data['eventStatus'] ?? null );
	$data = Calendar::event_schema( $m_gamma );
	$check( 'an organization that is not listed is not named', is_array( $data ) && ! isset( $data['organizer'] ), $data );
	$check( 'a private mission has no event data', null === Calendar::event_schema( $m_private ) );

	/* ---------- Repeating a mission ---------- */

	WP_CLI::log( 'Repeating a mission' );
	$link   = Resources::add(
		$alpha,
		array(
			'title'   => 'Caltest route notes ' . $packout_run,
			'url'     => 'https://example.org/route-notes',
			'level'   => 'mission',
			'mission' => $m_timed,
		),
		$ana
	);
	$file   = wp_insert_attachment(
		array(
			'post_title'     => 'Caltest map ' . $packout_run,
			'post_mime_type' => 'application/pdf',
			'post_status'    => 'inherit',
		),
		false,
		$alpha
	);
	$list   = Schema::stored_list( $alpha, 'resources' );
	$list[] = array(
		'id'       => Schema::next_number( $alpha ),
		'title'    => 'Caltest map ' . $packout_run,
		'url'      => '',
		'file'     => (int) $file,
		'note'     => '',
		'audience' => 'members',
		'level'    => 'mission',
		'mission'  => $m_timed,
	);
	Schema::write_list( $alpha, 'resources', $list );
	$own = static fn( int $mission ): array => array_values( array_filter( Resources::all( Missions::team( $mission ) ), static fn( array $row ): bool => 'mission' === $row['level'] && $row['mission'] === $mission ) );
	if ( is_wp_error( $link ) || 2 !== count( $own( $m_timed ) ) ) {
		WP_CLI::error( 'The fixture mission does not have its two resources.' );
	}

	$copies = Missions::repeat(
		$m_timed,
		array(
			'every'  => '2',
			'copies' => '3',
			'carry'  => array( 'checklist', 'gear' ),
		),
		$ana
	);
	foreach ( is_array( $copies ) ? $copies : array() as $copy ) {
		$packout_posts[] = (int) $copy;
	}
	$check( 'every two weeks, three copies, makes three missions', is_array( $copies ) && 3 === count( $copies ) && 3 === count( array_filter( $copies, static fn( $copy ): bool => Schema::MISSION === get_post_type( $copy ) ) ), $said( $copies ) );
	$copies = is_array( $copies ) ? array_values( $copies ) : array( 0, 0, 0 );
	$check( 'fourteen days apart', array( $d( 26 ), $d( 40 ), $d( 54 ) ) === array_map( static fn( int $copy ): string => Missions::field( $copy, 'start' ), $copies ) && array( $d( 26 ), $d( 40 ), $d( 54 ) ) === array_map( static fn( int $copy ): string => Missions::field( $copy, 'end' ), $copies ), array_map( static fn( int $copy ): string => Missions::field( $copy, 'start' ), $copies ) );
	$check( 'each planned, with no Spots', array( 'planned', 'planned', 'planned' ) === array_map( array( Missions::class, 'status' ), $copies ) && array( array(), array(), array() ) === array_map( array( Missions::class, 'spots' ), $copies ) );
	$lines = Missions::checklist( $copies[0] );
	$check( 'with the checklist, nothing ticked', array( 'Count the bags', 'Weigh the haul' ) === array_column( $lines, 'text' ) && ! array_filter( array_column( $lines, 'done' ) ) && ! array_intersect( array_column( $lines, 'id' ), array_column( Missions::checklist( $m_timed ), 'id' ) ), $lines );
	$check( 'the same title, time of day, zone, and visibility', $title( $m_timed ) === $title( $copies[0] ) && '08:00' === Missions::field( $copies[0], 'start_time' ) && '14:00' === Missions::field( $copies[0], 'end_time' ) && 'America/Denver' === Missions::field( $copies[0], 'timezone' ) && 'publish' === get_post_status( $copies[0] ) && Missions::team( $copies[0] ) === $alpha );
	$check( 'what was ticked is carried, and what was not is left', 'Gloves and a grabber.' === Missions::field( $copies[0], 'gear' ) && '' === Missions::field( $copies[0], 'meeting_note' ) && array() === Missions::subteams( $copies[0] ) && 0 === Missions::campaign( $copies[0] ) && 0 === Programs::of_mission( $copies[0] ) && array() === $own( $copies[0] ) );
	$check( 'nothing links a copy to the mission it came from', ! array_filter( array_keys( get_post_meta( $copies[0] ) ), static fn( string $key ): bool => false !== strpos( $key, 'repeat' ) || false !== strpos( $key, 'series' ) || false !== strpos( $key, 'rrule' ) ) );
	$check( 'the mission that was repeated is as it was', $d( 12 ) === Missions::field( $m_timed, 'start' ) && array( $a2 ) === Missions::spots( $m_timed ) && 2 === count( $own( $m_timed ) ) );
	$check( 'the copies are on the calendar', ! array_diff( array( $copies[0], $copies[1] ), $ids( $route( $range ) ) ), $ids( $route( $range ) ) );

	$full = Missions::repeat(
		$m_timed,
		array(
			'every'  => 'month',
			'copies' => '1',
			'carry'  => Repeat::CARRY,
		),
		$ana
	);
	foreach ( is_array( $full ) ? $full : array() as $copy ) {
		$packout_posts[] = (int) $copy;
	}
	$full_id = is_array( $full ) ? (int) ( $full[0] ?? 0 ) : 0;
	$month   = ( new DateTimeImmutable( $d( 12 ) ) )->modify( 'last day of next month' );
	$same    = min( (int) substr( $d( 12 ), 8, 2 ), (int) $month->format( 'd' ) );
	$check( 'each month, on the same day of the month', $full_id > 0 && $month->format( 'Y-m-' ) . sprintf( '%02d', $same ) === Missions::field( $full_id, 'start' ), $full_id > 0 ? Missions::field( $full_id, 'start' ) : $said( $full ) );
	$check( 'with everything ticked, the copy keeps the meeting place, the teams, the campaign, and through it the program', $full_id > 0 && 'The kiosk at the trailhead' === Missions::field( $full_id, 'meeting_note' ) && '1' === Missions::field( $full_id, 'meeting_share' ) && array( $north ) === Missions::subteams( $full_id ) && Missions::campaign( $full_id ) === $campaign && Programs::of_mission( $full_id ) === $program );
	$carried = $full_id > 0 ? $own( $full_id ) : array();
	$check( 'and the resources, as its own', 2 === count( $carried ) && 2 === count( $own( $m_timed ) ) && ! array_intersect( array_column( $carried, 'id' ), array_column( $own( $m_timed ), 'id' ) ), $carried );
	$shared = array_values( array_filter( $carried, static fn( array $row ): bool => (int) $file === $row['file'] ) );
	$first  = array_values( array_filter( $own( $m_timed ), static fn( array $row ): bool => (int) $file === $row['file'] ) );
	$gone   = $shared ? Resources::remove( $alpha, $shared[0]['id'], $ana ) : null;
	$check( 'removing a copy\'s resource leaves the file the first mission still uses', true === $gone && null !== get_post( (int) $file ) && 2 === count( $own( $m_timed ) ), $said( $gone ) );
	$gone = $first ? Resources::remove( $alpha, $first[0]['id'], $ana ) : null;
	$check( 'and the file goes with its last resource', true === $gone && null === get_post( (int) $file ), $said( $gone ) );

	$refused = Missions::repeat(
		$m_timed,
		array(
			'every'  => '13',
			'copies' => '2',
		),
		$ana
	);
	$check( 'an interval over twelve weeks is refused', is_wp_error( $refused ) && 'packout_mission_repeat' === $refused->get_error_code(), $said( $refused ) );
	$refused = Missions::repeat(
		$m_timed,
		array(
			'every'  => '1',
			'copies' => '13',
		),
		$ana
	);
	$check( 'and more than twelve copies', is_wp_error( $refused ) && 'packout_mission_repeat' === $refused->get_error_code(), $said( $refused ) );
	$undated = $plan( $alpha, 'Caltest some day', array( $a1 ), $ana );
	$refused = Missions::repeat(
		$undated,
		array(
			'every'  => '1',
			'copies' => '1',
		),
		$ana
	);
	$check( 'a mission with no day cannot be repeated', is_wp_error( $refused ) && 'packout_mission_repeat' === $refused->get_error_code(), $said( $refused ) );
	$refused = Missions::repeat(
		$m_timed,
		array(
			'every'  => '1',
			'copies' => '1',
		),
		$sam
	);
	$other   = Missions::repeat(
		$m_timed,
		array(
			'every'  => '1',
			'copies' => '1',
		),
		$bob
	);
	$check( 'only a person who may change a mission may repeat it', is_wp_error( $refused ) && 'packout_forbidden' === $refused->get_error_code() && is_wp_error( $other ) && 'packout_forbidden' === $other->get_error_code(), array( $said( $refused ), $said( $other ) ) );
	$count = count(
		get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => '_pos_team',
				'meta_value'  => (string) $alpha,
			)
		)
	); // phpcs:ignore WordPress.DB.SlowDBQuery -- Counting one organization's missions.
	$check( 'a refusal makes nothing', 5 + 3 + 1 + 1 === $count, $count );

	$renamed = Missions::save( $copies[0], array( 'title' => 'Caltest second visit ' . $packout_run ), $ana );
	$check( 'a copy can be changed before it has a Spot, and changing it changes only it', ! is_wp_error( $renamed ) && 'Caltest second visit ' . $packout_run === $title( $copies[0] ) && $title( $m_timed ) === $title( $copies[1] ), $said( $renamed ) );
	$started = Missions::set_status( $copies[0], 'in_progress' );
	$check( 'but not started', is_wp_error( $started ) && 'packout_mission_spots' === $started->get_error_code() && 'planned' === Missions::status( $copies[0] ), $said( $started ) );
	$given = Missions::save( $copies[0], array( 'spots' => array( $a3 ) ), $ana );
	$check( 'until it is given one', ! is_wp_error( $given ) && array( $a3 ) === Missions::spots( $copies[0] ) && true === Missions::set_status( $copies[0], 'in_progress' ), $said( $given ) );
	$empty = Missions::save(
		0,
		array(
			'title' => 'Caltest nothing to clean ' . $packout_run,
			'team'  => $alpha,
			'spots' => array(),
		),
		$ana
	);
	$check( 'a new mission still needs a Spot', is_wp_error( $empty ) && 'packout_mission_spots' === $empty->get_error_code(), $said( $empty ) );
	$emptied = Missions::save( $m_legacy, array( 'spots' => array() ), $ana );
	$check( 'and a mission that has Spots cannot be left with none', is_wp_error( $emptied ) && array( $a1 ) === Missions::spots( $m_legacy ), $said( $emptied ) );

	Workspace_Views::mission_pane( get_post( $m_timed ), $ana );
	$html = \PackOut\Core\App\Workspace_Lists::panes();
	$check( 'the mission\'s pane has the Repeat form for a person who may change it', false !== strpos( $html, 'value="mission_repeat"' ) && false !== strpos( $html, 'name="every"' ) && false !== strpos( $html, 'name="copies"' ) && false !== strpos( $html, 'name="carry[]" value="checklist"' ) && false !== strpos( $html, 'name="carry[]" value="campaign"' ) );
	// The library is in the plugin's lib directory, and the plugin's handlers are beside it.
	$handlers = require dirname( PACKOUT_CORE_DIR, 2 ) . '/src/handlers.php';
	$check( 'both acts of this phase have their code named', is_callable( $handlers['mission.repeat'] ?? null ) && is_callable( $handlers['calendar.view'] ?? null ) );

	/* ---------- What must not have changed ---------- */

	WP_CLI::log( 'Overdue missions and the sign-up cutoff' );
	$m_over  = $plan(
		$alpha,
		'Caltest should have happened',
		array( $a4 ),
		$ana,
		array(
			'start'      => $d( -1 ),
			'end'        => $d( -1 ),
			'start_time' => '09:00',
			'end_time'   => '10:00',
		)
	);
	$m_today = $plan(
		$alpha,
		'Caltest earlier today',
		array( $a4 ),
		$ana,
		array(
			'start'      => $d( 0 ),
			'end'        => $d( 0 ),
			'start_time' => '00:00',
			'end_time'   => '00:01',
		)
	);
	Overview::forget();
	$overdue = Overview::overdue_ids();
	$check( 'an open mission whose last day has passed is overdue, with a time or without', in_array( $m_over, $overdue, true ) );
	$check( 'one whose last day is today is not, whatever the hour', ! in_array( $m_today, $overdue, true ) );
	$check( 'a finished one never is, and nor is one that is still ahead', ! in_array( $m_past, $overdue, true ) && ! in_array( $m_timed, $overdue, true ) && ! in_array( $m_legacy, $overdue, true ) );
	$c_over          = Campaigns::save(
		0,
		array(
			'team'       => $alpha,
			'title'      => 'Caltest ended yesterday ' . $packout_run,
			'start'      => $d( -3 ),
			'end'        => $d( -1 ),
			'start_time' => '09:00',
			'end_time'   => '17:00',
			'public'     => true,
		),
		$ana
	);
	$c_today         = Campaigns::save(
		0,
		array(
			'team'       => $alpha,
			'title'      => 'Caltest ends today ' . $packout_run,
			'start'      => $d( -3 ),
			'end'        => $d( 0 ),
			'start_time' => '00:00',
			'end_time'   => '00:01',
			'public'     => true,
		),
		$ana
	);
	$packout_posts[] = $c_over;
	$packout_posts[] = $c_today;
	$check( 'a campaign takes sign-ups through its last day, and not after', is_int( $c_over ) && is_int( $c_today ) && ! Signups::takes( (int) $c_over ) && Signups::takes( (int) $c_today ) && Signups::takes( $campaign ), array( $said( $c_over ), $said( $c_today ) ) );
	$check( 'a mission takes them while it is open', Signups::takes( $m_timed ) && Signups::takes( $m_over ) && ! Signups::takes( $m_past ) && ! Signups::takes( $m_off ) && ! Signups::takes( $m_private ) );
} catch ( \Throwable $e ) {
	$packout_failures[] = $e->getMessage();
	WP_CLI::log( '  FAIL  ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	remove_filter( 'pre_wp_mail', $keep_mail, 99 );
	$_GET = array();
	if ( isset( $packout_site, $partner, $coordinator ) ) {
		Partners::save( $partner, $packout_site, $coordinator );
	}
	wp_set_current_user( 0 );
	foreach ( array_values( array_unique( array_filter( $packout_posts, 'is_int' ) ) ) as $post_id ) {
		if ( in_array( get_post_type( $post_id ), array( Schema::TEAM, Schema::PARTNER ), true ) ) {
			delete_option( 'packout_messages_' . $post_id );
			Overview::forget_scope(
				array(
					'kind' => Schema::PARTNER === get_post_type( $post_id ) ? 'partner' : 'team',
					'id'   => $post_id,
				)
			);
		}
		wp_delete_post( $post_id, true );
	}
	foreach ( $packout_users as $user_id ) {
		wp_delete_user( (int) $user_id );
	}
	update_option( 'timezone_string', $packout_zone[0] );
	update_option( 'gmt_offset', $packout_zone[1] );
	Overview::forget();
	Calendar::settle();
	Calendar::changed();
	Calendar::settle();
}

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( count( $packout_failures ) . ' checks failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
