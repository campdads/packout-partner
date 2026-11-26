<?php
/**
 * Phase 10 acceptance checks: who a Spot's page credits with the report, the
 * photos the public sees, the page of a Spot (its header, its detail beside its
 * photos, and its activity), and the updates signed-in people leave on a Spot.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-10.php
 *
 * The script creates its own users, Spots, organizations, and missions,
 * keeps mail in memory, and removes everything it created. It exits non-zero
 * when a check fails.
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Claims;
use PackOut\Core\App\Credit;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Photos;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Settings;
use PackOut\Core\App\Spot_Page;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Subteams;
use PackOut\Core\App\Team_Page;
use PackOut\Core\App\Teams;
use PackOut\Core\App\Updates;

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
$packout_files    = array();
$packout_mail     = array();
$packout_run      = strtolower( wp_generate_password( 6, false ) );
$packout_settings = get_option( Settings::option(), null );

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

$code = static fn( $thing ): string => is_wp_error( $thing ) ? (string) $thing->get_error_code() : '';

$user = static function ( string $role, string $name ) use ( &$packout_users, $packout_run ): int {
	$login           = 'pos-test-' . sanitize_key( $name ) . '-' . $packout_run;
	$id              = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => $login . '@example.test',
			'display_name' => $name . ' Testperson',
			'role'         => $role,
		)
	);
	$packout_users[] = $id;
	return $id;
};

$email_of = static fn( int $id ): string => (string) get_userdata( $id )->user_email;

/**
 * A file as a form sends it: a JPEG, or a script with a photo's name.
 */
$file = static function ( string $name, bool $image = true ) use ( &$packout_files ): array {
	$path = wp_tempnam( $name );
	if ( $image ) {
		$picture = imagecreatetruecolor( 1600, 700 );
		imagefill( $picture, 0, 0, imagecolorallocate( $picture, 40, 94, 187 ) );
		imagejpeg( $picture, $path );
	} else {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test file.
		file_put_contents( $path, "<?php echo 'not an image';\n" );
	}
	$packout_files[] = $path;
	return array(
		'name'     => $name,
		'type'     => 'application/octet-stream',
		'tmp_name' => $path,
		'error'    => 0,
		'size'     => filesize( $path ),
	);
};

// Keep mail in memory. Nothing leaves the machine.
$keep_mail = static function ( $sent, $mail ) use ( &$packout_mail ) {
	foreach ( (array) $mail['to'] as $to ) {
		$packout_mail[] = array(
			'to'      => (string) $to,
			'subject' => (string) $mail['subject'],
			'message' => (string) $mail['message'],
		);
	}
	return true;
};
add_filter( 'pre_wp_mail', $keep_mail, 99, 2 );
$mail_to = static function ( string $to, string $subject = '' ) use ( &$packout_mail ): array {
	return array_values( array_filter( $packout_mail, static fn( $mail ) => strtolower( $mail['to'] ) === strtolower( $to ) && ( '' === $subject || false !== strpos( $mail['subject'], $subject ) ) ) );
};

// The public page of a post, as the theme asks for it. With an address, the page of that team of an organization.
$page_of = static function ( int $post_id, string $subteam = '' ): string {
	global $wp_query, $wp_the_query;
	$before_query = $wp_query;
	$before_main  = $wp_the_query;
	// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Standing in for the main query of a page view.
	$wp_query     = new WP_Query(
		array(
			'p'         => $post_id,
			'post_type' => get_post_type( $post_id ),
		)
	);
	$wp_the_query = $wp_query;
	if ( '' !== $subteam ) {
		$wp_query->set( Team_Page::QUERY_VAR, $subteam );
	}
	$html = '';
	if ( $wp_query->have_posts() ) {
		$wp_query->the_post();
		$html = (string) apply_filters( 'the_content', get_the_content() );
	}
	$wp_query     = $before_query;
	$wp_the_query = $before_main;
	// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	wp_reset_postdata();
	return $html;
};

try {
	$coordinator = $user( 'pos_coordinator', 'Coordinator' );
	$ana         = $user( 'pos_lead', 'Ana' );
	$ben         = $user( 'pos_reporter', 'Ben' );
	$cal         = $user( 'pos_lead', 'Cal' );
	$dee         = $user( 'pos_reporter', 'Dee' );
	$fay         = $user( 'pos_reporter', 'Fay' );

	$as( $coordinator );
	$name            = 'Credit Test Crew ' . $packout_run;
	$team            = Teams::save(
		0,
		array(
			'name'   => $name,
			'lead'   => $ana,
			'listed' => true,
		)
	);
	$packout_posts[] = $team;
	Teams::add_member( $team, get_userdata( $ben )->user_login );
	$quiet_name      = 'Quiet Test Crew ' . $packout_run;
	$quiet           = Teams::save(
		0,
		array(
			'name'   => $quiet_name,
			'lead'   => $cal,
			'listed' => false,
		)
	);
	$packout_posts[] = $quiet;
	$waiting         = Teams::propose( $fay, array( 'name' => 'Waiting Test Crew ' . $packout_run ) );
	$packout_posts[] = $waiting;
	Teams::flush();

	$report = static function ( string $what, float $lat, int $by, array $extra = array(), bool $publish = true ) use ( &$packout_posts ): int {
		$id              = Spots::create(
			array(
				'category'    => 'cleanup-waste',
				'description' => $what,
				'lat'         => $lat,
				'lng'         => -111.8,
			),
			$by,
			$extra
		);
		$packout_posts[] = $id;
		if ( $publish ) {
			Spots::publish( $id );
		}
		return $id;
	};
	$public = static fn( int $id ): array => Spots::payload( Spots::get( $id ), 'public' );
	$said   = static fn( $thing ): string => is_wp_error( $thing ) ? $thing->get_error_message() : '';
	$call   = static function ( string $method, string $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/packout/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}
		$response = rest_do_request( $request );
		return array( $response->get_status(), $response->get_data() );
	};

	WP_CLI::log( 'Who reported a Spot' );
	$check( 'the fixtures are in place', is_int( $team ) && is_int( $quiet ) && is_int( $waiting ), array( $said( $team ), $said( $quiet ), $said( $waiting ) ) );
	$check( 'a person can report for the organizations they lead or belong to', array( $team ) === array_keys( Credit::teams( $ana ) ) && array( $team ) === array_keys( Credit::teams( $ben ) ) && array() === Credit::teams( $dee ) );
	$check( 'and not for one that waits for a coordinator', array() === Credit::teams( $fay ) );

	$s_org = $report( 'Credittest tires at the turnout. ' . $packout_run, 34.61, $ben );
	$by    = $public( $s_org )['reported_by'];
	$check( 'a report from a person in an organization is credited to it', $name === $by['label'] && get_permalink( $team ) === $by['url'], $by );
	$check( 'the public copy carries the day it was reported', 1 === preg_match( '/^\d{4}-\d\d-\d\d$/', (string) $public( $s_org )['reported'] ) );
	$check( 'and nothing else about the reporter', array( 'label', 'url' ) === array_keys( $by ) && ! isset( $public( $s_org )['reporter'] ) && ! isset( $public( $s_org )['credit'] ) && false === strpos( (string) wp_json_encode( $public( $s_org ) ), 'Ben Testperson' ) );

	$s_self = $report( 'Credittest cans in the fire ring. ' . $packout_run, 34.62, $ben, array( 'credit_team' => 0 ) );
	$by     = $public( $s_self )['reported_by'];
	$check( 'a person who reports as themselves is “an individual”', 'an individual' === $by['label'] && '' === $by['url'], $by );
	$s_named = $report(
		'Credittest mattress in the wash. ' . $packout_run,
		34.63,
		$ben,
		array(
			'credit_team'  => 0,
			'credit_named' => true,
		)
	);
	$check( 'unless they asked to be named', 'Ben Testperson' === $public( $s_named )['reported_by']['label'] );
	$s_both = $report(
		'Credittest glass at the pullout. ' . $packout_run,
		34.64,
		$ben,
		array(
			'credit_team'  => $team,
			'credit_named' => true,
		)
	);
	$check( 'an organization is credited before a name', $name === $public( $s_both )['reported_by']['label'] && false === strpos( (string) wp_json_encode( $public( $s_both ) ), 'Ben Testperson' ) );
	$s_alone = $report( 'Credittest paint cans. ' . $packout_run, 34.65, $dee );
	$check( 'a person in no organization is “an individual”', 'an individual' === $public( $s_alone )['reported_by']['label'] );
	$s_other = $report( 'Credittest pallets. ' . $packout_run, 34.66, $dee, array( 'credit_team' => $team ) );
	$check( 'nobody reports for an organization they are not in', 0 === Spots::credit( $s_other )['team'] && 'an individual' === $public( $s_other )['reported_by']['label'] );
	$s_quiet = $report( 'Credittest shingles. ' . $packout_run, 34.67, $cal );
	$by      = $public( $s_quiet )['reported_by'];
	$check( 'an organization that is not listed is “an organization”', Spots::credit( $s_quiet )['team'] === $quiet && 'an organization' === $by['label'] && '' === $by['url'] && false === strpos( (string) wp_json_encode( $public( $s_quiet ) ), $quiet_name ), $by );
	$check(
		'the reporter\'s own copy says what they chose',
		array(
			'team'  => $team,
			'named' => true,
		) === Spots::payload( Spots::get( $s_both ), 'owner' )['credit']
	);

	// A coordinator changes it.
	Spots::set_credit( $s_alone, $team, false, true );
	$check( 'a coordinator can credit any organization', $name === $public( $s_alone )['reported_by']['label'] );
	Spots::set_credit( $s_named, 0, false, true );
	$check( 'and take a name off the page', 'an individual' === $public( $s_named )['reported_by']['label'] );
	Spots::set_credit( $s_alone, 0, false, true );

	// The field app.
	$as( $ben );
	$uuid                  = static fn(): string => wp_generate_uuid4();
	$one                   = $uuid();
	list( $status, $data ) = $call(
		'PUT',
		'/spots/client/' . $one,
		array(
			'category'     => 'cleanup-waste',
			'description'  => 'Credittest from the app. ' . $packout_run,
			'lat'          => 34.7,
			'lng'          => -111.8,
			'credit_team'  => 0,
			'credit_named' => true,
		)
	);
	$app                   = (int) ( $data['spot']['id'] ?? 0 );
	$packout_posts[]       = $app;
	$check(
		'the field app sends who to credit',
		in_array( $status, array( 200, 201 ), true ) && array(
			'team'  => 0,
			'named' => true,
		) === Spots::credit( $app ),
		array( $status, Spots::credit( $app ) )
	);
	list( $status, $data ) = $call(
		'PUT',
		'/spots/client/' . $one,
		array(
			'category'     => 'cleanup-waste',
			'description'  => 'Credittest from the app. ' . $packout_run,
			'lat'          => 34.7,
			'lng'          => -111.8,
			'credit_team'  => $team,
			'credit_named' => false,
			'revision'     => (int) ( $data['spot']['revision'] ?? 0 ),
		)
	);
	$check(
		'and a reporter changes it while the report waits',
		200 === $status && array(
			'team'  => $team,
			'named' => false,
		) === Spots::credit( $app ),
		array( $status, Spots::credit( $app ) )
	);
	list( $status, $data ) = $call(
		'PUT',
		'/spots/client/' . $one,
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Credittest from the app, again. ' . $packout_run,
			'lat'         => 34.7,
			'lng'         => -111.8,
			'revision'    => (int) ( $data['spot']['revision'] ?? 0 ),
		)
	);
	$check(
		'a device that does not say leaves the credit as it is',
		200 === $status && array(
			'team'  => $team,
			'named' => false,
		) === Spots::credit( $app ),
		array( $status, Spots::credit( $app ) )
	);
	$two                   = $uuid();
	list( $status, $data ) = $call(
		'PUT',
		'/spots/client/' . $two,
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Credittest from an older app. ' . $packout_run,
			'lat'         => 34.71,
			'lng'         => -111.8,
		)
	);
	$packout_posts[]       = (int) ( $data['spot']['id'] ?? 0 );
	$check( 'a new report from a device that does not say is credited to the person\'s organization', Spots::credit( (int) ( $data['spot']['id'] ?? 0 ) )['team'] === $team );
	list( $status, $data ) = $call( 'GET', '/sync' );
	$check(
		'the app is told which organizations the person can report for',
		200 === $status && array(
			array(
				'id'   => $team,
				'name' => $name,
			),
		) === ( $data['user']['teams'] ?? null ),
		$data['user'] ?? null
	);

	if ( $hub ) {
		// The website form.
		$as( 0 );
		Settings::save( array( 'form' => 'pos-test-form' ) );
		$submit = static function ( int $entry, array $post, array $values ) use ( &$packout_posts ): ?WP_Post {
			$_POST = $post;
			do_action(
				'vectra_form_submitted',
				array(
					'id'       => 9001,
					'form_key' => 'pos-test-form',
				),
				array( 'id' => $entry ),
				array(
					'by_key' => $values + array(
						'category' => 'Cleanup and waste',
						'landmark' => 'Second creek crossing',
					),
				)
			);
			$_POST = array();
			$found = get_posts(
				array(
					'post_type'   => Schema::SPOT,
					'post_status' => 'pending',
					'numberposts' => 1,
					'meta_key'    => '_pos_entry_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test lookup.
				'meta_value'      => $entry, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test lookup.
				)
			);
			if ( $found ) {
				$packout_posts[] = $found[0]->ID;
				Spots::publish( $found[0]->ID );
			}
			return $found ? $found[0] : null;
		};
		$entry  = 900000 + wp_rand( 1, 90000 );
		$guest  = $submit(
			$entry,
			array(),
			array(
				'description'   => 'Credittest form, no name. ' . $packout_run,
				'reporter_name' => 'Gwen Guest',
			)
		);
		$check( 'a visitor who does not ask to be named is “an individual”, and the name is not kept on the Spot', $guest && 'an individual' === $public( $guest->ID )['reported_by']['label'] && '' === Spots::field( $guest->ID, 'reporter_name' ) );
		$guest2 = $submit(
			$entry + 1,
			array( 'packout_credit_named' => '1' ),
			array(
				'description'   => 'Credittest form, named. ' . $packout_run,
				'reporter_name' => 'Gwen Guest',
			)
		);
		$check( 'a visitor who ticks the box is named as they typed', $guest2 && 'Gwen Guest' === $public( $guest2->ID )['reported_by']['label'] );
		$guest3 = $submit(
			$entry + 2,
			array(
				'packout_credit_named' => '1',
				'packout_credit_team'  => (string) $team,
			),
			array( 'description' => 'Credittest form, no name typed. ' . $packout_run )
		);
		$check( 'a visitor cannot credit an organization, and a box with no name is “an individual”', $guest3 && 0 === Spots::credit( $guest3->ID )['team'] && 'an individual' === $public( $guest3->ID )['reported_by']['label'] );
		$as( $ben );
		$member = $submit( $entry + 3, array( 'packout_credit_team' => (string) $team ), array( 'description' => 'Credittest form, signed in. ' . $packout_run ) );
		$check( 'a signed-in person chooses their organization on the form', $member && $name === $public( $member->ID )['reported_by']['label'] );
		$member2 = $submit(
			$entry + 4,
			array(
				'packout_credit_team'  => '0',
				'packout_credit_named' => '1',
			),
			array( 'description' => 'Credittest form, as myself. ' . $packout_run )
		);
		$check( 'or themselves, by name', $member2 && 'Ben Testperson' === $public( $member2->ID )['reported_by']['label'] );
	} else {
		$skip( 'The hub’s report form is Toolkit’s. A partner site’s own form is checked in its plugin.' );
	}

	$fields = Credit::fields( 'packout_credit', $ben );
	$check( 'the questions offer the person\'s organizations and “Myself”', false !== strpos( $fields, 'name="packout_credit_team"' ) && false !== strpos( $fields, '<option value="' . $team . '">' . esc_html( $name ) . '</option>' ) && false !== strpos( $fields, 'Myself, not an organization' ) && false !== strpos( $fields, 'name="packout_credit_named"' ) );
	$fields = Credit::fields( 'packout_credit', 0 );
	$check( 'a visitor is asked only whether to show their name', false === strpos( $fields, '<select' ) && false !== strpos( $fields, 'name="packout_credit_named"' ) && false !== strpos( $fields, 'the name you typed' ) );
	if ( null === $packout_settings ) {
		delete_option( Settings::option() );
	} else {
		update_option( Settings::option(), $packout_settings, false );
	}
	$as( 0 );
	$block = do_blocks( '<!-- wp:packout/report-prompt {"showForm":true} /-->' );
	if ( $hub ) {
		$check( 'the Report form asks them before its button, and says a photo is shown', false !== strpos( $block, 'vectra-submit' ) && strpos( $block, 'name="packout_credit_named"' ) > 0 && strpos( $block, 'name="packout_credit_named"' ) < strrpos( $block, '<p class="vectra-submit' ) && false === strpos( $block, 'not shown on the public map' ) && false !== strpos( $block, 'once a coordinator publishes the report' ) );
	}

	WP_CLI::log( 'The photos the public sees' );
	$as( $coordinator );
	$check( 'a new Spot shows its photos', Spots::photos_public( $s_org ) );
	$check( 'a Spot with no photo has none to show', array() === Spots::gallery( Spots::get( $s_org ) ) && array() === $public( $s_org )['gallery'] );
	$p1 = Photos::attach_upload( $s_org, $file( 'one.jpg' ) );
	$p2 = Photos::attach_upload( $s_org, $file( 'two.jpg' ) );
	$check( 'photos are attached', is_int( $p1 ) && is_int( $p2 ), array( $said( $p1 ), $said( $p2 ) ) );
	$gallery = Spots::gallery( Spots::get( $s_org ) );
	$check( 'a published Spot shows them, oldest first', 2 === count( $gallery ) && array( $p1, $p2 ) === array_column( $gallery, 'id' ) && '' !== $gallery[0]['large'] && '' !== $gallery[0]['full'], array_column( $gallery, 'id' ) );
	$shown = $public( $s_org )['gallery'];
	$check( 'the public copy has their addresses and no more', 2 === count( $shown ) && array( 'url', 'large', 'full', 'width', 'height', 'kind', 'caption', 'alt' ) === array_keys( $shown[0] ) && ! isset( $public( $s_org )['photos'] ) );
	$s_wait = $report( 'Credittest waiting. ' . $packout_run, 34.68, $ben, array(), false );
	Photos::attach_upload( $s_wait, $file( 'wait.jpg' ) );
	$check( 'a Spot that is not published shows none', array() === Spots::gallery( Spots::get( $s_wait ) ) );
	Spots::set_precision( $s_org, 'coarse' );
	$check( 'a Spot with an approximate location shows none', array() === Spots::gallery( Spots::get( $s_org ) ) && array() === $public( $s_org )['gallery'] );
	Spots::set_precision( $s_org, 'exact' );
	delete_post_meta( $s_org, '_pos_photos_public' );
	$check( 'a Spot nobody has decided about shows its photos', Spots::photos_public( $s_org ) && 2 === count( $public( $s_org )['gallery'] ) );
	Spots::set_photos_public( $s_org, false );
	$check( 'a coordinator can hide them', ! Spots::photos_public( $s_org ) && array() === $public( $s_org )['gallery'] );
	$check( 'its crew still has them', 2 === count( Spots::payload( Spots::get( $s_org ), 'exact' )['photos'] ) );
	Spots::set_photos_public( $s_org, true );
	$check( 'and show them again', 2 === count( $public( $s_org )['gallery'] ) );

	WP_CLI::log( 'The page of a Spot' );
	$as( 0 );
	$html = $page_of( $s_org );
	$check( 'it opens with the header of a Place: the category, the title, the first photo behind them', false !== strpos( $html, 'pos-spot-page pos-place-page' ) && false !== strpos( $html, 'pos-place__hero--cover' ) && false !== strpos( $html, 'class="pos-place__cover' ) && false !== strpos( $html, '<p class="pos-place__kind">Cleanup and waste</p>' ) && 1 === preg_match( '#<h1 class="pos-place__name">[^<]+</h1>#', $html ) );
	$cover = wp_get_attachment_image_src( (int) $p1, 'full' );
	$check( 'the photo behind the title is the first one', $cover && false !== strpos( substr( $html, 0, (int) strpos( $html, 'pos-place__hero-inner' ) ), basename( (string) $cover[0] ) ) );
	$check( 'the header says the stage, and who reported it and when', 1 === preg_match( '#<p class="pos-place__facts"><span class="pos-spot-page__stage">Spotted</span>.*Reported [^<]+ by <a href="' . preg_quote( esc_url( (string) get_permalink( $team ) ), '#' ) . '">' . preg_quote( esc_html( $name ), '#' ) . '</a>#s', $html ) );
	$check( 'the detail is two halves: where to find it, then the photos', false === strpos( $html, 'About this Spot' ) && 1 === preg_match( '#<div class="pos-spot-page__split"><div class="pos-spot-page__about">.*</div><div class="pos-spot-page__photos">#s', $html ) );
	$check( 'the description is in the header, and the location stays below', false !== strpos( $html, 'Credittest tires at the turnout.' ) && false === strpos( $html, '<dt>Category</dt>' ) && false === strpos( $html, '<dt>Stage</dt>' ) && false !== strpos( $html, '<dt>Location</dt>' ) );
	$check( 'the photos are a slider with every photo, each opening larger', 2 === substr_count( $html, '<figure class="pos-slider__slide">' ) && false !== strpos( $html, 'aria-roledescription="carousel"' ) && false !== strpos( $html, 'data-pos-thumbs' ) && false !== strpos( $html, 'alt="Photo 1 of 2 from the report"' ) && wp_script_is( 'packout-slider', 'enqueued' ) );
	$check( 'it offers the two things a visitor can do', false !== strpos( $html, 'href="#pos-claim"' ) && false !== strpos( $html, 'href="#pos-update"' ) && false !== strpos( $html, 'id="pos-claim"' ) && false !== strpos( $html, 'id="pos-update"' ) );
	$check( 'the activity starts with the report', 1 === preg_match( '#<section class="pos-place__section pos-activity pos-ws" id="pos-activity"><h2>Activity</h2><ol class="pos-activity__list"><li class="pos-activity__item pos-activity__item--report">.*Reported by <a #s', $html ) );
	$check( 'a visitor is asked to sign in to share an update, and gets no form', false !== strpos( $html, 'Sign in to share an update' ) && false === strpos( $html, 'name="packout_update"' ) );
	$check( 'the page names nobody who did not ask', false === strpos( $html, 'Testperson' ) && false === strpos( $html, '@example.test' ) );

	$html = $page_of( $s_self );
	$check( 'a Spot with no photo has a plain header and says so', false === strpos( $html, 'pos-place__hero--cover' ) && false === strpos( $html, 'pos-slider' ) && false !== strpos( $html, 'No photos of this Spot are shown.' ) && false !== strpos( $html, 'by an individual' ) );
	Spots::set_precision( $s_org, 'coarse' );
	$html = $page_of( $s_org );
	$check( 'a Spot with an approximate location shows no photo, and says who has them', false === strpos( $html, 'pos-place__hero--cover' ) && false === strpos( $html, 'pos-slider' ) && false !== strpos( $html, 'shared with the crew that takes it on' ) && false !== strpos( $html, '<dt>Approximate location</dt>' ) );
	Spots::set_precision( $s_org, 'exact' );
	$html = $page_of( $s_named );
	$check( 'a name a coordinator took off is off the page', false === strpos( $html, 'Ben Testperson' ) );

	WP_CLI::log( 'Missions and cleanups in the activity' );
	$as( $ana );
	$plan            = static fn( string $title, array $spots, bool $shared, int $owner ): array => array(
		'title'  => $title . ' ' . $packout_run,
		'team'   => $owner,
		'spots'  => $spots,
		'start'  => '2027-05-01',
		'end'    => '2027-05-02',
		'public' => $shared,
	);
	$m_open          = Missions::save( 0, $plan( 'Credittest open day', array( $s_org ), true, $team ), $ana );
	$packout_posts[] = $m_open;
	$m_shut          = Missions::save( 0, $plan( 'Credittest closed day', array( $s_both ), false, $team ), $ana );
	$packout_posts[] = $m_shut;
	$check( 'the missions are in place', is_int( $m_open ) && is_int( $m_shut ), array( $said( $m_open ), $said( $m_shut ) ) );
	$as( 0 );
	$html = $page_of( $s_org );
	$check( 'a public mission is named and linked, with its organization, its stage, and its days', 1 === preg_match( '#pos-activity__item--mission">.*Mission: <a href="' . preg_quote( esc_url( (string) get_permalink( (int) $m_open ) ), '#' ) . '">Credittest open day [^<]+</a>.*<p>Planned <span aria-hidden="true">·</span> <a href="' . preg_quote( esc_url( (string) get_permalink( $team ) ), '#' ) . '">[^<]+</a> <span aria-hidden="true">·</span> [^<]*2027[^<]*</p>#s', $html ), substr( $html, (int) strpos( $html, 'pos-activity__list' ), 900 ) );
	$check( 'a planned mission is above the report', strpos( $html, 'pos-activity__item--mission' ) < strpos( $html, 'pos-activity__item--report' ) );
	$html = $page_of( $s_both );
	$check( 'a mission that is not public is only said to exist', false !== strpos( $html, 'A mission took this Spot on' ) && false === strpos( $html, 'Credittest closed day' ) && false === strpos( $html, '2027' ) && false === strpos( $html, (string) get_permalink( (int) $m_shut ) ) );

	$as( $ana );
	Missions::set_status( (int) $m_open, 'in_progress' );
	Spots::mark_cleaned( $s_org, $ben, (int) $m_open );
	Impact::flush();
	$as( 0 );
	$html = $page_of( $s_org );
	$check( 'a Spot cleaned on a mission says the organization cleaned it, before any record is written', 1 === preg_match( '#pos-activity__item--cleanup">.*Cleaned by <a href="' . preg_quote( esc_url( (string) get_permalink( $team ) ), '#' ) . '">.*On the mission <a href="' . preg_quote( esc_url( (string) get_permalink( (int) $m_open ) ), '#' ) . '">#s', $html ) && 1 === substr_count( $html, 'pos-activity__item--cleanup' ) && false === strpos( $html, 'Testperson' ) );
	$as( $ana );
	$record = Impact::save_cleanup(
		$s_org,
		array(
			'narrative'     => 'Private words of the crew. ' . $packout_run,
			'hours'         => 3,
			'people'        => 2,
			'bags'          => 4,
			'bags_source'   => 'measured',
			'pounds'        => 60.5,
			'pounds_source' => 'measured',
		),
		$ana
	);
	if ( is_int( $record ) ) {
		$packout_posts[] = $record;
	}
	Impact::flush();
	$as( 0 );
	$html = $page_of( $s_org );
	$check( 'with a record there is still one cleanup, and no figures until a coordinator counts it', is_int( $record ) && 1 === substr_count( $html, 'pos-activity__item--cleanup' ) && false === strpos( $html, 'pos-activity__figures' ), $said( $record ) );
	$check( 'what the crew wrote for the coordinators is not on the page', false === strpos( $html, 'Private words of the crew.' ) );
	Impact::set_visible( (int) $record, true, $coordinator );
	$html = $page_of( $s_org );
	$check( 'a counted record adds its figures', 1 === preg_match( '#<p class="pos-activity__figures">2 people · 4 bags · 60\.5 lb</p>#', $html ), substr( $html, (int) strpos( $html, 'pos-activity__item--cleanup' ), 600 ) );
	$check( 'the newest is first: the cleanup, then the mission, then the report', strpos( $html, 'pos-activity__item--cleanup' ) < strpos( $html, 'pos-activity__item--report' ) && 'report' === array_slice( Spot_Page::events( Spots::get( $s_org ) ), -1 )[0]['kind'] );
	$check( 'a cleaned Spot no longer offers “I cleaned this Spot”', false === strpos( $html, 'href="#pos-claim"' ) );

	// A return visit.
	$visit = Impact::save_visit( $s_org, Impact::STILL_CLEAR, 'Looked fine. ' . $packout_run, $dee );
	if ( is_int( $visit ) ) {
		$packout_posts[] = $visit;
	}
	$html = $page_of( $s_org );
	$check( 'a return visit says what was found, and not who looked', is_int( $visit ) && false !== strpos( $html, 'pos-activity__item--visit' ) && false !== strpos( $html, 'A return visit found it still clear' ) && false === strpos( $html, 'Dee Testperson' ) && false === strpos( $html, 'Looked fine.' ), $said( $visit ) );

	// A person cleans a Spot on their own, from its page.
	$claim = Claims::submit( $s_self, array( 'note' => 'Bagged it. ' . $packout_run ), $file( 'after.jpg' ), $dee );
	foreach ( Impact::of_spot( $s_self ) as $made ) {
		$packout_posts[] = $made->ID;
	}
	$html = $page_of( $s_self );
	$check( 'a person who cleans a Spot on their own is “an individual”', ! is_wp_error( $claim ) && false !== strpos( $html, 'Cleaned by an individual' ) && false === strpos( $html, 'Dee Testperson' ) && false === strpos( $html, 'Bagged it.' ), $said( $claim ) );
	$claim = Claims::submit(
		$s_alone,
		array(
			'note'  => 'Bagged this one too. ' . $packout_run,
			'named' => true,
		),
		$file( 'after-2.jpg' ),
		$dee
	);
	foreach ( Impact::of_spot( $s_alone ) as $made ) {
		$packout_posts[] = $made->ID;
	}
	$html = $page_of( $s_alone );
	$check( 'and is named when they tick the box', ! is_wp_error( $claim ) && false !== strpos( $html, 'Cleaned by Dee Testperson' ), $said( $claim ) );
	$after = Spots::gallery( Spots::get( $s_alone ) );
	$check( 'the photo of a cleanup is among the Spot\'s photos, and says what it is', 1 === count( $after ) && 'cleanup' === $after[0]['kind'] && false !== strpos( $html, 'pos-slider__caption' ) && false !== strpos( $html, 'After the cleanup' ), array_column( $after, 'kind' ) );
	$as( 0 );
	$form = Claims::section( Spots::get( $s_named ) );
	$check( 'the cleanup form asks whether to show the name', false !== strpos( $form, 'name="named"' ) && false !== strpos( $form, 'Show my name on this page' ) );

	WP_CLI::log( 'Updates on a Spot' );
	$packout_mail = array();
	$check( 'a visitor cannot leave one', 'packout_signin' === $code( Updates::add( $s_named, 0, 'Hello.' ) ) );
	$check( 'an update needs words', 'packout_update_empty' === $code( Updates::add( $s_named, $dee, "  \n " ) ) );
	$check( 'and not too many of them', 'packout_update_long' === $code( Updates::add( $s_named, $dee, str_repeat( 'a', Updates::LENGTH + 1 ) ) ) );
	$check( 'a Spot that is not published takes none', 'packout_not_found' === $code( Updates::add( $s_wait, $dee, 'Hello.' ) ) && ! Updates::open( Spots::get( $s_wait ) ) );
	$first = Updates::add( $s_named, $dee, "The road washed out past the gate.\n<script>alert(1)</script> Bring a high-clearance truck. " . $packout_run );
	$check( 'a signed-in person leaves an update', is_int( $first ) && 1 === count( Updates::of( $s_named ) ), $said( $first ) );
	$check( 'it is credited to “an individual” unless they ask', is_int( $first ) && 'an individual' === Updates::from( Updates::get( $first ) )['label'] );
	$second = Updates::add( $s_named, $ben, 'We can bring the trailer on the 12th. ' . $packout_run, $team, true );
	$check( 'a person in an organization posts for it', is_int( $second ) && Updates::from( Updates::get( $second ) )['label'] === $name );
	$third = Updates::add( $s_named, $dee, 'Checked again today. ' . $packout_run, $team, true );
	$check( 'nobody posts for an organization they are not in, and a name shows when asked', is_int( $third ) && 'Dee Testperson' === Updates::from( Updates::get( $third ) )['label'] );
	$told = $mail_to( (string) get_option( 'admin_email' ), 'New update on' );
	$check( 'the coordinators are told of each one, with what it says', 3 === count( $told ) && false !== strpos( $told[0]['message'], 'The road washed out past the gate.' ) && false !== strpos( $told[0]['message'], '#pos-activity' ), count( $told ) );

	$html = $page_of( $s_named );
	$check( 'the page shows them, newest first, as plain text', 3 === substr_count( $html, 'pos-activity__item--update' ) && strpos( $html, 'Checked again today.' ) < strpos( $html, 'We can bring the trailer' ) && strpos( $html, 'We can bring the trailer' ) < strpos( $html, 'The road washed out' ) && false === strpos( $html, '<script>alert' ) );
	$check( 'each says who it is from', false !== strpos( $html, 'Update from Dee Testperson' ) && false !== strpos( $html, 'Update from an individual' ) && 1 === preg_match( '#Update from <a href="[^"]+">' . preg_quote( esc_html( $name ), '#' ) . '</a>#', $html ) && false === strpos( $html, 'Ben Testperson' ) );
	$check( 'a visitor sees no way to remove one', false === strpos( $html, 'value="remove"' ) );
	$as( $dee );
	$html = $page_of( $s_named );
	$check( 'a signed-in person gets the form, with the two questions', false !== strpos( $html, 'name="packout_update" value="add"' ) && false !== strpos( $html, 'name="text"' ) && false !== strpos( $html, 'name="pos_update_named"' ) && false === strpos( $html, 'Sign in to share an update' ) );
	$check( 'and can remove their own updates, after being asked', 2 === substr_count( $html, 'name="packout_update" value="remove"' ) && false !== strpos( $html, 'data-pos-confirm="Remove this update?"' ) );
	$as( $ben );
	$html = $page_of( $s_named );
	$check( 'a person in an organization is asked which to post for', false !== strpos( $html, 'name="pos_update_team"' ) && 1 === substr_count( $html, 'name="packout_update" value="remove"' ) );
	$check( 'nobody removes another person\'s update', 'packout_forbidden' === $code( Updates::remove( (int) $first, $ben ) ) && null !== Updates::get( (int) $first ) );
	$check( 'its author does', true === Updates::remove( (int) $first, $dee ) && null === get_post( (int) $first ) && 2 === count( Updates::of( $s_named ) ) );
	$as( $coordinator );
	$html = $page_of( $s_named );
	$check( 'a coordinator can remove any', 2 === substr_count( $html, 'name="packout_update" value="remove"' ) && true === Updates::remove( (int) $second, $coordinator ) && 1 === count( Updates::of( $s_named ) ) );

	// The limit of a day.
	$as( $fay );
	$made = 0;
	for ( $i = 0; $i < Updates::DAILY; $i++ ) {
		$made += is_int( Updates::add( $s_self, $fay, 'Update number ' . $i . '. ' . $packout_run ) ) ? 1 : 0;
	}
	$check( 'a person leaves up to ten in a day', Updates::DAILY === $made && 'packout_update_limit' === $code( Updates::add( $s_self, $fay, 'One more. ' . $packout_run ) ), $made );
	delete_transient( 'pos_limit_' . md5( 'update:' . $fay ) );
	delete_transient( 'pos_limit_' . md5( 'update:' . $dee ) );
	delete_transient( 'pos_limit_' . md5( 'update:' . $ben ) );

	$check( 'an update is not a page, and is not found by a search', ! is_post_type_viewable( Updates::TYPE ) && true === get_post_type_object( Updates::TYPE )->exclude_from_search );
	$left = Updates::of( $s_self );
	wp_delete_post( $s_self, true );
	$check( 'the updates of a Spot go with it', Updates::DAILY === count( $left ) && null === get_post( $left[0]->ID ) && array() === Updates::of( $s_self ) );
} finally {
	foreach ( array_filter( $packout_posts, 'is_int' ) as $packout_spot ) {
		foreach ( Updates::of( $packout_spot ) as $packout_update ) {
			wp_delete_post( $packout_update->ID, true );
		}
	}
	remove_filter( 'pre_wp_mail', $keep_mail, 99 );
	$_GET     = array();
	$_POST    = array();
	$_REQUEST = array();
	if ( null === $packout_settings ) {
		delete_option( Settings::option() );
	} else {
		update_option( Settings::option(), $packout_settings, false );
	}

	foreach ( array_values( array_unique( array_filter( $packout_posts, 'is_int' ) ) ) as $post_id ) {
		if ( Schema::TEAM === get_post_type( $post_id ) ) {
			foreach ( Subteams::all( $post_id ) as $subteam ) {
				foreach ( array( 'logo', 'cover' ) as $image ) {
					if ( $subteam[ $image ] > 0 ) {
						wp_delete_attachment( $subteam[ $image ], true );
					}
				}
			}
			delete_option( 'packout_messages_' . $post_id );
		}
		foreach ( get_children(
			array(
				'post_parent' => $post_id,
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		) as $media ) {
			wp_delete_attachment( $media, true );
		}
		$cover = (int) get_post_meta( $post_id, '_pos_cover', true );
		if ( $cover > 0 ) {
			wp_delete_attachment( $cover, true );
		}
		wp_delete_post( (int) $post_id, true );
	}
	foreach ( $packout_files as $path ) {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
	Teams::flush();
	Missions::flush();
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array_unique( array_filter( $packout_users, 'is_int' ) ) as $id ) {
		delete_transient( 'pos_flash_' . $id );
		is_multisite() ? wpmu_delete_user( $id ) : wp_delete_user( $id );
	}
}

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( count( $packout_failures ) . ' failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
