<?php
/**
 * Phase 4 acceptance checks for accounts, the organization workspace, leads and co-leads, teams,
 * campaigns, and resources at each level.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-4.php
 *
 * The script creates its own users, organizations, campaigns, missions, Spots, and
 * files, and removes everything it created. Mail is kept in memory and never
 * sent. It exits non-zero when a check fails.
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Accounts;
use PackOut\Core\App\Campaigns;
use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Resources;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Settings;
use PackOut\Core\App\Sponsors;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Subteams;
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
$packout_fired    = array();
$packout_pages    = array();
$packout_settings = array(
	'signup_page'    => (int) Settings::get( 'signup_page' ),
	'workspace_page' => (int) Settings::get( 'workspace_page' ),
);
$packout_run      = strtolower( wp_generate_password( 6, false ) );

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

$call = static function ( string $method, string $route, array $params = array() ) {
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

$file = static function ( string $name, string $kind ) use ( &$packout_files ): array {
	$path = wp_tempnam( $name );
	if ( 'image' === $kind ) {
		$image = imagecreatetruecolor( 120, 60 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 187, 94, 40 ) );
		imagepng( $image, $path );
	} elseif ( 'pdf' === $kind ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test file.
		file_put_contents( $path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n" );
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
	unset( $sent );
	$packout_mail[] = array(
		'to'      => is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'],
		'subject' => (string) $mail['subject'],
		'message' => (string) $mail['message'],
	);
	return true;
};
add_filter( 'pre_wp_mail', $keep_mail, 99, 2 );

$last_mail = static function ( string $to ) use ( &$packout_mail ): ?array {
	foreach ( array_reverse( $packout_mail ) as $mail ) {
		if ( strtolower( $mail['to'] ) === strtolower( $to ) ) {
			return $mail;
		}
	}
	return null;
};
$token_in  = static function ( ?array $mail ): string {
	return $mail && preg_match( '/pos_confirm=([A-Za-z0-9._%-]+)/', $mail['message'], $found ) ? rawurldecode( $found[1] ) : '';
};
$unlimit   = static function ( array $buckets ): void {
	foreach ( $buckets as $bucket ) {
		delete_transient( 'pos_limit_' . md5( $bucket ) );
	}
};

$listen        = static function ( string $name ) use ( &$packout_fired ) {
	return static function ( ...$args ) use ( &$packout_fired, $name ): void {
		$packout_fired[ $name ][] = $args;
	};
};
$packout_hooks = array();
foreach ( array( 'packout_account_created', 'packout_team_proposed', 'packout_team_approved', 'packout_team_joined', 'packout_campaign_opened' ) as $packout_hook ) {
	$packout_hooks[ $packout_hook ] = $listen( $packout_hook );
	add_action( $packout_hook, $packout_hooks[ $packout_hook ], 10, 3 );
}

// A public page as a visitor's browser would get it.
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

// A workspace form, sent as the current user. Returns `refused`, `done`, or `ignored`.
$post_form = static function ( array $fields, bool $with_nonce = true ): string {
	$_POST = $fields;
	if ( $with_nonce ) {
		$_POST['_posnonce'] = wp_create_nonce( 'packout_workspace' );
	}
	$stop = static function () {
		throw new RuntimeException( 'redirected' );
	};
	add_filter( 'wp_redirect', $stop, 1 );
	$state = 'ignored';
	try {
		Workspace::handle();
		$html  = Workspace::render( array() );
		$state = false !== strpos( $html, 'pos-ws__notice--error' ) ? 'refused' : 'ignored';
	} catch ( RuntimeException $caught ) {
		$state = 'done';
	}
	remove_filter( 'wp_redirect', $stop, 1 );
	$_POST = array();
	return $state;
};

try {
	// The two pages the plugin is pointed at.
	foreach ( array(
		'signup_page'    => '<!-- wp:packout/signup /-->',
		'workspace_page' => '<!-- wp:packout/workspace /-->',
	) as $packout_setting => $packout_block ) {
		$packout_pages[ $packout_setting ] = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'POS test ' . $packout_setting . ' ' . $packout_run,
				'post_content' => $packout_block,
			)
		);
	}
	Settings::save( $packout_pages );

	$coordinator = $user( 'pos_coordinator', 'Coordinator' );
	$stranger    = $user( 'pos_reporter', 'Stranger' );
	$rival       = $user( 'pos_lead', 'Rival' );

	WP_CLI::log( 'Signing up' );
	$admin_id = (int) get_users(
		array(
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'fields'  => 'ID',
		)
	)[0];
	$check( 'the Sign in page never opens the dashboard, even for an administrator', Accounts::landing( admin_url(), $admin_id ) === Accounts::workspace_url() && Accounts::landing( admin_url( 'admin.php?page=packout-teams' ), $admin_id ) === Accounts::workspace_url() );
	$check( 'signing in keeps a front-end return address', Accounts::landing( home_url( '/field/' ), $admin_id ) === home_url( '/field/' ) );
	$check( 'signing in with nowhere to go lands on My organizations', Accounts::landing( '', $admin_id ) === Accounts::workspace_url() );

	// The WordPress sign-in screen is the dashboard's door. Do not change these to the front end:
	// an administrator who opens a dashboard address while signed out must arrive at it.
	$admin_user = get_userdata( $admin_id );
	$overview   = admin_url( 'admin.php?page=packout-spot' );
	$check( 'an administrator who signs in on the WordPress screen is returned to the dashboard address they opened', Accounts::after_sign_in( $overview, $overview, $admin_user ) === $overview, Accounts::after_sign_in( $overview, $overview, $admin_user ) );
	$check( 'and to the dashboard when the WordPress screen was opened by itself', Accounts::after_sign_in( admin_url(), '', $admin_user ) === admin_url() && Accounts::after_sign_in( admin_url(), admin_url(), $admin_user ) === admin_url() );
	$check( 'a coordinator is returned to the Pack-Out Spot overview, not to their profile', Accounts::after_sign_in( admin_url(), admin_url(), get_userdata( $coordinator ) ) === $overview, Accounts::after_sign_in( admin_url(), admin_url(), get_userdata( $coordinator ) ) );
	$check( 'a person with no dashboard screens lands on My organizations from the WordPress screen', Accounts::after_sign_in( $overview, $overview, get_userdata( $stranger ) ) === Accounts::workspace_url() && Accounts::after_sign_in( admin_url(), '', get_userdata( $stranger ) ) === Accounts::workspace_url() );
	$check( 'the WordPress screen keeps a front-end return address for everyone', Accounts::after_sign_in( home_url( '/field/' ), home_url( '/field/' ), $admin_user ) === home_url( '/field/' ) && Accounts::after_sign_in( home_url( '/field/' ), home_url( '/field/' ), get_userdata( $stranger ) ) === home_url( '/field/' ) );
	$check( 'an address on another site is never followed', Accounts::after_sign_in( 'https://example.org/wp-admin/', 'https://example.org/wp-admin/', $admin_user ) === Accounts::workspace_url(), Accounts::after_sign_in( 'https://example.org/wp-admin/', 'https://example.org/wp-admin/', $admin_user ) );
	$email = 'pos-test-ana-' . $packout_run . '@example.test';
	$unlimit( array( 'signup-address-' . ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ), 'signup-email-' . $email ) );
	$as( 0 );
	$check( 'a name and an email address are accepted', true === Accounts::request( 'Ana Ruiz', $email ) );
	$mail  = $last_mail( $email );
	$token = $token_in( $mail );
	$check( 'the person is sent a link, and no account exists yet', '' !== $token && ! email_exists( $email ), $mail );
	$claims = Accounts::read( $token );
	$check( 'the link carries the address and the name', $claims && $email === $claims['email'] && 'Ana Ruiz' === $claims['name'] && 'signup' === $claims['kind'] );
	$check( 'a link that was altered is refused', null === Accounts::read( substr( $token, 0, -3 ) . 'abc' ) && null === Accounts::read( 'x' . $token ) );
	$check( 'a short password is refused', is_wp_error( Accounts::finish( $token, 'short' ) ) && ! email_exists( $email ) );
	$check( 'the address is refused as its own password', is_wp_error( Accounts::finish( $token, $email ) ) );
	$made = Accounts::finish( $token, wp_generate_password( 20 ) );
	$ana  = is_array( $made ) ? $made['user'] : 0;
	if ( $ana ) {
		$packout_users[] = $ana;
	}
	$account = get_userdata( $ana );
	$check( 'a good password makes the account', $account && 'Ana Ruiz' === $account->display_name && $email === $account->user_email, $made );
	$check( 'the new person is a Reporter and nothing more', $account && array( 'pos_reporter' ) === array_values( $account->roles ) && Capabilities::can( Capabilities::REPORT, $ana ) && ! Capabilities::can( Capabilities::LEAD, $ana ) );
	$check( 'packout_account_created fires', isset( $packout_fired['packout_account_created'] ) && $ana === $packout_fired['packout_account_created'][0][0] );
	$check( 'the link cannot make a second account', is_wp_error( Accounts::finish( $token, wp_generate_password( 20 ) ) ) );

	$before = count( $packout_mail );
	$check( 'asking again with that address looks the same', true === Accounts::request( 'Someone Else', $email ) );
	$again = $last_mail( $email );
	$check( 'and the message carries no sign-up link', count( $packout_mail ) === $before + 1 && '' === $token_in( $again ) && false !== strpos( $again['subject'], 'already' ) );
	Accounts::request( 'Ana Ruiz', $email );
	$before = count( $packout_mail );
	$check( 'a fourth request for one address in a day is accepted and sends nothing', true === Accounts::request( 'Ana Ruiz', $email ) && count( $packout_mail ) === $before );
	$check( 'an address that is not one is refused', is_wp_error( Accounts::request( 'Ana Ruiz', 'not-an-address' ) ) );
	$check( 'a name of one letter is refused', is_wp_error( Accounts::request( 'A', 'pos-test-b-' . $packout_run . '@example.test' ) ) );
	$unlimit( array( 'signup-address-' . ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ), 'signup-email-' . $email ) );

	WP_CLI::log( 'Starting an organization' );
	$name            = 'Rim Road Crew ' . $packout_run;
	$team            = Teams::propose(
		$ana,
		array(
			'name'        => $name,
			'description' => 'We clean the rim road.',
			'region'      => 'Coconino National Forest',
			'website'     => 'https://example.org/rim',
		)
	);
	$packout_posts[] = $team;
	$check( 'a Reporter can propose an organization', is_int( $team ) && Teams::lead( $team ) === $ana, $team );
	$check( 'it waits for a coordinator, unlisted', ! Teams::active( $team ) && 'private' === get_post_status( $team ) && ! in_array( $team, wp_list_pluck( Teams::listed(), 'id' ), true ) );
	$check( 'the site is told', null !== $last_mail( (string) get_option( 'admin_email' ) ) && false !== strpos( $last_mail( (string) get_option( 'admin_email' ) )['message'], $name ) );
	$check( 'packout_team_proposed fires', isset( $packout_fired['packout_team_proposed'] ) );
	$check( 'a second organization cannot take the name, whatever the case', is_wp_error( Teams::propose( $stranger, array( 'name' => strtoupper( $name ) ) ) ) );
	$check(
		'the founder cannot plan for it yet',
		! Missions::can_lead_team( $team, $ana ) && is_wp_error(
			Campaigns::save(
				0,
				array(
					'team'  => $team,
					'title' => 'Too soon',
				),
				$ana
			)
		)
	);
	$check( 'and cannot list it', ! is_wp_error( Teams::update_profile( $team, array( 'listed' => true ), $ana ) ) && 'private' === get_post_status( $team ) );
	$check( 'a pending organization takes no files', is_wp_error( Sponsors::save( $team, 0, array( 'name' => 'Early Sponsor' ), $ana, $file( 'logo.png', 'image' ) ) ) && is_wp_error( Resources::add( $team, array( 'title' => 'Brief' ), $ana, $file( 'brief.pdf', 'pdf' ) ) ) );
	$check( 'the founder cannot approve their own organization', is_wp_error( Teams::approve( $team, $ana ) ) && ! Teams::active( $team ) );

	$second          = Teams::propose( $ana, array( 'name' => 'Second Crew ' . $packout_run ) );
	$packout_posts[] = $second;
	$check( 'a person with two organizations waiting cannot propose a third', is_int( $second ) && is_wp_error( Teams::propose( $ana, array( 'name' => 'Third Crew ' . $packout_run ) ) ) );
	$check( 'a coordinator declines an organization with a reason', true === Teams::decline( $second, 'Please join the Rim Road Crew instead.', $coordinator ) && 'trash' === get_post_status( $second ) && false !== strpos( $last_mail( $email )['message'], 'Please join the Rim Road Crew instead.' ) );

	$check( 'a coordinator approves the organization', true === Teams::approve( $team, $coordinator ) && Teams::active( $team ) );
	$check( 'the founder is given the Lead role and told', Capabilities::can( Capabilities::LEAD, $ana ) && Missions::can_lead_team( $team, $ana ) && false !== strpos( $last_mail( $email )['subject'], 'approved' ) );
	$check( 'packout_team_approved fires', isset( $packout_fired['packout_team_approved'] ) && $team === $packout_fired['packout_team_approved'][0][0] );
	$check( 'an approved organization cannot be declined', is_wp_error( Teams::decline( $team, 'Changed my mind.', $coordinator ) ) && 'trash' !== get_post_status( $team ) );
	$check( 'the lead lists the organization', Teams::update_profile( $team, array( 'listed' => true ), $ana ) === $team && 'publish' === get_post_status( $team ) && in_array( $team, wp_list_pluck( Teams::listed(), 'id' ), true ) );

	WP_CLI::log( 'Joining an organization' );
	$ben = $user( 'pos_reporter', 'Ben' );
	$cal = $user( 'pos_reporter', 'Cal' );
	$dee = $user( 'pos_reporter', 'Dee' );
	$eli = $user( 'pos_reporter', 'Eli' );
	$check( 'a signed-in person asks to join', 'requested' === Teams::ask( $team, $ben ) && array( $ben ) === Teams::requests( $team ) && ! Teams::includes( $team, $ben ) );
	$check( 'the lead is told, by name', false !== strpos( $last_mail( $email )['message'], 'Ben Testperson' ) );
	$check( 'asking twice is one request', 'requested' === Teams::ask( $team, $ben ) && 1 === count( Teams::requests( $team ) ) );
	$check( 'someone who is not the lead cannot answer it', is_wp_error( Teams::answer( $team, $ben, true, $stranger ) ) && is_wp_error( Teams::answer( $team, $ben, true, $ben ) ) && ! Teams::includes( $team, $ben ) );
	$check( 'the lead accepts, and the person is in the organization', true === Teams::answer( $team, $ben, true, $ana ) && Teams::includes( $team, $ben ) && array() === Teams::requests( $team ) );
	$check( 'packout_team_joined fires', isset( $packout_fired['packout_team_joined'] ) && array( $team, $ben ) === array_slice( $packout_fired['packout_team_joined'][0], 0, 2 ) );
	Teams::ask( $team, $stranger );
	$check( 'a person can take a request back', Teams::withdraw( $team, $stranger ) && array() === Teams::requests( $team ) );
	Teams::ask( $team, $stranger );
	$check( 'a declined request leaves the person out of the organization', true === Teams::answer( $team, $stranger, false, $ana ) && ! Teams::includes( $team, $stranger ) );

	Teams::update_profile( $team, array( 'join' => 'open' ), $ana );
	$check( 'an open organization takes a person at once', 'joined' === Teams::ask( $team, $cal ) && Teams::includes( $team, $cal ) );
	Teams::update_profile( $team, array( 'join' => 'closed' ), $ana );
	$check( 'an invitation-only organization refuses a request', is_wp_error( Teams::ask( $team, $dee ) ) && ! Teams::includes( $team, $dee ) );

	$invited = 'pos-test-fay-' . $packout_run . '@example.test';
	$unlimit( array( 'invite-team-' . $team, 'invite-email-' . $invited, 'invite-email-' . get_userdata( $dee )->user_email ) );
	$check( 'someone who is not the lead cannot invite', is_wp_error( Teams::invite( $team, $invited, $ben ) ) && is_wp_error( Teams::invite( $team, $invited, $stranger ) ) );
	$check( 'the lead invites a person with no account', true === Teams::invite( $team, $invited, $ana ) );
	$invite = $token_in( $last_mail( $invited ) );
	$claims = Accounts::read( $invite );
	$check( 'the invitation names the organization and the address', $claims && 'invite' === $claims['kind'] && $team === $claims['team'] && $invited === $claims['email'] );
	$made = Accounts::finish( $invite, wp_generate_password( 20 ), 'Fay Okoro' );
	$fay  = is_array( $made ) ? $made['user'] : 0;
	if ( $fay ) {
		$packout_users[] = $fay;
	}
	$check( 'accepting it makes the account and puts the person in the organization', $fay > 0 && Teams::includes( $team, $fay ) && 'Fay Okoro' === get_userdata( $fay )->display_name, $made );

	$check( 'inviting a person who has an account looks the same to the lead', true === Teams::invite( $team, get_userdata( $dee )->user_email, $ana ) && ! Teams::includes( $team, $dee ) );
	$invite = $token_in( $last_mail( get_userdata( $dee )->user_email ) );
	$check( 'another person cannot use that invitation', is_wp_error( Accounts::accept( $invite, $eli ) ) && ! Teams::includes( $team, $eli ) );
	$check( 'the invited person accepts with their own account', Accounts::accept( $invite, $dee ) === $team && Teams::includes( $team, $dee ) );
	$check( 'inviting someone already in the organization is refused', is_wp_error( Teams::invite( $team, get_userdata( $dee )->user_email, $ana ) ) );
	$check( 'a sign-up link is not an invitation', is_wp_error( Accounts::accept( $token, $eli ) ) );

	Teams::admit( $team, $eli );
	$check( 'a member can leave', true === Teams::leave( $team, $eli ) && ! Teams::includes( $team, $eli ) );
	$check( 'the lead cannot leave without handing the organization over', is_wp_error( Teams::leave( $team, $ana ) ) && Teams::lead( $team ) === $ana );

	WP_CLI::log( 'Teams' );
	$north = Subteams::save(
		$team,
		0,
		array(
			'name' => 'North Rim',
			'lead' => $ben,
		),
		$ana
	);
	$south = Subteams::save(
		$team,
		0,
		array(
			'name' => 'South Rim',
			'lead' => $cal,
		),
		$ana
	);
	$check( 'the lead builds two teams, each with a lead', is_int( $north ) && is_int( $south ) && $north !== $south && 2 === count( Subteams::all( $team ) ) );
	$check( 'two teams cannot share a name', is_wp_error( Subteams::save( $team, 0, array( 'name' => 'north rim' ), $ana ) ) );
	$check(
		'a team lead is a person in the organization',
		is_wp_error(
			Subteams::save(
				$team,
				0,
				array(
					'name' => 'Outsiders',
					'lead' => $stranger,
				),
				$ana
			)
		)
	);
	$check( 'a member cannot create a team', is_wp_error( Subteams::save( $team, 0, array( 'name' => 'Mine' ), $ben ) ) && is_wp_error( Subteams::save( $team, $north, array( 'name' => 'Renamed' ), $ben ) ) );
	$check( 'the lead puts members in', true === Subteams::set_member( $team, $north, $dee, true, $ana ) && true === Subteams::set_member( $team, $south, $fay, true, $ana ) );
	$check( 'a team lead adds a member to their own team', true === Subteams::set_member( $team, $north, $fay, true, $ben ) && in_array( $fay, Subteams::get( $team, $north )['members'], true ) );
	$check( 'and not to another one', is_wp_error( Subteams::set_member( $team, $south, $dee, true, $ben ) ) && ! in_array( $dee, Subteams::get( $team, $south )['members'], true ) );
	$check( 'a person who is not in the organization cannot be put in a team', is_wp_error( Subteams::set_member( $team, $north, $stranger, true, $ana ) ) );
	Subteams::set_member( $team, $north, $fay, false, $ben );
	$check( 'leading a team gives no role', ! Capabilities::can( Capabilities::LEAD, $ben ) && ! Capabilities::can( Capabilities::IMPACT, $ben ) && array( 'pos_reporter' ) === array_values( get_userdata( $ben )->roles ) );
	$check( 'a person knows their teams', array( $north ) === Subteams::of_user( $team, $dee ) && array( $north ) === Subteams::led_by( $team, $ben ) && array() === Subteams::led_by( $team, $dee ) );

	WP_CLI::log( 'Sponsors and resources' );
	$sponsor = Sponsors::save(
		$team,
		0,
		array(
			'name' => 'Canyon Outfitters',
			'url'  => 'https://example.com/outfitters',
		),
		$ana,
		$file( 'logo.png', 'image' )
	);
	$check( 'the lead adds a sponsor with a logo', is_int( $sponsor ) && 1 === count( Sponsors::all( $team ) ) && Sponsors::all( $team )[0]['logo'] > 0, $sponsor );
	$check( 'a file that is not an image is not a logo', is_wp_error( Sponsors::save( $team, 0, array( 'name' => 'Bad Logo' ), $ana, $file( 'logo.png', 'script' ) ) ) && 1 === count( Sponsors::all( $team ) ) );
	$check( 'a member cannot add or remove a sponsor', is_wp_error( Sponsors::save( $team, 0, array( 'name' => 'Sneaky' ), $ben ) ) && is_wp_error( Sponsors::remove( $team, $sponsor, $ben ) ) && 1 === count( Sponsors::all( $team ) ) );
	$credit = Sponsors::html( Sponsors::all( $team ) );
	$check( 'a sponsor link is marked sponsored and nofollow', false !== strpos( $credit, 'rel="sponsored nofollow noopener"' ) && false !== strpos( $credit, '<img' ) && false !== strpos( $credit, 'alt="Canyon Outfitters"' ) );

	$brief  = Resources::add(
		$team,
		array(
			'title'    => 'Crew safety brief',
			'audience' => 'members',
			'note'     => 'Read before every outing.',
		),
		$ana,
		$file( 'brief.pdf', 'pdf' )
	);
	$public = Resources::add(
		$team,
		array(
			'title'    => 'Leave No Trace basics',
			'url'      => 'https://example.org/lnt',
			'audience' => 'public',
		),
		$ana
	);
	$check( 'the lead adds a file for members and a link for everyone', is_int( $brief ) && is_int( $public ) && 2 === count( Resources::all( $team ) ), array( $brief, $public ) );
	$check( 'the public list holds only the public one', array( 'Leave No Trace basics' ) === wp_list_pluck( Resources::of_team( $team, true ), 'title' ) );
	$check(
		'a resource needs a link or a file',
		is_wp_error( Resources::add( $team, array( 'title' => 'Nothing' ), $ana ) ) && is_wp_error(
			Resources::add(
				$team,
				array(
					'title' => 'Script',
					'url'   => 'javascript:alert(1)',
				),
				$ana
			)
		)
	);
	$check( 'a script is not a resource file', is_wp_error( Resources::add( $team, array( 'title' => 'Payload' ), $ana, $file( 'payload.pdf', 'script' ) ) ) && is_wp_error( Resources::add( $team, array( 'title' => 'Payload' ), $ana, $file( 'payload.php', 'script' ) ) ) );
	$check(
		'a member cannot add or remove a resource',
		is_wp_error(
			Resources::add(
				$team,
				array(
					'title' => 'Mine',
					'url'   => 'https://example.org',
				),
				$ben
			)
		) && is_wp_error(
			Resources::remove(
				$team,
				$brief,
				$ben
			)
		) && 2 === count( Resources::all( $team ) )
	);

	WP_CLI::log( 'Campaigns and missions' );
	$spot = static function ( string $what, float $lat ) use ( &$packout_posts, $stranger ): int {
		$id              = Spots::create(
			array(
				'category'    => 'cleanup-waste',
				'description' => $what,
				'lat'         => $lat,
				'lng'         => -111.65,
			),
			$stranger
		);
		$packout_posts[] = $id;
		Spots::publish( $id );
		return $id;
	};
	$s1   = $spot( 'North rim tires.', 36.21 );
	$s2   = $spot( 'South rim cans.', 36.22 );
	$s3   = $spot( 'Trailhead litter.', 36.23 );

	$campaign        = Campaigns::save(
		0,
		array(
			'team'        => $team,
			'title'       => 'Rim Road Fall Sweep ' . $packout_run,
			'description' => 'Both rims before the snow.',
			'goal'        => 'Every reported site on the rim road cleaned by October.',
			'start'       => '2027-09-01',
			'end'         => '2027-10-15',
			'subteams'    => array( $north, $south, 9999 ),
			'sponsors'    => array( $sponsor ),
			'public'      => true,
		),
		$ana
	);
	$packout_posts[] = $campaign;
	$check( 'the lead plans a campaign with both teams', is_int( $campaign ) && array( $north, $south ) === Campaigns::subteams( $campaign ) && Campaigns::team( $campaign ) === $team, $campaign );
	$check( 'packout_campaign_opened fires', isset( $packout_fired['packout_campaign_opened'] ) && $campaign === $packout_fired['packout_campaign_opened'][0][0] );
	$check(
		'a member, a team lead, and another organization\'s lead cannot plan or change it',
		is_wp_error(
			Campaigns::save(
				0,
				array(
					'team'  => $team,
					'title' => 'Mine',
				),
				$dee
			)
		) && is_wp_error(
			Campaigns::save(
				$campaign,
				array( 'title' => 'Hijacked' ),
				$ben
			)
		) && is_wp_error(
			Campaigns::save(
				$campaign,
				array( 'title' => 'Hijacked' ),
				$rival
			)
		) && is_wp_error(
			Campaigns::remove(
				$campaign,
				$rival
			)
		)
	);
	$check(
		'a campaign cannot end before it starts',
		is_wp_error(
			Campaigns::save(
				$campaign,
				array(
					'start' => '2027-10-01',
					'end'   => '2027-09-01',
				),
				$ana
			)
		)
	);

	$plan = static function ( string $title, array $spots, array $subteams, int $by ) use ( &$packout_posts, $team, $campaign, $sponsor ) {
		$saved = Missions::save(
			0,
			array(
				'title'    => $title,
				'team'     => $team,
				'campaign' => $campaign,
				'subteams' => $subteams,
				'sponsors' => array( $sponsor ),
				'spots'    => $spots,
				'public'   => true,
			),
			$by
		);
		if ( ! is_wp_error( $saved ) ) {
			$packout_posts[] = $saved;
		}
		return $saved;
	};
	$m1   = $plan( 'North rim sweep', array( $s1 ), array( $north ), $ana );
	$m2   = $plan( 'South rim sweep', array( $s2 ), array( $south ), $ana );
	$check( 'the lead plans two missions, each assigned to one team', is_int( $m1 ) && is_int( $m2 ) && array( $north ) === Missions::subteams( $m1 ) && array( $south ) === Missions::subteams( $m2 ) && Missions::campaign( $m1 ) === $campaign, array( $m1, $m2 ) );
	$check( 'a team lead cannot plan a new mission', is_wp_error( $plan( 'My own outing', array( $s3 ), array( $north ), $ben ) ) );
	$m3              = Missions::save(
		0,
		array(
			'title' => 'Trailhead tidy',
			'team'  => $team,
			'spots' => array( $s3 ),
		),
		$ana
	);
	$packout_posts[] = $m3;

	$titles = static function ( int $who ) use ( $as, $call ): array {
		$as( $who );
		list( $status, $data ) = $call( 'GET', '/missions' );
		$found                 = 200 === $status ? wp_list_pluck( $data['missions'] ?? $data, 'title' ) : array();
		sort( $found );
		return $found;
	};
	$check( 'a member of the first team gets its mission and the whole-team one', array( 'North rim sweep', 'Trailhead tidy' ) === $titles( $dee ), $titles( $dee ) );
	$check( 'a member of the second team gets theirs instead', array( 'South rim sweep', 'Trailhead tidy' ) === $titles( $fay ), $titles( $fay ) );
	$check( 'the organization lead gets all three', array( 'North rim sweep', 'South rim sweep', 'Trailhead tidy' ) === $titles( $ana ), $titles( $ana ) );
	$check( 'a person in no organization gets none', array() === $titles( $stranger ) );
	Missions::flush();
	$check( 'only the assigned crew gets the exact pins', in_array( $s1, Missions::crew_spots( $dee ), true ) && ! in_array( $s2, Missions::crew_spots( $dee ), true ) && in_array( $s2, Missions::crew_spots( $fay ), true ) );
	$as( $dee );
	list( $status, $data ) = $call( 'GET', '/missions' );
	$north_sweep           = current( array_filter( $data['missions'] ?? $data, static fn( $row ) => 'North rim sweep' === $row['title'] ) );
	$check( 'the field app is given the campaign and the team', $north_sweep && 'Rim Road Fall Sweep ' . $packout_run === $north_sweep['campaign'] && array( 'North Rim' ) === $north_sweep['subteams'] && false === $north_sweep['can_manage'], $north_sweep );

	$check( 'the lead of the second team can run its mission', Missions::can_manage( $m2, $cal ) && ! Missions::can_manage( $m1, $cal ) && ! Missions::can_manage( $m3, $cal ) );
	$check( 'and cannot edit the first', is_wp_error( Missions::save( $m1, array( 'title' => 'Taken over' ), $cal ) ) && 'North rim sweep' === get_the_title( $m1 ) );
	$edited = Missions::save(
		$m2,
		array(
			'title'    => 'South rim sweep',
			'gear'     => 'Gloves, grabbers.',
			'subteams' => array( $north ),
			'campaign' => 0,
		),
		$cal
	);
	$check( 'they can change its plan, but not who it is assigned to', $m2 === $edited && 'Gloves, grabbers.' === Missions::field( $m2, 'gear' ) && array( $south ) === Missions::subteams( $m2 ) && Missions::campaign( $m2 ) === $campaign, $edited );
	$as( $cal );
	list( $status ) = $call( 'POST', '/missions/' . $m1 . '/status', array( 'status' => 'completed' ) );
	$check( 'they cannot complete the first mission', in_array( $status, array( 403, 404 ), true ) && 'planned' === Missions::status( $m1 ), $status );
	list( $status ) = $call(
		'POST',
		'/missions/' . $m2 . '/progress',
		array(
			'revision' => Missions::revision( $m2 ),
			'outcomes' => array( $s2 => 'cleaned' ),
		)
	);
	$check( 'they record the outcome on their own', 200 === $status && 'cleaned' === Spots::field( $s2, 'status' ), $status );
	list( $status ) = $call( 'POST', '/missions/' . $m2 . '/status', array( 'status' => 'completed' ) );
	$check( 'and complete it', 200 === $status && 'completed' === Missions::status( $m2 ), $status );
	Impact::flush();
	$record = Impact::save_cleanup(
		$s2,
		array(
			'hours'       => 6,
			'people'      => 3,
			'bags'        => 9,
			'bags_source' => 'measured',
		),
		$cal
	);
	$check( 'and write up the work', is_int( $record ) && (int) Impact::field( $record, 'mission', '0' ) === $m2, $record );
	if ( is_int( $record ) ) {
		$packout_posts[] = $record;
	}
	$check( 'which they cannot verify, because that takes a lead', is_wp_error( Impact::verify( $record, $cal ) ) && true === Impact::verify( $record, $ana ) );
	$as( $dee );
	list( $status ) = $call(
		'POST',
		'/missions/' . $m2 . '/progress',
		array(
			'revision' => Missions::revision( $m2 ),
			'outcomes' => array( $s2 => 'open' ),
		)
	);
	$check( 'a member of the first team cannot touch the second mission', in_array( $status, array( 403, 404 ), true ), $status );

	$progress = Campaigns::progress( $campaign );
	$check( 'the campaign counts its missions and Spots', 2 === $progress['missions'] && 1 === $progress['completed'] && 1 === $progress['open'] && 2 === $progress['spots'] && 1 === $progress['cleaned'], $progress );
	$totals = Campaigns::totals( $campaign );
	$check( 'and adds up the records of its missions', 1 === $totals['records']['cleanups'] && 9 === $totals['outputs']['bags_measured'] && 6.0 === (float) $totals['effort']['hours_verified'], $totals );
	$empty           = Campaigns::save(
		0,
		array(
			'team'  => $team,
			'title' => 'Empty campaign ' . $packout_run,
		),
		$ana
	);
	$packout_posts[] = $empty;
	$check( 'a campaign with no missions adds up to nothing', 0 === Campaigns::totals( $empty )['records']['cleanups'] && 0 === Campaigns::progress( $empty )['missions'] );

	$other           = Teams::save(
		0,
		array(
			'name'   => 'Rival Crew ' . $packout_run,
			'lead'   => $rival,
			'listed' => false,
		)
	);
	$packout_posts[] = $other;
	$stolen          = is_int( $other ) ? Missions::save(
		0,
		array(
			'title'    => 'Rival outing',
			'team'     => $other,
			'campaign' => $campaign,
			'subteams' => array( $north ),
			'sponsors' => array( $sponsor ),
			'spots'    => array( $s3 ),
		),
		$rival
	) : null;
	$check( 'a mission cannot join another organization\'s campaign or name its teams', is_int( $stolen ) && 0 === Missions::campaign( $stolen ) && array() === Missions::subteams( $stolen ) && array() === Missions::sponsors( $stolen ) );
	if ( is_int( $stolen ) ) {
		$packout_posts[] = $stolen;
	}

	WP_CLI::log( 'What the public sees' );
	$as( 0 );
	$html  = $page_of( $team );
	$names = array( 'Ana Ruiz', 'Testperson', 'Fay Okoro', '@example.test' );
	$check( 'the organization page shows the region, the campaign, and the sponsor', false !== strpos( $html, 'Coconino National Forest' ) && false !== strpos( $html, 'Rim Road Fall Sweep' ) && false !== strpos( $html, 'Canyon Outfitters' ) && false !== strpos( $html, 'rel="sponsored nofollow noopener"' ), $html );
	$check( 'and the public resource, without the members one', false !== strpos( $html, 'Leave No Trace basics' ) && false === strpos( $html, 'Crew safety brief' ) );
	$check( 'and how many people, without naming any', false !== strpos( $html, '5 people' ) && array() === array_filter( $names, static fn( $text ) => false !== strpos( $html, $text ) ), array_filter( $names, static fn( $text ) => false !== strpos( $html, $text ) ) );
	$check( 'an invitation-only organization says so to a visitor', false !== strpos( $html, 'by invitation' ) && false === strpos( $html, 'packout_workspace' ) );
	$html = $page_of( $campaign );
	$check( 'the campaign page shows the goal, the teams, the missions, and the sponsor', false !== strpos( $html, 'cleaned by October' ) && false !== strpos( $html, 'North Rim, South Rim' ) && false !== strpos( $html, 'South rim sweep' ) && false !== strpos( $html, 'Canyon Outfitters' ), $html );
	$check( 'with no person named and no record that a coordinator has not counted', array() === array_filter( $names, static fn( $text ) => false !== strpos( $html, $text ) ) && false === strpos( $html, 'Recorded so far' ) );
	$html = $page_of( $m1 );
	$check( 'the mission page links its campaign and credits the sponsor', false !== strpos( $html, 'Rim Road Fall Sweep' ) && false !== strpos( $html, 'Canyon Outfitters' ) && array() === array_filter( $names, static fn( $text ) => false !== strpos( $html, $text ) ), $html );
	Campaigns::save( $campaign, array( 'public' => false ), $ana );
	$check( 'a campaign the lead takes back leaves the public pages', '' === $page_of( $campaign ) && false === strpos( $page_of( $team ), 'Rim Road Fall Sweep' ) && false === strpos( $page_of( $m1 ), 'Rim Road Fall Sweep' ) );
	list( $status ) = $call( 'GET', '/missions' );
	$check( 'a visitor gets no missions', in_array( $status, array( 401, 403 ), true ), $status );

	WP_CLI::log( 'The workspace refuses' );
	$as( 0 );
	$html = Workspace::render( array() );
	$check( 'a visitor is asked to sign in and shown no organization', false !== strpos( $html, 'Sign in' ) && false === strpos( $html, $name ) );
	$as( $stranger );
	$_GET = array(
		'team' => (string) $team,
		'view' => 'members',
	);
	$html = Workspace::render( array() );
	$_GET = array();
	$check( 'a person from outside the organization cannot open it', false !== strpos( $html, 'You are not in that organization' ) && false === strpos( $html, 'Ben Testperson' ) && false === strpos( $html, '@example.test' ) );
	$as( $dee );
	$_GET = array(
		'team' => (string) $team,
		'view' => 'members',
	);
	$html = Workspace::render( array() );
	$check( 'a member sees the roster by name, without email addresses or the lead\'s forms', false !== strpos( $html, 'Ben Testperson' ) && false === strpos( $html, '@example.test' ) && false === strpos( $html, 'value="invite"' ) && false === strpos( $html, 'value="remove_member"' ) );
	$as( $ana );
	$html = Workspace::render( array() );
	$_GET = array();
	$check( 'the lead sees the email addresses and the forms', false !== strpos( $html, '@example.test' ) && false !== strpos( $html, 'value="invite"' ) && false !== strpos( $html, 'value="remove_member"' ) );

	$as( $ana );
	$check(
		'a form without its nonce changes nothing',
		'refused' === $post_form(
			array(
				'packout_workspace' => 'profile',
				'team'              => $team,
				'name'              => 'Renamed without a nonce',
			),
			false
		) && get_the_title( $team ) === $name
	);
	$forms    = array(
		'profile'         => array( 'name' => 'Hijacked ' . $packout_run ),
		'hand_over'       => array( 'to' => $ben ),
		'answer'          => array(
			'who'    => $stranger,
			'accept' => '1',
		),
		'invite'          => array( 'email' => 'pos-test-x-' . $packout_run . '@example.test' ),
		'remove_member'   => array( 'who' => $ben ),
		'colead'          => array(
			'who' => $ben,
			'on'  => '1',
		),
		'subteam_colead'  => array(
			'item' => $north,
			'who'  => $dee,
			'on'   => '1',
		),
		'subteam_save'    => array(
			'item' => 0,
			'name' => 'Hijack squad',
		),
		'subteam_remove'  => array( 'item' => $north ),
		'subteam_member'  => array(
			'item' => $north,
			'who'  => $fay,
			'in'   => '1',
		),
		'sponsor_save'    => array(
			'item' => 0,
			'name' => 'Hijack Inc',
		),
		'sponsor_remove'  => array( 'item' => $sponsor ),
		'resource_add'    => array(
			'title' => 'Hijack',
			'url'   => 'https://example.org/x',
		),
		'resource_remove' => array( 'item' => $brief ),
		'campaign_save'   => array(
			'item'  => 0,
			'title' => 'Hijack campaign',
		),
		'campaign_remove' => array( 'item' => $campaign ),
		'mission_save'    => array(
			'item'  => $m1,
			'title' => 'Hijacked mission',
			'spots' => array( $s1 ),
		),
		'mission_status'  => array(
			'item' => $m1,
			'to'   => 'call_off',
		),
		'mission_trash'   => array( 'item' => $m2 ),
		'impact_unverify' => array( 'item' => $record ),
	);
	$snapshot = static fn() => md5( (string) wp_json_encode( array( get_post( $team )->post_title, Teams::lead( $team ), Teams::people( $team ), Teams::coleads( $team ), Teams::requests( $team ), Subteams::all( $team ), Sponsors::all( $team ), Resources::all( $team ), wp_list_pluck( Campaigns::of_team( $team ), 'post_title' ), get_the_title( $m1 ), Missions::status( $m1 ), get_post_status( $m2 ), Impact::verified( $record ) ) ) );
	foreach ( array(
		'a person from another organization' => $rival,
		'a person in no organization'        => $stranger,
		'a member'                           => $dee,
	) as $who => $person ) {
		$as( $person );
		$before = $snapshot();
		$let_in = array();
		foreach ( $forms as $do => $fields ) {
			if ( 'refused' !== $post_form(
				array(
					'packout_workspace' => $do,
					'team'              => $team,
				) + $fields
			) ) {
				$let_in[] = $do;
			}
		}
		$check( 'every form refuses ' . $who, array() === $let_in && $before === $snapshot(), $let_in );
	}
	$as( $cal );
	$before = $snapshot();
	$let_in = array();
	// Their own mission is theirs to trash once it is closed.
	foreach ( array_diff_key( $forms, array( 'mission_trash' => true ) ) as $do => $fields ) {
		if ( 'refused' !== $post_form(
			array(
				'packout_workspace' => $do,
				'team'              => $team,
			) + $fields
		) ) {
			$let_in[] = $do;
		}
	}
	$check( 'and the lead of the other team, outside their own mission', array() === $let_in && $before === $snapshot(), $let_in );
	$as( $ana );
	$check(
		'the lead\'s own form goes through',
		'done' === $post_form(
			array(
				'packout_workspace' => 'subteam_member',
				'team'              => $team,
				'item'              => $south,
				'who'               => $dee,
				'in'                => '1',
			)
		) && in_array(
			$dee,
			Subteams::get( $team, $south )['members'],
			true
		)
	);

	WP_CLI::log( 'Leads and co-leads' );
	$admin_mail = (string) get_option( 'admin_email' );
	$gus        = $user( 'pos_reporter', 'Gus' );
	$jo         = $user( 'pos_lead', 'Jo' );
	Teams::admit( $team, $gus );
	Teams::admit( $team, $jo );
	$check( 'only the lead names a co-lead', is_wp_error( Teams::set_colead( $team, $gus, true, $dee ) ) && is_wp_error( Teams::set_colead( $team, $gus, true, $cal ) ) && is_wp_error( Teams::set_colead( $team, $gus, true, $rival ) ) && is_wp_error( Teams::set_colead( $team, $gus, true, $gus ) ) && array() === Teams::coleads( $team ) );
	$check( 'a co-lead is a member of the organization', is_wp_error( Teams::set_colead( $team, $stranger, true, $ana ) ) && is_wp_error( Teams::set_colead( $team, $ana, true, $ana ) ) );
	$as( $ana );
	$check(
		'the lead promotes a member from the workspace',
		'done' === $post_form(
			array(
				'packout_workspace' => 'colead',
				'team'              => $team,
				'who'               => $gus,
				'on'                => '1',
			)
		) && array( $gus ) === Teams::coleads( $team ) && Teams::leads_team(
			$team,
			$gus
		)
	);
	$check( 'the co-lead is given the Lead role, and the site is told who', Capabilities::can( Capabilities::LEAD, $gus ) && false !== strpos( $last_mail( $admin_mail )['message'], 'Gus Testperson' ) && false !== strpos( $last_mail( $admin_mail )['subject'], 'co-lead' ) );
	$check( 'the co-lead is told', false !== strpos( $last_mail( get_userdata( $gus )->user_email )['subject'], 'co-lead' ) );
	$west   = Subteams::save( $team, 0, array( 'name' => 'West Rim' ), $gus );
	$shared = Resources::add(
		$team,
		array(
			'title' => 'Co-lead notes',
			'url'   => 'https://example.org/notes',
		),
		$gus
	);
	$check( 'a co-lead runs the organization: missions, teams, resources, and requests', Teams::can_run( $team, $gus ) && Missions::can_lead_team( $team, $gus ) && Missions::can_manage( $m1, $gus ) && is_int( $west ) && is_int( $shared ) && true === Resources::remove( $team, $shared, $gus ) );
	$check( 'a co-lead cannot name a co-lead, invite one, or hand the organization over', is_wp_error( Teams::set_colead( $team, $dee, true, $gus ) ) && is_wp_error( Teams::invite( $team, 'pos-test-z-' . $packout_run . '@example.test', $gus, array( 'role' => 'colead' ) ) ) && is_wp_error( Teams::hand_over( $team, $gus, $gus ) ) && Teams::lead( $team ) === $ana && array( $gus ) === Teams::coleads( $team ) );

	$hal_mail = 'pos-test-hal-' . $packout_run . '@example.test';
	$unlimit( array( 'invite-team-' . $team, 'invite-email-' . $hal_mail ) );
	$check( 'the lead invites a person as a co-lead', true === Teams::invite( $team, $hal_mail, $ana, array( 'role' => 'colead' ) ) && false !== strpos( $last_mail( $hal_mail )['subject'], 'co-lead' ) );
	$invite = $token_in( $last_mail( $hal_mail ) );
	$claims = Accounts::read( $invite );
	$check( 'the invitation carries the offer and who made it', $claims && 'colead' === $claims['role'] && $ana === $claims['by'] && $team === $claims['team'], $claims );
	$made = Accounts::finish( $invite, wp_generate_password( 20 ), 'Hal Brandt' );
	$hal  = is_array( $made ) ? $made['user'] : 0;
	if ( $hal ) {
		$packout_users[] = $hal;
	}
	$check( 'accepting it makes the person a co-lead with the Lead role', $hal > 0 && in_array( $hal, Teams::coleads( $team ), true ) && Capabilities::can( Capabilities::LEAD, $hal ) && Missions::can_lead_team( $team, $hal ), $made );
	Teams::admit( $team, $eli );
	$as( $gus );
	$check(
		'a co-lead removes a member, and cannot remove another co-lead',
		'refused' === $post_form(
			array(
				'packout_workspace' => 'remove_member',
				'team'              => $team,
				'who'               => $hal,
			)
		) && Teams::includes( $team, $hal ) && 'done' === $post_form(
			array(
				'packout_workspace' => 'remove_member',
				'team'              => $team,
				'who'               => $eli,
			)
		) && ! Teams::includes(
			$team,
			$eli
		)
	);
	$_GET = array(
		'team' => (string) $team,
		'view' => 'members',
	);
	$html = Workspace::render( array() );
	$check( 'a co-lead gets the invitation form, without the forms that name co-leads', false !== strpos( $html, 'value="invite"' ) && false === strpos( $html, 'name="packout_workspace" value="colead"' ) && false === strpos( $html, 'name="role"' ) );
	$as( $ana );
	$html = Workspace::render( array() );
	$_GET = array();
	$check( 'the lead gets them', false !== strpos( $html, 'name="packout_workspace" value="colead"' ) && false !== strpos( $html, 'name="role"' ) && false !== strpos( $html, 'Co-lead' ) );
	$check(
		'the lead returns a co-lead to member, and the role the plugin gave goes with it',
		'done' === $post_form(
			array(
				'packout_workspace' => 'colead',
				'team'              => $team,
				'who'               => $gus,
			)
		) && ! in_array(
			$gus,
			Teams::coleads( $team ),
			true
		) && Teams::includes(
			$team,
			$gus
		) && ! Capabilities::can(
			Capabilities::LEAD,
			$gus
		) && ! Missions::can_lead_team(
			$team,
			$gus
		)
	);
	$check( 'a Lead role given by hand is left alone', true === Teams::set_colead( $team, $jo, true, $ana ) && true === Teams::set_colead( $team, $jo, false, $ana ) && Capabilities::can( Capabilities::LEAD, $jo ) );
	Teams::set_colead( $team, $gus, true, $ana );
	$check( 'removing a co-lead from the organization takes the role back', Capabilities::can( Capabilities::LEAD, $gus ) && Teams::remove_member( $team, $gus ) && ! in_array( $gus, Teams::coleads( $team ), true ) && ! Capabilities::can( Capabilities::LEAD, $gus ) );
	$check( 'and so does leaving', true === Teams::leave( $team, $hal ) && ! Capabilities::can( Capabilities::LEAD, $hal ) );
	Teams::admit( $team, $gus );
	$check(
		'a place as co-lead offered by someone who is not the lead is not given',
		null === Teams::seat(
			$team,
			$gus,
			array(
				'role'    => 'colead',
				'subteam' => 0,
				'subrole' => 'member',
			),
			$cal
		) && array() === Teams::coleads( $team )
	);

	$kit             = $user( 'pos_reporter', 'Kit' );
	$lou             = $user( 'pos_reporter', 'Lou' );
	$waiting         = Teams::propose( $kit, array( 'name' => 'Waiting Crew ' . $packout_run ) );
	$packout_posts[] = $waiting;
	Teams::admit( $waiting, $lou );
	$check( 'in an organization that is waiting, a co-lead gets no role yet', true === Teams::set_colead( $waiting, $lou, true, $kit ) && array( $lou ) === Teams::coleads( $waiting ) && ! Capabilities::can( Capabilities::LEAD, $lou ) );
	$check( 'approval gives it to the lead and the co-leads', true === Teams::approve( $waiting, $coordinator ) && Capabilities::can( Capabilities::LEAD, $kit ) && Capabilities::can( Capabilities::LEAD, $lou ) );
	$check( 'and trashing the organization takes it back', (bool) wp_trash_post( $waiting ) && ! Capabilities::can( Capabilities::LEAD, $kit ) && ! Capabilities::can( Capabilities::LEAD, $lou ) );

	WP_CLI::log( 'Team leads and co-leads' );
	$check( 'a team lead names a co-lead', true === Subteams::set_colead( $team, $south, $fay, true, $cal ) && array( $fay ) === Subteams::get( $team, $south )['coleads'] && array( $south ) === Subteams::led_by( $team, $fay ) );
	$check( 'a co-lead, a member, and another team\'s lead cannot', is_wp_error( Subteams::set_colead( $team, $south, $dee, true, $fay ) ) && is_wp_error( Subteams::set_colead( $team, $south, $dee, true, $dee ) ) && is_wp_error( Subteams::set_colead( $team, $north, $dee, true, $cal ) ) && array( $fay ) === Subteams::get( $team, $south )['coleads'] && array() === Subteams::get( $team, $north )['coleads'] );
	$check( 'the co-lead runs the team\'s mission and no other, with no role', Missions::can_manage( $m2, $fay ) && ! Missions::can_manage( $m1, $fay ) && ! Missions::can_manage( $m3, $fay ) && array( 'pos_reporter' ) === array_values( get_userdata( $fay )->roles ) );
	Subteams::set_colead( $team, $south, $dee, true, $cal );
	$check( 'the co-lead chooses members of their team, and cannot take out a co-lead', true === Subteams::set_member( $team, $south, $gus, true, $fay ) && is_wp_error( Subteams::set_member( $team, $north, $gus, true, $fay ) ) && is_wp_error( Subteams::set_member( $team, $south, $dee, false, $fay ) ) && in_array( $dee, Subteams::get( $team, $south )['coleads'], true ) );
	$check( 'the team lead returns a co-lead to member', true === Subteams::set_colead( $team, $south, $dee, false, $cal ) && in_array( $dee, Subteams::get( $team, $south )['members'], true ) && array( $fay ) === Subteams::get( $team, $south )['coleads'] );
	$check( 'the organization lead names a team co-lead who was not in it yet', true === Subteams::set_colead( $team, $north, $gus, true, $ana ) && in_array( $gus, Subteams::get( $team, $north )['members'], true ) && array( $gus ) === Subteams::get( $team, $north )['coleads'] && true === Subteams::set_member( $team, $north, $gus, false, $ana ) && array() === Subteams::get( $team, $north )['coleads'] );
	$as( $cal );
	$check(
		'the team lead does it from the workspace',
		'done' === $post_form(
			array(
				'packout_workspace' => 'subteam_colead',
				'team'              => $team,
				'item'              => $south,
				'who'               => $gus,
				'on'                => '1',
			)
		) && 'done' === $post_form(
			array(
				'packout_workspace' => 'subteam_colead',
				'team'              => $team,
				'item'              => $south,
				'who'               => $gus,
			)
		) && 'refused' === $post_form(
			array(
				'packout_workspace' => 'subteam_colead',
				'team'              => $team,
				'item'              => $north,
				'who'               => $dee,
				'on'                => '1',
			)
		) && array( $fay ) === Subteams::get(
			$team,
			$south
		)['coleads']
	);

	$ivy_mail = 'pos-test-ivy-' . $packout_run . '@example.test';
	$jay_mail = 'pos-test-jay-' . $packout_run . '@example.test';
	$ned_mail = 'pos-test-ned-' . $packout_run . '@example.test';
	$nobody   = 'pos-test-y-' . $packout_run . '@example.test';
	$unlimit( array( 'invite-team-' . $team, 'invite-email-' . $ivy_mail, 'invite-email-' . $jay_mail, 'invite-email-' . $ned_mail ) );
	$in_south = array( 'subteam' => $south );
	$check( 'a team lead invites a person into it as a co-lead', true === Teams::invite( $team, $ivy_mail, $cal, $in_south + array( 'subrole' => 'colead' ) ) && false !== strpos( $last_mail( $ivy_mail )['message'], 'co-lead its team “South Rim”' ), $last_mail( $ivy_mail ) );
	$check( 'and cannot offer its lead, an organization co-lead, another team, or the organization alone', is_wp_error( Teams::invite( $team, $nobody, $cal, $in_south + array( 'subrole' => 'lead' ) ) ) && is_wp_error( Teams::invite( $team, $nobody, $cal, $in_south + array( 'role' => 'colead' ) ) ) && is_wp_error( Teams::invite( $team, $nobody, $cal, array( 'subteam' => $north ) ) ) && is_wp_error( Teams::invite( $team, $nobody, $cal ) ) && null === $last_mail( $nobody ) );
	$check( 'a team co-lead invites a member, and cannot offer a co-lead', is_wp_error( Teams::invite( $team, $nobody, $fay, $in_south + array( 'subrole' => 'colead' ) ) ) && true === Teams::invite( $team, $jay_mail, $fay, $in_south ) && false !== strpos( $last_mail( $jay_mail )['message'], 'in its team “South Rim”' ) );
	$check( 'a member cannot invite into a team', is_wp_error( Teams::invite( $team, $nobody, $dee, $in_south ) ) );
	$made = Accounts::finish( $token_in( $last_mail( $ivy_mail ) ), wp_generate_password( 20 ), 'Ivy Chen' );
	$ivy  = is_array( $made ) ? $made['user'] : 0;
	if ( $ivy ) {
		$packout_users[] = $ivy;
	}
	$check( 'accepting puts the person in the organization, in the team, as a co-lead, with no role', $ivy > 0 && Teams::includes( $team, $ivy ) && in_array( $ivy, Subteams::get( $team, $south )['coleads'], true ) && Missions::can_manage( $m2, $ivy ) && array( 'pos_reporter' ) === array_values( get_userdata( $ivy )->roles ), $made );

	Subteams::save( $team, $west, array( 'lead' => $gus ), $ana );
	$check(
		'a team lead named by the organization lead invites into theirs',
		true === Teams::invite(
			$team,
			$ned_mail,
			$gus,
			array(
				'subteam' => $west,
				'subrole' => 'colead',
			)
		)
	);
	Subteams::save( $team, $west, array( 'lead' => 0 ), $ana );
	$made = Accounts::finish( $token_in( $last_mail( $ned_mail ) ), wp_generate_password( 20 ), 'Ned Park' );
	$ned  = is_array( $made ) ? $made['user'] : 0;
	if ( $ned ) {
		$packout_users[] = $ned;
	}
	$check( 'an invitation whose sender no longer leads there gives membership of the organization only', $ned > 0 && Teams::includes( $team, $ned ) && array() === Subteams::get( $team, $west )['members'] && array() === Subteams::of_user( $team, $ned ), $made );

	$as( $cal );
	$_GET = array(
		'team' => (string) $team,
		'view' => 'subteams',
	);
	$html = Workspace::render( array() );
	$_GET = array();
	$check( 'a team lead gets the forms for their own team only', false !== strpos( $html, 'value="subteam_colead"' ) && false !== strpos( $html, 'name="subteam" value="' . $south . '"' ) && false === strpos( $html, 'name="subteam" value="' . $north . '"' ) && false === strpos( $html, 'value="subteam_save"' ) && false !== strpos( $html, 'Co-leads' ) );

	WP_CLI::log( 'Resources at each level' );
	$link   = static fn( string $slug ): string => 'https://example.org/' . $slug;
	$master = Resources::add(
		$team,
		array(
			'title' => 'Master agreement',
			'url'   => $link( 'master' ),
		),
		$ana
	);
	$sheet  = Resources::add(
		$team,
		array(
			'title'    => 'South rim sign-in sheet',
			'url'      => $link( 'sheet' ),
			'level'    => 'subteam',
			'subteam'  => $south,
			'audience' => 'public',
		),
		$cal
	);
	$permit = Resources::add(
		$team,
		array(
			'title'    => 'North rim permit',
			'url'      => $link( 'permit' ),
			'level'    => 'mission',
			'mission'  => $m1,
			'audience' => 'public',
		),
		$ben
	);
	$gate   = Resources::add(
		$team,
		array(
			'title'   => 'North rim gate code',
			'url'     => $link( 'gate' ),
			'level'   => 'subteam',
			'subteam' => $north,
		),
		$ana
	);
	$row    = static function ( $id ) use ( $team ) {
		$found = array_filter( Resources::all( $team ), static fn( $entry ) => $entry['id'] === $id );
		return $found ? current( $found ) : null;
	};
	$check( 'a resource is shared with the organization, with a team by its lead, or with a mission by the person who runs it', is_int( $master ) && is_int( $sheet ) && is_int( $permit ) && is_int( $gate ) && 'team' === $row( $master )['level'] && $south === $row( $sheet )['subteam'] && $m1 === $row( $permit )['mission'], array( $master, $sheet, $permit, $gate ) );
	$check( 'a team resource is never public, and a mission resource can be', 'members' === $row( $sheet )['audience'] && 'public' === $row( $permit )['audience'] );
	$mine  = static fn( array $where ): array => array(
		'title' => 'Not mine to share',
		'url'   => 'https://example.org/x',
	) + $where;
	$check(
		'a team lead shares with their own team and its missions only',
		is_wp_error( Resources::add( $team, $mine( array() ), $cal ) ) && is_wp_error(
			Resources::add(
				$team,
				$mine(
					array(
						'level'   => 'subteam',
						'subteam' => $north,
					)
				),
				$cal
			)
		) && is_wp_error(
			Resources::add(
				$team,
				$mine(
					array(
						'level'   => 'mission',
						'mission' => $m1,
					)
				),
				$cal
			)
		) && is_wp_error(
			Resources::add(
				$team,
				$mine(
					array(
						'level'   => 'mission',
						'mission' => $m3,
					)
				),
				$cal
			)
		)
	);
	$extra = Resources::add(
		$team,
		array(
			'title'   => 'South rim radio channels',
			'url'     => $link( 'radio' ),
			'level'   => 'subteam',
			'subteam' => $south,
		),
		$fay
	);
	$check( 'a team co-lead shares with it too', is_int( $extra ) && true === Resources::remove( $team, $extra, $fay ) && null === $row( $extra ) );
	$check(
		'a member shares nothing',
		is_wp_error(
			Resources::add(
				$team,
				$mine(
					array(
						'level'   => 'subteam',
						'subteam' => $south,
					)
				),
				$dee
			)
		) && is_wp_error(
			Resources::add(
				$team,
				$mine(
					array(
						'level'   => 'mission',
						'mission' => $m3,
					)
				),
				$dee
			)
		) && is_wp_error(
			Resources::remove(
				$team,
				$sheet,
				$dee
			)
		) && null !== $row( $sheet )
	);
	$check(
		'a resource names a team or a mission of this organization',
		is_wp_error(
			Resources::add(
				$team,
				$mine(
					array(
						'level'   => 'subteam',
						'subteam' => 9999,
					)
				),
				$ana
			)
		) && is_wp_error(
			Resources::add(
				$team,
				$mine(
					array(
						'level'   => 'mission',
						'mission' => $s1,
					)
				),
				$ana
			)
		) && ( ! isset( $stolen ) || ! is_int( $stolen ) || is_wp_error(
			Resources::add(
				$team,
				$mine(
					array(
						'level'   => 'mission',
						'mission' => $stolen,
					)
				),
				$ana
			)
		) )
	);

	$sees = static fn( int $who ): array => wp_list_pluck( Resources::for_user( $team, $who ), 'title' );
	$has  = static fn( array $titles, array $wanted, array $unwanted ): bool => array() === array_diff( $wanted, $titles ) && array() === array_intersect( $unwanted, $titles );
	$check( 'the organization\'s leads and a coordinator are shown every one', $has( $sees( $ana ), array( 'Master agreement', 'South rim sign-in sheet', 'North rim permit', 'North rim gate code' ), array() ) && $has( $sees( $coordinator ), array( 'Master agreement', 'South rim sign-in sheet', 'North rim permit', 'North rim gate code' ), array() ), $sees( $ana ) );
	$check( 'a person in one team is shown the organization\'s and their own', $has( $sees( $fay ), array( 'Master agreement', 'South rim sign-in sheet' ), array( 'North rim permit', 'North rim gate code' ) ), $sees( $fay ) );
	$check( 'a team lead is not shown another team\'s', $has( $sees( $ben ), array( 'Master agreement', 'North rim permit', 'North rim gate code' ), array( 'South rim sign-in sheet' ) ), $sees( $ben ) );
	$check( 'a person in both is shown both', $has( $sees( $dee ), array( 'Master agreement', 'South rim sign-in sheet', 'North rim permit', 'North rim gate code' ), array() ), $sees( $dee ) );
	$check( 'a person outside the organization is shown none', array() === $sees( $stranger ) && array() === $sees( $rival ) && array() === $sees( 0 ) );

	Missions::flush();
	$as( $dee );
	list( $status, $data ) = $call( 'GET', '/missions' );
	$sent                  = array();
	foreach ( 200 === $status ? ( $data['missions'] ?? $data ) : array() as $mission_row ) {
		$sent[ $mission_row['title'] ] = $mission_row['resources'] ?? null;
	}
	$north_titles = wp_list_pluck( $sent['North rim sweep'] ?? array(), 'title' );
	$check( 'the field app lists what applies to a mission: its own first, then its team\'s, then the organization\'s', $has( $north_titles, array( 'North rim permit', 'North rim gate code', 'Master agreement' ), array( 'South rim sign-in sheet' ) ) && 'North rim permit' === $north_titles[0] && 'This mission' === $sent['North rim sweep'][0]['for'] && 'North Rim' === $sent['North rim sweep'][1]['for'] && $link( 'permit' ) === $sent['North rim sweep'][0]['link'], $sent['North rim sweep'] ?? $status );
	$check( 'a whole-team mission carries the organization\'s resources only', $has( wp_list_pluck( $sent['Trailhead tidy'] ?? array(), 'title' ), array( 'Master agreement' ), array( 'South rim sign-in sheet', 'North rim permit', 'North rim gate code' ) ), $sent['Trailhead tidy'] ?? $status );

	$as( 0 );
	$html = $page_of( $m1 );
	$check( 'the public mission page lists the mission\'s public resource and nothing from the organization or a team', false !== strpos( $html, 'North rim permit' ) && false === strpos( $html, 'Master agreement' ) && false === strpos( $html, 'gate code' ) && false === strpos( $html, 'Crew safety brief' ), $html );
	$html = $page_of( $team );
	$check( 'the public organization page lists no team or mission resource', false !== strpos( $html, 'Leave No Trace basics' ) && false === strpos( $html, 'North rim permit' ) && false === strpos( $html, 'sign-in sheet' ) && false === strpos( $html, 'Master agreement' ) );

	$as( $dee );
	$_GET = array(
		'team' => (string) $team,
		'view' => 'resources',
	);
	$html = Workspace::render( array() );
	$check( 'a member sees their resources under each place, with no forms', false !== strpos( $html, 'Master agreement' ) && false !== strpos( $html, 'Team: South Rim' ) && false !== strpos( $html, 'Mission: North rim sweep' ) && false === strpos( $html, 'value="resource_add"' ) && false === strpos( $html, 'value="resource_remove"' ) );
	$as( $cal );
	$html = Workspace::render( array() );
	$check( 'a team lead is offered their own team and its mission, not the organization', false !== strpos( $html, '<option value="subteam-' . $south . '"' ) && false !== strpos( $html, '<option value="mission-' . $m2 . '"' ) && false === strpos( $html, '<option value="team"' ) && false === strpos( $html, '<option value="subteam-' . $north . '"' ) && false === strpos( $html, '<option value="mission-' . $m1 . '"' ) && false === strpos( $html, 'North rim gate code' ) );
	$_GET = array();
	$add  = static fn( string $owner ): array => array(
		'packout_workspace' => 'resource_add',
		'team'              => $team,
		'for'               => $owner,
		'title'             => 'From the form',
		'url'               => 'https://example.org/form',
	);
	$check( 'and the form holds them to it', 'refused' === $post_form( $add( 'team' ) ) && 'refused' === $post_form( $add( 'subteam-' . $north ) ) && 'refused' === $post_form( $add( 'mission-' . $m1 ) ) && 'done' === $post_form( $add( 'subteam-' . $south ) ) && in_array( 'From the form', $sees( $fay ), true ) && ! in_array( 'From the form', $sees( $ben ), true ) );
	$as( $ben );
	$_GET = array(
		'team' => (string) $team,
		'view' => 'mission',
		'item' => (string) $m1,
	);
	$html = Workspace::render( array() );
	$_GET = array();
	$check( 'a mission lists what applies to it, with the form that adds to it', false !== strpos( $html, 'North rim permit' ) && false !== strpos( $html, 'Master agreement' ) && false !== strpos( $html, 'North rim gate code' ) && false === strpos( $html, 'South rim sign-in sheet' ) && false !== strpos( $html, 'value="mission-' . $m1 . '"' ) );
	$check( 'each level is removed by the people who share at it', is_wp_error( Resources::remove( $team, $master, $cal ) ) && is_wp_error( Resources::remove( $team, $permit, $cal ) ) && is_wp_error( Resources::remove( $team, $sheet, $ben ) ) && is_wp_error( Resources::remove( $team, $master, $ben ) ) && true === Resources::remove( $team, $sheet, $cal ) && null === $row( $sheet ) && null !== $row( $master ) && null !== $row( $permit ) );

	$note = Resources::add(
		$team,
		array(
			'title'   => 'Trailhead parking note',
			'url'     => $link( 'parking' ),
			'level'   => 'mission',
			'mission' => $m3,
		),
		$ana
	);
	$seen = in_array( 'Trailhead parking note', $sees( $dee ), true );
	wp_trash_post( $m3 );
	Missions::flush();
	$check( 'when a mission is removed, its resources stay with the organization\'s leads', $seen && in_array( 'Trailhead parking note', $sees( $ana ), true ) && ! in_array( 'Trailhead parking note', $sees( $dee ), true ) && is_wp_error( Resources::remove( $team, $note, $dee ) ) && true === Resources::remove( $team, $note, $ana ) );

	WP_CLI::log( 'Leaving, and handing over' );
	$check( 'removing a member takes them out of their teams', Teams::remove_member( $team, $dee ) && ! in_array( $dee, Subteams::get( $team, $north )['members'], true ) && ! in_array( $dee, Subteams::get( $team, $south )['members'], true ) );
	Missions::flush();
	$check( 'and out of the missions', array() === $titles( $dee ) );
	$check( 'removing a team takes it off its missions and campaigns', true === Subteams::remove( $team, $north, $ana ) && array() === Missions::subteams( $m1 ) && array( $south ) === Campaigns::subteams( $campaign ) && Teams::includes( $team, $ben ) );
	$check( 'and its resources become the organization\'s', 'team' === $row( $gate )['level'] && in_array( 'North rim gate code', $sees( $fay ), true ) );
	$check( 'a member cannot hand the organization to themselves', is_wp_error( Teams::hand_over( $team, $ben, $ben ) ) && is_wp_error( Teams::hand_over( $team, $stranger, $ana ) ) && Teams::lead( $team ) === $ana );
	$check( 'the lead hands the organization to a member and stays on it', true === Teams::hand_over( $team, $ben, $ana ) && Teams::lead( $team ) === $ben && in_array( $ana, Teams::members( $team ), true ) && Missions::can_lead_team( $team, $ben ) && ! Missions::can_lead_team( $team, $ana ) );
	$check( 'and the site is told who now holds the Lead role', false !== strpos( $last_mail( (string) get_option( 'admin_email' ) )['message'], 'Ben Testperson' ) );
} finally {
	wp_set_current_user( 0 );
	$_POST = array();
	$_GET  = array();
	remove_filter( 'pre_wp_mail', $keep_mail, 99 );
	foreach ( $packout_hooks as $packout_hook => $packout_listener ) {
		remove_action( $packout_hook, $packout_listener, 10 );
	}
	Settings::save( $packout_settings );

	$teams = array_filter( $packout_posts, static fn( $id ) => is_int( $id ) && Schema::TEAM === get_post_type( $id ) );
	foreach ( $teams as $team_id ) {
		foreach ( get_children(
			array(
				'post_parent' => $team_id,
				'post_type'   => 'attachment',
				'fields'      => 'ids',
			)
		) as $media ) {
			wp_delete_attachment( $media, true );
		}
	}
	foreach ( array_merge( array_filter( $packout_posts, 'is_int' ), array_filter( $packout_pages, 'is_int' ) ) as $post ) {
		wp_delete_post( $post, true );
	}
	foreach ( $packout_files as $path ) {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
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
