<?php
/**
 * The addresses of the site's records.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a site choose the first part of each public path.
 *
 * A site may already have a page called Team, or a post type called
 * `mission`. So the base of each path is a setting. A base that something
 * else on the site already uses is refused, with one that is free. Changing
 * a base forwards the old address to the new one.
 */
class Addresses {

	/**
	 * The option that maps a base the site used before to the kind of record it was for.
	 */
	public const OLD = 'packout_partner_old_bases';

	/**
	 * The option that says the rewrite rules are to be written again.
	 */
	public const FLUSH = 'packout_partner_flush_rules';

	/**
	 * Paths WordPress itself answers.
	 */
	private const RESERVED = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'page', 'comments', 'search', 'author', 'embed', 'attachment', 'trackback', 'date' );

	/**
	 * Follow a change of base.
	 */
	public static function register(): void {
		// After the record types are registered with the bases as they now are.
		add_action( 'init', array( self::class, 'flush' ), 99 );
		add_action( 'template_redirect', array( self::class, 'forward' ), 1 );
	}

	/**
	 * What each kind of record is called on the Settings screen.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'spot'     => __( 'Spot', 'packout-partner' ),
			'team'     => __( 'Organization', 'packout-partner' ),
			'mission'  => __( 'Mission', 'packout-partner' ),
			'campaign' => __( 'Campaign', 'packout-partner' ),
			'program'  => __( 'Program', 'packout-partner' ),
			'partner'  => __( 'Partner', 'packout-partner' ),
			'field'    => __( 'Field app', 'packout-partner' ),
		);
	}

	/**
	 * Change the bases.
	 *
	 * Each base is checked on its own. One that cannot be used keeps the
	 * value it had, and the rest are saved.
	 *
	 * @param array<string, mixed> $bases Kind of record to the base it should have.
	 * @return array<string, string> Kind of record to why its base was refused. Empty when every one was taken.
	 */
	public static function save( array $bases ): array {
		$checked = self::check( $bases );
		if ( Settings::get( 'bases' ) !== $checked['bases'] ) {
			Settings::update( array( 'bases' => $checked['bases'] ) );
		}
		return $checked['problems'];
	}

	/**
	 * The bases as they would be saved, and why any of them was refused.
	 *
	 * @param array<string, mixed> $bases Kind of record to the base it should have.
	 * @return array{bases: array<string, string>, problems: array<string, string>}
	 */
	public static function check( array $bases ): array {
		$now      = (array) Settings::get( 'bases', Settings::BASES );
		$next     = $now;
		$problems = array();
		foreach ( array_keys( Settings::BASES ) as $kind ) {
			if ( ! isset( $bases[ $kind ] ) || ! is_string( $bases[ $kind ] ) ) {
				continue;
			}
			$base = sanitize_title( $bases[ $kind ] );
			if ( ( $now[ $kind ] ?? '' ) === $base ) {
				continue;
			}
			$problem = self::problem( $kind, $base, $next );
			if ( '' !== $problem ) {
				$problems[ $kind ] = $problem;
				continue;
			}
			$next[ $kind ] = $base;
		}
		return array(
			'bases'    => $next,
			'problems' => $problems,
		);
	}

	/**
	 * Why a base cannot be used, or an empty string when it can.
	 *
	 * @param string                $kind  The kind of record.
	 * @param string                $base  The base, already cleaned.
	 * @param array<string, string> $bases The bases of every kind, as they would be.
	 */
	public static function problem( string $kind, string $base, array $bases ): string {
		if ( '' === $base ) {
			return __( 'Give it a word or two: letters, numbers, and hyphens.', 'packout-partner' );
		}
		$labels = self::labels();
		foreach ( $bases as $other => $taken ) {
			if ( $other !== $kind && $taken === $base ) {
				/* translators: 1: the address, 2: a kind of record, such as Mission. */
				return sprintf( __( '“%1$s” is the address of %2$s here.', 'packout-partner' ), $base, $labels[ $other ] ?? $other );
			}
		}
		$user = self::user_of( $base );
		if ( '' === $user ) {
			return '';
		}
		/* translators: 1: the address, 2: what uses it, such as "the page Team", 3: an address that is free. */
		return sprintf( __( '“%1$s” is already used by %2$s. “%3$s” is free.', 'packout-partner' ), $base, $user, self::suggest( $base, $bases ) );
	}

	/**
	 * What on this site already answers at a base, in words, or an empty string when nothing does.
	 *
	 * @param string $base The base.
	 */
	public static function user_of( string $base ): string {
		if ( in_array( $base, self::RESERVED, true ) ) {
			return __( 'WordPress', 'packout-partner' );
		}
		$page = get_page_by_path( $base, OBJECT, array( 'page' ) );
		if ( $page instanceof \WP_Post && 'trash' !== $page->post_status ) {
			/* translators: %s: a page's title. */
			return sprintf( __( 'the page “%s”', 'packout-partner' ), $page->post_title );
		}
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( \PackOut\Core\App\Schema::is_record( $type->name ) || ! is_post_type_viewable( $type ) || in_array( $type->name, array( 'post', 'page', 'attachment' ), true ) ) {
				continue;
			}
			$slug    = is_array( $type->rewrite ) && ! empty( $type->rewrite['slug'] ) ? (string) $type->rewrite['slug'] : $type->name;
			$archive = is_string( $type->has_archive ) ? $type->has_archive : '';
			if ( self::first( $slug ) === $base || self::first( $archive ) === $base ) {
				/* translators: %s: a kind of content, such as Products. */
				return sprintf( __( 'the content type “%s”', 'packout-partner' ), $type->labels->name );
			}
		}
		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public || ! is_array( $taxonomy->rewrite ) || empty( $taxonomy->rewrite['slug'] ) ) {
				continue;
			}
			if ( self::first( (string) $taxonomy->rewrite['slug'] ) === $base ) {
				/* translators: %s: a way of grouping content, such as Categories. */
				return sprintf( __( 'the grouping “%s”', 'packout-partner' ), $taxonomy->labels->name );
			}
		}
		return '';
	}

	/**
	 * A base close to the one asked for that nothing uses.
	 *
	 * @param string                $base  The base that is taken.
	 * @param array<string, string> $bases The bases of every kind.
	 */
	public static function suggest( string $base, array $bases = array() ): string {
		$try = 'pack-out-' . $base;
		$n   = 2;
		while ( in_array( $try, $bases, true ) || '' !== self::user_of( $try ) ) {
			$try = 'pack-out-' . $base . '-' . $n;
			++$n;
			if ( $n > 20 ) {
				break;
			}
		}
		return $try;
	}

	/**
	 * Remember the bases that changed, so their addresses forward, and ask for the rules to be written again.
	 *
	 * @param array<string, string> $before The bases before.
	 * @param array<string, string> $after  The bases after.
	 */
	public static function changed( array $before, array $after ): void {
		$stored = get_option( self::OLD, array() );
		$old    = is_array( $stored ) ? $stored : array();
		$moved  = false;
		foreach ( array_keys( Settings::BASES ) as $kind ) {
			$was = (string) ( $before[ $kind ] ?? Settings::BASES[ $kind ] );
			$is  = (string) ( $after[ $kind ] ?? Settings::BASES[ $kind ] );
			if ( $was !== $is ) {
				$old[ $was ] = $kind;
				$moved       = true;
			}
		}
		if ( ! $moved ) {
			return;
		}
		// A base in use forwards nowhere.
		foreach ( $after as $base ) {
			unset( $old[ $base ] );
		}
		update_option( self::OLD, $old, true );
		update_option( self::FLUSH, '1', true );
	}

	/**
	 * Write the rewrite rules again, once, after a base changed.
	 */
	public static function flush(): void {
		if ( '1' === get_option( self::FLUSH ) ) {
			delete_option( self::FLUSH );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Send an address under a base the site used before to the same address under the base it uses now.
	 */
	public static function forward(): void {
		if ( ! is_404() ) {
			return;
		}
		$stored = get_option( self::OLD, array() );
		if ( ! is_array( $stored ) || ! $stored ) {
			return;
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		$path  = ltrim( $path, '/' );
		$first = self::first( $path );
		$kind  = isset( $stored[ $first ] ) && is_string( $stored[ $first ] ) ? $stored[ $first ] : '';
		$bases = (array) Settings::get( 'bases', Settings::BASES );
		if ( '' === $first || '' === $kind || empty( $bases[ $kind ] ) || $bases[ $kind ] === $first ) {
			return;
		}
		wp_safe_redirect( home_url( '/' . $bases[ $kind ] . substr( $path, strlen( $first ) ) ), 301 );
		exit;
	}

	/**
	 * The first part of a path.
	 *
	 * @param string $path A path, with or without slashes around it.
	 */
	private static function first( string $path ): string {
		$parts = explode( '/', trim( $path, '/' ) );
		return (string) $parts[0];
	}
}
