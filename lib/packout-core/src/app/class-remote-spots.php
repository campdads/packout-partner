<?php
/**
 * Spots that partners' sites sent, as packout.spot shows them.
 *
 * A partner's Spot is a record of a type of its own here, `pos_partner_spot`,
 * and not a `pos_spot`. Every count, queue, mission, claim, export, and
 * overview on the hub reads `pos_spot`, so a partner's Spot is in none of
 * them without any of them having to leave it out. It is shown where this
 * class puts it: on the map, on a page of its own that sends people to the
 * partner's site, and on the page of a Place it lies inside.
 *
 * The record holds what the partner's site sent and no more. A Spot still
 * waiting for review there holds a category, a day, a hazard value, and a
 * location rounded to about a kilometre; its title is written here.
 *
 * Whether it is shown is its post status: `publish` on the map, `private`
 * off it. The hub's plugin sets that from the partner's state and from what
 * a coordinator here did.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

defined( 'ABSPATH' ) || exit;

/**
 * Partner Spots on the hub: what a visitor sees.
 */
final class Remote_Spots {

	/**
	 * `awaiting` or `published`: how far the partner's review has come.
	 */
	public const STATE = '_pos_remote_state';

	/**
	 * `hidden` or `returned`, when a coordinator here took it off the map.
	 */
	public const STANDING = '_pos_remote_standing';

	/**
	 * Why.
	 */
	public const REASON = '_pos_remote_reason';

	/**
	 * `1` while it waits for a coordinator here, because the partner's Spots are held.
	 */
	public const HELD = '_pos_remote_held';

	/**
	 * `1` when the hub shows its own coarse point, whatever the partner sent.
	 */
	public const COARSE = '_pos_remote_coarse';

	/**
	 * The hub Spot a coordinator linked it to as the same thing.
	 */
	public const LINKED = '_pos_remote_linked';

	/**
	 * The photographs it should have: a JSON list of `{id, of}` in the partner's order.
	 */
	public const PHOTOS = '_pos_remote_photos';

	/**
	 * On an attachment: the photograph's ID on the partner's site.
	 */
	public const PHOTO_ID = '_pos_remote_photo';

	/**
	 * How many partner Spots one answer to the map carries.
	 */
	public const ANSWER = 1000;

	/**
	 * Whether the last answer of visible() left some out.
	 *
	 * @var bool
	 */
	private static bool $more = false;

	/**
	 * The partner Spots linked to Spots of the hub, by the hub's Spot. Null until asked.
	 *
	 * @var array<int, array<int, int>>|null
	 */
	private static ?array $links = null;

	/**
	 * Register the type and its page. The hub only.
	 */
	public static function register(): void {
		if ( ! App::is_hub() ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'type' ), 9 );
		add_filter( 'the_content', array( __CLASS__, 'content' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_page_before_review' ), 5 );
	}

	/**
	 * The record type.
	 */
	public static function type(): void {
		register_post_type(
			Schema::PARTNER_SPOT,
			array(
				'labels'              => array(
					'name'          => __( 'Partner Spots', 'packout-core' ),
					'singular_name' => __( 'Partner Spot', 'packout-core' ),
				),
				'public'              => true,
				'show_ui'             => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => array(
					'slug'       => 'partner-spot',
					'with_front' => false,
				),
				'supports'            => array( 'title', 'editor' ),
				'capability_type'     => array( 'pos_partner_spot', 'pos_partner_spots' ),
				'map_meta_cap'        => true,
				// Nobody makes or changes one by hand. The partner interface writes them.
				'capabilities'        => array(
					'create_posts'           => 'do_not_allow',
					'edit_posts'             => Capabilities::MODERATE,
					'read_private_posts'     => Capabilities::MODERATE,
					'edit_others_posts'      => 'do_not_allow',
					'edit_private_posts'     => 'do_not_allow',
					'edit_published_posts'   => 'do_not_allow',
					'publish_posts'          => 'do_not_allow',
					'delete_posts'           => 'do_not_allow',
					'delete_others_posts'    => 'do_not_allow',
					'delete_private_posts'   => 'do_not_allow',
					'delete_published_posts' => 'do_not_allow',
				),
			)
		);
	}

	/**
	 * A partner Spot, or null.
	 *
	 * @param int $id Post ID.
	 */
	public static function get( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && Schema::PARTNER_SPOT === $post->post_type ? $post : null;
	}

	/**
	 * Whether the partner has reviewed and published it.
	 *
	 * @param int $id The partner Spot.
	 */
	public static function published( int $id ): bool {
		return 'published' === get_post_meta( $id, self::STATE, true );
	}

	/**
	 * Where it is shown: the point the partner sent, or the hub's own coarse point.
	 *
	 * @param int $id The partner Spot.
	 * @return array{0: float, 1: float, 2: string}|null Latitude, longitude, and `exact` or `coarse`.
	 */
	public static function point( int $id ): ?array {
		$lat = get_post_meta( $id, '_pos_lat', true );
		$lng = get_post_meta( $id, '_pos_lng', true );
		if ( '' === $lat || '' === $lng ) {
			return null;
		}
		$precision = 'coarse' === get_post_meta( $id, '_pos_precision', true ) ? 'coarse' : 'exact';
		if ( ! self::published( $id ) ) {
			// Rounded on the partner's site before it was sent.
			return array( (float) $lat, (float) $lng, 'coarse' );
		}
		if ( '1' === get_post_meta( $id, self::COARSE, true ) && 'exact' === $precision ) {
			$coarse = Geo::coarse( (float) $lat, (float) $lng );
			return array( $coarse[0], $coarse[1], 'coarse' );
		}
		return array( (float) $lat, (float) $lng, $precision );
	}

	/**
	 * The category: the hub's own name for it where the hub has that category, and the partner's otherwise.
	 *
	 * @param int $id The partner Spot.
	 * @return array{slug: string, name: string}
	 */
	public static function category( int $id ): array {
		$slug = (string) get_post_meta( $id, '_pos_category', true );
		$term = '' !== $slug ? get_term_by( 'slug', $slug, Schema::CATEGORY ) : null;
		$name = $term instanceof \WP_Term ? $term->name : (string) get_post_meta( $id, '_pos_category_name', true );
		return array(
			'slug' => $slug,
			'name' => '' !== $name ? $name : __( 'Spot', 'packout-core' ),
		);
	}

	/**
	 * The photographs, in the partner's order, as a Spot's gallery is given.
	 *
	 * A Spot shown at a coarse point here shows none, as a coarse Spot of the hub shows none.
	 *
	 * @param int $id The partner Spot.
	 * @return array<int, array<string, mixed>>
	 */
	public static function gallery( int $id ): array {
		$point = self::point( $id );
		if ( ! self::published( $id ) || ( $point && 'coarse' === $point[2] ) ) {
			return array();
		}
		$wanted = json_decode( (string) get_post_meta( $id, self::PHOTOS, true ), true );
		if ( ! is_array( $wanted ) || ! $wanted ) {
			return array();
		}
		$held = array();
		foreach ( get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => $id,
				'numberposts' => 60,
			)
		) as $attachment ) {
			$held[ (string) get_post_meta( $attachment->ID, self::PHOTO_ID, true ) ] = $attachment->ID;
		}
		$captions = array(
			'report'  => '',
			'cleanup' => __( 'After the cleanup', 'packout-core' ),
			'visit'   => __( 'Return visit', 'packout-core' ),
		);
		$photos   = array();
		foreach ( $wanted as $photo ) {
			$attachment = $held[ (string) ( $photo['id'] ?? '' ) ] ?? 0;
			if ( $attachment <= 0 ) {
				continue;
			}
			$kind  = isset( $captions[ (string) ( $photo['of'] ?? '' ) ] ) ? (string) $photo['of'] : 'report';
			$thumb = wp_get_attachment_image_src( $attachment, 'medium' );
			$large = wp_get_attachment_image_src( $attachment, 'large' );
			$full  = (string) wp_get_attachment_url( $attachment );
			if ( '' === $full ) {
				continue;
			}
			$photos[] = array(
				'id'      => $attachment,
				'url'     => $thumb ? (string) $thumb[0] : $full,
				'large'   => $large ? (string) $large[0] : $full,
				'full'    => $full,
				'width'   => $large ? (int) $large[1] : 0,
				'height'  => $large ? (int) $large[2] : 0,
				'kind'    => $kind,
				'caption' => $captions[ $kind ],
				'alt'     => '',
			);
		}//end foreach
		$count = count( $photos );
		foreach ( $photos as $index => $photo ) {
			$here = number_format_i18n( $index + 1 );
			$all  = number_format_i18n( $count );
			if ( 'cleanup' === $photo['kind'] ) {
				/* translators: 1: number of this photo, 2: number of photos. */
				$photos[ $index ]['alt'] = sprintf( __( 'Photo %1$s of %2$s, taken after the cleanup', 'packout-core' ), $here, $all );
			} elseif ( 'visit' === $photo['kind'] ) {
				/* translators: 1: number of this photo, 2: number of photos. */
				$photos[ $index ]['alt'] = sprintf( __( 'Photo %1$s of %2$s, taken on a return visit', 'packout-core' ), $here, $all );
			} else {
				/* translators: 1: number of this photo, 2: number of photos. */
				$photos[ $index ]['alt'] = sprintf( __( 'Photo %1$s of %2$s from the report', 'packout-core' ), $here, $all );
			}
		}
		return $photos;
	}

	/**
	 * A partner Spot as the map and the API give it: the fields a Spot of the hub has for the public, and whose it is.
	 *
	 * @param \WP_Post $post The partner Spot.
	 * @return array<string, mixed>
	 */
	public static function payload( \WP_Post $post ): array {
		$id        = $post->ID;
		$published = self::published( $id );
		$point     = self::point( $id );
		$partner   = (int) get_post_meta( $id, Remote::PARTNER, true );
		$owner     = Partners::get( $partner );
		$name      = $owner ? $owner->post_title : __( 'a partner', 'packout-core' );
		$hazard    = (string) get_post_meta( $id, '_pos_hazard', true );
		$hazard    = isset( Schema::hazards()[ $hazard ] ) ? $hazard : 'none';
		$status    = (string) get_post_meta( $id, '_pos_status', true );
		$label     = (string) get_post_meta( $id, '_pos_credit_label', true );

		return array(
			'id'                  => $id,
			'title'               => $post->post_title,
			// A Spot the partner has not reviewed has no page.
			'url'                 => $published && 'publish' === $post->post_status ? (string) get_permalink( $post ) : '',
			'category'            => self::category( $id ),
			'status'              => $published && isset( Schema::statuses()[ $status ] ) ? $status : 'spotted',
			'hazard'              => $hazard,
			'needs_authorization' => $published ? '1' === get_post_meta( $id, '_pos_blocked', true ) : 'none' !== $hazard,
			'authorized'          => $published && '1' === get_post_meta( $id, '_pos_authorized', true ),
			'guidance'            => $published ? (string) get_post_meta( $id, '_pos_guidance', true ) : Schema::hazard_guidance( $hazard ),
			'place'               => null,
			'description'         => $published
				? $post->post_content
				/* translators: %s: the name of a partner. */
				: sprintf( __( 'Reported on the site of %s, whose coordinators are reviewing it. Its title, description, and photos appear here once they publish it.', 'packout-core' ), $name ),
			'location'            => array(
				'kind'      => $published ? (string) get_post_meta( $id, '_pos_location_kind', true ) : ( $point ? 'pin' : 'description' ),
				'lat'       => $point ? $point[0] : null,
				'lng'       => $point ? $point[1] : null,
				'precision' => $point ? $point[2] : 'exact',
				// A landmark names the place. It is not shown beside a point the hub made coarse.
				'landmark'  => $published && $point && 'exact' === $point[2] ? (string) get_post_meta( $id, '_pos_landmark', true ) : '',
			),
			'updated'             => mysql2date( 'c', $post->post_modified_gmt, false ),
			'reported'            => mysql2date( 'Y-m-d', $post->post_date, false ),
			'reported_by'         => $published && '' !== $label ? array(
				'label' => $label,
				'url'   => esc_url_raw( (string) get_post_meta( $id, '_pos_credit_url', true ), array( 'https', 'http' ) ),
			) : array(
				'label' => $name,
				'url'   => $owner && 'publish' === $owner->post_status ? (string) get_permalink( $owner ) : '',
			),
			'gallery'             => array_map( static fn( $photo ) => array_diff_key( $photo, array( 'id' => 0 ) ), self::gallery( $id ) ),
			// Whose it is, and where it is kept.
			'through'             => array(
				'name'     => $name,
				'url'      => $owner && 'publish' === $owner->post_status ? (string) get_permalink( $owner ) : '',
				'site'     => $published ? esc_url_raw( (string) get_post_meta( $id, Remote::URL, true ), array( 'https', 'http' ) ) : '',
				'awaiting' => ! $published,
			),
			'partners'            => $partner > 0 ? array( $partner ) : array(),
		);
	}

	/**
	 * The partner Spots a visitor may see, as payloads.
	 *
	 * @param array<string, mixed> $args Optional bbox.
	 * @return array<int, array<string, mixed>>
	 */
	public static function visible( array $args = array() ): array {
		if ( ! App::is_hub() || ! post_type_exists( Schema::PARTNER_SPOT ) ) {
			self::$more = false;
			return array();
		}
		$query = array(
			'post_type'   => Schema::PARTNER_SPOT,
			'post_status' => 'publish',
			'numberposts' => self::ANSWER,
			'orderby'     => 'modified',
			'order'       => 'DESC',
		);
		$bbox  = $args['bbox'] ?? null;
		if ( is_array( $bbox ) ) {
			$edge = Geo::COARSE_GRID;
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The partner Spots in or beside the box, before the exact test on what is shown.
			$query['meta_query'] = array(
				array(
					'key'     => '_pos_lat',
					'value'   => array( $bbox['south'] - $edge, $bbox['north'] + $edge ),
					'compare' => 'BETWEEN',
					'type'    => 'DECIMAL(10,6)',
				),
				array(
					'key'     => '_pos_lng',
					'value'   => array( $bbox['west'] - $edge, $bbox['east'] + $edge ),
					'compare' => 'BETWEEN',
					'type'    => 'DECIMAL(10,6)',
				),
			);
		}
		$posts      = get_posts( $query );
		self::$more = count( $posts ) >= self::ANSWER;
		return array_map( array( __CLASS__, 'payload' ), $posts );
	}

	/**
	 * Whether the last answer of visible() left some out because it was full.
	 */
	public static function more(): bool {
		return self::$more;
	}

	/**
	 * The published partner Spots inside an outline, newest first.
	 *
	 * @param array<int, array{0: float, 1: float}> $ring Longitude and latitude pairs.
	 * @return array<int, array<string, mixed>> Payloads.
	 */
	public static function inside( array $ring ): array {
		if ( count( $ring ) < 3 || ! App::is_hub() ) {
			return array();
		}
		$lngs  = array_column( $ring, 0 );
		$lats  = array_column( $ring, 1 );
		$found = array();
		foreach ( self::visible(
			array(
				'bbox' => array(
					'south' => min( $lats ),
					'north' => max( $lats ),
					'west'  => min( $lngs ),
					'east'  => max( $lngs ),
				),
			)
		) as $spot ) {
			if ( empty( $spot['through']['awaiting'] ) && null !== $spot['location']['lat'] && Places::contains( $ring, (float) $spot['location']['lat'], (float) $spot['location']['lng'] ) ) {
				$found[] = $spot;
			}
		}
		return $found;
	}

	/**
	 * The partner Spots a coordinator linked to a Spot of the hub as the same thing.
	 *
	 * @param int $spot The hub's Spot.
	 * @return array<int, int> Partner Spot IDs.
	 */
	public static function linked_to( int $spot ): array {
		global $wpdb;
		if ( ! App::is_hub() ) {
			return array();
		}
		if ( null === self::$links ) {
			self::$links = array();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Every link at once: there are few, and a map answer asks about a thousand Spots.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = %s AND p.post_status IN ( 'publish', 'private' ) LIMIT 2000", self::LINKED, Schema::PARTNER_SPOT ) );
			foreach ( (array) $rows as $row ) {
				self::$links[ (int) $row->meta_value ][] = (int) $row->post_id;
			}
		}
		return self::$links[ $spot ] ?? array();
	}

	/**
	 * Forget what was read about links, after one changed.
	 */
	public static function flush(): void {
		self::$links = null;
	}

	/**
	 * Who else reported a Spot of the hub: the partners whose Spots a coordinator linked to it.
	 *
	 * @param int $spot The hub's Spot.
	 * @return array<int, array{name: string, url: string, site: string}> The partner, its page here, and the Spot on its site.
	 */
	public static function also( int $spot ): array {
		$also = array();
		foreach ( self::linked_to( $spot ) as $id ) {
			$partner = (int) get_post_meta( $id, Remote::PARTNER, true );
			$owner   = Partners::get( $partner );
			// A partner that is not in good standing is not named.
			if ( ! $owner || 'publish' !== $owner->post_status || ! self::published( $id ) || '' !== (string) get_post_meta( $id, self::STANDING, true ) ) {
				continue;
			}
			$also[] = array(
				'name' => $owner->post_title,
				'url'  => (string) get_permalink( $owner ),
				'site' => esc_url_raw( (string) get_post_meta( $id, Remote::URL, true ), array( 'https', 'http' ) ),
			);
		}
		return $also;
	}

	/**
	 * A Spot the partner has not reviewed has no page, and one that is off the map has none for a visitor.
	 */
	public static function no_page_before_review(): void {
		if ( ! is_singular( Schema::PARTNER_SPOT ) ) {
			return;
		}
		$post = get_queried_object();
		if ( $post instanceof \WP_Post && self::published( $post->ID ) && 'publish' === $post->post_status ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * The page: what the partner's site shows the public, and the way there.
	 *
	 * @param string $content Post content.
	 */
	public static function content( $content ) {
		if ( ! is_singular( Schema::PARTNER_SPOT ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}
		wp_enqueue_style( 'packout-workspace' );
		wp_enqueue_script( 'packout-workspace' );
		wp_enqueue_style( 'packout-blocks' );

		$spot    = self::payload( $post );
		$photos  = self::gallery( $post->ID );
		$labels  = Schema::statuses();
		$through = $spot['through'];
		$date    = mysql2date( get_option( 'date_format' ), $post->post_date );
		$partner = '' !== $through['url'] ? '<a href="' . esc_url( $through['url'] ) . '">' . esc_html( $through['name'] ) . '</a>' : esc_html( $through['name'] );

		$html  = '<div class="pos-spot-page pos-spot-page--partner pos-place-page pos-place pos-event">';
		$html .= Page_Header::render(
			array(
				'cover'       => $photos ? $photos[0]['id'] : 0,
				'kind'        => $spot['category']['name'],
				'title'       => $post->post_title,
				'description' => trim( wp_strip_all_tags( $post->post_content ) ),
				'facts'       => array(
					'<span class="pos-spot-page__stage">' . esc_html( $labels[ $spot['status'] ] ?? $spot['status'] ) . '</span>',
					/* translators: %s: the name of a partner, linked. */
					'<span class="pos-spot-page__through">' . sprintf( esc_html__( 'Reported through %s', 'packout-core' ), $partner ) . '</span>',
					/* translators: 1: date, 2: who reported the Spot. */
					'<span class="pos-spot-page__reported">' . sprintf( esc_html__( 'Reported %1$s by %2$s', 'packout-core' ), esc_html( $date ), Credit::html( $spot['reported_by'] ) ) . '</span>',
				),
			)
		);
		$html .= '<div class="pos-place__body"><section class="pos-place__section pos-spot-page__detail"><div class="pos-spot-page__split"><div class="pos-spot-page__about">';

		if ( '' !== $spot['guidance'] ) {
			$html .= '<p class="pos-spot-page__hazard"><strong>' . esc_html( $spot['authorized'] ? __( 'Work is authorized', 'packout-core' ) : Schema::hazards()[ $spot['hazard'] ] ) . '.</strong> ' . esc_html( $spot['guidance'] ) . '</p>';
		}
		$location = $spot['location'];
		$facts    = array();
		if ( null !== $location['lat'] ) {
			$facts[ 'coarse' === $location['precision'] ? __( 'Approximate location', 'packout-core' ) : __( 'Location', 'packout-core' ) ] = '<span class="pos-spot-page__location">' . esc_html(
				'coarse' === $location['precision']
					/* translators: 1: latitude, 2: longitude. */
					? sprintf( __( 'Near %1$s, %2$s.', 'packout-core' ), $location['lat'], $location['lng'] )
					: $location['lat'] . ', ' . $location['lng']
			) . '</span>';
		}
		if ( '' !== $location['landmark'] ) {
			$facts[ __( 'Landmark', 'packout-core' ) ] = '<span class="pos-spot-page__landmark">' . esc_html( $location['landmark'] ) . '</span>';
		}
		if ( $facts ) {
			$html .= '<dl class="pos-event__facts pos-spot-page__facts">';
			foreach ( $facts as $label => $value ) {
				$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd>';
			}
			$html .= '</dl>';
		}

		// The record is kept on the partner's site. That is where a person says they cleaned it, or adds to it.
		$html .= '<div class="pos-remote pos-spot-page__kept">';
		/* translators: %s: the name of a partner. */
		$html .= '<p>' . esc_html( sprintf( __( 'This Spot is kept on the site of %s. To say you cleaned it, share an update, or join the crew working on it, go there.', 'packout-core' ), $through['name'] ) ) . '</p>';
		if ( '' !== $through['site'] ) {
			/* translators: %s: the name of a partner. */
			$html .= '<p class="pos-spot-page__actions pos-ws"><a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( $through['site'] ) . '" rel="noopener">' . esc_html( sprintf( __( 'See this Spot on the site of %s', 'packout-core' ), $through['name'] ) ) . '</a></p>';
		}
		$html .= '</div></div><div class="pos-spot-page__photos">';
		if ( $photos ) {
			wp_enqueue_script( 'packout-slider' );
			$html .= Spot_Page::slider( $photos, $post->post_title );
		} else {
			$html .= '<div class="pos-spot-page__no-photos"><p>' . esc_html__( 'No photos of this Spot are shown.', 'packout-core' ) . '</p></div>';
		}
		return $html . '</div></div></section></div></div>';
	}
}
