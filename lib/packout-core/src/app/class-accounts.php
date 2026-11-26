<?php
/**
 * Accounts: sign-up, invitations, and where a person lands after signing in.
 *
 * Signing in, signing out, and choosing a new password are in class-sign-in.php.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An account is a WordPress user on this site with the Reporter role.
 *
 * The plugin runs its own sign-up, so network registration stays off and no
 * other site gains a form. A person asks with a name and an email address,
 * follows the link they are sent, and chooses a password. The link is signed
 * with the site's secret keys and carries the name and address itself, so
 * nothing is stored until the account is made.
 */
class Accounts {

	public const PASSWORD_LENGTH = 12;

	/**
	 * How long a sign-up link and an invitation stay good, in seconds.
	 */
	private const SIGNUP_TTL = 3 * DAY_IN_SECONDS;

	/**
	 * How long an invitation link stays good, in seconds.
	 */
	public const INVITE_TTL = 14 * DAY_IN_SECONDS;

	/**
	 * Requests accepted from one address in an hour, and for one email in a day.
	 */
	private const PER_ADDRESS = 8;
	private const PER_EMAIL   = 3;

	/**
	 * Register the hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'handle' ), 30 );
		add_filter( 'login_redirect', array( self::class, 'after_sign_in' ), 10, 3 );
		add_filter( 'show_admin_bar', array( self::class, 'admin_bar' ) );
	}

	/**
	 * Address of a page named in settings, or the home page.
	 *
	 * @param string               $setting signup_page, signin_page, or workspace_page.
	 * @param array<string, mixed> $args    Query arguments.
	 */
	public static function page( string $setting, array $args = array() ): string {
		$id  = (int) Settings::get( $setting );
		$url = $id > 0 && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : home_url( '/' );
		return $args ? add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $url ) : $url;
	}

	/**
	 * Whether the site has a sign-up page.
	 */
	public static function open(): bool {
		$id = (int) Settings::get( 'signup_page' );
		return $id > 0 && 'publish' === get_post_status( $id );
	}

	/**
	 * The sign-up page.
	 *
	 * @param string $back Address on this site to return to afterward.
	 */
	public static function signup_url( string $back = '' ): string {
		$back = self::local( $back );
		return self::page( 'signup_page', '' !== $back ? array( 'back' => $back ) : array() );
	}

	/**
	 * The workspace page.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	public static function workspace_url( array $args = array() ): string {
		return self::page( 'workspace_page', $args );
	}

	/**
	 * Where a person signs in, returning to an address on this site.
	 *
	 * The site's own Sign in page when settings name one. Without one it is
	 * the WordPress sign-in screen.
	 *
	 * @param string $back Address to return to. The workspace when empty.
	 */
	public static function signin_url( string $back = '' ): string {
		$back = self::local( $back );
		if ( Sign_In::open() ) {
			return Sign_In::url( '' !== $back ? array( 'back' => $back ) : array() );
		}
		return wp_login_url( '' !== $back ? $back : self::workspace_url() );
	}

	/**
	 * A password as WordPress stores and checks it.
	 *
	 * The WordPress sign-in screen checks a password with the slashes PHP
	 * added to the request still in it, and its own forms store one the same
	 * way. A password with a quote mark or a backslash in it only matches when
	 * this plugin does the same.
	 *
	 * @param string $password The password as the person typed it.
	 */
	public static function as_stored( string $password ): string {
		return (string) wp_slash( $password );
	}

	/**
	 * An address on this site, or an empty string.
	 *
	 * @param string $url Address a request named.
	 */
	public static function local( string $url ): string {
		$url = trim( $url );
		return '' !== $url ? (string) wp_validate_redirect( esc_url_raw( $url ), '' ) : '';
	}

	/**
	 * Whether a person can use the product on this site.
	 *
	 * @param int $user_id User ID.
	 */
	public static function on_site( int $user_id ): bool {
		return $user_id > 0 && ( ! is_multisite() || is_user_member_of_blog( $user_id ) ) && Capabilities::can( Capabilities::REPORT, $user_id );
	}

	/**
	 * Give a signed-in person from elsewhere on the network the Reporter role here.
	 *
	 * @param int $user_id User ID.
	 */
	public static function join_site( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}
		if ( self::on_site( $user_id ) ) {
			return true;
		}
		if ( is_multisite() && ! is_user_member_of_blog( $user_id ) ) {
			return true === add_user_to_blog( get_current_blog_id(), $user_id, 'pos_reporter' );
		}
		$user->add_role( 'pos_reporter' );
		return true;
	}

	/**
	 * Sign a set of claims.
	 *
	 * @param array<string, mixed> $claims What the link carries.
	 * @param int                  $ttl    Seconds until it expires.
	 */
	public static function token( array $claims, int $ttl ): string {
		$claims['x'] = time() + $ttl;
		$body        = self::encode( (string) wp_json_encode( $claims ) );
		return $body . '.' . self::encode( hash_hmac( 'sha256', $body, wp_salt( 'auth' ) . '|packout-account', true ) );
	}

	/**
	 * The claims of a link, or null when it was altered or has expired.
	 *
	 * @param string $token Token from the address.
	 * @return array{kind: string, email: string, name: string, team: int, back: string, role: string, subteam: int, subrole: string, by: int, invite_id: int, partner: int}|null
	 */
	public static function read( string $token ): ?array {
		$claims = self::claims( $token );
		if ( ! $claims || ! in_array( $claims['k'] ?? '', array( 'signup', 'invite' ), true ) || ! is_email( (string) ( $claims['e'] ?? '' ) ) ) {
			return null;
		}
		return array(
			'kind'      => 'invite' === ( $claims['k'] ?? '' ) ? 'invite' : 'signup',
			'email'     => strtolower( (string) $claims['e'] ),
			'name'      => sanitize_text_field( (string) ( $claims['n'] ?? '' ) ),
			'team'      => (int) ( $claims['t'] ?? 0 ),
			'back'      => self::local( (string) ( $claims['b'] ?? '' ) ),
			// What an invitation offers beyond membership, and who sent it.
			'role'      => 'colead' === ( $claims['r'] ?? '' ) ? 'colead' : 'member',
			'subteam'   => (int) ( $claims['s'] ?? 0 ),
			'subrole'   => in_array( $claims['sr'] ?? '', array( 'colead', 'lead' ), true ) ? (string) $claims['sr'] : 'member',
			'by'        => (int) ( $claims['by'] ?? 0 ),
			'invite_id' => (int) ( $claims['i'] ?? 0 ),
			// The partner an invitation is to co-lead. Then there is no organization.
			'partner'   => (int) ( $claims['p'] ?? 0 ),
		);
	}

	/**
	 * What a signed link carries, or null when it was altered or has expired.
	 *
	 * @param string $token Token from the address.
	 * @return array<string, mixed>|null
	 */
	public static function claims( string $token ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! hash_equals( self::encode( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) . '|packout-account', true ) ), $parts[1] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The body of a signed link.
		$claims = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );
		return is_array( $claims ) && (int) ( $claims['x'] ?? 0 ) >= time() ? $claims : null;
	}

	/**
	 * Base64 that is safe in an address.
	 *
	 * @param string $raw Bytes.
	 */
	private static function encode( string $raw ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The body of a signed link.
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * The address that finishes a sign-up.
	 *
	 * @param string $email Email address.
	 * @param string $name  Name the person gave.
	 * @param string $back  Address to return to afterward.
	 */
	public static function confirm_link( string $email, string $name, string $back = '' ): string {
		return self::page(
			'signup_page',
			array(
				'pos_confirm' => self::token(
					array(
						'k' => 'signup',
						'e' => strtolower( $email ),
						'n' => $name,
						'b' => self::local( $back ),
					),
					self::SIGNUP_TTL
				),
			)
		);
	}

	/**
	 * Signed token that accepts an invitation to a team.
	 *
	 * @param string               $email         Email address the lead invited.
	 * @param int                  $team_id       Team ID.
	 * @param array<string, mixed> $seat            role, subteam, subrole. Membership alone when empty.
	 * @param int                  $by            The person inviting.
	 * @param int                  $invitation_id Stored invitation id, when known.
	 */
	public static function invite_token( string $email, int $team_id, array $seat = array(), int $by = 0, int $invitation_id = 0 ): string {
		$claims = array(
			'k' => 'invite',
			'e' => strtolower( $email ),
			't' => $team_id,
		);
		if ( 'colead' === ( $seat['role'] ?? '' ) ) {
			$claims['r'] = 'colead';
		}
		if ( (int) ( $seat['subteam'] ?? 0 ) > 0 ) {
			$claims['s']  = (int) $seat['subteam'];
			$claims['sr'] = (string) ( $seat['subrole'] ?? 'member' );
		}
		if ( (int) ( $seat['partner'] ?? 0 ) > 0 ) {
			// An invitation to co-lead a partner names the partner and no organization.
			$claims['p'] = (int) $seat['partner'];
			$claims['t'] = 0;
			$claims['r'] = 'colead';
			unset( $claims['s'], $claims['sr'] );
		}
		if ( $by > 0 ) {
			$claims['by'] = $by;
		}
		if ( $invitation_id > 0 ) {
			$claims['i'] = $invitation_id;
		}
		return self::token( $claims, self::INVITE_TTL );
	}

	/**
	 * The address that accepts an invitation to a team.
	 *
	 * The link says what the invitation offers and who sent it. Both are
	 * signed, and the sender's standing is checked again when it is accepted.
	 *
	 * @param string               $email         Email address the lead invited.
	 * @param int                  $team_id       Team ID.
	 * @param array<string, mixed> $seat            role, subteam, subrole. Membership alone when empty.
	 * @param int                  $by            The person inviting.
	 * @param int                  $invitation_id Stored invitation id, when known.
	 */
	public static function invite_link( string $email, int $team_id, array $seat = array(), int $by = 0, int $invitation_id = 0 ): string {
		return self::page( 'signup_page', array( 'pos_confirm' => self::invite_token( $email, $team_id, $seat, $by, $invitation_id ) ) );
	}

	/**
	 * Send a plain message from the site.
	 *
	 * @param string             $to      Email address.
	 * @param string             $subject Subject, without the site name.
	 * @param array<int, string> $lines   Paragraphs.
	 */
	public static function mail( string $to, string $subject, array $lines ): bool {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$body = implode( "\n\n", array_filter( $lines ) ) . "\n\n-- \n" . $site . "\n" . home_url( '/' ) . "\n";
		return is_email( $to ) && App::kernel()->mailer()->send( $to, '[' . $site . '] ' . $subject, $body );
	}

	/**
	 * Count a request against a limit. False once the limit is passed.
	 *
	 * @param string $bucket Name of the counter.
	 * @param int    $limit  Requests allowed in the period.
	 * @param int    $period Seconds.
	 */
	public static function allow( string $bucket, int $limit, int $period ): bool {
		$key   = 'pos_limit_' . md5( $bucket );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, $period );
		return true;
	}

	/**
	 * Ask for an account: send the confirmation link.
	 *
	 * The result is the same whether or not the address already has an
	 * account, so the form cannot be used to find out who has signed up.
	 *
	 * @param string $name  Name.
	 * @param string $email Email address.
	 * @param string $back  Address to return to afterward.
	 * @return true|\WP_Error
	 */
	public static function request( string $name, string $email, string $back = '' ) {
		$name  = sanitize_text_field( $name );
		$email = strtolower( sanitize_email( $email ) );
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 60 ) {
			return new \WP_Error( 'packout_account_name', __( 'Enter the name your organization will know you by, from 2 to 60 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		// A network can ban email domains from signing up. That list is honored here too.
		if ( ! is_email( $email ) || ( is_multisite() && is_email_address_unsafe( $email ) ) ) {
			return new \WP_Error( 'packout_account_email', __( 'Enter an email address.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! self::allow( 'signup-address-' . $address, self::PER_ADDRESS, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'packout_account_limit', __( 'Too many sign-ups came from this connection. Try again in an hour.', 'packout-core' ), array( 'status' => 429 ) );
		}
		if ( ! self::allow( 'signup-email-' . $email, self::PER_EMAIL, DAY_IN_SECONDS ) ) {
			// The address has been sent enough messages today. Say nothing different.
			return true;
		}

		if ( email_exists( $email ) ) {
			$sent = self::mail(
				$email,
				__( 'You already have an account', 'packout-core' ),
				array(
					__( 'Someone asked to sign up for Pack-Out Spot with this email address. It already has an account, so nothing was changed.', 'packout-core' ),
					__( 'Sign in:', 'packout-core' ) . ' ' . self::signin_url( $back ),
					__( 'Choose a new password:', 'packout-core' ) . ' ' . wp_lostpassword_url(),
					__( 'If this was not you, ignore this message.', 'packout-core' ),
				)
			);
		} else {
			$sent = self::mail(
				$email,
				__( 'Confirm your email address', 'packout-core' ),
				array(
					/* translators: %s: the person's name. */
					sprintf( __( 'Hello %s,', 'packout-core' ), $name ),
					__( 'Follow this link to choose a password and finish signing up for Pack-Out Spot. It is good for three days.', 'packout-core' ),
					self::confirm_link( $email, $name, $back ),
					__( 'If you did not ask for this, ignore this message. No account is made until the link is used.', 'packout-core' ),
				)
			);
		}//end if
		return $sent ? true : new \WP_Error( 'packout_account_mail', __( 'The confirmation message could not be sent. Try again in a few minutes.', 'packout-core' ), array( 'status' => 500 ) );
	}

	/**
	 * Finish a sign-up or accept an invitation as a new person.
	 *
	 * @param string $token    Token from the link.
	 * @param string $password Password the person chose.
	 * @param string $name     Name, for an invitation. A sign-up link carries its own.
	 * @return array{user: int, back: string}|\WP_Error
	 */
	public static function finish( string $token, string $password, string $name = '' ) {
		$claims = self::read( $token );
		if ( ! $claims ) {
			return new \WP_Error( 'packout_account_link', __( 'That link has expired or is incomplete. Ask for a new one.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( email_exists( $claims['email'] ) ) {
			return new \WP_Error( 'packout_account_exists', __( 'That email address already has an account. Sign in to continue.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$name = '' !== $claims['name'] ? $claims['name'] : sanitize_text_field( $name );
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 60 ) {
			return new \WP_Error( 'packout_account_name', __( 'Enter the name your organization will know you by, from 2 to 60 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( strlen( $password ) < self::PASSWORD_LENGTH || strtolower( $password ) === $claims['email'] ) {
			/* translators: %d: number of characters. */
			return new \WP_Error( 'packout_account_password', sprintf( __( 'Choose a password of %d characters or more.', 'packout-core' ), self::PASSWORD_LENGTH ), array( 'status' => 400 ) );
		}
		if ( 'invite' === $claims['kind'] && Invitations::is_revoked( $claims ) ) {
			return new \WP_Error( 'packout_invite_revoked', __( 'That invitation was revoked. Ask for a new one.', 'packout-core' ), array( 'status' => 410 ) );
		}

		$id = self::create( $claims['email'], $name, $password, $claims['kind'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$back = $claims['back'];
		if ( 'invite' === $claims['kind'] && $claims['partner'] > 0 ) {
			if ( Partners::get( $claims['partner'] ) && ! is_wp_error( Partners::seat( $claims['partner'], $id, $claims['by'] ) ) ) {
				Invitations::accept_by_claims( $claims, $id );
				$back = self::workspace_url( array( 'partner' => $claims['partner'] ) );
			}
		} elseif ( 'invite' === $claims['kind'] && Teams::get( $claims['team'] ) ) {
			Teams::admit( $claims['team'], $id );
			Teams::seat( $claims['team'], $id, $claims, $claims['by'] );
			Invitations::accept_by_claims( $claims, $id );
			$back = self::workspace_url( array( 'team' => $claims['team'] ) );
		}
		return array(
			'user' => $id,
			'back' => $back,
		);
	}

	/**
	 * Make an account on this site, as a Reporter.
	 *
	 * @param string $email    Email address nobody on the network uses.
	 * @param string $name     Name the person gave.
	 * @param string $password Password, as the person typed it.
	 * @param string $kind     signup, invite, or claim.
	 * @return int|\WP_Error User ID.
	 */
	public static function create( string $email, string $name, string $password, string $kind ) {
		$id = wp_insert_user(
			array(
				'user_login'   => self::login_for( $email ),
				'user_email'   => $email,
				'user_pass'    => self::as_stored( $password ),
				'display_name' => $name,
				'nickname'     => $name,
				'role'         => 'pos_reporter',
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id = (int) $id;

		/**
		 * A person signed up.
		 *
		 * @param int    $id   User ID.
		 * @param string $kind signup, invite, or claim.
		 */
		do_action( 'packout_account_created', $id, $kind );
		return $id;
	}

	/**
	 * Accept an invitation as a person who already has an account.
	 *
	 * @param string $token   Token from the link.
	 * @param int    $user_id The signed-in person.
	 * @return int|\WP_Error Team ID, or the partner's ID when the invitation is to co-lead a partner.
	 */
	public static function accept( string $token, int $user_id ) {
		$claims = self::read( $token );
		$user   = get_userdata( $user_id );
		$known  = $claims && ( $claims['partner'] > 0 ? Partners::get( $claims['partner'] ) : Teams::get( $claims['team'] ) );
		if ( ! $claims || 'invite' !== $claims['kind'] || ! $known ) {
			return new \WP_Error( 'packout_account_link', __( 'That link has expired or is incomplete. Ask for a new one.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( ! $user instanceof \WP_User || strtolower( $user->user_email ) !== $claims['email'] ) {
			return new \WP_Error( 'packout_account_other', __( 'This invitation was sent to a different email address. Sign in with that account to accept it.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( Invitations::is_revoked( $claims ) ) {
			return new \WP_Error( 'packout_invite_revoked', __( 'That invitation was revoked. Ask for a new one.', 'packout-core' ), array( 'status' => 410 ) );
		}
		self::join_site( $user_id );
		if ( $claims['partner'] > 0 ) {
			$seated = Partners::seat( $claims['partner'], $user_id, $claims['by'] );
			if ( is_wp_error( $seated ) ) {
				return $seated;
			}
			Invitations::accept_by_claims( $claims, $user_id );
			return $claims['partner'];
		}
		if ( ! Teams::includes( $claims['team'], $user_id ) ) {
			Teams::admit( $claims['team'], $user_id );
		}
		Teams::seat( $claims['team'], $user_id, $claims, $claims['by'] );
		Invitations::accept_by_claims( $claims, $user_id );
		return $claims['team'];
	}

	/**
	 * A username nobody has, made from the email address.
	 *
	 * Lower-case letters and digits only, which is what a network accepts.
	 *
	 * @param string $email Email address.
	 */
	private static function login_for( string $email ): string {
		$base = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( (string) strstr( $email, '@', true ) ) );
		$base = substr( $base, 0, 40 );
		if ( strlen( $base ) < 4 ) {
			$base .= wp_rand( 1000, 9999 );
		}
		$login = $base;
		while ( username_exists( $login ) ) {
			$login = $base . wp_rand( 100, 99999 );
		}
		return $login;
	}

	/**
	 * Act on a form from the Sign-up block.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- A visitor has no session to protect. The finish and accept steps are proven by the signed link.
		$do = isset( $_POST['packout_account'] ) ? sanitize_key( wp_unslash( $_POST['packout_account'] ) ) : '';
		if ( '' === $do ) {
			return;
		}
		$text  = static fn( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		$token = $text( 'token' );
		$back  = self::local( isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '' );

		if ( 'ask' === $do ) {
			// A filled trap field is a script. It is told the same thing as a person.
			$result = '' !== $text( 'website' ) ? true : self::request( $text( 'name' ), $text( 'email' ), $back );
			self::go( is_wp_error( $result ) ? self::problem( $result, $back ) : self::page( 'signup_page', array( 'sent' => '1' ) ) );
		}

		if ( 'finish' === $do ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is used as typed.
			$result = self::finish( $token, isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '', $text( 'name' ) );
			if ( is_wp_error( $result ) ) {
				self::go( self::problem( $result, '', $token ) );
			}
			wp_set_current_user( $result['user'] );
			wp_set_auth_cookie( $result['user'], true );
			// A person who came from My teams, or from nowhere, is welcomed there.
			$home = '' === $result['back'] || untrailingslashit( $result['back'] ) === untrailingslashit( self::workspace_url() );
			self::go( $home ? self::workspace_url( array( 'done' => 'welcome' ) ) : $result['back'] );
		}

		if ( 'accept' === $do && is_user_logged_in() ) {
			$result = self::accept( $token, get_current_user_id() );
			$claims = self::read( $token );
			$where  = $claims && $claims['partner'] > 0 ? 'partner' : 'team';
			self::go(
				is_wp_error( $result ) ? self::problem( $result, '', $token ) : self::workspace_url(
					array(
						$where => $result,
						'done' => 'joined',
					)
				)
			);
		}

		if ( 'join_site' === $do && is_user_logged_in() && isset( $_POST['_posnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_posnonce'] ) ), 'packout_join_site' ) ) {
			self::join_site( get_current_user_id() );
			self::go( '' !== $back ? $back : self::workspace_url( array( 'done' => 'welcome' ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * The sign-up page with a message about what went wrong.
	 *
	 * @param \WP_Error $error What went wrong.
	 * @param string    $back  Address to return to afterward.
	 * @param string    $token Token to keep in the address.
	 */
	private static function problem( \WP_Error $error, string $back = '', string $token = '' ): string {
		$args = array( 'problem' => (string) $error->get_error_code() );
		if ( '' !== $back ) {
			$args['back'] = $back;
		}
		if ( '' !== $token ) {
			$args['pos_confirm'] = $token;
		}
		return self::page( 'signup_page', $args );
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url Address on this site.
	 */
	public static function go( string $url ): void {
		nocache_headers();
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Where a person lands after signing in on the WordPress sign-in screen.
	 *
	 * DO NOT change this to send everyone to the front end. That locks an
	 * administrator out of the dashboard: a signed-out request for a
	 * dashboard address arrives here, and sending it to My organizations
	 * means the dashboard is never reached by asking for it. POS-QS-001
	 * section 9.8 and tests/phase-4.php hold this rule.
	 *
	 * The site has two doors. The Sign in page is the site's door, and
	 * landing() keeps a person who uses it on the site. The WordPress sign-in
	 * screen is the dashboard's door: a person reaches it by opening a
	 * dashboard address or by typing its own address. A person who has
	 * dashboard screens is returned to the dashboard address WordPress was
	 * asked for. Everyone else lands on the site.
	 *
	 * @param mixed $redirect_to Where WordPress would send them.
	 * @param mixed $requested   Where the sign-in link asked to go.
	 * @param mixed $user        The person, or an error.
	 * @return mixed
	 */
	public static function after_sign_in( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof \WP_User ) {
			return $redirect_to;
		}
		$pick = is_string( $redirect_to ) ? self::local( $redirect_to ) : '';
		if ( '' !== $pick && self::is_dashboard( $pick ) && self::has_dashboard( $user->ID ) ) {
			return self::dashboard_address( $pick, $user->ID );
		}
		// Prefer the address the person asked for. Fall back to WordPress's pick only when
		// that pick is on the front end.
		$asked = is_string( $requested ) ? $requested : '';
		if ( '' === $asked && '' !== $pick && ! self::is_dashboard( $pick ) ) {
			$asked = $pick;
		}
		return self::landing( $asked, $user->ID );
	}

	/**
	 * A dashboard address a person can open.
	 *
	 * WordPress sends a person who cannot edit posts from the dashboard's
	 * first screen to their profile. A coordinator is such a person, so the
	 * first screen becomes the Pack-Out Spot screen they work on: the
	 * Platform overview for a coordinator, and for a lead the overview of
	 * the one organization or partner they lead, or the list of them.
	 *
	 * @param string $url     A dashboard address on this site.
	 * @param int    $user_id The person.
	 */
	private static function dashboard_address( string $url, int $user_id ): string {
		$path  = trailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$admin = trailingslashit( (string) wp_parse_url( admin_url(), PHP_URL_PATH ) );
		$first = $path === $admin && '' === (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! $first || user_can( $user_id, 'edit_posts' ) ) {
			return $url;
		}
		return user_can( $user_id, Capabilities::MODERATE ) ? admin_url( 'admin.php?page=packout-spot' ) : Overview_Page::home( $user_id );
	}

	/**
	 * Where a person goes after signing in on the site's Sign in page.
	 *
	 * The Sign in page never opens the dashboard, for anyone. The person
	 * lands on the front-end page they were on their way to, or on My
	 * organizations when that page was the dashboard or nothing. The
	 * dashboard has its own door: see after_sign_in().
	 *
	 * @param string $asked   Address the sign-in was asked to return to. May be empty.
	 * @param int    $user_id The person. Kept for callers; the destination does not depend on who they are.
	 */
	public static function landing( string $asked, int $user_id ): string {
		unset( $user_id );
		$asked = self::local( $asked );
		return '' === $asked || self::is_dashboard( $asked ) ? self::workspace_url() : $asked;
	}

	/**
	 * Whether an address is on the WordPress dashboard of this site.
	 *
	 * @param string $url Address on this site.
	 */
	public static function is_dashboard( string $url ): bool {
		$path  = trailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$admin = trailingslashit( (string) wp_parse_url( admin_url(), PHP_URL_PATH ) );
		return '' !== $admin && str_starts_with( $path, $admin );
	}

	/**
	 * Hide the admin bar from a person with no dashboard screens.
	 *
	 * @param mixed $show Whether WordPress would show it.
	 * @return mixed
	 */
	public static function admin_bar( $show ) {
		return $show && is_user_logged_in() && ! self::has_dashboard( get_current_user_id() ) ? false : $show;
	}

	/**
	 * Whether a person has something to open in the dashboard.
	 *
	 * @param int $user_id User ID.
	 */
	public static function has_dashboard( int $user_id ): bool {
		return user_can( $user_id, 'edit_posts' ) || user_can( $user_id, Capabilities::MODERATE ) || user_can( $user_id, Capabilities::TEAM ) || user_can( $user_id, Capabilities::PARTNER );
	}

	/**
	 * The Sign-up block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		unset( $attributes );
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// The editor's preview. The form itself depends on who is looking.
			return '<div ' . Ui::wrapper( 'pos-account pos-ws' ) . '>' . self::notice( 'info', __( 'The sign-up form appears here for visitors: a name and an email address, then a link to choose a password.', 'packout-core' ) ) . '</div>';
		}
		wp_enqueue_style( 'packout-workspace' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the address to choose what to show.
		$token   = isset( $_GET['pos_confirm'] ) ? sanitize_text_field( wp_unslash( $_GET['pos_confirm'] ) ) : '';
		$problem = isset( $_GET['problem'] ) ? sanitize_key( wp_unslash( $_GET['problem'] ) ) : '';
		$sent    = isset( $_GET['sent'] );
		$back    = self::local( isset( $_GET['back'] ) ? esc_url_raw( wp_unslash( $_GET['back'] ) ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$claims = '' !== $token ? self::read( $token ) : null;
		$html   = '<div ' . Ui::wrapper( 'pos-account pos-ws' ) . '>';
		if ( '' !== $problem ) {
			$html .= self::notice( 'error', self::problems()[ $problem ] ?? __( 'The account could not be created. Try again.', 'packout-core' ) );
		}

		// What the invitation is to: an organization, or a partner to co-lead.
		$for_partner = $claims && 'invite' === $claims['kind'] && $claims['partner'] > 0;
		$team        = null;
		if ( $claims && 'invite' === $claims['kind'] ) {
			$team = $for_partner ? Partners::get( $claims['partner'] ) : Teams::get( $claims['team'] );
		}
		if ( $claims && 'invite' === $claims['kind'] && ! $team ) {
			$claims = null;
		}
		/* translators: %s: organization or partner name. */
		$join = $team ? sprintf( $for_partner ? __( 'Co-lead %s', 'packout-core' ) : __( 'Join %s', 'packout-core' ), $team->post_title ) : '';

		if ( '' !== $token && ! $claims && 'packout_account_link' !== $problem ) {
			$html .= self::notice( 'warning', __( 'That link has expired or is incomplete. Ask for a new one below.', 'packout-core' ) );
		}

		if ( $claims && $team && is_user_logged_in() ) {
			// An invitation, opened by a person who already has an account.
			$user = wp_get_current_user();
			if ( strtolower( $user->user_email ) !== $claims['email'] ) {
				return $html . self::notice( 'warning', __( 'This invitation was sent to a different email address. Sign out, then sign in with that account to accept it.', 'packout-core' ) ) . '<p><a class="pos-ws__button" href="' . esc_url( wp_logout_url( self::current_url() ) ) . '">' . esc_html__( 'Sign out', 'packout-core' ) . '</a></p></div>';
			}
			$html .= '<h2>' . esc_html( $join ) . '</h2>' . self::offer( $claims );
			$html .= '<form method="post" class="pos-ws__form"><input type="hidden" name="packout_account" value="accept" /><input type="hidden" name="token" value="' . esc_attr( $token ) . '" />';
			return $html . '<button class="pos-ws__button pos-ws__button--primary">' . esc_html( $for_partner ? __( 'Accept and co-lead the partner', 'packout-core' ) : __( 'Join the organization', 'packout-core' ) ) . '</button></form></div>';
		}

		if ( is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$html .= '<p>' . esc_html(
				/* translators: %s: the person's name. */
				sprintf( __( 'You are signed in as %s.', 'packout-core' ), $user->display_name )
			) . '</p>';
			return $html . '<p><a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( self::workspace_url() ) . '">' . esc_html__( 'Go to My organizations', 'packout-core' ) . '</a></p></div>';
		}

		if ( $claims && email_exists( $claims['email'] ) ) {
			$html .= self::notice( 'info', $team ? __( 'This email address already has an account. Sign in to accept the invitation.', 'packout-core' ) : __( 'This email address already has an account. Sign in to continue.', 'packout-core' ) );
			return $html . '<p><a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( self::signin_url( $team ? self::current_url() : $claims['back'] ) ) . '">' . esc_html__( 'Sign in', 'packout-core' ) . '</a></p></div>';
		}

		if ( $claims ) {
			// The address is confirmed. Choose a password.
			$html .= '<h2>' . esc_html( $team ? $join : __( 'Choose a password', 'packout-core' ) ) . '</h2>' . ( $team ? self::offer( $claims ) : '' );
			$html .= '<p>' . esc_html(
				/* translators: %s: email address. */
				sprintf( __( 'Your account will use %s.', 'packout-core' ), $claims['email'] )
			) . '</p>';
			$html .= '<form method="post" class="pos-ws__form"><input type="hidden" name="packout_account" value="finish" /><input type="hidden" name="token" value="' . esc_attr( $token ) . '" />';
			if ( '' === $claims['name'] ) {
				$html .= '<p class="pos-ws__field"><label for="pos-account-name">' . esc_html__( 'Your name', 'packout-core' ) . '</label><input id="pos-account-name" type="text" name="name" required minlength="2" maxlength="60" autocomplete="name" /><span class="pos-ws__help">' . esc_html__( 'Your organization sees this name. It is not shown on public pages.', 'packout-core' ) . '</span></p>';
			}
			$html .= '<p class="pos-ws__field"><label for="pos-account-password">' . esc_html__( 'Password', 'packout-core' ) . '</label><input id="pos-account-password" type="password" name="password" required minlength="' . esc_attr( (string) self::PASSWORD_LENGTH ) . '" autocomplete="new-password" /><span class="pos-ws__help">' . esc_html(
				/* translators: %d: number of characters. */
				sprintf( __( '%d characters or more.', 'packout-core' ), self::PASSWORD_LENGTH )
			) . '</span></p>';
			$create = $for_partner ? __( 'Create my account and accept', 'packout-core' ) : __( 'Create my account and join', 'packout-core' );
			return $html . '<button class="pos-ws__button pos-ws__button--primary">' . esc_html( $team ? $create : __( 'Create my account', 'packout-core' ) ) . '</button></form></div>';
		}

		if ( $sent ) {
			$html .= self::notice( 'success', __( 'Check your email. We sent a link to finish signing up. It is good for three days.', 'packout-core' ) );
			return $html . '<p>' . esc_html__( 'Nothing arrived? Look in your spam folder, then ask again.', 'packout-core' ) . ' <a href="' . esc_url( self::signup_url() ) . '">' . esc_html__( 'Start over', 'packout-core' ) . '</a></p></div>';
		}

		$html .= '<form method="post" class="pos-ws__form"><input type="hidden" name="packout_account" value="ask" /><input type="hidden" name="back" value="' . esc_attr( $back ) . '" />';
		$html .= '<p class="pos-ws__field"><label for="pos-account-name">' . esc_html__( 'Your name', 'packout-core' ) . '</label><input id="pos-account-name" type="text" name="name" required minlength="2" maxlength="60" autocomplete="name" /><span class="pos-ws__help">' . esc_html__( 'Your organization sees this name. It is not shown on public pages.', 'packout-core' ) . '</span></p>';
		$html .= '<p class="pos-ws__field"><label for="pos-account-email">' . esc_html__( 'Email address', 'packout-core' ) . '</label><input id="pos-account-email" type="email" name="email" required autocomplete="email" /><span class="pos-ws__help">' . esc_html__( 'We send one message with a link to choose your password. After that we use it to sign you in and for notices about your organizations.', 'packout-core' ) . '</span></p>';
		$html .= '<p class="pos-ws__trap" aria-hidden="true"><label for="pos-account-website">' . esc_html__( 'Leave this empty', 'packout-core' ) . '</label><input id="pos-account-website" type="text" name="website" tabindex="-1" autocomplete="off" /></p>';
		$html .= '<button class="pos-ws__button pos-ws__button--primary">' . esc_html__( 'Send me the link', 'packout-core' ) . '</button></form>';
		return $html . '<p class="pos-ws__aside">' . esc_html__( 'Already have an account?', 'packout-core' ) . ' <a href="' . esc_url( self::signin_url( $back ) ) . '">' . esc_html__( 'Sign in', 'packout-core' ) . '</a></p></div>';
	}

	/**
	 * What an invitation offers beyond membership, as a paragraph, or nothing.
	 *
	 * @param array<string, mixed> $claims From read().
	 */
	private static function offer( array $claims ): string {
		$lines = array();
		if ( $claims['partner'] > 0 ) {
			return '<p>' . esc_html__( 'You are invited as a co-lead of the partner. A co-lead manages its profile, its programs, and its organizations with its lead.', 'packout-core' ) . '</p>';
		}
		if ( 'colead' === $claims['role'] ) {
			$lines[] = __( 'You are invited as a co-lead of the organization.', 'packout-core' );
		}
		$subteam = $claims['subteam'] > 0 ? Subteams::get( $claims['team'], $claims['subteam'] ) : null;
		if ( $subteam ) {
			$places  = array(
				/* translators: %s: team name. */
				'member' => __( 'You would be in its team “%s”.', 'packout-core' ),
				/* translators: %s: team name. */
				'colead' => __( 'You would co-lead its team “%s”.', 'packout-core' ),
				/* translators: %s: team name. */
				'lead'   => __( 'You would lead its team “%s”.', 'packout-core' ),
			);
			$lines[] = sprintf( $places[ $claims['subrole'] ], $subteam['name'] );
		}
		return $lines ? '<p>' . esc_html( implode( ' ', $lines ) ) . '</p>' : '';
	}

	/**
	 * The address of this request.
	 */
	public static function current_url(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned as a whole address below.
		$home = wp_parse_url( home_url() );
		return esc_url_raw( ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $path );
	}

	/**
	 * What the Sign-up block says for each thing that can go wrong.
	 *
	 * The address carries the code, never the sentence, so a link cannot put
	 * words on the page.
	 *
	 * @return array<string, string>
	 */
	private static function problems(): array {
		return array(
			'packout_account_name'     => __( 'Enter the name your organization will know you by, from 2 to 60 characters.', 'packout-core' ),
			'packout_account_email'    => __( 'Enter an email address.', 'packout-core' ),
			'packout_account_limit'    => __( 'Too many sign-ups came from this connection. Try again in an hour.', 'packout-core' ),
			'packout_account_mail'     => __( 'The confirmation message could not be sent. Try again in a few minutes.', 'packout-core' ),
			'packout_account_link'     => __( 'That link has expired or is incomplete. Ask for a new one.', 'packout-core' ),
			'packout_account_exists'   => __( 'That email address already has an account. Sign in to continue.', 'packout-core' ),
			'packout_account_other'    => __( 'This invitation was sent to a different email address. Sign in with that account to accept it.', 'packout-core' ),
			/* translators: %d: number of characters. */
			'packout_account_password' => sprintf( __( 'Choose a password of %d characters or more.', 'packout-core' ), self::PASSWORD_LENGTH ),
		);
	}

	/**
	 * A notice on a public page.
	 *
	 * @param string $intent success, info, warning, or error.
	 * @param string $text   What to say.
	 */
	public static function notice( string $intent, string $text ): string {
		return '<div class="pos-ws__notice pos-ws__notice--' . esc_attr( $intent ) . '" role="' . ( 'error' === $intent ? 'alert' : 'status' ) . '">' . esc_html( $text ) . '</div>';
	}
}
