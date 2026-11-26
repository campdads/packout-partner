<?php
/**
 * The outbox of a partner site: what is waiting to be sent to packout.spot.
 *
 * Nothing is sent while a visitor waits. A change to a public record writes
 * one small row naming the record, and the row is sent afterwards, by
 * WP-Cron. What is sent is built from the record at that moment, so two
 * changes in a row send the record once, as it ended up.
 *
 * A send that fails is tried again later: after a minute, five minutes,
 * fifteen, an hour, and then every six hours. A row is never dropped
 * because the hub was unreachable. Once a night everything public is sent
 * again in full, which also tells the hub what is no longer here.
 *
 * The log keeps ninety days of what was sent: the action, how many items,
 * and how the hub answered. It never keeps a payload.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

use PackOut\Core\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * A partner site's queue of things to send, and the record of what it sent.
 */
final class Outbox {

	/**
	 * The WP-Cron hook that sends what is due.
	 */
	public const RUN = 'packout_outbox_run';

	/**
	 * The WP-Cron hook that sends once, soon after something was queued.
	 */
	public const SOON = 'packout_outbox_soon';

	/**
	 * The WP-Cron hook of the nightly full send.
	 */
	public const NIGHTLY = 'packout_outbox_nightly';

	/**
	 * How long the log is kept, in days.
	 */
	public const KEEP_DAYS = 90;

	/**
	 * The waits between tries, in seconds. The last one repeats.
	 */
	public const WAITS = array( 60, 300, 900, 3600, 21600 );

	/**
	 * The most rows one run sends.
	 */
	private const PER_RUN = 1000;

	/**
	 * How many images one run sends. The rest go with the next.
	 */
	private const IMAGES = 30;

	/**
	 * Records queued in this request, so each is queued and its revision raised once.
	 *
	 * @var array<string, bool>
	 */
	private static array $queued = array();

	/**
	 * Whether something was queued in this request.
	 *
	 * @var bool
	 */
	private static bool $dirty = false;

	/**
	 * Whether a Spot was queued in this request. A Spot goes at once.
	 *
	 * @var bool
	 */
	private static bool $urgent = false;

	/**
	 * Hook the queue. A partner site only.
	 */
	public static function register(): void {
		if ( App::is_hub() ) {
			return;
		}
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Five minutes is the interval the sync contract names.
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::RUN, array( __CLASS__, 'due' ) );
		add_action( self::SOON, array( __CLASS__, 'due' ) );
		add_action( self::NIGHTLY, array( __CLASS__, 'nightly' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
		add_action( 'packout_connection_active', array( __CLASS__, 'everything' ) );

		add_action( 'save_post', array( __CLASS__, 'saved' ), 20, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'moved' ), 20, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'removed' ), 20, 2 );
		foreach ( array( 'added', 'updated', 'deleted' ) as $did ) {
			add_action( $did . '_post_meta', array( __CLASS__, 'field_changed' ), 20, 3 );
		}
		add_action( 'packout_impact_recorded', array( __CLASS__, 'counted' ), 20, 0 );
		add_action( 'packout_subteam_youth', array( __CLASS__, 'youth_changed' ), 20, 2 );
		add_action( 'packout_connection_claims', array( __CLASS__, 'counted' ) );
		add_action( 'shutdown', array( __CLASS__, 'wake' ), 5 );
	}

	/**
	 * A team became a youth team, or stopped being one. The Spots of its missions are sent again, so the hub's copies lose or gain their photographs.
	 *
	 * @param int $team_id The organization.
	 * @param int $id      The team's number.
	 */
	public static function youth_changed( $team_id, $id ): void {
		if ( ! Connection::connected() ) {
			return;
		}
		foreach ( Subteams::missions( (int) $team_id, (int) $id ) as $mission ) {
			self::listing( 'mission', (string) $mission->ID );
			foreach ( Missions::spots( $mission->ID ) as $spot ) {
				self::spot( $spot );
			}
		}
	}

	/**
	 * Make the two tables.
	 */
	public static function install_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}pos_outbox (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(20) NOT NULL,
				subject varchar(60) NOT NULL,
				queued datetime NOT NULL,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_try bigint(20) unsigned NOT NULL DEFAULT 0,
				last_error varchar(190) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY subject (kind,subject),
				KEY next_try (next_try)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}pos_sync_log (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				at datetime NOT NULL,
				action varchar(30) NOT NULL,
				items int(10) unsigned NOT NULL DEFAULT 0,
				status smallint(5) unsigned NOT NULL DEFAULT 0,
				note varchar(190) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY at (at)
			) {$charset};"
		);
	}

	/**
	 * Remove the two tables. Called when the plugin's data is removed.
	 */
	public static function drop_tables(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own tables, named from the prefix.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pos_outbox" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pos_sync_log" );
		// phpcs:enable
	}

	/**
	 * The five-minute interval.
	 *
	 * @param array<string, array<string, mixed>> $schedules The intervals WordPress knows.
	 * @return array<string, array<string, mixed>>
	 */
	public static function schedules( $schedules ) {
		$schedules                         = is_array( $schedules ) ? $schedules : array();
		$schedules['packout_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every five minutes', 'packout-core' ),
		);
		return $schedules;
	}

	/**
	 * Keep the two timers going while the site is connected, and stop them when it is not.
	 */
	public static function schedule(): void {
		if ( ! Connection::connected() ) {
			if ( wp_next_scheduled( self::RUN ) || wp_next_scheduled( self::NIGHTLY ) ) {
				self::unschedule();
			}
			return;
		}
		if ( ! wp_next_scheduled( self::RUN ) ) {
			wp_schedule_event( time() + 300, 'packout_five_minutes', self::RUN );
		}
		if ( ! wp_next_scheduled( self::NIGHTLY ) ) {
			// A time in the small hours that differs from site to site, so the hub does not hear from every site at once.
			$offset = hexdec( substr( md5( home_url() ), 0, 4 ) ) % ( 4 * HOUR_IN_SECONDS );
			$first  = strtotime( 'tomorrow 01:00', time() ) + $offset;
			wp_schedule_event( $first ? $first : time() + DAY_IN_SECONDS, 'daily', self::NIGHTLY );
		}
	}

	/**
	 * Stop both timers. Called on disconnecting and when the plugin is deactivated.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::RUN );
		wp_clear_scheduled_hook( self::SOON );
		wp_clear_scheduled_hook( self::NIGHTLY );
	}

	/**
	 * Queue one thing to send.
	 *
	 * @param string $kind    `listing`, `profile`, `stats`, or `full`.
	 * @param string $subject Which one: `kind:id` for a listing, and the kind itself for the others.
	 * @param int    $wait    Seconds before the first try.
	 */
	public static function enqueue( string $kind, string $subject, int $wait = 0 ): void {
		global $wpdb;
		if ( App::is_hub() || ! Connection::connected() ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table; a later change resets the row it already has.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}pos_outbox (kind, subject, queued, attempts, next_try, last_error) VALUES (%s, %s, %s, 0, %d, '')
				ON DUPLICATE KEY UPDATE queued = VALUES(queued), attempts = 0, next_try = LEAST(next_try, VALUES(next_try)), last_error = ''",
				$kind,
				$subject,
				gmdate( 'Y-m-d H:i:s' ),
				time() + $wait
			)
		);
		self::$dirty = true;
	}

	/**
	 * Queue a listing, and raise the record's revision, once in a request.
	 *
	 * @param string $kind      The kind of listing.
	 * @param string $remote_id The record's identifier here.
	 */
	public static function listing( string $kind, string $remote_id ): void {
		$subject = $kind . ':' . $remote_id;
		if ( isset( self::$queued[ $subject ] ) || ! Connection::connected() ) {
			return;
		}
		self::$queued[ $subject ] = true;
		list( $id )               = Listings::parts( $remote_id );
		if ( 'team' !== $kind && get_post( $id ) ) {
			Listings::bump( $id );
		}
		self::enqueue( 'listing', $subject );
	}

	/**
	 * Queue a listing whether or not it was queued earlier in this request.
	 *
	 * A change that has to reach the hub, made after the record was already
	 * queued and perhaps sent in the same request, is queued again.
	 *
	 * @param string $kind      The kind of listing.
	 * @param string $remote_id The record's identifier here.
	 */
	public static function listing_again( string $kind, string $remote_id ): void {
		unset( self::$queued[ $kind . ':' . $remote_id ] );
		self::listing( $kind, $remote_id );
	}

	/**
	 * Queue a Spot, and raise its revision, once in a request. A Spot is sent at once.
	 *
	 * @param int $id The Spot.
	 */
	public static function spot( int $id ): void {
		$subject = 'spot:' . $id;
		if ( $id <= 0 || isset( self::$queued[ $subject ] ) || ! Connection::connected() ) {
			return;
		}
		self::$queued[ $subject ] = true;
		if ( get_post( $id ) ) {
			$state = Exchange::state( $id );
			if ( '' === $state ) {
				// A Spot copied from the hub is never sent. The hub is told how its adoption stands.
				Adoptions::queue( $id );
				return;
			}
			// Neither is one the hub does not hold and is not to be given.
			if ( ( 'removed' === $state && in_array( (string) get_post_meta( $id, Exchange::SENT, true ), array( '', 'removed' ), true ) ) ) {
				return;
			}
			Listings::bump( $id );
		}
		self::enqueue( 'spot', (string) $id );
		self::$urgent = true;
	}

	/**
	 * Queue everything: the profile, a full send of the listings, the totals, and a comparison of the Spots the hub holds with the ones it should.
	 */
	public static function everything(): void {
		self::enqueue( 'profile', 'profile' );
		self::enqueue( 'full', 'full' );
		self::enqueue( 'stats', 'stats' );
		self::enqueue( 'reconcile', 'spots' );
		self::schedule();
	}

	/**
	 * A record was saved.
	 *
	 * @param int      $id   The record.
	 * @param \WP_Post $post The record.
	 */
	public static function saved( $id, $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		self::changed( $post );
	}

	/**
	 * A record's status changed: it was published, withdrawn, or put in the trash.
	 *
	 * @param string   $new_status The status now.
	 * @param string   $old_status The status before.
	 * @param \WP_Post $post       The record.
	 */
	public static function moved( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || $new_status === $old_status ) {
			return;
		}
		self::changed( $post );
		// An organization that comes or goes takes its campaigns and missions with it. A full send says so in one pass.
		if ( Schema::TEAM === $post->post_type && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
			self::enqueue( 'full', 'full' );
		}
	}

	/**
	 * A record is about to be deleted.
	 *
	 * @param int      $id   The record.
	 * @param \WP_Post $post The record.
	 */
	public static function removed( $id, $post ): void {
		if ( $post instanceof \WP_Post ) {
			self::changed( $post );
		}
	}

	/**
	 * A field of a record changed.
	 *
	 * @param mixed  $meta_id The field's row.
	 * @param int    $id      The record.
	 * @param string $key     The field.
	 */
	public static function field_changed( $meta_id, $id, $key ): void {
		$key = (string) $key;
		if ( 0 !== strpos( $key, '_pos_' ) || 1 === preg_match( '/^_pos_(rev|revision|client_revision|member|lead|colead|request|signup|invite|remote|origin|hub_)/', $key ) ) {
			return;
		}
		$post = get_post( (int) $id );
		if ( $post instanceof \WP_Post ) {
			self::changed( $post, '_pos_subteams' === $key );
		}
	}

	/**
	 * Queue what a changed record means for the hub.
	 *
	 * @param \WP_Post $post  The record.
	 * @param bool     $teams Whether the organization's teams changed too.
	 */
	private static function changed( \WP_Post $post, bool $teams = false ): void {
		if ( ! Connection::connected() ) {
			return;
		}
		if ( Schema::IMPACT === $post->post_type ) {
			self::counted();
			// Work recorded on a Spot changes what the Spot's page shows: its stage and its photographs.
			$spot = (int) get_post_meta( $post->ID, '_pos_spot', true );
			if ( $spot > 0 ) {
				self::spot( $spot );
			}
			return;
		}
		if ( Schema::SPOT === $post->post_type ) {
			self::spot( $post->ID );
			return;
		}
		if ( Schema::PARTNER === $post->post_type ) {
			if ( Partners::site() === $post->ID ) {
				self::enqueue( 'profile', 'profile' );
			}
			return;
		}
		$kind = Listings::kind_of( $post->post_type );
		if ( '' === $kind ) {
			return;
		}
		// A copy of a joint campaign is the hub's record. The hub is not told what it said.
		if ( 'campaign' === $kind && Joint::is_copy( $post->ID ) ) {
			return;
		}
		if ( 'mission' === $kind ) {
			// A mission that started, ended, or lost a Spot changes how a Spot taken on from the hub stands.
			Adoptions::mission_changed();
			// A mission of a youth team decides what is sent of each of its Spots.
			if ( Subteams::youth_among( Missions::team( $post->ID ), Missions::subteams( $post->ID ) ) ) {
				foreach ( Missions::spots( $post->ID ) as $spot ) {
					self::spot( $spot );
				}
			}
		}
		self::listing( $kind, (string) $post->ID );
		if ( 'organization' === $kind && $teams ) {
			foreach ( Subteams::listed( $post->ID ) as $subteam ) {
				self::listing( 'team', $post->ID . '-' . (int) $subteam['id'] );
			}
		}
	}

	/**
	 * A cleanup was recorded, verified, or counted: the totals go out within a minute.
	 */
	public static function counted(): void {
		if ( ! isset( self::$queued['stats'] ) ) {
			self::$queued['stats'] = true;
			self::enqueue( 'stats', 'stats', 30 );
		}
	}

	/**
	 * Send what is due. The timers call this.
	 */
	public static function due(): void {
		self::run();
	}

	/**
	 * At the end of a request that queued something, wake WP-Cron so the send does not wait for the next visitor.
	 */
	public static function wake(): void {
		if ( ! self::$dirty || ! Connection::sending() ) {
			return;
		}
		self::$dirty = false;
		$next        = wp_next_scheduled( self::SOON );
		if ( self::$urgent && ( ! $next || $next > time() ) ) {
			// A Spot is on the hub's map within a minute. The timer is moved to now.
			if ( $next ) {
				wp_unschedule_event( $next, self::SOON );
			}
			wp_schedule_single_event( time() - 1, self::SOON );
		} elseif ( ! $next ) {
			wp_schedule_single_event( time() + 30, self::SOON );
		}
		self::$urgent = false;
		if ( ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			spawn_cron();
		}
	}

	/**
	 * The nightly work: send everything in full, ask how the connection stands, and trim the log.
	 */
	public static function nightly(): void {
		global $wpdb;
		Connection::refresh();
		if ( Connection::sending() ) {
			self::everything();
			self::run();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}pos_sync_log WHERE at < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Send what is due.
	 *
	 * @param bool $all Send everything queued, due or not: the administrator pressed Send now.
	 * @return array{sent: int, failed: int, waiting: int}
	 */
	public static function run( bool $all = false ): array {
		global $wpdb;
		$done = array(
			'sent'    => 0,
			'failed'  => 0,
			'waiting' => 0,
		);
		if ( App::is_hub() ) {
			return $done;
		}
		Connection::refresh_if_due();
		if ( ! Connection::sending() ) {
			$done['waiting'] = self::waiting();
			return $done;
		}
		// One run at a time. The lock lets go by itself if a run dies.
		if ( get_transient( 'packout_outbox_lock' ) ) {
			$done['waiting'] = self::waiting();
			return $done;
		}
		set_transient( 'packout_outbox_lock', 1, 5 * MINUTE_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, kind, subject, queued, attempts FROM {$wpdb->prefix}pos_outbox WHERE next_try <= %d ORDER BY id ASC LIMIT %d",
				$all ? PHP_INT_MAX : time(),
				self::PER_RUN
			),
			ARRAY_A
		);
		$by   = array();
		foreach ( (array) $rows as $row ) {
			$by[ $row['kind'] ][] = $row;
		}

		$alive = true;
		if ( $alive && ! empty( $by['profile'] ) ) {
			$said  = Connection::send( 'PUT', '/profile', Connection::profile() );
			$alive = self::settle( $by['profile'], $said, 'profile', 1, $done );
			if ( $alive && is_array( $said ) ) {
				// The hub names the logo and the cover it does not hold yet. Each goes in a request of its own.
				foreach ( array( 'logo', 'cover' ) as $kind ) {
					$token = (string) ( ( (array) ( $said['images'] ?? array() ) )[ $kind ] ?? '' );
					if ( ctype_digit( $token ) && Connection::image_token( $kind ) === $token ) {
						self::enqueue( 'image', 'listing:' . $kind . ':' . $token );
					}
				}
			}
		}
		if ( $alive && ! empty( $by['full'] ) ) {
			$alive = self::send_full( $by['full'], $done );
			if ( $alive ) {
				// Everything queued before the full send went out in it.
				$by['listing'] = array();
			}
		}
		if ( $alive && ! empty( $by['listing'] ) ) {
			$alive = self::send_listings( $by['listing'], $done );
		}
		if ( $alive && ! empty( $by['reconcile'] ) ) {
			// The comparison comes before the Spots, so that what it finds goes in this run.
			$queued = Exchange::reconcile();
			$alive  = self::settle( $by['reconcile'], is_wp_error( $queued ) ? $queued : array(), 'reconcile', is_wp_error( $queued ) ? 0 : (int) $queued, $done );
		}
		if ( $alive ) {
			$spots = self::due_rows( 'spot', $all );
			if ( $spots ) {
				$alive = self::send_spots( $spots, $done );
			}
		}
		/**
		 * The queue sends what other parts of the plugin put in it.
		 *
		 * @param bool                                            $alive Whether the hub is answering.
		 * @param array<string, array<int, array<string, mixed>>> $by    The rows that are due, by kind.
		 */
		$alive = (bool) apply_filters( 'packout_outbox_send', $alive, $by );
		if ( $alive ) {
			// The images the hub just asked for go in this run, with the ones that were waiting.
			$images = self::due_rows( 'image', $all );
			if ( $images ) {
				$alive = self::send_images( $images, $done );
			}
		}
		if ( $alive && ! empty( $by['stats'] ) ) {
			$alive = self::send_stats( $by['stats'], $done );
		}
		if ( $alive ) {
			// What changed on the hub: a Spot hidden or returned, a hint, an adoption released.
			Exchange::pull();
		}

		if ( $done['sent'] > 0 ) {
			Connection::remember(
				array(
					'sent'  => time(),
					'error' => '',
				)
			);
		}
		delete_transient( 'packout_outbox_lock' );
		$done['waiting'] = self::waiting();
		// What this run left, or queued itself, goes with a run of its own and does not wait five minutes.
		if ( $alive && self::due_now() > 0 && ! wp_next_scheduled( self::SOON ) ) {
			wp_schedule_single_event( time() + 5, self::SOON );
		}
		return $done;
	}

	/**
	 * The rows of one kind that are due, read now.
	 *
	 * @param string $kind The kind.
	 * @param bool   $all  Every row of the kind, due or not.
	 * @return array<int, array<string, mixed>>
	 */
	private static function due_rows( string $kind, bool $all ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, kind, subject, queued, attempts FROM {$wpdb->prefix}pos_outbox WHERE kind = %s AND next_try <= %d ORDER BY id ASC LIMIT %d",
				$kind,
				$all ? PHP_INT_MAX : time(),
				self::PER_RUN
			),
			ARRAY_A
		);
	}

	/**
	 * How many rows are due now.
	 */
	private static function due_now(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pos_outbox WHERE next_try <= %d", time() ) );
	}

	/**
	 * Mark rows done from outside this class: a part of the plugin that sends its own kind.
	 *
	 * @param array<int, array<string, mixed>> $rows   The rows.
	 * @param array<string, mixed>|\WP_Error   $answer What the hub said.
	 * @param string                           $action The action for the log.
	 * @return bool Whether the hub is answering.
	 */
	public static function finish( array $rows, $answer, string $action ): bool {
		$done = array(
			'sent'   => 0,
			'failed' => 0,
		);
		// A refusal about one thing is kept and tried again. The hub is still answering.
		if ( is_wp_error( $answer ) && self::about_one( $answer ) ) {
			$data = (array) $answer->get_error_data();
			self::log( $action, 1, (int) ( $data['status'] ?? 0 ), $answer->get_error_code() );
			self::later( $rows, $answer->get_error_code() );
			return true;
		}
		return self::settle( $rows, $answer, $action, 1, $done );
	}

	/**
	 * Whether a refusal is about the one thing that was sent, and not about the connection.
	 *
	 * @param \WP_Error $refusal The refusal.
	 */
	private static function about_one( \WP_Error $refusal ): bool {
		$data   = (array) $refusal->get_error_data();
		$status = (int) ( $data['status'] ?? 0 );
		return in_array( $status, array( 404, 409, 413, 415, 422 ), true );
	}

	/**
	 * Send the Spots that changed.
	 *
	 * @param array<int, array<string, mixed>> $rows The queued rows.
	 * @param array<string, int>               $done The run's counts.
	 * @return bool Whether the hub is answering.
	 */
	private static function send_spots( array $rows, array &$done ): bool {
		$items = array();
		$row   = array();
		foreach ( $rows as $queued ) {
			$id   = ctype_digit( (string) $queued['subject'] ) ? (int) $queued['subject'] : 0;
			$item = $id > 0 ? Exchange::item( $id ) : null;
			if ( null === $item ) {
				self::settle( array( $queued ), array(), '', 0, $done );
				continue;
			}
			$items[]             = $item;
			$row[ (string) $id ] = $queued;
		}
		foreach ( array_chunk( $items, Exchange::batch() ) as $batch ) {
			$mine   = array();
			$states = array();
			foreach ( $batch as $item ) {
				$mine[ $item['remote_id'] ]   = $row[ $item['remote_id'] ];
				$states[ $item['remote_id'] ] = $item['state'];
			}
			$answer = Connection::send( 'POST', '/spots', array( 'items' => $batch ) + Connection::versions() );
			if ( is_wp_error( $answer ) ) {
				return self::settle( array_values( $mine ), $answer, 'spots', count( $batch ), $done );
			}
			self::log( 'spots', count( $batch ), 200, self::refusals( $answer ) );
			foreach ( (array) ( $answer['items'] ?? array() ) as $result ) {
				$subject = (string) ( $result['remote_id'] ?? '' );
				if ( ! isset( $mine[ $subject ] ) ) {
					continue;
				}
				if ( 'refused' === ( $result['result'] ?? '' ) ) {
					self::later( array( $mine[ $subject ] ), sanitize_key( (string) ( $result['reason'] ?? 'refused' ) ) );
					++$done['failed'];
				} else {
					self::settle( array( $mine[ $subject ] ), array(), '', 0, $done );
					Exchange::settle( (array) $result, $states[ $subject ] );
					++$done['sent'];
				}
				unset( $mine[ $subject ] );
			}
			if ( $mine ) {
				self::later( array_values( $mine ), 'no_answer' );
				$done['failed'] += count( $mine );
			}
		}//end foreach
		return true;
	}

	/**
	 * Send the images the hub asked for, one in a request.
	 *
	 * @param array<int, array<string, mixed>> $rows The queued rows.
	 * @param array<string, int>               $done The run's counts.
	 * @return bool Whether the hub is answering.
	 */
	private static function send_images( array $rows, array &$done ): bool {
		foreach ( array_slice( $rows, 0, self::IMAGES ) as $queued ) {
			/**
			 * Filters the file an image row of the queue stands for.
			 *
			 * @param array{about: array<string, mixed>, path: string}|null $file    What the file is, as the request's query, and where it is. Null drops the row.
			 * @param string                                                 $subject The row's subject, as `spot:12:340`.
			 */
			$file = apply_filters( 'packout_outbox_image', 0 === strpos( (string) $queued['subject'], 'listing:' ) ? self::listing_image( (string) $queued['subject'] ) : self::spot_image( (string) $queued['subject'] ), (string) $queued['subject'] );
			if ( ! is_array( $file ) || ! is_readable( (string) ( $file['path'] ?? '' ) ) ) {
				self::settle( array( $queued ), array(), '', 0, $done );
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file in this site's uploads.
			$bytes  = (string) file_get_contents( $file['path'] );
			$type   = (string) wp_check_filetype( $file['path'] )['type'];
			$answer = Connection::send_file( '/images', (array) $file['about'], $bytes, '' !== $type ? $type : 'application/octet-stream' );
			if ( is_wp_error( $answer ) ) {
				if ( self::about_one( $answer ) ) {
					// The hub does not want this file: it is not one the record lists now, or not one it can read. It asks again for what it wants when the record is next sent.
					$data = (array) $answer->get_error_data();
					self::log( 'image', 1, (int) ( $data['status'] ?? 0 ), $answer->get_error_code() );
					self::settle( array( $queued ), array(), '', 0, $done );
					++$done['failed'];
					continue;
				}
				return self::settle( array( $queued ), $answer, 'image', 1, $done );
			}
			self::settle( array( $queued ), array(), 'image', 1, $done );
		}//end foreach
		return true;
	}

	/**
	 * The file of the partner's logo or cover, from an image row's subject.
	 *
	 * @param string $subject The subject, as `listing:logo:340`.
	 * @return array{about: array<string, mixed>, path: string}|null
	 */
	private static function listing_image( string $subject ): ?array {
		$parts = explode( ':', $subject );
		$path  = 3 === count( $parts ) ? Connection::image_file( $parts[1], $parts[2] ) : '';
		return '' !== $path ? array(
			'about' => array(
				'of'    => 'listing',
				'id'    => $parts[1],
				'photo' => $parts[2],
			),
			'path'  => $path,
		) : null;
	}

	/**
	 * The file of a Spot's photograph, from an image row's subject.
	 *
	 * @param string $subject The subject, as `spot:12:340`.
	 * @return array{about: array<string, mixed>, path: string}|null
	 */
	private static function spot_image( string $subject ): ?array {
		$parts = explode( ':', $subject );
		if ( 3 !== count( $parts ) || 'spot' !== $parts[0] ) {
			return null;
		}
		$photo = Exchange::photo( (int) $parts[1], (int) $parts[2] );
		return $photo ? array(
			'about' => array(
				'of'    => 'spot',
				'id'    => (string) (int) $parts[1],
				'photo' => (string) (int) $parts[2],
				'about' => $photo['of'],
			),
			'path'  => $photo['path'],
		) : null;
	}

	/**
	 * Send every public record, and tell the hub this is all of them.
	 *
	 * @param array<int, array<string, mixed>> $rows The queued row.
	 * @param array<string, int>               $done The run's counts.
	 * @return bool Whether the hub is answering.
	 */
	private static function send_full( array $rows, array &$done ): bool {
		global $wpdb;
		$started = gmdate( 'Y-m-d H:i:s' );
		$items   = array();
		foreach ( Listings::all() as $entry ) {
			$items[] = Listings::item( $entry[0], $entry[1] );
		}
		$sweep   = bin2hex( random_bytes( 8 ) );
		$batches = self::batches( $items );
		$batches = $batches ? $batches : array( array() );
		$last    = count( $batches ) - 1;
		foreach ( $batches as $index => $batch ) {
			$answer = Connection::send(
				'POST',
				'/listings',
				array(
					'full'  => true,
					'sweep' => $sweep,
					'last'  => $index === $last,
					'items' => $batch,
				) + Connection::versions()
			);
			if ( is_wp_error( $answer ) ) {
				return self::settle( $rows, $answer, 'listings', count( $batch ), $done );
			}
			self::log( 'listings', count( $batch ), 200, self::refusals( $answer ) );
		}
		self::settle( $rows, array(), '', 0, $done );
		$done['sent'] += count( $items );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}pos_outbox WHERE kind = 'listing' AND queued <= %s", $started ) );
		return true;
	}

	/**
	 * Send the listings that changed.
	 *
	 * @param array<int, array<string, mixed>> $rows The queued rows.
	 * @param array<string, int>               $done The run's counts.
	 * @return bool Whether the hub is answering.
	 */
	private static function send_listings( array $rows, array &$done ): bool {
		$order = array_flip( array_keys( Listings::kinds() ) );
		$items = array();
		$row   = array();
		foreach ( $rows as $queued ) {
			list( $kind, $remote_id ) = array_pad( explode( ':', (string) $queued['subject'], 2 ), 2, '' );
			if ( ! isset( $order[ $kind ] ) || 1 !== preg_match( '/^[0-9]{1,18}(-[0-9]{1,9})?$/', $remote_id ) ) {
				self::settle( array( $queued ), array(), '', 0, $done );
				continue;
			}
			$items[]                         = Listings::item( $kind, $remote_id );
			$row[ $kind . ':' . $remote_id ] = $queued;
		}
		// The order the hub applies them in: what is referred to goes before what refers to it.
		usort( $items, static fn( array $a, array $b ): int => $order[ $a['kind'] ] <=> $order[ $b['kind'] ] );

		foreach ( self::batches( $items ) as $batch ) {
			$mine = array();
			foreach ( $batch as $item ) {
				$mine[ $item['kind'] . ':' . $item['remote_id'] ] = $row[ $item['kind'] . ':' . $item['remote_id'] ];
			}
			$answer = Connection::send( 'POST', '/listings', array( 'items' => $batch ) + Connection::versions() );
			if ( is_wp_error( $answer ) ) {
				return self::settle( array_values( $mine ), $answer, 'listings', count( $batch ), $done );
			}
			self::log( 'listings', count( $batch ), 200, self::refusals( $answer ) );
			// Each item has its own answer. One the hub refused is kept and tried again, since what it refers to may arrive later.
			foreach ( (array) ( $answer['items'] ?? array() ) as $result ) {
				$subject = (string) ( $result['kind'] ?? '' ) . ':' . (string) ( $result['remote_id'] ?? '' );
				if ( ! isset( $mine[ $subject ] ) ) {
					continue;
				}
				if ( 'refused' === ( $result['result'] ?? '' ) ) {
					self::later( array( $mine[ $subject ] ), sanitize_key( (string) ( $result['reason'] ?? 'refused' ) ) );
					++$done['failed'];
				} else {
					self::settle( array( $mine[ $subject ] ), array(), '', 0, $done );
					++$done['sent'];
				}
				unset( $mine[ $subject ] );
			}
			// An item the hub did not answer for was not taken.
			if ( $mine ) {
				self::later( array_values( $mine ), 'no_answer' );
				$done['failed'] += count( $mine );
			}
		}//end foreach
		return true;
	}

	/**
	 * Send today's totals.
	 *
	 * @param array<int, array<string, mixed>> $rows The queued row.
	 * @param array<string, int>               $done The run's counts.
	 * @return bool Whether the hub is answering.
	 */
	private static function send_stats( array $rows, array &$done ): bool {
		$items = Snapshots::all( true );
		foreach ( self::batches( $items ) as $batch ) {
			$answer = Connection::send( 'POST', '/stats', array( 'items' => $batch ) + Connection::versions() );
			if ( is_wp_error( $answer ) ) {
				return self::settle( $rows, $answer, 'stats', count( $batch ), $done );
			}
			self::log( 'stats', count( $batch ), 200, self::refusals( $answer ) );
		}
		self::settle( $rows, array(), '', 0, $done );
		$done['sent'] += count( $items );
		return true;
	}

	/**
	 * Split items into batches the hub takes: no more than the contract's count, and no larger than its size.
	 *
	 * @param array<int, array<string, mixed>> $items The items.
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	public static function batches( array $items ): array {
		$batches = array();
		$batch   = array();
		$size    = 0;
		// Room for the versions and the flags that go with every batch.
		$limit = Contract::BODY - 2048;
		foreach ( $items as $item ) {
			$bytes = strlen( (string) wp_json_encode( $item ) ) + 1;
			if ( $bytes > $limit ) {
				// Only the overview's figures can make one item this large. The totals go without them.
				unset( $item['overview'] );
				$bytes = strlen( (string) wp_json_encode( $item ) ) + 1;
			}
			if ( $batch && ( count( $batch ) >= Contract::BATCH || $size + $bytes > $limit ) ) {
				$batches[] = $batch;
				$batch     = array();
				$size      = 0;
			}
			$batch[] = $item;
			$size   += $bytes;
		}
		if ( $batch ) {
			$batches[] = $batch;
		}
		return $batches;
	}

	/**
	 * Finish rows after a send: delete them when it went through, and set the next try when it did not.
	 *
	 * @param array<int, array<string, mixed>> $rows   The rows.
	 * @param array<string, mixed>|\WP_Error   $answer What the hub said.
	 * @param string                           $action The action for the log, or empty to log nothing.
	 * @param int                              $items  How many items went.
	 * @param array<string, int>               $done   The run's counts.
	 * @return bool Whether the hub is answering, so the run goes on.
	 */
	private static function settle( array $rows, $answer, string $action, int $items, array &$done ): bool {
		global $wpdb;
		if ( is_wp_error( $answer ) ) {
			$data = (array) $answer->get_error_data();
			// A hub that did not answer has no status.
			$status = 'packout_hub_unreachable' === $answer->get_error_code() ? 0 : (int) ( $data['status'] ?? 0 );
			self::log( $action, $items, $status, $answer->get_error_code() );
			self::later( $rows, $answer->get_error_code() );
			Connection::remember( array( 'error' => $answer->get_error_message() ) );
			$done['failed'] += max( 1, $items );
			// The hub said what state the partner is in, or that the plugin is too old. Ask, so the screen can say so.
			if ( in_array( $status, array( 401, 403, 426 ), true ) ) {
				Connection::refresh();
			}
			return false;
		}
		foreach ( $rows as $row ) {
			// A row that was queued again while this was being sent stays: what changed since has not gone.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}pos_outbox WHERE id = %d AND queued = %s", (int) $row['id'], (string) ( $row['queued'] ?? '' ) ) );
		}
		if ( '' !== $action ) {
			self::log( $action, $items, 200, '' );
			$done['sent'] += $items;
		}
		return true;
	}

	/**
	 * Set rows to be tried again later.
	 *
	 * @param array<int, array<string, mixed>> $rows   The rows.
	 * @param string                           $reason Why, as a short code.
	 */
	private static function later( array $rows, string $reason ): void {
		global $wpdb;
		foreach ( $rows as $row ) {
			$attempts = (int) $row['attempts'] + 1;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
			$wpdb->update(
				$wpdb->prefix . 'pos_outbox',
				array(
					'attempts'   => $attempts,
					'next_try'   => time() + self::wait( $attempts ),
					'last_error' => substr( $reason, 0, 190 ),
				),
				array( 'id' => (int) $row['id'] )
			);
		}
	}

	/**
	 * How long to wait before a try.
	 *
	 * @param int $attempts How many tries have failed.
	 */
	public static function wait( int $attempts ): int {
		return self::WAITS[ max( 0, min( count( self::WAITS ), $attempts ) - 1 ) ];
	}

	/**
	 * How many of a batch the hub refused, as a note for the log.
	 *
	 * @param array<string, mixed> $answer What the hub said.
	 */
	private static function refusals( array $answer ): string {
		$reasons = array();
		foreach ( (array) ( $answer['items'] ?? array() ) as $result ) {
			if ( 'refused' === ( $result['result'] ?? '' ) ) {
				$reason             = sanitize_key( (string) ( $result['reason'] ?? 'refused' ) );
				$reasons[ $reason ] = ( $reasons[ $reason ] ?? 0 ) + 1;
			}
		}
		$note = array();
		foreach ( $reasons as $reason => $count ) {
			$note[] = $count . ' ' . $reason;
		}
		return $note ? 'refused: ' . implode( ', ', $note ) : '';
	}

	/**
	 * How many rows are waiting.
	 */
	public static function waiting(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pos_outbox" );
	}

	/**
	 * The rows that are waiting, the ones that failed first.
	 *
	 * @param int $limit The most rows.
	 * @return array<int, array{kind: string, subject: string, queued: string, attempts: int, next_try: int, last_error: string}>
	 */
	public static function rows( int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT kind, subject, queued, attempts, next_try, last_error FROM {$wpdb->prefix}pos_outbox ORDER BY attempts DESC, id ASC LIMIT %d", $limit ), ARRAY_A );
		return array_map(
			static fn( array $row ): array => array(
				'kind'       => (string) $row['kind'],
				'subject'    => (string) $row['subject'],
				'queued'     => (string) $row['queued'],
				'attempts'   => (int) $row['attempts'],
				'next_try'   => (int) $row['next_try'],
				'last_error' => (string) $row['last_error'],
			),
			(array) $rows
		);
	}

	/**
	 * Empty the queue.
	 */
	public static function clear(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}pos_outbox" );
		// Nothing is queued any more, so what this request queued before can be queued again.
		self::$queued = array();
	}

	/**
	 * Write a line of the log. No payload is ever written.
	 *
	 * @param string $action What was done.
	 * @param int    $items  How many items it carried.
	 * @param int    $status The hub's status code, or zero when it did not answer.
	 * @param string $note   A short code or count.
	 */
	public static function log( string $action, int $items, int $status, string $note ): void {
		global $wpdb;
		if ( '' === $action ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$wpdb->insert(
			$wpdb->prefix . 'pos_sync_log',
			array(
				'at'     => gmdate( 'Y-m-d H:i:s' ),
				'action' => substr( $action, 0, 30 ),
				'items'  => max( 0, $items ),
				'status' => max( 0, $status ),
				'note'   => substr( $note, 0, 190 ),
			)
		);
	}

	/**
	 * The latest lines of the log.
	 *
	 * @param int $limit The most lines.
	 * @return array<int, array{at: string, action: string, items: int, status: int, note: string}>
	 */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT at, action, items, status, note FROM {$wpdb->prefix}pos_sync_log ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		return array_map(
			static fn( array $row ): array => array(
				'at'     => (string) $row['at'],
				'action' => (string) $row['action'],
				'items'  => (int) $row['items'],
				'status' => (int) $row['status'],
				'note'   => (string) $row['note'],
			),
			(array) $rows
		);
	}
}
