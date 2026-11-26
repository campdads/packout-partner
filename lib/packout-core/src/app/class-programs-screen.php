<?php
/**
 * Programs screen.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The programs list and the program drawer.
 *
 * A coordinator sees every program. A lead sees the ones they may change.
 * A program is opened and changed in the workspace of the partner or the
 * organization that owns it. This screen is where a person finds one, reads
 * how far it has come, downloads its report, closes it, and removes it.
 */
class Programs_Screen {

	public const PAGE = 'packout-programs';

	/**
	 * Programs the current user may change, by name.
	 *
	 * @param int $partner Only the programs of this partner, or 0 for all.
	 * @param int $team    Only the programs this organization owns or takes part in, or 0 for all.
	 * @return array<int, \WP_Post>
	 */
	public static function visible( int $partner = 0, int $team = 0 ): array {
		$programs = Programs::changeable( get_current_user_id() );
		if ( $partner > 0 ) {
			$programs = array_filter( $programs, static fn( $program ) => (int) get_post_meta( $program->ID, '_pos_partner', true ) === $partner );
		}
		if ( $team > 0 ) {
			$programs = array_filter( $programs, static fn( $program ) => in_array( $team, Programs::taking_part( $program->ID ), true ) );
		}
		return array_values( $programs );
	}

	/**
	 * Where a program is changed: the workspace of its owner.
	 *
	 * @param int $id Program ID.
	 */
	public static function workspace_url( int $id ): string {
		$owner = Programs::owner( $id );
		return $owner['id'] > 0 ? Workspace::url( $owner['id'], 'program', (string) $id ) : '';
	}

	/**
	 * The list and the drawer.
	 */
	public static function render(): void {
		Admin::require( Capabilities::can( Capabilities::LEAD ) );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- List filters and the open drawer.
		$partner = isset( $_GET['partner'] ) ? (int) $_GET['partner'] : 0;
		$team    = isset( $_GET['team'] ) ? (int) $_GET['team'] : 0;
		$open    = isset( $_GET['open'] ) ? (int) $_GET['open'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$programs = self::visible( $partner, $team );
		$scope    = $partner > 0 ? Partners::get( $partner ) : ( $team > 0 ? Teams::get( $team ) : null );

		echo '<div class="wrap packout-admin">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Programs', 'packout-core' ) . '</h1>';
		echo '<hr class="wp-header-end" />';
		if ( $scope ) {
			/* translators: %s: partner or organization name. */
			echo '<p>' . esc_html( sprintf( __( 'Programs of %s.', 'packout-core' ), $scope->post_title ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Show every program', 'packout-core' ) . '</a></p>';
		}

		Admin::notice(
			array(
				'closed'   => __( 'Program closed. Nothing new can be filed under it. What is filed stays.', 'packout-core' ),
				'reopened' => __( 'Program open again.', 'packout-core' ),
				'removed'  => __( 'Program removed. Its campaigns and missions stay with their organizations, in no program.', 'packout-core' ),
			),
			array(
				'program' => __( 'That program could not be changed.', 'packout-core' ),
			)
		);

		if ( ! $programs ) {
			echo '<div class="packout-admin__empty">';
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
			echo Admin::ui(
				'empty-state',
				array(
					'title'       => $scope ? __( 'No programs here', 'packout-core' ) : __( 'No programs yet', 'packout-core' ),
					'description' => __( 'A program is long-running work with a goal, such as a river corridor or a school year. Campaigns and missions are filed under it. A partner opens one in its Partner workspace, and an organization with no partner opens one in My organizations.', 'packout-core' ),
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div></div>';
			return;
		}

		$rows = array();
		foreach ( $programs as $program ) {
			$id     = $program->ID;
			$owner  = Programs::owner_post( $id );
			$filed  = Programs::filed( $id );
			$rows[] = array(
				'id'        => (string) $id,
				'title'     => $program->post_title,
				'url'       => Admin::pane_url( (string) $id ),
				'owner'     => $owner ? $owner->post_title : '—',
				'kind'      => 'partner' === Programs::owner( $id )['kind'] ? __( 'Partner', 'packout-core' ) : __( 'Organization', 'packout-core' ),
				'state'     => 'open' === Programs::state( $id ) ? __( 'Open', 'packout-core' ) : __( 'Closed', 'packout-core' ),
				'window'    => '' !== Programs::window_line( $id ) ? Programs::window_line( $id ) : '—',
				'campaigns' => $filed['campaigns'],
				'missions'  => $filed['missions'],
				'listing'   => Programs::shown( $id ) ? __( 'Public', 'packout-core' ) : __( 'Not public', 'packout-core' ),
			);
		}
		Admin_Views::render(
			'programs',
			array(
				'owner'     => array(
					'label'  => __( 'Owner', 'packout-core' ),
					'search' => true,
				),
				'kind'      => array( 'label' => __( 'Owned by a', 'packout-core' ) ),
				'state'     => array(
					'label'   => __( 'State', 'packout-core' ),
					'primary' => true,
				),
				'window'    => array(
					'label'  => __( 'When', 'packout-core' ),
					'filter' => false,
				),
				'campaigns' => array(
					'label' => __( 'Campaigns', 'packout-core' ),
					'type'  => 'integer',
				),
				'missions'  => array(
					'label' => __( 'Missions', 'packout-core' ),
					'type'  => 'integer',
				),
				'listing'   => array( 'label' => __( 'Listing', 'packout-core' ) ),
			),
			$rows,
			array(
				'title' => __( 'Program', 'packout-core' ),
				'empty' => __( 'No programs match.', 'packout-core' ),
			)
		);

		if ( $open > 0 && Programs::can_change( $open ) ) {
			Admin::reopen( (string) $open, get_the_title( $open ) );
		}

		Admin::drawer(
			__( 'Program', 'packout-core' ),
			static function () use ( $programs ): void {
				foreach ( $programs as $program ) {
					self::pane( $program );
				}
			}
		);
		echo '</div>';
	}

	/**
	 * One program: what it is for, how far it has come, who takes part, and what can be done to it.
	 *
	 * @param \WP_Post $program Program.
	 */
	private static function pane( \WP_Post $program ): void {
		$id    = $program->ID;
		$owner = Programs::owner_post( $id );
		$kind  = Programs::owner( $id )['kind'];
		$state = Programs::state( $id );
		$goal  = Programs::field( $id, 'goal' );

		echo '<div class="packout-drawer__pane packout-pane" data-pane="' . esc_attr( (string) $id ) . '" hidden>';

		echo '<section>';
		if ( '' !== $goal ) {
			echo '<p class="packout-pane__story">' . esc_html( $goal ) . '</p>';
		}
		echo '<dl class="packout-pane__facts">';
		foreach ( array(
			__( 'Owner', 'packout-core' )    => $owner ? $owner->post_title : '',
			__( 'State', 'packout-core' )    => 'open' === $state ? __( 'Open', 'packout-core' ) : __( 'Closed', 'packout-core' ),
			__( 'When', 'packout-core' )     => Programs::window_line( $id ),
			__( 'Progress', 'packout-core' ) => Programs::progress_line( Programs::progress( $id ) ),
		) as $label => $value ) {
			if ( '' !== $value ) {
				echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
			}
		}
		echo '</dl><p>';
		$workspace = self::workspace_url( $id );
		if ( '' !== $workspace ) {
			echo '<a class="button button-primary" href="' . esc_url( $workspace ) . '">' . esc_html__( 'Open and change it in the workspace', 'packout-core' ) . '</a> ';
		}
		echo str_replace( 'pos-ws__button', 'button', Overview_Report::link( 'program', $id, __( 'Impact report (PDF)', 'packout-core' ), 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link() escapes.
		echo '</p>';
		if ( Programs::shown( $id ) ) {
			echo '<p><a href="' . esc_url( (string) get_permalink( $program ) ) . '">' . esc_html__( 'Public program page', 'packout-core' ) . '</a></p>';
		}
		if ( $owner && Overview::can_open( 'partner' === $kind ? 'partner' : 'team', $owner->ID, get_current_user_id() ) ) {
			/* translators: %s: partner or organization name. */
			echo '<p><a href="' . esc_url( Overview_Page::url( 'partner' === $kind ? 'partner' : 'team', $owner->ID ) ) . '">' . esc_html( sprintf( __( 'Overview of %s', 'packout-core' ), $owner->post_title ) ) . '</a></p>';
		}
		echo '</section>';

		echo '<section><h3>' . esc_html__( 'Taking part', 'packout-core' ) . '</h3>';
		$parts = array_filter( array_map( array( Teams::class, 'get' ), Programs::taking_part( $id ) ) );
		if ( ! $parts ) {
			echo '<p>' . esc_html__( 'No organization takes part yet.', 'packout-core' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $parts as $team ) {
				echo '<li>' . esc_html( $team->post_title ) . ' <span class="description">' . esc_html( Programs::progress_line( Programs::progress( $id, false, $team->ID ) ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '</section>';

		echo '<section><h3>' . esc_html__( 'Filed under it', 'packout-core' ) . '</h3>';
		$campaigns = Programs::campaigns( $id );
		$missions  = Programs::missions( $id );
		if ( ! $campaigns && ! $missions ) {
			echo '<p>' . esc_html__( 'Nothing yet. A campaign or a mission is filed under a program where it is planned.', 'packout-core' ) . '</p>';
		}
		if ( $campaigns ) {
			echo '<h4>' . esc_html__( 'Campaigns', 'packout-core' ) . '</h4><ul>';
			foreach ( $campaigns as $campaign ) {
				echo '<li>' . esc_html( $campaign->post_title ) . '</li>';
			}
			echo '</ul>';
		}
		if ( $missions ) {
			echo '<h4>' . esc_html__( 'Missions', 'packout-core' ) . '</h4>';
			echo '<p><a href="' . esc_url( add_query_arg( 'program', $id, admin_url( 'admin.php?page=packout-missions' ) ) ) . '">';
			/* translators: %d: number of missions. */
			echo esc_html( sprintf( _n( '%d mission on the Missions screen', '%d missions on the Missions screen', count( $missions ), 'packout-core' ), count( $missions ) ) ) . '</a></p>';
		}
		echo '</section>';

		echo '<section>';
		if ( 'open' === $state ) {
			echo '<details class="packout-pane__trash"><summary>' . esc_html__( 'Close the program', 'packout-core' ) . '</summary>';
			echo '<p>' . esc_html__( 'Nothing new can be filed under a closed program. What is filed stays, and its page and its report keep their figures. You can open it again.', 'packout-core' ) . '</p>';
			self::button( $id, 'close', __( 'Close the program', 'packout-core' ) );
			echo '</details>';
		} else {
			self::button( $id, 'reopen', __( 'Open the program again', 'packout-core' ) );
		}
		echo '<details class="packout-pane__trash"><summary>' . esc_html__( 'Remove', 'packout-core' ) . '</summary>';
		echo '<p>' . esc_html__( 'The program and its page are removed. Its campaigns and missions stay with their organizations, in no program, and their impact records are untouched.', 'packout-core' ) . '</p>';
		self::button( $id, 'remove', __( 'Remove this program', 'packout-core' ), 'button-link-delete' );
		echo '</details></section>';
		echo '</div>';
	}

	/**
	 * A program action that is one button.
	 *
	 * @param int    $id    Program ID.
	 * @param string $act    Action name.
	 * @param string $label Button label.
	 * @param string $classes Extra button class.
	 */
	private static function button( int $id, string $act, string $label, string $classes = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-pane__form">';
		echo '<input type="hidden" name="action" value="packout_program_action" />';
		echo '<input type="hidden" name="program" value="' . esc_attr( (string) $id ) . '" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $act ) . '" />';
		wp_nonce_field( 'packout_program_action_' . $id );
		echo '<button class="button ' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Apply a program action and return to the list.
	 */
	public static function action(): void {
		$id = isset( $_POST['program'] ) ? (int) $_POST['program'] : 0;
		Admin::require( $id > 0 && Programs::can_change( $id ) );
		check_admin_referer( 'packout_program_action_' . $id );
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$user   = get_current_user_id();
		$result = array( 'error' => 'program' );
		$done   = null;

		switch ( $do ) {
			case 'close':
			case 'reopen':
				$done = Programs::save( $id, array( 'state' => 'close' === $do ? 'closed' : 'open' ), $user );
				if ( ! is_wp_error( $done ) ) {
					$result = array( 'updated' => 'close' === $do ? 'closed' : 'reopened' );
				}
				break;
			case 'remove':
				$done = Programs::remove( $id, $user );
				if ( ! is_wp_error( $done ) ) {
					Admin::back( self::PAGE, array( 'updated' => 'removed' ) );
				}
				break;
		}

		if ( is_wp_error( $done ) ) {
			$result = array(
				'error'  => 'detail',
				'detail' => $done->get_error_message(),
			);
		}
		$result['open'] = (string) $id;
		Admin::back( self::PAGE, $result );
	}
}
