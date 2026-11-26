<?php
/**
 * The field app on a partner site, as a phone is sent it.
 *
 * The page, its manifest, and its worker over HTTP: what each is, what it
 * tells a cache, and how far the worker reaches. Every file the worker keeps
 * for use with no signal answers. The app moves with its address when the
 * site changes it. The acts of the field app have code behind them.
 *
 * What the app carries (the partner's name, the site's icon, the theme's
 * color) is in tests/core/phase-23.php. What it does in a browser with no
 * signal is in the hub repository's Docker/scripts/tests/partner-field.cjs.
 *
 * Run inside WordPress, with the plugin active:
 *
 *     wp eval-file tests/field.php
 *
 * The checks put the site's addresses back as they were.
 *
 * @package PackOut_Partner
 */

use PackOut\Core\App\App;
use PackOut\Core\App\Field_App;
use PackOut\Core\App\Partners;
use PackOut\Core\App\Schema;
use PackOut\Core\Capability_Map;
use PackOut\Partner\Addresses;
use PackOut\Partner\Settings;

require __DIR__ . '/support.php';

$packout_run   = strtolower( wp_generate_password( 6, false ) );
$packout_bases = Settings::get( 'bases' );

// An address of the site over HTTP, as a phone asks for it.
$home     = untrailingslashit( home_url() );
$host     = (string) preg_replace( '#^https?://#', '', $home );
$loopback = untrailingslashit( (string) ( getenv( 'PACKOUT_LOOPBACK' ) ? getenv( 'PACKOUT_LOOPBACK' ) : $home ) );
// The site's address may have a path. The loopback address stands for the host alone.
$origin = (string) preg_replace( '#^(https?://[^/]+).*$#', '$1', $home );
$fetch  = static function ( string $url ) use ( $origin, $host, $loopback ): array {
	$response = wp_remote_get(
		0 === strpos( $url, $origin ) ? (string) preg_replace( '#^(https?://[^/]+).*$#', '$1', $loopback ) . substr( $url, strlen( $origin ) ) : $url,
		array(
			'headers'     => array( 'Host' => (string) preg_replace( '#/.*$#', '', $host ) ),
			'redirection' => 0,
			'timeout'     => 30,
		)
	);
	if ( is_wp_error( $response ) ) {
		return array( 0, array(), $response->get_error_message() );
	}
	$headers = array();
	foreach ( array( 'content-type', 'cache-control', 'expires', 'service-worker-allowed', 'location' ) as $name ) {
		$headers[ $name ] = (string) wp_remote_retrieve_header( $response, $name );
	}
	return array( (int) wp_remote_retrieve_response_code( $response ), $headers, (string) wp_remote_retrieve_body( $response ) );
};
$settle = static function () use ( $fetch ): void {
	$fetch( home_url( '/' ) );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( Addresses::FLUSH, 'options' );
	Schema::types();
};
// A cache between the site and a phone is told to ask the site every time, and is given a date long past to keep the
// answer until, so that a web server which dates what has no date leaves it alone.
$asks_again = static fn( array $headers ): bool => false !== strpos( $headers['cache-control'], 'no-cache' ) && false !== strpos( $headers['cache-control'], 'max-age=0' ) && false === strpos( $headers['cache-control'], 'public' ) && '' !== $headers['expires'] && strtotime( $headers['expires'] ) < time() - YEAR_IN_SECONDS;

try {
	$partner = Partners::site();
	if ( $partner <= 0 ) {
		WP_CLI::error( 'The site has no partner. Nothing can be checked.' );
	}
	$name = (string) get_post_field( 'post_title', $partner );
	$app  = Field_App::url();
	$path = (string) wp_parse_url( $app, PHP_URL_PATH );

	WP_CLI::log( 'The page' );
	$page = $fetch( $app );
	packout_partner_check( 'the app\'s address answers with a page', 200 === $page[0] && 0 === strpos( $page[1]['content-type'], 'text/html' ) && false !== strpos( $page[2], 'id="pos-field"' ), array( $page[0], $page[1] ) );
	packout_partner_check( 'which a cache is told to ask for again every time', $asks_again( $page[1] ), $page[1]['cache-control'] );
	packout_partner_check( 'it is titled with the partner\'s name', false !== strpos( $page[2], '<title>' . esc_html( $name . ' field app' ) . '</title>' ), substr( $page[2], (int) strpos( $page[2], '<title>' ), 120 ) );
	packout_partner_check( 'it is kept out of search results and names its manifest', false !== strpos( $page[2], '<meta name="robots" content="noindex" />' ) && false !== strpos( $page[2], 'rel="manifest" href="' . esc_url( $app . 'app.webmanifest' ) . '"' ) );
	$config = array();
	if ( preg_match( '/id="pos-field" data-config="([^"]*)"/', $page[2], $found ) ) {
		$config = (array) json_decode( html_entity_decode( $found[1], ENT_QUOTES ), true );
	}
	packout_partner_check( 'it tells the app its name, its reach, and where its worker is', ( $config['name'] ?? '' ) === $name && ( $config['scope'] ?? '' ) === $path && 0 === strpos( (string) ( $config['worker'] ?? '' ), $app . 'sw.js?v=' ), array( $config['name'] ?? null, $config['scope'] ?? null, $config['worker'] ?? null ) );
	packout_partner_check( 'the page is the same for everyone: it names no person', false === strpos( $page[2], (string) get_option( 'admin_email' ) ) && false === strpos( $page[2], 'wp-admin/profile' ) && false === stripos( $page[2], 'logout' ) );
	$bare = $fetch( untrailingslashit( $app ) );
	packout_partner_check( 'the address without its last stroke leads to the one with it', in_array( $bare[0], array( 301, 302 ), true ) && untrailingslashit( $bare[1]['location'] ) === untrailingslashit( $app ) && '/' === substr( $bare[1]['location'], -1 ), array( $bare[0], $bare[1]['location'] ) );

	WP_CLI::log( 'The manifest' );
	$manifest = $fetch( $app . 'app.webmanifest' );
	$said     = (array) json_decode( $manifest[2], true );
	packout_partner_check( 'the manifest answers as a manifest', 200 === $manifest[0] && 0 === strpos( $manifest[1]['content-type'], 'application/manifest+json' ) && $asks_again( $manifest[1] ), array( $manifest[0], $manifest[1] ) );
	packout_partner_check( 'it is what the plugin says of the app, with the partner\'s name under the icon', Field_App::manifest_data() === $said && ( $said['short_name'] ?? '' ) === $name, $said );
	packout_partner_check( 'the app opens at its own address, on its own, and reaches no further than that', ( $said['start_url'] ?? '' ) === $path && ( $said['scope'] ?? '' ) === $path && 'standalone' === ( $said['display'] ?? '' ) );
	$icons = array();
	foreach ( (array) ( $said['icons'] ?? array() ) as $icon ) {
		$got     = $fetch( (string) $icon['src'] );
		$size    = 200 === $got[0] ? getimagesizefromstring( $got[2] ) : false;
		$icons[] = array( $icon['sizes'], $got[0], $size ? $size[0] . 'x' . $size[1] : '' );
	}
	packout_partner_check( 'each icon it names answers, and is the size it says', count( $icons ) >= 2 && array() === array_filter( $icons, static fn( array $icon ): bool => 200 !== $icon[1] || $icon[0] !== $icon[2] ), $icons );

	WP_CLI::log( 'The worker' );
	$worker = $fetch( (string) ( $config['worker'] ?? $app . 'sw.js' ) );
	packout_partner_check( 'the worker answers as a script', 200 === $worker[0] && 0 === strpos( $worker[1]['content-type'], 'application/javascript' ) && $asks_again( $worker[1] ), array( $worker[0], $worker[1] ) );
	packout_partner_check( 'it may control the app\'s address and nothing else of the site', $worker[1]['service-worker-allowed'] === $path && '/' !== $path, $worker[1]['service-worker-allowed'] );
	$setup = array();
	if ( preg_match( '/^const SETUP = (\{.*\});$/m', $worker[2], $found ) ) {
		$setup = (array) json_decode( $found[1], true );
	}
	packout_partner_check( 'it knows its version, the page, and the files to keep', 1 === preg_match( '/^[0-9a-f]{12}$/', (string) ( $setup['version'] ?? '' ) ) && ( $setup['shell'] ?? '' ) === $app && count( (array) ( $setup['assets'] ?? array() ) ) >= 8 && false !== strpos( (string) ( $config['worker'] ?? '' ), 'v=' . ( $setup['version'] ?? '?' ) ), array( $setup['version'] ?? null, $setup['shell'] ?? null, count( (array) ( $setup['assets'] ?? array() ) ) ) );
	$without = $fetch( $app . 'sw.js' );
	packout_partner_check( 'asked for without a version it is the same worker, and is not sent on to another address', 200 === $without[0] && $without[2] === $worker[2] );
	// A worker that cannot fetch one of its files does not install, and the app then does not open with no signal.
	$missing = array();
	$foreign = array();
	foreach ( (array) ( $setup['assets'] ?? array() ) as $asset ) {
		if ( 0 !== strpos( (string) $asset, $origin . '/' ) ) {
			$foreign[] = $asset;
			continue;
		}
		$got = $fetch( (string) $asset );
		if ( 200 !== $got[0] || '' === $got[2] ) {
			$missing[] = array( $asset, $got[0] );
		}
	}
	packout_partner_check( 'every file it keeps for use with no signal answers', array() === $missing, $missing );
	packout_partner_check( 'and every one is this site\'s own: the app asks no other server for its code', array() === $foreign, $foreign );
	packout_partner_check( 'the page loads those same files', array() === array_filter( (array) ( $setup['assets'] ?? array() ), static fn( $asset ): bool => false === strpos( (string) $asset, '/assets/img/' ) && false === strpos( $page[2], esc_url( (string) $asset ) ) && false === strpos( $page[2], (string) $asset ) && false === strpos( $page[2], str_replace( '&', '&#038;', (string) $asset ) ) ), $setup['assets'] ?? array() );

	WP_CLI::log( 'What the app does has code behind it' );
	$acts = array( 'spot.report.app', 'field.capture', 'field.region', 'field.mission', 'field.cleaned', 'mission.decide', 'admin.regions' );
	packout_partner_check( 'each act of the field app names code that can be called', array() === array_filter( $acts, static fn( string $act ): bool => ! is_callable( Capability_Map::handler( $act ) ) ), array_values( array_filter( $acts, static fn( string $act ): bool => ! is_callable( Capability_Map::handler( $act ) ) ) ) );

	WP_CLI::log( 'The app moves with its address' );
	$new      = 'afield-' . $packout_run;
	$problems = Addresses::save( array( 'field' => $new ) );
	$settle();
	$moved = Field_App::url();
	packout_partner_check( 'the site may give the app another address', array() === $problems && App::base( 'field' ) === $new && false !== strpos( $moved, '/' . $new . '/' ), array( $problems, $moved ) );
	$page  = $fetch( $moved );
	$reach = (string) wp_parse_url( $moved, PHP_URL_PATH );
	packout_partner_check( 'the app answers there', 200 === $page[0] && false !== strpos( $page[2], 'id="pos-field"' ), $page[0] );
	$worker   = $fetch( $moved . 'sw.js' );
	$manifest = (array) json_decode( $fetch( $moved . 'app.webmanifest' )[2], true );
	packout_partner_check( 'and its worker and its manifest reach as far as the new address and no further', $worker[1]['service-worker-allowed'] === $reach && ( $manifest['scope'] ?? '' ) === $reach && ( $manifest['start_url'] ?? '' ) === $reach && false !== strpos( $worker[2], wp_json_encode( $moved ) ), array( $worker[1]['service-worker-allowed'], $manifest['scope'] ?? null ) );
	$old = $fetch( $app );
	packout_partner_check( 'the old address sends a phone to the new one', in_array( $old[0], array( 301, 302 ), true ) && untrailingslashit( $old[1]['location'] ) === untrailingslashit( $moved ), array( $old[0], $old[1]['location'] ) );
} catch ( \Throwable $e ) {
	packout_partner_check( $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine(), false );
} finally {
	if ( Settings::get( 'bases' ) !== $packout_bases ) {
		Addresses::save( array( 'field' => $packout_bases['field'] ) );
		$settle();
		$back = $fetch( home_url( '/' . $packout_bases['field'] . '/' ) );
		packout_partner_check( 'put back, the app answers at its first address again', 200 === $back[0] && Settings::get( 'bases' ) === $packout_bases, $back[0] );
	}
}

packout_partner_finish();
