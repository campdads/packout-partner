<?php
/**
 * Partner records.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

use PackOut\Core\Partner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A partner answers for one or more organizations: a council, a nonprofit
 * with several chapters, a club with its own website.
 *
 * The name is the post title and the description is the post content. A
 * partner has a lead and up to ten co-leads, and no member list of its own:
 * its people are its leads and the people of its organizations. An
 * organization names its partner in `_pos_partner`.
 *
 * What a partner's state allows is decided by `PackOut\Core\Partner`, which
 * the partner plugin shares. This class stores the record and asks there.
 */
class Partners {

	/**
	 * Words a partner's address cannot be.
	 */
	private const RESERVED = array( 'admin', 'attachment', 'embed', 'feed', 'new', 'page', 'packout', 'pack-out-spot', 'partner', 'partners', 'trackback', 'wp-admin', 'wp-json' );

	/**
	 * Organization IDs of each partner, worked out once per request.
	 *
	 * @var array<int, array<int, int>>
	 */
	private static array $teams = array();

	/**
	 * Partner IDs each person leads, worked out once per request.
	 *
	 * @var array<int, array<int, int>>
	 */
	private static array $led = array();

	/**
	 * Partner IDs each Spot is shown under on a map, worked out once per request.
	 *
	 * @var array<int, array<int, int>>|null
	 */
	private static ?array $spots = null;

	/**
	 * Register what follows a partner or an organization into the trash.
	 */
	public static function register(): void {
		add_action( 'trashed_post', array( self::class, 'trashed' ) );
	}

	/**
	 * When a partner is moved to the trash, its organizations have no partner
	 * and its leads no longer lead it.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public static function trashed( $post_id ): void {
		$id = (int) $post_id;
		if ( Schema::PARTNER !== get_post_type( $id ) ) {
			return;
		}
		foreach ( self::organization_ids( $id ) as $team ) {
			self::unfile( $team, $id );
		}
		self::flush();
		foreach ( self::leads( $id ) as $person ) {
			Teams::settle_lead( $person );
		}
	}

	/**
	 * Forget what was worked out for this request. Called when a partner or its organizations change.
	 */
	public static function flush(): void {
		self::$spots = null;
		self::$teams = array();
		self::$led   = array();
		Teams::flush();
	}

	/**
	 * A partner post, or null.
	 *
	 * @param int $id Post ID.
	 */
	public static function get( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && Schema::PARTNER === $post->post_type && in_array( $post->post_status, array( 'publish', 'private' ), true ) ? $post : null;
	}

	/**
	 * The option that names a partner site's one partner.
	 */
	public const SITE_OPTION = 'packout_site_partner';

	/**
	 * The one partner of a partner site, or 0.
	 *
	 * A partner site is one partner: the record owns every organization on
	 * the site, is active from the start, and nobody approves it. On
	 * packout.spot there is no such record, and this is 0.
	 */
	public static function site(): int {
		if ( App::is_hub() ) {
			return 0;
		}
		$id = (int) get_option( self::SITE_OPTION, 0 );
		return $id > 0 && self::get( $id ) ? $id : 0;
	}

	/**
	 * Make a partner site's one partner, or change it.
	 *
	 * @param int                  $user_id The person doing it, who may manage partners. They lead a new record unless `lead` names another person.
	 * @param array<string, mixed> $input   name, description, lead, listed, region, website, show_leads. A new record with no name takes the site's.
	 * @return int|\WP_Error Partner ID.
	 */
	public static function make_site( int $user_id, array $input = array() ) {
		if ( App::is_hub() ) {
			return new \WP_Error( 'packout_forbidden', __( 'packout.spot has many partners, and none of them is the site.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$id = self::site();
		if ( 0 === $id ) {
			$name          = sanitize_text_field( (string) ( $input['name'] ?? App::kernel()->site()->name() ) );
			$name          = mb_substr( $name, 0, 80 );
			$input['name'] = mb_strlen( $name ) >= 3 ? $name : __( 'This site', 'packout-core' );
			$input        += array(
				'lead'   => $user_id,
				// Nothing is public until the site says so.
				'listed' => false,
			);
		}
		$saved = self::write( $id, $input, $user_id );
		if ( ! is_wp_error( $saved ) && $saved !== $id ) {
			update_option( self::SITE_OPTION, $saved, false );
		}
		return $saved;
	}

	/**
	 * Every partner, by name.
	 *
	 * @return array<int, \WP_Post>
	 */
	public static function all(): array {
		return get_posts(
			array(
				'post_type'   => Schema::PARTNER,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 200,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
	}

	/**
	 * A text field of a partner.
	 *
	 * @param int    $id      Partner ID.
	 * @param string $key     Field name without the prefix.
	 * @param string $fallback Value when nothing is stored.
	 */
	public static function field( int $id, string $key, string $fallback = '' ): string {
		$value = get_post_meta( $id, '_pos_' . $key, true );
		return is_string( $value ) && '' !== $value ? $value : $fallback;
	}

	/**
	 * The partner's state: pending, active, suspended, dormant, or disconnected.
	 *
	 * @param int $id Partner ID.
	 */
	public static function state( int $id ): string {
		return Partner::state( get_post_meta( $id, '_pos_state', true ) );
	}

	/**
	 * Where the partner is kept: empty for this site, `remote` for a partner's own site.
	 *
	 * @param int $id Partner ID.
	 */
	public static function origin( int $id ): string {
		return Partner::origin( get_post_meta( $id, '_pos_origin', true ) );
	}

	/**
	 * Whether a coordinator has approved the partner and it is not suspended.
	 *
	 * @param int $id Partner ID.
	 */
	public static function active( int $id ): bool {
		return 'active' === self::state( $id );
	}

	/**
	 * Whether the partner's state allows an act. The acts are listed on `PackOut\Core\Partner`.
	 *
	 * @param int    $id  Partner ID.
	 * @param string $act What is being done.
	 */
	public static function allows( int $id, string $act ): bool {
		return null !== self::get( $id ) && Partner::allows( self::state( $id ), $act );
	}

	/**
	 * Whether the public sees the partner: its `page`, its `directory` card and filter, or its `totals`.
	 *
	 * @param int    $id  Partner ID.
	 * @param string $act `page`, `directory`, or `totals`.
	 */
	public static function shown( int $id, string $act = 'page' ): bool {
		$partner = self::get( $id );
		return $partner && Partner::shown( self::state( $id ), 'publish' === $partner->post_status, $act );
	}

	/**
	 * States and what each is called.
	 *
	 * @return array<string, string>
	 */
	public static function states(): array {
		return Vocabularies::labels( Vocabularies::PARTNER_STATE );
	}

	/**
	 * The lead's user ID, or 0.
	 *
	 * @param int $id Partner ID.
	 */
	public static function lead( int $id ): int {
		return (int) get_post_meta( $id, '_pos_lead', true );
	}

	/**
	 * Co-leads, without the lead, at most ten.
	 *
	 * @param int $id Partner ID.
	 * @return array<int, int>
	 */
	public static function coleads( int $id ): array {
		return Partner::coleads( array_map( 'intval', (array) get_post_meta( $id, '_pos_colead', false ) ), self::lead( $id ) );
	}

	/**
	 * The lead, then the co-leads.
	 *
	 * @param int $id Partner ID.
	 * @return array<int, int>
	 */
	public static function leads( int $id ): array {
		return array_values( array_unique( array_filter( array_merge( array( self::lead( $id ) ), self::coleads( $id ) ) ) ) );
	}

	/**
	 * Whether a person is the partner's lead or one of its co-leads.
	 *
	 * @param int $id      Partner ID.
	 * @param int $user_id User ID.
	 */
	public static function leads_partner( int $id, int $user_id ): bool {
		return $user_id > 0 && in_array( $user_id, self::leads( $id ), true );
	}

	/**
	 * Whether a list may be narrowed to a partner for a person, which names it.
	 *
	 * A partner the public can see, or one the person may open. A partner that
	 * is waiting or suspended is not named to anyone else.
	 *
	 * @param int $id      Partner ID.
	 * @param int $user_id User ID, or the current user.
	 */
	public static function nameable( int $id, int $user_id = -1 ): bool {
		$user_id = $user_id < 0 ? get_current_user_id() : $user_id;
		return null !== self::get( $id ) && ( self::shown( $id ) || self::can_edit( $user_id ) || self::leads_partner( $id, $user_id ) );
	}

	/**
	 * Whether a user may approve, suspend, and change any partner.
	 *
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_edit( int $user_id = -1 ): bool {
		return Capabilities::can( Capabilities::PARTNER, $user_id );
	}

	/**
	 * Whether a person answers for the partner: its lead, or a coordinator.
	 *
	 * Naming co-leads and handing the partner over are theirs alone.
	 *
	 * @param int $id      Partner ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function owns( int $id, int $user_id = -1 ): bool {
		if ( $user_id < 0 ) {
			$user_id = get_current_user_id();
		}
		return $user_id > 0 && ( self::can_edit( $user_id ) || self::lead( $id ) === $user_id );
	}

	/**
	 * Whether a user runs a partner: its lead, a co-lead, or a coordinator.
	 *
	 * @param int $id      Partner ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_run( int $id, int $user_id = -1 ): bool {
		if ( $user_id < 0 ) {
			$user_id = get_current_user_id();
		}
		return $user_id > 0 && ( self::can_edit( $user_id ) || self::leads_partner( $id, $user_id ) );
	}

	/**
	 * IDs of the partners a person leads or co-leads.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, int>
	 */
	public static function of_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		if ( ! isset( self::$led[ $user_id ] ) ) {
			$found = array_map(
				'intval',
				get_posts(
					array(
						'post_type'   => Schema::PARTNER,
						'post_status' => array( 'publish', 'private' ),
						'numberposts' => 200,
						'fields'      => 'ids',
						'orderby'     => 'title',
						'order'       => 'ASC',
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The partners of one person.
						'meta_query'  => array(
							'relation' => 'OR',
							array(
								'key'   => '_pos_lead',
								'value' => $user_id,
							),
							array(
								'key'   => '_pos_colead',
								'value' => $user_id,
							),
						),
					)
				)
			);
			// A co-lead past the limit is stored and does not lead.
			self::$led[ $user_id ] = array_values( array_filter( $found, static fn( $id ) => self::leads_partner( $id, $user_id ) ) );
		}//end if
		return self::$led[ $user_id ];
	}

	/**
	 * The partner an organization is filed under, or 0.
	 *
	 * @param int $team_id Organization ID.
	 */
	public static function of_team( int $team_id ): int {
		// On a partner site every organization is the partner's.
		if ( ! App::is_hub() ) {
			return Teams::get( $team_id ) ? self::site() : 0;
		}
		$id = (int) get_post_meta( $team_id, Partner::ORGANIZATION_META, true );
		return $id > 0 && self::get( $id ) ? $id : 0;
	}

	/**
	 * IDs of the organizations filed under a partner.
	 *
	 * @param int $id Partner ID.
	 * @return array<int, int>
	 */
	public static function organization_ids( int $id ): array {
		if ( $id <= 0 ) {
			return array();
		}
		if ( ! isset( self::$teams[ $id ] ) ) {
			$query = array(
				'post_type'   => Schema::TEAM,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 500,
				'fields'      => 'ids',
				'orderby'     => 'title',
				'order'       => 'ASC',
			);
			if ( App::is_hub() ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The organizations of one partner.
				$query['meta_key'] = Partner::ORGANIZATION_META;
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The organizations of one partner.
				$query['meta_value'] = $id;
			} elseif ( self::site() !== $id ) {
				// A partner site's one partner has every organization on the site. No other record has any.
				return array();
			}
			self::$teams[ $id ] = array_map( 'intval', get_posts( $query ) );
		}
		return self::$teams[ $id ];
	}

	/**
	 * The organizations filed under a partner, by name.
	 *
	 * @param int $id Partner ID.
	 * @return array<int, \WP_Post>
	 */
	public static function organizations( int $id ): array {
		return array_values( array_filter( array_map( array( Teams::class, 'get' ), self::organization_ids( $id ) ) ) );
	}

	/**
	 * Whether a person runs an organization through its partner.
	 *
	 * A lead or co-lead of an active partner may do for its organizations
	 * what their co-leads may. A partner that is waiting or suspended gives
	 * nothing.
	 *
	 * @param int $team_id Organization ID.
	 * @param int $user_id User ID.
	 */
	public static function reaches( int $team_id, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$partner = self::of_team( $team_id );
		return $partner > 0 && self::allows( $partner, 'organization' ) && self::leads_partner( $partner, $user_id );
	}

	/**
	 * Everyone a partner has: its leads, then the people of its organizations.
	 *
	 * @param int $id Partner ID.
	 * @return array<int, int>
	 */
	public static function people( int $id ): array {
		$people = self::leads( $id );
		foreach ( self::organization_ids( $id ) as $team ) {
			$people = array_merge( $people, Teams::people( $team ) );
		}
		return array_values( array_unique( array_filter( $people ) ) );
	}

	/**
	 * A partner with this name, or null. Names are compared without case.
	 *
	 * @param string $name   Partner name.
	 * @param int    $except Partner to leave out.
	 */
	public static function named( string $name, int $except = 0 ): ?\WP_Post {
		foreach ( self::all() as $partner ) {
			if ( $partner->ID !== $except && 0 === strcasecmp( trim( $partner->post_title ), trim( $name ) ) ) {
				return $partner;
			}
		}
		return null;
	}

	/**
	 * Partners waiting for a coordinator.
	 *
	 * @return array<int, \WP_Post>
	 */
	public static function pending(): array {
		return array_values( array_filter( self::all(), static fn( $partner ) => 'pending' === self::state( $partner->ID ) ) );
	}

	/**
	 * A name and a description as they are stored, or why they cannot be.
	 *
	 * @param array<string, mixed> $input name, description.
	 * @param int                  $id    The partner being changed, or 0.
	 * @param \WP_Post|null        $now   The record as it is, when it exists.
	 * @return array{name: string, description: string}|\WP_Error
	 */
	private static function words( array $input, int $id = 0, ?\WP_Post $now = null ) {
		$name = sanitize_text_field( (string) ( $input['name'] ?? ( $now ? $now->post_title : '' ) ) );
		if ( mb_strlen( $name ) < 3 || mb_strlen( $name ) > 80 ) {
			return new \WP_Error( 'packout_partner_name', __( 'Give the partner a name, from 3 to 80 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( self::named( $name, $id ) ) {
			return new \WP_Error( 'packout_partner_taken', __( 'A partner already has that name.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$description = sanitize_textarea_field( (string) ( $input['description'] ?? ( $now ? $now->post_content : '' ) ) );
		if ( mb_strlen( $description ) > Partner::MAX_DESCRIPTION ) {
			/* translators: %d: number of characters. */
			return new \WP_Error( 'packout_partner_description', sprintf( __( 'Say what the partner does in %d characters or fewer.', 'packout-core' ), Partner::MAX_DESCRIPTION ), array( 'status' => 400 ) );
		}
		return array(
			'name'        => $name,
			'description' => $description,
		);
	}

	/**
	 * Store the parts of a profile that are not the name or the description.
	 *
	 * @param int                  $id    Partner ID.
	 * @param array<string, mixed> $input region, website, show_leads.
	 */
	private static function write_profile( int $id, array $input ): void {
		if ( array_key_exists( 'region', $input ) ) {
			$region = sanitize_text_field( (string) $input['region'] );
			'' === $region ? delete_post_meta( $id, '_pos_region' ) : update_post_meta( $id, '_pos_region', wp_slash( $region ) );
		}
		if ( array_key_exists( 'website', $input ) ) {
			$website = esc_url_raw( trim( (string) $input['website'] ), array( 'http', 'https' ) );
			'' === $website ? delete_post_meta( $id, '_pos_website' ) : update_post_meta( $id, '_pos_website', $website );
		}
		if ( array_key_exists( 'show_leads', $input ) ) {
			empty( $input['show_leads'] ) ? delete_post_meta( $id, '_pos_show_leads' ) : update_post_meta( $id, '_pos_show_leads', '1' );
		}
	}

	/**
	 * Whether the partner's public page names its lead and co-leads.
	 *
	 * @param int $id Partner ID.
	 */
	public static function shows_leads( int $id ): bool {
		return '1' === self::field( $id, 'show_leads' );
	}

	/**
	 * Propose a partner. It waits for a coordinator.
	 *
	 * @param int                  $user_id The proposer, who becomes its lead. They lead an active organization.
	 * @param array<string, mixed> $input   name, description, region, website.
	 * @return int|\WP_Error Partner ID.
	 */
	public static function propose( int $user_id, array $input ) {
		if ( ! App::is_hub() ) {
			return App::hub_only();
		}
		if ( ! Accounts::on_site( $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'Sign up before proposing a partner.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$leads = array_filter( Teams::of_user( $user_id ), static fn( $team ) => Teams::active( $team ) && Teams::leads_team( $team, $user_id ) );
		if ( ! $leads ) {
			return new \WP_Error( 'packout_forbidden', __( 'A partner is proposed by a person who leads an approved organization.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$words = self::words( $input );
		if ( is_wp_error( $words ) ) {
			return $words;
		}
		if ( array_filter( self::pending(), static fn( $partner ) => self::lead( $partner->ID ) === $user_id ) ) {
			return new \WP_Error( 'packout_partner_waiting', __( 'You have a partner waiting for approval. A coordinator will review it first.', 'packout-core' ), array( 'status' => 409 ) );
		}

		$id = wp_insert_post(
			array(
				'post_type'    => Schema::PARTNER,
				'post_status'  => 'private',
				'post_author'  => $user_id,
				'post_title'   => wp_slash( $words['name'] ),
				'post_content' => wp_slash( $words['description'] ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id = (int) $id;
		update_post_meta( $id, '_pos_lead', $user_id );
		update_post_meta( $id, '_pos_state', 'pending' );
		Vocabularies::assign( $id, Vocabularies::PARTNER_STATE, 'pending' );
		self::write_profile( $id, $input );
		self::flush();

		/**
		 * A person proposed a partner.
		 *
		 * @param int $id Partner ID.
		 */
		do_action( 'packout_partner_proposed', $id );

		$sent = Approvals::send(
			(string) get_option( 'admin_email' ),
			'partner',
			Approvals::coordinator( Capabilities::PARTNER ),
			array( 'id' => $id )
		);
		if ( ! $sent ) {
			$proposer = get_userdata( $user_id );
			Accounts::mail(
				(string) get_option( 'admin_email' ),
				__( 'A partner is waiting for approval', 'packout-core' ),
				array(
					/* translators: 1: partner name, 2: the proposer's name. */
					sprintf( __( '%2$s proposed the partner “%1$s”.', 'packout-core' ), $words['name'], $proposer ? $proposer->display_name : '' ),
					__( 'Approve or decline it:', 'packout-core' ) . ' ' . admin_url( 'admin.php?page=packout-partners' ),
				)
			);
		}
		return $id;
	}

	/**
	 * Create or change a partner as a coordinator. A partner a coordinator creates is active from the start.
	 *
	 * @param int                  $id      Partner ID, or 0 for a new partner.
	 * @param array<string, mixed> $input   name, description, lead, listed, region, website, show_leads.
	 * @param int                  $user_id The coordinator.
	 * @return int|\WP_Error Partner ID.
	 */
	public static function save( int $id, array $input, int $user_id ) {
		// A partner site has one partner. `make_site()` makes it, and nothing makes a second.
		if ( ! App::is_hub() && ( 0 === $id || self::site() !== $id ) ) {
			return App::hub_only();
		}
		return self::write( $id, $input, $user_id );
	}

	/**
	 * Store a partner a coordinator creates or changes.
	 *
	 * @param int                  $id      Partner ID, or 0 for a new partner.
	 * @param array<string, mixed> $input   name, description, lead, listed, region, website, show_leads.
	 * @param int                  $user_id The coordinator.
	 * @return int|\WP_Error Partner ID.
	 */
	private static function write( int $id, array $input, int $user_id ) {
		if ( ! self::can_edit( $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'A coordinator creates and changes partners here.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$existing = $id > 0 ? self::get( $id ) : null;
		if ( $id > 0 && ! $existing ) {
			return new \WP_Error( 'packout_not_found', __( 'That partner does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( $existing && Remote::is_site( $id ) ) {
			return new \WP_Error( 'packout_remote', __( 'This partner keeps its records on its own site, and its name and description are changed there.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$words = self::words( $input, $id, $existing );
		if ( is_wp_error( $words ) ) {
			return $words;
		}
		$lead      = array_key_exists( 'lead', $input ) ? (int) $input['lead'] : ( $existing ? self::lead( $id ) : 0 );
		$unchanged = $existing && self::lead( $id ) === $lead;
		if ( $lead <= 0 || ( ! $unchanged && ! Accounts::on_site( $lead ) ) ) {
			return new \WP_Error( 'packout_partner_lead', __( 'Choose a lead. A lead is a person with an account on this site.', 'packout-core' ), array( 'status' => 400 ) );
		}

		$was_listed = $existing && 'publish' === $existing->post_status;
		$listed     = array_key_exists( 'listed', $input ) ? ! empty( $input['listed'] ) : ( $existing ? $was_listed : true );
		// Only a partner whose state gives it a page is listed.
		$listed = $listed && ( ! $existing || self::allows( $id, 'page' ) );
		$post   = array(
			'post_type'    => Schema::PARTNER,
			'post_status'  => $listed ? 'publish' : 'private',
			'post_title'   => wp_slash( $words['name'] ),
			'post_content' => wp_slash( $words['description'] ),
		);
		if ( $existing ) {
			$post['ID'] = $id;
			$saved      = wp_update_post( $post, true );
		} else {
			$post['post_author'] = $user_id;
			$saved               = wp_insert_post( $post, true );
		}
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$saved  = (int) $saved;
		$before = $existing ? self::lead( $id ) : 0;
		update_post_meta( $saved, '_pos_lead', $lead );
		delete_post_meta( $saved, '_pos_colead', $lead );
		if ( ! $existing ) {
			update_post_meta( $saved, '_pos_state', 'active' );
		}
		self::write_profile( $saved, $input );
		self::flush();
		if ( self::active( $saved ) ) {
			Teams::make_lead( $lead );
		}
		if ( $before > 0 && $before !== $lead ) {
			Teams::settle_lead( $before );
		}
		if ( ! $existing ) {
			/**
			 * A partner became active.
			 *
			 * @param int $saved Partner ID.
			 */
			do_action( 'packout_partner_approved', $saved );
		}
		return $saved;
	}

	/**
	 * Take one of the steps that change a partner's state, when the state allows it.
	 *
	 * @param int    $id   Partner ID.
	 * @param string $step A step named on `PackOut\Core\Partner`.
	 * @return string|\WP_Error The state it is in afterward. An empty string when the step removes the record.
	 */
	private static function step( int $id, string $step ) {
		$next = Partner::step( self::state( $id ), $step, self::origin( $id ) );
		if ( null === $next ) {
			return new \WP_Error( 'packout_partner_state', __( 'That cannot be done to a partner in this state.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( '' !== $next ) {
			update_post_meta( $id, '_pos_state', $next );
			Vocabularies::assign( $id, Vocabularies::PARTNER_STATE, $next );
		}
		self::flush();
		return $next;
	}

	/**
	 * Approve a proposed partner. Its lead and co-leads are given the Lead role.
	 *
	 * @param int $id      Partner ID.
	 * @param int $user_id The coordinator.
	 * @return true|\WP_Error
	 */
	public static function approve( int $id, int $user_id ) {
		if ( ! App::is_hub() ) {
			return App::hub_only();
		}
		$partner = self::get( $id );
		if ( ! self::can_edit( $user_id ) || ! $partner ) {
			return new \WP_Error( 'packout_forbidden', __( 'A coordinator approves partners.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( self::active( $id ) ) {
			return true;
		}
		$done = self::step( $id, 'approve' );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		foreach ( self::leads( $id ) as $person ) {
			Teams::make_lead( $person );
		}

		/** This action is documented in src/class-partners.php */
		do_action( 'packout_partner_approved', $id );

		$lead = get_userdata( self::lead( $id ) );
		if ( $lead ) {
			Accounts::mail(
				$lead->user_email,
				__( 'Your partner is approved', 'packout-core' ),
				array(
					/* translators: %s: partner name. */
					sprintf( __( '“%s” is approved. You can now add organizations to it, open programs, and choose whether the partner is listed publicly.', 'packout-core' ), $partner->post_title ),
					Accounts::workspace_url( array( 'partner' => $id ) ),
				)
			);
		}
		return true;
	}

	/**
	 * Decline a proposed partner. The proposer is told why and the partner is removed.
	 *
	 * @param int    $id      Partner ID.
	 * @param string $reason  What the coordinator wrote.
	 * @param int    $user_id The coordinator.
	 * @return true|\WP_Error
	 */
	public static function decline( int $id, string $reason, int $user_id ) {
		if ( ! App::is_hub() ) {
			return App::hub_only();
		}
		$partner = self::get( $id );
		if ( ! self::can_edit( $user_id ) || ! $partner || null === Partner::step( self::state( $id ), 'decline', self::origin( $id ) ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'Only a partner that is waiting for approval can be declined.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$lead = get_userdata( self::lead( $id ) );
		/**
		 * A coordinator declined a partner. It is about to be removed.
		 *
		 * @param int    $id     The partner.
		 * @param string $reason What the coordinator wrote.
		 */
		do_action( 'packout_partner_declined', $id, sanitize_textarea_field( $reason ) );
		wp_trash_post( $id );
		self::flush();
		if ( $lead ) {
			Accounts::mail(
				$lead->user_email,
				__( 'Your partner was not approved', 'packout-core' ),
				array(
					/* translators: %s: partner name. */
					sprintf( __( 'A coordinator did not approve “%s”.', 'packout-core' ), $partner->post_title ),
					sanitize_textarea_field( $reason ),
					__( 'You can propose a partner again from My organizations.', 'packout-core' ),
				)
			);
		}
		return true;
	}

	/**
	 * Suspend a partner. Its page, its card, its programs, and its figures leave public view. Nothing is deleted.
	 *
	 * @param int    $id      Partner ID.
	 * @param int    $user_id The coordinator.
	 * @param string $reason  What the coordinator wrote, for the partner's lead.
	 * @return true|\WP_Error
	 */
	public static function suspend( int $id, int $user_id, string $reason = '' ) {
		if ( ! App::is_hub() ) {
			return App::hub_only();
		}
		$partner = self::get( $id );
		if ( ! self::can_edit( $user_id ) || ! $partner ) {
			return new \WP_Error( 'packout_forbidden', __( 'A coordinator suspends partners.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$done = self::step( $id, 'suspend' );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		// WordPress serves a published post on its own, so the listing is set aside and kept for the day it is lifted.
		if ( 'publish' === $partner->post_status ) {
			update_post_meta( $id, '_pos_listed_before', '1' );
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'private',
				)
			);
		}
		self::flush();
		foreach ( self::leads( $id ) as $person ) {
			Teams::settle_lead( $person );
		}

		/**
		 * A coordinator suspended a partner, or lifted the suspension.
		 *
		 * @param int    $id    Partner ID.
		 * @param string $state The state it is in now.
		 */
		do_action( 'packout_partner_state_changed', $id, 'suspended', sanitize_textarea_field( $reason ) );

		$lead = get_userdata( self::lead( $id ) );
		if ( $lead ) {
			Accounts::mail(
				$lead->user_email,
				__( 'Your partner is suspended', 'packout-core' ),
				array(
					/* translators: %s: partner name. */
					sprintf( __( 'A coordinator suspended “%s”. Its page and its programs are not shown until the suspension is lifted. Nothing was deleted, and its organizations are as they were.', 'packout-core' ), $partner->post_title ),
					sanitize_textarea_field( $reason ),
				)
			);
		}
		return true;
	}

	/**
	 * Lift a suspension. The partner is listed again if it was before.
	 *
	 * @param int $id      Partner ID.
	 * @param int $user_id The coordinator.
	 * @return true|\WP_Error
	 */
	public static function lift( int $id, int $user_id ) {
		if ( ! App::is_hub() ) {
			return App::hub_only();
		}
		$partner = self::get( $id );
		if ( ! self::can_edit( $user_id ) || ! $partner ) {
			return new \WP_Error( 'packout_forbidden', __( 'A coordinator lifts a suspension.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$done = self::step( $id, 'lift' );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		if ( '1' === self::field( $id, 'listed_before' ) ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
		}
		delete_post_meta( $id, '_pos_listed_before' );
		self::flush();
		foreach ( self::leads( $id ) as $person ) {
			Teams::make_lead( $person );
		}

		/** This action is documented in src/class-partners.php */
		do_action( 'packout_partner_state_changed', $id, 'active', '' );

		$lead = get_userdata( self::lead( $id ) );
		if ( $lead ) {
			Accounts::mail(
				$lead->user_email,
				__( 'Your partner is active again', 'packout-core' ),
				array(
					/* translators: %s: partner name. */
					sprintf( __( 'A coordinator lifted the suspension of “%s”.', 'packout-core' ), $partner->post_title ),
					Accounts::workspace_url( array( 'partner' => $id ) ),
				)
			);
		}
		return true;
	}

	/**
	 * Change a partner's profile from the workspace.
	 *
	 * @param int                  $id      Partner ID.
	 * @param array<string, mixed> $input   name, description, region, website, listed, show_leads.
	 * @param int                  $user_id The person saving.
	 * @return int|\WP_Error Partner ID.
	 */
	public static function update_profile( int $id, array $input, int $user_id ) {
		$partner = self::get( $id );
		if ( ! $partner || ! self::can_run( $id, $user_id ) || ! self::allows( $id, 'profile' ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The partner\'s lead changes its profile.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$words = self::words( $input, $id, $partner );
		if ( is_wp_error( $words ) ) {
			return $words;
		}
		$listed = array_key_exists( 'listed', $input ) ? ! empty( $input['listed'] ) : 'publish' === $partner->post_status;
		$saved  = wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => wp_slash( $words['name'] ),
				'post_content' => wp_slash( $words['description'] ),
				'post_status'  => $listed && self::allows( $id, 'page' ) ? 'publish' : 'private',
			),
			true
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		if ( array_key_exists( 'listed', $input ) && 'suspended' === self::state( $id ) ) {
			// A suspended partner is not listed. What it chose is kept for the day the suspension is lifted.
			$listed ? update_post_meta( $id, '_pos_listed_before', '1' ) : delete_post_meta( $id, '_pos_listed_before' );
		}
		self::write_profile( $id, $input );
		self::flush();
		return $id;
	}

	/**
	 * Name a co-lead, or return a co-lead to where they were.
	 *
	 * The partner's lead promotes a person of one of its organizations. A
	 * co-lead may step down on their own. On an active partner a co-lead
	 * holds the Lead role, which a coordinator did not review for this
	 * person, so the site's administrator is told each time it is given.
	 *
	 * @param int  $id      Partner ID.
	 * @param int  $who     The person.
	 * @param bool $on      Whether they co-lead after this.
	 * @param int  $user_id The person deciding: the partner's lead, a coordinator, or the co-lead stepping down.
	 * @return true|\WP_Error
	 */
	public static function set_colead( int $id, int $who, bool $on, int $user_id ) {
		$partner = self::get( $id );
		$owns    = $partner && self::owns( $id, $user_id );
		if ( ! $partner || ! ( $owns || ( ! $on && $who === $user_id ) ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The partner\'s lead names its co-leads.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! $on ) {
			delete_post_meta( $id, '_pos_colead', $who );
			self::flush();
			Teams::settle_lead( $who );
			return true;
		}
		if ( ! self::allows( $id, 'profile' ) ) {
			return new \WP_Error( 'packout_partner_state', __( 'That cannot be done to a partner in this state.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( self::lead( $id ) === $who || ! in_array( $who, self::people( $id ), true ) ) {
			return new \WP_Error( 'packout_member_unknown', __( 'Choose a person from one of the partner\'s organizations, or invite them by email.', 'packout-core' ), array( 'status' => 400 ) );
		}
		return self::add_colead( $id, $who, $user_id );
	}

	/**
	 * Give a person who accepted an invitation the co-lead place it offered.
	 *
	 * The person who sent it is checked again now. If they no longer answer
	 * for the partner, nothing is given.
	 *
	 * @param int $id  Partner ID.
	 * @param int $who The person who accepted.
	 * @param int $by  The person who sent it.
	 * @return true|\WP_Error
	 */
	public static function seat( int $id, int $who, int $by ) {
		if ( $by <= 0 || $who <= 0 || ! self::get( $id ) || ! self::owns( $id, $by ) || ! self::allows( $id, 'profile' ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The partner\'s lead names its co-leads.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( self::lead( $id ) === $who ) {
			return true;
		}
		return self::add_colead( $id, $who, $by );
	}

	/**
	 * Store a co-lead and tell the people who should know.
	 *
	 * @param int $id  Partner ID.
	 * @param int $who The new co-lead.
	 * @param int $by  The person who named them.
	 * @return true|\WP_Error
	 */
	private static function add_colead( int $id, int $who, int $by ) {
		$partner = self::get( $id );
		$stored  = array_map( 'intval', (array) get_post_meta( $id, '_pos_colead', false ) );
		if ( ! $partner ) {
			return new \WP_Error( 'packout_not_found', __( 'That partner does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( in_array( $who, self::coleads( $id ), true ) ) {
			return true;
		}
		if ( ! Partner::has_room( $stored, self::lead( $id ) ) ) {
			return new \WP_Error( 'packout_colead_limit', __( 'This partner has as many co-leads as it can hold.', 'packout-core' ), array( 'status' => 400 ) );
		}
		add_post_meta( $id, '_pos_colead', $who );
		self::flush();

		$person = get_userdata( $who );
		$namer  = get_userdata( $by );
		if ( self::active( $id ) && Teams::make_lead( $who ) ) {
			Accounts::mail(
				(string) get_option( 'admin_email' ),
				__( 'A partner has a new co-lead', 'packout-core' ),
				array(
					/* translators: 1: who named the co-lead, 2: the co-lead, 3: partner name. */
					sprintf( __( '%1$s named %2$s a co-lead of the partner “%3$s”. %2$s now holds the Lead role.', 'packout-core' ), $namer ? $namer->display_name : '', $person ? $person->display_name : '', $partner->post_title ),
					__( 'Partners:', 'packout-core' ) . ' ' . admin_url( 'admin.php?page=packout-partners' ),
				)
			);
		}
		if ( $person && $who !== $by ) {
			Accounts::mail(
				$person->user_email,
				/* translators: %s: partner name. */
				sprintf( __( 'You are a co-lead of %s', 'packout-core' ), $partner->post_title ),
				array(
					/* translators: 1: who named the co-lead, 2: partner name. */
					sprintf( __( '%1$s named you a co-lead of the partner “%2$s”. You can now manage its profile, its programs, and its organizations.', 'packout-core' ), $namer ? $namer->display_name : __( 'The partner\'s lead', 'packout-core' ), $partner->post_title ),
					Accounts::workspace_url( array( 'partner' => $id ) ),
				)
			);
		}
		return true;
	}

	/**
	 * Hand a partner to one of its co-leads. The lead who hands it over stays as a co-lead.
	 *
	 * @param int $id      Partner ID.
	 * @param int $to      The co-lead who becomes the lead.
	 * @param int $user_id The person handing it over.
	 * @return true|\WP_Error
	 */
	public static function hand_over( int $id, int $to, int $user_id ) {
		if ( ! self::get( $id ) || ! self::owns( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The partner\'s lead hands it over.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! in_array( $to, self::coleads( $id ), true ) ) {
			return new \WP_Error( 'packout_member_unknown', __( 'Choose one of the partner\'s co-leads.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$before = self::lead( $id );
		update_post_meta( $id, '_pos_lead', $to );
		delete_post_meta( $id, '_pos_colead', $to );
		if ( $before > 0 && $before !== $to ) {
			add_post_meta( $id, '_pos_colead', $before );
		}
		self::flush();
		if ( self::active( $id ) ) {
			Teams::make_lead( $to );
			$new = get_userdata( $to );
			$old = get_userdata( $user_id );
			Accounts::mail(
				(string) get_option( 'admin_email' ),
				__( 'A partner has a new lead', 'packout-core' ),
				array(
					/* translators: 1: who handed the partner over, 2: partner name, 3: the new lead. */
					sprintf( __( '%1$s handed the partner “%2$s” to %3$s.', 'packout-core' ), $old ? $old->display_name : '', get_the_title( $id ), $new ? $new->display_name : '' ),
					__( 'Partners:', 'packout-core' ) . ' ' . admin_url( 'admin.php?page=packout-partners' ),
				)
			);
		}
		return true;
	}

	/**
	 * Invite a person by email to co-lead a partner.
	 *
	 * The person accepts from the link, with the account they have or a new
	 * one. Nobody is made a co-lead without agreeing to it.
	 *
	 * @param int    $id      Partner ID.
	 * @param string $email   Email address.
	 * @param int    $user_id The person inviting: the partner's lead, or a coordinator.
	 * @return true|\WP_Error
	 */
	public static function invite( int $id, string $email, int $user_id ) {
		$partner = self::get( $id );
		if ( ! $partner || ! self::owns( $id, $user_id ) || ! self::allows( $id, 'profile' ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The partner\'s lead names its co-leads.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$email = strtolower( sanitize_email( trim( $email ) ) );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'packout_account_email', __( 'Enter the email address of the person you are inviting.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( ! Accounts::open() ) {
			return new \WP_Error( 'packout_invite_closed', __( 'This site has no sign-up page yet, so an invitation cannot be sent.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$known = get_user_by( 'email', $email );
		if ( $known && self::leads_partner( $id, (int) $known->ID ) ) {
			return new \WP_Error( 'packout_member_exists', __( 'That person already leads the partner.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( ! Partner::has_room( array_map( 'intval', (array) get_post_meta( $id, '_pos_colead', false ) ), self::lead( $id ) ) ) {
			return new \WP_Error( 'packout_colead_limit', __( 'This partner has as many co-leads as it can hold.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( ! Accounts::allow( 'invite-partner-' . $id, 30, DAY_IN_SECONDS ) ) {
			return new \WP_Error( 'packout_invite_limit', __( 'That is enough invitations for today. Send the rest tomorrow.', 'packout-core' ), array( 'status' => 429 ) );
		}
		if ( ! Accounts::allow( 'invite-email-' . $email, 3, DAY_IN_SECONDS ) ) {
			return new \WP_Error( 'packout_invite_limit', __( 'That address has been sent three invitations today. Try again tomorrow.', 'packout-core' ), array( 'status' => 429 ) );
		}
		$offer      = array(
			'scope'    => 'partner',
			'scope_id' => $id,
			'role'     => 'colead',
		);
		$invitation = Invitations::prepare_send( 0, $email, $offer, $user_id );
		$resent     = Invitations::was_delivered( $invitation );
		$sent       = Invitations::deliver( 0, $email, $offer, $user_id, $invitation, $resent );
		return $sent ? true : new \WP_Error( 'packout_account_mail', __( 'The invitation could not be sent. Try again in a few minutes.', 'packout-core' ), array( 'status' => 500 ) );
	}

	/**
	 * Create an organization under a partner.
	 *
	 * It is active at once, with the partner's lead as its lead until they
	 * name another. A coordinator approved the partner and so answers for the
	 * organizations it creates, which is why the second review is skipped.
	 * Coordinators are told.
	 *
	 * @param int                  $id      Partner ID.
	 * @param array<string, mixed> $input   name, description, region, website.
	 * @param int                  $user_id The person creating it: a lead or co-lead of the partner, or a coordinator.
	 * @return int|\WP_Error Organization ID.
	 */
	public static function create_organization( int $id, array $input, int $user_id ) {
		$partner = self::get( $id );
		if ( ! $partner || ! self::can_run( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'A partner\'s leads add its organizations.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! self::allows( $id, 'organization' ) ) {
			return new \WP_Error( 'packout_partner_state', __( 'A partner adds organizations once a coordinator has approved it.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$lead = self::lead( $id );
		$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		if ( mb_strlen( $name ) < 3 || mb_strlen( $name ) > 80 ) {
			return new \WP_Error( 'packout_team_name', __( 'Give the organization a name, from 3 to 80 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( Teams::named( $name ) ) {
			return new \WP_Error( 'packout_team_taken', __( 'An organization already has that name.', 'packout-core' ), array( 'status' => 409 ) );
		}
		// The lead of an active partner holds the Lead role. This covers one whose role was taken away by hand.
		Teams::make_lead( $lead );
		$team = wp_insert_post(
			array(
				'post_type'    => Schema::TEAM,
				'post_status'  => 'private',
				'post_author'  => $user_id,
				'post_title'   => wp_slash( $name ),
				'post_content' => wp_slash( sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ) ),
			),
			true
		);
		if ( is_wp_error( $team ) ) {
			return $team;
		}
		$team = (int) $team;
		update_post_meta( $team, '_pos_lead', $lead );
		update_post_meta( $team, Partner::ORGANIZATION_META, $id );
		self::flush();
		$profile = array_intersect_key( $input, array_flip( array( 'region', 'website' ) ) );
		if ( $profile ) {
			Teams::update_profile( $team, $profile + array( 'listed' => false ), $lead );
		}

		/**
		 * An organization was filed under a partner, or left it.
		 *
		 * @param int    $team   Organization ID.
		 * @param int    $id     Partner ID.
		 * @param string $change `joined` or `left`.
		 */
		do_action( 'packout_organization_claimed', $team, $id, 'joined' );

		$by = get_userdata( $user_id );
		Accounts::mail(
			(string) get_option( 'admin_email' ),
			__( 'A partner added an organization', 'packout-core' ),
			array(
				/* translators: 1: person's name, 2: organization name, 3: partner name. */
				sprintf( __( '%1$s created the organization “%2$s” under the partner “%3$s”. It is active without review, because the partner is approved.', 'packout-core' ), $by ? $by->display_name : '', $name, $partner->post_title ),
				__( 'Organizations:', 'packout-core' ) . ' ' . admin_url( 'admin.php?page=packout-teams&open=' . $team ),
			)
		);
		return $team;
	}

	/**
	 * The requests that wait between a partner and organizations.
	 *
	 * @param int $id Partner ID.
	 * @return array<int, array{team: int, from: string}> `from` is `team` when the organization asked, `partner` when the partner did.
	 */
	public static function requests( int $id ): array {
		$found = get_posts(
			array(
				'post_type'   => Schema::TEAM,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 200,
				'fields'      => 'ids',
				'orderby'     => 'title',
				'order'       => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The requests of one partner.
				'meta_query'  => array(
					array(
						'key'     => '_pos_partner_request',
						'value'   => array( $id . ':team', $id . ':partner' ),
						'compare' => 'IN',
					),
				),
			)
		);
		$rows = array();
		foreach ( $found as $team ) {
			foreach ( self::requests_of_team( (int) $team ) as $row ) {
				if ( $row['partner'] === $id ) {
					$rows[] = array(
						'team' => (int) $team,
						'from' => $row['from'],
					);
				}
			}
		}
		return $rows;
	}

	/**
	 * The requests that wait on one organization.
	 *
	 * @param int $team_id Organization ID.
	 * @return array<int, array{partner: int, from: string}>
	 */
	public static function requests_of_team( int $team_id ): array {
		$rows = array();
		foreach ( (array) get_post_meta( $team_id, '_pos_partner_request', false ) as $raw ) {
			$parts = explode( ':', (string) $raw );
			if ( 2 === count( $parts ) && (int) $parts[0] > 0 && in_array( $parts[1], array( 'team', 'partner' ), true ) && self::get( (int) $parts[0] ) ) {
				$rows[] = array(
					'partner' => (int) $parts[0],
					'from'    => $parts[1],
				);
			}
		}
		return $rows;
	}

	/**
	 * The side that asked, when a request waits between an organization and a partner, or an empty string.
	 *
	 * @param int $team_id Organization ID.
	 * @param int $id      Partner ID.
	 */
	public static function asked_by( int $team_id, int $id ): string {
		foreach ( self::requests_of_team( $team_id ) as $row ) {
			if ( $row['partner'] === $id ) {
				return $row['from'];
			}
		}
		return '';
	}

	/**
	 * Ask that an organization be filed under a partner.
	 *
	 * The organization's lead asks the partner, or the partner's lead asks
	 * the organization. Nothing changes until the other side accepts. A
	 * person who leads both has both answers, and the organization is filed
	 * at once.
	 *
	 * @param int $team_id Organization ID.
	 * @param int $id      Partner ID.
	 * @param int $user_id The person asking.
	 * @return string|\WP_Error `joined` or `requested`.
	 */
	public static function ask( int $team_id, int $id, int $user_id ) {
		// On a partner site every organization is the partner's. There is nothing to ask.
		if ( ! App::is_hub() ) {
			return App::hub_only();
		}
		$team    = Teams::get( $team_id );
		$partner = self::get( $id );
		if ( ! $team || ! $partner ) {
			return new \WP_Error( 'packout_not_found', __( 'That organization or partner does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$for_team    = Teams::owns( $team_id, $user_id );
		$for_partner = self::owns( $id, $user_id );
		if ( ! $for_team && ! $for_partner ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead or the partner\'s lead asks.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! Teams::active( $team_id ) || ! self::allows( $id, 'organization' ) || 'remote' === self::origin( $id ) ) {
			return new \WP_Error( 'packout_partner_state', __( 'An approved organization joins an approved partner.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( self::of_team( $team_id ) > 0 ) {
			return new \WP_Error( 'packout_partner_has', __( 'That organization already has a partner. It leaves that one first.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( $for_team && $for_partner ) {
			self::file( $team_id, $id );
			return 'joined';
		}
		$from   = $for_team ? 'team' : 'partner';
		$before = self::asked_by( $team_id, $id );
		if ( '' !== $before && $before !== $from ) {
			// The other side already asked. Asking back is the answer.
			self::file( $team_id, $id );
			return 'joined';
		}
		if ( '' === $before ) {
			add_post_meta( $team_id, '_pos_partner_request', $id . ':' . $from );
			$asker = get_userdata( $user_id );
			$to    = 'team' === $from ? self::leads( $id ) : Teams::leads( $team_id );
			foreach ( array_filter( array_map( 'get_userdata', $to ) ) as $person ) {
				Accounts::mail(
					$person->user_email,
					'team' === $from ? __( 'An organization asked to join your partner', 'packout-core' ) : __( 'A partner asked your organization to join it', 'packout-core' ),
					array(
						'team' === $from
							/* translators: 1: person's name, 2: organization name, 3: partner name. */
							? sprintf( __( '%1$s asked that the organization “%2$s” be filed under the partner “%3$s”.', 'packout-core' ), $asker ? $asker->display_name : '', $team->post_title, $partner->post_title )
							/* translators: 1: person's name, 2: partner name, 3: organization name. */
							: sprintf( __( '%1$s, of the partner “%2$s”, asked that your organization “%3$s” be filed under it. The partner’s leads could then manage the organization as its co-leads do.', 'packout-core' ), $asker ? $asker->display_name : '', $partner->post_title, $team->post_title ),
						__( 'Accept or decline:', 'packout-core' ) . ' ' . Accounts::workspace_url(
							'team' === $from
								? array(
									'partner' => $id,
									'view'    => 'organizations',
								)
								: array(
									'team' => $team_id,
									'view' => 'settings',
								)
						),
					)
				);
			}//end foreach
		}//end if
		return 'requested';
	}

	/**
	 * Accept or decline a request between an organization and a partner.
	 *
	 * The side that did not ask answers: its lead or a co-lead, or a coordinator.
	 *
	 * @param int  $team_id Organization ID.
	 * @param int  $id      Partner ID.
	 * @param bool $accept  Whether the organization is filed under the partner.
	 * @param int  $user_id The person deciding.
	 * @return true|\WP_Error
	 */
	public static function answer( int $team_id, int $id, bool $accept, int $user_id ) {
		if ( ! App::is_hub() ) {
			return App::hub_only();
		}
		$from = self::asked_by( $team_id, $id );
		if ( '' === $from || ! Teams::get( $team_id ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That request is no longer waiting.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$may = 'team' === $from
			? self::can_run( $id, $user_id )
			: ( Teams::can_edit( $user_id ) || Teams::leads_team( $team_id, $user_id ) );
		if ( ! $may ) {
			return new \WP_Error( 'packout_forbidden', __( 'The side that was asked answers.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( $accept && ( ! Teams::active( $team_id ) || ! self::allows( $id, 'organization' ) || self::of_team( $team_id ) > 0 ) ) {
			return new \WP_Error( 'packout_partner_state', __( 'An approved organization with no partner joins an approved partner.', 'packout-core' ), array( 'status' => 409 ) );
		}
		delete_post_meta( $team_id, '_pos_partner_request', $id . ':' . $from );
		if ( $accept ) {
			self::file( $team_id, $id );
			return true;
		}
		$team    = Teams::get( $team_id );
		$partner = self::get( $id );
		$to      = 'team' === $from ? Teams::lead( $team_id ) : self::lead( $id );
		$person  = get_userdata( $to );
		if ( $person && $team && $partner ) {
			Accounts::mail(
				$person->user_email,
				__( 'Your request was declined', 'packout-core' ),
				array(
					/* translators: 1: organization name, 2: partner name. */
					sprintf( __( 'The request to file the organization “%1$s” under the partner “%2$s” was declined. Nothing changed.', 'packout-core' ), $team->post_title, $partner->post_title ),
				)
			);
		}
		return true;
	}

	/**
	 * Take back a request. The side that asked may.
	 *
	 * @param int $team_id Organization ID.
	 * @param int $id      Partner ID.
	 * @param int $user_id The person taking it back.
	 */
	public static function withdraw( int $team_id, int $id, int $user_id ): bool {
		$from = self::asked_by( $team_id, $id );
		$may  = 'team' === $from ? Teams::owns( $team_id, $user_id ) : ( 'partner' === $from && self::owns( $id, $user_id ) );
		return $may && delete_post_meta( $team_id, '_pos_partner_request', $id . ':' . $from );
	}

	/**
	 * File an organization under a partner and tell both sides. The callers have checked that both agree.
	 *
	 * @param int $team_id Organization ID.
	 * @param int $id      Partner ID.
	 */
	private static function file( int $team_id, int $id ): void {
		update_post_meta( $team_id, Partner::ORGANIZATION_META, $id );
		// Whatever else was asked of this organization ends here: it has one partner.
		delete_post_meta( $team_id, '_pos_partner_request' );
		self::flush();

		/** This action is documented in src/class-partners.php */
		do_action( 'packout_organization_claimed', $team_id, $id, 'joined' );

		$team    = Teams::get( $team_id );
		$partner = self::get( $id );
		if ( ! $team || ! $partner ) {
			return;
		}
		$lines = array(
			/* translators: 1: organization name, 2: partner name. */
			sprintf( __( 'The organization “%1$s” is now filed under the partner “%2$s”. The partner’s leads can manage it as its co-leads do. Its missions, campaigns, and impact stay its own.', 'packout-core' ), $team->post_title, $partner->post_title ),
		);
		$told  = array( (string) get_option( 'admin_email' ) );
		foreach ( array_filter( array_map( 'get_userdata', array( Teams::lead( $team_id ), self::lead( $id ) ) ) ) as $person ) {
			$told[] = $person->user_email;
		}
		foreach ( array_unique( array_map( 'strtolower', $told ) ) as $address ) {
			Accounts::mail( $address, __( 'An organization joined a partner', 'packout-core' ), $lines );
		}
	}

	/**
	 * Take an organization out from under its partner, with what that undoes.
	 *
	 * @param int $team_id Organization ID.
	 * @param int $id      The partner it was under.
	 */
	private static function unfile( int $team_id, int $id ): void {
		delete_post_meta( $team_id, Partner::ORGANIZATION_META );
		self::flush();

		/** This action is documented in src/class-partners.php */
		do_action( 'packout_organization_claimed', $team_id, $id, 'left' );
	}

	/**
	 * Leave a partner. The organization has no partner again, and its history stays its own.
	 *
	 * @param int $team_id Organization ID.
	 * @param int $user_id The person deciding: the organization's lead, the partner's lead, or a coordinator.
	 * @return true|\WP_Error
	 */
	public static function release( int $team_id, int $user_id ) {
		if ( ! App::is_hub() ) {
			return App::hub_only();
		}
		$id      = self::of_team( $team_id );
		$team    = Teams::get( $team_id );
		$partner = self::get( $id );
		if ( ! $team || ! $partner ) {
			return new \WP_Error( 'packout_not_found', __( 'That organization has no partner.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! ( Teams::can_edit( $user_id ) || Teams::lead( $team_id ) === $user_id || self::owns( $id, $user_id ) ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead or the partner\'s lead decides that.', 'packout-core' ), array( 'status' => 403 ) );
		}
		self::unfile( $team_id, $id );

		$lines = array(
			/* translators: 1: organization name, 2: partner name. */
			sprintf( __( 'The organization “%1$s” is no longer filed under the partner “%2$s”. Its missions, campaigns, and impact are as they were, and are no longer part of the partner’s programs or totals.', 'packout-core' ), $team->post_title, $partner->post_title ),
		);
		$told  = array( (string) get_option( 'admin_email' ) );
		foreach ( array_filter( array_map( 'get_userdata', array( Teams::lead( $team_id ), self::lead( $id ) ) ) ) as $person ) {
			$told[] = $person->user_email;
		}
		foreach ( array_unique( array_map( 'strtolower', $told ) ) as $address ) {
			Accounts::mail( $address, __( 'An organization left a partner', 'packout-core' ), $lines );
		}
		return true;
	}

	/**
	 * What a partner's organizations add up to.
	 *
	 * Read through its organizations at the time of asking, so an
	 * organization that leaves takes its figures with it.
	 *
	 * @param int                  $id      Partner ID.
	 * @param bool                 $visible Whether only publicly counted records are summed.
	 * @param array<string, mixed> $args    from, to.
	 * @return array<string, array<string, int|float>>
	 */
	public static function totals( int $id, bool $visible = false, array $args = array() ): array {
		// A partner site counts its own work. What it reported stands in for a count made here.
		if ( Remote::is_site( $id ) ) {
			return Remote::reported( $id );
		}
		return Impact::totals(
			array(
				'teams'   => self::organization_ids( $id ),
				'visible' => $visible,
			) + array_intersect_key( $args, array_flip( array( 'from', 'to' ) ) )
		);
	}

	/**
	 * Partners the public is shown, for the Partners block: listed, active, in alphabetical order.
	 *
	 * @return array<int, array{id: int, name: string, description: string, url: string, organizations: int, logo: string, region: string}>
	 */
	public static function listed(): array {
		$partners = array();
		// The directory is the hub's. A partner site is one partner.
		foreach ( App::is_hub() ? self::all() : array() as $partner ) {
			if ( ! self::shown( $partner->ID, 'directory' ) ) {
				continue;
			}
			$listed     = array_filter( self::organizations( $partner->ID ), static fn( $team ) => 'publish' === $team->post_status );
			$partners[] = array(
				'id'            => $partner->ID,
				'name'          => $partner->post_title,
				'description'   => $partner->post_content,
				'url'           => (string) get_permalink( $partner ),
				'organizations' => count( $listed ),
				'logo'          => self::logo_html( $partner->ID, 'pos-teams__logo', 'thumbnail' ),
				'region'        => self::field( $partner->ID, 'region' ),
			);
		}
		return $partners;
	}

	/**
	 * The partners a map can be filtered to: the ones the directory shows, by name.
	 *
	 * @return array<int, string> Partner names by ID.
	 */
	public static function map_choices(): array {
		$choices = array();
		// A partner site is one partner, so its map has no partner to choose.
		foreach ( App::is_hub() ? self::all() : array() as $partner ) {
			if ( self::shown( $partner->ID, 'directory' ) ) {
				$choices[ $partner->ID ] = $partner->post_title;
			}
		}
		return $choices;
	}

	/**
	 * The partners each Spot is shown under on a map.
	 *
	 * A Spot is a partner's when it is on a public mission of one of the
	 * partner's organizations, or is credited to one of its listed
	 * organizations. Both are things the public can already read, on the
	 * mission's page and in the Spot's credit line. Only partners the
	 * directory shows are named.
	 *
	 * @return array<int, array<int, int>> Partner IDs by Spot ID.
	 */
	public static function of_spots(): array {
		global $wpdb;
		if ( null !== self::$spots ) {
			return self::$spots;
		}
		self::$spots = array();
		foreach ( array_keys( self::map_choices() ) as $partner ) {
			$teams = self::organization_ids( $partner );
			if ( ! $teams ) {
				continue;
			}
			$found = array();
			foreach ( Overview_Scope::mission_ids( $teams ) as $mission ) {
				if ( 'publish' !== get_post_status( $mission ) ) {
					continue;
				}
				foreach ( Missions::spots( $mission ) as $spot ) {
					$found[ (int) $spot ] = true;
				}
			}
			$listed = array_values( array_filter( $teams, static fn( $team ) => 'publish' === get_post_status( $team ) ) );
			if ( $listed ) {
				$in = implode( ',', array_fill( 0, count( $listed ), '%s' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The Spots credited to a partner's listed organizations. $in is placeholders.
				$credited = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s WHERE pm.meta_key = %s AND pm.meta_value IN ($in)", array_merge( array( Schema::SPOT, '_pos_credit_team' ), array_map( 'strval', $listed ) ) ) );
				foreach ( (array) $credited as $spot ) {
					$found[ (int) $spot ] = true;
				}
			}
			foreach ( array_keys( $found ) as $spot ) {
				self::$spots[ $spot ][] = (int) $partner;
			}
		}//end foreach
		return self::$spots;
	}

	/**
	 * The last part of a partner's public address.
	 *
	 * @param int $id Partner ID.
	 */
	public static function address( int $id ): string {
		$partner = self::get( $id );
		return $partner ? (string) $partner->post_name : '';
	}

	/**
	 * Where a partner's public page would be with a given address.
	 *
	 * @param string $address Last part of the address.
	 */
	public static function address_url( string $address ): string {
		$type = get_post_type_object( Schema::PARTNER );
		$base = $type && is_array( $type->rewrite ) && ! empty( $type->rewrite['slug'] ) ? (string) $type->rewrite['slug'] : 'partner';
		return home_url( user_trailingslashit( $base . '/' . $address ) );
	}

	/**
	 * Why a partner cannot have an address, or an empty string when it can.
	 *
	 * An address another partner uses is taken, and so is one another
	 * partner used before: links to it still lead to that partner.
	 *
	 * @param int    $id      Partner ID.
	 * @param string $address Address, lower-case and trimmed.
	 */
	public static function address_problem( int $id, string $address ): string {
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{1,58}[a-z0-9]$/', $address ) ) {
			return __( 'Use 3 to 60 lower-case letters, digits, and hyphens, starting and ending with a letter or a digit.', 'packout-core' );
		}
		if ( in_array( $address, self::RESERVED, true ) ) {
			return __( 'That address is reserved. Choose another.', 'packout-core' );
		}
		if ( self::address( $id ) === $address ) {
			return '';
		}
		$query = array(
			'post_type'   => Schema::PARTNER,
			'post_status' => array( 'publish', 'private', 'pending', 'draft', 'future' ),
			'numberposts' => 1,
			'fields'      => 'ids',
			'exclude'     => array( $id ),
		);
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One lookup, when a lead changes the address.
		$before = get_posts(
			$query + array(
				'meta_key'   => '_wp_old_slug',
				'meta_value' => $address,
			)
		);
		if ( get_posts( $query + array( 'name' => $address ) ) || $before ) {
			return __( 'Another partner has that address. Choose another.', 'packout-core' );
		}
		return '';
	}

	/**
	 * Change a partner's public address. The old one forwards to the new one.
	 *
	 * @param int    $id      Partner ID.
	 * @param string $address New address.
	 * @param int    $user_id The person changing it.
	 * @return bool|\WP_Error True when it changed, false when it was already that.
	 */
	public static function set_address( int $id, string $address, int $user_id ) {
		$partner = self::get( $id );
		if ( ! $partner || ! self::can_run( $id, $user_id ) || ! self::allows( $id, 'profile' ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The partner\'s lead changes its address.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$address = Teams::clean_address( $address );
		$problem = self::address_problem( $id, $address );
		if ( '' !== $problem ) {
			return new \WP_Error( 'packout_partner_address', $problem, array( 'status' => 400 ) );
		}
		if ( $address === $partner->post_name ) {
			return false;
		}
		$saved = wp_update_post(
			array(
				'ID'        => $id,
				'post_name' => $address,
			),
			true
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		clean_post_cache( $id );
		if ( self::address( $id ) !== $address ) {
			// WordPress added a number to keep it unique. That is not what was asked for.
			wp_update_post(
				array(
					'ID'        => $id,
					'post_name' => $partner->post_name,
				)
			);
			return new \WP_Error( 'packout_partner_address', __( 'Another partner has that address. Choose another.', 'packout-core' ), array( 'status' => 409 ) );
		}
		// WordPress remembers the old address of a listed record only. An unlisted partner's links forward too.
		if ( ! in_array( $partner->post_name, get_post_meta( $id, '_wp_old_slug', false ), true ) ) {
			add_post_meta( $id, '_wp_old_slug', $partner->post_name );
		}
		delete_post_meta( $id, '_wp_old_slug', $address );
		self::flush();
		return true;
	}

	/**
	 * The partner's logo: an attachment ID, or 0.
	 *
	 * @param int $id Partner ID.
	 */
	public static function logo( int $id ): int {
		$logo = (int) self::field( $id, 'logo', '0' );
		return $logo > 0 && 'attachment' === get_post_type( $logo ) ? $logo : 0;
	}

	/**
	 * The logo as an image tag, or an empty string.
	 *
	 * @param int    $id    Partner ID.
	 * @param string $classes Class for the image.
	 * @param string $size  Image size.
	 */
	public static function logo_html( int $id, string $classes = 'pos-team-logo', string $size = 'medium' ): string {
		$logo    = self::logo( $id );
		$partner = self::get( $id );
		if ( $logo <= 0 || ! $partner ) {
			return '';
		}
		return wp_get_attachment_image(
			$logo,
			$size,
			false,
			array(
				'class'   => $classes,
				/* translators: %s: partner name. */
				'alt'     => sprintf( __( 'Logo of %s', 'packout-core' ), $partner->post_title ),
				'loading' => 'lazy',
			)
		);
	}

	/**
	 * The answer to changing the logo or the cover of a partner that keeps its records on its own site.
	 */
	private static function kept_elsewhere(): \WP_Error {
		return new \WP_Error( 'packout_remote', __( 'This partner keeps its records on its own site, and its logo and cover image are changed there.', 'packout-core' ), array( 'status' => 409 ) );
	}

	/**
	 * Upload or replace the partner's logo.
	 *
	 * @param int                  $id      Partner ID.
	 * @param array<string, mixed> $file    One entry from the request files.
	 * @param int                  $user_id The person uploading.
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function set_logo( int $id, array $file, int $user_id ) {
		if ( ! self::get( $id ) || ! self::can_run( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The partner\'s lead uploads its logo.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( Remote::is_site( $id ) ) {
			return self::kept_elsewhere();
		}
		if ( ! self::allows( $id, 'images' ) ) {
			return new \WP_Error( 'packout_partner_state', __( 'A partner can add a logo once a coordinator has approved it.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$stored = Photos::store( $id, $file );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$old = self::logo( $id );
		if ( $old > 0 ) {
			wp_delete_attachment( $old, true );
		}
		update_post_meta( $id, '_pos_logo', $stored );
		return $stored;
	}

	/**
	 * Remove the partner's logo.
	 *
	 * @param int $id      Partner ID.
	 * @param int $user_id The person removing it.
	 * @return true|\WP_Error
	 */
	public static function remove_logo( int $id, int $user_id ) {
		if ( ! self::get( $id ) || ! self::can_run( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The partner\'s lead removes its logo.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( Remote::is_site( $id ) ) {
			return self::kept_elsewhere();
		}
		$old = self::logo( $id );
		if ( $old > 0 ) {
			wp_delete_attachment( $old, true );
		}
		delete_post_meta( $id, '_pos_logo' );
		return true;
	}

	/**
	 * Upload, replace, or remove the partner's cover image.
	 *
	 * @param int                       $id      Partner ID.
	 * @param array<string, mixed>|null $file    One entry from the request files, or null to remove the cover.
	 * @param int                       $user_id The person uploading.
	 * @return int|\WP_Error Attachment ID, or 0 when the cover was removed.
	 */
	public static function set_cover( int $id, ?array $file, int $user_id ) {
		if ( ! self::get( $id ) || ! self::can_run( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The partner\'s lead chooses its cover image.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( Remote::is_site( $id ) ) {
			return self::kept_elsewhere();
		}
		if ( $file && ! self::allows( $id, 'images' ) ) {
			return new \WP_Error( 'packout_partner_state', __( 'A partner can add a cover image once a coordinator has approved it.', 'packout-core' ), array( 'status' => 409 ) );
		}
		return Page_Header::set_cover( $id, $file );
	}
}
