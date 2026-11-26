<?php
/**
 * Phase 8 acceptance checks: what a member cannot do, planning by members,
 * the header of a public campaign or mission, signing up for one, missions
 * and campaigns in panes, sections and the question before a change that
 * cannot be undone, My account in sections with panes on My impact, and the
 * search box of a map.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-8.php
 *
 * The script creates its own users, Spots, an organization, a campaign, missions, and
 * a Place, keeps mail in memory, answers the geocoder itself so nothing
 * leaves the machine, and removes everything it created. Settings it changes
 * are put back. It exits non-zero when a check fails.
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Blocks;
use PackOut\Core\App\Campaigns;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Messages;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Page_Header;
use PackOut\Core\App\Places;
use PackOut\Core\App\Profile;
use PackOut\Core\App\Resources;
use PackOut\Core\App\Rest;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Search;
use PackOut\Core\App\Settings;
use PackOut\Core\App\Signups;
use PackOut\Core\App\Sponsors;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Subteams;
use PackOut\Core\App\Teams;
use PackOut\Core\App\Workspace;
use PackOut\Core\App\Workspace_Lists;

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
$packout_asked    = array();
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

// The public page of a post, as the theme asks for it.
$page_of = static function ( int $post_id ): string {
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
	$html         = '';
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

// The geocoder, answered here. A request that reaches the network would be a failure of the test.
$geocoder = array(
	'mode'  => 'answer',
	'calls' => 0,
	'agent' => '',
	'url'   => '',
);
$answer   = static function ( $pre, $args, $url ) use ( &$geocoder ) {
	if ( false === strpos( (string) $url, 'nominatim.openstreetmap.org' ) ) {
		return $pre;
	}
	++$geocoder['calls'];
	$geocoder['agent'] = (string) ( $args['user-agent'] ?? '' );
	$geocoder['url']   = (string) $url;
	if ( 'fail' === $geocoder['mode'] ) {
		return new WP_Error( 'http_request_failed', 'No route.' );
	}
	if ( false !== strpos( (string) $url, '/reverse' ) ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'name'      => 'Rimtest Road',
					'category'  => 'highway',
					'type'      => 'secondary',
					'extratags' => array(
						'surface' => 'gravel',
						'ref'     => 'FR 123',
					),
					'address'   => array( 'road' => 'Rimtest Road' ),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode(
			array(
				array(
					'name'         => 'Rimtest Junction',
					'display_name' => 'Rimtest Junction, Yavapai County, Arizona, United States',
					'lat'          => '34.6012',
					'lon'          => '-111.8034',
					'boundingbox'  => array( '34.59', '34.61', '-111.82', '-111.79' ),
				),
				array(
					'display_name' => 'Rimtest Road, Camp Verde, Arizona, United States',
					'lat'          => '34.5',
					'lon'          => '-111.9',
				),
				array(
					'name' => 'Nowhere',
					'lat'  => 'north',
					'lon'  => 'east',
				),
			)
		),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $answer, 10, 3 );

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
	$name            = 'Signup Test Crew ' . $packout_run;
	$team            = Teams::save(
		0,
		array(
			'name'        => $name,
			'description' => 'A crew that walks the rim road every month.',
			'lead'        => $ana,
			'listed'      => true,
		)
	);
	$packout_posts[] = $team;
	Teams::add_member( $team, get_userdata( $ben )->user_login );
	Teams::add_member( $team, get_userdata( $cal )->user_login );
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

	$spot = static function ( string $what, float $lat, int $by, bool $publish = true ) use ( &$packout_posts ): int {
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
		if ( $publish ) {
			Spots::publish( $id );
		}
		return $id;
	};
	$s1   = $spot( 'Rimtest tires at the turnout. ' . $packout_run, 34.61, $dee );
	$s2   = $spot( 'Rimtest cans in the fire ring. ' . $packout_run, 34.62, $dee );
	$s3   = $spot( 'Rimtest mattress, waiting for review. ' . $packout_run, 34.63, $gil, false );
	$s4   = $spot( 'Rimtest glass that Cal reported. ' . $packout_run, 34.64, $cal );

	$tab = static function ( string $view, int $who, array $more = array() ) use ( $team, $as ): string {
		$as( $who );
		$_GET = array(
			'team' => (string) $team,
			'view' => $view,
		) + $more;
		$html = Workspace::render( array() );
		$_GET = array();
		return $html;
	};

	WP_CLI::log( 'A member cannot run the organization' );
	$check( 'a member cannot change the organization\'s profile', 'packout_forbidden' === $code( Teams::update_profile( $team, array( 'name' => 'Cal\'s Crew ' . $packout_run ), $cal ) ) && get_the_title( $team ) === $name );
	$check( 'nor its address', is_wp_error( Teams::set_address( $team, "1 Rim Road\nCamp Verde, AZ 86322", $cal ) ) );
	$check( 'nor its logo', is_wp_error( Teams::set_logo( $team, $file( 'logo.jpg' ), $cal ) ) );
	$check( 'a member cannot add a team, or rename one', is_wp_error( Subteams::save( $team, 0, array( 'name' => 'Cal\'s Wash' ), $cal ) ) && is_wp_error( Subteams::save( $team, $south, array( 'name' => 'Renamed' ), $cal ) ) && 'South Wash' === Subteams::get( $team, $south )['name'] );
	$check( 'nor put a person in one, nor remove one', is_wp_error( Subteams::set_member( $team, $south, $cal, true, $cal ) ) && is_wp_error( Subteams::remove( $team, $south, $cal ) ) );
	$check(
		'a member cannot add a sponsor or share a resource',
		is_wp_error( Sponsors::save( $team, 0, array( 'name' => 'Cal\'s Shop' ), $cal ) ) && is_wp_error(
			Resources::add(
				$team,
				array(
					'title'    => 'Cal\'s notes',
					'url'      => 'https://example.org/notes',
					'audience' => 'members',
				),
				$cal
			)
		)
	);
	$check( 'a member cannot write to the organization, a team, or a mission', 'packout_forbidden' === $code( Messages::send( $team, 'team', 'Hello', 'From a member.', $cal ) ) && 'packout_forbidden' === $code( Messages::send( $team, 'subteam-' . $south, 'Hello', 'From a member.', $cal ) ) );
	$html = $tab( 'overview', $cal );
	$check( 'a member\'s organization has no Settings and no Sponsors tab', false === strpos( $html, 'view=settings' ) && false === strpos( $html, 'view=sponsors' ) && false !== strpos( $html, 'view=missions' ) );
	$html = $tab( 'settings', $cal );
	$check( 'and the address of Settings shows a member no form of it', false === strpos( $html, 'value="profile"' ) && false === strpos( $html, 'value="handover"' ) && false === strpos( $html, 'pos-settings-profile' ) );
	$html = $tab( 'messages', $cal );
	$check( 'Messages offers a member nothing to write', false === strpos( $html, 'data-pos-open="message-new"' ) && false === strpos( $html, 'value="message_send"' ) );
	$html = $tab( 'subteams', $cal );
	$check( 'Teams offers a member no form', false === strpos( $html, 'data-pos-open="subteam-new"' ) && false === strpos( $html, 'value="subteam_save"' ) && false === strpos( $html, 'value="subteam_member"' ) );

	WP_CLI::log( 'Planning by members' );
	$plan = static fn( string $title, array $spots, array $more = array() ): array => array(
		'title' => $title . ' ' . $packout_run,
		'team'  => $team,
		'spots' => $spots,
		'start' => '2027-05-01',
		'end'   => '2027-05-01',
	) + $more;

	$check( 'an organization does not let its members plan until its lead says so', false === Teams::members_plan( $team ) && false === Missions::can_plan( $team, $cal ) && false === Campaigns::can_plan( $team, $cal ) );
	$check( 'so a member cannot plan a mission', is_wp_error( Missions::save( 0, $plan( 'Cal\'s outing', array( $s1 ) ), $cal ) ) );
	$check(
		'nor a campaign',
		is_wp_error(
			Campaigns::save(
				0,
				array(
					'team'  => $team,
					'title' => 'Cal\'s sweep ' . $packout_run,
				),
				$cal
			)
		)
	);
	$html = $tab( 'missions', $cal );
	$check( 'and Missions offers them no button to plan one', false === strpos( $html, 'data-pos-open="mission-new"' ) && false === strpos( $html, 'value="mission_save"' ) );
	$html = $tab( 'campaigns', $cal );
	$check( 'nor does Campaigns', false === strpos( $html, 'data-pos-open="campaign-new"' ) && false === strpos( $html, 'value="campaign_save"' ) );
	$html = $tab( 'settings', $ana );
	$check( 'the lead\'s Settings has the box, unticked', 1 === preg_match( '#<input[^>]*name="member_plans"[^>]*>#', $html, $found ) && false === strpos( $found[0], 'checked' ), $found[0] ?? '' );

	$led             = Missions::save(
		0,
		$plan(
			'Lead\'s outing',
			array( $s1 ),
			array(
				'public'      => true,
				'subteams'    => array( $north ),
				'sponsors'    => array( $brand ),
				'description' => "Six tires at the turnout.\n\nBring gloves.",
			)
		),
		$ana
	);
	$packout_posts[] = $led;
	$check( 'the lead plans a mission', is_int( $led ), is_wp_error( $led ) ? $led->get_error_message() : '' );
	$check( 'a member cannot turn planning on for themselves', is_wp_error( Teams::update_profile( $team, array( 'member_plans' => true ), $cal ) ) && false === Teams::members_plan( $team ) );
	$check( 'the lead turns it on', is_int( Teams::update_profile( $team, array( 'member_plans' => true ), $ana ) ) && true === Teams::members_plan( $team ) );
	$html = $tab( 'settings', $ana );
	$check( 'and the box is ticked', 1 === preg_match( '#<input[^>]*name="member_plans"[^>]*>#', $html, $found ) && false !== strpos( $found[0], 'checked' ) );
	$check( 'a member may plan now, and a person who is not in the organization may not', true === Missions::can_plan( $team, $cal ) && true === Campaigns::can_plan( $team, $cal ) && false === Missions::can_plan( $team, $dee ) );
	$before          = count( $packout_mail );
	$mine            = Missions::save(
		0,
		$plan(
			'Cal\'s outing',
			array( $s2 ),
			array(
				'public'      => true,
				'description' => 'Cans in the ring.',
			)
		),
		$cal
	);
	$packout_posts[] = $mine;
	$check( 'a member plans a mission', is_int( $mine ) && (int) get_post( $mine )->post_author === $cal && Missions::team( $mine ) === $team, is_wp_error( $mine ) ? $mine->get_error_message() : '' );
	$told = $mail_to( $email_of( $ana ), 'A member planned a mission' );
	$check( 'the lead is emailed, with who and what', 1 === count( $told ) && false !== strpos( $told[0]['message'], 'Cal Testperson' ) && false !== strpos( $told[0]['message'], 'Cal\'s outing' ) && count( $packout_mail ) - $before >= 1, wp_list_pluck( array_slice( $packout_mail, $before ), 'subject' ) );
	$check( 'the member is not emailed about their own plan', array() === $mail_to( $email_of( $cal ), 'A member planned' ) );
	$check( 'the member changes and runs what they planned', true === Missions::can_manage( $mine, $cal ) && true === Missions::can_assign( $mine, $cal ) && is_int( Missions::save( $mine, array( 'title' => 'Cal\'s second outing ' . $packout_run ), $cal ) ) && get_post( $mine )->post_title === 'Cal\'s second outing ' . $packout_run );
	$check( 'and nobody else\'s', false === Missions::can_manage( $led, $cal ) && is_wp_error( Missions::save( $led, array( 'title' => 'Taken over' ), $cal ) ) && false === Missions::can_manage( $mine, $ben ) );
	$check( 'the lead changes a member\'s mission', true === Missions::can_manage( $mine, $ana ) && is_int( Missions::save( $mine, array( 'gear' => 'Gloves.' ), $ana ) ) );
	$sweep           = Campaigns::save(
		0,
		array(
			'team'   => $team,
			'title'  => 'Cal\'s sweep ' . $packout_run,
			'public' => true,
		),
		$cal
	);
	$packout_posts[] = $sweep;
	$check( 'a member plans a campaign, and the lead is emailed', is_int( $sweep ) && 1 === count( $mail_to( $email_of( $ana ), 'A member planned a campaign' ) ), is_wp_error( $sweep ) ? $sweep->get_error_message() : '' );
	$fall            = Campaigns::save(
		0,
		array(
			'team'        => $team,
			'title'       => 'Rimtest Fall Sweep ' . $packout_run,
			'description' => 'Both washes before the snow.',
			'goal'        => 'Every turnout cleaned.',
			'subteams'    => array( $north, $south ),
			'sponsors'    => array( $brand ),
			'public'      => true,
		),
		$ana
	);
	$packout_posts[] = $fall;
	$check( 'a member changes their own campaign and not the lead\'s', true === Campaigns::can_change( $sweep, $cal ) && false === Campaigns::can_change( $fall, $cal ) && is_wp_error( Campaigns::save( $fall, array( 'title' => 'Taken over' ), $cal ) ) && is_wp_error( Campaigns::remove( $fall, $cal ) ) );
	$html = $tab( 'missions', $cal );
	$check( 'Missions offers the member the button, and the forms of their own mission alone', false !== strpos( $html, 'data-pos-open="mission-new"' ) && 1 === preg_match( '#id="pos-pane-mission-' . $mine . '".*?value="mission_save"#s', $html ) && 0 === preg_match( '#id="pos-pane-mission-' . $led . '"(?:(?!id="pos-pane-).)*value="mission_save"#s', $html ) );
	$check( 'planning gives a member nothing else', is_wp_error( Teams::update_profile( $team, array( 'name' => 'Cal\'s Crew ' . $packout_run ), $cal ) ) && is_wp_error( Messages::send( $team, 'team', 'Hello', 'From a member.', $cal ) ) && is_wp_error( Subteams::save( $team, 0, array( 'name' => 'Cal\'s Wash' ), $cal ) ) && is_wp_error( Sponsors::save( $team, 0, array( 'name' => 'Cal\'s Shop' ), $cal ) ) );
	Teams::update_profile( $team, array( 'member_plans' => false ), $ana );
	$check( 'the lead turns it off, and the member changes nothing more', false === Teams::members_plan( $team ) && false === Missions::can_manage( $mine, $cal ) && is_wp_error( Missions::save( $mine, array( 'title' => 'Once more' ), $cal ) ) && false === Campaigns::can_change( $sweep, $cal ) );
	$check( 'what they planned stays with the organization, for its lead', null !== Missions::get( $mine ) && true === Missions::can_manage( $mine, $ana ) && null !== Campaigns::get( $sweep ) );

	WP_CLI::log( 'The header of a public campaign or mission' );
	$cover = Campaigns::set_cover( $led, $file( 'turnout.jpg' ), $ana );
	$check( 'the lead gives a mission a cover image, kept as a WebP', is_int( $cover ) && $cover > 0 && Page_Header::cover( $led ) === $cover && 'image/webp' === get_post_mime_type( $cover ), is_wp_error( $cover ) ? $cover->get_error_message() : $cover );
	$check( 'a member cannot', 'packout_forbidden' === $code( Campaigns::set_cover( $led, $file( 'other.jpg' ), $cal ) ) && Page_Header::cover( $led ) === $cover );
	$check( 'a script with a photo\'s name is refused, and the cover stays', is_wp_error( Campaigns::set_cover( $led, $file( 'script.jpg', false ), $ana ) ) && Page_Header::cover( $led ) === $cover );
	$banner = Campaigns::set_cover( $fall, $file( 'sweep.jpg' ), $ana );
	$check( 'a campaign takes one too', is_int( $banner ) && $banner > 0 && Page_Header::cover( $fall ) === $banner );
	$as( 0 );
	$html = $page_of( $led );
	$check( 'the mission page opens with the cover, the name, and what it is', false !== strpos( $html, 'pos-place__hero--cover' ) && 1 === preg_match( '#<h1[^>]*class="pos-place__name"[^>]*>[^<]*Lead&\#8217;s outing|<h1[^>]*class="pos-place__name"[^>]*>[^<]*Lead(&\#039;|’|\')s outing#', $html ) && false !== strpos( $html, 'Mission' ), substr( wp_strip_all_tags( $html ), 0, 300 ) );
	$check( 'the description, in paragraphs', false !== strpos( $html, 'Six tires at the turnout.' ) && false !== strpos( $html, 'Bring gloves.' ) && 1 === preg_match( '#pos-place__description.*?<p>Six tires at the turnout\.</p>#s', $html ) );
	$check( 'and the sponsor, as a credit', false !== strpos( $html, 'Test Outfitters ' . $packout_run ) && false !== strpos( $html, 'pos-place__support' ) && false !== strpos( $html, 'rel="sponsored nofollow noopener"' ) );
	$check( 'no person is named on it', false === strpos( $html, 'Testperson' ) && false === strpos( $html, '@example.test' ) );
	$html = $page_of( $fall );
	$check( 'the campaign page has the same header', false !== strpos( $html, 'pos-place__hero--cover' ) && false !== strpos( $html, 'Both washes before the snow.' ) && false !== strpos( $html, 'Test Outfitters ' . $packout_run ) && false !== strpos( $html, 'Campaign' ) );
	$html = $page_of( $mine );
	$check( 'a mission without a cover has the dark header', false !== strpos( $html, 'pos-place__hero' ) && false === strpos( $html, 'pos-place__hero--cover' ) && false !== strpos( $html, 'Cans in the ring.' ) );
	$payload = Missions::public_payload( get_post( $led ) );
	$check( 'the public record of a mission carries its description and cover', 'Six tires at the turnout.' === strtok( (string) $payload['description'], "\n" ) && $cover === $payload['cover'], array( $payload['description'] ?? null, $payload['cover'] ?? null ) );
	$check( 'removing the cover leaves the dark header', 0 === Campaigns::set_cover( $fall, null, $ana ) && 0 === Page_Header::cover( $fall ) && false === strpos( $page_of( $fall ), 'pos-place__hero--cover' ) );

	WP_CLI::log( 'Signing up for a mission or a campaign' );
	$as( 0 );
	$html = $page_of( $led );
	$check( 'a public mission of a listed organization has a sign-up section', false !== strpos( $html, 'id="pos-signup"' ) && false !== strpos( $html, 'Sign in to sign up' ) && true === Signups::takes( $led ) );
	$check( 'that tells a visitor what signing up does, with no form', false !== strpos( $html, 'asks to join' ) && false === strpos( $html, 'value="signup"' ) );
	$quiet           = Missions::save( 0, $plan( 'Team-only outing', array( $s1 ) ), $ana );
	$packout_posts[] = $quiet;
	$check( 'a mission shown to the organization only takes no sign-up', false === Signups::takes( $quiet ) && 'packout_not_found' === $code( Signups::sign_up( $quiet, $dee ) ) );

	// The organization takes requests: signing up asks to join.
	$before = count( $packout_mail );
	$as( $dee );
	$html = $page_of( $led );
	$check( 'a signed-in person gets the form, and is told which team', false !== strpos( $html, 'value="signup"' ) && false !== strpos( $html, 'North Wash' ) && false !== strpos( $html, 'Sign up and ask to join' ) );
	$result = Signups::sign_up( $led, $dee );
	$check( 'signing up for an organization that takes requests asks to join', 'requested' === $result && in_array( $dee, Teams::requests( $team ), true ) && false === Teams::includes( $team, $dee ), $result );
	$check(
		'and waits, with the mission and its team',
		'requested' === Signups::state( $led, $dee ) && array(
			'event'   => $led,
			'subteam' => $north,
		) === ( Signups::waiting( $team )[ $dee ] ?? null ) && array() === Signups::people( $led ),
		Signups::waiting( $team )
	);
	$told = $mail_to( $email_of( $ana ), 'Someone signed up for a mission' );
	$check( 'the lead is told once, with who, what, and how to answer', 1 === count( $told ) && false !== strpos( $told[0]['message'], 'Dee Testperson' ) && false !== strpos( $told[0]['message'], 'Lead\'s outing' ) && false !== strpos( $told[0]['message'], 'North Wash' ) && false !== strpos( $told[0]['message'], 'view=members' ) && 1 === count( array_filter( array_slice( $packout_mail, $before ), static fn( $mail ) => strtolower( $mail['to'] ) === strtolower( $email_of( $ana ) ) ) ), wp_list_pluck( array_slice( $packout_mail, $before ), 'subject' ) );
	$check( 'and so is the lead of the team', 1 === count( $mail_to( $email_of( $ben ), 'Someone signed up for a mission' ) ) );
	$html = $page_of( $led );
	$check( 'the page says the sign-up is with the lead', false !== strpos( $html, 'with the organization’s lead' ) && false !== strpos( $html, 'value="signup_withdraw"' ) && false === strpos( $html, 'value="signup"' ) );
	$html = $tab( 'members', $ana );
	$check( 'Members names what the person signed up for', 1 === preg_match( '#Asked to join.*?Dee Testperson.*?Signed up for “Lead(&\#039;|’|\'|&\#8217;)s outing[^”]*”, to work with North Wash#s', $html ), substr( wp_strip_all_tags( (string) strstr( $html, 'Asked to join' ) ), 0, 300 ) );
	$check( 'the lead accepts, and the person is in the organization, on the mission, and in its team', ! is_wp_error( Teams::answer( $team, $dee, true, $ana ) ) && Teams::includes( $team, $dee ) && in_array( $dee, Signups::people( $led ), true ) && in_array( $north, Subteams::of_user( $team, $dee ), true ) && 'signed' === Signups::state( $led, $dee ) && array() === Signups::waiting( $team ) );
	$check( 'the mission is theirs to work', true === Missions::can_work( $led, $dee ) );

	$check( 'a person the lead declines is not signed up', 'requested' === Signups::sign_up( $led, $gil ) && ! is_wp_error( Teams::answer( $team, $gil, false, $ana ) ) && '' === Signups::state( $led, $gil ) && ! isset( Signups::waiting( $team )[ $gil ] ) && false === Teams::includes( $team, $gil ) );
	$check( 'a request taken back leaves nothing waiting', 'requested' === Signups::sign_up( $led, $gil ) && true === Signups::withdraw( $led, $gil ) && '' === Signups::state( $led, $gil ) && ! isset( Signups::waiting( $team )[ $gil ] ) );
	Teams::answer( $team, $gil, false, $ana );

	// The organization takes anyone: signing up joins.
	Teams::update_profile( $team, array( 'join' => 'open' ), $ana );
	$as( $eve );
	$html = $page_of( $fall );
	$check( 'a campaign of several teams asks which one', false !== strpos( $html, 'name="subteam"' ) && false !== strpos( $html, 'North Wash' ) && false !== strpos( $html, 'South Wash' ) && false !== strpos( $html, 'adds you to' ) );
	$check( 'and refuses a sign-up that names none', 'packout_signup_subteam' === $code( Signups::sign_up( $fall, $eve ) ) && false === Teams::includes( $team, $eve ) );
	$before = count( $packout_mail );
	$result = Signups::sign_up( $fall, $eve, $south );
	$check( 'signing up for an open organization joins it', 'joined' === $result && Teams::includes( $team, $eve ) && in_array( $eve, Signups::people( $fall ), true ) && in_array( $south, Subteams::of_user( $team, $eve ), true ), $result );
	$told = $mail_to( $email_of( $ana ), 'Someone signed up for a campaign' );
	$check( 'the lead is told they joined', 1 === count( $told ) && false !== strpos( $told[0]['message'], 'Eve Testperson' ) && false !== strpos( $told[0]['message'], 'joined the organization' ) && false !== strpos( $told[0]['message'], 'South Wash' ) );
	$before = count( $packout_mail );
	$check( 'a member signs up without joining again', 'signed' === Signups::sign_up( $led, $cal ) && in_array( $cal, Signups::people( $led ), true ) && in_array( $north, Subteams::of_user( $team, $cal ), true ) );
	$check( 'and the lead is told they were in the organization already', 1 === count( array_filter( array_slice( $packout_mail, $before ), static fn( $mail ) => strtolower( $mail['to'] ) === strtolower( $email_of( $ana ) ) && false !== strpos( $mail['message'], 'in the organization already' ) ) ) );
	$before = count( $packout_mail );
	$count  = count( Signups::people( $led ) );
	$check( 'signing up twice changes nothing and tells nobody', 'signed' === Signups::sign_up( $led, $cal ) && count( Signups::people( $led ) ) === $count && count( $packout_mail ) === $before );
	$html = $tab( 'missions', $ana );
	$check( 'the lead\'s pane of the mission lists who signed up', 1 === preg_match( '#id="pos-mission-' . $led . '-signups".*?Dee Testperson.*?Cal Testperson|id="pos-mission-' . $led . '-signups".*?Cal Testperson.*?Dee Testperson#s', $html ) );
	$html = $tab( 'missions', $ben );
	$check( 'and a member\'s pane does not', false === strpos( $html, 'id="pos-mission-' . $quiet . '-signups"' ) );
	$as( $cal );
	$check( 'a person takes their sign-up back and stays in the organization', true === Signups::withdraw( $led, $cal ) && '' === Signups::state( $led, $cal ) && Teams::includes( $team, $cal ) && false === Signups::withdraw( $led, $cal ) );

	// The organization adds people by invitation.
	Teams::update_profile( $team, array( 'join' => 'closed' ), $ana );
	$as( $fay );
	$html = $page_of( $led );
	$check( 'an organization that adds people by invitation offers no sign-up', false !== strpos( $html, 'by invitation' ) && false === strpos( $html, 'value="signup"' ) && is_wp_error( Signups::sign_up( $led, $fay ) ) && false === Teams::includes( $team, $fay ) );
	$as( $cal );
	$html = $page_of( $led );
	$check( 'but its own members can still say they are coming', false !== strpos( $html, 'value="signup"' ) && 'signed' === Signups::sign_up( $led, $cal ) );
	Teams::update_profile( $team, array( 'join' => 'open' ), $ana );

	// A sign-up form that is refused, through the page's own handler.
	$as( $fay );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = array(
		'packout_workspace' => 'signup',
		'team'              => (string) $team,
		'item'              => (string) $fall,
		'back'              => get_permalink( $fall ) . '#pos-signup',
		'_posnonce'         => wp_create_nonce( 'packout_workspace' ),
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This stands in for a request. The handler checks the nonce.
	$_REQUEST = $_POST;
	Workspace::handle();
	$html                      = $page_of( $fall );
	$_POST                     = array();
	$_REQUEST                  = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$check( 'a form that names no team comes back with what was wrong', false !== strpos( Workspace::problem(), 'team' ) && false !== strpos( $html, 'Choose the team' ) && false === Teams::includes( $team, $fay ), Workspace::problem() );

	Missions::set_status( $led, 'in_progress' );
	Missions::set_status( $led, 'completed' );
	$as( $fay );
	$check( 'a mission that has ended takes no more sign-ups', false === Signups::takes( $led ) && 'packout_signup_closed' === $code( Signups::sign_up( $led, $fay ) ) && false === strpos( $page_of( $led ), 'id="pos-signup"' ) );
	Teams::update_profile( $team, array( 'listed' => false ), $ana );
	$check( 'nor does anything of an organization that is not listed', false === Signups::takes( $fall ) && false === Signups::takes( $mine ) );
	Teams::update_profile( $team, array( 'listed' => true ), $ana );

	WP_CLI::log( 'Missions and campaigns open in panes' );
	$html = $tab( 'missions', $ana );
	$check( 'a row of Missions opens a pane, not a page', false !== strpos( $html, 'data-pos-view="missions"' ) && false !== strpos( $html, 'href="#pos-pane-mission-' . $mine . '"' ) && false !== strpos( $html, 'id="pos-pane-mission-' . $mine . '"' ) && false === strpos( $html, 'view=mission&#038;item=' . $mine . '"' ) && false === strpos( $html, 'view=mission&amp;item=' . $mine . '"' ) );
	$check( 'planning one is a pane too', false !== strpos( $html, 'data-pos-open="mission-new"' ) && 1 === preg_match( '#id="pos-pane-mission-new".*?value="mission_save".*?name="cover"#s', $html ) );
	$check( 'the pane of a mission has its sections', array() === array_filter( array( 'details', 'spots', 'resources', 'signups', 'plan', 'run' ), static fn( $part ) => false === strpos( $html, 'id="pos-mission-' . $mine . '-' . $part . '"' ) ) && false !== strpos( $html, 'data-pos-sections' ) );
	$html = $tab( 'missions', $ben );
	$check( 'a member\'s pane has what the crew needs to know, and no form', false !== strpos( $html, 'id="pos-mission-' . $mine . '-details"' ) && false !== strpos( $html, 'id="pos-mission-' . $mine . '-spots"' ) && false === strpos( $html, 'id="pos-mission-' . $mine . '-plan"' ) && false === strpos( $html, 'value="mission_status"' ) );
	$html = $tab( 'mission', $ana, array( 'item' => (string) $mine ) );
	$check( 'the old address of a mission shows the list with its pane open', false !== strpos( $html, 'data-pos-view="missions"' ) && false !== strpos( $html, 'data-pos-reopen="mission-' . $mine . '"' ) );
	$html = $tab(
		'mission',
		$ana,
		array(
			'item' => (string) $mine,
			'part' => 'plan',
		)
	);
	$check( 'and can name the section to show', false !== strpos( $html, 'data-pos-open="pos-mission-' . $mine . '-plan"' ) );
	$html = $tab( 'mission', $ana, array( 'item' => '999999999' ) );
	$check( 'the address of a mission that is gone shows the list and says so', false !== strpos( $html, 'data-pos-view="missions"' ) && false === strpos( $html, 'data-pos-reopen' ) );
	$html = $tab( 'campaigns', $ana );
	$check( 'Campaigns works the same way', false !== strpos( $html, 'href="#pos-pane-campaign-' . $fall . '"' ) && false !== strpos( $html, 'data-pos-open="campaign-new"' ) && array() === array_filter( array( 'details', 'missions', 'signups', 'plan' ), static fn( $part ) => false === strpos( $html, 'id="pos-campaign-' . $fall . '-' . $part . '"' ) ) );
	$html = $tab( 'campaign', $ana, array( 'item' => (string) $fall ) );
	$check( 'and so does the old address of a campaign', false !== strpos( $html, 'data-pos-view="campaigns"' ) && false !== strpos( $html, 'data-pos-reopen="campaign-' . $fall . '"' ) );

	// A plan that is refused comes back in its pane, and only its own form is refilled.
	$as( $ana );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = array(
		'packout_workspace' => 'mission_save',
		'team'              => (string) $team,
		'item'              => (string) $mine,
		'title'             => 'A title that was typed ' . $packout_run,
		'start'             => '2027-06-02',
		'end'               => '2027-06-01',
		'_posnonce'         => wp_create_nonce( 'packout_workspace' ),
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This stands in for a request. The handler checks the nonce.
	$_REQUEST = $_POST;
	Workspace::handle();
	$html                      = $tab( 'missions', $ana );
	$_POST                     = array();
	$_REQUEST                  = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$check( 'a plan that is refused comes back with its pane open on the plan', '' !== Workspace::problem() && false !== strpos( $html, 'data-pos-reopen="mission-' . $mine . '"' ) && false !== strpos( $html, 'data-pos-open="pos-mission-' . $mine . '-plan"' ), Workspace::problem() );
	$check( 'what was typed is kept in that form and in no other', 1 === substr_count( $html, 'value="A title that was typed ' . $packout_run . '"' ) );

	WP_CLI::log( 'Sections, buttons, and the question' );
	$one = Workspace::sections(
		'Test',
		array(
			array(
				'id'    => 'pos-test-a',
				'label' => 'Alpha',
				'html'  => '<p>A</p>',
			),
			array(
				'id'    => 'pos-test-b',
				'label' => 'Beta',
				'html'  => '',
			),
		)
	);
	$check( 'a page with one section has no tabs', false !== strpos( $one, 'pos-sections--one' ) && false === strpos( $one, 'pos-sections__nav' ) && false === strpos( $one, 'pos-test-b' ) );
	$two = Workspace::sections(
		'Test',
		array(
			array(
				'id'    => 'pos-test-a',
				'label' => 'Alpha',
				'html'  => '<p>A</p>',
			),
			array(
				'id'    => 'pos-test-b',
				'label' => 'Beta',
				'html'  => '<p>B</p>',
			),
		),
		'side',
		'pos-test-b',
		2
	);
	$check( 'several sections have links to each, which the script makes tabs', false !== strpos( $two, 'pos-sections--side' ) && false !== strpos( $two, 'data-pos-sections' ) && false !== strpos( $two, 'data-pos-open="pos-test-b"' ) && false !== strpos( $two, '<a href="#pos-test-a">Alpha</a>' ) && false !== strpos( $two, '<section class="pos-sections__panel" id="pos-test-b"><h2 class="pos-sections__title">Beta</h2>' ) );
	$check( 'and nothing empty', '' === Workspace::sections( 'Test', array() ) );
	$html = $tab( 'settings', $ana );
	$check( 'Settings is in sections down the left', false !== strpos( $html, 'pos-sections--side' ) && array() === array_filter( array( 'profile', 'address', 'logo', 'lead' ), static fn( $part ) => false === strpos( $html, 'id="pos-settings-' . $part . '"' ) ) );
	$check( 'with nothing folded away', false === strpos( $html, '<details' ) );
	$html = $tab( 'subteams', $ana );
	$check( 'the pane of a team is in sections across its top', 1 === preg_match( '#id="pos-pane-subteam-' . $north . '".*?pos-sections--top.*?id="pos-subteam-' . $north . '-people"#s', $html ) );
	$check( 'a change that cannot be undone is a red link that asks first', 1 === preg_match( '#<button class="pos-ws__button pos-ws__button--danger"[^>]*data-pos-confirm="[^"]+"[^>]*>#', $html ) && false === strpos( $html, '<details' ) );
	$html = $tab( 'overview', $ben );
	$check( 'leaving an organization asks first, and says what happens', 1 === preg_match( '#value="leave".*?<button class="pos-ws__button pos-ws__button--danger"[^>]*data-pos-confirm="Leave this organization\?"[^>]*data-pos-confirm-note="[^"]+"[^>]*data-pos-confirm-yes="Leave this organization"#s', $html ) );
	$every = $tab( 'missions', $ana ) . $tab( 'campaigns', $ana ) . $tab( 'members', $ana ) . $tab( 'resources', $ana ) . $tab( 'sponsors', $ana ) . $tab( 'settings', $ana );
	preg_match_all( '#<button class="pos-ws__button pos-ws__button--danger"[^>]*>#', $every, $found );
	$check( 'every such button on a lead\'s pages asks first', count( $found[0] ) >= 4 && array() === array_filter( $found[0], static fn( $button ) => false === strpos( $button, 'data-pos-confirm="' ) ), array_values( array_filter( $found[0], static fn( $button ) => false === strpos( $button, 'data-pos-confirm="' ) ) ) );

	WP_CLI::log( 'My account in sections, and My impact in panes' );
	$mine_record = Impact::save_cleanup(
		$s4,
		array(
			'narrative'   => 'Swept the glass. ' . $packout_run,
			'hours'       => 2,
			'people'      => 1,
			'bags'        => 1,
			'bags_source' => 'measured',
		),
		$cal
	);
	if ( is_int( $mine_record ) ) {
		$packout_posts[] = $mine_record;
	}
	$as( $cal );
	$html = Profile::render( array( 'view' => 'personal' ) );
	$check( 'Personal info has a section for each form, down the left', false !== strpos( $html, 'pos-sections--side' ) && false !== strpos( $html, '<section class="pos-sections__panel" id="pos-account-about"><h2 class="pos-sections__title">About you</h2>' ) && false !== strpos( $html, 'id="pos-account-contact"' ) && 1 === substr_count( $html, 'id="pos-account-about"' ) );
	$html = Profile::render( array( 'view' => 'signin' ) );
	$check( 'Sign-in details has the email and the password apart', false !== strpos( $html, 'id="pos-account-email"' ) && false !== strpos( $html, 'id="pos-account-password"' ) && 1 === preg_match( '#id="pos-account-password".*?value="password"#s', $html ) );
	$html = Profile::render( array( 'view' => 'email' ) );
	$check( 'Email preferences is one section, with no tabs', false !== strpos( $html, 'pos-sections--one' ) && false !== strpos( $html, 'id="pos-account-mail"' ) && false === strpos( $html, 'pos-sections__nav' ) );
	foreach ( array( 'personal', 'impact', 'email', 'signin', '' ) as $view ) {
		$part = Profile::render( array( 'view' => $view ) );
		if ( 1 !== preg_match( '#<nav class="pos-ws__tabs pos-profile__nav"#', $part ) ) {
			$check( 'every page of My account has the same row of tabs', false, $view );
		}
	}
	$check( 'every page of My account has the same row of tabs', true );
	$html = Profile::render( array( 'view' => 'impact' ) );
	$check( 'My impact has the figures and a section for each list', false !== strpos( $html, 'id="pos-account-contributions"' ) && false !== strpos( $html, 'id="pos-impact-missions"' ) && false !== strpos( $html, 'id="pos-impact-cleanups"' ) && false !== strpos( $html, 'id="pos-impact-spots"' ) && false === strpos( $html, '<details' ), is_wp_error( $mine_record ) ? $mine_record->get_error_message() : '' );
	$check( 'a mission opens in a pane, not in My organizations', false !== strpos( $html, 'href="#pos-pane-mission-' . $mine . '"' ) && false !== strpos( $html, 'id="pos-pane-mission-' . $mine . '"' ) && 0 === preg_match( '#id="pos-impact-missions".*?view=mission#s', (string) strstr( $html, 'pos-ws__panes', true ) ) );
	$pane = (string) strstr( $html, 'id="pos-pane-mission-' . $mine . '"' );
	$pane = substr( $pane, 0, (int) strpos( $pane . '<section class="packout-drawer__pane', '<section class="packout-drawer__pane', 10 ) );
	$check( 'with what the organization\'s own pane says: the details, the Spots, and the resources', false !== strpos( $pane, 'id="pos-mission-' . $mine . '-details"' ) && false !== strpos( $pane, 'id="pos-mission-' . $mine . '-spots"' ) && false !== strpos( $pane, 'id="pos-mission-' . $mine . '-resources"' ) && false !== strpos( $pane, 'Cans in the ring.' ) && false !== strpos( $pane, $name ) );
	$check( 'a link to its public page', false !== strpos( $pane, 'href="' . esc_url( (string) get_permalink( $mine ) ) . '"' ) && false !== strpos( $pane, 'Open the public page' ) );
	$check( 'and no form', false === strpos( $pane, '<form' ) );
	$check( 'a cleanup opens in a pane too, with a link to the Spot\'s public page', is_int( $mine_record ) && false !== strpos( $html, 'href="#pos-pane-record-' . $mine_record . '"' ) && 1 === preg_match( '#id="pos-pane-record-' . $mine_record . '".*?Swept the glass\..*?href="' . preg_quote( esc_url( (string) get_permalink( $s4 ) ), '#' ) . '"#s', $html ) );
	$check( 'the drawer comes with the panes', false !== strpos( $html, 'class="packout-drawer pos-drawer"' ) );
	$as( $ana );
	$html = Profile::render( array( 'view' => 'impact' ) );
	$pane = (string) strstr( $html, 'id="pos-pane-mission-' . $mine . '"' );
	$pane = substr( $pane, 0, (int) strpos( $pane . '<section class="packout-drawer__pane', '<section class="packout-drawer__pane', 10 ) );
	$check( 'a lead\'s pane on My impact has no form either, and the way to change the mission', false !== strpos( $html, 'id="pos-pane-mission-' . $mine . '"' ) && false === strpos( $pane, '<form' ) && false !== strpos( $pane, 'Change it in My organizations' ) );
	$as( $fay );
	$html = Profile::render( array( 'view' => 'impact' ) );
	$check( 'a person with nothing yet has the figures and no empty lists', false !== strpos( $html, 'Nothing yet' ) && false === strpos( $html, 'pos-impact-missions' ) && false === strpos( $html, 'pos-ws__panes' ) );

	WP_CLI::log( 'Searching a map' );
	$place = Places::save(
		0,
		array(
			'name'   => 'Rimtest Corridor ' . $packout_run,
			'kind'   => 'road',
			'listed' => true,
			'shape'  => "34.60, -111.81\n34.60, -111.79\n34.65, -111.79\n34.65, -111.81",
		),
		$coordinator
	);
	if ( is_int( $place ) ) {
		$packout_posts[] = $place;
	}
	$found = Search::find( '  rimtest   ', array( 34.6, -111.8 ), 0 );
	$check( 'a search answers with four kinds of result, kept apart', array( 'query', 'locations', 'spots', 'places', 'features', 'located', 'attribution' ) === array_keys( $found ) && 'rimtest' === $found['query'] && true === $found['located'], array_keys( $found ) );
	$check( 'locations come from the geocoder, with a name and where it is', 2 === count( $found['locations'] ) && 'Rimtest Junction' === $found['locations'][0]['name'] && 'Yavapai County, Arizona, United States' === $found['locations'][0]['detail'] && 34.6012 === $found['locations'][0]['lat'] && -111.8034 === $found['locations'][0]['lng'], $found['locations'] );
	$check( 'the box around one is west, south, east, north', array( -111.82, 34.59, -111.79, 34.61 ) === $found['locations'][0]['bbox'] && null === $found['locations'][1]['bbox'] && 'Rimtest Road' === $found['locations'][1]['name'] );
	$check( 'the geocoder is told which site is asking, and is asked for what is near the map', 1 === $geocoder['calls'] && 0 === strpos( $geocoder['agent'], 'PackOutSpot/' ) && false !== strpos( $geocoder['agent'], wp_parse_url( home_url(), PHP_URL_HOST ) ) && false !== strpos( $geocoder['url'], 'viewbox=' ) && false !== strpos( $geocoder['url'], 'q=rimtest' ), $geocoder );
	$check( 'and is credited', false !== strpos( $found['attribution'], 'OpenStreetMap' ) );
	$titles = wp_list_pluck( $found['spots'], 'id' );
	$check( 'Spots on the public map are found by what they say', in_array( $s1, $titles, true ) && in_array( $s2, $titles, true ) && in_array( $s4, $titles, true ), $titles );
	$check( 'a Spot waiting for review is not found by a visitor', ! in_array( $s3, $titles, true ) );
	$check( 'its reporter and a coordinator find it', in_array( $s3, wp_list_pluck( Search::spots( 'rimtest mattress', $gil ), 'id' ), true ) && in_array( $s3, wp_list_pluck( Search::spots( 'rimtest mattress', $coordinator ), 'id' ), true ) && ! in_array( $s3, wp_list_pluck( Search::spots( 'rimtest mattress', $cal ), 'id' ), true ) );
	$check( 'every word has to match', array() === Search::spots( 'rimtest zebra', 0 ) && 1 === count( Search::spots( 'rimtest tires turnout', 0 ) ) );
	$check( 'a Spot result says its category and its stage, and where to go', isset( $found['spots'][0]['detail'], $found['spots'][0]['lat'], $found['spots'][0]['lng'], $found['spots'][0]['url'] ) && false !== strpos( $found['spots'][0]['detail'], 'Cleanup' ), $found['spots'][0] ?? null );
	if ( $hub ) {
		$check( 'a listed Place is found by its name, with the box around its outline', is_int( $place ) && 1 === count( $found['places'] ) && $place === $found['places'][0]['id'] && array( -111.81, 34.6, -111.79, 34.65 ) === $found['places'][0]['bbox'] && get_permalink( $place ) === $found['places'][0]['url'], $found['places'] );
	} else {
		$skip( 'Places are kept on packout.spot.' );
	}
	if ( is_int( $place ) ) {
		Places::save( $place, array( 'listed' => false ), $coordinator );
		$check( 'a Place that is not listed is not found', array() === Search::places( 'rimtest corridor' ) );
		Places::save( $place, array( 'listed' => true ), $coordinator );
	}
	Search::find( 'RIMTEST', array( 34.61, -111.81 ), 0 );
	$check( 'the same search from a nearby map is answered from what was kept', 1 === $geocoder['calls'], $geocoder['calls'] );
	$geocoder['mode'] = 'fail';
	$found            = Search::find( 'rimtest tires', array( 10.0, 10.0 ), 0 );
	$check( 'when the geocoder cannot be reached, the Spots and Places are still listed', false === $found['located'] && array() === $found['locations'] && 1 === count( $found['spots'] ) && 2 === $geocoder['calls'], $found );
	$geocoder['mode'] = 'answer';
	$given            = static fn() => array(
		array(
			'name' => 'From a filter',
			'lat'  => 1.5,
			'lng'  => 2.5,
		),
		array( 'name' => 'No place' ),
	);
	add_filter( 'packout_spot_geocode', $given );
	$found            = Search::find( 'anything at all', null, 0 );
	remove_filter( 'packout_spot_geocode', $given );
	$check( 'a site can answer location searches itself', 1 === count( $found['locations'] ) && 'From a filter' === $found['locations'][0]['name'] && 2 === $geocoder['calls'] );
	$check( 'a search of one character asks nobody', array() === Search::locations( 'a', null ) && array() === Search::spots( 'a', 0 ) && array() === Search::places( 'a' ) && 2 === $geocoder['calls'] );
	delete_transient( 'pos_line_' . md5( sprintf( '%.4F,%.4F', 34.25, -111.25 ) ) );
	$line = Search::line( 34.25, -111.25 );
	$check( 'a road keeps its name at any zoom', is_array( $line ) && 'Rimtest Road' === $line['name'] && 'secondary' === $line['class'] && 'gravel' === $line['surface'] && 'FR 123' === $line['ref'] && false !== strpos( $geocoder['url'], '/reverse' ) && false !== strpos( $geocoder['url'], 'zoom=16' ) && 0 === strpos( $geocoder['agent'], 'PackOutSpot/' ), $line );
	$calls = $geocoder['calls'];
	$again = Search::line( 34.25004, -111.25004 );
	$check( 'the same stretch of road is not asked for again', $again === $line && $calls === $geocoder['calls'], $again );

	$routes = rest_get_server()->get_routes();
	$check( 'the search has an address anyone can ask', isset( $routes[ '/' . Rest::NAMESPACE . '/search' ] ) );
	$check( 'a road lookup has an address anyone can ask', isset( $routes[ '/' . Rest::NAMESPACE . '/line' ] ) );
	$ask = static function ( string $query ) {
		$request = new WP_REST_Request( 'GET', '/' . Rest::NAMESPACE . '/search' );
		$request->set_query_params(
			array(
				'q'    => $query,
				'near' => '34.6,-111.8',
			)
		);
		return rest_get_server()->dispatch( $request );
	};
	$as( 0 );
	$response = $ask( 'r' );
	$check( 'which refuses a search of one character', 400 === $response->get_status(), $response->get_status() );
	$as( $fay );
	$response = $ask( 'rimtest' );
	$check( 'and answers a real one, not to be kept by a cache', 200 === $response->get_status() && 2 === count( $response->get_data()['locations'] ) && false !== strpos( (string) ( $response->get_headers()['Cache-Control'] ?? '' ), 'no-cache' ), $response->get_status() );
	$last = 200;
	for ( $turn = 0; $turn < 30; $turn++ ) {
		$last = $ask( 'rimtest' )->get_status();
	}
	$check( 'a person who searches thirty times in five minutes is asked to wait', 429 === $last, $last );
	delete_transient( 'pos_limit_' . md5( 'search_u' . $fay ) );

	$html = Blocks::render_map(
		array(
			'height'      => 420,
			'showFilters' => true,
		)
	);
	$check( 'the map block has the search box', false !== strpos( $html, 'class="pos-map__search"' ) && wp_script_is( 'packout-search', 'registered' ) && false !== strpos( $html, '&quot;search&quot;:true' ) );
	$html = Blocks::render_map(
		array(
			'height'      => 420,
			'showFilters' => true,
			'showSearch'  => false,
		)
	);
	$check( 'unless the block turns it off', false === strpos( $html, 'pos-map__search' ) && false !== strpos( $html, '&quot;search&quot;:false' ) );
	$settings = Search::settings();
	$check( 'the box is told where to ask and what to say', 0 === strpos( $settings['url'], rest_url( Rest::NAMESPACE . '/search' ) ) && isset( $settings['text']['locations'], $settings['text']['spots'], $settings['text']['places'], $settings['text']['offline'] ) );
	$html = Blocks::render_report( array( 'showForm' => true ) );
	if ( preg_match( '/data-pos-locate="([^"]+)"/', $html, $found ) ) {
		$sent = json_decode( html_entity_decode( $found[1], ENT_QUOTES ), true );
		$check( 'the report form is given a map to place the pin on, with the search', ( isset( $sent['map']['style'], $sent['map']['center'], $sent['map']['hint'] ) && wp_script_is( 'packout-search', 'enqueued' ) ) || ( isset( $sent['map']['style'] ) && in_array( 'packout-search', wp_scripts()->registered['packout-report']->deps, true ) ), $sent['map'] ?? null );
	} else {
		WP_CLI::log( '  skip  no report form is selected in settings' );
	}
} catch ( \Throwable $error ) {
	$packout_failures[] = 'The run stopped: ' . $error->getMessage();
	WP_CLI::log( '  FAIL  ' . $error->getMessage() . ' at ' . basename( $error->getFile() ) . ':' . $error->getLine() );
} finally {
	wp_set_current_user( 0 );
	remove_filter( 'pre_wp_mail', $keep_mail, 99 );
	remove_filter( 'pre_http_request', $answer, 10 );
	$_GET     = array();
	$_POST    = array();
	$_REQUEST = array();
	if ( null === $packout_settings ) {
		delete_option( Settings::option() );
	} else {
		update_option( Settings::option(), $packout_settings, false );
	}

	// What the search kept: its answers and its turn with the geocoder.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing the test's own transients.
	foreach ( (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pos\\_geo\\_%'" ) as $option ) {
		delete_transient( substr( (string) $option, strlen( '_transient_' ) ) );
	}

	foreach ( array_values( array_unique( array_filter( $packout_posts, 'is_int' ) ) ) as $post_id ) {
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
		if ( Schema::TEAM === get_post_type( $post_id ) ) {
			delete_option( 'packout_messages_' . $post_id );
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
		delete_transient( 'pos_limit_' . md5( 'search_u' . $id ) );
		is_multisite() ? wpmu_delete_user( $id ) : wp_delete_user( $id );
	}
}

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( count( $packout_failures ) . ' failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
