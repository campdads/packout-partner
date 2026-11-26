<?php
/**
 * The header of a public record page.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A Place, a campaign, and a mission open with the same header: a cover image
 * behind the title, the description, a line of facts, and the sponsors.
 */
class Page_Header {

	/**
	 * The cover image of a record, or 0.
	 *
	 * @param int $id Post ID.
	 */
	public static function cover( int $id ): int {
		$image = (int) get_post_meta( $id, '_pos_cover', true );
		return $image > 0 && 'attachment' === get_post_type( $image ) ? $image : 0;
	}

	/**
	 * Store, replace, or remove the cover image of a record. The caller has decided the person may.
	 *
	 * @param int                       $id   Post ID.
	 * @param array<string, mixed>|null $file One entry from the request files, or null to remove the cover.
	 * @return int|\WP_Error Attachment ID, or 0 when the cover was removed.
	 */
	public static function set_cover( int $id, ?array $file ) {
		$stored = 0;
		if ( null !== $file ) {
			$stored = Photos::store( $id, $file, false, 'cover' );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
		}
		$old = self::cover( $id );
		if ( $old > 0 ) {
			wp_delete_attachment( $old, true );
		}
		if ( $stored > 0 ) {
			update_post_meta( $id, '_pos_cover', $stored );
		} else {
			delete_post_meta( $id, '_pos_cover' );
		}
		return $stored;
	}

	/**
	 * The header.
	 *
	 * @param array<string, mixed> $args cover (attachment ID), logo (attachment ID), logo_alt, kind, title,
	 *                                   description (plain text), facts (escaped HTML, one per fact), sponsors.
	 */
	public static function render( array $args ): string {
		$cover_id = (int) ( $args['cover'] ?? 0 );
		$cover    = $cover_id > 0 ? wp_get_attachment_image(
			$cover_id,
			'full',
			false,
			array(
				'class'         => 'pos-place__cover',
				'alt'           => '',
				'sizes'         => '100vw',
				'loading'       => 'eager',
				'fetchpriority' => 'high',
			)
		) : '';

		$html  = '<header class="pos-place__hero pos-hero bg-8' . ( '' !== $cover ? ' pos-place__hero--cover' : '' ) . '">' . $cover;
		$html .= '<div class="pos-place__hero-inner"><div class="pos-place__intro">';
		if ( (int) ( $args['logo'] ?? 0 ) > 0 ) {
			$html .= wp_get_attachment_image(
				(int) $args['logo'],
				'medium',
				false,
				array(
					'class' => 'pos-place__logo',
					'alt'   => (string) ( $args['logo_alt'] ?? '' ),
				)
			);
		}
		if ( '' !== (string) ( $args['kind'] ?? '' ) ) {
			$html .= '<p class="pos-place__kind">' . esc_html( (string) $args['kind'] ) . '</p>';
		}
		$html .= '<h1 class="pos-place__name">' . esc_html( (string) ( $args['title'] ?? '' ) ) . '</h1>';
		if ( '' !== trim( (string) ( $args['description'] ?? '' ) ) ) {
			$html .= '<div class="pos-place__description">' . wpautop( esc_html( (string) $args['description'] ) ) . '</div>';
		}
		$facts = array_filter( (array) ( $args['facts'] ?? array() ) );
		if ( $facts ) {
			$html .= '<p class="pos-place__facts">' . implode( ' <span aria-hidden="true">·</span> ', $facts ) . '</p>';
		}
		$html .= '</div>';
		if ( ! empty( $args['sponsors'] ) ) {
			$html .= '<aside class="pos-place__support" aria-labelledby="pos-place-support"><h2 id="pos-place-support">' . esc_html__( 'Supported by', 'packout-core' ) . '</h2>' . Sponsors::html( (array) $args['sponsors'] ) . '</aside>';
		}
		return $html . '</div></header>';
	}
}
