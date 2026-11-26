<?php
/**
 * The pages a partner site's plugin can make from templates.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A partner site's public pages: made from a template, pointed at a page the
 * site already has, or left out.
 *
 * A page the plugin makes is an ordinary page. The plugin remembers which
 * template it came from, the template's version, and a hash of what it wrote,
 * so it can tell a page nobody has touched from one the site has edited. It
 * writes to a page only when an administrator presses a button on the Pages
 * screen, and never to one that was edited unless they choose Replace.
 *
 * The site's front page, its menus, and its theme are never set or changed.
 * The hub's pages are built and deployed from its own content, so none of
 * this runs there.
 */
class Pages {

	/**
	 * The option that maps a template's key to a page: its ID, or 0 for a page the site chose to skip.
	 */
	public const OPTION = 'packout_pages';

	/**
	 * Post meta of a page the plugin made: the template's key, its version, and the hash of what was written.
	 */
	public const KEY     = '_pos_template';
	public const VERSION = '_pos_template_version';
	public const HASH    = '_pos_template_hash';

	/**
	 * The settings that already name a page. The setting is where the application looks, so it is the truth for these.
	 */
	private const SETTINGS = array(
		'report'    => 'report_page',
		'sign-up'   => 'signup_page',
		'sign-in'   => 'signin_page',
		'workspace' => 'workspace_page',
		'account'   => 'account_page',
	);

	/**
	 * Register the block patterns. A partner site only.
	 */
	public static function register(): void {
		if ( App::is_hub() ) {
			return;
		}
		add_action( 'init', array( self::class, 'patterns' ), 20 );
	}

	/**
	 * The templates, in the order the wizard and the Pages screen list them.
	 *
	 * @return array<string, array{title: string, slug: string, version: int, parent: string, block: string, view: string, about: string, signed_in: bool}>
	 */
	public static function templates(): array {
		$list = array(
			'map'           => array( __( 'Map', 'packout-core' ), 'map', 1, '', 'packout/map', __( 'The map of Spots.', 'packout-core' ), false ),
			'report'        => array( __( 'Report a Spot', 'packout-core' ), 'report-a-spot', 1, '', 'packout/report-prompt', __( 'The report form. The Report prompt block links here.', 'packout-core' ), false ),
			'organizations' => array( __( 'Organizations', 'packout-core' ), 'organizations', 1, '', 'packout/teams', __( 'The list of organizations.', 'packout-core' ), false ),
			'calendar'      => array( __( 'Calendar', 'packout-core' ), 'calendar', 1, '', Calendar_View::BLOCK, __( 'Missions and campaigns by day, week, or month.', 'packout-core' ), false ),
			'impact'        => array( __( 'Impact', 'packout-core' ), 'impact', 1, '', 'packout/impact', __( 'The public totals.', 'packout-core' ), false ),
			'how'           => array( __( 'How it works', 'packout-core' ), 'how-it-works', 1, '', '', __( 'A short explanation, naming your partner.', 'packout-core' ), false ),
			'sign-up'       => array( __( 'Sign up', 'packout-core' ), 'sign-up', 1, '', 'packout/signup', __( 'Where a person makes an account.', 'packout-core' ), false ),
			'sign-in'       => array( __( 'Sign in', 'packout-core' ), 'sign-in', 1, '', 'packout/signin', __( 'Where a person signs in, signs out, and chooses a new password.', 'packout-core' ), false ),
			'workspace'     => array( __( 'My organizations', 'packout-core' ), 'my-organizations', 1, '', 'packout/workspace', __( 'Where people run their organizations, teams, and missions.', 'packout-core' ), true ),
			'account'       => array( __( 'My account', 'packout-core' ), 'my-account', 1, '', 'packout/account', __( 'A person\'s own account.', 'packout-core' ), true ),
		);
		foreach ( array(
			'personal'      => array( __( 'Personal info', 'packout-core' ), 'personal-info' ),
			'impact'        => array( __( 'My impact', 'packout-core' ), 'my-impact' ),
			'contributions' => array( __( 'My contributions', 'packout-core' ), 'my-contributions' ),
			'email'         => array( __( 'Email preferences', 'packout-core' ), 'email-preferences' ),
			'signin'        => array( __( 'Sign-in details', 'packout-core' ), 'sign-in-details' ),
		) as $view => $part ) {
			$list[ 'account-' . $view ] = array( $part[0], $part[1], 1, 'account', 'packout/account', __( 'One part of My account, on a page of its own.', 'packout-core' ), true, $view );
		}
		$out = array();
		foreach ( $list as $key => $row ) {
			$out[ $key ] = array(
				'title'     => $row[0],
				'slug'      => $row[1],
				'version'   => $row[2],
				'parent'    => $row[3],
				'block'     => $row[4],
				'about'     => $row[5],
				'signed_in' => $row[6],
				'view'      => (string) ( $row[7] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * A template's block markup, as it would be written now.
	 *
	 * @param string $key Template key.
	 */
	public static function content( string $key ): string {
		if ( ! isset( self::templates()[ $key ] ) ) {
			return '';
		}
		$file = dirname( __DIR__, 2 ) . '/templates/pages/' . $key . '.html';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin's own.
		$markup  = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		$partner = get_post( Partners::site() );
		$name    = $partner instanceof \WP_Post && '' !== $partner->post_title ? $partner->post_title : (string) get_bloginfo( 'name' );
		return trim( str_replace( '{{partner}}', esc_html( $name ), $markup ) );
	}

	/**
	 * The hash kept for a page's content. Line endings and outer space do not count as an edit.
	 *
	 * @param string $content Post content.
	 */
	public static function hash( string $content ): string {
		return md5( trim( str_replace( "\r\n", "\n", $content ) ) );
	}

	/**
	 * The page of each template: its ID, 0 when the site skipped it, or absent when it was never decided.
	 *
	 * @return array<string, int>
	 */
	public static function map(): array {
		$stored = get_option( self::OPTION, array() );
		$map    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $id ) {
			if ( isset( self::templates()[ $key ] ) ) {
				$map[ (string) $key ] = max( 0, (int) $id );
			}
		}
		foreach ( self::SETTINGS as $key => $setting ) {
			$id = (int) Settings::get( $setting );
			if ( $id > 0 ) {
				$map[ $key ] = $id;
			} elseif ( ! empty( $map[ $key ] ) ) {
				// The setting was emptied on the Settings screen: the page is no longer the one.
				unset( $map[ $key ] );
			}
		}
		return $map;
	}

	/**
	 * Point a template's key at a page, or at nothing.
	 *
	 * @param string   $key Template key.
	 * @param int|null $id  Page ID, 0 for skipped, null to forget the choice.
	 */
	private static function point( string $key, ?int $id ): void {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( null === $id ) {
			unset( $stored[ $key ] );
		} else {
			$stored[ $key ] = $id;
		}
		update_option( self::OPTION, $stored, false );
		if ( isset( self::SETTINGS[ $key ] ) ) {
			Settings::save( array( self::SETTINGS[ $key ] => (int) $id ) );
		}
	}

	/**
	 * The page of a template, when there is one and it is not in the trash.
	 *
	 * @param string $key Template key.
	 */
	public static function page( string $key ): int {
		$id   = (int) ( self::map()[ $key ] ?? 0 );
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && 'page' === $post->post_type && 'trash' !== $post->post_status ? $id : 0;
	}

	/**
	 * The address of a template's page, or of the site when it has none that is published.
	 *
	 * @param string $key Template key.
	 */
	public static function url( string $key ): string {
		$id = self::page( $key );
		return $id > 0 && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
	}

	/**
	 * How a template's page stands.
	 *
	 * `state` is one of:
	 * - `skipped`: never created, or the site chose to leave it out;
	 * - `unchanged`: the plugin made it, nobody edited it, and the template has not changed;
	 * - `update`: the plugin made it, nobody edited it, and the template is newer;
	 * - `edited`: the plugin made it and the site edited it. `newer` says whether the template has changed since;
	 * - `missing`: the page is in the trash or gone;
	 * - `yours`: the site pointed the key at a page of its own. `has_block` says whether it carries the block.
	 *
	 * @param string $key Template key.
	 * @return array{key: string, state: string, id: int, title: string, url: string, edit: string, newer: bool, has_block: bool, trashed: bool, status: string, decided: bool}
	 */
	public static function state( string $key ): array {
		$template = self::templates()[ $key ] ?? null;
		$map      = self::map();
		$decided  = array_key_exists( $key, $map );
		$id       = (int) ( $map[ $key ] ?? 0 );
		$out      = array(
			'key'       => $key,
			'state'     => 'skipped',
			'id'        => 0,
			'title'     => $template ? $template['title'] : '',
			'url'       => '',
			'edit'      => '',
			'newer'     => false,
			'has_block' => false,
			'trashed'   => false,
			'status'    => '',
			'decided'   => $decided,
		);
		if ( ! $template || $id <= 0 ) {
			return $out;
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
			return array(
				'state'   => 'missing',
				'id'      => $id,
				'trashed' => $post instanceof \WP_Post && 'trash' === $post->post_status,
			) + $out;
		}
		$out = array(
			'id'        => $id,
			'title'     => $post->post_title,
			'url'       => 'publish' === $post->post_status ? (string) get_permalink( $post ) : '',
			'edit'      => (string) get_edit_post_link( $id, 'raw' ),
			'status'    => $post->post_status,
			'has_block' => '' === $template['block'] || self::carries( $post->post_content, $template['block'], $template['view'] ),
		) + $out;
		if ( (string) get_post_meta( $id, self::KEY, true ) !== $key ) {
			return array( 'state' => 'yours' ) + $out;
		}
		$newer = (int) get_post_meta( $id, self::VERSION, true ) < $template['version'];
		if ( self::hash( $post->post_content ) !== (string) get_post_meta( $id, self::HASH, true ) ) {
			return array(
				'state' => 'edited',
				'newer' => $newer,
			) + $out;
		}
		return array(
			'state' => $newer ? 'update' : 'unchanged',
			'newer' => $newer,
		) + $out;
	}

	/**
	 * Every template's state.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function states(): array {
		$out = array();
		foreach ( array_keys( self::templates() ) as $key ) {
			$out[ $key ] = self::state( $key );
		}
		return $out;
	}

	/**
	 * Whether content carries a block, set to a view when one is named.
	 *
	 * @param string $content Post content.
	 * @param string $block   Block name.
	 * @param string $view    The block's `view`, for the parts of My account.
	 */
	public static function carries( string $content, string $block, string $view = '' ): bool {
		$find = static function ( array $blocks ) use ( &$find, $block, $view ): bool {
			foreach ( $blocks as $one ) {
				if ( ( $one['blockName'] ?? '' ) === $block && (string) ( $one['attrs']['view'] ?? '' ) === $view ) {
					return true;
				}
				if ( ! empty( $one['innerBlocks'] ) && $find( $one['innerBlocks'] ) ) {
					return true;
				}
			}
			return false;
		};
		return has_block( $block, $content ) && $find( parse_blocks( $content ) );
	}

	/**
	 * What already answers at an address a new page would take: a page, another kind of content, or one of the plugin's own first words.
	 *
	 * @param string $slug  The address's word.
	 * @param int    $under The page it would sit under.
	 * @return string What is there, in words, or nothing.
	 */
	public static function clash( string $slug, int $under = 0 ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed lookup, at the moment a page is made.
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'page' AND post_parent = %d AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) LIMIT 1", $slug, $under ) );
		if ( $found > 0 ) {
			/* translators: %s: a page's title. */
			return sprintf( __( 'the page “%s”', 'packout-core' ), get_the_title( $found ) );
		}
		if ( 0 === $under ) {
			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
				$base = is_array( $type->rewrite ) ? (string) ( $type->rewrite['slug'] ?? '' ) : '';
				if ( $slug === $base || $slug === $type->has_archive ) {
					/* translators: %s: a content type's name. */
					return sprintf( __( 'the addresses of “%s”', 'packout-core' ), $type->labels->name );
				}
			}
		}
		return '';
	}

	/**
	 * Make a template's page.
	 *
	 * A part of My account needs My account first. An address that is taken
	 * gets `pack-out-` in front, so nothing of the site's is shadowed.
	 *
	 * @param string $key Template key.
	 * @return int|\WP_Error The page.
	 */
	public static function create( string $key ) {
		$template = self::templates()[ $key ] ?? null;
		if ( ! $template ) {
			return new \WP_Error( 'packout_page_unknown', __( 'There is no such page to make.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! self::allowed() ) {
			return new \WP_Error( 'packout_forbidden', __( 'Only an administrator of this site can make its pages.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( self::page( $key ) > 0 ) {
			return new \WP_Error( 'packout_page_exists', __( 'That page already exists.', 'packout-core' ), array( 'status' => 409 ) );
		}
		$parent = 0;
		if ( '' !== $template['parent'] ) {
			$parent = self::page( $template['parent'] );
			if ( $parent <= 0 ) {
				/* translators: %s: a page's title. */
				return new \WP_Error( 'packout_page_parent', sprintf( __( 'Make “%s” first. This page sits under it.', 'packout-core' ), self::templates()[ $template['parent'] ]['title'] ), array( 'status' => 409 ) );
			}
		}
		$slug = $template['slug'];
		if ( '' !== self::clash( $slug, $parent ) ) {
			$slug = 'pack-out-' . $slug;
		}
		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => $template['title'],
				'post_name'      => $slug,
				'post_parent'    => $parent,
				'post_content'   => self::content( $key ),
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::stamp( (int) $id, $key );
		self::point( $key, (int) $id );
		return (int) $id;
	}

	/**
	 * Record what was written to a page: the template, its version, and the hash of the content as WordPress stored it.
	 *
	 * @param int    $id  Page ID.
	 * @param string $key Template key.
	 */
	private static function stamp( int $id, string $key ): void {
		update_post_meta( $id, self::KEY, $key );
		update_post_meta( $id, self::VERSION, (string) self::templates()[ $key ]['version'] );
		update_post_meta( $id, self::HASH, self::hash( (string) get_post_field( 'post_content', $id, 'raw' ) ) );
	}

	/**
	 * Whether the person asking may change the site's pages.
	 */
	private static function allowed(): bool {
		return ! App::is_hub() && current_user_can( 'manage_options' );
	}

	/**
	 * Do one thing to a template's page. Every button of the Pages screen and the wizard's Pages step comes here.
	 *
	 * - `create`: make it from the template;
	 * - `use`: point at `$page`, a page the site already has. Nothing is written to it;
	 * - `skip`: leave it out;
	 * - `update`: write the newer template, for a page nobody edited;
	 * - `keep`: keep an edited page as it is and stop offering the newer template;
	 * - `replace`: write the template over an edited page;
	 * - `draft`: make a draft copy of the template beside an edited page, to compare;
	 * - `restore`: take the page out of the trash;
	 * - `again`: make it again, when it is gone.
	 *
	 * @param string $key  Template key.
	 * @param string $act  What to do.
	 * @param int    $page For `use`, the page.
	 * @return int|true|\WP_Error The page made, or true.
	 */
	public static function act( string $key, string $act, int $page = 0 ) {
		$template = self::templates()[ $key ] ?? null;
		if ( ! $template ) {
			return new \WP_Error( 'packout_page_unknown', __( 'There is no such page.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! self::allowed() ) {
			return new \WP_Error( 'packout_forbidden', __( 'Only an administrator of this site can change its pages.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$state = self::state( $key );
		$wrong = new \WP_Error( 'packout_page_state', __( 'That cannot be done to the page as it stands now. The list below shows how it stands.', 'packout-core' ), array( 'status' => 409 ) );
		switch ( $act ) {
			case 'create':
				return 'skipped' === $state['state'] ? self::create( $key ) : $wrong;

			case 'again':
				if ( 'missing' !== $state['state'] ) {
					return $wrong;
				}
				self::point( $key, null );
				return self::create( $key );

			case 'use':
				$post = get_post( $page );
				if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
					return new \WP_Error( 'packout_page_choice', __( 'Choose a page of this site.', 'packout-core' ), array( 'status' => 422 ) );
				}
				if ( ! in_array( $state['state'], array( 'skipped', 'missing', 'yours' ), true ) ) {
					return $wrong;
				}
				self::point( $key, $post->ID );
				return true;

			case 'skip':
				if ( ! in_array( $state['state'], array( 'skipped', 'missing', 'yours' ), true ) ) {
					return $wrong;
				}
				self::point( $key, 0 );
				return true;

			case 'restore':
				if ( 'missing' !== $state['state'] || ! $state['trashed'] ) {
					return $wrong;
				}
				return wp_untrash_post( $state['id'] ) && wp_update_post(
					array(
						'ID'          => $state['id'],
						'post_status' => 'publish',
					)
				) ? true : $wrong;

			case 'update':
				return 'update' === $state['state'] ? self::write( $state['id'], $key ) : $wrong;

			case 'replace':
				return 'edited' === $state['state'] ? self::write( $state['id'], $key ) : $wrong;

			case 'keep':
				if ( 'edited' !== $state['state'] || ! $state['newer'] ) {
					return $wrong;
				}
				update_post_meta( $state['id'], self::VERSION, (string) $template['version'] );
				return true;

			case 'draft':
				if ( 'edited' !== $state['state'] ) {
					return $wrong;
				}
				$copy = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_status'  => 'draft',
						/* translators: %s: a page's title. */
						'post_title'   => sprintf( __( '%s (the template, to compare)', 'packout-core' ), $template['title'] ),
						'post_parent'  => (int) get_post_field( 'post_parent', $state['id'] ),
						'post_content' => self::content( $key ),
					),
					true
				);
				return is_wp_error( $copy ) ? $copy : (int) $copy;
		}//end switch
		return new \WP_Error( 'packout_page_act', __( 'That is not something the Pages screen does.', 'packout-core' ), array( 'status' => 400 ) );
	}

	/**
	 * Write the template to a page and record it.
	 *
	 * @param int    $id  Page ID.
	 * @param string $key Template key.
	 * @return true|\WP_Error
	 */
	private static function write( int $id, string $key ) {
		$saved = wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => self::content( $key ),
			),
			true
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		self::stamp( $id, $key );
		return true;
	}

	/**
	 * Register each template as a block pattern, for a site that builds its own pages.
	 */
	public static function patterns(): void {
		if ( ! function_exists( 'register_block_pattern' ) ) {
			return;
		}
		register_block_pattern_category( 'packout', array( 'label' => __( 'Pack-Out Spot', 'packout-core' ) ) );
		foreach ( self::templates() as $key => $template ) {
			if ( '' !== $template['parent'] ) {
				continue;
			}
			register_block_pattern(
				'packout/' . $key,
				array(
					'title'       => $template['title'],
					'description' => $template['about'],
					'categories'  => array( 'packout' ),
					'content'     => self::content( $key ),
				)
			);
		}
	}

	/**
	 * What deleting the plugin with "Remove all data" removes of the pages: the plugin's notes on them. The pages are the site's and stay.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
		foreach ( array( self::KEY, self::VERSION, self::HASH ) as $key ) {
			delete_post_meta_by_key( $key );
		}
	}
}
