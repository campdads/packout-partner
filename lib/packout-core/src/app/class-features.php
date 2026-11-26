<?php
/**
 * Public map features: points, routes, and boundaries.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A map feature is a public point of interest, route, or boundary that a
 * signed-in person draws. It is not a stewardship Place. It can link to one
 * Place, carry sponsors, and start an adoption request for that Place.
 */
class Features {

	public const KIND_POINT    = 'point';
	public const KIND_ROUTE    = 'route';
	public const KIND_BOUNDARY = 'boundary';

	/**
	 * Most vertices a route or a boundary may have.
	 */
	public const VERTEX_LIMIT = 500;

	/**
	 * Register nothing on its own. Callers enqueue the editor.
	 */
	public static function register(): void {
	}

	/**
	 * Kinds of feature and their labels.
	 *
	 * @return array<string, string>
	 */
	public static function kinds(): array {
		return array(
			self::KIND_POINT    => __( 'Point of interest', 'packout-core' ),
			self::KIND_ROUTE    => __( 'Route', 'packout-core' ),
			self::KIND_BOUNDARY => __( 'Boundary', 'packout-core' ),
		);
	}

	/**
	 * Named swatches for the ColorPalette. Hex values stay the stored color.
	 *
	 * @return array<int, array{name: string, slug: string, color: string}>
	 */
	public static function palette(): array {
		return array(
			array(
				'name'  => __( 'Rust', 'packout-core' ),
				'slug'  => 'rust',
				'color' => '#bb5e28',
			),
			array(
				'name'  => __( 'Navy', 'packout-core' ),
				'slug'  => 'navy',
				'color' => '#25486b',
			),
			array(
				'name'  => __( 'Forest', 'packout-core' ),
				'slug'  => 'forest',
				'color' => '#415840',
			),
			array(
				'name'  => __( 'Bark', 'packout-core' ),
				'slug'  => 'bark',
				'color' => '#863d14',
			),
			array(
				'name'  => __( 'Gold', 'packout-core' ),
				'slug'  => 'gold',
				'color' => '#9a7b12',
			),
			array(
				'name'  => __( 'Charcoal', 'packout-core' ),
				'slug'  => 'charcoal',
				'color' => '#2f3232',
			),
			array(
				'name'  => __( 'Plum', 'packout-core' ),
				'slug'  => 'plum',
				'color' => '#6b3d54',
			),
			array(
				'name'  => __( 'Slate', 'packout-core' ),
				'slug'  => 'slate',
				'color' => '#3d5a6b',
			),
		);
	}

	/**
	 * Colors a feature may use, names keyed by hex.
	 *
	 * @return array<string, string>
	 */
	public static function colors(): array {
		$colors = array();
		foreach ( self::palette() as $row ) {
			$colors[ $row['color'] ] = $row['name'];
		}
		return $colors;
	}

	/**
	 * Material map icons a point of interest may use, labels keyed by id.
	 *
	 * @return array<string, string>
	 */
	public static function icons(): array {
		$labels = array();
		foreach ( Feature_Icons::catalog() as $id => $row ) {
			$labels[ $id ] = $row['label'];
		}
		return $labels;
	}

	/**
	 * Older catalog keys mapped onto Material icon ids.
	 *
	 * @return array<string, string>
	 */
	public static function icon_aliases(): array {
		return Feature_Icons::aliases();
	}

	/**
	 * A stored icon resolved to a Material icon id.
	 *
	 * @param string $icon Catalog key or Material icon id.
	 */
	public static function icon_id( string $icon ): string {
		$icon = strtolower( trim( $icon ) );
		$icon = (string) preg_replace( '/[^a-z0-9:-]/', '', $icon );
		if ( isset( self::icon_aliases()[ $icon ] ) ) {
			$icon = self::icon_aliases()[ $icon ];
		}
		return Feature_Icons::has( $icon ) ? $icon : Feature_Icons::DEFAULT;
	}

	/**
	 * Whether a stored icon is one this feature may keep.
	 *
	 * @param string $icon Catalog key or Material icon id.
	 */
	public static function icon_allowed( string $icon ): bool {
		$icon = strtolower( trim( $icon ) );
		$icon = (string) preg_replace( '/[^a-z0-9:-]/', '', $icon );
		if ( isset( self::icon_aliases()[ $icon ] ) ) {
			$icon = self::icon_aliases()[ $icon ];
		}
		return Feature_Icons::has( $icon );
	}

	/**
	 * A feature post, or null.
	 *
	 * @param int $id Post ID.
	 */
	public static function get( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && Schema::FEATURE === $post->post_type && 'publish' === $post->post_status ? $post : null;
	}

	/**
	 * A text field of a feature.
	 *
	 * @param int    $id  Feature ID.
	 * @param string $key Field name without the prefix.
	 */
	public static function field( int $id, string $key ): string {
		$value = get_post_meta( $id, '_pos_' . $key, true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The kind of a feature.
	 *
	 * @param int $id Feature ID.
	 */
	public static function kind( int $id ): string {
		$kind = self::field( $id, 'kind' );
		return isset( self::kinds()[ $kind ] ) ? $kind : self::KIND_POINT;
	}

	/**
	 * The color of a feature.
	 *
	 * @param int $id Feature ID.
	 */
	public static function color( int $id ): string {
		$color = strtolower( self::field( $id, 'color' ) );
		return isset( self::colors()[ $color ] ) ? $color : '#415840';
	}

	/**
	 * The icon of a point, or empty for a route or boundary.
	 *
	 * @param int $id Feature ID.
	 */
	public static function icon( int $id ): string {
		if ( self::KIND_POINT !== self::kind( $id ) ) {
			return '';
		}
		return self::icon_id( self::field( $id, 'icon' ) );
	}

	/**
	 * The Place a feature is linked to, or 0.
	 *
	 * @param int $id Feature ID.
	 */
	public static function place( int $id ): int {
		$place = (int) self::field( $id, 'place' );
		return Places::get( $place ) ? $place : 0;
	}

	/**
	 * Stored GeoJSON geometry, or null.
	 *
	 * @param int $id Feature ID.
	 * @return array{type: string, coordinates: mixed}|null
	 */
	public static function geometry( int $id ): ?array {
		$raw  = self::field( $id, 'geometry' );
		$data = '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['type'] ) || ! isset( $data['coordinates'] ) ) {
			return null;
		}
		return array(
			'type'        => (string) $data['type'],
			'coordinates' => $data['coordinates'],
		);
	}

	/**
	 * Where a feature is, as the page states it: latitude, then longitude.
	 *
	 * A point is that pair. A route runs from its first point to its last. A
	 * boundary is named by the middle of its outline.
	 *
	 * @param int $id Feature ID.
	 * @return array{label: string, text: string}
	 */
	public static function location( int $id ): array {
		$label    = __( 'Location', 'packout-core' );
		$geometry = self::geometry( $id );
		if ( ! $geometry ) {
			return array(
				'label' => $label,
				'text'  => '',
			);
		}
		$written = static function ( $pair ): string {
			$point = self::lnglat( $pair );
			if ( ! $point ) {
				return '';
			}
			return self::coordinate( $point[1] ) . ', ' . self::coordinate( $point[0] );
		};
		if ( 'Point' === $geometry['type'] ) {
			return array(
				'label' => $label,
				'text'  => $written( $geometry['coordinates'] ),
			);
		}
		if ( 'LineString' === $geometry['type'] ) {
			$points = array_values( array_filter( array_map( $written, (array) $geometry['coordinates'] ) ) );
			$start  = $points[0] ?? '';
			$end    = $points ? $points[ count( $points ) - 1 ] : '';
			return array(
				'label' => $label,
				'text'  => '' !== $start && '' !== $end
					/* translators: 1: latitude and longitude where the route starts, 2: where it ends. */
					? sprintf( __( 'A route from %1$s to %2$s', 'packout-core' ), $start, $end )
					: $start,
			);
		}
		$ring  = (array) ( $geometry['coordinates'][0] ?? array() );
		$lng   = 0.0;
		$lat   = 0.0;
		$count = 0;
		$last  = count( $ring ) - 1;
		foreach ( $ring as $index => $pair ) {
			if ( $index === $last && $last > 0 ) {
				continue;
			}
			$point = self::lnglat( $pair );
			if ( ! $point ) {
				continue;
			}
			$lng += $point[0];
			$lat += $point[1];
			++$count;
		}
		return array(
			'label' => $label,
			'text'  => $count > 0
				/* translators: %s: latitude and longitude of the middle of the boundary. */
				? sprintf( __( 'Around %s', 'packout-core' ), self::coordinate( $lat / $count ) . ', ' . self::coordinate( $lng / $count ) )
				: '',
		);
	}

	/**
	 * A coordinate without trailing zeroes.
	 *
	 * @param float $value Latitude or longitude.
	 */
	private static function coordinate( float $value ): string {
		$text = rtrim( rtrim( number_format( $value, 6, '.', '' ), '0' ), '.' );
		return '' === $text ? '0' : $text;
	}

	/**
	 * Whether a person may create a feature.
	 *
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_create( int $user_id = -1 ): bool {
		// Map features are the hub's shared layer. A partner site authors none.
		return App::is_hub() && Capabilities::can( Capabilities::REPORT, $user_id );
	}

	/**
	 * Whether a person may edit or trash a feature.
	 *
	 * @param int $id      Feature ID.
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can_edit( int $id, int $user_id = -1 ): bool {
		$user = $user_id < 0 ? get_current_user_id() : $user_id;
		$post = self::get( $id );
		if ( ! $post || $user <= 0 || ! App::is_hub() ) {
			return false;
		}
		return Capabilities::can( Capabilities::MODERATE, $user ) || ( (int) $post->post_author === $user && Capabilities::can( Capabilities::REPORT, $user ) );
	}

	/**
	 * Every public feature, newest first.
	 *
	 * @param int $place_id Limit to features of one Place, or 0 for all.
	 * @return array<int, \WP_Post>
	 */
	public static function all( int $place_id = 0 ): array {
		$query = array(
			'post_type'   => Schema::FEATURE,
			'post_status' => 'publish',
			'numberposts' => 2000,
			'orderby'     => 'date',
			'order'       => 'DESC',
		);
		if ( $place_id > 0 ) {
			$query['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Features of one Place.
				array(
					'key'   => '_pos_place',
					'value' => $place_id,
				),
			);
		}
		return get_posts( $query );
	}

	/**
	 * Features a person authored, newest first.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, \WP_Post>
	 */
	public static function of_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'   => Schema::FEATURE,
				'post_status' => 'publish',
				'author'      => $user_id,
				'numberposts' => 500,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
	}

	/**
	 * Public payload of every feature, for the map.
	 *
	 * @param int $place_id Limit to one Place, or 0 for all.
	 * @return array<int, array<string, mixed>>
	 */
	public static function public_list( int $place_id = 0 ): array {
		return array_values( array_filter( array_map( array( self::class, 'payload' ), self::all( $place_id ) ) ) );
	}

	/**
	 * Photos attached to a feature, in the shape a Spot's gallery uses.
	 *
	 * @param \WP_Post $post Feature.
	 * @return array<int, array<string, mixed>>
	 */
	public static function gallery( \WP_Post $post ): array {
		$rows = Photos::for_spot( $post->ID );
		$rank = array_flip( self::photo_order( $post->ID ) );
		if ( $rank ) {
			usort(
				$rows,
				static function ( array $a, array $b ) use ( $rank ): int {
					$left  = $rank[ (int) $a['id'] ] ?? PHP_INT_MAX;
					$right = $rank[ (int) $b['id'] ] ?? PHP_INT_MAX;
					return 0 !== ( $left <=> $right ) ? $left <=> $right : ( (int) $a['id'] <=> (int) $b['id'] );
				}
			);
		}
		$count  = count( $rows );
		$photos = array();
		foreach ( $rows as $index => $photo ) {
			$large    = wp_get_attachment_image_src( (int) $photo['id'], 'large' );
			$here     = number_format_i18n( $index + 1 );
			$all      = number_format_i18n( $count );
			$photos[] = array(
				'id'      => (int) $photo['id'],
				'url'     => $photo['url'],
				'large'   => $large ? (string) $large[0] : $photo['full'],
				'full'    => $photo['full'],
				'width'   => 0,
				'height'  => 0,
				'caption' => '',
				/* translators: 1: number of this photo, 2: number of photos. */
				'alt'     => sprintf( __( 'Photo %1$s of %2$s', 'packout-core' ), $here, $all ),
			);
		}
		return $photos;
	}

	/**
	 * The saved order of a feature's photos, or nothing when they stay in the order they were added.
	 *
	 * @param int $id Feature ID.
	 * @return array<int, int>
	 */
	public static function photo_order( int $id ): array {
		$raw  = self::field( $id, 'photo_order' );
		$data = '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', $data ) ) );
	}

	/**
	 * Remember the order of a feature's photos. The first one is the cover.
	 *
	 * @param int             $id      Feature ID.
	 * @param array<int, int> $ids     Attachment IDs, first to last. Every photo, and no others.
	 * @param int             $user_id Who sets it. Negative uses the current user.
	 * @return true|\WP_Error
	 */
	public static function set_photo_order( int $id, array $ids, int $user_id = -1 ) {
		if ( ! self::can_edit( $id, $user_id ) ) {
			return self::refusal();
		}
		$ids   = array_values( array_map( 'intval', $ids ) );
		$owned = array_map( 'intval', array_column( Photos::for_spot( $id ), 'id' ) );
		$given = $ids;
		$have  = $owned;
		sort( $given );
		sort( $have );
		if ( $given !== $have ) {
			return new \WP_Error( 'packout_photo_order', __( 'Those are not this feature’s photos.', 'packout-core' ), array( 'status' => 400 ) );
		}
		update_post_meta( $id, '_pos_photo_order', wp_json_encode( $ids ) );
		return true;
	}

	/**
	 * What the public map and the editor need of one feature.
	 *
	 * @param \WP_Post $post Feature.
	 * @return array<string, mixed>|null
	 */
	public static function payload( \WP_Post $post ): ?array {
		if ( Schema::FEATURE !== $post->post_type ) {
			return null;
		}
		$geometry = self::geometry( $post->ID );
		if ( ! $geometry ) {
			return null;
		}
		$place    = self::place( $post->ID );
		$held     = $place ? Places::get( $place ) : null;
		$listed   = $held && 'publish' === $held->post_status;
		$sponsors = array();
		foreach ( Sponsors::all( $post->ID ) as $sponsor ) {
			$sponsors[] = array(
				'name' => $sponsor['name'],
				'url'  => $sponsor['url'],
			);
		}
		return array(
			'id'          => $post->ID,
			'name'        => $post->post_title,
			// The name is the label drawn beside the feature on the map.
			'label'       => $post->post_title,
			'description' => $post->post_content,
			'url'         => (string) get_permalink( $post ),
			'gallery'     => self::gallery( $post ),
			'editable'    => self::can_edit( $post->ID ),
			'kind'        => self::kind( $post->ID ),
			'kind_label'  => self::kinds()[ self::kind( $post->ID ) ],
			'color'       => self::color( $post->ID ),
			'icon'        => self::icon( $post->ID ),
			'geometry'    => $geometry,
			'place'       => $listed ? array(
				'id'   => $held->ID,
				'name' => $held->post_title,
				'url'  => (string) get_permalink( $held ),
			) : ( $place ? array(
				'id'   => $place,
				'name' => '',
				'url'  => '',
			) : null ),
			'sponsors'    => $sponsors,
			'author'      => (int) $post->post_author,
			'updated'     => get_post_modified_time( 'c', true, $post ),
		);
	}

	/**
	 * Create or update a feature.
	 *
	 * @param int                  $id      Feature ID, or 0 for a new one.
	 * @param array<string, mixed> $input   name, description, kind, color, icon, geometry, place.
	 * @param int                  $user_id The person saving.
	 * @return int|\WP_Error Feature ID.
	 */
	public static function save( int $id, array $input, int $user_id ) {
		if ( $id > 0 ) {
			if ( ! self::can_edit( $id, $user_id ) ) {
				return self::refusal();
			}
		} elseif ( ! self::can_create( $user_id ) ) {
			return self::refusal();
		}

		$existing = $id > 0 ? get_post( $id ) : null;
		$name     = array_key_exists( 'name', $input ) ? sanitize_text_field( (string) $input['name'] ) : ( $existing ? $existing->post_title : '' );
		if ( '' === $name || mb_strlen( $name ) > 120 ) {
			return new \WP_Error( 'packout_feature_name', __( 'Give the feature a name of up to 120 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$kind = sanitize_key( (string) ( $input['kind'] ?? ( $id > 0 ? self::kind( $id ) : self::KIND_POINT ) ) );
		if ( ! isset( self::kinds()[ $kind ] ) ) {
			return new \WP_Error( 'packout_feature_kind', __( 'Choose a point, a route, or a boundary.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$raw_geometry = $input['geometry'] ?? null;
		if ( ( null === $raw_geometry || '' === $raw_geometry || array() === $raw_geometry ) && $id > 0 ) {
			$raw_geometry = self::geometry( $id );
		}
		$geometry = self::parse_geometry( $kind, $raw_geometry ?? '' );
		if ( is_wp_error( $geometry ) ) {
			return $geometry;
		}
		if ( ! $geometry ) {
			return new \WP_Error( 'packout_feature_geometry', __( 'Draw the feature on the map.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$color = strtolower( sanitize_text_field( (string) ( $input['color'] ?? ( $id > 0 ? self::color( $id ) : '#415840' ) ) ) );
		if ( ! isset( self::colors()[ $color ] ) ) {
			$color = '#415840';
		}
		$icon = (string) ( $input['icon'] ?? ( $id > 0 ? self::field( $id, 'icon' ) : '' ) );
		if ( self::KIND_POINT !== $kind ) {
			$icon = '';
		} else {
			$icon = self::icon_id( $icon );
		}
		$place = array_key_exists( 'place', $input ) ? (int) $input['place'] : ( $id > 0 ? self::place( $id ) : 0 );
		if ( $place > 0 ) {
			$held = Places::get( $place );
			if ( ! $held ) {
				return new \WP_Error( 'packout_feature_place', __( 'That Place does not exist.', 'packout-core' ), array( 'status' => 400 ) );
			}
			if ( 'publish' !== $held->post_status && ! Places::can_manage( $user_id ) ) {
				return new \WP_Error( 'packout_feature_place', __( 'Choose a Place that is listed, or ask a coordinator.', 'packout-core' ), array( 'status' => 400 ) );
			}
		}

		$created = 0 === $id;
		$fields  = array(
			'post_type'    => Schema::FEATURE,
			'post_status'  => 'publish',
			'post_title'   => $name,
			'post_content' => array_key_exists( 'description', $input ) ? sanitize_textarea_field( (string) $input['description'] ) : ( $existing ? $existing->post_content : '' ),
			'post_author'  => $id > 0 ? (int) get_post( $id )->post_author : $user_id,
		);
		if ( $id > 0 ) {
			$fields['ID'] = $id;
			$saved        = wp_update_post( $fields, true );
		} else {
			$saved = wp_insert_post( $fields, true );
		}
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$id = (int) $saved;
		update_post_meta( $id, '_pos_kind', $kind );
		update_post_meta( $id, '_pos_color', $color );
		update_post_meta( $id, '_pos_geometry', wp_slash( (string) wp_json_encode( $geometry ) ) );
		if ( '' === $icon ) {
			delete_post_meta( $id, '_pos_icon' );
		} else {
			update_post_meta( $id, '_pos_icon', $icon );
		}
		if ( $place > 0 ) {
			update_post_meta( $id, '_pos_place', $place );
		} else {
			delete_post_meta( $id, '_pos_place' );
		}

		if ( $created ) {
			do_action( 'packout_feature_created', $id, $user_id );
		} else {
			do_action( 'packout_feature_updated', $id, $user_id );
		}
		return $id;
	}

	/**
	 * Move a feature to the trash.
	 *
	 * @param int $id      Feature ID.
	 * @param int $user_id The person.
	 * @return true|\WP_Error
	 */
	public static function trash( int $id, int $user_id ) {
		if ( ! self::can_edit( $id, $user_id ) ) {
			return self::refusal();
		}
		$done = wp_trash_post( $id );
		return $done ? true : new \WP_Error( 'packout_feature_trash', __( 'That feature could not be removed.', 'packout-core' ), array( 'status' => 500 ) );
	}

	/**
	 * Read geometry a person drew or pasted.
	 *
	 * Accepts a GeoJSON geometry, Feature, or FeatureCollection, or a JSON
	 * list of [longitude, latitude] pairs. Kind decides the type: a point is
	 * one coordinate, a route a LineString, a boundary a Polygon.
	 *
	 * @param string $kind point, route, or boundary.
	 * @param mixed  $raw  JSON string, array, or empty.
	 * @return array{type: string, coordinates: mixed}|\WP_Error|array{} Empty when nothing was given.
	 */
	public static function parse_geometry( string $kind, $raw ) {
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			if ( '' === $raw ) {
				return array();
			}
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return self::unreadable();
			}
			$raw = $decoded;
		}
		if ( ! is_array( $raw ) || array() === $raw ) {
			return array();
		}

		$geometry = self::geometry_of( $raw );
		if ( null === $geometry ) {
			return self::unreadable();
		}
		$type = $geometry['type'];
		$need = array(
			self::KIND_POINT    => 'Point',
			self::KIND_ROUTE    => 'LineString',
			self::KIND_BOUNDARY => 'Polygon',
		);
		if ( ( $need[ $kind ] ?? '' ) !== $type ) {
			return new \WP_Error( 'packout_feature_kind', __( 'The drawing does not match the kind of feature. A point is one place, a route is a line, and a boundary is an area.', 'packout-core' ), array( 'status' => 400 ) );
		}

		if ( 'Point' === $type ) {
			$point = self::lnglat( $geometry['coordinates'] );
			return $point ? array(
				'type'        => 'Point',
				'coordinates' => $point,
			) : self::unreadable();
		}

		$ring = 'Polygon' === $type
			? ( is_array( $geometry['coordinates'][0] ?? null ) ? $geometry['coordinates'][0] : null )
			: $geometry['coordinates'];
		if ( ! is_array( $ring ) ) {
			return self::unreadable();
		}
		$points = array();
		foreach ( $ring as $pair ) {
			$point = self::lnglat( $pair );
			if ( ! $point ) {
				return self::unreadable();
			}
			if ( $points && $points[ count( $points ) - 1 ] === $point ) {
				continue;
			}
			$points[] = $point;
		}
		if ( 'Polygon' === $type && count( $points ) > 1 && $points[0] === $points[ count( $points ) - 1 ] ) {
			array_pop( $points );
		}
		$min = 'Polygon' === $type ? 3 : 2;
		if ( count( $points ) < $min ) {
			return new \WP_Error(
				'packout_feature_geometry',
				'Polygon' === $type
					? __( 'A boundary needs at least three points.', 'packout-core' )
					: __( 'A route needs at least two points.', 'packout-core' ),
				array( 'status' => 400 )
			);
		}
		if ( count( $points ) > self::VERTEX_LIMIT ) {
			/* translators: %d: number of points. */
			return new \WP_Error( 'packout_feature_geometry', sprintf( __( 'A feature can have up to %d points. Simplify it and try again.', 'packout-core' ), self::VERTEX_LIMIT ), array( 'status' => 400 ) );
		}
		if ( 'Polygon' === $type ) {
			$points[] = $points[0];
			return array(
				'type'        => 'Polygon',
				'coordinates' => array( $points ),
			);
		}
		return array(
			'type'        => 'LineString',
			'coordinates' => $points,
		);
	}

	/**
	 * Parse a GPX or GeoJSON upload into drafts the editor can preview.
	 *
	 * @param string $raw  File contents.
	 * @param string $name Original file name.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function parse_file( string $raw, string $name = '' ) {
		$raw  = trim( $raw );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$json = '{' === ( $raw[0] ?? '' ) || '[' === ( $raw[0] ?? '' );
		if ( $json || 'geojson' === $ext || 'json' === $ext ) {
			return self::parse_geojson_file( $raw );
		}
		if ( '<' === ( $raw[0] ?? '' ) || 'gpx' === $ext || 'xml' === $ext ) {
			return self::parse_gpx( $raw );
		}
		return new \WP_Error( 'packout_feature_import', __( 'That file is not a GPX or GeoJSON file.', 'packout-core' ), array( 'status' => 400 ) );
	}

	/**
	 * Create features from parsed import drafts.
	 *
	 * @param array<int, array<string, mixed>> $drafts From parse_file().
	 * @param array<string, mixed>             $input  color, icon, place.
	 * @param int                              $user_id Owner.
	 * @return array<int, int>|\WP_Error Feature IDs.
	 */
	public static function import( array $drafts, array $input, int $user_id ) {
		if ( ! self::can_create( $user_id ) ) {
			return self::refusal();
		}
		if ( ! $drafts ) {
			return new \WP_Error( 'packout_feature_import', __( 'Nothing in that file could be imported.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$ids = array();
		foreach ( $drafts as $index => $draft ) {
			$saved = self::save(
				0,
				array(
					'name'        => (string) ( $draft['name'] ?? '' ),
					'description' => (string) ( $draft['description'] ?? '' ),
					'kind'        => (string) ( $draft['kind'] ?? self::KIND_POINT ),
					'geometry'    => $draft['geometry'] ?? null,
					'color'       => $input['color'] ?? '#415840',
					'icon'        => $input['icon'] ?? 'other',
					'place'       => (int) ( $input['place'] ?? 0 ),
				),
				$user_id
			);
			if ( is_wp_error( $saved ) ) {
				foreach ( $ids as $made ) {
					wp_delete_post( $made, true );
				}
				return $saved;
			}
			$ids[] = (int) $saved;
			unset( $index );
		}//end foreach
		return $ids;
	}

	/**
	 * Settings the shared map editor needs.
	 *
	 * @return array<string, mixed>
	 */
	public static function editor_settings(): array {
		$places = array();
		foreach ( Places::all( ! Places::can_manage() ) as $place ) {
			$places[] = array(
				'id'     => $place->ID,
				'name'   => $place->post_title,
				'listed' => 'publish' === $place->post_status,
			);
		}
		$teams = array();
		$user  = get_current_user_id();
		if ( $user > 0 ) {
			foreach ( Teams::of_user( $user ) as $id ) {
				$team = Teams::get( $id );
				if ( $team && Teams::active( $id ) ) {
					$teams[] = array(
						'id'   => $id,
						'name' => $team->post_title,
					);
				}
			}
		}
		return array(
			'rest'       => esc_url_raw( rest_url( Rest::NAMESPACE . '/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'style'      => Settings::get( 'map_style' ),
			'center'     => array( (float) Settings::get( 'map_lng' ), (float) Settings::get( 'map_lat' ) ),
			'zoom'       => (float) Settings::get( 'map_zoom' ),
			'kinds'      => self::kinds(),
			'palette'    => self::palette(),
			'colors'     => array_column( self::palette(), 'color' ),
			'icons'      => self::icons(),
			'iconPicker' => self::icon_picker(),
			'places'     => $places,
			'teams'      => $teams,
			'canEdit'    => self::can_create(),
			'text'       => array(
				'add'         => __( 'Add a map feature', 'packout-core' ),
				'edit'        => __( 'Edit on map', 'packout-core' ),
				'save'        => __( 'Save', 'packout-core' ),
				'cancel'      => __( 'Cancel', 'packout-core' ),
				'name'        => __( 'Name', 'packout-core' ),
				'note'        => __( 'Note', 'packout-core' ),
				'photos'      => __( 'Photos', 'packout-core' ),
				'photosHelp'  => sprintf(
					/* translators: %d: number of photos. */
					__( 'Add more than one, and drag them to change the order. The first photo is the one on the map. A feature holds up to %d photos, the same as a Spot.', 'packout-core' ),
					Photos::LIMIT
				),
				'dragPhoto'   => __( 'Drag to change the order', 'packout-core' ),
				'removePhoto' => __( 'Remove', 'packout-core' ),
				'kind'        => __( 'Kind', 'packout-core' ),
				'color'       => __( 'Color', 'packout-core' ),
				'icon'        => __( 'Icon', 'packout-core' ),
				'chooseIcon'  => __( 'Choose icon', 'packout-core' ),
				'searchIcons' => __( 'Search icons', 'packout-core' ),
				'allIcons'    => __( 'All icons', 'packout-core' ),
				'iconGroup'   => __( 'Group', 'packout-core' ),
				'place'       => __( 'Place', 'packout-core' ),
				'none'        => __( 'None', 'packout-core' ),
				'point'       => __( 'Place a point', 'packout-core' ),
				'route'       => __( 'Draw a route', 'packout-core' ),
				'boundary'    => __( 'Draw a boundary', 'packout-core' ),
				'undo'        => __( 'Remove the last point', 'packout-core' ),
				'clear'       => __( 'Start over', 'packout-core' ),
				'delete'      => __( 'Remove', 'packout-core' ),
				'popover'     => __( 'This point', 'packout-core' ),
				'import'      => __( 'Import GPX or GeoJSON', 'packout-core' ),
				'importing'   => __( 'Reading the file…', 'packout-core' ),
				'saving'      => __( 'Saving…', 'packout-core' ),
				'failed'      => __( 'That could not be saved. Check your connection and try again.', 'packout-core' ),
				'needName'    => __( 'Give the feature a name.', 'packout-core' ),
				'needDraw'    => __( 'Draw the feature on the map.', 'packout-core' ),
				/* translators: %d: number of features. */
				'preview'     => __( '%d features ready. Save to add them to the map.', 'packout-core' ),
				'previewOne'  => __( '1 feature ready. Save to add it to the map.', 'packout-core' ),
				'close'       => __( 'Close', 'packout-core' ),
				'map'         => __( 'Map for drawing a feature', 'packout-core' ),
				/* translators: %d: number of points. */
				'points'      => __( '%d points', 'packout-core' ),
			),
		);
	}

	/**
	 * Assets of the shared map editor.
	 */
	public static function enqueue_editor(): void {
		Blocks::register_map_library();
		if ( ! wp_script_is( 'packout-search', 'registered' ) ) {
			Search::register_assets();
		}
		Ui::enqueue();
		wp_enqueue_style( 'packout-maplibre' );
		wp_enqueue_style( 'packout-search' );
		if ( ! wp_script_is( 'packout-map-editor', 'registered' ) ) {
			wp_register_style( 'packout-map-editor', plugins_url( 'assets/css/map-editor.css', PACKOUT_CORE_FILE ), array_merge( array( 'packout-maplibre', 'packout-search' ), Ui::styles() ), PACKOUT_CORE_VERSION );
			wp_register_script( 'packout-map-editor', plugins_url( 'assets/js/map-editor.js', PACKOUT_CORE_FILE ), array_merge( array( 'packout-maplibre', 'packout-search' ), Ui::scripts() ), PACKOUT_CORE_VERSION, true );
		}
		wp_enqueue_style( 'packout-map-editor' );
		if ( ! wp_script_is( 'packout-map-editor', 'enqueued' ) ) {
			wp_localize_script( 'packout-map-editor', 'packoutMapEditor', self::editor_settings() );
			wp_enqueue_script( 'packout-map-editor' );
		}
	}

	/**
	 * Material map icons for the graphical picker.
	 *
	 * @return array{library: array<string, mixed>, markup: array<string, string>, aliases: array<string, string>}
	 */
	private static function icon_picker(): array {
		$icons = array();
		foreach ( Feature_Icons::catalog() as $id => $row ) {
			$icons[] = array(
				'slug'     => $id,
				'label'    => $row['label'],
				'group'    => $row['group'],
				'keywords' => $id . ' ' . $row['keywords'] . ' ' . $row['label'],
			);
		}
		return array(
			'library' => array(
				'groups' => Feature_Icons::groups(),
				'icons'  => $icons,
				'legacy' => array(),
			),
			'markup'  => Feature_Icons::markup(),
			'aliases' => self::icon_aliases(),
		);
	}

	/**
	 * GeoJSON geometry inside a decoded payload, or null.
	 *
	 * @param array<int|string, mixed> $data Decoded JSON.
	 * @return array{type: string, coordinates: mixed}|null
	 */
	private static function geometry_of( array $data ): ?array {
		$type = isset( $data['type'] ) && is_string( $data['type'] ) ? $data['type'] : '';
		if ( 'FeatureCollection' === $type ) {
			foreach ( (array) ( $data['features'] ?? array() ) as $feature ) {
				$found = is_array( $feature ) ? self::geometry_of( $feature ) : null;
				if ( $found ) {
					return $found;
				}
			}
			return null;
		}
		if ( 'Feature' === $type ) {
			return is_array( $data['geometry'] ?? null ) ? self::geometry_of( $data['geometry'] ) : null;
		}
		if ( in_array( $type, array( 'Point', 'LineString', 'Polygon' ), true ) && isset( $data['coordinates'] ) ) {
			return array(
				'type'        => $type,
				'coordinates' => $data['coordinates'],
			);
		}
		if ( '' === $type && array_is_list( $data ) && isset( $data[0] ) ) {
			if ( is_numeric( $data[0] ) && isset( $data[1] ) && is_numeric( $data[1] ) ) {
				return array(
					'type'        => 'Point',
					'coordinates' => $data,
				);
			}
			if ( is_array( $data[0] ) && is_numeric( $data[0][0] ?? null ) ) {
				return array(
					'type'        => count( $data ) >= 3 ? 'LineString' : 'LineString',
					'coordinates' => $data,
				);
			}
		}
		return null;
	}

	/**
	 * A longitude, latitude pair from a GeoJSON coordinate, or null.
	 *
	 * @param mixed $pair Coordinate.
	 * @return array{0: float, 1: float}|null
	 */
	private static function lnglat( $pair ): ?array {
		if ( ! is_array( $pair ) || ! isset( $pair[0], $pair[1] ) ) {
			return null;
		}
		$point = Geo::point( $pair[1], $pair[0] );
		return $point ? array( $point[1], $point[0] ) : null;
	}

	/**
	 * Parse GeoJSON that may hold several features.
	 *
	 * @param string $raw File contents.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function parse_geojson_file( string $raw ) {
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'packout_feature_import', __( 'The GeoJSON could not be read.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$features = array();
		if ( 'FeatureCollection' === ( $data['type'] ?? '' ) ) {
			foreach ( (array) ( $data['features'] ?? array() ) as $feature ) {
				if ( is_array( $feature ) ) {
					$features[] = $feature;
				}
			}
		} else {
			$features[] = $data;
		}
		$drafts = array();
		foreach ( $features as $index => $feature ) {
			$geometry = is_array( $feature ) ? self::geometry_of( $feature ) : null;
			if ( ! $geometry ) {
				continue;
			}
			$kind = array(
				'Point'      => self::KIND_POINT,
				'LineString' => self::KIND_ROUTE,
				'Polygon'    => self::KIND_BOUNDARY,
			)[ $geometry['type'] ] ?? '';
			if ( '' === $kind ) {
				continue;
			}
			$parsed = self::parse_geometry( $kind, $geometry );
			if ( is_wp_error( $parsed ) || ! $parsed ) {
				continue;
			}
			$props = is_array( $feature['properties'] ?? null ) ? $feature['properties'] : array();
			$name  = sanitize_text_field( (string) ( $props['name'] ?? $props['Name'] ?? $props['title'] ?? '' ) );
			if ( '' === $name ) {
				/* translators: %d: number of this feature in the file. */
				$name = sprintf( __( 'Imported feature %d', 'packout-core' ), $index + 1 );
			}
			$drafts[] = array(
				'name'        => $name,
				'description' => sanitize_textarea_field( (string) ( $props['desc'] ?? $props['description'] ?? $props['cmt'] ?? '' ) ),
				'kind'        => $kind,
				'geometry'    => $parsed,
			);
		}//end foreach
		return $drafts ? $drafts : new \WP_Error( 'packout_feature_import', __( 'Nothing in that GeoJSON could be imported as a point, a route, or a boundary.', 'packout-core' ), array( 'status' => 400 ) );
	}

	/**
	 * Parse a GPX file: waypoints become points, tracks and routes become routes.
	 *
	 * @param string $raw File contents.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function parse_gpx( string $raw ) {
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $raw );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $xml instanceof \SimpleXMLElement ) {
			return new \WP_Error( 'packout_feature_import', __( 'The GPX file could not be read.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$drafts = array();

		foreach ( self::gpx_nodes( $xml, 'wpt' ) as $wpt ) {
			$point = Geo::point( (string) $wpt['lat'], (string) $wpt['lon'] );
			if ( ! $point ) {
				continue;
			}
			$name     = sanitize_text_field( self::gpx_text( $wpt, 'name' ) );
			$desc     = self::gpx_text( $wpt, 'desc' );
			$drafts[] = array(
				'name'        => '' !== $name ? $name : __( 'Waypoint', 'packout-core' ),
				'description' => sanitize_textarea_field( '' !== $desc ? $desc : self::gpx_text( $wpt, 'cmt' ) ),
				'kind'        => self::KIND_POINT,
				'geometry'    => array(
					'type'        => 'Point',
					'coordinates' => array( $point[1], $point[0] ),
				),
			);
		}

		$lines = array();
		foreach ( self::gpx_nodes( $xml, 'rte' ) as $rte ) {
			$lines[] = array( $rte, self::gpx_nodes( $rte, 'rtept' ) );
		}
		foreach ( self::gpx_nodes( $xml, 'trk' ) as $trk ) {
			$pts = array();
			foreach ( self::gpx_nodes( $trk, 'trkseg' ) as $seg ) {
				foreach ( self::gpx_nodes( $seg, 'trkpt' ) as $pt ) {
					$pts[] = $pt;
				}
			}
			$lines[] = array( $trk, $pts );
		}
		foreach ( $lines as $row ) {
			$node   = $row[0];
			$coords = array();
			foreach ( $row[1] as $pt ) {
				$point = Geo::point( (string) $pt['lat'], (string) $pt['lon'] );
				if ( $point ) {
					$coords[] = array( $point[1], $point[0] );
				}
			}
			$parsed = self::parse_geometry(
				self::KIND_ROUTE,
				array(
					'type'        => 'LineString',
					'coordinates' => $coords,
				)
			);
			if ( is_wp_error( $parsed ) || ! $parsed ) {
				continue;
			}
			$name     = sanitize_text_field( self::gpx_text( $node, 'name' ) );
			$desc     = self::gpx_text( $node, 'desc' );
			$drafts[] = array(
				'name'        => '' !== $name ? $name : __( 'Track', 'packout-core' ),
				'description' => sanitize_textarea_field( '' !== $desc ? $desc : self::gpx_text( $node, 'cmt' ) ),
				'kind'        => self::KIND_ROUTE,
				'geometry'    => $parsed,
			);
		}//end foreach

		return $drafts ? $drafts : new \WP_Error( 'packout_feature_import', __( 'Nothing in that GPX file could be imported. It needs waypoints, routes, or tracks.', 'packout-core' ), array( 'status' => 400 ) );
	}

	/**
	 * Child elements of a GPX node by local name, with or without a namespace.
	 *
	 * @param \SimpleXMLElement $node Parent.
	 * @param string            $name Local name.
	 * @return array<int, \SimpleXMLElement>
	 */
	private static function gpx_nodes( \SimpleXMLElement $node, string $name ): array {
		$direct = $node->xpath( '*[local-name()="' . $name . '"]' );
		if ( is_array( $direct ) && $direct ) {
			return $direct;
		}
		$found = $node->xpath( './/*[local-name()="' . $name . '"]' );
		return is_array( $found ) ? $found : array();
	}

	/**
	 * Text of a named child of a GPX node.
	 *
	 * @param \SimpleXMLElement $node Parent.
	 * @param string            $name Local name.
	 */
	private static function gpx_text( \SimpleXMLElement $node, string $name ): string {
		$found = $node->xpath( '*[local-name()="' . $name . '"]' );
		return is_array( $found ) && isset( $found[0] ) ? (string) $found[0] : '';
	}

	/**
	 * What a person who may not edit a feature is told.
	 */
	private static function refusal(): \WP_Error {
		return new \WP_Error( 'packout_forbidden', __( 'You can add map features when you are signed in, and you can change the ones you created.', 'packout-core' ), array( 'status' => 403 ) );
	}

	/**
	 * Geometry that could not be read.
	 */
	private static function unreadable(): \WP_Error {
		return new \WP_Error( 'packout_feature_geometry', __( 'The drawing could not be read. Draw it on the map, or paste GeoJSON.', 'packout-core' ), array( 'status' => 400 ) );
	}
}
