<?php
/**
 * The team workspace: what each view shows.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The views of My teams.
 *
 * Each view shows a person what their place on the team lets them see, and
 * the forms for what it lets them change. A form that is not shown is still
 * refused when it is sent: the record classes check again.
 *
 * Every list is a Data View from Workspace_Lists: a table, cards, or a list.
 * A mission and a campaign open on a page of their own. A person, a
 * sub-team, a resource, a message, a sponsor, and an impact record open in
 * the drawer, and so do the forms that add one.
 */
class Workspace_Views {

	/**
	 * Most Spots offered to a mission at once.
	 */
	private const PICKER_LIMIT = 300;

	/**
	 * What the Spot picker says about each Spot that still needs work, once it has been worked out.
	 *
	 * @var array<int, array{title: string, facts: array<int, string>, open: array<int, string>}>|null
	 */
	private static ?array $candidates = null;

	/**
	 * The list of a person's teams, their requests, and the form that starts a team.
	 *
	 * @param int $user User ID.
	 */
	public static function home( int $user ): string {
		$mine = array_filter( array_map( array( Teams::class, 'get' ), Teams::of_user( $user ) ) );
		usort( $mine, static fn( $a, $b ) => strcasecmp( $a->post_title, $b->post_title ) );

		$html  = '<h2>' . esc_html__( 'My organizations', 'packout-core' ) . '</h2>';
		$html .= '<p class="pos-ws__actions">' . Workspace_Lists::opener( 'team-new', __( 'Start an organization', 'packout-core' ), __( 'Start an organization', 'packout-core' ) ) . ( Partner_Workspace::has_own_block() ? '' : ' ' . Partner_Workspace::propose_button( $user ) ) . '</p>';
		$rows  = array();
		foreach ( $mine as $team ) {
			$logo   = Teams::logo( $team->ID );
			$rows[] = array(
				'id'     => (string) $team->ID,
				'title'  => $team->post_title,
				'url'    => Workspace::url( $team->ID ),
				'state'  => self::team_state( $team ),
				'role'   => self::role_line( $team->ID, $user ),
				'people' => count( Teams::people( $team->ID ) ),
				'logo'   => $logo > 0 ? (string) wp_get_attachment_image_url( $logo, 'medium' ) : '',
			);
		}
		$html .= Workspace_Lists::view(
			'teams',
			array(
				'role'   => array( 'label' => __( 'Your place', 'packout-core' ) ),
				'state'  => array(
					'label' => __( 'Status', 'packout-core' ),
					'sort'  => true,
				),
				'people' => array(
					'label' => __( 'People', 'packout-core' ),
					'type'  => 'integer',
					'sort'  => true,
				),
				'logo'   => array(
					'label' => __( 'Logo', 'packout-core' ),
					'type'  => 'media',
				),
			),
			$rows,
			array(
				'title'       => __( 'Organization', 'packout-core' ),
				'layout'      => 'grid',
				'media'       => 'logo',
				'description' => 'role',
				'empty'       => __( 'You are not in an organization yet. Join one that is listed below, or start your own.', 'packout-core' ),
			)
		);

		if ( Teams::can_edit( $user ) ) {
			$waiting = count( Teams::pending() );
			$html   .= '<p>' . esc_html(
				/* translators: %d: number of organizations. */
				$waiting > 0 ? sprintf( _n( '%d organization is waiting for approval.', '%d organizations are waiting for approval.', $waiting, 'packout-core' ), $waiting ) : __( 'No organization is waiting for approval.', 'packout-core' )
			) . ' <a href="' . esc_url( admin_url( 'admin.php?page=packout-teams' ) ) . '">' . esc_html__( 'Open Organizations in the dashboard', 'packout-core' ) . '</a></p>';
		}

		$asked = Teams::requested_by( $user );
		if ( $asked ) {
			$html .= '<h3>' . esc_html__( 'Requests you sent', 'packout-core' ) . '</h3><ul class="pos-ws__rows">';
			foreach ( $asked as $team ) {
				$html .= '<li><span>' . esc_html( $team->post_title ) . ' <span class="pos-ws__muted">' . esc_html__( 'Waiting for the lead', 'packout-core' ) . '</span></span>';
				/* translators: %s: organization name. */
				$html .= Workspace::act( 'withdraw', $team->ID, __( 'Withdraw', 'packout-core' ), array(), '', '', sprintf( __( 'Withdraw your request to join %s', 'packout-core' ), $team->post_title ) ) . '</li>';
			}
			$html .= '</ul>';
		}

		$open = array_filter( Teams::listed(), static fn( $team ) => ! Teams::includes( $team['id'], $user ) && 'closed' !== Vocabularies::join_behavior( Teams::join_policy( $team['id'] ) ) );
		if ( $open ) {
			$rows = array();
			foreach ( $open as $team ) {
				$rows[] = array(
					'id'    => (string) $team['id'],
					'title' => $team['name'],
					'url'   => $team['url'],
					'join'  => 'open' === Vocabularies::join_behavior( Teams::join_policy( $team['id'] ) ) ? __( 'Open to join', 'packout-core' ) : __( 'Ask to join', 'packout-core' ),
				);
			}
			$html .= '<h3>' . esc_html__( 'Organizations you can join', 'packout-core' ) . '</h3>';
			$html .= Workspace_Lists::view(
				'teams-open',
				array(
					'join' => array(
						'label' => __( 'How to join', 'packout-core' ),
						'sort'  => true,
					),
				),
				$rows,
				array( 'title' => __( 'Organization', 'packout-core' ) )
			);
		}//end if

		$form  = '<p>' . esc_html__( 'A coordinator reviews every new organization before it can be listed or plan missions. While you wait you can invite people and set up teams, sponsors, and resources.', 'packout-core' ) . '</p>';
		$form .= Workspace::open( 'propose', 0 );
		$form .= self::profile_fields( 'propose', null );
		$form .= Workspace::submit( __( 'Send the organization for approval', 'packout-core' ) ) . '</form>';
		Workspace_Lists::pane( 'team-new', __( 'Start an organization', 'packout-core' ), $form );
		if ( 'propose' === self::failed() ) {
			Workspace_Lists::reopen( 'team-new' );
		}
		// The partners a person leads, under their own heading. A page that carries the Partner workspace block shows them there.
		if ( ! Partner_Workspace::has_own_block() ) {
			$html .= Partner_Workspace::home( $user );
		}
		return $html . Workspace_Lists::panes();
	}

	/**
	 * The action whose form was refused, by what the workspace kept of it.
	 */
	public static function failed(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Naming the form to reopen.
		return isset( $_POST['packout_workspace'] ) ? sanitize_key( wp_unslash( $_POST['packout_workspace'] ) ) : '';
	}

	/**
	 * Name, description, region, and website.
	 *
	 * @param string        $act   The form's action.
	 * @param \WP_Post|null $team Team, or null for a new one.
	 */
	private static function profile_fields( string $act, ?\WP_Post $team ): string {
		$html  = Workspace::input(
			'name',
			__( 'Organization name', 'packout-core' ),
			Workspace::old( $act, 'name', $team ? $team->post_title : '' ),
			array(
				'required'  => '',
				'minlength' => '3',
				'maxlength' => '80',
			)
		);
		$html .= Workspace::area( 'description', __( 'What the organization does', 'packout-core' ), Workspace::old( $act, 'description', $team ? $team->post_content : '' ), __( 'Shown on the organization\'s public page when the organization is listed.', 'packout-core' ) );
		$html .= Workspace::input( 'region', __( 'Where the organization works', 'packout-core' ), Workspace::old( $act, 'region', $team ? Teams::field( $team->ID, 'region' ) : '' ), array( 'maxlength' => '80' ), __( 'A region, forest, or county. Not a street address.', 'packout-core' ) );
		$types = array( '' => __( 'Not set', 'packout-core' ) ) + Vocabularies::labels( Vocabularies::GROUP_TYPE );
		$html .= Workspace::choose( 'group_type', __( 'Type of group', 'packout-core' ), $types, Workspace::old( $act, 'group_type', $team ? Teams::group_type( $team->ID ) : '' ) );
		return $html . Workspace::input(
			'website',
			__( 'Website', 'packout-core' ),
			Workspace::old( $act, 'website', $team ? Teams::field( $team->ID, 'website' ) : '' ),
			array(
				'type'        => 'url',
				'placeholder' => 'https://',
			)
		);
	}

	/**
	 * Badges for a team's state and listing.
	 *
	 * @param \WP_Post $team Team.
	 */
	private static function team_badges( \WP_Post $team ): string {
		if ( ! Teams::active( $team->ID ) ) {
			return Admin::badge( __( 'Waiting for approval', 'packout-core' ), 'medium' );
		}
		return Admin::badge( 'publish' === $team->post_status ? __( 'Listed publicly', 'packout-core' ) : __( 'Not listed', 'packout-core' ), 'publish' === $team->post_status ? 'stable' : 'none' );
	}

	/**
	 * A team's state and listing, in words.
	 *
	 * @param \WP_Post $team Team.
	 */
	private static function team_state( \WP_Post $team ): string {
		if ( ! Teams::active( $team->ID ) ) {
			return __( 'Waiting for approval', 'packout-core' );
		}
		return 'publish' === $team->post_status ? __( 'Listed publicly', 'packout-core' ) : __( 'Not listed', 'packout-core' );
	}

	/**
	 * The pane to open again, when a form that lives in one was refused.
	 */
	public static function refused_pane(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Naming the pane to reopen. Nothing is changed.
		$item = isset( $_POST['item'] ) ? (int) $_POST['item'] : 0;
		$who  = isset( $_POST['who'] ) ? (int) $_POST['who'] : 0;
		$sub  = isset( $_POST['subteam'] ) ? (int) $_POST['subteam'] : 0;
		$from = isset( $_POST['from'] ) ? sanitize_key( wp_unslash( $_POST['from'] ) ) : '';
		$for  = isset( $_POST['for'] ) ? sanitize_key( wp_unslash( $_POST['for'] ) ) : '';
		$on   = isset( $_POST['mission'] ) ? (int) $_POST['mission'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === Workspace::problem() ) {
			return '';
		}
		switch ( self::failed() ) {
			case 'invite':
				return 'subteams' === $from && $sub > 0 ? 'subteam-' . $sub : 'invite';
			case 'colead':
			case 'remove_member':
				return 'member-' . $who;
			case 'subteam_save':
				return $item > 0 ? 'subteam-' . $item : 'subteam-new';
			case 'subteam_member':
			case 'subteam_colead':
			case 'subteam_logo':
			case 'subteam_logo_remove':
			case 'subteam_cover':
			case 'subteam_cover_remove':
			case 'subteam_page':
			case 'subteam_youth':
			case 'subteam_answer':
			case 'subteam_leave':
			case 'subteam_remove':
				return 'subteam-' . $item;
			case 'resource_add':
				return 'mission' === $from ? (string) $for : 'resource-new';
			case 'resource_remove':
				return $on > 0 ? 'mission-' . $on : 'resource-' . $item;
			case 'mission_save':
				return $item > 0 ? 'mission-' . $item : 'mission-new';
			case 'mission_status':
			case 'mission_trash':
			case 'mission_repeat':
				return 'mission-' . $item;
			case 'campaign_save':
				return $item > 0 ? 'campaign-' . $item : 'campaign-new';
			case 'campaign_remove':
			case 'joint_open':
			case 'joint_close':
			case 'joint_invite':
			case 'joint_remove':
				return 'campaign-' . $item;
			case 'joint_leave':
				// The form names the hub's number for the campaign. On a partner site the pane is the copy's.
				return 'campaign-' . ( App::is_hub() ? $item : Joint::copy_of( $item ) );
			case 'cover_remove':
				return ( Missions::get( $item ) ? 'mission-' : 'campaign-' ) . $item;
			case 'sponsor_save':
				return $item > 0 ? 'sponsor-' . $item : 'sponsor-new';
			case 'sponsor_remove':
				return 'sponsor-' . $item;
			case 'adoption_save':
			case 'adoption_decide':
				return 'request-' . sanitize_key( isset( $_POST['request'] ) ? wp_unslash( $_POST['request'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Naming the pane to reopen.
			case 'message':
				return 'message-new';
			case 'impact_verify':
			case 'impact_unverify':
				return 'record-' . $item;
		}//end switch
		return Partner_Workspace::refused_pane( self::failed(), $item );
	}

	/**
	 * Facts as a definition list, leaving out the empty ones. Values are escaped here.
	 *
	 * @param array<string, string> $facts Values by label.
	 */
	public static function facts( array $facts ): string {
		$html = '';
		foreach ( $facts as $label => $value ) {
			if ( '' !== $value ) {
				$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
			}
		}
		return '' !== $html ? '<dl class="pos-ws__facts">' . $html . '</dl>' : '';
	}

	/**
	 * A person's place on a team, in words.
	 *
	 * @param int $team Team ID.
	 * @param int $user User ID.
	 */
	private static function role_line( int $team, int $user ): string {
		if ( Teams::lead( $team ) === $user ) {
			return __( 'You lead this organization.', 'packout-core' );
		}
		if ( Teams::leads_team( $team, $user ) ) {
			return __( 'You co-lead this organization.', 'packout-core' );
		}
		$led = Subteams::names( $team, Subteams::led_by( $team, $user ) );
		if ( $led ) {
			/* translators: %s: team names. */
			return sprintf( __( 'You are a member, and you lead or co-lead %s.', 'packout-core' ), implode( ', ', $led ) );
		}
		if ( ! Teams::includes( $team, $user ) && Partners::reaches( $team, $user ) ) {
			/* translators: %s: partner name. */
			return sprintf( __( 'You lead its partner, %s. You are not a member of this organization.', 'packout-core' ), get_the_title( Partners::of_team( $team ) ) );
		}
		if ( ! Teams::includes( $team, $user ) ) {
			return __( 'You are a coordinator. You are not in this organization.', 'packout-core' );
		}
		$in = Subteams::names( $team, Subteams::of_user( $team, $user ) );
		/* translators: %s: team names. */
		return $in ? sprintf( __( 'You are a member, in %s.', 'packout-core' ), implode( ', ', $in ) ) : __( 'You are a member.', 'packout-core' );
	}

	/**
	 * One team: its header, its tabs, and the view the address named.
	 *
	 * @param \WP_Post $team Team.
	 * @param string   $view View name.
	 * @param string   $item Record the view is about.
	 * @param int      $user User ID.
	 */
	public static function team( \WP_Post $team, string $view, string $item, int $user ): string {
		$id    = $team->ID;
		$runs  = Teams::can_run( $id, $user );
		$plans = Missions::can_plan( $id, $user );
		$tabs  = array(
			''          => __( 'Overview', 'packout-core' ),
			'missions'  => __( 'Missions', 'packout-core' ),
			'campaigns' => __( 'Campaigns', 'packout-core' ),
			'members'   => __( 'Members', 'packout-core' ),
			'subteams'  => __( 'Teams', 'packout-core' ),
			'resources' => __( 'Resources', 'packout-core' ),
			'messages'  => __( 'Messages', 'packout-core' ),
		);
		if ( $runs ) {
			$tabs['sponsors']    = __( 'Sponsors', 'packout-core' );
			$tabs['submissions'] = __( 'Submissions', 'packout-core' );
		}
		// Programs, for an organization that takes part in one or may open its own.
		if ( Programs::of_team( $id ) || Programs::can_open( 'team', $id, $user ) ) {
			$tabs = array_slice( $tabs, 0, 3, true ) + array( 'programs' => __( 'Programs', 'packout-core' ) ) + array_slice( $tabs, 3, null, true );
		}
		// The calendar comes after what it shows: missions, campaigns, and programs.
		$before         = isset( $tabs['programs'] ) ? 4 : 3;
		$tabs           = array_slice( $tabs, 0, $before, true ) + array( 'calendar' => __( 'Calendar', 'packout-core' ) ) + array_slice( $tabs, $before, null, true );
		$tabs['impact'] = __( 'Impact', 'packout-core' );
		if ( $runs ) {
			$tabs['settings'] = __( 'Settings', 'packout-core' );
		}
		$parent = array(
			'mission'  => 'missions',
			'campaign' => 'campaigns',
			'program'  => 'programs',
		);
		$tab    = $parent[ $view ] ?? $view;
		if ( ! isset( $tabs[ $tab ] ) ) {
			$view = '';
			$tab  = '';
		}

		$html  = '<p class="pos-ws__back"><a href="' . esc_url( Workspace::url() ) . '">' . esc_html__( 'All my organizations', 'packout-core' ) . '</a></p>';
		$html .= '<header class="pos-ws__head">' . Teams::logo_html( $id, 'pos-ws__logo', 'thumbnail' ) . '<h2>' . esc_html( $team->post_title ) . '</h2><p class="pos-ws__badges">' . self::team_badges( $team ) . '</p></header>';
		if ( ! Teams::active( $id ) ) {
			$html .= Workspace::notice( 'info', __( 'This organization is waiting for a coordinator. Until it is approved it is not listed, and it cannot plan campaigns or missions. Everything else is ready to set up.', 'packout-core' ) );
		}
		if ( Remote::moved( $id ) > 0 ) {
			$html .= Workspace::notice( 'info', Remote::moved_words( $id ) );
		}
		/**
		 * Filters what stands above the tabs of an organization's workspace. The hub puts a partner site's request about the organization here.
		 *
		 * @param string   $above Escaped HTML.
		 * @param \WP_Post $team  The organization.
		 * @param int      $user  The person looking.
		 */
		$html .= (string) apply_filters( 'packout_workspace_team_above', '', $team, $user );
		$html .= '<nav class="pos-ws__tabs" aria-label="' . esc_attr__( 'Organization', 'packout-core' ) . '"><ul>';
		foreach ( $tabs as $key => $label ) {
			$html .= '<li><a href="' . esc_url( Workspace::url( $id, $key ) ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		}
		$html .= '</ul></nav><div class="pos-ws__view">';

		// A mission and a campaign open in the drawer, over their list. An address that names one opens its pane.
		$named = '';
		switch ( $view ) {
			case 'missions':
			case 'mission':
				$body  = self::missions( $team, $user, $plans );
				$named = 'mission' === $view ? 'mission-' . ( 'new' === $item ? 'new' : (int) $item ) : '';
				if ( '' !== $named && ! Workspace_Lists::has_pane( $named ) ) {
					$html .= Workspace::notice( 'warning', 'new' !== $item ? __( 'That mission is not one of yours in this organization.', 'packout-core' ) : ( Teams::active( $id ) ? __( 'This organization’s leads plan its missions. A lead can let members plan in the organization’s settings.', 'packout-core' ) : __( 'Only an approved organization can plan a mission.', 'packout-core' ) ) );
				}
				$html .= $body;
				break;
			case 'campaigns':
			case 'campaign':
				$body  = self::campaigns( $team, $user, $plans );
				$named = 'campaign' === $view ? 'campaign-' . ( 'new' === $item ? 'new' : (int) $item ) : '';
				if ( '' !== $named && ! Workspace_Lists::has_pane( $named ) ) {
					$html .= Workspace::notice( 'warning', 'new' !== $item ? __( 'That campaign is not in this organization.', 'packout-core' ) : ( Teams::active( $id ) ? __( 'This organization’s leads plan its campaigns. A lead can let members plan in the organization’s settings.', 'packout-core' ) : __( 'Only an approved organization can plan a campaign.', 'packout-core' ) ) );
				}
				$html .= $body;
				break;
			case 'programs':
			case 'program':
				$body  = Partner_Workspace::programs( 'team', $id, $user );
				$named = 'program' === $view ? 'program-' . ( 'new' === $item ? 'new' : (int) $item ) : '';
				if ( '' !== $named && ! Workspace_Lists::has_pane( $named ) ) {
					$html .= Workspace::notice( 'warning', __( 'That program is not one this organization takes part in.', 'packout-core' ) );
				}
				$html .= $body;
				break;
			case 'calendar':
				$html .= '<p>' . esc_html__( 'This organization\'s missions, campaigns, and programs, as you are shown them. A mission opens where you work on it.', 'packout-core' ) . '</p>';
				$html .= Calendar_View::embedded(
					array(
						'view'         => 'month',
						'organization' => $id,
					)
				);
				break;
			case 'members':
				$html .= self::members( $team, $user, $runs );
				break;
			case 'subteams':
				$html .= self::subteams( $team, $user, $runs );
				break;
			case 'resources':
				$html .= self::resources( $team, $user );
				break;
			case 'messages':
				$html .= Messages::view( $team, $user );
				break;
			case 'sponsors':
				$html .= self::sponsors( $team );
				break;
			case 'submissions':
				$html .= self::submissions( $team, $user );
				break;
			case 'impact':
				$html .= self::impact( $team, $user );
				break;
			case 'settings':
				$html .= self::settings( $team, $user );
				break;
			default:
				$html .= self::overview( $team, $user );
		}//end switch
		// A form in a pane that was refused comes back with its pane open.
		if ( '' !== self::refused_pane() ) {
			Workspace_Lists::reopen( self::refused_pane() );
		} elseif ( '' !== $named && Workspace_Lists::has_pane( $named ) ) {
			Workspace_Lists::reopen( $named );
		}
		return $html . '</div>' . Workspace_Lists::panes();
	}

	/**
	 * A person's name, or a placeholder for an account that is gone.
	 *
	 * @param int $user_id User ID.
	 */
	public static function name( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : __( 'Someone who left', 'packout-core' );
	}

	/**
	 * Overview: the profile, what is coming up, and the team's resources.
	 *
	 * @param \WP_Post $team Team.
	 * @param int      $user User ID.
	 */
	private static function overview( \WP_Post $team, int $user ): string {
		$id   = $team->ID;
		$html = '<p>' . esc_html( self::role_line( $id, $user ) ) . '</p>';
		if ( '' !== trim( $team->post_content ) ) {
			$html .= wpautop( esc_html( $team->post_content ) );
		}

		$facts = array(
			__( 'Lead', 'packout-core' )        => esc_html( self::name( Teams::lead( $id ) ) ),
			__( 'Co-leads', 'packout-core' )    => esc_html( implode( ', ', array_map( array( self::class, 'name' ), Teams::coleads( $id ) ) ) ),
			__( 'People', 'packout-core' )      => esc_html( number_format_i18n( count( Teams::people( $id ) ) ) ),
			__( 'New members', 'packout-core' ) => esc_html( Teams::join_policies()[ Teams::join_policy( $id ) ] ),
		);
		if ( '' !== Teams::field( $id, 'region' ) ) {
			$facts[ __( 'Works in', 'packout-core' ) ] = esc_html( Teams::field( $id, 'region' ) );
		}
		if ( '' !== Teams::field( $id, 'website' ) ) {
			$facts[ __( 'Website', 'packout-core' ) ] = '<a href="' . esc_url( Teams::field( $id, 'website' ) ) . '" rel="noopener">' . esc_html( Teams::field( $id, 'website' ) ) . '</a>';
		}
		if ( 'publish' === $team->post_status ) {
			$facts[ __( 'Public page', 'packout-core' ) ] = '<a href="' . esc_url( (string) get_permalink( $team ) ) . '">' . esc_html( (string) get_permalink( $team ) ) . '</a>';
		}
		if ( Partners::of_team( $id ) > 0 ) {
			$facts[ __( 'Partner', 'packout-core' ) ] = esc_html( get_the_title( Partners::of_team( $id ) ) );
		}
		// The Places a coordinator recorded as adopted by this team. A Place that is not listed has no page to link to.
		$adopted = array();
		foreach ( Places::of_team( $id ) as $place ) {
			$adopted[] = 'publish' === $place->post_status ? '<a href="' . esc_url( (string) get_permalink( $place ) ) . '">' . esc_html( $place->post_title ) . '</a>' : esc_html( $place->post_title );
		}
		$facts[ __( 'Adopted Places', 'packout-core' ) ] = implode( ', ', $adopted );
		$html .= '<dl class="pos-ws__facts">';
		foreach ( array_filter( $facts, static fn( $value ) => '' !== $value ) as $label => $value ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd>';
		}
		$html .= '</dl>';

		$open  = array_filter( self::team_missions( $id, $user ), static fn( $mission ) => Missions::is_open( $mission->ID ) );
		$html .= '<h3>' . esc_html__( 'Your open missions', 'packout-core' ) . '</h3>';
		$html .= self::mission_rows( 'open-missions', $open, $user, __( 'No open mission is assigned to you in this organization.', 'packout-core' ) );
		if ( $open ) {
			$html .= '<p class="pos-ws__muted">' . esc_html__( 'Checklists, outcomes, and the record of the work are kept in the field app, with or without signal.', 'packout-core' ) . '</p><p class="pos-ws__actions"><a class="pos-ws__button" href="' . esc_url( Field_App::url() ) . '">' . esc_html__( 'Open the field app', 'packout-core' ) . '</a></p>';
		}

		$resources = Resources::for_user( $id, $user );
		if ( $resources ) {
			$html .= '<h3>' . esc_html__( 'Resources', 'packout-core' ) . '</h3>' . self::resource_groups( $id, $resources, 0, 'h4' );
		}

		if ( Teams::lead( $id ) !== $user && Teams::includes( $id, $user ) ) {
			$html .= '<div class="pos-ws__group pos-ws__pane-actions pos-ws__leave">' . Workspace::act( 'leave', $id, __( 'Leave this organization', 'packout-core' ), array(), 'pos-ws__button--danger', __( 'Leave this organization?', 'packout-core' ), '', __( 'You stop seeing its missions and its messages. Your account, your reports, and the records you are credited on are unchanged.', 'packout-core' ) ) . '</div>';
		}
		return $html;
	}

	/**
	 * Missions of a team that a person can work on, newest first.
	 *
	 * @param int $team Team ID.
	 * @param int $user User ID.
	 * @return array<int, \WP_Post>
	 */
	private static function team_missions( int $team, int $user ): array {
		$found = get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 200,
				'orderby'     => 'ID',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Missions of one team.
				'meta_key'    => '_pos_team',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Missions of one team.
				'meta_value'  => $team,
			)
		);
		return array_values( array_filter( $found, static fn( $mission ) => Missions::can_work( $mission->ID, $user ) ) );
	}

	/**
	 * Whether the form that was refused on this request belongs to this record.
	 *
	 * Every mission and every campaign of a view has its own form, so what was
	 * typed goes back into the one that was sent and no other.
	 *
	 * @param string $act   The form's action.
	 * @param int    $item Record ID, or 0 for the form that makes a new one.
	 */
	public static function mine( string $act, int $item ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Finding the form that was sent.
		return self::failed() === $act && '' !== Workspace::problem() && isset( $_POST['item'] ) && (int) $_POST['item'] === $item;
	}

	/**
	 * The section of a pane the address asked for, when the address names this record.
	 *
	 * @param int $item Record ID.
	 */
	public static function part( int $item ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the address to choose what to show.
		$named = isset( $_GET['item'] ) ? (int) $_GET['item'] : 0;
		$part  = isset( $_GET['part'] ) ? sanitize_key( wp_unslash( $_GET['part'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return $named === $item ? $part : '';
	}

	/**
	 * Missions as a list: title, status, and the facts a crew plans around.
	 *
	 * Each mission opens in the drawer.
	 *
	 * @param string               $key      Name of the list.
	 * @param array<int, \WP_Post> $missions Missions.
	 * @param int                  $user     User ID.
	 * @param string               $if_empty    What to say with no missions.
	 */
	private static function mission_rows( string $key, array $missions, int $user, string $if_empty = '' ): string {
		$statuses = Missions::statuses();
		$rows     = array();
		foreach ( $missions as $mission ) {
			$id       = $mission->ID;
			$team     = Missions::team( $id );
			$campaign = Missions::campaign( $id );
			$assigned = Subteams::names( $team, Missions::subteams( $id ) );
			$status   = Missions::status( $id );
			$rows[]   = array(
				'id'       => (string) $id,
				'title'    => $mission->post_title,
				'url'      => Workspace_Lists::link( self::mission_pane( $mission, $user ) ),
				'status'   => $statuses[ $status ] ?? $status,
				'when'     => Missions::window_label( $id ),
				'spots'    => count( Missions::spots( $id ) ),
				'campaign' => $campaign > 0 ? (string) get_the_title( $campaign ) : '',
				'crew'     => $assigned ? implode( ', ', $assigned ) : __( 'Whole organization', 'packout-core' ),
				'listing'  => 'publish' === $mission->post_status ? __( 'Public', 'packout-core' ) : __( 'Organization only', 'packout-core' ),
			);
		}
		return Workspace_Lists::view(
			$key,
			array(
				'status'   => array(
					'label' => __( 'Status', 'packout-core' ),
					'sort'  => true,
				),
				'when'     => array( 'label' => __( 'When', 'packout-core' ) ),
				'spots'    => array(
					'label' => __( 'Spots', 'packout-core' ),
					'type'  => 'integer',
					'sort'  => true,
				),
				'campaign' => array(
					'label' => __( 'Campaign', 'packout-core' ),
					'sort'  => true,
				),
				'crew'     => array(
					'label' => __( 'Assigned to', 'packout-core' ),
					'sort'  => true,
				),
				'listing'  => array(
					'label' => __( 'Shown to', 'packout-core' ),
					'sort'  => true,
				),
			),
			$rows,
			array(
				'title'       => __( 'Mission', 'packout-core' ),
				'description' => 'when',
				'empty'       => $if_empty,
			)
		);
	}

	/**
	 * The missions of a team. Each opens in the drawer, and so does the form that plans one.
	 *
	 * @param \WP_Post $team  Team.
	 * @param int      $user  User ID.
	 * @param bool     $plans Whether the person may plan missions for the team.
	 */
	private static function missions( \WP_Post $team, int $user, bool $plans ): string {
		$id   = $team->ID;
		$html = '';
		if ( $plans ) {
			$html .= '<p class="pos-ws__actions">' . Workspace_Lists::opener( 'mission-new', __( 'Plan a mission', 'packout-core' ), __( 'Plan a mission', 'packout-core' ) ) . '</p>';
			Workspace_Lists::pane( 'mission-new', __( 'Plan a mission', 'packout-core' ), self::mission_form( $id, null, $user ) );
		} elseif ( Teams::can_run( $id, $user ) ) {
			$html .= '<p>' . esc_html__( 'Missions open once a coordinator has approved the organization.', 'packout-core' ) . '</p>';
		}
		return $html . self::mission_rows(
			'missions',
			self::team_missions( $id, $user ),
			$user,
			$plans ? __( 'No missions yet. A mission groups Spots into one outing for the organization or for some of its teams.', 'packout-core' ) : __( 'No mission is assigned to you in this organization.', 'packout-core' )
		);
	}

	/**
	 * Keep the pane of a mission, and name it.
	 *
	 * @param \WP_Post $mission Mission.
	 * @param int      $user    User ID.
	 * @param bool     $forms   Whether the pane has the forms of a person who may change the mission. My impact shows none.
	 */
	public static function mission_pane( \WP_Post $mission, int $user, bool $forms = true ): string {
		$pane = 'mission-' . $mission->ID;
		if ( ! Workspace_Lists::has_pane( $pane ) ) {
			Workspace_Lists::pane( $pane, $mission->post_title, self::mission_detail( $mission, $user, $forms ) );
		}
		return $pane;
	}

	/**
	 * One mission, for the drawer: what the crew needs to know, and the forms of a person who may change it.
	 *
	 * The same sections wherever it opens. On My impact it has no forms, and
	 * adds the team's name and the way to the forms.
	 *
	 * @param \WP_Post $mission Mission.
	 * @param int      $user    User ID.
	 * @param bool     $forms   Whether to print the forms.
	 */
	private static function mission_detail( \WP_Post $mission, int $user, bool $forms ): string {
		$id      = $mission->ID;
		$team_id = Missions::team( $id );
		$team    = Teams::get( $team_id );
		$manage  = Missions::can_manage( $id, $user );
		$status  = Missions::status( $id );
		$public  = 'publish' === $mission->post_status;
		$open    = Missions::is_open( $id );
		$prefix  = 'pos-mission-' . $id . '-';

		// Details.
		$details = '<p class="pos-ws__badges">' . Admin::mission_badge( $status ) . ' ' . Admin::badge( $public ? __( 'Public', 'packout-core' ) : __( 'Organization only', 'packout-core' ), $public ? 'stable' : 'none' ) . '</p>';
		$cover   = Page_Header::cover( $id );
		if ( $cover > 0 ) {
			$details .= '<p class="pos-ws__cover">' . wp_get_attachment_image( $cover, 'large', false, array( 'alt' => '' ) ) . '</p>';
		}
		if ( '' !== trim( Missions::field( $id, 'description' ) ) ) {
			$details .= wpautop( esc_html( Missions::field( $id, 'description' ) ) );
		}
		$campaign = Missions::campaign( $id );
		$assigned = Subteams::names( $team_id, Missions::subteams( $id ) );
		$sponsors = wp_list_pluck( Missions::sponsors( $id ), 'name' );
		$details .= self::facts(
			array(
				__( 'Organization', 'packout-core' )     => $forms || ! $team ? '' : $team->post_title,
				__( 'When', 'packout-core' )             => Missions::window_label( $id ),
				__( 'Objective', 'packout-core' )        => Missions::field( $id, 'objective' ),
				__( 'Where the crew meets', 'packout-core' ) => Missions::field( $id, 'meeting_note' ),
				__( 'Gear and caution', 'packout-core' ) => Missions::field( $id, 'gear' ),
				__( 'Campaign', 'packout-core' )         => $campaign > 0 ? (string) get_the_title( $campaign ) : '',
				__( 'Assigned to', 'packout-core' )      => $assigned ? implode( ', ', $assigned ) : __( 'Whole organization', 'packout-core' ),
				__( 'Supported by', 'packout-core' )     => implode( ', ', $sponsors ),
			)
		);
		$lines    = Missions::checklist( $id );
		if ( $lines ) {
			$done     = count( array_filter( $lines, static fn( $line ) => ! empty( $line['done'] ) ) );
			$details .= '<h4>' . esc_html__( 'Checklist', 'packout-core' ) . '</h4><p class="pos-ws__muted">' . esc_html(
				/* translators: 1: lines done, 2: lines in the checklist. */
				sprintf( __( '%1$s of %2$s done', 'packout-core' ), number_format_i18n( $done ), number_format_i18n( count( $lines ) ) )
			) . '</p><ul class="pos-ws__checks">';
			foreach ( $lines as $line ) {
				$details .= '<li class="' . ( $line['done'] ? 'is-done' : '' ) . '">' . esc_html( $line['text'] ) . ( $line['done'] ? ' <span class="pos-ws__muted">' . esc_html__( 'Done', 'packout-core' ) . '</span>' : '' ) . '</li>';
			}
			$details .= '</ul>';
		}
		$links = '';
		if ( $public ) {
			$links .= '<a class="pos-ws__button" href="' . esc_url( (string) get_permalink( $mission ) ) . '">' . esc_html__( 'Open the public page', 'packout-core' ) . '</a> ';
		}
		if ( $open ) {
			$links .= '<a class="pos-ws__button" href="' . esc_url( Field_App::url() ) . '">' . esc_html__( 'Open the field app', 'packout-core' ) . '</a> ';
		}
		if ( ! $forms && $manage ) {
			$links .= '<a class="pos-ws__button" href="' . esc_url( Workspace::url( $team_id, 'mission', (string) $id ) ) . '">' . esc_html__( 'Change it in My organizations', 'packout-core' ) . '</a>';
		}
		if ( '' !== $links ) {
			$details .= '<p class="pos-ws__actions">' . $links . '</p>';
		}
		if ( ! $public ) {
			$details .= '<p class="pos-ws__muted">' . esc_html__( 'This mission is shown to the organization only, so it has no public page.', 'packout-core' ) . '</p>';
		}

		// Where each Spot stands. Outcomes are recorded where the work happens.
		$recorded = Missions::recorded( $id );
		$outcomes = Missions::outcomes();
		$spots    = '<ul class="pos-ws__rows">';
		foreach ( Missions::spots( $id ) as $spot_id ) {
			$spot = Spots::get( $spot_id );
			if ( $spot ) {
				$spots .= '<li><span>' . esc_html( $spot->post_title ) . '</span><span class="pos-ws__muted">' . esc_html( isset( $recorded[ $spot_id ] ) ? $outcomes[ $recorded[ $spot_id ]['outcome'] ] : __( 'Nothing recorded yet', 'packout-core' ) ) . '</span></li>';
			}
		}
		$spots .= '</ul><p class="pos-ws__muted">' . esc_html__( 'The exact places are on the mission in the field app. Outcomes, the checklist, and the record of the work are kept there, with or without signal.', 'packout-core' ) . '</p>';

		$sections = array(
			array(
				'id'    => $prefix . 'details',
				'label' => __( 'Details', 'packout-core' ),
				'html'  => $details,
			),
			array(
				'id'    => $prefix . 'spots',
				'label' => __( 'Spots', 'packout-core' ),
				'html'  => $spots,
			),
			array(
				'id'    => $prefix . 'resources',
				'label' => __( 'Resources', 'packout-core' ),
				'html'  => self::mission_resources( $team_id, $id, $forms ? $user : 0 ),
			),
		);
		// Who signed up is for the people who run the mission, wherever the pane opens. The forms are in My teams.
		if ( $manage ) {
			$sections[] = array(
				'id'    => $prefix . 'signups',
				'label' => __( 'Signed up', 'packout-core' ),
				'html'  => Signups::list_html( $id ),
			);
		}
		if ( $forms && $manage ) {
			$sections[] = array(
				'id'    => $prefix . 'plan',
				'label' => __( 'Change the plan', 'packout-core' ),
				'html'  => self::mission_form( $team_id, $mission, $user ),
			);
			$sections[] = array(
				'id'    => $prefix . 'repeat',
				'label' => __( 'Repeat', 'packout-core' ),
				'html'  => self::mission_repeat( $team_id, $id ),
			);
			$sections[] = array(
				'id'    => $prefix . 'run',
				'label' => $open ? __( 'Start and finish', 'packout-core' ) : __( 'Move to trash', 'packout-core' ),
				'html'  => self::mission_run( $team_id, $id ),
			);
		}

		// The section to show first: the one whose form was refused, or the one the address names.
		$first = array(
			'mission_save'    => 'plan',
			'cover_remove'    => 'plan',
			'mission_status'  => 'run',
			'mission_trash'   => 'run',
			'mission_repeat'  => 'repeat',
			'resource_add'    => 'resources',
			'resource_remove' => 'resources',
		);
		$part  = self::refused_pane() === 'mission-' . $id ? ( $first[ self::failed() ] ?? '' ) : self::part( $id );
		return Workspace::sections( $mission->post_title, $sections, 'top', '' !== $part ? $prefix . $part : '' );
	}

	/**
	 * The form that repeats a mission: how often, how many times, and what each copy keeps.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Mission ID.
	 */
	private static function mission_repeat( int $team_id, int $id ): string {
		$do   = 'mission_repeat';
		$mine = self::mine( $do, $id );
		$html = '<p>' . esc_html__( 'Plan this mission again, on later days. Each copy is a mission of its own with the same title, description, objective, time of day, and visibility. It has no Spots: add them when you plan that visit. Changing one copy changes only that one.', 'packout-core' ) . '</p>';
		if ( '' === Missions::field( $id, 'start' ) ) {
			return $html . Workspace::notice( 'info', __( 'This mission has no start date. Give it one under Change the plan, and it can be repeated from that day.', 'packout-core' ) );
		}

		$every = array( '1' => __( 'Every week', 'packout-core' ) );
		for ( $weeks = 2; $weeks <= \PackOut\Core\Repeat::MAX_WEEKS; $weeks++ ) {
			/* translators: %s: number of weeks. */
			$every[ (string) $weeks ] = sprintf( __( 'Every %s weeks', 'packout-core' ), number_format_i18n( $weeks ) );
		}
		$every['month'] = __( 'On the same day each month', 'packout-core' );
		$parts          = array(
			'checklist' => __( 'The checklist, with nothing ticked', 'packout-core' ),
			'gear'      => __( 'Gear and caution', 'packout-core' ),
			'meeting'   => __( 'Where the crew meets', 'packout-core' ),
			'teams'     => __( 'The teams it is assigned to', 'packout-core' ),
			'campaign'  => __( 'Its campaign', 'packout-core' ),
			'program'   => __( 'Its program', 'packout-core' ),
			'resources' => __( 'Its resources', 'packout-core' ),
		);
		// Only what this mission has is offered.
		$has = array(
			'checklist' => (bool) Missions::checklist( $id ),
			'gear'      => '' !== Missions::field( $id, 'gear' ),
			'meeting'   => '' !== Missions::field( $id, 'meeting_note' ),
			'teams'     => (bool) Missions::subteams( $id ),
			'campaign'  => Missions::campaign( $id ) > 0,
			'program'   => Programs::of_mission( $id ) > 0,
			'resources' => (bool) array_filter( Resources::all( $team_id ), static fn( array $row ): bool => 'mission' === $row['level'] && $row['mission'] === $id ),
		);

		$html .= Workspace::open( $do, $team_id, array( 'item' => (string) $id ) );
		$html .= '<div class="pos-ws__pair">' . Workspace::choose( 'every', __( 'How often', 'packout-core' ), $every, $mine ? Workspace::old( $do, 'every', '1' ) : '1', __( 'Counted from the day this mission starts.', 'packout-core' ) );
		$html .= Workspace::input(
			'copies',
			__( 'How many more', 'packout-core' ),
			$mine ? Workspace::old( $do, 'copies', '4' ) : '4',
			array(
				'type'      => 'number',
				'min'       => '1',
				'max'       => (string) \PackOut\Core\Repeat::MAX_COPIES,
				'step'      => '1',
				'required'  => '',
				'inputmode' => 'numeric',
			),
			/* translators: %s: number of copies. */
			sprintf( __( 'From 1 to %s.', 'packout-core' ), number_format_i18n( \PackOut\Core\Repeat::MAX_COPIES ) )
		) . '</div>';
		if ( array_filter( $has ) ) {
			$kept  = $mine && isset( $_POST['carry'] ) && is_array( $_POST['carry'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['carry'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Refilling a form that was refused.
			$html .= '<fieldset class="pos-ws__set"><legend>' . esc_html__( 'Each copy keeps', 'packout-core' ) . '</legend><p class="pos-ws__help">' . esc_html__( 'Untick what a copy should start without.', 'packout-core' ) . '</p>';
			foreach ( $parts as $part => $label ) {
				if ( $has[ $part ] ) {
					$html .= Workspace::tick( 'carry[]', $label, null === $kept || in_array( $part, $kept, true ), $part );
				}
			}
			$html .= '</fieldset>';
		}
		return $html . Workspace::submit( __( 'Plan the copies', 'packout-core' ) ) . '</form>';
	}

	/**
	 * The buttons that start, complete, call off, and remove a mission.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Mission ID.
	 */
	private static function mission_run( int $team_id, int $id ): string {
		$hidden = array( 'item' => (string) $id );
		if ( ! Missions::is_open( $id ) ) {
			$html = '<p>' . esc_html__( 'This mission has ended. Moving it to the trash takes it off this list and off the organization page. Its Spots are not changed.', 'packout-core' ) . '</p>';
			return $html . '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'mission_trash', $team_id, __( 'Move to the trash', 'packout-core' ), $hidden, 'pos-ws__button--danger', __( 'Move this mission to the trash?', 'packout-core' ), '', __( 'It leaves this list and the organization page. Its Spots and its impact records are not changed.', 'packout-core' ) ) . '</div>';
		}
		$html = '';
		if ( 'planned' === Missions::status( $id ) ) {
			$html .= '<p>' . esc_html__( 'Starting moves the planned Spots to in progress. The first tick from the field app starts the mission too.', 'packout-core' ) . '</p>';
			$html .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'mission_status', $team_id, __( 'Start the mission', 'packout-core' ), $hidden + array( 'to' => 'start' ), 'pos-ws__button--primary' ) . '</div>';
		}
		$html .= '<h4>' . esc_html__( 'Complete', 'packout-core' ) . '</h4>';
		$html .= Workspace::open( 'mission_status', $team_id, $hidden + array( 'to' => 'complete' ) );
		$html .= Workspace::choose(
			'open_to',
			__( 'Spots still open when the mission completes', 'packout-core' ),
			array(
				'in_progress' => __( 'Stay in progress', 'packout-core' ),
				'spotted'     => __( 'Go back to spotted', 'packout-core' ),
			),
			'in_progress',
			__( 'Completing does not mark any Spot cleaned. Record each Spot in the field app first.', 'packout-core' )
		);
		$html .= Workspace::submit( __( 'Complete the mission', 'packout-core' ) ) . '</form>';
		$html .= '<h4>' . esc_html__( 'Call off', 'packout-core' ) . '</h4><p>' . esc_html__( 'Spots this mission moved to planned go back to spotted, unless another open mission includes them.', 'packout-core' ) . '</p>';
		return $html . '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'mission_status', $team_id, __( 'Call this mission off', 'packout-core' ), $hidden + array( 'to' => 'call_off' ), 'pos-ws__button--danger', __( 'Call this mission off?', 'packout-core' ), '', __( 'The mission ends without being completed. Spots it moved to planned go back to spotted, unless another open mission includes them. It cannot be opened again.', 'packout-core' ) ) . '</div>';
	}

	/**
	 * The form that plans a mission or changes one.
	 *
	 * @param int           $team_id Team ID.
	 * @param \WP_Post|null $mission Mission, or null for a new one.
	 * @param int           $user    User ID.
	 */
	private static function mission_form( int $team_id, ?\WP_Post $mission, int $user ): string {
		$id     = $mission ? $mission->ID : 0;
		$do     = 'mission_save';
		$mine   = self::mine( $do, $id );
		$open   = ! $mission || Missions::is_open( $id );
		$stored = static fn( string $key ): string => $mission ? Missions::field( $id, $key ) : '';
		$old    = static fn( string $name, string $fallback = '' ): string => $mine ? Workspace::old( $do, $name, $fallback ) : $fallback;
		$ticked = static fn( string $name, array $fallback ): array => $mine ? Workspace::old_list( $do, $name, $fallback ) : $fallback;
		$on     = static fn( string $name, bool $fallback ): bool => $mine ? Workspace::old_on( $do, $name, $fallback ) : $fallback;
		// The person who plans a mission decides who it is for. The lead of an assigned sub-team edits the rest.
		$owner = $mission ? Missions::can_assign( $id, $user ) : true;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A campaign to start the form with.
		$from = ! $mission && isset( $_GET['campaign'] ) ? (int) $_GET['campaign'] : 0;

		$html  = Workspace::open( $do, $team_id, array( 'item' => (string) $id ), true );
		$html .= Workspace::input(
			'title',
			__( 'Title', 'packout-core' ),
			$old( 'title', $mission ? $mission->post_title : '' ),
			array(
				'required'  => '',
				'maxlength' => '120',
			)
		);
		$html .= Workspace::area( 'description', __( 'Description', 'packout-core' ), $old( 'description', $stored( 'description' ) ), __( 'For the public mission page: what the outing is and who it suits. Shown in the header when the mission is public.', 'packout-core' ) );
		$html .= Workspace::area( 'objective', __( 'Objective: what done means', 'packout-core' ), $old( 'objective', $stored( 'objective' ) ), __( 'For the crew. It is not shown on the public page.', 'packout-core' ) );

		if ( $owner ) {
			$campaigns = array( '0' => __( 'No campaign', 'packout-core' ) );
			foreach ( Campaigns::of_team( $team_id ) as $campaign ) {
				$campaigns[ (string) $campaign->ID ] = $campaign->post_title;
			}
			// The joint campaigns the organization takes part in, and the one this mission is in already.
			foreach ( Joint::of_team( $team_id, array( 'accepted', 'left' ) ) as $campaign ) {
				if ( Campaigns::takes( $campaign->ID, $team_id, $mission ? Missions::campaign( $id ) : 0 ) ) {
					/* translators: %s: campaign title. */
					$campaigns[ (string) $campaign->ID ] = sprintf( __( '%s (joint campaign)', 'packout-core' ), $campaign->post_title );
				}
			}
			if ( count( $campaigns ) > 1 ) {
				$html .= Workspace::choose( 'campaign', __( 'Campaign', 'packout-core' ), $campaigns, $old( 'campaign', (string) ( $mission ? Missions::campaign( $id ) : $from ) ) );
			}
			// A mission in a campaign has that campaign's program, so its own field is offered only outside one.
			$in = $mission ? Missions::campaign( $id ) : $from;
			if ( \PackOut\Core\Program::offers_field( $in > 0 && Campaigns::get( $in ) ? $in : 0 ) ) {
				$html .= self::program_field( $team_id, $mission ? (int) get_post_meta( $id, \PackOut\Core\Program::FILED_META, true ) : 0, $do, $mine, count( $campaigns ) > 1 ? __( 'For a mission in no campaign. A mission in a campaign is part of that campaign\'s program.', 'packout-core' ) : '' );
			} elseif ( Programs::of_campaign( $in ) > 0 ) {
				$html .= '<p class="pos-ws__muted">' . esc_html(
					/* translators: %s: program name. */
					sprintf( __( 'Part of the program “%s”, through its campaign.', 'packout-core' ), get_the_title( Programs::of_campaign( $in ) ) )
				) . '</p>';
			}
			$subteams = wp_list_pluck( Subteams::all( $team_id ), 'name', 'id' );
			if ( $subteams ) {
				$html .= Workspace::ticks( 'subteams', __( 'Assigned to', 'packout-core' ), $subteams, $ticked( 'subteams', $mission ? Missions::subteams( $id ) : array() ), __( 'Tick the teams this mission is for. Their people see it in the field app, and their leads can run it. With none ticked, the whole organization sees it. A person who signs up on the public page joins the team ticked here.', 'packout-core' ) );
			}
		} else {
			$assigned = Subteams::names( $team_id, Missions::subteams( $id ) );
			$html    .= '<p class="pos-ws__muted">' . esc_html(
				/* translators: %s: team names. */
				sprintf( __( 'Assigned to %s. The organization\'s lead decides who a mission is for.', 'packout-core' ), implode( ', ', $assigned ) )
			) . '</p>';
		}//end if

		if ( $open ) {
			$html .= self::picker( $id, $ticked( 'spots', $mission ? Missions::spots( $id ) : array() ) );
		}
		$html .= '<div class="pos-ws__pair">' . Workspace::input( 'start', __( 'Starts', 'packout-core' ), $old( 'start', $stored( 'start' ) ), array( 'type' => 'date' ) );
		$html .= Workspace::input( 'end', __( 'Ends', 'packout-core' ), $old( 'end', $stored( 'end' ) ), array( 'type' => 'date' ) ) . '</div>';
		$html .= Schedule::inputs(
			array(
				'start_time' => $old( 'start_time', $stored( 'start_time' ) ),
				'end_time'   => $old( 'end_time', $stored( 'end_time' ) ),
				'timezone'   => $old( 'timezone', $stored( 'timezone' ) ),
			)
		);
		$html .= Workspace::input( 'meeting_note', __( 'Where the crew meets', 'packout-core' ), $old( 'meeting_note', $stored( 'meeting_note' ) ), array( 'maxlength' => '200' ) );
		$html .= Workspace::tick( 'meeting_share', __( 'Show this meeting place on the public mission page', 'packout-core' ), $on( 'meeting_share', '1' === $stored( 'meeting_share' ) ) );
		$html .= Workspace::area( 'gear', __( 'Gear and caution', 'packout-core' ), $old( 'gear', $stored( 'gear' ) ) );

		if ( $open ) {
			$lines = $mission ? Missions::checklist( $id ) : array();
			$html .= '<fieldset class="pos-ws__set"><legend>' . esc_html__( 'Checklist', 'packout-core' ) . '</legend>';
			// Present even with no lines, so clearing the last one is still an edit.
			$html .= '<input type="hidden" name="lines[]" value="" />';
			foreach ( $lines as $line ) {
				$html .= '<p class="pos-ws__line"><input type="text" name="lines[' . esc_attr( $line['id'] ) . ']" maxlength="200" value="' . esc_attr( $line['text'] ) . '" aria-label="' . esc_attr__( 'Checklist line', 'packout-core' ) . '" />' . ( $line['done'] ? ' ' . Admin::badge( __( 'Done', 'packout-core' ), 'stable' ) : '' ) . '</p>';
			}
			if ( $lines ) {
				$html .= '<p class="pos-ws__help">' . esc_html__( 'Clear a line to remove it.', 'packout-core' ) . '</p>';
			}
			$html .= Workspace::area( 'new_lines', __( 'Add lines, one per line', 'packout-core' ), $old( 'new_lines' ) ) . '</fieldset>';
		}

		$cover = $mission ? Page_Header::cover( $id ) : 0;
		if ( $owner ) {
			$sponsors = wp_list_pluck( Sponsors::all( $team_id ), 'name', 'id' );
			if ( $sponsors ) {
				$html .= Workspace::ticks( 'sponsors', __( 'Supported by', 'packout-core' ), $sponsors, $ticked( 'sponsors', $mission ? wp_list_pluck( Missions::sponsors( $id ), 'id' ) : array() ), __( 'Sponsors are credited in the header of the public mission page.', 'packout-core' ) );
			}
			$html .= self::cover_field( $cover > 0, __( 'Shown behind the header of the public mission page.', 'packout-core' ) );
		}
		$html .= Workspace::tick( 'public', __( 'Public: give the mission its own page with the title, description, dates, organization, and number of Spots. When the organization is listed, people can sign up there.', 'packout-core' ), $on( 'public', $mission && 'publish' === $mission->post_status ) );
		$html .= Workspace::submit( $mission ? __( 'Save the mission', 'packout-core' ) : __( 'Plan this mission', 'packout-core' ) ) . '</form>';
		if ( $owner && $cover > 0 ) {
			$html .= self::cover_now( $team_id, $id, $cover );
		}
		return $html;
	}

	/**
	 * The Program field of a campaign or mission form. Empty when there is no program to choose.
	 *
	 * A closed program stays in the list for the record that is already
	 * filed under it, so saving the form does not take it out.
	 *
	 * @param int    $team_id Organization ID.
	 * @param int    $current The program it is filed under now, or 0.
	 * @param string $act      The form's action.
	 * @param bool   $mine    Whether this is the form that was refused.
	 * @param string $help    A line under the field.
	 */
	private static function program_field( int $team_id, int $current, string $act, bool $mine, string $help = '' ): string {
		$choices = Programs::choices( $team_id );
		if ( $current > 0 && ! isset( $choices[ $current ] ) && Programs::get( $current ) ) {
			/* translators: %s: program name. */
			$choices[ $current ] = sprintf( __( '%s (closed)', 'packout-core' ), get_the_title( $current ) );
		}
		if ( ! $choices ) {
			return '';
		}
		$options = array( '0' => __( 'No program', 'packout-core' ) );
		foreach ( $choices as $program => $name ) {
			$options[ (string) $program ] = $name;
		}
		return Workspace::choose( 'program', __( 'Program', 'packout-core' ), $options, $mine ? Workspace::old( $act, 'program', (string) $current ) : (string) $current, $help );
	}

	/**
	 * The file input of a cover image.
	 *
	 * @param bool   $has     Whether there is a cover already.
	 * @param string $help    Where the cover is shown.
	 * @param string $without What the header shows without one.
	 */
	public static function cover_field( bool $has, string $help, string $without = '' ): string {
		return Workspace::input(
			'cover',
			$has ? __( 'Replace the cover image', 'packout-core' ) : __( 'Cover image', 'packout-core' ),
			'',
			array(
				'type'   => 'file',
				'accept' => Photos::accept(),
			),
			trim( $help . ' ' . Photos::hint() . ' ' . __( 'A wide photo reads best.', 'packout-core' ) . ' ' . ( '' !== $without ? $without : __( 'Without one the header is dark.', 'packout-core' ) ) )
		);
	}

	/**
	 * The cover a campaign or a mission has now, with the way to remove it.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Campaign or mission ID.
	 * @param int $cover   Attachment ID.
	 */
	private static function cover_now( int $team_id, int $id, int $cover ): string {
		$html  = '<h4>' . esc_html__( 'Cover image', 'packout-core' ) . '</h4><p class="pos-ws__cover">' . wp_get_attachment_image( $cover, 'large', false, array( 'alt' => '' ) ) . '</p>';
		$html .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'cover_remove', $team_id, __( 'Remove the cover image', 'packout-core' ), array( 'item' => (string) $id ), 'pos-ws__button--danger', __( 'Remove the cover image?', 'packout-core' ), '', __( 'The image is deleted. The public page keeps its header, on a dark ground.', 'packout-core' ) ) . '</div>';
		return $html;
	}

	/**
	 * The reviewed Spots that still need work, with what the picker says about each.
	 *
	 * Worked out once for a page, because every mission's form lists them.
	 *
	 * @return array<int, array{title: string, facts: array<int, string>, open: array<int, string>}>
	 */
	private static function candidates(): array {
		if ( null !== self::$candidates ) {
			return self::$candidates;
		}
		$found = get_posts(
			array(
				'post_type'   => Schema::SPOT,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => self::PICKER_LIMIT,
				'orderby'     => 'date',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Spots that still need work.
				'meta_query'  => array(
					array(
						'key'     => '_pos_status',
						'value'   => 'cleaned',
						'compare' => '!=',
					),
				),
			)
		);
		self::$candidates = array();
		foreach ( $found as $spot ) {
			self::$candidates[ $spot->ID ] = self::candidate( $spot );
		}
		return self::$candidates;
	}

	/**
	 * What the picker says about one Spot.
	 *
	 * @param \WP_Post $spot Spot.
	 * @return array{title: string, facts: array<int, string>, open: array<int, string>}
	 */
	private static function candidate( \WP_Post $spot ): array {
		$statuses = Schema::statuses();
		$category = Spots::category( $spot->ID );
		$place    = Places::of_spot( $spot->ID );
		return array(
			'title' => $spot->post_title,
			'facts' => array_values( array_filter( array( $category ? $category->name : '', $statuses[ Spots::field( $spot->ID, 'status', 'spotted' ) ] ?? '', Spots::hazard_fact( $spot->ID ), $place ? $place->post_title : '' ) ) ),
			'open'  => wp_list_pluck( Missions::open_with( $spot->ID ), 'post_title', 'ID' ),
		);
	}

	/**
	 * Reviewed Spots that can join a mission, with the ones already on it ticked.
	 *
	 * The list names each Spot and its category. It does not show where it is.
	 *
	 * @param int             $id     Mission ID, or 0.
	 * @param array<int, int> $linked The Spots to tick.
	 */
	private static function picker( int $id, array $linked ): string {
		$spots = array();
		foreach ( array_filter( array_map( array( Spots::class, 'get' ), $linked ) ) as $spot ) {
			$spots[ $spot->ID ] = self::candidates()[ $spot->ID ] ?? self::candidate( $spot );
		}
		$spots += self::candidates();

		$html = '<fieldset class="pos-ws__set pos-ws__pick"><legend>' . esc_html__( 'Spots', 'packout-core' ) . '</legend>';
		if ( ! $spots ) {
			return $html . '<p>' . esc_html__( 'No reviewed Spot is open. A Spot can join a mission once a coordinator has published it or kept it for crews.', 'packout-core' ) . '</p></fieldset>';
		}
		$html .= '<input type="search" class="pos-ws__filter" placeholder="' . esc_attr__( 'Filter Spots', 'packout-core' ) . '" aria-label="' . esc_attr__( 'Filter Spots', 'packout-core' ) . '" /><div class="pos-ws__list">';
		foreach ( $spots as $spot_id => $spot ) {
			$facts = $spot['facts'];
			foreach ( $spot['open'] as $other => $title ) {
				if ( (int) $other !== $id ) {
					/* translators: %s: mission title. */
					$facts[] = sprintf( __( 'also on “%s”', 'packout-core' ), $title );
				}
			}
			$html .= '<label class="pos-ws__tick pos-ws__item"><input type="checkbox" name="spots[]" value="' . esc_attr( (string) $spot_id ) . '"' . checked( in_array( $spot_id, $linked, true ), true, false ) . ' /> ';
			$html .= '<span><strong>' . esc_html( $spot['title'] ) . '</strong><br /><span class="pos-ws__muted">' . esc_html( implode( ' · ', $facts ) ) . '</span></span></label>';
		}
		return $html . '</div></fieldset>';
	}

	/**
	 * The campaigns of a team. Each opens in the drawer, and so does the form that plans one.
	 *
	 * @param \WP_Post $team  Team.
	 * @param int      $user  User ID.
	 * @param bool     $plans Whether the person may plan campaigns for the team.
	 */
	private static function campaigns( \WP_Post $team, int $user, bool $plans ): string {
		$id   = $team->ID;
		$html = '';
		if ( $plans ) {
			$html .= '<p class="pos-ws__actions">' . Workspace_Lists::opener( 'campaign-new', __( 'Plan a campaign', 'packout-core' ), __( 'Plan a campaign', 'packout-core' ) ) . '</p>';
			Workspace_Lists::pane( 'campaign-new', __( 'Plan a campaign', 'packout-core' ), self::campaign_form( $id, null ) );
		} elseif ( Teams::can_run( $id, $user ) ) {
			$html .= '<p>' . esc_html__( 'Campaigns open once a coordinator has approved the organization.', 'packout-core' ) . '</p>';
		}
		// An invitation to a joint campaign waits here for a lead's answer.
		$html .= Joint::invitations_html( $team, $user );
		$rows  = array();
		foreach ( Campaigns::of_team( $id ) as $campaign ) {
			$pane   = 'campaign-' . $campaign->ID;
			$rows[] = array(
				'id'       => (string) $campaign->ID,
				'title'    => $campaign->post_title,
				'url'      => Workspace_Lists::link( $pane ),
				'dates'    => Schedule::label( $campaign->ID ),
				'progress' => Campaigns::progress_line( Campaigns::progress( $campaign->ID ) ),
				'crews'    => implode( ', ', Subteams::names( $id, Campaigns::subteams( $campaign->ID ) ) ),
				'listing'  => Joint::is_joint( $campaign->ID ) ? __( 'Public, joint campaign', 'packout-core' ) : ( 'publish' === $campaign->post_status ? __( 'Public', 'packout-core' ) : __( 'Organization only', 'packout-core' ) ),
			);
			Workspace_Lists::pane( $pane, $campaign->post_title, self::campaign_detail( $campaign, $user, $plans ) );
		}
		// The joint campaigns of other hosts the organization takes part in, or took part in. Its own missions in each are what it sees.
		foreach ( Joint::of_team( $id, array( 'accepted', 'left' ) ) as $campaign ) {
			$pane = 'campaign-' . $campaign->ID;
			$mine = array_filter( Campaigns::missions( $campaign->ID ), static fn( $mission ) => Missions::team( $mission->ID ) === $id );
			$done = count( array_filter( $mine, static fn( $mission ) => 'completed' === Missions::status( $mission->ID ) ) );
			/* translators: %s: the name of the organization that hosts a campaign. */
			$host   = sprintf( __( 'Joint campaign, hosted by %s', 'packout-core' ), Joint::host( $campaign->ID ) );
			$rows[] = array(
				'id'       => (string) $campaign->ID,
				'title'    => $campaign->post_title,
				'url'      => Workspace_Lists::link( $pane ),
				'dates'    => Schedule::label( $campaign->ID ),
				/* translators: 1: number of missions, 2: number completed. */
				'progress' => sprintf( _n( '%1$d mission of yours, %2$d completed', '%1$d missions of yours, %2$d completed', count( $mine ), 'packout-core' ), count( $mine ), $done ),
				'crews'    => '',
				'listing'  => 'left' === Joint::state( $campaign->ID, $id ) ? $host . ' · ' . __( 'Left', 'packout-core' ) : $host,
			);
			Workspace_Lists::pane( $pane, $campaign->post_title, Joint::taking_pane( $campaign, $team, $user, $plans ) );
		}
		return $html . Workspace_Lists::view(
			'campaigns',
			array(
				'dates'    => array( 'label' => __( 'Dates', 'packout-core' ) ),
				'progress' => array( 'label' => __( 'Progress', 'packout-core' ) ),
				'crews'    => array(
					'label' => __( 'Teams', 'packout-core' ),
					'sort'  => true,
				),
				'listing'  => array(
					'label' => __( 'Shown to', 'packout-core' ),
					'sort'  => true,
				),
			),
			$rows,
			array(
				'title'       => __( 'Campaign', 'packout-core' ),
				'description' => 'progress',
				'empty'       => __( 'No campaigns yet. A campaign is a stretch of work with one goal: it names the teams taking part and groups their missions.', 'packout-core' ),
			)
		);
	}

	/**
	 * One campaign, for the drawer: the plan, its missions, and the forms of a person who may change it.
	 *
	 * @param \WP_Post $campaign Campaign.
	 * @param int      $user     User ID.
	 * @param bool     $plans    Whether the person may plan missions for the team.
	 */
	private static function campaign_detail( \WP_Post $campaign, int $user, bool $plans ): string {
		$id      = $campaign->ID;
		$team_id = Campaigns::team( $id );
		$change  = Campaigns::can_change( $id, $user );
		$public  = 'publish' === $campaign->post_status;
		$prefix  = 'pos-campaign-' . $id . '-';

		$details = '<p class="pos-ws__badges">' . Admin::badge( $public ? __( 'Public', 'packout-core' ) : __( 'Organization only', 'packout-core' ), $public ? 'stable' : 'none' ) . ( Joint::is_joint( $id ) ? Admin::badge( __( 'Joint campaign', 'packout-core' ), 'informational' ) : '' ) . '</p>';
		$cover   = Page_Header::cover( $id );
		if ( $cover > 0 ) {
			$details .= '<p class="pos-ws__cover">' . wp_get_attachment_image( $cover, 'large', false, array( 'alt' => '' ) ) . '</p>';
		}
		$details .= '<p>' . esc_html( Campaigns::progress_line( Campaigns::progress( $id ) ) ) . '</p>';
		$totals   = Campaigns::totals( $id );
		if ( (int) $totals['records']['cleanups'] > 0 ) {
			$details .= '<p class="pos-ws__muted">' . esc_html( Campaigns::totals_line( $totals ) ) . '</p>';
		}
		if ( '' !== trim( $campaign->post_content ) ) {
			$details .= wpautop( esc_html( $campaign->post_content ) );
		}
		$details .= self::facts(
			array(
				__( 'Goal', 'packout-core' )         => Campaigns::field( $id, 'goal' ),
				__( 'When', 'packout-core' )         => Schedule::label( $id ),
				__( 'Program', 'packout-core' )      => Programs::of_campaign( $id ) > 0 ? (string) get_the_title( Programs::of_campaign( $id ) ) : '',
				__( 'Taking part', 'packout-core' )  => implode( ', ', Subteams::names( $team_id, Campaigns::subteams( $id ) ) ),
				__( 'Supported by', 'packout-core' ) => implode( ', ', wp_list_pluck( Campaigns::sponsors( $id ), 'name' ) ),
			)
		);
		// The report is for whoever may change the campaign. It covers the campaign from its first day.
		$report = Overview_Report::link( 'campaign', $id, __( 'Impact report', 'packout-core' ), 0 );
		if ( $public ) {
			$details .= '<p class="pos-ws__actions"><a class="pos-ws__button" href="' . esc_url( (string) get_permalink( $campaign ) ) . '">' . esc_html__( 'Open the public page', 'packout-core' ) . '</a>' . $report . '</p>';
		} else {
			$details .= '<p class="pos-ws__muted">' . esc_html__( 'This campaign is shown to the organization only, so it has no public page.', 'packout-core' ) . '</p>';
			$details .= '' !== $report ? '<p class="pos-ws__actions">' . $report . '</p>' : '';
		}

		// The missions the person can work on. Each opens in the Missions view.
		$statuses = Missions::statuses();
		$missions = '';
		foreach ( Campaigns::missions( $id ) as $mission ) {
			if ( ! Missions::can_work( $mission->ID, $user ) ) {
				continue;
			}
			$facts     = array_filter( array( $statuses[ Missions::status( $mission->ID ) ] ?? '', Missions::window_label( $mission->ID ) ) );
			$missions .= '<li><span><a href="' . esc_url( Workspace::url( $team_id, 'mission', (string) $mission->ID ) ) . '">' . esc_html( $mission->post_title ) . '</a></span><span class="pos-ws__muted">' . esc_html( implode( ' · ', $facts ) ) . '</span></li>';
		}
		$missions = '' !== $missions ? '<ul class="pos-ws__rows">' . $missions . '</ul>' : '<p class="pos-ws__empty">' . esc_html( $plans ? __( 'No missions yet.', 'packout-core' ) : __( 'No mission of this campaign is assigned to you.', 'packout-core' ) ) . '</p>';
		if ( $plans ) {
			$missions .= '<p class="pos-ws__actions"><a class="pos-ws__button" href="' . esc_url( add_query_arg( 'campaign', $id, Workspace::url( $team_id, 'mission', 'new' ) ) ) . '">' . esc_html__( 'Plan a mission in this campaign', 'packout-core' ) . '</a></p>';
		}

		$sections = array(
			array(
				'id'    => $prefix . 'details',
				'label' => __( 'Details', 'packout-core' ),
				'html'  => $details,
			),
			array(
				'id'    => $prefix . 'missions',
				'label' => __( 'Missions', 'packout-core' ),
				'html'  => $missions,
			),
		);
		if ( $change ) {
			$sections[] = array(
				'id'    => $prefix . 'signups',
				'label' => __( 'Signed up', 'packout-core' ),
				'html'  => Signups::list_html( $id ),
			);
			$plan       = self::campaign_form( $team_id, $campaign );
			if ( $cover > 0 ) {
				$plan .= self::cover_now( $team_id, $id, $cover );
			}
			$plan      .= '<h4>' . esc_html__( 'Remove this campaign', 'packout-core' ) . '</h4><p>' . esc_html__( 'The campaign and its public page go away. Its missions stay with the organization, in no campaign.', 'packout-core' ) . '</p>';
			$plan      .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'campaign_remove', $team_id, __( 'Remove the campaign', 'packout-core' ), array( 'item' => (string) $id ), 'pos-ws__button--danger', __( 'Remove this campaign?', 'packout-core' ), '', __( 'The campaign and its public page go away. Its missions stay with the organization, in no campaign.', 'packout-core' ) ) . '</div>';
			$sections[] = array(
				'id'    => $prefix . 'plan',
				'label' => __( 'Change the plan', 'packout-core' ),
				'html'  => $plan,
			);
		}
		// Its host makes a campaign joint, invites the organizations that take part, and closes it.
		$sections[] = array(
			'id'    => $prefix . 'joint',
			'label' => __( 'Joint campaign', 'packout-core' ),
			'html'  => Joint::host_pane( $campaign, $user ),
		);
		$part       = self::refused_pane() === 'campaign-' . $id ? ( Joint::handles( self::failed() ) ? 'joint' : 'plan' ) : self::part( $id );
		return Workspace::sections( $campaign->post_title, $sections, 'top', '' !== $part ? $prefix . $part : '' );
	}

	/**
	 * The form that plans a campaign or changes one.
	 *
	 * @param int           $team_id  Team ID.
	 * @param \WP_Post|null $campaign Campaign, or null for a new one.
	 */
	private static function campaign_form( int $team_id, ?\WP_Post $campaign ): string {
		$id     = $campaign ? $campaign->ID : 0;
		$do     = 'campaign_save';
		$mine   = self::mine( $do, $id );
		$old    = static fn( string $name, string $fallback = '' ): string => $mine ? Workspace::old( $do, $name, $fallback ) : $fallback;
		$ticked = static fn( string $name, array $fallback ): array => $mine ? Workspace::old_list( $do, $name, $fallback ) : $fallback;

		$html  = Workspace::open( $do, $team_id, array( 'item' => (string) $id ), true );
		$html .= Workspace::input(
			'title',
			__( 'Title', 'packout-core' ),
			$old( 'title', $campaign ? $campaign->post_title : '' ),
			array(
				'required'  => '',
				'maxlength' => '120',
			)
		);
		$html .= Workspace::input( 'goal', __( 'Goal', 'packout-core' ), $old( 'goal', $campaign ? Campaigns::field( $id, 'goal' ) : '' ), array( 'maxlength' => '200' ), __( 'One line the organization can check itself against, such as "Every reported site on Forest Road 12 cleaned by October".', 'packout-core' ) );
		$html .= Workspace::area( 'description', __( 'Description', 'packout-core' ), $old( 'description', $campaign ? $campaign->post_content : '' ), __( 'Shown in the header of the public campaign page when the campaign is public.', 'packout-core' ) );
		$html .= '<div class="pos-ws__pair">' . Workspace::input( 'start', __( 'Starts', 'packout-core' ), $old( 'start', $campaign ? Campaigns::field( $id, 'start' ) : '' ), array( 'type' => 'date' ) );
		$html .= Workspace::input( 'end', __( 'Ends', 'packout-core' ), $old( 'end', $campaign ? Campaigns::field( $id, 'end' ) : '' ), array( 'type' => 'date' ) ) . '</div>';
		$html .= Schedule::inputs(
			array(
				'start_time' => $old( 'start_time', $campaign ? Campaigns::field( $id, 'start_time' ) : '' ),
				'end_time'   => $old( 'end_time', $campaign ? Campaigns::field( $id, 'end_time' ) : '' ),
				'timezone'   => $old( 'timezone', $campaign ? Campaigns::field( $id, 'timezone' ) : '' ),
			)
		);

		$html .= self::program_field( $team_id, $campaign ? (int) get_post_meta( $id, \PackOut\Core\Program::FILED_META, true ) : 0, $do, $mine, __( 'Every mission of the campaign is then part of the program.', 'packout-core' ) );

		$subteams = wp_list_pluck( Subteams::all( $team_id ), 'name', 'id' );
		if ( $subteams ) {
			$html .= Workspace::ticks( 'subteams', __( 'Teams taking part', 'packout-core' ), $subteams, $ticked( 'subteams', $campaign ? Campaigns::subteams( $id ) : array() ), __( 'Each mission of the campaign is then assigned to one or more of them. A person who signs up on the public page joins one of the teams ticked here.', 'packout-core' ) );
		} else {
			$html .= '<p class="pos-ws__muted">' . esc_html__( 'This organization has no teams yet. Add them under Teams to split a campaign between crews.', 'packout-core' ) . '</p>';
		}
		$sponsors = wp_list_pluck( Sponsors::all( $team_id ), 'name', 'id' );
		if ( $sponsors ) {
			$html .= Workspace::ticks( 'sponsors', __( 'Supported by', 'packout-core' ), $sponsors, $ticked( 'sponsors', $campaign ? wp_list_pluck( Campaigns::sponsors( $id ), 'id' ) : array() ), __( 'Sponsors are credited in the header of the public campaign page.', 'packout-core' ) );
		}
		$html .= self::cover_field( $campaign && Page_Header::cover( $id ) > 0, __( 'Shown behind the header of the public campaign page.', 'packout-core' ) );
		$html .= Workspace::tick( 'public', __( 'Public: give the campaign its own page with the goal, dates, progress, and public missions. When the organization is listed, people can sign up there.', 'packout-core' ), $mine ? Workspace::old_on( $do, 'public', false ) : ( $campaign && 'publish' === $campaign->post_status ) );
		return $html . Workspace::submit( $campaign ? __( 'Save the campaign', 'packout-core' ) : __( 'Plan this campaign', 'packout-core' ) ) . '</form>';
	}

	/**
	 * The people of a team, the requests to join, and the invitation.
	 *
	 * The invitation is an action at the top that opens in the drawer. Each
	 * person opens in the drawer too, with what a lead may do about them.
	 *
	 * @param \WP_Post $team Team.
	 * @param int      $user User ID.
	 * @param bool     $runs Whether the person runs the team.
	 */
	private static function members( \WP_Post $team, int $user, bool $runs ): string {
		$id      = $team->ID;
		$owns    = Teams::owns( $id, $user );
		$coleads = Teams::coleads( $id );
		$html    = '';

		if ( $runs ) {
			if ( Accounts::open() ) {
				$html .= '<p class="pos-ws__actions">' . Workspace_Lists::opener( 'invite', __( 'Invite a person', 'packout-core' ), __( 'Invite a person', 'packout-core' ) ) . '</p>';
				Workspace_Lists::pane( 'invite', __( 'Invite a person', 'packout-core' ), self::invite_form( $id, $owns ) );
			} else {
				$html .= '<p>' . esc_html__( 'This site has no sign-up page yet, so invitations cannot be sent.', 'packout-core' ) . '</p>';
			}

			$requests = array_filter( array_map( 'get_userdata', Teams::requests( $id ) ) );
			if ( $requests ) {
				// A person who signed up for a campaign or a mission asked to join by doing so.
				$signed = Signups::waiting( $id );
				$html  .= '<h3>' . esc_html__( 'Asked to join', 'packout-core' ) . '</h3><ul class="pos-ws__rows">';
				foreach ( $requests as $person ) {
					$hidden = array( 'who' => (string) $person->ID );
					$for    = '';
					if ( isset( $signed[ $person->ID ] ) ) {
						$event = Signups::event( $signed[ $person->ID ]['event'] );
						$named = $signed[ $person->ID ]['subteam'] > 0 ? Subteams::names( $id, array( $signed[ $person->ID ]['subteam'] ) ) : array();
						$for   = $event ? '<br /><span class="pos-ws__muted">' . esc_html(
							$named
								/* translators: 1: campaign or mission title, 2: team name. */
								? sprintf( __( 'Signed up for “%1$s”, to work with %2$s. Accepting adds them to both.', 'packout-core' ), $event['title'], $named[0] )
								/* translators: %s: campaign or mission title. */
								: sprintf( __( 'Signed up for “%s”. Accepting adds them to it.', 'packout-core' ), $event['title'] )
						) . '</span>' : '';
					}
					$html .= '<li><span>' . esc_html( $person->display_name ) . $for . '</span><span class="pos-ws__group">';
					/* translators: %s: person's name. */
					$html .= Workspace::act( 'answer', $id, __( 'Accept', 'packout-core' ), $hidden + array( 'accept' => '1' ), 'pos-ws__button--primary', '', sprintf( __( 'Accept %s', 'packout-core' ), $person->display_name ) );
					/* translators: %s: person's name. */
					$html .= Workspace::act( 'answer', $id, __( 'Decline', 'packout-core' ), $hidden, '', '', sprintf( __( 'Decline %s', 'packout-core' ), $person->display_name ) ) . '</span></li>';
				}
				$html .= '</ul>';
			}//end if
		}//end if

		$html .= '<h3>' . esc_html__( 'In the organization', 'packout-core' ) . '</h3>';
		if ( $owns && Teams::members( $id ) ) {
			$html .= '<p class="pos-ws__muted">' . esc_html(
				Teams::active( $id )
					? __( 'A co-lead runs the organization with you: members, teams, campaigns, missions, resources, and sponsors. They are given the Lead role, and the site\'s administrator is told. Only you name co-leads and hand the organization over. Open a person to change their place.', 'packout-core' )
					: __( 'A co-lead runs the organization with you. They are given the Lead role when a coordinator approves the organization. Only you name co-leads and hand the organization over. Open a person to change their place.', 'packout-core' )
			) . '</p>';
		}

		$rows = array();
		foreach ( Teams::people( $id ) as $person_id ) {
			$person = get_userdata( $person_id );
			if ( ! $person ) {
				continue;
			}
			$is_lead   = Teams::lead( $id ) === $person_id;
			$is_colead = in_array( $person_id, $coleads, true );
			$role      = $is_lead ? __( 'Organization lead', 'packout-core' ) : ( $is_colead ? __( 'Co-lead', 'packout-core' ) : __( 'Member', 'packout-core' ) );
			$crews     = implode( ', ', Subteams::names( $id, Subteams::of_user( $id, $person_id ) ) );
			$name      = $person->display_name;
			$pane      = 'member-' . $person_id;
			$row       = array(
				'id'    => (string) $person_id,
				'title' => $name . ( $person_id === $user ? ' ' . __( '(you)', 'packout-core' ) : '' ),
				'url'   => Workspace_Lists::link( $pane ),
				'role'  => $role,
				'crews' => $crews,
			);
			$facts     = array(
				__( 'Place in the organization', 'packout-core' ) => $role,
				__( 'Teams', 'packout-core' ) => $crews,
			);
			if ( $runs ) {
				// A lead reaches the people they are responsible for. Members see names only.
				$row['email'] = $person->user_email;
				// What the person chose to share with this team's leads.
				$given        = Profile::shared_with( $person_id, $id );
				$row['phone'] = $given['phone'];
				$facts       += array(
					__( 'Email', 'packout-core' ) => $person->user_email,
					__( 'Phone', 'packout-core' ) => $given['phone'],
					__( 'Mailing address', 'packout-core' ) => $given['address'] ? implode( ', ', $given['address'] ) : '',
					__( 'About', 'packout-core' ) => $given['about'],
				);
			}
			$rows[] = $row;

			$detail = self::facts( $facts );
			if ( $runs && '' === $row['phone'] && ! $given['address'] ) {
				$detail .= '<p class="pos-ws__muted">' . esc_html__( 'A phone number or a mailing address shows here when the person shares it with this organization\'s leads in My account.', 'packout-core' ) . '</p>';
			}
			$who     = array( 'who' => (string) $person_id );
			$buttons = '';
			if ( $owns && ! $is_lead ) {
				$buttons .= $is_colead
					/* translators: %s: person's name. */
					? Workspace::act( 'colead', $id, __( 'Return to member', 'packout-core' ), $who, '', '', sprintf( __( 'Return %s to member', 'packout-core' ), $name ) )
					/* translators: %s: person's name. */
					: Workspace::act( 'colead', $id, __( 'Make co-lead', 'packout-core' ), $who + array( 'on' => '1' ), '', sprintf( __( 'Make %s a co-lead of the organization?', 'packout-core' ), $name ), sprintf( __( 'Make %s a co-lead', 'packout-core' ), $name ) );
			}
			// A co-lead is removed by the lead, not by another co-lead.
			if ( $runs && ! $is_lead && $person_id !== $user && ( $owns || ! $is_colead ) ) {
				/* translators: %s: person's name. */
				$buttons .= Workspace::act( 'remove_member', $id, __( 'Remove from the organization', 'packout-core' ), $who, 'pos-ws__button--danger', sprintf( __( 'Remove %s from the organization?', 'packout-core' ), $name ), sprintf( __( 'Remove %s from the organization', 'packout-core' ), $name ), __( 'They leave every team and stop seeing the organization\'s missions. Their account and the records they are credited on are unchanged.', 'packout-core' ) );
			}
			if ( '' !== $buttons ) {
				$detail .= '<div class="pos-ws__group pos-ws__pane-actions">' . $buttons . '</div>';
			}
			Workspace_Lists::pane( $pane, $name, $detail );
		}//end foreach

		$columns = array(
			'role'  => array(
				'label' => __( 'Place in the organization', 'packout-core' ),
				'sort'  => true,
			),
			'crews' => array(
				'label' => __( 'Teams', 'packout-core' ),
				'sort'  => true,
			),
		);
		if ( $runs ) {
			$columns['email'] = array(
				'label' => __( 'Email', 'packout-core' ),
				'sort'  => true,
			);
			$columns['phone'] = array( 'label' => __( 'Phone', 'packout-core' ) );
		}
		return $html . Workspace_Lists::view(
			'members',
			$columns,
			$rows,
			array(
				'title'       => __( 'Name', 'packout-core' ),
				'description' => 'role',
			)
		);
	}

	/**
	 * The form that invites a person to the team.
	 *
	 * @param int  $id   Team ID.
	 * @param bool $owns Whether the person is the team's lead, who may invite a co-lead.
	 */
	private static function invite_form( int $id, bool $owns ): string {
		$do    = 'invite';
		$html  = Workspace::open( $do, $id );
		$html .= Workspace::input(
			'email',
			__( 'Email address', 'packout-core' ),
			Workspace::old( $do, 'email' ),
			array(
				'type'         => 'email',
				'required'     => '',
				'autocomplete' => 'off',
			),
			__( 'They get a link that is good for fourteen days. They accept with the account they have, or make one.', 'packout-core' )
		);
		if ( $owns ) {
			$html .= Workspace::choose(
				'role',
				__( 'Invite them as', 'packout-core' ),
				array(
					'member' => __( 'A member of the organization', 'packout-core' ),
					'colead' => __( 'A co-lead of the organization', 'packout-core' ),
				),
				Workspace::old( $do, 'role', 'member' ),
				Teams::active( $id )
					? __( 'A co-lead runs the organization with you and is given the Lead role when they accept: exact Spot locations, mission planning, and impact verification. The site\'s administrator is told.', 'packout-core' )
					: __( 'A co-lead runs the organization with you. They are given the Lead role when a coordinator approves the organization.', 'packout-core' )
			);
		}
		$subteams = wp_list_pluck( Subteams::all( $id ), 'name', 'id' );
		if ( $subteams ) {
			$html .= '<div class="pos-ws__pair">' . Workspace::choose( 'subteam', __( 'Team', 'packout-core' ), array( '0' => __( 'No team', 'packout-core' ) ) + $subteams, Workspace::old( $do, 'subteam', '0' ) );
			$html .= Workspace::choose(
				'subrole',
				__( 'Place in the team', 'packout-core' ),
				array(
					'member' => __( 'Member', 'packout-core' ),
					'colead' => __( 'Co-lead', 'packout-core' ),
					'lead'   => __( 'Lead', 'packout-core' ),
				),
				Workspace::old( $do, 'subrole', 'member' )
			) . '</div>';
		}
		return $html . Workspace::submit( __( 'Send the invitation', 'packout-core' ) ) . '</form>';
	}

	/**
	 * The sub-teams of a team. Each opens in the drawer, with who is in it and what a lead may change.
	 *
	 * @param \WP_Post $team Team.
	 * @param int      $user User ID.
	 * @param bool     $runs Whether the person runs the team.
	 */
	private static function subteams( \WP_Post $team, int $user, bool $runs ): string {
		$id       = $team->ID;
		$subteams = Subteams::all( $id );
		$people   = array();
		foreach ( Teams::people( $id ) as $person_id ) {
			$people[ $person_id ] = self::name( $person_id );
		}
		asort( $people );

		$html = '';
		if ( $runs ) {
			$html .= '<p class="pos-ws__actions">' . Workspace_Lists::opener( 'subteam-new', __( 'Add a team', 'packout-core' ), __( 'Add a team', 'packout-core' ) ) . '</p>';
			$form  = '<p>' . esc_html__( 'A team is a crew inside the organization, such as a chapter or a trailer crew. It has a lead and co-leads of its own.', 'packout-core' ) . '</p>';
			$form .= Workspace::open( 'subteam_save', $id, array( 'item' => '0' ) );
			$form .= Workspace::input(
				'name',
				__( 'Name', 'packout-core' ),
				Workspace::old( 'subteam_save', 'name' ),
				array(
					'required'  => '',
					'maxlength' => '60',
				)
			);
			$form .= Workspace::choose( 'lead', __( 'Lead', 'packout-core' ), array( '0' => __( 'No lead yet', 'packout-core' ) ) + $people, '0', __( 'Choose from the people in the organization, or add the team and invite its lead by email.', 'packout-core' ) );
			$form .= Workspace::submit( __( 'Add the team', 'packout-core' ) ) . '</form>';
			Workspace_Lists::pane( 'subteam-new', __( 'Add a team', 'packout-core' ), $form );
		}

		$rows = array();
		foreach ( $subteams as $subteam ) {
			$sub    = $subteam['id'];
			$pane   = 'subteam-' . $sub;
			$rows[] = array(
				'id'      => (string) $sub,
				'title'   => $subteam['name'],
				'url'     => Workspace_Lists::link( $pane ),
				'lead'    => $subteam['lead'] > 0 ? self::name( $subteam['lead'] ) : __( 'No lead yet', 'packout-core' ),
				'coleads' => implode( ', ', array_map( array( self::class, 'name' ), $subteam['coleads'] ) ),
				'people'  => count( $subteam['members'] ),
				'page'    => $subteam['public'] ? __( 'Public', 'packout-core' ) : __( 'Not public', 'packout-core' ),
				'asking'  => count( $subteam['requests'] ),
				'logo'    => $subteam['logo'] > 0 ? (string) wp_get_attachment_image_url( $subteam['logo'], 'medium' ) : '',
			);
			Workspace_Lists::pane( $pane, $subteam['name'], self::subteam_detail( $id, $subteam, $people, $user, $runs ) );
		}
		return $html . Workspace_Lists::view(
			'subteams',
			array(
				'lead'    => array(
					'label' => __( 'Lead', 'packout-core' ),
					'sort'  => true,
				),
				'coleads' => array( 'label' => __( 'Co-leads', 'packout-core' ) ),
				'people'  => array(
					'label' => __( 'Members', 'packout-core' ),
					'type'  => 'integer',
					'sort'  => true,
				),
				'page'    => array(
					'label' => __( 'Public page', 'packout-core' ),
					'sort'  => true,
				),
				'asking'  => array(
					'label' => __( 'Asking to join', 'packout-core' ),
					'type'  => 'integer',
					'sort'  => true,
				),
				'logo'    => array(
					'label' => __( 'Logo', 'packout-core' ),
					'type'  => 'media',
				),
			),
			$rows,
			array(
				'title'       => __( 'Team', 'packout-core' ),
				'media'       => 'logo',
				'description' => 'lead',
				'empty'       => __( 'No teams yet. A team is a crew inside the organization, such as a chapter or a trailer crew. It has a lead and co-leads of its own. A campaign names the teams taking part, and each mission can be assigned to one or more.', 'packout-core' ),
			)
		);
	}

	/**
	 * One sub-team, for the drawer: its people, and the forms of the person looking.
	 *
	 * @param int                  $id      Team ID.
	 * @param array<string, mixed> $subteam From Subteams::all().
	 * @param array<int, string>   $people  Names of the team's people by user ID.
	 * @param int                  $user    User ID.
	 * @param bool                 $runs    Whether the person runs the team.
	 */
	private static function subteam_detail( int $id, array $subteam, array $people, int $user, bool $runs ): string {
		$sub    = (int) $subteam['id'];
		$hidden = array( 'item' => (string) $sub );
		$can    = Subteams::can_run( $id, $sub, $user );
		$names  = Subteams::can_name( $id, $sub, $user );
		$prefix = 'pos-subteam-' . $sub . '-';

		// Who is in it.
		$html = '';
		if ( $subteam['logo'] > 0 ) {
			$html .= '<p class="pos-ws__logo-now">' . wp_get_attachment_image(
				$subteam['logo'],
				'medium',
				false,
				array(
					'class' => 'pos-ws__logo pos-ws__logo--large',
					'alt'   => '',
				)
			) . '</p>';
		}
		$html .= self::facts(
			array(
				__( 'Lead', 'packout-core' )     => $subteam['lead'] > 0 ? self::name( $subteam['lead'] ) : __( 'No lead yet', 'packout-core' ),
				__( 'Co-leads', 'packout-core' ) => implode( ', ', array_map( array( self::class, 'name' ), $subteam['coleads'] ) ),
			)
		);
		if ( Subteams::shown( $id, $sub ) ) {
			$html .= '<p class="pos-ws__actions"><a class="pos-ws__button" href="' . esc_url( Subteams::url( $id, $sub ) ) . '">' . esc_html__( 'Open the public page', 'packout-core' ) . '</a></p>';
		}
		$html .= '<h4>' . esc_html__( 'Members', 'packout-core' ) . '</h4>';
		if ( ! $subteam['members'] ) {
			$html .= '<p>' . esc_html__( 'No members yet.', 'packout-core' ) . '</p>';
		} else {
			$html .= '<ul class="pos-ws__rows">';
			foreach ( $subteam['members'] as $person_id ) {
				$is_colead = in_array( $person_id, $subteam['coleads'], true );
				$who       = $hidden + array( 'who' => (string) $person_id );
				$html     .= '<li><span>' . esc_html( self::name( $person_id ) ) . ( $is_colead ? ' <span class="pos-ws__muted">' . esc_html__( 'Co-lead', 'packout-core' ) . '</span>' : '' ) . '</span><span class="pos-ws__group">';
				if ( $names ) {
					$html .= $is_colead
						/* translators: 1: person's name, 2: team name. */
						? Workspace::act( 'subteam_colead', $id, __( 'Return to member', 'packout-core' ), $who, '', '', sprintf( __( 'Return %1$s to member of %2$s', 'packout-core' ), self::name( $person_id ), $subteam['name'] ) )
						/* translators: 1: person's name, 2: team name. */
						: Workspace::act( 'subteam_colead', $id, __( 'Make co-lead', 'packout-core' ), $who + array( 'on' => '1' ), '', '', sprintf( __( 'Make %1$s a co-lead of %2$s', 'packout-core' ), self::name( $person_id ), $subteam['name'] ) );
				}
				// A co-lead is taken out by the people who name co-leads.
				if ( $can && ( $names || ! $is_colead ) ) {
					$html .= Workspace::act(
						'subteam_member',
						$id,
						__( 'Take out', 'packout-core' ),
						$who,
						'pos-ws__button--danger',
						/* translators: 1: person's name, 2: team name. */
						sprintf( __( 'Take %1$s out of %2$s?', 'packout-core' ), self::name( $person_id ), $subteam['name'] ),
						/* translators: 1: person's name, 2: team name. */
						sprintf( __( 'Take %1$s out of %2$s', 'packout-core' ), self::name( $person_id ), $subteam['name'] ),
						__( 'They stay in the organization. They stop seeing the missions assigned to this team alone.', 'packout-core' )
					);
				}
				$html .= '</span></li>';
			}//end foreach
			$html .= '</ul>';
		}//end if
		if ( $can ) {
			$outside = array_diff_key( $people, array_flip( $subteam['members'] ), array( $subteam['lead'] => true ) );
			if ( $outside ) {
				$html .= Workspace::open( 'subteam_member', $id, $hidden + array( 'in' => '1' ), false, 'pos-ws__form--row' );
				/* translators: %s: team name. */
				$html .= Workspace::choose( 'who', sprintf( __( 'Add an organization member to %s', 'packout-core' ), $subteam['name'] ), $outside );
				$html .= '<button class="pos-ws__button">' . esc_html__( 'Add', 'packout-core' ) . '</button></form>';
			}
		}
		if ( in_array( $user, $subteam['members'], true ) ) {
			$html .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'subteam_leave', $id, __( 'Leave this team', 'packout-core' ), $hidden, 'pos-ws__button--danger', __( 'Leave this team?', 'packout-core' ), '', __( 'You stay in the organization. You stop seeing the missions assigned to this team alone.', 'packout-core' ) ) . '</div>';
		}
		$sections = array(
			array(
				'id'    => $prefix . 'people',
				'label' => __( 'People', 'packout-core' ),
				'html'  => $html,
			),
		);

		// The people who asked to join from the sub-team's public page.
		if ( $can && $subteam['requests'] ) {
			$asked = '<p class="pos-ws__help">' . esc_html__( 'These people asked to join from the team\'s public page. A team takes its members from the organization, so a person who is not in the organization yet waits for the organization\'s lead first.', 'packout-core' ) . '</p><ul class="pos-ws__rows">';
			foreach ( $subteam['requests'] as $person_id ) {
				$person  = get_userdata( $person_id );
				$who     = $hidden + array( 'who' => (string) $person_id );
				$on_team = Teams::includes( $id, $person_id );
				$asked  .= '<li><span>' . esc_html( self::name( $person_id ) ) . ( $person ? ' <span class="pos-ws__muted">' . esc_html( $person->user_email ) . '</span>' : '' ) . ( $on_team ? '' : ' <span class="pos-ws__muted">' . esc_html__( 'Waiting for the organization\'s lead', 'packout-core' ) . '</span>' ) . '</span><span class="pos-ws__group">';
				if ( $on_team ) {
					/* translators: 1: person's name, 2: team name. */
					$asked .= Workspace::act( 'subteam_answer', $id, __( 'Accept', 'packout-core' ), $who + array( 'accept' => '1' ), 'pos-ws__button--primary', '', sprintf( __( 'Accept %1$s into %2$s', 'packout-core' ), self::name( $person_id ), $subteam['name'] ) );
				}
				/* translators: 1: person's name, 2: team name. */
				$asked .= Workspace::act( 'subteam_answer', $id, __( 'Decline', 'packout-core' ), $who, '', '', sprintf( __( 'Decline the request of %1$s to join %2$s', 'packout-core' ), self::name( $person_id ), $subteam['name'] ) );
				$asked .= '</span></li>';
			}
			$sections[] = array(
				'id'    => $prefix . 'requests',
				/* translators: %s: number of people. */
				'label' => sprintf( __( 'Asking to join (%s)', 'packout-core' ), number_format_i18n( count( $subteam['requests'] ) ) ),
				'html'  => $asked . '</ul>',
			);
		}//end if

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Finding the form that was sent.
		$mine = 'invite' === self::failed() && isset( $_POST['subteam'] ) && (int) $_POST['subteam'] === $sub && isset( $_POST['from'] );
		if ( $can && Accounts::open() ) {
			// Each sub-team has its own form, so what was typed goes back to the one that failed.
			$form  = Workspace::open(
				'invite',
				$id,
				array(
					'subteam' => (string) $sub,
					'from'    => 'subteams',
				)
			);
			$form .= Workspace::input(
				'email',
				/* translators: %s: team name. */
				sprintf( __( 'Email address of the person to invite into %s', 'packout-core' ), $subteam['name'] ),
				$mine ? Workspace::old( 'invite', 'email' ) : '',
				array(
					'type'         => 'email',
					'required'     => '',
					'autocomplete' => 'off',
				),
				__( 'They join the organization in this team when they accept. The link is good for fourteen days.', 'packout-core' )
			);
			$places = array( 'member' => __( 'Member', 'packout-core' ) );
			if ( $names ) {
				$places['colead'] = __( 'Co-lead', 'packout-core' );
			}
			if ( $runs ) {
				$places['lead'] = __( 'Lead', 'packout-core' );
			}
			if ( count( $places ) > 1 ) {
				$form .= Workspace::choose( 'subrole', __( 'Place in the team', 'packout-core' ), $places, $mine ? Workspace::old( 'invite', 'subrole', 'member' ) : 'member' );
			}
			$sections[] = array(
				'id'    => $prefix . 'invite',
				'label' => __( 'Invite a person by email', 'packout-core' ),
				'html'  => $form . Workspace::submit( __( 'Send the invitation', 'packout-core' ) ) . '</form>',
			);
		}//end if
		if ( $can && Teams::active( $id ) ) {
			$has   = $subteam['logo'] > 0;
			$logo  = '<p class="pos-ws__help">' . esc_html__( 'Shown here and at the top of messages sent to this team. Without one, the organization\'s logo is used.', 'packout-core' ) . '</p>';
			$logo .= Workspace::open( 'subteam_logo', $id, $hidden, true ) . self::logo_field( $has ) . Workspace::submit( $has ? __( 'Replace the logo', 'packout-core' ) : __( 'Upload the logo', 'packout-core' ) ) . '</form>';
			if ( $has ) {
				/* translators: %s: team name. */
				$logo .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'subteam_logo_remove', $id, __( 'Remove the logo', 'packout-core' ), $hidden, 'pos-ws__button--danger', sprintf( __( 'Remove the logo of %s?', 'packout-core' ), $subteam['name'] ), sprintf( __( 'Remove the logo of %s', 'packout-core' ), $subteam['name'] ), __( 'The image is deleted. Messages to this team use the organization\'s logo.', 'packout-core' ) ) . '</div>';
			}
			$sections[] = array(
				'id'    => $prefix . 'logo',
				'label' => __( 'Logo', 'packout-core' ),
				'html'  => $logo,
			);

			$has   = $subteam['cover'] > 0;
			$cover = '';
			if ( $has ) {
				$cover .= '<p class="pos-ws__cover">' . wp_get_attachment_image( $subteam['cover'], 'large', false, array( 'alt' => '' ) ) . '</p>';
			}
			$cover .= Workspace::open( 'subteam_cover', $id, $hidden, true ) . self::cover_field( $has, __( 'The picture behind the team\'s name on its public page.', 'packout-core' ), __( 'Without one, the organization\'s cover image is used.', 'packout-core' ) ) . Workspace::submit( $has ? __( 'Replace the cover image', 'packout-core' ) : __( 'Upload the cover image', 'packout-core' ) ) . '</form>';
			if ( $has ) {
				/* translators: %s: team name. */
				$cover .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'subteam_cover_remove', $id, __( 'Remove the cover image', 'packout-core' ), $hidden, 'pos-ws__button--danger', sprintf( __( 'Remove the cover image of %s?', 'packout-core' ), $subteam['name'] ), sprintf( __( 'Remove the cover image of %s', 'packout-core' ), $subteam['name'] ), __( 'The image is deleted. The team\'s public page uses the organization\'s cover image.', 'packout-core' ) ) . '</div>';
			}
			$sections[] = array(
				'id'    => $prefix . 'cover',
				'label' => __( 'Cover image', 'packout-core' ),
				'html'  => $cover,
			);
		}//end if
		if ( $can ) {
			$sections[] = array(
				'id'    => $prefix . 'page',
				'label' => __( 'Public page', 'packout-core' ),
				'html'  => self::subteam_page_form( $id, $subteam ),
			);
		}
		if ( $runs ) {
			$form       = Workspace::open( 'subteam_save', $id, $hidden );
			$form      .= Workspace::input(
				'name',
				__( 'Name', 'packout-core' ),
				$subteam['name'],
				array(
					'required'  => '',
					'maxlength' => '60',
				)
			);
			$form      .= Workspace::choose( 'lead', __( 'Lead', 'packout-core' ), array( '0' => __( 'No lead', 'packout-core' ) ) + $people, (string) $subteam['lead'], __( 'A team lead runs the missions assigned to it, chooses its members, names its co-leads, and shares resources with it.', 'packout-core' ) );
			$form      .= Workspace::submit( __( 'Save the team', 'packout-core' ) ) . '</form>';
			$form      .= '<h4>' . esc_html__( 'Youth team', 'packout-core' ) . '</h4>';
			$form      .= '<p>' . esc_html(
				App::is_hub()
					? __( 'Tick this for a team of people under eighteen. Its public page then names no lead, nobody joins it from its page, and a mission it takes part in takes no sign-up from the public.', 'packout-core' )
					: __( 'Tick this for a team of people under eighteen. Its public page then names no lead, nobody joins it from its page, and a mission it takes part in takes no sign-up from the public. No photograph and no credit line from a Spot of its missions is sent to packout.spot.', 'packout-core' )
			) . '</p>';
			$form      .= Workspace::open( 'subteam_youth', $id, $hidden ) . Workspace::tick( 'youth', __( 'This is a youth team', 'packout-core' ), $subteam['youth'] ) . Workspace::submit( __( 'Save', 'packout-core' ) ) . '</form>';
			$form      .= '<h4>' . esc_html__( 'Remove this team', 'packout-core' ) . '</h4><p>' . esc_html__( 'Its people stay in the organization, and its resources become organization resources.', 'packout-core' ) . '</p>';
			$form      .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'subteam_remove', $id, __( 'Remove the team', 'packout-core' ), $hidden, 'pos-ws__button--danger', __( 'Remove this team?', 'packout-core' ), '', __( 'Its people stay in the organization, and its resources become organization resources. Missions and campaigns stop naming it.', 'packout-core' ) ) . '</div>';
			$sections[] = array(
				'id'    => $prefix . 'name',
				'label' => __( 'Name and lead', 'packout-core' ),
				'html'  => $form,
			);
		}//end if

		// The section whose form was refused.
		$first = array(
			'invite'               => 'invite',
			'subteam_logo'         => 'logo',
			'subteam_logo_remove'  => 'logo',
			'subteam_cover'        => 'cover',
			'subteam_cover_remove' => 'cover',
			'subteam_page'         => 'page',
			'subteam_youth'        => 'name',
			'subteam_answer'       => 'requests',
			'subteam_leave'        => 'people',
			'subteam_save'         => 'name',
			'subteam_remove'       => 'name',
		);
		$part  = self::refused_pane() === 'subteam-' . $sub ? ( $first[ self::failed() ] ?? '' ) : '';
		return Workspace::sections( $subteam['name'], $sections, 'top', '' !== $part ? $prefix . $part : '' );
	}

	/**
	 * Resources as rows, each with the button that removes it for a person who may.
	 *
	 * @param int                              $team    Team ID.
	 * @param array<int, array<string, mixed>> $rows    From Resources.
	 * @param int                              $user    User ID. 0 shows no buttons.
	 * @param int                              $mission Mission the list is shown on, to return to it.
	 * @param bool                             $placed  Whether to say which level each one is from.
	 */
	private static function resource_rows( int $team, array $rows, int $user, int $mission = 0, bool $placed = false ): string {
		$html = '<ul class="pos-ws__rows pos-ws__rows--tall">';
		foreach ( $rows as $row ) {
			$from = '';
			if ( $placed ) {
				$from = 'team' === $row['level'] ? __( 'Organization', 'packout-core' ) : ( 'subteam' === $row['level'] ? Resources::place( $team, $row ) : __( 'This mission', 'packout-core' ) );
			}
			$facts = array_filter( array( $from, 'public' === $row['audience'] ? __( 'Public', 'packout-core' ) : '', $row['file'] > 0 ? __( 'File', 'packout-core' ) : __( 'Link', 'packout-core' ), $row['note'] ) );
			$html .= '<li><span><strong><a href="' . esc_url( (string) $row['link'] ) . '" rel="nofollow noopener">' . esc_html( (string) $row['title'] ) . '</a></strong><br /><span class="pos-ws__muted">' . esc_html( implode( ' · ', $facts ) ) . '</span></span>';
			if ( $user > 0 && Resources::can_share( $team, (string) $row['level'], 'subteam' === $row['level'] ? (int) $row['subteam'] : (int) $row['mission'], $user ) ) {
				$hidden = array( 'item' => (string) $row['id'] ) + ( $mission > 0 ? array( 'mission' => (string) $mission ) : array() );
				/* translators: %s: resource title. */
				$html .= Workspace::act( 'resource_remove', $team, __( 'Remove', 'packout-core' ), $hidden, 'pos-ws__button--danger', sprintf( __( 'Remove “%s”?', 'packout-core' ), $row['title'] ), sprintf( __( 'Remove %s', 'packout-core' ), $row['title'] ), __( 'The resource stops being shared. A file it holds is deleted.', 'packout-core' ) );
			}
			$html .= '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Resources under a heading for each place they are shared: the team, each sub-team, each mission.
	 *
	 * @param int                              $team Team ID.
	 * @param array<int, array<string, mixed>> $rows From Resources.
	 * @param int                              $user User ID. 0 shows no remove buttons.
	 * @param string                           $tag  Heading element.
	 */
	private static function resource_groups( int $team, array $rows, int $user, string $tag = 'h3' ): string {
		$groups = array();
		foreach ( array( 'team', 'subteam', 'mission' ) as $level ) {
			foreach ( $rows as $row ) {
				if ( $row['level'] !== $level ) {
					continue;
				}
				$place = Resources::place( $team, $row );
				if ( 'team' === $level ) {
					$heading = __( 'The whole organization', 'packout-core' );
				} elseif ( 'subteam' === $level ) {
					/* translators: %s: team name. */
					$heading = sprintf( __( 'Team: %s', 'packout-core' ), $place );
				} else {
					/* translators: %s: mission title. */
					$heading = $row['orphan'] ? $place : sprintf( __( 'Mission: %s', 'packout-core' ), $place );
				}
				$groups[ $heading ][] = $row;
			}
		}
		$html = '';
		foreach ( $groups as $heading => $group ) {
			$html .= '<' . $tag . '>' . esc_html( $heading ) . '</' . $tag . '>' . self::resource_rows( $team, $group, $user );
		}
		return $html;
	}

	/**
	 * The fields of the form that adds a resource. The caller opens the form and names the place.
	 *
	 * @param int    $team   Team ID.
	 * @param string $act     The form's action.
	 * @param bool   $is_public Whether the place has a public page to list it on.
	 */
	private static function resource_fields( int $team, string $act, bool $is_public ): string {
		$html  = Workspace::input(
			'title',
			__( 'Title', 'packout-core' ),
			Workspace::old( $act, 'title' ),
			array(
				'required'  => '',
				'maxlength' => '120',
			)
		);
		$html .= Workspace::input(
			'url',
			__( 'Link', 'packout-core' ),
			Workspace::old( $act, 'url' ),
			array(
				'type'        => 'url',
				'placeholder' => 'https://',
			)
		);
		if ( Teams::active( $team ) ) {
			$html .= Workspace::input(
				'file',
				__( 'Or a file', 'packout-core' ),
				'',
				array(
					'type'   => 'file',
					'accept' => '.pdf,application/pdf,' . Photos::accept(),
				),
				__( 'A PDF of up to 8 MB, or any image. An image is stored as a smaller WebP copy. A file wins over a link.', 'packout-core' )
			);
		} else {
			$html .= '<p class="pos-ws__help">' . esc_html__( 'Files can be added once the organization is approved.', 'packout-core' ) . '</p>';
		}
		$html .= Workspace::input( 'note', __( 'Note', 'packout-core' ), Workspace::old( $act, 'note' ), array( 'maxlength' => '200' ) );
		if ( $is_public ) {
			$html .= Workspace::choose( 'audience', __( 'Who it is listed for', 'packout-core' ), Resources::audiences(), Workspace::old( $act, 'audience', 'members' ), __( 'A public resource is listed on the organization\'s public page, or on the mission\'s public page when it is shared with a mission. A team resource is never public. Anyone who is given a file\'s address can open it, so do not upload anything private.', 'packout-core' ) );
		}
		return $html;
	}

	/**
	 * What the team shares with this person. Each resource opens in the drawer, and so does the form that adds one.
	 *
	 * @param \WP_Post $team Team.
	 * @param int      $user User ID.
	 */
	public static function resources( \WP_Post $team, int $user ): string {
		$id        = $team->ID;
		$resources = Resources::for_user( $id, $user );
		$places    = Resources::places( $id, $user );
		$shares    = $places['team'] || $places['subteams'] || $places['missions'];
		$html      = '';

		if ( $shares ) {
			$for     = array();
			$partner = Schema::PARTNER === $team->post_type;
			if ( $places['team'] ) {
				$for['team'] = $partner ? __( 'Everyone in the partner\'s organizations', 'packout-core' ) : __( 'The whole organization and all its missions', 'packout-core' );
			}
			foreach ( $places['subteams'] as $sub => $name ) {
				/* translators: %s: team name. */
				$for[ 'subteam-' . $sub ] = sprintf( __( 'Team: %s', 'packout-core' ), $name );
			}
			foreach ( $places['missions'] as $mission => $title ) {
				/* translators: %s: mission title. */
				$for[ 'mission-' . $mission ] = sprintf( __( 'Mission: %s', 'packout-core' ), $title );
			}
			$do    = 'resource_add';
			$html .= '<p class="pos-ws__actions">' . Workspace_Lists::opener( 'resource-new', __( 'Add a resource', 'packout-core' ), __( 'Add a resource', 'packout-core' ) ) . '</p>';
			$form  = Workspace::open( $do, $id, array(), true );
			$form .= Workspace::choose( 'for', __( 'Share it with', 'packout-core' ), $for, Workspace::old( $do, 'for', (string) array_key_first( $for ) ), $partner ? __( 'A partner\'s resource reaches every member of every organization under it.', 'packout-core' ) : __( 'An organization resource reaches every member and every mission. A team resource reaches that team and the missions assigned to it. A mission resource reaches that mission\'s crew.', 'packout-core' ) );
			$form .= self::resource_fields( $id, $do, $places['team'] || $places['missions'] );
			$form .= Workspace::submit( __( 'Add the resource', 'packout-core' ) ) . '</form>';
			Workspace_Lists::pane( 'resource-new', __( 'Add a resource', 'packout-core' ), $form );
		}//end if

		$rows = array();
		foreach ( $resources as $row ) {
			$level = (string) $row['level'];
			$place = Resources::place( $id, $row );
			if ( 'team' === $level ) {
				$with = Schema::PARTNER === $team->post_type ? __( 'Every organization', 'packout-core' ) : __( 'The whole organization', 'packout-core' );
			} elseif ( 'subteam' === $level ) {
				/* translators: %s: team name. */
				$with = sprintf( __( 'Team: %s', 'packout-core' ), $place );
			} else {
				/* translators: %s: mission title. */
				$with = $row['orphan'] ? $place : sprintf( __( 'Mission: %s', 'packout-core' ), $place );
			}
			$kind   = $row['file'] > 0 ? __( 'File', 'packout-core' ) : __( 'Link', 'packout-core' );
			$listed = 'public' === $row['audience'] ? __( 'Public', 'packout-core' ) : __( 'Members', 'packout-core' );
			$pane   = 'resource-' . $row['id'];
			$rows[] = array(
				'id'     => (string) $row['id'],
				'title'  => (string) $row['title'],
				'url'    => Workspace_Lists::link( $pane ),
				'with'   => $with,
				'kind'   => $kind,
				'listed' => $listed,
				'note'   => (string) $row['note'],
			);

			$detail  = '<p class="pos-ws__actions"><a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( (string) $row['link'] ) . '" rel="nofollow noopener">' . esc_html( $row['file'] > 0 ? __( 'Open the file', 'packout-core' ) : __( 'Open the link', 'packout-core' ) ) . '</a></p>';
			$detail .= self::facts(
				array(
					__( 'Shared with', 'packout-core' ) => $with,
					__( 'Kind', 'packout-core' )        => $kind,
					__( 'Listed for', 'packout-core' )  => $listed,
					__( 'Note', 'packout-core' )        => (string) $row['note'],
				)
			);
			if ( Resources::can_share( $id, $level, 'subteam' === $level ? (int) $row['subteam'] : (int) $row['mission'], $user ) ) {
				/* translators: %s: resource title. */
				$detail .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'resource_remove', $id, __( 'Remove the resource', 'packout-core' ), array( 'item' => (string) $row['id'] ), 'pos-ws__button--danger', __( 'Remove this resource?', 'packout-core' ), sprintf( __( 'Remove %s', 'packout-core' ), $row['title'] ), __( 'The resource stops being shared. A file it holds is deleted.', 'packout-core' ) ) . '</div>';
			}
			Workspace_Lists::pane( $pane, (string) $row['title'], $detail );
		}//end foreach
		return $html . Workspace_Lists::view(
			'resources',
			array(
				'with'   => array(
					'label' => __( 'Shared with', 'packout-core' ),
					'sort'  => true,
				),
				'kind'   => array(
					'label' => __( 'Kind', 'packout-core' ),
					'sort'  => true,
				),
				'listed' => array(
					'label' => __( 'Listed for', 'packout-core' ),
					'sort'  => true,
				),
				'note'   => array( 'label' => __( 'Note', 'packout-core' ) ),
			),
			$rows,
			array(
				'title'       => __( 'Resource', 'packout-core' ),
				'description' => 'note',
				'empty'       => $shares ? __( 'No resources yet. Share a master agreement, a gear list, a route file, or a safety brief.', 'packout-core' ) : __( 'Nothing has been shared with you here yet.', 'packout-core' ),
			)
		);
	}

	/**
	 * On a mission: every resource that applies to it, and the form that adds one for this mission.
	 *
	 * @param int $team    Team ID.
	 * @param int $mission Mission ID.
	 * @param int $user    User ID. 0 shows the list alone.
	 */
	private static function mission_resources( int $team, int $mission, int $user ): string {
		$rows = Resources::for_mission( $mission );
		$html = $rows
			? '<p class="pos-ws__muted">' . esc_html__( 'The crew sees these on the mission in the field app: the organization\'s resources, those of the teams it is assigned to, and its own.', 'packout-core' ) . '</p>' . self::resource_rows( $team, $rows, $user, $mission, true )
			: '<p class="pos-ws__empty">' . esc_html__( 'No resource applies to this mission yet.', 'packout-core' ) . '</p>';
		if ( $user <= 0 || ! Resources::can_share( $team, 'mission', $mission, $user ) ) {
			return $html;
		}
		$do = 'resource_add';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Finding the form that was sent.
		$mine  = self::failed() === $do && '' !== Workspace::problem() && isset( $_POST['for'] ) && sanitize_key( wp_unslash( $_POST['for'] ) ) === 'mission-' . $mission;
		$html .= '<h4>' . esc_html__( 'Add a resource for this mission', 'packout-core' ) . '</h4><p class="pos-ws__help">' . esc_html__( 'A permit, a route file, or a brief for this mission. Share one with the whole organization from Resources.', 'packout-core' ) . '</p>';
		$html .= Workspace::open(
			$do,
			$team,
			array(
				'for'  => 'mission-' . $mission,
				'from' => 'mission',
			),
			true
		);
		$html .= self::resource_fields( $team, $mine ? $do : '', true );
		return $html . Workspace::submit( __( 'Add the resource', 'packout-core' ) ) . '</form>';
	}

	/**
	 * The team's sponsors. Each opens in the drawer, and so does the form that adds one.
	 *
	 * @param \WP_Post $team Team.
	 */
	/**
	 * Adoption requests for this organization.
	 *
	 * @param \WP_Post $team Organization.
	 * @param int      $user User ID.
	 */
	private static function submissions( \WP_Post $team, int $user ): string {
		unset( $user );
		$labels = array(
			'pending'  => __( 'Waiting', 'packout-core' ),
			'accepted' => __( 'Accepted', 'packout-core' ),
			'declined' => __( 'Declined', 'packout-core' ),
		);
		$html   = '<p>' . esc_html__( 'When a member asks this organization to adopt a Place, the request is listed here. Accepting it records the adoption. You can change the decision later.', 'packout-core' ) . '</p>';
		$rows   = array();
		foreach ( Places::adoption_requests_for_team( $team->ID ) as $row ) {
			$pane   = 'request-' . $row['id'];
			$rows[] = array(
				'id'     => $row['id'],
				'title'  => $row['place'],
				'url'    => Workspace_Lists::link( $pane ),
				'status' => $labels[ $row['status'] ] ?? $row['status'],
				'who'    => $row['user'],
				'when'   => $row['created'] ? mysql2date( (string) get_option( 'date_format' ), gmdate( 'Y-m-d H:i:s', strtotime( $row['created'] ) ) ) : '',
				'note'   => $row['note'],
			);
			$detail = '';
			if ( '' !== $row['place_url'] ) {
				$detail .= '<p><a href="' . esc_url( $row['place_url'] ) . '">' . esc_html( $row['place'] ) . '</a></p>';
			} else {
				$detail .= '<p>' . esc_html( $row['place'] ) . '</p>';
			}
			if ( '' !== $row['feature'] ) {
				$detail .= '<p>' . esc_html(
					sprintf(
						/* translators: %s: map feature name. */
						__( 'Started from the map feature %s.', 'packout-core' ),
						$row['feature']
					)
				) . '</p>';
			}
			if ( '' !== $row['user'] ) {
				$detail .= '<p>' . esc_html(
					sprintf(
						/* translators: %s: person name. */
						__( 'Asked by %s.', 'packout-core' ),
						$row['user']
					)
				) . '</p>';
			}
			$hidden  = array(
				'place'   => (string) $row['place_id'],
				'request' => $row['id'],
			);
			$detail .= Workspace::open( 'adoption_save', $team->ID, $hidden );
			$detail .= Workspace::input( 'note', __( 'Note', 'packout-core' ), $row['note'], array( 'maxlength' => '300' ) );
			$detail .= Workspace::submit( __( 'Save the note', 'packout-core' ) ) . '</form>';
			if ( 'accepted' !== $row['status'] ) {
				$detail .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'adoption_decide', $team->ID, __( 'Accept', 'packout-core' ), $hidden + array( 'status' => 'accepted' ), 'pos-ws__button--primary' ) . '</div>';
			}
			if ( 'declined' !== $row['status'] ) {
				$detail .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'adoption_decide', $team->ID, __( 'Decline', 'packout-core' ), $hidden + array( 'status' => 'declined' ) ) . '</div>';
			}
			Workspace_Lists::pane( $pane, $row['place'], $detail );
		}//end foreach
		return $html . Workspace_Lists::view(
			'submissions',
			array(
				'status' => array(
					'label' => __( 'Status', 'packout-core' ),
					'sort'  => true,
				),
				'who'    => array(
					'label' => __( 'Asked by', 'packout-core' ),
					'sort'  => true,
				),
				'when'   => array(
					'label'  => __( 'When', 'packout-core' ),
					'search' => false,
				),
				'note'   => array(
					'label' => __( 'Note', 'packout-core' ),
				),
			),
			$rows,
			array(
				'title'       => __( 'Place', 'packout-core' ),
				'layout'      => 'table',
				'description' => 'status',
				'empty'       => __( 'No adoption requests yet.', 'packout-core' ),
			)
		);
	}

	/**
	 * The Sponsors pane of an organization's or a partner's workspace.
	 *
	 * @param \WP_Post $team Organization or partner.
	 * @return string
	 */
	public static function sponsors( \WP_Post $team ): string {
		$id      = $team->ID;
		$partner = Schema::PARTNER === $team->post_type;
		$active  = $partner ? Partners::allows( $id, 'images' ) : Teams::active( $id );
		$html    = '<p>' . esc_html( $partner ? __( 'A sponsor is a credit: a name, a link, and a logo. Sponsors appear on the partner\'s public page, and on the programs that name them. A credit gives the sponsor no access.', 'packout-core' ) : __( 'A sponsor is a credit: a name, a link, and a logo. Sponsors appear on the organization\'s public page, and on the campaigns and missions that name them. A credit gives the sponsor no access.', 'packout-core' ) ) . '</p>';
		$html   .= '<p class="pos-ws__actions">' . Workspace_Lists::opener( 'sponsor-new', __( 'Add a sponsor', 'packout-core' ), __( 'Add a sponsor', 'packout-core' ) ) . '</p>';
		Workspace_Lists::pane(
			'sponsor-new',
			__( 'Add a sponsor', 'packout-core' ),
			Workspace::open( 'sponsor_save', $id, array( 'item' => '0' ), true ) . self::sponsor_fields( null, $active ) . Workspace::submit( __( 'Add the sponsor', 'packout-core' ) ) . '</form>'
		);

		$rows = array();
		foreach ( Sponsors::all( $id ) as $sponsor ) {
			$hidden = array( 'item' => (string) $sponsor['id'] );
			$pane   = 'sponsor-' . $sponsor['id'];
			$rows[] = array(
				'id'      => (string) $sponsor['id'],
				'title'   => $sponsor['name'],
				'url'     => Workspace_Lists::link( $pane ),
				'website' => $sponsor['url'],
				'logo'    => $sponsor['logo'] > 0 ? (string) wp_get_attachment_image_url( $sponsor['logo'], 'medium' ) : '',
			);

			$detail = '';
			if ( $sponsor['logo'] > 0 ) {
				$detail .= '<p class="pos-ws__card--sponsor">' . wp_get_attachment_image( $sponsor['logo'], 'medium', false, array( 'alt' => '' ) ) . '</p>';
			}
			if ( '' !== $sponsor['url'] ) {
				$detail .= '<p><a href="' . esc_url( $sponsor['url'] ) . '" rel="nofollow noopener">' . esc_html( $sponsor['url'] ) . '</a></p>';
			}
			$detail .= '<h4>' . esc_html__( 'Change the sponsor', 'packout-core' ) . '</h4>';
			$detail .= Workspace::open( 'sponsor_save', $id, $hidden, true ) . self::sponsor_fields( $sponsor, $active ) . Workspace::submit( __( 'Save the sponsor', 'packout-core' ) ) . '</form>';
			$detail .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'sponsor_remove', $id, __( 'Remove the sponsor', 'packout-core' ), $hidden, 'pos-ws__button--danger', __( 'Remove this sponsor?', 'packout-core' ), '', $partner ? __( 'Programs stop crediting it, and its logo is deleted.', 'packout-core' ) : __( 'Campaigns and missions stop crediting it, and its logo is deleted.', 'packout-core' ) ) . '</div>';
			Workspace_Lists::pane( $pane, $sponsor['name'], $detail );
		}//end foreach
		return $html . Workspace_Lists::view(
			'sponsors',
			array(
				'website' => array(
					'label' => __( 'Website', 'packout-core' ),
					'sort'  => true,
				),
				'logo'    => array(
					'label' => __( 'Logo', 'packout-core' ),
					'type'  => 'media',
				),
			),
			$rows,
			array(
				'title'       => __( 'Sponsor', 'packout-core' ),
				'layout'      => 'grid',
				'media'       => 'logo',
				'description' => 'website',
				'empty'       => __( 'No sponsors yet.', 'packout-core' ),
			)
		);
	}

	/**
	 * Name, link, and logo of a sponsor.
	 *
	 * @param array{id: int, name: string, url: string, logo: int}|null $sponsor Sponsor, or null for a new one.
	 * @param bool                                                      $active  Whether the team is approved and may add files.
	 */
	private static function sponsor_fields( ?array $sponsor, bool $active ): string {
		$html  = Workspace::input(
			'name',
			__( 'Name', 'packout-core' ),
			$sponsor ? $sponsor['name'] : Workspace::old( 'sponsor_save', 'name' ),
			array(
				'required'  => '',
				'maxlength' => '80',
			)
		);
		$html .= Workspace::input(
			'url',
			__( 'Website', 'packout-core' ),
			$sponsor ? $sponsor['url'] : Workspace::old( 'sponsor_save', 'url' ),
			array(
				'type'        => 'url',
				'placeholder' => 'https://',
			)
		);
		if ( ! $active ) {
			return $html . '<p class="pos-ws__help">' . esc_html__( 'A logo can be added once a coordinator has approved it. The name is shown until then.', 'packout-core' ) . '</p>';
		}
		return $html . Workspace::input(
			'logo',
			$sponsor && $sponsor['logo'] > 0 ? __( 'Replace the logo', 'packout-core' ) : __( 'Logo', 'packout-core' ),
			'',
			array(
				'type'   => 'file',
				'accept' => Photos::accept(),
			),
			Photos::hint() . ' ' . __( 'Without one, the name is shown.', 'packout-core' )
		);
	}

	/**
	 * The form that says what a sub-team's public page shows, where it is, and how people join.
	 *
	 * @param int                  $id      Team ID.
	 * @param array<string, mixed> $subteam From Subteams::all().
	 */
	private static function subteam_page_form( int $id, array $subteam ): string {
		$sub = (int) $subteam['id'];
		$do  = 'subteam_page';
		// Each sub-team has its own form, so what was typed goes back to the one that failed.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Finding the form that was sent.
		$mine   = self::failed() === $do && isset( $_POST['item'] ) && (int) $_POST['item'] === $sub;
		$field  = wp_unique_id( 'pos-ws-' );
		$status = $field . '-status';
		$url    = Subteams::url( $id, $sub );
		$base   = trailingslashit( Teams::address_url( $id, Teams::address( $id ) ) );

		if ( Subteams::shown( $id, $sub ) ) {
			/* translators: %s: web address. */
			$html = '<p>' . esc_html( sprintf( __( 'The team\'s public page is %s', 'packout-core' ), $url ) ) . '</p>';
		} elseif ( $subteam['public'] ) {
			$html = '<p>' . esc_html__( 'The page is turned on, but the organization is not listed, so only the people who run the team can open it.', 'packout-core' ) . '</p>';
		} else {
			$html = '<p>' . esc_html__( 'This team has no public page yet. Only the people who run it can open the page until it is made public.', 'packout-core' ) . '</p>';
		}
		if ( '' !== $url && ! Subteams::shown( $id, $sub ) && 'publish' === get_post_status( $id ) ) {
			$html .= '<p class="pos-ws__actions"><a class="pos-ws__button" href="' . esc_url( $url ) . '">' . esc_html__( 'See what the page looks like', 'packout-core' ) . '</a></p>';
		}

		$html .= Workspace::open( $do, $id, array( 'item' => (string) $sub ) );
		$html .= Workspace::area( 'about', __( 'What the team does', 'packout-core' ), $mine ? Workspace::old( $do, 'about', $subteam['about'] ) : $subteam['about'], __( 'Shown under the team\'s name on its public page. Up to 2,000 characters.', 'packout-core' ) );
		$html .= Workspace::tick( 'public', __( 'Show a public page for this team: its name, description, logo, cover image, number of people, impact, and the public campaigns and missions it takes part in. No member is named.', 'packout-core' ), $mine ? Workspace::old_on( $do, 'public', $subteam['public'] ) : $subteam['public'] );
		if ( $subteam['youth'] ) {
			$html .= '<p class="pos-ws__help">' . esc_html__( 'This is a youth team: its page names no lead, and its lead chooses its members.', 'packout-core' ) . '</p>';
		} else {
			$html .= Workspace::tick( 'leads', __( 'Name the lead and the co-leads on the page', 'packout-core' ), $mine ? Workspace::old_on( $do, 'leads', $subteam['leads'] ) : $subteam['leads'] );
		}
		$html .= '<p class="pos-ws__field"><label for="' . esc_attr( $field ) . '">' . esc_html__( 'Address', 'packout-core' ) . '</label>';
		$html .= '<span class="pos-ws__address"><span class="pos-ws__address-base">' . esc_html( $base ) . '</span>';
		$html .= '<input id="' . esc_attr( $field ) . '" type="text" name="address" required value="' . esc_attr( $mine ? Workspace::old( $do, 'address', $subteam['slug'] ) : $subteam['slug'] ) . '"' . Teams::address_attributes( $id, $status, $sub ) . ' /></span>';
		$html .= '<span class="pos-ws__address-status" id="' . esc_attr( $status ) . '" role="status"></span>';
		$html .= '<span class="pos-ws__help">' . esc_html__( 'Lower-case letters, digits, and hyphens, 3 to 60 characters, with at least one letter. Links to the old address keep working.', 'packout-core' ) . '</span></p>';
		if ( ! $subteam['youth'] ) {
			$html .= Workspace::choose( 'join', __( 'How people join', 'packout-core' ), Subteams::join_policies(), $mine ? Workspace::old( $do, 'join', $subteam['join'] ) : $subteam['join'], __( 'People join or ask from the team\'s public page, so this applies once the page is public. A team takes its members from the organization: a person who is not in the organization joins or asks the organization first, by the organization\'s own rule. You can always add organization members and invite people yourself.', 'packout-core' ) );
		}
		return $html . Workspace::submit( __( 'Save the public page', 'packout-core' ) ) . '</form>';
	}

	/**
	 * The form that uploads a team's cover image.
	 *
	 * @param \WP_Post $team Team.
	 */
	private static function cover_form( \WP_Post $team ): string {
		$id = $team->ID;
		if ( ! Teams::active( $id ) ) {
			return '<p class="pos-ws__help">' . esc_html__( 'A cover image can be added once a coordinator has approved the organization.', 'packout-core' ) . '</p>';
		}
		$cover = Teams::cover( $id );
		$has   = $cover > 0;
		$html  = '';
		if ( $has ) {
			$html .= '<p class="pos-ws__cover">' . wp_get_attachment_image( $cover, 'large', false, array( 'alt' => '' ) ) . '</p>';
		}
		$html .= Workspace::open( 'team_cover', $id, array(), true ) . self::cover_field( $has, __( 'The picture behind the organization\'s name on its public page, and on the pages of its teams that have none of their own.', 'packout-core' ) ) . Workspace::submit( $has ? __( 'Replace the cover image', 'packout-core' ) : __( 'Upload the cover image', 'packout-core' ) ) . '</form>';
		if ( $has ) {
			$html .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'team_cover_remove', $id, __( 'Remove the cover image', 'packout-core' ), array(), 'pos-ws__button--danger', __( 'Remove the organization\'s cover image?', 'packout-core' ), '', __( 'The image is deleted. The organization\'s public page keeps its header, on a dark ground.', 'packout-core' ) ) . '</div>';
		}
		return $html;
	}

	/**
	 * The form that changes a team's public address.
	 *
	 * @param \WP_Post $team Team.
	 */
	private static function address_form( \WP_Post $team ): string {
		$id     = $team->ID;
		$do     = 'address';
		$field  = wp_unique_id( 'pos-ws-' );
		$status = $field . '-status';
		$base   = Teams::address_url( $id, '' );
		$html   = '<p>' . esc_html(
			'publish' === $team->post_status
				/* translators: %s: web address. */
				? sprintf( __( 'The organization\'s public page is %s', 'packout-core' ), (string) get_permalink( $team ) )
				: __( 'The organization is not listed, so it has no public page yet. The address is used once it is.', 'packout-core' )
		) . '</p>';
		$html .= Workspace::open( $do, $id );
		$html .= '<p class="pos-ws__field"><label for="' . esc_attr( $field ) . '">' . esc_html__( 'Address', 'packout-core' ) . '</label>';
		$html .= '<span class="pos-ws__address"><span class="pos-ws__address-base">' . esc_html( trailingslashit( $base ) ) . '</span>';
		$html .= '<input id="' . esc_attr( $field ) . '" type="text" name="address" required value="' . esc_attr( Workspace::old( $do, 'address', Teams::address( $id ) ) ) . '"' . Teams::address_attributes( $id, $status ) . ' /></span>';
		$html .= '<span class="pos-ws__address-status" id="' . esc_attr( $status ) . '" role="status"></span>';
		$html .= '<span class="pos-ws__help">' . esc_html__( 'Lower-case letters, digits, and hyphens, 3 to 60 characters. Links to the old address keep working.', 'packout-core' ) . '</span></p>';
		return $html . Workspace::submit( __( 'Change the address', 'packout-core' ) ) . '</form>';
	}

	/**
	 * The form that uploads a team's logo.
	 *
	 * @param \WP_Post $team Team.
	 */
	private static function logo_form( \WP_Post $team ): string {
		$id   = $team->ID;
		$html = '';
		if ( ! Teams::active( $id ) ) {
			return '<p class="pos-ws__help">' . esc_html__( 'A logo can be added once a coordinator has approved the organization.', 'packout-core' ) . '</p>';
		}
		$has   = Teams::logo( $id ) > 0;
		$html .= '<p>' . esc_html__( 'Shown on the organization\'s public page, in the list of organizations, here, and at the top of the messages the organization sends.', 'packout-core' ) . '</p>';
		if ( $has ) {
			$html .= '<p class="pos-ws__logo-now">' . Teams::logo_html( $id, 'pos-ws__logo pos-ws__logo--large' ) . '</p>';
		}
		$html .= Workspace::open( 'logo', $id, array(), true ) . self::logo_field( $has ) . Workspace::submit( $has ? __( 'Replace the logo', 'packout-core' ) : __( 'Upload the logo', 'packout-core' ) ) . '</form>';
		if ( $has ) {
			$html .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'logo_remove', $id, __( 'Remove the logo', 'packout-core' ), array(), 'pos-ws__button--danger', __( 'Remove the organization\'s logo?', 'packout-core' ), '', __( 'The image is deleted. The organization\'s public page and its messages show its name alone.', 'packout-core' ) ) . '</div>';
		}
		return $html;
	}

	/**
	 * The file input of a logo form.
	 *
	 * @param bool $has Whether there is a logo already.
	 */
	private static function logo_field( bool $has ): string {
		return Workspace::input(
			'logo',
			$has ? __( 'New logo', 'packout-core' ) : __( 'Logo', 'packout-core' ),
			'',
			array(
				'type'     => 'file',
				'required' => '',
				'accept'   => Photos::accept(),
			),
			Photos::hint() . ' ' . __( 'A square or wide image on a plain background reads best.', 'packout-core' )
		);
	}

	/**
	 * What the team's records add up to, and the records. Each record opens in the drawer, where a lead verifies it.
	 *
	 * @param \WP_Post $team Team.
	 * @param int      $user User ID.
	 */
	private static function impact( \WP_Post $team, int $user ): string {
		$id     = $team->ID;
		$totals = Impact::totals( array( 'team' => $id ) );
		$html   = '<p>' . esc_html__( 'Every figure is the sum of the organization\'s impact records. Measured and self-reported amounts stay apart, and so do verified and unverified hours.', 'packout-core' ) . '</p>';

		$figures = array(
			__( 'Spots with a record', 'packout-core' )   => number_format_i18n( (int) $totals['outputs']['spots'] ),
			__( 'Missions completed', 'packout-core' )    => number_format_i18n( (int) $totals['effort']['missions'] ),
			__( 'Hours, verified', 'packout-core' )       => Impact::number( (float) $totals['effort']['hours_verified'] ),
			__( 'Hours, unverified', 'packout-core' )     => Impact::number( (float) $totals['effort']['hours_unverified'] ),
			__( 'Bags, measured', 'packout-core' )        => number_format_i18n( (int) $totals['outputs']['bags_measured'] ),
			__( 'Bags, self-reported', 'packout-core' )   => number_format_i18n( (int) $totals['outputs']['bags_self_reported'] ),
			__( 'Pounds, measured', 'packout-core' )      => Impact::number( (float) $totals['outputs']['pounds_measured'] ),
			__( 'Pounds, self-reported', 'packout-core' ) => Impact::number( (float) $totals['outputs']['pounds_self_reported'] ),
			__( 'Still clear on a later visit', 'packout-core' ) => number_format_i18n( (int) $totals['outcomes']['still_clear'] ),
			__( 'Came back', 'packout-core' )             => number_format_i18n( (int) $totals['outcomes']['returned'] ),
		);
		$html   .= '<dl class="pos-ws__figures">';
		foreach ( $figures as $label => $value ) {
			$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		$html .= '</dl>';

		$records = Impact::listed( array( 'team' => $id ), $user );
		if ( ! $records ) {
			return $html . '<p class="pos-ws__muted">' . esc_html( Impact::can_review( $user ) ? __( 'No records yet. The crew writes up each cleaned Spot in the field app.', 'packout-core' ) : __( 'The organization\'s lead reviews the records behind these figures.', 'packout-core' ) ) . '</p>';
		}
		$rows = array();
		foreach ( $records as $record ) {
			$row     = Impact::payload( $record );
			$cleanup = Impact::CLEANUP === $row['kind'];
			$checked = $cleanup ? ( 'verified' === $row['verification'] ? __( 'Verified', 'packout-core' ) : __( 'Unverified', 'packout-core' ) ) : '';
			$pane    = 'record-' . $record->ID;
			$rows[]  = array(
				'id'      => (string) $record->ID,
				'title'   => $row['spot_title'],
				'url'     => Workspace_Lists::link( $pane ),
				'kind'    => $row['kind_label'],
				'day'     => Impact::date( $row['date'] ),
				'person'  => $row['person'],
				'figures' => Impact::summary( $record->ID ),
				'checked' => $checked,
			);

			self::record_pane( $record, $user );
		}
		$html .= '<h3>' . esc_html__( 'Records', 'packout-core' ) . '</h3>';
		$html .= Workspace_Lists::view(
			'records',
			array(
				'kind'    => array(
					'label' => __( 'Record', 'packout-core' ),
					'sort'  => true,
				),
				'day'     => array( 'label' => __( 'Date', 'packout-core' ) ),
				'person'  => array(
					'label' => __( 'Written by', 'packout-core' ),
					'sort'  => true,
				),
				'figures' => array( 'label' => __( 'Figures', 'packout-core' ) ),
				'checked' => array(
					'label' => __( 'Verification', 'packout-core' ),
					'sort'  => true,
				),
			),
			$rows,
			array(
				'title'       => __( 'Spot', 'packout-core' ),
				'description' => 'figures',
			)
		);
		return $html . '<p class="pos-ws__muted">' . esc_html__( 'Open a record to verify it. Verifying says you checked the figures. A coordinator decides which records the public totals include.', 'packout-core' ) . '</p>';
	}

	/**
	 * A stored day as people write it, or an empty string.
	 *
	 * @param string $value Y-m-d.
	 */
	public static function day( string $value ): string {
		$value = Impact::date( $value );
		return '' === $value ? '' : (string) mysql2date( (string) get_option( 'date_format' ), $value . ' 00:00:00' );
	}

	/**
	 * Keep the pane of an impact record, and name it.
	 *
	 * The same facts wherever it opens. On My impact it has no forms, and
	 * adds the team's name and the way to the Spot's public page.
	 *
	 * @param \WP_Post $record Impact record.
	 * @param int      $user   User ID.
	 * @param bool     $forms  Whether the pane has the buttons of a person who may verify the record. My impact shows none.
	 */
	public static function record_pane( \WP_Post $record, int $user, bool $forms = true ): string {
		$pane = 'record-' . $record->ID;
		if ( Workspace_Lists::has_pane( $pane ) ) {
			return $pane;
		}
		$row     = Impact::payload( $record );
		$id      = (int) Impact::field( $record->ID, 'team', '0' );
		$team    = $id > 0 ? Teams::get( $id ) : null;
		$cleanup = Impact::CLEANUP === $row['kind'];
		$checked = $cleanup ? ( 'verified' === $row['verification'] ? __( 'Verified', 'packout-core' ) : __( 'Unverified', 'packout-core' ) ) : '';
		$mission = (int) Impact::field( $record->ID, 'mission', '0' );

		$detail = self::facts(
			array(
				__( 'Record', 'packout-core' )       => $row['kind_label'],
				__( 'Date', 'packout-core' )         => self::day( (string) $row['date'] ),
				__( 'Organization', 'packout-core' ) => $forms || ! $team ? '' : $team->post_title,
				__( 'Mission', 'packout-core' )      => $forms || $mission <= 0 || ! Missions::get( $mission ) ? '' : (string) get_the_title( $mission ),
				__( 'Written by', 'packout-core' )   => $row['person'],
				__( 'Figures', 'packout-core' )      => Impact::summary( $record->ID ),
				__( 'Verification', 'packout-core' ) => 'verified' === $row['verification'] && '' !== $row['verified_by']
					/* translators: %s: person's name. */
					? sprintf( __( 'Verified by %s', 'packout-core' ), $row['verified_by'] )
					: $checked,
				__( 'Photos', 'packout-core' )       => $row['photos'] > 0 ? number_format_i18n( $row['photos'] ) : '',
			)
		);
		if ( '' !== trim( (string) $row['narrative'] ) ) {
			$detail .= '<h4>' . esc_html__( 'What was done', 'packout-core' ) . '</h4>' . wpautop( esc_html( (string) $row['narrative'] ) );
		}
		if ( $row['come_back'] ) {
			$detail .= '<p><strong>' . esc_html__( 'The crew asked for a return visit.', 'packout-core' ) . '</strong> ' . esc_html( (string) $row['come_back_note'] ) . '</p>';
		}
		if ( ! $forms ) {
			$spot = Spots::get( (int) Impact::field( $record->ID, 'spot', '0' ) );
			if ( $spot && 'publish' === $spot->post_status ) {
				$detail .= '<p class="pos-ws__actions"><a class="pos-ws__button" href="' . esc_url( (string) get_permalink( $spot ) ) . '">' . esc_html__( 'Open the Spot\'s public page', 'packout-core' ) . '</a></p>';
			} else {
				$detail .= '<p class="pos-ws__muted">' . esc_html__( 'The Spot of this record is not on the public map, so it has no public page.', 'packout-core' ) . '</p>';
			}
		} elseif ( $cleanup && Impact::can_verify( $record->ID, $user ) ) {
			$hidden  = array( 'item' => (string) $record->ID );
			$detail .= '<div class="pos-ws__group pos-ws__pane-actions">' . ( 'verified' === $row['verification']
				/* translators: %s: Spot title. */
				? Workspace::act( 'impact_unverify', $id, __( 'Return to unverified', 'packout-core' ), $hidden, '', '', sprintf( __( 'Return the record of %s to unverified', 'packout-core' ), $row['spot_title'] ) )
				/* translators: %s: Spot title. */
				: Workspace::act( 'impact_verify', $id, __( 'Verify', 'packout-core' ), $hidden, 'pos-ws__button--primary', '', sprintf( __( 'Verify the record of %s', 'packout-core' ), $row['spot_title'] ) ) ) . '</div>';
		}
		Workspace_Lists::pane( $pane, $row['spot_title'], $detail );
		return $pane;
	}

	/**
	 * The team's profile, how it takes members, its listing, and its lead.
	 *
	 * @param \WP_Post $team Team.
	 * @param int      $user User ID.
	 */
	private static function settings( \WP_Post $team, int $user ): string {
		$id    = $team->ID;
		$do    = 'profile';
		$html  = Workspace::open( $do, $id ) . self::profile_fields( $do, $team );
		$html .= Workspace::choose( 'join', __( 'How people join', 'packout-core' ), Teams::join_policies(), Workspace::old( $do, 'join', Teams::join_policy( $id ) ), __( 'People can ask or join from the organization\'s public page, and by signing up for one of its public campaigns or missions, so this applies once the organization is listed. Invitations always work.', 'packout-core' ) );
		if ( Teams::active( $id ) ) {
			$html .= Workspace::tick( 'listed', __( 'List this organization publicly: its name, description, logo, cover image, region, website, number of people, impact, sponsors, public campaigns, and public missions. No member is named.', 'packout-core' ), Workspace::old_on( $do, 'listed', 'publish' === $team->post_status ) );
			$html .= Workspace::tick( 'show_leads', __( 'Name the organization\'s lead and co-leads on its public page', 'packout-core' ), Workspace::old_on( $do, 'show_leads', Teams::shows_leads( $id ) ) );
			$html .= '<p class="pos-ws__field">' . Workspace::tick( 'member_plans', __( 'Members can plan their own campaigns and missions', 'packout-core' ), Workspace::old_on( $do, 'member_plans', Teams::members_plan( $id ) ) ) . '<span class="pos-ws__help">' . esc_html__( 'Off unless you tick it. With it on, any member can plan a campaign or a mission for this organization, and change, run, and remove the ones they planned. You and the co-leads are emailed each time and can change anything. Members still cannot change the organization, its teams, its sponsors, or its resources, and cannot send messages.', 'packout-core' ) . '</span></p>';
		} else {
			$html .= '<p class="pos-ws__help">' . esc_html__( 'You can list the organization, and let members plan, once a coordinator has approved it.', 'packout-core' ) . '</p>';
		}
		$html .= Workspace::submit( __( 'Save the organization', 'packout-core' ) ) . '</form>';

		$members = array();
		foreach ( Teams::members( $id ) as $person_id ) {
			$members[ $person_id ] = self::name( $person_id );
		}
		if ( ! Teams::owns( $id, $user ) ) {
			$lead = '<p>' . esc_html__( 'The organization\'s lead hands it to another member. A co-lead cannot.', 'packout-core' ) . '</p>';
		} elseif ( ! $members ) {
			$lead = '<p>' . esc_html__( 'An organization is handed to one of its members. This organization has none yet.', 'packout-core' ) . '</p>';
		} else {
			$lead  = '<p>' . esc_html__( 'The member you choose becomes the lead. You stay in the organization as a member.', 'packout-core' ) . '</p>';
			$lead .= Workspace::open( 'hand_over', $id ) . Workspace::choose( 'to', __( 'New lead', 'packout-core' ), $members );
			$lead .= '<p class="pos-ws__actions"><button class="pos-ws__button pos-ws__button--danger"' . Workspace::confirm( __( 'Hand this organization to the person you chose?', 'packout-core' ), __( 'They become the lead and are the only one who can hand it back. You stay in the organization as a member.', 'packout-core' ), __( 'Hand over the organization', 'packout-core' ) ) . '>' . esc_html__( 'Hand over the organization', 'packout-core' ) . '</button></p></form>';
		}

		// The section whose form was refused.
		$first = array(
			'address'           => 'address',
			'logo'              => 'logo',
			'logo_remove'       => 'logo',
			'team_cover'        => 'cover',
			'team_cover_remove' => 'cover',
			'hand_over'         => 'lead',
			'partner_ask'       => 'partner',
			'partner_answer'    => 'partner',
			'partner_withdraw'  => 'partner',
			'partner_release'   => 'partner',
		);
		$part  = '' !== Workspace::problem() ? ( $first[ self::failed() ] ?? '' ) : '';
		return Workspace::sections(
			__( 'Settings', 'packout-core' ),
			array(
				array(
					'id'    => 'pos-settings-profile',
					'label' => __( 'Profile', 'packout-core' ),
					'html'  => $html,
				),
				array(
					'id'    => 'pos-settings-address',
					'label' => __( 'Organization address', 'packout-core' ),
					'html'  => self::address_form( $team ),
				),
				array(
					'id'    => 'pos-settings-logo',
					'label' => __( 'Logo', 'packout-core' ),
					'html'  => self::logo_form( $team ),
				),
				array(
					'id'    => 'pos-settings-cover',
					'label' => __( 'Cover image', 'packout-core' ),
					'html'  => self::cover_form( $team ),
				),
				array(
					'id'    => 'pos-settings-partner',
					'label' => __( 'Partner', 'packout-core' ),
					'html'  => Partner_Workspace::organization_partner( $team, $user ),
				),
				array(
					'id'    => 'pos-settings-lead',
					'label' => __( 'Hand the organization over', 'packout-core' ),
					'html'  => $lead,
				),
			),
			'side',
			'' !== $part ? 'pos-settings-' . $part : ''
		);
	}
}
