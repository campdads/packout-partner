<?php
/**
 * Joint campaigns: one campaign that several organizations work in.
 *
 * The record is on packout.spot. It is an ordinary campaign with a field
 * that says it is joint and a list of who takes part. Its host is the
 * organization it belongs to: one of the hub's own, or one of a partner
 * site's, in which case the campaign is that site's and the hub keeps a copy
 * as it does of any listing. Who takes part is decided on the hub alone.
 *
 * A participant is an organization: one of the hub's own, whose lead answers
 * in the workspace, or one a partner site lists, whose site answers through
 * the partner interface. Once it has accepted, its missions can be filed
 * under the campaign. A partner site keeps a copy of the campaign that
 * nobody there can change, files its missions under the copy, and sends
 * them with the hub's number for the campaign.
 *
 * The hub counts its own missions and takes each partner site's figures as
 * that site reports them. The lines stay labeled: nobody is ranked, and a
 * partner's figures are never shown as the hub's.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

defined( 'ABSPATH' ) || exit;

/**
 * A campaign that more than one organization takes part in.
 */
final class Joint {

	/**
	 * The field that says a campaign is joint: `open` while it takes participants and their missions, `closed` after.
	 */
	public const FLAG = '_pos_joint';

	/**
	 * The list of who takes part, on the hub's record.
	 */
	public const PARTS = 'joint_parts';

	/**
	 * On a partner site's copy: the hub's number for the campaign.
	 */
	public const HUB = '_pos_hub_campaign';

	/**
	 * On a partner site's copy: who hosts the campaign.
	 */
	public const HOST = '_pos_hub_host';

	/**
	 * On a partner site's copy: the campaign's page on the hub.
	 */
	public const URL = '_pos_hub_campaign_url';

	/**
	 * On a partner site's copy: how each organization of the site stands, by organization.
	 */
	public const STATES = 'joint_states';

	/**
	 * On a partner site: what the hub last said about joint campaigns.
	 */
	public const OPTION = 'packout_joint';

	/**
	 * The workspace's forms this class answers.
	 */
	private const ACTIONS = array( 'joint_open', 'joint_close', 'joint_invite', 'joint_remove', 'joint_accept', 'joint_decline', 'joint_leave' );

	/**
	 * The joint campaigns of this request, once found.
	 *
	 * @var array<int, int>|null
	 */
	private static ?array $found = null;

	/**
	 * Hook the pull of a partner site.
	 */
	public static function register(): void {
		if ( App::is_hub() ) {
			return;
		}
		add_action( 'packout_exchange_pulled', array( self::class, 'pull' ) );
	}

	/**
	 * Whether a campaign is joint.
	 *
	 * @param int $id Campaign ID.
	 */
	public static function is_joint( int $id ): bool {
		return $id > 0 && in_array( get_post_meta( $id, self::FLAG, true ), array( 'open', 'closed' ), true ) && null !== Campaigns::get( $id );
	}

	/**
	 * Whether a joint campaign takes no more participants, and no more missions from the ones it has.
	 *
	 * @param int $id Campaign ID.
	 */
	public static function closed( int $id ): bool {
		return 'closed' === get_post_meta( $id, self::FLAG, true );
	}

	/**
	 * Whether a campaign on a partner site is a copy of one the hub holds.
	 *
	 * @param int $id Campaign ID.
	 */
	public static function is_copy( int $id ): bool {
		return $id > 0 && ! App::is_hub() && (int) get_post_meta( $id, self::HUB, true ) > 0;
	}

	/**
	 * The hub's number for a joint campaign: the record itself on the hub, and what a copy names on a partner site.
	 *
	 * @param int $id Campaign ID here.
	 */
	public static function number( int $id ): int {
		return App::is_hub() ? $id : (int) get_post_meta( $id, self::HUB, true );
	}

	/**
	 * Every joint campaign the hub holds.
	 *
	 * @return array<int, int>
	 */
	private static function all(): array {
		if ( null === self::$found ) {
			self::$found = array_map(
				'intval',
				get_posts(
					array(
						'post_type'        => Schema::CAMPAIGN,
						'post_status'      => array( 'publish', 'private' ),
						'numberposts'      => 500,
						'fields'           => 'ids',
						'orderby'          => 'ID',
						'order'            => 'DESC',
						'suppress_filters' => true,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The joint campaigns, which are few.
						'meta_key'         => App::is_hub() ? self::FLAG : self::HUB,
					)
				)
			);
		}
		return self::$found;
	}

	/**
	 * Every joint campaign the hub holds, the newest first.
	 *
	 * @return array<int, int>
	 */
	public static function every(): array {
		return App::is_hub() ? self::all() : array();
	}

	/**
	 * This site's copies of the joint campaigns its organizations take part in, or took part in.
	 *
	 * @return array<int, int>
	 */
	public static function copies(): array {
		return App::is_hub() ? array() : self::all();
	}

	/**
	 * Forget what this request found.
	 */
	public static function flush(): void {
		self::$found = null;
	}

	/**
	 * Who takes part in a joint campaign, as the hub holds it.
	 *
	 * @param int $id Campaign ID.
	 * @return array<int, array{org: int, state: string, invited: string, answered: string, left: string}> By organization. An organization that no longer exists is left out.
	 */
	public static function parts( int $id ): array {
		$rows = array();
		foreach ( Schema::stored_list( $id, self::PARTS ) as $row ) {
			$org = (int) ( $row['org'] ?? 0 );
			if ( $org > 0 && Teams::get( $org ) && in_array( $row['state'] ?? '', array( 'invited', 'accepted', 'declined', 'left' ), true ) ) {
				$rows[ $org ] = array(
					'org'      => $org,
					'state'    => (string) $row['state'],
					'invited'  => Schema::day( $row['invited'] ?? '' ),
					'answered' => Schema::day( $row['answered'] ?? '' ),
					'left'     => Schema::day( $row['left'] ?? '' ),
				);
			}
		}
		return $rows;
	}

	/**
	 * Store who takes part. The hub's copy of a partner site's campaign is written through the seal: its participant list is the hub's.
	 *
	 * @param int                              $id   Campaign ID.
	 * @param array<int, array<string, mixed>> $rows The rows.
	 */
	private static function write_parts( int $id, array $rows ): void {
		Remote::write(
			static function () use ( $id, $rows ): void {
				Schema::write_list( $id, self::PARTS, array_values( $rows ) );
			}
		);
		self::flush();
	}

	/**
	 * How an organization stands in a joint campaign.
	 *
	 * @param int $id  Campaign ID here.
	 * @param int $org Organization ID here.
	 * @return string `invited`, `accepted`, `declined`, `left`, or empty.
	 */
	public static function state( int $id, int $org ): string {
		if ( App::is_hub() ) {
			return self::parts( $id )[ $org ]['state'] ?? '';
		}
		$states = Schema::stored_list( $id, self::STATES );
		return (string) ( $states[ (string) $org ] ?? '' );
	}

	/**
	 * Whether a person runs a joint campaign as its host: a lead of the organization it belongs to, or a coordinator.
	 *
	 * @param int $id      Campaign ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_host( int $id, int $user_id = -1 ): bool {
		$user_id = $user_id < 0 ? get_current_user_id() : $user_id;
		if ( $user_id <= 0 || ! Campaigns::get( $id ) || self::is_copy( $id ) ) {
			return false;
		}
		return Capabilities::can( Capabilities::MODERATE, $user_id ) || ( ! Remote::is( $id ) && Campaigns::can_manage( Campaigns::team( $id ), $user_id ) );
	}

	/**
	 * Whether a person answers for an organization: its lead, a co-lead, a lead of its partner, or a coordinator.
	 *
	 * @param int $org     Organization ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_answer( int $org, int $user_id = -1 ): bool {
		return null !== Teams::get( $org ) && Campaigns::can_manage( $org, $user_id );
	}

	/**
	 * Whether an organization is one the public sees: approved, and with a page.
	 *
	 * @param int $org Organization ID.
	 */
	public static function listed( int $org ): bool {
		$post = Teams::get( $org );
		return $post && 'publish' === $post->post_status && Teams::active( $org ) && Post_Access::can_read( $post, 0 );
	}

	/**
	 * What to call an organization, and whose it is.
	 *
	 * @param int $org Organization ID on the hub.
	 * @return array{id: int, name: string, partner: string, partner_id: int, url: string}
	 */
	public static function named( int $org ): array {
		$post    = Teams::get( $org );
		$partner = $post ? ( Remote::is( $org ) ? Remote::partner( $org ) : Partners::of_team( $org ) ) : 0;
		$owner   = $partner > 0 ? Partners::get( $partner ) : null;
		return array(
			'id'         => $org,
			'name'       => $post ? $post->post_title : '',
			'partner'    => $owner ? $owner->post_title : '',
			'partner_id' => $owner ? $owner->ID : 0,
			'url'        => $post && 'publish' === $post->post_status ? (string) get_permalink( $post ) : '',
		);
	}

	/**
	 * Why a campaign cannot be made joint, or null when it can.
	 *
	 * @param int $id Campaign ID.
	 */
	private static function unfit( int $id ): ?\WP_Error {
		$post = Campaigns::get( $id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new \WP_Error( 'packout_joint_private', __( 'Share the campaign with the public first. Other organizations can only join a campaign they can see.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( ! self::listed( Campaigns::team( $id ) ) ) {
			return new \WP_Error( 'packout_joint_unlisted', __( 'A joint campaign is hosted by an organization that is approved and has a public page.', 'packout-core' ), array( 'status' => 409 ) );
		}
		return null;
	}

	/**
	 * Make a campaign joint.
	 *
	 * @param int $id      Campaign ID.
	 * @param int $user_id The person: its host.
	 * @return true|\WP_Error
	 */
	public static function open( int $id, int $user_id ) {
		if ( ! self::can_host( $id, $user_id ) || Remote::is( $id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'A lead of the organization that owns the campaign makes it a joint campaign.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$unfit = self::unfit( $id );
		if ( $unfit ) {
			return $unfit;
		}
		if ( ! App::is_hub() ) {
			if ( ! Connection::sending() ) {
				return new \WP_Error( 'packout_not_connected', __( 'This site is not sending to packout.spot now. See the Connection screen.', 'packout-core' ), array( 'status' => 409 ) );
			}
			if ( ! Listings::listable( 'campaign', (string) $id ) ) {
				return new \WP_Error( 'packout_not_listed', __( 'packout.spot lists a campaign once it and its organization are public here. This one is not yet.', 'packout-core' ), array( 'status' => 409 ) );
			}
		}
		update_post_meta( $id, self::FLAG, 'open' );
		self::flush();
		if ( ! App::is_hub() ) {
			// The hub learns of it with the campaign's listing, and says in its next answer that it has.
			Outbox::listing_again( 'campaign', (string) $id );
			Outbox::run();
			if ( ! isset( self::known()['hosted'][ (string) $id ] ) ) {
				self::pull();
			}
		}
		/**
		 * A campaign was made joint.
		 *
		 * @param int $id Campaign ID.
		 */
		do_action( 'packout_joint_opened', $id );
		return true;
	}

	/**
	 * Close a joint campaign: it takes no more participants, and no participant can file another mission under it. What was done stays.
	 *
	 * @param int $id      Campaign ID.
	 * @param int $user_id The person: its host.
	 * @return true|\WP_Error
	 */
	public static function close( int $id, int $user_id ) {
		if ( ! self::is_joint( $id ) || ! self::can_host( $id, $user_id ) || Remote::is( $id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'A lead of the organization that hosts the campaign closes it.', 'packout-core' ), array( 'status' => 403 ) );
		}
		update_post_meta( $id, self::FLAG, 'closed' );
		self::flush();
		if ( App::is_hub() ) {
			self::told( $id, 0, 'closed' );
		} else {
			Outbox::listing_again( 'campaign', (string) $id );
		}
		return true;
	}

	/**
	 * A partner site closed the joint campaign it hosts. The hub tells the organizations that take part.
	 *
	 * @param int $id Campaign ID on the hub.
	 */
	public static function host_closed( int $id ): void {
		if ( App::is_hub() && self::is_joint( $id ) ) {
			self::flush();
			self::told( $id, 0, 'closed' );
		}
	}

	/**
	 * Why an organization cannot be invited to a joint campaign, or null when it can.
	 *
	 * @param int $id  Campaign ID on the hub.
	 * @param int $org Organization ID on the hub.
	 */
	private static function uninvitable( int $id, int $org ): ?\WP_Error {
		if ( ! self::is_joint( $id ) || self::closed( $id ) ) {
			return new \WP_Error( 'packout_joint_closed', __( 'That campaign is not taking participants.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$unfit = self::unfit( $id );
		if ( $unfit ) {
			return $unfit;
		}
		$host = Campaigns::team( $id );
		if ( ! self::listed( $org ) || $org === $host ) {
			return new \WP_Error( 'packout_joint_org', __( 'Choose an organization that packout.spot lists.', 'packout-core' ), array( 'status' => 400 ) );
		}
		// The organizations of the host's own partner site file their missions on that site, under the campaign itself.
		if ( Remote::is( $host ) && Remote::is( $org ) && Remote::partner( $host ) === Remote::partner( $org ) ) {
			return new \WP_Error( 'packout_joint_org', __( 'That organization is on the host\'s own site. Its missions are filed under the campaign there.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( in_array( self::state( $id, $org ), array( 'invited', 'accepted' ), true ) ) {
			return new \WP_Error( 'packout_joint_twice', __( 'That organization is already invited.', 'packout-core' ), array( 'status' => 409 ) );
		}
		return null;
	}

	/**
	 * Invite an organization, on the hub. Nobody is checked here: the caller has checked who asks.
	 *
	 * @param int $id  Campaign ID.
	 * @param int $org Organization ID.
	 * @return true|\WP_Error
	 */
	public static function host_invite( int $id, int $org ) {
		if ( ! App::is_hub() ) {
			return App::hub_only();
		}
		$refused = self::uninvitable( $id, $org );
		if ( $refused ) {
			return $refused;
		}
		$rows         = self::parts( $id );
		$rows[ $org ] = array(
			'org'      => $org,
			'state'    => 'invited',
			'invited'  => wp_date( 'Y-m-d' ),
			'answered' => '',
			'left'     => '',
		);
		self::write_parts( $id, $rows );
		self::told( $id, $org, 'invited' );
		return true;
	}

	/**
	 * Take an organization out of a joint campaign, on the hub. Nobody is checked here.
	 *
	 * One that took part keeps its history and stands as having left. One that had not answered is forgotten.
	 *
	 * @param int $id  Campaign ID.
	 * @param int $org Organization ID.
	 * @return true|\WP_Error
	 */
	public static function host_remove( int $id, int $org ) {
		if ( ! App::is_hub() ) {
			return App::hub_only();
		}
		$rows = self::parts( $id );
		if ( ! isset( $rows[ $org ] ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That organization is not in the campaign.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( 'accepted' === $rows[ $org ]['state'] ) {
			$rows[ $org ]['state'] = 'left';
			$rows[ $org ]['left']  = wp_date( 'Y-m-d' );
		} elseif ( 'left' !== $rows[ $org ]['state'] ) {
			unset( $rows[ $org ] );
		}
		self::write_parts( $id, $rows );
		self::told( $id, $org, 'removed' );
		return true;
	}

	/**
	 * An organization answers or leaves, on the hub. Nobody is checked here.
	 *
	 * @param int    $id  Campaign ID.
	 * @param int    $org Organization ID.
	 * @param string $to  `accepted`, `declined`, or `left`.
	 * @return string|\WP_Error How it now stands.
	 */
	public static function decide( int $id, int $org, string $to ) {
		if ( ! App::is_hub() ) {
			return App::hub_only();
		}
		$rows = self::parts( $id );
		$now  = $rows[ $org ]['state'] ?? '';
		if ( ! self::is_joint( $id ) || '' === $now ) {
			return new \WP_Error( 'packout_not_found', __( 'That organization is not invited to the campaign.', 'packout-core' ), array( 'status' => 404 ) );
		}
		// Leaving before answering is declining.
		if ( 'left' === $to && 'invited' === $now ) {
			$to = 'declined';
		}
		$allowed = array(
			'accepted' => array( 'invited' ),
			'declined' => array( 'invited' ),
			'left'     => array( 'accepted' ),
		);
		if ( $now === $to ) {
			return $to;
		}
		if ( ! in_array( $now, $allowed[ $to ] ?? array(), true ) ) {
			return new \WP_Error( 'packout_joint_state', 'accepted' === $to ? __( 'There is no invitation to accept. Ask the host to invite the organization again.', 'packout-core' ) : __( 'The organization is not taking part in that campaign.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( 'accepted' === $to && ( self::closed( $id ) || self::unfit( $id ) ) ) {
			return new \WP_Error( 'packout_joint_closed', __( 'That campaign is not taking participants.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$rows[ $org ]['state']    = $to;
		$rows[ $org ]['answered'] = 'left' === $to ? $rows[ $org ]['answered'] : wp_date( 'Y-m-d' );
		$rows[ $org ]['left']     = 'left' === $to ? wp_date( 'Y-m-d' ) : '';
		self::write_parts( $id, $rows );
		self::told( $id, $org, $to );
		return $to;
	}

	/**
	 * Tell the people a change concerns.
	 *
	 * A hub organization's leads are sent an email. A partner site is told
	 * by the hub's plugin, which hears the action.
	 *
	 * @param int    $id    Campaign ID.
	 * @param int    $org   The organization it is about, or 0 for all of them.
	 * @param string $event `invited`, `accepted`, `declined`, `left`, `removed`, or `closed`.
	 */
	private static function told( int $id, int $org, string $event ): void {
		$campaign = Campaigns::get( $id );
		if ( ! $campaign ) {
			return;
		}
		$host  = Campaigns::team( $id );
		$name  = $org > 0 ? self::named( $org )['name'] : '';
		$hosts = self::named( $host )['name'];
		$mail  = static function ( int $team, string $subject, string $line ) use ( $campaign ): void {
			if ( Remote::is( $team ) ) {
				return;
			}
			foreach ( array_filter( array_map( 'get_userdata', Teams::leads( $team ) ) ) as $lead ) {
				Accounts::mail(
					$lead->user_email,
					$subject,
					array(
						$line,
						Accounts::workspace_url(
							array(
								'team' => $team,
								'view' => 'campaigns',
							)
						),
						(string) get_permalink( $campaign ),
					)
				);
			}
		};
		if ( 'invited' === $event ) {
			/* translators: 1: organization name, 2: campaign title, 3: the host's name. */
			$mail( $org, __( 'An invitation to a joint campaign', 'packout-core' ), sprintf( __( '“%1$s” is invited to take part in the joint campaign “%2$s”, hosted by %3$s. A lead accepts or declines under Campaigns.', 'packout-core' ), $name, $campaign->post_title, $hosts ) );
		} elseif ( 'removed' === $event ) {
			/* translators: 1: organization name, 2: campaign title. */
			$mail( $org, __( 'A joint campaign', 'packout-core' ), sprintf( __( 'The host took “%1$s” out of the joint campaign “%2$s”. What the organization did in it stays on record.', 'packout-core' ), $name, $campaign->post_title ) );
		} elseif ( in_array( $event, array( 'accepted', 'declined', 'left' ), true ) ) {
			$lines = array(
				/* translators: 1: organization name, 2: campaign title. */
				'accepted' => __( '“%1$s” accepted and now takes part in the joint campaign “%2$s”.', 'packout-core' ),
				/* translators: 1: organization name, 2: campaign title. */
				'declined' => __( '“%1$s” declined the invitation to the joint campaign “%2$s”.', 'packout-core' ),
				/* translators: 1: organization name, 2: campaign title. */
				'left'     => __( '“%1$s” left the joint campaign “%2$s”. What it did in the campaign stays on record.', 'packout-core' ),
			);
			$mail( $host, __( 'A joint campaign', 'packout-core' ), sprintf( $lines[ $event ], $name, $campaign->post_title ) );
		} elseif ( 'closed' === $event ) {
			foreach ( self::parts( $id ) as $part => $row ) {
				if ( 'accepted' === $row['state'] ) {
					/* translators: 1: campaign title, 2: the host's name. */
					$mail( $part, __( 'A joint campaign', 'packout-core' ), sprintf( __( '%2$s closed the joint campaign “%1$s”. No new mission can be filed under it. What your organization did in it stays on record.', 'packout-core' ), $campaign->post_title, $hosts ) );
				}
			}
		}//end if
		/**
		 * Something changed in who takes part in a joint campaign.
		 *
		 * @param int    $id    Campaign ID.
		 * @param int    $org   The organization it is about, or 0 for all of them.
		 * @param string $event `invited`, `accepted`, `declined`, `left`, `removed`, or `closed`.
		 */
		do_action( 'packout_joint_changed', $id, $org, $event );
	}

	/**
	 * Whether an organization's new mission can be filed under a campaign that is not its own.
	 *
	 * @param int $id   Campaign ID here.
	 * @param int $team Organization ID here.
	 */
	public static function open_to( int $id, int $team ): bool {
		if ( $team <= 0 || ! Campaigns::get( $id ) || self::closed( $id ) ) {
			return false;
		}
		if ( App::is_hub() ) {
			return self::is_joint( $id ) && 'accepted' === self::state( $id, $team );
		}
		return self::is_copy( $id ) && 'accepted' === self::state( $id, $team );
	}

	/**
	 * The joint campaigns of other hosts that an organization stands in, as records here.
	 *
	 * On the hub these are the campaigns themselves. On a partner site they
	 * are its copies, so an invitation that is not yet accepted is not among
	 * them: see `invitations()`.
	 *
	 * @param int                $team   Organization ID here.
	 * @param array<int, string> $states The states to find.
	 * @return array<int, \WP_Post>
	 */
	public static function of_team( int $team, array $states = array( 'accepted' ) ): array {
		$found = array();
		foreach ( self::all() as $id ) {
			$post = Campaigns::get( $id );
			if ( $post && in_array( self::state( $id, $team ), $states, true ) ) {
				$found[] = $post;
			}
		}
		return $found;
	}

	/**
	 * The invitations an organization has not answered.
	 *
	 * @param int $team Organization ID here.
	 * @return array<int, array{id: int, title: string, host: string, window: string, url: string, description: string}> `id` is the hub's number.
	 */
	public static function invitations( int $team ): array {
		$found = array();
		if ( App::is_hub() ) {
			foreach ( self::of_team( $team, array( 'invited' ) ) as $post ) {
				if ( self::closed( $post->ID ) || self::unfit( $post->ID ) ) {
					continue;
				}
				$found[] = array(
					'id'          => $post->ID,
					'title'       => $post->post_title,
					'host'        => self::host( $post->ID ),
					'window'      => Schedule::label( $post->ID ),
					'url'         => (string) get_permalink( $post ),
					'description' => $post->post_content,
				);
			}
			return $found;
		}
		foreach ( (array) ( self::known()['invited'] ?? array() ) as $row ) {
			if ( (int) ( $row['team'] ?? 0 ) === $team ) {
				$found[] = array(
					'id'          => (int) $row['id'],
					'title'       => (string) $row['title'],
					'host'        => (string) $row['host'],
					'window'      => (string) $row['window'],
					'url'         => (string) $row['url'],
					'description' => (string) $row['description'],
				);
			}
		}
		return $found;
	}

	/**
	 * Who hosts a joint campaign, in words.
	 *
	 * @param int $id Campaign ID here.
	 */
	public static function host( int $id ): string {
		if ( self::is_copy( $id ) ) {
			return (string) get_post_meta( $id, self::HOST, true );
		}
		$host = self::named( Campaigns::team( $id ) );
		return '' !== $host['partner'] && $host['partner'] !== $host['name'] ? $host['name'] . ' (' . $host['partner'] . ')' : $host['name'];
	}

	/**
	 * The campaign's page on the hub.
	 *
	 * @param int $id Campaign ID here.
	 */
	public static function hub_url( int $id ): string {
		if ( App::is_hub() ) {
			$post = Campaigns::get( $id );
			return $post && 'publish' === $post->post_status ? (string) get_permalink( $post ) : '';
		}
		return esc_url_raw( (string) get_post_meta( $id, self::URL, true ), array( 'https', 'http' ) );
	}

	/**
	 * Who takes part in a campaign a person hosts, and who could be invited.
	 *
	 * @param int $id Campaign ID here: the campaign itself.
	 * @return array{state: string, known: bool, participants: array<int, array{id: int, name: string, partner: string, state: string}>, invitable: array<int, array{id: int, name: string, partner: string}>} `known` is false on a partner site until the hub has the campaign.
	 */
	public static function hosted( int $id ): array {
		$state = (string) get_post_meta( $id, self::FLAG, true );
		$view  = array(
			'state'        => in_array( $state, array( 'open', 'closed' ), true ) ? $state : '',
			'known'        => App::is_hub(),
			'participants' => array(),
			'invitable'    => array(),
		);
		if ( '' === $view['state'] ) {
			return $view;
		}
		if ( ! App::is_hub() ) {
			$known = self::known();
			$mine  = $known['hosted'][ (string) $id ] ?? null;
			if ( is_array( $mine ) ) {
				$view['known']        = true;
				$view['participants'] = array_values( (array) ( $mine['participants'] ?? array() ) );
				$taken                = array_column( array_filter( $view['participants'], static fn( array $row ): bool => in_array( $row['state'], array( 'invited', 'accepted' ), true ) ), 'id' );
				$view['invitable']    = array_values( array_filter( (array) ( $known['organizations'] ?? array() ), static fn( array $row ): bool => ! in_array( $row['id'], $taken, true ) ) );
			}
			return $view;
		}
		foreach ( self::parts( $id ) as $org => $row ) {
			$named                  = self::named( $org );
			$view['participants'][] = array(
				'id'      => $org,
				'name'    => $named['name'],
				'partner' => $named['partner'],
				'state'   => $row['state'],
			);
		}
		usort( $view['participants'], static fn( array $a, array $b ): int => strnatcasecmp( $a['name'], $b['name'] ) );
		$view['invitable'] = 'open' === $view['state'] ? self::directory( $id ) : array();
		return $view;
	}

	/**
	 * The organizations that could be invited to a campaign, by name.
	 *
	 * @param int $id Campaign ID on the hub.
	 * @return array<int, array{id: int, name: string, partner: string}>
	 */
	public static function directory( int $id ): array {
		$found = array();
		foreach ( Teams::all( true ) as $team ) {
			if ( count( $found ) >= 500 ) {
				break;
			}
			if ( null === self::uninvitable( $id, $team->ID ) ) {
				$named   = self::named( $team->ID );
				$found[] = array(
					'id'      => $team->ID,
					'name'    => $named['name'],
					'partner' => $named['partner'],
				);
			}
		}
		return $found;
	}

	/**
	 * The figures of a joint campaign, one line for each participant that had a part in it, by name.
	 *
	 * A hub organization's line is counted here from its missions. A partner
	 * site's line is what the site reported, for all of its organizations
	 * together, and says the day it is of.
	 *
	 * @param int  $id      Campaign ID on the hub.
	 * @param bool $visible Only the records counted publicly.
	 * @return array<int, array{name: string, kind: string, url: string, state: string, left: string, reported: string, totals: array<string, mixed>}> `kind` is `organization` or `partner`.
	 */
	public static function lines( int $id, bool $visible = true ): array {
		if ( ! App::is_hub() || ! self::is_joint( $id ) ) {
			return array();
		}
		$missions = array();
		foreach ( Campaigns::missions( $id ) as $mission ) {
			if ( ! Remote::is( $mission->ID ) ) {
				$missions[ Missions::team( $mission->ID ) ][] = $mission->ID;
			}
		}
		$host = Campaigns::team( $id );
		$rows = array(
			$host => array(
				'state' => 'host',
				'left'  => '',
			),
		);
		foreach ( self::parts( $id ) as $org => $row ) {
			if ( in_array( $row['state'], array( 'accepted', 'left' ), true ) ) {
				$rows[ $org ] = array(
					'state' => $row['state'],
					'left'  => $row['left'],
				);
			}
		}
		$lines = array();
		$sites = array();
		foreach ( $rows as $org => $row ) {
			$named = self::named( $org );
			if ( Remote::is( $org ) ) {
				// A partner site reports for all of its organizations together: one line for the site.
				$partner = Remote::partner( $org );
				if ( isset( $sites[ $partner ] ) ) {
					if ( 'left' !== $row['state'] ) {
						$lines[ $sites[ $partner ] ]['state'] = 'host' === $row['state'] ? 'host' : $lines[ $sites[ $partner ] ]['state'];
						$lines[ $sites[ $partner ] ]['left']  = '';
					}
					continue;
				}
				/**
				 * What a partner site reported for its part of a joint campaign.
				 *
				 * @param array<string, mixed>|null $totals  None.
				 * @param int                       $id      The campaign.
				 * @param int                       $partner The partner.
				 */
				$reported          = apply_filters( 'packout_joint_reported', null, $id, $partner );
				$owner             = Partners::get( $partner );
				$sites[ $partner ] = count( $lines );
				$lines[]           = array(
					'name'     => '' !== $named['partner'] ? $named['partner'] : $named['name'],
					'kind'     => 'partner',
					'url'      => $owner && Partners::shown( $partner ) ? (string) get_permalink( $owner ) : '',
					'state'    => $row['state'],
					'left'     => $row['left'],
					'reported' => is_array( $reported ) ? (string) ( $reported['reported'] ?? '' ) : '',
					'totals'   => is_array( $reported ) ? $reported : Remote::no_totals(),
				);
				continue;
			}//end if
			$lines[] = array(
				'name'     => $named['name'],
				'kind'     => 'organization',
				'url'      => $named['url'],
				'state'    => $row['state'],
				'left'     => $row['left'],
				'reported' => '',
				'totals'   => Impact::totals(
					array(
						'missions' => ! empty( $missions[ $org ] ) ? $missions[ $org ] : array( 0 ),
						'visible'  => $visible,
					)
				),
			);
		}//end foreach
		usort( $lines, static fn( array $a, array $b ): int => strnatcasecmp( $a['name'], $b['name'] ) );
		return $lines;
	}

	/**
	 * The lines of one kind, added up, in the shape of `Impact::totals()`.
	 *
	 * @param array<int, array<string, mixed>> $lines From lines().
	 * @param string                           $kind  `organization`, `partner`, or empty for all of them.
	 * @return array<string, mixed> With `lines`, the number added, and `reported`, the newest day a partner's line is of.
	 */
	public static function sum( array $lines, string $kind = '' ): array {
		$sum      = Remote::no_totals();
		$count    = 0;
		$reported = '';
		foreach ( $lines as $line ) {
			if ( '' !== $kind && $line['kind'] !== $kind ) {
				continue;
			}
			++$count;
			$reported = max( $reported, (string) $line['reported'] );
			foreach ( $sum as $group => $figures ) {
				foreach ( $figures as $name => $value ) {
					$sum[ $group ][ $name ] = $value + ( $line['totals'][ $group ][ $name ] ?? 0 );
				}
			}
		}
		return $sum + array(
			'lines'    => $count,
			'reported' => $reported,
		);
	}

	/**
	 * The whole of a joint campaign: the hub's own count and what each partner site reported, together.
	 *
	 * @param int  $id      Campaign ID on the hub.
	 * @param bool $visible Only the records counted publicly.
	 * @return array<string, mixed>
	 */
	public static function whole( int $id, bool $visible = true ): array {
		return array_intersect_key( self::sum( self::lines( $id, $visible ) ), Remote::no_totals() );
	}

	/**
	 * On a campaign's public page: who takes part, and what each has recorded.
	 *
	 * @param int $id Campaign ID here.
	 */
	public static function section( int $id ): string {
		if ( self::is_copy( $id ) ) {
			$url  = self::hub_url( $id );
			$html = '<section class="pos-place__section pos-joint"><h2>' . esc_html__( 'A joint campaign', 'packout-core' ) . '</h2>';
			/* translators: %s: the name of the organization that hosts a campaign. */
			$html .= '<p>' . esc_html( sprintf( __( 'Several organizations take part in this campaign. It is hosted by %s on packout.spot. This page shows what was planned and recorded here.', 'packout-core' ), self::host( $id ) ) ) . '</p>';
			if ( '' !== $url ) {
				$html .= '<p><a class="pos-ws__button" href="' . esc_url( $url ) . '" rel="noopener">' . esc_html__( 'See the whole campaign on packout.spot', 'packout-core' ) . '</a></p>';
			}
			return $html . '</section>';
		}
		if ( ! App::is_hub() || ! self::is_joint( $id ) ) {
			return '';
		}
		$lines = self::lines( $id );
		$html  = '<section class="pos-place__section pos-joint"><h2>' . esc_html__( 'Taking part', 'packout-core' ) . '</h2>';
		/* translators: %s: the name of the organization that hosts a campaign. */
		$html .= '<p>' . esc_html( sprintf( __( 'A joint campaign, hosted by %s. Each organization plans and records its own missions. They are listed by name.', 'packout-core' ), self::host( $id ) ) ) . '</p><ul class="pos-joint__lines">';
		foreach ( $lines as $line ) {
			$name  = '' !== $line['url'] ? '<a href="' . esc_url( $line['url'] ) . '">' . esc_html( $line['name'] ) . '</a>' : esc_html( $line['name'] );
			$notes = array();
			if ( 'partner' === $line['kind'] ) {
				$notes[] = '' !== $line['reported']
					/* translators: %s: a date. */
					? sprintf( __( 'A partner site. Its figures are its own report of %s.', 'packout-core' ), wp_date( get_option( 'date_format' ), (int) strtotime( $line['reported'] . ' 12:00:00' ) ) )
					: __( 'A partner site.', 'packout-core' );
			}
			if ( 'left' === $line['state'] && '' !== $line['left'] ) {
				/* translators: %s: a date. */
				$notes[] = sprintf( __( 'Took part until %s.', 'packout-core' ), wp_date( get_option( 'date_format' ), (int) strtotime( $line['left'] . ' 12:00:00' ) ) );
			}
			$html .= '<li><span class="pos-joint__name">' . $name . '</span>';
			if ( $notes ) {
				$html .= ' <span class="pos-team-page__facts">' . esc_html( implode( ' ', $notes ) ) . '</span>';
			}
			$html .= '<span class="pos-joint__figures">' . esc_html( (int) $line['totals']['records']['cleanups'] > 0 ? Campaigns::totals_line( $line['totals'] ) : __( 'Nothing recorded yet.', 'packout-core' ) ) . '</span></li>';
		}
		$html .= '</ul>';
		$sites = self::sum( $lines, 'partner' );
		if ( $sites['lines'] > 0 ) {
			$html .= '<p class="pos-team-page__facts">' . esc_html__( 'A partner site keeps its own records and verifies them with its own people. packout.spot shows what each one reports and does not check it.', 'packout-core' ) . '</p>';
		}
		return $html . '</section>';
	}

	/**
	 * What the public may know about the joint campaigns an organization takes part in, for its page.
	 *
	 * @param int $team Organization ID here.
	 * @return array<int, \WP_Post>
	 */
	public static function public_of( int $team ): array {
		return array_values( array_filter( self::of_team( $team, array( 'accepted', 'left' ) ), static fn( \WP_Post $post ): bool => 'publish' === $post->post_status && Post_Access::can_read( $post, 0 ) ) );
	}

	/*
	 * A partner site.
	 */

	/**
	 * What the hub last said, on a partner site.
	 *
	 * @return array{at: int, stamp: string, invited: array<int, array<string, mixed>>, hosted: array<string, array<string, mixed>>, organizations: array<int, array<string, mixed>>}
	 */
	public static function known(): array {
		$known = get_option( self::OPTION, array() );
		$known = is_array( $known ) ? $known : array();
		return array(
			'at'            => (int) ( $known['at'] ?? 0 ),
			'stamp'         => is_string( $known['stamp'] ?? null ) ? $known['stamp'] : '',
			'invited'       => is_array( $known['invited'] ?? null ) ? $known['invited'] : array(),
			'hosted'        => is_array( $known['hosted'] ?? null ) ? $known['hosted'] : array(),
			'organizations' => is_array( $known['organizations'] ?? null ) ? $known['organizations'] : array(),
		);
	}

	/**
	 * The copy of a hub campaign on this site, or 0.
	 *
	 * @param int $hub The hub's number for the campaign.
	 */
	public static function copy_of( int $hub ): int {
		if ( App::is_hub() || $hub <= 0 ) {
			return 0;
		}
		foreach ( self::all() as $id ) {
			if ( (int) get_post_meta( $id, self::HUB, true ) === $hub ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * Ask the hub which joint campaigns concern this site, and keep the answer.
	 *
	 * The hub's answer to "what changed" carries a stamp of what it would
	 * say about joint campaigns. The question is asked only when the stamp
	 * is not the one last learned, so a site that is in no joint campaign
	 * asks nothing, and a hub that knows no joint campaigns is not asked.
	 *
	 * @param mixed $changes The hub's answer to "what changed", or nothing to ask whatever it said.
	 * @return bool Whether the hub was asked and answered.
	 */
	public static function pull( $changes = null ): bool {
		if ( App::is_hub() || ! Connection::sending() ) {
			return false;
		}
		if ( is_array( $changes ) ) {
			$stamp = is_string( $changes['campaigns'] ?? null ) ? $changes['campaigns'] : '';
			if ( '' === $stamp || self::known()['stamp'] === $stamp ) {
				return false;
			}
		}
		$answer = Connection::send( 'GET', '/campaigns', null );
		if ( is_wp_error( $answer ) ) {
			return false;
		}
		self::learn( $answer );
		return true;
	}

	/**
	 * Keep what the hub said about the joint campaigns that concern this site.
	 *
	 * A campaign one of the site's organizations takes part in, or took
	 * part in, is kept as a campaign here that nobody can change. An
	 * invitation is kept as a line to answer. A campaign the site hosts is
	 * the site's own record, and only its participants are kept.
	 *
	 * @param array<string, mixed> $answer The hub's answer: `campaigns`, `stamp`, and `organizations` when the site hosts one.
	 */
	public static function learn( array $answer ): void {
		if ( App::is_hub() || ! is_array( $answer['campaigns'] ?? null ) ) {
			return;
		}
		$line    = static fn( $value, int $limit ): string => mb_substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, $limit );
		$states  = array( 'invited', 'accepted', 'declined', 'left' );
		$invited = array();
		$hosted  = array();
		$named   = array();
		foreach ( array_slice( $answer['campaigns'], 0, 200 ) as $campaign ) {
			$hub = is_array( $campaign ) ? (int) ( $campaign['id'] ?? 0 ) : 0;
			if ( $hub <= 0 ) {
				continue;
			}
			$url = esc_url_raw( (string) ( $campaign['url'] ?? '' ), array( 'https', 'http' ) );
			// The campaign's page is on the hub, and nowhere else.
			if ( '' !== $url && 0 !== strpos( $url, trailingslashit( Connection::hub() ) ) ) {
				$url = '';
			}
			if ( ! empty( $campaign['hosted'] ) ) {
				$local = (int) ( $campaign['local'] ?? 0 );
				if ( $local > 0 && Campaigns::get( $local ) && ! self::is_copy( $local ) ) {
					$rows = array();
					foreach ( array_slice( (array) ( $campaign['participants'] ?? array() ), 0, 500 ) as $row ) {
						if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) > 0 && in_array( $row['state'] ?? '', $states, true ) ) {
							$rows[] = array(
								'id'      => (int) $row['id'],
								'name'    => $line( $row['name'] ?? '', 200 ),
								'partner' => $line( $row['partner'] ?? '', 200 ),
								'state'   => (string) $row['state'],
							);
						}
					}
					$hosted[ (string) $local ] = array(
						'id'           => $hub,
						'url'          => $url,
						'participants' => $rows,
					);
				}
				continue;
			}//end if
			$mine = array();
			foreach ( (array) ( $campaign['states'] ?? array() ) as $team => $state ) {
				if ( Teams::get( (int) $team ) && in_array( $state, $states, true ) ) {
					$mine[ (string) (int) $team ] = (string) $state;
				}
			}
			$about = array(
				'id'          => $hub,
				'title'       => $line( $campaign['title'] ?? '', 200 ),
				'description' => mb_substr( sanitize_textarea_field( is_scalar( $campaign['description'] ?? null ) ? (string) $campaign['description'] : '' ), 0, 5000 ),
				'goal'        => $line( $campaign['goal'] ?? '', 300 ),
				'host'        => $line( $campaign['host'] ?? '', 300 ),
				'url'         => $url,
				'joint'       => 'closed' === ( $campaign['joint'] ?? '' ) ? 'closed' : 'open',
				'window'      => array(
					'start'      => Schema::day( $campaign['start'] ?? '' ),
					'end'        => Schema::day( $campaign['end'] ?? '' ),
					'start_time' => is_string( $campaign['start_time'] ?? null ) && 1 === preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $campaign['start_time'] ) ? $campaign['start_time'] : '',
					'end_time'   => is_string( $campaign['end_time'] ?? null ) && 1 === preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $campaign['end_time'] ) ? $campaign['end_time'] : '',
					'timezone'   => is_string( $campaign['timezone'] ?? null ) && \PackOut\Core\Schedule::is_zone( $campaign['timezone'] ) ? $campaign['timezone'] : '',
				),
			);
			if ( '' === $about['title'] ) {
				continue;
			}
			if ( array_intersect( $mine, array( 'accepted', 'left' ) ) ) {
				$copy = self::keep( $about, $mine );
				if ( $copy > 0 ) {
					$named[] = $hub;
				}
			}
			foreach ( $mine as $team => $state ) {
				if ( 'invited' === $state && 'open' === $about['joint'] ) {
					$invited[] = array(
						'id'          => $hub,
						'team'        => (int) $team,
						'title'       => $about['title'],
						'host'        => $about['host'],
						'window'      => self::span( $about['window'] ),
						'url'         => $url,
						'description' => $about['description'],
					);
				}
			}
		}//end foreach

		// A copy the hub no longer names: the campaign is gone there, or the site was taken out of it. What was done here stays, as past.
		self::flush();
		foreach ( self::all() as $copy ) {
			if ( in_array( (int) get_post_meta( $copy, self::HUB, true ), $named, true ) ) {
				continue;
			}
			if ( ! Campaigns::missions( $copy ) ) {
				wp_delete_post( $copy, true );
				continue;
			}
			$gone = array();
			foreach ( Schema::stored_list( $copy, self::STATES ) as $team => $state ) {
				$gone[ (string) $team ] = 'left';
			}
			Schema::write_list( $copy, self::STATES, $gone );
			update_post_meta( $copy, self::FLAG, 'closed' );
		}
		self::flush();

		$directory = array();
		foreach ( array_slice( (array) ( $answer['organizations'] ?? array() ), 0, 500 ) as $row ) {
			if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) > 0 ) {
				$directory[] = array(
					'id'      => (int) $row['id'],
					'name'    => $line( $row['name'] ?? '', 200 ),
					'partner' => $line( $row['partner'] ?? '', 200 ),
				);
			}
		}
		update_option(
			self::OPTION,
			array(
				'at'            => time(),
				'stamp'         => is_string( $answer['stamp'] ?? null ) ? substr( sanitize_key( $answer['stamp'] ), 0, 64 ) : '',
				'invited'       => $invited,
				'hosted'        => $hosted,
				'organizations' => $directory,
			),
			false
		);
	}

	/**
	 * A window in words, for an invitation that has no record here yet.
	 *
	 * @param array<string, string> $window start, end.
	 */
	private static function span( array $window ): string {
		$day = static fn( string $ymd ): string => '' !== $ymd ? wp_date( get_option( 'date_format' ), (int) strtotime( $ymd . ' 12:00:00' ) ) : '';
		if ( '' === $window['start'] ) {
			return '';
		}
		return '' !== $window['end'] && $window['end'] !== $window['start'] ? $day( $window['start'] ) . ' – ' . $day( $window['end'] ) : $day( $window['start'] );
	}

	/**
	 * Make or bring up to date this site's copy of a hub campaign.
	 *
	 * @param array<string, mixed>  $about  What the hub said, cleaned.
	 * @param array<string, string> $states How each organization of the site stands.
	 * @return int The copy, or 0.
	 */
	private static function keep( array $about, array $states ): int {
		$copy = self::copy_of( (int) $about['id'] );
		$post = array(
			'post_type'    => Schema::CAMPAIGN,
			'post_status'  => 'publish',
			'post_title'   => wp_slash( $about['title'] ),
			'post_content' => wp_slash( $about['description'] ),
		);
		if ( $copy > 0 ) {
			$now = get_post( $copy );
			if ( $now instanceof \WP_Post && ( $now->post_title !== $about['title'] || $now->post_content !== $about['description'] || 'publish' !== $now->post_status ) ) {
				wp_update_post( $post + array( 'ID' => $copy ) );
			}
		} else {
			// The hub's number goes on with the record, so nothing that hears of the new campaign takes it for one of the site's own.
			$made = wp_insert_post(
				$post + array(
					'post_author' => 0,
					'meta_input'  => array( self::HUB => (int) $about['id'] ),
				),
				true
			);
			if ( is_wp_error( $made ) || (int) $made <= 0 ) {
				return 0;
			}
			$copy = (int) $made;
			self::flush();
		}
		$set = static function ( string $key, string $value ) use ( $copy ): void {
			if ( (string) get_post_meta( $copy, $key, true ) === $value ) {
				return;
			}
			if ( '' === $value ) {
				delete_post_meta( $copy, $key );
			} else {
				update_post_meta( $copy, $key, wp_slash( $value ) );
			}
		};
		$set( self::HOST, (string) $about['host'] );
		$set( self::URL, (string) $about['url'] );
		$set( self::FLAG, (string) $about['joint'] );
		$set( '_pos_goal', (string) $about['goal'] );
		if ( Schedule::fields( $copy ) !== $about['window'] ) {
			Schedule::write( $copy, $about['window'] );
		}
		if ( Schema::stored_list( $copy, self::STATES ) !== $states ) {
			Schema::write_list( $copy, self::STATES, $states );
		}
		return $copy;
	}

	/**
	 * Say something to the hub about a joint campaign, and keep what it answers.
	 *
	 * @param int                   $hub  The hub's number for the campaign.
	 * @param string                $act  `accept`, `leave`, `invite`, or `remove`.
	 * @param array<string, string> $body `organization`, or `participant`.
	 * @return true|\WP_Error
	 */
	private static function tell( int $hub, string $act, array $body ) {
		if ( ! Connection::sending() ) {
			return new \WP_Error( 'packout_not_connected', __( 'This site is not sending to packout.spot now. See the Connection screen.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( $hub <= 0 ) {
			return new \WP_Error( 'packout_joint_unknown', __( 'packout.spot does not have this campaign yet. It takes it up within a few minutes of it being made joint.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$answer = Connection::send( 'POST', '/campaigns/' . $hub . '/' . $act, $body + Connection::versions() );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		self::learn( $answer );
		return true;
	}

	/*
	 * What a person does, on either site.
	 */

	/**
	 * Invite an organization to a campaign a person hosts.
	 *
	 * @param int $id      Campaign ID here.
	 * @param int $org     The hub's number for the organization.
	 * @param int $user_id The person: the campaign's host.
	 * @return true|\WP_Error
	 */
	public static function invite( int $id, int $org, int $user_id ) {
		if ( ! self::is_joint( $id ) || ! self::can_host( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'A lead of the organization that hosts the campaign invites others to it.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( self::closed( $id ) ) {
			return new \WP_Error( 'packout_joint_closed', __( 'That campaign is not taking participants.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( App::is_hub() ) {
			return self::host_invite( $id, $org );
		}
		return self::tell( (int) ( self::known()['hosted'][ (string) $id ]['id'] ?? 0 ), 'invite', array( 'participant' => (string) $org ) );
	}

	/**
	 * Take an organization out of a campaign a person hosts.
	 *
	 * @param int $id      Campaign ID here.
	 * @param int $org     The hub's number for the organization.
	 * @param int $user_id The person: the campaign's host.
	 * @return true|\WP_Error
	 */
	public static function remove( int $id, int $org, int $user_id ) {
		if ( ! self::is_joint( $id ) || ! self::can_host( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'A lead of the organization that hosts the campaign decides who takes part.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( App::is_hub() ) {
			return self::host_remove( $id, $org );
		}
		return self::tell( (int) ( self::known()['hosted'][ (string) $id ]['id'] ?? 0 ), 'remove', array( 'participant' => (string) $org ) );
	}

	/**
	 * Accept or decline an invitation, or leave a campaign, for an organization.
	 *
	 * @param int    $hub     The hub's number for the campaign.
	 * @param int    $team    Organization ID here.
	 * @param string $to      `accepted`, `declined`, or `left`.
	 * @param int    $user_id The person: one who answers for the organization.
	 * @return true|\WP_Error
	 */
	public static function answer( int $hub, int $team, string $to, int $user_id ) {
		if ( ! in_array( $to, array( 'accepted', 'declined', 'left' ), true ) || ! self::can_answer( $team, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'A lead of the organization answers an invitation to a joint campaign.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( App::is_hub() ) {
			$done = self::decide( $hub, $team, $to );
			return is_wp_error( $done ) ? $done : true;
		}
		if ( ! Listings::listable( 'organization', (string) $team ) ) {
			return new \WP_Error( 'packout_not_listed', __( 'packout.spot lists an organization once it is approved and public here. This one is not yet.', 'packout-core' ), array( 'status' => 409 ) );
		}
		return self::tell( $hub, 'accepted' === $to ? 'accept' : 'leave', array( 'organization' => (string) $team ) );
	}

	/**
	 * Whether a workspace form is one of this class's.
	 *
	 * @param string $act The form's action.
	 */
	public static function handles( string $act ): bool {
		return in_array( $act, self::ACTIONS, true );
	}

	/**
	 * Do what a workspace form asks.
	 *
	 * @param string $act  The form's action.
	 * @param int    $user The person.
	 * @return array{0: true|\WP_Error, 1: string, 2: string} The result, what to say, and where to go.
	 */
	public static function act( string $act, int $user ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Workspace::handle() checked the nonce before calling this.
		$team = isset( $_POST['team'] ) ? (int) $_POST['team'] : 0;
		$item = isset( $_POST['item'] ) ? (int) $_POST['item'] : 0;
		$org  = isset( $_POST['org'] ) ? (int) $_POST['org'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$pane = add_query_arg( 'part', 'joint', Workspace::url( $team, 'campaign', (string) $item ) );
		$list = Workspace::url( $team, 'campaigns' );
		switch ( $act ) {
			case 'joint_open':
				$own = Campaigns::team( $item ) === $team;
				return array( $own ? self::open( $item, $user ) : new \WP_Error( 'packout_forbidden', __( 'You are not allowed to do that.', 'packout-core' ) ), __( 'This is now a joint campaign. Invite the organizations that will take part.', 'packout-core' ), $pane );
			case 'joint_close':
				return array( self::close( $item, $user ), __( 'The joint campaign is closed. It takes no more participants, and no participant can file another mission under it. What was done stays.', 'packout-core' ), $pane );
			case 'joint_invite':
				return array( self::invite( $item, $org, $user ), __( 'Invited. A lead of the organization accepts or declines.', 'packout-core' ), $pane );
			case 'joint_remove':
				return array( self::remove( $item, $org, $user ), __( 'Taken out of the campaign. What the organization did in it stays on record.', 'packout-core' ), $pane );
			case 'joint_accept':
				return array( self::answer( $item, $team, 'accepted', $user ), __( 'Your organization takes part. Its missions can now be filed under the campaign.', 'packout-core' ), $list );
			case 'joint_decline':
				return array( self::answer( $item, $team, 'declined', $user ), __( 'The invitation was declined.', 'packout-core' ), $list );
			case 'joint_leave':
				return array( self::answer( $item, $team, 'left', $user ), __( 'Your organization left the campaign. Its missions keep their place in it, and no new one can be filed.', 'packout-core' ), $list );
		}
		return array( new \WP_Error( 'packout_unknown', __( 'That could not be done.', 'packout-core' ) ), '', '' );
	}

	/**
	 * Above an organization's campaigns: the invitations it has not answered.
	 *
	 * @param \WP_Post $team The organization.
	 * @param int      $user The person looking.
	 */
	public static function invitations_html( \WP_Post $team, int $user ): string {
		$invitations = self::invitations( $team->ID );
		if ( ! $invitations ) {
			return '';
		}
		$answers = self::can_answer( $team->ID, $user );
		$html    = '<div class="pos-ws__group pos-joint__invitations"><h3>' . esc_html__( 'Invitations to joint campaigns', 'packout-core' ) . '</h3><ul class="pos-ws__rows">';
		foreach ( $invitations as $invitation ) {
			$title = '' !== $invitation['url'] ? '<a href="' . esc_url( $invitation['url'] ) . '" rel="noopener">' . esc_html( $invitation['title'] ) . '</a>' : esc_html( $invitation['title'] );
			/* translators: %s: the name of the organization that hosts a campaign. */
			$facts = array_filter( array( sprintf( __( 'Hosted by %s', 'packout-core' ), $invitation['host'] ), $invitation['window'] ) );
			$html .= '<li><span>' . $title . '</span><span class="pos-ws__muted">' . esc_html( implode( ' · ', $facts ) ) . '</span>';
			if ( $answers ) {
				$hidden = array( 'item' => (string) $invitation['id'] );
				$html  .= '<span class="pos-ws__actions">' . Workspace::act( 'joint_accept', $team->ID, __( 'Accept', 'packout-core' ), $hidden, 'pos-ws__button--primary', '', sprintf( /* translators: %s: campaign title. */ __( 'Accept the invitation to %s', 'packout-core' ), $invitation['title'] ) );
				$html  .= Workspace::act( 'joint_decline', $team->ID, __( 'Decline', 'packout-core' ), $hidden, '', '', sprintf( /* translators: %s: campaign title. */ __( 'Decline the invitation to %s', 'packout-core' ), $invitation['title'] ) ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		if ( ! $answers ) {
			$html .= '<p class="pos-ws__muted">' . esc_html__( 'A lead of the organization accepts or declines.', 'packout-core' ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * A joint campaign of another host that an organization takes part in, for the drawer.
	 *
	 * @param \WP_Post $campaign The campaign here: the hub's record, or a partner site's copy.
	 * @param \WP_Post $team     The organization.
	 * @param int      $user     The person looking.
	 * @param bool     $plans    Whether the person may plan missions for the organization.
	 */
	public static function taking_pane( \WP_Post $campaign, \WP_Post $team, int $user, bool $plans ): string {
		$id      = $campaign->ID;
		$state   = self::state( $id, $team->ID );
		$url     = self::hub_url( $id );
		$prefix  = 'pos-campaign-' . $id . '-';
		$details = '<p class="pos-ws__badges">' . Admin::badge( __( 'Joint campaign', 'packout-core' ), 'informational' ) . ( 'left' === $state ? Admin::badge( __( 'Left', 'packout-core' ), 'none' ) : ( self::closed( $id ) ? Admin::badge( __( 'Closed', 'packout-core' ), 'none' ) : '' ) ) . '</p>';
		/* translators: %s: the name of the organization that hosts a campaign. */
		$details .= '<p>' . esc_html( sprintf( __( 'Hosted by %s. The host changes the campaign; your organization plans and records its own missions in it.', 'packout-core' ), self::host( $id ) ) ) . '</p>';
		if ( '' !== trim( $campaign->post_content ) ) {
			$details .= wpautop( esc_html( $campaign->post_content ) );
		}
		$details .= Workspace_Views::facts(
			array(
				__( 'Goal', 'packout-core' ) => Campaigns::field( $id, 'goal' ),
				__( 'When', 'packout-core' ) => Schedule::label( $id ),
			)
		);
		if ( '' !== $url ) {
			$details .= '<p class="pos-ws__actions"><a class="pos-ws__button" href="' . esc_url( $url ) . '" rel="noopener">' . esc_html( App::is_hub() ? __( 'Open the public page', 'packout-core' ) : __( 'See the whole campaign on packout.spot', 'packout-core' ) ) . '</a></p>';
		}

		$statuses = Missions::statuses();
		$missions = '';
		foreach ( Campaigns::missions( $id ) as $mission ) {
			if ( Missions::team( $mission->ID ) !== $team->ID || ! Missions::can_work( $mission->ID, $user ) ) {
				continue;
			}
			$facts     = array_filter( array( $statuses[ Missions::status( $mission->ID ) ] ?? '', Missions::window_label( $mission->ID ) ) );
			$missions .= '<li><span><a href="' . esc_url( Workspace::url( $team->ID, 'mission', (string) $mission->ID ) ) . '">' . esc_html( $mission->post_title ) . '</a></span><span class="pos-ws__muted">' . esc_html( implode( ' · ', $facts ) ) . '</span></li>';
		}
		$missions = '' !== $missions ? '<ul class="pos-ws__rows">' . $missions . '</ul>' : '<p class="pos-ws__empty">' . esc_html__( 'Your organization has no mission in this campaign yet.', 'packout-core' ) . '</p>';
		if ( $plans && self::open_to( $id, $team->ID ) ) {
			$missions .= '<p class="pos-ws__actions"><a class="pos-ws__button" href="' . esc_url( add_query_arg( 'campaign', $id, Workspace::url( $team->ID, 'mission', 'new' ) ) ) . '">' . esc_html__( 'Plan a mission in this campaign', 'packout-core' ) . '</a></p>';
		} elseif ( 'left' === $state || self::closed( $id ) ) {
			$missions .= '<p class="pos-ws__muted">' . esc_html__( 'No new mission can be filed under this campaign. The ones above keep their place in it.', 'packout-core' ) . '</p>';
		}

		$sections = array(
			array(
				'id'    => $prefix . 'details',
				'label' => __( 'Details', 'packout-core' ),
				'html'  => $details,
			),
			array(
				'id'    => $prefix . 'missions',
				'label' => __( 'Your missions', 'packout-core' ),
				'html'  => $missions,
			),
		);
		if ( 'accepted' === $state && self::can_answer( $team->ID, $user ) ) {
			$leave      = '<p>' . esc_html__( 'Your organization stops taking part. Its missions keep their place in the campaign and what they recorded stays counted. No new mission can be filed under it.', 'packout-core' ) . '</p>';
			$leave     .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'joint_leave', $team->ID, __( 'Leave the campaign', 'packout-core' ), array( 'item' => (string) self::number( $id ) ), 'pos-ws__button--danger', __( 'Leave this joint campaign?', 'packout-core' ), '', __( 'The missions already in it keep their place. No new one can be filed.', 'packout-core' ) ) . '</div>';
			$sections[] = array(
				'id'    => $prefix . 'joint',
				'label' => __( 'Leave', 'packout-core' ),
				'html'  => $leave,
			);
		}
		return Workspace::sections( $campaign->post_title, $sections, 'top', '' );
	}

	/**
	 * In the drawer of a campaign a person hosts: make it joint, invite, see who takes part, close it.
	 *
	 * @param \WP_Post $campaign The campaign.
	 * @param int      $user     The person looking.
	 * @return string Empty for a person who does not host it.
	 */
	public static function host_pane( \WP_Post $campaign, int $user ): string {
		$id   = $campaign->ID;
		$team = Campaigns::team( $id );
		if ( ! self::can_host( $id, $user ) || Remote::is( $id ) ) {
			return '';
		}
		$view = self::hosted( $id );
		if ( '' === $view['state'] ) {
			$html  = '<p>' . esc_html__( 'A joint campaign is one that other organizations take part in. Each plans and records its own missions under it, and the campaign shows what all of them did, by organization.', 'packout-core' ) . '</p>';
			$html .= App::is_hub()
				? '<p>' . esc_html__( 'You invite organizations that packout.spot lists, and each one\'s lead accepts or declines. The campaign has to be shared with the public.', 'packout-core' ) . '</p>'
				: '<p>' . esc_html__( 'The campaign is then listed on packout.spot as a joint campaign, and the organizations packout.spot lists can be invited to it. Who takes part is kept there. The campaign has to be shared with the public and this site connected.', 'packout-core' ) . '</p>';
			return $html . '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'joint_open', $team, __( 'Make this a joint campaign', 'packout-core' ), array( 'item' => (string) $id ), 'pos-ws__button--primary' ) . '</div>';
		}
		$words = array(
			'invited'  => __( 'Invited', 'packout-core' ),
			'accepted' => __( 'Taking part', 'packout-core' ),
			'declined' => __( 'Declined', 'packout-core' ),
			'left'     => __( 'Left', 'packout-core' ),
		);
		$html  = '<p class="pos-ws__badges">' . Admin::badge( __( 'Joint campaign', 'packout-core' ), 'informational' ) . ( 'closed' === $view['state'] ? Admin::badge( __( 'Closed', 'packout-core' ), 'none' ) : '' ) . '</p>';
		if ( ! $view['known'] ) {
			return $html . '<p>' . esc_html__( 'packout.spot does not have this campaign yet. It takes it up within a few minutes, and the organizations to invite appear here then.', 'packout-core' ) . '</p>';
		}
		if ( ! $view['participants'] ) {
			$html .= '<p class="pos-ws__empty">' . esc_html__( 'Nobody is invited yet.', 'packout-core' ) . '</p>';
		} else {
			$html .= '<ul class="pos-ws__rows">';
			foreach ( $view['participants'] as $row ) {
				$name  = '' !== $row['partner'] && $row['partner'] !== $row['name'] ? $row['name'] . ' (' . $row['partner'] . ')' : $row['name'];
				$html .= '<li><span>' . esc_html( $name ) . '</span><span class="pos-ws__muted">' . esc_html( $words[ $row['state'] ] ?? '' ) . '</span>';
				if ( in_array( $row['state'], array( 'invited', 'accepted' ), true ) ) {
					$html .= '<span class="pos-ws__actions">' . Workspace::act(
						'joint_remove',
						$team,
						'invited' === $row['state'] ? __( 'Take back', 'packout-core' ) : __( 'Remove', 'packout-core' ),
						array(
							'item' => (string) $id,
							'org'  => (string) $row['id'],
						),
						'pos-ws__button--danger',
						/* translators: %s: organization name. */
						sprintf( __( 'Take %s out of the campaign?', 'packout-core' ), $row['name'] ),
						/* translators: %s: organization name. */
						sprintf( __( 'Take %s out of the campaign', 'packout-core' ), $row['name'] ),
						__( 'What the organization did in the campaign stays on record.', 'packout-core' )
					) . '</span>';
				}
				$html .= '</li>';
			}//end foreach
			$html .= '</ul>';
		}//end if
		if ( 'open' === $view['state'] ) {
			if ( $view['invitable'] ) {
				$options = array( '0' => __( 'Choose an organization', 'packout-core' ) );
				foreach ( $view['invitable'] as $row ) {
					$options[ (string) $row['id'] ] = '' !== $row['partner'] && $row['partner'] !== $row['name'] ? $row['name'] . ' (' . $row['partner'] . ')' : $row['name'];
				}
				$html .= '<h4>' . esc_html__( 'Invite an organization', 'packout-core' ) . '</h4>' . Workspace::open( 'joint_invite', $team, array( 'item' => (string) $id ) );
				$html .= Workspace::choose( 'org', __( 'Organization', 'packout-core' ), $options, '0', __( 'The organizations packout.spot lists. A lead of the one you invite accepts or declines.', 'packout-core' ) );
				$html .= Workspace::submit( __( 'Invite', 'packout-core' ) ) . '</form>';
			} else {
				$html .= '<p class="pos-ws__muted">' . esc_html__( 'There is no other organization to invite now.', 'packout-core' ) . '</p>';
			}
			$html .= '<h4>' . esc_html__( 'Close the joint campaign', 'packout-core' ) . '</h4><p>' . esc_html__( 'It takes no more participants, and no participant can file another mission under it. What was done stays, and the campaign keeps its page.', 'packout-core' ) . '</p>';
			$html .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'joint_close', $team, __( 'Close the joint campaign', 'packout-core' ), array( 'item' => (string) $id ), 'pos-ws__button--danger', __( 'Close this joint campaign?', 'packout-core' ), '', __( 'It takes no more participants, and no participant can file another mission under it. This cannot be undone.', 'packout-core' ) ) . '</div>';
		}
		return $html;
	}
}
