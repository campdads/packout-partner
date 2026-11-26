<?php
/**
 * A partner site's Spots, as they go to packout.spot.
 *
 * What is sent is decided here, before anything is sent, so nothing depends
 * on the hub discarding what it should not have:
 *
 * - A Spot waiting for review goes as `awaiting`: its category, the day it
 *   was reported, its hazard value, and its location rounded to two decimal
 *   places, which is about a kilometre. No title, description, landmark,
 *   photograph, or credit. The contract has no field for any of them.
 * - A published Spot goes as `published`: what the site's own public API
 *   shows a visitor, built by Spots::payload() for the public. A coarse
 *   Spot's point is already coarsened there and its landmark removed.
 * - Anything else is `removed`: kept private, returned to the reporter,
 *   linked as a duplicate, in the trash, or deleted.
 *
 * A Spot that was copied from the hub when a mission adopted it is never
 * sent. The hub has it.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

use PackOut\Core\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * What a partner site sends of its Spots, and what the hub says back.
 */
final class Exchange {

	/**
	 * How the Spot stands on the hub: `shown`, `waiting`, `held`, `hidden`, or `returned`.
	 */
	public const STANDING = '_pos_hub_standing';

	/**
	 * Why a hub coordinator hid or returned it.
	 */
	public const REASON = '_pos_hub_reason';

	/**
	 * Reports of what may be the same thing, on the hub. A JSON list.
	 */
	public const HINTS = '_pos_hub_hints';

	/**
	 * The state the Spot was last sent in.
	 */
	public const SENT = '_pos_hub_sent';

	/**
	 * The hub Spot this one was copied from when a mission adopted it.
	 */
	public const ADOPTED = '_pos_hub_spot';

	/**
	 * When the hub was last asked what changed there. An option.
	 */
	public const PULLED = 'packout_exchange_pulled';

	/**
	 * Decimal places an unreviewed location is rounded to.
	 */
	public const ROUND = 2;

	/**
	 * The most photographs sent for one Spot.
	 */
	public const PHOTOS = 36;

	/**
	 * Hook what the queue does not see by itself. A partner site only.
	 */
	public static function register(): void {
		if ( App::is_hub() ) {
			return;
		}
		add_action( 'add_attachment', array( __CLASS__, 'photo_changed' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'photo_changed' ) );
	}

	/**
	 * A photograph was added to a Spot or to a record of work on one, or removed.
	 *
	 * @param int $id The attachment.
	 */
	public static function photo_changed( $id ): void {
		$parent = (int) wp_get_post_parent_id( (int) $id );
		$type   = $parent > 0 ? get_post_type( $parent ) : '';
		if ( Schema::SPOT === $type ) {
			Outbox::spot( $parent );
		} elseif ( Schema::IMPACT === $type ) {
			$spot = (int) get_post_meta( $parent, '_pos_spot', true );
			if ( $spot > 0 ) {
				Outbox::spot( $spot );
			}
		}
	}

	/**
	 * The state a Spot is sent in.
	 *
	 * @param int $id The Spot.
	 * @return string `awaiting`, `published`, `removed`, or empty for a Spot that is never sent.
	 */
	public static function state( int $id ): string {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || Schema::SPOT !== $post->post_type ) {
			return 'removed';
		}
		if ( Adoptions::making() || '' !== (string) get_post_meta( $id, self::ADOPTED, true ) ) {
			return '';
		}
		if ( '' !== Spots::field( $id, 'duplicate_of' ) || '' !== (string) $post->post_password ) {
			return 'removed';
		}
		if ( 'publish' === $post->post_status ) {
			return 'published';
		}
		if ( 'pending' === $post->post_status && '' === Spots::field( $id, 'return_reason' ) ) {
			return 'awaiting';
		}
		return 'removed';
	}

	/**
	 * A Spot, as it is sent.
	 *
	 * @param int $id The Spot.
	 * @return array<string, mixed>|null The item, or null for a Spot that is never sent.
	 */
	public static function item( int $id ): ?array {
		$state = self::state( $id );
		if ( '' === $state ) {
			return null;
		}
		$post = get_post( $id );
		$item = array(
			'remote_id' => (string) $id,
			'rev'       => $post instanceof \WP_Post ? Listings::rev( $id ) : time(),
			'state'     => $state,
		);
		if ( 'removed' === $state || ! $post instanceof \WP_Post ) {
			return $item;
		}

		$term     = Spots::category( $id );
		$category = array(
			'slug' => $term ? substr( sanitize_title( $term->slug ), 0, 60 ) : '',
			'name' => $term ? self::text( $term->name, 80 ) : '',
		);
		$hazard   = Spots::field( $id, 'hazard', 'none' );
		$hazard   = '' !== $hazard ? $hazard : 'none';

		if ( 'awaiting' === $state ) {
			$item += array(
				'category' => $category,
				'reported' => (string) mysql2date( 'Y-m-d', $post->post_date, false ),
				'hazard'   => $hazard,
			);
			$pin   = Spots::pin( $id );
			if ( $pin ) {
				$item['location'] = array(
					'lat' => self::rounded( (float) $pin[0] ),
					'lng' => self::rounded( (float) $pin[1] ),
				);
			}
			return $item;
		}

		// What a visitor to this site is shown, and nothing a visitor is not.
		$public = Spots::payload( $post, 'public' );
		$status = sanitize_key( (string) ( $public['status'] ?? 'spotted' ) );
		$status = '' !== $status ? $status : 'spotted';
		$title  = self::text( (string) $public['title'], 200 );
		$item  += array(
			'title'               => '' !== $title ? $title : ( '' !== $category['name'] ? $category['name'] : __( 'Spot', 'packout-core' ) ),
			'url'                 => (string) $public['url'],
			'category'            => $category,
			'status'              => $status,
			'hazard'              => $hazard,
			'needs_authorization' => (bool) $public['needs_authorization'],
			'authorized'          => (bool) $public['authorized'],
			'guidance'            => self::text( (string) $public['guidance'], 600 ),
			'description'         => self::text( (string) $public['description'], 600 ),
			'location'            => array(
				'kind'      => preg_replace( '/[^a-z_]/', '', (string) $public['location']['kind'] ),
				'lat'       => null === $public['location']['lat'] ? null : (float) $public['location']['lat'],
				'lng'       => null === $public['location']['lng'] ? null : (float) $public['location']['lng'],
				'precision' => 'coarse' === $public['location']['precision'] ? 'coarse' : 'exact',
				'landmark'  => self::text( (string) $public['location']['landmark'], 200 ),
			),
			'reported'            => (string) $public['reported'],
			'updated'             => (string) get_post_modified_time( 'c', true, $post ),
			'photos'              => self::photos( $post ),
		);
		if ( '' === $item['location']['kind'] ) {
			$item['location']['kind'] = 'description';
		}
		// A Spot a youth team works on is sent with nobody credited: the line could name a young person.
		$label = Subteams::youth_spot( $id ) ? '' : self::text( (string) ( $public['reported_by']['label'] ?? '' ), 120 );
		if ( '' !== $label ) {
			$item['credit'] = array( 'label' => $label );
			$url            = esc_url_raw( (string) ( $public['reported_by']['url'] ?? '' ), array( 'https', 'http' ) );
			if ( '' !== $url && strlen( $url ) <= 500 ) {
				$item['credit']['url'] = $url;
			}
		}
		// The organization the reporter credited, when the hub lists it.
		$team = (int) ( Spots::credit( $id )['team'] ?? 0 );
		if ( $team > 0 && Listings::listable( 'organization', (string) $team ) ) {
			$item['organization'] = (string) $team;
		}
		return $item;
	}

	/**
	 * The photographs of a Spot that go to the hub: the ones this site shows everyone.
	 *
	 * @param \WP_Post $post The Spot.
	 * @return array<int, array{id: string, of: string}>
	 */
	public static function photos( \WP_Post $post ): array {
		if ( 'published' !== self::state( $post->ID ) || ! self::photos_allowed( $post->ID ) ) {
			return array();
		}
		$photos = array();
		foreach ( array_slice( Spots::gallery( $post ), 0, self::PHOTOS ) as $photo ) {
			$photos[] = array(
				'id' => (string) (int) $photo['id'],
				'of' => in_array( $photo['kind'], array( 'cleanup', 'visit' ), true ) ? (string) $photo['kind'] : 'report',
			);
		}
		return $photos;
	}

	/**
	 * Whether a Spot's photographs may leave this site at all.
	 *
	 * @param int $id The Spot.
	 */
	public static function photos_allowed( int $id ): bool {
		// No photograph of a youth team's work leaves the site.
		if ( Subteams::youth_spot( $id ) ) {
			return false;
		}
		/**
		 * Filters whether a Spot's public photographs are sent to packout.spot.
		 *
		 * A site returns false for a Spot whose photographs should stay on it.
		 *
		 * @param bool $allowed Whether they are sent.
		 * @param int  $id      The Spot.
		 */
		return (bool) apply_filters( 'packout_exchange_photos', true, $id );
	}

	/**
	 * The file of one photograph of a Spot, when the photograph is one the public sees.
	 *
	 * @param int $spot  The Spot.
	 * @param int $photo The attachment.
	 * @return array{path: string, of: string}|null Null when it is not to be sent.
	 */
	public static function photo( int $spot, int $photo ): ?array {
		$post = Spots::get( $spot );
		if ( ! $post ) {
			return null;
		}
		foreach ( self::photos( $post ) as $allowed ) {
			if ( (int) $allowed['id'] === $photo ) {
				$path = (string) get_attached_file( $photo );
				return '' !== $path && is_readable( $path ) ? array(
					'path' => $path,
					'of'   => $allowed['of'],
				) : null;
			}
		}
		return null;
	}

	/**
	 * Keep what the hub answered for one Spot, and queue the photographs it asked for.
	 *
	 * @param array<string, mixed> $result The hub's answer for the item.
	 * @param string               $state  The state the Spot was sent in.
	 */
	public static function settle( array $result, string $state ): void {
		$id = (int) ( $result['remote_id'] ?? 0 );
		if ( $id <= 0 || ! Spots::get( $id ) ) {
			return;
		}
		update_post_meta( $id, self::SENT, $state );
		self::stand( $id, $result );
		if ( 'published' === $state ) {
			foreach ( array_slice( (array) ( $result['photos'] ?? array() ), 0, self::PHOTOS ) as $photo ) {
				if ( (int) $photo > 0 ) {
					Outbox::enqueue( 'image', 'spot:' . $id . ':' . (int) $photo );
				}
			}
		}
	}

	/**
	 * Keep how a Spot stands on the hub.
	 *
	 * @param int                  $id     The Spot.
	 * @param array<string, mixed> $result What the hub said: `standing`, `reason`, and `hints`.
	 */
	public static function stand( int $id, array $result ): void {
		$standing = sanitize_key( (string) ( $result['standing'] ?? '' ) );
		if ( ! in_array( $standing, array( 'shown', 'waiting', 'held', 'hidden', 'returned', 'linked' ), true ) ) {
			return;
		}
		update_post_meta( $id, self::STANDING, $standing );
		$reason = self::text( (string) ( $result['reason'] ?? '' ), 300 );
		if ( '' !== $reason ) {
			update_post_meta( $id, self::REASON, $reason );
		} else {
			delete_post_meta( $id, self::REASON );
		}
		$hints = array();
		foreach ( array_slice( (array) ( $result['hints'] ?? array() ), 0, 5 ) as $hint ) {
			$url = esc_url_raw( (string) ( $hint['url'] ?? '' ), array( 'https', 'http' ) );
			if ( '' !== $url && 0 === strpos( $url, Connection::hub() ) ) {
				$hints[] = array(
					'url'   => $url,
					'title' => self::text( (string) ( $hint['title'] ?? '' ), 200 ),
				);
			}
		}
		if ( $hints ) {
			update_post_meta( $id, self::HINTS, wp_slash( (string) wp_json_encode( $hints ) ) );
		} else {
			delete_post_meta( $id, self::HINTS );
		}
	}

	/**
	 * How a Spot stands on the hub, for a coordinator here.
	 *
	 * @param int $id The Spot.
	 * @return array{standing: string, reason: string, hints: array<int, array{url: string, title: string}>}
	 */
	public static function standing( int $id ): array {
		$hints = json_decode( (string) get_post_meta( $id, self::HINTS, true ), true );
		return array(
			'standing' => (string) get_post_meta( $id, self::STANDING, true ),
			'reason'   => (string) get_post_meta( $id, self::REASON, true ),
			'hints'    => is_array( $hints ) ? $hints : array(),
		);
	}

	/**
	 * What to tell a coordinator here about a Spot on the hub, as sentences. Empty when there is nothing to say.
	 *
	 * @param int $id The Spot.
	 * @return array<int, array{text: string, url: string, link: string, intent: string}>
	 */
	public static function lines( int $id ): array {
		if ( App::is_hub() || ! Connection::connected() ) {
			return array();
		}
		$stands = self::standing( $id );
		$lines  = array();
		$line   = static fn( string $text, string $intent = 'info', string $url = '', string $link = '' ): array => array(
			'text'   => $text,
			'url'    => $url,
			'link'   => $link,
			'intent' => $intent,
		);
		switch ( $stands['standing'] ) {
			case 'hidden':
				/* translators: %s: a reason a coordinator gave. */
				$lines[] = $line( sprintf( __( 'A coordinator on packout.spot hid this Spot from the map there: %s', 'packout-core' ), '' !== $stands['reason'] ? $stands['reason'] : __( 'no reason was given.', 'packout-core' ) ), 'warning' );
				break;
			case 'returned':
				/* translators: %s: a reason a coordinator gave. */
				$lines[] = $line( sprintf( __( 'A coordinator on packout.spot asks for a change before this Spot is shown there again: %s Save the Spot when it is changed.', 'packout-core' ), '' !== $stands['reason'] ? $stands['reason'] : '' ), 'warning' );
				break;
			case 'held':
				$lines[] = $line( __( 'packout.spot is holding this Spot for its own coordinators to look at before it is shown there.', 'packout-core' ) );
				break;
			case 'linked':
				$lines[] = $line( __( 'packout.spot shows this Spot as part of a report it already had, not as a marker of its own.', 'packout-core' ) );
				break;
		}
		foreach ( $stands['hints'] as $hint ) {
			$lines[] = $line( __( 'packout.spot has a report near this one, in the same category. It may be the same thing.', 'packout-core' ), 'info', (string) $hint['url'], '' !== (string) $hint['title'] ? (string) $hint['title'] : __( 'See it on packout.spot', 'packout-core' ) );
		}
		return $lines;
	}

	/**
	 * Ask the hub what changed there since the last time: a Spot hidden or returned, a hint, an adoption released, an answer to a request.
	 *
	 * @return bool Whether the hub answered.
	 */
	public static function pull(): bool {
		if ( App::is_hub() || ! Connection::sending() ) {
			return false;
		}
		$since  = (int) get_option( self::PULLED, 0 );
		$answer = Connection::send( 'GET', '/changes?since=' . $since, null );
		if ( is_wp_error( $answer ) ) {
			return false;
		}
		foreach ( array_slice( (array) ( $answer['spots'] ?? array() ), 0, 500 ) as $spot ) {
			$id = (int) ( $spot['remote_id'] ?? 0 );
			if ( $id > 0 && Spots::get( $id ) ) {
				self::stand( $id, (array) $spot );
			}
		}
		/**
		 * The hub said what changed there.
		 *
		 * @param array<string, mixed> $answer The hub's answer: `spots`, `adoptions`, `requests`, and `now`.
		 */
		do_action( 'packout_exchange_pulled', $answer );
		update_option( self::PULLED, max( $since, (int) ( $answer['now'] ?? time() ) ), false );
		return true;
	}

	/**
	 * Compare what the hub holds of this site's Spots with what it should hold, and queue the difference.
	 *
	 * @return int|\WP_Error How many Spots were queued.
	 */
	public static function reconcile() {
		global $wpdb;
		$held  = array();
		$after = '0';
		for ( $page = 0; $page < 200; $page++ ) {
			$answer = Connection::send( 'GET', '/spots/index?after=' . $after, null );
			if ( is_wp_error( $answer ) ) {
				return $answer;
			}
			$rows = (array) ( $answer['items'] ?? array() );
			foreach ( $rows as $row ) {
				$held[ (int) ( $row[0] ?? 0 ) ] = array( (int) ( $row[1] ?? 0 ), (string) ( $row[2] ?? '' ) );
				$after                          = (string) (int) ( $row[0] ?? 0 );
			}
			if ( empty( $answer['more'] ) || ! $rows ) {
				break;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Every Spot the public or a reviewer may hold, by ID only.
		$mine   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ( 'publish', 'pending' )", Schema::SPOT ) ) );
		$queued = 0;
		foreach ( array_chunk( $mine, 200 ) as $chunk ) {
			update_meta_cache( 'post', $chunk );
			foreach ( $chunk as $id ) {
				$state = self::state( $id );
				$known = $held[ $id ] ?? null;
				unset( $held[ $id ] );
				if ( '' === $state || ( 'removed' === $state && ! $known ) ) {
					continue;
				}
				if ( ! $known || $state !== $known[1] || Listings::rev( $id ) !== $known[0] ) {
					Outbox::enqueue( 'spot', (string) $id );
					++$queued;
				}
			}
		}
		// What the hub holds and this site no longer shows.
		foreach ( array_keys( $held ) as $id ) {
			if ( $id > 0 ) {
				Outbox::enqueue( 'spot', (string) $id );
				++$queued;
			}
		}
		return $queued;
	}

	/**
	 * A location rounded as an unreviewed Spot's is.
	 *
	 * @param float $value A latitude or a longitude.
	 */
	public static function rounded( float $value ): float {
		return round( $value, self::ROUND );
	}

	/**
	 * Whether a number is already rounded as an unreviewed location is.
	 *
	 * @param mixed $value A latitude or a longitude.
	 */
	public static function is_rounded( $value ): bool {
		return ( is_int( $value ) || is_float( $value ) ) && abs( round( (float) $value, self::ROUND ) - (float) $value ) < 1e-9;
	}

	/**
	 * Plain text of no more than a length.
	 *
	 * @param string $text  The text.
	 * @param int    $limit The most characters.
	 */
	private static function text( string $text, int $limit ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit );
	}

	/**
	 * The most Spots in one request.
	 */
	public static function batch(): int {
		return Contract::SPOTS;
	}
}
