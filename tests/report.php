<?php
/**
 * The report form, as a browser uses it.
 *
 * A page with the Report block is asked for and sent back over HTTP: as a
 * visitor, as a script would, and as a person who is signed in. Every report
 * that should become a Spot does, pending review, and none that should not.
 *
 * Run inside WordPress, with the plugin active:
 *
 *     wp eval-file tests/report.php
 *
 * The run takes about ten seconds: a report sent sooner than a person could
 * write one is dropped, so the checks wait as a person would. The checks
 * create their own page, people, and Spots, and delete them.
 *
 * @package PackOut_Partner
 */

use PackOut\Core\App\Photos;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Teams;
use PackOut\Partner\Report_Form;

require __DIR__ . '/support.php';

$packout_run   = strtolower( wp_generate_password( 6, false ) );
$packout_posts = array();
$packout_users = array();
$packout_files = array();

$home     = untrailingslashit( home_url() );
$host     = (string) preg_replace( '#^https?://#', '', $home );
$loopback = untrailingslashit( (string) ( getenv( 'PACKOUT_LOOPBACK' ) ? getenv( 'PACKOUT_LOOPBACK' ) : $home ) );

/**
 * Ask for a page, or send a form to it.
 *
 * @param string               $url     The page.
 * @param array<string, mixed> $fields  What the form holds. Null asks for the page.
 * @param array<string, mixed> $file    A file to send: name, type, body.
 * @param array<int, mixed>    $cookies Cookies of a person who is signed in.
 * @return array{0: int, 1: string, 2: string} Status, where it redirects to, and the page.
 */
$ask = static function ( string $url, ?array $fields = null, array $file = array(), array $cookies = array() ) use ( $home, $host, $loopback ): array {
	$args = array(
		'method'      => null === $fields ? 'GET' : 'POST',
		'headers'     => array( 'Host' => $host ),
		'redirection' => 0,
		'timeout'     => 30,
		'cookies'     => $cookies,
	);
	if ( null !== $fields && ! $file ) {
		$args['body'] = $fields;
	} elseif ( null !== $fields ) {
		// A form with a file is sent in parts.
		$edge = 'packout' . wp_generate_password( 16, false );
		$body = '';
		foreach ( explode( '&', http_build_query( $fields ) ) as $pair ) {
			list( $name, $value ) = array_map( 'urldecode', explode( '=', $pair, 2 ) );
			$body                .= '--' . $edge . "\r\n" . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n" . $value . "\r\n";
		}
		$body                           .= '--' . $edge . "\r\n" . 'Content-Disposition: form-data; name="' . Report_Form::PHOTO . '"; filename="' . $file['name'] . '"' . "\r\n" . 'Content-Type: ' . $file['type'] . "\r\n\r\n" . $file['body'] . "\r\n--" . $edge . "--\r\n";
		$args['body']                    = $body;
		$args['headers']['Content-Type'] = 'multipart/form-data; boundary=' . $edge;
	}
	$response = wp_remote_request( $loopback . substr( $url, strlen( $home ) ), $args );
	// What another request stored is read fresh.
	wp_cache_flush();
	if ( is_wp_error( $response ) ) {
		return array( 0, '', $response->get_error_message() );
	}
	return array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_header( $response, 'location' ), (string) wp_remote_retrieve_body( $response ) );
};

// The hidden fields of the form on a page: the nonce and when it was drawn.
$hidden = static function ( string $html ): array {
	$found = array();
	foreach ( array( Report_Form::FIELD . '_nonce', Report_Form::FIELD . '_opened' ) as $name ) {
		$found[ $name ] = preg_match( '#name="' . preg_quote( $name, '#' ) . '" value="([^"]*)"#', $html, $match ) ? html_entity_decode( $match[1] ) : '';
	}
	return $found;
};

// The Spots this run's reports became, newest first.
$spots = static function () use ( $packout_run ): array {
	return get_posts(
		array(
			'post_type'   => Schema::SPOT,
			'post_status' => 'any',
			's'           => 'Reporttest ' . $packout_run,
			'numberposts' => 50,
			'orderby'     => 'ID',
			'order'       => 'DESC',
		)
	);
};

$forget = static function (): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The counts of reports by address, which a rerun inside ten minutes would trip over.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_packout_report_' ) . '%', $wpdb->esc_like( '_transient_timeout_packout_report_' ) . '%' ) );
	wp_cache_flush();
};

try {
	$forget();
	$page            = (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Reporttest ' . $packout_run,
			'post_name'    => 'reporttest-' . $packout_run,
			'post_content' => '<!-- wp:packout/report-prompt {"heading":"Report a Spot","showForm":true} /-->',
		)
	);
	$packout_posts[] = $page;
	$url             = (string) get_permalink( $page );
	$what            = static fn( string $thing ): string => 'Reporttest ' . $packout_run . ' ' . $thing;
	$report          = static fn( array $hidden, array $more = array() ): array => $hidden + array(
		Report_Form::FIELD => $more + array(
			'category'    => 'cleanup-waste',
			'description' => 'Reporttest ' . $packout_run . ' tires.',
			'landmark'    => 'Mile 9 turnout',
			'latitude'    => '',
			'longitude'   => '',
			'name'        => '',
		),
	);

	/* ---------- The form ---------- */

	WP_CLI::log( 'The form a visitor is shown' );
	$got  = $ask( $url );
	$form = $got[2];
	$keys = $hidden( $form );
	packout_partner_check( 'the page opens with the form on it', 200 === $got[0] && false !== strpos( $form, '<form class="packout-form" id="packout-report" method="post" enctype="multipart/form-data"' ), $got[0] );
	packout_partner_check( 'it asks what the hub asks: the kind, what is there, where, a photo, and coordinates', 1 === preg_match( '#<select[^>]+name="packout_report\[category\]" required>#', $form ) && false !== strpos( $form, 'name="packout_report[description]"' ) && false !== strpos( $form, 'name="packout_report[landmark]"' ) && false !== strpos( $form, 'name="' . Report_Form::PHOTO . '"' ) && false !== strpos( $form, 'name="packout_report[latitude]"' ) && false !== strpos( $form, 'name="packout_report[longitude]"' ) );
	packout_partner_check( 'every category is offered', count( Schema::categories() ) === substr_count( $form, '<option value="' ) - 1 - substr_count( $form, 'name="packout_credit_team"' ), substr_count( $form, '<option value="' ) );
	packout_partner_check( 'it asks a visitor\'s name and no email address', false !== strpos( $form, 'name="packout_report[name]"' ) && false === strpos( $form, 'type="email"' ) && false === stripos( $form, 'packout_report[email]' ) );
	packout_partner_check( 'it asks whether to show the name, and offers a visitor no organization', false !== strpos( $form, 'name="packout_credit_named"' ) && false === strpos( $form, 'name="packout_credit_team"' ) );
	packout_partner_check( 'it carries a nonce, the time it was drawn, and a field a person never sees', '' !== $keys[ Report_Form::FIELD . '_nonce' ] && 1 === preg_match( '#^\d+\.[0-9a-f]+$#', $keys[ Report_Form::FIELD . '_opened' ] ) && 1 === preg_match( '#<p class="packout-form__website" aria-hidden="true">.*?tabindex="-1"#s', $form ) );
	packout_partner_check( 'each field with help is tied to it', false !== strpos( $form, 'aria-describedby="packout-report-description-help"' ) && false !== strpos( $form, 'id="packout-report-description-help"' ) );
	packout_partner_check( 'the script that places the pin is told where the fields are', 1 === preg_match( '#data-pos-locate="([^"]+)"#', $form, $match ) && is_array( json_decode( html_entity_decode( $match[1] ), true ) ) && 'input[name="packout_report[latitude]"]' === json_decode( html_entity_decode( $match[1] ), true )['latitude'] && '.packout-form__pair' === json_decode( html_entity_decode( $match[1] ), true )['row'] );
	packout_partner_check( 'the form\'s style sheet and the pin script are loaded', false !== strpos( $form, 'assets/css/report-form.css' ) && 1 === preg_match( '#<script[^>]+src="[^"]*/report[^"]*\.js#', $form ) );
	packout_partner_check( 'the page draws no PHP warning', false === stripos( $form, '<b>Warning</b>' ) && false === stripos( $form, '<b>Notice</b>' ) && false === stripos( $form, '<b>Deprecated</b>' ) );

	/* ---------- What a script sends ---------- */

	WP_CLI::log( 'What a script sends' );
	$got = $ask( $url, $report( $keys ) );
	packout_partner_check( 'a report sent the moment the form loads is told what a person is told', 303 === $got[0] && false !== strpos( $got[1], 'packout_reported=1' ), array( $got[0], $got[1] ) );
	packout_partner_check( 'and becomes nothing', array() === $spots() );

	// A person takes a few seconds to write a report.
	sleep( 4 );
	$got = $ask( $url, array( 'packout_report_website' => 'https://example.test/' ) + $report( $keys ) );
	packout_partner_check( 'so is one that fills in the field nobody sees', 303 === $got[0] && array() === $spots(), $got[0] );
	$got = $ask( $url, array( Report_Form::FIELD . '_opened' => ( time() - 60 ) . '.' . str_repeat( 'a', 32 ) ) + $report( $keys ) );
	packout_partner_check( 'and one that writes its own time', 303 === $got[0] && array() === $spots(), $got[0] );
	$got = $ask( $url, array( Report_Form::FIELD . '_nonce' => 'abcdef1234' ) + $report( $keys, array( 'description' => $what( 'kept words.' ) ) ) );
	packout_partner_check( 'a report with no good nonce is refused, and says why', 200 === $got[0] && false !== strpos( $got[2], 'role="alert"' ) && false !== strpos( $got[2], 'open too long' ) && array() === $spots(), $got[0] );
	packout_partner_check( 'what the person wrote is still in the form', false !== strpos( $got[2], esc_textarea( $what( 'kept words.' ) ) . '</textarea>' ) && false !== strpos( $got[2], 'value="Mile 9 turnout"' ) && 1 === preg_match( '#<option value="cleanup-waste"\s+selected#', $got[2] ) );

	/* ---------- A visitor's report ---------- */

	WP_CLI::log( 'A visitor\'s report' );
	$got = $ask( $url, $report( $keys, array( 'landmark' => '' ) ) );
	packout_partner_check( 'a report that does not say where is refused, and says what is needed', 200 === $got[0] && false !== strpos( $got[2], 'where it is' ) && array() === $spots(), $got[0] );

	$count = (int) get_option( Report_Form::COUNT, 0 );
	$got   = $ask(
		$url,
		$report(
			$keys,
			array(
				'description' => $what( 'tires at the turnout.' ),
				'name'        => 'Val Visitor',
				'latitude'    => '34.7102',
				'longitude'   => '-111.6511',
			)
		)
	);
	$made  = $spots();
	packout_partner_check( 'a complete report is taken, and the person is sent back to the page', 303 === $got[0] && untrailingslashit( $url ) === untrailingslashit( (string) strtok( $got[1], '?' ) ) && false !== strpos( $got[1], 'packout_reported=1#packout-report' ), array( $got[0], $got[1] ) );
	packout_partner_check( 'it is one Spot, waiting for review', 1 === count( $made ) && 'pending' === $made[0]->post_status && $what( 'tires at the turnout.' ) === $made[0]->post_content, wp_list_pluck( $made, 'post_status' ) );
	$first = $made ? $made[0]->ID : 0;
	packout_partner_check( 'it has its kind, its landmark, and its pin', has_term( 'cleanup-waste', Schema::CATEGORY, $first ) && 'Mile 9 turnout' === Spots::field( $first, 'landmark' ) && 34.7102 === (float) Spots::field( $first, 'lat' ) && -111.6511 === (float) Spots::field( $first, 'lng' ) );
	packout_partner_check( 'nobody is its author', 0 === (int) $made[0]->post_author );
	packout_partner_check( 'a name that was typed and not asked to be shown is not kept', '' === (string) get_post_meta( $first, '_pos_reporter_name', true ) && false === Spots::credit( $first )['named'] );
	packout_partner_check( 'the report is numbered, one more than the last', (int) get_option( Report_Form::COUNT ) === $count + 1 && (int) get_post_meta( $first, '_pos_entry_id', true ) === $count + 1, array( $count, get_option( Report_Form::COUNT ), get_post_meta( $first, '_pos_entry_id', true ) ) );
	packout_partner_check( 'the public does not see it yet', 'public' !== Spots::view_for( get_post( $first ), 0 ) );

	$got = $ask( $got[1] );
	packout_partner_check( 'the page thanks the person and says what happens next', 200 === $got[0] && false !== strpos( $got[2], 'packout-form--done' ) && false !== strpos( $got[2], 'review queue' ) && false === strpos( $got[2], 'name="packout_report[description]"' ), $got[0] );

	$got  = $ask( $url, array( 'packout_credit_named' => '1' ) + $report( $keys, array( 'description' => $what( 'a fire ring.' ), 'name' => 'Val Visitor' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Two short values.
	$made = $spots();
	packout_partner_check( 'a visitor who asks to be named is', 303 === $got[0] && 2 === count( $made ) && 'Val Visitor' === get_post_meta( $made[0]->ID, '_pos_reporter_name', true ) && true === Spots::credit( $made[0]->ID )['named'], array( $got[0], count( $made ) ) );
	packout_partner_check( 'a report with no pin is taken with its landmark alone', '' === (string) Spots::field( $made[0]->ID, 'lat' ) && 'Mile 9 turnout' === Spots::field( $made[0]->ID, 'landmark' ) );

	/* ---------- A photo ---------- */

	WP_CLI::log( 'A photo' );
	$picture = imagecreatetruecolor( 640, 480 );
	imagefilledrectangle( $picture, 0, 0, 639, 479, imagecolorallocate( $picture, 180, 120, 60 ) );
	ob_start();
	imagejpeg( $picture, null, 85 );
	$jpeg = (string) ob_get_clean();
	$got  = $ask(
		$url,
		$report( $keys, array( 'description' => $what( 'a dumped couch.' ) ) ),
		array(
			'name' => 'couch.jpg',
			'type' => 'image/jpeg',
			'body' => $jpeg,
		)
	);
	$made = $spots();
	packout_partner_check( 'a report with a photo is taken', 303 === $got[0] && 3 === count( $made ), array( $got[0], count( $made ), substr( wp_strip_all_tags( $got[2] ), 0, 300 ) ) );
	$photos = $made ? Photos::for_spot( $made[0]->ID ) : array();
	packout_partner_check( 'the photo is the Spot\'s', 1 === count( $photos ), $photos );
	foreach ( $photos as $photo ) {
		$packout_files[] = (int) $photo['id'];
	}
	$got = $ask(
		$url,
		$report( $keys, array( 'description' => $what( 'a text file.' ) ) ),
		array(
			'name' => 'notes.jpg',
			'type' => 'image/jpeg',
			'body' => 'This is not a photo.',
		)
	);
	packout_partner_check( 'a file that is not a photo is refused, and no Spot is made', 200 === $got[0] && false !== strpos( $got[2], 'not a photo this site can read' ) && 3 === count( $spots() ), array( $got[0], count( $spots() ) ) );
	packout_partner_check( 'the page says how large a photo may be', false !== strpos( $form, esc_html( Photos::hint() ) ) );

	/* ---------- A person who is signed in ---------- */

	WP_CLI::log( 'A person who is signed in' );
	$login           = 'pos-test-rae-' . $packout_run;
	$rae             = (int) wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => $login . '@example.test',
			'display_name' => 'Rae Reporter',
			'role'         => 'pos_lead',
		)
	);
	$packout_users[] = $rae;
	$admins          = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	$team            = Teams::save(
		0,
		array(
			'name'   => 'Reporttest Crew ' . $packout_run,
			'lead'   => $rae,
			'listed' => true,
		),
		(int) $admins[0]
	);
	if ( is_wp_error( $team ) ) {
		WP_CLI::error( 'Organization: ' . $team->get_error_message() );
	}
	$packout_posts[] = (int) $team;
	$expires         = time() + HOUR_IN_SECONDS;
	$token           = WP_Session_Tokens::get_instance( $rae )->create( $expires );
	$cookies         = array( new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $rae, $expires, 'logged_in', $token ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One cookie.

	$got    = $ask( $url, null, array(), $cookies );
	$theirs = $hidden( $got[2] );
	packout_partner_check( 'their form does not ask their name', 200 === $got[0] && false === strpos( $got[2], 'name="packout_report[name]"' ) && false !== strpos( $got[2], 'name="packout_report[description]"' ), $got[0] );
	packout_partner_check( 'it offers their organization, or themself', 1 === preg_match( '#<select[^>]+name="packout_credit_team">.*?<option value="' . (int) $team . '"#s', $got[2] ) && false !== strpos( $got[2], 'Myself, not an organization' ) );
	packout_partner_check( 'their nonce is their own', '' !== $theirs[ Report_Form::FIELD . '_nonce' ] && $theirs[ Report_Form::FIELD . '_nonce' ] !== $keys[ Report_Form::FIELD . '_nonce' ] );
	$got = $ask( $url, $report( $keys, array( 'description' => $what( 'a visitor\'s nonce.' ) ) ), array(), $cookies );
	packout_partner_check( 'a visitor\'s nonce does not send their report', 200 === $got[0] && 3 === count( $spots() ), $got[0] );

	// Their form was drawn a moment ago. The visitor's was drawn long enough ago, and its time is as good.
	$theirs[ Report_Form::FIELD . '_opened' ] = $keys[ Report_Form::FIELD . '_opened' ];
	$got                                      = $ask( $url, array( 'packout_credit_team' => (string) $team ) + $report( $theirs, array( 'description' => $what( 'cans for the crew.' ) ) ), array(), $cookies );
	$made                                     = $spots();
	packout_partner_check( 'their report is theirs, and is credited to the organization they chose', 303 === $got[0] && 4 === count( $made ) && $rae === (int) $made[0]->post_author && Spots::credit( $made[0]->ID )['team'] === (int) $team, array( $got[0], count( $made ) ) );
	$got  = $ask( $url, array( 'packout_credit_team' => '0', 'packout_credit_named' => '1' ) + $report( $theirs, array( 'description' => $what( 'cans for myself.' ) ) ), array(), $cookies ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Two short values.
	$made = $spots();
	packout_partner_check( 'or to themself by name', 303 === $got[0] && 5 === count( $made ) && 0 === Spots::credit( $made[0]->ID )['team'] && true === Spots::credit( $made[0]->ID )['named'], array( $got[0], count( $made ) ) );
	packout_partner_check( 'and to no organization they are not in', 303 === $ask( $url, array( 'packout_credit_team' => (string) $page ) + $report( $theirs, array( 'description' => $what( 'cans for a stranger.' ) ) ), array(), $cookies )[0] && 0 === Spots::credit( $spots()[0]->ID )['team'] );

	/* ---------- Many reports from one address ---------- */

	WP_CLI::log( 'Many reports from one address' );
	// Six have been taken from this address: three as a visitor, three signed in. A visitor may send five in ten minutes.
	$got = $ask( $url, $report( $keys, array( 'description' => $what( 'one too many.' ) ) ) );
	packout_partner_check( 'a visitor\'s next report is refused, and says to wait', 200 === $got[0] && false !== strpos( $got[2], 'Wait ten minutes' ) && 6 === count( $spots() ), array( $got[0], count( $spots() ) ) );
	packout_partner_check( 'what they wrote is kept in the form', false !== strpos( $got[2], esc_textarea( $what( 'one too many.' ) ) ) );
	$got = $ask( $url, $report( $theirs, array( 'description' => $what( 'one more, signed in.' ) ) ), array(), $cookies );
	packout_partner_check( 'a person who is signed in may send more', 303 === $got[0] && 7 === count( $spots() ), array( $got[0], count( $spots() ) ) );
	packout_partner_check( 'every report has its own number', 7 === count( array_unique( array_map( static fn( $post ) => (int) get_post_meta( $post->ID, '_pos_entry_id', true ), $spots() ) ) ) && (int) get_option( Report_Form::COUNT ) === $count + 7 );
	packout_partner_check(
		'the address itself is stored nowhere',
		( static function (): bool {
			global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Whether any stored value holds an address of this machine.
			return 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%packout_report_%' AND ( option_value LIKE '%127.0.0.1%' OR option_value LIKE '%172.%' OR option_name LIKE '%127.0.0.1%' )" );
		} )()
	);
} catch ( \Throwable $e ) {
	packout_partner_check( $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine(), false );
} finally {
	wp_cache_flush();
	foreach ( $spots() as $spot ) {
		foreach ( Photos::for_spot( $spot->ID ) as $photo ) {
			wp_delete_attachment( (int) $photo['id'], true );
		}
		wp_delete_post( $spot->ID, true );
	}
	foreach ( $packout_posts as $post_id ) {
		if ( Schema::TEAM === get_post_type( $post_id ) ) {
			delete_option( 'packout_messages_' . $post_id );
		}
		wp_delete_post( $post_id, true );
	}
	foreach ( $packout_users as $user_id ) {
		WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		wp_delete_user( $user_id );
	}
	$forget();
}

packout_partner_finish();
