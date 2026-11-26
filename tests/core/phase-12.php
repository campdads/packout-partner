<?php
/**
 * Phase 12 acceptance checks: invitation persistence, accept hooks, resend,
 * expired filter, and coordinator People listing helpers.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-12.php
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Accounts;
use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Invitations;
use PackOut\Core\App\People_Screen;
use PackOut\Core\App\Profile;
use PackOut\Core\App\Settings;
use PackOut\Core\App\Teams;

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
$packout_mail     = array();
$packout_pages    = array();
$packout_run      = strtolower( wp_generate_password( 6, false ) );
$packout_settings = array(
	'signup_page'    => (int) Settings::get( 'signup_page' ),
	'workspace_page' => (int) Settings::get( 'workspace_page' ),
);

$check = static function ( string $label, bool $ok, $detail = '' ) use ( &$packout_failures, &$packout_passed ): void {
	if ( $ok ) {
		++$packout_passed;
		WP_CLI::log( '  ok    ' . $label );
		return;
	}
	$packout_failures[] = $label;
	WP_CLI::log( '  FAIL  ' . $label . ( '' !== $detail ? ' — ' . ( is_scalar( $detail ) ? $detail : wp_json_encode( $detail ) ) : '' ) );
};

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

try {
	Invitations::install_tables();

	$signup = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Sign up ' . $packout_run,
			'post_content' => '<!-- wp:packout/signup /-->',
		),
		true
	);
	$work   = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Workspace ' . $packout_run,
			'post_content' => '<!-- wp:packout/workspace /-->',
		),
		true
	);
	if ( is_wp_error( $signup ) || is_wp_error( $work ) ) {
		WP_CLI::error( 'Could not create pages.' );
	}
	$packout_pages[] = (int) $signup;
	$packout_pages[] = (int) $work;
	Settings::save(
		array(
			'signup_page'    => (int) $signup,
			'workspace_page' => (int) $work,
		)
	);

	$coord  = $user( 'pos_coordinator', 'Coord Twelve' );
	$lead   = $user( 'pos_reporter', 'Lead Twelve' );
	$member = $user( 'pos_reporter', 'Member Twelve' );

	$org = Teams::propose(
		$lead,
		array(
			'name'        => 'Phase Twelve Org ' . $packout_run,
			'description' => 'Invitation storage checks.',
			'region'      => 'Test region',
		)
	);
	if ( is_wp_error( $org ) ) {
		WP_CLI::error( $org->get_error_message() );
	}
	$packout_posts[] = (int) $org;
	$approved        = Teams::approve( (int) $org, $coord );
	if ( is_wp_error( $approved ) ) {
		WP_CLI::error( $approved->get_error_message() );
	}

	WP_CLI::log( 'Invitation storage' );
	$email = 'pos-test-invitee-' . $packout_run . '@example.test';
	$unlimit( array( 'invite-team-' . $org, 'invite-email-' . $email ) );
	$check( 'lead invites and a row is stored', true === Teams::invite( (int) $org, $email, $lead ) );
	$listed = Invitations::list(
		array(
			'status' => 'pending',
			's'      => $email,
		),
		1,
		10
	);
	$check( 'pending list includes the invite', 1 === $listed['total'] && isset( $listed['rows'][0] ), $listed );
	$row = $listed['rows'][0] ?? array();
	$id  = (int) ( $row['id'] ?? 0 );
	$check( 'invite has sent event and fourteen-day expiry', $id > 0 && ! empty( $row['sent_at'] ) && ! empty( $row['expires_at'] ) );
	$events = Invitations::events( $id );
	$check( 'first event is sent', isset( $events[0] ) && Invitations::EVENT_SENT === $events[0]['event'] );
	$expires = strtotime( (string) $row['expires_at'] . ' UTC' );
	$check( 'expires about fourteen days out', $expires && abs( $expires - ( time() + Accounts::INVITE_TTL ) ) < 120, $row['expires_at'] ?? '' );

	$claims = Accounts::read( $token_in( $last_mail( $email ) ) );
	$check( 'token carries invitation id', $claims && (int) ( $claims['invite_id'] ?? 0 ) === $id, $claims );

	WP_CLI::log( 'Resend' );
	// The signed link includes the expiry second, so a resend in that same second is the same link.
	sleep( 1 );
	$unlimit( array( 'invite-team-' . $org, 'invite-email-' . $email ) );
	$check( 'same offer again is a resend', true === Teams::invite( (int) $org, $email, $lead ) );
	$row2    = Invitations::get( $id );
	$events2 = Invitations::events( $id );
	$check( 'same invitation id after resend', is_array( $row2 ) && Invitations::STATUS_PENDING === ( $row2['status'] ?? '' ) );
	$check( 'resent event recorded', Invitations::EVENT_RESENT === ( $events2[0]['event'] ?? '' ) );
	$check( 'token hash changed on resend', is_array( $row2 ) && (string) ( $row['token_hash'] ?? '' ) !== (string) ( $row2['token_hash'] ?? '' ) );

	$check( 'coordinator resend extends without rate limit', true === Invitations::resend( $id, $coord ) );
	$row3 = Invitations::get( $id );
	$check( 'coordinator resend updates invited_by', is_array( $row3 ) && (int) ( $row3['invited_by'] ?? 0 ) === $coord );

	WP_CLI::log( 'Accept' );
	$invite = $token_in( $last_mail( $email ) );
	$made   = Accounts::finish( $invite, wp_generate_password( 20 ), 'Invitee Twelve' );
	$new_id = is_array( $made ) ? (int) $made['user'] : 0;
	if ( $new_id ) {
		$packout_users[] = $new_id;
	}
	$accepted = Invitations::get( $id );
	$check( 'finish marks invitation accepted', $new_id > 0 && Teams::includes( (int) $org, $new_id ) && is_array( $accepted ) && Invitations::STATUS_ACCEPTED === ( $accepted['status'] ?? '' ), $accepted );
	$check( 'accepted_user_id set', is_array( $accepted ) && (int) ( $accepted['accepted_user_id'] ?? 0 ) === $new_id );
	$check( 'accepted event recorded', Invitations::EVENT_ACCEPTED === ( Invitations::events( $id )[0]['event'] ?? '' ) );
	$check( 'resend refused after accept', is_wp_error( Invitations::resend( $id, $coord ) ) );

	WP_CLI::log( 'Existing account accept' );
	$other = 'pos-test-other-' . $packout_run . '@example.test';
	$unlimit( array( 'invite-team-' . $org, 'invite-email-' . $other, 'invite-email-' . get_userdata( $member )->user_email ) );
	$check( 'invite existing member candidate', true === Teams::invite( (int) $org, get_userdata( $member )->user_email, $lead ) );
	$invite2    = $token_in( $last_mail( get_userdata( $member )->user_email ) );
	$claims2    = Accounts::read( $invite2 );
	$invite_id2 = (int) ( $claims2['invite_id'] ?? 0 );
	$check( 'accept as existing user', (int) Accounts::accept( $invite2, $member ) === (int) $org && Teams::includes( (int) $org, $member ) );
	$row_m = Invitations::get( $invite_id2 );
	$check( 'existing-user accept updates store', is_array( $row_m ) && Invitations::STATUS_ACCEPTED === ( $row_m['status'] ?? '' ) && (int) ( $row_m['accepted_user_id'] ?? 0 ) === $member );

	WP_CLI::log( 'Expired filter' );
	$stale = 'pos-test-stale-' . $packout_run . '@example.test';
	$unlimit( array( 'invite-team-' . $org, 'invite-email-' . $stale ) );
	$check( 'invite for expiry check', true === Teams::invite( (int) $org, $stale, $lead ) );
	$stale_list = Invitations::list(
		array(
			'status' => 'pending',
			's'      => $stale,
		),
		1,
		5
	);
	$stale_id   = (int) ( $stale_list['rows'][0]['id'] ?? 0 );
	global $wpdb;
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		Invitations::table(),
		array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
		array( 'id' => $stale_id ),
		array( '%s' ),
		array( '%d' )
	);
	$expired = Invitations::list(
		array(
			'status' => 'expired',
			's'      => $stale,
		),
		1,
		5
	);
	$check( 'expired filter finds overdue pending', $stale_id > 0 && 1 === $expired['total'], $expired );
	$stale_row = Invitations::get( $stale_id );
	$check( 'mark_expired wrote expired status', is_array( $stale_row ) && Invitations::STATUS_EXPIRED === ( $stale_row['status'] ?? '' ) );

	WP_CLI::log( 'Accept by offer key without i claim' );
	$legacy = 'pos-test-legacy-' . $packout_run . '@example.test';
	$unlimit( array( 'invite-team-' . $org, 'invite-email-' . $legacy ) );
	$check( 'invite for legacy accept', true === Teams::invite( (int) $org, $legacy, $lead ) );
	$legacy_list  = Invitations::list(
		array(
			'status' => 'pending',
			's'      => $legacy,
		),
		1,
		5
	);
	$legacy_id    = (int) ( $legacy_list['rows'][0]['id'] ?? 0 );
	$legacy_token = Accounts::invite_token( $legacy, (int) $org, array(), $lead, 0 );
	$legacy_user  = Accounts::finish( $legacy_token, wp_generate_password( 20 ), 'Legacy Twelve' );
	$legacy_uid   = is_array( $legacy_user ) ? (int) $legacy_user['user'] : 0;
	if ( $legacy_uid ) {
		$packout_users[] = $legacy_uid;
	}
	$legacy_row = Invitations::get( $legacy_id );
	$check( 'accept_by_claims updates row without i', $legacy_uid > 0 && is_array( $legacy_row ) && Invitations::STATUS_ACCEPTED === ( $legacy_row['status'] ?? '' ), $legacy_row );

	WP_CLI::log( 'Revoke' );
	$rev = 'pos-test-revoke-' . $packout_run . '@example.test';
	$unlimit( array( 'invite-team-' . $org, 'invite-email-' . $rev ) );
	$check( 'invite to revoke', true === Teams::invite( (int) $org, $rev, $lead ) );
	$rev_id = (int) ( Invitations::list(
		array(
			'status' => 'pending',
			's'      => $rev,
		),
		1,
		5
	)['rows'][0]['id'] ?? 0 );
	$check( 'coordinator revokes', true === Invitations::revoke( $rev_id, $coord ) );
	$check( 'revoked status', Invitations::STATUS_REVOKED === ( Invitations::get( $rev_id )['status'] ?? '' ) );

	WP_CLI::log( 'People screen helpers' );
	$check( 'people count includes reporters', People_Screen::count() >= 3 );
	$people = People_Screen::query( array( 's' => 'Lead Twelve' ), 1, 10 );
	$check( 'people search finds lead', $people['total'] >= 1 );
	$about = Profile::save_about(
		$lead,
		array(
			'name'  => 'Lead Twelve',
			'about' => 'Coordinator-edited about.',
		)
	);
	$check( 'profile about editable for another user', true === $about && 'Coordinator-edited about.' === Profile::get( $lead )['about'] );

} finally {
	Settings::save( $packout_settings );
	foreach ( $packout_posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	foreach ( $packout_pages as $page_id ) {
		wp_delete_post( $page_id, true );
	}
	foreach ( $packout_users as $user_id ) {
		if ( is_multisite() ) {
			wpmu_delete_user( $user_id );
		} else {
			wp_delete_user( $user_id );
		}
	}
	remove_filter( 'pre_wp_mail', $keep_mail, 99 );
}

WP_CLI::log( '' );
WP_CLI::log( $packout_passed . ' checks passed, ' . count( $packout_failures ) . ' failed.' );
if ( $packout_failures ) {
	WP_CLI::error( 'Phase 12 failed: ' . implode( '; ', $packout_failures ) );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
