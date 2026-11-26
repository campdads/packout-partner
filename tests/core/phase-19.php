<?php
/**
 * Phase 19 acceptance checks: a partner site's connection to packout.spot.
 *
 * What a site sends is built from its public records and names no person.
 * A site connects, proves its address, sends what changed after the page
 * that changed it has been answered, tries again when the hub is away, and
 * stops when the hub says to.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-19.php
 *
 * The hub is played in place: every request the site would make is caught
 * before it leaves, checked against the contract and the signature, and
 * answered here. Nothing leaves the machine. The checks create their own
 * users and records and delete them, and put the site's own connection,
 * if it has one, back as it was.
 *
 * @package PackOut_Core
 */

use PackOut\Core\Contract;
use PackOut\Core\Signing;
use PackOut\Core\App\Campaigns;
use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Connection;
use PackOut\Core\App\Connection_Screen;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Listings;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Outbox;
use PackOut\Core\App\Overview;
use PackOut\Core\App\Partners;
use PackOut\Core\App\Programs;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Snapshots;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Subteams;
use PackOut\Core\App\Teams;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

// What only a partner site does is checked there. On packout.spot it is left out, and the run says so.
$hub  = \PackOut\Core\App\App::is_hub();
$skip = static function ( string $what ): void {
	WP_CLI::log( '  --  ' . $what );
};

global $wpdb;

$packout_failures = array();
$packout_passed   = 0;
$packout_posts    = array();
$packout_users    = array();
$packout_run      = strtolower( wp_generate_password( 6, false ) );
$packout_kept     = array();
$packout_rows     = array();
$packout_logged   = PHP_INT_MAX;

$check = static function ( string $label, bool $ok, $detail = '' ) use ( &$packout_failures, &$packout_passed ): void {
	if ( $ok ) {
		++$packout_passed;
		WP_CLI::log( '  ok    ' . $label );
		return;
	}
	$packout_failures[] = $label;
	WP_CLI::log( '  FAIL  ' . $label . ( '' !== $detail && array() !== $detail ? ' — ' . ( is_scalar( $detail ) ? $detail : wp_json_encode( $detail ) ) : '' ) );
};

$as   = static function ( int $user_id ): void {
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
			'display_name' => $name . ' Syncperson',
			'role'         => $role,
		)
	);
	$packout_users[] = $id;
	return $id;
};

// Mail stays in memory. Nothing leaves the machine.
$keep_mail = static fn() => true;
add_filter( 'pre_wp_mail', $keep_mail, 99 );

/*
 * The hub, played in place.
 */
$origin = Connection::hub();
$played = array(
	'requests'  => array(),
	'site'      => bin2hex( random_bytes( 16 ) ),
	'challenge' => bin2hex( random_bytes( 32 ) ),
	'public'    => '',
	'state'     => 'pending',
	'confirmed' => false,
	'proved'    => false,
	'terms'     => '1',
	'accepted'  => '',
	'minimum'   => '0.1.0',
	'notices'   => array(),
	// Set to make the hub unreachable.
	'down'      => false,
	// One answer to give instead of the usual one, by route, used once.
	'next'      => array(),
	// Listings to refuse, as `kind:id` => reason.
	'refuse'    => array(),
	// Seconds the hub's clock is ahead of the site's.
	'ahead'     => 0,
);
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
$wrong  = static fn( int $status, string $code, array $data = array() ): array => $reply(
	$status,
	array(
		'code'    => $code,
		'message' => 'The hub said ' . $code . '.',
		'data'    => array( 'status' => $status ) + $data,
	)
);
$answer = static function ( $pre, $args, $url ) use ( &$played, $origin, $reply, $wrong ) {
	$base = $origin . Connection::BASE;
	if ( 0 !== strpos( (string) $url, $base ) ) {
		return $pre;
	}
	$method  = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
	$route   = (string) strtok( substr( (string) $url, strlen( $base ) ), '?' );
	$raw     = (string) ( $args['body'] ?? '' );
	$body    = '' !== $raw ? json_decode( $raw, true ) : null;
	$headers = (array) ( $args['headers'] ?? array() );
	$signed  = isset( $headers[ Signing::SIGNATURE ] );
	$schemas = array(
		'POST /register'   => 'register',
		'POST /verify'     => 'signed',
		'POST /terms'      => 'signed',
		'PUT /profile'     => 'profile',
		'POST /listings'   => 'listings',
		'POST /stats'      => 'stats',
		'POST /key'        => 'key',
		'POST /disconnect' => 'signed',
	);
	$entry   = array(
		'method'  => $method,
		'route'   => $route,
		'url'     => (string) $url,
		'raw'     => $raw,
		'body'    => is_array( $body ) ? $body : array(),
		'headers' => $headers,
		'signed'  => $signed,
		'good'    => true,
		'fault'   => isset( $schemas[ $method . ' ' . $route ] ) ? Contract::fault( $schemas[ $method . ' ' . $route ], $body ) : '',
	);
	if ( $played['down'] ) {
		$played['requests'][] = $entry;
		return new WP_Error( 'http_request_failed', 'Could not resolve host.' );
	}
	$now = time() + $played['ahead'];
	if ( $signed ) {
		$stamp         = (int) ( $headers[ Signing::TIMESTAMP ] ?? 0 );
		$text          = Signing::text( $method, Signing::path( (string) $url ), $raw, $stamp, (string) ( $headers[ Signing::NONCE ] ?? '' ) );
		$entry['good'] = ( $headers[ Signing::KEY ] ?? '' ) === $played['site'] && Signing::verify( $text, (string) $headers[ Signing::SIGNATURE ], $played['public'] );
		if ( ! Signing::fresh( $stamp, $now ) ) {
			$entry['stale']       = true;
			$played['requests'][] = $entry;
			return $wrong( 401, 'stale', array( 'now' => $now ) );
		}
		if ( ! $entry['good'] ) {
			$played['requests'][] = $entry;
			return $wrong( 401, 'bad_signature' );
		}
	}
	$played['requests'][] = $entry;
	if ( isset( $played['next'][ $route ] ) ) {
		$given = $played['next'][ $route ];
		unset( $played['next'][ $route ] );
		return $given;
	}
	if ( '' !== $entry['fault'] ) {
		return $wrong( 422, 'invalid', array( 'path' => $entry['fault'] ) );
	}
	$standing = static function () use ( &$played, $now ): array {
		return array(
			'state'           => $played['state'],
			'confirmed'       => $played['confirmed'],
			'proved'          => $played['proved'],
			'partner_url'     => 'active' === $played['state'] ? 'https://packout.spot/partner/played/' : '',
			'minimum_version' => $played['minimum'],
			'current_version' => '9.9.9',
			'contract'        => Contract::version(),
			'terms_version'   => $played['terms'],
			'terms_url'       => 'https://packout.spot/partner-terms/',
			'terms_required'  => $played['terms'] === $played['accepted'] ? '' : $played['terms'],
			'notices'         => $played['notices'],
			'now'             => $now,
		);
	};
	$open     = in_array( $played['state'], array( 'active', 'dormant' ), true );
	switch ( $method . ' ' . $route ) {
		case 'GET /terms':
			return $reply(
				200,
				array(
					'version' => $played['terms'],
					'url'     => 'https://packout.spot/partner-terms/',
					'open'    => true,
				)
			);
		case 'POST /register':
			$played['public']   = (string) $entry['body']['public_key'];
			$played['accepted'] = (string) $entry['body']['terms_version'];
			return $reply(
				201,
				array(
					'site'      => $played['site'],
					'challenge' => $played['challenge'],
					'state'     => 'pending',
				)
			);
		case 'POST /verify':
			$played['proved'] = true;
			return $reply( 200, $standing() );
		case 'GET /me':
			return $reply( 200, $standing() );
		case 'POST /terms':
			if ( ( $entry['body']['terms_version'] ?? '' ) !== $played['terms'] ) {
				return $wrong( 409, 'terms_changed' );
			}
			$played['accepted'] = $played['terms'];
			return $reply( 200, $standing() );
		case 'PUT /profile':
			return $open ? $reply( 200, array( 'saved' => true ) ) : $wrong( 403, 'partner_' . $played['state'] );
		case 'POST /listings':
			if ( ! $open ) {
				return $wrong( 403, 'partner_' . $played['state'] );
			}
			$items = array();
			foreach ( $entry['body']['items'] as $item ) {
				$key     = $item['kind'] . ':' . $item['remote_id'];
				$items[] = array(
					'kind'      => $item['kind'],
					'remote_id' => $item['remote_id'],
				) + ( isset( $played['refuse'][ $key ] ) ? array(
					'result' => 'refused',
					'reason' => $played['refuse'][ $key ],
				) : array( 'result' => ! empty( $item['deleted'] ) ? 'deleted' : 'updated' ) );
			}
			return $reply( 200, array( 'items' => $items ) );
		case 'POST /stats':
			if ( ! $open ) {
				return $wrong( 403, 'partner_' . $played['state'] );
			}
			return $reply(
				200,
				array(
					'items' => array_map(
						static fn( array $item ): array => array(
							'scope'     => $item['scope'],
							'remote_id' => $item['remote_id'],
							'result'    => 'kept',
						),
						$entry['body']['items']
					),
				)
			);
		// The Spot exchange, answered plainly: tests/site/phase-20.php is where it is checked.
		case 'POST /spots':
			if ( ! $open ) {
				return $wrong( 403, 'partner_' . $played['state'] );
			}
			$items = array();
			foreach ( $entry['body']['items'] as $item ) {
				$id = (string) $item['remote_id'];
				if ( 'removed' === $item['state'] ) {
					unset( $played['spots'][ $id ] );
					$items[] = array(
						'remote_id' => $id,
						'result'    => 'deleted',
					);
					continue;
				}
				$played['spots'][ $id ] = array( (int) $id, (int) $item['rev'], (string) $item['state'] );
				$items[]                = array(
					'remote_id' => $id,
					'result'    => 'updated',
					'photos'    => array(),
					'standing'  => 'shown',
					'reason'    => '',
					'hints'     => array(),
				);
			}
			return $reply( 200, array( 'items' => $items ) );
		case 'GET /spots/index':
			return $open ? $reply(
				200,
				array(
					'items' => array_values( $played['spots'] ?? array() ),
					'more'  => false,
				)
			) : $wrong( 403, 'partner_' . $played['state'] );
		case 'GET /changes':
			return $open ? $reply(
				200,
				array(
					'now'   => time(),
					'spots' => array(),
				)
			) : $wrong( 403, 'partner_' . $played['state'] );
		case 'POST /key':
			$played['public'] = (string) $entry['body']['public_key'];
			return $reply( 200, array( 'replaced' => true ) );
		case 'POST /disconnect':
			$played['state']  = 'disconnected';
			$played['public'] = '';
			return $reply( 200, array( 'state' => 'disconnected' ) );
	}//end switch
	return $wrong( 404, 'rest_no_route' );
};
add_filter( 'pre_http_request', $answer, 5, 3 );

// The requests the hub heard since a mark, by method and route. Those of the Spot exchange are left out: they have their own checks.
$heard = static function ( int $since = 0 ) use ( &$played ): array {
	$mine = array_filter( array_slice( $played['requests'], $since ), static fn( array $entry ): bool => 0 !== strpos( $entry['route'], '/spots' ) && '/changes' !== $entry['route'] );
	return array_values( array_map( static fn( array $entry ): string => $entry['method'] . ' ' . $entry['route'], $mine ) );
};
$last  = static function ( string $route ) use ( &$played ): array {
	foreach ( array_reverse( $played['requests'] ) as $entry ) {
		if ( $entry['route'] === $route ) {
			return $entry;
		}
	}
	return array();
};
$mark  = static function () use ( &$played ): int {
	return count( $played['requests'] );
};
// The listings of a request, by kind and identifier.
$sent = static function ( array $entry ): array {
	$out = array();
	foreach ( (array) ( $entry['body']['items'] ?? array() ) as $item ) {
		$out[ ( $item['kind'] ?? $item['scope'] ) . ':' . $item['remote_id'] ] = $item;
	}
	return $out;
};
// The rows waiting, as `kind subject`.
$queue = static fn(): array => array_map( static fn( array $row ): string => $row['kind'] . ' ' . $row['subject'], Outbox::rows( 200 ) );
// A new request: what was queued once in the last one may be queued again.
$fresh = static function (): void {
	$seen = new ReflectionProperty( Outbox::class, 'queued' );
	$seen->setAccessible( true );
	$seen->setValue( null, array() );
};

try {
	Capabilities::install();
	Schema::register();

	$coordinator = $user( 'pos_coordinator', 'Coordinator' );
	$admin       = $user( 'administrator', 'Admin' );
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
	$ana    = $user( 'pos_lead', 'Ana' );
	$mem    = $user( 'pos_reporter', 'Mem' );

	if ( ! $hub ) {
		// The site's own connection, if it has one, is set aside and put back at the end.
		foreach ( array( Connection::OPTION, Connection::KEY_OPTION, Connection::NOTICES ) as $option ) {
			$packout_kept[ $option ] = get_option( $option, null );
			delete_option( $option );
		}
		Outbox::install_tables();
		// The log's lines from before the checks stay. Only the ones the checks write are read and removed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$packout_logged = (int) $wpdb->get_var( "SELECT COALESCE( MAX( id ), 0 ) FROM {$wpdb->prefix}pos_sync_log" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$packout_rows = (array) $wpdb->get_results( "SELECT kind, subject, queued, attempts, next_try, last_error FROM {$wpdb->prefix}pos_outbox", ARRAY_A );
		Outbox::clear();
		Outbox::unschedule();
		delete_transient( 'packout_partner_terms' );
		delete_transient( 'packout_outbox_lock' );
	}

	$as( $coordinator );
	if ( ! $hub && Partners::site() > 0 ) {
		// A partner that is not listed has no public programs. It is listed while the checks run.
		$packout_kept['listed'] = 'publish' === get_post_status( Partners::site() );
		Partners::make_site( $coordinator, array( 'listed' => true ) );
	}
	$org_name        = 'Synctest Creek Crew ' . $packout_run;
	$org             = Teams::save(
		0,
		array(
			'name'        => $org_name,
			'description' => 'We clean <b>the creek</b>.',
			'lead'        => $ana,
			'listed'      => true,
		)
	);
	$packout_posts[] = $org;
	if ( is_wp_error( $org ) ) {
		WP_CLI::error( 'Organization: ' . $org->get_error_message() );
	}
	$org = (int) $org;
	update_post_meta( $org, '_pos_region', 'Mill Valley' );
	update_post_meta( $org, '_pos_website', 'https://creekcrew.example.org' );
	Teams::add_member( $org, get_userdata( $mem )->user_login );
	$quiet_org       = Teams::save(
		0,
		array(
			'name'   => 'Synctest Quiet Crew ' . $packout_run,
			'lead'   => $ana,
			'listed' => false,
		)
	);
	$packout_posts[] = $quiet_org;
	$quiet_org       = (int) $quiet_org;

	$crew  = (int) Subteams::save( $org, 0, array( 'name' => 'Synctest Saturday team ' . $packout_run ), $ana );
	$inner = (int) Subteams::save( $org, 0, array( 'name' => 'Synctest inner team ' . $packout_run ), $ana );
	$paged = Subteams::update_page(
		$org,
		$crew,
		array(
			'about'  => 'Out every Saturday.',
			'public' => true,
		),
		$ana
	);
	if ( is_wp_error( $paged ) ) {
		WP_CLI::error( 'Team: ' . $paged->get_error_message() );
	}

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
	$s1   = $spot( 'Synctest tires at the turnout.', 34.61, $mem );
	$s2   = $spot( 'Synctest cans in the fire ring.', 34.62, $mem );
	$s3   = $spot( 'Synctest glass by the gate.', 34.63, $mem );

	$owner           = $hub ? array(
		'owner_kind' => 'team',
		'owner'      => $org,
	) : array(
		'owner_kind' => 'partner',
		'owner'      => Partners::site(),
	);
	$program         = Programs::save(
		0,
		$owner + array(
			'title'  => 'Synctest Clean Creeks ' . $packout_run,
			'goal'   => 'Fifty Spots cleaned',
			'orgs'   => array( $org ),
			'public' => true,
		),
		$coordinator
	);
	$packout_posts[] = $program;
	$campaign        = Campaigns::save(
		0,
		array(
			'team'     => $org,
			'title'    => 'Synctest Spring Sweep ' . $packout_run,
			'goal'     => 'Twenty Spots',
			'public'   => true,
			'program'  => is_int( $program ) ? $program : 0,
			'subteams' => array( $crew, $inner ),
		),
		$ana
	);
	$packout_posts[] = $campaign;
	$day             = wp_date( 'Y-m-d', strtotime( '+20 days' ) );
	$mission         = Missions::save(
		0,
		array(
			'title'         => 'Synctest Mill Bridge ' . $packout_run,
			'team'          => $org,
			'spots'         => array( $s1, $s2 ),
			'start'         => $day,
			'end'           => $day,
			'start_time'    => '09:00',
			'end_time'      => '12:00',
			'timezone'      => 'America/Chicago',
			'description'   => 'Bring gloves.',
			'meeting_note'  => 'By the mill bridge sign',
			'meeting_share' => true,
			'meeting_lat'   => 34.6123,
			'meeting_lng'   => -111.8123,
			'public'        => true,
			'campaign'      => is_int( $campaign ) ? $campaign : 0,
			'subteams'      => array( $crew ),
		),
		$ana
	);
	$packout_posts[] = $mission;
	$quiet           = Missions::save(
		0,
		array(
			'title'  => 'Synctest quiet day ' . $packout_run,
			'team'   => $org,
			'spots'  => array( $s3 ),
			'start'  => $day,
			'end'    => $day,
			'public' => false,
		),
		$ana
	);
	$packout_posts[] = $quiet;
	foreach ( array(
		'program'       => $program,
		'campaign'      => $campaign,
		'mission'       => $mission,
		'quiet mission' => $quiet,
	) as $what => $made ) {
		if ( is_wp_error( $made ) ) {
			WP_CLI::error( $what . ': ' . $made->get_error_message() );
		}
	}
	$program  = (int) $program;
	$campaign = (int) $campaign;
	$mission  = (int) $mission;
	$quiet    = (int) $quiet;
	Missions::set_status( $mission, 'in_progress' );
	Spots::mark_cleaned( $s1, $ana, $mission );
	$record          = Impact::save_cleanup(
		$s1,
		array(
			'narrative'     => 'Synctest haul. ' . $packout_run,
			'hours'         => 2,
			'people'        => 3,
			'pounds'        => 120,
			'pounds_source' => 'measured',
		),
		$ana
	);
	$packout_posts[] = $record;
	if ( is_wp_error( $record ) ) {
		WP_CLI::error( 'Record: ' . $record->get_error_message() );
	}
	Impact::verify( (int) $record, $coordinator );
	Impact::set_visible( (int) $record, true, $coordinator );
	$as( 0 );

	WP_CLI::log( 'What a site lists' );

	$item = Listings::item( 'organization', (string) $org );
	$check( 'an organization is listed with its name, where it works, and its page', $org_name === $item['name'] && 'We clean the creek.' === $item['description'] && 'Mill Valley' === $item['region'] && 'https://creekcrew.example.org' === $item['website'] && get_permalink( $org ) === $item['url'] && empty( $item['deleted'] ), $item );
	$check( 'and the teams of it that have a public page, and no other', array( $org . '-' . $crew ) === $item['teams'], $item['teams'] );
	$team_item = Listings::item( 'team', $org . '-' . $crew );
	$check( 'a team is listed under its organization', (string) $org === $team_item['organization'] && 'Synctest Saturday team ' . $packout_run === $team_item['name'] && 'Out every Saturday.' === $team_item['description'] && Subteams::url( $org, $crew ) === $team_item['url'] && false === $team_item['youth'], $team_item );
	$check( 'a team with no public page is not', ! empty( Listings::item( 'team', $org . '-' . $inner )['deleted'] ) );
	$program_item = Listings::item( 'program', (string) $program );
	$check( 'a program names the organizations taking part', 'Synctest Clean Creeks ' . $packout_run === $program_item['title'] && 'Fifty Spots cleaned' === $program_item['goal'] && array( (string) $org ) === $program_item['organizations'] && 'open' === $program_item['state'], $program_item );
	$campaign_item = Listings::item( 'campaign', (string) $campaign );
	$check( 'a campaign names its organization, its program, and its public teams', (string) $org === $campaign_item['organization'] && (string) $program === $campaign_item['program'] && array( $org . '-' . $crew ) === $campaign_item['teams'], $campaign_item );
	$mission_item = Listings::item( 'mission', (string) $mission );
	$check( 'a mission has its day, its times, and the zone they are in', $day === $mission_item['start'] && '09:00' === $mission_item['start_time'] && '12:00' === $mission_item['end_time'] && 'America/Chicago' === $mission_item['timezone'] && 'in_progress' === $mission_item['status'], $mission_item );
	$check( 'what it belongs to, and how many Spots it has', (string) $org === $mission_item['organization'] && (string) $campaign === $mission_item['campaign'] && (string) $program === $mission_item['program'] && array( $org . '-' . $crew ) === $mission_item['teams'] && 2 === $mission_item['spots'], $mission_item );
	$check( 'and the landmark its lead chose to share, without the pin', 'By the mill bridge sign' === $mission_item['meeting'] && false === strpos( (string) wp_json_encode( $mission_item ), '34.61' ) );
	Missions::save( $mission, array( 'meeting_share' => false ), $ana );
	$check( 'a landmark that is not shared is not sent', '' === Listings::item( 'mission', (string) $mission )['meeting'] );
	Missions::save( $mission, array( 'meeting_share' => true ), $ana );
	$zoneless = Listings::item( 'campaign', (string) $campaign );
	$check( 'a record that names no zone is sent in the site\'s', '' !== $zoneless['timezone'] && \PackOut\Core\App\Schedule::site_zone() === $zoneless['timezone'], $zoneless['timezone'] );

	$gone = Listings::item( 'mission', (string) $quiet );
	$check( 'a mission that is not public is sent as no longer listed, with nothing about it', ! empty( $gone['deleted'] ) && array( 'deleted', 'kind', 'remote_id', 'rev', 'updated' ) === array_values( array_intersect( array( 'deleted', 'kind', 'remote_id', 'rev', 'updated' ), array_keys( $gone ) ) ) && 5 === count( $gone ), $gone );
	$check( 'and so is an organization that is not listed, and one that does not exist', ! empty( Listings::item( 'organization', (string) $quiet_org )['deleted'] ) && ! empty( Listings::item( 'organization', '999999999' )['deleted'] ) );

	$everything = array( $item, $team_item, $program_item, $campaign_item, $mission_item, $gone );
	$check( 'every listing fits the contract', '' === Contract::fault( 'listings', array( 'items' => $everything ) + Connection::versions() ), Contract::fault( 'listings', array( 'items' => $everything ) + Connection::versions() ) );
	$text = (string) wp_json_encode( $everything );
	$check( 'and none names a person, a member, or an address to write to', false === strpos( $text, 'Syncperson' ) && false === strpos( $text, '@example.test' ) && false === strpos( $text, 'pos-test-' ) && false === stripos( $text, 'lead' ) && false === stripos( $text, 'member' ) );

	$all   = Listings::all();
	$mine  = array_values( array_filter( $all, static fn( array $entry ): bool => in_array( $entry[1], array( (string) $org, $org . '-' . $crew, (string) $program, (string) $campaign, (string) $mission ), true ) ) );
	$kinds = array_values( array_unique( array_column( $all, 0 ) ) );
	$check( 'a full send has every public record, the ones referred to first', array( array( 'organization', (string) $org ), array( 'team', $org . '-' . $crew ), array( 'program', (string) $program ), array( 'campaign', (string) $campaign ), array( 'mission', (string) $mission ) ) === $mine && array_values( array_intersect( array_keys( Listings::kinds() ), $kinds ) ) === $kinds, array( $mine, $kinds ) );
	$check( 'and nothing that is not public', ! in_array( array( 'mission', (string) $quiet ), $all, true ) && ! in_array( array( 'organization', (string) $quiet_org ), $all, true ) && ! in_array( array( 'team', $org . '-' . $inner ), $all, true ) );

	$before = Listings::rev( $org );
	$raised = Listings::bump( $org );
	$check( 'a record\'s revision only rises, and follows the clock', $raised > $before && $raised >= time() - 2 && Listings::bump( $org ) === $raised + 1 );

	WP_CLI::log( 'Figures only' );

	$figures = Snapshots::figures(
		array(
			'count'   => 3,
			'share'   => 0.5,
			'stale'   => true,
			'none'    => null,
			'at'      => '2026-11-25T10:00:00+00:00',
			'day'     => '2026-11-25',
			'reading' => '7 Spots joined a mission.',
			'name'    => 'Ana Syncperson',
			'Bad Key' => 4,
			'url'     => 'https://example.test/x',
			'rows'    => array(
				array(
					'count' => 1,
					'label' => 'Tires',
				),
				array( 'count' => 2 ),
			),
			'who'     => array( 'Creek Crew', 'Hill Hikers' ),
		)
	);
	$check( 'numbers, yes and no, days, and times are kept', 3 === $figures['count'] && 0.5 === $figures['share'] && true === $figures['stale'] && array_key_exists( 'none', $figures ) && '2026-11-25T10:00:00+00:00' === $figures['at'] && '2026-11-25' === $figures['day'] );
	$check( 'a sentence, a name, an address, and an odd key are dropped', ! isset( $figures['reading'] ) && ! isset( $figures['name'] ) && ! isset( $figures['url'] ) && ! isset( $figures['Bad Key'] ) && array( array( 'count' => 1 ), array( 'count' => 2 ) ) === $figures['rows'] && array( null, null ) === $figures['who'], $figures );

	$many = array();
	for ( $i = 0; $i < 2 * Contract::BATCH + 50; $i++ ) {
		$many[] = array(
			'kind'      => 'mission',
			'remote_id' => (string) $i,
		);
	}
	$check( 'what is sent is split into batches the hub takes', array( Contract::BATCH, Contract::BATCH, 50 ) === array_map( 'count', Outbox::batches( $many ) ) );
	$heavy   = array(
		'kind'        => 'mission',
		'remote_id'   => '1',
		'description' => str_repeat( 'x', 400000 ),
	);
	$batches = Outbox::batches( array( $heavy, $heavy, $heavy, $heavy ) );
	$check( 'by size as well as by count', array( 2, 2 ) === array_map( 'count', $batches ) && strlen( (string) wp_json_encode( $batches[0] ) ) < Contract::BODY, array_map( 'count', $batches ) );
	$bloated = Outbox::batches(
		array(
			array(
				'scope'     => 'partner',
				'remote_id' => '1',
				'overview'  => array( 'now' => array_fill( 0, 400, str_repeat( '9', 4000 ) ) ),
			),
		)
	);
	$check( 'totals whose overview is too large to send go without it', 1 === count( $bloated ) && ! isset( $bloated[0][0]['overview'] ) && 'partner' === $bloated[0][0]['scope'] );
	$check( 'a send that fails waits longer each time, and then every six hours', array( 60, 300, 900, 3600, 21600, 21600, 21600 ) === array_map( array( Outbox::class, 'wait' ), array( 1, 2, 3, 4, 5, 6, 40 ) ) );

	if ( $hub ) {
		$skip( 'packout.spot is the hub: it has no connection of its own, no outbox, and sends nothing' );
		$check( 'the hub does not answer a challenge', 404 === rest_do_request( new WP_REST_Request( 'GET', '/packout/v1/hub-challenge' ) )->get_status() );
		$check( 'and it connects to nothing', is_wp_error( Connection::connect( '1', $admin ) ) && ! Connection::connected() && array() === Snapshots::all() );
	} else {
		$partner = Partners::site();
		if ( $partner <= 0 ) {
			WP_CLI::error( 'This partner site has no partner record.' );
		}

		WP_CLI::log( 'The totals a site sends' );

		$snapshots = Snapshots::all( true );
		$by        = array();
		foreach ( $snapshots as $snapshot ) {
			$by[ $snapshot['scope'] . ':' . $snapshot['remote_id'] ] = $snapshot;
		}
		$check( 'the partner and each listed organization have a snapshot of today', isset( $by[ 'partner:' . $partner ], $by[ 'organization:' . $org ] ) && wp_date( 'Y-m-d' ) === $by[ 'partner:' . $partner ]['day'] && ! isset( $by[ 'organization:' . $quiet_org ] ), array_keys( $by ) );
		$mine = $by[ 'organization:' . $org ];
		$check( 'with hours, people, Spots, and weight, measured apart from self-reported', 1 === $mine['outputs']['spots_cleaned'] && 120.0 === $mine['outputs']['pounds_measured'] && 0.0 === $mine['outputs']['pounds_self_reported'] && 2.0 === $mine['effort']['hours_verified'] && 0.0 === $mine['effort']['hours_unverified'] && 3 === $mine['effort']['people'], $mine );
		$check(
			'and how many records they are from, and how many of those are verified',
			array(
				'total'    => 1,
				'verified' => 1,
				'counted'  => 1,
			) === $mine['records'],
			$mine['records']
		);
		$check( 'a mission, a campaign, and a program with something counted have theirs', isset( $by[ 'mission:' . $mission ], $by[ 'campaign:' . $campaign ], $by[ 'program:' . $program ] ) && 1 === $by[ 'mission:' . $mission ]['outputs']['spots_cleaned'], array_keys( $by ) );
		$check( 'and one with nothing counted has none', ! isset( $by[ 'mission:' . $quiet ] ) );
		$check( 'the partner and its organizations carry the figures of their overview, for four periods', isset( $mine['overview']['now'], $by[ 'partner:' . $partner ]['overview']['now'] ) && array( '7', '30', '90', '365' ) === array_map( 'strval', array_keys( $mine['overview']['periods'] ) ) && ! isset( $by[ 'mission:' . $mission ]['overview'] ) );
		$check(
			'the same figures the site\'s own overview shows',
			(int) ( Overview::now(
				array(
					'kind' => 'team',
					'id'   => $org,
				)
			)['missions']['count'] ?? -1 ) === (int) ( $mine['overview']['now']['missions']['count'] ?? -2 ) && (int) ( Overview::period(
				30,
				array(
					'kind' => 'team',
					'id'   => $org,
				)
			)['a']['current']['cleaned'] ?? -1 ) === (int) ( $mine['overview']['periods']['30']['a']['current']['cleaned'] ?? -2 ),
			$mine['overview']['now']['missions'] ?? null
		);
		$fault = Contract::fault( 'stats', array( 'items' => $snapshots ) + Connection::versions() );
		$check( 'every snapshot fits the contract', '' === $fault, $fault );
		$text = (string) wp_json_encode( $snapshots );
		$check( 'and none carries a name, a title, or a sentence', false === strpos( $text, 'Synctest' ) && false === strpos( $text, 'Syncperson' ) && false === strpos( $text, 'example.test' ) && 0 === preg_match( '/"[^"]*":"[^"]*[A-Za-z]{4,}[^"]*"/', (string) wp_json_encode( array_column( $snapshots, 'overview' ) ) ), substr( $text, 0, 300 ) );
		$without = Snapshots::all();
		$check( 'a send made during the day carries the totals alone', count( $without ) === count( $snapshots ) && ! isset( $without[0]['overview'] ) );

		WP_CLI::log( 'A site that is not connected' );

		$check( 'a site starts with no connection and no key', ! Connection::connected() && null === Connection::key() && '' === Connection::state()['state'] && ! Connection::sending() );
		$as( $ana );
		$fresh();
		Missions::save( $mission, array( 'description' => 'Bring gloves and water.' ), $ana );
		Outbox::run( true );
		$check( 'it queues nothing and sends nothing', 0 === Outbox::waiting() && array() === $played['requests'] );
		$check( 'and it has no timers running', false === wp_next_scheduled( Outbox::RUN ) && false === wp_next_scheduled( Outbox::NIGHTLY ) );
		$check( 'it does not answer a challenge', 404 === rest_do_request( new WP_REST_Request( 'GET', '/packout/v1/hub-challenge' ) )->get_status() || 400 === rest_do_request( new WP_REST_Request( 'GET', '/packout/v1/hub-challenge' ) )->get_status() );

		WP_CLI::log( 'Connecting' );

		$terms = Connection::terms( true );
		$check( 'the site reads which terms are in force', ! is_wp_error( $terms ) && '1' === $terms['version'] && true === $terms['open'] && array( 'GET /terms' ) === $heard(), $said( $terms ) );
		$check( 'and asks once an hour, not every time', Connection::terms() === $terms && 1 === $mark() );
		$check( 'only an administrator connects the site', 0 === strpos( $said( Connection::connect( '1', $ana ) ), 'packout_forbidden' ) && 0 === strpos( $said( Connection_Screen::perform( 'connect', '1', true, false, $ana ) ), 'packout_forbidden' ) );
		$check( 'and that made no key', null === Connection::key() && 1 === $mark() );
		$html = $screen();
		$check( 'before anything is sent the screen says what will be, and what never is', false !== strpos( $html, 'What is sent' ) && false !== strpos( $html, 'What is never sent' ) && false !== strpos( $html, 'Anything about a person' ) && false !== strpos( $html, 'Connect to packout.spot' ) && false !== strpos( $html, 'https://packout.spot/partner-terms/' ) && false !== strpos( $html, get_userdata( $admin )->user_email ), substr( wp_strip_all_tags( $html ), 0, 300 ) );
		$check( 'and nothing is sent until the terms are accepted', 'error:terms_unticked' === Connection_Screen::perform( 'connect', '1', false, false, $admin ) && null === Connection::key() && 1 === $mark() );

		$connected = Connection::connect( '1', $admin );
		$register  = $last( '/register' );
		$check( 'an administrator connects it', true === $connected && Connection::connected() && 'registered' === Connection::state()['state'] && Connection::state()['site'] === $played['site'], $said( $connected ) );
		$check( 'the registration fits the contract and is not signed', '' === $register['fault'] && ! $register['signed'] && untrailingslashit( home_url() ) === $register['body']['site_url'] && get_userdata( $admin )->user_email === $register['body']['admin_email'] && '1' === $register['body']['terms_version'], array( $register['fault'], $register['body'] ) );
		$key = Connection::key();
		$check( 'the site made a key pair and sent the public half', null !== $key && $key['public'] === $register['body']['public_key'] && $key['public'] === $played['public'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reading how one option is stored.
		$autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Connection::KEY_OPTION ) );
		$check( 'the secret half stays here, in an option that is not loaded with every page', in_array( $autoload, array( 'no', 'off', 'auto-off' ), true ) && false === strpos( (string) wp_json_encode( $played['requests'] ), $key['secret'] ) && false === strpos( (string) wp_json_encode( get_option( Connection::OPTION ) ), $key['secret'] ), $autoload );
		$check( 'the site remembers the address it connected with and the terms it accepted', untrailingslashit( home_url() ) === Connection::state()['home'] && '1' === Connection::state()['terms'] && Connection::at_home() );
		$check( 'a site that is connected does not connect twice', 0 === strpos( $said( Connection::connect( '1', $admin ) ), 'packout_connected' ) );

		$request = new WP_REST_Request( 'GET', '/packout/v1/hub-challenge' );
		$request->set_query_params( array( 'site' => $played['site'] ) );
		$proof = rest_do_request( $request );
		$given = (array) $proof->get_data();
		$check( 'asked for the challenge, the site answers with it, signed by its key', 200 === $proof->get_status() && ( $given['challenge'] ?? '' ) === $played['challenge'] && Signing::verify( Connection::challenge_text( $played['site'], $played['challenge'] ), (string) ( $given['signature'] ?? '' ), $key['public'] ) && array( 'challenge', 'signature' ) === array_keys( $given ), array_keys( $given ) );
		$request->set_query_params( array( 'site' => bin2hex( random_bytes( 16 ) ) ) );
		$check( 'asked about another site, it answers nothing', 404 === rest_do_request( $request )->get_status() );

		$stood = Connection::verify();
		$check( 'the site asks the hub to check its address, with a signed request', ! is_wp_error( $stood ) && $last( '/verify' )['signed'] && $last( '/verify' )['good'] && '' === $last( '/verify' )['fault'], $said( $stood ) );
		$check( 'until its email is confirmed it is registered and no more', 'registered' === Connection::state()['state'] && true === Connection::state()['proved'] && false === Connection::state()['confirmed'] && '' === Connection::state()['challenge'] );
		$played['confirmed'] = true;
		Connection::refresh();
		$check( 'then it waits for a coordinator', 'pending' === Connection::state()['state'] && ! Connection::sending() && $last( '/me' )['good'] );
		$me = $last( '/me' );
		$check( 'a request with no body carries its number in its address', 1 === preg_match( '/\?contract=' . Contract::version() . '&nonce=[0-9a-f]{32}$/', $me['url'] ) && false !== strpos( $me['url'], $me['headers'][ Signing::NONCE ] ), $me['url'] );

		$html = $screen();
		$check( 'the screen shows the steps that are left', false !== strpos( $html, 'Waiting for approval' ) && false !== strpos( $html, 'Before this site is listed' ) && 2 === substr_count( $html, '<strong>Done.</strong>' ) && false === strpos( $html, 'Waiting to be sent' ) && false !== strpos( $html, 'Disconnect this site' ), substr( wp_strip_all_tags( $html ), 0, 400 ) );

		Outbox::schedule();
		$check( 'a connected site has its two timers', false !== wp_next_scheduled( Outbox::RUN ) && false !== wp_next_scheduled( Outbox::NIGHTLY ) );
		$fresh();
		$at = $mark();
		Missions::save( $mission, array( 'description' => 'Bring gloves, water, and a hat.' ), $ana );
		$check( 'a change made while it waits is queued', in_array( 'listing mission:' . $mission, $queue(), true ), $queue() );
		$ran = Outbox::run( true );
		$check( 'and nothing is sent before the partner is approved', 0 === $ran['sent'] && $ran['waiting'] >= 1 && array() === array_values( array_diff( $heard( $at ), array( 'GET /me' ) ) ), array( $ran, $heard( $at ) ) );

		WP_CLI::log( 'Approved' );

		$played['state']     = 'active';
		$played['notices'][] = array(
			'at'   => time(),
			'kind' => 'approved',
			'text' => 'A coordinator approved this partner.',
		);
		Connection::refresh();
		$check( 'the site learns it is approved, and where its page on the hub is', 'active' === Connection::state()['state'] && Connection::sending() && 'https://packout.spot/partner/played/' === Connection::state()['partner_url'] && 'approved' === ( Connection::notices()[0]['kind'] ?? '' ) );
		$waiting = $queue();
		$check( 'and queues everything public: its profile, a full send, and its totals', array() === array_diff( array( 'profile profile', 'full full', 'stats stats' ), $waiting ), $waiting );

		$at  = $mark();
		$ran = Outbox::run( true );
		$check( 'it sends its profile first, then its listings, then its totals', array( 'PUT /profile', 'POST /listings', 'POST /stats' ) === array_values( array_diff( $heard( $at ), array( 'GET /me' ) ) ) && 0 === $ran['failed'] && 0 === $ran['waiting'], array( $ran, $heard( $at ) ) );
		$profile = $last( '/profile' );
		$check( 'the profile fits the contract and is the partner\'s own', '' === $profile['fault'] && $profile['good'] && get_post( $partner )->post_title === $profile['body']['name'] && 0 === strpos( $profile['body']['url'], untrailingslashit( home_url() ) ), array( $profile['fault'], $profile['body'] ) );
		$full  = $last( '/listings' );
		$items = $sent( $full );
		$check( 'the full send says it is one, with a number for the pass and a mark on its last part', '' === $full['fault'] && true === $full['body']['full'] && true === $full['body']['last'] && 1 === preg_match( '/^[0-9a-f]{16}$/', (string) $full['body']['sweep'] ), array( $full['fault'], array_diff_key( $full['body'], array( 'items' => 1 ) ) ) );
		$check( 'it has every public record, and the change that was waiting went with it', isset( $items[ 'organization:' . $org ], $items[ 'team:' . $org . '-' . $crew ], $items[ 'program:' . $program ], $items[ 'campaign:' . $campaign ], $items[ 'mission:' . $mission ] ) && 'Bring gloves, water, and a hat.' === $items[ 'mission:' . $mission ]['description'] && ! isset( $items[ 'mission:' . $quiet ] ) && ! isset( $items[ 'organization:' . $quiet_org ] ), array_keys( $items ) );
		$stats = $last( '/stats' );
		$check( 'the totals fit the contract and carry the overview\'s figures', '' === $stats['fault'] && $stats['good'] && isset( $sent( $stats )[ 'partner:' . $partner ]['overview'] ), $stats['fault'] );
		$check( 'the site remembers when it last sent', Connection::state()['sent'] >= time() - 5 && '' === Connection::state()['error'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$log = Outbox::recent( min( 20, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pos_sync_log WHERE id > %d", $packout_logged ) ) ) );
		$check( 'and keeps a line for each send: what, how many, and how the hub answered', array() === array_diff( array( 'profile', 'listings', 'stats', 'register' ), wp_list_pluck( $log, 'action' ) ) && array( 200 ) === array_values( array_unique( wp_list_pluck( $log, 'status' ) ) ), $log );
		$check( 'never what it carried', false === strpos( (string) wp_json_encode( $log ), 'Synctest' ) && false === strpos( (string) wp_json_encode( $log ), 'example' ) );
		$html = $screen();
		$check( 'the screen shows how the connection stands, the partner\'s page on the hub, and what was sent', false !== strpos( $html, 'is-stable' ) && false !== strpos( $html, 'https://packout.spot/partner/played/' ) && false !== strpos( $html, 'Nothing is waiting.' ) && false !== strpos( $html, 'Listings' ) && false !== strpos( $html, 'Taken' ) && false !== strpos( $html, 'Replace the key' ) && false !== strpos( $html, 'Keep the partner' ), substr( wp_strip_all_tags( $html ), 0, 400 ) );
		$check( 'and nowhere this site\'s key', false === strpos( $html, Connection::key()['secret'] ) && false === strpos( $html, Connection::key()['public'] ) );

		WP_CLI::log( 'A change' );

		$fresh();
		$at  = $mark();
		$rev = Listings::rev( $mission );
		Missions::save( $mission, array( 'title' => 'Synctest Mill Bridge, moved ' . $packout_run ), $ana );
		$check( 'a change to a public record is queued once, however many fields it touched', array( 'listing mission:' . $mission ) === $queue() && Listings::rev( $mission ) > $rev, $queue() );
		$check( 'and nothing is sent while the page is answered', $at === $mark() );
		$ran   = Outbox::run( true );
		$items = $sent( $last( '/listings' ) );
		$check( 'afterwards the record is sent as it is now, and it alone', 1 === $ran['sent'] && array( 'mission:' . $mission ) === array_keys( $items ) && 'Synctest Mill Bridge, moved ' . $packout_run === $items[ 'mission:' . $mission ]['title'] && $items[ 'mission:' . $mission ]['rev'] > $rev && empty( $last( '/listings' )['body']['full'] ) && 0 === Outbox::waiting(), array( $ran, array_keys( $items ) ) );

		$fresh();
		Missions::save( $quiet, array( 'title' => 'Synctest quiet day, renamed ' . $packout_run ), $ana );
		Outbox::run( true );
		$items = $sent( $last( '/listings' ) );
		$check( 'a change to a record that is not public says only that it is not listed', array( 'mission:' . $quiet ) === array_keys( $items ) && ! empty( $items[ 'mission:' . $quiet ]['deleted'] ) && ! isset( $items[ 'mission:' . $quiet ]['title'] ), $items );

		$fresh();
		Missions::save( $mission, array( 'public' => false ), $ana );
		Outbox::run( true );
		$items = $sent( $last( '/listings' ) );
		$check( 'a record that stops being public is withdrawn from the hub', ! empty( $items[ 'mission:' . $mission ]['deleted'] ), $items );
		$fresh();
		Missions::save( $mission, array( 'public' => true ), $ana );
		Outbox::run( true );
		$check( 'and one that is public again is listed again', ( $sent( $last( '/listings' ) )[ 'mission:' . $mission ]['title'] ?? '' ) === 'Synctest Mill Bridge, moved ' . $packout_run );

		$fresh();
		Subteams::update_page( $org, $crew, array( 'about' => 'Out every Saturday morning.' ), $ana );
		$check( 'a change to a team queues its organization and the team', array() === array_diff( array( 'listing organization:' . $org, 'listing team:' . $org . '-' . $crew ), $queue() ), $queue() );
		Outbox::run( true );
		$items = $sent( $last( '/listings' ) );
		$check( 'and they go in the order the hub applies them', array( 'organization:' . $org, 'team:' . $org . '-' . $crew ) === array_values( array_intersect( array_keys( $items ), array( 'organization:' . $org, 'team:' . $org . '-' . $crew ) ) ) && 'Out every Saturday morning.' === $items[ 'team:' . $org . '-' . $crew ]['description'], array_keys( $items ) );

		$fresh();
		$as( $coordinator );
		$packout_kept['region'] = Partners::field( $partner, 'region' );
		Partners::make_site( $coordinator, array( 'region' => 'Mill Valley ' . $packout_run ) );
		$check( 'a change to the partner\'s own record queues its profile', in_array( 'profile profile', $queue(), true ), $queue() );
		Outbox::run( true );
		$check( 'which is sent', ( $last( '/profile' )['body']['region'] ?? '' ) === 'Mill Valley ' . $packout_run );
		$as( $ana );

		$fresh();
		Outbox::clear();
		Teams::add_member( $org, get_userdata( $coordinator )->user_login );
		$check( 'a change to who belongs queues nothing: members are not sent', array() === $queue(), $queue() );

		$fresh();
		Spots::mark_cleaned( $s2, $ana, $mission );
		$second          = Impact::save_cleanup(
			$s2,
			array(
				'narrative' => 'Synctest second haul. ' . $packout_run,
				'hours'     => 1,
				'people'    => 2,
			),
			$ana
		);
		$packout_posts[] = $second;
		$rows            = Outbox::rows( 50 );
		$stats_row       = array_values( array_filter( $rows, static fn( array $row ): bool => 'stats' === $row['kind'] ) );
		$check( 'a cleanup that is recorded queues the totals, to go within the minute', 1 === count( $stats_row ) && $stats_row[0]['next_try'] > time() && $stats_row[0]['next_try'] <= time() + 31, $said( $second ) . ' ' . wp_json_encode( $rows ) );
		$at  = $mark();
		$ran = Outbox::run();
		$check( 'what is not due yet waits', ! in_array( 'POST /stats', $heard( $at ), true ) && $ran['waiting'] >= 1, array( $ran, $heard( $at ) ) );
		Outbox::run( true );
		$check( 'and "Send now" sends it', in_array( 'POST /stats', $heard( $at ), true ) && 0 === Outbox::waiting() );

		WP_CLI::log( 'When the hub is away' );

		$fresh();
		$played['down'] = true;
		Missions::save( $mission, array( 'description' => 'Bring gloves. The gate is open.' ), $ana );
		$ran  = Outbox::run( true );
		$rows = Outbox::rows();
		$check( 'a send that does not get through stays queued, to be tried in a minute', $ran['failed'] >= 1 && 1 === count( $rows ) && 1 === $rows[0]['attempts'] && abs( $rows[0]['next_try'] - time() - 60 ) <= 3 && 'packout_hub_unreachable' === $rows[0]['last_error'], array( $ran, $rows ) );
		$check( 'the log says the hub did not answer', '' !== Connection::state()['error'] && 0 === ( Outbox::recent( 1 )[0]['status'] ?? -1 ) && 'packout_hub_unreachable' === ( Outbox::recent( 1 )[0]['note'] ?? '' ), Outbox::recent( 1 ) );
		$html = $screen();
		$check( 'and the screen says so in words, with what is waiting and when it is tried next', false !== strpos( $html, 'packout.spot could not be reached' ) && false !== strpos( $html, 'Mission: Synctest Mill Bridge' ) && false !== strpos( $html, 'No answer' ) && false === strpos( $html, 'packout_hub_unreachable' ), substr( wp_strip_all_tags( $html ), 0, 400 ) );
		$check( '"Send now" says when something could not be sent', 'error:some_failed' === Connection_Screen::perform( 'send', '', false, false, $admin ) );
		$rows = Outbox::rows();
		$check( 'a second failure waits five minutes', 2 === $rows[0]['attempts'] && abs( $rows[0]['next_try'] - time() - 300 ) <= 3, $rows );
		$fresh();
		Missions::save( $mission, array( 'description' => 'Bring gloves. The gate is open until noon.' ), $ana );
		$rows = Outbox::rows();
		$check( 'a later change to the same record does not queue it twice, and starts the count again', 1 === count( $rows ) && 0 === $rows[0]['attempts'] && '' === $rows[0]['last_error'], $rows );
		$played['down'] = false;
		$ran            = Outbox::run( true );
		$check( 'when the hub is back the record goes as it is now', 1 === $ran['sent'] && 0 === $ran['waiting'] && 'Bring gloves. The gate is open until noon.' === ( $sent( $last( '/listings' ) )[ 'mission:' . $mission ]['description'] ?? '' ) && '' === Connection::state()['error'], $ran );

		$fresh();
		$played['refuse'] = array( 'mission:' . $mission => 'missing_reference' );
		Missions::save( $mission, array( 'description' => 'Bring gloves.' ), $ana );
		Campaigns::save( $campaign, array( 'goal' => 'Thirty Spots' ), $ana );
		$ran  = Outbox::run( true );
		$rows = Outbox::rows();
		$check( 'a listing the hub refuses is kept and tried again, and the rest of the batch is done', 1 === $ran['failed'] && $ran['sent'] >= 1 && 1 === count( $rows ) && 'mission:' . $mission === $rows[0]['subject'] && 'missing_reference' === $rows[0]['last_error'], array( $ran, $rows ) );
		$check( 'and the log says how many were refused, and why', false !== strpos( (string) wp_json_encode( Outbox::recent( 3 ) ), 'refused: 1 missing_reference' ), Outbox::recent( 3 ) );
		$played['refuse'] = array();
		Outbox::run( true );
		$check( 'it goes once the hub takes it', 0 === Outbox::waiting() );

		$fresh();
		$played['ahead'] = 1000;
		Missions::save( $mission, array( 'description' => 'Bring gloves and a friend.' ), $ana );
		$at    = $mark();
		$ran   = Outbox::run( true );
		$tries = array_values( array_filter( array_slice( $played['requests'], $at ), static fn( array $entry ): bool => '/listings' === $entry['route'] ) );
		$check( 'a site whose clock is off is told the hub\'s time, corrects for it, and tries once more', 2 === count( $tries ) && ! empty( $tries[0]['stale'] ) && empty( $tries[1]['stale'] ) && $tries[1]['good'] && 1 === $ran['sent'] && abs( Connection::state()['skew'] - 1000 ) <= 2, array( $ran, count( $tries ), Connection::state()['skew'] ) );
		$played['ahead'] = 0;
		Connection::remember( array( 'skew' => 0 ) );

		WP_CLI::log( 'When the hub says stop' );

		$fresh();
		$played['state']     = 'suspended';
		$played['notices'][] = array(
			'at'   => time(),
			'kind' => 'suspended',
			'text' => 'A coordinator suspended this partner: figures need a look.',
		);
		Missions::save( $mission, array( 'description' => 'Bring gloves and two friends.' ), $ana );
		$at  = $mark();
		$ran = Outbox::run( true );
		$check( 'a suspended partner\'s send is refused and kept', $ran['failed'] >= 1 && 1 === Outbox::waiting() && 'partner_suspended' === Outbox::rows()[0]['last_error'], array( $ran, Outbox::rows() ) );
		$check( 'the site asks how it stands and learns why', in_array( 'GET /me', $heard( $at ), true ) && 'suspended' === Connection::state()['state'] && ! Connection::sending() && false !== strpos( (string) wp_json_encode( Connection::notices() ), 'figures need a look' ) );
		$at = $mark();
		Outbox::run( true );
		$check( 'and sends nothing more while it is suspended', array() === array_values( array_diff( $heard( $at ), array( 'GET /me' ) ) ) && 1 === Outbox::waiting(), $heard( $at ) );
		$played['state'] = 'active';
		Connection::refresh();
		$check( 'when the suspension is lifted everything public is queued to go again', Connection::sending() && array() === array_diff( array( 'profile profile', 'full full', 'stats stats' ), $queue() ), $queue() );
		$ran = Outbox::run( true );
		$check( 'and goes, the waiting change with it', 0 === $ran['failed'] && 0 === Outbox::waiting() && 'Bring gloves and two friends.' === ( $sent( $last( '/listings' ) )[ 'mission:' . $mission ]['description'] ?? '' ), $ran );

		$fresh();
		$played['terms'] = '2';
		Connection::refresh();
		$check( 'when the terms change the site is told which version to accept, and stops sending', '2' === Connection::state()['terms_required'] && ! Connection::sending() );
		$html = $screen();
		$check( 'the screen asks an administrator to accept them', false !== strpos( $html, 'The partner terms changed. Version 2 is in force' ) && false !== strpos( $html, 'Accept and go on sending' ) && 'error:terms_unticked' === Connection_Screen::perform( 'terms', '2', false, false, $admin ) );
		Missions::save( $mission, array( 'description' => 'Bring gloves and three friends.' ), $ana );
		$at = $mark();
		Outbox::run( true );
		$check( 'what changes meanwhile waits', 1 === Outbox::waiting() && ! in_array( 'POST /listings', $heard( $at ), true ) );
		$check( 'accepting a version other than the one in force is refused', is_wp_error( Connection::accept_terms( '1' ) ) && ! Connection::sending() );
		$accepted = Connection::accept_terms( '2' );
		$check( 'accepting the one in force starts the sending again', true === $accepted && '' === Connection::state()['terms_required'] && '2' === Connection::state()['terms'] && Connection::sending() && '' === $last( '/terms' )['fault'], $said( $accepted ) );
		Outbox::run( true );
		$check( 'and what waited goes', 0 === Outbox::waiting() );

		$fresh();
		$played['next']['/listings'] = $wrong( 426, 'update_required', array( 'minimum_version' => '9.0.0' ) );
		$played['minimum']           = '9.0.0';
		Missions::save( $mission, array( 'description' => 'Bring gloves.' ), $ana );
		$ran = Outbox::run( true );
		$check( 'a plugin too old for the hub is told to update, and keeps what it has to send', $ran['failed'] >= 1 && 1 === Outbox::waiting() && 'update_required' === Outbox::rows()[0]['last_error'] && '9.0.0' === Connection::state()['minimum'] && version_compare( Connection::versions()['plugin_version'], Connection::state()['minimum'], '<' ), array( $ran, Connection::state()['minimum'] ) );
		$played['minimum'] = '0.1.0';
		Connection::refresh();
		Outbox::run( true );

		$fresh();
		Connection::remember( array( 'home' => 'https://staging.example.test' ) );
		Missions::save( $mission, array( 'description' => 'Bring gloves, again.' ), $ana );
		$at = $mark();
		Outbox::run( true );
		$check( 'a copy of the site at another address sends nothing', ! Connection::at_home() && ! Connection::sending() && ! in_array( 'POST /listings', $heard( $at ), true ) && 1 === Outbox::waiting() );
		$html = $screen();
		$check( 'and the screen asks which it is: a copy, or the site at a new address', false !== strpos( $html, 'https://staging.example.test' ) && false !== strpos( $html, 'This is a copy' ) && false !== strpos( $html, 'The site moved here' ) && false === strpos( $html, 'Send now' ) );
		Connection::remember( array( 'home' => untrailingslashit( home_url() ) ) );
		Outbox::run( true );
		$check( 'the site at its own address does', 0 === Outbox::waiting() );
		$at = $mark();
		$check( '"Send everything again" queues it all, and "Send now" sends it', 'queued' === Connection_Screen::perform( 'everything', '', false, false, $admin ) && 4 === Outbox::waiting() && 'sent' === Connection_Screen::perform( 'send', '', false, false, $admin ) && 0 === Outbox::waiting() && array( 'PUT /profile', 'POST /listings', 'POST /stats' ) === array_values( array_diff( $heard( $at ), array( 'GET /me' ) ) ), $heard( $at ) );
		$check( 'a person who is not an administrator can do none of it', is_wp_error( Connection_Screen::perform( 'send', '', false, false, $coordinator ) ) && is_wp_error( Connection_Screen::perform( 'disconnect', '', false, false, $ana ) ) && Connection::connected() );

		WP_CLI::log( 'The nightly send' );

		$fresh();
		$at = $mark();
		Outbox::nightly();
		$check( 'once a night the site asks how it stands and sends everything in full', array( 'GET /me', 'PUT /profile', 'POST /listings', 'POST /stats' ) === $heard( $at ) && true === $last( '/listings' )['body']['full'] && 0 === Outbox::waiting(), $heard( $at ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$wpdb->insert(
			$wpdb->prefix . 'pos_sync_log',
			array(
				'at'     => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ),
				'action' => 'synctest-old',
				'items'  => 0,
				'status' => 200,
				'note'   => '',
			)
		);
		Outbox::nightly();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table.
		$check( 'and drops lines of the log older than ninety days', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pos_sync_log WHERE action = 'synctest-old'" ) );

		WP_CLI::log( 'Replacing the key and leaving' );

		$old      = Connection::key();
		$replaced = Connection::replace_key();
		$new      = Connection::key();
		$request  = $last( '/key' );
		$check( 'the site replaces its key with a request signed by the old one', true === $replaced && $new['public'] !== $old['public'] && $new['public'] === $request['body']['public_key'] && $request['good'] && '' === $request['fault'] && $played['public'] === $new['public'], $said( $replaced ) );
		Connection::refresh();
		$check( 'and signs with the new one from then on', $last( '/me' )['good'] && 'active' === Connection::state()['state'] );

		$check( 'every signed request the hub heard was signed by the site\'s key', array() === array_filter( $played['requests'], static fn( array $entry ): bool => $entry['signed'] && ! $entry['good'] && empty( $entry['stale'] ) ) );
		$check( 'and everything it heard fits the contract', array() === array_values( array_filter( wp_list_pluck( $played['requests'], 'fault' ) ) ), array_values( array_filter( wp_list_pluck( $played['requests'], 'fault' ) ) ) );
		$bodies = '';
		foreach ( $played['requests'] as $entry ) {
			$bodies .= '/register' === $entry['route'] ? '' : $entry['raw'];
		}
		$check( 'nothing it sent names a person or an address to write to', false === strpos( $bodies, 'Syncperson' ) && false === strpos( $bodies, '@' ) && false === strpos( $bodies, 'pos-test-' ) && false === strpos( $bodies, $new['secret'] ) && false === strpos( $bodies, $old['secret'] ) );
		$check( 'and only the registration carried the administrator\'s email', 1 === substr_count( (string) wp_json_encode( wp_list_pluck( $played['requests'], 'raw' ) ), get_userdata( $admin )->user_email ) );

		$fresh();
		Missions::save( $mission, array( 'description' => 'Bring gloves. Last one.' ), $ana );
		$left = Connection::disconnect( true );
		$check( 'the site disconnects, asking the hub to keep its name and last totals', true === $left && true === $last( '/disconnect' )['body']['keep'] && $last( '/disconnect' )['good'] && '' === $last( '/disconnect' )['fault'], $said( $left ) );
		$check( 'its key is gone, its queue is empty, and its timers are stopped', null === Connection::key() && ! Connection::connected() && 'disconnected' === Connection::state()['state'] && 0 === Outbox::waiting() && false === wp_next_scheduled( Outbox::RUN ) && false === wp_next_scheduled( Outbox::NIGHTLY ) && false === wp_next_scheduled( Outbox::SOON ) );
		$fresh();
		$at = $mark();
		Missions::save( $mission, array( 'description' => 'Bring gloves.' ), $ana );
		Outbox::run( true );
		$check( 'from then on it queues nothing and sends nothing', 0 === Outbox::waiting() && $at === $mark() );
		$check( 'its records are as they were', 'Bring gloves.' === Missions::field( $mission, 'description' ) && 'publish' === get_post_status( $org ) );

		$played['state']     = 'pending';
		$played['confirmed'] = false;
		$played['proved']    = false;
		$played['site']      = bin2hex( random_bytes( 16 ) );
		$again               = Connection::connect( '2', $admin );
		$check( 'a site that left can connect again, with a new key', true === $again && Connection::connected() && Connection::key()['public'] !== $new['public'] && Connection::state()['site'] === $played['site'], $said( $again ) );
		$played['down'] = true;
		$left           = Connection::disconnect( false );
		$check( 'and it can leave even when the hub cannot be reached', is_wp_error( $left ) && null === Connection::key() && 'disconnected' === Connection::state()['state'] );
		$played['down'] = false;

		$played['site'] = bin2hex( random_bytes( 16 ) );
		Connection::connect( '2', $admin );
		Connection::forget();
		$check( 'a copy of a site forgets the connection without telling the hub', null === Connection::key() && '' === Connection::state()['state'] && '' === Connection::state()['site'] && '/register' === $played['requests'][ $mark() - 1 ]['route'] );
	}//end if
} catch ( \Throwable $e ) {
	$packout_failures[] = $e->getMessage();
	WP_CLI::log( '  FAIL  ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	remove_filter( 'pre_wp_mail', $keep_mail, 99 );
	wp_set_current_user( 0 );
	if ( ! $hub ) {
		// The site's own connection goes back as it was. The hub was never told of the one the checks made.
		Outbox::clear();
		Outbox::unschedule();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table: the lines these checks wrote.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}pos_sync_log WHERE id > %d", $packout_logged ) );
		$partner = Partners::site();
		if ( $partner > 0 && isset( $packout_kept['region'] ) ) {
			'' === $packout_kept['region'] ? delete_post_meta( $partner, '_pos_region' ) : update_post_meta( $partner, '_pos_region', wp_slash( $packout_kept['region'] ) );
		}
		if ( $partner > 0 && isset( $packout_kept['listed'] ) && ! $packout_kept['listed'] ) {
			wp_update_post(
				array(
					'ID'          => $partner,
					'post_status' => 'private',
				)
			);
		}
		unset( $packout_kept['region'], $packout_kept['listed'] );
		foreach ( $packout_kept as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value, Connection::OPTION === $option );
			}
		}
		delete_transient( 'packout_partner_terms' );
		delete_transient( 'packout_outbox_lock' );
	}
	remove_filter( 'pre_http_request', $answer, 5 );
	foreach ( array_values( array_unique( array_filter( $packout_posts, 'is_int' ) ) ) as $post_id ) {
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
	foreach ( $packout_users as $user_id ) {
		wp_delete_user( (int) $user_id );
	}
	Overview::forget();
	if ( ! $hub ) {
		// Deleting the records above queued them, if the site has a connection of its own. What was waiting before goes back instead.
		Outbox::clear();
		foreach ( $packout_rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
			$wpdb->insert( $wpdb->prefix . 'pos_outbox', $row );
		}
		if ( Connection::connected() ) {
			Outbox::schedule();
		}
	}
}

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( count( $packout_failures ) . ' checks failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
