<?php
/**
 * The application's settings.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Names the option the application keeps its settings in, and reads and
 * writes them.
 *
 * These are the settings the hub has too: the map, the duplicate radius, the
 * pages. What only this plugin has, such as the update channel and the
 * address bases, is in `PackOut\Partner\Settings` and its own option.
 */
class Settings implements \PackOut\Core\Contracts\Settings {

	public const OPTION = 'packout_settings';

	/**
	 * The option the application's settings are kept in.
	 */
	public function option(): string {
		return self::OPTION;
	}

	/**
	 * Every setting.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return \PackOut\Core\App\Settings::all();
	}

	/**
	 * One setting.
	 *
	 * @param string $key      Setting name.
	 * @param mixed  $fallback Returned when the setting has no value.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		$value = \PackOut\Core\App\Settings::get( $key );
		return null === $value ? $fallback : $value;
	}

	/**
	 * Save settings. Names that are not given keep their value.
	 *
	 * @param array<string, mixed> $values Setting name to value.
	 */
	public function update( array $values ): bool {
		\PackOut\Core\App\Settings::save( $values );
		return true;
	}
}
