#!/usr/bin/env php
<?php
/**
 * Check a built package before it goes anywhere.
 *
 * Usage:
 *
 *     php bin/package-check.php <package.zip>
 *
 * The package must be what a site should run and nothing else:
 *
 * - every entry is under `packout-partner/`, and the plugin file is there at
 *   the version in its name;
 * - it holds the library and its lock, the readme, and the license;
 * - it holds no tests, tools, workflows, or development files;
 * - each file is the same as in this checkout, and every file Git tracks in
 *   the directories a site runs is in it;
 * - its SHA-256 is the one written beside it.
 *
 * @package PackOut_Partner
 */

declare( strict_types=1 );

$root    = dirname( __DIR__ );
$package = $argv[1] ?? '';
$stop    = static function ( string $message ): void {
	fwrite( STDERR, 'package-check: ' . $message . "\n" );
	exit( 1 );
};

if ( '' === $package || ! is_file( $package ) ) {
	$stop( 'name the package.' );
}
if ( 1 !== preg_match( '/^packout-partner-((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?)\.zip$/', basename( $package ), $named ) ) {
	$stop( 'the package is not named packout-partner-{version}.zip.' );
}
$sidecar = is_readable( $package . '.sha256' ) ? preg_split( '/\s+/', trim( (string) file_get_contents( $package . '.sha256' ) ) ) : array();
if ( ( $sidecar[0] ?? '' ) !== hash_file( 'sha256', $package ) || ( $sidecar[1] ?? '' ) !== basename( $package ) ) {
	$stop( 'the package does not match the checksum written beside it.' );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $package, ZipArchive::RDONLY ) ) {
	$stop( 'the package is not a ZIP.' );
}
$packed  = array();
$entries = $zip->count();
for ( $i = 0; $i < $entries; $i++ ) {
	$name = (string) $zip->getNameIndex( $i );
	if ( '/' === substr( $name, -1 ) ) {
		continue;
	}
	if ( 0 !== strpos( $name, 'packout-partner/' ) || false !== strpos( $name, '..' ) ) {
		$stop( 'the package holds ' . $name . ', outside packout-partner/.' );
	}
	$packed[ substr( $name, strlen( 'packout-partner/' ) ) ] = hash( 'sha256', (string) $zip->getFromIndex( $i ) );
}
$main = (string) $zip->getFromName( 'packout-partner/packout-partner.php' );
$zip->close();

if ( 1 !== preg_match( '/^[ \t]*\*[ \t]*Version:[ \t]*(\S+)/m', $main, $header ) || $header[1] !== $named[1] ) {
	$stop( 'the plugin file in the package is not version ' . $named[1] . '.' );
}
foreach ( array( 'packout-partner.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'packout-core.lock', 'lib/packout-core/load.php', 'lib/packout-core/capabilities.php', 'lib/packout-core/src/class-release.php', 'src/class-plugin.php', 'src/handlers.php' ) as $needed ) {
	if ( ! isset( $packed[ $needed ] ) ) {
		$stop( 'the package has no ' . $needed . '.' );
	}
}

$problems = array();
foreach ( array_keys( $packed ) as $file ) {
	$parts = explode( '/', $file );
	// Dompdf is carried whole, as its authors released it, so a directory of theirs may have any name.
	$ours = 0 !== strpos( $file, 'lib/dompdf/' );
	if ( $ours && ( array() !== array_intersect( $parts, array( 'tests', 'ci', 'bin', 'vendor', 'node_modules', 'dist' ) ) || 1 === preg_match( '#(^|/)\.|^(composer\.|phpcs\.|README\.md$|CONTRIBUTING\.md$|SECURITY\.md$)#', $file ) ) ) {
		$problems[] = $file . ' does not belong in a package';
		continue;
	}
	if ( ! is_file( $root . '/' . $file ) ) {
		$problems[] = $file . ' is not in this checkout';
	} elseif ( hash_file( 'sha256', $root . '/' . $file ) !== $packed[ $file ] ) {
		$problems[] = $file . ' differs from this checkout';
	}
}

// Every tracked file a site runs must be there. An untracked one must not.
$listing = array();
$status  = 1;
exec( 'git -C ' . escapeshellarg( $root ) . ' ls-files -z -- packout-partner.php uninstall.php readme.txt LICENSE CHANGELOG.md packout-core.lock src lib assets blocks languages templates 2>/dev/null', $listing, $status );
if ( 0 === $status ) {
	$tracked = array_filter( explode( "\0", implode( "\n", $listing ) ), 'strlen' );
	foreach ( $tracked as $file ) {
		if ( ! isset( $packed[ $file ] ) ) {
			$problems[] = $file . ' is tracked and not in the package';
		}
	}
	foreach ( array_keys( $packed ) as $file ) {
		if ( ! in_array( $file, $tracked, true ) ) {
			$problems[] = $file . ' is in the package and not tracked';
		}
	}
}

if ( array() !== $problems ) {
	$problems = array_values( array_unique( $problems ) );
	foreach ( array_slice( $problems, 0, 30 ) as $problem ) {
		fwrite( STDERR, 'package-check: ' . $problem . "\n" );
	}
	$stop( count( $problems ) . ' problems.' );
}
fwrite( STDOUT, 'package-check: ' . basename( $package ) . ' holds ' . count( $packed ) . " files, each as in this checkout\n" );
