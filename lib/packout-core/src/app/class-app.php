<?php
/**
 * The application, as a plugin starts it.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

use PackOut\Core\Kernel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the parts of the application both plugins run, and installs what
 * they need on a site.
 *
 * A plugin builds a `PackOut\Core\Kernel` with its adapters and registers it
 * first. The application asks the kernel for everything it cannot do with
 * WordPress alone.
 */
class App {

	/**
	 * Register the application's hooks.
	 *
	 * What only the hub has is registered by the hub's plugin afterwards.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'load_textdomain' ), 1 );
		Capabilities::register();
		Schema::register();
		Teams::register();
		Team_Page::register();
		Remote::register();
		Remote_Spots::register();
		Connection::register();
		Outbox::register();
		Exchange::register();
		Adoptions::register();
		Joint::register();
		Hub_Layers::register();
		Partners::register();
		Programs::register();
		Partner_Page::register();
		Missions::register();
		Campaigns::register();
		Calendar::register();
		Signups::register();
		Places::register();
		Features::register();
		Photos::register();
		Accounts::register();
		Approvals::register();
		Claims::register();
		Updates::register();
		Spot_Page::register();
		// Map features are the hub's shared layer. A partner site has no page for one.
		if ( self::is_hub() ) {
			Feature_Page::register();
		}
		Profile::register();
		Messages::register();
		Sign_In::register();
		Workspace::register();
		Overview::register();
		Rest::register();
		Blocks::register();
		Field_App::register();
		Pages::register();
		Health::register();
		Privacy::register();
		Cli::register();

		if ( is_admin() ) {
			Admin::register();
			Wizard::register();
			Pages_Screen::register();
			Connection_Screen::register();
		}
	}

	/**
	 * Where the plugin that carries the library keeps its translations: its own `languages` directory.
	 */
	public static function languages(): string {
		return dirname( PACKOUT_CORE_FILE, 3 ) . '/languages';
	}

	/**
	 * Load the library's translations: a file the site installed, or else the one the plugin carries.
	 */
	public static function load_textdomain(): void {
		$name = 'packout-core-' . determine_locale() . '.mo';
		foreach ( array( WP_LANG_DIR . '/plugins/' . $name, self::languages() . '/' . $name ) as $file ) {
			if ( is_readable( $file ) ) {
				load_textdomain( 'packout-core', $file );
				return;
			}
		}
	}

	/**
	 * Give a script its translations.
	 *
	 * @param string $handle The script.
	 */
	public static function translate_script( string $handle ): void {
		if ( function_exists( 'wp_set_script_translations' ) && is_dir( self::languages() ) ) {
			wp_set_script_translations( $handle, 'packout-core', self::languages() );
		}
	}

	/**
	 * Install or upgrade what the application keeps on a site: the roles, the
	 * category terms, and the invitation tables. The rewrite rules are
	 * written again, since a version can add one.
	 *
	 * A plugin calls this on `init`, after the record types are registered,
	 * when its stored version is behind.
	 */
	public static function install(): void {
		Capabilities::install();
		Schema::install_terms();
		Invitations::install_tables();
		if ( ! self::is_hub() ) {
			Outbox::install_tables();
		}
		flush_rewrite_rules( false );
	}

	/**
	 * The kernel the plugin registered.
	 *
	 * @throws \RuntimeException When the application is used before a plugin registered a kernel.
	 */
	public static function kernel(): Kernel {
		$kernel = Kernel::instance();
		if ( ! $kernel instanceof Kernel ) {
			throw new \RuntimeException( 'Pack-Out Spot was used before a plugin registered its kernel.' );
		}
		return $kernel;
	}

	/**
	 * Whether this site is packout.spot.
	 */
	public static function is_hub(): bool {
		return self::kernel()->site()->is_hub();
	}

	/**
	 * The answer to an act a partner site does not have.
	 *
	 * The capability list says which acts are the hub's alone, and why.
	 */
	public static function hub_only(): \WP_Error {
		return new \WP_Error( 'packout_hub_only', __( 'That is done on packout.spot, not on this site.', 'packout-core' ), array( 'status' => 404 ) );
	}

	/**
	 * The first part of a public path, without slashes.
	 *
	 * @param string $kind One of `spot`, `team`, `mission`, `campaign`, `program`, `partner`, `place`, `feature`, `field`.
	 */
	public static function base( string $kind ): string {
		$base = trim( self::kernel()->site()->base( $kind ), '/' );
		return '' !== $base ? $base : $kind;
	}
}
