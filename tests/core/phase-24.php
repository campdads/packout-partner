<?php
/**
 * Phase 24 acceptance checks: joint campaigns, and the report of a program and of a campaign.
 *
 * The report is checked on either site: a program's and a campaign's, whole,
 * against the figures their pages show.
 *
 * A joint campaign is checked as the site keeps it. On packout.spot: an
 * organization makes its campaign joint, invites another, and each files
 * its own missions under it, through the forms of the workspace. What a
 * partner site adds to that is in the hub's tests/partner-campaigns.php.
 * On a partner site the hub is played in place: the site is invited, accepts,
 * files a mission under the hub's campaign, sends its figures for it, leaves,
 * and hosts a joint campaign of its own.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-24.php
 *
 * The checks make their own users and records and delete them, and put the
 * site's connection and queue back as they were. No request leaves the site.
 *
 * @package PackOut_Core
 */

use PackOut\Core\Contract;
use PackOut\Core\Signing;
use PackOut\Core\App\App;
use PackOut\Core\App\Calendar;
use PackOut\Core\App\Campaigns;
use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Connection;
use PackOut\Core\App\Exchange;
use PackOut\Core\App\Hub_Layers;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Joint;
use PackOut\Core\App\Listings;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Outbox;
use PackOut\Core\App\Overview;
use PackOut\Core\App\Overview_Report;
use PackOut\Core\App\Partners;
use PackOut\Core\App\Programs;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Snapshots;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Teams;
use PackOut\Core\App\Workspace_Views;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

global $wpdb;

$hub              = App::is_hub();
$packout_failures = array();
$packout_passed   = 0;
$packout_posts    = array();
$packout_users    = array();
$packout_run      = strtolower( wp_generate_password( 6, false ) );
$packout_kept     = array();
$packout_rows     = null;

$check = static function ( string $label, bool $ok, $detail = '' ) use ( &$packout_failures, &$packout_passed ): void {
	if ( $ok ) {
		++$packout_passed;
		WP_CLI::log( '  ok    ' . $label );
		return;
	}
	$packout_failures[] = $label;
	WP_CLI::log( '  FAIL  ' . $label . ( '' !== $detail && array() !== $detail ? ' — ' . ( is_scalar( $detail ) ? $detail : wp_json_encode( $detail ) ) : '' ) );
};

$said = static fn( $thing ): string => is_wp_error( $thing ) ? $thing->get_error_code() . ': ' . $thing->get_error_message() : ( is_scalar( $thing ) ? (string) $thing : (string) wp_json_encode( $thing ) );

$user = static function ( string $role, string $name ) use ( &$packout_users, $packout_run ): int {
	$login           = 'pos-test-' . sanitize_key( $name ) . '-' . $packout_run;
	$id              = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => $login . '@example.test',
			'display_name' => $name . ' Jointperson',
			'role'         => $role,
		)
	);
	$packout_users[] = $id;
	return $id;
};

// Mail stays in memory. Nothing leaves the machine.
$keep_mail = static fn() => true;
add_filter( 'pre_wp_mail', $keep_mail, 99 );

// The public page of a post, as the theme asks for it.
$page_of = static function ( int $post_id ): string {
	global $wp_query, $wp_the_query;
	$before_query = $wp_query;
	$before_main  = $wp_the_query;
	// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Standing in for the main query of a page view.
	$wp_query     = new WP_Query(
		array(
			'p'           => $post_id,
			'post_type'   => get_post_type( $post_id ),
			'post_status' => array( 'publish', 'private' ),
		)
	);
	$wp_the_query = $wp_query;
	$html         = '';
	if ( $wp_query->have_posts() ) {
		$wp_query->the_post();
		$html = (string) apply_filters( 'the_content', get_the_content() );
	}
	$wp_query     = $before_query;
	$wp_the_query = $before_main;
	// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	wp_reset_postdata();
	return $html;
};

// One view of an organization's workspace, as a person sees it.
$view = static function ( int $team, string $name, string $item, int $who ): string {
	wp_set_current_user( $who );
	return Workspace_Views::team( get_post( $team ), $name, $item, $who );
};

// One of the workspace's forms for a joint campaign, as the page sends it. The answer is what the form was told.
$send = static function ( string $act, int $team, array $fields, int $who ) {
	wp_set_current_user( $who );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This stands in for a request. The workspace checks the nonce before it calls the class.
	$_POST  = array( 'team' => (string) $team ) + array_map( 'strval', $fields );
	$answer = Joint::act( $act, $who );
	$_POST  = array();
	return $answer[0];
};

/*
 * The hub, played in place on a partner site.
 */
$origin = $hub ? '' : Connection::hub();
$played = array(
	'requests'      => array(),
	'campaigns'     => array(),
	'organizations' => array(),
	'stamp'         => true,
	'down'          => false,
);
$index  = static function () use ( &$played ): array {
	return array(
		'campaigns'     => array_values( $played['campaigns'] ),
		'organizations' => $played['organizations'],
		'stamp'         => md5( (string) wp_json_encode( array( $played['campaigns'], $played['organizations'] ) ) ),
	);
};
$answer = static function ( $pre, $args, $url ) use ( &$played, $origin, $index ) {
	if ( '' === $origin || 0 !== strpos( (string) $url, $origin . '/wp-json/packout/v1' ) ) {
		return $pre;
	}
	$reply  = static fn( int $status, array $body ): array => array(
		'headers'  => array(),
		'body'     => (string) wp_json_encode( $body ),
		'response' => array(
			'code'    => $status,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
	$route  = (string) strtok( substr( (string) $url, strlen( $origin . Connection::BASE ) ), '?' );
	$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
	$raw    = (string) ( $args['body'] ?? '' );
	$body   = json_decode( $raw, true );
	$fault  = '';
	if ( 'POST' === $method && 0 === strpos( $route, '/campaigns/' ) ) {
		$fault = Contract::fault( 'campaigns', $body );
	} elseif ( 'POST' === $method && in_array( $route, array( '/listings', '/stats' ), true ) ) {
		$fault = Contract::fault( ltrim( $route, '/' ), $body );
	}
	$played['requests'][] = array(
		'method' => $method,
		'route'  => $route,
		'raw'    => $raw,
		'body'   => $body,
		'signed' => isset( $args['headers'][ Signing::SIGNATURE ] ),
		'fault'  => $fault,
	);
	if ( $played['down'] ) {
		return new WP_Error( 'http_request_failed', 'The played hub is down.' );
	}
	if ( '/me' === $route ) {
		return $reply(
			200,
			array(
				'state'   => 'active',
				'minimum' => '0.1.0',
				'current' => '0.1.0',
			)
		);
	}
	if ( '/changes' === $route ) {
		return $reply(
			200,
			array(
				'spots'     => array(),
				'adoptions' => array(),
				'requests'  => array(),
				'now'       => time(),
			) + ( $played['stamp'] ? array( 'campaigns' => $index()['stamp'] ) : array() )
		);
	}
	if ( 'GET' === $method && '/campaigns' === $route ) {
		return $reply( 200, $index() );
	}
	if ( 'POST' === $method && 1 === preg_match( '#^/campaigns/([0-9]+)/(accept|leave|invite|remove)$#', $route, $found ) ) {
		$number = (int) $found[1];
		if ( '' !== $fault ) {
			return $reply(
				422,
				array(
					'code'    => 'invalid_payload',
					'message' => $fault,
				)
			);
		}
		if ( ! isset( $played['campaigns'][ $number ] ) ) {
			return $reply(
				404,
				array(
					'code'    => 'not_found',
					'message' => 'No such campaign.',
				)
			);
		}
		$result = '';
		if ( in_array( $found[2], array( 'accept', 'leave' ), true ) ) {
			$org    = (string) $body['organization'];
			$now    = (string) ( $played['campaigns'][ $number ]['states'][ $org ] ?? '' );
			$result = 'accept' === $found[2] ? 'accepted' : ( 'invited' === $now ? 'declined' : 'left' );
			$played['campaigns'][ $number ]['states'][ $org ] = $result;
		} else {
			$org  = (int) $body['participant'];
			$rows = (array) $played['campaigns'][ $number ]['participants'];
			if ( 'invite' === $found[2] ) {
				$named  = array_values( array_filter( $played['organizations'], static fn( array $row ): bool => (int) $row['id'] === $org ) );
				$rows[] = array(
					'id'      => $org,
					'name'    => $named ? $named[0]['name'] : 'Unknown',
					'partner' => $named ? $named[0]['partner'] : '',
					'state'   => 'invited',
				);
				$result = 'invited';
			} else {
				$rows   = array_values( array_filter( $rows, static fn( array $row ): bool => (int) $row['id'] !== $org ) );
				$result = 'removed';
			}
			$played['campaigns'][ $number ]['participants'] = $rows;
		}
		return $reply( 200, array( 'result' => $result ) + $index() );
	}//end if
	if ( 'POST' === $method && in_array( $route, array( '/listings', '/stats', '/spots' ), true ) ) {
		$results = array();
		foreach ( (array) ( $body['items'] ?? array() ) as $item ) {
			$results[] = array_intersect_key( (array) $item, array_flip( array( 'kind', 'scope', 'remote_id' ) ) ) + array(
				'result' => '/stats' === $route ? 'kept' : 'updated',
				'photos' => array(),
			);
			// A campaign the site says is joint is one the hub then tells it it hosts.
			if ( '/listings' === $route && 'campaign' === ( $item['kind'] ?? '' ) && ! empty( $item['joint'] ) ) {
				$played['campaigns'][9100] = array(
					'id'           => 9100,
					'title'        => (string) $item['title'],
					'host'         => 'This site',
					'url'          => $origin . '/campaign/hosted/',
					'joint'        => (string) $item['joint'],
					'hosted'       => true,
					'local'        => (string) $item['remote_id'],
					'participants' => (array) ( $played['campaigns'][9100]['participants'] ?? array() ),
				);
			}
		}
		return $reply( 200, array( 'items' => $results ) );
	}
	return $reply( 200, array( 'result' => 'updated' ) );
};
add_filter( 'pre_http_request', $answer, 5, 3 );
$sent = static function ( string $method, string $route ) use ( &$played ): array {
	return array_values( array_filter( $played['requests'], static fn( array $one ): bool => $one['method'] === $method && $one['route'] === $route ) );
};

try {
	Capabilities::install();
	Schema::register();

	if ( ! $hub ) {
		// What the site has now is set aside, and put back at the end. The records below are made while the site is not connected, so none of them is queued.
		foreach ( array( Connection::OPTION, Connection::KEY_OPTION, Connection::NOTICES, Exchange::PULLED, Hub_Layers::OPTION, Hub_Layers::DATA, Joint::OPTION ) as $option ) {
			$packout_kept[ $option ] = get_option( $option, null );
			delete_option( $option );
		}
		Outbox::install_tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$packout_rows = (array) $wpdb->get_results( "SELECT kind, subject, queued, attempts, next_try, last_error FROM {$wpdb->prefix}pos_outbox", ARRAY_A );
		Outbox::clear();
		Outbox::unschedule();
		Joint::flush();
	}

	$coordinator = $user( 'pos_coordinator', 'Coordinator' );
	$ana         = $user( 'pos_lead', 'Ana' );
	$bob         = $user( 'pos_lead', 'Bob' );
	$mem         = $user( 'pos_reporter', 'Mem' );
	$kim         = $user( 'pos_reporter', 'Kim' );

	$org  = static function ( string $name, int $lead ) use ( &$packout_posts, $coordinator, $packout_run ): int {
		wp_set_current_user( $coordinator );
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
		$packout_posts[] = (int) $id;
		return (int) $id;
	};
	$spot = static function ( string $what, float $lat, int $by ) use ( &$packout_posts, $packout_run ): int {
		$id              = (int) Spots::create(
			array(
				'category'    => 'cleanup-waste',
				'description' => $what . ' ' . $packout_run,
				'lat'         => $lat,
				'lng'         => -111.6,
			),
			$by
		);
		$packout_posts[] = $id;
		Spots::publish( $id );
		return $id;
	};
	$plan = static fn( int $team, string $title, array $spots, array $more = array() ): array => $more + array(
		'title'  => $title . ' ' . $packout_run,
		'team'   => $team,
		'spots'  => $spots,
		'start'  => wp_date( 'Y-m-d', strtotime( '+6 days' ) ),
		'end'    => wp_date( 'Y-m-d', strtotime( '+6 days' ) ),
		'public' => true,
	);
	// A mission of an organization that cleans one Spot, with a record the public counts.
	$day   = static function ( int $team, int $lead, string $title, float $lat, array $more, array $haul ) use ( &$packout_posts, $spot, $plan, $coordinator, $packout_run, $said ): int {
		$place = $spot( 'Jointtest ' . $title . ' haul.', $lat, $lead );
		wp_set_current_user( $lead );
		$mission = Missions::save( 0, $plan( $team, 'Jointtest ' . $title, array( $place ), $more ), $lead );
		if ( ! is_int( $mission ) ) {
			WP_CLI::error( 'A mission could not be planned: ' . $said( $mission ) );
		}
		$packout_posts[] = $mission;
		Missions::set_status( $mission, 'in_progress' );
		Spots::mark_cleaned( $place, $lead, $mission );
		$record = Impact::save_cleanup(
			$place,
			$haul + array(
				'narrative' => 'Jointtest haul. ' . $packout_run,
				'hours'     => 2,
				'people'    => 2,
			),
			$lead
		);
		if ( ! is_int( $record ) ) {
			WP_CLI::error( 'A cleanup could not be recorded: ' . $said( $record ) );
		}
		$packout_posts[] = $record;
		Impact::set_visible( $record, true, $coordinator );
		Missions::set_status( $mission, 'completed' );
		wp_set_current_user( $lead );
		return $mission;
	};
	$sweep = static fn( int $team, string $title, array $more = array() ): array => $more + array(
		'team'        => $team,
		'title'       => $title . ' ' . $packout_run,
		'description' => 'Every landing on the river.',
		'goal'        => 'Thirty landings.',
		'start'       => wp_date( 'Y-m-d', strtotime( '+2 days' ) ),
		'end'         => wp_date( 'Y-m-d', strtotime( '+30 days' ) ),
		'public'      => true,
	);

	$alpha_name = 'Joint Alpha ' . $packout_run;
	$beta_name  = 'Joint Beta ' . $packout_run;
	$alpha      = $org( 'Joint Alpha', $ana );
	$beta       = $org( 'Joint Beta', $bob );
	Teams::add_member( $alpha, get_userdata( $mem )->user_login );
	Teams::add_member( $beta, get_userdata( $kim )->user_login );

	// A program: of the organization on packout.spot, and of the site's partner on a partner site.
	$opener = $hub ? $ana : $coordinator;
	if ( ! $hub && Partners::site() > 0 ) {
		// A report names the owner of a program when the public may see the owner. The partner is listed while the checks run.
		$packout_listed = 'publish' === get_post_status( Partners::site() );
		wp_set_current_user( $coordinator );
		Partners::make_site( $coordinator, array( 'listed' => true ) );
	}
	$program = Programs::save(
		0,
		array(
			'owner_kind' => $hub ? 'team' : 'partner',
			'owner'      => $hub ? $alpha : Partners::site(),
			'title'      => 'Jointtest River Year ' . $packout_run,
			'goal'       => 'Clear the river road.',
			'orgs'       => array( $alpha ),
			'public'     => true,
		),
		$opener
	);
	if ( ! is_int( $program ) ) {
		WP_CLI::error( 'The program could not be opened: ' . $said( $program ) );
	}
	$packout_posts[] = $program;
	wp_set_current_user( $ana );
	$campaign = Campaigns::save( 0, $sweep( $alpha, 'Jointtest valley sweep', array( 'program' => $program ) ), $ana );
	if ( ! is_int( $campaign ) ) {
		WP_CLI::error( 'The campaign could not be made: ' . $said( $campaign ) );
	}
	$packout_posts[] = $campaign;
	$m_alpha         = $day(
		$alpha,
		$ana,
		'alpha day',
		34.51,
		array( 'campaign' => $campaign ),
		array(
			'pounds'        => 120,
			'pounds_source' => 'measured',
			'bags'          => 4,
			'bags_source'   => 'measured',
		)
	);
	$m_solo          = $day(
		$alpha,
		$ana,
		'alpha solo day',
		34.52,
		array( 'program' => $program ),
		array(
			'pounds'        => 15,
			'pounds_source' => 'self_reported',
		)
	);
	Impact::flush();

	WP_CLI::log( 'The report of a program' );

	wp_set_current_user( $opener );
	$data   = Overview_Report::data( 0, array( 'program' => $program ) );
	$totals = Programs::totals( $program );
	$shown  = Programs::totals( $program, array( 'visible' => true ) );
	$check( 'the program has both missions, one through its campaign', Programs::of_mission( $m_alpha ) === $program && Programs::of_mission( $m_solo ) === $program && 2 === (int) $totals['outputs']['spots'], $totals['outputs'] );
	$check( 'its report names the program', false !== strpos( $data['title'], 'Jointtest River Year' ) && 'program' === $data['subject']['kind'], $data['title'] );
	$check( 'its figures are the program\'s totals, measured and self-reported apart', 2 === $data['outcomes']['spots_cleaned'] && (float) $totals['outputs']['pounds_measured'] === (float) $data['outputs']['pounds_measured'] && 120.0 === (float) $data['outputs']['pounds_measured'] && 15.0 === (float) $data['outputs']['pounds_self_reported'] && (float) $totals['effort']['hours_unverified'] === (float) $data['effort']['hours_unverified'], array( $data['outputs'], $totals['outputs'] ) );
	$check( 'and the figures the program\'s page shows', (float) $shown['outputs']['pounds_measured'] === (float) $data['outputs']['pounds_measured'] && (float) $shown['outputs']['pounds_self_reported'] === (float) $data['outputs']['pounds_self_reported'] && (int) $shown['outputs']['spots'] === $data['outcomes']['spots_cleaned'] );
	$check( 'it covers the program from its first day to today', $data['period']['from'] <= wp_date( 'Y-m-d' ) && wp_date( 'Y-m-d' ) === $data['period']['to'] && $data['period']['days'] >= 1, $data['period'] );
	$check( 'a window of days still covers that many', 30 === Overview_Report::data( 30, array( 'program' => $program ) )['period']['days'] );
	$check( 'it lists only the organization taking part', array( $alpha_name ) === $data['who']['listed'], $data['who'] );
	$json = Overview_Report::json( $data );
	$html = Overview_Report::html( $data );
	$csv  = Overview_Report::csv( $data );
	$check( 'it names no person', false === strpos( $json . $html . $csv, 'Jointperson' ) && false === strpos( $json . $html . $csv, '@example.test' ) );
	$check( 'it says whose program it is', '' !== (string) $data['subject']['owner'] && false !== strpos( $html, 'A program of ' . $data['subject']['owner'] ) && false !== strpos( $csv, '"cover","owner","' . $data['subject']['owner'] . '"' ), $data['subject'] );
	$check( 'the page of the report has no script and asks for nothing from another address', false === stripos( $html, '<script' ) && 0 === preg_match( '#(src|href)\s*=\s*["\']?(https?:)?//#i', $html ) && false === stripos( $html, '@import' ) && 0 === preg_match( '#url\(\s*["\']?(https?:)?//#i', $html ) && false === stripos( $html, '<link' ) && false === stripos( $html, '<iframe' ) );
	$pdf = Overview_Report::pdf( $data );
	$check( 'and it is made as a PDF', is_string( $pdf ) && 0 === strpos( $pdf, '%PDF' ), is_wp_error( $pdf ) ? $pdf->get_error_message() : strlen( (string) $pdf ) );
	$check( 'whoever may change the program may download it, and a member may not', Overview_Report::may( array( 'program' => $program ), $opener ) && Overview_Report::may( array( 'program' => $program ), $coordinator ) && ! Overview_Report::may( array( 'program' => $program ), $mem ) && ! Overview_Report::may( array( 'program' => $program ), $bob ) && ! Overview_Report::may( array( 'program' => $program ), 0 ) );
	$link = Overview_Report::link( 'program', $program, 'Impact report', 0 );
	$check( 'the link to it asks for the whole program', false !== strpos( $link, 'kind=program' ) && 1 === preg_match( '/days=0(&|")/', $link ), $link );
	wp_set_current_user( $mem );
	$check( 'and a member is given no link', '' === Overview_Report::link( 'program', $program, 'Impact report', 0 ) );

	WP_CLI::log( 'The report of a campaign' );

	wp_set_current_user( $ana );
	$data   = Overview_Report::data( 0, array( 'campaign' => $campaign ) );
	$totals = Campaigns::totals( $campaign );
	$check( 'a campaign\'s report names the campaign and its organization', false !== strpos( $data['title'], 'Jointtest valley sweep' ) && 'campaign' === $data['subject']['kind'] && $alpha_name === $data['subject']['owner'], $data['subject'] );
	$check( 'its figures are the campaign\'s totals, which are what its page and the workspace show', 1 === $data['outcomes']['spots_cleaned'] && 120.0 === (float) $data['outputs']['pounds_measured'] && (float) $totals['outputs']['pounds_measured'] === (float) $data['outputs']['pounds_measured'] && (float) Campaigns::totals( $campaign, true )['outputs']['pounds_measured'] === (float) $data['outputs']['pounds_measured'] && 0.0 === (float) $data['outputs']['pounds_self_reported'], array( $data['outputs'], $totals['outputs'] ) );
	$check( 'nothing in it is reported by a partner site', null === $data['partner_sites'] && false === strpos( Overview_Report::html( $data ), 'Reported by partner sites' ) );
	$check( 'its lead may download it; a member, another organization\'s lead, and a visitor may not', Overview_Report::may( array( 'campaign' => $campaign ), $ana ) && Overview_Report::may( array( 'campaign' => $campaign ), $coordinator ) && ! Overview_Report::may( array( 'campaign' => $campaign ), $mem ) && ! Overview_Report::may( array( 'campaign' => $campaign ), $bob ) && ! Overview_Report::may( array( 'campaign' => $campaign ), 0 ) );
	$pane = $view( $alpha, 'campaign', (string) $campaign, $ana );
	$check( 'the campaign in the workspace offers it, whole', false !== strpos( $pane, 'kind=campaign' ) && 1 === preg_match( '/kind=campaign[^"]*days=0(&|")/', str_replace( '&#038;', '&', $pane ) ), substr( $pane, (int) strpos( $pane, 'kind=campaign' ) - 120, 300 ) );
	$check( 'and offers a member nothing of the kind', false === strpos( $view( $alpha, 'campaign', (string) $campaign, $mem ), 'kind=campaign' ) );

	if ( $hub ) {
		WP_CLI::log( 'An organization makes its campaign joint' );

		wp_set_current_user( $ana );
		$quiet           = Campaigns::save( 0, $sweep( $alpha, 'Jointtest quiet sweep', array( 'public' => false ) ), $ana );
		$packout_posts[] = $quiet;
		$refused         = $send( 'joint_open', $alpha, array( 'item' => $quiet ), $ana );
		$check( 'a campaign that is not public cannot be joint', is_int( $quiet ) && is_wp_error( $refused ) && ! Joint::is_joint( (int) $quiet ), $said( $refused ) );
		$pane = $view( $alpha, 'campaign', (string) $campaign, $ana );
		$check( 'the campaign\'s pane in the workspace offers to make it joint, to its lead', false !== strpos( $pane, 'value="joint_open"' ) && false !== strpos( $pane, 'Make this a joint campaign' ) );
		$check( 'and not to a member', false === strpos( $view( $alpha, 'campaign', (string) $campaign, $mem ), 'value="joint_open"' ) );
		$check( 'a member\'s form is refused, and another organization\'s lead\'s', is_wp_error( $send( 'joint_open', $alpha, array( 'item' => $campaign ), $mem ) ) && is_wp_error( $send( 'joint_open', $beta, array( 'item' => $campaign ), $bob ) ) && ! Joint::is_joint( $campaign ) );
		$opened = $send( 'joint_open', $alpha, array( 'item' => $campaign ), $ana );
		$check( 'the lead\'s makes it joint', true === $opened && Joint::is_joint( $campaign ) && ! Joint::closed( $campaign ) && in_array( $campaign, Joint::every(), true ), $said( $opened ) );
		$pane = $view( $alpha, 'campaign', (string) $campaign, $ana );
		$check( 'the pane then lists who could be invited, by name, and nobody yet invited', false !== strpos( $pane, 'value="joint_invite"' ) && false !== strpos( $pane, $beta_name ) && false !== strpos( $pane, 'Nobody is invited yet.' ) && false === strpos( $pane, 'Jointperson' ) );
		$check( 'the organizations\' list says it is a joint campaign', false !== strpos( $view( $alpha, 'campaigns', '', $ana ), 'Public, joint campaign' ) );

		WP_CLI::log( 'Inviting, and answering' );

		$check(
			'a member cannot invite',
			is_wp_error(
				$send(
					'joint_invite',
					$alpha,
					array(
						'item' => $campaign,
						'org'  => $beta,
					),
					$mem
				)
			) && '' === Joint::state(
				$campaign,
				$beta
			)
		);
		$asked = $send(
			'joint_invite',
			$alpha,
			array(
				'item' => $campaign,
				'org'  => $beta,
			),
			$ana
		);
		$check( 'the lead invites an organization', true === $asked && 'invited' === Joint::state( $campaign, $beta ), $said( $asked ) );
		$check( 'nothing shows until it accepts: the campaign\'s lines are the host alone', array( $alpha_name ) === wp_list_pluck( Joint::lines( $campaign ), 'name' ) && false === strpos( $page_of( $campaign ), $beta_name ) );
		$check( 'and it can file no mission under it', ! Campaigns::takes( $campaign, $beta ) && false === strpos( $view( $beta, 'mission', 'new', $bob ), 'Jointtest valley sweep' ) );
		$list = $view( $beta, 'campaigns', '', $bob );
		$check( 'the invitation waits in the organization\'s workspace, with Accept and Decline for its lead', false !== strpos( $list, 'Invitations to joint campaigns' ) && false !== strpos( $list, 'Jointtest valley sweep' ) && false !== strpos( $list, 'Hosted by ' . $alpha_name ) && false !== strpos( $list, 'value="joint_accept"' ) && false !== strpos( $list, 'value="joint_decline"' ) );
		$list = $view( $beta, 'campaigns', '', $kim );
		$check( 'a member sees it and is told a lead answers', false !== strpos( $list, 'Invitations to joint campaigns' ) && false === strpos( $list, 'value="joint_accept"' ) && false !== strpos( $list, 'A lead of the organization accepts or declines.' ) );
		$check( 'a member\'s answer is refused', is_wp_error( $send( 'joint_accept', $beta, array( 'item' => $campaign ), $kim ) ) && 'invited' === Joint::state( $campaign, $beta ) );
		$check( 'the lead accepts', true === $send( 'joint_accept', $beta, array( 'item' => $campaign ), $bob ) && 'accepted' === Joint::state( $campaign, $beta ) );
		$list = $view( $beta, 'campaigns', '', $bob );
		$check( 'the campaign is then among the organization\'s, as one it takes part in', false === strpos( $list, 'Invitations to joint campaigns' ) && false !== strpos( $list, 'Joint campaign, hosted by ' . $alpha_name ) && false !== strpos( $list, 'Plan a mission in this campaign' ) && false !== strpos( $list, 'value="joint_leave"' ) );
		$check( 'which it cannot change', ! Campaigns::can_change( $campaign, $bob ) && false === strpos( $list, 'id="pos-campaign-' . $campaign . '-change"' ) );
		$check( 'a member sees it without the way to leave', false !== strpos( $view( $beta, 'campaigns', '', $kim ), 'Joint campaign, hosted by' ) && false === strpos( $view( $beta, 'campaigns', '', $kim ), 'value="joint_leave"' ) );
		$check( 'the mission form offers it, marked', false !== strpos( $view( $beta, 'mission', 'new', $bob ), 'Jointtest valley sweep ' . $packout_run . ' (joint campaign)' ) );
		$pane = $view( $alpha, 'campaign', (string) $campaign, $ana );
		$check( 'the host sees who takes part', false !== strpos( $pane, 'Taking part' ) && false !== strpos( $pane, 'value="joint_remove"' ) );

		WP_CLI::log( 'Each files its own missions' );

		$m_beta = $day(
			$beta,
			$bob,
			'beta day',
			34.53,
			array( 'campaign' => $campaign ),
			array(
				'pounds'        => 30,
				'pounds_source' => 'measured',
			)
		);
		Impact::flush();
		$check( 'the participant\'s mission is in the campaign', Missions::campaign( $m_beta ) === $campaign && in_array( $m_beta, wp_list_pluck( Campaigns::missions( $campaign ), 'ID' ), true ) );
		$check( 'and not in the campaign\'s program, which its organization does not take part in', 0 === Programs::of_mission( $m_beta ) && ! in_array( $m_beta, wp_list_pluck( Programs::missions( $program ), 'ID' ), true ) && Programs::of_mission( $m_alpha ) === $program );
		$check( 'so the program\'s totals are its own organizations\'', 2 === (int) Programs::totals( $program )['outputs']['spots'] && 120.0 === (float) Programs::totals( $program )['outputs']['pounds_measured'] );
		$lines = Joint::lines( $campaign );
		$check( 'the campaign has a line for each, by name, counted from its own missions', array( $alpha_name, $beta_name ) === wp_list_pluck( $lines, 'name' ) && 120.0 === (float) $lines[0]['totals']['outputs']['pounds_measured'] && 30.0 === (float) $lines[1]['totals']['outputs']['pounds_measured'] && 'host' === $lines[0]['state'] && 'accepted' === $lines[1]['state'], $lines );
		$whole = Campaigns::totals( $campaign, true );
		$check( 'and its totals are the lines added up', 150.0 === (float) $whole['outputs']['pounds_measured'] && 2 === (int) $whole['outputs']['spots'] && array() === array_diff( array_keys( $whole ), array( 'effort', 'outputs', 'outcomes', 'records' ) ), array_keys( $whole ) );
		$page = $page_of( $campaign );
		$check( 'the page lists who takes part and both missions', false !== strpos( $page, 'Taking part' ) && false !== strpos( $page, $alpha_name ) && false !== strpos( $page, $beta_name ) && false !== strpos( $page, 'Jointtest alpha day' ) && false !== strpos( $page, 'Jointtest beta day' ) );
		$check( 'with no partner site in it, it says nothing of reports', false === strpos( $page, 'Reported by partner sites' ) && false === strpos( $page, 'A partner site' ) );
		$check( 'and takes no sign-up of its own', false === strpos( $page, 'pos-signup' ) );
		$check( 'the participant\'s page lists the campaign', false !== strpos( $page_of( $beta ), 'Joint campaigns it takes part in' ) && false !== strpos( $page_of( $beta ), 'Jointtest valley sweep' ) );
		$range = array(
			'from' => wp_date( 'Y-m-d' ),
			'to'   => wp_date( 'Y-m-d', strtotime( '+40 days' ) ),
			'show' => 'campaigns',
		);
		$check( 'and so does its calendar', in_array( $campaign, array_map( 'intval', array_column( Calendar::events( $range + array( 'organization' => $beta ), 0 ), 'id' ) ), true ) );
		wp_set_current_user( $ana );
		$data = Overview_Report::data( 0, array( 'campaign' => $campaign ) );
		$check( 'the campaign\'s report counts every organization\'s missions, as the page does', 150.0 === (float) $data['outputs']['pounds_measured'] && 2 === $data['outcomes']['spots_cleaned'] && array( $alpha_name, $beta_name ) === $data['who']['listed'] && null === $data['partner_sites'], array( $data['outputs'], $data['who'] ) );
		$check( 'a participant\'s lead is not given the host\'s report', ! Overview_Report::may( array( 'campaign' => $campaign ), $bob ) );

		WP_CLI::log( 'Leaving, taking out, and closing' );

		$check( 'a member cannot leave for the organization', is_wp_error( $send( 'joint_leave', $beta, array( 'item' => $campaign ), $kim ) ) && 'accepted' === Joint::state( $campaign, $beta ) );
		$check( 'its lead can', true === $send( 'joint_leave', $beta, array( 'item' => $campaign ), $bob ) && 'left' === Joint::state( $campaign, $beta ) );
		$lines = Joint::lines( $campaign );
		$check( 'its line stays with its figures, and says the day it left', 2 === count( $lines ) && 'left' === $lines[1]['state'] && wp_date( 'Y-m-d' ) === $lines[1]['left'] && 30.0 === (float) $lines[1]['totals']['outputs']['pounds_measured'] && false !== strpos( $page_of( $campaign ), 'Took part until' ), $lines[1] ?? array() );
		$check( 'its mission keeps its place', Missions::campaign( $m_beta ) === $campaign && 150.0 === (float) Campaigns::totals( $campaign, true )['outputs']['pounds_measured'] );
		$list = $view( $beta, 'campaigns', '', $bob );
		$check( 'the workspace says it left and that no new mission can be filed', ! Campaigns::takes( $campaign, $beta ) && false !== strpos( $list, 'Left' ) && false !== strpos( $list, 'No new mission can be filed under this campaign.' ) && false === strpos( $list, 'Plan a mission in this campaign' ) );
		$gamma = $org( 'Joint Gamma', $bob );
		$check(
			'the host takes back an invitation nobody answered',
			true === $send(
				'joint_invite',
				$alpha,
				array(
					'item' => $campaign,
					'org'  => $gamma,
				),
				$ana
			) && true === $send(
				'joint_remove',
				$alpha,
				array(
					'item' => $campaign,
					'org'  => $gamma,
				),
				$ana
			) && '' === Joint::state(
				$campaign,
				$gamma
			)
		);
		$check(
			'a declined invitation is kept as declined, and can be made again',
			true === $send(
				'joint_invite',
				$alpha,
				array(
					'item' => $campaign,
					'org'  => $gamma,
				),
				$ana
			) && true === $send( 'joint_decline', $gamma, array( 'item' => $campaign ), $bob ) && 'declined' === Joint::state( $campaign, $gamma ) && true === $send(
				'joint_invite',
				$alpha,
				array(
					'item' => $campaign,
					'org'  => $gamma,
				),
				$ana
			) && 'invited' === Joint::state(
				$campaign,
				$gamma
			)
		);
		$check( 'a member cannot close the campaign', is_wp_error( $send( 'joint_close', $alpha, array( 'item' => $campaign ), $mem ) ) && ! Joint::closed( $campaign ) );
		$check( 'the host closes it', true === $send( 'joint_close', $alpha, array( 'item' => $campaign ), $ana ) && Joint::closed( $campaign ) && Joint::is_joint( $campaign ) );
		$check( 'an invitation that was waiting can no longer be accepted', array() === Joint::invitations( $gamma ) && is_wp_error( $send( 'joint_accept', $gamma, array( 'item' => $campaign ), $bob ) ) );
		$check(
			'nobody new is invited',
			is_wp_error(
				$send(
					'joint_invite',
					$alpha,
					array(
						'item' => $campaign,
						'org'  => $beta,
					),
					$ana
				)
			)
		);
		$check( 'the host can still file its own mission under it, and what was done stays', Campaigns::takes( $campaign, $alpha ) && 150.0 === (float) Campaigns::totals( $campaign, true )['outputs']['pounds_measured'] );
		$check( 'an ordinary campaign is as it was: its totals are its missions\'', ! Joint::is_joint( (int) $quiet ) && array() === Joint::lines( (int) $quiet ) && 0 === (int) Campaigns::totals( (int) $quiet )['outputs']['spots'] );
	} else {
		WP_CLI::log( 'A site that is not connected' );

		$check( 'asks packout.spot nothing about joint campaigns', false === Joint::pull() && array() === $played['requests'] );
		$refused = $send( 'joint_open', $alpha, array( 'item' => $campaign ), $ana );
		$check( 'and cannot make a campaign joint', is_wp_error( $refused ) && ! Joint::is_joint( $campaign ), $said( $refused ) );

		// Connected, as phase 22 puts it.
		update_option( Connection::KEY_OPTION, Signing::keypair(), false );
		update_option(
			Connection::OPTION,
			array(
				'site'        => bin2hex( random_bytes( 16 ) ),
				'state'       => 'active',
				'hub'         => $origin,
				'home'        => home_url(),
				'registered'  => time(),
				'terms'       => '1',
				'checked'     => time(),
				'confirmed'   => true,
				'proved'      => true,
				'partner_url' => $origin . '/partner/played/',
			)
		);
		Outbox::clear();
		delete_transient( 'packout_outbox_lock' );
		if ( ! Connection::sending() ) {
			WP_CLI::error( 'The site could not be put in a connected state.' );
		}
		if ( ! Listings::listable( 'organization', (string) $alpha ) ) {
			WP_CLI::error( 'The fixture organization is not one the site lists.' );
		}

		WP_CLI::log( 'An invitation from packout.spot' );

		$played['campaigns'][9041] = array(
			'id'          => 9041,
			'title'       => 'Jointtest regional sweep',
			'description' => 'Every landing in the region.',
			'goal'        => 'Sixty landings.',
			'host'        => 'Hub Alliance',
			'url'         => $origin . '/campaign/regional-sweep/',
			'joint'       => 'open',
			'hosted'      => false,
			'start'       => wp_date( 'Y-m-d', strtotime( '+2 days' ) ),
			'end'         => wp_date( 'Y-m-d', strtotime( '+30 days' ) ),
			'start_time'  => '',
			'end_time'    => '',
			'timezone'    => '',
			'states'      => array( (string) $alpha => 'invited' ),
		);
		$played['stamp']           = false;
		Exchange::pull();
		$check( 'a hub that sends no stamp of joint campaigns is not asked for them', 1 === count( $sent( 'GET', '/changes' ) ) && array() === $sent( 'GET', '/campaigns' ) && array() === Joint::invitations( $alpha ) );
		$played['stamp'] = true;
		Exchange::pull();
		$asked = $sent( 'GET', '/campaigns' );
		$check( 'a stamp the site has not seen makes it ask, signed', 1 === count( $asked ) && $asked[0]['signed'] );
		$check( 'the invitation is kept as one to answer, and makes no campaign here', 1 === count( Joint::invitations( $alpha ) ) && 9041 === Joint::invitations( $alpha )[0]['id'] && 'Hub Alliance' === Joint::invitations( $alpha )[0]['host'] && 0 === Joint::copy_of( 9041 ) && array() === Joint::copies() && array() === Joint::invitations( $beta ), Joint::invitations( $alpha ) );
		Exchange::pull();
		$check( 'the same stamp again asks nothing', 1 === count( $sent( 'GET', '/campaigns' ) ) && 3 === count( $sent( 'GET', '/changes' ) ) );
		$list = $view( $alpha, 'campaigns', '', $ana );
		$check( 'the invitation waits in the organization\'s workspace, with Accept and Decline for its lead', false !== strpos( $list, 'Invitations to joint campaigns' ) && false !== strpos( $list, 'Jointtest regional sweep' ) && false !== strpos( $list, 'Hosted by Hub Alliance' ) && false !== strpos( $list, 'value="joint_accept"' ) && false !== strpos( $list, 'value="joint_decline"' ) && false !== strpos( $list, esc_url( $origin . '/campaign/regional-sweep/' ) ) );
		$list = $view( $alpha, 'campaigns', '', $mem );
		$check( 'a member sees it and is told a lead answers', false !== strpos( $list, 'Invitations to joint campaigns' ) && false === strpos( $list, 'value="joint_accept"' ) && false !== strpos( $list, 'A lead of the organization accepts or declines.' ) );
		$check( 'another organization of the site sees none', false === strpos( $view( $beta, 'campaigns', '', $bob ), 'Invitations to joint campaigns' ) );
		$before = count( $played['requests'] );
		$check( 'a member\'s answer is refused, and nothing is sent', is_wp_error( $send( 'joint_accept', $alpha, array( 'item' => 9041 ), $mem ) ) && is_wp_error( $send( 'joint_accept', $beta, array( 'item' => 9041 ), $ana ) ) && count( $played['requests'] ) === $before );

		WP_CLI::log( 'Accepting' );

		$accepted = $send( 'joint_accept', $alpha, array( 'item' => 9041 ), $ana );
		$posted   = $sent( 'POST', '/campaigns/9041/accept' );
		$check( 'the lead accepts, and the site says so to packout.spot, signed', true === $accepted && 1 === count( $posted ) && $posted[0]['signed'], $said( $accepted ) );
		$check(
			'in the words of the contract: its organization, and no person',
			'' === $posted[0]['fault'] && (string) $alpha === $posted[0]['body']['organization'] && array( 'contract', 'core_version', 'organization', 'plugin_version' ) === ( static function ( array $keys ): array {
				sort( $keys );
				return $keys;
			} )( array_keys( $posted[0]['body'] ) ),
			$posted[0]
		);
		$copy = Joint::copy_of( 9041 );
		$check( 'the campaign is then kept here, as the hub described it', $copy > 0 && Joint::is_copy( $copy ) && 9041 === Joint::number( $copy ) && 'Jointtest regional sweep' === get_post( $copy )->post_title && 'Hub Alliance' === Joint::host( $copy ) && 'Sixty landings.' === Campaigns::field( $copy, 'goal' ) && Joint::hub_url( $copy ) === $origin . '/campaign/regional-sweep/' && 'accepted' === Joint::state( $copy, $alpha ) && array() === Joint::invitations( $alpha ) );
		$check( 'it belongs to no organization here, and nobody can change it', $copy > 0 && 0 === Campaigns::team( $copy ) && ! Campaigns::can_change( $copy, $ana ) && ! Campaigns::can_change( $copy, $coordinator ) && is_wp_error( Campaigns::save( $copy, array( 'title' => 'Renamed here' ), $coordinator ) ) );
		$check( 'it is not one the site lists on packout.spot, and nothing of it is queued', ! Listings::listable( 'campaign', (string) $copy ) && ! in_array( 'campaign:' . $copy, array_column( Outbox::rows(), 'subject' ), true ), array_column( Outbox::rows(), 'subject' ) );
		$check( 'the answer the hub gave was kept, so its stamp asks nothing more', Joint::known()['stamp'] === $index()['stamp'] && false === Joint::pull( array( 'campaigns' => $index()['stamp'] ) ) );

		WP_CLI::log( 'What the hub says is cleaned' );

		$played['campaigns'][9042] = array(
			'id'          => 9042,
			'title'       => 'Jointtest <script>alert(1)</script>coast week',
			'description' => "A week.\n<img src=x onerror=alert(1)>",
			'goal'        => '<b>Bold</b> goal',
			'host'        => '<a href="https://evil.example/">Coast Keepers</a>',
			'url'         => 'https://evil.example/campaign/coast-week/',
			'joint'       => 'sideways',
			'hosted'      => false,
			'start'       => 'tomorrow',
			'end'         => wp_date( 'Y-m-d', strtotime( '+9 days' ) ),
			'start_time'  => '25:99',
			'end_time'    => '',
			'timezone'    => 'Mars/Olympus',
			'states'      => array(
				(string) $alpha => 'accepted',
				'999999999'     => 'accepted',
				(string) $beta  => 'crowned',
			),
		);
		$played['campaigns'][9043] = array(
			'id'     => 9043,
			'title'  => '',
			'states' => array( (string) $alpha => 'accepted' ),
		);
		$check( 'a new stamp makes the site ask again', true === Joint::pull( array( 'campaigns' => $index()['stamp'] ) ) );
		$odd  = Joint::copy_of( 9042 );
		$post = get_post( $odd );
		$check( 'markup in a title, a goal, and a host\'s name is dropped', $odd > 0 && false === strpos( $post->post_title . $post->post_content . Campaigns::field( $odd, 'goal' ) . Joint::host( $odd ), '<' ) && 'Coast Keepers' === Joint::host( $odd ) && 'Bold goal' === Campaigns::field( $odd, 'goal' ), array( $post->post_title ?? '', Joint::host( $odd ) ) );
		$check( 'an address that is not packout.spot\'s is not kept', '' === Joint::hub_url( $odd ) && false === strpos( $page_of( $odd ), 'evil.example' ) );
		$check( 'a day, a time, and a zone that are not ones are left out, and a state that is not one is nobody\'s', '' === (string) get_post_meta( $odd, '_pos_start_time', true ) && '' === Joint::state( $odd, $beta ) && 'accepted' === Joint::state( $odd, $alpha ) && ! Joint::closed( $odd ) );
		$check( 'a campaign with no title is not kept', 0 === Joint::copy_of( 9043 ) );
		unset( $played['campaigns'][9042], $played['campaigns'][9043] );
		Joint::pull();
		$check( 'a campaign the hub no longer names, with no mission here, is removed', 0 === Joint::copy_of( 9042 ) && null === get_post( $odd ) && Joint::copy_of( 9041 ) === $copy );

		WP_CLI::log( 'A mission under the hub\'s campaign' );

		$check( 'the mission form offers the campaign, marked', false !== strpos( $view( $alpha, 'mission', 'new', $ana ), 'Jointtest regional sweep (joint campaign)' ) && false === strpos( $view( $beta, 'mission', 'new', $bob ), 'Jointtest regional sweep' ) );
		$m_joint = $day(
			$alpha,
			$ana,
			'regional day',
			34.54,
			array( 'campaign' => $copy ),
			array(
				'pounds'        => 44,
				'pounds_source' => 'measured',
				'bags'          => 3,
				'bags_source'   => 'self_reported',
			)
		);
		Impact::flush();
		$check( 'an organization that takes part files its mission under it', Missions::campaign( $m_joint ) === $copy && in_array( $m_joint, wp_list_pluck( Campaigns::missions( $copy ), 'ID' ), true ) );
		wp_set_current_user( $bob );
		$m_stray         = Missions::save( 0, $plan( $beta, 'Jointtest stray day', array( $spot( 'Jointtest stray haul.', 34.55, $bob ) ), array( 'campaign' => $copy ) ), $bob );
		$packout_posts[] = $m_stray;
		$check( 'one that does not is saved in no campaign', is_int( $m_stray ) && 0 === Missions::campaign( (int) $m_stray ), $said( $m_stray ) );
		$item = Listings::item( 'mission', (string) $m_joint );
		$check( 'the mission is listed under the hub\'s number for the campaign', 'hub:9041' === $item['campaign'] && '' === Contract::fault( 'listings', array( 'items' => array( $item ) ) + Connection::versions() ), array( $item['campaign'] ?? null, Contract::fault( 'listings', array( 'items' => array( $item ) ) + Connection::versions() ) ) );
		$mine = array_values( array_filter( Snapshots::all(), static fn( array $one ): bool => 'campaign' === $one['scope'] && 'hub:9041' === $one['remote_id'] ) );
		$check( 'the site\'s figures for the campaign go under that number too', 1 === count( $mine ) && 44.0 === (float) $mine[0]['outputs']['pounds_measured'] && 3.0 === (float) $mine[0]['outputs']['bags_self_reported'] && 1 === (int) $mine[0]['outputs']['spots_cleaned'] && '' === Contract::fault( 'stats', array( 'items' => $mine ) + Connection::versions() ), $mine );
		$check( 'and the site\'s own campaign under its own', 1 === count( array_filter( Snapshots::all(), static fn( array $one ): bool => 'campaign' === $one['scope'] && (string) $campaign === $one['remote_id'] ) ) );
		$played['requests'] = array();
		Outbox::enqueue( 'stats', 'stats' );
		Outbox::run( true );
		$listed = array();
		foreach ( $sent( 'POST', '/listings' ) as $one ) {
			foreach ( (array) $one['body']['items'] as $row ) {
				$listed[ $row['kind'] . ':' . $row['remote_id'] ] = $row;
			}
		}
		$figures = array();
		foreach ( $sent( 'POST', '/stats' ) as $one ) {
			foreach ( (array) $one['body']['items'] as $row ) {
				$figures[ $row['scope'] . ':' . $row['remote_id'] ] = $row;
			}
		}
		$check( 'the queue sends the mission so, and never the campaign', isset( $listed[ 'mission:' . $m_joint ] ) && 'hub:9041' === $listed[ 'mission:' . $m_joint ]['campaign'] && ! isset( $listed[ 'campaign:' . $copy ] ), array_keys( $listed ) );
		$check( 'and the figures', isset( $figures['campaign:hub:9041'] ) && ! isset( $figures[ 'campaign:' . $copy ] ), array_keys( $figures ) );
		$check( 'every request fits the contract', array() === array_filter( array_column( $played['requests'], 'fault' ) ), array_filter( array_column( $played['requests'], 'fault' ) ) );

		WP_CLI::log( 'The campaign on this site' );

		$page = $page_of( $copy );
		$check( 'its page says it is a joint campaign, who hosts it, and where the whole of it is', false !== strpos( $page, 'A joint campaign' ) && false !== strpos( $page, 'hosted by Hub Alliance on packout.spot' ) && false !== strpos( $page, 'See the whole campaign on packout.spot' ) && false !== strpos( $page, esc_url( $origin . '/campaign/regional-sweep/' ) ) );
		$check( 'it lists the missions planned here, under words that say so, and takes no sign-up', false !== strpos( $page, 'Jointtest regional day' ) && false !== strpos( $page, 'Missions here' ) && false !== strpos( $page, 'Recorded here so far' ) && false === strpos( $page, 'pos-signup' ) );
		$range = array(
			'from' => wp_date( 'Y-m-d' ),
			'to'   => wp_date( 'Y-m-d', strtotime( '+40 days' ) ),
			'show' => 'campaigns',
		);
		$on    = static function ( array $filter ) use ( $range, $copy ): array {
			foreach ( Calendar::events( $range + $filter, 0 ) as $event ) {
				if ( (int) $event['id'] === $copy ) {
					return $event;
				}
			}
			return array();
		};
		$check( 'it is on the site\'s calendar, under its host\'s name', $on( array() ) && 'Hub Alliance' === $on( array() )['organization'], $on( array() ) );
		$check( 'and on the calendar of the organization that takes part, and not another\'s', array() !== $on( array( 'organization' => $alpha ) ) && array() === $on( array( 'organization' => $beta ) ) );
		$list = $view( $alpha, 'campaigns', '', $ana );
		$check( 'the workspace lists it as one the organization takes part in, with its own missions and a way to leave', false !== strpos( $list, 'Joint campaign, hosted by Hub Alliance' ) && false !== strpos( $list, 'Jointtest regional day' ) && false !== strpos( $list, 'campaign=' . $copy ) && false !== strpos( $list, 'value="joint_leave"' ) && false === strpos( $list, 'id="pos-campaign-' . $copy . '-change"' ) );
		$check( 'a member sees it without the way to leave', false !== strpos( $view( $alpha, 'campaigns', '', $mem ), 'Joint campaign, hosted by Hub Alliance' ) && false === strpos( $view( $alpha, 'campaigns', '', $mem ), 'value="joint_leave"' ) );
		$check( 'another organization\'s workspace does not have it', false === strpos( $view( $beta, 'campaigns', '', $bob ), 'Jointtest regional sweep' ) );
		$check( 'the organization\'s page lists it', false !== strpos( $page_of( $alpha ), 'Joint campaigns it takes part in' ) && false !== strpos( $page_of( $alpha ), 'Jointtest regional sweep' ) );
		wp_set_current_user( $coordinator );
		$data = Overview_Report::data( 0, array( 'campaign' => $copy ) );
		$check( 'its report here counts what was recorded here, as its page does', 44.0 === (float) $data['outputs']['pounds_measured'] && (float) Campaigns::totals( $copy, true )['outputs']['pounds_measured'] === (float) $data['outputs']['pounds_measured'] && 1 === $data['outcomes']['spots_cleaned'] && null === $data['partner_sites'], $data['outputs'] );
		$check( 'and says whose campaign it is', 'Hub Alliance' === $data['subject']['owner'] && false !== strpos( Overview_Report::html( $data ), 'A campaign of Hub Alliance' ) );
		$check( 'a coordinator of the site may download it, and a lead who cannot change the campaign may not', Overview_Report::may( array( 'campaign' => $copy ), $coordinator ) && ! Overview_Report::may( array( 'campaign' => $copy ), $ana ) );

		WP_CLI::log( 'Leaving' );

		$check( 'a member cannot leave for the organization', is_wp_error( $send( 'joint_leave', $alpha, array( 'item' => 9041 ), $mem ) ) && array() === $sent( 'POST', '/campaigns/9041/leave' ) );
		$left   = $send( 'joint_leave', $alpha, array( 'item' => 9041 ), $ana );
		$posted = $sent( 'POST', '/campaigns/9041/leave' );
		$check( 'its lead leaves, and the site says so to packout.spot', true === $left && 1 === count( $posted ) && $posted[0]['signed'] && '' === $posted[0]['fault'] && (string) $alpha === $posted[0]['body']['organization'], $said( $left ) );
		$check( 'the campaign stays here, as one the organization left', Joint::copy_of( 9041 ) === $copy && 'left' === Joint::state( $copy, $alpha ) && ! Campaigns::takes( $copy, $alpha ) );
		wp_set_current_user( $ana );
		$kept = Missions::save( $m_joint, array( 'title' => 'Jointtest regional day, again ' . $packout_run ), $ana );
		$check( 'its mission keeps its place, and is still listed under the hub\'s number', ! is_wp_error( $kept ) && Missions::campaign( $m_joint ) === $copy && 'hub:9041' === Listings::item( 'mission', (string) $m_joint )['campaign'], $said( $kept ) );
		$m_late          = Missions::save( 0, $plan( $alpha, 'Jointtest late day', array( $spot( 'Jointtest late haul.', 34.56, $ana ) ), array( 'campaign' => $copy ) ), $ana );
		$packout_posts[] = $m_late;
		$check( 'a new one is saved in no campaign', is_int( $m_late ) && 0 === Missions::campaign( (int) $m_late ), $said( $m_late ) );
		$list = $view( $alpha, 'campaigns', '', $ana );
		$check( 'the workspace says it left and that no new mission can be filed', false !== strpos( $list, 'Left' ) && false !== strpos( $list, 'No new mission can be filed under this campaign.' ) && false === strpos( $list, 'campaign=' . $copy ) && false === strpos( $list, 'value="joint_leave"' ) );
		$check( 'its figures for what it did are still sent', 1 === count( array_filter( Snapshots::all(), static fn( array $one ): bool => 'hub:9041' === $one['remote_id'] ) ) );
		unset( $played['campaigns'][9041] );
		Joint::pull();
		$check( 'a campaign the hub no longer names, with a mission here, stays as past: closed, and left', Joint::copy_of( 9041 ) === $copy && Joint::closed( $copy ) && 'left' === Joint::state( $copy, $alpha ) && Missions::campaign( $m_joint ) === $copy );

		WP_CLI::log( 'Declining' );

		$played['campaigns'][9044] = array(
			'id'     => 9044,
			'title'  => 'Jointtest lake week',
			'host'   => 'Lake Keepers',
			'url'    => $origin . '/campaign/lake-week/',
			'joint'  => 'open',
			'hosted' => false,
			'start'  => wp_date( 'Y-m-d', strtotime( '+12 days' ) ),
			'end'    => wp_date( 'Y-m-d', strtotime( '+19 days' ) ),
			'states' => array( (string) $beta => 'invited' ),
		);
		Joint::pull();
		$declined = $send( 'joint_decline', $beta, array( 'item' => 9044 ), $bob );
		$check( 'a lead declines an invitation, which the site says as leaving, and keeps no campaign', true === $declined && 1 === count( $sent( 'POST', '/campaigns/9044/leave' ) ) && 0 === Joint::copy_of( 9044 ) && array() === Joint::invitations( $beta ), $said( $declined ) );
		$played['campaigns'][9044]['states'] = array( (string) $beta => 'invited' );
		$played['campaigns'][9044]['joint']  = 'closed';
		Joint::pull();
		$check( 'an invitation to a campaign that has closed is not one to answer', array() === Joint::invitations( $beta ) );

		WP_CLI::log( 'Hosting a joint campaign' );

		$played['organizations'] = array(
			array(
				'id'      => 501,
				'name'    => 'Hub Crew One',
				'partner' => '',
			),
			array(
				'id'      => 502,
				'name'    => 'Far <b>Shore</b> Crew',
				'partner' => 'Far Shore Alliance',
			),
		);
		$played['requests']      = array();
		$pane                    = $view( $alpha, 'campaign', (string) $campaign, $ana );
		$check( 'the campaign\'s pane offers to make it joint, to its lead and not to a member', false !== strpos( $pane, 'value="joint_open"' ) && false === strpos( $view( $alpha, 'campaign', (string) $campaign, $mem ), 'value="joint_open"' ) );
		$check( 'a member\'s form is refused', is_wp_error( $send( 'joint_open', $alpha, array( 'item' => $campaign ), $mem ) ) && ! Joint::is_joint( $campaign ) && array() === $played['requests'] );
		wp_set_current_user( $ana );
		$quiet           = Campaigns::save( 0, $sweep( $alpha, 'Jointtest quiet sweep', array( 'public' => false ) ), $ana );
		$packout_posts[] = $quiet;
		$check( 'a campaign that is not public cannot be joint', is_int( $quiet ) && is_wp_error( $send( 'joint_open', $alpha, array( 'item' => $quiet ), $ana ) ) && ! Joint::is_joint( (int) $quiet ) );
		$check( 'nor can the site\'s copy of another host\'s', is_wp_error( Joint::open( $copy, $coordinator ) ) );
		$opened = $send( 'joint_open', $alpha, array( 'item' => $campaign ), $ana );
		$listed = array();
		foreach ( $sent( 'POST', '/listings' ) as $one ) {
			foreach ( (array) $one['body']['items'] as $row ) {
				$listed[ $row['kind'] . ':' . $row['remote_id'] ] = $row;
			}
		}
		$check( 'the lead makes it joint, and the site lists it so at once', true === $opened && Joint::is_joint( $campaign ) && 'open' === ( $listed[ 'campaign:' . $campaign ]['joint'] ?? '' ), array( $said( $opened ), array_keys( $listed ) ) );
		$check( 'the listing fits the contract and names nobody who takes part', array() === array_filter( array_column( $played['requests'], 'fault' ) ) && ! isset( $listed[ 'campaign:' . $campaign ]['participants'] ), array_filter( array_column( $played['requests'], 'fault' ) ) );
		$hosted = Joint::hosted( $campaign );
		$check( 'the hub then tells it it hosts the campaign, and whom it could invite', $hosted['known'] && 'open' === $hosted['state'] && array() === $hosted['participants'] && array( 501, 502 ) === array_column( $hosted['invitable'], 'id' ), $hosted );
		$check( 'the campaign is still the site\'s own to change', Campaigns::can_change( $campaign, $ana ) && ! Joint::is_copy( $campaign ) && Listings::listable( 'campaign', (string) $campaign ) );
		$pane = $view( $alpha, 'campaign', (string) $campaign, $ana );
		$check( 'the pane lists the organizations to invite, by name and partner, as plain text', false !== strpos( $pane, 'value="joint_invite"' ) && false !== strpos( $pane, 'Hub Crew One' ) && false !== strpos( $pane, 'Far Shore Crew' ) && false !== strpos( $pane, 'Far Shore Alliance' ) && false === strpos( $pane, '<b>Shore</b>' ) && false !== strpos( $pane, 'Nobody is invited yet.' ) );
		$check(
			'a member cannot invite',
			is_wp_error(
				$send(
					'joint_invite',
					$alpha,
					array(
						'item' => $campaign,
						'org'  => 501,
					),
					$mem
				)
			) && array() === $sent(
				'POST',
				'/campaigns/9100/invite'
			)
		);
		$asked  = $send(
			'joint_invite',
			$alpha,
			array(
				'item' => $campaign,
				'org'  => 501,
			),
			$ana
		);
		$posted = $sent( 'POST', '/campaigns/9100/invite' );
		$check( 'the lead invites one, which the site asks of packout.spot by the hub\'s numbers', true === $asked && 1 === count( $posted ) && $posted[0]['signed'] && '' === $posted[0]['fault'] && '501' === $posted[0]['body']['participant'] && ! isset( $posted[0]['body']['organization'] ), $said( $asked ) );
		$hosted = Joint::hosted( $campaign );
		$check( 'who takes part is what the hub says: the one invited, and one fewer to invite', array( 501 ) === array_column( $hosted['participants'], 'id' ) && 'invited' === $hosted['participants'][0]['state'] && array( 502 ) === array_column( $hosted['invitable'], 'id' ), $hosted );
		$pane = $view( $alpha, 'campaign', (string) $campaign, $ana );
		$check( 'the pane shows it as invited, with a way to take the invitation back', false !== strpos( $pane, 'Invited' ) && false !== strpos( $pane, 'value="joint_remove"' ) && false !== strpos( $pane, 'Take back' ) );
		$played['campaigns'][9100]['participants'][0]['state'] = 'accepted';
		Joint::pull();
		$check( 'when the hub says it accepted, the pane says it takes part', 'accepted' === Joint::hosted( $campaign )['participants'][0]['state'] && false !== strpos( $view( $alpha, 'campaign', (string) $campaign, $ana ), 'Taking part' ) );
		$check( 'the site\'s own campaign counts only what was recorded here', 120.0 === (float) Campaigns::totals( $campaign, true )['outputs']['pounds_measured'] && array() === Joint::lines( $campaign ) );
		$page = $page_of( $campaign );
		$check( 'its page here says it is a campaign of the site\'s, with the usual headings', false !== strpos( $page, 'Jointtest alpha day' ) && false === strpos( $page, 'Missions here' ) );
		$taken  = $send(
			'joint_remove',
			$alpha,
			array(
				'item' => $campaign,
				'org'  => 501,
			),
			$ana
		);
		$posted = $sent( 'POST', '/campaigns/9100/remove' );
		$check( 'the lead takes it out, which the site asks of packout.spot', true === $taken && 1 === count( $posted ) && '501' === $posted[0]['body']['participant'] && '' === $posted[0]['fault'] && array() === Joint::hosted( $campaign )['participants'], $said( $taken ) );
		$played['down'] = true;
		$failed         = $send(
			'joint_invite',
			$alpha,
			array(
				'item' => $campaign,
				'org'  => 502,
			),
			$ana
		);
		$check( 'when packout.spot cannot be reached the form says so, and nothing changes here', is_wp_error( $failed ) && array() === Joint::hosted( $campaign )['participants'], $said( $failed ) );
		$played['down']     = false;
		$played['requests'] = array();
		delete_transient( 'packout_outbox_lock' );
		$check( 'a member cannot close it', is_wp_error( $send( 'joint_close', $alpha, array( 'item' => $campaign ), $mem ) ) && ! Joint::closed( $campaign ) );
		// A listing is queued once in a request, and this run is one request that queued the campaign when it was opened. Closing queues it again.
		$closed = $send( 'joint_close', $alpha, array( 'item' => $campaign ), $ana );
		Outbox::run( true );
		$listed = array();
		foreach ( $sent( 'POST', '/listings' ) as $one ) {
			foreach ( (array) $one['body']['items'] as $row ) {
				$listed[ $row['kind'] . ':' . $row['remote_id'] ] = $row;
			}
		}
		$check( 'the lead closes it, and the site lists it as closed', true === $closed && Joint::closed( $campaign ) && 'closed' === ( $listed[ 'campaign:' . $campaign ]['joint'] ?? '' ), array( $said( $closed ), $listed[ 'campaign:' . $campaign ] ?? null ) );
		$check(
			'a closed campaign invites nobody, and packout.spot is not asked',
			is_wp_error(
				$send(
					'joint_invite',
					$alpha,
					array(
						'item' => $campaign,
						'org'  => 502,
					),
					$ana
				)
			) && array() === $sent(
				'POST',
				'/campaigns/9100/invite'
			)
		);

		WP_CLI::log( 'What left the site' );

		$everything = implode( "\n", array_column( $played['requests'], 'raw' ) );
		$people     = array( 'Jointperson', '@example.test' );
		foreach ( $packout_users as $one ) {
			$people[] = (string) get_userdata( $one )->user_login;
		}
		$check( 'no request about a joint campaign names a person', array() === array_filter( $people, static fn( string $word ): bool => false !== strpos( $everything, $word ) ) );
		$check( 'and every one of them that says something is signed', array() === array_filter( $played['requests'], static fn( array $one ): bool => 0 === strpos( $one['route'], '/campaigns' ) && ! $one['signed'] ) );
	}//end if
} catch ( \Throwable $e ) {
	$packout_failures[] = $e->getMessage();
	WP_CLI::log( '  FAIL  ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	remove_filter( 'pre_wp_mail', $keep_mail, 99 );
	remove_filter( 'pre_http_request', $answer, 5 );
	$_POST = array();
	wp_set_current_user( 0 );

	if ( ! $hub ) {
		// The copies this run made of the played hub's campaigns.
		Joint::flush();
		foreach ( Joint::copies() as $made ) {
			if ( in_array( Joint::number( (int) $made ), array( 9041, 9042, 9043, 9044 ), true ) ) {
				wp_delete_post( (int) $made, true );
			}
		}
	}
	foreach ( array_values( array_unique( array_filter( $packout_posts, static fn( $id ): bool => is_int( $id ) && $id > 0 ) ) ) as $post_id ) {
		if ( Schema::TEAM === get_post_type( $post_id ) ) {
			delete_option( 'packout_messages_' . $post_id );
			Overview::forget_scope(
				array(
					'kind' => 'team',
					'id'   => $post_id,
				)
			);
		}
		wp_delete_post( $post_id, true );
	}
	if ( isset( $packout_listed ) && ! $packout_listed && Partners::site() > 0 ) {
		wp_update_post(
			array(
				'ID'          => Partners::site(),
				'post_status' => 'private',
			)
		);
		Partners::flush();
	}
	foreach ( $packout_users as $user_id ) {
		wp_delete_user( (int) $user_id );
	}
	if ( ! $hub ) {
		foreach ( $packout_kept as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value, Connection::OPTION === $option );
			}
		}
		delete_transient( 'packout_outbox_lock' );
		Outbox::clear();
		Outbox::unschedule();
		foreach ( (array) $packout_rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
			$wpdb->insert( $wpdb->prefix . 'pos_outbox', $row );
		}
		if ( Connection::connected() ) {
			Outbox::schedule();
		}
	}
	Joint::flush();
	Impact::flush();
	Overview::forget();
}

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( count( $packout_failures ) . ' checks failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
