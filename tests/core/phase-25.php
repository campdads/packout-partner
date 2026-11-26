<?php
/**
 * Phase 25 acceptance checks: youth teams, an account that is deleted, and what a partner site does about its terms, its key, its address, and an organization that is also on packout.spot.
 *
 * On either site: a team marked as a youth team names no lead, takes nobody
 * from a public page, and takes no public sign-up for its missions; deleting
 * an account deletes no record and leaves no record naming the person.
 *
 * On a partner site the hub is played in place: no photograph and no credit
 * of a youth team's Spot is sent; a later version of the terms is accepted
 * within the days the hub gives; an organization that is also on
 * packout.spot is counted from the day the hub names; a lost key is
 * replaced; a new address is told; and the site takes its copy of what the
 * hub holds. What the hub does about each is in its own
 * tests/partner-lifecycle.php.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-25.php
 *
 * The checks make their own users and records and delete them, and put the
 * site's connection and queue back as they were. No request leaves the site.
 *
 * @package PackOut_Core
 */

use PackOut\Core\Contract;
use PackOut\Core\Signing;
use PackOut\Core\App\App;
use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Connection;
use PackOut\Core\App\Connection_Screen;
use PackOut\Core\App\Exchange;
use PackOut\Core\App\Hub_Layers;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Joint;
use PackOut\Core\App\Listings;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Outbox;
use PackOut\Core\App\Overview;
use PackOut\Core\App\Partners;
use PackOut\Core\App\Photos;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Signups;
use PackOut\Core\App\Snapshots;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Subteams;
use PackOut\Core\App\Team_Page;
use PackOut\Core\App\Teams;
use PackOut\Core\App\Wizard;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/user.php';

$hub              = App::is_hub();
$packout_failures = array();
$packout_passed   = 0;
$packout_posts    = array();
$packout_users    = array();
$packout_files    = array();
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
$code = static fn( $thing ): string => is_wp_error( $thing ) ? (string) $thing->get_error_code() : '';

$user = static function ( string $role, string $name ) use ( &$packout_users, $packout_run ): int {
	$login           = 'pos-test-' . sanitize_key( $name ) . '-' . $packout_run;
	$id              = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => $login . '@example.test',
			'display_name' => $name . ' Lifeperson',
			'role'         => $role,
		)
	);
	$packout_users[] = $id;
	return $id;
};

$file = static function ( string $name, int $red ) use ( &$packout_files ): array {
	$path    = wp_tempnam( $name );
	$picture = imagecreatetruecolor( 600, 400 );
	imagefill( $picture, 0, 0, imagecolorallocate( $picture, $red, 120, 60 ) );
	imagejpeg( $picture, $path );
	$packout_files[] = $path;
	return array(
		'name'     => $name,
		'type'     => 'application/octet-stream',
		'tmp_name' => $path,
		'error'    => 0,
		'size'     => filesize( $path ),
	);
};

// Mail stays in memory. Nothing leaves the machine.
$keep_mail = static fn() => true;
add_filter( 'pre_wp_mail', $keep_mail, 99 );

// The public page of a post, as the theme asks for it. With an address, the page of that team of an organization.
$page_of = static function ( int $post_id, string $subteam = '' ): string {
	global $wp_query, $wp_the_query;
	$before_query = $wp_query;
	$before_main  = $wp_the_query;
	// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Standing in for the main query of a page view.
	$wp_query     = new WP_Query(
		array(
			'p'         => $post_id,
			'post_type' => get_post_type( $post_id ),
		)
	);
	$wp_the_query = $wp_query;
	if ( '' !== $subteam ) {
		$wp_query->set( Team_Page::QUERY_VAR, $subteam );
	}
	$html = '';
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

/*
 * The hub, played in place on a partner site.
 */
$origin = $hub ? '' : Connection::hub();
$played = array(
	'requests' => array(),
	// What `GET /me` adds to a site in good standing.
	'me'       => array(),
	// What the hub says stands between the site's organizations and its own.
	'claims'   => array(),
	// Whether the link in the recovery email was followed in the last hour.
	'open'     => false,
	// Whether the site answered the hub's question with the challenge of the request, signed with the key the hub was to check.
	'proved'   => null,
);
$stands = static function () use ( &$played ): array {
	return $played['me'] + array(
		'state'           => 'active',
		'confirmed'       => true,
		'proved'          => true,
		'minimum_version' => '0.1.0',
		'current_version' => '0.1.0',
		'terms_required'  => '',
		'claims'          => $played['claims'],
	);
};
// The hub's one question to the site, asked while the site's request waits.
$question = static function ( string $challenge, string $with ) use ( &$played ): void {
	$asked = new WP_REST_Request( 'GET', '/packout/v1/hub-challenge' );
	$asked->set_param( 'site', Connection::state()['site'] );
	$got              = rest_do_request( $asked );
	$data             = (array) $got->get_data();
	$played['proved'] = 200 === $got->get_status()
		&& hash_equals( $challenge, (string) ( $data['challenge'] ?? '' ) )
		&& Signing::verify( Connection::challenge_text( Connection::state()['site'], $challenge ), (string) ( $data['signature'] ?? '' ), $with );
};
$answer   = static function ( $pre, $args, $url ) use ( &$played, $origin, $stands, $question ) {
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
	if ( 'POST' === $method && in_array( $route, array( '/claims', '/move', '/recover', '/listings', '/stats' ), true ) ) {
		$fault = Contract::fault( ltrim( $route, '/' ), $body );
	}
	$played['requests'][] = array(
		'method' => $method,
		'route'  => $route,
		'body'   => $body,
		'signed' => isset( $args['headers'][ Signing::SIGNATURE ] ),
		'fault'  => $fault,
	);
	if ( '/me' === $route ) {
		return $reply( 200, $stands() );
	}
	if ( '/changes' === $route ) {
		return $reply(
			200,
			array(
				'spots'     => array(),
				'adoptions' => array(),
				'requests'  => array(),
				'now'       => time(),
			)
		);
	}
	if ( 'POST' === $method && '/claims' === $route ) {
		return $reply( 200, $stands() );
	}
	if ( 'POST' === $method && '/move' === $route ) {
		$key = Connection::key();
		$question( (string) ( $body['challenge'] ?? '' ), $key ? (string) $key['public'] : '' );
		return $played['proved'] ? $reply( 200, $stands() ) : $reply(
			409,
			array(
				'code'    => 'not_proved',
				'message' => 'The played hub could not reach the site.',
			)
		);
	}
	if ( 'POST' === $method && '/recover' === $route ) {
		if ( ! isset( $body['public_key'] ) ) {
			return $reply( 200, array( 'asked' => true ) );
		}
		if ( $played['open'] ) {
			$question( (string) ( $body['challenge'] ?? '' ), (string) $body['public_key'] );
		}
		return $played['open'] && $played['proved'] ? $reply( 200, array( 'recovered' => true ) ) : $reply(
			403,
			array(
				'code'    => 'recovery_closed',
				'message' => 'The recovery is not open for this site.',
			)
		);
	}
	if ( 'GET' === $method && '/export' === $route ) {
		return $reply(
			200,
			array(
				'exported' => gmdate( 'c' ),
				'site'     => array( 'url' => home_url() ),
				'listings' => array(),
				'spots'    => array(),
			)
		);
	}
	if ( 'POST' === $method && in_array( $route, array( '/listings', '/stats', '/spots' ), true ) ) {
		$results = array();
		foreach ( (array) ( $body['items'] ?? array() ) as $item ) {
			$results[] = array_intersect_key( (array) $item, array_flip( array( 'kind', 'scope', 'remote_id' ) ) ) + array(
				'result' => '/stats' === $route ? 'kept' : 'updated',
				'photos' => array(),
			);
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
		foreach ( array( Connection::OPTION, Connection::KEY_OPTION, Connection::NOTICES, Connection::CLAIMS, Exchange::PULLED, Hub_Layers::OPTION, Hub_Layers::DATA, Joint::OPTION ) as $option ) {
			$packout_kept[ $option ] = get_option( $option, null );
			delete_option( $option );
		}
		Outbox::install_tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$packout_rows = (array) $wpdb->get_results( "SELECT kind, subject, queued, attempts, next_try, last_error FROM {$wpdb->prefix}pos_outbox", ARRAY_A );
		Outbox::clear();
		Outbox::unschedule();
	}

	$coordinator = $user( 'pos_coordinator', 'Coordinator' );
	$admin       = $user( 'administrator', 'Admin' );
	$ana         = $user( 'pos_lead', 'Ana' );
	$ben         = $user( 'pos_reporter', 'Ben' );
	$cal         = $user( 'pos_reporter', 'Cal' );
	$out         = $user( 'pos_reporter', 'Out' );

	// The Connection screen, as an administrator sees it.
	$screen = static function () use ( $admin ): string {
		$before = get_current_user_id();
		wp_set_current_user( $admin );
		ob_start();
		Connection_Screen::render();
		$html = (string) ob_get_clean();
		wp_set_current_user( $before );
		return $html;
	};

	$org  = static function ( string $name, int $lead, bool $listed = true ) use ( &$packout_posts, $coordinator, $packout_run ): int {
		wp_set_current_user( $coordinator );
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
		$packout_posts[] = (int) $id;
		return (int) $id;
	};
	$spot = static function ( string $what, float $lat, int $by, array $extra = array() ) use ( &$packout_posts, $coordinator, $packout_run ): int {
		$id              = (int) Spots::create(
			array(
				'category'    => 'cleanup-waste',
				'description' => $what . ' ' . $packout_run,
				'lat'         => $lat,
				'lng'         => -111.6,
			),
			$by,
			$extra
		);
		$packout_posts[] = $id;
		$before          = get_current_user_id();
		wp_set_current_user( $coordinator );
		Spots::publish( $id );
		wp_set_current_user( $before );
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
	// A mission of an organization that cleans one Spot, with a record the public counts, dated as given.
	$day = static function ( int $team, int $lead, string $title, float $lat, float $pounds, string $date ) use ( &$packout_posts, $spot, $plan, $coordinator, $packout_run, $said ): int {
		$place = $spot( 'Lifetest ' . $title . ' haul.', $lat, $lead );
		wp_set_current_user( $lead );
		$mission = Missions::save( 0, $plan( $team, 'Lifetest ' . $title, array( $place ) ), $lead );
		if ( ! is_int( $mission ) ) {
			WP_CLI::error( 'A mission could not be planned: ' . $said( $mission ) );
		}
		$packout_posts[] = $mission;
		Missions::set_status( $mission, 'in_progress' );
		Spots::mark_cleaned( $place, $lead, $mission );
		$record = Impact::save_cleanup(
			$place,
			array(
				'narrative'     => 'Lifetest haul. ' . $packout_run,
				'hours'         => 2,
				'people'        => 2,
				'pounds'        => $pounds,
				'pounds_source' => 'measured',
			),
			$lead
		);
		if ( ! is_int( $record ) ) {
			WP_CLI::error( 'A cleanup could not be recorded: ' . $said( $record ) );
		}
		$packout_posts[] = $record;
		update_post_meta( $record, '_pos_date', $date );
		Impact::set_visible( $record, true, $coordinator );
		Missions::set_status( $mission, 'completed' );
		Impact::flush();
		wp_set_current_user( $lead );
		return $record;
	};

	if ( ! $hub && Partners::site() > 0 ) {
		// The site's organizations are listed on the hub only while its partner is public.
		$packout_listed = 'publish' === get_post_status( Partners::site() );
		wp_set_current_user( $coordinator );
		Partners::make_site( $coordinator, array( 'listed' => true ) );
	}

	$alpha = $org( 'Life Alpha', $ana );
	Teams::add_member( $alpha, get_userdata( $ben )->user_login );
	Teams::add_member( $alpha, get_userdata( $cal )->user_login );

	WP_CLI::log( 'A youth team' );

	wp_set_current_user( $ana );
	$juniors = Subteams::save(
		$alpha,
		0,
		array(
			'name' => 'Juniors ' . $packout_run,
			'lead' => $ben,
		),
		$ana
	);
	if ( ! is_int( $juniors ) ) {
		WP_CLI::error( 'A team could not be made: ' . $said( $juniors ) );
	}
	Subteams::update_page(
		$alpha,
		$juniors,
		array(
			'public' => true,
			'leads'  => true,
			'join'   => 'open',
		),
		$ana
	);
	$address = (string) Subteams::get( $alpha, $juniors )['slug'];
	wp_set_current_user( 0 );
	$html = $page_of( $alpha, $address );
	$check( 'before it is one, the team\'s page names its lead and anyone signed in can join', false !== strpos( $html, 'Ben Lifeperson' ) && 'open' === Subteams::get( $alpha, $juniors )['join'], strlen( $html ) );

	$check( 'the lead of the team cannot say it is a youth team, and a member cannot', 'packout_forbidden' === $code( Subteams::set_youth( $alpha, $juniors, true, $ben ) ) && 'packout_forbidden' === $code( Subteams::set_youth( $alpha, $juniors, true, $cal ) ) && ! Subteams::get( $alpha, $juniors )['youth'] );
	$ticked = Subteams::set_youth( $alpha, $juniors, true, $ana );
	$row    = Subteams::get( $alpha, $juniors );
	$check( 'the lead of the organization can', true === $ticked && true === $row['youth'], $said( $ticked ) );
	$check( 'it names no lead and nobody joins it', false === $row['leads'] && 'closed' === $row['join'] && array() === $row['requests'], $row );
	$html = $page_of( $alpha, $address );
	$check( 'its page names nobody, says it is a youth team, and has no way to join', '' !== $html && false === strpos( $html, 'Ben Lifeperson' ) && false !== strpos( $html, 'Youth team' ) && false !== strpos( $html, 'This is a youth team. Its lead adds its members' ), strlen( $html ) );
	$html = $page_of( $alpha );
	$check( 'the organization\'s page does not name the team\'s lead beside it', false !== strpos( $html, 'Juniors ' . $packout_run ) && false === strpos( $html, 'Ben Lifeperson' ) );
	$check( 'a person who asks to join it is refused', is_wp_error( Subteams::ask( $alpha, $juniors, $cal ) ) && is_wp_error( Subteams::ask( $alpha, $juniors, $out ) ) && ! Subteams::includes( $alpha, $juniors, $cal ) );
	Subteams::update_page(
		$alpha,
		$juniors,
		array(
			'leads' => true,
			'join'  => 'open',
		),
		$ana
	);
	$row = Subteams::get( $alpha, $juniors );
	$check( 'and its page cannot be set to name its lead or to take people', false === $row['leads'] && 'closed' === $row['join'] );

	$place = $spot( 'Lifetest cans behind the Juniors\' field.', 34.71, $cal, array( 'credit_named' => true ) );
	$shot  = Photos::attach_upload( $place, $file( 'field.jpg', 80 ) );
	wp_set_current_user( $ana );
	$outing = Missions::save( 0, $plan( $alpha, 'Lifetest juniors day', array( $place ), array( 'subteams' => array( $juniors ) ) ), $ana );
	if ( ! is_int( $outing ) ) {
		WP_CLI::error( 'A mission could not be planned: ' . $said( $outing ) );
	}
	$packout_posts[] = $outing;
	$check( 'a mission that names it takes no sign-up from the public', Signups::takes( $outing ) && 'packout_signup_youth' === $code( Signups::sign_up( $outing, $out ) ), $said( Signups::sign_up( $outing, $out ) ) );
	$refused = Signups::sign_up( $outing, $cal );
	$check( 'a member of the organization who is not in the team is not put in it by signing up', 'packout_signup_youth' === $code( $refused ) && ! Subteams::includes( $alpha, $juniors, $cal ), $said( $refused ) );
	$joined = Signups::sign_up( $outing, $ben );
	$check( 'a person the team already has signs up', ! is_wp_error( $joined ) && in_array( $ben, Signups::people( $outing ), true ), $said( $joined ) );
	wp_set_current_user( 0 );
	$html = Signups::section( $outing );
	$check( 'the mission\'s page says why there is no sign-up', false !== strpos( $html, 'A youth team takes part in this' ), $html );
	$check( 'a Spot of that mission is one a youth team works on', Subteams::youth_spot( $place ) && ! Exchange::photos_allowed( $place ) );
	$check( 'the setup says where the tick is when the description mentions young people', Wizard::mentions_youth( 'Troop 12 Scouts of the river' ) && Wizard::mentions_youth( 'A school club' ) && ! Wizard::mentions_youth( 'River Alliance' ) && false !== strpos( Wizard::youth_words(), 'youth team' ) );

	if ( ! $hub ) {
		WP_CLI::log( 'What a partner site sends of a youth team\'s Spot' );

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
		$queued = static fn( string $kind, string $subject ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pos_outbox WHERE kind = %s AND subject = %s", $kind, $subject ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.

		$item = (array) Exchange::item( $place );
		$check( 'it goes with no photograph', 'published' === ( $item['state'] ?? '' ) && array() === $item['photos'] && ! is_wp_error( $shot ) && null === Exchange::photo( $place, (int) $shot ), $item['photos'] ?? null );
		$check( 'and with nobody credited', ! isset( $item['credit'] ) && false === strpos( (string) wp_json_encode( $item ), 'Lifeperson' ), $item['credit'] ?? null );
		$check( 'the item still fits the contract', '' === Contract::fault( 'spots', array( 'items' => array( $item ) ) + Connection::versions() ), Contract::fault( 'spots', array( 'items' => array( $item ) ) + Connection::versions() ) );
		Outbox::clear();
		Subteams::set_youth( $alpha, $juniors, false, $ana );
		$item = (array) Exchange::item( $place );
		$check( 'when the mark is taken off, the Spot is queued again and goes as any other', 1 === $queued( 'spot', (string) $place ) && 1 === count( $item['photos'] ) && '' !== (string) ( $item['credit']['label'] ?? '' ), array( $queued( 'spot', (string) $place ), $item['photos'], $item['credit'] ?? null ) );
		Outbox::clear();
		Subteams::set_youth( $alpha, $juniors, true, $ana );
		$check( 'and when it is put back, the Spot is queued so the hub\'s copy loses them', 1 === $queued( 'spot', (string) $place ) && array() === Exchange::item( $place )['photos'] );
		Outbox::clear();
	}//end if

	Subteams::set_youth( $alpha, $juniors, false, $ana );
	$row = Subteams::get( $alpha, $juniors );
	$check( 'a team that stops being one stays closed and names nobody until someone says otherwise', false === $row['youth'] && false === $row['leads'] && 'closed' === $row['join'], $row );

	WP_CLI::log( 'An account that is deleted' );

	$exporters = (array) apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$erasers   = (array) apply_filters( 'wp_privacy_personal_data_erasers', array() );
	$ours      = static fn( array $all ): int => count( array_filter( $all, static fn( $one ): bool => is_array( $one ) && is_array( $one['callback'] ?? null ) && 'PackOut\\Core\\App\\Privacy' === ltrim( (string) ( is_string( $one['callback'][0] ) ? $one['callback'][0] : get_class( $one['callback'][0] ) ), '\\' ) ) );
	$check( 'WordPress\'s export and erasure of a person\'s data both ask the plugin', 1 === $ours( $exporters ) && 1 === $ours( $erasers ), array( array_keys( $exporters ), array_keys( $erasers ) ) );
	$types     = array( Schema::SPOT, Schema::MISSION, Schema::TEAM, Schema::IMPACT, Schema::CAMPAIGN, Schema::PLACE, Schema::FEATURE, Schema::PARTNER, Schema::PROGRAM );
	$check( 'no kind of record is deleted with the account that made it', array() === array_filter( $types, static fn( string $type ): bool => false !== get_post_type_object( $type )->delete_with_user ), array_filter( $types, static fn( string $type ): bool => false !== get_post_type_object( $type )->delete_with_user ) );

	$gone   = $user( 'pos_reporter', 'Gone' );
	$went   = $user( 'pos_reporter', 'Went' );
	$theirs = $spot( 'Lifetest drums by the Gone turnout.', 34.72, $gone, array( 'credit_named' => true ) );
	$others = $spot( 'Lifetest drums by the Went turnout.', 34.73, $went, array( 'credit_named' => true ) );
	$named  = static fn( int $id ): string => (string) wp_json_encode( Spots::reported_by( get_post( $id ) ) );
	$check( 'a Spot names its reporter when they asked to be named', false !== strpos( $named( $theirs ), 'Gone Lifeperson' ) && false !== strpos( $named( $others ), 'Went Lifeperson' ), $named( $theirs ) );
	wp_set_current_user( $admin );
	wp_delete_user( $gone );
	clean_post_cache( $theirs );
	$check( 'deleting the account with its content keeps the Spot', Schema::SPOT === get_post_type( $theirs ) && 'publish' === get_post_status( $theirs ) );
	$check( 'which belongs to no account and names nobody', 0 === (int) get_post_field( 'post_author', $theirs ) && '' === (string) get_post_meta( $theirs, '_pos_credit_named', true ) && false === strpos( $named( $theirs ), 'Lifeperson' ), $named( $theirs ) );
	wp_delete_user( $went, $coordinator );
	clean_post_cache( $others );
	$check( 'giving the account\'s content to another person does not make that person the reporter', Schema::SPOT === get_post_type( $others ) && 0 === (int) get_post_field( 'post_author', $others ) && false === strpos( $named( $others ), 'Lifeperson' ), array( (int) get_post_field( 'post_author', $others ), $named( $others ) ) );

	WP_CLI::log( 'The Platform overview' );

	$queues = array_keys( Overview::queues() );
	if ( $hub ) {
		$check( 'packout.spot has a queue of partner figures that are held back, after partners to approve', isset( Overview::queues()['figures'] ) && array_search( 'figures', $queues, true ) === array_search( 'partners', $queues, true ) + 1, $queues );
	} else {
		$check( 'a partner site has no queue of partner figures', ! isset( Overview::queues()['figures'] ), $queues );
	}

	if ( ! $hub ) {
		WP_CLI::log( 'A later version of the terms' );

		$by           = time() + 5 * DAY_IN_SECONDS;
		$played['me'] = array(
			'terms_required' => '2',
			'terms_by'       => $by,
		);
		Connection::refresh();
		$html = $screen();
		$check( 'a site told to accept by a day goes on sending until that day', Connection::sending() && Connection::state()['terms_by'] === $by && '2' === Connection::state()['terms_required'] );
		$check( 'and its screen asks, and says until when', false !== strpos( $html, 'Accept and go on sending' ) && false !== strpos( $html, 'goes on sending until' ) && false === strpos( $html, 'this site sends nothing until an administrator accepts it' ) );
		$played['me']['terms_by'] = time() - 60;
		Connection::refresh();
		$html = $screen();
		$check( 'after that day it sends nothing, and says so', ! Connection::sending() && false !== strpos( $html, 'The partner terms changed. Version 2 is in force' ) && false !== strpos( $html, 'this site sends nothing until an administrator accepts it' ) );
		$played['me'] = array( 'terms_required' => '2' );
		Connection::refresh();
		$check( 'a hub that names no day stops the sending at once, as before', ! Connection::sending() && 0 === Connection::state()['terms_by'] );
		$played['me'] = array();
		Connection::refresh();
		$check( 'a site whose acceptance is current sends', Connection::sending() && '' === Connection::state()['terms_required'] );

		WP_CLI::log( 'An organization that is also on packout.spot' );

		$today  = wp_date( 'Y-m-d' );
		$early  = wp_date( 'Y-m-d', strtotime( '-10 days' ) );
		$since  = wp_date( 'Y-m-d', strtotime( '-5 days' ) );
		$hidden = $org( 'Life Unlisted', $ana, false );
		$day( $alpha, $ana, 'early day', 34.74, 40.0, $early );
		$day( $alpha, $ana, 'late day', 34.75, 7.0, $today );
		$of    = static function ( string $scope, string $id ): float {
			$mine = array_values( array_filter( Snapshots::all(), static fn( array $one ): bool => $scope === $one['scope'] && $id === $one['remote_id'] ) );
			return $mine ? (float) $mine[0]['outputs']['pounds_measured'] : -1.0;
		};
		$whole = $of( 'partner', (string) Partners::site() );
		$check( 'before anything is said, the site sends all of the organization\'s work', 47.0 === $of( 'organization', (string) $alpha ), $of( 'organization', (string) $alpha ) );

		$there              = $origin . '/organization/life-alpha/';
		$played['requests'] = array();
		$check(
			'only an administrator says an organization is also on packout.spot',
			'packout_forbidden' === $code( Connection::claim( $alpha, $there, $ana ) ) && is_wp_error(
				Connection_Screen::perform(
					'claim',
					'',
					false,
					false,
					$coordinator,
					array(
						'team'    => $alpha,
						'address' => $there,
					)
				)
			)
		);
		$check( 'the address has to be one of packout.spot\'s', 'packout_claim_address' === $code( Connection::claim( $alpha, 'https://elsewhere.example/organization/life-alpha/', $admin ) ) && 'packout_claim_address' === $code( Connection::claim( $alpha, '', $admin ) ) );
		$check( 'and the organization one the site lists', 'packout_not_listed' === $code( Connection::claim( $hidden, $there, $admin ) ) );
		$check( 'none of which asked the hub anything', array() === $sent( 'POST', '/claims' ) );

		$played['claims'] = array(
			array(
				'organization' => (string) $alpha,
				'state'        => 'asked',
				'day'          => '',
				'until'        => '',
				'name'         => 'Life Alpha on the hub',
				'url'          => $there,
			),
		);
		$done             = Connection_Screen::perform(
			'claim',
			'',
			false,
			false,
			$admin,
			array(
				'team'    => $alpha,
				'address' => $there,
			)
		);
		$asked            = $sent( 'POST', '/claims' );
		$check( 'the request is signed, fits the contract, and names the organization and the address', 'claimed' === $done && 1 === count( $asked ) && $asked[0]['signed'] && '' === $asked[0]['fault'] && (string) $alpha === $asked[0]['body']['organization'] && $there === $asked[0]['body']['address'], array( $said( $done ), $asked ) );
		$check( 'and names no person', false === strpos( (string) wp_json_encode( $asked ), 'Lifeperson' ) && false === strpos( (string) wp_json_encode( $asked ), '@' ) );
		$check( 'the site knows the request waits, and counts as before', 'asked' === ( Connection::claims()[ $alpha ]['state'] ?? '' ) && '' === Connection::claim_day( $alpha ) && 47.0 === $of( 'organization', (string) $alpha ), Connection::claims() );
		$html = $screen();
		$check( 'the screen says how it stands', false !== strpos( $html, 'An organization that is already on packout.spot' ) && false !== strpos( $html, 'Life Alpha on the hub' ) && false !== strpos( $html, esc_url( $there ) ) );

		$fired                        = did_action( 'packout_connection_claims' );
		$played['claims'][0]['state'] = 'accepted';
		$played['claims'][0]['day']   = $since;
		Connection::refresh();
		$check( 'when the lead there accepts, the site learns the day', 'accepted' === Connection::claims()[ $alpha ]['state'] && Connection::claim_day( $alpha ) === $since && 1 === did_action( 'packout_connection_claims' ) - $fired, Connection::claims() );
		$check( 'what the organization did before that day is in no total the site sends', 7.0 === $of( 'organization', (string) $alpha ) && $whole - 40.0 === $of( 'partner', (string) Partners::site() ), array( $of( 'organization', (string) $alpha ), $whole, $of( 'partner', (string) Partners::site() ) ) );
		$check( 'the site\'s own pages still count all of it', 47.0 === (float) Impact::totals( array( 'team' => $alpha ) )['outputs']['pounds_measured'], Impact::totals( array( 'team' => $alpha ) )['outputs'] );
		$check( 'the totals are queued to be sent again', 1 === $queued( 'stats', 'stats' ) );
		Connection::refresh();
		$check( 'hearing the same again changes nothing', 1 === did_action( 'packout_connection_claims' ) - $fired );

		$played['claims'][0]['url'] = 'https://elsewhere.example/organization/life-alpha/';
		Connection::refresh();
		$check( 'an address that is not packout.spot\'s is dropped', '' === Connection::claims()[ $alpha ]['url'] && 'Life Alpha on the hub' === Connection::claims()[ $alpha ]['name'] );
		$played['claims'][0]['url'] = $there;

		$played['requests']           = array();
		$played['claims'][0]['state'] = 'ended';
		$played['claims'][0]['until'] = $today;
		$done                         = Connection_Screen::perform( 'unclaim', '', false, false, $admin, array( 'team' => $alpha ) );
		$asked                        = $sent( 'POST', '/claims' );
		$check( 'the site can end it, with a signed request that fits the contract', 'unclaimed' === $done && 1 === count( $asked ) && $asked[0]['signed'] && '' === $asked[0]['fault'] && true === $asked[0]['body']['withdraw'] && (string) $alpha === $asked[0]['body']['organization'], array( $said( $done ), $asked ) );
		$check( 'and still leaves out what packout.spot counts from its own records', 'ended' === Connection::claims()[ $alpha ]['state'] && Connection::claim_day( $alpha ) === $since && 7.0 === $of( 'organization', (string) $alpha ) );
		Outbox::clear();

		WP_CLI::log( 'A copy of what packout.spot holds' );

		$played['requests'] = array();
		$copy               = Connection::export();
		$asked              = $sent( 'GET', '/export' );
		$check( 'the site asks for it with a signed request and is given it', is_array( $copy ) && isset( $copy['exported'], $copy['listings'] ) && 1 === count( $asked ) && $asked[0]['signed'], $said( $copy ) );
		$check( 'the screen offers it beside leaving', false !== strpos( $screen(), 'A copy of what packout.spot holds' ) );

		WP_CLI::log( 'The site at a new address' );

		Connection::remember( array( 'home' => 'https://old.example.test' ) );
		$played['requests'] = array();
		$played['proved']   = null;
		$html               = $screen();
		$check( 'a site that is not where it connected sends nothing and is asked which it is', ! Connection::at_home() && ! Connection::sending() && false !== strpos( $html, 'The site moved here' ) && false !== strpos( $html, 'Tell packout.spot the site moved' ) && false !== strpos( $html, 'This is a copy' ) );
		$check( 'only an administrator tells the hub', is_wp_error( Connection_Screen::perform( 'move', '', false, false, $coordinator ) ) && array() === $sent( 'POST', '/move' ) );
		$done  = Connection_Screen::perform( 'move', '', false, false, $admin );
		$asked = $sent( 'POST', '/move' );
		$check( 'telling the hub is a signed request that fits the contract and gives the address the site has now', 'moved_here' === $done && 1 === count( $asked ) && $asked[0]['signed'] && '' === $asked[0]['fault'] && untrailingslashit( home_url() ) === $asked[0]['body']['site_url'], array( $said( $done ), $asked ) );
		$check( 'while it waits, the site answers the hub\'s question with the challenge of that request, signed with its key', true === $played['proved'] );
		$check( 'afterwards the site is at home, keeps no challenge, and sends again', Connection::at_home() && Connection::sending() && '' === Connection::state()['challenge'] );
		$check( 'and everything is queued, since every address the hub held was the old one', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pos_outbox" ) > 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		Outbox::clear();

		WP_CLI::log( 'A lost key' );

		$old = (string) Connection::key()['public'];
		delete_option( Connection::KEY_OPTION );
		$played['requests'] = array();
		$html               = $screen();
		$check( 'a connected site with no key knows it, and sends nothing', Connection::lost() && ! Connection::sending() && ! Connection::connected() );
		$check( 'its screen says how to get a new one', false !== strpos( $html, 'Get a new key' ) && false !== strpos( $html, 'Send the email' ) && false !== strpos( $html, 'Finish with a new key' ) && false === strpos( $html, 'Send now' ) );
		$check( 'only an administrator asks', is_wp_error( Connection_Screen::perform( 'recover', '', false, false, $coordinator ) ) && array() === $sent( 'POST', '/recover' ) );
		$done  = Connection_Screen::perform( 'recover', '', false, false, $admin );
		$asked = $sent( 'POST', '/recover' );
		$check( 'asking for the email is an unsigned request that fits the contract and carries the site\'s identifier and no key', 'recover' === $done && 1 === count( $asked ) && ! $asked[0]['signed'] && '' === $asked[0]['fault'] && Connection::state()['site'] === $asked[0]['body']['site'] && ! isset( $asked[0]['body']['public_key'] ) && Connection::state()['recovering'] > 0, array( $said( $done ), $asked ) );
		$check( 'the screen says the email was asked for', false !== strpos( $screen(), 'Send the email again' ) );

		$played['requests'] = array();
		$played['open']     = false;
		$played['proved']   = null;
		$done               = Connection_Screen::perform( 'recover_finish', '', false, false, $admin );
		$check( 'finishing before the link was followed is refused, and the site keeps no key it made', 'recovery_closed' === $code( $done ) && null === Connection::key() && Connection::lost() && '' === Connection::state()['challenge'], $said( $done ) );

		$played['requests'] = array();
		$played['open']     = true;
		$done               = Connection_Screen::perform( 'recover_finish', '', false, false, $admin );
		$asked              = $sent( 'POST', '/recover' );
		$new                = Connection::key();
		$check( 'after it was, the site gives the hub a new public key in a request that fits the contract', 'recovered' === $done && 1 === count( $asked ) && ! $asked[0]['signed'] && '' === $asked[0]['fault'] && $new && $new['public'] === $asked[0]['body']['public_key'] && $new['public'] !== $old, array( $said( $done ), array_keys( (array) ( $asked[0]['body'] ?? array() ) ) ) );
		$check( 'and answers the hub\'s question with the challenge of that request, signed with the new key', true === $played['proved'] );
		$check( 'the request does not carry the secret half', $new && false === strpos( (string) wp_json_encode( $asked ), (string) $new['secret'] ) );
		$check( 'the site is connected again and sends', ! Connection::lost() && Connection::connected() && Connection::sending() && 0 === Connection::state()['recovering'] && '' === Connection::state()['challenge'] );
		$check( 'a site that has its key has nothing to recover', 'packout_not_lost' === $code( Connection::recover_finish( $admin ) ) );

		update_option(
			Connection::NOTICES,
			array(
				array(
					'at'   => time(),
					'kind' => 'listing_hidden',
					'text' => 'A listing was hidden.',
				),
			),
			false
		);
		$told = count( Connection::notices() );
		Connection::disconnect( true );
		$check( 'a site that leaves keeps nothing the hub told it about the connection that ended', 1 === $told && array() === Connection::notices() && array() === Connection::claims() && ! Connection::connected() && null === Connection::key() );

		Connection::forget();
		$check( 'a site that forgets its connection forgets what stood between its organizations and the hub\'s', array() === Connection::claims() && '' === Connection::claim_day( $alpha ) );
	}//end if
} finally {
	remove_filter( 'pre_http_request', $answer, 5 );
	remove_filter( 'pre_wp_mail', $keep_mail, 99 );
	wp_set_current_user( 0 );

	foreach ( array_values( array_unique( array_filter( $packout_posts, static fn( $id ): bool => is_int( $id ) && $id > 0 ) ) ) as $post_id ) {
		foreach ( get_children(
			array(
				'post_parent' => $post_id,
				'post_type'   => 'attachment',
				'fields'      => 'ids',
			)
		) as $attachment ) {
			wp_delete_attachment( (int) $attachment, true );
		}
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
	foreach ( $packout_files as $path ) {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
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
		if ( get_userdata( (int) $user_id ) ) {
			wp_delete_user( (int) $user_id );
		}
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
	Impact::flush();
	Teams::flush();
	Overview::forget();
}//end try

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( count( $packout_failures ) . ' checks failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
