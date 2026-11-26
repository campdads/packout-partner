<?php
/**
 * Post types, taxonomy, and the fixed vocabularies.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The product schema is registered in code so a deploy does not depend on
 * someone recreating post types in the admin.
 */
class Schema {

	public const SPOT     = 'pos_spot';
	public const MISSION  = 'pos_mission';
	public const TEAM     = 'pos_team';
	public const IMPACT   = 'pos_impact';
	public const CAMPAIGN = 'pos_campaign';
	public const PLACE    = 'pos_place';
	public const FEATURE  = 'pos_feature';
	public const PARTNER  = \PackOut\Core\Partner::POST_TYPE;
	public const PROGRAM  = \PackOut\Core\Program::POST_TYPE;
	public const CATEGORY = 'pos_category';

	/**
	 * A Spot a partner's site sent. The hub only: see Remote_Spots.
	 */
	public const PARTNER_SPOT = 'pos_partner_spot';

	/**
	 * Meta keys stored on a Spot.
	 */
	public const META = array(
		'_pos_status',
		'_pos_location_kind',
		'_pos_lat',
		'_pos_lng',
		'_pos_accuracy',
		'_pos_landmark',
		'_pos_area',
		'_pos_client_id',
		'_pos_hazard',
		'_pos_precision',
		'_pos_entry_id',
		'_pos_return_reason',
		'_pos_duplicate_of',
		'_pos_revision',
		'_pos_client_revision',
		'_pos_title_custom',
		'_pos_cleaned_by',
		'_pos_cleaned_at',
		'_pos_cleaned_mission',
		'_pos_returned_at',
		'_pos_place',
		'_pos_clear_by',
		'_pos_clear_on',
		'_pos_clear_ref',
		'_pos_clear_scope',
		'_pos_clear_public',
		'_pos_pin_source',
		'_pos_claim_by',
		'_pos_claim_email',
		'_pos_claim_name',
		'_pos_claim_note',
		'_pos_claim_at',
		'_pos_claim_photo',
		'_pos_claim_from',
		'_pos_claim_named',
		'_pos_credit_team',
		'_pos_credit_named',
		'_pos_reporter_name',
		'_pos_photos_public',
		'_pos_clearance_since',
		'_pos_unrecorded_since',
	);

	/**
	 * Meta keys stored on a mission. Lists are JSON text.
	 */
	public const MISSION_META = array(
		'_pos_team',
		'_pos_spot',
		'_pos_spot_on',
		'_pos_synced_at',
		'_pos_moved',
		'_pos_status',
		'_pos_objective',
		'_pos_start',
		'_pos_end',
		'_pos_start_time',
		'_pos_end_time',
		'_pos_timezone',
		'_pos_meeting_note',
		'_pos_meeting_share',
		'_pos_meeting_lat',
		'_pos_meeting_lng',
		'_pos_gear',
		'_pos_checklist',
		'_pos_outcomes',
		'_pos_proposals',
		'_pos_revision',
		'_pos_completed_at',
		'_pos_campaign',
		'_pos_subteams',
		'_pos_sponsors',
		'_pos_description',
		'_pos_cover',
		'_pos_signup',
		'_pos_program',
	);

	/**
	 * Meta keys stored on a team. The organization id is not written by this
	 * code: a site's relationships plugin writes it, where the site has one.
	 */
	public const TEAM_META = array(
		'_pos_lead',
		'_pos_member',
		'_pos_org_id',
		'_pos_state',
		'_pos_join',
		'_pos_request',
		'_pos_region',
		'_pos_website',
		'_pos_subteams',
		'_pos_resources',
		'_pos_sponsors',
		'_pos_seq',
		'_pos_logo',
		'_pos_member_plans',
		'_pos_signup_requests',
		'_pos_cover',
		'_pos_show_leads',
		'_pos_partner',
		'_pos_partner_request',
	);

	/**
	 * Meta keys stored on a partner. Lists are JSON text.
	 */
	public const PARTNER_META = array(
		'_pos_lead',
		'_pos_colead',
		'_pos_state',
		'_pos_origin',
		'_pos_region',
		'_pos_website',
		'_pos_resources',
		'_pos_sponsors',
		'_pos_seq',
		'_pos_logo',
		'_pos_cover',
		'_pos_show_leads',
		'_pos_listed_before',
		'_pos_heard',
	);

	/**
	 * Meta keys stored on a program. Lists are JSON text.
	 */
	public const PROGRAM_META = array(
		'_pos_partner',
		'_pos_team',
		'_pos_goal',
		'_pos_start',
		'_pos_end',
		'_pos_orgs',
		'_pos_subteams',
		'_pos_sponsors',
		'_pos_cover',
		'_pos_state',
	);

	/**
	 * Meta keys stored on a campaign. Lists are JSON text.
	 */
	public const CAMPAIGN_META = array(
		'_pos_team',
		'_pos_goal',
		'_pos_start',
		'_pos_end',
		'_pos_start_time',
		'_pos_end_time',
		'_pos_timezone',
		'_pos_subteams',
		'_pos_sponsors',
		'_pos_cover',
		'_pos_signup',
		'_pos_program',
	);

	/**
	 * Meta keys stored on a Place. The outline and the sponsors are JSON text.
	 * The organization id is not written by this code: a site's relationships plugin writes it, where the site has one.
	 */
	public const PLACE_META = array(
		'_pos_kind',
		'_pos_shape',
		'_pos_team',
		'_pos_adopted_on',
		'_pos_adoption_note',
		'_pos_adoption_requests',
		'_pos_sponsors',
		'_pos_seq',
		'_pos_org_id',
	);

	/**
	 * Meta keys stored on a map feature. Geometry is JSON text.
	 */
	public const FEATURE_META = array(
		'_pos_kind',
		'_pos_geometry',
		'_pos_color',
		'_pos_icon',
		'_pos_place',
		'_pos_sponsors',
		'_pos_seq',
	);

	/**
	 * Meta keys stored on an impact record.
	 */
	public const IMPACT_META = array(
		'_pos_spot',
		'_pos_mission',
		'_pos_team',
		'_pos_user',
		'_pos_kind',
		'_pos_cycle',
		'_pos_date',
		'_pos_hours',
		'_pos_people',
		'_pos_person',
		'_pos_bags',
		'_pos_bags_source',
		'_pos_pounds',
		'_pos_pounds_source',
		'_pos_return',
		'_pos_return_note',
		'_pos_verification',
		'_pos_verified_by',
		'_pos_verified_at',
		'_pos_unverified_at',
		'_pos_client_id',
		'_pos_named',
	);

	/**
	 * Register the schema hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'types' ), 9 );
		add_action( 'template_redirect', array( self::class, 'single_pages_only' ), 0 );
		add_filter( 'oembed_response_data', array( self::class, 'without_embed_author' ), 10, 2 );
		// The fields of each record, for a site that keeps them where they can be searched quickly.
		foreach ( self::record_meta() as $type => $keys ) {
			App::kernel()->storage()->columns( $type, $keys );
		}
	}

	/**
	 * Domain statuses and their labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			'spotted'     => __( 'Spotted', 'packout-core' ),
			'planned'     => __( 'Planned', 'packout-core' ),
			'in_progress' => __( 'In progress', 'packout-core' ),
			// Someone said they cleaned it. No photo or coordinator has confirmed that yet.
			'pending'     => __( 'Cleanup reported', 'packout-core' ),
			'cleaned'     => __( 'Cleaned', 'packout-core' ),
			'watch'       => __( 'Watch', 'packout-core' ),
		);
	}

	/**
	 * Hazard flags and their labels.
	 *
	 * @return array<string, string>
	 */
	public static function hazards(): array {
		return array(
			'none'                => __( 'No hazard noted', 'packout-core' ),
			'do_not_touch'        => __( 'Do not touch', 'packout-core' ),
			'needs_authorization' => __( 'Needs authorization', 'packout-core' ),
		);
	}

	/**
	 * Location kinds and their labels.
	 *
	 * @return array<string, string>
	 */
	public static function location_kinds(): array {
		return array(
			'pin'         => __( 'Pin', 'packout-core' ),
			'area'        => __( 'Area', 'packout-core' ),
			'landmark'    => __( 'Landmark', 'packout-core' ),
			'description' => __( 'Description only', 'packout-core' ),
		);
	}

	/**
	 * Category terms that exist from the first release.
	 *
	 * @return array<string, string>
	 */
	public static function categories(): array {
		return array(
			'cleanup-waste'             => __( 'Cleanup and waste', 'packout-core' ),
			'campsites-recreation'      => __( 'Campsites and recreation', 'packout-core' ),
			'trails-access'             => __( 'Trails and access', 'packout-core' ),
			'land-habitat'              => __( 'Land and habitat', 'packout-core' ),
			'waterways'                 => __( 'Waterways', 'packout-core' ),
			'facilities-infrastructure' => __( 'Facilities and infrastructure', 'packout-core' ),
		);
	}

	/**
	 * The word for finished work, by category.
	 *
	 * The stored Spot status is `cleaned` in every case.
	 *
	 * @param string $category Category slug.
	 */
	public static function done_word( string $category ): string {
		$words = self::done_words();
		return $words[ $category ] ?? $words['cleanup-waste'];
	}

	/**
	 * The words for finished work, by category slug.
	 *
	 * @return array<string, string>
	 */
	public static function done_words(): array {
		return array(
			'cleanup-waste'             => __( 'Cleaned', 'packout-core' ),
			'campsites-recreation'      => __( 'Cleaned', 'packout-core' ),
			'trails-access'             => __( 'Cleared', 'packout-core' ),
			'land-habitat'              => __( 'Restored', 'packout-core' ),
			'waterways'                 => __( 'Cleared', 'packout-core' ),
			'facilities-infrastructure' => __( 'Repaired', 'packout-core' ),
		);
	}

	/**
	 * What the public is told about a hazard flag.
	 *
	 * @param string $hazard Hazard flag.
	 */
	public static function hazard_guidance( string $hazard ): string {
		if ( 'do_not_touch' === $hazard ) {
			return __( 'Leave this material where it is. Do not move, open, or pack it out. A coordinator will decide what happens next. If anyone is in danger, call 911 or the local land management office.', 'packout-core' );
		}
		if ( 'needs_authorization' === $hazard ) {
			return __( 'This work needs permission from the land manager before anyone starts. Leave it in place and let a coordinator arrange it.', 'packout-core' );
		}
		return '';
	}

	/**
	 * Register the post types and the category taxonomy.
	 *
	 * The plugin screens are the interface, so the editing UI stays off. A
	 * published Spot, a listed team, and a public mission keep a public address.
	 */
	public static function types(): void {
		register_post_type(
			self::SPOT,
			array(
				'labels'              => array(
					'name'          => __( 'Spots', 'packout-core' ),
					'singular_name' => __( 'Spot', 'packout-core' ),
				),
				'public'              => true,
				'show_ui'             => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => array(
					'slug'       => App::base( 'spot' ),
					'with_front' => false,
				),
				'supports'            => array( 'title', 'editor', 'author' ),
				// Deleting an account never deletes a record: a place's history is not one person's.
				'delete_with_user'    => false,
				'capability_type'     => array( 'pos_spot', 'pos_spots' ),
				'map_meta_cap'        => true,
				'capabilities'        => array(
					'create_posts'           => Capabilities::REPORT,
					'edit_posts'             => Capabilities::REPORT,
					'read_private_posts'     => Capabilities::REPORT,
					'edit_others_posts'      => Capabilities::MODERATE,
					'edit_private_posts'     => Capabilities::MODERATE,
					'edit_published_posts'   => Capabilities::MODERATE,
					'publish_posts'          => Capabilities::MODERATE,
					'delete_posts'           => Capabilities::MODERATE,
					'delete_others_posts'    => Capabilities::MODERATE,
					'delete_private_posts'   => Capabilities::MODERATE,
					'delete_published_posts' => Capabilities::MODERATE,
				),
			)
		);

		// A listed team has a public page with its description and public missions.
		register_post_type(
			self::TEAM,
			array(
				'labels'              => array(
					'name'          => __( 'Organizations', 'packout-core' ),
					'singular_name' => __( 'Organization', 'packout-core' ),
				),
				'public'              => true,
				'show_ui'             => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => array(
					'slug'       => App::base( 'team' ),
					'with_front' => false,
				),
				'supports'            => array( 'title', 'editor', 'author' ),
				// Deleting an account never deletes a record: a place's history is not one person's.
				'delete_with_user'    => false,
				'capability_type'     => array( 'pos_team', 'pos_teams' ),
				'map_meta_cap'        => true,
				'capabilities'        => self::record_capabilities( Capabilities::TEAM ),
			)
		);

		// A mission the lead marked public has a page with its title, dates, team, and Spot count.
		register_post_type(
			self::MISSION,
			array(
				'labels'              => array(
					'name'          => __( 'Missions', 'packout-core' ),
					'singular_name' => __( 'Mission', 'packout-core' ),
				),
				'public'              => true,
				'show_ui'             => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => array(
					'slug'       => App::base( 'mission' ),
					'with_front' => false,
				),
				'supports'            => array( 'title', 'author' ),
				// Deleting an account never deletes a record: a place's history is not one person's.
				'delete_with_user'    => false,
				'capability_type'     => array( 'pos_mission', 'pos_missions' ),
				'map_meta_cap'        => true,
				'capabilities'        => self::record_capabilities( Capabilities::LEAD ),
			)
		);

		// A campaign the lead marked public has a page with its goal, its missions, and its sponsors.
		register_post_type(
			self::CAMPAIGN,
			array(
				'labels'              => array(
					'name'          => __( 'Campaigns', 'packout-core' ),
					'singular_name' => __( 'Campaign', 'packout-core' ),
				),
				'public'              => true,
				'show_ui'             => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => array(
					'slug'       => App::base( 'campaign' ),
					'with_front' => false,
				),
				'supports'            => array( 'title', 'editor', 'author' ),
				// Deleting an account never deletes a record: a place's history is not one person's.
				'delete_with_user'    => false,
				'capability_type'     => array( 'pos_campaign', 'pos_campaigns' ),
				'map_meta_cap'        => true,
				'capabilities'        => self::record_capabilities( Capabilities::LEAD ),
			)
		);

		// A listed, active partner has a page with its organizations, its programs, and what they add up to.
		register_post_type(
			self::PARTNER,
			array(
				'labels'              => array(
					'name'          => __( 'Partners', 'packout-core' ),
					'singular_name' => __( 'Partner', 'packout-core' ),
				),
				'public'              => true,
				'show_ui'             => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => array(
					'slug'       => App::base( 'partner' ),
					'with_front' => false,
				),
				'supports'            => array( 'title', 'editor', 'author' ),
				// Deleting an account never deletes a record: a place's history is not one person's.
				'delete_with_user'    => false,
				'capability_type'     => array( 'pos_partner', 'pos_partners' ),
				'map_meta_cap'        => true,
				'capabilities'        => self::record_capabilities( Capabilities::PARTNER ),
			)
		);

		// A program its owner marked public has a page with its goal, who takes part, and its campaigns and missions.
		register_post_type(
			self::PROGRAM,
			array(
				'labels'              => array(
					'name'          => __( 'Programs', 'packout-core' ),
					'singular_name' => __( 'Program', 'packout-core' ),
				),
				'public'              => true,
				'show_ui'             => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => array(
					'slug'       => App::base( 'program' ),
					'with_front' => false,
				),
				'supports'            => array( 'title', 'editor', 'author' ),
				// Deleting an account never deletes a record: a place's history is not one person's.
				'delete_with_user'    => false,
				'capability_type'     => array( 'pos_program', 'pos_programs' ),
				'map_meta_cap'        => true,
				'capabilities'        => self::record_capabilities( Capabilities::LEAD ),
			)
		);

		// A listed Place has a page with its outline, its adopting team, its Spots, and their history.
		// Places and map features are the hub's. A partner site keeps none of its own, so neither has a page there.
		$hub = App::is_hub();
		register_post_type(
			self::PLACE,
			array(
				'labels'              => array(
					'name'          => __( 'Places', 'packout-core' ),
					'singular_name' => __( 'Place', 'packout-core' ),
				),
				'public'              => $hub,
				'show_ui'             => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => ! $hub ? false : array(
					'slug'       => App::base( 'place' ),
					'with_front' => false,
				),
				'supports'            => array( 'title', 'editor', 'author' ),
				// Deleting an account never deletes a record: a place's history is not one person's.
				'delete_with_user'    => false,
				'capability_type'     => array( 'pos_place', 'pos_places' ),
				'map_meta_cap'        => true,
				'capabilities'        => self::record_capabilities( Capabilities::MODERATE ),
			)
		);

		// A map feature is public on the map and has a page of its own, like a Spot.
		register_post_type(
			self::FEATURE,
			array(
				'labels'              => array(
					'name'          => __( 'Map features', 'packout-core' ),
					'singular_name' => __( 'Map feature', 'packout-core' ),
				),
				'public'              => $hub,
				'show_ui'             => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => ! $hub ? false : array(
					'slug'       => App::base( 'feature' ),
					'with_front' => false,
				),
				'supports'            => array( 'title', 'editor', 'author' ),
				// Deleting an account never deletes a record: a place's history is not one person's.
				'delete_with_user'    => false,
				'capability_type'     => array( 'pos_feature', 'pos_features' ),
				'map_meta_cap'        => true,
				'capabilities'        => array(
					'create_posts'           => Capabilities::REPORT,
					'edit_posts'             => Capabilities::REPORT,
					'read_private_posts'     => Capabilities::REPORT,
					'edit_others_posts'      => Capabilities::MODERATE,
					'edit_private_posts'     => Capabilities::MODERATE,
					'edit_published_posts'   => Capabilities::REPORT,
					'publish_posts'          => Capabilities::REPORT,
					'delete_posts'           => Capabilities::REPORT,
					'delete_others_posts'    => Capabilities::MODERATE,
					'delete_private_posts'   => Capabilities::MODERATE,
					'delete_published_posts' => Capabilities::REPORT,
				),
			)
		);

		// An impact record has no page of its own. "publish" means a coordinator counts it in the public totals.
		register_post_type(
			self::IMPACT,
			array(
				'labels'              => array(
					'name'          => __( 'Impact records', 'packout-core' ),
					'singular_name' => __( 'Impact record', 'packout-core' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'supports'            => array( 'title', 'editor', 'author' ),
				// Deleting an account never deletes a record: a place's history is not one person's.
				'delete_with_user'    => false,
				'capability_type'     => array( 'pos_impact', 'pos_impacts' ),
				'map_meta_cap'        => true,
				'capabilities'        => self::record_capabilities( Capabilities::IMPACT ),
			)
		);

		register_taxonomy(
			self::CATEGORY,
			self::SPOT,
			array(
				'labels'            => array(
					'name'          => __( 'Stewardship categories', 'packout-core' ),
					'singular_name' => __( 'Stewardship category', 'packout-core' ),
				),
				'public'            => false,
				'show_ui'           => false,
				'show_in_rest'      => false,
				'hierarchical'      => false,
				'show_admin_column' => false,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Post type capabilities for a record that one capability governs.
	 *
	 * Crews read private records through the plugin, which checks membership.
	 *
	 * @param string $capability Capability that creates and edits the record.
	 * @return array<string, string>
	 */
	private static function record_capabilities( string $capability ): array {
		$caps = array();
		foreach ( array( 'create_posts', 'edit_posts', 'edit_others_posts', 'edit_private_posts', 'edit_published_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_private_posts', 'delete_published_posts' ) as $name ) {
			$caps[ $name ] = $capability;
		}
		return $caps;
	}

	/**
	 * Create the category terms that are missing.
	 */
	public static function install_terms(): void {
		if ( ! taxonomy_exists( self::CATEGORY ) ) {
			self::types();
		}
		foreach ( self::categories() as $slug => $name ) {
			if ( ! term_exists( $slug, self::CATEGORY ) ) {
				wp_insert_term( $name, self::CATEGORY, array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * The fields of each record, by record type.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function record_meta(): array {
		return array(
			self::SPOT     => self::META,
			self::MISSION  => self::MISSION_META,
			self::TEAM     => self::TEAM_META,
			self::IMPACT   => self::IMPACT_META,
			self::CAMPAIGN => self::CAMPAIGN_META,
			self::PLACE    => self::PLACE_META,
			self::FEATURE  => self::FEATURE_META,
			self::PARTNER  => self::PARTNER_META,
			self::PROGRAM  => self::PROGRAM_META,
		);
	}

	/**
	 * Whether a post type is a record with a public page: a Spot, an organization, a mission, a campaign, a Place, a partner, or a program.
	 *
	 * @param string $post_type Post type.
	 */
	public static function is_record( string $post_type ): bool {
		return in_array( $post_type, array( self::SPOT, self::MISSION, self::TEAM, self::CAMPAIGN, self::PLACE, self::PARTNER, self::PROGRAM, self::PARTNER_SPOT ), true );
	}

	/**
	 * A list field of a record, stored as JSON so a site that keeps the fields in table columns holds plain text.
	 *
	 * @param int    $id  Post ID.
	 * @param string $key Field name without the prefix.
	 * @return array<int|string, mixed>
	 */
	public static function stored_list( int $id, string $key ): array {
		$raw  = get_post_meta( $id, '_pos_' . $key, true );
		$data = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Write a list field. An empty list removes it.
	 *
	 * @param int                      $id    Post ID.
	 * @param string                   $key   Field name without the prefix.
	 * @param array<int|string, mixed> $value List.
	 */
	public static function write_list( int $id, string $key, array $value ): void {
		if ( ! $value ) {
			delete_post_meta( $id, '_pos_' . $key );
			return;
		}
		update_post_meta( $id, '_pos_' . $key, wp_slash( (string) wp_json_encode( $value ) ) );
	}

	/**
	 * The next number for a sub-team, a sponsor, or a resource of a team, or for a sponsor of a Place.
	 *
	 * One counter serves all three, so a number is never used twice and a
	 * mission that named a removed sub-team cannot pick up a new one.
	 *
	 * @param int $team_id Team ID.
	 */
	public static function next_number( int $team_id ): int {
		$next = (int) get_post_meta( $team_id, '_pos_seq', true ) + 1;
		update_post_meta( $team_id, '_pos_seq', $next );
		return $next;
	}

	/**
	 * A calendar date, or an empty string.
	 *
	 * @param mixed $raw Y-m-d.
	 */
	public static function day( $raw ): string {
		$value = is_string( $raw ) ? trim( $raw ) : '';
		$parts = explode( '-', $value );
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ? $value : '';
	}

	/**
	 * A span of days in words, with its times when it has any.
	 *
	 * @param string                $start Y-m-d, or empty.
	 * @param string                $end   Y-m-d, or empty.
	 * @param array<string, string> $times `start_time`, `end_time`, and `timezone`, as stored. Without a start time the span is days alone.
	 */
	public static function span( string $start, string $end, array $times = array() ): string {
		if ( '' !== (string) ( $times['start_time'] ?? '' ) ) {
			$schedule = \PackOut\Core\Schedule::of(
				array(
					'start' => $start,
					'end'   => $end,
				) + $times,
				Schedule::site_zone()
			);
			if ( $schedule && ! $schedule['all_day'] ) {
				return Schedule::words( $schedule );
			}
		}
		$format = (string) get_option( 'date_format', 'F j, Y' );
		$show   = static fn( string $day ) => date_i18n( $format, (int) strtotime( $day . ' 12:00:00' ) );
		if ( '' === $start && '' === $end ) {
			return '';
		}
		if ( '' === $end || $end === $start ) {
			return $show( $start );
		}
		if ( '' === $start ) {
			return $show( $end );
		}
		return $show( $start ) . ' – ' . $show( $end );
	}

	/**
	 * Answer "not found" to a list or a feed of records.
	 *
	 * A record is public as its own page, which this plugin writes. WordPress
	 * would also list any public post type at `?post_type=` and in a feed, with
	 * the author's name on every item.
	 */
	public static function single_pages_only(): void {
		$asked = array_filter( (array) get_query_var( 'post_type' ), 'is_string' );
		if ( ! array_filter( $asked, array( self::class, 'is_record' ) ) || ( is_singular() && ! is_feed() ) ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		$wp_query->is_feed = false;
		// set_404() keeps the feed flag, and the feed would still be written.
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Keep the author out of the embed data of a public record.
	 *
	 * @param array<string, mixed> $data Embed data.
	 * @param \WP_Post             $post The post.
	 */
	public static function without_embed_author( $data, $post ) {
		if ( $post instanceof \WP_Post && self::is_record( $post->post_type ) ) {
			unset( $data['author_name'], $data['author_url'] );
		}
		return $data;
	}
}
