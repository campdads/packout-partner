<?php
/**
 * Settings adapter.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the plugin's settings.
 */
interface Settings {

	/**
	 * The name of the option the application's settings are kept in.
	 *
	 * The application reads, cleans, and writes that option itself. A plugin
	 * names it so that a site keeps the settings it has already saved.
	 */
	public function option(): string;

	/**
	 * Every setting.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array;

	/**
	 * One setting.
	 *
	 * @param string $key      Setting name.
	 * @param mixed  $fallback Returned when the setting has no value.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null );

	/**
	 * Save settings. Names that are not given keep their value.
	 *
	 * @param array<string, mixed> $values Setting name to value.
	 */
	public function update( array $values ): bool;
}
