<?php
/**
 * My account: what a person tells about themselves, who sees it, and what they have done.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A person's profile is one user meta value. A phone number and a mailing
 * address are private until the person shares them, team by team, with the
 * leads of that team. Team messages are on until the person turns a team
 * off. News from Pack-Out Spot is off until the person asks for it.
 *
 * My account is an overview page and one page for each part: personal info,
 * my impact, email preferences, and sign-in details. Each is a page under the
 * My account page that holds the My account block set to that part. A site
 * that has only the one page shows each part there.
 */
class Profile {

	public const META  = 'pos_profile';
	public const NONCE = 'packout_profile';

	/**
	 * How long the link that confirms a new sign-in email works, and the one that stops a team's messages.
	 */
	private const EMAIL_TTL = DAY_IN_SECONDS;
	private const QUIET_TTL = YEAR_IN_SECONDS;

	/**
	 * Email changes a person may ask for in a day.
	 */
	private const EMAIL_CHANGES = 3;

	/**
	 * Option: the page of each part of My account, found among the pages under the My account page.
	 */
	private const PAGES = 'packout_spot_account_pages';

	/**
	 * The part of My account each form belongs to.
	 */
	private const PART_OF = array(
		'about'         => 'personal',
		'contact'       => 'personal',
		'mail'          => 'email',
		'quiet'         => 'email',
		'email'         => 'signin',
		'email_confirm' => 'signin',
		'password'      => 'signin',
		'spot_edit'     => 'impact',
		'note_add'      => 'impact',
		'note_save'     => 'impact',
		'note_remove'   => 'impact',
		'feature_save'  => 'contributions',
		'feature_trash' => 'contributions',
		'feature_adopt' => 'contributions',
	);

	/**
	 * The forms of a reported Spot, with the part of its pane each belongs to.
	 */
	private const SPOT_FORMS = array(
		'spot_edit'   => 'edit',
		'note_add'    => 'notes',
		'note_save'   => 'notes',
		'note_remove' => 'notes',
	);

	/**
	 * The parts of My account, with the names they go by. The overview is the empty key.
	 *
	 * @return array<string, string>
	 */
	public static function views(): array {
		return array(
			''              => __( 'Overview', 'packout-core' ),
			'personal'      => __( 'Personal info', 'packout-core' ),
			'impact'        => __( 'My impact', 'packout-core' ),
			'contributions' => __( 'My contributions', 'packout-core' ),
			'email'         => __( 'Email preferences', 'packout-core' ),
			'signin'        => __( 'Sign-in details', 'packout-core' ),
		);
	}

	/**
	 * A part of My account by name, or the overview.
	 *
	 * @param mixed $raw What was asked for.
	 */
	public static function view( $raw ): string {
		$view = is_string( $raw ) ? sanitize_key( $raw ) : '';
		return '' !== $view && isset( self::views()[ $view ] ) ? $view : '';
	}

	/**
	 * The page of each part of My account that has one.
	 *
	 * @return array<string, int> Page IDs by part.
	 */
	public static function pages(): array {
		$root   = (int) Settings::get( 'account_page' );
		$cached = get_option( self::PAGES );
		if ( is_array( $cached ) && (int) ( $cached['root'] ?? -1 ) === $root && is_array( $cached['views'] ?? null ) ) {
			return array_map( 'intval', $cached['views'] );
		}
		$views = array();
		if ( $root > 0 ) {
			$children = get_pages(
				array(
					'child_of'    => $root,
					'post_status' => 'publish',
				)
			);
			foreach ( is_array( $children ) ? $children : array() as $page ) {
				$view = self::view_in( parse_blocks( $page->post_content ) );
				if ( '' !== $view && ! isset( $views[ $view ] ) ) {
					$views[ $view ] = (int) $page->ID;
				}
			}
		}
		update_option(
			self::PAGES,
			array(
				'root'  => $root,
				'views' => $views,
			)
		);
		return $views;
	}

	/**
	 * The part a My account block among some blocks is set to, or an empty string.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 */
	private static function view_in( array $blocks ): string {
		foreach ( $blocks as $block ) {
			if ( 'packout/account' === ( $block['blockName'] ?? '' ) ) {
				return self::view( $block['attrs']['view'] ?? '' );
			}
			$inner = self::view_in( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() );
			if ( '' !== $inner ) {
				return $inner;
			}
		}
		return '';
	}

	/**
	 * Look for the pages again after a page changes.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public static function forget_pages( $post_id = 0 ): void {
		if ( 0 === (int) $post_id || 'page' === get_post_type( (int) $post_id ) ) {
			delete_option( self::PAGES );
		}
	}

	/**
	 * The parts of a mailing address, with labels.
	 *
	 * @return array<string, string>
	 */
	public static function address_parts(): array {
		return array(
			'line1'   => __( 'Address', 'packout-core' ),
			'line2'   => __( 'Apartment, suite, or box', 'packout-core' ),
			'city'    => __( 'City', 'packout-core' ),
			'region'  => __( 'State or province', 'packout-core' ),
			'postal'  => __( 'ZIP or postal code', 'packout-core' ),
			'country' => __( 'Country', 'packout-core' ),
		);
	}

	/**
	 * Hook the form handler and the block's headers.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'handle' ), 32 );
		add_action( 'template_redirect', array( self::class, 'headers' ) );
		foreach ( array( 'save_post_page', 'trashed_post', 'untrashed_post', 'deleted_post' ) as $hook ) {
			add_action( $hook, array( self::class, 'forget_pages' ) );
		}
	}

	/**
	 * The My account pages are about one person, so they are not cached.
	 */
	public static function headers(): void {
		$page = (int) Settings::get( 'account_page' );
		if ( $page > 0 && is_page( array_merge( array( $page ), array_values( self::pages() ) ) ) && ! headers_sent() ) {
			nocache_headers();
			header( 'Referrer-Policy: same-origin' );
		}
	}

	/**
	 * Address of My account, or of one part of it.
	 *
	 * A part that has no page of its own is shown on the My account page.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @param string               $view A part from views(), or empty for the overview.
	 */
	public static function url( array $args = array(), string $view = '' ): string {
		$view = self::view( $view );
		if ( '' === $view ) {
			return Accounts::page( 'account_page', $args );
		}
		$page = self::pages()[ $view ] ?? 0;
		if ( $page > 0 && 'publish' === get_post_status( $page ) ) {
			$url = (string) get_permalink( $page );
			return $args ? add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $url ) : $url;
		}
		return Accounts::page( 'account_page', array( 'view' => $view ) + $args );
	}

	/**
	 * A person's profile, with every key present.
	 *
	 * @param int $user User ID.
	 * @return array{about: string, phone: string, address: array<string, string>, share_phone: array<int, int>, share_address: array<int, int>, swag: bool, news: bool, quiet: array<int, int>}
	 */
	public static function get( int $user ): array {
		$stored  = $user > 0 ? get_user_meta( $user, self::META, true ) : array();
		$stored  = is_array( $stored ) ? $stored : array();
		$address = array();
		foreach ( array_keys( self::address_parts() ) as $part ) {
			$address[ $part ] = (string) ( $stored['address'][ $part ] ?? '' );
		}
		$ids = static fn( $items ): array => array_values( array_unique( array_filter( array_map( 'intval', (array) $items ) ) ) );
		return array(
			'about'         => (string) ( $stored['about'] ?? '' ),
			'phone'         => (string) ( $stored['phone'] ?? '' ),
			'address'       => $address,
			'share_phone'   => $ids( $stored['share_phone'] ?? array() ),
			'share_address' => $ids( $stored['share_address'] ?? array() ),
			'swag'          => ! empty( $stored['swag'] ),
			'news'          => ! empty( $stored['news'] ),
			'quiet'         => $ids( $stored['quiet'] ?? array() ),
		);
	}

	/**
	 * Store a profile.
	 *
	 * @param int                  $user    User ID.
	 * @param array<string, mixed> $profile From get(), changed.
	 */
	private static function put( int $user, array $profile ): void {
		update_user_meta( $user, self::META, wp_slash( $profile ) );
	}

	/**
	 * Teams a person is on, as names by ID.
	 *
	 * @param int $user User ID.
	 * @return array<int, string>
	 */
	public static function teams( int $user ): array {
		$teams = array();
		foreach ( Teams::of_user( $user ) as $id ) {
			$team = Teams::get( $id );
			if ( $team ) {
				$teams[ $id ] = $team->post_title;
			}
		}
		return $teams;
	}

	/**
	 * Change the name and the lines a person wrote about themselves.
	 *
	 * @param int                  $user  User ID.
	 * @param array<string, mixed> $input name, about.
	 * @return true|\WP_Error
	 */
	public static function save_about( int $user, array $input ) {
		$person = get_userdata( $user );
		if ( ! $person ) {
			return new \WP_Error( 'packout_not_found', __( 'That account does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$name = sanitize_text_field( (string) ( $input['name'] ?? $person->display_name ) );
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 60 ) {
			return new \WP_Error( 'packout_account_name', __( 'Enter the name your organization will know you by, from 2 to 60 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$about = sanitize_textarea_field( (string) ( $input['about'] ?? '' ) );
		if ( mb_strlen( $about ) > 600 ) {
			return new \WP_Error( 'packout_profile_about', __( 'Keep what you write about yourself to 600 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( $name !== $person->display_name ) {
			$saved = wp_update_user(
				array(
					'ID'           => $user,
					'display_name' => wp_slash( $name ),
					'nickname'     => wp_slash( $name ),
				)
			);
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}
		$profile          = self::get( $user );
		$profile['about'] = $about;
		self::put( $user, $profile );
		return true;
	}

	/**
	 * The Spot a person reported, or null: only its reporter changes it or keeps notes on it here.
	 *
	 * @param int $user    User ID.
	 * @param int $spot_id Spot ID.
	 */
	public static function own_spot( int $user, int $spot_id ): ?\WP_Post {
		$spot = Spots::get( $spot_id );
		return $spot && $user > 0 && (int) $spot->post_author === $user && in_array( $spot->post_status, Spots::POST_STATUSES, true ) ? $spot : null;
	}

	/**
	 * Change a report the person made.
	 *
	 * The description, the category, the landmark, and the pin can be changed
	 * until a coordinator has reviewed the report. Who the public page
	 * credits can be changed at any time.
	 *
	 * @param int                  $user    User ID.
	 * @param int                  $spot_id Spot ID.
	 * @param array<string, mixed> $input   title, category, description, landmark, lat, lng, credit_team, credit_named.
	 * @return true|\WP_Error
	 */
	public static function save_spot( int $user, int $spot_id, array $input ) {
		$spot = self::own_spot( $user, $spot_id );
		if ( ! $spot ) {
			return new \WP_Error( 'packout_forbidden', __( 'You can change the Spots you reported.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( 'pending' === $spot->post_status ) {
			$fields = array_intersect_key( $input, array_flip( array( 'title', 'category', 'description', 'landmark' ) ) );
			if ( array_key_exists( 'lat', $input ) || array_key_exists( 'lng', $input ) ) {
				$lat = trim( (string) ( $input['lat'] ?? '' ) );
				$lng = trim( (string) ( $input['lng'] ?? '' ) );
				if ( ( '' !== $lat || '' !== $lng ) && ! Geo::point( $lat, $lng ) ) {
					return new \WP_Error( 'packout_pin', __( 'Enter the latitude and the longitude as decimal degrees, such as 35.1983 and -111.6513, or leave both empty.', 'packout-core' ), array( 'status' => 400 ) );
				}
				$fields['lat'] = $lat;
				$fields['lng'] = $lng;
			}
			$changed = Spots::update( $spot_id, $fields );
			if ( is_wp_error( $changed ) ) {
				return $changed;
			}
		}
		if ( array_key_exists( 'credit_team', $input ) || array_key_exists( 'credit_named', $input ) ) {
			Spots::set_credit( $spot_id, (int) ( $input['credit_team'] ?? 0 ), ! empty( $input['credit_named'] ) );
		}
		Updates::refresh( $spot_id );
		return true;
	}

	/**
	 * Change the phone number, the mailing address, and who sees each.
	 *
	 * @param int                  $user  User ID.
	 * @param array<string, mixed> $input phone, address (by part), share_phone and share_address (team IDs), swag.
	 * @return true|\WP_Error
	 */
	public static function save_contact( int $user, array $input ) {
		if ( ! get_userdata( $user ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That account does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$phone = trim( sanitize_text_field( (string) ( $input['phone'] ?? '' ) ) );
		if ( '' !== $phone && ( mb_strlen( $phone ) > 30 || 1 !== preg_match( '/^[0-9+().\-\sx#]{7,30}$/i', $phone ) || strlen( (string) preg_replace( '/\D/', '', $phone ) ) < 7 ) ) {
			return new \WP_Error( 'packout_profile_phone', __( 'Enter a phone number with at least seven digits, or leave it empty.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$address = array();
		foreach ( array_keys( self::address_parts() ) as $part ) {
			$address[ $part ] = mb_substr( trim( sanitize_text_field( (string) ( $input['address'][ $part ] ?? '' ) ) ), 0, 100 );
		}
		$mine    = array_keys( self::teams( $user ) );
		$chosen  = static fn( $items ): array => array_values( array_intersect( array_map( 'intval', (array) $items ), $mine ) );
		$profile = self::get( $user );

		$profile['phone']   = $phone;
		$profile['address'] = $address;
		// Nothing is shared that is not there to share.
		$profile['share_phone']   = '' !== $phone ? $chosen( $input['share_phone'] ?? array() ) : array();
		$profile['share_address'] = self::has_address( $address ) ? $chosen( $input['share_address'] ?? array() ) : array();
		$profile['swag']          = self::has_address( $address ) && ! empty( $input['swag'] );
		self::put( $user, $profile );
		return true;
	}

	/**
	 * Change which teams may email a person, and whether Pack-Out Spot may send news.
	 *
	 * @param int                  $user  User ID.
	 * @param array<string, mixed> $input teams (IDs whose messages the person wants), news.
	 * @return true|\WP_Error
	 */
	public static function save_mail( int $user, array $input ) {
		if ( ! get_userdata( $user ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That account does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$mine    = array_keys( self::teams( $user ) );
		$wanted  = array_map( 'intval', (array) ( $input['teams'] ?? array() ) );
		$profile = self::get( $user );
		// A team the person has left stays off, in case they come back.
		$profile['quiet'] = array_values( array_unique( array_merge( array_diff( $profile['quiet'], $mine ), array_diff( $mine, $wanted ) ) ) );
		$profile['news']  = ! empty( $input['news'] );
		self::put( $user, $profile );
		return true;
	}

	/**
	 * Whether an address has enough in it to reach someone.
	 *
	 * @param array<string, string> $address Address by part.
	 */
	public static function has_address( array $address ): bool {
		return '' !== ( $address['line1'] ?? '' ) && '' !== ( $address['city'] ?? '' );
	}

	/**
	 * An address on its lines.
	 *
	 * @param array<string, string> $address Address by part.
	 * @return array<int, string>
	 */
	public static function address_lines( array $address ): array {
		return array_values(
			array_filter(
				array(
					$address['line1'] ?? '',
					$address['line2'] ?? '',
					trim( implode( ', ', array_filter( array( $address['city'] ?? '', $address['region'] ?? '' ) ) ) . ' ' . ( $address['postal'] ?? '' ) ),
					$address['country'] ?? '',
				)
			)
		);
	}

	/**
	 * What a person shares with the leads of one team.
	 *
	 * @param int $user User ID.
	 * @param int $team Team ID.
	 * @return array{about: string, phone: string, address: array<int, string>}
	 */
	public static function shared_with( int $user, int $team ): array {
		$empty = array(
			'about'   => '',
			'phone'   => '',
			'address' => array(),
		);
		if ( ! Teams::includes( $team, $user ) ) {
			return $empty;
		}
		$profile = self::get( $user );
		return array(
			'about'   => $profile['about'],
			'phone'   => in_array( $team, $profile['share_phone'], true ) ? $profile['phone'] : '',
			'address' => in_array( $team, $profile['share_address'], true ) ? self::address_lines( $profile['address'] ) : array(),
		);
	}

	/**
	 * End what a person shares with a team they are no longer on.
	 *
	 * @param int $user User ID.
	 * @param int $team Team ID.
	 */
	public static function forget_team( int $user, int $team ): void {
		$profile = self::get( $user );
		if ( ! in_array( $team, $profile['share_phone'], true ) && ! in_array( $team, $profile['share_address'], true ) ) {
			return;
		}
		$profile['share_phone']   = array_values( array_diff( $profile['share_phone'], array( $team ) ) );
		$profile['share_address'] = array_values( array_diff( $profile['share_address'], array( $team ) ) );
		self::put( $user, $profile );
	}

	/**
	 * Whether a team may email a person its messages.
	 *
	 * @param int $user User ID.
	 * @param int $team Team ID.
	 */
	public static function wants_team_mail( int $user, int $team ): bool {
		return ! in_array( $team, self::get( $user )['quiet'], true );
	}

	/**
	 * Turn a team's messages off or on for a person.
	 *
	 * @param int  $user User ID.
	 * @param int  $team Team ID.
	 * @param bool $on   Whether the team may email them.
	 */
	public static function set_team_mail( int $user, int $team, bool $on ): void {
		$profile          = self::get( $user );
		$profile['quiet'] = $on ? array_values( array_diff( $profile['quiet'], array( $team ) ) ) : array_values( array_unique( array_merge( $profile['quiet'], array( $team ) ) ) );
		self::put( $user, $profile );
	}

	/**
	 * The link in a team's message that stops that team's messages for one person.
	 *
	 * @param int $user User ID.
	 * @param int $team Team ID.
	 */
	public static function quiet_link( int $user, int $team ): string {
		return self::url(
			array(
				'pos_quiet' => Accounts::token(
					array(
						'k' => 'quiet',
						'u' => $user,
						't' => $team,
					),
					self::QUIET_TTL
				),
			),
			'email'
		);
	}

	/**
	 * Who and which team a stop link is for, or null.
	 *
	 * @param string $token Token from the address.
	 * @return array{user: int, team: int}|null
	 */
	public static function read_quiet( string $token ): ?array {
		$claims = '' !== $token ? Accounts::claims( $token ) : null;
		if ( ! $claims || 'quiet' !== ( $claims['k'] ?? '' ) || ! get_userdata( (int) ( $claims['u'] ?? 0 ) ) || ! Teams::get( (int) ( $claims['t'] ?? 0 ) ) ) {
			return null;
		}
		return array(
			'user' => (int) $claims['u'],
			'team' => (int) $claims['t'],
		);
	}

	/**
	 * Ask to change the sign-in email. Nothing changes until the link sent to the new address is used.
	 *
	 * The answer is the same whether or not the new address already has an
	 * account, so the form cannot be used to find out who has signed up.
	 *
	 * @param int    $user     User ID.
	 * @param string $email    New address.
	 * @param string $password The person's current password, as typed.
	 * @return true|\WP_Error
	 */
	public static function request_email( int $user, string $email, string $password ) {
		$person = get_userdata( $user );
		if ( ! $person ) {
			return new \WP_Error( 'packout_not_found', __( 'That account does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) || ( is_multisite() && is_email_address_unsafe( $email ) ) ) {
			return new \WP_Error( 'packout_account_email', __( 'Enter an email address.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( strtolower( $person->user_email ) === $email ) {
			return new \WP_Error( 'packout_profile_same', __( 'That is your sign-in email now.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( ! Accounts::allow( 'email-change-' . $user, self::EMAIL_CHANGES, DAY_IN_SECONDS ) ) {
			return new \WP_Error( 'packout_account_limit', __( 'You have asked to change your email several times today. Try again tomorrow.', 'packout-core' ), array( 'status' => 429 ) );
		}
		if ( ! wp_check_password( $password, $person->user_pass, $user ) ) {
			return new \WP_Error( 'packout_profile_password', __( 'That is not your current password.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( email_exists( $email ) ) {
			// Another account uses it. Its owner is not told, and neither is this person.
			return true;
		}
		$link = self::url(
			array(
				'pos_email' => Accounts::token(
					array(
						'k' => 'email',
						'u' => $user,
						'e' => $email,
						// The link is for the address the account has now, so it works once.
						'o' => md5( strtolower( $person->user_email ) ),
					),
					self::EMAIL_TTL
				),
			),
			'signin'
		);
		$sent = Accounts::mail(
			$email,
			__( 'Confirm your new email address', 'packout-core' ),
			array(
				/* translators: %s: the person's name. */
				sprintf( __( 'Hello %s,', 'packout-core' ), $person->display_name ),
				__( 'You asked to sign in to Pack-Out Spot with this email address. Follow this link while signed in, and confirm. It is good for one day.', 'packout-core' ),
				$link,
				__( 'If you did not ask for this, ignore this message. Nothing changes until the link is used.', 'packout-core' ),
			)
		);
		return $sent ? true : new \WP_Error( 'packout_account_mail', __( 'The confirmation message could not be sent. Try again in a few minutes.', 'packout-core' ), array( 'status' => 500 ) );
	}

	/**
	 * The new address a link asks for, when the link is this person's and still good.
	 *
	 * @param string $token Token from the address.
	 * @param int    $user  The signed-in person.
	 */
	public static function read_email( string $token, int $user ): string {
		$claims = '' !== $token ? Accounts::claims( $token ) : null;
		$person = get_userdata( $user );
		if ( ! $claims || ! $person || 'email' !== ( $claims['k'] ?? '' ) || (int) ( $claims['u'] ?? 0 ) !== $user ) {
			return '';
		}
		$email = strtolower( (string) ( $claims['e'] ?? '' ) );
		return is_email( $email ) && hash_equals( md5( strtolower( $person->user_email ) ), (string) ( $claims['o'] ?? '' ) ) ? $email : '';
	}

	/**
	 * Change the sign-in email with the link sent to the new address.
	 *
	 * @param string $token Token from the link.
	 * @param int    $user  The signed-in person.
	 * @return true|\WP_Error
	 */
	public static function confirm_email( string $token, int $user ) {
		$email  = self::read_email( $token, $user );
		$person = get_userdata( $user );
		if ( '' === $email || ! $person || email_exists( $email ) ) {
			return new \WP_Error( 'packout_account_link', __( 'That link has expired or has been used. Ask again below.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$old = $person->user_email;
		// WordPress would send its own notice. This one is written for people who never see the dashboard.
		add_filter( 'send_email_change_email', '__return_false' );
		$saved = wp_update_user(
			array(
				'ID'         => $user,
				'user_email' => $email,
			)
		);
		remove_filter( 'send_email_change_email', '__return_false' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		Accounts::mail(
			$old,
			__( 'Your sign-in email was changed', 'packout-core' ),
			array(
				/* translators: %s: the person's name. */
				sprintf( __( 'Hello %s,', 'packout-core' ), $person->display_name ),
				/* translators: %s: email address. */
				sprintf( __( 'Your Pack-Out Spot account now signs in with %s. Messages go there from now on.', 'packout-core' ), $email ),
				/* translators: %s: email address. */
				sprintf( __( 'If you did not do this, write to %s right away.', 'packout-core' ), (string) get_option( 'admin_email' ) ),
			)
		);
		return true;
	}

	/**
	 * What a person has done.
	 *
	 * @param int $user User ID.
	 * @return array{spots: array<int, \WP_Post>, waiting: array<int, \WP_Post>, cleanups: array<int, \WP_Post>, visits: array<int, \WP_Post>, missions: array<int, \WP_Post>, features: array<int, \WP_Post>, hours: float, bags: int, pounds: float}
	 */
	public static function contributions( int $user ): array {
		$spots = get_posts(
			array(
				'post_type'   => Schema::SPOT,
				'post_status' => Spots::POST_STATUSES,
				'author'      => $user,
				'numberposts' => 500,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$query    = array(
			'post_type'   => Schema::IMPACT,
			'post_status' => array( 'publish', 'private' ),
			'numberposts' => 500,
			'orderby'     => 'ID',
			'order'       => 'DESC',
		);
		$authored = get_posts( $query + array( 'author' => $user ) );
		$credited = get_posts(
			$query + array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One person's records, on their own page.
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'   => '_pos_user',
						'value' => $user,
					),
					array(
						'key'   => '_pos_person',
						'value' => $user,
					),
				),
			)
		);
		$records  = array();
		foreach ( array_merge( $authored, $credited ) as $record ) {
			$records[ $record->ID ] = $record;
		}
		krsort( $records );

		$done = array(
			'spots'    => $spots,
			'waiting'  => array(),
			'cleanups' => array(),
			'visits'   => array(),
			'missions' => array(),
			'features' => Features::of_user( $user ),
			'hours'    => 0.0,
			'bags'     => 0,
			'pounds'   => 0.0,
		);
		foreach ( $records as $record ) {
			if ( Impact::CLEANUP === Impact::kind( $record->ID ) ) {
				$done['cleanups'][] = $record;
				$done['hours']     += (float) Impact::field( $record->ID, 'hours', '0' );
				$done['bags']      += (int) Impact::field( $record->ID, 'bags', '0' );
				$done['pounds']    += (float) Impact::field( $record->ID, 'pounds', '0' );
			} else {
				$done['visits'][] = $record;
			}
		}

		// Cleanups the person reported from a Spot's page that still wait for a photo.
		$claimed         = get_posts(
			array(
				'post_type'   => Schema::SPOT,
				'post_status' => 'publish',
				'numberposts' => 50,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One person's claims, on their own page.
				'meta_query'  => array(
					array(
						'key'   => '_pos_claim_by',
						'value' => $user,
					),
					array(
						'key'   => '_pos_status',
						'value' => 'pending',
					),
				),
			)
		);
		$done['waiting'] = $claimed;

		foreach ( Missions::for_user( $user ) as $mission ) {
			if ( 'called_off' !== Missions::status( $mission->ID ) && Teams::includes( Missions::team( $mission->ID ), $user ) && Missions::can_work( $mission->ID, $user ) ) {
				$done['missions'][] = $mission;
			}
		}
		return $done;
	}

	/**
	 * People for the mailing file: everyone who asked for news, and everyone
	 * who let Pack-Out Spot use their address.
	 *
	 * @return array<int, array<int, string>> Rows, with a header.
	 */
	public static function mailing_rows(): array {
		$rows  = array( array( 'name', 'email', 'news', 'may_send_things', 'address_1', 'address_2', 'city', 'state_or_province', 'postal_code', 'country', 'phone', 'teams' ) );
		$users = get_users(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- People who filled in a profile.
				'meta_key' => self::META,
				'orderby'  => 'display_name',
				'number'   => 5000,
			)
		);
		foreach ( $users as $person ) {
			$profile = self::get( $person->ID );
			if ( ! $profile['news'] && ! $profile['swag'] ) {
				continue;
			}
			// An address and a phone number are in the file only when the person let Pack-Out Spot use the address.
			$address = $profile['swag'] ? $profile['address'] : array();
			$rows[]  = array(
				$person->display_name,
				$person->user_email,
				$profile['news'] ? 'yes' : 'no',
				$profile['swag'] ? 'yes' : 'no',
				(string) ( $address['line1'] ?? '' ),
				(string) ( $address['line2'] ?? '' ),
				(string) ( $address['city'] ?? '' ),
				(string) ( $address['region'] ?? '' ),
				(string) ( $address['postal'] ?? '' ),
				(string) ( $address['country'] ?? '' ),
				$profile['swag'] ? $profile['phone'] : '',
				implode( '; ', self::teams( $person->ID ) ),
			);
		}//end foreach
		return $rows;
	}

	/**
	 * Act on a form from the My account page.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked below for a signed-in person. The stop link is proven by its signature.
		$do = isset( $_POST['packout_profile'] ) ? sanitize_key( wp_unslash( $_POST['packout_profile'] ) ) : '';
		if ( '' === $do ) {
			return;
		}
		$text  = static fn( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		$list  = static fn( string $key ): array => isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? array_map( 'intval', wp_unslash( $_POST[ $key ] ) ) : array();
		$token = $text( 'token' );

		if ( 'quiet' === $do ) {
			// The link in a message. It works without signing in, and does one thing.
			$who = self::read_quiet( $token );
			if ( ! $who ) {
				Accounts::go( self::url( array( 'problem' => 'link' ), 'email' ) );
			}
			self::set_team_mail( $who['user'], $who['team'], false );
			Accounts::go(
				self::url(
					array(
						'pos_quiet' => $token,
						'done'      => 'quiet',
					),
					'email'
				)
			);
		}

		$user = get_current_user_id();
		if ( $user <= 0 ) {
			Accounts::go( Accounts::signin_url( self::url() ) );
		}
		if ( ! isset( $_POST['_posnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_posnonce'] ) ), self::NONCE ) ) {
			self::$problem = new \WP_Error( 'packout_expired', __( 'That form had expired. Try again.', 'packout-core' ) );
			return;
		}

		$result = null;
		switch ( $do ) {
			case 'about':
				$result = self::save_about(
					$user,
					array(
						'name'  => $text( 'name' ),
						'about' => isset( $_POST['about'] ) ? sanitize_textarea_field( wp_unslash( $_POST['about'] ) ) : '',
					)
				);
				break;
			case 'contact':
				$address = array();
				foreach ( array_keys( self::address_parts() ) as $part ) {
					$address[ $part ] = $text( 'address_' . $part );
				}
				$result = self::save_contact(
					$user,
					array(
						'phone'         => $text( 'phone' ),
						'address'       => $address,
						'share_phone'   => $list( 'share_phone' ),
						'share_address' => $list( 'share_address' ),
						'swag'          => ! empty( $_POST['swag'] ),
					)
				);
				break;
			case 'mail':
				$result = self::save_mail(
					$user,
					array(
						'teams' => $list( 'teams' ),
						'news'  => ! empty( $_POST['news'] ),
					)
				);
				break;
			case 'email':
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is used as typed.
				$result = self::request_email( $user, $text( 'email' ), isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '' );
				break;
			case 'email_confirm':
				$result = self::confirm_email( $token, $user );
				break;
			case 'password':
				$person = get_userdata( $user );
				$result = $person ? Sign_In::forgot( $person->user_email ) : null;
				break;
			case 'spot_edit':
				$spot  = isset( $_POST['spot'] ) ? (int) $_POST['spot'] : 0;
				$input = array(
					'credit_team'  => isset( $_POST['spot_credit_team'] ) ? (int) $_POST['spot_credit_team'] : 0,
					'credit_named' => ! empty( $_POST['spot_credit_named'] ),
				);
				foreach ( array( 'title', 'category', 'landmark', 'lat', 'lng' ) as $field ) {
					if ( isset( $_POST[ $field ] ) ) {
						$input[ $field ] = $text( $field );
					}
				}
				if ( isset( $_POST['description'] ) ) {
					$input['description'] = sanitize_textarea_field( wp_unslash( $_POST['description'] ) );
				}
				$result = self::save_spot( $user, $spot, $input );
				break;
			case 'feature_save':
				$id     = isset( $_POST['feature'] ) ? (int) $_POST['feature'] : 0;
				$result = Features::save(
					$id,
					array(
						'name'        => $text( 'name' ),
						'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
						'kind'        => $text( 'kind' ),
						'color'       => $text( 'color' ),
						'icon'        => $text( 'icon' ),
						'geometry'    => isset( $_POST['geometry'] ) ? wp_unslash( $_POST['geometry'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Features::parse_geometry() reads JSON.
						'place'       => isset( $_POST['place'] ) ? (int) $_POST['place'] : 0,
					),
					$user
				);
				break;
			case 'feature_trash':
				$result = Features::trash( isset( $_POST['feature'] ) ? (int) $_POST['feature'] : 0, $user );
				break;
			case 'feature_adopt':
				$feature = isset( $_POST['feature'] ) ? (int) $_POST['feature'] : 0;
				$place   = isset( $_POST['place'] ) ? (int) $_POST['place'] : Features::place( $feature );
				if ( $feature > 0 && $place > 0 && Features::can_edit( $feature, $user ) && Features::place( $feature ) !== $place ) {
					$linked = Features::save( $feature, array( 'place' => $place ), $user );
					if ( is_wp_error( $linked ) ) {
						$result = $linked;
						break;
					}
				}
				$result = Places::request_adoption(
					$place,
					isset( $_POST['team'] ) ? (int) $_POST['team'] : 0,
					$user,
					array(
						'note'       => $text( 'note' ),
						'feature_id' => $feature,
					)
				);
				break;
			case 'note_add':
			case 'note_save':
			case 'note_remove':
				$spot   = isset( $_POST['spot'] ) ? (int) $_POST['spot'] : 0;
				$note   = Updates::get( isset( $_POST['note'] ) ? (int) $_POST['note'] : 0, true );
				$words  = isset( $_POST['text'] ) ? (string) wp_unslash( $_POST['text'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Updates cleans it.
				$public = ! empty( $_POST['public'] );
				if ( ! self::own_spot( $user, $spot ) || ( 'note_add' !== $do && ( ! $note || (int) $note->post_parent !== $spot || (int) $note->post_author !== $user ) ) ) {
					$result = new \WP_Error( 'packout_forbidden', __( 'You can keep notes on the Spots you reported.', 'packout-core' ), array( 'status' => 403 ) );
				} elseif ( 'note_add' === $do ) {
					$result = Updates::note( $spot, $user, $words, $public );
					$result = is_wp_error( $result ) ? $result : true;
				} elseif ( 'note_save' === $do ) {
					$result = Updates::edit( $note->ID, $user, $words, $public );
				} else {
					$result = Updates::remove( $note->ID, $user );
				}
				break;
		}//end switch
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( null === $result ) {
			return;
		}
		if ( is_wp_error( $result ) ) {
			self::$problem = $result;
			self::$failed  = $do;
			if ( isset( self::SPOT_FORMS[ $do ] ) ) {
				// The form is in the Spot's pane, which opens again with what was wrong.
				self::$spot = $spot;
			}
			if ( 'contributions' === ( self::PART_OF[ $do ] ?? '' ) ) {
				self::$feature = isset( $_POST['feature'] ) ? (int) $_POST['feature'] : 0;
			}
			return;
		}
		if ( isset( self::SPOT_FORMS[ $do ] ) ) {
			Accounts::go(
				self::url(
					array(
						'done' => $do,
						'spot' => (string) $spot,
					),
					'impact'
				) . '#pos-pane-spot-' . $spot
			);
		}
		if ( 'contributions' === ( self::PART_OF[ $do ] ?? '' ) ) {
			$feature = isset( $_POST['feature'] ) ? (int) $_POST['feature'] : ( is_int( $result ) ? $result : 0 );
			Accounts::go(
				self::url( array( 'done' => $do ), 'contributions' ) . ( $feature > 0 && 'feature_trash' !== $do ? '#pos-pane-feature-' . $feature : '#pos-account-features' )
			);
		}
		Accounts::go( self::url( array( 'done' => $do ), self::PART_OF[ $do ] ?? '' ) . '#pos-account-' . ( 'email_confirm' === $do ? 'email' : $do ) );
	}

	/**
	 * What went wrong with the form that was just sent.
	 *
	 * @var \WP_Error|null
	 */
	private static ?\WP_Error $problem = null;

	/**
	 * The form that failed, so what was typed can be shown again.
	 *
	 * @var string
	 */
	private static string $failed = '';

	/**
	 * The reported Spot whose form failed, so its pane opens again.
	 *
	 * @var int
	 */
	private static int $spot = 0;

	/**
	 * The map feature whose form failed, so its pane opens again.
	 *
	 * @var int
	 */
	private static int $feature = 0;

	/**
	 * What was typed into the form that failed, or the stored value.
	 *
	 * @param string $act      The form's action.
	 * @param string $name    Field name.
	 * @param string $fallback Stored value.
	 */
	private static function old( string $act, string $name, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Showing back what the person typed.
		return self::$failed === $act && isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) ) : $fallback;
	}

	/**
	 * The boxes ticked in the form that failed.
	 *
	 * @param string $name Field name.
	 * @return array<int, int>
	 */
	private static function old_list( string $name ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Showing back what the person chose.
		return isset( $_POST[ $name ] ) && is_array( $_POST[ $name ] ) ? array_values( array_map( 'intval', wp_unslash( $_POST[ $name ] ) ) ) : array();
	}

	/**
	 * Open a form that posts to the page of the part it belongs to.
	 *
	 * @param string $act    Action name.
	 * @param string $token Token to carry, or an empty string.
	 */
	private static function open( string $act, string $token = '' ): string {
		$html = '<form method="post" class="pos-ws__form" action="' . esc_url( self::url( array(), self::PART_OF[ $act ] ?? '' ) ) . '"><input type="hidden" name="packout_profile" value="' . esc_attr( $act ) . '" />';
		if ( '' !== $token ) {
			$html .= '<input type="hidden" name="token" value="' . esc_attr( $token ) . '" />';
		}
		return $html . ( is_user_logged_in() ? wp_nonce_field( self::NONCE, '_posnonce', false, false ) : '' );
	}

	/**
	 * The My account block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		// The block names its part. On a site with one My account page, the address names it.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the address to choose what to show.
		$view = self::view( $attributes['view'] ?? '' );
		$view = '' !== $view ? $view : self::view( isset( $_GET['view'] ) ? wp_unslash( $_GET['view'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- view() cleans it.
		$html = '<div ' . Ui::wrapper( 'pos-ws pos-profile' . ( '' !== $view ? ' pos-profile--' . $view : ' pos-profile--overview' ) ) . '>';
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// The editor's preview. The page itself depends on who is looking.
			$says = array(
				''              => __( 'The My account overview appears here for signed-in people, with a link to each part: personal info, my impact, my contributions, my organizations, email preferences, and sign-in details.', 'packout-core' ),
				'personal'      => __( 'Personal info appears here for signed-in people: their name, a few lines about them, and their phone number and mailing address with who sees each.', 'packout-core' ),
				'impact'        => __( 'My impact appears here for signed-in people: the Spots they reported, their cleanups and return visits, and their missions.', 'packout-core' ),
				'contributions' => __( 'My contributions appears here for signed-in people: the map features they added.', 'packout-core' ),
				'email'         => __( 'Email preferences appear here for signed-in people: which organizations may email them, and whether they get news.', 'packout-core' ),
				'signin'        => __( 'Sign-in details appear here for signed-in people: their sign-in email and the link to choose a new password.', 'packout-core' ),
			);
			return $html . Accounts::notice( 'info', $says[ $view ] ) . '</div>';
		}
		wp_enqueue_style( 'packout-workspace' );
		wp_enqueue_script( 'packout-workspace' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the address to choose what to show.
		$quiet   = isset( $_GET['pos_quiet'] ) ? sanitize_text_field( wp_unslash( $_GET['pos_quiet'] ) ) : '';
		$change  = isset( $_GET['pos_email'] ) ? sanitize_text_field( wp_unslash( $_GET['pos_email'] ) ) : '';
		$done    = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		$problem = isset( $_GET['problem'] ) ? sanitize_key( wp_unslash( $_GET['problem'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$user = get_current_user_id();

		if ( 'link' === $problem ) {
			$html .= Accounts::notice( 'warning', __( 'That link has expired or is incomplete. Sign in to change what you are sent.', 'packout-core' ) );
		}

		// The link in a team's message. It needs no sign-in, and shows nothing about the person.
		if ( '' !== $quiet ) {
			$who  = self::read_quiet( $quiet );
			$team = $who ? Teams::get( $who['team'] ) : null;
			if ( ! $who || ! $team ) {
				$html .= Accounts::notice( 'warning', __( 'That link has expired or is incomplete. Sign in to change what you are sent.', 'packout-core' ) );
			} elseif ( 'quiet' === $done || ! self::wants_team_mail( $who['user'], $who['team'] ) ) {
				/* translators: %s: organization name. */
				$html .= Accounts::notice( 'success', sprintf( __( 'Done. %s will not email you its messages. You are still in the organization, and its messages are in My organizations.', 'packout-core' ), $team->post_title ) );
				$html .= '<p>' . esc_html__( 'Changed your mind? Sign in and turn them back on under My account, Email preferences.', 'packout-core' ) . '</p>';
			} else {
				/* translators: %s: organization name. */
				$html .= '<h2>' . esc_html( sprintf( __( 'Stop messages from %s?', 'packout-core' ), $team->post_title ) ) . '</h2>';
				$html .= '<p>' . esc_html__( 'You stay in the organization. Its leads can still write to the organization, and you can read what they send in My organizations. It just stops arriving by email.', 'packout-core' ) . '</p>';
				$html .= self::open( 'quiet', $quiet ) . Workspace::submit( __( 'Stop the emails', 'packout-core' ) ) . '</form>';
			}
			if ( $user <= 0 ) {
				return $html . '</div>';
			}
			$html .= '<hr class="pos-profile__rule" />';
		}

		if ( $user <= 0 ) {
			$html .= '<p>' . esc_html__( 'My account is where you see what you have done, tell your organizations about yourself, and choose what you are sent.', 'packout-core' ) . '</p>';
			$html .= '<p class="pos-ws__actions"><a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( Accounts::signin_url( Accounts::current_url() ) ) . '">' . esc_html__( 'Sign in', 'packout-core' ) . '</a>';
			if ( Accounts::open() ) {
				$html .= ' <a class="pos-ws__button" href="' . esc_url( Accounts::signup_url( self::url() ) ) . '">' . esc_html__( 'Sign up', 'packout-core' ) . '</a>';
			}
			return $html . '</p></div>';
		}

		$person  = wp_get_current_user();
		$profile = self::get( $user );
		$teams   = self::teams( $user );

		if ( self::$problem ) {
			$html .= Accounts::notice( 'error', self::$problem->get_error_message() );
		}
		$said = array(
			'about'         => __( 'Saved.', 'packout-core' ),
			'contact'       => __( 'Contact details saved.', 'packout-core' ),
			'mail'          => __( 'Email choices saved.', 'packout-core' ),
			'email'         => __( 'Check the new address. We sent a link to confirm it. Your sign-in email stays as it is until you use the link.', 'packout-core' ),
			'email_confirm' => __( 'Your sign-in email is changed. We told the old address.', 'packout-core' ),
			'password'      => __( 'Check your email. We sent a link to choose a new password. It is good for one day.', 'packout-core' ),
		) + self::spot_said() + array(
			'feature_save'  => __( 'Saved.', 'packout-core' ),
			'feature_trash' => __( 'The map feature is removed.', 'packout-core' ),
			'feature_adopt' => __( 'The request was sent. The organization\'s lead will see it under Submissions.', 'packout-core' ),
		);
		if ( isset( $said[ $done ] ) && ! self::$problem ) {
			$html .= Accounts::notice( 'success', $said[ $done ] );
		}

		$html .= self::nav( $view );

		// The link sent to a new address, opened by the person who asked.
		if ( '' !== $change ) {
			$email = self::read_email( $change, $user );
			if ( '' === $email ) {
				if ( 'email_confirm' !== $done ) {
					$html .= Accounts::notice( 'warning', __( 'That link has expired, has been used, or was made for another account. Ask again under Sign-in details.', 'packout-core' ) );
				}
			} else {
				$html .= '<section class="pos-ws__card"><h2>' . esc_html__( 'Change your sign-in email?', 'packout-core' ) . '</h2>';
				/* translators: 1: current email address, 2: new email address. */
				$html .= '<p>' . esc_html( sprintf( __( 'You sign in with %1$s now. Confirm, and you sign in with %2$s from now on.', 'packout-core' ), $person->user_email, $email ) ) . '</p>';
				$html .= self::open( 'email_confirm', $change ) . Workspace::submit( __( 'Change my sign-in email', 'packout-core' ) ) . '</form></section>';
			}
		}

		// A page with more than one section shows one at a time, chosen from tabs down its left.
		$section = static fn( string $id, string $label, string $body ): array => array(
			'id'    => 'pos-account-' . $id,
			'label' => $label,
			'html'  => $body,
		);
		// The section whose form was refused is the one to show.
		$first = self::$problem && '' !== self::$failed ? 'pos-account-' . self::$failed : '';
		$views = self::views();
		switch ( $view ) {
			case 'personal':
				$html .= Workspace::sections(
					$views[ $view ],
					array(
						$section( 'about', __( 'About you', 'packout-core' ), self::about_html( $person, $profile ) ),
						$section( 'contact', __( 'Contact details', 'packout-core' ), self::contact_html( $profile, $teams ) ),
					),
					'side',
					$first,
					2
				);
				break;
			case 'impact':
				// The badges of a mission are components.
				Ui::enqueue();
				// A reported Spot shows its photos in the slider of its public page.
				wp_enqueue_style( 'packout-blocks' );
				wp_enqueue_script( 'packout-slider' );
				// The Spot whose form was just sent is the one to show: its list, and its pane.
				$acted = self::acted_spot();
				if ( $acted > 0 && self::$problem ) {
					Workspace_Lists::reopen( 'spot-' . $acted );
				}
				$html .= Workspace::sections( $views[ $view ], self::contributions_sections( $user ), 'side', $acted > 0 ? 'pos-impact-spots' : '', 2 );
				// A Spot, a mission, or a record opens in the drawer.
				Workspace_Lists::enqueue();
				$html .= Workspace_Lists::panes();
				break;
			case 'contributions':
				Features::enqueue_editor();
				Workspace_Lists::enqueue();
				if ( self::$feature > 0 && self::$problem ) {
					Workspace_Lists::reopen( 'feature-' . self::$feature );
				}
				$html .= Workspace::sections( $views[ $view ], self::features_sections( $user ), 'side', '', 2 );
				$html .= Workspace_Lists::panes();
				break;
			case 'email':
				$html .= Workspace::sections( $views[ $view ], array( $section( 'mail', __( 'Email', 'packout-core' ), self::mail_html( $profile, $teams ) ) ), 'side', '', 2 );
				break;
			case 'signin':
				$html .= Workspace::sections(
					$views[ $view ],
					array(
						$section( 'email', __( 'Sign-in email', 'packout-core' ), self::email_html( $person ) ),
						$section( 'password', __( 'Password', 'packout-core' ), self::password_html() ),
					),
					'side',
					$first,
					2
				);
				break;
			default:
				$html .= self::overview_html( $person, $profile, $teams );
		}//end switch
		$html .= '<p class="pos-ws__who">' . esc_html(
			/* translators: %s: the person's name. */
			sprintf( __( 'Signed in as %s.', 'packout-core' ), $person->display_name )
		) . ' <a href="' . esc_url( Sign_In::out_url() ) . '">' . esc_html__( 'Sign out', 'packout-core' ) . '</a></p>';
		return $html . '</div>';
	}

	/**
	 * The links between the parts of My account. Sign out is last.
	 *
	 * @param string $current The part being shown.
	 */
	private static function nav( string $current ): string {
		$html = '<nav class="pos-ws__tabs pos-profile__nav" aria-label="' . esc_attr__( 'My account', 'packout-core' ) . '"><ul>';
		$item = static function ( string $url, string $label, bool $here = false ): string {
			return '<li><a href="' . esc_url( $url ) . '"' . ( $here ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		};
		foreach ( self::views() as $view => $label ) {
			$html .= $item( self::url( array(), $view ), $label, $view === $current );
			if ( 'contributions' === $view && (int) Settings::get( 'workspace_page' ) > 0 ) {
				$html .= $item( Accounts::workspace_url(), __( 'My organizations', 'packout-core' ) );
			}
		}
		return $html . $item( Sign_In::out_url(), __( 'Sign out', 'packout-core' ) ) . '</ul></nav>';
	}

	/**
	 * The overview: one card for each part of My account, with what it holds now.
	 *
	 * @param \WP_User             $person  The person.
	 * @param array<string, mixed> $profile From get().
	 * @param array<int, string>   $teams   The person's teams.
	 */
	private static function overview_html( \WP_User $person, array $profile, array $teams ): string {
		$done   = self::contributions( $person->ID );
		$shared = count( array_unique( array_merge( $profile['share_phone'], $profile['share_address'] ) ) );
		$heard  = count( array_diff( array_keys( $teams ), $profile['quiet'] ) );

		$details = array();
		if ( '' !== $profile['phone'] ) {
			$details[] = __( 'a phone number', 'packout-core' );
		}
		if ( self::has_address( $profile['address'] ) ) {
			$details[] = __( 'a mailing address', 'packout-core' );
		}
		if ( ! $details ) {
			$contact = __( 'No phone number or mailing address yet. Both are optional.', 'packout-core' );
		} else {
			/* translators: %s: what is on file, for example "a phone number and a mailing address". */
			$contact = sprintf( __( 'On file: %s.', 'packout-core' ), wp_sprintf( '%l', $details ) ) . ' ' . ( $shared > 0
				/* translators: %s: number of organizations. */
				? sprintf( _n( 'Shared with the leads of %s organization.', 'Shared with the leads of %s organizations.', $shared, 'packout-core' ), number_format_i18n( $shared ) )
				: __( 'Shared with nobody.', 'packout-core' ) );
		}

		$cards = array(
			array(
				self::url( array(), 'personal' ),
				__( 'Personal info', 'packout-core' ),
				array( $person->display_name, $contact ),
				__( 'Change your name and details', 'packout-core' ),
			),
			array(
				self::url( array(), 'impact' ),
				__( 'My impact', 'packout-core' ),
				array(
					implode(
						' · ',
						array(
							/* translators: %s: number of Spots. */
							sprintf( _n( '%s Spot reported', '%s Spots reported', count( $done['spots'] ), 'packout-core' ), number_format_i18n( count( $done['spots'] ) ) ),
							/* translators: %s: number of cleanups. */
							sprintf( _n( '%s cleanup', '%s cleanups', count( $done['cleanups'] ), 'packout-core' ), number_format_i18n( count( $done['cleanups'] ) ) ),
							/* translators: %s: number of missions. */
							sprintf( _n( '%s mission', '%s missions', count( $done['missions'] ), 'packout-core' ), number_format_i18n( count( $done['missions'] ) ) ),
						)
					),
					$done['waiting']
						/* translators: %s: number of cleanups. */
						? sprintf( _n( '%s cleanup is waiting for your photo.', '%s cleanups are waiting for your photo.', count( $done['waiting'] ), 'packout-core' ), number_format_i18n( count( $done['waiting'] ) ) )
						: '',
				),
				__( 'See what you have done', 'packout-core' ),
			),
			array(
				self::url( array(), 'contributions' ),
				__( 'My contributions', 'packout-core' ),
				array(
					sprintf(
						/* translators: %s: number of map features. */
						_n( '%s map feature', '%s map features', count( $done['features'] ), 'packout-core' ),
						number_format_i18n( count( $done['features'] ) )
					),
				),
				__( 'See the places you have added to the map', 'packout-core' ),
			),
		);
		if ( (int) Settings::get( 'workspace_page' ) > 0 ) {
			$cards[] = array(
				Accounts::workspace_url(),
				__( 'My organizations', 'packout-core' ),
				array( $teams ? implode( ', ', $teams ) : __( 'You are not in an organization yet. Join one, or start your own.', 'packout-core' ) ),
				$teams ? __( 'Open your organizations', 'packout-core' ) : __( 'Find or start an organization', 'packout-core' ),
			);
		}
		$cards[] = array(
			self::url( array(), 'email' ),
			__( 'Email preferences', 'packout-core' ),
			array(
				$teams
					/* translators: 1: number of organizations that may email the person, 2: number of organizations the person is on. */
					? sprintf( __( 'Organization messages: on for %1$s of %2$s organizations.', 'packout-core' ), number_format_i18n( $heard ), number_format_i18n( count( $teams ) ) )
					: '',
				$profile['news'] ? __( 'News from Pack-Out Spot: on.', 'packout-core' ) : __( 'News from Pack-Out Spot: off.', 'packout-core' ),
			),
			__( 'Choose what you are sent', 'packout-core' ),
		);
		$cards[] = array(
			self::url( array(), 'signin' ),
			__( 'Sign-in details', 'packout-core' ),
			/* translators: %s: email address. */
			array( sprintf( __( 'You sign in with %s.', 'packout-core' ), $person->user_email ) ),
			__( 'Change your email or password', 'packout-core' ),
		);

		$html = '<ul class="pos-profile__cards">';
		foreach ( $cards as $card ) {
			$html .= '<li class="pos-ws__card pos-profile__card"><h2>' . esc_html( $card[1] ) . '</h2>';
			foreach ( array_filter( $card[2] ) as $line ) {
				$html .= '<p>' . esc_html( $line ) . '</p>';
			}
			$html .= '<p class="pos-profile__go"><a href="' . esc_url( $card[0] ) . '">' . esc_html( $card[3] ) . '</a></p></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * What the person has done: the figures, then a section for each list.
	 *
	 * A mission and a record open in the drawer. A Spot opens its public page.
	 *
	 * @param int $user User ID.
	 * @return array<int, array{id: string, label: string, html: string}>
	 */
	private static function contributions_sections( int $user ): array {
		$done     = self::contributions( $user );
		$statuses = Schema::statuses();
		$number   = static fn( $value ): string => Impact::number( (float) $value );
		$stats    = array(
			__( 'Spots reported', 'packout-core' )       => number_format_i18n( count( $done['spots'] ) ),
			__( 'Cleanups (pack-outs)', 'packout-core' ) => number_format_i18n( count( $done['cleanups'] ) ),
			__( 'Return visits', 'packout-core' )        => number_format_i18n( count( $done['visits'] ) ),
			__( 'Missions', 'packout-core' )             => number_format_i18n( count( $done['missions'] ) ),
			__( 'Hours', 'packout-core' )                => $number( $done['hours'] ),
			__( 'Bags', 'packout-core' )                 => number_format_i18n( $done['bags'] ),
			__( 'Pounds', 'packout-core' )               => $number( $done['pounds'] ),
		);

		$html = '<dl class="pos-profile__stats">';
		foreach ( $stats as $label => $value ) {
			$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		$html .= '</dl>';
		if ( ! $done['spots'] && ! $done['cleanups'] && ! $done['visits'] && ! $done['missions'] && ! $done['waiting'] ) {
			$report = (int) Settings::get( 'report_page' );
			$html  .= '<p>' . esc_html__( 'Nothing yet. Report a Spot, or pack one out and say so on its page.', 'packout-core' );
			if ( $report > 0 && 'publish' === get_post_status( $report ) ) {
				$html .= ' <a href="' . esc_url( (string) get_permalink( $report ) ) . '">' . esc_html__( 'Report a Spot', 'packout-core' ) . '</a>';
			}
			$html .= '</p>';
		} else {
			$html .= '<p class="pos-ws__help">' . esc_html__( 'Hours, bags, and pounds add up the cleanup records you are credited on, verified or not. On an organization record they are the whole crew\'s.', 'packout-core' ) . '</p>';
		}
		$sections = array(
			array(
				'id'    => 'pos-account-contributions',
				'label' => __( 'What you have done', 'packout-core' ),
				'html'  => $html,
			),
		);

		$row         = static fn( string $title, array $facts ): string => '<li><span>' . $title . '</span><span class="pos-ws__muted">' . esc_html( implode( ' · ', array_filter( $facts ) ) ) . '</span></li>';
		$spot_row    = static function ( \WP_Post $spot ) use ( $statuses, $row ): string {
			$title = esc_html( $spot->post_title );
			if ( 'publish' === $spot->post_status ) {
				$title = '<a href="' . esc_url( (string) get_permalink( $spot ) ) . '">' . $title . '</a>';
			}
			$facts = array( $statuses[ Spots::field( $spot->ID, 'status', 'spotted' ) ] ?? '', get_the_date( '', $spot ) );
			if ( 'pending' === $spot->post_status ) {
				$facts[] = __( 'waiting for review', 'packout-core' );
			}
			return $row( $title, $facts );
		};
		$record_row  = static function ( \WP_Post $record ) use ( $number, $row, $user ): string {
			$id    = $record->ID;
			$spot  = Spots::get( (int) Impact::field( $id, 'spot', '0' ) );
			$title = $spot ? $spot->post_title : __( 'A Spot that was removed', 'packout-core' );
			$facts = array( Workspace_Views::day( Impact::field( $id, 'date' ) ) );
			if ( Impact::CLEANUP === Impact::kind( $id ) ) {
				$bags = (int) Impact::field( $id, 'bags', '0' );
				if ( $bags > 0 ) {
					/* translators: %s: number of bags. */
					$facts[] = sprintf( _n( '%s bag', '%s bags', $bags, 'packout-core' ), number_format_i18n( $bags ) );
				}
				$pounds = (float) Impact::field( $id, 'pounds', '0' );
				if ( $pounds > 0 ) {
					/* translators: %s: number of pounds. */
					$facts[] = sprintf( __( '%s lb', 'packout-core' ), $number( $pounds ) );
				}
				$facts[] = Impact::verified( $id ) ? __( 'verified', 'packout-core' ) : __( 'not yet verified', 'packout-core' );
			} else {
				$facts[] = Impact::kinds()[ Impact::kind( $id ) ] ?? '';
			}
			// The details open in the drawer, and link to the Spot's public page.
			$pane = Workspace_Views::record_pane( $record, $user, false );
			return $row( '<a href="' . esc_attr( Workspace_Lists::link( $pane ) ) . '">' . esc_html( $title ) . '</a>', $facts );
		};
		$mission_row = static function ( \WP_Post $mission ) use ( $row, $user ): string {
			$team  = Teams::get( Missions::team( $mission->ID ) );
			$facts = array( $team ? $team->post_title : '', Missions::statuses()[ Missions::status( $mission->ID ) ] ?? '', Missions::window_label( $mission->ID ) );
			// The same details as the team's own pages show, without leaving My impact.
			$pane = Workspace_Views::mission_pane( $mission, $user, false );
			return $row( '<a href="' . esc_attr( Workspace_Lists::link( $pane ) ) . '">' . esc_html( $mission->post_title ) . '</a>', $facts );
		};

		$lists = array(
			'waiting'  => array( __( 'Waiting for your photo', 'packout-core' ), $done['waiting'], $spot_row, __( 'You reported cleaning these. Add a photo on the Spot\'s page and it is marked cleaned.', 'packout-core' ) ),
			'spots'    => array( __( 'Reported Spots', 'packout-core' ), $done['spots'], null, __( 'Open a Spot to see where it stands, change your report, and keep notes on it.', 'packout-core' ) ),
			'cleanups' => array( __( 'Cleanups', 'packout-core' ), $done['cleanups'], $record_row, __( 'Open a record to see what was written up.', 'packout-core' ) ),
			'visits'   => array( __( 'Return visits', 'packout-core' ), $done['visits'], $record_row, __( 'Open a record to see what was written up.', 'packout-core' ) ),
			'missions' => array( __( 'Missions', 'packout-core' ), $done['missions'], $mission_row, __( 'Open a mission to see its details, its Spots, and its resources.', 'packout-core' ) ),
		);
		foreach ( $lists as $key => $list ) {
			if ( ! $list[1] ) {
				continue;
			}
			$shown = array_slice( $list[1], 0, 100 );
			$body  = '<p class="pos-ws__help">' . esc_html( $list[3] ) . '</p>' . ( 'spots' === $key ? self::spots_view( $shown, $user ) : '<ul class="pos-ws__rows">' . implode( '', array_map( $list[2], $shown ) ) . '</ul>' );
			if ( count( $list[1] ) > count( $shown ) ) {
				$body .= '<p class="pos-ws__muted">' . esc_html(
					/* translators: %s: number of items. */
					sprintf( __( 'The newest %s are listed.', 'packout-core' ), number_format_i18n( count( $shown ) ) )
				) . '</p>';
			}
			$sections[] = array(
				'id'    => 'pos-impact-' . $key,
				/* translators: 1: name of a list, 2: number of items in it. */
				'label' => sprintf( __( '%1$s (%2$s)', 'packout-core' ), $list[0], number_format_i18n( count( $list[1] ) ) ),
				'html'  => $body,
			);
		}
		return $sections;
	}

	/**
	 * What My impact says after a form of a reported Spot went through.
	 *
	 * @return array<string, string>
	 */
	private static function spot_said(): array {
		return array(
			'spot_edit'   => __( 'Saved.', 'packout-core' ),
			'note_add'    => __( 'The note is added.', 'packout-core' ),
			'note_save'   => __( 'The note is saved.', 'packout-core' ),
			'note_remove' => __( 'The note is removed.', 'packout-core' ),
		);
	}

	/**
	 * The reported Spot whose form was just sent, whether it went through or not.
	 */
	private static function acted_spot(): int {
		if ( self::$spot > 0 ) {
			return self::$spot;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the address to choose what to show.
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		return isset( self::SPOT_FORMS[ $done ] ) && isset( $_GET['spot'] ) ? (int) $_GET['spot'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Where a report stands with the coordinators, in words.
	 *
	 * @param \WP_Post $spot Spot.
	 */
	public static function review_of( \WP_Post $spot ): string {
		if ( 'publish' === $spot->post_status ) {
			return __( 'On the map', 'packout-core' );
		}
		if ( 'private' === $spot->post_status ) {
			return __( 'Kept off the public map', 'packout-core' );
		}
		return '' !== Spots::field( $spot->ID, 'return_reason' ) ? __( 'Returned to you', 'packout-core' ) : __( 'Waiting for review', 'packout-core' );
	}

	/**
	 * The Spots a person reported, as a Data View: a table, cards with the photo across the top, or a list.
	 *
	 * Each opens in the drawer.
	 *
	 * @param array<int, \WP_Post> $spots Spots, newest first.
	 * @param int                  $user  User ID.
	 */
	private static function spots_view( array $spots, int $user ): string {
		$statuses = Schema::statuses();
		$rows     = array();
		foreach ( $spots as $spot ) {
			$id       = $spot->ID;
			$photos   = Spots::gallery( $spot, true );
			$category = Spots::category( $id );
			$notes    = Updates::mine( $id, $user );
			$rows[]   = array(
				'id'       => (string) $id,
				'title'    => $spot->post_title,
				'url'      => Workspace_Lists::link( self::spot_pane( $spot, $user, $photos, $notes ) ),
				'photo'    => $photos ? $photos[0]['url'] : '',
				'category' => $category ? $category->name : '',
				'stage'    => $statuses[ Spots::field( $id, 'status', 'spotted' ) ] ?? '',
				'review'   => self::review_of( $spot ),
				'reported' => (string) get_the_date( '', $spot ),
				'notes'    => count( $notes ),
			);
		}
		return Workspace_Lists::view(
			'my-spots',
			array(
				'photo'    => array(
					'label' => __( 'Photo', 'packout-core' ),
					'type'  => 'media',
				),
				'category' => array(
					'label' => __( 'Category', 'packout-core' ),
					'sort'  => true,
				),
				'stage'    => array(
					'label' => __( 'Stage', 'packout-core' ),
					'sort'  => true,
				),
				'review'   => array(
					'label' => __( 'Review', 'packout-core' ),
					'sort'  => true,
				),
				'reported' => array(
					'label'  => __( 'Reported', 'packout-core' ),
					'search' => false,
				),
				'notes'    => array(
					'label' => __( 'Notes', 'packout-core' ),
					'type'  => 'integer',
					'sort'  => true,
				),
			),
			$rows,
			array(
				'title'  => __( 'Spot', 'packout-core' ),
				'layout' => 'table',
				'media'  => 'photo',
				'photos' => true,
			)
		);
	}

	/**
	 * The map features the person added.
	 *
	 * @param int $user User ID.
	 * @return array<int, array{id: string, label: string, html: string}>
	 */
	private static function features_sections( int $user ): array {
		$features = Features::of_user( $user );
		$html     = '<p class="pos-ws__help">' . esc_html__( 'Points, routes, and boundaries you have added to the public map. Open one to change it, link it to a Place, or ask an organization to adopt that Place.', 'packout-core' ) . '</p>';
		$html    .= '<p class="pos-ws__actions"><button type="button" class="pos-ws__button pos-ws__button--primary" data-pos-feature-edit="1">' . esc_html__( 'Add a map feature', 'packout-core' ) . '</button></p>';
		if ( ! $features ) {
			$html .= '<p>' . esc_html__( 'None yet. Draw a point, a route, or a boundary on the map.', 'packout-core' ) . '</p>';
		} else {
			$html .= self::features_view( $features, $user );
		}
		return array(
			array(
				'id'    => 'pos-account-features',
				'label' => sprintf(
					/* translators: %s: number of map features. */
					__( 'Map features (%s)', 'packout-core' ),
					number_format_i18n( count( $features ) )
				),
				'html'  => $html,
			),
		);
	}

	/**
	 * The person's map features as a Data View.
	 *
	 * @param array<int, \WP_Post> $features Features, newest first.
	 * @param int                  $user     User ID.
	 */
	private static function features_view( array $features, int $user ): string {
		$kinds = Features::kinds();
		$icons = Features::icons();
		$rows  = array();
		foreach ( $features as $feature ) {
			$id     = $feature->ID;
			$place  = Features::place( $id );
			$held   = $place ? Places::get( $place ) : null;
			$icon   = Features::icon( $id );
			$rows[] = array(
				'id'      => (string) $id,
				'title'   => $feature->post_title,
				'url'     => Workspace_Lists::link( self::feature_pane( $feature, $user ) ),
				'kind'    => $kinds[ Features::kind( $id ) ] ?? Features::kind( $id ),
				'color'   => Features::color( $id ),
				'icon'    => $icon && isset( $icons[ $icon ] ) ? $icons[ $icon ] : '',
				'place'   => $held ? $held->post_title : '',
				'updated' => (string) get_the_modified_date( '', $feature ),
			);
		}
		return Workspace_Lists::view(
			'my-features',
			array(
				'kind'    => array(
					'label' => __( 'Kind', 'packout-core' ),
					'sort'  => true,
				),
				'color'   => array(
					'label' => __( 'Color', 'packout-core' ),
				),
				'icon'    => array(
					'label' => __( 'Icon', 'packout-core' ),
					'sort'  => true,
				),
				'place'   => array(
					'label' => __( 'Place', 'packout-core' ),
					'sort'  => true,
				),
				'updated' => array(
					'label'  => __( 'Updated', 'packout-core' ),
					'search' => false,
				),
			),
			$rows,
			array(
				'title'       => __( 'Feature', 'packout-core' ),
				'layout'      => 'table',
				'description' => 'kind',
				'empty'       => __( 'None yet.', 'packout-core' ),
			)
		);
	}

	/**
	 * Keep the pane of a map feature, and name it.
	 *
	 * @param \WP_Post $feature Feature.
	 * @param int      $user    User ID.
	 */
	private static function feature_pane( \WP_Post $feature, int $user ): string {
		$id   = $feature->ID;
		$pane = 'feature-' . $id;
		if ( Workspace_Lists::has_pane( $pane ) ) {
			return $pane;
		}

		$mine    = self::$failed && self::$feature === $id;
		$old     = static fn( string $act, string $name, string $stored = '' ): string => $mine && self::$failed === $act ? self::old( $act, $name, $stored ) : $stored;
		$kind    = Features::kind( $id );
		$payload = Features::payload( $feature );
		$places  = array( '0' => __( 'None', 'packout-core' ) );
		foreach ( Places::all( ! Places::can_manage( $user ) ) as $place ) {
			$places[ (string) $place->ID ] = $place->post_title;
		}
		$teams = array();
		foreach ( Teams::of_user( $user ) as $team_id ) {
			$team = Teams::get( $team_id );
			if ( $team && Teams::active( $team_id ) ) {
				$teams[ (string) $team_id ] = $team->post_title;
			}
		}

		$html  = '<p class="pos-ws__help">' . esc_html( Features::kinds()[ $kind ] ?? $kind ) . '</p>';
		$html .= '<p class="pos-ws__actions"><button type="button" class="pos-ws__button pos-ws__button--primary" data-pos-feature-edit="' . esc_attr( (string) wp_json_encode( $payload ) ) . '">' . esc_html__( 'Edit on map', 'packout-core' ) . '</button></p>';

		$html .= self::open( 'feature_save' ) . '<input type="hidden" name="feature" value="' . esc_attr( (string) $id ) . '" />';
		$html .= '<input type="hidden" name="kind" value="' . esc_attr( $kind ) . '" />';
		$html .= Workspace::input( 'name', __( 'Name', 'packout-core' ), $old( 'feature_save', 'name', $feature->post_title ), array( 'maxlength' => '120' ) );
		$html .= Workspace::area( 'description', __( 'Note', 'packout-core' ), $old( 'feature_save', 'description', $feature->post_content ), __( 'Shown on the map when someone opens this feature.', 'packout-core' ), 3 );
		$html .= Workspace::choose( 'color', __( 'Color', 'packout-core' ), Features::colors(), $old( 'feature_save', 'color', Features::color( $id ) ) );
		if ( Features::KIND_POINT === $kind ) {
			$icons   = Features::icons();
			$current = $old( 'feature_save', 'icon', Features::icon( $id ) );
			if ( '' !== $current && ! isset( $icons[ $current ] ) ) {
				$icons[ $current ] = $current;
			}
			$html .= Workspace::choose( 'icon', __( 'Icon', 'packout-core' ), $icons, $current );
		}
		$html .= Workspace::choose( 'place', __( 'Place', 'packout-core' ), $places, $old( 'feature_save', 'place', (string) Features::place( $id ) ), __( 'Optional. Links this feature to a stewardship Place.', 'packout-core' ) );
		$html .= Workspace::submit( __( 'Save', 'packout-core' ) ) . '</form>';

		$place = (int) $old( 'feature_adopt', 'place', (string) Features::place( $id ) );
		if ( $teams ) {
			$html .= '<h3>' . esc_html__( 'Ask an organization to adopt the Place', 'packout-core' ) . '</h3>';
			$html .= '<p class="pos-ws__help">' . esc_html__( 'The organization’s lead sees the request under Submissions. Accepting it records the adoption.', 'packout-core' ) . '</p>';
			$html .= self::open( 'feature_adopt' ) . '<input type="hidden" name="feature" value="' . esc_attr( (string) $id ) . '" />';
			$html .= Workspace::choose( 'place', __( 'Place', 'packout-core' ), $places, (string) $place );
			$html .= Workspace::choose( 'team', __( 'Organization', 'packout-core' ), $teams, $old( 'feature_adopt', 'team' ) );
			$html .= Workspace::input( 'note', __( 'Note', 'packout-core' ), $old( 'feature_adopt', 'note' ), array( 'maxlength' => '300' ) );
			$html .= Workspace::submit( __( 'Send the request', 'packout-core' ) ) . '</form>';
		}

		$html .= self::open( 'feature_trash' ) . '<input type="hidden" name="feature" value="' . esc_attr( (string) $id ) . '" />';
		$html .= '<button class="pos-ws__button pos-ws__button--danger"' . Workspace::confirm( __( 'Remove this map feature?', 'packout-core' ), __( 'It comes off the public map.', 'packout-core' ), __( 'Remove it', 'packout-core' ) ) . '>' . esc_html__( 'Remove', 'packout-core' ) . '</button></form>';

		Workspace_Lists::pane( $pane, $feature->post_title, $html );
		return $pane;
	}

	/**
	 * Keep the pane of a reported Spot, and name it.
	 *
	 * Three parts: what the report says and where it stands, the form that
	 * changes it, and the person's notes on it.
	 *
	 * @param \WP_Post                         $spot   Spot.
	 * @param int                              $user   User ID.
	 * @param array<int, array<string, mixed>> $photos From Spots::gallery(), as its reporter sees it.
	 * @param array<int, \WP_Post>             $notes  From Updates::mine().
	 */
	private static function spot_pane( \WP_Post $spot, int $user, array $photos, array $notes ): string {
		$id   = $spot->ID;
		$pane = 'spot-' . $id;
		if ( Workspace_Lists::has_pane( $pane ) ) {
			return $pane;
		}
		$statuses = Schema::statuses();
		$category = Spots::category( $id );
		$shown    = 'publish' === $spot->post_status;
		$pending  = 'pending' === $spot->post_status;
		$acted    = self::acted_spot() === $id;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the address to choose what to show.
		$done   = $acted && ! self::$problem && isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		$part   = $acted ? ( self::SPOT_FORMS[ self::$problem ? self::$failed : $done ] ?? '' ) : '';
		$prefix = 'pos-spot-' . $id . '-';

		// What the report says, and where it stands.
		$reason = $pending ? Spots::field( $id, 'return_reason' ) : '';
		$detail = '';
		if ( '' !== $reason ) {
			/* translators: %s: what the coordinator wrote. */
			$detail .= Accounts::notice( 'warning', sprintf( __( 'A coordinator returned this report: %s Change it under “Change the report” and it waits for review again.', 'packout-core' ), $reason ) );
		}
		if ( $photos ) {
			$detail .= '<div class="pos-myspot__photos">' . Spot_Page::slider( $photos, $spot->post_title ) . '</div>';
		}
		$detail .= '<div class="pos-myspot__story">' . wpautop( esc_html( $spot->post_content ) ) . '</div>';
		$pin     = Spots::pin( $id );
		$hidden  = $photos && ! Spots::gallery( $spot );
		$facts   = array(
			__( 'Category', 'packout-core' )    => $category ? $category->name : '',
			__( 'Stage', 'packout-core' )       => $statuses[ Spots::field( $id, 'status', 'spotted' ) ] ?? '',
			__( 'Review', 'packout-core' )      => self::review_of( $spot ),
			__( 'Reported', 'packout-core' )    => (string) get_the_date( '', $spot ),
			__( 'Reported by', 'packout-core' ) => Spots::reported_by( $spot )['label'],
			__( 'Pin', 'packout-core' )         => $pin ? $pin[0] . ', ' . $pin[1] : __( 'No pin', 'packout-core' ),
			__( 'Landmark', 'packout-core' )    => Spots::field( $id, 'landmark' ),
			__( 'Photos', 'packout-core' )      => ! $photos ? __( 'None', 'packout-core' ) : ( $hidden
				? ( $shown ? __( 'Only you and the coordinators see them.', 'packout-core' ) : __( 'Shown on the Spot\'s page once it is on the map.', 'packout-core' ) )
				: __( 'Shown on the Spot\'s page.', 'packout-core' ) ),
		);
		$detail .= '<dl class="pos-ws__facts">';
		foreach ( $facts as $label => $value ) {
			if ( '' !== (string) $value ) {
				$detail .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd>';
			}
		}
		$detail .= '</dl>';
		$detail .= $shown
			? '<p class="pos-ws__actions"><a class="pos-ws__button" href="' . esc_url( (string) get_permalink( $spot ) ) . '">' . esc_html__( 'Open the Spot\'s public page', 'packout-core' ) . '</a></p>'
			: '<p class="pos-ws__muted">' . esc_html__( 'This Spot is not on the public map, so it has no public page.', 'packout-core' ) . '</p>';

		// The form that changes the report.
		$do   = 'spot_edit';
		$mine = self::$failed === $do && self::$spot === $id;
		$old  = static fn( string $name, string $stored ): string => $mine ? self::old( $do, $name, $stored ) : $stored;
		$edit = self::open( $do ) . '<input type="hidden" name="spot" value="' . esc_attr( (string) $id ) . '" />';
		if ( $pending ) {
			$edit .= '<p class="pos-ws__help">' . esc_html__( 'You can change the report until a coordinator has reviewed it.', 'packout-core' ) . '</p>';
			$edit .= Workspace::input( 'title', __( 'Title', 'packout-core' ), $old( 'title', '1' === Spots::field( $id, 'title_custom' ) ? $spot->post_title : '' ), array( 'maxlength' => '120' ), __( 'Optional. Left empty, the title is made from the category and the landmark.', 'packout-core' ) );
			$edit .= Workspace::choose( 'category', __( 'Category', 'packout-core' ), Schema::categories(), $old( 'category', $category ? $category->slug : '' ) );
			$edit .= Workspace::area( 'description', __( 'What is there', 'packout-core' ), $old( 'description', $spot->post_content ), __( 'What you saw and why it needs attention.', 'packout-core' ), 4 );
			$edit .= Workspace::input( 'landmark', __( 'Landmark', 'packout-core' ), $old( 'landmark', Spots::field( $id, 'landmark' ) ), array( 'maxlength' => '200' ), __( 'A road, a pullout, a trail junction: how someone finds the place.', 'packout-core' ) );
			$edit .= '<div class="pos-ws__pair">' . Workspace::input( 'lat', __( 'Latitude', 'packout-core' ), $old( 'lat', $pin ? (string) $pin[0] : '' ), array( 'inputmode' => 'decimal' ) ) . Workspace::input( 'lng', __( 'Longitude', 'packout-core' ), $old( 'lng', $pin ? (string) $pin[1] : '' ), array( 'inputmode' => 'decimal' ) ) . '</div>';
			$edit .= '<p class="pos-ws__help">' . esc_html__( 'The pin, in decimal degrees. Leave both empty for a report with no pin.', 'packout-core' ) . '</p>';
		} else {
			$edit .= '<p class="pos-ws__help">' . esc_html__( 'A coordinator has reviewed this report, so what it says and where it is are theirs to change. You can still choose who the Spot\'s page credits, and add a note under Notes when something has changed.', 'packout-core' ) . '</p>';
		}
		$edit .= Credit::fields( 'spot_credit', $user, 'report', Spots::credit( $id ) );
		$edit .= Workspace::submit( $pending ? __( 'Save the report', 'packout-core' ) : __( 'Save', 'packout-core' ) ) . '</form>';

		// The person's notes.
		$note_help = $shown
			? __( 'Ticked, the note is on the Spot\'s page as an update, credited the way your report is. Unticked, only you see it.', 'packout-core' )
			: __( 'Ticked, the note is on the Spot\'s page as an update once the Spot is on the map. Unticked, only you see it.', 'packout-core' );
		$tick      = __( 'Show this note on the Spot\'s page', 'packout-core' );
		$adding    = 'note_add' === self::$failed && self::$spot === $id;
		$list      = '<p class="pos-ws__help">' . esc_html__( 'Notes are yours: what you saw on a later visit, who you called, what a crew should know. Each one stays private unless you tick its box.', 'packout-core' ) . '</p>';
		$list     .= self::open( 'note_add' ) . '<input type="hidden" name="spot" value="' . esc_attr( (string) $id ) . '" />';
		/* translators: %s: number of characters. */
		$list .= Workspace::area( 'text', __( 'New note', 'packout-core' ), $adding ? self::old( 'note_add', 'text' ) : '', sprintf( __( 'Up to %s characters.', 'packout-core' ), number_format_i18n( Updates::LENGTH ) ), 3 );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Showing back what the person chose.
		$list .= '<p class="pos-ws__field">' . Workspace::tick( 'public', $tick, $adding && ! empty( $_POST['public'] ) ) . '<span class="pos-ws__help">' . esc_html( $note_help ) . '</span></p>';
		$list .= Workspace::submit( __( 'Add the note', 'packout-core' ) ) . '</form>';
		if ( $notes ) {
			$list .= '<ol class="pos-notes">';
			foreach ( $notes as $note ) {
				$public = Updates::shown( $note );
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Showing back what the person typed.
				$saving = 'note_save' === self::$failed && self::$spot === $id && isset( $_POST['note'] ) && (int) $_POST['note'] === $note->ID;
				$fields = '<input type="hidden" name="spot" value="' . esc_attr( (string) $id ) . '" /><input type="hidden" name="note" value="' . esc_attr( (string) $note->ID ) . '" />';
				$list  .= '<li class="pos-note pos-note--' . ( $public ? 'public' : 'private' ) . '" id="pos-note-' . esc_attr( (string) $note->ID ) . '">';
				$list  .= '<p class="pos-note__head"><time datetime="' . esc_attr( (string) get_the_date( 'Y-m-d', $note ) ) . '">' . esc_html( (string) get_the_date( '', $note ) ) . '</time> <span class="pos-note__state">' . esc_html( $public ? ( $shown ? __( 'On the Spot\'s page', 'packout-core' ) : __( 'For the Spot\'s page', 'packout-core' ) ) : __( 'Only you see it', 'packout-core' ) ) . '</span></p>';
				$list  .= self::open( 'note_save' ) . $fields;
				/* translators: %s: date the note was written. */
				$list .= Workspace::area( 'text', sprintf( __( 'Note of %s', 'packout-core' ), (string) get_the_date( '', $note ) ), $saving ? self::old( 'note_save', 'text', $note->post_content ) : $note->post_content, '', 3 );
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Showing back what the person chose.
				$list .= '<p class="pos-ws__field">' . Workspace::tick( 'public', $tick, $saving ? ! empty( $_POST['public'] ) : $public ) . '</p>';
				$list .= Workspace::submit( __( 'Save the note', 'packout-core' ) ) . '</form>';
				$list .= self::open( 'note_remove' ) . $fields . '<button class="pos-ws__button pos-ws__button--danger"' . Workspace::confirm( __( 'Remove this note?', 'packout-core' ), $public ? __( 'It is taken off the Spot\'s page and removed for good.', 'packout-core' ) : __( 'It is removed for good.', 'packout-core' ), __( 'Remove it', 'packout-core' ) ) . '>' . esc_html__( 'Remove the note', 'packout-core' ) . '</button></form>';
				$list .= '</li>';
			}
			$list .= '</ol>';
		}

		$html = '';
		if ( $acted && self::$problem ) {
			// The drawer covers the page's own notice, so the pane says what was wrong as well.
			$html .= Accounts::notice( 'error', self::$problem->get_error_message() );
		} elseif ( '' !== $done && isset( self::spot_said()[ $done ] ) ) {
			$html .= Accounts::notice( 'success', self::spot_said()[ $done ] );
		}
		$html .= Workspace::sections(
			$spot->post_title,
			array(
				array(
					'id'    => $prefix . 'details',
					'label' => __( 'Details', 'packout-core' ),
					'html'  => $detail,
				),
				array(
					'id'    => $prefix . 'edit',
					'label' => $pending ? __( 'Change the report', 'packout-core' ) : __( 'Credit', 'packout-core' ),
					'html'  => $edit,
				),
				array(
					'id'    => $prefix . 'notes',
					/* translators: %s: number of notes. */
					'label' => $notes ? sprintf( __( 'Notes (%s)', 'packout-core' ), number_format_i18n( count( $notes ) ) ) : __( 'Notes', 'packout-core' ),
					'html'  => $list,
				),
			),
			'top',
			'' !== $part ? $prefix . $part : ''
		);
		Workspace_Lists::pane( $pane, $spot->post_title, $html );
		return $pane;
	}

	/**
	 * The name and the lines about the person.
	 *
	 * @param \WP_User             $person  The person.
	 * @param array<string, mixed> $profile From get().
	 */
	private static function about_html( \WP_User $person, array $profile ): string {
		$do    = 'about';
		$html  = self::open( $do );
		$html .= Workspace::input(
			'name',
			__( 'Your name', 'packout-core' ),
			self::old( $do, 'name', $person->display_name ),
			array(
				'required'     => '',
				'minlength'    => '2',
				'maxlength'    => '60',
				'autocomplete' => 'name',
			),
			__( 'Your organizations see this name. It is not shown on public pages.', 'packout-core' )
		);
		$html .= Workspace::area( 'about', __( 'A few lines about you', 'packout-core' ), self::old( $do, 'about', (string) $profile['about'] ), __( 'Where you get out, what you like to work on, skills or gear you bring. The leads of your organizations see it. Up to 600 characters.', 'packout-core' ), 4 );
		return $html . Workspace::submit( __( 'Save', 'packout-core' ) ) . '</form>';
	}

	/**
	 * The phone number, the mailing address, and who sees each.
	 *
	 * @param array<string, mixed> $profile From get().
	 * @param array<int, string>   $teams   The person's teams.
	 */
	private static function contact_html( array $profile, array $teams ): string {
		$do       = 'contact';
		$html     = '<p>' . esc_html__( 'Both are optional and private. Nobody sees them unless you tick a box below, and you can untick it at any time.', 'packout-core' ) . '</p>' . self::open( $do );
		$html    .= Workspace::input(
			'phone',
			__( 'Phone', 'packout-core' ),
			self::old( $do, 'phone', (string) $profile['phone'] ),
			array(
				'type'         => 'tel',
				'maxlength'    => '30',
				'autocomplete' => 'tel',
			)
		);
		$complete = array(
			'line1'   => 'address-line1',
			'line2'   => 'address-line2',
			'city'    => 'address-level2',
			'region'  => 'address-level1',
			'postal'  => 'postal-code',
			'country' => 'country-name',
		);
		$html    .= '<fieldset class="pos-ws__set pos-profile__address"><legend>' . esc_html__( 'Mailing address', 'packout-core' ) . '</legend>';
		foreach ( self::address_parts() as $part => $label ) {
			$html .= Workspace::input(
				'address_' . $part,
				$label,
				self::old( $do, 'address_' . $part, (string) $profile['address'][ $part ] ),
				array(
					'maxlength'    => '100',
					'autocomplete' => $complete[ $part ],
				)
			);
		}
		$html .= '</fieldset>';

		if ( $teams ) {
			$html .= Workspace::ticks( 'share_phone', __( 'Share my phone number with the leads of', 'packout-core' ), $teams, self::$failed === $do ? self::old_list( 'share_phone' ) : $profile['share_phone'] );
			$html .= Workspace::ticks( 'share_address', __( 'Share my mailing address with the leads of', 'packout-core' ), $teams, self::$failed === $do ? self::old_list( 'share_address' ) : $profile['share_address'], __( 'An organization\'s lead and co-leads see it on the organization\'s Members page. Other members do not.', 'packout-core' ) );
		} else {
			$html .= '<p class="pos-ws__help">' . esc_html__( 'You are not in an organization yet. When you are, you can choose here whether its leads see your phone number or address.', 'packout-core' ) . '</p>';
		}
		$html .= '<p class="pos-ws__field">' . Workspace::tick( 'swag', __( 'Pack-Out Spot may use my mailing address to send me things, such as stickers or a patch.', 'packout-core' ), (bool) $profile['swag'] ) . '<span class="pos-ws__help">' . esc_html__( 'Coordinators see your address only when this is ticked. We do not give it to anyone else.', 'packout-core' ) . '</span></p>';
		return $html . Workspace::submit( __( 'Save contact details', 'packout-core' ) ) . '</form>';
	}

	/**
	 * What the person is sent.
	 *
	 * @param array<string, mixed> $profile From get().
	 * @param array<int, string>   $teams   The person's teams.
	 */
	private static function mail_html( array $profile, array $teams ): string {
		$do   = 'mail';
		$html = self::open( $do );
		if ( $teams ) {
			$html .= Workspace::ticks( 'teams', __( 'Email me the messages these organizations send', 'packout-core' ), $teams, array_values( array_diff( array_keys( $teams ), $profile['quiet'] ) ), __( 'An organization\'s leads write to the organization, a team, or the people of a mission. Untick an organization and its messages stop arriving. You can still read them in My organizations.', 'packout-core' ) );
		}
		$html .= '<p class="pos-ws__field">' . Workspace::tick( 'news', __( 'Email me news from Pack-Out Spot.', 'packout-core' ), (bool) $profile['news'] ) . '<span class="pos-ws__help">' . esc_html__( 'An occasional update on what the community has done. We will not spam you, and we do not share the list.', 'packout-core' ) . '</span></p>';
		$html .= '<p class="pos-ws__help">' . esc_html__( 'Messages about your own account, such as a password link or an invitation, are always sent.', 'packout-core' ) . '</p>';
		return $html . Workspace::submit( __( 'Save email choices', 'packout-core' ) ) . '</form>';
	}

	/**
	 * The sign-in email.
	 *
	 * @param \WP_User $person The person.
	 */
	private static function email_html( \WP_User $person ): string {
		$do = 'email';
		/* translators: %s: email address. */
		$html  = '<p>' . esc_html( sprintf( __( 'You sign in with %s.', 'packout-core' ), $person->user_email ) ) . '</p>' . self::open( $do );
		$html .= Workspace::input(
			'email',
			__( 'New email address', 'packout-core' ),
			self::old( $do, 'email' ),
			array(
				'type'         => 'email',
				'required'     => '',
				'autocomplete' => 'email',
			),
			__( 'We send a link to the new address. Nothing changes until you follow it.', 'packout-core' )
		);
		$html .= Workspace::input(
			'password',
			__( 'Your current password', 'packout-core' ),
			'',
			array(
				'type'         => 'password',
				'required'     => '',
				'autocomplete' => 'current-password',
			)
		);
		return $html . Workspace::submit( __( 'Send the link', 'packout-core' ) ) . '</form>';
	}

	/**
	 * The password. It is chosen from a link sent to the sign-in email.
	 */
	private static function password_html(): string {
		$html = '<p>' . esc_html__( 'We send a link to your sign-in email. Follow it to choose a new password.', 'packout-core' ) . '</p>';
		return $html . self::open( 'password' ) . Workspace::submit( __( 'Send me a password link', 'packout-core' ) ) . '</form>';
	}
}
