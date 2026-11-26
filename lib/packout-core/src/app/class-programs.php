<?php
/**
 * Program records.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

use PackOut\Core\Program;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A program is a standing line of work that outlasts any one campaign.
 *
 * It has one owner: a partner, or an organization. The owner chooses the
 * organizations that take part, and their campaigns and missions can be
 * filed under it. A mission in a campaign follows that campaign.
 *
 * The rules are `PackOut\Core\Program`, which the partner plugin shares.
 * This class stores the record and asks there.
 */
class Programs {

	/**
	 * Register what keeps programs true when an organization leaves a partner.
	 */
	public static function register(): void {
		add_action( 'packout_organization_claimed', array( self::class, 'organization_moved' ), 10, 3 );
	}

	/**
	 * A program post, or null.
	 *
	 * @param int $id Post ID.
	 */
	public static function get( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && Schema::PROGRAM === $post->post_type && in_array( $post->post_status, array( 'publish', 'private' ), true ) ? $post : null;
	}

	/**
	 * A text field of a program.
	 *
	 * @param int    $id  Program ID.
	 * @param string $key Field name without the prefix.
	 */
	public static function field( int $id, string $key ): string {
		$value = get_post_meta( $id, '_pos_' . $key, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Whether the program is `open` or `closed`.
	 *
	 * @param int $id Program ID.
	 */
	public static function state( int $id ): string {
		return Program::state( get_post_meta( $id, '_pos_state', true ) );
	}

	/**
	 * Who owns a program.
	 *
	 * @param int $id Program ID.
	 * @return array{kind: string, id: int} `kind` is `partner`, `team`, or empty.
	 */
	public static function owner( int $id ): array {
		$partner = (int) get_post_meta( $id, '_pos_partner', true );
		$team    = (int) get_post_meta( $id, '_pos_team', true );
		return Program::owner( $partner > 0 && Partners::get( $partner ) ? $partner : 0, $team > 0 && Teams::get( $team ) ? $team : 0 );
	}

	/**
	 * The owner's record, or null.
	 *
	 * @param int $id Program ID.
	 */
	public static function owner_post( int $id ): ?\WP_Post {
		$owner = self::owner( $id );
		return 'partner' === $owner['kind'] ? Partners::get( $owner['id'] ) : ( 'team' === $owner['kind'] ? Teams::get( $owner['id'] ) : null );
	}

	/**
	 * Whether the public may be shown the owner: it is listed, and its state allows a page.
	 *
	 * @param int $id Program ID.
	 */
	public static function owner_shown( int $id ): bool {
		$owner = self::owner( $id );
		if ( 'partner' === $owner['kind'] ) {
			return Partners::shown( $owner['id'], 'page' );
		}
		$team = 'team' === $owner['kind'] ? Teams::get( $owner['id'] ) : null;
		return $team && 'publish' === $team->post_status && Teams::active( $team->ID );
	}

	/**
	 * Whether the public may see the program's page.
	 *
	 * @param int $id Program ID.
	 */
	public static function shown( int $id ): bool {
		$program = self::get( $id );
		return $program && Program::shown( 'publish' === $program->post_status, self::owner_shown( $id ) );
	}

	/**
	 * The organizations that take part.
	 *
	 * @param int $id Program ID.
	 * @return array<int, int>
	 */
	public static function taking_part( int $id ): array {
		$owner = self::owner( $id );
		return Program::taking_part(
			$owner,
			array_map( 'intval', (array) get_post_meta( $id, '_pos_orgs', false ) ),
			'partner' === $owner['kind'] ? Partners::organization_ids( $owner['id'] ) : array()
		);
	}

	/**
	 * The teams of the organizations taking part that the program names.
	 *
	 * @param int $id Program ID.
	 * @return array<int, array{team: int, subteam: int, name: string}>
	 */
	public static function subteams( int $id ): array {
		$taking = self::taking_part( $id );
		$rows   = array();
		foreach ( Schema::stored_list( $id, 'subteams' ) as $raw ) {
			$parts   = explode( ':', (string) $raw );
			$team    = (int) ( $parts[0] ?? 0 );
			$number  = (int) ( $parts[1] ?? 0 );
			$subteam = in_array( $team, $taking, true ) ? Subteams::get( $team, $number ) : null;
			if ( $subteam ) {
				$rows[] = array(
					'team'    => $team,
					'subteam' => $number,
					'name'    => (string) $subteam['name'],
				);
			}
		}
		return $rows;
	}

	/**
	 * Sponsors credited on a program: any of its owner's.
	 *
	 * @param int $id Program ID.
	 * @return array<int, array{id: int, name: string, url: string, logo: int}>
	 */
	public static function sponsors( int $id ): array {
		return Sponsors::named( self::owner( $id )['id'], Schema::stored_list( $id, 'sponsors' ) );
	}

	/**
	 * Whether a person runs the owner: its lead, a co-lead, or a coordinator.
	 *
	 * @param array{kind: string, id: int} $owner   From owner().
	 * @param int                          $user_id User ID.
	 */
	private static function runs_owner( array $owner, int $user_id ): bool {
		if ( $user_id <= 0 || ! Capabilities::can( Capabilities::LEAD, $user_id ) ) {
			return false;
		}
		if ( 'partner' === $owner['kind'] ) {
			return Partners::can_run( $owner['id'], $user_id );
		}
		return 'team' === $owner['kind'] && Teams::can_run( $owner['id'], $user_id );
	}

	/**
	 * Whether a person may open a program for an owner.
	 *
	 * A partner opens one while it is active. An organization opens one
	 * while it is approved and has no partner; under a partner, programs are
	 * the partner's to open.
	 *
	 * @param string $kind    `partner` or `team`.
	 * @param int    $owner   The partner or the organization.
	 * @param int    $user_id User ID. Negative uses the current user.
	 */
	public static function can_open( string $kind, int $owner, int $user_id = -1 ): bool {
		if ( $user_id < 0 ) {
			$user_id = get_current_user_id();
		}
		$as = array(
			'kind' => $kind,
			'id'   => $owner,
		);
		if ( 'partner' === $kind ) {
			// A partner site opens its programs on its own site.
			return ! Remote::is_site( $owner ) && Partners::allows( $owner, 'program' ) && self::runs_owner( $as, $user_id );
		}
		return 'team' === $kind && ! Remote::is( $owner ) && Teams::get( $owner ) && Teams::active( $owner ) && 0 === Partners::of_team( $owner ) && self::runs_owner( $as, $user_id );
	}

	/**
	 * Whether a person may change, close, and remove a program: the owner's lead, a co-lead, or a coordinator.
	 *
	 * @param int $id      Program ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_change( int $id, int $user_id = -1 ): bool {
		// A copy of a record kept on a partner's site is changed there, by nobody here.
		if ( Remote::is( $id ) ) {
			return false;
		}
		if ( $user_id < 0 ) {
			$user_id = get_current_user_id();
		}
		if ( ! self::get( $id ) ) {
			return false;
		}
		$owner = self::owner( $id );
		if ( 'partner' === $owner['kind'] && ! Partners::allows( $owner['id'], 'program' ) ) {
			// A partner that is suspended keeps its programs and changes none of them.
			return Partners::can_edit( $user_id );
		}
		return self::runs_owner( $owner, $user_id );
	}

	/**
	 * Whether a person may read a program in the workspace.
	 *
	 * The people of the organizations taking part, the people who run them, the
	 * owner's leads, and coordinators.
	 *
	 * @param int $id      Program ID.
	 * @param int $user_id User ID.
	 */
	public static function can_see( int $id, int $user_id ): bool {
		if ( $user_id <= 0 || ! self::get( $id ) ) {
			return false;
		}
		$owner = self::owner( $id );
		if ( Capabilities::can( Capabilities::MODERATE, $user_id ) || Teams::can_edit( $user_id ) || Partners::can_edit( $user_id ) ) {
			return true;
		}
		if ( 'partner' === $owner['kind'] && Partners::leads_partner( $owner['id'], $user_id ) ) {
			return true;
		}
		foreach ( self::taking_part( $id ) as $team ) {
			// The people of the organization, and the leads of the partner it is under.
			if ( Teams::includes( $team, $user_id ) || Teams::can_run( $team, $user_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every program, by name.
	 *
	 * @param array<string, mixed> $query More query arguments.
	 * @return array<int, \WP_Post>
	 */
	public static function all( array $query = array() ): array {
		return get_posts(
			$query + array(
				'post_type'   => Schema::PROGRAM,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 300,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
	}

	/**
	 * The programs a partner owns.
	 *
	 * @param int  $partner     Partner ID.
	 * @param bool $public_only Only the ones the public may see.
	 * @return array<int, \WP_Post>
	 */
	public static function of_partner( int $partner, bool $public_only = false ): array {
		$found = self::all(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Programs of one partner.
				'meta_key'   => '_pos_partner',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Programs of one partner.
				'meta_value' => $partner,
			)
		);
		return $public_only ? array_values( array_filter( $found, static fn( $program ) => self::shown( $program->ID ) ) ) : $found;
	}

	/**
	 * The programs an organization owns or takes part in.
	 *
	 * @param int  $team        Organization ID.
	 * @param bool $public_only Only the ones the public may see.
	 * @return array<int, \WP_Post>
	 */
	public static function of_team( int $team, bool $public_only = false ): array {
		$found = self::all(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Programs of one organization.
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'   => '_pos_team',
						'value' => $team,
					),
					array(
						'key'   => '_pos_orgs',
						'value' => $team,
					),
				),
			)
		);
		// A stored row is not enough: an organization that left the partner no longer takes part.
		$found = array_values( array_filter( $found, static fn( $program ) => in_array( $team, self::taking_part( $program->ID ), true ) ) );
		return $public_only ? array_values( array_filter( $found, static fn( $program ) => self::shown( $program->ID ) ) ) : $found;
	}

	/**
	 * The open programs an organization's campaign or mission can be filed under.
	 *
	 * @param int $team Organization ID.
	 * @return array<int, string> Program names by ID.
	 */
	public static function choices( int $team ): array {
		$choices = array();
		foreach ( self::of_team( $team ) as $program ) {
			if ( 'open' === self::state( $program->ID ) ) {
				$choices[ $program->ID ] = $program->post_title;
			}
		}
		return $choices;
	}

	/**
	 * The programs a person may change, for the admin list. A coordinator may change every one.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, \WP_Post>
	 */
	public static function changeable( int $user_id ): array {
		return array_values( array_filter( self::all(), static fn( $program ) => self::can_change( $program->ID, $user_id ) ) );
	}

	/**
	 * Open or change a program.
	 *
	 * @param int                  $id      Program ID, or 0 for a new one.
	 * @param array<string, mixed> $input   owner_kind and owner (new only), title, description, goal, start, end, orgs, subteams, sponsors, public, state.
	 * @param int                  $user_id The person saving.
	 * @return int|\WP_Error Program ID.
	 */
	public static function save( int $id, array $input, int $user_id ) {
		$existing = $id > 0 ? self::get( $id ) : null;
		if ( $id > 0 && ! $existing ) {
			return new \WP_Error( 'packout_not_found', __( 'That program does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$owner = $existing
			? self::owner( $id )
			: array(
				'kind' => 'partner' === ( $input['owner_kind'] ?? '' ) ? 'partner' : 'team',
				'id'   => (int) ( $input['owner'] ?? 0 ),
			);
		if ( $existing ? ! self::can_change( $id, $user_id ) : ! self::can_open( $owner['kind'], $owner['id'], $user_id ) ) {
			$partner = 'partner' === $owner['kind'] ? Partners::get( $owner['id'] ) : null;
			return new \WP_Error(
				'packout_forbidden',
				$partner && ! Partners::allows( $owner['id'], 'program' ) && Partners::can_run( $owner['id'], $user_id )
					? __( 'A partner opens programs once a coordinator has approved it, and while it is active.', 'packout-core' )
					: __( 'A program is opened and changed by the leads of the partner or the organization that owns it.', 'packout-core' ),
				array( 'status' => 403 )
			);
		}
		$title = sanitize_text_field( (string) ( $input['title'] ?? ( $existing ? $existing->post_title : '' ) ) );
		if ( '' === $title || mb_strlen( $title ) > 120 ) {
			return new \WP_Error( 'packout_program_title', __( 'Give the program a name of up to 120 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$goal = array_key_exists( 'goal', $input ) ? sanitize_text_field( (string) $input['goal'] ) : ( $existing ? self::field( $id, 'goal' ) : '' );
		if ( mb_strlen( $goal ) > Program::MAX_GOAL ) {
			/* translators: %d: number of characters. */
			return new \WP_Error( 'packout_program_goal', sprintf( __( 'Say the goal in one sentence of up to %d characters.', 'packout-core' ), Program::MAX_GOAL ), array( 'status' => 400 ) );
		}
		$start = Schema::day( $input['start'] ?? ( $existing ? self::field( $id, 'start' ) : '' ) );
		$end   = Schema::day( $input['end'] ?? ( $existing ? self::field( $id, 'end' ) : '' ) );
		if ( '' !== $end && ( '' === $start || $end < $start ) ) {
			return new \WP_Error( 'packout_program_window', __( 'A program that has a last day has a first day, and does not end before it starts.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$window = Program::window( $start, $end );

		$public = array_key_exists( 'public', $input ) ? ! empty( $input['public'] ) : ( $existing ? 'publish' === $existing->post_status : false );
		$post   = array(
			'post_type'    => Schema::PROGRAM,
			'post_status'  => $public ? 'publish' : 'private',
			'post_title'   => wp_slash( $title ),
			'post_content' => wp_slash( sanitize_textarea_field( (string) ( $input['description'] ?? ( $existing ? $existing->post_content : '' ) ) ) ),
		);
		if ( $existing ) {
			$post['ID'] = $id;
			$saved      = wp_update_post( $post, true );
		} else {
			$post['post_author'] = $user_id;
			$saved               = wp_insert_post( $post, true );
		}
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$saved = (int) $saved;

		if ( ! $existing ) {
			update_post_meta( $saved, 'partner' === $owner['kind'] ? '_pos_partner' : '_pos_team', $owner['id'] );
		}
		foreach ( array(
			'goal'  => $goal,
			'start' => $window['start'],
			'end'   => $window['end'],
		) as $key => $value ) {
			'' === $value ? delete_post_meta( $saved, '_pos_' . $key ) : update_post_meta( $saved, '_pos_' . $key, wp_slash( $value ) );
		}
		if ( 'partner' === $owner['kind'] && array_key_exists( 'orgs', $input ) ) {
			$named = Program::taking_part( $owner, array_map( 'intval', (array) $input['orgs'] ), Partners::organization_ids( $owner['id'] ) );
			$gone  = array_diff( self::taking_part( $saved ), $named );
			delete_post_meta( $saved, '_pos_orgs' );
			foreach ( $named as $team ) {
				add_post_meta( $saved, '_pos_orgs', $team );
			}
			// What an organization filed stays with it and leaves the program it no longer takes part in.
			foreach ( $gone as $team ) {
				self::unfile_team( $saved, (int) $team );
			}
		}
		if ( array_key_exists( 'subteams', $input ) ) {
			$taking = self::taking_part( $saved );
			$kept   = array();
			foreach ( (array) $input['subteams'] as $raw ) {
				$parts = explode( ':', (string) $raw );
				$team  = (int) ( $parts[0] ?? 0 );
				$sub   = (int) ( $parts[1] ?? 0 );
				if ( in_array( $team, $taking, true ) && Subteams::get( $team, $sub ) ) {
					$kept[] = $team . ':' . $sub;
				}
			}
			Schema::write_list( $saved, 'subteams', array_values( array_unique( $kept ) ) );
		}
		if ( array_key_exists( 'sponsors', $input ) ) {
			Schema::write_list( $saved, 'sponsors', wp_list_pluck( Sponsors::named( $owner['id'], (array) $input['sponsors'] ), 'id' ) );
		}
		if ( array_key_exists( 'state', $input ) ) {
			'closed' === Program::state( $input['state'] ) ? update_post_meta( $saved, '_pos_state', 'closed' ) : delete_post_meta( $saved, '_pos_state' );
		}

		if ( ! $existing ) {
			/**
			 * A lead opened a program.
			 *
			 * @param int                          $saved Program ID.
			 * @param array{kind: string, id: int} $owner Its owner: `partner` or `team`, and the ID.
			 */
			do_action( 'packout_program_opened', $saved, $owner );
		}
		return $saved;
	}

	/**
	 * Upload, replace, or remove a program's cover image.
	 *
	 * @param int                       $id      Program ID.
	 * @param array<string, mixed>|null $file    One entry from the request files, or null to remove the cover.
	 * @param int                       $user_id The person changing it.
	 * @return int|\WP_Error Attachment ID, or 0 when the cover was removed.
	 */
	public static function set_cover( int $id, ?array $file, int $user_id ) {
		if ( ! self::can_change( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The program\'s owner changes its cover image.', 'packout-core' ), array( 'status' => 403 ) );
		}
		return Page_Header::set_cover( $id, $file );
	}

	/**
	 * A partner takes over a program of one of its organizations. The partner is then its owner.
	 *
	 * @param int $id      Program ID.
	 * @param int $user_id The partner's lead or co-lead, or a coordinator.
	 * @return true|\WP_Error
	 */
	public static function take_over( int $id, int $user_id ) {
		$owner   = self::owner( $id );
		$partner = 'team' === $owner['kind'] ? Partners::of_team( $owner['id'] ) : 0;
		if ( ! self::get( $id ) || $partner <= 0 ) {
			return new \WP_Error( 'packout_not_found', __( 'That is not a program of an organization under a partner.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! Partners::allows( $partner, 'program' ) || ! Partners::can_run( $partner, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The leads of an active partner take over a program of its organizations.', 'packout-core' ), array( 'status' => 403 ) );
		}
		update_post_meta( $id, '_pos_partner', $partner );
		delete_post_meta( $id, '_pos_orgs' );
		add_post_meta( $id, '_pos_orgs', $owner['id'] );
		// Its sponsors were the organization's. The partner names its own.
		delete_post_meta( $id, '_pos_sponsors' );
		return true;
	}

	/**
	 * How many campaigns and missions are filed under a program.
	 *
	 * @param int $id Program ID.
	 * @return array{campaigns: int, missions: int}
	 */
	public static function filed( int $id ): array {
		return array(
			'campaigns' => count( self::campaigns( $id ) ),
			'missions'  => count( self::missions( $id ) ),
		);
	}

	/**
	 * Remove a program. Its campaigns and missions stay where they are, in no program.
	 *
	 * @param int $id      Program ID.
	 * @param int $user_id The person removing it.
	 * @return true|\WP_Error
	 */
	public static function remove( int $id, int $user_id ) {
		if ( ! self::can_change( $id, $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The program\'s owner removes it.', 'packout-core' ), array( 'status' => 403 ) );
		}
		foreach ( self::stored_on( $id, Schema::CAMPAIGN ) as $post ) {
			delete_post_meta( $post, Program::FILED_META );
		}
		foreach ( self::stored_on( $id, Schema::MISSION ) as $post ) {
			delete_post_meta( $post, Program::FILED_META );
		}
		wp_trash_post( $id );
		return true;
	}

	/**
	 * IDs of the campaigns or the missions that name a program in their own field.
	 *
	 * @param int    $id   Program ID.
	 * @param string $type Post type.
	 * @return array<int, int>
	 */
	private static function stored_on( int $id, string $type ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'   => $type,
					'post_status' => array( 'publish', 'private' ),
					'numberposts' => 500,
					'fields'      => 'ids',
					'orderby'     => 'ID',
					'order'       => 'ASC',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- What is filed under one program.
					'meta_key'    => Program::FILED_META,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- What is filed under one program.
					'meta_value'  => $id,
				)
			)
		);
	}

	/**
	 * The campaigns filed under a program, newest first.
	 *
	 * @param int  $id          Program ID.
	 * @param bool $public_only Only the ones a visitor may read.
	 * @return array<int, \WP_Post>
	 */
	public static function campaigns( int $id, bool $public_only = false ): array {
		$taking = self::taking_part( $id );
		$found  = array();
		foreach ( array_reverse( self::stored_on( $id, Schema::CAMPAIGN ) ) as $campaign ) {
			$post = Campaigns::get( $campaign );
			if ( $post && in_array( Campaigns::team( $campaign ), $taking, true ) && ( ! $public_only || Post_Access::can_read( $post, 0 ) ) ) {
				$found[] = $post;
			}
		}
		return $found;
	}

	/**
	 * The missions of a program: those of its campaigns, and those filed under it on their own. By start day.
	 *
	 * @param int  $id          Program ID.
	 * @param bool $public_only Only the ones a visitor may read.
	 * @return array<int, \WP_Post>
	 */
	public static function missions( int $id, bool $public_only = false ): array {
		$taking = self::taking_part( $id );
		$found  = array();
		foreach ( self::campaigns( $id ) as $campaign ) {
			foreach ( Campaigns::missions( $campaign->ID, $public_only ) as $mission ) {
				// A joint campaign holds other organizations' missions too. The program counts the work of the ones that take part in it.
				if ( in_array( Missions::team( $mission->ID ), $taking, true ) ) {
					$found[ $mission->ID ] = $mission;
				}
			}
		}
		foreach ( self::stored_on( $id, Schema::MISSION ) as $mission ) {
			$post = Missions::get( $mission );
			// A mission in a campaign follows the campaign, whatever its own field says.
			if ( $post && self::of_mission( $mission ) === $id && in_array( Missions::team( $mission ), $taking, true ) && ( ! $public_only || Post_Access::can_read( $post, 0 ) ) ) {
				$found[ $mission ] = $post;
			}
		}
		$found = array_values( $found );
		usort( $found, static fn( $a, $b ) => strcmp( Missions::field( $a->ID, 'start', '9999' ), Missions::field( $b->ID, 'start', '9999' ) ) );
		return $found;
	}

	/**
	 * The program a campaign is filed under, or 0.
	 *
	 * @param int $campaign Campaign ID.
	 */
	public static function of_campaign( int $campaign ): int {
		$id = (int) get_post_meta( $campaign, Program::FILED_META, true );
		return $id > 0 && self::get( $id ) && in_array( Campaigns::team( $campaign ), self::taking_part( $id ), true ) ? $id : 0;
	}

	/**
	 * The program a mission has, or 0. A mission in a campaign has that campaign's.
	 *
	 * @param int $mission Mission ID.
	 */
	public static function of_mission( int $mission ): int {
		$campaign = Missions::campaign( $mission );
		$campaign = $campaign > 0 && Campaigns::get( $campaign ) ? $campaign : 0;
		$own      = (int) get_post_meta( $mission, Program::FILED_META, true );
		$own      = $own > 0 && self::get( $own ) && in_array( Missions::team( $mission ), self::taking_part( $own ), true ) ? $own : 0;
		$filed    = $campaign > 0 ? self::of_campaign( $campaign ) : 0;
		// A joint campaign's program is its host's. Another organization's mission in it has that program only when the organization takes part in it.
		if ( $filed > 0 && ! in_array( Missions::team( $mission ), self::taking_part( $filed ), true ) ) {
			$filed = 0;
		}
		return Program::of_mission( $own, $campaign, $filed );
	}

	/**
	 * Why an organization's campaign or mission cannot be filed under a program, or null when it can.
	 *
	 * @param int $id   Program ID.
	 * @param int $team The organization the campaign or mission belongs to.
	 */
	public static function refusal( int $id, int $team ): ?\WP_Error {
		if ( ! self::get( $id ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That program does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$why = Program::refusal( self::state( $id ), self::taking_part( $id ), $team );
		if ( 'outside' === $why ) {
			return new \WP_Error( 'packout_program_outside', __( 'That organization does not take part in the program. Its owner chooses who takes part.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( 'closed' === $why ) {
			return new \WP_Error( 'packout_program_closed', __( 'That program is closed. It takes no new campaigns or missions.', 'packout-core' ), array( 'status' => 409 ) );
		}
		return null;
	}

	/**
	 * Check the program a campaign or mission form asked for, before anything is saved.
	 *
	 * Keeping the program it already has is always allowed, so that a closed
	 * program does not stop its campaigns from being edited.
	 *
	 * @param int $program The program asked for, or 0 for none.
	 * @param int $team    The organization the campaign or mission belongs to.
	 * @param int $current The program it is stored under now.
	 */
	public static function check( int $program, int $team, int $current = 0 ): ?\WP_Error {
		return $program <= 0 || $program === $current ? null : self::refusal( $program, $team );
	}

	/**
	 * File a campaign or a mission under a program, or take it out.
	 *
	 * The person who decides its campaign and whether it is public decides
	 * this: the organization's leads, and the member who planned it. The
	 * program's owner may take out what was filed.
	 *
	 * @param int $post_id A campaign or a mission.
	 * @param int $program Program ID, or 0 to take it out.
	 * @param int $user_id The person filing it.
	 * @return true|\WP_Error
	 */
	public static function file( int $post_id, int $program, int $user_id ) {
		$mission  = Missions::get( $post_id );
		$campaign = $mission ? null : Campaigns::get( $post_id );
		if ( ! $mission && ! $campaign ) {
			return new \WP_Error( 'packout_not_found', __( 'That campaign or mission does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$team    = $mission ? Missions::team( $post_id ) : Campaigns::team( $post_id );
		$current = (int) get_post_meta( $post_id, Program::FILED_META, true );
		$may     = $mission ? Missions::can_assign( $post_id, $user_id ) : Campaigns::can_change( $post_id, $user_id );
		if ( ! $may && ! ( 0 === $program && $current > 0 && self::can_change( $current, $user_id ) ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The person who plans it files it under a program.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( $mission && ! Program::offers_field( Missions::campaign( $post_id ) > 0 && Campaigns::get( Missions::campaign( $post_id ) ) ? Missions::campaign( $post_id ) : 0 ) ) {
			return new \WP_Error( 'packout_program_campaign', __( 'This mission is in a campaign, and has that campaign\'s program. Change the campaign instead.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$problem = self::check( $program, $team, $current );
		if ( $problem ) {
			return $problem;
		}
		$program > 0 ? update_post_meta( $post_id, Program::FILED_META, $program ) : delete_post_meta( $post_id, Program::FILED_META );
		return true;
	}

	/**
	 * Take an organization's campaigns and missions out of a program.
	 *
	 * @param int $id   Program ID.
	 * @param int $team Organization ID.
	 */
	private static function unfile_team( int $id, int $team ): void {
		foreach ( self::stored_on( $id, Schema::CAMPAIGN ) as $campaign ) {
			if ( Campaigns::team( $campaign ) === $team ) {
				delete_post_meta( $campaign, Program::FILED_META );
			}
		}
		foreach ( self::stored_on( $id, Schema::MISSION ) as $mission ) {
			if ( Missions::team( $mission ) === $team ) {
				delete_post_meta( $mission, Program::FILED_META );
			}
		}
	}

	/**
	 * When an organization leaves a partner, its work leaves that partner's programs and stays as it is otherwise.
	 *
	 * @param mixed $team    Organization ID.
	 * @param mixed $partner Partner ID.
	 * @param mixed $change  `joined` or `left`.
	 */
	public static function organization_moved( $team, $partner, $change ): void {
		if ( 'left' !== $change ) {
			return;
		}
		foreach ( self::of_partner( (int) $partner ) as $program ) {
			// A program the partner took over from this organization stays the partner's, without the organization.
			self::unfile_team( $program->ID, (int) $team );
			delete_post_meta( $program->ID, '_pos_orgs', (int) $team );
		}
	}

	/**
	 * Where a program stands, counted from what is filed under it.
	 *
	 * @param int      $id          Program ID.
	 * @param bool     $public_only Count only what a visitor may read.
	 * @param int|null $team        Count only this organization's, or null for all.
	 * @return array{campaigns: int, campaigns_open: int, campaigns_finished: int, missions: int, planned: int, in_progress: int, completed: int, spots: int, cleaned: int}
	 */
	public static function progress( int $id, bool $public_only = false, ?int $team = null ): array {
		$today     = gmdate( 'Y-m-d' );
		$campaigns = self::campaigns( $id, $public_only );
		$missions  = self::missions( $id, $public_only );
		if ( null !== $team ) {
			$campaigns = array_filter( $campaigns, static fn( $campaign ) => Campaigns::team( $campaign->ID ) === $team );
			$missions  = array_filter( $missions, static fn( $mission ) => Missions::team( $mission->ID ) === $team );
		}
		$finished = 0;
		foreach ( $campaigns as $campaign ) {
			$end       = Campaigns::field( $campaign->ID, 'end' );
			$finished += '' !== $end && $end < $today ? 1 : 0;
		}
		$count = array(
			'planned'     => 0,
			'in_progress' => 0,
			'completed'   => 0,
		);
		$spots = array();
		foreach ( $missions as $mission ) {
			$status = Missions::status( $mission->ID );
			if ( isset( $count[ $status ] ) ) {
				++$count[ $status ];
			}
			if ( 'called_off' !== $status ) {
				$spots = array_merge( $spots, Missions::spots( $mission->ID ) );
			}
		}
		$spots = array_unique( $spots );
		return array(
			'campaigns'          => count( $campaigns ),
			'campaigns_open'     => count( $campaigns ) - $finished,
			'campaigns_finished' => $finished,
			'missions'           => count( $missions ),
			'planned'            => $count['planned'],
			'in_progress'        => $count['in_progress'],
			'completed'          => $count['completed'],
			'spots'              => count( $spots ),
			'cleaned'            => count( array_filter( $spots, static fn( $spot ) => 'cleaned' === Spots::field( (int) $spot, 'status', 'spotted' ) ) ),
		);
	}

	/**
	 * Progress in one line.
	 *
	 * @param array<string, int> $progress From progress().
	 */
	public static function progress_line( array $progress ): string {
		return implode(
			' · ',
			array(
				/* translators: %d: number of campaigns. */
				sprintf( _n( '%d campaign', '%d campaigns', $progress['campaigns'], 'packout-core' ), $progress['campaigns'] ),
				/* translators: %d: number of missions. */
				sprintf( _n( '%d mission', '%d missions', $progress['missions'], 'packout-core' ), $progress['missions'] ),
				/* translators: %d: number of missions. */
				sprintf( __( '%d completed', 'packout-core' ), $progress['completed'] ),
				/* translators: 1: Spots cleaned, 2: Spots in the program. */
				sprintf( __( '%1$d of %2$d Spots cleaned', 'packout-core' ), $progress['cleaned'], $progress['spots'] ),
			)
		);
	}

	/**
	 * Impact totals of a program: the sum of the records of its missions.
	 *
	 * @param int                  $id   Program ID.
	 * @param array<string, mixed> $args visible, from, to, team (one organization taking part).
	 * @return array<string, array<string, int|float>>
	 */
	public static function totals( int $id, array $args = array() ): array {
		if ( Remote::is( $id ) ) {
			return Remote::reported( $id );
		}
		$missions = self::missions( $id );
		if ( ! empty( $args['team'] ) ) {
			$team     = (int) $args['team'];
			$missions = array_filter( $missions, static fn( $mission ) => Missions::team( $mission->ID ) === $team );
		}
		return Impact::totals(
			array(
				'missions' => array_values( wp_list_pluck( $missions, 'ID' ) ),
				'visible'  => ! empty( $args['visible'] ),
			) + array_intersect_key( $args, array_flip( array( 'from', 'to' ) ) )
		);
	}

	/**
	 * The same figures for each organization taking part, in alphabetical order.
	 *
	 * @param int $id Program ID.
	 * @return array<int, array{team: int, name: string, progress: array<string, int>, totals: array<string, array<string, int|float>>}>
	 */
	public static function by_organization( int $id ): array {
		$rows = array();
		foreach ( self::taking_part( $id ) as $team ) {
			$post = Teams::get( $team );
			if ( $post ) {
				$rows[] = array(
					'team'     => $team,
					'name'     => $post->post_title,
					'progress' => self::progress( $id, false, $team ),
					'totals'   => self::totals( $id, array( 'team' => $team ) ),
				);
			}
		}
		usort( $rows, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
		return $rows;
	}

	/**
	 * The line that says whose program it is.
	 *
	 * @param int $id Program ID.
	 */
	public static function owner_line( int $id ): string {
		$owner = self::owner_post( $id );
		/* translators: %s: the partner or organization that owns the program. */
		return $owner ? sprintf( __( 'A program of %s', 'packout-core' ), $owner->post_title ) : '';
	}

	/**
	 * What the public may know about a program.
	 *
	 * @param \WP_Post $program Program.
	 * @return array<string, mixed>
	 */
	public static function public_payload( \WP_Post $program ): array {
		$id    = $program->ID;
		$owner = self::owner_post( $id );
		return array(
			'id'        => $id,
			'title'     => $program->post_title,
			'url'       => (string) get_permalink( $program ),
			'goal'      => self::field( $id, 'goal' ),
			'state'     => self::state( $id ),
			'window'    => self::window_line( $id ),
			'owner'     => $owner ? $owner->post_title : '',
			'owner_url' => $owner && self::owner_shown( $id ) ? (string) get_permalink( $owner ) : '',
			'cover'     => Page_Header::cover( $id ),
			'sponsors'  => self::sponsors( $id ),
			'progress'  => self::progress( $id, true ),
		);
	}

	/**
	 * A program's window in words. A program with a first day and no last day says so.
	 *
	 * @param int $id Program ID.
	 */
	public static function window_line( int $id ): string {
		$start = self::field( $id, 'start' );
		$end   = self::field( $id, 'end' );
		if ( '' !== $start && '' === $end ) {
			/* translators: %s: a date. */
			return sprintf( __( 'Since %s', 'packout-core' ), Schema::span( $start, '' ) );
		}
		return Schema::span( $start, $end );
	}
}
