<?php
/**
 * Pack-Out Spot Partner.
 *
 * Runs Pack-Out Spot's stewardship work on a partner's own WordPress site,
 * with the partner's records in the partner's database. It needs no other
 * plugin. It can connect the site to packout.spot.
 *
 * @package PackOut_Partner
 */

/**
 * Plugin Name:       Pack-Out Spot Partner
 * Plugin URI:        https://packout.spot/plugins/packout-partner
 * Description:       Community stewardship for places that need attention, on your own site. Report Spots, plan missions, record the result, and share it with packout.spot.
 * Version:           1.0.1
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            CampDads Digital
 * Author URI:        https://campdads.digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://packout.spot/plugins/packout-partner
 * Text Domain:       packout-partner
 * Domain Path:       /languages
 *
 * @package PackOut_Partner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PACKOUT_PARTNER_FILE', __FILE__ );
define( 'PACKOUT_PARTNER_VERSION', '1.0.1' );

/*
 * The last phase of the partner build this plugin has reached. The parity
 * test expects a handler for every act the capability list gives the partner
 * plugin by this phase.
 */
define( 'PACKOUT_PARTNER_PHASE', 12 );

require_once __DIR__ . '/src/load.php';

register_activation_hook( __FILE__, array( PackOut\Partner\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( PackOut\Partner\Installer::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		( new PackOut\Partner\Plugin() )->register();
	},
	20
);
