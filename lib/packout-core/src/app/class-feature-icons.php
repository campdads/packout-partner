<?php
/**
 * Map icons from the Material Design Icons set on Iconify.
 *
 * Bodies are the currentColor SVG paths published by Iconify for the mdi
 * collection (Pictogrammers). The picker and the map both draw these.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Material map icons a point of interest may use.
 */
class Feature_Icons {

	public const DEFAULT = 'mdi:map-marker';

	/**
	 * Group labels, in picker order.
	 *
	 * @return array<string, string>
	 */
	public static function groups(): array {
		return array(
			'markers'    => __( 'Markers', 'packout-core' ),
			'navigation' => __( 'Navigation', 'packout-core' ),
			'trail'      => __( 'Trail and land', 'packout-core' ),
			'camp'       => __( 'Camp', 'packout-core' ),
			'access'     => __( 'Access', 'packout-core' ),
			'water'      => __( 'Water', 'packout-core' ),
			'facilities' => __( 'Facilities', 'packout-core' ),
			'caution'    => __( 'Caution', 'packout-core' ),
		);
	}

	/**
	 * Older catalog keys, kept so a saved feature still resolves.
	 *
	 * @return array<string, string>
	 */
	public static function aliases(): array {
		return array(
			'trailhead'  => 'mdi:map-marker',
			'parking'    => 'mdi:parking',
			'water'      => 'mdi:water',
			'camp'       => 'mdi:tent',
			'hazard'     => 'mdi:alert',
			'restroom'   => 'mdi:toilet',
			'viewpoint'  => 'mdi:binoculars',
			'other'      => 'mdi:map-marker',
			'map-marker' => 'mdi:map-marker',
			'pin'        => 'mdi:pin',
		);
	}

	/**
	 * Whether the id is one of these Material icons.
	 *
	 * @param string $id Icon id, such as mdi:map-marker.
	 */
	public static function has( string $id ): bool {
		return isset( self::catalog()[ $id ] );
	}

	/**
	 * SVG element for one icon, or empty.
	 *
	 * @param string $id Icon id.
	 */
	public static function svg( string $id ): string {
		$row = self::catalog()[ $id ] ?? null;
		if ( ! is_array( $row ) || '' === ( $row['body'] ?? '' ) ) {
			return '';
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">' . $row['body'] . '</svg>';
	}

	/**
	 * Every icon as SVG markup, keyed by id.
	 *
	 * @return array<string, string>
	 */
	public static function markup(): array {
		$markup = array();
		foreach ( self::catalog() as $id => $row ) {
			$markup[ $id ] = self::svg( $id );
		}
		return $markup;
	}

	/**
	 * The Material map icons.
	 *
	 * @return array<string, array{label: string, group: string, keywords: string, body: string}>
	 */
	public static function catalog(): array {
		static $catalog = null;
		if ( null !== $catalog ) {
			return $catalog;
		}
		$catalog = array(
			'mdi:map-marker'               => array(
				'label'    => __( 'Marker', 'packout-core' ),
				'group'    => 'markers',
				'keywords' => 'map marker',
				'body'     => '<path fill="currentColor" d="M12 11.5A2.5 2.5 0 0 1 9.5 9A2.5 2.5 0 0 1 12 6.5A2.5 2.5 0 0 1 14.5 9a2.5 2.5 0 0 1-2.5 2.5M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7"/>',
			),
			'mdi:map-marker-radius'        => array(
				'label'    => __( 'Area', 'packout-core' ),
				'group'    => 'markers',
				'keywords' => 'map marker radius',
				'body'     => '<path fill="currentColor" d="M12 2c3.31 0 6 2.66 6 5.95C18 12.41 12 19 12 19S6 12.41 6 7.95C6 4.66 8.69 2 12 2m0 4a2 2 0 0 0-2 2a2 2 0 0 0 2 2a2 2 0 0 0 2-2a2 2 0 0 0-2-2m8 13c0 2.21-3.58 4-8 4s-8-1.79-8-4c0-1.29 1.22-2.44 3.11-3.17l.64.91C6.67 17.19 6 17.81 6 18.5c0 1.38 2.69 2.5 6 2.5s6-1.12 6-2.5c0-.69-.67-1.31-1.75-1.76l.64-.91C18.78 16.56 20 17.71 20 19"/>',
			),
			'mdi:map-marker-star'          => array(
				'label'    => __( 'Star marker', 'packout-core' ),
				'group'    => 'markers',
				'keywords' => 'map marker star',
				'body'     => '<path fill="currentColor" d="M12 2C8.1 2 5 5.1 5 9c0 5.2 7 13 7 13s7-7.8 7-13c0-3.9-3.1-7-7-7m2.5 11L12 11.5L9.5 13l.7-2.8L8 8.3l2.9-.2L12 5.4L13.1 8l2.9.3l-2.2 1.9z"/>',
			),
			'mdi:map-marker-check'         => array(
				'label'    => __( 'Checked marker', 'packout-core' ),
				'group'    => 'markers',
				'keywords' => 'map marker check',
				'body'     => '<path fill="currentColor" d="M12 2c3.86 0 7 3.14 7 7c0 5.25-7 13-7 13S5 14.25 5 9c0-3.86 3.14-7 7-7m-1.53 12L17 7.41L15.6 6l-5.13 5.18L8.4 9.09L7 10.5z"/>',
			),
			'mdi:map-marker-alert'         => array(
				'label'    => __( 'Alert marker', 'packout-core' ),
				'group'    => 'markers',
				'keywords' => 'map marker alert',
				'body'     => '<path fill="currentColor" d="M12 2c3.9 0 7 3.1 7 7c0 5.2-7 13-7 13S5 14.2 5 9c0-3.9 3.1-7 7-7m-1 4v6h2V6zm0 8v2h2v-2z"/>',
			),
			'mdi:map-marker-plus'          => array(
				'label'    => __( 'Add marker', 'packout-core' ),
				'group'    => 'markers',
				'keywords' => 'map marker plus',
				'body'     => '<path fill="currentColor" d="M9 11.5A2.5 2.5 0 0 0 11.5 9A2.5 2.5 0 0 0 9 6.5A2.5 2.5 0 0 0 6.5 9A2.5 2.5 0 0 0 9 11.5M9 2c3.86 0 7 3.13 7 7c0 5.25-7 13-7 13S2 14.25 2 9a7 7 0 0 1 7-7m6 15h3v-3h2v3h3v2h-3v3h-2v-3h-3z"/>',
			),
			'mdi:pin'                      => array(
				'label'    => __( 'Pin', 'packout-core' ),
				'group'    => 'markers',
				'keywords' => 'pin',
				'body'     => '<path fill="currentColor" d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2z"/>',
			),
			'mdi:crosshairs-gps'           => array(
				'label'    => __( 'GPS', 'packout-core' ),
				'group'    => 'markers',
				'keywords' => 'crosshairs gps',
				'body'     => '<path fill="currentColor" d="M12 8a4 4 0 0 1 4 4a4 4 0 0 1-4 4a4 4 0 0 1-4-4a4 4 0 0 1 4-4m-8.95 5H1v-2h2.05C3.5 6.83 6.83 3.5 11 3.05V1h2v2.05c4.17.45 7.5 3.78 7.95 7.95H23v2h-2.05c-.45 4.17-3.78 7.5-7.95 7.95V23h-2v-2.05C6.83 20.5 3.5 17.17 3.05 13M12 5a7 7 0 0 0-7 7a7 7 0 0 0 7 7a7 7 0 0 0 7-7a7 7 0 0 0-7-7"/>',
			),
			'mdi:star'                     => array(
				'label'    => __( 'Star', 'packout-core' ),
				'group'    => 'markers',
				'keywords' => 'star',
				'body'     => '<path fill="currentColor" d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.62L12 2L9.19 8.62L2 9.24l5.45 4.73L5.82 21z"/>',
			),
			'mdi:circle'                   => array(
				'label'    => __( 'Circle', 'packout-core' ),
				'group'    => 'markers',
				'keywords' => 'circle',
				'body'     => '<path fill="currentColor" d="M12 2A10 10 0 0 0 2 12a10 10 0 0 0 10 10a10 10 0 0 0 10-10A10 10 0 0 0 12 2"/>',
			),
			'mdi:triangle'                 => array(
				'label'    => __( 'Triangle', 'packout-core' ),
				'group'    => 'markers',
				'keywords' => 'triangle',
				'body'     => '<path fill="currentColor" d="M1 21h22L12 2"/>',
			),
			'mdi:flag'                     => array(
				'label'    => __( 'Flag', 'packout-core' ),
				'group'    => 'markers',
				'keywords' => 'flag',
				'body'     => '<path fill="currentColor" d="M14.4 6L14 4H5v17h2v-7h5.6l.4 2h7V6z"/>',
			),
			'mdi:flag-variant'             => array(
				'label'    => __( 'Flag variant', 'packout-core' ),
				'group'    => 'markers',
				'keywords' => 'flag variant',
				'body'     => '<path fill="currentColor" d="M6 3a1 1 0 0 1 1 1v.88C8.06 4.44 9.5 4 11 4c3 0 3 2 5 2c3 0 4-2 4-2v8s-1 2-4 2s-3-2-5-2c-3 0-4 2-4 2v7H5V4a1 1 0 0 1 1-1"/>',
			),
			'mdi:compass'                  => array(
				'label'    => __( 'Compass', 'packout-core' ),
				'group'    => 'navigation',
				'keywords' => 'compass',
				'body'     => '<path fill="currentColor" d="M14.19 14.19L6 18l3.81-8.19L18 6m-6-4A10 10 0 0 0 2 12a10 10 0 0 0 10 10a10 10 0 0 0 10-10A10 10 0 0 0 12 2m0 8.9a1.1 1.1 0 0 0-1.1 1.1a1.1 1.1 0 0 0 1.1 1.1a1.1 1.1 0 0 0 1.1-1.1a1.1 1.1 0 0 0-1.1-1.1"/>',
			),
			'mdi:compass-outline'          => array(
				'label'    => __( 'Compass outline', 'packout-core' ),
				'group'    => 'navigation',
				'keywords' => 'compass outline',
				'body'     => '<path fill="currentColor" d="m7 17l3.2-6.8L17 7l-3.2 6.8zm5-5.9a.9.9 0 0 0-.9.9a.9.9 0 0 0 .9.9a.9.9 0 0 0 .9-.9a.9.9 0 0 0-.9-.9M12 2a10 10 0 0 1 10 10a10 10 0 0 1-10 10A10 10 0 0 1 2 12A10 10 0 0 1 12 2m0 2a8 8 0 0 0-8 8a8 8 0 0 0 8 8a8 8 0 0 0 8-8a8 8 0 0 0-8-8"/>',
			),
			'mdi:navigation'               => array(
				'label'    => __( 'Navigation', 'packout-core' ),
				'group'    => 'navigation',
				'keywords' => 'navigation',
				'body'     => '<path fill="currentColor" d="M12 2L4.5 20.29l.71.71L12 18l6.79 3l.71-.71z"/>',
			),
			'mdi:sign-direction'           => array(
				'label'    => __( 'Direction sign', 'packout-core' ),
				'group'    => 'navigation',
				'keywords' => 'sign direction',
				'body'     => '<path fill="currentColor" d="M11 12H3.5L6 9.5L3.5 7H11V3l1-1l1 1v4h5l2.5 2.5L18 12h-5v8a2 2 0 0 1 2 2H9a2 2 0 0 1 2-2z"/>',
			),
			'mdi:map'                      => array(
				'label'    => __( 'Map', 'packout-core' ),
				'group'    => 'navigation',
				'keywords' => 'map',
				'body'     => '<path fill="currentColor" d="m15 19l-6-2.11V5l6 2.11M20.5 3h-.16L15 5.1L9 3L3.36 4.9c-.21.07-.36.25-.36.48V20.5a.5.5 0 0 0 .5.5c.05 0 .11 0 .16-.03L9 18.9l6 2.1l5.64-1.9c.21-.1.36-.25.36-.48V3.5a.5.5 0 0 0-.5-.5"/>',
			),
			'mdi:map-search'               => array(
				'label'    => __( 'Map search', 'packout-core' ),
				'group'    => 'navigation',
				'keywords' => 'map search',
				'body'     => '<path fill="currentColor" d="M15.5 12c2.5 0 4.5 2 4.5 4.5c0 .88-.25 1.71-.69 2.4l3.08 3.1L21 23.39l-3.12-3.07c-.69.43-1.51.68-2.38.68c-2.5 0-4.5-2-4.5-4.5s2-4.5 4.5-4.5m0 2a2.5 2.5 0 0 0-2.5 2.5a2.5 2.5 0 0 0 2.5 2.5a2.5 2.5 0 0 0 2.5-2.5a2.5 2.5 0 0 0-2.5-2.5M14 6.11L8 4v11.89l1 .35v.26c0 .64.09 1.26.26 1.84L8 17.9l-5.34 2.07l-.16.03a.5.5 0 0 1-.5-.5V4.38c0-.23.15-.41.36-.48L8 2l6 2.1L19.34 2h.16a.5.5 0 0 1 .5.5v9.31A6.48 6.48 0 0 0 15.5 10c-.5 0-1 .06-1.5.17z"/>',
			),
			'mdi:routes'                   => array(
				'label'    => __( 'Routes', 'packout-core' ),
				'group'    => 'navigation',
				'keywords' => 'routes',
				'body'     => '<path fill="currentColor" d="M11 10H5L3 8l2-2h6V3l1-1l1 1v1h6l2 2l-2 2h-6v2h6l2 2l-2 2h-6v6a2 2 0 0 1 2 2H9a2 2 0 0 1 2-2z"/>',
			),
			'mdi:hiking'                   => array(
				'label'    => __( 'Hiking', 'packout-core' ),
				'group'    => 'trail',
				'keywords' => 'hiking',
				'body'     => '<path fill="currentColor" d="M17.47 8.67H19V23h-1.53V12.6c-.8-.16-1.55-.46-2.26-.89s-1.31-.93-1.82-1.51l-.62 3.07l2.23 2.2V23h-2v-6l-2.24-2.2L8.89 23H6.73S9.86 7.22 9.89 7.09c.11-.48.33-.85.7-1.09c.37-.27.74-.4 1.12-.4q.585 0 1.08.27c.34.17.6.42.79.74l1.06 1.63c.29.54.68 1.01 1.17 1.39s1.05.67 1.66.87zM8.55 5.89L7.4 5.65c-.57-.15-1.09-.03-1.56.29c-.46.32-.74.76-.84 1.34l-.81 3.98c-.03.29.03.55.19.79s.37.37.62.41l2.21.43zM13 1c-1.1 0-2 .9-2 2s.9 2 2 2s2-.89 2-2s-.89-2-2-2"/>',
			),
			'mdi:walk'                     => array(
				'label'    => __( 'Walk', 'packout-core' ),
				'group'    => 'trail',
				'keywords' => 'walk',
				'body'     => '<path fill="currentColor" d="M14.12 10H19V8.2h-3.62l-2-3.33c-.3-.5-.84-.84-1.46-.84c-.18 0-.34.03-.5.08L6 5.8V11h1.8V7.33l2.11-.66L6 22h1.8l2.87-8.11L13 17v5h1.8v-6.41l-2.49-4.54l.73-2.87M14 3.8c1 0 1.8-.8 1.8-1.8S15 .2 14 .2S12.2 1 12.2 2S13 3.8 14 3.8"/>',
			),
			'mdi:pine-tree'                => array(
				'label'    => __( 'Pine', 'packout-core' ),
				'group'    => 'trail',
				'keywords' => 'pine tree',
				'body'     => '<path fill="currentColor" d="M10 21v-3H3l5-5H5l5-5H7l5-5l5 5h-3l5 5h-3l5 5h-7v3z"/>',
			),
			'mdi:tree'                     => array(
				'label'    => __( 'Tree', 'packout-core' ),
				'group'    => 'trail',
				'keywords' => 'tree',
				'body'     => '<path fill="currentColor" d="M11 21v-4.26c-.47.17-.97.26-1.5.26C7 17 5 15 5 12.5c0-1.27.5-2.41 1.36-3.23C6.13 8.73 6 8.13 6 7.5C6 5 8 3 10.5 3c1.56 0 2.94.8 3.75 2h.25a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5q-.75 0-1.5-.21V21z"/>',
			),
			'mdi:image-filter-hdr'         => array(
				'label'    => __( 'Mountain', 'packout-core' ),
				'group'    => 'trail',
				'keywords' => 'image filter hdr',
				'body'     => '<path fill="currentColor" d="m14 6l-3.75 5l2.85 3.8l-1.6 1.2C9.81 13.75 7 10 7 10l-6 8h22z"/>',
			),
			'mdi:terrain'                  => array(
				'label'    => __( 'Terrain', 'packout-core' ),
				'group'    => 'trail',
				'keywords' => 'terrain',
				'body'     => '<path fill="currentColor" d="m14 6l-3.75 5l2.85 3.8l-1.6 1.2C9.81 13.75 7 10 7 10l-6 8h22z"/>',
			),
			'mdi:binoculars'               => array(
				'label'    => __( 'Viewpoint', 'packout-core' ),
				'group'    => 'trail',
				'keywords' => 'binoculars',
				'body'     => '<path fill="currentColor" d="M11 6h2v7h-2zM9 20a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-5l2-9h4v7a1 1 0 0 1-1 1zm1-15H7V3h3zm5 15v-6a1 1 0 0 1-1-1V6h4l2 9v5a1 1 0 0 1-1 1h-3a1 1 0 0 1-1-1M14 5V3h3v2z"/>',
			),
			'mdi:bridge'                   => array(
				'label'    => __( 'Bridge', 'packout-core' ),
				'group'    => 'trail',
				'keywords' => 'bridge',
				'body'     => '<path fill="currentColor" d="M7 14v-3.09c-.72-.33-1.39-.73-2-1.2V14zm-2 4H3v-2H1v-2h2V7h2v1.43C6.8 10 9.27 11 12 11s5.2-1 7-2.57V7h2v7h2v2h-2v2h-2v-2H5zm12-7.09V14h2V9.71c-.61.47-1.28.87-2 1.2M16 14v-2.68c-.64.23-1.31.4-2 .52V14zm-3 0v-2.04L12 12l-1-.04V14zm-3 0v-2.16c-.69-.12-1.36-.29-2-.52V14z"/>',
			),
			'mdi:fence'                    => array(
				'label'    => __( 'Fence', 'packout-core' ),
				'group'    => 'trail',
				'keywords' => 'fence',
				'body'     => '<path fill="currentColor" d="M9 9v2H7V9H5v2H3V9H1v12h2v-2h2v2h2v-2h2v2h2v-2h2v2h2v-2h2v2h2v-2h2v2h2V9h-2v2h-2V9h-2v2h-2V9h-2v2h-2V9zm-6 4h2v4H3zm4 0h2v4H7zm4 0h2v4h-2zm4 0h2v4h-2zm4 0h2v4h-2z"/>',
			),
			'mdi:boom-gate'                => array(
				'label'    => __( 'Gate', 'packout-core' ),
				'group'    => 'trail',
				'keywords' => 'boom gate',
				'body'     => '<path fill="currentColor" d="M20 9H8.22A2.97 2.97 0 0 0 4 8.8c-.64.56-1 1.36-1 2.2v9a1 1 0 0 0-1 1v1h8v-1a1 1 0 0 0-1-1v-7h11a2 2 0 0 0 2-2a2 2 0 0 0-2-2M6 12.5A1.5 1.5 0 0 1 4.5 11A1.5 1.5 0 0 1 6 9.5A1.5 1.5 0 0 1 7.5 11A1.5 1.5 0 0 1 6 12.5m4.5-.5L9 10h1.5l1.5 2zm4 0L13 10h1.5l1.5 2zm4 0L17 10h1.5l1.5 2z"/>',
			),
			'mdi:paw'                      => array(
				'label'    => __( 'Wildlife', 'packout-core' ),
				'group'    => 'trail',
				'keywords' => 'paw',
				'body'     => '<path fill="currentColor" d="M8.35 3c1.18-.17 2.43 1.12 2.79 2.9c.36 1.77-.29 3.35-1.47 3.53c-1.17.18-2.43-1.11-2.8-2.89c-.37-1.77.3-3.35 1.48-3.54m7.15 0c1.19.19 1.85 1.77 1.5 3.54c-.38 1.78-1.63 3.07-2.81 2.89c-1.19-.18-1.84-1.76-1.47-3.53c.36-1.78 1.61-3.07 2.78-2.9M3 7.6c1.14-.49 2.69.4 3.5 1.95c.76 1.58.5 3.24-.63 3.73s-2.67-.39-3.46-1.96S1.9 8.08 3 7.6m18 0c1.1.48 1.38 2.15.59 3.72s-2.33 2.45-3.46 1.96s-1.39-2.15-.63-3.73C18.31 8 19.86 7.11 21 7.6m-1.67 10.78c.04.94-.68 1.98-1.54 2.37c-1.79.82-3.91-.88-5.9-.88s-4.13 1.77-5.89.88c-1-.49-1.69-1.79-1.56-2.87c.18-1.49 1.97-2.29 3.03-3.38c1.41-1.41 2.41-4.06 4.42-4.06c2 0 3.06 2.61 4.41 4.06c1.11 1.22 2.96 2.25 3.03 3.88"/>',
			),
			'mdi:leaf'                     => array(
				'label'    => __( 'Leaf', 'packout-core' ),
				'group'    => 'trail',
				'keywords' => 'leaf',
				'body'     => '<path fill="currentColor" d="M17 8C8 10 5.9 16.17 3.82 21.34l1.89.66l.95-2.3c.48.17.98.3 1.34.3C19 20 22 3 22 3c-1 2-8 2.25-13 3.25S2 11.5 2 13.5s1.75 3.75 1.75 3.75C7 8 17 8 17 8"/>',
			),
			'mdi:flower'                   => array(
				'label'    => __( 'Flower', 'packout-core' ),
				'group'    => 'trail',
				'keywords' => 'flower',
				'body'     => '<path fill="currentColor" d="M3 13a9 9 0 0 0 9 9c0-5-4.03-9-9-9m9-7.5A2.5 2.5 0 0 1 14.5 8a2.5 2.5 0 0 1-2.5 2.5A2.5 2.5 0 0 1 9.5 8A2.5 2.5 0 0 1 12 5.5m-6.4 4.75a2.5 2.5 0 0 0 2.5 2.5c.53 0 1.02-.17 1.4-.44v.19A2.5 2.5 0 0 0 12 15a2.5 2.5 0 0 0 2.5-2.5v-.19c.38.27.87.44 1.4.44a2.5 2.5 0 0 0 2.5-2.5c0-1-.59-1.85-1.43-2.25c.84-.4 1.43-1.26 1.43-2.25a2.5 2.5 0 0 0-2.5-2.5c-.53 0-1.02.16-1.4.44V3.5A2.5 2.5 0 0 0 12 1a2.5 2.5 0 0 0-2.5 2.5v.19c-.38-.28-.87-.44-1.4-.44a2.5 2.5 0 0 0-2.5 2.5c0 .99.59 1.85 1.43 2.25c-.84.4-1.43 1.25-1.43 2.25M12 22a9 9 0 0 0 9-9c-5 0-9 4-9 9"/>',
			),
			'mdi:bug'                      => array(
				'label'    => __( 'Insect', 'packout-core' ),
				'group'    => 'trail',
				'keywords' => 'bug',
				'body'     => '<path fill="currentColor" d="M14 12h-4v-2h4m0 6h-4v-2h4m6-6h-2.81a6 6 0 0 0-1.82-1.96L17 4.41L15.59 3l-2.17 2.17a6 6 0 0 0-2.83 0L8.41 3L7 4.41l1.62 1.63C7.88 6.55 7.26 7.22 6.81 8H4v2h2.09c-.05.33-.09.66-.09 1v1H4v2h2v1c0 .34.04.67.09 1H4v2h2.81c1.04 1.79 2.97 3 5.19 3s4.15-1.21 5.19-3H20v-2h-2.09c.05-.33.09-.66.09-1v-1h2v-2h-2v-1c0-.34-.04-.67-.09-1H20z"/>',
			),
			'mdi:tent'                     => array(
				'label'    => __( 'Tent', 'packout-core' ),
				'group'    => 'camp',
				'keywords' => 'tent',
				'body'     => '<path fill="currentColor" d="M4 6c0 1.19.39 2.27 1 3a3 3 0 0 1-3-3a3 3 0 0 1 3-3c-.61.73-1 1.81-1 3M2 21v-2h2.76L12 4.78L19.24 19H22v2zM12 9.19L7 19h10z"/>',
			),
			'mdi:campfire'                 => array(
				'label'    => __( 'Campfire', 'packout-core' ),
				'group'    => 'camp',
				'keywords' => 'campfire',
				'body'     => '<path fill="currentColor" d="m15.9 18.5l6 1.6l-.4 1.9l-9.4-2.5L2.7 22l-.5-1.9l6-1.6l-6.1-1.6l.5-1.9l9.4 2.5l9.4-2.5l.5 1.9zm.17-10.58c-.16-.22-.36-.42-.57-.59c-.45-.43-1-.74-1.44-1.2C13 5.08 12.79 3.34 13.44 2c-.65.17-1.26.54-1.77.95C9.84 4.46 9.11 7.1 10 9.38c0 .07.04.15.04.24c0 .16-.11.3-.25.38a.51.51 0 0 1-.57-.23c-.8-1.03-.93-2.51-.38-3.7c-1.19.99-1.84 2.65-1.73 4.22c.02.36.07.71.19 1.07c.11.44.29.87.52 1.25c.75 1.25 2.08 2.15 3.5 2.33c1.52.2 3.14-.09 4.31-1.15c1.3-1.2 1.77-3.12 1.08-4.79l-.1-.17q-.21-.495-.57-.9zm-2.24 4.55c-.2.18-.52.36-.78.44c-.79.28-1.59-.12-2.05-.6c.84-.2 1.34-.84 1.5-1.48c.11-.58-.11-1.05-.21-1.61c-.08-.53-.07-.99.13-1.49c.13.27.27.55.44.77c.55.72 1.41 1.04 1.59 2c.02.13.05.22.05.33c0 .6-.24 1.24-.68 1.64"/>',
			),
			'mdi:flashlight'               => array(
				'label'    => __( 'Flashlight', 'packout-core' ),
				'group'    => 'camp',
				'keywords' => 'flashlight',
				'body'     => '<path fill="currentColor" d="M9 10L6 5h12l-3 5zm9-6H6V2h12zM9 22V11h6v11zm3-9a1 1 0 0 0-1 1a1 1 0 0 0 1 1a1 1 0 0 0 1-1a1 1 0 0 0-1-1"/>',
			),
			'mdi:home'                     => array(
				'label'    => __( 'Cabin', 'packout-core' ),
				'group'    => 'camp',
				'keywords' => 'home',
				'body'     => '<path fill="currentColor" d="M10 20v-6h4v6h5v-8h3L12 3L2 12h3v8z"/>',
			),
			'mdi:home-variant'             => array(
				'label'    => __( 'Shelter', 'packout-core' ),
				'group'    => 'camp',
				'keywords' => 'home variant',
				'body'     => '<path fill="currentColor" d="m12 3l8 6v12h-5v-7H9v7H4V9z"/>',
			),
			'mdi:grill'                    => array(
				'label'    => __( 'Grill', 'packout-core' ),
				'group'    => 'camp',
				'keywords' => 'grill',
				'body'     => '<path fill="currentColor" d="M8.06 2c-.18 1.17.11 2.16.89 2.97c.5.5.66 1.17.47 2.03h.99c.12-.55.14-1 .04-1.45c-.09-.42-.4-.92-.95-1.52c-.45-.56-.61-1.23-.45-2.03zm2.49 0c-.19 1.17.11 2.16.89 2.97c.5.5.65 1.17.47 2.03h.98c.11-.55.14-1 .05-1.45c-.1-.42-.41-.92-.94-1.52c-.47-.56-.62-1.23-.47-2.03zm2.53 0c-.19 1.17.11 2.16.89 2.97c.5.5.64 1.17.42 2.03h1.03c.13-.55.14-1 .05-1.45c-.09-.42-.41-.92-.94-1.52c-.47-.56-.62-1.23-.47-2.03zM5 8c0 1.42.39 2.7 1.14 3.84a6.9 6.9 0 0 0 3 2.55l-3.98 6.05c-.1.12-.16.31-.16.56c0 .41.16.69.44.84c.12.1.31.16.56.16c.41 0 .69-.16.84-.44l.99-1.59h6.37c.21.58.59 1.08 1.08 1.45c.5.38 1.08.58 1.72.58c.83 0 1.53-.31 2.13-.91c.59-.59.87-1.29.87-2.09c0-.83-.28-1.53-.87-2.12c-.6-.6-1.3-.88-2.13-.88c-.64 0-1.22.17-1.72.55c-.5.37-.86.86-1.08 1.45H9.14l1.97-3.05c.16.05.45.05.89.05s.73 0 .89-.05l.99 1.55c.41-.54.96-.96 1.59-1.22l-.56-.89c1.12-.5 2.09-1.39 2.88-2.62C18.59 10.5 19 9.27 19 8zm12 10c.3 0 .53.09.72.28s.28.44.28.72c0 .27-.09.5-.28.71c-.18.2-.44.29-.72.29c-.26 0-.5-.09-.71-.29A1 1 0 0 1 16 19c0-.3.09-.53.29-.72c.21-.19.44-.28.71-.28"/>',
			),
			'mdi:parking'                  => array(
				'label'    => __( 'Parking', 'packout-core' ),
				'group'    => 'access',
				'keywords' => 'parking',
				'body'     => '<path fill="currentColor" d="M13.2 11H10V7h3.2a2 2 0 0 1 2 2a2 2 0 0 1-2 2M13 3H6v18h4v-6h3a6 6 0 0 0 6-6c0-3.32-2.69-6-6-6"/>',
			),
			'mdi:car'                      => array(
				'label'    => __( 'Car', 'packout-core' ),
				'group'    => 'access',
				'keywords' => 'car',
				'body'     => '<path fill="currentColor" d="m5 11l1.5-4.5h11L19 11m-1.5 5a1.5 1.5 0 0 1-1.5-1.5a1.5 1.5 0 0 1 1.5-1.5a1.5 1.5 0 0 1 1.5 1.5a1.5 1.5 0 0 1-1.5 1.5m-11 0A1.5 1.5 0 0 1 5 14.5A1.5 1.5 0 0 1 6.5 13A1.5 1.5 0 0 1 8 14.5A1.5 1.5 0 0 1 6.5 16M18.92 6c-.2-.58-.76-1-1.42-1h-11c-.66 0-1.22.42-1.42 1L3 12v8a1 1 0 0 0 1 1h1a1 1 0 0 0 1-1v-1h12v1a1 1 0 0 0 1 1h1a1 1 0 0 0 1-1v-8z"/>',
			),
			'mdi:car-estate'               => array(
				'label'    => __( 'Wagon', 'packout-core' ),
				'group'    => 'access',
				'keywords' => 'car estate',
				'body'     => '<path fill="currentColor" d="M3 6h13l3 4h2c1.11 0 2 .89 2 2v3h-2a3 3 0 0 1-3 3a3 3 0 0 1-3-3H9a3 3 0 0 1-3 3a3 3 0 0 1-3-3H1V8c0-1.11.89-2 2-2m-.5 1.5V10h8V7.5zm9.5 0V10h5.14l-1.89-2.5zm-6 6A1.5 1.5 0 0 0 4.5 15A1.5 1.5 0 0 0 6 16.5A1.5 1.5 0 0 0 7.5 15A1.5 1.5 0 0 0 6 13.5m12 0a1.5 1.5 0 0 0-1.5 1.5a1.5 1.5 0 0 0 1.5 1.5a1.5 1.5 0 0 0 1.5-1.5a1.5 1.5 0 0 0-1.5-1.5"/>',
			),
			'mdi:rv-truck'                 => array(
				'label'    => __( 'RV', 'packout-core' ),
				'group'    => 'access',
				'keywords' => 'rv truck',
				'body'     => '<path fill="currentColor" d="M20 8h2V6l-2-2H3a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h1a3 3 0 0 0 3 3a3 3 0 0 0 3-3h5a3 3 0 0 0 6 0h2v-5M7 18.5a1.5 1.5 0 0 1 0-3a1.5 1.5 0 0 1 0 3M9 12H3V9h6m5 6h-3V9h3m4 9.5a1.5 1.5 0 1 1 1.5-1.5a1.54 1.54 0 0 1-1.5 1.5M17 12V9.5h2.5l2 2.5Z"/>',
			),
			'mdi:bicycle'                  => array(
				'label'    => __( 'Bicycle', 'packout-core' ),
				'group'    => 'access',
				'keywords' => 'bicycle',
				'body'     => '<path fill="currentColor" d="M19 10c-.56 0-1.09.11-1.59.28L14.46 4.5H11V6h2.54l.88 1.72L12 13.13l-1.77-4.18c.27-.1.51-.37.51-.7c0-.41-.33-.75-.74-.75H8c-.42 0-.76.34-.76.75S7.58 9 8 9h.61l2.25 5.25h-.94C9.56 11.85 7.5 10 5 10c-2.76 0-5 2.24-5 5s2.24 5 5 5c2.5 0 4.56-1.85 4.92-4.25h2.58l2.79-6.32l.79 1.53A4.98 4.98 0 0 0 14 15c0 2.76 2.24 5 5 5s5-2.24 5-5s-2.24-5-5-5M5 18.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5c1.67 0 3.07 1.18 3.41 2.75H4v1.5h4.41A3.495 3.495 0 0 1 5 18.5m14 0c-1.93 0-3.5-1.57-3.5-3.5c0-1.08.5-2.03 1.27-2.67l1.8 3.52l1.32-.72l-1.79-3.5c.29-.07.59-.13.9-.13c1.93 0 3.5 1.57 3.5 3.5s-1.57 3.5-3.5 3.5"/>',
			),
			'mdi:motorbike'                => array(
				'label'    => __( 'Motorcycle', 'packout-core' ),
				'group'    => 'access',
				'keywords' => 'motorbike',
				'body'     => '<path fill="currentColor" d="m17.42 10l-4.01-4H9v2h3.59l2 2H6.5C4 10 2 12 2 14.5S4 19 6.5 19c2.22 0 4.06-1.62 4.42-3.73L13.04 14c-.04.17-.04.33-.04.5c0 2.5 2 4.5 4.5 4.5s4.5-2 4.5-4.5s-2-4.5-4.5-4.5m-8.66 5.26C8.5 16.27 7.58 17 6.47 17a2.5 2.5 0 0 1 0-5c1.12 0 2.03.74 2.37 1.75H6v1.5zM17.47 17a2.5 2.5 0 0 1 0-5a2.5 2.5 0 0 1 2.5 2.5a2.5 2.5 0 0 1-2.5 2.5"/>',
			),
			'mdi:gas-station'              => array(
				'label'    => __( 'Fuel', 'packout-core' ),
				'group'    => 'access',
				'keywords' => 'gas station',
				'body'     => '<path fill="currentColor" d="M18 10a1 1 0 0 1-1-1a1 1 0 0 1 1-1a1 1 0 0 1 1 1a1 1 0 0 1-1 1m-6 0H6V5h6m7.77 2.23l.01-.01l-3.72-3.72L15 4.56l2.11 2.11C16.17 7 15.5 7.93 15.5 9a2.5 2.5 0 0 0 2.5 2.5c.36 0 .69-.08 1-.21v7.21a1 1 0 0 1-1 1a1 1 0 0 1-1-1V14a2 2 0 0 0-2-2h-1V5a2 2 0 0 0-2-2H6c-1.11 0-2 .89-2 2v16h10v-7.5h1.5v5A2.5 2.5 0 0 0 18 21a2.5 2.5 0 0 0 2.5-2.5V9c0-.69-.28-1.32-.73-1.77"/>',
			),
			'mdi:road-variant'             => array(
				'label'    => __( 'Road', 'packout-core' ),
				'group'    => 'access',
				'keywords' => 'road variant',
				'body'     => '<path fill="currentColor" d="M18.1 4.8c-.1-.5-.5-.8-1-.8H13l.2 3h-2.4l.2-3H6.8c-.5 0-.9.4-1 .8l-2.7 14c-.1.6.4 1.2 1 1.2H10l.3-5h3.4l.3 5h5.8c.6 0 1.1-.6 1-1.2zM10.4 13l.2-4h2.6l.2 4z"/>',
			),
			'mdi:water'                    => array(
				'label'    => __( 'Water', 'packout-core' ),
				'group'    => 'water',
				'keywords' => 'water',
				'body'     => '<path fill="currentColor" d="M12 20a6 6 0 0 1-6-6c0-4 6-10.75 6-10.75S18 10 18 14a6 6 0 0 1-6 6"/>',
			),
			'mdi:water-pump'               => array(
				'label'    => __( 'Water pump', 'packout-core' ),
				'group'    => 'water',
				'keywords' => 'water pump',
				'body'     => '<path fill="currentColor" d="M19 14.5s2 2.17 2 3.5a2 2 0 0 1-2 2a2 2 0 0 1-2-2c0-1.33 2-3.5 2-3.5M5 18V9a2 2 0 0 1-2-2a2 2 0 0 1 2-2V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v1h8a2 2 0 0 1 2 2v4a1 1 0 0 1 1 1a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1a1 1 0 0 1 1-1V9h-6v9h1a2 2 0 0 1 2 2v2H2v-2a2 2 0 0 1 2-2z"/>',
			),
			'mdi:waves'                    => array(
				'label'    => __( 'Waves', 'packout-core' ),
				'group'    => 'water',
				'keywords' => 'waves',
				'body'     => '<path fill="currentColor" d="M20 12h2v2h-2c-1.38 0-2.74-.35-4-1c-2.5 1.3-5.5 1.3-8 0c-1.26.65-2.63 1-4 1H2v-2h2c1.39 0 2.78-.47 4-1.33c2.44 1.71 5.56 1.71 8 0c1.22.86 2.61 1.33 4 1.33m0-6h2v2h-2c-1.38 0-2.74-.35-4-1c-2.5 1.3-5.5 1.3-8 0c-1.26.65-2.63 1-4 1H2V6h2c1.39 0 2.78-.47 4-1.33c2.44 1.71 5.56 1.71 8 0C17.22 5.53 18.61 6 20 6m0 12h2v2h-2c-1.38 0-2.74-.35-4-1c-2.5 1.3-5.5 1.3-8 0c-1.26.65-2.63 1-4 1H2v-2h2c1.39 0 2.78-.47 4-1.33c2.44 1.71 5.56 1.71 8 0c1.22.86 2.61 1.33 4 1.33"/>',
			),
			'mdi:fish'                     => array(
				'label'    => __( 'Fish', 'packout-core' ),
				'group'    => 'water',
				'keywords' => 'fish',
				'body'     => '<path fill="currentColor" d="m12 20l.76-3c-3.26-.21-6.17-1.6-7.01-3.42c-.09.48-.22.92-.42 1.25C4.67 16 3.33 16 2 16c1.1 0 1.5-1.57 1.5-3.5S3.1 9 2 9c1.33 0 2.67 0 3.33 1.17c.2.33.33.77.42 1.25c.65-1.42 2.57-2.57 4.91-3.1L9 5c2 0 4 0 5.33.67c1.13.56 1.78 1.6 2.36 2.71c2.92.7 5.31 2.28 5.31 4.12c0 1.88-2.5 3.5-5.5 4.16c-.83 1.1-1.64 2.12-2.33 2.67c-.84.67-1.5.67-2.17.67m5-9a1 1 0 0 0-1 1a1 1 0 0 0 1 1a1 1 0 0 0 1-1a1 1 0 0 0-1-1"/>',
			),
			'mdi:sail-boat'                => array(
				'label'    => __( 'Boat', 'packout-core' ),
				'group'    => 'water',
				'keywords' => 'sail boat',
				'body'     => '<path fill="currentColor" d="m3 13.5l8-11.47V13.5zm9.5 0c1.35-3.75 1.17-8.79 0-12.5c4.76 1.54 8.4 7.4 8.46 12.5zm8.6 3.58c-.41.64-.89 1.19-1.45 1.66c-.65-.29-1.23-.74-1.69-1.24c-1.49 1.93-4.5 1.93-5.99 0c-1.47 1.93-4.5 1.93-5.97 0c-.5.5-1.05.95-1.7 1.24c-1.14-.94-2-2.28-2.3-3.74h19.94a6.4 6.4 0 0 1-.84 2.08M20.96 23q-1.59 0-3-.75c-1.84 1-4.15 1-5.99 0c-1.84 1-4.15 1-5.97 0c-1.23.69-2.64.8-4 .75v-2c1.41.05 2.77-.1 4-1c1.74 1.25 4.21 1.25 5.97 0c1.77 1.25 4.23 1.25 5.99 0c1.21.9 2.58 1.05 3.98 1v2z"/>',
			),
			'mdi:ferry'                    => array(
				'label'    => __( 'Ferry', 'packout-core' ),
				'group'    => 'water',
				'keywords' => 'ferry',
				'body'     => '<path fill="currentColor" d="M6 6h12v3.96L12 8L6 9.96M3.94 19H4c1.6 0 3-.88 4-2c1 1.12 2.4 2 4 2s3-.88 4-2c1 1.12 2.4 2 4 2h.05l1.9-6.69c.08-.25.05-.53-.06-.77c-.13-.24-.34-.42-.6-.5L20 10.62V6a2 2 0 0 0-2-2h-3V1H9v3H6a2 2 0 0 0-2 2v4.62l-1.29.42c-.26.08-.47.26-.6.5c-.11.24-.14.52-.06.77M20 21c-1.39 0-2.78-.47-4-1.33c-2.44 1.71-5.56 1.71-8 0C6.78 20.53 5.39 21 4 21H2v2h2c1.37 0 2.74-.35 4-1c2.5 1.3 5.5 1.3 8 0c1.26.65 2.62 1 4 1h2v-2z"/>',
			),
			'mdi:waterfall'                => array(
				'label'    => __( 'Waterfall', 'packout-core' ),
				'group'    => 'water',
				'keywords' => 'waterfall',
				'body'     => '<path fill="currentColor" d="M20 20c-1.39 0-2.78-.47-4-1.33c-2.44 1.71-5.56 1.71-8 0C6.78 19.53 5.39 20 4 20H2v2h2c1.37 0 2.74-.35 4-1c2.5 1.3 5.5 1.3 8 0c1.26.65 2.62 1 4 1h2v-2m-2-4c-1.39 0-2.78-.47-4-1.33c-2.44 1.71-5.56 1.71-8 0C6.78 15.53 5.39 16 4 16H2v2h2c1.37 0 2.74-.35 4-1c2.5 1.3 5.5 1.3 8 0c1.26.65 2.62 1 4 1h2v-2m0-14H2v2h4v12h12V4h4M9 4h2v6H9m4-2h2v6h-2Z"/>',
			),
			'mdi:toilet'                   => array(
				'label'    => __( 'Restroom', 'packout-core' ),
				'group'    => 'facilities',
				'keywords' => 'toilet',
				'body'     => '<path fill="currentColor" d="M9 22h8v-2.5c2.41-1.63 4-4.38 4-7.5V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v8H3c0 3.09 2 6 6 7.5zm-3.71-8h13.42A7.02 7.02 0 0 1 15 18.33V20h-4v-1.67C9 18 5.86 15.91 5.29 14M15 4h4v8h-4zm1 1v3h2V5z"/>',
			),
			'mdi:shower'                   => array(
				'label'    => __( 'Shower', 'packout-core' ),
				'group'    => 'facilities',
				'keywords' => 'shower',
				'body'     => '<path fill="currentColor" d="M21 14v1c0 1.91-1.07 3.57-2.65 4.41L19 22h-2l-.5-2h-9L7 22H5l.65-2.59A4.99 4.99 0 0 1 3 15v-1H2v-2h18V5a1 1 0 0 0-1-1c-.5 0-.88.34-1 .79c.63.54 1 1.34 1 2.21h-6a3 3 0 0 1 3-3h.17c.41-1.16 1.52-2 2.83-2a3 3 0 0 1 3 3v9zm-2 0H5v1a3 3 0 0 0 3 3h8a3 3 0 0 0 3-3z"/>',
			),
			'mdi:table-picnic'             => array(
				'label'    => __( 'Picnic', 'packout-core' ),
				'group'    => 'facilities',
				'keywords' => 'table picnic',
				'body'     => '<path fill="currentColor" d="M4 6h16v3h-2l.22 2H23v2h-4.56l.56 5h-2.5l-.56-5H8.06l-.56 5H5l.56-5H1v-2h4.78L6 9H4m11.5 0h-7l-.21 2h7.42Z"/>',
			),
			'mdi:information'              => array(
				'label'    => __( 'Information', 'packout-core' ),
				'group'    => 'facilities',
				'keywords' => 'information',
				'body'     => '<path fill="currentColor" d="M13 9h-2V7h2m0 10h-2v-6h2m-1-9A10 10 0 0 0 2 12a10 10 0 0 0 10 10a10 10 0 0 0 10-10A10 10 0 0 0 12 2"/>',
			),
			'mdi:food'                     => array(
				'label'    => __( 'Food', 'packout-core' ),
				'group'    => 'facilities',
				'keywords' => 'food',
				'body'     => '<path fill="currentColor" d="M18.06 23h1.66c.84 0 1.53-.65 1.63-1.47L23 5.05h-5V1h-1.97v4.05h-4.97l.3 2.34c1.71.47 3.31 1.32 4.27 2.26c1.44 1.42 2.43 2.89 2.43 5.29zM1 22v-1h15.03v1c0 .54-.45 1-1.03 1H2c-.55 0-1-.46-1-1m15.03-7C16.03 7 1 7 1 15zM1 17h15v2H1z"/>',
			),
			'mdi:medical-bag'              => array(
				'label'    => __( 'First aid', 'packout-core' ),
				'group'    => 'facilities',
				'keywords' => 'medical bag',
				'body'     => '<path fill="currentColor" d="M10 3L8 5v2H5C3.85 7 3.12 8 3 9L2 19c-.12 1 .54 2 2 2h16c1.46 0 2.12-1 2-2L21 9c-.12-1-.94-2-2-2h-3V5l-2-2zm0 2h4v2h-4zm1 5h2v3h3v2h-3v3h-2v-3H8v-2h3z"/>',
			),
			'mdi:delete'                   => array(
				'label'    => __( 'Trash', 'packout-core' ),
				'group'    => 'facilities',
				'keywords' => 'delete',
				'body'     => '<path fill="currentColor" d="M19 4h-3.5l-1-1h-5l-1 1H5v2h14M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6z"/>',
			),
			'mdi:recycle'                  => array(
				'label'    => __( 'Recycling', 'packout-core' ),
				'group'    => 'facilities',
				'keywords' => 'recycle',
				'body'     => '<path fill="currentColor" d="m21.82 15.42l-2.5 4.33c-.49.86-1.4 1.31-2.32 1.25h-2v2l-2.5-4.5L15 14v2h2.82l-2.22-3.85l4.33-2.5l1.8 3.12c.52.77.59 1.8.09 2.65M9.21 3.06h5c.98 0 1.83.57 2.24 1.39l1 1.74l1.73-1l-2.64 4.41l-5.15.09l1.73-1l-1.41-2.45l-2.21 3.85l-4.34-2.5l1.8-3.12c.41-.83 1.26-1.41 2.25-1.41m-4.16 16.7l-2.5-4.33c-.49-.85-.42-1.87.09-2.64l1-1.73l-1.73-1l5.14.08l2.65 4.42l-1.73-1L6.56 16H11v5H7.4a2.51 2.51 0 0 1-2.35-1.24"/>',
			),
			'mdi:camera'                   => array(
				'label'    => __( 'Camera', 'packout-core' ),
				'group'    => 'facilities',
				'keywords' => 'camera',
				'body'     => '<path fill="currentColor" d="M4 4h3l2-2h6l2 2h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2m8 3a5 5 0 0 0-5 5a5 5 0 0 0 5 5a5 5 0 0 0 5-5a5 5 0 0 0-5-5m0 2a3 3 0 0 1 3 3a3 3 0 0 1-3 3a3 3 0 0 1-3-3a3 3 0 0 1 3-3"/>',
			),
			'mdi:wheelchair-accessibility' => array(
				'label'    => __( 'Accessible', 'packout-core' ),
				'group'    => 'facilities',
				'keywords' => 'wheelchair accessibility',
				'body'     => '<path fill="currentColor" d="m18.4 11.2l-4.1.2l2.3-2.6c.2-.3.3-.8.2-1.3c-.1-.3-.2-.6-.5-.8l-5.4-3.2c-.4-.3-1-.2-1.4.1L6.8 6.1c-.5.5-.6 1.2-.1 1.7c.4.5 1.2.5 1.7.1l2-1.8l1.9 1.1l-4.2 4.3c-.1.1-.1.2-.2.2c-.5.2-1 .4-1.4.7L8 13.9c.5-.2 1-.4 1.5-.4c1.9 0 3.5 1.6 3.5 3.5c0 .6-.1 1.1-.4 1.5l1.5 1.5q.9-1.35.9-3c0-1.2-.4-2.4-1.1-3.3l3.3-.3l-.2 4.8c-.1.7.4 1.2 1.1 1.3h.1c.6 0 1.1-.5 1.2-1.1l.2-5.9c0-.3-.1-.7-.3-.9c-.3-.3-.6-.4-.9-.4M18 5.5a2 2 0 0 0 2-2a2 2 0 0 0-2-2a2 2 0 0 0-2 2a2 2 0 0 0 2 2m-5.5 16.1q-1.35.9-3 .9C6.5 22.5 4 20 4 17q0-1.65.9-3l1.5 1.5c-.2.5-.4 1-.4 1.5c0 1.9 1.6 3.5 3.5 3.5c.6 0 1.1-.1 1.5-.4z"/>',
			),
			'mdi:alert'                    => array(
				'label'    => __( 'Alert', 'packout-core' ),
				'group'    => 'caution',
				'keywords' => 'alert',
				'body'     => '<path fill="currentColor" d="M13 14h-2V9h2m0 9h-2v-2h2M1 21h22L12 2z"/>',
			),
			'mdi:alert-octagon'            => array(
				'label'    => __( 'Hazard', 'packout-core' ),
				'group'    => 'caution',
				'keywords' => 'alert octagon',
				'body'     => '<path fill="currentColor" d="M13 13h-2V7h2m-2 8h2v2h-2m4.73-14H8.27L3 8.27v7.46L8.27 21h7.46L21 15.73V8.27z"/>',
			),
			'mdi:fire'                     => array(
				'label'    => __( 'Fire', 'packout-core' ),
				'group'    => 'caution',
				'keywords' => 'fire',
				'body'     => '<path fill="currentColor" d="M17.66 11.2c-.23-.3-.51-.56-.77-.82c-.67-.6-1.43-1.03-2.07-1.66C13.33 7.26 13 4.85 13.95 3c-.95.23-1.78.75-2.49 1.32c-2.59 2.08-3.61 5.75-2.39 8.9c.04.1.08.2.08.33c0 .22-.15.42-.35.5c-.23.1-.47.04-.66-.12a.6.6 0 0 1-.14-.17c-1.13-1.43-1.31-3.48-.55-5.12C5.78 10 4.87 12.3 5 14.47c.06.5.12 1 .29 1.5c.14.6.41 1.2.71 1.73c1.08 1.73 2.95 2.97 4.96 3.22c2.14.27 4.43-.12 6.07-1.6c1.83-1.66 2.47-4.32 1.53-6.6l-.13-.26c-.21-.46-.77-1.26-.77-1.26m-3.16 6.3c-.28.24-.74.5-1.1.6c-1.12.4-2.24-.16-2.9-.82c1.19-.28 1.9-1.16 2.11-2.05c.17-.8-.15-1.46-.28-2.23c-.12-.74-.1-1.37.17-2.06c.19.38.39.76.63 1.06c.77 1 1.98 1.44 2.24 2.8c.04.14.06.28.06.43c.03.82-.33 1.72-.93 2.27"/>',
			),
			'mdi:fire-alert'               => array(
				'label'    => __( 'Fire alert', 'packout-core' ),
				'group'    => 'caution',
				'keywords' => 'fire alert',
				'body'     => '<path fill="currentColor" d="M15.66 11.2c-.23-.3-.51-.56-.77-.82c-.67-.6-1.43-1.03-2.07-1.66C11.33 7.26 11 4.85 11.95 3c-.95.23-1.78.75-2.49 1.32c-2.59 2.08-3.61 5.75-2.39 8.9c.04.1.08.2.08.33c0 .22-.15.42-.35.5c-.23.1-.47.04-.66-.12a.6.6 0 0 1-.14-.17c-1.13-1.43-1.31-3.48-.55-5.12C3.78 10 2.87 12.3 3 14.47c.06.5.12 1 .29 1.5c.14.6.41 1.2.71 1.73c1.08 1.73 2.95 2.97 4.96 3.22c2.14.27 4.43-.12 6.07-1.6c1.83-1.66 2.47-4.32 1.53-6.6l-.13-.26c-.21-.46-.77-1.26-.77-1.26m-3.16 6.3c-.28.24-.74.5-1.1.6c-1.12.4-2.24-.16-2.9-.82c1.19-.28 1.9-1.16 2.11-2.05c.17-.8-.15-1.46-.28-2.23c-.12-.74-.1-1.37.17-2.06c.19.38.39.76.63 1.06c.77 1 1.98 1.44 2.24 2.8c.04.14.06.28.06.43c.03.82-.33 1.72-.93 2.27M21 13h-2V7h2zm0 4h-2v-2h2z"/>',
			),
			'mdi:hazard-lights'            => array(
				'label'    => __( 'Hazard lights', 'packout-core' ),
				'group'    => 'caution',
				'keywords' => 'hazard lights',
				'body'     => '<path fill="currentColor" d="m12 12l2.33 4H9.68zm0-4L6.21 18H17.8zm0-6L1 21h22zm0 4l7.53 13H4.47z"/>',
			),
			'mdi:landslide'                => array(
				'label'    => __( 'Landslide', 'packout-core' ),
				'group'    => 'caution',
				'keywords' => 'landslide',
				'body'     => '<path fill="currentColor" d="m15.47 13.79l-2.58-1.03L6 15.05L2 13.5v2.11l4 1.34zm-4.9-2.37L8 8H2v3.61l4 1.34zM6 19.05l-4-1.33V22h20l-4.97-6.62zM17 6V1l-5-1l-3 2v4l3 2zm1.5 1L16 9v3l2.5 2l4.5-2V8z"/>',
			),
			'mdi:sign-caution'             => array(
				'label'    => __( 'Caution sign', 'packout-core' ),
				'group'    => 'caution',
				'keywords' => 'sign caution',
				'body'     => '<path fill="currentColor" d="M2 3h20v10h-4v8h-2v-8H8v8H6v-8H2zm16.97 8L20 9.97V7.15L16.15 11zm-5.65 0l6-6H16.5l-6 6zm-5.66 0l6-6h-2.83l-6 6zM5.18 5L4 6.18V9l4-4z"/>',
			),
			'mdi:shield-alert'             => array(
				'label'    => __( 'Warning', 'packout-core' ),
				'group'    => 'caution',
				'keywords' => 'shield alert',
				'body'     => '<path fill="currentColor" d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12c5.16-1.26 9-6.45 9-12V5M11 7h2v6h-2m0 2h2v2h-2"/>',
			),
			'mdi:pickaxe'                  => array(
				'label'    => __( 'Pickaxe', 'packout-core' ),
				'group'    => 'caution',
				'keywords' => 'pickaxe',
				'body'     => '<path fill="currentColor" d="M14.79 10.62L3.5 21.9l-1.4-1.4L13.38 9.21zm4.48-2.89l.59-.59l-.79-.79l.64-.64l-1.42-1.42l-.64.64l-.79-.79l-.59.59c-1.74-1.42-3.7-2.56-5.8-3.36l-.83 1.79c1.75.92 3.36 2.03 4.86 3.34L14 7l3 3l.5-.5c1.31 1.5 2.42 3.11 3.34 4.86l1.79-.83c-.8-2.1-1.94-4.06-3.36-5.8"/>',
			),
			'mdi:axe'                      => array(
				'label'    => __( 'Axe', 'packout-core' ),
				'group'    => 'caution',
				'keywords' => 'axe',
				'body'     => '<path fill="currentColor" d="m12 2l10 6c0 4-2 6-6 7l-3-5l-4-4zM4.11 19.84l-1.99-1.51L9.19 9L11 10.81z"/>',
			),
			'mdi:shovel'                   => array(
				'label'    => __( 'Shovel', 'packout-core' ),
				'group'    => 'caution',
				'keywords' => 'shovel',
				'body'     => '<path fill="currentColor" d="m15.1 1.81l-2.83 2.83c-.77.78-.77 2.05 0 2.83l1.41 1.41l-4.55 4.55l-2.82-2.83L4.89 12c-4.95 5-1.39 8.5-1.39 8.5S7 24 12 19.09l1.41-1.41l-2.8-2.8l4.54-4.54l1.39 1.39c.78.77 2.05.77 2.83 0L22.2 8.9zm2.83 8.47L16.55 8.9l-1.44-1.44l-1.4-1.4l1.41-1.41l4.23 4.23z"/>',
			),
		);
		return $catalog;
	}
}
