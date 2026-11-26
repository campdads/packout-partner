<?php
/**
 * Phase 11 acceptance checks: a Spot's photos are shown unless a coordinator
 * hides them, and the photos of a cleanup are among them. Reported Spots on My
 * impact: the list, the pane of each Spot, changing a report, and the notes a
 * reporter keeps on a Spot, which are private until they tick a box.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-11.php
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
use PackOut\Core\App\Profile;
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
	$dee         = $user( 'pos_reporter', 'Dee' );

	$as( $coordinator );
	$name            = 'Notes Test Crew ' . $packout_run;
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
	Teams::flush();

	$report = static function ( string $what, float $lat, int $by, array $extra = array(), bool $publish = true ) use ( &$packout_posts ): int {
		$id              = Spots::create(
			array(
				'category'    => 'cleanup-waste',
				'description' => $what,
				'landmark'    => 'Notetest gate',
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
	// One pane of a page, cut out of it.
	$pane_of = static function ( string $html, string $id ): string {
		$pane = (string) strstr( $html, 'id="pos-pane-' . $id . '"' );
		return substr( $pane, 0, (int) strpos( $pane . '<section class="packout-drawer__pane', '<section class="packout-drawer__pane', 10 ) );
	};

	WP_CLI::log( 'Photos are shown unless a coordinator hides them' );
	$s_org  = $report( 'Notetest tires. ' . $packout_run, 34.61, $ben, array( 'credit_team' => $team ) );
	$s_dee  = $report( 'Notetest cans. ' . $packout_run, 34.62, $dee, array( 'credit_team' => 0 ) );
	$s_wait = $report( 'Notetest waiting. ' . $packout_run, 34.63, $dee, array( 'credit_team' => 0 ), false );
	$s_bare = $report( 'Notetest no photo. ' . $packout_run, 34.64, $ben, array( 'credit_team' => 0 ) );
	$check( 'the fixtures are in place', is_int( $team ) && $s_org > 0 && $s_dee > 0 && $s_wait > 0 && $s_bare > 0, $said( $team ) );
	$p1 = Photos::attach_upload( $s_org, $file( 'one.jpg' ) );
	$p2 = Photos::attach_upload( $s_dee, $file( 'two.jpg' ) );
	$p3 = Photos::attach_upload( $s_wait, $file( 'three.jpg' ) );
	$check( 'a Spot stores nothing about its photos until a coordinator decides', '' === Spots::field( $s_org, 'photos_public' ) && Spots::photos_public( $s_org ) );
	$check( 'and shows them', is_int( $p1 ) && array( $p1 ) === array_column( Spots::gallery( Spots::get( $s_org ) ), 'id' ) && 1 === count( $public( $s_org )['gallery'] ), $said( $p1 ) );
	Spots::set_photos_public( $s_org, false );
	$check( 'a coordinator hides them', array() === Spots::gallery( Spots::get( $s_org ) ) && array() === $public( $s_org )['gallery'] && false === strpos( $page_of( $s_org ), 'pos-slider' ) );
	$check( 'the reporter still sees their own', array( $p1 ) === array_column( Spots::gallery( Spots::get( $s_org ), true ), 'id' ) );
	Spots::set_photos_public( $s_org, true );
	$check( 'and shows them again', 1 === count( $public( $s_org )['gallery'] ) );
	$check( 'a Spot that waits for review shows the public none, and its reporter theirs', array() === Spots::gallery( Spots::get( $s_wait ) ) && array( $p3 ) === array_column( Spots::gallery( Spots::get( $s_wait ), true ), 'id' ) );

	WP_CLI::log( 'The photos of a cleanup' );
	$as( 0 );
	$form = Claims::section( Spots::get( $s_dee ) );
	$check( 'the cleanup form says its photo is shown on the page', false !== strpos( $form, 'shown on the Spot&#039;s page' ) && false === strpos( $form, 'Only coordinators' ), substr( wp_strip_all_tags( $form ), 0, 200 ) );
	$claim  = Claims::submit( $s_dee, array( 'note' => 'Bagged it. ' . $packout_run ), $file( 'after.jpg' ), $ana );
	$claim2 = Claims::submit( $s_bare, array( 'note' => 'Bagged this too. ' . $packout_run ), $file( 'after-2.jpg' ), $ana );
	foreach ( array_merge( Impact::of_spot( $s_dee ), Impact::of_spot( $s_bare ) ) as $made ) {
		$packout_posts[] = $made->ID;
	}
	$gallery = Spots::gallery( Spots::get( $s_dee ) );
	$check( 'a cleanup\'s photo follows the report\'s', ! is_wp_error( $claim ) && 2 === count( $gallery ) && $p2 === $gallery[0]['id'] && array( 'report', 'cleanup' ) === array_column( $gallery, 'kind' ), $said( $claim ) );
	$check( 'and says what it is', '' === $gallery[0]['caption'] && 'After the cleanup' === $gallery[1]['caption'] && false !== strpos( $gallery[1]['alt'], 'after the cleanup' ) && false !== strpos( $gallery[0]['alt'], 'from the report' ) );
	$html = $page_of( $s_dee );
	$check( 'the page has both in its slider, the second with its caption', 2 === substr_count( $html, 'class="pos-slider__slide"' ) && 1 === substr_count( $html, 'class="pos-slider__caption"' ) && false !== strpos( $html, 'After the cleanup' ) );
	$shown = $public( $s_dee )['gallery'];
	$check( 'the map\'s card is given the caption too', 2 === count( $shown ) && 'After the cleanup' === $shown[1]['caption'] && ! isset( $shown[1]['id'] ) );
	$bare = Spots::gallery( Spots::get( $s_bare ) );
	$html = $page_of( $s_bare );
	$check( 'a Spot reported with no photo shows the photo of its cleanup', ! is_wp_error( $claim2 ) && 1 === count( $bare ) && 'cleanup' === $bare[0]['kind'] && 1 === substr_count( $html, 'class="pos-slider__slide"' ) && false === strpos( $html, 'pos-spot-page__no-photos' ), $said( $claim2 ) );
	Spots::set_photos_public( $s_dee, false );
	$check( 'hiding a Spot\'s photos hides the cleanup\'s too', array() === $public( $s_dee )['gallery'] && array( $p2 ) === array_column( Spots::gallery( Spots::get( $s_dee ), true ), 'id' ) );
	Spots::set_photos_public( $s_dee, true );
	Spots::set_precision( $s_dee, 'coarse' );
	$check( 'a Spot with an approximate location shows none of either', array() === Spots::gallery( Spots::get( $s_dee ) ) );
	Spots::set_precision( $s_dee, 'exact' );

	WP_CLI::log( 'Notes on a reported Spot' );
	$packout_mail = array();
	$check( 'only its reporter keeps notes on a Spot', 'packout_forbidden' === $code( Updates::note( $s_dee, $ben, 'Hello.' ) ) && 'packout_forbidden' === $code( Updates::note( $s_dee, 0, 'Hello.' ) ) && 'packout_forbidden' === $code( Updates::note( $s_dee, $coordinator, 'Hello.' ) ) );
	$check( 'a note needs words', 'packout_update_empty' === $code( Updates::note( $s_dee, $dee, "  \n" ) ) );
	$check( 'and not too many of them', 'packout_update_long' === $code( Updates::note( $s_dee, $dee, str_repeat( 'a', Updates::LENGTH + 1 ) ) ) );
	$n1 = Updates::note( $s_dee, $dee, 'Called the district office. <b>They</b> will send a truck. ' . $packout_run );
	$check( 'a reporter keeps a note', is_int( $n1 ) && 'private' === get_post_status( (int) $n1 ), $said( $n1 ) );
	$check( 'it is theirs alone: not an update of the Spot, and not on its page', array() === Updates::of( $s_dee ) && null === Updates::get( (int) $n1 ) && false === strpos( $page_of( $s_dee ), 'Called the district office' ) );
	$check( 'they find it among their notes', array( $n1 ) === wp_list_pluck( Updates::mine( $s_dee, $dee ), 'ID' ) && array() === Updates::mine( $s_dee, $ben ) );
	$check( 'nobody is told of a private note', array() === $mail_to( (string) get_option( 'admin_email' ), 'New update on' ) );
	$n2   = Updates::note( $s_dee, $dee, 'The gate is locked after 6 pm. ' . $packout_run, true );
	$html = $page_of( $s_dee );
	$check( 'a note with its box ticked is on the Spot\'s page as an update', is_int( $n2 ) && array( $n2 ) === wp_list_pluck( Updates::of( $s_dee ), 'ID' ) && false !== strpos( $html, 'The gate is locked after 6 pm.' ) && false !== strpos( $html, 'Update from an individual' ) && false === strpos( $html, 'Called the district office' ), $said( $n2 ) );
	$check( 'the coordinators are told of it', 1 === count( $mail_to( (string) get_option( 'admin_email' ), 'New update on' ) ) );
	$n3 = Updates::note( $s_org, $ben, 'We have a trailer for these. ' . $packout_run, true );
	$check( 'it is credited the way the report is', is_int( $n3 ) && Updates::from( Updates::get( (int) $n3 ) )['label'] === $name && 1 === preg_match( '#Update from <a href="[^"]+">' . preg_quote( esc_html( $name ), '#' ) . '</a>#', $page_of( $s_org ) ) );

	$check( 'nobody changes another person\'s note', 'packout_forbidden' === $code( Updates::edit( (int) $n1, $ben, 'Mine now.', true ) ) && 'packout_forbidden' === $code( Updates::edit( (int) $n1, $coordinator, 'Mine now.', true ) ) );
	$check( 'a changed note needs words too', 'packout_update_empty' === $code( Updates::edit( (int) $n1, $dee, ' ', false ) ) );
	$packout_mail = array();
	$done         = Updates::edit( (int) $n1, $dee, 'Called the district office. A truck comes Friday. ' . $packout_run, true );
	$html         = $page_of( $s_dee );
	$check( 'ticking the box of a note puts it on the page, with what it says now', true === $done && 2 === count( Updates::of( $s_dee ) ) && false !== strpos( $html, 'A truck comes Friday.' ) && 1 === count( $mail_to( (string) get_option( 'admin_email' ), 'New update on' ) ), $said( $done ) );
	$done = Updates::edit( (int) $n1, $dee, 'Called the district office. A truck comes Friday. ' . $packout_run, false );
	$check( 'unticking it takes it off, and keeps it', true === $done && array( $n2 ) === wp_list_pluck( Updates::of( $s_dee ), 'ID' ) && false === strpos( $page_of( $s_dee ), 'A truck comes Friday.' ) && 2 === count( Updates::mine( $s_dee, $dee ) ) );
	$check( 'a coordinator cannot remove a note only its author sees', ! Updates::can_remove( (int) $n1, $coordinator ) && 'packout_forbidden' === $code( Updates::remove( (int) $n1, $coordinator ) ) && null !== get_post( (int) $n1 ) );
	$check( 'and can remove one that is on the page', Updates::can_remove( (int) $n2, $coordinator ) );
	$check( 'its author removes either', true === Updates::remove( (int) $n1, $dee ) && null === get_post( (int) $n1 ) );

	$n4           = Updates::note( $s_wait, $dee, 'For the page, when there is one. ' . $packout_run, true );
	$packout_mail = array();
	$check( 'a note for the page of a Spot that waits for review is kept for it', is_int( $n4 ) && 'publish' === get_post_status( (int) $n4 ) && array() === $mail_to( (string) get_option( 'admin_email' ), 'New update on' ), $said( $n4 ) );
	$as( $coordinator );
	Spots::publish( $s_wait );
	$check( 'and is on the page once the Spot is published', false !== strpos( $page_of( $s_wait ), 'For the page, when there is one.' ) );

	WP_CLI::log( 'Changing a report' );
	$s_new = $report( 'Notetest to change. ' . $packout_run, 34.65, $dee, array( 'credit_team' => 0 ), false );
	$check( 'nobody changes another person\'s report', 'packout_forbidden' === $code( Profile::save_spot( $ben, $s_new, array( 'description' => 'Mine.' ) ) ) && null === Profile::own_spot( $ben, $s_new ) && null !== Profile::own_spot( $dee, $s_new ) );
	$done = Profile::save_spot(
		$dee,
		$s_new,
		array(
			'title'       => '',
			'category'    => 'waterways',
			'description' => 'A culvert full of bottles. ' . $packout_run,
			'landmark'    => 'Notetest culvert',
			'lat'         => '34.7',
			'lng'         => '-111.75',
		)
	);
	$now  = Spots::get( $s_new );
	$check( 'a reporter changes a report that waits for review', true === $done && 0 === strpos( $now->post_content, 'A culvert full of bottles.' ) && 'waterways' === Spots::category( $s_new )->slug && 'Notetest culvert' === Spots::field( $s_new, 'landmark' ) && array( 34.7, -111.75 ) === Spots::pin( $s_new ), $said( $done ) );
	$check( 'its title follows the change', false !== strpos( $now->post_title, 'Notetest culvert' ), $now->post_title );
	$check(
		'a pin that is not a place is refused',
		'packout_pin' === $code(
			Profile::save_spot(
				$dee,
				$s_new,
				array(
					'lat' => '95',
					'lng' => 'east',
				)
			)
		) && array(
			34.7,
			-111.75,
		) === Spots::pin( $s_new )
	);
	$check( 'a report still needs its description', 'packout_description' === $code( Profile::save_spot( $dee, $s_new, array( 'description' => ' ' ) ) ) );
	$done = Profile::save_spot(
		$dee,
		$s_new,
		array(
			'lat' => '',
			'lng' => '',
		)
	);
	$check( 'the pin can be taken off a report that has a landmark', true === $done && null === Spots::pin( $s_new ), $said( $done ) );
	$done = Profile::save_spot(
		$dee,
		$s_new,
		array(
			'credit_team'  => $team,
			'credit_named' => true,
		)
	);
	$check( 'a reporter is named when they ask, and cannot credit an organization they are not in', true === $done && 'Dee Testperson' === Spots::reported_by( Spots::get( $s_new ) )['label'], Spots::reported_by( Spots::get( $s_new ) )['label'] );
	Spots::publish( $s_new );
	$done = Profile::save_spot(
		$dee,
		$s_new,
		array(
			'description'  => 'Changed after review.',
			'credit_team'  => 0,
			'credit_named' => false,
		)
	);
	$check( 'after review the report is the coordinators\' to change, and the credit is still the reporter\'s', true === $done && 0 === strpos( Spots::get( $s_new )->post_content, 'A culvert full of bottles.' ) && 'an individual' === Spots::reported_by( Spots::get( $s_new ) )['label'] );
	$done = Profile::save_spot(
		$ben,
		$s_org,
		array(
			'credit_team'  => 0,
			'credit_named' => false,
		)
	);
	$check( 'a person in an organization can report as themself after all', true === $done && 'an individual' === Spots::reported_by( Spots::get( $s_org ) )['label'] );
	Profile::save_spot( $ben, $s_org, array( 'credit_team' => $team ) );

	WP_CLI::log( 'Reported Spots on My impact' );
	$s_pend = $report( 'Notetest pending. ' . $packout_run, 34.66, $dee, array( 'credit_team' => 0 ), false );
	$as( $dee );
	$html = Profile::render( array( 'view' => 'impact' ) );
	$list = (string) strstr( $html, 'id="pos-impact-spots"' );
	$list = substr( $list, 0, (int) strpos( $list . 'pos-ws__panes', 'pos-ws__panes' ) );
	$mine = array( $s_dee, $s_wait, $s_new, $s_pend );
	$check( 'My impact has a section, Reported Spots, with the number of them', false !== strpos( $list, 'Reported Spots (4)' ) && false === strpos( $html, 'Spots you reported' ) );
	$check( 'it is a Data View that starts as a table, whose cards show the photo across the top', false !== strpos( $list, 'data-pos-view="my-spots"' ) && false !== strpos( $list, 'pos-view--photos' ) && 1 === preg_match( '#data-pos-setup="[^"]*&quot;layout&quot;:&quot;table&quot;#', $list ) );
	preg_match( '#class="pos-view__mount" data-config="([^"]*)"#', $list, $found );
	$config = json_decode( html_entity_decode( (string) ( $found[1] ?? '' ), ENT_QUOTES ), true );
	$rows   = is_array( $config ) ? $config['source']['rows'] : array();
	$by_id  = array_column( $rows, null, 'id' );
	sort( $mine );
	$listed = array_map( 'intval', array_column( $rows, 'id' ) );
	sort( $listed );
	$check( 'it lists the Spots the person reported, and nobody else\'s', $mine === $listed, $listed );
	$check( 'each row has its category, its stage, where the review stands, and its notes', isset( $by_id[ $s_dee ] ) && 'Cleaned' === $by_id[ $s_dee ]['stage'] && 'On the map' === $by_id[ $s_dee ]['review'] && 1 === $by_id[ $s_dee ]['notes'] && 'Waiting for review' === $by_id[ $s_pend ]['review'] && 'Waterways' === $by_id[ $s_new ]['category'], $by_id[ $s_dee ] ?? array() );
	$check( 'and its photo, when it has one', isset( $by_id[ $s_dee ] ) && false !== strpos( $by_id[ $s_dee ]['photo'], '/uploads/' ) && '' === $by_id[ $s_pend ]['photo'] && 'photo' === $config['view']['mediaField'] );
	$check( 'a row opens a pane', '#pos-pane-spot-' . $s_dee === $by_id[ $s_dee ]['url'] && false !== strpos( $html, 'id="pos-pane-spot-' . $s_dee . '"' ) && false === strpos( $html, 'id="pos-pane-spot-' . $s_org . '"' ) );
	$pane = $pane_of( $html, 'spot-' . $s_dee );
	$check( 'the pane has the details, the form, and the notes', false !== strpos( $pane, 'id="pos-spot-' . $s_dee . '-details"' ) && false !== strpos( $pane, 'id="pos-spot-' . $s_dee . '-edit"' ) && false !== strpos( $pane, 'id="pos-spot-' . $s_dee . '-notes"' ) );
	$check( 'its details show the photos, the report, where it stands, and a link to the public page', false !== strpos( $pane, 'pos-slider' ) && false !== strpos( $pane, 'Notetest cans.' ) && false !== strpos( $pane, 'On the map' ) && false !== strpos( $pane, 'href="' . esc_url( (string) get_permalink( $s_dee ) ) . '"' ) );
	$check( 'a reviewed report offers the credit and not the description', false !== strpos( $pane, 'name="packout_profile" value="spot_edit"' ) && false !== strpos( $pane, 'name="spot_credit_named"' ) && false === strpos( $pane, 'name="description"' ) && false !== strpos( $pane, 'A coordinator has reviewed this report' ) );
	$check( 'the notes have a form for a new one, and one for each note with its box', false !== strpos( $pane, 'name="packout_profile" value="note_add"' ) && 1 === substr_count( $pane, 'name="packout_profile" value="note_save"' ) && 1 === substr_count( $pane, 'name="packout_profile" value="note_remove"' ) && false !== strpos( $pane, 'The gate is locked after 6 pm.' ) && false !== strpos( $pane, 'Notes (1)' ) && false !== strpos( $pane, 'On the Spot&#039;s page' ) && false !== strpos( $pane, 'data-pos-confirm="Remove this note?"' ) );
	$pane = $pane_of( $html, 'spot-' . $s_pend );
	$check( 'a report that waits for review can be changed: its title, category, description, landmark, and pin', false !== strpos( $pane, 'name="description"' ) && false !== strpos( $pane, 'name="category"' ) && false !== strpos( $pane, 'name="landmark"' ) && false !== strpos( $pane, 'name="lat"' ) && false !== strpos( $pane, 'name="lng"' ) && false !== strpos( $pane, 'name="title"' ) && false !== strpos( $pane, 'Change the report' ) && false !== strpos( $pane, 'no public page' ) );
	$as( $coordinator );
	Spots::return_to_reporter( $s_pend, 'Which side of the road? ' . $packout_run );
	$as( $dee );
	$html = Profile::render( array( 'view' => 'impact' ) );
	$check( 'a report a coordinator returned says why', false !== strpos( $pane_of( $html, 'spot-' . $s_pend ), 'Which side of the road?' ) && false !== strpos( $html, 'Returned to you' ) );
	$as( $ana );
	$html = Profile::render( array( 'view' => 'impact' ) );
	$check( 'a person who reported nothing has no such section', false === strpos( $html, 'pos-impact-spots' ) && false === strpos( $html, 'pos-pane-spot-' ) );

	// A form that is refused comes back with its pane open and what was wrong.
	$as( $dee );
	$_POST = array(
		'packout_profile' => 'note_add',
		'spot'            => (string) $s_dee,
		'text'            => '   ',
		'public'          => '1',
		'_posnonce'       => wp_create_nonce( Profile::NONCE ),
	);
	Profile::handle();
	$html = Profile::render( array( 'view' => 'impact' ) );
	$pane = $pane_of( $html, 'spot-' . $s_dee );
	$check( 'a note that is refused opens its Spot again, on the notes, and says why', false !== strpos( $html, 'data-pos-reopen="spot-' . $s_dee . '"' ) && false !== strpos( $pane, 'Write the note first.' ) && false !== strpos( $pane, 'data-pos-open="pos-spot-' . $s_dee . '-notes"' ) && false !== strpos( $html, 'data-pos-open="pos-impact-spots"' ) );
	$_POST = array(
		'packout_profile' => 'note_save',
		'spot'            => (string) $s_dee,
		'note'            => (string) $n3,
		'text'            => 'Not mine.',
		'_posnonce'       => wp_create_nonce( Profile::NONCE ),
	);
	Profile::handle();
	$check( 'a form that names another person\'s note changes nothing', false !== strpos( Updates::get( (int) $n3, true )->post_content, 'We have a trailer' ) );
	$_POST = array();
	foreach ( array(
		'problem' => null,
		'failed'  => '',
		'spot'    => 0,
	) as $packout_name => $packout_value ) {
		$packout_property = new ReflectionProperty( Profile::class, $packout_name );
		$packout_property->setAccessible( true );
		$packout_property->setValue( null, $packout_value );
	}

	WP_CLI::log( 'Limits' );
	$made = 0;
	for ( $i = count( Updates::mine( $s_new, $dee ) ); $i < Updates::NOTES; $i++ ) {
		$made += is_int( Updates::note( $s_new, $dee, 'Note number ' . $i . '. ' . $packout_run ) ) ? 1 : 0;
	}
	$check( 'a Spot holds fifty of a person\'s notes', Updates::NOTES === $made && 'packout_note_limit' === $code( Updates::note( $s_new, $dee, 'One more. ' . $packout_run ) ), $made );
	delete_transient( 'pos_limit_' . md5( 'update:' . $dee ) );
	$made = 0;
	for ( $i = 0; $i < Updates::DAILY; $i++ ) {
		$made += is_int( Updates::note( $s_pend, $dee, 'Public note ' . $i . '. ' . $packout_run, true ) ) ? 1 : 0;
	}
	$check( 'a person puts up to ten on pages in a day, and keeps private ones after that', Updates::DAILY === $made && 'packout_update_limit' === $code( Updates::note( $s_pend, $dee, 'One more. ' . $packout_run, true ) ) && is_int( Updates::note( $s_pend, $dee, 'A private one. ' . $packout_run ) ), $made );
	delete_transient( 'pos_limit_' . md5( 'update:' . $dee ) );
	delete_transient( 'pos_limit_' . md5( 'update:' . $ben ) );
	$left = Updates::mine( $s_new, $dee );
	wp_delete_post( $s_new, true );
	$check( 'the notes of a Spot go with it', Updates::NOTES === count( $left ) && null === get_post( $left[0]->ID ) && array() === Updates::mine( $s_new, $dee ) );
} finally {
	foreach ( array_filter( $packout_posts, 'is_int' ) as $packout_spot ) {
		foreach ( get_posts(
			array(
				'post_type'   => Updates::TYPE,
				'post_status' => 'any',
				'post_parent' => $packout_spot,
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		) as $packout_update ) {
			wp_delete_post( (int) $packout_update, true );
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
