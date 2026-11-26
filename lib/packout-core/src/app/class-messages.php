<?php
/**
 * Messages from the people who lead a team to the people they lead.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A team's lead and co-leads write to the whole team, a sub-team, or the
 * people of a mission. A sub-team's lead and co-leads write to their
 * sub-team and to the people of a mission assigned to it.
 *
 * Each person gets their own email, headed with the team's logo, and a link
 * that stops that team's messages for them. The message is also kept with the
 * team, where the people it was addressed to can read it.
 */
class Messages {

	/**
	 * Messages kept for each team, and how many a person may send in a day.
	 */
	private const KEPT    = 100;
	private const PER_DAY = 10;

	/**
	 * Longest subject and body, in characters.
	 */
	public const SUBJECT = 120;
	public const BODY    = 5000;

	/**
	 * Emails sent in one go. The rest follow a minute apart.
	 */
	private const BATCH = 50;

	private const HOOK = 'packout_send_messages';

	/**
	 * Hook the background sender.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'deliver' ), 10, 2 );
	}

	/**
	 * The messages of a team, newest first.
	 *
	 * They are kept apart from the team's other fields, which are read on every page that shows the team.
	 *
	 * @param int $team Team ID.
	 * @return array<int, array{id: int, at: string, by: int, to: string, label: string, subject: string, body: string, reply: bool, people: array<int, int>, queue: array<int, int>, sent: int, skipped: int}>
	 */
	public static function all( int $team ): array {
		$stored = get_option( 'packout_messages_' . $team, array() );
		$rows   = array();
		foreach ( is_array( $stored ) ? $stored : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$rows[] = array(
				'id'      => (int) $row['id'],
				'at'      => (string) ( $row['at'] ?? '' ),
				'by'      => (int) ( $row['by'] ?? 0 ),
				'to'      => (string) ( $row['to'] ?? 'team' ),
				'label'   => (string) ( $row['label'] ?? '' ),
				'subject' => (string) ( $row['subject'] ?? '' ),
				'body'    => (string) ( $row['body'] ?? '' ),
				'reply'   => ! empty( $row['reply'] ),
				'people'  => array_values( array_map( 'intval', (array) ( $row['people'] ?? array() ) ) ),
				'queue'   => array_values( array_map( 'intval', (array) ( $row['queue'] ?? array() ) ) ),
				'sent'    => (int) ( $row['sent'] ?? 0 ),
				'skipped' => (int) ( $row['skipped'] ?? 0 ),
			);
		}
		return $rows;
	}

	/**
	 * Store a team's messages.
	 *
	 * @param int                              $team Team ID.
	 * @param array<int, array<string, mixed>> $rows Messages, newest first.
	 */
	private static function put( int $team, array $rows ): void {
		update_option( 'packout_messages_' . $team, array_slice( array_values( $rows ), 0, self::KEPT ), false );
	}

	/**
	 * One message, or null.
	 *
	 * @param int $team Team ID.
	 * @param int $id   Message number.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $team, int $id ): ?array {
		foreach ( self::all( $team ) as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The missions of a team a message can be about: every one that was not called off, newest first.
	 *
	 * @param int $team Team ID.
	 * @return array<int, \WP_Post>
	 */
	private static function missions( int $team ): array {
		$found = get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 30,
				'orderby'     => 'ID',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Missions of one team.
				'meta_key'    => '_pos_team',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Missions of one team.
				'meta_value'  => $team,
			)
		);
		return array_values( array_filter( $found, static fn( $mission ) => 'called_off' !== Missions::status( $mission->ID ) ) );
	}

	/**
	 * The people a message to a group reaches.
	 *
	 * @param int    $team Team ID.
	 * @param string $to   team, sub-<number>, or mission-<ID>.
	 * @return array<int, int> User IDs.
	 */
	public static function recipients( int $team, string $to ): array {
		$people = array();
		$parts  = explode( '-', $to, 2 );
		$number = (int) ( $parts[1] ?? 0 );

		if ( Partners::get( $team ) ) {
			// A partner writes to everyone in its organizations, to their leads, or to one organization.
			if ( 'all' === $to ) {
				$people = Partners::people( $team );
			} elseif ( 'leads' === $to ) {
				$people = Partners::leads( $team );
				foreach ( Partners::organization_ids( $team ) as $organization ) {
					$people = array_merge( $people, Teams::leads( $organization ) );
				}
			} elseif ( 'org' === $parts[0] && in_array( $number, Partners::organization_ids( $team ), true ) ) {
				$people = Teams::people( $number );
			}
			return array_values( array_unique( array_intersect( array_filter( array_map( 'intval', $people ) ), Partners::people( $team ) ) ) );
		}

		if ( 'team' === $to ) {
			$people = Teams::people( $team );
		} elseif ( 'sub' === $parts[0] ) {
			$subteam = Subteams::get( $team, $number );
			$people  = $subteam ? array_merge( array( $subteam['lead'] ), $subteam['members'] ) : array();
		} elseif ( 'mission' === $parts[0] && Missions::get( $number ) && Missions::team( $number ) === $team ) {
			// The people a mission is assigned to: the whole team when it names no sub-team, otherwise those sub-teams and the team's leads.
			$assigned = Missions::subteams( $number );
			if ( ! $assigned ) {
				$people = Teams::people( $team );
			} else {
				$people = Teams::leads( $team );
				foreach ( $assigned as $sub ) {
					$subteam = Subteams::get( $team, $sub );
					if ( $subteam ) {
						$people = array_merge( $people, array( $subteam['lead'] ), $subteam['members'] );
					}
				}
			}
		}
		$on_team = Teams::people( $team );
		return array_values( array_unique( array_intersect( array_filter( array_map( 'intval', $people ) ), $on_team ) ) );
	}

	/**
	 * Whether a person may write to a group.
	 *
	 * @param int    $team Team ID.
	 * @param string $to   team, sub-<number>, or mission-<ID>.
	 * @param int    $user User ID.
	 */
	public static function can_send( int $team, string $to, int $user ): bool {
		if ( Partners::get( $team ) ) {
			return $user > 0 && Partners::allows( $team, 'message' ) && Partners::can_run( $team, $user ) && '' !== self::label( $team, $to );
		}
		if ( $user <= 0 || ! Teams::get( $team ) || ! Teams::active( $team ) ) {
			return false;
		}
		if ( Teams::can_run( $team, $user ) ) {
			return 'team' === $to || '' !== self::label( $team, $to );
		}
		$parts  = explode( '-', $to, 2 );
		$number = (int) ( $parts[1] ?? 0 );
		if ( 'sub' === $parts[0] ) {
			return in_array( $number, Subteams::led_by( $team, $user ), true );
		}
		if ( 'mission' === $parts[0] && Missions::get( $number ) && Missions::team( $number ) === $team ) {
			// The lead or a co-lead of a sub-team the mission is assigned to.
			return array() !== array_intersect( Missions::subteams( $number ), Subteams::led_by( $team, $user ) );
		}
		return false;
	}

	/**
	 * The name of a group, or an empty string when there is no such group.
	 *
	 * @param int    $team Team ID.
	 * @param string $to   team, sub-<number>, or mission-<ID>.
	 */
	public static function label( int $team, string $to ): string {
		$parts  = explode( '-', $to, 2 );
		$number = (int) ( $parts[1] ?? 0 );
		if ( Partners::get( $team ) ) {
			if ( 'all' === $to ) {
				return __( 'Everyone in its organizations', 'packout-core' );
			}
			if ( 'leads' === $to ) {
				return __( 'Leads and co-leads only', 'packout-core' );
			}
			$organization = 'org' === $parts[0] && in_array( $number, Partners::organization_ids( $team ), true ) ? Teams::get( $number ) : null;
			/* translators: %s: organization name. */
			return $organization ? sprintf( __( 'Organization: %s', 'packout-core' ), $organization->post_title ) : '';
		}
		if ( 'team' === $to ) {
			return __( 'The whole organization', 'packout-core' );
		}
		if ( 'sub' === $parts[0] ) {
			$subteam = Subteams::get( $team, $number );
			/* translators: %s: team name. */
			return $subteam ? sprintf( __( 'Team: %s', 'packout-core' ), $subteam['name'] ) : '';
		}
		if ( 'mission' === $parts[0] ) {
			$mission = Missions::get( $number );
			/* translators: %s: mission title. */
			return $mission && Missions::team( $number ) === $team && 'called_off' !== Missions::status( $number ) ? sprintf( __( 'People of the mission: %s', 'packout-core' ), $mission->post_title ) : '';
		}
		return '';
	}

	/**
	 * The groups a person may write to, with how many people each reaches.
	 *
	 * @param int $team Team ID.
	 * @param int $user User ID.
	 * @return array<string, string> Labels by group.
	 */
	public static function audiences( int $team, int $user ): array {
		$groups = array( 'team' );
		if ( Partners::get( $team ) ) {
			$groups = array( 'all', 'leads' );
			foreach ( Partners::organization_ids( $team ) as $organization ) {
				$groups[] = 'org-' . $organization;
			}
		} else {
			foreach ( Subteams::all( $team ) as $subteam ) {
				$groups[] = 'sub-' . $subteam['id'];
			}
			foreach ( self::missions( $team ) as $mission ) {
				$groups[] = 'mission-' . $mission->ID;
			}
		}
		$choices = array();
		foreach ( $groups as $to ) {
			if ( ! self::can_send( $team, $to, $user ) ) {
				continue;
			}
			$count = count( self::recipients( $team, $to ) );
			/* translators: 1: name of a group, 2: number of people. */
			$choices[ $to ] = sprintf( _n( '%1$s (%2$s person)', '%1$s (%2$s people)', $count, 'packout-core' ), self::label( $team, $to ), number_format_i18n( $count ) );
		}
		return $choices;
	}

	/**
	 * Send a message.
	 *
	 * @param int    $team    Team ID.
	 * @param string $to      team, sub-<number>, or mission-<ID>.
	 * @param string $subject Subject.
	 * @param string $body    Plain text.
	 * @param int    $user    The person sending.
	 * @param bool   $reply   Whether replies go to the sender.
	 * @return array{id: int, people: int, emailed: int, skipped: int}|\WP_Error
	 */
	public static function send( int $team, string $to, string $subject, string $body, int $user, bool $reply = true ) {
		$partner = Partners::get( $team );
		$post    = $partner ? $partner : Teams::get( $team );
		if ( ! $post ) {
			return new \WP_Error( 'packout_not_found', __( 'That organization does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( $partner && ! Partners::allows( $team, 'message' ) ) {
			return new \WP_Error( 'packout_partner_state', __( 'A partner can send messages while it is approved and active.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( ! $partner && ! Teams::active( $team ) ) {
			return new \WP_Error( 'packout_team_pending', __( 'An organization can send messages once a coordinator has approved it.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( ! self::can_send( $team, $to, $user ) ) {
			return new \WP_Error(
				'packout_forbidden',
				$partner
					? __( 'A partner\'s leads write to the people of its organizations.', 'packout-core' )
					: __( 'You can write to the people you lead: your organization, your team, or the people of a mission assigned to it.', 'packout-core' ),
				array( 'status' => 403 )
			);
		}
		$subject = trim( sanitize_text_field( $subject ) );
		$body    = trim( sanitize_textarea_field( $body ) );
		if ( '' === $subject || mb_strlen( $subject ) > self::SUBJECT ) {
			/* translators: %d: number of characters. */
			return new \WP_Error( 'packout_message_subject', sprintf( __( 'Give the message a subject of up to %d characters.', 'packout-core' ), self::SUBJECT ), array( 'status' => 400 ) );
		}
		if ( '' === $body || mb_strlen( $body ) > self::BODY ) {
			/* translators: %d: number of characters. */
			return new \WP_Error( 'packout_message_body', sprintf( __( 'Write the message, in up to %d characters.', 'packout-core' ), self::BODY ), array( 'status' => 400 ) );
		}
		$people = self::recipients( $team, $to );
		if ( ! $people || array( $user ) === $people ) {
			return new \WP_Error( 'packout_message_nobody', __( 'Nobody else is in that group yet.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( ! Accounts::allow( 'message-' . $user, self::PER_DAY, DAY_IN_SECONDS ) ) {
			return new \WP_Error( 'packout_message_limit', __( 'You have sent as many messages as one person can in a day. Try again tomorrow.', 'packout-core' ), array( 'status' => 429 ) );
		}

		// The sender gets a copy, whether or not they are in the group.
		$copies = array_values( array_unique( array_merge( $people, array( $user ) ) ) );
		$queue  = array_values( array_filter( $copies, static fn( $person ) => $person === $user || self::wants( $person, $team ) ) );
		$row    = array(
			'id'      => Schema::next_number( $team ),
			'at'      => gmdate( 'c' ),
			'by'      => $user,
			'to'      => $to,
			'label'   => self::label( $team, $to ),
			'subject' => $subject,
			'body'    => $body,
			'reply'   => $reply,
			'people'  => $people,
			'queue'   => $queue,
			'sent'    => 0,
			'skipped' => count( $copies ) - count( $queue ),
		);
		self::put( $team, array_merge( array( $row ), self::all( $team ) ) );

		/**
		 * A lead sent a message.
		 *
		 * @param int $team   Team ID.
		 * @param int $id     Message number.
		 * @param int $people How many people it is addressed to.
		 */
		do_action( 'packout_message_sent', $team, $row['id'], count( $people ) );

		self::deliver( $team, $row['id'] );
		return array(
			'id'      => $row['id'],
			'people'  => count( $people ),
			'emailed' => count( $queue ),
			'skipped' => $row['skipped'],
		);
	}

	/**
	 * Email the next people a message is waiting to reach.
	 *
	 * @param int $team Team ID.
	 * @param int $id   Message number.
	 */
	public static function deliver( $team, $id ): void {
		$team = (int) $team;
		$id   = (int) $id;
		$rows = self::all( $team );
		$post = Partners::get( $team ) ?? Teams::get( $team );
		foreach ( $rows as $index => $row ) {
			if ( $row['id'] !== $id || ! $row['queue'] || ! $post ) {
				continue;
			}
			$now = array_slice( $row['queue'], 0, self::BATCH );
			// The list is shortened first, so a run that is cut off does not write to the same people twice.
			$rows[ $index ]['queue'] = array_slice( $row['queue'], self::BATCH );
			self::put( $team, $rows );

			$sent = 0;
			foreach ( $now as $person ) {
				// A person may have left the team, or turned its messages off, since the message was written.
				if ( $person !== $row['by'] && ( ! self::reaches( $team, $person ) || ! self::wants( $person, $team ) ) ) {
					continue;
				}
				$sent += self::mail( $post, $row, $person ) ? 1 : 0;
			}

			$rows = self::all( $team );
			foreach ( $rows as $position => $fresh ) {
				if ( $fresh['id'] === $id ) {
					$rows[ $position ]['sent'] += $sent;
				}
			}
			self::put( $team, $rows );

			if ( $rows[ $index ]['queue'] && ! wp_next_scheduled( self::HOOK, array( $team, $id ) ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array( $team, $id ) );
			}
			return;
		}//end foreach
	}

	/**
	 * Whether a person is still among the people an organization or a partner writes to.
	 *
	 * @param int $team   Team ID or partner ID.
	 * @param int $person User ID.
	 */
	private static function reaches( int $team, int $person ): bool {
		return Partners::get( $team ) ? in_array( $person, Partners::people( $team ), true ) : Teams::includes( $team, $person );
	}

	/**
	 * The organization of a partner through which a person hears from it, or 0.
	 *
	 * A person has no setting for a partner. They turn an organization's
	 * messages off, and a partner's messages stop with the last of its
	 * organizations they are in.
	 *
	 * @param int $partner Partner ID.
	 * @param int $person  User ID.
	 * @return int An organization whose messages the person takes, the negative of one they turned off, or 0 when they are in none.
	 */
	private static function through( int $partner, int $person ): int {
		$first = 0;
		foreach ( Partners::organization_ids( $partner ) as $organization ) {
			if ( ! in_array( $person, Teams::people( $organization ), true ) ) {
				continue;
			}
			if ( Profile::wants_team_mail( $person, $organization ) ) {
				return $organization;
			}
			$first = 0 !== $first ? $first : -$organization;
		}
		return $first;
	}

	/**
	 * Whether an organization or a partner may email a person its messages.
	 *
	 * @param int $person User ID.
	 * @param int $team   Team ID or partner ID.
	 */
	private static function wants( int $person, int $team ): bool {
		if ( ! Partners::get( $team ) ) {
			return Profile::wants_team_mail( $person, $team );
		}
		// A lead of the partner who is in none of its organizations has nothing to turn off.
		return self::through( $team, $person ) >= 0;
	}

	/**
	 * The logo a message carries: the sub-team's when the message is to a sub-team that has one, otherwise the team's.
	 *
	 * @param int    $team Team ID.
	 * @param string $to   team, sub-<number>, or mission-<ID>.
	 * @return int Attachment ID, or 0.
	 */
	public static function logo( int $team, string $to ): int {
		$parts = explode( '-', $to, 2 );
		if ( 'sub' === $parts[0] ) {
			$logo = Subteams::logo( $team, (int) ( $parts[1] ?? 0 ) );
			if ( $logo > 0 ) {
				return $logo;
			}
		}
		return Partners::get( $team ) ? Partners::logo( $team ) : Teams::logo( $team );
	}

	/**
	 * A message as plain text and as HTML, for one person.
	 *
	 * @param \WP_Post             $team   Team.
	 * @param array<string, mixed> $row    Message.
	 * @param int                  $person The person it goes to.
	 * @return array{text: string, html: string}
	 */
	public static function compose( \WP_Post $team, array $row, int $person ): array {
		$sender  = get_userdata( (int) $row['by'] );
		$name    = $sender ? $sender->display_name : __( 'A lead', 'packout-core' );
		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$partner = Schema::PARTNER === $team->post_type;
		$through = $partner ? self::through( $team->ID, $person ) : $team->ID;
		$read    = $partner
			? Accounts::workspace_url(
				array(
					'partner' => $team->ID,
					'view'    => 'messages',
				)
			)
			: Workspace::url( $team->ID, 'messages' );
		// A partner's message stops with the organization the person is in. A lead in none has no link.
		$stop = $through > 0 ? Profile::quiet_link( $person, $through ) : '';
		$logo = self::logo( $team->ID, (string) $row['to'] );
		// A PNG copy of the logo where there is one: some email programs cannot show WebP.
		$image = $logo > 0 ? Photos::mail_url( $logo ) : '';
		$group = in_array( $row['to'], array( 'team', 'all' ), true ) ? '' : (string) $row['label'];
		/* translators: 1: person's name, 2: organization name. */
		$signed = sprintf( __( 'Sent by %1$s for %2$s', 'packout-core' ), $name, $team->post_title );
		$why    = $partner
			/* translators: 1: partner name, 2: site name. */
			? sprintf( __( 'You get this because you are in an organization of %1$s at %2$s.', 'packout-core' ), $team->post_title, $site )
			/* translators: 1: organization name, 2: site name. */
			: sprintf( __( 'You get this because you are on %1$s at %2$s.', 'packout-core' ), $team->post_title, $site );
		$stop_label = $partner ? __( 'Stop emails from your organization and its partner', 'packout-core' ) : __( 'Stop emails from this organization', 'packout-core' );

		$text  = $row['body'] . "\n\n-- \n" . $signed . ( '' !== $group ? "\n" . $group : '' ) . "\n\n" . $why . "\n";
		$text .= __( 'Read it online:', 'packout-core' ) . ' ' . $read . "\n" . ( '' !== $stop ? $stop_label . ': ' . $stop . "\n" : '' );

		$font  = 'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;';
		$html  = '<!DOCTYPE html><html><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>' . esc_html( (string) $row['subject'] ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background:#f4f1ea;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ea;"><tr><td align="center" style="padding:24px 12px;">';
		$html .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e3ded2;border-radius:8px;">';
		$html .= '<tr><td style="padding:24px 28px 0;' . $font . '">';
		if ( '' !== $image ) {
			$html .= '<img src="' . esc_url( $image ) . '" alt="' . esc_attr( $team->post_title ) . '" height="72" style="display:block;height:72px;width:auto;max-width:240px;border:0;margin:0 0 12px;" />';
		}
		$html .= '<p style="margin:0;font-size:14px;line-height:1.4;font-weight:600;color:#415840;">' . esc_html( $team->post_title ) . ( '' !== $group ? ' <span style="font-weight:400;color:#5b6060;">· ' . esc_html( $group ) . '</span>' : '' ) . '</p></td></tr>';
		$html .= '<tr><td style="padding:12px 28px 24px;' . $font . 'font-size:16px;line-height:1.55;color:#2f3232;">';
		$html .= '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#2f3232;">' . esc_html( (string) $row['subject'] ) . '</h1>';
		$html .= str_replace( array( '<p>', '<a href=' ), array( '<p style="margin:0 0 16px;">', '<a style="color:#863d14;" href=' ), wpautop( make_clickable( esc_html( (string) $row['body'] ) ) ) );
		$html .= '<p style="margin:24px 0 0;font-size:14px;color:#5b6060;">' . esc_html( $signed ) . '</p></td></tr>';
		$html .= '<tr><td style="padding:16px 28px 20px;border-top:1px solid #e3ded2;' . $font . 'font-size:13px;line-height:1.5;color:#5b6060;">' . esc_html( $why );
		$html .= ' <a href="' . esc_url( $read ) . '" style="color:#863d14;">' . esc_html__( 'Read it online', 'packout-core' ) . '</a>';
		if ( '' !== $stop ) {
			$html .= ' · <a href="' . esc_url( $stop ) . '" style="color:#863d14;">' . esc_html( $stop_label ) . '</a>';
		}
		$html .= '</td></tr></table></td></tr></table></body></html>';

		return array(
			'text' => $text,
			'html' => $html,
		);
	}

	/**
	 * Email a message to one person.
	 *
	 * @param \WP_Post             $team   Team.
	 * @param array<string, mixed> $row    Message.
	 * @param int                  $person The person it goes to.
	 */
	private static function mail( \WP_Post $team, array $row, int $person ): bool {
		$to = get_userdata( $person );
		if ( ! $to || ! is_email( $to->user_email ) ) {
			return false;
		}
		$parts   = self::compose( $team, $row, $person );
		$headers = array();
		$sender  = get_userdata( (int) $row['by'] );
		if ( ! empty( $row['reply'] ) && $sender && is_email( $sender->user_email ) ) {
			$headers[] = 'Reply-To: ' . str_replace( array( '"', "\r", "\n", '<', '>' ), '', $sender->display_name ) . ' <' . $sender->user_email . '>';
		}
		$through = Schema::PARTNER === $team->post_type ? self::through( $team->ID, $person ) : $team->ID;
		if ( $through > 0 ) {
			$headers[] = 'List-Unsubscribe: <' . Profile::quiet_link( $person, $through ) . '>';
		}

		// The sender's name and address stay the site's own. The organization is
		// in the subject; replies use Reply-To when allowed.
		return App::kernel()->mailer()->send( $to->user_email, '[' . wp_specialchars_decode( $team->post_title, ENT_QUOTES ) . '] ' . $row['subject'], $parts['html'], $headers, $parts['text'] );
	}

	/**
	 * The messages a person may read: the ones addressed to them, the ones
	 * they sent, and for a person who runs the team, all of them.
	 *
	 * @param int $team Team ID.
	 * @param int $user User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function visible( int $team, int $user ): array {
		$all = Partners::get( $team ) ? Partners::can_run( $team, $user ) : Teams::can_run( $team, $user );
		// A person who has left the team reads none of it.
		if ( ! $all && ! self::reaches( $team, $user ) ) {
			return array();
		}
		return array_values( array_filter( self::all( $team ), static fn( $row ) => $all || $row['by'] === $user || in_array( $user, $row['people'], true ) ) );
	}

	/**
	 * The Messages view: the messages a person may read, and the form that writes one.
	 *
	 * Writing is an action at the top that opens in the drawer. Each message opens there too.
	 *
	 * @param \WP_Post $team Team.
	 * @param int      $user User ID.
	 */
	public static function view( \WP_Post $team, int $user ): string {
		$id      = $team->ID;
		$do      = 'message';
		$choices = self::audiences( $id, $user );
		$html    = '';

		if ( $choices ) {
			$html .= '<p class="pos-ws__actions">' . Workspace_Lists::opener( 'message-new', __( 'Write to your people', 'packout-core' ), __( 'Write a message', 'packout-core' ) ) . '</p>';
			$form  = '<p class="pos-ws__muted">' . esc_html__( 'Each person gets their own email, with the logo at the top and your name on it. Nobody sees anyone else\'s address. People who turned these emails off still find the message here.', 'packout-core' ) . '</p>';
			$form .= Workspace::open( $do, $id );
			$form .= Workspace::choose( 'to', __( 'To', 'packout-core' ), $choices, Workspace::old( $do, 'to', (string) array_key_first( $choices ) ) );
			$form .= Workspace::input(
				'subject',
				__( 'Subject', 'packout-core' ),
				Workspace::old( $do, 'subject' ),
				array(
					'required'  => '',
					'maxlength' => (string) self::SUBJECT,
				)
			);
			$form .= Workspace::area(
				'body',
				__( 'Message', 'packout-core' ),
				Workspace::old( $do, 'body' ),
				/* translators: %s: number of characters. */
				sprintf( __( 'Plain text, up to %s characters. A web address becomes a link. Leave a blank line between paragraphs.', 'packout-core' ), number_format_i18n( self::BODY ) ),
				10
			);
			$form .= '<p class="pos-ws__field">' . Workspace::tick( 'reply', __( 'Replies come to my email address.', 'packout-core' ), Workspace::old_on( $do, 'reply', true ) ) . '<span class="pos-ws__help">' . esc_html__( 'Untick it and a reply goes to the site instead, where nobody reads it.', 'packout-core' ) . '</span></p>';
			$form .= '<p class="pos-ws__actions"><button class="pos-ws__button pos-ws__button--primary" data-pos-confirm="' . esc_attr__( 'Send this message now? It cannot be taken back.', 'packout-core' ) . '">' . esc_html__( 'Send the message', 'packout-core' ) . '</button></p></form>';
			Workspace_Lists::pane( 'message-new', __( 'Write to your people', 'packout-core' ), $form );
		} elseif ( Schema::PARTNER === $team->post_type ) {
			$html .= Partners::can_run( $id, $user ) ? '<p>' . esc_html__( 'A partner writes to its people while it is approved and active.', 'packout-core' ) . '</p>' : '';
		} elseif ( ! Teams::active( $id ) && Teams::can_run( $id, $user ) ) {
			$html .= '<p>' . esc_html__( 'You can write to the organization once a coordinator has approved it.', 'packout-core' ) . '</p>';
		}//end if
		$runs = Schema::PARTNER === $team->post_type ? Partners::can_run( $id, $user ) : Teams::can_run( $id, $user );

		$rows = array();
		foreach ( self::visible( $id, $user ) as $row ) {
			$sender = get_userdata( $row['by'] );
			$when   = strtotime( $row['at'] );
			$from   = $sender ? $sender->display_name : __( 'A lead', 'packout-core' );
			$sent   = $when ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ) : '';
			$facts  = array(
				__( 'From', 'packout-core' ) => $from,
				__( 'Sent', 'packout-core' ) => $sent,
				__( 'To', 'packout-core' )   => (string) $row['label'],
			);
			$reach  = '';
			if ( $row['by'] === $user || $runs ) {
				$count = count( $row['people'] );
				/* translators: %s: number of people. */
				$reach                                    = sprintf( _n( '%s person', '%s people', $count, 'packout-core' ), number_format_i18n( $count ) );
				$facts[ __( 'Reached', 'packout-core' ) ] = $reach;
				if ( $row['queue'] ) {
					/* translators: %s: number of emails. */
					$facts[ __( 'Still going out', 'packout-core' ) ] = sprintf( _n( '%s email', '%s emails', count( $row['queue'] ), 'packout-core' ), number_format_i18n( count( $row['queue'] ) ) );
				}
				if ( $row['skipped'] > 0 ) {
					/* translators: %s: number of people. */
					$facts[ __( 'Emails turned off', 'packout-core' ) ] = sprintf( _n( '%s person', '%s people', $row['skipped'], 'packout-core' ), number_format_i18n( $row['skipped'] ) );
				}
			}
			$pane   = 'message-' . $row['id'];
			$rows[] = array(
				'id'    => (string) $row['id'],
				'title' => (string) $row['subject'],
				'url'   => Workspace_Lists::link( $pane ),
				'from'  => $from,
				'sent'  => $sent,
				'to'    => (string) $row['label'],
				'reach' => $reach,
			);

			$detail = '<dl class="pos-ws__facts">';
			foreach ( array_filter( $facts ) as $label => $value ) {
				$detail .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
			}
			$detail .= '</dl><div class="pos-ws__message-body">' . wpautop( make_clickable( esc_html( $row['body'] ) ) ) . '</div>';
			Workspace_Lists::pane( $pane, (string) $row['subject'], $detail );
		}//end foreach

		$columns = array(
			'from' => array(
				'label' => __( 'From', 'packout-core' ),
				'sort'  => true,
			),
			'sent' => array( 'label' => __( 'Sent', 'packout-core' ) ),
			'to'   => array(
				'label' => __( 'To', 'packout-core' ),
				'sort'  => true,
			),
		);
		if ( array_filter( wp_list_pluck( $rows, 'reach' ) ) ) {
			$columns['reach'] = array( 'label' => __( 'Reached', 'packout-core' ) );
		}
		return $html . Workspace_Lists::view(
			'messages',
			$columns,
			$rows,
			array(
				'title'       => __( 'Subject', 'packout-core' ),
				'layout'      => 'list',
				'description' => 'sent',
				'empty'       => $choices ? __( 'Nothing has been sent yet.', 'packout-core' ) : __( 'No messages yet. When a lead writes to the organization, a team you are in, or the people of one of your missions, it shows here and arrives by email.', 'packout-core' ),
			)
		);
	}
}
