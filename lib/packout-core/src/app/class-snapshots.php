<?php
/**
 * Snapshots: the totals a partner site sends to packout.spot.
 *
 * A snapshot is the totals of one scope on one day, cumulative to that day:
 * the partner, an organization, a team, a program, a campaign, or a mission.
 * The figures are what `Impact::totals()` gives for the scope with `visible`
 * set, so a figure this site's coordinator has not counted publicly is not
 * sent. Measured and self-reported amounts, and verified and unverified
 * hours, stay apart, as they are everywhere else.
 *
 * The snapshot of the partner and of each organization can also carry the
 * figures its overview shows here, so its overview on the hub shows the same
 * ones. Only figures are kept from them: numbers, days, and times. Every
 * sentence and every name is left out before anything is sent.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

defined( 'ABSPATH' ) || exit;

/**
 * What a partner site sends about its totals.
 */
final class Snapshots {

	/**
	 * The periods of the overview that are sent, in days.
	 */
	public const PERIODS = array( 7, 30, 90, 365 );

	/**
	 * Every snapshot of today.
	 *
	 * @param bool $overview Whether to add the overview's figures for the partner and each organization.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( bool $overview = false ): array {
		$partner = Partners::site();
		if ( $partner <= 0 ) {
			return array();
		}
		// Work on a Spot taken on from packout.spot is counted there, from the record the hub keeps. It is in no total sent.
		Impact::apart( true );
		try {
			return self::gather( $partner, $overview );
		} finally {
			Impact::apart( false );
		}
	}

	/**
	 * The snapshots, gathered.
	 *
	 * @param int  $partner  The partner this site is.
	 * @param bool $overview Whether to add the overview's figures for the partner and each organization.
	 * @return array<int, array<string, mixed>>
	 */
	private static function gather( int $partner, bool $overview ): array {
		$day   = wp_date( 'Y-m-d' );
		$items = array();
		$teams = array_values( array_filter( Partners::organization_ids( $partner ), static fn( int $team ): bool => Listings::listable( 'organization', (string) $team ) ) );

		$items[] = self::item(
			'partner',
			(string) $partner,
			$day,
			Impact::totals(
				array(
					'teams'   => $teams ? $teams : array( 0 ),
					'visible' => true,
				)
			),
			$overview ? array(
				'kind' => 'partner',
				'id'   => $partner,
			) : null
		);
		foreach ( $teams as $team ) {
			$items[] = self::item(
				'organization',
				(string) $team,
				$day,
				Impact::totals(
					array(
						'team'    => $team,
						'visible' => true,
					)
				),
				$overview ? array(
					'kind' => 'team',
					'id'   => $team,
				) : null
			);
			foreach ( Subteams::listed( $team ) as $subteam ) {
				$totals = Subteams::totals( $team, (int) $subteam['id'], true );
				if ( (int) $totals['records']['cleanups'] > 0 ) {
					$items[] = self::item( 'team', $team . '-' . (int) $subteam['id'], $day, $totals );
				}
			}
		}//end foreach

		// The rest are sent when they have something counted: a program, a campaign, or a mission with nothing yet has no figures to show.
		foreach ( Listings::all() as $entry ) {
			list( $kind, $remote_id ) = $entry;
			$id                       = (int) $remote_id;
			if ( 'program' === $kind ) {
				$totals = Programs::totals( $id, array( 'visible' => true ) );
			} elseif ( 'campaign' === $kind ) {
				$totals = Campaigns::totals( $id, true );
			} elseif ( 'mission' === $kind ) {
				$totals = Impact::totals(
					array(
						'missions' => array( $id ),
						'visible'  => true,
					)
				);
			} else {
				continue;
			}
			if ( (int) $totals['records']['cleanups'] > 0 ) {
				$items[] = self::item( $kind, $remote_id, $day, $totals );
			}
		}//end foreach

		// What this site did in a joint campaign another organization hosts, under the hub's number for the campaign. It is sent after the site has left too: what was done stays.
		foreach ( Joint::copies() as $copy ) {
			$totals = Campaigns::totals( $copy, true );
			if ( (int) $totals['records']['cleanups'] > 0 ) {
				$items[] = self::item( 'campaign', 'hub:' . Joint::number( $copy ), $day, $totals );
			}
		}
		return $items;
	}

	/**
	 * One snapshot.
	 *
	 * @param string                                  $scope     The scope.
	 * @param string                                  $remote_id Which one.
	 * @param string                                  $day       The day, in this site's zone.
	 * @param array<string, array<string, int|float>> $totals    From `Impact::totals()`.
	 * @param array<string, mixed>|null               $overview  The scope of the overview to add, or null.
	 * @return array<string, mixed>
	 */
	public static function item( string $scope, string $remote_id, string $day, array $totals, ?array $overview = null ): array {
		$cleanups = (int) $totals['records']['cleanups'];
		$item     = array(
			'scope'     => $scope,
			'remote_id' => $remote_id,
			'day'       => $day,
			'effort'    => array(
				'hours_verified'       => (float) $totals['effort']['hours_verified'],
				'hours_unverified'     => (float) $totals['effort']['hours_unverified'],
				'people'               => (int) $totals['effort']['people'],
				'missions_completed'   => (int) $totals['effort']['missions'],
				'organizations_active' => (int) $totals['effort']['teams'],
			),
			'outputs'   => array(
				'spots_cleaned'        => (int) $totals['outputs']['spots'],
				'bags_measured'        => (float) $totals['outputs']['bags_measured'],
				'bags_self_reported'   => (float) $totals['outputs']['bags_self_reported'],
				'pounds_measured'      => (float) $totals['outputs']['pounds_measured'],
				'pounds_self_reported' => (float) $totals['outputs']['pounds_self_reported'],
			),
			'outcomes'  => array(
				'still_clear' => (int) $totals['outcomes']['still_clear'],
				'returned'    => (int) $totals['outcomes']['returned'],
			),
			// Every record in these totals is one this site counts publicly.
			'records'   => array(
				'total'    => $cleanups,
				'verified' => min( $cleanups, (int) ( $totals['records']['verified'] ?? 0 ) ),
				'counted'  => $cleanups,
			),
		);
		if ( null !== $overview ) {
			$periods = array();
			foreach ( self::PERIODS as $days ) {
				$periods[ (string) $days ] = self::figures( Overview::period( $days, $overview ) );
			}
			$item['overview'] = array(
				'now'     => self::figures( Overview::now( $overview ) ),
				'periods' => $periods,
			);
		}
		return $item;
	}

	/**
	 * Only the figures of a nested set of values: numbers, true or false,
	 * nothing, and days and times, under short plain keys. A sentence, a name,
	 * a label, or an address is dropped, and so is a key that is not a plain word.
	 *
	 * @param mixed $value The values.
	 * @param int   $depth How deep this value is.
	 * @return mixed
	 */
	public static function figures( $value, int $depth = 0 ) {
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return is_float( $value ) && ( is_nan( $value ) || is_infinite( $value ) ) ? null : $value;
		}
		if ( is_string( $value ) ) {
			return 1 === preg_match( '/^[0-9T:+Z. -]{1,40}$/', $value ) ? $value : null;
		}
		if ( ! is_array( $value ) || $depth >= 8 ) {
			return null;
		}
		$list = array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
		$kept = array();
		foreach ( $value as $key => $item ) {
			if ( $list ) {
				if ( count( $kept ) >= 400 ) {
					break;
				}
				$kept[] = self::figures( $item, $depth + 1 );
				continue;
			}
			$key = (string) $key;
			if ( 1 !== preg_match( '/^[a-z0-9_]{1,40}$/', $key ) || count( $kept ) >= 80 ) {
				continue;
			}
			$inner = self::figures( $item, $depth + 1 );
			// A label or a sentence that was dropped leaves no empty key behind.
			if ( null === $inner && is_string( $item ) ) {
				continue;
			}
			$kept[ $key ] = $inner;
		}
		return $kept;
	}
}
