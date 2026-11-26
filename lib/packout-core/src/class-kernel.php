<?php
/**
 * The library's entry point.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the adapters a plugin supplies and hands them to the library.
 *
 * The library asks for what it cannot do itself through eleven interfaces in
 * `PackOut\Core\Contracts`. The hub's plugin answers with Vectra. The
 * partner plugin answers with WordPress.
 */
class Kernel {

	/**
	 * Adapter name to the interface it must implement.
	 */
	public const ADAPTERS = array(
		'access'      => Contracts\Access::class,
		'lists'       => Contracts\Lists::class,
		'report_form' => Contracts\Report_Form::class,
		'roles'       => Contracts\Roles::class,
		'storage'     => Contracts\Storage::class,
		'screens'     => Contracts\Screens::class,
		'palette'     => Contracts\Palette::class,
		'icons'       => Contracts\Icons::class,
		'settings'    => Contracts\Settings::class,
		'mailer'      => Contracts\Mailer::class,
		'site'        => Contracts\Site::class,
	);

	/**
	 * The color names a palette supplies, in the order the overview and the
	 * impact report number them.
	 */
	public const COLORS = array( 'primary', 'secondary', 'accent', 'accent_dark', 'surface', 'subtle', 'line', 'muted', 'ink' );

	/**
	 * The kernel the plugin registered.
	 *
	 * @var Kernel|null
	 */
	private static $instance = null;

	/**
	 * Adapters by name.
	 *
	 * @var array<string, object>
	 */
	private $adapters = array();

	/**
	 * Build a kernel.
	 *
	 * @param array<string, object> $adapters One adapter for each name in ADAPTERS.
	 * @throws \InvalidArgumentException When an adapter is missing or is the wrong kind.
	 */
	public function __construct( array $adapters ) {
		foreach ( self::ADAPTERS as $name => $contract ) {
			if ( ! isset( $adapters[ $name ] ) || ! $adapters[ $name ] instanceof $contract ) {
				// The message names a class and is read by a developer, not shown on a page.
				throw new \InvalidArgumentException( 'Pack-Out Spot needs a ' . $name . ' adapter that implements ' . $contract . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$this->adapters[ $name ] = $adapters[ $name ];
		}
		$surface = $this->site()->surface();
		if ( 'hub' !== $surface && 'partner' !== $surface ) {
			throw new \InvalidArgumentException( 'The site adapter must say hub or partner.' );
		}
	}

	/**
	 * Make this kernel the one the library uses.
	 *
	 * A plugin calls this once, on `plugins_loaded`.
	 */
	public function register(): void {
		self::$instance = $this;
		if ( function_exists( 'do_action' ) ) {
			/**
			 * The library is ready.
			 *
			 * @param Kernel $kernel The kernel.
			 */
			do_action( 'packout_core_loaded', $this );
		}
	}

	/**
	 * The registered kernel, or null before a plugin registered one.
	 */
	public static function instance(): ?Kernel {
		return self::$instance;
	}

	/**
	 * Forget the registered kernel. For tests.
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * The library's version.
	 */
	public static function version(): string {
		return PACKOUT_CORE_VERSION;
	}

	/**
	 * An adapter by name.
	 *
	 * @param string $name A key of ADAPTERS.
	 * @return object
	 * @throws \InvalidArgumentException When there is no adapter of that name.
	 */
	public function adapter( string $name ) {
		if ( ! isset( $this->adapters[ $name ] ) ) {
			throw new \InvalidArgumentException( 'Pack-Out Spot has no adapter of that name.' );
		}
		return $this->adapters[ $name ];
	}

	/**
	 * Read access.
	 */
	public function access(): Contracts\Access {
		return $this->adapters['access'];
	}

	/**
	 * Lists.
	 */
	public function lists(): Contracts\Lists {
		return $this->adapters['lists'];
	}

	/**
	 * The report form.
	 */
	public function report_form(): Contracts\Report_Form {
		return $this->adapters['report_form'];
	}

	/**
	 * Roles.
	 */
	public function roles(): Contracts\Roles {
		return $this->adapters['roles'];
	}

	/**
	 * Storage.
	 */
	public function storage(): Contracts\Storage {
		return $this->adapters['storage'];
	}

	/**
	 * Screens.
	 */
	public function screens(): Contracts\Screens {
		return $this->adapters['screens'];
	}

	/**
	 * The palette.
	 */
	public function palette(): Contracts\Palette {
		return $this->adapters['palette'];
	}

	/**
	 * Icons.
	 */
	public function icons(): Contracts\Icons {
		return $this->adapters['icons'];
	}

	/**
	 * Settings.
	 */
	public function settings(): Contracts\Settings {
		return $this->adapters['settings'];
	}

	/**
	 * Mail.
	 */
	public function mailer(): Contracts\Mailer {
		return $this->adapters['mailer'];
	}

	/**
	 * The site.
	 */
	public function site(): Contracts\Site {
		return $this->adapters['site'];
	}
}
