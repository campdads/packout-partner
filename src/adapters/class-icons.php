<?php
/**
 * Icons.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies an icon by name.
 *
 * The icons are WordPress's own, from the `@wordpress/icons` package, kept as
 * files in `assets/icons/`. A name the plugin has no file for gives nothing.
 */
class Icons implements \PackOut\Core\Contracts\Icons {

	/**
	 * Icons already read, by name.
	 *
	 * @var array<string, string>
	 */
	private $read = array();

	/**
	 * An inline SVG.
	 *
	 * @param string $name    Icon name.
	 * @param string $classes Class attribute of the `svg` element.
	 */
	public function svg( string $name, string $classes = '' ): string {
		$name = sanitize_key( $name );
		if ( '' === $name ) {
			return '';
		}
		if ( ! isset( $this->read[ $name ] ) ) {
			$file                = dirname( PACKOUT_PARTNER_FILE ) . '/assets/icons/' . $name . '.svg';
			$this->read[ $name ] = is_readable( $file ) ? trim( (string) file_get_contents( $file ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin's own.
		}
		$svg = $this->read[ $name ];
		if ( 0 !== strpos( $svg, '<svg ' ) ) {
			return '';
		}
		// The files are the plugin's own. Only the class is written into them.
		$attributes = ' width="24" height="24" fill="currentColor" aria-hidden="true" focusable="false"';
		if ( '' !== trim( $classes ) ) {
			$attributes = ' class="' . esc_attr( trim( $classes ) ) . '"' . $attributes;
		}
		return '<svg' . $attributes . substr( $svg, 4 );
	}
}
