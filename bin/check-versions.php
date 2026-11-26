#!/usr/bin/env php
<?php
/**
 * Check that the version is the same everywhere it is written.
 *
 * Usage:
 *
 *     php bin/check-versions.php [tag]
 *
 * The plugin header, the PACKOUT_PARTNER_VERSION constant, the stable tag in
 * readme.txt, and the first entry of CHANGELOG.md must agree. Given a tag,
 * such as v0.1.0, they must agree with it too. The release workflow passes
 * the tag, so a tag that does not match the plugin stops the release.
 *
 * @package PackOut_Partner
 */

declare( strict_types=1 );

$root   = dirname( __DIR__ );
$plugin = (string) file_get_contents( $root . '/packout-partner.php' );
$seen   = array();

if ( 1 === preg_match( '/^[ \t]*\*[ \t]*Version:[ \t]*(\S+)/m', $plugin, $found ) ) {
	$seen['header'] = $found[1];
}
if ( 1 === preg_match( "/define\( 'PACKOUT_PARTNER_VERSION', '([^']+)' \)/", $plugin, $found ) ) {
	$seen['constant'] = $found[1];
}
if ( 1 === preg_match( '/^Stable tag:[ \t]*(\S+)/mi', (string) file_get_contents( $root . '/readme.txt' ), $found ) ) {
	$seen['readme.txt'] = $found[1];
}
if ( 1 === preg_match( '/^## \[?([0-9][^\]\s]*)\]?/m', (string) file_get_contents( $root . '/CHANGELOG.md' ), $found ) ) {
	$seen['CHANGELOG.md'] = $found[1];
}
if ( isset( $argv[1] ) && '' !== $argv[1] ) {
	$seen['tag'] = 0 === strpos( $argv[1], 'v' ) ? substr( $argv[1], 1 ) : $argv[1];
}

foreach ( $seen as $where => $version ) {
	fwrite( STDOUT, str_pad( $where, 14 ) . $version . "\n" );
}
if ( ! isset( $seen['header'], $seen['constant'], $seen['readme.txt'], $seen['CHANGELOG.md'] ) ) {
	fwrite( STDERR, "a version is missing from the plugin header, the constant, readme.txt, or CHANGELOG.md\n" );
	exit( 1 );
}
if ( count( array_unique( $seen ) ) > 1 ) {
	fwrite( STDERR, "the versions do not agree\n" );
	exit( 1 );
}
if ( 1 !== preg_match( '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/', $seen['header'] ) ) {
	fwrite( STDERR, "the version is not of the form 1.2.3 or 1.2.3-beta.1\n" );
	exit( 1 );
}
fwrite( STDOUT, "the versions agree\n" );
