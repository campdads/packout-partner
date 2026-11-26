<?php
/**
 * The setup wizard of a partner site.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eight steps that take a new partner site from installed to usable: what
 * the server can do, the partner, its first organization, the connection to
 * packout.spot, the pages, the map, the people, and a list of what is left.
 *
 * Every step can be left and come back to, in any order, and each does only
 * what its own button says. The wizard never sets the site's front page, its
 * menus, or its theme, and connecting is offered, never done for the site.
 */
class Wizard {

	public const PAGE = 'packout-setup';

	/**
	 * The option that keeps where the wizard stands: the steps saved, and whether it was finished or put away.
	 */
	public const OPTION = 'packout_wizard';

	/**
	 * Add the screen, its form handler, and the reminder. A partner site only.
	 */
	public static function register(): void {
		if ( App::is_hub() ) {
			return;
		}
		add_action( 'admin_menu', array( self::class, 'menu' ), 11 );
		add_action( 'admin_post_packout_wizard_action', array( self::class, 'action' ) );
		add_action( 'admin_notices', array( self::class, 'reminder' ) );
	}

	/**
	 * Add the menu item.
	 */
	public static function menu(): void {
		Admin::add_screen( (string) add_submenu_page( 'packout-spot', __( 'Setup', 'packout-core' ), __( 'Setup', 'packout-core' ), 'manage_options', self::PAGE, array( self::class, 'render' ) ) );
	}

	/**
	 * The steps, in order.
	 *
	 * @return array<string, string>
	 */
	public static function steps(): array {
		return array(
			'check'        => __( 'Check', 'packout-core' ),
			'partner'      => __( 'Your partner', 'packout-core' ),
			'organization' => __( 'Your first organization', 'packout-core' ),
			'connect'      => __( 'Connect', 'packout-core' ),
			'pages'        => __( 'Pages', 'packout-core' ),
			'map'          => __( 'Map', 'packout-core' ),
			'people'       => __( 'People', 'packout-core' ),
			'done'         => __( 'Done', 'packout-core' ),
		);
	}

	/**
	 * Where the wizard stands.
	 *
	 * @return array{seen: array<int, string>, done: int, away: bool}
	 */
	public static function standing(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'seen' => array_values( array_intersect( array_keys( self::steps() ), array_map( 'strval', (array) ( $stored['seen'] ?? array() ) ) ) ),
			'done' => (int) ( $stored['done'] ?? 0 ),
			'away' => ! empty( $stored['away'] ),
		);
	}

	/**
	 * Keep what changed about where the wizard stands.
	 *
	 * @param array<string, mixed> $changes Keys of `standing()`.
	 */
	private static function remember( array $changes ): void {
		update_option( self::OPTION, $changes + self::standing(), false );
	}

	/**
	 * The address of a step.
	 *
	 * @param string $step Step.
	 */
	public static function url( string $step = '' ): string {
		return admin_url( 'admin.php?page=' . self::PAGE . ( '' !== $step ? '&step=' . $step : '' ) );
	}

	/**
	 * The step after one.
	 *
	 * @param string $step Step.
	 */
	public static function after( string $step ): string {
		$keys = array_keys( self::steps() );
		$at   = array_search( $step, $keys, true );
		return false === $at || ! isset( $keys[ $at + 1 ] ) ? 'done' : $keys[ $at + 1 ];
	}

	/**
	 * Tell an administrator the wizard is there, until it is finished or put away.
	 */
	public static function reminder(): void {
		$standing = self::standing();
		$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $standing['done'] > 0 || $standing['away'] || ! current_user_can( 'manage_options' ) || ( $screen && false !== strpos( (string) $screen->id, self::PAGE ) ) ) {
			return;
		}
		echo '<div class="notice notice-info packout-setup-reminder"><p>' . esc_html__( 'Pack-Out Spot is installed. A short setup makes its pages, names your partner, and checks what this server can do.', 'packout-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>';
		echo '<a class="button button-primary" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Set it up', 'packout-core' ) . '</a> ';
		echo '<input type="hidden" name="action" value="packout_wizard_action" /><input type="hidden" name="step" value="done" /><input type="hidden" name="do" value="away" />';
		wp_nonce_field( 'packout_wizard', '_wpnonce', false );
		echo '<button type="submit" class="button-link">' . esc_html__( 'Not now', 'packout-core' ) . '</button></p></form></div>';
	}

	/**
	 * The step the address names, or the first one not yet saved.
	 */
	private static function current(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses which step to show.
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		if ( isset( self::steps()[ $step ] ) ) {
			return $step;
		}
		$seen = self::standing()['seen'];
		foreach ( array_keys( self::steps() ) as $key ) {
			if ( ! in_array( $key, $seen, true ) ) {
				return $key;
			}
		}
		return 'done';
	}

	/**
	 * Draw the screen.
	 */
	public static function render(): void {
		Admin::require( current_user_can( 'manage_options' ) );
		$step     = self::current();
		$steps    = self::steps();
		$standing = self::standing();

		echo '<div class="wrap packout-admin packout-setup" style="' . esc_attr( Admin::palette() ) . '">';
		echo '<h1>' . esc_html__( 'Set up Pack-Out Spot', 'packout-core' ) . '</h1>';
		Admin::notice(
			array(
				'saved'   => __( 'Saved.', 'packout-core' ),
				'mail'    => __( 'A test email was handed to this site\'s mail setup. Check that it arrived.', 'packout-core' ),
				'found'   => __( 'The map now opens there. Change the numbers if it is not quite right.', 'packout-core' ),
				'invited' => __( 'The invitations were sent.', 'packout-core' ),
				'pages'   => __( 'The pages are as you chose.', 'packout-core' ),
			)
		);

		echo '<ol class="packout-setup__steps">';
		$number = 0;
		foreach ( $steps as $key => $label ) {
			++$number;
			$class = 'packout-setup__step' . ( $key === $step ? ' is-current' : '' ) . ( in_array( $key, $standing['seen'], true ) ? ' is-seen' : '' );
			echo '<li class="' . esc_attr( $class ) . '"><a href="' . esc_url( self::url( $key ) ) . '"' . ( $key === $step ? ' aria-current="step"' : '' ) . '><span class="packout-setup__number">' . esc_html( number_format_i18n( $number ) ) . '</span> ' . esc_html( $label ) . '</a></li>';
		}
		echo '</ol>';

		echo '<section class="packout-setup__body" data-pos-step="' . esc_attr( $step ) . '"><h2>' . esc_html( $steps[ $step ] ) . '</h2>';
		call_user_func( array( self::class, 'step_' . $step ) );
		echo '</section></div>';
	}

	/**
	 * Open a step's form.
	 *
	 * @param string $step  Step.
	 * @param bool   $files Whether the form carries a file.
	 */
	private static function open( string $step, bool $files = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-setup__form"' . ( $files ? ' enctype="multipart/form-data"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="packout_wizard_action" /><input type="hidden" name="step" value="' . esc_attr( $step ) . '" />';
		wp_nonce_field( 'packout_wizard', '_wpnonce', false );
	}

	/**
	 * Close a step's form with its buttons.
	 *
	 * @param string $step  Step.
	 * @param string $label What the main button says.
	 * @param bool   $skip  Whether the step can be passed over.
	 */
	private static function close( string $step, string $label, bool $skip = true ): void {
		echo '<p class="packout-setup__buttons"><button type="submit" name="do" value="save" class="button button-primary">' . esc_html( $label ) . '</button>';
		if ( $skip ) {
			echo ' <a class="button" href="' . esc_url( self::url( self::after( $step ) ) ) . '">' . esc_html__( 'Skip for now', 'packout-core' ) . '</a>';
		}
		echo '</p></form>';
	}

	/**
	 * Step 1: what this server can do.
	 */
	private static function step_check(): void {
		echo '<p>' . esc_html__( 'What Pack-Out Spot needs from this server. Nothing here stops you going on.', 'packout-core' ) . '</p>';
		self::checklist( array_filter( Health::checks(), static fn( array $row ): bool => 'site' === $row['group'] && ! in_array( $row['id'], array( 'pages', 'partner' ), true ) ) );
		self::open( 'check' );
		echo '<p><button type="submit" name="do" value="mail" class="button">' . esc_html__( 'Send me a test email', 'packout-core' ) . '</button> <span class="description">' . esc_html__( 'It goes to the address of your own account.', 'packout-core' ) . '</span></p>';
		self::close( 'check', __( 'Continue', 'packout-core' ), false );
	}

	/**
	 * A list of checks.
	 *
	 * @param array<string, array<string, string>> $rows Checks.
	 */
	private static function checklist( array $rows ): void {
		$words = array(
			'good'        => array( __( 'Good', 'packout-core' ), 'success' ),
			'recommended' => array( __( 'Worth a look', 'packout-core' ), 'warning' ),
			'critical'    => array( __( 'Needs fixing', 'packout-core' ), 'error' ),
		);
		echo '<table class="widefat striped packout-setup__checks"><tbody>';
		foreach ( $rows as $row ) {
			$word = $words[ $row['status'] ] ?? $words['recommended'];
			echo '<tr data-pos-check="' . esc_attr( $row['id'] ) . '" data-pos-status="' . esc_attr( $row['status'] ) . '"><th scope="row">' . esc_html( $row['label'] ) . '</th><td>' . Admin::badge( $word[0], $word[1] ) . '</td><td>' . esc_html( $row['detail'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			if ( '' !== $row['url'] && 'good' !== $row['status'] ) {
				echo ' <a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['action'] ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Step 2: the partner.
	 */
	private static function step_partner(): void {
		$id      = Partners::site();
		$partner = get_post( $id );
		if ( ! $partner instanceof \WP_Post ) {
			echo '<p>' . esc_html__( 'This site has no partner record. Deactivate the plugin and activate it again to make one.', 'packout-core' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'A partner is who this site belongs to: the body that the organizations, teams, and programs here work under. It is what is named on your pages, and on packout.spot if you connect.', 'packout-core' ) . '</p>';
		self::open( 'partner', true );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="packout-setup-name">' . esc_html__( 'Name', 'packout-core' ) . '</label></th><td><input type="text" id="packout-setup-name" name="name" class="regular-text" required minlength="3" maxlength="80" value="' . esc_attr( $partner->post_title ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="packout-setup-description">' . esc_html__( 'What it does', 'packout-core' ) . '</label></th><td><textarea id="packout-setup-description" name="description" class="large-text" rows="4" maxlength="2000">' . esc_textarea( $partner->post_content ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="packout-setup-region">' . esc_html__( 'Where it works', 'packout-core' ) . '</label></th><td><input type="text" id="packout-setup-region" name="region" class="regular-text" maxlength="120" value="' . esc_attr( Partners::field( $id, 'region' ) ) . '" /><p class="description">' . esc_html__( 'In words: a county, a forest, a stretch of river.', 'packout-core' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="packout-setup-website">' . esc_html__( 'Website', 'packout-core' ) . '</label></th><td><input type="url" id="packout-setup-website" name="website" class="regular-text" value="' . esc_attr( Partners::field( $id, 'website', home_url( '/' ) ) ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="packout-setup-logo">' . esc_html__( 'Logo', 'packout-core' ) . '</label></th><td>';
		$logo = Partners::logo( $id );
		if ( $logo > 0 ) {
			echo wp_get_attachment_image( $logo, 'thumbnail', false, array( 'class' => 'packout-setup__logo' ) ) . '<br />';
		}
		echo '<input type="file" id="packout-setup-logo" name="logo" accept="image/jpeg,image/png,image/webp" /><p class="description">' . esc_html__( 'A JPEG, PNG, or WebP image. Leave it empty to keep what is there.', 'packout-core' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Public page', 'packout-core' ) . '</th><td><label><input type="checkbox" name="listed" value="1"' . checked( 'publish', $partner->post_status, false ) . ' /> ' . esc_html__( 'Give the partner a public page on this site', 'packout-core' ) . '</label><p class="description">' . esc_html__( 'Its programs are public, and listed on packout.spot once connected, only while the partner has a public page.', 'packout-core' ) . '</p></td></tr>';
		echo '</tbody></table>';
		self::close( 'partner', __( 'Save and continue', 'packout-core' ) );
	}

	/**
	 * Step 3: the first organization.
	 */
	private static function step_organization(): void {
		$id   = Partners::site();
		$have = $id > 0 ? Partners::organizations( $id ) : array();
		echo '<p>' . esc_html__( 'Organizations are the groups that do the work: they plan missions, and people join them. A partner can have one or many. Make the first now, and add the rest from the Organizations screen or the partner workspace.', 'packout-core' ) . '</p>';
		if ( $have ) {
			echo '<p>' . esc_html__( 'This site already has:', 'packout-core' ) . '</p><ul class="ul-disc">';
			foreach ( $have as $team ) {
				echo '<li>' . esc_html( $team->post_title ) . '</li>';
			}
			echo '</ul><p><a class="button button-primary" href="' . esc_url( self::url( 'connect' ) ) . '">' . esc_html__( 'Continue', 'packout-core' ) . '</a></p>';
			return;
		}
		$partner = get_post( $id );
		if ( $partner instanceof \WP_Post && self::mentions_youth( $partner->post_title . ' ' . $partner->post_content ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( self::youth_words() ) . '</p></div>';
		}
		self::open( 'organization' );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'The first organization', 'packout-core' ) . '</legend>';
		/* translators: %s: the partner's name. */
		echo '<p><label><input type="radio" name="same" value="1" checked /> ' . esc_html( sprintf( __( 'The same as the partner: “%s”', 'packout-core' ), $partner instanceof \WP_Post ? $partner->post_title : '' ) ) . '</label></p>';
		echo '<p><label><input type="radio" name="same" value="0" /> ' . esc_html__( 'Another name:', 'packout-core' ) . '</label> <input type="text" name="name" class="regular-text" maxlength="80" aria-label="' . esc_attr__( 'The organization\'s name', 'packout-core' ) . '" /></p>';
		echo '</fieldset>';
		self::close( 'organization', __( 'Make it and continue', 'packout-core' ) );
	}

	/**
	 * Whether a description reads as though the partner works with people under eighteen.
	 *
	 * @param string $text A name and a description.
	 */
	public static function mentions_youth( string $text ): bool {
		/**
		 * Filters the words that make the setup say where a team is marked as a youth team.
		 *
		 * @param array<int, string> $words Words and beginnings of words, in lower case.
		 */
		$words = (array) apply_filters( 'packout_youth_words', array( 'youth', 'scout', 'school', 'student', 'pupil', 'kids', 'child', 'teen', 'junior', 'under 18', 'under eighteen', '4-h', 'cadet' ) );
		$text  = ' ' . strtolower( wp_strip_all_tags( $text ) );
		foreach ( $words as $word ) {
			$word = strtolower( trim( (string) $word ) );
			if ( '' !== $word && 1 === preg_match( '/[^a-z0-9]' . preg_quote( $word, '/' ) . '/', $text ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What the setup says to a partner that works with young people.
	 */
	public static function youth_words(): string {
		return __( 'Your description mentions young people. When you make a team of people under eighteen, mark it as a youth team: in My organizations, open the team, then Name and lead. A youth team\'s page names no lead, nobody joins it or signs up to its missions from a public page, and no photograph or credit line from its Spots is sent to packout.spot.', 'packout-core' );
	}

	/**
	 * Step 4: the connection to packout.spot.
	 */
	private static function step_connect(): void {
		$state  = Connection::state();
		$states = Connection_Screen::states();
		echo '<p>' . esc_html__( 'This site works in full on its own. Connecting it to packout.spot is your choice, now or later: it gives the partner a page there and lists what is public here, each with a link back to this site.', 'packout-core' ) . '</p>';
		if ( Connection::connected() ) {
			/* translators: %s: how the connection stands, such as Connected. */
			echo '<p><strong>' . esc_html( sprintf( __( 'How it stands: %s.', 'packout-core' ), $states[ $state['state'] ] ?? $state['state'] ) ) . '</strong></p>';
		}
		Connection_Screen::what_is_sent();
		echo '<p>' . esc_html__( 'Connecting is done on the Connection screen, where the partner terms are shown before anything is sent.', 'packout-core' ) . '</p>';
		self::open( 'connect' );
		echo '<p class="packout-setup__buttons"><a class="button' . ( Connection::connected() ? '' : ' button-primary' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . Connection_Screen::PAGE ) ) . '">' . esc_html__( 'Open the Connection screen', 'packout-core' ) . '</a> ';
		echo '<button type="submit" name="do" value="save" class="button' . ( Connection::connected() ? ' button-primary' : '' ) . '">' . esc_html( Connection::connected() ? __( 'Continue', 'packout-core' ) : __( 'Not now', 'packout-core' ) ) . '</button></p></form>';
	}

	/**
	 * Step 5: the pages.
	 */
	private static function step_pages(): void {
		echo '<p>' . esc_html__( 'Choose, for each page, whether the plugin makes it, uses a page you already have, or leaves it out. A page it makes is an ordinary page that you can edit. Your front page, your menus, and your theme are not touched: add the pages you want to your menu yourself.', 'packout-core' ) . '</p>';
		self::open( 'pages' );
		echo '<table class="widefat striped packout-setup__pages"><thead><tr><th scope="col">' . esc_html__( 'Page', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'Address', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'What to do', 'packout-core' ) . '</th></tr></thead><tbody>';
		$templates = Pages::templates();
		$labels    = Pages_Screen::labels();
		foreach ( Pages::states() as $key => $state ) {
			$template = $templates[ $key ];
			$parent   = '' !== $template['parent'] ? $templates[ $template['parent'] ]['slug'] . '/' : '';
			echo '<tr data-pos-page="' . esc_attr( $key ) . '"><th scope="row"><strong>' . ( '' !== $parent ? '— ' : '' ) . esc_html( $template['title'] ) . '</strong><br /><span class="description">' . esc_html( $template['about'] ) . '</span></th><td>';
			if ( $state['id'] > 0 && 'missing' !== $state['state'] ) {
				echo '' !== $state['url'] ? '<a href="' . esc_url( $state['url'] ) . '">' . esc_html( (string) wp_parse_url( $state['url'], PHP_URL_PATH ) ) . '</a>' : esc_html( $state['title'] );
				echo '</td><td>' . Admin::badge( $labels[ $state['state'] ][0], $labels[ $state['state'] ][1] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
				continue;
			}
			$clash = Pages::clash( $template['slug'], '' !== $template['parent'] ? Pages::page( $template['parent'] ) : 0 );
			echo '<code>/' . esc_html( $parent . ( '' !== $clash ? 'pack-out-' : '' ) . $template['slug'] ) . '/</code>';
			if ( '' !== $clash ) {
				/* translators: 1: an address, 2: what is already there, such as the page "Map". */
				echo '<br /><span class="description">' . esc_html( sprintf( __( '/%1$s/ is taken by %2$s, so a new page gets this address instead.', 'packout-core' ), $parent . $template['slug'], $clash ) ) . '</span>';
			}
			echo '</td><td><fieldset><legend class="screen-reader-text">' . esc_html( $template['title'] ) . '</legend>';
			$skipped = $state['decided'] && 'skipped' === $state['state'];
			echo '<label><input type="radio" name="pages[' . esc_attr( $key ) . '][do]" value="create"' . checked( $skipped, false, false ) . ' /> ' . esc_html__( 'Make it', 'packout-core' ) . '</label><br />';
			echo '<label><input type="radio" name="pages[' . esc_attr( $key ) . '][do]" value="use" /> ' . esc_html__( 'Use', 'packout-core' ) . '</label> ';
			wp_dropdown_pages(
				array(
					'name'             => 'pages[' . $key . '][page]', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed word.
					'id'               => 'packout-setup-page-' . $key, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed word.
					'show_option_none' => esc_html__( 'a page you already have…', 'packout-core' ),
					'post_status'      => array( 'publish', 'draft', 'private' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed words.
				)
			);
			echo '<br /><label><input type="radio" name="pages[' . esc_attr( $key ) . '][do]" value="skip"' . checked( $skipped, true, false ) . ' /> ' . esc_html__( 'Leave it out', 'packout-core' ) . '</label></fieldset></td></tr>';
		}//end foreach
		echo '</tbody></table>';
		self::close( 'pages', __( 'Do this and continue', 'packout-core' ) );
	}

	/**
	 * Step 6: the map.
	 */
	private static function step_map(): void {
		$settings = Settings::all();
		echo '<p>' . esc_html__( 'Where the map opens when there are no Spots to fit it to, and how reports are checked.', 'packout-core' ) . '</p>';
		self::open( 'map' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="packout-setup-find">' . esc_html__( 'Find your area', 'packout-core' ) . '</label></th><td><input type="search" id="packout-setup-find" name="q" class="regular-text" placeholder="' . esc_attr__( 'A town, a county, a forest', 'packout-core' ) . '" /> <button type="submit" name="do" value="find" class="button">' . esc_html__( 'Find', 'packout-core' ) . '</button>';
		echo '<p class="description">' . esc_html( Search::attribution() ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Opening view', 'packout-core' ) . '</th><td class="packout-admin__inline">';
		foreach ( array(
			'map_lat'  => __( 'Latitude', 'packout-core' ),
			'map_lng'  => __( 'Longitude', 'packout-core' ),
			'map_zoom' => __( 'Zoom', 'packout-core' ),
		) as $name => $label ) {
			echo '<label>' . esc_html( $label ) . ' <input type="number" step="any" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $settings[ $name ] ) . '" class="small-text" /></label> ';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="packout-setup-radius">' . esc_html__( 'Possible duplicates', 'packout-core' ) . '</label></th><td><input id="packout-setup-radius" type="number" min="5" max="1000" name="duplicate_radius" value="' . esc_attr( (string) $settings['duplicate_radius'] ) . '" class="small-text" /> ' . esc_html__( 'meters', 'packout-core' );
		echo '<p class="description">' . esc_html__( 'Open Spots in the same category this close to a new pin are shown as possible duplicates.', 'packout-core' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="packout-setup-category">' . esc_html__( 'Default category', 'packout-core' ) . '</label></th><td><select id="packout-setup-category" name="default_category">';
		foreach ( Schema::categories() as $slug => $name ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $settings['default_category'], $slug, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'packout.spot on your map', 'packout-core' ) . '</th><td>';
		if ( Connection::connected() ) {
			$layers = Hub_Layers::settings();
			echo '<label><input type="checkbox" name="layers" value="1"' . checked( $layers['on'], true, false ) . ' /> ' . esc_html__( 'Show packout.spot\'s Spots, Places, and map features around the opening view', 'packout-core' ) . '</label>';
			echo '<p class="description">' . esc_html__( 'The region is about a hundred kilometers across, centered where the map opens. Change it on the Connection screen.', 'packout-core' ) . '</p>';
		} else {
			echo '<span class="description">' . esc_html__( 'Once this site is connected, its map can also show what packout.spot knows about your area. That is set on the Connection screen.', 'packout-core' ) . '</span>';
		}
		echo '</td></tr></tbody></table>';
		self::close( 'map', __( 'Save and continue', 'packout-core' ) );
	}

	/**
	 * Step 7: the people.
	 */
	private static function step_people(): void {
		$roles = Capabilities::roles();
		$have  = Partners::site() > 0 ? Partners::organizations( Partners::site() ) : array();
		echo '<h3>' . esc_html__( 'Coordinators', 'packout-core' ) . '</h3>';
		/* translators: %s: a role's name. */
		echo '<p>' . esc_html( sprintf( __( 'A coordinator reviews reports, verifies records, and sees every screen of Pack-Out Spot. A coordinator is a user of this site with the %s role, so you add one as you add any user.', 'packout-core' ), $roles['pos_coordinator']['label'] ) ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'user-new.php' ) ) . '">' . esc_html__( 'Add a user', 'packout-core' ) . '</a></p>';
		echo '<h3>' . esc_html__( 'Co-leads', 'packout-core' ) . '</h3>';
		if ( ! $have ) {
			echo '<p>' . esc_html__( 'Make an organization first. Its co-leads are invited by email.', 'packout-core' ) . '</p><p><a class="button button-primary" href="' . esc_url( self::url( 'done' ) ) . '">' . esc_html__( 'Continue', 'packout-core' ) . '</a></p>';
			return;
		}
		if ( ! Accounts::open() ) {
			echo '<p>' . esc_html__( 'Invitations lead to the Sign up page, and this site does not have one yet. Make it in the Pages step, and then come back.', 'packout-core' ) . '</p><p><a class="button button-primary" href="' . esc_url( self::url( 'done' ) ) . '">' . esc_html__( 'Continue', 'packout-core' ) . '</a></p>';
			return;
		}
		echo '<p>' . esc_html__( 'A co-lead helps run an organization: plans missions, answers people who ask to join, and records the work. Each address below is sent an invitation to make an account and join as a co-lead.', 'packout-core' ) . '</p>';
		self::open( 'people' );
		echo '<p><label for="packout-setup-team">' . esc_html__( 'Organization', 'packout-core' ) . '</label><br /><select id="packout-setup-team" name="team">';
		foreach ( $have as $team ) {
			echo '<option value="' . esc_attr( (string) $team->ID ) . '">' . esc_html( $team->post_title ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label for="packout-setup-emails">' . esc_html__( 'Email addresses, one on each line (ten at most)', 'packout-core' ) . '</label><br /><textarea id="packout-setup-emails" name="emails" rows="5" class="large-text code"></textarea></p>';
		self::close( 'people', __( 'Send the invitations and continue', 'packout-core' ) );
	}

	/**
	 * Step 8: what is done and what is left.
	 */
	private static function step_done(): void {
		$standing = self::standing();
		if ( 0 === $standing['done'] ) {
			self::remember( array( 'done' => time() ) );
		}
		echo '<p>' . esc_html__( 'That is the setup. This is how the site stands. Any step can be opened again from the list above.', 'packout-core' ) . '</p>';
		self::checklist( self::left() );
		$partner = get_post( Partners::site() );
		if ( $partner instanceof \WP_Post && self::mentions_youth( $partner->post_title . ' ' . $partner->post_content ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( self::youth_words() ) . '</p></div>';
		}
		echo '<h3>' . esc_html__( 'Next', 'packout-core' ) . '</h3><ul class="ul-disc">';
		foreach ( array(
			array( admin_url( 'nav-menus.php' ), __( 'Add the pages you want to your site\'s menu. The plugin does not change menus.', 'packout-core' ) ),
			array( Pages::url( 'report' ), __( 'Report a first Spot, to see the whole path.', 'packout-core' ) ),
			array( admin_url( 'admin.php?page=packout-spot' ), __( 'Open the Overview, where reports wait for review.', 'packout-core' ) ),
			array( admin_url( 'privacy.php' ), __( 'Add the suggested text about Pack-Out Spot to your privacy policy.', 'packout-core' ) ),
			array( admin_url( 'site-health.php' ), __( 'Site Health reports how this plugin stands, with everything else about the site.', 'packout-core' ) ),
		) as $next ) {
			if ( '' !== $next[0] ) {
				echo '<li><a href="' . esc_url( $next[0] ) . '">' . esc_html( $next[1] ) . '</a></li>';
			}
		}
		echo '</ul>';
	}

	/**
	 * The checklist of the last step: each part of the setup, and how it stands.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function left(): array {
		$rows = array();
		$add  = static function ( string $id, string $label, bool $ok, string $detail, string $url, string $action ) use ( &$rows ): void {
			$rows[ $id ] = array(
				'id'     => $id,
				'group'  => 'setup',
				'label'  => $label,
				'status' => $ok ? 'good' : 'recommended',
				'detail' => $detail,
				'url'    => $url,
				'action' => $action,
			);
		};
		$open = __( 'Open this step', 'packout-core' );

		$site  = array_filter( Health::checks(), static fn( array $row ): bool => 'site' === $row['group'] && ! in_array( $row['id'], array( 'pages', 'partner' ), true ) && 'good' !== $row['status'] );
		$worst = Health::worst( $site );
		$add( 'check', __( 'This server', 'packout-core' ), ! $site, $site ? implode( ' ', array_column( $site, 'detail' ) ) : __( 'It has everything Pack-Out Spot needs.', 'packout-core' ), self::url( 'check' ), $open );
		if ( 'critical' === $worst ) {
			$rows['check']['status'] = 'critical';
		}

		$id      = Partners::site();
		$partner = get_post( $id );
		$named   = $partner instanceof \WP_Post && '' !== trim( $partner->post_content );
		$add( 'partner', __( 'Your partner', 'packout-core' ), $named, $named ? $partner->post_title : __( 'The partner has no description yet.', 'packout-core' ), self::url( 'partner' ), $open );

		$have = $id > 0 ? Partners::organizations( $id ) : array();
		/* translators: %s: a number. */
		$add( 'organization', __( 'Organizations', 'packout-core' ), (bool) $have, $have ? sprintf( _n( '%s organization.', '%s organizations.', count( $have ), 'packout-core' ), number_format_i18n( count( $have ) ) ) : __( 'There is no organization yet, so nobody can plan a mission.', 'packout-core' ), self::url( 'organization' ), $open );

		$state  = Connection::state();
		$states = Connection_Screen::states();
		$add( 'connect', __( 'Connection to packout.spot', 'packout-core' ), true, Connection::connected() ? ( $states[ $state['state'] ] ?? $state['state'] ) : __( 'Not connected. The site works in full on its own.', 'packout-core' ), self::url( 'connect' ), $open );

		$made  = 0;
		$wants = array();
		foreach ( Pages::states() as $key => $page ) {
			if ( in_array( $page['state'], array( 'unchanged', 'update', 'edited', 'yours' ), true ) ) {
				++$made;
			} elseif ( in_array( $key, array( 'map', 'report' ), true ) ) {
				$wants[] = Pages::templates()[ $key ]['title'];
			}
		}
		$add(
			'pages',
			__( 'Pages', 'packout-core' ),
			! $wants,
			$wants
				/* translators: %s: a list of page titles. */
				? sprintf( __( 'Not there yet: %s.', 'packout-core' ), implode( ', ', $wants ) )
				/* translators: %s: a number. */
				: sprintf( _n( '%s page.', '%s pages.', $made, 'packout-core' ), number_format_i18n( $made ) ),
			self::url( 'pages' ),
			$open
		);

		$settings = Settings::all();
		$defaults = Settings::defaults();
		$moved    = (float) $settings['map_lat'] !== (float) $defaults['map_lat'] || (float) $settings['map_lng'] !== (float) $defaults['map_lng'];
		$add( 'map', __( 'Map', 'packout-core' ), $moved, $moved ? __( 'The map opens on your area.', 'packout-core' ) : __( 'The map still opens on the whole of the United States.', 'packout-core' ), self::url( 'map' ), $open );
		return $rows;
	}

	/**
	 * Handle a form of the wizard.
	 */
	public static function action(): void {
		Admin::require( ! App::is_hub() && current_user_can( 'manage_options' ) );
		check_admin_referer( 'packout_wizard' );
		$step  = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		$do    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : 'save';
		$input = array();
		foreach ( array( 'name', 'region', 'q', 'map_lat', 'map_lng', 'map_zoom', 'duplicate_radius', 'default_category' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$input[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			}
		}
		foreach ( array( 'description', 'emails' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$input[ $field ] = sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) );
			}
		}
		if ( isset( $_POST['website'] ) ) {
			$input['website'] = esc_url_raw( wp_unslash( $_POST['website'] ) );
		}
		$input['listed'] = ! empty( $_POST['listed'] );
		$input['same']   = ! isset( $_POST['same'] ) || '1' === $_POST['same'];
		$input['layers'] = ! empty( $_POST['layers'] );
		$input['team']   = isset( $_POST['team'] ) ? absint( wp_unslash( $_POST['team'] ) ) : 0;
		$input['pages']  = array();
		foreach ( isset( $_POST['pages'] ) && is_array( $_POST['pages'] ) ? wp_unslash( $_POST['pages'] ) : array() as $key => $choice ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized below.
			$input['pages'][ sanitize_key( (string) $key ) ] = array(
				'do'   => sanitize_key( (string) ( is_array( $choice ) ? ( $choice['do'] ?? '' ) : '' ) ),
				'page' => absint( is_array( $choice ) ? ( $choice['page'] ?? 0 ) : 0 ),
			);
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- An uploaded file, checked by the photo store.
		$files = isset( $_FILES['logo'] ) && is_array( $_FILES['logo'] ) && ! empty( $_FILES['logo']['name'] ) ? array( 'logo' => $_FILES['logo'] ) : array();

		$result = self::perform( $step, $do, $input, $files, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'error'  => 'detail',
						'detail' => rawurlencode( $result->get_error_message() ),
					),
					self::url( isset( self::steps()[ $step ] ) ? $step : '' )
				)
			);
			exit;
		}
		if ( 'away' === $do ) {
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'updated', rawurlencode( $result['notice'] ), self::url( $result['step'] ) ) );
		exit;
	}

	/**
	 * Do what a step's form asks.
	 *
	 * @param string               $step    Step.
	 * @param string               $act     `save`, or the step's own second button: `mail` on Check, `find` on Map, `away` to put the reminder away.
	 * @param array<string, mixed> $input   The form's values, sanitized.
	 * @param array<string, mixed> $files   Uploaded files: `logo`.
	 * @param int                  $user_id The administrator.
	 * @return array{step: string, notice: string}|\WP_Error Where to go next and what to say.
	 */
	public static function perform( string $step, string $act, array $input, array $files, int $user_id ) {
		if ( App::is_hub() || ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'An administrator sets this site up.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( ! isset( self::steps()[ $step ] ) ) {
			return new \WP_Error( 'packout_unknown', __( 'That is not a step of the setup.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( 'away' === $act ) {
			self::remember( array( 'away' => true ) );
			return array(
				'step'   => $step,
				'notice' => '',
			);
		}
		$stay = static fn( string $notice ): array => array(
			'step'   => $step,
			'notice' => $notice,
		);
		$site = Partners::site();

		switch ( $step ) {
			case 'check':
				if ( 'mail' === $act ) {
					$sent = Health::test_mail( $user_id );
					return is_wp_error( $sent ) ? $sent : $stay( 'mail' );
				}
				break;

			case 'partner':
				$saved = Partners::update_profile(
					$site,
					array(
						'name'        => (string) ( $input['name'] ?? '' ),
						'description' => (string) ( $input['description'] ?? '' ),
						'region'      => (string) ( $input['region'] ?? '' ),
						'website'     => (string) ( $input['website'] ?? '' ),
						'listed'      => ! empty( $input['listed'] ),
					),
					$user_id
				);
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				if ( ! empty( $files['logo'] ) ) {
					$logo = Partners::set_logo( $site, (array) $files['logo'], $user_id );
					if ( is_wp_error( $logo ) ) {
						return $logo;
					}
				}
				break;

			case 'organization':
				if ( $site > 0 && Partners::organizations( $site ) ) {
					break;
				}
				$partner = get_post( $site );
				$name    = ! empty( $input['same'] ) || '' === trim( (string) ( $input['name'] ?? '' ) ) ? ( $partner instanceof \WP_Post ? $partner->post_title : '' ) : (string) $input['name'];
				$made    = Partners::create_organization(
					$site,
					array(
						'name'        => $name,
						'description' => $partner instanceof \WP_Post ? $partner->post_content : '',
						'region'      => Partners::field( $site, 'region' ),
						'website'     => Partners::field( $site, 'website' ),
					),
					$user_id
				);
				if ( is_wp_error( $made ) ) {
					return $made;
				}
				break;

			case 'pages':
				$failed = array();
				foreach ( array_keys( Pages::templates() ) as $key ) {
					$choice = $input['pages'][ $key ] ?? null;
					$state  = Pages::state( $key );
					if ( ! is_array( $choice ) || ! in_array( $state['state'], array( 'skipped', 'missing' ), true ) ) {
						continue;
					}
					$do = (string) $choice['do'];
					if ( 'create' === $do ) {
						$done = Pages::act( $key, 'missing' === $state['state'] ? 'again' : 'create' );
					} elseif ( 'use' === $do ) {
						$done = Pages::act( $key, 'use', (int) $choice['page'] );
					} else {
						$done = Pages::act( $key, 'skip' );
					}
					if ( is_wp_error( $done ) ) {
						$failed[] = Pages::templates()[ $key ]['title'] . ': ' . $done->get_error_message();
					}
				}
				if ( $failed ) {
					return new \WP_Error( 'packout_pages', implode( ' ', $failed ), array( 'status' => 422 ) );
				}
				self::remember( array( 'seen' => array_merge( self::standing()['seen'], array( $step ) ) ) );
				return array(
					'step'   => self::after( $step ),
					'notice' => 'pages',
				);

			case 'map':
				if ( 'find' === $act ) {
					$query = Search::clean( (string) ( $input['q'] ?? '' ) );
					$found = Search::locations( $query, null );
					if ( ! $found ) {
						return new \WP_Error( 'packout_not_found', null === $found ? __( 'The place search could not be reached just now. Type the latitude and longitude instead, or try again in a minute.', 'packout-core' ) : __( 'Nothing was found by that name. Try a nearby town.', 'packout-core' ), array( 'status' => 404 ) );
					}
					Settings::save(
						array(
							'map_lat'  => round( (float) $found[0]['lat'], 4 ),
							'map_lng'  => round( (float) $found[0]['lng'], 4 ),
							'map_zoom' => 9,
						)
					);
					return $stay( 'found' );
				}
				$saved = Settings::save( array_intersect_key( $input, array_flip( array( 'map_lat', 'map_lng', 'map_zoom', 'duplicate_radius', 'default_category' ) ) ) );
				if ( Connection::connected() ) {
					$layers = Hub_Layers::settings();
					if ( ! empty( $input['layers'] ) && ! $layers['on'] ) {
						$set = Hub_Layers::save( true, self::around( (float) $saved['map_lat'], (float) $saved['map_lng'] ) );
						if ( is_wp_error( $set ) ) {
							return $set;
						}
					} elseif ( empty( $input['layers'] ) && $layers['on'] ) {
						$stored = get_option( Hub_Layers::OPTION, array() );
						Hub_Layers::save( false, is_array( $stored ) ? (string) ( $stored['box'] ?? '' ) : '' );
					}
				}
				break;

			case 'people':
				$emails = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_email', (array) preg_split( '/[\s,;]+/', (string) ( $input['emails'] ?? '' ) ) ) ) ) ), 0, 10 );
				if ( ! $emails ) {
					break;
				}
				$team = (int) ( $input['team'] ?? 0 );
				if ( ! in_array( $team, array_map( static fn( \WP_Post $one ): int => $one->ID, Partners::organizations( $site ) ), true ) ) {
					return new \WP_Error( 'packout_team', __( 'Choose one of this site\'s organizations.', 'packout-core' ), array( 'status' => 422 ) );
				}
				$failed = array();
				foreach ( $emails as $email ) {
					$sent = Teams::invite( $team, $email, $user_id, array( 'role' => 'colead' ) );
					if ( is_wp_error( $sent ) ) {
						$failed[] = $email . ': ' . $sent->get_error_message();
					}
				}
				if ( $failed ) {
					return new \WP_Error( 'packout_invite', implode( ' ', $failed ), array( 'status' => 422 ) );
				}
				self::remember( array( 'seen' => array_merge( self::standing()['seen'], array( $step ) ) ) );
				return array(
					'step'   => self::after( $step ),
					'notice' => 'invited',
				);
		}//end switch

		self::remember( array( 'seen' => array_merge( self::standing()['seen'], array( $step ) ) ) );
		return array(
			'step'   => self::after( $step ),
			'notice' => in_array( $step, array( 'check', 'connect', 'done' ), true ) ? '' : 'saved',
		);
	}

	/**
	 * A region about a hundred kilometers across around a point, as "west,south,east,north".
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 */
	public static function around( float $lat, float $lng ): string {
		$lat    = max( -85.0, min( 85.0, $lat ) );
		$tall   = 50 / 111.0;
		$wide   = 50 / ( 111.0 * max( 0.1, cos( deg2rad( $lat ) ) ) );
		$number = static fn( float $value ): string => rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
		return implode( ',', array( $number( max( -180.0, $lng - $wide ) ), $number( max( -90.0, $lat - $tall ) ), $number( min( 180.0, $lng + $wide ) ), $number( min( 90.0, $lat + $tall ) ) ) );
	}

	/**
	 * What deleting the plugin with "Remove all data" removes of the wizard.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
		delete_option( Health::MAIL );
	}
}
