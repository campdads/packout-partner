<?php
/**
 * Spot photos.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photos are WordPress attachments whose parent is the Spot.
 *
 * A person sends whatever their phone or computer makes: JPEG, PNG, HEIC,
 * AVIF, WebP, GIF, TIFF, or BMP. The site keeps one WebP copy, no larger
 * than a set size, turned the right way up, with the camera's notes removed.
 * Where the photo was taken is read first and kept on the record, not in the
 * file. The file as it was sent is not kept.
 *
 * Trashing a Spot leaves the files in the media library until a coordinator
 * deletes them there.
 */
class Photos {

	/**
	 * Most photos one Spot accepts.
	 */
	public const LIMIT = 12;

	/**
	 * Records known to have no photo while a long list is built, by ID.
	 *
	 * @var array<int, bool>
	 */
	private static array $bare = array();

	/**
	 * Longest side of a stored photo or cover image, in pixels.
	 */
	public const EDGE = 2560;

	/**
	 * Longest side of a stored logo, in pixels.
	 */
	public const LOGO_EDGE = 1200;

	/**
	 * Largest image a person may send, before it is converted.
	 */
	public const IMAGE_BYTES = 25 * MB_IN_BYTES;

	/**
	 * Largest image the site will open: 120 megapixels.
	 */
	private const PIXELS = 120000000;

	/**
	 * Image types by what the file holds, and the name each goes by in a message.
	 */
	private const TYPES = array(
		'image/jpeg'          => 'JPEG',
		'image/png'           => 'PNG',
		'image/gif'           => 'GIF',
		'image/webp'          => 'WebP',
		'image/avif'          => 'AVIF',
		'image/heic'          => 'HEIC',
		'image/heif'          => 'HEIC',
		'image/heic-sequence' => 'HEIC',
		'image/heif-sequence' => 'HEIC',
		'image/tiff'          => 'TIFF',
		'image/bmp'           => 'BMP',
		'image/x-ms-bmp'      => 'BMP',
	);

	/**
	 * Largest resource document a team may upload.
	 */
	private const FILE_BYTES = 8 * MB_IN_BYTES;

	/**
	 * Attachment meta: a PNG copy of a logo, for email programs that cannot show WebP.
	 */
	private const MAIL_COPY = '_pos_mail_copy';

	/**
	 * Remove the email copy of a logo with the logo.
	 */
	public static function register(): void {
		add_action( 'delete_attachment', array( self::class, 'forget_mail_copy' ) );
	}

	/**
	 * The `accept` value for a photo or logo field: any image the device offers.
	 */
	public static function accept(): string {
		return 'image/*,.heic,.heif,.avif';
	}

	/**
	 * The largest image a person may send, in megabytes, given what the server takes.
	 */
	public static function limit_mb(): int {
		$server = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : self::IMAGE_BYTES;
		return max( 1, (int) floor( min( self::IMAGE_BYTES, $server > 0 ? $server : self::IMAGE_BYTES ) / MB_IN_BYTES ) );
	}

	/**
	 * What a photo field says under itself.
	 */
	public static function hint(): string {
		/* translators: %d: number of megabytes. */
		return sprintf( __( 'Any photo your phone or computer makes, up to %d MB. It is stored as a smaller WebP copy.', 'packout-core' ), self::limit_mb() );
	}

	/**
	 * The type of an image, read from the file's contents, or an empty string when it is not one this site takes.
	 *
	 * @param string $path File path.
	 */
	public static function type_of( string $path ): string {
		$type = '' !== $path && is_readable( $path ) ? (string) wp_get_image_mime( $path ) : '';
		return isset( self::TYPES[ $type ] ) ? $type : '';
	}

	/**
	 * Make the copy the site keeps: a WebP no longer than $edge on its long side.
	 *
	 * The first frame of an animation is kept. The picture is turned the way
	 * its camera said it should be, then the camera's notes are removed. The
	 * color profile stays, so the picture looks the same.
	 *
	 * @param string $path    The image as it was sent.
	 * @param int    $edge    Longest side, in pixels.
	 * @param int    $quality WebP quality, 1 to 100.
	 * @return array{file: string, type: string, gps: array{0: float, 1: float}|null}|\WP_Error A temporary file, its type, and where the photo was taken.
	 */
	public static function convert( string $path, int $edge = self::EDGE, int $quality = 82 ) {
		$type = self::type_of( $path );
		if ( '' === $type ) {
			return new \WP_Error( 'packout_photo_type', __( 'That file is not a photo this site can read. Send a JPEG, PNG, HEIC, AVIF, WebP, GIF, TIFF, or BMP image.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( (int) filesize( $path ) > self::IMAGE_BYTES ) {
			/* translators: %d: number of megabytes. */
			return new \WP_Error( 'packout_photo_size', sprintf( __( 'That image is larger than %d MB.', 'packout-core' ), (int) ( self::IMAGE_BYTES / MB_IN_BYTES ) ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$edge    = max( 16, $edge );
		$quality = max( 1, min( 100, $quality ) );
		$gps     = self::gps( $path );
		$made    = self::with_imagick( $path, $edge, $quality );
		if ( null === $made ) {
			$made = self::with_editor( $path, $edge, $quality );
		}
		if ( null === $made ) {
			$made = self::with_gd( $path, $edge, $quality );
		}
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		if ( null === $made ) {
			/* translators: %s: kind of image, for example HEIC. */
			return new \WP_Error( 'packout_photo_unreadable', sprintf( __( 'This site could not open that %s image. Send it as a JPEG or PNG.', 'packout-core' ), self::TYPES[ $type ] ), array( 'status' => 400 ) );
		}
		return array(
			'file' => $made['file'],
			'type' => $made['type'],
			'gps'  => $gps ?? $made['gps'],
		);
	}

	/**
	 * Convert with ImageMagick, which reads every type this site takes.
	 *
	 * @param string $path    The image as it was sent.
	 * @param int    $edge    Longest side, in pixels.
	 * @param int    $quality WebP quality.
	 * @return array{file: string, type: string, gps: array{0: float, 1: float}|null}|\WP_Error|null Null when ImageMagick cannot do it here.
	 */
	private static function with_imagick( string $path, int $edge, int $quality ) {
		if ( ! class_exists( '\Imagick' ) || ! \Imagick::queryFormats( 'WEBP' ) ) {
			return null;
		}
		$out = '';
		try {
			// The size is read before the picture is, so a file that claims an enormous size is never opened.
			$image = new \Imagick();
			$image->pingImage( $path );
			$wide = (int) $image->getImageWidth();
			$high = (int) $image->getImageHeight();
			$image->clear();
			if ( $wide < 1 || $high < 1 ) {
				return null;
			}
			if ( $wide * $high > self::PIXELS ) {
				return self::too_many_pixels();
			}

			$image = new \Imagick();
			$image->readImage( $path . '[0]' );
			$gps = self::gps_of( $image );

			if ( method_exists( $image, 'autoOrient' ) ) {
				$image->autoOrient();
			}
			$image->setImageOrientation( \Imagick::ORIENTATION_TOPLEFT );
			if ( \Imagick::COLORSPACE_CMYK === $image->getImageColorspace() ) {
				$image->transformImageColorspace( \Imagick::COLORSPACE_SRGB );
			}

			$profile = '';
			try {
				$profile = (string) $image->getImageProfile( 'icc' );
			} catch ( \Exception $e ) {
				$profile = '';
			}
			$image->stripImage();
			if ( '' !== $profile ) {
				$image->profileImage( 'icc', $profile );
			}

			$wide = (int) $image->getImageWidth();
			$high = (int) $image->getImageHeight();
			if ( max( $wide, $high ) > $edge ) {
				$scale = $edge / max( $wide, $high );
				$image->resizeImage( max( 1, (int) round( $wide * $scale ) ), max( 1, (int) round( $high * $scale ) ), \Imagick::FILTER_LANCZOS, 1 );
			}
			$image->setImageDepth( 8 );
			$image->setImageFormat( 'webp' );
			$image->setImageCompressionQuality( $quality );
			$image->setOption( 'webp:method', '5' );

			$out = wp_tempnam( 'pos-photo' );
			$image->writeImage( 'webp:' . $out );
			$image->clear();
			if ( 'image/webp' !== wp_get_image_mime( $out ) ) {
				wp_delete_file( $out );
				return null;
			}
			return array(
				'file' => $out,
				'type' => 'image/webp',
				'gps'  => $gps,
			);
		} catch ( \Exception $e ) {
			if ( '' !== $out ) {
				wp_delete_file( $out );
			}
			return null;
		}//end try
	}

	/**
	 * Convert with the image editor WordPress chooses, for a server whose ImageMagick cannot.
	 *
	 * @param string $path    The image as it was sent.
	 * @param int    $edge    Longest side, in pixels.
	 * @param int    $quality WebP quality.
	 * @return array{file: string, type: string, gps: null}|\WP_Error|null Null when no editor here reads the file.
	 */
	private static function with_editor( string $path, int $edge, int $quality ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A damaged header raises a warning. The file is refused below.
		$size = @getimagesize( $path );
		if ( is_array( $size ) && (int) $size[0] * (int) $size[1] > self::PIXELS ) {
			return self::too_many_pixels();
		}
		// The type is named: a file a browser sent has no extension for WordPress to go by.
		$editor = wp_get_image_editor( $path, array( 'mime_type' => self::type_of( $path ) ) );
		if ( is_wp_error( $editor ) ) {
			return null;
		}
		$editor->maybe_exif_rotate();
		$now = $editor->get_size();
		if ( max( (int) $now['width'], (int) $now['height'] ) > $edge ) {
			$editor->resize( $edge, $edge, false );
		}
		$editor->set_quality( $quality );
		$type  = $editor::supports_mime_type( 'image/webp' ) ? 'image/webp' : 'image/jpeg';
		$out   = wp_tempnam( 'pos-photo' );
		$saved = $editor->save( $out, $type );
		// The editor names the file for its type, so the placeholder is not the file it wrote.
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_readable( $saved['path'] ) ) {
			wp_delete_file( $out );
			return null;
		}
		if ( $saved['path'] !== $out ) {
			wp_delete_file( $out );
		}
		// An editor can report a file it did not manage to write: GD writes no WebP from a picture with a palette.
		$kept = (string) ( $saved['mime-type'] ?? $type );
		if ( (int) filesize( $saved['path'] ) < 1 || wp_get_image_mime( $saved['path'] ) !== $kept ) {
			wp_delete_file( $saved['path'] );
			return null;
		}
		return array(
			'file' => (string) $saved['path'],
			'type' => $kept,
			'gps'  => null,
		);
	}

	/**
	 * Convert with GD alone, for the pictures the WordPress editor does not take: a BMP, or a GIF with a palette.
	 *
	 * @param string $path    The image as it was sent.
	 * @param int    $edge    Longest side, in pixels.
	 * @param int    $quality WebP quality.
	 * @return array{file: string, type: string, gps: null}|\WP_Error|null Null when GD cannot read the file or write a WebP.
	 */
	private static function with_gd( string $path, int $edge, int $quality ) {
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagewebp' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A damaged header raises a warning. The file is refused below.
		$size = @getimagesize( $path );
		if ( ! is_array( $size ) || (int) $size[0] < 1 || (int) $size[1] < 1 ) {
			return null;
		}
		if ( (int) $size[0] * (int) $size[1] > self::PIXELS ) {
			return self::too_many_pixels();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- A local file the form sent. GD warns about one it cannot read.
		$image = @imagecreatefromstring( (string) file_get_contents( $path ) );
		if ( false === $image ) {
			return null;
		}
		imagepalettetotruecolor( $image );
		$wide = imagesx( $image );
		$high = imagesy( $image );
		if ( max( $wide, $high ) > $edge ) {
			$scale = $edge / max( $wide, $high );
			$small = imagescale( $image, max( 1, (int) round( $wide * $scale ) ), max( 1, (int) round( $high * $scale ) ), IMG_BICUBIC );
			if ( false === $small ) {
				return null;
			}
			$image = $small;
		}
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		$out = wp_tempnam( 'pos-photo' );
		if ( ! imagewebp( $image, $out, $quality ) || 'image/webp' !== wp_get_image_mime( $out ) ) {
			wp_delete_file( $out );
			return null;
		}
		return array(
			'file' => $out,
			'type' => 'image/webp',
			'gps'  => null,
		);
	}

	/**
	 * The refusal for an image with more pixels than the site will open.
	 */
	private static function too_many_pixels(): \WP_Error {
		return new \WP_Error( 'packout_photo_pixels', __( 'That image has more pixels than this site can open. Send a smaller copy.', 'packout-core' ), array( 'status' => 400 ) );
	}

	/**
	 * Put a converted image in uploads, through the WordPress upload handler, so the site's media rules apply.
	 *
	 * @param string $made A temporary file from convert().
	 * @param string $type Its type.
	 * @param string $name The name the file was sent with.
	 * @return array{file: string, url: string, type: string}|\WP_Error
	 */
	private static function keep( string $made, string $type, string $name ) {
		$extension = 'image/webp' === $type ? 'webp' : 'jpg';
		$base      = sanitize_file_name( (string) pathinfo( $name, PATHINFO_FILENAME ) );
		$file      = array(
			'name'     => ( '' !== $base ? $base : 'photo' ) . '.' . $extension,
			'type'     => $type,
			'tmp_name' => $made,
			'error'    => UPLOAD_ERR_OK,
			'size'     => (int) filesize( $made ),
		);
		$moved     = wp_handle_sideload(
			$file,
			array(
				'test_form' => false,
				// A network's list of file types need not name WebP. This file was made here.
				'mimes'     => array( $extension => $type ),
			)
		);
		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			wp_delete_file( $made );
			return new \WP_Error( 'packout_photo_upload', is_array( $moved ) ? (string) $moved['error'] : __( 'The photo could not be saved.', 'packout-core' ), array( 'status' => 400 ) );
		}
		return $moved;
	}

	/**
	 * Find out in one query which of these records have no photo, so that a
	 * long list does not ask once for each. Called with none, it forgets.
	 *
	 * What it learned holds only while the list is being built: a photo
	 * attached later in the same request must be found.
	 *
	 * @param array<int, int> $ids Spot or impact record IDs.
	 */
	public static function expect( array $ids ): void {
		self::$bare = array();
		if ( ! $ids ) {
			return;
		}
		$with = array();
		foreach ( array_chunk( $ids, 500 ) as $some ) {
			$found = get_posts(
				array(
					'post_type'       => 'attachment',
					'post_status'     => 'inherit',
					'post_parent__in' => $some,
					'post_mime_type'  => 'image',
					'numberposts'     => -1,
					'fields'          => 'id=>parent',
				)
			);
			foreach ( $found as $parent ) {
				$with[ (int) $parent ] = true;
			}
		}
		foreach ( $ids as $id ) {
			if ( ! isset( $with[ $id ] ) ) {
				self::$bare[ $id ] = true;
			}
		}
	}

	/**
	 * Photos attached to a Spot.
	 *
	 * @param int $spot_id Spot ID.
	 * @return array<int, array{id: int, url: string, full: string}>
	 */
	public static function for_spot( int $spot_id ): array {
		if ( isset( self::$bare[ $spot_id ] ) ) {
			return array();
		}
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => $spot_id,
				'post_mime_type' => 'image',
				'numberposts'    => self::LIMIT,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$photos = array();
		foreach ( $attachments as $attachment ) {
			$medium   = wp_get_attachment_image_src( $attachment->ID, 'medium_large' );
			$photos[] = array(
				'id'   => $attachment->ID,
				'url'  => $medium ? $medium[0] : (string) wp_get_attachment_url( $attachment->ID ),
				'full' => (string) wp_get_attachment_url( $attachment->ID ),
			);
		}
		return $photos;
	}

	/**
	 * Attach one uploaded image to a Spot.
	 *
	 * The photo is converted first. The copy goes through the WordPress upload
	 * handler, so the site's media rules apply. A repeat of the same client photo
	 * id returns the photo that is already attached.
	 *
	 * @param int                  $spot_id         Spot ID.
	 * @param array<string, mixed> $file            One entry from the request files.
	 * @param string               $client_photo_id Idempotency key from the field app.
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function attach_upload( int $spot_id, array $file, string $client_photo_id = '' ) {
		if ( '' !== $client_photo_id && $spot_id > 0 ) {
			$existing = get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'post_parent' => $spot_id,
					'numberposts' => 1,
					'fields'      => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Lookup by the sync idempotency key.
					'meta_key'    => '_pos_client_photo_id',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Lookup by the sync idempotency key.
					'meta_value'  => $client_photo_id,
				)
			);
			if ( $existing ) {
				return (int) $existing[0];
			}
		}

		// A photo with no parent is held for a claim until the record it belongs to exists.
		if ( $spot_id > 0 && count( self::for_spot( $spot_id ) ) >= self::LIMIT ) {
			$message = Schema::FEATURE === get_post_type( $spot_id )
				/* translators: %d: number of photos. */
				? __( 'A map feature holds up to %d photos.', 'packout-core' )
				/* translators: %d: number of photos. */
				: __( 'A Spot holds up to %d photos.', 'packout-core' );
			return new \WP_Error( 'packout_photo_limit', sprintf( $message, self::LIMIT ), array( 'status' => 400 ) );
		}

		$made = self::convert( (string) ( $file['tmp_name'] ?? '' ) );
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		$moved = self::keep( $made['file'], $made['type'], (string) ( $file['name'] ?? '' ) );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		$id = self::insert( $spot_id, $moved['file'], $moved['type'], $made['gps'] );
		if ( ! is_wp_error( $id ) && '' !== $client_photo_id ) {
			update_post_meta( $id, '_pos_client_photo_id', $client_photo_id );
		}
		return $id;
	}

	/**
	 * Keep an image that another site sent: a partner Spot's photograph, a logo, or a cover.
	 *
	 * The image goes through the same conversion as an upload, so it is a
	 * WebP of this site's making with no camera notes. Where it was taken is
	 * not read and not stored.
	 *
	 * @param int    $parent_id The record it belongs to.
	 * @param string $path      The image, in a temporary file.
	 * @param string $name      A name for the file.
	 * @param int    $edge      Longest side, in pixels.
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function receive( int $parent_id, string $path, string $name, int $edge = self::EDGE ) {
		$parent = get_post( $parent_id );
		if ( ! $parent instanceof \WP_Post ) {
			return new \WP_Error( 'packout_photo_parent', __( 'The record this image belongs to is not here.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$made = self::convert( $path, $edge );
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		$moved = self::keep( $made['file'], $made['type'], $name );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $moved['type'],
				'post_title'     => $parent->post_title,
				'post_status'    => 'inherit',
				'post_author'    => 0,
			),
			$moved['file'],
			$parent_id,
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $moved['file'] );
			return $id;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $moved['file'] ) );
		return (int) $id;
	}

	/**
	 * Attach a converted copy of a file that the site's form already stored in uploads.
	 *
	 * @param int    $spot_id Spot ID.
	 * @param string $url     File address inside this site's uploads.
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function attach_url( int $spot_id, string $url ) {
		$uploads = wp_get_upload_dir();
		if ( '' === $url || ! str_starts_with( $url, $uploads['baseurl'] . '/' ) ) {
			return new \WP_Error( 'packout_photo_url', __( 'That file is not in this site\'s uploads.', 'packout-core' ) );
		}

		$path = $uploads['basedir'] . '/' . ltrim( substr( $url, strlen( $uploads['baseurl'] ) ), '/' );
		$real = realpath( $path );
		$base = realpath( $uploads['basedir'] );
		if ( ! $real || ! $base || ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) || '' === self::type_of( $real ) ) {
			return new \WP_Error( 'packout_photo_type', __( 'That file is not an image.', 'packout-core' ) );
		}

		// The form keeps the file it stored, and its entry links to it. The Spot gets its own converted copy.
		$made = self::convert( $real );
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		$moved = self::keep( $made['file'], $made['type'], basename( $real ) );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}
		return self::insert( $spot_id, $moved['file'], $moved['type'], $made['gps'] );
	}

	/**
	 * Store a file a team or a coordinator uploaded: a logo, a cover image, or a resource.
	 *
	 * An image is converted like a photo. A logo also gets a small PNG copy
	 * for email, because some email programs cannot show WebP. A PDF is kept
	 * as it was sent.
	 *
	 * @param int                  $parent_id Team or Place ID.
	 * @param array<string, mixed> $file      One entry from the request files.
	 * @param bool                 $documents Whether a PDF is accepted as well as an image.
	 * @param string               $purpose       `logo`, `cover`, or `file`.
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function store( int $parent_id, array $file, bool $documents = false, string $purpose = 'logo' ) {
		$refused = new \WP_Error(
			'packout_file_type',
			$documents
				? __( 'The file must be a PDF or an image: JPEG, PNG, HEIC, AVIF, WebP, GIF, TIFF, or BMP.', 'packout-core' )
				: __( 'That must be an image: JPEG, PNG, HEIC, AVIF, WebP, GIF, TIFF, or BMP.', 'packout-core' ),
			array( 'status' => 400 )
		);

		$path = (string) ( $file['tmp_name'] ?? '' );
		$name = (string) ( $file['name'] ?? '' );
		if ( '' === $path || ! is_readable( $path ) ) {
			return $refused;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// The type comes from the file's contents, not from the name it was given.
		$mail = '';
		if ( '' !== self::type_of( $path ) ) {
			$logo = 'logo' === $purpose;
			$made = self::convert( $path, $logo ? self::LOGO_EDGE : self::EDGE, $logo ? 90 : 82 );
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$moved = self::keep( $made['file'], $made['type'], $name );
			if ( ! is_wp_error( $moved ) && $logo ) {
				$mail = self::mail_copy( $moved['file'] );
			}
		} elseif ( $documents ) {
			if ( (int) filesize( $path ) > self::FILE_BYTES ) {
				return new \WP_Error( 'packout_file_size', __( 'The document is larger than 8 MB.', 'packout-core' ), array( 'status' => 400 ) );
			}
			$allowed = array( 'pdf' => 'application/pdf' );
			$found   = wp_check_filetype_and_ext( $path, $name, $allowed );
			if ( 'application/pdf' !== (string) ( $found['type'] ?? '' ) ) {
				return $refused;
			}
			$overrides = array(
				'test_form' => false,
				'mimes'     => $allowed,
			);
			$moved     = is_uploaded_file( $path ) ? wp_handle_upload( $file, $overrides ) : wp_handle_sideload( $file, $overrides );
			if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
				$moved = new \WP_Error( 'packout_file_upload', is_array( $moved ) ? (string) $moved['error'] : __( 'The file could not be saved.', 'packout-core' ), array( 'status' => 400 ) );
			}
		} else {
			return $refused;
		}//end if
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $moved['type'],
				'post_title'     => sanitize_text_field( (string) pathinfo( $name, PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
				'post_author'    => get_current_user_id(),
			),
			$moved['file'],
			$parent_id,
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $moved['file'] ) );
		if ( '' !== $mail ) {
			update_post_meta( $id, self::MAIL_COPY, $mail );
		}
		return (int) $id;
	}

	/**
	 * Write a PNG copy of a logo beside it, at most 480 pixels on its long side.
	 *
	 * @param string $path The stored logo.
	 * @return string The copy's path inside uploads, or an empty string when none was made.
	 */
	private static function mail_copy( string $path ): string {
		$editor = wp_get_image_editor( $path, array( 'mime_type' => self::type_of( $path ) ) );
		if ( is_wp_error( $editor ) || ! $editor::supports_mime_type( 'image/png' ) ) {
			return '';
		}
		$size = $editor->get_size();
		if ( max( (int) $size['width'], (int) $size['height'] ) > 480 ) {
			$editor->resize( 480, 480, false );
		}
		$saved = $editor->save( preg_replace( '/\.[^.\/]+$/', '', $path ) . '-mail.png', 'image/png' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return '';
		}
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( (string) $uploads['basedir'] );
		return str_starts_with( (string) $saved['path'], $base ) ? substr( (string) $saved['path'], strlen( $base ) ) : '';
	}

	/**
	 * The address of a logo for an email: the PNG copy when there is one, otherwise the logo.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function mail_url( int $id ): string {
		$copy    = (string) get_post_meta( $id, self::MAIL_COPY, true );
		$uploads = wp_get_upload_dir();
		if ( '' !== $copy && ! str_contains( $copy, '..' ) && is_readable( trailingslashit( (string) $uploads['basedir'] ) . $copy ) ) {
			return trailingslashit( (string) $uploads['baseurl'] ) . $copy;
		}
		$image = wp_get_attachment_image_src( $id, 'medium' );
		return $image ? (string) $image[0] : '';
	}

	/**
	 * Delete the email copy of a logo that is being deleted.
	 *
	 * @param mixed $id Attachment ID.
	 */
	public static function forget_mail_copy( $id ): void {
		$copy = (string) get_post_meta( (int) $id, self::MAIL_COPY, true );
		if ( '' === $copy || str_contains( $copy, '..' ) || ! str_ends_with( $copy, '-mail.png' ) ) {
			return;
		}
		$uploads = wp_get_upload_dir();
		wp_delete_file( trailingslashit( (string) $uploads['basedir'] ) . $copy );
	}

	/**
	 * Create the attachment record and its image sizes.
	 *
	 * @param int                            $spot_id Spot ID.
	 * @param string                         $path    File path inside uploads.
	 * @param string                         $type    Mime type.
	 * @param array{0: float, 1: float}|null $taken Where the photo was taken, read before it was converted.
	 * @return int|\WP_Error Attachment ID.
	 */
	private static function insert( int $spot_id, string $path, string $type, ?array $taken = null ) {
		// The parent is a Spot, or an impact record for photos taken after the work.
		$spot = get_post( $spot_id );
		$spot = $spot instanceof \WP_Post && in_array( $spot->post_type, array( Schema::SPOT, Schema::IMPACT ), true ) ? $spot : null;
		$id   = wp_insert_attachment(
			array(
				'post_mime_type' => $type,
				'post_title'     => $spot ? $spot->post_title : __( 'Spot photo', 'packout-core' ),
				'post_status'    => 'inherit',
				'post_author'    => $spot ? (int) $spot->post_author : 0,
			),
			$path,
			$spot_id,
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
		if ( $taken ) {
			self::set_location( (int) $id, $taken[0], $taken[1] );
			// A Spot with no pin takes it from its photo. A pin a person set is left alone.
			if ( $spot && Schema::SPOT === $spot->post_type ) {
				Spots::pin_from_photo( $spot->ID, $taken[0], $taken[1] );
			}
		}
		return $id;
	}

	/**
	 * Where a photo was taken, from the location its camera wrote into the file.
	 *
	 * PHP reads it from a JPEG or a TIFF. ImageMagick reads it from the other
	 * types, HEIC among them, where the server has it. Many phones remove the
	 * location before a web page receives the photo, so most photos have none.
	 *
	 * @param string $path File path.
	 * @return array{0: float, 1: float}|null Latitude and longitude.
	 */
	public static function gps( string $path ): ?array {
		$type = self::type_of( $path );
		if ( '' === $type ) {
			return null;
		}
		if ( in_array( $type, array( 'image/jpeg', 'image/tiff' ), true ) ) {
			if ( ! function_exists( 'exif_read_data' ) ) {
				return null;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A damaged header raises a warning. The photo is kept either way.
			$exif = @exif_read_data( $path, 'GPS', true );
			$gps  = is_array( $exif ) && isset( $exif['GPS'] ) && is_array( $exif['GPS'] ) ? $exif['GPS'] : array();
			return self::place( $gps['GPSLatitude'] ?? null, (string) ( $gps['GPSLatitudeRef'] ?? 'N' ), $gps['GPSLongitude'] ?? null, (string) ( $gps['GPSLongitudeRef'] ?? 'E' ) );
		}
		if ( ! class_exists( '\Imagick' ) ) {
			return null;
		}
		try {
			$image = new \Imagick();
			$image->pingImage( $path );
			$found = self::gps_of( $image );
			$image->clear();
			return $found;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Where a photo was taken, from an image ImageMagick has open.
	 *
	 * @param \Imagick $image The image.
	 * @return array{0: float, 1: float}|null Latitude and longitude.
	 */
	private static function gps_of( \Imagick $image ): ?array {
		try {
			$found = $image->getImageProperties( 'exif:GPS*' );
		} catch ( \Exception $e ) {
			return null;
		}
		$found = is_array( $found ) ? $found : array();
		// ImageMagick gives "35/1, 10/1, 1612/100".
		$parts = static fn( string $key ): ?array => isset( $found[ $key ] ) ? array_map( 'trim', explode( ',', (string) $found[ $key ] ) ) : null;
		return self::place( $parts( 'exif:GPSLatitude' ), (string) ( $found['exif:GPSLatitudeRef'] ?? 'N' ), $parts( 'exif:GPSLongitude' ), (string) ( $found['exif:GPSLongitudeRef'] ?? 'E' ) );
	}

	/**
	 * A latitude and longitude from what a camera wrote, or null when it wrote none.
	 *
	 * @param mixed  $lat     Latitude as three fractions.
	 * @param string $lat_ref N or S.
	 * @param mixed  $lng     Longitude as three fractions.
	 * @param string $lng_ref E or W.
	 * @return array{0: float, 1: float}|null
	 */
	private static function place( $lat, string $lat_ref, $lng, string $lng_ref ): ?array {
		if ( empty( $lat ) || empty( $lng ) ) {
			return null;
		}
		$lat = self::degrees( $lat, $lat_ref );
		$lng = self::degrees( $lng, $lng_ref );
		// A camera with no fix writes zeros.
		if ( null === $lat || null === $lng || abs( $lat ) > 90 || abs( $lng ) > 180 || ( 0.0 === $lat && 0.0 === $lng ) ) {
			return null;
		}
		return array( round( $lat, 6 ), round( $lng, 6 ) );
	}

	/**
	 * Degrees, minutes, and seconds as one number.
	 *
	 * @param mixed  $parts Three fractions, as the file stores them.
	 * @param string $ref   N, S, E, or W.
	 */
	private static function degrees( $parts, string $ref ): ?float {
		if ( ! is_array( $parts ) || count( $parts ) < 3 ) {
			return null;
		}
		$values = array();
		foreach ( array_slice( array_values( $parts ), 0, 3 ) as $part ) {
			$pair = explode( '/', (string) $part );
			$top  = (float) $pair[0];
			$base = isset( $pair[1] ) ? (float) $pair[1] : 1.0;
			if ( 0.0 === $base ) {
				return null;
			}
			$values[] = $top / $base;
		}
		$degrees = $values[0] + $values[1] / 60 + $values[2] / 3600;
		return in_array( strtoupper( substr( $ref, 0, 1 ) ), array( 'S', 'W' ), true ) ? -$degrees : $degrees;
	}

	/**
	 * Keep where a photo was taken.
	 *
	 * @param int   $id  Attachment ID.
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 */
	public static function set_location( int $id, float $lat, float $lng ): bool {
		if ( abs( $lat ) > 90 || abs( $lng ) > 180 || ( 0.0 === $lat && 0.0 === $lng ) || 'attachment' !== get_post_type( $id ) ) {
			return false;
		}
		update_post_meta( $id, '_pos_gps', round( $lat, 6 ) . ',' . round( $lng, 6 ) );
		return true;
	}

	/**
	 * Where a photo was taken, when that is known.
	 *
	 * @param int $id Attachment ID.
	 * @return array{0: float, 1: float}|null Latitude and longitude.
	 */
	public static function location( int $id ): ?array {
		$parts = explode( ',', (string) get_post_meta( $id, '_pos_gps', true ) );
		return 2 === count( $parts ) && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ? array( (float) $parts[0], (float) $parts[1] ) : null;
	}

	/**
	 * How far from a Spot's pin a photo was taken, in words, or an empty string.
	 *
	 * @param int $id      Attachment ID.
	 * @param int $spot_id Spot ID.
	 */
	public static function distance_line( int $id, int $spot_id ): string {
		$taken = self::location( $id );
		if ( ! $taken ) {
			return '';
		}
		$pin = Spots::pin( $spot_id );
		if ( ! $pin ) {
			/* translators: 1: latitude, 2: longitude. */
			return sprintf( __( 'Taken at %1$s, %2$s. The Spot has no pin to compare.', 'packout-core' ), $taken[0], $taken[1] );
		}
		$meters = Geo::distance( $pin[0], $pin[1], $taken[0], $taken[1] );
		return $meters < 1000
			/* translators: %s: number of meters. */
			? sprintf( __( 'Taken %s m from the Spot\'s pin.', 'packout-core' ), number_format_i18n( round( $meters ) ) )
			/* translators: %s: number of kilometers. */
			: sprintf( __( 'Taken %s km from the Spot\'s pin.', 'packout-core' ), number_format_i18n( $meters / 1000, 1 ) );
	}
}
