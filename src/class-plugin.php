<?php
/**
 * Plugin bootstrap.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether the plugin may run on this site, then loads the shared
 * library, hands it the WordPress adapters, and registers the application.
 */
class Plugin {

	public const MINIMUM_PHP       = '8.2';
	public const MINIMUM_WORDPRESS = '6.8';

	/**
	 * The oldest version of the shared library this plugin works with.
	 */
	public const MINIMUM_CORE = '0.12.0';

	/**
	 * The hub's plugin. The two never run on one site.
	 */
	public const HUB_PLUGIN = 'packout-spot/packout-spot.php';

	/**
	 * Why the plugin is paused, when it is: one of the reasons `message()`
	 * knows.
	 *
	 * @var string
	 */
	private static $paused = '';

	/**
	 * The update client, once registered.
	 *
	 * @var \PackOut\Core\Updates|null
	 */
	private static $updates = null;

	/**
	 * Start the plugin, or note why it cannot start.
	 *
	 * This runs before WordPress is ready to translate, so a reason is kept
	 * as a word and turned into a sentence when it is shown.
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'load_textdomain' ) );

		self::$paused = self::obstacle( PHP_VERSION, get_bloginfo( 'version' ), function_exists( 'sodium_crypto_sign_verify_detached' ), defined( 'PACKOUT_SPOT_VERSION' ) );
		if ( '' === self::$paused ) {
			$library = dirname( __DIR__ ) . '/lib/packout-core/load.php';
			if ( ! is_readable( $library ) ) {
				self::$paused = 'library';
			} else {
				require_once $library;
				if ( ! defined( 'PACKOUT_CORE_VERSION' ) || version_compare( PACKOUT_CORE_VERSION, self::MINIMUM_CORE, '<' ) ) {
					self::$paused = 'library_old';
				}
			}
		}
		if ( '' !== self::$paused ) {
			add_action( 'admin_notices', array( self::class, 'paused_notice' ) );
			return;
		}

		foreach ( array( 'access', 'lists', 'report-form', 'roles', 'storage', 'screens', 'palette', 'icons', 'settings', 'mailer', 'site' ) as $adapter ) {
			require_once __DIR__ . '/adapters/class-' . $adapter . '.php';
		}

		$kernel = new \PackOut\Core\Kernel(
			array(
				'access'      => new Adapters\Access(),
				'lists'       => new Adapters\Lists(),
				'report_form' => new Adapters\Report_Form(),
				'roles'       => new Adapters\Roles(),
				'storage'     => new Adapters\Storage(),
				'screens'     => new Adapters\Screens(),
				'palette'     => new Adapters\Palette(),
				'icons'       => new Adapters\Icons(),
				'settings'    => new Adapters\Settings(),
				'mailer'      => new Adapters\Mailer(),
				'site'        => new Adapters\Site(),
			)
		);
		$kernel->register();

		// What a partner site has that the hub does not: its own report form, and addresses it chooses.
		Installer::register();
		Report_Form::register();
		Addresses::register();
		Site_Health::register();

		// The application, which the hub's plugin runs too.
		\PackOut\Core\App\App::register();

		\PackOut\Core\Capability_Map::handle_all( require __DIR__ . '/handlers.php' );

		self::$updates = new \PackOut\Core\Updates(
			array(
				'file'    => plugin_basename( PACKOUT_PARTNER_FILE ),
				'slug'    => 'packout-partner',
				'version' => PACKOUT_PARTNER_VERSION,
				'channel' => array( Settings::class, 'channel' ),
			)
		);
		self::$updates->register();

		Settings::register();
		if ( is_admin() ) {
			Settings_Screen::register();
		}
	}

	/**
	 * Load the plugin's translations.
	 */
	public static function load_textdomain(): void {
		// The library's own text domain, `packout-core`, is loaded by the library from the same directory.
		load_plugin_textdomain( 'packout-partner', false, dirname( plugin_basename( PACKOUT_PARTNER_FILE ) ) . '/languages' );
	}

	/**
	 * What stops the plugin from running here.
	 *
	 * @param string $php       The PHP version.
	 * @param string $wordpress The WordPress version.
	 * @param bool   $sodium    Whether PHP can check a signature.
	 * @param bool   $hub       Whether the hub's plugin is running.
	 * @return string `hub`, `php`, `wordpress`, `sodium`, or an empty string when nothing does.
	 */
	public static function obstacle( string $php, string $wordpress, bool $sodium, bool $hub ): string {
		if ( $hub ) {
			return 'hub';
		}
		if ( version_compare( $php, self::MINIMUM_PHP, '<' ) ) {
			return 'php';
		}
		// A development version, such as 7.2-alpha-1234, counts as its release.
		if ( version_compare( (string) preg_replace( '/[^0-9.].*$/', '', $wordpress ), self::MINIMUM_WORDPRESS, '<' ) ) {
			return 'WordPress';
		}
		if ( ! $sodium ) {
			return 'sodium';
		}
		return '';
	}

	/**
	 * A reason, as a sentence for an administrator.
	 *
	 * Call it once WordPress has reached `init`.
	 *
	 * @param string $reason    A reason from `obstacle()`, or `library` or `library_old`.
	 * @param string $php       The PHP version the site has.
	 * @param string $wordpress The WordPress version the site has.
	 */
	public static function message( string $reason, string $php = PHP_VERSION, string $wordpress = '' ): string {
		switch ( $reason ) {
			case 'hub':
				return __( 'Pack-Out Spot Partner is paused. This site runs Pack-Out Spot itself, and the two cannot run together.', 'packout-partner' );
			case 'php':
				/* translators: 1: the PHP version needed, 2: the PHP version the site has. */
				return sprintf( __( 'Pack-Out Spot Partner is paused. It needs PHP %1$s or later, and this site has %2$s.', 'packout-partner' ), self::MINIMUM_PHP, $php );
			case 'WordPress':
				/* translators: 1: the WordPress version needed, 2: the WordPress version the site has. */
				return sprintf( __( 'Pack-Out Spot Partner is paused. It needs WordPress %1$s or later, and this site has %2$s.', 'packout-partner' ), self::MINIMUM_WORDPRESS, '' !== $wordpress ? $wordpress : get_bloginfo( 'version' ) );
			case 'sodium':
				return __( 'Pack-Out Spot Partner is paused. PHP on this site has no sodium extension, so the plugin could not check that an update is genuine.', 'packout-partner' );
			case 'library':
				return __( 'Pack-Out Spot Partner is paused. Part of the plugin is missing. Install the plugin again.', 'packout-partner' );
			case 'library_old':
				return __( 'Pack-Out Spot Partner is paused. Another plugin loaded an older copy of the Pack-Out Spot library.', 'packout-partner' );
		}
		return '';
	}

	/**
	 * Why the plugin is paused, or an empty string when it is running.
	 *
	 * @return string A reason `message()` knows.
	 */
	public static function paused(): string {
		return self::$paused;
	}

	/**
	 * The update client, or null when the plugin is paused.
	 */
	public static function updates(): ?\PackOut\Core\Updates {
		return self::$updates;
	}

	/**
	 * Tell an administrator why the plugin is paused.
	 */
	public static function paused_notice(): void {
		if ( '' === self::$paused || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html( self::message( self::$paused ) ) . '</p></div>';
	}
}
