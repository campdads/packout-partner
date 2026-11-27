<?php
/**
 * Loads the Pack-Out Spot library.
 *
 * A plugin requires this file from its `lib/packout-core/` directory, then
 * builds a `PackOut\Core\Kernel` with its adapters. The library registers
 * nothing with WordPress until the plugin asks it to.
 *
 * @package PackOut_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// A second copy of the library never loads: the first one in wins, and the
// plugin that loaded second sees the version it got in PACKOUT_CORE_VERSION.
if ( defined( 'PACKOUT_CORE_VERSION' ) ) {
	return;
}

define( 'PACKOUT_CORE_VERSION', '0.13.3' );
define( 'PACKOUT_CORE_DIR', __DIR__ );
define( 'PACKOUT_CORE_FILE', __FILE__ );

/*
 * The partner interface's contract version. A site and the hub each say
 * which one they speak. It changes only when a payload changes shape.
 */
define( 'PACKOUT_CORE_CONTRACT', 1 );

foreach ( array( 'access', 'lists', 'report-form', 'roles', 'storage', 'screens', 'palette', 'icons', 'settings', 'mailer', 'site' ) as $packout_core_file ) {
	require_once __DIR__ . '/src/contracts/interface-' . $packout_core_file . '.php';
}
foreach ( array( 'kernel', 'capability-map', 'parity', 'release', 'updates', 'build', 'partner', 'program', 'schedule', 'calendar', 'ical', 'repeat', 'signing', 'contract' ) as $packout_core_file ) {
	require_once __DIR__ . '/src/class-' . $packout_core_file . '.php';
}

/*
 * The application: records, pages, blocks, the workspace, the admin screens,
 * the field app. Each class is static and registers its hooks only when the
 * plugin that carries the library calls its register().
 */
foreach ( array( 'app', 'post-access', 'ui', 'capabilities', 'settings', 'schema', 'vocabularies', 'schedule', 'calendar', 'calendar-view', 'geo', 'remote', 'remote-spots', 'listings', 'snapshots', 'connection', 'outbox', 'exchange', 'adoptions', 'joint', 'hub-layers', 'spots', 'credit', 'updates', 'spot-page', 'feature-page', 'photos', 'teams', 'subteams', 'team-page', 'partners', 'programs', 'partner-page', 'partner-workspace', 'sponsors', 'resources', 'page-header', 'campaigns', 'places', 'feature-icons', 'features', 'missions', 'signups', 'impact', 'accounts', 'approvals', 'invitations', 'claims', 'profile', 'messages', 'sign-in', 'workspace', 'workspace-lists', 'workspace-views', 'search', 'rest', 'admin', 'admin-views', 'overview', 'overview-scope', 'overview-page', 'overview-report', 'spots-screen', 'missions-screen', 'teams-screen', 'programs-screen', 'impact-screen', 'people-screen', 'invitations-screen', 'export', 'export-screen', 'settings-screen', 'connection-screen', 'pages', 'pages-screen', 'health', 'wizard', 'privacy', 'cli', 'blocks', 'field-app' ) as $packout_core_file ) {
	require_once __DIR__ . '/src/app/class-' . $packout_core_file . '.php';
}
unset( $packout_core_file );
