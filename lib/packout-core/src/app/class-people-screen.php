<?php
/**
 * The People screen.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinator directory of people on the site, with an editable drawer.
 */
class People_Screen {

	public const PAGE = 'packout-people';

	/**
	 * The list and the drawer.
	 */
	public static function render(): void {
		Admin::guard();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- List filters and the open drawer.
		$filters = array(
			's'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'role' => isset( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : '',
			'team' => isset( $_GET['team'] ) ? (int) $_GET['team'] : 0,
		);
		$open    = isset( $_GET['open'] ) ? (int) $_GET['open'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$listed = self::query(
			array(
				's'    => '',
				'role' => '',
				'team' => 0,
			),
			1,
			5000
		);
		$people = $listed['users'];

		echo '<div class="wrap packout-admin">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'People', 'packout-core' ) . '</h1>';
		echo '<hr class="wp-header-end" />';

		Admin::notice(
			array(
				'saved'   => __( 'Person saved.', 'packout-core' ),
				'added'   => __( 'Added to the organization.', 'packout-core' ),
				'removed' => __( 'Removed from the organization.', 'packout-core' ),
			),
			array(
				'person' => __( 'That person could not be changed.', 'packout-core' ),
				'member' => __( 'That membership could not be changed.', 'packout-core' ),
			)
		);

		if ( ! $people ) {
			echo '<div class="packout-admin__empty">';
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
			echo Admin::ui(
				'empty-state',
				array(
					'title'       => __( 'No people yet', 'packout-core' ),
					'description' => __( 'People appear here when they have a Pack-Out Spot account on this site.', 'packout-core' ),
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		} else {
			$view_filters = array();
			if ( isset( Capabilities::roles()[ $filters['role'] ] ) ) {
				$view_filters[] = array(
					'field'    => 'role',
					'operator' => 'contains',
					'value'    => Capabilities::roles()[ $filters['role'] ]['label'],
				);
			}
			if ( $filters['team'] > 0 && Teams::get( $filters['team'] ) ) {
				$view_filters[] = array(
					'field'    => 'organizations',
					'operator' => 'contains',
					'value'    => Teams::get( $filters['team'] )->post_title,
				);
			}
			$rows = array();
			foreach ( $people as $user ) {
				$orgs   = Profile::teams( $user->ID );
				$spots  = (int) count_user_posts( $user->ID, Schema::SPOT, true );
				$rows[] = array(
					'id'            => (string) $user->ID,
					'title'         => $user->display_name,
					'url'           => Admin::pane_url( (string) $user->ID ),
					'email'         => $user->user_email,
					'role'          => self::role_labels( $user ),
					'organizations' => $orgs ? implode( ', ', array_values( $orgs ) ) : '—',
					'impact'        => $spots,
					'joined'        => mysql2date( 'Y-m-d', $user->user_registered ),
				);
			}
			Admin_Views::render(
				'people',
				array(
					'email'         => array(
						'label'  => __( 'Email', 'packout-core' ),
						'search' => true,
					),
					'role'          => array( 'label' => __( 'Role', 'packout-core' ) ),
					'organizations' => array(
						'label'  => __( 'Organizations', 'packout-core' ),
						'search' => true,
					),
					'impact'        => array(
						'label' => __( 'Spots', 'packout-core' ),
						'type'  => 'integer',
					),
					'joined'        => array( 'label' => __( 'Joined', 'packout-core' ) ),
				),
				$rows,
				array(
					'title'   => __( 'Name', 'packout-core' ),
					'empty'   => __( 'No people match.', 'packout-core' ),
					'search'  => $filters['s'],
					'filters' => $view_filters,
					'sort'    => array(
						'field'     => 'title',
						'direction' => 'asc',
					),
				)
			);
		}//end if

		$panes = $people;
		if ( $open > 0 ) {
			$opened = get_userdata( $open );
			if ( $opened instanceof \WP_User && Capabilities::can( Capabilities::REPORT, $opened->ID ) ) {
				$found = false;
				foreach ( $panes as $person ) {
					if ( (int) $person->ID === $open ) {
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					$panes[] = $opened;
				}
			}
		}

		Admin::drawer(
			__( 'Person', 'packout-core' ),
			static function () use ( $panes ): void {
				foreach ( $panes as $user ) {
					self::pane( $user );
				}
			}
		);
		echo '</div>';
	}

	/**
	 * Form handler.
	 */
	public static function action(): void {
		Admin::guard();
		$user_id = isset( $_POST['user'] ) ? (int) $_POST['user'] : 0;
		check_admin_referer( 'packout_people_action_' . $user_id );
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || ! Capabilities::can( Capabilities::REPORT, $user_id ) ) {
			Admin::back( self::PAGE, array( 'error' => 'person' ) );
		}

		$result = array( 'open' => (string) $user_id );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passed through Profile/Teams helpers.
		$input = wp_unslash( $_POST );
		// phpcs:enable

		if ( 'save_about' === $do ) {
			$done = Profile::save_about( $user_id, $input );
			$result[ is_wp_error( $done ) ? 'error' : 'updated' ] = is_wp_error( $done ) ? 'person' : 'saved';
		} elseif ( 'save_contact' === $do ) {
			$done = Profile::save_contact( $user_id, $input );
			$result[ is_wp_error( $done ) ? 'error' : 'updated' ] = is_wp_error( $done ) ? 'person' : 'saved';
		} elseif ( 'save_mail' === $do ) {
			$done = Profile::save_mail( $user_id, $input );
			$result[ is_wp_error( $done ) ? 'error' : 'updated' ] = is_wp_error( $done ) ? 'person' : 'saved';
		} elseif ( 'add_member' === $do ) {
			$team = isset( $_POST['team'] ) ? (int) $_POST['team'] : 0;
			$done = Teams::add_member( $team, $user->user_email );
			$result[ is_wp_error( $done ) ? 'error' : 'updated' ] = is_wp_error( $done ) ? 'member' : 'added';
		} elseif ( 'remove_member' === $do ) {
			$team = isset( $_POST['team'] ) ? (int) $_POST['team'] : 0;
			if ( $team <= 0 || Teams::lead( $team ) === $user_id ) {
				$result['error'] = 'member';
			} elseif ( Teams::remove_member( $team, $user_id ) ) {
				$result['updated'] = 'removed';
			} else {
				$result['error'] = 'member';
			}
		} else {
			$result['error'] = 'person';
		}//end if

		Admin::back( self::PAGE, $result );
	}

	/**
	 * People matching the filters.
	 *
	 * @param array<string, mixed> $filters s, role, team.
	 * @param int                  $page    Page number.
	 * @param int                  $per     Per page.
	 * @return array{users: array<int, \WP_User>, total: int}
	 */
	public static function query( array $filters, int $page = 1, int $per = 50 ): array {
		$args = array(
			'capability'  => Capabilities::REPORT,
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'number'      => $per,
			'paged'       => max( 1, $page ),
			'count_total' => true,
		);
		if ( '' !== (string) ( $filters['s'] ?? '' ) ) {
			$args['search']         = '*' . (string) $filters['s'] . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		if ( '' !== (string) ( $filters['role'] ?? '' ) && isset( Capabilities::roles()[ (string) $filters['role'] ] ) ) {
			$args['role'] = (string) $filters['role'];
			unset( $args['capability'] );
		}
		$team = (int) ( $filters['team'] ?? 0 );
		if ( $team > 0 ) {
			$ids             = array_values( array_unique( array_merge( array( Teams::lead( $team ) ), Teams::members( $team ) ) ) );
			$ids             = array_values( array_filter( array_map( 'intval', $ids ) ) );
			$args['include'] = $ids ? $ids : array( 0 );
		}

		$query = new \WP_User_Query( $args );
		$users = array();
		foreach ( $query->get_results() as $user ) {
			if ( $user instanceof \WP_User && Accounts::on_site( $user->ID ) ) {
				$users[] = $user;
			}
		}
		return array(
			'users' => $users,
			'total' => (int) $query->get_total(),
		);
	}

	/**
	 * Count of people with the report capability (overview card).
	 */
	public static function count(): int {
		$query = new \WP_User_Query(
			array(
				'capability'  => Capabilities::REPORT,
				'fields'      => 'ID',
				'number'      => 1,
				'count_total' => true,
			)
		);
		return (int) $query->get_total();
	}

	/**
	 * Drawer pane for one person.
	 *
	 * @param \WP_User $user Person.
	 */
	private static function pane( \WP_User $user ): void {
		$id      = $user->ID;
		$profile = Profile::get( $id );
		$orgs    = Profile::teams( $id );
		$done    = Profile::contributions( $id );

		echo '<div class="packout-drawer__pane packout-pane" data-pane="' . esc_attr( (string) $id ) . '" hidden>';

		echo '<section><h3>' . esc_html__( 'Account', 'packout-core' ) . '</h3>';
		echo '<dl class="packout-pane__facts">';
		foreach ( array(
			__( 'Email', 'packout-core' )    => $user->user_email,
			__( 'Username', 'packout-core' ) => $user->user_login,
			__( 'Joined', 'packout-core' )   => mysql2date( get_option( 'date_format' ), $user->user_registered ),
			__( 'Role', 'packout-core' )     => self::role_labels( $user ),
		) as $label => $value ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd>';
		}
		echo '</dl>';
		echo '<p><a href="' . esc_url( get_edit_user_link( $id ) ) . '">' . esc_html__( 'WordPress user profile', 'packout-core' ) . '</a></p>';
		echo '</section>';

		echo '<section><h3>' . esc_html__( 'About', 'packout-core' ) . '</h3>';
		self::open_form( $id, 'save_about' );
		echo '<p><label>' . esc_html__( 'Name', 'packout-core' ) . '<br /><input type="text" name="name" class="widefat" value="' . esc_attr( $user->display_name ) . '" required maxlength="60" /></label></p>';
		echo '<p><label>' . esc_html__( 'About', 'packout-core' ) . '<br /><textarea name="about" class="widefat" rows="4" maxlength="600">' . esc_textarea( $profile['about'] ) . '</textarea></label></p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Save about', 'packout-core' ) . '</button></p></form></section>';

		echo '<section><h3>' . esc_html__( 'Contact', 'packout-core' ) . '</h3>';
		self::open_form( $id, 'save_contact' );
		echo '<p><label>' . esc_html__( 'Phone', 'packout-core' ) . '<br /><input type="text" name="phone" class="widefat" value="' . esc_attr( $profile['phone'] ) . '" /></label></p>';
		foreach ( Profile::address_parts() as $part => $label ) {
			echo '<p><label>' . esc_html( $label ) . '<br /><input type="text" name="address[' . esc_attr( $part ) . ']" class="widefat" value="' . esc_attr( (string) ( $profile['address'][ $part ] ?? '' ) ) . '" /></label></p>';
		}
		if ( $orgs ) {
			echo '<p><strong>' . esc_html__( 'Share phone with leads of', 'packout-core' ) . '</strong></p>';
			foreach ( $orgs as $team_id => $title ) {
				echo '<label><input type="checkbox" name="share_phone[]" value="' . esc_attr( (string) $team_id ) . '"' . checked( in_array( (int) $team_id, $profile['share_phone'], true ), true, false ) . ' /> ' . esc_html( $title ) . '</label><br />';
			}
			echo '<p><strong>' . esc_html__( 'Share address with leads of', 'packout-core' ) . '</strong></p>';
			foreach ( $orgs as $team_id => $title ) {
				echo '<label><input type="checkbox" name="share_address[]" value="' . esc_attr( (string) $team_id ) . '"' . checked( in_array( (int) $team_id, $profile['share_address'], true ), true, false ) . ' /> ' . esc_html( $title ) . '</label><br />';
			}
		}
		echo '<p><label><input type="checkbox" name="swag" value="1"' . checked( ! empty( $profile['swag'] ), true, false ) . ' /> ' . esc_html__( 'Pack-Out Spot may use this address for mailings', 'packout-core' ) . '</label></p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Save contact', 'packout-core' ) . '</button></p></form></section>';

		echo '<section><h3>' . esc_html__( 'Email preferences', 'packout-core' ) . '</h3>';
		self::open_form( $id, 'save_mail' );
		if ( $orgs ) {
			echo '<p>' . esc_html__( 'Organization messages', 'packout-core' ) . '</p>';
			foreach ( $orgs as $team_id => $title ) {
				$wants = ! in_array( (int) $team_id, $profile['quiet'], true );
				echo '<label><input type="checkbox" name="teams[]" value="' . esc_attr( (string) $team_id ) . '"' . checked( $wants, true, false ) . ' /> ' . esc_html( $title ) . '</label><br />';
			}
		} else {
			echo '<p class="description">' . esc_html__( 'Not in an organization yet.', 'packout-core' ) . '</p>';
		}
		echo '<p><label><input type="checkbox" name="news" value="1"' . checked( ! empty( $profile['news'] ), true, false ) . ' /> ' . esc_html__( 'Pack-Out Spot news', 'packout-core' ) . '</label></p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Save email preferences', 'packout-core' ) . '</button></p></form></section>';

		echo '<section><h3>' . esc_html__( 'Organizations', 'packout-core' ) . '</h3>';
		if ( ! $orgs ) {
			echo '<p>' . esc_html__( 'Not in an organization yet.', 'packout-core' ) . '</p>';
		}
		foreach ( $orgs as $team_id => $title ) {
			$lead    = Teams::lead( (int) $team_id ) === $id;
			$colead  = in_array( $id, Teams::coleads( (int) $team_id ), true );
			$subs    = Subteams::of_user( (int) $team_id, $id );
			$subtext = $subs ? implode( ', ', Subteams::names( (int) $team_id, $subs ) ) : '';
			echo '<div class="packout-pane__hint"><span><strong>' . esc_html( $title ) . '</strong>';
			if ( $lead ) {
				echo ' ' . Admin::badge( __( 'Lead', 'packout-core' ), 'stable' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( $colead ) {
				echo ' ' . Admin::badge( __( 'Co-lead', 'packout-core' ), 'stable' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( '' !== $subtext ) {
				echo '<br /><span class="description">' . esc_html( $subtext ) . '</span>';
			}
			echo '</span>';
			if ( ! $lead ) {
				self::open_form( $id, 'remove_member' );
				echo '<input type="hidden" name="team" value="' . esc_attr( (string) $team_id ) . '" />';
				echo '<button class="button">' . esc_html__( 'Remove', 'packout-core' ) . '</button></form>';
			}
			echo '</div>';
		}//end foreach
		self::open_form( $id, 'add_member' );
		echo '<p><label>' . esc_html__( 'Add to an organization', 'packout-core' ) . '<br /><select name="team" required><option value="">' . esc_html__( 'Choose an organization', 'packout-core' ) . '</option>';
		foreach ( Teams::all() as $option ) {
			if ( ! isset( $orgs[ $option->ID ] ) ) {
				echo '<option value="' . esc_attr( (string) $option->ID ) . '">' . esc_html( $option->post_title ) . '</option>';
			}
		}
		echo '</select></label></p>';
		echo '<p><button class="button">' . esc_html__( 'Add to organization', 'packout-core' ) . '</button></p></form></section>';

		echo '<section><h3>' . esc_html__( 'Impact', 'packout-core' ) . '</h3>';
		echo '<dl class="packout-pane__facts">';
		foreach ( array(
			__( 'Spots reported', 'packout-core' ) => (string) count( $done['spots'] ),
			__( 'Cleanups', 'packout-core' )       => (string) count( $done['cleanups'] ),
			__( 'Return visits', 'packout-core' )  => (string) count( $done['visits'] ),
			__( 'Missions', 'packout-core' )       => (string) count( $done['missions'] ),
			__( 'Hours', 'packout-core' )          => (string) $done['hours'],
			__( 'Bags', 'packout-core' )           => (string) $done['bags'],
			__( 'Pounds', 'packout-core' )         => (string) $done['pounds'],
		) as $label => $value ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		echo '</dl></section>';

		echo '<section><h3>' . esc_html__( 'Invitations', 'packout-core' ) . '</h3>';
		echo '<p><a href="' . esc_url(
			add_query_arg(
				array(
					'page'   => Invitations_Screen::PAGE,
					's'      => $user->user_email,
					'status' => 'all',
				),
				admin_url( 'admin.php' )
			)
		) . '">' . esc_html__( 'Invitations for this email', 'packout-core' ) . '</a></p>';
		echo '</section>';

		echo '</div>';
	}

	/**
	 * Pack-Out role labels for a user.
	 *
	 * @param \WP_User $user User.
	 */
	private static function role_labels( \WP_User $user ): string {
		$labels = array();
		foreach ( Capabilities::roles() as $slug => $role ) {
			if ( in_array( $slug, (array) $user->roles, true ) ) {
				$labels[] = $role['label'];
			}
		}
		return $labels ? implode( ', ', $labels ) : __( 'Reporter', 'packout-core' );
	}

	/**
	 * Open an action form.
	 *
	 * @param int    $user_id User ID.
	 * @param string $act      Action.
	 */
	private static function open_form( int $user_id, string $act ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="packout_people_action" />';
		echo '<input type="hidden" name="user" value="' . esc_attr( (string) $user_id ) . '" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $act ) . '" />';
		wp_nonce_field( 'packout_people_action_' . $user_id );
	}
}
