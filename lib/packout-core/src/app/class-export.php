<?php
/**
 * Export of Spots and impact records.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Files a coordinator hands to a partner: Spots as GeoJSON and impact
 * records as CSV, for a date range and an optional Place.
 *
 * The public file of Spots holds what the anonymous map holds. The internal
 * file holds exact pins. No file names a reporter or a crew member.
 */
class Export {

	/**
	 * Whether a user may download the files.
	 *
	 * @param int $user_id User ID. Negative uses the current user.
	 */
	public static function can( int $user_id = -1 ): bool {
		return Capabilities::can( Capabilities::EXPORT, $user_id );
	}

	/**
	 * The files and their names.
	 *
	 * @return array<string, string>
	 */
	public static function kinds(): array {
		return array(
			'spots_public'   => __( 'Spots, public (GeoJSON)', 'packout-core' ),
			'spots_internal' => __( 'Spots, internal with exact pins (GeoJSON)', 'packout-core' ),
			'impact'         => __( 'Impact records (CSV)', 'packout-core' ),
			'people'         => __( 'People: news list and mailing addresses (CSV)', 'packout-core' ),
		);
	}

	/**
	 * A clean date range and Place from what was asked.
	 *
	 * @param array<string, mixed> $args from, to (Y-m-d), place.
	 * @return array{from: string, to: string, place: int}
	 */
	public static function range( array $args ): array {
		$from = Schema::day( $args['from'] ?? '' );
		$to   = Schema::day( $args['to'] ?? '' );
		if ( '' !== $from && '' !== $to && $to < $from ) {
			list( $from, $to ) = array( $to, $from );
		}
		$place = Places::get( (int) ( $args['place'] ?? 0 ) );
		return array(
			'from'  => $from,
			'to'    => $to,
			'place' => $place ? $place->ID : 0,
		);
	}

	/**
	 * Spots as a GeoJSON feature collection.
	 *
	 * The date range applies to the day a Spot was reported. A Spot with no
	 * pin is a feature with no geometry.
	 *
	 * @param array<string, mixed> $args     from, to, place.
	 * @param bool                 $internal Exact pins and the fields a coordinator sees, or the public map's view.
	 * @return array<string, mixed>
	 */
	public static function spots( array $args, bool $internal ): array {
		$range = self::range( $args );
		$query = array(
			'post_type'   => Schema::SPOT,
			'post_status' => $internal ? Spots::POST_STATUSES : array( 'publish' ),
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		);
		$dates = array_filter(
			array(
				'after'  => '' !== $range['from'] ? $range['from'] . ' 00:00:00' : '',
				'before' => '' !== $range['to'] ? $range['to'] . ' 23:59:59' : '',
			)
		);
		if ( $dates ) {
			$query['date_query'] = array( $dates + array( 'inclusive' => true ) );
		}
		if ( $range['place'] > 0 ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The Spots of one Place.
			$query['meta_key'] = '_pos_place';
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The Spots of one Place.
			$query['meta_value'] = (string) $range['place'];
		}

		$features = array();
		foreach ( get_posts( $query ) as $post ) {
			if ( ! $internal && 'public' !== Spots::view_for( $post, 0 ) ) {
				continue;
			}
			$spot     = Spots::payload( $post, $internal ? 'moderator' : 'public' );
			$location = $spot['location'];
			$fields   = array(
				'id'            => $spot['id'],
				'title'         => $spot['title'],
				'url'           => $spot['url'],
				'category'      => $spot['category']['slug'],
				'category_name' => $spot['category']['name'],
				'status'        => $spot['status'],
				'hazard'        => $spot['hazard'],
				'authorized'    => $spot['authorized'],
				'precision'     => $location['precision'],
				'landmark'      => $location['landmark'],
				'description'   => $spot['description'],
				'place'         => $spot['place'] ? $spot['place']['name'] : '',
				'reported'      => mysql2date( 'Y-m-d', $post->post_date, false ),
				'updated'       => $spot['updated'],
			);
			if ( $internal ) {
				$clearance = $spot['clearance'];
				$fields   += array(
					'review'              => $spot['post_status'],
					'location_kind'       => $location['kind'],
					'accuracy_m'          => $location['accuracy'],
					'area_note'           => $location['area'],
					'clearance_reference' => $clearance ? $clearance['reference'] : '',
					'clearance_scope'     => $clearance ? $clearance['scope'] : '',
					'clearance_on'        => $clearance ? $clearance['on'] : '',
					'cleaned_at'          => Spots::field( $post->ID, 'cleaned_at' ),
					'duplicate_of'        => $spot['duplicate_of'],
					'photos'              => wp_list_pluck( $spot['photos'], 'full' ),
				);
			}
			$features[] = array(
				'type'       => 'Feature',
				'id'         => $spot['id'],
				'geometry'   => null !== $location['lat'] ? array(
					'type'        => 'Point',
					'coordinates' => array( $location['lng'], $location['lat'] ),
				) : null,
				'properties' => $fields,
			);
		}//end foreach

		return array(
			'type'      => 'FeatureCollection',
			'name'      => $internal ? 'Pack-Out Spot: Spots, internal' : 'Pack-Out Spot: Spots, public',
			'source'    => home_url( '/' ),
			'generated' => gmdate( 'c' ),
			'precision' => $internal ? 'exact' : 'public',
			'range'     => $range,
			'features'  => $features,
		);
	}

	/**
	 * Impact records as rows, the first being the column names.
	 *
	 * The date range applies to the date of the record.
	 *
	 * @param array<string, mixed> $args from, to, place.
	 * @return array<int, array<int, string>>
	 */
	public static function impact( array $args ): array {
		$range   = self::range( $args );
		$filters = array_filter(
			array(
				'from' => $range['from'],
				'to'   => $range['to'],
			)
		);
		if ( $range['place'] > 0 ) {
			$filters['spots'] = wp_list_pluck( Places::spots( $range['place'] ), 'ID' );
		}

		$rows = array(
			array( 'record', 'kind', 'date', 'spot_id', 'spot', 'place', 'organization', 'mission_id', 'hours', 'people', 'bags', 'bags_source', 'pounds', 'pounds_source', 'verification', 'verified_at', 'counted_publicly' ),
		);
		foreach ( Impact::found( $filters ) as $post ) {
			$record = Impact::payload( $post );
			$place  = $record['spot'] > 0 ? Places::of_spot( $record['spot'] ) : null;
			$rows[] = array_map(
				'strval',
				array(
					$record['id'],
					$record['kind'],
					$record['date'],
					$record['spot'],
					$record['spot_title'],
					$place ? $place->post_title : '',
					$record['team_name'],
					$record['mission'],
					$record['hours'],
					$record['people'],
					$record['bags'],
					$record['bags_source'],
					$record['pounds'],
					$record['pounds_source'],
					$record['verification'],
					$record['verified_at'],
					$record['visible'] ? 'yes' : 'no',
				)
			);
		}//end foreach
		return $rows;
	}

	/**
	 * Rows as CSV text.
	 *
	 * A cell a spreadsheet would run as a formula is written as text.
	 *
	 * @param array<int, array<int, string>> $rows Rows.
	 */
	public static function csv( array $rows ): string {
		$lines = array();
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $row as $cell ) {
				if ( '' !== $cell && ! is_numeric( $cell ) && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
					$cell = "'" . $cell;
				}
				$cells[] = '"' . str_replace( '"', '""', $cell ) . '"';
			}
			$lines[] = implode( ',', $cells );
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * One file, ready to send.
	 *
	 * @param string               $kind    spots_public, spots_internal, impact, or people.
	 * @param array<string, mixed> $args    from, to, place.
	 * @param int                  $user_id The person asking. Negative uses the current user.
	 * @return array{name: string, type: string, body: string}|\WP_Error
	 */
	public static function file( string $kind, array $args, int $user_id = -1 ) {
		if ( ! self::can( $user_id ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'You are not allowed to export records.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! isset( self::kinds()[ $kind ] ) ) {
			return new \WP_Error( 'packout_export_kind', __( 'Choose which file to download.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$range = self::range( $args );
		$place = $range['place'] > 0 ? Places::get( $range['place'] ) : null;
		$stem  = implode( '-', array_filter( array( 'packout', str_replace( '_', '-', $kind ), $place ? $place->post_name : '', $range['from'], $range['to'], wp_date( 'Ymd' ) ) ) );

		if ( 'people' === $kind ) {
			// People are not filtered by date or Place.
			return array(
				'name' => 'packout-people-' . wp_date( 'Ymd' ) . '.csv',
				'type' => 'text/csv; charset=utf-8',
				'body' => self::csv( Profile::mailing_rows() ),
			);
		}
		if ( 'impact' === $kind ) {
			return array(
				'name' => $stem . '.csv',
				'type' => 'text/csv; charset=utf-8',
				'body' => self::csv( self::impact( $range ) ),
			);
		}
		return array(
			'name' => $stem . '.geojson',
			'type' => 'application/geo+json; charset=utf-8',
			'body' => (string) wp_json_encode( self::spots( $range, 'spots_internal' === $kind ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);
	}
}
