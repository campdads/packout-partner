<?php
/**
 * Roles adapter.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes the capabilities and the suggested roles known to the site.
 */
interface Roles {

	/**
	 * Register capabilities and roles.
	 *
	 * @param array<string, string>                                         $capabilities Capability name to what it allows.
	 * @param array<string, array{label: string, caps: array<int, string>}> $roles        Role slug to its label and capabilities.
	 */
	public function register( array $capabilities, array $roles ): void;
}
