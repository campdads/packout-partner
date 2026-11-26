<?php
/**
 * What a page is drawn with.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

use PackOut\Core\Kernel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Components, the drawer, and the palette, as the application asks for them.
 *
 * A component is a badge, a notice, or an empty state. The plugin's screens
 * adapter draws it: on the hub with Vectra UI, on a partner site as plain
 * markup this library styles. The drawer is this library's own. The palette
 * is nine named colors the plugin supplies, printed as custom properties the
 * library's styles read.
 */
class Ui {

	/**
	 * The drawer script's handle.
	 */
	public const DRAWER = 'packout-drawer';

	/**
	 * The palette style's handle.
	 */
	public const PALETTE = 'packout-palette';

	/**
	 * The palette's fallback, in the order of `Kernel::COLORS`.
	 */
	private const FALLBACK = array( '#415840', '#25486b', '#bb5e28', '#863d14', '#ffffff', '#edeae5', '#c9c9c9', '#838585', '#2f3232' );

	/**
	 * Script handles a page that shows components depends on.
	 *
	 * @return array<int, string>
	 */
	public static function scripts(): array {
		return self::handles( 'scripts' );
	}

	/**
	 * Style handles a page that shows components depends on.
	 *
	 * @return array<int, string>
	 */
	public static function styles(): array {
		return self::handles( 'styles' );
	}

	/**
	 * Load what components need on this page.
	 */
	public static function enqueue(): void {
		foreach ( self::styles() as $handle ) {
			wp_enqueue_style( $handle );
		}
		foreach ( self::scripts() as $handle ) {
			wp_enqueue_script( $handle );
		}
	}

	/**
	 * Markup for one component.
	 *
	 * @param string               $id    Component: badge, notice, empty-state, search-control.
	 * @param array<string, mixed> $props What it shows.
	 */
	public static function component( string $id, array $props ): string {
		return App::kernel()->screens()->render( $id, $props, self::plain( $id, $props ) );
	}

	/**
	 * A component as plain markup: what shows where the plugin has nothing richer.
	 *
	 * @param string               $id    Component.
	 * @param array<string, mixed> $props What it shows.
	 */
	public static function plain( string $id, array $props ): string {
		$intent = sanitize_html_class( (string) ( $props['intent'] ?? 'none' ) );
		$title  = (string) ( $props['title'] ?? '' );
		$text   = (string) ( $props['text'] ?? $props['description'] ?? '' );
		if ( '' !== $title && isset( $props['text'] ) ) {
			$title = '';
		}
		$html = '<span class="packout-ui packout-ui--' . esc_attr( sanitize_html_class( $id ) ) . ' is-' . esc_attr( $intent ) . '" data-packout-ui="' . esc_attr( $id ) . '">';
		return $html . '<span class="packout-ui__fallback">' . ( '' !== $title ? '<strong>' . esc_html( $title ) . '</strong> ' : '' ) . esc_html( $text ) . '</span></span>';
	}

	/**
	 * The runtime that draws lists: its scripts and styles, as the lists adapter names them.
	 *
	 * @return array{scripts: array<int, string>, styles: array<int, string>}
	 */
	public static function lists(): array {
		static $found = null;
		if ( null === $found ) {
			$assets = App::kernel()->lists()->assets();
			$found  = array(
				'scripts' => array_values( array_filter( array_map( 'strval', (array) ( $assets['scripts'] ?? array() ) ) ) ),
				'styles'  => array_values( array_filter( array_map( 'strval', (array) ( $assets['styles'] ?? array() ) ) ) ),
			);
		}
		return $found;
	}

	/**
	 * An icon from the plugin's icons adapter.
	 *
	 * @param string $name    Icon name.
	 * @param string $classes Class attribute of the `svg` element.
	 */
	public static function icon( string $name, string $classes = '' ): string {
		return App::kernel()->icons()->svg( $name, $classes );
	}

	/**
	 * Register the drawer script and return its handle.
	 */
	public static function drawer(): string {
		if ( ! wp_script_is( self::DRAWER, 'registered' ) ) {
			wp_register_script( self::DRAWER, plugins_url( 'assets/js/drawer.js', PACKOUT_CORE_FILE ), array(), PACKOUT_CORE_VERSION, true );
		}
		return self::DRAWER;
	}

	/**
	 * The palette: color name to a CSS color, for the nine names in `Kernel::COLORS`.
	 *
	 * @return array<string, string>
	 */
	public static function colors(): array {
		$given  = App::kernel()->palette()->colors();
		$colors = array();
		foreach ( Kernel::COLORS as $index => $name ) {
			$color           = is_string( $given[ $name ] ?? null ) ? trim( $given[ $name ] ) : '';
			$colors[ $name ] = '' !== $color ? $color : self::FALLBACK[ $index ];
		}
		return $colors;
	}

	/**
	 * The palette as custom properties: `--packout-primary:#415840;` and the rest.
	 */
	public static function palette(): string {
		$css = '';
		foreach ( self::colors() as $name => $color ) {
			$css .= '--packout-' . str_replace( '_', '-', $name ) . ':' . $color . ';';
		}
		return $css;
	}

	/**
	 * The attributes of a block's outer element.
	 *
	 * While WordPress renders the block they are WordPress's, so what the
	 * editor set on the block applies. Anywhere else they are the classes
	 * alone: a page that draws the same markup itself has no block to ask.
	 *
	 * @param string $classes The element's own classes.
	 */
	public static function wrapper( string $classes ): string {
		if ( null !== \WP_Block_Supports::$block_to_render ) {
			return get_block_wrapper_attributes( array( 'class' => $classes ) );
		}
		return 'class="' . esc_attr( $classes ) . '"';
	}

	/**
	 * A style that carries nothing but the palette. The stylesheets that read the palette depend on it.
	 */
	public static function palette_style(): string {
		if ( ! wp_style_is( self::PALETTE, 'registered' ) ) {
			wp_register_style( self::PALETTE, false, array(), PACKOUT_CORE_VERSION );
			wp_add_inline_style( self::PALETTE, ':root{' . self::palette() . '}' );
		}
		return self::PALETTE;
	}

	/**
	 * Handles of one kind from the screens adapter.
	 *
	 * @param string $kind `scripts` or `styles`.
	 * @return array<int, string>
	 */
	private static function handles( string $kind ): array {
		$assets = App::kernel()->screens()->assets();
		return array_values( array_filter( array_map( 'strval', (array) ( $assets[ $kind ] ?? array() ) ) ) );
	}
}
