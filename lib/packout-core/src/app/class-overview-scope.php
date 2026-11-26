<?php
/**
 * The overview of one partner or one organization.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The figures of the Overview, about one record.
 *
 * The payloads have the shape of the Platform overview's, so the page and
 * its script are the same. What is counted is the record's own: the missions
 * of its organizations, the Spots those missions took on or cleaned, the
 * Spots reported in its name, and the impact records credited to it.
 */
class Overview_Scope {

	/**
	 * A scope as it is used everywhere, or an empty array.
	 *
	 * @param mixed $scope `kind` (`team` or `partner`) and `id`.
	 * @return array{kind: string, id: int}|array{}
	 */
	public static function scope( $scope ): array {
		$kind = is_array( $scope ) ? (string) ( $scope['kind'] ?? '' ) : '';
		$id   = is_array( $scope ) ? (int) ( $scope['id'] ?? 0 ) : 0;
		if ( $id <= 0 || ! in_array( $kind, array( 'team', 'partner' ), true ) ) {
			return array();
		}
		return array(
			'kind' => $kind,
			'id'   => $id,
		);
	}

	/**
	 * The part of a snapshot's name that says whose it is.
	 *
	 * @param array<string, mixed> $scope Scope.
	 */
	public static function key( array $scope ): string {
		$scope = self::scope( $scope );
		return $scope ? $scope['kind'] . '_' . $scope['id'] : '';
	}

	/**
	 * The partner or the organization, or null.
	 *
	 * @param array<string, mixed> $scope Scope.
	 */
	public static function record( array $scope ): ?\WP_Post {
		$scope = self::scope( $scope );
		if ( ! $scope ) {
			return null;
		}
		return 'partner' === $scope['kind'] ? Partners::get( $scope['id'] ) : Teams::get( $scope['id'] );
	}

	/**
	 * The queues only the record's leads can clear, in the order the page draws them.
	 *
	 * @param array<string, mixed> $scope Scope.
	 * @return array<string, string>
	 */
	public static function queues( array $scope ): array {
		$queues = array(
			'requests'   => __( 'Requests to join', 'packout-core' ),
			'signups'    => __( 'Sign-ups to answer', 'packout-core' ),
			'impact'     => __( 'Unverified impact records', 'packout-core' ),
			'unrecorded' => __( 'Cleaned Spots without impact records', 'packout-core' ),
			'overdue'    => __( 'Missions past their window', 'packout-core' ),
			'adoptions'  => __( 'Adoption requests to answer', 'packout-core' ),
		);
		if ( 'partner' === ( $scope['kind'] ?? '' ) ) {
			$queues['claims'] = __( 'Organizations asking to join', 'packout-core' );
		}
		return $queues;
	}

	/**
	 * The scopes a changed record belongs to: its organization, and the partner of that organization.
	 *
	 * @param int $post_id A Spot, a mission, a campaign, an impact record, an organization, or a partner.
	 * @return array<int, array{kind: string, id: int}>
	 */
	public static function scopes_of( int $post_id ): array {
		global $wpdb;
		$teams = array();
		switch ( get_post_type( $post_id ) ) {
			case Schema::TEAM:
				$teams[] = $post_id;
				break;
			case Schema::MISSION:
				$teams[] = Missions::team( $post_id );
				break;
			case Schema::CAMPAIGN:
				$teams[] = Campaigns::team( $post_id );
				break;
			case Schema::IMPACT:
				$teams[] = (int) Impact::field( $post_id, 'team', '0' );
				$spot    = (int) Impact::field( $post_id, 'spot', '0' );
				// A later visit is counted where the Spot was cleaned.
				$teams[] = $spot > 0 ? Missions::team( (int) Spots::field( $spot, 'cleaned_mission', '0' ) ) : 0;
				break;
			case Schema::SPOT:
				$teams[] = (int) Spots::field( $post_id, 'credit_team', '0' );
				$teams[] = Missions::team( (int) Spots::field( $post_id, 'cleaned_mission', '0' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The missions one Spot is on.
				$on = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s WHERE pm.meta_key = %s AND pm.meta_value = %s", Schema::MISSION, '_pos_spot', (string) $post_id ) );
				foreach ( (array) $on as $mission ) {
					$teams[] = Missions::team( (int) $mission );
				}
				break;
			case Schema::PARTNER:
				return array(
					array(
						'kind' => 'partner',
						'id'   => $post_id,
					),
				);
		}//end switch
		$scopes = array();
		foreach ( array_unique( array_filter( array_map( 'intval', $teams ) ) ) as $team ) {
			$scopes[ 'team_' . $team ] = array(
				'kind' => 'team',
				'id'   => $team,
			);
			$partner                   = Partners::of_team( $team );
			if ( $partner > 0 ) {
				$scopes[ 'partner_' . $partner ] = array(
					'kind' => 'partner',
					'id'   => $partner,
				);
			}
		}
		return array_values( $scopes );
	}

	/**
	 * The missions of a list of organizations.
	 *
	 * @param array<int, int> $teams Organization IDs.
	 * @return array<int, int>
	 */
	public static function mission_ids( array $teams ): array {
		$teams = array_values( array_filter( array_map( 'intval', $teams ) ) );
		if ( ! $teams ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'   => Schema::MISSION,
					'post_status' => array( 'publish', 'private' ),
					'numberposts' => -1,
					'fields'      => 'ids',
					'orderby'     => 'ID',
					'order'       => 'ASC',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The missions of a record's organizations.
					'meta_query'  => array(
						array(
							'key'     => '_pos_team',
							'value'   => $teams,
							'compare' => 'IN',
						),
					),
				)
			)
		);
	}

	/**
	 * The Spots of a list of organizations: on one of their missions, cleaned by one, or reported in their name.
	 *
	 * @param array<int, int> $teams Organization IDs.
	 * @return array<int, int>
	 */
	public static function spot_ids( array $teams ): array {
		return self::sets( array_values( array_filter( array_map( 'intval', $teams ) ) ) )['spots'];
	}

	/**
	 * Current queues, rows that move an outcome, stages, and the foot of the page.
	 *
	 * @param array<string, mixed> $scope Scope.
	 * @return array<string, mixed>
	 */
	public static function now( array $scope ): array {
		$scope   = self::scope( $scope );
		$teams   = Overview::scope_ids( $scope );
		$sets    = self::sets( $teams );
		$waiting = array();
		foreach ( array_keys( self::queues( $scope ) ) as $key ) {
			$waiting[ $key ] = self::aged( self::queue_row( $key, $scope, $sets ) );
		}

		$counts = array(
			'planned'     => 0,
			'in_progress' => 0,
		);
		foreach ( $sets['missions'] as $mission ) {
			$status = Missions::status( $mission );
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}
		$stages = array_fill_keys( array_keys( Schema::statuses() ), 0 );
		$shown  = 0;
		foreach ( $sets['spots'] as $spot ) {
			if ( 'publish' !== get_post_status( $spot ) ) {
				continue;
			}
			$status = Spots::field( $spot, 'status', 'spotted' );
			++$stages[ isset( $stages[ $status ] ) ? $status : 'spotted' ];
			++$shown;
		}

		return array(
			'scope'       => $scope,
			'empty'       => array() === $teams,
			'waiting'     => $waiting,
			'levers'      => array(
				'a' => array(
					'planned' => self::aged( self::planned_row( $sets ) ),
					'watch'   => self::aged( self::watch_row( $sets ) ),
					'overdue' => $waiting['overdue'],
				),
				'b' => array(
					'due' => self::aged( self::due_row( $sets ) ),
				),
				'c' => array(
					'dormant'     => self::aged( self::dormant_row( $scope, $teams ) ),
					'invitations' => array(
						'count' => self::lapsed( $scope, $teams ),
					),
				),
				'd' => array(
					'impact'     => $waiting['impact'],
					'unrecorded' => $waiting['unrecorded'],
				),
			),
			'stages'      => $stages,
			'stage_total' => $shown,
			'missions'    => array(
				'count'       => $counts['planned'] + $counts['in_progress'],
				'planned'     => $counts['planned'],
				'in_progress' => $counts['in_progress'],
			),
			'platform'    => self::foot( $scope, $teams, $sets, $counts['planned'] + $counts['in_progress'] ),
			'computed_at' => gmdate( 'c' ),
		);
	}

	/**
	 * Outcome figures for a day window and the window before it.
	 *
	 * @param int                  $days  7, 30, 90, or 365.
	 * @param array<string, mixed> $scope Scope.
	 * @return array<string, mixed>
	 */
	public static function period( int $days, array $scope ): array {
		$scope    = self::scope( $scope );
		$days     = Overview::window( $days );
		$bounds   = Overview::bounds( $days );
		$teams    = Overview::scope_ids( $scope );
		$sets     = self::sets( $teams );
		$current  = self::figures( $bounds['from'], $bounds['to'], $scope, $sets );
		$previous = self::figures( $bounds['previous_from'], $bounds['previous_to'], $scope, $sets );
		$chart    = self::series( $days, $sets );
		return array(
			'scope'         => $scope,
			'days'          => $days,
			'from'          => $bounds['from'],
			'to'            => $bounds['to'],
			'previous_from' => $bounds['previous_from'],
			'previous_to'   => $bounds['previous_to'],
			'a'             => array(
				'current'  => $current['a'],
				'previous' => $previous['a'],
				'reading'  => self::reading_a( $current['a'] ),
			),
			'b'             => array(
				'current'  => $current['b'],
				'previous' => $previous['b'],
				'reading'  => Overview::reading( 'b', $current['b'] ),
			),
			'c'             => array(
				'current'  => $current['c'],
				'previous' => $previous['c'],
				'reading'  => 'partner' === ( $scope['kind'] ?? '' ) ? Overview::reading( 'c', $current['c'] ) : self::reading_c( $current['c'] ),
			),
			'd'             => array(
				'current'  => $current['d'],
				'previous' => $previous['d'],
				'reading'  => Overview::reading( 'd', $current['d'] ),
			),
			'e'             => array(
				'current'  => $current['e'],
				'previous' => $previous['e'],
				'reading'  => self::reading_e( $current['e'] ),
			),
			'series'        => $chart['series'],
			'bucket'        => $chart['bucket'],
			'reports'       => __( 'Spots added to missions', 'packout-core' ),
			'cleanups'      => __( 'Cleanups documented', 'packout-core' ),
			'computed_at'   => gmdate( 'c' ),
		);
	}

	/**
	 * What the queries of a scope are asked about.
	 *
	 * @param array<int, int> $teams Organization IDs.
	 * @return array{teams: array<int, int>, missions: array<int, int>, joined: array<int, array{mission: int, spot: int, day: string}>, cleaned: array<int, int>, spots: array<int, int>, members: array<int, int>}
	 */
	private static function sets( array $teams ): array {
		global $wpdb;
		$sets = array(
			'teams'    => $teams,
			'missions' => array(),
			// One row for each Spot on each mission, with the day it joined.
			'joined'   => array(),
			// The Spots one of its missions cleaned, each with that mission.
			'cleaned'  => array(),
			'spots'    => array(),
			'members'  => array(),
		);
		if ( ! $teams ) {
			return $sets;
		}
		$sets['missions'] = self::mission_ids( $teams );
		$work             = self::on_missions( $sets['missions'] );
		$sets['joined']   = $work['joined'];
		$sets['cleaned']  = $work['cleaned'];
		$spots            = array_fill_keys( $work['spots'], true );
		$in               = implode( ',', array_fill( 0, count( $teams ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The Spots reported in a record's name. $in is placeholders.
		$credited = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s WHERE pm.meta_key = %s AND pm.meta_value IN ($in)", array_merge( array( Schema::SPOT, '_pos_credit_team' ), array_map( 'strval', $teams ) ) ) );
		foreach ( (array) $credited as $spot ) {
			$spots[ (int) $spot ] = true;
		}
		$sets['spots'] = array_keys( $spots );
		sort( $sets['spots'] );
		_prime_post_caches( $sets['spots'], false, true );

		$members = array();
		foreach ( $teams as $team ) {
			foreach ( Teams::people( $team ) as $person ) {
				$members[ $person ] = true;
			}
		}
		$sets['members'] = array_keys( $members );
		return $sets;
	}

	/**
	 * The Spots a list of missions took on, and the ones they cleaned.
	 *
	 * @param array<int, int> $missions Mission IDs.
	 * @return array{joined: array<int, array{mission: int, spot: int, day: string}>, cleaned: array<int, int>, spots: array<int, int>}
	 */
	private static function on_missions( array $missions ): array {
		global $wpdb;
		$work = array(
			'joined'  => array(),
			'cleaned' => array(),
			'spots'   => array(),
		);
		if ( ! $missions ) {
			return $work;
		}
		update_meta_cache( 'post', $missions );
		$spots = array();
		foreach ( $missions as $mission ) {
			foreach ( Missions::joined( $mission ) as $spot => $day ) {
				$work['joined'][]     = array(
					'mission' => $mission,
					'spot'    => (int) $spot,
					'day'     => $day,
				);
				$spots[ (int) $spot ] = true;
			}
		}
		$in = implode( ',', array_fill( 0, count( $missions ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The Spots a record's missions cleaned. $in is placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id AS spot, pm.meta_value AS mission FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s WHERE pm.meta_key = %s AND pm.meta_value IN ($in)", array_merge( array( Schema::SPOT, '_pos_cleaned_mission' ), array_map( 'strval', $missions ) ) ) );
		foreach ( (array) $rows as $row ) {
			$work['cleaned'][ (int) $row->spot ] = (int) $row->mission;
			$spots[ (int) $row->spot ]           = true;
		}
		$work['spots'] = array_keys( $spots );
		return $work;
	}

	/**
	 * How many Spots joined missions in a range, how many those missions cleaned in it, and the median days between.
	 *
	 * @param array<int, array{mission: int, spot: int, day: string}> $joined  One row for each Spot on each mission.
	 * @param array<int, int>                                         $cleaned The mission that cleaned each Spot, by Spot.
	 * @param string                                                  $from    Y-m-d.
	 * @param string                                                  $to      Y-m-d.
	 * @return array{reported: int, cleaned: int, median_days: int|null}
	 */
	private static function action( array $joined, array $cleaned, string $from, string $to ): array {
		$within = static fn( string $day ): bool => '' !== $day && $day >= $from && $day <= $to;
		$took   = array();
		$days   = array();
		foreach ( $joined as $row ) {
			$days[ $row['mission'] ][ $row['spot'] ] = $row['day'];
			if ( $within( $row['day'] ) ) {
				$took[ $row['spot'] ] = true;
			}
		}
		$count = 0;
		$waits = array();
		foreach ( $cleaned as $spot => $mission ) {
			$day = substr( Spots::field( $spot, 'cleaned_at' ), 0, 10 );
			if ( ! $within( $day ) ) {
				continue;
			}
			++$count;
			if ( isset( $days[ $mission ][ $spot ] ) ) {
				$waits[] = max( 0, (int) round( ( self::stamp( $day ) - self::stamp( $days[ $mission ][ $spot ] ) ) / DAY_IN_SECONDS ) );
			}
		}
		sort( $waits, SORT_NUMERIC );
		$median = null;
		if ( $waits ) {
			$mid    = intdiv( count( $waits ), 2 );
			$median = 0 !== count( $waits ) % 2 ? $waits[ $mid ] : (int) round( ( $waits[ $mid - 1 ] + $waits[ $mid ] ) / 2 );
		}
		return array(
			'reported'    => count( $took ),
			'cleaned'     => $count,
			'median_days' => $median,
		);
	}

	/**
	 * What a list of missions took on in a range, for the report of a program or a campaign.
	 *
	 * @param array<int, int> $missions Mission IDs.
	 * @param string          $from     Y-m-d.
	 * @param string          $to       Y-m-d.
	 * @return array{reported: int, cleaned: int, median_days: int|null, open: int} `open` is the Spots on them that are not cleaned now.
	 */
	public static function taken_on( array $missions, string $from, string $to ): array {
		$missions = array_values( array_filter( array_map( 'intval', $missions ) ) );
		$work     = self::on_missions( $missions );
		_prime_post_caches( $work['spots'], false, true );
		$open = array();
		foreach ( $work['joined'] as $row ) {
			if ( 'called_off' !== Missions::status( $row['mission'] ) && 'cleaned' !== Spots::field( $row['spot'], 'status', 'spotted' ) ) {
				$open[ $row['spot'] ] = true;
			}
		}
		return self::action( $work['joined'], $work['cleaned'], $from, $to ) + array( 'open' => count( $open ) );
	}

	/**
	 * A count, the time its oldest item began to wait, that item, and the organization it belongs to.
	 *
	 * @param array<int, array{at: string, id: int, team?: int}> $items What waits.
	 * @return array{count: int, since: string, oldest_id: int, team: int}
	 */
	private static function tally( array $items ): array {
		$oldest = null;
		foreach ( $items as $item ) {
			if ( null === $oldest || ( '' !== $item['at'] && ( '' === $oldest['at'] || self::stamp( $item['at'] ) < self::stamp( $oldest['at'] ) ) ) ) {
				$oldest = $item;
			}
		}
		return array(
			'count'     => count( $items ),
			'since'     => $oldest ? $oldest['at'] : '',
			'oldest_id' => $oldest ? (int) $oldest['id'] : 0,
			'team'      => $oldest ? (int) ( $oldest['team'] ?? 0 ) : 0,
		);
	}

	/**
	 * A stored time as seconds. Times are kept as GMT, in ISO or MySQL form, or as a day.
	 *
	 * @param string $when The stored time.
	 */
	private static function stamp( string $when ): int {
		$when = trim( $when );
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $when ) ) {
			$when .= ' UTC';
		}
		$time = strtotime( $when );
		return false === $time ? PHP_INT_MAX : $time;
	}

	/**
	 * A tally with its age, as the page reads it.
	 *
	 * @param array{count: int, since: string, oldest_id: int, team: int} $row Tally.
	 * @return array<string, mixed>
	 */
	private static function aged( array $row ): array {
		$age = $row['count'] > 0 && '' !== $row['since']
			? Overview::age( $row['since'] )
			: array(
				'age'   => '',
				'stale' => false,
				'days'  => 0,
			);
		return array(
			'count'     => $row['count'],
			'age'       => $age['age'],
			'stale'     => $age['stale'],
			'days'      => $age['days'],
			'oldest_id' => $row['oldest_id'],
			'team'      => $row['team'],
		);
	}

	/**
	 * One queue.
	 *
	 * @param string               $key   Queue key.
	 * @param array<string, mixed> $scope Scope.
	 * @param array<string, mixed> $sets  From sets().
	 * @return array{count: int, since: string, oldest_id: int, team: int}
	 */
	private static function queue_row( string $key, array $scope, array $sets ): array {
		$items = array();
		$today = wp_date( 'Y-m-d' );
		switch ( $key ) {
			case 'requests':
				foreach ( $sets['teams'] as $team ) {
					// A request that came with an event sign-up is counted there.
					foreach ( array_diff( Teams::requests( $team ), array_keys( Signups::waiting( $team ) ) ) as $person ) {
						$items[] = array(
							'at'   => '',
							'id'   => (int) $person,
							'team' => $team,
						);
					}
				}
				break;
			case 'signups':
				foreach ( $sets['teams'] as $team ) {
					foreach ( array_keys( Signups::waiting( $team ) ) as $person ) {
						$items[] = array(
							'at'   => '',
							'id'   => (int) $person,
							'team' => $team,
						);
					}
				}
				break;
			case 'impact':
				$found = $sets['teams'] ? Impact::found(
					array(
						'teams'        => $sets['teams'],
						'verification' => 'unverified',
					)
				) : array();
				foreach ( $found as $record ) {
					$items[] = array(
						'at'   => Impact::field( $record->ID, 'unverified_at', (string) $record->post_date_gmt ),
						'id'   => $record->ID,
						'team' => (int) Impact::field( $record->ID, 'team', '0' ),
					);
				}
				break;
			case 'unrecorded':
				foreach ( self::unrecorded( $sets ) as $spot ) {
					$items[] = array(
						'at' => Spots::field( $spot, 'unrecorded_since', Spots::field( $spot, 'cleaned_at' ) ),
						'id' => $spot,
					);
				}
				break;
			case 'overdue':
				foreach ( $sets['missions'] as $mission ) {
					$end = Missions::field( $mission, 'end' );
					if ( Missions::is_open( $mission ) && '' !== $end && $end < $today ) {
						$items[] = array(
							'at'   => $end,
							'id'   => $mission,
							'team' => Missions::team( $mission ),
						);
					}
				}
				break;
			case 'adoptions':
				foreach ( $sets['teams'] as $team ) {
					foreach ( Places::adoption_requests_for_team( $team ) as $request ) {
						if ( 'pending' === ( $request['status'] ?? '' ) ) {
							$items[] = array(
								'at'   => (string) ( $request['created'] ?? '' ),
								'id'   => (int) ( $request['place_id'] ?? 0 ),
								'team' => $team,
							);
						}
					}
				}
				break;
			case 'claims':
				foreach ( 'partner' === ( $scope['kind'] ?? '' ) ? Partners::requests( (int) $scope['id'] ) : array() as $request ) {
					if ( 'team' === $request['from'] ) {
						$items[] = array(
							'at'   => '',
							'id'   => $request['team'],
							'team' => $request['team'],
						);
					}
				}
				break;
		}//end switch
		return self::tally( $items );
	}

	/**
	 * The record's cleaned Spots that have no record of the work.
	 *
	 * @param array<string, mixed> $sets From sets().
	 * @return array<int, int>
	 */
	private static function unrecorded( array $sets ): array {
		$found = array();
		foreach ( $sets['spots'] as $spot ) {
			if ( 'cleaned' === Spots::field( $spot, 'status', 'spotted' ) && in_array( get_post_status( $spot ), Spots::POST_STATUSES, true ) && ! Impact::current( $spot ) ) {
				$found[] = $spot;
			}
		}
		return $found;
	}

	/**
	 * Planned Spots with no mission date ahead.
	 *
	 * @param array<string, mixed> $sets From sets().
	 * @return array{count: int, since: string, oldest_id: int, team: int}
	 */
	private static function planned_row( array $sets ): array {
		$today   = wp_date( 'Y-m-d' );
		$ahead   = array();
		$planned = array();
		foreach ( $sets['joined'] as $row ) {
			if ( ! Missions::is_open( $row['mission'] ) || 'planned' !== Spots::field( $row['spot'], 'status', 'spotted' ) ) {
				continue;
			}
			$start = Missions::field( $row['mission'], 'start' );
			if ( '' !== $start && $start >= $today ) {
				$ahead[ $row['spot'] ] = true;
			}
			$planned[ $row['spot'] ] = array(
				'at' => $row['day'],
				'id' => $row['spot'],
			);
		}
		return self::tally( array_values( array_diff_key( $planned, $ahead ) ) );
	}

	/**
	 * Spots that came back and are on no open mission.
	 *
	 * @param array<string, mixed> $sets From sets().
	 * @return array{count: int, since: string, oldest_id: int, team: int}
	 */
	private static function watch_row( array $sets ): array {
		$items = array();
		foreach ( $sets['spots'] as $spot ) {
			if ( 'watch' === Spots::field( $spot, 'status', 'spotted' ) && 'publish' === get_post_status( $spot ) && 0 === Missions::other_open( $spot, 0 ) ) {
				$items[] = array(
					'at' => Spots::field( $spot, 'returned_at', (string) get_post_field( 'post_date', $spot ) ),
					'id' => $spot,
				);
			}
		}
		return self::tally( $items );
	}

	/**
	 * Spots the record cleaned that nobody has gone back to in 90 days.
	 *
	 * @param array<string, mixed> $sets From sets().
	 * @return array{count: int, since: string, oldest_id: int, team: int}
	 */
	private static function due_row( array $sets ): array {
		$items = array();
		foreach ( $sets['cleaned'] ? array_intersect( Overview::due_ids(), array_keys( $sets['cleaned'] ) ) : array() as $spot ) {
			$items[] = array(
				'at' => Spots::field( (int) $spot, 'cleaned_at' ),
				'id' => (int) $spot,
			);
		}
		return self::tally( $items );
	}

	/**
	 * One level down, the ones with no mission in 90 days: an organization's teams, a partner's organizations.
	 *
	 * @param array<string, mixed> $scope Scope.
	 * @param array<int, int>      $teams Organization IDs.
	 * @return array{count: int, since: string, oldest_id: int, team: int}
	 */
	private static function dormant_row( array $scope, array $teams ): array {
		$items = array();
		if ( 'partner' === ( $scope['kind'] ?? '' ) ) {
			foreach ( $teams ? array_intersect( array_map( 'intval', Overview::dormant_ids() ), $teams ) : array() as $team ) {
				$items[] = array(
					'at'   => '',
					'id'   => (int) $team,
					'team' => (int) $team,
				);
			}
			return self::tally( $items );
		}
		$idle = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0 )->sub( new \DateInterval( 'P' . Overview::IDLE_DAYS . 'D' ) )->format( 'Y-m-d' );
		foreach ( $teams as $team ) {
			foreach ( Subteams::all( $team ) as $subteam ) {
				$recent = false;
				foreach ( Subteams::missions( $team, (int) $subteam['id'] ) as $mission ) {
					$day = Missions::field( $mission->ID, 'start', substr( (string) $mission->post_date, 0, 10 ) );
					if ( $day > $idle || substr( (string) $mission->post_date, 0, 10 ) > $idle ) {
						$recent = true;
						break;
					}
				}
				if ( ! $recent ) {
					$items[] = array(
						'at'   => '',
						'id'   => (int) $subteam['id'],
						'team' => $team,
					);
				}
			}
		}
		return self::tally( $items );
	}

	/**
	 * Invitations that lapsed unused.
	 *
	 * @param array<string, mixed> $scope Scope.
	 * @param array<int, int>      $teams Organization IDs.
	 */
	private static function lapsed( array $scope, array $teams ): int {
		$count = 0;
		foreach ( $teams as $team ) {
			$count += (int) Invitations::list(
				array(
					'status' => Invitations::STATUS_EXPIRED,
					'team'   => $team,
				),
				1,
				1
			)['total'];
		}
		if ( 'partner' === ( $scope['kind'] ?? '' ) ) {
			$count += (int) Invitations::list(
				array(
					'status'  => Invitations::STATUS_EXPIRED,
					'partner' => (int) $scope['id'],
				),
				1,
				1
			)['total'];
		}
		return $count;
	}

	/**
	 * The counts at the foot of the page.
	 *
	 * @param array<string, mixed> $scope Scope.
	 * @param array<int, int>      $teams Organization IDs.
	 * @param array<string, mixed> $sets  From sets().
	 * @param int                  $open  Open missions.
	 * @return array<string, int>
	 */
	private static function foot( array $scope, array $teams, array $sets, int $open ): array {
		if ( 'partner' === ( $scope['kind'] ?? '' ) ) {
			return array(
				'organizations' => count( $teams ),
				'programs'      => count( Programs::of_partner( (int) $scope['id'] ) ),
				'members'       => count( $sets['members'] ),
				'missions'      => $open,
			);
		}
		$team = (int) ( $scope['id'] ?? 0 );
		return array(
			'teams'     => count( Subteams::all( $team ) ),
			'members'   => count( $sets['members'] ),
			'missions'  => $open,
			'campaigns' => count( Campaigns::of_team( $team ) ),
			'places'    => count( Places::of_team( $team ) ),
		);
	}

	/**
	 * Outcome figures for one inclusive date range.
	 *
	 * @param string               $from  Y-m-d.
	 * @param string               $to    Y-m-d.
	 * @param array<string, mixed> $scope Scope.
	 * @param array<string, mixed> $sets  From sets().
	 * @return array<string, array<string, mixed>>
	 */
	private static function figures( string $from, string $to, array $scope, array $sets ): array {
		$within = static fn( string $day ): bool => '' !== $day && $day >= $from && $day <= $to;

		// A. The Spots this record took on, and the ones it cleaned.
		$action = self::action( $sets['joined'], $sets['cleaned'], $from, $to );

		// B. Later visits to the Spots it cleaned.
		$visits  = array(
			Impact::STILL_CLEAR => 0,
			Impact::RETURNED    => 0,
		);
		$visited = array();
		$mine    = array_keys( $sets['cleaned'] );
		foreach ( $mine ? Impact::found( array( 'spots' => $mine ) ) : array() as $record ) {
			$kind = Impact::kind( $record->ID );
			if ( ! isset( $visits[ $kind ] ) ) {
				continue;
			}
			$visited[ (int) Impact::field( $record->ID, 'spot', '0' ) ] = true;
			if ( $within( Impact::field( $record->ID, 'date' ) ) ) {
				++$visits[ $kind ];
			}
		}
		$stock = array_filter( $mine, static fn( $spot ) => 'cleaned' === Spots::field( $spot, 'status', 'spotted' ) && 'publish' === get_post_status( $spot ) );

		// C and D. The cleanup records credited to it.
		$records    = $sets['teams'] ? Impact::found(
			array(
				'teams' => $sets['teams'],
				'kind'  => Impact::CLEANUP,
				'from'  => $from,
				'to'    => $to,
			)
		) : array();
		$partner    = 'partner' === ( $scope['kind'] ?? '' );
		$by_unit    = array();
		$by_mission = 0;
		$verified   = 0;
		$counted    = 0;
		$workers    = array();
		$shared     = 0;
		foreach ( $records as $record ) {
			$mission     = (int) Impact::field( $record->ID, 'mission', '0' );
			$by_mission += $mission > 0 ? 1 : 0;
			$verified   += Impact::verified( $record->ID ) ? 1 : 0;
			$counted    += 'publish' === $record->post_status ? 1 : 0;
			$workers[]   = (int) Impact::field( $record->ID, 'user', '0' );
			// One level down: a partner's organizations, an organization's teams.
			$units = $partner ? array( (int) Impact::field( $record->ID, 'team', '0' ) ) : ( $mission > 0 ? Missions::subteams( $mission ) : array() );
			$units = array_filter( array_map( 'intval', $units ) );
			foreach ( $units as $unit ) {
				$by_unit[ $unit ] = ( $by_unit[ $unit ] ?? 0 ) + 1;
			}
			$shared += $units ? 1 : 0;
		}
		$units_all = $partner ? count( $sets['teams'] ) : array_sum( array_map( static fn( $team ) => count( Subteams::all( $team ) ), $sets['teams'] ) );
		$people    = self::people_in( $from, $to, $sets['members'], $workers );
		$totals    = Impact::totals(
			array(
				'teams' => $sets['teams'],
				'from'  => $from,
				'to'    => $to,
			)
		);
		$now_clean = array_filter( $mine, static fn( $spot ) => 'cleaned' === Spots::field( $spot, 'status', 'spotted' ) && in_array( get_post_status( $spot ), Spots::POST_STATUSES, true ) );
		$recorded  = array_filter( $now_clean, static fn( $spot ) => null !== Impact::current( $spot ) );

		return array(
			'a' => array(
				'reported'    => $action['reported'],
				'cleaned'     => $action['cleaned'],
				'net_open'    => $action['reported'] - $action['cleaned'],
				'median_days' => $action['median_days'],
			),
			'b' => array(
				'still_clear'   => $visits[ Impact::STILL_CLEAR ],
				'returned'      => $visits[ Impact::RETURNED ],
				'visited'       => count( array_intersect( $stock, array_keys( $visited ) ) ),
				'cleaned_total' => count( $stock ),
			),
			'c' => array(
				'orgs_active'       => count( $by_unit ),
				'orgs_approved'     => $units_all,
				'largest_share'     => $by_unit ? max( $by_unit ) : 0,
				'largest_of'        => $shared,
				'by_mission'        => $by_mission,
				'by_person'         => count( $records ) - $by_mission,
				'people_active'     => $people['active'],
				'people_registered' => count( $sets['members'] ),
				'people_joined'     => $people['joined'],
			),
			'd' => array(
				'verified'             => $verified,
				'total_cleanups'       => count( $records ),
				'counted'              => $counted,
				'pounds_measured'      => (float) $totals['outputs']['pounds_measured'],
				'pounds_self_reported' => (float) $totals['outputs']['pounds_self_reported'],
				'bags_measured'        => (int) $totals['outputs']['bags_measured'],
				'bags_self_reported'   => (int) $totals['outputs']['bags_self_reported'],
				'hours_verified'       => (float) $totals['effort']['hours_verified'],
				'hours_unverified'     => (float) $totals['effort']['hours_unverified'],
				'people'               => (int) $totals['effort']['people'],
				'missions'             => (int) $totals['effort']['missions'],
				'teams'                => (int) $totals['effort']['teams'],
				'cleaned_with_record'  => count( $recorded ),
				'cleaned'              => count( $now_clean ),
				'spots_cleaned'        => (int) $totals['outputs']['spots'],
				'still_clear'          => (int) $totals['outcomes']['still_clear'],
				'returned'             => (int) $totals['outcomes']['returned'],
			),
			'e' => self::field_in( $from, $to, $sets ),
		);
	}

	/**
	 * The record's people who reported or cleaned in a range, and the ones whose account began in it.
	 *
	 * @param string          $from    Y-m-d.
	 * @param string          $to      Y-m-d.
	 * @param array<int, int> $members The record's people.
	 * @param array<int, int> $workers The people named on its cleanup records in the range.
	 * @return array{active: int, joined: int}
	 */
	private static function people_in( string $from, string $to, array $members, array $workers ): array {
		global $wpdb;
		if ( ! $members ) {
			return array(
				'active' => 0,
				'joined' => 0,
			);
		}
		$in = implode( ',', array_fill( 0, count( $members ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The record's people who reported in the range. $in is placeholders.
		$authors = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.post_author FROM {$wpdb->posts} p WHERE p.post_type = %s AND DATE(p.post_date) BETWEEN %s AND %s AND p.post_author IN ($in)", array_merge( array( Schema::SPOT, $from, $to ), $members ) ) );
		$active  = array_unique( array_merge( array_map( 'intval', (array) $authors ), array_intersect( $workers, $members ) ) );
		$joined  = 0;
		foreach ( $members as $member ) {
			$user = get_userdata( $member );
			$day  = $user ? substr( (string) get_date_from_gmt( (string) $user->user_registered ), 0, 10 ) : '';
			if ( '' !== $day && $day >= $from && $day <= $to ) {
				++$joined;
			}
		}
		return array(
			'active' => count( $active ),
			'joined' => $joined,
		);
	}

	/**
	 * How its people's reports arrived, what review did with them, and how many of its missions were worked from a phone.
	 *
	 * @param string               $from Y-m-d.
	 * @param string               $to   Y-m-d.
	 * @param array<string, mixed> $sets From sets().
	 * @return array<string, int>
	 */
	private static function field_in( string $from, string $to, array $sets ): array {
		global $wpdb;
		$out = array(
			'reports'   => 0,
			'field'     => 0,
			'form'      => 0,
			'no_pin'    => 0,
			'published' => 0,
			'private'   => 0,
			'waiting'   => 0,
			'returned'  => 0,
			'duplicate' => 0,
			'synced'    => 0,
			'missions'  => 0,
		);
		if ( $sets['members'] ) {
			$spot_in   = implode( ',', array_fill( 0, count( Spots::POST_STATUSES ), '%s' ) );
			$member_in = implode( ',', array_fill( 0, count( $sets['members'] ), '%d' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The reports of the record's people in the range. The lists are placeholders.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						COUNT(*) AS reports,
						SUM(CASE WHEN client.meta_id IS NOT NULL AND client.meta_value != '' THEN 1 ELSE 0 END) AS field,
						SUM(CASE WHEN (client.meta_id IS NULL OR client.meta_value = '') AND entry.meta_id IS NOT NULL AND entry.meta_value != '' THEN 1 ELSE 0 END) AS form,
						SUM(CASE WHEN kind.meta_id IS NULL OR kind.meta_value != %s THEN 1 ELSE 0 END) AS no_pin,
						SUM(CASE WHEN p.post_status = %s THEN 1 ELSE 0 END) AS published,
						SUM(CASE WHEN p.post_status = %s THEN 1 ELSE 0 END) AS private_count,
						SUM(CASE WHEN p.post_status = %s THEN 1 ELSE 0 END) AS waiting,
						SUM(CASE WHEN reason.meta_id IS NOT NULL AND reason.meta_value != '' THEN 1 ELSE 0 END) AS returned,
						SUM(CASE WHEN dupe.meta_id IS NOT NULL AND dupe.meta_value != '' AND dupe.meta_value != '0' THEN 1 ELSE 0 END) AS duplicate
						FROM {$wpdb->posts} p
						LEFT JOIN {$wpdb->postmeta} client ON client.post_id = p.ID AND client.meta_key = %s
						LEFT JOIN {$wpdb->postmeta} entry ON entry.post_id = p.ID AND entry.meta_key = %s
						LEFT JOIN {$wpdb->postmeta} kind ON kind.post_id = p.ID AND kind.meta_key = %s
						LEFT JOIN {$wpdb->postmeta} reason ON reason.post_id = p.ID AND reason.meta_key = %s
						LEFT JOIN {$wpdb->postmeta} dupe ON dupe.post_id = p.ID AND dupe.meta_key = %s
						WHERE p.post_type = %s AND p.post_status IN ($spot_in)
						AND DATE(p.post_date) BETWEEN %s AND %s
						AND p.post_author IN ($member_in)",
					array_merge(
						array( 'pin', 'publish', 'private', 'pending', '_pos_client_id', '_pos_entry_id', '_pos_location_kind', '_pos_return_reason', '_pos_duplicate_of', Schema::SPOT ),
						Spots::POST_STATUSES,
						array( $from, $to ),
						$sets['members']
					)
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			if ( $row ) {
				$out['reports']   = (int) $row->reports;
				$out['field']     = (int) $row->field;
				$out['form']      = (int) $row->form;
				$out['no_pin']    = (int) $row->no_pin;
				$out['published'] = (int) $row->published;
				$out['private']   = (int) $row->private_count;
				$out['waiting']   = (int) $row->waiting;
				$out['returned']  = (int) $row->returned;
				$out['duplicate'] = (int) $row->duplicate;
			}
		}//end if
		foreach ( $sets['missions'] as $mission ) {
			$status = Missions::status( $mission );
			$start  = Missions::field( $mission, 'start', substr( (string) get_post_field( 'post_date', $mission ), 0, 10 ) );
			$end    = Missions::field( $mission, 'end', $start );
			// A mission that was worked in the range: it began on or before the last day and had not ended before the first.
			if ( in_array( $status, array( 'in_progress', 'completed' ), true ) && $start <= $to && $end >= $from ) {
				++$out['missions'];
			}
			$synced = substr( Missions::field( $mission, 'synced_at' ), 0, 10 );
			if ( '' !== $synced && $synced >= $from && $synced <= $to ) {
				++$out['synced'];
			}
		}
		return $out;
	}

	/**
	 * Reading for outcome A.
	 *
	 * @param array<string, mixed> $figures Figures.
	 */
	public static function reading_a( array $figures ): string {
		$took    = (int) ( $figures['reported'] ?? 0 );
		$cleaned = (int) ( $figures['cleaned'] ?? 0 );
		$net     = (int) ( $figures['net_open'] ?? 0 );
		if ( 0 === $took && 0 === $cleaned ) {
			return __( 'No Spot joined a mission or was cleaned in this period.', 'packout-core' );
		}
		$sentence = sprintf(
			/* translators: 1: a clause such as 7 Spots joined a mission. 2: a clause such as 1 was cleaned. */
			__( '%1$s and %2$s.', 'packout-core' ),
			/* translators: %s: number of Spots added to missions. */
			sprintf( _n( '%s Spot joined a mission', '%s Spots joined a mission', $took, 'packout-core' ), number_format_i18n( $took ) ),
			/* translators: %s: number of Spots cleaned. */
			sprintf( _n( '%s was cleaned', '%s were cleaned', $cleaned, 'packout-core' ), number_format_i18n( $cleaned ) )
		);
		if ( $net > 0 ) {
			/* translators: %d: how many more Spots were taken on than cleaned. */
			return $sentence . ' ' . sprintf( __( 'The open pile grew by %d.', 'packout-core' ), $net );
		}
		return $sentence . ' ' . __( 'Cleanups kept pace with what was taken on.', 'packout-core' );
	}

	/**
	 * Reading for outcome C of an organization: its teams. It names none of them.
	 *
	 * @param array<string, mixed> $figures Figures.
	 */
	private static function reading_c( array $figures ): string {
		$teams = (int) ( $figures['orgs_active'] ?? 0 );
		$share = (int) ( $figures['largest_share'] ?? 0 );
		$of    = (int) ( $figures['largest_of'] ?? 0 );
		if ( 0 === (int) ( $figures['orgs_approved'] ?? 0 ) ) {
			return __( 'This organization has no teams, so the work is not split between them.', 'packout-core' );
		}
		if ( 0 === $teams ) {
			return __( 'No team documented a cleanup in this period.', 'packout-core' );
		}
		if ( 1 === $teams ) {
			return __( 'All cleanups by a team came from one team.', 'packout-core' );
		}
		/* translators: 1: teams, 2: largest share, 3: cleanups by a team. */
		return sprintf( __( '%1$d teams cleaned Spots; the most active did %2$d of %3$d.', 'packout-core' ), $teams, $share, $of );
	}

	/**
	 * Reading for outcome E.
	 *
	 * @param array<string, mixed> $figures Figures.
	 */
	private static function reading_e( array $figures ): string {
		$field   = (int) ( $figures['field'] ?? 0 );
		$reports = (int) ( $figures['reports'] ?? 0 );
		$synced  = (int) ( $figures['synced'] ?? 0 );
		$worked  = (int) ( $figures['missions'] ?? 0 );
		/* translators: 1: field-application reports, 2: all reports by the record's members. */
		$sentence = 0 === $reports ? __( 'None of its members reported a Spot in this period.', 'packout-core' ) : sprintf( _n( '%1$s of %2$s report by its members came from the field application.', '%1$s of %2$s reports by its members came from the field application.', $reports, 'packout-core' ), number_format_i18n( $field ), number_format_i18n( $reports ) );
		if ( $worked > 0 || $synced > 0 ) {
			/* translators: %s: number of missions. */
			$sentence .= ' ' . sprintf( _n( '%s mission had checklist progress synced from a phone.', '%s missions had checklist progress synced from a phone.', $synced, 'packout-core' ), number_format_i18n( $synced ) );
		}
		return $sentence;
	}

	/**
	 * The current half of an overview, put back together from the figures a
	 * partner's site sent. The figures are the site's. The words are written
	 * here, and nothing in them points at a record, since the records are on
	 * that site.
	 *
	 * @param array{kind: string, id: int} $scope   The scope on this site.
	 * @param array<string, mixed>         $figures What the site sent.
	 * @param string                       $at      When it arrived.
	 * @return array<string, mixed>
	 */
	public static function restore_now( array $scope, array $figures, string $at ): array {
		$stages = array_fill_keys( array_keys( Schema::statuses() ), 0 );
		foreach ( (array) ( $figures['stages'] ?? array() ) as $status => $count ) {
			if ( isset( $stages[ $status ] ) ) {
				$stages[ $status ] = max( 0, (int) $count );
			}
		}
		$waiting = array();
		foreach ( array_keys( self::queues( $scope ) ) as $key ) {
			$waiting[ $key ] = self::restore_row( (array) ( $figures['waiting'][ $key ] ?? array() ) );
		}
		$levers = (array) ( $figures['levers'] ?? array() );
		$lever  = static fn( string $group, string $key ): array => self::restore_row( (array) ( $levers[ $group ][ $key ] ?? array() ) );
		$open   = (array) ( $figures['missions'] ?? array() );
		return array(
			'scope'       => $scope,
			'reported'    => true,
			'empty'       => array() === $figures,
			'waiting'     => $waiting,
			'levers'      => array(
				'a' => array(
					'planned' => $lever( 'a', 'planned' ),
					'watch'   => $lever( 'a', 'watch' ),
					'overdue' => $lever( 'a', 'overdue' ),
				),
				'b' => array(
					'due' => $lever( 'b', 'due' ),
				),
				'c' => array(
					'dormant'     => $lever( 'c', 'dormant' ),
					'invitations' => array(
						'count' => max( 0, (int) ( $levers['c']['invitations']['count'] ?? 0 ) ),
					),
				),
				'd' => array(
					'impact'     => $lever( 'd', 'impact' ),
					'unrecorded' => $lever( 'd', 'unrecorded' ),
				),
			),
			'stages'      => $stages,
			'stage_total' => max( 0, (int) ( $figures['stage_total'] ?? 0 ) ),
			'missions'    => array(
				'count'       => max( 0, (int) ( $open['count'] ?? 0 ) ),
				'planned'     => max( 0, (int) ( $open['planned'] ?? 0 ) ),
				'in_progress' => max( 0, (int) ( $open['in_progress'] ?? 0 ) ),
			),
			'platform'    => array_map( static fn( $count ): int => max( 0, (int) $count ), array_filter( (array) ( $figures['platform'] ?? array() ), 'is_numeric' ) ),
			'computed_at' => self::restore_time( (string) ( $figures['computed_at'] ?? '' ), $at ),
		);
	}

	/**
	 * The period half of an overview, put back together from the figures a partner's site sent.
	 *
	 * @param array{kind: string, id: int} $scope   The scope on this site.
	 * @param int                          $days    The period.
	 * @param array<string, mixed>         $figures What the site sent for the period.
	 * @param string                       $at      When it arrived.
	 * @return array<string, mixed>
	 */
	public static function restore_period( array $scope, int $days, array $figures, string $at ): array {
		$days   = Overview::window( $days );
		$bounds = Overview::bounds( $days );
		$half   = static function ( string $key, string $which ) use ( $figures ): array {
			$kept = array();
			foreach ( (array) ( $figures[ $key ][ $which ] ?? array() ) as $name => $value ) {
				if ( is_int( $value ) || is_float( $value ) || null === $value ) {
					$kept[ (string) $name ] = $value;
				}
			}
			return $kept;
		};
		$series = array();
		foreach ( array_slice( (array) ( $figures['series'] ?? array() ), 0, 400 ) as $point ) {
			$day = Schema::day( (string) ( $point['day'] ?? '' ) );
			if ( '' !== $day ) {
				$series[] = array(
					'day'      => $day,
					'reports'  => max( 0, (int) ( $point['reports'] ?? 0 ) ),
					'cleanups' => max( 0, (int) ( $point['cleanups'] ?? 0 ) ),
				);
			}
		}
		$out = array(
			'scope'    => $scope,
			'reported' => true,
			'days'     => $days,
		);
		foreach ( array( 'from', 'to', 'previous_from', 'previous_to' ) as $edge ) {
			$day          = Schema::day( (string) ( $figures[ $edge ] ?? '' ) );
			$out[ $edge ] = '' !== $day ? $day : $bounds[ $edge ];
		}
		foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $key ) {
			$current = $half( $key, 'current' );
			if ( 'a' === $key ) {
				$reading = self::reading_a( $current );
			} elseif ( 'e' === $key ) {
				$reading = self::reading_e( $current );
			} elseif ( 'c' === $key && 'partner' !== $scope['kind'] ) {
				$reading = self::reading_c( $current );
			} else {
				$reading = Overview::reading( $key, $current );
			}
			$out[ $key ] = array(
				'current'  => $current,
				'previous' => $half( $key, 'previous' ),
				'reading'  => $reading,
			);
		}
		return $out + array(
			'series'      => $series,
			'bucket'      => $days < 90 ? 'day' : 'week',
			'reports'     => __( 'Spots added to missions', 'packout-core' ),
			'cleanups'    => __( 'Cleanups documented', 'packout-core' ),
			'computed_at' => self::restore_time( (string) ( $figures['computed_at'] ?? '' ), $at ),
		);
	}

	/**
	 * A row of a queue from a partner's site: how many, and how old the oldest is. Which record it is stays there.
	 *
	 * @param array<string, mixed> $row What the site sent.
	 * @return array{count: int, age: string, stale: bool, days: int, oldest_id: int, team: int}
	 */
	private static function restore_row( array $row ): array {
		$count = max( 0, (int) ( $row['count'] ?? 0 ) );
		$days  = max( 0, (int) ( $row['days'] ?? 0 ) );
		$age   = '';
		if ( $count > 0 && isset( $row['days'] ) ) {
			if ( 0 === $days ) {
				$age = __( 'Today', 'packout-core' );
			} elseif ( 1 === $days ) {
				$age = __( '1 day', 'packout-core' );
			} else {
				/* translators: %d: number of days. */
				$age = sprintf( __( '%d days', 'packout-core' ), $days );
			}
		}
		return array(
			'count'     => $count,
			'age'       => $age,
			'stale'     => $count > 0 && ! empty( $row['stale'] ),
			'days'      => $count > 0 ? $days : 0,
			'oldest_id' => 0,
			'team'      => 0,
		);
	}

	/**
	 * The time a partner's site computed its figures, or the time they arrived.
	 *
	 * @param string $theirs The time the site gave.
	 * @param string $at     When the figures arrived.
	 */
	private static function restore_time( string $theirs, string $at ): string {
		$time = strtotime( $theirs );
		// A time that is in the future is not one to show.
		if ( false !== $time && $time <= time() + 300 ) {
			return gmdate( 'c', $time );
		}
		$time = strtotime( $at );
		return false !== $time ? gmdate( 'c', $time ) : '';
	}

	/**
	 * Daily or weekly counts of Spots taken on and cleanups documented.
	 *
	 * @param int                  $days Window length.
	 * @param array<string, mixed> $sets From sets().
	 * @return array{bucket: string, series: array<int, array{day: string, reports: int, cleanups: int}>}
	 */
	private static function series( int $days, array $sets ): array {
		$today = new \DateTimeImmutable( 'now', wp_timezone() );
		$from  = $today->setTime( 0, 0 )->sub( new \DateInterval( 'P' . ( $days - 1 ) . 'D' ) );
		$out   = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$day         = $from->add( new \DateInterval( 'P' . $i . 'D' ) )->format( 'Y-m-d' );
			$out[ $day ] = array(
				'day'      => $day,
				'reports'  => 0,
				'cleanups' => 0,
			);
		}
		foreach ( $sets['joined'] as $row ) {
			if ( isset( $out[ $row['day'] ] ) ) {
				++$out[ $row['day'] ]['reports'];
			}
		}
		$records = $sets['teams'] ? Impact::found(
			array(
				'teams' => $sets['teams'],
				'kind'  => Impact::CLEANUP,
				'from'  => $from->format( 'Y-m-d' ),
				'to'    => $today->format( 'Y-m-d' ),
			)
		) : array();
		foreach ( $records as $record ) {
			$day = Impact::field( $record->ID, 'date' );
			if ( isset( $out[ $day ] ) ) {
				++$out[ $day ]['cleanups'];
			}
		}
		$daily = array_values( $out );
		if ( $days < 90 ) {
			return array(
				'bucket' => 'day',
				'series' => $daily,
			);
		}
		$weeks = array();
		foreach ( array_chunk( $daily, 7 ) as $chunk ) {
			$weeks[] = array(
				'day'      => $chunk[0]['day'],
				'reports'  => array_sum( array_column( $chunk, 'reports' ) ),
				'cleanups' => array_sum( array_column( $chunk, 'cleanups' ) ),
			);
		}
		return array(
			'bucket' => 'week',
			'series' => $weeks,
		);
	}
}
