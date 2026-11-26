<?php
/**
 * What a partner site is: one partner, its own addresses, and none of what packout.spot alone does.
 *
 * The shared checks in tests/core leave out what differs on a partner site
 * and say so. These are the checks of that difference: what the plugin
 * installs, the site's one partner, the acts that answer "that is done on
 * packout.spot", the partner's program, the overview of the site and of one
 * organization, clearing a Spot, the export, and the choice of addresses.
 *
 * Run inside WordPress, with the plugin active:
 *
 *     wp eval-file tests/site.php
 *
 * The checks create their own users and records and delete them. They put
 * the site's partner and its addresses back as they were.
 *
 * @package PackOut_Partner
 */

use PackOut\Core\App\App;
use PackOut\Core\App\Blocks;
use PackOut\Core\App\Campaigns;
use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Connection;
use PackOut\Core\App\Connection_Screen;
use PackOut\Core\App\Export;
use PackOut\Core\App\Features;
use PackOut\Core\App\Invitations;
use PackOut\Core\App\Outbox;
use PackOut\Core\App\Overview;
use PackOut\Core\App\Overview_Page;
use PackOut\Core\App\Partners;
use PackOut\Core\App\Places;
use PackOut\Core\App\Programs;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Teams;
use PackOut\Core\Capability_Map;
use PackOut\Partner\Addresses;
use PackOut\Partner\Installer;
use PackOut\Partner\Settings;

require __DIR__ . '/support.php';

$packout_run   = strtolower( wp_generate_password( 6, false ) );
$packout_posts = array();
$packout_users = array();
$packout_bases = Settings::get( 'bases' );
$packout_old   = get_option( Addresses::OLD, array() );
$packout_site  = array();

$code = static fn( $thing ): string => is_wp_error( $thing ) ? (string) $thing->get_error_code() : '';
$user = static function ( string $role, string $name ) use ( &$packout_users, $packout_run ): int {
	$login = 'pos-test-' . sanitize_key( $name ) . '-' . $packout_run;
	$id    = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => $login . '@example.test',
			'display_name' => $name,
			'role'         => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	$packout_users[] = (int) $id;
	return (int) $id;
};

// A page of the site over HTTP, as a visitor's browser asks for it.
$home     = untrailingslashit( home_url() );
$host     = (string) preg_replace( '#^https?://#', '', $home );
$loopback = untrailingslashit( (string) ( getenv( 'PACKOUT_LOOPBACK' ) ? getenv( 'PACKOUT_LOOPBACK' ) : $home ) );
$fetch    = static function ( string $url ) use ( $home, $host, $loopback ): array {
	$response = wp_remote_get(
		$loopback . substr( $url, strlen( $home ) ),
		array(
			'headers'     => array( 'Host' => $host ),
			'redirection' => 0,
			'timeout'     => 30,
		)
	);
	if ( is_wp_error( $response ) ) {
		return array( 0, $response->get_error_message(), '' );
	}
	return array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_header( $response, 'location' ), (string) wp_remote_retrieve_body( $response ) );
};

// Once an address has changed, the next request registers the record types with it and writes the rules. That
// request is made here, and this one registers the types again so that it builds the same links.
$settle = static function () use ( $fetch ): void {
	$fetch( home_url( '/' ) );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( Addresses::FLUSH, 'options' );
	Schema::types();
};

try {
	/* ---------- What the plugin installs ---------- */

	WP_CLI::log( 'What the plugin installs' );
	global $wpdb;
	packout_partner_check( 'the version that was installed is this one', PACKOUT_PARTNER_VERSION === get_option( Installer::VERSION_OPTION ), get_option( Installer::VERSION_OPTION ) );
	packout_partner_check( 'the three roles exist', null !== get_role( 'pos_reporter' ) && null !== get_role( 'pos_lead' ) && null !== get_role( 'pos_coordinator' ) );
	packout_partner_check( 'an administrator may do what a coordinator does', Capabilities::can( Capabilities::MODERATE, (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One short query.
	$terms = get_terms(
		array(
			'taxonomy'   => Schema::CATEGORY,
			'hide_empty' => false,
			'fields'     => 'slugs',
		)
	);
	packout_partner_check( 'every category of Spot is a term', ! is_wp_error( $terms ) && array() === array_diff( array_keys( Schema::categories() ), $terms ), $terms );
	$tables = array( Invitations::table(), Invitations::events_table() );
	$found  = array_filter( $tables, static fn( $table ) => $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Whether a table exists.
	packout_partner_check( 'the two invitation tables exist', array_values( $found ) === $tables, $found );
	$tables = array( $wpdb->prefix . 'pos_outbox', $wpdb->prefix . 'pos_sync_log' );
	$found  = array_filter( $tables, static fn( $table ) => $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Whether a table exists.
	packout_partner_check( 'and so do the two tables of the connection: what is waiting to be sent, and the log of what was', array_values( $found ) === $tables, $found );
	packout_partner_check( 'a site that was never connected has no key and no timer, and sends nothing', Connection::connected() || ( null === Connection::key() && false === wp_next_scheduled( Outbox::RUN ) && false === wp_next_scheduled( Outbox::NIGHTLY ) && ! Connection::sending() ) );
	$timers = array( wp_next_scheduled( Outbox::RUN ), wp_next_scheduled( Outbox::NIGHTLY ) );
	wp_schedule_single_event( time() + HOUR_IN_SECONDS, Outbox::SOON );
	if ( ! $timers[0] ) {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, Outbox::RUN );
	}
	Installer::deactivate();
	packout_partner_check( 'deactivating the plugin stops the timers that send', false === wp_next_scheduled( Outbox::RUN ) && false === wp_next_scheduled( Outbox::SOON ) && false === wp_next_scheduled( Outbox::NIGHTLY ) );
	// A connected site's timers start again with its next page.
	Outbox::schedule();
	$menu = static function (): array {
		global $submenu;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$before = $submenu;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The menu is built here to be read, and put back.
		$submenu = array();
		Connection_Screen::menu();
		$items = wp_list_pluck( $submenu['packout-spot'] ?? array(), 2 );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Put back.
		$submenu = $before;
		return $items;
	};
	wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One short query.
	packout_partner_check( 'the dashboard has a Connection screen for an administrator', in_array( Connection_Screen::PAGE, $menu(), true ), $menu() );
	wp_set_current_user( 0 );
	packout_partner_check( 'and for nobody else', ! in_array( Connection_Screen::PAGE, $menu(), true ) && is_wp_error( Connection_Screen::perform( 'send', '', false, false, 0 ) ), $menu() );
	packout_partner_check(
		'installing again changes nothing',
		( static function (): bool {
			$before = array( Partners::site(), get_option( Installer::VERSION_OPTION ) );
			Installer::maybe_install();
			return array( Partners::site(), get_option( Installer::VERSION_OPTION ) ) === $before;
		} )()
	);
	packout_partner_check( 'the plugin is refused on a whole network and beside the hub\'s plugin', '' !== Installer::refusal( true, false ) && '' !== Installer::refusal( false, true ) && '' === Installer::refusal( false, false ) );

	/* ---------- The site's one partner ---------- */

	WP_CLI::log( 'The site\'s one partner' );
	$partner = Partners::site();
	$post    = get_post( $partner );
	packout_partner_check( 'this site is not packout.spot', ! App::is_hub() );
	packout_partner_check( 'the site has one partner', $partner > 0 && $post instanceof WP_Post && Schema::PARTNER === $post->post_type && 1 === count( Partners::all() ), count( Partners::all() ) );
	if ( $partner <= 0 ) {
		WP_CLI::error( 'The site has no partner. Nothing else can be checked.' );
	}
	$packout_site = array(
		'lead'   => Partners::lead( $partner ),
		'listed' => 'publish' === $post->post_status,
	);
	packout_partner_check( 'it is active, and nobody approved it', Partners::active( $partner ) && Partners::allows( $partner, 'organization' ) && Partners::allows( $partner, 'program' ) );
	packout_partner_check( 'an administrator leads it', user_can( Partners::lead( $partner ), 'manage_options' ), Partners::lead( $partner ) );
	packout_partner_check( 'it has a name', mb_strlen( $post->post_title ) >= 3, $post->post_title );

	$coordinator = $user( 'pos_coordinator', 'Coordinator Site' );
	$ana         = $user( 'pos_lead', 'Ana Site' );
	$bob         = $user( 'pos_lead', 'Bob Site' );
	$mem         = $user( 'pos_reporter', 'Mem Site' );

	$org   = static function ( string $name, int $lead ) use ( &$packout_posts, $coordinator, $packout_run ): int {
		$id = Teams::save(
			0,
			array(
				'name'   => $name . ' ' . $packout_run,
				'lead'   => $lead,
				'listed' => true,
			),
			$coordinator
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( 'Organization: ' . $id->get_error_message() );
		}
		$packout_posts[] = (int) $id;
		return (int) $id;
	};
	$alpha = $org( 'Sitetest Alpha', $ana );
	$beta  = $org( 'Sitetest Beta', $bob );
	Teams::add_member( $alpha, get_userdata( $mem )->user_login );

	packout_partner_check( 'every organization on the site is the partner\'s, with nothing asked or answered', Partners::of_team( $alpha ) === $partner && Partners::of_team( $beta ) === $partner && array() === array_diff( array( $alpha, $beta ), Partners::organization_ids( $partner ) ) );
	packout_partner_check( 'a record that is not an organization has no partner', 0 === Partners::of_team( $partner ) );

	$renamed = Partners::make_site( $coordinator, array( 'name' => 'Sitetest Council ' . $packout_run ) );
	packout_partner_check( 'a coordinator changes the site\'s partner, and it stays the one record', $renamed === $partner && get_post( $partner )->post_title === 'Sitetest Council ' . $packout_run && 1 === count( Partners::all() ), $code( $renamed ) );
	packout_partner_check( 'a lead does not', is_wp_error( Partners::make_site( $ana, array( 'name' => 'Sitetest Mine ' . $packout_run ) ) ) );
	Partners::make_site( $coordinator, array( 'name' => $post->post_title ) );

	/* ---------- What is done on packout.spot ---------- */

	WP_CLI::log( 'What is done on packout.spot' );
	$refusals = array(
		'proposing a partner'             => Partners::propose( $ana, array( 'name' => 'Sitetest Second ' . $packout_run ) ),
		'creating a second partner'       => Partners::save( 0, array( 'name' => 'Sitetest Third ' . $packout_run, 'lead' => $ana ), $coordinator ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One short record.
		'approving a partner'             => Partners::approve( $partner, $coordinator ),
		'declining a partner'             => Partners::decline( $partner, 'Sitetest.', $coordinator ),
		'suspending a partner'            => Partners::suspend( $partner, $coordinator, 'Sitetest.' ),
		'lifting a suspension'            => Partners::lift( $partner, $coordinator ),
		'asking to join a partner'        => Partners::ask( $alpha, $partner, $ana ),
		'answering that'                  => Partners::answer( $alpha, $partner, true, $coordinator ),
		'leaving a partner'               => Partners::release( $alpha, $ana ),
		'asking to adopt a Place'         => Places::request_adoption( 1, $alpha, $ana ),
		'answering a request to adopt it' => Places::decide_request( 1, 'x', $coordinator ),
	);
	foreach ( $refusals as $act => $answer ) {
		packout_partner_check( $act . ' is answered "that is done on packout.spot"', 'packout_hub_only' === $code( $answer ) && 404 === ( $answer->get_error_data()['status'] ?? 0 ), $code( $answer ) );
	}
	packout_partner_check( 'the partner is still active after all of that', Partners::active( $partner ) && Partners::of_team( $alpha ) === $partner );
	packout_partner_check( 'nobody keeps Places or draws map features here', ! Places::can_manage( $coordinator ) && ! Features::can_create( $coordinator ) );
	packout_partner_check( 'the Places and Partners blocks are not registered', ! WP_Block_Type_Registry::get_instance()->is_registered( 'packout/places' ) && ! WP_Block_Type_Registry::get_instance()->is_registered( 'packout/partners' ) && WP_Block_Type_Registry::get_instance()->is_registered( 'packout/map' ) );

	$handlers = Capability_Map::handlers();
	$hub_acts = array_filter( Capability_Map::rows(), static fn( array $row ): bool => 'hub' === ( $row['surface'] ?? '' ) );
	packout_partner_check( 'no act that is the hub\'s alone has code here', $hub_acts && array() === array_intersect_key( $handlers, $hub_acts ), array_keys( array_intersect_key( $handlers, $hub_acts ) ) );

	/* ---------- The partner's program ---------- */

	WP_CLI::log( 'The partner\'s program' );
	packout_partner_check( 'a coordinator opens a program of the partner', Programs::can_open( 'partner', $partner, $coordinator ) );
	packout_partner_check( 'the lead of an organization does not, of the partner or of their organization', ! Programs::can_open( 'partner', $partner, $ana ) && ! Programs::can_open( 'team', $alpha, $ana ) );
	$program = Programs::save(
		0,
		array(
			'owner_kind' => 'partner',
			'owner'      => $partner,
			'title'      => 'Sitetest Adopt-a-Road ' . $packout_run,
			'orgs'       => array( $alpha ),
			'public'     => true,
		),
		$coordinator
	);
	packout_partner_check( 'it is saved', is_int( $program ) && $program > 0, $code( $program ) );
	$program         = (int) $program;
	$packout_posts[] = $program;
	$campaign        = Campaigns::save(
		0,
		array(
			'team'    => $alpha,
			'title'   => 'Sitetest sweep ' . $packout_run,
			'public'  => true,
			'program' => $program,
		),
		$ana
	);
	packout_partner_check( 'an organization taking part files a campaign under it', is_int( $campaign ) && Programs::of_campaign( (int) $campaign ) === $program, $code( $campaign ) );
	$packout_posts[] = (int) $campaign;
	$outside         = Campaigns::save(
		0,
		array(
			'team'    => $beta,
			'title'   => 'Sitetest other sweep ' . $packout_run,
			'public'  => true,
			'program' => $program,
		),
		$bob
	);
	packout_partner_check( 'an organization that is not taking part cannot', is_wp_error( $outside ) || 0 === Programs::of_campaign( (int) $outside ), $code( $outside ) );
	if ( is_int( $outside ) ) {
		$packout_posts[] = $outside;
	}
	packout_partner_check( 'the program is among the partner\'s', in_array( $program, wp_list_pluck( Programs::of_partner( $partner ), 'ID' ), true ) );

	/* ---------- The overview ---------- */

	WP_CLI::log( 'The overview of the site and of one organization' );
	packout_partner_check( 'a coordinator opens the partner\'s overview, which is the site\'s', Overview::can_open( 'partner', $partner, $coordinator ) && admin_url( 'admin.php?page=packout-spot' ) === Overview_Page::url( 'partner', $partner ) );
	packout_partner_check( 'the lead of an organization opens their own and no other', Overview::can_open( 'team', $alpha, $ana ) && ! Overview::can_open( 'team', $beta, $ana ) && ! Overview::can_open( 'partner', $partner, $ana ) );
	packout_partner_check( 'and lands on it', false !== strpos( Overview_Page::home( $ana ), 'overview=' . $alpha ), Overview_Page::home( $ana ) );
	packout_partner_check( 'a member opens none', ! Overview::can_open( 'team', $alpha, $mem ) );
	packout_partner_check( 'no queue waits for partners to be approved', ! isset( Overview::queues()['partners'] ) && isset( Overview::queues()['organizations'] ), array_keys( Overview::queues() ) );
	$now = Overview::now();
	packout_partner_check( 'the site\'s figures count its organizations and people', is_array( $now['platform'] ?? null ) && (int) ( $now['platform']['organizations'] ?? 0 ) >= 2 && (int) ( $now['platform']['people'] ?? 0 ) >= 4, $now['platform'] ?? null );

	/* ---------- Clearing a Spot, and the export ---------- */

	WP_CLI::log( 'Clearing a Spot, and the export' );
	$spot = Spots::create(
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Sitetest tires at the turnout ' . $packout_run . '.',
			'landmark'    => 'Sitetest turnout',
			'lat'         => 34.71,
			'lng'         => -111.65,
		),
		$mem
	);
	packout_partner_check( 'a person reports a Spot', is_int( $spot ), $code( $spot ) );
	$spot            = (int) $spot;
	$packout_posts[] = $spot;
	Spots::publish( $spot );
	packout_partner_check( 'a Spot that needs no authorization has nothing to clear', 'packout_no_clearance' === $code( Spots::clear( $spot, array( 'reference' => 'Permit 12' ), $coordinator ) ) );
	Spots::set_hazard( $spot, 'needs_authorization' );
	packout_partner_check( 'a reporter cannot record that the work is authorized', is_wp_error( Spots::clear( $spot, array( 'reference' => 'Permit 12' ), $mem ) ) );
	$cleared = Spots::clear(
		$spot,
		array(
			'reference' => 'Sitetest permit 12',
			'scope'     => 'Removal of tires.',
			'on'        => gmdate( 'Y-m-d' ),
			'public'    => true,
		),
		$coordinator
	);
	packout_partner_check( 'a coordinator can', true === $cleared, $code( $cleared ) );
	packout_partner_check( 'the Spot holds what was typed', 'Sitetest permit 12' === ( Spots::clearance( $spot )['reference'] ?? '' ), Spots::clearance( $spot ) );

	packout_partner_check( 'a coordinator exports, and a lead does not', Export::can( $coordinator ) && ! Export::can( $ana ) );
	$file = Export::file( 'spots_public', array(), $coordinator );
	packout_partner_check( 'the public file of Spots holds the Spot, and not what authorizes the work', ! is_wp_error( $file ) && false !== strpos( (string) ( $file['body'] ?? '' ), 'Sitetest turnout' ) && false === strpos( (string) ( $file['body'] ?? '' ), 'Sitetest permit 12' ), is_wp_error( $file ) ? $code( $file ) : array_keys( $file ) );
	$file = Export::file( 'spots_internal', array(), $coordinator );
	packout_partner_check( 'the internal file holds both', ! is_wp_error( $file ) && false !== strpos( (string) ( $file['body'] ?? '' ), 'Sitetest turnout' ) && false !== strpos( (string) ( $file['body'] ?? '' ), 'Sitetest permit 12' ) );
	packout_partner_check( 'a lead is refused the file', 'packout_forbidden' === $code( Export::file( 'spots_public', array(), $ana ) ) );

	/* ---------- Addresses ---------- */

	WP_CLI::log( 'Addresses' );
	packout_partner_check( 'each kind of record has an address, and no two share one', array_keys( Settings::BASES ) === array_keys( $packout_bases ) && count( array_unique( $packout_bases ) ) === count( $packout_bases ), $packout_bases );
	packout_partner_check( 'the application uses the site\'s choice', App::base( 'spot' ) === $packout_bases['spot'] && App::base( 'team' ) === $packout_bases['team'] );

	$page            = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Sitetest Crew ' . $packout_run,
			'post_name'   => 'sitetest-crew-' . $packout_run,
		)
	);
	$packout_posts[] = (int) $page;
	$taken           = 'sitetest-crew-' . $packout_run;
	$checked         = Addresses::check( array( 'team' => $taken ) );
	packout_partner_check( 'an address a page has is refused, naming the page', isset( $checked['problems']['team'] ) && false !== strpos( $checked['problems']['team'], 'Sitetest Crew ' . $packout_run ) && $packout_bases === $checked['bases'], $checked );
	packout_partner_check( 'and one that is free is suggested', false !== strpos( (string) ( $checked['problems']['team'] ?? '' ), 'pack-out-' . $taken ) && Addresses::suggest( $taken, $packout_bases ) === 'pack-out-' . $taken );
	packout_partner_check( 'an address WordPress uses is refused', '' !== Addresses::problem( 'team', 'wp-admin', $packout_bases ) && '' !== Addresses::problem( 'team', 'feed', $packout_bases ) );
	packout_partner_check( 'so is the address of a grouping of posts', '' !== Addresses::user_of( 'category' ) && '' !== Addresses::user_of( 'tag' ), array( Addresses::user_of( 'category' ), Addresses::user_of( 'tag' ) ) );
	packout_partner_check( 'so is one another kind of record has here', '' !== Addresses::problem( 'team', $packout_bases['mission'], $packout_bases ) );
	packout_partner_check( 'and one with no letters in it', '' !== Addresses::problem( 'team', sanitize_title( ' !! ' ), $packout_bases ) );
	packout_partner_check( 'the address a kind already has is no problem', array() === Addresses::check( array( 'team' => $packout_bases['team'] ) )['problems'] );

	$before = (string) get_permalink( $alpha );
	$new    = 'crew-' . $packout_run;
	packout_partner_check( 'a lead does not change the addresses', ! user_can( $ana, 'manage_options' ) );
	$problems = Addresses::save( array( 'team' => $new, 'mission' => $taken ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Two short values.
	packout_partner_check( 'saving keeps the good choice and reports the bad one', array( 'mission' ) === array_keys( $problems ) && Settings::get( 'bases' )['team'] === $new && Settings::get( 'bases' )['mission'] === $packout_bases['mission'], $problems );
	packout_partner_check( 'the rules are to be written once', '1' === get_option( Addresses::FLUSH ) );
	$settle();
	packout_partner_check( 'and then are not again', false === get_option( Addresses::FLUSH ) );
	$after = (string) get_permalink( $alpha );
	packout_partner_check( 'an organization\'s page is at the new address', false !== strpos( $after, '/' . $new . '/' ) && $after !== $before, $after );
	$got = $fetch( $after );
	packout_partner_check( 'which opens', 200 === $got[0], $got[0] );
	$got = $fetch( $before );
	packout_partner_check( 'the old address forwards to it for good', 301 === $got[0] && untrailingslashit( $after ) === untrailingslashit( $got[1] ), array( $got[0], $got[1] ) );
	$got = $fetch( home_url( '/sitetest-nothing-' . $packout_run . '/' ) );
	packout_partner_check( 'an address that never was is still not found', 404 === $got[0], $got[0] );

	Addresses::save( array( 'team' => $packout_bases['team'] ) );
	$settle();
	$got = $fetch( $before );
	packout_partner_check( 'changing it back brings the first address back, and it forwards nowhere', 200 === $got[0] && ! isset( get_option( Addresses::OLD, array() )[ $packout_bases['team'] ] ), array( $got[0], get_option( Addresses::OLD ) ) );
	$got = $fetch( $after );
	packout_partner_check( 'and the address that was tried forwards to it', 301 === $got[0] && untrailingslashit( $before ) === untrailingslashit( $got[1] ), array( $got[0], $got[1] ) );
} catch ( \Throwable $e ) {
	packout_partner_check( $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine(), false );
} finally {
	wp_set_current_user( 0 );
	if ( Settings::get( 'bases' ) !== $packout_bases ) {
		Settings::update( array( 'bases' => $packout_bases ) );
	}
	update_option( Addresses::OLD, $packout_old, true );
	foreach ( array_reverse( array_unique( array_filter( $packout_posts ) ) ) as $post_id ) {
		if ( in_array( get_post_type( $post_id ), array( Schema::TEAM, Schema::PARTNER ), true ) ) {
			delete_option( 'packout_messages_' . $post_id );
		}
		wp_delete_post( $post_id, true );
	}
	if ( $packout_site && Partners::site() > 0 ) {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		Partners::make_site( (int) $admins[0], $packout_site );
	}
	foreach ( $packout_users as $user_id ) {
		wp_delete_user( $user_id );
	}
	// The rules are written by a request of their own, which knows only the addresses as they now are.
	update_option( Addresses::FLUSH, '1', true );
	$fetch( home_url( '/' ) );
}

packout_partner_finish();
