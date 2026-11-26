<?php
/**
 * One-time links that approve something from an email.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A signed link for a decision that can be made from the message itself.
 *
 * The message carries what was submitted and who submitted it, a link to the
 * record, and one button. The button opens a page with the same facts and a
 * single Approve control. Opening the link does not approve: mail programs
 * fetch links on their own. Declining stays in the admin or the workspace.
 * The link is good for fourteen days and works once. It is the proof, so the
 * person does not have to be signed in.
 */
class Approvals {

	/**
	 * How long a link works.
	 */
	private const TTL = 14 * DAY_IN_SECONDS;

	/**
	 * Hook the page the link opens.
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'handle' ), 0 );
	}

	/**
	 * A person who holds a capability, preferring the one who receives site mail.
	 *
	 * @param string $capability Capability the action requires.
	 */
	public static function coordinator( string $capability ): int {
		$email = (string) get_option( 'admin_email' );
		$user  = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		if ( $user instanceof \WP_User && user_can( $user, $capability ) ) {
			return (int) $user->ID;
		}
		$found = get_users(
			array(
				'capability' => $capability,
				'number'     => 1,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'fields'     => 'ID',
			)
		);
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Email the person who can approve, with the facts and one approve link.
	 *
	 * @param string               $to     Recipient.
	 * @param string               $action team, join, subteam, adopt, or claim.
	 * @param int                  $actor  User the approval is done as.
	 * @param array<string, mixed> $args   What the action needs: id, who, sub, place, request.
	 */
	public static function send( string $to, string $action, int $actor, array $args ): bool {
		if ( ! is_email( $to ) ) {
			return false;
		}
		$claims = self::claims( $action, $actor, $args );
		$about  = self::describe( $claims );
		if ( ! $about ) {
			return false;
		}
		$url = $actor > 0 && $about['ready'] ? self::url( $claims ) : '';
		return self::mail( $to, $about, $url );
	}

	/**
	 * The page a link opens, and the approval itself when the button is used.
	 */
	public static function handle(): void {
		if ( is_admin() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- The token is the proof. There is no session to check.
		// The token is compared with a stored hash and never shown, so it is read as it was sent.
		$posted = isset( $_POST['pos_approve'] ) ? wp_unslash( $_POST['pos_approve'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A token, checked against its hash.
		$got    = isset( $_GET['pos_approve'] ) ? wp_unslash( $_GET['pos_approve'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A token, checked against its hash.
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$raw = is_string( $posted ) && '' !== $posted ? $posted : ( is_string( $got ) ? $got : '' );
		if ( '' === $raw ) {
			return;
		}
		$token  = (string) preg_replace( '/[^A-Za-z0-9\-_.]/', '', $raw );
		$claims = Accounts::claims( $token );
		if ( ! $claims || 'approve' !== ( $claims['k'] ?? '' ) ) {
			self::page( __( 'This link has expired', 'packout-core' ), '<p>' . esc_html__( 'This approval link has expired or was changed. Review it from the site instead.', 'packout-core' ) . '</p>', 410 );
		}
		$about = self::describe( $claims );
		if ( ! $about ) {
			self::page( __( 'This link has expired', 'packout-core' ), '<p>' . esc_html__( 'This approval link has expired or was changed. Review it from the site instead.', 'packout-core' ) . '</p>', 410 );
		}
		$nonce = (string) ( $claims['n'] ?? '' );
		$key   = self::nonce_key( $nonce );
		if ( '' === $nonce || false === get_transient( $key ) ) {
			self::page( __( 'Already used', 'packout-core' ), '<p>' . esc_html__( 'This approval link was already used.', 'packout-core' ) . '</p>' . self::links_html( $about['links'] ) );
		}

		$do = isset( $_POST['pos_approve_do'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The token is the proof.
		if ( ! $do ) {
			$body = self::facts_html( $about['facts'] ) . self::links_html( $about['links'] );
			if ( $about['ready'] ) {
				$body .= '<form method="post" action="' . esc_url( home_url( '/' ) ) . '">';
				$body .= '<input type="hidden" name="pos_approve" value="' . esc_attr( $token ) . '" />';
				$body .= '<input type="hidden" name="pos_approve_do" value="1" />';
				$body .= '<p><button type="submit" style="display:inline-block;background:#863d14;color:#ffffff;font:600 16px/1 -apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;padding:14px 22px;border:0;border-radius:6px;">' . esc_html( $about['button'] ) . '</button></p>';
				$body .= '</form>';
				$body .= '<p style="color:#5b6060;font-size:14px;">' . esc_html__( 'The link in the email is the proof. It works once. To decline, open the record and decide there.', 'packout-core' ) . '</p>';
			} else {
				$body = '<p>' . esc_html( $about['done'] ) . '</p>' . $body;
			}
			self::page( $about['title'], $body );
		}

		delete_transient( $key );
		if ( ! $about['ready'] ) {
			self::page( $about['title'], '<p>' . esc_html( $about['done'] ) . '</p>' . self::links_html( $about['links'] ) );
		}
		$result = self::perform( $claims );
		if ( is_wp_error( $result ) ) {
			self::page( __( 'Not approved', 'packout-core' ), '<p>' . esc_html( $result->get_error_message() ) . '</p>' . self::links_html( $about['links'] ), 409 );
		}
		self::page( __( 'Approved', 'packout-core' ), '<p>' . esc_html( $result ) . '</p>' . self::links_html( $about['links'] ) );
	}

	/**
	 * What the email and the page say about one decision, from the records as they are now.
	 *
	 * @param array<string, mixed> $claims Token claims, without checking the one-time mark.
	 * @return array{title: string, intro: string, facts: array<string, string>, button: string, ready: bool, done: string, links: array<int, array{label: string, url: string}>}|null
	 */
	private static function describe( array $claims ): ?array {
		$action = (string) ( $claims['a'] ?? '' );
		$id     = (int) ( $claims['id'] ?? 0 );
		switch ( $action ) {
			case 'team':
				return self::describe_team( $id );
			case 'join':
				return self::describe_join( $id, (int) ( $claims['w'] ?? 0 ) );
			case 'subteam':
				return self::describe_subteam( $id, (int) ( $claims['s'] ?? 0 ), (int) ( $claims['w'] ?? 0 ) );
			case 'adopt':
				return self::describe_adopt( (int) ( $claims['p'] ?? 0 ), (string) ( $claims['r'] ?? '' ) );
			case 'claim':
				return self::describe_claim( $id );
			case 'partner':
				return self::describe_partner( $id );
		}
		return null;
	}

	/**
	 * Carry out the decision as the person the link was sent for.
	 *
	 * @param array<string, mixed> $claims Token claims.
	 * @return string|\WP_Error What to tell the person, or why it was refused.
	 */
	private static function perform( array $claims ) {
		$action = (string) ( $claims['a'] ?? '' );
		$actor  = (int) ( $claims['u'] ?? 0 );
		$id     = (int) ( $claims['id'] ?? 0 );
		if ( $actor <= 0 ) {
			return new \WP_Error( 'packout_approve', __( 'This link cannot approve on its own. Open the record and decide there.', 'packout-core' ) );
		}
		switch ( $action ) {
			case 'team':
				if ( ! Teams::can_edit( $actor ) ) {
					return new \WP_Error( 'packout_approve', __( 'A coordinator approves organizations. Open the record and decide there.', 'packout-core' ) );
				}
				$done = Teams::approve( $id, $actor );
				return is_wp_error( $done ) ? $done : __( 'The organization is approved. Its lead has been told.', 'packout-core' );
			case 'partner':
				if ( ! Partners::can_edit( $actor ) ) {
					return new \WP_Error( 'packout_approve', __( 'A coordinator approves partners. Open the record and decide there.', 'packout-core' ) );
				}
				$done = Partners::approve( $id, $actor );
				return is_wp_error( $done ) ? $done : __( 'The partner is approved. Its lead has been told.', 'packout-core' );
			case 'join':
				$done = Teams::answer( $id, (int) ( $claims['w'] ?? 0 ), true, $actor );
				return is_wp_error( $done ) ? $done : __( 'They are in the organization now.', 'packout-core' );
			case 'subteam':
				$done = Subteams::answer( $id, (int) ( $claims['s'] ?? 0 ), (int) ( $claims['w'] ?? 0 ), true, $actor );
				return is_wp_error( $done ) ? $done : __( 'They are in the team now.', 'packout-core' );
			case 'adopt':
				$done = Places::decide_request( (int) ( $claims['p'] ?? 0 ), (string) ( $claims['r'] ?? '' ), $actor, array( 'status' => 'accepted' ) );
				return is_wp_error( $done ) ? $done : __( 'The organization now adopts the Place.', 'packout-core' );
			case 'claim':
				if ( ! Capabilities::can( Capabilities::MODERATE, $actor ) ) {
					return new \WP_Error( 'packout_approve', __( 'A coordinator confirms a cleanup. Open the Spot and decide there.', 'packout-core' ) );
				}
				$done = Claims::confirm( $id );
				return is_wp_error( $done ) ? $done : __( 'The cleanup is confirmed. The Spot is marked cleaned.', 'packout-core' );
		}//end switch
		return new \WP_Error( 'packout_approve', __( 'This link cannot approve on its own. Open the record and decide there.', 'packout-core' ) );
	}

	/**
	 * An organization a person proposed.
	 *
	 * @param int $id Team ID.
	 * @return array{title: string, intro: string, facts: array<string, string>, button: string, ready: bool, done: string, links: array<int, array{label: string, url: string}>}|null
	 */
	private static function describe_team( int $id ): ?array {
		$team = Teams::get( $id );
		if ( ! $team ) {
			return null;
		}
		$lead    = get_userdata( Teams::lead( $id ) );
		$coleads = array_filter( array_map( 'get_userdata', Teams::coleads( $id ) ) );
		$facts   = array(
			__( 'Organization', 'packout-core' ) => $team->post_title,
			__( 'About', 'packout-core' )        => trim( wp_strip_all_tags( $team->post_content ) ),
			__( 'Works in', 'packout-core' )     => Teams::field( $id, 'region' ),
			__( 'Website', 'packout-core' )      => Teams::field( $id, 'website' ),
			__( 'Proposed by', 'packout-core' )  => $lead ? $lead->display_name : '',
			__( 'Email', 'packout-core' )        => $lead ? $lead->user_email : '',
			__( 'Co-leads', 'packout-core' )     => implode( ', ', wp_list_pluck( $coleads, 'display_name' ) ),
		);
		$ready   = ! Teams::active( $id );
		return array(
			'title'  => __( 'An organization is waiting for approval', 'packout-core' ),
			'intro'  => $lead
				/* translators: 1: person's name, 2: organization name. */
				? sprintf( __( '%1$s proposed the organization “%2$s”.', 'packout-core' ), $lead->display_name, $team->post_title )
				/* translators: %s: organization name. */
				: sprintf( __( 'The organization “%s” is waiting for approval.', 'packout-core' ), $team->post_title ),
			'facts'  => $facts,
			'button' => __( 'Approve the organization', 'packout-core' ),
			'ready'  => $ready,
			'done'   => __( 'This organization is already approved.', 'packout-core' ),
			'links'  => array(
				array(
					'label' => __( 'View in admin', 'packout-core' ),
					'url'   => admin_url( 'admin.php?page=packout-teams&open=' . $id ),
				),
			),
		);
	}

	/**
	 * A partner a person proposed.
	 *
	 * @param int $id Partner ID.
	 * @return array{title: string, intro: string, facts: array<string, string>, button: string, ready: bool, done: string, links: array<int, array{label: string, url: string}>}|null
	 */
	private static function describe_partner( int $id ): ?array {
		$partner = Partners::get( $id );
		if ( ! $partner ) {
			return null;
		}
		$lead  = get_userdata( Partners::lead( $id ) );
		$leads = array_filter( Teams::of_user( Partners::lead( $id ) ), static fn( $team ) => Teams::active( $team ) && Teams::leads_team( $team, Partners::lead( $id ) ) );
		$facts = array(
			__( 'Partner', 'packout-core' )     => $partner->post_title,
			__( 'About', 'packout-core' )       => trim( wp_strip_all_tags( $partner->post_content ) ),
			__( 'Works in', 'packout-core' )    => Partners::field( $id, 'region' ),
			__( 'Website', 'packout-core' )     => Partners::field( $id, 'website' ),
			__( 'Proposed by', 'packout-core' ) => $lead ? $lead->display_name : '',
			__( 'Email', 'packout-core' )       => $lead ? $lead->user_email : '',
			__( 'Leads', 'packout-core' )       => implode( ', ', array_map( 'get_the_title', $leads ) ),
		);
		return array(
			'title'  => __( 'A partner is waiting for approval', 'packout-core' ),
			'intro'  => $lead
				/* translators: 1: person's name, 2: partner name. */
				? sprintf( __( '%1$s proposed the partner “%2$s”. An approved partner adds organizations without a second review, and its leads manage them.', 'packout-core' ), $lead->display_name, $partner->post_title )
				/* translators: %s: partner name. */
				: sprintf( __( 'The partner “%s” is waiting for approval.', 'packout-core' ), $partner->post_title ),
			'facts'  => $facts,
			'button' => __( 'Approve the partner', 'packout-core' ),
			'ready'  => 'pending' === Partners::state( $id ),
			'done'   => __( 'This partner has already been decided.', 'packout-core' ),
			'links'  => array(
				array(
					'label' => __( 'View in admin', 'packout-core' ),
					'url'   => admin_url( 'admin.php?page=packout-partners&open=' . $id ),
				),
			),
		);
	}

	/**
	 * A request to join an organization.
	 *
	 * @param int $id  Team ID.
	 * @param int $who The person who asked.
	 * @return array{title: string, intro: string, facts: array<string, string>, button: string, ready: bool, done: string, links: array<int, array{label: string, url: string}>}|null
	 */
	private static function describe_join( int $id, int $who ): ?array {
		$team   = Teams::get( $id );
		$person = get_userdata( $who );
		if ( ! $team || ! $person ) {
			return null;
		}
		$ready = in_array( $who, Teams::requests( $id ), true );
		return array(
			'title'  => __( 'Someone asked to join your organization', 'packout-core' ),
			/* translators: 1: person's name, 2: organization name. */
			'intro'  => sprintf( __( '%1$s asked to join “%2$s”.', 'packout-core' ), $person->display_name, $team->post_title ),
			'facts'  => self::person_facts( $person, array( __( 'Organization', 'packout-core' ) => $team->post_title ) ),
			'button' => __( 'Accept into the organization', 'packout-core' ),
			'ready'  => $ready,
			'done'   => __( 'This request is no longer waiting.', 'packout-core' ),
			'links'  => array(
				array(
					'label' => __( 'View in My organizations', 'packout-core' ),
					'url'   => Accounts::workspace_url(
						array(
							'team' => $id,
							'view' => 'members',
						)
					),
				),
			),
		);
	}

	/**
	 * A request to join a team inside an organization.
	 *
	 * @param int $team_id Team ID.
	 * @param int $sub     Sub-team number.
	 * @param int $who     The person who asked.
	 * @return array{title: string, intro: string, facts: array<string, string>, button: string, ready: bool, done: string, links: array<int, array{label: string, url: string}>}|null
	 */
	private static function describe_subteam( int $team_id, int $sub, int $who ): ?array {
		$team    = Teams::get( $team_id );
		$subteam = $team ? Subteams::get( $team_id, $sub ) : null;
		$person  = get_userdata( $who );
		if ( ! $team || ! $subteam || ! $person ) {
			return null;
		}
		$on    = Teams::includes( $team_id, $who );
		$ready = $on && in_array( $who, (array) ( $subteam['requests'] ?? array() ), true );
		$done  = $on
			? __( 'This request is no longer waiting.', 'packout-core' )
			: __( 'They are not in the organization yet. The organization’s lead answers that request first.', 'packout-core' );
		return array(
			'title'  => __( 'Someone asked to join your team', 'packout-core' ),
			/* translators: 1: person's name, 2: team name, 3: organization name. */
			'intro'  => sprintf( __( '%1$s asked to join “%2$s”, a team of “%3$s”.', 'packout-core' ), $person->display_name, (string) $subteam['name'], $team->post_title ),
			'facts'  => self::person_facts(
				$person,
				array(
					__( 'Team', 'packout-core' )         => (string) $subteam['name'],
					__( 'Organization', 'packout-core' ) => $team->post_title,
				)
			),
			'button' => __( 'Accept into the team', 'packout-core' ),
			'ready'  => $ready,
			'done'   => $done,
			'links'  => array(
				array(
					'label' => __( 'View in My organizations', 'packout-core' ),
					'url'   => Accounts::workspace_url(
						array(
							'team' => $team_id,
							'view' => 'subteams',
						)
					),
				),
			),
		);
	}

	/**
	 * A request that an organization adopt a Place.
	 *
	 * @param int    $place_id Place ID.
	 * @param string $request  Request id.
	 * @return array{title: string, intro: string, facts: array<string, string>, button: string, ready: bool, done: string, links: array<int, array{label: string, url: string}>}|null
	 */
	private static function describe_adopt( int $place_id, string $request ): ?array {
		$place = Places::get( $place_id );
		if ( ! $place || '' === $request ) {
			return null;
		}
		$row = null;
		foreach ( Places::adoption_requests( $place_id ) as $item ) {
			if ( (string) ( $item['id'] ?? '' ) === $request ) {
				$row = $item;
				break;
			}
		}
		if ( ! is_array( $row ) ) {
			return null;
		}
		$team    = Teams::get( (int) ( $row['team_id'] ?? 0 ) );
		$person  = get_userdata( (int) ( $row['user_id'] ?? 0 ) );
		$feature = Features::get( (int) ( $row['feature_id'] ?? 0 ) );
		$facts   = array(
			__( 'Place', 'packout-core' )        => $place->post_title,
			__( 'Organization', 'packout-core' ) => $team ? $team->post_title : '',
			__( 'Note', 'packout-core' )         => (string) ( $row['note'] ?? '' ),
			__( 'Map feature', 'packout-core' )  => $feature ? $feature->post_title : '',
		);
		if ( $person ) {
			$facts[ __( 'Asked by', 'packout-core' ) ] = $person->display_name;
			$facts[ __( 'Email', 'packout-core' ) ]    = $person->user_email;
		}
		$links = array(
			array(
				'label' => __( 'View in admin', 'packout-core' ),
				'url'   => admin_url( 'admin.php?page=' . Admin::PLACES_PAGE . '&open=' . $place_id ),
			),
		);
		if ( 'publish' === $place->post_status ) {
			$links[] = array(
				'label' => __( 'View the Place', 'packout-core' ),
				'url'   => (string) get_permalink( $place ),
			);
		}
		$ready = 'pending' === (string) ( $row['status'] ?? '' );
		return array(
			'title'  => __( 'An organization asked to adopt a Place', 'packout-core' ),
			'intro'  => $team
				/* translators: 1: organization name, 2: Place name. */
				? sprintf( __( '“%1$s” asked to adopt “%2$s”.', 'packout-core' ), $team->post_title, $place->post_title )
				/* translators: %s: Place name. */
				: sprintf( __( 'An organization asked to adopt “%s”.', 'packout-core' ), $place->post_title ),
			'facts'  => $facts,
			'button' => __( 'Approve the adoption', 'packout-core' ),
			'ready'  => $ready,
			'done'   => __( 'This request is no longer waiting.', 'packout-core' ),
			'links'  => $links,
		);
	}

	/**
	 * A cleanup reported without a photo, waiting for a coordinator.
	 *
	 * @param int $id Spot ID.
	 * @return array{title: string, intro: string, facts: array<string, string>, button: string, ready: bool, done: string, links: array<int, array{label: string, url: string}>}|null
	 */
	private static function describe_claim( int $id ): ?array {
		$post  = Spots::get( $id );
		$claim = $post ? Claims::of( $id ) : null;
		if ( ! $post || ! $claim ) {
			return null;
		}
		$links = array(
			array(
				'label' => __( 'View in admin', 'packout-core' ),
				'url'   => admin_url( 'admin.php?page=packout-spots&open=' . $id ),
			),
		);
		if ( 'publish' === $post->post_status ) {
			$links[] = array(
				'label' => __( 'View the Spot', 'packout-core' ),
				'url'   => (string) get_permalink( $post ),
			);
		}
		return array(
			'title'  => __( 'A cleanup is waiting to be confirmed', 'packout-core' ),
			/* translators: 1: person's name, 2: Spot title. */
			'intro'  => sprintf( __( '%1$s reported cleaning “%2$s” without a photo. The Spot shows “Cleanup reported” until you confirm it.', 'packout-core' ), $claim['name'], $post->post_title ),
			'facts'  => array(
				__( 'Spot', 'packout-core' )        => $post->post_title,
				__( 'Reported by', 'packout-core' ) => $claim['name'],
				__( 'Email', 'packout-core' )       => $claim['email'],
				__( 'Note', 'packout-core' )        => $claim['note'],
			),
			'button' => __( 'Confirm the cleanup', 'packout-core' ),
			'ready'  => 'pending' === Spots::field( $id, 'status', 'spotted' ),
			'done'   => __( 'This Spot is no longer waiting to be confirmed.', 'packout-core' ),
			'links'  => $links,
		);
	}

	/**
	 * Name and email, then anything else the decision is about.
	 *
	 * @param \WP_User              $person The submitter.
	 * @param array<string, string> $more  More facts.
	 * @return array<string, string>
	 */
	private static function person_facts( \WP_User $person, array $more ): array {
		return array_merge(
			array(
				__( 'Name', 'packout-core' )  => $person->display_name,
				__( 'Email', 'packout-core' ) => $person->user_email,
			),
			$more
		);
	}

	/**
	 * Claims before the one-time mark and the expiry are added.
	 *
	 * @param string               $action team, join, subteam, adopt, or claim.
	 * @param int                  $actor  User the approval is done as.
	 * @param array<string, mixed> $args   id, who, sub, place, request.
	 * @return array<string, mixed>
	 */
	private static function claims( string $action, int $actor, array $args ): array {
		return array(
			'k'  => 'approve',
			'a'  => $action,
			'u'  => $actor,
			'id' => (int) ( $args['id'] ?? 0 ),
			'w'  => (int) ( $args['who'] ?? 0 ),
			's'  => (int) ( $args['sub'] ?? 0 ),
			'p'  => (int) ( $args['place'] ?? 0 ),
			'r'  => (string) ( $args['request'] ?? '' ),
		);
	}

	/**
	 * The address of the page, and the one-time mark that makes this link the only copy.
	 *
	 * @param array<string, mixed> $claims Claims from claims().
	 */
	private static function url( array $claims ): string {
		$nonce = strtolower( wp_generate_password( 20, false ) );
		set_transient( self::nonce_key( $nonce ), 1, self::TTL );
		$claims['n'] = $nonce;
		return add_query_arg( 'pos_approve', Accounts::token( $claims, self::TTL ), home_url( '/' ) );
	}

	/**
	 * Transient name for one link.
	 *
	 * @param string $nonce The mark.
	 */
	private static function nonce_key( string $nonce ): string {
		return 'pos_approve_' . $nonce;
	}

	/**
	 * Send the HTML message and a plain copy for programs that show only text.
	 *
	 * @param string                                                                                                                                                             $to    Recipient.
	 * @param array{title: string, intro: string, facts: array<string, string>, button: string, ready: bool, done: string, links: array<int, array{label: string, url: string}>} $about What to say.
	 * @param string                                                                                                                                                             $url   Approve page, or empty when there is nothing to approve.
	 */
	private static function mail( string $to, array $about, string $url ): bool {
		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = $about['title'];
		$intro   = $about['ready'] ? $about['intro'] : $about['done'];
		$facts   = array_filter( $about['facts'], static fn( $value ) => '' !== trim( (string) $value ) );

		$text = $intro . "\n\n";
		foreach ( $facts as $label => $value ) {
			$text .= $label . ': ' . $value . "\n";
		}
		if ( '' !== $url ) {
			$text .= "\n" . $about['button'] . ': ' . $url . "\n";
		}
		foreach ( $about['links'] as $link ) {
			$text .= $link['label'] . ': ' . $link['url'] . "\n";
		}
		$text .= "\n-- \n" . $site . "\n" . home_url( '/' ) . "\n";

		$font  = 'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;';
		$html  = '<!DOCTYPE html><html><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>' . esc_html( $subject ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background:#f4f1ea;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ea;"><tr><td align="center" style="padding:24px 12px;">';
		$html .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e3ded2;border-radius:8px;">';
		$html .= '<tr><td style="padding:24px 28px 8px;' . $font . 'font-size:14px;line-height:1.4;font-weight:600;color:#415840;">' . esc_html( $site ) . '</td></tr>';
		$html .= '<tr><td style="padding:8px 28px 24px;' . $font . 'font-size:16px;line-height:1.55;color:#2f3232;">';
		$html .= '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#2f3232;">' . esc_html( $subject ) . '</h1>';
		$html .= '<p style="margin:0 0 16px;">' . esc_html( $intro ) . '</p>';
		if ( $facts ) {
			$html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 20px;' . $font . 'font-size:15px;line-height:1.45;">';
			foreach ( $facts as $label => $value ) {
				$html .= '<tr><td style="padding:6px 16px 6px 0;color:#5b6060;vertical-align:top;white-space:nowrap;">' . esc_html( (string) $label ) . '</td>';
				$html .= '<td style="padding:6px 0;color:#2f3232;vertical-align:top;">' . esc_html( (string) $value ) . '</td></tr>';
			}
			$html .= '</table>';
		}
		if ( '' !== $url ) {
			$html .= '<p style="margin:0 0 16px;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#863d14;color:#ffffff;text-decoration:none;font-weight:600;font-size:16px;line-height:1;padding:14px 22px;border-radius:6px;">' . esc_html( $about['button'] ) . '</a></p>';
			$html .= '<p style="margin:0 0 16px;font-size:14px;color:#5b6060;">' . esc_html__( 'To decline, open the record and decide there. This link only approves.', 'packout-core' ) . '</p>';
		}
		if ( $about['links'] ) {
			$html .= '<p style="margin:0;font-size:14px;line-height:1.6;">';
			$first = true;
			foreach ( $about['links'] as $link ) {
				if ( ! $first ) {
					$html .= ' · ';
				}
				$first = false;
				$html .= '<a href="' . esc_url( $link['url'] ) . '" style="color:#863d14;">' . esc_html( $link['label'] ) . '</a>';
			}
			$html .= '</p>';
		}
		$html .= '</td></tr></table></td></tr></table></body></html>';

		return App::kernel()->mailer()->send( $to, '[' . $site . '] ' . $subject, $html, array(), $text );
	}

	/**
	 * Facts as a list.
	 *
	 * @param array<string, string> $facts Label, value.
	 */
	private static function facts_html( array $facts ): string {
		$html = '';
		foreach ( $facts as $label => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$html .= '<p style="margin:0 0 8px;"><span style="color:#5b6060;">' . esc_html( (string) $label ) . '</span><br />' . esc_html( (string) $value ) . '</p>';
		}
		return $html;
	}

	/**
	 * The view links.
	 *
	 * @param array<int, array{label: string, url: string}> $links Links.
	 */
	private static function links_html( array $links ): string {
		if ( ! $links ) {
			return '';
		}
		$html = '<p>';
		foreach ( $links as $link ) {
			$html .= '<a href="' . esc_url( $link['url'] ) . '" style="color:#863d14;margin-right:16px;">' . esc_html( $link['label'] ) . '</a>';
		}
		return $html . '</p>';
	}

	/**
	 * A small page, then stop. The theme is not involved.
	 *
	 * @param string $title   Heading.
	 * @param string $body    Markup already escaped.
	 * @param int    $status  HTTP status.
	 */
	private static function page( string $title, string $body, int $status = 200 ): void {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		$site = esc_html( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		echo '<!DOCTYPE html><html><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><meta name="robots" content="noindex" /><title>' . esc_html( $title ) . '</title></head>';
		echo '<body style="margin:0;background:#f4f1ea;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;color:#2f3232;">';
		echo '<main style="max-width:560px;margin:32px auto;padding:24px;background:#fff;border:1px solid #e3ded2;border-radius:8px;">';
		echo '<p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#415840;">' . $site . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		echo '<h1 style="margin:0 0 16px;font-size:24px;line-height:1.3;">' . esc_html( $title ) . '</h1>';
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Callers escape.
		echo '</main></body></html>';
		exit;
	}
}
