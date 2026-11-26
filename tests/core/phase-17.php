<?php
/**
 * Phase 17 acceptance checks: the overview of one partner or one organization.
 *
 * The page, both routes, and the export of a record are open to the people
 * who lead it and to nobody else. Every figure is the record's own.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-17.php
 *
 * The checks create their own users and records and delete them.
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Blocks;
use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Missions_Screen;
use PackOut\Core\App\Overview;
use PackOut\Core\App\Overview_Page;
use PackOut\Core\App\Overview_Report;
use PackOut\Core\App\Overview_Scope;
use PackOut\Core\App\Partners;
use PackOut\Spot\Partners_Screen;
use PackOut\Core\App\Programs;
use PackOut\Core\App\Programs_Screen;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Subteams;
use PackOut\Core\App\Teams;
use PackOut\Core\App\Teams_Screen;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

// What only packout.spot does is checked there. On a partner site it is left out, and the run says so.
$hub  = \PackOut\Core\App\App::is_hub();
$skip = static function ( string $what ): void {
	WP_CLI::log( '  --  ' . $what );
};

if ( ! $hub ) {
	$skip( 'The overview of one partner among several is on packout.spot. On a partner site the partner’s overview is the site’s, and its plugin checks it with an organization’s.' );
	WP_CLI::success( '0 checks passed.' );
	return;
}

$packout_failures = array();
$packout_passed   = 0;
$packout_posts    = array();
$packout_users    = array();
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

$said = static fn( $thing ): string => is_wp_error( $thing ) ? $thing->get_error_message() : ( is_scalar( $thing ) ? (string) $thing : (string) wp_json_encode( $thing ) );

$user = static function ( string $role, string $name ) use ( &$packout_users, $packout_run ): int {
	$login           = 'pos-test-' . sanitize_key( $name ) . '-' . $packout_run;
	$id              = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => $login . '@example.test',
			'display_name' => $name . ' Scopeperson',
			'role'         => $role,
		)
	);
	$packout_users[] = $id;
	return $id;
};

// Mail stays in memory. Nothing leaves the machine.
$keep_mail = static fn() => true;
add_filter( 'pre_wp_mail', $keep_mail, 99 );

// One of the two routes, as the page's script asks for it.
$call     = static function ( string $route, array $params = array() ) {
	$request = new WP_REST_Request( 'GET', '/packout/v1/overview' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
};
$scope_of = static fn( string $kind, int $id ): array => array(
	'kind' => $kind,
	'id'   => $id,
);

// An admin screen as a person sees it. A refusal is the word "refused".
$die    = static function (): callable {
	return static function (): void {
		throw new RuntimeException( 'refused' );
	};
};
$screen = static function ( callable $render, int $who, array $get = array() ) use ( $as, $die ): string {
	$as( $who );
	$_GET = array_map( 'strval', $get );
	add_filter( 'wp_die_handler', $die, 99 );
	ob_start();
	try {
		$render();
		$html = (string) ob_get_clean();
	} catch ( RuntimeException $e ) {
		ob_end_clean();
		$html = $e->getMessage();
	}
	remove_filter( 'wp_die_handler', $die, 99 );
	$_GET = array();
	return $html;
};

try {
	Capabilities::install();
	Schema::register();

	$coordinator = $user( 'pos_coordinator', 'Coordinator' );
	$ana         = $user( 'pos_lead', 'Ana' );
	$bob         = $user( 'pos_lead', 'Bob' );
	$cat         = $user( 'pos_lead', 'Cat' );
	$mem         = $user( 'pos_reporter', 'Mem' );
	$out         = $user( 'pos_reporter', 'Out' );

	$org  = static function ( string $name, int $lead ) use ( &$packout_posts, $coordinator, $packout_run, $as ): int {
		$as( $coordinator );
		$id = Teams::save(
			0,
			array(
				'name'        => $name . ' ' . $packout_run,
				'description' => $name . ' looks after its own stretch.',
				'lead'        => $lead,
				'listed'      => true,
			)
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $name . ': ' . $id->get_error_message() );
		}
		$packout_posts[] = $id;
		return (int) $id;
	};
	$spot = static function ( string $what, float $lat, int $by ) use ( &$packout_posts, $packout_run ): int {
		$id              = Spots::create(
			array(
				'category'    => 'cleanup-waste',
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
	$plan = static fn( int $team, string $title, array $spots, array $more = array() ): array => $more + array(
		'title'  => $title . ' ' . $packout_run,
		'team'   => $team,
		'spots'  => $spots,
		'start'  => wp_date( 'Y-m-d', strtotime( '+20 days' ) ),
		'end'    => wp_date( 'Y-m-d', strtotime( '+20 days' ) ),
		'public' => true,
	);
	// A mission cleans a Spot and its lead writes the record.
	$clean = static function ( int $spot_id, int $mission, int $who, array $more = array() ) use ( &$packout_posts, $packout_run ) {
		Spots::mark_cleaned( $spot_id, $who, $mission );
		$record = Impact::save_cleanup(
			$spot_id,
			$more + array(
				'narrative' => 'Scopetest haul. ' . $packout_run,
				'hours'     => 2,
				'people'    => 2,
			),
			$who
		);
		if ( is_int( $record ) ) {
			$packout_posts[] = $record;
		}
		return $record;
	};

	$partners_before = (int) ( Overview::now()['platform']['partners'] ?? 0 );
	$waiting_before  = (int) Overview::queue( 'partners' )['count'];

	$alpha = $org( 'Scope Alpha', $ana );
	$beta  = $org( 'Scope Beta', $bob );
	$gamma = $org( 'Scope Gamma', $cat );
	Teams::add_member( $alpha, get_userdata( $mem )->user_login );

	$partner_name    = 'Scope Council ' . $packout_run;
	$partner         = Partners::save(
		0,
		array(
			'name'        => $partner_name,
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
	Partners::ask( $beta, $partner, $bob );
	Partners::answer( $beta, $partner, true, $ana );
	if ( array( $alpha, $beta ) !== array_values( array_intersect( array( $alpha, $beta ), Partners::organization_ids( $partner ) ) ) ) {
		WP_CLI::error( 'The fixture partner does not have its two organizations.' );
	}

	$crew_name = 'Scope Wash Crew ' . $packout_run;
	$crew      = Subteams::save( $alpha, 0, array( 'name' => $crew_name ), $ana );
	$idle_crew = Subteams::save( $alpha, 0, array( 'name' => 'Scope Idle Crew ' . $packout_run ), $ana );

	$a1 = $spot( 'Scopetest tires at the turnout.', 34.71, $mem );
	$a2 = $spot( 'Scopetest cans in the fire ring.', 34.72, $mem );
	$a3 = $spot( 'Scopetest glass by the gate.', 34.73, $mem );
	$a4 = $spot( 'Scopetest couch in the wash.', 34.74, $mem );
	$b1 = $spot( 'Scopetest wire by the tank.', 34.75, $out );
	$g1 = $spot( 'Scopetest drum at the pit.', 34.76, $out );
	$g2 = $spot( 'Scopetest pallet at the ford.', 34.77, $out );

	$m_alpha         = Missions::save( 0, $plan( $alpha, 'Scopetest alpha day', array( $a1, $a2, $a3, $a4 ), array( 'subteams' => array( (int) $crew ) ) ), $ana );
	$m_beta          = Missions::save( 0, $plan( $beta, 'Scopetest beta day', array( $b1 ) ), $bob );
	$m_gamma         = Missions::save( 0, $plan( $gamma, 'Scopetest gamma day', array( $g1, $g2 ) ), $cat );
	$packout_posts[] = $m_alpha;
	$packout_posts[] = $m_beta;
	$packout_posts[] = $m_gamma;
	foreach ( array( $m_alpha, $m_beta, $m_gamma ) as $mission ) {
		if ( is_wp_error( $mission ) ) {
			WP_CLI::error( 'Mission: ' . $mission->get_error_message() );
		}
		Missions::set_status( (int) $mission, 'in_progress' );
	}
	$m_alpha = (int) $m_alpha;
	$m_beta  = (int) $m_beta;
	$m_gamma = (int) $m_gamma;

	// Alpha cleans three of its four Spots, Beta its one, and Gamma one of two.
	$r_a1 = $clean(
		$a1,
		$m_alpha,
		$ana,
		array(
			'pounds'        => 120,
			'pounds_source' => 'measured',
		)
	);
	$r_a2 = $clean(
		$a2,
		$m_alpha,
		$ana,
		array(
			'pounds'        => 40,
			'pounds_source' => 'self_reported',
		)
	);
	$r_a3 = $clean( $a3, $m_alpha, $ana );
	$r_b1 = $clean(
		$b1,
		$m_beta,
		$bob,
		array(
			'pounds'        => 15,
			'pounds_source' => 'measured',
		)
	);
	$r_g1 = $clean(
		$g1,
		$m_gamma,
		$cat,
		array(
			'pounds'        => 300,
			'pounds_source' => 'measured',
		)
	);
	Impact::verify( (int) $r_a1, $coordinator );
	Impact::set_visible( (int) $r_a1, true, $coordinator );

	$s_alpha   = $scope_of( 'team', $alpha );
	$s_beta    = $scope_of( 'team', $beta );
	$s_gamma   = $scope_of( 'team', $gamma );
	$s_partner = $scope_of( 'partner', $partner );

	WP_CLI::log( 'Who may open an overview' );
	$check( 'a lead may open their organization\'s overview', Overview::can_open( 'team', $alpha, $ana ) && Overview::can_open( 'team', $gamma, $cat ) );
	$check( 'and not another organization\'s', ! Overview::can_open( 'team', $gamma, $ana ) && ! Overview::can_open( 'team', $alpha, $cat ) );
	$check( 'a partner\'s lead may open the partner\'s and each of its organizations\'', Overview::can_open( 'partner', $partner, $ana ) && Overview::can_open( 'team', $alpha, $ana ) && Overview::can_open( 'team', $beta, $ana ) );
	$check( 'the lead of an organization under a partner may not open the partner\'s, or its other organization\'s', ! Overview::can_open( 'partner', $partner, $bob ) && ! Overview::can_open( 'team', $alpha, $bob ) && Overview::can_open( 'team', $beta, $bob ) );
	$check( 'a coordinator may open any', Overview::can_open( 'team', $alpha, $coordinator ) && Overview::can_open( 'team', $gamma, $coordinator ) && Overview::can_open( 'partner', $partner, $coordinator ) );
	$check( 'a member may open none', ! Overview::can_open( 'team', $alpha, $mem ) && ! Overview::can_open( 'partner', $partner, $mem ) );
	$check( 'nor may a person who is signed out', ! Overview::can_open( 'team', $alpha, 0 ) );

	WP_CLI::log( 'The routes' );
	$as( $ana );
	list( $code, $body ) = $call( '/now', $s_alpha );
	$check( 'a lead reads overview/now for their organization', 200 === $code && ( $body['scope'] ?? null ) === $s_alpha && isset( $body['waiting']['requests'], $body['levers']['a'], $body['platform']['members'] ), array( $code, $body ) );
	list( $code, $body ) = $call( '/period', $s_alpha + array( 'days' => 30 ) );
	$check( 'and overview/period', 200 === $code && ( $body['scope'] ?? null ) === $s_alpha && isset( $body['a']['current'], $body['e']['reading'], $body['series'] ), array( $code, array_keys( (array) $body ) ) );
	list( $code ) = $call( '/now', $s_gamma );
	$check( 'a lead is denied overview/now for another organization', $code >= 400, $code );
	list( $code ) = $call( '/period', $s_gamma + array( 'days' => 30 ) );
	$check( 'and overview/period', $code >= 400, $code );
	list( $code ) = $call( '/now' );
	$check( 'a lead is still denied the platform\'s', $code >= 400, $code );
	list( $code, $body ) = $call( '/now', $s_beta );
	$check( 'a partner\'s lead reads an organization under it', 200 === $code && ( $body['scope'] ?? null ) === $s_beta, $code );
	list( $code, $body ) = $call( '/now', $s_partner );
	$check( 'and the partner\'s, which has the queue of organizations asking to join', 200 === $code && isset( $body['waiting']['claims'], $body['platform']['organizations'], $body['platform']['programs'] ), array( $code, array_keys( (array) ( $body['waiting'] ?? array() ) ) ) );
	list( $code ) = $call( '/now', $scope_of( 'team', $partner ) );
	$check( 'a scope that names no such record is refused, and is not answered with the platform\'s', $code >= 400, $code );
	list( $code ) = $call( '/now', $scope_of( 'everything', $alpha ) );
	$check( 'and so is a kind that does not exist', $code >= 400, $code );
	$as( $coordinator );
	list( $code ) = $call(
		'/now',
		array(
			'kind' => 'everything',
			'id'   => 0,
		)
	);
	$check( 'for a coordinator too', $code >= 400, $code );
	list( $code ) = $call( '/period', $s_gamma + array( 'days' => 7 ) );
	$check( 'a coordinator reads any organization\'s', 200 === $code, $code );
	$as( $mem );
	list( $code ) = $call( '/now', $s_alpha );
	$check( 'a member is denied overview/now for their own organization', $code >= 400, $code );
	list( $code ) = $call( '/period', $s_alpha + array( 'days' => 30 ) );
	$check( 'and overview/period', $code >= 400, $code );
	$as( 0 );
	list( $code ) = $call( '/now', $s_alpha );
	$check( 'a visitor is denied', $code >= 400, $code );

	WP_CLI::log( 'The page' );
	$html = $screen( static fn() => Overview_Page::render( 'team', $alpha ), $ana );
	$check( 'a lead\'s page names the organization and says what waits on them', false !== strpos( $html, 'Scope Alpha' ) && false !== strpos( $html, 'Waiting on you' ) && false === strpos( $html, 'Waiting on a coordinator' ) );
	$check( 'it tells the script which record it is', false !== strpos( $html, 'data-packout-scope="kind=team&amp;id=' . $alpha . '"' ), substr( $html, 0, 400 ) );
	$check( 'it carries its figures and no skeleton', false !== strpos( $html, 'id="packout-overview-now"' ) && false !== strpos( $html, 'id="packout-overview-period"' ) && false === strpos( $html, 'skeleton' ) );
	$check( 'it asks the five questions', 5 === preg_match_all( '/class="[^"]*packout-overview__outcome[ "]/', $html ) || 5 === substr_count( $html, 'packout-overview__reading' ), array( preg_match_all( '/class="[^"]*packout-overview__outcome[ "]/', $html ), substr_count( $html, 'packout-overview__reading' ) ) );
	$check( 'it offers the organization\'s report', false !== strpos( $html, 'name="kind" value="team"' ) && false !== strpos( $html, 'name="id" value="' . $alpha . '"' ) );
	$check( 'it names no other organization', false === strpos( $html, 'Scope Gamma' ) && false === strpos( $html, 'Scope Beta' ) );
	$check( 'another organization\'s page is refused', 'refused' === $screen( static fn() => Overview_Page::render( 'team', $gamma ), $ana ) );
	$check( 'a member is refused their organization\'s page', 'refused' === $screen( static fn() => Overview_Page::render( 'team', $alpha ), $mem ) );
	$html = $screen( static fn() => Overview_Page::render( 'partner', $partner ), $ana );
	$check( 'the partner\'s page names the partner and counts its organizations', false !== strpos( $html, $partner_name ) && false !== strpos( $html, 'Organizations asking to join' ) && false !== strpos( $html, 'data-packout-scope="kind=partner&amp;id=' . $partner . '"' ) );
	$check( 'the lead of an organization under it is refused the partner\'s page', 'refused' === $screen( static fn() => Overview_Page::render( 'partner', $partner ), $bob ) );
	$html = $screen( static fn() => Teams_Screen::render(), $ana, array( 'overview' => $alpha ) );
	$check( 'the Organizations screen opens it at overview=', false !== strpos( $html, 'packout-overview--scoped' ) && false !== strpos( $html, 'Scope Alpha' ) );
	$html = $screen( static fn() => Partners_Screen::render(), $coordinator, array( 'overview' => $partner ) );
	$check( 'and the Partners screen opens a partner\'s', false !== strpos( $html, 'packout-overview--scoped' ) && false !== strpos( $html, $partner_name ) );
	$check( 'a lead of one organization lands on its overview', Overview_Page::url( 'team', $gamma ) === Overview_Page::home( $cat ) && Overview_Page::url( 'team', $beta ) === Overview_Page::home( $bob ), array( Overview_Page::home( $cat ), Overview_Page::home( $bob ) ) );
	$check( 'a person who leads a partner lands on a list or an overview of their own, never the platform\'s', false === strpos( Overview_Page::home( $ana ), 'page=packout-spot' ) && false === strpos( Overview_Page::home( $mem ), 'page=packout-spot' ), array( Overview_Page::home( $ana ), Overview_Page::home( $mem ) ) );

	WP_CLI::log( 'What a record is counted on' );
	$check( 'an organization\'s scope is itself', array( $alpha ) === Overview::scope_ids( $s_alpha ) );
	$ids = Overview::scope_ids( $s_partner );
	sort( $ids );
	$check( 'a partner\'s is its organizations', array( $alpha, $beta ) === $ids, $ids );
	$check( 'an organization with no partner is in no partner\'s scope', ! in_array( $gamma, $ids, true ) && 0 === Partners::of_team( $gamma ) );

	$p_alpha   = Overview::period( 30, $s_alpha );
	$p_beta    = Overview::period( 30, $s_beta );
	$p_gamma   = Overview::period( 30, $s_gamma );
	$p_partner = Overview::period( 30, $s_partner );
	$from      = (string) $p_alpha['from'];
	$to        = (string) $p_alpha['to'];

	WP_CLI::log( 'A. Does a report lead to action?' );
	$a = $p_alpha['a']['current'];
	$check( 'four Spots joined the organization\'s missions and three were cleaned', 4 === $a['reported'] && 3 === $a['cleaned'] && 1 === $a['net_open'] && 0 === $a['median_days'], $a );
	$check( 'the reading says so, and that the open pile grew', false !== strpos( $p_alpha['a']['reading'], '4 Spots joined a mission' ) && false !== strpos( $p_alpha['a']['reading'], 'grew by 1' ), $p_alpha['a']['reading'] );
	$check( 'the other organization under the partner has its own', 1 === $p_beta['a']['current']['reported'] && 1 === $p_beta['a']['current']['cleaned'], $p_beta['a']['current'] );
	$check( 'a partner\'s cleaned count is the sum of its organizations\'', $p_alpha['a']['current']['cleaned'] + $p_beta['a']['current']['cleaned'] === $p_partner['a']['current']['cleaned'] && 4 === $p_partner['a']['current']['cleaned'], $p_partner['a']['current'] );
	$check( 'and so is what it took on', 5 === $p_partner['a']['current']['reported'], $p_partner['a']['current'] );
	$check( 'an organization with no partner is counted on its own', 2 === $p_gamma['a']['current']['reported'] && 1 === $p_gamma['a']['current']['cleaned'], $p_gamma['a']['current'] );
	$n_alpha = Overview::now( $s_alpha );
	$check( 'the stages are the organization\'s Spots', 3 === (int) $n_alpha['stages']['cleaned'] && 1 === (int) $n_alpha['stages']['in_progress'] && 4 === (int) $n_alpha['stage_total'], $n_alpha['stages'] );
	$check( 'and its open missions', 1 === (int) $n_alpha['missions']['count'] && 1 === (int) $n_alpha['missions']['in_progress'], $n_alpha['missions'] );
	$series = array_sum( wp_list_pluck( $p_alpha['series'], 'cleanups' ) );
	$check( 'the chart counts the same cleanups', 3 === (int) $series && 'day' === $p_alpha['bucket'], array( $series, $p_alpha['bucket'] ) );

	WP_CLI::log( 'C. Is the work shared?' );
	$c = $p_partner['c']['current'];
	$check( 'a partner with organizations at three and one cleanups reads three of four', 2 === $c['orgs_active'] && 2 === $c['orgs_approved'] && 3 === $c['largest_share'] && 4 === $c['largest_of'], $c );
	$reading = $p_partner['c']['reading'];
	$check( 'the reading says three of four', false !== strpos( $reading, '3 of 4' ), $reading );
	$check( 'and names neither organization', false === strpos( $reading, 'Scope Alpha' ) && false === strpos( $reading, 'Scope Beta' ) && false === strpos( $reading, '%' ), $reading );
	$c = $p_alpha['c']['current'];
	$check( 'an organization counts its teams: one of two was on a mission with a cleanup', 1 === $c['orgs_active'] && 2 === $c['orgs_approved'] && 3 === $c['by_mission'] && 0 === $c['by_person'], $c );
	$check( 'and its members', 2 === $c['people_registered'] && $c['people_active'] >= 1, $c );
	$check( 'its reading is about teams and names none', false !== stripos( $p_alpha['c']['reading'], 'team' ) && false === strpos( $p_alpha['c']['reading'], 'Scope Wash Crew' ), $p_alpha['c']['reading'] );
	$check( 'a team with no mission is in the row that moves it', 1 === (int) $n_alpha['levers']['c']['dormant']['count'], $n_alpha['levers']['c'] );

	WP_CLI::log( 'D. Would a funder trust the record?' );
	$same   = static function ( array $d, array $totals ): bool {
		return (float) $d['pounds_measured'] === (float) $totals['outputs']['pounds_measured']
			&& (float) $d['pounds_self_reported'] === (float) $totals['outputs']['pounds_self_reported']
			&& (float) $d['hours_verified'] === (float) $totals['effort']['hours_verified']
			&& (float) $d['hours_unverified'] === (float) $totals['effort']['hours_unverified']
			&& (int) $d['spots_cleaned'] === (int) $totals['outputs']['spots']
			&& (int) $d['missions'] === (int) $totals['effort']['missions'];
	};
	$totals = Impact::totals(
		array(
			'team' => $alpha,
			'from' => $from,
			'to'   => $to,
		)
	);
	$d      = $p_alpha['d']['current'];
	$check( 'an organization\'s figures equal Impact::totals for it and the same dates', $same( $d, $totals ), array( $d, $totals ) );
	$check( 'measured and self-reported weight are apart', 120.0 === (float) $d['pounds_measured'] && 40.0 === (float) $d['pounds_self_reported'], $d );
	$check( 'one of its three records is verified and counted', 1 === $d['verified'] && 3 === $d['total_cleanups'] && 1 === $d['counted'], $d );
	$totals = Impact::totals(
		array(
			'teams' => array( $alpha, $beta ),
			'from'  => $from,
			'to'    => $to,
		)
	);
	$d      = $p_partner['d']['current'];
	$check( 'a partner\'s equal Impact::totals over its organizations', $same( $d, $totals ) && 135.0 === (float) $d['pounds_measured'] && 4 === $d['total_cleanups'], array( $d, $totals ) );
	$check( 'and leave out the organization with no partner', 300.0 === (float) $p_gamma['d']['current']['pounds_measured'] && (float) $d['pounds_measured'] < 300.0 );

	WP_CLI::log( 'The queue' );
	$check( 'an organization\'s queue is what its leads can clear', array( 'requests', 'signups', 'impact', 'unrecorded', 'overdue', 'adoptions' ) === array_keys( $n_alpha['waiting'] ), array_keys( $n_alpha['waiting'] ) );
	$check( 'two unverified records wait on the organization', 2 === (int) $n_alpha['waiting']['impact']['count'] && in_array( (int) $n_alpha['waiting']['impact']['oldest_id'], array( (int) $r_a2, (int) $r_a3 ), true ), $n_alpha['waiting']['impact'] );
	$n_partner = Overview::now( $s_partner );
	$check( 'three wait across the partner', 3 === (int) $n_partner['waiting']['impact']['count'], $n_partner['waiting']['impact'] );
	$check( 'no mission is past its window yet', 0 === (int) $n_alpha['waiting']['overdue']['count'] );
	update_post_meta( $m_alpha, '_pos_end', wp_date( 'Y-m-d', strtotime( '-2 days' ) ) );
	update_post_meta( $m_alpha, '_pos_start', wp_date( 'Y-m-d', strtotime( '-2 days' ) ) );
	$n_alpha   = Overview::now( $s_alpha );
	$n_partner = Overview::now( $s_partner );
	$check( 'a mission past its window waits on its organization', 1 === (int) $n_alpha['waiting']['overdue']['count'] && $m_alpha === (int) $n_alpha['waiting']['overdue']['oldest_id'], $n_alpha['waiting']['overdue'] );
	$check( 'and on its partner, and on nobody else', 1 === (int) $n_partner['waiting']['overdue']['count'] && 0 === (int) Overview::now( $s_gamma )['waiting']['overdue']['count'] && 0 === (int) Overview::now( $s_beta )['waiting']['overdue']['count'] );
	$check( 'nothing asks to join the partner', 0 === (int) $n_partner['waiting']['claims']['count'] );
	$asked = Partners::ask( $gamma, $partner, $cat );
	$check( 'an organization that asks to join waits on the partner\'s leads', 'requested' === $asked && 1 === (int) Overview::now( $s_partner )['waiting']['claims']['count'], $said( $asked ) );
	$check( 'and is in none of its figures until it is accepted', ! in_array( $gamma, Overview::scope_ids( $s_partner ), true ) && 4 === Overview::period( 30, $s_partner )['a']['current']['cleaned'] );
	Partners::withdraw( $gamma, $partner, $cat );

	WP_CLI::log( 'One record changes one record\'s figures' );
	Overview::refresh_now( $s_alpha );
	Overview::refresh_now( $s_gamma );
	Overview::refresh_now( $s_partner );
	Overview::refresh_period( 30, $s_alpha );
	Overview::refresh_period( 30, $s_gamma );
	$check( 'snapshots are kept for each record', false !== get_transient( Overview::snapshot_name( $s_alpha ) ) && false !== get_transient( Overview::snapshot_name( $s_gamma ) ) && false !== get_transient( Overview::snapshot_name( $s_partner ) ) && false !== get_transient( Overview::snapshot_name( $s_alpha, 30 ) ) );
	$check( 'under names of their own', 3 === count( array_unique( array( Overview::snapshot_name( $s_alpha ), Overview::snapshot_name( $s_gamma ), Overview::snapshot_name() ) ) ) && Overview::snapshot_name( $s_alpha, 30 ) !== Overview::snapshot_name( $s_gamma, 30 ) );
	$gamma_before = $p_gamma;
	$r_a4         = $clean(
		$a4,
		$m_alpha,
		$ana,
		array(
			'pounds'        => 9,
			'pounds_source' => 'measured',
		)
	);
	$check( 'an impact record for one organization deletes its snapshot', is_int( $r_a4 ) && false === get_transient( Overview::snapshot_name( $s_alpha ) ) && false === get_transient( Overview::snapshot_name( $s_alpha, 30 ) ), $said( $r_a4 ) );
	$check( 'and its partner\'s', false === get_transient( Overview::snapshot_name( $s_partner ) ) );
	$check( 'and not another organization\'s', false !== get_transient( Overview::snapshot_name( $s_gamma ) ) && false !== get_transient( Overview::snapshot_name( $s_gamma, 30 ) ) );
	$p_gamma = Overview::period( 30, $s_gamma );
	$check( 'the other organization\'s figures are unchanged', $gamma_before['a']['current'] === $p_gamma['a']['current'] && $gamma_before['c']['current'] === $p_gamma['c']['current'] && $gamma_before['d']['current'] === $p_gamma['d']['current'], array( $gamma_before['d']['current'], $p_gamma['d']['current'] ) );
	$p_alpha = Overview::period( 30, $s_alpha );
	$check( 'and its own moved', 4 === $p_alpha['a']['current']['cleaned'] && 0 === $p_alpha['a']['current']['net_open'] && 129.0 === (float) $p_alpha['d']['current']['pounds_measured'], $p_alpha['a']['current'] );
	$check( 'which now reads that cleanups kept pace', false !== strpos( $p_alpha['a']['reading'], 'kept pace' ), $p_alpha['a']['reading'] );

	WP_CLI::log( 'The report of one record' );
	$as( $ana );
	$check( 'a lead may download their organization\'s report', Overview_Report::may( array( 'team' => $alpha ), $ana ) && '' !== Overview_Report::link( 'team', $alpha, 'Report' ) );
	$check( 'and not another organization\'s', ! Overview_Report::may( array( 'team' => $gamma ), $ana ) && '' === Overview_Report::link( 'team', $gamma, 'Report' ) );
	$check( 'nor the platform\'s', ! Overview_Report::may( array(), $ana ) );
	$check( 'a partner\'s lead may download the partner\'s and each organization\'s', Overview_Report::may( array( 'partner' => $partner ), $ana ) && Overview_Report::may( array( 'team' => $beta ), $ana ) );
	$check( 'a member may download none', ! Overview_Report::may( array( 'team' => $alpha ), $mem ) && ! Overview_Report::may( array( 'partner' => $partner ), $mem ) );
	$check( 'a coordinator may download any', Overview_Report::may( array( 'team' => $gamma ), $coordinator ) && Overview_Report::may( array( 'partner' => $partner ), $coordinator ) );

	$data   = Overview_Report::data( 30, array( 'team' => $alpha ) );
	$totals = Impact::totals(
		array(
			'team' => $alpha,
			'from' => (string) $data['period']['from'],
			'to'   => (string) $data['period']['to'],
		)
	);
	$check( 'the cover names the organization', false !== strpos( $data['title'], 'Scope Alpha ' . $packout_run ) && 'team' === $data['subject']['kind'], $data['title'] );
	$check( 'its figures are the organization\'s', (int) $totals['outputs']['spots'] === $data['outcomes']['spots_cleaned'] && 4 === $data['outcomes']['spots_cleaned'] && 129.0 === (float) $data['outputs']['pounds_measured'] && 40.0 === (float) $data['outputs']['pounds_self_reported'], $data['outputs'] );
	$check( 'from report to cleanup is what it took on', 4 === $data['from_report_to_cleanup']['reported'] && 4 === $data['from_report_to_cleanup']['cleaned'] && 0 === $data['from_report_to_cleanup']['open_at_end'], $data['from_report_to_cleanup'] );
	$check( '"Who did the work" lists only teams of that organization', array( $crew_name ) === $data['who']['listed'] && 0 === $data['who']['unlisted'], $data['who'] );
	$json  = Overview_Report::json( $data );
	$csv   = Overview_Report::csv( $data );
	$html  = Overview_Report::html( $data );
	$blob  = $json . $csv . $html;
	$named = false;
	foreach ( array( $ana, $bob, $cat, $mem, $out, $coordinator ) as $person ) {
		$account = get_userdata( $person );
		$named   = $named || false !== strpos( $blob, $account->display_name ) || false !== strpos( $blob, $account->user_email ) || false !== strpos( $blob, $account->user_login );
	}
	$check( 'it names no person', ! $named );
	$check( 'and no other organization', false === strpos( $blob, 'Scope Beta' ) && false === strpos( $blob, 'Scope Gamma' ) && false === strpos( $blob, $partner_name ) );
	$check( 'and no pin', false === strpos( $blob, '34.71' ) && false === strpos( $blob, '-111.8' ) );
	$check( 'JSON decodes and CSV says which rows are teams', is_array( json_decode( $json, true ) ) && false !== strpos( $csv, '"who","team","' . $crew_name . '"' ) && false === strpos( $csv, 'listed_organization' ), $csv );
	$check( 'the page words the list for teams', false !== strpos( $html, 'Its teams that were on a mission' ) && false === strpos( $html, '<script' ) );
	$pdf = Overview_Report::pdf( $data );
	$check( 'the PDF is a PDF', is_string( $pdf ) && 0 === strpos( $pdf, '%PDF' ), is_wp_error( $pdf ) ? $pdf->get_error_message() : substr( (string) $pdf, 0, 8 ) );

	$data = Overview_Report::data( 30, array( 'partner' => $partner ) );
	$check( 'a partner\'s report names the partner', false !== strpos( $data['title'], $partner_name ) && 'partner' === $data['subject']['kind'], $data['title'] );
	$check( 'and lists its organizations, and no other', array( 'Scope Alpha ' . $packout_run, 'Scope Beta ' . $packout_run ) === $data['who']['listed'], $data['who'] );
	$check( 'its figures are the sum of theirs', 5 === $data['outcomes']['spots_cleaned'] && 144.0 === (float) $data['outputs']['pounds_measured'] && 2 === $data['effort']['organizations'], array( $data['outcomes'], $data['outputs'], $data['effort'] ) );
	$check( 'and it names no person either', false === strpos( Overview_Report::json( $data ), 'Scopeperson' ) );

	$program         = Programs::save(
		0,
		array(
			'owner_kind' => 'partner',
			'owner'      => $partner,
			'title'      => 'Scope River Year ' . $packout_run,
			'goal'       => 'Clear the river road.',
			'orgs'       => array( $alpha ),
			'public'     => true,
		),
		$ana
	);
	$packout_posts[] = $program;
	$filed           = is_int( $program ) ? Missions::save( $m_alpha, array( 'program' => $program ), $ana ) : $program;
	$check( 'a program of the partner takes the organization\'s mission', is_int( $program ) && ! is_wp_error( $filed ) && Programs::of_mission( $m_alpha ) === $program, $said( $filed ) );
	$program = (int) $program;
	$data    = Overview_Report::data( 30, array( 'program' => $program ) );
	$totals  = Programs::totals(
		$program,
		array(
			'from' => (string) $data['period']['from'],
			'to'   => (string) $data['period']['to'],
		)
	);
	$check( 'a program\'s report names the program', false !== strpos( $data['title'], 'Scope River Year' ) && 'program' === $data['subject']['kind'], $data['title'] );
	$check( 'its figures equal the program\'s totals for the same dates', (int) $totals['outputs']['spots'] === $data['outcomes']['spots_cleaned'] && 4 === $data['outcomes']['spots_cleaned'] && (float) $totals['outputs']['pounds_measured'] === (float) $data['outputs']['pounds_measured'] && (float) $totals['effort']['hours_unverified'] === (float) $data['effort']['hours_unverified'], array( $data['outcomes'], $totals['outputs'] ) );
	$check( 'and what its missions took on', 4 === $data['from_report_to_cleanup']['reported'] && 4 === $data['from_report_to_cleanup']['cleaned'] && 0 === $data['from_report_to_cleanup']['open_at_end'], $data['from_report_to_cleanup'] );
	$check( 'it lists only the organization taking part', array( 'Scope Alpha ' . $packout_run ) === $data['who']['listed'], $data['who'] );
	$check( 'whoever may change the program may download it, and a member may not', Overview_Report::may( array( 'program' => $program ), $ana ) && Overview_Report::may( array( 'program' => $program ), $coordinator ) && ! Overview_Report::may( array( 'program' => $program ), $mem ) && ! Overview_Report::may( array( 'program' => $program ), $cat ) );
	$check( 'a report of a record that does not exist is the platform\'s to nobody', '' === Overview_Report::subject( array( 'team' => $partner ) )['kind'] && '' === Overview_Report::subject( array( 'program' => $alpha ) )['kind'] );

	WP_CLI::log( 'The Platform overview' );
	$as( $coordinator );
	$keys = array_keys( Overview::queues() );
	$check( 'the coordinator\'s queue has Partners to approve after Organizations to approve', array_search( 'partners', $keys, true ) === array_search( 'organizations', $keys, true ) + 1, $keys );
	$check( 'On the platform counts approved partners', (int) Overview::now()['platform']['partners'] === $partners_before + 1, Overview::now()['platform'] );
	$proposed        = Partners::propose( $cat, array( 'name' => 'Scope Proposed ' . $packout_run ) );
	$packout_posts[] = $proposed;
	$queue           = Overview::queue( 'partners' );
	$check( 'a proposed partner waits on a coordinator', is_int( $proposed ) && $waiting_before + 1 === (int) $queue['count'], array( $said( $proposed ), $queue ) );
	$check( 'and is not counted as approved', (int) Overview::now()['platform']['partners'] === $partners_before + 1 );
	$check( 'a partner is not counted as an organization', count( array_filter( Teams::all(), static fn( $team ) => Teams::active( $team->ID ) ) ) === (int) Overview::now()['platform']['organizations'], Overview::now()['platform'] );

	WP_CLI::log( 'The screens' );
	$as( $ana );
	$check( 'a partner\'s lead has the Partners screen', Partners_Screen::can_open() && array( $partner ) === wp_list_pluck( Partners_Screen::visible(), 'ID' ) );
	$as( $bob );
	$check( 'the lead of an organization does not', ! Partners_Screen::can_open() && 'refused' === $screen( static fn() => Partners_Screen::render(), $bob ) );
	$html = $screen( static fn() => Partners_Screen::render(), $coordinator, array( 'listing' => 'waiting' ) );
	$check( 'a coordinator sees every partner, with the proposed one to approve', false !== strpos( $html, $partner_name ) && false !== strpos( $html, 'Scope Proposed' ) && false !== strpos( $html, 'Approve the partner' ) );
	$html = $screen( static fn() => Partners_Screen::render(), $ana );
	$check( 'a partner\'s lead sees theirs and cannot change its state', false !== strpos( $html, $partner_name ) && false === strpos( $html, 'Scope Proposed' ) && false === strpos( $html, 'Suspend the partner' ) && false === strpos( $html, 'value="save"' ) );
	$html = $screen( static fn() => Teams_Screen::render(), $coordinator, array( 'partner' => $partner ) );
	$check( 'Organizations narrows to a partner\'s', false !== strpos( $html, 'Scope Alpha' ) && false !== strpos( $html, 'Scope Beta' ) && false === strpos( $html, 'Scope Gamma' ) );
	// Cat proposed a partner that still waits. Bob leads an organization and is no part of it.
	$check( 'a partner that waits is named to its proposer and to a coordinator, and to no other lead', Partners::nameable( (int) $proposed, $cat ) && Partners::nameable( (int) $proposed, $coordinator ) && ! Partners::nameable( (int) $proposed, $bob ) && Partners::nameable( $partner, $bob ) );
	$html = $screen( static fn() => Teams_Screen::render(), $bob, array( 'partner' => (int) $proposed ) );
	$check( 'so a list is not narrowed to it for them, and does not say its name', 'refused' !== $html && false === strpos( $html, 'Scope Proposed' ) && false !== strpos( $html, 'Scope Beta' ), substr( wp_strip_all_tags( $html ), 0, 300 ) );
	$html = $screen( static fn() => Missions_Screen::render(), $bob, array( 'partner' => (int) $proposed ) );
	$check( 'nor is the Missions list', 'refused' !== $html && false === strpos( $html, 'Scope Proposed' ) );
	// The list itself. The Spot picker beside it names other missions a Spot is on.
	$list = static function ( string $html ): string {
		$from = strpos( $html, 'packout-admin-view__plain' );
		return false === $from ? '' : substr( $html, $from, (int) strpos( $html, '</table>', $from ) - $from );
	};
	$html = $list( $screen( static fn() => Missions_Screen::render(), $coordinator, array( 'partner' => $partner ) ) );
	$check( 'Missions narrows to a partner\'s', false !== strpos( $html, 'Scopetest alpha day' ) && false !== strpos( $html, 'Scopetest beta day' ) && false === strpos( $html, 'Scopetest gamma day' ), $html );
	$html = $list( $screen( static fn() => Missions_Screen::render(), $coordinator, array( 'program' => $program ) ) );
	$check( 'and to a program\'s', false !== strpos( $html, 'Scopetest alpha day' ) && false === strpos( $html, 'Scopetest beta day' ) && false !== strpos( $html, 'Scope River Year' ) );
	$as( $ana );
	$check( 'Programs lists what a lead may change', array( $program ) === wp_list_pluck( Programs_Screen::visible( $partner ), 'ID' ) );
	$as( $cat );
	$check( 'and nothing of another owner\'s', ! in_array( $program, wp_list_pluck( Programs_Screen::visible(), 'ID' ), true ) );
	$html = $screen( static fn() => Programs_Screen::render(), $ana );
	$check( 'the drawer offers the program\'s report and its workspace', false !== strpos( $html, 'Scope River Year' ) && false !== strpos( $html, 'kind=program' ) && false !== strpos( $html, 'Open and change it in the workspace' ) );
	$check( 'a member is refused the Programs screen', 'refused' === $screen( static fn() => Programs_Screen::render(), $mem ) );

	WP_CLI::log( 'The map\'s partner filter' );
	// A Spot on a mission that is not public, and one a member asked to be credited to their organization.
	$b2              = $spot( 'Scopetest barrel off the spur.', 34.78, $out );
	$m_quiet         = Missions::save( 0, $plan( $beta, 'Scopetest quiet day', array( $b2 ), array( 'public' => false ) ), $bob );
	$packout_posts[] = $m_quiet;
	$a5              = Spots::create(
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Scopetest mattress at the gate. ' . $packout_run,
			'lat'         => 34.79,
			'lng'         => -111.8,
		),
		$mem,
		array( 'credit_team' => $alpha )
	);
	$packout_posts[] = $a5;
	Spots::publish( (int) $a5 );
	Partners::flush();
	$choices = Partners::map_choices();
	$check( 'the filter offers the partners the directory shows', isset( $choices[ $partner ] ) && $partner_name === $choices[ $partner ] && ! isset( $choices[ (int) $proposed ] ), $choices );
	$check( 'the map is told about them', isset( Blocks::map_config()['partners'][ $partner ] ) && isset( Blocks::map_config()['text']['partner'], Blocks::map_config()['text']['allPartners'] ) );
	$under = Partners::of_spots();
	$check( 'a Spot on a public mission of one of its organizations is the partner\'s', array( $partner ) === ( $under[ $a1 ] ?? null ) && array( $partner ) === ( $under[ $b1 ] ?? null ), array( $under[ $a1 ] ?? null, $under[ $b1 ] ?? null ) );
	$check( 'and so is a Spot credited to one of its listed organizations', is_int( $a5 ) && array( $partner ) === ( $under[ $a5 ] ?? null ), $under[ (int) $a5 ] ?? null );
	$check( 'a Spot on a mission that is not public is not', is_int( $m_quiet ) && ! isset( $under[ $b2 ] ), $said( $m_quiet ) );
	$check( 'nor a Spot of an organization with no partner', ! isset( $under[ $g1 ] ) && ! isset( $under[ $g2 ] ) );
	$as( 0 );
	$listed = static function ( array $params = array() ): array {
		$request = new WP_REST_Request( 'GET', '/packout/v1/spots' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$spots = rest_do_request( $request )->get_data()['spots'] ?? array();
		return array_column( $spots, null, 'id' );
	};
	$all    = $listed();
	$check( 'a visitor\'s Spots say which partners they are shown under', array( $partner ) === ( $all[ $a1 ]['partners'] ?? null ) && array() === ( $all[ $g1 ]['partners'] ?? null ) && array() === ( $all[ $b2 ]['partners'] ?? null ), array( $all[ $a1 ]['partners'] ?? null, $all[ $g1 ]['partners'] ?? null ) );
	$only = array_keys( $listed( array( 'partner' => $partner ) ) );
	sort( $only );
	$mine = array( $a1, $a2, $a3, $a4, $b1, (int) $a5 );
	sort( $mine );
	$check( 'the route narrows to one partner\'s Spots', $mine === $only, array( $mine, $only ) );
	$check( 'a partner that does not exist has none', array() === $listed( array( 'partner' => $alpha ) ) );
	Partners::suspend( $partner, $coordinator, 'Scopetest.' );
	Partners::flush();
	$check( 'a suspended partner leaves the filter, and its Spots stay on the map', ! isset( Partners::map_choices()[ $partner ] ) && array() === Partners::of_spots() && array() === $listed( array( 'partner' => $partner ) ) && isset( $listed()[ $a1 ] ) );
	$check( 'and the Spots carry no partner at all', ! array_key_exists( 'partners', $listed()[ $a1 ] ) || array() === $listed()[ $a1 ]['partners'] );
	Partners::lift( $partner, $coordinator );
	Partners::flush();
	$check( 'lifting the suspension brings the filter back', isset( Partners::map_choices()[ $partner ] ) && array( $partner ) === ( Partners::of_spots()[ $a1 ] ?? null ) );
} catch ( \Throwable $e ) {
	$packout_failures[] = $e->getMessage();
	WP_CLI::log( '  FAIL  ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	remove_filter( 'pre_wp_mail', $keep_mail, 99 );
	$_GET = array();
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
	Overview::forget();
}

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( count( $packout_failures ) . ' checks failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
