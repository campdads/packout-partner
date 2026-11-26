<?php
/**
 * Lists.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Names what draws a list of records.
 *
 * The library prints every list as plain rows, which is what a partner site
 * shows: a table in the dashboard and cards in the workspace. This adapter
 * names no script that would draw them another way, and keeps no columns for
 * a person.
 */
class Lists implements \PackOut\Core\Contracts\Lists {

	/**
	 * The scripts and styles of the runtime that draws lists.
	 *
	 * @return array{scripts: array<int, string>, styles: array<int, string>}
	 */
	public function assets(): array {
		return array(
			'scripts' => array(),
			'styles'  => array(),
		);
	}

	/**
	 * The columns a person kept for a list, or null when they kept none.
	 *
	 * @param string             $view   The list's name.
	 * @param array<int, string> $fields The list's columns.
	 * @return array<string, mixed>|null
	 */
	public function saved( string $view, array $fields ): ?array {
		unset( $view, $fields );
		return null;
	}
}
