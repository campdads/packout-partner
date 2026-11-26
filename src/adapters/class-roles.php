<?php
/**
 * Roles, from WordPress.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the suggested roles with `add_role()` and gives the site's
 * administrators every capability.
 */
class Roles implements \PackOut\Core\Contracts\Roles {

	/**
	 * Register capabilities and roles.
	 *
	 * A role that already exists keeps the capabilities the site set on it.
	 *
	 * @param array<string, string>                                         $capabilities Capability name to what it allows.
	 * @param array<string, array{label: string, caps: array<int, string>}> $roles        Role slug to its label and capabilities.
	 */
	public function register( array $capabilities, array $roles ): void {
		foreach ( $roles as $slug => $role ) {
			if ( get_role( $slug ) instanceof \WP_Role ) {
				continue;
			}
			$caps = array( 'read' => true );
			foreach ( $role['caps'] as $cap ) {
				$caps[ $cap ] = true;
			}
			add_role( $slug, $role['label'], $caps );
		}

		$administrator = get_role( 'administrator' );
		if ( $administrator instanceof \WP_Role ) {
			foreach ( array_keys( $capabilities ) as $cap ) {
				if ( ! $administrator->has_cap( $cap ) ) {
					$administrator->add_cap( $cap );
				}
			}
		}
	}
}
