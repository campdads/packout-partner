<?php
/**
 * Lists adapter.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies what draws a list of records in place of its plain rows.
 *
 * Every list is printed as plain rows first. A site that has a Data Views
 * runtime names its script here, and the page's own script hands each list to
 * `window.packoutLists.render( mount, config )`, which that script defines.
 * A site with none leaves both lists of handles empty, and the plain rows stay.
 */
interface Lists {

	/**
	 * The scripts and styles of the runtime.
	 *
	 * @return array{scripts: array<int, string>, styles: array<int, string>} Registered handles.
	 */
	public function assets(): array;

	/**
	 * The columns the current person arranged for one list.
	 *
	 * @param string             $view   The list's name, unique on the site.
	 * @param array<int, string> $fields The field ids the list has.
	 * @return array<string, mixed>|null Null when the person has arranged none.
	 */
	public function saved( string $view, array $fields ): ?array;
}
