<?php
/**
 * The Connection screen of a partner site.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where an administrator of a partner site connects it to packout.spot,
 * sees how the connection stands, and ends it.
 *
 * The screen says what is sent and what never is before anything is sent.
 * Nothing on it is needed to use the site: a site that is never connected
 * works in full.
 */
class Connection_Screen {

	public const PAGE = 'packout-connection';

	/**
	 * Add the screen and its form handler. A partner site only.
	 */
	public static function register(): void {
		if ( App::is_hub() ) {
			return;
		}
		// After the application's own screens, so Connection is the last item of the menu.
		add_action( 'admin_menu', array( self::class, 'menu' ), 11 );
		add_action( 'admin_post_packout_connection_action', array( self::class, 'action' ) );
	}

	/**
	 * Add the menu item.
	 */
	public static function menu(): void {
		Admin::add_screen( (string) add_submenu_page( 'packout-spot', __( 'Connection', 'packout-core' ), __( 'Connection', 'packout-core' ), 'manage_options', self::PAGE, array( self::class, 'render' ) ) );
	}

	/**
	 * What the states are called.
	 *
	 * @return array<string, string>
	 */
	public static function states(): array {
		return array(
			''             => __( 'Not connected', 'packout-core' ),
			'registered'   => __( 'Connecting', 'packout-core' ),
			'pending'      => __( 'Waiting for approval', 'packout-core' ),
			'active'       => __( 'Connected', 'packout-core' ),
			'dormant'      => __( 'Connected', 'packout-core' ),
			'suspended'    => __( 'Suspended', 'packout-core' ),
			'disconnected' => __( 'Disconnected', 'packout-core' ),
		);
	}

	/**
	 * Draw the screen.
	 */
	public static function render(): void {
		Admin::require( current_user_can( 'manage_options' ) );
		$state = Connection::state();
		$name  = self::partner_name();

		echo '<div class="wrap packout-admin packout-connection" style="' . esc_attr( Admin::palette() ) . '">';
		echo '<h1>' . esc_html__( 'Connection to packout.spot', 'packout-core' ) . '</h1>';
		Admin::notice(
			array(
				'connected'    => __( 'This site is registered with packout.spot. A confirmation link was sent to your email address.', 'packout-core' ),
				'checked'      => __( 'packout.spot was asked how the connection stands.', 'packout-core' ),
				'proved'       => __( 'packout.spot reached this site and confirmed its address.', 'packout-core' ),
				'sent'         => __( 'Everything waiting was sent.', 'packout-core' ),
				'queued'       => __( 'Everything public on this site is queued to be sent again.', 'packout-core' ),
				'terms'        => __( 'The terms are accepted, and this site is sending again.', 'packout-core' ),
				'key'          => __( 'This site has a new key. The old one no longer works.', 'packout-core' ),
				'disconnected' => __( 'This site is disconnected. Its records are as they were.', 'packout-core' ),
				'layers'       => __( 'Saved.', 'packout-core' ),
				'forgotten'    => __( 'This copy of the site has forgotten the connection. packout.spot was not told, and the site at the address it knows is still connected.', 'packout-core' ),
				'claimed'      => __( 'packout.spot has the request. The lead of the organization there is asked by email, and the answer shows here.', 'packout-core' ),
				'unclaimed'    => __( 'Done. packout.spot was told.', 'packout-core' ),
				'moved_here'   => __( 'packout.spot reached this site at its new address. Everything public here is queued to be sent again, so every link there leads here.', 'packout-core' ),
				'recover'      => __( 'If packout.spot knows this site, it has sent a link to the administrator who connected it. Open the link, and then come back here to finish.', 'packout-core' ),
				'recovered'    => __( 'This site has a new key, and packout.spot has confirmed its address. It is sending again.', 'packout-core' ),
			),
			array(
				'terms_unticked' => __( 'Tick the box to say you accept the partner terms.', 'packout-core' ),
				'unsure'         => __( 'Nothing was done. Tick the box to say you mean to disconnect this site, and press the button again.', 'packout-core' ),
				'some_failed'    => __( 'Some of what was waiting could not be sent. It stays queued and is tried again. The table below says why.', 'packout-core' ),
			)
		);

		if ( Connection::lost() ) {
			self::lost( $state );
		} elseif ( ! Connection::connected() ) {
			self::offer( $state, $name );
		} elseif ( ! Connection::at_home() ) {
			self::moved( $state );
		} else {
			self::standing( $state, $name );
		}
		echo '</div>';
	}

	/**
	 * The name of the site's partner.
	 */
	private static function partner_name(): string {
		$partner = get_post( Partners::site() );
		return $partner instanceof \WP_Post && '' !== $partner->post_title ? $partner->post_title : (string) get_bloginfo( 'name' );
	}

	/**
	 * What connecting sends, and what it never sends.
	 */
	public static function what_is_sent(): void {
		echo '<div class="packout-connection__lists"><section><h2>' . esc_html__( 'What is sent', 'packout-core' ) . '</h2><ul>';
		foreach ( array(
			__( 'The name, description, region, and website of this partner, and the address of its page here.', 'packout-core' ),
			__( 'Each organization and team that has a public page here: its name, its description, and the address of that page.', 'packout-core' ),
			__( 'Each public program, campaign, and mission: its title, description, goal, dates and times, status, and the address of its page. A mission\'s landmark is sent only when its lead chose to share it.', 'packout-core' ),
			__( 'Each Spot, as soon as it is reported: its category, the day, whether it was flagged as a hazard, and its location rounded to about a kilometre. Nothing the reporter wrote, no photo, and no credit, until a coordinator here publishes it.', 'packout-core' ),
			__( 'Each published Spot, as its page here shows it: title, category, stage, a short description, the location as your coordinator set it, the day, the credit its reporter chose to show, and the photos the public sees here, as copies that carry no location data.', 'packout-core' ),
			__( 'When a mission here takes on a Spot from packout.spot: the mission and its organization, and afterwards the hours, people, bags, pounds, what was written about the cleanup, and the photos of the work.', 'packout-core' ),
			__( 'Totals: hours, people, Spots cleaned, bags, and pounds, with how many records they are from and how many of those are verified. Only records this site counts publicly are in them.', 'packout-core' ),
			__( 'The figures of this partner\'s overview and of each organization\'s, as numbers.', 'packout-core' ),
			__( 'This site\'s address, the version of this plugin, and, once, the email address of the administrator who connects it.', 'packout-core' ),
		) as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul></section><section><h2>' . esc_html__( 'What is never sent', 'packout-core' ) . '</h2><ul>';
		foreach ( array(
			__( 'Anything about a person: no member, lead, volunteer, or reporter, and no email address or phone number. The one name sent is the credit a reporter chose to have shown on a published Spot.', 'packout-core' ),
			__( 'Who signed up for a mission, or how many.', 'packout-core' ),
			__( 'Anything that is not public on this site: an organization that is not listed, a mission that is not public, a record that is not counted, a Spot kept private. The one exception is the bare report of a Spot awaiting review, described under What is sent.', 'packout-core' ),
			__( 'The stored pin of a Spot shown at an approximate point, or its landmark.', 'packout-core' ),
			__( 'The exact place of a mission\'s meeting point, or a note written for the crew.', 'packout-core' ),
			__( 'A photo the public does not see here, the original of any photo, or its caption.', 'packout-core' ),
			__( 'Messages, invitations, and anything in this site\'s settings.', 'packout-core' ),
		) as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul></section></div>';
	}

	/**
	 * A site that is not connected: what connecting does, and the form.
	 *
	 * @param array<string, mixed> $state What the site knows about its connection.
	 * @param string               $name  The partner's name.
	 */
	private static function offer( array $state, string $name ): void {
		echo '<p class="packout-admin__lede">' . esc_html(
			sprintf(
				/* translators: %s: the partner's name. */
				__( 'This site works in full on its own. Connecting it to packout.spot gives %s a public page there and lists what is public here, each with a link back to this site. Nothing is taken over: your records stay here, and people sign up here.', 'packout-core' ),
				$name
			)
		) . '</p>';
		if ( 'disconnected' === $state['state'] ) {
			echo '<p>' . esc_html__( 'This site was connected before and is not now. Connecting again starts a new request.', 'packout-core' ) . '</p>';
		}
		self::what_is_sent();

		echo '<h2>' . esc_html__( 'Connect', 'packout-core' ) . '</h2>';
		if ( 0 === Partners::site() ) {
			self::say( 'warning', __( 'This site has no partner record yet. Open the Overview once, and then come back.', 'packout-core' ) );
			return;
		}
		if ( ! Connection::secure( home_url() ) ) {
			self::say( 'warning', __( 'A site connects over HTTPS. This site\'s address does not begin with https, so it cannot connect yet.', 'packout-core' ) );
			return;
		}
		$terms = Connection::terms();
		if ( is_wp_error( $terms ) || '' === $terms['version'] ) {
			self::say( 'error', __( 'packout.spot could not be reached to read the partner terms, so this site cannot connect right now.', 'packout-core' ) . ( is_wp_error( $terms ) ? ' ' . $terms->get_error_message() : '' ) );
			self::button( 'terms_again', __( 'Try again', 'packout-core' ) );
			return;
		}
		if ( ! $terms['open'] ) {
			self::say( 'info', __( 'packout.spot is not taking new partner sites yet. Nothing has been sent. Come back to this screen later.', 'packout-core' ) );
			self::button( 'terms_again', __( 'Check again', 'packout-core' ) );
			return;
		}

		$user = wp_get_current_user();
		echo '<ol class="packout-connection__steps">';
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: an email address. */
				__( 'packout.spot sends a link to %s. Opening it and pressing Confirm shows the request came from a person.', 'packout-core' ),
				$user->user_email
			)
		) . '</li>';
		echo '<li>' . esc_html__( 'packout.spot asks this site one question, to be sure the request came from this address.', 'packout-core' ) . '</li>';
		echo '<li>' . esc_html__( 'A coordinator at packout.spot approves the partner. Nothing here is listed there before that.', 'packout-core' ) . '</li>';
		echo '</ol>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-connection__form">';
		self::fields( 'connect' );
		echo '<input type="hidden" name="terms_version" value="' . esc_attr( $terms['version'] ) . '" />';
		echo '<p><label><input type="checkbox" name="accept" value="1" required /> ';
		echo wp_kses(
			sprintf(
				/* translators: 1: the address of the partner terms, 2: their version. */
				__( 'I have read the <a href="%1$s" target="_blank" rel="noopener">partner terms</a> (version %2$s) and accept them for this site.', 'packout-core' ),
				esc_url( $terms['url'] ),
				esc_html( $terms['version'] )
			),
			array(
				'a' => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				),
			)
		);
		echo '</label></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Connect to packout.spot', 'packout-core' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * A site at another address than the one it connected with.
	 *
	 * @param array<string, mixed> $state What the site knows about its connection.
	 */
	private static function moved( array $state ): void {
		self::say(
			'warning',
			sprintf(
				/* translators: 1: the address the site connected with, 2: the address it has now. */
				__( 'This site connected to packout.spot as %1$s, and its address is now %2$s. It sends nothing until you say which this is.', 'packout-core' ),
				$state['home'],
				untrailingslashit( home_url() )
			)
		);
		echo '<div class="packout-connection__choices">';
		echo '<section><h2>' . esc_html__( 'This is a copy', 'packout-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'A staging or development copy of the site. The copy forgets the connection and its key. packout.spot is not told, and the site at the address it knows stays connected.', 'packout-core' ) . '</p>';
		self::button( 'copy', __( 'Forget the connection on this copy', 'packout-core' ) );
		echo '</section><section><h2>' . esc_html__( 'The site moved here', 'packout-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'The site now lives at this address. packout.spot asks this site one question at the new address, to be sure the move is real. When it has its answer, the connection goes on as before, and everything public here is sent again so that every link there leads here.', 'packout-core' ) . '</p>';
		self::button( 'move', __( 'Tell packout.spot the site moved', 'packout-core' ) );
		echo '</section><section><h2>' . esc_html__( 'End the connection', 'packout-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'If packout.spot cannot reach the new address, or you would rather start over: the connection is ended, keeping the partner\'s name and last totals on packout.spot, and you can connect again from here. A coordinator approves the new request.', 'packout-core' ) . '</p>';
		self::button( 'moved', __( 'End the old connection', 'packout-core' ) );
		echo '</section></div>';
	}

	/**
	 * A site that has lost its key: the hub knows it, and it can sign nothing.
	 *
	 * @param array<string, mixed> $state What the site knows about its connection.
	 */
	private static function lost( array $state ): void {
		self::say( 'warning', __( 'This site is connected to packout.spot, and the key it signs with is missing. That happens when a site is restored without its settings. Nothing is sent until the site has a new key.', 'packout-core' ) );
		echo '<h2>' . esc_html__( 'Get a new key', 'packout-core' ) . '</h2><ol class="packout-connection__steps">';
		echo '<li>' . esc_html__( 'Ask packout.spot to email the administrator who connected this site. It sends a link to the address it has on file, and to no other.', 'packout-core' ) . ' ';
		self::button( 'recover', $state['recovering'] > 0 ? __( 'Send the email again', 'packout-core' ) : __( 'Send the email', 'packout-core' ), false );
		if ( $state['recovering'] > 0 ) {
			echo ' <span class="description">' . esc_html(
				sprintf(
					/* translators: %s: a date and how long ago. */
					__( 'Asked %s.', 'packout-core' ),
					self::when( $state['recovering'] )
				)
			) . '</span>';
		}
		echo '</li>';
		echo '<li>' . esc_html__( 'Open the link in that email and press the button on the page it opens. The link works for a day, and what it opens works for an hour.', 'packout-core' ) . '</li>';
		echo '<li>' . esc_html__( 'Come back here and finish. This site makes a new key, and packout.spot asks it one question to be sure the key is this site\'s.', 'packout-core' ) . ' ';
		self::button( 'recover_finish', __( 'Finish with a new key', 'packout-core' ), false );
		echo '</li></ol>';
		echo '<h2>' . esc_html__( 'Or forget the connection', 'packout-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'If the email address on file no longer reaches anyone, this site can forget the connection and connect again as a new partner. Ask packout.spot\'s coordinators to remove the old one.', 'packout-core' ) . '</p>';
		self::button( 'copy', __( 'Forget the connection', 'packout-core' ) );
	}

	/**
	 * A connected site: how the connection stands, what is waiting, and what was sent.
	 *
	 * @param array<string, mixed> $state What the site knows about its connection.
	 * @param string               $name  The partner's name.
	 */
	private static function standing( array $state, string $name ): void {
		$is      = $state['state'];
		$waiting = in_array( $is, array( 'registered', 'pending' ), true );
		$labels  = self::states();
		$intent  = array(
			'active'    => 'stable',
			'dormant'   => 'stable',
			'suspended' => 'high',
		);
		echo '<p class="packout-connection__state">' . Admin::badge( $labels[ $is ] ?? $is, $intent[ $is ] ?? 'medium' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.

		if ( '' !== $state['error'] ) {
			self::say( 'error', __( 'The last request to packout.spot did not go through.', 'packout-core' ) . ' ' . $state['error'] );
		}
		$versions = Connection::versions();
		if ( '' !== $state['minimum'] && version_compare( $versions['plugin_version'], $state['minimum'], '<' ) ) {
			self::say(
				'error',
				sprintf(
					/* translators: 1: the oldest version packout.spot accepts, 2: the version this site has. */
					__( 'packout.spot accepts version %1$s of this plugin or later, and this site has %2$s. Update the plugin: until then nothing is sent.', 'packout-core' ),
					$state['minimum'],
					$versions['plugin_version']
				)
			);
		}

		if ( $waiting ) {
			self::steps( $state );
		}
		if ( 'suspended' === $is ) {
			self::say( 'warning', __( 'A coordinator at packout.spot suspended this partner. Its page and its listings there are out of public view, and nothing is sent until the suspension is lifted. This site works as before. The reason is below.', 'packout-core' ) );
		}
		if ( 'dormant' === $is ) {
			self::say( 'info', __( 'packout.spot had not heard from this site for a while and marked it as quiet. Sending anything wakes it.', 'packout-core' ) );
		}
		if ( '' !== $state['terms_required'] ) {
			self::accept( $state );
		}

		echo '<h2>' . esc_html__( 'The connection', 'packout-core' ) . '</h2><table class="widefat striped packout-admin__table packout-connection__facts"><tbody>';
		$facts = array(
			__( 'Partner', 'packout-core' )      => esc_html( $name ),
			__( 'This site', 'packout-core' )    => esc_html( $state['home'] ),
			__( 'Connected to', 'packout-core' ) => esc_html( Connection::hub() ),
		);
		if ( '' !== $state['partner_url'] ) {
			$facts[ __( 'Its page there', 'packout-core' ) ] = '<a href="' . esc_url( $state['partner_url'] ) . '" rel="noopener">' . esc_html( $state['partner_url'] ) . '</a>';
		}
		$facts[ __( 'Registered', 'packout-core' ) ]     = esc_html( self::when( $state['registered'] ) );
		$facts[ __( 'Last sent', 'packout-core' ) ]      = esc_html( $state['sent'] > 0 ? self::when( $state['sent'] ) : __( 'Nothing yet', 'packout-core' ) );
		$facts[ __( 'Last asked', 'packout-core' ) ]     = esc_html( $state['checked'] > 0 ? self::when( $state['checked'] ) : __( 'Not yet', 'packout-core' ) );
		$facts[ __( 'Terms accepted', 'packout-core' ) ] = esc_html(
			sprintf(
				/* translators: %s: a version of the partner terms. */
				__( 'Version %s', 'packout-core' ),
				$state['terms']
			)
		);
		$facts[ __( 'This plugin', 'packout-core' ) ] = esc_html( $versions['plugin_version'] . ( '' !== $state['current'] && version_compare( $versions['plugin_version'], $state['current'], '<' ) ? ' · ' . sprintf( /* translators: %s: a version. */ __( '%s is the newest', 'packout-core' ), $state['current'] ) : '' ) );
		foreach ( $facts as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each value is escaped where it is built.
		}
		echo '</tbody></table>';
		echo '<p class="packout-connection__buttons">';
		self::button( 'refresh', __( 'Ask packout.spot now', 'packout-core' ), false );
		echo '</p>';

		$notices = Connection::notices();
		if ( $notices ) {
			echo '<h2>' . esc_html__( 'From packout.spot', 'packout-core' ) . '</h2><ul class="packout-connection__notices">';
			foreach ( $notices as $notice ) {
				echo '<li><span class="packout-connection__when">' . esc_html( self::when( $notice['at'] ) ) . '</span> ' . esc_html( $notice['text'] ) . '</li>';
			}
			echo '</ul>';
		}

		if ( ! $waiting ) {
			self::outbox();
			self::claims();
		}
		self::log();
		/**
		 * The Connection screen of a connected site, before the part about leaving.
		 *
		 * @param array<string, mixed> $state The connection.
		 */
		do_action( 'packout_connection_screen', $state );
		self::leave( $state );
	}

	/**
	 * The three steps between registering and being listed.
	 *
	 * @param array<string, mixed> $state What the site knows about its connection.
	 */
	private static function steps( array $state ): void {
		echo '<h2>' . esc_html__( 'Before this site is listed', 'packout-core' ) . '</h2><ol class="packout-connection__steps">';
		$done = '<strong>' . esc_html__( 'Done.', 'packout-core' ) . '</strong> ';
		echo '<li>' . ( $state['confirmed'] ? $done : '' ) . esc_html__( 'Confirm the request by email. packout.spot sent a link to the administrator who connected this site. The link works for three days.', 'packout-core' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup around an escaped word.
		echo '<li>' . ( $state['proved'] ? $done : '' ) . esc_html__( 'packout.spot reaches this site and confirms its address.', 'packout-core' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup around an escaped word.
		if ( ! $state['proved'] ) {
			echo ' ' . esc_html__( 'It has not been able to yet. A firewall, a maintenance page, or a login wall in front of this site would stop it.', 'packout-core' ) . ' ';
			self::button( 'verify', __( 'Try again', 'packout-core' ), false );
		}
		echo '</li>';
		echo '<li>' . esc_html( $state['confirmed'] && $state['proved'] ? __( 'A coordinator at packout.spot approves the partner. They have been told it is waiting.', 'packout-core' ) : __( 'A coordinator at packout.spot approves the partner. They are told once the first two steps are done.', 'packout-core' ) ) . '</li>';
		echo '</ol>';
	}

	/**
	 * A later version of the terms to accept.
	 *
	 * @param array<string, mixed> $state What the site knows about its connection.
	 */
	private static function accept( array $state ): void {
		$terms = Connection::terms();
		$url   = is_wp_error( $terms ) ? '' : $terms['url'];
		echo '<div class="packout-connection__terms">';
		if ( $state['terms_by'] > time() ) {
			self::say(
				'warning',
				sprintf(
					/* translators: 1: a version of the partner terms, 2: a date. */
					__( 'The partner terms changed. Version %1$s is in force. This site goes on sending until %2$s. If no administrator has accepted the new version by then, it sends nothing until one does, and nothing already on packout.spot is removed.', 'packout-core' ),
					$state['terms_required'],
					wp_date( (string) get_option( 'date_format' ), $state['terms_by'] )
				)
			);
		} else {
			self::say(
				'warning',
				sprintf(
					/* translators: %s: a version of the partner terms. */
					__( 'The partner terms changed. Version %s is in force, and this site sends nothing until an administrator accepts it.', 'packout-core' ),
					$state['terms_required']
				)
			);
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::fields( 'terms' );
		echo '<input type="hidden" name="terms_version" value="' . esc_attr( $state['terms_required'] ) . '" />';
		echo '<p><label><input type="checkbox" name="accept" value="1" required /> ';
		if ( '' !== $url ) {
			echo wp_kses(
				sprintf(
					/* translators: 1: the address of the partner terms, 2: their version. */
					__( 'I have read the <a href="%1$s" target="_blank" rel="noopener">partner terms</a> (version %2$s) and accept them for this site.', 'packout-core' ),
					esc_url( $url ),
					esc_html( $state['terms_required'] )
				),
				array(
					'a' => array(
						'href'   => true,
						'target' => true,
						'rel'    => true,
					),
				)
			);
		} else {
			echo esc_html(
				sprintf(
					/* translators: %s: a version of the partner terms. */
					__( 'I have read the partner terms on packout.spot (version %s) and accept them for this site.', 'packout-core' ),
					$state['terms_required']
				)
			);
		}//end if
		echo '</label></p><p><button type="submit" class="button button-primary">' . esc_html__( 'Accept and go on sending', 'packout-core' ) . '</button></p></form></div>';
	}

	/**
	 * An organization of this site that is also on packout.spot: asking for it to be counted once, and how each request stands.
	 */
	private static function claims(): void {
		$claims = Connection::claims();
		$teams  = array();
		foreach ( Partners::organization_ids( Partners::site() ) as $team ) {
			if ( Listings::listable( 'organization', (string) $team ) && ! in_array( $claims[ $team ]['state'] ?? '', array( 'asked', 'accepted' ), true ) ) {
				$teams[ $team ] = (string) get_post_field( 'post_title', $team );
			}
		}
		echo '<h2>' . esc_html__( 'An organization that is already on packout.spot', 'packout-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'If one of your organizations has worked on packout.spot before, its cleanups would be counted there twice: once from its records there, and once from what this site sends. Say which organization it is, and its lead there is asked. When they accept, packout.spot counts what the organization did before that day from its own records, and what it does from that day from this site. Nobody\'s account is moved or copied.', 'packout-core' ) . '</p>';
		if ( $claims ) {
			$words = array(
				'asked'    => __( 'Waiting for its lead on packout.spot', 'packout-core' ),
				'accepted' => __( 'Accepted', 'packout-core' ),
				'declined' => __( 'Declined', 'packout-core' ),
				'ended'    => __( 'Ended', 'packout-core' ),
			);
			$day   = static fn( string $value ): string => '' !== $value ? (string) wp_date( (string) get_option( 'date_format' ), (int) strtotime( $value . ' 12:00:00' ) ) : '';
			echo '<table class="widefat striped packout-admin__table packout-connection__claims"><thead><tr><th scope="col">' . esc_html__( 'Here', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'On packout.spot', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'How it stands', 'packout-core' ) . '</th><th scope="col"></th></tr></thead><tbody>';
			foreach ( $claims as $team => $claim ) {
				$said = $words[ $claim['state'] ] ?? $claim['state'];
				if ( 'accepted' === $claim['state'] && '' !== $claim['day'] ) {
					/* translators: %s: a date. */
					$said .= ' · ' . sprintf( __( 'Counted here from %s', 'packout-core' ), $day( $claim['day'] ) );
				} elseif ( 'ended' === $claim['state'] && '' !== $claim['day'] ) {
					/* translators: 1: a date, 2: a date. */
					$said .= ' · ' . sprintf( __( 'Counted here from %1$s. packout.spot counts its own records again from %2$s.', 'packout-core' ), $day( $claim['day'] ), $day( $claim['until'] ) );
				}
				echo '<tr><td>' . esc_html( (string) get_post_field( 'post_title', (int) $team ) ) . '</td><td>';
				echo '' !== $claim['url'] ? '<a href="' . esc_url( $claim['url'] ) . '" rel="noopener">' . esc_html( '' !== $claim['name'] ? $claim['name'] : $claim['url'] ) . '</a>' : esc_html( $claim['name'] );
				echo '</td><td>' . esc_html( $said ) . '</td><td>';
				if ( in_array( $claim['state'], array( 'asked', 'accepted' ), true ) ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-connection__button packout-connection__button--inline">';
					self::fields( 'unclaim' );
					echo '<input type="hidden" name="team" value="' . esc_attr( (string) $team ) . '" />';
					echo '<button type="submit" class="button button-small">' . esc_html( 'asked' === $claim['state'] ? __( 'Withdraw', 'packout-core' ) : __( 'End it', 'packout-core' ) ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}//end if
		if ( ! Connection::sending() ) {
			return;
		}
		if ( ! $teams ) {
			echo '<p class="description">' . esc_html__( 'An organization can be named here once it is listed on this site.', 'packout-core' ) . '</p>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-connection__form packout-connection__claim">';
		self::fields( 'claim' );
		echo '<p><label>' . esc_html__( 'The organization here', 'packout-core' ) . '<br /><select name="team" required>';
		foreach ( $teams as $team => $title ) {
			echo '<option value="' . esc_attr( (string) $team ) . '">' . esc_html( $title ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'The address of its page on packout.spot', 'packout-core' ) . '<br /><input type="url" name="address" class="regular-text" required placeholder="' . esc_attr( trailingslashit( Connection::hub() ) ) . '" /></label></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Ask its lead on packout.spot', 'packout-core' ) . '</button></p></form>';
	}

	/**
	 * What is waiting to be sent.
	 */
	private static function outbox(): void {
		$rows  = Outbox::rows( 50 );
		$count = Outbox::waiting();
		echo '<h2>' . esc_html__( 'Waiting to be sent', 'packout-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'A change to something public here is sent within a few minutes, after the page that made it has been answered. Once a night everything public is sent again in full.', 'packout-core' ) . '</p>';
		if ( 0 === $count ) {
			echo '<p>' . esc_html__( 'Nothing is waiting.', 'packout-core' ) . '</p>';
		} else {
			$kinds = array(
				'profile' => __( 'The partner\'s profile', 'packout-core' ),
				'full'    => __( 'Everything public, in full', 'packout-core' ),
				'stats'   => __( 'Totals', 'packout-core' ),
			);
			echo '<table class="widefat striped packout-admin__table"><thead><tr><th scope="col">' . esc_html__( 'What', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'Queued', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'Tries', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'Next try', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'Why it waits', 'packout-core' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr><td>' . esc_html( 'listing' === $row['kind'] ? self::subject( $row['subject'] ) : ( $kinds[ $row['kind'] ] ?? $row['kind'] ) ) . '</td>';
				echo '<td>' . esc_html( self::when( (int) strtotime( $row['queued'] . ' UTC' ) ) ) . '</td>';
				echo '<td>' . esc_html( (string) $row['attempts'] ) . '</td>';
				echo '<td>' . esc_html( $row['next_try'] > time() ? self::when( $row['next_try'] ) : __( 'With the next send', 'packout-core' ) ) . '</td>';
				echo '<td>' . esc_html( self::reason( $row['last_error'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			if ( $count > count( $rows ) ) {
				echo '<p class="description">' . esc_html(
					sprintf(
						/* translators: %s: a number. */
						__( 'And %s more.', 'packout-core' ),
						number_format_i18n( $count - count( $rows ) )
					)
				) . '</p>';
			}
		}//end if
		echo '<p class="packout-connection__buttons">';
		self::button( 'send', __( 'Send now', 'packout-core' ), false, $count > 0 );
		self::button( 'everything', __( 'Send everything again', 'packout-core' ), false );
		echo '</p>';
	}

	/**
	 * A queued listing, in words.
	 *
	 * @param string $subject `kind:id`.
	 */
	private static function subject( string $subject ): string {
		list( $kind, $remote_id ) = array_pad( explode( ':', $subject, 2 ), 2, '' );
		list( $id, $sub )         = Listings::parts( $remote_id );
		$kinds                    = array(
			'organization' => __( 'Organization', 'packout-core' ),
			'team'         => __( 'Team', 'packout-core' ),
			'program'      => __( 'Program', 'packout-core' ),
			'campaign'     => __( 'Campaign', 'packout-core' ),
			'mission'      => __( 'Mission', 'packout-core' ),
		);
		$title                    = 'team' === $kind ? (string) ( Subteams::get( $id, $sub )['name'] ?? '' ) : (string) get_post_field( 'post_title', $id );
		return ( $kinds[ $kind ] ?? $kind ) . ': ' . ( '' !== $title ? $title : '#' . $remote_id );
	}

	/**
	 * Why a row waits, or how a send ended, in words.
	 *
	 * @param string $code A short code from the hub or from this site.
	 */
	public static function reason( string $code ): string {
		$words = array(
			''                        => '',
			'packout_hub_unreachable' => __( 'packout.spot could not be reached', 'packout-core' ),
			'packout_https'           => __( 'packout.spot is reached over HTTPS only', 'packout-core' ),
			'missing_reference'       => __( 'It belongs to something packout.spot does not have yet', 'packout-core' ),
			'foreign_url'             => __( 'Its page is not at this site\'s address', 'packout-core' ),
			'not_saved'               => __( 'packout.spot could not save it', 'packout-core' ),
			'no_answer'               => __( 'packout.spot did not answer for it', 'packout-core' ),
			'partner_suspended'       => __( 'The partner is suspended', 'packout-core' ),
			'partner_pending'         => __( 'The partner is waiting for approval', 'packout-core' ),
			'update_required'         => __( 'This plugin is too old for packout.spot', 'packout-core' ),
			'too_many'                => __( 'Too many requests in a minute', 'packout-core' ),
			'too_large'               => __( 'The request was too large', 'packout-core' ),
			'invalid'                 => __( 'packout.spot did not accept what was sent', 'packout-core' ),
			'stale'                   => __( 'This site\'s clock is off', 'packout-core' ),
			'bad_signature'           => __( 'packout.spot did not accept this site\'s key', 'packout-core' ),
			'unknown_site'            => __( 'packout.spot does not know this site', 'packout-core' ),
			'terms_required'          => __( 'The partner terms changed and have not been accepted', 'packout-core' ),
			'not_proved'              => __( 'packout.spot could not reach this site at its address', 'packout-core' ),
			'recovery_closed'         => __( 'The recovery was not open, or this site could not be reached', 'packout-core' ),
			'address_taken'           => __( 'Another site is connected at this address', 'packout-core' ),
			'withdrawn'               => __( 'Taken back', 'packout-core' ),
			'asked'                   => __( 'The email was asked for', 'packout-core' ),
		);
		return $words[ $code ] ?? $code;
	}

	/**
	 * What was sent lately.
	 */
	private static function log(): void {
		$lines = Outbox::recent( 30 );
		echo '<h2>' . esc_html__( 'Lately', 'packout-core' ) . '</h2>';
		if ( ! $lines ) {
			echo '<p>' . esc_html__( 'Nothing has been sent yet.', 'packout-core' ) . '</p>';
			return;
		}
		$actions = array(
			'register'   => __( 'Registered', 'packout-core' ),
			'profile'    => __( 'Profile', 'packout-core' ),
			'listings'   => __( 'Listings', 'packout-core' ),
			'stats'      => __( 'Totals', 'packout-core' ),
			'key'        => __( 'Key replaced', 'packout-core' ),
			'disconnect' => __( 'Disconnected', 'packout-core' ),
			'claim'      => __( 'An organization that is on packout.spot too', 'packout-core' ),
			'move'       => __( 'New address', 'packout-core' ),
			'recover'    => __( 'Key recovered', 'packout-core' ),
			'export'     => __( 'Copy of what packout.spot holds', 'packout-core' ),
		);
		echo '<table class="widefat striped packout-admin__table"><thead><tr><th scope="col">' . esc_html__( 'When', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'What', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'Items', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'Answer', 'packout-core' ) . '</th></tr></thead><tbody>';
		foreach ( $lines as $line ) {
			$went = $line['status'] >= 200 && $line['status'] < 300;
			$note = 0 === strpos( $line['note'], 'refused: ' ) ? $line['note'] : self::reason( $line['note'] );
			echo '<tr><td>' . esc_html( self::when( (int) strtotime( $line['at'] . ' UTC' ) ) ) . '</td><td>' . esc_html( $actions[ $line['action'] ] ?? $line['action'] ) . '</td><td>' . esc_html( $line['items'] > 0 ? number_format_i18n( $line['items'] ) : '' ) . '</td>';
			echo '<td>' . esc_html( $went ? __( 'Taken', 'packout-core' ) : ( 0 === $line['status'] ? __( 'No answer', 'packout-core' ) : (string) $line['status'] ) ) . ( '' !== $note ? ' · ' . esc_html( $note ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'The log keeps ninety days of what was sent and how it was answered. It never keeps what a request carried.', 'packout-core' ) . '</p>';
	}

	/**
	 * Replacing the key, and leaving.
	 *
	 * @param array<string, mixed> $state What the site knows about its connection.
	 */
	private static function leave( array $state ): void {
		echo '<h2>' . esc_html__( 'The key', 'packout-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'Every request this site sends is signed with a key that is kept here and never leaves the site. Replace it if a copy of this site\'s database may have reached someone who should not have it.', 'packout-core' ) . '</p>';
		self::button( 'key', __( 'Replace the key', 'packout-core' ) );

		echo '<h2>' . esc_html__( 'A copy of what packout.spot holds', 'packout-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'Everything packout.spot holds from this site, in one file: the connection, the partner\'s profile, every listing, every Spot, the totals of each day, and the record of requests. Nothing in it is about a person, except the email address of the administrator who connected the site.', 'packout-core' ) . '</p>';
		self::button( 'export', __( 'Download the file', 'packout-core' ) );

		echo '<h2>' . esc_html__( 'Disconnect', 'packout-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'Disconnecting removes this partner\'s listings, its Spots, and its images from packout.spot and stops everything being sent. Nothing on this site changes, and you can connect again later. Without the box below, the partner\'s record and every total it reported are removed there as well.', 'packout-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::fields( 'disconnect' );
		if ( in_array( $state['state'], array( 'active', 'dormant', 'suspended' ), true ) ) {
			echo '<p><label><input type="checkbox" name="keep" value="1" checked /> ' . esc_html__( 'Keep the partner\'s name and its last totals on packout.spot as history, out of public view', 'packout-core' ) . '</label></p>';
		}
		// Asked on the form itself, so it is asked with or without scripts, and the answer is checked when the form arrives.
		echo '<p><label><input type="checkbox" name="sure" value="1" required /> ' . esc_html__( 'Yes, disconnect this site from packout.spot', 'packout-core' ) . '</label></p>';
		echo '<p><button type="submit" class="button button-secondary packout-connection__danger">' . esc_html__( 'Disconnect this site', 'packout-core' ) . '</button></p></form>';
	}

	/**
	 * A time, as a date and how long ago.
	 *
	 * @param int $time Seconds since the epoch.
	 */
	private static function when( int $time ): string {
		if ( $time <= 0 ) {
			return '';
		}
		$date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
		if ( $time > time() ) {
			/* translators: %s: how long, such as "5 mins". */
			return sprintf( __( 'In %s', 'packout-core' ), human_time_diff( time(), $time ) );
		}
		/* translators: 1: a date and time, 2: how long ago, such as "5 mins". */
		return sprintf( __( '%1$s (%2$s ago)', 'packout-core' ), $date, human_time_diff( $time ) );
	}

	/**
	 * A notice in the page.
	 *
	 * @param string $intent `info`, `warning`, `error`, or `success`.
	 * @param string $text   What it says.
	 */
	private static function say( string $intent, string $text ): void {
		$notice = Admin::ui(
			'notice',
			array(
				'intent'      => $intent,
				'description' => $text,
			)
		);
		echo '<div class="packout-admin__notice">' . $notice . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
	}

	/**
	 * The hidden fields of a form on this screen.
	 *
	 * @param string $what The act.
	 */
	private static function fields( string $what ): void {
		echo '<input type="hidden" name="action" value="packout_connection_action" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $what ) . '" />';
		wp_nonce_field( 'packout_connection' );
	}

	/**
	 * A form of one button.
	 *
	 * @param string $what    The act.
	 * @param string $label   What the button says.
	 * @param bool   $block   Whether the form stands on its own line.
	 * @param bool   $enabled Whether the button can be pressed.
	 */
	private static function button( string $what, string $label, bool $block = true, bool $enabled = true ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-connection__button' . ( $block ? '' : ' packout-connection__button--inline' ) . '">';
		self::fields( $what );
		echo '<button type="submit" class="button"' . disabled( $enabled, false, false ) . '>' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Handle a form of this screen.
	 */
	public static function action(): void {
		Admin::require( ! App::is_hub() && current_user_can( 'manage_options' ) );
		check_admin_referer( 'packout_connection' );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'disconnect' === $do && empty( $_POST['sure'] ) ) {
			Admin::back( self::PAGE, array( 'error' => 'unsure' ) );
		}
		if ( 'export' === $do ) {
			$held = Connection::export();
			if ( ! is_wp_error( $held ) ) {
				nocache_headers();
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="packout-spot-export-' . gmdate( 'Y-m-d' ) . '.json"' );
				echo wp_json_encode( $held, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A file of JSON, not a page.
				exit;
			}
			Admin::back(
				self::PAGE,
				array(
					'error'  => 'detail',
					'detail' => $held->get_error_message(),
				)
			);
		}
		$version = isset( $_POST['terms_version'] ) ? preg_replace( '/[^0-9A-Za-z.\-]/', '', sanitize_text_field( wp_unslash( $_POST['terms_version'] ) ) ) : '';
		$more    = array(
			'team'    => isset( $_POST['team'] ) ? (int) $_POST['team'] : 0,
			'address' => isset( $_POST['address'] ) ? esc_url_raw( wp_unslash( $_POST['address'] ) ) : '',
		);
		$result  = self::perform( $do, (string) $version, ! empty( $_POST['accept'] ), ! empty( $_POST['keep'] ), get_current_user_id(), $more );
		if ( is_wp_error( $result ) ) {
			Admin::back(
				self::PAGE,
				array(
					'error'  => 'detail',
					'detail' => $result->get_error_message(),
				)
			);
		}
		Admin::back( self::PAGE, 0 === strpos( $result, 'error:' ) ? array( 'error' => substr( $result, 6 ) ) : array( 'updated' => $result ) );
	}

	/**
	 * Do what a form of this screen asks.
	 *
	 * @param string $what    The act.
	 * @param string $version The version of the terms the form named.
	 * @param bool   $accept  Whether the box accepting the terms was ticked.
	 * @param bool   $keep    Whether the hub keeps the partner as history.
	 * @param int    $user_id The administrator.
	 * @param array  $more    For a request about an organization that is on packout.spot too: `team`, and `address`.
	 * @return string|\WP_Error The key of the notice to show, `error:` and a key for a refusal the screen words itself, or the refusal.
	 */
	public static function perform( string $what, string $version, bool $accept, bool $keep, int $user_id, array $more = array() ) {
		if ( App::is_hub() || ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'An administrator manages this site\'s connection.', 'packout-core' ), array( 'status' => 403 ) );
		}
		switch ( $what ) {
			case 'terms_again':
				$terms = Connection::terms( true );
				return is_wp_error( $terms ) ? $terms : 'checked';

			case 'connect':
				if ( ! $accept || '' === $version ) {
					return 'error:terms_unticked';
				}
				$connected = Connection::connect( $version, $user_id );
				if ( is_wp_error( $connected ) ) {
					return $connected;
				}
				// The hub asks this site its question now. When it cannot get through, the screen offers to try again.
				Connection::verify();
				Outbox::schedule();
				return 'connected';

			case 'verify':
				$stood = Connection::verify();
				if ( is_wp_error( $stood ) ) {
					return $stood;
				}
				return 'proved';

			case 'refresh':
				$stood = Connection::refresh();
				return is_wp_error( $stood ) ? $stood : 'checked';

			case 'send':
				$ran = Outbox::run( true );
				return $ran['failed'] > 0 ? 'error:some_failed' : 'sent';

			case 'everything':
				if ( ! Connection::sending() ) {
					return new \WP_Error( 'packout_not_sending', __( 'This site is not sending right now.', 'packout-core' ), array( 'status' => 409 ) );
				}
				Outbox::everything();
				return 'queued';

			case 'terms':
				if ( ! $accept || '' === $version ) {
					return 'error:terms_unticked';
				}
				$accepted = Connection::accept_terms( $version );
				return is_wp_error( $accepted ) ? $accepted : 'terms';

			case 'key':
				$replaced = Connection::replace_key();
				return is_wp_error( $replaced ) ? $replaced : 'key';

			case 'disconnect':
			case 'moved':
				// The site is disconnected whatever the hub answers, so it can always leave.
				Connection::disconnect( 'moved' === $what ? true : $keep );
				return 'disconnected';

			case 'copy':
				Connection::forget();
				return 'forgotten';

			case 'move':
				$moved = Connection::move( $user_id );
				return is_wp_error( $moved ) ? $moved : 'moved_here';

			case 'recover':
				$asked = Connection::recover( $user_id );
				return is_wp_error( $asked ) ? $asked : 'recover';

			case 'recover_finish':
				$recovered = Connection::recover_finish( $user_id );
				return is_wp_error( $recovered ) ? $recovered : 'recovered';

			case 'claim':
				$claimed = Connection::claim( (int) ( $more['team'] ?? 0 ), (string) ( $more['address'] ?? '' ), $user_id );
				return is_wp_error( $claimed ) ? $claimed : 'claimed';

			case 'unclaim':
				$ended = Connection::unclaim( (int) ( $more['team'] ?? 0 ), $user_id );
				return is_wp_error( $ended ) ? $ended : 'unclaimed';
		}//end switch
		return new \WP_Error( 'packout_unknown', __( 'That is not something this screen does.', 'packout-core' ), array( 'status' => 400 ) );
	}
}
