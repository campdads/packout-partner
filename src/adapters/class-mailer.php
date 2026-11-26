<?php
/**
 * Mail, through WordPress.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends a message with `wp_mail()`. The plugin does not configure delivery.
 */
class Mailer implements \PackOut\Core\Contracts\Mailer {

	/**
	 * Send one message.
	 *
	 * @param string             $to      Address.
	 * @param string             $subject Subject line.
	 * @param string             $message Body. Plain text, or HTML when `$plain` is given.
	 * @param array<int, string> $headers Mail headers.
	 * @param string             $plain   The same message as plain text, sent beside an HTML body.
	 */
	public function send( string $to, string $subject, string $message, array $headers = array(), string $plain = '' ): bool {
		if ( '' === $plain ) {
			return (bool) wp_mail( $to, $subject, $message, $headers );
		}

		$headers[]   = 'Content-Type: text/html; charset=UTF-8';
		$alternative = static function ( $mailer ) use ( $plain ) {
			if ( is_object( $mailer ) ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property.
				$mailer->AltBody = $plain;
			}
		};
		add_action( 'phpmailer_init', $alternative );
		$sent = (bool) wp_mail( $to, $subject, $message, $headers );
		remove_action( 'phpmailer_init', $alternative );
		return $sent;
	}
}
