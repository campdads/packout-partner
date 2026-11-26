<?php
/**
 * Sponsor credits.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A sponsor is a credit: a name, a link, and an optional logo.
 *
 * A team lists its sponsors, and a campaign or a mission can name any of
 * them. A Place lists its own, which a coordinator keeps. The credit grants
 * nothing in the product, and no payment is recorded.
 */
class Sponsors {

	private const LIMIT = 40;

	/**
	 * Whether a person keeps the sponsors of a team or a Place.
	 *
	 * @param int $owner_id Team ID or Place ID.
	 * @param int $user_id  User ID.
	 */
	private static function allowed( int $owner_id, int $user_id ): bool {
		if ( Places::get( $owner_id ) ) {
			return Places::can_manage( $user_id );
		}
		if ( Features::get( $owner_id ) ) {
			return Features::can_edit( $owner_id, $user_id );
		}
		if ( Partners::get( $owner_id ) ) {
			return Partners::can_run( $owner_id, $user_id ) && Partners::allows( $owner_id, 'profile' );
		}
		return (bool) Teams::get( $owner_id ) && Teams::can_run( $owner_id, $user_id );
	}

	/**
	 * What a person who may not change the sponsors is told.
	 *
	 * @param int $owner_id Team ID or Place ID.
	 */
	private static function refusal( int $owner_id ): \WP_Error {
		if ( Places::get( $owner_id ) ) {
			$message = __( 'A coordinator lists the sponsors of a Place.', 'packout-core' );
		} elseif ( Features::get( $owner_id ) ) {
			$message = __( 'You can list sponsors on a map feature you created.', 'packout-core' );
		} elseif ( Partners::get( $owner_id ) ) {
			$message = __( 'The partner\'s leads list its sponsors.', 'packout-core' );
		} else {
			$message = __( 'The organization\'s lead lists its sponsors.', 'packout-core' );
		}
		return new \WP_Error( 'packout_forbidden', $message, array( 'status' => 403 ) );
	}

	/**
	 * The sponsors of a team or a Place, by name.
	 *
	 * @param int $team_id Team ID or Place ID.
	 * @return array<int, array{id: int, name: string, url: string, logo: int}>
	 */
	public static function all( int $team_id ): array {
		$list = array();
		foreach ( Schema::stored_list( $team_id, 'sponsors' ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				$list[] = array(
					'id'   => (int) $row['id'],
					'name' => (string) ( $row['name'] ?? '' ),
					'url'  => (string) ( $row['url'] ?? '' ),
					'logo' => (int) ( $row['logo'] ?? 0 ),
				);
			}
		}
		usort( $list, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
		return $list;
	}

	/**
	 * The sponsors of a team that a campaign or a mission names.
	 *
	 * @param int               $team_id Team ID.
	 * @param array<int, mixed> $ids     Sponsor numbers.
	 * @return array<int, array{id: int, name: string, url: string, logo: int}>
	 */
	public static function named( int $team_id, array $ids ): array {
		$ids = array_map( 'intval', $ids );
		return array_values( array_filter( self::all( $team_id ), static fn( $sponsor ) => in_array( $sponsor['id'], $ids, true ) ) );
	}

	/**
	 * Add or change a sponsor.
	 *
	 * @param int                       $team_id Team ID or Place ID.
	 * @param int                       $id      Sponsor number, or 0 for a new one.
	 * @param array<string, mixed>      $input   name, url.
	 * @param int                       $user_id The person saving.
	 * @param array<string, mixed>|null $file    A logo from the request files.
	 * @return int|\WP_Error Sponsor number.
	 */
	public static function save( int $team_id, int $id, array $input, int $user_id, ?array $file = null ) {
		if ( ! self::allowed( $team_id, $user_id ) ) {
			return self::refusal( $team_id );
		}
		$list  = self::all( $team_id );
		$index = null;
		foreach ( $list as $position => $sponsor ) {
			if ( $sponsor['id'] === $id ) {
				$index = $position;
			}
		}
		if ( $id > 0 && null === $index ) {
			return new \WP_Error( 'packout_not_found', __( 'That sponsor is not on the list.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( null === $index && count( $list ) >= self::LIMIT ) {
			return new \WP_Error( 'packout_sponsor_limit', __( 'This list has as many sponsors as it can hold.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$name = sanitize_text_field( (string) ( $input['name'] ?? ( null !== $index ? $list[ $index ]['name'] : '' ) ) );
		if ( '' === $name || mb_strlen( $name ) > 80 ) {
			return new \WP_Error( 'packout_sponsor_name', __( 'Give the sponsor a name of up to 80 characters.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$url  = array_key_exists( 'url', $input ) ? esc_url_raw( trim( (string) $input['url'] ), array( 'http', 'https' ) ) : ( null !== $index ? $list[ $index ]['url'] : '' );
		$logo = null !== $index ? $list[ $index ]['logo'] : 0;

		if ( $file && ! empty( $file['tmp_name'] ) ) {
			// Files come from teams a coordinator has approved, and from coordinators.
			if ( Partners::get( $team_id ) && ! Partners::allows( $team_id, 'images' ) ) {
				return new \WP_Error( 'packout_partner_state', __( 'A partner can add a logo once a coordinator has approved it. The name is shown until then.', 'packout-core' ), array( 'status' => 409 ) );
			}
			if ( ! Places::get( $team_id ) && ! Partners::get( $team_id ) && ! Teams::active( $team_id ) ) {
				return new \WP_Error( 'packout_team_pending', __( 'An organization can add a logo once a coordinator has approved it. The name is shown until then.', 'packout-core' ), array( 'status' => 409 ) );
			}
			$stored = Photos::store( $team_id, $file );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
			if ( $logo > 0 ) {
				wp_delete_attachment( $logo, true );
			}
			$logo = $stored;
		}

		$row = array(
			'id'   => null !== $index ? $id : Schema::next_number( $team_id ),
			'name' => $name,
			'url'  => $url,
			'logo' => $logo,
		);
		if ( null === $index ) {
			$list[] = $row;
		} else {
			$list[ $index ] = $row;
		}
		Schema::write_list( $team_id, 'sponsors', $list );
		return $row['id'];
	}

	/**
	 * Remove a sponsor and its logo.
	 *
	 * @param int $team_id Team ID or Place ID.
	 * @param int $id      Sponsor number.
	 * @param int $user_id The person removing it.
	 * @return true|\WP_Error
	 */
	public static function remove( int $team_id, int $id, int $user_id ) {
		if ( ! self::allowed( $team_id, $user_id ) ) {
			return self::refusal( $team_id );
		}
		$keep = array();
		foreach ( self::all( $team_id ) as $sponsor ) {
			if ( $sponsor['id'] !== $id ) {
				$keep[] = $sponsor;
			} elseif ( $sponsor['logo'] > 0 ) {
				wp_delete_attachment( $sponsor['logo'], true );
			}
		}
		Schema::write_list( $team_id, 'sponsors', $keep );
		return true;
	}

	/**
	 * Sponsor credits for a public page, or an empty string.
	 *
	 * A sponsor's link is marked as sponsored and passes no ranking.
	 *
	 * @param array<int, array{id: int, name: string, url: string, logo: int}> $sponsors Sponsors.
	 */
	public static function html( array $sponsors ): string {
		if ( ! $sponsors ) {
			return '';
		}
		$html = '<ul class="pos-sponsors">';
		foreach ( $sponsors as $sponsor ) {
			$logo  = $sponsor['logo'] > 0 ? wp_get_attachment_image(
				$sponsor['logo'],
				'medium',
				false,
				array(
					'alt'     => $sponsor['name'],
					'loading' => 'lazy',
				)
			) : '';
			$label = '' !== $logo ? $logo : '<span class="pos-sponsors__name">' . esc_html( $sponsor['name'] ) . '</span>';
			$html .= '<li class="pos-sponsors__item">';
			$html .= '' !== $sponsor['url'] ? '<a href="' . esc_url( $sponsor['url'] ) . '" rel="sponsored nofollow noopener">' . $label . '</a>' : $label;
			$html .= '</li>';
		}
		return $html . '</ul>';
	}
}
