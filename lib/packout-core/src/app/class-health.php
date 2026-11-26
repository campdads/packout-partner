<?php
/**
 * How a partner site stands: what it needs to run, and whether what it sends is getting through.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One list of checks, read by the setup wizard, by Site Health, and by the
 * `wp packout` commands, so the three never disagree.
 *
 * A check reads what the site already knows. None of them makes a request to
 * another server, so Site Health stays quick and nothing is sent by looking.
 */
class Health {

	/**
	 * The option that keeps how the last test email went: when, and whether WordPress took it. Never the address.
	 */
	public const MAIL = 'packout_health_mail';

	/**
	 * How old the kept copy of the hub's map may be before it is called stale, in seconds.
	 */
	public const STALE = 6 * HOUR_IN_SECONDS;

	/**
	 * How many tries make a send count as failing.
	 */
	public const TRIES = 3;

	/**
	 * Add the checks to Site Health. A partner site only.
	 */
	public static function register(): void {
		if ( App::is_hub() ) {
			return;
		}
		add_filter( 'site_status_tests', array( self::class, 'tests' ) );
		add_filter( 'debug_information', array( self::class, 'info' ) );
	}

	/**
	 * Every check.
	 *
	 * `status` is `good`, `recommended`, or `critical`, as Site Health names them.
	 *
	 * @return array<string, array{id: string, group: string, label: string, status: string, detail: string, url: string, action: string}>
	 */
	public static function checks(): array {
		$rows = array();
		$add  = static function ( string $id, string $group, string $label, string $status, string $detail, string $url = '', string $action = '' ) use ( &$rows ): void {
			$rows[ $id ] = array(
				'id'     => $id,
				'group'  => $group,
				'label'  => $label,
				'status' => $status,
				'detail' => $detail,
				'url'    => $url,
				'action' => $action,
			);
		};

		// What the site needs to run.
		$https = Connection::secure( home_url() );
		$add( 'https', 'site', __( 'HTTPS', 'packout-core' ), $https ? 'good' : 'recommended', $https ? __( 'This site\'s address begins with https.', 'packout-core' ) : __( 'This site\'s address does not begin with https. Everything works here, but the site cannot connect to packout.spot until it does.', 'packout-core' ) );

		$pretty = '' !== (string) get_option( 'permalink_structure' );
		$add( 'permalinks', 'site', __( 'Permalinks', 'packout-core' ), $pretty ? 'good' : 'critical', $pretty ? __( 'Permalinks are on.', 'packout-core' ) : __( 'Permalinks are set to Plain. The pages of Spots, organizations, and missions need any other setting.', 'packout-core' ), admin_url( 'options-permalink.php' ), __( 'Permalink settings', 'packout-core' ) );

		$cron = ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
		$add( 'cron', 'site', __( 'Scheduled tasks', 'packout-core' ), $cron ? 'good' : 'recommended', $cron ? __( 'WordPress runs its scheduled tasks when the site is visited.', 'packout-core' ) : __( 'WP-Cron is turned off in this site\'s configuration. That is fine when the server calls wp-cron.php itself, every five minutes or more often. If nothing does, reminders are not sent and nothing reaches packout.spot.', 'packout-core' ) );

		$sodium = function_exists( 'sodium_crypto_sign_detached' ) && function_exists( 'sodium_crypto_sign_verify_detached' );
		$add( 'sodium', 'site', __( 'Signatures', 'packout-core' ), $sodium ? 'good' : 'critical', $sodium ? __( 'This server can sign what it sends and check the signature of an update.', 'packout-core' ) : __( 'This server cannot sign or check signatures (sodium is missing). Updates cannot be checked and the site cannot connect.', 'packout-core' ) );

		$webp = function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		$add( 'webp', 'site', __( 'Photos', 'packout-core' ), $webp ? 'good' : 'recommended', $webp ? __( 'This server can make WebP copies of photos.', 'packout-core' ) : __( 'This server cannot make WebP images. Photos are kept as they are uploaded, which is larger, and copies sent to packout.spot may be refused for their size.', 'packout-core' ) );

		$limit  = (int) wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$enough = $limit <= 0 || $limit >= 128 * MB_IN_BYTES;
		/* translators: %s: an amount of memory, such as 64 MB. */
		$add( 'memory', 'site', __( 'Memory', 'packout-core' ), $enough ? 'good' : 'recommended', $enough ? __( 'PHP has enough memory for photos and reports.', 'packout-core' ) : sprintf( __( 'PHP is limited to %s of memory. Large photos and the PDF report may fail. 128 MB or more is better.', 'packout-core' ), size_format( $limit ) ) );

		$mail = get_option( self::MAIL, array() );
		$mail = is_array( $mail ) ? $mail : array();
		if ( empty( $mail['at'] ) ) {
			$add( 'mail', 'site', __( 'Email', 'packout-core' ), 'recommended', __( 'No test email has been sent. Invitations, sign-up confirmations, and reminders all go by email.', 'packout-core' ), admin_url( 'admin.php?page=' . Wizard::PAGE . '&step=check' ), __( 'Send a test email', 'packout-core' ) );
		} else {
			$when = human_time_diff( (int) $mail['at'] );
			$add(
				'mail',
				'site',
				__( 'Email', 'packout-core' ),
				empty( $mail['ok'] ) ? 'critical' : 'good',
				empty( $mail['ok'] )
					/* translators: %s: how long ago, such as 5 mins. */
					? sprintf( __( 'A test email %s ago was refused by this site\'s mail setup. Invitations and confirmations will not arrive.', 'packout-core' ), $when )
					/* translators: %s: how long ago, such as 5 mins. */
					: sprintf( __( 'A test email was handed to this site\'s mail setup %s ago. Check that it arrived.', 'packout-core' ), $when ),
				admin_url( 'admin.php?page=' . Wizard::PAGE . '&step=check' ),
				__( 'Send a test email', 'packout-core' )
			);
		}

		$partner = Partners::site();
		$add( 'partner', 'site', __( 'Partner record', 'packout-core' ), $partner > 0 ? 'good' : 'critical', $partner > 0 ? __( 'This site has its partner record.', 'packout-core' ) : __( 'This site has no partner record. Deactivate the plugin and activate it again to make one.', 'packout-core' ) );

		$missing = array();
		foreach ( Pages::states() as $key => $state ) {
			if ( 'missing' === $state['state'] || ( 'yours' === $state['state'] && ! $state['has_block'] ) ) {
				$missing[] = Pages::templates()[ $key ]['title'];
			}
		}
		$add(
			'pages',
			'site',
			__( 'Pages', 'packout-core' ),
			$missing ? 'recommended' : 'good',
			$missing
				/* translators: %s: a list of page titles. */
				? sprintf( __( 'These pages are missing or do not carry their block: %s.', 'packout-core' ), implode( ', ', $missing ) )
				: __( 'Every page the plugin was asked for is there.', 'packout-core' ),
			admin_url( 'admin.php?page=' . Pages_Screen::PAGE ),
			__( 'Pages', 'packout-core' )
		);

		// Whether what the site sends is getting through.
		$state      = Connection::state();
		$connection = admin_url( 'admin.php?page=' . Connection_Screen::PAGE );
		$open       = __( 'Connection', 'packout-core' );
		if ( ! Connection::connected() ) {
			$add( 'connection', 'sync', __( 'Connection to packout.spot', 'packout-core' ), 'good', __( 'This site is not connected to packout.spot. It works in full on its own, and nothing is sent.', 'packout-core' ), $connection, $open );
		} elseif ( ! Connection::at_home() ) {
			$add( 'connection', 'sync', __( 'Connection to packout.spot', 'packout-core' ), 'critical', __( 'This site connected at another address and sends nothing until you say whether it moved or is a copy.', 'packout-core' ), $connection, $open );
		} elseif ( 'suspended' === $state['state'] ) {
			$add( 'connection', 'sync', __( 'Connection to packout.spot', 'packout-core' ), 'critical', trim( __( 'packout.spot has suspended this partner. Nothing is listed there.', 'packout-core' ) . ' ' . $state['reason'] ), $connection, $open );
		} elseif ( '' !== $state['terms_required'] ) {
			$add( 'connection', 'sync', __( 'Connection to packout.spot', 'packout-core' ), 'critical', __( 'The partner terms have changed. This site sends nothing until an administrator accepts them.', 'packout-core' ), $connection, $open );
		} elseif ( '' !== $state['minimum'] && version_compare( Connection::versions()['plugin_version'], $state['minimum'], '<' ) ) {
			/* translators: 1: the oldest version packout.spot accepts, 2: the version this site has. */
			$add( 'connection', 'sync', __( 'Connection to packout.spot', 'packout-core' ), 'critical', sprintf( __( 'packout.spot accepts version %1$s of this plugin or later, and this site has %2$s. Update the plugin: until then nothing is sent.', 'packout-core' ), $state['minimum'], Connection::versions()['plugin_version'] ), admin_url( 'plugins.php' ), __( 'Plugins', 'packout-core' ) );
		} elseif ( in_array( $state['state'], array( 'registered', 'pending' ), true ) ) {
			$add( 'connection', 'sync', __( 'Connection to packout.spot', 'packout-core' ), 'recommended', 'registered' === $state['state'] ? __( 'Connecting is not finished: the confirmation link has not been opened, or packout.spot has not yet reached this site.', 'packout-core' ) : __( 'This site is waiting for a coordinator at packout.spot to approve it.', 'packout-core' ), $connection, $open );
		} elseif ( '' !== $state['error'] ) {
			$add( 'connection', 'sync', __( 'Connection to packout.spot', 'packout-core' ), 'recommended', trim( __( 'The last request to packout.spot did not go through.', 'packout-core' ) . ' ' . $state['error'] ), $connection, $open );
		} else {
			$add( 'connection', 'sync', __( 'Connection to packout.spot', 'packout-core' ), 'good', __( 'This site is connected to packout.spot.', 'packout-core' ), $connection, $open );
		}

		if ( Connection::connected() ) {
			$failing = array_values( array_filter( Outbox::rows( 200 ), static fn( array $row ): bool => $row['attempts'] >= self::TRIES ) );
			$waiting = Outbox::waiting();
			$timer   = (bool) wp_next_scheduled( Outbox::RUN );
			if ( $failing ) {
				$add(
					'queue',
					'sync',
					__( 'Sending to packout.spot', 'packout-core' ),
					'critical',
					sprintf(
						/* translators: 1: a number, 2: the reason the last one failed. */
						_n( '%1$s thing could not be sent after several tries. It stays queued. The last reason: %2$s', '%1$s things could not be sent after several tries. They stay queued. The last reason: %2$s', count( $failing ), 'packout-core' ),
						number_format_i18n( count( $failing ) ),
						'' !== $failing[0]['last_error'] ? $failing[0]['last_error'] : __( 'none was given.', 'packout-core' )
					),
					$connection,
					$open
				);
			} elseif ( Connection::sending() && ! $timer ) {
				$add( 'queue', 'sync', __( 'Sending to packout.spot', 'packout-core' ), 'critical', __( 'The task that sends to packout.spot is not scheduled. Open the Connection screen once to schedule it again.', 'packout-core' ), $connection, $open );
			} else {
				$add(
					'queue',
					'sync',
					__( 'Sending to packout.spot', 'packout-core' ),
					'good',
					$state['sent'] > 0
						/* translators: 1: how long ago, 2: a number. */
						? sprintf( __( 'Last sent %1$s ago. Waiting to go: %2$s.', 'packout-core' ), human_time_diff( $state['sent'] ), number_format_i18n( $waiting ) )
						/* translators: %s: a number. */
						: sprintf( __( 'Nothing has been sent yet. Waiting to go: %s.', 'packout-core' ), number_format_i18n( $waiting ) ),
					$connection,
					$open
				);
			}//end if

			if ( Hub_Layers::on() ) {
				$fetched = Hub_Layers::data()['fetched'];
				$fresh   = $fetched > 0 && time() - $fetched < self::STALE;
				$add(
					'layers',
					'sync',
					__( 'packout.spot on this site\'s map', 'packout-core' ),
					$fresh ? 'good' : 'recommended',
					$fresh
						/* translators: %s: how long ago. */
						? sprintf( __( 'Last read %s ago.', 'packout-core' ), human_time_diff( $fetched ) )
						: ( $fetched > 0
							/* translators: %s: how long ago. */
							? sprintf( __( 'What the map shows of packout.spot was last read %s ago, so it may be out of date. packout.spot could not be reached since.', 'packout-core' ), human_time_diff( $fetched ) )
							: __( 'packout.spot\'s Spots have not been read yet.', 'packout-core' ) ),
					$connection,
					$open
				);
			}
		}//end if

		/**
		 * The checks of a partner site, for the plugin to add its own.
		 *
		 * @param array<string, array<string, string>> $rows Each with id, group, label, status, detail, url, action.
		 */
		$rows = apply_filters( 'packout_health_checks', $rows );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The worst a list of checks says: good, recommended, or critical.
	 *
	 * @param array<string, array<string, string>>|null $rows Checks, or all of them.
	 */
	public static function worst( ?array $rows = null ): string {
		$statuses = array_column( $rows ?? self::checks(), 'status' );
		return in_array( 'critical', $statuses, true ) ? 'critical' : ( in_array( 'recommended', $statuses, true ) ? 'recommended' : 'good' );
	}

	/**
	 * Send the administrator a test email and remember how it went.
	 *
	 * @param int $user_id The administrator. The email goes to their own address.
	 * @return true|\WP_Error
	 */
	public static function test_mail( int $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'An administrator sends the test email.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$site = (string) get_bloginfo( 'name' );
		$went = App::kernel()->mailer()->send(
			$user->user_email,
			/* translators: %s: the site's name. */
			sprintf( __( 'A test email from %s', 'packout-core' ), $site ),
			'<p>' . esc_html(
				sprintf(
					/* translators: %s: the site's name. */
					__( 'This is the test email you asked for from the setup of Pack-Out Spot on %s. If you are reading it, the site can send email.', 'packout-core' ),
					$site
				)
			) . '</p>'
		);
		update_option(
			self::MAIL,
			array(
				'at' => time(),
				'ok' => (bool) $went,
			),
			false
		);
		return $went ? true : new \WP_Error( 'packout_mail', __( 'This site\'s mail setup refused the test email. Ask your host how the site sends mail, or add an SMTP plugin.', 'packout-core' ), array( 'status' => 502 ) );
	}

	/**
	 * Site Health's tests.
	 *
	 * @param mixed $tests The tests so far.
	 * @return mixed
	 */
	public static function tests( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		foreach ( self::checks() as $id => $row ) {
			$tests['direct'][ 'packout_' . $id ] = array(
				/* translators: %s: what is checked. */
				'label' => sprintf( __( 'Pack-Out Spot: %s', 'packout-core' ), $row['label'] ),
				'test'  => static fn(): array => self::result( $id ),
			);
		}
		return $tests;
	}

	/**
	 * One check, as Site Health shows it.
	 *
	 * @param string $id Check id.
	 * @return array<string, mixed>
	 */
	public static function result( string $id ): array {
		$row = self::checks()[ $id ] ?? null;
		if ( ! $row ) {
			return array();
		}
		$first = strtok( $row['detail'], "\n" );
		return array(
			/* translators: 1: what is checked, 2: what was found. */
			'label'       => 'good' === $row['status'] ? sprintf( __( 'Pack-Out Spot: %s', 'packout-core' ), $row['label'] ) : sprintf( __( 'Pack-Out Spot, %1$s: %2$s', 'packout-core' ), $row['label'], mb_strimwidth( (string) $first, 0, 110, '…' ) ),
			'status'      => $row['status'],
			'badge'       => array(
				'label' => __( 'Pack-Out Spot', 'packout-core' ),
				'color' => 'critical' === $row['status'] ? 'red' : ( 'recommended' === $row['status'] ? 'orange' : 'blue' ),
			),
			'description' => '<p>' . esc_html( $row['detail'] ) . '</p>',
			'actions'     => '' !== $row['url'] ? '<p><a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['action'] ) . '</a></p>' : '',
			'test'        => 'packout_' . $id,
		);
	}

	/**
	 * What the site would tell a person helping with it. Nothing private: no key, no email address, no record.
	 *
	 * @return array<string, string>
	 */
	public static function facts(): array {
		$site   = App::kernel()->site();
		$state  = Connection::state();
		$states = Connection_Screen::states();
		$layers = Hub_Layers::settings();
		$when   = static fn( int $time ): string => $time > 0 ? gmdate( 'Y-m-d H:i', $time ) . ' UTC' : __( 'Never', 'packout-core' );
		$facts  = array(
			__( 'Plugin version', 'packout-core' )     => $site->version(),
			__( 'Library version', 'packout-core' )    => defined( 'PACKOUT_CORE_VERSION' ) ? (string) PACKOUT_CORE_VERSION : '',
			__( 'Connection', 'packout-core' )         => $states[ $state['state'] ] ?? $state['state'],
			__( 'Hub', 'packout-core' )                => Connection::connected() ? $state['hub'] : '',
			__( 'Terms accepted', 'packout-core' )     => $state['terms'],
			__( 'Last asked the hub', 'packout-core' ) => $when( $state['checked'] ),
			__( 'Last sent', 'packout-core' )          => $when( $state['sent'] ),
			__( 'Waiting to be sent', 'packout-core' ) => (string) ( Connection::connected() ? Outbox::waiting() : 0 ),
			__( 'Hub map layers', 'packout-core' )     => $layers['on'] && $layers['box'] ? implode( ',', $layers['box'] ) : __( 'Off', 'packout-core' ),
			__( 'Hub map last read', 'packout-core' )  => $when( Hub_Layers::data()['fetched'] ),
			// Whether there is one, and never the key.
			__( 'Signing key', 'packout-core' )        => Connection::key() ? __( 'Present (never shown)', 'packout-core' ) : __( 'No key', 'packout-core' ),
		);
		foreach ( self::checks() as $row ) {
			$facts[ $row['label'] ] = $row['status'];
		}
		return $facts;
	}

	/**
	 * Site Health's Info tab.
	 *
	 * @param mixed $info The sections so far.
	 * @return mixed
	 */
	public static function info( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}
		$fields = array();
		$number = 0;
		foreach ( self::facts() as $label => $value ) {
			$fields[ 'packout_' . ( ++$number ) ] = array(
				'label' => $label,
				'value' => '' !== $value ? $value : '—',
			);
		}
		$info['packout'] = array(
			'label'  => __( 'Pack-Out Spot', 'packout-core' ),
			'fields' => $fields,
		);
		return $info;
	}
}
