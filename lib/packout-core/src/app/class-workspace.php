<?php
/**
 * The team workspace: requests and form helpers.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * My teams: where a signed-in person starts, joins, and runs a team.
 *
 * It is one block on an ordinary page. The address chooses the view, and
 * every form posts back to the page. This class checks who is asking, hands
 * the change to the record classes, and says what happened. The record
 * classes decide whether the person may make the change.
 */
class Workspace {

	private const NONCE = 'packout_workspace';

	/**
	 * What went wrong with the form that was just sent, if anything.
	 *
	 * @var \WP_Error|null
	 */
	private static ?\WP_Error $problem = null;

	/**
	 * The action that failed, so its form can show what was typed.
	 *
	 * @var string
	 */
	private static string $failed = '';

	/**
	 * What the last form did, once it has been read for this page.
	 *
	 * @var string
	 */
	private static string $said = '';

	/**
	 * Register the hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'handle' ), 31 );
		add_action( 'template_redirect', array( self::class, 'headers' ) );
	}

	/**
	 * Keep the workspace out of page caches.
	 */
	public static function headers(): void {
		$page = (int) Settings::get( 'workspace_page' );
		if ( $page > 0 && is_page( $page ) && is_user_logged_in() ) {
			nocache_headers();
		}
	}

	/**
	 * Address of a view.
	 *
	 * A partner's views are on the same page, so the same forms and the same
	 * links serve both: the address names a partner where the record is one.
	 *
	 * @param int    $team Team ID or partner ID, or 0 for the list of teams.
	 * @param string $view View name.
	 * @param string $item Record the view is about: an ID, or `new`.
	 */
	public static function url( int $team = 0, string $view = '', string $item = '' ): string {
		return Accounts::workspace_url(
			array_filter(
				array(
					( $team > 0 && Partners::get( $team ) ? 'partner' : 'team' ) => $team > 0 ? (string) $team : '',
					'view' => $view,
					'item' => $item,
				),
				static fn( $value ) => '' !== $value
			)
		);
	}

	/**
	 * Act on a form from the workspace.
	 */
	public static function handle(): void {
		$do = isset( $_POST['packout_workspace'] ) ? sanitize_key( wp_unslash( $_POST['packout_workspace'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below.
		if ( '' === $do ) {
			return;
		}
		self::$problem = null;
		$user          = get_current_user_id();
		if ( $user <= 0 ) {
			nocache_headers();
			wp_safe_redirect( Accounts::signin_url( Accounts::current_url() ) );
			exit;
		}
		self::$failed = $do;
		if ( ! isset( $_POST['_posnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_posnonce'] ) ), self::NONCE ) ) {
			self::$problem = new \WP_Error( 'packout_expired', __( 'That form was open too long. Send it again.', 'packout-core' ) );
			return;
		}
		if ( ! Accounts::on_site( $user ) ) {
			self::$problem = new \WP_Error( 'packout_forbidden', __( 'Join Pack-Out Spot first.', 'packout-core' ) );
			return;
		}

		$text = static fn( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		$long = static fn( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		$list = static fn( string $key ): array => isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? array_values( array_map( 'intval', wp_unslash( $_POST[ $key ] ) ) ) : array();
		$on   = static fn( string $key ): bool => ! empty( $_POST[ $key ] );
		$team = isset( $_POST['team'] ) ? (int) $_POST['team'] : 0;
		$item = isset( $_POST['item'] ) ? (int) $_POST['item'] : 0;

		$result  = new \WP_Error( 'packout_unknown', __( 'That could not be done.', 'packout-core' ) );
		$message = '';
		$to      = '';
		$refused = new \WP_Error( 'packout_forbidden', __( 'You are not allowed to do that.', 'packout-core' ) );

		switch ( $do ) {
			case 'propose':
				$result = Teams::propose(
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
					$message = __( 'Your organization is waiting for a coordinator. You can set it up now: invite people, and add teams, sponsors, and resources. Campaigns and missions open once it is approved.', 'packout-core' );
				}
				break;

			case 'ask':
				$result = Teams::ask( $team, $user );
				$back   = Accounts::local( isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '' );
				if ( 'joined' === $result ) {
					$to      = self::url( $team );
					$message = __( 'You are in the organization. Its missions appear in the field app after your next sync.', 'packout-core' );
				} elseif ( ! is_wp_error( $result ) ) {
					$to      = '' !== $back ? $back : self::url();
					$message = __( 'Your request is with the organization\'s lead. You will get an email when they answer.', 'packout-core' );
				}
				break;

			case 'withdraw':
				$result  = Teams::withdraw( $team, $user ) ? true : new \WP_Error( 'packout_not_found', __( 'That request is no longer waiting.', 'packout-core' ) );
				$back    = Accounts::local( isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '' );
				$to      = '' !== $back ? $back : self::url();
				$message = __( 'Your request was withdrawn.', 'packout-core' );
				break;

			case 'leave':
				$result  = Teams::leave( $team, $user );
				$to      = self::url();
				$message = __( 'You left the organization.', 'packout-core' );
				break;

			case 'answer':
				$result  = Teams::answer( $team, isset( $_POST['who'] ) ? (int) $_POST['who'] : 0, $on( 'accept' ), $user );
				$to      = self::url( $team, 'members' );
				$message = $on( 'accept' ) ? __( 'They are in the organization and have been told.', 'packout-core' ) : __( 'The request was declined and they have been told.', 'packout-core' );
				break;

			case 'invite':
				// What the invitation offers beyond membership. The server decides whether this person may offer it.
				$result  = Teams::invite(
					$team,
					$text( 'email' ),
					$user,
					array(
						'role'    => $text( 'role' ),
						'subteam' => isset( $_POST['subteam'] ) ? (int) $_POST['subteam'] : 0,
						'subrole' => $text( 'subrole' ),
					)
				);
				$to      = self::url( $team, 'subteams' === $text( 'from' ) ? 'subteams' : 'members' );
				$message = __( 'The invitation is on its way. The link in it is good for fourteen days.', 'packout-core' );
				break;

			case 'colead':
				$result  = Teams::set_colead( $team, isset( $_POST['who'] ) ? (int) $_POST['who'] : 0, $on( 'on' ), $user );
				$to      = self::url( $team, 'members' );
				$message = $on( 'on' ) ? __( 'They are a co-lead of the organization and have been told.', 'packout-core' ) : __( 'They are a member again.', 'packout-core' );
				break;

			case 'remove_member':
				$who = isset( $_POST['who'] ) ? (int) $_POST['who'] : 0;
				if ( ! Teams::get( $team ) || ! Teams::can_run( $team, $user ) ) {
					$result = $refused;
				} elseif ( in_array( $who, Teams::coleads( $team ), true ) && ! Teams::owns( $team, $user ) ) {
					$result = new \WP_Error( 'packout_forbidden', __( 'The organization\'s lead removes its co-leads.', 'packout-core' ) );
				} else {
					$result = Teams::remove_member( $team, $who ) ? true : $refused;
				}
				$to      = self::url( $team, 'members' );
				$message = __( 'They are out of the organization. Their account is unchanged.', 'packout-core' );
				break;

			case 'subteam_save':
				$input = array( 'name' => $text( 'name' ) );
				if ( isset( $_POST['lead'] ) ) {
					$input['lead'] = (int) $_POST['lead'];
				}
				$result  = Subteams::save( $team, $item, $input, $user );
				$to      = self::url( $team, 'subteams' );
				$message = __( 'Team saved.', 'packout-core' );
				break;

			case 'subteam_page':
				$result  = Subteams::update_page(
					$team,
					$item,
					array(
						'about'   => $long( 'about' ),
						'public'  => $on( 'public' ),
						'leads'   => $on( 'leads' ),
						'address' => $text( 'address' ),
						'join'    => $text( 'join' ),
					),
					$user
				);
				$to      = self::url( $team, 'subteams' );
				$message = __( 'Public page saved.', 'packout-core' );
				break;

			case 'subteam_youth':
				$result  = Subteams::set_youth( $team, $item, $on( 'youth' ), $user );
				$to      = self::url( $team, 'subteams' );
				$message = $on( 'youth' ) ? __( 'This is now a youth team.', 'packout-core' ) : __( 'This is no longer a youth team.', 'packout-core' );
				break;

			case 'subteam_ask':
				$result = Subteams::ask( $team, $item, $user );
				$back   = Accounts::local( isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '' );
				$to     = '' !== $back ? $back : self::url( $team, 'subteams' );
				if ( 'joined' === $result ) {
					$message = __( 'You are in the team. The missions assigned to it appear in the field app after your next sync.', 'packout-core' );
				} elseif ( ! is_wp_error( $result ) ) {
					$message = Teams::includes( $team, $user )
						? __( 'Your request is with the team\'s lead. You will get an email when they answer.', 'packout-core' )
						: __( 'Your request is with the organization\'s lead. You will get an email when they answer.', 'packout-core' );
				}
				break;

			case 'subteam_withdraw':
				$result  = Subteams::withdraw( $team, $item, $user ) ? true : new \WP_Error( 'packout_not_found', __( 'That request is no longer waiting.', 'packout-core' ) );
				$back    = Accounts::local( isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '' );
				$to      = '' !== $back ? $back : self::url();
				$message = __( 'Your request was withdrawn.', 'packout-core' );
				break;

			case 'subteam_leave':
				$result  = Subteams::leave( $team, $item, $user );
				$back    = Accounts::local( isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '' );
				$to      = '' !== $back ? $back : self::url( $team, 'subteams' );
				$message = __( 'You left the team. You are still in the organization.', 'packout-core' );
				break;

			case 'subteam_answer':
				$result  = Subteams::answer( $team, $item, isset( $_POST['who'] ) ? (int) $_POST['who'] : 0, $on( 'accept' ), $user );
				$to      = self::url( $team, 'subteams' );
				$message = $on( 'accept' ) ? __( 'They are in the team and have been told.', 'packout-core' ) : __( 'The request was declined and they have been told.', 'packout-core' );
				break;

			case 'subteam_remove':
				$result  = Subteams::remove( $team, $item, $user );
				$to      = self::url( $team, 'subteams' );
				$message = __( 'Team removed. Its people are still in the organization.', 'packout-core' );
				break;

			case 'subteam_member':
				$result  = Subteams::set_member( $team, $item, isset( $_POST['who'] ) ? (int) $_POST['who'] : 0, $on( 'in' ), $user );
				$to      = self::url( $team, 'subteams' );
				$message = $on( 'in' ) ? __( 'Added to the team.', 'packout-core' ) : __( 'Taken out of the team.', 'packout-core' );
				break;

			case 'subteam_colead':
				$result  = Subteams::set_colead( $team, $item, isset( $_POST['who'] ) ? (int) $_POST['who'] : 0, $on( 'on' ), $user );
				$to      = self::url( $team, 'subteams' );
				$message = $on( 'on' ) ? __( 'They co-lead the team.', 'packout-core' ) : __( 'They are a member of the team again.', 'packout-core' );
				break;

			case 'sponsor_save':
				$file    = self::upload( 'logo' );
				$result  = is_wp_error( $file ) ? $file : Sponsors::save(
					$team,
					$item,
					array(
						'name' => $text( 'name' ),
						'url'  => $text( 'url' ),
					),
					$user,
					$file
				);
				$to      = self::url( $team, 'sponsors' );
				$message = __( 'Sponsor saved.', 'packout-core' );
				break;

			case 'sponsor_remove':
				$result  = Sponsors::remove( $team, $item, $user );
				$to      = self::url( $team, 'sponsors' );
				$message = __( 'Sponsor removed.', 'packout-core' );
				break;

			case 'resource_add':
				// The form names one place: the team, a sub-team, or a mission.
				$for     = $text( 'for' );
				$level   = str_starts_with( $for, 'subteam-' ) ? 'subteam' : ( str_starts_with( $for, 'mission-' ) ? 'mission' : 'team' );
				$target  = 'team' === $level ? 0 : (int) substr( $for, 8 );
				$file    = self::upload( 'file' );
				$result  = is_wp_error( $file ) ? $file : Resources::add(
					$team,
					array(
						'title'    => $text( 'title' ),
						'url'      => $text( 'url' ),
						'note'     => $text( 'note' ),
						'audience' => $text( 'audience' ),
						'level'    => $level,
						'subteam'  => $target,
						'mission'  => $target,
					),
					$user,
					$file
				);
				$to      = 'mission' === $text( 'from' ) && 'mission' === $level ? add_query_arg( 'part', 'resources', self::url( $team, 'mission', (string) $target ) ) : self::url( $team, 'resources' );
				$message = __( 'Resource added.', 'packout-core' );
				break;

			case 'resource_remove':
				$result  = Resources::remove( $team, $item, $user );
				$back    = isset( $_POST['mission'] ) ? (int) $_POST['mission'] : 0;
				$to      = $back > 0 ? add_query_arg( 'part', 'resources', self::url( $team, 'mission', (string) $back ) ) : self::url( $team, 'resources' );
				$message = __( 'Resource removed.', 'packout-core' );
				break;

			case 'campaign_save':
				$cover = self::cover_upload();
				if ( is_wp_error( $cover ) ) {
					$result = $cover;
					break;
				}
				$result = Campaigns::save(
					$item,
					array(
						'team'        => $team,
						'title'       => $text( 'title' ),
						'description' => $long( 'description' ),
						'goal'        => $text( 'goal' ),
						'start'       => $text( 'start' ),
						'end'         => $text( 'end' ),
						'start_time'  => $text( 'start_time' ),
						'end_time'    => $text( 'end_time' ),
						'timezone'    => $text( 'timezone' ),
						'subteams'    => $list( 'subteams' ),
						'sponsors'    => $list( 'sponsors' ),
						'public'      => $on( 'public' ),
						// The Program field is on the form only when there is a program to choose.
					) + ( isset( $_POST['program'] ) ? array( 'program' => (int) $_POST['program'] ) : array() ),
					$user
				);
				if ( ! is_wp_error( $result ) ) {
					$to      = self::url( Campaigns::team( (int) $result ), 'campaign', (string) $result );
					$message = __( 'Campaign saved.', 'packout-core' ) . self::cover_note( (int) $result, $cover, $user );
				}
				break;

			case 'cover_remove':
				$mission = (bool) Missions::get( $item );
				$team    = $mission ? Missions::team( $item ) : Campaigns::team( $item );
				$result  = Campaigns::set_cover( $item, null, $user );
				$to      = add_query_arg( 'part', 'plan', self::url( $team, $mission ? 'mission' : 'campaign', (string) $item ) );
				$message = __( 'Cover image removed.', 'packout-core' );
				break;

			case 'signup':
				$result = Signups::sign_up( $item, $user, isset( $_POST['subteam'] ) ? (int) $_POST['subteam'] : 0 );
				$back   = Accounts::local( isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '' );
				$to     = '' !== $back ? $back : self::url();
				if ( 'requested' === $result ) {
					$message = __( 'Your sign-up is with the organization’s lead. You will get an email when they answer.', 'packout-core' );
				} elseif ( 'joined' === $result ) {
					$message = __( 'You are signed up, and you are in the organization. Its missions appear in the field app after your next sync.', 'packout-core' );
				} elseif ( ! is_wp_error( $result ) ) {
					$message = __( 'You are signed up. The organization’s leads have been told.', 'packout-core' );
				}
				break;

			case 'signup_withdraw':
				$result  = Signups::withdraw( $item, $user ) ? true : new \WP_Error( 'packout_not_found', __( 'You are not signed up for that.', 'packout-core' ) );
				$back    = Accounts::local( isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '' );
				$to      = '' !== $back ? $back : self::url();
				$message = __( 'Your sign-up was taken back.', 'packout-core' );
				break;

			case 'campaign_remove':
				$team    = Campaigns::team( $item );
				$result  = Campaigns::remove( $item, $user );
				$to      = self::url( $team, 'campaigns' );
				$message = __( 'Campaign removed. Its missions stay with the organization.', 'packout-core' );
				break;

			case 'mission_save':
				$cover = self::cover_upload();
				if ( is_wp_error( $cover ) ) {
					$result = $cover;
					break;
				}
				$lines = array();
				foreach ( isset( $_POST['lines'] ) && is_array( $_POST['lines'] ) ? wp_unslash( $_POST['lines'] ) : array() as $line => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each line is sanitized below.
					$lines[ sanitize_key( (string) $line ) ] = sanitize_text_field( (string) $value );
				}
				$result = Missions::save(
					$item,
					array(
						'title'         => $text( 'title' ),
						'objective'     => $long( 'objective' ),
						'description'   => $long( 'description' ),
						'team'          => $item > 0 ? Missions::team( $item ) : $team,
						'campaign'      => isset( $_POST['campaign'] ) ? (int) $_POST['campaign'] : 0,
						'subteams'      => $list( 'subteams' ),
						'sponsors'      => $list( 'sponsors' ),
						'spots'         => $list( 'spots' ),
						'start'         => $text( 'start' ),
						'end'           => $text( 'end' ),
						'start_time'    => $text( 'start_time' ),
						'end_time'      => $text( 'end_time' ),
						'timezone'      => $text( 'timezone' ),
						'meeting_note'  => $text( 'meeting_note' ),
						'meeting_share' => $on( 'meeting_share' ),
						'gear'          => $long( 'gear' ),
						'public'        => $on( 'public' ),
						'lines'         => $lines,
						'new_lines'     => $long( 'new_lines' ),
					) + ( isset( $_POST['program'] ) ? array( 'program' => (int) $_POST['program'] ) : array() ),
					$user
				);
				if ( ! is_wp_error( $result ) ) {
					$to      = self::url( Missions::team( (int) $result ), 'mission', (string) $result );
					$message = ( $item > 0 ? __( 'Mission saved.', 'packout-core' ) : __( 'Mission planned. The crew sees it in the field app after their next sync.', 'packout-core' ) ) . self::cover_note( (int) $result, $cover, $user );
				}
				break;

			case 'mission_status':
				$step = $text( 'to' );
				$next = array(
					'start'    => 'in_progress',
					'complete' => 'completed',
					'call_off' => 'called_off',
				);
				if ( Missions::get( $item ) && Missions::can_manage( $item, $user ) && isset( $next[ $step ] ) ) {
					$result = Missions::set_status( $item, $next[ $step ], 'spotted' === $text( 'open_to' ) ? 'spotted' : 'in_progress' );
				} else {
					$result = $refused;
				}
				$to      = self::url( Missions::team( $item ), 'mission', (string) $item );
				$message = array(
					'start'    => __( 'Mission started.', 'packout-core' ),
					'complete' => __( 'Mission completed.', 'packout-core' ),
					'call_off' => __( 'Mission called off.', 'packout-core' ),
				)[ $step ] ?? '';
				break;

			case 'mission_repeat':
				$carry = array();
				foreach ( isset( $_POST['carry'] ) && is_array( $_POST['carry'] ) ? wp_unslash( $_POST['carry'] ) : array() as $part ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each name is sanitized below.
					$carry[] = sanitize_key( (string) $part );
				}
				$result = Missions::repeat(
					$item,
					array(
						'every'  => $text( 'every' ),
						'copies' => $text( 'copies' ),
						'carry'  => $carry,
					),
					$user
				);
				if ( ! is_wp_error( $result ) ) {
					$days = array();
					foreach ( $result as $copy ) {
						$days[] = Workspace_Views::day( Missions::field( $copy, 'start' ) );
					}
					$to      = self::url( Missions::team( $item ), 'missions' );
					$message = sprintf(
						/* translators: 1: number of missions, 2: the days they start on. */
						_n( '%1$s mission planned, for %2$s. It has no Spots yet: add them when you plan the visit.', '%1$s missions planned, for %2$s. They have no Spots yet: add them when you plan each visit.', count( $result ), 'packout-core' ),
						number_format_i18n( count( $result ) ),
						implode( '; ', $days )
					);
				}
				break;

			case 'mission_trash':
				$team = Missions::team( $item );
				if ( Missions::get( $item ) && Missions::can_manage( $item, $user ) && ! Missions::is_open( $item ) && wp_trash_post( $item ) ) {
					Missions::flush();
					$result = true;
				} else {
					$result = $refused;
				}
				$to      = self::url( $team, 'missions' );
				$message = __( 'Mission moved to the trash.', 'packout-core' );
				break;

			case 'impact_verify':
			case 'impact_unverify':
				$team    = (int) Impact::field( $item, 'team', '0' );
				$result  = 'impact_verify' === $do ? Impact::verify( $item, $user ) : Impact::unverify( $item, $user );
				$to      = self::url( $team, 'impact' );
				$message = 'impact_verify' === $do ? __( 'Record verified.', 'packout-core' ) : __( 'Record returned to unverified.', 'packout-core' );
				break;

			case 'profile':
				$result  = Teams::update_profile(
					$team,
					array(
						'name'         => $text( 'name' ),
						'description'  => $long( 'description' ),
						'region'       => $text( 'region' ),
						'website'      => $text( 'website' ),
						'join'         => $text( 'join' ),
						'listed'       => $on( 'listed' ),
						'member_plans' => $on( 'member_plans' ),
						'show_leads'   => $on( 'show_leads' ),
					),
					$user
				);
				$to      = self::url( $team, 'settings' );
				$message = __( 'Organization saved.', 'packout-core' );
				break;

			case 'message':
				$result = Messages::send( $team, $text( 'to' ), $text( 'subject' ), $long( 'body' ), $user, $on( 'reply' ) );
				$to     = self::url( $team, 'messages' );
				if ( ! is_wp_error( $result ) ) {
					/* translators: %s: number of people. */
					$message = sprintf( _n( 'Sent to %s person.', 'Sent to %s people.', $result['people'], 'packout-core' ), number_format_i18n( $result['people'] ) );
					if ( $result['skipped'] > 0 ) {
						/* translators: %s: number of people. */
						$message .= ' ' . sprintf( _n( '%s has this organization\'s emails turned off and can read it here.', '%s have this organization\'s emails turned off and can read it here.', $result['skipped'], 'packout-core' ), number_format_i18n( $result['skipped'] ) );
					}
				}
				break;

			case 'address':
				$result  = Teams::set_address( $team, $text( 'address' ), $user );
				$to      = self::url( $team, 'settings' ) . '#pos-settings-address';
				$message = false === $result
					? __( 'That is already the organization\'s address.', 'packout-core' )
					: __( 'Address changed. Links to the old address still lead to the organization.', 'packout-core' );
				break;

			case 'logo':
				$file    = self::upload( 'logo' );
				$result  = is_wp_error( $file ) ? $file : ( $file ? Teams::set_logo( $team, $file, $user ) : new \WP_Error( 'packout_file_upload', __( 'Choose an image first.', 'packout-core' ) ) );
				$to      = self::url( $team, 'settings' ) . '#pos-settings-logo';
				$message = __( 'Logo saved.', 'packout-core' );
				break;

			case 'logo_remove':
				$result  = Teams::remove_logo( $team, $user );
				$to      = self::url( $team, 'settings' ) . '#pos-settings-logo';
				$message = __( 'Logo removed.', 'packout-core' );
				break;

			case 'subteam_logo':
				$file    = self::upload( 'logo' );
				$result  = is_wp_error( $file ) ? $file : ( $file ? Subteams::set_logo( $team, $item, $file, $user ) : new \WP_Error( 'packout_file_upload', __( 'Choose an image first.', 'packout-core' ) ) );
				$to      = self::url( $team, 'subteams' );
				$message = __( 'Logo saved.', 'packout-core' );
				break;

			case 'subteam_logo_remove':
				$result  = Subteams::set_logo( $team, $item, null, $user );
				$to      = self::url( $team, 'subteams' );
				$message = __( 'Logo removed.', 'packout-core' );
				break;

			case 'team_cover':
				$file    = self::cover_upload();
				$result  = is_wp_error( $file ) ? $file : ( $file ? Teams::set_cover( $team, $file, $user ) : new \WP_Error( 'packout_file_upload', __( 'Choose an image first.', 'packout-core' ) ) );
				$to      = self::url( $team, 'settings' ) . '#pos-settings-cover';
				$message = __( 'Cover image saved.', 'packout-core' );
				break;

			case 'team_cover_remove':
				$result  = Teams::set_cover( $team, null, $user );
				$to      = self::url( $team, 'settings' ) . '#pos-settings-cover';
				$message = __( 'Cover image removed.', 'packout-core' );
				break;

			case 'subteam_cover':
				$file    = self::cover_upload();
				$result  = is_wp_error( $file ) ? $file : ( $file ? Subteams::set_cover( $team, $item, $file, $user ) : new \WP_Error( 'packout_file_upload', __( 'Choose an image first.', 'packout-core' ) ) );
				$to      = self::url( $team, 'subteams' );
				$message = __( 'Cover image saved.', 'packout-core' );
				break;

			case 'subteam_cover_remove':
				$result  = Subteams::set_cover( $team, $item, null, $user );
				$to      = self::url( $team, 'subteams' );
				$message = __( 'Cover image removed.', 'packout-core' );
				break;

			case 'hand_over':
				$result  = Teams::hand_over( $team, isset( $_POST['to'] ) ? (int) $_POST['to'] : 0, $user );
				$to      = self::url( $team );
				$message = __( 'The organization has a new lead. You are still a member.', 'packout-core' );
				break;

			case 'adoption_save':
			case 'adoption_decide':
				$place   = isset( $_POST['place'] ) ? (int) $_POST['place'] : 0;
				$request = $text( 'request' );
				$input   = array();
				if ( 'adoption_save' === $do ) {
					$input['note'] = $text( 'note' );
					$message       = __( 'Note saved.', 'packout-core' );
				} else {
					$input['status'] = $text( 'status' );
					$message         = 'accepted' === $input['status'] ? __( 'The organization now adopts this Place.', 'packout-core' ) : __( 'The request was declined.', 'packout-core' );
				}
				$result = Places::decide_request( $place, $request, $user, $input );
				$to     = self::url( $team, 'submissions' );
				break;

			default:
				// A partner's forms, and the forms of programs.
				if ( Partner_Workspace::handles( $do ) ) {
					list( $result, $message, $to ) = Partner_Workspace::act( $do, $user );
				} elseif ( Joint::handles( $do ) ) {
					list( $result, $message, $to ) = Joint::act( $do, $user );
				}
		}//end switch

		if ( is_wp_error( $result ) ) {
			self::$problem = $result;
			return;
		}
		self::$failed = '';
		if ( '' !== $message ) {
			set_transient( 'pos_flash_' . $user, $message, 2 * MINUTE_IN_SECONDS );
		}
		nocache_headers();
		wp_safe_redirect( '' !== $to ? $to : self::url() );
		exit;
	}

	/**
	 * The cover image a campaign or mission form sent, checked before anything is saved.
	 *
	 * @return array<string, mixed>|\WP_Error|null
	 */
	public static function cover_upload() {
		$file = self::upload( 'cover' );
		if ( is_array( $file ) && '' === Photos::type_of( (string) $file['tmp_name'] ) ) {
			return new \WP_Error( 'packout_file_type', __( 'The cover must be an image: JPEG, PNG, HEIC, AVIF, WebP, GIF, TIFF, or BMP.', 'packout-core' ) );
		}
		return $file;
	}

	/**
	 * Store the cover a form sent. The record is saved by now, so a cover that fails is reported beside the good news.
	 *
	 * @param int                       $id   Campaign or mission ID.
	 * @param array<string, mixed>|null $file The upload, or null.
	 * @param int                       $user The person saving.
	 */
	private static function cover_note( int $id, ?array $file, int $user ): string {
		if ( ! $file ) {
			return '';
		}
		$stored = Campaigns::set_cover( $id, $file, $user );
		return is_wp_error( $stored ) ? ' ' . sprintf(
			/* translators: %s: what went wrong. */
			__( 'The cover image was not stored: %s', 'packout-core' ),
			$stored->get_error_message()
		) : '';
	}

	/**
	 * A file the form sent, null when it sent none, or what went wrong.
	 *
	 * @param string $key Field name.
	 * @return array<string, mixed>|\WP_Error|null
	 */
	public static function upload( string $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The caller checked the nonce. The file is checked by its contents when stored.
		$file = isset( $_FILES[ $key ] ) && is_array( $_FILES[ $key ] ) ? $_FILES[ $key ] : null;
		if ( ! $file || UPLOAD_ERR_NO_FILE === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return null;
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_string( $file['tmp_name'] ?? null ) ) {
			return new \WP_Error( 'packout_file_upload', __( 'The file did not arrive. It may be too large. Try a smaller one.', 'packout-core' ) );
		}
		return $file;
	}

	/**
	 * The Team workspace block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		unset( $attributes );
		$html = '<div ' . Ui::wrapper( 'pos-ws' ) . '>';
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// The editor's preview. The workspace itself depends on who is looking.
			return $html . self::notice( 'info', __( 'My organizations appears here for signed-in people: their organizations, members, teams, campaigns, missions, sponsors, and resources.', 'packout-core' ) ) . '</div>';
		}
		Ui::enqueue();
		wp_enqueue_style( 'packout-workspace' );
		wp_enqueue_script( 'packout-workspace' );

		$user = get_current_user_id();
		if ( $user > 0 ) {
			Workspace_Lists::enqueue();
		}
		if ( $user <= 0 ) {
			$html .= '<h2>' . esc_html__( 'Sign in to see your organizations', 'packout-core' ) . '</h2>';
			$html .= '<p>' . esc_html__( 'My organizations is where you start an organization, join one, and run its members, teams, campaigns, and missions.', 'packout-core' ) . '</p><p class="pos-ws__actions">';
			$html .= '<a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( Accounts::signin_url( Accounts::workspace_url() ) ) . '">' . esc_html__( 'Sign in', 'packout-core' ) . '</a>';
			if ( Accounts::open() ) {
				$html .= ' <a class="pos-ws__button" href="' . esc_url( Accounts::signup_url( Accounts::workspace_url() ) ) . '">' . esc_html__( 'Sign up', 'packout-core' ) . '</a>';
			}
			return $html . '</p></div>';
		}

		if ( ! Accounts::on_site( $user ) ) {
			$html .= '<h2>' . esc_html__( 'Join Pack-Out Spot', 'packout-core' ) . '</h2>';
			$html .= '<p>' . esc_html__( 'Your account is from another site on this network. Joining adds you here as a Reporter, so you can report Spots, join an organization, and start one.', 'packout-core' ) . '</p>';
			$html .= '<form method="post" class="pos-ws__form"><input type="hidden" name="packout_account" value="join_site" />' . wp_nonce_field( 'packout_join_site', '_posnonce', true, false );
			return $html . '<button class="pos-ws__button pos-ws__button--primary">' . esc_html__( 'Join Pack-Out Spot', 'packout-core' ) . '</button></form>' . self::signed_in_as( $user ) . '</div>';
		}

		$html .= self::flash( $user );
		if ( self::$problem ) {
			$html .= self::notice( 'error', self::$problem->get_error_message() );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the address to choose what to show.
		$team    = isset( $_GET['team'] ) ? (int) $_GET['team'] : 0;
		$partner = isset( $_GET['partner'] ) ? (int) $_GET['partner'] : 0;
		$view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$item    = isset( $_GET['item'] ) ? sanitize_key( wp_unslash( $_GET['item'] ) ) : '';
		$done    = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'welcome' === $done ) {
			$html .= self::notice( 'success', __( 'Your account is ready. Start an organization here, or find one to join.', 'packout-core' ) );
		} elseif ( 'joined' === $done ) {
			$html .= self::notice( 'success', $partner > 0 ? __( 'You are a co-lead of the partner.', 'packout-core' ) : __( 'You are in the organization. Its missions appear in the field app after your next sync.', 'packout-core' ) );
		}

		// The Partner workspace is on this page too, unless the page carries its own block for it.
		if ( $partner > 0 && 0 === $team && ! Partner_Workspace::has_own_block() ) {
			return $html . Partner_Workspace::partner( $partner, $view, $item, $user ) . self::signed_in_as( $user ) . '</div>';
		}

		$post = $team > 0 ? Teams::get( $team ) : null;
		if ( $team > 0 && ( ! $post || ! ( Teams::includes( $team, $user ) || Teams::can_edit( $user ) || Partners::reaches( $team, $user ) ) ) ) {
			$html .= self::notice( 'warning', __( 'You are not in that organization.', 'packout-core' ) );
			$post  = null;
		}
		$html .= $post ? Workspace_Views::team( $post, $view, $item, $user ) : Workspace_Views::home( $user );
		return $html . self::signed_in_as( $user ) . '</div>';
	}

	/**
	 * Who is signed in, for the Partner workspace when it is on a page of its own.
	 *
	 * @param int $user User ID.
	 */
	public static function who( int $user ): string {
		return self::signed_in_as( $user );
	}

	/**
	 * Who is signed in, with the way to sign out. People here may have no toolbar to do it from.
	 *
	 * Signing in on the site never opens the dashboard, so a person who works
	 * there is given the link to it.
	 *
	 * @param int $user User ID.
	 */
	private static function signed_in_as( int $user ): string {
		$person = get_userdata( $user );
		$html   = '<p class="pos-ws__who">' . esc_html(
			/* translators: %s: the person's name. */
			sprintf( __( 'Signed in as %s.', 'packout-core' ), $person ? $person->display_name : '' )
		);
		if ( (int) Settings::get( 'account_page' ) > 0 ) {
			$html .= ' <a href="' . esc_url( Profile::url() ) . '">' . esc_html__( 'My account', 'packout-core' ) . '</a> &middot;';
		}
		$html .= ' <a href="' . esc_url( Sign_In::out_url() ) . '">' . esc_html__( 'Sign out', 'packout-core' ) . '</a>';
		if ( Accounts::has_dashboard( $user ) ) {
			$html .= ' &middot; <a href="' . esc_url( admin_url() ) . '">' . esc_html__( 'Dashboard', 'packout-core' ) . '</a>';
		}
		return $html . '</p>';
	}

	/**
	 * What the last form did, said once. Empty when there is nothing to say.
	 *
	 * @param int $user User ID.
	 */
	public static function flash( int $user ): string {
		$flash = $user > 0 ? get_transient( 'pos_flash_' . $user ) : '';
		if ( ! is_string( $flash ) || '' === $flash ) {
			return '';
		}
		delete_transient( 'pos_flash_' . $user );
		self::$said = $flash;
		return self::notice( 'success', $flash );
	}

	/**
	 * What the last form did, for a pane that opens over the page's own notice.
	 */
	public static function said(): string {
		return self::$said;
	}

	/**
	 * Sections shown one at a time, chosen from a row of tabs.
	 *
	 * Without scripts every section is on the page and the tabs are links to
	 * them. The script shows one section and makes the links tabs: down the
	 * left of a page, across the top of a pane and of a narrow screen.
	 *
	 * @param string                                                     $label    What the tabs are, for a screen reader.
	 * @param array<int, array{id: string, label: string, html: string}> $sections The sections. An empty one is left out.
	 * @param string                                                     $layout   `side` for tabs down the left, `top` for tabs across the top.
	 * @param string                                                     $open     ID of the section to show first. The first one when empty.
	 * @param int                                                        $level    Heading level of each section's title: 3 under a view's heading, 2 on a page of its own.
	 */
	public static function sections( string $label, array $sections, string $layout = 'side', string $open = '', int $level = 3 ): string {
		$tag      = 2 === $level ? 'h2' : 'h3';
		$sections = array_values( array_filter( $sections, static fn( $section ) => '' !== $section['html'] ) );
		if ( ! $sections ) {
			return '';
		}
		$nav  = '';
		$body = '';
		foreach ( $sections as $section ) {
			$id    = (string) $section['id'];
			$nav  .= '<li><a href="#' . esc_attr( $id ) . '">' . esc_html( $section['label'] ) . '</a></li>';
			$body .= '<section class="pos-sections__panel" id="' . esc_attr( $id ) . '"><' . $tag . ' class="pos-sections__title">' . esc_html( $section['label'] ) . '</' . $tag . '>' . $section['html'] . '</section>';
		}
		if ( 1 === count( $sections ) ) {
			return '<div class="pos-sections pos-sections--one">' . $body . '</div>';
		}
		$html  = '<div class="pos-sections pos-sections--' . ( 'top' === $layout ? 'top' : 'side' ) . '" data-pos-sections' . ( '' !== $open ? ' data-pos-open="' . esc_attr( $open ) . '"' : '' ) . '>';
		$html .= '<nav class="pos-sections__nav" aria-label="' . esc_attr( $label ) . '"><ul>' . $nav . '</ul></nav>';
		return $html . '<div class="pos-sections__body">' . $body . '</div></div>';
	}

	/**
	 * The action of the form that was refused on this request, or nothing.
	 */
	public static function failed_action(): string {
		return self::$problem ? self::$failed : '';
	}

	/**
	 * What was wrong with the form this request sent, or nothing.
	 */
	public static function problem(): string {
		return self::$problem ? self::$problem->get_error_message() : '';
	}

	/**
	 * A notice.
	 *
	 * @param string $intent success, info, warning, or error.
	 * @param string $text   What to say.
	 */
	public static function notice( string $intent, string $text ): string {
		return Accounts::notice( $intent, $text );
	}

	/**
	 * Open a form that posts to this page.
	 *
	 * @param string                $act     Action name.
	 * @param int                   $team   Team ID.
	 * @param array<string, string> $hidden Other hidden fields.
	 * @param bool                  $files  Whether the form sends a file.
	 * @param string                $classes  Extra class.
	 */
	public static function open( string $act, int $team, array $hidden = array(), bool $files = false, string $classes = '' ): string {
		$html  = '<form method="post" class="pos-ws__form ' . esc_attr( $classes ) . '"' . ( $files ? ' enctype="multipart/form-data"' : '' ) . '>';
		$html .= '<input type="hidden" name="packout_workspace" value="' . esc_attr( $act ) . '" /><input type="hidden" name="team" value="' . esc_attr( (string) $team ) . '" />';
		foreach ( $hidden as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		return $html . wp_nonce_field( self::NONCE, '_posnonce', false, false );
	}

	/**
	 * A form that is one button.
	 *
	 * A button with the class `pos-ws__button--danger` is drawn as a red link.
	 * One with a question is asked in a dialog before the form is sent.
	 *
	 * @param string                $act      Action name.
	 * @param int                   $team    Team ID.
	 * @param string                $label   Button label.
	 * @param array<string, string> $hidden  Other hidden fields.
	 * @param string                $classes   Extra button class.
	 * @param string                $confirm Question to ask before sending.
	 * @param string                $spoken  Longer label for screen readers.
	 * @param string                $note    What will and will not happen, under the question.
	 */
	public static function act( string $act, int $team, string $label, array $hidden = array(), string $classes = '', string $confirm = '', string $spoken = '', string $note = '' ): string {
		$html = self::open( $act, $team, $hidden, false, 'pos-ws__form--inline' );
		return $html . '<button class="pos-ws__button ' . esc_attr( $classes ) . '"' . self::confirm( $confirm, $note, $label ) . ( '' !== $spoken ? ' aria-label="' . esc_attr( $spoken ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * The attributes of a button that asks before it sends its form.
	 *
	 * @param string $question The question.
	 * @param string $note     What will and will not happen.
	 * @param string $yes      What the button that goes ahead says.
	 */
	public static function confirm( string $question, string $note = '', string $yes = '' ): string {
		if ( '' === $question ) {
			return '';
		}
		return ' data-pos-confirm="' . esc_attr( $question ) . '"' . ( '' !== $note ? ' data-pos-confirm-note="' . esc_attr( $note ) . '"' : '' ) . ( '' !== $yes ? ' data-pos-confirm-yes="' . esc_attr( $yes ) . '"' : '' );
	}

	/**
	 * A submit button.
	 *
	 * @param string $label Button label.
	 */
	public static function submit( string $label ): string {
		return '<p class="pos-ws__actions"><button class="pos-ws__button pos-ws__button--primary">' . esc_html( $label ) . '</button></p>';
	}

	/**
	 * What was typed into a form that failed, or the stored value.
	 *
	 * @param string $act      The form's action.
	 * @param string $name    Field name.
	 * @param string $fallback Stored value.
	 */
	public static function old( string $act, string $name, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Showing back what the person typed.
		return self::$failed === $act && isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) ) : $fallback;
	}

	/**
	 * The boxes ticked in a form that failed, or the stored list.
	 *
	 * @param string          $act      The form's action.
	 * @param string          $name    Field name.
	 * @param array<int, int> $fallback Stored list.
	 * @return array<int, int>
	 */
	public static function old_list( string $act, string $name, array $fallback = array() ): array {
		if ( self::$failed !== $act ) {
			return $fallback;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Showing back what the person chose.
		return isset( $_POST[ $name ] ) && is_array( $_POST[ $name ] ) ? array_values( array_map( 'intval', wp_unslash( $_POST[ $name ] ) ) ) : array();
	}

	/**
	 * Whether a box was ticked in a form that failed, or the stored state.
	 *
	 * @param string $act      The form's action.
	 * @param string $name    Field name.
	 * @param bool   $fallback Stored state.
	 */
	public static function old_on( string $act, string $name, bool $fallback ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Showing back what the person chose.
		return self::$failed === $act ? ! empty( $_POST[ $name ] ) : $fallback;
	}

	/**
	 * A labeled text input.
	 *
	 * @param string                $name  Field name.
	 * @param string                $label Label.
	 * @param string                $value Value.
	 * @param array<string, string> $attrs Other attributes: type, maxlength, required, placeholder.
	 * @param string                $help  A line under the field.
	 */
	public static function input( string $name, string $label, string $value = '', array $attrs = array(), string $help = '' ): string {
		$id    = wp_unique_id( 'pos-ws-' );
		$attrs = array_merge( array( 'type' => 'text' ), $attrs );
		$html  = '<p class="pos-ws__field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"';
		foreach ( $attrs as $attr => $content ) {
			$html .= ' ' . esc_attr( $attr ) . ( 'required' === $attr ? '' : '="' . esc_attr( $content ) . '"' );
		}
		if ( 'file' !== $attrs['type'] ) {
			$html .= ' value="' . esc_attr( $value ) . '"';
		}
		return $html . ' />' . ( '' !== $help ? '<span class="pos-ws__help">' . esc_html( $help ) . '</span>' : '' ) . '</p>';
	}

	/**
	 * A labeled text area.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $help  A line under the field.
	 * @param int    $rows  Height in lines.
	 */
	public static function area( string $name, string $label, string $value = '', string $help = '', int $rows = 3 ): string {
		$id = wp_unique_id( 'pos-ws-' );
		return '<p class="pos-ws__field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . esc_attr( (string) $rows ) . '">' . esc_textarea( $value ) . '</textarea>' . ( '' !== $help ? '<span class="pos-ws__help">' . esc_html( $help ) . '</span>' : '' ) . '</p>';
	}

	/**
	 * A labeled select.
	 *
	 * @param string                    $name    Field name.
	 * @param string                    $label   Label.
	 * @param array<int|string, string> $options Labels by value.
	 * @param string                    $current Selected value.
	 * @param string                    $help    A line under the field.
	 */
	public static function choose( string $name, string $label, array $options, string $current = '', string $help = '' ): string {
		$id   = wp_unique_id( 'pos-ws-' );
		$html = '<p class="pos-ws__field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $text ) {
			$html .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $value, $current, false ) . '>' . esc_html( $text ) . '</option>';
		}
		return $html . '</select>' . ( '' !== $help ? '<span class="pos-ws__help">' . esc_html( $help ) . '</span>' : '' ) . '</p>';
	}

	/**
	 * A checkbox with its label.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param bool   $on    Whether it is ticked.
	 * @param string $value Value sent when ticked.
	 */
	public static function tick( string $name, string $label, bool $on, string $value = '1' ): string {
		return '<label class="pos-ws__tick"><input type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( $on, true, false ) . ' /> <span>' . esc_html( $label ) . '</span></label>';
	}

	/**
	 * A group of checkboxes under one legend.
	 *
	 * @param string             $name    Field name, without the brackets.
	 * @param string             $legend  Legend.
	 * @param array<int, string> $options Labels by number.
	 * @param array<int, int>    $chosen  Numbers that are ticked.
	 * @param string             $help    A line under the legend.
	 */
	public static function ticks( string $name, string $legend, array $options, array $chosen, string $help = '' ): string {
		$html = '<fieldset class="pos-ws__set"><legend>' . esc_html( $legend ) . '</legend>' . ( '' !== $help ? '<p class="pos-ws__help">' . esc_html( $help ) . '</p>' : '' );
		foreach ( $options as $value => $label ) {
			$html .= self::tick( $name . '[]', $label, in_array( (int) $value, $chosen, true ), (string) $value );
		}
		return $html . '</fieldset>';
	}
}
