<?php
/**
 * The Impact screen.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Totals for a date range, the list of impact records, and a drawer for one.
 *
 * The drawer shows the photos from before and after side by side, the
 * figures with where each came from, and the verification. A lead or a
 * coordinator verifies a record or returns it to unverified. A coordinator
 * decides whether the public totals count it.
 */
class Impact_Screen {

	public const PAGE = 'packout-impact';

	/**
	 * The totals, the list, and the drawer.
	 */
	public static function render(): void {
		Admin::require( Impact::can_review() );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- List filters and the open drawer.
		$filters = array(
			'verification' => isset( $_GET['verification'] ) ? sanitize_key( wp_unslash( $_GET['verification'] ) ) : '',
			'kind'         => isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '',
			'team'         => isset( $_GET['team'] ) ? (int) $_GET['team'] : 0,
			'partner'      => isset( $_GET['partner'] ) ? (int) $_GET['partner'] : 0,
			'from'         => isset( $_GET['from'] ) ? Impact::date( sanitize_text_field( wp_unslash( $_GET['from'] ) ) ) : '',
			'to'           => isset( $_GET['to'] ) ? Impact::date( sanitize_text_field( wp_unslash( $_GET['to'] ) ) ) : '',
		);
		$open    = isset( $_GET['open'] ) ? sanitize_key( wp_unslash( $_GET['open'] ) ) : '';
		$spot_id = isset( $_GET['spot'] ) ? (int) $_GET['spot'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$base = admin_url( 'admin.php?page=' . self::PAGE );
		// One organization, or every organization of a partner. An organization named in the address wins.
		$within = array(
			'team' => $filters['team'],
			'from' => $filters['from'],
			'to'   => $filters['to'],
		);
		if ( $filters['team'] <= 0 && $filters['partner'] > 0 && Partners::nameable( $filters['partner'] ) ) {
			$within['teams'] = Partners::organization_ids( $filters['partner'] );
		} else {
			$filters['partner'] = 0;
		}
		$records = Impact::listed( $within );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Overview deep link.
		$counted = isset( $_GET['counted'] ) ? sanitize_key( wp_unslash( $_GET['counted'] ) ) : '';
		if ( 'no' === $counted ) {
			$records = array_values(
				array_filter(
					$records,
					static fn( \WP_Post $record ): bool => 'private' === $record->post_status && Impact::verified( $record->ID )
				)
			);
		}
		$teams = Impact::coordinates() ? Teams::all() : Missions::teams_for( get_current_user_id() );

		echo '<div class="wrap packout-admin">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Impact', 'packout-core' ) . '</h1>';
		echo '<hr class="wp-header-end" />';

		Admin::notice(
			array(
				'saved'      => __( 'Record saved.', 'packout-core' ),
				'verified'   => __( 'Record verified.', 'packout-core' ),
				'unverified' => __( 'Record returned to unverified.', 'packout-core' ),
				'shown'      => __( 'The public totals now count this record.', 'packout-core' ),
				'hidden'     => __( 'The public totals no longer count this record.', 'packout-core' ),
				'trashed'    => __( 'Record moved to the trash. Its photos stay in the media library.', 'packout-core' ),
			),
			array(
				'record' => __( 'That record could not be changed.', 'packout-core' ),
			)
		);

		echo '<form method="get" class="packout-admin__filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		echo '<label>' . esc_html__( 'From', 'packout-core' ) . ' <input type="date" name="from" value="' . esc_attr( $filters['from'] ) . '" /></label> ';
		echo '<label>' . esc_html__( 'To', 'packout-core' ) . ' <input type="date" name="to" value="' . esc_attr( $filters['to'] ) . '" /></label> ';
		echo '<select name="team" aria-label="' . esc_attr__( 'Every organization', 'packout-core' ) . '"><option value="0">' . esc_html__( 'Every organization', 'packout-core' ) . '</option>';
		foreach ( $teams as $option ) {
			echo '<option value="' . esc_attr( (string) $option->ID ) . '"' . selected( $filters['team'], $option->ID, false ) . '>' . esc_html( $option->post_title ) . '</option>';
		}
		echo '</select>';
		if ( $filters['partner'] > 0 ) {
			echo '<input type="hidden" name="partner" value="' . esc_attr( (string) $filters['partner'] ) . '" />';
		}
		echo '<button class="button">' . esc_html__( 'Filter', 'packout-core' ) . '</button>';
		if ( $filters['team'] > 0 || $filters['partner'] > 0 || '' !== $filters['from'] || '' !== $filters['to'] ) {
			echo ' <a href="' . esc_url( $base ) . '">' . esc_html__( 'Clear', 'packout-core' ) . '</a>';
		}
		echo '</form>';

		self::totals( Impact::totals( $within ), $filters );
		self::unrecorded( $base, $filters['team'] > 0 ? array( $filters['team'] ) : ( $within['teams'] ?? null ) );

		if ( ! $records ) {
			echo '<div class="packout-admin__empty">';
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
			echo Admin::ui(
				'empty-state',
				array(
					'title'       => ( $filters['team'] > 0 || $filters['partner'] > 0 || '' !== $filters['from'] || '' !== $filters['to'] ) ? __( 'No records match these filters', 'packout-core' ) : __( 'No impact records yet', 'packout-core' ),
					'description' => __( 'A record is written when a lead documents a cleaned Spot, when a reporter documents their own cleanup, or when someone files a return visit.', 'packout-core' ),
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		} else {
			$view_filters = array();
			if ( isset( Impact::kinds()[ $filters['kind'] ] ) ) {
				$view_filters[] = array(
					'field' => 'record',
					'value' => Impact::kinds()[ $filters['kind'] ],
				);
			}
			if ( isset( Impact::verifications()[ $filters['verification'] ] ) ) {
				$view_filters[] = array(
					'field' => 'verification',
					'value' => Impact::verifications()[ $filters['verification'] ],
				);
			}
			if ( 'no' === $counted ) {
				$view_filters[] = array(
					'field' => 'totals',
					'value' => __( 'Not counted', 'packout-core' ),
				);
			}
			$rows = array();
			foreach ( $records as $record ) {
				$row    = Impact::payload( $record );
				$rows[] = array(
					'id'           => (string) $record->ID,
					'title'        => $row['spot_title'],
					'url'          => Admin::pane_url( (string) $record->ID ),
					'record'       => (string) $row['kind_label'],
					'organization' => '' !== $row['team_name'] ? $row['team_name'] : __( 'No organization', 'packout-core' ),
					'date'         => '' !== Impact::date( (string) $row['date'] ) ? Impact::date( (string) $row['date'] ) : '—',
					'verification' => Impact::verifications()[ $row['verification'] ] ?? $row['verification'],
					'figures'      => '' !== Impact::summary( $record->ID ) ? Impact::summary( $record->ID ) : '—',
					'totals'       => Impact::CLEANUP !== $row['kind'] ? '—' : ( $row['visible'] ? __( 'Counted', 'packout-core' ) : __( 'Not counted', 'packout-core' ) ),
				);
			}
			Admin_Views::render(
				'impact',
				array(
					'record'       => array( 'label' => __( 'Record', 'packout-core' ) ),
					'organization' => array(
						'label'  => __( 'Organization', 'packout-core' ),
						'search' => true,
					),
					'date'         => array( 'label' => __( 'Date', 'packout-core' ) ),
					'verification' => array( 'label' => __( 'Verification', 'packout-core' ) ),
					'figures'      => array(
						'label'  => __( 'Figures', 'packout-core' ),
						'filter' => false,
					),
					'totals'       => array( 'label' => __( 'Public totals', 'packout-core' ) ),
				),
				$rows,
				array(
					'title'   => __( 'Spot', 'packout-core' ),
					'empty'   => __( 'No records match.', 'packout-core' ),
					'filters' => $view_filters,
					'sort'    => array(
						'field'     => 'date',
						'direction' => 'desc',
					),
				)
			);
		}//end if

		$record = ctype_digit( $open ) ? Impact::get( (int) $open ) : null;
		if ( $record && ! Impact::can_see( $record->ID ) ) {
			$record = null;
		}
		$spot = 'new' === $open ? Spots::get( $spot_id ) : null;
		if ( $spot && Impact::current( $spot->ID ) ) {
			// The Spot's cleaning already has a record. Open that one.
			$record = Impact::current( $spot->ID );
			$open   = (string) $record->ID;
			$spot   = null;
		}
		$panes = $records;
		if ( $record ) {
			$listed = false;
			foreach ( $panes as $item ) {
				if ( (int) $item->ID === (int) $record->ID ) {
					$listed = true;
					break;
				}
			}
			if ( ! $listed ) {
				$panes[] = $record;
			}
			Admin::reopen( (string) $record->ID, $record->post_title );
		} elseif ( $spot ) {
			Admin::reopen( 'new', $spot->post_title );
		}
		if ( $panes || $spot ) {
			Admin::drawer(
				__( 'Impact', 'packout-core' ),
				static function () use ( $panes, $spot ): void {
					foreach ( $panes as $item ) {
						echo '<div class="packout-drawer__pane packout-pane" data-pane="' . esc_attr( (string) $item->ID ) . '" hidden>';
						self::pane( $item );
						echo '</div>';
					}
					if ( $spot ) {
						echo '<div class="packout-drawer__pane packout-pane" data-pane="new" hidden>';
						self::new_pane( $spot );
						echo '</div>';
					}
				}
			);
		}
		echo '</div>';
	}

	/**
	 * Three groups of figures: effort, outputs, outcomes.
	 *
	 * Self-reported and measured amounts sit in separate cells and are never
	 * added together.
	 *
	 * @param array<string, array<string, int|float>> $totals  From Impact::totals().
	 * @param array<string, mixed>                    $filters The screen's filters.
	 */
	private static function totals( array $totals, array $filters ): void {
		$scope = $filters['team'] > 0 && Teams::get( $filters['team'] ) ? Teams::get( $filters['team'] )->post_title : __( 'Every organization', 'packout-core' );
		if ( ! empty( $filters['partner'] ) ) {
			/* translators: %s: partner name. */
			$scope = sprintf( __( 'Organizations of %s', 'packout-core' ), get_the_title( (int) $filters['partner'] ) );
		}
		if ( '' !== $filters['from'] || '' !== $filters['to'] ) {
			/* translators: 1: organization name or "Every organization", 2: first day or "the start", 3: last day or "today". */
			$scope = sprintf( __( '%1$s, %2$s to %3$s', 'packout-core' ), $scope, '' !== $filters['from'] ? self::day( $filters['from'] ) : __( 'the start', 'packout-core' ), '' !== $filters['to'] ? self::day( $filters['to'] ) : __( 'today', 'packout-core' ) );
		}

		echo '<section class="packout-totals" aria-label="' . esc_attr__( 'Totals', 'packout-core' ) . '">';
		echo '<h2>' . esc_html__( 'Totals', 'packout-core' ) . ' <span class="packout-totals__scope">' . esc_html( $scope ) . '</span></h2>';
		echo '<div class="packout-totals__groups">';

		self::group(
			__( 'Effort', 'packout-core' ),
			array(
				__( 'Hours, verified', 'packout-core' )    => Impact::number( (float) $totals['effort']['hours_verified'] ),
				__( 'Hours, unverified', 'packout-core' )  => Impact::number( (float) $totals['effort']['hours_unverified'] ),
				__( 'People', 'packout-core' )             => number_format_i18n( (int) $totals['effort']['people'] ),
				__( 'Missions completed', 'packout-core' ) => number_format_i18n( (int) $totals['effort']['missions'] ),
				__( 'Organizations active', 'packout-core' ) => number_format_i18n( (int) $totals['effort']['teams'] ),
			)
		);
		self::group(
			__( 'Outputs', 'packout-core' ),
			array(
				__( 'Spots cleaned', 'packout-core' )    => number_format_i18n( (int) $totals['outputs']['spots'] ),
				__( 'Bags, measured', 'packout-core' )   => number_format_i18n( (int) $totals['outputs']['bags_measured'] ),
				__( 'Bags, self-reported', 'packout-core' ) => number_format_i18n( (int) $totals['outputs']['bags_self_reported'] ),
				__( 'Pounds, measured', 'packout-core' ) => Impact::number( (float) $totals['outputs']['pounds_measured'] ),
				__( 'Pounds, self-reported', 'packout-core' ) => Impact::number( (float) $totals['outputs']['pounds_self_reported'] ),
			)
		);
		self::group(
			__( 'Outcomes', 'packout-core' ),
			array(
				__( 'Spots with a later still-clear visit', 'packout-core' ) => number_format_i18n( (int) $totals['outcomes']['still_clear'] ),
				__( 'Spots where the problem came back', 'packout-core' )    => number_format_i18n( (int) $totals['outcomes']['returned'] ),
			)
		);
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Every figure is the sum of impact records, counted or not in the public totals. Measured and self-reported amounts are kept apart.', 'packout-core' ) . '</p>';
		echo '</section>';
	}

	/**
	 * One group of figures.
	 *
	 * @param string                $title   Group name.
	 * @param array<string, string> $figures Label => formatted number.
	 */
	private static function group( string $title, array $figures ): void {
		echo '<div class="packout-totals__group"><h3>' . esc_html( $title ) . '</h3><dl>';
		foreach ( $figures as $label => $value ) {
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		echo '</dl></div>';
	}

	/**
	 * Cleaned Spots nobody has written up, with a way to do it.
	 *
	 * @param string               $base  Address of this screen.
	 * @param array<int, int>|null $teams Only the Spots of these organizations, or null for all.
	 */
	private static function unrecorded( string $base, ?array $teams = null ): void {
		$spots = Impact::unrecorded();
		if ( null !== $teams ) {
			$theirs = array_fill_keys( Overview_Scope::spot_ids( $teams ), true );
			$spots  = array_values( array_filter( $spots, static fn( $spot ) => isset( $theirs[ $spot->ID ] ) ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Overview deep link.
		$asked = isset( $_GET['unrecorded'] ) && '1' === sanitize_key( wp_unslash( $_GET['unrecorded'] ) );
		if ( ! $spots && ! $asked ) {
			return;
		}
		echo '<section id="packout-unrecorded" class="packout-totals__missing"><h2>' . esc_html__( 'Cleaned, with no record yet', 'packout-core' ) . '</h2>';
		if ( ! $spots ) {
			echo '<p>' . esc_html__( 'Every cleaned Spot has a record of the work.', 'packout-core' ) . '</p></section>';
			return;
		}
		echo '<p>' . esc_html__( 'These Spots were marked cleaned and nobody has recorded the work, so the totals leave them out.', 'packout-core' ) . '</p><ul>';
		foreach ( $spots as $spot ) {
			$url = add_query_arg(
				array(
					'open' => 'new',
					'spot' => $spot->ID,
				),
				$base
			);
			echo '<li>' . esc_html( $spot->post_title ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Record the work', 'packout-core' ) . '</a></li>';
		}
		echo '</ul></section>';
	}

	/**
	 * The drawer for one record.
	 *
	 * @param \WP_Post $record Record.
	 */
	private static function pane( \WP_Post $record ): void {
		$id      = $record->ID;
		$row     = Impact::payload( $record );
		$cleanup = Impact::CLEANUP === $row['kind'];
		$spot    = Spots::get( $row['spot'] );

		echo '<section><p class="packout-pane__badges">' . self::kind_badge( $row['kind'] ) . ' ' . self::verification_badge( $row['verification'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
		if ( $cleanup && $row['visible'] ) {
			echo ' ' . Admin::badge( __( 'In the public totals', 'packout-core' ), 'low' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
		}
		echo '</p>';
		$facts = array( $row['spot_title'], self::day( $row['date'] ), '' !== $row['team_name'] ? $row['team_name'] : __( 'No organization', 'packout-core' ) );
		if ( '' !== $row['person'] ) {
			/* translators: %s: person's name. */
			$facts[] = sprintf( $cleanup ? __( 'cleaned by %s', 'packout-core' ) : __( 'visited by %s', 'packout-core' ), $row['person'] );
		}
		echo '<p>' . esc_html( implode( ' · ', $facts ) ) . '</p>';
		if ( 'verified' === $row['verification'] ) {
			/* translators: 1: person's name, 2: date. */
			echo '<p class="packout-pane__note">' . esc_html( sprintf( __( 'Verified by %1$s on %2$s.', 'packout-core' ), '' !== $row['verified_by'] ? $row['verified_by'] : __( 'a former member', 'packout-core' ), self::day( substr( $row['verified_at'], 0, 10 ) ) ) );
			if ( $row['own_work'] ) {
				echo ' ' . esc_html__( 'This person took part in the work or leads the crew that did it.', 'packout-core' );
			}
			echo '</p>';
		}
		echo '</section>';

		self::photos( $spot ? $spot->ID : 0, $id, $cleanup );

		if ( ! $cleanup ) {
			echo '<section><h3>' . esc_html__( 'What the visitor found', 'packout-core' ) . '</h3><p>' . nl2br( esc_html( $record->post_content ) ) . '</p>';
			echo '<p class="description">' . esc_html( Impact::RETURNED === $row['kind'] ? __( 'This visit moved the Spot to watch. It is open for a new mission.', 'packout-core' ) : __( 'This visit left the Spot cleaned. It counts as evidence that the work held.', 'packout-core' ) ) . '</p></section>';
		} elseif ( Impact::can_edit( $id ) ) {
			echo '<section><h3>' . esc_html__( 'Figures', 'packout-core' ) . '</h3>';
			self::form( $id, $row['spot'], $row, __( 'Save the record', 'packout-core' ) );
			echo '</section>';
		} else {
			self::figures( $row, $record->post_content );
		}

		if ( Impact::can_verify( $id ) ) {
			echo '<section><h3>' . esc_html__( 'Verification', 'packout-core' ) . '</h3>';
			if ( 'verified' === $row['verification'] ) {
				echo '<p>' . esc_html__( 'Returning the record makes it unverified again, so its figures can be corrected.', 'packout-core' ) . '</p>';
				self::button( $id, 'unverify', __( 'Return to unverified', 'packout-core' ) );
			} else {
				echo '<p>' . esc_html__( 'Verify when the photos and the figures agree with what you know happened. You do not have to be someone who did the work.', 'packout-core' ) . '</p>';
				self::button( $id, 'verify', __( 'Verify this record', 'packout-core' ), 'button-primary' );
			}
			echo '</section>';
		}

		if ( $cleanup && Impact::coordinates() ) {
			echo '<section><h3>' . esc_html__( 'Public totals', 'packout-core' ) . '</h3>';
			if ( $row['visible'] ) {
				echo '<p>' . esc_html__( 'The Impact summary on the website counts this record.', 'packout-core' ) . '</p>';
				self::button( $id, 'hide', __( 'Stop counting it publicly', 'packout-core' ) );
			} else {
				echo '<p>' . esc_html__( 'The Impact summary on the website does not count this record. Photos and names are never shown there.', 'packout-core' ) . '</p>';
				self::button( $id, 'show', __( 'Count it in the public totals', 'packout-core' ) );
			}
			echo '</section>';
		}

		if ( Impact::coordinates() ) {
			echo '<section><h3>' . esc_html__( 'Remove', 'packout-core' ) . '</h3>';
			echo '<p>' . esc_html__( 'The record leaves the totals and this list. The Spot keeps its status.', 'packout-core' ) . '</p>';
			self::button( $id, 'trash', __( 'Move to the trash', 'packout-core' ), 'button-link-delete' );
			echo '</section>';
		}
	}

	/**
	 * The drawer for the first record of a cleaned Spot.
	 *
	 * @param \WP_Post $spot Spot.
	 */
	private static function new_pane( \WP_Post $spot ): void {
		$category = Spots::category( $spot->ID );
		$mission  = Missions::get( (int) Spots::field( $spot->ID, 'cleaned_mission', '0' ) );

		echo '<section><p class="packout-pane__badges">' . Admin::status_badge( Spots::field( $spot->ID, 'status', 'spotted' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
		/* translators: %s: mission title. */
		echo '<p>' . esc_html( $mission ? sprintf( __( 'Cleaned on the mission “%s”.', 'packout-core' ), $mission->post_title ) : __( 'Cleaned with no mission.', 'packout-core' ) ) . '</p></section>';
		self::photos( $spot->ID, 0, true );
		echo '<section><h3>' . esc_html__( 'Record the work', 'packout-core' ) . '</h3>';
		self::form( 0, $spot->ID, array(), Schema::done_word( $category ? $category->slug : '' ) );
		echo '</section>';
	}

	/**
	 * Before and after, side by side.
	 *
	 * @param int  $spot_id Spot ID. Its photos are the "before".
	 * @param int  $id      Record ID, or 0.
	 * @param bool $cleanup Whether this is a cleanup record.
	 */
	private static function photos( int $spot_id, int $id, bool $cleanup ): void {
		$sides = array(
			$cleanup ? __( 'Before: the Spot as reported', 'packout-core' ) : __( 'The Spot as reported', 'packout-core' ) => $spot_id > 0 ? Photos::for_spot( $spot_id ) : array(),
			$cleanup ? __( 'After the work', 'packout-core' ) : __( 'On this visit', 'packout-core' ) => $id > 0 ? Photos::for_spot( $id ) : array(),
		);
		echo '<section><h3>' . esc_html__( 'Photos', 'packout-core' ) . '</h3><div class="packout-compare">';
		foreach ( $sides as $title => $photos ) {
			echo '<div class="packout-compare__side"><h4>' . esc_html( $title ) . '</h4>';
			if ( $photos ) {
				echo '<div class="packout-pane__photos">';
				foreach ( $photos as $photo ) {
					echo '<a href="' . esc_url( $photo['full'] ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $photo['url'] ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" /></a>';
				}
				echo '</div>';
				// Where each photo says it was taken, against the Spot's pin.
				$taken = $spot_id > 0 ? array_filter( array_map( static fn( $photo ) => Photos::distance_line( (int) $photo['id'], $spot_id ), $photos ) ) : array();
				foreach ( array_unique( $taken ) as $line ) {
					echo '<p class="description packout-compare__taken">' . esc_html( $line ) . '</p>';
				}
			} else {
				echo '<p class="description">' . esc_html__( 'No photos.', 'packout-core' ) . '</p>';
			}
			echo '</div>';
		}
		echo '</div></section>';
	}

	/**
	 * The figures of a record that this person cannot change.
	 *
	 * @param array<string, mixed> $row       From Impact::payload().
	 * @param string               $narrative What changed.
	 */
	private static function figures( array $row, string $narrative ): void {
		$sources = Impact::sources();
		echo '<section><h3>' . esc_html__( 'Figures', 'packout-core' ) . '</h3>';
		if ( '' !== trim( $narrative ) ) {
			echo '<p>' . nl2br( esc_html( $narrative ) ) . '</p>';
		}
		echo '<dl class="packout-pane__facts">';
		echo '<div><dt>' . esc_html__( 'Hours', 'packout-core' ) . '</dt><dd>' . esc_html( Impact::number( (float) $row['hours'] ) ) . '</dd></div>';
		echo '<div><dt>' . esc_html__( 'People present', 'packout-core' ) . '</dt><dd>' . esc_html( number_format_i18n( (int) $row['people'] ) ) . '</dd></div>';
		echo '<div><dt>' . esc_html__( 'Bags', 'packout-core' ) . '</dt><dd>' . esc_html( number_format_i18n( (int) $row['bags'] ) . ', ' . strtolower( $sources[ $row['bags_source'] ] ) ) . '</dd></div>';
		echo '<div><dt>' . esc_html__( 'Pounds', 'packout-core' ) . '</dt><dd>' . esc_html( Impact::number( (float) $row['pounds'] ) . ', ' . strtolower( $sources[ $row['pounds_source'] ] ) ) . '</dd></div>';
		echo '<div><dt>' . esc_html__( 'Come back', 'packout-core' ) . '</dt><dd>' . esc_html( $row['come_back'] ? trim( __( 'Yes', 'packout-core' ) . '. ' . $row['come_back_note'] ) : __( 'No', 'packout-core' ) ) . '</dd></div>';
		echo '</dl></section>';
	}

	/**
	 * The form for the figures of a cleanup record.
	 *
	 * @param int                  $id      Record ID, or 0 for a new one.
	 * @param int                  $spot_id Spot ID.
	 * @param array<string, mixed> $row     From Impact::payload(), or empty.
	 * @param string               $submit  Label of the primary button.
	 */
	private static function form( int $id, int $spot_id, array $row, string $submit ): void {
		$record   = $id > 0 ? Impact::get( $id ) : null;
		$mission  = (int) Spots::field( $spot_id, 'cleaned_mission', '0' );
		$team     = $mission > 0 ? Missions::team( $mission ) : 0;
		$present  = $id > 0 ? array_map( 'intval', get_post_meta( $id, '_pos_person', false ) ) : array();
		$verified = 'verified' === ( $row['verification'] ?? '' );

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-pane__form">';
		echo '<input type="hidden" name="action" value="packout_impact_action" />';
		echo '<input type="hidden" name="impact" value="' . esc_attr( (string) $id ) . '" />';
		echo '<input type="hidden" name="spot" value="' . esc_attr( (string) $spot_id ) . '" />';
		echo '<input type="hidden" name="do" value="save" />';
		wp_nonce_field( 'packout_impact_action_' . $id );

		if ( $verified ) {
			echo '<p class="packout-pane__note">' . esc_html__( 'This record is verified. A change here is yours to stand behind.', 'packout-core' ) . '</p>';
		}
		echo '<p><label>' . esc_html__( 'What changed', 'packout-core' ) . '<br /><textarea name="narrative" rows="3">' . esc_textarea( $record ? $record->post_content : '' ) . '</textarea></label></p>';
		echo '<p><label>' . esc_html__( 'Day of the work', 'packout-core' ) . '<br /><input type="date" name="date" max="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" value="' . esc_attr( (string) ( $row['date'] ?? wp_date( 'Y-m-d' ) ) ) . '" /></label></p>';

		echo '<div class="packout-pane__pair">';
		echo '<p><label>' . esc_html__( 'Hours, all people together', 'packout-core' ) . '<br /><input type="number" name="hours" min="0" step="0.25" value="' . esc_attr( (string) ( $row['hours'] ?? '' ) ) . '" /></label></p>';
		echo '<p><label>' . esc_html__( 'People present', 'packout-core' ) . '<br /><input type="number" name="people" min="0" step="1" value="' . esc_attr( (string) ( $row['people'] ?? '' ) ) . '" /></label></p>';
		echo '</div>';

		if ( $team > 0 ) {
			$crew = array_unique( array_merge( array( Teams::lead( $team ) ), Teams::members( $team ) ) );
			echo '<fieldset class="packout-pick"><legend>' . esc_html__( 'Who was there', 'packout-core' ) . '</legend><div class="packout-pick__list">';
			foreach ( $crew as $person ) {
				$user = get_userdata( $person );
				if ( $user ) {
					echo '<label class="packout-pick__item"><input type="checkbox" name="persons[]" value="' . esc_attr( (string) $person ) . '"' . checked( in_array( $person, $present, true ), true, false ) . ' /> <span>' . esc_html( $user->display_name ) . '</span></label>';
				}
			}
			echo '</div></fieldset>';
		}

		foreach ( array(
			'bags'   => array( __( 'Bags', 'packout-core' ), '1' ),
			'pounds' => array( __( 'Weight in pounds', 'packout-core' ), '0.1' ),
		) as $key => $labels ) {
			echo '<div class="packout-pane__pair">';
			echo '<p><label>' . esc_html( $labels[0] ) . '<br /><input type="number" name="' . esc_attr( $key ) . '" min="0" step="' . esc_attr( $labels[1] ) . '" value="' . esc_attr( (string) ( $row[ $key ] ?? '' ) ) . '" /></label></p>';
			/* translators: %s: Bags, or Weight in pounds. */
			echo '<p><label>' . esc_html( sprintf( __( '%s: where the number comes from', 'packout-core' ), $labels[0] ) ) . '<br /><select name="' . esc_attr( $key ) . '_source">';
			foreach ( Impact::sources() as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $row[ $key . '_source' ] ?? 'self_reported', $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></label></p></div>';
		}
		echo '<p class="description">' . esc_html__( 'Measured means counted or weighed. An estimate is self-reported.', 'packout-core' ) . '</p>';

		echo '<p><label><input type="checkbox" name="come_back" value="1"' . checked( ! empty( $row['come_back'] ), true, false ) . ' /> ' . esc_html__( 'This place needs another visit', 'packout-core' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Why, and what to bring', 'packout-core' ) . '<br /><input type="text" name="come_back_note" class="widefat" value="' . esc_attr( (string) ( $row['come_back_note'] ?? '' ) ) . '" /></label></p>';

		/* translators: %d: number of photos. */
		echo '<p><label>' . esc_html__( 'Add photos taken after the work', 'packout-core' ) . '<br /><input type="file" name="photos[]" accept="' . esc_attr( Photos::accept() ) . '" multiple /></label><br /><span class="description">' . esc_html( sprintf( __( 'A record holds up to %d photos. They are shown on the Spot\'s page unless the Spot\'s photos are hidden.', 'packout-core' ), Photos::LIMIT ) ) . '</span></p>';

		echo '<p><button class="button button-primary">' . esc_html( $submit ) . '</button></p></form>';
	}

	/**
	 * A record action that is one button.
	 *
	 * @param int    $id    Record ID.
	 * @param string $act    Action name.
	 * @param string $label Button label.
	 * @param string $classes Extra button class.
	 */
	private static function button( int $id, string $act, string $label, string $classes = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-pane__form">';
		echo '<input type="hidden" name="action" value="packout_impact_action" />';
		echo '<input type="hidden" name="impact" value="' . esc_attr( (string) $id ) . '" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $act ) . '" />';
		wp_nonce_field( 'packout_impact_action_' . $id );
		echo '<button class="button ' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * A badge for the kind of record.
	 *
	 * @param string $kind Kind.
	 */
	public static function kind_badge( string $kind ): string {
		$intents = array(
			Impact::CLEANUP     => 'stable',
			Impact::STILL_CLEAR => 'informational',
			Impact::RETURNED    => 'high',
		);
		return Admin::badge( Impact::kinds()[ $kind ] ?? $kind, $intents[ $kind ] ?? 'none' );
	}

	/**
	 * A badge for the verification state.
	 *
	 * @param string $state unverified or verified.
	 */
	public static function verification_badge( string $state ): string {
		return Admin::badge( Impact::verifications()[ $state ] ?? $state, 'verified' === $state ? 'stable' : 'draft' );
	}

	/**
	 * A stored day in the site's date format.
	 *
	 * @param string $date Y-m-d.
	 */
	private static function day( string $date ): string {
		$date = Impact::date( $date );
		return '' === $date ? '—' : date_i18n( get_option( 'date_format' ), strtotime( $date . ' 12:00:00' ) );
	}

	/**
	 * Apply a record action and return to the list.
	 */
	public static function action(): void {
		Admin::require( Impact::can_review() );
		$id = isset( $_POST['impact'] ) ? (int) $_POST['impact'] : 0;
		check_admin_referer( 'packout_impact_action_' . $id );
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$user   = get_current_user_id();
		$result = array( 'error' => 'record' );
		$done   = null;

		if ( $id > 0 && ! Impact::can_see( $id ) ) {
			Admin::back( self::PAGE, $result );
		}

		switch ( $do ) {
			case 'save':
				$spot  = $id > 0 ? (int) Impact::field( $id, 'spot', '0' ) : ( isset( $_POST['spot'] ) ? (int) $_POST['spot'] : 0 );
				$input = array(
					'narrative'      => isset( $_POST['narrative'] ) ? sanitize_textarea_field( wp_unslash( $_POST['narrative'] ) ) : '',
					'date'           => isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '',
					'hours'          => isset( $_POST['hours'] ) ? (float) $_POST['hours'] : 0,
					'people'         => isset( $_POST['people'] ) ? (int) $_POST['people'] : 0,
					'persons'        => isset( $_POST['persons'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['persons'] ) ) : array(),
					'bags'           => isset( $_POST['bags'] ) ? (int) $_POST['bags'] : 0,
					'bags_source'    => isset( $_POST['bags_source'] ) ? sanitize_key( wp_unslash( $_POST['bags_source'] ) ) : '',
					'pounds'         => isset( $_POST['pounds'] ) ? (float) $_POST['pounds'] : 0,
					'pounds_source'  => isset( $_POST['pounds_source'] ) ? sanitize_key( wp_unslash( $_POST['pounds_source'] ) ) : '',
					'come_back'      => ! empty( $_POST['come_back'] ),
					'come_back_note' => isset( $_POST['come_back_note'] ) ? sanitize_text_field( wp_unslash( $_POST['come_back_note'] ) ) : '',
				);
				$done  = $id > 0 ? Impact::update( $id, $input, $user ) : Impact::save_cleanup( $spot, $input, $user );
				if ( ! is_wp_error( $done ) ) {
					$id     = (int) $done;
					$result = array( 'updated' => 'saved' );
					$failed = self::store_photos( $id, $user );
					if ( '' !== $failed ) {
						$done = new \WP_Error( 'packout_photo', $failed );
					}
				}
				break;
			case 'verify':
				$done   = Impact::verify( $id, $user );
				$result = array( 'updated' => 'verified' );
				break;
			case 'unverify':
				$done   = Impact::unverify( $id, $user );
				$result = array( 'updated' => 'unverified' );
				break;
			case 'show':
			case 'hide':
				$done   = Impact::set_visible( $id, 'show' === $do, $user );
				$result = array( 'updated' => 'show' === $do ? 'shown' : 'hidden' );
				break;
			case 'trash':
				Admin::require( Impact::coordinates() );
				if ( $id > 0 ) {
					Impact::remember_unrecorded( $id );
					if ( wp_trash_post( $id ) ) {
						Impact::flush();
						Admin::back( self::PAGE, array( 'updated' => 'trashed' ) );
					}
				}
				break;
		}//end switch

		if ( is_wp_error( $done ) ) {
			$result = array(
				'error'  => 'detail',
				'detail' => $done->get_error_message(),
			);
		}
		if ( $id > 0 ) {
			$result['open'] = (string) $id;
		}
		Admin::back( self::PAGE, $result );
	}

	/**
	 * Attach the photos sent with the form.
	 *
	 * @param int $id   Record ID.
	 * @param int $user Who uploads.
	 * @return string The first problem, or an empty string.
	 */
	private static function store_photos( int $id, int $user ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- action() checked the nonce. The upload handler validates each file.
		$sent = isset( $_FILES['photos'] ) && is_array( $_FILES['photos'] ) ? $_FILES['photos'] : array();
		if ( empty( $sent['name'] ) || ! is_array( $sent['name'] ) ) {
			return '';
		}
		$problem = '';
		foreach ( array_keys( $sent['name'] ) as $index ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $sent['error'][ $index ] ) {
				continue;
			}
			$file  = array(
				'name'     => sanitize_file_name( (string) $sent['name'][ $index ] ),
				'type'     => (string) $sent['type'][ $index ],
				'tmp_name' => (string) $sent['tmp_name'][ $index ],
				'error'    => (int) $sent['error'][ $index ],
				'size'     => (int) $sent['size'][ $index ],
			);
			$added = Impact::add_photo( $id, $file, $user );
			if ( is_wp_error( $added ) && '' === $problem ) {
				/* translators: %s: reason. */
				$problem = sprintf( __( 'The record was saved. A photo was not: %s', 'packout-core' ), $added->get_error_message() );
			}
		}
		return $problem;
	}
}
