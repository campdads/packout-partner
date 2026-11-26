<?php
/**
 * The Invitations screen.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinator list of invitations, with filters, resend, and a history drawer.
 */
class Invitations_Screen {

	public const PAGE = 'packout-invitations';

	/**
	 * The list and the drawer.
	 */
	public static function render(): void {
		Admin::guard();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- List filters and the open drawer.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( ! in_array( $status, array( Invitations::STATUS_PENDING, Invitations::STATUS_ACCEPTED, Invitations::STATUS_EXPIRED, Invitations::STATUS_REVOKED, 'all', '' ), true ) ) {
			$status = '';
		}
		$filters = array(
			'status'  => $status,
			's'       => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'team'    => isset( $_GET['team'] ) ? (int) $_GET['team'] : 0,
			'partner' => isset( $_GET['partner'] ) ? (int) $_GET['partner'] : 0,
			'by'      => isset( $_GET['by'] ) ? (int) $_GET['by'] : 0,
		);
		$open    = isset( $_GET['open'] ) ? (int) $_GET['open'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$invites = array();
		$page    = 1;
		$total   = 0;
		do {
			$listed  = Invitations::list(
				array(
					'status' => 'all',
					's'      => '',
					'team'   => 0,
					'by'     => 0,
				),
				$page,
				100
			);
			$invites = array_merge( $invites, $listed['rows'] );
			$total   = $listed['total'];
			$held    = count( $invites );
			++$page;
		} while ( $held < $total && $page <= 50 );

		echo '<div class="wrap packout-admin">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Invitations', 'packout-core' ) . '</h1>';
		echo '<hr class="wp-header-end" />';

		Admin::notice(
			array(
				'resent'  => __( 'Invitation sent again. The new link is good for fourteen days.', 'packout-core' ),
				'revoked' => __( 'Invitation revoked.', 'packout-core' ),
			),
			array(
				'invite' => __( 'That invitation could not be changed.', 'packout-core' ),
			)
		);

		if ( ! $invites ) {
			echo '<div class="packout-admin__empty">';
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
			echo Admin::ui(
				'empty-state',
				array(
					'title'       => __( 'No invitations yet', 'packout-core' ),
					'description' => __( 'Invitations are recorded when an organization lead or a coordinator sends one. History starts from then.', 'packout-core' ),
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		} else {
			$view_filters = array();
			$labels       = Invitations::statuses();
			if ( isset( $labels[ $filters['status'] ] ) ) {
				$view_filters[] = array(
					'field' => 'status',
					'value' => $labels[ $filters['status'] ],
				);
			}
			if ( $filters['team'] > 0 && Teams::get( $filters['team'] ) ) {
				$view_filters[] = array(
					'field' => 'organization',
					'value' => Teams::get( $filters['team'] )->post_title,
				);
			} elseif ( $filters['partner'] > 0 && Partners::get( $filters['partner'] ) ) {
				// An invitation to lead a partner is listed under the partner's name.
				$view_filters[] = array(
					'field' => 'organization',
					'value' => Partners::get( $filters['partner'] )->post_title,
				);
			}
			$sender_filter = $filters['by'] > 0 ? get_userdata( $filters['by'] ) : false;
			if ( $sender_filter ) {
				$view_filters[] = array(
					'field' => 'sent_by',
					'value' => $sender_filter->display_name,
				);
			}
			$rows = array();
			foreach ( $invites as $row ) {
				$partner = 'partner' === ( $row['scope'] ?? '' );
				$org     = $partner ? Partners::get( (int) $row['scope_id'] ) : Teams::get( (int) $row['team_id'] );
				$sender  = get_userdata( (int) $row['invited_by'] );
				$sub     = (int) $row['subteam_id'] > 0 ? Subteams::get( (int) $row['team_id'], (int) $row['subteam_id'] ) : null;
				$shown   = Invitations::display_status( $row );
				$sent    = strtotime( (string) $row['sent_at'] . ' UTC' );
				$rows[]  = array(
					'id'           => (string) $row['id'],
					'title'        => (string) $row['email'],
					'url'          => Admin::pane_url( (string) $row['id'] ),
					'organization' => $org ? $org->post_title : ( $partner ? __( 'Removed partner', 'packout-core' ) : __( 'Removed organization', 'packout-core' ) ),
					'for'          => $partner ? __( 'To co-lead a partner', 'packout-core' ) : __( 'To join an organization', 'packout-core' ),
					'team'         => $sub['name'] ?? '—',
					'sent_by'      => $sender ? $sender->display_name : '—',
					'sent'         => $sent ? wp_date( 'Y-m-d H:i', $sent ) : '—',
					'status'       => $labels[ $shown ] ?? $shown,
				);
			}
			Admin_Views::render(
				'invitations',
				array(
					'organization' => array(
						'label'  => __( 'Organization or partner', 'packout-core' ),
						'search' => true,
					),
					'for'          => array( 'label' => __( 'Invited', 'packout-core' ) ),
					'team'         => array( 'label' => __( 'Team', 'packout-core' ) ),
					'sent_by'      => array(
						'label'  => __( 'Sent by', 'packout-core' ),
						'search' => true,
					),
					'sent'         => array( 'label' => __( 'Sent', 'packout-core' ) ),
					'status'       => array( 'label' => __( 'Status', 'packout-core' ) ),
				),
				$rows,
				array(
					'title'   => __( 'Email', 'packout-core' ),
					'empty'   => __( 'No invitations match.', 'packout-core' ),
					'search'  => $filters['s'],
					'filters' => $view_filters,
					'sort'    => array(
						'field'     => 'sent',
						'direction' => 'desc',
					),
				)
			);
		}//end if

		$detail = $open > 0 ? Invitations::get( $open ) : null;
		if ( $detail ) {
			Admin::reopen( (string) $open, (string) $detail['email'] );
			$known = false;
			foreach ( $invites as $row ) {
				if ( (int) $row['id'] === $open ) {
					$known = true;
					break;
				}
			}
			if ( ! $known ) {
				$invites[] = $detail;
			}
		}
		if ( $invites ) {
			Admin::drawer(
				__( 'Invitation', 'packout-core' ),
				static function () use ( $invites ): void {
					foreach ( $invites as $row ) {
						self::pane( $row, Teams::get( (int) $row['team_id'] ) );
					}
				}
			);
		}
		echo '</div>';
	}

	/**
	 * Form handler.
	 */
	public static function action(): void {
		Admin::guard();
		$id = isset( $_POST['invitation'] ) ? (int) $_POST['invitation'] : 0;
		check_admin_referer( 'packout_invitation_action_' . $id );
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$row = Invitations::get( $id );
		if ( ! $row ) {
			Admin::back( self::PAGE, array( 'error' => 'invite' ) );
		}

		$result = array( 'open' => (string) $id );
		if ( 'resend' === $do ) {
			$done = Invitations::resend( $id, get_current_user_id() );
			if ( is_wp_error( $done ) ) {
				$result['error'] = 'invite';
			} else {
				$result['updated'] = 'resent';
			}
		} elseif ( 'revoke' === $do ) {
			$done = Invitations::revoke( $id, get_current_user_id() );
			if ( is_wp_error( $done ) ) {
				$result['error'] = 'invite';
			} else {
				$result['updated'] = 'revoked';
			}
		} else {
			$result['error'] = 'invite';
		}
		Admin::back( self::PAGE, $result );
	}

	/**
	 * Drawer pane for one invitation.
	 *
	 * @param array<string, mixed> $row Invitation.
	 * @param \WP_Post|null        $org Organization.
	 */
	private static function pane( array $row, ?\WP_Post $org ): void {
		$id     = (int) $row['id'];
		$status = Invitations::display_status( $row );
		$sender = get_userdata( (int) $row['invited_by'] );
		$who    = ! empty( $row['accepted_user_id'] ) ? get_userdata( (int) $row['accepted_user_id'] ) : null;
		$sub    = (int) $row['subteam_id'] > 0 ? Subteams::get( (int) $row['team_id'], (int) $row['subteam_id'] ) : null;

		echo '<div class="packout-drawer__pane packout-pane" data-pane="' . esc_attr( (string) $id ) . '" hidden>';
		echo '<section><h3>' . esc_html__( 'Invitation', 'packout-core' ) . '</h3>';
		echo '<dl class="packout-pane__facts">';
		foreach ( array(
			__( 'Email', 'packout-core' )        => (string) $row['email'],
			__( 'Organization', 'packout-core' ) => $org ? $org->post_title : __( 'Removed organization', 'packout-core' ),
			__( 'Team', 'packout-core' )         => $sub['name'] ?? '',
			__( 'Offer', 'packout-core' )        => Invitations::offer_label( $row ),
			__( 'Status', 'packout-core' )       => Invitations::statuses()[ $status ] ?? $status,
			__( 'Sent by', 'packout-core' )      => $sender ? $sender->display_name : '',
			__( 'Sent', 'packout-core' )         => self::when( (string) $row['sent_at'] ),
			__( 'Expires', 'packout-core' )      => self::when( (string) $row['expires_at'] ),
			__( 'Accepted', 'packout-core' )     => $who ? $who->display_name . ' · ' . self::when( (string) $row['accepted_at'] ) : '',
		) as $label => $value ) {
			if ( '' !== (string) $value ) {
				echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd>';
			}
		}
		echo '</dl></section>';

		echo '<section><h3>' . esc_html__( 'History', 'packout-core' ) . '</h3>';
		echo Admin::timeline( Invitations::events( $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- timeline() escapes.
		echo '</section>';

		if ( in_array( $status, array( Invitations::STATUS_PENDING, Invitations::STATUS_EXPIRED ), true ) && $org ) {
			echo '<section><h3>' . esc_html__( 'Actions', 'packout-core' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Resending sends a new link and extends the invitation another fourteen days.', 'packout-core' ) . '</p>';
			self::button( $id, 'resend', __( 'Resend invitation', 'packout-core' ), 'button-primary' );
			echo '<details class="packout-pane__trash"><summary>' . esc_html__( 'Revoke', 'packout-core' ) . '</summary>';
			echo '<p>' . esc_html__( 'The person can no longer use this invitation. They can still be invited again later.', 'packout-core' ) . '</p>';
			self::button( $id, 'revoke', __( 'Revoke this invitation', 'packout-core' ), 'button-link-delete' );
			echo '</details></section>';
		}
		echo '</div>';
	}

	/**
	 * Format a GMT datetime for the screen.
	 *
	 * @param string $gmt MySQL GMT datetime.
	 */
	private static function when( string $gmt ): string {
		if ( '' === $gmt ) {
			return '—';
		}
		$ts = strtotime( $gmt . ' UTC' );
		return $ts ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '—';
	}

	/**
	 * Open a post form and print a submit button.
	 *
	 * @param int    $id    Invitation ID.
	 * @param string $act    Action.
	 * @param string $label Button label.
	 * @param string $classes Extra button class.
	 */
	private static function button( int $id, string $act, string $label, string $classes = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="packout_invitation_action" />';
		echo '<input type="hidden" name="invitation" value="' . esc_attr( (string) $id ) . '" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $act ) . '" />';
		wp_nonce_field( 'packout_invitation_action_' . $id );
		echo '<p><button class="button ' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button></p></form>';
	}
}
