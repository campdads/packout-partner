<?php
/**
 * Phase 1 acceptance checks for Spots and sync.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-1.php
 *
 * The script creates its own users and Spots, exercises the REST routes as
 * those users, and removes everything it created. It exits non-zero when a
 * check fails.
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Geo;
use PackOut\Core\App\Photos;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Settings;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Post_Access;

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
$packout_spots    = array();
$packout_users    = array();
$packout_files    = array();

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

$call = static function ( string $method, string $route, array $params = array(), array $files = array() ) {
	$request = new WP_REST_Request( $method, '/packout/v1' . $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
		if ( $files ) {
			$request->set_header( 'Content-Type', 'multipart/form-data' );
			$request->set_body_params( $params );
			$request->set_file_params( $files );
		}
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
};

$user = static function ( string $role ) use ( &$packout_users ): int {
	$login           = 'pos-test-' . $role . '-' . wp_generate_password( 6, false );
	$id              = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);
	$packout_users[] = $id;
	return $id;
};

$uuid = static fn() => wp_generate_uuid4();

$image = static function () use ( &$packout_files ): string {
	$path  = wp_tempnam( 'pos-test.jpg' );
	$image = imagecreatetruecolor( 320, 240 );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 187, 94, 40 ) );
	imagejpeg( $image, $path, 80 );
	$packout_files[] = $path;
	return $path;
};

$saved_settings = get_option( Settings::option() );

try {
	WP_CLI::log( 'Install' );
	$check( 'Spot post type is registered', post_type_exists( Schema::SPOT ) );
	$check(
		'six category terms exist',
		6 === (int) wp_count_terms(
			array(
				'taxonomy'   => Schema::CATEGORY,
				'hide_empty' => false,
			)
		)
	);
	$check( 'suggested roles exist', get_role( 'pos_reporter' ) && get_role( 'pos_crew' ) && get_role( 'pos_lead' ) && get_role( 'pos_coordinator' ) );
	if ( $hub ) {
		$check( 'Spots are offered to Post Type Tables', in_array( Schema::SPOT, (array) apply_filters( 'vectra_tables_post_types', array() ), true ) );
		$check( 'capabilities are labelled for Toolkit Roles', isset( apply_filters( 'vectra_register_capabilities', array() )['packout_moderate'] ) );
	} else {
		$skip( 'Toolkit’s tables and Toolkit Roles are on packout.spot.' );
	}

	$reporter    = $user( 'pos_reporter' );
	$other       = $user( 'pos_reporter' );
	$coordinator = $user( 'pos_coordinator' );
	$subscriber  = $user( 'subscriber' );

	WP_CLI::log( 'Reporting from the field app' );
	$as( $reporter );
	$client              = $uuid();
	list( $code, $data ) = $call(
		'PUT',
		'/spots/client/' . $client,
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Pile of tires and a broken cooler.',
			'landmark'    => 'Pullout past the second cattle guard on FS 214',
		)
	);
	$spot_id             = (int) ( $data['spot']['id'] ?? 0 );
	$packout_spots[]     = $spot_id;
	$check( 'a landmark with no coordinates creates a Spot', 201 === $code && $spot_id > 0, $data );
	$check( 'the new Spot is pending and spotted', 'pending' === ( $data['spot']['post_status'] ?? '' ) && 'spotted' === ( $data['spot']['status'] ?? '' ) );
	$check( 'location kind is landmark', 'landmark' === ( $data['spot']['location']['kind'] ?? '' ) );
	$check( 'the title is generated from category and landmark', str_starts_with( (string) ( $data['spot']['title'] ?? '' ), 'Cleanup and waste: Pullout' ), $data['spot']['title'] ?? '' );

	list( $code, $data ) = $call(
		'PUT',
		'/spots/client/' . $client,
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Pile of tires and a broken cooler.',
			'landmark'    => 'Pullout past the second cattle guard on FS 214',
			'lat'         => 34.123456,
			'lng'         => -116.654321,
			'accuracy'    => 8,
			'revision'    => $data['spot']['revision'] ?? 0,
		)
	);
	$check( 'adding a pin later updates the same Spot', 200 === $code && (int) ( $data['spot']['id'] ?? 0 ) === $spot_id && 'pin' === ( $data['spot']['location']['kind'] ?? '' ), $data );

	list( $code, $again ) = $call(
		'PUT',
		'/spots/client/' . $client,
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Pile of tires and a broken cooler.',
			'landmark'    => 'Pullout past the second cattle guard on FS 214',
			'lat'         => 34.123456,
			'lng'         => -116.654321,
			'accuracy'    => 8,
		)
	);
	$mine                 = get_posts(
		array(
			'post_type'   => Schema::SPOT,
			'post_status' => 'any',
			'author'      => $reporter,
			'numberposts' => -1,
		)
	);
	$check( 'a repeat sync with the same client id does not create another Spot', 200 === $code && 1 === count( $mine ) && (int) $again['spot']['revision'] === (int) $data['spot']['revision'], array( $code, count( $mine ) ) );

	list( $code ) = $call(
		'PUT',
		'/spots/client/' . $uuid(),
		array(
			'category'    => 'cleanup-waste',
			'description' => 'No way to find it.',
		)
	);
	$check( 'a Spot with no photo, pin, or landmark is refused', 400 === $code, $code );
	list( $code ) = $call(
		'PUT',
		'/spots/client/' . $uuid(),
		array(
			'category' => 'cleanup-waste',
			'landmark' => 'Somewhere',
		)
	);
	$check( 'a Spot with no description is refused', 400 === $code, $code );
	list( $code, $data ) = $call(
		'PUT',
		'/spots/client/' . $uuid(),
		array(
			'category'       => 'cleanup-waste',
			'description'    => 'Photos to follow.',
			'photos_pending' => 2,
		)
	);
	$photo_spot          = (int) ( $data['spot']['id'] ?? 0 );
	$packout_spots[]     = $photo_spot;
	$check( 'a text-and-photo report with no pin is accepted', 201 === $code && 'description' === ( $data['spot']['location']['kind'] ?? '' ), $data );

	WP_CLI::log( 'Photos' );
	$upload              = static fn( string $path ) => array(
		'file' => array(
			'name'     => 'spot.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => $path,
			'error'    => 0,
			'size'     => filesize( $path ),
		),
	);
	list( $code, $data ) = $call( 'POST', '/spots/' . $photo_spot . '/photos', array( 'client_photo_id' => 'photo-a' ), $upload( $image() ) );
	$first_photo         = (int) ( $data['photo'] ?? 0 );
	$check( 'a photo attaches after the Spot exists', 201 === $code && $first_photo > 0 && (int) wp_get_post_parent_id( $first_photo ) === $photo_spot, $data );
	list( $code, $data ) = $call( 'POST', '/spots/' . $photo_spot . '/photos', array( 'client_photo_id' => 'photo-a' ), $upload( $image() ) );
	$check( 'a repeated photo upload is not attached twice', (int) ( $data['photo'] ?? 0 ) === $first_photo && 1 === count( Photos::for_spot( $photo_spot ) ), $data );
	$call( 'POST', '/spots/' . $photo_spot . '/photos', array( 'client_photo_id' => 'photo-b' ), $upload( $image() ) );
	$check( 'two photos are attached', 2 === count( Photos::for_spot( $photo_spot ) ) );

	$text = wp_tempnam( 'pos-test.txt' );
	file_put_contents( $text, 'not an image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
	$packout_files[] = $text;
	list( $code )    = $call( 'POST', '/spots/' . $photo_spot . '/photos', array(), $upload( $text ) );
	$check( 'a file that is not an image is refused', 400 === $code, $code );

	$as( $other );
	list( $code ) = $call( 'POST', '/spots/' . $photo_spot . '/photos', array(), $upload( $image() ) );
	$check( 'another reporter cannot add photos to the Spot', 403 === $code, $code );

	WP_CLI::log( 'Visibility' );
	$as( 0 );
	list( $code ) = $call( 'GET', '/spots/' . $spot_id );
	$check( 'an anonymous request for a pending Spot is denied', 404 === $code, $code );
	list( , $data ) = $call( 'GET', '/spots' );
	$check( 'the anonymous map omits pending Spots', ! in_array( $spot_id, wp_list_pluck( $data['spots'], 'id' ), true ) );
	$check( 'Post_Access refuses a pending Spot to a visitor', ! Post_Access::can_read( $spot_id, 0 ) );
	list( $code ) = $call( 'GET', '/regions' );
	$check( 'regions require a reporter', in_array( $code, array( 401, 403 ), true ), $code );
	list( $code ) = $call(
		'PUT',
		'/spots/client/' . $uuid(),
		array(
			'category'    => 'cleanup-waste',
			'description' => 'x',
			'landmark'    => 'y',
		)
	);
	$check( 'an anonymous sync is refused', in_array( $code, array( 401, 403 ), true ), $code );
	$as( $subscriber );
	list( $code ) = $call(
		'PUT',
		'/spots/client/' . $uuid(),
		array(
			'category'    => 'cleanup-waste',
			'description' => 'x',
			'landmark'    => 'y',
		)
	);
	$check( 'a user without packout_report cannot sync', 403 === $code, $code );
	$as( $other );
	list( $code ) = $call( 'GET', '/spots/' . $spot_id );
	$check( 'another reporter cannot read a pending Spot', 404 === $code, $code );

	WP_CLI::log( 'Conflicts' );
	$as( $coordinator );
	Spots::update( $spot_id, array( 'description' => 'Pile of tires, a broken cooler, and a mattress.' ) );
	$as( $reporter );
	list( $code, $data ) = $call(
		'PUT',
		'/spots/client/' . $client,
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Reporter edit made offline.',
			'landmark'    => 'Pullout past the second cattle guard on FS 214',
			'lat'         => 34.123456,
			'lng'         => -116.654321,
			'revision'    => (int) $again['spot']['revision'],
		)
	);
	$check( 'a coordinator edit between two syncs returns a conflict', 409 === $code && 'packout_conflict' === ( $data['code'] ?? '' ), $data );
	$check( 'the conflict carries the server copy', str_contains( (string) ( $data['data']['spot']['description'] ?? '' ), 'mattress' ) );
	$check( 'the coordinator edit was not overwritten', str_contains( Spots::get( $spot_id )->post_content, 'mattress' ) );
	list( $code, $data ) = $call(
		'PUT',
		'/spots/client/' . $client,
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Reporter edit made offline.',
			'landmark'    => 'Pullout past the second cattle guard on FS 214',
			'lat'         => 34.123456,
			'lng'         => -116.654321,
			'revision'    => (int) $data['data']['spot']['revision'],
		)
	);
	$check( 'the reporter can keep their copy after seeing the conflict', 200 === $code && 'Reporter edit made offline.' === ( $data['spot']['description'] ?? '' ), $data );

	WP_CLI::log( 'Moderation and the public map' );
	$published = 0;
	add_action(
		'packout_spot_published',
		static function () use ( &$published ) {
			++$published;
		}
	);
	$as( $coordinator );
	Spots::publish( $spot_id );
	$check( 'publishing fires packout_spot_published', 1 === $published );

	$as( 0 );
	list( $code, $data ) = $call( 'GET', '/spots/' . $spot_id );
	$check( 'a visitor can read a published Spot', 200 === $code, $code );
	$check( 'Post_Access allows a published Spot to a visitor', Post_Access::can_read( $spot_id, 0 ) );
	$public = $data['spot'] ?? array();
	$check( 'the public Spot has no reporter, photos, client id, or revision', ! isset( $public['reporter'] ) && ! isset( $public['photos'] ) && ! isset( $public['client_id'] ) && ! isset( $public['revision'] ) );
	$check( 'an exact published Spot shows its pin', 34.123456 === (float) ( $public['location']['lat'] ?? 0 ) );
	$check( 'the Spot has a public address', str_contains( (string) ( $public['url'] ?? '' ), '/spot/' ), $public['url'] ?? '' );

	Spots::set_precision( $spot_id, 'coarse' );
	list( , $data )        = $call( 'GET', '/spots/' . $spot_id );
	$public                = $data['spot'];
	list( $c_lat, $c_lng ) = Geo::coarse( 34.123456, -116.654321 );
	$check( 'a coarse Spot gives the public a different location', (float) $public['location']['lat'] === $c_lat && (float) $public['location']['lng'] === $c_lng && 34.123456 !== (float) $public['location']['lat'], $public['location'] );
	$check( 'the coarse location is at least 100 m from the pin', Geo::distance( 34.123456, -116.654321, $c_lat, $c_lng ) > 100 );
	$check( 'a coarse Spot hides its landmark from the public', '' === $public['location']['landmark'] );
	list( , $data ) = $call( 'GET', '/spots', array( 'bbox' => '-116.6544,34.1234,-116.6542,34.1236' ) );
	$check( 'a tight box around the real pin does not find a coarse Spot', ! in_array( $spot_id, wp_list_pluck( $data['spots'], 'id' ), true ) );
	$shown          = sprintf( '%.4F,%.4F,%.4F,%.4F', $c_lng - 0.001, $c_lat - 0.001, $c_lng + 0.001, $c_lat + 0.001 );
	list( , $data ) = $call( 'GET', '/spots', array( 'bbox' => $shown ) );
	$check( 'a box around where it is shown finds it', in_array( $spot_id, wp_list_pluck( $data['spots'], 'id' ), true ) );
	$check( 'an answer that holds every Spot says there are no more', false === ( $data['more'] ?? null ), $data['more'] ?? null );

	// A site with more Spots than one answer carries: the box is asked of the database, not of the newest Spots.
	$far = Spots::create(
		array(
			'category'    => array_key_first( Schema::categories() ),
			'description' => 'A Spot a long way from the others.',
			'landmark'    => 'Mile 200',
			'lat'         => 40.5,
			'lng'         => -100.5,
		),
		$coordinator
	);
	$check( 'a second Spot is made far away', is_int( $far ), $far );
	if ( is_int( $far ) ) {
		Spots::publish( $far );
		$one            = static fn() => 1;
		add_filter( 'packout_spots_answer', $one );
		list( , $data ) = $call( 'GET', '/spots' );
		$check( 'a full answer carries as many as it may and says there are more', 1 === count( $data['spots'] ) && true === $data['more'], array( count( $data['spots'] ), $data['more'] ?? null ) );
		list( , $data ) = $call( 'GET', '/spots', array( 'bbox' => $shown ) );
		$check( 'the box finds a Spot whether or not it is among the newest', array( $spot_id ) === wp_list_pluck( $data['spots'], 'id' ), wp_list_pluck( $data['spots'], 'id' ) );
		list( , $data ) = $call( 'GET', '/spots', array( 'bbox' => '-100.6,40.4,-100.4,40.6' ) );
		$check( 'and the other box finds the other', array( $far ) === wp_list_pluck( $data['spots'], 'id' ), wp_list_pluck( $data['spots'], 'id' ) );
		remove_filter( 'packout_spots_answer', $one );
		wp_delete_post( $far, true );
	}
	list( , $data ) = $call( 'GET', '/spots' );
	$check( 'a Spot with no photo and no record has an empty gallery in the list', array() === ( array_values( array_filter( $data['spots'], static fn( $spot ) => $spot_id === $spot['id'] ) )[0]['gallery'] ?? null ) );
	$as( $coordinator );
	list( , $data ) = $call( 'GET', '/spots/' . $spot_id );
	$check( 'the coordinator still sees the exact pin', 34.123456 === (float) $data['spot']['location']['lat'] );
	$as( $other );
	list( , $data ) = $call( 'GET', '/spots/' . $spot_id );
	$check( 'another reporter sees the coarse location', (float) $data['spot']['location']['lat'] === $c_lat );
	Spots::set_precision( $spot_id, 'exact' );
	list( , $data ) = $call( 'GET', '/spots/' . $spot_id );
	$check( 'a reporter sees the exact pin of an exact published Spot', 34.123456 === (float) $data['spot']['location']['lat'] );

	$as( $reporter );
	list( $code, $data ) = $call(
		'PUT',
		'/spots/client/' . $client,
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Change after review.',
			'landmark'    => 'x',
		)
	);
	$check( 'a reporter change after review is refused with the server copy', 409 === $code && 'packout_reviewed' === ( $data['code'] ?? '' ) && isset( $data['data']['spot'] ), $data );

	WP_CLI::log( 'Hazards' );
	Spots::set_hazard( $spot_id, 'do_not_touch' );
	$as( 0 );
	list( , $data ) = $call( 'GET', '/spots/' . $spot_id );
	$check( 'a do_not_touch Spot tells people to leave the material alone', true === $data['spot']['needs_authorization'] && str_contains( $data['spot']['guidance'], 'Leave this material' ), $data['spot'] );

	WP_CLI::log( 'Duplicate hints' );
	$as( $other );
	// 20 meters north of the first pin.
	list( $code, $data ) = $call(
		'PUT',
		'/spots/client/' . $uuid(),
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Tires by the pullout.',
			'lat'         => 34.123456 + ( 20 / 111320 ),
			'lng'         => -116.654321,
		)
	);
	$near                = (int) ( $data['spot']['id'] ?? 0 );
	$packout_spots[]     = $near;
	$hint                = $data['duplicates'][0] ?? array();
	$check( 'two pins 20 m apart in one category produce a duplicate hint', 201 === $code && (int) ( $hint['id'] ?? 0 ) === $spot_id && abs( (int) ( $hint['distance'] ?? 0 ) - 20 ) <= 2, $data['duplicates'] ?? array() );
	$check( 'both remain separate Spots', Spots::get( $near ) && Spots::get( $spot_id ) && $near !== $spot_id );

	list( , $data )  = $call(
		'PUT',
		'/spots/client/' . $uuid(),
		array(
			'category'    => 'trails-access',
			'description' => 'Washed-out section.',
			'lat'         => 34.123456,
			'lng'         => -116.654321,
		)
	);
	$packout_spots[] = (int) ( $data['spot']['id'] ?? 0 );
	$check( 'a pin in another category is not a duplicate hint', array() === ( $data['duplicates'] ?? null ), $data['duplicates'] ?? null );

	$as( $coordinator );
	Spots::publish( $near );
	Spots::mark_duplicate( $near, $spot_id );
	$as( 0 );
	list( , $data ) = $call( 'GET', '/spots' );
	$ids            = wp_list_pluck( $data['spots'], 'id' );
	$check( 'a linked duplicate leaves the public map and the older Spot stays', ! in_array( $near, $ids, true ) && in_array( $spot_id, $ids, true ) );
	$check( 'the duplicate record remains for the coordinator', 'publish' === get_post_status( $near ) );

	if ( $hub ) {
		WP_CLI::log( 'Website form' );
		$as( 0 );
		Settings::save( array( 'form' => 'pos-test-form' ) );
		$uploads = wp_get_upload_dir();
		wp_mkdir_p( $uploads['basedir'] . '/pos-test' );
		$form_file = $uploads['basedir'] . '/pos-test/form-photo.jpg';
		copy( $image(), $form_file );
		$packout_files[] = $form_file;
		$before          = (int) wp_count_posts( Schema::SPOT )->pending;
		do_action(
			'vectra_form_submitted',
			array(
				'id'       => 9001,
				'form_key' => 'pos-test-form',
			),
			array( 'id' => 77 ),
			array(
				'by_key' => array(
					'category'    => 'Waterways',
					'description' => 'Trash collecting at the creek crossing.',
					'landmark'    => 'Second creek crossing below the trailhead',
					'photo'       => $uploads['baseurl'] . '/pos-test/form-photo.jpg',
				),
			)
		);
		$from_form = get_posts(
			array(
				'post_type'   => Schema::SPOT,
				'post_status' => 'pending',
				'numberposts' => 1,
				'meta_key'    => '_pos_entry_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test lookup.
			'meta_value'      => 77, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test lookup.
			)
		);
		$form_spot = $from_form ? $from_form[0] : null;
		if ( $form_spot ) {
			$packout_spots[] = $form_spot->ID;
		}
		$check( 'a guest form submission creates one pending Spot', $form_spot && (int) wp_count_posts( Schema::SPOT )->pending === $before + 1 );
		$check( 'the form Spot has the description, category, and no user', $form_spot && str_contains( $form_spot->post_content, 'creek crossing' ) && 'waterways' === Spots::category( $form_spot->ID )->slug && 0 === (int) $form_spot->post_author );
		$check( 'the form photo is attached to the Spot', $form_spot && 1 === count( Photos::for_spot( $form_spot->ID ) ) );
		do_action(
			'vectra_form_submitted',
			array(
				'id'       => 9002,
				'form_key' => 'another-form',
			),
			array( 'id' => 78 ),
			array(
				'by_key' => array(
					'description' => 'Not ours',
					'landmark'    => 'x',
				),
			)
		);
		$check( 'a different form does not create a Spot', (int) wp_count_posts( Schema::SPOT )->pending === $before + 1 );
	} else {
		$skip( 'The hub’s report form is Toolkit’s. A partner site’s own form is checked in its plugin.' );
	}

	WP_CLI::log( 'Sync summary' );
	$as( $reporter );
	list( $code, $data ) = $call( 'GET', '/sync' );
	$row                 = current( array_filter( $data['spots'] ?? array(), static fn( $item ) => $item['client_id'] === $client ) );
	$check( 'sync lists the reporter\'s Spot with its revision and status', 200 === $code && $row && 'publish' === $row['post_status'] && $row['revision'] > 1, $data );

	WP_CLI::log( 'Trash' );
	$photo_file = get_attached_file( $first_photo );
	wp_trash_post( $photo_spot );
	$as( $coordinator );
	list( , $data ) = $call( 'GET', '/spots' );
	$check( 'a trashed Spot leaves the map', ! in_array( $photo_spot, wp_list_pluck( $data['spots'], 'id' ), true ) );
	$check( 'its photo files stay in the media library', is_readable( $photo_file ) && get_post( $first_photo ) );
} finally {
	wp_set_current_user( 0 );
	foreach ( array_filter( $packout_spots ) as $spot ) {
		foreach ( get_children(
			array(
				'post_parent' => $spot,
				'post_type'   => 'attachment',
			)
		) as $attachment ) {
			wp_delete_attachment( $attachment->ID, true );
		}
		wp_delete_post( $spot, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $packout_users as $id ) {
		is_multisite() ? wpmu_delete_user( $id ) : wp_delete_user( $id );
	}
	foreach ( $packout_files as $file ) {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	if ( false === $saved_settings ) {
		delete_option( Settings::option() );
	} else {
		update_option( Settings::option(), $saved_settings );
	}
}

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( count( $packout_failures ) . ' failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
