<?php
/**
 * The Partner workspace.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

use PackOut\Core\Partner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Organization workspace, one level up.
 *
 * A partner's views are on the My organizations page, under a Partners
 * heading, built from the same lists, panes, and forms. The workspace keeps
 * one set of panes and one drawer for a page, so both workspaces share them:
 * the Organization workspace hands a request that names a partner to this
 * class. A page that carries the Partner workspace block shows it there and
 * the Organization workspace leaves it alone.
 *
 * Opening an organization, a campaign, or a mission from here goes to that
 * organization's workspace, where a partner's lead may do what its co-leads
 * may. Nothing here is a second copy of those forms.
 */
class Partner_Workspace {

	public const BLOCK = 'packout/partner-workspace';

	/**
	 * The actions this class answers.
	 */
	private const ACTIONS = array(
		'partner_propose',
		'partner_profile',
		'partner_address',
		'partner_logo',
		'partner_logo_remove',
		'partner_cover',
		'partner_cover_remove',
		'partner_colead',
		'partner_invite',
		'partner_hand_over',
		'partner_org_create',
		'partner_ask',
		'partner_answer',
		'partner_withdraw',
		'partner_release',
		'program_save',
		'program_state',
		'program_remove',
		'program_cover_remove',
		'program_take_over',
	);

	/**
	 * Whether the page being shown carries the Partner workspace block.
	 */
	public static function has_own_block(): bool {
		$post = get_post();
		return $post instanceof \WP_Post && has_block( self::BLOCK, $post );
	}

	/**
	 * Whether a form's action is one of this class's.
	 *
	 * @param string $act Action name.
	 */
	public static function handles( string $act ): bool {
		return in_array( $act, self::ACTIONS, true );
	}

	/**
	 * Address of a partner's view.
	 *
	 * @param int    $partner Partner ID.
	 * @param string $view    View name.
	 * @param string $item    Record the view is about.
	 */
	public static function url( int $partner, string $view = '', string $item = '' ): string {
		return Accounts::workspace_url(
			array_filter(
				array(
					'partner' => $partner > 0 ? (string) $partner : '',
					'view'    => $view,
					'item'    => $item,
				),
				static fn( $value ) => '' !== $value
			)
		);
	}

	/**
	 * Act on one of this class's forms. The workspace has checked the nonce and the person.
	 *
	 * @param string $act   Action name.
	 * @param int    $user The person.
	 * @return array{0: mixed, 1: string, 2: string} The result, what to say, and where to go.
	 */
	public static function act( string $act, int $user ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Workspace::handle() checked the nonce before calling this.
		$text  = static fn( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		$long  = static fn( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		$list  = static fn( string $key ): array => isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? array_values( array_map( 'intval', wp_unslash( $_POST[ $key ] ) ) ) : array();
		$on    = static fn( string $key ): bool => ! empty( $_POST[ $key ] );
		$whole = static fn( string $key ): int => isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : 0;
		$owner = $whole( 'team' );
		$item  = $whole( 'item' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result  = new \WP_Error( 'packout_unknown', __( 'That could not be done.', 'packout-core' ) );
		$message = '';
		$to      = '';
		$choose  = new \WP_Error( 'packout_file_upload', __( 'Choose an image first.', 'packout-core' ) );

		switch ( $act ) {
			case 'partner_propose':
				$result = Partners::propose(
					$user,
					array(
						'name'        => $text( 'name' ),
						'description' => $long( 'description' ),
						'region'      => $text( 'region' ),
						'website'     => $text( 'website' ),
					)
				);
				if ( ! is_wp_error( $result ) ) {
					$to      = self::url( (int) $result );
					$message = __( 'Your partner is waiting for a coordinator. You can set up its profile and invite co-leads now. Organizations and programs open once it is approved.', 'packout-core' );
				}
				break;

			case 'partner_profile':
				$result  = Partners::update_profile(
					$owner,
					array(
						'name'        => $text( 'name' ),
						'description' => $long( 'description' ),
						'region'      => $text( 'region' ),
						'website'     => $text( 'website' ),
						'listed'      => $on( 'listed' ),
						'show_leads'  => $on( 'show_leads' ),
					),
					$user
				);
				$to      = self::url( $owner, 'settings' );
				$message = __( 'Partner saved.', 'packout-core' );
				break;

			case 'partner_address':
				$result  = Partners::set_address( $owner, $text( 'address' ), $user );
				$to      = self::url( $owner, 'settings' ) . '#pos-partner-address';
				$message = false === $result
					? __( 'That is already the partner\'s address.', 'packout-core' )
					: __( 'Address changed. Links to the old address still lead to the partner.', 'packout-core' );
				break;

			case 'partner_logo':
				$file    = Workspace::upload( 'logo' );
				$result  = is_wp_error( $file ) ? $file : ( $file ? Partners::set_logo( $owner, $file, $user ) : $choose );
				$to      = self::url( $owner, 'settings' ) . '#pos-partner-logo';
				$message = __( 'Logo saved.', 'packout-core' );
				break;

			case 'partner_logo_remove':
				$result  = Partners::remove_logo( $owner, $user );
				$to      = self::url( $owner, 'settings' ) . '#pos-partner-logo';
				$message = __( 'Logo removed.', 'packout-core' );
				break;

			case 'partner_cover':
				$file    = Workspace::cover_upload();
				$result  = is_wp_error( $file ) ? $file : ( $file ? Partners::set_cover( $owner, $file, $user ) : $choose );
				$to      = self::url( $owner, 'settings' ) . '#pos-partner-cover';
				$message = __( 'Cover image saved.', 'packout-core' );
				break;

			case 'partner_cover_remove':
				$result  = Partners::set_cover( $owner, null, $user );
				$to      = self::url( $owner, 'settings' ) . '#pos-partner-cover';
				$message = __( 'Cover image removed.', 'packout-core' );
				break;

			case 'partner_colead':
				$result  = Partners::set_colead( $owner, $whole( 'who' ), $on( 'on' ), $user );
				$to      = Partners::can_run( $owner, $user ) ? self::url( $owner, 'settings' ) . '#pos-partner-leads' : Workspace::url();
				$message = $on( 'on' ) ? __( 'They are a co-lead of the partner and have been told.', 'packout-core' ) : __( 'They no longer co-lead the partner.', 'packout-core' );
				break;

			case 'partner_invite':
				$result  = Partners::invite( $owner, $text( 'email' ), $user );
				$to      = self::url( $owner, 'settings' ) . '#pos-partner-leads';
				$message = __( 'The invitation is on its way. The link in it is good for fourteen days.', 'packout-core' );
				break;

			case 'partner_hand_over':
				$result  = Partners::hand_over( $owner, $whole( 'to' ), $user );
				$to      = self::url( $owner );
				$message = __( 'The partner has a new lead. You are still a co-lead.', 'packout-core' );
				break;

			case 'partner_org_create':
				$result = Partners::create_organization(
					$owner,
					array(
						'name'        => $text( 'name' ),
						'description' => $long( 'description' ),
						'region'      => $text( 'region' ),
						'website'     => $text( 'website' ),
					),
					$user
				);
				if ( ! is_wp_error( $result ) ) {
					$to      = Workspace::url( (int) $result );
					$message = __( 'The organization is active. The partner\'s lead leads it until another person is named. Coordinators have been told.', 'packout-core' );
				}
				break;

			case 'partner_ask':
			case 'partner_answer':
			case 'partner_withdraw':
				// The form is on the partner's Organizations view or in the organization's Settings. The owner field says which.
				$from_partner = null !== Partners::get( $owner );
				$partner      = $from_partner ? $owner : $whole( 'partner' );
				$team         = $from_partner ? $whole( 'org' ) : $owner;
				$to           = $from_partner ? self::url( $partner, 'organizations' ) : Workspace::url( $team, 'settings' ) . '#pos-settings-partner';
				if ( 'partner_ask' === $act ) {
					$result  = Partners::ask( $team, $partner, $user );
					$message = 'joined' === $result
						? __( 'The organization is filed under the partner.', 'packout-core' )
						: __( 'The request is sent. Nothing changes until the other side accepts.', 'packout-core' );
				} elseif ( 'partner_answer' === $act ) {
					$result  = Partners::answer( $team, $partner, $on( 'accept' ), $user );
					$message = $on( 'accept' ) ? __( 'The organization is filed under the partner. Both leads have been told.', 'packout-core' ) : __( 'The request was declined. Nothing changed.', 'packout-core' );
				} else {
					$result  = Partners::withdraw( $team, $partner, $user ) ? true : new \WP_Error( 'packout_not_found', __( 'That request is no longer waiting.', 'packout-core' ) );
					$message = __( 'The request was withdrawn.', 'packout-core' );
				}
				break;

			case 'partner_release':
				$from_partner = null !== Partners::get( $owner );
				$team         = $from_partner ? $whole( 'org' ) : $owner;
				$to           = $from_partner ? self::url( $owner, 'organizations' ) : Workspace::url( $team, 'settings' ) . '#pos-settings-partner';
				$result       = Partners::release( $team, $user );
				$message      = __( 'The organization has no partner now. Its missions, campaigns, and impact are as they were.', 'packout-core' );
				break;

			case 'program_save':
				$cover = Workspace::cover_upload();
				if ( is_wp_error( $cover ) ) {
					$result = $cover;
					break;
				}
				$input = array(
					'owner_kind'  => Partners::get( $owner ) ? 'partner' : 'team',
					'owner'       => $owner,
					'title'       => $text( 'title' ),
					'description' => $long( 'description' ),
					'goal'        => $text( 'goal' ),
					'start'       => $text( 'start' ),
					'end'         => $text( 'end' ),
					'sponsors'    => $list( 'sponsors' ),
					'public'      => $on( 'public' ),
					// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked above. Each value is read as two whole numbers by Programs::save().
					'subteams'    => isset( $_POST['program_subteams'] ) && is_array( $_POST['program_subteams'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['program_subteams'] ) ) : array(),
				);
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
				if ( isset( $_POST['has_orgs'] ) ) {
					$input['orgs'] = $list( 'orgs' );
				}
				$result = Programs::save( $item, $input, $user );
				if ( ! is_wp_error( $result ) ) {
					$note = '';
					if ( $cover ) {
						$stored = Programs::set_cover( (int) $result, $cover, $user );
						/* translators: %s: what went wrong. */
						$note = is_wp_error( $stored ) ? ' ' . sprintf( __( 'The cover image was not stored: %s', 'packout-core' ), $stored->get_error_message() ) : '';
					}
					$to      = self::program_url( (int) $result, $owner );
					$message = ( $item > 0 ? __( 'Program saved.', 'packout-core' ) : __( 'Program opened. Campaigns and missions of the organizations taking part can now be filed under it.', 'packout-core' ) ) . $note;
				}
				break;

			case 'program_state':
				$result  = Programs::save( $item, array( 'state' => 'closed' === $text( 'to' ) ? 'closed' : 'open' ), $user );
				$to      = self::program_url( $item, $owner );
				$message = 'closed' === $text( 'to' ) ? __( 'Program closed. It keeps its page and its history, and takes no new campaigns or missions.', 'packout-core' ) : __( 'Program opened again.', 'packout-core' );
				break;

			case 'program_remove':
				$result  = Programs::remove( $item, $user );
				$to      = Workspace::url( $owner, 'programs' );
				$message = __( 'Program removed. Its campaigns and missions stay where they are, in no program.', 'packout-core' );
				break;

			case 'program_cover_remove':
				$result  = Programs::set_cover( $item, null, $user );
				$to      = add_query_arg( 'part', 'change', self::program_url( $item, $owner ) );
				$message = __( 'Cover image removed.', 'packout-core' );
				break;

			case 'program_take_over':
				$result  = Programs::take_over( $item, $user );
				$to      = is_wp_error( $result ) ? '' : self::program_url( $item, Programs::owner( $item )['id'] );
				$message = __( 'The partner now owns the program. The organization still takes part in it.', 'packout-core' );
				break;
		}//end switch
		return array( $result, $message, $to );
	}

	/**
	 * Where a program opens: in the workspace of the partner or the organization the form came from.
	 *
	 * @param int $program Program ID.
	 * @param int $owner   The partner or the organization whose workspace the person is in.
	 */
	private static function program_url( int $program, int $owner ): string {
		return Workspace::url( $owner, 'program', (string) $program );
	}

	/**
	 * The pane to open again when one of this class's forms was refused.
	 *
	 * @param string $act   The form's action.
	 * @param int    $item The record it named.
	 */
	public static function refused_pane( string $act, int $item ): string {
		switch ( $act ) {
			case 'partner_propose':
				return 'partner-new';
			case 'partner_org_create':
				return 'org-new';
			case 'partner_ask':
				return 'org-ask';
			case 'partner_release':
				return 'org-release';
			case 'program_save':
				return $item > 0 ? 'program-' . $item : 'program-new';
			case 'program_state':
			case 'program_remove':
			case 'program_cover_remove':
			case 'program_take_over':
				return 'program-' . $item;
		}
		return '';
	}

	/**
	 * Whether a person may propose a partner: they lead an approved organization.
	 *
	 * @param int $user User ID.
	 */
	private static function may_propose( int $user ): bool {
		// A partner site is one partner, made when the site is set up.
		if ( ! App::is_hub() ) {
			return false;
		}
		foreach ( Teams::of_user( $user ) as $team ) {
			if ( Teams::active( $team ) && Teams::leads_team( $team, $user ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The button that opens the form proposing a partner, or nothing for a person who may not.
	 *
	 * @param int $user User ID.
	 */
	public static function propose_button( int $user ): string {
		return self::may_propose( $user ) ? Workspace_Lists::opener( 'partner-new', __( 'Propose a partner', 'packout-core' ), __( 'Propose a partner', 'packout-core' ), '' ) : '';
	}

	/**
	 * Name, description, region, and website of a partner.
	 *
	 * @param string        $act      The form's action.
	 * @param \WP_Post|null $partner Partner, or null for a new one.
	 */
	private static function profile_fields( string $act, ?\WP_Post $partner ): string {
		$html  = Workspace::input(
			'name',
			__( 'Partner name', 'packout-core' ),
			Workspace::old( $act, 'name', $partner ? $partner->post_title : '' ),
			array(
				'required'  => '',
				'minlength' => '3',
				'maxlength' => '80',
			)
		);
		$html .= Workspace::area(
			'description',
			__( 'What the partner does', 'packout-core' ),
			Workspace::old( $act, 'description', $partner ? $partner->post_content : '' ),
			/* translators: %s: number of characters. */
			sprintf( __( 'Plain text, up to %s characters. Shown on the partner\'s public page when the partner is listed.', 'packout-core' ), number_format_i18n( Partner::MAX_DESCRIPTION ) )
		);
		$html .= Workspace::input( 'region', __( 'Where the partner works', 'packout-core' ), Workspace::old( $act, 'region', $partner ? Partners::field( $partner->ID, 'region' ) : '' ), array( 'maxlength' => '80' ), __( 'A region, forest, or county. Not a street address.', 'packout-core' ) );
		return $html . Workspace::input(
			'website',
			__( 'Website', 'packout-core' ),
			Workspace::old( $act, 'website', $partner ? Partners::field( $partner->ID, 'website' ) : '' ),
			array(
				'type'        => 'url',
				'placeholder' => 'https://',
			)
		);
	}

	/**
	 * A partner's state and listing, in words.
	 *
	 * @param \WP_Post $partner Partner.
	 */
	public static function state_line( \WP_Post $partner ): string {
		$state = Partners::state( $partner->ID );
		if ( 'active' !== $state ) {
			return Partners::states()[ $state ];
		}
		return 'publish' === $partner->post_status ? __( 'Listed publicly', 'packout-core' ) : __( 'Not listed', 'packout-core' );
	}

	/**
	 * Home: the partners a person leads, the requests waiting on them, and the form that proposes one.
	 *
	 * Nothing for a person who leads no partner and may not propose one.
	 *
	 * @param int $user User ID.
	 */
	public static function home( int $user ): string {
		$mine    = array_values( array_filter( array_map( array( Partners::class, 'get' ), Partners::of_user( $user ) ) ) );
		$propose = self::may_propose( $user );
		if ( ! $mine && ! $propose && ! Partners::can_edit( $user ) ) {
			return '';
		}
		$html  = '<h2 class="pos-ws__partners">' . esc_html__( 'Partners', 'packout-core' ) . '</h2>';
		$html .= '<p class="pos-ws__muted">' . esc_html__( 'A partner answers for several organizations: a council, a nonprofit with chapters, a club with its own website. Its leads manage those organizations and run programs across them.', 'packout-core' ) . '</p>';

		$rows = array();
		foreach ( $mine as $partner ) {
			$logo   = Partners::logo( $partner->ID );
			$count  = count( Partners::organization_ids( $partner->ID ) );
			$rows[] = array(
				'id'    => (string) $partner->ID,
				'title' => $partner->post_title,
				'url'   => self::url( $partner->ID ),
				'state' => self::state_line( $partner ),
				'role'  => Partners::lead( $partner->ID ) === $user ? __( 'You lead this partner.', 'packout-core' ) : __( 'You co-lead this partner.', 'packout-core' ),
				'orgs'  => $count,
				'logo'  => $logo > 0 ? (string) wp_get_attachment_image_url( $logo, 'medium' ) : '',
			);
		}
		if ( $rows ) {
			$html .= Workspace_Lists::view(
				'partners',
				array(
					'role'  => array( 'label' => __( 'Your place', 'packout-core' ) ),
					'state' => array(
						'label' => __( 'Status', 'packout-core' ),
						'sort'  => true,
					),
					'orgs'  => array(
						'label' => __( 'Organizations', 'packout-core' ),
						'type'  => 'integer',
						'sort'  => true,
					),
					'logo'  => array(
						'label' => __( 'Logo', 'packout-core' ),
						'type'  => 'media',
					),
				),
				$rows,
				array(
					'title'       => __( 'Partner', 'packout-core' ),
					'layout'      => 'grid',
					'media'       => 'logo',
					'description' => 'role',
				)
			);
		} elseif ( $propose ) {
			$html .= '<p class="pos-view__empty">' . esc_html__( 'You do not lead a partner. If your organizations work together under one body, propose it.', 'packout-core' ) . '</p>';
		}//end if

		// Requests from organizations that wait on a partner this person leads.
		$waiting = '';
		foreach ( $mine as $partner ) {
			foreach ( Partners::requests( $partner->ID ) as $request ) {
				$team = Teams::get( $request['team'] );
				if ( $team && 'team' === $request['from'] ) {
					/* translators: 1: organization name, 2: partner name. */
					$waiting .= '<li><span>' . esc_html( sprintf( __( '%1$s asked to join %2$s', 'packout-core' ), $team->post_title, $partner->post_title ) ) . '</span> <a class="pos-ws__button" href="' . esc_url( self::url( $partner->ID, 'organizations' ) ) . '">' . esc_html__( 'Answer', 'packout-core' ) . '</a></li>';
				}
			}
		}
		if ( '' !== $waiting ) {
			$html .= '<h3>' . esc_html__( 'Requests waiting', 'packout-core' ) . '</h3><ul class="pos-ws__rows">' . $waiting . '</ul>';
		}

		if ( App::is_hub() && Partners::can_edit( $user ) ) {
			$count = count( Partners::pending() );
			$html .= '<p>' . esc_html(
				/* translators: %d: number of partners. */
				$count > 0 ? sprintf( _n( '%d partner is waiting for approval.', '%d partners are waiting for approval.', $count, 'packout-core' ), $count ) : __( 'No partner is waiting for approval.', 'packout-core' )
			) . ' <a href="' . esc_url( admin_url( 'admin.php?page=packout-partners' ) ) . '">' . esc_html__( 'Open Partners in the dashboard', 'packout-core' ) . '</a></p>';
		}

		if ( $propose ) {
			$form  = '<p>' . esc_html__( 'A coordinator reviews every new partner. While you wait you can set up its profile and invite co-leads. Once it is approved, you can add organizations to it without a second review, and open programs.', 'packout-core' ) . '</p>';
			$form .= Workspace::open( 'partner_propose', 0 ) . self::profile_fields( 'partner_propose', null );
			$form .= Workspace::submit( __( 'Send the partner for approval', 'packout-core' ) ) . '</form>';
			Workspace_Lists::pane( 'partner-new', __( 'Propose a partner', 'packout-core' ), $form );
			if ( 'partner_propose' === Workspace_Views::failed() ) {
				Workspace_Lists::reopen( 'partner-new' );
			}
		}
		return $html;
	}

	/**
	 * The Partner workspace block, for a page of its own.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		unset( $attributes );
		$html = '<div ' . Ui::wrapper( 'pos-ws pos-ws--partner' ) . '>';
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $html . Workspace::notice( 'info', __( 'The Partner workspace appears here for the people who lead a partner: its organizations, teams, programs, campaigns, missions, members, resources, messages, sponsors, and impact.', 'packout-core' ) ) . '</div>';
		}
		Ui::enqueue();
		wp_enqueue_style( 'packout-workspace' );
		wp_enqueue_script( 'packout-workspace' );
		$user = get_current_user_id();
		if ( $user <= 0 || ! Accounts::on_site( $user ) ) {
			$html .= '<h2>' . esc_html__( 'Sign in to see your partners', 'packout-core' ) . '</h2>';
			return $html . '<p class="pos-ws__actions"><a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( Accounts::signin_url( Accounts::current_url() ) ) . '">' . esc_html__( 'Sign in', 'packout-core' ) . '</a></p></div>';
		}
		Workspace_Lists::enqueue();
		$html .= Workspace::flash( $user );
		if ( '' !== Workspace::problem() ) {
			$html .= Workspace::notice( 'error', Workspace::problem() );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the address to choose what to show.
		$partner = isset( $_GET['partner'] ) ? (int) $_GET['partner'] : 0;
		$view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$item    = isset( $_GET['item'] ) ? sanitize_key( wp_unslash( $_GET['item'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $partner > 0 ) {
			return $html . self::partner( $partner, $view, $item, $user ) . '</div>';
		}
		$home = self::home( $user );
		if ( '' === $home ) {
			$home = '<h2>' . esc_html__( 'Partners', 'packout-core' ) . '</h2><p>' . esc_html__( 'You do not lead a partner. A partner is proposed by a person who leads an approved organization.', 'packout-core' ) . '</p>';
		} else {
			$home .= '<p class="pos-ws__actions">' . self::propose_button( $user ) . '</p>';
		}
		return $html . $home . Workspace_Lists::panes() . '</div>';
	}

	/**
	 * One partner: its header, its tabs, and the view the address named.
	 *
	 * @param int    $id   Partner ID.
	 * @param string $view View name.
	 * @param string $item Record the view is about.
	 * @param int    $user User ID.
	 */
	public static function partner( int $id, string $view, string $item, int $user ): string {
		$partner = Partners::get( $id );
		if ( ! $partner || ! Partners::can_run( $id, $user ) ) {
			// The same answer whether there is no such partner or it is someone else's.
			return Workspace::notice( 'warning', __( 'You do not lead that partner.', 'packout-core' ) ) . '<p class="pos-ws__back"><a href="' . esc_url( Workspace::url() ) . '">' . esc_html__( 'All my organizations', 'packout-core' ) . '</a></p>';
		}
		$tabs   = array(
			''              => __( 'Overview', 'packout-core' ),
			'organizations' => __( 'Organizations', 'packout-core' ),
			'teams'         => __( 'Teams', 'packout-core' ),
			'programs'      => __( 'Programs', 'packout-core' ),
			'campaigns'     => __( 'Campaigns', 'packout-core' ),
			'missions'      => __( 'Missions', 'packout-core' ),
			'calendar'      => __( 'Calendar', 'packout-core' ),
			'members'       => __( 'Members', 'packout-core' ),
			'resources'     => __( 'Resources', 'packout-core' ),
			'messages'      => __( 'Messages', 'packout-core' ),
			'sponsors'      => __( 'Sponsors', 'packout-core' ),
			'impact'        => __( 'Impact', 'packout-core' ),
			'settings'      => __( 'Settings', 'packout-core' ),
		);
		$parent = array( 'program' => 'programs' );
		$tab    = $parent[ $view ] ?? $view;
		if ( ! isset( $tabs[ $tab ] ) ) {
			$view = '';
			$tab  = '';
		}
		$state = Partners::state( $id );

		$html  = '<p class="pos-ws__back"><a href="' . esc_url( Workspace::url() ) . '">' . esc_html__( 'All my organizations', 'packout-core' ) . '</a></p>';
		$html .= '<header class="pos-ws__head">' . Partners::logo_html( $id, 'pos-ws__logo', 'thumbnail' ) . '<h2>' . esc_html( $partner->post_title ) . '</h2><p class="pos-ws__badges">' . Admin::badge( __( 'Partner', 'packout-core' ), 'none' ) . ' ' . Admin::badge( self::state_line( $partner ), 'active' === $state ? ( 'publish' === $partner->post_status ? 'stable' : 'none' ) : 'medium' ) . '</p></header>';
		if ( 'pending' === $state ) {
			$html .= Workspace::notice( 'info', __( 'This partner is waiting for a coordinator. Until it is approved it is not listed, no organization can be filed under it, and it cannot open a program. Its profile and its co-leads are ready to set up.', 'packout-core' ) );
		} elseif ( 'suspended' === $state ) {
			$html .= Workspace::notice( 'warning', __( 'A coordinator suspended this partner. Its page, its programs, and its figures are not shown to the public. Nothing was deleted, and its organizations are as they were.', 'packout-core' ) );
		}
		$html .= '<nav class="pos-ws__tabs" aria-label="' . esc_attr__( 'Partner', 'packout-core' ) . '"><ul>';
		foreach ( $tabs as $key => $label ) {
			$html .= '<li><a href="' . esc_url( self::url( $id, $key ) ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		}
		$html .= '</ul></nav><div class="pos-ws__view">';

		$named = '';
		switch ( $view ) {
			case 'organizations':
				$html .= self::organizations( $partner, $user );
				break;
			case 'teams':
				$html .= self::teams( $partner );
				break;
			case 'programs':
			case 'program':
				$body  = self::programs( 'partner', $id, $user );
				$named = 'program' === $view ? 'program-' . ( 'new' === $item ? 'new' : (int) $item ) : '';
				if ( '' !== $named && ! Workspace_Lists::has_pane( $named ) ) {
					$html .= Workspace::notice( 'warning', 'new' === $item ? __( 'A partner opens programs once a coordinator has approved it, and while it is active.', 'packout-core' ) : __( 'That program is not this partner\'s.', 'packout-core' ) );
				}
				$html .= $body;
				break;
			case 'campaigns':
				$html .= self::campaigns( $partner, $user );
				break;
			case 'missions':
				$html .= self::missions( $partner, $user );
				break;
			case 'calendar':
				$html .= '<p>' . esc_html__( 'The missions and campaigns of this partner\'s organizations, and its programs, as you are shown them.', 'packout-core' ) . '</p>';
				$html .= Calendar_View::embedded(
					array(
						'view'    => 'month',
						'partner' => $id,
					)
				);
				break;
			case 'members':
				$html .= self::members( $partner );
				break;
			case 'resources':
				$html .= '<p>' . esc_html__( 'The partner\'s own resources. Every member of every organization under it is shown them.', 'packout-core' ) . '</p>' . Workspace_Views::resources( $partner, $user );
				break;
			case 'messages':
				$html .= Messages::view( $partner, $user );
				break;
			case 'sponsors':
				$html .= Workspace_Views::sponsors( $partner );
				break;
			case 'impact':
				$html .= self::impact( $partner );
				break;
			case 'settings':
				$html .= self::settings( $partner, $user );
				break;
			default:
				$html .= self::overview( $partner, $user );
		}//end switch
		$refused = '' !== Workspace::problem() ? Workspace_Views::refused_pane() : '';
		if ( '' !== $refused ) {
			Workspace_Lists::reopen( $refused );
		} elseif ( '' !== $named && Workspace_Lists::has_pane( $named ) ) {
			Workspace_Lists::reopen( $named );
		}
		return $html . '</div>' . Workspace_Lists::panes();
	}

	/**
	 * The open missions of a partner's organizations, newest first.
	 *
	 * @param int  $id   Partner ID.
	 * @param bool $open Only the ones that are planned or in progress.
	 * @return array<int, \WP_Post>
	 */
	private static function all_missions( int $id, bool $open = false ): array {
		$teams = Partners::organization_ids( $id );
		if ( ! $teams ) {
			return array();
		}
		$found = get_posts(
			array(
				'post_type'   => Schema::MISSION,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 500,
				'orderby'     => 'ID',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Missions of a partner's organizations.
				'meta_query'  => array(
					array(
						'key'     => '_pos_team',
						'value'   => $teams,
						'compare' => 'IN',
					),
				),
			)
		);
		return $open ? array_values( array_filter( $found, static fn( $mission ) => Missions::is_open( $mission->ID ) ) ) : $found;
	}

	/**
	 * Overview: the profile, the organizations, open missions across them, and the programs.
	 *
	 * @param \WP_Post $partner Partner.
	 * @param int      $user    User ID.
	 */
	private static function overview( \WP_Post $partner, int $user ): string {
		$id   = $partner->ID;
		$html = '<p>' . esc_html( Partners::lead( $id ) === $user ? __( 'You lead this partner.', 'packout-core' ) : ( Partners::leads_partner( $id, $user ) ? __( 'You co-lead this partner.', 'packout-core' ) : __( 'You are a coordinator. You do not lead this partner.', 'packout-core' ) ) ) . '</p>';
		if ( '' !== trim( $partner->post_content ) ) {
			$html .= wpautop( esc_html( $partner->post_content ) );
		}
		$facts = array(
			__( 'Lead', 'packout-core' )          => esc_html( Workspace_Views::name( Partners::lead( $id ) ) ),
			__( 'Co-leads', 'packout-core' )      => esc_html( implode( ', ', array_map( array( Workspace_Views::class, 'name' ), Partners::coleads( $id ) ) ) ),
			__( 'Organizations', 'packout-core' ) => esc_html( number_format_i18n( count( Partners::organization_ids( $id ) ) ) ),
			__( 'People', 'packout-core' )        => esc_html( number_format_i18n( count( Partners::people( $id ) ) ) ),
			__( 'Works in', 'packout-core' )      => esc_html( Partners::field( $id, 'region' ) ),
		);
		if ( '' !== Partners::field( $id, 'website' ) ) {
			$facts[ __( 'Website', 'packout-core' ) ] = '<a href="' . esc_url( Partners::field( $id, 'website' ) ) . '" rel="noopener">' . esc_html( Partners::field( $id, 'website' ) ) . '</a>';
		}
		if ( Partners::shown( $id, 'page' ) ) {
			$facts[ __( 'Public page', 'packout-core' ) ] = '<a href="' . esc_url( (string) get_permalink( $partner ) ) . '">' . esc_html( (string) get_permalink( $partner ) ) . '</a>';
		}
		$html .= '<dl class="pos-ws__facts">';
		foreach ( array_filter( $facts, static fn( $value ) => '' !== $value ) as $label => $value ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd>';
		}
		$html .= '</dl>';

		$html .= '<h3>' . esc_html__( 'Organizations', 'packout-core' ) . '</h3>';
		$orgs  = Partners::organizations( $id );
		if ( ! $orgs ) {
			$html .= '<p class="pos-view__empty">' . esc_html( Partners::allows( $id, 'organization' ) ? __( 'No organization is filed under this partner yet. Add one, or ask an existing organization to join, under Organizations.', 'packout-core' ) : __( 'Organizations are added once a coordinator has approved the partner.', 'packout-core' ) ) . '</p>';
		} else {
			$html .= '<ul class="pos-ws__rows">';
			foreach ( $orgs as $team ) {
				/* translators: %s: number of people. */
				$html .= '<li><span><a href="' . esc_url( Workspace::url( $team->ID ) ) . '">' . esc_html( $team->post_title ) . '</a></span><span class="pos-ws__muted">' . esc_html( sprintf( _n( '%s person', '%s people', count( Teams::people( $team->ID ) ), 'packout-core' ), number_format_i18n( count( Teams::people( $team->ID ) ) ) ) ) . '</span></li>';
			}
			$html .= '</ul>';
		}

		$open  = self::all_missions( $id, true );
		$html .= '<h3>' . esc_html__( 'Open missions', 'packout-core' ) . '</h3>';
		$html .= $open ? self::mission_list( 'partner-open-missions', $open ) : '<p class="pos-view__empty">' . esc_html__( 'No mission of its organizations is planned or in progress.', 'packout-core' ) . '</p>';

		$programs = Programs::of_partner( $id );
		if ( $programs ) {
			$html .= '<h3>' . esc_html__( 'Programs', 'packout-core' ) . '</h3><ul class="pos-ws__rows">';
			foreach ( $programs as $program ) {
				$html .= '<li><span><a href="' . esc_url( self::url( $id, 'program', (string) $program->ID ) ) . '">' . esc_html( $program->post_title ) . '</a></span><span class="pos-ws__muted">' . esc_html( Programs::progress_line( Programs::progress( $program->ID ) ) ) . '</span></li>';
			}
			$html .= '</ul>';
		}
		return $html;
	}

	/**
	 * Missions as a list. Each opens in its organization's workspace.
	 *
	 * @param string               $key      Name of the list.
	 * @param array<int, \WP_Post> $missions Missions.
	 */
	private static function mission_list( string $key, array $missions ): string {
		$statuses = Missions::statuses();
		$rows     = array();
		foreach ( $missions as $mission ) {
			$id      = $mission->ID;
			$team    = Missions::team( $id );
			$program = Programs::of_mission( $id );
			$rows[]  = array(
				'id'       => (string) $id,
				'title'    => $mission->post_title,
				'url'      => Workspace::url( $team, 'mission', (string) $id ),
				'org'      => (string) get_the_title( $team ),
				'status'   => $statuses[ Missions::status( $id ) ] ?? '',
				'window'   => Missions::window_label( $id ),
				'campaign' => Missions::campaign( $id ) > 0 ? (string) get_the_title( Missions::campaign( $id ) ) : '',
				'program'  => $program > 0 ? (string) get_the_title( $program ) : '',
			);
		}
		return Workspace_Lists::view(
			$key,
			array(
				'org'      => array(
					'label' => __( 'Organization', 'packout-core' ),
					'sort'  => true,
				),
				'status'   => array(
					'label' => __( 'Status', 'packout-core' ),
					'sort'  => true,
				),
				'window'   => array( 'label' => __( 'Dates', 'packout-core' ) ),
				'campaign' => array(
					'label' => __( 'Campaign', 'packout-core' ),
					'sort'  => true,
				),
				'program'  => array(
					'label' => __( 'Program', 'packout-core' ),
					'sort'  => true,
				),
			),
			$rows,
			array(
				'title'       => __( 'Mission', 'packout-core' ),
				'description' => 'org',
				'empty'       => __( 'No missions yet.', 'packout-core' ),
			)
		);
	}

	/**
	 * A pane that asks which organization a new campaign or mission is for.
	 *
	 * @param \WP_Post $partner Partner.
	 * @param int      $user    User ID.
	 * @param string   $what    `mission` or `campaign`.
	 */
	private static function plan_pane( \WP_Post $partner, int $user, string $what ): string {
		$links = '';
		foreach ( Partners::organizations( $partner->ID ) as $team ) {
			if ( Missions::can_plan( $team->ID, $user ) ) {
				$links .= '<li><span><a href="' . esc_url( Workspace::url( $team->ID, $what, 'new' ) ) . '">' . esc_html( $team->post_title ) . '</a></span></li>';
			}
		}
		if ( '' === $links ) {
			return '';
		}
		$title = 'mission' === $what ? __( 'Plan a mission', 'packout-core' ) : __( 'Plan a campaign', 'packout-core' );
		Workspace_Lists::pane( 'plan-' . $what, $title, '<p>' . esc_html__( 'Which organization is it for? It is planned in that organization\'s workspace, and stays that organization\'s.', 'packout-core' ) . '</p><ul class="pos-ws__rows">' . $links . '</ul>' );
		return '<p class="pos-ws__actions">' . Workspace_Lists::opener( 'plan-' . $what, $title, $title ) . '</p>';
	}

	/**
	 * Missions across the partner's organizations.
	 *
	 * @param \WP_Post $partner Partner.
	 * @param int      $user    User ID.
	 */
	private static function missions( \WP_Post $partner, int $user ): string {
		return self::plan_pane( $partner, $user, 'mission' ) . '<p class="pos-ws__muted">' . esc_html__( 'Search by an organization or a program to narrow the list. A mission opens in its organization\'s workspace.', 'packout-core' ) . '</p>' . self::mission_list( 'partner-missions', self::all_missions( $partner->ID ) );
	}

	/**
	 * Campaigns across the partner's organizations.
	 *
	 * @param \WP_Post $partner Partner.
	 * @param int      $user    User ID.
	 */
	private static function campaigns( \WP_Post $partner, int $user ): string {
		$rows = array();
		foreach ( Partners::organizations( $partner->ID ) as $team ) {
			foreach ( Campaigns::of_team( $team->ID ) as $campaign ) {
				$program = Programs::of_campaign( $campaign->ID );
				$rows[]  = array(
					'id'       => (string) $campaign->ID,
					'title'    => $campaign->post_title,
					'url'      => Workspace::url( $team->ID, 'campaign', (string) $campaign->ID ),
					'org'      => $team->post_title,
					'program'  => $program > 0 ? (string) get_the_title( $program ) : '',
					'dates'    => Schedule::label( $campaign->ID ),
					'progress' => Campaigns::progress_line( Campaigns::progress( $campaign->ID ) ),
					'listing'  => 'publish' === $campaign->post_status ? __( 'Public', 'packout-core' ) : __( 'Organization only', 'packout-core' ),
				);
			}
		}
		$html = self::plan_pane( $partner, $user, 'campaign' ) . '<p class="pos-ws__muted">' . esc_html__( 'Search by an organization or a program to narrow the list. A campaign opens in its organization\'s workspace.', 'packout-core' ) . '</p>';
		return $html . Workspace_Lists::view(
			'partner-campaigns',
			array(
				'org'      => array(
					'label' => __( 'Organization', 'packout-core' ),
					'sort'  => true,
				),
				'program'  => array(
					'label' => __( 'Program', 'packout-core' ),
					'sort'  => true,
				),
				'dates'    => array( 'label' => __( 'Dates', 'packout-core' ) ),
				'progress' => array( 'label' => __( 'Progress', 'packout-core' ) ),
				'listing'  => array(
					'label' => __( 'Shown to', 'packout-core' ),
					'sort'  => true,
				),
			),
			$rows,
			array(
				'title'       => __( 'Campaign', 'packout-core' ),
				'description' => 'org',
				'empty'       => __( 'No campaign of its organizations yet.', 'packout-core' ),
			)
		);
	}

	/**
	 * Organizations: each with its lead, members, and open missions. Add one, and answer requests.
	 *
	 * @param \WP_Post $partner Partner.
	 * @param int      $user    User ID.
	 */
	private static function organizations( \WP_Post $partner, int $user ): string {
		$id     = $partner->ID;
		$open   = Partners::allows( $id, 'organization' );
		$owns   = Partners::owns( $id, $user );
		$html   = '';
		$orgs   = Partners::organizations( $id );
		$action = '';

		if ( $open ) {
			$action .= Workspace_Lists::opener( 'org-new', __( 'Add an organization', 'packout-core' ), __( 'Add an organization', 'packout-core' ) );
			$form    = '<p>' . esc_html(
				App::is_hub()
					? __( 'The organization is active at once, because a coordinator approved this partner. The partner\'s lead leads it until they name another person, from the organization\'s Members. Coordinators are told.', 'packout-core' )
					: __( 'The organization is active at once. The partner\'s lead leads it until they name another person, from the organization\'s Members. Coordinators are told.', 'packout-core' )
			) . '</p>';
			$form   .= Workspace::open( 'partner_org_create', $id );
			$form   .= Workspace::input(
				'name',
				__( 'Organization name', 'packout-core' ),
				Workspace::old( 'partner_org_create', 'name' ),
				array(
					'required'  => '',
					'minlength' => '3',
					'maxlength' => '80',
				)
			);
			$form   .= Workspace::area( 'description', __( 'What the organization does', 'packout-core' ), Workspace::old( 'partner_org_create', 'description' ) );
			$form   .= Workspace::input( 'region', __( 'Where the organization works', 'packout-core' ), Workspace::old( 'partner_org_create', 'region' ), array( 'maxlength' => '80' ) );
			$form   .= Workspace::input(
				'website',
				__( 'Website', 'packout-core' ),
				Workspace::old( 'partner_org_create', 'website' ),
				array(
					'type'        => 'url',
					'placeholder' => 'https://',
				)
			);
			$form   .= Workspace::submit( __( 'Add the organization', 'packout-core' ) ) . '</form>';
			Workspace_Lists::pane( 'org-new', __( 'Add an organization', 'packout-core' ), $form );

			// An organization that exists joins only when both sides agree. The partner's lead asks; the organization answers.
			$free = array();
			// On a partner site every organization is the partner's already.
			foreach ( App::is_hub() ? Teams::listed() : array() as $team ) {
				if ( 0 === Partners::of_team( $team['id'] ) && '' === Partners::asked_by( $team['id'], $id ) ) {
					$free[ $team['id'] ] = $team['name'];
				}
			}
			if ( $owns && $free ) {
				$action .= ' ' . Workspace_Lists::opener( 'org-ask', __( 'Ask an organization to join', 'packout-core' ), __( 'Ask an organization to join', 'packout-core' ), '' );
				$ask     = '<p>' . esc_html__( 'The organization\'s lead is asked. Nothing changes until they accept. Its missions, campaigns, impact, and adopted Places stay its own.', 'packout-core' ) . '</p>';
				$ask    .= Workspace::open( 'partner_ask', $id ) . Workspace::choose( 'org', __( 'Organization', 'packout-core' ), $free, Workspace::old( 'partner_ask', 'org' ), __( 'Listed organizations that have no partner.', 'packout-core' ) );
				$ask    .= Workspace::submit( __( 'Send the request', 'packout-core' ) ) . '</form>';
				Workspace_Lists::pane( 'org-ask', __( 'Ask an organization to join', 'packout-core' ), $ask );
			}
		}//end if
		if ( $owns && $orgs && App::is_hub() ) {
			$action  .= ' ' . Workspace_Lists::opener( 'org-release', __( 'Take an organization out', 'packout-core' ), __( 'Take an organization out', 'packout-core' ), '' );
			$release  = '<p>' . esc_html__( 'The organization has no partner again. Its history stays its own: its missions, campaigns, and impact are as they were, and they leave this partner\'s programs and totals.', 'packout-core' ) . '</p>';
			$release .= Workspace::open( 'partner_release', $id ) . Workspace::choose( 'org', __( 'Organization', 'packout-core' ), wp_list_pluck( $orgs, 'post_title', 'ID' ) );
			$release .= '<p class="pos-ws__actions"><button class="pos-ws__button pos-ws__button--danger"' . Workspace::confirm( __( 'Take this organization out from under the partner?', 'packout-core' ), __( 'It keeps everything it has. The partner\'s leads stop managing it.', 'packout-core' ), __( 'Take it out', 'packout-core' ) ) . '>' . esc_html__( 'Take it out', 'packout-core' ) . '</button></p></form>';
			Workspace_Lists::pane( 'org-release', __( 'Take an organization out', 'packout-core' ), $release );
		}
		if ( '' !== $action ) {
			$html .= '<p class="pos-ws__actions">' . $action . '</p>';
		} elseif ( ! $open ) {
			$html .= '<p>' . esc_html__( 'Organizations are added once a coordinator has approved the partner, and while it is active.', 'packout-core' ) . '</p>';
		}

		$requests = '';
		foreach ( Partners::requests( $id ) as $request ) {
			$team = Teams::get( $request['team'] );
			if ( ! $team ) {
				continue;
			}
			$hidden = array( 'org' => (string) $team->ID );
			if ( 'team' === $request['from'] ) {
				$requests .= '<li><span>' . esc_html( $team->post_title ) . ' <span class="pos-ws__muted">' . esc_html__( 'Asked to join', 'packout-core' ) . '</span></span>';
				$requests .= Workspace::act( 'partner_answer', $id, __( 'Accept', 'packout-core' ), $hidden + array( 'accept' => '1' ), 'pos-ws__button--primary', '', '' ) . Workspace::act( 'partner_answer', $id, __( 'Decline', 'packout-core' ), $hidden ) . '</li>';
			} else {
				$requests .= '<li><span>' . esc_html( $team->post_title ) . ' <span class="pos-ws__muted">' . esc_html__( 'Waiting for the organization\'s lead', 'packout-core' ) . '</span></span>';
				$requests .= ( $owns ? Workspace::act( 'partner_withdraw', $id, __( 'Withdraw', 'packout-core' ), $hidden ) : '' ) . '</li>';
			}
		}
		if ( '' !== $requests ) {
			$html .= '<h3>' . esc_html__( 'Requests to join', 'packout-core' ) . '</h3><ul class="pos-ws__rows">' . $requests . '</ul>';
		}

		$rows = array();
		foreach ( $orgs as $team ) {
			$logo   = Teams::logo( $team->ID );
			$rows[] = array(
				'id'      => (string) $team->ID,
				'title'   => $team->post_title,
				'url'     => Workspace::url( $team->ID ),
				'lead'    => Workspace_Views::name( Teams::lead( $team->ID ) ),
				'people'  => count( Teams::people( $team->ID ) ),
				'open'    => count( array_filter( self::all_missions( $id, true ), static fn( $mission ) => Missions::team( $mission->ID ) === $team->ID ) ),
				'listing' => 'publish' === $team->post_status ? __( 'Listed publicly', 'packout-core' ) : __( 'Not listed', 'packout-core' ),
				'logo'    => $logo > 0 ? (string) wp_get_attachment_image_url( $logo, 'medium' ) : '',
			);
		}
		return $html . Workspace_Lists::view(
			'partner-organizations',
			array(
				'lead'    => array(
					'label' => __( 'Lead', 'packout-core' ),
					'sort'  => true,
				),
				'people'  => array(
					'label' => __( 'People', 'packout-core' ),
					'type'  => 'integer',
					'sort'  => true,
				),
				'open'    => array(
					'label' => __( 'Open missions', 'packout-core' ),
					'type'  => 'integer',
					'sort'  => true,
				),
				'listing' => array(
					'label' => __( 'Status', 'packout-core' ),
					'sort'  => true,
				),
				'logo'    => array(
					'label' => __( 'Logo', 'packout-core' ),
					'type'  => 'media',
				),
			),
			$rows,
			array(
				'title'       => __( 'Organization', 'packout-core' ),
				'media'       => 'logo',
				'description' => 'lead',
				'empty'       => __( 'No organization is filed under this partner yet.', 'packout-core' ),
			)
		);
	}

	/**
	 * Every team of every organization, by organization.
	 *
	 * @param \WP_Post $partner Partner.
	 */
	private static function teams( \WP_Post $partner ): string {
		$rows = array();
		foreach ( Partners::organizations( $partner->ID ) as $team ) {
			foreach ( Subteams::all( $team->ID ) as $subteam ) {
				$rows[] = array(
					'id'     => $team->ID . '-' . $subteam['id'],
					'title'  => (string) $subteam['name'],
					'url'    => Workspace::url( $team->ID, 'subteams' ),
					'org'    => $team->post_title,
					'lead'   => (int) $subteam['lead'] > 0 ? Workspace_Views::name( (int) $subteam['lead'] ) : '',
					'people' => count( array_unique( array_filter( array_merge( array( (int) $subteam['lead'] ), array_map( 'intval', (array) $subteam['members'] ) ) ) ) ),
				);
			}
		}
		return Workspace_Lists::view(
			'partner-teams',
			array(
				'org'    => array(
					'label' => __( 'Organization', 'packout-core' ),
					'sort'  => true,
				),
				'lead'   => array(
					'label' => __( 'Lead', 'packout-core' ),
					'sort'  => true,
				),
				'people' => array(
					'label' => __( 'People', 'packout-core' ),
					'type'  => 'integer',
					'sort'  => true,
				),
			),
			$rows,
			array(
				'title'       => __( 'Team', 'packout-core' ),
				'description' => 'org',
				'empty'       => __( 'Its organizations have no teams yet. A team is added in its organization\'s workspace, under Teams.', 'packout-core' ),
			)
		);
	}

	/**
	 * Everyone in the partner's organizations, by organization, with the email addresses a lead may see.
	 *
	 * @param \WP_Post $partner Partner.
	 */
	private static function members( \WP_Post $partner ): string {
		$rows = array();
		foreach ( Partners::organizations( $partner->ID ) as $team ) {
			foreach ( Teams::people( $team->ID ) as $person_id ) {
				$person = get_userdata( $person_id );
				if ( Teams::lead( $team->ID ) === $person_id ) {
					$role = __( 'Lead', 'packout-core' );
				} elseif ( Teams::leads_team( $team->ID, $person_id ) ) {
					$role = __( 'Co-lead', 'packout-core' );
				} else {
					$role = __( 'Member', 'packout-core' );
				}
				$rows[] = array(
					'id'    => $team->ID . '-' . $person_id,
					'title' => Workspace_Views::name( $person_id ),
					'url'   => Workspace::url( $team->ID, 'members' ),
					'org'   => $team->post_title,
					'role'  => $role,
					// A partner's lead may see what the organization's co-lead may.
					'email' => $person ? $person->user_email : '',
				);
			}
		}//end foreach
		return '<p class="pos-ws__muted">' . esc_html__( 'A person in two organizations is listed in each. Adding, inviting, and removing people is done in the organization\'s workspace, under Members.', 'packout-core' ) . '</p>' . Workspace_Lists::view(
			'partner-members',
			array(
				'org'   => array(
					'label' => __( 'Organization', 'packout-core' ),
					'sort'  => true,
				),
				'role'  => array(
					'label' => __( 'Place', 'packout-core' ),
					'sort'  => true,
				),
				'email' => array( 'label' => __( 'Email', 'packout-core' ) ),
			),
			$rows,
			array(
				'title'       => __( 'Person', 'packout-core' ),
				'description' => 'org',
				'empty'       => __( 'Its organizations have no people yet.', 'packout-core' ),
			)
		);
	}

	/**
	 * Totals as figure cards.
	 *
	 * @param array<string, array<string, int|float>> $totals From Impact::totals().
	 */
	public static function figures( array $totals ): string {
		$figures = array(
			__( 'Spots with a record', 'packout-core' )   => number_format_i18n( (int) $totals['outputs']['spots'] ),
			__( 'Missions completed', 'packout-core' )    => number_format_i18n( (int) $totals['effort']['missions'] ),
			__( 'Hours, verified', 'packout-core' )       => Impact::number( (float) $totals['effort']['hours_verified'] ),
			__( 'Hours, unverified', 'packout-core' )     => Impact::number( (float) $totals['effort']['hours_unverified'] ),
			__( 'Bags, measured', 'packout-core' )        => number_format_i18n( (int) $totals['outputs']['bags_measured'] ),
			__( 'Bags, self-reported', 'packout-core' )   => number_format_i18n( (int) $totals['outputs']['bags_self_reported'] ),
			__( 'Pounds, measured', 'packout-core' )      => Impact::number( (float) $totals['outputs']['pounds_measured'] ),
			__( 'Pounds, self-reported', 'packout-core' ) => Impact::number( (float) $totals['outputs']['pounds_self_reported'] ),
			__( 'Still clear on a later visit', 'packout-core' ) => number_format_i18n( (int) $totals['outcomes']['still_clear'] ),
			__( 'Came back', 'packout-core' )             => number_format_i18n( (int) $totals['outcomes']['returned'] ),
		);
		$html    = '<dl class="pos-ws__figures">';
		foreach ( $figures as $label => $value ) {
			$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		return $html . '</dl>';
	}

	/**
	 * Totals by organization, as rows for a list. In alphabetical order: no list ranks the parts.
	 *
	 * @param array<int, array{title: string, url: string, totals: array<string, array<string, int|float>>}> $parts One row for each organization.
	 * @param string                                                                                         $key   Name of the list.
	 */
	public static function split( array $parts, string $key ): string {
		$rows = array();
		foreach ( $parts as $index => $part ) {
			$totals = $part['totals'];
			$rows[] = array(
				'id'      => (string) $index,
				'title'   => $part['title'],
				'url'     => $part['url'],
				'records' => (int) $totals['records']['cleanups'],
				/* translators: 1: verified hours, 2: unverified hours. */
				'hours'   => sprintf( __( '%1$s verified, %2$s unverified', 'packout-core' ), Impact::number( (float) $totals['effort']['hours_verified'] ), Impact::number( (float) $totals['effort']['hours_unverified'] ) ),
				/* translators: 1: measured bags, 2: self-reported bags. */
				'bags'    => sprintf( __( '%1$s measured, %2$s self-reported', 'packout-core' ), number_format_i18n( (int) $totals['outputs']['bags_measured'] ), number_format_i18n( (int) $totals['outputs']['bags_self_reported'] ) ),
				/* translators: 1: measured pounds, 2: self-reported pounds. */
				'pounds'  => sprintf( __( '%1$s measured, %2$s self-reported', 'packout-core' ), Impact::number( (float) $totals['outputs']['pounds_measured'] ), Impact::number( (float) $totals['outputs']['pounds_self_reported'] ) ),
			);
		}
		return Workspace_Lists::view(
			$key,
			array(
				'records' => array(
					'label' => __( 'Cleanup records', 'packout-core' ),
					'type'  => 'integer',
				),
				'hours'   => array( 'label' => __( 'Hours', 'packout-core' ) ),
				'bags'    => array( 'label' => __( 'Bags', 'packout-core' ) ),
				'pounds'  => array( 'label' => __( 'Pounds', 'packout-core' ) ),
			),
			$rows,
			array(
				'title' => __( 'Organization', 'packout-core' ),
				'empty' => __( 'No organization to show yet.', 'packout-core' ),
			)
		);
	}

	/**
	 * Impact: what the organizations' records add up to, and each organization's part.
	 *
	 * @param \WP_Post $partner Partner.
	 */
	private static function impact( \WP_Post $partner ): string {
		$id    = $partner->ID;
		$html  = '<p>' . esc_html__( 'Every figure is the sum of the impact records of the partner\'s organizations, read now. An organization that leaves takes its figures with it. Measured and self-reported amounts stay apart, and so do verified and unverified hours.', 'packout-core' ) . '</p>';
		$html .= self::figures( Partners::totals( $id ) );
		$parts = array();
		foreach ( Partners::organizations( $id ) as $team ) {
			$parts[] = array(
				'title'  => $team->post_title,
				'url'    => Workspace::url( $team->ID, 'impact' ),
				'totals' => Impact::totals( array( 'team' => $team->ID ) ),
			);
		}
		$html .= '<h3>' . esc_html__( 'By organization', 'packout-core' ) . '</h3>' . self::split( $parts, 'partner-impact' );
		$html .= '<p class="pos-ws__muted">' . esc_html__( 'Open an organization to read and verify its records. A coordinator decides which records the public totals include.', 'packout-core' ) . '</p>';
		if ( Export::can() || Partners::can_run( $id ) ) {
			$html .= '<p class="pos-ws__actions">' . Overview_Report::link( 'partner', $id, __( 'Impact report', 'packout-core' ) ) . '</p>';
		}
		return $html;
	}

	/**
	 * Settings: profile, address, logo, cover, listing, the leads, and handing the partner over.
	 *
	 * @param \WP_Post $partner Partner.
	 * @param int      $user    User ID.
	 */
	private static function settings( \WP_Post $partner, int $user ): string {
		$id   = $partner->ID;
		$owns = Partners::owns( $id, $user );
		$do   = 'partner_profile';

		$profile = Workspace::open( $do, $id ) . self::profile_fields( $do, $partner );
		if ( Partners::allows( $id, 'page' ) || 'suspended' === Partners::state( $id ) ) {
			$listed   = 'publish' === $partner->post_status || '1' === Partners::field( $id, 'listed_before' );
			$profile .= Workspace::tick( 'listed', __( 'List this partner publicly: its name, description, logo, cover image, region, website, number of organizations, its listed organizations, its public programs, its impact, and its sponsors. No member is named.', 'packout-core' ), Workspace::old_on( $do, 'listed', $listed ) );
			$profile .= Workspace::tick( 'show_leads', __( 'Name the partner\'s lead and co-leads on its public page', 'packout-core' ), Workspace::old_on( $do, 'show_leads', Partners::shows_leads( $id ) ) );
		} else {
			$profile .= '<p class="pos-ws__help">' . esc_html__( 'You can list the partner once a coordinator has approved it.', 'packout-core' ) . '</p>';
		}
		$profile .= Workspace::submit( __( 'Save the partner', 'packout-core' ) ) . '</form>';

		$address = '<p>' . esc_html(
			Partners::shown( $id, 'page' )
				/* translators: %s: web address. */
				? sprintf( __( 'The partner\'s public page is %s', 'packout-core' ), (string) get_permalink( $partner ) )
				: __( 'The partner is not listed, so it has no public page yet. The address is used once it is.', 'packout-core' )
		) . '</p>';
		$address .= Workspace::open( 'partner_address', $id );
		$address .= Workspace::input(
			'address',
			/* translators: %s: the first part of the address. */
			sprintf( __( 'Address, after %s', 'packout-core' ), Partners::address_url( '' ) ),
			Workspace::old( 'partner_address', 'address', Partners::address( $id ) ),
			array(
				'required'  => '',
				'minlength' => '3',
				'maxlength' => '60',
				'pattern'   => '[a-z0-9][a-z0-9-]{1,58}[a-z0-9]',
			),
			__( 'Lower-case letters, digits, and hyphens, 3 to 60 characters. Links to the old address keep working.', 'packout-core' )
		);
		$address .= Workspace::submit( __( 'Change the address', 'packout-core' ) ) . '</form>';

		$images = Partners::allows( $id, 'images' );
		if ( ! $images ) {
			$logo  = '<p class="pos-ws__help">' . esc_html__( 'A logo can be added once a coordinator has approved the partner.', 'packout-core' ) . '</p>';
			$cover = '<p class="pos-ws__help">' . esc_html__( 'A cover image can be added once a coordinator has approved the partner.', 'packout-core' ) . '</p>';
		} else {
			$has  = Partners::logo( $id ) > 0;
			$logo = '<p>' . esc_html__( 'Shown on the partner\'s public page, in the list of partners, here, and at the top of the messages the partner sends.', 'packout-core' ) . '</p>';
			if ( $has ) {
				$logo .= '<p class="pos-ws__logo-now">' . Partners::logo_html( $id, 'pos-ws__logo pos-ws__logo--large' ) . '</p>';
			}
			$logo .= Workspace::open( 'partner_logo', $id, array(), true ) . Workspace::input(
				'logo',
				$has ? __( 'New logo', 'packout-core' ) : __( 'Logo', 'packout-core' ),
				'',
				array(
					'type'     => 'file',
					'required' => '',
					'accept'   => Photos::accept(),
				),
				Photos::hint() . ' ' . __( 'A square or wide image on a plain background reads best.', 'packout-core' )
			) . Workspace::submit( $has ? __( 'Replace the logo', 'packout-core' ) : __( 'Upload the logo', 'packout-core' ) ) . '</form>';
			if ( $has ) {
				$logo .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'partner_logo_remove', $id, __( 'Remove the logo', 'packout-core' ), array(), 'pos-ws__button--danger', __( 'Remove the partner\'s logo?', 'packout-core' ), '', __( 'The image is deleted. The partner\'s public page and its messages show its name alone.', 'packout-core' ) ) . '</div>';
			}

			$image = Page_Header::cover( $id );
			$cover = '';
			if ( $image > 0 ) {
				$cover .= '<p class="pos-ws__cover">' . wp_get_attachment_image( $image, 'large', false, array( 'alt' => '' ) ) . '</p>';
			}
			$cover .= Workspace::open( 'partner_cover', $id, array(), true ) . Workspace_Views::cover_field( $image > 0, __( 'The picture behind the partner\'s name on its public page.', 'packout-core' ) ) . Workspace::submit( $image > 0 ? __( 'Replace the cover image', 'packout-core' ) : __( 'Upload the cover image', 'packout-core' ) ) . '</form>';
			if ( $image > 0 ) {
				$cover .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'partner_cover_remove', $id, __( 'Remove the cover image', 'packout-core' ), array(), 'pos-ws__button--danger', __( 'Remove the partner\'s cover image?', 'packout-core' ), '', __( 'The image is deleted. The partner\'s public page keeps its header, on a dark ground.', 'packout-core' ) ) . '</div>';
			}
		}//end if

		// The leads. The partner's lead names co-leads; a co-lead may step down.
		$coleads = Partners::coleads( $id );
		$leads   = '<p>' . esc_html(
			/* translators: 1: person's name, 2: number of co-leads a partner can have. */
			sprintf( __( '%1$s leads this partner. A partner has up to %2$d co-leads, who manage its profile, its programs, and its organizations with its lead.', 'packout-core' ), Workspace_Views::name( Partners::lead( $id ) ), Partner::MAX_COLEADS )
		) . '</p>';
		if ( $coleads ) {
			$leads .= '<ul class="pos-ws__rows">';
			foreach ( $coleads as $who ) {
				$leads .= '<li><span>' . esc_html( Workspace_Views::name( $who ) ) . ' <span class="pos-ws__muted">' . esc_html__( 'Co-lead', 'packout-core' ) . '</span></span>';
				if ( $owns || $who === $user ) {
					$leads .= Workspace::act( 'partner_colead', $id, $who === $user ? __( 'Step down', 'packout-core' ) : __( 'Return to member', 'packout-core' ), array( 'who' => (string) $who ), '', $who === $user ? __( 'Stop co-leading this partner?', 'packout-core' ) : '' );
				}
				$leads .= '</li>';
			}
			$leads .= '</ul>';
		}
		if ( $owns && Partners::allows( $id, 'profile' ) ) {
			$people = array();
			foreach ( Partners::people( $id ) as $person_id ) {
				if ( ! Partners::leads_partner( $id, $person_id ) ) {
					$people[ $person_id ] = Workspace_Views::name( $person_id );
				}
			}
			asort( $people );
			if ( $people ) {
				$leads .= '<h4>' . esc_html__( 'Name a co-lead from its organizations', 'packout-core' ) . '</h4>';
				$leads .= Workspace::open( 'partner_colead', $id, array( 'on' => '1' ) ) . Workspace::choose( 'who', __( 'Person', 'packout-core' ), $people ) . Workspace::submit( __( 'Name the co-lead', 'packout-core' ) ) . '</form>';
			}
			$leads .= '<h4>' . esc_html__( 'Invite a co-lead by email', 'packout-core' ) . '</h4>';
			$leads .= Workspace::open( 'partner_invite', $id ) . Workspace::input(
				'email',
				__( 'Email address', 'packout-core' ),
				Workspace::old( 'partner_invite', 'email' ),
				array(
					'type'     => 'email',
					'required' => '',
				),
				__( 'They accept from the link, with the account they have or a new one. Nobody is made a co-lead without agreeing to it.', 'packout-core' )
			) . Workspace::submit( __( 'Send the invitation', 'packout-core' ) ) . '</form>';
		} elseif ( ! $owns ) {
			$leads .= '<p class="pos-ws__muted">' . esc_html__( 'The partner\'s lead names its co-leads.', 'packout-core' ) . '</p>';
		}//end if

		if ( ! $owns ) {
			$hand = '<p>' . esc_html__( 'The partner\'s lead hands it to a co-lead. A co-lead cannot.', 'packout-core' ) . '</p>';
		} elseif ( ! $coleads ) {
			$hand = '<p>' . esc_html__( 'A partner is handed to one of its co-leads. Name one first.', 'packout-core' ) . '</p>';
		} else {
			$names = array();
			foreach ( $coleads as $who ) {
				$names[ $who ] = Workspace_Views::name( $who );
			}
			$hand  = '<p>' . esc_html__( 'The co-lead you choose becomes the lead. You stay as a co-lead.', 'packout-core' ) . '</p>';
			$hand .= Workspace::open( 'partner_hand_over', $id ) . Workspace::choose( 'to', __( 'New lead', 'packout-core' ), $names );
			$hand .= '<p class="pos-ws__actions"><button class="pos-ws__button pos-ws__button--danger"' . Workspace::confirm( __( 'Hand this partner to the person you chose?', 'packout-core' ), __( 'They become the lead and are the only one who can hand it back. You stay as a co-lead.', 'packout-core' ), __( 'Hand over the partner', 'packout-core' ) ) . '>' . esc_html__( 'Hand over the partner', 'packout-core' ) . '</button></p></form>';
		}

		$first = array(
			'partner_address'      => 'address',
			'partner_logo'         => 'logo',
			'partner_logo_remove'  => 'logo',
			'partner_cover'        => 'cover',
			'partner_cover_remove' => 'cover',
			'partner_colead'       => 'leads',
			'partner_invite'       => 'leads',
			'partner_hand_over'    => 'hand',
		);
		$part  = '' !== Workspace::problem() ? ( $first[ Workspace_Views::failed() ] ?? '' ) : '';
		return Workspace::sections(
			__( 'Settings', 'packout-core' ),
			array(
				array(
					'id'    => 'pos-partner-profile',
					'label' => __( 'Profile', 'packout-core' ),
					'html'  => $profile,
				),
				array(
					'id'    => 'pos-partner-address',
					'label' => __( 'Partner address', 'packout-core' ),
					'html'  => $address,
				),
				array(
					'id'    => 'pos-partner-logo',
					'label' => __( 'Logo', 'packout-core' ),
					'html'  => $logo,
				),
				array(
					'id'    => 'pos-partner-cover',
					'label' => __( 'Cover image', 'packout-core' ),
					'html'  => $cover,
				),
				array(
					'id'    => 'pos-partner-leads',
					'label' => __( 'Leads', 'packout-core' ),
					'html'  => $leads,
				),
				array(
					'id'    => 'pos-partner-hand',
					'label' => __( 'Hand the partner over', 'packout-core' ),
					'html'  => $hand,
				),
			),
			'side',
			'' !== $part ? 'pos-partner-' . $part : ''
		);
	}

	/**
	 * The Partner section of an organization's Settings: its partner, the requests, and the way to ask or leave.
	 *
	 * @param \WP_Post $team Organization.
	 * @param int      $user User ID.
	 */
	public static function organization_partner( \WP_Post $team, int $user ): string {
		$id      = $team->ID;
		$owns    = Teams::owns( $id, $user );
		$current = Partners::of_team( $id );
		if ( ! App::is_hub() ) {
			$partner = Partners::get( $current );
			return '<p>' . esc_html(
				$partner
					/* translators: %s: partner name. */
					? sprintf( __( 'Every organization on this site belongs to “%s”. The partner\'s leads can manage it as its co-leads do.', 'packout-core' ), $partner->post_title )
					: __( 'Every organization on this site belongs to the site\'s partner.', 'packout-core' )
			) . '</p>';
		}
		if ( $current > 0 ) {
			$partner = Partners::get( $current );
			$html    = '<p>' . esc_html(
				/* translators: %s: partner name. */
				sprintf( __( 'This organization is filed under the partner “%s”. The partner\'s leads can manage it as its co-leads do. Its missions, campaigns, impact, and adopted Places are its own.', 'packout-core' ), $partner ? $partner->post_title : '' )
			) . '</p>';
			if ( $owns || Partners::owns( $current, $user ) ) {
				$html .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'partner_release', $id, __( 'Leave the partner', 'packout-core' ), array(), 'pos-ws__button--danger', __( 'Leave this partner?', 'packout-core' ), '', __( 'The organization has no partner again. It keeps everything it has, and its work leaves the partner\'s programs and totals.', 'packout-core' ) ) . '</div>';
			} else {
				$html .= '<p class="pos-ws__muted">' . esc_html__( 'The organization\'s lead decides whether it leaves.', 'packout-core' ) . '</p>';
			}
			return $html;
		}

		$html     = '<p>' . esc_html__( 'A partner answers for several organizations and runs programs across them. An organization joins a partner only when both sides agree, and can leave. It belongs to one partner at most.', 'packout-core' ) . '</p>';
		$requests = '';
		foreach ( Partners::requests_of_team( $id ) as $request ) {
			$partner = Partners::get( $request['partner'] );
			if ( ! $partner ) {
				continue;
			}
			$hidden = array( 'partner' => (string) $partner->ID );
			if ( 'partner' === $request['from'] ) {
				$requests .= '<li><span>' . esc_html( $partner->post_title ) . ' <span class="pos-ws__muted">' . esc_html__( 'Asked this organization to join it', 'packout-core' ) . '</span></span>';
				$requests .= Workspace::act( 'partner_answer', $id, __( 'Accept', 'packout-core' ), $hidden + array( 'accept' => '1' ), 'pos-ws__button--primary', __( 'File this organization under the partner?', 'packout-core' ), '', __( 'The partner\'s leads will be able to manage the organization as its co-leads do. You can leave later.', 'packout-core' ) ) . Workspace::act( 'partner_answer', $id, __( 'Decline', 'packout-core' ), $hidden ) . '</li>';
			} else {
				$requests .= '<li><span>' . esc_html( $partner->post_title ) . ' <span class="pos-ws__muted">' . esc_html__( 'Waiting for the partner\'s lead', 'packout-core' ) . '</span></span>' . ( $owns ? Workspace::act( 'partner_withdraw', $id, __( 'Withdraw', 'packout-core' ), $hidden ) : '' ) . '</li>';
			}
		}
		if ( '' !== $requests ) {
			$html .= '<ul class="pos-ws__rows">' . $requests . '</ul>';
		}

		if ( ! $owns ) {
			return $html . '<p class="pos-ws__muted">' . esc_html__( 'The organization\'s lead asks a partner.', 'packout-core' ) . '</p>';
		}
		if ( ! Teams::active( $id ) ) {
			return $html . '<p class="pos-ws__help">' . esc_html__( 'An organization can ask a partner once a coordinator has approved it.', 'packout-core' ) . '</p>';
		}
		$choices = array();
		foreach ( Partners::all() as $partner ) {
			if ( Partners::allows( $partner->ID, 'organization' ) && 'remote' !== Partners::origin( $partner->ID ) && '' === Partners::asked_by( $id, $partner->ID ) && ( 'publish' === $partner->post_status || Partners::leads_partner( $partner->ID, $user ) ) ) {
				$choices[ $partner->ID ] = $partner->post_title;
			}
		}
		if ( ! $choices ) {
			return $html . '<p class="pos-ws__muted">' . esc_html__( 'No listed partner is taking organizations right now.', 'packout-core' ) . '</p>';
		}
		$html .= Workspace::open( 'partner_ask', $id ) . Workspace::choose( 'partner', __( 'Ask to join', 'packout-core' ), $choices, Workspace::old( 'partner_ask', 'partner' ), __( 'The partner\'s lead is asked. Nothing changes until they accept.', 'packout-core' ) );
		return $html . Workspace::submit( __( 'Send the request', 'packout-core' ) ) . '</form>';
	}

	/**
	 * The Programs view, in a partner's workspace or an organization's.
	 *
	 * @param string $kind  `partner` or `team`: whose workspace this is.
	 * @param int    $owner The partner or the organization.
	 * @param int    $user  User ID.
	 */
	public static function programs( string $kind, int $owner, int $user ): string {
		$html = '<p class="pos-ws__muted">' . esc_html__( 'A program is a standing line of work that outlasts any one campaign. Campaigns and missions of the organizations taking part are filed under it, and it is always shown with its owner\'s name.', 'packout-core' ) . '</p>';
		if ( Programs::can_open( $kind, $owner, $user ) ) {
			$html .= '<p class="pos-ws__actions">' . Workspace_Lists::opener( 'program-new', __( 'Open a program', 'packout-core' ), __( 'Open a program', 'packout-core' ) ) . '</p>';
			Workspace_Lists::pane( 'program-new', __( 'Open a program', 'packout-core' ), self::program_form( $kind, $owner, null ) );
		} elseif ( 'team' === $kind && Partners::of_team( $owner ) > 0 && Teams::can_run( $owner, $user ) ) {
			$html .= '<p>' . esc_html(
				/* translators: %s: partner name. */
				sprintf( __( 'This organization is under the partner “%s”, which opens the programs it takes part in.', 'packout-core' ), get_the_title( Partners::of_team( $owner ) ) )
			) . '</p>';
		}

		$rows = array();
		foreach ( 'partner' === $kind ? Programs::of_partner( $owner ) : Programs::of_team( $owner ) as $program ) {
			if ( ! Programs::can_see( $program->ID, $user ) ) {
				continue;
			}
			$id       = $program->ID;
			$progress = Programs::progress( $id );
			$pane     = 'program-' . $id;
			$rows[]   = array(
				'id'        => (string) $id,
				'title'     => $program->post_title,
				'url'       => Workspace_Lists::link( $pane ),
				'state'     => 'closed' === Programs::state( $id ) ? __( 'Closed', 'packout-core' ) : __( 'Open', 'packout-core' ),
				'window'    => Programs::window_line( $id ),
				'orgs'      => implode( ', ', array_map( 'get_the_title', Programs::taking_part( $id ) ) ),
				'campaigns' => $progress['campaigns'],
				'open'      => $progress['planned'] + $progress['in_progress'],
			);
			Workspace_Lists::pane( $pane, $program->post_title, self::program_detail( $program, $kind, $owner, $user ) );
		}
		return $html . Workspace_Lists::view(
			'programs',
			array(
				'state'     => array(
					'label' => __( 'State', 'packout-core' ),
					'sort'  => true,
				),
				'window'    => array( 'label' => __( 'Window', 'packout-core' ) ),
				'orgs'      => array( 'label' => __( 'Taking part', 'packout-core' ) ),
				'campaigns' => array(
					'label' => __( 'Campaigns', 'packout-core' ),
					'type'  => 'integer',
					'sort'  => true,
				),
				'open'      => array(
					'label' => __( 'Open missions', 'packout-core' ),
					'type'  => 'integer',
					'sort'  => true,
				),
			),
			$rows,
			array(
				'title'       => __( 'Program', 'packout-core' ),
				'description' => 'orgs',
				'empty'       => __( 'No programs yet.', 'packout-core' ),
			)
		);
	}

	/**
	 * One program, for the drawer.
	 *
	 * @param \WP_Post $program Program.
	 * @param string   $kind    `partner` or `team`: whose workspace this is.
	 * @param int      $owner   The partner or the organization whose workspace this is.
	 * @param int      $user    User ID.
	 */
	private static function program_detail( \WP_Post $program, string $kind, int $owner, int $user ): string {
		$id      = $program->ID;
		$change  = Programs::can_change( $id, $user );
		$public  = 'publish' === $program->post_status;
		$closed  = 'closed' === Programs::state( $id );
		$prefix  = 'pos-program-' . $id . '-';
		$whose   = Programs::owner( $id );
		$hidden  = array( 'item' => (string) $id );
		$details = '<p class="pos-ws__badges">' . Admin::badge( $closed ? __( 'Closed', 'packout-core' ) : __( 'Open', 'packout-core' ), $closed ? 'none' : 'stable' ) . ' ' . Admin::badge( $public ? __( 'Public', 'packout-core' ) : __( 'Its people only', 'packout-core' ), $public ? 'stable' : 'none' ) . '</p>';
		$cover   = Page_Header::cover( $id );
		if ( $cover > 0 ) {
			$details .= '<p class="pos-ws__cover">' . wp_get_attachment_image( $cover, 'large', false, array( 'alt' => '' ) ) . '</p>';
		}
		$details .= '<p><strong>' . esc_html( Programs::owner_line( $id ) ) . '</strong></p>';
		$details .= '<p>' . esc_html( Programs::progress_line( Programs::progress( $id ) ) ) . '</p>';
		$totals   = Programs::totals( $id );
		if ( (int) $totals['records']['cleanups'] > 0 ) {
			$details .= '<p class="pos-ws__muted">' . esc_html( Campaigns::totals_line( $totals ) ) . '</p>';
		}
		if ( '' !== trim( $program->post_content ) ) {
			$details .= wpautop( esc_html( $program->post_content ) );
		}
		$teams = array();
		foreach ( Programs::subteams( $id ) as $row ) {
			$teams[] = get_the_title( $row['team'] ) . ': ' . $row['name'];
		}
		$details .= Workspace_Views::facts(
			array(
				__( 'Goal', 'packout-core' )          => Programs::field( $id, 'goal' ),
				__( 'Window', 'packout-core' )        => Programs::window_line( $id ),
				__( 'Organizations', 'packout-core' ) => implode( ', ', array_map( 'get_the_title', Programs::taking_part( $id ) ) ),
				__( 'Teams', 'packout-core' )         => implode( ', ', $teams ),
				__( 'Supported by', 'packout-core' )  => implode( ', ', wp_list_pluck( Programs::sponsors( $id ), 'name' ) ),
			)
		);
		$actions  = '';
		if ( Programs::shown( $id ) ) {
			$actions .= '<a class="pos-ws__button" href="' . esc_url( (string) get_permalink( $program ) ) . '">' . esc_html__( 'Open the public page', 'packout-core' ) . '</a> ';
		} elseif ( $public ) {
			$details .= '<p class="pos-ws__muted">' . esc_html__( 'The program is public, and its owner is not listed, so it has no public page yet.', 'packout-core' ) . '</p>';
		} else {
			$details .= '<p class="pos-ws__muted">' . esc_html__( 'This program is shown to the people of the organizations taking part only, so it has no public page.', 'packout-core' ) . '</p>';
		}
		$actions .= Overview_Report::link( 'program', $id, __( 'Impact report', 'packout-core' ), 0 );
		$details .= '<p class="pos-ws__actions">' . $actions . '</p>';

		// What is filed under it. Each opens in its organization's workspace.
		$filed    = '';
		$statuses = Missions::statuses();
		foreach ( Programs::campaigns( $id ) as $campaign ) {
			$team   = Campaigns::team( $campaign->ID );
			$filed .= '<li><span><a href="' . esc_url( Workspace::url( $team, 'campaign', (string) $campaign->ID ) ) . '">' . esc_html( $campaign->post_title ) . '</a></span><span class="pos-ws__muted">' . esc_html( implode( ' · ', array_filter( array( __( 'Campaign', 'packout-core' ), get_the_title( $team ), Campaigns::progress_line( Campaigns::progress( $campaign->ID ) ) ) ) ) ) . '</span></li>';
		}
		foreach ( Programs::missions( $id ) as $mission ) {
			$team   = Missions::team( $mission->ID );
			$filed .= '<li><span><a href="' . esc_url( Workspace::url( $team, 'mission', (string) $mission->ID ) ) . '">' . esc_html( $mission->post_title ) . '</a></span><span class="pos-ws__muted">' . esc_html( implode( ' · ', array_filter( array( __( 'Mission', 'packout-core' ), get_the_title( $team ), $statuses[ Missions::status( $mission->ID ) ] ?? '', Missions::window_label( $mission->ID ) ) ) ) ) . '</span></li>';
		}
		$filed  = '' !== $filed ? '<ul class="pos-ws__rows">' . $filed . '</ul>' : '<p class="pos-ws__empty">' . esc_html__( 'Nothing is filed under this program yet.', 'packout-core' ) . '</p>';
		$filed .= '<p class="pos-ws__muted">' . esc_html( $closed ? __( 'A closed program takes no new campaigns or missions.', 'packout-core' ) : __( 'To file a campaign or a mission under it, choose this program on that campaign\'s or mission\'s form, in its organization\'s workspace. A mission in a campaign follows the campaign.', 'packout-core' ) ) . '</p>';

		$sections = array(
			array(
				'id'    => $prefix . 'details',
				'label' => __( 'Details', 'packout-core' ),
				'html'  => $details,
			),
			array(
				'id'    => $prefix . 'filed',
				'label' => __( 'Campaigns and missions', 'packout-core' ),
				'html'  => $filed,
			),
		);
		$parts    = array();
		foreach ( Programs::by_organization( $id ) as $row ) {
			$parts[] = array(
				'title'  => $row['name'],
				'url'    => Workspace::url( $row['team'], 'impact' ),
				'totals' => $row['totals'],
			);
		}
		if ( count( $parts ) > 1 ) {
			$sections[] = array(
				'id'    => $prefix . 'split',
				'label' => __( 'By organization', 'packout-core' ),
				'html'  => self::split( $parts, 'program-' . $id . '-split' ),
			);
		}

		if ( $change ) {
			$count = Programs::filed( $id );
			$plan  = self::program_form( $whose['kind'], $whose['id'], $program, $owner );
			if ( $cover > 0 ) {
				$plan .= '<h4>' . esc_html__( 'Cover image', 'packout-core' ) . '</h4><p class="pos-ws__cover">' . wp_get_attachment_image( $cover, 'large', false, array( 'alt' => '' ) ) . '</p>';
				$plan .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'program_cover_remove', $owner, __( 'Remove the cover image', 'packout-core' ), $hidden, 'pos-ws__button--danger', __( 'Remove the cover image?', 'packout-core' ), '', __( 'The image is deleted. The public page keeps its header, on a dark ground.', 'packout-core' ) ) . '</div>';
			}
			$plan .= '<h4>' . esc_html( $closed ? __( 'Open this program again', 'packout-core' ) : __( 'Close this program', 'packout-core' ) ) . '</h4><p>' . esc_html( $closed ? __( 'It takes new campaigns and missions again.', 'packout-core' ) : __( 'A closed program keeps its page and its history, and takes no new campaigns or missions.', 'packout-core' ) ) . '</p>';
			$plan .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'program_state', $owner, $closed ? __( 'Open the program again', 'packout-core' ) : __( 'Close the program', 'packout-core' ), $hidden + array( 'to' => $closed ? 'open' : 'closed' ) ) . '</div>';
			/* translators: 1: number of campaigns, 2: number of missions. */
			$unfiled    = sprintf( __( 'The program and its public page go away. %1$d campaigns and %2$d missions are unfiled: they stay with their organizations, in no program.', 'packout-core' ), $count['campaigns'], $count['missions'] );
			$plan      .= '<h4>' . esc_html__( 'Remove this program', 'packout-core' ) . '</h4><p>' . esc_html( $unfiled ) . '</p>';
			$plan      .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'program_remove', $owner, __( 'Remove the program', 'packout-core' ), $hidden, 'pos-ws__button--danger', __( 'Remove this program?', 'packout-core' ), '', $unfiled ) . '</div>';
			$sections[] = array(
				'id'    => $prefix . 'change',
				'label' => __( 'Change the program', 'packout-core' ),
				'html'  => $plan,
			);
		}
		// An organization's own program, seen by a lead of the partner it has since joined.
		$partner = 'team' === $whose['kind'] ? Partners::of_team( $whose['id'] ) : 0;
		if ( $partner > 0 && Partners::allows( $partner, 'program' ) && Partners::can_run( $partner, $user ) ) {
			$take = '<p>' . esc_html(
				/* translators: %s: partner name. */
				sprintf( __( 'The partner “%s” becomes the owner of this program, and can then name more of its organizations to take part. The organization that opened it still takes part.', 'packout-core' ), get_the_title( $partner ) )
			) . '</p>';
			$take      .= '<div class="pos-ws__group pos-ws__pane-actions">' . Workspace::act( 'program_take_over', $owner, __( 'Take the program over', 'packout-core' ), $hidden, '', __( 'Make the partner the owner of this program?', 'packout-core' ), '', __( 'This cannot be handed back to the organization.', 'packout-core' ) ) . '</div>';
			$sections[] = array(
				'id'    => $prefix . 'take',
				'label' => __( 'Take it over', 'packout-core' ),
				'html'  => $take,
			);
		}
		unset( $kind );
		$part = Workspace_Views::refused_pane() === 'program-' . $id ? 'change' : Workspace_Views::part( $id );
		return Workspace::sections( $program->post_title, $sections, 'top', '' !== $part ? $prefix . $part : '' );
	}

	/**
	 * The form that opens a program or changes one.
	 *
	 * @param string        $kind    `partner` or `team`: the owner.
	 * @param int           $owner   The owner's ID.
	 * @param \WP_Post|null $program Program, or null for a new one.
	 * @param int           $from    The partner or organization whose workspace the form is in. The owner when 0.
	 */
	private static function program_form( string $kind, int $owner, ?\WP_Post $program, int $from = 0 ): string {
		$id     = $program ? $program->ID : 0;
		$do     = 'program_save';
		$mine   = Workspace_Views::mine( $do, $id );
		$old    = static fn( string $name, string $fallback = '' ): string => $mine ? Workspace::old( $do, $name, $fallback ) : $fallback;
		$ticked = static fn( string $name, array $fallback ): array => $mine ? Workspace::old_list( $do, $name, $fallback ) : $fallback;

		// A new program posts its owner. An existing one posts the workspace it was opened from, so the person comes back to it.
		$html  = Workspace::open( $do, $program && $from > 0 ? $from : $owner, array( 'item' => (string) $id ), true );
		$html .= Workspace::input(
			'title',
			__( 'Name', 'packout-core' ),
			$old( 'title', $program ? $program->post_title : '' ),
			array(
				'required'  => '',
				'maxlength' => '120',
			)
		);
		$html .= Workspace::input( 'goal', __( 'Goal', 'packout-core' ), $old( 'goal', $program ? Programs::field( $id, 'goal' ) : '' ), array( 'maxlength' => (string) \PackOut\Core\Program::MAX_GOAL ), __( 'One sentence, such as "Every adopted road walked four times a year".', 'packout-core' ) );
		$html .= Workspace::area( 'description', __( 'Description', 'packout-core' ), $old( 'description', $program ? $program->post_content : '' ), __( 'Shown in the header of the public program page when the program is public.', 'packout-core' ) );
		$html .= '<div class="pos-ws__pair">' . Workspace::input( 'start', __( 'Starts', 'packout-core' ), $old( 'start', $program ? Programs::field( $id, 'start' ) : '' ), array( 'type' => 'date' ) );
		$html .= Workspace::input( 'end', __( 'Ends', 'packout-core' ), $old( 'end', $program ? Programs::field( $id, 'end' ) : '' ), array( 'type' => 'date' ), __( 'Leave empty for a program with no last day.', 'packout-core' ) ) . '</div>';

		$taking = $program ? Programs::taking_part( $id ) : array();
		if ( 'partner' === $kind ) {
			$orgs = wp_list_pluck( Partners::organizations( $owner ), 'post_title', 'ID' );
			// Present even with none ticked, so unticking the last one is still an edit.
			$html .= '<input type="hidden" name="has_orgs" value="1" />';
			$html .= $orgs
				? Workspace::ticks( 'orgs', __( 'Organizations taking part', 'packout-core' ), $orgs, $ticked( 'orgs', $taking ), __( 'Only their campaigns and missions can be filed under the program. Unticking one takes its campaigns and missions out of the program.', 'packout-core' ) )
				: '<p class="pos-ws__muted">' . esc_html__( 'This partner has no organizations yet. Add them under Organizations, then choose who takes part.', 'packout-core' ) . '</p>';
		} else {
			$taking = array( $owner );
		}

		$stored = array();
		foreach ( $program ? Programs::subteams( $id ) : array() as $row ) {
			$stored[] = $row['team'] . ':' . $row['subteam'];
		}
		$boxes = '';
		foreach ( $taking as $team ) {
			foreach ( Subteams::all( $team ) as $subteam ) {
				$value  = $team . ':' . $subteam['id'];
				$label  = 'partner' === $kind ? get_the_title( $team ) . ': ' . $subteam['name'] : (string) $subteam['name'];
				$boxes .= Workspace::tick( 'program_subteams[]', $label, in_array( $value, $stored, true ), $value );
			}
		}
		if ( '' !== $boxes ) {
			$html .= '<fieldset class="pos-ws__set"><legend>' . esc_html__( 'Teams taking part', 'packout-core' ) . '</legend><p class="pos-ws__help">' . esc_html__( 'Named on the program\'s public page when they have a page of their own.', 'packout-core' ) . '</p>' . $boxes . '</fieldset>';
		}

		$sponsors = wp_list_pluck( Sponsors::all( $owner ), 'name', 'id' );
		if ( $sponsors ) {
			$html .= Workspace::ticks( 'sponsors', __( 'Supported by', 'packout-core' ), $sponsors, $ticked( 'sponsors', $program ? wp_list_pluck( Programs::sponsors( $id ), 'id' ) : array() ), __( 'Sponsors are credited in the header of the public program page.', 'packout-core' ) );
		}
		$html .= Workspace_Views::cover_field( $program && Page_Header::cover( $id ) > 0, __( 'Shown behind the header of the public program page.', 'packout-core' ) );
		$html .= Workspace::tick( 'public', __( 'Public: give the program its own page with its goal, who takes part, its public campaigns and missions, and its impact. The page says whose program it is and names no person.', 'packout-core' ), $mine ? Workspace::old_on( $do, 'public', false ) : ( $program && 'publish' === $program->post_status ) );
		return $html . Workspace::submit( $program ? __( 'Save the program', 'packout-core' ) : __( 'Open this program', 'packout-core' ) ) . '</form>';
	}
}
