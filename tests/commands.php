<?php
/**
 * The `wp packout` commands, the rows the plugin adds to the checks, and translations.
 *
 * Each command is run as a person would run it, in a process of its own,
 * and its output and exit code are read. packout.spot is never asked: a
 * stand-in answers for it in the processes that would.
 *
 * Run inside WordPress, with the plugin active:
 *
 *     wp eval-file tests/commands.php
 *
 * The checks put the site's settings back as they were.
 *
 * @package PackOut_Partner
 */

use PackOut\Core\App\App;
use PackOut\Core\App\Connection;
use PackOut\Core\App\Health;
use PackOut\Core\App\Pages;
use PackOut\Partner\Settings;
use PackOut\Partner\Site_Health;

require __DIR__ . '/support.php';

$packout_run   = strtolower( wp_generate_password( 6, false ) );
$packout_kept  = array();
$packout_made  = array();
$packout_files = array();

$run = static function ( string $command ): object {
	return WP_CLI::runcommand(
		$command,
		array(
			'launch'     => true,
			'return'     => 'all',
			'exit_error' => false,
		)
	);
};

// An option as the database has it now: a command's own process may have changed it since this one last read it.
$stored = static function ( string $name ) {
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	return get_option( $name );
};

// What answers for packout.spot in a command's own process. It keeps what it was asked, by method and path.
$packout_hub = trailingslashit( get_temp_dir() ) . 'crewtest-hub-' . $packout_run . '.php';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A file of the test's own, in the temporary directory.
file_put_contents(
	$packout_hub,
	<<<'PHP'
<?php
WP_CLI::add_wp_hook(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, '/wp-json/packout/v1/' ) ) {
			return $pre;
		}
		$seen   = (array) get_option( 'crewtest_cli_requests', array() );
		$seen[] = strtoupper( (string) ( $args['method'] ?? 'GET' ) ) . ' ' . wp_parse_url( (string) $url, PHP_URL_PATH );
		update_option( 'crewtest_cli_requests', $seen, false );
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array(
					'version' => '7',
					'url'     => 'https://packout.spot/partner-terms/',
					'open'    => '1' === get_option( 'crewtest_cli_open' ),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	5,
	3
);
PHP
);

try {
	foreach ( array( Health::MAIL, Connection::OPTION, Connection::KEY_OPTION, Pages::OPTION ) as $option ) {
		$packout_kept[ $option ] = get_option( $option, null );
		delete_option( $option );
	}
	delete_transient( 'packout_partner_terms' );
	$admin = (int) get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	)[0];

	WP_CLI::log( 'How the site stands' );
	$doctor = $run( 'packout doctor --format=json' );
	$rows   = json_decode( (string) $doctor->stdout, true );
	$byid   = is_array( $rows ) ? array_column( $rows, null, 'check' ) : array();
	packout_partner_check( '`wp packout doctor` lists every check with what it found', isset( $byid['permalinks'], $byid['sodium'], $byid['mail'], $byid['connection'], $byid['addresses'], $byid['updates'] ) && 'good' === $byid['permalinks']['status'] && '' !== $byid['mail']['found'], substr( (string) $doctor->stdout . (string) $doctor->stderr, 0, 300 ) );
	packout_partner_check( 'and exits with 0 when nothing needs fixing', 0 === (int) $doctor->return_code, $doctor->return_code );
	$words = $run( 'packout doctor' );
	packout_partner_check( 'as a table it ends by saying so', 0 === (int) $words->return_code && ( false !== strpos( (string) $words->stdout, 'Nothing needs fixing' ) || false !== strpos( (string) $words->stdout, 'Every check is good' ) ) );

	$status = $run( 'packout status --format=json' );
	$facts  = json_decode( (string) $status->stdout, true );
	$named  = is_array( $facts ) ? array_column( $facts, 'value', 'name' ) : array();
	packout_partner_check( '`wp packout status` says the versions and how the connection stands', 0 === (int) $status->return_code && PACKOUT_PARTNER_VERSION === ( $named['Plugin version'] ?? '' ) && PACKOUT_CORE_VERSION === ( $named['Library version'] ?? '' ) && isset( $named['Waiting to be sent'] ), substr( (string) $status->stdout . (string) $status->stderr, 0, 300 ) );
	packout_partner_check( 'it says there is no key, and prints no email address', 'No key' === ( $named['Signing key'] ?? '' ) && false === strpos( (string) $status->stdout, '@' ) );

	// A site whose mail was refused has something that needs fixing.
	update_option(
		Health::MAIL,
		array(
			'at' => time(),
			'ok' => false,
		),
		false
	);
	$doctor = $run( 'packout doctor --format=json' );
	$byid   = array_column( (array) json_decode( (string) $doctor->stdout, true ), null, 'check' );
	packout_partner_check( 'when a check is critical, doctor exits with 1 and says which', 1 === (int) $doctor->return_code && 'critical' === ( $byid['mail']['status'] ?? '' ), $doctor->return_code );
	packout_partner_check( 'and so does status', 1 === (int) $run( 'packout status' )->return_code );
	delete_option( Health::MAIL );

	WP_CLI::log( 'The connection' );
	$sync = $run( 'packout sync' );
	packout_partner_check( '`wp packout sync` on a site that is not connected sends nothing and says why', 1 === (int) $sync->return_code && false !== strpos( (string) $sync->stderr, 'not sending' ) );
	packout_partner_check( 'so does reconcile', 1 === (int) $run( 'packout reconcile' )->return_code );
	$notices = $run( 'packout notices' );
	packout_partner_check( '`wp packout notices` says there are none', 0 === (int) $notices->return_code && false !== strpos( (string) $notices->stdout, 'nothing' ) );
	$gone = $run( 'packout disconnect --yes --user=' . $admin );
	packout_partner_check( 'disconnecting a site that is not connected is refused', 1 === (int) $gone->return_code && false !== strpos( (string) $gone->stderr, 'not connected' ) );

	delete_option( 'crewtest_cli_requests' );
	$nobody = $run( 'packout connect --accept-terms=7 --require=' . $packout_hub );
	packout_partner_check( 'connecting needs an administrator, and asks nothing of packout.spot without one', 1 === (int) $nobody->return_code && false !== strpos( (string) $nobody->stderr, '--user' ) && false === $stored( 'crewtest_cli_requests' ), (string) $nobody->stderr );

	update_option( 'crewtest_cli_open', '0', false );
	$closed = $run( 'packout connect --accept-terms=7 --user=' . $admin . ' --require=' . $packout_hub );
	packout_partner_check( 'while packout.spot is not taking partner sites, connecting stops there', 1 === (int) $closed->return_code && false !== strpos( (string) $closed->stderr, 'not taking new partner sites' ) && array( 'GET /wp-json/packout/v1/partners/terms' ) === $stored( 'crewtest_cli_requests' ), array( (string) $closed->stderr, $stored( 'crewtest_cli_requests' ) ) );

	update_option( 'crewtest_cli_open', '1', false );
	delete_option( 'crewtest_cli_requests' );
	$unread = $run( 'packout connect --user=' . $admin . ' --require=' . $packout_hub );
	packout_partner_check( 'without --accept-terms it prints the version and where to read them', 1 === (int) $unread->return_code && false !== strpos( (string) $unread->stdout, 'version 7' ) && false !== strpos( (string) $unread->stdout, 'https://packout.spot/partner-terms/' ) && false !== strpos( (string) $unread->stderr, '--accept-terms=7' ), array( (string) $unread->stdout, (string) $unread->stderr ) );
	$older = $run( 'packout connect --accept-terms=6 --user=' . $admin . ' --require=' . $packout_hub );
	packout_partner_check( 'accepting a version that is not the current one is not accepting', 1 === (int) $older->return_code && false !== strpos( (string) $older->stderr, 'Nothing was sent' ) );
	packout_partner_check( 'and in neither case was anything but the terms asked for, or a key made', array( 'GET /wp-json/packout/v1/partners/terms' ) === array_values( array_unique( (array) $stored( 'crewtest_cli_requests' ) ) ) && false === $stored( Connection::KEY_OPTION ) && false === $stored( Connection::OPTION ) && ! Connection::connected(), $stored( 'crewtest_cli_requests' ) );

	WP_CLI::log( 'The pages' );
	$pages = $run( 'packout pages --format=json' );
	$list  = array_column( (array) json_decode( (string) $pages->stdout, true ), 'state', 'page' );
	packout_partner_check( '`wp packout pages` lists every template and how it stands', 0 === (int) $pages->return_code && count( Pages::templates() ) === count( $list ) && 'skipped' === ( $list['map'] ?? '' ), $list );
	wp_set_current_user( $admin );
	$map            = Pages::act( 'map', 'create' );
	$how            = Pages::act( 'how', 'create' );
	$packout_made[] = (int) $map;
	$packout_made[] = (int) $how;
	// Both as an older version of the plugin made them. The site then edits one.
	foreach ( array( (int) $map, (int) $how ) as $page ) {
		wp_update_post(
			array(
				'ID'           => $page,
				'post_content' => '<!-- wp:paragraph --><p>The words of an older version.</p><!-- /wp:paragraph -->',
			)
		);
		update_post_meta( $page, Pages::VERSION, '0' );
		update_post_meta( $page, Pages::HASH, Pages::hash( (string) get_post_field( 'post_content', $page ) ) );
	}
	wp_update_post(
		array(
			'ID'           => (int) $how,
			'post_content' => '<!-- wp:paragraph --><p>We wrote this ourselves.</p><!-- /wp:paragraph -->',
		)
	);
	$refused = $run( 'packout pages update' );
	packout_partner_check( 'updating the pages needs an administrator', 1 === (int) $refused->return_code && false !== strpos( (string) get_post_field( 'post_content', (int) $map ), 'older version' ) );
	$updated = $run( 'packout pages update --user=' . $admin );
	clean_post_cache( (int) $map );
	clean_post_cache( (int) $how );
	packout_partner_check( '`wp packout pages update` writes the new template to the page nobody edited', 0 === (int) $updated->return_code && false !== strpos( (string) $updated->stdout, 'Updated: 1' ) && Pages::hash( (string) get_post_field( 'post_content', (int) $map ) ) === Pages::hash( Pages::content( 'map' ) ), (string) $updated->stdout . (string) $updated->stderr );
	packout_partner_check( 'and leaves the edited one word for word', false !== strpos( (string) get_post_field( 'post_content', (int) $how ), 'We wrote this ourselves.' ) && 'edited' === Pages::state( 'how' )['state'] );

	WP_CLI::log( 'The plugin\'s own checks' );
	$checks = Health::checks();
	packout_partner_check( 'the plugin adds the addresses and the update check to the list', isset( $checks['addresses'], $checks['updates'] ) && 'good' === $checks['addresses']['status'] && array() === Site_Health::clashes(), $checks['addresses'] ?? 'missing' );
	// A page of the site's own at the address the organizations use.
	$base           = (string) Settings::get( 'bases' )['team'];
	$clash          = (int) wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Our people ' . $packout_run,
			'post_name'   => $base,
		)
	);
	$packout_made[] = $clash;
	if ( get_post_field( 'post_name', $clash ) === $base ) {
		$checks = Health::checks();
		packout_partner_check( 'a page of the site\'s own at an address the plugin uses is critical, and named', 'critical' === $checks['addresses']['status'] && false !== strpos( $checks['addresses']['detail'], 'Our people ' . $packout_run ) && false !== strpos( $checks['addresses']['url'], 'packout-partner' ), $checks['addresses'] );
		$doctor = $run( 'packout doctor --format=json' );
		packout_partner_check( 'and doctor exits with 1 for it', 1 === (int) $doctor->return_code && false !== strpos( (string) $doctor->stdout, 'Our people ' . $packout_run ) );
		wp_delete_post( $clash, true );
		packout_partner_check( 'with the page gone the check is good again', 'good' === Health::checks()['addresses']['status'] );
	} else {
		WP_CLI::log( '  --  The address /' . $base . '/ could not be given to a page on this site, so the clash was not made.' );
	}
	$tests = Health::tests( array( 'direct' => array() ) );
	packout_partner_check( 'Site Health shows them with the rest', isset( $tests['direct']['packout_addresses'], $tests['direct']['packout_updates'] ) );

	WP_CLI::log( 'Translations' );
	packout_partner_check( 'the library looks for its translations in the plugin\'s languages directory', App::languages() === dirname( PACKOUT_PARTNER_FILE ) . '/languages' && is_readable( App::languages() . '/packout-core.pot' ) && is_readable( App::languages() . '/packout-partner.pot' ), App::languages() );
	// A translation as a site would install one, for a language nobody speaks.
	require_once ABSPATH . WPINC . '/pomo/mo.php';
	$words = new MO();
	$words->add_entry(
		new Translation_Entry(
			array(
				'singular'     => 'Set up Pack-Out Spot',
				'translations' => array( 'Tes pu Kcap-Tuo Tops' ),
			)
		)
	);
	wp_mkdir_p( WP_LANG_DIR . '/plugins' );
	$packout_files[] = WP_LANG_DIR . '/plugins/packout-core-xx_XX.mo';
	$written         = $words->export_to_file( $packout_files[0] );
	$speak           = static fn(): string => 'xx_XX';
	add_filter( 'determine_locale', $speak );
	App::load_textdomain();
	// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- The library's words, read as the library reads them.
	$said = __( 'Set up Pack-Out Spot', 'packout-core' );
	remove_filter( 'determine_locale', $speak );
	unload_textdomain( 'packout-core' );
	packout_partner_check( 'a translation of the library\'s words is used when the site has one', $written && 'Tes pu Kcap-Tuo Tops' === $said, $said );
	// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- As above.
	packout_partner_check( 'and without one the words are as written', 'Set up Pack-Out Spot' === __( 'Set up Pack-Out Spot', 'packout-core' ) );
	$scripts = wp_scripts();
	packout_partner_check( 'the editor\'s script is given its translations from the same place', isset( $scripts->registered['packout-blocks-editor'] ) && 'packout-core' === $scripts->registered['packout-blocks-editor']->textdomain && App::languages() === $scripts->registered['packout-blocks-editor']->translations_path, $scripts->registered['packout-blocks-editor']->textdomain ?? 'not registered' );
} finally {
	foreach ( $packout_made as $page ) {
		if ( $page > 0 ) {
			wp_delete_post( $page, true );
		}
	}
	foreach ( $packout_kept as $option => $value ) {
		if ( null === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value, Connection::OPTION === $option );
		}
	}
	delete_option( 'crewtest_cli_requests' );
	delete_option( 'crewtest_cli_open' );
	delete_transient( 'packout_partner_terms' );
	if ( file_exists( $packout_hub ) ) {
		wp_delete_file( $packout_hub );
	}
	foreach ( $packout_files as $file ) {
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}
}

packout_partner_finish();
