<?php
/**
 * Mailer adapter.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends a message.
 */
interface Mailer {

	/**
	 * Send one message.
	 *
	 * The sender, and how mail is delivered, are the site's choice.
	 *
	 * @param string             $to      Address.
	 * @param string             $subject Subject line.
	 * @param string             $message Body. Plain text, or HTML when `$plain` is given.
	 * @param array<int, string> $headers Mail headers, such as `Reply-To` and `List-Unsubscribe`.
	 * @param string             $plain   The same message as plain text, sent beside an HTML body.
	 */
	public function send( string $to, string $subject, string $message, array $headers = array(), string $plain = '' ): bool;
}
