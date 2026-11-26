<?php
/**
 * One person's way through a partner site, start to finish, on WordPress alone.
 *
 * A person signs up, starts an organization, adds a team, takes part in a
 * program, opens a campaign, and plans a mission. A second person signs up,
 * joins, and reports a Spot. A coordinator reviews it. The mission is done,
 * the impact is recorded, and the totals show it: the organization's, the
 * program's, the site's, and each person's own.
 *
 * Every step is taken through the code the plugin names for that act in
 * src/handlers.php, so a step whose code is missing or wrong fails here.
 *
 * Run inside WordPress, with the plugin active:
 *
 *     wp eval-file tests/journey.php
 *
 * The checks create their own people and records and delete them.
 *
 * @package PackOut_Partner
 */

use PackOut\Core\App\Accounts;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Partners;
use PackOut\Core\App\Programs;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Subteams;
use PackOut\Core\App\Teams;
use PackOut\Core\Capability_Map;
use PackOut\Partner\Report_Form;

require __DIR__ . '/support.php';

$packout_run   = strtolower( wp_generate_password( 6, false ) );
$packout_posts = array();
$packout_users = array();
$packout_mail  = array();
$packout_site  = array();

$code = static fn( $thing ): string => is_wp_error( $thing ) ? $thing->get_error_code() . ': ' . $thing->get_error_message() : '';

// The code the plugin names for an act.
$act = static function ( string $id, ...$args ) {
	$handler = Capability_Map::handler( $id );
	if ( ! is_callable( $handler ) ) {
		return new WP_Error( 'packout_no_handler', 'The plugin names no code for ' . $id . '.' );
	}
	return $handler( ...$args );
};
$as  = static function ( int $user_id ): void {
	wp_set_current_user( $user_id );
};

$keep_mail = static function ( $sent, $mail ) use ( &$packout_mail ) {
	unset( $sent );
	$packout_mail[] = array(
		'to'      => is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'],
		'subject' => (string) $mail['subject'],
		'message' => (string) $mail['message'],
	);
	return true;
};
add_filter( 'pre_wp_mail', $keep_mail, 99, 2 );
$mail_to = static function ( string $to ) use ( &$packout_mail ): ?array {
	foreach ( array_reverse( $packout_mail ) as $mail ) {
		if ( strtolower( $mail['to'] ) === strtolower( $to ) ) {
			return $mail;
		}
	}
	return null;
};

// A person signs up: they ask, they are sent a link, and they choose a password.
$sign_up = static function ( string $name ) use ( &$packout_users, $packout_run, $act, $mail_to, $as ): array {
	$email = 'pos-test-' . sanitize_key( $name ) . '-' . $packout_run . '@example.test';
	foreach ( array( 'signup-address-', 'signup-email-' . $email ) as $bucket ) {
		delete_transient( 'pos_limit_' . md5( $bucket ) );
	}
	$as( 0 );
	$asked    = Accounts::request( $name, $email );
	$mail     = $mail_to( $email );
	$token    = $mail && preg_match( '/pos_confirm=([A-Za-z0-9._%-]+)/', $mail['message'], $found ) ? rawurldecode( $found[1] ) : '';
	$password = wp_generate_password( 20 );
	$made     = '' !== $token ? $act( 'account.signup', $token, $password ) : new WP_Error( 'packout_no_link', 'No link was sent.' );
	$user     = is_array( $made ) ? (int) $made['user'] : 0;
	if ( $user > 0 ) {
		$packout_users[] = $user;
	}
	return array(
		'asked'    => $asked,
		'made'     => $made,
		'user'     => $user,
		'email'    => $email,
		'password' => $password,
	);
};

$call = static function ( string $method, string $route, array $params = array() ): array {
	$request = new WP_REST_Request( $method, '/packout/v1' . $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
};

// A page of the site over HTTP, as a browser asks for it, or a form sent to it.
$home     = untrailingslashit( home_url() );
$host     = (string) preg_replace( '#^https?://#', '', $home );
$loopback = untrailingslashit( (string) ( getenv( 'PACKOUT_LOOPBACK' ) ? getenv( 'PACKOUT_LOOPBACK' ) : $home ) );
$ask      = static function ( string $url, ?array $fields = null, array $cookies = array() ) use ( $home, $host, $loopback ): array {
	$args = array(
		'method'      => null === $fields ? 'GET' : 'POST',
		'headers'     => array( 'Host' => $host ),
		'redirection' => 0,
		'timeout'     => 30,
		'cookies'     => $cookies,
	);
	if ( null !== $fields ) {
		$args['body'] = $fields;
	}
	$response = wp_remote_request( $loopback . substr( $url, strlen( $home ) ), $args );
	// What another request stored is read fresh.
	wp_cache_flush();
	if ( is_wp_error( $response ) ) {
		return array( 0, '', $response->get_error_message() );
	}
	return array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_header( $response, 'location' ), (string) wp_remote_retrieve_body( $response ) );
};
$clean    = static fn( string $html ): bool => false === stripos( $html, '<b>Warning</b>' ) && false === stripos( $html, '<b>Notice</b>' ) && false === stripos( $html, '<b>Deprecated</b>' ) && false === stripos( $html, '<b>Fatal error</b>' ) && false === stripos( $html, 'There has been a critical error' );

try {
	$partner = Partners::site();
	if ( $partner <= 0 ) {
		WP_CLI::error( 'The site has no partner. Nothing can be checked.' );
	}
	$packout_site = array(
		'listed' => 'publish' === get_post_status( $partner ),
	);
	$admin        = Partners::lead( $partner );
	// The site says its partner is public, as the setup wizard will.
	Partners::make_site( $admin, array( 'listed' => true ) );
	$before = array(
		'site'   => Impact::totals(),
		'public' => Impact::totals( array( 'visible' => true ) ),
	);

	/* ---------- Signing up ---------- */

	WP_CLI::log( 'A person signs up' );
	$dana = $sign_up( 'Dana Founder' );
	packout_partner_check( 'they ask for an account and are sent a link', true === $dana['asked'] && null !== $mail_to( $dana['email'] ), $code( $dana['asked'] ) );
	packout_partner_check( 'the link and a password make the account', $dana['user'] > 0, $code( $dana['made'] ) );
	$dana_id = $dana['user'];
	packout_partner_check( 'they are a reporter, with no dashboard screens', in_array( 'pos_reporter', (array) get_userdata( $dana_id )->roles, true ) && Accounts::on_site( $dana_id ) && ! Accounts::has_dashboard( $dana_id ), get_userdata( $dana_id )->roles );
	$entered = $act( 'account.signin', $dana['email'], $dana['password'], false );
	packout_partner_check( 'they sign in with their address and password', $entered instanceof WP_User && $dana_id === $entered->ID, $code( $entered ) );
	packout_partner_check( 'and not with a wrong one', is_wp_error( $act( 'account.signin', $dana['email'], 'not-' . $dana['password'], false ) ) );
	$as( $dana_id );
	packout_partner_check( 'they say who they are', ! is_wp_error( $act( 'account.profile', $dana_id, array( 'name' => 'Dana Founder', 'about' => 'Hauls tires.' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Two short values.

	/* ---------- An organization ---------- */

	WP_CLI::log( 'They start an organization' );
	$org = $act(
		'org.propose',
		$dana_id,
		array(
			'name'        => 'Journey Road Crew ' . $packout_run,
			'description' => 'We keep the forest road clean.',
			'region'      => 'Verde Valley',
		)
	);
	packout_partner_check( 'it is proposed, and waits for a coordinator', is_int( $org ) && ! Teams::active( (int) $org ), $code( $org ) );
	$org             = (int) $org;
	$packout_posts[] = $org;
	packout_partner_check( 'until then they cannot plan a mission', is_wp_error( $act( 'mission.plan', 0, array( 'title' => 'Journey too soon', 'team' => $org ), $dana_id ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Two short values.
	packout_partner_check( 'they cannot approve it themself', is_wp_error( $act( 'org.approve', $org, $dana_id ) ) );
	$approved = $act( 'org.approve', $org, $admin );
	packout_partner_check( 'the site\'s administrator approves it', true === $approved && Teams::active( $org ), $code( $approved ) );
	packout_partner_check( 'they lead it, and now hold the Lead role', Teams::leads_team( $org, $dana_id ) && in_array( 'pos_lead', (array) get_userdata( $dana_id )->roles, true ), get_userdata( $dana_id )->roles );
	packout_partner_check( 'it is the site\'s partner\'s, with nothing more to do', Partners::of_team( $org ) === $partner && in_array( $org, Partners::organization_ids( $partner ), true ) );
	$listed = $act( 'org.profile', $org, array( 'listed' => true, 'description' => 'We keep Forest Road 9 clean.' ), $dana_id ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Two short values.
	packout_partner_check( 'they list it publicly', ! is_wp_error( $listed ) && 'publish' === get_post_status( $org ), $code( $listed ) );

	WP_CLI::log( 'They add a team' );
	$team = $act( 'team.manage', $org, 0, array( 'name' => 'Journey North ' . $packout_run ), $dana_id );
	packout_partner_check( 'the organization has a team', is_int( $team ) && $team > 0 && null !== Subteams::get( $org, $team ), $code( $team ) );
	$team = (int) $team;

	/* ---------- A second person ---------- */

	WP_CLI::log( 'A second person signs up and joins' );
	$eli    = $sign_up( 'Eli Member' );
	$eli_id = $eli['user'];
	packout_partner_check( 'they have an account', $eli_id > 0, $code( $eli['made'] ) );
	$asked = $act( 'org.join', $org, $eli_id );
	packout_partner_check( 'they ask to join the organization', in_array( $asked, array( 'requested', 'joined' ), true ), $code( $asked ) );
	if ( 'requested' === $asked ) {
		$answered = $act( 'org.join.answer', $org, $eli_id, true, $dana_id );
		packout_partner_check( 'its lead lets them in', true === $answered, $code( $answered ) );
	}
	packout_partner_check( 'they are a member', in_array( $eli_id, array_map( 'intval', Teams::members( $org ) ), true ) );
	$placed = $act( 'team.run', $org, $team, $eli_id, true, $dana_id );
	packout_partner_check( 'and are put on the team', true === $placed && Subteams::includes( $org, $team, $eli_id ), $code( $placed ) );

	/* ---------- A program, a campaign ---------- */

	WP_CLI::log( 'A program and a campaign' );
	$today   = wp_date( 'Y-m-d' );
	$later   = wp_date( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );
	$program = $act(
		'program.manage',
		0,
		array(
			'owner_kind' => 'partner',
			'owner'      => $partner,
			'title'      => 'Journey Adopt-a-Road ' . $packout_run,
			'start'      => $today,
			'end'        => $later,
			'orgs'       => array( $org ),
			'public'     => true,
		),
		$admin
	);
	packout_partner_check( 'the site opens a program, and the organization takes part', is_int( $program ) && in_array( $org, Programs::taking_part( (int) $program ), true ), $code( $program ) );
	$program         = (int) $program;
	$packout_posts[] = $program;
	$campaign        = $act(
		'campaign.plan',
		0,
		array(
			'team'     => $org,
			'title'    => 'Journey spring sweep ' . $packout_run,
			'goal'     => 'Every turnout on Forest Road 9.',
			'start'    => $today,
			'end'      => $later,
			'subteams' => array( $team ),
			'public'   => true,
			'program'  => $program,
		),
		$dana_id
	);
	packout_partner_check( 'the lead opens a campaign under the program', is_int( $campaign ) && Programs::of_campaign( (int) $campaign ) === $program, $code( $campaign ) );
	$campaign        = (int) $campaign;
	$packout_posts[] = $campaign;

	/* ---------- A Spot ---------- */

	WP_CLI::log( 'A Spot is reported and reviewed' );
	$page            = (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Journey report ' . $packout_run,
			'post_content' => '<!-- wp:packout/report-prompt {"showForm":true} /--><!-- wp:packout/map /--><!-- wp:packout/impact /-->',
		)
	);
	$packout_posts[] = $page;
	$expires         = time() + HOUR_IN_SECONDS;
	$cookies         = array( new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $eli_id, $expires, 'logged_in', WP_Session_Tokens::get_instance( $eli_id )->create( $expires ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One cookie.
	$form            = $ask( (string) get_permalink( $page ), null, $cookies );
	$fields          = array();
	foreach ( array( Report_Form::FIELD . '_nonce', Report_Form::FIELD . '_opened' ) as $name ) {
		$fields[ $name ] = preg_match( '#name="' . preg_quote( $name, '#' ) . '" value="([^"]*)"#', $form[2], $match ) ? html_entity_decode( $match[1] ) : '';
	}
	packout_partner_check( 'the member opens the report page: the form, the map, and the totals, with no PHP warning', 200 === $form[0] && '' !== $fields[ Report_Form::FIELD . '_nonce' ] && false !== strpos( $form[2], 'pos-map' ) && false !== strpos( $form[2], 'pos-impact' ) && $clean( $form[2] ), $form[0] );
	// A person takes a few seconds to write a report.
	sleep( 4 );
	$sent  = $ask(
		(string) get_permalink( $page ),
		$fields + array(
			'packout_credit_team' => (string) $org,
			Report_Form::FIELD    => array(
				'category'    => 'cleanup-waste',
				'description' => 'Journey ' . $packout_run . ' tires at the mile 9 turnout.',
				'landmark'    => 'Mile 9 turnout',
				'latitude'    => '34.7102',
				'longitude'   => '-111.6511',
			),
		),
		$cookies
	);
	$found = get_posts(
		array(
			'post_type'   => Schema::SPOT,
			'post_status' => 'any',
			'author'      => $eli_id,
			'numberposts' => 5,
		)
	);
	packout_partner_check( 'they report a Spot for the organization', 303 === $sent[0] && 1 === count( $found ) && 'pending' === $found[0]->post_status && Spots::credit( $found[0]->ID )['team'] === $org, array( $sent[0], count( $found ) ) );
	$spot            = $found ? (int) $found[0]->ID : 0;
	$packout_posts[] = $spot;
	$as( 0 );
	list( , $data ) = $call( 'GET', '/spots' );
	packout_partner_check( 'it is not on the map yet', ! in_array( $spot, wp_list_pluck( $data['spots'] ?? array(), 'id' ), true ) );
	$as( $admin );
	packout_partner_check( 'a coordinator reviews it and puts it on the map', true === $act( 'spot.review', $spot ) && 'publish' === get_post_status( $spot ) );
	$as( 0 );
	list( , $data ) = $call( 'GET', '/spots' );
	packout_partner_check( 'and there it is, for a visitor', in_array( $spot, wp_list_pluck( $data['spots'] ?? array(), 'id' ), true ) );

	/* ---------- A mission ---------- */

	WP_CLI::log( 'A mission is planned and done' );
	$as( $dana_id );
	$mission = $act(
		'mission.plan',
		0,
		array(
			'title'      => 'Journey mile 9 day ' . $packout_run,
			'objective'  => 'Haul the tires out.',
			'team'       => $org,
			'spots'      => array( $spot ),
			'start'      => $today,
			'end'        => $today,
			'start_time' => '08:00',
			'end_time'   => '12:00',
			'campaign'   => $campaign,
			'subteams'   => array( $team ),
			'public'     => true,
		),
		$dana_id
	);
	packout_partner_check( 'the lead plans a mission for the Spot, in the campaign, for the team', is_int( $mission ) && array( $spot ) === Missions::spots( (int) $mission ) && 'planned' === Spots::field( $spot, 'status' ), $code( $mission ) );
	$mission         = (int) $mission;
	$packout_posts[] = $mission;
	packout_partner_check( 'it is the program\'s, through its campaign', Programs::of_mission( $mission ) === $program );
	$signed = $act( 'event.signup', $mission, $eli_id, $team );
	packout_partner_check( 'the member signs up for it', in_array( $signed, array( 'signed', 'joined' ), true ), $code( $signed ) );
	$events = $act( 'calendar.view', array( 'from' => $today, 'to' => $later ), 0 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Two short values.
	packout_partner_check( 'the calendar shows a visitor the mission, the campaign, and the program', is_array( $events ) && array() === array_diff( array( $mission, $campaign, $program ), wp_list_pluck( $events['events'] ?? $events, 'id' ) ), is_array( $events ) ? wp_list_pluck( $events['events'] ?? $events, 'id' ) : $code( $events ) );

	$started = $act( 'mission.start', $mission, 'in_progress' );
	packout_partner_check( 'the lead starts it', true === $started && 'in_progress' === Missions::status( $mission ), $code( $started ) );
	$worked = $act(
		'mission.work',
		$mission,
		$eli_id,
		array(
			'revision' => Missions::revision( $mission ),
			'outcomes' => array( $spot => 'cleaned' ),
		)
	);
	packout_partner_check( 'the member marks the Spot cleaned', is_array( $worked ) && 1 === $worked['applied'] && 'cleaned' === Spots::field( $spot, 'status' ), is_array( $worked ) ? $worked : $code( $worked ) );
	// Who may end a mission is decided where the request arrives, so the request is made.
	$as( $eli_id );
	list( $status ) = $call( 'POST', '/missions/' . $mission . '/status', array( 'status' => 'completed' ) );
	packout_partner_check( 'the member cannot complete the mission', 403 === $status && 'in_progress' === Missions::status( $mission ), $status );
	$as( $dana_id );
	list( $status ) = $call( 'POST', '/missions/' . $mission . '/status', array( 'status' => 'completed' ) );
	packout_partner_check( 'the lead completes it', 200 === $status && 'completed' === Missions::status( $mission ), $status );
	packout_partner_check( 'and a mission that has ended is not ended twice', is_wp_error( $act( 'mission.complete', $mission, 'completed' ) ) );

	/* ---------- Impact ---------- */

	WP_CLI::log( 'The impact is recorded, and the totals show it' );
	packout_partner_check( 'the member cannot record the mission\'s impact', is_wp_error( $act( 'impact.record', $spot, array( 'pounds' => 999 ), $eli_id ) ) && null === Impact::current( $spot ) );
	$record = $act(
		'impact.record',
		$spot,
		array(
			'narrative'     => 'Hauled six tires out of the wash.',
			'hours'         => 6,
			'people'        => 2,
			'persons'       => array( $dana_id, $eli_id ),
			'bags'          => 4,
			'bags_source'   => 'self_reported',
			'pounds'        => 180,
			'pounds_source' => 'measured',
		),
		$dana_id
	);
	packout_partner_check( 'the lead records it', is_int( $record ) && $record > 0, $code( $record ) );
	$record          = (int) $record;
	$packout_posts[] = $record;
	$summary         = Impact::summary( $record );
	packout_partner_check( 'its one-line summary says which figure was measured', false !== strpos( $summary, 'measured' ) && false !== strpos( $summary, 'self-reported' ), $summary );

	$of_org = $act( 'impact.totals', array( 'team' => $org ) );
	packout_partner_check( 'the organization\'s totals hold it', 180.0 === (float) $of_org['outputs']['pounds_measured'] && 4 === (int) $of_org['outputs']['bags_self_reported'] && 1 === (int) $of_org['outputs']['spots'] && 1 === (int) $of_org['effort']['missions'], $of_org );
	$of_program = Programs::totals( $program );
	packout_partner_check( 'so do the program\'s', 180.0 === (float) $of_program['outputs']['pounds_measured'] && 1 === (int) $of_program['outputs']['spots'], $of_program['outputs'] );
	$progress = Programs::progress( $program );
	packout_partner_check( 'and the program counts one campaign, one mission completed, one Spot cleaned', 1 === $progress['campaigns'] && 1 === $progress['missions'] && 1 === $progress['completed'] && 1 === $progress['cleaned'], $progress );
	$site = $act( 'impact.totals', array() );
	packout_partner_check( 'the site\'s totals grew by it', 180.0 === (float) $site['outputs']['pounds_measured'] - (float) $before['site']['outputs']['pounds_measured'] && 1 === (int) $site['outputs']['spots'] - (int) $before['site']['outputs']['spots'] );
	packout_partner_check( 'the public totals did not, until a coordinator says so', $act( 'impact.totals', array( 'visible' => true ) ) === $before['public'] );

	packout_partner_check( 'the member cannot verify it', is_wp_error( $act( 'impact.verify', $record, $eli_id ) ) && ! Impact::verified( $record ) );
	$verified = $act( 'impact.verify', $record, $admin );
	packout_partner_check( 'a coordinator verifies it', true === $verified && Impact::verified( $record ), $code( $verified ) );
	$counted = $act( 'impact.count', $record, true, $admin );
	$public  = $act( 'impact.totals', array( 'visible' => true ) );
	packout_partner_check( 'and counts it publicly', true === $counted && 180.0 === (float) $public['outputs']['pounds_measured'] - (float) $before['public']['outputs']['pounds_measured'] && 6.0 === (float) $public['effort']['hours_verified'] - (float) $before['public']['effort']['hours_verified'], $code( $counted ) );

	$own = $act( 'impact.own', $eli_id );
	packout_partner_check( 'the member\'s own record shows the Spot they reported and the cleanup they were part of', in_array( $spot, wp_list_pluck( $own['spots'], 'ID' ), true ) && in_array( $record, wp_list_pluck( $own['cleanups'], 'ID' ), true ) && in_array( $mission, wp_list_pluck( $own['missions'], 'ID' ), true ), array_map( static fn( $posts ) => is_array( $posts ) ? wp_list_pluck( $posts, 'ID' ) : $posts, $own ) );

	/* ---------- What a visitor sees ---------- */

	WP_CLI::log( 'What a visitor sees' );
	$pages = array(
		'the Spot\'s page'         => array( $spot, 'Mile 9 turnout' ),
		'the organization\'s page' => array( $org, 'Journey Road Crew ' . $packout_run ),
		'the mission\'s page'      => array( $mission, 'Journey mile 9 day ' . $packout_run ),
		'the campaign\'s page'     => array( $campaign, 'Journey spring sweep ' . $packout_run ),
		'the program\'s page'      => array( $program, 'Journey Adopt-a-Road ' . $packout_run ),
		'the partner\'s page'      => array( $partner, 'Journey Road Crew ' . $packout_run ),
	);
	foreach ( $pages as $name => $expect ) {
		$got = $ask( (string) get_permalink( $expect[0] ) );
		packout_partner_check( $name . ' opens, says what it should, and draws no PHP warning', 200 === $got[0] && false !== strpos( $got[2], esc_html( $expect[1] ) ) && $clean( $got[2] ), array( $got[0], get_permalink( $expect[0] ) ) );
	}
	$got = $ask( (string) get_permalink( $spot ) );
	packout_partner_check( 'the Spot\'s page says it is cleaned and who reported it', false !== strpos( $got[2], 'Cleaned' ) && false !== strpos( $got[2], 'Journey Road Crew ' . $packout_run ) );
	$got = $ask( (string) get_permalink( $page ) );
	packout_partner_check( 'the totals on a page show the 180 pounds that were measured', 200 === $got[0] && 1 === preg_match( '#pos-impact.*?180#s', $got[2] ) && $clean( $got[2] ), $got[0] );
	$got = $ask( rest_url( 'packout/v1/calendar' ) . '?from=' . $today . '&to=' . $later );
	packout_partner_check( 'the calendar route answers a visitor with the mission', 200 === $got[0] && false !== strpos( $got[2], 'Journey mile 9 day ' . $packout_run ), $got[0] );
} catch ( \Throwable $e ) {
	packout_partner_check( $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine(), false );
} finally {
	remove_filter( 'pre_wp_mail', $keep_mail, 99 );
	wp_cache_flush();
	wp_set_current_user( 0 );
	foreach ( array_reverse( array_unique( array_filter( $packout_posts ) ) ) as $post_id ) {
		if ( Schema::TEAM === get_post_type( $post_id ) ) {
			delete_option( 'packout_messages_' . $post_id );
		}
		wp_delete_post( $post_id, true );
	}
	foreach ( get_posts( array( 'post_type' => Schema::IMPACT, 'post_status' => 'any', 'author__in' => $packout_users ? $packout_users : array( 0 ), 'numberposts' => 50, 'fields' => 'ids' ) ) as $left ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One short query.
		wp_delete_post( (int) $left, true );
	}
	if ( $packout_site && Partners::site() > 0 ) {
		Partners::make_site( Partners::lead( Partners::site() ), $packout_site );
	}
	foreach ( $packout_users as $user_id ) {
		WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		wp_delete_user( $user_id );
	}
}

packout_partner_finish();
