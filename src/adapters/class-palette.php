<?php
/**
 * The palette, from the active theme.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Takes the overview's colors from the theme's palette where the theme names
 * them, and from fixed values where it does not.
 */
class Palette implements \PackOut\Core\Contracts\Palette {

	/**
	 * The colors used when the theme has none to give. They are the colors
	 * of packout.spot.
	 */
	public const FALLBACK = array(
		'primary'     => '#415840',
		'secondary'   => '#25486b',
		'accent'      => '#bb5e28',
		'accent_dark' => '#863d14',
		'surface'     => '#ffffff',
		'subtle'      => '#edeae5',
		'line'        => '#c9c9c9',
		'muted'       => '#838585',
		'ink'         => '#2f3232',
	);

	/**
	 * Theme palette slugs that stand for each color. The first one the
	 * theme has wins.
	 */
	private const SLUGS = array(
		'primary'   => array( 'primary', 'accent-1', 'accent' ),
		'secondary' => array( 'secondary', 'accent-2' ),
		'accent'    => array( 'tertiary', 'accent-3' ),
		'ink'       => array( 'contrast', 'foreground' ),
		'surface'   => array( 'base', 'background' ),
	);

	/**
	 * The palette.
	 *
	 * @return array<string, string>
	 */
	public function colors(): array {
		$colors = self::FALLBACK;
		$theme  = array();
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$palette = wp_get_global_settings( array( 'color', 'palette', 'theme' ) );
			foreach ( is_array( $palette ) ? $palette : array() as $entry ) {
				if ( is_array( $entry ) && isset( $entry['slug'], $entry['color'] ) && is_string( $entry['color'] ) ) {
					$theme[ (string) $entry['slug'] ] = $entry['color'];
				}
			}
		}
		foreach ( self::SLUGS as $name => $slugs ) {
			foreach ( $slugs as $slug ) {
				$color = isset( $theme[ $slug ] ) ? sanitize_hex_color( $theme[ $slug ] ) : '';
				if ( is_string( $color ) && '' !== $color ) {
					$colors[ $name ] = $color;
					break;
				}
			}
		}

		/**
		 * The colors the overview and the impact report use.
		 *
		 * @param array<string, string> $colors Color name to a CSS color.
		 */
		$filtered = apply_filters( 'packout_partner_palette', $colors );
		return is_array( $filtered ) ? array_merge( $colors, array_intersect_key( $filtered, $colors ) ) : $colors;
	}
}
