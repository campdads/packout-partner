<?php
/**
 * Signing up for a campaign or a mission.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A public campaign or mission of a listed team takes sign-ups.
 *
 * Signing up is joining: the person goes on the team, by the team's own join
 * policy, and into the sub-team responsible for the event. The team's leads
 * are told each time. The people who signed up are stored on the event, and a
 * sign-up that waits for the lead's answer is stored on the team.
 */
class Signups {

	/**
	 * Register the hooks.
	 */
	public static function register(): void {
		add_action( 'packout_team_joined', array( self::class, 'settle' ), 10, 2 );
	}

	/**
	 * A campaign or a mission as a thing to sign up for, or null.
	 *
	 * @param int $id Post ID.
	 * @return array{id: int, type: string, team: int, title: string, url: string, public: bool, running: bool, subteams: array<int, int>}|null
	 */
	public static function event( int $id ): ?array {
		$mission  = Missions::get( $id );
		$campaign = $mission ? null : Campaigns::get( $id );
		$post     = $mission ?? $campaign;
		if ( ! $post ) {
			return null;
		}
		if ( $mission ) {
			$team     = Missions::team( $id );
			$subteams = Missions::subteams( $id );
			$running  = Missions::is_open( $id );
		} else {
			$team     = Campaigns::team( $id );
			$subteams = Campaigns::subteams( $id );
			$end      = Campaigns::field( $id, 'end' );
			$running  = '' === $end || $end >= wp_date( 'Y-m-d' );
		}
		return array(
			'id'       => $id,
			'type'     => $mission ? 'mission' : 'campaign',
			'team'     => $team,
			'title'    => $post->post_title,
			'url'      => (string) get_permalink( $post ),
			'public'   => 'publish' === $post->post_status,
			'running'  => $running,
			'subteams' => $subteams,
		);
	}

	/**
	 * Whether an event takes sign-ups: it is public, it has not ended, and its team is approved and listed.
	 *
	 * @param int $id Campaign or mission ID.
	 */
	public static function takes( int $id ): bool {
		// A copy of a record kept on a partner's site is changed there, by nobody here.
		if ( Remote::is( $id ) ) {
			return false;
		}
		$event = self::event( $id );
		$team  = $event ? Teams::get( $event['team'] ) : null;
		return $event && $team && $event['public'] && $event['running'] && 'publish' === $team->post_status && Teams::active( $team->ID );
	}

	/**
	 * User IDs of the team members who signed up for an event.
	 *
	 * @param int $id Campaign or mission ID.
	 * @return array<int, int>
	 */
	public static function people( int $id ): array {
		$event = self::event( $id );
		if ( ! $event ) {
			return array();
		}
		$people = array_map( 'intval', Schema::stored_list( $id, 'signup' ) );
		return array_values( array_unique( array_filter( $people, static fn( $user ) => Teams::includes( $event['team'], $user ) ) ) );
	}

	/**
	 * Sign-ups that wait for the lead to accept the person, by user ID.
	 *
	 * A request that was declined or taken back has nothing waiting.
	 *
	 * @param int $team_id Team ID.
	 * @return array<int, array{event: int, subteam: int}>
	 */
	public static function waiting( int $team_id ): array {
		$asked   = Teams::requests( $team_id );
		$waiting = array();
		foreach ( Schema::stored_list( $team_id, 'signup_requests' ) as $user => $row ) {
			if ( is_array( $row ) && in_array( (int) $user, $asked, true ) && self::event( (int) ( $row['event'] ?? 0 ) ) ) {
				$waiting[ (int) $user ] = array(
					'event'   => (int) $row['event'],
					'subteam' => (int) ( $row['subteam'] ?? 0 ),
				);
			}
		}
		return $waiting;
	}

	/**
	 * Where a person stands with an event: `signed`, `requested`, or an empty string.
	 *
	 * @param int $id      Campaign or mission ID.
	 * @param int $user_id User ID.
	 */
	public static function state( int $id, int $user_id ): string {
		$event = self::event( $id );
		if ( ! $event || $user_id <= 0 ) {
			return '';
		}
		if ( in_array( $user_id, self::people( $id ), true ) ) {
			return 'signed';
		}
		$waiting = self::waiting( $event['team'] );
		return isset( $waiting[ $user_id ] ) && $waiting[ $user_id ]['event'] === $id ? 'requested' : '';
	}

	/**
	 * Sign a person up for a campaign or a mission.
	 *
	 * @param int $id      Campaign or mission ID.
	 * @param int $user_id The person signing up.
	 * @param int $subteam The sub-team they chose, when the event has more than one.
	 * @return string|\WP_Error `joined`, `requested`, or `signed`.
	 */
	public static function sign_up( int $id, int $user_id, int $subteam = 0 ) {
		$event = self::event( $id );
		if ( ! $event || ! $event['public'] ) {
			return new \WP_Error( 'packout_not_found', __( 'That is not open for sign-up.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! Accounts::on_site( $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'Sign up for Pack-Out Spot first.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! self::takes( $id ) ) {
			return new \WP_Error(
				'packout_signup_closed',
				$event['running'] ? __( 'This organization is not taking sign-ups.', 'packout-core' ) : __( 'This has ended, so it takes no more sign-ups.', 'packout-core' ),
				array( 'status' => 409 )
			);
		}
		$team = $event['team'];

		// The sub-team responsible. With one there is nothing to choose. With several the person chooses.
		$choices = $event['subteams'];
		if ( 1 === count( $choices ) ) {
			$subteam = $choices[0];
		} elseif ( $choices && ! in_array( $subteam, $choices, true ) ) {
			return new \WP_Error( 'packout_signup_subteam', __( 'Choose the team you want to work with.', 'packout-core' ), array( 'status' => 400 ) );
		} elseif ( ! $choices ) {
			$subteam = 0;
		}

		// Where a youth team takes part, signing up is for the people the organization already has, and it puts nobody in a youth team.
		if ( Subteams::youth_among( $team, $choices ) ) {
			if ( ! Teams::includes( $team, $user_id ) ) {
				return new \WP_Error( 'packout_signup_youth', self::youth_words(), array( 'status' => 403 ) );
			}
			if ( $subteam > 0 && Subteams::youth_among( $team, array( $subteam ) ) && ! Subteams::includes( $team, $subteam, $user_id ) ) {
				return new \WP_Error( 'packout_signup_youth', __( 'That is a youth team. Its lead adds its members.', 'packout-core' ), array( 'status' => 403 ) );
			}
		}

		if ( Teams::includes( $team, $user_id ) ) {
			if ( in_array( $user_id, self::people( $id ), true ) ) {
				return 'signed';
			}
			$result = 'signed';
		} else {
			$result = Teams::ask( $team, $user_id, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( 'requested' === $result ) {
			$waiting             = Schema::stored_list( $team, 'signup_requests' );
			$waiting[ $user_id ] = array(
				'event'   => $id,
				'subteam' => $subteam,
			);
			Schema::write_list( $team, 'signup_requests', $waiting );
		} else {
			self::enter( $id, $user_id, $subteam );
		}
		self::tell( $event, $user_id, $subteam, $result );

		/**
		 * A person signed up for a campaign or a mission.
		 *
		 * @param int    $id      Campaign or mission ID.
		 * @param int    $user_id The person.
		 * @param string $result  `joined`: they are on the team now. `requested`: they wait for the lead. `signed`: they were on the team already.
		 */
		do_action( 'packout_event_signup', $id, $user_id, $result );
		return $result;
	}

	/**
	 * What a page says where a youth team takes part and the public cannot sign up.
	 */
	public static function youth_words(): string {
		return __( 'A youth team takes part in this, so there is no sign-up here for the public. The organization adds the people who take part.', 'packout-core' );
	}

	/**
	 * Take a sign-up back. The person stays on the team, and a request to join stays with the lead.
	 *
	 * @param int $id      Campaign or mission ID.
	 * @param int $user_id The person.
	 */
	public static function withdraw( int $id, int $user_id ): bool {
		$event = self::event( $id );
		if ( ! $event || $user_id <= 0 ) {
			return false;
		}
		$people = array_map( 'intval', Schema::stored_list( $id, 'signup' ) );
		$found  = in_array( $user_id, $people, true );
		if ( $found ) {
			Schema::write_list( $id, 'signup', array_values( array_diff( $people, array( $user_id ) ) ) );
		}
		$waiting = Schema::stored_list( $event['team'], 'signup_requests' );
		if ( isset( $waiting[ $user_id ] ) && (int) ( $waiting[ $user_id ]['event'] ?? 0 ) === $id ) {
			unset( $waiting[ $user_id ] );
			Schema::write_list( $event['team'], 'signup_requests', $waiting );
			$found = true;
		}
		return $found;
	}

	/**
	 * When a person joins a team, finish the sign-up that was waiting for it.
	 *
	 * @param mixed $team_id Team ID.
	 * @param mixed $user_id User ID.
	 */
	public static function settle( $team_id, $user_id ): void {
		$team    = (int) $team_id;
		$user    = (int) $user_id;
		$waiting = Schema::stored_list( $team, 'signup_requests' );
		if ( ! isset( $waiting[ $user ] ) || ! is_array( $waiting[ $user ] ) ) {
			return;
		}
		$row = $waiting[ $user ];
		unset( $waiting[ $user ] );
		Schema::write_list( $team, 'signup_requests', $waiting );
		$event = self::event( (int) ( $row['event'] ?? 0 ) );
		if ( $event && $event['team'] === $team ) {
			self::enter( $event['id'], $user, (int) ( $row['subteam'] ?? 0 ) );
		}
	}

	/**
	 * List a team member on an event and put them in its sub-team.
	 *
	 * @param int $id      Campaign or mission ID.
	 * @param int $user_id The team member.
	 * @param int $subteam Sub-team number, or 0.
	 */
	private static function enter( int $id, int $user_id, int $subteam ): void {
		$event = self::event( $id );
		if ( ! $event || ! Teams::includes( $event['team'], $user_id ) ) {
			return;
		}
		$people = array_map( 'intval', Schema::stored_list( $id, 'signup' ) );
		if ( ! in_array( $user_id, $people, true ) ) {
			$people[] = $user_id;
			Schema::write_list( $id, 'signup', $people );
		}
		if ( $subteam > 0 && in_array( $subteam, $event['subteams'], true ) ) {
			Subteams::enrol( $event['team'], $subteam, $user_id );
		}
		Missions::flush();
	}

	/**
	 * Tell the team's leads, and the leads of the sub-team, about a sign-up.
	 *
	 * @param array<string, mixed> $event   From event().
	 * @param int                  $user_id The person who signed up.
	 * @param int                  $subteam Sub-team number, or 0.
	 * @param string               $result  `joined`, `requested`, or `signed`.
	 */
	private static function tell( array $event, int $user_id, int $subteam, string $result ): void {
		$person = get_userdata( $user_id );
		$team   = Teams::get( (int) $event['team'] );
		if ( ! $person || ! $team ) {
			return;
		}
		$to  = Teams::leads( $team->ID );
		$sub = $subteam > 0 ? Subteams::get( $team->ID, $subteam ) : null;
		if ( $sub ) {
			$to = array_merge( $to, array( $sub['lead'] ), $sub['coleads'] );
		}
		$to = array_diff( array_unique( array_filter( array_map( 'intval', $to ) ) ), array( $user_id ) );

		$mission = 'mission' === $event['type'];
		$lines   = array(
			sprintf(
				$mission
					/* translators: 1: the person's name, 2: mission title, 3: organization name. */
					? __( '%1$s signed up for the mission “%2$s” of “%3$s”.', 'packout-core' )
					/* translators: 1: the person's name, 2: campaign title, 3: organization name. */
					: __( '%1$s signed up for the campaign “%2$s” of “%3$s”.', 'packout-core' ),
				$person->display_name,
				$event['title'],
				$team->post_title
			),
		);
		if ( $sub ) {
			/* translators: %s: team name. */
			$lines[] = sprintf( __( 'Team: %s.', 'packout-core' ), $sub['name'] );
		}
		if ( 'requested' === $result ) {
			$lines[] = __( 'They are not in the organization yet. Accept or decline:', 'packout-core' ) . ' ' . Accounts::workspace_url(
				array(
					'team' => $team->ID,
					'view' => 'members',
				)
			);
		} else {
			$lines[] = ( 'joined' === $result ? __( 'They joined the organization with this sign-up.', 'packout-core' ) : __( 'They were in the organization already.', 'packout-core' ) ) . ' ' . Accounts::workspace_url(
				array(
					'team' => $team->ID,
					'view' => $event['type'],
					'item' => $event['id'],
				)
			);
		}
		foreach ( array_filter( array_map( 'get_userdata', $to ) ) as $lead ) {
			Accounts::mail( $lead->user_email, $mission ? __( 'Someone signed up for a mission', 'packout-core' ) : __( 'Someone signed up for a campaign', 'packout-core' ), $lines );
		}
	}

	/**
	 * The sign-up section of a public campaign or mission page.
	 *
	 * A visitor's page may be cached, so it says nothing about who is looking.
	 *
	 * @param int $id Campaign or mission ID.
	 */
	public static function section( int $id ): string {
		$event = self::event( $id );
		if ( Remote::is( $id ) ) {
			$mission = Schema::MISSION === get_post_type( $id );
			// A youth team takes part: the hub offers no way to sign up, here or there.
			if ( $event && Subteams::youth_among( $event['team'], $event['subteams'] ) ) {
				return '';
			}
			/* translators: %s: the name of a partner. */
			return Remote::sign_up( $id, $mission ? __( 'Sign up for this mission', 'packout-core' ) : __( 'Sign up for this campaign', 'packout-core' ), sprintf( __( 'This is run from the site of %s. Sign up there.', 'packout-core' ), Remote::partner_name( $id ) ) );
		}
		if ( ! $event || ! self::takes( $id ) ) {
			return '';
		}
		$team    = $event['team'];
		$policy  = Teams::join_policy( $team );
		$user    = get_current_user_id();
		$here    = $event['url'] . '#pos-signup';
		$mission = 'mission' === $event['type'];
		$on_team = $user > 0 && Teams::includes( $team, $user );
		$name    = (string) get_the_title( $team );

		$html = '<section class="pos-place__section pos-signup pos-ws" id="pos-signup"><h2>' . esc_html( $mission ? __( 'Sign up for this mission', 'packout-core' ) : __( 'Sign up for this campaign', 'packout-core' ) ) . '</h2>';
		if ( $user > 0 ) {
			$html .= Workspace::flash( $user );
			if ( in_array( Workspace::failed_action(), array( 'signup', 'signup_withdraw' ), true ) && '' !== Workspace::problem() ) {
				$html .= Workspace::notice( 'error', Workspace::problem() );
			}
		}

		$state = self::state( $id, $user );
		if ( 'signed' === $state ) {
			$html .= '<p>' . esc_html__( 'You are signed up.', 'packout-core' ) . ' <a href="' . esc_url( Workspace::url( $team, $event['type'], (string) $id ) ) . '">' . esc_html__( 'Open it in My organizations', 'packout-core' ) . '</a></p>';
			return $html . '<div class="pos-ws__actions">' . Workspace::act(
				'signup_withdraw',
				$team,
				__( 'Take my sign-up back', 'packout-core' ),
				array(
					'item' => (string) $id,
					'back' => $here,
				),
				'pos-ws__button--danger',
				__( 'Take your sign-up back? You stay in the organization.', 'packout-core' )
			) . '</div></section>';
		}
		if ( 'requested' === $state ) {
			$html .= '<p>' . esc_html__( 'Your sign-up is with the organization’s lead. You will get an email when they answer.', 'packout-core' ) . '</p>';
			return $html . '<div class="pos-ws__actions">' . Workspace::act(
				'signup_withdraw',
				$team,
				__( 'Take my sign-up back', 'packout-core' ),
				array(
					'item' => (string) $id,
					'back' => $here,
				),
				'pos-ws__button--danger',
				__( 'Take your sign-up back? Your request to join the organization stays with its lead.', 'packout-core' )
			) . '</div></section>';
		}
		if ( ! $on_team && 'closed' === $policy ) {
			/* translators: %s: organization name. */
			return $html . '<p>' . esc_html( sprintf( __( '%s adds its members by invitation, so there is no sign-up here. Ask the organization for an invitation.', 'packout-core' ), $name ) ) . '</p></section>';
		}
		if ( ! $on_team && Subteams::youth_among( $team, $event['subteams'] ) ) {
			return $html . '<p>' . esc_html( self::youth_words() ) . '</p></section>';
		}

		if ( $on_team ) {
			$html .= '<p>' . esc_html__( 'You are in this organization. Signing up tells its leads you are coming.', 'packout-core' ) . '</p>';
		} elseif ( 'open' === $policy ) {
			/* translators: %s: organization name. */
			$html .= '<p>' . esc_html( sprintf( __( 'Signing up adds you to %s, the organization running this. Its leads see your name and email address.', 'packout-core' ), $name ) ) . '</p>';
		} else {
			/* translators: %s: organization name. */
			$html .= '<p>' . esc_html( sprintf( __( 'Signing up asks to join %s, the organization running this. Its lead answers, and if they accept they see your name and email address.', 'packout-core' ), $name ) ) . '</p>';
		}

		if ( $user <= 0 ) {
			$html .= '<p class="pos-ws__actions"><a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( Accounts::signin_url( $here ) ) . '">' . esc_html__( 'Sign in to sign up', 'packout-core' ) . '</a>';
			if ( Accounts::open() ) {
				$html .= ' <a class="pos-ws__button" href="' . esc_url( Accounts::signup_url( $here ) ) . '">' . esc_html__( 'Create an account', 'packout-core' ) . '</a>';
			}
			return $html . '</p></section>';
		}
		if ( ! Accounts::on_site( $user ) ) {
			return $html . '<p class="pos-ws__actions"><a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( Workspace::url() ) . '">' . esc_html__( 'Join Pack-Out Spot first', 'packout-core' ) . '</a></p></section>';
		}

		$html .= Workspace::open(
			'signup',
			$team,
			array(
				'item' => (string) $id,
				'back' => $here,
			)
		);
		$names = Subteams::names( $team, $event['subteams'] );
		if ( count( $event['subteams'] ) > 1 ) {
			$options = array( '' => __( 'Choose a team', 'packout-core' ) );
			foreach ( $event['subteams'] as $position => $subteam ) {
				$options[ (string) $subteam ] = $names[ $position ] ?? (string) $subteam;
			}
			$html .= Workspace::choose( 'subteam', __( 'Team to work with', 'packout-core' ), $options, Workspace::old( 'signup', 'subteam' ) );
		} elseif ( $names ) {
			/* translators: %s: team name. */
			$html .= '<p class="pos-signup__subteam">' . esc_html( sprintf( __( 'You will be in the team “%s”.', 'packout-core' ), $names[0] ) ) . '</p>';
		}
		$label = $on_team || 'open' === $policy ? __( 'Sign up', 'packout-core' ) : __( 'Sign up and ask to join', 'packout-core' );
		return $html . Workspace::submit( $label ) . '</form></section>';
	}

	/**
	 * Who signed up, for the people who run the event.
	 *
	 * @param int $id Campaign or mission ID.
	 */
	public static function list_html( int $id ): string {
		$event = self::event( $id );
		if ( ! $event ) {
			return '';
		}
		$rows = array();
		foreach ( self::people( $id ) as $user ) {
			$person = get_userdata( $user );
			if ( $person ) {
				$rows[] = '<li>' . esc_html( $person->display_name ) . '</li>';
			}
		}
		foreach ( self::waiting( $event['team'] ) as $user => $row ) {
			$person = $row['event'] === $id ? get_userdata( $user ) : null;
			if ( $person ) {
				$rows[] = '<li>' . esc_html( $person->display_name ) . ' <span class="pos-ws__badge">' . esc_html__( 'Asked to join', 'packout-core' ) . '</span></li>';
			}
		}
		if ( ! $rows ) {
			return '<p class="pos-ws__empty">' . esc_html(
				self::takes( $id )
					? __( 'Nobody has signed up yet.', 'packout-core' )
					: __( 'This takes sign-ups on its public page when it is public and the organization is listed.', 'packout-core' )
			) . '</p>';
		}
		return '<ul class="pos-ws__plain pos-signup__list">' . implode( '', $rows ) . '</ul>';
	}
}
