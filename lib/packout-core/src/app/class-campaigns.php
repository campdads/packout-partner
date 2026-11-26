<?php
/**
 * Campaign records.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A campaign is a stretch of a team's work with one goal.
 *
 * It names the sub-teams taking part and groups any number of missions. The
 * title is the post title and the description is the post content. Both are
 * public when the lead marks the campaign public. Progress is worked out from
 * the missions, and impact from their records.
 */
class Campaigns {

	/**
	 * Register the public page of a campaign.
	 */
	public static function register(): void {
		add_filter( 'the_content', array( self::class, 'page' ) );
	}

	/**
	 * A campaign post, or null.
	 *
	 * @param int $id Post ID.
	 */
	public static function get( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && Schema::CAMPAIGN === $post->post_type && in_array( $post->post_status, array( 'publish', 'private' ), true ) ? $post : null;
	}

	/**
	 * A text field of a campaign.
	 *
	 * @param int    $id  Campaign ID.
	 * @param string $key Field name without the prefix.
	 */
	public static function field( int $id, string $key ): string {
		$value = get_post_meta( $id, '_pos_' . $key, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * The team a campaign belongs to.
	 *
	 * @param int $id Campaign ID.
	 */
	public static function team( int $id ): int {
		return (int) get_post_meta( $id, '_pos_team', true );
	}

	/**
	 * Sub-teams taking part.
	 *
	 * @param int $id Campaign ID.
	 * @return array<int, int>
	 */
	public static function subteams( int $id ): array {
		return Subteams::known( self::team( $id ), Schema::stored_list( $id, 'subteams' ) );
	}

	/**
	 * Sponsors credited on a campaign.
	 *
	 * @param int $id Campaign ID.
	 * @return array<int, array{id: int, name: string, url: string, logo: int}>
	 */
	public static function sponsors( int $id ): array {
		return Sponsors::named( self::team( $id ), Schema::stored_list( $id, 'sponsors' ) );
	}

	/**
	 * A team's campaigns, newest first.
	 *
	 * @param int  $team_id     Team ID.
	 * @param bool $public_only Only the ones a visitor may read.
	 * @return array<int, \WP_Post>
	 */
	public static function of_team( int $team_id, bool $public_only = false ): array {
		$found = get_posts(
			array(
				'post_type'   => Schema::CAMPAIGN,
				'post_status' => $public_only ? 'publish' : array( 'publish', 'private' ),
				'numberposts' => 100,
				'orderby'     => 'date',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Campaigns of one team.
				'meta_key'    => '_pos_team',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Campaigns of one team.
				'meta_value'  => $team_id,
			)
		);
		return $public_only ? array_values( array_filter( $found, static fn( $post ) => Post_Access::can_read( $post, 0 ) ) ) : $found;
	}

	/**
	 * The missions of a campaign, by start day.
	 *
	 * @param int  $id          Campaign ID.
	 * @param bool $public_only Only the ones a visitor may read.
	 * @return array<int, \WP_Post>
	 */
	public static function missions( int $id, bool $public_only = false ): array {
		$found = get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => $public_only ? 'publish' : array( 'publish', 'private' ),
				'numberposts' => 200,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Missions of one campaign.
				'meta_key'    => '_pos_campaign',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Missions of one campaign.
				'meta_value'  => $id,
			)
		);
		if ( $public_only ) {
			$found = array_values( array_filter( $found, static fn( $post ) => Post_Access::can_read( $post, 0 ) ) );
		}
		usort( $found, static fn( $a, $b ) => strcmp( Missions::field( $a->ID, 'start', '9999' ), Missions::field( $b->ID, 'start', '9999' ) ) );
		return $found;
	}

	/**
	 * Whether a user leads a team's campaigns: its lead, a co-lead, or a coordinator.
	 *
	 * @param int $team_id Team ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_manage( int $team_id, int $user_id = -1 ): bool {
		// A copy of a record kept on a partner's site is changed there, by nobody here. So is the work of an organization that moved to one.
		if ( Remote::is( $team_id ) || Remote::moved( $team_id ) > 0 ) {
			return false;
		}
		return Missions::can_lead_team( $team_id, $user_id );
	}

	/**
	 * Whether a user may plan a new campaign for a team.
	 *
	 * The people who lead its campaigns, and a member when the team lets its members plan.
	 *
	 * @param int $team_id Team ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_plan( int $team_id, int $user_id = -1 ): bool {
		// A copy of a record kept on a partner's site is changed there, by nobody here. So is the work of an organization that moved to one.
		if ( Remote::is( $team_id ) || Remote::moved( $team_id ) > 0 ) {
			return false;
		}
		return Missions::can_plan( $team_id, $user_id );
	}

	/**
	 * Whether a user may change or remove one campaign.
	 *
	 * The people who lead the team's campaigns, and the member who planned
	 * this one for as long as the team lets its members plan.
	 *
	 * @param int $id      Campaign ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_change( int $id, int $user_id = -1 ): bool {
		// A copy of a record kept on a partner's site is changed there, by nobody here. So is a partner site's copy of a joint campaign: its host changes it.
		if ( Remote::is( $id ) || Joint::is_copy( $id ) ) {
			return false;
		}
		if ( $user_id < 0 ) {
			$user_id = get_current_user_id();
		}
		$post = self::get( $id );
		if ( ! $post ) {
			return false;
		}
		$team = self::team( $id );
		return self::can_manage( $team, $user_id ) || ( (int) $post->post_author === $user_id && Missions::plans_as_member( $team, $user_id ) );
	}

	/**
	 * Whether an organization's mission can be filed under a campaign.
	 *
	 * Its own campaign, a joint campaign it takes part in, or the campaign
	 * the mission is in already: an organization that left a joint campaign
	 * keeps its missions where they were.
	 *
	 * @param int $id      Campaign ID.
	 * @param int $team    Organization ID.
	 * @param int $already The campaign the mission is in now, or 0.
	 */
	public static function takes( int $id, int $team, int $already = 0 ): bool {
		if ( $id <= 0 || $team <= 0 || ! self::get( $id ) ) {
			return false;
		}
		return self::team( $id ) === $team || $already === $id || Joint::open_to( $id, $team );
	}

	/**
	 * Tell a team's leads that a member planned a campaign or a mission.
	 *
	 * @param int $team_id Team ID.
	 * @param int $user_id The member.
	 * @param int $post_id The campaign or the mission.
	 */
	public static function tell_leads( int $team_id, int $user_id, int $post_id ): void {
		$person = get_userdata( $user_id );
		$team   = Teams::get( $team_id );
		$post   = get_post( $post_id );
		if ( ! $person || ! $team || ! $post instanceof \WP_Post ) {
			return;
		}
		$mission = Schema::MISSION === $post->post_type;
		foreach ( array_filter( array_map( 'get_userdata', array_diff( Teams::leads( $team_id ), array( $user_id ) ) ) ) as $lead ) {
			Accounts::mail(
				$lead->user_email,
				$mission ? __( 'A member planned a mission', 'packout-core' ) : __( 'A member planned a campaign', 'packout-core' ),
				array(
					sprintf(
						$mission
							/* translators: 1: the person's name, 2: mission title, 3: organization name. */
							? __( '%1$s planned the mission “%2$s” for “%3$s”.', 'packout-core' )
							/* translators: 1: the person's name, 2: campaign title, 3: organization name. */
							: __( '%1$s planned the campaign “%2$s” for “%3$s”.', 'packout-core' ),
						$person->display_name,
						$post->post_title,
						$team->post_title
					),
					__( 'You can change anything in it, or turn planning by members off in the organization’s settings.', 'packout-core' ),
					Accounts::workspace_url(
						array(
							'team' => $team_id,
							'view' => $mission ? 'mission' : 'campaign',
							'item' => $post_id,
						)
					),
				)
			);
		}//end foreach
	}

	/**
	 * Upload, replace, or remove the cover image of a campaign or a mission.
	 *
	 * @param int                       $id      Campaign or mission ID.
	 * @param array<string, mixed>|null $file    One entry from the request files, or null to remove the cover.
	 * @param int                       $user_id The person changing it.
	 * @return int|\WP_Error Attachment ID, or 0 when the cover was removed.
	 */
	public static function set_cover( int $id, ?array $file, int $user_id ) {
		$mission = Missions::get( $id );
		$allowed = $mission ? Missions::can_assign( $id, $user_id ) : ( self::get( $id ) && self::can_change( $id, $user_id ) );
		if ( ! $allowed ) {
			return new \WP_Error( 'packout_forbidden', __( 'The person who plans it changes its cover image.', 'packout-core' ), array( 'status' => 403 ) );
		}
		return Page_Header::set_cover( $id, $file );
	}

	/**
	 * Create or change a campaign.
	 *
	 * @param int                  $id      Campaign ID, or 0 for a new one.
	 * @param array<string, mixed> $input   team, title, description, goal, start, end, start_time, end_time, timezone,
	 *                                      subteams, sponsors, public, program.
	 * @param int                  $user_id The person saving.
	 * @return int|\WP_Error Campaign ID.
	 */
	public static function save( int $id, array $input, int $user_id ) {
		$existing = $id > 0 ? self::get( $id ) : null;
		if ( $id > 0 && ! $existing ) {
			return new \WP_Error( 'packout_not_found', __( 'That campaign does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$team = $existing ? self::team( $existing->ID ) : (int) ( $input['team'] ?? 0 );
		if ( ! Teams::get( $team ) || ! ( $existing ? self::can_change( $existing->ID, $user_id ) : self::can_plan( $team, $user_id ) ) ) {
			return new \WP_Error(
				'packout_forbidden',
				Teams::get( $team ) && Teams::active( $team ) && Teams::includes( $team, $user_id )
					? __( 'This organization’s leads plan its campaigns. A lead can let members plan in the organization’s settings.', 'packout-core' )
					: __( 'The lead of an approved organization plans its campaigns.', 'packout-core' ),
				array( 'status' => 403 )
			);
		}
		$title = sanitize_text_field( (string) ( $input['title'] ?? ( $existing ? $existing->post_title : '' ) ) );
		if ( '' === $title ) {
			return new \WP_Error( 'packout_campaign_title', __( 'Give the campaign a title.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$window = Schedule::read( $input, $existing ? $existing->ID : 0, 'campaign' );
		if ( is_wp_error( $window ) ) {
			return $window;
		}
		// A campaign is filed under a program its organization takes part in. Checked before anything is written.
		$program = array_key_exists( 'program', $input ) ? max( 0, (int) $input['program'] ) : null;
		$refused = null !== $program ? Programs::check( $program, $team, $existing ? (int) get_post_meta( $existing->ID, \PackOut\Core\Program::FILED_META, true ) : 0 ) : null;
		if ( $refused ) {
			return $refused;
		}

		$public = array_key_exists( 'public', $input ) ? ! empty( $input['public'] ) : ( $existing ? 'publish' === $existing->post_status : false );
		$post   = array(
			'post_type'    => Schema::CAMPAIGN,
			'post_status'  => $public ? 'publish' : 'private',
			'post_title'   => wp_slash( $title ),
			'post_content' => wp_slash( sanitize_textarea_field( (string) ( $input['description'] ?? ( $existing ? $existing->post_content : '' ) ) ) ),
		);
		if ( $existing ) {
			$post['ID'] = $existing->ID;
			$saved      = wp_update_post( $post, true );
		} else {
			$post['post_author'] = $user_id;
			$saved               = wp_insert_post( $post, true );
		}
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$saved = (int) $saved;

		update_post_meta( $saved, '_pos_team', $team );
		Schedule::write( $saved, $window );
		if ( array_key_exists( 'goal', $input ) ) {
			$goal = sanitize_text_field( (string) $input['goal'] );
			if ( '' === $goal ) {
				delete_post_meta( $saved, '_pos_goal' );
			} else {
				update_post_meta( $saved, '_pos_goal', wp_slash( $goal ) );
			}
		}
		if ( array_key_exists( 'subteams', $input ) ) {
			Schema::write_list( $saved, 'subteams', Subteams::known( $team, (array) $input['subteams'] ) );
		}
		if ( array_key_exists( 'sponsors', $input ) ) {
			Schema::write_list( $saved, 'sponsors', wp_list_pluck( Sponsors::named( $team, (array) $input['sponsors'] ), 'id' ) );
		}
		if ( null !== $program ) {
			// Its missions follow it: they have this program, or none.
			$program > 0 ? update_post_meta( $saved, \PackOut\Core\Program::FILED_META, $program ) : delete_post_meta( $saved, \PackOut\Core\Program::FILED_META );
		}

		if ( ! $existing ) {
			/**
			 * A lead opened a campaign.
			 *
			 * @param int $saved Campaign ID.
			 */
			do_action( 'packout_campaign_opened', $saved );
			if ( ! self::can_manage( $team, $user_id ) ) {
				self::tell_leads( $team, $user_id, $saved );
			}
		}
		return $saved;
	}

	/**
	 * Remove a campaign. Its missions stay with the team, in no campaign.
	 *
	 * @param int $id      Campaign ID.
	 * @param int $user_id The person removing it.
	 * @return true|\WP_Error
	 */
	public static function remove( int $id, int $user_id ) {
		if ( ! self::get( $id ) || ! self::can_change( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The lead of the organization removes its campaigns.', 'packout-core' ), array( 'status' => 403 ) );
		}
		foreach ( self::missions( $id ) as $mission ) {
			delete_post_meta( $mission->ID, '_pos_campaign' );
		}
		wp_trash_post( $id );
		return true;
	}

	/**
	 * Where a campaign stands, counted from its missions.
	 *
	 * @param int  $id          Campaign ID.
	 * @param bool $public_only Count only the missions a visitor may read.
	 * @return array{missions: int, open: int, completed: int, spots: int, cleaned: int}
	 */
	public static function progress( int $id, bool $public_only = false ): array {
		$missions = self::missions( $id, $public_only );
		$spots    = array();
		$open     = 0;
		$done     = 0;
		foreach ( $missions as $mission ) {
			$status = Missions::status( $mission->ID );
			$open  += in_array( $status, Missions::OPEN, true ) ? 1 : 0;
			$done  += 'completed' === $status ? 1 : 0;
			if ( 'called_off' !== $status ) {
				$spots = array_merge( $spots, Missions::spots( $mission->ID ) );
			}
		}
		$spots = array_unique( $spots );
		return array(
			'missions'  => count( $missions ),
			'open'      => $open,
			'completed' => $done,
			'spots'     => count( $spots ),
			'cleaned'   => count( array_filter( $spots, static fn( $spot ) => 'cleaned' === Spots::field( (int) $spot, 'status', 'spotted' ) ) ),
		);
	}

	/**
	 * Progress in one line.
	 *
	 * @param array{missions: int, open: int, completed: int, spots: int, cleaned: int} $progress From progress().
	 */
	public static function progress_line( array $progress ): string {
		return implode(
			' · ',
			array(
				/* translators: %d: number of missions. */
				sprintf( _n( '%d mission', '%d missions', $progress['missions'], 'packout-core' ), $progress['missions'] ),
				/* translators: %d: number of missions. */
				sprintf( __( '%d completed', 'packout-core' ), $progress['completed'] ),
				/* translators: 1: Spots cleaned, 2: Spots in the campaign. */
				sprintf( __( '%1$d of %2$d Spots cleaned', 'packout-core' ), $progress['cleaned'], $progress['spots'] ),
			)
		);
	}

	/**
	 * Impact totals of a campaign: the sum of the records of its missions.
	 *
	 * @param int  $id      Campaign ID.
	 * @param bool $visible Only the records a coordinator counts publicly.
	 * @return array<string, array<string, int|float>>
	 */
	public static function totals( int $id, bool $visible = false ): array {
		// A joint campaign on the hub: what the hub counted and what each partner site reported, together.
		if ( App::is_hub() && Joint::is_joint( $id ) ) {
			return Joint::whole( $id, $visible );
		}
		if ( Remote::is( $id ) ) {
			return Remote::reported( $id );
		}
		return Impact::totals(
			array(
				'missions' => wp_list_pluck( self::missions( $id ), 'ID' ),
				'visible'  => $visible,
			)
		);
	}

	/**
	 * What the public may know about a campaign.
	 *
	 * @param \WP_Post $campaign Campaign.
	 * @return array<string, mixed>
	 */
	public static function public_payload( \WP_Post $campaign ): array {
		$id   = $campaign->ID;
		$team = Teams::get( self::team( $id ) );
		return array(
			'id'       => $id,
			'title'    => $campaign->post_title,
			'url'      => (string) get_permalink( $campaign ),
			'goal'     => self::field( $id, 'goal' ),
			'window'   => Schedule::label( $id ),
			'team'     => $team && 'publish' === $team->post_status ? $team->post_title : '',
			'team_url' => $team && 'publish' === $team->post_status ? (string) get_permalink( $team ) : '',
			'subteams' => Subteams::names( self::team( $id ), self::subteams( $id ) ),
			'progress' => self::progress( $id, true ),
			'sponsors' => self::sponsors( $id ),
			'cover'    => Page_Header::cover( $id ),
		);
	}

	/**
	 * The public page of a public campaign.
	 *
	 * @param string $content Post content.
	 */
	public static function page( $content ) {
		if ( ! is_singular( Schema::CAMPAIGN ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return '';
		}

		wp_enqueue_style( 'packout-workspace' );
		wp_enqueue_script( 'packout-workspace' );
		wp_enqueue_style( 'packout-blocks' );
		$campaign = self::public_payload( $post );
		$facts    = array();
		if ( '' !== $campaign['team'] ) {
			$facts[] = '<a href="' . esc_url( $campaign['team_url'] ) . '">' . esc_html( $campaign['team'] ) . '</a>';
		}
		if ( '' !== $campaign['window'] ) {
			$facts[] = esc_html( $campaign['window'] );
		}
		$facts[] = Remote::fact( (int) $campaign['id'] );

		$html  = '<div class="pos-campaign-page pos-place-page pos-place pos-event">';
		$html .= Page_Header::render(
			array(
				'cover'       => $campaign['cover'],
				'kind'        => __( 'Campaign', 'packout-core' ),
				'title'       => $campaign['title'],
				'description' => $post->post_content,
				'facts'       => $facts,
				'sponsors'    => $campaign['sponsors'],
			)
		);
		$html .= '<div class="pos-place__body">';

		$html .= '<section class="pos-place__section"><h2>' . esc_html__( 'About this campaign', 'packout-core' ) . '</h2><dl class="pos-event__facts">';
		if ( '' !== $campaign['goal'] ) {
			$html .= '<dt>' . esc_html__( 'Goal', 'packout-core' ) . '</dt><dd>' . esc_html( $campaign['goal'] ) . '</dd>';
		}
		if ( '' !== $campaign['team'] ) {
			$html .= '<dt>' . esc_html__( 'Organization', 'packout-core' ) . '</dt><dd><a href="' . esc_url( $campaign['team_url'] ) . '">' . esc_html( $campaign['team'] ) . '</a></dd>';
		}
		if ( '' !== $campaign['window'] ) {
			$html .= '<dt>' . esc_html__( 'When', 'packout-core' ) . '</dt><dd>' . esc_html( $campaign['window'] ) . '</dd>';
		}
		if ( $campaign['subteams'] ) {
			$html .= '<dt>' . esc_html__( 'Taking part', 'packout-core' ) . '</dt><dd>' . esc_html( implode( ', ', $campaign['subteams'] ) ) . '</dd>';
		}
		$html .= '<dt>' . esc_html__( 'Progress', 'packout-core' ) . '</dt><dd>' . esc_html( self::progress_line( $campaign['progress'] ) ) . '</dd></dl></section>';

		// A person signs up with one organization. In a joint campaign each organization takes its own.
		$joint = Joint::is_joint( $post->ID ) || Joint::is_copy( $post->ID );
		$html .= $joint ? Joint::section( $post->ID ) : Signups::section( $post->ID );

		$missions = self::missions( $post->ID, true );
		$html    .= '<section class="pos-place__section"><h2>' . esc_html( Joint::is_copy( $post->ID ) ? __( 'Missions here', 'packout-core' ) : __( 'Missions', 'packout-core' ) ) . '</h2>';
		if ( ! $missions ) {
			$html .= '<p>' . esc_html__( 'No mission of this campaign has been shared yet.', 'packout-core' ) . '</p>';
		} else {
			$html .= '<ul class="pos-team-page__missions">';
			foreach ( $missions as $mission ) {
				$row   = Missions::public_payload( $mission );
				$html .= '<li><a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['title'] ) . '</a>';
				$html .= '<span class="pos-team-page__facts">' . esc_html( implode( ' · ', array_filter( array( $row['status_label'], $row['window'], $row['spots_label'] ) ) ) ) . '</span></li>';
			}
			$html .= '</ul>';
		}
		$html .= '</section>';

		$totals = self::totals( $post->ID, true );
		if ( (int) $totals['records']['cleanups'] > 0 ) {
			$html .= '<section class="pos-place__section"><h2>' . esc_html( Joint::is_copy( $post->ID ) ? __( 'Recorded here so far', 'packout-core' ) : __( 'Recorded so far', 'packout-core' ) ) . '</h2><p>' . esc_html( self::totals_line( $totals ) ) . '</p>';
			if ( App::is_hub() && $joint ) {
				// The whole is two kinds of figure. The page says how much of it is each.
				$lines = Joint::lines( $post->ID );
				$sites = Joint::sum( $lines, 'partner' );
				if ( $sites['lines'] > 0 ) {
					$here  = Joint::sum( $lines, 'organization' );
					$html .= '<p class="pos-team-page__facts">' . esc_html__( 'These figures add what packout.spot counted from its own records to what partner sites reported from theirs.', 'packout-core' ) . '</p><dl class="pos-event__facts">';
					$html .= '<dt>' . esc_html__( 'Counted on packout.spot', 'packout-core' ) . '</dt><dd>' . esc_html( (int) $here['records']['cleanups'] > 0 ? self::totals_line( $here ) : __( 'Nothing yet.', 'packout-core' ) ) . '</dd>';
					$html .= '<dt>' . esc_html__( 'Reported by partner sites', 'packout-core' ) . '</dt><dd>' . esc_html( (int) $sites['records']['cleanups'] > 0 ? self::totals_line( $sites ) : __( 'Nothing yet.', 'packout-core' ) ) . '</dd></dl>';
				}
			}
			$html .= '</section>';
		}
		return $html . '</div></div>';
	}

	/**
	 * Impact totals in one sentence, with measured and self-reported figures apart.
	 *
	 * @param array<string, array<string, int|float>> $totals From Impact::totals().
	 */
	public static function totals_line( array $totals ): string {
		$parts = array(
			/* translators: %s: number of Spots. */
			sprintf( __( '%s Spots with a record', 'packout-core' ), number_format_i18n( (int) $totals['outputs']['spots'] ) ),
			/* translators: 1: verified hours, 2: unverified hours. */
			sprintf( __( '%1$s verified hours and %2$s unverified', 'packout-core' ), Impact::number( (float) $totals['effort']['hours_verified'] ), Impact::number( (float) $totals['effort']['hours_unverified'] ) ),
			/* translators: 1: measured bags, 2: self-reported bags. */
			sprintf( __( '%1$s bags measured and %2$s self-reported', 'packout-core' ), number_format_i18n( (int) $totals['outputs']['bags_measured'] ), number_format_i18n( (int) $totals['outputs']['bags_self_reported'] ) ),
			/* translators: 1: measured pounds, 2: self-reported pounds. */
			sprintf( __( '%1$s pounds measured and %2$s self-reported', 'packout-core' ), Impact::number( (float) $totals['outputs']['pounds_measured'] ), Impact::number( (float) $totals['outputs']['pounds_self_reported'] ) ),
		);
		return implode( ' · ', $parts ) . '.';
	}
}
