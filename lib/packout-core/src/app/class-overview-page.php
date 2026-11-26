<?php
/**
 * The overview page of one partner or one organization.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Platform overview's page, about one record.
 *
 * It is drawn with the same parts and read by the same script. The queue is
 * what only the record's leads can clear, the outcomes count the record's own
 * work, and the foot has no product signals.
 */
class Overview_Page {

	/**
	 * The record whose overview the address asks for, or 0.
	 */
	public static function requested(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Opens a page.
		return isset( $_GET['overview'] ) ? absint( wp_unslash( $_GET['overview'] ) ) : 0;
	}

	/**
	 * The address of a record's overview.
	 *
	 * @param string $kind `partner` or `team`.
	 * @param int    $id   The record.
	 */
	public static function url( string $kind, int $id ): string {
		// A partner site is one partner, so the partner's overview is the site's.
		if ( 'partner' === $kind && ! App::is_hub() ) {
			return admin_url( 'admin.php?page=packout-spot' );
		}
		return add_query_arg(
			array(
				'page'     => 'partner' === $kind ? Admin::PARTNERS_PAGE : Teams_Screen::PAGE,
				'overview' => $id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Where a person with no Platform overview starts: the overview of the one
	 * organization or the one partner they lead, or the list when they lead more.
	 *
	 * @param int $user User ID.
	 */
	public static function home( int $user ): string {
		// On a partner site the partner's overview is the site's, which a coordinator opens.
		$partners = App::is_hub() ? Partners::of_user( $user ) : array();
		$teams    = array_values( array_filter( wp_list_pluck( Teams::all(), 'ID' ), static fn( $team ) => Teams::leads_work( (int) $team, $user ) && 0 === Partners::of_team( (int) $team ) ) );
		if ( 1 === count( $partners ) && ! $teams ) {
			return self::url( 'partner', (int) $partners[0] );
		}
		$led = array_values( array_filter( wp_list_pluck( Teams::all(), 'ID' ), static fn( $team ) => Teams::leads_work( (int) $team, $user ) ) );
		if ( 1 === count( $led ) && ! $partners ) {
			return self::url( 'team', (int) $led[0] );
		}
		if ( $partners && ! $teams ) {
			return admin_url( 'admin.php?page=' . Admin::PARTNERS_PAGE );
		}
		return admin_url( 'admin.php?page=' . Teams_Screen::PAGE );
	}

	/**
	 * The page.
	 *
	 * @param string $kind `partner` or `team`.
	 * @param int    $id   The record.
	 */
	public static function render( string $kind, int $id ): void {
		$user = get_current_user_id();
		Admin::require( Overview::can_open( $kind, $id, $user ) );

		$scope    = array(
			'kind' => $kind,
			'id'   => $id,
		);
		$record   = Overview_Scope::record( $scope );
		$reported = null !== Remote::reported_scope( $scope );
		$partner  = 'partner' === $kind;
		$moderate = Capabilities::can( Capabilities::MODERATE );
		$days     = 30;
		$now      = Overview::cached_now( $scope );
		$period   = Overview::cached_period( $days, $scope );
		$filter   = array( $partner ? 'partner' : 'team' => $id );

		// A view of the workspace: the organization's, or the partner's when the row names no organization.
		$work     = static function ( string $view, int $team = 0 ) use ( $partner, $id ): string {
			if ( $partner && $team <= 0 ) {
				return Partner_Workspace::url( $id, $view );
			}
			return Workspace::url( $team > 0 ? $team : $id, $view );
		};
		$missions = add_query_arg( $filter, admin_url( 'admin.php?page=packout-missions' ) );
		$impact   = add_query_arg( $filter, admin_url( 'admin.php?page=packout-impact' ) );
		// The Spots screen is a coordinator's. A lead plans Spots from the workspace.
		$spots = $moderate ? add_query_arg( $filter, admin_url( 'admin.php?page=packout-spots' ) ) : $work( 'missions' );
		$wait  = $now['waiting'] ?? array();

		$queues = array(
			'requests'   => array( 'people', $work( 'members', (int) ( $wait['requests']['team'] ?? 0 ) ), false ),
			'signups'    => array( 'scheduled', $work( 'members', (int) ( $wait['signups']['team'] ?? 0 ) ), false ),
			'impact'     => array( 'trending-up', add_query_arg( 'verification', 'unverified', $impact ), true ),
			'unrecorded' => array( 'caution', add_query_arg( 'unrecorded', '1', $impact ) . '#packout-unrecorded', true ),
			'overdue'    => array(
				'scheduled',
				add_query_arg(
					array(
						'status'  => 'open',
						'overdue' => '1',
					),
					$missions
				),
				true,
			),
			'adoptions'  => array( 'map-marker', $work( 'submissions', (int) ( $wait['adoptions']['team'] ?? 0 ) ), false ),
			'claims'     => array( 'institution', add_query_arg( 'claims', '1', self::url( $kind, $id ) ) . '#packout-claims', false ),
		);

		echo '<div class="wrap packout-admin packout-overview packout-overview--scoped' . ( $reported ? ' packout-overview--reported' : '' ) . '" style="' . esc_attr( Admin::palette() ) . '" data-packout-days="' . esc_attr( (string) $days ) . '" data-packout-scope="' . esc_attr( 'kind=' . $kind . '&id=' . $id ) . '">';
		echo '<script type="application/json" id="packout-overview-now">' . wp_json_encode( $now ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON snapshot.
		echo '<script type="application/json" id="packout-overview-period">' . wp_json_encode( $period ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON snapshot.

		echo '<header class="packout-overview__header">';
		echo '<div class="packout-overview__title">';
		echo '<p class="packout-overview__updated"><a href="' . esc_url( admin_url( 'admin.php?page=' . ( $partner ? Admin::PARTNERS_PAGE : Teams_Screen::PAGE ) ) ) . '">' . esc_html( $partner ? __( 'Partners', 'packout-core' ) : __( 'Organizations', 'packout-core' ) ) . '</a> / ' . esc_html__( 'Overview', 'packout-core' ) . '</p>';
		echo '<h1>' . esc_html( $record ? $record->post_title : '' ) . '</h1>';
		echo '<p class="packout-overview__updated" data-packout-updated>' . esc_html( Admin::updated_label( (string) ( $now['computed_at'] ?? '' ) ) ) . '</p></div>';
		echo '<div class="packout-overview__tools">';
		echo '<label class="packout-overview__window"><span class="screen-reader-text">' . esc_html__( 'Period', 'packout-core' ) . '</span>';
		echo '<select data-packout-activity>';
		foreach ( Overview::windows() as $window ) {
			echo '<option value="' . esc_attr( (string) $window ) . '"' . selected( $days, $window, false ) . '>';
			/* translators: %d: number of days. */
			echo esc_html( sprintf( __( 'Last %d days', 'packout-core' ), $window ) );
			echo '</option>';
		}
		echo '</select></label>';
		if ( ! $reported && Overview_Report::may( array( $kind => $id ) ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-overview__export">';
			echo '<input type="hidden" name="action" value="packout_export_overview" />';
			wp_nonce_field( 'packout_export_overview' );
			echo '<input type="hidden" name="kind" value="' . esc_attr( $kind ) . '" />';
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
			echo '<input type="hidden" name="days" value="' . esc_attr( (string) $days ) . '" />';
			echo '<label><span class="screen-reader-text">' . esc_html__( 'Format', 'packout-core' ) . '</span>';
			echo '<select name="format">';
			foreach ( Overview_Report::formats() as $format => $label ) {
				echo '<option value="' . esc_attr( $format ) . '"' . selected( 'pdf', $format, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></label>';
			echo '<button class="packout-overview__button">' . Admin::icon( 'download' ) . esc_html__( 'Export impact report', 'packout-core' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is escaped SVG.
		}
		echo '</div></header>';
		echo '<hr class="wp-header-end" />';

		if ( $reported ) {
			$owner = $partner ? $id : Remote::partner( $id );
			$named = $owner > 0 && '' !== (string) get_post_field( 'post_title', $owner ) ? (string) get_post_field( 'post_title', $owner ) : __( 'a partner', 'packout-core' );
			echo '<div class="packout-overview__notice">';
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
			echo Admin::ui(
				'notice',
				array(
					'intent'      => 'info',
					'description' => empty( $now['empty'] )
						/* translators: %s: partner name. */
						? sprintf( __( 'These figures were sent by the site of %s, where its records are kept. They are shown as that site counts them, and nothing here opens a list, because the lists are there.', 'packout-core' ), $named )
						/* translators: %s: partner name. */
						: sprintf( __( 'The site of %s has not sent its figures yet. They arrive with its next daily send.', 'packout-core' ), $named ),
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
			/**
			 * Print what this site knows about the connection behind a reported overview.
			 *
			 * @param int   $owner The partner.
			 * @param array $scope The overview's scope.
			 */
			do_action( 'packout_overview_connection', $owner, $scope );
			ob_start();
		} elseif ( ! empty( $now['empty'] ) ) {
			echo '<div class="packout-overview__notice">';
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
			echo Admin::ui(
				'notice',
				array(
					'intent'      => 'info',
					'description' => __( 'This partner has no organizations yet, so there is nothing to count. Add one from the Partner workspace.', 'packout-core' ),
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		}//end if

		echo '<div class="packout-overview__body">';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Opens a list on the page.
		if ( $partner && isset( $_GET['claims'] ) ) {
			self::claims( $id );
		}
		Admin::overview_queue( $wait, $queues, Overview_Scope::queues( $scope ), __( 'Waiting on you', 'packout-core' ) );
		self::outcome_a( $now, $period, $spots, $missions );
		self::outcome_b( $now, $period, $spots );
		self::outcome_c( $now, $period, $partner, $id, $work, $moderate );
		self::outcome_d( $now, $period, $queues );
		self::outcome_e( $period );
		self::foot( $record ? $record->post_title : '', $now['platform'] ?? array(), $partner, $id, $work, $missions );
		echo '</div>';
		if ( $reported ) {
			// Every row of a counted overview opens the list behind it. Those lists are on the partner's site, so here a row is its figure and no more.
			echo preg_replace( '/(<a\b[^>]*?)\shref="[^"]*"/', '$1', (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The page's own markup, escaped where it was written.
		}
		echo '</div>';
	}

	/**
	 * The organizations asking to join a partner, with where to answer them.
	 *
	 * @param int $id Partner ID.
	 */
	private static function claims( int $id ): void {
		$names = array();
		foreach ( Partners::requests( $id ) as $request ) {
			if ( 'team' === $request['from'] ) {
				$names[] = get_the_title( $request['team'] );
			}
		}
		echo '<section class="packout-overview__panel packout-overview__waiting" id="packout-claims">';
		echo '<h2>' . esc_html__( 'Organizations asking to join', 'packout-core' ) . '</h2>';
		if ( ! $names ) {
			echo '<p class="packout-overview__empty"><span class="packout-overview__chip packout-overview__chip--clear">' . Admin::icon( 'check' ) . '</span>' . esc_html__( 'No organization is asking to join.', 'packout-core' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is escaped SVG.
		} else {
			echo '<p class="packout-overview__reading">' . esc_html( implode( ', ', $names ) ) . '</p>';
			echo '<p><a class="packout-overview__go" href="' . esc_url( Partner_Workspace::url( $id, 'organizations' ) ) . '">' . esc_html__( 'Accept or decline in the Partner workspace', 'packout-core' ) . Admin::icon( 'chevron-right' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is escaped SVG.
		}
		echo '</section>';
	}

	/**
	 * Outcome A: the Spots this record took on.
	 *
	 * @param array<string, mixed> $now      Current snapshot.
	 * @param array<string, mixed> $period   Period snapshot.
	 * @param string               $spots    Where its Spots are listed.
	 * @param string               $missions Missions screen, on this record.
	 */
	private static function outcome_a( array $now, array $period, string $spots, string $missions ): void {
		$a      = $period['a'] ?? array();
		$cur    = $a['current'] ?? array();
		$prev   = $a['previous'] ?? array();
		$levers = $now['levers']['a'] ?? array();
		Admin::overview_outcome_open( 'a', __( 'Does a report lead to action?', 'packout-core' ), (string) ( $a['reading'] ?? '' ) );
		Admin::overview_stack( $now['stages'] ?? array(), $spots );
		$mission = $now['missions'] ?? array();
		echo '<p class="packout-overview__missions">';
		echo '<a href="' . esc_url( add_query_arg( 'status', 'open', $missions ) ) . '"><strong data-packout-path="missions.count">' . esc_html( number_format_i18n( (int) ( $mission['count'] ?? 0 ) ) ) . '</strong> ' . esc_html__( 'open missions', 'packout-core' ) . '</a>';
		echo '<a href="' . esc_url( add_query_arg( 'status', 'planned', $missions ) ) . '"><strong data-packout-path="missions.planned">' . esc_html( number_format_i18n( (int) ( $mission['planned'] ?? 0 ) ) ) . '</strong> ' . esc_html__( 'planned', 'packout-core' ) . '</a>';
		echo '<a href="' . esc_url( add_query_arg( 'status', 'in_progress', $missions ) ) . '"><strong data-packout-path="missions.in_progress">' . esc_html( number_format_i18n( (int) ( $mission['in_progress'] ?? 0 ) ) ) . '</strong> ' . esc_html__( 'in progress', 'packout-core' ) . '</a>';
		echo '</p>';
		echo '</div><div class="packout-overview__proof">';
		echo '<dl class="packout-overview__evidence">';
		Admin::overview_stat( __( 'Added to its missions', 'packout-core' ), 'a.current.reported', $cur['reported'] ?? 0, $prev['reported'] ?? 0 );
		Admin::overview_stat( __( 'Cleaned', 'packout-core' ), 'a.current.cleaned', $cur['cleaned'] ?? 0, $prev['cleaned'] ?? 0 );
		Admin::overview_stat( __( 'Net open', 'packout-core' ), 'a.current.net_open', $cur['net_open'] ?? 0, $prev['net_open'] ?? 0, true );
		Admin::overview_stat( __( 'Median days from mission to cleaned', 'packout-core' ), 'a.current.median_days', $cur['median_days'] ?? null, $prev['median_days'] ?? null );
		echo '</dl>';
		echo '<div class="packout-overview__chart-wrap" data-packout-metric="activity"><div class="packout-overview__chart"><canvas role="img" aria-label="' . esc_attr__( 'Spots added to missions, and cleanups', 'packout-core' ) . '"></canvas></div></div>';
		Admin::overview_levers_open();
		Admin::overview_lever(
			__( 'Planned, with no mission date ahead', 'packout-core' ),
			'pin',
			add_query_arg(
				array(
					'review' => 'publish',
					'status' => 'planned',
				),
				$spots
			),
			$levers['planned'] ?? array(),
			'a.planned',
			false
		);
		Admin::overview_lever(
			__( 'Came back, no new mission', 'packout-core' ),
			'caution',
			add_query_arg(
				array(
					'review' => 'publish',
					'status' => 'watch',
				),
				$spots
			),
			$levers['watch'] ?? array(),
			'a.watch',
			false
		);
		Admin::overview_lever(
			__( 'Missions past their window', 'packout-core' ),
			'scheduled',
			add_query_arg(
				array(
					'status'  => 'open',
					'overdue' => '1',
				),
				$missions
			),
			$levers['overdue'] ?? array(),
			'a.overdue'
		);
		echo '</ul></div></section>';
	}

	/**
	 * Outcome B: the Spots this record cleaned.
	 *
	 * @param array<string, mixed> $now    Current snapshot.
	 * @param array<string, mixed> $period Period snapshot.
	 * @param string               $spots  Where its Spots are listed.
	 */
	private static function outcome_b( array $now, array $period, string $spots ): void {
		$b    = $period['b'] ?? array();
		$cur  = $b['current'] ?? array();
		$prev = $b['previous'] ?? array();
		Admin::overview_outcome_open( 'b', __( 'Do cleaned places stay clean?', 'packout-core' ), (string) ( $b['reading'] ?? '' ) );
		echo '</div><div class="packout-overview__proof">';
		echo '<dl class="packout-overview__evidence">';
		Admin::overview_stat( __( 'Still clear', 'packout-core' ), 'b.current.still_clear', $cur['still_clear'] ?? 0, $prev['still_clear'] ?? 0 );
		Admin::overview_stat( __( 'Came back', 'packout-core' ), 'b.current.returned', $cur['returned'] ?? 0, $prev['returned'] ?? 0 );
		Admin::overview_ratio( __( 'Cleaned Spots revisited', 'packout-core' ), 'b.current.visited', $cur['visited'] ?? 0, 'b.current.cleaned_total', $cur['cleaned_total'] ?? 0 );
		echo '</dl>';
		Admin::overview_levers_open();
		Admin::overview_lever(
			__( 'Cleaned, not revisited in 90 days', 'packout-core' ),
			'update',
			add_query_arg(
				array(
					'review'  => 'publish',
					'status'  => 'cleaned',
					'revisit' => 'due',
				),
				$spots
			),
			$now['levers']['b']['due'] ?? array(),
			'b.due',
			false
		);
		echo '</ul></div></section>';
	}

	/**
	 * Outcome C, one level down: an organization's teams, a partner's organizations.
	 *
	 * @param array<string, mixed> $now      Current snapshot.
	 * @param array<string, mixed> $period   Period snapshot.
	 * @param bool                 $partner  Whether the record is a partner.
	 * @param int                  $id       The record.
	 * @param callable             $work     Builds a workspace address.
	 * @param bool                 $moderate Whether the person is a coordinator.
	 */
	private static function outcome_c( array $now, array $period, bool $partner, int $id, callable $work, bool $moderate ): void {
		$c      = $period['c'] ?? array();
		$cur    = $c['current'] ?? array();
		$prev   = $c['previous'] ?? array();
		$levers = $now['levers']['c'] ?? array();
		Admin::overview_outcome_open( 'c', __( 'Is the work shared?', 'packout-core' ), (string) ( $c['reading'] ?? '' ) );
		echo '</div><div class="packout-overview__proof">';
		echo '<dl class="packout-overview__evidence">';
		Admin::overview_ratio( $partner ? __( 'Organizations that cleaned', 'packout-core' ) : __( 'Teams that cleaned', 'packout-core' ), 'c.current.orgs_active', $cur['orgs_active'] ?? 0, 'c.current.orgs_approved', $cur['orgs_approved'] ?? 0 );
		Admin::overview_ratio( __( 'Largest share of cleanups', 'packout-core' ), 'c.current.largest_share', $cur['largest_share'] ?? 0, 'c.current.largest_of', $cur['largest_of'] ?? 0 );
		Admin::overview_stat( __( 'By a mission', 'packout-core' ), 'c.current.by_mission', $cur['by_mission'] ?? 0, $prev['by_mission'] ?? 0 );
		Admin::overview_stat( __( 'By one person', 'packout-core' ), 'c.current.by_person', $cur['by_person'] ?? 0, $prev['by_person'] ?? 0 );
		Admin::overview_ratio( __( 'Members who reported or cleaned', 'packout-core' ), 'c.current.people_active', $cur['people_active'] ?? 0, 'c.current.people_registered', $cur['people_registered'] ?? 0 );
		Admin::overview_stat( __( 'Members who joined', 'packout-core' ), 'c.current.people_joined', $cur['people_joined'] ?? 0, $prev['people_joined'] ?? 0 );
		echo '</dl>';
		Admin::overview_levers_open();
		if ( $partner ) {
			$dormant = $moderate ? add_query_arg(
				array(
					'page'    => Teams_Screen::PAGE,
					'partner' => $id,
					'dormant' => '1',
				),
				admin_url( 'admin.php' )
			) : $work( 'organizations' );
			Admin::overview_lever( __( 'Organizations with no mission in 90 days', 'packout-core' ), 'scheduled', $dormant, $levers['dormant'] ?? array(), 'c.dormant', false );
		} else {
			Admin::overview_lever( __( 'Teams with no mission in 90 days', 'packout-core' ), 'scheduled', $work( 'subteams' ), $levers['dormant'] ?? array(), 'c.dormant', false );
		}
		$invites = $moderate ? add_query_arg(
			array(
				'page'                        => Invitations_Screen::PAGE,
				'status'                      => 'expired',
				$partner ? 'partner' : 'team' => $id,
			),
			admin_url( 'admin.php' )
		) : $work( 'members' );
		Admin::overview_lever( __( 'Invitations that lapsed unused', 'packout-core' ), 'envelope', $invites, $levers['invitations'] ?? array(), 'c.invitations', false );
		echo '</ul></div></section>';
	}

	/**
	 * Outcome D: the impact records credited to this record.
	 *
	 * @param array<string, mixed>                                 $now    Current snapshot.
	 * @param array<string, mixed>                                 $period Period snapshot.
	 * @param array<string, array{0: string, 1: string, 2?: bool}> $queues Queue addresses.
	 */
	private static function outcome_d( array $now, array $period, array $queues ): void {
		$d      = $period['d'] ?? array();
		$cur    = $d['current'] ?? array();
		$levers = $now['levers']['d'] ?? array();
		Admin::overview_outcome_open( 'd', __( 'Would a funder trust the record?', 'packout-core' ), (string) ( $d['reading'] ?? '' ) );
		echo '</div><div class="packout-overview__proof">';
		echo '<dl class="packout-overview__evidence">';
		Admin::overview_ratio( __( 'Verified cleanups', 'packout-core' ), 'd.current.verified', $cur['verified'] ?? 0, 'd.current.total_cleanups', $cur['total_cleanups'] ?? 0 );
		Admin::overview_ratio( __( 'Counted publicly', 'packout-core' ), 'd.current.counted', $cur['counted'] ?? 0, 'd.current.total_cleanups', $cur['total_cleanups'] ?? 0 );
		Admin::overview_ratio( __( 'Cleaned Spots with a record', 'packout-core' ), 'd.current.cleaned_with_record', $cur['cleaned_with_record'] ?? 0, 'd.current.cleaned', $cur['cleaned'] ?? 0 );
		echo '</dl>';
		// Measured and self-reported figures sit side by side and are never added together.
		echo '<dl class="packout-overview__evidence">';
		Admin::overview_figure( __( 'Pounds measured', 'packout-core' ), 'd.current.pounds_measured', $cur['pounds_measured'] ?? 0 );
		Admin::overview_figure( __( 'Pounds self-reported', 'packout-core' ), 'd.current.pounds_self_reported', $cur['pounds_self_reported'] ?? 0 );
		Admin::overview_figure( __( 'Hours verified', 'packout-core' ), 'd.current.hours_verified', $cur['hours_verified'] ?? 0 );
		Admin::overview_figure( __( 'Hours unverified', 'packout-core' ), 'd.current.hours_unverified', $cur['hours_unverified'] ?? 0 );
		echo '</dl>';
		Admin::overview_levers_open();
		Admin::overview_lever( __( 'Unverified impact records', 'packout-core' ), 'trending-up', $queues['impact'][1] ?? '', $levers['impact'] ?? array(), 'd.impact' );
		Admin::overview_lever( __( 'Cleaned Spots without impact records', 'packout-core' ), 'caution', $queues['unrecorded'][1] ?? '', $levers['unrecorded'] ?? array(), 'd.unrecorded' );
		echo '</ul></div></section>';
	}

	/**
	 * Outcome E: how its members' reports arrived, and its missions worked from a phone.
	 *
	 * @param array<string, mixed> $period Period snapshot.
	 */
	private static function outcome_e( array $period ): void {
		$e    = $period['e'] ?? array();
		$cur  = $e['current'] ?? array();
		$prev = $e['previous'] ?? array();
		Admin::overview_outcome_open( 'e', __( 'Does it work where the mess is?', 'packout-core' ), (string) ( $e['reading'] ?? '' ) );
		echo '</div><div class="packout-overview__proof">';
		echo '<h3 class="packout-overview__label">' . esc_html__( 'How its members\' reports arrived', 'packout-core' ) . '</h3>';
		echo '<dl class="packout-overview__evidence">';
		Admin::overview_stat( __( 'Field application', 'packout-core' ), 'e.current.field', $cur['field'] ?? 0, $prev['field'] ?? 0 );
		Admin::overview_stat( __( 'Web form', 'packout-core' ), 'e.current.form', $cur['form'] ?? 0, $prev['form'] ?? 0 );
		Admin::overview_stat( __( 'No pin', 'packout-core' ), 'e.current.no_pin', $cur['no_pin'] ?? 0, $prev['no_pin'] ?? 0 );
		echo '</dl>';
		echo '<h3 class="packout-overview__label">' . esc_html__( 'Its missions in the field', 'packout-core' ) . '</h3>';
		echo '<dl class="packout-overview__evidence">';
		Admin::overview_ratio( __( 'Missions with progress synced from a phone', 'packout-core' ), 'e.current.synced', $cur['synced'] ?? 0, 'e.current.missions', $cur['missions'] ?? 0 );
		echo '</dl>';
		echo '</div></section>';
	}

	/**
	 * One line of linked counts.
	 *
	 * @param string             $name     The record's name.
	 * @param array<string, int> $counts   Counts.
	 * @param bool               $partner  Whether the record is a partner.
	 * @param int                $id       The record.
	 * @param callable           $work     Builds a workspace address.
	 * @param string             $missions Missions screen, on this record.
	 */
	private static function foot( string $name, array $counts, bool $partner, int $id, callable $work, string $missions ): void {
		if ( $partner ) {
			$items = array(
				'organizations' => array(
					__( 'organizations', 'packout-core' ),
					Teams::can_edit() ? add_query_arg(
						array(
							'page'    => Teams_Screen::PAGE,
							'partner' => $id,
						),
						admin_url( 'admin.php' )
					) : $work( 'organizations' ),
				),
				'programs'      => array(
					__( 'programs', 'packout-core' ),
					add_query_arg(
						array(
							'page'    => Programs_Screen::PAGE,
							'partner' => $id,
						),
						admin_url( 'admin.php' )
					),
				),
				'members'       => array( __( 'members across them', 'packout-core' ), $work( 'members' ) ),
				'missions'      => array( __( 'open missions', 'packout-core' ), add_query_arg( 'status', 'open', $missions ) ),
			);
		} else {
			$items = array(
				'teams'     => array( __( 'teams', 'packout-core' ), $work( 'subteams' ) ),
				'members'   => array( __( 'members', 'packout-core' ), $work( 'members' ) ),
				'missions'  => array( __( 'open missions', 'packout-core' ), add_query_arg( 'status', 'open', $missions ) ),
				'campaigns' => array( __( 'campaigns', 'packout-core' ), $work( 'campaigns' ) ),
				'places'    => array( __( 'adopted Places', 'packout-core' ), '' ),
			);
		}//end if
		echo '<section class="packout-overview__platform"><h2>' . esc_html( $name ) . '</h2><ul>';
		foreach ( $items as $key => $item ) {
			$figure = '<strong data-packout-path="platform.' . esc_attr( $key ) . '">' . esc_html( number_format_i18n( (int) ( $counts[ $key ] ?? 0 ) ) ) . '</strong> ' . esc_html( $item[0] );
			echo '<li>';
			echo '' !== $item[1] ? '<a href="' . esc_url( $item[1] ) . '">' . $figure . '</a>' : '<span>' . $figure . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			echo '</li>';
		}
		echo '</ul></section>';
	}
}
