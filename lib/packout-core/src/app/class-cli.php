<?php
/**
 * The `wp packout` commands of a partner site.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * See how a partner site stands and run its connection to packout.spot from the command line.
 *
 * No command prints the site's key, an email address, or what a request carried.
 */
class Cli {

	/**
	 * Add the commands. A partner site only, and only under WP-CLI.
	 */
	public static function register(): void {
		if ( App::is_hub() || ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'packout', self::class );
	}

	/**
	 * Print rows as a table, or as JSON.
	 *
	 * @param array<int, array<string, string>> $rows   Rows.
	 * @param array<int, string>                $fields Columns.
	 * @param array<string, mixed>              $assoc  The command's options.
	 */
	private static function table( array $rows, array $fields, array $assoc ): void {
		\WP_CLI\Utils\format_items( 'json' === ( $assoc['format'] ?? '' ) ? 'json' : 'table', $rows, $fields );
	}

	/**
	 * The administrator a command acts as, or stop.
	 */
	private static function administrator(): int {
		$user = get_current_user_id();
		if ( $user <= 0 || ! user_can( $user, 'manage_options' ) ) {
			\WP_CLI::error( 'Run this as an administrator of the site: add --user=<login>.' );
		}
		return $user;
	}

	/**
	 * Shows how the site and its connection to packout.spot stand.
	 *
	 * Exits with 1 when a check needs fixing.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function status( $args, $assoc ): void {
		unset( $args );
		$rows = array();
		foreach ( Health::facts() as $name => $value ) {
			$rows[] = array(
				'name'  => (string) $name,
				'value' => '' !== $value ? $value : '-',
			);
		}
		self::table( $rows, array( 'name', 'value' ), (array) $assoc );
		if ( 'critical' === Health::worst() ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Runs every check and says what each found.
	 *
	 * Exits with 1 when a check needs fixing.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function doctor( $args, $assoc ): void {
		unset( $args );
		$checks = Health::checks();
		$rows   = array();
		foreach ( $checks as $row ) {
			$rows[] = array(
				'check'  => $row['id'],
				'status' => $row['status'],
				'found'  => $row['detail'],
			);
		}
		self::table( $rows, array( 'check', 'status', 'found' ), (array) $assoc );
		$worst = Health::worst( $checks );
		if ( 'critical' === $worst ) {
			\WP_CLI::halt( 1 );
		}
		if ( 'json' !== ( $assoc['format'] ?? '' ) ) {
			\WP_CLI::success( 'good' === $worst ? 'Every check is good.' : 'Nothing needs fixing. Some checks are worth a look.' );
		}
	}

	/**
	 * Sends what is waiting to packout.spot now.
	 *
	 * ## OPTIONS
	 *
	 * [--full]
	 * : Queue everything public on the site first, as the nightly run does.
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function sync( $args, $assoc ): void {
		unset( $args );
		if ( ! Connection::sending() ) {
			\WP_CLI::error( 'This site is not sending: it is not connected, not yet approved, suspended, or waiting for the terms to be accepted. Run `wp packout status`.' );
		}
		if ( ! empty( $assoc['full'] ) ) {
			Outbox::everything();
		}
		$ran = Outbox::run( true );
		\WP_CLI::log( sprintf( 'Sent: %d. Failed: %d. Still waiting: %d.', $ran['sent'], $ran['failed'], Outbox::waiting() ) );
		if ( $ran['failed'] > 0 ) {
			\WP_CLI::error( 'Some of what was waiting could not be sent. It stays queued and is tried again. Run `wp packout doctor`.' );
		}
		\WP_CLI::success( 'Done.' );
	}

	/**
	 * Compares the Spots this site has sent with what packout.spot holds, and queues what differs.
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function reconcile( $args, $assoc ): void {
		unset( $args, $assoc );
		if ( ! Connection::sending() ) {
			\WP_CLI::error( 'This site is not sending. Run `wp packout status`.' );
		}
		$done = Exchange::reconcile();
		if ( is_wp_error( $done ) ) {
			\WP_CLI::error( $done->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Compared. Waiting to be sent: %d.', Outbox::waiting() ) );
	}

	/**
	 * Connects the site to packout.spot.
	 *
	 * Without --accept-terms it prints the version of the partner terms and
	 * where to read them, and connects nothing.
	 *
	 * ## OPTIONS
	 *
	 * [--accept-terms=<version>]
	 * : The version of the partner terms you have read and accept.
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function connect( $args, $assoc ): void {
		unset( $args );
		$user = self::administrator();
		if ( Connection::connected() ) {
			\WP_CLI::error( 'This site is already connected. Run `wp packout status`.' );
		}
		$terms = Connection::terms( true );
		if ( is_wp_error( $terms ) ) {
			\WP_CLI::error( 'packout.spot could not be reached to read the partner terms. ' . $terms->get_error_message() );
		}
		if ( ! $terms['open'] ) {
			\WP_CLI::error( 'packout.spot is not taking new partner sites yet. Nothing was sent.' );
		}
		$accepted = (string) ( $assoc['accept-terms'] ?? '' );
		if ( $accepted !== $terms['version'] || '' === $accepted ) {
			\WP_CLI::log( sprintf( 'The partner terms are at version %s: %s', $terms['version'], $terms['url'] ) );
			\WP_CLI::error( sprintf( 'Read them, and run the command again with --accept-terms=%s to accept them. Nothing was sent.', $terms['version'] ) );
		}
		$done = Connection_Screen::perform( 'connect', $accepted, true, false, $user );
		if ( is_wp_error( $done ) ) {
			\WP_CLI::error( $done->get_error_message() );
		}
		\WP_CLI::success( 'Registered with packout.spot. A confirmation link was sent to the administrator\'s email address, and a coordinator there approves the site.' );
	}

	/**
	 * Disconnects the site from packout.spot. Nothing on the site changes.
	 *
	 * ## OPTIONS
	 *
	 * [--remove]
	 * : Have packout.spot remove the partner and its last totals too, instead of keeping them as history out of public view.
	 *
	 * [--yes]
	 * : Do not ask first.
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function disconnect( $args, $assoc ): void {
		unset( $args );
		$user = self::administrator();
		if ( ! Connection::connected() ) {
			\WP_CLI::error( 'This site is not connected.' );
		}
		\WP_CLI::confirm( 'Disconnect this site from packout.spot? Its listings there are removed.', (array) $assoc );
		$done = Connection_Screen::perform( 'disconnect', '', false, empty( $assoc['remove'] ), $user );
		if ( is_wp_error( $done ) ) {
			\WP_CLI::error( $done->get_error_message() );
		}
		\WP_CLI::success( 'Disconnected. The records on this site are as they were.' );
	}

	/**
	 * Lists what packout.spot last told this site.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function notices( $args, $assoc ): void {
		unset( $args );
		$rows = array();
		foreach ( Connection::notices() as $notice ) {
			$rows[] = array(
				'when' => gmdate( 'Y-m-d H:i', (int) ( $notice['at'] ?? 0 ) ) . ' UTC',
				'kind' => (string) ( $notice['kind'] ?? '' ),
				'said' => (string) ( $notice['text'] ?? '' ),
			);
		}
		if ( ! $rows && 'json' !== ( $assoc['format'] ?? '' ) ) {
			\WP_CLI::log( 'packout.spot has told this site nothing.' );
			return;
		}
		self::table( $rows, array( 'when', 'kind', 'said' ), (array) $assoc );
	}

	/**
	 * Lists the pages the plugin can make and how each stands, or updates the ones nobody has edited.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : `update` writes the newer template to every page that is as the plugin made it. An edited page is never written to.
	 *
	 * [--format=<format>]
	 * : table or json.
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function pages( $args, $assoc ): void {
		if ( 'update' === ( $args[0] ?? '' ) ) {
			self::administrator();
			$updated = 0;
			foreach ( Pages::states() as $key => $state ) {
				if ( 'update' !== $state['state'] ) {
					continue;
				}
				$done = Pages::act( $key, 'update' );
				if ( is_wp_error( $done ) ) {
					\WP_CLI::warning( $key . ': ' . $done->get_error_message() );
					continue;
				}
				++$updated;
			}
			\WP_CLI::success( sprintf( 'Updated: %d. Edited pages were left alone.', $updated ) );
			return;
		}
		$rows = array();
		foreach ( Pages::states() as $key => $state ) {
			$rows[] = array(
				'page'    => $key,
				'state'   => $state['state'] . ( 'edited' === $state['state'] && $state['newer'] ? ' (template is newer)' : '' ),
				'id'      => $state['id'] > 0 ? (string) $state['id'] : '-',
				'address' => '' !== $state['url'] ? $state['url'] : '-',
			);
		}
		self::table( $rows, array( 'page', 'state', 'id', 'address' ), (array) $assoc );
	}
}
