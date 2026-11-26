<?php
/**
 * Shared by the test scripts: one function that records a check, and one
 * that ends the run.
 *
 * @package PackOut_Partner
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

$GLOBALS['packout_partner_test'] = array(
	'passed' => 0,
	'failed' => array(),
);

/**
 * Record one check.
 *
 * @param string $label  What should be true.
 * @param bool   $ok     Whether it is.
 * @param mixed  $detail Shown when it is not.
 */
function packout_partner_check( string $label, bool $ok, $detail = '' ): void {
	if ( $ok ) {
		++$GLOBALS['packout_partner_test']['passed'];
		WP_CLI::log( '  ok    ' . $label );
		return;
	}
	$GLOBALS['packout_partner_test']['failed'][] = $label;
	$shown                                       = is_scalar( $detail ) ? (string) $detail : (string) wp_json_encode( $detail );
	WP_CLI::log( '  FAIL  ' . $label . ( '' !== $shown && '[]' !== $shown ? ' — ' . $shown : '' ) );
}

/**
 * End the run: an error when any check failed.
 */
function packout_partner_finish(): void {
	$passed = $GLOBALS['packout_partner_test']['passed'];
	$failed = count( $GLOBALS['packout_partner_test']['failed'] );
	if ( $failed > 0 ) {
		WP_CLI::error( $failed . ' of ' . ( $passed + $failed ) . ' checks failed.' );
	}
	WP_CLI::success( $passed . ' checks passed.' );
}
