<?php
/**
 * Platform overview figures.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinator Overview: queues, outcome figures, and cached snapshots.
 */
class Overview {

	public const IDLE_DAYS  = 90;
	public const STALE_DAYS = 7;
	public const FRESH      = 60;

	private const TRANSIENT_NOW    = 'packout_overview_now';
	private const TRANSIENT_PERIOD = 'packout_overview_period_';
	private const NOW_TTL          = HOUR_IN_SECONDS;
	private const PERIOD_TTL       = HOUR_IN_SECONDS;

	/**
	 * The queue of cleaned Spots without a record, while one period snapshot is worked out.
	 *
	 * @var array{count: int, since: string, oldest_id: int}|null
	 */
	private static ?array $unrecorded = null;

	/**
	 * Invalidate the current-state snapshot when a queue can move.
	 */
	public static function register(): void {
		foreach ( self::invalidators() as $action ) {
			add_action( $action, array( self::class, 'forget' ) );
		}
		add_action( 'packout_place_adoption_requested', array( self::class, 'forget_team' ), 10, 2 );
		add_action( 'packout_place_adopted', array( self::class, 'forget_team' ), 10, 2 );
		add_action( 'packout_organization_claimed', array( self::class, 'forget_claim' ), 10, 2 );
	}

	/**
	 * Actions that change a queue or a current-state figure.
	 *
	 * @return array<int, string>
	 */
	public static function invalidators(): array {
		return array(
			'packout_spot_submitted',
			'packout_spot_published',
			'packout_spot_status_changed',
			'packout_spot_claimed',
			'packout_spot_cleared',
			'packout_impact_recorded',
			'packout_team_proposed',
			'packout_team_approved',
			'packout_team_joined',
			'packout_mission_opened',
			'packout_mission_completed',
			'packout_campaign_opened',
			'packout_event_signup',
			'packout_partner_proposed',
			'packout_partner_approved',
			'packout_partner_state_changed',
			'packout_organization_claimed',
		);
	}

	/**
	 * Drop the current-state snapshot of the platform, and the snapshots of the record a change belongs to.
	 *
	 * Every action above passes the record that changed first: a Spot, a
	 * mission, a campaign, an impact record, an organization, or a partner.
	 * The snapshots of its organization and of that organization's partner go
	 * with the platform's. Another organization's stay.
	 *
	 * @param mixed $changed The record that changed, when the action names one.
	 */
	public static function forget( $changed = 0 ): void {
		delete_transient( self::TRANSIENT_NOW );
		if ( ! is_numeric( $changed ) || (int) $changed <= 0 ) {
			return;
		}
		foreach ( Overview_Scope::scopes_of( (int) $changed ) as $scope ) {
			self::forget_scope( $scope );
		}
	}

	/**
	 * Drop the snapshots of an organization, and of its partner, after a change that names the organization second.
	 *
	 * @param mixed $changed The record that changed.
	 * @param mixed $team    Organization ID.
	 */
	public static function forget_team( $changed, $team = 0 ): void {
		unset( $changed );
		self::forget( is_numeric( $team ) ? (int) $team : 0 );
	}

	/**
	 * Drop a partner's snapshots when an organization joins or leaves it.
	 *
	 * @param mixed $team    Organization ID.
	 * @param mixed $partner Partner ID.
	 */
	public static function forget_claim( $team, $partner = 0 ): void {
		unset( $team );
		self::forget_scope(
			array(
				'kind' => 'partner',
				'id'   => is_numeric( $partner ) ? (int) $partner : 0,
			)
		);
	}

	/**
	 * Drop the snapshots of one scope.
	 *
	 * @param array<string, mixed> $scope Scope.
	 */
	public static function forget_scope( array $scope ): void {
		$key = Overview_Scope::key( $scope );
		if ( '' === $key ) {
			return;
		}
		delete_transient( self::TRANSIENT_NOW . '_' . $key );
		foreach ( self::windows() as $days ) {
			delete_transient( self::TRANSIENT_PERIOD . $key . '_' . $days );
		}
	}

	/**
	 * The name a snapshot is stored under.
	 *
	 * @param array<string, mixed> $scope Scope, or an empty array for the platform.
	 * @param int                  $days  Window, or 0 for the current-state snapshot.
	 */
	public static function snapshot_name( array $scope = array(), int $days = 0 ): string {
		$key = Overview_Scope::key( $scope );
		if ( $days <= 0 ) {
			return self::TRANSIENT_NOW . ( '' !== $key ? '_' . $key : '' );
		}
		return self::TRANSIENT_PERIOD . ( '' !== $key ? $key . '_' : '' ) . self::window( $days );
	}

	/**
	 * Whether a person may open the overview of a partner or an organization.
	 *
	 * A coordinator opens any. A partner's leads open the partner's and each
	 * of its organizations'. An organization's lead and co-leads open the
	 * organization's. The page, both routes, and the export ask here.
	 *
	 * @param string $kind `partner` or `team`.
	 * @param int    $id   The record.
	 * @param int    $user User ID.
	 */
	public static function can_open( string $kind, int $id, int $user ): bool {
		if ( $user <= 0 || $id <= 0 ) {
			return false;
		}
		if ( 'partner' === $kind ) {
			// On a partner site the partner's overview is the site's, which a coordinator opens.
			return null !== Partners::get( $id ) && ( Capabilities::can( Capabilities::MODERATE, $user ) || ( App::is_hub() && Partners::leads_partner( $id, $user ) ) );
		}
		if ( 'team' === $kind ) {
			return null !== Teams::get( $id ) && ( Capabilities::can( Capabilities::MODERATE, $user ) || Teams::leads_work( $id, $user ) );
		}
		return false;
	}

	/**
	 * The organizations of a scope: the one organization, or every organization under a partner.
	 *
	 * @param array<string, mixed> $scope `kind` (`team` or `partner`) and `id`.
	 * @return array<int, int>
	 */
	public static function scope_ids( array $scope ): array {
		$kind = (string) ( $scope['kind'] ?? '' );
		$id   = (int) ( $scope['id'] ?? 0 );
		if ( 'team' === $kind ) {
			return Teams::get( $id ) ? array( $id ) : array();
		}
		if ( 'partner' === $kind ) {
			return Partners::organization_ids( $id );
		}
		return array();
	}

	/**
	 * Current-state snapshot, computing it when missing.
	 *
	 * @param array<string, mixed> $scope A partner or an organization, or an empty array for the platform.
	 * @return array<string, mixed>
	 */
	public static function cached_now( array $scope = array() ): array {
		$reported = Remote::overview_now( $scope );
		if ( null !== $reported ) {
			return $reported;
		}
		return self::snapshot( self::snapshot_name( $scope ), static fn() => self::now( $scope ), self::NOW_TTL );
	}

	/**
	 * Period snapshot, computing it when missing.
	 *
	 * @param int                  $days  Window.
	 * @param array<string, mixed> $scope A partner or an organization, or an empty array for the platform.
	 * @return array<string, mixed>
	 */
	public static function cached_period( int $days, array $scope = array() ): array {
		$days     = self::window( $days );
		$reported = Remote::overview_period( $days, $scope );
		if ( null !== $reported ) {
			return $reported;
		}
		return self::snapshot( self::snapshot_name( $scope, $days ), static fn() => self::period( $days, $scope ), self::PERIOD_TTL );
	}

	/**
	 * Recompute and store the current-state snapshot.
	 *
	 * @param array<string, mixed> $scope A partner or an organization, or an empty array for the platform.
	 * @return array<string, mixed>
	 */
	public static function refresh_now( array $scope = array() ): array {
		$reported = Remote::overview_now( $scope );
		if ( null !== $reported ) {
			return $reported;
		}
		$data = self::now( $scope );
		set_transient( self::snapshot_name( $scope ), $data, self::NOW_TTL );
		return $data;
	}

	/**
	 * Recompute and store a period snapshot.
	 *
	 * @param int                  $days  Window.
	 * @param array<string, mixed> $scope A partner or an organization, or an empty array for the platform.
	 * @return array<string, mixed>
	 */
	public static function refresh_period( int $days, array $scope = array() ): array {
		$days     = self::window( $days );
		$reported = Remote::overview_period( $days, $scope );
		if ( null !== $reported ) {
			return $reported;
		}
		$data = self::period( $days, $scope );
		set_transient( self::snapshot_name( $scope, $days ), $data, self::PERIOD_TTL );
		return $data;
	}

	/**
	 * Whether a snapshot is young enough to skip a refresh.
	 *
	 * @param array<string, mixed> $payload Snapshot.
	 */
	public static function fresh_enough( array $payload ): bool {
		$at = strtotime( (string) ( $payload['computed_at'] ?? '' ) );
		return false !== $at && ( time() - $at ) < self::FRESH;
	}

	/**
	 * Queue keys the overview draws, in process order.
	 *
	 * @return array<string, string>
	 */
	public static function queues(): array {
		$queues = array(
			'reports'       => __( 'Spot reports to review', 'packout-core' ),
			'organizations' => __( 'Organizations to approve', 'packout-core' ),
			'partners'      => __( 'Partners to approve', 'packout-core' ),
			'cleanup'       => __( 'Cleanup reports to confirm', 'packout-core' ),
			'clearance'     => __( 'Spots needing clearance', 'packout-core' ),
			'impact'        => __( 'Unverified impact records', 'packout-core' ),
			'counted'       => __( 'Verified records not counted publicly', 'packout-core' ),
			'unrecorded'    => __( 'Cleaned Spots without impact records', 'packout-core' ),
		);
		// A partner site is one partner, and nobody approves it.
		if ( ! App::is_hub() ) {
			unset( $queues['partners'] );
		} else {
			// Totals a partner's site sent that rose far faster than usual, and wait for a coordinator before they are shown.
			$queues = array_slice( $queues, 0, 3, true ) + array( 'figures' => __( 'Partner figures held back', 'packout-core' ) ) + array_slice( $queues, 3, null, true );
		}
		return $queues;
	}

	/**
	 * Day windows the activity chart accepts.
	 *
	 * @return array<int, int>
	 */
	public static function windows(): array {
		return array( 7, 30, 90, 365 );
	}

	/**
	 * Current queues, levers, stages, and signals.
	 *
	 * @param array<string, mixed> $scope A partner or an organization, or an empty array for the platform.
	 * @return array<string, mixed>
	 */
	public static function now( array $scope = array() ): array {
		if ( Overview_Scope::scope( $scope ) ) {
			return Overview_Scope::now( $scope );
		}
		$waiting = array();
		foreach ( array_keys( self::queues() ) as $key ) {
			$waiting[ $key ] = self::queue( $key );
		}
		$setup   = self::setup();
		$missing = array();
		foreach ( $setup['rows'] as $row ) {
			if ( empty( $row['ok'] ) ) {
				$missing[] = (string) ( $row['label'] ?? '' );
			}
		}
		$regions = null;
		foreach ( $setup['rows'] as $row ) {
			if ( 'regions' === ( $row['id'] ?? '' ) ) {
				$regions = $row;
				break;
			}
		}
		$missions = self::missions();
		$stages   = self::stage_counts();
		$spots    = self::published_total();
		return array(
			'waiting'     => $waiting,
			'levers'      => array(
				'a' => array(
					'spotted'      => self::aged( self::spotted_row() ),
					'watch'        => self::aged( self::watch_row() ),
					'overdue'      => self::aged( self::overdue_row() ),
					'do_not_touch' => array(
						'count' => self::do_not_touch_count(),
					),
				),
				'b' => array(
					'due' => self::aged( self::due_row() ),
				),
				'c' => array(
					'organizations' => $waiting['organizations'],
					'dormant'       => self::aged( self::dormant_row() ),
					'invitations'   => array(
						'count' => Invitations::counts()['expired'],
					),
				),
				'd' => array(
					'impact'     => $waiting['impact'],
					'counted'    => $waiting['counted'],
					'unrecorded' => $waiting['unrecorded'],
					'clearance'  => $waiting['clearance'],
				),
				'e' => array(
					'regions' => array(
						'ok'     => $regions ? ! empty( $regions['ok'] ) : true,
						'detail' => $regions['detail'] ?? '',
					),
				),
			),
			'stages'      => $stages,
			'stage_total' => $spots,
			'missions'    => $missions,
			'platform'    => self::platform(),
			'signals'     => self::signals(),
			'setup'       => array(
				'missing'     => array_values( array_filter( $missing ) ),
				'settingsUrl' => $setup['settingsUrl'],
				'fieldAppUrl' => $setup['fieldAppUrl'],
			),
			'computed_at' => gmdate( 'c' ),
		);
	}

	/**
	 * Outcome figures for a day window and the window before it.
	 *
	 * @param int                  $days  7, 30, 90, or 365.
	 * @param array<string, mixed> $scope A partner or an organization, or an empty array for the platform.
	 * @return array<string, mixed>
	 */
	public static function period( int $days, array $scope = array() ): array {
		if ( Overview_Scope::scope( $scope ) ) {
			return Overview_Scope::period( $days, $scope );
		}
		$days   = self::window( $days );
		$bounds = self::bounds( $days );
		// The queue of cleaned Spots without a record is the same for both periods, and is asked for once.
		self::$unrecorded = self::unrecorded_queue();
		$current          = self::period_figures( $bounds['from'], $bounds['to'] );
		$previous         = self::period_figures( $bounds['previous_from'], $bounds['previous_to'] );
		self::$unrecorded = null;
		$unowned          = self::oldest_unowned();
		$chart            = self::bucketed_series( $days );
		return array(
			'days'          => $days,
			'from'          => $bounds['from'],
			'to'            => $bounds['to'],
			'previous_from' => $bounds['previous_from'],
			'previous_to'   => $bounds['previous_to'],
			'a'             => array(
				'current'  => $current['a'],
				'previous' => $previous['a'],
				'reading'  => self::reading( 'a', $current['a'], $unowned ),
			),
			'b'             => array(
				'current'  => $current['b'],
				'previous' => $previous['b'],
				'reading'  => self::reading( 'b', $current['b'] ),
			),
			'c'             => array(
				'current'  => $current['c'],
				'previous' => $previous['c'],
				'reading'  => self::reading( 'c', $current['c'] ),
			),
			'd'             => array(
				'current'  => $current['d'],
				'previous' => $previous['d'],
				'reading'  => self::reading( 'd', $current['d'] ),
			),
			'e'             => array(
				'current'  => $current['e'],
				'previous' => $previous['e'],
				'reading'  => self::reading( 'e', $current['e'] ),
			),
			'series'        => $chart['series'],
			'bucket'        => $chart['bucket'],
			'reports'       => __( 'Spot reports received', 'packout-core' ),
			'cleanups'      => __( 'Cleanups documented', 'packout-core' ),
			'computed_at'   => gmdate( 'c' ),
		);
	}

	/**
	 * One sentence that says what an outcome's figures mean.
	 *
	 * @param string               $outcome a, b, c, d, or e.
	 * @param array<string, mixed> $figures Current figures.
	 * @param array<string, mixed> $context Extra current-state facts.
	 */
	public static function reading( string $outcome, array $figures, array $context = array() ): string {
		switch ( $outcome ) {
			case 'a':
				return self::reading_a( $figures, $context );
			case 'b':
				return self::reading_b( $figures );
			case 'c':
				return self::reading_c( $figures );
			case 'd':
				return self::reading_d( $figures );
			case 'e':
				return self::reading_e( $figures );
		}
		return '';
	}

	/**
	 * Approved organizations.
	 *
	 * @return array{count: int}
	 */
	public static function organizations(): array {
		global $wpdb;
		$statuses = self::listed_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql      = "SELECT COUNT(*) FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type = %s AND p.post_status IN ($in)
			AND (m.meta_id IS NULL OR m.meta_value != %s)" . Remote::sql( 'p' );
		$count    = self::var(
			$sql,
			array_merge( array( '_pos_state', Schema::TEAM ), $statuses, array( 'pending' ) )
		);
		return array( 'count' => (int) $count );
	}

	/**
	 * People who can report.
	 *
	 * @return array{count: int}
	 */
	public static function people(): array {
		return array( 'count' => People_Screen::count() );
	}

	/**
	 * Open missions, with the planned and in-progress split.
	 *
	 * @return array{count: int, planned: int, in_progress: int}
	 */
	public static function missions(): array {
		global $wpdb;
		$statuses = self::listed_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql      = "SELECT COALESCE(NULLIF(m.meta_value, ''), 'planned') AS status, COUNT(*) AS n
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type = %s AND p.post_status IN ($in)" . Remote::sql( 'p' ) . '
			GROUP BY status';
		$rows     = self::results(
			$sql,
			array_merge( array( '_pos_status', Schema::MISSION ), $statuses )
		);
		$counts   = array(
			'planned'     => 0,
			'in_progress' => 0,
		);
		foreach ( $rows as $row ) {
			$status = (string) $row->status;
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = (int) $row->n;
			}
		}
		return array(
			'count'       => $counts['planned'] + $counts['in_progress'],
			'planned'     => $counts['planned'],
			'in_progress' => $counts['in_progress'],
		);
	}

	/**
	 * Published Spots.
	 *
	 * @return array{count: int}
	 */
	public static function spots(): array {
		return array( 'count' => self::published_total() );
	}

	/**
	 * One attention queue: the open count, age, and oldest item.
	 *
	 * @param string $key Queue key.
	 * @return array{count: int, age: string, stale: bool, oldest_id: int}
	 */
	public static function queue( string $key ): array {
		if ( ! isset( self::queues()[ $key ] ) ) {
			return array(
				'count'     => 0,
				'age'       => '',
				'stale'     => false,
				'oldest_id' => 0,
			);
		}
		return self::aged( self::queue_row( $key ) );
	}

	/**
	 * Cleaned Spots due for a return visit.
	 *
	 * @return array<int, int>
	 */
	public static function due_ids(): array {
		return self::due_id_list();
	}

	/**
	 * Open missions whose window has ended.
	 *
	 * @return array<int, int>
	 */
	public static function overdue_ids(): array {
		return self::overdue_id_list();
	}

	/**
	 * Approved organizations with no recent work.
	 *
	 * @return array<int, int>
	 */
	public static function dormant_ids(): array {
		return self::dormant_id_list();
	}

	/**
	 * Site setup rows and the two links the Help sidebar ends with.
	 *
	 * @return array{rows: array<int, array{id: string, ok: bool, tab: string, label: string, detail: string}>, settingsUrl: string, fieldAppUrl: string}
	 */
	public static function setup(): array {
		return array(
			'rows'        => self::setup_rows(),
			'settingsUrl' => admin_url( 'admin.php?page=packout-settings' ),
			'fieldAppUrl' => Field_App::url(),
		);
	}

	/**
	 * A calendar age in the site timezone.
	 *
	 * @param string $when GMT ISO, GMT MySQL, or a local MySQL datetime.
	 * @return array{age: string, stale: bool, days: int}
	 */
	public static function age( string $when ): array {
		$raw = trim( $when );
		if ( '' === $raw ) {
			return array(
				'age'   => '',
				'stale' => false,
				'days'  => 0,
			);
		}
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			$raw .= ' 00:00:00 UTC';
		} elseif ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw ) ) {
			$raw .= ' UTC';
		}
		$ts = strtotime( $raw );
		if ( false === $ts ) {
			return array(
				'age'   => '',
				'stale' => false,
				'days'  => 0,
			);
		}
		$tz    = wp_timezone();
		$start = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->setTime( 0, 0 );
		$today = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 );
		$days  = (int) $today->diff( $start )->format( '%a' );
		if ( $start > $today ) {
			$days = 0;
		}
		if ( 0 === $days ) {
			$label = __( 'Today', 'packout-core' );
		} elseif ( 1 === $days ) {
			$label = __( '1 day', 'packout-core' );
		} else {
			/* translators: %d: number of days. */
			$label = sprintf( __( '%d days', 'packout-core' ), $days );
		}
		return array(
			'age'   => $label,
			'stale' => $days >= self::STALE_DAYS,
			'days'  => $days,
		);
	}

	/**
	 * A permitted chart window.
	 *
	 * @param int $days Requested length.
	 */
	public static function window( int $days ): int {
		return in_array( $days, self::windows(), true ) ? $days : 30;
	}

	/**
	 * Read or fill a transient.
	 *
	 * @param string   $key     Transient name.
	 * @param callable $compute Builder.
	 * @param int      $ttl     Lifetime in seconds.
	 * @return array<string, mixed>
	 */
	private static function snapshot( string $key, callable $compute, int $ttl ): array {
		$stored = get_transient( $key );
		if ( is_array( $stored ) && isset( $stored['computed_at'] ) ) {
			return $stored;
		}
		$data = $compute();
		$data = is_array( $data ) ? $data : array();
		set_transient( $key, $data, $ttl );
		return $data;
	}

	/**
	 * Start and end dates for a window and the window before it.
	 *
	 * @param int $days Length.
	 * @return array{from: string, to: string, previous_from: string, previous_to: string}
	 */
	public static function bounds( int $days ): array {
		$tz    = wp_timezone();
		$today = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 );
		$from  = $today->sub( new \DateInterval( 'P' . ( $days - 1 ) . 'D' ) );
		$prev  = $from->sub( new \DateInterval( 'P1D' ) );
		$start = $prev->sub( new \DateInterval( 'P' . ( $days - 1 ) . 'D' ) );
		return array(
			'from'          => $from->format( 'Y-m-d' ),
			'to'            => $today->format( 'Y-m-d' ),
			'previous_from' => $start->format( 'Y-m-d' ),
			'previous_to'   => $prev->format( 'Y-m-d' ),
		);
	}

	/**
	 * The idle cutoff as Y-m-d in the site timezone.
	 */
	private static function idle_day(): string {
		$tz = wp_timezone();
		return ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 )->sub( new \DateInterval( 'P' . self::IDLE_DAYS . 'D' ) )->format( 'Y-m-d' );
	}

	/**
	 * Outcome figures for one inclusive date range.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<string, array<string, mixed>>
	 */
	private static function period_figures( string $from, string $to ): array {
		$reported = self::reported_in( $from, $to );
		$cleaned  = self::cleaned_in( $from, $to );
		$visits   = self::visits_in( $from, $to );
		$trust    = self::trust_in( $from, $to );
		$stock    = self::cleaned_visit_stock();
		return array(
			'a' => array(
				'reported'    => $reported,
				'cleaned'     => $cleaned,
				'net_open'    => $reported - $cleaned,
				'median_days' => self::median_days( $from, $to ),
			),
			'b' => array(
				'still_clear'   => $visits['still_clear'],
				'returned'      => $visits['returned'],
				'visited'       => $stock['visited'],
				'cleaned_total' => $stock['cleaned_total'],
			),
			'c' => self::community_in( $from, $to ),
			'd' => $trust,
			'e' => self::field_in( $from, $to ),
		);
	}

	/**
	 * Reading for outcome A.
	 *
	 * @param array<string, mixed> $figures Figures.
	 * @param array<string, mixed> $unowned Oldest unowned Spot.
	 */
	private static function reading_a( array $figures, array $unowned ): string {
		$reported = (int) ( $figures['reported'] ?? 0 );
		$cleaned  = (int) ( $figures['cleaned'] ?? 0 );
		$net      = (int) ( $figures['net_open'] ?? 0 );
		if ( 0 === $reported && 0 === $cleaned ) {
			return __( 'No reports or cleanups in this period.', 'packout-core' );
		}
		$sentence = sprintf(
			/* translators: 1: a clause such as 7 Spots were reported. 2: a clause such as 1 was cleaned. */
			__( '%1$s and %2$s.', 'packout-core' ),
			/* translators: %s: number of Spots reported. */
			sprintf( _n( '%s Spot was reported', '%s Spots were reported', $reported, 'packout-core' ), number_format_i18n( $reported ) ),
			/* translators: %s: number of Spots cleaned. */
			sprintf( _n( '%s was cleaned', '%s were cleaned', $cleaned, 'packout-core' ), number_format_i18n( $cleaned ) )
		);
		if ( $net > 0 ) {
			/* translators: %d: how many more reports than cleanups. */
			$sentence .= ' ' . sprintf( __( 'The open pile grew by %d.', 'packout-core' ), $net );
		} else {
			$sentence .= ' ' . __( 'Cleanups kept pace with reports.', 'packout-core' );
		}
		if ( ! empty( $unowned['stale'] ) && '' !== (string) ( $unowned['age'] ?? '' ) ) {
			/* translators: %s: age such as "12 days". */
			$sentence .= ' ' . sprintf( __( 'The oldest unowned Spot has waited %s.', 'packout-core' ), $unowned['age'] );
		}
		return $sentence;
	}

	/**
	 * Reading for outcome B.
	 *
	 * @param array<string, mixed> $figures Figures.
	 */
	private static function reading_b( array $figures ): string {
		$clear = (int) ( $figures['still_clear'] ?? 0 );
		$back  = (int) ( $figures['returned'] ?? 0 );
		$total = $clear + $back;
		if ( 0 === $total ) {
			return __( 'Nobody has gone back yet, so there is no evidence either way.', 'packout-core' );
		}
		$sentence = sprintf(
			/* translators: 1: a clause such as 3 later visits found the place still clear. 2: a clause such as 2 found the problem back. */
			__( '%1$s, and %2$s.', 'packout-core' ),
			/* translators: %s: number of later visits that found the place still clear. */
			sprintf( _n( '%s later visit found the place still clear', '%s later visits found the place still clear', $clear, 'packout-core' ), number_format_i18n( $clear ) ),
			/* translators: %s: number of later visits that found the problem back. */
			sprintf( __( '%s found the problem back', 'packout-core' ), number_format_i18n( $back ) )
		);
		if ( $total >= 10 ) {
			/* translators: %d: percentage that held. */
			$sentence .= ' ' . sprintf( __( '%d%% held.', 'packout-core' ), (int) round( 100 * $clear / $total ) );
		}
		return $sentence;
	}

	/**
	 * Reading for outcome C.
	 *
	 * @param array<string, mixed> $figures Figures.
	 */
	private static function reading_c( array $figures ): string {
		$orgs  = (int) ( $figures['orgs_active'] ?? 0 );
		$share = (int) ( $figures['largest_share'] ?? 0 );
		$of    = (int) ( $figures['largest_of'] ?? 0 );
		if ( 0 === $orgs ) {
			return __( 'No organization documented a cleanup in this period.', 'packout-core' );
		}
		if ( 1 === $orgs ) {
			return __( 'All organized cleanups came from one organization.', 'packout-core' );
		}
		/* translators: 1: organizations, 2: largest share, 3: organized cleanups. */
		return sprintf( __( '%1$d organizations cleaned Spots; the most active did %2$d of %3$d.', 'packout-core' ), $orgs, $share, $of );
	}

	/**
	 * Reading for outcome D.
	 *
	 * @param array<string, mixed> $figures Figures.
	 */
	private static function reading_d( array $figures ): string {
		$verified = (int) ( $figures['verified'] ?? 0 );
		$total    = (int) ( $figures['total_cleanups'] ?? 0 );
		$counted  = (int) ( $figures['counted'] ?? 0 );
		if ( 0 === $total ) {
			return __( 'No cleanup records were written in this period.', 'packout-core' );
		}
		if ( 0 === $counted ) {
			return __( 'The public Impact page shows nothing for this period.', 'packout-core' );
		}
		return sprintf(
			/* translators: 1: a clause such as 3 of 4 cleanups are verified. 2: a clause such as 2 are counted publicly. */
			__( '%1$s and %2$s.', 'packout-core' ),
			/* translators: 1: verified cleanups, 2: all cleanups. */
			sprintf( _n( '%1$s of %2$s cleanup is verified', '%1$s of %2$s cleanups are verified', $total, 'packout-core' ), number_format_i18n( $verified ), number_format_i18n( $total ) ),
			/* translators: %s: number of cleanups counted publicly. */
			sprintf( _n( '%s is counted publicly', '%s are counted publicly', $counted, 'packout-core' ), number_format_i18n( $counted ) )
		);
	}

	/**
	 * Reading for outcome E.
	 *
	 * @param array<string, mixed> $figures Figures.
	 */
	private static function reading_e( array $figures ): string {
		$field    = (int) ( $figures['field'] ?? 0 );
		$reports  = (int) ( $figures['reports'] ?? 0 );
		$returned = (int) ( $figures['returned'] ?? 0 );
		if ( 0 === $reports ) {
			return __( 'Nobody reported a Spot in this period.', 'packout-core' );
		}
		/* translators: 1: field-application reports, 2: all reports. */
		$sentence = sprintf( _n( '%1$s of %2$s report came from the field application.', '%1$s of %2$s reports came from the field application.', $reports, 'packout-core' ), number_format_i18n( $field ), number_format_i18n( $reports ) );
		if ( $returned >= 3 ) {
			/* translators: %s: returned reports. */
			$sentence .= ' ' . sprintf( __( '%s were returned to the reporter.', 'packout-core' ), number_format_i18n( $returned ) );
		}
		return $sentence;
	}

	/**
	 * Age and oldest ID from a tally row.
	 *
	 * @param array{count: int, since: string, oldest_id?: int} $row Tally.
	 * @return array{count: int, age: string, stale: bool, days: int, oldest_id: int}
	 */
	private static function aged( array $row ): array {
		$count = (int) ( $row['count'] ?? 0 );
		$since = (string) ( $row['since'] ?? '' );
		$id    = (int) ( $row['oldest_id'] ?? 0 );
		if ( 0 === $count || '' === $since ) {
			return array(
				'count'     => $count,
				'age'       => '',
				'stale'     => false,
				'days'      => 0,
				'oldest_id' => $id,
			);
		}
		$age = self::age( $since );
		return array(
			'count'     => $count,
			'age'       => $age['age'],
			'stale'     => $age['stale'],
			'days'      => $age['days'],
			'oldest_id' => $id,
		);
	}

	/**
	 * The older of spotted-without-mission and watch.
	 *
	 * @return array{age: string, stale: bool}
	 */
	private static function oldest_unowned(): array {
		$spotted = self::aged( self::spotted_row() );
		$watch   = self::aged( self::watch_row() );
		$pick    = $watch['count'] > 0 && ( 0 === $spotted['count'] || $watch['days'] > $spotted['days'] ) ? $watch : $spotted;
		return array(
			'age'   => $pick['age'],
			'stale' => $pick['stale'],
		);
	}

	/**
	 * Count and oldest entry time of one queue.
	 *
	 * @param string $key Queue key.
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function queue_row( string $key ): array {
		switch ( $key ) {
			case 'reports':
				return self::reports_queue();
			case 'organizations':
				return self::organizations_queue();
			case 'partners':
				return self::partners_queue();
			case 'figures':
				/**
				 * The figures of partner sites that are held back: how many days of them, since when, and the partner whose are the oldest.
				 *
				 * The hub's plugin keeps the snapshots and answers.
				 *
				 * @param array{count: int, since: string, oldest_id: int} $tally None.
				 */
				$tally = apply_filters( 'packout_overview_figures_queue', self::empty_tally() );
				return is_array( $tally ) ? array(
					'count'     => (int) ( $tally['count'] ?? 0 ),
					'since'     => (string) ( $tally['since'] ?? '' ),
					'oldest_id' => (int) ( $tally['oldest_id'] ?? 0 ),
				) : self::empty_tally();
			case 'cleanup':
				return self::cleanup_queue();
			case 'impact':
				return self::impact_queue();
			case 'counted':
				return self::counted_queue();
			case 'clearance':
				return self::clearance_queue();
			case 'unrecorded':
				return self::unrecorded_queue();
		}//end switch
		return self::empty_tally();
	}

	/**
	 * Pending Spot reports.
	 *
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function reports_queue(): array {
		global $wpdb;
		$sql = "SELECT COUNT(*) AS n, MIN(p.post_date) AS since
			FROM {$wpdb->posts} p
			WHERE p.post_type = %s AND p.post_status = %s";
		$row = self::row( $sql, array( Schema::SPOT, 'pending' ) );
		$id  = self::var(
			"SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status = %s ORDER BY p.post_date ASC, p.ID ASC LIMIT 1",
			array( Schema::SPOT, 'pending' )
		);
		return self::tally( $row, $id );
	}

	/**
	 * Organizations waiting for approval.
	 *
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function organizations_queue(): array {
		global $wpdb;
		$statuses = self::listed_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql      = "SELECT COUNT(*) AS n, MIN(p.post_date) AS since
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
			WHERE p.post_type = %s AND p.post_status IN ($in)";
		$args     = array_merge( array( '_pos_state', 'pending', Schema::TEAM ), $statuses );
		$row      = self::row( $sql, $args );
		$id       = self::var(
			"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
				WHERE p.post_type = %s AND p.post_status IN ($in)
				ORDER BY p.post_date ASC, p.ID ASC LIMIT 1",
			$args
		);
		return self::tally( $row, $id );
	}

	/**
	 * Partners waiting for approval.
	 *
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function partners_queue(): array {
		$pending = Partners::pending();
		$oldest  = null;
		foreach ( $pending as $partner ) {
			if ( null === $oldest || $partner->post_date < $oldest->post_date ) {
				$oldest = $partner;
			}
		}
		return array(
			'count'     => count( $pending ),
			'since'     => $oldest ? (string) $oldest->post_date : '',
			'oldest_id' => $oldest ? $oldest->ID : 0,
		);
	}

	/**
	 * Published Spots whose cleanup is waiting for a photo or a confirmation.
	 *
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function cleanup_queue(): array {
		global $wpdb;
		$sql  = "SELECT COUNT(*) AS n, MIN(c.meta_value) AS since
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
			LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
			WHERE p.post_type = %s AND p.post_status = %s";
		$args = array( '_pos_status', 'pending', '_pos_claim_at', Schema::SPOT, 'publish' );
		$row  = self::row( $sql, $args );
		$id   = self::var(
			"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
				LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = %s
				ORDER BY c.meta_value ASC, p.ID ASC LIMIT 1",
			$args
		);
		return self::tally( $row, $id );
	}

	/**
	 * Impact records that have not been verified.
	 *
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function impact_queue(): array {
		global $wpdb;
		$statuses = self::listed_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql      = "SELECT COUNT(*) AS n, MIN(COALESCE(NULLIF(u.meta_value, ''), p.post_date_gmt)) AS since
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = %s AND v.meta_value = %s
			LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s
			WHERE p.post_type = %s AND p.post_status IN ($in)";
		$args     = array_merge( array( '_pos_verification', 'unverified', '_pos_unverified_at', Schema::IMPACT ), $statuses );
		$row      = self::row( $sql, $args );
		$id       = self::var(
			"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = %s AND v.meta_value = %s
				LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s
				WHERE p.post_type = %s AND p.post_status IN ($in)
				ORDER BY COALESCE(NULLIF(u.meta_value, ''), p.post_date_gmt) ASC, p.ID ASC LIMIT 1",
			$args
		);
		return self::tally( $row, $id );
	}

	/**
	 * Verified records the public totals do not count.
	 *
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function counted_queue(): array {
		global $wpdb;
		$sql  = "SELECT COUNT(*) AS n, MIN(COALESCE(NULLIF(v.meta_value, ''), p.post_date_gmt)) AS since
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
			LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = %s
			WHERE p.post_type = %s AND p.post_status = %s";
		$args = array( '_pos_verification', 'verified', '_pos_verified_at', Schema::IMPACT, 'private' );
		$row  = self::row( $sql, $args );
		$id   = self::var(
			"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
				LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = %s
				ORDER BY COALESCE(NULLIF(v.meta_value, ''), p.post_date_gmt) ASC, p.ID ASC LIMIT 1",
			$args
		);
		return self::tally( $row, $id );
	}

	/**
	 * Spots flagged for authorization with no clearance yet.
	 *
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function clearance_queue(): array {
		global $wpdb;
		$statuses = Spots::POST_STATUSES;
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql      = "SELECT COUNT(*) AS n, MIN(COALESCE(NULLIF(since.meta_value, ''), p.post_date)) AS since
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} h ON h.post_id = p.ID AND h.meta_key = %s AND h.meta_value = %s
			LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} since ON since.post_id = p.ID AND since.meta_key = %s
			WHERE p.post_type = %s AND p.post_status IN ($in)
			AND (c.meta_id IS NULL OR c.meta_value = '')
			AND (s.meta_id IS NULL OR s.meta_value != %s)";
		$args     = array_merge(
			array( '_pos_hazard', 'needs_authorization', '_pos_clear_ref', '_pos_status', '_pos_clearance_since', Schema::SPOT ),
			$statuses,
			array( 'cleaned' )
		);
		$row      = self::row( $sql, $args );
		$id       = self::var(
			"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} h ON h.post_id = p.ID AND h.meta_key = %s AND h.meta_value = %s
				LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} since ON since.post_id = p.ID AND since.meta_key = %s
				WHERE p.post_type = %s AND p.post_status IN ($in)
				AND (c.meta_id IS NULL OR c.meta_value = '')
				AND (s.meta_id IS NULL OR s.meta_value != %s)
				ORDER BY COALESCE(NULLIF(since.meta_value, ''), p.post_date) ASC, p.ID ASC LIMIT 1",
			$args
		);
		return self::tally( $row, $id );
	}

	/**
	 * Cleaned Spots with no current cleanup record.
	 *
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function unrecorded_queue(): array {
		global $wpdb;
		$spot_in   = implode( ',', array_fill( 0, count( Spots::POST_STATUSES ), '%s' ) );
		$record_in = implode( ',', array_fill( 0, count( self::listed_statuses() ), '%s' ) );
		$sql       = "SELECT COUNT(*) AS n, MIN(COALESCE(NULLIF(u.meta_value, ''), c.meta_value)) AS since
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s AND st.meta_value = %s
			LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s
			WHERE p.post_type = %s AND p.post_status IN ($spot_in)
			AND NOT EXISTS (
				SELECT 1 FROM (
					SELECT CAST(ispot.meta_value AS UNSIGNED) AS spot, LEFT(icycle.meta_value, 191) AS cycle FROM {$wpdb->posts} i
				INNER JOIN {$wpdb->postmeta} ispot ON ispot.post_id = i.ID AND ispot.meta_key = %s AND ispot.meta_value IS NOT NULL
					INNER JOIN {$wpdb->postmeta} ikind ON ikind.post_id = i.ID AND ikind.meta_key = %s AND ikind.meta_value = %s
					INNER JOIN {$wpdb->postmeta} icycle ON icycle.post_id = i.ID AND icycle.meta_key = %s AND icycle.meta_value IS NOT NULL
					WHERE i.post_type = %s AND i.post_status IN ($record_in)
				) recorded WHERE recorded.spot = p.ID AND recorded.cycle = c.meta_value
			)";
		$args      = array_merge(
			array( '_pos_status', 'cleaned', '_pos_cleaned_at', '_pos_unrecorded_since', Schema::SPOT ),
			Spots::POST_STATUSES,
			array( '_pos_spot', '_pos_kind', Impact::CLEANUP, '_pos_cycle', Schema::IMPACT ),
			self::listed_statuses()
		);
		$row       = self::row( $sql, $args );
		$id        = self::var(
			"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s AND st.meta_value = %s
				LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s
				WHERE p.post_type = %s AND p.post_status IN ($spot_in)
				AND NOT EXISTS (
					SELECT 1 FROM (
						SELECT CAST(ispot.meta_value AS UNSIGNED) AS spot, LEFT(icycle.meta_value, 191) AS cycle FROM {$wpdb->posts} i
					INNER JOIN {$wpdb->postmeta} ispot ON ispot.post_id = i.ID AND ispot.meta_key = %s AND ispot.meta_value IS NOT NULL
						INNER JOIN {$wpdb->postmeta} ikind ON ikind.post_id = i.ID AND ikind.meta_key = %s AND ikind.meta_value = %s
						INNER JOIN {$wpdb->postmeta} icycle ON icycle.post_id = i.ID AND icycle.meta_key = %s AND icycle.meta_value IS NOT NULL
						WHERE i.post_type = %s AND i.post_status IN ($record_in)
					) recorded WHERE recorded.spot = p.ID AND recorded.cycle = c.meta_value
				)
				ORDER BY COALESCE(NULLIF(u.meta_value, ''), c.meta_value) ASC, p.ID ASC LIMIT 1",
			$args
		);
		return self::tally( $row, $id );
	}

	/**
	 * Published Spots at spotted, excluding do-not-touch.
	 *
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function spotted_row(): array {
		global $wpdb;
		$sql  = "SELECT COUNT(*) AS n, MIN(p.post_date) AS since
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} h ON h.post_id = p.ID AND h.meta_key = %s
			WHERE p.post_type = %s AND p.post_status = %s
			AND (s.meta_id IS NULL OR s.meta_value = '' OR s.meta_value = %s)
			AND (h.meta_id IS NULL OR h.meta_value != %s)";
		$args = array( '_pos_status', '_pos_hazard', Schema::SPOT, 'publish', 'spotted', 'do_not_touch' );
		$row  = self::row( $sql, $args );
		$id   = self::var(
			"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} h ON h.post_id = p.ID AND h.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = %s
				AND (s.meta_id IS NULL OR s.meta_value = '' OR s.meta_value = %s)
				AND (h.meta_id IS NULL OR h.meta_value != %s)
				ORDER BY p.post_date ASC, p.ID ASC LIMIT 1",
			$args
		);
		return self::tally( $row, $id );
	}

	/**
	 * Published Spots at watch.
	 *
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function watch_row(): array {
		global $wpdb;
		$sql  = "SELECT COUNT(*) AS n, MIN(COALESCE(NULLIF(r.meta_value, ''), p.post_date)) AS since
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
			LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s
			WHERE p.post_type = %s AND p.post_status = %s";
		$args = array( '_pos_status', 'watch', '_pos_returned_at', Schema::SPOT, 'publish' );
		$row  = self::row( $sql, $args );
		$id   = self::var(
			"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
				LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = %s
				ORDER BY COALESCE(NULLIF(r.meta_value, ''), p.post_date) ASC, p.ID ASC LIMIT 1",
			$args
		);
		return self::tally( $row, $id );
	}

	/**
	 * Open missions whose end date has passed.
	 *
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function overdue_row(): array {
		global $wpdb;
		// The hub's copies of a partner site's records are counted by that site, not here.
		$local    = Remote::sql( 'p' );
		$today    = wp_date( 'Y-m-d' );
		$statuses = self::listed_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$open     = Missions::OPEN;
		$open_in  = implode( ',', array_fill( 0, count( $open ), '%s' ) );
		$sql      = "SELECT COUNT(*) AS n, MIN(e.meta_value) AS since
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value IN ($open_in)
			INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s AND e.meta_value != '' AND e.meta_value < %s
			WHERE p.post_type = %s AND p.post_status IN ($in)" . Remote::sql( 'p' );
		$args     = array_merge( array( '_pos_status' ), $open, array( '_pos_end', $today, Schema::MISSION ), $statuses );
		$row      = self::row( $sql, $args );
		$id       = self::var(
			"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value IN ($open_in)
				INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s AND e.meta_value != '' AND e.meta_value < %s
				WHERE p.post_type = %s AND p.post_status IN ($in){$local}
				ORDER BY e.meta_value ASC, p.ID ASC LIMIT 1",
			$args
		);
		return self::tally( $row, $id );
	}

	/**
	 * Open mission IDs whose window has ended.
	 *
	 * @return array<int, int>
	 */
	private static function overdue_id_list(): array {
		global $wpdb;
		$today    = wp_date( 'Y-m-d' );
		$statuses = self::listed_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$open     = Missions::OPEN;
		$open_in  = implode( ',', array_fill( 0, count( $open ), '%s' ) );
		return self::col(
			"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value IN ($open_in)
				INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s AND e.meta_value != '' AND e.meta_value < %s
				WHERE p.post_type = %s AND p.post_status IN ($in)" . Remote::sql( 'p' ),
			array_merge( array( '_pos_status' ), $open, array( '_pos_end', $today, Schema::MISSION ), $statuses )
		);
	}

	/**
	 * Published do-not-touch Spots.
	 */
	private static function do_not_touch_count(): int {
		global $wpdb;
		return (int) self::var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} h ON h.post_id = p.ID AND h.meta_key = %s AND h.meta_value = %s
				WHERE p.post_type = %s AND p.post_status = %s",
			array( '_pos_hazard', 'do_not_touch', Schema::SPOT, 'publish' )
		);
	}

	/**
	 * Cleaned Spots not revisited in 90 days.
	 *
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function due_row(): array {
		global $wpdb;
		$idle      = self::idle_day();
		$record_in = implode( ',', array_fill( 0, count( self::listed_statuses() ), '%s' ) );
		$kinds     = array( Impact::STILL_CLEAR, Impact::RETURNED );
		$kind_in   = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		$sql       = "SELECT COUNT(*) AS n, MIN(c.meta_value) AS since
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s AND st.meta_value = %s
			INNER JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s AND LEFT(c.meta_value, 10) <= %s
			WHERE p.post_type = %s AND p.post_status = %s
			AND NOT EXISTS (
				SELECT 1 FROM (
					SELECT CAST(ispot.meta_value AS UNSIGNED) AS spot, LEFT(icycle.meta_value, 191) AS cycle FROM {$wpdb->posts} i
				INNER JOIN {$wpdb->postmeta} ispot ON ispot.post_id = i.ID AND ispot.meta_key = %s AND ispot.meta_value IS NOT NULL
					INNER JOIN {$wpdb->postmeta} ikind ON ikind.post_id = i.ID AND ikind.meta_key = %s AND ikind.meta_value IN ($kind_in)
					INNER JOIN {$wpdb->postmeta} icycle ON icycle.post_id = i.ID AND icycle.meta_key = %s AND icycle.meta_value IS NOT NULL
					WHERE i.post_type = %s AND i.post_status IN ($record_in)
				) recorded WHERE recorded.spot = p.ID AND recorded.cycle = c.meta_value
			)";
		$args      = array_merge(
			array( '_pos_status', 'cleaned', '_pos_cleaned_at', $idle, Schema::SPOT, 'publish', '_pos_spot', '_pos_kind' ),
			$kinds,
			array( '_pos_cycle', Schema::IMPACT ),
			self::listed_statuses()
		);
		$row       = self::row( $sql, $args );
		$id        = self::var(
			"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s AND st.meta_value = %s
				INNER JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s AND LEFT(c.meta_value, 10) <= %s
				WHERE p.post_type = %s AND p.post_status = %s
				AND NOT EXISTS (
					SELECT 1 FROM (
						SELECT CAST(ispot.meta_value AS UNSIGNED) AS spot, LEFT(icycle.meta_value, 191) AS cycle FROM {$wpdb->posts} i
					INNER JOIN {$wpdb->postmeta} ispot ON ispot.post_id = i.ID AND ispot.meta_key = %s AND ispot.meta_value IS NOT NULL
						INNER JOIN {$wpdb->postmeta} ikind ON ikind.post_id = i.ID AND ikind.meta_key = %s AND ikind.meta_value IN ($kind_in)
						INNER JOIN {$wpdb->postmeta} icycle ON icycle.post_id = i.ID AND icycle.meta_key = %s AND icycle.meta_value IS NOT NULL
						WHERE i.post_type = %s AND i.post_status IN ($record_in)
					) recorded WHERE recorded.spot = p.ID AND recorded.cycle = c.meta_value
				)
				ORDER BY c.meta_value ASC, p.ID ASC LIMIT 1",
			$args
		);
		return self::tally( $row, $id );
	}

	/**
	 * IDs of cleaned Spots due for a return visit.
	 *
	 * @return array<int, int>
	 */
	private static function due_id_list(): array {
		global $wpdb;
		$idle      = self::idle_day();
		$record_in = implode( ',', array_fill( 0, count( self::listed_statuses() ), '%s' ) );
		$kinds     = array( Impact::STILL_CLEAR, Impact::RETURNED );
		$kind_in   = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		return self::col(
			"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s AND st.meta_value = %s
				INNER JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s AND LEFT(c.meta_value, 10) <= %s
				WHERE p.post_type = %s AND p.post_status = %s
				AND NOT EXISTS (
					SELECT 1 FROM (
						SELECT CAST(ispot.meta_value AS UNSIGNED) AS spot, LEFT(icycle.meta_value, 191) AS cycle FROM {$wpdb->posts} i
					INNER JOIN {$wpdb->postmeta} ispot ON ispot.post_id = i.ID AND ispot.meta_key = %s AND ispot.meta_value IS NOT NULL
						INNER JOIN {$wpdb->postmeta} ikind ON ikind.post_id = i.ID AND ikind.meta_key = %s AND ikind.meta_value IN ($kind_in)
						INNER JOIN {$wpdb->postmeta} icycle ON icycle.post_id = i.ID AND icycle.meta_key = %s AND icycle.meta_value IS NOT NULL
						WHERE i.post_type = %s AND i.post_status IN ($record_in)
					) recorded WHERE recorded.spot = p.ID AND recorded.cycle = c.meta_value
				)",
			array_merge(
				array( '_pos_status', 'cleaned', '_pos_cleaned_at', $idle, Schema::SPOT, 'publish', '_pos_spot', '_pos_kind' ),
				$kinds,
				array( '_pos_cycle', Schema::IMPACT ),
				self::listed_statuses()
			)
		);
	}

	/**
	 * Approved organizations with no mission or cleanup in 90 days.
	 *
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function dormant_row(): array {
		global $wpdb;
		// The hub's copies of a partner site's records are counted by that site, not here.
		$local     = Remote::sql( 'p' );
		$idle      = self::idle_day();
		$statuses  = self::listed_statuses();
		$in        = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$record_in = $in;
		$sql       = "SELECT COUNT(*) AS n, MIN(p.post_date) AS since
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s
			WHERE p.post_type = %s AND p.post_status IN ($in){$local}
			AND (st.meta_id IS NULL OR st.meta_value != %s)
			AND DATE(p.post_date) <= %s
			AND NOT EXISTS (
				SELECT 1 FROM (
					SELECT CAST(mt.meta_value AS UNSIGNED) AS owner FROM {$wpdb->posts} m
				INNER JOIN {$wpdb->postmeta} mt ON mt.post_id = m.ID AND mt.meta_key = %s AND mt.meta_value IS NOT NULL
					WHERE m.post_type = %s AND m.post_status IN ($in) AND DATE(m.post_date) > %s
				) of_m WHERE of_m.owner = p.ID
			)
			AND NOT EXISTS (
				SELECT 1 FROM (
					SELECT CAST(it.meta_value AS UNSIGNED) AS owner FROM {$wpdb->posts} i
				INNER JOIN {$wpdb->postmeta} it ON it.post_id = i.ID AND it.meta_key = %s AND it.meta_value IS NOT NULL
					INNER JOIN {$wpdb->postmeta} ik ON ik.post_id = i.ID AND ik.meta_key = %s AND ik.meta_value = %s
					INNER JOIN {$wpdb->postmeta} idt ON idt.post_id = i.ID AND idt.meta_key = %s AND idt.meta_value > %s
					WHERE i.post_type = %s AND i.post_status IN ($record_in)
				) of_i WHERE of_i.owner = p.ID
			)";
		$args      = array_merge(
			array( '_pos_state', Schema::TEAM ),
			$statuses,
			array( 'pending', $idle, '_pos_team', Schema::MISSION ),
			$statuses,
			array( $idle, '_pos_team', '_pos_kind', Impact::CLEANUP, '_pos_date', $idle, Schema::IMPACT ),
			$statuses
		);
		$row       = self::row( $sql, $args );
		$id        = self::var(
			"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s
				WHERE p.post_type = %s AND p.post_status IN ($in){$local}
				AND (st.meta_id IS NULL OR st.meta_value != %s)
				AND DATE(p.post_date) <= %s
				AND NOT EXISTS (
					SELECT 1 FROM (
						SELECT CAST(mt.meta_value AS UNSIGNED) AS owner FROM {$wpdb->posts} m
					INNER JOIN {$wpdb->postmeta} mt ON mt.post_id = m.ID AND mt.meta_key = %s AND mt.meta_value IS NOT NULL
						WHERE m.post_type = %s AND m.post_status IN ($in) AND DATE(m.post_date) > %s
					) of_m WHERE of_m.owner = p.ID
				)
				AND NOT EXISTS (
					SELECT 1 FROM (
						SELECT CAST(it.meta_value AS UNSIGNED) AS owner FROM {$wpdb->posts} i
					INNER JOIN {$wpdb->postmeta} it ON it.post_id = i.ID AND it.meta_key = %s AND it.meta_value IS NOT NULL
						INNER JOIN {$wpdb->postmeta} ik ON ik.post_id = i.ID AND ik.meta_key = %s AND ik.meta_value = %s
						INNER JOIN {$wpdb->postmeta} idt ON idt.post_id = i.ID AND idt.meta_key = %s AND idt.meta_value > %s
						WHERE i.post_type = %s AND i.post_status IN ($record_in)
					) of_i WHERE of_i.owner = p.ID
				)
				ORDER BY p.post_date ASC, p.ID ASC LIMIT 1",
			$args
		);
		return self::tally( $row, $id );
	}

	/**
	 * IDs of dormant organizations.
	 *
	 * @return array<int, int>
	 */
	private static function dormant_id_list(): array {
		global $wpdb;
		// The hub's copies of a partner site's records are counted by that site, not here.
		$local     = Remote::sql( 'p' );
		$idle      = self::idle_day();
		$statuses  = self::listed_statuses();
		$in        = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$record_in = $in;
		return self::col(
			"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s
				WHERE p.post_type = %s AND p.post_status IN ($in){$local}
				AND (st.meta_id IS NULL OR st.meta_value != %s)
				AND DATE(p.post_date) <= %s
				AND NOT EXISTS (
					SELECT 1 FROM (
						SELECT CAST(mt.meta_value AS UNSIGNED) AS owner FROM {$wpdb->posts} m
					INNER JOIN {$wpdb->postmeta} mt ON mt.post_id = m.ID AND mt.meta_key = %s AND mt.meta_value IS NOT NULL
						WHERE m.post_type = %s AND m.post_status IN ($in) AND DATE(m.post_date) > %s
					) of_m WHERE of_m.owner = p.ID
				)
				AND NOT EXISTS (
					SELECT 1 FROM (
						SELECT CAST(it.meta_value AS UNSIGNED) AS owner FROM {$wpdb->posts} i
					INNER JOIN {$wpdb->postmeta} it ON it.post_id = i.ID AND it.meta_key = %s AND it.meta_value IS NOT NULL
						INNER JOIN {$wpdb->postmeta} ik ON ik.post_id = i.ID AND ik.meta_key = %s AND ik.meta_value = %s
						INNER JOIN {$wpdb->postmeta} idt ON idt.post_id = i.ID AND idt.meta_key = %s AND idt.meta_value > %s
						WHERE i.post_type = %s AND i.post_status IN ($record_in)
					) of_i WHERE of_i.owner = p.ID
				)",
			array_merge(
				array( '_pos_state', Schema::TEAM ),
				$statuses,
				array( 'pending', $idle, '_pos_team', Schema::MISSION ),
				$statuses,
				array( $idle, '_pos_team', '_pos_kind', Impact::CLEANUP, '_pos_date', $idle, Schema::IMPACT ),
				$statuses
			)
		);
	}

	/**
	 * Reports received in a range.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	private static function reported_in( string $from, string $to ): int {
		global $wpdb;
		$spot_in = implode( ',', array_fill( 0, count( Spots::POST_STATUSES ), '%s' ) );
		return (int) self::var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
				WHERE p.post_type = %s AND p.post_status IN ($spot_in)
				AND DATE(p.post_date) BETWEEN %s AND %s",
			array_merge( array( Schema::SPOT ), Spots::POST_STATUSES, array( $from, $to ) )
		);
	}

	/**
	 * Spots cleaned in a range.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	private static function cleaned_in( string $from, string $to ): int {
		global $wpdb;
		return (int) self::var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s AND LEFT(c.meta_value, 10) BETWEEN %s AND %s
				WHERE p.post_type = %s",
			array( '_pos_cleaned_at', $from, $to, Schema::SPOT )
		);
	}

	/**
	 * Median days from report to cleaned, for Spots cleaned in the range.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	private static function median_days( string $from, string $to ): ?int {
		global $wpdb;
		$rows = self::results(
			"SELECT DATEDIFF(LEFT(c.meta_value, 10), DATE(p.post_date)) AS days
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s AND LEFT(c.meta_value, 10) BETWEEN %s AND %s
				WHERE p.post_type = %s",
			array( '_pos_cleaned_at', $from, $to, Schema::SPOT )
		);
		$days = array();
		foreach ( $rows as $row ) {
			$days[] = (int) $row->days;
		}
		if ( array() === $days ) {
			return null;
		}
		sort( $days, SORT_NUMERIC );
		$n   = count( $days );
		$mid = intdiv( $n, 2 );
		if ( 0 !== $n % 2 ) {
			return $days[ $mid ];
		}
		return (int) round( ( $days[ $mid - 1 ] + $days[ $mid ] ) / 2 );
	}

	/**
	 * Return visits in a range.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array{still_clear: int, returned: int}
	 */
	private static function visits_in( string $from, string $to ): array {
		global $wpdb;
		$record_in = implode( ',', array_fill( 0, count( self::listed_statuses() ), '%s' ) );
		$rows      = self::results(
			"SELECT k.meta_value AS kind, COUNT(*) AS n
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = %s AND k.meta_value IN (%s, %s)
				INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s AND d.meta_value BETWEEN %s AND %s
				WHERE p.post_type = %s AND p.post_status IN ($record_in)
				GROUP BY kind",
			array_merge(
				array( '_pos_kind', Impact::STILL_CLEAR, Impact::RETURNED, '_pos_date', $from, $to, Schema::IMPACT ),
				self::listed_statuses()
			)
		);
		$out       = array(
			'still_clear' => 0,
			'returned'    => 0,
		);
		foreach ( $rows as $row ) {
			$kind = (string) $row->kind;
			if ( isset( $out[ $kind ] ) ) {
				$out[ $kind ] = (int) $row->n;
			}
		}
		return $out;
	}

	/**
	 * Cleaned published Spots that have had a later visit.
	 *
	 * @return array{visited: int, cleaned_total: int}
	 */
	private static function cleaned_visit_stock(): array {
		global $wpdb;
		$record_in = implode( ',', array_fill( 0, count( self::listed_statuses() ), '%s' ) );
		$kinds     = array( Impact::STILL_CLEAR, Impact::RETURNED );
		$kind_in   = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		$total     = (int) self::var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
				WHERE p.post_type = %s AND p.post_status = %s",
			array( '_pos_status', 'cleaned', Schema::SPOT, 'publish' )
		);
		$visited   = (int) self::var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
				WHERE p.post_type = %s AND p.post_status = %s
				AND EXISTS (
					SELECT 1 FROM (
						SELECT CAST(ispot.meta_value AS UNSIGNED) AS owner FROM {$wpdb->posts} i
					INNER JOIN {$wpdb->postmeta} ispot ON ispot.post_id = i.ID AND ispot.meta_key = %s AND ispot.meta_value IS NOT NULL
						INNER JOIN {$wpdb->postmeta} ikind ON ikind.post_id = i.ID AND ikind.meta_key = %s AND ikind.meta_value IN ($kind_in)
						WHERE i.post_type = %s AND i.post_status IN ($record_in)
					) of_i WHERE of_i.owner = p.ID
				)",
			array_merge(
				array( '_pos_status', 'cleaned', Schema::SPOT, 'publish', '_pos_spot', '_pos_kind' ),
				$kinds,
				array( Schema::IMPACT ),
				self::listed_statuses()
			)
		);
		return array(
			'visited'       => $visited,
			'cleaned_total' => $total,
		);
	}

	/**
	 * Community figures for a range.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<string, int>
	 */
	private static function community_in( string $from, string $to ): array {
		global $wpdb;
		$record_in = implode( ',', array_fill( 0, count( self::listed_statuses() ), '%s' ) );
		$rows      = self::results(
			"SELECT COALESCE(NULLIF(t.meta_value, ''), '0') AS team, COUNT(*) AS n
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = %s AND k.meta_value = %s
				INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s AND d.meta_value BETWEEN %s AND %s
				LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = %s
				WHERE p.post_type = %s AND p.post_status IN ($record_in)
				GROUP BY team",
			array_merge(
				array( '_pos_kind', Impact::CLEANUP, '_pos_date', $from, $to, '_pos_team', Schema::IMPACT ),
				self::listed_statuses()
			)
		);
		$by_org    = array();
		foreach ( $rows as $row ) {
			$team = (int) $row->team;
			if ( $team > 0 ) {
				$by_org[ $team ] = (int) $row->n;
			}
		}
		$largest_of    = array_sum( $by_org );
		$largest_share = $by_org ? max( $by_org ) : 0;
		$split         = self::row(
			"SELECT
				SUM(CASE WHEN CAST(m.meta_value AS UNSIGNED) > 0 THEN 1 ELSE 0 END) AS by_mission,
				SUM(CASE WHEN m.meta_id IS NULL OR m.meta_value = '' OR m.meta_value = '0' THEN 1 ELSE 0 END) AS by_person
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = %s AND k.meta_value = %s
				INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s AND d.meta_value BETWEEN %s AND %s
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = %s AND p.post_status IN ($record_in)",
			array_merge(
				array( '_pos_kind', Impact::CLEANUP, '_pos_date', $from, $to, '_pos_mission', Schema::IMPACT ),
				self::listed_statuses()
			)
		);
		$authors       = self::col(
			"SELECT DISTINCT p.post_author FROM {$wpdb->posts} p
				WHERE p.post_type = %s AND DATE(p.post_date) BETWEEN %s AND %s AND p.post_author > 0",
			array( Schema::SPOT, $from, $to )
		);
		$workers       = self::col(
			"SELECT DISTINCT u.meta_value FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = %s AND k.meta_value = %s
				INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s AND d.meta_value BETWEEN %s AND %s
				INNER JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s AND u.meta_value != '' AND u.meta_value != '0'
				WHERE p.post_type = %s AND p.post_status IN ($record_in)",
			array_merge(
				array( '_pos_kind', Impact::CLEANUP, '_pos_date', $from, $to, '_pos_user', Schema::IMPACT ),
				self::listed_statuses()
			)
		);
		$active        = array_unique( array_merge( $authors, $workers ) );
		return array(
			'orgs_active'       => count( $by_org ),
			'orgs_approved'     => self::organizations()['count'],
			'largest_share'     => (int) $largest_share,
			'largest_of'        => (int) $largest_of,
			'by_mission'        => $split ? (int) $split->by_mission : 0,
			'by_person'         => $split ? (int) $split->by_person : 0,
			'people_active'     => count( $active ),
			'people_registered' => self::people()['count'],
			'people_joined'     => self::joined_in( $from, $to ),
		);
	}

	/**
	 * People with packout_report whose account was created in the range.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	private static function joined_in( string $from, string $to ): int {
		$query = new \WP_User_Query(
			array(
				'capability'  => Capabilities::REPORT,
				'date_query'  => array(
					array(
						'after'     => $from . ' 00:00:00',
						'before'    => $to . ' 23:59:59',
						'inclusive' => true,
					),
				),
				'fields'      => 'ID',
				'number'      => 500,
				'count_total' => false,
			)
		);
		$n     = 0;
		foreach ( (array) $query->get_results() as $id ) {
			if ( Accounts::on_site( (int) $id ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Trust figures for a range, matching Impact::totals().
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<string, int|float>
	 */
	private static function trust_in( string $from, string $to ): array {
		global $wpdb;
		$totals     = Impact::totals(
			array(
				'from' => $from,
				'to'   => $to,
			)
		);
		$record_in  = implode( ',', array_fill( 0, count( self::listed_statuses() ), '%s' ) );
		$row        = self::row(
			"SELECT
				COUNT(*) AS total_cleanups,
				SUM(CASE WHEN v.meta_value = %s THEN 1 ELSE 0 END) AS verified,
				SUM(CASE WHEN p.post_status = %s THEN 1 ELSE 0 END) AS counted
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = %s AND k.meta_value = %s
				INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s AND d.meta_value BETWEEN %s AND %s
				LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = %s
				WHERE p.post_type = %s AND p.post_status IN ($record_in)",
			array_merge(
				array( 'verified', 'publish', '_pos_kind', Impact::CLEANUP, '_pos_date', $from, $to, '_pos_verification', Schema::IMPACT ),
				self::listed_statuses()
			)
		);
		$unrecorded = self::$unrecorded ?? self::unrecorded_queue();
		$cleaned    = (int) self::var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
				WHERE p.post_type = %s",
			array( '_pos_status', 'cleaned', Schema::SPOT )
		);
		return array(
			'verified'             => $row ? (int) $row->verified : 0,
			'total_cleanups'       => $row ? (int) $row->total_cleanups : 0,
			'counted'              => $row ? (int) $row->counted : 0,
			'pounds_measured'      => (float) ( $totals['outputs']['pounds_measured'] ?? 0 ),
			'pounds_self_reported' => (float) ( $totals['outputs']['pounds_self_reported'] ?? 0 ),
			'bags_measured'        => (int) ( $totals['outputs']['bags_measured'] ?? 0 ),
			'bags_self_reported'   => (int) ( $totals['outputs']['bags_self_reported'] ?? 0 ),
			'hours_verified'       => (float) ( $totals['effort']['hours_verified'] ?? 0 ),
			'hours_unverified'     => (float) ( $totals['effort']['hours_unverified'] ?? 0 ),
			'people'               => (int) ( $totals['effort']['people'] ?? 0 ),
			'missions'             => (int) ( $totals['effort']['missions'] ?? 0 ),
			'teams'                => (int) ( $totals['effort']['teams'] ?? 0 ),
			'cleaned_with_record'  => max( 0, $cleaned - $unrecorded['count'] ),
			'cleaned'              => $cleaned,
			'spots_cleaned'        => (int) ( $totals['outputs']['spots'] ?? 0 ),
			'still_clear'          => (int) ( $totals['outcomes']['still_clear'] ?? 0 ),
			'returned'             => (int) ( $totals['outcomes']['returned'] ?? 0 ),
		);
	}

	/**
	 * How reports arrived and what review did, in a range.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<string, int>
	 */
	private static function field_in( string $from, string $to ): array {
		global $wpdb;
		$spot_in = implode( ',', array_fill( 0, count( Spots::POST_STATUSES ), '%s' ) );
		$row     = self::row(
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
				AND DATE(p.post_date) BETWEEN %s AND %s",
			array_merge(
				array( 'pin', 'publish', 'private', 'pending', '_pos_client_id', '_pos_entry_id', '_pos_location_kind', '_pos_return_reason', '_pos_duplicate_of', Schema::SPOT ),
				Spots::POST_STATUSES,
				array( $from, $to )
			)
		);
		return array(
			'reports'   => $row ? (int) $row->reports : 0,
			'field'     => $row ? (int) $row->field : 0,
			'form'      => $row ? (int) $row->form : 0,
			'no_pin'    => $row ? (int) $row->no_pin : 0,
			'published' => $row ? (int) $row->published : 0,
			'private'   => $row ? (int) $row->private_count : 0,
			'waiting'   => $row ? (int) $row->waiting : 0,
			'returned'  => $row ? (int) $row->returned : 0,
			'duplicate' => $row ? (int) $row->duplicate : 0,
		);
	}

	/**
	 * Inventory counts that do not change with the window.
	 *
	 * @return array<string, int>
	 */
	private static function platform(): array {
		global $wpdb;
		$places  = (int) self::var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
			array( Schema::PLACE, 'publish' )
		);
		$adopted = 0;
		foreach ( Places::all( true ) as $place ) {
			if ( Places::adopters( $place->ID ) ) {
				++$adopted;
			}
		}
		return array(
			'organizations' => self::organizations()['count'],
			'partners'      => count( array_filter( Partners::all(), static fn( $partner ) => Partners::active( $partner->ID ) ) ),
			'people'        => self::people()['count'],
			'spots'         => self::published_total(),
			'places'        => $places,
			'adopted'       => $adopted,
		);
	}

	/**
	 * Product-signal counts, for deciding what to build next.
	 *
	 * @return array<string, mixed>
	 */
	private static function signals(): array {
		global $wpdb;
		$record_in  = implode( ',', array_fill( 0, count( self::listed_statuses() ), '%s' ) );
		$kinds      = array( Impact::STILL_CLEAR, Impact::RETURNED );
		$kind_in    = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		$rows       = self::results(
			"SELECT t.slug AS slug, COUNT(*) AS n
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = %s
				AND (s.meta_id IS NULL OR s.meta_value != %s)
				GROUP BY t.slug",
			array( Schema::CATEGORY, '_pos_status', Schema::SPOT, 'publish', 'cleaned' )
		);
		$categories = array();
		foreach ( array_keys( Schema::categories() ) as $slug ) {
			$categories[ $slug ] = 0;
		}
		foreach ( $rows as $row ) {
			$slug = (string) $row->slug;
			if ( isset( $categories[ $slug ] ) ) {
				$categories[ $slug ] = (int) $row->n;
			}
		}
		$spot_in = implode( ',', array_fill( 0, count( Spots::POST_STATUSES ), '%s' ) );
		$all     = (int) self::var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status IN ($spot_in)",
			array_merge( array( Schema::SPOT ), Spots::POST_STATUSES )
		);
		$field   = (int) self::var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s AND c.meta_value != ''
				WHERE p.post_type = %s AND p.post_status IN ($spot_in)",
			array_merge( array( '_pos_client_id', Schema::SPOT ), Spots::POST_STATUSES )
		);
		$dupes   = (int) self::var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s AND d.meta_value != '' AND d.meta_value != '0'
				WHERE p.post_type = %s",
			array( '_pos_duplicate_of', Schema::SPOT )
		);
		$never   = (int) self::var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
				WHERE p.post_type = %s AND p.post_status = %s
				AND NOT EXISTS (
					SELECT 1 FROM (
						SELECT CAST(ispot.meta_value AS UNSIGNED) AS owner FROM {$wpdb->posts} i
					INNER JOIN {$wpdb->postmeta} ispot ON ispot.post_id = i.ID AND ispot.meta_key = %s AND ispot.meta_value IS NOT NULL
						INNER JOIN {$wpdb->postmeta} ikind ON ikind.post_id = i.ID AND ikind.meta_key = %s AND ikind.meta_value IN ($kind_in)
						WHERE i.post_type = %s AND i.post_status IN ($record_in)
					) of_i WHERE of_i.owner = p.ID
				)",
			array_merge(
				array( '_pos_status', 'cleaned', Schema::SPOT, 'publish', '_pos_spot', '_pos_kind' ),
				$kinds,
				array( Schema::IMPACT ),
				self::listed_statuses()
			)
		);
		$hidden  = (int) self::var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} h ON h.post_id = p.ID AND h.meta_key = %s AND h.meta_value = %s
				WHERE p.post_type = %s",
			array( '_pos_photos_public', '0', Schema::SPOT )
		);
		$cleared = (int) self::var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s AND r.meta_value != ''
				WHERE p.post_type = %s",
			array( '_pos_clear_ref', Schema::SPOT )
		);
		$adopted = 0;
		foreach ( Places::all( true ) as $place ) {
			if ( Places::adopters( $place->ID ) ) {
				++$adopted;
			}
		}
		return array(
			'categories'      => $categories,
			'field'           => $field,
			'reports'         => $all,
			'duplicates'      => $dupes,
			'never_revisited' => $never,
			'hidden_photos'   => $hidden,
			'clearances'      => $cleared,
			'adopted_places'  => $adopted,
		);
	}

	/**
	 * Published Spot counts by domain status.
	 *
	 * @return array<string, int>
	 */
	private static function stage_counts(): array {
		global $wpdb;
		$counts = array_fill_keys( array_keys( Schema::statuses() ), 0 );
		$sql    = "SELECT COALESCE(NULLIF(s.meta_value, ''), 'spotted') AS status, COUNT(*) AS n
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
			WHERE p.post_type = %s AND p.post_status = %s
			GROUP BY status";
		$rows   = self::results( $sql, array( '_pos_status', Schema::SPOT, 'publish' ) );
		foreach ( $rows as $row ) {
			$status = (string) $row->status;
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] += (int) $row->n;
			} else {
				$counts['spotted'] += (int) $row->n;
			}
		}
		return $counts;
	}

	/**
	 * How many Spots are on the public map.
	 */
	private static function published_total(): int {
		global $wpdb;
		return (int) self::var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
			array( Schema::SPOT, 'publish' )
		);
	}

	/**
	 * Daily or weekly report and cleanup series.
	 *
	 * @param int $days Window length.
	 * @return array{bucket: string, series: array<int, array{day: string, reports: int, cleanups: int}>}
	 */
	private static function bucketed_series( int $days ): array {
		$daily = self::series( $days );
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

	/**
	 * Daily report and cleanup counts.
	 *
	 * @param int $days Window length.
	 * @return array<int, array{day: string, reports: int, cleanups: int}>
	 */
	private static function series( int $days ): array {
		global $wpdb;
		$tz    = wp_timezone();
		$today = new \DateTimeImmutable( 'now', $tz );
		$from  = $today->setTime( 0, 0 )->sub( new \DateInterval( 'P' . ( $days - 1 ) . 'D' ) );
		$start = $from->format( 'Y-m-d' );
		$end   = $today->format( 'Y-m-d' );
		$out   = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$day         = $from->add( new \DateInterval( 'P' . $i . 'D' ) )->format( 'Y-m-d' );
			$out[ $day ] = array(
				'day'      => $day,
				'reports'  => 0,
				'cleanups' => 0,
			);
		}

		$spot_in = implode( ',', array_fill( 0, count( Spots::POST_STATUSES ), '%s' ) );
		$reports = self::results(
			"SELECT DATE(p.post_date) AS day, COUNT(*) AS n
				FROM {$wpdb->posts} p
				WHERE p.post_type = %s AND p.post_status IN ($spot_in)
				AND DATE(p.post_date) BETWEEN %s AND %s
				GROUP BY day",
			array_merge( array( Schema::SPOT ), Spots::POST_STATUSES, array( $start, $end ) )
		);
		foreach ( $reports as $row ) {
			$day = (string) $row->day;
			if ( isset( $out[ $day ] ) ) {
				$out[ $day ]['reports'] = (int) $row->n;
			}
		}

		$record_in = implode( ',', array_fill( 0, count( self::listed_statuses() ), '%s' ) );
		$cleanups  = self::results(
			"SELECT d.meta_value AS day, COUNT(*) AS n
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = %s AND k.meta_value = %s
				INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s
				WHERE p.post_type = %s AND p.post_status IN ($record_in)
				AND d.meta_value BETWEEN %s AND %s
				GROUP BY day",
			array_merge(
				array( '_pos_kind', Impact::CLEANUP, '_pos_date', Schema::IMPACT ),
				self::listed_statuses(),
				array( $start, $end )
			)
		);
		foreach ( $cleanups as $row ) {
			$day = (string) $row->day;
			if ( isset( $out[ $day ] ) ) {
				$out[ $day ]['cleanups'] = (int) $row->n;
			}
		}

		return array_values( $out );
	}

	/**
	 * Setup checks: the report form, the recommended pages, and map regions.
	 *
	 * @return array<int, array{id: string, ok: bool, tab: string, label: string, detail: string}>
	 */
	private static function setup_rows(): array {
		$form = App::kernel()->report_form()->status();
		$rows = array(
			array(
				'id'     => 'form',
				'ok'     => ! empty( $form['ok'] ),
				'tab'    => __( 'Form', 'packout-core' ),
				'label'  => __( 'Report a Spot form', 'packout-core' ),
				'detail' => (string) ( $form['detail'] ?? '' ),
			),
		);

		$pages = array(
			'packout/map'           => array(
				'id'      => 'map',
				'tab'     => __( 'Map', 'packout-core' ),
				'label'   => __( 'A page with the map', 'packout-core' ),
				'missing' => __( 'Add the Pack-Out Spot map block to Home or Map.', 'packout-core' ),
			),
			'packout/report-prompt' => array(
				'id'      => 'reports',
				'tab'     => __( 'Reports', 'packout-core' ),
				'label'   => __( 'A page that takes reports', 'packout-core' ),
				'missing' => __( 'Add the Report prompt block, or the report form, to the Report a Spot page.', 'packout-core' ),
			),
			'packout/teams'         => array(
				'id'      => 'organizations',
				'tab'     => __( 'Organizations', 'packout-core' ),
				'label'   => __( 'A page that lists the organizations', 'packout-core' ),
				'missing' => __( 'Add the Organization list block to the For Organizations page.', 'packout-core' ),
			),
			'packout/impact'        => array(
				'id'      => 'impact',
				'tab'     => __( 'Impact', 'packout-core' ),
				'label'   => __( 'A page with the impact totals', 'packout-core' ),
				'missing' => __( 'Add the Impact summary block to the Impact page.', 'packout-core' ),
			),
			'packout/signup'        => array(
				'id'      => 'signup',
				'tab'     => __( 'Sign up', 'packout-core' ),
				'label'   => __( 'A page where people sign up', 'packout-core' ),
				'missing' => __( 'Add the Sign up block to a page, then choose that page in settings.', 'packout-core' ),
			),
			'packout/signin'        => array(
				'id'      => 'signin',
				'tab'     => __( 'Sign in', 'packout-core' ),
				'label'   => __( 'A page where people sign in', 'packout-core' ),
				'missing' => __( 'Add the Sign in block to a page, then choose that page in settings.', 'packout-core' ),
			),
			'packout/workspace'     => array(
				'id'      => 'workspace',
				'tab'     => __( 'Workspace', 'packout-core' ),
				'label'   => __( 'A page where organizations are run', 'packout-core' ),
				'missing' => __( 'Add the Organization workspace block to a page, then choose that page in settings.', 'packout-core' ),
			),
			'packout/account'       => array(
				'id'      => 'account',
				'tab'     => __( 'Account', 'packout-core' ),
				'label'   => __( 'A page for each person\'s account', 'packout-core' ),
				'missing' => __( 'Add the My account block to a page, then choose that page in settings.', 'packout-core' ),
			),
		);
		// A partner site makes these pages, or chooses its own, on its Pages screen.
		$elsewhere = App::is_hub() ? '' : __( 'Make this page, or choose one you already have, under Pack-Out Spot, Pages.', 'packout-core' );
		foreach ( $pages as $block => $text ) {
			$found  = self::pages_with_block( $block );
			$chosen = array(
				'packout/signup'    => 'signup_page',
				'packout/signin'    => 'signin_page',
				'packout/workspace' => 'workspace_page',
				'packout/account'   => 'account_page',
			);
			$named  = ! isset( $chosen[ $block ] ) || ( (int) Settings::get( $chosen[ $block ] ) > 0 && has_block( $block, (int) Settings::get( $chosen[ $block ] ) ) );
			$rows[] = array(
				'id'     => $text['id'],
				'ok'     => array() !== $found && $named,
				'tab'    => $text['tab'],
				'label'  => $text['label'],
				'detail' => $found && $named ? implode( ', ', $found ) : ( '' !== $elsewhere ? $elsewhere : $text['missing'] ),
			);
		}

		$regions = (array) Settings::get( 'regions' );
		$rows[]  = array(
			'id'     => 'regions',
			'ok'     => array() !== $regions,
			'tab'    => __( 'Offline maps', 'packout-core' ),
			'label'  => __( 'Offline map regions', 'packout-core' ),
			'detail' => $regions
				? implode( ', ', wp_list_pluck( $regions, 'name' ) )
				: __( 'No region is published. Reporters can still save reports offline, without a base map.', 'packout-core' ),
		);

		return $rows;
	}

	/**
	 * Titles of published pages that contain a block.
	 *
	 * @param string $block Block name.
	 * @return array<int, string>
	 */
	private static function pages_with_block( string $block ): array {
		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'numberposts' => 20,
				's'           => '<!-- wp:' . $block,
			)
		);
		$found = array();
		foreach ( $pages as $page ) {
			if ( has_block( $block, $page ) ) {
				$found[] = $page->post_title;
			}
		}
		return $found;
	}

	/**
	 * Post statuses of listed organizations, missions, and impact records.
	 *
	 * @return array<int, string>
	 */
	private static function listed_statuses(): array {
		return array( 'publish', 'private' );
	}

	/**
	 * Empty tally.
	 *
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function empty_tally(): array {
		return array(
			'count'     => 0,
			'since'     => '',
			'oldest_id' => 0,
		);
	}

	/**
	 * Count, oldest time, and oldest ID from a row.
	 *
	 * @param object|null $row Query row with n and since.
	 * @param mixed       $id  Oldest ID.
	 * @return array{count: int, since: string, oldest_id: int}
	 */
	private static function tally( $row, $id = 0 ): array {
		return array(
			'count'     => $row ? (int) $row->n : 0,
			'since'     => $row && isset( $row->since ) ? (string) $row->since : '',
			'oldest_id' => (int) $id,
		);
	}

	/**
	 * One scalar from an aggregate query.
	 *
	 * @param string            $sql  Prepared statement.
	 * @param array<int, mixed> $args Placeholder values.
	 */
	private static function var( string $sql, array $args ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Overview aggregates.
	}

	/**
	 * One row from an aggregate query.
	 *
	 * @param string            $sql  Prepared statement.
	 * @param array<int, mixed> $args Placeholder values.
	 */
	private static function row( string $sql, array $args ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Overview aggregates.
	}

	/**
	 * IDs from a query.
	 *
	 * @param string            $sql  Prepared statement.
	 * @param array<int, mixed> $args Placeholder values.
	 * @return array<int, int>
	 */
	private static function col( string $sql, array $args ): array {
		global $wpdb;
		$found = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Overview aggregates.
		return array_map( 'intval', is_array( $found ) ? $found : array() );
	}

	/**
	 * Rows from a grouped query.
	 *
	 * @param string            $sql  Prepared statement.
	 * @param array<int, mixed> $args Placeholder values.
	 * @return array<int, object>
	 */
	private static function results( string $sql, array $args ): array {
		global $wpdb;
		$found = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Overview aggregates.
		return is_array( $found ) ? $found : array();
	}
}
