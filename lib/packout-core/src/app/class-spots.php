<?php
/**
 * Spot records.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, changes, and reads Spots, and decides what each viewer may see.
 *
 * WordPress post status is the visibility switch. The domain status is the
 * stewardship lifecycle. Both are stored.
 */
class Spots {

	/**
	 * Post statuses a Spot can hold while it is in use.
	 */
	public const POST_STATUSES = array( 'pending', 'publish', 'private' );

	/**
	 * Most Spots one answer to the map or the field app carries.
	 */
	public const ANSWER = 1000;

	/**
	 * Whether the last answer was full.
	 *
	 * @var bool
	 */
	private static bool $more = false;

	/**
	 * A Spot post, or null.
	 *
	 * @param int $id Post ID.
	 */
	public static function get( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && Schema::SPOT === $post->post_type ? $post : null;
	}

	/**
	 * One stored field.
	 *
	 * @param int    $id      Spot ID.
	 * @param string $key     Field name without the prefix.
	 * @param string $fallback Value when nothing is stored.
	 */
	public static function field( int $id, string $key, string $fallback = '' ): string {
		$value = get_post_meta( $id, '_pos_' . $key, true );
		return is_scalar( $value ) && '' !== (string) $value ? (string) $value : $fallback;
	}

	/**
	 * The stored pin, or null.
	 *
	 * @param int $id Spot ID.
	 * @return array{0: float, 1: float}|null
	 */
	public static function pin( int $id ): ?array {
		return Geo::point( get_post_meta( $id, '_pos_lat', true ), get_post_meta( $id, '_pos_lng', true ) );
	}

	/**
	 * The Spot category term.
	 *
	 * @param int $id Spot ID.
	 */
	public static function category( int $id ): ?\WP_Term {
		$terms = get_the_terms( $id, Schema::CATEGORY );
		return is_array( $terms ) && $terms ? $terms[0] : null;
	}

	/**
	 * Revision counter. It rises on every change to the record.
	 *
	 * @param int $id Spot ID.
	 */
	public static function revision( int $id ): int {
		return (int) self::field( $id, 'revision', '0' );
	}

	/**
	 * The reporter's Spot for a client id.
	 *
	 * @param string $client_id UUID from the field app.
	 * @param int    $author    Reporter user ID.
	 */
	public static function by_client( string $client_id, int $author ): ?\WP_Post {
		$found = get_posts(
			array(
				'post_type'   => Schema::SPOT,
				'post_status' => array( 'pending', 'publish', 'private', 'trash' ),
				'author'      => $author,
				'numberposts' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Lookup by the sync idempotency key.
				'meta_key'    => '_pos_client_id',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Lookup by the sync idempotency key.
				'meta_value'  => $client_id,
			)
		);
		return $found ? $found[0] : null;
	}

	/**
	 * Create a pending Spot.
	 *
	 * @param array<string, mixed> $input  Spot fields. See normalize().
	 * @param int                  $author Reporter user ID, or 0 for a guest.
	 * @param array<string, mixed> $extra  client_id and entry_id, and who to credit: credit_team (an organization
	 *                                     of the reporter's, 0 for none, absent to use theirs), credit_named, and
	 *                                     reporter_name for a visitor who asked to be named.
	 * @return int|\WP_Error Spot ID.
	 */
	public static function create( array $input, int $author, array $extra = array() ) {
		$fields = self::normalize( $input, null );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$id = wp_insert_post(
			array(
				'post_type'    => Schema::SPOT,
				'post_status'  => 'pending',
				'post_author'  => $author,
				'post_title'   => wp_slash( $fields['title'] ),
				'post_content' => wp_slash( $fields['description'] ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		wp_set_object_terms( $id, $fields['category'], Schema::CATEGORY );
		self::store( $id, $fields );
		update_post_meta( $id, '_pos_status', 'spotted' );
		update_post_meta( $id, '_pos_precision', 'exact' );
		Vocabularies::assign( $id, Vocabularies::STAGE, 'spotted' );
		Vocabularies::assign( $id, Vocabularies::PRECISION, 'exact' );
		update_post_meta( $id, '_pos_revision', 1 );

		if ( ! empty( $extra['client_id'] ) ) {
			update_post_meta( $id, '_pos_client_id', (string) $extra['client_id'] );
			update_post_meta( $id, '_pos_client_revision', 1 );
		}
		if ( ! empty( $extra['entry_id'] ) ) {
			update_post_meta( $id, '_pos_entry_id', (int) $extra['entry_id'] );
		}

		// Who the public page credits. A visitor's name is kept only when they asked for it to be shown.
		self::set_credit( $id, array_key_exists( 'credit_team', $extra ) && null !== $extra['credit_team'] ? (int) $extra['credit_team'] : null, ! empty( $extra['credit_named'] ) );
		$name = $author <= 0 && ! empty( $extra['credit_named'] ) ? mb_substr( sanitize_text_field( (string) ( $extra['reporter_name'] ?? '' ) ), 0, 60 ) : '';
		if ( '' !== $name ) {
			update_post_meta( $id, '_pos_reporter_name', wp_slash( $name ) );
		}
		/**
		 * A Spot was reported.
		 *
		 * @param int $id Spot ID.
		 */
		do_action( 'packout_spot_submitted', $id );

		return $id;
	}

	/**
	 * Change the reported fields of a Spot.
	 *
	 * @param int                  $id        Spot ID.
	 * @param array<string, mixed> $input     Fields to change. Missing fields keep their value.
	 * @param bool                 $by_client True when the reporter's device is the source.
	 * @return bool|\WP_Error True when something changed, false when nothing did.
	 */
	public static function update( int $id, array $input, bool $by_client = false ) {
		$post = self::get( $id );
		if ( ! $post ) {
			return new \WP_Error( 'packout_not_found', __( 'That Spot does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}

		$fields = self::normalize( $input, $post );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$before = self::snapshot( $post );
		if ( $before === $fields ) {
			return false;
		}

		wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => wp_slash( $fields['title'] ),
				'post_content' => wp_slash( $fields['description'] ),
			)
		);
		wp_set_object_terms( $id, $fields['category'], Schema::CATEGORY );
		self::store( $id, $fields );
		if ( $before['lat'] !== $fields['lat'] || $before['lng'] !== $fields['lng'] ) {
			// A pin somebody set or moved is theirs, whatever it started as.
			delete_post_meta( $id, '_pos_pin_source' );
		}
		self::touch( $id, $by_client );

		return true;
	}

	/**
	 * Say who the public page credits with a report.
	 *
	 * @param int      $id        Spot ID.
	 * @param int|null $team      An organization, 0 for none, or null to use the reporter's own.
	 * @param bool     $named     Whether the reporter asked to be named.
	 * @param bool     $moderator True when a coordinator decides: any organization can then be credited.
	 */
	public static function set_credit( int $id, ?int $team, bool $named, bool $moderator = false ): bool {
		$post = self::get( $id );
		if ( ! $post ) {
			return false;
		}
		if ( $moderator ) {
			$credit = array(
				'team'  => null !== $team && Teams::get( $team ) ? $team : 0,
				'named' => $named,
			);
		} else {
			$credit = Credit::choose( (int) $post->post_author, $team, $named );
		}
		if ( $credit['team'] > 0 ) {
			update_post_meta( $id, '_pos_credit_team', $credit['team'] );
		} else {
			delete_post_meta( $id, '_pos_credit_team' );
		}
		if ( $credit['named'] ) {
			update_post_meta( $id, '_pos_credit_named', '1' );
		} else {
			delete_post_meta( $id, '_pos_credit_named' );
		}
		return true;
	}

	/**
	 * Who reported a Spot, as the reporter left it: the organization and whether they asked to be named.
	 *
	 * @param int $id Spot ID.
	 * @return array{team: int, named: bool}
	 */
	public static function credit( int $id ): array {
		return array(
			'team'  => (int) self::field( $id, 'credit_team', '0' ),
			'named' => '1' === self::field( $id, 'credit_named' ),
		);
	}

	/**
	 * Who reported a Spot, as the public reads it.
	 *
	 * @param \WP_Post $post Spot.
	 * @return array{kind: string, label: string, url: string}
	 */
	public static function reported_by( \WP_Post $post ): array {
		$credit = self::credit( $post->ID );
		return Credit::line( $credit['team'], $credit['named'], (int) $post->post_author, self::field( $post->ID, 'reporter_name' ) );
	}

	/**
	 * Whether the public sees the photos of a Spot. They do, unless a coordinator hid them.
	 *
	 * @param int $id Spot ID.
	 */
	public static function photos_public( int $id ): bool {
		return '0' !== self::field( $id, 'photos_public' );
	}

	/**
	 * Let the public see the photos of a Spot, or stop.
	 *
	 * @param int  $id     Spot ID.
	 * @param bool $is_public Whether they are shown.
	 */
	public static function set_photos_public( int $id, bool $is_public ): bool {
		return self::set_field( $id, 'photos_public', $is_public ? '1' : '0' );
	}

	/**
	 * The photos the public sees of a Spot: those sent with the report, then
	 * those of each cleanup and each return visit, oldest first.
	 *
	 * None of a Spot that is not published, none of a Spot whose location the
	 * public sees approximately, because a photo can show where it is, and
	 * none where a coordinator hid them.
	 *
	 * The reporter is shown the photos of their own report whatever the
	 * public sees, and the others when the public sees them.
	 *
	 * @param \WP_Post $post  Spot.
	 * @param bool     $owner True for the reporter's own view.
	 * @return array<int, array{id: int, url: string, large: string, full: string, width: int, height: int, kind: string, caption: string, alt: string}>
	 */
	public static function gallery( \WP_Post $post, bool $owner = false ): array {
		$public = 'publish' === $post->post_status && '' === self::field( $post->ID, 'duplicate_of' ) && 'coarse' !== self::field( $post->ID, 'precision', 'exact' ) && self::photos_public( $post->ID );
		if ( ! $public && ! $owner ) {
			return array();
		}
		$sets = array( array( 'report', $post->ID ) );
		if ( $public ) {
			foreach ( array_reverse( Impact::of_spot( $post->ID ) ) as $record ) {
				$sets[] = array( Impact::CLEANUP === Impact::kind( $record->ID ) ? 'cleanup' : 'visit', $record->ID );
			}
		}
		$captions = array(
			'report'  => '',
			'cleanup' => __( 'After the cleanup', 'packout-core' ),
			'visit'   => __( 'Return visit', 'packout-core' ),
		);
		$photos   = array();
		foreach ( $sets as $set ) {
			foreach ( Photos::for_spot( $set[1] ) as $photo ) {
				$large    = wp_get_attachment_image_src( $photo['id'], 'large' );
				$photos[] = array(
					'id'      => $photo['id'],
					'url'     => $photo['url'],
					'large'   => $large ? (string) $large[0] : $photo['full'],
					'full'    => $photo['full'],
					'width'   => $large ? (int) $large[1] : 0,
					'height'  => $large ? (int) $large[2] : 0,
					'kind'    => $set[0],
					'caption' => $captions[ $set[0] ],
					'alt'     => '',
				);
			}
		}
		$count = count( $photos );
		foreach ( $photos as $index => $photo ) {
			$here = number_format_i18n( $index + 1 );
			$all  = number_format_i18n( $count );
			if ( 'cleanup' === $photo['kind'] ) {
				/* translators: 1: number of this photo, 2: number of photos. */
				$photos[ $index ]['alt'] = sprintf( __( 'Photo %1$s of %2$s, taken after the cleanup', 'packout-core' ), $here, $all );
			} elseif ( 'visit' === $photo['kind'] ) {
				/* translators: 1: number of this photo, 2: number of photos. */
				$photos[ $index ]['alt'] = sprintf( __( 'Photo %1$s of %2$s, taken on a return visit', 'packout-core' ), $here, $all );
			} else {
				/* translators: 1: number of this photo, 2: number of photos. */
				$photos[ $index ]['alt'] = sprintf( __( 'Photo %1$s of %2$s from the report', 'packout-core' ), $here, $all );
			}
		}
		return $photos;
	}

	/**
	 * Give a Spot that has no pin the place its photo was taken.
	 *
	 * @param int   $id  Spot ID.
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return bool Whether the pin was set.
	 */
	public static function pin_from_photo( int $id, float $lat, float $lng ): bool {
		if ( ! self::get( $id ) || self::pin( $id ) ) {
			return false;
		}
		$done = self::update(
			$id,
			array(
				'lat' => $lat,
				'lng' => $lng,
			)
		);
		if ( true !== $done ) {
			return false;
		}
		update_post_meta( $id, '_pos_pin_source', 'photo' );
		return true;
	}

	/**
	 * Publish a Spot to the map.
	 *
	 * @param int $id Spot ID.
	 */
	public static function publish( int $id ): bool {
		if ( ! self::set_post_status( $id, 'publish' ) ) {
			return false;
		}
		delete_post_meta( $id, '_pos_return_reason' );

		/**
		 * A coordinator published a Spot.
		 *
		 * @param int $id Spot ID.
		 */
		do_action( 'packout_spot_published', $id );
		return true;
	}

	/**
	 * Keep a Spot for crews and coordinators only.
	 *
	 * @param int $id Spot ID.
	 */
	public static function keep_private( int $id ): bool {
		return self::set_post_status( $id, 'private' );
	}

	/**
	 * Send a Spot back to the reporter with a reason.
	 *
	 * @param int    $id     Spot ID.
	 * @param string $reason Short reason shown to the reporter.
	 */
	public static function return_to_reporter( int $id, string $reason ): bool {
		if ( ! self::get( $id ) ) {
			return false;
		}
		self::set_post_status( $id, 'pending' );
		update_post_meta( $id, '_pos_return_reason', sanitize_textarea_field( $reason ) );
		self::touch( $id );
		return true;
	}

	/**
	 * Set how precisely the public sees the location.
	 *
	 * @param int    $id        Spot ID.
	 * @param string $precision exact or coarse.
	 */
	public static function set_precision( int $id, string $precision ): bool {
		$coarse = 'coarse' === $precision;
		if ( ! self::set_field( $id, 'precision', $coarse ? 'coarse' : 'exact' ) ) {
			return false;
		}
		// A generated title names the pin or the landmark, and the address is made from the title.
		// Neither may say more than the public is shown. A title a person typed is theirs.
		$post = self::get( $id );
		if ( $post && '1' !== self::field( $id, 'title_custom' ) ) {
			$title = self::generated_title( self::snapshot( $post ), $coarse );
			if ( $title !== $post->post_title ) {
				wp_update_post(
					array(
						'ID'         => $id,
						'post_title' => wp_slash( $title ),
						'post_name'  => '',
					)
				);
				// WordPress keeps the old address so it can redirect. The old address held the pin.
				delete_post_meta( $id, '_wp_old_slug' );
			}
		}
		return true;
	}

	/**
	 * Set the hazard flag.
	 *
	 * @param int    $id     Spot ID.
	 * @param string $hazard Hazard flag.
	 */
	public static function set_hazard( int $id, string $hazard ): bool {
		if ( ! isset( Schema::hazards()[ $hazard ] ) || ! self::set_field( $id, 'hazard', $hazard ) ) {
			return false;
		}
		if ( 'needs_authorization' === $hazard ) {
			if ( '' === self::field( $id, 'clearance_since' ) ) {
				update_post_meta( $id, '_pos_clearance_since', gmdate( 'c' ) );
			}
		} else {
			delete_post_meta( $id, '_pos_clearance_since' );
			// A clearance answers "needs authorization" and nothing else.
			self::drop_clearance( $id );
		}
		return true;
	}

	/**
	 * The clearance of a Spot that needs authorization, or null.
	 *
	 * @param int $id Spot ID.
	 * @return array{by: int, on: string, reference: string, scope: string, public: bool}|null
	 */
	public static function clearance( int $id ): ?array {
		$reference = self::field( $id, 'clear_ref' );
		if ( '' === $reference || 'needs_authorization' !== self::field( $id, 'hazard', 'none' ) ) {
			return null;
		}
		return array(
			'by'        => (int) self::field( $id, 'clear_by', '0' ),
			'on'        => self::field( $id, 'clear_on' ),
			'reference' => $reference,
			'scope'     => self::field( $id, 'clear_scope' ),
			'public'    => '1' === self::field( $id, 'clear_public' ),
		);
	}

	/**
	 * Whether a hazard flag still stops the Spot from being marked cleaned.
	 *
	 * @param int $id Spot ID.
	 */
	public static function blocked( int $id ): bool {
		return Vocabularies::hazard_blocks( self::field( $id, 'hazard', 'none' ) ) && ! self::clearance( $id );
	}

	/**
	 * How many open Spots need authorization and have no clearance.
	 */
	public static function awaiting_clearance(): int {
		$flagged = get_posts(
			array(
				'post_type'   => Schema::SPOT,
				'post_status' => self::POST_STATUSES,
				'numberposts' => 500,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Spots with one hazard flag.
				'meta_key'    => '_pos_hazard',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Spots with one hazard flag.
				'meta_value'  => 'needs_authorization',
			)
		);
		return count( array_filter( $flagged, static fn( $id ) => self::blocked( (int) $id ) && 'cleaned' !== self::field( (int) $id, 'status', 'spotted' ) ) );
	}

	/**
	 * Record that the work at a Spot is authorized.
	 *
	 * The coordinator types what the authorization is. The plugin files no
	 * permit and checks none. A do-not-touch Spot has no clearance.
	 *
	 * @param int                  $id    Spot ID.
	 * @param array<string, mixed> $input reference, scope, on (Y-m-d), public.
	 * @param int                  $user  The coordinator.
	 * @return true|\WP_Error
	 */
	public static function clear( int $id, array $input, int $user ) {
		if ( ! self::get( $id ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That Spot does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! Capabilities::can( Capabilities::MODERATE, $user ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'A coordinator records a clearance.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$hazard = self::field( $id, 'hazard', 'none' );
		if ( 'do_not_touch' === $hazard ) {
			return new \WP_Error( 'packout_no_clearance', __( 'A do-not-touch Spot has no clearance. It stays a report for the agency responsible. If the flag is wrong, change the flag.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( 'needs_authorization' !== $hazard ) {
			return new \WP_Error( 'packout_no_clearance', __( 'This Spot is not flagged as needing authorization, so there is nothing to clear.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$reference = sanitize_text_field( (string) ( $input['reference'] ?? '' ) );
		if ( '' === $reference || mb_strlen( $reference ) > 200 ) {
			return new \WP_Error( 'packout_clearance_reference', __( 'Say what authorizes the work, in up to 200 characters: the permit, the agreement, or who wrote and when.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$on = Schema::day( $input['on'] ?? '' );

		update_post_meta( $id, '_pos_clear_ref', wp_slash( $reference ) );
		update_post_meta( $id, '_pos_clear_scope', wp_slash( mb_substr( sanitize_textarea_field( (string) ( $input['scope'] ?? '' ) ), 0, 1000 ) ) );
		update_post_meta( $id, '_pos_clear_on', '' !== $on ? $on : wp_date( 'Y-m-d' ) );
		update_post_meta( $id, '_pos_clear_by', $user );
		update_post_meta( $id, '_pos_clear_public', empty( $input['public'] ) ? '0' : '1' );
		delete_post_meta( $id, '_pos_clearance_since' );
		self::touch( $id );

		/**
		 * A coordinator recorded that the work at a Spot is authorized.
		 *
		 * @param int $id   Spot ID.
		 * @param int $user The coordinator.
		 */
		do_action( 'packout_spot_cleared', $id, $user );
		return true;
	}

	/**
	 * Withdraw a clearance. The Spot is blocked again.
	 *
	 * @param int $id   Spot ID.
	 * @param int $user The coordinator.
	 * @return true|\WP_Error
	 */
	public static function unclear( int $id, int $user ) {
		if ( ! self::get( $id ) || ! Capabilities::can( Capabilities::MODERATE, $user ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'A coordinator withdraws a clearance.', 'packout-core' ), array( 'status' => 403 ) );
		}
		self::drop_clearance( $id );
		return true;
	}

	/**
	 * Delete the clearance fields.
	 *
	 * @param int $id Spot ID.
	 */
	private static function drop_clearance( int $id ): void {
		if ( '' === self::field( $id, 'clear_ref' ) ) {
			return;
		}
		foreach ( array( 'ref', 'scope', 'on', 'by', 'public' ) as $key ) {
			delete_post_meta( $id, '_pos_clear_' . $key );
		}
		self::touch( $id );
	}

	/**
	 * The hazard flag of a Spot in a few words for a crew's list, or an empty string.
	 *
	 * @param int $id Spot ID.
	 */
	public static function hazard_fact( int $id ): string {
		$hazard    = self::field( $id, 'hazard', 'none' );
		$clearance = self::clearance( $id );
		if ( $clearance ) {
			/* translators: %s: what authorizes the work. */
			return sprintf( __( 'Authorized: %s', 'packout-core' ), $clearance['reference'] );
		}
		return 'none' !== $hazard && isset( Schema::hazards()[ $hazard ] ) ? Schema::hazards()[ $hazard ] : '';
	}

	/**
	 * What a viewer is told about the hazard flag of a Spot.
	 *
	 * @param int  $id     Spot ID.
	 * @param bool $is_public Whether the viewer is the public.
	 * @return array{blocked: bool, authorized: bool, guidance: string}
	 */
	public static function standing( int $id, bool $is_public ): array {
		$hazard    = self::field( $id, 'hazard', 'none' );
		$clearance = self::clearance( $id );
		$told      = $clearance && ( ! $is_public || $clearance['public'] );
		if ( $told ) {
			return array(
				'blocked'    => false,
				'authorized' => true,
				'guidance'   => $is_public
					? __( 'A coordinator has recorded that this work is authorized. The crew that takes it on does it. Please leave it for them.', 'packout-core' )
					: __( 'A coordinator has recorded that this work is authorized. Stay inside what the clearance allows.', 'packout-core' ),
			);
		}
		return array(
			'blocked'    => Vocabularies::hazard_blocks( $hazard ),
			'authorized' => false,
			'guidance'   => Schema::hazard_guidance( $hazard ),
		);
	}

	/**
	 * Move a Spot along the stewardship lifecycle.
	 *
	 * @param int    $id     Spot ID.
	 * @param string $status Domain status.
	 */
	public static function set_status( int $id, string $status ): bool {
		$old = self::field( $id, 'status', 'spotted' );
		if ( ! isset( Schema::statuses()[ $status ] ) || ! self::set_field( $id, 'status', $status ) ) {
			return false;
		}
		if ( $old !== $status ) {
			/**
			 * A Spot moved along the lifecycle.
			 *
			 * @param int    $id     Spot ID.
			 * @param string $status New domain status.
			 * @param string $old    Previous domain status.
			 */
			do_action( 'packout_spot_status_changed', $id, $status, $old );
		}
		return true;
	}

	/**
	 * Record that a Spot was cleaned.
	 *
	 * A Spot flagged to be left in place cannot be marked cleaned until a
	 * coordinator clears the flag, or records a clearance for a Spot that
	 * needs authorization.
	 *
	 * @param int $id      Spot ID.
	 * @param int $user    Who cleaned it, or recorded it for the crew.
	 * @param int $mission Mission ID, or 0 for a cleanup with no mission.
	 * @return true|\WP_Error
	 */
	public static function mark_cleaned( int $id, int $user, int $mission = 0 ) {
		$post = self::get( $id );
		if ( ! $post || 'trash' === $post->post_status ) {
			return new \WP_Error( 'packout_not_found', __( 'That Spot does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( self::blocked( $id ) ) {
			return new \WP_Error( 'packout_hazard', Schema::hazard_guidance( self::field( $id, 'hazard', 'none' ) ), array( 'status' => 409 ) );
		}

		update_post_meta( $id, '_pos_cleaned_by', $user );
		update_post_meta( $id, '_pos_cleaned_at', gmdate( 'c' ) );
		update_post_meta( $id, '_pos_cleaned_mission', $mission );
		delete_post_meta( $id, '_pos_returned_at' );
		self::set_status( $id, 'cleaned' );
		return true;
	}

	/**
	 * The status of a Spot nobody is working on: watch when the problem came
	 * back after a cleanup, spotted otherwise.
	 *
	 * @param int $id Spot ID.
	 */
	public static function waiting_status( int $id ): string {
		return '' !== self::field( $id, 'returned_at' ) ? 'watch' : 'spotted';
	}

	/**
	 * Record that the problem came back at a cleaned Spot.
	 *
	 * The Spot moves to watch and is open for a new mission. Earlier impact
	 * records are kept.
	 *
	 * @param int $id Spot ID.
	 */
	public static function mark_returned( int $id ): bool {
		update_post_meta( $id, '_pos_returned_at', gmdate( 'c' ) );
		return self::set_status( $id, 'watch' );
	}

	/**
	 * Link a Spot to an older report of the same place, or clear the link.
	 *
	 * A linked Spot leaves the public map. Both records remain.
	 *
	 * @param int $id    Newer Spot ID.
	 * @param int $older Older Spot ID, or 0 to clear.
	 */
	public static function mark_duplicate( int $id, int $older ): bool {
		if ( ! self::get( $id ) || $older === $id || ( $older > 0 && ! self::get( $older ) ) ) {
			return false;
		}
		if ( $older > 0 ) {
			update_post_meta( $id, '_pos_duplicate_of', $older );
		} else {
			delete_post_meta( $id, '_pos_duplicate_of' );
		}
		self::touch( $id );
		return true;
	}

	/**
	 * Other open Spots in the same category within the duplicate radius.
	 *
	 * Spots without a pin are not distance-matched.
	 *
	 * @param int $id Spot ID.
	 * @return array<int, array{id: int, title: string, distance: int, post_status: string}>
	 */
	public static function duplicates( int $id ): array {
		$pin      = self::pin( $id );
		$category = self::category( $id );
		if ( ! $pin || ! $category ) {
			return array();
		}

		$radius = (int) Settings::get( 'duplicate_radius' );
		$d_lat  = $radius / 111320;
		$d_lng  = $radius / ( 111320 * max( 0.1, cos( deg2rad( $pin[0] ) ) ) );

		$nearby = get_posts(
			array(
				'post_type'    => Schema::SPOT,
				'post_status'  => self::POST_STATUSES,
				'numberposts'  => 50,
				'post__not_in' => array( $id ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Duplicate hints compare one category.
				'tax_query'    => array(
					array(
						'taxonomy' => Schema::CATEGORY,
						'terms'    => array( $category->term_id ),
					),
				),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounding box before the exact distance.
				'meta_query'   => array(
					array(
						'key'     => '_pos_lat',
						'value'   => array( $pin[0] - $d_lat, $pin[0] + $d_lat ),
						'compare' => 'BETWEEN',
						'type'    => 'DECIMAL(10,6)',
					),
					array(
						'key'     => '_pos_lng',
						'value'   => array( $pin[1] - $d_lng, $pin[1] + $d_lng ),
						'compare' => 'BETWEEN',
						'type'    => 'DECIMAL(10,6)',
					),
				),
			)
		);

		$hints = array();
		foreach ( $nearby as $other ) {
			$other_pin = self::pin( $other->ID );
			if ( ! $other_pin || 'cleaned' === self::field( $other->ID, 'status', 'spotted' ) ) {
				continue;
			}
			$distance = Geo::distance( $pin[0], $pin[1], $other_pin[0], $other_pin[1] );
			if ( $distance <= $radius ) {
				$hints[] = array(
					'id'          => $other->ID,
					'title'       => $other->post_title,
					'distance'    => (int) round( $distance ),
					'post_status' => $other->post_status,
				);
			}
		}

		usort( $hints, static fn( $a, $b ) => $a['distance'] <=> $b['distance'] );
		return $hints;
	}

	/**
	 * How much of a Spot a user may see.
	 *
	 * @param \WP_Post $post    Spot.
	 * @param int      $user_id User ID, 0 for a visitor.
	 * @return string|null moderator, owner, exact, public, or null when the Spot is hidden.
	 *                     A lead, and the crew of an open mission that includes the Spot,
	 *                     get the exact pin even when the public gets an approximate one.
	 */
	public static function view_for( \WP_Post $post, int $user_id ): ?string {
		if ( ! in_array( $post->post_status, self::POST_STATUSES, true ) ) {
			return null;
		}
		if ( Capabilities::can( Capabilities::MODERATE, $user_id ) ) {
			return 'moderator';
		}
		if ( $user_id > 0 && (int) $post->post_author === $user_id ) {
			return 'owner';
		}
		if ( '' !== self::field( $post->ID, 'duplicate_of' ) || ! Post_Access::can_read( $post, $user_id ) ) {
			return null;
		}
		if ( Capabilities::can( Capabilities::LEAD, $user_id ) || in_array( $post->ID, Missions::crew_spots( $user_id ), true ) ) {
			return 'exact';
		}
		if ( Capabilities::can( Capabilities::REPORT, $user_id ) && 'coarse' !== self::field( $post->ID, 'precision', 'exact' ) ) {
			return 'exact';
		}
		return 'public';
	}

	/**
	 * Spots a user may see, as payloads.
	 *
	 * The bounding box is applied to the location the viewer receives, so a
	 * coarse Spot cannot be narrowed down by shrinking the box. The database
	 * is asked first for the Spots stored in or beside the box, so that a
	 * site with more Spots than one answer carries still gives every Spot of
	 * a small enough area.
	 *
	 * One answer carries the thousand most recently changed. more() says
	 * whether the last one left any out.
	 *
	 * @param int                  $user_id User ID, 0 for a visitor.
	 * @param array<string, mixed> $args    Optional bbox, category slug, and domain status.
	 * @return array<int, array<string, mixed>>
	 */
	public static function visible( int $user_id, array $args = array() ): array {
		$statuses = array( 'publish' );
		if ( Capabilities::can( Capabilities::MODERATE, $user_id ) ) {
			$statuses = self::POST_STATUSES;
		} elseif ( Capabilities::can( Capabilities::REPORT, $user_id ) ) {
			$statuses = array( 'publish', 'private' );
		}

		/**
		 * Filters how many Spots one answer to the map or the field app carries.
		 *
		 * A site on a small server may lower it. It is never more than Spots::ANSWER.
		 *
		 * @param int $limit Spots in one answer.
		 */
		$limit = max( 1, min( self::ANSWER, (int) apply_filters( 'packout_spots_answer', self::ANSWER ) ) );
		$bbox  = $args['bbox'] ?? null;
		$query = array(
			'post_type'   => Schema::SPOT,
			'post_status' => $statuses,
			'numberposts' => $limit,
			'orderby'     => 'modified',
			'order'       => 'DESC',
		);
		if ( is_array( $bbox ) ) {
			// A coarse Spot is shown at the middle of its square, at most one square from its pin.
			$edge = Geo::COARSE_GRID;
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The Spots in or beside the box, before the exact test on what the viewer is shown.
			$query['meta_query'] = array(
				array(
					'key'     => '_pos_lat',
					'value'   => array( $bbox['south'] - $edge, $bbox['north'] + $edge ),
					'compare' => 'BETWEEN',
					'type'    => 'DECIMAL(10,6)',
				),
				array(
					'key'     => '_pos_lng',
					'value'   => array( $bbox['west'] - $edge, $bbox['east'] + $edge ),
					'compare' => 'BETWEEN',
					'type'    => 'DECIMAL(10,6)',
				),
			);
		}
		$posts      = get_posts( $query );
		self::$more = count( $posts ) >= $limit;

		if ( $user_id > 0 && ! in_array( 'pending', $statuses, true ) ) {
			$own   = get_posts(
				array(
					'post_type'   => Schema::SPOT,
					'post_status' => 'pending',
					'author'      => $user_id,
					'numberposts' => 200,
				)
			);
			$posts = array_merge( $own, $posts );
		}

		$category = (string) ( $args['category'] ?? '' );
		$status   = (string) ( $args['status'] ?? '' );
		$spots    = array();

		// Which of them have a photo or a record is asked once, not once for each Spot.
		$ids = array_map( static fn( $post ) => (int) $post->ID, $posts );
		Photos::expect( $ids );
		Impact::expect( $ids );

		foreach ( $posts as $post ) {
			$view = self::view_for( $post, $user_id );
			if ( null === $view ) {
				continue;
			}
			$spot = self::payload( $post, $view );
			if ( '' !== $category && $spot['category']['slug'] !== $category ) {
				continue;
			}
			if ( '' !== $status && $spot['status'] !== $status ) {
				continue;
			}
			if ( is_array( $bbox ) && ( null === $spot['location']['lat'] || ! Geo::inside( $spot['location']['lat'], $spot['location']['lng'], $bbox ) ) ) {
				continue;
			}
			$spots[] = $spot;
		}
		Photos::expect( array() );
		Impact::expect( array() );

		if ( empty( $args['partners'] ) ) {
			return $spots;
		}
		// What partners' sites reported, on the hub's map. The same three tests apply to what each shows.
		foreach ( Remote_Spots::visible( $args ) as $spot ) {
			if ( '' !== $category && $spot['category']['slug'] !== $category ) {
				continue;
			}
			if ( '' !== $status && $spot['status'] !== $status ) {
				continue;
			}
			if ( is_array( $bbox ) && ( null === $spot['location']['lat'] || ! Geo::inside( $spot['location']['lat'], $spot['location']['lng'], $bbox ) ) ) {
				continue;
			}
			$spots[] = $spot;
		}
		self::$more = self::$more || Remote_Spots::more();

		return $spots;
	}

	/**
	 * Whether the last answer of visible() left Spots out because it was full.
	 */
	public static function more(): bool {
		return self::$more;
	}

	/**
	 * A Spot as the API returns it.
	 *
	 * The public view carries the display location, a short description, who
	 * the reporter asked to be credited as, and the photos a coordinator lets
	 * the public see. It leaves out the reporter's account, the other photos,
	 * the client id, and the stored pin of a coarse Spot.
	 *
	 * @param \WP_Post $post Spot.
	 * @param string   $view public, exact, owner, or moderator.
	 * @return array<string, mixed>
	 */
	public static function payload( \WP_Post $post, string $view ): array {
		$id        = $post->ID;
		$category  = self::category( $id );
		$hazard    = self::field( $id, 'hazard', 'none' );
		$precision = self::field( $id, 'precision', 'exact' );
		$pin       = self::pin( $id );
		$landmark  = self::field( $id, 'landmark' );
		$public    = 'public' === $view;
		$standing  = self::standing( $id, $public );
		$place     = Places::of_spot( $id );

		if ( $public && $pin && 'coarse' === $precision ) {
			$pin      = Geo::coarse( $pin[0], $pin[1] );
			$landmark = '';
		}

		$spot = array(
			'id'                  => $id,
			'title'               => $post->post_title,
			'url'                 => 'publish' === $post->post_status ? (string) get_permalink( $post ) : '',
			'category'            => array(
				'slug' => $category ? $category->slug : '',
				'name' => $category ? $category->name : '',
			),
			'status'              => self::field( $id, 'status', 'spotted' ),
			'hazard'              => $hazard,
			// Whether a hazard flag still stops the work, as far as this viewer is told.
			'needs_authorization' => $standing['blocked'],
			'authorized'          => $standing['authorized'],
			'guidance'            => $standing['guidance'],
			// The public learns of a Place when the Place is listed.
			'place'               => $place && ( ! $public || 'publish' === $place->post_status ) ? array(
				'id'   => $place->ID,
				'name' => $place->post_title,
				'url'  => 'publish' === $place->post_status ? (string) get_permalink( $place ) : '',
			) : null,
			'description'         => $public ? wp_trim_words( $post->post_content, 40 ) : $post->post_content,
			'location'            => array(
				'kind'      => self::field( $id, 'location_kind', 'description' ),
				'lat'       => $pin ? $pin[0] : null,
				'lng'       => $pin ? $pin[1] : null,
				'precision' => $precision,
				'landmark'  => $landmark,
			),
			'updated'             => mysql2date( 'c', $post->post_modified_gmt, false ),
			// The day it was reported, and who the reporter asked to be credited as.
			'reported'            => mysql2date( 'Y-m-d', $post->post_date, false ),
			'reported_by'         => array_intersect_key( self::reported_by( $post ), array_flip( array( 'label', 'url' ) ) ),
			// The photos everyone may see. A crew's own copy of every photo is under photos.
			'gallery'             => array_map( static fn( $photo ) => array_diff_key( $photo, array( 'id' => 0 ) ), self::gallery( $post ) ),
		);
		// On the hub: the partners whose own report of this a coordinator linked to it.
		$also = Remote_Spots::also( $id );
		if ( $also ) {
			$spot['also'] = $also;
		}
		/**
		 * Lines to show with a Spot that name who else has to do with it: a crew of a partner that took it on, the hub it was copied from.
		 *
		 * @param array<int, array{text: string, name: string, url: string}> $notes Each a sentence with `%s` where the name goes.
		 * @param int                                                       $id    The Spot.
		 */
		$notes = (array) apply_filters( 'packout_spot_notes', array(), $id );
		if ( $notes ) {
			$spot['notes'] = array_values( $notes );
		}

		if ( $public ) {
			return $spot;
		}

		$accuracy                     = self::field( $id, 'accuracy' );
		$spot['location']['accuracy'] = '' !== $accuracy ? (float) $accuracy : null;
		$spot['location']['area']     = self::field( $id, 'area' );
		$spot['photos']               = Photos::for_spot( $id );
		$clearance                    = self::clearance( $id );
		$spot['clearance']            = $clearance ? array(
			'reference' => $clearance['reference'],
			'scope'     => $clearance['scope'],
			'on'        => $clearance['on'],
		) : null;

		if ( 'exact' === $view ) {
			return $spot;
		}

		$spot['post_status']   = $post->post_status;
		$spot['credit']        = self::credit( $id );
		$spot['revision']      = self::revision( $id );
		$spot['client_id']     = self::field( $id, 'client_id' );
		$spot['return_reason'] = self::field( $id, 'return_reason' );

		if ( 'moderator' === $view ) {
			$spot['reporter']     = (int) $post->post_author;
			$spot['entry_id']     = (int) self::field( $id, 'entry_id', '0' );
			$spot['duplicate_of'] = (int) self::field( $id, 'duplicate_of', '0' );
		}

		return $spot;
	}

	/**
	 * Clean and complete a set of Spot fields.
	 *
	 * A Spot needs a category and a description, and at least one of a pin, a
	 * landmark, or a photo.
	 *
	 * @param array<string, mixed> $input    Raw fields: category, description, title, landmark, lat, lng,
	 *                                       accuracy, area, hazard, photos_pending.
	 * @param \WP_Post|null        $existing The Spot being changed, or null for a new one.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function normalize( array $input, ?\WP_Post $existing ) {
		$current = $existing ? self::snapshot( $existing ) : array(
			'category'      => '',
			'description'   => '',
			'title'         => '',
			'title_custom'  => '',
			'landmark'      => '',
			'lat'           => '',
			'lng'           => '',
			'accuracy'      => '',
			'area'          => '',
			'hazard'        => 'none',
			'location_kind' => 'description',
		);

		$fields = $current;

		if ( array_key_exists( 'category', $input ) ) {
			$fields['category'] = self::category_slug( $input['category'] );
		}
		if ( '' === $fields['category'] ) {
			$fields['category'] = self::category_slug( Settings::get( 'default_category' ) );
		}
		if ( array_key_exists( 'description', $input ) ) {
			$fields['description'] = sanitize_textarea_field( (string) $input['description'] );
		}
		if ( array_key_exists( 'landmark', $input ) ) {
			$fields['landmark'] = sanitize_text_field( (string) $input['landmark'] );
		}
		if ( array_key_exists( 'area', $input ) ) {
			$fields['area'] = sanitize_textarea_field( (string) $input['area'] );
		}
		if ( array_key_exists( 'hazard', $input ) ) {
			$fields['hazard'] = isset( Schema::hazards()[ (string) $input['hazard'] ] ) ? (string) $input['hazard'] : 'none';
		}
		if ( array_key_exists( 'lat', $input ) || array_key_exists( 'lng', $input ) ) {
			$point              = Geo::point( $input['lat'] ?? null, $input['lng'] ?? null );
			$fields['lat']      = $point ? sprintf( '%.6F', $point[0] ) : '';
			$fields['lng']      = $point ? sprintf( '%.6F', $point[1] ) : '';
			$fields['accuracy'] = $point && isset( $input['accuracy'] ) && is_numeric( $input['accuracy'] ) ? (string) round( max( 0, (float) $input['accuracy'] ), 1 ) : '';
		}

		if ( '' === $fields['category'] ) {
			return new \WP_Error( 'packout_category', __( 'Choose a category for this Spot.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( '' === trim( $fields['description'] ) ) {
			return new \WP_Error( 'packout_description', __( 'Describe what is there and why it needs attention.', 'packout-core' ), array( 'status' => 400 ) );
		}

		$has_pin    = '' !== $fields['lat'];
		$has_photos = (int) ( $input['photos_pending'] ?? 0 ) > 0 || ( $existing && Photos::for_spot( $existing->ID ) );
		if ( ! $has_pin && '' === $fields['landmark'] && ! $has_photos ) {
			return new \WP_Error( 'packout_location', __( 'Add a photo, a pin, or a landmark so someone can find this place.', 'packout-core' ), array( 'status' => 400 ) );
		}

		if ( $has_pin ) {
			$fields['location_kind'] = 'pin';
		} elseif ( '' !== $fields['area'] ) {
			$fields['location_kind'] = 'area';
		} elseif ( '' !== $fields['landmark'] ) {
			$fields['location_kind'] = 'landmark';
		} else {
			$fields['location_kind'] = 'description';
		}

		$title = array_key_exists( 'title', $input ) ? sanitize_text_field( (string) $input['title'] ) : null;
		if ( null !== $title && '' !== $title ) {
			$fields['title']        = $title;
			$fields['title_custom'] = '1';
		} elseif ( '1' !== $fields['title_custom'] || null !== $title ) {
			$fields['title']        = self::generated_title( $fields, $existing && 'coarse' === self::field( $existing->ID, 'precision', 'exact' ) );
			$fields['title_custom'] = '';
		}

		return $fields;
	}

	/**
	 * The stored fields of a Spot, in the shape normalize() returns.
	 *
	 * @param \WP_Post $post Spot.
	 * @return array<string, string>
	 */
	private static function snapshot( \WP_Post $post ): array {
		$category = self::category( $post->ID );
		return array(
			'category'      => $category ? $category->slug : '',
			'description'   => $post->post_content,
			'title'         => $post->post_title,
			'title_custom'  => self::field( $post->ID, 'title_custom' ),
			'landmark'      => self::field( $post->ID, 'landmark' ),
			'lat'           => self::field( $post->ID, 'lat' ),
			'lng'           => self::field( $post->ID, 'lng' ),
			'accuracy'      => self::field( $post->ID, 'accuracy' ),
			'area'          => self::field( $post->ID, 'area' ),
			'hazard'        => self::field( $post->ID, 'hazard', 'none' ),
			'location_kind' => self::field( $post->ID, 'location_kind', 'description' ),
		);
	}

	/**
	 * Write the meta fields.
	 *
	 * @param int                   $id     Spot ID.
	 * @param array<string, string> $fields Normalized fields.
	 */
	private static function store( int $id, array $fields ): void {
		foreach ( array( 'landmark', 'lat', 'lng', 'accuracy', 'area', 'hazard', 'location_kind', 'title_custom' ) as $key ) {
			if ( '' === $fields[ $key ] ) {
				delete_post_meta( $id, '_pos_' . $key );
			} else {
				update_post_meta( $id, '_pos_' . $key, wp_slash( $fields[ $key ] ) );
			}
		}
		if ( isset( $fields['hazard'] ) ) {
			Vocabularies::assign( $id, Vocabularies::HAZARD, (string) $fields['hazard'] );
		}
		if ( isset( $fields['location_kind'] ) ) {
			Vocabularies::assign( $id, Vocabularies::LOCATION_KIND, (string) $fields['location_kind'] );
		}
	}

	/**
	 * A short label from the category plus the landmark or coordinates.
	 *
	 * The title is public, and so is the address made from it. For a Spot the
	 * public sees approximately, it names neither the landmark nor the pin.
	 *
	 * @param array<string, string> $fields Normalized fields.
	 * @param bool                  $coarse Whether the public sees an approximate location.
	 */
	private static function generated_title( array $fields, bool $coarse = false ): string {
		$term     = get_term_by( 'slug', $fields['category'], Schema::CATEGORY );
		$category = $term instanceof \WP_Term ? $term->name : __( 'Spot', 'packout-core' );

		if ( $coarse ) {
			/* translators: %s: category name. */
			return sprintf( __( '%s, approximate location', 'packout-core' ), $category );
		}
		if ( '' !== $fields['landmark'] ) {
			return $category . ': ' . wp_html_excerpt( $fields['landmark'], 60, '…' );
		}
		if ( '' !== $fields['lat'] ) {
			/* translators: 1: category name, 2: latitude, 3: longitude. */
			return sprintf( __( '%1$s near %2$s, %3$s', 'packout-core' ), $category, round( (float) $fields['lat'], 4 ), round( (float) $fields['lng'], 4 ) );
		}
		/* translators: %s: category name. */
		return sprintf( __( '%s report', 'packout-core' ), $category );
	}

	/**
	 * A category slug from a slug, a name, or a term id.
	 *
	 * @param mixed $value Raw category.
	 */
	private static function category_slug( $value ): string {
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return '';
		}
		$value = (string) $value;
		foreach ( array( 'slug', 'name', 'id' ) as $by ) {
			if ( 'id' === $by && ! ctype_digit( $value ) ) {
				continue;
			}
			$term = get_term_by( $by, 'id' === $by ? (int) $value : $value, Schema::CATEGORY );
			if ( $term instanceof \WP_Term ) {
				return $term->slug;
			}
		}
		return '';
	}

	/**
	 * Change one meta field and record the change.
	 *
	 * @param int    $id    Spot ID.
	 * @param string $key   Field name without the prefix.
	 * @param string $value New value.
	 */
	private static function set_field( int $id, string $key, string $value ): bool {
		if ( ! self::get( $id ) ) {
			return false;
		}
		if ( 'location_kind' === $key && '' === Vocabularies::location_shape( $value ) ) {
			return false;
		}
		if ( self::field( $id, $key ) !== $value ) {
			update_post_meta( $id, '_pos_' . $key, $value );
			self::touch( $id );
		}
		$terms = array(
			'status'        => Vocabularies::STAGE,
			'hazard'        => Vocabularies::HAZARD,
			'location_kind' => Vocabularies::LOCATION_KIND,
			'precision'     => Vocabularies::PRECISION,
		);
		if ( isset( $terms[ $key ] ) ) {
			Vocabularies::assign( $id, $terms[ $key ], $value );
		}
		return true;
	}

	/**
	 * Change the post status and record the change.
	 *
	 * @param int    $id     Spot ID.
	 * @param string $status Post status.
	 */
	private static function set_post_status( int $id, string $status ): bool {
		$post = self::get( $id );
		if ( ! $post ) {
			return false;
		}
		if ( $post->post_status !== $status ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => $status,
				)
			);
			self::touch( $id );
		}
		return true;
	}

	/**
	 * Raise the revision counter.
	 *
	 * The client revision follows only when the reporter's device made the
	 * change. A gap between the two means someone else has edited the Spot
	 * since the device last wrote it.
	 *
	 * @param int  $id        Spot ID.
	 * @param bool $by_client True when the reporter's device made the change.
	 */
	private static function touch( int $id, bool $by_client = false ): void {
		$next = self::revision( $id ) + 1;
		update_post_meta( $id, '_pos_revision', $next );
		if ( $by_client ) {
			update_post_meta( $id, '_pos_client_revision', $next );
		}
	}
}
