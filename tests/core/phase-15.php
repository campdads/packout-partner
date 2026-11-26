<?php
/**
 * Phase 15 acceptance checks for the coordinator Overview.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-15.php
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Admin;
use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Export;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Overview;
use PackOut\Core\App\Overview_Report;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Spots;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

// What only packout.spot does is checked there. On a partner site it is left out, and the run says so.
$hub  = \PackOut\Core\App\App::is_hub();
$skip = static function ( string $what ): void {
	WP_CLI::log( '  --  ' . $what );
};

$packout_failures = array();
$packout_passed   = 0;
$packout_posts    = array();
$packout_users    = array();
$reporter         = 0;

$check = static function ( string $label, bool $ok, $detail = '' ) use ( &$packout_failures, &$packout_passed ): void {
	if ( $ok ) {
		++$packout_passed;
		WP_CLI::log( '  ok    ' . $label );
		return;
	}
	$packout_failures[] = $label;
	WP_CLI::log( '  FAIL  ' . $label . ( '' !== $detail ? ' — ' . ( is_scalar( $detail ) ? $detail : wp_json_encode( $detail ) ) : '' ) );
};

$as = static function ( int $user_id ): void {
	wp_set_current_user( $user_id );
};

$call = static function ( string $route, array $params = array() ) {
	$request = new WP_REST_Request( 'GET', '/packout/v1/overview' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
};

$user = static function ( string $role, string $display = '' ) use ( &$packout_users ): int {
	$login           = 'pos-ov-' . $role . '-' . wp_generate_password( 6, false );
	$id              = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => $login . '@example.test',
			'display_name' => '' !== $display ? $display : $login,
			'role'         => $role,
		)
	);
	$packout_users[] = $id;
	return (int) $id;
};

$post = static function ( string $type, string $status, array $meta = array(), string $title = '' ) use ( &$packout_posts ): int {
	$id              = wp_insert_post(
		array(
			'post_type'   => $type,
			'post_status' => $status,
			'post_title'  => '' !== $title ? $title : 'Overview ' . wp_generate_password( 8, false ),
			'post_author' => get_current_user_id(),
		),
		true
	);
	$id              = (int) $id;
	$packout_posts[] = $id;
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
};

$spot = static function ( array $extra = array() ) use ( &$packout_posts, &$reporter ): int {
	$id              = Spots::create(
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Overview fixture Spot.',
			'landmark'    => 'Overview fixture landmark',
		),
		$reporter,
		$extra
	);
	$id              = (int) $id;
	$packout_posts[] = $id;
	return $id;
};

try {
	$reporter_name = 'River Reporter UniqueName ' . wp_generate_password( 6, false );
	$coordinator   = $user( 'pos_coordinator' );
	$lead          = $user( 'pos_lead' );
	$reporter      = $user( 'pos_reporter', $reporter_name );
	$as( $coordinator );
	$reporter_email = (string) get_userdata( $reporter )->user_email;
	$token          = wp_generate_password( 8, false );
	$listed_name    = 'Alpha River Keepers ' . $token;
	$unlisted_name  = 'Hidden Crew ' . $token;
	$today          = wp_date( 'Y-m-d' );
	$yesterday      = wp_date( 'Y-m-d', strtotime( '-1 day' ) );

	WP_CLI::log( 'Access' );
	$as( $lead );
	list( $code ) = $call( '/now' );
	$check( 'a lead is denied overview/now', $code >= 400, $code );
	list( $code ) = $call( '/period', array( 'days' => 30 ) );
	$check( 'a lead is denied overview/period', $code >= 400, $code );
	$check( 'a lead cannot download the impact report', ! Export::can() && ! Capabilities::can( Capabilities::MODERATE ) );
	$as( $coordinator );
	list( $code, $now_payload ) = $call( '/now' );
	$check( 'a coordinator may read overview/now', 200 === $code && isset( $now_payload['waiting'], $now_payload['computed_at'] ), array( $code, $now_payload ) );
	list( $code, $period_payload ) = $call( '/period', array( 'days' => 90 ) );
	$check( 'a coordinator may read overview/period', 200 === $code && isset( $period_payload['a'], $period_payload['bucket'] ), array( $code, $period_payload ) );

	WP_CLI::log( 'Queues' );
	$before_counted = Overview::queue( 'counted' )['count'];
	$old_counted    = $post(
		Schema::IMPACT,
		'private',
		array(
			'_pos_kind'         => Impact::CLEANUP,
			'_pos_verification' => 'verified',
			'_pos_verified_at'  => '2020-01-01T00:00:00+00:00',
			'_pos_date'         => $today,
		)
	);
	$post(
		Schema::IMPACT,
		'private',
		array(
			'_pos_kind'         => Impact::CLEANUP,
			'_pos_verification' => 'verified',
			'_pos_verified_at'  => gmdate( 'c' ),
			'_pos_date'         => $today,
		)
	);
	$counted = Overview::queue( 'counted' );
	$check( 'a verified private record is in not counted', $counted['count'] === $before_counted + 2, $counted );
	if ( 0 === $before_counted ) {
		$check( 'oldest_id is the earliest not-counted record', $old_counted === (int) $counted['oldest_id'], $counted );
	} else {
		$oldest_at = Impact::field( (int) $counted['oldest_id'], 'verified_at' );
		$check(
			'oldest_id is the earliest not-counted record',
			$counted['oldest_id'] > 0 && strtotime( $oldest_at ) <= strtotime( '2020-01-01T00:00:00+00:00' ),
			array( $counted, $oldest_at )
		);
	}

	WP_CLI::log( 'A' );
	$before_spotted = Overview::now()['levers']['a']['spotted']['count'];
	$open_spot      = $spot();
	Spots::publish( $open_spot );
	$check( 'a spotted Spot is in the no-mission lever', Overview::now()['levers']['a']['spotted']['count'] === $before_spotted + 1 );
	Spots::set_hazard( $open_spot, 'do_not_touch' );
	$check( 'do_not_touch is outside Spotted, no mission', Overview::now()['levers']['a']['spotted']['count'] === $before_spotted );

	$watch_spot = $spot();
	Spots::publish( $watch_spot );
	Spots::mark_cleaned( $watch_spot, $coordinator, 0 );
	Spots::mark_returned( $watch_spot );
	$returned_at = '2019-06-01T00:00:00+00:00';
	update_post_meta( $watch_spot, '_pos_returned_at', $returned_at );
	$watch = Overview::now()['levers']['a']['watch'];
	$stamp = Spots::field( (int) $watch['oldest_id'], 'returned_at' );
	$check( 'a watch Spot ages from returned_at', '' !== $stamp && Overview::age( $stamp )['age'] === $watch['age'], array( $watch, $stamp ) );

	$overdue = $post(
		Schema::MISSION,
		'private',
		array(
			'_pos_status' => 'planned',
			'_pos_end'    => $yesterday,
		)
	);
	$check( 'a mission ended yesterday is overdue', in_array( $overdue, Overview::overdue_ids(), true ) );

	$period_a = Overview::period( 30 )['a']['current'];
	$check(
		'net open equals reported minus cleaned',
		(int) $period_a['net_open'] === (int) $period_a['reported'] - (int) $period_a['cleaned'],
		$period_a
	);
	$weekly = Overview::period( 90 );
	$check( 'period 90 is weekly', 'week' === $weekly['bucket'] && count( $weekly['series'] ) >= 12, $weekly['bucket'] );

	WP_CLI::log( 'B' );
	$due_spot = $spot();
	Spots::publish( $due_spot );
	Spots::mark_cleaned( $due_spot, $coordinator, 0 );
	$old_clean = gmdate( 'c', strtotime( '-100 days' ) );
	update_post_meta( $due_spot, '_pos_cleaned_at', $old_clean );

	$held_spot = $spot();
	Spots::publish( $held_spot );
	Spots::mark_cleaned( $held_spot, $coordinator, 0 );
	update_post_meta( $held_spot, '_pos_cleaned_at', $old_clean );
	$visit           = Impact::save_visit( $held_spot, Impact::STILL_CLEAR, 'Still clear at the pullout.', $coordinator );
	$packout_posts[] = (int) $visit;
	$check( 'a Spot cleaned 100 days ago with no visit is due', in_array( $due_spot, Overview::due_ids(), true ) );
	$check( 'a Spot with a still-clear record is not due', ! in_array( $held_spot, Overview::due_ids(), true ), $visit );

	// The queue of cleaned Spots without a record follows the record of the Spot's current cleaning, and no other.
	$waiting = Overview::queue( 'unrecorded' )['count'];
	$check( 'two cleaned Spots with no cleanup record wait to be recorded', $waiting >= 2, $waiting );
	$cleanup = Impact::save_cleanup( $due_spot, array( 'bags' => 1 ), $coordinator );
	$check( 'recording one takes it out of the queue', is_int( $cleanup ) && Overview::queue( 'unrecorded' )['count'] === $waiting - 1, array( $cleanup, Overview::queue( 'unrecorded' )['count'] ) );
	if ( is_int( $cleanup ) ) {
		update_post_meta( $cleanup, '_pos_cycle', gmdate( 'c', strtotime( '-300 days' ) ) );
		$check( 'a record of an earlier cleaning does not', Overview::queue( 'unrecorded' )['count'] === $waiting, Overview::queue( 'unrecorded' )['count'] );
		wp_delete_post( $cleanup, true );
	}
	$check( 'and without the record the Spot waits again', Overview::queue( 'unrecorded' )['count'] === $waiting, Overview::queue( 'unrecorded' )['count'] );

	WP_CLI::log( 'C' );
	$big   = $post( Schema::TEAM, 'publish', array(), $listed_name );
	$small = $post( Schema::TEAM, 'publish', array(), 'Beta Trail Club ' . $token );
	for ( $i = 0; $i < 3; $i++ ) {
		$post(
			Schema::IMPACT,
			'private',
			array(
				'_pos_kind' => Impact::CLEANUP,
				'_pos_team' => $big,
				'_pos_date' => $today,
			)
		);
	}
	$post(
		Schema::IMPACT,
		'private',
		array(
			'_pos_kind' => Impact::CLEANUP,
			'_pos_team' => $small,
			'_pos_date' => $today,
		)
	);
	$period_c = Overview::period( 30 );
	$from     = (string) $period_c['from'];
	$to       = (string) $period_c['to'];
	$by_org   = array();
	foreach ( Impact::found(
		array(
			'kind' => Impact::CLEANUP,
			'from' => $from,
			'to'   => $to,
		)
	) as $record ) {
		$team = (int) Impact::field( $record->ID, 'team', '0' );
		if ( $team > 0 ) {
			$by_org[ $team ] = ( $by_org[ $team ] ?? 0 ) + 1;
		}
	}
	$c = $period_c['c']['current'];
	$check( 'two organizations with three and one cleanups are counted', 3 === ( $by_org[ $big ] ?? 0 ) && 1 === ( $by_org[ $small ] ?? 0 ), $by_org );
	$check(
		'largest share matches organized cleanups in the window',
		( $by_org ? max( $by_org ) : 0 ) === (int) $c['largest_share'] && (int) array_sum( $by_org ) === (int) $c['largest_of'],
		array( $c, $by_org )
	);
	$fresh_org = $post( Schema::TEAM, 'publish', array() );
	wp_update_post(
		array(
			'ID'            => $fresh_org,
			'post_date'     => $yesterday . ' 12:00:00',
			'post_date_gmt' => get_gmt_from_date( $yesterday . ' 12:00:00' ),
		)
	);
	$check( 'an organization approved yesterday is not dormant', ! in_array( $fresh_org, Overview::dormant_ids(), true ) );

	WP_CLI::log( 'D' );
	$measured = $post(
		Schema::IMPACT,
		'private',
		array(
			'_pos_kind'          => Impact::CLEANUP,
			'_pos_date'          => $today,
			'_pos_pounds'        => '10',
			'_pos_pounds_source' => 'measured',
		)
	);
	$self     = $post(
		Schema::IMPACT,
		'private',
		array(
			'_pos_kind'          => Impact::CLEANUP,
			'_pos_date'          => $today,
			'_pos_pounds'        => '3',
			'_pos_pounds_source' => 'self_reported',
		)
	);
	unset( $measured, $self );
	$period_d = Overview::period( 30 );
	$totals   = Impact::totals(
		array(
			'from' => (string) $period_d['from'],
			'to'   => (string) $period_d['to'],
		)
	);
	$d        = $period_d['d']['current'];
	$check(
		'D figures equal Impact::totals for the same dates',
		(float) $d['pounds_measured'] === (float) $totals['outputs']['pounds_measured']
			&& (float) $d['pounds_self_reported'] === (float) $totals['outputs']['pounds_self_reported']
			&& (float) $d['hours_verified'] === (float) $totals['effort']['hours_verified']
			&& (float) $d['hours_unverified'] === (float) $totals['effort']['hours_unverified']
			&& (int) $d['spots_cleaned'] === (int) $totals['outputs']['spots']
			&& (int) $d['still_clear'] === (int) $totals['outcomes']['still_clear']
			&& (int) $d['returned'] === (int) $totals['outcomes']['returned'],
		array( $d, $totals )
	);

	WP_CLI::log( 'E' );
	$before_e   = Overview::period( 30 )['e']['current'];
	$field_spot = $spot( array( 'client_id' => 'field-' . $token ) );
	$form_spot  = $spot( array( 'entry_id' => 9001 ) );
	$after_e    = Overview::period( 30 )['e']['current'];
	$check( 'a client_id counts as the field application', (int) $after_e['field'] === (int) $before_e['field'] + 1, array( $before_e, $after_e, $field_spot ) );
	$check( 'an entry_id counts as the form', (int) $after_e['form'] === (int) $before_e['form'] + 1, array( $before_e, $after_e, $form_spot ) );

	WP_CLI::log( 'Readings' );
	$under = Overview::reading(
		'b',
		array(
			'still_clear' => 3,
			'returned'    => 2,
		)
	);
	$empty = Overview::reading(
		'b',
		array(
			'still_clear' => 0,
			'returned'    => 0,
		)
	);
	$rate  = Overview::reading(
		'b',
		array(
			'still_clear' => 8,
			'returned'    => 2,
		)
	);
	$check( 'under ten events the sentence contains no percent sign', false === strpos( $under, '%' ), $under );
	$check( 'with no return visits outcome B says there is no evidence', false !== strpos( $empty, 'no evidence' ), $empty );
	$check( 'ten or more visits may state a held share', false !== strpos( $rate, '%' ), $rate );

	WP_CLI::log( 'Cache' );
	Overview::refresh_now();
	$check( 'a now snapshot can be stored', false !== get_transient( 'packout_overview_now' ) );
	ob_start();
	Admin::overview();
	$html = ob_get_clean();
	$check( 'rendered HTML has figures', false !== strpos( $html, 'data-packout-path=' ) && false !== strpos( $html, 'Does a report lead to action' ), substr( $html, 0, 200 ) );
	$check( 'rendered HTML has no skeleton', false === strpos( $html, 'data-vectra-ui="skeleton"' ) );
	$check( 'the overview shell does not run Site Setup', false === strpos( $html, 'Report a Spot form' ) );
	$pending = $spot();
	Overview::refresh_now();
	$check( 'a now snapshot is present before publish', false !== get_transient( 'packout_overview_now' ) );
	Spots::publish( $pending );
	$check( 'publishing a pending Spot deletes now', false === get_transient( 'packout_overview_now' ) );

	$help   = Admin::setup_help();
	$tabs   = $help['tabs'];
	$titles = array();
	$bodies = '';
	foreach ( $tabs as $tab ) {
		$titles[] = (string) ( $tab['title'] ?? '' );
		$bodies  .= (string) ( $tab['content'] ?? '' );
	}
	$short = true;
	$icons = true;
	foreach ( $tabs as $tab ) {
		$title = (string) ( $tab['title'] ?? '' );
		if ( false !== strpos( $title, 'Ready' ) || false !== strpos( $title, 'Needs attention' ) || '' === $title ) {
			$short = false;
		}
		$icon = (string) ( $tab['icon'] ?? '' );
		// The icon is the plugin's: Toolkit's on the hub, the partner plugin's own on a partner site.
		if ( false === strpos( $icon, '<svg' ) || false === strpos( $icon, 'packout-setup-tab__glyph' ) || ( $hub && false === strpos( $icon, 'vectra-icon' ) ) ) {
			$icons = false;
		}
	}
	$sample = '<li id="tab-link-' . esc_attr( (string) ( $tabs[0]['id'] ?? '' ) ) . '"><a href="#tab-panel">' . esc_html( (string) ( $tabs[0]['title'] ?? '' ) ) . '</a></li>';
	$marked = Admin::with_setup_tab_icons( $sample );
	$check( 'Site Setup is one Help tab per section', 10 === count( $tabs ), $titles );
	$check( 'Site Setup tab labels stay short and put status in an icon', $short && $icons && array() !== $titles, $titles );
	$check( 'status icons are placed before the tab labels', false !== strpos( $marked, 'packout-setup-tab__icon' ) && false !== strpos( $marked, '<svg' ) && false !== strpos( $marked, 'aria-label="' ) );
	$check( 'tab content includes the setup details', false !== strpos( $bodies, 'Report a Spot form' ) && false !== strpos( $bodies, 'Offline map regions' ) && false !== strpos( $bodies, $hub ? 'data-vectra-ui="badge"' : 'data-packout-ui="badge"' ) );
	$check( 'the Help sidebar links to settings', false !== strpos( (string) $help['sidebar'], 'packout-settings' ) );
	$check( 'Help is called Site Setup on Overview', 'Site Setup' === Admin::screen_label( 'Help', 'Help', 'default' ) );

	WP_CLI::log( 'Report' );
	$unlisted = $post( Schema::TEAM, 'private', array(), $unlisted_name );
	$post(
		Schema::IMPACT,
		'private',
		array(
			'_pos_kind'          => Impact::CLEANUP,
			'_pos_team'          => $unlisted,
			'_pos_date'          => $today,
			'_pos_pounds'        => '4',
			'_pos_pounds_source' => 'self_reported',
		)
	);
	$data = Overview_Report::data( 30 );
	$json = Overview_Report::json( $data );
	$csv  = Overview_Report::csv( $data );
	$html = Overview_Report::html( $data );
	$pdf  = Overview_Report::pdf( $data );
	$blob = wp_json_encode( $data ) . $json . $csv . $html;
	$check( 'report data contains no reporter display name', false === strpos( $blob, $reporter_name ), $reporter_name );
	$check( 'report data contains no reporter email', false === strpos( $blob, $reporter_email ), $reporter_email );
	$check( 'report data contains no POS line', false === strpos( $blob, 'One Less POS' ) && false === strpos( $blob, 'POS on the Trail' ) );
	$check( 'report data contains no pin coordinates', ! isset( $data['lat'], $data['lng'], $data['latitude'], $data['longitude'] ) && false === strpos( $json, '"lat"' ) );
	$check(
		'measured and self-reported pounds are separate keys',
		isset( $data['outputs']['pounds_measured'], $data['outputs']['pounds_self_reported'] )
			&& $data['outputs']['pounds_measured'] !== $data['outputs']['pounds_self_reported']
	);
	$check( 'an unlisted organization is not named', ! in_array( $unlisted_name, (array) ( $data['who']['listed'] ?? array() ), true ), $data['who'] ?? array() );
	$check( 'unlisted organizations are a count', (int) ( $data['who']['unlisted'] ?? 0 ) >= 1, $data['who'] ?? array() );
	$decoded = json_decode( $json, true );
	$check( 'JSON decodes', is_array( $decoded ) && 1 === (int) ( $decoded['schema'] ?? 0 ) );
	$first = str_getcsv( strtok( $csv, "\r\n" ), ',', '"', '\\' );
	$check( 'CSV has four columns', array( 'section', 'metric', 'value', 'basis' ) === $first, $first );
	$check( 'HTML has no script', false === strpos( strtolower( $html ), '<script' ) );
	$check( 'HTML has no http resource', 1 !== preg_match( '/(?:src|href)=["\']https?:/i', $html ) );
	$check( 'HTML has every color written in', false === strpos( $html, '{{' ) && false === strpos( $html, 'var(--' ) && 1 === preg_match( '/background: #[0-9a-f]{6};/', $html ) );
	$from_day = wp_date( (string) get_option( 'date_format' ), strtotime( $data['period']['from'] . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
	$check( 'the cover gives the period in the site date format', false !== strpos( $html, esc_html( (string) $from_day ) ), $from_day );
	$check( 'the cover gives the site address without its scheme', false === strpos( $html, esc_html( home_url( '/' ) ) ) && false !== strpos( $html, (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) );
	$check( 'PDF output starts with %PDF', is_string( $pdf ) && 0 === strpos( $pdf, '%PDF' ), is_wp_error( $pdf ) ? $pdf->get_error_message() : substr( (string) $pdf, 0, 8 ) );
	$fonts  = dirname( PACKOUT_CORE_DIR ) . '/dompdf/packages/dompdf/dompdf/lib/fonts/';
	$cached = array_merge( (array) glob( $fonts . '*.afm.json' ), (array) glob( $fonts . '*.ufm.json' ) );
	$check( 'rendering the PDF writes nothing into the plugin', array() === $cached, $cached );
	$formats = array_keys( Overview_Report::formats() );
	$check( 'PDF is the default format', 'pdf' === ( $formats[0] ?? '' ), $formats );
} catch ( \Throwable $e ) {
	$packout_failures[] = $e->getMessage();
	WP_CLI::log( '  FAIL  ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

foreach ( $packout_posts as $id ) {
	wp_delete_post( (int) $id, true );
}
foreach ( $packout_users as $id ) {
	wp_delete_user( (int) $id );
}

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( count( $packout_failures ) . ' checks failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
