<?php
/**
 * What the plugin itself adds to the checks of a partner site.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two checks the library cannot make, since they are about this plugin: that
 * nothing else on the site answers at an address the plugin's records use,
 * and that the plugin can reach packout.spot for its updates.
 *
 * They join the library's list, so the setup wizard, Site Health, and
 * `wp packout doctor` all show them.
 */
class Site_Health {

	/**
	 * Add the checks.
	 */
	public static function register(): void {
		add_filter( 'packout_health_checks', array( self::class, 'checks' ) );
	}

	/**
	 * The addresses of the plugin's records that something else on the site also answers at.
	 *
	 * @return array<string, string> Kind of record to what else uses its address, in words.
	 */
	public static function clashes(): array {
		$found = array();
		$bases = (array) Settings::get( 'bases', Settings::BASES );
		foreach ( array_keys( Settings::BASES ) as $kind ) {
			$base = isset( $bases[ $kind ] ) && is_string( $bases[ $kind ] ) ? $bases[ $kind ] : Settings::BASES[ $kind ];
			$user = Addresses::user_of( $base );
			if ( '' !== $user ) {
				$found[ $kind ] = sprintf(
					/* translators: 1: an address, such as /team/, 2: a kind of record, such as Organization, 3: what else uses the address, such as the page "Team". */
					__( '/%1$s/ is where each %2$s has its page, and it is also used by %3$s.', 'packout-partner' ),
					$base,
					Addresses::labels()[ $kind ] ?? $kind,
					$user
				);
			}
		}
		return $found;
	}

	/**
	 * Add the plugin's checks to the library's.
	 *
	 * @param mixed $rows The checks so far.
	 * @return mixed
	 */
	public static function checks( $rows ) {
		if ( ! is_array( $rows ) ) {
			return $rows;
		}
		$clashes           = self::clashes();
		$rows['addresses'] = array(
			'id'     => 'addresses',
			'group'  => 'site',
			'label'  => __( 'Addresses', 'packout-partner' ),
			'status' => $clashes ? 'critical' : 'good',
			'detail' => $clashes
				? implode( ' ', $clashes ) . ' ' . __( 'One of the two cannot be reached. Rename the other, or choose another address in Settings.', 'packout-partner' )
				: __( 'Nothing else on this site uses the addresses of Spots, organizations, missions, and the rest.', 'packout-partner' ),
			'url'    => admin_url( 'options-general.php?page=' . Settings_Screen::PAGE ),
			'action' => __( 'Settings', 'packout-partner' ),
		);

		$updates         = Plugin::updates();
		$status          = null !== $updates ? $updates->status() : null;
		$failed          = is_array( $status ) && $status['failed'];
		$rows['updates'] = array(
			'id'     => 'updates',
			'group'  => 'site',
			'label'  => __( 'Updates', 'packout-partner' ),
			'status' => $failed ? 'recommended' : 'good',
			'detail' => $failed
				? __( 'The last check for updates did not reach packout.spot. It is tried again by itself.', 'packout-partner' )
				: __( 'Updates come from packout.spot, and each one is checked against its signature before it is installed.', 'packout-partner' ),
			'url'    => admin_url( 'options-general.php?page=' . Settings_Screen::PAGE ),
			'action' => __( 'Settings', 'packout-partner' ),
		);
		return $rows;
	}
}
