<?php
/**
 * The calendar: what is on it, the route, the files, and the event data.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

use PackOut\Core\Calendar as Rules;
use PackOut\Core\Ical;
use PackOut\Core\Schedule as Times;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Missions as events, and campaigns and programs as spans.
 *
 * A calendar keeps no records of its own. events() is the one query behind
 * the block, the route, and the calendar file, and it answers for the person
 * asking: a visitor is shown what the public pages show and nothing more.
 *
 * | Route                         | Purpose                                          |
 * |-------------------------------|--------------------------------------------------|
 * | GET /calendar                 | The events of a range of days, for the viewer    |
 * | GET /calendar.ics             | The public events, as a calendar file            |
 * | GET /mission/<mission>/calendar.ics | One public mission, as a calendar file     |
 */
class Calendar {

	/**
	 * The option that counts changes. A visitor's stored answer is kept under the number it was made at.
	 */
	public const VERSION_OPTION = 'packout_calendar_version';

	/**
	 * The query variable that asks for a mission's calendar file.
	 */
	public const QUERY_VAR = 'packout_calendar_file';

	/**
	 * How long a visitor's answer is kept, in seconds.
	 */
	public const KEEP = 300;

	/**
	 * How far back, and how far ahead, the calendar file reaches, in days.
	 */
	public const FILE_REACH = array( 30, 365 );

	/**
	 * Whether a change was already counted in this request.
	 *
	 * @var bool
	 */
	private static $counted = false;

	/**
	 * Register the routes, the mission file, the event data, and what clears a stored answer.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'init', array( self::class, 'rewrite' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'mission_file' ), 1 );
		add_action( 'wp_head', array( self::class, 'event_data' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_file' ), 10, 4 );

		foreach ( array( 'packout_mission_opened', 'packout_mission_completed', 'packout_campaign_opened' ) as $hook ) {
			add_action( $hook, array( self::class, 'changed' ), 10, 0 );
		}
		foreach ( array( 'save_post', 'trashed_post', 'untrashed_post', 'deleted_post' ) as $hook ) {
			add_action( $hook, array( self::class, 'post_changed' ) );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'meta_changed' ), 10, 2 );
		}
	}

	/* ---------- What clears a stored answer ---------- */

	/**
	 * Count a change, which abandons every stored answer at once.
	 *
	 * Counted now, and once more when the request ends, so an answer another
	 * request stored in between does not outlive the change. Until then this
	 * request keeps no answer and reads none.
	 */
	public static function changed(): void {
		if ( self::$counted ) {
			return;
		}
		self::$counted = true;
		update_option( self::VERSION_OPTION, self::version() + 1, true );
		add_action( 'shutdown', array( self::class, 'settle' ), 10, 0 );
	}

	/**
	 * The request that changed something is over.
	 */
	public static function settle(): void {
		if ( self::$counted ) {
			self::$counted = false;
			update_option( self::VERSION_OPTION, self::version() + 1, true );
		}
	}

	/**
	 * A record was saved, trashed, restored, or deleted.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public static function post_changed( $post_id ): void {
		if ( ! self::$counted && in_array( get_post_type( (int) $post_id ), array( Schema::MISSION, Schema::CAMPAIGN, Schema::PROGRAM, Schema::TEAM, Schema::PARTNER ), true ) ) {
			self::changed();
		}
	}

	/**
	 * A field of a record changed.
	 *
	 * @param mixed $meta_id Meta ID.
	 * @param mixed $post_id Post ID.
	 */
	public static function meta_changed( $meta_id, $post_id ): void {
		self::post_changed( $post_id );
	}

	/**
	 * How many changes have been counted.
	 */
	public static function version(): int {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/* ---------- What is on the calendar ---------- */

	/**
	 * What a request may limit a calendar to, made safe.
	 *
	 * @param array<string, mixed> $args `from`, `to`, `partner`, `organization`, `team`, `program`, `campaign`, `category`, `show`,
	 *                                   and `only`: a list of missions to show and nothing else, which is how a Place's page
	 *                                   asks for the missions at that Place.
	 * @return array{from: string, to: string, partner: int, organization: int, team: int, program: int, campaign: int, category: string, show: array<int, string>, only: array<int, int>}
	 */
	public static function args( array $args ): array {
		$category = sanitize_key( (string) ( $args['category'] ?? '' ) );
		$only     = array_values( array_unique( array_filter( array_map( 'intval', is_array( $args['only'] ?? null ) ? $args['only'] : array() ) ) ) );
		sort( $only );
		return array(
			'from'         => Times::day( $args['from'] ?? '' ),
			'to'           => Times::day( $args['to'] ?? '' ),
			'partner'      => max( 0, (int) ( $args['partner'] ?? 0 ) ),
			'organization' => max( 0, (int) ( $args['organization'] ?? 0 ) ),
			'team'         => max( 0, (int) ( $args['team'] ?? 0 ) ),
			'program'      => max( 0, (int) ( $args['program'] ?? 0 ) ),
			'campaign'     => max( 0, (int) ( $args['campaign'] ?? 0 ) ),
			'category'     => isset( Schema::categories()[ $category ] ) ? $category : '',
			'show'         => Rules::kinds( $args['show'] ?? '' ),
			'only'         => array_slice( $only, 0, 200 ),
		);
	}

	/**
	 * The events of a range of days, for one person, sorted by start.
	 *
	 * A visitor is shown public missions of listed organizations, public
	 * campaigns, and public programs. A signed-in person is also shown the
	 * private missions and campaigns of the organizations they belong to, as
	 * the workspace would show them. A coordinator is shown everything.
	 *
	 * A visitor's answer is kept for five minutes. A signed-in person's is not kept.
	 *
	 * @param array<string, mixed> $args From args(), or what it takes.
	 * @param int                  $user The person asking, or 0 for a visitor.
	 * @return array<int, array<string, mixed>> Each event has the fields the route returns, and `start_at` and `end_at`
	 *                                          as Unix times, `first_day`, `last_day`, `open_end`, `status_label`,
	 *                                          `organization_id`, `landmark`, `private`, `modified`, and `cleaned`.
	 */
	public static function events( array $args, int $user = 0 ): array {
		$args = self::args( $args );
		if ( '' === $args['from'] || '' === $args['to'] || $args['to'] < $args['from'] ) {
			return array();
		}
		$key  = 'packout_cal_' . md5( (string) wp_json_encode( $args ) . '|' . self::version() );
		$keep = 0 === $user && ! self::$counted;
		if ( $keep ) {
			$kept = get_transient( $key );
			if ( is_array( $kept ) ) {
				return $kept;
			}
		}

		$all   = $user > 0 && Capabilities::can( Capabilities::MODERATE, $user );
		$teams = self::organizations( $args );
		$found = array();
		if ( null === $teams || $teams ) {
			if ( in_array( 'missions', $args['show'], true ) ) {
				$found = array_merge( $found, self::missions( $args, $teams, $user, $all ) );
			}
			// A span has no category of its own, so a calendar limited to one shows missions alone.
			if ( '' === $args['category'] && ! $args['only'] && in_array( 'campaigns', $args['show'], true ) ) {
				$found = array_merge( $found, self::campaigns( $args, $teams, $user, $all ) );
			}
		}
		if ( '' === $args['category'] && ! $args['only'] && 0 === $args['campaign'] && in_array( 'programs', $args['show'], true ) ) {
			$found = array_merge( $found, self::programs( $args, $user, $all ) );
		}
		$found = Rules::sorted( array_map( static fn( array $event ): array => $event + array( 'start' => $event['start_at'] ), $found ) );
		$found = array_map(
			static function ( array $event ): array {
				$zone           = new \DateTimeZone( $event['zone'] );
				$event['start'] = ( new \DateTimeImmutable( '@' . $event['start_at'] ) )->setTimezone( $zone )->format( 'c' );
				$event['end']   = ( new \DateTimeImmutable( '@' . $event['end_at'] ) )->setTimezone( $zone )->format( 'c' );
				return $event;
			},
			$found
		);

		if ( $keep ) {
			set_transient( $key, $found, self::KEEP );
		}
		return $found;
	}

	/**
	 * The organizations a calendar is limited to, or null when it is limited to none.
	 *
	 * @param array<string, mixed> $args From args().
	 * @return array<int, int>|null An empty list means the limit leaves nothing.
	 */
	private static function organizations( array $args ): ?array {
		$teams = null;
		if ( $args['organization'] > 0 ) {
			$teams = array( $args['organization'] );
		}
		if ( $args['partner'] > 0 ) {
			$under = Partners::organization_ids( $args['partner'] );
			$teams = null === $teams ? $under : array_values( array_intersect( $teams, $under ) );
		}
		if ( $args['program'] > 0 ) {
			$taking = Programs::get( $args['program'] ) ? Programs::taking_part( $args['program'] ) : array();
			$teams  = null === $teams ? $taking : array_values( array_intersect( $teams, $taking ) );
		}
		return null === $teams ? null : array_map( 'intval', $teams );
	}

	/**
	 * The records of one type whose window touches a range of days.
	 *
	 * Two comparisons on the stored days find them. A record with only one
	 * of its two days is that day. Each of the three cases is asked for on
	 * its own: asked for together, WordPress joins the meta table once per
	 * clause with no key to narrow it, and a mission with thirty Spots then
	 * costs the database millions of rows.
	 *
	 * @param string               $type  Post type.
	 * @param array<string, mixed> $args  From args().
	 * @param array<int, int>|null $teams The organizations to limit to, or null.
	 * @param array<int, mixed>    $more  Further meta clauses, all of which must hold.
	 * @return array<int, \WP_Post>
	 */
	private static function in_range( string $type, array $args, ?array $teams, array $more = array() ): array {
		$days    = array( $args['from'], $args['to'] );
		$windows = array(
			array(
				array(
					'key'     => '_pos_start',
					'value'   => $args['to'],
					'compare' => '<=',
				),
				array(
					'key'     => '_pos_end',
					'value'   => $args['from'],
					'compare' => '>=',
				),
			),
			array(
				array(
					'key'     => '_pos_start',
					'value'   => $days,
					'compare' => 'BETWEEN',
				),
				array(
					'key'     => '_pos_end',
					'compare' => 'NOT EXISTS',
				),
			),
			array(
				array(
					'key'     => '_pos_end',
					'value'   => $days,
					'compare' => 'BETWEEN',
				),
				array(
					'key'     => '_pos_start',
					'compare' => 'NOT EXISTS',
				),
			),
		);
		if ( null !== $teams ) {
			$more[] = array(
				'key'     => '_pos_team',
				'value'   => array_map( 'strval', $teams ),
				'compare' => 'IN',
			);
		}
		$query = array(
			'post_type'        => $type,
			'post_status'      => array( 'publish', 'private' ),
			'numberposts'      => 500,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
		);
		$found = array();
		foreach ( $windows as $window ) {
			$ids = get_posts(
				$query + array(
					'fields'     => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The records whose window touches a range of days.
					'meta_query' => array_merge( array( 'relation' => 'AND' ), $window, array_values( $more ) ),
				)
			);
			foreach ( $ids as $id ) {
				$found[ (int) $id ] = true;
			}
		}
		if ( ! $found ) {
			return array();
		}
		ksort( $found );
		return get_posts( $query + array( 'post__in' => array_slice( array_keys( $found ), 0, 500 ) ) );
	}

	/**
	 * Whether an organization is listed, so the public may be told its name.
	 *
	 * @param int $team Organization ID.
	 */
	private static function listed( int $team ): bool {
		$post = Teams::get( $team );
		return $post && 'publish' === $post->post_status && Post_Access::can_read( $post, 0 );
	}

	/**
	 * The fields every event has.
	 *
	 * @param \WP_Post             $post     The record.
	 * @param string               $kind     `mission`, `campaign`, or `program`.
	 * @param array<string, mixed> $schedule From Schedule::of().
	 * @param int                  $team     The organization the record belongs to, or 0.
	 * @param bool                 $named    Whether the viewer may be told the organization's name.
	 * @return array<string, mixed>
	 */
	private static function event( \WP_Post $post, string $kind, array $schedule, int $team, bool $named ): array {
		$partner = $team > 0 ? Partners::of_team( $team ) : 0;
		return array(
			'id'              => $post->ID,
			'kind'            => $kind,
			'title'           => $post->post_title,
			'url'             => (string) get_permalink( $post ),
			'start_at'        => $schedule['start']->getTimestamp(),
			'end_at'          => $schedule['end']->getTimestamp(),
			'all_day'         => $schedule['all_day'],
			'open_end'        => $schedule['open_end'],
			'zone'            => $schedule['zone'],
			'first_day'       => $schedule['first_day'],
			'last_day'        => $schedule['last_day'],
			'status'          => '',
			'status_label'    => '',
			'organization'    => $team > 0 && $named ? (string) get_the_title( $team ) : '',
			'organization_id' => $team > 0 && $named ? $team : 0,
			'partner'         => $partner > 0 && Partners::shown( $partner ) ? (string) get_the_title( $partner ) : '',
			'spots'           => 0,
			'remote'          => false,
			'landmark'        => '',
			'private'         => 'publish' !== $post->post_status,
			'modified'        => (int) get_post_modified_time( 'U', true, $post ),
			'cleaned'         => 0,
		);
	}

	/**
	 * Missions, as events.
	 *
	 * @param array<string, mixed> $args  From args().
	 * @param array<int, int>|null $teams The organizations to limit to, or null.
	 * @param int                  $user  The person asking.
	 * @param bool                 $all   The person is a coordinator.
	 * @return array<int, array<string, mixed>>
	 */
	private static function missions( array $args, ?array $teams, int $user, bool $all ): array {
		$more = array();
		if ( $args['campaign'] > 0 ) {
			$more[] = array(
				'key'   => '_pos_campaign',
				'value' => (string) $args['campaign'],
			);
		}
		// The Spots of one category, found once.
		$in_category = null;
		if ( '' !== $args['category'] ) {
			$term        = get_term_by( 'slug', $args['category'], Schema::CATEGORY );
			$in_category = $term ? array_map( 'intval', (array) get_objects_in_term( $term->term_id, Schema::CATEGORY ) ) : array();
		}

		$events = array();
		foreach ( self::in_range( Schema::MISSION, $args, $teams, $more ) as $post ) {
			$id       = $post->ID;
			$team     = Missions::team( $id );
			$schedule = Schedule::of( $post );
			if ( ! $schedule || ! Rules::overlaps( $schedule['first_day'], $schedule['last_day'], $args['from'], $args['to'] ) ) {
				continue;
			}
			if ( $args['only'] && ! in_array( $id, $args['only'], true ) ) {
				continue;
			}
			$public = 'publish' === $post->post_status && Post_Access::can_read( $post, 0 ) && self::listed( $team );
			$works  = $user > 0 && Missions::can_work( $id, $user );
			if ( ! $all && ! $public && ! $works ) {
				continue;
			}
			if ( $args['team'] > 0 && ! in_array( $args['team'], Missions::subteams( $id ), true ) ) {
				continue;
			}
			if ( $args['program'] > 0 && Programs::of_mission( $id ) !== $args['program'] ) {
				continue;
			}
			if ( null !== $in_category && ! array_intersect( Missions::spots( $id ), $in_category ) ) {
				continue;
			}
			$event = self::mission( $post, $schedule, $all || $works || self::listed( $team ) );
			// A member opens the mission where they work on it. Anyone else opens its public page.
			if ( $works || ( $all && ! $public ) ) {
				$event['url'] = Workspace::url( $team, 'mission', (string) $id );
			}
			$events[] = $event;
		}//end foreach
		return $events;
	}

	/**
	 * One mission as an event.
	 *
	 * What its public page shows and nothing more: the title, the
	 * organization, the time, how many Spots, and the meeting place when the
	 * lead marked it shareable. No pin and no name.
	 *
	 * @param \WP_Post             $post     The mission.
	 * @param array<string, mixed> $schedule From Schedule::of().
	 * @param bool                 $named    Whether the viewer may be told the organization's name.
	 * @return array<string, mixed>
	 */
	private static function mission( \WP_Post $post, array $schedule, bool $named ): array {
		$id     = $post->ID;
		$spots  = Missions::spots( $id );
		$status = Missions::status( $id );
		$event  = self::event( $post, 'mission', $schedule, Missions::team( $id ), $named );

		$event['status']       = $status;
		$event['status_label'] = Missions::statuses()[ $status ] ?? $status;
		$event['spots']        = count( $spots );
		$event['landmark']     = '1' === Missions::field( $id, 'meeting_share' ) ? Missions::field( $id, 'meeting_note' ) : '';
		if ( 'completed' === $status ) {
			$event['cleaned'] = count( array_filter( $spots, static fn( $spot ) => 'cleaned' === Spots::field( (int) $spot, 'status' ) && (int) Spots::field( (int) $spot, 'cleaned_mission', '0' ) === $id ) );
		}
		return $event;
	}

	/**
	 * Campaigns, as spans.
	 *
	 * @param array<string, mixed> $args  From args().
	 * @param array<int, int>|null $teams The organizations to limit to, or null.
	 * @param int                  $user  The person asking.
	 * @param bool                 $all   The person is a coordinator.
	 * @return array<int, array<string, mixed>>
	 */
	private static function campaigns( array $args, ?array $teams, int $user, bool $all ): array {
		$events = array();
		$found  = self::in_range( Schema::CAMPAIGN, $args, $teams );
		if ( null !== $teams ) {
			// A joint campaign is on the calendar of every organization that takes part in it.
			$seen = wp_list_pluck( $found, 'ID' );
			foreach ( $teams as $one ) {
				foreach ( Joint::of_team( (int) $one ) as $post ) {
					if ( ! in_array( $post->ID, $seen, true ) ) {
						$found[] = $post;
						$seen[]  = $post->ID;
					}
				}
			}
		}
		foreach ( $found as $post ) {
			$id       = $post->ID;
			$team     = Campaigns::team( $id );
			$copy     = Joint::is_copy( $id );
			$schedule = Schedule::of( $post );
			if ( ! $schedule || ! Rules::overlaps( $schedule['first_day'], $schedule['last_day'], $args['from'], $args['to'] ) ) {
				continue;
			}
			if ( $args['campaign'] > 0 && $args['campaign'] !== $id ) {
				continue;
			}
			// A partner site's copy of a joint campaign belongs to no organization here. It is public, as the campaign is on the hub.
			$public = 'publish' === $post->post_status && Post_Access::can_read( $post, 0 ) && ( $copy || self::listed( $team ) );
			$member = $user > 0 && ( Teams::includes( $team, $user ) || Teams::can_run( $team, $user ) );
			if ( ! $all && ! $public && ! $member ) {
				continue;
			}
			if ( $args['team'] > 0 && ! in_array( $args['team'], Campaigns::subteams( $id ), true ) ) {
				continue;
			}
			if ( $args['program'] > 0 && Programs::of_campaign( $id ) !== $args['program'] ) {
				continue;
			}
			$event = self::event( $post, 'campaign', $schedule, $team, $all || $member || self::listed( $team ) );
			if ( ! $public ) {
				$event['url'] = Workspace::url( $team, 'campaign', (string) $id );
			}
			if ( $copy ) {
				$event['organization'] = Joint::host( $id );
			}
			$events[] = $event;
		}//end foreach
		return $events;
	}

	/**
	 * Programs that have a window, as spans.
	 *
	 * @param array<string, mixed> $args From args().
	 * @param int                  $user The person asking.
	 * @param bool                 $all  The person is a coordinator.
	 * @return array<int, array<string, mixed>>
	 */
	private static function programs( array $args, int $user, bool $all ): array {
		$events = array();
		foreach ( self::in_range( Schema::PROGRAM, $args, null ) as $post ) {
			$id = $post->ID;
			// A program has days and no time.
			$schedule = Times::of(
				array(
					'start' => Programs::field( $id, 'start' ),
					'end'   => Programs::field( $id, 'end' ),
				),
				Schedule::site_zone()
			);
			if ( ! $schedule || ! Rules::overlaps( $schedule['first_day'], $schedule['last_day'], $args['from'], $args['to'] ) ) {
				continue;
			}
			if ( $args['program'] > 0 && $args['program'] !== $id ) {
				continue;
			}
			$shown = Programs::shown( $id );
			if ( ! $all && ! $shown && ! ( $user > 0 && Programs::can_see( $id, $user ) ) ) {
				continue;
			}
			$owner  = Programs::owner( $id );
			$taking = Programs::taking_part( $id );
			if ( $args['partner'] > 0 && ! ( 'partner' === $owner['kind'] && $owner['id'] === $args['partner'] ) ) {
				continue;
			}
			if ( $args['organization'] > 0 && ! in_array( $args['organization'], $taking, true ) ) {
				continue;
			}
			if ( $args['team'] > 0 && ! in_array( $args['organization'] . ':' . $args['team'], Programs::subteams( $id ), true ) ) {
				continue;
			}
			$team  = 'team' === $owner['kind'] ? $owner['id'] : 0;
			$event = self::event( $post, 'program', $schedule, $team, $team > 0 && ( $all || self::listed( $team ) ) );
			if ( 'partner' === $owner['kind'] && ( $all || Partners::shown( $owner['id'] ) ) ) {
				$event['partner'] = (string) get_the_title( $owner['id'] );
			}
			$event['status'] = Programs::state( $id );
			$events[]        = $event;
		}//end foreach
		return $events;
	}

	/**
	 * An event as the route returns it.
	 *
	 * @param array<string, mixed> $event From events().
	 * @return array<string, mixed>
	 */
	public static function public_event( array $event ): array {
		return array_intersect_key( $event, array_flip( array( 'id', 'kind', 'title', 'url', 'start', 'end', 'all_day', 'zone', 'status', 'organization', 'partner', 'spots', 'remote' ) ) );
	}

	/* ---------- The route ---------- */

	/**
	 * Route table.
	 */
	public static function routes(): void {
		$filters = array();
		foreach ( array( 'partner', 'organization', 'team', 'program', 'campaign' ) as $name ) {
			$filters[ $name ] = array(
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			);
		}
		$filters['category'] = array(
			'type'    => 'string',
			'default' => '',
		);
		$filters['show']     = array(
			'type'    => 'string',
			'default' => '',
		);

		register_rest_route(
			Rest::NAMESPACE,
			'/calendar',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'answer' ),
				'permission_callback' => '__return_true',
				'args'                => $filters + array(
					'from'       => array( 'type' => 'string' ),
					'to'         => array( 'type' => 'string' ),
					'view'       => array( 'type' => 'string' ),
					'date'       => array( 'type' => 'string' ),
					'count'      => array( 'type' => 'integer' ),
					'week_start' => array( 'type' => 'string' ),
					'switcher'   => array( 'type' => 'boolean' ),
					'feed'       => array( 'type' => 'boolean' ),
					'html'       => array( 'type' => 'boolean' ),
					'base'       => array( 'type' => 'string' ),
					'fixed'      => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			Rest::NAMESPACE,
			'/calendar\.ics',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'file' ),
				'permission_callback' => '__return_true',
				'args'                => $filters,
			)
		);
	}

	/**
	 * GET /calendar. The events of a range of days, for the person asking.
	 *
	 * The range is `from` and `to`, at most 62 days apart, or a `view` and a
	 * `date`, which name the range that form covers. With `html`, the answer
	 * also carries the calendar drawn for that range, which is what the block
	 * puts in place of what it showed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function answer( \WP_REST_Request $request ) {
		$user  = get_current_user_id();
		$args  = self::args( $request->get_params() );
		$view  = (string) $request->get_param( 'view' );
		$drawn = in_array( $view, Rules::SWITCHABLE, true );
		$date  = Times::day( $request->get_param( 'date' ) );
		$date  = '' !== $date ? $date : self::today();
		$start = Rules::week_start( (string) $request->get_param( 'week_start' ), (int) get_option( 'start_of_week', 0 ) );

		if ( $drawn ) {
			$range = Rules::range( $view, $date, $start );
		} else {
			$range   = array(
				'from' => $request->get_param( 'from' ),
				'to'   => $request->get_param( 'to' ),
			);
			$refusal = Rules::refusal( $range['from'], $range['to'] );
			if ( '' !== $refusal ) {
				$messages = array(
					'invalid' => __( 'Give the first and the last day as dates, such as 2026-03-14.', 'packout-core' ),
					'order'   => __( 'The last day cannot be before the first.', 'packout-core' ),
					/* translators: %d: number of days. */
					'long'    => sprintf( __( 'Ask for at most %d days at a time.', 'packout-core' ), Rules::MAX_DAYS ),
				);
				return new \WP_Error( 'packout_calendar_range', $messages[ $refusal ], array( 'status' => 400 ) );
			}
		}
		$args['from'] = $range['from'];
		$args['to']   = $range['to'];

		$events = self::events( $args, $user );
		$body   = array(
			'from'   => $args['from'],
			'to'     => $args['to'],
			'events' => array_map( array( self::class, 'public_event' ), $events ),
		);
		if ( $drawn && $request->get_param( 'html' ) ) {
			$body['html'] = Calendar_View::region(
				array(
					'view'      => $view,
					'date'      => $date,
					'count'     => Rules::count( $request->get_param( 'count' ) ),
					'weekStart' => (string) $request->get_param( 'week_start' ),
					'switcher'  => (bool) $request->get_param( 'switcher' ),
					'feed'      => (bool) $request->get_param( 'feed' ),
					'base'      => Accounts::local( esc_url_raw( (string) $request->get_param( 'base' ) ) ),
					'fixed'     => (string) $request->get_param( 'fixed' ),
				) + $args,
				$user
			);
		}

		$response = rest_ensure_response( $body );
		// The answer is for the person asking. A visitor's may be kept for five minutes. A signed-in person's is not kept.
		$response->header( 'Cache-Control', $user > 0 ? 'private, no-store' : 'public, max-age=' . self::KEEP );
		return $response;
	}

	/**
	 * Today, on the site's clock.
	 */
	public static function today(): string {
		return (string) wp_date( 'Y-m-d' );
	}

	/* ---------- Calendar files ---------- */

	/**
	 * The address of the calendar file for a set of filters.
	 *
	 * @param array<string, mixed> $args   From args().
	 * @param string               $scheme `https` for the plain address, `webcal` for the one a calendar program subscribes to.
	 */
	public static function file_url( array $args, string $scheme = 'https' ): string {
		$query = array();
		foreach ( array( 'partner', 'organization', 'team', 'program', 'campaign' ) as $name ) {
			if ( ! empty( $args[ $name ] ) ) {
				$query[ $name ] = (int) $args[ $name ];
			}
		}
		if ( '' !== (string) ( $args['category'] ?? '' ) ) {
			$query['category'] = (string) $args['category'];
		}
		$show = Rules::kinds( $args['show'] ?? '' );
		if ( Rules::KINDS !== $show ) {
			$query['show'] = implode( ',', $show );
		}
		$url = add_query_arg( $query, rest_url( Rest::NAMESPACE . '/calendar.ics' ) );
		return 'webcal' === $scheme ? (string) preg_replace( '#^https?://#', 'webcal://', $url ) : $url;
	}

	/**
	 * The address of one mission's calendar file.
	 *
	 * @param int $id Mission ID.
	 */
	public static function mission_file_url( int $id ): string {
		return trailingslashit( (string) get_permalink( $id ) ) . 'calendar.ics';
	}

	/**
	 * Events as a calendar file hands them over.
	 *
	 * @param array<int, array<string, mixed>> $events From events(), for a visitor.
	 * @return array<int, array<string, mixed>>
	 */
	private static function for_file( array $events ): array {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$rows = array();
		foreach ( $events as $event ) {
			$zone        = new \DateTimeZone( $event['zone'] );
			$description = 'mission' === $event['kind'] ? Missions::field( (int) $event['id'], 'description' ) : (string) get_post_field( 'post_content', (int) $event['id'] );
			$rows[]      = array(
				'uid'         => $event['kind'] . '-' . $event['id'] . '@' . $host,
				'stamp'       => $event['modified'],
				'start'       => ( new \DateTimeImmutable( '@' . $event['start_at'] ) )->setTimezone( $zone ),
				'end'         => ( new \DateTimeImmutable( '@' . $event['end_at'] ) )->setTimezone( $zone ),
				'all_day'     => $event['all_day'],
				'zone'        => $event['zone'],
				'summary'     => $event['title'],
				'description' => wp_strip_all_tags( $description ),
				'url'         => $event['url'],
				'location'    => $event['landmark'],
				'cancelled'   => 'called_off' === $event['status'],
			);
		}
		return $rows;
	}

	/**
	 * A calendar file of events.
	 *
	 * @param array<int, array<string, mixed>> $events From events(), for a visitor.
	 * @param string                           $name   The calendar's name.
	 */
	public static function document( array $events, string $name ): string {
		return Ical::document(
			self::for_file( $events ),
			array(
				'product' => '-//Pack-Out Spot//Calendar ' . \PackOut\Core\Kernel::instance()->site()->version() . '//EN',
				'name'    => $name,
			)
		);
	}

	/**
	 * GET /calendar.ics. The public events from 30 days back to a year ahead.
	 *
	 * The file carries only what a visitor may see, whoever asks for it.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function file( \WP_REST_Request $request ): \WP_REST_Response {
		$args         = self::args( $request->get_params() );
		$today        = self::today();
		$args['from'] = Rules::shift( $today, -self::FILE_REACH[0] );
		$args['to']   = Rules::shift( $today, self::FILE_REACH[1] );
		$response     = new \WP_REST_Response( self::document( self::events( $args, 0 ), (string) get_bloginfo( 'name' ) ) );
		$response->header( 'Content-Type', Ical::TYPE );
		$response->header( 'Content-Disposition', 'inline; filename="calendar.ics"' );
		$response->header( 'Cache-Control', 'public, max-age=' . self::KEEP );
		return $response;
	}

	/**
	 * Send a calendar file as it is, not as JSON.
	 *
	 * @param mixed $served  Whether the request was already served.
	 * @param mixed $result  The response.
	 * @param mixed $request The request.
	 * @param mixed $server  The server.
	 * @return mixed
	 */
	public static function serve_file( $served, $result, $request, $server ) {
		if ( $served || ! $request instanceof \WP_REST_Request || '/' . Rest::NAMESPACE . '/calendar.ics' !== $request->get_route() || ! $result instanceof \WP_HTTP_Response || 200 !== $result->get_status() ) {
			return $served;
		}
		unset( $server );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A calendar file, written and escaped by PackOut\Core\Ical.
		echo $result->get_data();
		return true;
	}

	/**
	 * The address of a mission's calendar file: one more part after the address of its page.
	 */
	public static function rewrite(): void {
		$type = get_post_type_object( Schema::MISSION );
		$base = $type && is_array( $type->rewrite ) && ! empty( $type->rewrite['slug'] ) ? (string) $type->rewrite['slug'] : 'mission';
		add_rewrite_rule( '^' . preg_quote( $base, '#' ) . '/([^/]+)/calendar\.ics$', 'index.php?' . Schema::MISSION . '=$matches[1]&' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Let WordPress keep the request for a mission's calendar file.
	 *
	 * @param mixed $vars Query variables.
	 * @return mixed
	 */
	public static function query_vars( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[] = self::QUERY_VAR;
		}
		return $vars;
	}

	/**
	 * A mission as one event, when its page is public and it has a day.
	 *
	 * The page of a public mission is public whether or not its organization
	 * is listed, so its file and its event data are too. An organization that
	 * is not listed is not named, as on the page.
	 *
	 * @param int $id Mission ID.
	 * @return array<string, mixed>|null
	 */
	public static function public_mission( int $id ): ?array {
		$post     = Missions::get( $id );
		$schedule = $post ? Schedule::of( $post ) : null;
		if ( ! $post || ! $schedule || 'publish' !== $post->post_status || ! Post_Access::can_read( $post, 0 ) ) {
			return null;
		}
		return self::mission( $post, $schedule, self::listed( Missions::team( $id ) ) );
	}

	/**
	 * Answer a request for one mission's calendar file.
	 */
	public static function mission_file(): void {
		if ( '' === (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		$post  = is_singular( Schema::MISSION ) ? get_queried_object() : null;
		$event = $post instanceof \WP_Post ? self::public_mission( $post->ID ) : null;
		if ( ! $event ) {
			// The same answer whether there is no such mission, it is private, or it has no day.
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		status_header( 200 );
		header( 'Content-Type: ' . Ical::TYPE );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $post->post_name ) . '.ics"' );
		header( 'Cache-Control: public, max-age=' . self::KEEP );
		header( 'X-Robots-Tag: noindex' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A calendar file, written and escaped by PackOut\Core\Ical.
		echo self::document( array( $event ), $post->post_title );
		exit;
	}

	/* ---------- Event data ---------- */

	/**
	 * A public mission's page as `schema.org/Event`.
	 *
	 * The name, the start and the end, the status, and the organization as
	 * the organizer. The place is named only when the lead marked the meeting
	 * place shareable. There are never coordinates.
	 *
	 * @param int $id Mission ID.
	 * @return array<string, mixed>|null
	 */
	public static function event_schema( int $id ): ?array {
		$event = self::public_mission( $id );
		if ( ! $event ) {
			return null;
		}
		$zone   = new \DateTimeZone( $event['zone'] );
		$start  = ( new \DateTimeImmutable( '@' . $event['start_at'] ) )->setTimezone( $zone );
		$end    = ( new \DateTimeImmutable( '@' . $event['end_at'] ) )->setTimezone( $zone );
		$status = array(
			'called_off' => 'https://schema.org/EventCancelled',
		);
		$data   = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'name'                => $event['title'],
			'url'                 => $event['url'],
			// An all-day event is given as days. Its last day is the day before the moment it ends.
			'startDate'           => $event['all_day'] ? $event['first_day'] : $start->format( 'c' ),
			'endDate'             => $event['all_day'] ? $event['last_day'] : $end->format( 'c' ),
			'eventStatus'         => $status[ $event['status'] ] ?? 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
		);
		$about  = wp_strip_all_tags( Missions::field( $id, 'description' ) );
		if ( '' !== $about ) {
			$data['description'] = $about;
		}
		if ( $event['organization_id'] > 0 ) {
			$data['organizer'] = array(
				'@type' => 'Organization',
				'name'  => $event['organization'],
				'url'   => (string) get_permalink( $event['organization_id'] ),
			);
		}
		if ( '' !== $event['landmark'] ) {
			$data['location'] = array(
				'@type' => 'Place',
				'name'  => $event['landmark'],
			);
		}
		return $data;
	}

	/**
	 * Print the event data in the head of a public mission's page.
	 */
	public static function event_data(): void {
		if ( ! is_singular( Schema::MISSION ) ) {
			return;
		}
		$data = self::event_schema( (int) get_queried_object_id() );
		if ( $data ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with its tags and ampersands encoded.
		}
	}
}
