<?php
/**
 * Remote records: the hub's copies of what a partner site published.
 *
 * A remote record is a post of the ordinary type, an organization, a
 * program, a campaign, or a mission, with `_pos_origin` set to `remote`. It
 * is filed under the partner it came from and links to its page on the
 * partner's site. Pages, cards, lists, and the calendar show it through the
 * code that shows any other record.
 *
 * Nobody on this site changes one. The record of truth is on the partner's
 * site. Three things hold that:
 *
 * - `is()` is the one test, and every permission to change a record answers
 *   no for a remote one, so no form or button offers the change.
 * - `guard()` refuses by name where a method takes a record and no person.
 * - The seal refuses, underneath all of it, any write to a remote record's
 *   row or its fields that does not come through `write()`. Only the partner
 *   interface calls `write()`.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

defined( 'ABSPATH' ) || exit;

/**
 * The hub's read-only copies of a partner site's records.
 */
final class Remote {

	/**
	 * The field that marks a remote record. A record made on this site has no such field.
	 */
	public const ORIGIN = '_pos_origin';

	/**
	 * The partner a remote record came from: a `pos_partner` ID.
	 */
	public const PARTNER = '_pos_partner';

	/**
	 * The partner site's identifier for the record.
	 */
	public const ID = '_pos_remote_id';

	/**
	 * The partner site's revision of the record.
	 */
	public const REV = '_pos_remote_rev';

	/**
	 * The record's page on the partner's site.
	 */
	public const URL = '_pos_remote_url';

	/**
	 * Set when a coordinator here hid the record.
	 */
	public const HIDDEN = '_pos_remote_hidden';

	/**
	 * On an organization of this site whose work moved to a partner's site: the listing it moved to.
	 */
	public const MOVED = '_pos_moved_to';

	/**
	 * The day it moved, as `Y-m-d`. What it did before that day is counted here, and what it does from that day is counted on its site.
	 */
	public const MOVED_DAY = '_pos_moved_day';

	/**
	 * The day the move was undone, as `Y-m-d`. The days from the move to this one stay the partner's.
	 */
	public const MOVED_UNTIL = '_pos_moved_until';

	/**
	 * How many writes through `write()` are under way.
	 *
	 * @var int
	 */
	private static int $writing = 0;

	/**
	 * The record types a listing can be.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		return array( Schema::TEAM, Schema::PROGRAM, Schema::CAMPAIGN, Schema::MISSION );
	}

	/**
	 * Every record type that can be a copy of a partner's record: the listings, and a partner's Spot.
	 *
	 * @return array<int, string>
	 */
	public static function sealed(): array {
		return array_merge( self::types(), array( Schema::PARTNER_SPOT ) );
	}

	/**
	 * Put the seal on. Only the hub holds remote records.
	 */
	public static function register(): void {
		if ( ! App::is_hub() ) {
			return;
		}
		add_filter( 'add_post_metadata', array( __CLASS__, 'seal_field' ), 10, 2 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'seal_field' ), 10, 2 );
		add_filter( 'delete_post_metadata', array( __CLASS__, 'seal_field' ), 10, 2 );
		add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'seal_row' ), 10, 2 );
		add_filter( 'pre_trash_post', array( __CLASS__, 'seal_removal' ), 10, 2 );
		add_filter( 'pre_untrash_post', array( __CLASS__, 'seal_removal' ), 10, 2 );
		add_filter( 'pre_delete_post', array( __CLASS__, 'seal_removal' ), 10, 2 );
	}

	/**
	 * Whether a record is a copy of one kept on a partner's site.
	 *
	 * @param int|\WP_Post|null $post A record or its ID.
	 */
	public static function is( $post ): bool {
		$id = $post instanceof \WP_Post ? $post->ID : (int) $post;
		return $id > 0 && 'remote' === get_post_meta( $id, self::ORIGIN, true ) && in_array( get_post_type( $id ), self::sealed(), true );
	}

	/**
	 * Refuse to change a remote record.
	 *
	 * @param int|\WP_Post|null $post A record or its ID.
	 * @return \WP_Error|null The refusal, or null for a record of this site.
	 */
	public static function guard( $post ): ?\WP_Error {
		if ( ! self::is( $post ) ) {
			return null;
		}
		return new \WP_Error(
			'packout_remote',
			/* translators: %s: the name of a partner. */
			sprintf( __( 'This is kept on the site of %s and is changed there.', 'packout-core' ), self::partner_name( $post instanceof \WP_Post ? $post->ID : (int) $post ) ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The listing an organization of this site moved to, when a partner's site keeps its work now.
	 *
	 * Such an organization keeps its history and its people here, and plans
	 * and records nothing new here: that is done on its own site.
	 *
	 * @param int $team An organization.
	 * @return int The listing's ID, or 0.
	 */
	public static function moved( int $team ): int {
		if ( ! App::is_hub() || $team <= 0 ) {
			return 0;
		}
		$listing = (int) get_post_meta( $team, self::MOVED, true );
		return $listing > 0 && self::is( $listing ) ? $listing : 0;
	}

	/**
	 * Whether a day of an organization of this site is the partner's to count: it is on or after the day the organization's work moved, and before the day that was undone.
	 *
	 * @param int    $team An organization.
	 * @param string $day  A day, as `Y-m-d`.
	 */
	public static function gone( int $team, string $day ): bool {
		if ( ! App::is_hub() || $team <= 0 || '' === $day ) {
			return false;
		}
		$from = (string) get_post_meta( $team, self::MOVED_DAY, true );
		if ( '' === $from || $day < $from ) {
			return false;
		}
		$until = (string) get_post_meta( $team, self::MOVED_UNTIL, true );
		return '' === $until || $day < $until;
	}

	/**
	 * The day an organization's work moved to its own site, in words, or an empty string.
	 *
	 * @param int $team An organization.
	 */
	public static function moved_day( int $team ): string {
		$day = (string) get_post_meta( $team, self::MOVED_DAY, true );
		return self::moved( $team ) > 0 && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ? (string) wp_date( (string) get_option( 'date_format' ), (int) strtotime( $day . ' 12:00:00' ) ) : '';
	}

	/**
	 * What a page says about an organization whose work moved, or an empty string.
	 *
	 * @param int $team An organization.
	 */
	public static function moved_words( int $team ): string {
		$listing = self::moved( $team );
		if ( 0 === $listing ) {
			return '';
		}
		$day = self::moved_day( $team );
		return '' !== $day
			/* translators: 1: the name of a partner, 2: a date. */
			? sprintf( __( 'This organization has kept its work on the site of %1$s since %2$s. What it did before then is counted here, and nothing new is planned or recorded here.', 'packout-core' ), self::partner_name( $listing ), $day )
			/* translators: %s: the name of a partner. */
			: sprintf( __( 'This organization keeps its work on the site of %s now. What it did before is counted here, and nothing new is planned or recorded here.', 'packout-core' ), self::partner_name( $listing ) );
	}

	/**
	 * Run what writes a remote record. Only the partner interface calls this.
	 *
	 * @param callable $work What to run.
	 * @return mixed What it returned.
	 */
	public static function write( callable $work ) {
		++self::$writing;
		try {
			return $work();
		} finally {
			--self::$writing;
		}
	}

	/**
	 * Whether a write through `write()` is under way.
	 */
	public static function writing(): bool {
		return self::$writing > 0;
	}

	/**
	 * Refuse to add, change, or delete a field of a remote record.
	 *
	 * @param mixed $check     Null to go ahead.
	 * @param int   $object_id The record.
	 * @return mixed False to refuse.
	 */
	public static function seal_field( $check, $object_id ) {
		return ! self::writing() && self::is( (int) $object_id ) ? false : $check;
	}

	/**
	 * Refuse to change the row of a remote record.
	 *
	 * @param mixed                $refuse Whether the save is already refused.
	 * @param array<string, mixed> $post   What is being saved.
	 * @return mixed True to refuse.
	 */
	public static function seal_row( $refuse, $post ) {
		return ! self::writing() && ! empty( $post['ID'] ) && self::is( (int) $post['ID'] ) ? true : $refuse;
	}

	/**
	 * Refuse to trash, restore, or delete a remote record.
	 *
	 * @param mixed    $check Null to go ahead.
	 * @param \WP_Post $post  The record.
	 * @return mixed False to refuse.
	 */
	public static function seal_removal( $check, $post ) {
		return ! self::writing() && $post instanceof \WP_Post && self::is( $post ) ? false : $check;
	}

	/**
	 * The partner a remote record came from.
	 *
	 * @param int $id The record.
	 * @return int A partner ID, or 0.
	 */
	public static function partner( int $id ): int {
		return self::is( $id ) ? (int) get_post_meta( $id, self::PARTNER, true ) : 0;
	}

	/**
	 * The name of the partner a remote record came from.
	 *
	 * @param int $id The record.
	 */
	public static function partner_name( int $id ): string {
		$owner   = self::partner( $id );
		$partner = $owner > 0 ? get_post( $owner ) : null;
		return $partner instanceof \WP_Post && '' !== $partner->post_title ? $partner->post_title : __( 'a partner', 'packout-core' );
	}

	/**
	 * A remote record's page on the partner's site.
	 *
	 * @param int $id The record.
	 */
	public static function url( int $id ): string {
		return self::is( $id ) ? esc_url_raw( (string) get_post_meta( $id, self::URL, true ), array( 'https', 'http' ) ) : '';
	}

	/**
	 * Whether a coordinator here hid a remote record.
	 *
	 * @param int $id The record.
	 */
	public static function hidden( int $id ): bool {
		return '1' === get_post_meta( $id, self::HIDDEN, true );
	}

	/**
	 * A query's arguments with remote records left out.
	 *
	 * @param array<string, mixed> $query Arguments for `get_posts()`.
	 * @return array<string, mixed>
	 */
	public static function without( array $query ): array {
		if ( ! App::is_hub() ) {
			return $query;
		}
		$not = array(
			'key'     => self::ORIGIN,
			'compare' => 'NOT EXISTS',
		);
		if ( isset( $query['meta_key'] ) ) {
			// A query that names one field keeps it, as one clause beside this one.
			$clause = array( 'key' => $query['meta_key'] );
			if ( array_key_exists( 'meta_value', $query ) ) {
				$clause['value'] = $query['meta_value'];
			}
			if ( isset( $query['meta_compare'] ) ) {
				$clause['compare'] = $query['meta_compare'];
			}
			unset( $query['meta_key'], $query['meta_value'], $query['meta_compare'] );
			$query['meta_query'] = array_merge( array( 'relation' => 'AND' ), array( $clause ), isset( $query['meta_query'] ) ? array( $query['meta_query'] ) : array(), array( $not ) );
			return $query;
		}
		$query['meta_query'] = isset( $query['meta_query'] ) ? array(
			'relation' => 'AND',
			$query['meta_query'],
			$not,
		) : array( $not );
		return $query;
	}

	/**
	 * A condition for a hand-written query, which leaves remote records out.
	 *
	 * @param string $alias The alias of the posts table in the query.
	 * @return string SQL that begins with ` AND`. Empty on a partner site, which holds no remote record.
	 */
	public static function sql( string $alias = 'p' ): string {
		global $wpdb;
		if ( ! App::is_hub() || 1 !== preg_match( '/^[a-z_]+$/', $alias ) ) {
			return '';
		}
		return " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} remote_of_{$alias} WHERE remote_of_{$alias}.post_id = {$alias}.ID AND remote_of_{$alias}.meta_key = '" . self::ORIGIN . "' )";
	}

	/**
	 * Records with the remote ones left out.
	 *
	 * @param array<int, \WP_Post|int> $posts Records or IDs.
	 * @return array<int, \WP_Post|int>
	 */
	public static function only_local( array $posts ): array {
		if ( ! App::is_hub() ) {
			return $posts;
		}
		return array_values( array_filter( $posts, static fn( $post ): bool => ! self::is( $post ) ) );
	}

	/**
	 * What a remote record's page says in place of a join button or a sign-up form.
	 *
	 * @param int    $id    The record.
	 * @param string $title The heading.
	 * @param string $lead  The sentence before the link.
	 */
	public static function sign_up( int $id, string $title, string $lead ): string {
		$url  = self::url( $id );
		$name = self::partner_name( $id );
		$html = '<section class="pos-place__section pos-signup pos-remote" id="pos-join"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $lead ) . '</p>';
		if ( '' !== $url ) {
			/* translators: %s: the name of a partner. */
			$html .= '<p><a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( $url ) . '" rel="noopener">' . esc_html( sprintf( __( 'Sign up on the site of %s', 'packout-core' ), $name ) ) . '</a></p>';
		}
		return $html . '</section>';
	}

	/**
	 * A fact for a remote record's page: whose site it is on, linked.
	 *
	 * @param int $id The record.
	 * @return string Escaped HTML, or empty for a record of this site.
	 */
	public static function fact( int $id ): string {
		if ( ! self::is( $id ) ) {
			return '';
		}
		$url  = self::url( $id );
		$host = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '';
		if ( '' === $host ) {
			return '';
		}
		/* translators: %s: a web address, linked. */
		$fact = sprintf( esc_html__( 'On %s', 'packout-core' ), '<a href="' . esc_url( $url ) . '" rel="noopener">' . esc_html( $host ) . '</a>' );
		/**
		 * Filters the fact a remote record's page gives about where it is kept. The hub adds when the partner's site was last heard from.
		 *
		 * @param string $fact Escaped HTML.
		 * @param int    $id   The record.
		 */
		return (string) apply_filters( 'packout_remote_fact', $fact, $id );
	}

	/**
	 * Whether an overview is of a partner's site, or of an organization kept on one.
	 *
	 * @param array<string, mixed> $scope The overview's scope.
	 * @return array{kind: string, id: int}|null The scope when it is, and null when the overview is counted here.
	 */
	public static function reported_scope( array $scope ): ?array {
		if ( ! App::is_hub() ) {
			return null;
		}
		$scope = Overview_Scope::scope( $scope );
		if ( ! $scope ) {
			return null;
		}
		$remote = 'partner' === $scope['kind'] ? self::is_site( $scope['id'] ) : self::is( $scope['id'] );
		return $remote ? $scope : null;
	}

	/**
	 * The figures a partner's site last sent for an overview.
	 *
	 * @param array{kind: string, id: int} $scope The scope.
	 * @return array{now: array<string, mixed>, periods: array<string, mixed>, at: string}
	 */
	private static function overview_figures( array $scope ): array {
		/**
		 * The overview figures a partner's site sent for a partner or an organization.
		 *
		 * @param array|null $figures `now`, `periods` keyed by days, and `at`, the time they arrived.
		 * @param array      $scope   `kind` and `id`.
		 */
		$figures = apply_filters( 'packout_remote_overview', null, $scope );
		$figures = is_array( $figures ) ? $figures : array();
		return array(
			'now'     => is_array( $figures['now'] ?? null ) ? $figures['now'] : array(),
			'periods' => is_array( $figures['periods'] ?? null ) ? $figures['periods'] : array(),
			'at'      => (string) ( $figures['at'] ?? '' ),
		);
	}

	/**
	 * The current half of an overview, as the partner's site reported it.
	 *
	 * @param array<string, mixed> $scope The overview's scope.
	 * @return array<string, mixed>|null Null when the overview is counted here.
	 */
	public static function overview_now( array $scope ): ?array {
		$scope = self::reported_scope( $scope );
		if ( ! $scope ) {
			return null;
		}
		$figures = self::overview_figures( $scope );
		return Overview_Scope::restore_now( $scope, $figures['now'], $figures['at'] );
	}

	/**
	 * The period half of an overview, as the partner's site reported it.
	 *
	 * @param int                  $days  The period.
	 * @param array<string, mixed> $scope The overview's scope.
	 * @return array<string, mixed>|null Null when the overview is counted here.
	 */
	public static function overview_period( int $days, array $scope ): ?array {
		$scope = self::reported_scope( $scope );
		if ( ! $scope ) {
			return null;
		}
		$figures = self::overview_figures( $scope );
		$period  = $figures['periods'][ (string) $days ] ?? array();
		return Overview_Scope::restore_period( $scope, $days, is_array( $period ) ? $period : array(), $figures['at'] );
	}

	/**
	 * A fact for the page of a partner that is a partner site: where it keeps its records, linked.
	 *
	 * @param int $partner A partner ID.
	 * @return string Escaped HTML, or empty for a partner kept on this site.
	 */
	public static function site_fact( int $partner ): string {
		if ( ! self::is_site( $partner ) ) {
			return '';
		}
		$url  = esc_url_raw( (string) get_post_meta( $partner, self::URL, true ), array( 'https', 'http' ) );
		$host = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '';
		if ( '' === $host ) {
			return '';
		}
		/* translators: %s: a web address, linked. */
		$fact = sprintf( esc_html__( 'Keeps its records on %s', 'packout-core' ), '<a href="' . esc_url( $url ) . '" rel="noopener">' . esc_html( $host ) . '</a>' );
		/**
		 * Filters the fact a partner's page gives about where it keeps its records. The hub adds when the partner's site was last heard from.
		 *
		 * @param string $fact    Escaped HTML.
		 * @param int    $partner The partner.
		 */
		return (string) apply_filters( 'packout_remote_site_fact', $fact, $partner );
	}

	/**
	 * The totals a partner site reported for a remote record or a remote partner.
	 *
	 * The hub's plugin keeps the snapshots and answers through the filter.
	 *
	 * @param int    $id   A remote record, or a partner that is a partner site.
	 * @param string $team For a team of a remote organization, the site's identifier of the team.
	 * @return array<string, mixed>|null The shape of `Impact::totals()` with `reported` added, the day they are of. Null when there are none.
	 */
	public static function totals( int $id, string $team = '' ): ?array {
		/**
		 * The totals a partner site reported for a record.
		 *
		 * @param array<string, mixed>|null $totals None.
		 * @param int                       $id     The record.
		 * @param string                    $team   For a team of a remote organization, the site's identifier of the team.
		 */
		$totals = apply_filters( 'packout_remote_totals', null, $id, $team );
		return is_array( $totals ) ? $totals : null;
	}

	/**
	 * Totals with nothing in them, in the shape of `Impact::totals()`.
	 *
	 * @return array<string, array<string, int|float>>
	 */
	public static function no_totals(): array {
		return array(
			'records'  => array(
				'cleanups' => 0,
				'verified' => 0,
			),
			'effort'   => array(
				'hours_verified'   => 0.0,
				'hours_unverified' => 0.0,
				'people'           => 0,
				'missions'         => 0,
				'teams'            => 0,
			),
			'outputs'  => array(
				'spots'                => 0,
				'bags_measured'        => 0,
				'bags_self_reported'   => 0,
				'pounds_measured'      => 0.0,
				'pounds_self_reported' => 0.0,
			),
			'outcomes' => array(
				'still_clear' => 0,
				'returned'    => 0,
			),
		);
	}

	/**
	 * What a partner site reported for a record, or totals with nothing in them.
	 *
	 * @param int    $id   A remote record, or a partner that is a partner site.
	 * @param string $team For a team of a remote organization, the site's identifier of the team.
	 * @return array<string, mixed>
	 */
	public static function reported( int $id, string $team = '' ): array {
		$totals = self::totals( $id, $team );
		return null === $totals ? self::no_totals() : $totals;
	}

	/**
	 * What every active partner site reported, together: the second line of the site's totals.
	 *
	 * @return array<string, mixed>|null The shape of `Impact::totals()` with `partners` added, the number of sites. Null when no site has reported.
	 */
	public static function sites_totals(): ?array {
		/**
		 * The totals of every active partner site, together.
		 *
		 * @param array<string, mixed>|null $totals None.
		 */
		$totals = apply_filters( 'packout_partner_sites_totals', null );
		return is_array( $totals ) && ! empty( $totals['partners'] ) ? $totals : null;
	}

	/**
	 * Whether a partner is a partner site.
	 *
	 * @param int $partner A partner ID.
	 */
	public static function is_site( int $partner ): bool {
		return $partner > 0 && Schema::PARTNER === get_post_type( $partner ) && 'remote' === Partners::origin( $partner );
	}

	/**
	 * The remote records of a partner site.
	 *
	 * @param int                $partner A partner ID.
	 * @param array<int, string> $types   Record types. All four when empty.
	 * @return array<int, int> Record IDs, of any status.
	 */
	public static function of_partner( int $partner, array $types = array() ): array {
		if ( $partner <= 0 ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => $types ? $types : self::types(),
					'post_status'      => array( 'publish', 'private' ),
					'numberposts'      => 2000,
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => true,
					'meta_query'       => array(
						'relation' => 'AND',
						array(
							'key'   => self::ORIGIN,
							'value' => 'remote',
						),
						array(
							'key'   => self::PARTNER,
							'value' => $partner,
						),
					),
				)
			)
		);
	}
}
