<?php
/**
 * Site settings.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and cleans the site option that holds the plugin settings.
 *
 * The option belongs to this site. A network settings change does not reach it.
 */
class Settings {

	/**
	 * The option that holds the settings. The plugin names it: a site that
	 * has saved settings keeps them where they are.
	 */
	public static function option(): string {
		return App::kernel()->settings()->option();
	}

	/**
	 * Form field keys the report form maps onto a Spot.
	 */
	public const FORM_FIELDS = array( 'category', 'description', 'landmark', 'latitude', 'longitude', 'photo' );

	/**
	 * Values used until a coordinator saves the settings screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'map_style'        => 'https://tiles.openfreemap.org/styles/liberty',
			'map_lat'          => 39.5,
			'map_lng'          => -98.35,
			'map_zoom'         => 3.5,
			'duplicate_radius' => 50,
			'celebration'      => 'public',
			'default_category' => 'cleanup-waste',
			'report_page'      => 0,
			'signup_page'      => 0,
			'signin_page'      => 0,
			'workspace_page'   => 0,
			'account_page'     => 0,
			'form'             => '',
			'form_keys'        => array(
				'category'    => 'category',
				'description' => 'description',
				'landmark'    => 'landmark',
				'latitude'    => 'latitude',
				'longitude'   => 'longitude',
				'photo'       => 'photo',
			),
			'regions'          => array(),
		);
	}

	/**
	 * Every setting, with defaults for anything not saved.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::option(), array() );
		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Save settings. Unknown keys are dropped.
	 *
	 * @param array<string, mixed> $input Raw values. Missing keys keep their stored value.
	 * @return array<string, mixed>
	 */
	public static function save( array $input ): array {
		$clean = self::sanitize( array_merge( self::all(), $input ) );
		update_option( self::option(), $clean, false );
		return $clean;
	}

	/**
	 * The line shown when a Spot is marked cleaned.
	 */
	public static function celebration_line(): string {
		return 'pos' === self::get( 'celebration' )
			? __( 'One Less POS on the Trail.', 'packout-core' )
			: __( 'Spot it. Pack it out. Leave it better.', 'packout-core' );
	}

	/**
	 * Clean a full settings array.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$input    = array_merge( $defaults, $input );

		$style = esc_url_raw( (string) $input['map_style'], array( 'https' ) );

		$keys = array();
		foreach ( self::FORM_FIELDS as $field ) {
			$value          = is_array( $input['form_keys'] ) && isset( $input['form_keys'][ $field ] ) ? (string) $input['form_keys'][ $field ] : '';
			$value          = sanitize_text_field( $value );
			$keys[ $field ] = '' !== $value ? $value : $defaults['form_keys'][ $field ];
		}

		return array(
			'map_style'        => '' !== $style ? $style : $defaults['map_style'],
			'map_lat'          => Geo::clamp( (float) $input['map_lat'], -90.0, 90.0 ),
			'map_lng'          => Geo::clamp( (float) $input['map_lng'], -180.0, 180.0 ),
			'map_zoom'         => Geo::clamp( (float) $input['map_zoom'], 0.0, 18.0 ),
			'duplicate_radius' => (int) Geo::clamp( (float) $input['duplicate_radius'], 5.0, 1000.0 ),
			'celebration'      => 'pos' === $input['celebration'] ? 'pos' : 'public',
			'default_category' => sanitize_title( (string) $input['default_category'] ),
			'report_page'      => max( 0, (int) $input['report_page'] ),
			'signup_page'      => max( 0, (int) $input['signup_page'] ),
			'signin_page'      => max( 0, (int) $input['signin_page'] ),
			'workspace_page'   => max( 0, (int) $input['workspace_page'] ),
			'account_page'     => max( 0, (int) $input['account_page'] ),
			'form'             => sanitize_text_field( (string) $input['form'] ),
			'form_keys'        => $keys,
			'regions'          => self::regions( $input['regions'] ),
		);
	}

	/**
	 * Clean the list of downloadable map regions.
	 *
	 * A region needs a name, a bounding box, and an https address for its tile
	 * package. Incomplete rows are dropped.
	 *
	 * @param mixed $raw Raw regions.
	 * @return array<int, array<string, mixed>>
	 */
	private static function regions( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		foreach ( $raw as $region ) {
			if ( ! is_array( $region ) ) {
				continue;
			}

			$name    = sanitize_text_field( (string) ( $region['name'] ?? '' ) );
			$package = esc_url_raw( (string) ( $region['package'] ?? '' ), array( 'https', 'http' ) );
			$west    = (float) ( $region['west'] ?? 0 );
			$south   = (float) ( $region['south'] ?? 0 );
			$east    = (float) ( $region['east'] ?? 0 );
			$north   = (float) ( $region['north'] ?? 0 );

			if ( '' === $name || '' === $package || $west >= $east || $south >= $north ) {
				continue;
			}

			$id      = sanitize_key( (string) ( $region['id'] ?? '' ) );
			$clean[] = array(
				'id'      => '' !== $id ? $id : substr( md5( $name . $package ), 0, 10 ),
				'name'    => $name,
				'package' => $package,
				'west'    => Geo::clamp( $west, -180.0, 180.0 ),
				'south'   => Geo::clamp( $south, -90.0, 90.0 ),
				'east'    => Geo::clamp( $east, -180.0, 180.0 ),
				'north'   => Geo::clamp( $north, -90.0, 90.0 ),
			);
		}//end foreach

		return $clean;
	}
}
