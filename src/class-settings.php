<?php
/**
 * The plugin's settings.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the settings, which are one option.
 */
class Settings {

	public const OPTION = 'packout_partner_settings';

	/**
	 * The kinds of record whose refused address was already reported in this request.
	 *
	 * @var array<string, bool>
	 */
	private static $told = array();

	/**
	 * The first part of each public path, unless the site chose another.
	 */
	public const BASES = array(
		'spot'     => 'spot',
		'team'     => 'team',
		'mission'  => 'mission',
		'campaign' => 'campaign',
		'program'  => 'program',
		'partner'  => 'partner',
		'field'    => 'field',
	);

	/**
	 * Make the option known to WordPress.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
		add_action( 'update_option_' . self::OPTION, array( self::class, 'changed' ), 10, 2 );
		add_action( 'add_option_' . self::OPTION, array( self::class, 'added' ), 10, 2 );
	}

	/**
	 * Register the option with its cleaner.
	 */
	public static function register_setting(): void {
		register_setting(
			'packout_partner',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize_saved' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * What every setting is before the site changes it.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'beta'        => false,
			'remove_data' => false,
			'bases'       => self::BASES,
		);
	}

	/**
	 * Every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION );
		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key      Setting name.
	 * @param mixed  $fallback Returned when there is no such setting.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Save settings. Names that are not given keep their value.
	 *
	 * @param array<string, mixed> $values Setting name to value.
	 */
	public static function update( array $values ): bool {
		$next = self::sanitize( array_merge( self::all(), $values ) );
		if ( self::all() === $next ) {
			return true;
		}
		return update_option( self::OPTION, $next, true );
	}

	/**
	 * Clean settings on their way in and out.
	 *
	 * @param mixed $values What was submitted or stored.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $values ): array {
		$values = is_array( $values ) ? $values : array();
		$clean  = self::defaults();

		$clean['beta']        = ! empty( $values['beta'] );
		$clean['remove_data'] = ! empty( $values['remove_data'] );

		$bases = isset( $values['bases'] ) && is_array( $values['bases'] ) ? $values['bases'] : array();
		foreach ( self::BASES as $kind => $default ) {
			$base                    = isset( $bases[ $kind ] ) && is_string( $bases[ $kind ] ) ? sanitize_title( $bases[ $kind ] ) : '';
			$clean['bases'][ $kind ] = '' !== $base ? $base : $default;
		}
		return $clean;
	}

	/**
	 * Clean settings on their way to the database.
	 *
	 * An address that something else on the site already uses is refused
	 * here: it keeps the value it had, and the Settings screen says why.
	 *
	 * @param mixed $values What was submitted.
	 * @return array<string, mixed>
	 */
	public static function sanitize_saved( $values ): array {
		$clean   = self::sanitize( $values );
		$checked = Addresses::check( $clean['bases'] );
		foreach ( $checked['problems'] as $kind => $problem ) {
			if ( isset( self::$told[ $kind ] ) || ! function_exists( 'add_settings_error' ) ) {
				continue;
			}
			self::$told[ $kind ] = true;
			add_settings_error( self::OPTION, 'packout_partner_base_' . $kind, $problem );
		}
		$clean['bases'] = $checked['bases'];
		return $clean;
	}

	/**
	 * The update channel this site takes: `stable` or `beta`.
	 */
	public static function channel(): string {
		return self::get( 'beta', false ) ? 'beta' : 'stable';
	}

	/**
	 * The first save. WordPress adds the option then, and does not say it
	 * was updated.
	 *
	 * @param mixed $option The option's name.
	 * @param mixed $value  The settings as saved.
	 */
	public static function added( $option, $value ): void {
		unset( $option );
		self::changed( self::defaults(), $value );
	}

	/**
	 * Ask the catalog again when the site changes its channel, and follow a change of address.
	 *
	 * @param mixed $before The settings before.
	 * @param mixed $after  The settings after.
	 */
	public static function changed( $before, $after ): void {
		$was = is_array( $before ) && ! empty( $before['beta'] );
		$is  = is_array( $after ) && ! empty( $after['beta'] );
		if ( $was !== $is && null !== Plugin::updates() ) {
			Plugin::updates()->forget();
			delete_site_transient( 'update_plugins' );
		}
		Addresses::changed(
			is_array( $before ) && isset( $before['bases'] ) && is_array( $before['bases'] ) ? $before['bases'] : self::BASES,
			is_array( $after ) && isset( $after['bases'] ) && is_array( $after['bases'] ) ? $after['bases'] : self::BASES
		);
	}
}
