<?php
/**
 * Storage.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps every value in post meta, which needs no setting up.
 */
class Storage implements \PackOut\Core\Contracts\Storage {

	/**
	 * Ask for these meta keys to be stored where they can be searched quickly.
	 *
	 * @param string             $post_type Post type.
	 * @param array<int, string> $meta_keys Meta keys.
	 */
	public function columns( string $post_type, array $meta_keys ): void {
		unset( $post_type, $meta_keys );
	}
}
