<?php
/**
 * Phase 16 acceptance checks: partners and programs.
 *
 * A partner: proposing and approving one, its leads and co-leads, the
 * organizations under it, joining and leaving, its workspace, its public
 * page, the list of partners, and suspending it. A program: who may open
 * one, who takes part, filing campaigns and missions under it, its progress
 * and totals, its public page, and removing it.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-16.php
 *
 * The script creates its own users, Spots, organizations, partners, programs,
 * campaigns, and missions, keeps mail in memory, and removes everything it
 * created. It exits non-zero when a check fails.
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Accounts;
use PackOut\Core\App\Blocks;
use PackOut\Core\App\Campaigns;
use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Invitations;
use PackOut\Core\App\Messages;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Partner_Page;
use PackOut\Core\App\Partner_Workspace;
use PackOut\Core\App\Partners;
use PackOut\Core\App\Programs;
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

if ( ! $hub ) {
	$skip( 'Proposing, approving, joining, and suspending a partner are done on packout.spot. A partner site is one partner, and its plugin checks that partner and its programs.' );
	WP_CLI::success( '0 checks passed.' );
	return;
}

$packout_failures = array();
$packout_passed   = 0;
$packout_posts    = array();
$packout_users    = array();
$packout_pages    = array();
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
$said = static fn( $thing ): string => is_wp_error( $thing ) ? $thing->get_error_message() : ( is_scalar( $thing ) ? (string) $thing : (string) wp_json_encode( $thing ) );

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
$mail_to  = static function ( string $to, string $subject = '' ) use ( &$packout_mail ): array {
	return array_values( array_filter( $packout_mail, static fn( $mail ) => strtolower( $mail['to'] ) === strtolower( $to ) && ( '' === $subject || false !== stripos( $mail['subject'], $subject ) ) ) );
};
$token_in = static function ( ?array $mail ): string {
	return $mail && preg_match( '/pos_confirm=([A-Za-z0-9._%-]+)/', $mail['message'], $found ) ? rawurldecode( $found[1] ) : '';
};
$unlimit  = static function (): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rate limits of this run's own forms.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_pos\_limit\_%' OR option_name LIKE '\_transient\_timeout\_pos\_limit\_%'" );
	wp_cache_flush();
};

// The public page of a post, as the theme asks for it.
$page_of = static function ( int $post_id ): string {
	global $wp_query, $wp_the_query;
	$before_query = $wp_query;
	$before_main  = $wp_the_query;
	// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Standing in for the main query of a page view.
	$wp_query     = new WP_Query(
		array(
			'p'           => $post_id,
			'post_type'   => get_post_type( $post_id ),
			'post_status' => array( 'publish', 'private' ),
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

// What a visitor's request for a page answers: 200, or 404 when the plugin says "not found".
$status_of = static function ( int $post_id ): int {
	global $wp_query, $wp_the_query;
	$before_query = $wp_query;
	$before_main  = $wp_the_query;
	// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Standing in for the main query of a page view.
	$wp_query                    = new WP_Query(
		array(
			'p'           => $post_id,
			'post_type'   => get_post_type( $post_id ),
			'post_status' => array( 'publish', 'private' ),
		)
	);
	$wp_the_query                = $wp_query;
	$wp_query->is_singular       = true;
	$wp_query->is_single         = true;
	$wp_query->queried_object    = get_post( $post_id );
	$wp_query->queried_object_id = $post_id;
	// Headers cannot be sent from the command line. The query says what was decided.
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- status_header() and nocache_headers() after output.
	@Partner_Page::route();
	$status       = $wp_query->is_404() ? 404 : 200;
	$wp_query     = $before_query;
	$wp_the_query = $before_main;
	// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	return $status;
};

// A view of a partner's workspace, or of an organization's.
$tab = static function ( string $kind, int $id, string $view, int $who, string $item = '' ) use ( $as ): string {
	$as( $who );
	$_GET = array_filter(
		array(
			$kind  => (string) $id,
			'view' => $view,
			'item' => $item,
		),
		static fn( $value ) => '' !== $value
	);
	$html = Workspace::render( array() );
	$_GET = array();
	return $html;
};

// Send one of the Partner workspace's forms the way the page does, and say what it answered.
$send = static function ( string $act, int $owner, array $fields, int $who ) use ( $as ) {
	$as( $who );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = array(
		'packout_workspace' => $act,
		'team'              => (string) $owner,
		'_posnonce'         => wp_create_nonce( 'packout_workspace' ),
	) + $fields;
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This stands in for a request. The handler checks the nonce.
	$_REQUEST                  = $_POST;
	$answer                    = Partner_Workspace::act( $act, $who );
	$_POST                     = array();
	$_REQUEST                  = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return $answer[0];
};

try {
	// What an upgrade to this version does on a site that has the plugin already.
	Capabilities::install();
	Invitations::install_tables();
	Schema::register();
	flush_rewrite_rules( false );

	$work            = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Workspace ' . $packout_run,
			'post_content' => '<!-- wp:packout/workspace /-->',
		)
	);
	$signup          = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Sign up ' . $packout_run,
			'post_content' => '<!-- wp:packout/signup /-->',
		)
	);
	$packout_pages[] = (int) $work;
	$packout_pages[] = (int) $signup;
	Settings::save(
		array(
			'workspace_page' => (int) $work,
			'signup_page'    => (int) $signup,
		)
	);

	$coordinator = $user( 'pos_coordinator', 'Coordinator' );
	$ana         = $user( 'pos_lead', 'Ana' );
	$ben         = $user( 'pos_reporter', 'Ben' );
	$cat         = $user( 'pos_lead', 'Cat' );
	$dan         = $user( 'pos_lead', 'Dan' );
	$eve         = $user( 'pos_reporter', 'Eve' );
	$gus         = $user( 'pos_reporter', 'Gus' );
	$hal         = $user( 'pos_reporter', 'Hal' );
	$ivy         = $user( 'pos_reporter', 'Ivy' );

	$org  = static function ( string $name, int $lead, bool $listed = true ) use ( &$packout_posts, $coordinator, $packout_run, $as ): int {
		$as( $coordinator );
		$id = Teams::save(
			0,
			array(
				'name'        => $name . ' ' . $packout_run,
				'description' => $name . ' looks after its own stretch.',
				'lead'        => $lead,
				'listed'      => $listed,
			)
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $name . ': ' . $id->get_error_message() );
		}
		$packout_posts[] = $id;
		return (int) $id;
	};
	$spot = static function ( string $what, float $lat, int $by ) use ( &$packout_posts, $packout_run ): int {
		$id              = Spots::create(
			array(
				'category'    => 'cleanup-waste',
				'description' => $what . ' ' . $packout_run,
				'lat'         => $lat,
				'lng'         => -111.8,
			),
			$by
		);
		$packout_posts[] = $id;
		Spots::publish( $id );
		return (int) $id;
	};
	$plan = static fn( int $team, string $title, array $spots, array $more = array() ): array => $more + array(
		'title'  => $title . ' ' . $packout_run,
		'team'   => $team,
		'spots'  => $spots,
		'start'  => '2027-05-01',
		'end'    => '2027-05-01',
		'public' => true,
	);

	$alpha = $org( 'Alpha Crew', $ana );
	$gamma = $org( 'Gamma Crew', $cat );
	$delta = $org( 'Delta Crew', $dan );
	Teams::add_member( $alpha, get_userdata( $ben )->user_login );
	Teams::add_member( $alpha, get_userdata( $hal )->user_login );
	Teams::add_member( $alpha, get_userdata( $ivy )->user_login );
	Teams::add_member( $gamma, get_userdata( $gus )->user_login );

	$s1 = $spot( 'Partnertest tires at the turnout.', 34.61, $ben );
	$s2 = $spot( 'Partnertest cans in the fire ring.', 34.62, $ben );
	$s3 = $spot( 'Partnertest glass by the gate.', 34.63, $ben );
	$s4 = $spot( 'Partnertest couch in the wash.', 34.64, $ben );
	$s5 = $spot( 'Partnertest wire by the tank.', 34.65, $ben );
	$s6 = $spot( 'Partnertest drum at the pit.', 34.66, $ben );

	// What an organization with no partner looks like, before any partner exists.
	$as( 0 );
	$before_page   = $page_of( $delta );
	$before_totals = Impact::totals(
		array(
			'team'    => $delta,
			'visible' => true,
		)
	);
	$before_tabs   = $tab( 'team', $delta, '', $dan );

	WP_CLI::log( 'The capability' );
	$check( 'a coordinator may approve and manage partners', user_can( $coordinator, Capabilities::PARTNER ) && Partners::can_edit( $coordinator ) );
	$check( 'a lead may not', ! Partners::can_edit( $ana ) && ! user_can( $ana, Capabilities::PARTNER ) );
	$check( 'the two record types are registered', post_type_exists( Schema::PARTNER ) && post_type_exists( Schema::PROGRAM ) );

	WP_CLI::log( 'Proposing a partner' );
	$check( 'a person who leads no organization is refused', is_wp_error( Partners::propose( $ben, array( 'name' => 'Nobody Council ' . $packout_run ) ) ) );
	$html = $tab( 'team', 0, '', $ben );
	$check( 'and is offered no Propose a partner button', false === strpos( $html, 'data-pos-open="partner-new"' ) && false === strpos( $html, 'value="partner_propose"' ) );
	$html = $tab( 'team', 0, '', $ana );
	$check( 'a lead of an active organization is offered it beside Start an organization', false !== strpos( $html, 'value="partner_propose"' ) && false !== strpos( $html, 'Propose a partner' ) && false !== strpos( $html, 'Start an organization' ) );

	$partner_name    = 'Rim Country Council ' . $packout_run;
	$partner         = $send(
		'partner_propose',
		0,
		array(
			'name'        => $partner_name,
			'description' => 'The council of the rim country crews.',
			'region'      => 'Mogollon Rim',
			'website'     => 'https://example.org/council',
		),
		$ana
	);
	$packout_posts[] = $partner;
	$check( 'a lead of an active organization proposes a partner', is_int( $partner ) && $partner > 0 && Schema::PARTNER === get_post_type( $partner ), $said( $partner ) );
	$partner = (int) $partner;
	$check( 'it is pending and unlisted', 'pending' === Partners::state( $partner ) && 'publish' !== get_post_status( $partner ) && ! Partners::shown( $partner, 'page' ) && ! Partners::shown( $partner, 'directory' ) );
	$check( 'the proposer is its lead', Partners::lead( $partner ) === $ana && Partners::leads_partner( $partner, $ana ) && in_array( $partner, Partners::of_user( $ana ), true ) );
	$check(
		'it cannot open a program',
		! Partners::allows( $partner, 'program' ) && ! Programs::can_open( 'partner', $partner, $ana ) && is_wp_error(
			Programs::save(
				0,
				array(
					'owner_kind' => 'partner',
					'owner'      => $partner,
					'title'      => 'Too early ' . $packout_run,
				),
				$ana
			)
		)
	);
	$check( 'nor take an organization', ! Partners::allows( $partner, 'organization' ) && is_wp_error( Partners::create_organization( $partner, array( 'name' => 'Too Early Crew ' . $packout_run ), $ana ) ) && is_wp_error( Partners::ask( $alpha, $partner, $ana ) ) );
	$check( 'a coordinator is emailed about it', count( $mail_to( (string) get_option( 'admin_email' ) ) ) > 0 || count( $mail_to( $email_of( $coordinator ) ) ) > 0, wp_list_pluck( $packout_mail, 'subject' ) );
	$check( 'it waits in the coordinator\'s list', in_array( $partner, wp_list_pluck( Partners::pending(), 'ID' ), true ) );
	$check( 'one person has one partner waiting at a time', is_wp_error( Partners::propose( $ana, array( 'name' => 'Second Council ' . $packout_run ) ) ) );
	$check( 'a name another partner has is refused', is_wp_error( Partners::propose( $cat, array( 'name' => $partner_name ) ) ) );
	$html = $tab( 'partner', $partner, '', $ana );
	$check( 'its workspace says it is waiting, and has every tab', false !== strpos( $html, 'waiting for a coordinator' ) && 13 === preg_match_all( '#<li><a href="[^"]*partner=' . $partner . '[^"]*"#', $html ) && false !== strpos( $html, 'Organizations' ) && false !== strpos( $html, 'Programs' ) && false !== strpos( $html, 'Calendar' ) && false !== strpos( $html, 'Settings' ), preg_match_all( '#<li><a href="[^"]*partner=' . $partner . '[^"]*"#', $html ) );
	$check( 'a lead cannot approve it', is_wp_error( Partners::approve( $partner, $ana ) ) && 'pending' === Partners::state( $partner ) );

	// A site owner took the role away in the meantime. Approval gives it back.
	get_userdata( $ana )->set_role( 'pos_reporter' );
	$roles_before = (array) get_userdata( $ana )->roles;
	$approved     = Partners::approve( $partner, $coordinator );
	$check( 'a coordinator approves it', ! is_wp_error( $approved ) && 'active' === Partners::state( $partner ) && Partners::active( $partner ), $said( $approved ) );
	$check( 'and the proposer is given the Lead role by the plugin', user_can( $ana, Capabilities::LEAD ) && in_array( 'pos_lead', (array) get_userdata( $ana )->roles, true ), array( $roles_before, get_userdata( $ana )->roles ) );
	$check( 'the proposer is told', count( $mail_to( $email_of( $ana ), 'partner' ) ) > 0 || count( $mail_to( $email_of( $ana ), $partner_name ) ) > 0, wp_list_pluck( $mail_to( $email_of( $ana ) ), 'subject' ) );
	$check( 'approved, it is still not listed until its lead lists it', ! Partners::shown( $partner, 'page' ) );
	$listed = Partners::update_profile(
		$partner,
		array(
			'listed'     => true,
			'show_leads' => false,
		),
		$ana
	);
	$check( 'its lead lists it', ! is_wp_error( $listed ) && 'publish' === get_post_status( $partner ) && Partners::shown( $partner, 'page' ) && Partners::shown( $partner, 'directory' ), $said( $listed ) );

	WP_CLI::log( 'Organizations under a partner' );
	$mail_before     = count( $packout_mail );
	$beta            = $send(
		'partner_org_create',
		$partner,
		array(
			'name'        => 'Beta Crew ' . $packout_run,
			'description' => 'Beta looks after the east road.',
			'region'      => 'East road',
		),
		$ana
	);
	$packout_posts[] = $beta;
	$check( 'the partner\'s lead creates a second organization', is_int( $beta ) && $beta > 0 && Schema::TEAM === get_post_type( $beta ), $said( $beta ) );
	$beta = (int) $beta;
	$check( 'it is active without review', Teams::active( $beta ) && Partners::of_team( $beta ) === $partner );
	$check( 'the partner\'s lead leads it', Teams::lead( $beta ) === $ana );
	$check( 'and coordinators are emailed', count( array_filter( array_slice( $packout_mail, $mail_before ), static fn( $mail ) => false !== strpos( $mail['message'] . $mail['subject'], 'Beta Crew' ) ) ) > 0, wp_list_pluck( array_slice( $packout_mail, $mail_before ), 'subject' ) );
	$check( 'a member of an organization cannot create one', is_wp_error( Partners::create_organization( $partner, array( 'name' => 'Member Crew ' . $packout_run ), $ben ) ) );
	$check( 'the lead of another organization cannot either', is_wp_error( Partners::create_organization( $partner, array( 'name' => 'Outsider Crew ' . $packout_run ), $dan ) ) );
	Teams::add_member( $beta, get_userdata( $gus )->user_login );

	$check( 'Alpha is not under the partner just because its lead leads the partner', 0 === Partners::of_team( $alpha ) );
	$joined = Partners::ask( $alpha, $partner, $ana );
	$check( 'an organization whose lead also leads the partner joins at once', 'joined' === $joined && Partners::of_team( $alpha ) === $partner, $said( $joined ) );
	$check( 'the partner has two organizations', array( $alpha, $beta ) === array_values( array_intersect( array( $alpha, $beta ), Partners::organization_ids( $partner ) ) ) && 2 === count( Partners::organization_ids( $partner ) ), Partners::organization_ids( $partner ) );

	WP_CLI::log( 'What a partner\'s leads may do' );
	$check( 'a partner\'s lead can run each organization under it', Teams::can_run( $alpha, $ana ) && Teams::can_run( $beta, $ana ) && Partners::reaches( $beta, $ana ) );
	$check( 'and not one outside it', ! Teams::can_run( $delta, $ana ) && ! Partners::reaches( $delta, $ana ) );
	$html = $tab( 'partner', $partner, 'missions', $ana );
	$check( 'the Missions view asks which organization a new mission is for', false !== strpos( $html, 'Plan a mission' ) && false !== strpos( $html, 'team=' . $alpha ) && false !== strpos( $html, 'team=' . $beta ) && false !== strpos( $html, 'item=new' ) );
	$m_alpha         = Missions::save( 0, $plan( $alpha, 'Partnertest alpha day', array( $s1 ) ), $ana );
	$packout_posts[] = $m_alpha;
	$m_beta          = Missions::save( 0, $plan( $beta, 'Partnertest beta day', array( $s2 ) ), $ana );
	$packout_posts[] = $m_beta;
	$check( 'the partner\'s lead plans a mission for each organization', is_int( $m_alpha ) && is_int( $m_beta ) && Missions::team( (int) $m_alpha ) === $alpha && Missions::team( (int) $m_beta ) === $beta, array( $said( $m_alpha ), $said( $m_beta ) ) );
	$html = $tab( 'partner', $partner, 'missions', $ana );
	$check( 'both are listed in the Partner workspace, each with its organization', false !== strpos( $html, 'Partnertest alpha day' ) && false !== strpos( $html, 'Partnertest beta day' ) && false !== strpos( $html, 'Alpha Crew' ) && false !== strpos( $html, 'Beta Crew' ) );
	$html = $tab( 'team', $beta, '', $ana );
	$check( 'the partner\'s lead opens an organization\'s workspace', false !== strpos( $html, 'Beta Crew' ) && false === strpos( $html, 'You are not in that organization' ) );

	$html = $tab( 'partner', $partner, 'settings', $ben );
	$check( 'a member of one organization is refused the partner\'s Settings', false !== strpos( $html, 'You do not lead that partner.' ) && false === strpos( $html, 'value="partner_profile"' ) );
	$refused = $send( 'partner_profile', $partner, array( 'name' => 'Taken Over ' . $packout_run ), $ben );
	$check( 'and the form itself is refused', is_wp_error( $refused ) && get_the_title( $partner ) === $partner_name, $said( $refused ) );
	$html = $tab( 'team', $beta, '', $ben );
	$check( 'and the other organization\'s workspace', false === strpos( $html, 'pos-ws__tabs' ) && ! Teams::can_run( $beta, $ben ) && ! Teams::includes( $beta, $ben ) );
	$check( 'a member cannot plan for the other organization', is_wp_error( Missions::save( 0, $plan( $beta, 'Partnertest stray day', array( $s3 ) ), $ben ) ) );
	$check( 'a partner\'s lead does not own an organization another person leads', ! Teams::owns( $gamma, $ana ) );

	WP_CLI::log( 'Joining and leaving' );
	$asked = $send( 'partner_ask', $gamma, array( 'partner' => (string) $partner ), $cat );
	$check( 'an existing organization asks to join', 'requested' === $asked, $said( $asked ) );
	$check( 'nothing changes until the partner\'s lead accepts', 0 === Partners::of_team( $gamma ) && ! in_array( $gamma, Partners::organization_ids( $partner ), true ) && ! Teams::can_run( $gamma, $ana ) );
	$check(
		'the request waits on the partner',
		array(
			array(
				'team' => $gamma,
				'from' => 'team',
			),
		) === Partners::requests( $partner ),
		Partners::requests( $partner )
	);
	$html = $tab( 'partner', $partner, 'organizations', $ana );
	$check( 'and shows in the Partner workspace with Accept and Decline', false !== strpos( $html, 'Requests to join' ) && false !== strpos( $html, 'Gamma Crew' ) && false !== strpos( $html, 'value="partner_answer"' ) );
	$check( 'a member cannot answer it', is_wp_error( Partners::answer( $gamma, $partner, true, $ben ) ) && 0 === Partners::of_team( $gamma ) );

	// A cleanup by Gamma, counted publicly, before it joins.
	$m_gamma         = Missions::save( 0, $plan( $gamma, 'Partnertest gamma day', array( $s3 ) ), $cat );
	$packout_posts[] = $m_gamma;
	Missions::set_status( (int) $m_gamma, 'in_progress' );
	Spots::mark_cleaned( $s3, $cat, (int) $m_gamma );
	$r_gamma = Impact::save_cleanup(
		$s3,
		array(
			'narrative'     => 'Gamma hauled glass. ' . $packout_run,
			'hours'         => 2,
			'people'        => 2,
			'pounds'        => 30,
			'pounds_source' => 'self_reported',
		),
		$cat
	);
	Missions::set_status( (int) $m_alpha, 'in_progress' );
	Spots::mark_cleaned( $s1, $ana, (int) $m_alpha );
	$r_alpha = Impact::save_cleanup(
		$s1,
		array(
			'narrative'     => 'Alpha hauled tires. ' . $packout_run,
			'hours'         => 3,
			'people'        => 2,
			'pounds'        => 120,
			'pounds_source' => 'measured',
			'bags'          => 4,
			'bags_source'   => 'measured',
		),
		$ana
	);
	foreach ( array( $r_gamma, $r_alpha ) as $record ) {
		if ( is_int( $record ) ) {
			$packout_posts[] = $record;
			Impact::set_visible( $record, true, $coordinator );
		}
	}
	Impact::flush();
	$check( 'the records are in place', is_int( $r_gamma ) && is_int( $r_alpha ), array( $said( $r_gamma ), $said( $r_alpha ) ) );
	$only_alpha = Partners::totals( $partner, true );
	$check( 'before Gamma joins, the partner\'s totals are Alpha\'s', 1 === (int) $only_alpha['records']['cleanups'] && 120.0 === (float) $only_alpha['outputs']['pounds_measured'] && 0.0 === (float) $only_alpha['outputs']['pounds_self_reported'], $only_alpha );

	$answer = $send(
		'partner_answer',
		$partner,
		array(
			'org'    => (string) $gamma,
			'accept' => '1',
		),
		$ana
	);
	$check( 'the partner\'s lead accepts', ! is_wp_error( $answer ) && Partners::of_team( $gamma ) === $partner && array() === Partners::requests( $partner ), $said( $answer ) );
	$check( 'the organization keeps its own lead', Teams::lead( $gamma ) === $cat && Teams::can_run( $gamma, $ana ) && ! Teams::owns( $gamma, $ana ) );
	$with_gamma = Partners::totals( $partner, true );
	$sum        = static function ( array $teams, bool $visible = true ): array {
		$pounds_m = 0.0;
		$pounds_s = 0.0;
		$records  = 0;
		foreach ( $teams as $team ) {
			$totals    = Impact::totals(
				array(
					'team'    => $team,
					'visible' => $visible,
				)
			);
			$pounds_m += (float) $totals['outputs']['pounds_measured'];
			$pounds_s += (float) $totals['outputs']['pounds_self_reported'];
			$records  += (int) $totals['records']['cleanups'];
		}
		return array( $records, $pounds_m, $pounds_s );
	};
	$check(
		'its totals equal the sum of its organizations\' public totals, measured and self-reported weight apart',
		array( (int) $with_gamma['records']['cleanups'], (float) $with_gamma['outputs']['pounds_measured'], (float) $with_gamma['outputs']['pounds_self_reported'] ) === $sum( array( $alpha, $beta, $gamma ) )
			&& 120.0 === (float) $with_gamma['outputs']['pounds_measured'] && 30.0 === (float) $with_gamma['outputs']['pounds_self_reported'],
		array( $with_gamma['outputs'], $sum( array( $alpha, $beta, $gamma ) ) )
	);
	$check( 'an organization belongs to one partner at most', is_wp_error( Partners::ask( $gamma, $partner, $cat ) ) );

	$left = $send( 'partner_release', $gamma, array(), $cat );
	$check( 'the organization\'s lead leaves the partner', ! is_wp_error( $left ) && 0 === Partners::of_team( $gamma ), $said( $left ) );
	$after = Partners::totals( $partner, true );
	$check( 'after leaving, the partner\'s totals no longer include it', 1 === (int) $after['records']['cleanups'] && 0.0 === (float) $after['outputs']['pounds_self_reported'], $after['outputs'] );
	$check(
		'and the organization keeps its own history',
		1 === (int) Impact::totals(
			array(
				'team'    => $gamma,
				'visible' => true,
			)
		)['records']['cleanups'] && Missions::team( (int) $m_gamma ) === $gamma && ! Teams::can_run(
			$gamma,
			$ana
		)
	);

	// The partner asks an organization.
	$asked = Partners::ask( $delta, $partner, $ana );
	$check( 'a partner\'s lead asks an organization, and nothing changes', 'requested' === $asked && 0 === Partners::of_team( $delta ) && 'partner' === Partners::asked_by( $delta, $partner ), $said( $asked ) );
	$html = $tab( 'team', $delta, 'settings', $dan );
	$check( 'the organization\'s Settings shows the request with Accept and Decline', false !== strpos( $html, 'id="pos-settings-partner"' ) && false !== strpos( $html, 'Asked this organization to join it' ) && false !== strpos( $html, 'value="partner_answer"' ) );
	$declined = $send( 'partner_answer', $delta, array( 'partner' => (string) $partner ), $dan );
	$check( 'the organization\'s lead declines, and nothing changed', ! is_wp_error( $declined ) && 0 === Partners::of_team( $delta ) && '' === Partners::asked_by( $delta, $partner ), $said( $declined ) );

	WP_CLI::log( 'Co-leads' );
	$unlimit();
	$new_email = 'pos-test-colead-' . $packout_run . '@example.test';
	$invited   = $send( 'partner_invite', $partner, array( 'email' => $new_email ), $ana );
	$check( 'the partner\'s lead invites a co-lead by email', ! is_wp_error( $invited ), $said( $invited ) );
	$rows = Invitations::list(
		array(
			'status' => 'pending',
			's'      => $new_email,
		),
		1,
		5
	);
	$row  = $rows['rows'][0] ?? array();
	$check( 'the invitation row has scope partner', 1 === (int) $rows['total'] && 'partner' === ( $row['scope'] ?? '' ) && (int) ( $row['scope_id'] ?? 0 ) === $partner && 0 === (int) ( $row['team_id'] ?? -1 ), $row );
	$token  = $token_in( $mail_to( $new_email )[0] ?? null );
	$claims = Accounts::read( $token );
	$check( 'the link names the partner and no organization', is_array( $claims ) && $partner === (int) $claims['partner'] && 0 === (int) $claims['team'], $claims );
	$check( 'nobody is a co-lead before accepting', array() === Partners::coleads( $partner ) );
	$made = Accounts::finish( $token, wp_generate_password( 20 ), 'Fay Testperson' );
	$fay  = is_array( $made ) ? (int) $made['user'] : 0;
	if ( $fay > 0 ) {
		$packout_users[] = $fay;
	}
	$check( 'a co-lead invited by email becomes a co-lead on accepting', $fay > 0 && in_array( $fay, Partners::coleads( $partner ), true ) && Partners::leads_partner( $partner, $fay ), $said( $made ) );
	$check( 'and holds the Lead role', $fay > 0 && user_can( $fay, Capabilities::LEAD ) );
	$accepted = Invitations::get( (int) ( $row['id'] ?? 0 ) );
	$check( 'the invitation is marked accepted', is_array( $accepted ) && Invitations::STATUS_ACCEPTED === ( $accepted['status'] ?? '' ), $accepted );
	$check( 'a co-lead runs the partner and its organizations, and does not own it', Partners::can_run( $partner, $fay ) && Teams::can_run( $beta, $fay ) && ! Partners::owns( $partner, $fay ) );
	$check( 'a co-lead cannot name another co-lead', is_wp_error( Partners::set_colead( $partner, $ben, true, $fay ) ) );

	$unlimit();
	$invited = Partners::invite( $partner, $email_of( $eve ), $ana );
	$token   = $token_in( $mail_to( $email_of( $eve ) )[0] ?? null );
	$check( 'a person with an account accepts with it', ! is_wp_error( $invited ) && Accounts::accept( $token, $eve ) === $partner && in_array( $eve, Partners::coleads( $partner ), true ), $said( $invited ) );
	$named = $send(
		'partner_colead',
		$partner,
		array(
			'who' => (string) $ben,
			'on'  => '1',
		),
		$ana
	);
	$check( 'the lead names a co-lead from its organizations', ! is_wp_error( $named ) && in_array( $ben, Partners::coleads( $partner ), true ), $said( $named ) );
	$stepped = $send( 'partner_colead', $partner, array( 'who' => (string) $ben ), $ben );
	$check( 'a co-lead steps down', ! is_wp_error( $stepped ) && ! in_array( $ben, Partners::coleads( $partner ), true ) && ! Partners::can_run( $partner, $ben ), $said( $stepped ) );
	$check( 'and loses the Lead role with it, leading nothing else', ! user_can( $ben, Capabilities::LEAD ) );
	$html = $tab( 'partner', $partner, 'settings', $ana );
	$check( 'Settings has the profile, address, logo, cover, leads, and hand-over sections', 6 === preg_match_all( '#id="pos-partner-(profile|address|logo|cover|leads|hand)"#', $html ) && false !== strpos( $html, 'value="partner_hand_over"' ) && false !== strpos( $html, 'value="partner_invite"' ) );
	$html = $tab( 'partner', $partner, 'settings', $eve );
	$check( 'a co-lead sees Settings without the forms only the lead has', false !== strpos( $html, 'value="partner_profile"' ) && false === strpos( $html, 'value="partner_hand_over"' ) && false === strpos( $html, 'value="partner_invite"' ) );

	WP_CLI::log( 'The Partner workspace' );
	$html = $tab( 'team', 0, '', $ana );
	$check( 'My organizations lists the partners a person leads', false !== strpos( $html, 'class="pos-ws__partners"' ) && false !== strpos( $html, esc_html( $partner_name ) ) && false !== strpos( $html, 'partner=' . $partner ) );
	$html = $tab( 'team', 0, '', $ben );
	$check( 'and nothing about partners for a person who leads none', false === strpos( $html, 'class="pos-ws__partners"' ) );
	$html = $tab( 'partner', $partner, '', $ana );
	$check( 'Overview names the lead, counts the organizations, and lists open missions', false !== strpos( $html, 'You lead this partner.' ) && false !== strpos( $html, 'Alpha Crew' ) && false !== strpos( $html, 'Beta Crew' ) && false !== strpos( $html, 'Partnertest beta day' ) );
	$html = $tab( 'partner', $partner, 'organizations', $ana );
	$check( 'Organizations lists each with its lead and has Add an organization', false !== strpos( $html, 'value="partner_org_create"' ) && false !== strpos( $html, 'Alpha Crew' ) && false !== strpos( $html, 'Ana Testperson' ) );
	Subteams::save(
		$alpha,
		0,
		array(
			'name' => 'Alpha North ' . $packout_run,
			'lead' => $ben,
		),
		$ana
	);
	$html = $tab( 'partner', $partner, 'teams', $ana );
	$check( 'Teams lists every team by organization', false !== strpos( $html, 'Alpha North' ) && false !== strpos( $html, 'Alpha Crew' ) );
	$html = $tab( 'partner', $partner, 'members', $ana );
	$check( 'Members lists the people of each organization, with email addresses for its leads', false !== strpos( $html, 'Ben Testperson' ) && false !== strpos( $html, 'Gus Testperson' ) && false !== strpos( $html, $email_of( $gus ) ) );
	$brand = Sponsors::save(
		$partner,
		0,
		array(
			'name' => 'Council Outfitters ' . $packout_run,
			'url'  => 'https://example.org/outfitters',
		),
		$ana
	);
	$check( 'a partner has its own sponsors', is_int( $brand ) && 1 === count( Sponsors::all( $partner ) ), $said( $brand ) );
	$check( 'which a member cannot add', is_wp_error( Sponsors::save( $partner, 0, array( 'name' => 'Stray Sponsor ' . $packout_run ), $ben ) ) );
	$html = $tab( 'partner', $partner, 'sponsors', $ana );
	$check( 'Sponsors lists them and posts to the partner', false !== strpos( $html, 'Council Outfitters' ) && false !== strpos( $html, 'value="sponsor_save"' ) && 1 === preg_match( '#name="team" value="' . $partner . '"#', $html ) );
	$resource = Resources::add(
		$partner,
		array(
			'title'    => 'Council safety brief ' . $packout_run,
			'url'      => 'https://example.org/brief',
			'level'    => 'team',
			'audience' => 'members',
		),
		$ana
	);
	$check( 'a partner has its own resources', ! is_wp_error( $resource ) && 1 === count( Resources::all( $partner ) ), $said( $resource ) );
	$html = $tab( 'partner', $partner, 'resources', $ana );
	$check( 'Resources lists them', false !== strpos( $html, 'Council safety brief' ) && false !== strpos( $html, 'value="resource_add"' ) );
	$mail_before = count( $packout_mail );
	$sent        = Messages::send( $partner, 'all', 'Council news ' . $packout_run, 'The rim road reopens in May.', $ana, true );
	$reached     = array_unique( wp_list_pluck( array_slice( $packout_mail, $mail_before ), 'to' ) );
	$check( 'a partner\'s lead writes to everyone in its organizations', ! is_wp_error( $sent ) && in_array( $email_of( $ben ), $reached, true ) && in_array( $email_of( $gus ), $reached, true ) && ! in_array( $email_of( $dan ), $reached, true ), array( $said( $sent ), $reached ) );
	$check( 'which a member cannot', is_wp_error( Messages::send( $partner, 'all', 'Stray news', 'Not from a lead.', $ben, true ) ) );
	$html = $tab( 'partner', $partner, 'messages', $ana );
	$check( 'Messages lists what was sent', false !== strpos( $html, 'Council news' ) && false !== strpos( $html, 'value="message"' ) );
	$html = $tab( 'partner', $partner, 'impact', $ana );
	$check( 'Impact shows the totals and each organization\'s part', false !== strpos( $html, 'pos-ws__figures' ) && false !== strpos( $html, 'By organization' ) && false !== strpos( $html, 'Alpha Crew' ) && 1 === preg_match( '#<dt>Pounds, measured</dt><dd>120</dd>#', $html ) );
	$html = $tab( 'partner', $partner, 'campaigns', $ana );
	$check( 'Campaigns asks which organization a new campaign is for', false !== strpos( $html, 'Plan a campaign' ) && false !== strpos( $html, 'view=campaign' ) );

	WP_CLI::log( 'The partner\'s public page' );
	$as( 0 );
	$html = $page_of( $partner );
	$check( 'it opens with the header: Partner, the name, the description', false !== strpos( $html, 'pos-partner-page' ) && false !== strpos( $html, '<p class="pos-place__kind">Partner</p>' ) && false !== strpos( $html, esc_html( $partner_name ) ) && false !== strpos( $html, 'The council of the rim country crews.' ) );
	$check( 'it lists its listed organizations', false !== strpos( $html, 'Alpha Crew' ) && ( false !== strpos( $html, 'Beta Crew' ) ) === ( 'publish' === get_post_status( $beta ) ) && false !== strpos( $html, 'href="' . esc_url( (string) get_permalink( $alpha ) ) . '"' ), get_post_status( $beta ) );
	$check( 'and not one that left', false === strpos( $html, 'Gamma Crew' ) );
	$check( 'it shows no email address', false === strpos( $html, '@example.test' ) );
	$check( 'it names no lead unless the partner chose that', false === strpos( $html, 'Ana Testperson' ) && false === strpos( $html, 'Eve Testperson' ) );
	$check( 'it carries the partner\'s sponsor', 1 === preg_match( '#<aside class="pos-place__support".*Council Outfitters#s', $html ) );
	$check( 'its impact is the partner\'s public totals', 1 === preg_match( '#<dt>Pounds, measured</dt><dd>120</dd>#', $html ) && 1 === preg_match( '#<dt>Pounds, self-reported</dt><dd>0</dd>#', $html ) );
	$check( 'it lists what its organizations take part in', false !== strpos( $html, 'pos-partner-page__taking-part' ) && false !== strpos( $html, 'Partnertest alpha day' ) );
	Partners::update_profile( $partner, array( 'show_leads' => true ), $ana );
	$html = $page_of( $partner );
	$check( 'once ticked, it names its lead and co-leads', false !== strpos( $html, 'Ana Testperson' ) && false !== strpos( $html, 'Eve Testperson' ) && false === strpos( $html, '@example.test' ) );
	$check( 'a visitor is shown the page', 200 === $status_of( $partner ) );
	$html = $page_of( $alpha );
	$check( 'an organization\'s page names its partner with a link', false !== strpos( $html, 'Part of <a href="' . esc_url( (string) get_permalink( $partner ) ) . '">' . esc_html( $partner_name ) . '</a>' ) );
	$html = Partner_Page::render_directory();
	$check( 'the Partner list block shows it with how many organizations are listed', false !== strpos( $html, esc_html( $partner_name ) ) && false !== strpos( $html, 'Mogollon Rim' ) && 1 === preg_match( '#\d+ organizations?#', $html ) );
	$check( 'the block is registered, with the Partner workspace block', WP_Block_Type_Registry::get_instance()->is_registered( 'packout/partners' ) && WP_Block_Type_Registry::get_instance()->is_registered( Partner_Workspace::BLOCK ) );

	WP_CLI::log( 'Programs of a partner' );
	$program         = $send(
		'program_save',
		$partner,
		array(
			'item'        => '0',
			'title'       => 'Adopt-a-Road ' . $packout_run,
			'goal'        => 'Every adopted road walked four times a year.',
			'description' => 'A standing program of the council.',
			'start'       => '2027-01-01',
			'has_orgs'    => '1',
			'orgs'        => array( (string) $alpha, (string) $beta ),
			'public'      => '1',
		),
		$ana
	);
	$packout_posts[] = $program;
	$check( 'a partner\'s lead opens a program and names two of its organizations', is_int( $program ) && Schema::PROGRAM === get_post_type( (int) $program ) && array( $alpha, $beta ) === Programs::taking_part( (int) $program ), array( $said( $program ), is_int( $program ) ? Programs::taking_part( $program ) : '' ) );
	$program = (int) $program;
	$check(
		'the partner owns it',
		array(
			'kind' => 'partner',
			'id'   => $partner,
		) === Programs::owner( $program ) && 'open' === Programs::state( $program )
	);
	$check( 'an organization outside the partner cannot be named', array( $alpha, $beta ) === Programs::taking_part( (int) Programs::save( $program, array( 'orgs' => array( $alpha, $beta, $delta ) ), $ana ) ), Programs::taking_part( $program ) );
	$check(
		'a member cannot open a program for the partner',
		! Programs::can_open( 'partner', $partner, $ben ) && is_wp_error(
			Programs::save(
				0,
				array(
					'owner_kind' => 'partner',
					'owner'      => $partner,
					'title'      => 'Stray program ' . $packout_run,
				),
				$ben
			)
		)
	);
	$check( 'an organization under a partner cannot open its own', ! Programs::can_open( 'team', $alpha, $ana ) );

	$campaign        = Campaigns::save(
		0,
		array(
			'team'    => $alpha,
			'title'   => 'Partnertest spring sweep ' . $packout_run,
			'public'  => true,
			'program' => $program,
		),
		$ana
	);
	$packout_posts[] = $campaign;
	$check( 'a campaign of one of them is filed under it', is_int( $campaign ) && Programs::of_campaign( (int) $campaign ) === $program && in_array( (int) $campaign, wp_list_pluck( Programs::campaigns( $program ), 'ID' ), true ), $said( $campaign ) );
	$outside = Campaigns::save(
		0,
		array(
			'team'    => $delta,
			'title'   => 'Partnertest outside sweep ' . $packout_run,
			'program' => $program,
		),
		$dan
	);
	if ( is_int( $outside ) ) {
		$packout_posts[] = $outside;
	}
	$check( 'a campaign of an organization outside the partner is refused', is_wp_error( $outside ) && ! in_array( 'Partnertest outside sweep ' . $packout_run, wp_list_pluck( Campaigns::of_team( $delta ), 'post_title' ), true ), $said( $outside ) );
	$html = $tab( 'team', $alpha, 'campaigns', $ana );
	$check( 'the campaign form offers the Program field', 1 === preg_match( '#<select[^>]*name="program"#', $html ) && false !== strpos( $html, 'Adopt-a-Road' ) );
	$html = $tab( 'team', $delta, 'campaigns', $dan );
	$check( 'and not in an organization that takes part in none', false === strpos( $html, 'name="program"' ) );

	$m_sweep         = Missions::save( 0, $plan( $alpha, 'Partnertest sweep day', array( $s4 ), array( 'campaign' => (int) $campaign ) ), $ana );
	$packout_posts[] = $m_sweep;
	$check( 'a mission in that campaign reports the program without having its own field set', is_int( $m_sweep ) && Programs::of_mission( (int) $m_sweep ) === $program && '' === (string) get_post_meta( (int) $m_sweep, '_pos_program', true ), array( $said( $m_sweep ), is_int( $m_sweep ) ? get_post_meta( $m_sweep, '_pos_program', true ) : '' ) );
	$moved = Missions::save( (int) $m_sweep, array( 'campaign' => 0 ), $ana );
	$check( 'moving the mission out of the campaign clears it', ! is_wp_error( $moved ) && 0 === Programs::of_mission( (int) $m_sweep ), array( $said( $moved ), Programs::of_mission( (int) $m_sweep ) ) );
	$filed = Missions::save( (int) $m_sweep, array( 'program' => $program ), $ana );
	$check( 'a mission in no campaign is filed under the program on its own', ! is_wp_error( $filed ) && Programs::of_mission( (int) $m_sweep ) === $program && (int) get_post_meta( (int) $m_sweep, '_pos_program', true ) === $program, $said( $filed ) );
	$back = Missions::save( (int) $m_sweep, array( 'campaign' => (int) $campaign ), $ana );
	$check( 'put back in the campaign, it follows the campaign and its own field is cleared', ! is_wp_error( $back ) && Programs::of_mission( (int) $m_sweep ) === $program && '' === (string) get_post_meta( (int) $m_sweep, '_pos_program', true ), $said( $back ) );
	$html = $tab( 'team', $alpha, 'mission', $ana, (string) $m_sweep );
	$check( 'its form says it is part of the program through its campaign', false !== strpos( $html, 'through its campaign' ) );
	$filed_beta = Missions::save( (int) $m_beta, array( 'program' => $program ), $ana );
	$check( 'a mission of the other organization is filed under it too', ! is_wp_error( $filed_beta ) && Programs::of_mission( (int) $m_beta ) === $program, $said( $filed_beta ) );
	$check( 'a mission of an organization that does not take part is refused', is_wp_error( Missions::save( (int) $m_gamma, array( 'program' => $program ), $cat ) ) && 0 === Programs::of_mission( (int) $m_gamma ) );

	// A cleanup on the sweep mission and one on Beta's, so the program has figures of both kinds.
	Missions::set_status( (int) $m_sweep, 'in_progress' );
	Spots::mark_cleaned( $s4, $ana, (int) $m_sweep );
	$r_sweep = Impact::save_cleanup(
		$s4,
		array(
			'narrative'     => 'Hauled the couch. ' . $packout_run,
			'hours'         => 4,
			'people'        => 3,
			'pounds'        => 80,
			'pounds_source' => 'measured',
		),
		$ana
	);
	Missions::set_status( (int) $m_beta, 'in_progress' );
	Spots::mark_cleaned( $s2, $ana, (int) $m_beta );
	$r_beta = Impact::save_cleanup(
		$s2,
		array(
			'narrative'     => 'Bagged the cans. ' . $packout_run,
			'hours'         => 1,
			'people'        => 1,
			'pounds'        => 12,
			'pounds_source' => 'self_reported',
		),
		$ana
	);
	foreach ( array( $r_sweep, $r_beta ) as $record ) {
		if ( is_int( $record ) ) {
			$packout_posts[] = $record;
			Impact::set_visible( $record, true, $coordinator );
		}
	}
	Impact::flush();
	$totals   = Programs::totals( $program );
	$expected = Impact::totals(
		array(
			'missions' => array_values( wp_list_pluck( Programs::missions( $program ), 'ID' ) ),
			'visible'  => false,
		)
	);
	$check( 'the program\'s missions are the campaign\'s and the ones filed on their own', array( (int) $m_beta, (int) $m_sweep ) === array_values( array_intersect( array( (int) $m_beta, (int) $m_sweep ), wp_list_pluck( Programs::missions( $program ), 'ID' ) ) ) && 2 === count( Programs::missions( $program ) ), wp_list_pluck( Programs::missions( $program ), 'ID' ) );
	$check( 'the program\'s totals equal Impact::totals() over its missions', $totals === $expected && 2 === (int) $totals['records']['cleanups'], array( $totals['outputs'], $expected['outputs'] ) );
	$check( 'and keep measured and self-reported weight apart', 80.0 === (float) $totals['outputs']['pounds_measured'] && 12.0 === (float) $totals['outputs']['pounds_self_reported'], $totals['outputs'] );
	$check( 'Alpha\'s earlier cleanup, on a mission in no program, is not in them', 120.0 !== (float) $totals['outputs']['pounds_measured'] );
	$split = Programs::by_organization( $program );
	$check( 'the split by organization lists both, in alphabetical order', 2 === count( $split ) && $alpha === $split[0]['team'] && $beta === $split[1]['team'] && 80.0 === (float) $split[0]['totals']['outputs']['pounds_measured'] && 12.0 === (float) $split[1]['totals']['outputs']['pounds_self_reported'], wp_list_pluck( $split, 'name' ) );
	$progress = Programs::progress( $program );
	$check( 'progress counts its campaigns, missions, and Spots', 1 === $progress['campaigns'] && 2 === $progress['missions'] && 2 === $progress['spots'] && 2 === $progress['cleaned'], $progress );

	WP_CLI::log( 'Members who plan' );
	Teams::update_profile( $alpha, array( 'member_plans' => true ), $ana );
	$m_hal           = Missions::save( 0, $plan( $alpha, 'Partnertest member day', array( $s5 ), array( 'program' => $program ) ), $hal );
	$packout_posts[] = $m_hal;
	$check( 'a member with planning on files their own mission under the program', is_int( $m_hal ) && Programs::of_mission( (int) $m_hal ) === $program, $said( $m_hal ) );
	$m_ivy           = Missions::save( 0, $plan( $alpha, 'Partnertest other member day', array( $s6 ) ), $ivy );
	$packout_posts[] = $m_ivy;
	$refused         = Missions::save( (int) $m_ivy, array( 'program' => $program ), $hal );
	$check( 'and is refused another member\'s', is_int( $m_ivy ) && is_wp_error( $refused ) && 0 === Programs::of_mission( (int) $m_ivy ), array( $said( $m_ivy ), $said( $refused ) ) );
	$check( 'a member cannot change the program itself', ! Programs::can_change( $program, $hal ) && is_wp_error( Programs::save( $program, array( 'title' => 'Renamed by a member' ), $hal ) ) );

	WP_CLI::log( 'The program in the workspace' );
	$html = $tab( 'partner', $partner, 'programs', $ana );
	$check( 'the Partner workspace lists it and has Open a program', false !== strpos( $html, 'Adopt-a-Road' ) && false !== strpos( $html, 'value="program_save"' ) && false !== strpos( $html, 'data-pos-open="program-new"' ) );
	$check( 'its pane lists the campaign and the missions, and the split by organization', false !== strpos( $html, 'Partnertest spring sweep' ) && false !== strpos( $html, 'Partnertest beta day' ) && false !== strpos( $html, 'id="pos-program-' . $program . '-split"' ) );
	$check( 'and the forms that change, close, and remove it', false !== strpos( $html, 'id="pos-program-' . $program . '-change"' ) && false !== strpos( $html, 'value="program_state"' ) && false !== strpos( $html, 'value="program_remove"' ) );
	$html = $tab( 'team', $alpha, 'programs', $ben );
	$check( 'a member sees the program in their organization\'s workspace, without the forms', false !== strpos( $html, 'Adopt-a-Road' ) && false === strpos( $html, 'value="program_save"' ) && false === strpos( $html, 'value="program_remove"' ) );
	$closed = $send(
		'program_state',
		$partner,
		array(
			'item' => (string) $program,
			'to'   => 'closed',
		),
		$ana
	);
	$check( 'a closed program takes no new campaigns or missions', ! is_wp_error( $closed ) && 'closed' === Programs::state( $program ) && is_wp_error( Missions::save( (int) $m_ivy, array( 'program' => $program ), $ana ) ) && array() === Programs::choices( $alpha ), $said( $closed ) );
	$check( 'and keeps what it has', 2 === (int) Programs::totals( $program )['records']['cleanups'] && Programs::of_campaign( (int) $campaign ) === $program );
	$saved = Missions::save(
		(int) $m_hal,
		array(
			'title'   => 'Partnertest member day renamed ' . $packout_run,
			'program' => $program,
		),
		$hal
	);
	$check( 'a mission filed under it before it closed can still be saved', ! is_wp_error( $saved ) && Programs::of_mission( (int) $m_hal ) === $program, $said( $saved ) );
	$send(
		'program_state',
		$partner,
		array(
			'item' => (string) $program,
			'to'   => 'open',
		),
		$ana
	);
	$check( 'it opens again', 'open' === Programs::state( $program ) && isset( Programs::choices( $alpha )[ $program ] ) );

	WP_CLI::log( 'The program\'s public page' );
	$as( 0 );
	$html = $page_of( $program );
	$check( 'it opens with the header: Program, the name, the description', false !== strpos( $html, 'pos-program-page' ) && false !== strpos( $html, '<p class="pos-place__kind">Program</p>' ) && false !== strpos( $html, 'Adopt-a-Road' ) && false !== strpos( $html, 'A standing program of the council.' ) );
	$check( 'it says whose program it is, with a link', false !== strpos( $html, 'A program of <a href="' . esc_url( (string) get_permalink( $partner ) ) . '">' . esc_html( $partner_name ) . '</a>' ) );
	$check( 'it lists no person', false === strpos( $html, 'Testperson' ) && false === strpos( $html, '@example.test' ) );
	$check( 'it shows the goal, who takes part, the campaign, the missions, and the impact', false !== strpos( $html, 'Every adopted road walked four times a year.' ) && false !== strpos( $html, 'Alpha Crew' ) && false !== strpos( $html, 'Partnertest spring sweep' ) && false !== strpos( $html, 'Partnertest beta day' ) && 1 === preg_match( '#<dt>Pounds, measured</dt><dd>80</dd>#', $html ) && 1 === preg_match( '#<dt>Pounds, self-reported</dt><dd>12</dd>#', $html ) );
	$check( 'a visitor is shown it', 200 === $status_of( $program ) && Programs::shown( $program ) );
	$html = $page_of( $partner );
	$check( 'and the partner\'s page lists it under Programs', false !== strpos( $html, 'pos-partner-page__programs' ) && false !== strpos( $html, 'Adopt-a-Road' ) );
	Programs::save( $program, array( 'public' => false ), $ana );
	$as( 0 );
	$check( 'a private program answers "not found" and shows nothing', 404 === $status_of( $program ) && '' === $page_of( $program ) && ! Programs::shown( $program ) );
	$check( 'and leaves the partner\'s page', false === strpos( $page_of( $partner ), 'Adopt-a-Road' ) );
	$as( $ana );
	$check( 'the people who run it can still open it, with a note', 200 === $status_of( $program ) && false !== strpos( $page_of( $program ), 'pos-subteam-page__preview' ) );
	Programs::save( $program, array( 'public' => true ), $ana );

	WP_CLI::log( 'Suspending a partner' );
	$check( 'a lead cannot suspend it', is_wp_error( Partners::suspend( $partner, $ana ) ) );
	$suspended = Partners::suspend( $partner, $coordinator, 'Checks.' );
	$as( 0 );
	$check( 'a coordinator suspends the partner', ! is_wp_error( $suspended ) && 'suspended' === Partners::state( $partner ), $said( $suspended ) );
	$check( 'its page answers "not found"', 404 === $status_of( $partner ) && '' === $page_of( $partner ) );
	$check( 'it is gone from the directory', false === strpos( Partner_Page::render_directory(), esc_html( $partner_name ) ) && ! in_array( $partner, wp_list_pluck( Partners::listed(), 'id' ), true ) );
	$check( 'its program answers "not found" because its owner is not shown', 404 === $status_of( $program ) && ! Programs::shown( $program ) );
	$html = $page_of( $alpha );
	$check( 'its organizations\' own pages remain', 'publish' === get_post_status( $alpha ) && false !== strpos( $html, 'Alpha Crew' ) && false !== strpos( $html, 'pos-team-page' ) );
	$check( 'and stop naming it', false === strpos( $html, esc_html( $partner_name ) ) );
	$check( 'a suspended partner opens no program and adds no organization', ! Programs::can_open( 'partner', $partner, $ana ) && is_wp_error( Partners::create_organization( $partner, array( 'name' => 'Suspended Crew ' . $packout_run ), $ana ) ) );
	$html = $tab( 'partner', $partner, '', $ana );
	$check( 'its leads are told in the workspace, and nothing was deleted', false !== strpos( $html, 'suspended this partner' ) && 2 === count( Partners::organization_ids( $partner ) ) && null !== Programs::get( $program ) );
	$lifted = Partners::lift( $partner, $coordinator );
	$as( 0 );
	$check( 'lifting the suspension brings back the page and the listing', ! is_wp_error( $lifted ) && 'active' === Partners::state( $partner ) && 200 === $status_of( $partner ) && in_array( $partner, wp_list_pluck( Partners::listed(), 'id' ), true ) && 200 === $status_of( $program ), $said( $lifted ) );

	WP_CLI::log( 'Removing a program' );
	$as( $ana );
	$counts  = Programs::filed( $program );
	$removed = $send( 'program_remove', $partner, array( 'item' => (string) $program ), $ana );
	$check( 'the confirmation can say how many records will be unfiled', 1 === $counts['campaigns'] && $counts['missions'] >= 2, $counts );
	$check( 'removing the program leaves its campaign and missions in place', ! is_wp_error( $removed ) && null === Programs::get( $program ) && null !== Campaigns::get( (int) $campaign ) && null !== Missions::get( (int) $m_sweep ) && null !== Missions::get( (int) $m_beta ), $said( $removed ) );
	$check( 'with no program', 0 === Programs::of_campaign( (int) $campaign ) && 0 === Programs::of_mission( (int) $m_sweep ) && 0 === Programs::of_mission( (int) $m_beta ) && '' === (string) get_post_meta( (int) $campaign, '_pos_program', true ) && '' === (string) get_post_meta( (int) $m_beta, '_pos_program', true ) );
	$check( 'and the impact records are untouched', is_int( $r_sweep ) && null !== get_post( $r_sweep ) && 80.0 === (float) Impact::totals( array( 'missions' => array( (int) $m_sweep ) ) )['outputs']['pounds_measured'] );

	WP_CLI::log( 'A program of an organization with no partner' );
	$check( 'an organization with no partner may open a program of its own', Programs::can_open( 'team', $delta, $dan ) && ! Programs::can_open( 'team', $delta, $ana ) );
	$own             = $send(
		'program_save',
		$delta,
		array(
			'item'   => '0',
			'title'  => 'Delta Trail Days ' . $packout_run,
			'goal'   => 'The loop trail walked every month.',
			'public' => '1',
		),
		$dan
	);
	$packout_posts[] = $own;
	$check(
		'it opens one',
		is_int( $own ) && array(
			'kind' => 'team',
			'id'   => $delta,
		) === Programs::owner( (int) $own ) && array( $delta ) === Programs::taking_part( (int) $own ),
		$said( $own )
	);
	$own  = (int) $own;
	$html = $tab( 'team', $delta, 'programs', $dan );
	$check( 'its workspace has a Programs view with the program', false !== strpos( $html, 'Delta Trail Days' ) && false !== strpos( $html, 'value="program_save"' ) );
	$as( 0 );
	$check( 'its public page says it is a program of the organization', false !== strpos( $page_of( $own ), 'A program of <a href="' . esc_url( (string) get_permalink( $delta ) ) . '">' ) );
	$d_campaign      = Campaigns::save(
		0,
		array(
			'team'    => $delta,
			'title'   => 'Partnertest delta sweep ' . $packout_run,
			'program' => $own,
		),
		$dan
	);
	$packout_posts[] = $d_campaign;
	$check( 'its own campaign is filed under it', is_int( $d_campaign ) && Programs::of_campaign( (int) $d_campaign ) === $own, $said( $d_campaign ) );
	$check( 'another organization\'s campaign is not', is_wp_error( Campaigns::save( (int) $campaign, array( 'program' => $own ), $ana ) ) );
	Partners::ask( $delta, $partner, $dan );
	$joined = Partners::answer( $delta, $partner, true, $ana );
	$check(
		'when it joins a partner, the program is still its own',
		! is_wp_error( $joined ) && Partners::of_team( $delta ) === $partner && array(
			'kind' => 'team',
			'id'   => $delta,
		) === Programs::owner( $own ) && Programs::of_campaign( (int) $d_campaign ) === $own,
		array( $said( $joined ), Programs::owner( $own ) )
	);
	$check( 'under a partner, the organization opens no further program', ! Programs::can_open( 'team', $delta, $dan ) && Programs::can_change( $own, $dan ) );
	$html = $tab( 'team', $delta, 'programs', $ana );
	$check( 'the partner\'s lead is offered to take it over', false !== strpos( $html, 'value="program_take_over"' ) );
	$html = $tab( 'team', $delta, 'programs', $dan );
	$check( 'and the organization\'s lead is not', false === strpos( $html, 'value="program_take_over"' ) );
	$taken = $send( 'program_take_over', $delta, array( 'item' => (string) $own ), $ana );
	$check(
		'the partner\'s lead takes it over, and the organization still takes part',
		! is_wp_error( $taken ) && array(
			'kind' => 'partner',
			'id'   => $partner,
		) === Programs::owner( $own ) && in_array( $delta, Programs::taking_part( $own ), true ) && Programs::of_campaign( (int) $d_campaign ) === $own,
		array( $said( $taken ), Programs::owner( $own ), Programs::taking_part( $own ) )
	);
	Partners::release( $delta, $dan );
	$check( 'when the organization leaves, its campaign leaves the partner\'s program', 0 === Programs::of_campaign( (int) $d_campaign ) && ! in_array( $delta, Programs::taking_part( $own ), true ) && null !== Campaigns::get( (int) $d_campaign ), array( Programs::of_campaign( (int) $d_campaign ), Programs::taking_part( $own ) ) );
	$packout_posts[] = $own;

	WP_CLI::log( 'An organization with no partner is as it was' );
	$as( 0 );
	$strip = static fn( string $html ): string => (string) preg_replace( '#(pos-ws-|wp-unique-id-|id="|for="|aria-labelledby="|aria-controls="|_posnonce" value=")[A-Za-z0-9_-]+#', '$1', $html );
	// Gamma never had a program and has left the partner. Its page is the page of an organization with no partner.
	$check( 'its public page names no partner', false === strpos( $page_of( $gamma ), 'Part of <a' ) && false === strpos( $page_of( $gamma ), esc_html( $partner_name ) ) );
	$after_page = $page_of( $delta );
	$check( 'an organization that joined and left has the page it had before', $strip( $before_page ) === $strip( $after_page ), array( strlen( $before_page ), strlen( $after_page ) ) );
	$check(
		'and the totals it had before',
		Impact::totals(
			array(
				'team'    => $delta,
				'visible' => true,
			)
		) === $before_totals
	);
	$html = $tab( 'team', $gamma, '', $cat );
	$check( 'its workspace has the tabs it had, and Programs only because it may open one', false !== strpos( $html, 'view=missions' ) && false !== strpos( $html, 'view=settings' ) && false === strpos( $html, 'Partner</dt>' ) );
	$nav  = static fn( string $html ): string => (string) preg_replace( '#.*(<nav class="pos-ws__tabs".*?</nav>).*#s', '$1', $html );
	$check( 'and the organization that joined and left has the tabs it had before', '' !== $nav( $before_tabs ) && $nav( $tab( 'team', $delta, '', $dan ) ) === $nav( $before_tabs ), array( $nav( $before_tabs ), $nav( $tab( 'team', $delta, '', $dan ) ) ) );
	$html = $tab( 'team', $gamma, 'settings', $cat );
	$check( 'its Settings offers to ask a partner', false !== strpos( $html, 'id="pos-settings-partner"' ) && false !== strpos( $html, 'value="partner_ask"' ) );

	WP_CLI::log( 'The site\'s pages' );
	$content = dirname( plugin_dir_path( PACKOUT_SPOT_FILE ), 2 ) . '/content/pages';
	$named   = array();
	foreach ( get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => 200,
		)
	) as $page ) {
		if ( false !== stripos( $page->post_content, 'Adopt-a-Road' ) || false !== stripos( $page->post_content, 'Trail Days' ) ) {
			$named[] = $page->post_name;
		}
	}
	foreach ( is_dir( $content ) ? (array) glob( $content . '/*' ) : array() as $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of the repository.
		$text = is_file( $file ) ? (string) file_get_contents( $file ) : '';
		if ( false !== stripos( $text, 'Adopt-a-Road' ) || false !== stripos( $text, 'Trail Days' ) ) {
			$named[] = basename( $file );
		}
	}
	$check( 'no page of the site names a program', array() === $named, $named );

	WP_CLI::log( 'Removing a partner' );
	$as( $coordinator );
	wp_trash_post( $partner );
	$check( 'trashing a partner leaves its organizations with no partner', 0 === Partners::of_team( $alpha ) && 0 === Partners::of_team( $beta ) && null !== Teams::get( $alpha ) && Teams::active( $beta ) );
	$check( 'and its leads keep the Lead role only while they lead something', user_can( $ana, Capabilities::LEAD ) && ! user_can( $eve, Capabilities::LEAD ) );
} catch ( \Throwable $e ) {
	$packout_failures[] = $e->getMessage();
	WP_CLI::log( '  FAIL  ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	remove_filter( 'pre_wp_mail', $keep_mail, 99 );
	$_GET     = array();
	$_POST    = array();
	$_REQUEST = array();
	wp_set_current_user( 0 );
	if ( null === $packout_settings ) {
		delete_option( Settings::option() );
	} else {
		update_option( Settings::option(), $packout_settings, false );
	}

	global $wpdb;
	foreach ( array_values( array_unique( array_filter( $packout_posts, 'is_int' ) ) ) as $post_id ) {
		if ( in_array( get_post_type( $post_id ), array( Schema::TEAM, Schema::PARTNER ), true ) ) {
			delete_option( 'packout_messages_' . $post_id );
			foreach ( Sponsors::all( $post_id ) as $sponsor ) {
				if ( ! empty( $sponsor['logo'] ) ) {
					wp_delete_attachment( (int) $sponsor['logo'], true );
				}
			}
		}
		// Records the fixtures made on the way: missions, campaigns, and impact records of this run's organizations.
		foreach ( get_children(
			array(
				'post_parent' => $post_id,
				'post_type'   => 'attachment',
			)
		) as $attachment ) {
			wp_delete_attachment( $attachment->ID, true );
		}
		wp_delete_post( $post_id, true );
	}
	foreach ( $packout_pages as $page_id ) {
		wp_delete_post( (int) $page_id, true );
	}
	// The invitations this run sent, and what was recorded about them.
	$packout_like = '%' . $wpdb->esc_like( '-' . $packout_run . '@example.test' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table names are the plugin's own.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE invitation_id IN ( SELECT id FROM %i WHERE email LIKE %s )', Invitations::events_table(), Invitations::table(), $packout_like ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE email LIKE %s', Invitations::table(), $packout_like ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $packout_users as $user_id ) {
		wp_delete_user( (int) $user_id );
	}
	flush_rewrite_rules( false );
}

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( count( $packout_failures ) . ' checks failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
