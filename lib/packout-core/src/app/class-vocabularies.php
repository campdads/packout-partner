<?php
/**
 * Choosable options as taxonomies, seeded in code and read as terms.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every select, radio, checkbox list, and list filter reads these terms.
 *
 * Code registers the taxonomies and inserts missing seed terms. It does not
 * stay behind the controls. On the hub the same definitions are merged into
 * Content Types so order, REST, and featured images stay with the collection.
 */
class Vocabularies {

	public const CATEGORY       = 'pos_category';
	public const STAGE          = 'pos_stage';
	public const HAZARD         = 'pos_hazard';
	public const LOCATION_KIND  = 'pos_location_kind';
	public const PRECISION      = 'pos_precision';
	public const PLACE_KIND     = 'pos_place_kind';
	public const FEATURE_KIND   = 'pos_feature_kind';
	public const FEATURE_ICON   = 'pos_feature_icon';
	public const FEATURE_COLOR  = 'pos_feature_color';
	public const OVERLAY        = 'pos_overlay';
	public const GROUP_TYPE     = 'pos_group_type';
	public const PROGRAM_TYPE   = 'pos_program_type';
	public const JOIN           = 'pos_join';
	public const ORG_STATE      = 'pos_org_state';
	public const PARTNER_STATE  = 'pos_partner_state';
	public const PROGRAM_STATE  = 'pos_program_state';
	public const MISSION_STATUS = 'pos_mission_status';
	public const OUTCOME        = 'pos_outcome';
	public const IMPACT_KIND    = 'pos_impact_kind';
	public const IMPACT_SOURCE  = 'pos_impact_source';
	public const VERIFICATION   = 'pos_verification';

	/**
	 * Overlay source keys the map already draws.
	 */
	public const OVERLAY_SOURCES = array( 'roads', 'trails', 'points', 'mvum', 'nfstrails', 'forests', 'wilderness' );

	/**
	 * Register the taxonomy hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'types' ), 9 );
		add_action( 'init', array( self::class, 'place_menus' ), 12 );
		add_action( 'pre_delete_term', array( self::class, 'protect' ), 1, 2 );
	}

	/**
	 * Register each taxonomy this site keeps.
	 */
	public static function types(): void {
		foreach ( self::definitions() as $key => $def ) {
			if ( taxonomy_exists( $key ) ) {
				continue;
			}
			register_taxonomy( $key, $def['objects'], self::arguments( $def ) );
		}
	}

	/**
	 * Keep each taxonomy under Pack Out after Content Types syncs it.
	 */
	public static function place_menus(): void {
		foreach ( array_keys( self::definitions() ) as $key ) {
			$tax = get_taxonomy( $key );
			if ( $tax instanceof \WP_Taxonomy ) {
				$tax->show_ui      = true;
				$tax->show_in_menu = 'packout-spot';
				$tax->show_in_rest = true;
			}
		}
	}

	/**
	 * Insert missing seed terms and assign terms from stored meta.
	 */
	public static function install(): void {
		if ( ! taxonomy_exists( self::CATEGORY ) ) {
			self::types();
		}
		foreach ( self::definitions() as $key => $def ) {
			$order = 0;
			foreach ( $def['seeds'] as $seed ) {
				++$order;
				$term = get_term_by( 'slug', $seed['slug'], $key );
				if ( ! $term instanceof \WP_Term ) {
					$created = wp_insert_term(
						$seed['name'],
						$key,
						array(
							'slug'        => $seed['slug'],
							'description' => (string) ( $seed['description'] ?? '' ),
						)
					);
					if ( is_wp_error( $created ) ) {
						continue;
					}
					$term_id = (int) $created['term_id'];
				} else {
					$term_id = (int) $term->term_id;
				}
				foreach ( $seed['meta'] ?? array() as $meta_key => $meta_value ) {
					if ( '' === (string) get_term_meta( $term_id, $meta_key, true ) ) {
						update_term_meta( $term_id, $meta_key, $meta_value );
					}
				}
				if ( 0 === (int) get_term_meta( $term_id, 'pos_term_order', true ) ) {
					update_term_meta( $term_id, 'pos_term_order', $order );
				}
			}//end foreach
		}//end foreach
		self::migrate();
	}

	/**
	 * Assign a term from the value already stored, and keep writing the old meta.
	 */
	public static function migrate(): void {
		$maps = array(
			array( Schema::SPOT, self::STAGE, '_pos_status' ),
			array( Schema::SPOT, self::HAZARD, '_pos_hazard' ),
			array( Schema::SPOT, self::LOCATION_KIND, '_pos_location_kind' ),
			array( Schema::SPOT, self::PRECISION, '_pos_precision' ),
			array( Schema::PLACE, self::PLACE_KIND, '_pos_kind' ),
			array( Schema::FEATURE, self::FEATURE_KIND, '_pos_kind' ),
			array( Schema::FEATURE, self::FEATURE_COLOR, '_pos_color' ),
			array( Schema::TEAM, self::JOIN, '_pos_join' ),
			array( Schema::TEAM, self::ORG_STATE, '_pos_state' ),
			array( Schema::PARTNER, self::PARTNER_STATE, '_pos_state' ),
			array( Schema::PROGRAM, self::PROGRAM_STATE, '_pos_state' ),
			array( Schema::MISSION, self::MISSION_STATUS, '_pos_status' ),
			array( Schema::IMPACT, self::IMPACT_KIND, '_pos_kind' ),
			array( Schema::IMPACT, self::VERIFICATION, '_pos_verification' ),
		);
		foreach ( $maps as $map ) {
			self::assign_from_meta( $map[0], $map[1], $map[2] );
		}
		self::assign_feature_icons();
		self::assign_mission_outcomes();
		self::assign_impact_sources();
		self::write_category_done_words();
	}

	/**
	 * Refuse to delete a seed the lifecycle is not allowed to lose.
	 *
	 * @param int    $term     Term ID.
	 * @param string $taxonomy Taxonomy key.
	 * @throws \RuntimeException When WP-CLI tries to delete a seed.
	 */
	public static function protect( $term, $taxonomy ): void {
		$taxonomy = is_string( $taxonomy ) ? $taxonomy : '';
		if ( '' === $taxonomy || ! isset( self::definitions()[ $taxonomy ] ) ) {
			return;
		}
		$row = get_term( (int) $term, $taxonomy );
		if ( ! $row instanceof \WP_Term || ! self::is_protected( $taxonomy, $row->slug ) ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			throw new \RuntimeException( 'packout_protected_term' );
		}
		wp_die(
			esc_html__( 'That term cannot be deleted.', 'packout-core' ),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * Whether this slug is one the product branches on.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @param string $slug     Term slug.
	 */
	public static function is_protected( string $taxonomy, string $slug ): bool {
		$def = self::definitions()[ $taxonomy ] ?? null;
		return is_array( $def ) && in_array( $slug, $def['protected'], true );
	}

	/**
	 * Labels keyed by slug, from terms when the taxonomy is registered.
	 *
	 * Screens that offer a choice call this only after install. Tests that
	 * boot without a full install still get the seed list.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @return array<string, string>
	 */
	public static function labels( string $taxonomy ): array {
		if ( taxonomy_exists( $taxonomy ) ) {
			$labels = array();
			foreach ( self::terms( $taxonomy ) as $term ) {
				$labels[ $term->slug ] = $term->name;
			}
			if ( $labels ) {
				return $labels;
			}
		}
		return self::seed_labels( $taxonomy );
	}

	/**
	 * Terms in seed order, then any added terms.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @return array<int, \WP_Term>
	 */
	public static function terms( string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		usort(
			$terms,
			static function ( $left, $right ) {
				$left_order  = (int) get_term_meta( $left->term_id, 'pos_term_order', true );
				$right_order = (int) get_term_meta( $right->term_id, 'pos_term_order', true );
				if ( $left_order === $right_order ) {
					return $left->term_id <=> $right->term_id;
				}
				if ( 0 === $left_order ) {
					return 1;
				}
				if ( 0 === $right_order ) {
					return -1;
				}
				return $left_order <=> $right_order;
			}
		);
		return $terms;
	}

	/**
	 * Seed labels keyed by slug, for a boot that has not registered the taxonomy.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @return array<string, string>
	 */
	public static function seed_labels( string $taxonomy ): array {
		$labels = array();
		foreach ( self::definitions()[ $taxonomy ]['seeds'] ?? array() as $seed ) {
			$labels[ $seed['slug'] ] = $seed['name'];
		}
		return $labels;
	}

	/**
	 * Assign a term and, for this release, keep writing the matching meta.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy key.
	 * @param string $slug     Term slug.
	 * @param string $meta_key Meta key to dual-write, or empty to skip.
	 */
	public static function assign( int $post_id, string $taxonomy, string $slug, string $meta_key = '' ): void {
		if ( $post_id <= 0 || '' === $taxonomy ) {
			return;
		}
		if ( '' === $slug ) {
			wp_set_object_terms( $post_id, array(), $taxonomy );
			return;
		}
		if ( ! term_exists( $slug, $taxonomy ) ) {
			return;
		}
		wp_set_object_terms( $post_id, $slug, $taxonomy );
		if ( '' !== $meta_key ) {
			update_post_meta( $post_id, $meta_key, $slug );
		}
	}

	/**
	 * The assigned slug, or empty.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy key.
	 */
	public static function slug( int $post_id, string $taxonomy ): string {
		$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
		return is_array( $terms ) && isset( $terms[0] ) && is_string( $terms[0] ) ? $terms[0] : '';
	}

	/**
	 * The assigned term, or null.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy key.
	 */
	public static function term_of( int $post_id, string $taxonomy ): ?\WP_Term {
		$terms = wp_get_object_terms( $post_id, $taxonomy );
		return is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ? $terms[0] : null;
	}

	/**
	 * Insert a term when the slug is new, so a partner listing can become a filter.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @param string $slug     Term slug.
	 * @param string $name     Term name when inserting.
	 */
	public static function ensure_term( string $taxonomy, string $slug, string $name = '' ): ?\WP_Term {
		$slug = sanitize_title( $slug );
		if ( '' === $taxonomy || '' === $slug || ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( $term instanceof \WP_Term ) {
			return $term;
		}
		$created = wp_insert_term( '' !== $name ? $name : $slug, $taxonomy, array( 'slug' => $slug ) );
		if ( is_wp_error( $created ) ) {
			return null;
		}
		$term = get_term( (int) $created['term_id'], $taxonomy );
		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * Slug and name for a listing field, or null when none is set.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy key.
	 * @return array{slug: string, name: string}|null
	 */
	public static function listing_type( int $post_id, string $taxonomy ): ?array {
		$term = self::term_of( $post_id, $taxonomy );
		if ( ! $term ) {
			return null;
		}
		return array(
			'slug' => substr( $term->slug, 0, 60 ),
			'name' => mb_substr( $term->name, 0, 80 ),
		);
	}

	/**
	 * What a location kind stores: pin, landmark, or note.
	 *
	 * @param string $slug Term slug.
	 */
	public static function location_shape( string $slug ): string {
		$shape = self::term_meta( self::LOCATION_KIND, $slug, 'pos_location_shape' );
		if ( in_array( $shape, array( 'pin', 'landmark', 'note' ), true ) ) {
			return $shape;
		}
		$seeds = array(
			'pin'         => 'pin',
			'area'        => 'note',
			'landmark'    => 'landmark',
			'description' => 'note',
		);
		return $seeds[ $slug ] ?? '';
	}

	/**
	 * Geometry of a feature kind: point, line, or polygon.
	 *
	 * @param string $slug Term slug.
	 */
	public static function feature_geometry( string $slug ): string {
		$geometry = self::term_meta( self::FEATURE_KIND, $slug, 'pos_geometry' );
		if ( in_array( $geometry, array( 'point', 'line', 'polygon' ), true ) ) {
			return $geometry;
		}
		$seeds = array(
			'point'    => 'point',
			'route'    => 'line',
			'boundary' => 'polygon',
		);
		return $seeds[ $slug ] ?? '';
	}

	/**
	 * Internal kind a feature drawing uses: point, route, or boundary.
	 *
	 * @param string $slug Term slug.
	 */
	public static function feature_draw_kind( string $slug ): string {
		$geometry = self::feature_geometry( $slug );
		if ( 'line' === $geometry ) {
			return Features::KIND_ROUTE;
		}
		if ( 'polygon' === $geometry ) {
			return Features::KIND_BOUNDARY;
		}
		return Features::KIND_POINT;
	}

	/**
	 * Overlay source key the map already draws, or empty.
	 *
	 * @param string $slug Term slug.
	 */
	public static function overlay_source( string $slug ): string {
		$source = self::term_meta( self::OVERLAY, $slug, 'pos_source' );
		return in_array( $source, self::OVERLAY_SOURCES, true ) ? $source : ( in_array( $slug, self::OVERLAY_SOURCES, true ) ? $slug : '' );
	}

	/**
	 * Whether this hazard blocks cleanup until a coordinator acts.
	 *
	 * An added hazard is choosable and does not block.
	 *
	 * @param string $slug Term slug.
	 */
	public static function hazard_blocks( string $slug ): bool {
		return in_array( $slug, array( 'do_not_touch', 'needs_authorization' ), true );
	}

	/**
	 * How an added or known join policy behaves: open, closed, or request.
	 *
	 * @param string $slug Term slug.
	 */
	public static function join_behavior( string $slug ): string {
		return in_array( $slug, array( 'open', 'closed', 'request' ), true ) ? $slug : 'request';
	}

	/**
	 * Hazard guidance: the term description, or the seed text.
	 *
	 * @param string $slug Term slug.
	 */
	public static function hazard_guidance( string $slug ): string {
		$term = get_term_by( 'slug', $slug, self::HAZARD );
		if ( $term instanceof \WP_Term && '' !== trim( $term->description ) ) {
			return $term->description;
		}
		foreach ( self::definitions()[ self::HAZARD ]['seeds'] ?? array() as $seed ) {
			if ( $seed['slug'] === $slug ) {
				return (string) ( $seed['description'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * The word for finished work on a category.
	 *
	 * @param string $slug Category slug.
	 */
	public static function done_word( string $slug ): string {
		$word = self::term_meta( self::CATEGORY, $slug, 'pos_done_word' );
		if ( '' !== $word ) {
			return $word;
		}
		$words = self::done_words();
		return $words[ $slug ] ?? $words['cleanup-waste'] ?? __( 'Cleaned', 'packout-core' );
	}

	/**
	 * Completion words keyed by category slug.
	 *
	 * @return array<string, string>
	 */
	public static function done_words(): array {
		$words = array();
		foreach ( self::terms( self::CATEGORY ) as $term ) {
			$word                 = (string) get_term_meta( $term->term_id, 'pos_done_word', true );
			$words[ $term->slug ] = '' !== $word ? $word : __( 'Cleaned', 'packout-core' );
		}
		if ( $words ) {
			return $words;
		}
		$fallback = array();
		foreach ( self::definitions()[ self::CATEGORY ]['seeds'] ?? array() as $seed ) {
			$fallback[ $seed['slug'] ] = (string) ( $seed['meta']['pos_done_word'] ?? __( 'Cleaned', 'packout-core' ) );
		}
		return $fallback;
	}

	/**
	 * Hex colors keyed for the feature palette.
	 *
	 * @return array<int, array{name: string, slug: string, color: string}>
	 */
	public static function color_palette(): array {
		$rows = array();
		foreach ( self::terms( self::FEATURE_COLOR ) as $term ) {
			$hex = (string) get_term_meta( $term->term_id, 'pos_hex', true );
			if ( 1 !== preg_match( '/^#[0-9a-f]{6}$/i', $hex ) ) {
				continue;
			}
			$rows[] = array(
				'name'  => $term->name,
				'slug'  => $term->slug,
				'color' => strtolower( $hex ),
			);
		}
		if ( $rows ) {
			return $rows;
		}
		$fallback = array();
		foreach ( self::definitions()[ self::FEATURE_COLOR ]['seeds'] ?? array() as $seed ) {
			$fallback[] = array(
				'name'  => $seed['name'],
				'slug'  => $seed['slug'],
				'color' => (string) ( $seed['meta']['pos_hex'] ?? '' ),
			);
		}
		return $fallback;
	}

	/**
	 * Material icon id stored on a point-icon term, or empty.
	 *
	 * @param string $slug Term slug.
	 */
	public static function icon_id( string $slug ): string {
		return self::term_meta( self::FEATURE_ICON, $slug, 'pos_icon_id' );
	}

	/**
	 * Point-icon term slug for a catalog id.
	 *
	 * @param string $icon_id Material icon id.
	 */
	public static function icon_slug( string $icon_id ): string {
		if ( '' === $icon_id ) {
			return '';
		}
		foreach ( self::terms( self::FEATURE_ICON ) as $term ) {
			if ( (string) get_term_meta( $term->term_id, 'pos_icon_id', true ) === $icon_id ) {
				return $term->slug;
			}
		}
		return sanitize_title( str_replace( ':', '-', $icon_id ) );
	}

	/**
	 * Overlay rows the map menu draws, in term order.
	 *
	 * @return array<int, array{slug: string, name: string, source: string, group: string, note: string, on: bool}>
	 */
	public static function overlays(): array {
		$rows = array();
		foreach ( self::terms( self::OVERLAY ) as $term ) {
			$source = (string) get_term_meta( $term->term_id, 'pos_source', true );
			if ( ! in_array( $source, self::OVERLAY_SOURCES, true ) ) {
				continue;
			}
			$rows[] = array(
				'slug'   => $term->slug,
				'name'   => $term->name,
				'source' => $source,
				'group'  => (string) get_term_meta( $term->term_id, 'pos_group', true ),
				'note'   => (string) get_term_meta( $term->term_id, 'pos_note', true ),
				'on'     => '1' === (string) get_term_meta( $term->term_id, 'pos_default_on', true ),
			);
		}
		if ( $rows ) {
			return $rows;
		}
		$fallback = array();
		foreach ( self::definitions()[ self::OVERLAY ]['seeds'] ?? array() as $seed ) {
			$fallback[] = array(
				'slug'   => $seed['slug'],
				'name'   => $seed['name'],
				'source' => (string) ( $seed['meta']['pos_source'] ?? $seed['slug'] ),
				'group'  => (string) ( $seed['meta']['pos_group'] ?? '' ),
				'note'   => (string) ( $seed['meta']['pos_note'] ?? '' ),
				'on'     => '1' === (string) ( $seed['meta']['pos_default_on'] ?? '' ),
			);
		}
		return $fallback;
	}

	/**
	 * Feature-kind rows the Contributions menu draws.
	 *
	 * @return array<int, array{slug: string, name: string, geometry: string, on: bool}>
	 */
	public static function feature_kinds(): array {
		$rows = array();
		foreach ( self::terms( self::FEATURE_KIND ) as $term ) {
			$geometry = (string) get_term_meta( $term->term_id, 'pos_geometry', true );
			if ( ! in_array( $geometry, array( 'point', 'line', 'polygon' ), true ) ) {
				continue;
			}
			$rows[] = array(
				'slug'     => $term->slug,
				'name'     => $term->name,
				'geometry' => $geometry,
				'on'       => '1' === (string) get_term_meta( $term->term_id, 'pos_default_on', true ),
			);
		}
		if ( $rows ) {
			return $rows;
		}
		$fallback = array();
		foreach ( self::definitions()[ self::FEATURE_KIND ]['seeds'] ?? array() as $seed ) {
			$fallback[] = array(
				'slug'     => $seed['slug'],
				'name'     => $seed['name'],
				'geometry' => (string) ( $seed['meta']['pos_geometry'] ?? 'point' ),
				'on'       => false,
			);
		}
		return $fallback;
	}

	/**
	 * The 21 vocabularies this site registers: hub-only keys drop off a partner site.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$spot    = array( Schema::SPOT );
		$team    = array( Schema::TEAM );
		$program = array( Schema::PROGRAM );
		$mission = array( Schema::MISSION );
		$impact  = array( Schema::IMPACT );
		$all     = array(
			self::CATEGORY       => array(
				'singular'       => __( 'Category', 'packout-core' ),
				'plural'         => __( 'Categories', 'packout-core' ),
				'objects'        => $spot,
				'admin_column'   => true,
				'featured_image' => false,
				'protected'      => array( 'cleanup-waste', 'campsites-recreation', 'trails-access', 'land-habitat', 'waterways', 'facilities-infrastructure' ),
				'seeds'          => array(
					self::seed( 'cleanup-waste', __( 'Cleanup and waste', 'packout-core' ), array( 'pos_done_word' => __( 'Cleaned', 'packout-core' ) ) ),
					self::seed( 'campsites-recreation', __( 'Campsites and recreation', 'packout-core' ), array( 'pos_done_word' => __( 'Cleaned', 'packout-core' ) ) ),
					self::seed( 'trails-access', __( 'Trails and access', 'packout-core' ), array( 'pos_done_word' => __( 'Cleared', 'packout-core' ) ) ),
					self::seed( 'land-habitat', __( 'Land and habitat', 'packout-core' ), array( 'pos_done_word' => __( 'Restored', 'packout-core' ) ) ),
					self::seed( 'waterways', __( 'Waterways', 'packout-core' ), array( 'pos_done_word' => __( 'Cleared', 'packout-core' ) ) ),
					self::seed( 'facilities-infrastructure', __( 'Facilities and infrastructure', 'packout-core' ), array( 'pos_done_word' => __( 'Repaired', 'packout-core' ) ) ),
				),
			),
			self::STAGE          => array(
				'singular'     => __( 'Stage', 'packout-core' ),
				'plural'       => __( 'Stages', 'packout-core' ),
				'objects'      => $spot,
				'admin_column' => true,
				'protected'    => array( 'spotted', 'planned', 'in_progress', 'pending', 'cleaned', 'watch' ),
				'seeds'        => array(
					self::seed( 'spotted', __( 'Spotted', 'packout-core' ) ),
					self::seed( 'planned', __( 'Planned', 'packout-core' ) ),
					self::seed( 'in_progress', __( 'In progress', 'packout-core' ) ),
					self::seed( 'pending', __( 'Cleanup reported', 'packout-core' ) ),
					self::seed( 'cleaned', __( 'Cleaned', 'packout-core' ) ),
					self::seed( 'watch', __( 'Watch', 'packout-core' ) ),
				),
			),
			self::HAZARD         => array(
				'singular'     => __( 'Hazard', 'packout-core' ),
				'plural'       => __( 'Hazards', 'packout-core' ),
				'objects'      => $spot,
				'admin_column' => true,
				'protected'    => array( 'do_not_touch', 'needs_authorization' ),
				'seeds'        => array(
					self::seed( 'none', __( 'No hazard noted', 'packout-core' ) ),
					self::seed(
						'do_not_touch',
						__( 'Do not touch', 'packout-core' ),
						array(),
						__( 'Leave this material where it is. Do not move, open, or pack it out. A coordinator will decide what happens next. If anyone is in danger, call 911 or the local land management office.', 'packout-core' )
					),
					self::seed(
						'needs_authorization',
						__( 'Needs authorization', 'packout-core' ),
						array(),
						__( 'This work needs permission from the land manager before anyone starts. Leave it in place and let a coordinator arrange it.', 'packout-core' )
					),
				),
			),
			self::LOCATION_KIND  => array(
				'singular'  => __( 'Location kind', 'packout-core' ),
				'plural'    => __( 'Location kinds', 'packout-core' ),
				'objects'   => $spot,
				'protected' => array( 'pin', 'area', 'landmark', 'description' ),
				'seeds'     => array(
					self::seed( 'pin', __( 'Pin', 'packout-core' ), array( 'pos_location_shape' => 'pin' ) ),
					self::seed( 'area', __( 'Area', 'packout-core' ), array( 'pos_location_shape' => 'note' ) ),
					self::seed( 'landmark', __( 'Landmark', 'packout-core' ), array( 'pos_location_shape' => 'landmark' ) ),
					self::seed( 'description', __( 'Description only', 'packout-core' ), array( 'pos_location_shape' => 'note' ) ),
				),
			),
			self::PRECISION      => array(
				'singular'  => __( 'Precision', 'packout-core' ),
				'plural'    => __( 'Precision', 'packout-core' ),
				'objects'   => $spot,
				'protected' => array( 'exact', 'coarse' ),
				'seeds'     => array(
					self::seed( 'exact', __( 'Exact', 'packout-core' ) ),
					self::seed( 'coarse', __( 'Approximate', 'packout-core' ) ),
				),
			),
			self::OVERLAY        => array(
				'singular'  => __( 'Map overlay', 'packout-core' ),
				'plural'    => __( 'Map overlays', 'packout-core' ),
				'objects'   => array(),
				'protected' => array(),
				'seeds'     => array(
					self::seed(
						'roads',
						__( 'Roads', 'packout-core' ),
						array(
							'pos_source'     => 'roads',
							'pos_group'      => __( 'OpenFreeMap', 'packout-core' ),
							'pos_default_on' => '1',
						)
					),
					self::seed(
						'trails',
						__( 'Trails', 'packout-core' ),
						array(
							'pos_source'     => 'trails',
							'pos_group'      => __( 'OpenFreeMap', 'packout-core' ),
							'pos_default_on' => '1',
						)
					),
					self::seed(
						'points',
						__( 'Existing points of interest', 'packout-core' ),
						array(
							'pos_source'     => 'points',
							'pos_group'      => __( 'OpenFreeMap', 'packout-core' ),
							'pos_default_on' => '1',
						)
					),
					self::seed(
						'mvum',
						__( 'Motor vehicle use map', 'packout-core' ),
						array(
							'pos_source'     => 'mvum',
							'pos_group'      => __( 'Forest Service', 'packout-core' ),
							'pos_default_on' => '0',
							'pos_note'       => __( 'These maps are not on OpenFreeMap. A motor vehicle use map shows where a vehicle is allowed, not whether a road is open today.', 'packout-core' ),
						)
					),
					self::seed(
						'nfstrails',
						__( 'National Forest trails', 'packout-core' ),
						array(
							'pos_source'     => 'nfstrails',
							'pos_group'      => __( 'Forest Service', 'packout-core' ),
							'pos_default_on' => '0',
						)
					),
					self::seed(
						'forests',
						__( 'National Forests', 'packout-core' ),
						array(
							'pos_source'     => 'forests',
							'pos_group'      => __( 'Forest Service', 'packout-core' ),
							'pos_default_on' => '0',
						)
					),
					self::seed(
						'wilderness',
						__( 'Wilderness', 'packout-core' ),
						array(
							'pos_source'     => 'wilderness',
							'pos_group'      => __( 'Forest Service', 'packout-core' ),
							'pos_default_on' => '0',
						)
					),
				),
			),
			self::GROUP_TYPE     => array(
				'singular'       => __( 'Group type', 'packout-core' ),
				'plural'         => __( 'Group types', 'packout-core' ),
				'objects'        => $team,
				'admin_column'   => true,
				'featured_image' => true,
				'protected'      => array(),
				'seeds'          => array(
					self::seed( 'college-students', __( 'College Students', 'packout-core' ) ),
					self::seed( 'corporations', __( 'Corporations', 'packout-core' ) ),
					self::seed( 'families', __( 'Families', 'packout-core' ) ),
					self::seed( 'students', __( 'Students', 'packout-core' ) ),
					self::seed( 'law-enforcement', __( 'Law Enforcement', 'packout-core' ) ),
					self::seed( 'municipalities', __( 'Municipalities', 'packout-core' ) ),
					self::seed( 'professionals', __( 'Professionals', 'packout-core' ) ),
					self::seed( 'volunteer-organizations', __( 'Volunteer Organizations', 'packout-core' ) ),
				),
			),
			self::PROGRAM_TYPE   => array(
				'singular'       => __( 'Program type', 'packout-core' ),
				'plural'         => __( 'Program types', 'packout-core' ),
				'objects'        => $program,
				'admin_column'   => true,
				'featured_image' => true,
				'protected'      => array(),
				'seeds'          => array(
					self::seed( 'beautification', __( 'Beautification Programs', 'packout-core' ) ),
					self::seed( 'campaign', __( 'Campaign Programs', 'packout-core' ) ),
					self::seed( 'cleanup', __( 'Cleanup Programs', 'packout-core' ) ),
					self::seed( 'enforcement', __( 'Enforcement Programs', 'packout-core' ) ),
					self::seed( 'special-event', __( 'Special Event Programs', 'packout-core' ) ),
					self::seed( 'youth-school', __( 'Youth and School Programs', 'packout-core' ) ),
				),
			),
			self::JOIN           => array(
				'singular'  => __( 'Join policy', 'packout-core' ),
				'plural'    => __( 'Join policies', 'packout-core' ),
				'objects'   => $team,
				'protected' => array( 'request', 'open', 'closed' ),
				'seeds'     => array(
					self::seed( 'request', __( 'Ask to join', 'packout-core' ) ),
					self::seed( 'open', __( 'Open', 'packout-core' ) ),
					self::seed( 'closed', __( 'Invitation only', 'packout-core' ) ),
				),
			),
			self::ORG_STATE      => array(
				'singular'     => __( 'Organization state', 'packout-core' ),
				'plural'       => __( 'Organization states', 'packout-core' ),
				'objects'      => $team,
				'admin_column' => true,
				'protected'    => array( 'pending', 'active' ),
				'seeds'        => array(
					self::seed( 'pending', __( 'Waiting for approval', 'packout-core' ) ),
					self::seed( 'active', __( 'Active', 'packout-core' ) ),
				),
			),
			self::PROGRAM_STATE  => array(
				'singular'     => __( 'Program state', 'packout-core' ),
				'plural'       => __( 'Program states', 'packout-core' ),
				'objects'      => $program,
				'admin_column' => true,
				'protected'    => array( 'open', 'closed' ),
				'seeds'        => array(
					self::seed( 'open', __( 'Open', 'packout-core' ) ),
					self::seed( 'closed', __( 'Closed', 'packout-core' ) ),
				),
			),
			self::MISSION_STATUS => array(
				'singular'  => __( 'Mission stage', 'packout-core' ),
				'plural'    => __( 'Mission stages', 'packout-core' ),
				'objects'   => $mission,
				'protected' => array( 'planned', 'in_progress', 'completed', 'called_off' ),
				'seeds'     => array(
					self::seed( 'planned', __( 'Planned', 'packout-core' ) ),
					self::seed( 'in_progress', __( 'In progress', 'packout-core' ) ),
					self::seed( 'completed', __( 'Completed', 'packout-core' ) ),
					self::seed( 'called_off', __( 'Called off', 'packout-core' ) ),
				),
			),
			self::OUTCOME        => array(
				'singular'  => __( 'Spot outcome', 'packout-core' ),
				'plural'    => __( 'Spot outcomes', 'packout-core' ),
				'objects'   => $mission,
				'protected' => array( 'cleaned', 'open', 'left', 'reopen' ),
				'seeds'     => array(
					self::seed( 'cleaned', __( 'Cleaned', 'packout-core' ) ),
					self::seed( 'open', __( 'Still open', 'packout-core' ) ),
					self::seed( 'left', __( 'Left: needs authorization', 'packout-core' ) ),
					self::seed( 'reopen', __( 'Still open: back to spotted', 'packout-core' ) ),
				),
			),
			self::IMPACT_KIND    => array(
				'singular'  => __( 'Impact kind', 'packout-core' ),
				'plural'    => __( 'Impact kinds', 'packout-core' ),
				'objects'   => $impact,
				'protected' => array( 'cleanup', 'still_clear', 'returned' ),
				'seeds'     => array(
					self::seed( 'cleanup', __( 'Cleanup', 'packout-core' ) ),
					self::seed( 'still_clear', __( 'Still clear', 'packout-core' ) ),
					self::seed( 'returned', __( 'It\'s back', 'packout-core' ) ),
				),
			),
			self::IMPACT_SOURCE  => array(
				'singular'  => __( 'Figure source', 'packout-core' ),
				'plural'    => __( 'Figure sources', 'packout-core' ),
				'objects'   => $impact,
				'protected' => array( 'self_reported', 'measured' ),
				'seeds'     => array(
					self::seed( 'self_reported', __( 'Self-reported', 'packout-core' ) ),
					self::seed( 'measured', __( 'Measured', 'packout-core' ) ),
				),
			),
			self::VERIFICATION   => array(
				'singular'  => __( 'Verification', 'packout-core' ),
				'plural'    => __( 'Verification', 'packout-core' ),
				'objects'   => $impact,
				'protected' => array( 'unverified', 'verified' ),
				'seeds'     => array(
					self::seed( 'unverified', __( 'Unverified', 'packout-core' ) ),
					self::seed( 'verified', __( 'Verified', 'packout-core' ) ),
				),
			),
		);

		if ( ! App::is_hub() ) {
			return $all;
		}

		$all[ self::PLACE_KIND ]    = array(
			'singular'       => __( 'Place kind', 'packout-core' ),
			'plural'         => __( 'Place kinds', 'packout-core' ),
			'objects'        => array( Schema::PLACE ),
			'featured_image' => true,
			'protected'      => array(),
			'seeds'          => array(
				self::seed( 'trail', __( 'Trail', 'packout-core' ) ),
				self::seed( 'trailhead', __( 'Trailhead', 'packout-core' ) ),
				self::seed( 'road', __( 'Road corridor', 'packout-core' ) ),
				self::seed( 'campsites', __( 'Campsites', 'packout-core' ) ),
				self::seed( 'water', __( 'Creek, river, or shoreline', 'packout-core' ) ),
				self::seed( 'other', __( 'Other area', 'packout-core' ) ),
			),
		);
		$all[ self::FEATURE_KIND ]  = array(
			'singular'  => __( 'Map feature', 'packout-core' ),
			'plural'    => __( 'Map features', 'packout-core' ),
			'objects'   => array( Schema::FEATURE ),
			'protected' => array( 'point', 'route', 'boundary' ),
			'seeds'     => array(
				self::seed( 'point', __( 'Point of interest', 'packout-core' ), array( 'pos_geometry' => 'point' ) ),
				self::seed( 'route', __( 'Route', 'packout-core' ), array( 'pos_geometry' => 'line' ) ),
				self::seed( 'boundary', __( 'Boundary', 'packout-core' ), array( 'pos_geometry' => 'polygon' ) ),
			),
		);
		$all[ self::FEATURE_ICON ]  = array(
			'singular'       => __( 'Point icon', 'packout-core' ),
			'plural'         => __( 'Point icons', 'packout-core' ),
			'objects'        => array( Schema::FEATURE ),
			'featured_image' => true,
			'protected'      => array(),
			'seeds'          => self::icon_seeds(),
		);
		$all[ self::FEATURE_COLOR ] = array(
			'singular'  => __( 'Feature color', 'packout-core' ),
			'plural'    => __( 'Feature colors', 'packout-core' ),
			'objects'   => array( Schema::FEATURE ),
			'protected' => array(),
			'seeds'     => array(
				self::seed( 'rust', __( 'Rust', 'packout-core' ), array( 'pos_hex' => '#bb5e28' ) ),
				self::seed( 'navy', __( 'Navy', 'packout-core' ), array( 'pos_hex' => '#25486b' ) ),
				self::seed( 'forest', __( 'Forest', 'packout-core' ), array( 'pos_hex' => '#415840' ) ),
				self::seed( 'bark', __( 'Bark', 'packout-core' ), array( 'pos_hex' => '#863d14' ) ),
				self::seed( 'gold', __( 'Gold', 'packout-core' ), array( 'pos_hex' => '#9a7b12' ) ),
				self::seed( 'charcoal', __( 'Charcoal', 'packout-core' ), array( 'pos_hex' => '#2f3232' ) ),
				self::seed( 'plum', __( 'Plum', 'packout-core' ), array( 'pos_hex' => '#6b3d54' ) ),
				self::seed( 'slate', __( 'Slate', 'packout-core' ), array( 'pos_hex' => '#3d5a6b' ) ),
			),
		);
		$all[ self::PARTNER_STATE ] = array(
			'singular'  => __( 'Partner state', 'packout-core' ),
			'plural'    => __( 'Partner states', 'packout-core' ),
			'objects'   => array( Schema::PARTNER ),
			'protected' => array( 'pending', 'active', 'suspended', 'dormant', 'disconnected' ),
			'seeds'     => array(
				self::seed( 'pending', __( 'Waiting for approval', 'packout-core' ) ),
				self::seed( 'active', __( 'Active', 'packout-core' ) ),
				self::seed( 'suspended', __( 'Suspended', 'packout-core' ) ),
				self::seed( 'dormant', __( 'Not heard from', 'packout-core' ) ),
				self::seed( 'disconnected', __( 'Disconnected', 'packout-core' ) ),
			),
		);

		return $all;
	}

	/**
	 * One seed row.
	 *
	 * @param string                $slug        Term slug.
	 * @param string                $name        Term name.
	 * @param array<string, string> $meta       Term meta.
	 * @param string                $description Term description.
	 * @return array{slug: string, name: string, meta: array<string, string>, description: string}
	 */
	private static function seed( string $slug, string $name, array $meta = array(), string $description = '' ): array {
		return array(
			'slug'        => $slug,
			'name'        => $name,
			'meta'        => $meta,
			'description' => $description,
		);
	}

	/**
	 * One term per icon the catalog draws.
	 *
	 * @return array<int, array{slug: string, name: string, meta: array<string, string>, description: string}>
	 */
	private static function icon_seeds(): array {
		$seeds = array();
		foreach ( Feature_Icons::catalog() as $id => $row ) {
			$seeds[] = self::seed(
				sanitize_title( str_replace( ':', '-', $id ) ),
				(string) ( $row['label'] ?? $id ),
				array( 'pos_icon_id' => $id )
			);
		}
		return $seeds;
	}

	/**
	 * WordPress register_taxonomy arguments.
	 *
	 * @param array<string, mixed> $def Vocabulary definition.
	 * @return array<string, mixed>
	 */
	private static function arguments( array $def ): array {
		$plural = (string) $def['plural'];
		$single = (string) $def['singular'];
		return array(
			'labels'             => array(
				'name'          => $plural,
				'singular_name' => $single,
				'menu_name'     => $plural,
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'packout-spot',
			'show_in_nav_menus'  => false,
			'show_tagcloud'      => false,
			'show_in_quick_edit' => false,
			'show_admin_column'  => ! empty( $def['admin_column'] ),
			'show_in_rest'       => true,
			'hierarchical'       => false,
			'rewrite'            => false,
			'query_var'          => false,
		);
	}

	/**
	 * A Content Types row the hub can merge into its collection.
	 *
	 * @param string               $key Taxonomy key.
	 * @param array<string, mixed> $def Vocabulary definition.
	 * @return array<string, mixed>
	 */
	public static function content_type_row( string $key, array $def ): array {
		return array(
			'key'                => $key,
			'id'                 => $key,
			'active'             => true,
			'singular'           => (string) $def['singular'],
			'plural'             => (string) $def['plural'],
			'public'             => false,
			'publicly_queryable' => false,
			'hierarchical'       => false,
			'post_types'         => $def['objects'],
			'show_ui'            => true,
			'show_in_menu'       => true,
			'menu_parent'        => 'packout-spot',
			'show_in_nav_menus'  => false,
			'show_tagcloud'      => false,
			'show_in_quick_edit' => false,
			'show_admin_column'  => ! empty( $def['admin_column'] ),
			'featured_image'     => ! empty( $def['featured_image'] ),
			'terms_order'        => true,
			'rewrite'            => false,
			'query_var'          => false,
			'show_in_rest'       => true,
		);
	}

	/**
	 * One term meta value, or empty.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @param string $slug     Term slug.
	 * @param string $key      Meta key.
	 */
	private static function term_meta( string $taxonomy, string $slug, string $key ): string {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}
		$value = get_term_meta( $term->term_id, $key, true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Assign terms from one meta key on every record of a type.
	 *
	 * @param string $post_type Post type.
	 * @param string $taxonomy  Taxonomy key.
	 * @param string $meta_key  Meta key.
	 */
	private static function assign_from_meta( string $post_type, string $taxonomy, string $meta_key ): void {
		if ( ! taxonomy_exists( $taxonomy ) || ! post_type_exists( $post_type ) ) {
			return;
		}
		foreach ( self::post_ids( $post_type ) as $id ) {
			$id    = (int) $id;
			$raw   = get_post_meta( $id, $meta_key, true );
			$value = is_scalar( $raw ) ? (string) $raw : '';
			$slug  = sanitize_key( $value );
			if ( self::FEATURE_COLOR === $taxonomy ) {
				$slug = self::color_slug_from_hex( strtolower( $value ) );
			}
			if ( self::ORG_STATE === $taxonomy ) {
				$slug = 'pending' === $slug ? 'pending' : 'active';
			}
			if ( self::PROGRAM_STATE === $taxonomy ) {
				$slug = 'closed' === $slug ? 'closed' : 'open';
			}
			if ( '' === $slug ) {
				continue;
			}
			self::ensure_term( $taxonomy, $slug );
			self::assign( $id, $taxonomy, $slug );
		}
	}

	/**
	 * Color term slug for a stored hex value.
	 *
	 * @param string $hex Stored color.
	 */
	private static function color_slug_from_hex( string $hex ): string {
		$hex = strtolower( $hex );
		foreach ( self::terms( self::FEATURE_COLOR ) as $term ) {
			if ( strtolower( (string) get_term_meta( $term->term_id, 'pos_hex', true ) ) === $hex ) {
				return $term->slug;
			}
		}
		foreach ( self::definitions()[ self::FEATURE_COLOR ]['seeds'] ?? array() as $seed ) {
			if ( strtolower( (string) ( $seed['meta']['pos_hex'] ?? '' ) ) === $hex ) {
				return $seed['slug'];
			}
		}
		return '';
	}

	/**
	 * Assign point-icon terms from stored catalog ids, resolving old aliases first.
	 */
	private static function assign_feature_icons(): void {
		if ( ! taxonomy_exists( self::FEATURE_ICON ) ) {
			return;
		}
		foreach ( self::post_ids( Schema::FEATURE ) as $id ) {
			$id     = (int) $id;
			$stored = Features::field( $id, 'icon' );
			if ( '' === $stored ) {
				continue;
			}
			$icon = Features::icon_id( $stored );
			$slug = self::icon_slug( $icon );
			self::ensure_term( self::FEATURE_ICON, $slug, Features::icons()[ $icon ] ?? $icon );
			$term = get_term_by( 'slug', $slug, self::FEATURE_ICON );
			if ( $term instanceof \WP_Term && '' === (string) get_term_meta( $term->term_id, 'pos_icon_id', true ) ) {
				update_term_meta( $term->term_id, 'pos_icon_id', $icon );
			}
			self::assign( $id, self::FEATURE_ICON, $slug );
		}
	}

	/**
	 * Assign every outcome stored on a mission.
	 */
	private static function assign_mission_outcomes(): void {
		if ( ! taxonomy_exists( self::OUTCOME ) ) {
			return;
		}
		foreach ( self::post_ids( Schema::MISSION ) as $id ) {
			$id    = (int) $id;
			$slugs = array();
			foreach ( Schema::stored_list( $id, 'outcomes' ) as $row ) {
				$outcome = is_array( $row ) ? sanitize_key( (string) ( $row['outcome'] ?? '' ) ) : '';
				if ( '' !== $outcome ) {
					self::ensure_term( self::OUTCOME, $outcome );
					$slugs[] = $outcome;
				}
			}
			if ( $slugs ) {
				wp_set_object_terms( $id, array_values( array_unique( $slugs ) ), self::OUTCOME );
			}
		}
	}

	/**
	 * Assign figure-source terms from bag and weight source fields.
	 */
	private static function assign_impact_sources(): void {
		if ( ! taxonomy_exists( self::IMPACT_SOURCE ) ) {
			return;
		}
		foreach ( self::post_ids( Schema::IMPACT ) as $id ) {
			$id    = (int) $id;
			$slugs = array();
			foreach ( array( 'bags_source', 'pounds_source' ) as $key ) {
				$slug = sanitize_key( (string) get_post_meta( $id, '_pos_' . $key, true ) );
				if ( '' !== $slug ) {
					self::ensure_term( self::IMPACT_SOURCE, $slug );
					$slugs[] = $slug;
				}
			}
			if ( $slugs ) {
				wp_set_object_terms( $id, array_values( array_unique( $slugs ) ), self::IMPACT_SOURCE );
			}
		}
	}

	/**
	 * Write pos_done_word on the six seed categories that do not have one.
	 */
	private static function write_category_done_words(): void {
		if ( ! taxonomy_exists( self::CATEGORY ) ) {
			return;
		}
		foreach ( self::definitions()[ self::CATEGORY ]['seeds'] ?? array() as $seed ) {
			$term = get_term_by( 'slug', $seed['slug'], self::CATEGORY );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			if ( '' === (string) get_term_meta( $term->term_id, 'pos_done_word', true ) ) {
				update_term_meta( $term->term_id, 'pos_done_word', (string) ( $seed['meta']['pos_done_word'] ?? __( 'Cleaned', 'packout-core' ) ) );
			}
		}
	}

	/**
	 * Every post of a type, in pages of two thousand.
	 *
	 * @param string $post_type Post type.
	 * @return int[]
	 */
	private static function post_ids( string $post_type ): array {
		$ids  = array();
		$page = 1;
		do {
			$batch = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => 2000,
					'paged'          => $page,
					'fields'         => 'ids',
				)
			);
			foreach ( $batch as $id ) {
				$ids[] = (int) $id;
			}
			++$page;
			$batch_count = count( $batch );
		} while ( 2000 === $batch_count );

		return $ids;
	}
}
