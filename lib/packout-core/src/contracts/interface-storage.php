<?php
/**
 * Storage adapter.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Says which values of a record deserve a column of their own.
 */
interface Storage {

	/**
	 * Ask for these meta keys to be stored where they can be searched quickly.
	 *
	 * An adapter may do nothing. Post meta is always correct.
	 *
	 * @param string             $post_type Post type.
	 * @param array<int, string> $meta_keys Meta keys.
	 */
	public function columns( string $post_type, array $meta_keys ): void;
}
