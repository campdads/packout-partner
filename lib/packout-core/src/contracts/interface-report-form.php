<?php
/**
 * Report_Form adapter.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws the form a visitor reports a Spot with, and says how the page finds its fields.
 */
interface Report_Form {

	/**
	 * Whether the form can be shown on this site.
	 */
	public function available(): bool;

	/**
	 * Markup for the form.
	 *
	 * @param array<string, mixed> $args `title`: whether to show the form's own title. `before_submit`: markup to place
	 *                                   just before the form's button, already escaped.
	 * @return string HTML.
	 */
	public function render( array $args = array() ): string;

	/**
	 * How the page's script finds the form's parts.
	 *
	 * @return array{latitude: string, longitude: string, photo: string, row: string} CSS selectors inside the form: the
	 *         latitude input, the longitude input, the photo's file input, and the element that wraps one field. An
	 *         empty string where the form has no such part.
	 */
	public function selectors(): array;

	/**
	 * The largest photo the form's own photo field takes, in megabytes. Zero when the form sets none.
	 */
	public function photo_limit(): int;

	/**
	 * Whether the form is ready, for the setup checks.
	 *
	 * @return array{ok: bool, detail: string} `detail` says what is true or what to do next.
	 */
	public function status(): array;

	/**
	 * What the Settings screen shows for the form.
	 *
	 * @param array<string, mixed> $settings The application's settings.
	 * @return array{intro: string, rows: string} A sentence under the heading, and table rows (`<tr>`), both already
	 *         escaped. Empty strings when the form has nothing to choose.
	 */
	public function settings( array $settings ): array;

	/**
	 * What a coordinator is told about the form's own record of a report.
	 *
	 * A plugin whose form keeps each submission says where the rest of it is, such as the reporter's email. One
	 * whose form keeps nothing beyond the Spot returns an empty string.
	 *
	 * @param int $entry The number the form gave the report, which the plugin passed to `Spots::create()` as `entry_id`.
	 */
	public function entry_note( int $entry ): string;
}
