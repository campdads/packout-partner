<?php
/**
 * Phase 22 acceptance checks: what makes a partner site a product.
 *
 * The pages the plugin can make and what it will and will not do to them,
 * the setup wizard, the checks Site Health and the commands read, the
 * partner's logo and cover on their way to packout.spot, the partner badge,
 * and WordPress's export and erasure of a person's data.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-22.php
 *
 * The hub is played in place: every request the site makes is answered here
 * and read. The checks make their own users, pages, and records and delete
 * them, and put the site's own settings, pages, partner, and connection
 * back as they were.
 *
 * @package PackOut_Core
 */

use PackOut\Core\Contract;
use PackOut\Core\Signing;
use PackOut\Core\App\Accounts;
use PackOut\Core\App\Admin;
use PackOut\Core\App\Blocks;
use PackOut\Core\App\Connection;
use PackOut\Core\App\Connection_Screen;
use PackOut\Core\App\Exchange;
use PackOut\Core\App\Health;
use PackOut\Core\App\Hub_Layers;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Invitations;
use PackOut\Core\App\Outbox;
use PackOut\Core\App\Overview;
use PackOut\Core\App\Page_Header;
use PackOut\Core\App\Pages;
use PackOut\Core\App\Pages_Screen;
use PackOut\Core\App\Partners;
use PackOut\Core\App\Privacy;
use PackOut\Core\App\Profile;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Settings;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Teams;
use PackOut\Core\App\Updates;
use PackOut\Core\App\Wizard;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( \PackOut\Core\App\App::is_hub() ) {
	WP_CLI::log( '  --  The setup wizard, the Pages screen, and the rest of this phase are a partner site\'s. On packout.spot these checks do not apply; what the hub does with a partner\'s logo is in tests/partner-api.php.' );
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
			'display_name' => $name . ' Setupper',
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

$mailed    = array();
$mail_goes = true;
$keep_mail = static function ( $pre, $atts ) use ( &$mailed, &$mail_goes ) {
	$mailed[] = (array) $atts;
	return $mail_goes;
};
add_filter( 'pre_wp_mail', $keep_mail, 99, 2 );

// The hub, played in place: it takes whatever is sent and says what a test told it to.
$origin = Connection::hub();
$played = array(
	'requests' => array(),
	'wants'    => array(),
);
$answer = static function ( $pre, $args, $url ) use ( &$played, $origin ) {
	if ( 0 !== strpos( (string) $url, $origin . '/wp-json/packout/v1' ) ) {
		return $pre;
	}
	$route = (string) strtok( substr( (string) $url, strlen( $origin . Connection::BASE ) ), '?' );
	$query = array();
	parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $query );
	$raw                  = (string) ( $args['body'] ?? '' );
	$played['requests'][] = array(
		'method' => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
		'route'  => $route,
		'query'  => $query,
		'raw'    => $raw,
		'body'   => '/images' === $route ? null : json_decode( $raw, true ),
		'signed' => isset( $args['headers'][ Signing::SIGNATURE ] ),
	);
	$body                 = array( 'result' => 'updated' );
	if ( '/profile' === $route ) {
		$body['images'] = (object) $played['wants'];
	} elseif ( '/images' === $route ) {
		$body = array( 'result' => 'created' );
	} elseif ( '/me' === $route ) {
		$body = array(
			'state'   => 'active',
			'minimum' => '0.1.0',
			'current' => '0.1.0',
		);
	}
	return array(
		'headers'  => array(),
		'body'     => (string) wp_json_encode( $body ),
		'response' => array(
			'code'    => 200,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $answer, 5, 3 );
$sent = static function ( string $method, string $route ) use ( &$played ): array {
	return array_values( array_filter( $played['requests'], static fn( array $one ): bool => $one['method'] === $method && $one['route'] === $route ) );
};

$render = static function ( callable $screen, array $get = array() ): string {
	$before = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Set aside and put back.
	$_GET   = $get + $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The address a screen reads.
	ob_start();
	try {
		$screen();
	} finally {
		$html = (string) ob_get_clean();
		$_GET = $before;
	}
	return $html;
};

$packout_partner_before = null;

try {
	Schema::register();
	// As in the dashboard, where every screen of the plugin's is drawn.
	set_current_screen( 'dashboard' );
	$admin = $user( 'administrator', 'Admin' );
	$coord = $user( 'pos_coordinator', 'Coordinator' );
	$ana   = $user( 'pos_reporter', 'Ana' );
	$site  = Partners::site();
	if ( $site <= 0 ) {
		WP_CLI::error( 'The site has no partner record.' );
	}

	// What the site has now is set aside, so the checks start from a site nobody has set up, and put back at the end.
	foreach ( array( Pages::OPTION, Wizard::OPTION, Health::MAIL, Settings::option(), Connection::OPTION, Connection::KEY_OPTION, Connection::NOTICES, Exchange::PULLED, Hub_Layers::OPTION, Hub_Layers::DATA ) as $option ) {
		$packout_kept[ $option ] = get_option( $option, null );
	}
	foreach ( array( Pages::OPTION, Wizard::OPTION, Health::MAIL, Connection::OPTION, Connection::KEY_OPTION, Connection::NOTICES, Hub_Layers::OPTION, Hub_Layers::DATA ) as $option ) {
		delete_option( $option );
	}
	Settings::save(
		array(
			'report_page'    => 0,
			'signup_page'    => 0,
			'signin_page'    => 0,
			'workspace_page' => 0,
			'account_page'   => 0,
		)
	);
	Outbox::install_tables();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
	$packout_rows = (array) $wpdb->get_results( "SELECT kind, subject, queued, attempts, next_try, last_error FROM {$wpdb->prefix}pos_outbox", ARRAY_A );
	Outbox::clear();
	Outbox::unschedule();
	$partner_post           = get_post( $site );
	$packout_partner_before = array(
		'post'  => array(
			'ID'           => $site,
			'post_title'   => $partner_post->post_title,
			'post_content' => $partner_post->post_content,
			'post_status'  => $partner_post->post_status,
		),
		'meta'  => array(
			'_pos_region'  => get_post_meta( $site, '_pos_region', true ),
			'_pos_website' => get_post_meta( $site, '_pos_website', true ),
		),
		'logo'  => Partners::logo( $site ),
		'cover' => Page_Header::cover( $site ),
		'lead'  => Partners::lead( $site ),
	);
	$front                  = array( get_option( 'show_on_front' ), get_option( 'page_on_front' ), get_option( 'page_for_posts' ), get_option( 'stylesheet' ), get_option( 'template' ), count( wp_get_nav_menus() ), wp_json_encode( get_theme_mod( 'nav_menu_locations' ) ) );

	/*
	 * The templates.
	 */
	WP_CLI::log( 'The page templates' );
	$templates = Pages::templates();
	$check( 'there is a template for each page the plan names', array() === array_diff( array( 'map', 'report', 'organizations', 'calendar', 'impact', 'how', 'sign-up', 'sign-in', 'workspace', 'account' ), array_keys( $templates ) ), array_keys( $templates ) );
	$registry = WP_Block_Type_Registry::get_instance();
	$foreign  = array();
	$empty    = array();
	$walk     = static function ( array $blocks ) use ( &$walk, &$foreign, $registry ): void {
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( '' !== $name && ( ! $registry->is_registered( $name ) || ( 0 !== strpos( $name, 'core/' ) && 0 !== strpos( $name, 'packout/' ) ) ) ) {
				$foreign[] = $name;
			}
			$walk( (array) ( $block['innerBlocks'] ?? array() ) );
		}
	};
	foreach ( array_keys( $templates ) as $key ) {
		$content = Pages::content( $key );
		if ( '' === $content || false !== strpos( $content, '{{' ) ) {
			$empty[] = $key;
		}
		$walk( parse_blocks( $content ) );
		if ( '' !== $templates[ $key ]['block'] && ! Pages::carries( $content, $templates[ $key ]['block'], $templates[ $key ]['view'] ) ) {
			$empty[] = $key . ' lacks ' . $templates[ $key ]['block'];
		}
	}
	$check( 'each has content, with the partner\'s name filled in and its own block', array() === $empty, $empty );
	$check( 'and uses only WordPress\'s blocks and Pack-Out Spot\'s, all of which this site has', array() === $foreign, array_values( array_unique( $foreign ) ) );
	$patterns = WP_Block_Patterns_Registry::get_instance();
	$check( 'each top-level template is also a block pattern', $patterns->is_registered( 'packout/map' ) && $patterns->is_registered( 'packout/how' ) && ! $patterns->is_registered( 'packout/account-personal' ) );

	/*
	 * Making, using, and leaving out.
	 */
	WP_CLI::log( 'Making a page' );
	wp_set_current_user( $coord );
	$refused = Pages::act( 'map', 'create' );
	$check( 'a coordinator who is not an administrator cannot make the site\'s pages', is_wp_error( $refused ) && 'packout_forbidden' === $refused->get_error_code() && 'skipped' === Pages::state( 'map' )['state'] && ! Pages::state( 'map' )['decided'] );
	wp_set_current_user( $admin );
	$check( 'an unknown page is refused', is_wp_error( Pages::act( 'nonsense', 'create' ) ) && is_wp_error( Pages::act( 'map', 'explode' ) ) );

	// Whatever the site already has at these addresses stays where it is.
	$taken           = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Our events ' . $packout_run,
			'post_name'   => 'calendar',
		)
	);
	$packout_posts[] = (int) $taken;
	$own_calendar    = 'calendar' === get_post_field( 'post_name', (int) $taken );

	$map             = Pages::act( 'map', 'create' );
	$packout_posts[] = is_int( $map ) ? $map : 0;
	$state           = Pages::state( 'map' );
	$check( 'an administrator makes the Map page: published, with the map block', is_int( $map ) && 'publish' === get_post_status( $map ) && has_block( 'packout/map', (string) get_post_field( 'post_content', $map ) ), is_wp_error( $map ) ? $map->get_error_message() : $map );
	$check( 'the plugin remembers the template, its version, and what it wrote', 'map' === get_post_meta( (int) $map, Pages::KEY, true ) && get_post_meta( (int) $map, Pages::VERSION, true ) === (string) $templates['map']['version'] && get_post_meta( (int) $map, Pages::HASH, true ) === Pages::hash( (string) get_post_field( 'post_content', (int) $map ) ) );
	$check( 'it stands as unchanged, with its address', 'unchanged' === $state['state'] && (int) $map === $state['id'] && '' !== $state['url'] && $state['has_block'], $state );
	$check( 'making it twice is refused', is_wp_error( Pages::act( 'map', 'create' ) ) && is_wp_error( Pages::create( 'map' ) ) );

	if ( $own_calendar ) {
		$check( 'an address that is taken is noticed', false !== strpos( Pages::clash( 'calendar' ), 'Our events' ), Pages::clash( 'calendar' ) );
		$calendar        = Pages::act( 'calendar', 'create' );
		$packout_posts[] = is_int( $calendar ) ? $calendar : 0;
		$check( 'the new page takes another address and the site\'s own page keeps its own', is_int( $calendar ) && 'pack-out-calendar' === get_post_field( 'post_name', $calendar ) && 'calendar' === get_post_field( 'post_name', (int) $taken ) && 'publish' === get_post_status( (int) $taken ), is_int( $calendar ) ? get_post_field( 'post_name', $calendar ) : $calendar->get_error_message() );
	} else {
		$check( 'an address that is taken is noticed', '' !== Pages::clash( (string) get_post_field( 'post_name', (int) $taken ) ) );
		$check( 'the new page takes another address and the site\'s own page keeps its own', true );
	}
	$check( 'the address of a kind of record counts as taken too', '' !== Pages::clash( (string) get_post_type_object( Schema::TEAM )->rewrite['slug'] ), get_post_type_object( Schema::TEAM )->rewrite );

	$report          = Pages::act( 'report', 'create' );
	$packout_posts[] = is_int( $report ) ? $report : 0;
	$check( 'making the Report page tells the application where reports are taken', is_int( $report ) && (int) Settings::get( 'report_page' ) === $report && Pages::url( 'report' ) === get_permalink( $report ) );
	$part = Pages::act( 'account-personal', 'create' );
	$check( 'a part of My account waits for My account', is_wp_error( $part ) && 'packout_page_parent' === $part->get_error_code() );
	$account         = Pages::act( 'account', 'create' );
	$part            = Pages::act( 'account-personal', 'create' );
	$packout_posts[] = is_int( $account ) ? $account : 0;
	$packout_posts[] = is_int( $part ) ? $part : 0;
	$check( 'and then sits under it, with the block set to that part', is_int( $part ) && is_int( $account ) && (int) get_post_field( 'post_parent', $part ) === $account && Pages::carries( (string) get_post_field( 'post_content', $part ), 'packout/account', 'personal' ) && ! Pages::carries( (string) get_post_field( 'post_content', $part ), 'packout/account', '' ) );
	Profile::forget_pages();
	$check( 'the account\'s own menu finds the part\'s page', false !== strpos( Profile::url( array(), 'personal' ), (string) get_post_field( 'post_name', (int) $part ) ), Profile::url( array(), 'personal' ) );

	WP_CLI::log( 'Using a page the site already has' );
	$mine            = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'What we have done ' . $packout_run,
			'post_content' => '<!-- wp:paragraph --><p>Our own words.</p><!-- /wp:paragraph -->',
		)
	);
	$packout_posts[] = (int) $mine;
	$used            = Pages::act( 'impact', 'use', (int) $mine );
	$state           = Pages::state( 'impact' );
	$check( 'a page of the site\'s own can stand for a template', true === $used && 'yours' === $state['state'] && (int) $mine === $state['id'] );
	$check( 'nothing is written to it, and the plugin keeps no note on it', '<!-- wp:paragraph --><p>Our own words.</p><!-- /wp:paragraph -->' === get_post_field( 'post_content', (int) $mine ) && '' === get_post_meta( (int) $mine, Pages::KEY, true ) );
	$check( 'the screen says it lacks the block', ! $state['has_block'] && false !== strpos( Pages_Screen::meaning( $state, $templates['impact'] ), 'packout/impact' ) );
	wp_update_post(
		array(
			'ID'           => (int) $mine,
			'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:packout/impact /--></div><!-- /wp:group -->',
		)
	);
	$check( 'and stops saying so once the block is there, even inside another block', Pages::state( 'impact' )['has_block'] );
	$check( 'a page cannot be updated, kept, or replaced when it is the site\'s own', is_wp_error( Pages::act( 'impact', 'update' ) ) && is_wp_error( Pages::act( 'impact', 'replace' ) ) && is_wp_error( Pages::act( 'impact', 'keep' ) ) );
	$check( 'something that is not a page cannot be used', is_wp_error( Pages::act( 'how', 'use', $site ) ) && is_wp_error( Pages::act( 'how', 'use', 0 ) ) );
	$skipped = Pages::act( 'how', 'skip' );
	$check( 'a page can be left out, and the choice is remembered', true === $skipped && 'skipped' === Pages::state( 'how' )['state'] && Pages::state( 'how' )['decided'] && ! Pages::state( 'organizations' )['decided'] );
	$check( 'leaving the site\'s own page out forgets it and leaves it as it was', true === Pages::act( 'impact', 'skip' ) && 'skipped' === Pages::state( 'impact' )['state'] && 'publish' === get_post_status( (int) $mine ) );

	/*
	 * An update of the template.
	 */
	WP_CLI::log( 'When a template changes' );
	// A page as an older version of the plugin made it: older words, the older version, and the hash of those words.
	$older = static function ( int $id ): void {
		$old = '<!-- wp:paragraph --><p>The words of an older version.</p><!-- /wp:paragraph -->' . "\n\n" . '<!-- wp:packout/map /-->';
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $old,
			)
		);
		update_post_meta( $id, Pages::VERSION, '0' );
		update_post_meta( $id, Pages::HASH, Pages::hash( (string) get_post_field( 'post_content', $id ) ) );
	};
	$older( (int) $map );
	$state = Pages::state( 'map' );
	$check( 'a page nobody edited says an update is available', 'update' === $state['state'] && $state['newer'], $state );
	$check( 'nothing was written by noticing', false !== strpos( (string) get_post_field( 'post_content', (int) $map ), 'older version' ) );
	$check( 'Update writes the new template', true === Pages::act( 'map', 'update' ) && 'unchanged' === Pages::state( 'map' )['state'] && Pages::hash( Pages::content( 'map' ) ) === Pages::hash( (string) get_post_field( 'post_content', (int) $map ) ) );
	$check( 'and cannot be pressed twice', is_wp_error( Pages::act( 'map', 'update' ) ) );

	$older( (int) $map );
	$edited = '<!-- wp:paragraph --><p>Welcome to our valley. We wrote this ourselves.</p><!-- /wp:paragraph -->' . "\n\n" . '<!-- wp:packout/map /-->';
	wp_update_post(
		array(
			'ID'           => (int) $map,
			'post_content' => $edited,
		)
	);
	$state = Pages::state( 'map' );
	$check( 'a page the site edited says so, and that the template is newer', 'edited' === $state['state'] && $state['newer'], $state );
	$check( 'Update is refused for it, and its words are as the site left them', is_wp_error( Pages::act( 'map', 'update' ) ) && get_post_field( 'post_content', (int) $map ) === $edited );
	$draft           = Pages::act( 'map', 'draft' );
	$packout_posts[] = is_int( $draft ) ? $draft : 0;
	$check( 'a draft copy of the template can be made to compare, and the page is untouched', is_int( $draft ) && 'draft' === get_post_status( $draft ) && Pages::hash( (string) get_post_field( 'post_content', $draft ) ) === Pages::hash( Pages::content( 'map' ) ) && get_post_field( 'post_content', (int) $map ) === $edited && Pages::state( 'map' )['id'] === (int) $map );
	$check( 'Keep mine stops the offer and leaves the page alone', true === Pages::act( 'map', 'keep' ) && 'edited' === Pages::state( 'map' )['state'] && ! Pages::state( 'map' )['newer'] && get_post_field( 'post_content', (int) $map ) === $edited && is_wp_error( Pages::act( 'map', 'keep' ) ) );
	$check( 'Replace writes the template over it', true === Pages::act( 'map', 'replace' ) && 'unchanged' === Pages::state( 'map' )['state'] && false === strpos( (string) get_post_field( 'post_content', (int) $map ), 'our valley' ) );
	$check( 'a page that only changed its line endings is not called edited', Pages::hash( "a\r\nb\n" ) === Pages::hash( "a\nb" ) );

	WP_CLI::log( 'A page that goes missing' );
	wp_trash_post( (int) $map );
	$state = Pages::state( 'map' );
	$check( 'a page in the trash is missing, and the screen says where it is', 'missing' === $state['state'] && $state['trashed'] && 0 === Pages::page( 'map' ) && '' === Pages::url( 'map' ) );
	$check( 'it can be taken out of the trash and is published again', true === Pages::act( 'map', 'restore' ) && 'publish' === get_post_status( (int) $map ) && 'unchanged' === Pages::state( 'map' )['state'], Pages::state( 'map' ) );
	wp_delete_post( (int) $map, true );
	$state = Pages::state( 'map' );
	$check( 'a deleted page is missing and cannot be restored', 'missing' === $state['state'] && ! $state['trashed'] && is_wp_error( Pages::act( 'map', 'restore' ) ) );
	$again           = Pages::act( 'map', 'again' );
	$packout_posts[] = is_int( $again ) ? $again : 0;
	$check( 'it can be made again', is_int( $again ) && $again !== $map && 'unchanged' === Pages::state( 'map' )['state'] );
	$map = (int) $again;

	WP_CLI::log( 'The Pages screen' );
	$html = $render( array( Pages_Screen::class, 'render' ) );
	$rows = preg_match_all( '/data-pos-page="([a-z-]+)" data-pos-state="([a-z]+)"/', $html, $found ) ? array_combine( $found[1], $found[2] ) : array();
	$check( 'lists every template with how it stands', count( $rows ) === count( $templates ) && 'unchanged' === ( $rows['map'] ?? '' ) && 'skipped' === ( $rows['organizations'] ?? '' ), $rows );
	$check( 'says what it never does', false !== strpos( $html, 'never changes your front page' ) );
	$check( 'offers Make for one not made and nothing destructive for one unchanged', 1 === preg_match( '/data-pos-page="organizations".*?name="do" value="create"/s', $html ) && 0 === preg_match( '/data-pos-page="map"[^§]*?name="do" value="replace".*?data-pos-page="report"/s', $html ) );
	wp_set_current_user( $coord );
	$died        = false;
	add_filter(
		'wp_die_handler',
		$handler = static function () use ( &$died ): callable {
			return static function () use ( &$died ): void {
				$died = true;
				throw new RuntimeException( 'died' );
			};
		},
		99
	);
	try {
		$render( array( Pages_Screen::class, 'render' ) );
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	$pages_died = $died;
	$died       = false;
	try {
		$render( array( Wizard::class, 'render' ) );
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	remove_filter( 'wp_die_handler', $handler, 99 );
	while ( ob_get_level() > 1 ) {
		ob_end_clean();
	}
	$check( 'a coordinator who is not an administrator is shown neither the Pages screen nor the setup', $pages_died && $died );
	wp_set_current_user( $admin );

	/*
	 * The wizard.
	 */
	WP_CLI::log( 'The setup wizard' );
	$check( 'has eight steps, and starts at the first', array( 'check', 'partner', 'organization', 'connect', 'pages', 'map', 'people', 'done' ) === array_keys( Wizard::steps() ) && array() === Wizard::standing()['seen'] && 0 === Wizard::standing()['done'] );
	set_current_screen( 'dashboard' );
	$note = $render( array( Wizard::class, 'reminder' ) );
	$check( 'an administrator is told the setup is there', false !== strpos( $note, Wizard::PAGE ) && false !== strpos( $note, 'Not now' ) );
	wp_set_current_user( $coord );
	$check( 'and nobody else is', '' === $render( array( Wizard::class, 'reminder' ) ) );
	$refused = Wizard::perform( 'partner', 'save', array( 'name' => 'Taken over' ), array(), $coord );
	$check( 'only an administrator can take a step', is_wp_error( $refused ) && 'Taken over' !== get_the_title( $site ) && is_wp_error( Wizard::perform( 'nonsense', 'save', array(), array(), $admin ) ) );
	wp_set_current_user( $admin );

	$html = $render( array( Wizard::class, 'render' ) );
	$check( 'the first step lists what the server can do', false !== strpos( $html, 'data-pos-step="check"' ) && 1 === preg_match( '/data-pos-check="permalinks" data-pos-status="good"/', $html ) && false !== strpos( $html, 'data-pos-check="sodium"' ) && false !== strpos( $html, 'data-pos-check="mail"' ), substr( wp_strip_all_tags( $html ), 0, 300 ) );
	$count  = count( $mailed );
	$tested = Wizard::perform( 'check', 'mail', array(), array(), $admin );
	$last   = end( $mailed );
	$check( 'a test email goes to the administrator\'s own address', is_array( $tested ) && 'check' === $tested['step'] && count( $mailed ) === $count + 1 && array( get_userdata( $admin )->user_email ) === (array) $last['to'] );
	$check( 'what is remembered of it is when and whether, never the address', array( 'at', 'ok' ) === array_keys( (array) get_option( Health::MAIL ) ) && 'good' === Health::checks()['mail']['status'] );
	$mail_goes = false;
	$tested    = Wizard::perform( 'check', 'mail', array(), array(), $admin );
	$check( 'a site whose mail is refused is told so', is_wp_error( $tested ) && 'critical' === Health::checks()['mail']['status'] );
	$mail_goes = true;
	Health::test_mail( $admin );
	$next = Wizard::perform( 'check', 'save', array(), array(), $admin );
	$check( 'Continue goes to the next step and marks this one', is_array( $next ) && 'partner' === $next['step'] && array( 'check' ) === Wizard::standing()['seen'] );

	$partner_name = 'Verde Valley Stewards ' . $packout_run;
	$logo         = $file( 'logo.jpg', 200 );
	$next         = Wizard::perform(
		'partner',
		'save',
		array(
			'name'        => 'Verde Valley Stewards ' . $packout_run,
			'description' => 'We look after the river and the roads that reach it.',
			'region'      => 'Verde Valley, Arizona',
			'website'     => 'https://verde.example/',
			'listed'      => true,
		),
		array( 'logo' => $logo ),
		$admin
	);
	$check( 'the partner step names the partner', is_array( $next ) && 'organization' === $next['step'] && get_the_title( $site ) === $partner_name && 'Verde Valley, Arizona' === Partners::field( $site, 'region' ) && 'publish' === get_post_status( $site ), is_wp_error( $next ) ? $next->get_error_message() : '' );
	$check( 'and keeps its logo', Partners::logo( $site ) > 0 && Partners::logo( $site ) !== $packout_partner_before['logo'] );
	$short = Wizard::perform( 'partner', 'save', array( 'name' => 'A' ), array(), $admin );
	$check( 'a name too short is refused and nothing changes', is_wp_error( $short ) && get_the_title( $site ) === $partner_name );
	$check( 'a template that names the partner uses the new name', false !== strpos( Pages::content( 'how' ), 'Verde Valley Stewards ' . $packout_run ) );

	$had  = count( Partners::organizations( $site ) );
	$next = Wizard::perform( 'organization', 'save', array( 'same' => true ), array(), $admin );
	$now  = Partners::organizations( $site );
	if ( 0 === $had ) {
		$made = $now[0] ?? null;
		if ( $made ) {
			$packout_posts[] = $made->ID;
		}
		$check( 'the first organization is made with the partner\'s name', is_array( $next ) && 'connect' === $next['step'] && 1 === count( $now ) && $made && 'Verde Valley Stewards ' . $packout_run === $made->post_title && Teams::lead( $made->ID ) === Partners::lead( $site ), is_wp_error( $next ) ? $next->get_error_message() : count( $now ) );
	} else {
		$check( 'a site that has organizations is not given another', is_array( $next ) && 'connect' === $next['step'] && count( $now ) === $had );
	}
	$again = Wizard::perform( 'organization', 'save', array( 'same' => true ), array(), $admin );
	$check( 'the step makes no second organization', is_array( $again ) && count( Partners::organizations( $site ) ) === count( $now ) );

	$html = $render( array( Wizard::class, 'render' ), array( 'step' => 'connect' ) );
	$check( 'the connect step says what is sent and never is, and sends nothing', false !== strpos( $html, 'What is never sent' ) && false !== strpos( $html, Connection_Screen::PAGE ) && array() === $played['requests'] && ! Connection::connected() );
	$check( 'it names Spots among what is sent', false !== strpos( $html, 'Each Spot, as soon as it is reported' ) && false !== strpos( $html, 'credit a reporter chose' ) );
	$next = Wizard::perform( 'connect', 'save', array(), array(), $admin );
	$check( 'Not now goes on without connecting', is_array( $next ) && 'pages' === $next['step'] && ! Connection::connected() && array() === $played['requests'] );

	$html = $render( array( Wizard::class, 'render' ), array( 'step' => 'pages' ) );
	$check( 'the pages step offers each page not yet made, and shows the ones that are', 1 === preg_match( '/data-pos-page="organizations".*?name="pages\[organizations\]\[do\]" value="create"/s', $html ) && 0 === preg_match( '/name="pages\[map\]\[do\]"/', $html ) && false !== strpos( $html, 'Your front page, your menus, and your theme are not touched' ) );
	$check( 'and says which address a clash would give', ! $own_calendar || false !== strpos( $html, 'pack-out-' ) || Pages::page( 'calendar' ) > 0 );
	$next = Wizard::perform(
		'pages',
		'save',
		array(
			'pages' => array(
				'organizations' => array(
					'do'   => 'create',
					'page' => 0,
				),
				'impact'        => array(
					'do'   => 'use',
					'page' => (int) $mine,
				),
				'sign-up'       => array(
					'do'   => 'create',
					'page' => 0,
				),
				'sign-in'       => array(
					'do'   => 'create',
					'page' => 0,
				),
				'workspace'     => array(
					'do'   => 'create',
					'page' => 0,
				),
				'how'           => array(
					'do'   => 'skip',
					'page' => 0,
				),
				'map'           => array(
					'do'   => 'skip',
					'page' => 0,
				),
				'account-email' => array(
					'do'   => 'create',
					'page' => 0,
				),
			),
		),
		array(),
		$admin
	);
	foreach ( array( 'organizations', 'sign-up', 'sign-in', 'workspace', 'account-email' ) as $key ) {
		$packout_posts[] = Pages::page( $key );
	}
	$states = Pages::states();
	$check( 'the pages step makes, uses, and leaves out as it was told', is_array( $next ) && 'map' === $next['step'] && 'unchanged' === $states['organizations']['state'] && 'yours' === $states['impact']['state'] && 'skipped' === $states['how']['state'] && 'unchanged' === $states['account-email']['state'], is_wp_error( $next ) ? $next->get_error_message() : array_column( $states, 'state', 'key' ) );
	$check( 'a page that already exists is not touched by the step', 'unchanged' === $states['map']['state'] && $map === $states['map']['id'] );
	$check( 'the sign-up, sign-in, and workspace pages are the ones the application uses', Accounts::open() && (int) Settings::get( 'signup_page' ) === Pages::page( 'sign-up' ) && (int) Settings::get( 'signin_page' ) === Pages::page( 'sign-in' ) && (int) Settings::get( 'workspace_page' ) === Pages::page( 'workspace' ) );
	$after = array( get_option( 'show_on_front' ), get_option( 'page_on_front' ), get_option( 'page_for_posts' ), get_option( 'stylesheet' ), get_option( 'template' ), count( wp_get_nav_menus() ), wp_json_encode( get_theme_mod( 'nav_menu_locations' ) ) );
	$check( 'the front page, the menus, and the theme are as they were', $front === $after, array( $front, $after ) );

	$geocode = static fn() => array(
		array(
			'name'   => 'Camp Verde',
			'detail' => 'Yavapai County, Arizona',
			'lat'    => 34.5636,
			'lng'    => -111.8543,
		),
	);
	add_filter( 'packout_spot_geocode', $geocode );
	$found   = Wizard::perform( 'map', 'find', array( 'q' => 'Camp Verde' ), array(), $admin );
	remove_filter( 'packout_spot_geocode', $geocode );
	$check( 'the map step finds a place by name and opens the map there', is_array( $found ) && 'map' === $found['step'] && 'found' === $found['notice'] && 34.5636 === (float) Settings::get( 'map_lat' ) && -111.8543 === (float) Settings::get( 'map_lng' ), is_wp_error( $found ) ? $found->get_error_message() : Settings::get( 'map_lat' ) );
	$nothing = static fn() => array();
	add_filter( 'packout_spot_geocode', $nothing );
	$lost    = Wizard::perform( 'map', 'find', array( 'q' => 'Nowhere at all' ), array(), $admin );
	remove_filter( 'packout_spot_geocode', $nothing );
	$check( 'a name nothing answers to changes nothing', is_wp_error( $lost ) && 34.5636 === (float) Settings::get( 'map_lat' ) );
	$next = Wizard::perform(
		'map',
		'save',
		array(
			'map_lat'          => '34.6',
			'map_lng'          => '-111.9',
			'map_zoom'         => '10',
			'duplicate_radius' => '75',
			'default_category' => 'cleanup-waste',
		),
		array(),
		$admin
	);
	$check( 'and saves the opening view and the duplicate radius', is_array( $next ) && 'people' === $next['step'] && 34.6 === (float) Settings::get( 'map_lat' ) && 75 === (int) Settings::get( 'duplicate_radius' ) && ! Hub_Layers::on() );
	$box   = array_map( 'floatval', explode( ',', Wizard::around( 34.6, -111.9 ) ) );
	$sides = Hub_Layers::sides(
		array(
			'west'  => $box[0],
			'south' => $box[1],
			'east'  => $box[2],
			'north' => $box[3],
		)
	);
	$check( 'the region it would name is about a hundred kilometres across, around the opening view', 4 === count( $box ) && $box[0] < -111.9 && $box[2] > -111.9 && $box[1] < 34.6 && $box[3] > 34.6 && max( $sides ) < 120 && min( $sides ) > 80, array( $box, $sides ) );

	$org    = Partners::organizations( $site )[0]->ID;
	$count  = count( $mailed );
	$invite = 'pos-test-colead-' . $packout_run . '@example.test';
	$next   = Wizard::perform(
		'people',
		'save',
		array(
			'team'   => $org,
			'emails' => $invite . "\nnot an address\n" . $invite,
		),
		array(),
		$admin
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own table.
	$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT role, team_id FROM ' . Invitations::table() . ' WHERE email = %s', $invite ), ARRAY_A );
	$check( 'the people step invites a co-lead by email, once', is_array( $next ) && 'done' === $next['step'] && 1 === count( $rows ) && 'colead' === $rows[0]['role'] && (int) $rows[0]['team_id'] === $org && count( $mailed ) === $count + 1, is_wp_error( $next ) ? $next->get_error_message() : array( $rows, count( $mailed ) - $count ) );
	$stray = Wizard::perform(
		'people',
		'save',
		array(
			'team'   => $site,
			'emails' => 'pos-test-other-' . $packout_run . '@example.test',
		),
		array(),
		$admin
	);
	$check( 'and only into an organization of this site', is_wp_error( $stray ) );

	$html = $render( array( Wizard::class, 'render' ), array( 'step' => 'done' ) );
	$left = Wizard::left();
	$check( 'the last step lists how each part stands', false !== strpos( $html, 'data-pos-step="done"' ) && 'good' === $left['partner']['status'] && 'good' === $left['organization']['status'] && 'good' === $left['map']['status'] && 'good' === $left['pages']['status'] && 'good' === $left['connect']['status'], array_column( $left, 'status', 'id' ) );
	$check( 'and marks the setup finished, so the reminder goes', Wizard::standing()['done'] > 0 && '' === $render( array( Wizard::class, 'reminder' ) ) );
	$steps_html = '';
	foreach ( array_keys( Wizard::steps() ) as $step ) {
		$steps_html .= $render( array( Wizard::class, 'render' ), array( 'step' => $step ) );
	}
	$check( 'every step draws, and none of them offers to change the front page, a menu, or the theme', 8 === substr_count( $steps_html, 'class="packout-setup__body"' ) && false === strpos( $steps_html, 'page_on_front' ) && false === strpos( $steps_html, 'switch_theme' ) );

	// Every sentence a person reads on these screens goes through a translation. Each one is marked on its way, and what is
	// left unmarked is looked at: the site's own names and addresses, and no sentence.
	$mark = static fn( $text ) => "\u{27E6}" . $text . "\u{27E7}";
	foreach ( array( 'gettext_packout-core', 'gettext_with_context_packout-core', 'ngettext_packout-core', 'gettext_packout-partner', 'ngettext_packout-partner' ) as $hook ) {
		add_filter( $hook, $mark );
	}
	$marked = $render( array( Pages_Screen::class, 'render' ) ) . $render( array( Connection_Screen::class, 'render' ) );
	foreach ( array_keys( Wizard::steps() ) as $step ) {
		$marked .= $render( array( Wizard::class, 'render' ), array( 'step' => $step ) );
	}
	foreach ( array( 'gettext_packout-core', 'gettext_with_context_packout-core', 'ngettext_packout-core', 'gettext_packout-partner', 'ngettext_packout-partner' ) as $hook ) {
		remove_filter( $hook, $mark );
	}
	// A list of the site's own pages, and what is in a field, are the site's words.
	$plain = (string) preg_replace( '/<(script|style|select|textarea)\b.*?<\/\1>/s', ' ', $marked );
	preg_match_all( '/(?:placeholder|title|aria-label|alt)="([^"]*)"/', $plain, $attributes );
	$plain = html_entity_decode( wp_strip_all_tags( $plain ) . ' ' . implode( ' | ', $attributes[1] ), ENT_QUOTES );
	$plain = str_replace( array( get_the_title( $site ), (string) get_bloginfo( 'name' ) ), ' ', $plain );
	for ( $pass = 0; $pass < 3; $pass++ ) {
		$plain = (string) preg_replace( '/\x{27E6}[^\x{27E6}\x{27E7}]*\x{27E7}/u', ' ', $plain );
	}
	preg_match_all( '/[A-Za-z][A-Za-z\'’-]+(?: [A-Za-z][A-Za-z\'’-]+){2,}/', $plain, $sentences );
	$check( 'every sentence on the setup, the Pages screen, and the Connection screen can be translated', substr_count( $marked, "\u{27E6}" ) > 150 && array() === $sentences[0], array( substr_count( $marked, "\u{27E6}" ), array_slice( $sentences[0], 0, 8 ) ) );

	delete_option( Wizard::OPTION );
	Wizard::perform( 'done', 'away', array(), array(), $admin );
	$check( 'Not now puts the reminder away without finishing', Wizard::standing()['away'] && 0 === Wizard::standing()['done'] && '' === $render( array( Wizard::class, 'reminder' ) ) );

	/*
	 * The checks.
	 */
	WP_CLI::log( 'How the site stands' );
	// The Connection screen, drawn above, asks the hub for the terms when it has no copy. The checks ask nothing.
	$played['requests'] = array();
	$checks             = Health::checks();
	$check( 'the checks cover the server, the pages, and the connection', array() === array_diff( array( 'https', 'permalinks', 'cron', 'sodium', 'webp', 'memory', 'mail', 'partner', 'pages', 'connection' ), array_keys( $checks ) ), array_keys( $checks ) );
	$check( 'each says good, recommended, or critical, in a sentence', array() === array_filter( $checks, static fn( array $row ): bool => ! in_array( $row['status'], array( 'good', 'recommended', 'critical' ), true ) || '' === $row['detail'] || '' === $row['label'] ) );
	$check( 'a site that is not connected is told that is fine', 'good' === $checks['connection']['status'] && false !== strpos( $checks['connection']['detail'], 'not connected' ) && ! isset( $checks['queue'] ) );
	$check( 'no check made a request', array() === $played['requests'], array_map( static fn( array $one ): string => $one['method'] . ' ' . $one['route'], $played['requests'] ) );
	wp_trash_post( Pages::page( 'organizations' ) );
	$check( 'a page that went missing shows in the checks, by name', 'recommended' === Health::checks()['pages']['status'] && false !== strpos( Health::checks()['pages']['detail'], $templates['organizations']['title'] ) );
	Pages::act( 'organizations', 'restore' );
	$tests  = Health::tests( array( 'direct' => array() ) );
	$result = Health::result( 'permalinks' );
	$check( 'Site Health is given each check as a test of its own', isset( $tests['direct']['packout_permalinks'], $tests['direct']['packout_connection'] ) && 'good' === $result['status'] && 'packout_permalinks' === $result['test'] && is_callable( $tests['direct']['packout_permalinks']['test'] ) && call_user_func( $tests['direct']['packout_permalinks']['test'] ) === $result );
	$info = Health::info( array() );
	$flat = wp_json_encode( $info );
	$check( 'the Info tab has a section that says "No key" and names no address of a person', isset( $info['packout']['fields'] ) && false !== strpos( $flat, 'No key' ) && false === strpos( $flat, '@' ), $flat );

	/*
	 * Connected.
	 */
	WP_CLI::log( 'A connected site' );
	$pair = Signing::keypair();
	update_option( Connection::KEY_OPTION, $pair, false );
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
			'partner_url' => $origin . '/partner/verde-valley-stewards/',
		)
	);
	Outbox::clear();
	if ( ! Connection::sending() ) {
		WP_CLI::error( 'The site could not be put in a connected state.' );
	}
	$checks = Health::checks();
	$check( 'the checks say it is connected, and that the sending task is not scheduled', 'good' === $checks['connection']['status'] && 'critical' === $checks['queue']['status'], array( $checks['connection'], $checks['queue'] ?? null ) );
	Outbox::schedule();
	$check( 'and are good once it is', 'good' === Health::checks()['queue']['status'], Health::checks()['queue'] );
	$flat = wp_json_encode( Health::info( array() ) );
	$check( 'the Info tab says a key is present and shows neither half of it', false !== strpos( $flat, 'Present (never shown)' ) && false === strpos( $flat, (string) $pair['secret'] ) && false === strpos( $flat, (string) $pair['public'] ) );

	// A row that has failed three times.
	Outbox::enqueue( 'stats', 'stats' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}pos_outbox SET attempts = %d, last_error = %s, next_try = %d", Health::TRIES, 'packout_hub_unreachable', time() + HOUR_IN_SECONDS ) );
	$queue = Health::checks()['queue'];
	$check( 'a send that keeps failing is critical, with the reason', 'critical' === $queue['status'] && false !== strpos( $queue['detail'], 'packout_hub_unreachable' ) && 'critical' === Health::worst(), $queue );
	wp_set_current_user( $admin );
	$over = $render( array( Admin::class, 'overview' ) );
	$check( 'the site\'s overview says so above the queue, with the reason and a way to the Connection screen', false !== strpos( $over, 'data-pos-sync="queue"' ) && false !== strpos( $over, 'packout_hub_unreachable' ) && false !== strpos( $over, 'page=' . Connection_Screen::PAGE ) && strpos( $over, 'data-pos-sync="queue"' ) < strpos( $over, 'packout-overview__waiting' ) );
	wp_set_current_user( $coord );
	$over = $render( array( Admin::class, 'overview' ) );
	$told = substr( $over, (int) strpos( $over, 'data-pos-sync="queue"' ), 1200 );
	$check( 'a coordinator is told too, and to ask an administrator, with no link to a screen that is not theirs', false !== strpos( $over, 'data-pos-sync="queue"' ) && false !== strpos( $told, 'An administrator of this site' ) && false === strpos( substr( $told, 0, (int) strpos( $told, '</div>' ) ), 'page=' . Connection_Screen::PAGE ), substr( $told, 0, 400 ) );
	wp_set_current_user( $admin );
	$check( 'Site Health shows it as a critical test that links to the Connection screen', 'critical' === Health::result( 'queue' )['status'] && false !== strpos( Health::result( 'queue' )['actions'], Connection_Screen::PAGE ) );
	Outbox::clear();
	$check( 'with nothing failing the overview says nothing about sending', false === strpos( $render( array( Admin::class, 'overview' ) ), 'data-pos-sync' ) );

	update_option(
		Hub_Layers::OPTION,
		array(
			'on'  => true,
			'box' => '-112.4,34.2,-111.4,35',
		),
		false
	);
	update_option(
		Hub_Layers::DATA,
		array(
			'fetched' => time() - Health::STALE - 60,
			'spots'   => array(),
		),
		false
	);
	$layers = Health::checks()['layers'] ?? array();
	$check( 'a copy of the hub\'s map that has gone stale is said to be', 'recommended' === ( $layers['status'] ?? '' ) && false !== strpos( (string) ( $layers['detail'] ?? '' ), 'out of date' ), $layers );
	update_option( Hub_Layers::DATA, array( 'fetched' => time() - 60 ), false );
	$check( 'and a fresh one is good', 'good' === ( Health::checks()['layers']['status'] ?? '' ) );
	delete_option( Hub_Layers::OPTION );
	delete_option( Hub_Layers::DATA );

	WP_CLI::log( 'The partner badge' );
	$badge = do_blocks( '<!-- wp:packout/partner-badge /-->' );
	$check( 'a connected, approved site shows the badge, linked to its page on packout.spot', false !== strpos( $badge, 'pos-badge' ) && false !== strpos( $badge, esc_url( $origin . '/partner/verde-valley-stewards/' ) ) && false !== strpos( $badge, 'A partner of packout.spot' ), $badge );
	$with = do_blocks( '<!-- wp:packout/partner-badge {"showTotals":true} /-->' );
	$check( 'asked for totals with nothing counted, it adds no line', false === strpos( $badge, 'pos-badge__totals' ) && ( 0 === (int) Impact::totals( array( 'visible' => true ) )['outputs']['spots'] ) === ( false === strpos( $with, 'pos-badge__totals' ) ) );
	$cleaned         = wp_insert_post(
		array(
			'post_type'   => Schema::SPOT,
			'post_status' => 'publish',
			'post_author' => $coord,
			'post_title'  => 'Cans at the fire ring ' . $packout_run,
		)
	);
	$packout_posts[] = (int) $cleaned;
	update_post_meta( (int) $cleaned, '_pos_lat', '34.62' );
	update_post_meta( (int) $cleaned, '_pos_lng', '-111.87' );
	update_post_meta( (int) $cleaned, '_pos_status', 'spotted' );
	update_post_meta( (int) $cleaned, '_pos_category', 'cleanup-waste' );
	$had    = (int) Impact::totals( array( 'visible' => true ) )['outputs']['spots'];
	$record = Impact::save_cleanup(
		(int) $cleaned,
		array(
			'narrative' => 'Bagged the cans.',
			'hours'     => 2,
			'people'    => 1,
		),
		$coord
	);
	if ( is_int( $record ) ) {
		$packout_posts[] = $record;
		Impact::verify( $record, $coord );
		Impact::set_visible( $record, true, $coord );
	}
	Impact::flush();
	$with = do_blocks( '<!-- wp:packout/partner-badge {"showTotals":true} /-->' );
	$check( 'with a cleanup counted publicly, the line gives the Spots cleaned and the verified hours', is_int( $record ) && false !== strpos( $with, 'pos-badge__totals' ) && false !== strpos( $with, number_format_i18n( $had + 1 ) . ' Spot' ) && false !== strpos( $with, 'verified hours' ), wp_strip_all_tags( $with ) );
	$check( 'and the plain badge still has none', false === strpos( do_blocks( '<!-- wp:packout/partner-badge /-->' ), 'pos-badge__totals' ) );
	Connection::remember(
		array(
			'state'   => 'active',
			'minimum' => '99.0.0',
		)
	);
	$old = Health::checks()['connection'];
	$check( 'a plugin older than packout.spot accepts is critical, and says which version', 'critical' === $old['status'] && false !== strpos( $old['detail'], '99.0.0' ), $old );
	Connection::remember( array( 'minimum' => '' ) );
	Connection::remember( array( 'state' => 'suspended' ) );
	$check( 'a suspended site does not', '' === trim( do_blocks( '<!-- wp:packout/partner-badge /-->' ) ) );
	Connection::remember( array( 'state' => 'pending' ) );
	$check( 'nor one that waits for approval', '' === trim( do_blocks( '<!-- wp:packout/partner-badge /-->' ) ) );
	Connection::remember( array( 'state' => 'active' ) );

	WP_CLI::log( 'The logo and the cover' );
	$played['requests'] = array();
	Outbox::clear();
	$token = Connection::image_token( 'logo' );
	$check(
		'the profile names the logo by a number and says there is no cover',
		ctype_digit( $token ) && Partners::logo( $site ) === (int) $token && '' === Connection::image_token( 'cover' ) && array(
			'logo'  => $token,
			'cover' => '',
		) === Connection::profile()['images']
	);
	$check( 'and is what the contract says a profile is', '' === Contract::fault( 'profile', Connection::profile() ), Contract::fault( 'profile', Connection::profile() ) );
	$played['wants'] = array( 'logo' => $token );
	Outbox::enqueue( 'profile', 'profile' );
	Outbox::run( true );
	Outbox::run( true );
	$images = $sent( 'POST', '/images' );
	$check( 'the hub asks for the logo once, in its answer to the profile', 1 === count( $sent( 'PUT', '/profile' ) ) && 1 === count( $images ), array( count( $sent( 'PUT', '/profile' ) ), count( $images ) ) );
	$check(
		'the logo goes as the body of a signed request that says what it is',
		1 === count( $images ) && $images[0]['signed'] && array(
			'of'    => 'listing',
			'id'    => 'logo',
			'photo' => $token,
		) === array_intersect_key( $images[0]['query'], array_flip( array( 'of', 'id', 'photo' ) ) ) && strlen( $images[0]['raw'] ) > 500 && (string) file_get_contents( (string) get_attached_file( (int) $token ) ) === $images[0]['raw'], // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the test's own.
		$images ? $images[0]['query'] : 'none sent'
	);
	$check( 'and the row is gone', ! in_array( 'listing:logo:' . $token, array_column( Outbox::rows(), 'subject' ), true ) );
	$played['requests'] = array();
	$cover              = Partners::set_cover( $site, $file( 'cover.jpg', 40 ), $admin );
	$check( 'a new cover queues the profile again', is_int( $cover ) && $cover > 0 && in_array( 'profile', array_column( Outbox::rows(), 'kind' ), true ) && Connection::image_token( 'cover' ) === (string) $cover );
	// The hub asks for a cover the site replaced in the meantime: the old one is not sent.
	$played['wants'] = array( 'cover' => (string) ( $cover + 100000 ) );
	Outbox::run( true );
	$check( 'an image the site no longer has is not queued', array() === array_filter( array_column( Outbox::rows(), 'subject' ), static fn( string $subject ): bool => 0 === strpos( $subject, 'listing:' ) ), array_column( Outbox::rows(), 'subject' ) );
	$check( 'and a file other than the one named cannot be asked for', '' === Connection::image_file( 'cover', (string) Partners::logo( $site ) ) && '' === Connection::image_file( 'spot', $token ) && '' !== Connection::image_file( 'cover', (string) $cover ) );
	Partners::set_cover( $site, null, $admin );
	Partners::remove_logo( $site, $admin );
	$check(
		'removing both says so in the next profile',
		array(
			'logo'  => '',
			'cover' => '',
		) === Connection::profile()['images']
	);
	$told = wp_json_encode( array_column( $played['requests'], 'body' ) );
	$check( 'nothing sent in all of this names a person', false === strpos( (string) $told, '@' ) && false === strpos( (string) $told, 'Setupper' ) );
	Outbox::clear();

	/*
	 * A person's data.
	 */
	WP_CLI::log( 'A person\'s data' );
	$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	$check( 'the plugin is one of WordPress\'s exporters and erasers', isset( $exporters['packout'], $erasers['packout'] ) && is_callable( $exporters['packout']['callback'] ) && is_callable( $erasers['packout']['callback'] ) );
	$policy = Privacy::policy_text();
	$check( 'the text for the privacy policy says what is kept, what is sent to packout.spot, and names the map search', false !== strpos( $policy, 'What is kept about you' ) && false !== strpos( $policy, 'packout.spot' ) && false !== strpos( $policy, 'Nominatim' ) && false !== strpos( $policy, 'the name you asked to be shown' ) );

	wp_set_current_user( $ana );
	$email = get_userdata( $ana )->user_email;
	Teams::add_member( $org, $email );
	Profile::save_about( $ana, array( 'about' => 'I walk the river road most mornings.' ) );
	Profile::save_contact(
		$ana,
		array(
			'phone'       => '928-555-0144',
			'share_phone' => array( $org ),
		)
	);
	$spot            = wp_insert_post(
		array(
			'post_type'    => Schema::SPOT,
			'post_status'  => 'publish',
			'post_author'  => $ana,
			'post_title'   => 'Tires at the river crossing ' . $packout_run,
			'post_content' => 'Six tires below the crossing.',
		)
	);
	$packout_posts[] = (int) $spot;
	update_post_meta( (int) $spot, '_pos_lat', '34.61' );
	update_post_meta( (int) $spot, '_pos_lng', '-111.88' );
	update_post_meta( (int) $spot, '_pos_status', 'spotted' );
	update_post_meta( (int) $spot, '_pos_category', 'cleanup-waste' );
	Spots::set_credit( (int) $spot, 0, true );
	$note   = Updates::note( (int) $spot, $ana, 'Came back Tuesday. Still there. Bring a truck.', false );
	$public = Updates::note( (int) $spot, $ana, 'Two more tires since last week.', true );
	$check( 'a person with a profile, a Spot, a private note, and a public update', is_int( $note ) && is_int( $public ) && '928-555-0144' === Profile::get( $ana )['phone'] && Spots::credit( (int) $spot )['named'] && 'person' === Spots::reported_by( get_post( (int) $spot ) )['kind'], array( is_wp_error( $note ) ? $note->get_error_message() : '', is_wp_error( $public ) ? $public->get_error_message() : '' ) );
	Teams::invite( $org, 'pos-test-friend-' . $packout_run . '@example.test', $admin, array( 'role' => 'member' ) );

	wp_set_current_user( $admin );
	$export = Privacy::export( $email );
	$flat   = (string) wp_json_encode( $export['data'] );
	$groups = array_values( array_unique( array_column( $export['data'], 'group_id' ) ) );
	$check( 'the export has their profile, organizations, Spots, and notes', $export['done'] && array() === array_diff( array( 'packout-profile', 'packout-memberships', 'packout-spots', 'packout-updates' ), $groups ), $groups );
	$check( 'with the words they wrote and the number they gave', false !== strpos( $flat, '928-555-0144' ) && false !== strpos( $flat, 'I walk the river road' ) && false !== strpos( $flat, 'Bring a truck' ) && false !== strpos( $flat, 'Tires at the river crossing' ) );
	$other = (string) wp_json_encode( Privacy::export( get_userdata( $coord )->user_email )['data'] );
	$check( 'and nobody else\'s export has any of it', false === strpos( $other, '928-555-0144' ) && false === strpos( $other, 'Bring a truck' ) && false === strpos( $other, 'Tires at the river crossing' ) );
	$friend = Privacy::export( 'pos-test-friend-' . $packout_run . '@example.test' );
	$check( 'a person with no account is given the invitations sent to their address', array( 'packout-invitations' ) === array_values( array_unique( array_column( $friend['data'], 'group_id' ) ) ) );

	$played['requests'] = array();
	Outbox::clear();
	$erased = Privacy::erase( $email );
	clean_post_cache( (int) $spot );
	$check( 'erasing removes the profile, with the phone number and who it was shared with', $erased['done'] && $erased['items_removed'] && '' === Profile::get( $ana )['phone'] && '' === Profile::get( $ana )['about'] && ! metadata_exists( 'user', $ana, Profile::META ) );
	$check( 'the private note is deleted', null === get_post( (int) $note ) );
	$check( 'the Spot is kept and names nobody', 'publish' === get_post_status( (int) $spot ) && 0 === (int) get_post_field( 'post_author', (int) $spot ) && ! Spots::credit( (int) $spot )['named'] && 'person' !== Spots::reported_by( get_post( (int) $spot ) )['kind'] && false === strpos( Spots::reported_by( get_post( (int) $spot ) )['label'], 'Ana' ) && '' === get_post_meta( (int) $spot, '_pos_credit_named', true ), Spots::reported_by( get_post( (int) $spot ) ) );
	$check( 'the public update is kept without their name', 'publish' === get_post_status( (int) $public ) && 0 === (int) get_post_field( 'post_author', (int) $public ) );
	$check( 'the Spot is queued for packout.spot, so its copy there loses the name', in_array( (string) $spot, array_column( Outbox::rows(), 'subject' ), true ), Outbox::rows() );
	$check( 'the answer says what was kept and why the account is still there', $erased['items_retained'] && 2 <= count( $erased['messages'] ) && false !== strpos( implode( ' ', $erased['messages'] ), 'no longer' ) && false !== strpos( implode( ' ', $erased['messages'] ), 'still a member' ), $erased['messages'] );
	$after = (string) wp_json_encode( Privacy::export( $email )['data'] );
	$check( 'an export afterwards has none of it', false === strpos( $after, '928-555-0144' ) && false === strpos( $after, 'Bring a truck' ) && false === strpos( $after, 'Tires at the river crossing' ) );
	$gone = Privacy::erase( 'pos-test-friend-' . $packout_run . '@example.test' );
	$check( 'an invitation is removed with the address it was sent to', $gone['items_removed'] && array() === Privacy::export( 'pos-test-friend-' . $packout_run . '@example.test' )['data'] );
	$check( 'erasing an address the site has never seen does nothing and says so', ! Privacy::erase( 'pos-test-nobody-' . $packout_run . '@example.test' )['items_removed'] );
	$check( 'their account is still there and still in the organization, as the answer said', get_userdata( $ana ) instanceof WP_User && isset( Profile::teams( $ana )[ $org ] ) && Teams::lead( $org ) > 0 );
} finally {
	remove_filter( 'pre_wp_mail', $keep_mail, 99 );
	remove_filter( 'pre_http_request', $answer, 5 );
	wp_set_current_user( $packout_users[0] ?? 0 );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own tables, and the run's own rows.
	$invited = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Invitations::table() . ' WHERE email LIKE %s', '%' . $wpdb->esc_like( '-' . $packout_run . '@example.test' ) ) );
	foreach ( $invited as $row ) {
		$wpdb->delete( Invitations::events_table(), array( 'invitation_id' => (int) $row ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$wpdb->delete( Invitations::table(), array( 'id' => (int) $row ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
	}

	if ( is_array( $packout_partner_before ) ) {
		$site = (int) $packout_partner_before['post']['ID'];
		foreach ( array( Partners::logo( $site ), Page_Header::cover( $site ) ) as $image ) {
			if ( $image > 0 && ! in_array( $image, array( $packout_partner_before['logo'], $packout_partner_before['cover'] ), true ) ) {
				wp_delete_attachment( $image, true );
			}
		}
		wp_update_post( wp_slash( $packout_partner_before['post'] ) );
		foreach ( $packout_partner_before['meta'] as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $site, $key );
			} else {
				update_post_meta( $site, $key, wp_slash( $value ) );
			}
		}
		foreach ( array(
			'_pos_logo'  => $packout_partner_before['logo'],
			'_pos_cover' => $packout_partner_before['cover'],
		) as $key => $image ) {
			if ( $image > 0 ) {
				update_post_meta( $site, $key, $image );
			} else {
				delete_post_meta( $site, $key );
			}
		}
	}

	foreach ( array_values( array_unique( array_filter( $packout_posts, static fn( $id ): bool => is_int( $id ) && $id > 0 ) ) ) as $post_id ) {
		foreach ( get_posts(
			array(
				'post_type'   => array( 'attachment', Updates::TYPE ),
				'post_status' => array( 'inherit', 'publish', 'private' ),
				'post_parent' => $post_id,
				'numberposts' => 50,
				'fields'      => 'ids',
			)
		) as $child ) {
			if ( 'attachment' === get_post_type( (int) $child ) ) {
				wp_delete_attachment( (int) $child, true );
			} else {
				wp_delete_post( (int) $child, true );
			}
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
	foreach ( $packout_users as $user_id ) {
		wp_delete_user( (int) $user_id );
	}
	foreach ( $packout_files as $path ) {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
	foreach ( $packout_kept as $option => $value ) {
		if ( null === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value, Connection::OPTION === $option );
		}
	}
	Profile::forget_pages();
	delete_transient( 'packout_partner_terms' );
	delete_transient( 'packout_outbox_lock' );
	Impact::flush();
	Overview::forget();
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

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( count( $packout_failures ) . ' checks failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
