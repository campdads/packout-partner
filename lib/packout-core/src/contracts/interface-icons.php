<?php
/**
 * Icons adapter.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies an icon by name.
 */
interface Icons {

	/**
	 * An inline SVG.
	 *
	 * @param string $name    Icon name in the WordPress icon library, such as `chevron-right`.
	 * @param string $classes Class attribute of the `svg` element.
	 * @return string SVG markup, or an empty string when there is no such icon.
	 */
	public function svg( string $name, string $classes = '' ): string;
}
