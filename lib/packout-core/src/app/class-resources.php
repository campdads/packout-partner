<?php
/**
 * Team resources.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A resource is something a team shares: a master agreement, a gear list, a
 * route file, a safety brief.
 *
 * It is a title with a link or a file, a note, and a level. A team resource
 * is for the whole team and all its missions. A sub-team resource is for one
 * sub-team. A mission resource is for one mission. All of them are kept on
 * the team record.
 *
 * A level decides who is shown the listing. A file's address is not secret,
 * so a level hides the listing and does not protect the file.
 */
class Resources {

	private const LIMIT = 120;

	/**
	 * Who a team or mission resource is listed for.
	 *
	 * @return array<string, string>
	 */
	public static function audiences(): array {
		return array(
			'members' => __( 'The people it is shared with', 'packout-core' ),
			'public'  => __( 'Everyone, on the public page', 'packout-core' ),
		);
	}

	/**
	 * The levels a resource can be shared at.
	 *
	 * @return array<string, string>
	 */
	public static function levels(): array {
		return array(
			'team'    => __( 'The whole organization', 'packout-core' ),
			'subteam' => __( 'One team', 'packout-core' ),
			'mission' => __( 'One mission', 'packout-core' ),
		);
	}

	/**
	 * Every resource a team holds, by title.
	 *
	 * A resource whose sub-team is gone counts as a team resource. One whose
	 * mission is gone keeps its level and is marked `orphan`, and only the
	 * team's leads are shown it.
	 *
	 * @param int $team_id Team ID.
	 * @return array<int, array{id: int, title: string, url: string, file: int, note: string, audience: string, link: string, level: string, subteam: int, mission: int, orphan: bool}>
	 */
	public static function all( int $team_id ): array {
		$list = array();
		foreach ( Schema::stored_list( $team_id, 'resources' ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$level   = in_array( $row['level'] ?? '', array( 'subteam', 'mission' ), true ) ? (string) $row['level'] : 'team';
			$subteam = 'subteam' === $level ? (int) ( $row['subteam'] ?? 0 ) : 0;
			$mission = 'mission' === $level ? (int) ( $row['mission'] ?? 0 ) : 0;
			if ( 'subteam' === $level && ! Subteams::get( $team_id, $subteam ) ) {
				$level   = 'team';
				$subteam = 0;
			}
			$orphan   = 'mission' === $level && ( ! Missions::get( $mission ) || Missions::team( $mission ) !== $team_id );
			$audience = 'public' === ( $row['audience'] ?? '' ) && 'subteam' !== $level ? 'public' : 'members';
			$file     = (int) ( $row['file'] ?? 0 );
			$url      = (string) ( $row['url'] ?? '' );
			$list[]   = array(
				'id'       => (int) $row['id'],
				'title'    => (string) ( $row['title'] ?? '' ),
				'url'      => $url,
				'file'     => $file,
				'note'     => (string) ( $row['note'] ?? '' ),
				'audience' => $audience,
				'link'     => $file > 0 ? (string) wp_get_attachment_url( $file ) : $url,
				'level'    => $level,
				'subteam'  => $subteam,
				'mission'  => $mission,
				'orphan'   => $orphan,
			);
		}//end foreach
		usort( $list, static fn( $a, $b ) => strcasecmp( $a['title'], $b['title'] ) );
		return $list;
	}

	/**
	 * The resources of the whole team.
	 *
	 * @param int  $team_id     Team ID.
	 * @param bool $public_only Only what is marked public, for the team's public page.
	 * @return array<int, array<string, mixed>>
	 */
	public static function of_team( int $team_id, bool $public_only = false ): array {
		return array_values(
			array_filter(
				self::all( $team_id ),
				static fn( $row ) => 'team' === $row['level'] && ( ! $public_only || 'public' === $row['audience'] )
			)
		);
	}

	/**
	 * Whether a person is shown a resource in the workspace.
	 *
	 * @param int                  $team_id Team ID.
	 * @param array<string, mixed> $row     From all().
	 * @param int                  $user_id User ID.
	 */
	public static function shown_to( int $team_id, array $row, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( Partners::get( $team_id ) ) {
			// A partner's resources are for every person of its organizations.
			return Partners::can_run( $team_id, $user_id ) || in_array( $user_id, Partners::people( $team_id ), true );
		}
		if ( Teams::can_run( $team_id, $user_id ) ) {
			return true;
		}
		if ( ! Teams::includes( $team_id, $user_id ) ) {
			return false;
		}
		if ( 'subteam' === $row['level'] ) {
			return in_array( (int) $row['subteam'], Subteams::of_user( $team_id, $user_id ), true );
		}
		if ( 'mission' === $row['level'] ) {
			return ! $row['orphan'] && Missions::can_work( (int) $row['mission'], $user_id );
		}
		return true;
	}

	/**
	 * The resources a person is shown on a team.
	 *
	 * @param int $team_id Team ID.
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_user( int $team_id, int $user_id ): array {
		return array_values( array_filter( self::all( $team_id ), static fn( $row ) => self::shown_to( $team_id, $row, $user_id ) ) );
	}

	/**
	 * Every resource that applies to a mission: the team's, those of the
	 * sub-teams it is assigned to, and its own. For the mission's crew.
	 *
	 * @param int $mission_id Mission ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_mission( int $mission_id ): array {
		$team     = Missions::team( $mission_id );
		$assigned = Missions::subteams( $mission_id );
		$order    = array(
			'mission' => 0,
			'subteam' => 1,
			'team'    => 2,
		);
		$list     = array_values(
			array_filter(
				self::all( $team ),
				static function ( $row ) use ( $mission_id, $assigned ) {
					if ( 'mission' === $row['level'] ) {
						return $row['mission'] === $mission_id;
					}
					return 'team' === $row['level'] || in_array( $row['subteam'], $assigned, true );
				}
			)
		);
		// The mission's own come first, then its sub-teams', then the team's. Titles are already in order.
		usort( $list, static fn( $a, $b ) => $order[ $a['level'] ] <=> $order[ $b['level'] ] );
		return $list;
	}

	/**
	 * A mission's own resources that are marked public, for its public page.
	 *
	 * @param int $mission_id Mission ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function public_of_mission( int $mission_id ): array {
		return array_values(
			array_filter(
				self::all( Missions::team( $mission_id ) ),
				static fn( $row ) => 'mission' === $row['level'] && $row['mission'] === $mission_id && 'public' === $row['audience']
			)
		);
	}

	/**
	 * Whether a person may share a resource at a level, or remove one from it.
	 *
	 * @param int    $team_id Team ID.
	 * @param string $level   team, subteam, or mission.
	 * @param int    $target  Sub-team number or mission ID. 0 for the team.
	 * @param int    $user_id User ID.
	 */
	public static function can_share( int $team_id, string $level, int $target, int $user_id ): bool {
		if ( $user_id > 0 && Partners::get( $team_id ) ) {
			// A partner has no teams or missions of its own, so its resources are all at one level.
			return 'team' === $level && Partners::can_run( $team_id, $user_id ) && Partners::allows( $team_id, 'profile' );
		}
		if ( $user_id <= 0 || ! Teams::get( $team_id ) ) {
			return false;
		}
		if ( 'subteam' === $level ) {
			return null !== Subteams::get( $team_id, $target ) && Subteams::can_run( $team_id, $target, $user_id );
		}
		if ( 'mission' === $level ) {
			if ( ! Missions::get( $target ) || Missions::team( $target ) !== $team_id ) {
				// A resource of a mission that is gone is the team's leads' to keep or remove.
				return Teams::can_run( $team_id, $user_id );
			}
			return Missions::can_manage( $target, $user_id );
		}
		return Teams::can_run( $team_id, $user_id );
	}

	/**
	 * The places a person may share a resource on a team.
	 *
	 * @param int $team_id Team ID.
	 * @param int $user_id User ID.
	 * @return array{team: bool, subteams: array<int, string>, missions: array<int, string>}
	 */
	public static function places( int $team_id, int $user_id ): array {
		if ( Partners::get( $team_id ) ) {
			return array(
				'team'     => self::can_share( $team_id, 'team', 0, $user_id ),
				'subteams' => array(),
				'missions' => array(),
			);
		}
		$places = array(
			'team'     => Teams::can_run( $team_id, $user_id ),
			'subteams' => array(),
			'missions' => array(),
		);
		foreach ( Subteams::all( $team_id ) as $subteam ) {
			if ( Subteams::can_run( $team_id, $subteam['id'], $user_id ) ) {
				$places['subteams'][ $subteam['id'] ] = $subteam['name'];
			}
		}
		$missions = get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 200,
				'orderby'     => 'date',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Missions of one team.
				'meta_key'    => '_pos_team',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Missions of one team.
				'meta_value'  => $team_id,
			)
		);
		foreach ( $missions as $mission ) {
			if ( Missions::can_manage( $mission->ID, $user_id ) ) {
				$places['missions'][ $mission->ID ] = $mission->post_title;
			}
		}
		return $places;
	}

	/**
	 * Add a resource.
	 *
	 * @param int                       $team_id Team ID.
	 * @param array<string, mixed>      $input   title, url, note, audience, level, subteam, mission.
	 * @param int                       $user_id The person saving.
	 * @param array<string, mixed>|null $file    A file from the request files.
	 * @return int|\WP_Error Resource number.
	 */
	public static function add( int $team_id, array $input, int $user_id, ?array $file = null ) {
		$partner = null !== Partners::get( $team_id );
		$level   = ! $partner && in_array( $input['level'] ?? '', array( 'subteam', 'mission' ), true ) ? (string) $input['level'] : 'team';
		$target  = 'team' === $level ? 0 : (int) ( $input[ $level ] ?? 0 );
		if ( 'subteam' === $level && Teams::get( $team_id ) && ! Subteams::get( $team_id, $target ) ) {
			return new \WP_Error( 'packout_not_found', __( 'Choose the team this resource is for.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( 'mission' === $level && Teams::get( $team_id ) && ( ! Missions::get( $target ) || Missions::team( $target ) !== $team_id ) ) {
			return new \WP_Error( 'packout_not_found', __( 'Choose the mission this resource is for.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( ! self::can_share( $team_id, $level, $target, $user_id ) ) {
			return self::refusal( $level );
		}
		$list = Schema::stored_list( $team_id, 'resources' );
		if ( count( $list ) >= self::LIMIT ) {
			return new \WP_Error( 'packout_resource_limit', __( 'This list has as many resources as it can hold.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title || mb_strlen( $title ) > 120 ) {
			return new \WP_Error( 'packout_resource_title', __( 'Give the resource a title of up to 120 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$url      = esc_url_raw( trim( (string) ( $input['url'] ?? '' ) ), array( 'http', 'https' ) );
		$attached = 0;
		if ( $file && ! empty( $file['tmp_name'] ) ) {
			if ( $partner ? ! Partners::allows( $team_id, 'images' ) : ! Teams::active( $team_id ) ) {
				return new \WP_Error( 'packout_team_pending', $partner ? __( 'A partner can add files once a coordinator has approved it. A link works now.', 'packout-core' ) : __( 'An organization can add files once a coordinator has approved it. A link works now.', 'packout-core' ), array( 'status' => 409 ) );
			}
			$attached = Photos::store( $team_id, $file, true );
			if ( is_wp_error( $attached ) ) {
				return $attached;
			}
		}
		if ( '' === $url && 0 === $attached ) {
			return new \WP_Error( 'packout_resource_link', __( 'Add a link that starts with https://, or choose a file.', 'packout-core' ), array( 'status' => 400 ) );
		}

		$id     = Schema::next_number( $team_id );
		$list[] = array(
			'id'       => $id,
			'title'    => $title,
			'url'      => $attached > 0 ? '' : $url,
			'file'     => $attached,
			'note'     => sanitize_text_field( (string) ( $input['note'] ?? '' ) ),
			// A sub-team has no public page, so its resources are never public.
			'audience' => 'public' === ( $input['audience'] ?? '' ) && 'subteam' !== $level ? 'public' : 'members',
			'level'    => $level,
			'subteam'  => 'subteam' === $level ? $target : 0,
			'mission'  => 'mission' === $level ? $target : 0,
		);
		Schema::write_list( $team_id, 'resources', $list );
		if ( 'mission' === $level ) {
			// The field app keeps a copy of each mission. Tell it this one changed.
			Missions::flush();
		}
		return $id;
	}

	/**
	 * Remove a resource and its file.
	 *
	 * @param int $team_id Team ID.
	 * @param int $id      Resource number.
	 * @param int $user_id The person removing it.
	 * @return true|\WP_Error
	 */
	public static function remove( int $team_id, int $id, int $user_id ) {
		$found = null;
		foreach ( Teams::get( $team_id ) || Partners::get( $team_id ) ? self::all( $team_id ) : array() as $row ) {
			if ( $row['id'] === $id ) {
				$found = $row;
			}
		}
		if ( ! $found ) {
			// The same answer whether the resource is missing or is someone else's.
			return self::refusal( 'team' );
		}
		if ( ! self::can_share( $team_id, $found['level'], 'subteam' === $found['level'] ? $found['subteam'] : $found['mission'], $user_id ) ) {
			return self::refusal( $found['level'] );
		}
		$keep = array();
		$file = 0;
		foreach ( Schema::stored_list( $team_id, 'resources' ) as $row ) {
			if ( ! is_array( $row ) || (int) ( $row['id'] ?? 0 ) !== $id ) {
				$keep[] = $row;
			} else {
				$file = (int) ( $row['file'] ?? 0 );
			}
		}
		// A mission that was repeated shares its files with its copies. A file goes when its last resource does.
		if ( $file > 0 && ! in_array( $file, array_map( static fn( $row ): int => is_array( $row ) ? (int) ( $row['file'] ?? 0 ) : 0, $keep ), true ) ) {
			wp_delete_attachment( $file, true );
		}
		Schema::write_list( $team_id, 'resources', $keep );
		Missions::flush();
		return true;
	}

	/**
	 * Give a mission the resources of another mission of the same organization.
	 *
	 * Each is a new resource with the same title, link or file, note, and
	 * audience. A file is shared, not copied. The list stops at its limit.
	 *
	 * @param int $team_id Organization ID.
	 * @param int $from    The mission that has the resources.
	 * @param int $to      The mission to give them to.
	 * @return int How many were given.
	 */
	public static function copy_to_mission( int $team_id, int $from, int $to ): int {
		$list  = Schema::stored_list( $team_id, 'resources' );
		$given = 0;
		foreach ( $list as $row ) {
			if ( ! is_array( $row ) || 'mission' !== ( $row['level'] ?? '' ) || (int) ( $row['mission'] ?? 0 ) !== $from || count( $list ) >= self::LIMIT ) {
				continue;
			}
			$row['id']      = Schema::next_number( $team_id );
			$row['mission'] = $to;
			$list[]         = $row;
			++$given;
		}
		if ( $given > 0 ) {
			Schema::write_list( $team_id, 'resources', $list );
		}
		return $given;
	}

	/**
	 * Turn the resources of a sub-team that is being removed into team resources.
	 *
	 * @param int $team_id Team ID.
	 * @param int $subteam Sub-team number.
	 */
	public static function release_subteam( int $team_id, int $subteam ): void {
		$list    = Schema::stored_list( $team_id, 'resources' );
		$changed = false;
		foreach ( $list as $position => $row ) {
			if ( is_array( $row ) && 'subteam' === ( $row['level'] ?? '' ) && (int) ( $row['subteam'] ?? 0 ) === $subteam ) {
				$list[ $position ]['level']   = 'team';
				$list[ $position ]['subteam'] = 0;
				$changed                      = true;
			}
		}
		if ( $changed ) {
			Schema::write_list( $team_id, 'resources', $list );
		}
	}

	/**
	 * What a person is told when a level is not theirs to share at.
	 *
	 * @param string $level team, subteam, or mission.
	 */
	private static function refusal( string $level ): \WP_Error {
		$messages = array(
			'team'    => __( 'The organization\'s leads share its resources.', 'packout-core' ),
			'subteam' => __( 'The organization\'s leads and the team\'s leads share its resources.', 'packout-core' ),
			'mission' => __( 'The people who run a mission share its resources.', 'packout-core' ),
		);
		return new \WP_Error( 'packout_forbidden', $messages[ $level ] ?? $messages['team'], array( 'status' => 403 ) );
	}

	/**
	 * Where a resource applies, in words: the sub-team or the mission. Empty for the team.
	 *
	 * @param int                  $team_id Team ID.
	 * @param array<string, mixed> $row     From all().
	 */
	public static function place( int $team_id, array $row ): string {
		if ( 'subteam' === $row['level'] ) {
			$subteam = Subteams::get( $team_id, (int) $row['subteam'] );
			return $subteam ? $subteam['name'] : '';
		}
		if ( 'mission' === $row['level'] ) {
			return $row['orphan'] ? __( 'A mission that was removed', 'packout-core' ) : (string) get_the_title( (int) $row['mission'] );
		}
		return '';
	}

	/**
	 * Resources as the field app receives them with a mission.
	 *
	 * @param int $mission_id Mission ID.
	 * @return array<int, array{title: string, link: string, note: string, file: bool, for: string}>
	 */
	public static function payload( int $mission_id ): array {
		$team = Missions::team( $mission_id );
		$rows = array();
		foreach ( self::for_mission( $mission_id ) as $row ) {
			$labels = array(
				'team'    => __( 'Organization', 'packout-core' ),
				'subteam' => self::place( $team, $row ),
				'mission' => __( 'This mission', 'packout-core' ),
			);
			$rows[] = array(
				'title' => $row['title'],
				'link'  => $row['link'],
				'note'  => $row['note'],
				'file'  => $row['file'] > 0,
				'for'   => $labels[ $row['level'] ],
			);
		}
		return $rows;
	}

	/**
	 * A list of resources for a page, or an empty string.
	 *
	 * @param array<int, array<string, mixed>> $resources From all().
	 * @param bool                             $audience  Whether to say who each one is for.
	 */
	public static function html( array $resources, bool $audience = false ): string {
		if ( ! $resources ) {
			return '';
		}
		$html = '<ul class="pos-resources">';
		foreach ( $resources as $resource ) {
			$html .= '<li><a href="' . esc_url( (string) $resource['link'] ) . '" rel="nofollow noopener">' . esc_html( (string) $resource['title'] ) . '</a>';
			if ( $resource['file'] > 0 ) {
				$html .= ' <span class="pos-resources__kind">' . esc_html__( 'File', 'packout-core' ) . '</span>';
			}
			if ( $audience ) {
				$html .= ' <span class="pos-resources__kind">' . esc_html( 'public' === $resource['audience'] ? __( 'Public', 'packout-core' ) : __( 'Members', 'packout-core' ) ) . '</span>';
			}
			if ( '' !== $resource['note'] ) {
				$html .= '<span class="pos-resources__note">' . esc_html( (string) $resource['note'] ) . '</span>';
			}
			$html .= '</li>';
		}
		return $html . '</ul>';
	}
}
