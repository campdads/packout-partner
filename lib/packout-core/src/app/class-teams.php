<?php
/**
 * Team records.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * People read "organization" where this code says team, and "team" where
 * it says sub-team. Only the words on screen changed: the post type, the
 * meta keys, the addresses, and the names here stayed as they were.
 *
 * A team is a crew with a lead and members. It gets the credit for a mission.
 *
 * The name is the post title and the description is the post content. Both are
 * public when the team is listed. Members are WordPress users on this site.
 * The plugin keeps no contact directory.
 *
 * A team a person proposes is `pending` until a coordinator approves it. A
 * pending team can gather its people and cannot plan missions, because a
 * mission changes what the public map says about a Spot.
 */
class Teams {

	/**
	 * Most co-leads one team holds.
	 */
	public const COLEAD_LIMIT = 10;

	/**
	 * User option that marks a Lead role this plugin gave.
	 */
	private const GRANTED = 'pos_lead_granted';

	/**
	 * Team IDs of each person, worked out once per request.
	 *
	 * @var array<int, array<int, int>>
	 */
	private static array $known = array();

	/**
	 * Register what follows a team into the trash. Its public page is in Team_Page.
	 */
	public static function register(): void {
		add_action( 'trashed_post', array( self::class, 'trashed' ) );
	}

	/**
	 * When a team is moved to the trash, its leads no longer lead it.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public static function trashed( $post_id ): void {
		$id = (int) $post_id;
		if ( Schema::TEAM !== get_post_type( $id ) ) {
			return;
		}
		self::flush();
		foreach ( self::leads( $id ) as $person ) {
			self::settle_lead( $person );
		}
	}

	/**
	 * A team post, or null.
	 *
	 * @param int $id Post ID.
	 */
	public static function get( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && Schema::TEAM === $post->post_type && in_array( $post->post_status, array( 'publish', 'private' ), true ) ? $post : null;
	}

	/**
	 * Every organization, by name. The ones kept on a partner's site are left out unless asked for: they are listed, and never managed here.
	 *
	 * @param bool $with_remote Include the organizations kept on a partner's site.
	 * @return array<int, \WP_Post>
	 */
	public static function all( bool $with_remote = false ): array {
		$query = array(
			'post_type'   => Schema::TEAM,
			'post_status' => array( 'publish', 'private' ),
			'numberposts' => 200,
			'orderby'     => 'title',
			'order'       => 'ASC',
		);
		return get_posts( $with_remote ? $query : Remote::without( $query ) );
	}

	/**
	 * The lead's user ID, or 0.
	 *
	 * @param int $id Team ID.
	 */
	public static function lead( int $id ): int {
		return (int) get_post_meta( $id, '_pos_lead', true );
	}

	/**
	 * Member user IDs. The lead is not repeated here.
	 *
	 * @param int $id Team ID.
	 * @return array<int, int>
	 */
	public static function members( int $id ): array {
		$members = array_map( 'intval', (array) get_post_meta( $id, '_pos_member', false ) );
		return array_values( array_unique( array_filter( $members ) ) );
	}

	/**
	 * The lead, then the members.
	 *
	 * @param int $id Team ID.
	 * @return array<int, int>
	 */
	public static function people( int $id ): array {
		return array_values( array_unique( array_filter( array_merge( array( self::lead( $id ) ), self::members( $id ) ) ) ) );
	}

	/**
	 * Co-leads: members the lead has named to lead the team alongside them.
	 *
	 * @param int $id Team ID.
	 * @return array<int, int>
	 */
	public static function coleads( int $id ): array {
		$named = array_map( 'intval', (array) get_post_meta( $id, '_pos_colead', false ) );
		return array_values( array_intersect( self::members( $id ), $named ) );
	}

	/**
	 * The lead, then the co-leads.
	 *
	 * @param int $id Team ID.
	 * @return array<int, int>
	 */
	public static function leads( int $id ): array {
		return array_values( array_unique( array_filter( array_merge( array( self::lead( $id ) ), self::coleads( $id ) ) ) ) );
	}

	/**
	 * Whether a person is the team's lead or one of its co-leads.
	 *
	 * @param int $id      Team ID.
	 * @param int $user_id User ID.
	 */
	public static function leads_team( int $id, int $user_id ): bool {
		return $user_id > 0 && in_array( $user_id, self::leads( $id ), true );
	}

	/**
	 * Whether a person answers for the team: its lead, or a coordinator.
	 *
	 * Naming co-leads and handing the team over are theirs alone.
	 *
	 * @param int $id      Team ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function owns( int $id, int $user_id = -1 ): bool {
		if ( $user_id < 0 ) {
			$user_id = get_current_user_id();
		}
		return $user_id > 0 && ( self::can_edit( $user_id ) || self::lead( $id ) === $user_id );
	}

	/**
	 * A text field of a team.
	 *
	 * @param int    $id      Team ID.
	 * @param string $key     Field name without the prefix.
	 * @param string $fallback Value when nothing is stored.
	 */
	public static function field( int $id, string $key, string $fallback = '' ): string {
		$value = get_post_meta( $id, '_pos_' . $key, true );
		return is_string( $value ) && '' !== $value ? $value : $fallback;
	}

	/**
	 * Whether a coordinator has approved the team. A team made in the dashboard is.
	 *
	 * @param int $id Team ID.
	 */
	public static function active( int $id ): bool {
		return 'pending' !== self::field( $id, 'state' );
	}

	/**
	 * Ways a team takes new members, and what each means.
	 *
	 * @return array<string, string>
	 */
	public static function join_policies(): array {
		return Vocabularies::labels( Vocabularies::JOIN );
	}

	/**
	 * How this team takes new members.
	 *
	 * @param int $id Team ID.
	 */
	public static function join_policy( int $id ): string {
		$policy = self::field( $id, 'join', 'request' );
		return isset( self::join_policies()[ $policy ] ) ? $policy : 'request';
	}

	/**
	 * Group type slug, or empty when not set.
	 *
	 * @param int $id Team ID.
	 */
	public static function group_type( int $id ): string {
		return Vocabularies::slug( $id, Vocabularies::GROUP_TYPE );
	}

	/**
	 * Whether the team lets its members plan their own campaigns and missions.
	 *
	 * Off until the team's lead or a co-lead turns it on in Settings.
	 *
	 * @param int $id Team ID.
	 */
	public static function members_plan( int $id ): bool {
		return '1' === self::field( $id, 'member_plans' );
	}

	/**
	 * Whether the team's public page names its lead and co-leads.
	 *
	 * Off until the team's lead or a co-lead turns it on in Settings.
	 *
	 * @param int $id Team ID.
	 */
	public static function shows_leads( int $id ): bool {
		return '1' === self::field( $id, 'show_leads' );
	}

	/**
	 * User IDs of the people who asked to join.
	 *
	 * @param int $id Team ID.
	 * @return array<int, int>
	 */
	public static function requests( int $id ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', (array) get_post_meta( $id, '_pos_request', false ) ) ) ) );
	}

	/**
	 * Teams a person has asked to join.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, \WP_Post>
	 */
	public static function requested_by( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'   => Schema::TEAM,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 50,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Requests of one person.
				'meta_key'    => '_pos_request',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Requests of one person.
				'meta_value'  => $user_id,
			)
		);
	}

	/**
	 * Teams waiting for a coordinator.
	 *
	 * @return array<int, \WP_Post>
	 */
	public static function pending(): array {
		return array_values( array_filter( self::all(), static fn( $team ) => ! self::active( $team->ID ) ) );
	}

	/**
	 * Whether a user runs a team: its lead, a co-lead, or a coordinator.
	 *
	 * This is about the team itself: its profile, its people, its sub-teams,
	 * its sponsors and resources. Planning missions also needs the team to be
	 * approved and the person to hold the lead capability.
	 *
	 * @param int $id      Team ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_run( int $id, int $user_id = -1 ): bool {
		// A copy of a record kept on a partner's site is changed there, by nobody here.
		if ( Remote::is( $id ) ) {
			return false;
		}
		if ( $user_id < 0 ) {
			$user_id = get_current_user_id();
		}
		return $user_id > 0 && ( self::can_edit( $user_id ) || self::leads_team( $id, $user_id ) || Partners::reaches( $id, $user_id ) );
	}

	/**
	 * Whether a person leads an organization's work: its lead, a co-lead, or a lead of its partner.
	 *
	 * This is what planning and managing its missions and campaigns ask.
	 * Naming co-leads, handing the organization over, and removing it ask
	 * `owns()`, which a partner's lead does not pass.
	 *
	 * @param int $id      Team ID.
	 * @param int $user_id User ID.
	 */
	public static function leads_work( int $id, int $user_id ): bool {
		return self::leads_team( $id, $user_id ) || Partners::reaches( $id, $user_id );
	}

	/**
	 * Whether a user is the lead or a member.
	 *
	 * @param int $id      Team ID.
	 * @param int $user_id User ID.
	 */
	public static function includes( int $id, int $user_id ): bool {
		return $user_id > 0 && ( self::lead( $id ) === $user_id || in_array( $user_id, self::members( $id ), true ) );
	}

	/**
	 * IDs of the teams a user leads or belongs to.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, int>
	 */
	public static function of_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		if ( ! isset( self::$known[ $user_id ] ) ) {
			self::$known[ $user_id ] = array_map(
				'intval',
				get_posts(
					array(
						'post_type'   => Schema::TEAM,
						'post_status' => array( 'publish', 'private' ),
						'numberposts' => 200,
						'fields'      => 'ids',
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Membership lookup for one user.
						'meta_query'  => array(
							'relation' => 'OR',
							array(
								'key'   => '_pos_lead',
								'value' => $user_id,
							),
							array(
								'key'   => '_pos_member',
								'value' => $user_id,
							),
						),
					)
				)
			);
		}//end if
		return self::$known[ $user_id ];
	}

	/**
	 * Forget what was worked out for this request. Called when a team changes.
	 */
	public static function flush(): void {
		self::$known = array();
		Missions::flush();
	}

	/**
	 * Whether a user may change a team's name, lead, and listing.
	 *
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_edit( int $user_id = -1 ): bool {
		return Capabilities::can( Capabilities::TEAM, $user_id );
	}

	/**
	 * Whether a user may add and remove members of a team.
	 *
	 * A coordinator can for every team. A lead can for the team they lead,
	 * including a team that is still waiting for approval.
	 *
	 * @param int $id      Team ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_manage_members( int $id, int $user_id = -1 ): bool {
		if ( $user_id < 0 ) {
			$user_id = get_current_user_id();
		}
		return self::can_run( $id, $user_id );
	}

	/**
	 * Create or change a team.
	 *
	 * @param int                  $id    Team ID, or 0 for a new team.
	 * @param array<string, mixed> $input name, description, lead, listed.
	 * @return int|\WP_Error Team ID.
	 */
	public static function save( int $id, array $input ) {
		$remote = Remote::guard( $id );
		if ( $remote ) {
			return $remote;
		}
		$existing = $id > 0 ? self::get( $id ) : null;
		if ( $id > 0 && ! $existing ) {
			return new \WP_Error( 'packout_not_found', __( 'That organization does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}

		$name = sanitize_text_field( (string) ( $input['name'] ?? ( $existing ? $existing->post_title : '' ) ) );
		if ( '' === $name ) {
			return new \WP_Error( 'packout_team_name', __( 'Give the organization a name.', 'packout-core' ), array( 'status' => 400 ) );
		}

		$lead      = array_key_exists( 'lead', $input ) ? (int) $input['lead'] : ( $existing ? self::lead( $existing->ID ) : 0 );
		$unchanged = $existing && self::lead( $existing->ID ) === $lead;
		if ( $lead <= 0 || ( ! $unchanged && ! Capabilities::can( Capabilities::LEAD, $lead ) ) ) {
			return new \WP_Error( 'packout_team_lead', __( 'Choose a lead. A lead is a person on this site who is allowed to lead missions.', 'packout-core' ), array( 'status' => 400 ) );
		}

		$listed = array_key_exists( 'listed', $input ) ? ! empty( $input['listed'] ) : ( $existing ? 'publish' === $existing->post_status : true );
		// A team waiting for approval is not listed.
		$listed = $listed && ( ! $existing || self::active( $existing->ID ) );
		$post   = array(
			'post_type'    => Schema::TEAM,
			'post_status'  => $listed ? 'publish' : 'private',
			'post_title'   => wp_slash( $name ),
			'post_content' => wp_slash( sanitize_textarea_field( (string) ( $input['description'] ?? ( $existing ? $existing->post_content : '' ) ) ) ),
		);

		if ( $existing ) {
			$post['ID'] = $existing->ID;
			$saved      = wp_update_post( $post, true );
		} else {
			$post['post_author'] = get_current_user_id();
			$saved               = wp_insert_post( $post, true );
		}
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$before = $existing ? self::lead( $existing->ID ) : 0;
		update_post_meta( $saved, '_pos_lead', $lead );
		// A lead is not also listed as a member or a co-lead.
		delete_post_meta( $saved, '_pos_member', $lead );
		delete_post_meta( $saved, '_pos_colead', $lead );
		if ( array_key_exists( 'group_type', $input ) ) {
			self::write_profile( (int) $saved, array( 'group_type' => $input['group_type'] ) );
		}
		if ( self::active( (int) $saved ) ) {
			Vocabularies::assign( (int) $saved, Vocabularies::ORG_STATE, 'active' );
		}
		self::flush();
		if ( $before > 0 && $before !== $lead ) {
			self::settle_lead( $before );
		}
		return (int) $saved;
	}

	/**
	 * Add a person on this site to a team.
	 *
	 * A member needs the field app, so a person who cannot report yet is given
	 * the Crew role alongside the roles they already hold. People who are not
	 * on this site are refused: a lead cannot pull in accounts from elsewhere
	 * on the network.
	 *
	 * @param int    $id   Team ID.
	 * @param string $who  Username or email address.
	 * @return int|\WP_Error User ID.
	 */
	public static function add_member( int $id, string $who ) {
		if ( ! self::get( $id ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That organization does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}

		$who  = trim( $who );
		$user = is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who );
		if ( ! $user instanceof \WP_User || ( is_multisite() && ! is_user_member_of_blog( $user->ID ) ) ) {
			return new \WP_Error( 'packout_member_unknown', __( 'No person on this site has that username or email address.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( self::includes( $id, $user->ID ) ) {
			return new \WP_Error( 'packout_member_exists', __( 'That person is already in the organization.', 'packout-core' ), array( 'status' => 409 ) );
		}

		self::admit( $id, $user->ID );
		return $user->ID;
	}

	/**
	 * Put a person on a team. The caller has decided they may join.
	 *
	 * @param int $id      Team ID.
	 * @param int $user_id User ID.
	 */
	public static function admit( int $id, int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || ! self::get( $id ) || self::includes( $id, $user_id ) ) {
			return;
		}
		if ( ! Capabilities::can( Capabilities::REPORT, $user_id ) && get_role( 'pos_crew' ) instanceof \WP_Role ) {
			$user->add_role( 'pos_crew' );
		}
		add_post_meta( $id, '_pos_member', $user_id );
		delete_post_meta( $id, '_pos_request', $user_id );
		self::flush();

		/**
		 * A person joined a team.
		 *
		 * @param int $id      Team ID.
		 * @param int $user_id User ID.
		 */
		do_action( 'packout_team_joined', $id, $user_id );
	}

	/**
	 * Remove a member. Their account and roles are left alone.
	 *
	 * @param int $id      Team ID.
	 * @param int $user_id User ID.
	 */
	public static function remove_member( int $id, int $user_id ): bool {
		self::flush();
		if ( ! self::get( $id ) || $user_id <= 0 || ! delete_post_meta( $id, '_pos_member', $user_id ) ) {
			return false;
		}
		delete_post_meta( $id, '_pos_colead', $user_id );
		Subteams::drop_person( $id, $user_id );
		self::flush();
		self::settle_lead( $user_id );
		// What the person shared with this team's leads ends with their place on it.
		Profile::forget_team( $user_id, $id );
		return true;
	}

	/**
	 * Propose a team. It waits for a coordinator.
	 *
	 * @param int                  $user_id The founder, who becomes its lead.
	 * @param array<string, mixed> $input   name, description, region, website.
	 * @return int|\WP_Error Team ID.
	 */
	public static function propose( int $user_id, array $input ) {
		if ( ! Accounts::on_site( $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'Sign up before starting an organization.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		if ( mb_strlen( $name ) < 3 || mb_strlen( $name ) > 80 ) {
			return new \WP_Error( 'packout_team_name', __( 'Give the organization a name, from 3 to 80 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( self::named( $name ) ) {
			return new \WP_Error( 'packout_team_taken', __( 'An organization already has that name.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$waiting = array_filter( self::pending(), static fn( $team ) => self::lead( $team->ID ) === $user_id );
		if ( count( $waiting ) >= 2 ) {
			return new \WP_Error( 'packout_team_waiting', __( 'You have two organizations waiting for approval. A coordinator will review them first.', 'packout-core' ), array( 'status' => 409 ) );
		}

		$id = wp_insert_post(
			array(
				'post_type'    => Schema::TEAM,
				'post_status'  => 'private',
				'post_author'  => $user_id,
				'post_title'   => wp_slash( $name ),
				'post_content' => wp_slash( sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ) ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id = (int) $id;
		update_post_meta( $id, '_pos_lead', $user_id );
		update_post_meta( $id, '_pos_state', 'pending' );
		Vocabularies::assign( $id, Vocabularies::ORG_STATE, 'pending' );
		self::write_profile( $id, $input );
		self::flush();

		/**
		 * A person proposed a team.
		 *
		 * @param int $id Team ID.
		 */
		do_action( 'packout_team_proposed', $id );

		$sent = Approvals::send(
			(string) get_option( 'admin_email' ),
			'team',
			Approvals::coordinator( Capabilities::TEAM ),
			array( 'id' => $id )
		);
		if ( ! $sent ) {
			$founder = get_userdata( $user_id );
			Accounts::mail(
				(string) get_option( 'admin_email' ),
				__( 'An organization is waiting for approval', 'packout-core' ),
				array(
					/* translators: 1: organization name, 2: the founder's name. */
					sprintf( __( '%2$s proposed the organization “%1$s”.', 'packout-core' ), $name, $founder ? $founder->display_name : '' ),
					__( 'Approve or decline it:', 'packout-core' ) . ' ' . admin_url( 'admin.php?page=packout-teams' ),
				)
			);
		}
		return $id;
	}

	/**
	 * A team with this name, or null. Names are compared without case.
	 *
	 * @param string $name   Team name.
	 * @param int    $except Team to leave out.
	 */
	public static function named( string $name, int $except = 0 ): ?\WP_Post {
		foreach ( self::all() as $team ) {
			if ( $team->ID !== $except && 0 === strcasecmp( trim( $team->post_title ), trim( $name ) ) ) {
				return $team;
			}
		}
		return null;
	}

	/**
	 * Store the parts of a profile that are not the name or the description.
	 *
	 * @param int                  $id    Team ID.
	 * @param array<string, mixed> $input region, website, join.
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
		if ( array_key_exists( 'join', $input ) && isset( self::join_policies()[ (string) $input['join'] ] ) ) {
			$join = (string) $input['join'];
			update_post_meta( $id, '_pos_join', $join );
			Vocabularies::assign( $id, Vocabularies::JOIN, $join );
		}
		if ( array_key_exists( 'group_type', $input ) ) {
			$type = sanitize_title( (string) $input['group_type'] );
			if ( '' === $type ) {
				wp_set_object_terms( $id, array(), Vocabularies::GROUP_TYPE );
			} elseif ( isset( Vocabularies::labels( Vocabularies::GROUP_TYPE )[ $type ] ) ) {
				Vocabularies::assign( $id, Vocabularies::GROUP_TYPE, $type );
			}
		}
		if ( array_key_exists( 'member_plans', $input ) ) {
			empty( $input['member_plans'] ) ? delete_post_meta( $id, '_pos_member_plans' ) : update_post_meta( $id, '_pos_member_plans', '1' );
		}
		if ( array_key_exists( 'show_leads', $input ) ) {
			empty( $input['show_leads'] ) ? delete_post_meta( $id, '_pos_show_leads' ) : update_post_meta( $id, '_pos_show_leads', '1' );
		}
	}

	/**
	 * Approve a proposed team. Its lead and co-leads are given the Lead role and may now plan missions.
	 *
	 * @param int $id      Team ID.
	 * @param int $user_id The coordinator.
	 * @return true|\WP_Error
	 */
	public static function approve( int $id, int $user_id ) {
		if ( ! self::can_edit( $user_id ) || ! self::get( $id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'A coordinator approves organizations.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( self::active( $id ) ) {
			return true;
		}
		delete_post_meta( $id, '_pos_state' );
		Vocabularies::assign( $id, Vocabularies::ORG_STATE, 'active' );
		$lead = self::lead( $id );
		foreach ( self::leads( $id ) as $person ) {
			self::make_lead( $person );
		}
		self::flush();

		/**
		 * A coordinator approved a team.
		 *
		 * @param int $id Team ID.
		 */
		do_action( 'packout_team_approved', $id );

		$founder = get_userdata( $lead );
		if ( $founder ) {
			Accounts::mail(
				$founder->user_email,
				__( 'Your organization is approved', 'packout-core' ),
				array(
					/* translators: %s: organization name. */
					sprintf( __( '“%s” is approved. You can now plan campaigns and missions, and choose whether the organization is listed publicly.', 'packout-core' ), get_the_title( $id ) ),
					Accounts::workspace_url( array( 'team' => $id ) ),
				)
			);
		}
		return true;
	}

	/**
	 * Decline a proposed team. The founder is told why and the team is removed.
	 *
	 * @param int    $id      Team ID.
	 * @param string $reason  What the coordinator wrote.
	 * @param int    $user_id The coordinator.
	 * @return true|\WP_Error
	 */
	public static function decline( int $id, string $reason, int $user_id ) {
		$team = self::get( $id );
		if ( ! self::can_edit( $user_id ) || ! $team || self::active( $id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'Only an organization that is waiting for approval can be declined.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$founder = get_userdata( self::lead( $id ) );
		wp_trash_post( $id );
		self::flush();
		if ( $founder ) {
			Accounts::mail(
				$founder->user_email,
				__( 'Your organization was not approved', 'packout-core' ),
				array(
					/* translators: %s: organization name. */
					sprintf( __( 'A coordinator did not approve “%s”.', 'packout-core' ), $team->post_title ),
					sanitize_textarea_field( $reason ),
					__( 'You can propose an organization again from My organizations.', 'packout-core' ),
				)
			);
		}
		return true;
	}

	/**
	 * Give a person the Lead role alongside the roles they hold, if they cannot lead yet.
	 *
	 * The plugin remembers that it gave the role, so it can take it back when
	 * the person no longer leads a team. A role an administrator gave by hand
	 * carries no such mark and is never removed here.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether the role was added.
	 */
	public static function make_lead( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( $user instanceof \WP_User && ! Capabilities::can( Capabilities::LEAD, $user_id ) && get_role( 'pos_lead' ) instanceof \WP_Role ) {
			$user->add_role( 'pos_lead' );
			update_user_option( $user_id, self::GRANTED, 1 );
			return true;
		}
		return false;
	}

	/**
	 * Take back a Lead role this plugin gave, once the person leads no approved team.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether the role was removed.
	 */
	public static function settle_lead( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || ! get_user_option( self::GRANTED, $user_id ) ) {
			return false;
		}
		self::flush();
		foreach ( self::of_user( $user_id ) as $team ) {
			if ( self::active( $team ) && self::leads_team( $team, $user_id ) ) {
				return false;
			}
		}
		// A person who leads an active partner keeps the role for its organizations.
		foreach ( Partners::of_user( $user_id ) as $partner ) {
			if ( Partners::active( $partner ) ) {
				return false;
			}
		}
		$user->remove_role( 'pos_lead' );
		delete_user_option( $user_id, self::GRANTED );
		return true;
	}

	/**
	 * Name a member a co-lead, or return a co-lead to member.
	 *
	 * A co-lead runs the team with its lead. On an approved team that means
	 * the Lead role, which a coordinator did not review for this person, so
	 * the site's administrator is told each time it is given.
	 *
	 * @param int  $id      Team ID.
	 * @param int  $who     The member.
	 * @param bool $on      Whether they co-lead after this.
	 * @param int  $user_id The person deciding: the team's lead, or a coordinator.
	 * @return true|\WP_Error
	 */
	public static function set_colead( int $id, int $who, bool $on, int $user_id ) {
		$team = self::get( $id );
		if ( ! $team || ! self::owns( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead names its co-leads.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! in_array( $who, self::members( $id ), true ) ) {
			return new \WP_Error( 'packout_member_unknown', __( 'Choose a member of the organization.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$is = in_array( $who, self::coleads( $id ), true );
		if ( ! $on ) {
			delete_post_meta( $id, '_pos_colead', $who );
			self::flush();
			self::settle_lead( $who );
			return true;
		}
		if ( $is ) {
			return true;
		}
		if ( count( self::coleads( $id ) ) >= self::COLEAD_LIMIT ) {
			return new \WP_Error( 'packout_colead_limit', __( 'This organization has as many co-leads as it can hold.', 'packout-core' ), array( 'status' => 400 ) );
		}
		add_post_meta( $id, '_pos_colead', $who );
		self::flush();

		$person = get_userdata( $who );
		$by     = get_userdata( $user_id );
		if ( self::active( $id ) && self::make_lead( $who ) ) {
			Accounts::mail(
				(string) get_option( 'admin_email' ),
				__( 'An organization has a new co-lead', 'packout-core' ),
				array(
					/* translators: 1: who named the co-lead, 2: the co-lead, 3: organization name. */
					sprintf( __( '%1$s named %2$s a co-lead of “%3$s”. %2$s now holds the Lead role.', 'packout-core' ), $by ? $by->display_name : '', $person ? $person->display_name : '', $team->post_title ),
					__( 'Organizations:', 'packout-core' ) . ' ' . admin_url( 'admin.php?page=packout-teams' ),
				)
			);
		}
		if ( $person && $who !== $user_id ) {
			Accounts::mail(
				$person->user_email,
				/* translators: %s: organization name. */
				sprintf( __( 'You are a co-lead of %s', 'packout-core' ), $team->post_title ),
				array(
					/* translators: 1: who named the co-lead, 2: organization name. */
					sprintf( __( '%1$s named you a co-lead of “%2$s”. You can now manage its members, teams, campaigns, missions, resources, and sponsors.', 'packout-core' ), $by ? $by->display_name : __( 'The organization\'s lead', 'packout-core' ), $team->post_title ),
					Accounts::workspace_url( array( 'team' => $id ) ),
				)
			);
		}
		return true;
	}

	/**
	 * Give a person who accepted an invitation the place it offered.
	 *
	 * The person who sent it is checked again now. If they no longer lead
	 * there, the invited person stays a member of the team and nothing more.
	 *
	 * @param int                                                $id  Team ID.
	 * @param int                                                $who The person who accepted. Already on the team.
	 * @param array{role: string, subteam: int, subrole: string} $seat  What the invitation offered.
	 * @param int                                                $by  The person who sent it.
	 */
	public static function seat( int $id, int $who, array $seat, int $by ): void {
		if ( $by <= 0 || ! self::includes( $id, $who ) ) {
			return;
		}
		if ( 'colead' === $seat['role'] ) {
			self::set_colead( $id, $who, true, $by );
		}
		$sub = (int) $seat['subteam'];
		if ( $sub <= 0 || ! Subteams::get( $id, $sub ) ) {
			return;
		}
		if ( 'lead' === $seat['subrole'] ) {
			if ( is_wp_error( Subteams::save( $id, $sub, array( 'lead' => $who ), $by ) ) ) {
				Subteams::set_member( $id, $sub, $who, true, $by );
			}
			return;
		}
		if ( true === Subteams::set_member( $id, $sub, $who, true, $by ) && 'colead' === $seat['subrole'] ) {
			Subteams::set_colead( $id, $sub, $who, true, $by );
		}
	}

	/**
	 * Change a team's profile from the workspace.
	 *
	 * @param int                  $id      Team ID.
	 * @param array<string, mixed> $input   name, description, region, website, join, listed, member_plans.
	 * @param int                  $user_id The person saving.
	 * @return int|\WP_Error Team ID.
	 */
	public static function update_profile( int $id, array $input, int $user_id ) {
		$team = self::get( $id );
		if ( ! $team || ! self::can_run( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead changes its profile.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$name = sanitize_text_field( (string) ( $input['name'] ?? $team->post_title ) );
		if ( mb_strlen( $name ) < 3 || mb_strlen( $name ) > 80 ) {
			return new \WP_Error( 'packout_team_name', __( 'Give the organization a name, from 3 to 80 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( self::named( $name, $id ) ) {
			return new \WP_Error( 'packout_team_taken', __( 'An organization already has that name.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$listed = array_key_exists( 'listed', $input ) ? ! empty( $input['listed'] ) : 'publish' === $team->post_status;
		$saved  = wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => wp_slash( $name ),
				'post_content' => wp_slash( sanitize_textarea_field( (string) ( $input['description'] ?? $team->post_content ) ) ),
				'post_status'  => $listed && self::active( $id ) ? 'publish' : 'private',
			),
			true
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		self::write_profile( $id, $input );
		self::flush();
		return $id;
	}

	/**
	 * Hand a team to one of its members. The lead who hands it over stays as a member.
	 *
	 * @param int $id      Team ID.
	 * @param int $to      The member who becomes the lead.
	 * @param int $user_id The person handing it over.
	 * @return true|\WP_Error
	 */
	public static function hand_over( int $id, int $to, int $user_id ) {
		if ( ! self::get( $id ) || ! self::owns( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead hands it over.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! in_array( $to, self::members( $id ), true ) ) {
			return new \WP_Error( 'packout_member_unknown', __( 'Choose a member of the organization.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$before = self::lead( $id );
		update_post_meta( $id, '_pos_lead', $to );
		delete_post_meta( $id, '_pos_member', $to );
		delete_post_meta( $id, '_pos_colead', $to );
		if ( $before > 0 && $before !== $to ) {
			add_post_meta( $id, '_pos_member', $before );
		}
		self::flush();
		if ( $before > 0 && $before !== $to ) {
			self::settle_lead( $before );
		}
		if ( self::active( $id ) && self::make_lead( $to ) ) {
			// A coordinator approved the team, not this person. Tell the site who now holds the role.
			$new = get_userdata( $to );
			$old = get_userdata( $user_id );
			Accounts::mail(
				(string) get_option( 'admin_email' ),
				__( 'An organization has a new lead', 'packout-core' ),
				array(
					/* translators: 1: who handed the organization over, 2: organization name, 3: the new lead. */
					sprintf( __( '%1$s handed “%2$s” to %3$s, who now holds the Lead role.', 'packout-core' ), $old ? $old->display_name : '', get_the_title( $id ), $new ? $new->display_name : '' ),
					__( 'Organizations:', 'packout-core' ) . ' ' . admin_url( 'admin.php?page=packout-teams' ),
				)
			);
		}
		return true;
	}

	/**
	 * Ask to join a listed team.
	 *
	 * @param int  $id      Team ID.
	 * @param int  $user_id The person asking.
	 * @param bool $quiet   Whether the caller tells the leads itself, as a sign-up for a mission does.
	 * @return string|\WP_Error `joined` or `requested`.
	 */
	public static function ask( int $id, int $user_id, bool $quiet = false ) {
		$team = self::get( $id );
		if ( ! $team || 'publish' !== $team->post_status ) {
			return new \WP_Error( 'packout_not_found', __( 'That organization does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$kept = Remote::guard( $id );
		if ( $kept ) {
			return $kept;
		}
		if ( ! Accounts::on_site( $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'Sign up before joining an organization.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( self::includes( $id, $user_id ) ) {
			return 'joined';
		}
		$policy = self::join_policy( $id );
		if ( 'closed' === $policy ) {
			return new \WP_Error( 'packout_team_closed', __( 'This organization adds its members by invitation.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( 'open' === $policy ) {
			self::admit( $id, $user_id );
			return 'joined';
		}
		if ( ! in_array( $user_id, self::requests( $id ), true ) ) {
			add_post_meta( $id, '_pos_request', $user_id );
			$person = get_userdata( $user_id );
			foreach ( array_filter( array_map( 'get_userdata', self::leads( $id ) ) ) as $lead ) {
				if ( ! $person || $quiet ) {
					break;
				}
				$sent = Approvals::send(
					$lead->user_email,
					'join',
					(int) $lead->ID,
					array(
						'id'  => $id,
						'who' => $user_id,
					)
				);
				if ( ! $sent ) {
					Accounts::mail(
						$lead->user_email,
						__( 'Someone asked to join your organization', 'packout-core' ),
						array(
							/* translators: 1: the person's name, 2: organization name. */
							sprintf( __( '%1$s asked to join “%2$s”.', 'packout-core' ), $person->display_name, $team->post_title ),
							__( 'Accept or decline:', 'packout-core' ) . ' ' . Accounts::workspace_url(
								array(
									'team' => $id,
									'view' => 'members',
								)
							),
						)
					);
				}
			}//end foreach
		}//end if
		return 'requested';
	}

	/**
	 * Accept or decline a request to join.
	 *
	 * @param int  $id      Team ID.
	 * @param int  $who     The person who asked.
	 * @param bool $accept  Whether they join.
	 * @param int  $user_id The person deciding.
	 * @return true|\WP_Error
	 */
	public static function answer( int $id, int $who, bool $accept, int $user_id ) {
		$team = self::get( $id );
		if ( ! $team || ! self::can_run( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead answers requests to join.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! in_array( $who, self::requests( $id ), true ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That request is no longer waiting.', 'packout-core' ), array( 'status' => 404 ) );
		}
		delete_post_meta( $id, '_pos_request', $who );
		if ( $accept ) {
			self::admit( $id, $who );
		} else {
			// A sub-team takes its members from the team, so what they asked of its sub-teams ends here too.
			Subteams::forget( $id, $who );
		}
		$person = get_userdata( $who );
		if ( $person ) {
			Accounts::mail(
				$person->user_email,
				$accept ? __( 'You are in the organization', 'packout-core' ) : __( 'Your request to join', 'packout-core' ),
				array(
					$accept
						/* translators: %s: organization name. */
						? sprintf( __( 'You are now a member of “%s”. Its missions appear in the field app after your next sync.', 'packout-core' ), $team->post_title )
						/* translators: %s: organization name. */
						: sprintf( __( 'The lead of “%s” did not add you to the organization this time.', 'packout-core' ), $team->post_title ),
					$accept ? Accounts::workspace_url( array( 'team' => $id ) ) : '',
				)
			);
		}
		return true;
	}

	/**
	 * Take back a request to join.
	 *
	 * @param int $id      Team ID.
	 * @param int $user_id The person who asked.
	 */
	public static function withdraw( int $id, int $user_id ): bool {
		if ( $user_id <= 0 || ! delete_post_meta( $id, '_pos_request', $user_id ) ) {
			return false;
		}
		Subteams::forget( $id, $user_id );
		return true;
	}

	/**
	 * Leave a team.
	 *
	 * @param int $id      Team ID.
	 * @param int $user_id The member leaving.
	 * @return true|\WP_Error
	 */
	public static function leave( int $id, int $user_id ) {
		if ( self::lead( $id ) === $user_id ) {
			return new \WP_Error( 'packout_team_lead', __( 'Hand the organization to another member before you leave it.', 'packout-core' ), array( 'status' => 409 ) );
		}
		return self::remove_member( $id, $user_id ) ? true : new \WP_Error( 'packout_not_found', __( 'You are not in that organization.', 'packout-core' ), array( 'status' => 404 ) );
	}

	/**
	 * Invite a person to a team by email.
	 *
	 * The person accepts from the link, with the account they have or a new
	 * one. The lead is told the same thing whether or not the address has an
	 * account, and nobody is put on a team without agreeing to it.
	 *
	 * An invitation can offer more than membership: a place as a co-lead of
	 * the team, or a place in a sub-team as a member, a co-lead, or its lead.
	 * Who may offer what:
	 *
	 * - The team's lead or a coordinator: anything.
	 * - A co-lead of the team: anything except co-lead of the team.
	 * - A sub-team's lead: a member or a co-lead of that sub-team.
	 * - A sub-team's co-lead: a member of that sub-team.
	 *
	 * @param int                  $id      Team ID.
	 * @param string               $email   Email address.
	 * @param int                  $user_id The person inviting.
	 * @param array<string, mixed> $seat      role (member or colead), subteam, subrole (member, colead, or lead).
	 * @return true|\WP_Error
	 */
	public static function invite( int $id, string $email, int $user_id, array $seat = array() ) {
		$team    = self::get( $id );
		$role    = 'colead' === ( $seat['role'] ?? '' ) ? 'colead' : 'member';
		$sub     = (int) ( $seat['subteam'] ?? 0 );
		$subrole = in_array( $seat['subrole'] ?? '', array( 'colead', 'lead' ), true ) ? (string) $seat['subrole'] : 'member';
		$subteam = $team && $sub > 0 ? Subteams::get( $id, $sub ) : null;
		$refused = new \WP_Error( 'packout_forbidden', __( 'You cannot send that invitation. An organization\'s leads invite its members, and a team\'s leads invite people into it.', 'packout-core' ), array( 'status' => 403 ) );
		if ( ! $team || ( $sub > 0 && ! $subteam ) ) {
			return $refused;
		}
		if ( ! $subteam ) {
			$subrole = 'member';
		}
		$runs = self::can_run( $id, $user_id );
		if ( ! $runs && ( ! $subteam || 'member' !== $role || ! Subteams::can_run( $id, $sub, $user_id ) ) ) {
			return $refused;
		}
		if ( 'colead' === $role && ! self::owns( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead names its co-leads.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! $runs && ( 'lead' === $subrole || ( 'colead' === $subrole && ! Subteams::can_name( $id, $sub, $user_id ) ) ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'A team\'s lead names its co-leads, and the organization\'s leads name its lead.', 'packout-core' ), array( 'status' => 403 ) );
		}

		$email = strtolower( sanitize_email( trim( $email ) ) );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'packout_account_email', __( 'Enter the email address of the person you are inviting.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( ! Accounts::open() ) {
			return new \WP_Error( 'packout_invite_closed', __( 'This site has no sign-up page yet, so an invitation cannot be sent.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$known = get_user_by( 'email', $email );
		if ( $known && self::includes( $id, $known->ID ) ) {
			// The roster already shows this person to anyone who may invite.
			return new \WP_Error( 'packout_member_exists', __( 'That person is already in the organization. Choose them from the roster.', 'packout-core' ), array( 'status' => 409 ) );
		}
		if ( ! Accounts::allow( 'invite-team-' . $id, 30, DAY_IN_SECONDS ) ) {
			return new \WP_Error( 'packout_invite_limit', __( 'That is enough invitations for today. Send the rest tomorrow.', 'packout-core' ), array( 'status' => 429 ) );
		}
		if ( ! Accounts::allow( 'invite-email-' . $email, 3, DAY_IN_SECONDS ) ) {
			return new \WP_Error( 'packout_invite_limit', __( 'That address has been sent three invitations today. Try again tomorrow.', 'packout-core' ), array( 'status' => 429 ) );
		}

		$as_offer      = array(
			'role'    => $role,
			'subteam' => $subteam ? $sub : 0,
			'subrole' => $subrole,
		);
		$invitation_id = Invitations::prepare_send( $id, $email, $as_offer, $user_id );
		$resent        = Invitations::was_delivered( $invitation_id );
		$sent          = Invitations::deliver( $id, $email, $as_offer, $user_id, $invitation_id, $resent );
		return $sent ? true : new \WP_Error( 'packout_account_mail', __( 'The invitation could not be sent. Try again in a few minutes.', 'packout-core' ), array( 'status' => 500 ) );
	}

	/**
	 * Words a team's address cannot be: they mean something else in an address.
	 */
	private const RESERVED = array( 'admin', 'attachment', 'embed', 'feed', 'new', 'page', 'packout', 'pack-out-spot', 'team', 'teams', 'trackback', 'wp-admin', 'wp-json' );

	/**
	 * The last part of a team's public address.
	 *
	 * @param int $id Team ID.
	 */
	public static function address( int $id ): string {
		$team = self::get( $id );
		return $team ? (string) $team->post_name : '';
	}

	/**
	 * Where a team's public page would be with a given address.
	 *
	 * @param int    $id      Team ID.
	 * @param string $address Last part of the address.
	 */
	public static function address_url( int $id, string $address ): string {
		unset( $id );
		$type = get_post_type_object( Schema::TEAM );
		$base = $type && is_array( $type->rewrite ) && ! empty( $type->rewrite['slug'] ) ? (string) $type->rewrite['slug'] : 'team';
		return home_url( user_trailingslashit( $base . '/' . $address ) );
	}

	/**
	 * What a person typed, as an address would be stored.
	 *
	 * @param string $raw What was typed.
	 */
	public static function clean_address( string $raw ): string {
		return strtolower( trim( $raw ) );
	}

	/**
	 * Why a team cannot have an address, or an empty string when it can.
	 *
	 * An address another team uses is taken, and so is one another team used
	 * before: links to it still lead to that team.
	 *
	 * @param int    $id      Team ID.
	 * @param string $address Address, as clean_address() returns it.
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
			'post_type'   => Schema::TEAM,
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
			return __( 'Another organization has that address. Choose another.', 'packout-core' );
		}
		return '';
	}

	/**
	 * Change a team's public address. The old one forwards to the new one.
	 *
	 * @param int    $id      Team ID.
	 * @param string $address New address.
	 * @param int    $user_id The person changing it.
	 * @return bool|\WP_Error True when it changed, false when it was already that.
	 */
	public static function set_address( int $id, string $address, int $user_id ) {
		$team = self::get( $id );
		if ( ! $team || ! ( self::can_run( $id, $user_id ) || self::can_edit( $user_id ) ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead changes its address.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$address = self::clean_address( $address );
		$problem = self::address_problem( $id, $address );
		if ( '' !== $problem ) {
			return new \WP_Error( 'packout_team_address', $problem, array( 'status' => 400 ) );
		}
		if ( $address === $team->post_name ) {
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
					'post_name' => $team->post_name,
				)
			);
			return new \WP_Error( 'packout_team_address', __( 'Another organization has that address. Choose another.', 'packout-core' ), array( 'status' => 409 ) );
		}
		// WordPress remembers the old address of a listed team only. An unlisted team's links forward too.
		if ( ! in_array( $team->post_name, get_post_meta( $id, '_wp_old_slug', false ), true ) ) {
			add_post_meta( $id, '_wp_old_slug', $team->post_name );
		}
		delete_post_meta( $id, '_wp_old_slug', $address );
		self::flush();
		return true;
	}

	/**
	 * The attributes that make an address field say whether what is typed is free.
	 *
	 * @param int    $id      Team ID.
	 * @param string $status  ID of the element that shows the answer.
	 * @param int    $subteam Sub-team number, when the address is a sub-team's.
	 */
	public static function address_attributes( int $id, string $status, int $subteam = 0 ): string {
		return ' data-pos-address="' . esc_url( rest_url( Rest::NAMESPACE . '/teams/' . $id . ( $subteam > 0 ? '/subteams/' . $subteam : '' ) . '/address' ) ) . '" data-pos-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '" data-pos-checking="' . esc_attr__( 'Checking…', 'packout-core' ) . '" aria-describedby="' . esc_attr( $status ) . '" pattern="[a-z0-9][a-z0-9\-]{1,58}[a-z0-9]" minlength="3" maxlength="60" autocapitalize="none" autocomplete="off" spellcheck="false"';
	}

	/**
	 * The team's logo: an attachment ID, or 0.
	 *
	 * @param int $id Team ID.
	 */
	public static function logo( int $id ): int {
		$logo = (int) self::field( $id, 'logo', '0' );
		return $logo > 0 && 'attachment' === get_post_type( $logo ) ? $logo : 0;
	}

	/**
	 * The logo as an image tag, or an empty string.
	 *
	 * @param int    $id    Team ID.
	 * @param string $classes Class for the image.
	 * @param string $size  Image size.
	 */
	public static function logo_html( int $id, string $classes = 'pos-team-logo', string $size = 'medium' ): string {
		$logo = self::logo( $id );
		$team = self::get( $id );
		if ( $logo <= 0 || ! $team ) {
			return '';
		}
		return wp_get_attachment_image(
			$logo,
			$size,
			false,
			array(
				'class'   => $classes,
				/* translators: %s: organization name. */
				'alt'     => sprintf( __( 'Logo of %s', 'packout-core' ), $team->post_title ),
				'loading' => 'lazy',
			)
		);
	}

	/**
	 * Upload or replace the team's logo.
	 *
	 * @param int                  $id      Team ID.
	 * @param array<string, mixed> $file    One entry from the request files.
	 * @param int                  $user_id The person uploading.
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function set_logo( int $id, array $file, int $user_id ) {
		if ( ! self::get( $id ) || ! ( self::can_run( $id, $user_id ) || self::can_edit( $user_id ) ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead uploads its logo.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! self::active( $id ) ) {
			return new \WP_Error( 'packout_team_pending', __( 'An organization can add a logo once a coordinator has approved it.', 'packout-core' ), array( 'status' => 409 ) );
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
	 * Remove the team's logo.
	 *
	 * @param int $id      Team ID.
	 * @param int $user_id The person removing it.
	 * @return true|\WP_Error
	 */
	public static function remove_logo( int $id, int $user_id ) {
		if ( ! self::get( $id ) || ! ( self::can_run( $id, $user_id ) || self::can_edit( $user_id ) ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead removes its logo.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$old = self::logo( $id );
		if ( $old > 0 ) {
			wp_delete_attachment( $old, true );
		}
		delete_post_meta( $id, '_pos_logo' );
		return true;
	}

	/**
	 * The team's cover image: an attachment ID, or 0.
	 *
	 * @param int $id Team ID.
	 */
	public static function cover( int $id ): int {
		return Page_Header::cover( $id );
	}

	/**
	 * Upload, replace, or remove the team's cover image: the picture behind its name on its public page.
	 *
	 * @param int                       $id      Team ID.
	 * @param array<string, mixed>|null $file    One entry from the request files, or null to remove the cover.
	 * @param int                       $user_id The person uploading.
	 * @return int|\WP_Error Attachment ID, or 0 when the cover was removed.
	 */
	public static function set_cover( int $id, ?array $file, int $user_id ) {
		if ( ! self::get( $id ) || ! ( self::can_run( $id, $user_id ) || self::can_edit( $user_id ) ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead chooses its cover image.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( $file && ! self::active( $id ) ) {
			return new \WP_Error( 'packout_team_pending', __( 'An organization can add a cover image once a coordinator has approved it.', 'packout-core' ), array( 'status' => 409 ) );
		}
		return Page_Header::set_cover( $id, $file );
	}

	/**
	 * Listed teams with their public mission counts, for the Team list block.
	 *
	 * @return array<int, array{id: int, name: string, description: string, url: string, planned: int, completed: int, logo: string, group_type: string}>
	 */
	public static function listed(): array {
		$teams = array();
		foreach ( self::all( true ) as $team ) {
			if ( 'publish' !== $team->post_status || ! Post_Access::can_read( $team, 0 ) ) {
				continue;
			}
			$missions = Missions::public_of_team( $team->ID );
			$teams[]  = array(
				'id'          => $team->ID,
				'name'        => $team->post_title,
				'description' => $team->post_content,
				'url'         => (string) get_permalink( $team ),
				'planned'     => count( array_filter( $missions, static fn( $mission ) => in_array( $mission['status'], array( 'planned', 'in_progress' ), true ) ) ),
				'completed'   => count( array_filter( $missions, static fn( $mission ) => 'completed' === $mission['status'] ) ),
				'logo'        => self::logo_html( $team->ID, 'pos-teams__logo', 'thumbnail' ),
				'group_type'  => self::group_type( $team->ID ),
			);
		}
		return $teams;
	}
}
