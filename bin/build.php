#!/usr/bin/env php
<?php
/**
 * Build the package a site installs.
 *
 * Usage:
 *
 *     php bin/build.php [output directory]
 *
 * Writes `packout-partner-{version}.zip` and `packout-partner-{version}.zip.sha256`
 * into the output directory, `dist` by default. The package holds the files
 * a site runs and nothing else: no tests, tools, or workflows.
 *
 * The same commit gives the same package on any machine: files go in path
 * order, and each carries the time of the commit, or SOURCE_DATE_EPOCH when
 * that is set, written as UTC. Anyone can build a tag and compare the
 * SHA-256 with the one the release carries.
 *
 * @package PackOut_Partner
 */

declare( strict_types=1 );

// A ZIP stores local time. Without this the bytes depend on the machine's time zone.
putenv( 'TZ=UTC' );

$root   = dirname( __DIR__ );
$output = rtrim( $argv[1] ?? $root . '/dist', '/' );

/**
 * What goes in the package: these files, and every file under these directories.
 */
$wanted = array( 'packout-partner.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'CHANGELOG.md', 'packout-core.lock', 'src', 'lib', 'assets', 'blocks', 'languages', 'templates' );

$stop = static function ( string $message ): void {
	fwrite( STDERR, 'build: ' . $message . "\n" );
	exit( 1 );
};

if ( ! class_exists( 'ZipArchive' ) ) {
	$stop( 'PHP needs the zip extension.' );
}
if ( 1 !== preg_match( '/^[ \t]*\*[ \t]*Version:[ \t]*(\S+)/m', (string) file_get_contents( $root . '/packout-partner.php' ), $found ) ) {
	$stop( 'packout-partner.php has no Version header.' );
}
$version = $found[1];

$files = array();
foreach ( $wanted as $path ) {
	$from = $root . '/' . $path;
	if ( is_file( $from ) ) {
		$files[] = $path;
		continue;
	}
	if ( ! is_dir( $from ) ) {
		continue;
	}
	$walk = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $walk as $file ) {
		if ( $file instanceof SplFileInfo && $file->isFile() && 0 !== strpos( $file->getFilename(), '.' ) ) {
			$files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		}
	}
}
sort( $files, SORT_STRING );
foreach ( array( 'packout-partner.php', 'readme.txt', 'LICENSE', 'packout-core.lock', 'lib/packout-core/load.php' ) as $needed ) {
	if ( ! in_array( $needed, $files, true ) ) {
		$stop( $needed . ' is missing.' );
	}
}

$epoch = getenv( 'SOURCE_DATE_EPOCH' );
if ( ! is_string( $epoch ) || 1 !== preg_match( '/^[0-9]+$/', $epoch ) ) {
	$lines  = array();
	$status = 1;
	exec( 'git -C ' . escapeshellarg( $root ) . ' log -1 --format=%ct 2>/dev/null', $lines, $status );
	$epoch = 0 === $status && isset( $lines[0] ) && 1 === preg_match( '/^[0-9]+$/', $lines[0] ) ? $lines[0] : '315532800';
}

if ( ! is_dir( $output ) && ! mkdir( $output, 0755, true ) ) {
	$stop( 'cannot create ' . $output );
}
$name = 'packout-partner-' . $version . '.zip';
$path = $output . '/' . $name;
if ( is_file( $path ) ) {
	unlink( $path );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $path, ZipArchive::CREATE ) ) {
	$stop( 'cannot create ' . $path );
}
foreach ( $files as $file ) {
	$inside = 'packout-partner/' . $file;
	$zip->addFromString( $inside, (string) file_get_contents( $root . '/' . $file ) );
	$zip->setMtimeName( $inside, (int) $epoch );
	$zip->setExternalAttributesName( $inside, ZipArchive::OPSYS_UNIX, 0100644 << 16 );
}
$zip->close();

$checksum = hash_file( 'sha256', $path );
file_put_contents( $path . '.sha256', $checksum . '  ' . $name . "\n" );
fwrite( STDOUT, $path . "\n" . $checksum . "\n" . count( $files ) . " files\n" );
