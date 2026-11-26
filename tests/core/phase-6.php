<?php
/**
 * Phase 6 acceptance checks: cleaning a Spot from its page, photo locations,
 * organization addresses and logos, My account, and messages.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-6.php
 *
 * The script creates its own users, Spots, an organization, and a mission, keeps mail
 * in memory, and removes everything it created. It exits non-zero when a
 * check fails.
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Accounts;
use PackOut\Core\App\Claims;
use PackOut\Core\App\Export;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Messages;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Photos;
use PackOut\Core\App\Profile;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Settings;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Subteams;
use PackOut\Core\App\Teams;
use PackOut\Core\App\Workspace_Views;

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
$packout_buckets  = array();
$packout_run      = strtolower( wp_generate_password( 6, false ) );
$packout_address  = '203.0.113.' . wp_rand( 10, 250 );
$packout_server   = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Kept to restore it.

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

$call = static function ( string $method, string $route, array $params = array(), array $files = array() ) {
	$request = new WP_REST_Request( $method, '/packout/v1' . $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} elseif ( $files ) {
		$request->set_body_params( $params );
		$request->set_file_params( $files );
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

/**
 * A JPEG that says where it was taken: big-endian TIFF, one GPS directory.
 */
$gps_jpeg = static function ( float $lat, float $lng ): string {
	$image = imagecreatetruecolor( 64, 48 );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 60, 110, 70 ) );
	ob_start();
	imagejpeg( $image, null, 80 );
	$jpeg  = (string) ob_get_clean();
	$dms   = static function ( float $value ): string {
		$value = abs( $value );
		$d     = floor( $value );
		$m     = floor( ( $value - $d ) * 60 );
		$s     = ( $value - $d - $m / 60 ) * 3600;
		return pack( 'N6', $d, 1, $m, 1, (int) round( $s * 10000 ), 10000 );
	};
	$tiff  = 'MM' . pack( 'n', 42 ) . pack( 'N', 8 );
	$tiff .= pack( 'n', 1 ) . pack( 'nnNN', 0x8825, 4, 1, 26 ) . pack( 'N', 0 );
	$tiff .= pack( 'n', 4 );
	$tiff .= pack( 'nnN', 1, 2, 2 ) . ( $lat < 0 ? 'S' : 'N' ) . "\0\0\0";
	$tiff .= pack( 'nnNN', 2, 5, 3, 80 );
	$tiff .= pack( 'nnN', 3, 2, 2 ) . ( $lng < 0 ? 'W' : 'E' ) . "\0\0\0";
	$tiff .= pack( 'nnNN', 4, 5, 3, 104 );
	$tiff .= pack( 'N', 0 ) . $dms( $lat ) . $dms( $lng );
	$app1  = "Exif\0\0" . $tiff;
	return substr( $jpeg, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $app1 ) + 2 ) . $app1 . substr( $jpeg, 2 );
};

$file = static function ( string $name, string $kind, array $at = array() ) use ( &$packout_files, $gps_jpeg ): array {
	$path = wp_tempnam( $name );
	if ( 'png' === $kind ) {
		$image = imagecreatetruecolor( 120, 60 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 187, 94, 40 ) );
		imagepng( $image, $path );
	} elseif ( 'jpeg' === $kind ) {
		$image = imagecreatetruecolor( 64, 48 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 40, 94, 187 ) );
		imagejpeg( $image, $path, 80 );
	} elseif ( 'gps' === $kind ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test file.
		file_put_contents( $path, $gps_jpeg( $at[0], $at[1] ) );
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
		'headers' => is_array( $mail['headers'] ) ? implode( "\n", $mail['headers'] ) : (string) $mail['headers'],
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
$mail_to   = static fn( string $to ): array => array_values( array_filter( $packout_mail, static fn( $mail ) => strtolower( $mail['to'] ) === strtolower( $to ) ) );
$token_in  = static function ( ?array $mail, string $name ): string {
	return $mail && preg_match( '/' . $name . '=([A-Za-z0-9._%-]+)/', $mail['message'], $found ) ? rawurldecode( $found[1] ) : '';
};
$email_of  = static fn( int $id ): string => (string) get_userdata( $id )->user_email;
$bucket    = static function ( string $name ) use ( &$packout_buckets ): string {
	$packout_buckets[] = $name;
	return 'pos_limit_' . md5( $name );
};
$stage     = static fn( int $spot ): string => Spots::field( $spot, 'status', 'spotted' );

$on_claimed = static function ( $spot, $by, $photo ) use ( &$packout_fired ): void {
	$packout_fired[] = array( 'claimed', (int) $spot, (int) $by, (bool) $photo );
};
$on_message = static function ( $team, $id, $count ) use ( &$packout_fired ): void {
	$packout_fired[] = array( 'message', (int) $team, (int) $id, (int) $count );
};
add_action( 'packout_spot_claimed', $on_claimed, 10, 3 );
add_action( 'packout_message_sent', $on_message, 10, 3 );

$_SERVER['REMOTE_ADDR'] = $packout_address;
$bucket( 'claim-address-' . $packout_address );

try {
	$coordinator = $user( 'pos_coordinator', 'Coordinator' );
	$reporter    = $user( 'pos_reporter', 'Reporter' );
	$ana         = $user( 'pos_reporter', 'Ana' );
	$ben         = $user( 'pos_reporter', 'Ben' );
	$cal         = $user( 'pos_reporter', 'Cal' );
	$dee         = $user( 'pos_reporter', 'Dee' );
	$eli         = $user( 'pos_reporter', 'Eli' );
	$stranger    = $user( 'pos_reporter', 'Stranger' );

	$spot = static function ( string $what, ?float $lat = 36.31, bool $publish = true ) use ( &$packout_posts, $reporter ): int {
		$input = array(
			'category'    => 'cleanup-waste',
			'description' => $what,
		);
		if ( null !== $lat ) {
			$input['lat'] = $lat;
			$input['lng'] = -111.75;
		} else {
			// A report with no pin and no landmark needs a photo on its way.
			$input['photos_pending'] = 1;
		}
		$id              = Spots::create( $input, $reporter );
		$packout_posts[] = $id;
		if ( $publish ) {
			Spots::publish( $id );
		}
		return $id;
	};

	WP_CLI::log( 'A visitor reports a cleanup with no photo' );
	$s1      = $spot( 'Cans at the pullout.' );
	$visitor = 'pos-test-visitor-' . $packout_run . '@example.test';
	$bucket( 'claim-email-' . $visitor );
	$check( 'the new stage has a name', 'Cleanup reported' === ( Schema::statuses()['pending'] ?? '' ) );
	$check( 'a published Spot nobody has planned is open to anyone', true === Claims::open( Spots::get( $s1 ) ) );
	$check(
		'a visitor gives a name',
		'packout_account_name' === $code(
			Claims::submit(
				$s1,
				array(
					'name'  => 'V',
					'email' => $visitor,
				),
				null
			)
		)
	);
	$check(
		'and an email address',
		'packout_account_email' === $code(
			Claims::submit(
				$s1,
				array(
					'name'  => 'Vera Visitor',
					'email' => 'not an address',
				),
				null
			)
		)
	);
	$check( 'a refusal changes nothing', 'spotted' === $stage( $s1 ) && null === Claims::of( $s1 ) && ! email_exists( $visitor ) );

	$made = Claims::submit(
		$s1,
		array(
			'name'  => 'Vera Visitor',
			'email' => strtoupper( $visitor ),
			'note'  => 'Two bags of cans and a tire.',
		),
		null
	);
	$vera = is_array( $made ) ? (int) $made['by'] : 0;
	if ( $vera > 0 ) {
		$packout_users[] = $vera;
	}
	$check( 'with no photo the cleanup waits', is_array( $made ) && 'pending' === $made['state'] && 'pending' === $stage( $s1 ), $made );
	$check( 'the address gets an account at once, as a Reporter', $vera > 0 && (int) email_exists( $visitor ) === $vera && in_array( 'pos_reporter', (array) get_userdata( $vera )->roles, true ) && 'Vera Visitor' === get_userdata( $vera )->display_name );
	$check( 'the claim is kept with who, what, and where the Spot stood', Claims::of( $s1 )['by'] === $vera && Claims::of( $s1 )['email'] === $visitor && 'Two bags of cans and a tire.' === Claims::of( $s1 )['note'] && 'spotted' === Claims::of( $s1 )['from'] );
	$check( 'the Spot is not cleaned and has no record yet', '' === Spots::field( $s1, 'cleaned_at' ) && null === Impact::current( $s1 ) );
	$check( 'the public sees the new stage', 'pending' === Spots::payload( Spots::get( $s1 ), 'public' )['status'] );
	$thanks = $last_mail( $visitor );
	$check( 'they are thanked', null !== $thanks && false !== strpos( $thanks['subject'], 'Thank you for cleaning a Spot' ) && false !== strpos( $thanks['message'], 'Hello Vera Visitor' ) && false !== strpos( $thanks['message'], 'Thank you for cleaning' ) );
	$check( 'with a link for the photo and one for a password', '' !== $token_in( $thanks, 'pos_claim' ) && false !== strpos( $thanks['message'], 'view=reset' ) && false !== strpos( $thanks['message'], 'key=' ) );
	$told = $last_mail( (string) get_option( 'admin_email' ) );
	$check( 'coordinators are told it waits', null !== $told && false !== strpos( $told['subject'], 'A cleanup is waiting to be confirmed' ) && false !== strpos( $told['message'], 'Vera Visitor' ) && false !== strpos( $told['message'], 'Two bags of cans' ) );
	$check( 'the action fires with no photo', array( 'claimed', $s1, $vera, false ) === end( $packout_fired ) );
	$check(
		'a second person cannot report the same cleanup',
		'packout_claim_pending' === $code(
			Claims::submit(
				$s1,
				array(
					'name'  => 'Otto Other',
					'email' => 'pos-test-otto-' . $packout_run . '@example.test',
				),
				null
			)
		)
	);

	WP_CLI::log( 'Adding the photo' );
	$link = $token_in( $thanks, 'pos_claim' );
	$s2   = $spot( 'Another Spot.', 36.32 );
	$check( 'the link proves the claim, for this Spot only', null !== Claims::proven( $s1, $link ) && null === Claims::proven( $s2, $link ) && null === Claims::proven( $s1, $link . 'x' ) );
	$check( 'a visitor with no link cannot add the photo', 'packout_forbidden' === $code( Claims::add_photo( $s1, $file( 'after.jpg', 'jpeg' ), 0 ) ) );
	$check( 'nor can another signed-in person', 'packout_forbidden' === $code( Claims::add_photo( $s1, $file( 'after.jpg', 'jpeg' ), $stranger ) ) );
	$check( 'a script is not a photo', is_wp_error( Claims::add_photo( $s1, $file( 'after.jpg', 'script' ), 0, $link ) ) && 'pending' === $stage( $s1 ) );
	$check( 'the link holder adds the photo', true === Claims::add_photo( $s1, $file( 'after.jpg', 'jpeg' ), 0, $link ) );
	$record = Impact::current( $s1 );
	$check( 'the Spot is cleaned and credited to them', 'cleaned' === $stage( $s1 ) && (int) Spots::field( $s1, 'cleaned_by', '0' ) === $vera && 0 === (int) Spots::field( $s1, 'cleaned_mission', '0' ) );
	$check( 'an unverified cleanup record holds the note', $record instanceof WP_Post && ! Impact::verified( $record->ID ) && (int) Impact::field( $record->ID, 'user', '0' ) === $vera && false !== strpos( wp_json_encode( Impact::payload( $record ) ), 'Two bags of cans' ) );
	$photos = $record ? get_children(
		array(
			'post_parent' => $record->ID,
			'post_type'   => 'attachment',
			'fields'      => 'ids',
		)
	) : array();
	$check( 'and the photo', 1 === count( $photos ) && (int) reset( $photos ) === (int) Claims::of( $s1 )['photo'] );
	$check( 'a cleaned Spot cannot be claimed', 'packout_claim_cleaned' === $code( Claims::submit( $s1, array(), null, $stranger ) ) );
	$check( 'and takes no second photo', 'packout_claim_cleaned' === $code( Claims::add_photo( $s1, $file( 'again.jpg', 'jpeg' ), $vera ) ) );
	$done = Profile::contributions( $vera );
	$check( 'their account shows the cleanup', 1 === count( $done['cleanups'] ) && array() === $done['waiting'] );

	WP_CLI::log( 'A coordinator reopens it' );
	$check( 'the claim stands behind the stage', Claims::stands( $s1 ) );
	$check( 'not cleaned: the Spot is open again', true === Claims::dismiss( $s1 ) && 'spotted' === $stage( $s1 ) && '' === Spots::field( $s1, 'cleaned_at' ) );
	$check( 'the record is in the trash and the claim is gone', 'trash' === get_post_status( $record->ID ) && null === Claims::of( $s1 ) && null === Impact::current( $s1 ) );
	$check( 'the old link proves nothing', null === Claims::proven( $s1, $link ) );
	$check( 'the Spot can be claimed again', true === Claims::open( Spots::get( $s1 ) ) );
	$packout_posts[] = $record->ID;

	WP_CLI::log( 'A visitor with a photo, using the address of an account' );
	$s3    = $spot( 'Mattress in the wash.', 36.33 );
	$place = array( 36.3304, -111.7502 );
	$bucket( 'claim-email-' . $email_of( $ben ) );
	$sent_before = count( $packout_mail );
	$made        = Claims::submit(
		$s3,
		array(
			'name'  => 'Somebody',
			'email' => $email_of( $ben ),
			'note'  => 'Hauled it to the transfer station.',
		),
		$file( 'after.jpg', 'gps', $place )
	);
	$check( 'with a photo the Spot is cleaned at once', is_array( $made ) && 'cleaned' === $made['state'] && 'cleaned' === $stage( $s3 ), $made );
	$check( 'nobody is credited until the address is proven', 0 === $made['by'] && 0 === (int) Spots::field( $s3, 'cleaned_by', '0' ) && null === Impact::current( $s3 ) );
	$check( 'nothing is added to the account', array() === Profile::contributions( $ben )['cleanups'] );
	$known = $last_mail( $email_of( $ben ) );
	$check( 'the owner of the address is sent the link and no password link', '' !== $token_in( $known, 'pos_claim' ) && false === strpos( $known['message'], 'view=reset' ) && false !== strpos( $known['message'], 'already has a Pack-Out Spot account' ) );
	$check( 'two messages went out: the thanks and the notice', 2 === count( $packout_mail ) - $sent_before && false !== strpos( $last_mail( (string) get_option( 'admin_email' ) )['subject'], 'A Spot was cleaned from its page' ) );
	$check( 'the action fires with a photo and nobody credited', array( 'claimed', $s3, 0, true ) === end( $packout_fired ) );
	$shot = (int) Claims::of( $s3 )['photo'];
	$check( 'where the photo was taken is kept', Photos::location( $shot ) === $place, Photos::location( $shot ) );
	$check( 'and compared with the pin', false !== strpos( Photos::distance_line( $shot, $s3 ), 'm from the Spot' ), Photos::distance_line( $shot, $s3 ) );
	$check( 'a link for another claim credits nobody', 'packout_claim_link' === $code( Claims::credit( $s3, $link ) ) );
	$mine = $token_in( $known, 'pos_claim' );
	$check( 'the link puts the cleanup on the account', Claims::credit( $s3, $mine ) === $ben && (int) Spots::field( $s3, 'cleaned_by', '0' ) === $ben );
	$record3 = Impact::current( $s3 );
	$check( 'and writes the record, with the photo', $record3 instanceof WP_Post && (int) Impact::field( $record3->ID, 'user', '0' ) === $ben && (int) wp_get_post_parent_id( $shot ) === $record3->ID );
	$check( 'using the link twice changes nothing', Claims::credit( $s3, $mine ) === $ben && 1 === count( Profile::contributions( $ben )['cleanups'] ) );
	$as( $coordinator );
	$check( 'a coordinator verifies the record', ! is_wp_error( Impact::verify( $record3->ID, $coordinator ) ) && Impact::verified( $record3->ID ) );
	$check( 'a verified cleanup is not reopened from the claim', 'packout_impact_verified' === $code( Claims::dismiss( $s3 ) ) && 'cleaned' === $stage( $s3 ) );
	$as( 0 );

	WP_CLI::log( 'A signed-in person, and a Spot that came back' );
	$s4 = $spot( 'Fire ring full of glass.', 36.34 );
	Spots::mark_cleaned( $s4, $reporter, 0 );
	Spots::mark_returned( $s4 );
	Impact::flush();
	$check( 'a Spot that needs another look can be claimed', 'watch' === $stage( $s4 ) && true === Claims::open( Spots::get( $s4 ) ) );
	$before = count( $packout_mail );
	$made   = Claims::submit(
		$s4,
		array(
			'note'  => 'Swept it out.',
			'name'  => 'Ignored',
			'email' => 'ignored@example.test',
		),
		null,
		$dee
	);
	$check( 'a signed-in person is credited by their account, whatever the form says', is_array( $made ) && $dee === $made['by'] && $email_of( $dee ) === Claims::of( $s4 )['email'] && 'pending' === $stage( $s4 ) && ! email_exists( 'ignored@example.test' ) );
	$check( 'they are thanked without a password link', null !== $last_mail( $email_of( $dee ) ) && false === strpos( $last_mail( $email_of( $dee ) )['message'], 'view=reset' ) && count( $packout_mail ) - $before >= 2 );
	$check( 'their account shows it waiting', array( $s4 ) === wp_list_pluck( Profile::contributions( $dee )['waiting'], 'ID' ) );
	$check( 'only a pending cleanup is confirmed', 'packout_not_found' === $code( Claims::confirm( $s2 ) ) );
	$check( 'a coordinator confirms it without a photo', true === Claims::confirm( $s4 ) && 'cleaned' === $stage( $s4 ) && (int) Spots::field( $s4, 'cleaned_by', '0' ) === $dee && Impact::current( $s4 ) instanceof WP_Post );
	$record4 = Impact::current( $s4 );
	$check( 'reopened, it needs another look again', true === Claims::dismiss( $s4 ) && 'watch' === $stage( $s4 ) && 'trash' === get_post_status( $record4->ID ) );
	$packout_posts[] = $record4->ID;

	$made = Claims::submit( $s2, array(), null, $eli );
	$check( 'the person who reported it adds the photo while signed in', is_array( $made ) && true === Claims::add_photo( $s2, $file( 'after.png', 'png' ), $eli ) && 'cleaned' === $stage( $s2 ) && (int) Spots::field( $s2, 'cleaned_by', '0' ) === $eli );

	WP_CLI::log( 'What cannot be claimed' );
	$s5 = $spot( 'Barrels, leaking.', 36.35 );
	Spots::set_hazard( $s5, 'do_not_touch' );
	$check( 'a Spot that must be left alone', 'packout_hazard' === $code( Claims::submit( $s5, array(), $file( 'after.jpg', 'jpeg' ), $dee ) ) && 'spotted' === $stage( $s5 ) && null === Claims::of( $s5 ) );
	$s6 = $spot( 'Waiting for review.', 36.36, false );
	$check( 'a Spot that is not on the map', 'packout_not_found' === $code( Claims::submit( $s6, array(), null, $dee ) ) );
	$s7 = $spot( 'Claim with a bad file.', 36.37 );
	$check( 'a file that is not a photo is refused and nothing changes', is_wp_error( Claims::submit( $s7, array(), $file( 'after.jpg', 'script' ), $dee ) ) && 'spotted' === $stage( $s7 ) && null === Claims::of( $s7 ) );
	set_transient( $bucket( 'claim-address-' . $packout_address ), 5, HOUR_IN_SECONDS );
	$late = 'pos-test-late-' . $packout_run . '@example.test';
	$check(
		'a connection that has sent its share is refused',
		'packout_claim_limit' === $code(
			Claims::submit(
				$s7,
				array(
					'name'  => 'Late Larry',
					'email' => $late,
				),
				null
			)
		) && ! email_exists( $late )
	);
	$check( 'a signed-in person is not', is_array( Claims::submit( $s7, array(), null, $dee ) ) );
	delete_transient( $bucket( 'claim-address-' . $packout_address ) );
	set_transient( $bucket( 'claim-email-' . $late ), 10, DAY_IN_SECONDS );
	$s8 = $spot( 'One more.', 36.38 );
	$check(
		'nor is an address written to more than its share in a day',
		'packout_claim_limit' === $code(
			Claims::submit(
				$s8,
				array(
					'name'  => 'Late Larry',
					'email' => $late,
				),
				null
			)
		) && 'spotted' === $stage( $s8 )
	);

	WP_CLI::log( 'The Spot page' );
	$render = static function ( int $id ): string {
		global $wp_query, $wp_the_query, $post;
		$keep = array( $wp_query, $wp_the_query, $post );
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Renders one page for a check, then restores the globals.
		$wp_query     = new WP_Query(
			array(
				'post_type' => get_post_type( $id ),
				'p'         => $id,
			)
		);
		$wp_the_query = $wp_query;
		$html         = 'none';
		while ( $wp_query->have_posts() ) {
			$wp_query->the_post();
			$html = (string) apply_filters( 'the_content', get_the_content() );
		}
		list( $wp_query, $wp_the_query, $post ) = $keep;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		return $html;
	};
	$html   = $render( $s8 );
	$check( 'a visitor is offered the form, with a name and an address to give', false !== strpos( $html, 'id="pos-claim"' ) && false !== strpos( $html, 'I cleaned it' ) && false !== strpos( $html, 'name="email"' ) && false !== strpos( $html, 'name="photo"' ) && false !== strpos( $html, 'multipart/form-data' ) );
	$as( $dee );
	$html = $render( $s8 );
	$check( 'a signed-in person is not asked who they are', false !== strpos( $html, 'I cleaned it' ) && false === strpos( $html, 'name="email"' ) && false !== strpos( $html, '_posnonce' ) );
	$html = $render( $s7 );
	$check( 'the person who reported a cleanup is asked for the photo', false !== strpos( $html, 'Add the photo' ) && false !== strpos( $html, 'value="photo"' ) );
	$as( 0 );
	$html = $render( $s7 );
	$check( 'everyone else is told it was reported', false === strpos( $html, 'Add the photo' ) && false === strpos( $html, 'I cleaned it' ) && false !== strpos( $html, 'Cleanup reported' ) );
	$html = $render( $s5 );
	$check( 'a Spot that must be left alone offers no form', false === strpos( $html, 'I cleaned it' ) );
	$check( 'no page names the person or shows their address', false === strpos( $render( $s7 ) . $render( $s3 ), 'Dee Testperson' ) && false === strpos( $render( $s3 ), $email_of( $ben ) ) );

	WP_CLI::log( 'Where a photo was taken' );
	$where = array( 39.123456, -105.654321 );
	$taken = $file( 'taken.jpg', 'gps', $where );
	$check( 'a JPEG that says where it was taken is read', Photos::gps( $taken['tmp_name'] ) === $where, Photos::gps( $taken['tmp_name'] ) );
	$south = $file( 'south.jpg', 'gps', array( -33.865143, 151.2099 ) );
	$check( 'south and east too', array( -33.865143, 151.2099 ) === Photos::gps( $south['tmp_name'] ), Photos::gps( $south['tmp_name'] ) );
	$check( 'a photo that says nothing gives nothing', null === Photos::gps( $file( 'plain.jpg', 'jpeg' )['tmp_name'] ) && null === Photos::gps( $file( 'plain.png', 'png' )['tmp_name'] ) && null === Photos::gps( $file( 'script.jpg', 'script' )['tmp_name'] ) );
	$zero = $file( 'zero.jpg', 'gps', array( 0.0, 0.0 ) );
	$check( 'a camera with no fix writes zeros, which are not a place', null === Photos::gps( $zero['tmp_name'] ) );
	$cut = $file( 'cut.jpg', 'script' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A test file.
	file_put_contents( $cut['tmp_name'], substr( (string) file_get_contents( $taken['tmp_name'] ), 0, 60 ) );
	$check( 'a damaged file gives nothing and raises nothing', null === Photos::gps( $cut['tmp_name'] ) );

	$pinless = $spot( 'No pin given.', null, false );
	$check( 'a report can arrive with no pin', null === Spots::pin( $pinless ) );
	$first = Photos::attach_upload( $pinless, $taken );
	$check( 'its photo gives it one', is_int( $first ) && Spots::pin( $pinless ) === $where && 'photo' === Spots::field( $pinless, 'pin_source' ), Spots::pin( $pinless ) );
	$check( 'and remembers the place itself', Photos::location( $first ) === $where );
	$second = Photos::attach_upload( $pinless, $south );
	$check( 'a later photo does not move the pin', is_int( $second ) && Spots::pin( $pinless ) === $where && array( -33.865143, 151.2099 ) === Photos::location( $second ) );
	$check( 'a photo taken far away says so', false !== strpos( Photos::distance_line( $second, $pinless ), 'km from the Spot' ), Photos::distance_line( $second, $pinless ) );
	Spots::update(
		$pinless,
		array(
			'lat' => 39.2,
			'lng' => -105.7,
		)
	);
	$check( 'a pin a person sets is no longer the photo\'s', '' === Spots::field( $pinless, 'pin_source' ) && array( 39.2, -105.7 ) === Spots::pin( $pinless ) );
	$pinned = $spot( 'Pin given.', 36.39, false );
	Photos::attach_upload( $pinned, $file( 'taken-2.jpg', 'gps', $where ) );
	$check( 'a pin the reporter gave is kept', array( 36.39, -111.75 ) === Spots::pin( $pinned ) && '' === Spots::field( $pinned, 'pin_source' ) );

	$as( $reporter );
	$bare          = $spot( 'From the field app.', null, false );
	list( $s, $d ) = $call(
		'POST',
		'/spots/' . $bare . '/photos',
		array(
			'photo_lat' => '36.4012345',
			'photo_lng' => '-111.8054321',
		),
		array( 'file' => $file( 'shrunk.jpg', 'jpeg' ) )
	);
	$sent          = is_array( $d ) ? (int) ( $d['photo'] ?? ( $d['id'] ?? 0 ) ) : 0;
	$sent          = $sent > 0 ? $sent : (int) current(
		get_children(
			array(
				'post_parent' => $bare,
				'post_type'   => 'attachment',
				'fields'      => 'ids',
			)
		)
	);
	$check( 'the field app sends the place it read before shrinking the photo', in_array( $s, array( 200, 201 ), true ) && null !== Photos::location( $sent ) && abs( Photos::location( $sent )[0] - 36.401235 ) < 0.00001, array( $s, Photos::location( $sent ) ) );
	$check( 'and a Spot with no pin takes it', null !== Spots::pin( $bare ) && abs( Spots::pin( $bare )[1] + 111.805432 ) < 0.00001 && 'photo' === Spots::field( $bare, 'pin_source' ) );
	list( $s, $d ) = $call(
		'POST',
		'/spots/' . $bare . '/photos',
		array(
			'photo_lat' => '10',
			'photo_lng' => '10',
		),
		array( 'file' => $file( 'has-its-own.jpg', 'gps', $where ) )
	);
	$kept          = array_map(
		array( Photos::class, 'location' ),
		get_children(
			array(
				'post_parent' => $bare,
				'post_type'   => 'attachment',
				'fields'      => 'ids',
			)
		)
	);
	$check( 'what the file itself says wins over what was sent beside it', in_array( $where, $kept, true ) && ! in_array( array( 10.0, 10.0 ), $kept, true ), $kept );
	list( $s, $d ) = $call(
		'POST',
		'/spots/' . $bare . '/photos',
		array(
			'photo_lat' => '91',
			'photo_lng' => 'here',
		),
		array( 'file' => $file( 'nonsense.jpg', 'jpeg' ) )
	);
	$check(
		'a place that is not on the earth is ignored and the photo kept',
		in_array( $s, array( 200, 201 ), true ) && 3 === count(
			get_children(
				array(
					'post_parent' => $bare,
					'post_type'   => 'attachment',
					'fields'      => 'ids',
				)
			)
		)
	);
	$as( 0 );

	WP_CLI::log( 'An organization\'s address' );
	$name            = 'Wash Road Crew ' . $packout_run;
	$team            = Teams::propose(
		$ana,
		array(
			'name'        => $name,
			'description' => 'We clean the wash road.',
		)
	);
	$packout_posts[] = $team;
	Teams::approve( $team, $coordinator );
	foreach ( array( $ben, $cal, $dee, $eli ) as $person ) {
		Teams::admit( $team, $person );
	}
	$other           = Teams::propose( $stranger, array( 'name' => 'Rival Crew ' . $packout_run ) );
	$packout_posts[] = $other;
	Teams::approve( $other, $coordinator );
	$check( 'the lead lists the organization', is_int( Teams::update_profile( $team, array( 'listed' => '1' ), $ana ) ) && 'publish' === get_post_status( $team ) );
	$old   = Teams::address( $team );
	$fresh = 'wash-road-' . $packout_run;
	$check( 'an organization has an address from its name', '' !== $old && false !== strpos( (string) get_permalink( $team ), '/' . $old . '/' ) );
	$check( 'an address is 3 to 60 letters, digits, and hyphens', '' !== Teams::address_problem( $team, 'ab' ) && '' !== Teams::address_problem( $team, 'has_underscore' ) && '' !== Teams::address_problem( $team, '-leading' ) && '' !== Teams::address_problem( $team, 'trailing-' ) && '' !== Teams::address_problem( $team, str_repeat( 'a', 61 ) ) && '' !== Teams::address_problem( $team, 'Has Space' ) );
	$check( 'what is typed is lower-cased before it is judged', Teams::clean_address( '  ' . strtoupper( $fresh ) . ' ' ) === $fresh && '' === Teams::address_problem( $team, $fresh ) );
	$check( 'words the site uses are reserved', '' !== Teams::address_problem( $team, 'teams' ) && '' !== Teams::address_problem( $team, 'wp-admin' ) && '' !== Teams::address_problem( $team, 'feed' ) );
	$check( 'another organization\'s address is taken, and its own is not a problem', '' !== Teams::address_problem( $team, Teams::address( $other ) ) && '' === Teams::address_problem( $team, $old ) );
	$check( 'the page it would have ends with it', substr( Teams::address_url( $team, $fresh ), -strlen( $fresh ) - 2 ) === '/' . $fresh . '/' && 0 === strpos( Teams::address_url( $team, $fresh ), home_url( '/' ) ) );
	$check( 'a member cannot change it, nor can another organization\'s lead', 'packout_forbidden' === $code( Teams::set_address( $team, $fresh, $ben ) ) && 'packout_forbidden' === $code( Teams::set_address( $team, $fresh, $stranger ) ) && Teams::address( $team ) === $old );
	$check( 'a taken or malformed address is refused', 'packout_team_address' === $code( Teams::set_address( $team, Teams::address( $other ), $ana ) ) && 'packout_team_address' === $code( Teams::set_address( $team, 'no', $ana ) ) && Teams::address( $team ) === $old );
	$check( 'the lead changes it', true === Teams::set_address( $team, strtoupper( $fresh ), $ana ) && Teams::address( $team ) === $fresh && false !== strpos( (string) get_permalink( $team ), '/' . $fresh . '/' ) );
	$check( 'saving the same address again changes nothing', false === Teams::set_address( $team, $fresh, $ana ) );
	$check( 'the old address is remembered, so it forwards', in_array( $old, get_post_meta( $team, '_wp_old_slug', false ), true ) );
	$check( 'and no other organization can take it', '' !== Teams::address_problem( $other, $old ) && 'packout_team_address' === $code( Teams::set_address( $other, $old, $stranger ) ) );
	$kept_other = Teams::address( $other );
	$check( 'an organization that is not listed keeps its old address too', 'private' === get_post_status( $other ) && true === Teams::set_address( $other, 'rival-' . $packout_run . '-moved', $stranger ) && in_array( $kept_other, get_post_meta( $other, '_wp_old_slug', false ), true ) && '' !== Teams::address_problem( $team, $kept_other ) );
	$check( 'going back to an address stops it being an old one', true === Teams::set_address( $other, $kept_other, $stranger ) && ! in_array( $kept_other, get_post_meta( $other, '_wp_old_slug', false ), true ) && in_array( 'rival-' . $packout_run . '-moved', get_post_meta( $other, '_wp_old_slug', false ), true ) );
	$check( 'the organization can go back to it', '' === Teams::address_problem( $team, $old ) );
	$check( 'a coordinator can change it too', true === Teams::set_address( $team, $fresh . '-2', $coordinator ) && true === Teams::set_address( $team, $fresh, $coordinator ) );

	list( $s, $d ) = $call( 'GET', '/teams/' . $team . '/address', array( 'address' => $fresh . '-new' ) );
	$check( 'a visitor cannot ask what is free', in_array( $s, array( 401, 403 ), true ), $s );
	$as( $ben );
	list( $s, $d ) = $call( 'GET', '/teams/' . $team . '/address', array( 'address' => $fresh . '-new' ) );
	$check( 'nor can a member', 403 === $s, $s );
	$as( $ana );
	list( $s, $d ) = $call( 'GET', '/teams/' . $team . '/address', array( 'address' => strtoupper( $fresh ) . '-NEW' ) );
	$check( 'the lead is told an address is available, and what the page would be', 200 === $s && true === $d['available'] && $fresh . '-new' === $d['address'] && Teams::address_url( $team, $fresh . '-new' ) === $d['url'] && false !== strpos( $d['message'], 'Available' ), $d );
	list( $s, $d ) = $call( 'GET', '/teams/' . $team . '/address', array( 'address' => Teams::address( $other ) ) );
	$check( 'or that it is taken', 200 === $s && false === $d['available'] && '' === $d['url'] && false !== strpos( $d['message'], 'Another organization' ), $d );
	list( $s, $d ) = $call( 'GET', '/teams/' . $team . '/address', array( 'address' => $fresh ) );
	$check( 'or that it is the one the organization has', 200 === $s && true === $d['available'] && false !== strpos( $d['message'], 'now' ), $d );
	list( $s, $d ) = $call( 'GET', '/teams/' . $other . '/address', array( 'address' => 'anything-else' ) );
	$check( 'a lead cannot ask about another organization', 403 === $s, $s );
	$as( $coordinator );
	list( $s, $d ) = $call( 'GET', '/teams/' . $other . '/address', array( 'address' => 'x' ) );
	$check( 'a coordinator can ask about any organization, and is told what is wrong', 200 === $s && false === $d['available'] && false !== strpos( $d['message'], '3 to 60' ), $d );
	$as( 0 );

	WP_CLI::log( 'Logos' );
	$check( 'a member cannot upload the organization\'s logo', 'packout_forbidden' === $code( Teams::set_logo( $team, $file( 'logo.png', 'png' ), $ben ) ) && 0 === Teams::logo( $team ) );
	$check( 'a script is not a logo', is_wp_error( Teams::set_logo( $team, $file( 'logo.png', 'script' ), $ana ) ) && 0 === Teams::logo( $team ) );
	$logo = Teams::set_logo( $team, $file( 'logo.png', 'png' ), $ana );
	$check( 'the lead uploads it', is_int( $logo ) && Teams::logo( $team ) === $logo && (int) wp_get_post_parent_id( $logo ) === $team );
	$check( 'it is on the organization\'s page and in the list of organizations', false !== strpos( $render( $team ), 'pos-place__logo' ) && '' !== ( array_values( array_filter( Teams::listed(), static fn( $row ) => $row['id'] === $team ) )[0]['logo'] ?? '' ) );
	$check( 'the image names the organization', false !== strpos( Teams::logo_html( $team ), esc_attr( $name ) ) );
	$newer = Teams::set_logo( $team, $file( 'logo-2.png', 'png' ), $coordinator );
	$check( 'a new logo replaces the old one, which is deleted', is_int( $newer ) && $newer !== $logo && Teams::logo( $team ) === $newer && null === get_post( $logo ) );
	$pending         = Teams::propose( $eli, array( 'name' => 'Not Yet Crew ' . $packout_run ) );
	$packout_posts[] = $pending;
	$check( 'an organization that is not approved has no logo', 'packout_team_pending' === $code( Teams::set_logo( $pending, $file( 'logo.png', 'png' ), $eli ) ) );

	$north = Subteams::save(
		$team,
		0,
		array(
			'name' => 'North Wash',
			'lead' => $ben,
		),
		$ana
	);
	$south = Subteams::save(
		$team,
		0,
		array(
			'name' => 'South Wash',
			'lead' => $cal,
		),
		$ana
	);
	Subteams::set_member( $team, $north, $dee, true, $ana );
	$check( 'a team starts with no logo', 0 === Subteams::logo( $team, $north ) && 0 === Subteams::get( $team, $north )['logo'] );
	$check( 'a member cannot give it one, nor can another team\'s lead', 'packout_forbidden' === $code( Subteams::set_logo( $team, $north, $file( 'north.png', 'png' ), $dee ) ) && 'packout_forbidden' === $code( Subteams::set_logo( $team, $north, $file( 'north.png', 'png' ), $cal ) ) );
	$sublogo = Subteams::set_logo( $team, $north, $file( 'north.png', 'png' ), $ben );
	$check( 'its lead can', is_int( $sublogo ) && $sublogo > 0 && Subteams::logo( $team, $north ) === $sublogo );
	$check( 'mail to the team carries its logo, and other mail the organization\'s', Messages::logo( $team, 'sub-' . $north ) === $sublogo && Messages::logo( $team, 'sub-' . $south ) === $newer && Messages::logo( $team, 'team' ) === $newer );
	$check( 'removing it deletes the file', 0 === Subteams::set_logo( $team, $north, null, $ana ) && 0 === Subteams::logo( $team, $north ) && null === get_post( $sublogo ) );
	$sublogo = Subteams::set_logo( $team, $north, $file( 'north-2.png', 'png' ), $ana );

	WP_CLI::log( 'My account: about, contact details, and what is shared' );
	$check( 'a profile starts empty, with news off', '' === Profile::get( $dee )['about'] && false === Profile::get( $dee )['news'] && false === Profile::get( $dee )['swag'] && array() === Profile::get( $dee )['quiet'] );
	$check( 'a person is on the organizations they are on', array( $team ) === array_keys( Profile::teams( $dee ) ) && Profile::teams( $dee )[ $team ] === $name );
	$check(
		'a name of one letter is refused',
		'packout_account_name' === $code(
			Profile::save_about(
				$dee,
				array(
					'name'  => 'D',
					'about' => '',
				)
			)
		)
	);
	$check(
		'and more than 600 characters about oneself',
		'packout_profile_about' === $code(
			Profile::save_about(
				$dee,
				array(
					'name'  => 'Dee Okafor',
					'about' => str_repeat( 'a', 601 ),
				)
			)
		)
	);
	$check(
		'the name and the lines are saved',
		true === Profile::save_about(
			$dee,
			array(
				'name'  => 'Dee Okafor',
				'about' => "I drive a pickup.\n<script>alert(1)</script>Weekends.",
			)
		) && 'Dee Okafor' === get_userdata( $dee )->display_name && false === strpos(
			Profile::get( $dee )['about'],
			'<script'
		) && false !== strpos(
			Profile::get( $dee )['about'],
			'I drive a pickup.'
		)
	);
	$check( 'a phone number needs seven digits', 'packout_profile_phone' === $code( Profile::save_contact( $dee, array( 'phone' => '555-12' ) ) ) && 'packout_profile_phone' === $code( Profile::save_contact( $dee, array( 'phone' => 'call me maybe' ) ) ) );
	$contact = array(
		'phone'         => '+1 (928) 555-0142',
		'address'       => array(
			'line1'   => '12 Juniper Rd',
			'line2'   => '',
			'city'    => 'Flagstaff',
			'region'  => 'AZ',
			'postal'  => '86001',
			'country' => 'USA',
		),
		'share_phone'   => array( $team, $other ),
		'share_address' => array(),
		'swag'          => '1',
	);
	$check( 'contact details are saved', true === Profile::save_contact( $dee, $contact ) && '+1 (928) 555-0142' === Profile::get( $dee )['phone'] && 'Flagstaff' === Profile::get( $dee )['address']['city'] && true === Profile::get( $dee )['swag'] );
	$check( 'sharing is with organizations the person is on, and no others', array( $team ) === Profile::get( $dee )['share_phone'] && array() === Profile::get( $dee )['share_address'] );
	$shared = Profile::shared_with( $dee, $team );
	$check( 'the organization\'s leads get the phone number and the lines, not the address', '+1 (928) 555-0142' === $shared['phone'] && array() === $shared['address'] && false !== strpos( $shared['about'], 'pickup' ) );
	$check(
		'another organization gets nothing',
		array(
			'about'   => '',
			'phone'   => '',
			'address' => array(),
		) === Profile::shared_with( $dee, $other )
	);
	$as( $ana );
	$html = Workspace_Views::team( Teams::get( $team ), 'members', '', $ana );
	$check( 'the lead sees the shared phone number on the Members tab', false !== strpos( $html, '+1 (928) 555-0142' ) && false === strpos( $html, '12 Juniper Rd' ) );
	$as( $ben );
	$html = Workspace_Views::team( Teams::get( $team ), 'members', '', $ben );
	$check( 'a member does not', false === strpos( $html, '+1 (928) 555-0142' ) );
	$as( 0 );
	$contact['share_address'] = array( $team );
	Profile::save_contact( $dee, $contact );
	$check( 'the address is shared when the person says so', array( '12 Juniper Rd', 'Flagstaff, AZ 86001', 'USA' ) === Profile::shared_with( $dee, $team )['address'], Profile::shared_with( $dee, $team )['address'] );
	$check(
		'nothing is shared that is not there',
		true === Profile::save_contact(
			$cal,
			array(
				'share_phone'   => array( $team ),
				'share_address' => array( $team ),
				'swag'          => '1',
			)
		) && array() === Profile::get( $cal )['share_phone'] && array() === Profile::get( $cal )['share_address'] && false === Profile::get( $cal )['swag']
	);
	$check( 'leaving the organization ends the sharing', Teams::remove_member( $team, $dee ) && array() === Profile::get( $dee )['share_phone'] && array() === Profile::get( $dee )['share_address'] && '' === Profile::shared_with( $dee, $team )['phone'] );
	Teams::admit( $team, $dee );
	Subteams::set_member( $team, $north, $dee, true, $ana );
	$check( 'and coming back does not start it again', '' === Profile::shared_with( $dee, $team )['phone'] && '+1 (928) 555-0142' === Profile::get( $dee )['phone'] );

	WP_CLI::log( 'My account: what a person has done' );
	Spots::publish( $pinless );
	$done = Profile::contributions( $reporter );
	$check( 'the Spots a person reported are theirs', count( $done['spots'] ) >= 10 && in_array( $s1, wp_list_pluck( $done['spots'], 'ID' ), true ) );
	$check( 'a person who has done nothing has an empty list', array() === Profile::contributions( $stranger )['spots'] && array() === Profile::contributions( $stranger )['cleanups'] && array() === Profile::contributions( $stranger )['missions'] );
	$check( 'a cleanup credited from a claim is on the account that proved it', array( $record3->ID ) === wp_list_pluck( Profile::contributions( $ben )['cleanups'], 'ID' ) );

	WP_CLI::log( 'My account: email choices and the mailing file' );
	$check( 'an organization\'s messages are on until a person turns them off', Profile::wants_team_mail( $dee, $team ) );
	$check(
		'they turn them off, and ask for news',
		true === Profile::save_mail(
			$dee,
			array(
				'teams' => array(),
				'news'  => '1',
			)
		) && ! Profile::wants_team_mail(
			$dee,
			$team
		) && true === Profile::get( $dee )['news']
	);
	$check(
		'and on again',
		true === Profile::save_mail(
			$dee,
			array(
				'teams' => array( $team ),
				'news'  => '1',
			)
		) && Profile::wants_team_mail(
			$dee,
			$team
		)
	);
	Profile::save_mail(
		$cal,
		array(
			'teams' => array( $team ),
			'news'  => '1',
		)
	);
	$rows = Profile::mailing_rows();
	$by   = array();
	foreach ( array_slice( $rows, 1 ) as $row ) {
		$by[ $row[1] ] = $row;
	}
	$check( 'the file has a header', array( 'name', 'email', 'news', 'may_send_things' ) === array_slice( $rows[0], 0, 4 ) );
	$check( 'a person who let things be sent is in it with their address', isset( $by[ $email_of( $dee ) ] ) && 'yes' === $by[ $email_of( $dee ) ][3] && '12 Juniper Rd' === $by[ $email_of( $dee ) ][4] && false !== strpos( $by[ $email_of( $dee ) ][11], $name ) );
	$check( 'a person who asked only for news is in it without one', isset( $by[ $email_of( $cal ) ] ) && 'yes' === $by[ $email_of( $cal ) ][2] && 'no' === $by[ $email_of( $cal ) ][3] && '' === $by[ $email_of( $cal ) ][4] && '' === $by[ $email_of( $cal ) ][10] );
	$check( 'a person who asked for neither is not in it', ! isset( $by[ $email_of( $ben ) ] ) && ! isset( $by[ $email_of( $ana ) ] ) );
	$check( 'people who export get the file', isset( Export::kinds()['people'] ) );
	$people = Export::file( 'people', array(), $coordinator );
	$check( 'as a CSV', ! is_wp_error( $people ) && '.csv' === substr( $people['name'], -4 ) && false !== strpos( $people['body'], $email_of( $dee ) ) && false !== strpos( $people['body'], '12 Juniper Rd' ), is_wp_error( $people ) ? $people->get_error_message() : '' );
	$check( 'and nobody else does', is_wp_error( Export::file( 'people', array(), $ana ) ) );
	$check( 'the Spot and impact files still name nobody', false === strpos( Export::file( 'spots_internal', array(), $coordinator )['body'] . Export::file( 'impact', array(), $coordinator )['body'], $email_of( $dee ) ) );

	WP_CLI::log( 'My account: changing the sign-in email' );
	$secret = wp_generate_password( 24 );
	wp_set_password( $secret, $dee );
	$bucket( 'email-change-' . $dee );
	$new = 'pos-test-dee-new-' . $packout_run . '@example.test';
	$check( 'the new address must be an address, and a different one', 'packout_account_email' === $code( Profile::request_email( $dee, 'nope', $secret ) ) && 'packout_profile_same' === $code( Profile::request_email( $dee, strtoupper( $email_of( $dee ) ), $secret ) ) );
	$check( 'the current password is asked for', 'packout_profile_password' === $code( Profile::request_email( $dee, $new, 'not the password' ) ) && null === $last_mail( $new ) );
	delete_transient( $bucket( 'email-change-' . $dee ) );
	$old_email = $email_of( $dee );
	$check( 'asking sends a link to the new address, and changes nothing', true === Profile::request_email( $dee, $new, $secret ) && '' !== $token_in( $last_mail( $new ), 'pos_email' ) && $old_email === $email_of( $dee ) );
	$change = $token_in( $last_mail( $new ), 'pos_email' );
	$check( 'the link is for the person who asked', Profile::read_email( $change, $dee ) === $new && '' === Profile::read_email( $change, $ben ) && 'packout_account_link' === $code( Profile::confirm_email( $change, $ben ) ) );
	$before = count( $packout_mail );
	$check( 'an address another account uses gets the same answer and no message', true === Profile::request_email( $dee, $email_of( $ben ), $secret ) && count( $packout_mail ) === $before );
	$check( 'confirming changes the address', true === Profile::confirm_email( $change, $dee ) && $new === $email_of( $dee ) );
	$check( 'the old address is told', null !== $last_mail( $old_email ) && false !== strpos( $last_mail( $old_email )['subject'], 'Your sign-in email was changed' ) && false !== strpos( $last_mail( $old_email )['message'], $new ) );
	$check( 'the link works once', 'packout_account_link' === $code( Profile::confirm_email( $change, $dee ) ) );
	$check( 'the fourth request in a day is refused', true === Profile::request_email( $dee, 'pos-test-dee-3-' . $packout_run . '@example.test', $secret ) && 'packout_account_limit' === $code( Profile::request_email( $dee, 'pos-test-dee-4-' . $packout_run . '@example.test', $secret ) ) );

	WP_CLI::log( 'Messages: who can write to whom' );
	$m_spot          = $spot( 'For the mission.', 36.41 );
	$mission         = Missions::save(
		0,
		array(
			'title'    => 'North wash sweep',
			'team'     => $team,
			'subteams' => array( $north ),
			'spots'    => array( $m_spot ),
		),
		$ana
	);
	$packout_posts[] = $mission;
	$open            = Missions::save(
		0,
		array(
			'title' => 'Whole organization day',
			'team'  => $team,
			'spots' => array( $spot( 'For the whole organization.', 36.42 ) ),
		),
		$ana
	);
	$packout_posts[] = $open;
	$check( 'a Spot on a mission is recorded by the organization, not from its page', is_int( $mission ) && 'packout_claim_mission' === $code( Claims::open( Spots::get( $m_spot ) ) ), $stage( $m_spot ) );

	$sorted = static function ( array $ids ): array {
		sort( $ids );
		return array_values( $ids );
	};
	$check( 'the organization is everyone on it', $sorted( array( $ana, $ben, $cal, $dee, $eli ) ) === $sorted( Messages::recipients( $team, 'team' ) ) );
	$check( 'a team is its lead and its members', $sorted( array( $ben, $dee ) ) === $sorted( Messages::recipients( $team, 'sub-' . $north ) ) && array( $cal ) === Messages::recipients( $team, 'sub-' . $south ) );
	$check( 'a mission given to a team is that team and the organization\'s leads', $sorted( array( $ana, $ben, $dee ) ) === $sorted( Messages::recipients( $team, 'mission-' . $mission ) ) );
	$check( 'a mission given to nobody in particular is the whole organization', 5 === count( Messages::recipients( $team, 'mission-' . $open ) ) );
	$check( 'a group that does not exist is nobody', array() === Messages::recipients( $team, 'sub-999' ) && array() === Messages::recipients( $team, 'mission-' . $s1 ) && array() === Messages::recipients( $team, 'everyone' ) && array() === Messages::recipients( $other, 'mission-' . $mission ) );

	$check( 'the lead can write to every group', Messages::can_send( $team, 'team', $ana ) && Messages::can_send( $team, 'sub-' . $north, $ana ) && Messages::can_send( $team, 'mission-' . $mission, $ana ) && ! Messages::can_send( $team, 'sub-999', $ana ) );
	$check( 'a team lead writes to their team and its missions', Messages::can_send( $team, 'sub-' . $north, $ben ) && Messages::can_send( $team, 'mission-' . $mission, $ben ) );
	$check( 'and not to the organization, another team, or a mission that is not theirs', ! Messages::can_send( $team, 'team', $ben ) && ! Messages::can_send( $team, 'sub-' . $south, $ben ) && ! Messages::can_send( $team, 'mission-' . $open, $ben ) && ! Messages::can_send( $team, 'mission-' . $mission, $cal ) );
	Subteams::set_member( $team, $north, $eli, true, $ana );
	Subteams::set_colead( $team, $north, $eli, true, $ben );
	$check( 'a team co-lead can too', Messages::can_send( $team, 'sub-' . $north, $eli ) && Messages::can_send( $team, 'mission-' . $mission, $eli ) && ! Messages::can_send( $team, 'team', $eli ) );
	$check( 'a member, a stranger, and a visitor cannot write at all', ! Messages::can_send( $team, 'team', $dee ) && ! Messages::can_send( $team, 'sub-' . $north, $dee ) && ! Messages::can_send( $team, 'team', $stranger ) && ! Messages::can_send( $team, 'team', 0 ) );
	$check( 'a coordinator can', Messages::can_send( $team, 'team', $coordinator ) );
	$check( 'the lead is offered every group, the team lead theirs', $sorted( array( 'team', 'sub-' . $north, 'sub-' . $south, 'mission-' . $mission, 'mission-' . $open ) ) === $sorted( array_keys( Messages::audiences( $team, $ana ) ) ) && 'team' === array_keys( Messages::audiences( $team, $ana ) )[0] && array( 'sub-' . $north, 'mission-' . $mission ) === array_keys( Messages::audiences( $team, $ben ) ) && array() === Messages::audiences( $team, $dee ), array( array_keys( Messages::audiences( $team, $ana ) ), array_keys( Messages::audiences( $team, $ben ) ) ) );

	WP_CLI::log( 'Messages: sending' );
	foreach ( array( $ana, $ben, $eli, $coordinator ) as $person ) {
		delete_transient( $bucket( 'message-' . $person ) );
	}
	$check( 'a message needs a subject and a body', 'packout_message_subject' === $code( Messages::send( $team, 'team', ' ', 'Body.', $ana ) ) && 'packout_message_body' === $code( Messages::send( $team, 'team', 'Subject', '  ', $ana ) ) && 'packout_message_subject' === $code( Messages::send( $team, 'team', str_repeat( 's', Messages::SUBJECT + 1 ), 'Body.', $ana ) ) && 'packout_message_body' === $code( Messages::send( $team, 'team', 'Subject', str_repeat( 'b', Messages::BODY + 1 ), $ana ) ) );
	$check( 'a member cannot send one', 'packout_forbidden' === $code( Messages::send( $team, 'team', 'Hello', 'From a member.', $dee ) ) && 'packout_forbidden' === $code( Messages::send( $team, 'team', 'Hello', 'From a team lead.', $ben ) ) );
	$check( 'an organization that is not approved cannot send one', 'packout_team_pending' === $code( Messages::send( $pending, 'team', 'Hello', 'Too soon.', $eli ) ) );
	$check( 'a group with nobody else in it gets nothing', 'packout_message_nobody' === $code( Messages::send( $other, 'team', 'Hello', 'To myself.', $stranger ) ) );
	$check( 'nothing has been sent or kept', array() === Messages::all( $team ) );

	Profile::set_team_mail( $cal, $team, false );
	$before = count( $packout_mail );
	$sent   = Messages::send( $team, 'team', 'Saturday at the wash', "Meet at 8 at the trailhead.\n\nBring gloves. Map: https://example.org/map <b>bold</b>", $ana );
	$check( 'the lead writes to the organization', is_array( $sent ) && 5 === $sent['people'] && 4 === $sent['emailed'] && 1 === $sent['skipped'], $sent );
	$check( 'four messages go out, none to the person who turned them off', 4 === count( $packout_mail ) - $before && array() === array_filter( array_slice( $packout_mail, $before ), static fn( $mail ) => $mail['to'] === $email_of( $cal ) ) );
	$got = $last_mail( $email_of( $ben ) );
	$check( 'the subject names the organization', '[' . $name . '] Saturday at the wash' === $got['subject'] );
	$check( 'the message is HTML, with the body escaped and the link made a link', false !== strpos( $got['headers'], 'text/html' ) && false !== strpos( $got['message'], 'Meet at 8 at the trailhead.' ) && false === strpos( $got['message'], '<b>bold</b>' ) && false !== strpos( $got['message'], 'href="https://example.org/map"' ) );
	$check( 'it carries the organization\'s logo, as a PNG copy every email program can show', str_ends_with( Photos::mail_url( $newer ), '-mail.png' ) && false !== strpos( $got['message'], 'src="' . Photos::mail_url( $newer ) . '"' ), Photos::mail_url( $newer ) );
	$check( 'replies go to the sender', false !== strpos( $got['headers'], 'Reply-To: Ana Testperson <' . $email_of( $ana ) . '>' ) );
	$stop = $token_in( $got, 'pos_quiet' );
	$check(
		'it has a link that stops this organization\'s messages for this person',
		'' !== $stop && array(
			'user' => $ben,
			'team' => $team,
		) === Profile::read_quiet( $stop ) && false !== strpos( $got['headers'], 'List-Unsubscribe: <' )
	);
	$check(
		'each person\'s link is their own',
		$token_in( $last_mail( $email_of( $dee ) ), 'pos_quiet' ) !== $stop && array(
			'user' => $dee,
			'team' => $team,
		) === Profile::read_quiet( $token_in( $last_mail( $email_of( $dee ) ), 'pos_quiet' ) )
	);
	$check( 'a link that was changed stops nothing', null === Profile::read_quiet( $stop . 'x' ) && null === Profile::read_quiet( $link ) );
	$check( 'the sender gets a copy', null !== $last_mail( $email_of( $ana ) ) && '[' . $name . '] Saturday at the wash' === $last_mail( $email_of( $ana ) )['subject'] );
	$check( 'the action fires with the number of people', array( 'message', $team, $sent['id'], 5 ) === end( $packout_fired ) );
	$kept = Messages::get( $team, $sent['id'] );
	$check( 'the message is kept, with how many it reached', null !== $kept && 4 === $kept['sent'] && 1 === $kept['skipped'] && array() === $kept['queue'] && $ana === $kept['by'], $kept ? array( $kept['sent'], $kept['skipped'], $kept['queue'] ) : null );
	$check( 'the person who turned mail off can still read it', 1 === count( Messages::visible( $team, $cal ) ) && 1 === count( Messages::visible( $team, $dee ) ) && array() === Messages::visible( $team, $stranger ) );

	$before = count( $packout_mail );
	$sub    = Messages::send( $team, 'sub-' . $north, 'North wash only', 'We start at the upper gate.', $ben, false );
	$check( 'a team lead writes to their team', is_array( $sub ) && 3 === $sub['people'] && 3 === count( $packout_mail ) - $before, $sub );
	$got = $last_mail( $email_of( $dee ) );
	$check( 'that message carries the team\'s logo and name', '' !== Photos::mail_url( $sublogo ) && false !== strpos( $got['message'], 'src="' . Photos::mail_url( $sublogo ) . '"' ) && false !== strpos( $got['message'], 'North Wash' ) );
	$check( 'with replies switched off there is no reply address', false === strpos( $got['headers'], 'Reply-To' ) );
	$check( 'people outside the team do not see it, the organization\'s lead does', 1 === count( Messages::visible( $team, $cal ) ) && 2 === count( Messages::visible( $team, $dee ) ) && 2 === count( Messages::visible( $team, $ana ) ) );
	$before = count( $packout_mail );
	$about  = Messages::send( $team, 'mission-' . $mission, 'Gate code', 'The code is on the sheet.', $eli );
	$check( 'a co-lead writes to the people of a mission', is_array( $about ) && 4 === $about['people'] && 4 === count( $packout_mail ) - $before && null !== $last_mail( $email_of( $ana ) ) && 'Gate code' === substr( $last_mail( $email_of( $ana ) )['subject'], -9 ), $about );
	$check( 'messages are listed newest first', array( 'Gate code', 'North wash only', 'Saturday at the wash' ) === wp_list_pluck( Messages::all( $team ), 'subject' ) );

	WP_CLI::log( 'Messages: the tab, the stop link, and the limits' );
	$as( $ana );
	$html = Workspace_Views::team( Teams::get( $team ), 'messages', '', $ana );
	$check( 'the lead has a form with every group', false !== strpos( $html, 'value="message"' ) && false !== strpos( $html, 'value="sub-' . $north . '"' ) && false !== strpos( $html, 'value="mission-' . $open . '"' ) && false !== strpos( $html, 'Send the message' ) );
	$check( 'and the messages sent', false !== strpos( $html, 'Saturday at the wash' ) && false !== strpos( $html, 'Gate code' ) && false === strpos( $html, '<b>bold</b>' ) );
	$as( $dee );
	$html = Workspace_Views::team( Teams::get( $team ), 'messages', '', $dee );
	$check( 'a member reads and does not write', false === strpos( $html, 'Send the message' ) && false !== strpos( $html, 'North wash only' ) );
	$as( $cal );
	$html = Workspace_Views::team( Teams::get( $team ), 'messages', '', $cal );
	$check( 'a team lead with nobody in it yet can write only there', false !== strpos( $html, 'value="sub-' . $south . '"' ) && false === strpos( $html, 'value="team"' ) && false === strpos( $html, 'North wash only' ) );
	$as( 0 );

	Profile::set_team_mail( $ben, $team, false );
	$before = count( $packout_mail );
	$again  = Messages::send( $team, 'team', 'One more thing', 'Bring water.', $ana );
	$check( 'a person who used the stop link gets no more', is_array( $again ) && 2 === $again['skipped'] && array() === array_filter( array_slice( $packout_mail, $before ), static fn( $mail ) => $mail['to'] === $email_of( $ben ) ) );
	$check( 'a lead who turned mail off still gets a copy of what they send', is_array( Messages::send( $team, 'sub-' . $north, 'From Ben', 'Still here.', $ben ) ) && 'From Ben' === substr( $last_mail( $email_of( $ben ) )['subject'], -8 ) );
	Profile::set_team_mail( $ben, $team, true );

	Teams::remove_member( $team, $dee );
	$check( 'a person who left the organization reads none of it', array() === Messages::visible( $team, $dee ) );
	Teams::admit( $team, $dee );

	set_transient( $bucket( 'message-' . $ana ), 10, DAY_IN_SECONDS );
	$check( 'a person can send ten in a day', 'packout_message_limit' === $code( Messages::send( $team, 'team', 'Eleventh', 'Too many.', $ana ) ) );
	delete_transient( $bucket( 'message-' . $ana ) );

	// A long list goes out in batches.
	$row          = Messages::all( $team )[0];
	$row['id']    = 9001;
	$row['queue'] = array_merge( array_fill( 0, 50, $ben ), array( $cal, $eli ) );
	$row['sent']  = 0;
	update_option( 'packout_messages_' . $team, array_merge( array( $row ), Messages::all( $team ) ), false );
	$before = count( $packout_mail );
	Messages::deliver( $team, 9001 );
	$left = Messages::get( $team, 9001 );
	$check( 'a long list goes out fifty at a time', 50 === count( $packout_mail ) - $before && array( $cal, $eli ) === $left['queue'] && 50 === $left['sent'], array( count( $packout_mail ) - $before, $left['queue'] ) );
	$check( 'and the rest is scheduled', false !== wp_next_scheduled( 'packout_send_messages', array( $team, 9001 ) ) );
	$before = count( $packout_mail );
	Messages::deliver( $team, 9001 );
	$check( 'the next run reaches whoever still wants it', 1 === count( $packout_mail ) - $before && array() === Messages::get( $team, 9001 )['queue'] );
	wp_clear_scheduled_hook( 'packout_send_messages', array( $team, 9001 ) );

	WP_CLI::log( 'Settings' );
	$check( 'the My account page is a setting', array_key_exists( 'account_page', Settings::all() ) );
	$check( 'Profile points at it', '' === Profile::url() || 0 === strpos( Profile::url(), home_url( '/' ) ) );
} catch ( \Throwable $error ) {
	$packout_failures[] = 'The run stopped: ' . $error->getMessage();
	WP_CLI::log( '  FAIL  ' . $error->getMessage() . ' at ' . basename( $error->getFile() ) . ':' . $error->getLine() );
} finally {
	wp_set_current_user( 0 );
	remove_filter( 'pre_wp_mail', $keep_mail, 99 );
	remove_action( 'packout_spot_claimed', $on_claimed, 10 );
	remove_action( 'packout_message_sent', $on_message, 10 );
	if ( null === $packout_server ) {
		unset( $_SERVER['REMOTE_ADDR'] );
	} else {
		$_SERVER['REMOTE_ADDR'] = $packout_server;
	}
	foreach ( array_unique( $packout_buckets ) as $packout_bucket ) {
		delete_transient( 'pos_limit_' . md5( $packout_bucket ) );
	}

	$ids   = array_values( array_unique( array_filter( $packout_posts, 'is_int' ) ) );
	$spots = array_values( array_filter( $ids, static fn( $id ) => Schema::SPOT === get_post_type( $id ) ) );
	foreach ( $spots as $spot_id ) {
		$photo = (int) get_post_meta( $spot_id, '_pos_claim_photo', true );
		if ( $photo > 0 ) {
			wp_delete_attachment( $photo, true );
		}
	}
	$records = $spots ? get_posts(
		array(
			'post_type'   => Schema::IMPACT,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Cleanup of this run's records.
			'meta_query'  => array(
				array(
					'key'     => '_pos_spot',
					'value'   => $spots,
					'compare' => 'IN',
				),
			),
		)
	) : array();
	foreach ( array_unique( array_merge( $records, $ids ) ) as $post_id ) {
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
	Impact::flush();
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
