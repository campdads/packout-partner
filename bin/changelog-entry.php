#!/usr/bin/env php
<?php
/**
 * Print one version's entry from CHANGELOG.md.
 *
 * Usage:
 *
 *     php bin/changelog-entry.php 0.1.0
 *
 * @package PackOut_Partner
 */

$version = isset( $argv[1] ) ? ltrim( $argv[1], 'v' ) : '';
$lines   = file( dirname( __DIR__ ) . '/CHANGELOG.md', FILE_IGNORE_NEW_LINES );
$entry   = array();
$inside  = false;
foreach ( is_array( $lines ) ? $lines : array() as $line ) {
	if ( 1 === preg_match( '/^## \[?([0-9][^\]\s]*)\]?/', $line, $found ) ) {
		if ( $inside ) {
			break;
		}
		$inside = $found[1] === $version;
		continue;
	}
	if ( $inside ) {
		$entry[] = $line;
	}
}
$text = trim( implode( "\n", $entry ) );
if ( '' === $version || '' === $text ) {
	fwrite( STDERR, "CHANGELOG.md has no entry for that version\n" );
	exit( 1 );
}
fwrite( STDOUT, $text . "\n" );
