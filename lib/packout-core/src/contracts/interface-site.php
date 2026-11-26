<?php
/**
 * Site adapter.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the site the library is running on.
 */
interface Site {

	/**
	 * The plugin that carries the library: `packout-spot` or `packout-partner`.
	 */
	public function plugin(): string;

	/**
	 * That plugin's version.
	 */
	public function version(): string;

	/**
	 * Which side of the capability list this plugin is: `hub` or `partner`.
	 */
	public function surface(): string;

	/**
	 * The last phase of the partner build this plugin has reached. The parity test skips rows built later.
	 */
	public function phase(): int;

	/**
	 * Whether this site is packout.spot.
	 */
	public function is_hub(): bool;

	/**
	 * The name people know the site by.
	 */
	public function name(): string;

	/**
	 * The address of the site's mark, or an empty string.
	 */
	public function mark(): string;

	/**
	 * The first part of a public path.
	 *
	 * @param string $kind One of `spot`, `team`, `mission`, `campaign`, `program`, `partner`, `field`.
	 * @return string The base, without slashes.
	 */
	public function base( string $kind ): string;
}
