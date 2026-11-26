<?php
/**
 * Mission records.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

use PackOut\Core\Repeat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A mission is organized work by one team on one or more Spots.
 *
 * The title is the post title. Everything else is meta, so nothing a crew was
 * told in confidence sits in post content. Post status is the visibility
 * switch: publish when the lead marks the mission public, private otherwise.
 *
 * Spot stages follow the mission:
 * - Linking a Spot that is spotted makes it planned. The mission remembers
 *   that it did so.
 * - Starting the mission makes its planned Spots in progress. The first
 *   checklist tick or Spot outcome from the field starts it.
 * - An outcome is applied to the Spot when it arrives: cleaned, still open, or
 *   left because it needs authorization. Completing the mission does not mark
 *   anything cleaned by itself.
 * - Calling the mission off, or unlinking a Spot, returns a Spot this mission
 *   made planned to spotted, unless another open mission still includes it.
 */
class Missions {

	/**
	 * Statuses in which work can still happen.
	 */
	public const OPEN = array( 'planned', 'in_progress' );

	/**
	 * Most lines a checklist can hold.
	 */
	private const MAX_LINES = 50;

	/**
	 * Spot IDs on each person's open missions, worked out once per request.
	 *
	 * @var array<int, array<int, int>>
	 */
	private static array $crew = array();

	/**
	 * Register the public page of a mission.
	 */
	public static function register(): void {
		add_filter( 'the_content', array( self::class, 'page' ) );
	}

	/**
	 * Mission statuses and their labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return Vocabularies::labels( Vocabularies::MISSION_STATUS );
	}

	/**
	 * What a crew can record about one Spot, and their labels.
	 *
	 * `reopen` is the lead's choice to send an open Spot back to spotted.
	 *
	 * @return array<string, string>
	 */
	public static function outcomes(): array {
		return Vocabularies::labels( Vocabularies::OUTCOME );
	}

	/**
	 * A mission post, or null.
	 *
	 * @param int $id Post ID.
	 */
	public static function get( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && Schema::MISSION === $post->post_type && in_array( $post->post_status, array( 'publish', 'private' ), true ) ? $post : null;
	}

	/**
	 * One stored field.
	 *
	 * @param int    $id      Mission ID.
	 * @param string $key     Field name without the prefix.
	 * @param string $fallback Value when nothing is stored.
	 */
	public static function field( int $id, string $key, string $fallback = '' ): string {
		$value = get_post_meta( $id, '_pos_' . $key, true );
		return is_scalar( $value ) && '' !== (string) $value ? (string) $value : $fallback;
	}

	/**
	 * Mission status.
	 *
	 * @param int $id Mission ID.
	 */
	public static function status( int $id ): string {
		return self::field( $id, 'status', 'planned' );
	}

	/**
	 * Whether work can still happen on a mission.
	 *
	 * @param int $id Mission ID.
	 */
	public static function is_open( int $id ): bool {
		return in_array( self::status( $id ), self::OPEN, true );
	}

	/**
	 * The team that owns a mission.
	 *
	 * @param int $id Mission ID.
	 */
	public static function team( int $id ): int {
		return (int) self::field( $id, 'team', '0' );
	}

	/**
	 * The day each linked Spot joined the mission, by Spot ID.
	 *
	 * @param int $id Mission ID.
	 * @return array<int, string> Y-m-d in the site's time zone.
	 */
	public static function joined( int $id ): array {
		$post = self::get( $id );
		if ( ! $post ) {
			return array();
		}
		$days = array();
		foreach ( (array) get_post_meta( $id, '_pos_spot_on', false ) as $row ) {
			$parts = explode( ':', (string) $row, 2 );
			if ( 2 === count( $parts ) && '' !== Impact::date( $parts[1] ) ) {
				$days[ (int) $parts[0] ] = $parts[1];
			}
		}
		$joined = array();
		foreach ( self::spots( $id ) as $spot ) {
			// A Spot added before the day was kept joined when the mission was planned, as far as anyone can say.
			$joined[ $spot ] = $days[ $spot ] ?? substr( (string) $post->post_date, 0, 10 );
		}
		return $joined;
	}

	/**
	 * Linked Spot IDs, in the order they were linked.
	 *
	 * @param int $id Mission ID.
	 * @return array<int, int>
	 */
	public static function spots( int $id ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', (array) get_post_meta( $id, '_pos_spot', false ) ) ) ) );
	}

	/**
	 * Revision counter. It rises on every change to the mission.
	 *
	 * @param int $id Mission ID.
	 */
	public static function revision( int $id ): int {
		return (int) self::field( $id, 'revision', '0' );
	}

	/**
	 * Checklist lines.
	 *
	 * @param int $id Mission ID.
	 * @return array<int, array{id: string, text: string, done: bool, rev: int, by: int}>
	 */
	public static function checklist( int $id ): array {
		$lines = array();
		foreach ( self::stored( $id, 'checklist' ) as $line ) {
			if ( is_array( $line ) && isset( $line['id'], $line['text'] ) ) {
				$lines[] = array(
					'id'   => (string) $line['id'],
					'text' => (string) $line['text'],
					'done' => ! empty( $line['done'] ),
					'rev'  => (int) ( $line['rev'] ?? 0 ),
					'by'   => (int) ( $line['by'] ?? 0 ),
				);
			}
		}
		return $lines;
	}

	/**
	 * What was recorded about each Spot.
	 *
	 * @param int $id Mission ID.
	 * @return array<int, array{outcome: string, rev: int, by: int}>
	 */
	public static function recorded( int $id ): array {
		$outcomes = array();
		foreach ( self::stored( $id, 'outcomes' ) as $spot => $row ) {
			if ( is_array( $row ) && isset( self::outcomes()[ (string) ( $row['outcome'] ?? '' ) ] ) ) {
				$outcomes[ (int) $spot ] = array(
					'outcome' => (string) $row['outcome'],
					'rev'     => (int) ( $row['rev'] ?? 0 ),
					'by'      => (int) ( $row['by'] ?? 0 ),
				);
			}
		}
		return $outcomes;
	}

	/**
	 * Changes from a phone that disagreed with a newer change, kept for the lead.
	 *
	 * @param int $id Mission ID.
	 * @return array<int, array{key: string, value: string, user: int, at: string}>
	 */
	public static function proposals( int $id ): array {
		$proposals = array();
		foreach ( self::stored( $id, 'proposals' ) as $row ) {
			if ( is_array( $row ) && isset( $row['key'], $row['value'], $row['user'] ) ) {
				$proposals[] = array(
					'key'   => (string) $row['key'],
					'value' => (string) $row['value'],
					'user'  => (int) $row['user'],
					'at'    => (string) ( $row['at'] ?? '' ),
				);
			}
		}
		return $proposals;
	}

	/**
	 * The campaign a mission belongs to, or 0.
	 *
	 * @param int $id Mission ID.
	 */
	public static function campaign( int $id ): int {
		return (int) get_post_meta( $id, '_pos_campaign', true );
	}

	/**
	 * Sub-teams a mission is assigned to. An empty list means the whole team.
	 *
	 * @param int $id Mission ID.
	 * @return array<int, int>
	 */
	public static function subteams( int $id ): array {
		return Subteams::known( self::team( $id ), self::stored( $id, 'subteams' ) );
	}

	/**
	 * Sponsors credited on a mission.
	 *
	 * @param int $id Mission ID.
	 * @return array<int, array{id: int, name: string, url: string, logo: int}>
	 */
	public static function sponsors( int $id ): array {
		return Sponsors::named( self::team( $id ), self::stored( $id, 'sponsors' ) );
	}

	/**
	 * Whether a user may create missions at all.
	 *
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_lead( int $user_id = -1 ): bool {
		return Capabilities::can( Capabilities::LEAD, $user_id );
	}

	/**
	 * Whether a user may lead missions for a team.
	 *
	 * A coordinator can for every team. A lead or co-lead can for their team.
	 * Nobody can for a team that is waiting for approval.
	 *
	 * @param int $team_id Team ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_lead_team( int $team_id, int $user_id = -1 ): bool {
		// A copy of a record kept on a partner's site is changed there, by nobody here. So is the work of an organization that moved to one.
		if ( Remote::is( $team_id ) || Remote::moved( $team_id ) > 0 ) {
			return false;
		}
		if ( $user_id < 0 ) {
			$user_id = get_current_user_id();
		}
		if ( ! self::can_lead( $user_id ) || ! Teams::active( $team_id ) ) {
			return false;
		}
		return Capabilities::can( Capabilities::TEAM, $user_id ) || Teams::leads_work( $team_id, $user_id );
	}

	/**
	 * Whether a member plans for a team because the team lets its members plan.
	 *
	 * Off until the team's lead turns it on in Settings. It needs an approved
	 * team and gives no role: the member plans, and changes what they planned.
	 *
	 * @param int $team_id Team ID.
	 * @param int $user_id User ID.
	 */
	public static function plans_as_member( int $team_id, int $user_id ): bool {
		return $user_id > 0
			&& Teams::active( $team_id )
			&& Teams::members_plan( $team_id )
			&& Capabilities::can( Capabilities::REPORT, $user_id )
			&& Teams::includes( $team_id, $user_id );
	}

	/**
	 * Whether a user may plan a new mission or a new campaign for a team.
	 *
	 * The team's lead, its co-leads, and a coordinator can. A member can when
	 * the team lets its members plan.
	 *
	 * @param int $team_id Team ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_plan( int $team_id, int $user_id = -1 ): bool {
		// A copy of a record kept on a partner's site is changed there, by nobody here. So is the work of an organization that moved to one.
		if ( Remote::is( $team_id ) || Remote::moved( $team_id ) > 0 ) {
			return false;
		}
		if ( $user_id < 0 ) {
			$user_id = get_current_user_id();
		}
		return self::can_lead_team( $team_id, $user_id ) || self::plans_as_member( $team_id, $user_id );
	}

	/**
	 * Whether a member planned this mission and still may change it.
	 *
	 * @param int $id      Mission ID.
	 * @param int $user_id User ID.
	 */
	public static function planned_by( int $id, int $user_id ): bool {
		$post = self::get( $id );
		return $post && $user_id > 0 && (int) $post->post_author === $user_id && self::plans_as_member( self::team( $id ), $user_id );
	}

	/**
	 * Whether a user may edit, start, complete, or call off a mission.
	 *
	 * The team's lead, its co-leads, and a coordinator can. So can the lead
	 * and co-leads of a sub-team the mission is assigned to. They are named by
	 * a team's lead, not by a coordinator, so this is all it gives them: no
	 * role comes with it. A member who planned the mission can for as long as
	 * the team lets its members plan.
	 *
	 * @param int $id      Mission ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_manage( int $id, int $user_id = -1 ): bool {
		// A copy of a record kept on a partner's site is changed there, by nobody here.
		if ( Remote::is( $id ) ) {
			return false;
		}
		if ( $user_id < 0 ) {
			$user_id = get_current_user_id();
		}
		$team = self::team( $id );
		if ( self::can_lead_team( $team, $user_id ) || self::planned_by( $id, $user_id ) ) {
			return true;
		}
		return Teams::active( $team )
			&& Capabilities::can( Capabilities::REPORT, $user_id )
			&& Teams::includes( $team, $user_id )
			&& array() !== array_intersect( self::subteams( $id ), Subteams::led_by( $team, $user_id ) );
	}

	/**
	 * Whether a user decides a mission's campaign, sub-teams, sponsors, cover, and whether it is public.
	 *
	 * The team's lead, a co-lead, a coordinator, and the member who planned it.
	 *
	 * @param int $id      Mission ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_assign( int $id, int $user_id = -1 ): bool {
		// A copy of a record kept on a partner's site is changed there, by nobody here.
		if ( Remote::is( $id ) ) {
			return false;
		}
		if ( $user_id < 0 ) {
			$user_id = get_current_user_id();
		}
		return self::can_lead_team( self::team( $id ), $user_id ) || self::planned_by( $id, $user_id );
	}

	/**
	 * Whether a user may see the crew fields and record progress.
	 *
	 * @param int $id      Mission ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_work( int $id, int $user_id = -1 ): bool {
		// A copy of a record kept on a partner's site is changed there, by nobody here.
		if ( Remote::is( $id ) ) {
			return false;
		}
		if ( $user_id < 0 ) {
			$user_id = get_current_user_id();
		}
		if ( self::can_manage( $id, $user_id ) ) {
			return true;
		}
		$team = self::team( $id );
		if ( ! Capabilities::can( Capabilities::REPORT, $user_id ) || ! Teams::includes( $team, $user_id ) ) {
			return false;
		}
		// A mission assigned to sub-teams goes to their people. One with none goes to the whole team.
		$assigned = self::subteams( $id );
		return ! $assigned || Teams::leads_team( $team, $user_id ) || array_intersect( $assigned, Subteams::of_user( $team, $user_id ) );
	}

	/**
	 * Teams a user may create missions for.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, \WP_Post>
	 */
	public static function teams_for( int $user_id ): array {
		return array_values( array_filter( Teams::all(), static fn( $team ) => self::can_lead_team( $team->ID, $user_id ) ) );
	}

	/**
	 * Create or change a mission.
	 *
	 * @param int                  $id    Mission ID, or 0 for a new mission.
	 * @param array<string, mixed> $input title, objective, description, team, spots, start, end, start_time, end_time,
	 *                                    timezone, meeting_note, meeting_share, meeting_lat, meeting_lng, gear, public,
	 *                                    lines (id => text), new_lines, campaign, subteams, sponsors, program.
	 * @param int                  $user  The person saving.
	 * @return int|\WP_Error Mission ID.
	 */
	public static function save( int $id, array $input, int $user ) {
		$existing = $id > 0 ? self::get( $id ) : null;
		if ( $id > 0 && ! $existing ) {
			return new \WP_Error( 'packout_not_found', __( 'That mission does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}

		$title = sanitize_text_field( (string) ( $input['title'] ?? ( $existing ? $existing->post_title : '' ) ) );
		if ( '' === $title ) {
			return new \WP_Error( 'packout_mission_title', __( 'Give the mission a title.', 'packout-core' ), array( 'status' => 400 ) );
		}

		$team = array_key_exists( 'team', $input ) ? (int) $input['team'] : ( $existing ? self::team( $existing->ID ) : 0 );
		if ( ! Teams::get( $team ) ) {
			return new \WP_Error( 'packout_mission_team', __( 'Choose the organization this mission is for.', 'packout-core' ), array( 'status' => 400 ) );
		}
		// The team's lead plans a mission and decides who it is assigned to. The lead of an assigned sub-team can edit the rest.
		// A member plans one, and decides the same for it, when the team lets its members plan.
		$leads = self::can_lead_team( $team, $user );
		$owner = $leads || ( $existing ? self::planned_by( $existing->ID, $user ) && self::team( $existing->ID ) === $team : self::plans_as_member( $team, $user ) );
		if ( $existing ? ! self::can_manage( $existing->ID, $user ) || ( ! $leads && self::team( $existing->ID ) !== $team ) : ! $owner ) {
			return new \WP_Error(
				'packout_forbidden',
				Teams::includes( $team, $user ) && ! Teams::leads_team( $team, $user )
					? __( 'This organization’s leads plan its missions. A lead can let members plan in the organization’s settings.', 'packout-core' )
					: __( 'You can plan missions for the organizations you lead.', 'packout-core' ),
				array( 'status' => 403 )
			);
		}

		$open  = ! $existing || self::is_open( $existing->ID );
		$spots = $existing ? self::spots( $existing->ID ) : array();
		if ( $open && array_key_exists( 'spots', $input ) ) {
			$had   = $spots;
			$spots = array();
			foreach ( array_unique( array_map( 'intval', (array) $input['spots'] ) ) as $spot_id ) {
				$spot = Spots::get( $spot_id );
				if ( $spot && in_array( $spot->post_status, array( 'publish', 'private' ), true ) ) {
					if ( ! in_array( $spot_id, $had, true ) ) {
						/**
						 * Whether a mission may take a Spot it does not have yet.
						 *
						 * @param true|\WP_Error $takes   True, or why not.
						 * @param int            $spot_id The Spot.
						 * @param int            $id      The mission, or 0 for a new one.
						 */
						$takes = apply_filters( 'packout_mission_takes_spot', true, $spot_id, $id );
						if ( is_wp_error( $takes ) ) {
							return $takes;
						}
					}
					$spots[] = $spot_id;
				}
			}
		}//end if
		// A mission made by repeating another starts with no Spots, and can be changed before it has any.
		if ( ! $spots && ! ( $existing && ! self::spots( $existing->ID ) ) ) {
			return new \WP_Error( 'packout_mission_spots', __( 'Choose at least one Spot. A Spot can join a mission once a coordinator has reviewed it.', 'packout-core' ), array( 'status' => 400 ) );
		}

		$window = Schedule::read( $input, $existing ? $existing->ID : 0, 'mission' );
		if ( is_wp_error( $window ) ) {
			return $window;
		}

		// The campaign the mission will be in decides its program. Its own Program field counts only outside a campaign.
		$in_campaign = $existing ? self::campaign( $existing->ID ) : 0;
		if ( $owner && array_key_exists( 'campaign', $input ) ) {
			$asked       = Campaigns::get( (int) $input['campaign'] );
			$in_campaign = $asked && Campaigns::takes( $asked->ID, $team, $in_campaign ) ? $asked->ID : 0;
		}
		$program = $owner && 0 === $in_campaign && array_key_exists( 'program', $input ) ? max( 0, (int) $input['program'] ) : null;
		$refused = null !== $program ? Programs::check( $program, $team, $existing ? (int) get_post_meta( $existing->ID, \PackOut\Core\Program::FILED_META, true ) : 0 ) : null;
		if ( $refused ) {
			return $refused;
		}

		$public = array_key_exists( 'public', $input ) ? ! empty( $input['public'] ) : ( $existing ? 'publish' === $existing->post_status : false );
		$post   = array(
			'post_type'   => Schema::MISSION,
			'post_status' => $public ? 'publish' : 'private',
			'post_title'  => wp_slash( $title ),
		);
		if ( $existing ) {
			$post['ID'] = $existing->ID;
			$saved      = wp_update_post( $post, true );
		} else {
			$post['post_author'] = $user;
			$saved               = wp_insert_post( $post, true );
		}
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$saved = (int) $saved;

		update_post_meta( $saved, '_pos_team', $team );
		if ( $owner ) {
			if ( array_key_exists( 'campaign', $input ) ) {
				self::set( $saved, 'campaign', $in_campaign > 0 ? (string) $in_campaign : '' );
			}
			if ( $in_campaign > 0 ) {
				// A mission in a campaign has that campaign's program. Its own field is not kept.
				delete_post_meta( $saved, \PackOut\Core\Program::FILED_META );
			} elseif ( null !== $program ) {
				$program > 0 ? update_post_meta( $saved, \PackOut\Core\Program::FILED_META, $program ) : delete_post_meta( $saved, \PackOut\Core\Program::FILED_META );
			}
			if ( array_key_exists( 'subteams', $input ) ) {
				self::write( $saved, 'subteams', Subteams::known( $team, (array) $input['subteams'] ) );
			}
			if ( array_key_exists( 'sponsors', $input ) ) {
				self::write( $saved, 'sponsors', wp_list_pluck( Sponsors::named( $team, (array) $input['sponsors'] ), 'id' ) );
			}
		}
		foreach ( array( 'objective', 'description', 'meeting_note', 'gear' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				self::set( $saved, $key, sanitize_textarea_field( (string) $input[ $key ] ) );
			}
		}
		Schedule::write( $saved, $window );
		if ( array_key_exists( 'meeting_share', $input ) ) {
			self::set( $saved, 'meeting_share', empty( $input['meeting_share'] ) ? '' : '1' );
		}
		if ( array_key_exists( 'meeting_lat', $input ) || array_key_exists( 'meeting_lng', $input ) ) {
			$point = Geo::point( $input['meeting_lat'] ?? null, $input['meeting_lng'] ?? null );
			self::set( $saved, 'meeting_lat', $point ? sprintf( '%.6F', $point[0] ) : '' );
			self::set( $saved, 'meeting_lng', $point ? sprintf( '%.6F', $point[1] ) : '' );
		}

		if ( ! $existing ) {
			update_post_meta( $saved, '_pos_status', 'planned' );
			Vocabularies::assign( $saved, Vocabularies::MISSION_STATUS, 'planned' );
		}

		$next = self::revision( $saved ) + 1;
		if ( $open ) {
			$before = $existing ? self::spots( $saved ) : array();
			foreach ( array_diff( $before, $spots ) as $spot_id ) {
				self::unlink( $saved, $spot_id );
			}
			foreach ( array_diff( $spots, $before ) as $spot_id ) {
				self::link( $saved, $spot_id );
			}
			if ( array_key_exists( 'lines', $input ) || array_key_exists( 'new_lines', $input ) ) {
				self::write( $saved, 'checklist', self::edited_lines( self::checklist( $saved ), $input, $next ) );
			}
		}
		update_post_meta( $saved, '_pos_revision', $next );
		self::flush();

		if ( ! $existing ) {
			/**
			 * A mission was opened.
			 *
			 * @param int $saved Mission ID.
			 */
			do_action( 'packout_mission_opened', $saved );
			if ( ! $leads ) {
				Campaigns::tell_leads( $team, $user, $saved );
			}
		}

		return $saved;
	}

	/**
	 * Repeat a mission: make copies of it at an interval.
	 *
	 * Each copy is an ordinary planned mission with its own days, the same
	 * title, description, objective, time of day, and visibility, and no
	 * Spots, since a Spot belongs to the visit that cleans it. Nothing links
	 * the copies afterward: changing one changes only that one.
	 *
	 * @param int                  $id    The mission to repeat.
	 * @param array<string, mixed> $input every (1 to 12 weeks, or `month`), copies (1 to 12), and carry: any of
	 *                                    checklist, gear, meeting, teams, campaign, program, resources.
	 * @param int                  $user  The person repeating it.
	 * @return array<int, int>|\WP_Error The new missions, soonest first.
	 */
	public static function repeat( int $id, array $input, int $user ) {
		$mission = self::get( $id );
		if ( ! $mission ) {
			return new \WP_Error( 'packout_not_found', __( 'That mission does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_manage( $id, $user ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The people who may change a mission may repeat it.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$team = self::team( $id );
		if ( ! Teams::active( $team ) ) {
			return new \WP_Error( 'packout_team_pending', __( 'Only an approved organization can plan a mission.', 'packout-core' ), array( 'status' => 409 ) );
		}

		$every   = Repeat::every( is_scalar( $input['every'] ?? null ) ? (string) $input['every'] : '' );
		$copies  = Repeat::copies( is_scalar( $input['copies'] ?? null ) ? (string) $input['copies'] : '' );
		$carry   = Repeat::carry( $input['carry'] ?? array() );
		$refusal = Repeat::refusal( self::field( $id, 'start' ), $every, $copies );
		if ( '' !== $refusal ) {
			$messages = array(
				'undated' => __( 'Give this mission a start date first. Each copy is dated from it.', 'packout-core' ),
				/* translators: %d: number of weeks. */
				'every'   => sprintf( __( 'Repeat every 1 to %d weeks, or on the same day each month.', 'packout-core' ), Repeat::MAX_WEEKS ),
				/* translators: %d: number of copies. */
				'copies'  => sprintf( __( 'Make from 1 to %d copies at a time.', 'packout-core' ), Repeat::MAX_COPIES ),
			);
			return new \WP_Error( 'packout_mission_repeat', $messages[ $refusal ], array( 'status' => 400 ) );
		}

		// A copy keeps the campaign only when asked. Its own program is kept only outside a campaign, as on any mission.
		$campaign = in_array( 'campaign', $carry, true ) && self::campaign( $id ) > 0 && Campaigns::get( self::campaign( $id ) ) ? self::campaign( $id ) : 0;
		$program  = 0 === $campaign && in_array( 'program', $carry, true ) ? Programs::of_mission( $id ) : 0;
		if ( $program > 0 && Programs::check( $program, $team, (int) get_post_meta( $id, \PackOut\Core\Program::FILED_META, true ) ) ) {
			// The program no longer takes this organization's missions. The copies are made without it.
			$program = 0;
		}

		$made = array();
		foreach ( Repeat::dates( self::field( $id, 'start' ), self::field( $id, 'end' ), $every, $copies ) as $window ) {
			$copy = wp_insert_post(
				array(
					'post_type'   => Schema::MISSION,
					'post_status' => 'publish' === $mission->post_status ? 'publish' : 'private',
					'post_title'  => wp_slash( $mission->post_title ),
					'post_author' => $user,
				),
				true
			);
			if ( is_wp_error( $copy ) ) {
				return $copy;
			}
			$copy = (int) $copy;
			update_post_meta( $copy, '_pos_team', $team );
			update_post_meta( $copy, '_pos_status', 'planned' );
			Vocabularies::assign( $copy, Vocabularies::MISSION_STATUS, 'planned' );
			foreach ( array( 'objective', 'description' ) as $key ) {
				self::set( $copy, $key, self::field( $id, $key ) );
			}
			Schedule::write(
				$copy,
				array(
					'start'      => $window['start'],
					'end'        => $window['end'],
					'start_time' => self::field( $id, 'start_time' ),
					'end_time'   => self::field( $id, 'end_time' ),
					'timezone'   => self::field( $id, 'timezone' ),
				)
			);
			if ( in_array( 'checklist', $carry, true ) ) {
				// The same lines, none of them ticked.
				$lines = array();
				foreach ( self::checklist( $id ) as $line ) {
					$lines[] = array(
						'id'   => substr( md5( uniqid( $line['text'], true ) ), 0, 8 ),
						'text' => $line['text'],
						'done' => false,
						'rev'  => 1,
						'by'   => 0,
					);
				}
				self::write( $copy, 'checklist', $lines );
			}
			if ( in_array( 'gear', $carry, true ) ) {
				self::set( $copy, 'gear', self::field( $id, 'gear' ) );
			}
			if ( in_array( 'meeting', $carry, true ) ) {
				foreach ( array( 'meeting_note', 'meeting_share', 'meeting_lat', 'meeting_lng' ) as $key ) {
					self::set( $copy, $key, self::field( $id, $key ) );
				}
			}
			if ( in_array( 'teams', $carry, true ) ) {
				self::write( $copy, 'subteams', Subteams::known( $team, self::subteams( $id ) ) );
			}
			if ( $campaign > 0 ) {
				self::set( $copy, 'campaign', (string) $campaign );
			} elseif ( $program > 0 ) {
				update_post_meta( $copy, \PackOut\Core\Program::FILED_META, $program );
			}
			if ( in_array( 'resources', $carry, true ) ) {
				Resources::copy_to_mission( $team, $id, $copy );
			}
			update_post_meta( $copy, '_pos_revision', 1 );
			$made[] = $copy;

			/** This action is documented in src/class-missions.php */
			do_action( 'packout_mission_opened', $copy );
		}//end foreach
		self::flush();
		return $made;
	}

	/**
	 * Start, complete, or call off a mission.
	 *
	 * @param int    $id      Mission ID.
	 * @param string $status  in_progress, completed, or called_off.
	 * @param string $open_to Where Spots that are still open go on completion: in_progress or spotted.
	 * @return true|\WP_Error
	 */
	public static function set_status( int $id, string $status, string $open_to = 'in_progress' ) {
		if ( ! self::get( $id ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That mission does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! self::is_open( $id ) ) {
			return new \WP_Error( 'packout_mission_closed', __( 'This mission has already ended.', 'packout-core' ), array( 'status' => 409 ) );
		}

		$spots = self::spots( $id );
		self::flush();

		if ( 'in_progress' === $status ) {
			if ( 'planned' !== self::status( $id ) ) {
				return true;
			}
			if ( ! $spots ) {
				return new \WP_Error( 'packout_mission_spots', __( 'Add a Spot to this mission before starting it.', 'packout-core' ), array( 'status' => 409 ) );
			}
			foreach ( $spots as $spot_id ) {
				if ( 'planned' === Spots::field( $spot_id, 'status', 'spotted' ) ) {
					Spots::set_status( $spot_id, 'in_progress' );
				}
			}
			update_post_meta( $id, '_pos_status', 'in_progress' );
			Vocabularies::assign( $id, Vocabularies::MISSION_STATUS, 'in_progress' );
			self::touch( $id );
			return true;
		}

		if ( 'completed' === $status ) {
			$flagged = array_filter( $spots, static fn( $spot_id ) => Spots::blocked( (int) $spot_id ) );
			if ( $spots && count( $flagged ) === count( $spots ) ) {
				return new \WP_Error( 'packout_mission_hazard', __( 'Every Spot on this mission is flagged to be left in place. A coordinator has to clear a flag, or record a clearance for a Spot that needs authorization, before the mission can be completed. It can still be called off.', 'packout-core' ), array( 'status' => 409 ) );
			}

			$recorded = self::recorded( $id );
			$next     = self::revision( $id ) + 1;
			foreach ( $spots as $spot_id ) {
				$outcome = $recorded[ $spot_id ]['outcome'] ?? '';
				if ( '' === $outcome ) {
					$recorded[ $spot_id ] = array(
						'outcome' => 'open',
						'rev'     => $next,
						'by'      => get_current_user_id(),
					);
					$outcome              = 'open';
				}
				if ( 'open' !== $outcome || 'cleaned' === Spots::field( $spot_id, 'status', 'spotted' ) ) {
					continue;
				}
				// A Spot left open goes where the lead chose. Another open mission keeps it planned.
				if ( 'spotted' === $open_to ) {
					self::release( $id, $spot_id, true );
				} elseif ( 'planned' === Spots::field( $spot_id, 'status', 'spotted' ) ) {
					Spots::set_status( $spot_id, 'in_progress' );
				}
			}
			self::write( $id, 'outcomes', $recorded );
			self::write( $id, 'proposals', array() );
			update_post_meta( $id, '_pos_status', 'completed' );
			Vocabularies::assign( $id, Vocabularies::MISSION_STATUS, 'completed' );
			update_post_meta( $id, '_pos_completed_at', gmdate( 'c' ) );
			self::touch( $id );

			/**
			 * A lead completed a mission.
			 *
			 * @param int $id Mission ID.
			 */
			do_action( 'packout_mission_completed', $id );
			return true;
		}//end if

		if ( 'called_off' === $status ) {
			update_post_meta( $id, '_pos_status', 'called_off' );
			Vocabularies::assign( $id, Vocabularies::MISSION_STATUS, 'called_off' );
			foreach ( $spots as $spot_id ) {
				self::release( $id, $spot_id, false );
			}
			self::write( $id, 'proposals', array() );
			self::touch( $id );
			return true;
		}

		return new \WP_Error( 'packout_mission_status', __( 'A mission can be started, completed, or called off.', 'packout-core' ), array( 'status' => 400 ) );
	}

	/**
	 * Record checklist ticks and Spot outcomes from a crew member.
	 *
	 * The request carries the mission revision the device last saw. A change is
	 * applied unless someone else changed the same line or Spot after that, to
	 * a different value. In that case the server copy stays, the device's
	 * change is kept as a proposal, and the lead picks one. A person who may
	 * manage the mission is told about the conflict and decides on their own
	 * device: sending `force` applies their copy.
	 *
	 * @param int                  $id   Mission ID.
	 * @param int                  $user The crew member.
	 * @param array<string, mixed> $body revision, lines (id => done), outcomes (Spot ID => outcome), force.
	 * @return array{applied: int, conflicts: array<int, array<string, mixed>>, refused: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function progress( int $id, int $user, array $body ) {
		if ( ! self::get( $id ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That mission does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_work( $id, $user ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'This mission belongs to another organization.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! self::is_open( $id ) ) {
			return new \WP_Error( 'packout_mission_closed', __( 'This mission has ended. Nothing more can be recorded on it.', 'packout-core' ), array( 'status' => 409 ) );
		}

		$manage    = self::can_manage( $id, $user );
		$force     = $manage && ! empty( $body['force'] );
		$seen      = (int) ( $body['revision'] ?? 0 );
		$next      = self::revision( $id ) + 1;
		$lines     = self::checklist( $id );
		$recorded  = self::recorded( $id );
		$proposals = self::proposals( $id );
		$linked    = self::spots( $id );
		$applied   = 0;
		$conflicts = array();
		$refused   = array();

		foreach ( (array) ( $body['lines'] ?? array() ) as $line_id => $done ) {
			$line_id = (string) $line_id;
			$done    = ! empty( $done ) && 'false' !== $done;
			$index   = array_search( $line_id, array_column( $lines, 'id' ), true );
			if ( false === $index ) {
				$refused[] = array(
					'type'    => 'line',
					'id'      => $line_id,
					'message' => __( 'The lead removed this line.', 'packout-core' ),
				);
				continue;
			}
			if ( $lines[ $index ]['done'] === $done ) {
				$proposals = self::without( $proposals, 'line:' . $line_id, $user );
				continue;
			}
			if ( $lines[ $index ]['rev'] > $seen && $lines[ $index ]['by'] !== $user && ! $force ) {
				$proposals   = $manage ? $proposals : self::proposed( $proposals, 'line:' . $line_id, $done ? '1' : '0', $user );
				$conflicts[] = array(
					'type'   => 'line',
					'id'     => $line_id,
					'mine'   => $done,
					'theirs' => $lines[ $index ]['done'],
				);
				continue;
			}
			$lines[ $index ]['done'] = $done;
			$lines[ $index ]['rev']  = $next;
			$lines[ $index ]['by']   = $user;
			$proposals               = self::without( $proposals, 'line:' . $line_id, 0 );
			++$applied;
		}//end foreach

		foreach ( (array) ( $body['outcomes'] ?? array() ) as $spot_id => $outcome ) {
			$spot_id = (int) $spot_id;
			$outcome = sanitize_key( (string) $outcome );
			if ( ! in_array( $spot_id, $linked, true ) ) {
				$refused[] = array(
					'type'    => 'spot',
					'id'      => $spot_id,
					'message' => __( 'This Spot is no longer on the mission.', 'packout-core' ),
				);
				continue;
			}
			if ( ! isset( self::outcomes()[ $outcome ] ) || ( 'reopen' === $outcome && ! $manage ) ) {
				$refused[] = array(
					'type'    => 'spot',
					'id'      => $spot_id,
					'message' => __( 'That outcome is not available.', 'packout-core' ),
				);
				continue;
			}
			$current = $recorded[ $spot_id ] ?? null;
			if ( $current && $current['outcome'] === $outcome ) {
				$proposals = self::without( $proposals, 'spot:' . $spot_id, $user );
				continue;
			}
			if ( $current && $current['rev'] > $seen && $current['by'] !== $user && ! $force ) {
				$proposals   = $manage ? $proposals : self::proposed( $proposals, 'spot:' . $spot_id, $outcome, $user );
				$conflicts[] = array(
					'type'   => 'spot',
					'id'     => $spot_id,
					'mine'   => $outcome,
					'theirs' => $current['outcome'],
				);
				continue;
			}
			$result = self::apply_outcome( $id, $spot_id, $outcome, $user );
			if ( is_wp_error( $result ) ) {
				$refused[] = array(
					'type'    => 'spot',
					'id'      => $spot_id,
					'message' => $result->get_error_message(),
				);
				continue;
			}
			$recorded[ $spot_id ] = array(
				'outcome' => $outcome,
				'rev'     => $next,
				'by'      => $user,
			);
			$proposals            = self::without( $proposals, 'spot:' . $spot_id, 0 );
			++$applied;
		}//end foreach

		self::write( $id, 'proposals', $proposals );
		if ( $applied > 0 ) {
			self::write( $id, 'checklist', $lines );
			self::write( $id, 'outcomes', $recorded );
			update_post_meta( $id, '_pos_revision', $next );
			// Work recorded from the field means the mission has started.
			self::set_status( $id, 'in_progress' );
		}

		return array(
			'applied'   => $applied,
			'conflicts' => $conflicts,
			'refused'   => $refused,
		);
	}

	/**
	 * The lead's decision on a proposal: apply the phone's copy or keep the server's.
	 *
	 * @param int    $id     Mission ID.
	 * @param string $key    Proposal key, such as line:ab12cd34 or spot:42.
	 * @param int    $from   The crew member who proposed it.
	 * @param bool   $accept True to apply the proposal.
	 * @param int    $user   The lead deciding.
	 * @return true|\WP_Error
	 */
	public static function decide( int $id, string $key, int $from, bool $accept, int $user ) {
		$found = null;
		foreach ( self::proposals( $id ) as $proposal ) {
			if ( $proposal['key'] === $key && $proposal['user'] === $from ) {
				$found = $proposal;
			}
		}
		if ( ! $found || ! self::can_manage( $id, $user ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That change is no longer waiting.', 'packout-core' ), array( 'status' => 404 ) );
		}

		if ( $accept ) {
			list( $type, $target ) = explode( ':', $key, 2 );
			$body                  = array(
				'revision' => self::revision( $id ),
				'force'    => true,
			);
			if ( 'line' === $type ) {
				$body['lines'] = array( $target => '1' === $found['value'] );
			} else {
				$body['outcomes'] = array( (int) $target => $found['value'] );
			}
			$result = self::progress( $id, $user, $body );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( $result['refused'] ) {
				return new \WP_Error( 'packout_refused', $result['refused'][0]['message'], array( 'status' => 409 ) );
			}
		}

		self::write( $id, 'proposals', self::without( self::proposals( $id ), $key, $accept ? 0 : $from ) );
		self::touch( $id );
		return true;
	}

	/**
	 * Missions a person works on, newest window first.
	 *
	 * Open missions, and missions that ended in the last thirty days so the
	 * field app can show how they finished. A coordinator sees every team's.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, \WP_Post>
	 */
	public static function for_user( int $user_id ): array {
		$args = array(
			'post_type'   => Schema::MISSION,
			'post_status' => array( 'publish', 'private' ),
			'numberposts' => 200,
			'orderby'     => 'modified',
			'order'       => 'DESC',
		);
		if ( ! ( Capabilities::can( Capabilities::TEAM, $user_id ) && Capabilities::can( Capabilities::LEAD, $user_id ) ) ) {
			$teams = Teams::of_user( $user_id );
			if ( ! $teams ) {
				return array();
			}
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Missions of the person's teams.
			$args['meta_query'] = array(
				array(
					'key'     => '_pos_team',
					'value'   => $teams,
					'compare' => 'IN',
				),
			);
		}

		$recent = time() - 30 * DAY_IN_SECONDS;
		return array_values(
			array_filter(
				get_posts( $args ),
				static fn( $post ) => self::is_open( $post->ID ) || strtotime( $post->post_modified_gmt . ' UTC' ) >= $recent
			)
		);
	}

	/**
	 * IDs of the Spots on a person's open missions.
	 *
	 * A crew member sees the exact pin of these, even when the public sees an
	 * approximate one.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, int>
	 */
	public static function crew_spots( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		if ( ! isset( self::$crew[ $user_id ] ) ) {
			$spots = array();
			foreach ( self::for_user( $user_id ) as $mission ) {
				if ( self::is_open( $mission->ID ) && self::can_work( $mission->ID, $user_id ) ) {
					$spots = array_merge( $spots, self::spots( $mission->ID ) );
				}
			}
			self::$crew[ $user_id ] = array_values( array_unique( $spots ) );
		}
		return self::$crew[ $user_id ];
	}

	/**
	 * Forget what was worked out for this request. Called when a mission or a team changes.
	 */
	public static function flush(): void {
		self::$crew = array();
	}

	/**
	 * A mission as the field app receives it. For crew, leads, and coordinators only.
	 *
	 * @param \WP_Post $post    Mission.
	 * @param int      $user_id The person asking.
	 * @return array<string, mixed>
	 */
	public static function payload( \WP_Post $post, int $user_id ): array {
		$id       = $post->ID;
		$team     = Teams::get( self::team( $id ) );
		$manage   = self::can_manage( $id, $user_id );
		$recorded = self::recorded( $id );
		$point    = Geo::point( self::field( $id, 'meeting_lat' ), self::field( $id, 'meeting_lng' ) );

		$spots = array();
		foreach ( self::spots( $id ) as $spot_id ) {
			$spot = Spots::get( $spot_id );
			if ( ! $spot || 'trash' === $spot->post_status ) {
				continue;
			}
			$row            = Spots::payload( $spot, 'exact' );
			$row['outcome'] = $recorded[ $spot_id ]['outcome'] ?? '';
			// The lead writes up the work at each Spot this mission cleaned.
			$ours          = 'cleaned' === $row['status'] && (int) Spots::field( $spot_id, 'cleaned_mission', '0' ) === $id;
			$record        = $ours ? Impact::current( $spot_id ) : null;
			$row['record'] = $manage && $ours;
			$row['impact'] = $manage && $record ? Impact::payload( $record ) : null;
			$spots[]       = $row;
		}

		$lines = array();
		foreach ( self::checklist( $id ) as $line ) {
			$lines[] = array(
				'id'   => $line['id'],
				'text' => $line['text'],
				'done' => $line['done'],
			);
		}

		$waiting = array();
		foreach ( self::proposals( $id ) as $proposal ) {
			if ( $manage || $proposal['user'] === $user_id ) {
				$person    = get_userdata( $proposal['user'] );
				$waiting[] = array(
					'key'   => $proposal['key'],
					'value' => $proposal['value'],
					'mine'  => $proposal['user'] === $user_id,
					'name'  => $person ? $person->display_name : '',
				);
			}
		}

		return array(
			'id'         => $id,
			'title'      => $post->post_title,
			'objective'  => self::field( $id, 'objective' ),
			'status'     => self::status( $id ),
			'revision'   => self::revision( $id ),
			'public'     => 'publish' === $post->post_status,
			'team'       => array(
				'id'   => $team ? $team->ID : 0,
				'name' => $team ? $team->post_title : '',
			),
			'window'     => array(
				'start'      => self::field( $id, 'start' ),
				'end'        => self::field( $id, 'end' ),
				'start_time' => self::field( $id, 'start_time' ),
				'end_time'   => self::field( $id, 'end_time' ),
				'zone'       => self::field( $id, 'timezone' ),
				'label'      => self::window_label( $id ),
			),
			'meeting'    => array(
				'note' => self::field( $id, 'meeting_note' ),
				'lat'  => $point ? $point[0] : null,
				'lng'  => $point ? $point[1] : null,
			),
			'gear'       => self::field( $id, 'gear' ),
			'can_manage' => $manage,
			'campaign'   => self::campaign( $id ) > 0 ? (string) get_the_title( self::campaign( $id ) ) : '',
			'subteams'   => Subteams::names( self::team( $id ), self::subteams( $id ) ),
			// What the crew needs to hand: the team's resources, its sub-teams', and the mission's own.
			'resources'  => Resources::payload( $id ),
			'checklist'  => $lines,
			'spots'      => $spots,
			'waiting'    => $waiting,
		);
	}

	/**
	 * What the public may know about a team's missions.
	 *
	 * Title, dates, the team, and how many Spots. The meeting landmark is
	 * included only when the lead marked it shareable. The pin never is.
	 *
	 * @param int $team_id Team ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function public_of_team( int $team_id ): array {
		$missions = get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => 'publish',
				'numberposts' => 100,
				'orderby'     => 'date',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Missions of one team.
				'meta_key'    => '_pos_team',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Missions of one team.
				'meta_value'  => $team_id,
			)
		);

		$public = array();
		foreach ( $missions as $mission ) {
			if ( Post_Access::can_read( $mission, 0 ) ) {
				$public[] = self::public_payload( $mission );
			}
		}
		return $public;
	}

	/**
	 * The public fields of one public mission.
	 *
	 * @param \WP_Post $mission Mission.
	 * @return array<string, mixed>
	 */
	public static function public_payload( \WP_Post $mission ): array {
		$id   = $mission->ID;
		$team = Teams::get( self::team( $id ) );
		// A mission kept on a partner's site says how many Spots it has. Which ones they are stays there.
		$count    = Remote::is( $id ) ? (int) get_post_meta( $id, '_pos_remote_spots', true ) : count( self::spots( $id ) );
		$status   = self::status( $id );
		$campaign = Campaigns::get( self::campaign( $id ) );
		return array(
			'id'           => $id,
			'title'        => $mission->post_title,
			'url'          => (string) get_permalink( $mission ),
			'status'       => $status,
			'status_label' => self::statuses()[ $status ] ?? $status,
			'window'       => self::window_label( $id ),
			'spots'        => $count,
			// A copy of a repeated mission has no Spots until its lead plans the visit.
			/* translators: %d: number of Spots. */
			'spots_label'  => $count > 0 ? sprintf( _n( '%d Spot', '%d Spots', $count, 'packout-core' ), $count ) : __( 'Spots not chosen yet', 'packout-core' ),
			'team'         => $team && 'publish' === $team->post_status ? $team->post_title : '',
			'team_url'     => $team && 'publish' === $team->post_status ? (string) get_permalink( $team ) : '',
			'landmark'     => '1' === self::field( $id, 'meeting_share' ) ? self::field( $id, 'meeting_note' ) : '',
			'campaign'     => $campaign && 'publish' === $campaign->post_status ? $campaign->post_title : '',
			'campaign_url' => $campaign && 'publish' === $campaign->post_status ? (string) get_permalink( $campaign ) : '',
			'sponsors'     => self::sponsors( $id ),
			'resources'    => Resources::public_of_mission( $id ),
			'description'  => self::field( $id, 'description' ),
			'cover'        => Page_Header::cover( $id ),
		);
	}

	/**
	 * The mission window in words.
	 *
	 * @param int $id Mission ID.
	 */
	public static function window_label( int $id ): string {
		return Schedule::label( $id );
	}

	/**
	 * The public page of a public mission.
	 *
	 * @param string $content Post content.
	 */
	public static function page( $content ) {
		if ( ! is_singular( Schema::MISSION ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return '';
		}

		wp_enqueue_style( 'packout-workspace' );
		wp_enqueue_script( 'packout-workspace' );
		wp_enqueue_style( 'packout-blocks' );
		$mission = self::public_payload( $post );
		$facts   = array();
		if ( '' !== $mission['team'] ) {
			$facts[] = '<a href="' . esc_url( $mission['team_url'] ) . '">' . esc_html( $mission['team'] ) . '</a>';
		}
		if ( '' !== $mission['campaign'] ) {
			$facts[] = '<a href="' . esc_url( $mission['campaign_url'] ) . '">' . esc_html( $mission['campaign'] ) . '</a>';
		}
		if ( '' !== $mission['window'] ) {
			$facts[] = esc_html( $mission['window'] );
		}
		$facts[] = esc_html( $mission['spots_label'] );
		$facts[] = Remote::fact( $post->ID );

		$html  = '<div class="pos-mission-page pos-place-page pos-place pos-event">';
		$html .= Page_Header::render(
			array(
				'cover'       => $mission['cover'],
				/* translators: %s: mission status. */
				'kind'        => sprintf( __( 'Mission · %s', 'packout-core' ), $mission['status_label'] ),
				'title'       => $mission['title'],
				'description' => $mission['description'],
				'facts'       => $facts,
				'sponsors'    => $mission['sponsors'],
			)
		);
		$html .= '<div class="pos-place__body">';

		$html .= '<section class="pos-place__section"><h2>' . esc_html__( 'About this mission', 'packout-core' ) . '</h2><dl class="pos-event__facts">';
		$html .= '<dt>' . esc_html__( 'Status', 'packout-core' ) . '</dt><dd>' . esc_html( $mission['status_label'] ) . '</dd>';
		if ( '' !== $mission['team'] ) {
			$html .= '<dt>' . esc_html__( 'Organization', 'packout-core' ) . '</dt><dd><a href="' . esc_url( $mission['team_url'] ) . '">' . esc_html( $mission['team'] ) . '</a></dd>';
		}
		if ( '' !== $mission['campaign'] ) {
			$html .= '<dt>' . esc_html__( 'Campaign', 'packout-core' ) . '</dt><dd><a href="' . esc_url( $mission['campaign_url'] ) . '">' . esc_html( $mission['campaign'] ) . '</a></dd>';
		}
		if ( '' !== $mission['window'] ) {
			$html .= '<dt>' . esc_html__( 'When', 'packout-core' ) . '</dt><dd>' . esc_html( $mission['window'] ) . '</dd>';
		}
		$html .= '<dt>' . esc_html__( 'Spots', 'packout-core' ) . '</dt><dd>' . esc_html( $mission['spots_label'] ) . '</dd>';
		if ( '' !== $mission['landmark'] ) {
			$html .= '<dt>' . esc_html__( 'Meeting place', 'packout-core' ) . '</dt><dd>' . esc_html( $mission['landmark'] ) . '</dd>';
		}
		$html .= '</dl>';
		if ( Calendar::public_mission( $post->ID ) ) {
			$html .= '<p class="pos-event__calendar"><a class="pos-ws__button" href="' . esc_url( Calendar::mission_file_url( $post->ID ) ) . '">' . esc_html__( 'Add to my calendar', 'packout-core' ) . '</a></p>';
		}
		$html .= '<p>' . esc_html__( 'The exact places and the crew checklist are shared with the organization.', 'packout-core' ) . '</p></section>';

		$html .= Signups::section( $post->ID );

		if ( $mission['resources'] ) {
			$html .= '<section class="pos-place__section"><h2>' . esc_html__( 'Resources', 'packout-core' ) . '</h2>' . Resources::html( $mission['resources'] ) . '</section>';
		}
		return $html . '</div></div>';
	}

	/**
	 * Open missions that include a Spot.
	 *
	 * @param int $spot_id Spot ID.
	 * @param int $except  Mission to leave out.
	 * @return array<int, \WP_Post>
	 */
	public static function open_with( int $spot_id, int $except = 0 ): array {
		$missions = get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 50,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Missions that include one Spot.
				'meta_query'  => array(
					array(
						'key'   => '_pos_spot',
						'value' => $spot_id,
					),
				),
			)
		);
		return array_values( array_filter( $missions, static fn( $mission ) => $mission->ID !== $except && self::is_open( $mission->ID ) ) );
	}

	/**
	 * Another open mission that includes a Spot.
	 *
	 * @param int $spot_id Spot ID.
	 * @param int $except  Mission to leave out.
	 * @return int The other mission's ID, or 0.
	 */
	public static function other_open( int $spot_id, int $except ): int {
		$others = self::open_with( $spot_id, $except );
		return $others ? $others[0]->ID : 0;
	}

	/**
	 * Add a Spot to a mission and move its stage.
	 *
	 * @param int $id      Mission ID.
	 * @param int $spot_id Spot ID.
	 */
	private static function link( int $id, int $spot_id ): void {
		add_post_meta( $id, '_pos_spot', $spot_id );
		// The day the Spot joined, for the overview of the organization that took it on.
		add_post_meta( $id, '_pos_spot_on', $spot_id . ':' . wp_date( 'Y-m-d' ) );
		// A Spot that came back after a cleanup waits in "watch". A new mission plans it like any open Spot.
		if ( in_array( Spots::field( $spot_id, 'status', 'spotted' ), array( 'spotted', 'watch' ), true ) ) {
			Spots::set_status( $spot_id, 'planned' );
			add_post_meta( $id, '_pos_moved', $spot_id );
		}
		if ( 'in_progress' === self::status( $id ) && 'planned' === Spots::field( $spot_id, 'status', 'spotted' ) ) {
			Spots::set_status( $spot_id, 'in_progress' );
		}
	}

	/**
	 * Take a Spot off a mission.
	 *
	 * @param int $id      Mission ID.
	 * @param int $spot_id Spot ID.
	 */
	private static function unlink( int $id, int $spot_id ): void {
		delete_post_meta( $id, '_pos_spot', $spot_id );
		foreach ( (array) get_post_meta( $id, '_pos_spot_on', false ) as $row ) {
			if ( 0 === strpos( (string) $row, $spot_id . ':' ) ) {
				delete_post_meta( $id, '_pos_spot_on', $row );
			}
		}
		self::release( $id, $spot_id, false );
		$recorded = self::recorded( $id );
		unset( $recorded[ $spot_id ] );
		self::write( $id, 'outcomes', $recorded );
		self::write( $id, 'proposals', self::without( self::proposals( $id ), 'spot:' . $spot_id, 0 ) );
	}

	/**
	 * Let go of a Spot this mission was holding.
	 *
	 * The Spot returns to spotted when this mission made it planned, or when
	 * the lead asked for that, and it is not cleaned. Another open mission
	 * that includes the Spot takes over instead, and the Spot stays as it is.
	 *
	 * @param int  $id      Mission ID.
	 * @param int  $spot_id Spot ID.
	 * @param bool $always  True when the lead chose to return the Spot whoever planned it.
	 */
	private static function release( int $id, int $spot_id, bool $always ): void {
		$moved = in_array( $spot_id, array_map( 'intval', (array) get_post_meta( $id, '_pos_moved', false ) ), true );
		delete_post_meta( $id, '_pos_moved', $spot_id );
		if ( ! $moved && ! $always ) {
			return;
		}
		$other = self::other_open( $spot_id, $id );
		if ( $other > 0 ) {
			if ( ! in_array( $spot_id, array_map( 'intval', (array) get_post_meta( $other, '_pos_moved', false ) ), true ) ) {
				add_post_meta( $other, '_pos_moved', $spot_id );
			}
			return;
		}
		if ( in_array( Spots::field( $spot_id, 'status', 'spotted' ), array( 'planned', 'in_progress' ), true ) ) {
			Spots::set_status( $spot_id, Spots::waiting_status( $spot_id ) );
		}
	}

	/**
	 * Apply one outcome to its Spot.
	 *
	 * @param int    $id      Mission ID.
	 * @param int    $spot_id Spot ID.
	 * @param string $outcome cleaned, open, left, or reopen.
	 * @param int    $user    Who recorded it.
	 * @return true|\WP_Error
	 */
	private static function apply_outcome( int $id, int $spot_id, string $outcome, int $user ) {
		$stage = Spots::field( $spot_id, 'status', 'spotted' );
		$ours  = 'cleaned' === $stage && (int) Spots::field( $spot_id, 'cleaned_mission', '0' ) === $id;

		if ( 'cleaned' === $outcome ) {
			return Spots::mark_cleaned( $spot_id, $user, $id );
		}
		if ( 'cleaned' === $stage && ! $ours ) {
			// Someone else documented this Spot as cleaned. A mission note does not undo that.
			return true;
		}
		if ( 'open' === $outcome ) {
			if ( $ours || 'planned' === $stage ) {
				Spots::set_status( $spot_id, 'in_progress' );
			}
			return true;
		}
		if ( 'left' === $outcome && 'none' === Spots::field( $spot_id, 'hazard', 'none' ) ) {
			Spots::set_hazard( $spot_id, 'needs_authorization' );
		}
		// Left, or sent back by the lead: nobody is working on it now.
		Spots::set_status( $spot_id, Spots::waiting_status( $spot_id ) );
		return true;
	}

	/**
	 * The checklist after the lead's edit.
	 *
	 * An existing line keeps its tick. A line whose text was cleared is removed.
	 *
	 * @param array<int, array<string, mixed>> $lines Current lines.
	 * @param array<string, mixed>             $input lines (id => text) and new_lines (one per line).
	 * @param int                              $rev   Revision of this save.
	 * @return array<int, array<string, mixed>>
	 */
	private static function edited_lines( array $lines, array $input, int $rev ): array {
		$kept = array();
		if ( array_key_exists( 'lines', $input ) ) {
			$texts = (array) $input['lines'];
			foreach ( $lines as $line ) {
				$text = sanitize_text_field( (string) ( $texts[ $line['id'] ] ?? '' ) );
				if ( '' === $text ) {
					continue;
				}
				// New wording is not a tick, so the line's revision stays.
				$line['text'] = mb_substr( $text, 0, 200 );
				$kept[]       = $line;
			}
		} else {
			$kept = $lines;
		}

		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $input['new_lines'] ?? '' ) ) as $text ) {
			$text = sanitize_text_field( (string) $text );
			if ( '' !== $text && count( $kept ) < self::MAX_LINES ) {
				$kept[] = array(
					'id'   => substr( md5( uniqid( $text, true ) ), 0, 8 ),
					'text' => mb_substr( $text, 0, 200 ),
					'done' => false,
					'rev'  => $rev,
					'by'   => 0,
				);
			}
		}
		return array_slice( $kept, 0, self::MAX_LINES );
	}

	/**
	 * Proposals with one person's proposal for a key added or replaced.
	 *
	 * @param array<int, array<string, mixed>> $proposals Proposals.
	 * @param string                           $key       Proposal key.
	 * @param string                           $value     Proposed value.
	 * @param int                              $user      Who proposed it.
	 * @return array<int, array<string, mixed>>
	 */
	private static function proposed( array $proposals, string $key, string $value, int $user ): array {
		$proposals   = self::without( $proposals, $key, $user );
		$proposals[] = array(
			'key'   => $key,
			'value' => $value,
			'user'  => $user,
			'at'    => gmdate( 'c' ),
		);
		return $proposals;
	}

	/**
	 * Proposals without those for a key. A user of 0 removes everyone's.
	 *
	 * @param array<int, array<string, mixed>> $proposals Proposals.
	 * @param string                           $key       Proposal key.
	 * @param int                              $user      Whose proposal to remove, or 0 for all.
	 * @return array<int, array<string, mixed>>
	 */
	private static function without( array $proposals, string $key, int $user ): array {
		return array_values( array_filter( $proposals, static fn( $row ) => $row['key'] !== $key || ( $user > 0 && $row['user'] !== $user ) ) );
	}

	/**
	 * Store one text field, or remove it when empty.
	 *
	 * @param int    $id    Mission ID.
	 * @param string $key   Field name without the prefix.
	 * @param string $value Value.
	 */
	private static function set( int $id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $id, '_pos_' . $key );
		} else {
			update_post_meta( $id, '_pos_' . $key, wp_slash( $value ) );
		}
		if ( 'status' === $key && '' !== $value ) {
			Vocabularies::assign( $id, Vocabularies::MISSION_STATUS, $value );
		}
	}

	/**
	 * A list field, stored as JSON so a site that keeps the fields in table columns holds plain text.
	 *
	 * @param int    $id  Mission ID.
	 * @param string $key Field name without the prefix.
	 * @return array<int|string, mixed>
	 */
	private static function stored( int $id, string $key ): array {
		return Schema::stored_list( $id, $key );
	}

	/**
	 * Write a list field.
	 *
	 * @param int                      $id    Mission ID.
	 * @param string                   $key   Field name without the prefix.
	 * @param array<int|string, mixed> $value List.
	 */
	private static function write( int $id, string $key, array $value ): void {
		Schema::write_list( $id, $key, $value );
	}

	/**
	 * Raise the revision counter.
	 *
	 * @param int $id Mission ID.
	 */
	private static function touch( int $id ): void {
		update_post_meta( $id, '_pos_revision', self::revision( $id ) + 1 );
	}
}
