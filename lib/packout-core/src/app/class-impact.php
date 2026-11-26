<?php
/**
 * Impact records.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An impact record is the evidence that work happened at one Spot.
 *
 * There are three kinds. A cleanup record holds the figures of one cleaning:
 * hours, people, bags, pounds, and the photos taken afterward. A Spot has one
 * cleanup record for each time it was cleaned. The two return visits, "still
 * clear" and "it's back", are records too, each with its own description and
 * photos. Nothing is deleted when a problem returns.
 *
 * The narrative is the post content. A record is `private` until a coordinator
 * counts it in the public totals, which sets it to `publish`. A record has no
 * public page.
 *
 * Bags and pounds each carry a source, self-reported or measured, and the
 * totals never add the two together. Verification is a separate act.
 */
class Impact {

	public const CLEANUP     = 'cleanup';
	public const STILL_CLEAR = 'still_clear';
	public const RETURNED    = 'returned';

	/**
	 * Cleanup record of each Spot's current cleaning, for this request.
	 *
	 * @var array<int, int>
	 */
	private static array $current = array();

	/**
	 * Spots known to have no record while a long list is built, by ID.
	 *
	 * @var array<int, bool>
	 */
	private static array $bare = array();

	/**
	 * Whether sums leave out the work on Spots taken on from packout.spot.
	 *
	 * @var bool
	 */
	private static bool $apart = false;

	/**
	 * Sum without the work on Spots taken on from packout.spot, or with it again. What a partner site sends the hub leaves it out, so it is counted once, there.
	 *
	 * @param bool $apart Whether to leave it out.
	 */
	public static function apart( bool $apart ): void {
		self::$apart = $apart;
	}

	/**
	 * Kinds of record, with labels.
	 *
	 * @return array<string, string>
	 */
	public static function kinds(): array {
		return Vocabularies::labels( Vocabularies::IMPACT_KIND );
	}

	/**
	 * Where a bag count or a weight came from.
	 *
	 * @return array<string, string>
	 */
	public static function sources(): array {
		return Vocabularies::labels( Vocabularies::IMPACT_SOURCE );
	}

	/**
	 * Verification states, with labels.
	 *
	 * @return array<string, string>
	 */
	public static function verifications(): array {
		return Vocabularies::labels( Vocabularies::VERIFICATION );
	}

	/**
	 * An impact record, or null.
	 *
	 * @param int $id Post ID.
	 */
	public static function get( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && Schema::IMPACT === $post->post_type && 'trash' !== $post->post_status ? $post : null;
	}

	/**
	 * One stored field.
	 *
	 * @param int    $id      Record ID.
	 * @param string $key     Field name without the prefix.
	 * @param string $fallback Value when nothing is stored.
	 */
	public static function field( int $id, string $key, string $fallback = '' ): string {
		$value = get_post_meta( $id, '_pos_' . $key, true );
		return is_scalar( $value ) && '' !== (string) $value ? (string) $value : $fallback;
	}

	/**
	 * Kind of a record.
	 *
	 * @param int $id Record ID.
	 */
	public static function kind( int $id ): string {
		$kind = self::field( $id, 'kind', self::CLEANUP );
		return isset( self::kinds()[ $kind ] ) ? $kind : self::CLEANUP;
	}

	/**
	 * Whether a record is verified.
	 *
	 * @param int $id Record ID.
	 */
	public static function verified( int $id ): bool {
		return 'verified' === self::field( $id, 'verification', 'unverified' );
	}

	/**
	 * Forget what this request looked up.
	 */
	public static function flush(): void {
		self::$current = array();
	}

	/**
	 * The cleanup record of a Spot's current cleaning, or null.
	 *
	 * A Spot that was cleaned, came back, and was cleaned again has two
	 * cleanup records. The time of the cleaning tells them apart.
	 *
	 * @param int $spot_id Spot ID.
	 */
	public static function current( int $spot_id ): ?\WP_Post {
		if ( ! isset( self::$current[ $spot_id ] ) ) {
			$cycle = Spots::field( $spot_id, 'cleaned_at' );
			$found = '' === $cycle ? array() : get_posts(
				array(
					'post_type'   => Schema::IMPACT,
					'post_status' => array( 'publish', 'private' ),
					'numberposts' => 1,
					'fields'      => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One Spot's records.
					'meta_query'  => array(
						array(
							'key'   => '_pos_spot',
							'value' => $spot_id,
						),
						array(
							'key'   => '_pos_kind',
							'value' => self::CLEANUP,
						),
						array(
							'key'   => '_pos_cycle',
							'value' => $cycle,
						),
					),
				)
			);
			self::$current[ $spot_id ] = $found ? (int) $found[0] : 0;
		}//end if
		return self::get( self::$current[ $spot_id ] );
	}

	/**
	 * Find out in one query which of these Spots have no record, so that a
	 * long list does not ask once for each. Called with none, it forgets.
	 *
	 * What it learned holds only while the list is being built: a record
	 * made later in the same request must be found.
	 *
	 * @param array<int, int> $spots Spot IDs.
	 */
	public static function expect( array $spots ): void {
		self::$bare = array();
		if ( ! $spots ) {
			return;
		}
		$with = array();
		foreach ( array_chunk( $spots, 500 ) as $some ) {
			$records = get_posts(
				array(
					'post_type'   => Schema::IMPACT,
					'post_status' => array( 'publish', 'private' ),
					'numberposts' => -1,
					'fields'      => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The records of the Spots in one answer.
					'meta_query'  => array(
						array(
							'key'     => '_pos_spot',
							'value'   => array_map( 'strval', $some ),
							'compare' => 'IN',
						),
					),
				)
			);
			update_meta_cache( 'post', $records );
			foreach ( $records as $record ) {
				$with[ (int) get_post_meta( $record, '_pos_spot', true ) ] = true;
			}
		}//end foreach
		foreach ( $spots as $spot ) {
			if ( ! isset( $with[ $spot ] ) ) {
				self::$bare[ $spot ] = true;
			}
		}
	}

	/**
	 * Every record of a Spot, newest first.
	 *
	 * @param int $spot_id Spot ID.
	 * @return array<int, \WP_Post>
	 */
	public static function of_spot( int $spot_id ): array {
		if ( isset( self::$bare[ $spot_id ] ) ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'   => Schema::IMPACT,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 100,
				'orderby'     => 'ID',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One Spot's records.
				'meta_key'    => '_pos_spot',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One Spot's records.
				'meta_value'  => $spot_id,
			)
		);
	}

	/**
	 * A person's record with this client id, or null.
	 *
	 * @param string $client_id Idempotency key from the field app.
	 * @param int    $user      User ID.
	 */
	public static function by_client( string $client_id, int $user ): ?\WP_Post {
		if ( '' === $client_id ) {
			return null;
		}
		$found = get_posts(
			array(
				'post_type'   => Schema::IMPACT,
				'post_status' => array( 'publish', 'private' ),
				'author'      => $user,
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
	 * Whether a person coordinates the whole site's records.
	 *
	 * @param int $user User ID, or -1 for the current user.
	 */
	public static function coordinates( int $user = -1 ): bool {
		$user = $user < 0 ? get_current_user_id() : $user;
		return Capabilities::can( Capabilities::IMPACT, $user ) && Capabilities::can( Capabilities::TEAM, $user );
	}

	/**
	 * Whether a person may open the Impact screen.
	 *
	 * @param int $user User ID, or -1 for the current user.
	 */
	public static function can_review( int $user = -1 ): bool {
		return Capabilities::can( Capabilities::IMPACT, $user < 0 ? get_current_user_id() : $user );
	}

	/**
	 * Whether a person may see a record on the Impact screen.
	 *
	 * A coordinator sees every record. A lead sees the records of the teams
	 * they lead, the records with no team, and their own.
	 *
	 * @param int $id   Record ID.
	 * @param int $user User ID, or -1 for the current user.
	 */
	public static function can_see( int $id, int $user = -1 ): bool {
		$user = $user < 0 ? get_current_user_id() : $user;
		$post = self::get( $id );
		if ( ! $post || ! self::can_review( $user ) ) {
			return false;
		}
		$team = (int) self::field( $id, 'team', '0' );
		return self::coordinates( $user ) || 0 === $team || (int) $post->post_author === $user || Missions::can_lead_team( $team, $user );
	}

	/**
	 * Whether a person may verify a record, or return it to unverified.
	 *
	 * The person does not have to be someone who did the work, and may be: a
	 * lead can verify their own crew's record. The record says so.
	 *
	 * @param int $id   Record ID.
	 * @param int $user User ID, or -1 for the current user.
	 */
	public static function can_verify( int $id, int $user = -1 ): bool {
		return self::can_see( $id, $user );
	}

	/**
	 * Whether a person may change the figures of a record.
	 *
	 * Until it is verified: the person who entered it, the lead of its
	 * mission, or a coordinator. Afterward: the people who may verify it.
	 *
	 * @param int $id   Record ID.
	 * @param int $user User ID, or -1 for the current user.
	 */
	public static function can_edit( int $id, int $user = -1 ): bool {
		$user = $user < 0 ? get_current_user_id() : $user;
		$post = self::get( $id );
		if ( ! $post || $user <= 0 ) {
			return false;
		}
		if ( self::verified( $id ) ) {
			return self::can_verify( $id, $user );
		}
		$mission = (int) self::field( $id, 'mission', '0' );
		return (int) $post->post_author === $user
			|| (int) self::field( $id, 'user', '0' ) === $user
			|| ( $mission > 0 && Missions::can_manage( $mission, $user ) )
			|| self::coordinates( $user );
	}

	/**
	 * Whether the person who verified a record also did or led the work.
	 *
	 * @param int $id Record ID.
	 */
	public static function own_work( int $id ): bool {
		$post = self::get( $id );
		$by   = (int) self::field( $id, 'verified_by', '0' );
		if ( ! $post || $by <= 0 || ! self::verified( $id ) ) {
			return false;
		}
		$team = (int) self::field( $id, 'team', '0' );
		return (int) $post->post_author === $by
			|| (int) self::field( $id, 'user', '0' ) === $by
			|| in_array( (string) $by, array_map( 'strval', get_post_meta( $id, '_pos_person', false ) ), true )
			|| ( $team > 0 && ( Teams::lead( $team ) === $by || Teams::includes( $team, $by ) ) );
	}

	/**
	 * Record the work done at a Spot, or change the record of its current cleaning.
	 *
	 * A Spot a mission cleaned is recorded by that mission's lead or a
	 * coordinator. A reporter records their own Spot, which marks it cleaned
	 * when it is not yet. A Spot flagged to be left in place is refused.
	 *
	 * Missing keys keep what is stored.
	 *
	 * @param int                  $spot_id   Spot ID.
	 * @param array<string, mixed> $input     narrative, date, hours, people, persons, bags, bags_source, pounds, pounds_source, come_back, come_back_note.
	 * @param int                  $user      Who is recording it.
	 * @param string               $client_id Idempotency key from the field app.
	 * @return int|\WP_Error Record ID.
	 */
	public static function save_cleanup( int $spot_id, array $input, int $user, string $client_id = '' ) {
		$spot = Spots::get( $spot_id );
		if ( ! $spot || 'trash' === $spot->post_status ) {
			return new \WP_Error( 'packout_not_found', __( 'That Spot does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$owner = (int) $spot->post_author === $user;

		if ( 'cleaned' !== Spots::field( $spot_id, 'status', 'spotted' ) ) {
			if ( ! $owner && ! Capabilities::can( Capabilities::MODERATE, $user ) ) {
				return new \WP_Error( 'packout_forbidden', __( 'Record the Spot as cleaned on its mission first. You can record your own Spots without one.', 'packout-core' ), array( 'status' => 403 ) );
			}
			$done = Spots::mark_cleaned( $spot_id, $user, 0 );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			self::flush();
		}

		$mission = (int) Spots::field( $spot_id, 'cleaned_mission', '0' );
		$by      = (int) Spots::field( $spot_id, 'cleaned_by', '0' );
		$allowed = self::coordinates( $user ) || ( $mission > 0 ? Missions::can_manage( $mission, $user ) : ( $owner || $by === $user ) );
		if ( ! $allowed ) {
			return new \WP_Error( 'packout_forbidden', __( 'The lead of the mission that cleaned this Spot records its impact.', 'packout-core' ), array( 'status' => 403 ) );
		}

		$existing = self::current( $spot_id );
		if ( $existing ) {
			return self::update( $existing->ID, $input, $user );
		}

		$team = $mission > 0 ? Missions::team( $mission ) : 0;
		$id   = self::insert( $spot, self::CLEANUP, $user );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::set(
			$id,
			array(
				'mission'       => $mission,
				'team'          => $team,
				'user'          => $by > 0 ? $by : $user,
				'cycle'         => Spots::field( $spot_id, 'cleaned_at' ),
				'date'          => wp_date( 'Y-m-d' ),
				'client_id'     => $client_id,
				'verification'  => 'unverified',
				'unverified_at' => gmdate( 'c' ),
			)
		);
		self::write_figures( $id, $input, $team );
		self::flush();
		self::announce( $id );
		return $id;
	}

	/**
	 * Change the figures of one cleanup record.
	 *
	 * @param int                  $id    Record ID.
	 * @param array<string, mixed> $input The same keys as save_cleanup(). Missing keys keep what is stored.
	 * @param int                  $user  Who is changing it.
	 * @return int|\WP_Error Record ID.
	 */
	public static function update( int $id, array $input, int $user ) {
		if ( ! self::get( $id ) || self::CLEANUP !== self::kind( $id ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That record does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_edit( $id, $user ) ) {
			return new \WP_Error( 'packout_impact_verified', __( 'This record has been verified. A lead or coordinator can return it to unverified before it is changed.', 'packout-core' ), array( 'status' => 409 ) );
		}
		self::write_figures( $id, $input, (int) self::field( $id, 'team', '0' ) );
		return $id;
	}

	/**
	 * File a return visit to a cleaned Spot.
	 *
	 * "Still clear" is evidence that the improvement held and leaves the Spot
	 * cleaned. "It's back" moves the Spot to watch and opens it for a new
	 * mission. The earlier records stay as they are.
	 *
	 * @param int    $spot_id     Spot ID.
	 * @param string $result      still_clear or returned.
	 * @param string $description What the visitor found.
	 * @param int    $user        Who visited.
	 * @param string $client_id   Idempotency key from the field app.
	 * @return int|\WP_Error Record ID.
	 */
	public static function save_visit( int $spot_id, string $result, string $description, int $user, string $client_id = '' ) {
		$sent = self::by_client( $client_id, $user );
		if ( $sent ) {
			return $sent->ID;
		}

		$spot = Spots::get( $spot_id );
		if ( ! $spot || null === Spots::view_for( $spot, $user ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That Spot does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $result, array( self::STILL_CLEAR, self::RETURNED ), true ) ) {
			return new \WP_Error( 'packout_visit_result', __( 'Say whether the place is still clear or the problem is back.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$description = sanitize_textarea_field( $description );
		if ( '' === $description ) {
			return new \WP_Error( 'packout_visit_description', __( 'Describe what you found.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$status = Spots::field( $spot_id, 'status', 'spotted' );
		if ( 'cleaned' !== $status ) {
			return new \WP_Error(
				'packout_visit_state',
				'watch' === $status ? __( 'This Spot is already marked as needing attention again.', 'packout-core' ) : __( 'A return visit is for a Spot that has been cleaned.', 'packout-core' ),
				array( 'status' => 409 )
			);
		}

		$id = self::insert( $spot, $result, $user, $description );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$mission = (int) Spots::field( $spot_id, 'cleaned_mission', '0' );
		self::set(
			$id,
			array(
				'mission'       => 0,
				// The team whose cleanup this visit speaks about, so a team's outcomes can be counted.
				'team'          => $mission > 0 ? Missions::team( $mission ) : 0,
				'user'          => $user,
				'cycle'         => Spots::field( $spot_id, 'cleaned_at' ),
				'date'          => wp_date( 'Y-m-d' ),
				'client_id'     => $client_id,
				'verification'  => 'unverified',
				'unverified_at' => gmdate( 'c' ),
			)
		);

		if ( self::RETURNED === $result ) {
			Spots::mark_returned( $spot_id );
		}
		self::announce( $id );
		return $id;
	}

	/**
	 * Verify a record.
	 *
	 * @param int $id   Record ID.
	 * @param int $user Who verifies.
	 * @return true|\WP_Error
	 */
	public static function verify( int $id, int $user ) {
		if ( ! self::can_verify( $id, $user ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'You cannot verify this record.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( self::verified( $id ) ) {
			return true;
		}
		self::set(
			$id,
			array(
				'verification' => 'verified',
				'verified_by'  => $user,
				'verified_at'  => gmdate( 'c' ),
			)
		);
		self::announce( $id );
		return true;
	}

	/**
	 * Return a verified record to unverified.
	 *
	 * @param int $id   Record ID.
	 * @param int $user Who returns it.
	 * @return true|\WP_Error
	 */
	public static function unverify( int $id, int $user ) {
		if ( ! self::can_verify( $id, $user ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'You cannot change the verification of this record.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! self::verified( $id ) ) {
			return true;
		}
		update_post_meta( $id, '_pos_verification', 'unverified' );
		Vocabularies::assign( $id, Vocabularies::VERIFICATION, 'unverified' );
		delete_post_meta( $id, '_pos_verified_by' );
		delete_post_meta( $id, '_pos_verified_at' );
		update_post_meta( $id, '_pos_unverified_at', gmdate( 'c' ) );
		self::announce( $id );
		return true;
	}

	/**
	 * Stamp the Spot when its current cleanup record is about to leave.
	 *
	 * The overview ages a missing-record queue from this moment, not from
	 * the original cleaning, because the Spot re-entered the queue.
	 *
	 * @param int $id Record ID.
	 */
	public static function remember_unrecorded( int $id ): void {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || Schema::IMPACT !== $post->post_type ) {
			return;
		}
		if ( self::CLEANUP !== self::kind( $id ) ) {
			return;
		}
		$spot  = (int) self::field( $id, 'spot', '0' );
		$cycle = self::field( $id, 'cycle' );
		if ( $spot <= 0 || '' === $cycle || Spots::field( $spot, 'cleaned_at' ) !== $cycle ) {
			return;
		}
		update_post_meta( $spot, '_pos_unrecorded_since', gmdate( 'c' ) );
	}

	/**
	 * Count a record in the public totals, or stop counting it.
	 *
	 * @param int  $id      Record ID.
	 * @param bool $visible Whether the public totals include it.
	 * @param int  $user    Who decides. Coordinators only.
	 * @return true|\WP_Error
	 */
	public static function set_visible( int $id, bool $visible, int $user ) {
		$post = self::get( $id );
		if ( ! $post || ! self::coordinates( $user ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'A coordinator decides which records the public totals include.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$status = $visible ? 'publish' : 'private';
		if ( $post->post_status !== $status ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => $status,
				)
			);
		}
		return true;
	}

	/**
	 * Whether the public totals include a record.
	 *
	 * @param int $id Record ID.
	 */
	public static function visible( int $id ): bool {
		return 'publish' === get_post_status( $id );
	}

	/**
	 * Add a photo taken after the work, or on a return visit.
	 *
	 * @param int                  $id              Record ID.
	 * @param array<string, mixed> $file            One entry from the request files.
	 * @param int                  $user            Who adds it.
	 * @param string               $client_photo_id Idempotency key from the field app.
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function add_photo( int $id, array $file, int $user, string $client_photo_id = '' ) {
		if ( ! self::can_edit( $id, $user ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'You cannot add photos to this record.', 'packout-core' ), array( 'status' => 403 ) );
		}
		return Photos::attach_upload( $id, $file, $client_photo_id );
	}

	/**
	 * A record for the Impact screen and the field app.
	 *
	 * @param \WP_Post $post Record.
	 * @return array<string, mixed>
	 */
	public static function payload( \WP_Post $post ): array {
		$id      = $post->ID;
		$spot    = Spots::get( (int) self::field( $id, 'spot', '0' ) );
		$team    = Teams::get( (int) self::field( $id, 'team', '0' ) );
		$kind    = self::kind( $id );
		$checker = get_userdata( (int) self::field( $id, 'verified_by', '0' ) );
		$actor   = get_userdata( (int) self::field( $id, 'user', '0' ) );
		$sources = self::sources();

		return array(
			'id'             => $id,
			'client_id'      => self::field( $id, 'client_id' ),
			'kind'           => $kind,
			'kind_label'     => self::kinds()[ $kind ],
			'spot'           => $spot ? $spot->ID : 0,
			'spot_title'     => $spot ? $spot->post_title : __( 'A Spot that was removed', 'packout-core' ),
			'mission'        => (int) self::field( $id, 'mission', '0' ),
			'team'           => $team ? $team->ID : 0,
			'team_name'      => $team ? $team->post_title : '',
			'person'         => $actor ? $actor->display_name : '',
			'date'           => self::field( $id, 'date' ),
			'narrative'      => $post->post_content,
			'hours'          => (float) self::field( $id, 'hours', '0' ),
			'people'         => (int) self::field( $id, 'people', '0' ),
			'bags'           => (int) self::field( $id, 'bags', '0' ),
			'bags_source'    => isset( $sources[ self::field( $id, 'bags_source' ) ] ) ? self::field( $id, 'bags_source' ) : 'self_reported',
			'pounds'         => (float) self::field( $id, 'pounds', '0' ),
			'pounds_source'  => isset( $sources[ self::field( $id, 'pounds_source' ) ] ) ? self::field( $id, 'pounds_source' ) : 'self_reported',
			'come_back'      => '1' === self::field( $id, 'return' ),
			'come_back_note' => self::field( $id, 'return_note' ),
			'verification'   => self::verified( $id ) ? 'verified' : 'unverified',
			'verified_by'    => $checker ? $checker->display_name : '',
			'verified_at'    => self::field( $id, 'verified_at' ),
			'own_work'       => self::own_work( $id ),
			'visible'        => 'publish' === $post->post_status,
			'photos'         => count( Photos::for_spot( $id ) ),
		);
	}

	/**
	 * What the field app keeps about a person's own records.
	 *
	 * @param int $user User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function mine( int $user ): array {
		$found = get_posts(
			array(
				'post_type'   => Schema::IMPACT,
				'post_status' => array( 'publish', 'private' ),
				'author'      => $user,
				'numberposts' => 200,
				'orderby'     => 'ID',
				'order'       => 'DESC',
			)
		);
		return array_map( array( self::class, 'payload' ), $found );
	}

	/**
	 * Records for the Impact screen.
	 *
	 * @param array<string, mixed> $filters verification, kind, team, from, to.
	 * @param int                  $user    User ID, or -1 for the current user.
	 * @return array<int, \WP_Post>
	 */
	public static function listed( array $filters = array(), int $user = -1 ): array {
		$user  = $user < 0 ? get_current_user_id() : $user;
		$found = get_posts(
			array(
				'post_type'   => Schema::IMPACT,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 500,
				'orderby'     => 'ID',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The screen's filters.
				'meta_query'  => self::conditions( $filters ),
			)
		);
		return array_values(
			array_filter(
				$found,
				static fn( \WP_Post $post ): bool => self::can_see( $post->ID, $user )
			)
		);
	}

	/**
	 * Every record that matches, whoever is asking. For the totals of a Place and for the export.
	 *
	 * @param array<string, mixed> $filters verification, kind, team, missions, spots, from, to.
	 * @return array<int, \WP_Post>
	 */
	public static function found( array $filters = array() ): array {
		return get_posts(
			array(
				'post_type'   => Schema::IMPACT,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Date range, Place, and kind.
				'meta_query'  => self::conditions( $filters ),
			)
		);
	}

	/**
	 * Sums for a date range, a team, a Place, or the whole site.
	 *
	 * Every figure comes from records. Self-reported and measured amounts
	 * stay apart, and so do verified and unverified hours. With `visible`
	 * set, only the cleanup records a coordinator counts publicly are summed,
	 * and return visits are counted for Spots that are on the public map.
	 *
	 * @param array<string, mixed> $args from, to (Y-m-d), team, teams, missions, spots, visible.
	 * @return array<string, array<string, int|float>>
	 */
	public static function totals( array $args = array() ): array {
		$public = ! empty( $args['visible'] );
		$apart  = self::$apart;
		$ids    = get_posts(
			array(
				'post_type'   => Schema::IMPACT,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => -1,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Date range and team.
				'meta_query'  => self::conditions( $args ),
			)
		);
		update_meta_cache( 'post', $ids );

		$hours    = array(
			'verified'   => 0.0,
			'unverified' => 0.0,
		);
		$people   = 0;
		$bags     = array(
			'measured'      => 0,
			'self_reported' => 0,
		);
		$pounds   = array(
			'measured'      => 0.0,
			'self_reported' => 0.0,
		);
		$spots    = array();
		$missions = array();
		$teams    = array();
		$clear    = array();
		$back     = array();
		$records  = 0;
		$verified = 0;

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$spot = (int) self::field( $id, 'spot', '0' );
			$kind = self::kind( $id );

			// Work on a Spot taken on from packout.spot is counted there, from its record there.
			if ( $apart && '' !== (string) get_post_meta( $spot, Exchange::ADOPTED, true ) ) {
				continue;
			}
			// So is what an organization did before its work moved here from packout.spot: the hub counts that from its own records.
			if ( $apart ) {
				$since = Connection::claim_day( (int) self::field( $id, 'team', '0' ) );
				if ( '' !== $since && self::field( $id, 'date' ) < $since ) {
					continue;
				}
			}
			// On packout.spot, the days after an organization's work moved to its own site are counted from what that site reports.
			if ( Remote::gone( (int) self::field( $id, 'team', '0' ), self::field( $id, 'date' ) ) ) {
				continue;
			}
			if ( self::CLEANUP !== $kind ) {
				if ( $public && 'publish' !== get_post_status( $spot ) ) {
					continue;
				}
				if ( self::STILL_CLEAR === $kind ) {
					$clear[ $spot ] = true;
				} else {
					$back[ $spot ] = true;
				}
				continue;
			}
			if ( $public && 'publish' !== get_post_status( $id ) ) {
				continue;
			}

			++$records;
			$checked                                        = self::verified( $id );
			$verified                                      += $checked ? 1 : 0;
			$hours[ $checked ? 'verified' : 'unverified' ] += (float) self::field( $id, 'hours', '0' );
			$people                                        += (int) self::field( $id, 'people', '0' );
			$bags_from                                      = 'measured' === self::field( $id, 'bags_source' ) ? 'measured' : 'self_reported';
			$pounds_from                                    = 'measured' === self::field( $id, 'pounds_source' ) ? 'measured' : 'self_reported';
			$bags[ $bags_from ]                            += (int) self::field( $id, 'bags', '0' );
			$pounds[ $pounds_from ]                        += (float) self::field( $id, 'pounds', '0' );
			$spots[ $spot ]                                 = true;

			$mission = (int) self::field( $id, 'mission', '0' );
			if ( $mission > 0 && 'completed' === Missions::status( $mission ) ) {
				$missions[ $mission ] = true;
			}
			$team = (int) self::field( $id, 'team', '0' );
			if ( $team > 0 ) {
				$teams[ $team ] = true;
			}
		}//end foreach

		return array(
			'records'  => array(
				'cleanups' => $records,
				'verified' => $verified,
			),
			'effort'   => array(
				'hours_verified'   => round( $hours['verified'], 1 ),
				'hours_unverified' => round( $hours['unverified'], 1 ),
				'people'           => $people,
				'missions'         => count( $missions ),
				'teams'            => count( $teams ),
			),
			'outputs'  => array(
				'spots'                => count( $spots ),
				'bags_measured'        => $bags['measured'],
				'bags_self_reported'   => $bags['self_reported'],
				'pounds_measured'      => round( $pounds['measured'], 1 ),
				'pounds_self_reported' => round( $pounds['self_reported'], 1 ),
			),
			'outcomes' => array(
				'still_clear' => count( $clear ),
				'returned'    => count( $back ),
			),
		);
	}

	/**
	 * Cleaned Spots with no record of the work yet.
	 *
	 * The totals count records, so a cleaning nobody wrote up is missing from
	 * them. This is the list of what is missing.
	 *
	 * @param int $user User ID, or -1 for the current user.
	 * @return array<int, \WP_Post>
	 */
	public static function unrecorded( int $user = -1 ): array {
		$user  = $user < 0 ? get_current_user_id() : $user;
		$spots = get_posts(
			array(
				'post_type'   => Schema::SPOT,
				'post_status' => array( 'pending', 'publish', 'private' ),
				'numberposts' => 200,
				'orderby'     => 'modified',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Spots that were cleaned.
				'meta_key'    => '_pos_status',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Spots that were cleaned.
				'meta_value'  => 'cleaned',
			)
		);
		return array_values(
			array_filter(
				$spots,
				static function ( \WP_Post $spot ) use ( $user ): bool {
					if ( self::current( $spot->ID ) ) {
						return false;
					}
					$mission = (int) Spots::field( $spot->ID, 'cleaned_mission', '0' );
					return self::coordinates( $user ) || ( $mission > 0 && Missions::can_manage( $mission, $user ) );
				}
			)
		);
	}

	/**
	 * A sentence of figures for a list: "6 bags measured · 180 lb self-reported · 12 h".
	 *
	 * @param int $id Record ID.
	 */
	public static function summary( int $id ): string {
		if ( self::CLEANUP !== self::kind( $id ) ) {
			return '';
		}
		$sources = self::sources();
		$parts   = array();
		$bags    = (int) self::field( $id, 'bags', '0' );
		$pounds  = (float) self::field( $id, 'pounds', '0' );
		$hours   = (float) self::field( $id, 'hours', '0' );
		// Whether each figure was measured or is the person's own estimate.
		$bags_from   = 'measured' === self::field( $id, 'bags_source' ) ? 'measured' : 'self_reported';
		$pounds_from = 'measured' === self::field( $id, 'pounds_source' ) ? 'measured' : 'self_reported';
		if ( $bags > 0 ) {
			/* translators: 1: number of bags, 2: self-reported or measured. */
			$parts[] = sprintf( _n( '%1$s bag, %2$s', '%1$s bags, %2$s', $bags, 'packout-core' ), number_format_i18n( $bags ), strtolower( $sources[ $bags_from ] ) );
		}
		if ( $pounds > 0 ) {
			/* translators: 1: weight in pounds, 2: self-reported or measured. */
			$parts[] = sprintf( __( '%1$s lb, %2$s', 'packout-core' ), self::number( $pounds ), strtolower( $sources[ $pounds_from ] ) );
		}
		if ( $hours > 0 ) {
			/* translators: %s: number of hours. */
			$parts[] = sprintf( __( '%s h', 'packout-core' ), self::number( $hours ) );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * A number without a trailing ".0".
	 *
	 * @param float $value Number.
	 */
	public static function number( float $value ): string {
		return number_format_i18n( $value, floor( $value ) === $value ? 0 : 1 );
	}

	/**
	 * Meta conditions for a date range, a team, a kind, and a verification state.
	 *
	 * @param array<string, mixed> $filters verification, kind, team, missions, spots, from, to.
	 * @return array<int|string, mixed>
	 */
	private static function conditions( array $filters ): array {
		$query = array();
		$from  = self::date( (string) ( $filters['from'] ?? '' ) );
		$to    = self::date( (string) ( $filters['to'] ?? '' ) );
		if ( '' !== $from ) {
			$query[] = array(
				'key'     => '_pos_date',
				'value'   => $from,
				'compare' => '>=',
			);
		}
		if ( '' !== $to ) {
			$query[] = array(
				'key'     => '_pos_date',
				'value'   => $to,
				'compare' => '<=',
			);
		}
		if ( ! empty( $filters['team'] ) ) {
			$query[] = array(
				'key'   => '_pos_team',
				'value' => (int) $filters['team'],
			);
		}
		if ( array_key_exists( 'teams', $filters ) ) {
			// The records of a partner's organizations. An empty list matches nothing.
			$query[] = array(
				'key'     => '_pos_team',
				'value'   => array_map( 'intval', (array) $filters['teams'] ) ? array_map( 'intval', (array) $filters['teams'] ) : array( -1 ),
				'compare' => 'IN',
			);
		}
		if ( array_key_exists( 'missions', $filters ) ) {
			// The records of a campaign's missions. An empty list matches nothing.
			$query[] = array(
				'key'     => '_pos_mission',
				'value'   => array_map( 'intval', (array) $filters['missions'] ) ? array_map( 'intval', (array) $filters['missions'] ) : array( -1 ),
				'compare' => 'IN',
			);
		}
		if ( array_key_exists( 'spots', $filters ) ) {
			// The records of a Place's Spots. An empty list matches nothing.
			$query[] = array(
				'key'     => '_pos_spot',
				'value'   => array_map( 'intval', (array) $filters['spots'] ) ? array_map( 'intval', (array) $filters['spots'] ) : array( -1 ),
				'compare' => 'IN',
			);
		}
		if ( isset( self::kinds()[ (string) ( $filters['kind'] ?? '' ) ] ) ) {
			$query[] = array(
				'key'   => '_pos_kind',
				'value' => (string) $filters['kind'],
			);
		}
		if ( isset( self::verifications()[ (string) ( $filters['verification'] ?? '' ) ] ) ) {
			$query[] = array(
				'key'   => '_pos_verification',
				'value' => (string) $filters['verification'],
			);
		}
		return $query;
	}

	/**
	 * A calendar date, or an empty string.
	 *
	 * @param string $value Y-m-d.
	 */
	public static function date( string $value ): string {
		$value = trim( $value );
		$parts = explode( '-', $value );
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ? $value : '';
	}

	/**
	 * Create the post of a record.
	 *
	 * @param \WP_Post $spot      The Spot.
	 * @param string   $kind      Kind of record.
	 * @param int      $user      Who enters it.
	 * @param string   $narrative What changed, or what the visitor found.
	 * @return int|\WP_Error
	 */
	private static function insert( \WP_Post $spot, string $kind, int $user, string $narrative = '' ) {
		$id = wp_insert_post(
			array(
				'post_type'    => Schema::IMPACT,
				'post_status'  => 'private',
				'post_author'  => $user,
				/* translators: 1: kind of record, 2: Spot title. */
				'post_title'   => wp_slash( sprintf( __( '%1$s: %2$s', 'packout-core' ), self::kinds()[ $kind ], $spot->post_title ) ),
				'post_content' => wp_slash( $narrative ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::set(
			$id,
			array(
				'spot' => $spot->ID,
				'kind' => $kind,
			)
		);
		return (int) $id;
	}

	/**
	 * Store the figures of a cleanup record.
	 *
	 * @param int                  $id    Record ID.
	 * @param array<string, mixed> $input Raw values. Missing keys keep what is stored.
	 * @param int                  $team  Team ID, or 0.
	 */
	private static function write_figures( int $id, array $input, int $team ): void {
		$sources = self::sources();
		$fields  = array();

		if ( array_key_exists( 'narrative', $input ) ) {
			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => wp_slash( sanitize_textarea_field( (string) $input['narrative'] ) ),
				)
			);
		}
		if ( array_key_exists( 'date', $input ) ) {
			$date = self::date( (string) $input['date'] );
			// The work happened on a day that has already come.
			if ( '' !== $date && $date <= wp_date( 'Y-m-d' ) ) {
				$fields['date'] = $date;
			}
		}
		if ( array_key_exists( 'hours', $input ) ) {
			$fields['hours'] = round( min( 100000, max( 0, (float) $input['hours'] ) ), 2 );
		}
		if ( array_key_exists( 'people', $input ) ) {
			$fields['people'] = min( 10000, max( 0, (int) $input['people'] ) );
		}
		if ( array_key_exists( 'bags', $input ) ) {
			$fields['bags'] = min( 1000000, max( 0, (int) $input['bags'] ) );
		}
		if ( array_key_exists( 'pounds', $input ) ) {
			$fields['pounds'] = round( min( 10000000, max( 0, (float) $input['pounds'] ) ), 1 );
		}
		foreach ( array( 'bags_source', 'pounds_source' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$fields[ $key ] = isset( $sources[ (string) $input[ $key ] ] ) ? (string) $input[ $key ] : 'self_reported';
			} elseif ( '' === self::field( $id, $key ) ) {
				$fields[ $key ] = 'self_reported';
			}
		}
		if ( array_key_exists( 'come_back', $input ) ) {
			$fields['return'] = empty( $input['come_back'] ) ? '' : '1';
		}
		if ( array_key_exists( 'come_back_note', $input ) ) {
			$fields['return_note'] = sanitize_textarea_field( (string) $input['come_back_note'] );
		}
		self::set( $id, $fields );

		// The people the lead names are members of the team, or its lead.
		if ( array_key_exists( 'persons', $input ) && $team > 0 ) {
			$crew = array_merge( Teams::members( $team ), array( Teams::lead( $team ) ) );
			delete_post_meta( $id, '_pos_person' );
			foreach ( array_unique( array_map( 'intval', (array) $input['persons'] ) ) as $person ) {
				if ( in_array( $person, $crew, true ) ) {
					add_post_meta( $id, '_pos_person', $person );
				}
			}
		}
	}

	/**
	 * Store fields.
	 *
	 * @param int                  $id     Record ID.
	 * @param array<string, mixed> $fields Field name without the prefix => value.
	 */
	private static function set( int $id, array $fields ): void {
		foreach ( $fields as $key => $value ) {
			$stored = is_bool( $value ) ? ( $value ? '1' : '' ) : (string) $value;
			update_post_meta( $id, '_pos_' . $key, $stored );
			if ( 'kind' === $key ) {
				Vocabularies::assign( $id, Vocabularies::IMPACT_KIND, $stored );
			}
			if ( 'verification' === $key ) {
				Vocabularies::assign( $id, Vocabularies::VERIFICATION, $stored );
			}
			if ( in_array( $key, array( 'bags_source', 'pounds_source' ), true ) && '' !== $stored ) {
				Vocabularies::ensure_term( Vocabularies::IMPACT_SOURCE, $stored );
				$current = wp_get_object_terms( $id, Vocabularies::IMPACT_SOURCE, array( 'fields' => 'slugs' ) );
				$slugs   = is_array( $current ) ? $current : array();
				$slugs[] = $stored;
				wp_set_object_terms( $id, array_values( array_unique( $slugs ) ), Vocabularies::IMPACT_SOURCE );
			}
		}
	}

	/**
	 * Tell listeners that a record was saved or that its verification changed.
	 *
	 * It fires once when a record is first saved and again on each
	 * verification change. Later edits of the figures do not fire it.
	 * Listeners must tolerate repeats.
	 *
	 * @param int $id Record ID.
	 */
	private static function announce( int $id ): void {
		/**
		 * An impact record was saved, or its verification changed.
		 *
		 * @param int    $id           Record ID.
		 * @param string $verification unverified or verified.
		 */
		do_action( 'packout_impact_recorded', $id, self::verified( $id ) ? 'verified' : 'unverified' );
	}
}
