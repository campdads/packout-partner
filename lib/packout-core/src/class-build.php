<?php
/**
 * The copy of the library a plugin carries.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fingerprints a copy of the library and compares it with a plugin's lock.
 *
 * A plugin carries the library in `lib/packout-core/` and records which
 * build that is in `packout-core.lock`. Both plugins' checks call `problem()`,
 * so a copy that was edited in place, or that is not a tagged version, stops
 * the build. Two plugins with the same fingerprint carry the same library.
 */
class Build {

	/**
	 * Every file under a directory, relative to it, sorted.
	 *
	 * @param string $root Directory.
	 * @return array<int, string>
	 */
	public static function files( string $root ): array {
		$files = array();
		$root  = rtrim( $root, '/' );
		if ( ! is_dir( $root ) ) {
			return $files;
		}
		$walk = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $walk as $file ) {
			if ( $file instanceof \SplFileInfo && $file->isFile() && '.DS_Store' !== $file->getFilename() ) {
				$files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			}
		}
		sort( $files, SORT_STRING );
		return $files;
	}

	/**
	 * A fingerprint of a copy: the SHA-256 of each file's path and SHA-256,
	 * in path order.
	 *
	 * @param string $root The copy's directory.
	 */
	public static function fingerprint( string $root ): string {
		$root  = rtrim( $root, '/' );
		$lines = '';
		foreach ( self::files( $root ) as $file ) {
			$lines .= $file . "\0" . hash_file( 'sha256', $root . '/' . $file ) . "\n";
		}
		return hash( 'sha256', $lines );
	}

	/**
	 * Read a lock file.
	 *
	 * @param string $lock_file Path of `packout-core.lock`.
	 * @return array{version: string, tag: string, commit: string, tree: string}
	 */
	public static function lock( string $lock_file ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file, read in tests and build checks.
		$decoded = is_readable( $lock_file ) ? json_decode( (string) file_get_contents( $lock_file ), true ) : null;
		$decoded = is_array( $decoded ) ? $decoded : array();
		$lock    = array();
		foreach ( array( 'version', 'tag', 'commit', 'tree' ) as $field ) {
			$lock[ $field ] = isset( $decoded[ $field ] ) && is_string( $decoded[ $field ] ) ? $decoded[ $field ] : '';
		}
		return $lock;
	}

	/**
	 * What is wrong with a plugin's copy of the library.
	 *
	 * @param string $lock_file Path of `packout-core.lock`.
	 * @param string $root      The copy's directory.
	 * @param bool   $tagged    Whether the copy must be a tagged version. A release must be.
	 * @return string Empty when the copy is the build the lock names.
	 */
	public static function problem( string $lock_file, string $root, bool $tagged = true ): string {
		$lock = self::lock( $lock_file );
		if ( '' === $lock['version'] || '' === $lock['tree'] ) {
			return 'There is no packout-core.lock, or it names no version.';
		}
		$load = rtrim( $root, '/' ) . '/load.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file, read in tests and build checks.
		if ( ! is_readable( $load ) || 1 !== preg_match( "/define\( 'PACKOUT_CORE_VERSION', '([^']+)' \)/", (string) file_get_contents( $load ), $found ) ) {
			return 'There is no copy of the library beside the lock.';
		}
		if ( $found[1] !== $lock['version'] ) {
			return 'The lock names version ' . $lock['version'] . ' and the copy is version ' . $found[1] . '.';
		}
		if ( ! hash_equals( $lock['tree'], self::fingerprint( $root ) ) ) {
			return 'The copy is not the build the lock names. Export the library again; do not edit the copy.';
		}
		if ( $tagged && ( 'v' . $lock['version'] !== $lock['tag'] || 1 !== preg_match( '/^[a-f0-9]{40}$/', $lock['commit'] ) ) ) {
			return 'The copy was not exported from a tagged version of the library.';
		}
		return '';
	}

	/**
	 * The files a plugin's code names, wherever the code is.
	 *
	 * A plugin names its own files as `'assets/…'`, `'lib/…'`, or
	 * `'templates/…'`. The library, in the plugin's `lib/packout-core`, names
	 * its own as `'assets/…'` or `'templates/…'`, and a third-party library
	 * kept beside it as `dirname( PACKOUT_CORE_DIR ) . '/…'`. A release is
	 * checked against this list, and so is a site after an update: a file the
	 * code names and a package lacks is a page that does not work.
	 *
	 * @param string $plugin The plugin's directory.
	 * @return array<string, string> Each file's path from the plugin's directory, and the file that names it.
	 */
	public static function named_files( string $plugin ): array {
		$plugin = rtrim( $plugin, '/' );
		$named  = array();
		$read   = static function ( array $sources, string $pattern, string $under ) use ( &$named, $plugin ): void {
			foreach ( $sources as $source ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file, read in tests and build checks.
				if ( is_file( $source ) && preg_match_all( $pattern, (string) file_get_contents( $source ), $found ) ) {
					foreach ( $found[1] as $path ) {
						$named[ $under . $path ] = substr( $source, strlen( $plugin ) + 1 );
					}
				}
			}
		};
		$file   = '[A-Za-z0-9_./-]+\.[A-Za-z0-9]{2,5}';

		$own = array_merge( (array) glob( $plugin . '/*.php' ), (array) glob( $plugin . '/src/*.php' ), (array) glob( $plugin . '/src/*/*.php' ), (array) glob( $plugin . '/templates/*.php' ) );
		$read( $own, "#'/?((?:assets|lib|templates)/" . $file . ")'#", '' );

		$library = $plugin . '/lib/packout-core';
		$shared  = array_merge( array( $library . '/load.php' ), (array) glob( $library . '/src/*.php' ), (array) glob( $library . '/src/*/*.php' ), (array) glob( $library . '/templates/*.php' ) );
		$read( $shared, "#'/?((?:assets|templates)/" . $file . ")'#", 'lib/packout-core/' );
		$read( $shared, "#dirname\( PACKOUT_CORE_DIR \) \. '/(" . $file . ")'#", 'lib/' );

		ksort( $named );
		return $named;
	}
}
