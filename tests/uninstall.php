<?php
/**
 * Deleting the plugin: what stays, and what goes when the site asked for that.
 *
 * Run in three parts, by the script that deletes the plugin between them:
 *
 *     wp eval-file tests/uninstall.php seed      with the plugin active
 *     (the plugin is deactivated and uninstall.php runs, "Remove all data" not ticked)
 *     wp eval-file tests/uninstall.php kept      ticks the box at its end
 *     (uninstall.php runs again)
 *     wp eval-file tests/uninstall.php removed
 *
 * `seed` makes one of everything the plugin keeps, and beside it things a
 * point-of-sale plugin would keep under the same `pos_` prefix, and things
 * that are the site's own. `kept` checks that nothing went. `removed` checks
 * that everything of the plugin's went and nothing else did, and deletes what
 * the checks made of the site's own.
 *
 * `kept` and `removed` run without the plugin: they read the database.
 *
 * @package PackOut_Partner
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

global $wpdb;

$packout_mode     = (string) ( $args[0] ?? '' );
$packout_failures = 0;
$packout_passed   = 0;
$packout_types    = array( 'pos_spot', 'pos_update', 'pos_mission', 'pos_team', 'pos_impact', 'pos_campaign', 'pos_place', 'pos_feature', 'pos_partner', 'pos_program', 'pos_partner_spot', 'pos_suggestion' );
$packout_tables   = array( 'pos_outbox', 'pos_sync_log', 'pos_invitations', 'pos_invitation_events' );
$packout_roles    = array( 'pos_reporter', 'pos_crew', 'pos_lead', 'pos_coordinator' );

$check = static function ( string $label, bool $ok, $detail = '' ) use ( &$packout_failures, &$packout_passed ): void {
	if ( $ok ) {
		++$packout_passed;
		WP_CLI::log( '  ok    ' . $label );
		return;
	}
	++$packout_failures;
	WP_CLI::log( '  FAIL  ' . $label . ( '' !== $detail && array() !== $detail ? ' — ' . ( is_scalar( $detail ) ? $detail : wp_json_encode( $detail ) ) : '' ) );
};

$picture = static function ( string $name, int $under ): int {
	$image = imagecreatetruecolor( 60, 40 );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 40, 120, 60 ) );
	ob_start();
	imagejpeg( $image );
	$upload = wp_upload_bits( $name, null, (string) ob_get_clean() );
	return (int) wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $name,
			'post_status'    => 'inherit',
		),
		$upload['file'],
		$under
	);
};

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- The checks read the database as it is, with the plugin gone, and make one table of their own.
$count_types  = static function () use ( $wpdb, $packout_types ): int {
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('" . implode( "','", $packout_types ) . "')" );
};
$has_table    = static fn( string $table ): bool => null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $table ) ) );
$options_like = static fn( string $like ): array => (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
$orphans      = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL" );
$hooks        = static function (): array {
	$found = array();
	foreach ( (array) _get_cron_array() as $at ) {
		foreach ( array_keys( (array) $at ) as $hook ) {
			if ( 0 === strpos( (string) $hook, 'packout_' ) ) {
				$found[] = (string) $hook;
			}
		}
	}
	return array_values( array_unique( $found ) );
};
$role_caps    = static function (): array {
	$found = array();
	foreach ( wp_roles()->roles as $name => $role ) {
		foreach ( array_keys( (array) $role['capabilities'] ) as $cap ) {
			if ( 0 === strpos( (string) $cap, 'packout_' ) ) {
				$found[] = $name . ':' . $cap;
			}
		}
	}
	return $found;
};

if ( 'seed' === $packout_mode ) {
	if ( ! class_exists( \PackOut\Core\App\App::class ) ) {
		WP_CLI::error( 'The plugin is not active.' );
	}
	$run   = strtolower( wp_generate_password( 6, false ) );
	$made  = array(
		'run'     => $run,
		'orphans' => $orphans(),
	);
	$admin = (int) get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	)[0];
	wp_set_current_user( $admin );
	\PackOut\Core\App\Schema::register();

	// People: one who holds only a role of the plugin's, and one who holds one of the site's as well.
	$lead           = wp_insert_user(
		array(
			'user_login' => 'pos-test-lead-' . $run,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'pos-test-lead-' . $run . '@example.test',
			'role'       => 'pos_lead',
		)
	);
	$crew           = wp_insert_user(
		array(
			'user_login' => 'pos-test-crew-' . $run,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'pos-test-crew-' . $run . '@example.test',
			'role'       => 'editor',
		)
	);
	$made['lead']   = (int) $lead;
	$made['crew']   = (int) $crew;
	$made['others'] = (int) get_user_by( 'id', $admin )->ID;
	( new WP_User( (int) $crew ) )->add_role( 'pos_crew' );
	\PackOut\Core\App\Profile::save_about( (int) $lead, array( 'about' => 'I keep the river road.' ) );

	// Records, through the plugin where it matters and directly elsewhere.
	$team = \PackOut\Core\App\Teams::save(
		0,
		array(
			'name'   => 'Crewtest Uninstallers ' . $run,
			'lead'   => (int) $lead,
			'listed' => true,
		)
	);
	if ( is_wp_error( $team ) ) {
		WP_CLI::error( 'Organization: ' . $team->get_error_message() );
	}
	\PackOut\Core\App\Teams::invite( (int) $team, 'pos-test-friend-' . $run . '@example.test', $admin, array( 'role' => 'member' ) );
	$spot = (int) wp_insert_post(
		array(
			'post_type'   => 'pos_spot',
			'post_status' => 'publish',
			'post_author' => (int) $lead,
			'post_title'  => 'Crewtest tires ' . $run,
		)
	);
	update_post_meta( $spot, '_pos_lat', '34.61' );
	update_post_meta( $spot, '_pos_lng', '-111.88' );
	update_post_meta( $spot, '_pos_status', 'spotted' );
	$term = wp_insert_term( 'Crewtest kind ' . $run, 'pos_category' );
	if ( ! is_wp_error( $term ) ) {
		wp_set_object_terms( $spot, array( (int) $term['term_id'] ), 'pos_category' );
	}
	$photo         = $picture( 'crewtest-spot-' . $run . '.jpg', $spot );
	$made['photo'] = (string) get_attached_file( $photo );
	foreach ( array( 'pos_update', 'pos_mission', 'pos_impact', 'pos_campaign', 'pos_program', 'pos_suggestion' ) as $type ) {
		$id = (int) wp_insert_post(
			array(
				'post_type'   => $type,
				'post_status' => 'publish',
				'post_parent' => 'pos_update' === $type ? $spot : 0,
				'post_title'  => 'Crewtest ' . $type . ' ' . $run,
			)
		);
		update_post_meta( $id, '_pos_team', (int) $team );
	}
	$page         = \PackOut\Core\App\Pages::act( 'how', 'create' );
	$made['page'] = is_int( $page ) ? $page : 0;
	$made['text'] = is_int( $page ) ? md5( (string) get_post_field( 'post_content', $page ) ) : '';

	// What the plugin keeps beside its records.
	\PackOut\Core\App\Outbox::install_tables();
	$wpdb->insert(
		$wpdb->prefix . 'pos_outbox',
		array(
			'kind'       => 'stats',
			'subject'    => 'stats',
			'queued'     => gmdate( 'Y-m-d H:i:s' ),
			'attempts'   => 0,
			'next_try'   => time(),
			'last_error' => '',
		)
	);
	update_option( 'packout_connection', array( 'state' => 'disconnected' ), true );
	// A stand-in with no key in it: only that the row goes is checked.
	update_option(
		'packout_partner_key',
		array(
			'public' => '',
			'secret' => '',
		),
		false
	);
	update_option( 'packout_wizard', array( 'done' => time() ), false );
	set_transient( 'packout_cal_' . $run, 'x', HOUR_IN_SECONDS );
	set_transient( 'pos_geo_' . $run, 'x', HOUR_IN_SECONDS );
	set_transient( 'pos_line_' . $run, 'x', HOUR_IN_SECONDS );
	set_site_transient( 'packout_update_' . $run, 'x', HOUR_IN_SECONDS );
	wp_schedule_single_event( time() + DAY_IN_SECONDS, 'packout_outbox_soon' );
	wp_schedule_single_event( time() + DAY_IN_SECONDS, 'packout_send_messages', array( (int) $team ) );

	// A point-of-sale plugin's, under the same prefix. None of this is the plugin's.
	update_option( 'pos_register_layout', 'grid', false );
	set_transient( 'pos_cart_' . $run, 'x', HOUR_IN_SECONDS );
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pos_orders ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, total INT NOT NULL DEFAULT 0, PRIMARY KEY (id) )" );
	$wpdb->insert( $wpdb->prefix . 'pos_orders', array( 'total' => 1200 ) );
	add_role( 'pos_cashier', 'Cashier', array( 'read' => true ) );
	update_user_meta( (int) $crew, 'pos_cashier_till', '4' );
	$made['order'] = (int) wp_insert_post(
		array(
			'post_type'   => 'pos_order',
			'post_status' => 'publish',
			'post_title'  => 'Crewtest order ' . $run,
		)
	);
	update_post_meta( $made['order'], '_pos_total', '1200' );

	// The site's own.
	$made['post']   = (int) wp_insert_post(
		array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => 'Crewtest news ' . $run,
		)
	);
	$made['file']   = (string) get_attached_file( $picture( 'crewtest-news-' . $run . '.jpg', $made['post'] ) );
	$made['counts'] = array(
		'records' => $count_types(),
		'users'   => (int) count_users()['total_users'],
	);
	update_option( 'crewtest_uninstall', $made, false );

	$check( 'there is one of everything to remove', $made['page'] > 0 && $made['counts']['records'] >= 9 && file_exists( $made['photo'] ) && $has_table( 'pos_outbox' ) && array() !== $hooks(), $made );
	$settings                = (array) get_option( 'packout_partner_settings', array() );
	$settings['remove_data'] = false;
	update_option( 'packout_partner_settings', $settings );
} elseif ( in_array( $packout_mode, array( 'kept', 'removed' ), true ) ) {
	if ( class_exists( \PackOut\Core\App\App::class, false ) ) {
		WP_CLI::error( 'The plugin is still active.' );
	}
	$made = get_option( 'crewtest_uninstall' );
	if ( ! is_array( $made ) ) {
		WP_CLI::error( 'Nothing was seeded.' );
	}
	$run  = (string) $made['run'];
	$lead = get_userdata( (int) $made['lead'] );
	$crew = get_userdata( (int) $made['crew'] );

	if ( 'kept' === $packout_mode ) {
		WP_CLI::log( 'Deleted with the box not ticked' );
		$check( 'every record is still there', $count_types() === (int) $made['counts']['records'], $count_types() );
		$check( 'with its photo', file_exists( $made['photo'] ) );
		$check( 'the tables and what is in them', $has_table( 'pos_outbox' ) && $has_table( 'pos_invitations' ) && 1 <= (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pos_outbox" ) );
		$check( 'the settings, the connection, and the key', false !== get_option( 'packout_partner_settings' ) && false !== get_option( 'packout_connection' ) && false !== get_option( 'packout_partner_key' ) );
		$check( 'the roles, and the people in them', null !== get_role( 'pos_lead' ) && $lead && in_array( 'pos_lead', $lead->roles, true ) && $crew && in_array( 'pos_crew', $crew->roles, true ) );
		$check( 'a person\'s profile', '' !== (string) wp_json_encode( get_user_meta( (int) $made['lead'], 'pos_profile', true ) ) && metadata_exists( 'user', (int) $made['lead'], 'pos_profile' ) );
		$check( 'the page, and the plugin\'s note on it', 'publish' === get_post_status( (int) $made['page'] ) && 'how' === get_post_meta( (int) $made['page'], '_pos_template', true ) );

		$settings                = (array) get_option( 'packout_partner_settings', array() );
		$settings['remove_data'] = true;
		update_option( 'packout_partner_settings', $settings );
	} else {
		WP_CLI::log( 'Deleted with "Remove all Pack-Out Spot data" ticked' );
		$check( 'no record of the plugin\'s is left', 0 === $count_types(), $count_types() );
		$check( 'nor anything that was kept about one', $orphans() <= (int) $made['orphans'], $orphans() . ' rows of post meta with no post, ' . $made['orphans'] . ' before' );
		$check( 'the photo is gone from the disk', ! file_exists( $made['photo'] ) );
		$check( 'the category terms are gone', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'pos_category'" ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE name = %s", 'Crewtest kind ' . $run ) ) );
		$left = array_filter( $packout_tables, $has_table );
		$check( 'the tables are gone', array() === $left, array_values( $left ) );
		$left = array_merge( $options_like( 'packout\\_%' ), $options_like( '\\_transient\\_packout\\_%' ), $options_like( '\\_transient\\_timeout\\_packout\\_%' ), $options_like( '\\_site\\_transient\\_packout\\_%' ), $options_like( '\\_transient\\_pos\\_geo\\_%' ), $options_like( '\\_transient\\_pos\\_line\\_%' ) );
		if ( is_multisite() ) {
			$left = array_merge( $left, (array) $wpdb->get_col( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE '\\_site\\_transient\\_packout\\_%'" ) );
		}
		$check( 'no option of the plugin\'s is left, with the connection and the key', array() === $left && false === get_option( 'packout_partner_key' ) && false === get_option( 'packout_connection' ) && false === get_option( 'packout_partner_settings' ), $left );
		$check( 'no scheduled task is left', array() === $hooks(), $hooks() );
		$left = array_filter( $packout_roles, static fn( string $role ): bool => null !== get_role( $role ) );
		$check( 'the roles are gone, and no role keeps a capability of the plugin\'s', array() === $left && array() === $role_caps(), array( array_values( $left ), $role_caps() ) );
		$check( 'what was kept on each account is gone', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s OR meta_key = %s", 'pos_profile', $wpdb->get_blog_prefix() . 'pos_lead_granted' ) ) );

		WP_CLI::log( 'What stays' );
		$check( 'every account is still there', (int) count_users()['total_users'] === (int) $made['counts']['users'] && $lead && $crew );
		$check( 'a person who held only the plugin\'s role has the site\'s default one', $lead && array( (string) get_option( 'default_role', 'subscriber' ) ) === array_values( $lead->roles ), $lead ? $lead->roles : 'gone' );
		$check( 'and one who held another keeps it and nothing else', $crew && array( 'editor' ) === array_values( $crew->roles ), $crew ? $crew->roles : 'gone' );
		$check( 'the page the setup made is the site\'s: still published, word for word', 'publish' === get_post_status( (int) $made['page'] ) && md5( (string) get_post_field( 'post_content', (int) $made['page'] ) ) === $made['text'] );
		$check( 'without the plugin\'s note on it', '' === get_post_meta( (int) $made['page'], '_pos_template', true ) && '' === get_post_meta( (int) $made['page'], '_pos_template_hash', true ) );
		$check( 'the site\'s own post and its file', 'publish' === get_post_status( (int) $made['post'] ) && file_exists( $made['file'] ) );
		$check( 'a point-of-sale plugin\'s option and transient', 'grid' === get_option( 'pos_register_layout' ) && 'x' === get_transient( 'pos_cart_' . $run ) );
		$check( 'its table, with what is in it', $has_table( 'pos_orders' ) && 1200 === (int) $wpdb->get_var( "SELECT total FROM {$wpdb->prefix}pos_orders LIMIT 1" ) );
		$check( 'its role, and what it keeps on an account', null !== get_role( 'pos_cashier' ) && '4' === get_user_meta( (int) $made['crew'], 'pos_cashier_till', true ) );
		$check( 'and its record', 'publish' === get_post_status( (int) $made['order'] ) && '1200' === get_post_meta( (int) $made['order'], '_pos_total', true ) );

		// What the checks made that is not the plugin's.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => (int) $made['post'],
				'fields'      => 'ids',
			)
		) as $file ) {
			wp_delete_attachment( (int) $file, true );
		}
		foreach ( array( 'post', 'order', 'page' ) as $key ) {
			wp_delete_post( (int) $made[ $key ], true );
		}
		wp_delete_user( (int) $made['lead'] );
		wp_delete_user( (int) $made['crew'] );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pos_orders" );
		remove_role( 'pos_cashier' );
		delete_option( 'pos_register_layout' );
		delete_transient( 'pos_cart_' . $run );
		delete_option( 'crewtest_uninstall' );
	}//end if
} else {
	WP_CLI::error( 'Say which part: seed, kept, or removed.' );
}//end if
// phpcs:enable

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( $packout_failures . ' checks failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
