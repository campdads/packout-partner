<?php
/**
 * Phase 9 acceptance checks: the cover image of an organization and of a team,
 * the public page of an organization in the form of a Place's, and the public page of
 * a team: its address under the organization's, who sees it, what it shows, and
 * joining a team from it.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-9.php
 *
 * The script creates its own users, Spots, organizations, a campaign, and missions,
 * keeps mail in memory, and removes everything it created. It exits non-zero
 * when a check fails.
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Campaigns;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Page_Header;
use PackOut\Core\App\Rest;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Settings;
use PackOut\Core\App\Sponsors;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Subteams;
use PackOut\Core\App\Team_Page;
use PackOut\Core\App\Teams;
use PackOut\Core\App\Workspace;

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
	$cal         = $user( 'pos_reporter', 'Cal' );
	$dee         = $user( 'pos_reporter', 'Dee' );
	$eve         = $user( 'pos_reporter', 'Eve' );
	$fay         = $user( 'pos_reporter', 'Fay' );
	$gil         = $user( 'pos_reporter', 'Gil' );

	$as( $coordinator );
	$name            = 'Cover Test Crew ' . $packout_run;
	$team            = Teams::save(
		0,
		array(
			'name'        => $name,
			'description' => "A crew that walks the rim road every month.\n\nWe carry out what we find.",
			'lead'        => $ana,
			'listed'      => true,
		)
	);
	$packout_posts[] = $team;
	Teams::update_profile(
		$team,
		array(
			'region'  => 'Coconino rim',
			'website' => 'https://example.org/crew',
		),
		$ana
	);
	Teams::add_member( $team, get_userdata( $ben )->user_login );
	Teams::add_member( $team, get_userdata( $cal )->user_login );
	Teams::add_member( $team, get_userdata( $gil )->user_login );
	Teams::set_colead( $team, $gil, true, $ana );
	$north = Subteams::save(
		$team,
		0,
		array(
			'name' => 'North Wash',
			'lead' => $ben,
		),
		$ana
	);
	$south = Subteams::save( $team, 0, array( 'name' => 'South Wash' ), $ana );
	$brand = Sponsors::save(
		$team,
		0,
		array(
			'name' => 'Test Outfitters ' . $packout_run,
			'url'  => 'https://example.org/outfitters',
		),
		$ana
	);

	$waiting         = Teams::propose( $fay, array( 'name' => 'Waiting Test Crew ' . $packout_run ) );
	$packout_posts[] = $waiting;

	$spot = static function ( string $what, float $lat, int $by ) use ( &$packout_posts ): int {
		$id              = Spots::create(
			array(
				'category'    => 'cleanup-waste',
				'description' => $what,
				'lat'         => $lat,
				'lng'         => -111.8,
			),
			$by
		);
		$packout_posts[] = $id;
		Spots::publish( $id );
		return $id;
	};
	$s1   = $spot( 'Covertest tires at the turnout. ' . $packout_run, 34.61, $dee );
	$s2   = $spot( 'Covertest cans in the fire ring. ' . $packout_run, 34.62, $dee );
	$s3   = $spot( 'Covertest glass by the gate. ' . $packout_run, 34.63, $dee );

	$tab  = static function ( string $view, int $who, int $of = 0 ) use ( $team, $as ): string {
		$as( $who );
		$_GET = array(
			'team' => (string) ( $of > 0 ? $of : $team ),
			'view' => $view,
		);
		$html = Workspace::render( array() );
		$_GET = array();
		return $html;
	};
	$sub  = static fn( int $id ): array => (array) Subteams::get( $team, $id );
	$said = static fn( $thing ): string => is_wp_error( $thing ) ? $thing->get_error_message() : '';

	WP_CLI::log( 'An organization\'s cover image' );
	$check( 'an organization has no cover until one is uploaded', 0 === Teams::cover( $team ) );
	$check( 'a member cannot upload one', 'packout_forbidden' === $code( Teams::set_cover( $team, $file( 'cover.jpg' ), $cal ) ) && 0 === Teams::cover( $team ) );
	$check( 'nor can a person who is not in the organization', 'packout_forbidden' === $code( Teams::set_cover( $team, $file( 'cover.jpg' ), $dee ) ) );
	$check( 'an organization that waits for a coordinator cannot have one yet', 'packout_team_pending' === $code( Teams::set_cover( $waiting, $file( 'cover.jpg' ), $fay ) ) );
	$check( 'a file that is not an image is refused', is_wp_error( Teams::set_cover( $team, $file( 'cover.jpg', false ), $ana ) ) && 0 === Teams::cover( $team ) );
	$first = Teams::set_cover( $team, $file( 'cover.jpg' ), $ana );
	$check( 'the lead uploads a cover', is_int( $first ) && $first > 0 && Teams::cover( $team ) === $first, $said( $first ) );
	$check( 'it is stored as WebP, as a campaign\'s is', 'image/webp' === get_post_mime_type( (int) $first ) && Page_Header::cover( $team ) === $first, get_post_mime_type( (int) $first ) );
	$second = Teams::set_cover( $team, $file( 'cover-2.jpg' ), $gil );
	$check( 'a co-lead replaces it, and the old image is deleted', is_int( $second ) && $second !== $first && Teams::cover( $team ) === $second && null === get_post( (int) $first ) );
	$html = $tab( 'settings', $ana );
	$check( 'Settings has a Cover image section with the image, the upload, and a removal that asks first', false !== strpos( $html, 'id="pos-settings-cover"' ) && false !== strpos( $html, 'value="team_cover"' ) && false !== strpos( $html, 'pos-ws__cover' ) && 1 === preg_match( '#value="team_cover_remove".*?<button class="pos-ws__button pos-ws__button--danger"[^>]*data-pos-confirm="[^"]+"#s', $html ) );
	$check( 'and the box that names the leads, unticked', 1 === preg_match( '#<input[^>]*name="show_leads"[^>]*>#', $html, $found ) && false === strpos( $found[0], 'checked' ) );
	$html = $tab( 'settings', $fay, (int) $waiting );
	$check( 'a waiting organization is told when it can add one', false !== strpos( $html, 'A cover image can be added once a coordinator has approved the organization.' ) && false === strpos( $html, 'value="team_cover"' ) );

	WP_CLI::log( 'The public page of an organization' );
	$as( 0 );
	$html = $page_of( $team );
	$check( 'it opens with the header of a Place, on the cover', false !== strpos( $html, 'pos-team-page' ) && false !== strpos( $html, 'pos-place__hero--cover' ) && false !== strpos( $html, 'class="pos-place__cover"' ) && false !== strpos( $html, '<p class="pos-place__kind">Organization</p>' ) );
	$check( 'with the name as the heading, the description, and the facts', false !== strpos( $html, '<h1 class="pos-place__name">' . esc_html( $name ) . '</h1>' ) && false !== strpos( $html, 'A crew that walks the rim road every month.' ) && 1 === preg_match( '#<p class="pos-place__facts">.*Coconino rim.*4 people.*example\.org#s', $html ) );
	$check( 'and the sponsor under Supported by', 1 === preg_match( '#<aside class="pos-place__support".*Test Outfitters#s', $html ) );
	$check( 'how people join is in the header, with no separate about section', false === strpos( $html, 'About this organization' ) && false === strpos( $html, 'Works in' ) && false !== strpos( $html, 'Ask to join' ) );
	$check( 'it has the way to join, as before', false !== strpos( $html, 'pos-team-page__join' ) && false !== strpos( $html, 'id="pos-join"' ) && false !== strpos( $html, 'Sign in to join' ) );
	$check( 'it names no person', false === strpos( $html, 'Testperson' ) );
	$check( 'it lists no team that has no page', false === strpos( $html, 'pos-team-page__subteams' ) && false === strpos( $html, 'North Wash' ) );
	$check( 'and says no cleanup has been counted yet', false !== strpos( $html, 'No cleanup by this organization has been counted yet.' ) );
	Teams::update_profile( $team, array( 'show_leads' => true ), $ana );
	$html = $page_of( $team );
	$check( 'an organization that chose to names its lead and co-lead, and no member', true === Teams::shows_leads( $team ) && false !== strpos( $html, 'Ana Testperson' ) && false !== strpos( $html, 'Gil Testperson' ) && false === strpos( $html, 'Cal Testperson' ) && false === strpos( $html, '@example.test' ) );
	$check( 'a member cannot turn that on or off', is_wp_error( Teams::update_profile( $team, array( 'show_leads' => false ), $cal ) ) && true === Teams::shows_leads( $team ) );
	Teams::update_profile( $team, array( 'show_leads' => false ), $ana );
	$check( 'removing the cover is the lead\'s to do', 'packout_forbidden' === $code( Teams::set_cover( $team, null, $cal ) ) && Teams::cover( $team ) === $second );
	$check( 'and deletes the image', 0 === Teams::set_cover( $team, null, $ana ) && 0 === Teams::cover( $team ) && null === get_post( (int) $second ) );
	$html = $page_of( $team );
	$check( 'without a cover the header is still there', false !== strpos( $html, 'pos-place__hero' ) && false === strpos( $html, 'pos-place__hero--cover' ) );
	$kept = Teams::set_cover( $team, $file( 'cover-3.jpg' ), $ana );

	WP_CLI::log( 'A team\'s address and page settings' );
	$check( 'a team starts with an address from its name, no page, and its lead choosing its members', 'north-wash' === $sub( $north )['slug'] && 'south-wash' === $sub( $south )['slug'] && false === $sub( $north )['public'] && 'closed' === $sub( $north )['join'] && true === $sub( $north )['leads'] );
	$check( 'its page would be under the organization\'s address', trailingslashit( (string) get_permalink( $team ) ) . 'north-wash/' === Subteams::url( $team, $north ), Subteams::url( $team, $north ) );
	// A team stored before teams had pages: no address, no settings.
	$stored   = Schema::stored_list( $team, 'subteams' );
	$stored[] = array(
		'id'      => 901,
		'name'    => 'Old Guard',
		'lead'    => 0,
		'members' => array( $cal ),
	);
	$stored[] = array(
		'id'   => 902,
		'name' => '77',
	);
	Schema::write_list( $team, 'subteams', $stored );
	Teams::flush();
	$check( 'a team from before gets its address from its name, and keeps its people', 'old-guard' === $sub( 901 )['slug'] && array( $cal ) === $sub( 901 )['members'] && false === $sub( 901 )['public'] && 'closed' === $sub( 901 )['join'] );
	$check( 'a name that makes no address gets one from its number', 'sub-team-902' === $sub( 902 )['slug'], $sub( 902 )['slug'] );
	Subteams::remove( $team, 901, $ana );
	Subteams::remove( $team, 902, $ana );

	$check( 'a member cannot change a team\'s page', 'packout_forbidden' === $code( Subteams::update_page( $team, $north, array( 'public' => true ), $cal ) ) && false === $sub( $north )['public'] );
	$check( 'nor can the lead of another team', 'packout_forbidden' === $code( Subteams::update_page( $team, $south, array( 'public' => true ), $ben ) ) );
	$check(
		'its lead writes what it does and makes the page public',
		true === Subteams::update_page(
			$team,
			$north,
			array(
				'about'  => "We look after the north wash.\n\nSecond Saturdays.",
				'public' => true,
			),
			$ben
		) && true === $sub( $north )['public'] && 0 === strpos(
			$sub( $north )['about'],
			'We look after'
		)
	);
	$check( 'a key left out is not changed', 'closed' === $sub( $north )['join'] && true === $sub( $north )['leads'] && 'north-wash' === $sub( $north )['slug'] );
	$check( 'a description longer than 2,000 characters is refused', 'packout_subteam_about' === $code( Subteams::update_page( $team, $north, array( 'about' => str_repeat( 'word ', 500 ) ), $ben ) ) );
	$check( 'and so is a way of joining that does not exist', 'packout_subteam_join' === $code( Subteams::update_page( $team, $north, array( 'join' => 'anyone' ), $ben ) ) );
	$early = Subteams::save( (int) $waiting, 0, array( 'name' => 'Early Birds' ), $fay );
	$check( 'a team of a waiting organization cannot go public', is_int( $early ) && 'packout_team_pending' === $code( Subteams::update_page( (int) $waiting, (int) $early, array( 'public' => true ), $fay ) ) );

	foreach ( array( 'ab', 'North Wash', '-north', '12345', 'embed', 'feed', 'page', 'trackback', 'attachment', 'comment-page-2' ) as $bad ) {
		$refused[ $bad ] = '' !== Subteams::address_problem( $team, $north, $bad );
	}
	$check( 'an address must be 3 to 60 lower-case letters, digits, and hyphens, with a letter, and not a word WordPress keeps', array() === array_filter( $refused, static fn( $no ) => ! $no ), $refused );
	$check( 'an address another team has is taken', '' !== Subteams::address_problem( $team, $north, 'south-wash' ) && 'packout_subteam_address' === $code( Subteams::update_page( $team, $north, array( 'address' => 'south-wash' ), $ben ) ) );
	$check( 'its own address is fine, and so is a free one', '' === Subteams::address_problem( $team, $north, 'north-wash' ) && '' === Subteams::address_problem( $team, $north, 'north-crew' ) );
	$check( 'the lead changes the address', true === Subteams::update_page( $team, $north, array( 'address' => ' North-Crew ' ), $ben ) && 'north-crew' === $sub( $north )['slug'] && array( 'north-wash' ) === $sub( $north )['old'] );
	$check( 'the old address still finds it, as one it had before', array( $north, false ) === array( Subteams::at( $team, 'north-wash' )[0]['id'], Subteams::at( $team, 'north-wash' )[1] ) && 'moved' === Team_Page::lookup( $team, 'north-wash' )[1] );
	$check( 'and no other team can be given it', '' !== Subteams::address_problem( $team, $south, 'north-wash' ) );
	$check( 'changing it back drops it from the old ones', true === Subteams::update_page( $team, $north, array( 'address' => 'north-wash' ), $ben ) && array( 'north-crew' ) === $sub( $north )['old'] );
	$crew = Subteams::save( $team, 0, array( 'name' => 'North Crew' ), $ana );
	$check( 'a new team is not given an address that forwards elsewhere', is_int( $crew ) && 'north-crew' !== $sub( (int) $crew )['slug'], $sub( (int) $crew )['slug'] ?? '' );
	Subteams::remove( $team, (int) $crew, $ana );

	$as( $ben );
	$request = new WP_REST_Request( 'GET', '/' . Rest::NAMESPACE . '/teams/' . $team . '/subteams/' . $north . '/address' );
	$request->set_param( 'address', 'south-wash' );
	$answer = rest_do_request( $request );
	$check( 'the field is told a taken address is taken as it is typed', 200 === $answer->get_status() && false === $answer->get_data()['available'] && '' !== $answer->get_data()['message'] );
	$request->set_param( 'address', 'north-rim' );
	$answer = rest_do_request( $request );
	$check( 'and where a free one would be', true === $answer->get_data()['available'] && Subteams::url( $team, $north, 'north-rim' ) === $answer->get_data()['url'], $answer->get_data() );
	$as( $cal );
	$check( 'a member is not answered', in_array( rest_do_request( $request )->get_status(), array( 401, 403 ), true ) );

	global $wp_rewrite;
	$rules = (array) $wp_rewrite->wp_rewrite_rules();
	$mine  = array_filter( $rules, static fn( $to ) => false !== strpos( (string) $to, Team_Page::QUERY_VAR . '=' ) );
	$check( 'the site knows the address of a team', 1 === count( $mine ) && 1 === preg_match( '#^' . key( $mine ) . '#', 'team/some-crew/north-wash/' ) && 1 === preg_match( '#^' . key( $mine ) . '#', 'team/some-crew/north-wash' ), array_keys( $mine ) );
	foreach ( array( 'team/some-crew/embed/', 'team/some-crew/feed/', 'team/some-crew/2/', 'team/some-crew/page/', 'team/some-crew/north/extra/', 'team/some-crew/' ) as $path ) {
		$left[ $path ] = 0 === preg_match( '#^' . key( $mine ) . '#', $path );
	}
	$check( 'and leaves WordPress the addresses it uses under an organization', array() === array_filter( $left, static fn( $ok ) => ! $ok ), $left );

	WP_CLI::log( 'Who is shown a team\'s page' );
	$as( 0 );
	$check( 'a visitor is shown a public team of a listed organization', true === Subteams::shown( $team, $north ) && 'show' === Team_Page::lookup( $team, 'north-wash' )[1] );
	$check( 'and not one that has not turned its page on', false === Subteams::shown( $team, $south ) && 'none' === Team_Page::lookup( $team, 'south-wash' )[1] && '' === $page_of( $team, 'south-wash' ) );
	$check( 'nor an address no team has', 'none' === Team_Page::lookup( $team, 'nowhere' )[1] );
	$as( $cal );
	$check( 'a member is not shown it either', 'none' === Team_Page::lookup( $team, 'south-wash' )[1] );
	$as( $ana );
	$check( 'the people who run it can open it to see what it will look like', 'show' === Team_Page::lookup( $team, 'south-wash' )[1] && false !== strpos( $page_of( $team, 'south-wash' ), 'pos-subteam-page__preview' ) );
	$check( 'only the public ones are listed', array( $north ) === wp_list_pluck( Subteams::listed( $team ), 'id' ) );
	Teams::update_profile( $team, array( 'listed' => false ), $ana );
	$as( 0 );
	$check( 'a team of an organization that is not listed is not shown', false === Subteams::shown( $team, $north ) && 'none' === Team_Page::lookup( $team, 'north-wash' )[1] && array() === Subteams::listed( $team ) );
	Teams::update_profile( $team, array( 'listed' => true ), $ana );

	WP_CLI::log( 'A team\'s cover image' );
	$check( 'a member cannot upload a team\'s cover', 'packout_forbidden' === $code( Subteams::set_cover( $team, $north, $file( 'north.jpg' ), $cal ) ) && 0 === Subteams::cover( $team, $north ) );
	$as( 0 );
	$html = $page_of( $team, 'north-wash' );
	$check( 'without one, the team\'s page wears the cover of its organization', false !== strpos( $html, 'pos-place__hero--cover' ) && false !== strpos( $html, (string) wp_get_attachment_image_url( (int) $kept, 'full' ) ) );
	$cover = Subteams::set_cover( $team, $north, $file( 'north.jpg' ), $ben );
	$check( 'its lead uploads one', is_int( $cover ) && $cover > 0 && Subteams::cover( $team, $north ) === $cover && 'image/webp' === get_post_mime_type( (int) $cover ), $said( $cover ) );
	$html = $page_of( $team, 'north-wash' );
	$check( 'and the page wears its own', false !== strpos( $html, (string) wp_get_attachment_image_url( (int) $cover, 'full' ) ) && false === strpos( $html, (string) wp_get_attachment_image_url( (int) $kept, 'full' ) ) );
	$again = Subteams::set_cover( $team, $north, $file( 'north-2.jpg' ), $ana );
	$check( 'the organization\'s lead replaces it, and the old image is deleted', is_int( $again ) && $again !== $cover && null === get_post( (int) $cover ) );
	$check( 'a file that is not an image is refused', is_wp_error( Subteams::set_cover( $team, $north, $file( 'north.jpg', false ), $ben ) ) && Subteams::cover( $team, $north ) === $again );
	$html = $tab( 'subteams', $ben );
	$check( 'its pane has a Cover image section, and a removal that asks first', false !== strpos( $html, 'id="pos-subteam-' . $north . '-cover"' ) && false !== strpos( $html, 'value="subteam_cover"' ) && 1 === preg_match( '#value="subteam_cover_remove".*?<button class="pos-ws__button pos-ws__button--danger"[^>]*data-pos-confirm="[^"]+"#s', $html ) );
	$check( 'and a Public page section with the address, the boxes, and how people join', false !== strpos( $html, 'id="pos-subteam-' . $north . '-page"' ) && false !== strpos( $html, 'value="subteam_page"' ) && 1 === preg_match( '#name="address"[^>]*value="north-wash"[^>]*data-pos-address="[^"]*/subteams/' . $north . '/address"#', $html ) && 1 === preg_match( '#<input[^>]*name="public"[^>]*checked#', $html ) && 1 === preg_match( '#<select[^>]*name="join"#', $html ) );
	$check( 'the lead of one team is shown no such form for another', false === strpos( $html, 'id="pos-subteam-' . $south . '-page"' ) && false === strpos( $html, 'id="pos-subteam-' . $south . '-cover"' ) );
	$html = $tab( 'subteams', $cal );
	$check( 'and a member is shown none', false === strpos( $html, 'value="subteam_page"' ) && false === strpos( $html, 'value="subteam_cover"' ) && false === strpos( $html, 'value="subteam_answer"' ) );

	// A page form that is refused, through the workspace's own handler.
	$as( $ben );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = array(
		'packout_workspace' => 'subteam_page',
		'team'              => (string) $team,
		'item'              => (string) $north,
		'about'             => 'Typed before the refusal.',
		'public'            => '1',
		'address'           => 'south-wash',
		'join'              => 'open',
		'_posnonce'         => wp_create_nonce( 'packout_workspace' ),
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This stands in for a request. The handler checks the nonce.
	$_REQUEST = $_POST;
	Workspace::handle();
	$_GET                      = array(
		'team' => (string) $team,
		'view' => 'subteams',
	);
	$html                      = Workspace::render( array() );
	$_GET                      = array();
	$_POST                     = array();
	$_REQUEST                  = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$check( 'a refused address comes back with what was typed, and nothing is saved', false !== strpos( $html, 'Another team of this organization has that address' ) && false !== strpos( $html, 'Typed before the refusal.' ) && 'north-wash' === $sub( $north )['slug'] && 'closed' === $sub( $north )['join'] );

	WP_CLI::log( 'What a team\'s page shows' );
	$plan            = static fn( string $title, array $spots, array $more = array() ): array => $more + array(
		'title'  => $title . ' ' . $packout_run,
		'team'   => $team,
		'spots'  => $spots,
		'start'  => '2027-05-01',
		'end'    => '2027-05-01',
		'public' => true,
	);
	$sweep           = Campaigns::save(
		0,
		array(
			'team'     => $team,
			'title'    => 'Covertest sweep ' . $packout_run,
			'subteams' => array( $north ),
			'public'   => true,
		),
		$ana
	);
	$packout_posts[] = $sweep;
	$m_north         = Missions::save( 0, $plan( 'Covertest north day', array( $s1 ), array( 'subteams' => array( $north ) ) ), $ana );
	$packout_posts[] = $m_north;
	$m_south         = Missions::save( 0, $plan( 'Covertest south day', array( $s2 ), array( 'subteams' => array( $south ) ) ), $ana );
	$packout_posts[] = $m_south;
	$m_quiet         = Missions::save(
		0,
		$plan(
			'Covertest quiet day',
			array( $s3 ),
			array(
				'subteams' => array( $north ),
				'public'   => false,
			)
		),
		$ana
	);
	$packout_posts[] = $m_quiet;
	$check( 'the fixtures are in place', is_int( $sweep ) && is_int( $m_north ) && is_int( $m_south ) && is_int( $m_quiet ), array( $said( $sweep ), $said( $m_north ), $said( $m_south ), $said( $m_quiet ) ) );

	$as( 0 );
	$html = $page_of( $team, 'north-wash' );
	$check( 'it opens with the header: Team, the name, the description', false !== strpos( $html, 'pos-subteam-page' ) && false !== strpos( $html, '<p class="pos-place__kind">Team</p>' ) && false !== strpos( $html, '<h1 class="pos-place__name">North Wash</h1>' ) && false !== strpos( $html, 'We look after the north wash.' ) );
	$check( 'the facts link to its organization and count its people', 1 === preg_match( '#<p class="pos-place__facts"><a href="' . preg_quote( (string) get_permalink( $team ), '#' ) . '">' . preg_quote( esc_html( $name ), '#' ) . '</a>.*1 person#s', $html ) );
	$check( 'it carries the organization\'s sponsor', 1 === preg_match( '#<aside class="pos-place__support".*Test Outfitters#s', $html ) );
	$check( 'it names its lead, and no member or email address', false !== strpos( $html, 'Ben Testperson' ) && false === strpos( $html, 'Cal Testperson' ) && false === strpos( $html, '@example.test' ) );
	$check( 'it lists the public campaign and mission that name it', false !== strpos( $html, 'Covertest sweep' ) && false !== strpos( $html, 'Covertest north day' ) );
	$check( 'and not another team\'s mission, nor one that is not shared', false === strpos( $html, 'Covertest south day' ) && false === strpos( $html, 'Covertest quiet day' ) );
	$check( 'it says nothing has been counted yet', false !== strpos( $html, 'No cleanup on a mission of this team has been counted yet.' ) );
	$check( 'a closed team offers no button, and points to the organization', false !== strpos( $html, 'chooses its members' ) && false === strpos( $html, 'value="subteam_ask"' ) && false === strpos( $html, 'Sign in to join' ) && false !== strpos( $html, '#pos-join' ) );
	$check( 'the page is not a preview', false === strpos( $html, 'pos-subteam-page__preview' ) );

	Subteams::update_page( $team, $north, array( 'leads' => false ), $ben );
	$check( 'a team that chose not to names nobody', false === strpos( $page_of( $team, 'north-wash' ), 'Testperson' ) );
	Subteams::update_page( $team, $north, array( 'leads' => true ), $ben );

	// A cleanup on the north mission, and one on the south.
	Missions::set_status( (int) $m_north, 'in_progress' );
	Missions::set_status( (int) $m_south, 'in_progress' );
	Spots::mark_cleaned( $s1, $ben, (int) $m_north );
	Spots::mark_cleaned( $s2, $ana, (int) $m_south );
	$r1 = Impact::save_cleanup(
		$s1,
		array(
			'narrative'   => 'Hauled the tires. ' . $packout_run,
			'hours'       => 3,
			'people'      => 2,
			'bags'        => 4,
			'bags_source' => 'measured',
		),
		$ana
	);
	$r2 = Impact::save_cleanup(
		$s2,
		array(
			'narrative'   => 'Bagged the cans. ' . $packout_run,
			'hours'       => 5,
			'people'      => 1,
			'bags'        => 9,
			'bags_source' => 'measured',
		),
		$ana
	);
	foreach ( array( $r1, $r2 ) as $record ) {
		if ( is_int( $record ) ) {
			$packout_posts[] = $record;
			Impact::set_visible( $record, true, $coordinator );
		}
	}
	Missions::set_status( (int) $m_north, 'completed' );
	Impact::flush();
	$totals = Subteams::totals( $team, $north, true );
	$check( 'a team\'s impact is what the records of its missions add up to', is_int( $r1 ) && is_int( $r2 ) && 1 === (int) $totals['records']['cleanups'] && 4 === (int) $totals['outputs']['bags_measured'] && 1 === (int) $totals['effort']['missions'], array( $said( $r1 ), $said( $r2 ), $totals ) );
	$check( 'a team with no mission has none', 0 === (int) Subteams::totals( (int) $waiting, (int) $early, true )['records']['cleanups'] );
	$as( 0 );
	$html = $page_of( $team, 'north-wash' );
	$check( 'the page shows the figures as a Place does', false !== strpos( $html, 'pos-team-page__impact' ) && false !== strpos( $html, 'pos-place__figures' ) && 1 === preg_match( '#<dt>Bags, measured</dt><dd>4</dd>#', $html ) && false !== strpos( $html, 'Measured means counted or weighed.' ) );
	$check( 'and counts its completed mission under Taking part', 1 === preg_match( '#<dt>Missions completed</dt><dd>1</dd>#', $html ) && 1 === preg_match( '#<dt>Campaigns</dt><dd>1</dd>#', $html ) );
	$html = $page_of( $team );
	$check( 'the organization\'s page adds up both', 1 === preg_match( '#<dt>Bags, measured</dt><dd>13</dd>#', $html ) && false === strpos( $html, 'No cleanup by this organization has been counted yet.' ) );
	$check( 'and lists the team that has a page, with a link, and not the one without', false !== strpos( $html, 'pos-team-page__subteams' ) && false !== strpos( $html, 'href="' . esc_url( Subteams::url( $team, $north ) ) . '"' ) && false !== strpos( $html, 'See the team' ) && false === strpos( $html, 'South Wash' ) );
	$check( 'with the campaign, the missions, and its team count', false !== strpos( $html, 'Covertest sweep' ) && false !== strpos( $html, 'Covertest south day' ) && 1 === preg_match( '#<p class="pos-place__facts">.*1 team#s', $html ) );

	WP_CLI::log( 'Joining a team' );
	$check( 'a closed team takes nobody from its page', 'packout_subteam_closed' === $code( Subteams::ask( $team, $north, $cal ) ) && false === Subteams::includes( $team, $north, $cal ) );
	$check( 'a team with no page takes nobody that way', 'packout_not_found' === $code( Subteams::ask( $team, $south, $cal ) ) );
	Subteams::update_page( $team, $north, array( 'join' => 'open' ), $ben );
	$as( 0 );
	$html = $page_of( $team, 'north-wash' );
	$check( 'an open team sends a visitor to sign in, and says the organization comes with it', false !== strpos( $html, 'Sign in to join' ) && false !== strpos( $html, 'If you are not in' ) && false === strpos( $html, 'value="subteam_ask"' ) );
	$as( $cal );
	$html = $page_of( $team, 'north-wash' );
	$check( 'an organization member is offered the button', false !== strpos( $html, 'value="subteam_ask"' ) && false !== strpos( $html, 'Join the team' ) );
	$before = count( $packout_mail );
	$check( 'and is in the team at once', 'joined' === Subteams::ask( $team, $north, $cal ) && true === Subteams::includes( $team, $north, $cal ) && in_array( $cal, $sub( $north )['members'], true ) && count( $packout_mail ) === $before );
	$check( 'asking again changes nothing', 'joined' === Subteams::ask( $team, $north, $cal ) && 1 === count( array_keys( $sub( $north )['members'], $cal, true ) ) );
	$html = $page_of( $team, 'north-wash' );
	$check( 'the page says so, and offers the way out after a question', false !== strpos( $html, 'You are in this team.' ) && 1 === preg_match( '#value="subteam_leave".*?<button class="pos-ws__button pos-ws__button--danger"[^>]*data-pos-confirm="Leave this team\?"#s', $html ) );
	$html = $tab( 'subteams', $cal );
	$check( 'and so does its pane', false !== strpos( $html, 'value="subteam_leave"' ) );
	$check( 'a member leaves and stays in the organization', true === Subteams::leave( $team, $north, $cal ) && false === Subteams::includes( $team, $north, $cal ) && true === Teams::includes( $team, $cal ) );
	$check( 'a person who is not in it cannot leave it', 'packout_not_found' === $code( Subteams::leave( $team, $north, $cal ) ) );
	$check( 'its lead cannot leave it', 'packout_subteam_lead' === $code( Subteams::leave( $team, $north, $ben ) ) && $ben === $sub( $north )['lead'] );
	$as( $ben );
	$check( 'its lead is not offered the way out', false === strpos( $page_of( $team, 'north-wash' ), 'value="subteam_leave"' ) );

	// The organization asks, the team is open: one answer settles both.
	$before = count( $packout_mail );
	$check( 'a person who is not in the organization asks the organization first', 'requested' === Subteams::ask( $team, $north, $dee ) && in_array( $dee, Teams::requests( $team ), true ) && array( $dee ) === $sub( $north )['requests'] && false === Subteams::includes( $team, $north, $dee ) );
	$check( 'the organization\'s leads are emailed, and the team\'s lead has nothing to decide', count( $mail_to( $email_of( $ana ), 'asked to join your organization' ) ) >= 1 && array() === $mail_to( $email_of( $ben ), 'asked to join your team' ) );
	$as( $dee );
	$html = $page_of( $team, 'north-wash' );
	$check( 'their page says who has the request, and lets them take it back', false !== strpos( $html, 'Your request is with the organization' ) && false !== strpos( $html, 'value="subteam_withdraw"' ) && false === strpos( $html, 'value="subteam_ask"' ) );
	$check( 'the team\'s lead cannot accept a person who is not in the organization', 'packout_member_unknown' === $code( Subteams::answer( $team, $north, $dee, true, $ben ) ) && array( $dee ) === $sub( $north )['requests'] );
	$html = $tab( 'subteams', $ben );
	$check( 'the pane lists them as waiting for the organization, with Decline and no Accept', false !== strpos( $html, 'id="pos-subteam-' . $north . '-requests"' ) && false !== strpos( $html, 'Dee Testperson' ) && false !== strpos( $html, 'Waiting for the organization' ) && 0 === preg_match( '#name="who" value="' . $dee . '"[^<]*(<input[^>]*>\s*)*<input[^>]*name="accept"#', $html ) );
	$check( 'when the organization accepts, they are in the organization and in the team', true === Teams::answer( $team, $dee, true, $ana ) && true === Teams::includes( $team, $dee ) && true === Subteams::includes( $team, $north, $dee ) && array() === $sub( $north )['requests'] );

	// The organization asks and declines.
	$check( 'another person asks', 'requested' === Subteams::ask( $team, $north, $eve ) && array( $eve ) === $sub( $north )['requests'] );
	$check( 'when the organization declines, the request to the team ends too', true === Teams::answer( $team, $eve, false, $ana ) && array() === $sub( $north )['requests'] && false === Teams::includes( $team, $eve ) );
	$check( 'and so it does when the person withdraws from the organization', 'requested' === Subteams::ask( $team, $north, $eve ) && true === Teams::withdraw( $team, $eve ) && array() === $sub( $north )['requests'] );
	$check( 'a request can be taken back from the team alone', 'requested' === Subteams::ask( $team, $north, $eve ) && true === Subteams::withdraw( $team, $north, $eve ) && array() === $sub( $north )['requests'] && in_array( $eve, Teams::requests( $team ), true ) && false === Subteams::withdraw( $team, $north, $eve ) );
	Teams::withdraw( $team, $eve );

	// The team's lead decides.
	Subteams::update_page( $team, $north, array( 'join' => 'request' ), $ben );
	Subteams::set_colead( $team, $north, $dee, true, $ben );
	$packout_mail = array();
	$check( 'with requests, an organization member waits for the team\'s lead', 'requested' === Subteams::ask( $team, $north, $cal ) && array( $cal ) === $sub( $north )['requests'] && false === Subteams::includes( $team, $north, $cal ) );
	$told = $mail_to( $email_of( $ben ), 'asked to join your team' );
	$check( 'its lead and co-lead are emailed, with who and where to answer', 1 === count( $told ) && false !== strpos( $told[0]['message'], 'Cal Testperson' ) && false !== strpos( $told[0]['message'], 'North Wash' ) && false !== strpos( $told[0]['message'], 'view=subteams' ) && 1 === count( $mail_to( $email_of( $dee ), 'asked to join your team' ) ) && array() === $mail_to( $email_of( $ana ), 'asked to join your team' ) );
	$check( 'asking twice sends nothing more', 'requested' === Subteams::ask( $team, $north, $cal ) && 1 === count( $mail_to( $email_of( $ben ), 'asked to join your team' ) ) && array( $cal ) === $sub( $north )['requests'] );
	$html = $tab( 'subteams', $ben );
	$check( 'the pane shows the request with Accept and Decline, and the email address', false !== strpos( $html, 'Asking to join (1)' ) && false !== strpos( $html, esc_html( $email_of( $cal ) ) ) && 1 === preg_match( '#value="subteam_answer".*name="accept"#s', $html ) );
	$check( 'a member cannot answer it', 'packout_forbidden' === $code( Subteams::answer( $team, $north, $cal, true, $cal ) ) && 'packout_forbidden' === $code( Subteams::answer( $team, $north, $cal, true, $gil === $ben ? $cal : $eve ) ) );
	$check( 'the lead accepts, and the person is told', true === Subteams::answer( $team, $north, $cal, true, $ben ) && true === Subteams::includes( $team, $north, $cal ) && array() === $sub( $north )['requests'] && 1 === count( $mail_to( $email_of( $cal ), 'You are in the team' ) ) );
	$check( 'a request that is gone cannot be answered again', 'packout_not_found' === $code( Subteams::answer( $team, $north, $cal, false, $ben ) ) );
	Teams::add_member( $team, get_userdata( $fay )->user_login );
	$check( 'the co-lead declines another, and that person is told', 'requested' === Subteams::ask( $team, $north, $fay ) && true === Subteams::answer( $team, $north, $fay, false, $dee ) && false === Subteams::includes( $team, $north, $fay ) && 1 === count( $mail_to( $email_of( $fay ), 'Your request to join' ) ) );
	// With no lead of its own, the organization's leads answer.
	Subteams::update_page(
		$team,
		$south,
		array(
			'public' => true,
			'join'   => 'request',
		),
		$ana
	);
	$packout_mail = array();
	$check( 'a team with no lead sends the request to the organization\'s leads', 'requested' === Subteams::ask( $team, $south, $fay ) && 1 === count( $mail_to( $email_of( $ana ), 'asked to join your team' ) ) && 1 === count( $mail_to( $email_of( $gil ), 'asked to join your team' ) ) );
	$check( 'turning the team to closed ends the requests', true === Subteams::update_page( $team, $south, array( 'join' => 'closed' ), $ana ) && array() === $sub( $south )['requests'] );

	// A person who is not in the organization, with both asking.
	$packout_mail = array();
	$check( 'a person who is not in the organization asks both', 'requested' === Subteams::ask( $team, $north, $eve ) && in_array( $eve, Teams::requests( $team ), true ) && array( $eve ) === $sub( $north )['requests'] );
	$told = $mail_to( $email_of( $ben ), 'asked to join your team' );
	$check( 'the team\'s lead is told the organization answers first', 1 === count( $told ) && false !== strpos( $told[0]['message'], 'not in the organization yet' ) && count( $mail_to( $email_of( $ana ), 'asked to join your organization' ) ) >= 1 );
	$check( 'the organization accepts, and the request stays with the team\'s lead', true === Teams::answer( $team, $eve, true, $ana ) && true === Teams::includes( $team, $eve ) && false === Subteams::includes( $team, $north, $eve ) && array( $eve ) === $sub( $north )['requests'] );
	$check( 'who then accepts', true === Subteams::answer( $team, $north, $eve, true, $ben ) && true === Subteams::includes( $team, $north, $eve ) );
	$check( 'a person removed from the organization is out of its teams and its requests', true === Teams::remove_member( $team, $eve ) && false === Subteams::includes( $team, $north, $eve ) && array() === $sub( $north )['requests'] );

	// An organization that takes anyone.
	Teams::update_profile( $team, array( 'join' => 'open' ), $ana );
	Subteams::update_page( $team, $north, array( 'join' => 'open' ), $ben );
	$check( 'with both open, a person is in the organization and in the team at once', 'joined' === Subteams::ask( $team, $north, $eve ) && true === Teams::includes( $team, $eve ) && true === Subteams::includes( $team, $north, $eve ) );
	Teams::remove_member( $team, $eve );
	Teams::update_profile( $team, array( 'join' => 'closed' ), $ana );
	$check( 'an organization that adds by invitation lets nobody in through a team', 'packout_team_closed' === $code( Subteams::ask( $team, $north, $eve ) ) && false === Teams::includes( $team, $eve ) && array() === $sub( $north )['requests'] );
	$as( $eve );
	$html = $page_of( $team, 'north-wash' );
	$check( 'and the page says so, with no button', false !== strpos( $html, 'adds its members by invitation' ) && false === strpos( $html, 'value="subteam_ask"' ) );
	Teams::update_profile( $team, array( 'join' => 'request' ), $ana );

	// A request that is refused, through the page's own handler.
	Subteams::update_page( $team, $north, array( 'join' => 'closed' ), $ben );
	$as( $fay );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = array(
		'packout_workspace' => 'subteam_ask',
		'team'              => (string) $team,
		'item'              => (string) $north,
		'back'              => Subteams::url( $team, $north ) . '#pos-join',
		'_posnonce'         => wp_create_nonce( 'packout_workspace' ),
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This stands in for a request. The handler checks the nonce.
	$_REQUEST = $_POST;
	Workspace::handle();
	$html                      = $page_of( $team, 'north-wash' );
	$_POST                     = array();
	$_REQUEST                  = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$check( 'a request the team no longer takes comes back with why', false !== strpos( Workspace::problem(), 'chooses its members' ) && false !== strpos( $html, 'pos-ws__notice' ) && false === Subteams::includes( $team, $north, $fay ) );

	WP_CLI::log( 'Removing a team' );
	$image = Subteams::cover( $team, $north );
	$check( 'removing its cover is for the people who run it', 'packout_forbidden' === $code( Subteams::set_cover( $team, $north, null, $fay ) ) && $image > 0 && Subteams::cover( $team, $north ) === $image );
	$check( 'removing a team deletes its cover', true === Subteams::remove( $team, $north, $ana ) && null === get_post( $image ) && null === Subteams::get( $team, $north ) );
	$as( 0 );
	$check( 'and its page is gone', 'none' === Team_Page::lookup( $team, 'north-wash' )[1] );
} finally {
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
