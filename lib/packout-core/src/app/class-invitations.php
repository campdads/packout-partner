<?php
/**
 * Organization invitations: storage, status, resend, and history.
 *
 * The signed link is still what a person follows. These tables record each
 * send so a coordinator can list, filter, and re-send invitations.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One invitation row per organization + email + offer, and an event log.
 */
class Invitations {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_ACCEPTED = 'accepted';
	public const STATUS_EXPIRED  = 'expired';
	public const STATUS_REVOKED  = 'revoked';

	public const EVENT_SENT     = 'sent';
	public const EVENT_RESENT   = 'resent';
	public const EVENT_ACCEPTED = 'accepted';
	public const EVENT_EXPIRED  = 'expired';
	public const EVENT_REVOKED  = 'revoked';

	/**
	 * Create or update the invitation tables.
	 */
	public static function install_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$invites = self::table();
		$events  = self::events_table();

		dbDelta(
			"CREATE TABLE {$invites} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				offer_key char(64) NOT NULL,
				email varchar(190) NOT NULL,
				team_id bigint(20) unsigned NOT NULL,
				scope varchar(16) NOT NULL DEFAULT 'team',
				scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
				role varchar(16) NOT NULL DEFAULT 'member',
				subteam_id bigint(20) unsigned NOT NULL DEFAULT 0,
				subrole varchar(16) NOT NULL DEFAULT 'member',
				status varchar(16) NOT NULL DEFAULT 'pending',
				invited_by bigint(20) unsigned NOT NULL DEFAULT 0,
				sent_at datetime DEFAULT NULL,
				expires_at datetime DEFAULT NULL,
				accepted_at datetime DEFAULT NULL,
				accepted_user_id bigint(20) unsigned DEFAULT NULL,
				token_hash char(64) DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY offer_key (offer_key),
				KEY status_expires (status, expires_at),
				KEY team_status (team_id, status),
				KEY scope_status (scope, scope_id, status),
				KEY email (email),
				KEY invited_by (invited_by)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				invitation_id bigint(20) unsigned NOT NULL,
				event varchar(16) NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				meta longtext,
				PRIMARY KEY  (id),
				KEY invitation_id (invitation_id)
			) {$charset};"
		);

		// A row from before the scope columns is an organization's invitation.
		$wpdb->query( "UPDATE {$invites} SET scope_id = team_id WHERE scope = 'team' AND scope_id = 0 AND team_id > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is fixed.
	}

	/**
	 * The partner an offer is for, or 0 when it is for an organization.
	 *
	 * @param array<string, mixed> $seat scope, scope_id.
	 */
	private static function partner_of( array $seat ): int {
		return 'partner' === ( $seat['scope'] ?? '' ) ? max( 0, (int) ( $seat['scope_id'] ?? 0 ) ) : 0;
	}

	/**
	 * Main invitations table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pos_invitations';
	}

	/**
	 * Events table name.
	 */
	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pos_invitation_events';
	}

	/**
	 * Stable key for one offer to one address for one organization or one partner.
	 *
	 * @param int                  $team_id Organization ID. 0 for a partner's invitation.
	 * @param string               $email   Email address.
	 * @param array<string, mixed> $seat      role, subteam, subrole. For a partner: scope `partner` and scope_id.
	 */
	public static function offer_key( int $team_id, string $email, array $seat = array() ): string {
		$partner = self::partner_of( $seat );
		if ( $partner > 0 ) {
			return hash( 'sha256', 'partner|' . $partner . '|' . strtolower( $email ) . '|colead' );
		}
		$role    = 'colead' === ( $seat['role'] ?? '' ) ? 'colead' : 'member';
		$sub     = (int) ( $seat['subteam'] ?? 0 );
		$subrole = $sub > 0 && in_array( $seat['subrole'] ?? '', array( 'colead', 'lead' ), true ) ? (string) $seat['subrole'] : 'member';
		return hash( 'sha256', $team_id . '|' . strtolower( $email ) . '|' . $role . '|' . $sub . '|' . $subrole );
	}

	/**
	 * Hash of the current invitation token.
	 *
	 * @param string $token Signed token body (pos_confirm value).
	 */
	public static function token_hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) . '|packout-invite' );
	}

	/**
	 * Upsert a pending invitation before the mail goes out. Returns its id.
	 *
	 * @param int                  $team_id Organization ID.
	 * @param string               $email   Email address.
	 * @param array<string, mixed> $seat      role, subteam, subrole.
	 * @param int                  $by      Sender user ID.
	 */
	public static function prepare_send( int $team_id, string $email, array $seat, int $by ): int {
		global $wpdb;

		$email   = strtolower( sanitize_email( $email ) );
		$partner = self::partner_of( $seat );
		$team_id = $partner > 0 ? 0 : $team_id;
		$role    = $partner > 0 || 'colead' === ( $seat['role'] ?? '' ) ? 'colead' : 'member';
		$sub     = $partner > 0 ? 0 : (int) ( $seat['subteam'] ?? 0 );
		$subrole = $sub > 0 && in_array( $seat['subrole'] ?? '', array( 'colead', 'lead' ), true ) ? (string) $seat['subrole'] : 'member';
		$as_norm = array(
			'role'     => $role,
			'subteam'  => $sub,
			'subrole'  => $subrole,
			'scope'    => $partner > 0 ? 'partner' : 'team',
			'scope_id' => $partner > 0 ? $partner : $team_id,
		);
		$key     = self::offer_key( $team_id, $email, $as_norm );

		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Invitation store.
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE offer_key = %s', $key ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is fixed.
			ARRAY_A
		);

		if ( is_array( $existing ) ) {
			$status = (string) ( $existing['status'] ?? '' );
			if ( self::STATUS_ACCEPTED === $status ) {
				return (int) $existing['id'];
			}
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				self::table(),
				array(
					'status'     => self::STATUS_PENDING,
					'invited_by' => max( 0, $by ),
					'role'       => $role,
					'subteam_id' => $sub,
					'subrole'    => $subrole,
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%d', '%s', '%d', '%s' ),
				array( '%d' )
			);
			return (int) $existing['id'];
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'offer_key'  => $key,
				'email'      => $email,
				'team_id'    => $team_id,
				'scope'      => $as_norm['scope'],
				'scope_id'   => $as_norm['scope_id'],
				'role'       => $role,
				'subteam_id' => $sub,
				'subrole'    => $subrole,
				'status'     => self::STATUS_PENDING,
				'invited_by' => max( 0, $by ),
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Whether this invitation already had a successful send (so the next is a resend).
	 *
	 * @param int $id Invitation ID.
	 */
	public static function was_delivered( int $id ): bool {
		$row = self::get( $id );
		return is_array( $row ) && ! empty( $row['sent_at'] );
	}

	/**
	 * Record that the invitation mail went out.
	 *
	 * @param int    $id     Invitation ID.
	 * @param int    $by     Sender user ID.
	 * @param string $token  Signed token body.
	 * @param bool   $resent True when this is a later send of the same offer.
	 */
	public static function record_delivery( int $id, int $by, string $token, bool $resent ): void {
		global $wpdb;

		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + Accounts::INVITE_TTL );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table(),
			array(
				'status'     => self::STATUS_PENDING,
				'invited_by' => max( 0, $by ),
				'sent_at'    => $now,
				'expires_at' => $expires,
				'token_hash' => self::token_hash( $token ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		self::add_event( $id, $resent ? self::EVENT_RESENT : self::EVENT_SENT, $by );
	}

	/**
	 * Mark an invitation accepted.
	 *
	 * @param int $id      Invitation ID.
	 * @param int $user_id User who accepted.
	 */
	public static function accept( int $id, int $user_id ): void {
		global $wpdb;

		$row = self::get( $id );
		if ( ! is_array( $row ) || self::STATUS_ACCEPTED === ( $row['status'] ?? '' ) || self::STATUS_REVOKED === ( $row['status'] ?? '' ) ) {
			return;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table(),
			array(
				'status'           => self::STATUS_ACCEPTED,
				'accepted_at'      => current_time( 'mysql', true ),
				'accepted_user_id' => $user_id,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
		self::add_event( $id, self::EVENT_ACCEPTED, $user_id );
	}

	/**
	 * Whether claims refer to a revoked invitation.
	 *
	 * @param array{email?: string, team?: int, role?: string, subteam?: int, subrole?: string, invite_id?: int} $claims From Accounts::read().
	 */
	public static function is_revoked( array $claims ): bool {
		$id = (int) ( $claims['invite_id'] ?? 0 );
		if ( $id > 0 ) {
			$row = self::get( $id );
			return is_array( $row ) && self::STATUS_REVOKED === ( $row['status'] ?? '' );
		}
		$team  = (int) ( $claims['team'] ?? 0 );
		$email = strtolower( (string) ( $claims['email'] ?? '' ) );
		if ( $team <= 0 || ! is_email( $email ) ) {
			return false;
		}
		$key = self::offer_key(
			$team,
			$email,
			array(
				'role'    => $claims['role'] ?? 'member',
				'subteam' => (int) ( $claims['subteam'] ?? 0 ),
				'subrole' => $claims['subrole'] ?? 'member',
			)
		);
		global $wpdb;
		$status = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT status FROM ' . self::table() . ' WHERE offer_key = %s', $key ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		return self::STATUS_REVOKED === $status;
	}

	/**
	 * Accept by invitation id in claims, or by offer key when the link is older.
	 *
	 * @param array{kind?: string, email?: string, team?: int, role?: string, subteam?: int, subrole?: string, invite_id?: int} $claims From Accounts::read().
	 * @param int                                                                                                               $user_id User who accepted.
	 */
	public static function accept_by_claims( array $claims, int $user_id ): void {
		$id = (int) ( $claims['invite_id'] ?? 0 );
		if ( $id > 0 ) {
			$row = self::get( $id );
			if ( is_array( $row ) && self::STATUS_REVOKED === ( $row['status'] ?? '' ) ) {
				return;
			}
			self::accept( $id, $user_id );
			return;
		}
		$team  = (int) ( $claims['team'] ?? 0 );
		$email = strtolower( (string) ( $claims['email'] ?? '' ) );
		if ( $team <= 0 || ! is_email( $email ) ) {
			return;
		}
		$key = self::offer_key(
			$team,
			$email,
			array(
				'role'    => $claims['role'] ?? 'member',
				'subteam' => (int) ( $claims['subteam'] ?? 0 ),
				'subrole' => $claims['subrole'] ?? 'member',
			)
		);
		global $wpdb;
		$found = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE offer_key = %s', $key ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		if ( $found > 0 ) {
			self::accept( $found, $user_id );
		}
	}

	/**
	 * Coordinator re-send: new token, fourteen more days, no daily rate limit.
	 *
	 * @param int $id Invitation ID.
	 * @param int $by Coordinator user ID.
	 * @return true|\WP_Error
	 */
	public static function resend( int $id, int $by ) {
		if ( ! Capabilities::can( Capabilities::MODERATE, $by ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'You are not allowed to do that.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$row = self::get( $id );
		if ( ! is_array( $row ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That invitation was not found.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( self::STATUS_ACCEPTED === ( $row['status'] ?? '' ) ) {
			return new \WP_Error( 'packout_invite_accepted', __( 'That invitation was already accepted.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( self::STATUS_REVOKED === ( $row['status'] ?? '' ) ) {
			return new \WP_Error( 'packout_invite_revoked', __( 'That invitation was revoked.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( ! Accounts::open() ) {
			return new \WP_Error( 'packout_invite_closed', __( 'This site has no sign-up page yet, so an invitation cannot be sent.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( 'partner' === $row['scope'] ? ! Partners::get( (int) $row['scope_id'] ) : ! Teams::get( (int) $row['team_id'] ) ) {
			return new \WP_Error( 'packout_not_found', 'partner' === $row['scope'] ? __( 'That partner does not exist.', 'packout-core' ) : __( 'That organization does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}

		$as   = array(
			'role'     => (string) $row['role'],
			'subteam'  => (int) $row['subteam_id'],
			'subrole'  => (string) $row['subrole'],
			'scope'    => (string) $row['scope'],
			'scope_id' => (int) $row['scope_id'],
		);
		$sent = self::deliver( (int) $row['team_id'], (string) $row['email'], $as, $by, $id, true );
		return $sent ? true : new \WP_Error( 'packout_account_mail', __( 'The invitation could not be sent. Try again in a few minutes.', 'packout-core' ), array( 'status' => 500 ) );
	}

	/**
	 * Revoke a pending or expired invitation.
	 *
	 * @param int $id Invitation ID.
	 * @param int $by Actor user ID.
	 * @return true|\WP_Error
	 */
	public static function revoke( int $id, int $by ) {
		if ( ! Capabilities::can( Capabilities::MODERATE, $by ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'You are not allowed to do that.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$row = self::get( $id );
		if ( ! is_array( $row ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That invitation was not found.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( self::STATUS_ACCEPTED === ( $row['status'] ?? '' ) ) {
			return new \WP_Error( 'packout_invite_accepted', __( 'That invitation was already accepted.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( self::STATUS_REVOKED === ( $row['status'] ?? '' ) ) {
			return true;
		}
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table(),
			array( 'status' => self::STATUS_REVOKED ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
		self::add_event( $id, self::EVENT_REVOKED, $by );
		return true;
	}

	/**
	 * Send the invitation email and record delivery. Used by Teams::invite, Partners::invite, and resend.
	 *
	 * @param int                  $team_id       Organization ID. 0 for a partner's invitation.
	 * @param string               $email         Email address.
	 * @param array<string, mixed> $seat            role, subteam, subrole. For a partner: scope `partner` and scope_id.
	 * @param int                  $by            Sender user ID.
	 * @param int                  $invitation_id Invitation row ID.
	 * @param bool                 $resent        Whether this is a later send.
	 */
	public static function deliver( int $team_id, string $email, array $seat, int $by, int $invitation_id, bool $resent ): bool {
		if ( self::partner_of( $seat ) > 0 ) {
			return self::deliver_partner( self::partner_of( $seat ), $email, $by, $invitation_id, $resent );
		}
		$team = Teams::get( $team_id );
		if ( ! $team ) {
			return false;
		}
		$from    = get_userdata( $by );
		$name    = $from ? $from->display_name : __( 'An organization lead', 'packout-core' );
		$role    = 'colead' === ( $seat['role'] ?? '' ) ? 'colead' : 'member';
		$sub     = (int) ( $seat['subteam'] ?? 0 );
		$subrole = in_array( $seat['subrole'] ?? '', array( 'colead', 'lead' ), true ) ? (string) $seat['subrole'] : 'member';
		$subteam = $sub > 0 ? Subteams::get( $team_id, $sub ) : null;

		if ( 'colead' === $role ) {
			/* translators: %s: organization name. */
			$subject = sprintf( __( 'You are invited to co-lead %s', 'packout-core' ), $team->post_title );
			/* translators: 1: the lead's name, 2: organization name. */
			$offer = sprintf( __( '%1$s invited you to co-lead “%2$s” on Pack-Out Spot, where crews find, plan, and document cleanups. A co-lead manages the organization\'s members, teams, campaigns, and missions with its lead.', 'packout-core' ), $name, $team->post_title );
		} else {
			/* translators: %s: organization name. */
			$subject = sprintf( __( 'You are invited to join %s', 'packout-core' ), $team->post_title );
			/* translators: 1: the lead's name, 2: organization name. */
			$offer = sprintf( __( '%1$s invited you to join “%2$s” on Pack-Out Spot, where crews find, plan, and document cleanups.', 'packout-core' ), $name, $team->post_title );
		}
		$place = '';
		if ( $subteam ) {
			$places = array(
				/* translators: %s: team name. */
				'member' => __( 'You would be in its team “%s”.', 'packout-core' ),
				/* translators: %s: team name. */
				'colead' => __( 'You would co-lead its team “%s”.', 'packout-core' ),
				/* translators: %s: team name. */
				'lead'   => __( 'You would lead its team “%s”.', 'packout-core' ),
			);
			$place  = sprintf( $places[ $subrole ], $subteam['name'] );
		}

		$token = Accounts::invite_token(
			$email,
			$team_id,
			array(
				'role'    => $role,
				'subteam' => $subteam ? $sub : 0,
				'subrole' => $subrole,
			),
			$by,
			$invitation_id
		);
		$link  = Accounts::page( 'signup_page', array( 'pos_confirm' => $token ) );
		$sent  = Accounts::mail(
			$email,
			$subject,
			array(
				$offer,
				$place,
				__( 'Follow this link to accept. It is good for fourteen days.', 'packout-core' ),
				$link,
				__( 'If you do not know this organization, ignore this message.', 'packout-core' ),
			)
		);
		if ( $sent ) {
			self::record_delivery( $invitation_id, $by, $token, $resent );
		}
		return $sent;
	}

	/**
	 * Send a partner's invitation to co-lead it, and record delivery.
	 *
	 * @param int    $partner_id    Partner ID.
	 * @param string $email         Email address.
	 * @param int    $by            Sender user ID.
	 * @param int    $invitation_id Invitation row ID.
	 * @param bool   $resent        Whether this is a later send.
	 */
	private static function deliver_partner( int $partner_id, string $email, int $by, int $invitation_id, bool $resent ): bool {
		$partner = Partners::get( $partner_id );
		if ( ! $partner ) {
			return false;
		}
		$from  = get_userdata( $by );
		$token = Accounts::invite_token(
			$email,
			0,
			array(
				'role'    => 'colead',
				'partner' => $partner_id,
			),
			$by,
			$invitation_id
		);
		$sent  = Accounts::mail(
			$email,
			/* translators: %s: partner name. */
			sprintf( __( 'You are invited to co-lead %s', 'packout-core' ), $partner->post_title ),
			array(
				/* translators: 1: the lead's name, 2: partner name. */
				sprintf( __( '%1$s invited you to co-lead the partner “%2$s” on Pack-Out Spot, where crews find, plan, and document cleanups. A co-lead manages the partner\'s profile, its programs, and its organizations with its lead.', 'packout-core' ), $from ? $from->display_name : __( 'A partner lead', 'packout-core' ), $partner->post_title ),
				__( 'Follow this link to accept. It is good for fourteen days.', 'packout-core' ),
				Accounts::page( 'signup_page', array( 'pos_confirm' => $token ) ),
				__( 'If you do not know this partner, ignore this message.', 'packout-core' ),
			)
		);
		if ( $sent ) {
			self::record_delivery( $invitation_id, $by, $token, $resent );
		}
		return $sent;
	}

	/**
	 * One invitation row, or null.
	 *
	 * @param int $id Invitation ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? self::normalize( $row ) : null;
	}

	/**
	 * Events for an invitation, newest first.
	 *
	 * @param int $id Invitation ID.
	 * @return array<int, array{event: string, actor_user_id: int, created_at: string, label: string, actor: string}>
	 */
	public static function events( int $id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM ' . self::events_table() . ' WHERE invitation_id = %d ORDER BY id DESC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$id
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$actor_id = (int) ( $row['actor_user_id'] ?? 0 );
			$actor    = $actor_id > 0 ? get_userdata( $actor_id ) : null;
			$out[]    = array(
				'event'         => (string) ( $row['event'] ?? '' ),
				'actor_user_id' => $actor_id,
				'created_at'    => (string) ( $row['created_at'] ?? '' ),
				'label'         => self::event_label( (string) ( $row['event'] ?? '' ), $actor ? $actor->display_name : '' ),
				'actor'         => $actor ? $actor->display_name : '',
			);
		}
		return $out;
	}

	/**
	 * List invitations for the admin screen.
	 *
	 * @param array<string, mixed> $filters status, s, team, partner, by.
	 * @param int                  $page    1-based page.
	 * @param int                  $per_page Rows per page.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function list( array $filters, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		self::mark_expired_batch();

		$status = isset( $filters['status'] ) ? (string) $filters['status'] : self::STATUS_PENDING;
		$team   = (int) ( $filters['team'] ?? 0 );
		$by     = (int) ( $filters['by'] ?? 0 );
		$search = strtolower( trim( (string) ( $filters['s'] ?? '' ) ) );
		$page   = max( 1, $page );
		$per    = max( 1, min( 100, $per_page ) );
		$offset = ( $page - 1 ) * $per;

		$where  = array( '1=1' );
		$params = array();

		if ( self::STATUS_PENDING === $status ) {
			$where[]  = 'status = %s AND expires_at >= UTC_TIMESTAMP()';
			$params[] = self::STATUS_PENDING;
		} elseif ( self::STATUS_EXPIRED === $status ) {
			$where[]  = '( status = %s OR ( status = %s AND expires_at < UTC_TIMESTAMP() ) )';
			$params[] = self::STATUS_EXPIRED;
			$params[] = self::STATUS_PENDING;
		} elseif ( self::STATUS_ACCEPTED === $status || self::STATUS_REVOKED === $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		} elseif ( 'all' !== $status && '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = self::STATUS_PENDING;
		}

		if ( $team > 0 ) {
			$where[]  = 'team_id = %d';
			$params[] = $team;
		}
		if ( (int) ( $filters['partner'] ?? 0 ) > 0 ) {
			$where[]  = 'scope = %s AND scope_id = %d';
			$params[] = 'partner';
			$params[] = (int) $filters['partner'];
		}
		if ( $by > 0 ) {
			$where[]  = 'invited_by = %d';
			$params[] = $by;
		}
		if ( '' !== $search ) {
			$where[]  = 'email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$sql_where = implode( ' AND ', $where );
		$table     = self::table();
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$list_sql  = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY sent_at DESC, id DESC LIMIT %d OFFSET %d";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$list_params   = $params;
		$list_params[] = $per;
		$list_params[] = $offset;
		$rows          = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $list_sql, $list_params ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = self::normalize( $row );
		}
		return array(
			'rows'  => $out,
			'total' => $total,
		);
	}

	/**
	 * Counts for overview cards.
	 *
	 * @return array{pending: int, accepted: int, expired: int}
	 */
	public static function counts(): array {
		global $wpdb;
		self::mark_expired_batch();
		$table = self::table();
		return array(
			'pending'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND expires_at >= UTC_TIMESTAMP()" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'accepted' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'accepted'" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'expired'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'expired' OR ( status = 'pending' AND expires_at < UTC_TIMESTAMP() )" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Human label for the offer on a row.
	 *
	 * @param array<string, mixed> $row Invitation row.
	 */
	public static function offer_label( array $row ): string {
		if ( 'partner' === ( $row['scope'] ?? '' ) ) {
			return __( 'Partner co-lead', 'packout-core' );
		}
		$role = 'colead' === ( $row['role'] ?? '' ) ? __( 'Organization co-lead', 'packout-core' ) : __( 'Member', 'packout-core' );
		$sub  = (int) ( $row['subteam_id'] ?? 0 );
		if ( $sub <= 0 ) {
			return $role;
		}
		$team = Subteams::get( (int) $row['team_id'], $sub );
		$name = $team['name'] ?? __( 'Team', 'packout-core' );
		$sr   = (string) ( $row['subrole'] ?? 'member' );
		if ( 'lead' === $sr ) {
			/* translators: %s: team name. */
			return sprintf( __( 'Lead of “%s”', 'packout-core' ), $name );
		}
		if ( 'colead' === $sr ) {
			/* translators: %s: team name. */
			return sprintf( __( 'Co-lead of “%s”', 'packout-core' ), $name );
		}
		/* translators: %s: team name. */
		return sprintf( __( 'Member of “%s”', 'packout-core' ), $name );
	}

	/**
	 * Display status for a row (pending vs expired by time).
	 *
	 * @param array<string, mixed> $row Invitation row.
	 */
	public static function display_status( array $row ): string {
		$status = (string) ( $row['status'] ?? '' );
		if ( self::STATUS_PENDING === $status && ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] . ' UTC' ) < time() ) {
			return self::STATUS_EXPIRED;
		}
		return $status;
	}

	/**
	 * Status labels for filters and badges.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_PENDING  => __( 'Pending', 'packout-core' ),
			self::STATUS_ACCEPTED => __( 'Accepted', 'packout-core' ),
			self::STATUS_EXPIRED  => __( 'Expired', 'packout-core' ),
			self::STATUS_REVOKED  => __( 'Revoked', 'packout-core' ),
		);
	}

	/**
	 * Mark overdue pending rows as expired (idempotent).
	 */
	public static function mark_expired_batch(): void {
		global $wpdb;
		$table = self::table();
		$ids   = $wpdb->get_col( "SELECT id FROM {$table} WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP() LIMIT 200" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$id = (int) $id;
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table,
				array( 'status' => self::STATUS_EXPIRED ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
			$already = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . self::events_table() . ' WHERE invitation_id = %d AND event = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$id,
					self::EVENT_EXPIRED
				)
			);
			if ( 0 === $already ) {
				self::add_event( $id, self::EVENT_EXPIRED, 0 );
			}
		}
	}

	/**
	 * Append an event.
	 *
	 * @param int                  $id      Invitation ID.
	 * @param string               $event   Event name.
	 * @param int                  $actor   Actor user ID.
	 * @param array<string, mixed> $meta    Optional meta.
	 */
	private static function add_event( int $id, string $event, int $actor, array $meta = array() ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::events_table(),
			array(
				'invitation_id' => $id,
				'event'         => $event,
				'actor_user_id' => max( 0, $actor ),
				'created_at'    => current_time( 'mysql', true ),
				'meta'          => $meta ? wp_json_encode( $meta ) : null,
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Normalize a database row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function normalize( array $row ): array {
		return array(
			'id'               => (int) ( $row['id'] ?? 0 ),
			'offer_key'        => (string) ( $row['offer_key'] ?? '' ),
			'email'            => (string) ( $row['email'] ?? '' ),
			'team_id'          => (int) ( $row['team_id'] ?? 0 ),
			'scope'            => 'partner' === ( $row['scope'] ?? '' ) ? 'partner' : 'team',
			'scope_id'         => (int) ( $row['scope_id'] ?? ( $row['team_id'] ?? 0 ) ),
			'role'             => (string) ( $row['role'] ?? 'member' ),
			'subteam_id'       => (int) ( $row['subteam_id'] ?? 0 ),
			'subrole'          => (string) ( $row['subrole'] ?? 'member' ),
			'status'           => (string) ( $row['status'] ?? '' ),
			'invited_by'       => (int) ( $row['invited_by'] ?? 0 ),
			'sent_at'          => (string) ( $row['sent_at'] ?? '' ),
			'expires_at'       => (string) ( $row['expires_at'] ?? '' ),
			'accepted_at'      => (string) ( $row['accepted_at'] ?? '' ),
			'accepted_user_id' => isset( $row['accepted_user_id'] ) ? (int) $row['accepted_user_id'] : 0,
			'token_hash'       => (string) ( $row['token_hash'] ?? '' ),
		);
	}

	/**
	 * Timeline label for an event.
	 *
	 * @param string $event Event name.
	 * @param string $actor Display name, or empty.
	 */
	private static function event_label( string $event, string $actor ): string {
		$name = '' !== $actor ? $actor : __( 'Someone', 'packout-core' );
		switch ( $event ) {
			case self::EVENT_SENT:
				/* translators: %s: person's name. */
				return sprintf( __( '%s sent an invitation', 'packout-core' ), $name );
			case self::EVENT_RESENT:
				/* translators: %s: person's name. */
				return sprintf( __( '%s sent it again', 'packout-core' ), $name );
			case self::EVENT_ACCEPTED:
				/* translators: %s: person's name. */
				return sprintf( __( '%s accepted', 'packout-core' ), $name );
			case self::EVENT_EXPIRED:
				return __( 'Link expired', 'packout-core' );
			case self::EVENT_REVOKED:
				/* translators: %s: person's name. */
				return sprintf( __( '%s revoked the invitation', 'packout-core' ), $name );
			default:
				return $event;
		}
	}
}
