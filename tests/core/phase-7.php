<?php
/**
 * Phase 7 acceptance checks: photos in any format, Places with a cover, a
 * logo, and several adopters, My account as separate pages, the menu, the
 * signed-in cookie, and the lists and panes of My organizations.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-7.php
 *
 * The script creates its own users, pages, Spots, an organization, and Places, keeps
 * mail in memory, and removes everything it created. Settings it changes are
 * put back. It exits non-zero when a check fails.
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\Blocks;
use PackOut\Core\App\Messages;
use PackOut\Core\App\Photos;
use PackOut\Core\App\Places;
use PackOut\Core\App\Profile;
use PackOut\Core\App\Resources;
use PackOut\Core\App\Schema;
use PackOut\Spot\Session;
use PackOut\Core\App\Settings;
use PackOut\Core\App\Sign_In;
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

/**
 * A file as a form sends it.
 *
 * @param string $name   File name.
 * @param string $kind   jpeg, png, gif, webp, bmp, or script.
 * @param int    $width  Width in pixels.
 * @param int    $height Height in pixels.
 */
$file = static function ( string $name, string $kind, int $width = 120, int $height = 60 ) use ( &$packout_files ): array {
	$path = wp_tempnam( $name );
	if ( 'script' === $kind ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test file.
		file_put_contents( $path, "<?php echo 'not an image';\n" );
	} else {
		$image = imagecreatetruecolor( $width, $height );
		if ( 'png' === $kind ) {
			// A clear corner, to see that the copy keeps it.
			imagealphablending( $image, false );
			imagesavealpha( $image, true );
			imagefill( $image, 0, 0, imagecolorallocatealpha( $image, 187, 94, 40, 0 ) );
			imagefilledrectangle( $image, 0, 0, (int) ( $width / 2 ), (int) ( $height / 2 ), imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );
		} else {
			imagefill( $image, 0, 0, imagecolorallocate( $image, 40, 94, 187 ) );
		}
		$write = array(
			'jpeg' => 'imagejpeg',
			'png'  => 'imagepng',
			'gif'  => 'imagegif',
			'webp' => 'imagewebp',
			'bmp'  => 'imagebmp',
		)[ $kind ];
		$write( $image, $path );
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

$page = static function ( string $title, string $content, int $under = 0 ) use ( &$packout_posts, $packout_run ): int {
	$id              = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => sanitize_title( $title ) . '-' . $packout_run,
			'post_content' => $content,
			'post_parent'  => $under,
		)
	);
	$packout_posts[] = $id;
	return $id;
};

/**
 * A menu item for a page, as the menu filter is given it.
 */
$item = static function ( int $id, int $page_id, string $title, int $under = 0 ): WP_Post {
	$post                   = new WP_Post( (object) array( 'ID' => $id ) );
	$post->object           = 'page';
	$post->object_id        = (string) $page_id;
	$post->title            = $title;
	$post->url              = (string) get_permalink( $page_id );
	$post->menu_item_parent = (string) $under;
	$post->classes          = array( 'menu-item' );
	$post->current          = false;
	return $post;
};

// Keep mail in memory. Nothing leaves the machine.
$keep_mail = static fn() => true;
add_filter( 'pre_wp_mail', $keep_mail, 99 );

try {
	$coordinator = $user( 'pos_coordinator', 'Coordinator' );
	$reporter    = $user( 'pos_reporter', 'Reporter' );
	$ana         = $user( 'pos_lead', 'Ana' );
	$ben         = $user( 'pos_reporter', 'Ben' );
	$cal         = $user( 'pos_reporter', 'Cal' );
	$lea         = $user( 'pos_lead', 'Lea' );
	$lou         = $user( 'pos_lead', 'Lou' );

	WP_CLI::log( 'Photos: any format in, WebP out' );
	$check( 'a photo field offers every image the device has, HEIC and AVIF by name', false !== strpos( Photos::accept(), 'image/*' ) && false !== strpos( Photos::accept(), '.heic' ) && false !== strpos( Photos::accept(), '.avif' ) );
	$check( 'and says what happens to it', false !== strpos( Photos::hint(), 'WebP' ) && Photos::limit_mb() >= 1 && Photos::limit_mb() <= 25, Photos::hint() );
	foreach ( array( 'jpeg', 'png', 'gif', 'webp', 'bmp' ) as $kind ) {
		$made = Photos::convert( $file( 'photo.' . $kind, $kind, 320, 200 )['tmp_name'] );
		$ok   = is_array( $made ) && 'image/webp' === $made['type'] && 'image/webp' === wp_get_image_mime( $made['file'] );
		if ( is_array( $made ) ) {
			$packout_files[] = $made['file'];
		}
		$check( 'a ' . strtoupper( $kind ) . ' becomes a WebP', $ok, is_wp_error( $made ) ? $made->get_error_message() : $made );
	}
	$big  = $file( 'big.jpg', 'jpeg', 4000, 3000 );
	$made = Photos::convert( $big['tmp_name'] );
	$size = is_array( $made ) ? (array) wp_getimagesize( $made['file'] ) : array( 0, 0 );
	if ( is_array( $made ) ) {
		$packout_files[] = $made['file'];
	}
	$check( 'a large photo is made no longer than the limit on its long side, in proportion', is_array( $made ) && Photos::EDGE === (int) $size[0] && 1920 === (int) $size[1], $size );
	$check( 'and the copy is smaller than what was sent', is_array( $made ) && filesize( $made['file'] ) < $big['size'] );
	$small = Photos::convert( $file( 'small.png', 'png', 200, 100 )['tmp_name'] );
	$size  = is_array( $small ) ? (array) wp_getimagesize( $small['file'] ) : array( 0, 0 );
	if ( is_array( $small ) ) {
		$packout_files[] = $small['file'];
	}
	$check( 'a small one is not made larger', is_array( $small ) && 200 === (int) $size[0] && 100 === (int) $size[1], $size );
	$clear = is_array( $small ) ? imagecreatefromwebp( $small['file'] ) : false;
	$check( 'a clear background stays clear', false !== $clear && 127 === ( imagecolorat( $clear, 5, 5 ) >> 24 & 0x7F ) && 0 === ( imagecolorat( $clear, 190, 90 ) >> 24 & 0x7F ) );
	$check( 'a script with a photo\'s name is refused', 'packout_photo_type' === $code( Photos::convert( $file( 'photo.jpg', 'script' )['tmp_name'] ) ) );
	$check( 'and so is a file that is not there', 'packout_photo_type' === $code( Photos::convert( '/tmp/pos-not-here-' . $packout_run . '.jpg' ) ) );

	$spot            = Spots::create(
		array(
			'category'    => 'cleanup-waste',
			'description' => 'Cans by the fire ring. ' . $packout_run,
			'lat'         => 34.61,
			'lng'         => -111.82,
		),
		$reporter
	);
	$packout_posts[] = $spot;
	$shot            = Photos::attach_upload( $spot, $file( 'IMG_0001.PNG', 'png', 3000, 1500 ) );
	$path            = is_int( $shot ) ? (string) get_attached_file( $shot ) : '';
	$size            = '' !== $path ? (array) wp_getimagesize( $path ) : array( 0, 0 );
	$check( 'a Spot photo is kept as a WebP file', is_int( $shot ) && 'image/webp' === get_post_mime_type( $shot ) && str_ends_with( $path, '.webp' ) && 'image/webp' === wp_get_image_mime( $path ), array( $shot, $path ) );
	$check( 'no longer than the limit', Photos::EDGE === (int) $size[0] && 1280 === (int) $size[1], $size );
	$check( 'and what was sent is not kept beside it', '' !== $path && array() === array_filter( (array) glob( dirname( $path ) . '/' . pathinfo( $path, PATHINFO_FILENAME ) . '*' ), static fn( $each ) => ! str_ends_with( (string) $each, '.webp' ) ) );
	$check( 'a script is not a Spot photo', is_wp_error( Photos::attach_upload( $spot, $file( 'IMG_0002.jpg', 'script' ) ) ) && 1 === count( Photos::for_spot( $spot ) ) );

	WP_CLI::log( 'The report form\'s photo field' );
	$report_form = class_exists( '\Vectra\Forms\Forms' ) ? \Vectra\Forms\Forms::locate( (string) Settings::get( 'form' ) ) : null;
	if ( is_array( $report_form ) ) {
		$report_keys  = (array) Settings::get( 'form_keys' );
		$report_photo = null;
		foreach ( (array) ( $report_form['fields'] ?? array() ) as $field ) {
			if ( (string) ( $report_keys['photo'] ?? '' ) === (string) ( $field['field_key'] ?? '' ) ) {
				$report_photo = $field;
			}
		}
		$takes = array_filter( array_map( 'trim', explode( ',', (string) ( $report_photo['settings']['extensions'] ?? '' ) ) ) );
		$check( 'the form takes the files a phone or a computer makes', is_array( $report_photo ) && 'upload' === $report_photo['type'] && array() === array_diff( array( 'jpg', 'jpeg', 'png', 'webp', 'heic' ), $takes ), $takes );
		$html = Blocks::render_report( array( 'showForm' => true ) );
		$sent = preg_match( '/data-pos-locate="([^"]+)"/', $html, $found ) ? json_decode( html_entity_decode( $found[1], ENT_QUOTES ), true ) : array();
		$own  = (int) ( $report_photo['settings']['max_mb'] ?? 0 );
		$check( 'the page is told which field is the photo and what to ask the device for', ( $sent['picture'] ?? '' ) === '[data-vectra-key="' . ( $report_keys['photo'] ?? '' ) . '"] input[type="file"]' && '.vectra-field' === ( $sent['row'] ?? '' ) && Photos::accept() === ( $sent['accept'] ?? '' ), $sent );
		$check( 'and the largest photo it takes: the smaller of the field\'s limit and the server\'s', ( $sent['limit'] ?? 0 ) === ( $own > 0 ? min( $own, Photos::limit_mb() ) : Photos::limit_mb() ) && false !== strpos( (string) ( $sent['large'] ?? '' ), '%2$s' ), array( $sent['limit'] ?? null, $own, Photos::limit_mb() ) );
	} else {
		WP_CLI::log( '  skip  no report form is selected in settings' );
	}

	WP_CLI::log( 'Places: cover, logo, and several adopters' );
	$name            = 'Rim Test Crew ' . $packout_run;
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
	$second          = Teams::save(
		0,
		array(
			'name'   => 'Wash Test Crew ' . $packout_run,
			'lead'   => $lea,
			'listed' => true,
		)
	);
	$packout_posts[] = $second;
	$hidden          = Teams::save(
		0,
		array(
			'name'   => 'Quiet Test Crew ' . $packout_run,
			'lead'   => $lou,
			'listed' => false,
		)
	);
	$packout_posts[] = $hidden;
	if ( $hub ) {
		$place_name      = 'Test Rim Road ' . $packout_run;
		$place           = Places::save(
			0,
			array(
				'name'        => $place_name,
				'kind'        => 'road',
				'description' => 'Eleven miles of dirt road with dispersed campsites along the rim.',
				'listed'      => true,
			),
			$coordinator
		);
		$packout_posts[] = $place;
		$check( 'a Place starts with no cover, no logo, and no adopters', is_int( $place ) && 0 === Places::cover( $place ) && 0 === Places::logo( $place ) && array() === Places::adopters( $place ) && null === Places::adoption( $place ) );

		$check( 'a lead cannot record an adoption', is_wp_error( Places::adopt( $place, $team, array(), $ana ) ) && array() === Places::adopters( $place ) );
		$check(
			'a coordinator can, with the date and the promise',
			true === Places::adopt(
				$place,
				$team,
				array(
					'adopted_on'    => '2026-04-18',
					'adoption_note' => 'Walked on the first Saturday of every month.',
				),
				$coordinator
			)
		);
		$check( 'a second organization adopts the same Place', true === Places::adopt( $place, $second, array( 'adopted_on' => '2026-06-01' ), $coordinator ) );
		Places::adopt( $place, $hidden, array(), $coordinator );
		$adopters = Places::adopters( $place );
		$check( 'the adopters are kept in the order they were recorded', array( $team, $second, $hidden ) === array_column( $adopters, 'team' ), array_column( $adopters, 'team' ) );
		$check( 'each with its date and its note', '2026-04-18' === $adopters[0]['since'] && 'Walked on the first Saturday of every month.' === $adopters[0]['note'] && '2026-06-01' === $adopters[1]['since'] );
		$check( 'the first adopter is still the adoption older code asks for', ( Places::adoption( $place )['team'] ?? 0 ) === $team );
		Places::adopt( $place, $team, array( 'adoption_note' => 'Walked on the first Saturday.' ), $coordinator );
		$adopters = Places::adopters( $place );
		$check( 'recording an organization again changes its row and adds none', 3 === count( $adopters ) && 'Walked on the first Saturday.' === $adopters[0]['note'] && '2026-04-18' === $adopters[0]['since'] );
		$check( 'each organization is told the Places it adopted', array( $place ) === wp_list_pluck( Places::of_team( $team ), 'ID' ) && array( $place ) === wp_list_pluck( Places::of_team( $second ), 'ID' ) );
		$check( 'a lead cannot end an adoption', is_wp_error( Places::release( $place, $second, $lea ) ) && 3 === count( Places::adopters( $place ) ) );

		$check( 'a lead cannot give a Place a cover', is_wp_error( Places::set_image( $place, 'cover', $file( 'cover.jpg', 'jpeg', 2000, 900 ), $ana ) ) && 0 === Places::cover( $place ) );
		$check( 'a script is not a cover', is_wp_error( Places::set_image( $place, 'cover', $file( 'cover.jpg', 'script' ), $coordinator ) ) && 0 === Places::cover( $place ) );
		$check( 'only a cover or a logo can be set', is_wp_error( Places::set_image( $place, 'banner', $file( 'cover.jpg', 'jpeg' ), $coordinator ) ) );
		$cover = Places::set_image( $place, 'cover', $file( 'cover.jpg', 'jpeg', 3200, 1400 ), $coordinator );
		$size  = is_int( $cover ) ? (array) wp_getimagesize( (string) get_attached_file( $cover ) ) : array( 0, 0 );
		$check( 'a coordinator uploads the cover, kept as a WebP', is_int( $cover ) && Places::cover( $place ) === $cover && 'image/webp' === get_post_mime_type( $cover ) && (int) wp_get_post_parent_id( $cover ) === $place, $cover );
		$check( 'wide enough for the top of a page, and no wider than the limit', (int) $size[0] > Photos::LOGO_EDGE && (int) $size[0] <= Photos::EDGE, $size );
		$logo = Places::set_image( $place, 'logo', $file( 'logo.png', 'png', 1600, 800 ), $coordinator );
		$size = is_int( $logo ) ? (array) wp_getimagesize( (string) get_attached_file( $logo ) ) : array( 0, 0 );
		$check( 'and the logo, no larger than a logo needs to be', is_int( $logo ) && Places::logo( $place ) === $logo && $logo !== $cover && Photos::LOGO_EDGE === (int) $size[0], $size );
		$newer = Places::set_image( $place, 'cover', $file( 'cover-2.jpg', 'jpeg', 1800, 800 ), $coordinator );
		$check( 'a new cover replaces the old one, which is deleted', is_int( $newer ) && $newer !== $cover && Places::cover( $place ) === $newer && null === get_post( $cover ) && Places::logo( $place ) === $logo );
		Sponsors::save(
			$place,
			0,
			array(
				'name' => 'Test Tire Shop',
				'url'  => 'https://example.org/tires',
			),
			$coordinator
		);

		$payload = array_values( array_filter( Places::listed(), static fn( $row ) => $row['id'] === $place ) )[0] ?? array();
		$check( 'the list of Places carries the cover, the logo, and the description', ( $payload['cover'] ?? 0 ) === $newer && ( $payload['logo'] ?? 0 ) === $logo && false !== strpos( (string) ( $payload['description'] ?? '' ), 'Eleven miles' ), array_keys( $payload ) );
		$named = array_column( $payload['adopters'] ?? array(), 'team' );
		$check( 'the public is told the listed adopters by name and the unlisted one without it', array( $name, 'Wash Test Crew ' . $packout_run, '' ) === $named, $named );

		$as( 0 );
		$list = Blocks::render_places();
		$check( 'the Place list shows the Place with its cover and logo', false !== strpos( $list, esc_html( $place_name ) ) && false !== strpos( $list, 'pos-places__cover' ) && false !== strpos( $list, 'pos-places__logo' ) && false !== strpos( $list, esc_url( get_permalink( $place ) ) ) );

		// The page as the theme asks for it: the main query, inside the loop.
		$render = static function ( int $id ): string {
			global $wp_query, $wp_the_query, $post;
			$keep = array( $wp_query, $wp_the_query, $post );
			// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Renders one page for a check, then restores the globals.
			$wp_query     = new WP_Query(
				array(
					'post_type'   => Schema::PLACE,
					'p'           => $id,
					'post_status' => 'publish',
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
		$html   = $render( $place );
		$top    = substr( $html, 0, (int) strpos( $html, 'pos-place__body' ) );
		$check( 'the Place page opens with the cover', false !== strpos( $top, 'pos-place__hero--cover' ) && false !== strpos( $top, 'pos-place__cover' ), substr( $top, 0, 300 ) );
		$check( 'with the logo, the name, and the description on it', false !== strpos( $top, 'pos-place__logo' ) && false !== strpos( $top, esc_html( $place_name ) ) && false !== strpos( $top, 'Eleven miles of dirt road' ) );
		$check( 'and the sponsor beside them', false !== strpos( $top, 'pos-place__support' ) && false !== strpos( $top, 'Test Tire Shop' ) );
		$check( 'every adopter is on the page, with what the organization says of itself', false !== strpos( $html, 'pos-place__adopters' ) && 3 === substr_count( $html, 'class="pos-place__adopter"' ) && false !== strpos( $html, 'A crew that walks the rim road every month.' ) );
		$check( 'a listed organization is linked to its page', false !== strpos( $html, 'href="' . esc_url( get_permalink( $team ) ) . '"' ) && false !== strpos( $html, 'href="' . esc_url( get_permalink( $second ) ) . '"' ) && false !== strpos( $html, 'See the organization' ) );
		$check( 'an organization that is not listed is neither named nor linked', false === strpos( $html, 'Quiet Test Crew' ) && false === strpos( $html, (string) get_permalink( $hidden ) ) && false !== strpos( $html, 'An organization that is not listed' ) );
		$check( 'the page still says what adoption is not', false !== strpos( $html, 'transfers no ownership' ) );
		$check( 'and has the impact, the missions, the Spots, and the work of the Place', false !== strpos( $html, '<h2>Impact</h2>' ) && false !== strpos( $html, '<h2>Upcoming missions</h2>' ) && false !== strpos( $html, '<h2>Spots</h2>' ) && false !== strpos( $html, '<h2>Work recorded here</h2>' ) );

		$as( $coordinator );
		$check( 'a coordinator ends one adoption and the others stay', true === Places::release( $place, $second, $coordinator ) && array( $team, $hidden ) === array_column( Places::adopters( $place ), 'team' ) && array() === Places::of_team( $second ) );
		$check( 'removing the cover deletes the file and leaves a dark top', 0 === Places::set_image( $place, 'cover', null, $coordinator ) && 0 === Places::cover( $place ) && null === get_post( $newer ) );
		$as( 0 );
		$html = $render( $place );
		$check( 'the page without a cover has the plain top', false !== strpos( $html, 'pos-place__hero' ) && false === strpos( $html, 'pos-place__hero--cover' ) && false !== strpos( $html, 'Eleven miles of dirt road' ) );
		$old             = Places::save(
			0,
			array(
				'name'   => 'Test Old Place ' . $packout_run,
				'listed' => true,
			),
			$coordinator
		);
		$packout_posts[] = $old;
		update_post_meta( $old, '_pos_team', $team );
		update_post_meta( $old, '_pos_adopted_on', '2026-02-02' );
		$check( 'a Place saved when a Place had one adopter still has it', array( $team ) === array_column( Places::adopters( $old ), 'team' ) && '2026-02-02' === Places::adopters( $old )[0]['since'] );
	} else {
		$skip( 'Places are kept on packout.spot.' );
	}

	WP_CLI::log( 'My account: a page for each part' );
	$root     = $page( 'Test account', '<!-- wp:packout/account /-->' );
	$personal = $page( 'Test personal info', '<!-- wp:group --><div class="wp-block-group"><!-- wp:packout/account {"view":"personal"} /--></div><!-- /wp:group -->', $root );
	$mine     = $page( 'Test my impact', '<!-- wp:packout/account {"view":"impact"} /-->', $root );
	$other    = $page( 'Test note', '<!-- wp:paragraph --><p>Nothing about an account.</p><!-- /wp:paragraph -->', $root );
	$outside  = $page( 'Test elsewhere', '<!-- wp:packout/account {"view":"email"} /-->' );
	$teams    = $page( 'Test organizations', '<!-- wp:packout/workspace /-->' );
	$enter    = $page( 'Test sign in', '<!-- wp:packout/signin /-->' );
	$join     = $page( 'Test sign up', '<!-- wp:packout/signup /-->' );
	Settings::save(
		array(
			'account_page'   => $root,
			'workspace_page' => $teams,
			'signin_page'    => $enter,
			'signup_page'    => $join,
		)
	);
	Profile::forget_pages();
	$found = Profile::pages();
	$check(
		'the parts of My account are found among the pages under it, by their block',
		array(
			'personal' => $personal,
			'impact'   => $mine,
		) === $found || array(
			'impact'   => $mine,
			'personal' => $personal,
		) === $found,
		$found
	);
	$check( 'a page elsewhere and a page without the block are not parts', ! in_array( $outside, $found, true ) && ! in_array( $other, $found, true ) );
	$check( 'a part with a page is reached at it', get_permalink( $personal ) === Profile::url( array(), 'personal' ) && get_permalink( $mine ) === Profile::url( array(), 'impact' ) );
	$check( 'a part without one is reached on the My account page', 0 === strpos( Profile::url( array(), 'email' ), (string) get_permalink( $root ) ) && false !== strpos( Profile::url( array(), 'email' ), 'view=email' ), Profile::url( array(), 'email' ) );
	$check( 'what a form says when it is done travels with the address', false !== strpos( Profile::url( array( 'done' => 'about' ), 'personal' ), 'done=about' ) && 0 === strpos( Profile::url( array( 'done' => 'about' ), 'personal' ), (string) get_permalink( $personal ) ) );
	$check( 'the parts have names', array( '', 'personal', 'impact', 'contributions', 'email', 'signin' ) === array_keys( Profile::views() ) && 'impact' === Profile::view( 'impact' ) && 'contributions' === Profile::view( 'contributions' ) && '' === Profile::view( 'everything' ) && '' === Profile::view( array( 'impact' ) ) );

	$as( $ben );
	$parts = array();
	foreach ( array_keys( Profile::views() ) as $view ) {
		$parts[ $view ] = Profile::render( array( 'view' => $view ) );
	}
	$has = static fn( string $view, string $text ): bool => false !== strpos( $parts[ $view ], $text );
	$check( 'the overview has a card for each part and no forms of its own', $has( '', 'pos-profile__cards' ) && $has( '', 'pos-profile--overview' ) && 6 === substr_count( $parts[''], 'pos-profile__card"' ) && ! $has( '', 'name="packout_profile"' ), substr_count( $parts[''], 'pos-profile__card"' ) );
	$check( 'each card links to its part', $has( '', 'href="' . esc_url( get_permalink( $personal ) ) . '"' ) && $has( '', 'href="' . esc_url( get_permalink( $mine ) ) . '"' ) && $has( '', esc_url( Profile::url( array(), 'signin' ) ) ) && $has( '', 'href="' . esc_url( get_permalink( $teams ) ) . '"' ) );
	$check( 'Personal info has the two forms about the person', $has( 'personal', 'value="about"' ) && $has( 'personal', 'value="contact"' ) && ! $has( 'personal', 'value="mail"' ) && ! $has( 'personal', 'value="email"' ) );
	$check( 'My impact has what the person has done and no forms', $has( 'impact', 'pos-profile--impact' ) && ! $has( 'impact', 'name="packout_profile"' ) );
	$check( 'My contributions lists map features', $has( 'contributions', 'pos-profile--contributions' ) && $has( 'contributions', 'pos-account-features' ) && $has( 'contributions', 'Add a map feature' ) );
	$check( 'Email preferences has the email choices only', $has( 'email', 'value="mail"' ) && ! $has( 'email', 'value="about"' ) && ! $has( 'email', 'value="email"' ) );
	$check( 'Sign-in details has the email address and nothing else to change', $has( 'signin', 'value="email"' ) && ! $has( 'signin', 'value="about"' ) && ! $has( 'signin', 'value="mail"' ) );
	$order = array( 'Overview', 'Personal info', 'My impact', 'My contributions', 'My organizations', 'Email preferences', 'Sign-in details', 'Sign out' );
	$nav   = preg_match( '#<nav class="[^"]*pos-profile__nav.*?</nav>#s', $parts['impact'], $got ) ? $got[0] : '';
	preg_match_all( '#<a [^>]*>(.*?)</a>#s', $nav, $links );
	$check( 'every part lists the others, with My organizations among them and Sign out last', array_map( 'wp_strip_all_tags', $links[1] ) === $order, $links[1] );
	$check( 'and marks the part being read', 1 === preg_match( '#aria-current="page"[^>]*>\s*My impact#', $nav ) || 1 === preg_match( '#<a [^>]*aria-current="page"[^>]*>My impact</a>#', $nav ), $nav );
	$check( 'a form posts to the page of its part', $has( 'personal', 'action="' . esc_url( get_permalink( $personal ) ) ) );
	$as( 0 );
	$check( 'a visitor is asked to sign in on every part', false !== strpos( Profile::render( array( 'view' => 'impact' ) ), esc_url( Sign_In::url() ) ) && false === strpos( Profile::render( array( 'view' => 'impact' ) ), 'pos-profile__nav' ) );

	wp_update_post(
		array(
			'ID'           => $other,
			'post_content' => '<!-- wp:packout/account {"view":"signin"} /-->',
		)
	);
	$check( 'a page that gains the block becomes a part', ( Profile::pages()['signin'] ?? 0 ) === $other && get_permalink( $other ) === Profile::url( array(), 'signin' ) );
	wp_trash_post( $mine );
	$check( 'a page in the trash stops being one', ! isset( Profile::pages()['impact'] ) && false !== strpos( Profile::url( array(), 'impact' ), 'view=impact' ) );
	wp_untrash_post( $mine );
	wp_publish_post( $mine );
	$check( 'and is one again when it is restored', ( Profile::pages()['impact'] ?? 0 ) === $mine );

	WP_CLI::log( 'The menu' );
	$menu  = static fn(): array => array(
		$item( 9001, $teams, 'For organizations page' ),
		$item( 9002, $root, 'My account' ),
		$item( 9003, $personal, 'Personal info', 9002 ),
		$item( 9004, $mine, 'My impact', 9002 ),
		$item( 9005, $teams, 'My organizations', 9002 ),
		$item( 9006, $enter, 'Sign in', 9002 ),
		$item( 9007, $join, 'Sign up' ),
		$item( 9008, $enter, 'Sign in' ),
	);
	$as( 0 );
	$shown = array_values( Sign_In::menu( $menu() ) );
	$check( 'a visitor is offered Sign up and Sign in', array( 'Sign up', 'Sign in' ) === wp_list_pluck( $shown, 'title' ), wp_list_pluck( $shown, 'title' ) );
	$check( 'and none of My account, nor anything under it', array() === array_intersect( array( 9002, 9003, 9004, 9005, 9006 ), wp_list_pluck( $shown, 'ID' ) ) );
	$check( 'Sign in still leads to the sign-in page for them', get_permalink( $enter ) === $shown[1]->url );
	$as( $ben );
	$shown  = array_values( Sign_In::menu( $menu() ) );
	$titles = wp_list_pluck( $shown, 'title' );
	$check( 'a signed-in person has My account where Sign in was', in_array( 'My account', $titles, true ) && ! in_array( 'Sign in', $titles, true ) && ! in_array( 'Sign up', $titles, true ), $titles );
	$under = array_values( array_filter( $shown, static fn( $each ) => 9002 === (int) $each->menu_item_parent ) );
	$check( 'with its parts under it and Sign out last', array( 'Personal info', 'My impact', 'My organizations', 'Sign out' ) === wp_list_pluck( $under, 'title' ), wp_list_pluck( $under, 'title' ) );
	$out = end( $under );
	$check( 'Sign out signs them out', false !== strpos( $out->url, 'action=logout' ) && false !== strpos( $out->url, '_wpnonce=' ) && ! in_array( 'current-menu-item', (array) $out->classes, true ) );
	$check( 'exactly one Sign out is left', 1 === count( array_keys( $titles, 'Sign out', true ) ) );
	$flat  = array( $item( 9101, $root, 'My account' ), $item( 9102, $join, 'Sign up' ), $item( 9103, $enter, 'Sign in' ) );
	$shown = array_values( Sign_In::menu( $flat ) );
	$check( 'a menu with nothing under My account keeps its Sign out beside it', array( 'My account', 'Sign out' ) === wp_list_pluck( $shown, 'title' ), wp_list_pluck( $shown, 'title' ) );
	$check( 'something that is not a list of items is left alone', 'menu' === Sign_In::menu( 'menu' ) );

	if ( $hub ) {
		WP_CLI::log( 'The signed-in cookie' );
		$check( 'a cookie sent with no path belongs to the directory of its address', '/' === Session::default_path( 'https://example.org/sign-in/' ) || '/sign-in' === Session::default_path( 'https://example.org/sign-in/' ), Session::default_path( 'https://example.org/sign-in/' ) );
		$check( 'the directory of a page is the page', '/sign-in' === Session::default_path( '/sign-in/?redirect=x' ) && '/my-account/personal-info' === Session::default_path( '/my-account/personal-info/' ) );
		$check( 'of a file, the directory it is in', '/' === Session::default_path( '/wp-login.php' ) && '/wp-admin' === Session::default_path( '/wp-admin/admin-ajax.php' ) );
		$check( 'and of the front page, the site', '/' === Session::default_path( '/' ) && '/' === Session::default_path( '' ) && '/' === Session::default_path( 'https://example.org' ) );
		$check( 'the site\'s own path starts and ends with a slash', str_starts_with( Session::path(), '/' ) && str_ends_with( Session::path(), '/' ) && 0 === strpos( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), Session::path() ) );
		$check( 'the class works only where WordPress has no path for the cookie', Session::sound() === ( defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && str_starts_with( COOKIEPATH, '/' ) ) );
		$hooked = false !== has_action( 'wp_logout', array( Session::class, 'end' ) ) && false !== has_filter( 'send_auth_cookies', array( Session::class, 'send' ) ) && false !== has_action( 'init', array( Session::class, 'mend' ) );
		$check( 'and is hooked exactly when it is needed', Session::sound() !== $hooked, array( Session::sound(), $hooked ) );
	} else {
		$skip( 'The signed-in cookie’s path is a matter of the hub’s host.' );
	}

	WP_CLI::log( 'My organizations: lists' );
	$columns = array(
		'role'   => array( 'label' => 'Place in the organization' ),
		'people' => array(
			'label' => 'People',
			'type'  => 'integer',
		),
		'logo'   => array(
			'label' => 'Logo',
			'type'  => 'media',
		),
	);
	$rows    = array(
		array(
			'id'     => 'a',
			'title'  => 'North <Wash> & Co',
			'url'    => Workspace_Lists::link( 'thing-1' ),
			'role'   => 'Lead',
			'people' => 4,
			'logo'   => '',
		),
		array(
			'id'     => 'b',
			'title'  => 'South Wash',
			'url'    => 'https://example.org/south',
			'role'   => 'Member',
			'people' => 2,
			'logo'   => 'https://example.org/logo.webp',
		),
	);
	$check( 'a list with nothing in it says so and draws no view', '<p class="pos-view__empty">No crews yet.</p>' === Workspace_Lists::view( 'crews', $columns, array(), array( 'empty' => 'No crews yet.' ) ) );
	$view  = Workspace_Lists::view(
		'crews',
		$columns,
		$rows,
		array(
			'title'       => 'Crew',
			'layout'      => 'grid',
			'media'       => 'logo',
			'description' => 'role',
		)
	);
	$found = preg_match( '/data-config="([^"]*)"/', $view, $got ) ? json_decode( html_entity_decode( $got[1], ENT_QUOTES ), true ) : null;
	$setup = preg_match( '/data-pos-setup="([^"]*)"/', $view, $got ) ? json_decode( html_entity_decode( $got[1], ENT_QUOTES ), true ) : null;
	$check( 'a list carries the configuration of a Data View', is_array( $found ) && 'static' === ( $found['source']['type'] ?? '' ) && 2 === count( $found['source']['rows'] ?? array() ) && false !== strpos( $view, 'data-pos-view="crews"' ), $found );
	$check( 'with a field for the title and one for each column', array( 'title', 'role', 'people', 'logo' ) === array_column( $found['fields'] ?? array(), 'id' ) && 'Crew' === ( $found['fields'][0]['label'] ?? '' ), array_column( $found['fields'] ?? array(), 'id' ) );
	$check( 'the picture and the line under the title are told apart from the rest', 'logo' === ( $found['view']['mediaField'] ?? '' ) && 'role' === ( $found['view']['descriptionField'] ?? '' ) && 'title' === ( $found['view']['titleField'] ?? '' ) );
	$check( 'it starts in the layout it was given and offers a table, cards, and a list', 'grid' === ( $setup['layout'] ?? '' ) && array( 'table', 'grid', 'list' ) === array_keys( $setup['labels'] ?? array() ) && 'Cards' === ( $setup['labels']['grid'] ?? '' ), $setup );
	$check( 'the table has a column for everything but the picture', array( 'title', 'role', 'people' ) === ( $setup['table'] ?? null ), $setup['table'] ?? null );
	$check( 'the view\'s own layout switch is off, and a title is a link', false === ( $found['dataviews']['picker'] ?? null ) && false === ( $found['dataviews']['itemsClickable'] ?? null ) );
	$check( 'a short list has no search box', false === ( $found['dataviews']['search'] ?? null ) );
	$check( 'the rows are on the page as plain links before any script runs', false !== strpos( $view, 'pos-view__plain' ) && false !== strpos( $view, 'href="#pos-pane-thing-1"' ) && false !== strpos( $view, 'href="https://example.org/south"' ) && false !== strpos( $view, 'North &lt;Wash&gt; &amp; Co' ) && false === strpos( $view, '<Wash>' ) );
	$check( 'a list that asks for no picture says so', false !== strpos( Workspace_Lists::view( 'plain', array( 'role' => array( 'label' => 'Role' ) ), $rows ), 'pos-view--no-media' ) && false === strpos( $view, 'pos-view--no-media' ) );
	$many = array();
	for ( $n = 1; $n <= 6; $n++ ) {
		$many[] = array(
			'id'    => (string) $n,
			'title' => 'Crew ' . $n,
			'url'   => '',
			'role'  => 'Member',
		);
	}
	$long = Workspace_Lists::view( 'many', array( 'role' => array( 'label' => 'Role' ) ), $many );
	$long = preg_match( '/data-config="([^"]*)"/', $long, $got ) ? json_decode( html_entity_decode( $got[1], ENT_QUOTES ), true ) : null;
	$check( 'a longer list has one', true === ( $long['dataviews']['search'] ?? null ) && 'table' === ( $long['view']['type'] ?? '' ) );

	WP_CLI::log( 'My organizations: panes' );
	$check( 'with no panes there is nothing to print', '' === Workspace_Lists::panes() );
	Workspace_Lists::pane( 'thing-1', 'The <first> thing', '<p>Body one</p>' );
	Workspace_Lists::pane( 'thing-2', 'Second', '<p>Body two</p>' );
	Workspace_Lists::reopen( 'thing-2' );
	$opener = Workspace_Lists::opener( 'thing-1', 'The first thing', 'Open it' );
	$panes  = Workspace_Lists::panes();
	$check( 'a button that opens a pane is a link to it', false !== strpos( $opener, 'href="#pos-pane-thing-1"' ) && false !== strpos( $opener, 'data-pos-open="thing-1"' ) && false !== strpos( $opener, '>Open it</a>' ) );
	$check( 'each pane is a section with a heading of its own', 2 === substr_count( $panes, 'class="packout-drawer__pane pos-ws__pane"' ) && false !== strpos( $panes, 'id="pos-pane-thing-1"' ) && false !== strpos( $panes, 'data-pane="thing-2"' ) && false !== strpos( $panes, 'The &lt;first&gt; thing</h3><p>Body one</p>' ) );
	$check( 'the pane to open again is named', false !== strpos( $panes, 'data-pos-reopen="thing-2"' ) );
	$check( 'the drawer comes with the panes, closed', false !== strpos( $panes, '<div class="packout-drawer pos-drawer" hidden>' ) && false !== strpos( $panes, 'pos-ws__panes--drawer' ) && 2 === substr_count( $panes, 'data-packout-close' ) );
	$check( 'and names nothing of Toolkit\'s', false === stripos( $panes, 'vectra' ) );
	$check( 'the panes are printed once', '' === Workspace_Lists::panes() );

	WP_CLI::log( 'My organizations: the tabs of an organization' );
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
			'name' => 'Test Outfitters',
			'url'  => 'https://example.org/outfitters',
		),
		$ana
	);
	$sheet = Resources::add(
		$team,
		array(
			'title'    => 'Test waiver',
			'url'      => 'https://example.org/waiver',
			'audience' => 'members',
		),
		$ana
	);
	$sent  = Messages::send( $team, 'team', 'Saturday at the turnout', 'Bring gloves and water.', $ana );
	$note  = is_array( $sent ) ? (int) $sent['id'] : 0;
	$tab   = static function ( string $view, int $who ) use ( $team, $as ): string {
		$as( $who );
		$_GET = array(
			'team' => (string) $team,
			'view' => $view,
		);
		$html = Workspace::render( array() );
		$_GET = array();
		return $html;
	};
	$html  = $tab( 'members', $ana );
	$check( 'Members has a button that opens the invitation in a pane', false !== strpos( $html, 'data-pos-open="invite"' ) && false !== strpos( $html, 'id="pos-pane-invite"' ) && 1 === preg_match( '#id="pos-pane-invite".*?value="invite"#s', $html ) );
	$check( 'the invitation is a primary action, like planning a mission', 1 === preg_match( '#class="pos-ws__button pos-ws__button--primary"[^>]*data-pos-open="invite"#', $html ) );
	$check( 'the roster is a Data View', false !== strpos( $html, 'data-pos-view="members"' ) && false !== strpos( $html, 'href="#pos-pane-member-' . $ben . '"' ) );
	$check( 'each person has a pane, with what the lead can do', false !== strpos( $html, 'id="pos-pane-member-' . $ben . '"' ) && 1 === preg_match( '#id="pos-pane-member-' . $ben . '".*?value="colead".*?value="remove_member"#s', $html ) );
	$html = $tab( 'members', $cal );
	$check( 'a member sees the roster and is offered no invitation', false !== strpos( $html, 'data-pos-view="members"' ) && false === strpos( $html, 'data-pos-open="invite"' ) && false === strpos( $html, 'value="invite"' ) && false === strpos( $html, 'value="remove_member"' ) );
	$check( 'nor anyone\'s email address', false === strpos( $html, get_userdata( $ben )->user_email ) );

	$html = $tab( 'subteams', $ana );
	$check( 'Teams has a button to add one, and a Data View', false !== strpos( $html, 'data-pos-open="subteam-new"' ) && false !== strpos( $html, 'data-pos-view="subteams"' ) );
	$check( 'each team has a pane with its people and its forms', 1 === preg_match( '#id="pos-pane-subteam-' . $north . '".*?value="subteam_member".*?value="subteam_save"#s', $html ) && false !== strpos( $html, 'id="pos-pane-subteam-' . $south . '"' ) );
	$html = $tab( 'subteams', $ben );
	$check( 'a team lead gets the forms of their own team and cannot add one', false !== strpos( $html, 'id="pos-pane-subteam-' . $north . '"' ) && false === strpos( $html, 'data-pos-open="subteam-new"' ) && false === strpos( $html, 'value="subteam_save"' ) && false === strpos( $html, 'name="subteam" value="' . $south . '"' ) );

	$html = $tab( 'resources', $ana );
	$check( 'Resources has a button to add one, a Data View, and a pane for each', false !== strpos( $html, 'data-pos-open="resource-new"' ) && false !== strpos( $html, 'data-pos-view="resources"' ) && 1 === preg_match( '#id="pos-pane-resource-' . $sheet . '".*?https://example\.org/waiver.*?value="resource_remove"#s', $html ) );
	$html = $tab( 'resources', $cal );
	$check( 'a member opens a resource and cannot remove it', false !== strpos( $html, 'id="pos-pane-resource-' . $sheet . '"' ) && false === strpos( $html, 'value="resource_remove"' ) && false === strpos( $html, 'data-pos-open="resource-new"' ) );

	$html = $tab( 'sponsors', $ana );
	$check( 'Sponsors has a button to add one, a Data View, and a pane for each', false !== strpos( $html, 'data-pos-open="sponsor-new"' ) && false !== strpos( $html, 'data-pos-view="sponsors"' ) && 1 === preg_match( '#id="pos-pane-sponsor-' . $brand . '".*?value="sponsor_save".*?value="sponsor_remove"#s', $html ) );

	$html = $tab( 'messages', $ana );
	$check( 'Messages has a button to write one, in a pane', false !== strpos( $html, 'data-pos-open="message-new"' ) && 1 === preg_match( '#id="pos-pane-message-new".*?name="body"#s', $html ) );
	$check( 'and each message opens in a pane with its text', false !== strpos( $html, 'data-pos-view="messages"' ) && $note > 0 && 1 === preg_match( '#id="pos-pane-message-' . $note . '".*?Bring gloves and water\.#s', $html ) );
	$html = $tab( 'messages', $cal );
	$check( 'a member reads the message and writes none', false !== strpos( $html, 'id="pos-pane-message-' . $note . '"' ) && false === strpos( $html, 'data-pos-open="message-new"' ) );

	foreach ( array( 'missions', 'campaigns' ) as $view ) {
		$html = $tab( $view, $ana );
		$check( 'the ' . $view . ' tab says so when it has nothing, with no empty view', strpos( $html, 'pos-view__empty' ) !== false && false === strpos( $html, 'data-pos-view="' . $view . '"' ), substr( wp_strip_all_tags( $html ), 0, 200 ) );
	}
	$html = $tab( 'impact', $ana );
	$check( 'the impact tab has the figures and, with no records, no view', false !== strpos( $html, 'No records yet.' ) && false === strpos( $html, 'data-pos-view="records"' ) );
	$as( $ana );
	$_GET = array();
	$html = Workspace::render( array() );
	$check( 'My organizations lists a person\'s organizations as cards, and starting an organization is a pane', false !== strpos( $html, 'data-pos-view="teams"' ) && false !== strpos( $html, '&quot;layout&quot;:&quot;grid&quot;' ) && false !== strpos( $html, 'data-pos-open="team-new"' ) && false !== strpos( $html, 'id="pos-pane-team-new"' ) );

	WP_CLI::log( 'My organizations: a form that is refused' );
	$as( $ana );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = array(
		'packout_workspace' => 'invite',
		'team'              => (string) $team,
		'email'             => get_userdata( $ben )->user_email,
		'_posnonce'         => wp_create_nonce( 'packout_workspace' ),
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This stands in for a request. The handler checks the nonce.
	$_REQUEST = $_POST;
	Workspace::handle();
	$html                      = $tab( 'members', $ana );
	$_POST                     = array();
	$_REQUEST                  = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$check( 'the invitation to someone already in the organization is refused', '' !== Workspace::problem(), Workspace::problem() );
	$check( 'the page comes back with the invitation pane open', false !== strpos( $html, 'data-pos-reopen="invite"' ) );
	$check( 'the pane says what was wrong, and keeps what was typed', 1 === preg_match( '#id="pos-pane-invite"[^>]*>\s*<h3[^>]*>[^<]*</h3>\s*<div[^>]*(notice|alert|error)#s', $html ) && false !== strpos( $html, 'value="' . esc_attr( get_userdata( $ben )->user_email ) . '"' ), substr( (string) strstr( $html, 'id="pos-pane-invite"' ), 0, 400 ) );
} catch ( \Throwable $error ) {
	$packout_failures[] = 'The run stopped: ' . $error->getMessage();
	WP_CLI::log( '  FAIL  ' . $error->getMessage() . ' at ' . basename( $error->getFile() ) . ':' . $error->getLine() );
} finally {
	wp_set_current_user( 0 );
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
	Profile::forget_pages();
	Teams::flush();
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
