<?php
/**
 * Plugin updates from the catalog on packout.spot.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells WordPress when a newer version of a plugin is in the catalog, and
 * refuses a package that is not the one the catalog described.
 *
 * The partner plugin uses this. It needs nothing but WordPress. The hub's
 * plugin is updated by Vectra Toolkit instead.
 *
 * The plugin's header carries `Update URI: https://packout.spot/plugins/{slug}`,
 * so WordPress asks this class, not wordpress.org. The check sends the
 * plugin's file name and version. WordPress adds its own `User-Agent`, which
 * carries the site's address; that is how the catalog counts the sites a
 * version is installed on.
 */
class Updates {

	/**
	 * How long an answer from the catalog is kept, in seconds.
	 */
	public const KEEP = 43200;

	/**
	 * How long to wait after the catalog did not answer, in seconds.
	 */
	public const KEEP_AFTER_FAILURE = 3600;

	/**
	 * The plugin's file, relative to the plugins directory.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * The plugin's slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * The installed version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Returns `beta` when the site asked for beta versions.
	 *
	 * @var callable|null
	 */
	private $channel;

	/**
	 * Sends a request. Null uses WordPress.
	 *
	 * @var callable|null
	 */
	private $http;

	/**
	 * Fetches a package to a temporary file. Null uses WordPress.
	 *
	 * @var callable|null
	 */
	private $download;

	/**
	 * Build the client.
	 *
	 * @param array<string, mixed> $config `file`, `slug`, and `version` of the plugin. Optional `channel`,
	 *                                     a callable that returns `stable` or `beta`. `http` and `download`
	 *                                     replace WordPress's request functions in tests.
	 * @throws \InvalidArgumentException When the plugin is not named.
	 */
	public function __construct( array $config ) {
		$this->file    = isset( $config['file'] ) ? (string) $config['file'] : '';
		$this->slug    = isset( $config['slug'] ) ? (string) $config['slug'] : '';
		$this->version = isset( $config['version'] ) ? (string) $config['version'] : '';
		if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->slug ) || 0 !== strpos( $this->file, $this->slug . '/' ) || '' === $this->version ) {
			throw new \InvalidArgumentException( 'The update client needs the plugin’s file, slug, and version.' );
		}
		$this->channel  = isset( $config['channel'] ) && is_callable( $config['channel'] ) ? $config['channel'] : null;
		$this->http     = isset( $config['http'] ) && is_callable( $config['http'] ) ? $config['http'] : null;
		$this->download = isset( $config['download'] ) && is_callable( $config['download'] ) ? $config['download'] : null;
	}

	/**
	 * Hook into WordPress's update checks.
	 */
	public function register(): void {
		add_filter( 'update_plugins_' . Release::HOST, array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), 10, 4 );
		add_action( 'delete_site_transient_update_plugins', array( $this, 'forget' ) );
	}

	/**
	 * The catalog's address.
	 *
	 * Always https://packout.spot, with one exception: on a site whose
	 * environment type is `local`, the PACKOUT_CATALOG_ORIGIN constant names
	 * another catalog, which may use plain HTTP. That is how the test
	 * environment reaches its own.
	 */
	public static function origin(): string {
		if ( Release::is_local() && defined( 'PACKOUT_CATALOG_ORIGIN' ) && is_string( PACKOUT_CATALOG_ORIGIN ) ) {
			$origin = rtrim( PACKOUT_CATALOG_ORIGIN, '/' );
			if ( 1 === preg_match( '#^https?://[a-z0-9.-]+(?::[0-9]{1,5})?$#i', $origin ) ) {
				return $origin;
			}
		}
		return Release::ORIGIN;
	}

	/**
	 * The channel this site takes: `stable` or `beta`.
	 */
	public function channel(): string {
		return null !== $this->channel && 'beta' === call_user_func( $this->channel ) ? 'beta' : 'stable';
	}

	/**
	 * Answer WordPress's update check for this plugin.
	 *
	 * @param mixed $update      What an earlier filter answered.
	 * @param mixed $plugin_data The plugin's headers.
	 * @param mixed $plugin_file The plugin's file.
	 * @return mixed The update, or what was passed in when there is nothing to say.
	 */
	public function check( $update, $plugin_data = array(), $plugin_file = '' ) {
		if ( $plugin_file !== $this->file || self::updates_off() ) {
			return $update;
		}
		$module = $this->module();
		if ( null === $module ) {
			return $update;
		}
		return array(
			'id'           => Release::ORIGIN . '/plugins/' . $this->slug,
			'slug'         => $this->slug,
			'version'      => $module['version'],
			'url'          => Release::ORIGIN . '/plugins/' . $this->slug,
			'package'      => $module['package_url'],
			'tested'       => $module['tested'],
			'requires'     => $module['requires'],
			'requires_php' => $module['requires_php'],
		);
	}

	/**
	 * What the catalog offers this plugin, or null.
	 *
	 * The answer is kept for twelve hours, and for an hour when the catalog
	 * did not answer.
	 *
	 * @param bool $fresh Ask the catalog even when an answer is kept.
	 * @return array{version: string, package_url: string, checksum: string, signature: string, tested: string, requires: string, requires_php: string}|null
	 */
	public function module( bool $fresh = false ): ?array {
		$key  = $this->transient();
		$kept = $fresh ? false : get_site_transient( $key );
		if ( is_array( $kept ) && isset( $kept['channel'] ) && $kept['channel'] === $this->channel() ) {
			return isset( $kept['module'] ) && is_array( $kept['module'] ) ? $kept['module'] : null;
		}

		$module = $this->ask();
		$failed = false === $module;
		set_site_transient(
			$key,
			array(
				'channel' => $this->channel(),
				'module'  => $failed ? null : $module,
				'failed'  => $failed,
				'checked' => time(),
			),
			$failed ? self::KEEP_AFTER_FAILURE : self::KEEP
		);
		return $failed ? null : $module;
	}

	/**
	 * When the catalog was last asked, and what happened.
	 *
	 * @return array{checked: int, failed: bool, version: string, channel: string}
	 */
	public function status(): array {
		$kept = get_site_transient( $this->transient() );
		return array(
			'checked' => is_array( $kept ) ? (int) ( $kept['checked'] ?? 0 ) : 0,
			'failed'  => is_array( $kept ) && ! empty( $kept['failed'] ),
			'version' => is_array( $kept ) && isset( $kept['module']['version'] ) ? (string) $kept['module']['version'] : '',
			'channel' => $this->channel(),
		);
	}

	/**
	 * Drop the kept answer, so the next check asks the catalog.
	 */
	public function forget(): void {
		delete_site_transient( $this->transient() );
		delete_site_transient( $this->transient() . '_details' );
	}

	/**
	 * Fill the details window WordPress opens from the Plugins screen.
	 *
	 * @param mixed $result What an earlier filter answered.
	 * @param mixed $action What WordPress asked for.
	 * @param mixed $args   The request.
	 * @return mixed
	 */
	public function details( $result, $action = '', $args = null ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}
		$key      = $this->transient() . '_details';
		$document = get_site_transient( $key );
		if ( ! is_array( $document ) ) {
			$document = $this->request( 'GET', self::origin() . '/wp-json/vectra/v1/plugins/' . $this->slug );
			if ( ! is_array( $document ) || ( $document['slug'] ?? '' ) !== $this->slug ) {
				return $result;
			}
			set_site_transient( $key, $document, self::KEEP );
		}

		$requires = isset( $document['requires'] ) && is_array( $document['requires'] ) ? $document['requires'] : array();
		$sections = array(
			'description' => wp_kses_post( wpautop( (string) ( $document['description'] ?? '' ) ) ),
		);
		if ( ! empty( $document['changelog'] ) ) {
			$sections['changelog'] = wp_kses_post( wpautop( (string) $document['changelog'] ) );
		}

		return (object) array(
			'name'          => (string) ( $document['name'] ?? $this->slug ),
			'slug'          => $this->slug,
			'version'       => (string) ( $document['version'] ?? '' ),
			'homepage'      => Release::ORIGIN . '/plugins/' . $this->slug,
			'requires'      => (string) ( $requires['wordpress'] ?? '' ),
			'tested'        => (string) ( $requires['tested_wordpress'] ?? '' ),
			'requires_php'  => (string) ( $requires['php'] ?? '' ),
			'last_updated'  => (string) ( $document['release_date'] ?? '' ),
			'download_link' => (string) ( $document['package_url'] ?? '' ),
			'sections'      => $sections,
		);
	}

	/**
	 * Download the package ourselves and hand WordPress the file only when
	 * it is the one the catalog described.
	 *
	 * The file's SHA-256 must be the one in the catalog's answer, and its
	 * signature must have been made by a key in `Release::PUBLIC_KEYS`. A
	 * package with no signature is refused.
	 *
	 * @param mixed $reply      What an earlier filter answered. False means nobody has.
	 * @param mixed $package    The package's address.
	 * @param mixed $upgrader   The upgrader.
	 * @param mixed $hook_extra What is being updated.
	 * @return mixed A path to the checked file, a WP_Error, or `$reply` when the package is not this plugin's.
	 */
	public function pre_download( $reply, $package = '', $upgrader = null, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) || ! $this->is_package( $package ) ) {
			return $reply;
		}
		if ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] !== $this->file ) {
			return $reply;
		}

		$module = $this->module();
		if ( null === $module || $module['package_url'] !== $package ) {
			$module = $this->module( true );
		}
		if ( null === $module || $module['package_url'] !== $package ) {
			return new \WP_Error( 'packout_package_unknown', __( 'The update was stopped: the catalog on packout.spot no longer offers this package. Check for updates again.', 'packout-core' ) );
		}
		if ( '' === $module['checksum'] || '' === $module['signature'] ) {
			return new \WP_Error( 'packout_package_unsigned', __( 'The update was stopped: the package has no signature.', 'packout-core' ) );
		}

		$path = null !== $this->download ? call_user_func( $this->download, $package ) : self::fetch( $package );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$bytes = is_string( $path ) && is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local temporary file.
		if ( ! is_string( $bytes ) || '' === $bytes ) {
			self::discard( $path );
			return new \WP_Error( 'packout_package_missing', __( 'The update was stopped: the package did not download.', 'packout-core' ) );
		}
		if ( ! Release::checksum_matches( $bytes, $module['checksum'] ) ) {
			self::discard( $path );
			return new \WP_Error( 'packout_package_checksum', __( 'The update was stopped: the package is not the file the catalog described.', 'packout-core' ) );
		}
		if ( ! Release::verify( $bytes, $module['signature'] ) ) {
			self::discard( $path );
			return new \WP_Error( 'packout_package_signature', __( 'The update was stopped: the package’s signature was not made by Pack-Out Spot’s release key.', 'packout-core' ) );
		}
		return $path;
	}

	/**
	 * Whether an address is one of this plugin's packages in the catalog.
	 *
	 * @param string $package The package's address.
	 */
	public function is_package( string $package ): bool {
		return 0 === strpos( $package, self::origin() . '/wp-json/vectra/v1/packages/' . $this->slug . '/' );
	}

	/**
	 * Ask the catalog what it offers this plugin.
	 *
	 * @return array<string, string>|null|false The offer, null when there is none, false when the catalog did not answer.
	 */
	private function ask() {
		$document = $this->request(
			'POST',
			self::origin() . '/wp-json/vectra/v1/manifest',
			array(
				'plugins' => wp_json_encode( array( $this->file => array( 'Version' => $this->version ) ) ),
				'channel' => $this->channel(),
			)
		);
		if ( ! is_array( $document ) || ! isset( $document['modules'] ) || ! is_array( $document['modules'] ) ) {
			return false;
		}
		foreach ( $document['modules'] as $module ) {
			if ( ! is_array( $module ) || ( $module['slug'] ?? '' ) !== $this->slug ) {
				continue;
			}
			$version = (string) ( $module['version'] ?? '' );
			$package = (string) ( $module['package_url'] ?? '' );
			if ( 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $version ) || ! $this->is_package( $package ) ) {
				return null;
			}
			$requires = isset( $module['requires'] ) && is_array( $module['requires'] ) ? $module['requires'] : array();
			return array(
				'version'      => $version,
				'package_url'  => $package,
				'checksum'     => strtolower( (string) ( $module['checksum'] ?? '' ) ),
				'signature'    => strtolower( (string) ( $module['signature'] ?? '' ) ),
				'tested'       => (string) ( $requires['tested_wordpress'] ?? '' ),
				'requires'     => (string) ( $requires['wordpress'] ?? '' ),
				'requires_php' => (string) ( $requires['php'] ?? '' ),
			);
		}
		return null;
	}

	/**
	 * Send a request to the catalog and decode its JSON answer.
	 *
	 * @param string               $method GET or POST.
	 * @param string               $url    Address.
	 * @param array<string, mixed> $body   Form fields, for a POST.
	 * @return array<string, mixed>|null The decoded answer, or null when there was none.
	 */
	private function request( string $method, string $url, array $body = array() ): ?array {
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array( 'Accept' => 'application/json' ),
		);
		if ( 'POST' === $method ) {
			$args['body'] = $body;
		}
		$response = null !== $this->http ? call_user_func( $this->http, $url, $args ) : wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Download a package to a temporary file.
	 *
	 * @param string $package The package's address.
	 * @return string|\WP_Error
	 */
	private static function fetch( string $package ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return download_url( $package, 300 );
	}

	/**
	 * Remove a temporary file.
	 *
	 * @param mixed $path The file.
	 */
	private static function discard( $path ): void {
		if ( is_string( $path ) && '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Whether the site turned plugin changes off.
	 */
	private static function updates_off(): bool {
		return function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'capability_update_core' );
	}

	/**
	 * The name the kept answer is stored under.
	 */
	private function transient(): string {
		return 'packout_update_' . substr( md5( $this->slug . '|' . self::origin() ), 0, 12 );
	}
}
