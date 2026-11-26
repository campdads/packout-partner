<?php
/**
 * Access adapter.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether a person may read a record.
 */
interface Access {

	/**
	 * Whether this person may read this post.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID. Zero is a visitor.
	 */
	public function can_read( int $post_id, int $user_id ): bool;
}
