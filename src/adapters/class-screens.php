<?php
/**
 * Screens.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws the parts a screen is made of.
 *
 * A badge, a notice, and an empty state are plain markup here, which the
 * library writes and its `ui.css` styles. Nothing is mounted over them.
 */
class Screens implements \PackOut\Core\Contracts\Screens {

	/**
	 * The handle of the stylesheet for plain components.
	 */
	public const STYLE = 'packout-ui';

	/**
	 * The scripts and styles a page that shows components needs.
	 *
	 * @return array{scripts: array<int, string>, styles: array<int, string>}
	 */
	public function assets(): array {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			wp_register_style( self::STYLE, plugins_url( 'assets/css/ui.css', PACKOUT_CORE_FILE ), array(), PACKOUT_CORE_VERSION );
		}
		return array(
			'scripts' => array(),
			'styles'  => array( self::STYLE ),
		);
	}

	/**
	 * Whether this adapter draws a component itself.
	 *
	 * @param string $component Component name.
	 */
	public function supports( string $component ): bool {
		unset( $component );
		return false;
	}

	/**
	 * Markup for one component: the plain form the library wrote.
	 *
	 * @param string               $component Component name.
	 * @param array<string, mixed> $props     What the component shows.
	 * @param string               $content   The component as plain markup.
	 */
	public function render( string $component, array $props = array(), string $content = '' ): string {
		unset( $component, $props );
		return $content;
	}
}
