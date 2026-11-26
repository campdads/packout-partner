<?php
/**
 * Phase 26 acceptance checks: choosable options as taxonomies.
 *
 * Seeds exist, a protected delete is refused, an added category is what the
 * report payload offers, an added hazard does not block, the public
 * organization list filters by group type, and a partner listing slug the
 * hub has never had becomes a term.
 *
 * Run against a site where the plugin is active:
 *
 *   wp eval-file tests/core/phase-26.php
 *
 * The script creates its own users, terms, and records and removes them. It
 * exits non-zero when a check fails.
 *
 * @package PackOut_Core
 */

use PackOut\Core\App\App;
use PackOut\Core\App\Blocks;
use PackOut\Core\App\Capabilities;
use PackOut\Core\App\Field_App;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Teams;
use PackOut\Core\App\Vocabularies;
use PackOut\Spot\Partner_Listings;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

$hub  = App::is_hub();
$skip = static function ( string $what ): void {
	WP_CLI::log( '  --  ' . $what );
};

$packout_failures = array();
$packout_passed   = 0;
$packout_posts    = array();
$packout_users    = array();
$packout_terms    = array();
$packout_run      = strtolower( wp_generate_password( 6, false ) );

$check = static function ( string $label, bool $ok, $detail = '' ) use ( &$packout_failures, &$packout_passed ): void {
	if ( $ok ) {
		++$packout_passed;
		WP_CLI::log( '  ok    ' . $label );
		return;
	}
	$packout_failures[] = $label;
	WP_CLI::log( '  FAIL  ' . $label . ( '' !== $detail ? ' — ' . ( is_scalar( $detail ) ? $detail : wp_json_encode( $detail ) ) : '' ) );
};

$as = static function ( int $user_id ): void {
	wp_set_current_user( $user_id );
};

$user = static function ( string $role, string $name ) use ( &$packout_users, $packout_run ): int {
	$login           = 'pos-test-' . sanitize_key( $name ) . '-' . $packout_run;
	$id              = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => $login . '@example.test',
			'display_name' => $name . ' Vocab',
			'role'         => $role,
		)
	);
	$packout_users[] = $id;
	return (int) $id;
};

$remember_term = static function ( string $taxonomy, string $slug ) use ( &$packout_terms ): void {
	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( $term instanceof WP_Term ) {
		$packout_terms[] = array( $taxonomy, (int) $term->term_id );
	}
};

try {
	Capabilities::install();
	Schema::register();
	Vocabularies::install();

	WP_CLI::log( 'Seeds' );
	$shared = array(
		Vocabularies::CATEGORY,
		Vocabularies::STAGE,
		Vocabularies::HAZARD,
		Vocabularies::LOCATION_KIND,
		Vocabularies::PRECISION,
		Vocabularies::GROUP_TYPE,
		Vocabularies::PROGRAM_TYPE,
		Vocabularies::JOIN,
		Vocabularies::ORG_STATE,
		Vocabularies::PROGRAM_STATE,
		Vocabularies::MISSION_STATUS,
		Vocabularies::OUTCOME,
		Vocabularies::IMPACT_KIND,
		Vocabularies::IMPACT_SOURCE,
		Vocabularies::VERIFICATION,
		Vocabularies::OVERLAY,
	);
	foreach ( $shared as $taxonomy ) {
		$check( $taxonomy . ' is registered', taxonomy_exists( $taxonomy ) );
		$tax = get_taxonomy( $taxonomy );
		$check( $taxonomy . ' sits under Pack-Out Taxonomies', $tax instanceof WP_Taxonomy && Vocabularies::MENU === $tax->show_in_menu, $tax instanceof WP_Taxonomy ? $tax->show_in_menu : '' );
	}
	$check( 'the six category seeds are terms', isset( Schema::categories()['cleanup-waste'], Schema::categories()['waterways'] ) && 6 <= count( Schema::categories() ), Schema::categories() );
	$check( 'the six stage seeds are terms', isset( Schema::statuses()['spotted'], Schema::statuses()['cleaned'] ) && 6 <= count( Schema::statuses() ), Schema::statuses() );
	$check( 'the hazard seeds are terms', isset( Schema::hazards()['none'], Schema::hazards()['do_not_touch'], Schema::hazards()['needs_authorization'] ), Schema::hazards() );
	$check( 'group types include Volunteer Organizations', isset( Vocabularies::labels( Vocabularies::GROUP_TYPE )['volunteer-organizations'] ), Vocabularies::labels( Vocabularies::GROUP_TYPE ) );
	if ( $hub ) {
		$check( 'the hub registers Place kinds and map features', taxonomy_exists( Vocabularies::PLACE_KIND ) && taxonomy_exists( Vocabularies::FEATURE_KIND ) && taxonomy_exists( Vocabularies::PARTNER_STATE ) );
		$place = get_taxonomy( Vocabularies::PLACE_KIND );
		$check( 'hub taxonomies sit under Pack-Out Taxonomies', $place instanceof WP_Taxonomy && Vocabularies::MENU === $place->show_in_menu, $place instanceof WP_Taxonomy ? $place->show_in_menu : '' );
	} else {
		$skip( 'Place kinds, map features, and partner states are the hub\'s.' );
	}

	WP_CLI::log( 'Protected delete' );
	$stage = get_term_by( 'slug', 'spotted', Vocabularies::STAGE );
	$kept  = false;
	try {
		wp_delete_term( (int) $stage->term_id, Vocabularies::STAGE );
	} catch ( RuntimeException $e ) {
		$kept = 'packout_protected_term' === $e->getMessage();
	}
	$still = get_term_by( 'slug', 'spotted', Vocabularies::STAGE );
	$check( 'deleting a protected stage is refused and the term stays', $kept && $still instanceof WP_Term && (int) $still->term_id === (int) $stage->term_id );

	WP_CLI::log( 'Added terms' );
	$category_slug = 'phase-26-category-' . $packout_run;
	$hazard_slug   = 'phase-26-hazard-' . $packout_run;
	$category      = wp_insert_term( 'Phase 26 litter', Vocabularies::CATEGORY, array( 'slug' => $category_slug ) );
	$hazard        = wp_insert_term( 'Loose wire', Vocabularies::HAZARD, array( 'slug' => $hazard_slug ) );
	$check( 'an added category is a term', ! is_wp_error( $category ), is_wp_error( $category ) ? $category->get_error_message() : '' );
	$check( 'an added hazard is a term', ! is_wp_error( $hazard ), is_wp_error( $hazard ) ? $hazard->get_error_message() : '' );
	$remember_term( Vocabularies::CATEGORY, $category_slug );
	$remember_term( Vocabularies::HAZARD, $hazard_slug );

	$payload = Field_App::config();
	$check( 'the field app offers the added category', isset( $payload['categories'][ $category_slug ] ) && 'Phase 26 litter' === $payload['categories'][ $category_slug ], $payload['categories'] ?? array() );
	$map = Blocks::map_config();
	$check( 'the map offers the added category', isset( $map['categories'][ $category_slug ] ), $map['categories'] ?? array() );
	$check( 'the field app offers the added hazard', isset( $payload['hazards'][ $hazard_slug ] ), $payload['hazards'] ?? array() );

	$coordinator = $user( 'pos_coordinator', 'Coordinator' );
	$lead        = $user( 'pos_lead', 'Lead' );
	$reporter    = $user( 'pos_reporter', 'Reporter' );
	$as( $reporter );
	$spot = Spots::create(
		array(
			'category'    => $category_slug,
			'description' => 'A pile of Phase 26 litter ' . $packout_run,
			'hazard'      => $hazard_slug,
			'lat'         => 34.61,
			'lng'         => -111.8,
		),
		$reporter
	);
	$check( 'a Spot can be reported in the added category with the added hazard', is_int( $spot ) && $spot > 0, is_wp_error( $spot ) ? $spot->get_error_message() : $spot );
	if ( is_int( $spot ) && $spot > 0 ) {
		$packout_posts[] = $spot;
		$check( 'the added hazard does not block the Spot', ! Spots::blocked( $spot ) && Spots::field( $spot, 'hazard', 'none' ) === $hazard_slug, Spots::field( $spot, 'hazard', 'none' ) );
	}

	WP_CLI::log( 'Organization list' );
	$as( $coordinator );
	$volunteers = Teams::save(
		0,
		array(
			'name'        => 'Volunteer crew ' . $packout_run,
			'description' => 'Volunteer Organizations listed for the filter.',
			'lead'        => $lead,
			'listed'      => true,
			'group_type'  => 'volunteer-organizations',
		)
	);
	$companies  = Teams::save(
		0,
		array(
			'name'        => 'Company crew ' . $packout_run,
			'description' => 'Corporations listed for the filter.',
			'lead'        => $lead,
			'listed'      => true,
			'group_type'  => 'corporations',
		)
	);
	$check( 'two listed organizations can be given group types', is_int( $volunteers ) && is_int( $companies ), array( $volunteers, $companies ) );
	if ( is_int( $volunteers ) ) {
		$packout_posts[] = $volunteers;
	}
	if ( is_int( $companies ) ) {
		$packout_posts[] = $companies;
	}
	$_GET['group_type'] = 'volunteer-organizations';
	$filtered           = Blocks::render_teams( array() );
	$_GET               = array();
	$check(
		'Volunteer Organizations shows those organizations and no others',
		is_int( $volunteers ) && is_int( $companies ) && false !== strpos( $filtered, 'Volunteer crew ' . $packout_run ) && false === strpos( $filtered, 'Company crew ' . $packout_run ),
		$filtered
	);
	$blank = Blocks::render_teams( array() );
	$check( 'the unfiltered list still names both', false !== strpos( $blank, 'Volunteer crew ' . $packout_run ) && false !== strpos( $blank, 'Company crew ' . $packout_run ) );
	$check( 'a type with no listed organization is hidden on the public list', false === strpos( $blank, 'Law Enforcement' ) && false !== strpos( $blank, 'Volunteer Organizations' ) && false !== strpos( $blank, 'Corporations' ) );

	if ( $hub && class_exists( Partner_Listings::class ) ) {
		WP_CLI::log( 'Partner listing' );
		$listing_slug = 'phase-26-' . $packout_run;
		$partner      = wp_insert_post(
			array(
				'post_type'   => Schema::PARTNER,
				'post_status' => 'publish',
				'post_title'  => 'Vocab partner ' . $packout_run,
			),
			true
		);
		$check( 'a partner record can be opened for a listing', is_int( $partner ) && $partner > 0, is_wp_error( $partner ) ? $partner->get_error_message() : $partner );
		if ( is_int( $partner ) && $partner > 0 ) {
			$packout_posts[] = $partner;
			update_post_meta( $partner, '_pos_state', 'active' );
			$results  = Partner_Listings::apply(
				$partner,
				'vocab.example.test',
				array(
					'items' => array(
						array(
							'kind'        => 'organization',
							'remote_id'   => '9001',
							'rev'         => 1,
							'updated'     => gmdate( 'c' ),
							'name'        => 'Listed cities ' . $packout_run,
							'description' => 'A partner listing with a group type the hub has never had.',
							'region'      => 'Yavapai County',
							'website'     => '',
							'join'        => 'request',
							'url'         => 'https://vocab.example.test/team/crew/',
							'teams'       => array(),
							'group_type'  => array(
								'slug' => $listing_slug,
								'name' => 'Phase 26 Cities',
							),
						),
					),
				)
			);
			$map_rows = Partner_Listings::map( $partner );
			$listed   = (int) ( $map_rows['organization:9001'] ?? 0 );
			if ( $listed > 0 ) {
				$packout_posts[] = $listed;
			}
			$term = get_term_by( 'slug', $listing_slug, Vocabularies::GROUP_TYPE );
			$remember_term( Vocabularies::GROUP_TYPE, $listing_slug );
			$check( 'a partner listing slug the hub has never had becomes a term', $term instanceof WP_Term && 'Phase 26 Cities' === $term->name, $results );
			$check( 'that term is then a filter', isset( Vocabularies::labels( Vocabularies::GROUP_TYPE )[ $listing_slug ] ) );
			if ( $listed > 0 ) {
				$check( 'the listing is assigned the new group type', Vocabularies::slug( $listed, Vocabularies::GROUP_TYPE ) === $listing_slug );
			}
		}
	} else {
		$skip( 'A partner listing becoming a term is checked on packout.spot.' );
	}
} finally {
	wp_set_current_user( 0 );
	$_GET = array();
	foreach ( array_reverse( array_filter( $packout_posts ) ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	foreach ( $packout_terms as $row ) {
		wp_delete_term( $row[1], $row[0] );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $packout_users as $id ) {
		is_multisite() ? wpmu_delete_user( $id ) : wp_delete_user( $id );
	}
}

WP_CLI::log( '' );
if ( $packout_failures ) {
	WP_CLI::error( count( $packout_failures ) . ' failed, ' . $packout_passed . ' passed.' );
}
WP_CLI::success( $packout_passed . ' checks passed.' );
