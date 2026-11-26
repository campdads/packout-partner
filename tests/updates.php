<?php
/**
 * The plugin takes a new version from the catalog on packout.spot, and only
 * a package that Pack-Out Spot signed.
 *
 * Run inside WordPress, with the plugin active, on a site whose environment
 * type is `local`:
 *
 *     wp eval-file tests/updates.php
 *
 * No request leaves the site. The catalog's answers, WordPress.org's answer,
 * and the package are supplied here, and the package is signed with a key
 * made for this run. A local site may add a key to the ones it trusts; no
 * other site can.
 *
 * @package PackOut_Partner
 */

use PackOut\Core\Release;
use PackOut\Partner\Plugin;
use PackOut\Partner\Settings;

require __DIR__ . '/support.php';

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Run this on a site whose environment type is local. It signs a package with a key of its own.' );
}

$packout_file    = plugin_basename( PACKOUT_PARTNER_FILE );
$packout_updates = Plugin::updates();
$packout_before  = get_option( Settings::OPTION );
delete_option( Settings::OPTION );

// A package of a newer version, and two keys: one this site will trust, one it will not.
$packout_zip_path = wp_tempnam( 'packout-partner-test.zip' );
$packout_zip      = new ZipArchive();
$packout_zip->open( $packout_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$packout_zip->addFromString( 'packout-partner/packout-partner.php', "<?php\n/**\n * Plugin Name: Pack-Out Spot Partner\n * Version: 9.9.9\n */\n" );
$packout_zip->close();
$packout_bytes = (string) file_get_contents( $packout_zip_path );
wp_delete_file( $packout_zip_path );

$packout_trusted   = sodium_crypto_sign_keypair();
$packout_stranger  = sodium_crypto_sign_keypair();
$packout_trust     = static function ( $keys ) use ( $packout_trusted ) {
	$keys[] = bin2hex( sodium_crypto_sign_publickey( $packout_trusted ) );
	return $keys;
};
$packout_package   = 'https://packout.spot/wp-json/vectra/v1/packages/packout-partner/9.9.9';
$packout_signature = bin2hex( sodium_crypto_sign_detached( $packout_bytes, sodium_crypto_sign_secretkey( $packout_trusted ) ) );

/*
 * What the catalog will say. Each check below changes one thing.
 */
$packout_catalog = array(
	'status'    => 200,
	'module'    => array(
		'slug'         => 'packout-partner',
		'name'         => 'Pack-Out Spot Partner',
		'version'      => '9.9.9',
		'package_url'  => $packout_package,
		'checksum'     => hash( 'sha256', $packout_bytes ),
		'signature'    => $packout_signature,
		'release_date' => '2026-11-24T00:00:00+00:00',
		'requires'     => array(
			'php'              => '8.2',
			'wordpress'        => '6.8',
			'tested_wordpress' => '7.1',
		),
	),
	'bytes'     => $packout_bytes,
	'asked'     => array(),
	'downloads' => 0,
	'elsewhere' => array(),
);

$packout_http = static function ( $reply, $args, $url ) use ( &$packout_catalog ) {
	$json = static function ( int $status, $body ): array {
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	if ( 0 === strpos( $url, 'https://api.wordpress.org/' ) ) {
		return $json(
			200,
			array(
				'plugins'      => array(),
				'translations' => array(),
				'no_update'    => array(),
			)
		);
	}
	if ( 'https://packout.spot/wp-json/vectra/v1/manifest' === $url ) {
		$packout_catalog['asked'][] = array(
			'method' => $args['method'] ?? '',
			'body'   => $args['body'] ?? null,
			'agent'  => (string) ( $args['user-agent'] ?? '' ),
		);
		return $json(
			$packout_catalog['status'],
			array(
				'schema'  => 1,
				'modules' => null === $packout_catalog['module'] ? array() : array( $packout_catalog['module'] ),
			)
		);
	}
	if ( 'https://packout.spot/wp-json/vectra/v1/plugins/packout-partner' === $url ) {
		return $json(
			200,
			array_merge(
				(array) $packout_catalog['module'],
				array(
					'description' => "Stewardship on your own site.\n\n<script>alert(1)</script>",
					'changelog'   => 'The first beta.',
				)
			)
		);
	}
	if ( 0 === strpos( $url, 'https://packout.spot/wp-json/vectra/v1/packages/' ) ) {
		++$packout_catalog['downloads'];
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $packout_catalog['bytes'] );
		}
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'] ?? null,
		);
	}
	$packout_catalog['elsewhere'][] = $url;
	return new WP_Error( 'packout_test_offline', 'The test let no request out: ' . $url );
};
add_filter( 'pre_http_request', $packout_http, 10, 3 );
add_filter( 'packout_release_public_keys', $packout_trust );

$packout_check_now = static function () use ( $packout_updates, $packout_file ) {
	$packout_updates->forget();
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	$found = get_site_transient( 'update_plugins' );
	return is_object( $found ) && isset( $found->response[ $packout_file ] ) ? $found->response[ $packout_file ] : null;
};
$packout_download  = static function ( string $package ) use ( $packout_file ) {
	return apply_filters( 'upgrader_pre_download', false, $package, null, array( 'plugin' => $packout_file ) );
};
$packout_code      = static function ( $result ): string {
	return is_wp_error( $result ) ? (string) $result->get_error_code() : ( is_string( $result ) ? 'file' : gettype( $result ) );
};
$packout_leftovers = static function (): array {
	$found = glob( trailingslashit( get_temp_dir() ) . '9.9.9*' );
	return is_array( $found ) ? $found : array();
};
foreach ( $packout_leftovers() as $packout_old ) {
	wp_delete_file( $packout_old );
}

WP_CLI::log( 'WordPress is told about a new version' );

$packout_offer = $packout_check_now();
packout_partner_check( 'WordPress\'s own update check offers the catalog\'s version', is_object( $packout_offer ) && '9.9.9' === ( $packout_offer->new_version ?? $packout_offer->version ?? '' ), $packout_offer );
packout_partner_check( 'with the catalog\'s package', is_object( $packout_offer ) && ( $packout_offer->package ?? '' ) === $packout_package );
packout_partner_check( 'and the versions of WordPress and PHP it needs', is_object( $packout_offer ) && '6.8' === ( $packout_offer->requires ?? '' ) && '8.2' === ( $packout_offer->requires_php ?? '' ) && '7.1' === ( $packout_offer->tested ?? '' ) );

$packout_asked = $packout_catalog['asked'][0] ?? array();
$packout_sent  = is_array( $packout_asked['body'] ?? null ) ? $packout_asked['body'] : array();
packout_partner_check( 'the plugin asked the catalog once, with a POST', 1 === count( $packout_catalog['asked'] ) && 'POST' === ( $packout_asked['method'] ?? '' ), count( $packout_catalog['asked'] ) );
packout_partner_check( 'it sent the plugin\'s file and version, the channel, and nothing else', array( 'plugins', 'channel' ) === array_keys( $packout_sent ) && array( $packout_file => array( 'Version' => PACKOUT_PARTNER_VERSION ) ) === json_decode( (string) $packout_sent['plugins'], true ) && 'stable' === $packout_sent['channel'], $packout_sent );
packout_partner_check( 'the request carries the site\'s address, as readme.txt says', str_contains( (string) ( $packout_asked['agent'] ?? '' ), (string) get_bloginfo( 'url' ) ), $packout_asked['agent'] ?? '' );
packout_partner_check( 'no request went anywhere else', array() === $packout_catalog['elsewhere'], $packout_catalog['elsewhere'] );

wp_update_plugins();
packout_partner_check( 'a second check within twelve hours does not ask the catalog again', 1 === count( $packout_catalog['asked'] ) );
packout_partner_check( 'the settings screen can say when it last checked', $packout_updates->status()['checked'] > 0 && ! $packout_updates->status()['failed'] && '9.9.9' === $packout_updates->status()['version'] );

require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
$packout_details = plugins_api( 'plugin_information', (object) array( 'slug' => 'packout-partner' ) );
packout_partner_check( 'the details window is filled from the catalog', is_object( $packout_details ) && 'Pack-Out Spot Partner' === $packout_details->name && '9.9.9' === $packout_details->version && 'https://packout.spot/plugins/packout-partner' === $packout_details->homepage );
packout_partner_check( 'and what the catalog says is cleaned before it is shown', is_object( $packout_details ) && str_contains( $packout_details->sections['description'], 'Stewardship' ) && ! str_contains( $packout_details->sections['description'], '<script' ) && isset( $packout_details->sections['changelog'] ) );
$packout_other = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'akismet' ) );
packout_partner_check( 'another plugin\'s details are left to WordPress', false === $packout_other );

WP_CLI::log( 'Only a package Pack-Out Spot signed is installed' );

$packout_good = $packout_download( $packout_package );
packout_partner_check( 'a package with the catalog\'s checksum and a trusted signature is handed to WordPress', is_string( $packout_good ) && is_file( $packout_good ) && hash_file( 'sha256', $packout_good ) === $packout_catalog['module']['checksum'], $packout_code( $packout_good ) );
if ( is_string( $packout_good ) ) {
	wp_delete_file( $packout_good );
}

$packout_catalog['bytes'] = $packout_bytes . 'x';
$packout_result           = $packout_download( $packout_package );
packout_partner_check( 'a package that is not the file the catalog described is refused', 'packout_package_checksum' === $packout_code( $packout_result ), $packout_code( $packout_result ) );
packout_partner_check( 'and the file is not left behind', array() === $packout_leftovers(), $packout_leftovers() );
$packout_catalog['bytes'] = $packout_bytes;

$packout_catalog['module']['signature'] = bin2hex( sodium_crypto_sign_detached( $packout_bytes, sodium_crypto_sign_secretkey( $packout_stranger ) ) );
$packout_updates->forget();
$packout_result = $packout_download( $packout_package );
packout_partner_check( 'a package signed by a key the site does not trust is refused', 'packout_package_signature' === $packout_code( $packout_result ), $packout_code( $packout_result ) );
packout_partner_check( 'and the file is not left behind', array() === $packout_leftovers(), $packout_leftovers() );

$packout_catalog['module']['signature'] = '';
$packout_updates->forget();
$packout_count  = $packout_catalog['downloads'];
$packout_result = $packout_download( $packout_package );
packout_partner_check( 'a package with no signature is refused', 'packout_package_unsigned' === $packout_code( $packout_result ), $packout_code( $packout_result ) );
packout_partner_check( 'without being downloaded', $packout_count === $packout_catalog['downloads'] );

$packout_catalog['module']['signature'] = $packout_signature;
$packout_catalog['module']['checksum']  = '';
$packout_updates->forget();
$packout_result = $packout_download( $packout_package );
packout_partner_check( 'a package with no checksum is refused', 'packout_package_unsigned' === $packout_code( $packout_result ), $packout_code( $packout_result ) );
$packout_catalog['module']['checksum'] = hash( 'sha256', $packout_bytes );

remove_filter( 'packout_release_public_keys', $packout_trust );
$packout_updates->forget();
$packout_result = $packout_download( $packout_package );
packout_partner_check( 'the key made for this run is trusted only because this run added it: without it, the same package is refused', 'packout_package_signature' === $packout_code( $packout_result ), $packout_code( $packout_result ) );
packout_partner_check( 'the plugin carries one release key, and it is not the test\'s', array( Release::PUBLIC_KEYS[0] ) === Release::public_keys() && bin2hex( sodium_crypto_sign_publickey( $packout_trusted ) ) !== Release::PUBLIC_KEYS[0] );
add_filter( 'packout_release_public_keys', $packout_trust );

$packout_updates->forget();
$packout_result = $packout_download( 'https://packout.spot/wp-json/vectra/v1/packages/packout-partner/9.9.8' );
packout_partner_check( 'a package the catalog is not offering is refused', 'packout_package_unknown' === $packout_code( $packout_result ), $packout_code( $packout_result ) );
$packout_result = $packout_download( 'https://example.com/wp-json/vectra/v1/packages/packout-partner/9.9.9' );
packout_partner_check( 'a download from somewhere else is not the plugin\'s business', false === $packout_result );
$packout_result = apply_filters( 'upgrader_pre_download', false, $packout_package, null, array( 'plugin' => 'akismet/akismet.php' ) );
packout_partner_check( 'nor is another plugin\'s update', false === $packout_result );

WP_CLI::log( 'When the catalog has nothing to offer' );

$packout_catalog['module']['package_url'] = 'https://example.com/wp-json/vectra/v1/packages/packout-partner/9.9.9';
packout_partner_check( 'an offer whose package is not on packout.spot is not passed on', null === $packout_check_now() );
$packout_catalog['module']['package_url'] = $packout_package;

$packout_catalog['module']['version'] = PACKOUT_PARTNER_VERSION;
packout_partner_check( 'the version the site already has is not an update', null === $packout_check_now() );
$packout_catalog['module']['version'] = '9.9.9';

$packout_kept              = $packout_catalog['module'];
$packout_catalog['module'] = null;
packout_partner_check( 'a catalog with no newer version offers nothing', null === $packout_check_now() && ! $packout_updates->status()['failed'] );

$packout_catalog['status'] = 503;
packout_partner_check( 'a catalog that does not answer offers nothing, and WordPress\'s check still finishes', null === $packout_check_now() && is_object( get_site_transient( 'update_plugins' ) ) );
packout_partner_check( 'and the settings screen can say the check failed', $packout_updates->status()['failed'] );
$packout_catalog['status'] = 200;
$packout_catalog['module'] = $packout_kept;

WP_CLI::log( 'Beta versions' );

$packout_catalog['asked'] = array();
Settings::update( array( 'beta' => true ) );
$packout_offer = $packout_check_now();
$packout_last  = end( $packout_catalog['asked'] );
packout_partner_check( 'a site that chose beta versions asks for the beta channel', is_array( $packout_last ) && 'beta' === ( $packout_last['body']['channel'] ?? '' ), $packout_last );
packout_partner_check( 'and is offered what the catalog answers', is_object( $packout_offer ) );
Settings::update( array( 'beta' => false ) );
$packout_check_now();
$packout_last = end( $packout_catalog['asked'] );
packout_partner_check( 'a site that changed its mind asks for stable again', is_array( $packout_last ) && 'stable' === ( $packout_last['body']['channel'] ?? '' ) );

WP_CLI::log( 'A site that has turned plugin changes off' );

$packout_no_mods = static function ( $allowed, $context ) {
	return 'capability_update_core' === $context ? false : $allowed;
};
add_filter( 'file_mod_allowed', $packout_no_mods, 10, 2 );
$packout_catalog['asked'] = array();
packout_partner_check( 'is offered nothing, and the catalog is not asked', null === $packout_check_now() && array() === $packout_catalog['asked'] );
remove_filter( 'file_mod_allowed', $packout_no_mods, 10 );

// Leave the site as it was.
remove_filter( 'pre_http_request', $packout_http, 10 );
remove_filter( 'packout_release_public_keys', $packout_trust );
$packout_updates->forget();
delete_site_transient( 'update_plugins' );
if ( false === $packout_before ) {
	delete_option( Settings::OPTION );
} else {
	update_option( Settings::OPTION, $packout_before );
}
sodium_memzero( $packout_trusted );
sodium_memzero( $packout_stranger );

packout_partner_finish();
