<?php
/**
 * Sub-teams.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A sub-team is a named group inside a team: a chapter, a trailer crew.
 * People read "team" for a sub-team and "organization" for its team.
 *
 * It has a lead, co-leads, and members, all of whom are on the team.
 * Sub-teams are part of the team record. A campaign names the sub-teams
 * taking part, and a mission names the ones it is assigned to. Leading or
 * co-leading a sub-team lets a person run the missions assigned to it, choose
 * its members, and share resources with it. The lead also names the
 * co-leads. None of it gives a role or a capability.
 *
 * A sub-team can have a public page under its team's address. The people
 * who run it turn the page on, write what it says, choose its address, and
 * say how people join: chosen by the lead, by asking, or at once.
 */
class Subteams {

	/**
	 * Most sub-teams one team holds.
	 */
	private const LIMIT = 40;

	/**
	 * Addresses a sub-team cannot have: WordPress uses them under the address of a team.
	 */
	private const RESERVED = array( 'attachment', 'embed', 'feed', 'page', 'trackback' );

	/**
	 * Most co-leads one sub-team holds.
	 */
	private const COLEAD_LIMIT = 10;

	/**
	 * The sub-teams of a team, by name.
	 *
	 * @param int $team_id Team ID.
	 * @return array<int, array{id: int, name: string, lead: int, coleads: array<int, int>, members: array<int, int>, logo: int, cover: int, about: string, slug: string, old: array<int, string>, public: bool, leads: bool, join: string, requests: array<int, int>}>
	 */
	public static function all( int $team_id ): array {
		$rows  = array();
		$taken = array();
		foreach ( Schema::stored_list( $team_id, 'subteams' ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$rows[] = $row;
			if ( '' !== (string) ( $row['slug'] ?? '' ) ) {
				$taken[ (string) $row['slug'] ] = true;
			}
			foreach ( (array) ( $row['old'] ?? array() ) as $before ) {
				$taken[ (string) $before ] = true;
			}
		}

		$list = array();
		foreach ( $rows as $row ) {
			$lead    = (int) ( $row['lead'] ?? 0 );
			$members = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $row['members'] ?? array() ) ) ) ) );
			$slug    = (string) ( $row['slug'] ?? '' );
			if ( '' === $slug ) {
				// A sub-team made before sub-teams had pages gets its address from its name.
				$slug           = self::address_from( (string) ( $row['name'] ?? '' ), (int) $row['id'], $taken );
				$taken[ $slug ] = true;
			}
			$join   = (string) ( $row['join'] ?? 'closed' );
			$youth  = ! empty( $row['youth'] );
			$list[] = array(
				'id'       => (int) $row['id'],
				'name'     => (string) ( $row['name'] ?? '' ),
				'lead'     => $lead,
				// A co-lead is one of the members.
				'coleads'  => array_values( array_intersect( $members, array_map( 'intval', (array) ( $row['coleads'] ?? array() ) ) ) ),
				'members'  => $members,
				'logo'     => (int) ( $row['logo'] ?? 0 ),
				'cover'    => (int) ( $row['cover'] ?? 0 ),
				'about'    => (string) ( $row['about'] ?? '' ),
				// A team of an organization kept on a partner's site: the site's name for it, and its page there.
				'remote'   => (string) ( $row['remote'] ?? '' ),
				'url'      => (string) ( $row['url'] ?? '' ),
				'rev'      => (int) ( $row['rev'] ?? 0 ),
				// A team of people under eighteen. Nobody is named on its page, nobody joins it from its page, and no photograph of its work leaves the site.
				'youth'    => $youth,
				'slug'     => $slug,
				'old'      => array_values( array_unique( array_filter( array_map( 'strval', (array) ( $row['old'] ?? array() ) ) ) ) ),
				'public'   => ! empty( $row['public'] ),
				// The page names the lead and the co-leads unless the people who run the sub-team say otherwise.
				'leads'    => ! $youth && ( ! array_key_exists( 'leads', $row ) || ! empty( $row['leads'] ) ),
				'join'     => ! $youth && isset( self::join_policies()[ $join ] ) ? $join : 'closed',
				// A person who is in the sub-team is no longer asking to be.
				'requests' => $youth ? array() : array_values( array_diff( array_unique( array_filter( array_map( 'intval', (array) ( $row['requests'] ?? array() ) ) ) ), $members, array( $lead ) ) ),
			);
		}//end foreach
		usort( $list, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
		return $list;
	}

	/**
	 * One sub-team, or null.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Sub-team number.
	 * @return array<string, mixed>|null From all().
	 */
	public static function get( int $team_id, int $id ): ?array {
		foreach ( self::all( $team_id ) as $subteam ) {
			if ( $subteam['id'] === $id ) {
				return $subteam;
			}
		}
		return null;
	}

	/**
	 * The numbers in a list that are sub-teams of this team.
	 *
	 * @param int               $team_id Team ID.
	 * @param array<int, mixed> $ids     Numbers a form or a record named.
	 * @return array<int, int>
	 */
	public static function known( int $team_id, array $ids ): array {
		$ids = array_map( 'intval', $ids );
		return array_values( array_filter( wp_list_pluck( self::all( $team_id ), 'id' ), static fn( $id ) => in_array( $id, $ids, true ) ) );
	}

	/**
	 * Names of the sub-teams in a list.
	 *
	 * @param int             $team_id Team ID.
	 * @param array<int, int> $ids     Sub-team numbers.
	 * @return array<int, string>
	 */
	public static function names( int $team_id, array $ids ): array {
		$names = array();
		foreach ( self::all( $team_id ) as $subteam ) {
			if ( in_array( $subteam['id'], $ids, true ) ) {
				$names[] = $subteam['name'];
			}
		}
		return $names;
	}

	/**
	 * Sub-teams a person leads or belongs to.
	 *
	 * @param int $team_id Team ID.
	 * @param int $user_id User ID.
	 * @return array<int, int>
	 */
	public static function of_user( int $team_id, int $user_id ): array {
		$ids = array();
		foreach ( self::all( $team_id ) as $subteam ) {
			if ( $user_id > 0 && ( $subteam['lead'] === $user_id || in_array( $user_id, $subteam['members'], true ) ) ) {
				$ids[] = $subteam['id'];
			}
		}
		return $ids;
	}

	/**
	 * Sub-teams a person leads or co-leads.
	 *
	 * @param int $team_id Team ID.
	 * @param int $user_id User ID.
	 * @return array<int, int>
	 */
	public static function led_by( int $team_id, int $user_id ): array {
		$ids = array();
		foreach ( self::all( $team_id ) as $subteam ) {
			if ( $user_id > 0 && ( $subteam['lead'] === $user_id || in_array( $user_id, $subteam['coleads'], true ) ) ) {
				$ids[] = $subteam['id'];
			}
		}
		return $ids;
	}

	/**
	 * Whether a person may run a sub-team: choose its members, run its
	 * missions, and share resources with it.
	 *
	 * The team's leads and a coordinator can, and so can the sub-team's lead
	 * and co-leads.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Sub-team number.
	 * @param int $user_id User ID.
	 */
	public static function can_run( int $team_id, int $id, int $user_id ): bool {
		return Teams::can_run( $team_id, $user_id ) || in_array( $id, self::led_by( $team_id, $user_id ), true );
	}

	/**
	 * Whether a person may name the co-leads of a sub-team.
	 *
	 * The team's leads and a coordinator can, and so can the sub-team's lead.
	 * A co-lead cannot name another.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Sub-team number.
	 * @param int $user_id User ID.
	 */
	public static function can_name( int $team_id, int $id, int $user_id ): bool {
		$subteam = self::get( $team_id, $id );
		return $subteam && $user_id > 0 && ( Teams::can_run( $team_id, $user_id ) || $subteam['lead'] === $user_id );
	}

	/**
	 * Create or change a sub-team.
	 *
	 * @param int                  $team_id Team ID.
	 * @param int                  $id      Sub-team number, or 0 for a new one.
	 * @param array<string, mixed> $input   name, lead.
	 * @param int                  $user_id The person saving.
	 * @return int|\WP_Error Sub-team number.
	 */
	public static function save( int $team_id, int $id, array $input, int $user_id ) {
		if ( ! Teams::get( $team_id ) || ! Teams::can_run( $team_id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead creates and changes teams.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$list  = self::all( $team_id );
		$index = null;
		foreach ( $list as $position => $subteam ) {
			if ( $subteam['id'] === $id ) {
				$index = $position;
			}
		}
		if ( $id > 0 && null === $index ) {
			return new \WP_Error( 'packout_not_found', __( 'That team does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( null === $index && count( $list ) >= self::LIMIT ) {
			return new \WP_Error( 'packout_subteam_limit', __( 'This organization has as many teams as it can hold.', 'packout-core' ), array( 'status' => 400 ) );
		}

		$name = sanitize_text_field( (string) ( $input['name'] ?? ( null !== $index ? $list[ $index ]['name'] : '' ) ) );
		if ( '' === $name || mb_strlen( $name ) > 60 ) {
			return new \WP_Error( 'packout_subteam_name', __( 'Give the team a name of up to 60 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		foreach ( $list as $position => $subteam ) {
			if ( $position !== $index && 0 === strcasecmp( $subteam['name'], $name ) ) {
				return new \WP_Error( 'packout_subteam_taken', __( 'This organization already has a team with that name.', 'packout-core' ), array( 'status' => 409 ) );
			}
		}

		$lead = array_key_exists( 'lead', $input ) ? (int) $input['lead'] : ( null !== $index ? $list[ $index ]['lead'] : 0 );
		if ( $lead > 0 && ! Teams::includes( $team_id, $lead ) ) {
			return new \WP_Error( 'packout_member_unknown', __( 'A team lead is a person in the organization.', 'packout-core' ), array( 'status' => 400 ) );
		}

		if ( null === $index ) {
			$id     = Schema::next_number( $team_id );
			$list[] = array(
				'id'      => $id,
				'name'    => $name,
				'lead'    => $lead,
				'coleads' => array(),
				'members' => array(),
				'logo'    => 0,
				'cover'   => 0,
				'slug'    => self::address_from( $name, $id, array_flip( array_merge( wp_list_pluck( $list, 'slug' ), ...array_values( wp_list_pluck( $list, 'old' ) ) ) ) ),
			);
		} else {
			// The lead is not also listed as a member or a co-lead.
			$list[ $index ]['name']    = $name;
			$list[ $index ]['lead']    = $lead;
			$list[ $index ]['members'] = array_values( array_diff( $list[ $index ]['members'], array( $lead ) ) );
			$list[ $index ]['coleads'] = array_values( array_diff( $list[ $index ]['coleads'], array( $lead ) ) );
		}
		Schema::write_list( $team_id, 'subteams', $list );
		Teams::flush();
		return $id;
	}

	/**
	 * Say whether a team is a youth team: one of people under eighteen.
	 *
	 * A youth team's page names no lead, nobody joins it or signs up to its
	 * missions from a public page, and on a partner site no photograph and no
	 * credit line from a Spot of its missions is sent to packout.spot.
	 *
	 * @param int  $team_id Team ID.
	 * @param int  $id      Sub-team number.
	 * @param bool $on      Whether it is one.
	 * @param int  $user_id The person saying so: a lead of the organization, or a coordinator.
	 * @return true|\WP_Error
	 */
	public static function set_youth( int $team_id, int $id, bool $on, int $user_id ) {
		$subteam = Teams::get( $team_id ) ? self::get( $team_id, $id ) : null;
		if ( ! $subteam || Remote::is( $team_id ) || ! Teams::can_run( $team_id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead says which teams are youth teams.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( $subteam['youth'] === $on ) {
			return true;
		}
		$list = self::all( $team_id );
		foreach ( $list as $position => $row ) {
			if ( $row['id'] === $id ) {
				$list[ $position ]['youth'] = $on;
			}
		}
		Schema::write_list( $team_id, 'subteams', $list );
		Teams::flush();

		/**
		 * A team was marked as a youth team, or the mark was taken off.
		 *
		 * @param int  $team_id Team ID.
		 * @param int  $id      Sub-team number.
		 * @param bool $on      Whether it is one now.
		 */
		do_action( 'packout_subteam_youth', $team_id, $id, $on );
		return true;
	}

	/**
	 * Whether any of the teams named is a youth team.
	 *
	 * @param int             $team_id Team ID.
	 * @param array<int, int> $ids     Sub-team numbers.
	 */
	public static function youth_among( int $team_id, array $ids ): bool {
		if ( ! $ids ) {
			return false;
		}
		foreach ( self::all( $team_id ) as $subteam ) {
			if ( $subteam['youth'] && in_array( $subteam['id'], $ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a Spot is one a youth team works on: a mission that includes it names a youth team.
	 *
	 * @param int $spot_id The Spot.
	 */
	public static function youth_spot( int $spot_id ): bool {
		$missions = get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 50,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Missions that include one Spot.
				'meta_query'  => array(
					array(
						'key'   => '_pos_spot',
						'value' => $spot_id,
					),
				),
			)
		);
		foreach ( $missions as $mission ) {
			if ( self::youth_among( Missions::team( (int) $mission ), Missions::subteams( (int) $mission ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The logo of a sub-team: an attachment ID, or 0.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Sub-team number.
	 */
	public static function logo( int $team_id, int $id ): int {
		$subteam = self::get( $team_id, $id );
		return $subteam && $subteam['logo'] > 0 && 'attachment' === get_post_type( $subteam['logo'] ) ? $subteam['logo'] : 0;
	}

	/**
	 * Upload, replace, or remove the logo of a sub-team.
	 *
	 * @param int                       $team_id Team ID.
	 * @param int                       $id      Sub-team number.
	 * @param array<string, mixed>|null $file    One entry from the request files, or null to remove the logo.
	 * @param int                       $user_id The person uploading.
	 * @return int|\WP_Error Attachment ID, or 0 when the logo was removed.
	 */
	public static function set_logo( int $team_id, int $id, ?array $file, int $user_id ) {
		if ( ! Teams::get( $team_id ) || ! self::get( $team_id, $id ) || ! self::can_run( $team_id, $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The team\'s lead uploads its logo.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$stored = 0;
		if ( $file ) {
			if ( ! Teams::active( $team_id ) ) {
				return new \WP_Error( 'packout_team_pending', __( 'An organization can add a logo once a coordinator has approved it.', 'packout-core' ), array( 'status' => 409 ) );
			}
			$stored = Photos::store( $team_id, $file );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
		}
		$list = self::all( $team_id );
		foreach ( $list as $position => $subteam ) {
			if ( $subteam['id'] === $id ) {
				if ( $subteam['logo'] > 0 ) {
					wp_delete_attachment( $subteam['logo'], true );
				}
				$list[ $position ]['logo'] = (int) $stored;
			}
		}
		Schema::write_list( $team_id, 'subteams', $list );
		Teams::flush();
		return (int) $stored;
	}

	/**
	 * The cover image of a sub-team: an attachment ID, or 0.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Sub-team number.
	 */
	public static function cover( int $team_id, int $id ): int {
		$subteam = self::get( $team_id, $id );
		return $subteam && $subteam['cover'] > 0 && 'attachment' === get_post_type( $subteam['cover'] ) ? $subteam['cover'] : 0;
	}

	/**
	 * Upload, replace, or remove the cover image of a sub-team.
	 *
	 * It is shown behind the sub-team's name on the team's public page. The
	 * people who run the sub-team decide it: its lead, its co-leads, and the
	 * team's leads.
	 *
	 * @param int                       $team_id Team ID.
	 * @param int                       $id      Sub-team number.
	 * @param array<string, mixed>|null $file    One entry from the request files, or null to remove the cover.
	 * @param int                       $user_id The person uploading.
	 * @return int|\WP_Error Attachment ID, or 0 when the cover was removed.
	 */
	public static function set_cover( int $team_id, int $id, ?array $file, int $user_id ) {
		if ( ! Teams::get( $team_id ) || ! self::get( $team_id, $id ) || ! self::can_run( $team_id, $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The team\'s lead chooses its cover image.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$stored = 0;
		if ( $file ) {
			if ( ! Teams::active( $team_id ) ) {
				return new \WP_Error( 'packout_team_pending', __( 'An organization can add a cover image once a coordinator has approved it.', 'packout-core' ), array( 'status' => 409 ) );
			}
			$stored = Photos::store( $team_id, $file, false, 'cover' );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
		}
		$list = self::all( $team_id );
		foreach ( $list as $position => $subteam ) {
			if ( $subteam['id'] === $id ) {
				if ( $subteam['cover'] > 0 ) {
					wp_delete_attachment( $subteam['cover'], true );
				}
				$list[ $position ]['cover'] = (int) $stored;
			}
		}
		Schema::write_list( $team_id, 'subteams', $list );
		Teams::flush();
		return (int) $stored;
	}

	/**
	 * Remove a sub-team. Its people stay on the team. Missions and campaigns
	 * stop naming it, and its resources become resources of the whole team.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Sub-team number.
	 * @param int $user_id The person removing it.
	 * @return true|\WP_Error
	 */
	public static function remove( int $team_id, int $id, int $user_id ) {
		if ( ! Teams::get( $team_id ) || ! Teams::can_run( $team_id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead removes teams.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$gone = self::get( $team_id, $id );
		foreach ( array( 'logo', 'cover' ) as $image ) {
			if ( $gone && $gone[ $image ] > 0 ) {
				wp_delete_attachment( $gone[ $image ], true );
			}
		}
		Schema::write_list( $team_id, 'subteams', array_values( array_filter( self::all( $team_id ), static fn( $subteam ) => $subteam['id'] !== $id ) ) );

		$records = get_posts(
			array(
				'post_type'   => array( Schema::MISSION, Schema::CAMPAIGN ),
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => -1,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Records of one team.
				'meta_key'    => '_pos_team',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Records of one team.
				'meta_value'  => $team_id,
			)
		);
		foreach ( $records as $record ) {
			$named = array_map( 'intval', Schema::stored_list( (int) $record, 'subteams' ) );
			if ( in_array( $id, $named, true ) ) {
				Schema::write_list( (int) $record, 'subteams', array_values( array_diff( $named, array( $id ) ) ) );
			}
		}
		Resources::release_subteam( $team_id, $id );
		Teams::flush();
		return true;
	}

	/**
	 * Put a team member in a sub-team, or take them out.
	 *
	 * @param int  $team_id Team ID.
	 * @param int  $id      Sub-team number.
	 * @param int  $who     The team member.
	 * @param bool $in      Whether they belong to the sub-team after this.
	 * @param int  $user_id The person deciding.
	 * @return true|\WP_Error
	 */
	public static function set_member( int $team_id, int $id, int $who, bool $in, int $user_id ) {
		if ( ! Teams::get( $team_id ) || ! self::get( $team_id, $id ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That team does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_run( $team_id, $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead and the team\'s lead decide who is in it.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( $in && ! Teams::includes( $team_id, $who ) ) {
			return new \WP_Error( 'packout_member_unknown', __( 'A team takes its members from the organization. Add the person to the organization first.', 'packout-core' ), array( 'status' => 400 ) );
		}
		// A co-lead is taken out by the people who name co-leads. A co-lead can step out on their own.
		if ( ! $in && $who !== $user_id && in_array( $who, self::get( $team_id, $id )['coleads'], true ) && ! self::can_name( $team_id, $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The team\'s lead takes its co-leads out.', 'packout-core' ), array( 'status' => 403 ) );
		}

		$list = self::all( $team_id );
		foreach ( $list as $position => $subteam ) {
			if ( $subteam['id'] !== $id ) {
				continue;
			}
			$members = array_values( array_diff( $subteam['members'], array( $who ) ) );
			if ( $in && $subteam['lead'] !== $who ) {
				$members[] = $who;
			}
			$list[ $position ]['members'] = $members;
			$list[ $position ]['coleads'] = array_values( array_intersect( $subteam['coleads'], $members ) );
		}
		Schema::write_list( $team_id, 'subteams', $list );
		Teams::flush();
		return true;
	}

	/**
	 * Put a team member in a sub-team because the team's own rules put them there.
	 *
	 * Used when a person signs up for a campaign or a mission of a team that
	 * takes members: the lead set the join policy, so nobody decides again here.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Sub-team number.
	 * @param int $who     The team member.
	 */
	public static function enrol( int $team_id, int $id, int $who ): bool {
		if ( ! self::get( $team_id, $id ) || ! Teams::includes( $team_id, $who ) ) {
			return false;
		}
		$list = self::all( $team_id );
		foreach ( $list as $position => $subteam ) {
			if ( $subteam['id'] === $id && $subteam['lead'] !== $who && ! in_array( $who, $subteam['members'], true ) ) {
				$list[ $position ]['members'][] = $who;
			}
		}
		Schema::write_list( $team_id, 'subteams', $list );
		Teams::flush();
		return true;
	}

	/**
	 * Name a member of a sub-team a co-lead, or return a co-lead to member.
	 *
	 * @param int  $team_id Team ID.
	 * @param int  $id      Sub-team number.
	 * @param int  $who     The person. On the team. Put in the sub-team if they are not in it yet.
	 * @param bool $on      Whether they co-lead after this.
	 * @param int  $user_id The person deciding.
	 * @return true|\WP_Error
	 */
	public static function set_colead( int $team_id, int $id, int $who, bool $on, int $user_id ) {
		$subteam = Teams::get( $team_id ) ? self::get( $team_id, $id ) : null;
		if ( ! $subteam ) {
			return new \WP_Error( 'packout_not_found', __( 'That team does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_name( $team_id, $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The organization\'s leads and the team\'s lead name its co-leads.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( $on && ( ! Teams::includes( $team_id, $who ) || $subteam['lead'] === $who ) ) {
			return new \WP_Error( 'packout_member_unknown', __( 'A co-lead is a person in the organization who does not already lead the team.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( $on && ! in_array( $who, $subteam['coleads'], true ) && count( $subteam['coleads'] ) >= self::COLEAD_LIMIT ) {
			return new \WP_Error( 'packout_colead_limit', __( 'This team has as many co-leads as it can hold.', 'packout-core' ), array( 'status' => 400 ) );
		}

		$list = self::all( $team_id );
		foreach ( $list as $position => $row ) {
			if ( $row['id'] !== $id ) {
				continue;
			}
			$coleads = array_values( array_diff( $row['coleads'], array( $who ) ) );
			if ( $on ) {
				$coleads[]                    = $who;
				$list[ $position ]['members'] = array_values( array_unique( array_merge( $row['members'], array( $who ) ) ) );
			}
			$list[ $position ]['coleads'] = $coleads;
		}
		Schema::write_list( $team_id, 'subteams', $list );
		Teams::flush();
		return true;
	}

	/**
	 * Take a person out of every sub-team. Called when they leave the team.
	 *
	 * @param int $team_id Team ID.
	 * @param int $who     User ID.
	 */
	public static function drop_person( int $team_id, int $who ): void {
		$list = self::all( $team_id );
		foreach ( $list as $position => $subteam ) {
			$list[ $position ]['members']  = array_values( array_diff( $subteam['members'], array( $who ) ) );
			$list[ $position ]['coleads']  = array_values( array_diff( $subteam['coleads'], array( $who ) ) );
			$list[ $position ]['requests'] = array_values( array_diff( $subteam['requests'], array( $who ) ) );
			if ( $subteam['lead'] === $who ) {
				$list[ $position ]['lead'] = 0;
			}
		}
		Schema::write_list( $team_id, 'subteams', $list );
	}

	/**
	 * Ways a sub-team takes new members, and what each means.
	 *
	 * @return array<string, string>
	 */
	public static function join_policies(): array {
		return Vocabularies::labels( Vocabularies::JOIN );
	}

	/**
	 * Whether a person is in a sub-team, as its lead, a co-lead, or a member.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Sub-team number.
	 * @param int $user_id User ID.
	 */
	public static function includes( int $team_id, int $id, int $user_id ): bool {
		return $user_id > 0 && in_array( $id, self::of_user( $team_id, $user_id ), true );
	}

	/**
	 * Whether a sub-team's public page is shown to everyone.
	 *
	 * The people who run it turned the page on, and its team is approved and listed.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Sub-team number.
	 */
	public static function shown( int $team_id, int $id ): bool {
		$team    = Teams::get( $team_id );
		$subteam = self::get( $team_id, $id );
		return $team && $subteam && $subteam['public'] && 'publish' === $team->post_status && Teams::active( $team_id );
	}

	/**
	 * The sub-teams of a team that have a public page.
	 *
	 * @param int $team_id Team ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function listed( int $team_id ): array {
		return array_values( array_filter( self::all( $team_id ), static fn( $subteam ) => self::shown( $team_id, $subteam['id'] ) ) );
	}

	/**
	 * Where a sub-team's public page is: its address under the address of its team.
	 *
	 * @param int    $team_id Team ID.
	 * @param int    $id      Sub-team number.
	 * @param string $address An address to show instead of the one it has.
	 */
	public static function url( int $team_id, int $id, string $address = '' ): string {
		$subteam = self::get( $team_id, $id );
		if ( $subteam && '' !== $subteam['url'] ) {
			// Its page is on the partner's site.
			return esc_url_raw( $subteam['url'], array( 'https', 'http' ) );
		}
		if ( ! $subteam || '' === Teams::address( $team_id ) ) {
			return '';
		}
		return trailingslashit( Teams::address_url( $team_id, Teams::address( $team_id ) ) ) . user_trailingslashit( '' !== $address ? $address : $subteam['slug'] );
	}

	/**
	 * The sub-team at an address under a team, and whether that is the address it has now.
	 *
	 * An address a sub-team had before still finds it, so old links can forward.
	 *
	 * @param int    $team_id Team ID.
	 * @param string $address Last part of the address.
	 * @return array{0: array<string, mixed>|null, 1: bool}
	 */
	public static function at( int $team_id, string $address ): array {
		$address = strtolower( trim( $address ) );
		$before  = null;
		foreach ( self::all( $team_id ) as $subteam ) {
			if ( $subteam['slug'] === $address ) {
				return array( $subteam, true );
			}
			if ( in_array( $address, $subteam['old'], true ) ) {
				$before = $subteam;
			}
		}
		return array( $before, false );
	}

	/**
	 * Why a sub-team cannot have an address, or an empty string when it can.
	 *
	 * @param int    $team_id Team ID.
	 * @param int    $id      Sub-team number.
	 * @param string $address Address, lower-case and trimmed.
	 */
	public static function address_problem( int $team_id, int $id, string $address ): string {
		$shape = self::address_shape( $address );
		if ( '' !== $shape ) {
			return $shape;
		}
		foreach ( self::all( $team_id ) as $subteam ) {
			if ( $subteam['id'] !== $id && ( $subteam['slug'] === $address || in_array( $address, $subteam['old'], true ) ) ) {
				return __( 'Another team of this organization has that address. Choose another.', 'packout-core' );
			}
		}
		return '';
	}

	/**
	 * What is wrong with the form of an address, or an empty string.
	 *
	 * @param string $address Address.
	 */
	private static function address_shape( string $address ): string {
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{1,58}[a-z0-9]$/', $address ) || 1 === preg_match( '/^[0-9]+$/', $address ) ) {
			return __( 'Use 3 to 60 lower-case letters, digits, and hyphens, starting and ending with a letter or a digit, with at least one letter.', 'packout-core' );
		}
		if ( in_array( $address, self::RESERVED, true ) || 0 === strpos( $address, 'comment-page-' ) ) {
			return __( 'That address is reserved. Choose another.', 'packout-core' );
		}
		return '';
	}

	/**
	 * An address made from a sub-team's name, or from its number when the name makes none.
	 *
	 * @param string               $name  Sub-team name.
	 * @param int                  $id    Sub-team number.
	 * @param array<string, mixed> $taken Addresses in use, as keys.
	 */
	private static function address_from( string $name, int $id, array $taken ): string {
		$address = trim( substr( sanitize_title( $name ), 0, 60 ), '-' );
		if ( '' !== self::address_shape( $address ) || isset( $taken[ $address ] ) ) {
			$address = 'sub-team-' . $id;
		}
		while ( isset( $taken[ $address ] ) ) {
			$address .= '-' . $id;
		}
		return $address;
	}

	/**
	 * Change what a sub-team's public page says, whether it is shown, its address, and how people join.
	 *
	 * @param int                  $team_id Team ID.
	 * @param int                  $id      Sub-team number.
	 * @param array<string, mixed> $input   about, public, leads, address, join. A key left out is not changed.
	 * @param int                  $user_id The person saving.
	 * @return true|\WP_Error
	 */
	public static function update_page( int $team_id, int $id, array $input, int $user_id ) {
		$subteam = Teams::get( $team_id ) ? self::get( $team_id, $id ) : null;
		if ( ! $subteam || ! self::can_run( $team_id, $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The team\'s lead decides what its public page shows.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$about = array_key_exists( 'about', $input ) ? sanitize_textarea_field( (string) $input['about'] ) : $subteam['about'];
		if ( mb_strlen( $about ) > 2000 ) {
			return new \WP_Error( 'packout_subteam_about', __( 'Keep the description to 2,000 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$address = array_key_exists( 'address', $input ) && '' !== trim( (string) $input['address'] ) ? strtolower( trim( (string) $input['address'] ) ) : $subteam['slug'];
		if ( $address !== $subteam['slug'] ) {
			$problem = self::address_problem( $team_id, $id, $address );
			if ( '' !== $problem ) {
				return new \WP_Error( 'packout_subteam_address', $problem, array( 'status' => 400 ) );
			}
		}
		// A youth team's lead chooses its members, whatever a form says.
		$join = array_key_exists( 'join', $input ) && ! $subteam['youth'] ? (string) $input['join'] : $subteam['join'];
		if ( ! isset( self::join_policies()[ $join ] ) ) {
			return new \WP_Error( 'packout_subteam_join', __( 'Choose how people join the team.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$public = array_key_exists( 'public', $input ) ? ! empty( $input['public'] ) : $subteam['public'];
		if ( $public && ! $subteam['public'] && ! Teams::active( $team_id ) ) {
			return new \WP_Error( 'packout_team_pending', __( 'A team can have a public page once a coordinator has approved the organization.', 'packout-core' ), array( 'status' => 409 ) );
		}

		$list = self::all( $team_id );
		foreach ( $list as $position => $row ) {
			if ( $row['id'] !== $id ) {
				continue;
			}
			if ( $address !== $row['slug'] ) {
				// The address it had forwards to the new one.
				$list[ $position ]['old'] = array_values( array_diff( array_unique( array_merge( $row['old'], array( $row['slug'] ) ) ), array( $address ) ) );
			}
			$list[ $position ]['about']  = $about;
			$list[ $position ]['slug']   = $address;
			$list[ $position ]['public'] = $public;
			$list[ $position ]['leads']  = array_key_exists( 'leads', $input ) && ! $row['youth'] ? ! empty( $input['leads'] ) : $row['leads'];
			$list[ $position ]['join']   = $join;
			if ( 'closed' === $join ) {
				// Nobody is left waiting for an answer that will not come.
				$list[ $position ]['requests'] = array();
			}
		}
		Schema::write_list( $team_id, 'subteams', $list );
		Teams::flush();
		return true;
	}

	/**
	 * Ask to join a sub-team from its public page.
	 *
	 * A sub-team takes its members from its team. A person who is not on the
	 * team asks the team first, by the team's own rule, and the sub-team keeps
	 * their request until the team has answered.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Sub-team number.
	 * @param int $user_id The person asking.
	 * @return string|\WP_Error `joined` or `requested`.
	 */
	public static function ask( int $team_id, int $id, int $user_id ) {
		$team    = Teams::get( $team_id );
		$subteam = $team ? self::get( $team_id, $id ) : null;
		if ( ! $subteam || ! self::shown( $team_id, $id ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That team does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! Accounts::on_site( $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'Sign up before joining a team.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( self::includes( $team_id, $id, $user_id ) ) {
			return 'joined';
		}
		if ( 'closed' === $subteam['join'] ) {
			return new \WP_Error( 'packout_subteam_closed', __( 'The lead of this team chooses its members.', 'packout-core' ), array( 'status' => 403 ) );
		}

		$on_team = Teams::includes( $team_id, $user_id );
		if ( ! $on_team ) {
			$asked = Teams::ask( $team_id, $user_id );
			if ( is_wp_error( $asked ) ) {
				return $asked;
			}
			$on_team = 'joined' === $asked;
		}
		if ( $on_team && 'open' === $subteam['join'] ) {
			self::enrol( $team_id, $id, $user_id );
			return 'joined';
		}

		if ( ! in_array( $user_id, $subteam['requests'], true ) ) {
			$list = self::all( $team_id );
			foreach ( $list as $position => $row ) {
				if ( $row['id'] === $id ) {
					$list[ $position ]['requests'][] = $user_id;
				}
			}
			Schema::write_list( $team_id, 'subteams', $list );
			Teams::flush();

			// A sub-team that takes anyone has nothing to decide: the team's answer settles it.
			$person = get_userdata( $user_id );
			if ( $person && 'request' === $subteam['join'] ) {
				foreach ( array_filter( array_map( 'get_userdata', self::deciders( $team_id, $subteam ) ) ) as $lead ) {
					$sent = Approvals::send(
						$lead->user_email,
						'subteam',
						(int) $lead->ID,
						array(
							'id'  => $team_id,
							'sub' => $id,
							'who' => $user_id,
						)
					);
					if ( ! $sent ) {
						Accounts::mail(
							$lead->user_email,
							__( 'Someone asked to join your team', 'packout-core' ),
							array(
								/* translators: 1: the person's name, 2: team name, 3: organization name. */
								sprintf( __( '%1$s asked to join “%2$s”, a team of “%3$s”.', 'packout-core' ), $person->display_name, $subteam['name'], $team->post_title ),
								$on_team ? '' : __( 'They are not in the organization yet. The organization\'s lead answers that request first.', 'packout-core' ),
								__( 'Accept or decline:', 'packout-core' ) . ' ' . Accounts::workspace_url(
									array(
										'team' => $team_id,
										'view' => 'subteams',
									)
								),
							)
						);
					}
				}//end foreach
			}//end if
		}//end if
		return 'requested';
	}

	/**
	 * The people who answer a request to join a sub-team: its lead and co-leads, or the team's leads when it has none.
	 *
	 * @param int                  $team_id Team ID.
	 * @param array<string, mixed> $subteam From all().
	 * @return array<int, int>
	 */
	private static function deciders( int $team_id, array $subteam ): array {
		$people = array_values( array_unique( array_filter( array_merge( array( (int) $subteam['lead'] ), (array) $subteam['coleads'] ) ) ) );
		return $people ? $people : Teams::leads( $team_id );
	}

	/**
	 * Accept or decline a request to join a sub-team.
	 *
	 * @param int  $team_id Team ID.
	 * @param int  $id      Sub-team number.
	 * @param int  $who     The person who asked.
	 * @param bool $accept  Whether they join.
	 * @param int  $user_id The person deciding.
	 * @return true|\WP_Error
	 */
	public static function answer( int $team_id, int $id, int $who, bool $accept, int $user_id ) {
		$team    = Teams::get( $team_id );
		$subteam = $team ? self::get( $team_id, $id ) : null;
		if ( ! $subteam || ! self::can_run( $team_id, $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The team\'s lead answers requests to join it.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! in_array( $who, $subteam['requests'], true ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That request is no longer waiting.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( $accept && ! Teams::includes( $team_id, $who ) ) {
			return new \WP_Error( 'packout_member_unknown', __( 'They are not in the organization yet. The organization\'s lead answers their request to join the organization first.', 'packout-core' ), array( 'status' => 409 ) );
		}
		self::forget( $team_id, $who, $id );
		if ( $accept ) {
			self::enrol( $team_id, $id, $who );
		}
		$person = get_userdata( $who );
		if ( $person ) {
			Accounts::mail(
				$person->user_email,
				$accept ? __( 'You are in the team', 'packout-core' ) : __( 'Your request to join', 'packout-core' ),
				array(
					$accept
						/* translators: 1: team name, 2: organization name. */
						? sprintf( __( 'You are now in “%1$s”, a team of “%2$s”. The missions assigned to it appear in the field app after your next sync.', 'packout-core' ), $subteam['name'], $team->post_title )
						/* translators: 1: team name, 2: organization name. */
						: sprintf( __( 'The lead of “%1$s”, a team of “%2$s”, did not add you to it this time.', 'packout-core' ), $subteam['name'], $team->post_title ),
					$accept ? Accounts::workspace_url( array( 'team' => $team_id ) ) : '',
				)
			);
		}
		return true;
	}

	/**
	 * Take back a request to join a sub-team.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Sub-team number.
	 * @param int $user_id The person who asked.
	 */
	public static function withdraw( int $team_id, int $id, int $user_id ): bool {
		$subteam = self::get( $team_id, $id );
		if ( ! $subteam || ! in_array( $user_id, $subteam['requests'], true ) ) {
			return false;
		}
		self::forget( $team_id, $user_id, $id );
		return true;
	}

	/**
	 * Leave a sub-team. The person stays on the team.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Sub-team number.
	 * @param int $user_id The member leaving.
	 * @return true|\WP_Error
	 */
	public static function leave( int $team_id, int $id, int $user_id ) {
		$subteam = self::get( $team_id, $id );
		if ( ! $subteam || ! self::includes( $team_id, $id, $user_id ) ) {
			return new \WP_Error( 'packout_not_found', __( 'You are not in that team.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( $subteam['lead'] === $user_id ) {
			return new \WP_Error( 'packout_subteam_lead', __( 'You lead this team. The organization\'s lead names another lead before you leave it.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$list = self::all( $team_id );
		foreach ( $list as $position => $row ) {
			if ( $row['id'] === $id ) {
				$list[ $position ]['members'] = array_values( array_diff( $row['members'], array( $user_id ) ) );
				$list[ $position ]['coleads'] = array_values( array_diff( $row['coleads'], array( $user_id ) ) );
			}
		}
		Schema::write_list( $team_id, 'subteams', $list );
		Teams::flush();
		return true;
	}

	/**
	 * When a person joins a team, finish the requests to join its sub-teams that were waiting for it.
	 *
	 * A sub-team that takes anyone takes them now. A sub-team whose lead
	 * decides keeps the request for its lead.
	 *
	 * @param mixed $team_id Team ID.
	 * @param mixed $user_id User ID.
	 */
	public static function settle( $team_id, $user_id ): void {
		$team = (int) $team_id;
		$user = (int) $user_id;
		foreach ( self::all( $team ) as $subteam ) {
			if ( 'open' === $subteam['join'] && in_array( $user, $subteam['requests'], true ) ) {
				self::enrol( $team, $subteam['id'], $user );
			}
		}
	}

	/**
	 * Drop a person's requests to join the sub-teams of a team: all of them, or one.
	 *
	 * @param int $team_id Team ID.
	 * @param int $who     The person.
	 * @param int $id      Sub-team number, or 0 for every sub-team.
	 */
	public static function forget( int $team_id, int $who, int $id = 0 ): void {
		$list  = self::all( $team_id );
		$found = false;
		foreach ( $list as $position => $subteam ) {
			if ( ( 0 === $id || $subteam['id'] === $id ) && in_array( $who, $subteam['requests'], true ) ) {
				$list[ $position ]['requests'] = array_values( array_diff( $subteam['requests'], array( $who ) ) );
				$found                         = true;
			}
		}
		if ( $found ) {
			Schema::write_list( $team_id, 'subteams', $list );
			Teams::flush();
		}
	}

	/**
	 * The missions of a team that are assigned to a sub-team, newest first.
	 *
	 * @param int  $team_id     Team ID.
	 * @param int  $id          Sub-team number.
	 * @param bool $public_only Whether to leave out missions a visitor cannot read.
	 * @return array<int, \WP_Post>
	 */
	public static function missions( int $team_id, int $id, bool $public_only = false ): array {
		$missions = get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => $public_only ? 'publish' : array( 'publish', 'private' ),
				'numberposts' => 200,
				'orderby'     => 'date',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Missions of one team.
				'meta_key'    => '_pos_team',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Missions of one team.
				'meta_value'  => $team_id,
			)
		);
		return array_values(
			array_filter(
				$missions,
				static fn( $mission ) => in_array( $id, Missions::subteams( $mission->ID ), true ) && ( ! $public_only || Post_Access::can_read( $mission, 0 ) )
			)
		);
	}

	/**
	 * Impact totals for the missions assigned to a sub-team.
	 *
	 * @param int  $team_id Team ID.
	 * @param int  $id      Sub-team number.
	 * @param bool $visible Whether to sum only what a coordinator counts publicly.
	 * @return array<string, array<string, int|float>>
	 */
	public static function totals( int $team_id, int $id, bool $visible = false ): array {
		if ( Remote::is( $team_id ) ) {
			$subteam = self::get( $team_id, $id );
			return Remote::reported( $team_id, (string) ( $subteam['remote'] ?? '' ) );
		}
		return Impact::totals(
			array(
				'team'     => $team_id,
				'missions' => wp_list_pluck( self::missions( $team_id, $id ), 'ID' ),
				'visible'  => $visible,
			)
		);
	}
}
