<?php
/**
 * Phase 20 acceptance checks: a partner site's Spots, as they go to packout.spot.
 *
 * What a site sends of a Spot is decided on the site, before it is sent. A
 * report nobody has reviewed goes with its category, its day, its hazard,
 * and a point rounded to about a kilometre, and nothing else. A published
 * Spot goes as the site's own public shows it. Everything else is sent as
 * removed. These checks read every request the site makes and look for what
 * must not be in any of them.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-20.php
 *
 * The hub is played in place, as in phase 19: every request is caught before
 * it leaves, checked against the contract and the signature, and answered
 * here. The checks make their own users and records and delete them, and put
 * the site's own connection, if it has one, back as it was.
 *
 * What packout.spot does with what it is sent is checked on the hub, in the
 * hub plugin's tests/partner-spots.php.
 *
 * @package PackOut_Core
 */

use PackOut\Core\Contract;
use PackOut\Core\Signing;
use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Connection;
use PackOut\Core\App\Exchange;
use PackOut\Core\App\Geo;
use PackOut\Core\App\Listings;
use PackOut\Core\App\Outbox;
use PackOut\Core\App\Overview;
use PackOut\Core\App\Partners;
use PackOut\Core\App\Photos;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Spots_Screen;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( \PackOut\Core\App\App::is_hub() ) {
	WP_CLI::log( '  --  A partner site sends its Spots. On packout.spot these checks do not apply; what the hub does with a partner\'s Spots is in tests/partner-spots.php.' );
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
			'display_name' => $name . ' Spotsender',
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
	$headers = (array) ( $args['headers'] ?? array() );
	$file    = '/images' === $route;
	$body    = '' !== $raw && ! $file ? json_decode( $raw, true ) : null;
	$signed  = isset( $headers[ Signing::SIGNATURE ] );
	$query   = array();
	parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $query );
	$schemas = array(
		'POST /register' => 'register',
		'POST /verify'   => 'signed',
		'PUT /profile'   => 'profile',
		'POST /listings' => 'listings',
		'POST /stats'    => 'stats',
		'POST /spots'    => 'spots',
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
					'now'   => time(),
					'spots' => array(),
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
};
// What a request does at its end, then what WP-Cron does.
$go = static function () use ( $fresh ): array {
	$ran = Outbox::run( true );
	$fresh();
	return $ran;
};

try {
	Capabilities::install();
	Schema::register();

	$coordinator = $user( 'pos_coordinator', 'Coordinator' );
	$admin       = $user( 'administrator', 'Admin' );
	$mem         = $user( 'pos_reporter', 'Mem' );

	// The site's own connection, if it has one, is set aside and put back at the end.
	foreach ( array( Connection::OPTION, Connection::KEY_OPTION, Connection::NOTICES, Exchange::PULLED ) as $option ) {
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

	wp_set_current_user( $mem );
	$before          = Spots::create(
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Spottest bottles under the bridge ' . $packout_run,
			'lat'         => 34.512345,
			'lng'         => -111.701234,
		),
		$mem
	);
	$packout_posts[] = $before;
	$check( 'a site that is not connected queues no Spot and sends nothing', is_int( $before ) && 0 === Outbox::waiting() && array() === $played['requests'] );

	$connected = Connection::connect( '1', $admin );
	Connection::verify();
	$played['state'] = 'active';
	Connection::refresh();
	if ( true !== $connected || ! Connection::sending() ) {
		WP_CLI::error( 'The site could not be connected to the played hub.' );
	}
	$first = $go();
	$check( 'when a site connects, the Spots it already had go with the first comparison', isset( $played['spots'][ (string) $before ] ) && 'awaiting' === $played['spots'][ (string) $before ]['state'] && in_array( 'GET /spots/index', $heard(), true ), array( $first, $heard() ) );

	WP_CLI::log( 'A report nobody has reviewed' );

	$fresh();
	$at              = $mark();
	$spot            = Spots::create(
		array(
			'category'    => 'cleanup-waste',
			'title'       => 'Spottest tires at Miller turnout',
			'description' => 'Spottest six tires and a mattress, dumped behind the Miller family gate ' . $packout_run,
			'landmark'    => 'Blue gate at mile four',
			'lat'         => 34.612345,
			'lng'         => -111.801234,
			'accuracy'    => 7.5,
		),
		$mem,
		array( 'credit_named' => true )
	);
	$packout_posts[] = $spot;
	$shot            = Photos::attach_upload( (int) $spot, $file( 'one.jpg', 40 ) );
	$check( 'a saved report is queued by itself', is_int( $spot ) && in_array( 'spot ' . $spot, $queue(), true ) && ! is_wp_error( $shot ), $queue() );
	Outbox::wake();
	$timer = wp_next_scheduled( Outbox::SOON );
	$check( 'and is sent at once: the timer is set to now, not to the next five minutes', false !== $timer && $timer <= time(), $timer );
	$check( 'nothing was sent while the report was being saved', array() === $heard( $at ), $heard( $at ) );
	$go();
	$item    = $sent( $at )[ (string) $spot ] ?? array();
	$request = array_values( array_filter( array_slice( $played['requests'], $at ), static fn( array $entry ): bool => '/spots' === $entry['route'] ) )[0] ?? array();
	$check( 'it goes in a signed request that fits the contract', $request && $request['signed'] && $request['good'] && '' === $request['fault'], $request['fault'] ?? 'none' );
	$check( 'as awaiting review, with its category, its day, its hazard, and a point', array( 'category', 'hazard', 'location', 'remote_id', 'reported', 'rev', 'state' ) === $keys( $item ) && 'awaiting' === $item['state'] && 'cleanup-waste' === $item['category']['slug'] && current_time( 'Y-m-d' ) === (string) $item['reported'], $item );
	$check( 'the point is rounded to two decimal places', $item && 34.61 === (float) $item['location']['lat'] && -111.8 === (float) $item['location']['lng'] && array( 'lat', 'lng' ) === array_keys( $item['location'] ), $item['location'] ?? array() );
	$said = $everything( $at );
	$check( 'what was sent has no title, description, or landmark', false === strpos( $said, 'Miller' ) && false === strpos( $said, 'mattress' ) && false === strpos( $said, 'Blue gate' ) && false === strpos( $said, 'mile four' ) );
	$check( 'nor the pin, nor how exact the pin was', false === strpos( $said, '34.612' ) && false === strpos( $said, '111.801' ) && false === strpos( $said, '7.5' ) );
	$check( 'nor a photograph, nor anything about the reporter', array() === $images( $at ) && false === strpos( $said, 'Spotsender' ) && false === strpos( $said, get_userdata( $mem )->user_login ) && false === strpos( $said, get_userdata( $mem )->user_email ) );
	$check( 'the site remembers how the Spot stands on the hub', 'awaiting' === get_post_meta( $spot, Exchange::SENT, true ) && 'shown' === Exchange::standing( $spot )['standing'] );

	$fresh();
	$at              = $mark();
	$pinless         = Spots::create(
		array(
			'category'    => 'trails-access',
			'description' => 'Spottest washed-out steps ' . $packout_run,
			'landmark'    => 'Behind the old mill',
		),
		$mem
	);
	$packout_posts[] = $pinless;
	$go();
	$item = $sent( $at )[ (string) $pinless ] ?? array();
	$check( 'a report with no pin is sent with no location at all', $item && 'awaiting' === $item['state'] && ! isset( $item['location'] ) && false === strpos( $everything( $at ), 'old mill' ), $item );

	WP_CLI::log( 'The coordinator publishes it' );

	wp_set_current_user( $coordinator );
	$fresh();
	$at = $mark();
	Spots::publish( (int) $spot );
	$check( 'publishing queues the Spot again', in_array( 'spot ' . $spot, $queue(), true ), $queue() );
	$ran  = $go();
	$item = $sent( $at )[ (string) $spot ] ?? array();
	$check( 'it goes as published, as the site\'s own public sees it', $item && 'published' === $item['state'] && 'Spottest tires at Miller turnout' === $item['title'] && get_permalink( $spot ) === $item['url'] && false !== strpos( $item['description'], 'mattress' ) && 'spotted' === $item['status'], $item );
	$check( 'with the pin and the landmark, which the site shows everyone', $item && 34.612345 === (float) $item['location']['lat'] && 'exact' === $item['location']['precision'] && 'Blue gate at mile four' === $item['location']['landmark'], $item['location'] ?? array() );
	$check( 'and the credit the reporter chose, and nothing else about them', $item && get_userdata( $mem )->display_name === ( $item['credit']['label'] ?? '' ) && false === strpos( $everything( $at ), get_userdata( $mem )->user_email ) && false === strpos( $everything( $at ), get_userdata( $mem )->user_login ), $item['credit'] ?? array() );
	$check(
		'it lists the photograph the public sees',
		$item && array(
			array(
				'id' => (string) $shot,
				'of' => 'report',
			),
		) === $item['photos'],
		$item['photos'] ?? array()
	);
	$pictures = $images( $at );
	$check( 'the hub asks for the photograph, and it follows in the same run', 1 === count( $pictures ) && $pictures[0]['signed'] && $pictures[0]['good'] && array( (string) $shot ) === $played['spots'][ (string) $spot ]['photos'] && 0 === $ran['waiting'], array( count( $pictures ), $ran ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The site's own file.
	$own = (string) file_get_contents( (string) get_attached_file( (int) $shot ) );
	$check( 'what is sent is the site\'s own public copy, signed with the request', $pictures && $pictures[0]['raw'] === $own && 'spot' === $pictures[0]['query']['of'] && (string) $spot === $pictures[0]['query']['id'] && (string) $shot === $pictures[0]['query']['photo'] && 'report' === $pictures[0]['query']['about'] && (string) Contract::version() === $pictures[0]['query']['contract'], $pictures ? $pictures[0]['query'] : array() );
	$check( 'which carries no notes from a camera', '' !== $own && false === strpos( $own, 'Exif' ) );

	$fresh();
	$at     = $mark();
	$second = Photos::attach_upload( (int) $spot, $file( 'two.jpg', 200 ) );
	$check( 'a photograph added later queues the Spot', ! is_wp_error( $second ) && in_array( 'spot ' . $spot, $queue(), true ), $queue() );
	$go();
	$check( 'and only the new one is sent', 1 === count( $images( $at ) ) && (string) $second === $images( $at )[0]['query']['photo'] && 2 === count( $sent( $at )[ (string) $spot ]['photos'] ) );

	$fresh();
	$at = $mark();
	Spots::set_photos_public( (int) $spot, false );
	$go();
	$check( 'when the coordinator hides the Spot\'s photographs, the next send lists none, and no file follows', isset( $sent( $at )[ (string) $spot ] ) && array() === $sent( $at )[ (string) $spot ]['photos'] && array() === $images( $at ), $sent( $at ) );
	Spots::set_photos_public( (int) $spot, true );
	$fresh();
	$go();

	$withhold = static fn( $allowed, $id ) => (int) $id === (int) $spot ? false : $allowed;
	add_filter( 'packout_exchange_photos', $withhold, 10, 2 );
	$fresh();
	$at = $mark();
	Outbox::spot( (int) $spot );
	$go();
	$check( 'a site can keep one Spot\'s photographs to itself', isset( $sent( $at )[ (string) $spot ] ) && array() === $sent( $at )[ (string) $spot ]['photos'] && null === Exchange::photo( (int) $spot, (int) $shot ), $sent( $at ) );
	remove_filter( 'packout_exchange_photos', $withhold, 10 );

	WP_CLI::log( 'A Spot shown at an approximate point' );

	$all_at = $mark();
	wp_set_current_user( $mem );
	$fresh();
	$quiet           = Spots::create(
		array(
			'category'    => 'land-habitat',
			'description' => 'Spottest nesting site with fishing line ' . $packout_run,
			'landmark'    => 'Heron rookery by the weir',
			'lat'         => 34.654321,
			'lng'         => -111.854321,
		),
		$mem
	);
	$packout_posts[] = $quiet;
	Photos::attach_upload( (int) $quiet, $file( 'nest.jpg', 90 ) );
	$go();
	wp_set_current_user( $coordinator );
	$fresh();
	Spots::set_precision( (int) $quiet, 'coarse' );
	Spots::publish( (int) $quiet );
	$at = $mark();
	$go();
	$item   = $sent( $at )[ (string) $quiet ] ?? array();
	$coarse = Geo::coarse( 34.654321, -111.854321 );
	$check( 'it is sent at the approximate point the site shows the public', $item && 'published' === $item['state'] && 'coarse' === $item['location']['precision'] && (float) $coarse[0] === (float) $item['location']['lat'] && (float) $coarse[1] === (float) $item['location']['lng'], $item['location'] ?? array() );
	$check( 'with no landmark and no photograph', $item && '' === ( $item['location']['landmark'] ?? '' ) && array() === $item['photos'] && array() === $images( $at ) );
	$said = $everything( $all_at );
	$check( 'nothing the hub received at either step has the pin or the landmark', false === strpos( $said, '34.654' ) && false === strpos( $said, '111.854' ) && false === strpos( $said, 'rookery' ) && false === strpos( $said, 'weir' ), substr_count( $said, 'rookery' ) );

	WP_CLI::log( 'A Spot the public no longer sees' );

	$fresh();
	$at = $mark();
	Spots::keep_private( (int) $quiet );
	$go();
	$check( 'a Spot kept for crews and coordinators is sent as removed', array( 'remote_id', 'rev', 'state' ) === array_keys( $sent( $at )[ (string) $quiet ] ?? array() ) && 'removed' === $sent( $at )[ (string) $quiet ]['state'] && ! isset( $played['spots'][ (string) $quiet ] ), $sent( $at ) );
	$fresh();
	$at = $mark();
	wp_update_post(
		array(
			'ID'           => $quiet,
			'post_content' => 'Spottest nesting site, changed ' . $packout_run,
		)
	);
	$go();
	$check( 'and is not sent again while it stays private', ! isset( $sent( $at )[ (string) $quiet ] ), $heard( $at ) );

	$fresh();
	$at = $mark();
	Spots::return_to_reporter( (int) $pinless, 'Where is it?' );
	$go();
	$check( 'a report returned to its reporter is removed from the hub', 'removed' === ( $sent( $at )[ (string) $pinless ]['state'] ?? '' ) && false === strpos( $everything( $at ), 'Where is it' ), $sent( $at ) );

	wp_set_current_user( $mem );
	$fresh();
	$twin            = Spots::create(
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Spottest the same tires again ' . $packout_run,
			'lat'         => 34.61235,
			'lng'         => -111.80124,
		),
		$mem
	);
	$packout_posts[] = $twin;
	$go();
	wp_set_current_user( $coordinator );
	$fresh();
	$at = $mark();
	Spots::mark_duplicate( (int) $twin, (int) $spot );
	$go();
	$check( 'a report linked as a duplicate here is removed from the hub', 'removed' === ( $sent( $at )[ (string) $twin ]['state'] ?? '' ), $sent( $at ) );

	$fresh();
	$at = $mark();
	wp_trash_post( (int) $before );
	$go();
	$check( 'and so is one put in the trash', 'removed' === ( $sent( $at )[ (string) $before ]['state'] ?? '' ) && ! isset( $played['spots'][ (string) $before ] ), $sent( $at ) );

	$fresh();
	$copy            = Spots::create(
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Spottest a Spot of the hub, taken on by a mission here ' . $packout_run,
			'lat'         => 34.7,
			'lng'         => -111.9,
		),
		$coordinator
	);
	$packout_posts[] = $copy;
	update_post_meta( (int) $copy, Exchange::ADOPTED, '4411' );
	Outbox::clear();
	$fresh();
	$at = $mark();
	Spots::publish( (int) $copy );
	$go();
	$check( 'a Spot copied from the hub is never sent back to it', '' === Exchange::state( (int) $copy ) && null === Exchange::item( (int) $copy ) && ! isset( $sent( $at )[ (string) $copy ] ) && ! in_array( 'spot ' . $copy, $queue(), true ), $heard( $at ) );

	WP_CLI::log( 'What the hub says back' );

	$played['stands'][ (string) $spot ] = array(
		'standing' => 'hidden',
		'reason'   => 'The gate is private land.',
		'hints'    => array(),
	);
	$fresh();
	Outbox::spot( (int) $spot );
	$go();
	$lines = Exchange::lines( (int) $spot );
	$check( 'when a coordinator on the hub hides a Spot, the site keeps the reason', 'hidden' === Exchange::standing( (int) $spot )['standing'] && 1 === count( $lines ) && false !== strpos( $lines[0]['text'], 'The gate is private land.' ), $lines );
	$_GET['page'] = 'packout-spots';
	ob_start();
	Spots_Screen::render();
	$screen = (string) ob_get_clean();
	unset( $_GET['page'] );
	$check( 'and the Spot\'s pane shows it to a coordinator here', false !== strpos( $screen, 'hid this Spot from the map there' ) && false !== strpos( $screen, 'The gate is private land.' ) );

	$played['stands']  = array();
	$played['changes'] = array(
		'now'   => time() + 3,
		'spots' => array(
			array(
				'remote_id' => (string) $spot,
				'standing'  => 'shown',
				'reason'    => '',
				'hints'     => array(
					array(
						'url'   => $origin . '/spot/tires-by-the-gate/',
						'title' => 'Tires by the gate',
					),
					array(
						'url'   => 'https://elsewhere.example.org/spot/9/',
						'title' => 'Somewhere else',
					),
				),
			),
			array(
				'remote_id' => '99999999',
				'standing'  => 'hidden',
				'reason'    => 'Not a Spot of this site.',
			),
		),
	);
	$at                = $mark();
	$pulled            = Exchange::pull();
	$asked             = array_values( array_filter( array_slice( $played['requests'], $at ), static fn( array $entry ): bool => '/changes' === $entry['route'] ) )[0] ?? array();
	$stands            = Exchange::standing( (int) $spot );
	$check( 'the site asks what changed on the hub, with a signed request that names the time it last asked', true === $pulled && $asked && $asked['signed'] && $asked['good'] && isset( $asked['query']['since'], $asked['query']['nonce'] ), $asked['url'] ?? '' );
	$check( 'a Spot shown again, with a report on the hub that may be the same thing', 'shown' === $stands['standing'] && '' === $stands['reason'] && 1 === count( $stands['hints'] ) && $origin . '/spot/tires-by-the-gate/' === $stands['hints'][0]['url'], $stands );
	$check( 'a hint that points anywhere but the hub is dropped', false === strpos( (string) wp_json_encode( $stands ), 'elsewhere' ) );
	$lines = Exchange::lines( (int) $spot );
	$check( 'the coordinator is told, with a link to the hub\'s report', 1 === count( $lines ) && $origin . '/spot/tires-by-the-gate/' === $lines[0]['url'] && 'Tires by the gate' === $lines[0]['link'], $lines );
	$check( 'the next time, it asks from where the hub\'s clock stood', (int) get_option( Exchange::PULLED ) === $played['changes']['now'] );
	$played['changes'] = array();
	$at                = $mark();
	$go();
	$check( 'every run of the queue asks, so a change on the hub is here within five minutes', in_array( 'GET /changes', $heard( $at ), true ), $heard( $at ) );

	WP_CLI::log( 'When the hub is away, or says no' );

	$played['down'] = true;
	$fresh();
	Outbox::spot( (int) $spot );
	$ran  = $go();
	$rows = array_column( Outbox::rows( 50 ), null, 'subject' );
	$check( 'a Spot that could not be sent waits, and is tried again later', $ran['failed'] >= 1 && isset( $rows[ (string) $spot ] ) && 1 === $rows[ (string) $spot ]['attempts'] && $rows[ (string) $spot ]['next_try'] > time(), $ran );
	$played['down'] = false;
	$at             = $mark();
	$ran            = $go();
	$check( 'and goes when the hub is back', isset( $sent( $at )[ (string) $spot ] ) && 0 === $ran['waiting'], $ran );

	$played['refuse'][ (string) $spot ] = 'foreign_url';
	$fresh();
	Outbox::spot( (int) $spot );
	$go();
	$rows = array_column( Outbox::rows( 50 ), null, 'subject' );
	$check( 'a Spot the hub refuses is kept, with the reason, and the rest still go', isset( $rows[ (string) $spot ] ) && 'foreign_url' === $rows[ (string) $spot ]['last_error'] && '' === Connection::state()['error'], $rows );
	$played['refuse'] = array();
	$go();

	$played['spots'][ (string) $spot ]['photos'] = array();
	$played['next']['/images']                   = $wrong( 409, 'not_expected' );
	$fresh();
	Outbox::spot( (int) $spot );
	$at  = $mark();
	$ran = $go();
	$check( 'an image the hub does not want is dropped, and the others still go', 2 === count( $images( $at ) ) && 0 === $ran['waiting'] && ! array_filter( $queue(), static fn( string $row ): bool => 0 === strpos( $row, 'image ' ) ), array( $ran, $queue() ) );

	$played['state'] = 'suspended';
	$fresh();
	Outbox::spot( (int) $spot );
	$at = $mark();
	$go();
	$check( 'a suspended partner\'s Spots wait', in_array( 'spot ' . $spot, $queue(), true ) && ! Connection::sending(), $queue() );
	$played['state'] = 'active';
	Connection::refresh();
	$go();
	$check( 'and go once the suspension is lifted', ! in_array( 'spot ' . $spot, $queue(), true ), $queue() );

	WP_CLI::log( 'Comparing with what the hub holds' );

	wp_set_current_user( $mem );
	$fresh();
	$missed          = Spots::create(
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Spottest a report the hub never got ' . $packout_run,
			'lat'         => 34.8,
			'lng'         => -111.95,
		),
		$mem
	);
	$packout_posts[] = $missed;
	Outbox::clear();
	$fresh();
	$played['index'] = array(
		// As the site has it.
		(string) $spot  => array(
			'rev'   => Listings::rev( (int) $spot ),
			'state' => 'published',
		),
		// Held by the hub, deleted here.
		'77777701'      => array(
			'rev'   => 5,
			'state' => 'published',
		),
		// Held by the hub as public, kept private here since.
		(string) $quiet => array(
			'rev'   => 5,
			'state' => 'published',
		),
	);
	$at     = $mark();
	$queued = Exchange::reconcile();
	$asked  = array_values( array_filter( array_slice( $played['requests'], $at ), static fn( array $entry ): bool => '/spots/index' === $entry['route'] ) );
	$waits  = $queue();
	$check( 'the site reads what the hub holds, a page at a time', count( $asked ) >= 2 && $asked[0]['signed'] && $asked[0]['good'] && '0' === $asked[0]['query']['after'], count( $asked ) );
	$check( 'and queues what differs: a report the hub never got, one the hub should no longer show, and one deleted here', ! is_wp_error( $queued ) && in_array( 'spot ' . $missed, $waits, true ) && in_array( 'spot ' . $quiet, $waits, true ) && in_array( 'spot 77777701', $waits, true ), $waits );
	$check( 'but not a Spot the hub already has as it is', ! in_array( 'spot ' . $spot, $waits, true ), $waits );
	$played['index'] = null;
	$at              = $mark();
	$go();
	$gone = $sent( $at );
	$check( 'what the hub should not hold goes as removed, and what it lacks goes as it stands', 'removed' === ( $gone['77777701']['state'] ?? '' ) && 'removed' === ( $gone[ (string) $quiet ]['state'] ?? '' ) && 'awaiting' === ( $gone[ (string) $missed ]['state'] ?? '' ), $gone );
	Outbox::everything();
	$check( 'the nightly send includes the comparison', in_array( 'reconcile spots', $queue(), true ), $queue() );
	$go();

	$all    = $everything();
	$secret = (string) ( Connection::key()['secret'] ?? '' );
	$check( 'every request about a Spot was signed, and none carried the site\'s secret key', '' !== $secret && false === strpos( $all, $secret ) && ! array_filter( $played['requests'], static fn( array $entry ): bool => in_array( $entry['route'], array( '/spots', '/images', '/changes', '/spots/index' ), true ) && ( ! $entry['signed'] || ! $entry['good'] ) ) );
	$check( 'and every Spot sent fit the contract', ! array_filter( $played['requests'], static fn( array $entry ): bool => '/spots' === $entry['route'] && '' !== $entry['fault'] ) );
	$check( 'no email address of anyone left the site', 0 === preg_match( '/pos-test-[a-z]+-' . preg_quote( $packout_run, '/' ) . '@example\.test/', str_replace( get_userdata( $admin )->user_email, '', $all ) ) );
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
		wp_delete_post( $post_id, true );
	}
	foreach ( $packout_users as $user_id ) {
		wp_delete_user( (int) $user_id );
	}
	foreach ( $packout_files as $path ) {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
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
