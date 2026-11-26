<?php
/**
 * Missions screen.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The missions list and the mission drawer.
 *
 * A coordinator sees every team's missions. A lead sees the missions of the
 * teams they lead. The drawer holds the plan, the Spot picker, the checklist,
 * the hazard summary, the outcome of each Spot, and the changes from phones
 * that are waiting for the lead's decision.
 *
 * One mission's drawer is printed at a time, for the mission named in the
 * address, because the Spot picker is long.
 */
class Missions_Screen {

	public const PAGE = 'packout-missions';

	/**
	 * Most Spots the picker offers.
	 */
	private const PICKER_LIMIT = 300;

	/**
	 * Missions the current user may manage, newest first.
	 *
	 * @param string $status Optional mission status.
	 * @param int    $team   Optional team ID.
	 * @return array<int, \WP_Post>
	 */
	public static function visible( string $status = '', int $team = 0 ): array {
		$user  = get_current_user_id();
		$teams = wp_list_pluck( Missions::teams_for( $user ), 'ID' );
		if ( $team > 0 ) {
			$teams = array_intersect( $teams, array( $team ) );
		}
		if ( ! $teams ) {
			return array();
		}

		$missions = get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 200,
				'orderby'     => 'date',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Missions of the teams this person leads.
				'meta_query'  => array(
					array(
						'key'     => '_pos_team',
						'value'   => array_values( $teams ),
						'compare' => 'IN',
					),
				),
			)
		);
		if ( isset( Missions::statuses()[ $status ] ) ) {
			$missions = array_filter( $missions, static fn( $mission ) => Missions::status( $mission->ID ) === $status );
		}
		return array_values( $missions );
	}

	/**
	 * The list and the drawer.
	 */
	public static function render(): void {
		Admin::require( Missions::can_lead() );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- List filters and the open drawer.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$team   = isset( $_GET['team'] ) ? (int) $_GET['team'] : 0;
		$open   = isset( $_GET['open'] ) ? sanitize_key( wp_unslash( $_GET['open'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$teams    = Missions::teams_for( get_current_user_id() );
		$missions = self::visible();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Deep links from a program and from a partner's overview.
		$program = isset( $_GET['program'] ) ? (int) $_GET['program'] : 0;
		$partner = isset( $_GET['partner'] ) ? (int) $_GET['partner'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$narrowed = '';
		if ( $program > 0 && Programs::get( $program ) && ( Programs::shown( $program ) || Programs::can_see( $program, get_current_user_id() ) ) ) {
			$missions = array_values( array_filter( $missions, static fn( $mission ) => Programs::of_mission( $mission->ID ) === $program ) );
			/* translators: %s: program name. */
			$narrowed = sprintf( __( 'Missions of the program %s.', 'packout-core' ), get_the_title( $program ) );
		} elseif ( $partner > 0 && Partners::nameable( $partner ) ) {
			$under    = Partners::organization_ids( $partner );
			$missions = array_values( array_filter( $missions, static fn( $mission ) => in_array( Missions::team( $mission->ID ), $under, true ) ) );
			/* translators: %s: partner name. */
			$narrowed = sprintf( __( 'Missions of the organizations of %s.', 'packout-core' ), get_the_title( $partner ) );
		}
		if ( 'open' === $status ) {
			$missions = array_values( array_filter( $missions, static fn( $mission ) => Missions::is_open( $mission->ID ) ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Overview deep link.
		if ( isset( $_GET['overdue'] ) && '1' === sanitize_key( wp_unslash( $_GET['overdue'] ) ) ) {
			$overdue  = array_fill_keys( Overview::overdue_ids(), true );
			$missions = array_values(
				array_filter(
					$missions,
					static fn( $mission ) => isset( $overdue[ $mission->ID ] )
				)
			);
		}

		echo '<div class="wrap packout-admin">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Missions', 'packout-core' ) . '</h1>';
		if ( $teams ) {
			echo ' <button type="button" class="page-title-action" data-packout-open="new" data-packout-label="' . esc_attr__( 'Plan a mission', 'packout-core' ) . '">' . esc_html__( 'Plan a mission', 'packout-core' ) . '</button>';
		}
		echo '<hr class="wp-header-end" />';
		if ( '' !== $narrowed ) {
			echo '<p>' . esc_html( $narrowed ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Show every mission', 'packout-core' ) . '</a></p>';
		}

		Admin::notice(
			array(
				'saved'      => __( 'Mission saved.', 'packout-core' ),
				'started'    => __( 'Mission started. Its planned Spots are now in progress.', 'packout-core' ),
				'completed'  => __( 'Mission completed. Spots that were not marked cleaned stay open.', 'packout-core' ),
				'called_off' => __( 'Mission called off. Spots it had planned are back to spotted, unless another open mission includes them.', 'packout-core' ),
				'outcomes'   => __( 'Outcomes recorded.', 'packout-core' ),
				'partly'     => __( 'Some outcomes were recorded. A Spot flagged to be left in place cannot be marked cleaned until a coordinator clears the flag.', 'packout-core' ),
				'decided'    => __( 'Decision recorded.', 'packout-core' ),
				'trashed'    => __( 'Mission moved to the trash.', 'packout-core' ),
				'adopted'    => __( 'The Spot is on this mission. packout.spot shows that your crew plans to work on it.', 'packout-core' ),
			),
			array(
				'mission' => __( 'That mission could not be changed.', 'packout-core' ),
			)
		);

		if ( ! $teams ) {
			echo '<div class="packout-admin__empty">';
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
			echo Admin::ui(
				'empty-state',
				array(
					'title'       => __( 'No organization to plan for yet', 'packout-core' ),
					'description' => Teams::can_edit() ? __( 'A mission belongs to an organization. Add an organization first.', 'packout-core' ) : __( 'A mission belongs to an organization. Ask a coordinator to make you the lead of one.', 'packout-core' ),
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=packout-teams' ) ) . '">' . esc_html__( 'Open Organizations', 'packout-core' ) . '</a></p></div></div>';
			return;
		}

		if ( ! $missions ) {
			echo '<div class="packout-admin__empty">';
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
			echo Admin::ui(
				'empty-state',
				array(
					'title'       => __( 'No missions yet', 'packout-core' ),
					'description' => __( 'A mission gathers an organization around one or more reviewed Spots.', 'packout-core' ),
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		} else {
			$rows    = array();
			$filters = array();
			if ( isset( Missions::statuses()[ $status ] ) ) {
				$filters[] = array(
					'field' => 'status',
					'value' => Missions::statuses()[ $status ],
				);
			}
			if ( $team > 0 && Teams::get( $team ) ) {
				$filters[] = array(
					'field' => 'organization',
					'value' => Teams::get( $team )->post_title,
				);
			}
			foreach ( $missions as $mission ) {
				$id      = $mission->ID;
				$owner   = Teams::get( Missions::team( $id ) );
				$spots   = Missions::spots( $id );
				$cleaned = count( array_filter( Missions::recorded( $id ), static fn( $row ) => 'cleaned' === $row['outcome'] ) );
				$waiting = count( Missions::proposals( $id ) );
				$filed   = Programs::get( Programs::of_mission( $id ) );
				$rows[]  = array(
					'id'           => (string) $id,
					'title'        => $mission->post_title,
					'url'          => Admin::pane_url( (string) $id ),
					'organization' => $owner ? $owner->post_title : '—',
					'program'      => $filed ? $filed->post_title : '—',
					'when'         => '' !== Missions::window_label( $id ) ? Missions::window_label( $id ) : '—',
					'status'       => Missions::statuses()[ Missions::status( $id ) ] ?? '',
					'spots'        => sprintf(
						/* translators: 1: Spots cleaned, 2: Spots on the mission. */
						__( '%1$d of %2$d cleaned', 'packout-core' ),
						$cleaned,
						count( $spots )
					),
					'listing'      => 'publish' === $mission->post_status ? __( 'Public', 'packout-core' ) : __( 'Not public', 'packout-core' ),
					'decisions'    => $waiting,
				);
			}//end foreach
			Admin_Views::render(
				'missions',
				array(
					'organization' => array(
						'label'  => __( 'Organization', 'packout-core' ),
						'search' => true,
					),
					'program'      => array(
						'label'  => __( 'Program', 'packout-core' ),
						'search' => true,
					),
					'when'         => array( 'label' => __( 'When', 'packout-core' ) ),
					'status'       => array( 'label' => __( 'Status', 'packout-core' ) ),
					'spots'        => array(
						'label'  => __( 'Spots', 'packout-core' ),
						'filter' => false,
					),
					'listing'      => array( 'label' => __( 'Listing', 'packout-core' ) ),
					'decisions'    => array(
						'label' => __( 'Changes to decide', 'packout-core' ),
						'type'  => 'integer',
					),
				),
				$rows,
				array(
					'title'   => __( 'Mission', 'packout-core' ),
					'empty'   => __( 'No missions match.', 'packout-core' ),
					'filters' => $filters,
				)
			);
		}//end if

		$mission = ctype_digit( $open ) ? Missions::get( (int) $open ) : null;
		if ( $mission && ! Missions::can_manage( $mission->ID ) ) {
			$mission = null;
		}
		$shown = $missions;
		if ( $mission ) {
			$listed = false;
			foreach ( $shown as $item ) {
				if ( (int) $item->ID === (int) $mission->ID ) {
					$listed = true;
					break;
				}
			}
			if ( ! $listed ) {
				$shown[] = $mission;
			}
			Admin::reopen( (string) $mission->ID, $mission->post_title );
		} elseif ( 'new' === $open ) {
			Admin::reopen( 'new', __( 'Plan a mission', 'packout-core' ) );
		}
		Admin::drawer(
			__( 'Mission', 'packout-core' ),
			static function () use ( $shown, $teams ): void {
				foreach ( $shown as $item ) {
					echo '<div class="packout-drawer__pane packout-pane" data-pane="' . esc_attr( (string) $item->ID ) . '" hidden>';
					self::progress_sections( $item );
					self::plan_form( $item, $teams );
					/**
					 * A mission's pane, after its plan.
					 *
					 * @param \WP_Post $item The mission.
					 */
					do_action( 'packout_missions_pane', $item );
					self::closing_sections( $item );
					echo '</div>';
				}
				echo '<div class="packout-drawer__pane packout-pane" data-pane="new" hidden>';
				self::plan_form( null, $teams );
				echo '</div>';
			}
		);
		echo '</div>';
	}

	/**
	 * Status, the changes waiting for a decision, the hazard summary, and each Spot's outcome.
	 *
	 * @param \WP_Post $mission Mission.
	 */
	private static function progress_sections( \WP_Post $mission ): void {
		$id       = $mission->ID;
		$status   = Missions::status( $id );
		$open     = Missions::is_open( $id );
		$recorded = Missions::recorded( $id );
		$outcomes = Missions::outcomes();
		$spots    = array_filter( array_map( array( Spots::class, 'get' ), Missions::spots( $id ) ) );

		echo '<p class="packout-pane__badges">' . Admin::mission_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
		if ( 'publish' === $mission->post_status ) {
			echo ' <a href="' . esc_url( (string) get_permalink( $mission ) ) . '">' . esc_html__( 'Public page', 'packout-core' ) . '</a>';
		}
		echo '</p>';

		$proposals = Missions::proposals( $id );
		if ( $proposals && $open ) {
			$lines = array_column( Missions::checklist( $id ), null, 'id' );
			echo '<section><h3>' . esc_html__( 'Waiting for your decision', 'packout-core' ) . '</h3>';
			echo '<p>' . esc_html__( 'These changes came from a phone after someone else had already changed the same thing. The mission keeps the earlier answer until you choose.', 'packout-core' ) . '</p>';
			foreach ( $proposals as $proposal ) {
				list( $type, $target ) = explode( ':', $proposal['key'], 2 );
				$person                = get_userdata( $proposal['user'] );
				$name                  = $person ? $person->display_name : __( 'A crew member', 'packout-core' );
				if ( 'line' === $type ) {
					$what = '1' === $proposal['value']
						/* translators: 1: person's name, 2: checklist line. */
						? sprintf( __( '%1$s ticked “%2$s”.', 'packout-core' ), $name, $lines[ $target ]['text'] ?? '' )
						/* translators: 1: person's name, 2: checklist line. */
						: sprintf( __( '%1$s unticked “%2$s”.', 'packout-core' ), $name, $lines[ $target ]['text'] ?? '' );
				} else {
					$spot = Spots::get( (int) $target );
					/* translators: 1: person's name, 2: Spot title, 3: outcome. */
					$what = sprintf( __( '%1$s recorded “%2$s” as: %3$s.', 'packout-core' ), $name, $spot ? $spot->post_title : '', $outcomes[ $proposal['value'] ] ?? $proposal['value'] );
				}
				echo '<div class="packout-pane__hint"><span>' . esc_html( $what ) . '</span><span class="packout-pane__row">';
				$fields = array(
					'key'  => $proposal['key'],
					'from' => (string) $proposal['user'],
				);
				self::button( $id, 'decide', __( 'Use this', 'packout-core' ), $fields + array( 'accept' => '1' ) );
				self::button( $id, 'decide', __( 'Keep what is recorded', 'packout-core' ), $fields + array( 'accept' => '0' ) );
				echo '</span></div>';
			}//end foreach
			echo '</section>';
		}//end if

		$flagged = array_filter( $spots, static fn( $spot ) => 'none' !== Spots::field( $spot->ID, 'hazard', 'none' ) );
		if ( $flagged ) {
			echo '<section><h3>' . esc_html__( 'Flagged Spots', 'packout-core' ) . '</h3>';
			foreach ( $flagged as $spot ) {
				$hazard    = Spots::field( $spot->ID, 'hazard', 'none' );
				$clearance = Spots::clearance( $spot->ID );
				if ( $clearance ) {
					echo '<p class="packout-pane__note"><strong>' . esc_html( $spot->post_title ) . ': ' . esc_html( Spots::hazard_fact( $spot->ID ) ) . '.</strong> ' . esc_html( '' !== $clearance['scope'] ? $clearance['scope'] : __( 'A coordinator has recorded that this work is authorized.', 'packout-core' ) ) . '</p>';
					continue;
				}
				echo '<p class="packout-pane__note"><strong>' . esc_html( $spot->post_title ) . ': ' . esc_html( Schema::hazards()[ $hazard ] ) . '.</strong> ' . esc_html( Schema::hazard_guidance( $hazard ) ) . '</p>';
			}
			echo '<p>' . esc_html__( 'These Spots cannot be marked cleaned until a coordinator clears the flag on the Spots screen.', 'packout-core' ) . '</p></section>';
		}

		echo '<section><h3>' . esc_html__( 'Spots and outcomes', 'packout-core' ) . '</h3>';
		if ( $open ) {
			self::open_form( $id, 'outcomes' );
		}
		echo '<table class="widefat packout-pane__outcomes"><tbody>';
		foreach ( $spots as $spot ) {
			$current = $recorded[ $spot->ID ]['outcome'] ?? '';
			echo '<tr><td><strong>' . esc_html( $spot->post_title ) . '</strong><br />' . Admin::status_badge( Spots::field( $spot->ID, 'status', 'spotted' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			// A Spot this mission cleaned gets a record of the work: figures and photos.
			if ( 'cleaned' === Spots::field( $spot->ID, 'status', 'spotted' ) && (int) Spots::field( $spot->ID, 'cleaned_mission', '0' ) === $id && Impact::can_review() ) {
				$record = Impact::current( $spot->ID );
				$url    = add_query_arg(
					$record ? array( 'open' => $record->ID ) : array(
						'open' => 'new',
						'spot' => $spot->ID,
					),
					admin_url( 'admin.php?page=' . Impact_Screen::PAGE )
				);
				echo '<br /><a class="packout-pane__record" href="' . esc_url( $url ) . '">' . esc_html( $record ? ( Impact::verified( $record->ID ) ? __( 'Impact recorded and verified', 'packout-core' ) : __( 'Impact recorded, unverified', 'packout-core' ) ) : __( 'Record the work', 'packout-core' ) ) . '</a>';
			}
			echo '</td><td>';
			if ( $open ) {
				/* translators: %s: Spot title. */
				echo '<select name="outcome[' . esc_attr( (string) $spot->ID ) . ']" aria-label="' . esc_attr( sprintf( __( 'Outcome for %s', 'packout-core' ), $spot->post_title ) ) . '">';
				echo '<option value="">' . esc_html__( 'Not recorded', 'packout-core' ) . '</option>';
				foreach ( $outcomes as $value => $label ) {
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
			} else {
				echo esc_html( $outcomes[ $current ] ?? __( 'Not recorded', 'packout-core' ) );
			}
			echo '</td></tr>';
		}//end foreach
		echo '</tbody></table>';
		if ( $open ) {
			echo '<p><button class="button">' . esc_html__( 'Record outcomes', 'packout-core' ) . '</button></p></form>';
		}
		echo '</section>';

		if ( 'completed' === $status ) {
			$done = array_filter( $spots, static fn( $spot ) => 'cleaned' === ( $recorded[ $spot->ID ]['outcome'] ?? '' ) );
			echo '<section><h3>' . esc_html__( 'Done on this mission', 'packout-core' ) . '</h3>';
			if ( $done ) {
				echo '<ul class="packout-pane__done">';
				foreach ( $done as $spot ) {
					echo '<li>' . esc_html( $spot->post_title ) . '</li>';
				}
				echo '</ul>';
			} else {
				echo '<p>' . esc_html__( 'No Spot was marked cleaned.', 'packout-core' ) . '</p>';
			}
			echo '</section>';
		}
	}

	/**
	 * The plan: what, who, when, where to meet, which Spots, and the checklist.
	 *
	 * @param \WP_Post|null        $mission Mission, or null for a new one.
	 * @param array<int, \WP_Post> $teams   Teams the person may plan for.
	 */
	private static function plan_form( ?\WP_Post $mission, array $teams ): void {
		$id    = $mission ? $mission->ID : 0;
		$open  = ! $mission || Missions::is_open( $id );
		$value = static fn( string $key ) => $mission ? Missions::field( $id, $key ) : '';

		echo '<section><h3>' . esc_html__( 'Plan', 'packout-core' ) . '</h3>';
		self::open_form( $id, 'save' );

		echo '<p><label>' . esc_html__( 'Title', 'packout-core' ) . '<br /><input type="text" name="title" class="widefat" maxlength="120" required value="' . esc_attr( $mission ? $mission->post_title : '' ) . '" /></label></p>';
		echo '<p><label>' . esc_html__( 'Objective: what done means', 'packout-core' ) . '<br /><textarea name="objective" rows="3">' . esc_textarea( $value( 'objective' ) ) . '</textarea></label></p>';

		echo '<p><label>' . esc_html__( 'Organization', 'packout-core' ) . '<br /><select name="team" required>';
		foreach ( $teams as $team ) {
			echo '<option value="' . esc_attr( (string) $team->ID ) . '"' . selected( $mission ? Missions::team( $id ) : 0, $team->ID, false ) . '>' . esc_html( $team->post_title ) . '</option>';
		}
		echo '</select></label></p>';

		echo '<p class="packout-pane__pair"><label>' . esc_html__( 'Starts', 'packout-core' ) . '<br /><input type="date" name="start" value="' . esc_attr( $value( 'start' ) ) . '" /></label>';
		echo '<label>' . esc_html__( 'Ends', 'packout-core' ) . '<br /><input type="date" name="end" value="' . esc_attr( $value( 'end' ) ) . '" /></label></p>';

		echo '<p><label>' . esc_html__( 'Where the crew meets', 'packout-core' ) . '<br /><input type="text" name="meeting_note" class="widefat" maxlength="200" value="' . esc_attr( $value( 'meeting_note' ) ) . '" /></label></p>';
		echo '<p><label><input type="checkbox" name="meeting_share" value="1"' . checked( $value( 'meeting_share' ), '1', false ) . ' /> ' . esc_html__( 'Show this meeting place on the public mission page', 'packout-core' ) . '</label></p>';
		echo '<p class="packout-pane__pair"><label>' . esc_html__( 'Meeting pin latitude', 'packout-core' ) . '<br /><input type="number" name="meeting_lat" step="any" min="-90" max="90" value="' . esc_attr( $value( 'meeting_lat' ) ) . '" /></label>';
		echo '<label>' . esc_html__( 'Meeting pin longitude', 'packout-core' ) . '<br /><input type="number" name="meeting_lng" step="any" min="-180" max="180" value="' . esc_attr( $value( 'meeting_lng' ) ) . '" /></label></p>';
		echo '<p class="description">' . esc_html__( 'The pin is for the crew only. It is never shown to the public.', 'packout-core' ) . '</p>';

		echo '<p><label>' . esc_html__( 'Gear and caution', 'packout-core' ) . '<br /><textarea name="gear" rows="3">' . esc_textarea( $value( 'gear' ) ) . '</textarea></label></p>';
		echo '<p><label><input type="checkbox" name="public" value="1"' . checked( $mission && 'publish' === $mission->post_status, true, false ) . ' /> ' . esc_html__( 'Public: show the title, dates, organization, and number of Spots on the organization page', 'packout-core' ) . '</label></p>';

		if ( $open ) {
			self::picker( $id );
			self::checklist_editor( $id );
		}

		echo '<p><button class="button button-primary">' . esc_html( $mission ? __( 'Save the plan', 'packout-core' ) : __( 'Plan this mission', 'packout-core' ) ) . '</button></p></form></section>';
	}

	/**
	 * Reviewed Spots that can join the mission, with the ones already on it ticked.
	 *
	 * @param int $id Mission ID, or 0.
	 */
	private static function picker( int $id ): void {
		$linked = $id > 0 ? Missions::spots( $id ) : array();
		$found  = get_posts(
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
		$spots = array();
		foreach ( array_merge( array_filter( array_map( array( Spots::class, 'get' ), $linked ) ), $found ) as $spot ) {
			$spots[ $spot->ID ] = $spot;
		}

		echo '<fieldset class="packout-pick"><legend>' . esc_html__( 'Spots', 'packout-core' ) . '</legend>';
		if ( ! $spots ) {
			echo '<p>' . esc_html__( 'No reviewed Spot is open. A Spot can join a mission once a coordinator has published it or kept it for crews.', 'packout-core' ) . '</p></fieldset>';
			return;
		}
		echo '<input type="search" class="packout-pick__filter widefat" placeholder="' . esc_attr__( 'Filter Spots', 'packout-core' ) . '" aria-label="' . esc_attr__( 'Filter Spots', 'packout-core' ) . '" />';
		echo '<div class="packout-pick__list">';
		$statuses = Schema::statuses();
		foreach ( $spots as $spot ) {
			$category = Spots::category( $spot->ID );
			$place    = Places::of_spot( $spot->ID );
			$facts    = array( $category ? $category->name : '', $statuses[ Spots::field( $spot->ID, 'status', 'spotted' ) ] ?? '', Spots::hazard_fact( $spot->ID ), $place ? $place->post_title : '' );
			foreach ( Missions::open_with( $spot->ID, $id ) as $other ) {
				/* translators: %s: mission title. */
				$facts[] = sprintf( __( 'also on “%s”', 'packout-core' ), $other->post_title );
			}
			echo '<label class="packout-pick__item"><input type="checkbox" name="spots[]" value="' . esc_attr( (string) $spot->ID ) . '"' . checked( in_array( $spot->ID, $linked, true ), true, false ) . ' /> ';
			echo '<span><strong>' . esc_html( $spot->post_title ) . '</strong><br /><span class="description">' . esc_html( implode( ' · ', array_filter( $facts ) ) ) . '</span></span></label>';
		}
		echo '</div></fieldset>';
	}

	/**
	 * The checklist: existing lines to reword or clear, and room for new ones.
	 *
	 * @param int $id Mission ID, or 0.
	 */
	private static function checklist_editor( int $id ): void {
		$lines = $id > 0 ? Missions::checklist( $id ) : array();
		echo '<fieldset class="packout-lines"><legend>' . esc_html__( 'Checklist', 'packout-core' ) . '</legend>';
		// Present even with no lines, so clearing the last one is still an edit.
		echo '<input type="hidden" name="lines[]" value="" />';
		foreach ( $lines as $line ) {
			echo '<p class="packout-lines__line"><input type="text" name="lines[' . esc_attr( $line['id'] ) . ']" class="widefat" maxlength="200" value="' . esc_attr( $line['text'] ) . '" aria-label="' . esc_attr__( 'Checklist line', 'packout-core' ) . '" />';
			if ( $line['done'] ) {
				echo ' ' . Admin::badge( __( 'Done', 'packout-core' ), 'stable' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			}
			echo '</p>';
		}
		if ( $lines ) {
			echo '<p class="description">' . esc_html__( 'Clear a line to remove it.', 'packout-core' ) . '</p>';
		}
		echo '<p><label>' . esc_html__( 'Add lines, one per line', 'packout-core' ) . '<br /><textarea name="new_lines" rows="3"></textarea></label></p></fieldset>';
	}

	/**
	 * Start, complete, call off, and trash.
	 *
	 * @param \WP_Post $mission Mission.
	 */
	private static function closing_sections( \WP_Post $mission ): void {
		$id     = $mission->ID;
		$status = Missions::status( $id );

		if ( Missions::is_open( $id ) ) {
			echo '<section><h3>' . esc_html__( 'Start and finish', 'packout-core' ) . '</h3>';
			if ( 'planned' === $status ) {
				echo '<p>' . esc_html__( 'Starting moves the planned Spots to in progress. The first tick from the field app starts the mission too.', 'packout-core' ) . '</p>';
				self::button( $id, 'start', __( 'Start the mission', 'packout-core' ) );
			}
			self::open_form( $id, 'complete' );
			echo '<p><label>' . esc_html__( 'Spots still open when the mission completes', 'packout-core' ) . '<br /><select name="open_to">';
			echo '<option value="in_progress">' . esc_html__( 'Stay in progress', 'packout-core' ) . '</option>';
			echo '<option value="spotted">' . esc_html__( 'Go back to spotted', 'packout-core' ) . '</option>';
			echo '</select></label></p>';
			echo '<p class="description">' . esc_html__( 'Completing does not mark any Spot cleaned. Record each Spot above first.', 'packout-core' ) . '</p>';
			echo '<p><button class="button button-primary">' . esc_html__( 'Complete the mission', 'packout-core' ) . '</button></p></form>';

			echo '<details class="packout-pane__trash"><summary>' . esc_html__( 'Call off', 'packout-core' ) . '</summary>';
			echo '<p>' . esc_html__( 'Spots this mission moved to planned go back to spotted, unless another open mission includes them.', 'packout-core' ) . '</p>';
			self::button( $id, 'call_off', __( 'Call this mission off', 'packout-core' ), array(), 'button-link-delete' );
			echo '</details></section>';
			return;
		}

		echo '<section><details class="packout-pane__trash"><summary>' . esc_html__( 'Move to trash', 'packout-core' ) . '</summary>';
		echo '<p>' . esc_html__( 'The mission leaves this list and the organization page. Its Spots are not changed.', 'packout-core' ) . '</p>';
		self::button( $id, 'trash', __( 'Move this mission to the trash', 'packout-core' ), array(), 'button-link-delete' );
		echo '</details></section>';
	}

	/**
	 * Open a mission form.
	 *
	 * @param int    $id Mission ID, or 0 for a new mission.
	 * @param string $act Action name.
	 */
	private static function open_form( int $id, string $act ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-pane__form">';
		echo '<input type="hidden" name="action" value="packout_mission_action" />';
		echo '<input type="hidden" name="mission" value="' . esc_attr( (string) $id ) . '" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $act ) . '" />';
		wp_nonce_field( 'packout_mission_action_' . $id );
	}

	/**
	 * A mission action that is one button.
	 *
	 * @param int                   $id     Mission ID.
	 * @param string                $act     Action name.
	 * @param string                $label  Button label.
	 * @param array<string, string> $fields Extra hidden fields.
	 * @param string                $classes  Extra button class.
	 */
	private static function button( int $id, string $act, string $label, array $fields = array(), string $classes = '' ): void {
		self::open_form( $id, $act );
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		echo '<button class="button ' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Apply a mission action and return to the list.
	 */
	public static function action(): void {
		Admin::require( Missions::can_lead() );
		$id = isset( $_POST['mission'] ) ? (int) $_POST['mission'] : 0;
		check_admin_referer( 'packout_mission_action_' . $id );
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$user = get_current_user_id();

		if ( $id > 0 && ! ( Missions::get( $id ) && Missions::can_manage( $id, $user ) ) ) {
			Admin::back( self::PAGE, array( 'error' => 'mission' ) );
		}

		$text   = static fn( string $key ) => isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		$result = array( 'error' => 'mission' );
		$done   = null;

		switch ( $do ) {
			case 'save':
				$lines = array();
				foreach ( isset( $_POST['lines'] ) && is_array( $_POST['lines'] ) ? wp_unslash( $_POST['lines'] ) : array() as $line => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each line is sanitized below.
					$lines[ sanitize_key( (string) $line ) ] = sanitize_text_field( (string) $value );
				}
				$done = Missions::save(
					$id,
					array(
						'title'         => $text( 'title' ),
						'objective'     => $text( 'objective' ),
						'team'          => isset( $_POST['team'] ) ? (int) $_POST['team'] : 0,
						'spots'         => isset( $_POST['spots'] ) && is_array( $_POST['spots'] ) ? array_map( 'intval', wp_unslash( $_POST['spots'] ) ) : array(),
						'start'         => $text( 'start' ),
						'end'           => $text( 'end' ),
						'meeting_note'  => $text( 'meeting_note' ),
						'meeting_share' => ! empty( $_POST['meeting_share'] ),
						'meeting_lat'   => $text( 'meeting_lat' ),
						'meeting_lng'   => $text( 'meeting_lng' ),
						'gear'          => $text( 'gear' ),
						'public'        => ! empty( $_POST['public'] ),
						'lines'         => $lines,
						'new_lines'     => $text( 'new_lines' ),
					),
					$user
				);
				if ( ! is_wp_error( $done ) ) {
					$id     = (int) $done;
					$result = array( 'updated' => 'saved' );
				}
				break;
			case 'start':
				$done   = Missions::set_status( $id, 'in_progress' );
				$result = array( 'updated' => 'started' );
				break;
			case 'complete':
				$done   = Missions::set_status( $id, 'completed', isset( $_POST['open_to'] ) && 'spotted' === $_POST['open_to'] ? 'spotted' : 'in_progress' );
				$result = array( 'updated' => 'completed' );
				break;
			case 'call_off':
				$done   = Missions::set_status( $id, 'called_off' );
				$result = array( 'updated' => 'called_off' );
				break;
			case 'outcomes':
				$outcomes = array();
				foreach ( isset( $_POST['outcome'] ) && is_array( $_POST['outcome'] ) ? wp_unslash( $_POST['outcome'] ) : array() as $spot => $outcome ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized below.
					if ( '' !== $outcome ) {
						$outcomes[ (int) $spot ] = sanitize_key( (string) $outcome );
					}
				}
				// The lead's own record wins over an earlier one from a phone.
				$done = Missions::progress(
					$id,
					$user,
					array(
						'revision' => Missions::revision( $id ),
						'force'    => true,
						'outcomes' => $outcomes,
					)
				);
				if ( ! is_wp_error( $done ) ) {
					$result = array( 'updated' => $done['refused'] ? 'partly' : 'outcomes' );
				}
				break;
			case 'decide':
				$done   = Missions::decide( $id, isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '', isset( $_POST['from'] ) ? (int) $_POST['from'] : 0, ! empty( $_POST['accept'] ), $user );
				$result = array( 'updated' => 'decided' );
				break;
			case 'trash':
				if ( $id > 0 && ! Missions::is_open( $id ) && wp_trash_post( $id ) ) {
					Admin::back( self::PAGE, array( 'updated' => 'trashed' ) );
				}
				break;
		}//end switch

		if ( is_wp_error( $done ) ) {
			$result = array(
				'error'  => 'detail',
				'detail' => $done->get_error_message(),
			);
		}
		$result['open'] = $id > 0 ? (string) $id : 'new';
		Admin::back( self::PAGE, $result );
	}
}
