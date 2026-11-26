<?php
/**
 * Sign in: the site's own page for signing in, signing out, and choosing a new password.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A person signs in on a page of the site and stays on the site.
 *
 * The page holds the Sign in block and is named in settings. Links that would
 * lead to the WordPress sign-in screen lead to it instead, signing out returns
 * to it, and a forgotten password is replaced on it. Signing in on this page
 * never opens the dashboard: Accounts::landing() chooses where the person goes.
 *
 * The WordPress sign-in screen stays where it is and keeps working. It is the
 * way in when this page is not set up, and when a signed-in session has to be
 * proven again for the editor. It is also the dashboard's door: a signed-out
 * person who opens a dashboard address signs in there, and one who has
 * dashboard screens is returned to that address. See Accounts::after_sign_in().
 *
 * The accounts are WordPress accounts and the checks are WordPress's own:
 * wp_signon() for the password, and its reset keys for a new one.
 */
class Sign_In {

	/**
	 * Wrong passwords accepted in a quarter of an hour, from one address and for one account.
	 */
	private const PER_ADDRESS = 20;
	private const PER_ACCOUNT = 10;
	private const WINDOW      = 15 * MINUTE_IN_SECONDS;

	/**
	 * Messages with a link to choose a new password: asked for from one address in an hour, and sent to one person in a day.
	 */
	private const RESETS_PER_ADDRESS = 6;
	private const RESETS_PER_PERSON  = 3;

	/**
	 * Register the hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'handle' ), 30 );
		add_action( 'template_redirect', array( self::class, 'headers' ) );
		add_filter( 'login_url', array( self::class, 'login_url' ), 10, 3 );
		add_filter( 'lostpassword_url', array( self::class, 'lost_url' ) );
		add_filter( 'logout_redirect', array( self::class, 'after_sign_out' ), 10, 2 );
		add_action( 'login_init', array( self::class, 'signed_out' ) );
		add_filter( 'wp_nav_menu_objects', array( self::class, 'menu' ) );
	}

	/**
	 * Whether the site has a Sign in page.
	 */
	public static function open(): bool {
		$id = (int) Settings::get( 'signin_page' );
		return $id > 0 && 'publish' === get_post_status( $id );
	}

	/**
	 * The Sign in page.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		return Accounts::page( 'signin_page', $args );
	}

	/**
	 * The address that signs a person out and returns them to the Sign in page.
	 */
	public static function out_url(): string {
		return wp_logout_url( self::open() ? self::url( array( 'done' => 'out' ) ) : home_url( '/' ) );
	}

	/**
	 * Point links to the WordPress sign-in screen at the Sign in page.
	 *
	 * Two are left alone: a signed-in person (the editor's "session ran out"
	 * box needs the WordPress address), and $force_reauth (the same box asks
	 * for a fresh sign-in). A dashboard address is not kept as a return
	 * address: signing in on the Sign in page stays on the front end.
	 *
	 * @param mixed $url          The WordPress sign-in address.
	 * @param mixed $redirect     Where to go afterward.
	 * @param mixed $force_reauth Whether the person must sign in again.
	 * @return mixed
	 */
	public static function login_url( $url, $redirect = '', $force_reauth = false ) {
		if ( $force_reauth || is_user_logged_in() || ! self::open() ) {
			return $url;
		}
		$back = Accounts::local( is_string( $redirect ) ? $redirect : '' );
		if ( '' !== $back && Accounts::is_dashboard( $back ) ) {
			$back = '';
		}
		return self::url( '' !== $back ? array( 'back' => $back ) : array() );
	}

	/**
	 * Point "Lost your password?" at the Sign in page.
	 *
	 * On a network the WordPress screen for this belongs to the main site.
	 *
	 * @param mixed $url The WordPress address.
	 * @return mixed
	 */
	public static function lost_url( $url ) {
		return self::open() ? self::url( array( 'view' => 'forgot' ) ) : $url;
	}

	/**
	 * Return a person who signs out to the Sign in page, unless the link named somewhere else.
	 *
	 * @param mixed $redirect_to Where WordPress would send them.
	 * @param mixed $requested   Where the sign-out link asked to go.
	 * @return mixed
	 */
	public static function after_sign_out( $redirect_to, $requested = '' ) {
		return self::open() && ( ! is_string( $requested ) || '' === $requested ) ? self::url( array( 'done' => 'out' ) ) : $redirect_to;
	}

	/**
	 * Send a person who arrives at the WordPress "you are signed out" screen to the Sign in page.
	 *
	 * On a network with mapped domains, the domain mapping plugin finishes a
	 * sign-out on the network's own domain and returns the person to that
	 * screen, without asking where they were going.
	 */
	public static function signed_out(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads where to send a signed-out visitor. Changes nothing.
		$said   = isset( $_GET['loggedout'] ) ? sanitize_key( wp_unslash( $_GET['loggedout'] ) ) : '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'true' !== $said || 'GET' !== $method || is_user_logged_in() || ! self::open() ) {
			return;
		}
		wp_safe_redirect( self::url( array( 'done' => 'out' ) ) );
		exit;
	}

	/**
	 * Show a menu for the person looking at it.
	 *
	 * A visitor sees Sign in and Sign up, and none of the pages that are about
	 * one person. A signed-in person sees My account in place of Sign in, with
	 * its parts under it, and no Sign up. The Sign in page reads "Sign out"
	 * for them and signs them out. A menu that has Sign in both beside My
	 * account and under it keeps the one under it, so Sign out is the last
	 * item under My account.
	 *
	 * @param mixed $items Menu items, parents before their children.
	 * @return mixed
	 */
	public static function menu( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		$signin  = (int) Settings::get( 'signin_page' );
		$signup  = (int) Settings::get( 'signup_page' );
		$account = (int) Settings::get( 'account_page' );
		$private = array_filter( array_merge( array( $account, (int) Settings::get( 'workspace_page' ) ), array_values( Profile::pages() ) ) );
		$page_of = static fn( $item ): int => $item instanceof \WP_Post && 'page' === ( $item->object ?? '' ) ? (int) ( $item->object_id ?? 0 ) : 0;

		if ( ! is_user_logged_in() ) {
			// An item goes, and so does everything under it.
			$gone = array();
			foreach ( $items as $key => $item ) {
				if ( ! $item instanceof \WP_Post ) {
					continue;
				}
				if ( in_array( $page_of( $item ), $private, true ) || in_array( (int) $item->menu_item_parent, $gone, true ) ) {
					$gone[] = (int) $item->ID;
					unset( $items[ $key ] );
				}
			}
			return $items;
		}

		$has_account = false;
		$nested      = false;
		foreach ( $items as $item ) {
			$page        = $page_of( $item );
			$has_account = $has_account || ( $account > 0 && $page === $account );
			$nested      = $nested || ( $signin > 0 && $page === $signin && (int) $item->menu_item_parent > 0 );
		}
		foreach ( $items as $key => $item ) {
			$page = $page_of( $item );
			if ( $page <= 0 ) {
				continue;
			}
			if ( $page === $signup || ( $page === $signin && $has_account && $nested && 0 === (int) $item->menu_item_parent ) ) {
				unset( $items[ $key ] );
				continue;
			}
			if ( $page === $signin ) {
				$item->title   = __( 'Sign out', 'packout-core' );
				$item->url     = html_entity_decode( self::out_url(), ENT_QUOTES );
				$item->classes = array_values( array_diff( (array) $item->classes, array( 'current-menu-item', 'current_page_item' ) ) );
				$item->current = false;
			}
		}
		return $items;
	}

	/**
	 * Keep a page that carries a reset key out of caches and out of referrers.
	 */
	public static function headers(): void {
		$page = (int) Settings::get( 'signin_page' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the address to choose the headers.
		if ( $page > 0 && is_page( $page ) && isset( $_GET['key'] ) && ! headers_sent() ) {
			nocache_headers();
			header( 'Referrer-Policy: same-origin' );
		}
	}

	/**
	 * The address of this request, for counting.
	 */
	private static function address(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Whether a counter has reached its limit.
	 *
	 * @param string $bucket Name of the counter.
	 * @param int    $limit  The limit.
	 */
	private static function over( string $bucket, int $limit ): bool {
		return (int) get_transient( 'pos_limit_' . md5( $bucket ) ) >= $limit;
	}

	/**
	 * Count a wrong password.
	 *
	 * @param string $bucket Name of the counter.
	 */
	private static function strike( string $bucket ): void {
		$key = 'pos_limit_' . md5( $bucket );
		set_transient( $key, (int) get_transient( $key ) + 1, self::WINDOW );
	}

	/**
	 * Sign a person in.
	 *
	 * The answer to a wrong password is the same whether or not the account
	 * exists.
	 *
	 * @param string $login    Email address or username.
	 * @param string $password Password, as typed.
	 * @param bool   $remember Whether to stay signed in on this device.
	 * @return \WP_User|\WP_Error
	 */
	public static function enter( string $login, string $password, bool $remember ) {
		$login   = trim( $login );
		$wrong   = new \WP_Error( 'packout_signin_wrong', __( 'That email address and password do not match an account.', 'packout-core' ), array( 'status' => 403 ) );
		$buckets = array(
			'signin-address-' . self::address()      => self::PER_ADDRESS,
			'signin-account-' . strtolower( $login ) => self::PER_ACCOUNT,
		);
		foreach ( $buckets as $bucket => $limit ) {
			if ( self::over( $bucket, $limit ) ) {
				return new \WP_Error( 'packout_signin_wait', __( 'Too many wrong passwords. Wait a quarter of an hour, or choose a new password.', 'packout-core' ), array( 'status' => 429 ) );
			}
		}
		if ( '' === $login || '' === $password ) {
			return $wrong;
		}

		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => Accounts::as_stored( $password ),
				'remember'      => $remember,
			)
		);
		if ( ! $user instanceof \WP_User ) {
			foreach ( array_keys( $buckets ) as $bucket ) {
				self::strike( $bucket );
			}
			return $wrong;
		}
		return $user;
	}

	/**
	 * Send the link that chooses a new password.
	 *
	 * The answer is the same whether or not the address has an account.
	 *
	 * @param string $login Email address or username.
	 * @return true|\WP_Error
	 */
	public static function forgot( string $login ) {
		$login = trim( $login );
		if ( '' === $login ) {
			return new \WP_Error( 'packout_signin_email', __( 'Enter your email address.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( ! Accounts::allow( 'reset-address-' . self::address(), self::RESETS_PER_ADDRESS, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'packout_signin_limit', __( 'Too many requests came from this connection. Try again in an hour.', 'packout-core' ), array( 'status' => 429 ) );
		}

		$user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		if ( ! $user instanceof \WP_User || ! Accounts::allow( 'reset-person-' . $user->ID, self::RESETS_PER_PERSON, DAY_IN_SECONDS ) ) {
			return true;
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			// Password resets are turned off for this person. Say nothing different.
			return true;
		}
		Accounts::mail(
			$user->user_email,
			__( 'Choose a new password', 'packout-core' ),
			array(
				__( 'Someone asked to choose a new password for the Pack-Out Spot account that uses this email address.', 'packout-core' ),
				__( 'Follow this link to choose one. It is good for one day and works once.', 'packout-core' ),
				self::url(
					array(
						'view'  => 'reset',
						'key'   => $key,
						'login' => $user->user_login,
					)
				),
				__( 'If this was not you, ignore this message. Your password stays as it is.', 'packout-core' ),
			)
		);
		return true;
	}

	/**
	 * Choose a new password with the key from the message.
	 *
	 * @param string $key      Key from the link.
	 * @param string $login    Username from the link.
	 * @param string $password The new password, as typed.
	 * @return true|\WP_Error
	 */
	public static function reset( string $key, string $login, string $password ) {
		$user = '' !== $key && '' !== $login ? check_password_reset_key( $key, $login ) : null;
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'packout_signin_link', __( 'That link has expired or was already used. Ask for a new one.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( strlen( $password ) < Accounts::PASSWORD_LENGTH || strtolower( $password ) === strtolower( $user->user_email ) ) {
			/* translators: %d: number of characters. */
			return new \WP_Error( 'packout_signin_password', sprintf( __( 'Choose a password of %d characters or more.', 'packout-core' ), Accounts::PASSWORD_LENGTH ), array( 'status' => 400 ) );
		}
		// A plugin that sets password rules for the WordPress screen is asked here too.
		$errors = new \WP_Error();
		do_action( 'validate_password_reset', $errors, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- A WordPress hook.
		if ( $errors->has_errors() ) {
			return new \WP_Error( 'packout_signin_refused', __( 'That password was not accepted. Choose a different one.', 'packout-core' ), array( 'status' => 400 ) );
		}
		reset_password( $user, Accounts::as_stored( $password ) );
		return true;
	}

	/**
	 * Handle a form sent from the Sign in block.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- A visitor has no session to protect. A new password is proven by the key from the message.
		$do = isset( $_POST['packout_signin'] ) ? sanitize_key( wp_unslash( $_POST['packout_signin'] ) ) : '';
		if ( '' === $do ) {
			return;
		}
		$text = static fn( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is used as typed.
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$back     = Accounts::local( isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '' );
		$return   = '' !== $back ? array( 'back' => $back ) : array();

		if ( 'in' === $do ) {
			$user = self::enter( $text( 'login' ), $password, isset( $_POST['remember'] ) );
			Accounts::go( is_wp_error( $user ) ? self::url( array( 'problem' => $user->get_error_code() ) + $return ) : Accounts::landing( $back, $user->ID ) );
		}

		if ( 'forgot' === $do ) {
			$sent = self::forgot( $text( 'login' ) );
			Accounts::go(
				self::url(
					is_wp_error( $sent ) ? array(
						'view'    => 'forgot',
						'problem' => $sent->get_error_code(),
					) : array( 'done' => 'sent' )
				)
			);
		}

		if ( 'reset' === $do ) {
			$link = array(
				'view'  => 'reset',
				'key'   => $text( 'key' ),
				'login' => $text( 'login' ),
			);
			$done = self::reset( $link['key'], $link['login'], $password );
			if ( is_wp_error( $done ) ) {
				$code = $done->get_error_code();
				Accounts::go(
					self::url(
						'packout_signin_link' === $code ? array(
							'view'    => 'forgot',
							'problem' => $code,
						) : $link + array( 'problem' => $code )
					)
				);
			}
			Accounts::go( self::url( array( 'done' => 'reset' ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * What the block says for each thing that can go wrong.
	 *
	 * The address carries the code, never the sentence, so a link cannot put
	 * words on the page.
	 *
	 * @return array<string, string>
	 */
	private static function problems(): array {
		return array(
			'packout_signin_wrong'    => __( 'That email address and password do not match an account.', 'packout-core' ),
			'packout_signin_wait'     => __( 'Too many wrong passwords. Wait a quarter of an hour, or choose a new password.', 'packout-core' ),
			'packout_signin_email'    => __( 'Enter your email address.', 'packout-core' ),
			'packout_signin_limit'    => __( 'Too many requests came from this connection. Try again in an hour.', 'packout-core' ),
			'packout_signin_link'     => __( 'That link has expired or was already used. Ask for a new one.', 'packout-core' ),
			/* translators: %d: number of characters. */
			'packout_signin_password' => sprintf( __( 'Choose a password of %d characters or more.', 'packout-core' ), Accounts::PASSWORD_LENGTH ),
			'packout_signin_refused'  => __( 'That password was not accepted. Choose a different one.', 'packout-core' ),
		);
	}

	/**
	 * The Sign in block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		unset( $attributes );
		$open = '<div ' . Ui::wrapper( 'pos-account pos-signin pos-ws' ) . '>';
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// The editor's preview. The form itself depends on who is looking.
			return $open . Accounts::notice( 'info', __( 'The sign-in form appears here for visitors: an email address and a password, with a link to choose a new password.', 'packout-core' ) ) . '</div>';
		}
		wp_enqueue_style( 'packout-workspace' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the address to choose what to show.
		$read    = static fn( string $key ): string => isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		$view    = sanitize_key( $read( 'view' ) );
		$problem = sanitize_key( $read( 'problem' ) );
		$done    = sanitize_key( $read( 'done' ) );
		$key     = $read( 'key' );
		$login   = $read( 'login' );
		$back    = Accounts::local( isset( $_GET['back'] ) ? esc_url_raw( wp_unslash( $_GET['back'] ) ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$html = $open;
		if ( '' !== $problem ) {
			$html .= Accounts::notice( 'error', self::problems()[ $problem ] ?? __( 'That did not work. Try again.', 'packout-core' ) );
		}
		if ( 'reset' === $done ) {
			$html .= Accounts::notice( 'success', __( 'Your password is changed. Sign in with the new one.', 'packout-core' ) );
		} elseif ( 'sent' === $done ) {
			$html .= Accounts::notice( 'success', __( 'If that address has an account, a message with a link to choose a new password is on its way. The link is good for one day.', 'packout-core' ) );
		}

		// A signed-in person who asked for a password link from My account still gets the form.
		if ( is_user_logged_in() && ! ( 'reset' === $view && check_password_reset_key( $key, $login ) instanceof \WP_User ) ) {
			$user  = wp_get_current_user();
			$html .= '<p>' . esc_html(
				/* translators: %s: the person's name. */
				sprintf( __( 'You are signed in as %s.', 'packout-core' ), $user->display_name )
			) . '</p><p class="pos-ws__actions">';
			if ( '' !== $back ) {
				$html .= '<a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( Accounts::landing( $back, $user->ID ) ) . '">' . esc_html__( 'Continue', 'packout-core' ) . '</a> ';
			}
			$html .= '<a class="pos-ws__button' . ( '' === $back ? ' pos-ws__button--primary' : '' ) . '" href="' . esc_url( Accounts::workspace_url() ) . '">' . esc_html__( 'Go to My organizations', 'packout-core' ) . '</a> ';
			return $html . '<a class="pos-ws__button" href="' . esc_url( self::out_url() ) . '">' . esc_html__( 'Sign out', 'packout-core' ) . '</a></p></div>';
		}
		if ( 'out' === $done ) {
			$html .= Accounts::notice( 'success', __( 'You are signed out.', 'packout-core' ) );
		}

		$password = '<p class="pos-ws__field"><label for="pos-signin-password">%1$s</label><input id="pos-signin-password" type="password" name="password" required autocomplete="%2$s"%3$s />%4$s</p>';

		if ( 'reset' === $view && ! check_password_reset_key( $key, $login ) instanceof \WP_User ) {
			// The link is old or was used. Offer a new one.
			$view = 'forgot';
			if ( 'packout_signin_link' !== $problem ) {
				$html .= Accounts::notice( 'warning', __( 'That link has expired or was already used. Ask for a new one below.', 'packout-core' ) );
			}
		}

		if ( 'reset' === $view ) {
			$html .= '<h2>' . esc_html__( 'Choose a new password', 'packout-core' ) . '</h2>';
			$html .= '<form method="post" action="' . esc_url( self::url() ) . '" class="pos-ws__form"><input type="hidden" name="packout_signin" value="reset" /><input type="hidden" name="key" value="' . esc_attr( $key ) . '" /><input type="hidden" name="login" value="' . esc_attr( $login ) . '" />';
			$html .= sprintf(
				$password,
				esc_html__( 'New password', 'packout-core' ),
				'new-password',
				' minlength="' . esc_attr( (string) Accounts::PASSWORD_LENGTH ) . '"',
				'<span class="pos-ws__help">' . esc_html(
					/* translators: %d: number of characters. */
					sprintf( __( '%d characters or more.', 'packout-core' ), Accounts::PASSWORD_LENGTH )
				) . '</span>'
			);
			return $html . '<button class="pos-ws__button pos-ws__button--primary">' . esc_html__( 'Save the password', 'packout-core' ) . '</button></form></div>';
		}

		if ( 'forgot' === $view ) {
			$html .= '<h2>' . esc_html__( 'Choose a new password', 'packout-core' ) . '</h2>';
			$html .= '<form method="post" action="' . esc_url( self::url() ) . '" class="pos-ws__form"><input type="hidden" name="packout_signin" value="forgot" />';
			$html .= '<p class="pos-ws__field"><label for="pos-signin-login">' . esc_html__( 'Email address', 'packout-core' ) . '</label><input id="pos-signin-login" type="text" name="login" required autocomplete="username" /><span class="pos-ws__help">' . esc_html__( 'We send a link to choose a new password. It is good for one day.', 'packout-core' ) . '</span></p>';
			$html .= '<button class="pos-ws__button pos-ws__button--primary">' . esc_html__( 'Send me the link', 'packout-core' ) . '</button></form>';
			return $html . '<p class="pos-ws__aside"><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to sign in', 'packout-core' ) . '</a></p></div>';
		}

		$html .= '<form method="post" action="' . esc_url( self::url() ) . '" class="pos-ws__form"><input type="hidden" name="packout_signin" value="in" /><input type="hidden" name="back" value="' . esc_attr( $back ) . '" />';
		$html .= '<p class="pos-ws__field"><label for="pos-signin-login">' . esc_html__( 'Email address or username', 'packout-core' ) . '</label><input id="pos-signin-login" type="text" name="login" required autocomplete="username" autocapitalize="none" spellcheck="false" /></p>';
		$html .= sprintf( $password, esc_html__( 'Password', 'packout-core' ), 'current-password', '', '' );
		$html .= '<p><label class="pos-ws__tick"><input type="checkbox" name="remember" value="1" /> <span>' . esc_html__( 'Keep me signed in on this device', 'packout-core' ) . '</span></label></p>';
		$html .= '<button class="pos-ws__button pos-ws__button--primary">' . esc_html__( 'Sign in', 'packout-core' ) . '</button></form>';
		$html .= '<p class="pos-ws__aside"><a href="' . esc_url( self::url( array( 'view' => 'forgot' ) ) ) . '">' . esc_html__( 'Forgot your password?', 'packout-core' ) . '</a>';
		if ( Accounts::open() ) {
			$html .= ' &middot; ' . esc_html__( 'New here?', 'packout-core' ) . ' <a href="' . esc_url( Accounts::signup_url( $back ) ) . '">' . esc_html__( 'Sign up', 'packout-core' ) . '</a>';
		}
		return $html . '</p></div>';
	}
}
