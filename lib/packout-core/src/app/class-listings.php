<?php
/**
 * Listings: the public face of a partner site's records, as the site sends
 * them to packout.spot.
 *
 * A listing is an organization that is listed, a team with a public page, a
 * public program, a public campaign, or a public mission. Each is built from
 * the record as it is at the moment it is sent, so what goes out is never
 * older than the site. A record that is no longer public, or no longer
 * exists, is sent once more as `deleted`.
 *
 * No person is in a listing: no member, lead, reporter, or sponsor contact,
 * and no email address. The fields are the ones `contract/v1/listings.json`
 * lists, and a field it does not list is refused at the other end.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

defined( 'ABSPATH' ) || exit;

/**
 * What a partner site sends about its public records.
 */
final class Listings {

	/**
	 * The field that holds a record's revision: a number that rises each time it is queued.
	 */
	public const REV = '_pos_rev';

	/**
	 * The order a batch is applied in, and the record type of each kind. A team is part of its organization's record.
	 *
	 * @return array<string, string>
	 */
	public static function kinds(): array {
		return array(
			'organization' => Schema::TEAM,
			'team'         => Schema::TEAM,
			'program'      => Schema::PROGRAM,
			'campaign'     => Schema::CAMPAIGN,
			'mission'      => Schema::MISSION,
		);
	}

	/**
	 * The kind of listing a record type gives.
	 *
	 * @param string $type A record type.
	 * @return string Empty for a type that is not listed.
	 */
	public static function kind_of( string $type ): string {
		$kinds = array(
			Schema::TEAM     => 'organization',
			Schema::PROGRAM  => 'program',
			Schema::CAMPAIGN => 'campaign',
			Schema::MISSION  => 'mission',
		);
		return $kinds[ $type ] ?? '';
	}

	/**
	 * A record's revision.
	 *
	 * @param int $id The record.
	 */
	public static function rev( int $id ): int {
		return max( 1, (int) get_post_meta( $id, self::REV, true ) );
	}

	/**
	 * Raise a record's revision. It never goes back, and it follows the clock, so a record deleted and made again is still newer.
	 *
	 * @param int $id The record.
	 */
	public static function bump( int $id ): int {
		$next = max( self::rev( $id ) + 1, time() );
		update_post_meta( $id, self::REV, $next );
		return $next;
	}

	/**
	 * Whether a record is one the public may see on this site, and so one to list.
	 *
	 * @param string $kind      The kind of listing.
	 * @param string $remote_id The record's identifier here: a post ID, or `organization-team` for a team.
	 */
	public static function listable( string $kind, string $remote_id ): bool {
		list( $id, $sub ) = self::parts( $remote_id );
		$post             = get_post( $id );
		if ( ! $post instanceof \WP_Post || ( self::kinds()[ $kind ] ?? '' ) !== $post->post_type || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return false;
		}
		switch ( $kind ) {
			case 'organization':
				return Teams::active( $id );
			case 'team':
				return $sub > 0 && Teams::active( $id ) && Subteams::shown( $id, $sub );
			case 'program':
				return Programs::shown( $id );
			case 'campaign':
				// A copy of a joint campaign is the hub's record, not one of this site's to list.
				return ! Joint::is_copy( $id ) && Campaigns::team( $id ) > 0 && self::listable( 'organization', (string) Campaigns::team( $id ) );
			case 'mission':
				return self::listable( 'organization', (string) Missions::team( $id ) );
		}
		return false;
	}

	/**
	 * A listing, as it is sent.
	 *
	 * @param string $kind      The kind of listing.
	 * @param string $remote_id The record's identifier here.
	 * @return array<string, mixed> The item, or the same marked `deleted` when the record is not to be listed.
	 */
	public static function item( string $kind, string $remote_id ): array {
		list( $id, $sub ) = self::parts( $remote_id );
		$post             = get_post( $id );
		$item             = array(
			'kind'      => $kind,
			'remote_id' => $remote_id,
			'rev'       => $post instanceof \WP_Post ? self::rev( $id ) : time(),
			'updated'   => $post instanceof \WP_Post ? (string) get_post_modified_time( 'c', true, $post ) : gmdate( 'c' ),
		);
		if ( ! $post instanceof \WP_Post || ! self::listable( $kind, $remote_id ) ) {
			$item['deleted'] = true;
			return $item;
		}

		switch ( $kind ) {
			case 'organization':
				$join  = Teams::join_policy( $id );
				$item += array(
					'name'        => self::text( $post->post_title, 200 ),
					'description' => self::text( $post->post_content, 5000 ),
					'region'      => self::text( Teams::field( $id, 'region' ), 120 ),
					'website'     => self::web( Teams::field( $id, 'website' ), 200 ),
					'join'        => in_array( $join, array( 'request', 'open', 'closed' ), true ) ? $join : 'request',
					'url'         => (string) get_permalink( $post ),
					'teams'       => array_map( static fn( array $subteam ): string => $id . '-' . (int) $subteam['id'], Subteams::listed( $id ) ),
				);
				$type  = Vocabularies::listing_type( $id, Vocabularies::GROUP_TYPE );
				if ( $type ) {
					$item['group_type'] = $type;
				}
				break;

			case 'team':
				$subteam = Subteams::get( $id, $sub );
				$item   += array(
					'organization' => (string) $id,
					'name'         => self::text( (string) ( $subteam['name'] ?? '' ), 200 ),
					'description'  => self::text( (string) ( $subteam['about'] ?? '' ), 5000 ),
					'url'          => Subteams::url( $id, $sub ),
					'youth'        => ! empty( $subteam['youth'] ),
				);
				break;

			case 'program':
				$item += array(
					'title'         => self::text( $post->post_title, 200 ),
					'description'   => self::text( $post->post_content, 5000 ),
					'goal'          => self::text( Programs::field( $id, 'goal' ), 300 ),
					'start'         => Schema::day( Programs::field( $id, 'start' ) ),
					'end'           => Schema::day( Programs::field( $id, 'end' ) ),
					'state'         => 'closed' === Programs::state( $id ) ? 'closed' : 'open',
					'organizations' => array_values( array_map( 'strval', array_filter( array_map( 'intval', Programs::taking_part( $id ) ), static fn( int $team ): bool => self::listable( 'organization', (string) $team ) ) ) ),
					'url'           => (string) get_permalink( $post ),
				);
				$type  = Vocabularies::listing_type( $id, Vocabularies::PROGRAM_TYPE );
				if ( $type ) {
					$item['program_type'] = $type;
				}
				break;

			case 'campaign':
				$team  = Campaigns::team( $id );
				$item += array(
					'organization' => (string) $team,
					'program'      => self::reference( 'program', Programs::of_campaign( $id ) ),
					'title'        => self::text( $post->post_title, 200 ),
					'description'  => self::text( $post->post_content, 5000 ),
					'goal'         => self::text( Campaigns::field( $id, 'goal' ), 300 ),
					'teams'        => self::teams( $team, Campaigns::subteams( $id ) ),
					'url'          => (string) get_permalink( $post ),
				) + self::window( $id );
				// A campaign the site hosts as a joint campaign says so. Who takes part is kept on the hub.
				if ( Joint::is_joint( $id ) ) {
					$item['joint'] = Joint::closed( $id ) ? 'closed' : 'open';
				}
				break;

			case 'mission':
				$team   = Missions::team( $id );
				$status = Missions::status( $id );
				$item  += array(
					'organization' => (string) $team,
					// A mission in a joint campaign another organization hosts names the hub's number for the campaign.
					'campaign'     => Joint::is_copy( Missions::campaign( $id ) ) ? 'hub:' . Joint::number( Missions::campaign( $id ) ) : self::reference( 'campaign', Missions::campaign( $id ) ),
					'program'      => self::reference( 'program', Programs::of_mission( $id ) ),
					'teams'        => self::teams( $team, Missions::subteams( $id ) ),
					'title'        => self::text( $post->post_title, 200 ),
					'description'  => self::text( Missions::field( $id, 'description' ), 5000 ),
					'status'       => in_array( $status, array( 'planned', 'in_progress', 'completed', 'called_off' ), true ) ? $status : 'planned',
					// The landmark the lead chose to share. The pin itself is not sent.
					'meeting'      => '1' === Missions::field( $id, 'meeting_share' ) ? self::text( Missions::field( $id, 'meeting_note' ), 300 ) : '',
					'spots'        => count( Missions::spots( $id ) ),
					'url'          => (string) get_permalink( $post ),
				) + self::window( $id );
				break;
		}//end switch

		// An address that is not on this site, or is not an address, means the record has no page to link to.
		if ( 1 !== preg_match( '#^https?://\S+$#', (string) ( $item['url'] ?? '' ) ) ) {
			return array(
				'kind'      => $kind,
				'remote_id' => $remote_id,
				'rev'       => $item['rev'],
				'updated'   => $item['updated'],
				'deleted'   => true,
			);
		}
		return $item;
	}

	/**
	 * Every record to list, for a full send.
	 *
	 * @return array<int, array{0: string, 1: string}> Kind and identifier, in the order a batch is applied.
	 */
	public static function all(): array {
		$found = array();
		$posts = get_posts(
			array(
				'post_type'        => array( Schema::TEAM, Schema::PROGRAM, Schema::CAMPAIGN, Schema::MISSION ),
				'post_status'      => 'publish',
				'numberposts'      => 2000,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		foreach ( $posts as $post ) {
			$kind = self::kind_of( $post->post_type );
			if ( '' === $kind || ! self::listable( $kind, (string) $post->ID ) ) {
				continue;
			}
			$found[ $kind ][] = (string) $post->ID;
			if ( 'organization' === $kind ) {
				foreach ( Subteams::listed( $post->ID ) as $subteam ) {
					$found['team'][] = $post->ID . '-' . (int) $subteam['id'];
				}
			}
		}
		$list = array();
		foreach ( array_keys( self::kinds() ) as $kind ) {
			foreach ( $found[ $kind ] ?? array() as $remote_id ) {
				$list[] = array( $kind, $remote_id );
			}
		}
		return $list;
	}

	/**
	 * The two numbers of an identifier.
	 *
	 * @param string $remote_id A post ID, or `organization-team`.
	 * @return array{0: int, 1: int}
	 */
	public static function parts( string $remote_id ): array {
		$parts = explode( '-', $remote_id, 2 );
		return array( (int) $parts[0], (int) ( $parts[1] ?? 0 ) );
	}

	/**
	 * Plain text of at most a length.
	 *
	 * @param string $text  The text.
	 * @param int    $limit The most characters.
	 */
	private static function text( string $text, int $limit ): string {
		return mb_substr( trim( wp_strip_all_tags( $text ) ), 0, $limit );
	}

	/**
	 * A web address, or nothing.
	 *
	 * @param string $url   The address.
	 * @param int    $limit The most characters.
	 */
	private static function web( string $url, int $limit ): string {
		$url = esc_url_raw( $url, array( 'https', 'http' ) );
		return '' !== $url && strlen( $url ) <= $limit && 1 === preg_match( '#^https?://\S+$#', $url ) ? $url : '';
	}

	/**
	 * A reference to another listing: its identifier when it is listed too, and nothing when it is not.
	 *
	 * @param string $kind The kind of the other listing.
	 * @param int    $id   The other record.
	 */
	private static function reference( string $kind, int $id ): string {
		return $id > 0 && self::listable( $kind, (string) $id ) ? (string) $id : '';
	}

	/**
	 * The teams of an organization that a campaign or a mission names and that have a public page.
	 *
	 * @param int             $team     The organization.
	 * @param array<int, int> $subteams Team numbers.
	 * @return array<int, string>
	 */
	private static function teams( int $team, array $subteams ): array {
		$named = array();
		foreach ( array_map( 'intval', $subteams ) as $sub ) {
			if ( $sub > 0 && Subteams::shown( $team, $sub ) ) {
				$named[] = $team . '-' . $sub;
			}
		}
		return $named;
	}

	/**
	 * A record's days, times, and time zone.
	 *
	 * @param int $id A campaign or a mission.
	 * @return array<string, string>
	 */
	private static function window( int $id ): array {
		$fields = Schedule::fields( $id );
		return array(
			'start'      => Schema::day( $fields['start'] ),
			'end'        => Schema::day( $fields['end'] ),
			'start_time' => 1 === preg_match( '/^[0-9]{2}:[0-9]{2}$/', $fields['start_time'] ) ? $fields['start_time'] : '',
			'end_time'   => 1 === preg_match( '/^[0-9]{2}:[0-9]{2}$/', $fields['end_time'] ) ? $fields['end_time'] : '',
			// A record that names no zone is in this site's, and the hub is somewhere else.
			'timezone'   => '' !== $fields['timezone'] && 1 === preg_match( '/^[A-Za-z0-9_+\/:-]{1,64}$/', $fields['timezone'] ) ? $fields['timezone'] : Schedule::site_zone(),
		);
	}
}
