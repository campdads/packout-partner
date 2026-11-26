<?php
/**
 * Updates and comments people leave on a Spot.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A signed-in person can leave an update on a published Spot: what it looks
 * like now, how to reach it, what a crew should bring.
 *
 * An update is shown at once, in the activity on the Spot's page. It is
 * credited the way a report is: to an organization the person chose, or to
 * "an individual", with their name only when they asked. Its author or a
 * coordinator can remove it. The coordinators are told of each one, so an
 * update that should not stand is seen soon.
 *
 * The person who reported a Spot can also keep notes on it, from My account.
 * A note is an update that only its author sees. Ticking a box on it puts it
 * on the Spot's page with the other updates, and unticking takes it off.
 */
class Updates {

	public const TYPE  = 'pos_update';
	public const NONCE = 'packout_update';

	/**
	 * Longest update, in characters.
	 */
	public const LENGTH = 1000;

	/**
	 * Updates one person can leave in a day.
	 */
	public const DAILY = 10;

	/**
	 * Notes one person can keep on one Spot.
	 */
	public const NOTES = 50;

	/**
	 * Register the hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'type' ), 9 );
		add_action( 'init', array( self::class, 'handle' ), 31 );
		add_action( 'before_delete_post', array( self::class, 'spot_deleted' ) );
	}

	/**
	 * An update has no page of its own and is not in a list anywhere but on its Spot.
	 */
	public static function type(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Spot updates', 'packout-core' ),
					'singular_name' => __( 'Spot update', 'packout-core' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'supports'            => array( 'editor', 'author' ),
				'delete_with_user'    => true,
			)
		);
	}

	/**
	 * An update that is on its Spot's page, or null.
	 *
	 * @param int  $id  Post ID.
	 * @param bool $any True to find a note only its author sees as well.
	 */
	public static function get( int $id, bool $any = false ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && self::TYPE === $post->post_type && ( 'publish' === $post->post_status || ( $any && 'private' === $post->post_status ) ) ? $post : null;
	}

	/**
	 * Whether an update is on its Spot's page, and not a note its author keeps.
	 *
	 * @param \WP_Post $update Update.
	 */
	public static function shown( \WP_Post $update ): bool {
		return 'publish' === $update->post_status;
	}

	/**
	 * What a person wrote, cleaned, or what is wrong with it.
	 *
	 * @param string $text What they wrote.
	 * @param bool   $note True when it is a note, for the wording.
	 * @return string|\WP_Error
	 */
	private static function clean( string $text, bool $note = false ) {
		$text = trim( sanitize_textarea_field( $text ) );
		if ( '' === $text ) {
			return new \WP_Error( 'packout_update_empty', $note ? __( 'Write the note first.', 'packout-core' ) : __( 'Write the update first.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $text ) > self::LENGTH ) {
			return new \WP_Error(
				'packout_update_long',
				$note
					/* translators: %s: number of characters. */
					? sprintf( __( 'A note can be up to %s characters. Shorten it and save it again.', 'packout-core' ), number_format_i18n( self::LENGTH ) )
					/* translators: %s: number of characters. */
					: sprintf( __( 'An update can be up to %s characters. Shorten it and send it again.', 'packout-core' ), number_format_i18n( self::LENGTH ) ),
				array( 'status' => 400 )
			);
		}
		return $text;
	}

	/**
	 * Whether a person may put one more update on a page today. Coordinators are not limited.
	 *
	 * @param int $user User ID.
	 * @return true|\WP_Error
	 */
	private static function within_limit( int $user ) {
		if ( ! Capabilities::can( Capabilities::MODERATE, $user ) && ! Accounts::allow( 'update:' . $user, self::DAILY, DAY_IN_SECONDS ) ) {
			return new \WP_Error( 'packout_update_limit', __( 'That is enough updates for today. Come back tomorrow.', 'packout-core' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Whether a Spot or a map feature takes updates.
	 *
	 * A Spot does when it is published and is not a duplicate of another. A
	 * published map feature does.
	 *
	 * @param \WP_Post|null $post Spot or feature.
	 */
	public static function open( ?\WP_Post $post ): bool {
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}
		if ( Schema::FEATURE === $post->post_type ) {
			return true;
		}
		return Schema::SPOT === $post->post_type && '' === Spots::field( $post->ID, 'duplicate_of' );
	}

	/**
	 * The Spot or map feature an update is written on, or null.
	 *
	 * @param int $id Post ID.
	 */
	public static function subject( int $id ): ?\WP_Post {
		$spot = Spots::get( $id );
		if ( $spot ) {
			return $spot;
		}
		return Features::get( $id );
	}

	/**
	 * Leave an update on a Spot or a map feature.
	 *
	 * @param int      $spot_id Spot or feature ID.
	 * @param int      $user    Who writes it.
	 * @param string   $text    What they wrote.
	 * @param int|null $team    The organization to post it for, 0 for none, null for theirs.
	 * @param bool     $named   Whether they asked to be named.
	 * @return int|\WP_Error Update ID.
	 */
	public static function add( int $spot_id, int $user, string $text, ?int $team = 0, bool $named = false ) {
		if ( $user <= 0 || ! get_userdata( $user ) ) {
			return new \WP_Error( 'packout_signin', __( 'Sign in to share an update.', 'packout-core' ), array( 'status' => 401 ) );
		}
		$spot = self::subject( $spot_id );
		if ( ! self::open( $spot ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That page does not take updates.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$text = self::clean( $text );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$allowed = self::within_limit( $user );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => self::TYPE,
					'post_status'  => 'publish',
					'post_parent'  => $spot_id,
					'post_author'  => $user,
					'post_content' => $text,
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$credit = Credit::choose( $user, $team, $named );
		if ( $credit['team'] > 0 ) {
			update_post_meta( $id, '_pos_credit_team', $credit['team'] );
		}
		if ( $credit['named'] ) {
			update_post_meta( $id, '_pos_credit_named', '1' );
		}
		self::refresh( $spot_id );
		self::tell_coordinators( $spot, (int) $id, $text );

		/**
		 * Someone left an update on a Spot.
		 *
		 * @param int $id      Update ID.
		 * @param int $spot_id Spot ID.
		 * @param int $user    Who wrote it.
		 */
		do_action( 'packout_spot_update_added', (int) $id, $spot_id, $user );
		return (int) $id;
	}

	/**
	 * Keep a note on a Spot the person reported.
	 *
	 * A note is its author's alone. One that is made public is on the Spot's
	 * page with the other updates, credited the way the report is.
	 *
	 * @param int    $spot_id Spot ID.
	 * @param int    $user    Who writes it: the Spot's reporter.
	 * @param string $text    What they wrote.
	 * @param bool   $is_public  Whether to show it on the Spot's page.
	 * @return int|\WP_Error Note ID.
	 */
	public static function note( int $spot_id, int $user, string $text, bool $is_public = false ) {
		$spot = Spots::get( $spot_id );
		if ( $user <= 0 || ! $spot || (int) $spot->post_author !== $user || ! in_array( $spot->post_status, Spots::POST_STATUSES, true ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'You can keep notes on the Spots you reported.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$text = self::clean( $text, true );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		if ( count( self::mine( $spot_id, $user ) ) >= self::NOTES ) {
			/* translators: %s: number of notes. */
			return new \WP_Error( 'packout_note_limit', sprintf( __( 'A Spot can hold %s of your notes. Remove one to add another.', 'packout-core' ), number_format_i18n( self::NOTES ) ), array( 'status' => 400 ) );
		}
		if ( $is_public ) {
			$allowed = self::within_limit( $user );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => self::TYPE,
					'post_status'  => $is_public ? 'publish' : 'private',
					'post_parent'  => $spot_id,
					'post_author'  => $user,
					'post_content' => $text,
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		// Credited as the report is, while the person is still in that organization.
		$credit = Spots::credit( $spot_id );
		$credit = Credit::choose( $user, $credit['team'], $credit['named'] );
		if ( $credit['team'] > 0 ) {
			update_post_meta( $id, '_pos_credit_team', $credit['team'] );
		}
		if ( $credit['named'] ) {
			update_post_meta( $id, '_pos_credit_named', '1' );
		}
		if ( $is_public ) {
			self::announce( $spot, (int) $id, $text, $user );
		}
		return (int) $id;
	}

	/**
	 * Change a note or an update: what it says, and whether the Spot's page shows it.
	 *
	 * @param int    $id     Update ID.
	 * @param int    $user   Who changes it: its author.
	 * @param string $text   What it says now.
	 * @param bool   $is_public Whether to show it on the Spot's page.
	 * @return true|\WP_Error
	 */
	public static function edit( int $id, int $user, string $text, bool $is_public ) {
		$update = self::get( $id, true );
		$spot   = $update ? Spots::get( (int) $update->post_parent ) : null;
		if ( ! $update || ! $spot || $user <= 0 || (int) $update->post_author !== $user ) {
			return new \WP_Error( 'packout_forbidden', __( 'You cannot change that note.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$text = self::clean( $text, true );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$was = self::shown( $update );
		if ( $is_public === $was && $text === $update->post_content ) {
			return true;
		}
		if ( $is_public && ! $was ) {
			$allowed = self::within_limit( $user );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_status'  => $is_public ? 'publish' : 'private',
					'post_content' => $text,
				)
			)
		);
		if ( $is_public ) {
			// Newly on the page, or on the page and saying something else: the coordinators read it again.
			self::announce( $spot, $id, $text, $user );
		} elseif ( $was ) {
			self::refresh( $spot->ID );
		}
		return true;
	}

	/**
	 * An update or a public note is on the page: build the page again, and tell the coordinators.
	 *
	 * @param \WP_Post $spot Spot.
	 * @param int      $id   Update ID.
	 * @param string   $text What it says.
	 * @param int      $user Who wrote it.
	 */
	private static function announce( \WP_Post $spot, int $id, string $text, int $user ): void {
		self::refresh( $spot->ID );
		if ( ! self::open( $spot ) ) {
			// The Spot has no public page yet. The note is on it when it has one.
			return;
		}
		self::tell_coordinators( $spot, $id, $text );

		/** This action is documented in add(). */
		do_action( 'packout_spot_update_added', $id, $spot->ID, $user );
	}

	/**
	 * Everything one person wrote on a Spot, newest first: their notes, and their updates on its page.
	 *
	 * @param int $spot_id Spot ID.
	 * @param int $user    User ID.
	 * @return array<int, \WP_Post>
	 */
	public static function mine( int $spot_id, int $user ): array {
		return $spot_id > 0 && $user > 0 ? get_posts(
			array(
				'post_type'   => self::TYPE,
				'post_status' => array( 'publish', 'private' ),
				'post_parent' => $spot_id,
				'author'      => $user,
				'numberposts' => 200,
				'orderby'     => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
			)
		) : array();
	}

	/**
	 * Whether a person can remove an update: its author, or a coordinator when it is on the page.
	 *
	 * @param int $id   Update ID.
	 * @param int $user User ID.
	 */
	public static function can_remove( int $id, int $user ): bool {
		$update = self::get( $id, true );
		return $update && $user > 0 && ( (int) $update->post_author === $user || ( self::shown( $update ) && Capabilities::can( Capabilities::MODERATE, $user ) ) );
	}

	/**
	 * Remove an update for good.
	 *
	 * @param int $id   Update ID.
	 * @param int $user Who removes it.
	 * @return true|\WP_Error
	 */
	public static function remove( int $id, int $user ) {
		$update = self::get( $id, true );
		if ( ! $update || ! self::can_remove( $id, $user ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'You cannot remove that update.', 'packout-core' ), array( 'status' => 403 ) );
		}
		wp_delete_post( $id, true );
		self::refresh( (int) $update->post_parent );
		return true;
	}

	/**
	 * The updates on a Spot, newest first.
	 *
	 * Two written in the same second are in the order they were written.
	 *
	 * @param int $spot_id Spot ID.
	 * @return array<int, \WP_Post>
	 */
	public static function of( int $spot_id ): array {
		return $spot_id > 0 ? get_posts(
			array(
				'post_type'   => self::TYPE,
				'post_status' => 'publish',
				'post_parent' => $spot_id,
				'numberposts' => 200,
				'orderby'     => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
			)
		) : array();
	}

	/**
	 * Who an update is from, as the public reads it.
	 *
	 * @param \WP_Post $update Update.
	 * @return array{kind: string, label: string, url: string}
	 */
	public static function from( \WP_Post $update ): array {
		return Credit::line( (int) get_post_meta( $update->ID, '_pos_credit_team', true ), '1' === get_post_meta( $update->ID, '_pos_credit_named', true ), (int) $update->post_author );
	}

	/**
	 * The updates of a Spot go with it when it is deleted for good.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public static function spot_deleted( $post_id ): void {
		$type = get_post_type( (int) $post_id );
		if ( Schema::SPOT !== $type && Schema::FEATURE !== $type ) {
			return;
		}
		$all = get_posts(
			array(
				'post_type'   => self::TYPE,
				'post_status' => array( 'publish', 'private' ),
				'post_parent' => (int) $post_id,
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		foreach ( $all as $update ) {
			wp_delete_post( (int) $update, true );
		}
	}

	/**
	 * Have the Spot's page built again for visitors, where the host keeps a copy of it.
	 *
	 * @param int $spot_id Spot ID.
	 */
	public static function refresh( int $spot_id ): void {
		clean_post_cache( $spot_id );

		/**
		 * Fires when a record's public page has changed and a copy a host keeps of it is out of date.
		 *
		 * @param int $post_id The record.
		 */
		do_action( 'packout_page_changed', $spot_id );
	}

	/**
	 * Tell the coordinators' address about a new update, so one that should not stand is seen.
	 *
	 * @param \WP_Post $spot Spot.
	 * @param int      $id   Update ID.
	 * @param string   $text What was written.
	 */
	private static function tell_coordinators( \WP_Post $spot, int $id, string $text ): void {
		$update = self::get( $id );
		$person = $update ? get_userdata( (int) $update->post_author ) : false;
		$from   = $update ? self::from( $update ) : null;
		Accounts::mail(
			(string) get_option( 'admin_email' ),
			/* translators: %s: Spot title. */
			sprintf( __( 'New update on “%s”', 'packout-core' ), $spot->post_title ),
			array(
				/* translators: 1: person's name, 2: how the page credits the update. */
				sprintf( __( '%1$s left an update. The page shows it as from %2$s.', 'packout-core' ), $person ? $person->display_name : __( 'Someone', 'packout-core' ), $from ? $from['label'] : '' ),
				$text,
				/* translators: %s: address of the Spot's page. */
				sprintf( __( 'It is on the page now: %s', 'packout-core' ), get_permalink( $spot ) . '#pos-activity' ),
				__( 'A coordinator who is signed in can remove it there.', 'packout-core' ),
			)
		);
	}

	/**
	 * Take an update, or remove one, from the Spot's page.
	 */
	public static function handle(): void {
		$do = isset( $_POST['packout_update'] ) ? sanitize_key( wp_unslash( $_POST['packout_update'] ) ) : '';
		if ( '' === $do ) {
			return;
		}
		$spot_id = isset( $_POST['spot'] ) ? (int) $_POST['spot'] : 0;
		$spot    = self::subject( $spot_id );
		$user    = get_current_user_id();
		$page    = self::open( $spot ) ? (string) get_permalink( $spot ) : home_url( '/' );
		$back    = static fn( array $args ): string => add_query_arg( array_map( 'rawurlencode', $args ), $page ) . '#pos-activity';
		$fail    = static function ( \WP_Error $error ) use ( $back ): void {
			$key = strtolower( wp_generate_password( 12, false ) );
			set_transient( 'pos_update_note_' . $key, $error->get_error_message(), 10 * MINUTE_IN_SECONDS );
			Accounts::go( $back( array( 'pos_update_problem' => $key ) ) );
		};

		if ( ! self::open( $spot ) ) {
			Accounts::go( $page );
		}
		if ( $user <= 0 ) {
			Accounts::go( Accounts::signin_url( $page . '#pos-activity' ) );
		}
		if ( ! isset( $_POST['_posnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_posnonce'] ) ), self::NONCE ) ) {
			$fail( new \WP_Error( 'packout_update_expired', __( 'That form had expired. Try again.', 'packout-core' ) ) );
		}

		if ( 'remove' === $do ) {
			$update = self::get( isset( $_POST['update'] ) ? (int) $_POST['update'] : 0 );
			$done   = $update && (int) $update->post_parent === $spot_id ? self::remove( $update->ID, $user ) : new \WP_Error( 'packout_forbidden', __( 'You cannot remove that update.', 'packout-core' ) );
			if ( is_wp_error( $done ) ) {
				$fail( $done );
			}
			Accounts::go( $back( array( 'pos_updated' => 'removed' ) ) );
		}

		$done = self::add(
			$spot_id,
			$user,
			isset( $_POST['text'] ) ? (string) wp_unslash( $_POST['text'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- add() sanitizes it.
			isset( $_POST['pos_update_team'] ) ? (int) $_POST['pos_update_team'] : 0,
			! empty( $_POST['pos_update_named'] )
		);
		if ( is_wp_error( $done ) ) {
			$fail( $done );
		}
		Accounts::go( $back( array( 'pos_updated' => 'added' ) ) );
	}

	/**
	 * What the form said back, as notices.
	 */
	public static function notices(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the address to choose what to show.
		$said    = isset( $_GET['pos_updated'] ) ? sanitize_key( wp_unslash( $_GET['pos_updated'] ) ) : '';
		$problem = isset( $_GET['pos_update_problem'] ) ? sanitize_key( wp_unslash( $_GET['pos_update_problem'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$html = '';
		if ( '' !== $problem ) {
			$reason = get_transient( 'pos_update_note_' . $problem );
			$html  .= Accounts::notice( 'error', is_string( $reason ) && '' !== $reason ? $reason : __( 'That did not go through. Try again.', 'packout-core' ) );
		}
		$words = array(
			'added'   => __( 'Your update is on the page.', 'packout-core' ),
			'removed' => __( 'The update is removed.', 'packout-core' ),
		);
		if ( isset( $words[ $said ] ) ) {
			$html .= Accounts::notice( 'success', $words[ $said ] );
		}
		return $html;
	}

	/**
	 * The button that removes an update, for its author and for coordinators.
	 *
	 * @param \WP_Post $update Update.
	 */
	public static function remove_form( \WP_Post $update ): string {
		if ( ! self::can_remove( $update->ID, get_current_user_id() ) ) {
			return '';
		}
		$html  = '<form method="post" class="pos-activity__remove"><input type="hidden" name="packout_update" value="remove" /><input type="hidden" name="spot" value="' . esc_attr( (string) $update->post_parent ) . '" /><input type="hidden" name="update" value="' . esc_attr( (string) $update->ID ) . '" />' . wp_nonce_field( self::NONCE, '_posnonce', false, false );
		$html .= '<button class="pos-ws__button pos-ws__button--danger"' . Workspace::confirm( __( 'Remove this update?', 'packout-core' ), __( 'It is taken off the page for good.', 'packout-core' ), __( 'Remove it', 'packout-core' ) ) . '>' . esc_html__( 'Remove', 'packout-core' ) . '</button></form>';
		return $html;
	}

	/**
	 * The place to share an update: a form for someone signed in, and a way in for a visitor.
	 *
	 * A visitor's page may be cached, so it says nothing about who is looking.
	 *
	 * @param \WP_Post $spot Spot.
	 */
	public static function form( \WP_Post $spot ): string {
		if ( ! self::open( $spot ) ) {
			return '';
		}
		$user = get_current_user_id();
		$here = get_permalink( $spot ) . '#pos-activity';
		$html = '<div class="pos-activity__share pos-ws" id="pos-update"><h3>' . esc_html__( 'Share an update', 'packout-core' ) . '</h3>';
		if ( $user <= 0 ) {
			$html .= '<p>' . esc_html__( 'Been here lately? Sign in to say what it looks like now, how to reach it, or what a crew should bring.', 'packout-core' ) . '</p>';
			$html .= '<p class="pos-ws__actions"><a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( Accounts::signin_url( $here ) ) . '">' . esc_html__( 'Sign in to share an update', 'packout-core' ) . '</a>';
			if ( Accounts::open() ) {
				$html .= ' <a class="pos-ws__button" href="' . esc_url( Accounts::signup_url( $here ) ) . '">' . esc_html__( 'Sign up', 'packout-core' ) . '</a>';
			}
			return $html . '</p></div>';
		}
		$html .= '<form method="post" class="pos-ws__form"><input type="hidden" name="packout_update" value="add" /><input type="hidden" name="spot" value="' . esc_attr( (string) $spot->ID ) . '" />' . wp_nonce_field( self::NONCE, '_posnonce', false, false );
		$id    = wp_unique_id( 'pos-update-' );
		/* translators: %s: number of characters. */
		$help  = sprintf( __( 'What it looks like now, how to reach it, what to bring. Up to %s characters. Everyone can read it, so leave out anything private.', 'packout-core' ), number_format_i18n( self::LENGTH ) );
		$html .= '<p class="pos-ws__field"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Your update or comment', 'packout-core' ) . '</label><textarea id="' . esc_attr( $id ) . '" name="text" rows="4" maxlength="' . esc_attr( (string) self::LENGTH ) . '" required></textarea><span class="pos-ws__help">' . esc_html( $help ) . '</span></p>';
		$html .= Credit::fields( 'pos_update', $user, 'update' );
		return $html . Workspace::submit( __( 'Share the update', 'packout-core' ) ) . '</form></div>';
	}
}
