#!/usr/bin/env php
<?php
/**
 * Check that the copy of the library in `lib/packout-core/` is the build
 * `packout-core.lock` names.
 *
 * Usage:
 *
 *     php bin/core-check.php [--allow-untagged]
 *
 * The copy is written by `php bin/export.php` in the library's repository and
 * is never edited here. A release must carry a tagged version of the library.
 * `--allow-untagged` accepts a copy exported from a working tree, for use
 * while both are being changed together.
 *
 * @package PackOut_Partner
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
$class = $root . '/lib/packout-core/src/class-build.php';
if ( ! is_readable( $class ) ) {
	fwrite( STDERR, "core-check: there is no copy of the library in lib/packout-core.\n" );
	exit( 1 );
}
require_once $class;

$tagged  = ! in_array( '--allow-untagged', $argv, true );
$problem = PackOut\Core\Build::problem( $root . '/packout-core.lock', $root . '/lib/packout-core', $tagged );
if ( '' !== $problem ) {
	fwrite( STDERR, 'core-check: ' . $problem . "\n" );
	exit( 1 );
}
$lock = PackOut\Core\Build::lock( $root . '/packout-core.lock' );
fwrite( STDOUT, 'core-check: packout-core ' . $lock['version'] . ( '' !== $lock['tag'] ? ' (' . $lock['tag'] . ', ' . substr( $lock['commit'], 0, 7 ) . ')' : ' (not a tagged version)' ) . ', tree ' . substr( $lock['tree'], 0, 12 ) . "\n" );
