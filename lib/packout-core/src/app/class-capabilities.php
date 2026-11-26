<?php
/**
 * Capabilities and the suggested roles.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability names, their labels, and the starting roles.
 */
class Capabilities {

	public const REPORT   = 'packout_report';
	public const MODERATE = 'packout_moderate';
	public const LEAD     = 'packout_lead';
	public const TEAM     = 'packout_team';
	public const IMPACT   = 'packout_impact';
	public const EXPORT   = 'packout_export';
	public const PARTNER  = 'packout_partner';

	/**
	 * Option that lists the capabilities this site has already been given.
	 */
	private const SEEN = 'packout_spot_capabilities';

	/**
	 * Opens the Pack-Out Spot menu. Nobody is granted it directly: a person
	 * holds it while they can review Spots, lead missions, or manage teams.
	 */
	public const SCREENS = 'packout_screens';

	/**
	 * Register the capability that opens the menu.
	 */
	public static function register(): void {
		add_filter( 'user_has_cap', array( self::class, 'screens' ), 10, 2 );
	}

	/**
	 * Grant the menu capability to people who have a screen to open.
	 *
	 * @param mixed $allcaps Capabilities the user holds.
	 * @param mixed $caps    Capabilities being checked.
	 * @return mixed
	 */
	public static function screens( $allcaps, $caps ) {
		if ( is_array( $allcaps ) && is_array( $caps ) && in_array( self::SCREENS, $caps, true ) ) {
			if ( ! empty( $allcaps[ self::MODERATE ] ) || ! empty( $allcaps[ self::LEAD ] ) || ! empty( $allcaps[ self::TEAM ] ) || ! empty( $allcaps[ self::PARTNER ] ) ) {
				$allcaps[ self::SCREENS ] = true;
			}
		}
		return $allcaps;
	}

	/**
	 * Capability names and what each one allows.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			self::REPORT   => __( 'Pack-Out Spot: report Spots and sync the field app', 'packout-core' ),
			self::MODERATE => __( 'Pack-Out Spot: review and publish Spots', 'packout-core' ),
			self::LEAD     => __( 'Pack-Out Spot: lead missions', 'packout-core' ),
			self::TEAM     => __( 'Pack-Out Spot: manage organizations', 'packout-core' ),
			self::IMPACT   => __( 'Pack-Out Spot: record and verify impact', 'packout-core' ),
			self::EXPORT   => __( 'Pack-Out Spot: export records', 'packout-core' ),
			self::PARTNER  => __( 'Pack-Out Spot: approve and manage partners', 'packout-core' ),
		);
	}

	/**
	 * Suggested roles and the capabilities each one starts with.
	 *
	 * @return array<string, array{label: string, caps: array<int, string>}>
	 */
	public static function roles(): array {
		return array(
			'pos_reporter'    => array(
				'label' => __( 'Reporter', 'packout-core' ),
				'caps'  => array( self::REPORT ),
			),
			'pos_crew'        => array(
				'label' => __( 'Crew', 'packout-core' ),
				'caps'  => array( self::REPORT ),
			),
			'pos_lead'        => array(
				'label' => __( 'Lead', 'packout-core' ),
				'caps'  => array( self::REPORT, self::LEAD, self::IMPACT ),
			),
			'pos_coordinator' => array(
				'label' => __( 'Coordinator', 'packout-core' ),
				'caps'  => array_keys( self::all() ),
			),
		);
	}

	/**
	 * Create missing roles and give administrators every capability.
	 *
	 * An existing role keeps the capabilities a site owner has set on it. A
	 * capability that is new in this version is the exception: nobody could
	 * have set it, so a role that starts with it is given it once.
	 */
	public static function install(): void {
		$stored = get_option( self::SEEN );
		// A site from before this list was kept has had the first six.
		$seen = is_array( $stored ) ? $stored : array( self::REPORT, self::MODERATE, self::LEAD, self::TEAM, self::IMPACT, self::EXPORT );
		$new  = array_diff( array_keys( self::all() ), $seen );
		foreach ( self::roles() as $slug => $role ) {
			$existing = get_role( $slug );
			if ( $existing instanceof \WP_Role ) {
				foreach ( array_intersect( $role['caps'], $new ) as $cap ) {
					$existing->add_cap( $cap );
				}
			}
		}
		update_option( self::SEEN, array_keys( self::all() ), false );

		// The plugin creates the roles that are missing and gives administrators every capability.
		App::kernel()->roles()->register( self::all(), self::roles() );
	}

	/**
	 * Whether a user holds a Pack-Out Spot capability.
	 *
	 * @param string $capability Capability name.
	 * @param int    $user_id    User ID. Negative uses the current user.
	 */
	public static function can( string $capability, int $user_id = -1 ): bool {
		if ( $user_id < 0 ) {
			$user_id = get_current_user_id();
		}
		return $user_id > 0 && user_can( $user_id, $capability );
	}
}
