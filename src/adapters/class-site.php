<?php
/**
 * The site.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes a partner site to the library.
 */
class Site implements \PackOut\Core\Contracts\Site {

	/**
	 * The plugin that carries the library.
	 */
	public function plugin(): string {
		return 'packout-partner';
	}

	/**
	 * The plugin's version.
	 */
	public function version(): string {
		return PACKOUT_PARTNER_VERSION;
	}

	/**
	 * Which side of the capability list this plugin is.
	 */
	public function surface(): string {
		return 'partner';
	}

	/**
	 * The last phase of the partner build this plugin has reached.
	 */
	public function phase(): int {
		return (int) PACKOUT_PARTNER_PHASE;
	}

	/**
	 * Whether this site is packout.spot. It never is.
	 */
	public function is_hub(): bool {
		return false;
	}

	/**
	 * The name people know the site by.
	 */
	public function name(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * The address of the site's mark: its site icon, or an empty string.
	 */
	public function mark(): string {
		return (string) get_site_icon_url( 192 );
	}

	/**
	 * The first part of a public path.
	 *
	 * @param string $kind One of `spot`, `team`, `mission`, `campaign`, `program`, `partner`, `field`.
	 */
	public function base( string $kind ): string {
		$bases = \PackOut\Partner\Settings::get( 'bases', \PackOut\Partner\Settings::BASES );
		if ( is_array( $bases ) && isset( $bases[ $kind ] ) && is_string( $bases[ $kind ] ) && '' !== $bases[ $kind ] ) {
			return $bases[ $kind ];
		}
		return \PackOut\Partner\Settings::BASES[ $kind ] ?? $kind;
	}
}
