<?php
/**
 * Phase 21 acceptance checks: what a partner site takes from packout.spot.
 *
 * A partner site can show what the hub knows about its region on its own
 * map, read by the site's server and never by a visitor's browser. A lead
 * can take a Spot of the hub's on for a mission: the site keeps a copy of
 * what the hub shows everyone, works it like its own, and tells the hub
 * when the mission starts, when the work is recorded, and when the Spot is
 * let go. The copy is never sent as a Spot of the site's, and the work on
 * it is in no total the site sends. An organization can ask to adopt a
 * Place the hub lists.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-21.php
 *
 * The hub is played in place, as in phases 19 and 20. The checks make their
 * own users and records and delete them, and put the site's own connection
 * and settings, if it has any, back as they were.
 *
 * What packout.spot does with what it is told is checked on the hub, in the
 * hub plugin's tests/partner-adoptions.php.
 *
 * @package PackOut_Core
 */

use PackOut\Core\Contract;
use PackOut\Core\Signing;
use PackOut\Core\App\Adoptions;
use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Connection;
use PackOut\Core\App\Exchange;
use PackOut\Core\App\Hub_Layers;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Listings;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Outbox;
use PackOut\Core\App\Overview;
use PackOut\Core\App\Partners;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Teams;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( \PackOut\Core\App\App::is_hub() ) {
	WP_CLI::log( '  --  A partner site takes Spots on from packout.spot. On packout.spot these checks do not apply; what the hub does is in tests/partner-adoptions.php.' );
	WP_CLI::success( '0 checks passed.' );
	return;
}

global $wpdb;

$packout_failures = array();
$packout_passed   = 0;
$packout_posts    = array();
$packout_users    = array();
$packout_files    = array();
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

$user = static function ( string $role, string $name ) use ( &$packout_users, $packout_run ): int {
	$login           = 'pos-test-' . sanitize_key( $name ) . '-' . $packout_run;
	$id              = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => $login . '@example.test',
			'display_name' => $name . ' Crewtaker',
			'role'         => $role,
		)
	);
	$packout_users[] = $id;
	return $id;
};

$file = static function ( string $name, int $red ) use ( &$packout_files ): array {
	$path    = wp_tempnam( $name );
	$picture = imagecreatetruecolor( 1200, 800 );
	imagefill( $picture, 0, 0, imagecolorallocate( $picture, $red, 94, 187 ) );
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

$keep_mail = static fn() => true;
add_filter( 'pre_wp_mail', $keep_mail, 99 );

/*
 * The hub, played in place.
 */
$origin = Connection::hub();
$played = array(
	'requests' => array(),
	'site'     => bin2hex( random_bytes( 16 ) ),
	'public'   => '',
	'state'    => 'pending',
	'down'     => false,
	// What the hub holds of the site's Spots: remote id to revision, state, and the photographs it has.
	'spots'    => array(),
	// How the hub says a Spot stands, by remote id: standing, reason, hints.
	'stands'   => array(),
	// Spots to refuse, by remote id.
	'refuse'   => array(),
	// One answer to give instead of the usual one, by route, used once.
	'next'     => array(),
	// What `/changes` answers.
	'changes'  => array(),
	// What `/spots/index` answers, when set. Otherwise what the hub holds.
	'index'    => null,
	// What the hub shows everyone: its Spots by number, its Places, and its map features.
	'hub'      => array(
		'spots'    => array(),
		'places'   => array(),
		'features' => array(),
	),
	// The adoptions the hub holds, by number.
	'adopted'  => array(),
	'last'     => 40,
	// Hub Spots it will not give, by number: the code it answers.
	'kept'     => array(),
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
	$wide = $origin . '/wp-json/packout/v1';
	if ( 0 !== strpos( (string) $url, $base ) ) {
		if ( 0 !== strpos( (string) $url, $wide . '/' ) ) {
			return $pre;
		}
		// What the hub shows everyone, asked for with no signature.
		$kind  = trim( (string) strtok( substr( (string) $url, strlen( $wide ) ), '?' ), '/' );
		$query = array();
		parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $query );
		$played['requests'][] = array(
			'method'  => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
			'route'   => 'public /' . $kind,
			'url'     => (string) $url,
			'raw'     => (string) ( $args['body'] ?? '' ),
			'body'    => array(),
			'query'   => $query,
			'headers' => (array) ( $args['headers'] ?? array() ),
			'signed'  => isset( $args['headers'][ Signing::SIGNATURE ] ),
			'good'    => true,
			'fault'   => '',
		);
		if ( $played['down'] ) {
			return new WP_Error( 'http_request_failed', 'Could not resolve host.' );
		}
		return isset( $played['hub'][ $kind ] ) ? $reply( 200, array( $kind => array_values( $played['hub'][ $kind ] ) ) ) : $wrong( 404, 'rest_no_route' );
	}
	$method  = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
	$route   = (string) strtok( substr( (string) $url, strlen( $base ) ), '?' );
	$raw     = (string) ( $args['body'] ?? '' );
	$headers = (array) ( $args['headers'] ?? array() );
	$file    = '/images' === $route;
	$body    = '' !== $raw && ! $file ? json_decode( $raw, true ) : null;
	$signed  = isset( $headers[ Signing::SIGNATURE ] );
	$query   = array();
	parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $query );
	$schemas = array(
		'POST /register'       => 'register',
		'POST /verify'         => 'signed',
		'PUT /profile'         => 'profile',
		'POST /listings'       => 'listings',
		'POST /stats'          => 'stats',
		'POST /spots'          => 'spots',
		'POST /adoptions'      => 'adoptions',
		'POST /place-requests' => 'place-requests',
	);
	$entry   = array(
		'method'  => $method,
		'route'   => $route,
		'url'     => (string) $url,
		'raw'     => $raw,
		'body'    => is_array( $body ) ? $body : array(),
		'query'   => $query,
		'headers' => $headers,
		'signed'  => $signed,
		'good'    => true,
		'fault'   => isset( $schemas[ $method . ' ' . $route ] ) ? Contract::fault( $schemas[ $method . ' ' . $route ], $body ) : '',
	);
	if ( $played['down'] ) {
		$played['requests'][] = $entry;
		return new WP_Error( 'http_request_failed', 'Could not resolve host.' );
	}
	if ( $signed ) {
		$stamp         = (int) ( $headers[ Signing::TIMESTAMP ] ?? 0 );
		$text          = Signing::text( $method, Signing::path( (string) $url ), $raw, $stamp, (string) ( $headers[ Signing::NONCE ] ?? '' ) );
		$entry['good'] = ( $headers[ Signing::KEY ] ?? '' ) === $played['site'] && Signing::verify( $text, (string) $headers[ Signing::SIGNATURE ], $played['public'] );
	}
	$played['requests'][] = $entry;
	if ( $signed && ! $entry['good'] ) {
		return $wrong( 401, 'bad_signature' );
	}
	if ( isset( $played['next'][ $route ] ) ) {
		$given = $played['next'][ $route ];
		unset( $played['next'][ $route ] );
		return $given;
	}
	if ( '' !== $entry['fault'] ) {
		return $wrong( 422, 'invalid', array( 'path' => $entry['fault'] ) );
	}
	$standing = static function () use ( &$played ): array {
		return array(
			'state'           => $played['state'],
			'confirmed'       => true,
			'proved'          => true,
			'partner_url'     => 'active' === $played['state'] ? 'https://packout.spot/partner/played/' : '',
			'minimum_version' => '0.1.0',
			'current_version' => '9.9.9',
			'contract'        => Contract::version(),
			'terms_version'   => '1',
			'terms_url'       => 'https://packout.spot/partner-terms/',
			'terms_required'  => '',
			'notices'         => array(),
			'now'             => time(),
		);
	};
	$open     = in_array( $played['state'], array( 'active', 'dormant' ), true );
	switch ( $method . ' ' . $route ) {
		case 'GET /terms':
			return $reply(
				200,
				array(
					'version' => '1',
					'url'     => 'https://packout.spot/partner-terms/',
					'open'    => true,
				)
			);
		case 'POST /register':
			$played['public'] = (string) $entry['body']['public_key'];
			return $reply(
				201,
				array(
					'site'      => $played['site'],
					'challenge' => bin2hex( random_bytes( 32 ) ),
					'state'     => 'pending',
				)
			);
		case 'POST /verify':
		case 'GET /me':
			return $reply( 200, $standing() );
		case 'PUT /profile':
			return $open ? $reply( 200, array( 'saved' => true ) ) : $wrong( 403, 'partner_' . $played['state'] );
		case 'POST /listings':
			if ( ! $open ) {
				return $wrong( 403, 'partner_' . $played['state'] );
			}
			return $reply(
				200,
				array(
					'items' => array_map(
						static fn( array $item ): array => array(
							'kind'      => $item['kind'],
							'remote_id' => $item['remote_id'],
							'result'    => 'updated',
						),
						$entry['body']['items']
					),
				)
			);
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
		case 'POST /spots':
			if ( ! $open ) {
				return $wrong( 403, 'partner_' . $played['state'] );
			}
			$items = array();
			foreach ( $entry['body']['items'] as $item ) {
				$id = (string) $item['remote_id'];
				if ( isset( $played['refuse'][ $id ] ) ) {
					$items[] = array(
						'remote_id' => $id,
						'result'    => 'refused',
						'reason'    => $played['refuse'][ $id ],
					);
					continue;
				}
				if ( 'removed' === $item['state'] ) {
					$items[] = array(
						'remote_id' => $id,
						'result'    => isset( $played['spots'][ $id ] ) ? 'deleted' : 'unchanged',
					);
					unset( $played['spots'][ $id ] );
					continue;
				}
				$had                    = $played['spots'][ $id ]['photos'] ?? array();
				$listed                 = array_column( (array) ( $item['photos'] ?? array() ), 'id' );
				$played['spots'][ $id ] = array(
					'rev'    => (int) $item['rev'],
					'state'  => (string) $item['state'],
					'listed' => $listed,
					'photos' => array_values( array_intersect( $had, $listed ) ),
				);
				$items[]                = array(
					'remote_id' => $id,
					'result'    => 'updated',
					'photos'    => array_values( array_diff( $listed, $played['spots'][ $id ]['photos'] ) ),
				) + ( $played['stands'][ $id ] ?? array(
					'standing' => 'shown',
					'reason'   => '',
					'hints'    => array(),
				) );
			}
			return $reply( 200, array( 'items' => $items ) );
		case 'POST /images':
			if ( ! $open ) {
				return $wrong( 403, 'partner_' . $played['state'] );
			}
			$id    = (string) ( $query['id'] ?? '' );
			$photo = (string) ( $query['photo'] ?? '' );
			if ( 'adoption' === ( $query['of'] ?? '' ) ) {
				if ( ! isset( $played['adopted'][ $id ] ) ) {
					return $wrong( 404, 'unknown_adoption' );
				}
				if ( ! in_array( $photo, $played['adopted'][ $id ]['wanted'], true ) ) {
					return $wrong( 409, 'not_expected' );
				}
				$played['adopted'][ $id ]['photos'][] = $photo;
				$played['adopted'][ $id ]['state']    = 'done';
				return $reply( 200, array( 'result' => 'created' ) );
			}
			if ( ! isset( $played['spots'][ $id ] ) ) {
				return $wrong( 404, 'unknown_spot' );
			}
			if ( ! in_array( $photo, $played['spots'][ $id ]['listed'], true ) ) {
				return $wrong( 409, 'not_expected' );
			}
			$played['spots'][ $id ]['photos'][] = $photo;
			return $reply( 200, array( 'result' => 'created' ) );
		case 'GET /changes':
			return $open ? $reply(
				200,
				$played['changes'] + array(
					'now'       => time(),
					'spots'     => array(),
					'adoptions' => array(),
				)
			) : $wrong( 403, 'partner_' . $played['state'] );
		case 'GET /spots/index':
			if ( ! $open ) {
				return $wrong( 403, 'partner_' . $played['state'] );
			}
			$rows = array();
			foreach ( null !== $played['index'] ? $played['index'] : $played['spots'] as $id => $held ) {
				if ( (int) $id > (int) ( $query['after'] ?? 0 ) ) {
					$rows[] = array( (int) $id, (int) $held['rev'], (string) $held['state'] );
				}
			}
			usort( $rows, static fn( $a, $b ) => $a[0] <=> $b[0] );
			return $reply(
				200,
				array(
					// Two to a page, so the site has to ask more than once.
					'items' => array_slice( $rows, 0, 2 ),
					'more'  => count( $rows ) > 2,
				)
			);
		case 'POST /adoptions':
			if ( ! $open ) {
				return $wrong( 403, 'partner_' . $played['state'] );
			}
			$said = $entry['body'];
			if ( 'adopt' === $said['act'] ) {
				$spot = (string) $said['spot'];
				if ( isset( $played['kept'][ $spot ] ) ) {
					return $wrong( 409, $played['kept'][ $spot ] );
				}
				if ( ! isset( $played['hub']['spots'][ $spot ] ) ) {
					return $wrong( 404, 'not_published' );
				}
				$id                       = (string) ( ++$played['last'] );
				$played['adopted'][ $id ] = array(
					'spot'   => $spot,
					'state'  => 'open',
					'wanted' => array(),
					'photos' => array(),
					'told'   => array( 'adopt' ),
				);
				return $reply(
					200,
					array(
						'id'     => $id,
						'state'  => 'open',
						'reason' => '',
						'spot'   => $played['hub']['spots'][ $spot ],
					)
				);
			}
			$id = (string) $said['id'];
			if ( ! isset( $played['adopted'][ $id ] ) ) {
				return $wrong( 404, 'unknown_adoption' );
			}
			$played['adopted'][ $id ]['told'][] = $said['act'] . ( isset( $said['reason'] ) ? ' ' . $said['reason'] : '' );
			$wanted                             = array();
			if ( ! in_array( $played['adopted'][ $id ]['state'], array( 'done', 'released' ), true ) ) {
				if ( 'start' === $said['act'] ) {
					$played['adopted'][ $id ]['state'] = 'started';
				} elseif ( 'complete' === $said['act'] ) {
					$played['adopted'][ $id ]['state']   = 'reported';
					$played['adopted'][ $id ]['wanted']  = (array) $said['photos'];
					$played['adopted'][ $id ]['figures'] = $said;
					$wanted                              = array_values( array_diff( (array) $said['photos'], $played['adopted'][ $id ]['photos'] ) );
				} else {
					$played['adopted'][ $id ]['state'] = 'released';
				}
			}
			return $reply(
				200,
				array(
					'id'     => $id,
					'state'  => $played['adopted'][ $id ]['state'],
					'reason' => '',
				) + ( 'complete' === $said['act'] ? array( 'photos' => $wanted ) : array() )
			);
		case 'POST /place-requests':
			if ( ! $open ) {
				return $wrong( 403, 'partner_' . $played['state'] );
			}
			return $reply(
				200,
				array(
					'request' => 'r1',
					'status'  => 'pending',
				)
			);
	}//end switch
	return $wrong( 404, 'rest_no_route' );
};
add_filter( 'pre_http_request', $answer, 5, 3 );

$keys  = static function ( array $item ): array {
	$names = array_keys( $item );
	sort( $names );
	return $names;
};
$mark  = static function () use ( &$played ): int {
	return count( $played['requests'] );
};
$heard = static function ( int $since = 0 ) use ( &$played ): array {
	return array_map( static fn( array $entry ): string => $entry['method'] . ' ' . $entry['route'], array_slice( $played['requests'], $since ) );
};
// The Spots sent since a mark, by identifier. The last one sent of each.
$sent = static function ( int $since = 0 ) use ( &$played ): array {
	$out = array();
	foreach ( array_slice( $played['requests'], $since ) as $entry ) {
		if ( '/spots' === $entry['route'] ) {
			foreach ( (array) ( $entry['body']['items'] ?? array() ) as $item ) {
				$out[ (string) $item['remote_id'] ] = $item;
			}
		}
	}
	return $out;
};
// The images sent since a mark.
$images = static function ( int $since = 0 ) use ( &$played ): array {
	return array_values( array_filter( array_slice( $played['requests'], $since ), static fn( array $entry ): bool => '/images' === $entry['route'] ) );
};
// Everything the site has said to the hub, as one text.
$everything = static function ( int $since = 0 ) use ( &$played ): string {
	return implode( "\n", array_map( static fn( array $entry ): string => $entry['url'] . "\n" . $entry['raw'], array_slice( $played['requests'], $since ) ) );
};
$queue      = static fn(): array => array_map( static fn( array $row ): string => $row['kind'] . ' ' . $row['subject'], Outbox::rows( 200 ) );
// A new request: what was queued once in the last one may be queued again.
$fresh = static function (): void {
	$seen = new ReflectionProperty( Outbox::class, 'queued' );
	$seen->setAccessible( true );
	$seen->setValue( null, array() );
	$looked = new ReflectionProperty( Adoptions::class, 'looked' );
	$looked->setAccessible( true );
	$looked->setValue( null, false );
};
// What a request does at its end, then what WP-Cron does.
$go = static function () use ( $fresh ): array {
	$ran = Outbox::run( true );
	$fresh();
	return $ran;
};

// The requests about adoptions since a mark.
$told = static function ( int $since = 0 ) use ( &$played ): array {
	return array_values( array_filter( array_slice( $played['requests'], $since ), static fn( array $entry ): bool => '/adoptions' === $entry['route'] ) );
};
// What the hub shows everyone of one of its Spots.
$shown  = static function ( int $id, string $title, float $lat, array $more = array() ) use ( $origin ): array {
	return array_replace_recursive(
		array(
			'id'                  => $id,
			'title'               => $title,
			'url'                 => $origin . '/spot/hub-' . $id . '/',
			'category'            => array(
				'slug' => 'cleanup-waste',
				'name' => 'Cleanup and waste',
			),
			'status'              => 'spotted',
			'hazard'              => 'none',
			'needs_authorization' => false,
			'authorized'          => false,
			'guidance'            => '',
			'place'               => null,
			'description'         => 'Six tires below the guard rail.',
			'location'            => array(
				'kind'      => 'pin',
				'lat'       => $lat,
				'lng'       => -111.9,
				'precision' => 'exact',
				'landmark'  => 'By the old pump house',
			),
			'updated'             => gmdate( 'c' ),
			'reported'            => gmdate( 'Y-m-d' ),
			'reported_by'         => array(
				'label' => 'an individual',
				'url'   => '',
			),
			'gallery'             => array(),
		),
		$more
	);
};
$state  = static fn( int $copy ): string => (string) get_post_meta( $copy, Adoptions::STATE, true );
$copies = static function (): array {
	return get_posts(
		array(
			'post_type'   => Schema::SPOT,
			'post_status' => 'any',
			'numberposts' => 100,
			'fields'      => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The copies the checks made.
			'meta_key'    => Exchange::ADOPTED,
		)
	);
};

try {
	Capabilities::install();
	Schema::register();

	$coordinator = $user( 'pos_coordinator', 'Coordinator' );
	$admin       = $user( 'administrator', 'Admin' );
	$ana         = $user( 'pos_lead', 'Ana' );
	$mem         = $user( 'pos_reporter', 'Mem' );

	// The site's own connection and settings, if it has any, are set aside and put back at the end.
	foreach ( array( Connection::OPTION, Connection::KEY_OPTION, Connection::NOTICES, Exchange::PULLED, Hub_Layers::OPTION, Hub_Layers::DATA ) as $option ) {
		$packout_kept[ $option ] = get_option( $option, null );
		delete_option( $option );
	}
	Outbox::install_tables();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
	$packout_logged = (int) $wpdb->get_var( "SELECT COALESCE( MAX( id ), 0 ) FROM {$wpdb->prefix}pos_sync_log" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
	$packout_rows = (array) $wpdb->get_results( "SELECT kind, subject, queued, attempts, next_try, last_error FROM {$wpdb->prefix}pos_outbox", ARRAY_A );
	Outbox::clear();
	Outbox::unschedule();
	delete_transient( 'packout_partner_terms' );
	delete_transient( 'packout_outbox_lock' );

	$check( 'a site that is not connected shows nothing of the hub\'s', ! Hub_Layers::on() && null === Hub_Layers::config() && is_wp_error( Hub_Layers::refresh() ) && array() === $played['requests'] );

	$connected = Connection::connect( '1', $admin );
	Connection::verify();
	$played['state'] = 'active';
	Connection::refresh();
	if ( true !== $connected || ! Connection::sending() ) {
		WP_CLI::error( 'The site could not be connected to the played hub.' );
	}

	wp_set_current_user( $coordinator );
	if ( Partners::site() > 0 ) {
		// An organization is listed on the hub while its partner is. It is listed while the checks run.
		$packout_listed = 'publish' === get_post_status( Partners::site() );
		Partners::make_site( $coordinator, array( 'listed' => true ) );
	}
	$org             = Teams::save(
		0,
		array(
			'name'   => 'Crewtest River Crew ' . $packout_run,
			'lead'   => $ana,
			'listed' => true,
		)
	);
	$packout_posts[] = $org;
	if ( is_wp_error( $org ) ) {
		WP_CLI::error( 'Organization: ' . $org->get_error_message() );
	}
	$org     = (int) $org;
	$local   = static function ( string $what, float $lat ) use ( &$packout_posts, $packout_run, $mem ): int {
		$id              = (int) Spots::create(
			array(
				'category'    => 'cleanup-waste',
				'description' => 'Crewtest ' . $what . ' ' . $packout_run,
				'lat'         => $lat,
				'lng'         => -111.8,
			),
			$mem
		);
		$packout_posts[] = $id;
		Spots::publish( $id );
		return $id;
	};
	$plan    = static function ( string $title, int $spot ) use ( &$packout_posts, $packout_run, $org, $ana ): int {
		$day             = wp_date( 'Y-m-d', strtotime( '+20 days' ) );
		$id              = Missions::save(
			0,
			array(
				'title'  => 'Crewtest ' . $title . ' ' . $packout_run,
				'team'   => $org,
				'spots'  => array( $spot ),
				'start'  => $day,
				'end'    => $day,
				'public' => true,
			),
			$ana
		);
		$packout_posts[] = $id;
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( 'Mission: ' . $id->get_error_message() );
		}
		return (int) $id;
	};
	$own     = $local( 'cans at the fire ring', 34.61 );
	$mission = $plan( 'River Road day', $own );
	$go();

	$played['hub']['spots'] = array(
		'5001' => $shown( 5001, 'Tires on River Road', 34.70 ),
		'5002' => $shown( 5002, 'Glass near the wash', 34.71, array( 'location' => array( 'precision' => 'coarse' ) ) ),
		// Sent by this site: the map already has it.
		'5003' => $shown(
			5003,
			'Cans at the fire ring',
			34.61,
			array(
				'through' => array(
					'name'     => 'This site',
					'site'     => untrailingslashit( home_url() ) . '/spot/cans/',
					'awaiting' => false,
				),
			)
		),
		// A report another partner's coordinator has not reviewed.
		'5004' => $shown(
			5004,
			'Reported through Other Crew',
			34.72,
			array(
				'url'     => '',
				'through' => array(
					'name'     => 'Other Crew',
					'site'     => 'https://other.example.org',
					'awaiting' => true,
				),
			)
		),
		'5005' => $shown( 5005, 'A drum left in place', 34.73, array( 'hazard' => 'do_not_touch' ) ),
		'5006' => $shown(
			5006,
			'Somewhere by the mill',
			34.74,
			array(
				'location' => array(
					'lat' => null,
					'lng' => null,
				),
			)
		),
		// Another partner's published Spot, which links to that partner's site.
		'5007' => $shown(
			5007,
			'A couch <script>alert(1)</script> in the wash',
			34.75,
			array(
				'url'     => 'https://other.example.org/spot/couch/',
				'through' => array(
					'name'     => 'Other Crew',
					'site'     => 'https://other.example.org',
					'awaiting' => false,
				),
			)
		),
		'5008' => $shown( 5008, 'Bottles at the boat ramp', 34.76 ),
		'5009' => $shown( 5009, 'A tire in the creek', 34.77 ),
		'5010' => $shown( 5010, 'Boards behind the kiosk', 34.78 ),
		'5011' => $shown( 5011, 'A mattress at mile six', 34.79 ),
	);
	$played['hub']['places']   = array(
		array(
			'id'         => 7001,
			'name'       => 'Pump House Turnout',
			'url'        => $origin . '/place/pump-house-turnout/',
			'kind'       => 'area',
			'kind_label' => 'Area',
			'shape'      => array( array( 34.60, -111.81 ), array( 34.62, -111.81 ), array( 34.62, -111.79 ), array( 34.60, -111.79 ) ),
			'adopters'   => array(
				array(
					'name' => 'Friends of the Verde',
					'url'  => $origin . '/team/friends/',
				),
			),
		),
		array(
			'id'         => 7002,
			'name'       => 'A Place with no outline',
			'url'        => $origin . '/place/none/',
			'kind'       => 'area',
			'kind_label' => 'Area',
			'shape'      => array( array( 34.60, -111.81 ) ),
			'adopters'   => array(),
		),
	);
	$played['hub']['features'] = array(
		array(
			'id'         => 8001,
			'name'       => 'River Road trail',
			'url'        => $origin . '/feature/river-road-trail/',
			'kind'       => 'trail',
			'kind_label' => 'Trail',
			'geometry'   => array(
				'type'        => 'LineString',
				'coordinates' => array( array( -111.9, 34.7 ), array( -111.89, 34.71 ) ),
			),
		),
		array(
			'id'         => 8002,
			'name'       => 'Not a shape',
			'url'        => 'javascript:alert(1)',
			'kind'       => 'trail',
			'kind_label' => 'Trail',
			'geometry'   => array(
				'type'        => 'GeometryCollection',
				'coordinates' => array(),
			),
		),
	);

	WP_CLI::log( 'packout.spot on the site\'s map' );

	$wrong_box = Hub_Layers::save( true, 'somewhere near Sedona' );
	$too_wide  = Hub_Layers::save( true, '-118,30,-105,35' );
	$off_map   = Hub_Layers::save( true, '-181,34,-179.5,35' );
	$check( 'a region is four numbers, on the map, and at most 500 kilometres on a side', is_wp_error( $wrong_box ) && is_wp_error( $too_wide ) && false !== strpos( $too_wide->get_error_message(), '500' ) && is_wp_error( $off_map ) && ! Hub_Layers::on() );
	$at    = $mark();
	$saved = Hub_Layers::save( true, '-112.2,34.2,-111.3,35.0' );
	$check( 'the administrator names the region, and saving it asks the hub nothing yet', true === $saved && Hub_Layers::on() && array() === $heard( $at ) && 0 === Hub_Layers::data()['fetched'] );
	$read  = Hub_Layers::refresh();
	$asked = array_slice( $played['requests'], $at );
	$check( 'the site asks the hub for its Spots, Places, and map features in the region', true === $read && array( 'GET public /spots', 'GET public /places', 'GET public /features' ) === $heard( $at ) && '-112.2,34.2,-111.3,35' === $asked[0]['query']['bbox'], array( is_wp_error( $read ) ? $read->get_error_message() : $read, $heard( $at ), $asked[0]['query'] ?? '' ) );
	$check( 'as anyone would: unsigned, and without naming the site', ! array_filter( $asked, static fn( array $entry ): bool => $entry['signed'] || isset( $entry['headers'][ Signing::KEY ] ) || 1 !== count( $entry['query'] ) ) );
	$layer = Hub_Layers::data();
	$check( 'it keeps the Spots a visitor could see, and leaves out its own, unreviewed reports, and ones with no point', array( 5001, 5002, 5005, 5007, 5008, 5009, 5010, 5011 ) === array_column( $layer['spots'], 'id' ), array_column( $layer['spots'], 'id' ) );
	$by = array_column( $layer['spots'], null, 'id' );
	$check( 'each with what a card needs and no more', array( 'category', 'coarse', 'hazard', 'id', 'lat', 'lng', 'status', 'through', 'title', 'url' ) === $keys( $by[5001] ) && 'Cleanup and waste' === $by[5001]['category'] && $by[5002]['coarse'] && ! $by[5001]['coarse'] && 'do_not_touch' === $by[5005]['hazard'], $by[5001] );
	$check( 'a link is kept only when it goes to the hub, and no markup is kept at all', $origin . '/spot/hub-5001/' === $by[5001]['url'] && '' === $by[5007]['url'] && 'Other Crew' === $by[5007]['through'] && false === strpos( (string) wp_json_encode( $layer ), 'script' ) && false === strpos( (string) wp_json_encode( $layer ), 'javascript' ), $by[5007] );
	$check( 'a Place is kept with its outline and the names of its adopters, a feature with its shape', array( 7001 ) === array_column( $layer['places'], 'id' ) && 4 === count( $layer['places'][0]['shape'] ) && array( 'Friends of the Verde' ) === $layer['places'][0]['adopters'] && array( 8001 ) === array_column( $layer['features'], 'id' ) && 'LineString' === $layer['features'][0]['geometry']['type'], array( $layer['places'], $layer['features'] ) );

	wp_set_current_user( 0 );
	$at       = $mark();
	$response = rest_do_request( new WP_REST_Request( 'GET', '/packout/v1/hub-layers' ) );
	$given    = (array) $response->get_data();
	$check( 'a visitor\'s map reads the layers from this site, which can be cached, and the hub is not asked', 200 === $response->get_status() && 8 === count( $given['spots'] ) && 1 === count( $given['places'] ) && false !== strpos( (string) ( $response->get_headers()['Cache-Control'] ?? '' ), 'max-age=300' ) && array() === $heard( $at ), $response->get_headers() );
	$config = Hub_Layers::config();
	$check( 'the map block is told where to read them and what to call them', $config && false !== strpos( $config['rest'], '/packout/v1/hub-layers' ) && 'On packout.spot' === $config['group'] );
	wp_set_current_user( $coordinator );
	Hub_Layers::refresh_if_due();
	$check( 'the hub is asked again only when the answer is fifteen minutes old', array() === $heard( $at ) );
	update_option( Hub_Layers::DATA, array( 'fetched' => time() - 1000 ) + Hub_Layers::data(), false );
	$played['down'] = true;
	Hub_Layers::refresh_if_due();
	$check( 'when the hub does not answer, the last answer stays', array( 'GET public /spots' ) === $heard( $at ) && 8 === count( Hub_Layers::data()['spots'] ), $heard( $at ) );
	$played['down'] = false;
	Hub_Layers::refresh_if_due();
	$check( 'and it is asked again on the next run', time() - Hub_Layers::data()['fetched'] < 60 );
	ob_start();
	Hub_Layers::section();
	$section = (string) ob_get_clean();
	$check( 'the Connection screen says what was read', false !== strpos( $section, 'value="-112.2,34.2,-111.3,35"' ) && false !== strpos( $section, '8 Spots, 1 Places, 1 map features' ), wp_strip_all_tags( $section ) );

	WP_CLI::log( 'Taking a Spot of the hub\'s on' );

	wp_set_current_user( $ana );
	ob_start();
	Adoptions::pane( get_post( $mission ) );
	$pane = (string) ob_get_clean();
	$check( 'the lead of a mission is offered the hub\'s Spots that wait for a crew', false !== strpos( $pane, 'value="5001"' ) && false !== strpos( $pane, 'Tires on River Road' ) && false === strpos( $pane, 'value="5005"' ) && false === strpos( $pane, 'value="5007"' ) );
	$check( 'a Spot is named by its number or by the address of its page on the hub', 5001 === Adoptions::number( ' 5001 ' ) && 5001 === Adoptions::number( $origin . '/spot/hub-5001' ) && 0 === Adoptions::number( 'https://elsewhere.example.org/spot/1/' ) && 0 === Adoptions::number( 'tires' ) );
	wp_set_current_user( $mem );
	ob_start();
	Adoptions::pane( get_post( $mission ) );
	$nothing = (string) ob_get_clean();
	$at      = $mark();
	$denied  = Adoptions::adopt( $mission, 5001, $mem );
	$check( 'someone who does not lead the mission is offered nothing and can take nothing on', '' === $nothing && is_wp_error( $denied ) && 'packout_forbidden' === $denied->get_error_code() && array() === $heard( $at ) );

	wp_set_current_user( $ana );
	$fresh();
	$at      = $mark();
	$copy    = Adoptions::adopt( $mission, 5001, $ana );
	$request = $told( $at )[0] ?? array();
	if ( is_wp_error( $copy ) ) {
		WP_CLI::error( 'Adoption: ' . $copy->get_error_message() );
	}
	$packout_posts[] = $copy;
	$check( 'the lead takes a Spot on, and the hub is asked at once, in a signed request that fits the contract', is_int( $copy ) && $request && $request['signed'] && $request['good'] && '' === $request['fault'], $request['fault'] ?? 'none' );
	$body = $request['body'] ?? array();
	$check( 'the request names the Spot, the mission, and the organization', 'adopt' === $body['act'] && '5001' === $body['spot'] && (string) $mission === $body['mission']['remote_id'] && get_permalink( $mission ) === $body['mission']['url'] && get_the_title( $mission ) === $body['mission']['title'] && wp_date( 'Y-m-d', strtotime( '+20 days' ) ) === $body['mission']['starts'] && (string) $org === $body['organization'], $body );
	$check( 'and nobody', array( 'act', 'contract', 'core_version', 'mission', 'organization', 'plugin_version', 'spot' ) === $keys( $body ) && false === strpos( $request['raw'], 'Crewtaker' ) && false === strpos( $request['raw'], get_userdata( $ana )->user_login ), $keys( $body ) );
	$made = get_post( $copy );
	$check( 'the site keeps a copy of what the hub shows everyone, on its own map', 'publish' === $made->post_status && 'Tires on River Road' === $made->post_title && 34.70 === (float) Spots::field( $copy, 'lat' ) && 'By the old pump house' === Spots::field( $copy, 'landmark' ) && false !== strpos( $made->post_content, 'Six tires' ), array( $made->post_title, Spots::field( $copy, 'lat' ) ) );
	$check( 'the copy knows whose it is', Adoptions::is( $copy ) && '5001' === get_post_meta( $copy, Exchange::ADOPTED, true ) && '41' === get_post_meta( $copy, Adoptions::ID, true ) && 'open' === $state( $copy ) && array( 5001 => $copy ) === Adoptions::hub_spots() );
	$check( 'and is on the mission', in_array( $copy, Missions::spots( $mission ), true ) && in_array( $own, Missions::spots( $mission ), true ), Missions::spots( $mission ) );
	$note = Spots::payload( $made, 'public' )['notes'][0] ?? array();
	$check( 'its card says the Spot was reported on packout.spot, and links to it there', $note && false !== strpos( $note['text'], 'reported on %s' ) && 'packout.spot' === $note['name'] && $origin . '/spot/hub-5001/' === $note['url'], $note );
	$check( 'a coordinator here reads the same on the Spots screen', 1 === count( Adoptions::lines( $copy ) ) && false !== strpos( Adoptions::lines( $copy )[0]['text'], 'taken it on' ) && array() === Exchange::lines( $copy ), Adoptions::lines( $copy ) );
	$check( 'a copy is not a Spot of the site\'s to send', '' === Exchange::state( $copy ) && ! in_array( 'spot ' . $copy, $queue(), true ), $queue() );
	$at = $mark();
	Outbox::everything();
	$go();
	$check( 'not when it is made, and not with the nightly comparison', ! isset( $sent()[ (string) $copy ] ) && ! isset( $played['spots'][ (string) $copy ] ) && isset( $played['spots'][ (string) $own ] ), array_keys( $played['spots'] ) );
	$at    = $mark();
	$twice = Adoptions::adopt( $plan( 'Second day', $own ), 5001, $ana );
	$check( 'a Spot a mission here already has cannot be taken on twice', is_wp_error( $twice ) && array() === $told( $at ) );

	$played['kept']['5005'] = 'do_not_touch';
	$before                 = count( $copies() );
	$refused                = Adoptions::adopt( $mission, 5005, $ana );
	$check( 'when the hub refuses, the lead reads why, and nothing is kept', is_wp_error( $refused ) && 'do_not_touch' === $refused->get_error_code() && count( $copies() ) === $before && ! in_array( 5005, array_map( 'intval', array_keys( Adoptions::hub_spots() ) ), true ), is_wp_error( $refused ) ? $refused->get_error_code() : $refused );
	$unknown = Adoptions::adopt( $mission, 99999, $ana );
	$check( 'a Spot the hub does not show is not found', is_wp_error( $unknown ) && 'not_published' === $unknown->get_error_code() );

	$coarse          = Adoptions::adopt( $mission, 5002, $ana );
	$packout_posts[] = $coarse;
	$check( 'a Spot the hub shows at an approximate point is shown at one here too', is_int( $coarse ) && 'coarse' === Spots::payload( get_post( $coarse ), 'public' )['location']['precision'], is_wp_error( $coarse ) ? $coarse->get_error_message() : '' );
	$check( 'the hub\'s layer stops drawing a Spot the moment the site has a copy of it', ! array_intersect( array( 5001, 5002 ), array_column( Hub_Layers::data()['spots'], 'id' ) ) && in_array( 5008, array_column( Hub_Layers::data()['spots'], 'id' ), true ), array_column( Hub_Layers::data()['spots'], 'id' ) );
	Hub_Layers::refresh();
	$check( 'and does not draw it again when the hub is asked next', ! array_intersect( array( 5001, 5002 ), array_column( Hub_Layers::data()['spots'], 'id' ) ), array_column( Hub_Layers::data()['spots'], 'id' ) );

	WP_CLI::log( 'The work' );

	// The site's own Spot on the mission is cleaned first, so there is a total to compare.
	$fresh();
	Missions::set_status( $mission, 'in_progress' );
	$check( 'starting the mission queues word of it', in_array( 'adoption ' . $copy, $queue(), true ) && in_array( 'adoption ' . $coarse, $queue(), true ), $queue() );
	$at = $mark();
	$go();
	$acts = array_map( static fn( array $entry ): string => $entry['body']['act'] . ' ' . $entry['body']['id'], $told( $at ) );
	sort( $acts );
	$check( 'the hub is told the crew has started, once for each Spot', array( 'start 41', 'start 42' ) === $acts && 'started' === $state( $copy ) && 'started' === $state( $coarse ) && ! array_filter( $told( $at ), static fn( array $entry ): bool => '' !== $entry['fault'] || ! $entry['good'] ), $acts );
	$at = $mark();
	$fresh();
	Outbox::spot( $copy );
	$go();
	$check( 'told once, it is not told again', array() === $told( $at ) );

	$fresh();
	Spots::mark_cleaned( $own, $ana, $mission );
	$first = Impact::save_cleanup(
		$own,
		array(
			'hours'  => 2,
			'people' => 3,
			'bags'   => 4,
		),
		$ana
	);
	$go();
	$fresh();
	$at = $mark();
	Spots::mark_cleaned( $copy, $ana, $mission );
	$go();
	$check( 'a Spot marked cleaned with nothing recorded is not reported yet', array() === $told( $at ) && 'started' === $state( $copy ), $told( $at ) );
	$fresh();
	$record = Impact::save_cleanup(
		$copy,
		array(
			'hours'         => 6.5,
			'people'        => 4,
			'bags'          => 9,
			'bags_source'   => 'measured',
			'pounds'        => 140,
			'pounds_source' => 'self_reported',
			'narrative'     => 'Six tires and a mattress, hauled to the transfer station.',
		),
		$ana
	);
	if ( is_wp_error( $record ) || is_wp_error( $first ) ) {
		WP_CLI::error( 'Record: ' . ( is_wp_error( $record ) ? $record->get_error_message() : $first->get_error_message() ) );
	}
	$packout_posts[] = $record;
	$packout_posts[] = $first;
	// A coordinator counts both in the site's public totals.
	Impact::set_visible( (int) $first, true, $coordinator );
	Impact::set_visible( (int) $record, true, $coordinator );
	$picture = Impact::add_photo( $record, $file( 'after.jpg', 90 ), $ana );
	$check( 'recording the work queues word of it', ! is_wp_error( $picture ) && in_array( 'adoption ' . $copy, $queue(), true ), array( is_wp_error( $picture ) ? $picture->get_error_message() : '', $queue() ) );
	$at = $mark();
	$go();
	$done = array_values( array_filter( $told( $at ), static fn( array $entry ): bool => 'complete' === $entry['body']['act'] ) )[0] ?? array();
	$said = $done['body'] ?? array();
	$check( 'the hub is told what was done, in a request that fits the contract', $done && '' === $done['fault'] && $done['good'] && '41' === $said['id'] && 6.5 === (float) $said['hours'] && 4 === (int) $said['people'] && 9 === (int) $said['bags'] && 'measured' === $said['bags_source'] && 140.0 === (float) $said['pounds'] && 'self_reported' === $said['pounds_source'] && wp_date( 'Y-m-d' ) === $said['date'], array( $done['fault'] ?? 'none', $said ) );
	$check( 'with the crew\'s account of it and the photographs the public here sees', false !== strpos( (string) ( $said['narrative'] ?? '' ), 'Six tires and a mattress' ) && array( (string) ( is_wp_error( $picture ) ? 0 : $picture['id'] ?? $picture ) ) === ( $said['photos'] ?? null ), $said['photos'] ?? 'none' );
	$pictures = $images( $at );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The site's own file.
	$own_copy = $pictures ? (string) file_get_contents( (string) get_attached_file( (int) $pictures[0]['query']['photo'] ) ) : '';
	$check( 'the photograph follows in the same run, as a photograph of that adoption', 1 === count( $pictures ) && $pictures[0]['signed'] && $pictures[0]['good'] && 'adoption' === $pictures[0]['query']['of'] && '41' === $pictures[0]['query']['id'] && 'cleanup' === $pictures[0]['query']['about'] && $pictures[0]['raw'] === $own_copy && '' !== $own_copy && false === strpos( $own_copy, 'Exif' ), $pictures ? $pictures[0]['query'] : $heard( $at ) );
	$check( 'the hub has it, and has recorded the work', array( (string) $pictures[0]['query']['photo'] ) === $played['adopted']['41']['photos'] && 'done' === $played['adopted']['41']['state'] );
	$played['changes'] = array(
		'adoptions' => array(
			array(
				'id'     => '41',
				'spot'   => '5001',
				'state'  => 'done',
				'reason' => '',
			),
		),
	);
	$fresh();
	$at = $mark();
	$go();
	$check( 'the site reads that it did', 'done' === $state( $copy ) && false !== strpos( Adoptions::lines( $copy )[0]['text'], 'is on packout.spot' ) && array() === $told( $at ), array( $state( $copy ), $heard( $at ) ) );
	$played['changes'] = array();

	$fresh();
	Impact::flush();
	$here = Impact::totals(
		array(
			'team'    => $org,
			'visible' => true,
		)
	);
	$at   = $mark();
	Outbox::counted();
	Outbox::everything();
	$go();
	$stats = array();
	foreach ( array_slice( $played['requests'], $at ) as $entry ) {
		if ( '/stats' === $entry['route'] ) {
			foreach ( (array) $entry['body']['items'] as $item ) {
				$stats[ $item['scope'] . ' ' . $item['remote_id'] ] = $item;
			}
		}
	}
	$sent_org = $stats[ 'organization ' . $org ] ?? array();
	$check( 'this site counts both cleanups', 2 === (int) $here['records']['cleanups'] && 13 === (int) $here['outputs']['bags_measured'] + (int) $here['outputs']['bags_self_reported'], $here );
	$check( 'what it sends as totals leaves out the work on the hub\'s Spot, which the hub counts itself', $sent_org && 1 === (int) $sent_org['records']['total'] && 4 === (int) $sent_org['outputs']['bags_measured'] + (int) $sent_org['outputs']['bags_self_reported'] && 1 === (int) $sent_org['outputs']['spots_cleaned'] && 1 === (int) ( $stats[ 'mission ' . $mission ]['records']['total'] ?? 0 ) && 1 === (int) ( $stats[ 'partner ' . Partners::site() ]['records']['total'] ?? 0 ), array( array_keys( $stats ), $sent_org['records'] ?? 'none', $sent_org['outputs'] ?? 'none' ) );
	$check( 'and the site\'s own count is as it was when asked again', 2 === (int) Impact::totals( array( 'team' => $org ) )['records']['cleanups'] );

	WP_CLI::log( 'A Spot shown at an approximate point' );

	$fresh();
	Spots::mark_cleaned( $coarse, $ana, $mission );
	$second          = Impact::save_cleanup(
		$coarse,
		array(
			'hours'  => 1,
			'people' => 2,
			'bags'   => 1,
		),
		$ana
	);
	$packout_posts[] = $second;
	Impact::add_photo( (int) $second, $file( 'coarse.jpg', 150 ), $ana );
	$at = $mark();
	$go();
	$said = array_values( array_filter( $told( $at ), static fn( array $entry ): bool => 'complete' === $entry['body']['act'] ) )[0]['body'] ?? array();
	$check( 'its work is reported with no photograph, as the site shows none of it, and the hub\'s coordinator confirms it', $said && '42' === $said['id'] && array() === $said['photos'] && array() === $images( $at ) && 'reported' === $state( $coarse ), $said );

	WP_CLI::log( 'Letting a Spot go' );

	$third           = Adoptions::adopt( $mission, 5008, $ana );
	$packout_posts[] = $third;
	$fresh();
	$at = $mark();
	Missions::save( $mission, array( 'spots' => array_values( array_diff( Missions::spots( $mission ), array( $third ) ) ) ), $ana );
	$go();
	$check( 'a Spot taken off its mission is let go', is_int( $third ) && array( 'adopt', 'release removed' ) === $played['adopted']['43']['told'] && 'released' === $state( $third ) && ! isset( Adoptions::hub_spots()[5008] ), $played['adopted']['43']['told'] ?? 'none' );
	$check( 'and its card here says no mission has it', false !== strpos( Adoptions::lines( $third )[0]['text'], 'no mission here has it' ) && 'warning' === Adoptions::lines( $third )[0]['intent'] );

	$other           = $plan( 'Boat ramp day', $own );
	$fourth          = Adoptions::adopt( $other, 5009, $ana );
	$packout_posts[] = $fourth;
	$fresh();
	Missions::set_status( $other, 'called_off' );
	$go();
	$check( 'a mission called off lets its Spots go', is_int( $fourth ) && array( 'adopt', 'release called_off' ) === $played['adopted']['44']['told'] && 'released' === $state( $fourth ), array( $played['adopted']['44']['told'] ?? 'none', Missions::status( $other ) ) );

	$kiosk           = $plan( 'Kiosk day', $own );
	$fifth           = Adoptions::adopt( $kiosk, 5010, $ana );
	$packout_posts[] = $fifth;
	$fresh();
	Missions::set_status( $kiosk, 'in_progress' );
	$go();
	$fresh();
	Missions::set_status( $kiosk, 'completed' );
	$go();
	$check( 'a mission that ends with a Spot not cleaned says the Spot is still open', is_int( $fifth ) && array( 'adopt', 'start', 'release still_open' ) === $played['adopted']['45']['told'] && 'released' === $state( $fifth ), array( $played['adopted']['45']['told'] ?? 'none', Missions::status( $kiosk ) ) );

	$mile              = $plan( 'Mile six day', $own );
	$sixth             = Adoptions::adopt( $mile, 5011, $ana );
	$packout_posts[]   = $sixth;
	$played['changes'] = array(
		'adoptions' => array(
			array(
				'id'     => '46',
				'spot'   => '5011',
				'state'  => 'released',
				'reason' => 'The county is clearing this site itself.',
			),
			// Not this site's to hear about.
			array(
				'id'     => '9999',
				'spot'   => '1',
				'state'  => 'released',
				'reason' => 'Nothing.',
			),
		),
	);
	$played['adopted']['46']['state'] = 'released';
	$fresh();
	$go();
	$lines = Adoptions::lines( (int) $sixth );
	$check( 'when a coordinator on the hub releases a Spot, the site reads why', is_int( $sixth ) && 'released' === $state( $sixth ) && false !== strpos( $lines[0]['text'], 'The county is clearing this site itself.' ) && 'warning' === $lines[0]['intent'], $lines );
	$played['changes'] = array();
	$fresh();
	$at = $mark();
	Missions::set_status( $mile, 'in_progress' );
	$go();
	$check( 'and says nothing more about it', array() === $told( $at ), $heard( $at ) );

	unset( $played['hub']['spots']['5008'] );
	$played['hub']['spots']['5012'] = $shown( 5012, 'Paint cans at the gate', 34.80 );
	$seventh                        = Adoptions::adopt( $mission, 5012, $ana );
	$packout_posts[]                = $seventh;
	unset( $played['adopted']['47'] );
	$fresh();
	wp_trash_post( (int) $seventh );
	$go();
	$check( 'an adoption the hub no longer has is closed here', is_int( $seventh ) && 'released' === $state( $seventh ), $state( (int) $seventh ) );

	$played['hub']['spots']['5013'] = $shown( 5013, 'A sofa by the tank', 34.81 );
	$eighth                         = Adoptions::adopt( $mission, 5013, $ana );
	$packout_posts[]                = $eighth;
	$played['state']                = 'suspended';
	Connection::refresh();
	$at     = $mark();
	$barred = Adoptions::adopt( $mission, 5001, $ana );
	$check( 'a site that is not sending takes nothing on', is_wp_error( $barred ) && 'packout_not_connected' === $barred->get_error_code() && array() === $told( $at ) );
	$fresh();
	wp_trash_post( (int) $eighth );
	$go();
	$check( 'and what it has to say waits', in_array( 'adoption ' . $eighth, $queue(), true ) && array( 'adopt' ) === $played['adopted']['48']['told'], $queue() );
	$played['state'] = 'active';
	Connection::refresh();
	$go();
	$check( 'until it can be said: a copy put in the trash is let go', array( 'adopt', 'release removed' ) === $played['adopted']['48']['told'] && 'released' === $state( (int) $eighth ), $played['adopted']['48']['told'] );

	WP_CLI::log( 'Asking to adopt a Place' );

	wp_set_current_user( $ana );
	ob_start();
	Adoptions::place_pane( get_post( $org ) );
	$pane = (string) ob_get_clean();
	$check( 'a lead of an organization is offered the hub\'s Places in the region', false !== strpos( $pane, 'value="7001"' ) && false !== strpos( $pane, 'Pump House Turnout' ), wp_strip_all_tags( $pane ) );
	$at      = $mark();
	$asked   = Adoptions::request_place( 7001, $org, 'Our crews walk this turnout every month.', $ana );
	$request = array_values( array_filter( array_slice( $played['requests'], $at ), static fn( array $entry ): bool => '/place-requests' === $entry['route'] ) )[0] ?? array();
	$check( 'the lead asks, in a signed request that fits the contract and names nobody', true === $asked && $request && $request['signed'] && $request['good'] && '' === $request['fault'] && '7001' === $request['body']['place'] && (string) $org === $request['body']['organization'] && 'Our crews walk this turnout every month.' === $request['body']['note'] && array( 'contract', 'core_version', 'note', 'organization', 'place', 'plugin_version' ) === $keys( $request['body'] ), array( is_wp_error( $asked ) ? $asked->get_error_message() : $asked, $request['fault'] ?? 'none' ) );
	$at     = $mark();
	$denied = Adoptions::request_place( 7001, $org, '', $mem );
	$check( 'someone who does not lead the organization cannot ask', is_wp_error( $denied ) && 'packout_forbidden' === $denied->get_error_code() && array() === $heard( $at ) );
	$played['next']['/place-requests'] = $wrong( 409, 'already_adopted' );
	$again                             = Adoptions::request_place( 7001, $org, '', $ana );
	$check( 'what the hub refuses, the lead reads', is_wp_error( $again ) && 'already_adopted' === $again->get_error_code() );

	$fresh();
	wp_set_current_user( $coordinator );
	$off = Hub_Layers::save( false, '-112.2,34.2,-111.3,35.0' );
	wp_set_current_user( 0 );
	$quiet = (array) rest_do_request( new WP_REST_Request( 'GET', '/packout/v1/hub-layers' ) )->get_data();
	$check( 'turned off, the layers are gone from the map and from what was kept', true === $off && ! Hub_Layers::on() && null === Hub_Layers::config() && array() === $quiet['spots'] && 0 === Hub_Layers::data()['fetched'] );

	$all    = $everything();
	$secret = (string) ( Connection::key()['secret'] ?? '' );
	$check( 'every request about an adoption or a Place was signed, and none carried the site\'s secret key', '' !== $secret && false === strpos( $all, $secret ) && ! array_filter( $played['requests'], static fn( array $entry ): bool => in_array( $entry['route'], array( '/adoptions', '/place-requests', '/images' ), true ) && ( ! $entry['signed'] || ! $entry['good'] || '' !== $entry['fault'] ) ) );
	$check( 'no email address or name of anyone left the site', 0 === preg_match( '/pos-test-[a-z]+-' . preg_quote( $packout_run, '/' ) . '/', str_replace( get_userdata( $admin )->user_email, '', $all ) ) && false === strpos( $all, 'Crewtaker' ) );
} catch ( \Throwable $e ) {
	$packout_failures[] = $e->getMessage();
	WP_CLI::log( '  FAIL  ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	remove_filter( 'pre_wp_mail', $keep_mail, 99 );
	wp_set_current_user( 0 );
	// The site's own connection goes back as it was. The hub was never told of the one the checks made.
	Outbox::clear();
	Outbox::unschedule();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table: the lines these checks wrote.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}pos_sync_log WHERE id > %d", $packout_logged ) );
	if ( isset( $packout_listed ) && ! $packout_listed && Partners::site() > 0 ) {
		wp_update_post(
			array(
				'ID'          => Partners::site(),
				'post_status' => 'private',
			)
		);
	}
	foreach ( $packout_kept as $option => $value ) {
		if ( null === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value, Connection::OPTION === $option );
		}
	}
	delete_transient( 'packout_partner_terms' );
	delete_transient( 'packout_outbox_lock' );
	remove_filter( 'pre_http_request', $answer, 5 );
	foreach ( array_values( array_unique( array_filter( $packout_posts, 'is_int' ) ) ) as $post_id ) {
		foreach ( get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => $post_id,
				'numberposts' => 20,
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
	// A copy the checks lost track of is still a copy the checks made.
	foreach ( $copies() as $left ) {
		if ( false !== strpos( (string) get_post_field( 'post_content', (int) $left ), 'Six tires below the guard rail.' ) ) {
			wp_delete_post( (int) $left, true );
		}
	}
	foreach ( $packout_users as $user_id ) {
		wp_delete_user( (int) $user_id );
	}
	foreach ( $packout_files as $path ) {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
	Impact::flush();
	Overview::forget();
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

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( count( $packout_failures ) . ' checks failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
