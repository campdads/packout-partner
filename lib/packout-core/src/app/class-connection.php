<?php
/**
 * The connection of a partner site to packout.spot.
 *
 * A site that is never connected works in full on its own. Connecting adds
 * a public presence on the hub and takes nothing over: the site's records
 * stay the record of truth, and nothing about a person is sent.
 *
 * Connecting makes an Ed25519 key pair. The secret key is kept in an option
 * that is not loaded with every page and never leaves the site. Every
 * request after registration is signed with it (`PackOut\Core\Signing`).
 *
 * The site calls the hub. The hub calls the site once, to prove the address:
 * it asks `packout/v1/hub-challenge` for the challenge it issued, signed.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

use PackOut\Core\Contract;
use PackOut\Core\Release;
use PackOut\Core\Signing;
use PackOut\Core\Updates as Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * A partner site's side of the partner interface.
 */
final class Connection {

	/**
	 * The option that holds the key pair. Not autoloaded.
	 */
	public const KEY_OPTION = 'packout_partner_key';

	/**
	 * The option that holds what the site knows about its connection.
	 */
	public const OPTION = 'packout_connection';

	/**
	 * The option that holds what the hub last told this site.
	 */
	public const NOTICES = 'packout_connection_notices';

	/**
	 * The option that holds what the hub said about each organization of this site that is also one of its own.
	 */
	public const CLAIMS = 'packout_connection_claims';

	/**
	 * The namespace and base of the hub's routes.
	 */
	public const BASE = '/wp-json/packout/v1/partners';

	/**
	 * How long an answer of `me` is good for, in seconds.
	 */
	public const CHECK_EVERY = 900;

	/**
	 * Register the route the hub asks to prove this site's address. A partner site only.
	 */
	public static function register(): void {
		if ( App::is_hub() ) {
			return;
		}
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * The route of the challenge.
	 */
	public static function routes(): void {
		register_rest_route(
			'packout/v1',
			'/hub-challenge',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'challenge' ),
				// Public: the hub has no account here. The answer is a signature and nothing else.
				'permission_callback' => '__return_true',
				'args'                => array(
					'site' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Answer the hub's challenge: the challenge it issued, signed with this site's key.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function challenge( \WP_REST_Request $request ) {
		$state = self::state();
		$key   = self::key();
		if ( '' === $state['site'] || '' === $state['challenge'] || ! $key || ! hash_equals( $state['site'], (string) $request->get_param( 'site' ) ) ) {
			return new \WP_Error( 'packout_not_found', __( 'This site has no connection waiting to be proved.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$response = new \WP_REST_Response(
			array(
				'challenge' => $state['challenge'],
				'signature' => Signing::sign( self::challenge_text( $state['site'], $state['challenge'] ), $key['secret'] ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * The text a site signs to answer a challenge.
	 *
	 * @param string $site      The id the hub issued.
	 * @param string $challenge The challenge the hub issued.
	 */
	public static function challenge_text( string $site, string $challenge ): string {
		return "packout-challenge\n" . $site . "\n" . $challenge;
	}

	/**
	 * The hub's address.
	 */
	public static function hub(): string {
		return Catalog::origin();
	}

	/**
	 * What the site knows about its connection.
	 *
	 * @return array{site: string, state: string, hub: string, home: string, registered: int, terms: string, challenge: string, partner_url: string, minimum: string, current: string, checked: int, sent: int, error: string, confirmed: bool, proved: bool, terms_required: string, terms_by: int, skew: int, reason: string, recovering: int}
	 */
	public static function state(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'site'           => (string) ( $stored['site'] ?? '' ),
			// Empty before a site connects. After that it is one of registered, pending, active, suspended, dormant, and disconnected.
			'state'          => (string) ( $stored['state'] ?? '' ),
			'hub'            => (string) ( $stored['hub'] ?? '' ),
			'home'           => (string) ( $stored['home'] ?? '' ),
			'registered'     => (int) ( $stored['registered'] ?? 0 ),
			'terms'          => (string) ( $stored['terms'] ?? '' ),
			'challenge'      => (string) ( $stored['challenge'] ?? '' ),
			'partner_url'    => (string) ( $stored['partner_url'] ?? '' ),
			'minimum'        => (string) ( $stored['minimum'] ?? '' ),
			'current'        => (string) ( $stored['current'] ?? '' ),
			'checked'        => (int) ( $stored['checked'] ?? 0 ),
			'sent'           => (int) ( $stored['sent'] ?? 0 ),
			'error'          => (string) ( $stored['error'] ?? '' ),
			'confirmed'      => ! empty( $stored['confirmed'] ),
			'proved'         => ! empty( $stored['proved'] ),
			'terms_required' => (string) ( $stored['terms_required'] ?? '' ),
			// Until when a later version of the terms may be accepted without anything stopping. Zero when the hub named no day.
			'terms_by'       => (int) ( $stored['terms_by'] ?? 0 ),
			'skew'           => (int) ( $stored['skew'] ?? 0 ),
			'reason'         => (string) ( $stored['reason'] ?? '' ),
			// When the hub was last asked to send the email that starts the recovery of a lost key.
			'recovering'     => (int) ( $stored['recovering'] ?? 0 ),
		);
	}

	/**
	 * Keep what changed about the connection.
	 *
	 * @param array<string, mixed> $changes Keys of `state()`.
	 */
	public static function remember( array $changes ): void {
		update_option( self::OPTION, array_merge( self::state(), $changes ), true );
	}

	/**
	 * The site's key pair.
	 *
	 * @return array{public: string, secret: string}|null
	 */
	public static function key(): ?array {
		$key = get_option( self::KEY_OPTION );
		if ( ! is_array( $key ) || ! Signing::is_public_key( $key['public'] ?? null ) || ! Signing::is_secret_key( $key['secret'] ?? null ) ) {
			return null;
		}
		return array(
			'public' => $key['public'],
			'secret' => $key['secret'],
		);
	}

	/**
	 * Whether the site has a connection, in any state but ended.
	 */
	public static function connected(): bool {
		$state = self::state();
		return '' !== $state['site'] && null !== self::key() && ! in_array( $state['state'], array( '', 'disconnected' ), true );
	}

	/**
	 * Whether the site's address is the one it connected with. A site that
	 * moved, or a copy of it on a staging address, stops sending until its
	 * administrator says which it is.
	 */
	public static function at_home(): bool {
		$state = self::state();
		return '' === $state['home'] || untrailingslashit( $state['home'] ) === untrailingslashit( home_url() );
	}

	/**
	 * Whether the site sends what it publishes: it is connected, approved, and where it was.
	 */
	public static function sending(): bool {
		$state = self::state();
		// A later version of the terms stops the sending at once when the hub named no day to accept it by, and from that day when it did.
		$terms = '' === $state['terms_required'] || $state['terms_by'] > time();
		return self::connected() && in_array( $state['state'], array( 'active', 'dormant' ), true ) && self::at_home() && $terms;
	}

	/**
	 * Whether the site's key is lost: the hub knows the site, and the site holds no key to sign with.
	 */
	public static function lost(): bool {
		$state = self::state();
		return '' !== $state['site'] && null === self::key() && ! in_array( $state['state'], array( '', 'disconnected' ), true );
	}

	/**
	 * The versions every payload names.
	 *
	 * @return array{contract: int, core_version: string, plugin_version: string}
	 */
	public static function versions(): array {
		return array(
			'contract'       => Contract::version(),
			'core_version'   => (string) PACKOUT_CORE_VERSION,
			'plugin_version' => App::kernel()->site()->version(),
		);
	}

	/**
	 * The partner terms as the hub publishes them: their version and their address.
	 *
	 * @param bool $fresh Ask the hub now.
	 * @return array{version: string, url: string, open: bool}|\WP_Error
	 */
	public static function terms( bool $fresh = false ) {
		$cached = get_transient( 'packout_partner_terms' );
		if ( ! $fresh && is_array( $cached ) ) {
			return $cached;
		}
		$answer = self::send( 'GET', '/terms', null, false );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		$terms = array(
			'version' => (string) ( $answer['version'] ?? '' ),
			'url'     => esc_url_raw( (string) ( $answer['url'] ?? '' ), array( 'https', 'http' ) ),
			'open'    => ! empty( $answer['open'] ),
		);
		set_transient( 'packout_partner_terms', $terms, HOUR_IN_SECONDS );
		return $terms;
	}

	/**
	 * Connect: make a key pair and register with the hub.
	 *
	 * @param string $terms_version The version of the partner terms the administrator accepted.
	 * @param int    $user_id       The administrator.
	 * @return true|\WP_Error
	 */
	public static function connect( string $terms_version, int $user_id ) {
		if ( App::is_hub() ) {
			return App::hub_only();
		}
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'An administrator connects this site.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( self::connected() ) {
			return new \WP_Error( 'packout_connected', __( 'This site is already connected.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$partner = get_post( Partners::site() );
		$user    = get_userdata( $user_id );
		if ( ! $partner instanceof \WP_Post || ! $user ) {
			return new \WP_Error( 'packout_not_found', __( 'This site has no partner record to connect.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! self::secure( home_url() ) ) {
			return new \WP_Error( 'packout_https', __( 'A site connects over HTTPS. This site\'s address does not begin with https.', 'packout-core' ), array( 'status' => 400 ) );
		}

		$pair = Signing::keypair();
		update_option( self::KEY_OPTION, $pair, false );
		$answer = self::send(
			'POST',
			'/register',
			array(
				'site_url'      => untrailingslashit( home_url() ),
				'name'          => mb_substr( wp_strip_all_tags( $partner->post_title ), 0, 120 ),
				'admin_email'   => $user->user_email,
				'public_key'    => $pair['public'],
				'terms_version' => $terms_version,
			) + self::versions(),
			false
		);
		if ( is_wp_error( $answer ) || empty( $answer['site'] ) || empty( $answer['challenge'] ) ) {
			delete_option( self::KEY_OPTION );
			return is_wp_error( $answer ) ? $answer : new \WP_Error( 'packout_hub', __( 'The hub did not answer the registration as expected.', 'packout-core' ), array( 'status' => 502 ) );
		}
		update_option(
			self::OPTION,
			array(
				'site'       => (string) $answer['site'],
				'state'      => 'registered',
				'hub'        => self::hub(),
				'home'       => untrailingslashit( home_url() ),
				'registered' => time(),
				'terms'      => $terms_version,
				'challenge'  => (string) $answer['challenge'],
			),
			true
		);
		Outbox::log( 'register', 0, 200, '' );
		return true;
	}

	/**
	 * Ask the hub to fetch the challenge now, and learn the state.
	 *
	 * @return array<string, mixed>|\WP_Error What `me` says.
	 */
	public static function verify() {
		$answer = self::send( 'POST', '/verify', self::versions() );
		if ( is_wp_error( $answer ) ) {
			self::remember( array( 'error' => $answer->get_error_message() ) );
			return $answer;
		}
		return self::learn( $answer );
	}

	/**
	 * Ask the hub how the connection stands.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function refresh() {
		if ( '' === self::state()['site'] || null === self::key() ) {
			return new \WP_Error( 'packout_not_connected', __( 'This site is not connected.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$answer = self::send( 'GET', '/me', null );
		if ( is_wp_error( $answer ) ) {
			$code    = $answer->get_error_code();
			$changes = array(
				'error'   => $answer->get_error_message(),
				'checked' => time(),
			);
			// The hub says the connection is over, or that it does not know this site.
			if ( in_array( $code, array( 'partner_disconnected', 'unknown_site' ), true ) ) {
				$changes['state'] = 'disconnected';
			}
			self::remember( $changes );
			return $answer;
		}
		return self::learn( $answer );
	}

	/**
	 * Ask the hub how the connection stands when the last answer is old.
	 */
	public static function refresh_if_due(): void {
		$state = self::state();
		if ( '' !== $state['site'] && 'disconnected' !== $state['state'] && time() - $state['checked'] >= self::CHECK_EVERY ) {
			self::refresh();
		}
	}

	/**
	 * Keep what the hub said about the connection.
	 *
	 * @param array<string, mixed> $answer What `me` says.
	 * @return array<string, mixed> The same.
	 */
	private static function learn( array $answer ): array {
		$before = self::state();
		$state  = (string) ( $answer['state'] ?? '' );
		$known  = array( 'pending', 'active', 'suspended', 'dormant', 'disconnected' );
		$proved = ! empty( $answer['proved'] );
		self::remember(
			array(
				// Until the email is confirmed and the address is proved, the site is registered and no more.
				'state'          => in_array( $state, $known, true ) ? ( 'pending' === $state && ( empty( $answer['confirmed'] ) || ! $proved ) ? 'registered' : $state ) : $before['state'],
				'confirmed'      => ! empty( $answer['confirmed'] ),
				'proved'         => $proved,
				'partner_url'    => esc_url_raw( (string) ( $answer['partner_url'] ?? '' ), array( 'https', 'http' ) ),
				'minimum'        => preg_replace( '/[^0-9A-Za-z.\-]/', '', (string) ( $answer['minimum_version'] ?? '' ) ),
				'current'        => preg_replace( '/[^0-9A-Za-z.\-]/', '', (string) ( $answer['current_version'] ?? '' ) ),
				'terms_required' => preg_replace( '/[^0-9A-Za-z.\-]/', '', (string) ( $answer['terms_required'] ?? '' ) ),
				'terms_by'       => max( 0, (int) ( $answer['terms_by'] ?? 0 ) ),
				'reason'         => sanitize_text_field( (string) ( $answer['reason'] ?? '' ) ),
				'checked'        => time(),
				'error'          => '',
				// The challenge has done its work once the address is proved.
				'challenge'      => $proved ? '' : $before['challenge'],
			)
		);
		if ( isset( $answer['notices'] ) && is_array( $answer['notices'] ) ) {
			$notices = array();
			foreach ( array_slice( $answer['notices'], 0, 20 ) as $notice ) {
				$notices[] = array(
					'at'   => (int) ( $notice['at'] ?? 0 ),
					'kind' => sanitize_key( (string) ( $notice['kind'] ?? '' ) ),
					'text' => sanitize_text_field( (string) ( $notice['text'] ?? '' ) ),
				);
			}
			update_option( self::NOTICES, $notices, false );
		}
		if ( isset( $answer['claims'] ) && is_array( $answer['claims'] ) ) {
			self::learn_claims( $answer['claims'] );
		}
		$after = self::state();
		if ( 'active' === $after['state'] && 'active' !== $before['state'] ) {
			/**
			 * The hub approved this site, or lifted a suspension: everything public is sent again.
			 */
			do_action( 'packout_connection_active' );
		}
		return $answer;
	}

	/**
	 * Keep what the hub said about the organizations of this site that are also its own.
	 *
	 * @param array<int, mixed> $said The claims, as `me` gives them.
	 */
	private static function learn_claims( array $said ): void {
		$before = self::claims();
		$claims = array();
		$day    = static fn( $value ): string => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? (string) $value : '';
		foreach ( array_slice( $said, 0, 100 ) as $claim ) {
			$team  = is_array( $claim ) ? (int) ( $claim['organization'] ?? 0 ) : 0;
			$state = is_array( $claim ) ? sanitize_key( (string) ( $claim['state'] ?? '' ) ) : '';
			if ( $team <= 0 || ! in_array( $state, array( 'asked', 'accepted', 'declined', 'ended' ), true ) ) {
				continue;
			}
			$url             = esc_url_raw( (string) ( $claim['url'] ?? '' ), array( 'https', 'http' ) );
			$claims[ $team ] = array(
				'state' => $state,
				'day'   => $day( $claim['day'] ?? '' ),
				'until' => $day( $claim['until'] ?? '' ),
				'name'  => mb_substr( sanitize_text_field( (string) ( $claim['name'] ?? '' ) ), 0, 200 ),
				// An address that is not the hub's is dropped.
				'url'   => 0 === strpos( $url, trailingslashit( self::hub() ) ) ? $url : '',
			);
		}
		if ( $claims === $before ) {
			return;
		}
		update_option( self::CLAIMS, $claims, false );
		$days = static fn( array $all ): array => array_map( static fn( array $claim ): string => in_array( $claim['state'], array( 'accepted', 'ended' ), true ) ? $claim['day'] : '', $all );
		if ( $days( $claims ) !== $days( $before ) ) {
			/**
			 * The day from which an organization's work is counted here changed: the totals are sent again.
			 */
			do_action( 'packout_connection_claims' );
		}
	}

	/**
	 * What the hub said about each organization of this site that is also one of its own.
	 *
	 * @return array<int, array{state: string, day: string, until: string, name: string, url: string}> By the organization's ID here.
	 */
	public static function claims(): array {
		$claims = get_option( self::CLAIMS, array() );
		return is_array( $claims ) ? $claims : array();
	}

	/**
	 * The day from which an organization's work is counted on this site, when packout.spot counts what it did before from its own records. Empty for every other organization.
	 *
	 * @param int $team An organization of this site.
	 * @return string `Y-m-d`, or an empty string.
	 */
	public static function claim_day( int $team ): string {
		if ( $team <= 0 || App::is_hub() ) {
			return '';
		}
		$claim = self::claims()[ $team ] ?? null;
		return is_array( $claim ) && in_array( $claim['state'] ?? '', array( 'accepted', 'ended' ), true ) ? (string) ( $claim['day'] ?? '' ) : '';
	}

	/**
	 * Say that an organization of this site is the same as one on packout.spot, so its work is counted once. The lead of the organization there answers.
	 *
	 * @param int    $team    An organization listed here.
	 * @param string $address The address of the organization's page on packout.spot.
	 * @param int    $user_id The administrator.
	 * @return true|\WP_Error
	 */
	public static function claim( int $team, string $address, int $user_id ) {
		if ( App::is_hub() ) {
			return App::hub_only();
		}
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'An administrator manages this site\'s connection.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! self::sending() ) {
			return new \WP_Error( 'packout_not_sending', __( 'This site is not sending right now.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( ! Listings::listable( 'organization', (string) $team ) ) {
			return new \WP_Error( 'packout_not_listed', __( 'Choose an organization that is listed here. packout.spot has to have its listing first.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$address = esc_url_raw( trim( $address ), array( 'https', 'http' ) );
		if ( '' === $address || 0 !== strpos( $address, trailingslashit( self::hub() ) ) || strlen( $address ) > 300 ) {
			return new \WP_Error( 'packout_claim_address', __( 'Give the address of the organization\'s page on packout.spot.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$answer = self::send(
			'POST',
			'/claims',
			array(
				'organization' => (string) $team,
				'address'      => $address,
			) + self::versions()
		);
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		Outbox::log( 'claim', 1, 200, '' );
		self::learn( $answer );
		return true;
	}

	/**
	 * Take back what was said about an organization: a request that waits is withdrawn, and one that was accepted is ended. packout.spot's organization can be worked in again, and the days between stay counted here.
	 *
	 * @param int $team    An organization of this site.
	 * @param int $user_id The administrator.
	 * @return true|\WP_Error
	 */
	public static function unclaim( int $team, int $user_id ) {
		if ( App::is_hub() ) {
			return App::hub_only();
		}
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'An administrator manages this site\'s connection.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$answer = self::send(
			'POST',
			'/claims',
			array(
				'organization' => (string) $team,
				'withdraw'     => true,
			) + self::versions()
		);
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		Outbox::log( 'claim', 1, 200, 'withdrawn' );
		self::learn( $answer );
		return true;
	}

	/**
	 * The site's address changed: tell the hub, which asks this site one question at the new address before it takes anything else from it.
	 *
	 * @param int $user_id The administrator.
	 * @return true|\WP_Error
	 */
	public static function move( int $user_id ) {
		if ( App::is_hub() ) {
			return App::hub_only();
		}
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'An administrator manages this site\'s connection.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! self::connected() ) {
			return new \WP_Error( 'packout_not_connected', __( 'This site is not connected.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$home = untrailingslashit( home_url() );
		if ( ! self::secure( $home ) ) {
			return new \WP_Error( 'packout_https', __( 'A site connects over HTTPS. This site\'s address does not begin with https.', 'packout-core' ), array( 'status' => 400 ) );
		}
		// The hub asks for this while the request waits, so it is kept first.
		$challenge = bin2hex( random_bytes( 32 ) );
		self::remember( array( 'challenge' => $challenge ) );
		$answer = self::send(
			'POST',
			'/move',
			array(
				'site_url'  => $home,
				'challenge' => $challenge,
			) + self::versions()
		);
		self::remember( array( 'challenge' => '' ) );
		if ( is_wp_error( $answer ) ) {
			Outbox::log( 'move', 0, (int) ( $answer->get_error_data()['status'] ?? 0 ), $answer->get_error_code() );
			return $answer;
		}
		self::remember( array( 'home' => $home ) );
		Outbox::log( 'move', 0, 200, '' );
		self::learn( $answer );
		if ( self::sending() ) {
			// Every address the hub holds is one of the old site's. Everything is sent again from here.
			Outbox::everything();
		}
		return true;
	}

	/**
	 * The key is lost: ask the hub to email the administrator who connected the site. The hub answers in the same words whether or not it knows the site.
	 *
	 * @param int $user_id The administrator.
	 * @return true|\WP_Error
	 */
	public static function recover( int $user_id ) {
		if ( App::is_hub() ) {
			return App::hub_only();
		}
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'An administrator manages this site\'s connection.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$state = self::state();
		if ( '' === $state['site'] ) {
			return new \WP_Error( 'packout_not_connected', __( 'This site is not connected.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$answer = self::send( 'POST', '/recover', array( 'site' => $state['site'] ) + self::versions(), false );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		self::remember( array( 'recovering' => time() ) );
		Outbox::log( 'recover', 0, 200, 'asked' );
		return true;
	}

	/**
	 * After the link in the hub's email was followed: make a new key and give the hub its public half. The hub asks this site one question, signed with the new key, before it takes it.
	 *
	 * @param int $user_id The administrator.
	 * @return true|\WP_Error
	 */
	public static function recover_finish( int $user_id ) {
		if ( App::is_hub() ) {
			return App::hub_only();
		}
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'An administrator manages this site\'s connection.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$state = self::state();
		if ( ! self::lost() ) {
			return new \WP_Error( 'packout_not_lost', __( 'This site has its key. There is nothing to recover.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( ! self::at_home() ) {
			return new \WP_Error( 'packout_moved', __( 'This site is not at the address it connected with. The key is recovered at that address.', 'packout-core' ), array( 'status' => 409 ) );
		}
		// The hub asks for the challenge, signed with the new key, while the request waits. Both are kept first.
		$pair      = Signing::keypair();
		$challenge = bin2hex( random_bytes( 32 ) );
		update_option( self::KEY_OPTION, $pair, false );
		self::remember( array( 'challenge' => $challenge ) );
		$answer = self::send(
			'POST',
			'/recover',
			array(
				'site'       => $state['site'],
				'public_key' => $pair['public'],
				'challenge'  => $challenge,
			) + self::versions(),
			false
		);
		self::remember( array( 'challenge' => '' ) );
		if ( is_wp_error( $answer ) || empty( $answer['recovered'] ) ) {
			delete_option( self::KEY_OPTION );
			Outbox::log( 'recover', 0, is_wp_error( $answer ) ? (int) ( $answer->get_error_data()['status'] ?? 0 ) : 0, is_wp_error( $answer ) ? $answer->get_error_code() : 'no_answer' );
			return is_wp_error( $answer ) ? $answer : new \WP_Error( 'packout_hub', __( 'The hub did not answer the recovery as expected.', 'packout-core' ), array( 'status' => 502 ) );
		}
		self::remember( array( 'recovering' => 0 ) );
		Outbox::log( 'recover', 0, 200, '' );
		self::refresh();
		return true;
	}

	/**
	 * Everything packout.spot holds from this site, as it holds it.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function export() {
		if ( ! self::connected() ) {
			return new \WP_Error( 'packout_not_connected', __( 'This site is not connected.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$answer = self::send( 'GET', '/export', null );
		if ( ! is_wp_error( $answer ) ) {
			Outbox::log( 'export', 0, 200, '' );
		}
		return $answer;
	}

	/**
	 * What the hub last told this site: a listing hidden, a figure held back, a suspension and its reason.
	 *
	 * @return array<int, array{at: int, kind: string, text: string}>
	 */
	public static function notices(): array {
		$notices = get_option( self::NOTICES, array() );
		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * Accept a later version of the partner terms.
	 *
	 * @param string $version The version accepted.
	 * @return true|\WP_Error
	 */
	public static function accept_terms( string $version ) {
		$answer = self::send( 'POST', '/terms', array( 'terms_version' => $version ) + self::versions() );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		self::remember( array( 'terms' => $version ) );
		self::learn( $answer );
		return true;
	}

	/**
	 * Replace the key pair. The request is signed with the key being replaced.
	 *
	 * @return true|\WP_Error
	 */
	public static function replace_key() {
		if ( ! self::connected() ) {
			return new \WP_Error( 'packout_not_connected', __( 'This site is not connected.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$pair   = Signing::keypair();
		$answer = self::send( 'POST', '/key', array( 'public_key' => $pair['public'] ) + self::versions() );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		update_option( self::KEY_OPTION, $pair, false );
		Outbox::log( 'key', 0, 200, '' );
		return true;
	}

	/**
	 * End the connection. The key is deleted here whatever the hub answers,
	 * so a site whose hub is unreachable can still leave.
	 *
	 * @param bool $keep Whether the hub keeps the partner's name and last totals as history.
	 * @return true|\WP_Error True, or the hub's refusal, after which the site is disconnected all the same.
	 */
	public static function disconnect( bool $keep ) {
		$answer = self::connected() ? self::send( 'POST', '/disconnect', array( 'keep' => $keep ) + self::versions() ) : true;
		delete_option( self::KEY_OPTION );
		delete_option( self::CLAIMS );
		// What the hub said was about the connection that ends here.
		delete_option( self::NOTICES );
		update_option(
			self::OPTION,
			array(
				'state' => 'disconnected',
				'hub'   => self::state()['hub'],
			),
			true
		);
		Outbox::clear();
		Outbox::unschedule();
		Outbox::log( 'disconnect', 0, is_wp_error( $answer ) ? 0 : 200, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		return is_wp_error( $answer ) ? $answer : true;
	}

	/**
	 * Forget a connection without telling the hub: for a copy of a site on another address.
	 */
	public static function forget(): void {
		delete_option( self::KEY_OPTION );
		delete_option( self::OPTION );
		delete_option( self::NOTICES );
		delete_option( self::CLAIMS );
		Outbox::clear();
		Outbox::unschedule();
	}

	/**
	 * What the partner says about itself, as it is sent.
	 *
	 * @return array<string, mixed>
	 */
	public static function profile(): array {
		$id      = Partners::site();
		$partner = get_post( $id );
		$website = esc_url_raw( Partners::field( $id, 'website' ), array( 'https', 'http' ) );
		return array(
			'name'        => mb_substr( trim( wp_strip_all_tags( $partner instanceof \WP_Post ? $partner->post_title : get_bloginfo( 'name' ) ) ), 0, 120 ),
			'description' => mb_substr( trim( wp_strip_all_tags( $partner instanceof \WP_Post ? $partner->post_content : '' ) ), 0, 2000 ),
			'region'      => mb_substr( trim( wp_strip_all_tags( Partners::field( $id, 'region' ) ) ), 0, 120 ),
			'website'     => strlen( $website ) <= 200 ? $website : '',
			// The partner's own page here, when the site lists it. The site's front page otherwise.
			'url'         => $partner instanceof \WP_Post && Partners::shown( $id ) ? (string) get_permalink( $partner ) : home_url( '/' ),
			// Which logo and cover the partner has now, each named by a number that changes when the image does. Empty for none. The hub asks for the ones it does not hold.
			'images'      => array(
				'logo'  => self::image_token( 'logo' ),
				'cover' => self::image_token( 'cover' ),
			),
		) + self::versions();
	}

	/**
	 * The number that names the partner's logo or cover as it is now, or nothing.
	 *
	 * @param string $kind `logo` or `cover`.
	 */
	public static function image_token( string $kind ): string {
		$id    = Partners::site();
		$image = $id <= 0 ? 0 : ( 'logo' === $kind ? Partners::logo( $id ) : Page_Header::cover( $id ) );
		return $image > 0 ? (string) $image : '';
	}

	/**
	 * The file of the partner's logo or cover, when it is still the one the hub asked for.
	 *
	 * @param string $kind  `logo` or `cover`.
	 * @param string $token The number the hub was given.
	 * @return string The file's path, or nothing.
	 */
	public static function image_file( string $kind, string $token ): string {
		if ( ! in_array( $kind, array( 'logo', 'cover' ), true ) || '' === $token || self::image_token( $kind ) !== $token ) {
			return '';
		}
		$path = get_attached_file( (int) $token );
		return is_string( $path ) && is_readable( $path ) ? $path : '';
	}

	/**
	 * Whether an address may be used: HTTPS, or a developer's own site.
	 *
	 * @param string $url An address.
	 */
	public static function secure( string $url ): bool {
		return 0 === stripos( $url, 'https://' ) || ( Release::is_local() && 0 === stripos( $url, 'http://' ) );
	}

	/**
	 * Send one request to the hub.
	 *
	 * @param string                    $method `GET`, `POST`, or `PUT`.
	 * @param string                    $route  The route under `packout/v1/partners`, with its leading slash.
	 * @param array<string, mixed>|null $body   The payload, or null for a request without one.
	 * @param bool                      $signed Whether to sign it. Only registering and reading the terms are not signed.
	 * @param bool                      $again  Whether this is the second try after the hub corrected the clock.
	 * @return array<string, mixed>|\WP_Error The decoded answer.
	 */
	public static function send( string $method, string $route, ?array $body, bool $signed = true, bool $again = false ) {
		$hub = self::hub();
		if ( ! self::secure( $hub ) ) {
			return new \WP_Error( 'packout_https', __( 'The hub is reached over HTTPS only.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$state = self::state();
		$key   = self::key();
		if ( $signed && ( '' === $state['site'] || ! $key ) ) {
			return new \WP_Error( 'packout_not_connected', __( 'This site is not connected.', 'packout-core' ), array( 'status' => 409 ) );
		}

		$nonce = Signing::nonce();
		$url   = $hub . self::BASE . $route;
		if ( 'GET' === $method ) {
			// A signed GET carries its number in the address, so no two share one and a cache cannot answer one with another.
			$url = add_query_arg(
				array(
					'contract' => Contract::version(),
					'nonce'    => $nonce,
				),
				$url
			);
		}
		$json    = null === $body ? '' : (string) wp_json_encode( $body );
		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $json ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( $signed ) {
			$headers += Signing::headers( $state['site'], $key['secret'], $method, Signing::path( $url ), $json, time() + $state['skew'], $nonce );
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => '' !== $json ? $json : null,
				'timeout'     => 20,
				'redirection' => 0,
			)
		);
		$answer   = self::read( $response );
		// The hub says this site's clock is off, and by how much. Correct for it and try once more.
		if ( is_wp_error( $answer ) && 'stale' === $answer->get_error_code() && ! $again && $signed && self::corrected( $answer ) ) {
			return self::send( $method, $route, $body, $signed, true );
		}
		return $answer;
	}

	/**
	 * Ask the hub for something it shows every visitor. The request is not signed and says nothing about this site.
	 *
	 * @param string                $route A public route under `packout/v1`, with its leading slash.
	 * @param array<string, string> $query The query.
	 * @return array<string, mixed>|\WP_Error The decoded answer.
	 */
	public static function ask( string $route, array $query = array() ) {
		$hub = self::hub();
		if ( ! self::secure( $hub ) ) {
			return new \WP_Error( 'packout_https', __( 'The hub is reached over HTTPS only.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$url = add_query_arg( array_map( 'rawurlencode', $query ), $hub . substr( self::BASE, 0, -strlen( '/partners' ) ) . $route );
		return self::read(
			wp_remote_get(
				$url,
				array(
					'headers'             => array( 'Accept' => 'application/json' ),
					'timeout'             => 20,
					'redirection'         => 0,
					// Not more than a map can draw.
					'limit_response_size' => 8 * MB_IN_BYTES,
				)
			)
		);
	}

	/**
	 * Send the hub a file: a photograph, a logo, or a cover. The request is signed over the file's bytes.
	 *
	 * What the file is of goes in the address, which is signed with it.
	 *
	 * @param string               $route The route under `/partners`.
	 * @param array<string, mixed> $about What the file is: the query of the request.
	 * @param string               $bytes The file.
	 * @param string               $type  Its type, as `image/webp`.
	 * @param bool                 $again True on the second try after the hub corrected this site's clock.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function send_file( string $route, array $about, string $bytes, string $type, bool $again = false ) {
		$hub = self::hub();
		if ( ! self::secure( $hub ) ) {
			return new \WP_Error( 'packout_https', __( 'The hub is reached over HTTPS only.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$state = self::state();
		$key   = self::key();
		if ( '' === $state['site'] || ! $key ) {
			return new \WP_Error( 'packout_not_connected', __( 'This site is not connected.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( '' === $bytes || strlen( $bytes ) > Contract::IMAGE ) {
			return new \WP_Error( 'too_large', __( 'The file is larger than the hub takes.', 'packout-core' ), array( 'status' => 413 ) );
		}
		$nonce    = Signing::nonce();
		$url      = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $about + self::versions() ) ), $hub . self::BASE . $route );
		$headers  = array(
			'Accept'       => 'application/json',
			'Content-Type' => $type,
		) + Signing::headers( $state['site'], $key['secret'], 'POST', Signing::path( $url ), $bytes, time() + $state['skew'], $nonce );
		$response = wp_remote_request(
			$url,
			array(
				'method'      => 'POST',
				'headers'     => $headers,
				'body'        => $bytes,
				'timeout'     => 45,
				'redirection' => 0,
			)
		);
		$answer   = self::read( $response );
		if ( is_wp_error( $answer ) && 'stale' === $answer->get_error_code() && ! $again && self::corrected( $answer ) ) {
			return self::send_file( $route, $about, $bytes, $type, true );
		}
		return $answer;
	}

	/**
	 * Correct this site's clock by the time the hub gave when it refused a request as stale.
	 *
	 * @param \WP_Error $refusal The refusal.
	 * @return bool Whether the hub gave its time.
	 */
	private static function corrected( \WP_Error $refusal ): bool {
		$data = (array) $refusal->get_error_data();
		if ( ! isset( $data['data']['now'] ) ) {
			return false;
		}
		self::remember( array( 'skew' => (int) $data['data']['now'] - time() ) );
		return true;
	}

	/**
	 * Read the hub's answer to a request.
	 *
	 * @param array<string, mixed>|\WP_Error $response What `wp_remote_request()` returned.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function read( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'packout_hub_unreachable', __( 'The hub could not be reached.', 'packout-core' ) . ' ' . $response->get_error_message(), array( 'status' => 502 ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$answer = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$answer = is_array( $answer ) ? $answer : array();
		if ( $status >= 200 && $status < 300 ) {
			return $answer;
		}

		$code = sanitize_key( (string) ( $answer['code'] ?? '' ) );
		// The hub names the oldest plugin it accepts when it refuses this one.
		if ( 'update_required' === $code && '' !== (string) ( $answer['data']['minimum_version'] ?? '' ) ) {
			self::remember( array( 'minimum' => preg_replace( '/[^0-9A-Za-z.\-]/', '', (string) $answer['data']['minimum_version'] ) ) );
		}
		$message = sanitize_text_field( (string) ( $answer['message'] ?? '' ) );
		return new \WP_Error(
			'' !== $code ? $code : 'packout_hub',
			/* translators: %d: an HTTP status code. */
			'' !== $message ? $message : sprintf( __( 'The hub answered %d.', 'packout-core' ), $status ),
			array(
				'status' => $status,
				'data'   => is_array( $answer['data'] ?? null ) ? $answer['data'] : array(),
			)
		);
	}
}
