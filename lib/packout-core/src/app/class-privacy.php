<?php
/**
 * A partner site's part in WordPress's privacy tools.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Text for the site's privacy policy, and what the plugin gives and removes
 * when an administrator runs WordPress's export or erasure for a person.
 *
 * Erasing removes what is the person's alone: their profile, their contact
 * details, the choices about sharing them, their private notes, and the
 * invitations sent to their address. A Spot, an update on its page, and a
 * record of work are kept, since the history of a place is not one person's,
 * and each stops naming the person. A Spot that stops naming them is sent to
 * packout.spot again, so the copy there stops naming them too.
 */
class Privacy {

	/**
	 * How many of each kind of record one request handles.
	 */
	private const MOST = 500;

	/**
	 * Register with WordPress's privacy tools.
	 *
	 * A partner site is also given text for its privacy policy. packout.spot publishes its own.
	 */
	public static function register(): void {
		if ( ! App::is_hub() ) {
			add_action( 'admin_init', array( self::class, 'policy' ) );
		}
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( 'delete_user', array( self::class, 'leaving' ), 5 );
	}

	/**
	 * An account is being deleted. What is the person's alone goes first, and what is kept stops naming them.
	 *
	 * WordPress then deletes the account's content or gives it to another
	 * account. Neither may happen to a Spot or a record of work: a Spot given
	 * to another account would name that person as its reporter. After this
	 * they belong to no account, and WordPress leaves them where they are.
	 *
	 * @param int $id The account.
	 */
	public static function leaving( $id ): void {
		$person = get_userdata( (int) $id );
		if ( $person && is_email( $person->user_email ) ) {
			self::erase( $person->user_email );
		}
	}

	/**
	 * The suggested text for the site's privacy policy.
	 */
	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( App::kernel()->site()->name(), wp_kses_post( self::policy_text() ) );
	}

	/**
	 * The text itself.
	 */
	public static function policy_text(): string {
		$parts = array(
			array(
				__( 'What is kept about you', 'packout-core' ),
				array(
					__( 'When you make an account: your name, your email address, and your password, kept by WordPress.', 'packout-core' ),
					__( 'If you add them: a few lines about yourself, a phone number, and a postal address. You choose which organizations\' leads may see the phone number and the address, and can take that back at any time.', 'packout-core' ),
					__( 'What you do here: the Spots you report with their photos, the notes and updates you write, the organizations and teams you belong to, the missions you sign up for, the work you record, and the messages you send as a lead.', 'packout-core' ),
					__( 'A photo\'s location is read once, on your device or on this site, to place the pin. The copy this site keeps has no location or camera details in it.', 'packout-core' ),
				),
			),
			array(
				__( 'What other people see', 'packout-core' ),
				array(
					__( 'A published Spot shows your name only if you asked to be named. Otherwise it shows your organization, or "an individual".', 'packout-core' ),
					__( 'The leads of an organization you belong to see your name. They see your email address, phone number, or postal address only where you chose to share it.', 'packout-core' ),
				),
			),
			array(
				__( 'What is sent to packout.spot', 'packout-core' ),
				array(
					__( 'If this site is connected to packout.spot, it sends what is public here: its organizations, programs, campaigns, and missions, its totals, and its Spots. A Spot is sent first as a bare report (category, day, and an approximate location) and in full once a coordinator here publishes it.', 'packout-core' ),
					__( 'Nothing about a person is sent, with one exception: the name you asked to be shown on a Spot you reported is sent with that Spot. No email address, phone number, postal address, membership, or sign-up ever leaves this site.', 'packout-core' ),
					__( 'When this site checks for an update of the plugin, it sends its own address and the plugin\'s version to packout.spot.', 'packout-core' ),
				),
			),
			array(
				__( 'Other services', 'packout-core' ),
				array(
					__( 'The map\'s search asks OpenStreetMap\'s Nominatim service for places by name. The request is made by this site, so the service sees this site and what was searched for, and not you.', 'packout-core' ),
					__( 'The map draws its background from a map tile service. Your browser asks that service for the tiles, so it sees your IP address and the part of the map you look at.', 'packout-core' ),
				),
			),
			array(
				__( 'Your choices', 'packout-core' ),
				array(
					__( 'You can change or remove your profile, contact details, and notes on your account page.', 'packout-core' ),
					__( 'You can ask for a copy of what is kept about you, or for it to be erased. Erasing removes your profile, your contact details, your private notes, and invitations sent to your address. The Spots you reported, the updates on their pages, and the records of work are kept without your name, because they are the history of a place.', 'packout-core' ),
				),
			),
		);
		$html  = '';
		foreach ( $parts as $part ) {
			$html .= '<h3>' . esc_html( $part[0] ) . '</h3><ul>';
			foreach ( $part[1] as $line ) {
				$html .= '<li>' . esc_html( $line ) . '</li>';
			}
			$html .= '</ul>';
		}
		return $html;
	}

	/**
	 * Add the exporter.
	 *
	 * @param mixed $exporters The exporters so far.
	 * @return mixed
	 */
	public static function exporters( $exporters ) {
		if ( is_array( $exporters ) ) {
			$exporters['packout'] = array(
				'exporter_friendly_name' => __( 'Pack-Out Spot', 'packout-core' ),
				'callback'               => array( self::class, 'export' ),
			);
		}
		return $exporters;
	}

	/**
	 * Add the eraser.
	 *
	 * @param mixed $erasers The erasers so far.
	 * @return mixed
	 */
	public static function erasers( $erasers ) {
		if ( is_array( $erasers ) ) {
			$erasers['packout'] = array(
				'eraser_friendly_name' => __( 'Pack-Out Spot', 'packout-core' ),
				'callback'             => array( self::class, 'erase' ),
			);
		}
		return $erasers;
	}

	/**
	 * One group of the export.
	 *
	 * @param string                $group Group id.
	 * @param string                $label Group name.
	 * @param string                $id    Item id.
	 * @param array<string, string> $data  Names and values. Empty values are left out.
	 * @return array<string, mixed>|null
	 */
	private static function item( string $group, string $label, string $id, array $data ): ?array {
		$rows = array();
		foreach ( $data as $name => $value ) {
			if ( '' !== trim( $value ) ) {
				$rows[] = array(
					'name'  => $name,
					'value' => $value,
				);
			}
		}
		return $rows ? array(
			'group_id'    => 'packout-' . $group,
			'group_label' => $label,
			'item_id'     => 'packout-' . $group . '-' . $id,
			'data'        => $rows,
		) : null;
	}

	/**
	 * The records of a person, of one kind.
	 *
	 * @param string               $type   Post type.
	 * @param int                  $user   The person.
	 * @param array<int, string>   $status Post statuses.
	 * @param array<string, mixed> $more   More of the query.
	 * @return array<int, \WP_Post>
	 */
	private static function authored( string $type, int $user, array $status, array $more = array() ): array {
		return get_posts(
			array(
				'post_type'   => $type,
				'post_status' => $status,
				'author'      => $user,
				'numberposts' => self::MOST,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			) + $more
		);
	}

	/**
	 * What is kept about a person, for WordPress's export.
	 *
	 * @param string $email The person's email address.
	 * @param int    $page  The page of the export. Everything fits in one.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( $email, $page = 1 ): array {
		unset( $page );
		$items  = array();
		$person = get_user_by( 'email', (string) $email );
		$yes    = __( 'Yes', 'packout-core' );
		$no     = __( 'No', 'packout-core' );
		if ( $person ) {
			$user    = (int) $person->ID;
			$profile = Profile::get( $user );
			$teams   = Profile::teams( $user );
			$names   = static fn( array $ids ): string => implode( ', ', array_filter( array_map( static fn( int $id ): string => (string) ( $teams[ $id ] ?? get_the_title( $id ) ), $ids ) ) );
			$items[] = self::item(
				'profile',
				__( 'Pack-Out Spot profile', 'packout-core' ),
				(string) $user,
				array(
					__( 'About', 'packout-core' )          => $profile['about'],
					__( 'Phone', 'packout-core' )          => $profile['phone'],
					__( 'Postal address', 'packout-core' ) => implode( ', ', Profile::address_lines( $profile['address'] ) ),
					__( 'Phone shared with the leads of', 'packout-core' ) => $names( $profile['share_phone'] ),
					__( 'Address shared with the leads of', 'packout-core' ) => $names( $profile['share_address'] ),
					__( 'Asked for news', 'packout-core' ) => $profile['news'] ? $yes : $no,
					__( 'Agreed to be sent things by post', 'packout-core' ) => $profile['swag'] ? $yes : $no,
					__( 'Organizations whose email is turned off', 'packout-core' ) => $names( $profile['quiet'] ),
				)
			);

			foreach ( $teams as $id => $name ) {
				$role    = Teams::lead( $id ) === $user ? __( 'Lead', 'packout-core' ) : ( in_array( $user, Teams::coleads( $id ), true ) ? __( 'Co-lead', 'packout-core' ) : __( 'Member', 'packout-core' ) );
				$items[] = self::item(
					'memberships',
					__( 'Pack-Out Spot organizations', 'packout-core' ),
					(string) $id,
					array(
						__( 'Organization', 'packout-core' ) => $name,
						__( 'Role', 'packout-core' ) => $role,
					)
				);
			}

			foreach ( self::authored( Schema::SPOT, $user, Spots::POST_STATUSES ) as $spot ) {
				$items[] = self::item(
					'spots',
					__( 'Pack-Out Spot reports', 'packout-core' ),
					(string) $spot->ID,
					array(
						__( 'Title', 'packout-core' )     => $spot->post_title,
						__( 'Reported', 'packout-core' )  => $spot->post_date,
						__( 'Description', 'packout-core' ) => wp_strip_all_tags( $spot->post_content ),
						__( 'Landmark', 'packout-core' )  => Spots::field( $spot->ID, 'landmark' ),
						__( 'Latitude', 'packout-core' )  => Spots::field( $spot->ID, 'lat' ),
						__( 'Longitude', 'packout-core' ) => Spots::field( $spot->ID, 'lng' ),
						__( 'Asked to be named', 'packout-core' ) => '1' === get_post_meta( $spot->ID, '_pos_credit_named', true ) ? $yes : $no,
						__( 'Address', 'packout-core' )   => 'publish' === $spot->post_status ? (string) get_permalink( $spot ) : '',
					)
				);
			}

			foreach ( self::authored( Updates::TYPE, $user, array( 'publish', 'private' ) ) as $update ) {
				$items[] = self::item(
					'updates',
					__( 'Pack-Out Spot notes and updates', 'packout-core' ),
					(string) $update->ID,
					array(
						__( 'Written', 'packout-core' ) => $update->post_date,
						__( 'About', 'packout-core' )   => get_the_title( $update->post_parent ),
						__( 'Text', 'packout-core' )    => wp_strip_all_tags( $update->post_content ),
						__( 'Shown on the page', 'packout-core' ) => 'publish' === $update->post_status ? $yes : $no,
					)
				);
			}

			foreach ( self::records( $user ) as $record ) {
				$items[] = self::item(
					'impact',
					__( 'Pack-Out Spot records of work', 'packout-core' ),
					(string) $record->ID,
					array(
						__( 'Recorded', 'packout-core' ) => $record->post_date,
						__( 'Kind', 'packout-core' )     => Impact::kind( $record->ID ),
						__( 'Spot', 'packout-core' )     => get_the_title( (int) Impact::field( $record->ID, 'spot', '0' ) ),
						__( 'Day', 'packout-core' )      => Impact::field( $record->ID, 'date' ),
						__( 'Hours', 'packout-core' )    => Impact::field( $record->ID, 'hours' ),
						__( 'People', 'packout-core' )   => Impact::field( $record->ID, 'people' ),
						__( 'Bags', 'packout-core' )     => Impact::field( $record->ID, 'bags' ),
						__( 'Pounds', 'packout-core' )   => Impact::field( $record->ID, 'pounds' ),
						__( 'Notes', 'packout-core' )    => wp_strip_all_tags( $record->post_content ),
					)
				);
			}

			foreach ( self::signed_up( $user ) as $event ) {
				$items[] = self::item(
					'signups',
					__( 'Pack-Out Spot sign-ups', 'packout-core' ),
					(string) $event->ID,
					array(
						__( 'Signed up for', 'packout-core' ) => $event->post_title,
						__( 'Starts', 'packout-core' ) => (string) get_post_meta( $event->ID, '_pos_start', true ),
					)
				);
			}

			foreach ( array_keys( $teams ) as $id ) {
				foreach ( Messages::all( (int) $id ) as $message ) {
					if ( $message['by'] !== $user ) {
						continue;
					}
					$items[] = self::item(
						'messages',
						__( 'Pack-Out Spot messages sent', 'packout-core' ),
						$id . '-' . $message['id'],
						array(
							__( 'Sent', 'packout-core' ) => $message['at'],
							__( 'To', 'packout-core' )   => '' !== $message['label'] ? $message['label'] : (string) ( $teams[ $id ] ?? '' ),
							__( 'Subject', 'packout-core' ) => $message['subject'],
							__( 'Message', 'packout-core' ) => wp_strip_all_tags( $message['body'] ),
						)
					);
				}
			}
		}//end if

		foreach ( self::claimed( (string) $email ) as $spot ) {
			$items[] = self::item(
				'claims',
				__( 'Pack-Out Spot cleanups reported without an account', 'packout-core' ),
				(string) $spot,
				array(
					__( 'Spot', 'packout-core' )       => get_the_title( $spot ),
					__( 'Reported', 'packout-core' )   => (string) get_post_meta( $spot, '_pos_claim_at', true ),
					__( 'Name given', 'packout-core' ) => (string) get_post_meta( $spot, '_pos_claim_name', true ),
					__( 'Note', 'packout-core' )       => (string) get_post_meta( $spot, '_pos_claim_note', true ),
					__( 'Asked to be named', 'packout-core' ) => '1' === get_post_meta( $spot, '_pos_claim_named', true ) ? $yes : $no,
				)
			);
		}

		foreach ( self::invitations( (string) $email ) as $row ) {
			$items[] = self::item(
				'invitations',
				__( 'Pack-Out Spot invitations', 'packout-core' ),
				(string) $row['id'],
				array(
					__( 'Invited to', 'packout-core' ) => get_the_title( (int) $row['team_id'] ),
					__( 'Sent', 'packout-core' )       => (string) $row['sent_at'],
					__( 'Status', 'packout-core' )     => (string) $row['status'],
				)
			);
		}

		/**
		 * Filters what the export says is kept about a person. A plugin that keeps more adds it here.
		 *
		 * @param array<int, array<string, mixed>|null> $items The export's items, in WordPress's form.
		 * @param string                                $email The person's email address.
		 */
		$items = (array) apply_filters( 'packout_privacy_export', $items, (string) $email );

		return array(
			'data' => array_values( array_filter( $items ) ),
			'done' => true,
		);
	}

	/**
	 * The Spots a person said they cleaned, by the address they gave without an account.
	 *
	 * @param string $email The address.
	 * @return array<int, int>
	 */
	private static function claimed( string $email ): array {
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'   => Schema::SPOT,
					'post_status' => array_merge( Spots::POST_STATUSES, array( 'trash' ) ),
					'numberposts' => self::MOST,
					'fields'      => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One person's Spots, at an administrator's request.
					'meta_key'    => '_pos_claim_email',
					'meta_value'  => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
				)
			)
		);
	}

	/**
	 * The records of work a person wrote or is named on.
	 *
	 * @param int $user The person.
	 * @return array<int, \WP_Post>
	 */
	private static function records( int $user ): array {
		$found = array();
		$named = get_posts(
			array(
				'post_type'   => Schema::IMPACT,
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => self::MOST,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One person's records, at an administrator's request.
				'meta_query'  => array(
					'relation' => 'OR',
					array(
						'key'   => '_pos_user',
						'value' => $user,
					),
					array(
						'key'   => '_pos_person',
						'value' => $user,
					),
				),
			)
		);
		foreach ( array_merge( self::authored( Schema::IMPACT, $user, array( 'publish', 'private' ) ), $named ) as $record ) {
			$found[ $record->ID ] = $record;
		}
		ksort( $found );
		return array_values( $found );
	}

	/**
	 * The campaigns and missions a person is signed up for.
	 *
	 * @param int $user The person.
	 * @return array<int, \WP_Post>
	 */
	private static function signed_up( int $user ): array {
		$found = array();
		foreach ( array_keys( Profile::teams( $user ) ) as $team ) {
			foreach ( get_posts(
				array(
					'post_type'   => array( Schema::MISSION, Schema::CAMPAIGN ),
					'post_status' => array( 'publish', 'private' ),
					'numberposts' => self::MOST,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One organization's events, at an administrator's request.
					'meta_key'    => '_pos_team',
					'meta_value'  => (int) $team, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
				)
			) as $event ) {
				if ( in_array( $user, array_map( 'intval', Schema::stored_list( $event->ID, 'signup' ) ), true ) ) {
					$found[ $event->ID ] = $event;
				}
			}
		}
		return array_values( $found );
	}

	/**
	 * The invitations sent to an address.
	 *
	 * @param string $email The address.
	 * @return array<int, array<string, mixed>>
	 */
	private static function invitations( string $email ): array {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return array();
		}
		$table = Invitations::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, team_id, sent_at, status FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT %d", $email, self::MOST ), ARRAY_A );
	}

	/**
	 * Remove what is a person's alone, and take their name off what is kept.
	 *
	 * @param string $email The person's email address.
	 * @param int    $page  The page of the erasure. Everything fits in one.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public static function erase( $email, $page = 1 ): array {
		global $wpdb;
		unset( $page );
		$removed  = false;
		$retained = false;
		$messages = array();
		$person   = get_user_by( 'email', (string) $email );

		$sent = self::invitations( (string) $email );
		if ( $sent ) {
			$ids    = implode( ',', array_map( static fn( array $row ): int => (int) $row['id'], $sent ) );
			$table  = Invitations::table();
			$events = Invitations::events_table();
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own tables, and numbers read from them.
			$wpdb->query( "DELETE FROM {$events} WHERE invitation_id IN ({$ids})" );
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids})" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$removed = true;
		}

		// A cleanup reported without an account keeps its place, and no longer says who reported it.
		foreach ( self::claimed( (string) $email ) as $spot ) {
			foreach ( array( 'email', 'name', 'named' ) as $field ) {
				delete_post_meta( $spot, '_pos_claim_' . $field );
			}
			$removed = true;
		}

		if ( ! $person ) {
			/** This filter is documented below. */
			return (array) apply_filters(
				'packout_privacy_erase',
				array(
					'items_removed'  => $removed,
					'items_retained' => false,
					'messages'       => array(),
					'done'           => true,
				),
				(string) $email
			);
		}
		$user = (int) $person->ID;

		if ( metadata_exists( 'user', $user, Profile::META ) ) {
			delete_user_meta( $user, Profile::META );
			$removed = true;
		}

		// A private note is its author's alone, and goes. An update on a Spot's page stays, without the name.
		foreach ( self::authored( Updates::TYPE, $user, array( 'publish', 'private' ) ) as $update ) {
			if ( 'private' === $update->post_status ) {
				wp_delete_post( $update->ID, true );
				$removed = true;
				continue;
			}
			delete_post_meta( $update->ID, '_pos_credit_named' );
			self::unsign( $update->ID );
			$retained = true;
		}

		$spots = 0;
		foreach ( self::authored( Schema::SPOT, $user, array_merge( Spots::POST_STATUSES, array( 'trash' ) ) ) as $spot ) {
			// Removing the flag queues the Spot for packout.spot, so its copy there stops naming the person too.
			delete_post_meta( $spot->ID, '_pos_credit_named' );
			self::unsign( $spot->ID );
			if ( Connection::connected() ) {
				Outbox::spot( $spot->ID );
			}
			++$spots;
		}

		// A cleanup the person reported from a Spot's page, and a Spot they are named as having cleaned.
		foreach ( array( '_pos_claim_by', '_pos_cleaned_by' ) as $key ) {
			$claimed = get_posts(
				array(
					'post_type'   => Schema::SPOT,
					'post_status' => array_merge( Spots::POST_STATUSES, array( 'trash' ) ),
					'numberposts' => self::MOST,
					'fields'      => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One person's Spots, at an administrator's request.
					'meta_key'    => $key,
					'meta_value'  => $user, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
				)
			);
			foreach ( $claimed as $spot ) {
				delete_post_meta( (int) $spot, $key );
				delete_post_meta( (int) $spot, '_pos_named' );
				if ( Connection::connected() ) {
					Outbox::spot( (int) $spot );
				}
				++$spots;
			}
		}//end foreach

		$records = 0;
		foreach ( self::records( $user ) as $record ) {
			delete_post_meta( $record->ID, '_pos_user', $user );
			delete_post_meta( $record->ID, '_pos_person', $user );
			if ( (int) $record->post_author === $user ) {
				self::unsign( $record->ID );
			}
			++$records;
		}

		if ( $spots > 0 ) {
			$retained = true;
			/* translators: %s: a number. */
			$messages[] = sprintf( _n( '%s Spot the person reported or cleaned was kept, and no longer names them.', '%s Spots the person reported or cleaned were kept, and no longer name them.', $spots, 'packout-core' ), number_format_i18n( $spots ) );
		}
		if ( $records > 0 ) {
			$retained = true;
			/* translators: %s: a number. */
			$messages[] = sprintf( _n( '%s record of work was kept, and no longer names the person.', '%s records of work were kept, and no longer name the person.', $records, 'packout-core' ), number_format_i18n( $records ) );
		}
		if ( Profile::teams( $user ) ) {
			$retained   = true;
			$messages[] = __( 'The person is still a member of organizations here, and their account is still there. Delete the user, or remove them from each organization, to end that.', 'packout-core' );
		}

		/**
		 * Filters the result of erasing a person's data. A plugin that keeps more removes it here and says so.
		 *
		 * @param array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} $result The result, in WordPress's form.
		 * @param string                                                                                     $email  The person's email address.
		 */
		return (array) apply_filters(
			'packout_privacy_erase',
			array(
				'items_removed'  => $removed || $spots > 0 || $records > 0,
				'items_retained' => $retained,
				'messages'       => $messages,
				'done'           => true,
			),
			(string) $email
		);
	}

	/**
	 * Make a record nobody's, without touching anything else about it.
	 *
	 * @param int $id The record.
	 */
	private static function unsign( int $id ): void {
		global $wpdb;
		// The row is changed where it is kept: saving the record again would send its mail and run its checks as though it had been edited.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One column of one row.
		$wpdb->update( $wpdb->posts, array( 'post_author' => 0 ), array( 'ID' => $id ), array( '%d' ), array( '%d' ) );
		clean_post_cache( $id );
	}
}
