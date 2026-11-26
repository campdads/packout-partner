<?php
/**
 * Screens adapter.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws the parts a screen is made of: the page shell, tabs, a drawer, a notice, a badge, a confirmation, and date and time fields.
 */
interface Screens {

	/**
	 * The scripts and styles a page that shows components must load.
	 *
	 * @return array{scripts: array<int, string>, styles: array<int, string>} Registered handles. Both empty when components are plain markup.
	 */
	public function assets(): array;

	/**
	 * Whether this adapter can draw a component.
	 *
	 * @param string $component Component name, such as `page` or `drawer`.
	 */
	public function supports( string $component ): bool;

	/**
	 * Markup for one component.
	 *
	 * @param string               $component Component name.
	 * @param array<string, mixed> $props     What the component shows.
	 * @param string               $content   Markup placed inside it.
	 * @return string HTML.
	 */
	public function render( string $component, array $props = array(), string $content = '' ): string;
}
