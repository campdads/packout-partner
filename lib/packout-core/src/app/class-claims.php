<?php
/**
 * Cleaning a Spot from its public page.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One person says they cleaned a Spot, with a note and a photo.
 *
 * With a photo the Spot is cleaned at once. Without one it waits as
 * "Cleanup reported" until the same person adds a photo or a coordinator
 * confirms it. A visitor gives a name and an email address: a new address
 * gets an account, and an address that already has one is credited only
 * after the link sent to it is followed.
 */
class Claims {

	/**
	 * How long the link in the thank-you message works.
	 */
	public const LINK_TTL = 14 * DAY_IN_SECONDS;

	public const NONCE = 'packout_claim';

	/**
	 * Claims one connection may send in an hour, and one address in a day.
	 */
	private const PER_ADDRESS = 5;
	private const PER_EMAIL   = 10;

	/**
	 * Longest note.
	 */
	private const NOTE = 1000;

	/**
	 * Hook the form handler.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'handle' ), 31 );
	}

	/**
	 * The claim on a Spot, or null.
	 *
	 * @param int $spot_id Spot ID.
	 * @return array{at: string, by: int, email: string, name: string, note: string, photo: int, from: string}|null
	 */
	public static function of( int $spot_id ): ?array {
		$at = Spots::field( $spot_id, 'claim_at' );
		if ( '' === $at ) {
			return null;
		}
		return array(
			'at'    => $at,
			'by'    => (int) Spots::field( $spot_id, 'claim_by', '0' ),
			'email' => Spots::field( $spot_id, 'claim_email' ),
			'name'  => Spots::field( $spot_id, 'claim_name' ),
			'note'  => (string) get_post_meta( $spot_id, '_pos_claim_note', true ),
			'photo' => (int) Spots::field( $spot_id, 'claim_photo', '0' ),
			'from'  => Spots::field( $spot_id, 'claim_from', 'spotted' ),
			// Whether the person asked for the Spot's page to give their name.
			'named' => '1' === Spots::field( $spot_id, 'claim_named' ),
		);
	}

	/**
	 * Whether a Spot can be claimed now, or why not.
	 *
	 * @param \WP_Post $post Spot.
	 * @return true|\WP_Error
	 */
	public static function open( \WP_Post $post ) {
		if ( 'publish' !== $post->post_status || '' !== Spots::field( $post->ID, 'duplicate_of' ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That Spot does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( Spots::blocked( $post->ID ) ) {
			return new \WP_Error( 'packout_hazard', Schema::hazard_guidance( Spots::field( $post->ID, 'hazard', 'none' ) ), array( 'status' => 409 ) );
		}
		switch ( Spots::field( $post->ID, 'status', 'spotted' ) ) {
			case 'spotted':
			case 'watch':
				return true;
			case 'pending':
				return new \WP_Error( 'packout_claim_pending', __( 'Someone has already reported cleaning this Spot.', 'packout-core' ), array( 'status' => 409 ) );
			case 'cleaned':
				return new \WP_Error( 'packout_claim_cleaned', __( 'This Spot is already recorded as cleaned.', 'packout-core' ), array( 'status' => 409 ) );
			default:
				return new \WP_Error( 'packout_claim_mission', __( 'An organization has this Spot on a mission. Its lead records the cleanup.', 'packout-core' ), array( 'status' => 409 ) );
		}
	}

	/**
	 * Say that a Spot was cleaned.
	 *
	 * @param int                       $spot_id Spot ID.
	 * @param array<string, mixed>      $input   note, named (whether to give the person's name on the page), and for a visitor name and email.
	 * @param array<string, mixed>|null $file    One entry from the request files, or null.
	 * @param int                       $user    The signed-in person, or 0 for a visitor.
	 * @return array{state: string, by: int}|\WP_Error state is cleaned or pending. by is 0 while the credit waits.
	 */
	public static function submit( int $spot_id, array $input, ?array $file, int $user = 0 ) {
		$post = Spots::get( $spot_id );
		if ( ! $post ) {
			return new \WP_Error( 'packout_not_found', __( 'That Spot does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$open = self::open( $post );
		if ( is_wp_error( $open ) ) {
			return $open;
		}

		$note   = mb_substr( sanitize_textarea_field( (string) ( $input['note'] ?? '' ) ), 0, self::NOTE );
		$person = $user > 0 ? get_userdata( $user ) : false;
		if ( $person ) {
			$name  = $person->display_name;
			$email = strtolower( $person->user_email );
		} else {
			$user  = 0;
			$name  = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
			$email = strtolower( sanitize_email( (string) ( $input['email'] ?? '' ) ) );
			if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 60 ) {
				return new \WP_Error( 'packout_account_name', __( 'Enter your name, from 2 to 60 characters.', 'packout-core' ), array( 'status' => 400 ) );
			}
			// A network can ban email domains from signing up. That list is honored here too.
			if ( ! is_email( $email ) || ( is_multisite() && is_email_address_unsafe( $email ) ) ) {
				return new \WP_Error( 'packout_account_email', __( 'Enter an email address.', 'packout-core' ), array( 'status' => 400 ) );
			}
			$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			if ( ! Accounts::allow( 'claim-address-' . $address, self::PER_ADDRESS, HOUR_IN_SECONDS ) ) {
				return new \WP_Error( 'packout_claim_limit', __( 'Too many cleanups were reported from this connection. Sign in, or try again in an hour.', 'packout-core' ), array( 'status' => 429 ) );
			}
			if ( ! Accounts::allow( 'claim-email-' . $email, self::PER_EMAIL, DAY_IN_SECONDS ) ) {
				return new \WP_Error( 'packout_claim_limit', __( 'That address has reported a lot of cleanups today. Sign in to keep going.', 'packout-core' ), array( 'status' => 429 ) );
			}
		}//end if

		// The photo is stored first, so a file that is refused changes nothing.
		$photo = 0;
		if ( $file ) {
			$photo = Photos::attach_upload( 0, $file );
			if ( is_wp_error( $photo ) ) {
				return $photo;
			}
		}

		$key = '';
		if ( $person ) {
			Accounts::join_site( $user );
		} elseif ( ! email_exists( $email ) ) {
			// A new address gets its account now. An address with an account is credited when its owner follows the link.
			$made = Accounts::create( $email, $name, wp_generate_password( 32, true, true ), 'claim' );
			if ( is_wp_error( $made ) ) {
				if ( $photo > 0 ) {
					wp_delete_attachment( $photo, true );
				}
				return $made;
			}
			$user    = $made;
			$created = get_userdata( $user );
			$reset   = $created ? get_password_reset_key( $created ) : '';
			$key     = is_string( $reset ) ? $reset : '';
		}

		$claim = array(
			'by'    => $user,
			'email' => $email,
			'name'  => $name,
			'note'  => $note,
			'at'    => gmdate( 'c' ),
			'photo' => $photo,
			'from'  => Spots::field( $spot_id, 'status', 'spotted' ),
			'named' => ! empty( $input['named'] ) ? '1' : '',
		);
		foreach ( $claim as $field => $value ) {
			update_post_meta( $spot_id, '_pos_claim_' . $field, wp_slash( $value ) );
		}

		if ( $photo > 0 ) {
			$done = self::clean( $spot_id );
			if ( is_wp_error( $done ) ) {
				self::forget( $spot_id );
				return $done;
			}
		} else {
			Spots::set_status( $spot_id, 'pending' );
		}
		$state = $photo > 0 ? 'cleaned' : 'pending';

		/**
		 * Someone said they cleaned a Spot from its page.
		 *
		 * @param int  $spot_id Spot ID.
		 * @param int  $user    The person credited, or 0 while the credit waits for the link to be followed.
		 * @param bool $photo   Whether a photo came with it.
		 */
		do_action( 'packout_spot_claimed', $spot_id, $user, $photo > 0 );

		self::thank( $post, $state, $person ? 'member' : ( '' !== $key || $user > 0 ? 'new' : 'known' ), $key );
		self::tell_coordinators( $post, $state );

		return array(
			'state' => $state,
			'by'    => $user,
		);
	}

	/**
	 * Mark the claimed Spot cleaned, and write its record when someone is credited.
	 *
	 * @param int $spot_id Spot ID.
	 * @return true|\WP_Error
	 */
	private static function clean( int $spot_id ) {
		$claim = self::of( $spot_id );
		if ( ! $claim ) {
			return new \WP_Error( 'packout_not_found', __( 'Nobody has reported cleaning this Spot.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$done = Spots::mark_cleaned( $spot_id, $claim['by'], 0 );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		Impact::flush();
		if ( $claim['by'] > 0 ) {
			self::record( $spot_id, $claim );
		}
		return true;
	}

	/**
	 * Write the cleanup record of a claim and move its photo onto it.
	 *
	 * @param int                  $spot_id Spot ID.
	 * @param array<string, mixed> $claim   From of().
	 */
	private static function record( int $spot_id, array $claim ): void {
		$id = Impact::save_cleanup( $spot_id, '' !== $claim['note'] ? array( 'narrative' => $claim['note'] ) : array(), (int) $claim['by'] );
		if ( ! is_wp_error( $id ) && ! empty( $claim['named'] ) ) {
			// The activity on the Spot's page names the person only when they asked.
			update_post_meta( $id, '_pos_named', '1' );
		}
		if ( is_wp_error( $id ) || $claim['photo'] <= 0 || 'attachment' !== get_post_type( (int) $claim['photo'] ) ) {
			return;
		}
		wp_update_post(
			array(
				'ID'          => (int) $claim['photo'],
				'post_parent' => $id,
				'post_author' => (int) $claim['by'],
			)
		);
	}

	/**
	 * Remove the claim from a Spot. The Spot's stage is left as it is.
	 *
	 * @param int $spot_id Spot ID.
	 */
	private static function forget( int $spot_id ): void {
		$claim = self::of( $spot_id );
		// A photo that never reached a record goes with the claim.
		if ( $claim && $claim['photo'] > 0 && 0 === (int) wp_get_post_parent_id( $claim['photo'] ) ) {
			wp_delete_attachment( $claim['photo'], true );
		}
		foreach ( array( 'by', 'email', 'name', 'note', 'at', 'photo', 'from', 'named' ) as $field ) {
			delete_post_meta( $spot_id, '_pos_claim_' . $field );
		}
	}

	/**
	 * The link sent to the person who made a claim.
	 *
	 * @param int $spot_id Spot ID.
	 */
	public static function link( int $spot_id ): string {
		$claim = self::of( $spot_id );
		if ( ! $claim ) {
			return (string) get_permalink( $spot_id );
		}
		$token = Accounts::token(
			array(
				'k' => 'claim',
				's' => $spot_id,
				'e' => $claim['email'],
				'a' => $claim['at'],
			),
			self::LINK_TTL
		);
		return add_query_arg( 'pos_claim', rawurlencode( $token ), (string) get_permalink( $spot_id ) ) . '#pos-claim';
	}

	/**
	 * The claim a link proves its holder made, or null.
	 *
	 * A link is for one claim: it stops working when the claim is removed or
	 * a later one replaces it.
	 *
	 * @param int    $spot_id Spot ID.
	 * @param string $token   Token from the address.
	 * @return array<string, mixed>|null
	 */
	public static function proven( int $spot_id, string $token ): ?array {
		$claim = self::of( $spot_id );
		$read  = '' !== $token ? Accounts::claims( $token ) : null;
		if ( ! $claim || ! $read || 'claim' !== ( $read['k'] ?? '' ) || (int) ( $read['s'] ?? 0 ) !== $spot_id ) {
			return null;
		}
		return hash_equals( $claim['email'], (string) ( $read['e'] ?? '' ) ) && hash_equals( $claim['at'], (string) ( $read['a'] ?? '' ) ) ? $claim : null;
	}

	/**
	 * Put a claim made with the address of an existing account on that account.
	 *
	 * @param int    $spot_id Spot ID.
	 * @param string $token   Token from the link in the message.
	 * @return int|\WP_Error The person credited.
	 */
	public static function credit( int $spot_id, string $token ) {
		$claim = self::proven( $spot_id, $token );
		if ( ! $claim ) {
			return new \WP_Error( 'packout_claim_link', __( 'That link has expired, or the cleanup it was for has changed.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( $claim['by'] > 0 ) {
			return (int) $claim['by'];
		}
		$person = get_user_by( 'email', $claim['email'] );
		if ( ! $person instanceof \WP_User ) {
			return new \WP_Error( 'packout_claim_link', __( 'No account uses that address any more. Sign up, then tell a coordinator about this cleanup.', 'packout-core' ), array( 'status' => 400 ) );
		}
		Accounts::join_site( $person->ID );
		update_post_meta( $spot_id, '_pos_claim_by', $person->ID );
		$claim['by'] = $person->ID;

		// A Spot nobody is credited for, cleaned outside a mission, was cleaned by this claim.
		$waiting = 'cleaned' === Spots::field( $spot_id, 'status', 'spotted' )
			&& 0 === (int) Spots::field( $spot_id, 'cleaned_by', '0' )
			&& 0 === (int) Spots::field( $spot_id, 'cleaned_mission', '0' );
		if ( $waiting ) {
			update_post_meta( $spot_id, '_pos_cleaned_by', $person->ID );
			Impact::flush();
			if ( ! Impact::current( $spot_id ) ) {
				self::record( $spot_id, $claim );
			}
		}
		return $person->ID;
	}

	/**
	 * Add the photo that turns a reported cleanup into a cleaned Spot.
	 *
	 * @param int                  $spot_id Spot ID.
	 * @param array<string, mixed> $file    One entry from the request files.
	 * @param int                  $user    The signed-in person, or 0.
	 * @param string               $token   Token from the link in the message, for a person who is not signed in.
	 * @return true|\WP_Error
	 */
	public static function add_photo( int $spot_id, array $file, int $user, string $token = '' ) {
		$claim  = self::of( $spot_id );
		$proven = '' !== $token && null !== self::proven( $spot_id, $token );
		if ( ! $claim || ( ! $proven && ( $user <= 0 || $claim['by'] !== $user ) ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'Only the person who reported this cleanup can add its photo.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( 'pending' !== Spots::field( $spot_id, 'status', 'spotted' ) ) {
			return new \WP_Error( 'packout_claim_cleaned', __( 'This Spot is no longer waiting for a photo.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( Spots::blocked( $spot_id ) ) {
			return new \WP_Error( 'packout_hazard', Schema::hazard_guidance( Spots::field( $spot_id, 'hazard', 'none' ) ), array( 'status' => 409 ) );
		}
		$photo = Photos::attach_upload( 0, $file );
		if ( is_wp_error( $photo ) ) {
			return $photo;
		}
		if ( $proven && 0 === $claim['by'] ) {
			// Holding the link proves the address, so the credit no longer waits.
			self::credit( $spot_id, $token );
		}
		update_post_meta( $spot_id, '_pos_claim_photo', $photo );
		return self::clean( $spot_id );
	}

	/**
	 * A coordinator confirms a reported cleanup that has no photo.
	 *
	 * @param int $spot_id Spot ID.
	 * @return true|\WP_Error
	 */
	public static function confirm( int $spot_id ) {
		if ( ! self::of( $spot_id ) || 'pending' !== Spots::field( $spot_id, 'status', 'spotted' ) ) {
			return new \WP_Error( 'packout_not_found', __( 'This Spot is not waiting to be confirmed.', 'packout-core' ), array( 'status' => 409 ) );
		}
		return self::clean( $spot_id );
	}

	/**
	 * Whether the Spot's current stage came from its claim.
	 *
	 * @param int $spot_id Spot ID.
	 */
	public static function stands( int $spot_id ): bool {
		$claim = self::of( $spot_id );
		if ( ! $claim ) {
			return false;
		}
		$status = Spots::field( $spot_id, 'status', 'spotted' );
		return 'pending' === $status
			|| ( 'cleaned' === $status && 0 === (int) Spots::field( $spot_id, 'cleaned_mission', '0' ) && (int) Spots::field( $spot_id, 'cleaned_by', '0' ) === $claim['by'] );
	}

	/**
	 * A coordinator says the Spot was not cleaned: reopen it and remove the claim.
	 *
	 * @param int $spot_id Spot ID.
	 * @return true|\WP_Error
	 */
	public static function dismiss( int $spot_id ) {
		$claim = self::of( $spot_id );
		if ( ! $claim ) {
			return new \WP_Error( 'packout_not_found', __( 'Nobody has reported cleaning this Spot.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( self::stands( $spot_id ) ) {
			if ( 'cleaned' === Spots::field( $spot_id, 'status', 'spotted' ) ) {
				$record = Impact::current( $spot_id );
				if ( $record && Impact::verified( $record->ID ) ) {
					return new \WP_Error( 'packout_impact_verified', __( 'The record of this cleanup has been verified. Return it to unverified on the Impact screen first.', 'packout-core' ), array( 'status' => 409 ) );
				}
				if ( $record ) {
					Impact::remember_unrecorded( $record->ID );
					wp_trash_post( $record->ID );
				}
				foreach ( array( 'cleaned_by', 'cleaned_at', 'cleaned_mission' ) as $field ) {
					delete_post_meta( $spot_id, '_pos_' . $field );
				}
				Impact::flush();
			}
			if ( 'watch' === $claim['from'] ) {
				Spots::mark_returned( $spot_id );
			} else {
				Spots::set_status( $spot_id, 'spotted' );
			}
		}//end if
		self::forget( $spot_id );
		return true;
	}

	/**
	 * Thank the person who made a claim.
	 *
	 * @param \WP_Post $post  Spot.
	 * @param string   $state cleaned or pending.
	 * @param string   $who   member (signed in), new (account just made), or known (the address has an account).
	 * @param string   $key   Key for choosing a password, for a new account.
	 */
	private static function thank( \WP_Post $post, string $state, string $who, string $key ): void {
		$claim = self::of( $post->ID );
		if ( ! $claim ) {
			return;
		}
		$lines   = array(
			/* translators: %s: the person's name. */
			sprintf( __( 'Hello %s,', 'packout-core' ), $claim['name'] ),
			/* translators: %s: Spot title. */
			sprintf( __( 'Thank you for cleaning “%s”. Every Spot that is packed out leaves the place better for the next person.', 'packout-core' ), $post->post_title ),
		);
		$lines[] = 'cleaned' === $state
			? __( 'Your photo is in, so the Spot is marked cleaned on the map.', 'packout-core' )
			: __( 'The Spot is marked “Cleanup reported” on the map. Add a photo of how it looks now and it is marked cleaned:', 'packout-core' ) . ' ' . ( 'member' === $who ? (string) get_permalink( $post ) . '#pos-claim' : self::link( $post->ID ) );

		if ( 'new' === $who ) {
			$person  = get_user_by( 'email', $claim['email'] );
			$lines[] = __( 'We made you a Pack-Out Spot account with this email address, so the cleanup is credited to you and you can see what you have done.', 'packout-core' );
			if ( $person && '' !== $key ) {
				$lines[] = __( 'Choose a password (the link is good for one day and works once):', 'packout-core' ) . ' ' . Sign_In::url(
					array(
						'view'  => 'reset',
						'key'   => $key,
						'login' => $person->user_login,
					)
				);
			}
			$lines[] = __( 'After that, “Forgot your password?” on the sign-in page sends a new link at any time.', 'packout-core' );
			$lines[] = __( 'If this was not you, ignore this message. Nobody can use the account without a link sent to this address.', 'packout-core' );
		} elseif ( 'known' === $who ) {
			$lines[] = 'cleaned' === $state
				? __( 'This address already has a Pack-Out Spot account. Follow this link to put the cleanup on it:', 'packout-core' ) . ' ' . self::link( $post->ID )
				: __( 'This address already has a Pack-Out Spot account. The same link puts the cleanup on it.', 'packout-core' );
			$lines[] = __( 'The link is good for fourteen days. If this was not you, ignore this message and nothing is added to your account.', 'packout-core' );
		} else {
			$lines[] = __( 'See what you have done:', 'packout-core' ) . ' ' . Profile::url( array(), 'impact' );
		}//end if
		Accounts::mail( $claim['email'], __( 'Thank you for cleaning a Spot', 'packout-core' ), $lines );
	}

	/**
	 * Tell the site's administration address about a claim.
	 *
	 * @param \WP_Post $post  Spot.
	 * @param string   $state cleaned or pending.
	 */
	private static function tell_coordinators( \WP_Post $post, string $state ): void {
		$claim = self::of( $post->ID );
		if ( ! $claim ) {
			return;
		}
		if ( 'pending' === $state ) {
			$sent = Approvals::send(
				(string) get_option( 'admin_email' ),
				'claim',
				Approvals::coordinator( Capabilities::MODERATE ),
				array( 'id' => $post->ID )
			);
			if ( $sent ) {
				return;
			}
		}
		Accounts::mail(
			(string) get_option( 'admin_email' ),
			'cleaned' === $state ? __( 'A Spot was cleaned from its page', 'packout-core' ) : __( 'A cleanup is waiting to be confirmed', 'packout-core' ),
			array(
				'cleaned' === $state
					/* translators: 1: person's name, 2: Spot title. */
					? sprintf( __( '%1$s reported cleaning “%2$s” and sent a photo. The Spot is marked cleaned and the record is unverified.', 'packout-core' ), $claim['name'], $post->post_title )
					/* translators: 1: person's name, 2: Spot title. */
					: sprintf( __( '%1$s reported cleaning “%2$s” without a photo. The Spot shows “Cleanup reported” until they add one or you confirm it.', 'packout-core' ), $claim['name'], $post->post_title ),
				'' !== $claim['note'] ? __( 'Their note:', 'packout-core' ) . ' ' . $claim['note'] : '',
				__( 'Look at it:', 'packout-core' ) . ' ' . admin_url( 'admin.php?page=packout-spots&open=' . $post->ID ),
			)
		);
	}

	/**
	 * Act on a form from the Spot page.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- A signed-in person's nonce is checked below. A visitor has no session to protect.
		$do = isset( $_POST['packout_claim'] ) ? sanitize_key( wp_unslash( $_POST['packout_claim'] ) ) : '';
		if ( '' === $do ) {
			return;
		}
		$text    = static fn( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		$spot_id = isset( $_POST['spot'] ) ? (int) $_POST['spot'] : 0;
		$post    = Spots::get( $spot_id );
		$user    = get_current_user_id();
		$token   = $text( 'token' );
		$page    = $post && 'publish' === $post->post_status ? (string) get_permalink( $post ) : home_url( '/' );
		$back    = static function ( array $args ) use ( $page, $token ): string {
			if ( '' !== $token && ! isset( $args['pos_claimed'] ) ) {
				$args['pos_claim'] = $token;
			}
			return add_query_arg( array_map( 'rawurlencode', $args ), $page ) . '#pos-claim';
		};
		$fail    = static function ( \WP_Error $error ) use ( $back ): void {
			// The reason rides in a short-lived note, so any refusal can be shown without a list of codes.
			$key = strtolower( wp_generate_password( 12, false ) );
			set_transient( 'pos_claim_note_' . $key, $error->get_error_message(), 10 * MINUTE_IN_SECONDS );
			Accounts::go( $back( array( 'pos_claim_problem' => $key ) ) );
		};

		if ( ! $post || 'publish' !== $post->post_status ) {
			Accounts::go( $page );
		}
		if ( $user > 0 && ( ! isset( $_POST['_posnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_posnonce'] ) ), self::NONCE ) ) ) {
			$fail( new \WP_Error( 'packout_claim_expired', __( 'That form had expired. Try again.', 'packout-core' ) ) );
		}
		// A filled trap field is a script. It is told the same thing as a person.
		if ( '' !== $text( 'website' ) ) {
			Accounts::go( $back( array( 'pos_claimed' => 'pending' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The upload handler checks the file.
		$file = isset( $_FILES['photo'] ) && is_array( $_FILES['photo'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE ) ? $_FILES['photo'] : null;
		if ( $file && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$fail( new \WP_Error( 'packout_photo_upload', __( 'The photo did not arrive. It may be too large. Try a smaller one.', 'packout-core' ) ) );
		}

		switch ( $do ) {
			case 'submit':
				$result = self::submit(
					$spot_id,
					array(
						'note'  => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
						'name'  => $text( 'name' ),
						'email' => $text( 'email' ),
						'named' => ! empty( $_POST['named'] ),
					),
					$file,
					$user
				);
				if ( is_wp_error( $result ) ) {
					$fail( $result );
				}
				Accounts::go( $back( array( 'pos_claimed' => $result['state'] ) ) );
				break;

			case 'photo':
				if ( ! $file ) {
					$fail( new \WP_Error( 'packout_photo_upload', __( 'Choose a photo first.', 'packout-core' ) ) );
				}
				$result = self::add_photo( $spot_id, $file, $user, $token );
				if ( is_wp_error( $result ) ) {
					$fail( $result );
				}
				Accounts::go( $back( array( 'pos_claimed' => 'cleaned' ) ) );
				break;

			case 'credit':
				$result = self::credit( $spot_id, $token );
				if ( is_wp_error( $result ) ) {
					$fail( $result );
				}
				Accounts::go( $back( array( 'pos_claimed' => 'credited' ) ) );
				break;
		}//end switch
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * The part of a Spot's public page about cleaning it.
	 *
	 * @param \WP_Post $post Spot.
	 */
	public static function section( \WP_Post $post ): string {
		if ( 'publish' !== $post->post_status || '' !== Spots::field( $post->ID, 'duplicate_of' ) ) {
			return '';
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the address to choose what to show.
		$token   = isset( $_GET['pos_claim'] ) ? sanitize_text_field( wp_unslash( $_GET['pos_claim'] ) ) : '';
		$said    = isset( $_GET['pos_claimed'] ) ? sanitize_key( wp_unslash( $_GET['pos_claimed'] ) ) : '';
		$problem = isset( $_GET['pos_claim_problem'] ) ? sanitize_key( wp_unslash( $_GET['pos_claim_problem'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$id     = $post->ID;
		$user   = get_current_user_id();
		$status = Spots::field( $id, 'status', 'spotted' );
		$claim  = self::of( $id );
		$proven = '' !== $token ? self::proven( $id, $token ) : null;
		$mine   = $claim && ( $proven || ( $user > 0 && $claim['by'] === $user ) );
		$body   = '';

		if ( '' !== $problem ) {
			$reason = get_transient( 'pos_claim_note_' . $problem );
			$body  .= Accounts::notice( 'error', is_string( $reason ) && '' !== $reason ? $reason : __( 'That did not go through. Try again.', 'packout-core' ) );
		}
		$thanks = array(
			'cleaned'  => __( 'Thank you. The Spot is marked cleaned. We sent a message to your email address.', 'packout-core' ),
			'pending'  => __( 'Thank you. The Spot shows “Cleanup reported”. Add a photo from the link we sent to your email address and it is marked cleaned.', 'packout-core' ),
			'credited' => __( 'Thank you. This cleanup is on your account.', 'packout-core' ),
		);
		if ( isset( $thanks[ $said ] ) ) {
			$body .= Accounts::notice( 'success', $thanks[ $said ] );
		}
		if ( '' !== $token && ! $proven && '' === $problem && '' === $said ) {
			$body .= Accounts::notice( 'warning', __( 'That link has expired, or the cleanup it was for has changed.', 'packout-core' ) );
		}

		$hidden = static function ( string $act ) use ( $id, $token, $user ): string {
			$html = '<input type="hidden" name="packout_claim" value="' . esc_attr( $act ) . '" /><input type="hidden" name="spot" value="' . esc_attr( (string) $id ) . '" />';
			if ( '' !== $token ) {
				$html .= '<input type="hidden" name="token" value="' . esc_attr( $token ) . '" />';
			}
			return $html . ( $user > 0 ? wp_nonce_field( self::NONCE, '_posnonce', false, false ) : '' );
		};
		$photo  = static fn( bool $required ): string => Workspace::input(
			'photo',
			$required ? __( 'Photo of how it looks now', 'packout-core' ) : __( 'Photo of how it looks now (optional)', 'packout-core' ),
			'',
			array_merge(
				array(
					'type'   => 'file',
					'accept' => Photos::accept(),
				),
				$required ? array( 'required' => '' ) : array()
			),
			$required ? Photos::hint() . ' ' . __( 'It is shown on the Spot\'s page.', 'packout-core' ) : __( 'With a photo the Spot is marked cleaned right away. Without one it shows “Cleanup reported” until you add one. The photo is shown on the Spot\'s page.', 'packout-core' )
		);

		if ( $proven && 0 === $claim['by'] && '' === $said ) {
			// The link from the message, opened by the owner of an address that already had an account.
			$body .= '<h2>' . esc_html__( 'Is this cleanup yours?', 'packout-core' ) . '</h2>';
			$body .= '<p>' . esc_html__( 'Someone reported cleaning this Spot with your email address. Confirm it and it goes on your account.', 'packout-core' ) . '</p>';
			if ( 'pending' === $status ) {
				$body .= '<form method="post" class="pos-ws__form" enctype="multipart/form-data">' . $hidden( 'photo' ) . $photo( true );
				$body .= Workspace::submit( __( 'It was me. Add the photo', 'packout-core' ) ) . '</form>';
			}
			$body .= '<form method="post" class="pos-ws__form">' . $hidden( 'credit' );
			$body .= '<p class="pos-ws__actions"><button class="pos-ws__button' . ( 'pending' === $status ? '' : ' pos-ws__button--primary' ) . '">' . esc_html( 'pending' === $status ? __( 'It was me. No photo yet', 'packout-core' ) : __( 'It was me', 'packout-core' ) ) . '</button></p></form>';
		} elseif ( 'pending' === $status && $mine ) {
			$body .= '<h2>' . esc_html__( 'Add a photo', 'packout-core' ) . '</h2>';
			$body .= '<p>' . esc_html__( 'You reported cleaning this Spot. A photo of how it looks now marks it cleaned.', 'packout-core' ) . '</p>';
			$body .= '<form method="post" class="pos-ws__form" enctype="multipart/form-data">' . $hidden( 'photo' ) . $photo( true );
			$body .= Workspace::submit( __( 'Add the photo', 'packout-core' ) ) . '</form>';
		} elseif ( 'pending' === $status ) {
			if ( '' === $said ) {
				$body .= '<p class="pos-claim__state">' . esc_html__( 'Someone reported cleaning this Spot. It is marked cleaned when they add a photo or a coordinator confirms it.', 'packout-core' ) . '</p>';
			}
		} elseif ( true === self::open( $post ) ) {
			$body .= '<h2>' . esc_html__( 'I cleaned this Spot', 'packout-core' ) . '</h2>';
			$body .= '<p>' . esc_html__( 'Packed it out? Tell us, and the map changes for everyone.', 'packout-core' ) . '</p>';
			$body .= '<form method="post" class="pos-ws__form" enctype="multipart/form-data">' . $hidden( 'submit' );
			if ( $user > 0 ) {
				$person = wp_get_current_user();
				/* translators: %s: the person's name. */
				$body .= '<p class="pos-ws__help">' . esc_html( sprintf( __( 'Signed in as %s. The cleanup is credited to you.', 'packout-core' ), $person->display_name ) ) . '</p>';
			} else {
				$body .= Workspace::input(
					'name',
					__( 'Your name', 'packout-core' ),
					'',
					array(
						'required'     => '',
						'minlength'    => '2',
						'maxlength'    => '60',
						'autocomplete' => 'name',
					)
				);
				$body .= Workspace::input(
					'email',
					__( 'Email address', 'packout-core' ),
					'',
					array(
						'type'         => 'email',
						'required'     => '',
						'autocomplete' => 'email',
					),
					__( 'We send a thank-you, and make you an account so the cleanup is credited to you. It is not shown on the site.', 'packout-core' )
				);
				$body .= '<p class="pos-ws__trap" aria-hidden="true"><label for="pos-claim-website">' . esc_html__( 'Leave this empty', 'packout-core' ) . '</label><input id="pos-claim-website" type="text" name="website" tabindex="-1" autocomplete="off" /></p>';
			}//end if
			$body .= Workspace::area( 'note', __( 'What you did', 'packout-core' ), '', __( 'A line or two: what you carried out, and anything the next person should know.', 'packout-core' ) );
			$body .= $photo( false );
			$body .= '<p class="pos-ws__field">' . Workspace::tick( 'named', __( 'Show my name on this page', 'packout-core' ), false ) . '<span class="pos-ws__help">' . esc_html__( 'The activity on this page says who cleaned the Spot: “an individual”, unless you tick this.', 'packout-core' ) . '</span></p>';
			$body .= Workspace::submit( __( 'I cleaned it', 'packout-core' ) ) . '</form>';
			if ( $user <= 0 ) {
				$body .= '<p class="pos-ws__aside">' . esc_html__( 'Already have an account?', 'packout-core' ) . ' <a href="' . esc_url( Accounts::signin_url( (string) get_permalink( $post ) ) ) . '">' . esc_html__( 'Sign in', 'packout-core' ) . '</a></p>';
			}
		} elseif ( in_array( $status, array( 'planned', 'in_progress' ), true ) ) {
			$body .= '<p class="pos-claim__state">' . esc_html__( 'An organization has this Spot on a mission. Its lead records the cleanup.', 'packout-core' ) . '</p>';
		}//end if

		if ( '' === $body ) {
			return '';
		}
		wp_enqueue_style( 'packout-workspace' );
		return '<section class="pos-claim pos-ws" id="pos-claim">' . $body . '</section>';
	}
}
