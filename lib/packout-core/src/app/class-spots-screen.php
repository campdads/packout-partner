<?php
/**
 * Spots review screen.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Spots list, its filters, and the review drawer.
 *
 * A row opens a drawer with the story, the location, photos, the hazard flag,
 * possible duplicates, and the moderation actions.
 */
class Spots_Screen {

	/**
	 * Every Spot a coordinator can review, newest first.
	 *
	 * The Data View filters, counts, and pages them. The address can still
	 * name a starting filter, which is how the overview cards open this list.
	 *
	 * @return array<int, \WP_Post>
	 */
	private static function all(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => Schema::SPOT,
				'post_status'    => Spots::POST_STATUSES,
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		return $query->posts;
	}

	/**
	 * A starting search and filters, from the address.
	 *
	 * @return array{search: string, filters: array<int, array{field: string, value: string}>}
	 */
	private static function initial(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- List filters from the overview.
		$get = static function ( string $key ): string {
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		};
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$filters = array();
		$review  = $get( 'review' );
		if ( isset( Admin::review_labels()[ $review ] ) ) {
			$filters[] = array(
				'field' => 'review',
				'value' => Admin::review_labels()[ $review ],
			);
		}
		$category = $get( 'category' );
		if ( isset( Schema::categories()[ $category ] ) ) {
			$filters[] = array(
				'field' => 'category',
				'value' => Schema::categories()[ $category ],
			);
		}
		$status = $get( 'status' );
		if ( isset( Schema::statuses()[ $status ] ) ) {
			$filters[] = array(
				'field' => 'stage',
				'value' => Schema::statuses()[ $status ],
			);
		}
		$hazard = $get( 'hazard' );
		if ( isset( Schema::hazards()[ $hazard ] ) ) {
			$filters[] = array(
				'field' => 'hazard',
				'value' => Schema::hazards()[ $hazard ],
			);
		}
		$pin = $get( 'pin' );
		if ( 'yes' === $pin || 'no' === $pin ) {
			$filters[] = array(
				'field' => 'pin',
				'value' => 'yes' === $pin ? __( 'Has a pin', 'packout-core' ) : __( 'No pin', 'packout-core' ),
			);
		}
		if ( 'waiting' === $get( 'clearance' ) ) {
			$filters[] = array(
				'field' => 'clearance',
				'value' => __( 'Waiting', 'packout-core' ),
			);
		}
		if ( '1' === $get( 'actionable' ) ) {
			$filters[] = array(
				'field'    => 'hazard',
				'operator' => 'isNot',
				'value'    => Schema::hazards()['do_not_touch'],
			);
		}
		return array(
			'search'  => $get( 's' ),
			'filters' => $filters,
		);
	}

	/**
	 * The list and the drawer.
	 */
	public static function render(): void {
		Admin::guard();
		/**
		 * Filters whether something else drew this screen: a view of records that are not this site's Spots.
		 *
		 * @param bool $drawn Whether the screen is already drawn.
		 */
		if ( apply_filters( 'packout_spots_screen_view', false ) ) {
			return;
		}
		$posts   = self::all();
		$initial = self::initial();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Overview deep links.
		if ( isset( $_GET['actionable'] ) && '1' === sanitize_key( wp_unslash( $_GET['actionable'] ) ) ) {
			$posts = array_values(
				array_filter(
					$posts,
					static fn( \WP_Post $post ): bool => 'do_not_touch' !== Spots::field( $post->ID, 'hazard', 'none' )
				)
			);
		}
		if ( isset( $_GET['revisit'] ) && 'due' === sanitize_key( wp_unslash( $_GET['revisit'] ) ) ) {
			$due   = array_fill_keys( Overview::due_ids(), true );
			$posts = array_values(
				array_filter(
					$posts,
					static fn( \WP_Post $post ): bool => isset( $due[ $post->ID ] )
				)
			);
		}
		// The Spots of one organization, or of a partner's organizations: on a mission of theirs, cleaned by one, or reported in their name.
		$team     = isset( $_GET['team'] ) ? (int) $_GET['team'] : 0;
		$partner  = isset( $_GET['partner'] ) ? (int) $_GET['partner'] : 0;
		$narrowed = '';
		$theirs   = null;
		if ( $team > 0 && Teams::get( $team ) ) {
			$theirs = Overview_Scope::spot_ids( array( $team ) );
			/* translators: %s: organization name. */
			$narrowed = sprintf( __( 'Spots of %s: on one of its missions, cleaned by one, or reported in its name.', 'packout-core' ), get_the_title( $team ) );
		} elseif ( $partner > 0 && Partners::get( $partner ) ) {
			$theirs = Overview_Scope::spot_ids( Partners::organization_ids( $partner ) );
			/* translators: %s: partner name. */
			$narrowed = sprintf( __( 'Spots of the organizations of %s: on one of their missions, cleaned by one, or reported in their name.', 'packout-core' ), get_the_title( $partner ) );
		}
		if ( null !== $theirs ) {
			$theirs = array_fill_keys( $theirs, true );
			$posts  = array_values( array_filter( $posts, static fn( \WP_Post $post ): bool => isset( $theirs[ $post->ID ] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Opens the drawer named in the address.
		$open = isset( $_GET['open'] ) ? (int) $_GET['open'] : 0;

		echo '<div class="wrap packout-admin">';
		echo '<h1>' . esc_html__( 'Spots', 'packout-core' ) . '</h1>';
		if ( '' !== $narrowed ) {
			echo '<p>' . esc_html( $narrowed ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=packout-spots' ) ) . '">' . esc_html__( 'Show every Spot', 'packout-core' ) . '</a></p>';
		}
		/**
		 * Fires under the heading of the Spots screen, for a way to another view of it.
		 */
		do_action( 'packout_spots_screen_top' );
		Admin::notice(
			array(
				'published' => __( 'Spot published to the map.', 'packout-core' ),
				'private'   => __( 'Spot kept for crews and coordinators.', 'packout-core' ),
				'returned'  => __( 'Spot returned to the reporter.', 'packout-core' ),
				'saved'     => __( 'Spot updated.', 'packout-core' ),
				'duplicate' => __( 'Spots linked as the same place. The newer one is off the public map.', 'packout-core' ),
				'unlinked'  => __( 'Duplicate link removed.', 'packout-core' ),
				'trashed'   => __( 'Spot moved to the trash. Its photos stay in the media library.', 'packout-core' ),
				'cleared'   => __( 'Clearance saved. The crew sees it, and the Spot can be marked cleaned.', 'packout-core' ),
				'uncleared' => __( 'Clearance withdrawn. The Spot cannot be marked cleaned.', 'packout-core' ),
				'confirmed' => __( 'Cleanup confirmed. The Spot is marked cleaned.', 'packout-core' ),
				'reopened'  => __( 'Spot reopened. The reported cleanup was removed.', 'packout-core' ),
			),
			array(
				'spot'   => __( 'That Spot could not be changed.', 'packout-core' ),
				'reason' => __( 'Give the reporter a reason before returning the Spot.', 'packout-core' ),
			)
		);
		if ( ! $posts ) {
			self::empty_state( false );
			echo '</div>';
			return;
		}

		$kinds = Schema::location_kinds();
		$rows  = array();
		foreach ( $posts as $post ) {
			$term     = Spots::category( $post->ID );
			$slug     = $term ? $term->slug : '';
			$category = isset( Schema::categories()[ $slug ] ) ? Schema::categories()[ $slug ] : ( $term ? $term->name : '—' );
			$hazard   = Spots::field( $post->ID, 'hazard', 'none' );
			$rows[]   = array(
				'id'        => (string) $post->ID,
				'title'     => $post->post_title,
				'url'       => Admin::pane_url( (string) $post->ID ),
				'category'  => $category,
				'stage'     => Schema::statuses()[ Spots::field( $post->ID, 'status', 'spotted' ) ] ?? '',
				'review'    => Admin::review_labels()[ $post->post_status ] ?? $post->post_status,
				'location'  => $kinds[ Spots::field( $post->ID, 'location_kind', 'description' ) ] ?? '—',
				'pin'       => Spots::pin( $post->ID ) ? __( 'Has a pin', 'packout-core' ) : __( 'No pin', 'packout-core' ),
				'hazard'    => Schema::hazards()[ $hazard ] ?? $hazard,
				'clearance' => self::clearance_label( $post->ID, $hazard ),
				'duplicate' => '' !== Spots::field( $post->ID, 'duplicate_of' ) ? __( 'Duplicate', 'packout-core' ) : '—',
				'reported'  => get_the_date( 'Y-m-d', $post ),
				'reporter'  => self::reporter( $post ),
			);
		}//end foreach
		Admin_Views::render(
			'spots',
			array(
				'category'  => array(
					'label'  => __( 'Category', 'packout-core' ),
					'search' => true,
				),
				'stage'     => array( 'label' => __( 'Stage', 'packout-core' ) ),
				'review'    => array( 'label' => __( 'Review', 'packout-core' ) ),
				'location'  => array( 'label' => __( 'Location', 'packout-core' ) ),
				'pin'       => array( 'label' => __( 'Pin', 'packout-core' ) ),
				'hazard'    => array( 'label' => __( 'Hazard', 'packout-core' ) ),
				'clearance' => array( 'label' => __( 'Clearance', 'packout-core' ) ),
				'duplicate' => array( 'label' => __( 'Duplicate', 'packout-core' ) ),
				'reported'  => array( 'label' => __( 'Reported', 'packout-core' ) ),
				'reporter'  => array(
					'label'  => __( 'Reporter', 'packout-core' ),
					'search' => true,
				),
			),
			$rows,
			array(
				'title'   => __( 'Spot', 'packout-core' ),
				'empty'   => __( 'No Spots match.', 'packout-core' ),
				'search'  => $initial['search'],
				'filters' => $initial['filters'],
				'sort'    => array(
					'field'     => 'reported',
					'direction' => 'desc',
				),
			)
		);

		$panes = $posts;
		$spot  = $open > 0 ? Spots::get( $open ) : null;
		if ( $spot ) {
			$listed = false;
			foreach ( $panes as $post ) {
				if ( (int) $post->ID === (int) $spot->ID ) {
					$listed = true;
					break;
				}
			}
			if ( ! $listed ) {
				$panes[] = $spot;
			}
			Admin::reopen( (string) $spot->ID, $spot->post_title );
		}
		Admin::drawer(
			__( 'Spot', 'packout-core' ),
			static function () use ( $panes ): void {
				foreach ( $panes as $post ) {
					self::pane( $post );
				}
			}
		);
		echo '</div>';
	}

	/**
	 * Whether a Spot is waiting for a clearance, already has one, or neither.
	 *
	 * @param int    $id     Spot ID.
	 * @param string $hazard Hazard flag.
	 */
	private static function clearance_label( int $id, string $hazard ): string {
		if ( 'needs_authorization' !== $hazard ) {
			return __( 'None', 'packout-core' );
		}
		if ( Spots::blocked( $id ) && 'cleaned' !== Spots::field( $id, 'status', 'spotted' ) ) {
			return __( 'Waiting', 'packout-core' );
		}
		return __( 'Recorded', 'packout-core' );
	}

	/**
	 * Empty list.
	 *
	 * @param bool $filtered Whether filters are narrowing the list.
	 */
	private static function empty_state( bool $filtered ): void {
		echo '<div class="packout-admin__empty">';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
		echo Admin::ui(
			'empty-state',
			array(
				'title'       => $filtered ? __( 'No Spots match these filters', 'packout-core' ) : __( 'No Spots yet', 'packout-core' ),
				'description' => $filtered ? __( 'Clear the filters to see every Spot.', 'packout-core' ) : __( 'Reports from the website form and the field app arrive here for review.', 'packout-core' ),
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		$page = (int) Settings::get( 'report_page' );
		if ( ! $filtered && $page > 0 && 'publish' === get_post_status( $page ) ) {
			echo '<p><a class="button" href="' . esc_url( (string) get_permalink( $page ) ) . '">' . esc_html__( 'Open the Report page', 'packout-core' ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Who reported a Spot.
	 *
	 * @param \WP_Post $post Spot.
	 */
	private static function reporter( \WP_Post $post ): string {
		$user = (int) $post->post_author > 0 ? get_userdata( (int) $post->post_author ) : false;
		if ( $user ) {
			return $user->display_name;
		}
		return (int) Spots::field( $post->ID, 'entry_id', '0' ) > 0 ? __( 'Guest (website form)', 'packout-core' ) : __( 'Guest', 'packout-core' );
	}

	/**
	 * Drawer pane for one Spot.
	 *
	 * @param \WP_Post $post Spot.
	 */
	private static function pane( \WP_Post $post ): void {
		$id        = $post->ID;
		$pin       = Spots::pin( $id );
		$hazard    = Spots::field( $id, 'hazard', 'none' );
		$precision = Spots::field( $id, 'precision', 'exact' );
		$status    = Spots::field( $id, 'status', 'spotted' );
		$category  = Spots::category( $id );
		$kinds     = Schema::location_kinds();
		$linked    = (int) Spots::field( $id, 'duplicate_of', '0' );

		echo '<div class="packout-drawer__pane packout-pane" data-pane="' . esc_attr( (string) $id ) . '" hidden>';

		echo '<p class="packout-pane__badges">' . Admin::review_badge( $post->post_status ) . ' ' . Admin::status_badge( $status ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badges escape.
		$reason = Spots::field( $id, 'return_reason' );
		if ( '' !== $reason ) {
			/* translators: %s: reason given to the reporter. */
			echo '<p class="packout-pane__note">' . esc_html( sprintf( __( 'Returned to the reporter: %s', 'packout-core' ), $reason ) ) . '</p>';
		}

		// On a partner's site: how the Spot stands on packout.spot, and what there may be the same thing.
		foreach ( array_merge( Exchange::lines( $id ), Adoptions::lines( $id ) ) as $line ) {
			echo '<p class="packout-pane__note packout-pane__hub">' . esc_html( $line['text'] );
			if ( '' !== $line['url'] ) {
				echo ' <a href="' . esc_url( $line['url'] ) . '" rel="noopener">' . esc_html( $line['link'] ) . '</a>';
			}
			echo '</p>';
		}

		foreach ( Missions::open_with( $id ) as $mission ) {
			/* translators: %s: mission title. */
			echo '<p class="packout-pane__note">' . esc_html( sprintf( __( 'On the mission “%s”.', 'packout-core' ), $mission->post_title ) ) . '</p>';
		}
		$cleaner = 'cleaned' === $status ? get_userdata( (int) Spots::field( $id, 'cleaned_by', '0' ) ) : false;
		if ( $cleaner ) {
			$mission = Missions::get( (int) Spots::field( $id, 'cleaned_mission', '0' ) );
			echo '<p class="packout-pane__note">' . esc_html(
				$mission
					/* translators: 1: person's name, 2: mission title. */
					? sprintf( __( 'Recorded as cleaned by %1$s on the mission “%2$s”.', 'packout-core' ), $cleaner->display_name, $mission->post_title )
					/* translators: %s: person's name. */
					: sprintf( __( 'Recorded as cleaned by %s, with no mission.', 'packout-core' ), $cleaner->display_name )
			) . '</p>';
		}

		self::claim_section( $id, $status );

		echo '<section><h3>' . esc_html__( 'Story', 'packout-core' ) . '</h3>';
		echo '<p class="packout-pane__story">' . nl2br( esc_html( $post->post_content ) ) . '</p>';
		echo '<dl class="packout-pane__facts">';
		echo '<dt>' . esc_html__( 'Category', 'packout-core' ) . '</dt><dd>' . esc_html( $category ? $category->name : '—' ) . '</dd>';
		echo '<dt>' . esc_html__( 'Reporter', 'packout-core' ) . '</dt><dd>' . esc_html( self::reporter( $post ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'The page says', 'packout-core' ) . '</dt><dd class="packout-pane__credit">' . esc_html( sprintf( /* translators: %s: who the Spot's page credits with the report. */ __( 'Reported by %s', 'packout-core' ), Spots::reported_by( $post )['label'] ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Reported', 'packout-core' ) . '</dt><dd>' . esc_html( get_the_date( '', $post ) . ' ' . get_the_time( '', $post ) ) . '</dd>';
		$entry = (int) Spots::field( $id, 'entry_id', '0' );
		$note  = $entry > 0 ? App::kernel()->report_form()->entry_note( $entry ) : '';
		if ( '' !== $note ) {
			echo '<dt>' . esc_html__( 'Form entry', 'packout-core' ) . '</dt><dd>' . esc_html( $note ) . '</dd>';
		}
		echo '</dl></section>';

		echo '<section><h3>' . esc_html__( 'Location', 'packout-core' ) . '</h3><dl class="packout-pane__facts">';
		echo '<dt>' . esc_html__( 'Kind', 'packout-core' ) . '</dt><dd>' . esc_html( $kinds[ Spots::field( $id, 'location_kind', 'description' ) ] ?? '—' ) . '</dd>';
		if ( $pin ) {
			$accuracy = Spots::field( $id, 'accuracy' );
			echo '<dt>' . esc_html__( 'Pin', 'packout-core' ) . '</dt><dd>' . esc_html( $pin[0] . ', ' . $pin[1] );
			if ( '' !== $accuracy ) {
				/* translators: %s: accuracy in meters. */
				echo ' ' . esc_html( sprintf( __( '(±%s m)', 'packout-core' ), $accuracy ) );
			}
			if ( 'photo' === Spots::field( $id, 'pin_source' ) ) {
				echo ' ' . esc_html__( 'Taken from the location in a photo. Nobody placed it.', 'packout-core' );
			}
			echo '</dd>';
		}
		foreach ( array(
			'landmark' => __( 'Landmark', 'packout-core' ),
			'area'     => __( 'Area note', 'packout-core' ),
		) as $key => $label ) {
			$value = Spots::field( $id, $key );
			if ( '' !== $value ) {
				echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
			}
		}
		echo '</dl>';
		if ( $pin ) {
			echo '<div class="packout-minimap" data-lat="' . esc_attr( (string) $pin[0] ) . '" data-lng="' . esc_attr( (string) $pin[1] ) . '" role="img" aria-label="' . esc_attr__( 'Map preview of the pin', 'packout-core' ) . '"></div>';
		}
		echo '</section>';

		$photos = Photos::for_spot( $id );
		echo '<section><h3>' . esc_html__( 'Photos', 'packout-core' ) . '</h3>';
		if ( $photos ) {
			if ( 'coarse' === $precision ) {
				$shown = __( 'Not shown to the public: the location is approximate, and a photo can give the place away.', 'packout-core' );
			} elseif ( ! Spots::photos_public( $id ) ) {
				$shown = __( 'Hidden from the public. Show them again under Review.', 'packout-core' );
			} elseif ( 'publish' !== $post->post_status ) {
				$shown = __( 'Shown on the Spot\'s page and its card on the map once the Spot is published.', 'packout-core' );
			} else {
				$shown = __( 'Shown on the Spot\'s page and its card on the map.', 'packout-core' );
			}
			echo '<p class="description packout-pane__photos-note">' . esc_html( $shown ) . '</p>';
			echo '<div class="packout-pane__photos">';
			foreach ( $photos as $photo ) {
				echo '<a href="' . esc_url( $photo['full'] ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $photo['url'] ) . '" alt="' . esc_attr__( 'Photo from the report', 'packout-core' ) . '" loading="lazy" /></a>';
			}
			echo '</div>';
		} else {
			echo '<p>' . esc_html__( 'No photos yet.', 'packout-core' ) . '</p>';
		}
		echo '</section>';

		// The work done here, and the visits since.
		$records = Impact::can_review() ? Impact::of_spot( $id ) : array();
		$status  = Spots::field( $id, 'status', 'spotted' );
		if ( $records || ( 'cleaned' === $status && Impact::can_review() ) ) {
			$screen = admin_url( 'admin.php?page=' . Impact_Screen::PAGE );
			echo '<section><h3>' . esc_html__( 'Impact', 'packout-core' ) . '</h3>';
			if ( $records ) {
				echo '<ul class="packout-pane__done">';
				foreach ( $records as $record ) {
					$summary = Impact::summary( $record->ID );
					echo '<li><a href="' . esc_url( add_query_arg( 'open', $record->ID, $screen ) ) . '">' . esc_html( Impact::kinds()[ Impact::kind( $record->ID ) ] . ', ' . Impact::field( $record->ID, 'date' ) ) . '</a> ' . Impact_Screen::verification_badge( Impact::verified( $record->ID ) ? 'verified' : 'unverified' ) . ( '' !== $summary ? ' ' . esc_html( $summary ) : '' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
				}
				echo '</ul>';
			}
			if ( 'cleaned' === $status && ! Impact::current( $id ) ) {
				$url = add_query_arg(
					array(
						'open' => 'new',
						'spot' => $id,
					),
					$screen
				);
				echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Record the work', 'packout-core' ) . '</a></p>';
			}
			echo '</section>';
		}//end if

		$hints = Spots::duplicates( $id );
		if ( $hints || $linked > 0 ) {
			echo '<section><h3>' . esc_html__( 'Possible duplicates', 'packout-core' ) . '</h3>';
			if ( $linked > 0 ) {
				$older = Spots::get( $linked );
				/* translators: %s: Spot title. */
				echo '<p>' . esc_html( sprintf( __( 'Linked as the same place as “%s”. This Spot is off the public map.', 'packout-core' ), $older ? $older->post_title : '#' . $linked ) ) . '</p>';
				self::form( $id, 'unduplicate', __( 'Remove the link', 'packout-core' ) );
			}
			foreach ( $hints as $hint ) {
				if ( $hint['id'] === $linked ) {
					continue;
				}
				echo '<div class="packout-pane__hint"><span>';
				/* translators: 1: Spot title, 2: distance in meters. */
				echo esc_html( sprintf( __( '%1$s, %2$d m away', 'packout-core' ), $hint['title'], $hint['distance'] ) );
				echo '</span>';
				self::form( $id, 'duplicate', __( 'Same place', 'packout-core' ), array( 'older' => (string) $hint['id'] ) );
				echo '</div>';
			}
			echo '</section>';
		}//end if
		/**
		 * Fires after a Spot's possible duplicates, for reports of the same thing that are not this site's Spots.
		 *
		 * @param int $id The Spot.
		 */
		do_action( 'packout_spots_pane_duplicates', $id );

		self::clearance_section( $id, $hazard );

		echo '<section><h3>' . esc_html__( 'Review', 'packout-core' ) . '</h3>';
		if ( Spots::blocked( $id ) ) {
			echo '<p class="packout-pane__note">' . esc_html( Schema::hazard_guidance( $hazard ) ) . '</p>';
		}
		echo '<div class="packout-pane__row">';
		if ( 'publish' !== $post->post_status ) {
			self::form( $id, 'publish', __( 'Publish to the map', 'packout-core' ), array(), 'button-primary' );
		}
		if ( 'private' !== $post->post_status ) {
			self::form( $id, 'private', __( 'Keep for crews only', 'packout-core' ) );
		}
		echo '</div>';

		self::open_form( $id, 'settings' );
		echo '<p><label>' . esc_html__( 'What the public sees of the location', 'packout-core' ) . '<br /><select name="precision">';
		echo '<option value="exact"' . selected( $precision, 'exact', false ) . '>' . esc_html__( 'The exact pin', 'packout-core' ) . '</option>';
		echo '<option value="coarse"' . selected( $precision, 'coarse', false ) . '>' . esc_html__( 'An approximate point, about 2 km wide', 'packout-core' ) . '</option>';
		echo '</select></label></p>';
		echo '<p class="description">' . esc_html__( 'With an approximate point, a generated title and the Spot\'s address no longer name the pin or the landmark. A title someone typed is left as it is, so check that it does not give the location away.', 'packout-core' ) . '</p>';
		echo '<p><label>' . esc_html__( 'Hazard', 'packout-core' ) . '<br /><select name="hazard">';
		foreach ( Schema::hazards() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $hazard, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Stage', 'packout-core' ) . '<br /><select name="status">';
		foreach ( Schema::statuses() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';
		$places = Places::all();
		if ( $places ) {
			$place = Places::of_spot( $id );
			echo '<p><label>' . esc_html__( 'Place', 'packout-core' ) . '<br /><select name="place">';
			echo '<option value="0">' . esc_html__( 'No Place', 'packout-core' ) . '</option>';
			foreach ( $places as $option ) {
				echo '<option value="' . esc_attr( (string) $option->ID ) . '"' . selected( $place ? $place->ID : 0, $option->ID, false ) . '>' . esc_html( $option->post_title ) . '</option>';
			}
			echo '</select></label></p>';
			echo '<p class="description">' . esc_html__( 'A published Spot is listed on the page of its Place when the Place is listed. That tells the public the Spot is inside the outline, even when they see only an approximate point.', 'packout-core' ) . '</p>';
		}
		// Who the public page credits, and whether it shows the photos.
		$credit = Spots::credit( $id );
		echo '<input type="hidden" name="credit_form" value="1" />';
		echo '<p><label>' . esc_html__( 'Reported by, on the Spot\'s page', 'packout-core' ) . '<br /><select name="credit_team">';
		echo '<option value="0">' . esc_html__( 'An individual', 'packout-core' ) . '</option>';
		foreach ( Teams::all() as $option ) {
			echo '<option value="' . esc_attr( (string) $option->ID ) . '"' . selected( $credit['team'], $option->ID, false ) . '>' . esc_html( $option->post_title ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label><input type="checkbox" name="credit_named" value="1"' . checked( $credit['named'], true, false ) . ' /> ' . esc_html__( 'Give the reporter\'s name when no organization is credited', 'packout-core' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'The reporter chose these. Change them only when the reporter asks, or to take a name off the page. An organization that is not listed shows as “an organization”.', 'packout-core' ) . '</p>';
		echo '<p><label><input type="checkbox" name="photos_public" value="1"' . checked( Spots::photos_public( $id ), true, false ) . ' /> ' . esc_html__( 'Show the photos on the Spot\'s page and its card on the map', 'packout-core' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Photos are shown unless you untick this: the ones sent with the report, and the ones from cleanups and return visits. Hide them when a photo shows a face, a plate, or anything that names a person. A Spot with an approximate location never shows its photos.', 'packout-core' ) . '</p>';
		echo '<p><button class="button">' . esc_html__( 'Save', 'packout-core' ) . '</button></p></form>';

		self::open_form( $id, 'return' );
		echo '<p><label>' . esc_html__( 'Return to the reporter with a reason', 'packout-core' ) . '<br />';
		echo '<textarea name="reason" rows="2" maxlength="300" required></textarea></label></p>';
		echo '<p><button class="button">' . esc_html__( 'Return', 'packout-core' ) . '</button></p></form>';

		echo '<details class="packout-pane__trash"><summary>' . esc_html__( 'Move to trash', 'packout-core' ) . '</summary>';
		echo '<p>' . esc_html__( 'The Spot leaves the map and this list. Its photos stay in the media library.', 'packout-core' ) . '</p>';
		self::form( $id, 'trash', __( 'Move this Spot to the trash', 'packout-core' ), array(), 'button-link-delete' );
		echo '</details></section>';

		echo '</div>';
	}

	/**
	 * The clearance of a Spot that needs authorization.
	 *
	 * @param int    $id     Spot ID.
	 * @param string $hazard Hazard flag.
	 */
	private static function clearance_section( int $id, string $hazard ): void {
		if ( 'none' === $hazard ) {
			return;
		}
		echo '<section><h3>' . esc_html__( 'Authorization', 'packout-core' ) . '</h3>';
		if ( 'do_not_touch' === $hazard ) {
			echo '<p>' . esc_html__( 'A do-not-touch Spot has no clearance. It stays a report for the agency responsible. If the flag is wrong, change the hazard below.', 'packout-core' ) . '</p></section>';
			return;
		}

		$clearance = Spots::clearance( $id );
		if ( $clearance ) {
			$by = get_userdata( $clearance['by'] );
			echo '<p class="packout-pane__badges">' . Admin::badge( __( 'Cleared', 'packout-core' ), 'stable' ) . ' ' . Admin::badge( $clearance['public'] ? __( 'Public line on', 'packout-core' ) : __( 'Public line off', 'packout-core' ), 'none' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			/* translators: 1: person's name, 2: date. */
			echo '<p>' . esc_html( sprintf( __( 'Cleared by %1$s on %2$s.', 'packout-core' ), $by ? $by->display_name : __( 'a coordinator', 'packout-core' ), Schema::span( $clearance['on'], '' ) ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Nobody can mark this Spot cleaned until a clearance is saved. Record what authorizes the work. The crew sees the reference and the scope. This does not file a permit, and it does not check one.', 'packout-core' ) . '</p>';
		}

		self::open_form( $id, 'clear' );
		echo '<p><label>' . esc_html__( 'Reference', 'packout-core' ) . '<br /><input type="text" name="reference" class="widefat" maxlength="200" required value="' . esc_attr( $clearance['reference'] ?? '' ) . '" /></label></p>';
		echo '<p class="description">' . esc_html__( 'The permit number, the agreement, or who wrote and when.', 'packout-core' ) . '</p>';
		echo '<p><label>' . esc_html__( 'What the crew may do', 'packout-core' ) . '<br /><textarea name="scope" rows="3" maxlength="1000">' . esc_textarea( $clearance['scope'] ?? '' ) . '</textarea></label></p>';
		echo '<p><label>' . esc_html__( 'Cleared on', 'packout-core' ) . '<br /><input type="date" name="on" value="' . esc_attr( $clearance['on'] ?? wp_date( 'Y-m-d' ) ) . '" /></label></p>';
		echo '<p><label><input type="checkbox" name="public" value="1"' . checked( ! empty( $clearance['public'] ), true, false ) . ' /> ' . esc_html__( 'Tell the public that this work is authorized', 'packout-core' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'The public is told only that the work is authorized and that the crew does it. The reference and the scope stay with the crew.', 'packout-core' ) . '</p>';
		echo '<p><button class="button">' . esc_html( $clearance ? __( 'Save the clearance', 'packout-core' ) : __( 'Record the clearance', 'packout-core' ) ) . '</button></p></form>';
		if ( $clearance ) {
			self::form( $id, 'unclear', __( 'Withdraw the clearance', 'packout-core' ), array(), 'button-link-delete' );
		}
		echo '</section>';
	}

	/**
	 * Open a moderation form.
	 *
	 * @param int    $id Spot ID.
	 * @param string $act Action name.
	 */
	private static function open_form( int $id, string $act ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-pane__form">';
		echo '<input type="hidden" name="action" value="packout_spot_action" />';
		echo '<input type="hidden" name="spot" value="' . esc_attr( (string) $id ) . '" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $act ) . '" />';
		wp_nonce_field( 'packout_spot_action_' . $id );
	}

	/**
	 * A cleanup someone reported from the Spot's public page.
	 *
	 * @param int    $id     Spot ID.
	 * @param string $status Domain status.
	 */
	private static function claim_section( int $id, string $status ): void {
		$claim = Claims::of( $id );
		if ( ! $claim ) {
			return;
		}
		$person = $claim['by'] > 0 ? get_userdata( $claim['by'] ) : false;
		$when   = strtotime( $claim['at'] );
		echo '<section class="packout-pane__claim"><h3>' . esc_html__( 'Cleanup reported from the Spot page', 'packout-core' ) . '</h3>';
		echo '<dl class="packout-pane__facts">';
		echo '<dt>' . esc_html__( 'By', 'packout-core' ) . '</dt><dd>' . esc_html( $claim['name'] . ' <' . $claim['email'] . '>' ) . '</dd>';
		echo '<dt>' . esc_html__( 'Credit', 'packout-core' ) . '</dt><dd>' . esc_html(
			$person
				/* translators: %s: person's name. */
				? sprintf( __( 'On the account of %s.', 'packout-core' ), $person->display_name )
				: __( 'Waiting. The address already had an account, so its owner must follow the link in the message first.', 'packout-core' )
		) . '</dd>';
		if ( $when ) {
			echo '<dt>' . esc_html__( 'Sent', 'packout-core' ) . '</dt><dd>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ) ) . '</dd>';
		}
		if ( '' !== $claim['note'] ) {
			echo '<dt>' . esc_html__( 'Note', 'packout-core' ) . '</dt><dd>' . nl2br( esc_html( $claim['note'] ) ) . '</dd>';
		}
		echo '</dl>';

		$image = $claim['photo'] > 0 ? wp_get_attachment_image_src( $claim['photo'], 'medium_large' ) : false;
		if ( $image ) {
			echo '<div class="packout-pane__photos"><a href="' . esc_url( (string) wp_get_attachment_url( $claim['photo'] ) ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $image[0] ) . '" alt="' . esc_attr__( 'Photo sent with the cleanup', 'packout-core' ) . '" loading="lazy" /></a></div>';
			$distance = Photos::distance_line( $claim['photo'], $id );
			echo '<p class="description">' . esc_html( '' !== $distance ? $distance : __( 'The photo does not say where it was taken.', 'packout-core' ) ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'No photo came with it.', 'packout-core' ) . '</p>';
		}

		if ( Claims::stands( $id ) ) {
			echo '<div class="packout-pane__actions">';
			if ( 'pending' === $status ) {
				self::form( $id, 'claim_confirm', __( 'Confirm it is cleaned', 'packout-core' ), array(), 'button-primary' );
			}
			self::form( $id, 'claim_dismiss', __( 'Not cleaned. Reopen the Spot', 'packout-core' ), array(), 'button-link-delete' );
			echo '</div>';
		}
		echo '</section>';
	}

	/**
	 * A moderation action that is one button.
	 *
	 * @param int                   $id     Spot ID.
	 * @param string                $act     Action name.
	 * @param string                $label  Button label.
	 * @param array<string, string> $fields Extra hidden fields.
	 * @param string                $classes  Extra button class.
	 */
	private static function form( int $id, string $act, string $label, array $fields = array(), string $classes = '' ): void {
		self::open_form( $id, $act );
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		echo '<button class="button ' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Apply a moderation action and return to the list.
	 */
	public static function action(): void {
		Admin::guard();
		$id = isset( $_POST['spot'] ) ? (int) $_POST['spot'] : 0;
		check_admin_referer( 'packout_spot_action_' . $id );
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$result = array( 'error' => 'spot' );

		if ( Spots::get( $id ) ) {
			switch ( $do ) {
				case 'publish':
					$result = Spots::publish( $id ) ? array( 'updated' => 'published' ) : $result;
					break;
				case 'private':
					$result = Spots::keep_private( $id ) ? array( 'updated' => 'private' ) : $result;
					break;
				case 'return':
					$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
					$result = '' === $reason ? array( 'error' => 'reason' ) : ( Spots::return_to_reporter( $id, $reason ) ? array( 'updated' => 'returned' ) : $result );
					break;
				case 'settings':
					Spots::set_precision( $id, isset( $_POST['precision'] ) ? sanitize_key( wp_unslash( $_POST['precision'] ) ) : 'exact' );
					Spots::set_hazard( $id, isset( $_POST['hazard'] ) ? sanitize_key( wp_unslash( $_POST['hazard'] ) ) : 'none' );
					Spots::set_status( $id, isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'spotted' );
					if ( isset( $_POST['place'] ) ) {
						Places::attach( $id, (int) $_POST['place'], get_current_user_id() );
					}
					// These are boxes: one that is not ticked sends nothing, so they are read only from the form that has them.
					if ( ! empty( $_POST['credit_form'] ) ) {
						Spots::set_credit( $id, isset( $_POST['credit_team'] ) ? (int) $_POST['credit_team'] : 0, ! empty( $_POST['credit_named'] ), true );
						Spots::set_photos_public( $id, ! empty( $_POST['photos_public'] ) );
					}
					$result = array( 'updated' => 'saved' );
					break;
				case 'clear':
					$done   = Spots::clear(
						$id,
						array(
							'reference' => isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '',
							'scope'     => isset( $_POST['scope'] ) ? sanitize_textarea_field( wp_unslash( $_POST['scope'] ) ) : '',
							'on'        => isset( $_POST['on'] ) ? sanitize_text_field( wp_unslash( $_POST['on'] ) ) : '',
							'public'    => ! empty( $_POST['public'] ),
						),
						get_current_user_id()
					);
					$result = is_wp_error( $done ) ? array(
						'error'  => 'detail',
						'detail' => $done->get_error_message(),
					) : array( 'updated' => 'cleared' );
					break;
				case 'unclear':
					$result = true === Spots::unclear( $id, get_current_user_id() ) ? array( 'updated' => 'uncleared' ) : $result;
					break;
				case 'claim_confirm':
				case 'claim_dismiss':
					$done   = 'claim_confirm' === $do ? Claims::confirm( $id ) : Claims::dismiss( $id );
					$result = is_wp_error( $done ) ? array(
						'error'  => 'detail',
						'detail' => $done->get_error_message(),
					) : array( 'updated' => 'claim_confirm' === $do ? 'confirmed' : 'reopened' );
					break;
				case 'duplicate':
					$result = Spots::mark_duplicate( $id, isset( $_POST['older'] ) ? (int) $_POST['older'] : 0 ) ? array( 'updated' => 'duplicate' ) : $result;
					break;
				case 'unduplicate':
					$result = Spots::mark_duplicate( $id, 0 ) ? array( 'updated' => 'unlinked' ) : $result;
					break;
				case 'trash':
					$result = wp_trash_post( $id ) ? array( 'updated' => 'trashed' ) : $result;
					break;
			}//end switch
		}//end if

		if ( 'trash' !== $do ) {
			$result['open'] = (string) $id;
		}
		$back = wp_get_referer();
		$back = $back ? remove_query_arg( array( 'updated', 'error', 'open', 'detail' ), $back ) : admin_url( 'admin.php?page=packout-spots' );
		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $result ), $back ) );
		exit;
	}
}
