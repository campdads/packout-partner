<?php
/**
 * The Pages screen of a partner site.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where an administrator of a partner site sees the pages the plugin can
 * make, how each stands, and what can be done to it.
 *
 * Nothing here runs by itself. A page changes only when a button is pressed,
 * and an edited page only when the button says Replace.
 */
class Pages_Screen {

	public const PAGE = 'packout-pages';

	/**
	 * Add the screen and its form handler. A partner site only.
	 */
	public static function register(): void {
		if ( App::is_hub() ) {
			return;
		}
		add_action( 'admin_menu', array( self::class, 'menu' ), 11 );
		add_action( 'admin_post_packout_pages_action', array( self::class, 'action' ) );
	}

	/**
	 * Add the menu item.
	 */
	public static function menu(): void {
		Admin::add_screen( (string) add_submenu_page( 'packout-spot', __( 'Pages', 'packout-core' ), __( 'Pages', 'packout-core' ), 'manage_options', self::PAGE, array( self::class, 'render' ) ) );
	}

	/**
	 * What each state is called, and how its badge is colored.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function labels(): array {
		return array(
			'unchanged' => array( __( 'Unchanged', 'packout-core' ), 'success' ),
			'update'    => array( __( 'Update available', 'packout-core' ), 'info' ),
			'edited'    => array( __( 'Edited by you', 'packout-core' ), 'none' ),
			'missing'   => array( __( 'Missing', 'packout-core' ), 'warning' ),
			'yours'     => array( __( 'Yours', 'packout-core' ), 'none' ),
			'skipped'   => array( __( 'Not made', 'packout-core' ), 'none' ),
		);
	}

	/**
	 * What a state means, in a sentence.
	 *
	 * @param array<string, mixed> $state    The page's state.
	 * @param array<string, mixed> $template Its template.
	 */
	public static function meaning( array $state, array $template ): string {
		switch ( $state['state'] ) {
			case 'unchanged':
				return __( 'As the plugin made it.', 'packout-core' );
			case 'update':
				return __( 'As the plugin made it, and the template has changed since. Nothing is written until you press Update.', 'packout-core' );
			case 'edited':
				return $state['newer']
					? __( 'You have edited this page, and the template has changed since. Your page is left alone unless you choose Replace.', 'packout-core' )
					: __( 'You have edited this page. It is left alone.', 'packout-core' );
			case 'missing':
				return $state['trashed']
					? __( 'The page is in the trash.', 'packout-core' )
					: __( 'The page was deleted.', 'packout-core' );
			case 'yours':
				if ( $state['has_block'] ) {
					return __( 'A page of your own. The plugin never writes to it.', 'packout-core' );
				}
				/* translators: %s: a block's name, such as packout/map. */
				return sprintf( __( 'A page of your own. It does not carry the %s block, so it will not do this page\'s work until you add it.', 'packout-core' ), $template['block'] . ( '' !== $template['view'] ? ' (' . $template['view'] . ')' : '' ) );
		}
		return $state['decided']
			? __( 'You chose to leave this page out.', 'packout-core' )
			: __( 'Not made yet.', 'packout-core' );
	}

	/**
	 * Draw the screen.
	 */
	public static function render(): void {
		Admin::require( current_user_can( 'manage_options' ) );
		$templates = Pages::templates();
		$labels    = self::labels();

		echo '<div class="wrap packout-admin packout-pages" style="' . esc_attr( Admin::palette() ) . '">';
		echo '<h1>' . esc_html__( 'Pages', 'packout-core' ) . '</h1>';
		Admin::notice(
			array(
				'create'  => __( 'The page was made.', 'packout-core' ),
				'again'   => __( 'The page was made again.', 'packout-core' ),
				'use'     => __( 'That page is used now. Nothing was written to it.', 'packout-core' ),
				'skip'    => __( 'The page is left out.', 'packout-core' ),
				'update'  => __( 'The page was updated from the template.', 'packout-core' ),
				'replace' => __( 'The page was replaced with the template. Your earlier version is in the page\'s revisions.', 'packout-core' ),
				'keep'    => __( 'Your page is kept as it is.', 'packout-core' ),
				'draft'   => __( 'A draft copy of the template was made. It is in Pages, as a draft.', 'packout-core' ),
				'restore' => __( 'The page was taken out of the trash and published.', 'packout-core' ),
			)
		);
		echo '<p class="packout-admin__lede">' . esc_html__( 'These are the pages this plugin can make for you. Each is an ordinary page: edit it, rename it, move it, or build your own with the Pack-Out Spot blocks and patterns and point to it here. The plugin never changes your front page, your menus, or your theme, and an update of the plugin never changes a page.', 'packout-core' ) . '</p>';

		echo '<table class="widefat striped packout-pages__table"><thead><tr><th scope="col">' . esc_html__( 'Page', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'How it stands', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'Address', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'What you can do', 'packout-core' ) . '</th></tr></thead><tbody>';
		foreach ( Pages::states() as $key => $state ) {
			$template = $templates[ $key ];
			$label    = $labels[ $state['state'] ];
			echo '<tr data-pos-page="' . esc_attr( $key ) . '" data-pos-state="' . esc_attr( $state['state'] ) . '">';
			echo '<th scope="row"><strong>' . ( '' !== $template['parent'] ? '— ' : '' ) . esc_html( $template['title'] ) . '</strong><br /><span class="description">' . esc_html( $template['about'] ) . '</span></th>';
			echo '<td>' . Admin::badge( $label[0], $label[1] ) . '<br /><span class="description">' . esc_html( self::meaning( $state, $template ) ) . '</span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			echo '<td>';
			if ( '' !== $state['url'] ) {
				echo '<a href="' . esc_url( $state['url'] ) . '">' . esc_html( (string) wp_parse_url( $state['url'], PHP_URL_PATH ) ) . '</a>';
			} elseif ( $state['id'] > 0 && 'missing' !== $state['state'] ) {
				echo esc_html( (string) ( get_post_status_object( $state['status'] )->label ?? $state['status'] ) );
			} else {
				echo '<span aria-hidden="true">—</span>';
			}
			if ( '' !== $state['edit'] && 'missing' !== $state['state'] ) {
				echo '<br /><a href="' . esc_url( $state['edit'] ) . '">' . esc_html__( 'Edit', 'packout-core' ) . '</a>';
			}
			echo '</td><td class="packout-pages__acts">';
			self::acts( $key, $state );
			echo '</td></tr>';
		}//end foreach
		echo '</tbody></table></div>';
	}

	/**
	 * The buttons of one row.
	 *
	 * @param string               $key   Template key.
	 * @param array<string, mixed> $state The page's state.
	 */
	private static function acts( string $key, array $state ): void {
		switch ( $state['state'] ) {
			case 'skipped':
				self::button( $key, 'create', __( 'Make this page', 'packout-core' ), true );
				self::choose( $key );
				break;
			case 'update':
				self::button( $key, 'update', __( 'Update', 'packout-core' ), true );
				break;
			case 'edited':
				if ( $state['newer'] ) {
					self::button( $key, 'keep', __( 'Keep mine', 'packout-core' ) );
				}
				self::button( $key, 'draft', __( 'Make a draft copy to compare', 'packout-core' ) );
				self::button( $key, 'replace', __( 'Replace with the template', 'packout-core' ), false, __( 'Replace this page with the template? What you wrote stays in the page\'s revisions.', 'packout-core' ) );
				break;
			case 'missing':
				if ( $state['trashed'] ) {
					self::button( $key, 'restore', __( 'Take it out of the trash', 'packout-core' ), true );
				}
				self::button( $key, 'again', __( 'Make it again', 'packout-core' ), ! $state['trashed'] );
				self::choose( $key );
				self::button( $key, 'skip', __( 'Leave it out', 'packout-core' ) );
				break;
			case 'yours':
				self::choose( $key );
				self::button( $key, 'skip', __( 'Stop using this page', 'packout-core' ) );
				break;
		}//end switch
	}

	/**
	 * One button.
	 *
	 * @param string $key     Template key.
	 * @param string $act     What it does.
	 * @param string $label   What it says.
	 * @param bool   $primary Whether it is the row's main button.
	 * @param string $confirm A question to ask first.
	 */
	private static function button( string $key, string $act, string $label, bool $primary = false, string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-pages__act"' . ( '' !== $confirm ? ' data-pos-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		self::fields( $key, $act );
		echo '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * The form that points a template at a page the site already has.
	 *
	 * @param string $key Template key.
	 */
	private static function choose( string $key ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-pages__act packout-pages__act--choose">';
		self::fields( $key, 'use' );
		echo '<label class="screen-reader-text" for="packout-page-' . esc_attr( $key ) . '">' . esc_html__( 'A page you already have', 'packout-core' ) . '</label>';
		wp_dropdown_pages(
			array(
				'name'             => 'page',
				'id'               => 'packout-page-' . $key, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed word.
				'show_option_none' => esc_html__( 'A page you already have…', 'packout-core' ),
				'post_status'      => array( 'publish', 'draft', 'private' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed words.
			)
		);
		echo ' <button type="submit" class="button">' . esc_html__( 'Use it', 'packout-core' ) . '</button></form>';
	}

	/**
	 * The hidden fields of a form on this screen.
	 *
	 * @param string $key Template key.
	 * @param string $act What the form does.
	 */
	private static function fields( string $key, string $act ): void {
		echo '<input type="hidden" name="action" value="packout_pages_action" />';
		echo '<input type="hidden" name="key" value="' . esc_attr( $key ) . '" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $act ) . '" />';
		wp_nonce_field( 'packout_pages', '_wpnonce', false );
	}

	/**
	 * Handle a form of this screen.
	 */
	public static function action(): void {
		Admin::require( ! App::is_hub() && current_user_can( 'manage_options' ) );
		check_admin_referer( 'packout_pages' );
		$key    = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$act    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$result = Pages::act( $key, $act, isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 0 );
		if ( is_wp_error( $result ) ) {
			Admin::back(
				self::PAGE,
				array(
					'error'  => 'detail',
					'detail' => $result->get_error_message(),
				)
			);
		}
		Admin::back( self::PAGE, array( 'updated' => $act ) );
	}
}
