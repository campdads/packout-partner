<?php
/**
 * Read access, from WordPress.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether a person may read a record from its status and password,
 * the way WordPress does for any post.
 */
class Access implements \PackOut\Core\Contracts\Access {

	/**
	 * Whether this person may read this post.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID. Zero is a visitor.
	 */
	public function can_read( int $post_id, int $user_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( 'publish' === $post->post_status && '' === $post->post_password ) {
			return true;
		}
		return $user_id > 0 && user_can( $user_id, 'read_post', $post_id );
	}
}
