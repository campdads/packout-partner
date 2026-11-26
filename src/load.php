<?php
/**
 * Loads the plugin's classes.
 *
 * The shared library in `lib/packout-core/` is loaded later, by
 * `Plugin::register()`, once the plugin knows it may run.
 *
 * @package PackOut_Partner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

foreach ( array( 'plugin', 'installer', 'settings', 'settings-screen', 'addresses', 'report-form', 'site-health' ) as $packout_partner_file ) {
	require_once __DIR__ . '/class-' . $packout_partner_file . '.php';
}
unset( $packout_partner_file );
