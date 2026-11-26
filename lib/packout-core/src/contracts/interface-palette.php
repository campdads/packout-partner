<?php
/**
 * Palette adapter.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies the colors the overview and the impact report use.
 */
interface Palette {

	/**
	 * The palette.
	 *
	 * @return array<string, string> Color name to a CSS color. The nine names are in `PackOut\Core\Kernel::COLORS`.
	 */
	public function colors(): array;
}
