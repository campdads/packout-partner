<?php
/**
 * Whether a person may read a record.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks the plugin's access adapter.
 *
 * On the hub that is Vectra Toolkit, so a rule set in Toolkit Roles applies.
 * On a partner site it is the post's status and password.
 */
class Post_Access {

	/**
	 * Whether this person may read this post.
	 *
	 * @param mixed $post    The post, or its ID.
	 * @param int   $user_id User ID. Zero is a visitor.
	 */
	public static function can_read( $post, int $user_id ): bool {
		$post = is_int( $post ) ? get_post( $post ) : $post;
		return $post instanceof \WP_Post && App::kernel()->access()->can_read( $post->ID, $user_id );
	}
}
