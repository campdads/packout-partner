<?php
/**
 * Activation.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuses an activation that cannot work, and installs what the application
 * keeps on the site.
 *
 * Installing creates the roles, the category terms, the invitation tables,
 * the two tables of the connection to packout.spot, and the site's one
 * partner record. It creates no page. Deactivating stops the timers that
 * send to packout.spot and removes nothing. Deleting the plugin leaves the
 * site's records in place; see uninstall.php.
 */
class Installer {

	/**
	 * The option that holds the version that was last installed.
	 */
	public const VERSION_OPTION = 'packout_partner_installed';

	/**
	 * Install when the stored version is behind.
	 */
	public static function register(): void {
		// After the record types are registered.
		add_action( 'init', array( self::class, 'maybe_install' ), 20 );
	}

	/**
	 * Install or upgrade this site.
	 */
	public static function maybe_install(): void {
		if ( PACKOUT_PARTNER_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}
		\PackOut\Core\App\App::install();
		self::partner();
		update_option( self::VERSION_OPTION, PACKOUT_PARTNER_VERSION, true );
	}

	/**
	 * Make the site's one partner, when there is none.
	 *
	 * It takes the site's name and is led by the administrator who activated
	 * the plugin, or by the site's first administrator. It is not listed
	 * publicly until the site says so.
	 */
	private static function partner(): void {
		if ( \PackOut\Core\App\Partners::site() > 0 ) {
			return;
		}
		$lead = current_user_can( 'manage_options' ) ? get_current_user_id() : 0;
		if ( $lead <= 0 ) {
			$administrators = get_users(
				array(
					'role'    => 'administrator',
					'number'  => 1,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'fields'  => 'ID',
				)
			);
			$lead           = $administrators ? (int) $administrators[0] : 0;
		}
		if ( $lead > 0 ) {
			\PackOut\Core\App\Partners::make_site( $lead );
		}
	}

	/**
	 * Run on activation.
	 *
	 * @param mixed $network_wide Whether the plugin is being activated for a whole network.
	 */
	public static function activate( $network_wide = false ): void {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$refusal = self::refusal( (bool) $network_wide, defined( 'PACKOUT_SPOT_VERSION' ) || is_plugin_active( Plugin::HUB_PLUGIN ) );
		if ( '' === $refusal ) {
			// The work runs on the next request, once the record types are registered.
			delete_option( self::VERSION_OPTION );
			return;
		}
		deactivate_plugins( plugin_basename( PACKOUT_PARTNER_FILE ), true, (bool) $network_wide );
		wp_die(
			esc_html( $refusal ),
			esc_html__( 'Pack-Out Spot Partner was not activated', 'packout-partner' ),
			array(
				'response'  => 200,
				'back_link' => true,
			)
		);
	}

	/**
	 * Run on deactivation: stop the timers that send to packout.spot.
	 *
	 * The connection, its key, and what is waiting to be sent stay. A plugin
	 * that is activated again goes on from where it was. packout.spot is not
	 * told: to end the connection, disconnect on the Connection screen first.
	 */
	public static function deactivate(): void {
		if ( class_exists( '\PackOut\Core\App\Outbox' ) ) {
			\PackOut\Core\App\Outbox::unschedule();
		}
	}

	/**
	 * Why this activation is refused, or an empty string.
	 *
	 * @param bool $network_wide Whether the plugin is being activated for a whole network.
	 * @param bool $hub          Whether the hub's plugin is active on this site.
	 */
	public static function refusal( bool $network_wide, bool $hub ): string {
		if ( $network_wide ) {
			return __( 'Pack-Out Spot Partner cannot be activated for a whole network. Activate it on the one site that will use it. Each site has its own records and its own connection to packout.spot.', 'packout-partner' );
		}
		if ( $hub ) {
			return __( 'Pack-Out Spot Partner cannot be activated here. This site runs Pack-Out Spot itself, and the two cannot run together.', 'packout-partner' );
		}
		return '';
	}
}
