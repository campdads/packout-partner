<?php
/**
 * Phase 23 acceptance checks: the field app as a site's own.
 *
 * What the app is called, the icon and color it installs with, what its
 * manifest and its configuration say, the hub's Spots it may keep with a
 * region, and a report sent from the app going on to packout.spot as any
 * saved report does.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-23.php
 *
 * No request leaves the site: the one the checks would cause is caught and
 * answered here. The checks make their own user, picture, and Spot and
 * delete them, and put the site's icon, its partner's name, and its
 * connection back as they were.
 *
 * What a browser does with the app, with no signal, is in the browser
 * checks: scripts/tests/field-app.cjs on the hub and partner-field.cjs on a
 * partner site.
 *
 * @package PackOut_Core
 */

use PackOut\Core\Signing;
use PackOut\Core\App\App;
use PackOut\Core\App\Connection;
use PackOut\Core\App\Exchange;
use PackOut\Core\App\Field_App;
use PackOut\Core\App\Hub_Layers;
use PackOut\Core\App\Outbox;
use PackOut\Core\App\Partners;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Ui;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
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
$packout_title    = null;
$packout_icon     = null;

$check = static function ( string $label, bool $ok, $detail = '' ) use ( &$packout_failures, &$packout_passed ): void {
	if ( $ok ) {
		++$packout_passed;
		WP_CLI::log( '  ok    ' . $label );
		return;
	}
	$packout_failures[] = $label;
	WP_CLI::log( '  FAIL  ' . $label . ( '' !== $detail && array() !== $detail ? ' — ' . ( is_scalar( $detail ) ? $detail : wp_json_encode( $detail ) ) : '' ) );
};

// A square picture in the media library, as a site icon is.
$picture = static function ( int $side ) use ( &$packout_posts, &$packout_files, $packout_run ): int {
	$upload = wp_upload_dir();
	$path   = trailingslashit( $upload['path'] ) . 'pos-test-icon-' . $side . '-' . $packout_run . '.png';
	$image  = imagecreatetruecolor( $side, $side );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 65, 88, 64 ) );
	imagepng( $image, $path );
	$packout_files[] = $path;
	$id              = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Test icon ' . $side,
			'post_status'    => 'inherit',
		),
		$path
	);
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, $path ) );
	$packout_posts[] = (int) $id;
	return (int) $id;
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

// Nothing leaves the site. A request to the hub is counted and answered with nothing in it.
$origin = Connection::hub();
$asked  = array();
$answer = static function ( $pre, $args, $url ) use ( &$asked, $origin ) {
	if ( 0 !== strpos( (string) $url, $origin . '/wp-json/packout/v1' ) ) {
		return $pre;
	}
	$asked[] = strtoupper( (string) ( $args['method'] ?? 'GET' ) ) . ' ' . (string) strtok( substr( (string) $url, strlen( $origin . Connection::BASE ) ), '?' );
	return array(
		'headers'  => array(),
		'body'     => '{}',
		'response' => array(
			'code'    => 200,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $answer, 5, 3 );

$hub  = App::is_hub();
$path = (string) wp_parse_url( home_url( '/' . App::base( 'field' ) . '/' ), PHP_URL_PATH );

try {
	Schema::register();
	$packout_icon = get_option( 'site_icon', null );
	delete_option( 'site_icon' );

	WP_CLI::log( 'What the app is called' );
	$manifest = Field_App::manifest_data();
	$config   = Field_App::config();
	if ( $hub ) {
		$check( 'on packout.spot the app is Pack-Out Spot', 'Pack-Out Spot' === Field_App::name() && 'Pack-Out Spot field app' === $manifest['name'] && 'Pack-Out Spot' === $manifest['short_name'] && 'Pack-Out Spot' === $config['name'], array( Field_App::name(), $manifest['name'] ) );
		$check( 'and keeps its own color', '#2f3232' === $manifest['theme_color'] && '#2f3232' === Field_App::color() );
	} else {
		$site          = Partners::site();
		$packout_title = $site > 0 ? (string) get_post_field( 'post_title', $site ) : null;
		$check( 'on a partner\'s site the app carries the partner\'s name', $site > 0 && Field_App::name() === $packout_title && $packout_title . ' field app' === $manifest['name'] && $packout_title === $manifest['short_name'] && $packout_title === $config['name'], array( Field_App::name(), $manifest['name'], $manifest['short_name'] ) );
		$new_name = 'O\'Neill & Daughters River Watch ' . $packout_run;
		wp_update_post(
			array(
				'ID'         => $site,
				'post_title' => wp_slash( $new_name ),
			)
		);
		$renamed = Field_App::manifest_data();
		$check( 'a new name for the partner is the app\'s name, written as the partner wrote it', $new_name === $renamed['short_name'] && false === strpos( (string) wp_json_encode( $renamed ), '&amp;' ) && false === strpos( $renamed['name'], '&#' ), $renamed['short_name'] );
		$primary = sanitize_hex_color( (string) Ui::colors()['primary'] );
		$check( 'the bar around the app takes the theme\'s primary color', is_string( $primary ) && '' !== $primary && $manifest['theme_color'] === $primary && Field_App::color() === $primary, array( $primary, $manifest['theme_color'] ) );
	}//end if
	$check( 'the app starts at its own address and reaches no further', $path === $manifest['start_url'] && $path === $manifest['scope'] && $path === $config['scope'] && 'standalone' === $manifest['display'], array( $path, $manifest['start_url'], $manifest['scope'], $config['scope'] ) );
	$check( 'its worker is asked for under that address, by a name that carries its version', 0 === strpos( (string) $config['worker'], home_url( '/' . App::base( 'field' ) . '/sw.js?v=' ) ) && 1 === preg_match( '/[?&]v=[0-9a-f]{12}$/', (string) $config['worker'] ), $config['worker'] );
	$check( 'everything the app asks the site is under the site\'s own address', 0 === strpos( (string) $config['rest'], home_url( '/' ) ) && 0 === strpos( (string) $config['nonce'], site_url( '/' ) ) && 0 === strpos( (string) $config['login'], home_url( '/' ) ), array( $config['rest'], $config['nonce'], $config['login'] ) );
	$check( 'the configuration names no person and no address of one', false === strpos( (string) wp_json_encode( $config ), (string) get_option( 'admin_email' ) ) && ! isset( $config['user'] ) );

	WP_CLI::log( 'Its icon' );
	$own = Field_App::icons();
	$check( 'with no site icon the app has the plugin\'s two, which a phone may crop to its own shape', 2 === count( $own ) && '192x192' === $own[0]['sizes'] && '512x512' === $own[1]['sizes'] && 'any maskable' === $own[0]['purpose'] && false !== strpos( $own[0]['src'], 'assets/img/icon-192.png' ), $own );
	$small = $picture( 100 );
	update_option( 'site_icon', $small );
	$check( 'a site icon too small to install with is passed over', Field_App::icons() === $own, Field_App::icons() );
	$large = $picture( 512 );
	update_option( 'site_icon', $large );
	$theirs = Field_App::icons();
	if ( $hub ) {
		$check( 'packout.spot keeps the plugin\'s icon whatever the site\'s is', $theirs === $own, $theirs );
	} else {
		$sizes = array_column( $theirs, 'sizes' );
		$check( 'a partner\'s site icon is the app\'s icon, at the sizes the site has', $theirs && in_array( '512x512', $sizes, true ) && array() === array_filter( $theirs, static fn( array $icon ): bool => false === strpos( $icon['src'], 'pos-test-icon-512-' ) || 'image/png' !== $icon['type'] || 'any' !== $icon['purpose'] ), $theirs );
		// The file itself is measured: WordPress will answer a request for one size with a copy of another.
		$real = static function ( array $icon ): string {
			$upload = wp_upload_dir();
			$size   = 0 === strpos( $icon['src'], $upload['baseurl'] ) ? getimagesize( $upload['basedir'] . substr( $icon['src'], strlen( $upload['baseurl'] ) ) ) : false;
			return $size ? $size[0] . 'x' . $size[1] : '';
		};
		$check( 'each is square and says its real size', array() === array_filter( $theirs, static fn( array $icon ): bool => 1 !== preg_match( '/^(\d+)x\1$/', $icon['sizes'] ) || (int) $icon['sizes'] < 144 || $real( $icon ) !== $icon['sizes'] ), array( $sizes, array_map( $real, $theirs ) ) );
		$check( 'a copy of another size is named by the size it is, and never passed off as the size a phone asks for', 2 === count( $theirs ) && '192x192' !== $theirs[0]['sizes'] && (int) $theirs[0]['sizes'] >= 192 && (int) $theirs[0]['sizes'] < 512, $sizes );
		$check( 'and the manifest carries them', Field_App::manifest_data()['icons'] === $theirs );
	}
	delete_option( 'site_icon' );

	WP_CLI::log( 'The hub\'s Spots, kept with a region' );
	if ( $hub ) {
		$check( 'packout.spot has no layer of its own to keep: the app there is told of none', null === Field_App::config()['hub'] && null === Hub_Layers::config() );
		WP_CLI::log( '  --  A partner\'s name, color, and icon on the app, the hub\'s Spots kept with a region, and a report from the app going on to packout.spot are a partner site\'s. On packout.spot these checks do not apply.' );
	} else {
		foreach ( array( Connection::OPTION, Connection::KEY_OPTION, Hub_Layers::OPTION, Hub_Layers::DATA ) as $option ) {
			$packout_kept[ $option ] = get_option( $option, null );
			delete_option( $option );
		}
		Outbox::install_tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$packout_rows = (array) $wpdb->get_results( "SELECT kind, subject, queued, attempts, next_try, last_error FROM {$wpdb->prefix}pos_outbox", ARRAY_A );
		Outbox::clear();
		Outbox::unschedule();
		$check( 'a site that is not connected tells the app of no layer', null === Field_App::config()['hub'] );

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
				'partner_url' => $origin . '/partner/field-test/',
			)
		);
		if ( ! Connection::sending() ) {
			WP_CLI::error( 'The site could not be put in a connected state.' );
		}
		$check( 'nor does a connected site that has not turned the layers on', null === Field_App::config()['hub'] );
		Hub_Layers::save( true, '-112.2,34.2,-111.3,35.0' );
		update_option(
			Hub_Layers::DATA,
			array(
				'fetched'  => time(),
				'spots'    => array(
					array(
						'id'       => 9001,
						'title'    => 'Tires below the bridge',
						'url'      => $origin . '/spot/tires-below-the-bridge/',
						'category' => 'Cleanup and waste',
						'status'   => 'spotted',
						'hazard'   => 'none',
						'lat'      => 34.6,
						'lng'      => -111.8,
						'coarse'   => false,
						'through'  => '',
					),
				),
				'places'   => array(),
				'features' => array(),
			),
			false
		);
		$told = Field_App::config()['hub'];
		$check( 'with the layers on, the app is told where the site keeps what it read of packout.spot', is_array( $told ) && rest_url( 'packout/v1/hub-layers' ) === $told['rest'] && false !== strpos( (string) $told['kept'], '%s' ) && '' !== (string) $told['kept_one'] && '' !== $told['badge'] && '' !== $told['open'], $told );
		$check( 'that address is the site\'s own: the phone never asks packout.spot', is_array( $told ) && 0 === strpos( (string) $told['rest'], home_url( '/' ) ) && false === strpos( (string) wp_json_encode( $told ), $origin ) );
		wp_set_current_user( 0 );
		$layer = $call( 'GET', '/hub-layers' );
		$check(
			'it answers anyone, with the Spots and nothing that could change one',
			200 === $layer[0] && 1 === count( $layer[1]['spots'] ) && 9001 === (int) $layer[1]['spots'][0]['id'] && array( 'category', 'coarse', 'hazard', 'id', 'lat', 'lng', 'status', 'through', 'title', 'url' ) === ( static function ( array $spot ): array {
				$keys = array_keys( $spot );
				sort( $keys );
				return $keys;
			} )( $layer[1]['spots'][0] ),
			$layer[1]['spots'] ?? $layer
		);
		$check( 'no request went to packout.spot for any of it', array() === $asked, $asked );

		WP_CLI::log( 'A report from the app goes on to packout.spot' );
		$login           = 'pos-test-field-' . $packout_run;
		$ana             = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 24 ),
				'user_email'   => $login . '@example.test',
				'display_name' => 'Ana Fieldtester',
				'role'         => 'pos_reporter',
			)
		);
		$packout_users[] = (int) $ana;
		wp_set_current_user( (int) $ana );
		$client = wp_generate_uuid4();
		$saved  = $call(
			'PUT',
			'/spots/client/' . $client,
			array(
				'category'    => 'cleanup-waste',
				'description' => 'Fieldtest pallets and a tire, left of the wash ' . $packout_run,
				'landmark'    => 'Second cattle guard',
				'lat'         => 34.612345,
				'lng'         => -111.801234,
				'accuracy'    => 9,
				'hazard'      => 'none',
			)
		);
		$spot   = (int) ( $saved[1]['spot']['id'] ?? 0 );
		if ( $spot > 0 ) {
			$packout_posts[] = $spot;
		}
		$queue = array_map( static fn( array $row ): string => $row['kind'] . ' ' . $row['subject'], Outbox::rows( 200 ) );
		$check( 'the app\'s report is saved as a Spot waiting for review', in_array( $saved[0], array( 200, 201 ), true ) && $spot > 0 && 'pending' === get_post_status( $spot ), $saved );
		$check( 'and is queued for packout.spot as any saved report is', in_array( 'spot ' . $spot, $queue, true ), $queue );
		Outbox::wake();
		$timer = wp_next_scheduled( Outbox::SOON );
		$check( 'to be sent at once, not at the next five minutes', false !== $timer && $timer <= time(), $timer );
		$check( 'what it will say is a bare report: awaiting review, with the point rounded', 'awaiting' === Exchange::state( $spot ), Exchange::state( $spot ) );
	}//end if
} finally {
	remove_filter( 'pre_http_request', $answer, 5 );
	wp_set_current_user( 0 );
	foreach ( array_reverse( $packout_posts ) as $post_id ) {
		if ( 'attachment' === get_post_type( (int) $post_id ) ) {
			wp_delete_attachment( (int) $post_id, true );
		} else {
			wp_delete_post( (int) $post_id, true );
		}
	}
	foreach ( $packout_users as $user_id ) {
		wp_delete_user( (int) $user_id );
	}
	foreach ( $packout_files as $file ) {
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}
	if ( null !== $packout_title && Partners::site() > 0 ) {
		wp_update_post(
			array(
				'ID'         => Partners::site(),
				'post_title' => wp_slash( $packout_title ),
			)
		);
	}
	if ( null === $packout_icon ) {
		delete_option( 'site_icon' );
	} else {
		update_option( 'site_icon', $packout_icon );
	}
	foreach ( $packout_kept as $option => $value ) {
		if ( null === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value, Connection::OPTION === $option );
		}
	}
	if ( ! $hub ) {
		Outbox::clear();
		Outbox::unschedule();
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
