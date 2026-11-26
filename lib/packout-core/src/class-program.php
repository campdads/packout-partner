<?php
/**
 * The program record's rules.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a program is and what may be filed under it.
 *
 * A program is a standing line of work that outlasts any one campaign. It
 * belongs to a partner, or to an organization that has no partner. This
 * class holds the rules that are the same on the hub and on a partner's own
 * site, and reads nothing from WordPress.
 */
final class Program {

	/**
	 * The post type a program is stored as.
	 */
	public const POST_TYPE = 'pos_program';

	/**
	 * The meta key on a campaign or a mission that names its program.
	 */
	public const FILED_META = '_pos_program';

	/**
	 * A program is open, or closed. A closed program keeps its page and its
	 * history and takes no new campaign or mission.
	 */
	public const STATES = array( 'open', 'closed' );

	/**
	 * The longest goal, in characters. A goal is one sentence.
	 */
	public const MAX_GOAL = 240;

	/**
	 * A stored state, made safe.
	 *
	 * @param mixed $value What was stored.
	 */
	public static function state( $value ): string {
		return 'closed' === $value ? 'closed' : 'open';
	}

	/**
	 * Who owns a program: a partner, or an organization.
	 *
	 * A program names one owner. When both are stored, the partner is the
	 * owner, which is what taking a program over leaves behind.
	 *
	 * @param int $partner The partner the program names, or zero.
	 * @param int $team    The organization the program names, or zero.
	 * @return array{kind: string, id: int} `kind` is `partner`, `team`, or empty when it has no owner.
	 */
	public static function owner( int $partner, int $team ): array {
		if ( $partner > 0 ) {
			return array(
				'kind' => 'partner',
				'id'   => $partner,
			);
		}
		if ( $team > 0 ) {
			return array(
				'kind' => 'team',
				'id'   => $team,
			);
		}
		return array(
			'kind' => '',
			'id'   => 0,
		);
	}

	/**
	 * The organizations that take part in a program.
	 *
	 * A partner's program takes the organizations it names that are still
	 * the partner's. An organization's own program has only itself.
	 *
	 * @param array{kind: string, id: int} $owner         From owner().
	 * @param array<int, mixed>            $named         The organization ids the program names.
	 * @param array<int, int>              $partner_teams The organization ids filed under the owning partner.
	 * @return array<int, int>
	 */
	public static function taking_part( array $owner, array $named, array $partner_teams = array() ): array {
		if ( 'team' === $owner['kind'] ) {
			return array( $owner['id'] );
		}
		if ( 'partner' !== $owner['kind'] ) {
			return array();
		}
		$kept = array();
		foreach ( $named as $id ) {
			if ( ! is_int( $id ) && ! ( is_string( $id ) && ctype_digit( $id ) ) ) {
				continue;
			}
			$id = (int) $id;
			if ( $id > 0 && in_array( $id, $partner_teams, true ) && ! in_array( $id, $kept, true ) ) {
				$kept[] = $id;
			}
		}
		return $kept;
	}

	/**
	 * Why a campaign or a mission of an organization cannot be filed under a
	 * program, or an empty string when it can.
	 *
	 * @param string          $state       The program's state.
	 * @param array<int, int> $taking_part From taking_part().
	 * @param int             $team        The organization the campaign or mission belongs to.
	 * @return string Empty, `closed`, or `outside`.
	 */
	public static function refusal( string $state, array $taking_part, int $team ): string {
		if ( $team <= 0 || ! in_array( $team, $taking_part, true ) ) {
			return 'outside';
		}
		if ( 'closed' === self::state( $state ) ) {
			return 'closed';
		}
		return '';
	}

	/**
	 * The program a mission has.
	 *
	 * A mission in a campaign has that campaign's program, or none when the
	 * campaign has none. Its own field counts only when it is in no campaign.
	 *
	 * @param int $own              The program stored on the mission.
	 * @param int $campaign         The mission's campaign, or zero.
	 * @param int $campaign_program The program stored on that campaign.
	 */
	public static function of_mission( int $own, int $campaign, int $campaign_program ): int {
		if ( $campaign > 0 ) {
			return max( 0, $campaign_program );
		}
		return max( 0, $own );
	}

	/**
	 * Whether a mission's form offers its own Program field. It does not
	 * when the mission is in a campaign, which decides for it.
	 *
	 * @param int $campaign The mission's campaign, or zero.
	 */
	public static function offers_field( int $campaign ): bool {
		return $campaign <= 0;
	}

	/**
	 * A window, made safe: two dates as `Y-m-d`, either of which may be empty.
	 *
	 * A program may have a start and no end. An end before the start, or an
	 * end with no start, is dropped.
	 *
	 * @param mixed $start The first day.
	 * @param mixed $end   The last day.
	 * @return array{start: string, end: string}
	 */
	public static function window( $start, $end ): array {
		$start = self::day( $start );
		$end   = self::day( $end );
		if ( '' === $start || ( '' !== $end && $end < $start ) ) {
			$end = '';
		}
		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Whether the public may see a program's page.
	 *
	 * @param bool $is_public    The program is public.
	 * @param bool $owner_listed Its owner is listed and may be shown.
	 */
	public static function shown( bool $is_public, bool $owner_listed ): bool {
		return $is_public && $owner_listed;
	}

	/**
	 * A day as `Y-m-d`, or an empty string.
	 *
	 * @param mixed $value What was given.
	 */
	private static function day( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
			return '';
		}
		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $value : '';
	}
}
