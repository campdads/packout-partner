<?php
/**
 * Field app.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the offline field app at /field/.
 *
 * The app is a progressive web app: a page shell, a web manifest, and a
 * service worker that caches the shell and its scripts. The shell carries
 * nothing about the visitor, so it can be cached and opened with no signal.
 * The app signs in with the site's normal WordPress login and talks to the
 * packout/v1 routes.
 *
 * On a partner's site the app is the partner's: it carries the partner's
 * name, the site's icon, and the theme's color, and its worker controls the
 * app's own address and nothing else of the site. The shell, the manifest,
 * and the worker tell a page cache not to keep them, since a kept copy of
 * any of the three would hold a phone on an old version.
 */
class Field_App {

	private const QUERY_VAR = 'packout_field';

	/**
	 * Register the address and the handlers.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'rewrites' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'serve' ), 0 );
		add_filter( 'redirect_canonical', array( self::class, 'keep_file_addresses' ) );
	}

	/**
	 * Address of the app.
	 */
	public static function url(): string {
		return home_url( '/' . App::base( 'field' ) . '/' );
	}

	/**
	 * What the app is called: Pack-Out Spot on packout.spot, and the partner on a partner's site.
	 */
	public static function name(): string {
		if ( App::is_hub() ) {
			return 'Pack-Out Spot';
		}
		$site = Partners::site();
		$name = $site > 0 ? trim( (string) get_post_field( 'post_title', $site ) ) : '';
		$name = '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return '' !== $name ? $name : 'Pack-Out Spot';
	}

	/**
	 * The app's full title, for the page and for an installed app's splash screen.
	 */
	public static function title(): string {
		if ( App::is_hub() ) {
			return __( 'Pack-Out Spot field app', 'packout-core' );
		}
		/* translators: %s: the name of the partner whose site this is. */
		return sprintf( __( '%s field app', 'packout-core' ), self::name() );
	}

	/**
	 * The color of the browser's bar around the app: the theme's primary color on a partner's site.
	 */
	public static function color(): string {
		$primary = App::is_hub() ? '' : sanitize_hex_color( (string) ( Ui::colors()['primary'] ?? '' ) );
		return is_string( $primary ) && '' !== $primary ? $primary : '#2f3232';
	}

	/**
	 * The app's icons: the site's own icon on a partner's site that has one large enough, and the plugin's otherwise.
	 *
	 * @return array<int, array{src: string, sizes: string, type: string, purpose: string}>
	 */
	public static function icons(): array {
		$icons = array();
		$id    = App::is_hub() ? 0 : (int) get_option( 'site_icon' );
		foreach ( $id > 0 ? self::squares( $id ) : array() as $width => $url ) {
			$type = (string) wp_check_filetype( $url )['type'];
			// A phone asks for a square picture of at least 144 pixels before it offers to install an app.
			if ( $width >= 144 && in_array( $type, array( 'image/png', 'image/webp', 'image/jpeg' ), true ) ) {
				$icons[ $width ] = array(
					'src'     => $url,
					'sizes'   => $width . 'x' . $width,
					'type'    => $type,
					'purpose' => 'any',
				);
			}
		}
		// The two sizes a phone looks for, or the nearest above each, and failing those the largest there is.
		$chosen = array();
		foreach ( array( 192, 512 ) as $wanted ) {
			foreach ( $icons as $width => $icon ) {
				if ( $width >= $wanted ) {
					$chosen[ $width ] = $icon;
					break;
				}
			}
		}
		$icons = $chosen ? $chosen : array_slice( $icons, -1, 1, true );
		if ( ! $icons ) {
			foreach ( array( 192, 512 ) as $size ) {
				$icons[ $size ] = array(
					'src'     => plugins_url( 'assets/img/icon-' . $size . '.png', PACKOUT_CORE_FILE ),
					'sizes'   => $size . 'x' . $size,
					'type'    => 'image/png',
					'purpose' => 'any maskable',
				);
			}
		}
		ksort( $icons );
		return array_values( $icons );
	}

	/**
	 * The square copies of a picture the site holds, by their real width.
	 *
	 * WordPress answers a request for a size with the nearest copy it has and the size that was asked for, and a
	 * manifest must say what each file really is.
	 *
	 * @param int $id Attachment ID.
	 * @return array<int, string> Address by width in pixels, smallest first.
	 */
	private static function squares( int $id ): array {
		$meta = wp_get_attachment_metadata( $id );
		$full = (string) wp_get_attachment_url( $id );
		if ( ! is_array( $meta ) || '' === $full || empty( $meta['width'] ) ) {
			return array();
		}
		$found = array();
		$sizes = (array) ( $meta['sizes'] ?? array() );
		// The picture as it was uploaded comes last, so that at one width it is the one kept.
		$sizes[] = array(
			'width'  => $meta['width'],
			'height' => $meta['height'] ?? 0,
			'file'   => wp_basename( $full ),
		);
		foreach ( $sizes as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) && (int) ( $size['width'] ?? 0 ) > 0 && (int) ( $size['height'] ?? 0 ) === (int) $size['width'] ) {
				$found[ (int) $size['width'] ] = trailingslashit( dirname( $full ) ) . wp_basename( (string) $size['file'] );
			}
		}
		ksort( $found );
		return $found;
	}

	/**
	 * The app's address, its service worker, and its manifest.
	 */
	public static function rewrites(): void {
		$base = preg_quote( App::base( 'field' ), '#' );
		add_rewrite_rule( '^' . $base . '/?$', 'index.php?' . self::QUERY_VAR . '=app', 'top' );
		add_rewrite_rule( '^' . $base . '/sw\.js$', 'index.php?' . self::QUERY_VAR . '=sw', 'top' );
		add_rewrite_rule( '^' . $base . '/app\.webmanifest$', 'index.php?' . self::QUERY_VAR . '=manifest', 'top' );
	}

	/**
	 * Allow the query variable.
	 *
	 * @param array<int, string> $vars Public query variables.
	 * @return array<int, string>
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Do not add a trailing slash to the worker or the manifest.
	 *
	 * @param mixed $redirect  Address WordPress would redirect to.
	 * @return mixed
	 */
	public static function keep_file_addresses( $redirect ) {
		return in_array( get_query_var( self::QUERY_VAR ), array( 'sw', 'manifest' ), true ) ? false : $redirect;
	}

	/**
	 * Answer a field app request.
	 */
	public static function serve(): void {
		$part = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $part ) {
			return;
		}

		// A page cache plugin reads this before it keeps a page. None of the three may be kept.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The name page cache plugins agree on.
		}

		// The worker reaches the app's address with its last stroke and not without it, so the app is at one address only.
		$asked = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		if ( 'app' === $part && '' !== $asked && '/' !== substr( $asked, -1 ) ) {
			wp_safe_redirect( self::url(), 301 );
			exit;
		}

		if ( 'sw' === $part ) {
			self::worker();
		} elseif ( 'manifest' === $part ) {
			self::manifest();
		} else {
			self::shell();
		}
		exit;
	}

	/**
	 * Script and style handles the shell loads, in order.
	 *
	 * @return array{styles: array<int, string>, scripts: array<int, string>}
	 */
	private static function handles(): array {
		Blocks::register_map_library();
		// The search box of the map. Its words travel in the app's own configuration.
		wp_register_style( 'packout-search', plugins_url( 'assets/css/search.css', PACKOUT_CORE_FILE ), array(), PACKOUT_CORE_VERSION );
		wp_register_script( 'packout-search', plugins_url( 'assets/js/search.js', PACKOUT_CORE_FILE ), array(), PACKOUT_CORE_VERSION, true );
		wp_register_style( 'packout-field', plugins_url( 'assets/css/field.css', PACKOUT_CORE_FILE ), array_merge( Ui::styles(), array( 'packout-maplibre', 'packout-search' ) ), PACKOUT_CORE_VERSION );
		wp_register_script( 'packout-exif', plugins_url( 'assets/js/exif.js', PACKOUT_CORE_FILE ), array(), PACKOUT_CORE_VERSION, true );
		wp_register_script( 'packout-field', plugins_url( 'assets/js/field.js', PACKOUT_CORE_FILE ), array_merge( Ui::scripts(), array( 'packout-maplibre', 'packout-pmtiles', 'packout-exif', 'packout-search', 'wp-i18n' ) ), PACKOUT_CORE_VERSION, true );
		return array(
			'styles'  => array( 'packout-field' ),
			'scripts' => array( 'packout-field' ),
		);
	}

	/**
	 * The style and script tags of the shell, with their dependencies.
	 *
	 * @return array{styles: string, scripts: string}
	 */
	private static function tags(): array {
		// WordPress prints a script or a style once in a request. The page prints them and then names its worker
		// by a version worked out from the same tags, so they are kept from the first asking.
		static $tags = null;
		if ( null !== $tags ) {
			return $tags;
		}
		$handles = self::handles();

		ob_start();
		wp_print_styles( $handles['styles'] );
		$styles = (string) ob_get_clean();

		ob_start();
		wp_print_scripts( $handles['scripts'] );
		$scripts = (string) ob_get_clean();

		$tags = array(
			'styles'  => $styles,
			'scripts' => $scripts,
		);
		return $tags;
	}

	/**
	 * Addresses the service worker keeps for offline use.
	 *
	 * They are read from the tags the shell prints, so the two always agree.
	 *
	 * @return array<int, string>
	 */
	private static function asset_urls(): array {
		$tags = self::tags();
		preg_match_all( '/<(?:script|link)\b[^>]*\b(?:src|href)=["\']([^"\']+)["\']/', $tags['styles'] . $tags['scripts'], $found );
		$urls = array();
		foreach ( $found[1] as $url ) {
			$urls[] = html_entity_decode( $url, ENT_QUOTES );
		}
		foreach ( self::icons() as $icon ) {
			$urls[] = $icon['src'];
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * What the app needs before it has spoken to the server.
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		$guidance = array();
		foreach ( array_keys( Schema::hazards() ) as $hazard ) {
			$guidance[ $hazard ] = Schema::hazard_guidance( $hazard );
		}

		// What packout.spot shows in the site's region, when the site shows it. The app keeps the Spots of a region with the region.
		$hub = Hub_Layers::config();
		if ( $hub ) {
			$hub['kept_one'] = __( 'One Spot of packout.spot is kept with the regions on this device. It can be read here, and not changed.', 'packout-core' );
			/* translators: %s: a number greater than one. */
			$hub['kept'] = __( '%s Spots of packout.spot are kept with the regions on this device. They can be read here, and not changed.', 'packout-core' );
		}

		return array(
			'version'         => \PackOut\Core\Kernel::instance()->site()->version(),
			'name'            => self::name(),
			'hub'             => $hub,
			'rest'            => esc_url_raw( rest_url( Rest::NAMESPACE . '/' ) ),
			// The search box of the map: where it asks, and its words.
			'search'          => Search::settings(),
			'nonce'           => esc_url_raw( admin_url( 'admin-ajax.php?action=rest-nonce' ) ),
			'login'           => esc_url_raw( Accounts::signin_url( self::url() ) ),
			// Empty when the site has no Sign up page chosen.
			'signup'          => Accounts::open() ? esc_url_raw( Accounts::signup_url( self::url() ) ) : '',
			'teams'           => (int) Settings::get( 'workspace_page' ) > 0 ? esc_url_raw( Accounts::workspace_url() ) : '',
			'home'            => esc_url_raw( home_url( '/' ) ),
			// The version is part of the address because hosts and CDNs keep
			// .js addresses for hours. A changed worker gets a new address.
			'worker'          => esc_url_raw( add_query_arg( 'v', self::worker_setup()['version'], self::url() . 'sw.js' ) ),
			'scope'           => wp_parse_url( self::url(), PHP_URL_PATH ),
			'style'           => Settings::get( 'map_style' ),
			'center'          => array( (float) Settings::get( 'map_lng' ), (float) Settings::get( 'map_lat' ) ),
			'zoom'            => (float) Settings::get( 'map_zoom' ),
			'categories'      => Schema::categories(),
			'defaultCategory' => Settings::get( 'default_category' ),
			'statuses'        => Schema::statuses(),
			'locationKinds'   => Schema::location_kinds(),
			'missionStatuses' => Missions::statuses(),
			'outcomes'        => Missions::outcomes(),
			'doneWords'       => Schema::done_words(),
			'sources'         => Impact::sources(),
			'kinds'           => Impact::kinds(),
			'verifications'   => Impact::verifications(),
			'celebration'     => Settings::celebration_line(),
			'hazards'         => Schema::hazards(),
			'guidance'        => $guidance,
			'review'          => Admin::review_labels(),
			'photoLimit'      => Photos::LIMIT,
		);
	}

	/**
	 * Tell a browser, a host, and a CDN to ask the site for this every time.
	 *
	 * A web server set to give every script or page a date to keep it until adds one where there is none, so the
	 * answer carries a date of its own, long past.
	 */
	private static function ask_again(): void {
		header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
		header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
	}

	/**
	 * The page shell.
	 */
	private static function shell(): void {
		$tags = self::tags();

		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		self::ask_again();

		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head>';
		echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />';
		echo '<meta name="robots" content="noindex" />';
		echo '<meta name="theme-color" content="' . esc_attr( self::color() ) . '" />';
		echo '<title>' . esc_html( self::title() ) . '</title>';
		echo '<link rel="manifest" href="' . esc_url( self::url() . 'app.webmanifest' ) . '" />';
		echo '<link rel="apple-touch-icon" href="' . esc_url( self::icons()[0]['src'] ) . '" />';
		echo $tags['styles']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Tags printed by wp_print_styles().
		echo '</head><body class="pos-field">';
		echo '<div id="pos-field" data-config="' . esc_attr( (string) wp_json_encode( self::config() ) ) . '">';
		echo '<p class="pos-field__boot">' . esc_html__( 'Starting the field app…', 'packout-core' ) . '</p>';
		echo '<noscript><p>' . esc_html__( 'The field app needs JavaScript.', 'packout-core' ) . '</p></noscript>';
		echo '</div>';
		echo $tags['scripts']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Tags printed by wp_print_scripts().
		echo '</body></html>';
	}

	/**
	 * What the web manifest says, for Add to Home Screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function manifest_data(): array {
		$scope = (string) wp_parse_url( self::url(), PHP_URL_PATH );
		return array(
			'name'             => self::title(),
			// The words under the icon on a home screen.
			'short_name'       => App::is_hub() ? __( 'Pack-Out Spot', 'packout-core' ) : self::name(),
			'description'      => __( 'Document a Spot with no signal and send it when you are back in coverage.', 'packout-core' ),
			'start_url'        => $scope,
			'scope'            => $scope,
			'display'          => 'standalone',
			'background_color' => '#2f3232',
			'theme_color'      => self::color(),
			'icons'            => self::icons(),
		);
	}

	/**
	 * The web manifest.
	 */
	private static function manifest(): void {
		status_header( 200 );
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		self::ask_again();
		echo wp_json_encode( self::manifest_data() );
	}

	/**
	 * What the service worker is built from.
	 *
	 * The version changes whenever the worker or any file it keeps changes.
	 *
	 * @return array{version: string, shell: string, assets: array<int, string>, source: string}
	 */
	private static function worker_setup(): array {
		static $setup = null;
		if ( null === $setup ) {
			$assets = self::asset_urls();
			$path   = PACKOUT_CORE_DIR . '/assets/js/field-sw.js';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Plugin file.
			$source = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
			$setup  = array(
				'version' => substr( md5( \PackOut\Core\Kernel::instance()->site()->version() . wp_json_encode( $assets ) . $source ), 0, 12 ),
				'shell'   => self::url(),
				'assets'  => $assets,
				'source'  => $source,
			);
		}
		return $setup;
	}

	/**
	 * The service worker.
	 *
	 * It keeps the shell and its scripts, and nothing else from the site.
	 * Downloaded map regions are stored by the app in their own cache, which
	 * the worker leaves alone when the app updates.
	 */
	private static function worker(): void {
		$setup  = self::worker_setup();
		$source = $setup['source'];
		unset( $setup['source'] );

		status_header( 200 );
		header( 'Content-Type: application/javascript; charset=utf-8' );
		self::ask_again();
		header( 'Service-Worker-Allowed: ' . wp_parse_url( self::url(), PHP_URL_PATH ) );
		echo 'const SETUP = ' . wp_json_encode( $setup ) . ";\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
		echo $source; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plugin JavaScript file.
	}
}
