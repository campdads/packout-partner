<?php
/**
 * Runs when the plugin is deleted.
 *
 * Deleting the plugin leaves every record, page, and attachment in place
 * unless the site ticked "Remove all Pack-Out Spot data when the plugin is
 * deleted" in Settings. Deactivating never removes anything.
 *
 * With the box ticked, everything the plugin made on the site goes: its
 * records (Spots, updates, organizations, missions, campaigns, programs,
 * records of work, the partner), the photos and logos attached to them, its
 * category terms, its tables, its options, what it kept on each account, its
 * roles and capabilities, its scheduled tasks, and its connection to
 * packout.spot with the key.
 *
 * What stays is the site's own: the pages the setup made (they are ordinary
 * pages, and only the plugin's notes on them are removed), the user accounts,
 * and anything another plugin keeps. Everything here is named exactly, never
 * matched by the `pos_` prefix alone, since point-of-sale plugins use it too.
 *
 * packout.spot is not told. A site that wants its listings off packout.spot
 * disconnects on the Connection screen before the plugin is deleted.
 *
 * @package PackOut_Partner
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove everything the plugin keeps on the current site, when the site asked for that.
 */
function packout_partner_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'packout_partner_settings' );
	if ( ! is_array( $settings ) || empty( $settings['remove_data'] ) ) {
		return;
	}

	// The key first, so nothing can be signed with it again.
	delete_option( 'packout_partner_key' );

	// Scheduled tasks: every hook of the plugin's, whatever its arguments.
	$crons = _get_cron_array();
	foreach ( is_array( $crons ) ? $crons : array() as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( 0 === strpos( (string) $hook, 'packout_' ) ) {
				wp_unschedule_hook( (string) $hook );
			}
		}
	}

	// The records. Their photos, logos, and files are attachments under them, and go first so the files leave the disk.
	$types = array( 'pos_spot', 'pos_update', 'pos_mission', 'pos_team', 'pos_impact', 'pos_campaign', 'pos_place', 'pos_feature', 'pos_partner', 'pos_program', 'pos_partner_spot', 'pos_suggestion' );
	$in    = "'" . implode( "','", $types ) . "'";
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Fixed names from the list above, and the plugin's own tables.
	do {
		$files = $wpdb->get_col( "SELECT a.ID FROM {$wpdb->posts} a JOIN {$wpdb->posts} p ON p.ID = a.post_parent WHERE a.post_type = 'attachment' AND p.post_type IN ({$in}) LIMIT 200" );
		foreach ( $files as $file ) {
			wp_delete_attachment( (int) $file, true );
		}
	} while ( $files );

	// A revision of a record, if a site turned revisions on for one.
	$wpdb->query( "DELETE r FROM {$wpdb->posts} r JOIN {$wpdb->posts} p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND p.post_type IN ({$in})" );
	$wpdb->query( "DELETE m FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type IN ({$in})" );
	$wpdb->query( "DELETE r FROM {$wpdb->term_relationships} r JOIN {$wpdb->posts} p ON p.ID = r.object_id WHERE p.post_type IN ({$in})" );
	$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ({$in})" );

	// The plugin's notes on the site's own pages. The pages stay.
	foreach ( array( '_pos_template', '_pos_template_version', '_pos_template_hash' ) as $key ) {
		delete_post_meta_by_key( $key );
	}

	// The category terms.
	$terms = $wpdb->get_results( "SELECT term_taxonomy_id, term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'pos_category'" );
	foreach ( (array) $terms as $term ) {
		$wpdb->delete( $wpdb->term_relationships, array( 'term_taxonomy_id' => (int) $term->term_taxonomy_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => (int) $term->term_taxonomy_id ), array( '%d' ) );
		if ( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d", (int) $term->term_id ) ) ) {
			$wpdb->delete( $wpdb->termmeta, array( 'term_id' => (int) $term->term_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $term->term_id ), array( '%d' ) );
		}
	}

	// The tables.
	foreach ( array( 'pos_outbox', 'pos_sync_log', 'pos_invitations', 'pos_invitation_events' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
	}

	// The options. Every one of the plugin's begins with `packout_`, and its short-lived ones with the prefixes below.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'packout\\_%' OR option_name LIKE '\\_transient\\_packout\\_%' OR option_name LIKE '\\_transient\\_timeout\\_packout\\_%'" );
	foreach ( array( 'pos_geo_', 'pos_line_', 'pos_flash_', 'pos_limit_', 'pos_claim_note_', 'pos_update_note_', 'pos_feature_note_' ) as $prefix ) {
		$like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
		$time = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $time ) );
	}

	// What the update check kept is the network's on a multisite, and this site's otherwise.
	if ( is_multisite() ) {
		$wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '\\_site\\_transient\\_packout\\_%' OR meta_key LIKE '\\_site\\_transient\\_timeout\\_packout\\_%'" );
	} else {
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_packout\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_packout\\_%'" );
	}

	// What was kept on each account: the profile, and the note that the Lead role was given by the plugin.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s OR meta_key = %s", 'pos_profile', $wpdb->get_blog_prefix() . 'pos_lead_granted' ) );
	// phpcs:enable

	// The roles the plugin made, and its capabilities on every other role. Accounts stay, without them.
	$caps = array( 'packout_report', 'packout_moderate', 'packout_lead', 'packout_team', 'packout_impact', 'packout_export', 'packout_partner', 'packout_screens' );
	foreach ( array( 'pos_reporter', 'pos_crew', 'pos_lead', 'pos_coordinator' ) as $role ) {
		foreach ( get_users(
			array(
				'role'   => $role,
				'fields' => 'ID',
			)
		) as $user_id ) {
			$user = new WP_User( (int) $user_id );
			$user->remove_role( $role );
			if ( ! $user->roles ) {
				// An account that had only this role keeps the site's default one, so it is not left with none.
				$user->set_role( (string) get_option( 'default_role', 'subscriber' ) );
			}
		}
		remove_role( $role );
	}
	foreach ( wp_roles()->role_objects as $role ) {
		foreach ( $caps as $cap ) {
			if ( $role->has_cap( $cap ) ) {
				$role->remove_cap( $cap );
			}
		}
	}

	wp_cache_flush();
	flush_rewrite_rules( false );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $packout_partner_site ) {
		switch_to_blog( (int) $packout_partner_site );
		packout_partner_uninstall_site();
		restore_current_blog();
	}
	unset( $packout_partner_site );
} else {
	packout_partner_uninstall_site();
}
