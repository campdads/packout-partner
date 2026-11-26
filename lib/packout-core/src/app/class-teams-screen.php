<?php
/**
 * Teams screen.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The teams list and the team drawer.
 *
 * A coordinator creates teams, names the lead, approves or declines the teams
 * people propose, and decides whether a team is listed publicly. The lead of
 * a team runs it from My teams on the public site.
 */
class Teams_Screen {

	public const PAGE = 'packout-teams';

	/**
	 * Teams the current user may open: all of them for a coordinator, the
	 * ones they lead for a lead.
	 *
	 * @return array<int, \WP_Post>
	 */
	public static function visible(): array {
		return array_values( array_filter( Teams::all(), static fn( $team ) => Teams::can_manage_members( $team->ID ) ) );
	}

	/**
	 * The list and the drawer.
	 */
	public static function render(): void {
		// The overview of one organization, at this screen's address so the menu item stays selected.
		if ( Overview_Page::requested() > 0 ) {
			Overview_Page::render( 'team', Overview_Page::requested() );
			return;
		}
		Admin::require( Teams::can_edit() || Missions::can_lead() );
		$teams = self::visible();
		$edit  = Teams::can_edit();

		echo '<div class="wrap packout-admin">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Organizations', 'packout-core' ) . '</h1>';
		if ( $edit ) {
			echo ' <button type="button" class="page-title-action" data-packout-open="new" data-packout-label="' . esc_attr__( 'Add an organization', 'packout-core' ) . '">' . esc_html__( 'Add an organization', 'packout-core' ) . '</button>';
		}
		echo '<hr class="wp-header-end" />';

		Admin::notice(
			array(
				'saved'       => __( 'Organization saved.', 'packout-core' ),
				'place_asked' => __( 'packout.spot was asked. A coordinator there answers, and the answer appears on the Connection screen.', 'packout-core' ),
				'logo'        => __( 'Logo saved.', 'packout-core' ),
				'unlogo'      => __( 'Logo removed.', 'packout-core' ),
				'cover'       => __( 'Cover image saved.', 'packout-core' ),
				'uncover'     => __( 'Cover image removed.', 'packout-core' ),
				'added'       => __( 'Member added. They see the organization\'s missions in the field app after their next sync.', 'packout-core' ),
				'crew'        => __( 'Member added and given the Crew role, so they can use the field app.', 'packout-core' ),
				'removed'     => __( 'Member removed. Their account is unchanged.', 'packout-core' ),
				'trashed'     => __( 'Organization moved to the trash.', 'packout-core' ),
				'approved'    => __( 'Organization approved. Its lead has been told and can now plan campaigns and missions.', 'packout-core' ),
				'declined'    => __( 'Organization declined and removed. The person who proposed it has been told why.', 'packout-core' ),
			),
			array(
				'team'     => __( 'That organization could not be changed.', 'packout-core' ),
				'missions' => __( 'This organization has an open mission. Complete it or call it off before removing the organization.', 'packout-core' ),
			)
		);

		if ( ! $teams ) {
			echo '<div class="packout-admin__empty">';
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
			echo Admin::ui(
				'empty-state',
				array(
					'title'       => $edit ? __( 'No organizations yet', 'packout-core' ) : __( 'You do not lead an organization yet', 'packout-core' ),
					'description' => $edit ? __( 'An organization is a crew with a lead. It plans missions and gets the credit for them.', 'packout-core' ) : __( 'A coordinator creates organizations and names their leads.', 'packout-core' ),
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		} else {
			$rows = array();
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The overview opens this list on waiting or approved organizations.
			$listing = isset( $_GET['listing'] ) ? sanitize_key( wp_unslash( $_GET['listing'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			if ( 'approved' === $listing ) {
				$teams = array_values( array_filter( $teams, static fn( $team ) => Teams::active( $team->ID ) ) );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Overview deep link.
			if ( isset( $_GET['dormant'] ) && '1' === sanitize_key( wp_unslash( $_GET['dormant'] ) ) ) {
				$dormant = array_fill_keys( Overview::dormant_ids(), true );
				$teams   = array_values(
					array_filter(
						$teams,
						static fn( $team ) => isset( $dormant[ $team->ID ] )
					)
				);
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A partner's overview and its drawer open this list on its organizations.
			$under = isset( $_GET['partner'] ) ? (int) $_GET['partner'] : 0;
			if ( $under > 0 && Partners::nameable( $under ) ) {
				$teams = array_values( array_filter( $teams, static fn( $team ) => Partners::of_team( $team->ID ) === $under ) );
				/* translators: %s: partner name. */
				echo '<p>' . esc_html( sprintf( __( 'Organizations of %s.', 'packout-core' ), get_the_title( $under ) ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Show every organization', 'packout-core' ) . '</a></p>';
			}
			foreach ( $teams as $team ) {
				$lead   = get_userdata( Teams::lead( $team->ID ) );
				$over   = Partners::get( Partners::of_team( $team->ID ) );
				$rows[] = array(
					'id'       => (string) $team->ID,
					'title'    => $team->post_title,
					'url'      => Admin::pane_url( (string) $team->ID ),
					'partner'  => $over ? $over->post_title : '—',
					'lead'     => $lead ? $lead->display_name : '—',
					'members'  => count( Teams::members( $team->ID ) ),
					'missions' => self::open_missions( $team->ID ),
					'listing'  => ! Teams::active( $team->ID ) ? __( 'Waiting for approval', 'packout-core' ) : ( 'publish' === $team->post_status ? __( 'Listed publicly', 'packout-core' ) : __( 'Not listed', 'packout-core' ) ),
				);
			}
			$waiting = 'waiting' === $listing;
			Admin_Views::render(
				'teams',
				array(
					'partner'  => array(
						'label'  => __( 'Partner', 'packout-core' ),
						'search' => true,
					),
					'lead'     => array(
						'label'  => __( 'Lead', 'packout-core' ),
						'search' => true,
					),
					'members'  => array(
						'label' => __( 'Members', 'packout-core' ),
						'type'  => 'integer',
					),
					'missions' => array(
						'label' => __( 'Open missions', 'packout-core' ),
						'type'  => 'integer',
					),
					'listing'  => array( 'label' => __( 'Listing', 'packout-core' ) ),
				),
				$rows,
				array(
					'title'   => __( 'Organization', 'packout-core' ),
					'empty'   => __( 'No organizations match.', 'packout-core' ),
					'actions' => array(
						array(
							'id'    => 'overview',
							'label' => __( 'Overview', 'packout-core' ),
							'url'   => add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) ) . '&overview={id}',
						),
					),
					'filters' => $waiting ? array(
						array(
							'field' => 'listing',
							'value' => __( 'Waiting for approval', 'packout-core' ),
						),
					) : array(),
				)
			);
		}//end if

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Opens the organization named in an approval email.
		$open = isset( $_GET['open'] ) ? (int) $_GET['open'] : 0;
		if ( $open > 0 && Teams::get( $open ) && Teams::can_manage_members( $open ) ) {
			Admin::reopen( (string) $open, get_the_title( $open ) );
		}

		Admin::drawer(
			__( 'Organization', 'packout-core' ),
			static function () use ( $teams, $edit ): void {
				foreach ( $teams as $team ) {
					self::pane( $team, $edit );
				}
				if ( $edit ) {
					echo '<div class="packout-drawer__pane packout-pane" data-pane="new" hidden><section>';
					self::details_form( null );
					echo '</section></div>';
				}
			}
		);
		echo '</div>';
	}

	/**
	 * One team: details, members, and removal.
	 *
	 * @param \WP_Post $team Team.
	 * @param bool     $edit Whether the person may change the details.
	 */
	private static function pane( \WP_Post $team, bool $edit ): void {
		$id   = $team->ID;
		$lead = get_userdata( Teams::lead( $id ) );

		echo '<div class="packout-drawer__pane packout-pane" data-pane="' . esc_attr( (string) $id ) . '" hidden>';

		if ( Teams::active( $id ) ) {
			$over = Partners::get( Partners::of_team( $id ) );
			echo '<section><p><a class="button button-primary" href="' . esc_url( Overview_Page::url( 'team', $id ) ) . '">' . esc_html__( 'Overview', 'packout-core' ) . '</a></p>';
			if ( $over ) {
				$named = Overview::can_open( 'partner', $over->ID, get_current_user_id() ) ? '<a href="' . esc_url( Overview_Page::url( 'partner', $over->ID ) ) . '">' . esc_html( $over->post_title ) . '</a>' : esc_html( $over->post_title );
				echo '<dl class="packout-pane__facts"><dt>' . esc_html__( 'Partner', 'packout-core' ) . '</dt><dd>' . $named . '</dd></dl>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
			}
			echo '</section>';
		}

		/**
		 * An organization's pane, after its overview.
		 *
		 * @param \WP_Post $team The organization.
		 */
		do_action( 'packout_teams_pane', $team );

		if ( $edit && ! Teams::active( $id ) ) {
			echo '<section><h3>' . esc_html__( 'Waiting for approval', 'packout-core' ) . '</h3>';
			/* translators: %s: person's name. */
			echo '<p>' . esc_html( sprintf( __( '%s proposed this organization. Until it is approved it is not listed and cannot plan campaigns or missions.', 'packout-core' ), $lead ? $lead->display_name : __( 'Someone', 'packout-core' ) ) ) . '</p>';
			echo '<dl class="packout-pane__facts">';
			foreach ( array(
				__( 'Works in', 'packout-core' ) => Teams::field( $id, 'region' ),
				__( 'Website', 'packout-core' )  => Teams::field( $id, 'website' ),
				__( 'Email', 'packout-core' )    => $lead ? $lead->user_email : '',
				__( 'Co-leads', 'packout-core' ) => implode( ', ', wp_list_pluck( array_filter( array_map( 'get_userdata', Teams::coleads( $id ) ) ), 'display_name' ) ),
			) as $label => $value ) {
				if ( '' !== $value ) {
					echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
				}
			}
			echo '</dl>';
			echo '<p class="description">' . esc_html__( 'Approving gives the Lead role to the lead and to each co-lead named here. A lead plans missions that change the public map, sees the exact location of every reviewed Spot, and can verify impact records. After approval the lead can name more co-leads, and you are emailed each time one is given the role.', 'packout-core' ) . '</p>';
			self::button( $id, 'approve', __( 'Approve the organization', 'packout-core' ), array(), 'button-primary' );
			echo '<details class="packout-pane__trash"><summary>' . esc_html__( 'Decline', 'packout-core' ) . '</summary>';
			self::open_form( $id, 'decline' );
			echo '<p><label>' . esc_html__( 'What to tell the person who proposed it', 'packout-core' ) . '<br /><textarea name="reason" rows="3" required></textarea></label></p>';
			echo '<p class="description">' . esc_html__( 'The organization is removed and this is emailed to them.', 'packout-core' ) . '</p>';
			echo '<p><button class="button button-link-delete">' . esc_html__( 'Decline and remove the organization', 'packout-core' ) . '</button></p></form></details></section>';
		}//end if

		echo '<section><h3>' . esc_html__( 'Details', 'packout-core' ) . '</h3>';
		if ( $edit ) {
			self::details_form( $team );
		} else {
			echo '<p class="packout-pane__story">' . nl2br( esc_html( $team->post_content ) ) . '</p>';
			echo '<dl class="packout-pane__facts"><dt>' . esc_html__( 'Lead', 'packout-core' ) . '</dt><dd>' . esc_html( $lead ? $lead->display_name : '—' ) . '</dd></dl>';
		}
		if ( 'publish' === $team->post_status ) {
			echo '<p><a href="' . esc_url( (string) get_permalink( $team ) ) . '">' . esc_html__( 'Public organization page', 'packout-core' ) . '</a></p>';
		}
		if ( Impact::can_review() ) {
			echo '<p><a href="' . esc_url( add_query_arg( 'team', $team->ID, admin_url( 'admin.php?page=' . Impact_Screen::PAGE ) ) ) . '">' . esc_html__( 'Impact totals and records of this organization', 'packout-core' ) . '</a></p>';
		}
		echo '</section>';

		echo '<section><h3>' . esc_html__( 'Members', 'packout-core' ) . '</h3>';
		$members = array_filter( array_map( 'get_userdata', Teams::members( $id ) ) );
		$coleads = Teams::coleads( $id );
		if ( ! $members ) {
			echo '<p>' . esc_html__( 'No members yet. The lead is in the organization already.', 'packout-core' ) . '</p>';
		}
		foreach ( $members as $member ) {
			$is_colead = in_array( $member->ID, $coleads, true );
			echo '<div class="packout-pane__hint"><span>' . esc_html( $member->display_name ) . ' <span class="description">' . esc_html( $member->user_login ) . '</span>' . ( $is_colead ? ' ' . Admin::badge( __( 'Co-lead', 'packout-core' ), 'stable' ) : '' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The badge is escaped where it is built.
			// A co-lead is removed by the team's lead or a coordinator.
			if ( ! $is_colead || Teams::owns( $id ) ) {
				/* translators: %s: person's name. */
				self::button( $id, 'remove_member', __( 'Remove', 'packout-core' ), array( 'user' => (string) $member->ID ), '', sprintf( __( 'Remove %s from the organization', 'packout-core' ), $member->display_name ) );
			}
			echo '</div>';
		}
		if ( $edit ) {
			self::open_form( $id, 'add_member' );
			echo '<p><label>' . esc_html__( 'Add a person by username or email address', 'packout-core' ) . '<br /><input type="text" name="who" class="widefat" required autocomplete="off" /></label></p>';
			echo '<p class="description">' . esc_html__( 'The person needs an account on this site first. Someone who cannot use the field app yet is given the Crew role.', 'packout-core' ) . '</p>';
			echo '<p><button class="button">' . esc_html__( 'Add to the organization', 'packout-core' ) . '</button></p></form>';
		}
		if ( Accounts::open() ) {
			echo '<p><a href="' . esc_url(
				Accounts::workspace_url(
					array(
						'team' => $id,
						'view' => 'members',
					)
				)
			) . '">' . esc_html__( 'Invite people, answer requests, and set up teams in My organizations', 'packout-core' ) . '</a></p>';
		}
		echo '</section>';

		if ( $edit ) {
			echo '<section><details class="packout-pane__trash"><summary>' . esc_html__( 'Move to trash', 'packout-core' ) . '</summary>';
			echo '<p>' . esc_html__( 'The organization leaves this list and the public list. Its finished missions keep their records.', 'packout-core' ) . '</p>';
			self::button( $id, 'trash', __( 'Move this organization to the trash', 'packout-core' ), array(), 'button-link-delete' );
			echo '</details></section>';
		}
		echo '</div>';
	}

	/**
	 * Name, description, lead, and listing.
	 *
	 * @param \WP_Post|null $team Team, or null for a new one.
	 */
	private static function details_form( ?\WP_Post $team ): void {
		$id      = $team ? $team->ID : 0;
		$current = $team ? Teams::lead( $id ) : get_current_user_id();
		$leads   = get_users(
			array(
				'capability' => Capabilities::LEAD,
				'orderby'    => 'display_name',
				'number'     => 200,
			)
		);

		self::open_form( $id, 'save' );
		echo '<p><label>' . esc_html__( 'Name', 'packout-core' ) . '<br /><input type="text" name="name" class="widefat" maxlength="120" required value="' . esc_attr( $team ? $team->post_title : '' ) . '" /></label></p>';
		echo '<p><label>' . esc_html__( 'Description, shown on the public list', 'packout-core' ) . '<br /><textarea name="description" rows="3">' . esc_textarea( $team ? $team->post_content : '' ) . '</textarea></label></p>';
		if ( $team ) {
			$status = 'packout-team-address-' . $id;
			echo '<p><label>' . esc_html__( 'Address', 'packout-core' ) . '<br /><code>' . esc_html( trailingslashit( Teams::address_url( $id, '' ) ) ) . '</code> <input type="text" name="address" required value="' . esc_attr( Teams::address( $id ) ) . '"' . Teams::address_attributes( $id, $status ) . ' /></label><br /><span class="packout-address-status" id="' . esc_attr( $status ) . '" role="status"></span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- address_attributes() escapes.
			echo '<p class="description">' . esc_html__( 'The last part of the organization\'s public page: lower-case letters, digits, and hyphens. Links to the old address keep working.', 'packout-core' ) . '</p>';
		}
		echo '<p><label>' . esc_html__( 'Lead', 'packout-core' ) . '<br /><select name="lead" required>';
		$listed = wp_list_pluck( $leads, 'ID' );
		$holder = get_userdata( $current );
		if ( $holder && ! in_array( $current, array_map( 'intval', $listed ), true ) ) {
			// The founder of a team that is waiting cannot lead missions yet, and is still its lead.
			echo '<option value="' . esc_attr( (string) $current ) . '" selected>' . esc_html( $holder->display_name ) . '</option>';
		}
		foreach ( $leads as $lead ) {
			echo '<option value="' . esc_attr( (string) $lead->ID ) . '"' . selected( $current, $lead->ID, false ) . '>' . esc_html( $lead->display_name ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p class="description">' . esc_html__( 'Anyone with the Lead or Coordinator role, or an administrator, can lead an organization.', 'packout-core' ) . '</p>';
		if ( ! $team || Teams::active( $id ) ) {
			echo '<p><label><input type="checkbox" name="listed" value="1"' . checked( ! $team || 'publish' === $team->post_status, true, false ) . ' /> ' . esc_html__( 'List this organization publicly, with its name and description', 'packout-core' ) . '</label></p>';
		}
		echo '<p><button class="button button-primary">' . esc_html( $team ? __( 'Save the organization', 'packout-core' ) : __( 'Add the organization', 'packout-core' ) ) . '</button></p></form>';

		if ( $team && Teams::active( $id ) ) {
			$has = Teams::logo( $id ) > 0;
			echo '<h3>' . esc_html__( 'Logo', 'packout-core' ) . '</h3>';
			if ( $has ) {
				echo '<p>' . Teams::logo_html( $id, 'packout-team-logo' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An image tag from WordPress.
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-pane__form" enctype="multipart/form-data">';
			echo '<input type="hidden" name="action" value="packout_team_action" /><input type="hidden" name="team" value="' . esc_attr( (string) $id ) . '" /><input type="hidden" name="do" value="logo" />';
			wp_nonce_field( 'packout_team_action_' . $id );
			echo '<p><label>' . esc_html( $has ? __( 'New logo', 'packout-core' ) : __( 'Logo', 'packout-core' ) ) . '<br /><input type="file" name="logo" required accept="' . esc_attr( Photos::accept() ) . '" /></label></p>';
			echo '<p class="description">' . esc_html( Photos::hint() . ' ' . __( 'It is shown on the organization\'s page and at the top of the messages the organization sends.', 'packout-core' ) ) . '</p>';
			echo '<p><button class="button">' . esc_html( $has ? __( 'Replace the logo', 'packout-core' ) : __( 'Upload the logo', 'packout-core' ) ) . '</button></p></form>';
			if ( $has ) {
				self::open_form( $id, 'logo_remove' );
				echo '<p><button class="button button-link-delete">' . esc_html__( 'Remove the logo', 'packout-core' ) . '</button></p></form>';
			}

			$cover = Teams::cover( $id );
			echo '<h3>' . esc_html__( 'Cover image', 'packout-core' ) . '</h3>';
			if ( $cover > 0 ) {
				echo '<p>' . wp_get_attachment_image( $cover, 'medium', false, array( 'alt' => '' ) ) . '</p>';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-pane__form" enctype="multipart/form-data">';
			echo '<input type="hidden" name="action" value="packout_team_action" /><input type="hidden" name="team" value="' . esc_attr( (string) $id ) . '" /><input type="hidden" name="do" value="cover" />';
			wp_nonce_field( 'packout_team_action_' . $id );
			echo '<p><label>' . esc_html( $cover > 0 ? __( 'New cover image', 'packout-core' ) : __( 'Cover image', 'packout-core' ) ) . '<br /><input type="file" name="cover" required accept="' . esc_attr( Photos::accept() ) . '" /></label></p>';
			echo '<p class="description">' . esc_html( Photos::hint() . ' ' . __( 'It is the picture behind the organization\'s name on its public page. A wide photo reads best.', 'packout-core' ) ) . '</p>';
			echo '<p><button class="button">' . esc_html( $cover > 0 ? __( 'Replace the cover image', 'packout-core' ) : __( 'Upload the cover image', 'packout-core' ) ) . '</button></p></form>';
			if ( $cover > 0 ) {
				self::open_form( $id, 'cover_remove' );
				echo '<p><button class="button button-link-delete">' . esc_html__( 'Remove the cover image', 'packout-core' ) . '</button></p></form>';
			}
		}//end if
	}

	/**
	 * How many missions of a team are still open.
	 *
	 * @param int $team_id Team ID.
	 */
	private static function open_missions( int $team_id ): int {
		$missions = get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 200,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Missions of one team.
				'meta_key'    => '_pos_team',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Missions of one team.
				'meta_value'  => $team_id,
			)
		);
		return count( array_filter( $missions, static fn( $mission ) => Missions::is_open( (int) $mission ) ) );
	}

	/**
	 * Open a team form.
	 *
	 * @param int    $id Team ID, or 0 for a new team.
	 * @param string $act Action name.
	 */
	private static function open_form( int $id, string $act ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-pane__form">';
		echo '<input type="hidden" name="action" value="packout_team_action" />';
		echo '<input type="hidden" name="team" value="' . esc_attr( (string) $id ) . '" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $act ) . '" />';
		wp_nonce_field( 'packout_team_action_' . $id );
	}

	/**
	 * A team action that is one button.
	 *
	 * @param int                   $id     Team ID.
	 * @param string                $act     Action name.
	 * @param string                $label  Button label.
	 * @param array<string, string> $fields Extra hidden fields.
	 * @param string                $classes  Extra button class.
	 * @param string                $spoken Longer label for screen readers.
	 */
	private static function button( int $id, string $act, string $label, array $fields = array(), string $classes = '', string $spoken = '' ): void {
		self::open_form( $id, $act );
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		echo '<button class="button ' . esc_attr( $classes ) . '"' . ( '' !== $spoken ? ' aria-label="' . esc_attr( $spoken ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Apply a team action and return to the list.
	 */
	public static function action(): void {
		$id = isset( $_POST['team'] ) ? (int) $_POST['team'] : 0;
		Admin::require( Teams::can_edit() || ( $id > 0 && Teams::can_manage_members( $id ) ) );
		check_admin_referer( 'packout_team_action_' . $id );
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$result = array( 'error' => 'team' );
		$done   = null;

		if ( $id > 0 && ! Teams::get( $id ) ) {
			Admin::back( self::PAGE, $result );
		}

		switch ( $do ) {
			case 'save':
				Admin::require( Teams::can_edit() );
				$done = Teams::save(
					$id,
					array(
						'name'        => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
						'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
						'lead'        => isset( $_POST['lead'] ) ? (int) $_POST['lead'] : 0,
						'listed'      => ! empty( $_POST['listed'] ),
					)
				);
				if ( ! is_wp_error( $done ) ) {
					$id     = (int) $done;
					$result = array( 'updated' => 'saved' );
					if ( isset( $_POST['address'] ) ) {
						// The rest of the team is saved either way. A refused address is said on its own.
						$moved = Teams::set_address( $id, sanitize_text_field( wp_unslash( $_POST['address'] ) ), get_current_user_id() );
						$done  = is_wp_error( $moved ) ? $moved : $done;
					}
				}
				break;
			case 'logo':
				Admin::require( Teams::can_edit() );
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The file is checked by its contents when stored.
				$file = isset( $_FILES['logo'] ) && is_array( $_FILES['logo'] ) && UPLOAD_ERR_OK === (int) ( $_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE ) ? $_FILES['logo'] : null;
				$done = $file ? Teams::set_logo( $id, $file, get_current_user_id() ) : new \WP_Error( 'packout_file_upload', __( 'The file did not arrive. It may be too large. Try a smaller one.', 'packout-core' ) );
				if ( ! is_wp_error( $done ) ) {
					$result = array( 'updated' => 'logo' );
				}
				break;
			case 'logo_remove':
				Admin::require( Teams::can_edit() );
				$done = Teams::remove_logo( $id, get_current_user_id() );
				if ( ! is_wp_error( $done ) ) {
					$result = array( 'updated' => 'unlogo' );
				}
				break;
			case 'cover':
				Admin::require( Teams::can_edit() );
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The file is checked by its contents when stored.
				$file = isset( $_FILES['cover'] ) && is_array( $_FILES['cover'] ) && UPLOAD_ERR_OK === (int) ( $_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE ) ? $_FILES['cover'] : null;
				$done = $file ? Teams::set_cover( $id, $file, get_current_user_id() ) : new \WP_Error( 'packout_file_upload', __( 'The file did not arrive. It may be too large. Try a smaller one.', 'packout-core' ) );
				if ( ! is_wp_error( $done ) ) {
					$result = array( 'updated' => 'cover' );
				}
				break;
			case 'cover_remove':
				Admin::require( Teams::can_edit() );
				$done = Teams::set_cover( $id, null, get_current_user_id() );
				if ( ! is_wp_error( $done ) ) {
					$result = array( 'updated' => 'uncover' );
				}
				break;
			case 'add_member':
				// Putting a person on a team without asking them is a coordinator's call. A lead invites from My teams.
				Admin::require( Teams::can_edit() );
				$who    = isset( $_POST['who'] ) ? sanitize_text_field( wp_unslash( $_POST['who'] ) ) : '';
				$before = is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who );
				$could  = $before && Capabilities::can( Capabilities::REPORT, $before->ID );
				$done   = Teams::add_member( $id, $who );
				$result = array( 'updated' => $could ? 'added' : 'crew' );
				break;
			case 'approve':
				$done   = Teams::approve( $id, get_current_user_id() );
				$result = array( 'updated' => 'approved' );
				break;
			case 'decline':
				$done = Teams::decline( $id, isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '', get_current_user_id() );
				if ( ! is_wp_error( $done ) ) {
					Admin::back( self::PAGE, array( 'updated' => 'declined' ) );
				}
				break;
			case 'remove_member':
				$who = isset( $_POST['user'] ) ? (int) $_POST['user'] : 0;
				// A co-lead is removed by the team's lead or a coordinator, not by another co-lead.
				Admin::require( Teams::owns( $id ) || ! in_array( $who, Teams::coleads( $id ), true ) );
				$result = Teams::remove_member( $id, $who ) ? array( 'updated' => 'removed' ) : $result;
				break;
			case 'trash':
				Admin::require( Teams::can_edit() );
				if ( self::open_missions( $id ) > 0 ) {
					$result = array( 'error' => 'missions' );
				} elseif ( wp_trash_post( $id ) ) {
					Teams::flush();
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
