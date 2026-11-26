<?php
/**
 * Administration menu and shared screen chrome.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Pack-Out Spot menu, the overview, and helpers the screens share.
 *
 * The drawer is the library's own. Badges, notices, and empty states are
 * components the plugin's screens adapter draws: see `Ui`.
 */
class Admin {

	/**
	 * The Partners screen's page name. The screen is the hub's.
	 */
	public const PARTNERS_PAGE = 'packout-partners';

	/**
	 * The Places screen's page name. The screen is the hub's.
	 */
	public const PLACES_PAGE = 'packout-places';

	/**
	 * Hooks of the plugin screens.
	 *
	 * @var array<int, string>
	 */
	private static array $hooks = array();

	/**
	 * Count a page a plugin added as one of these screens, so it loads the shared styles and scripts.
	 *
	 * @param string $hook What `add_submenu_page()` returned.
	 */
	public static function add_screen( string $hook ): void {
		if ( '' !== $hook ) {
			self::$hooks[] = $hook;
		}
	}

	/**
	 * Register the menu, assets, and form handlers.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_post_packout_spot_action', array( Spots_Screen::class, 'action' ) );
		add_action( 'admin_post_packout_mission_action', array( Missions_Screen::class, 'action' ) );
		add_action( 'admin_post_packout_team_action', array( Teams_Screen::class, 'action' ) );
		add_action( 'admin_post_packout_program_action', array( Programs_Screen::class, 'action' ) );
		add_action( 'admin_post_packout_impact_action', array( Impact_Screen::class, 'action' ) );
		add_action( 'admin_post_packout_people_action', array( People_Screen::class, 'action' ) );
		add_action( 'admin_post_packout_invitation_action', array( Invitations_Screen::class, 'action' ) );
		add_action( 'admin_post_packout_export', array( Export_Screen::class, 'download' ) );
		add_action( 'admin_post_packout_export_overview', array( Overview_Report::class, 'download' ) );
		add_action( 'admin_post_packout_save_settings', array( Settings_Screen::class, 'save' ) );
		add_action( 'current_screen', array( self::class, 'screen' ) );
	}

	/**
	 * Parent menu with an overview, then one screen per record type.
	 */
	public static function menu(): void {
		// A coordinator's Overview is the platform's. A lead's is the overview of what they lead.
		self::$hooks[] = (string) add_menu_page(
			__( 'Pack-Out Spot', 'packout-core' ),
			__( 'Pack-Out Spot', 'packout-core' ),
			Capabilities::SCREENS,
			'packout-spot',
			array( self::class, 'overview' ),
			'dashicons-location-alt',
			26
		);
		self::$hooks[] = (string) add_submenu_page( 'packout-spot', __( 'Overview', 'packout-core' ), __( 'Overview', 'packout-core' ), Capabilities::SCREENS, 'packout-spot', array( self::class, 'overview' ) );
		add_action( 'load-toplevel_page_packout-spot', array( self::class, 'overview_home' ) );
		self::$hooks[] = (string) add_submenu_page( 'packout-spot', __( 'Spots', 'packout-core' ), __( 'Spots', 'packout-core' ), Capabilities::MODERATE, 'packout-spots', array( Spots_Screen::class, 'render' ) );
		self::$hooks[] = (string) add_submenu_page( 'packout-spot', __( 'Missions', 'packout-core' ), __( 'Missions', 'packout-core' ), Capabilities::LEAD, 'packout-missions', array( Missions_Screen::class, 'render' ) );
		self::$hooks[] = (string) add_submenu_page( 'packout-spot', __( 'Organizations', 'packout-core' ), __( 'Organizations', 'packout-core' ), Capabilities::SCREENS, 'packout-teams', array( Teams_Screen::class, 'render' ) );

		/**
		 * Fires where a plugin may add a screen of its own to the menu.
		 *
		 * The plugin adds its page under `packout-spot` and passes the hook
		 * `add_submenu_page()` returns to `Admin::add_screen()`, so the page
		 * gets the shared styles and scripts. The hub adds Partners after
		 * `organizations` and Places after `impact`.
		 *
		 * @param string $after The screen just added: `organizations` or `impact`.
		 */
		do_action( 'packout_admin_menu', 'organizations' );
		self::$hooks[] = (string) add_submenu_page( 'packout-spot', __( 'Programs', 'packout-core' ), __( 'Programs', 'packout-core' ), Capabilities::LEAD, Programs_Screen::PAGE, array( Programs_Screen::class, 'render' ) );
		self::$hooks[] = (string) add_submenu_page( 'packout-spot', __( 'People', 'packout-core' ), __( 'People', 'packout-core' ), Capabilities::MODERATE, People_Screen::PAGE, array( People_Screen::class, 'render' ) );
		self::$hooks[] = (string) add_submenu_page( 'packout-spot', __( 'Invitations', 'packout-core' ), __( 'Invitations', 'packout-core' ), Capabilities::MODERATE, Invitations_Screen::PAGE, array( Invitations_Screen::class, 'render' ) );
		self::$hooks[] = (string) add_submenu_page( 'packout-spot', __( 'Impact', 'packout-core' ), __( 'Impact', 'packout-core' ), Capabilities::IMPACT, 'packout-impact', array( Impact_Screen::class, 'render' ) );
		/** This action is documented above. */
		do_action( 'packout_admin_menu', 'impact' );
		self::$hooks[] = (string) add_submenu_page( 'packout-spot', __( 'Export', 'packout-core' ), __( 'Export', 'packout-core' ), Capabilities::EXPORT, Export_Screen::PAGE, array( Export_Screen::class, 'render' ) );
		self::$hooks[] = (string) add_submenu_page( 'packout-spot', __( 'Settings', 'packout-core' ), __( 'Settings', 'packout-core' ), Capabilities::MODERATE, 'packout-settings', array( Settings_Screen::class, 'render' ) );
	}

	/**
	 * Address of one drawer pane. The list stays put; the panel opens over it.
	 *
	 * @param string $id Pane id.
	 */
	public static function pane_url( string $id ): string {
		return '#packout-pane-' . rawurlencode( $id );
	}

	/**
	 * WordPress library icons for the Pack-Out Spot menu: the parent item and each screen.
	 *
	 * A plugin whose administration menu shows such icons reads them from here.
	 *
	 * @return array<string, string> Menu item to icon name.
	 */
	public static function menu_icons(): array {
		return array(
			'toplevel_page_packout-spot'       => 'pin',
			'toplevel_page_packout-taxonomies' => 'tag',
			'page:packout-spot'                => 'home',
			'page:packout-spots'               => 'pin',
			'page:packout-missions'            => 'scheduled',
			'page:packout-teams'               => 'institution',
			'page:packout-partners'            => 'globe',
			'page:packout-programs'            => 'category',
			'page:packout-people'              => 'people',
			'page:packout-invitations'         => 'envelope',
			'page:packout-impact'              => 'trending-up',
			'page:packout-places'              => 'map-marker',
			'page:packout-export'              => 'download',
			'page:packout-settings'            => 'settings',
		);
	}

	/**
	 * Styles, the drawer, components, and the map preview.
	 *
	 * @param string $hook Current admin hook.
	 */
	public static function assets( string $hook ): void {
		if ( ! in_array( $hook, self::$hooks, true ) ) {
			return;
		}

		Ui::enqueue();
		wp_enqueue_style( 'packout-admin', plugins_url( 'assets/css/admin.css', PACKOUT_CORE_FILE ), array( Ui::palette_style() ), PACKOUT_CORE_VERSION );

		Admin_Views::enqueue();
		$deps   = array_merge( array( Ui::drawer() ), Ui::lists()['scripts'] );
		$places = str_ends_with( $hook, self::PLACES_PAGE );
		if ( $places || str_ends_with( $hook, 'packout-spots' ) ) {
			Blocks::register_map_library();
			wp_enqueue_style( 'packout-maplibre' );
			$deps[] = 'packout-maplibre';
		}
		if ( $places ) {
			Features::enqueue_editor();
			$deps[] = 'packout-map-editor';
		}

		if ( str_ends_with( $hook, Teams_Screen::PAGE ) ) {
			wp_register_script( 'packout-address', plugins_url( 'assets/js/address.js', PACKOUT_CORE_FILE ), array(), PACKOUT_CORE_VERSION, true );
			$deps[] = 'packout-address';
		}
		// The Overview's chart: the platform's page, and the page of one organization or one partner.
		$scoped = Overview_Page::requested() > 0 && ( str_ends_with( $hook, Teams_Screen::PAGE ) || str_ends_with( $hook, self::PARTNERS_PAGE ) );
		if ( 'toplevel_page_packout-spot' === $hook || $scoped ) {
			wp_enqueue_script( 'packout-chart', plugins_url( 'assets/js/chart.umd.min.js', PACKOUT_CORE_FILE ), array(), '4.6.2', true );
			$deps[] = 'packout-chart';
		}
		wp_enqueue_script( 'packout-admin', plugins_url( 'assets/js/admin.js', PACKOUT_CORE_FILE ), array_values( $deps ), PACKOUT_CORE_VERSION, true );
		App::translate_script( 'packout-admin' );
		$setup_tabs = array();
		if ( 'toplevel_page_packout-spot' === $hook && Capabilities::can( Capabilities::MODERATE ) ) {
			foreach ( self::setup_help()['tabs'] as $tab ) {
				$setup_tabs[ (string) $tab['id'] ] = array(
					'ok'     => ! empty( $tab['ok'] ),
					'status' => (string) ( $tab['status'] ?? '' ),
					'icon'   => (string) ( $tab['icon'] ?? '' ),
				);
			}
		}
		wp_localize_script(
			'packout-admin',
			'packoutAdmin',
			array(
				'mapStyle'  => Settings::get( 'map_style' ),
				'center'    => array( (float) Settings::get( 'map_lng' ), (float) Settings::get( 'map_lat' ) ),
				'zoom'      => (float) Settings::get( 'map_zoom' ),
				// The search answers as the coordinator, so it finds the Spots still waiting for review.
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				// The Spots a coordinator outlines a Place around.
				'spots'     => $places ? Places::pins() : array(),
				'overview'  => rest_url( Rest::NAMESPACE . '/overview/' ),
				'setupTabs' => $setup_tabs,
				'fresh'     => Overview::FRESH,
				'icons'     => array(
					'time'          => self::icon( 'time' ),
					'chevron-right' => self::icon( 'chevron-right' ),
				),
				'text'      => array(
					/* translators: %d: number of points. */
					'points'       => __( '%d points', 'packout-core' ),
					'few'          => __( 'An outline needs at least three points.', 'packout-core' ),
					'pasted'       => __( 'Pasted. The map shows the outline once the Place is saved.', 'packout-core' ),
					'retry'        => __( 'Retry', 'packout-core' ),
					/* translators: %s: a number. */
					'planned'      => __( '%s planned', 'packout-core' ),
					/* translators: %s: a number. */
					'progress'     => __( '%s in progress', 'packout-core' ),
					'reports'      => __( 'Spot reports received', 'packout-core' ),
					'cleanups'     => __( 'Cleanups documented', 'packout-core' ),
					'activity'     => __( 'Reports and cleanups', 'packout-core' ),
					'day'          => __( 'Day', 'packout-core' ),
					'week'         => __( 'Week of', 'packout-core' ),
					'justNow'      => __( 'Updated just now', 'packout-core' ),
					/* translators: %s: human time difference. */
					'updatedAgo'   => __( 'Updated %s ago', 'packout-core' ),
					'refreshFail'  => __( 'Could not refresh', 'packout-core' ),
					'nothing'      => __( 'Nothing is waiting.', 'packout-core' ),
					'reviewOldest' => __( 'Review oldest', 'packout-core' ),
					/* translators: %s: the figure of the period before. */
					'was'          => __( 'was %s', 'packout-core' ),
					/* translators: 1: open items, 2: oldest age. */
					'tally'        => __( '%1$s open, oldest %2$s', 'packout-core' ),
					/* translators: %s: open items. */
					'tallyPlain'   => __( '%s open', 'packout-core' ),
				),
			)
		);
	}

	/**
	 * WordPress icon-library artwork, or nothing on a site whose plugin has no such icon.
	 *
	 * @param string $slug    Library slug.
	 * @param string $classes Class on the svg.
	 */
	public static function icon( string $slug, string $classes = 'packout-overview__icon' ): string {
		return Ui::icon( $slug, $classes );
	}

	/**
	 * Site Setup Help tabs on the Overview screen.
	 *
	 * @param mixed $screen Current screen.
	 */
	public static function screen( $screen ): void {
		if ( ! is_object( $screen ) || 'toplevel_page_packout-spot' !== ( $screen->id ?? '' ) ) {
			return;
		}
		if ( ! Capabilities::can( Capabilities::MODERATE ) ) {
			return;
		}
		self::register_setup_help( $screen );
		add_filter( 'gettext', array( self::class, 'screen_label' ), 10, 3 );
	}

	/**
	 * One Help tab per setup section. Status is an icon before the label.
	 *
	 * @return array{tabs: array<int, array<string, mixed>>, sidebar: string}
	 */
	public static function setup_help(): array {
		static $help = null;
		if ( is_array( $help ) ) {
			return $help;
		}
		$setup = Overview::setup();
		$tabs  = array();
		foreach ( $setup['rows'] as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$ok     = ! empty( $row['ok'] );
			$status = $ok ? __( 'Ready', 'packout-core' ) : __( 'Needs attention', 'packout-core' );
			$name   = (string) ( $row['tab'] ?? $row['label'] ?? '' );
			$id     = sanitize_key( (string) ( $row['id'] ?? 'setup-' . $i ) );
			$tabs[] = array(
				'id'       => 'packout-setup-' . $id,
				'title'    => $name,
				'content'  => self::setup_tab_html( $row, $status, $ok ),
				'priority' => 10 + (int) $i,
				'ok'       => $ok,
				'status'   => $status,
				'icon'     => self::icon( $ok ? 'published' : 'error', 'packout-setup-tab__glyph' ),
			);
		}
		$help = array(
			'tabs'    => $tabs,
			'sidebar' => self::setup_sidebar_html( $setup ),
		);
		return $help;
	}

	/**
	 * Attach Site Setup tabs to a screen that supports Help.
	 *
	 * @param mixed $screen Current screen.
	 */
	public static function register_setup_help( $screen ): void {
		if ( ! is_object( $screen ) || ! method_exists( $screen, 'add_help_tab' ) ) {
			return;
		}
		$help = self::setup_help();
		foreach ( $help['tabs'] as $tab ) {
			$screen->add_help_tab( $tab );
		}
		if ( method_exists( $screen, 'set_help_sidebar' ) ) {
			$screen->set_help_sidebar( $help['sidebar'] );
		}
	}

	/**
	 * Insert status icons into WordPress Help tab links.
	 *
	 * Core prints titles with esc_html, so the icon has to be added after.
	 *
	 * @param string $html Screen-meta markup.
	 */
	public static function with_setup_tab_icons( string $html ): string {
		foreach ( self::setup_help()['tabs'] as $tab ) {
			$id    = (string) ( $tab['id'] ?? '' );
			$title = (string) ( $tab['title'] ?? '' );
			if ( '' === $id || '' === $title ) {
				continue;
			}
			$ok      = ! empty( $tab['ok'] );
			$status  = (string) ( $tab['status'] ?? '' );
			$icon    = (string) ( $tab['icon'] ?? '' );
			$state   = $ok ? 'ready' : 'attention';
			$label   = '' !== $status ? $title . ': ' . $status : $title;
			$prefix  = '<span class="packout-setup-tab__icon packout-setup-tab__icon--' . esc_attr( $state ) . '" aria-hidden="true">' . $icon . '</span>';
			$pattern = '/(<li id="' . preg_quote( 'tab-link-' . $id, '/' ) . '"[^>]*>\s*<a)([^>]*)>(?:\s*)' . preg_quote( esc_html( $title ), '/' ) . '(?:\s*)(<\/a>)/';
			$html    = preg_replace_callback(
				$pattern,
				static function ( array $found ) use ( $prefix, $label, $title ): string {
					$open = $found[1] . $found[2];
					if ( ! str_contains( $open, 'aria-label=' ) ) {
						$open .= ' aria-label="' . esc_attr( $label ) . '"';
					}
					return $open . '>' . $prefix . esc_html( $title ) . $found[3];
				},
				$html,
				1
			);
			$html    = is_string( $html ) ? $html : '';
		}//end foreach
		return $html;
	}

	/**
	 * Call the Overview's Help toggle Site Setup.
	 *
	 * @param string $translated Translated text.
	 * @param string $text       Original text.
	 * @param string $domain     Text domain.
	 */
	public static function screen_label( $translated, $text, $domain ) {
		if ( 'default' === $domain && ( 'Help' === $text || 'Help tab' === $text || 'Contextual Help Tab' === $text ) ) {
			return __( 'Site Setup', 'packout-core' );
		}
		return $translated;
	}

	/**
	 * Help panel for one setup section.
	 *
	 * @param array<string, mixed> $row    Setup row.
	 * @param string               $status Ready or Needs attention.
	 * @param bool                 $ok     Whether the section is ready.
	 */
	private static function setup_tab_html( array $row, string $status, bool $ok ): string {
		$html  = '<p>' . self::badge( $status, $ok ? 'stable' : 'medium' ) . '</p>';
		$html .= '<h3>' . esc_html( (string) ( $row['label'] ?? '' ) ) . '</h3>';
		$html .= '<p>' . esc_html( (string) ( $row['detail'] ?? '' ) ) . '</p>';
		return $html;
	}

	/**
	 * Settings and field app links in the Help sidebar.
	 *
	 * @param array{settingsUrl?: string, fieldAppUrl?: string} $setup Setup payload.
	 */
	private static function setup_sidebar_html( array $setup ): string {
		$html = '<p><strong>' . esc_html__( 'Open', 'packout-core' ) . '</strong></p>';
		if ( ! empty( $setup['settingsUrl'] ) ) {
			$html .= '<p><a href="' . esc_url( (string) $setup['settingsUrl'] ) . '">' . esc_html__( 'Open settings', 'packout-core' ) . '</a></p>';
		}
		if ( ! empty( $setup['fieldAppUrl'] ) ) {
			$html .= '<p><a href="' . esc_url( (string) $setup['fieldAppUrl'] ) . '">' . esc_html__( 'Open the field app', 'packout-core' ) . '</a></p>';
		}
		return $html;
	}

	/**
	 * Stop when the user cannot review Spots.
	 */
	public static function guard(): void {
		self::require( Capabilities::can( Capabilities::MODERATE ) );
	}

	/**
	 * Stop unless a condition holds.
	 *
	 * @param bool $allowed Whether the person may continue.
	 */
	public static function require( bool $allowed ): void {
		if ( ! $allowed ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'packout-core' ), 403 );
		}
	}

	/**
	 * A hidden control that opens one drawer pane when the address names it.
	 *
	 * @param string $id    Pane id.
	 * @param string $label What the drawer's bar says.
	 */
	public static function reopen( string $id, string $label ): void {
		if ( '' === $id ) {
			return;
		}
		echo '<button type="button" hidden data-packout-open="' . esc_attr( $id ) . '" data-packout-label="' . esc_attr( $label ) . '"></button>';
	}

	/**
	 * Badge for a mission status.
	 *
	 * @param string $status Mission status.
	 */
	public static function mission_badge( string $status ): string {
		$intents = array(
			'planned'     => 'informational',
			'in_progress' => 'medium',
			'completed'   => 'stable',
			'called_off'  => 'none',
		);
		return self::badge( Missions::statuses()[ $status ] ?? $status, $intents[ $status ] ?? 'none' );
	}

	/**
	 * The shared drawer, around the panes a screen prints.
	 *
	 * @param string   $title Heading shown until a pane sets its own.
	 * @param callable $panes Prints the panes.
	 * @param string   $classes Extra class on the drawer, such as packout-drawer--map.
	 */
	public static function drawer( string $title, callable $panes, string $classes = '' ): void {
		echo '<div class="packout-drawer' . ( '' !== $classes ? ' ' . esc_attr( $classes ) : '' ) . '" hidden>';
		echo '<button type="button" class="packout-drawer__backdrop" data-packout-close tabindex="-1" aria-label="' . esc_attr__( 'Close', 'packout-core' ) . '"></button>';
		echo '<aside class="packout-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="packout-drawer-title">';
		echo '<header class="packout-drawer__bar"><h2 id="packout-drawer-title" class="packout-drawer__title">' . esc_html( $title ) . '</h2>';
		echo '<button type="button" class="button" data-packout-close>' . esc_html__( 'Close', 'packout-core' ) . '</button></header>';
		echo '<div class="packout-drawer__body">';
		$panes();
		echo '</div></aside></div>';
	}

	/**
	 * Return to the screen a form came from, with a result.
	 *
	 * @param string                $page   Screen slug to fall back to.
	 * @param array<string, string> $result updated, error, and open.
	 */
	public static function back( string $page, array $result ): void {
		$back = wp_get_referer();
		$back = $back ? remove_query_arg( array( 'updated', 'error', 'open', 'detail' ), $back ) : admin_url( 'admin.php?page=' . $page );
		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $result ), $back ) );
		exit;
	}

	/**
	 * Markup of one component: a badge, a notice, or an empty state.
	 *
	 * @param string               $id    Component id: badge, notice, empty-state.
	 * @param array<string, mixed> $props Component props.
	 */
	public static function ui( string $id, array $props ): string {
		return Ui::component( $id, $props );
	}

	/**
	 * A vertical timeline of events for a drawer.
	 *
	 * @param array<int, array{label?: string, created_at?: string, actor?: string}> $events Newest first.
	 */
	public static function timeline( array $events ): string {
		if ( ! $events ) {
			return '<p class="description">' . esc_html__( 'No history yet.', 'packout-core' ) . '</p>';
		}
		$html = '<ol class="packout-timeline">';
		foreach ( $events as $event ) {
			$when  = (string) ( $event['created_at'] ?? '' );
			$ts    = '' !== $when ? strtotime( $when . ' UTC' ) : false;
			$meta  = $ts ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';
			$html .= '<li class="packout-timeline__item"><div class="packout-timeline__label">' . esc_html( (string) ( $event['label'] ?? '' ) ) . '</div>';
			if ( '' !== $meta ) {
				$html .= '<div class="packout-timeline__meta">' . esc_html( $meta ) . '</div>';
			}
			$html .= '</li>';
		}
		$html .= '</ol>';
		return $html;
	}

	/**
	 * A status badge.
	 *
	 * @param string $text   Label.
	 * @param string $intent Badge intent: none, informational, low, medium, high, stable, draft.
	 */
	public static function badge( string $text, string $intent = 'none' ): string {
		return self::ui(
			'badge',
			array(
				'text'   => $text,
				'intent' => $intent,
			)
		);
	}

	/**
	 * Badge for a domain status.
	 *
	 * @param string $status Domain status.
	 */
	public static function status_badge( string $status ): string {
		$intents = array(
			'spotted'     => 'medium',
			'planned'     => 'informational',
			'in_progress' => 'informational',
			'pending'     => 'low',
			'cleaned'     => 'stable',
			'watch'       => 'high',
		);
		return self::badge( Schema::statuses()[ $status ] ?? $status, $intents[ $status ] ?? 'none' );
	}

	/**
	 * Badge for the review state.
	 *
	 * @param string $post_status WordPress post status.
	 */
	public static function review_badge( string $post_status ): string {
		$labels = self::review_labels();
		$intent = array(
			'pending' => 'draft',
			'publish' => 'stable',
			'private' => 'low',
		);
		return self::badge( $labels[ $post_status ] ?? $post_status, $intent[ $post_status ] ?? 'none' );
	}

	/**
	 * Review states in the words a coordinator uses.
	 *
	 * @return array<string, string>
	 */
	public static function review_labels(): array {
		return array(
			'pending' => __( 'Pending review', 'packout-core' ),
			'publish' => __( 'On the map', 'packout-core' ),
			'private' => __( 'Crew only', 'packout-core' ),
		);
	}

	/**
	 * Result notice from the redirect query.
	 *
	 * @param array<string, string> $success Messages by key.
	 * @param array<string, string> $errors  Messages by key.
	 */
	public static function notice( array $success, array $errors = array() ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reads a result key after a redirect.
		$updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
		$error   = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( isset( $success[ $updated ] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
			echo '<div class="packout-admin__notice">' . self::ui(
				'notice',
				array(
					'intent'      => 'success',
					'description' => $success[ $updated ],
				)
			) . '</div>';
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		// A refusal from the record itself arrives as text and is shown as it was written.
		$detail = isset( $_GET['detail'] ) ? sanitize_text_field( wp_unslash( $_GET['detail'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads a result after a redirect.
		if ( isset( $errors[ $error ] ) || ( 'detail' === $error && '' !== $detail ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
			echo '<div class="packout-admin__notice">' . self::ui(
				'notice',
				array(
					'intent'      => 'error',
					'description' => $errors[ $error ] ?? $detail,
				)
			) . '</div>';
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Coordinator Overview: outcomes, queues, and a cached snapshot.
	 */
	public static function overview(): void {
		self::require( Capabilities::can( Capabilities::MODERATE ) );
		self::platform_overview();
	}

	/**
	 * Send a person who is not a coordinator to the overview of what they lead.
	 *
	 * Runs before the page prints anything. A lead of exactly one organization
	 * lands on that organization's overview.
	 */
	public static function overview_home(): void {
		if ( Capabilities::can( Capabilities::MODERATE ) ) {
			return;
		}
		wp_safe_redirect( Overview_Page::home( get_current_user_id() ) );
		exit;
	}

	/**
	 * The Platform overview.
	 */
	private static function platform_overview(): void {

		$days     = 30;
		$now      = Overview::cached_now();
		$period   = Overview::cached_period( $days );
		$spots    = admin_url( 'admin.php?page=packout-spots' );
		$missions = admin_url( 'admin.php?page=packout-missions' );
		$teams    = admin_url( 'admin.php?page=packout-teams' );
		$impact   = admin_url( 'admin.php?page=packout-impact' );
		$people   = admin_url( 'admin.php?page=' . People_Screen::PAGE );
		$places   = admin_url( 'admin.php?page=' . self::PLACES_PAGE );
		$invites  = admin_url( 'admin.php?page=' . Invitations_Screen::PAGE );
		$queues   = array(
			'reports'       => array( 'post', add_query_arg( 'review', 'pending', $spots ) ),
			'organizations' => array( 'institution', add_query_arg( 'listing', 'waiting', $teams ) ),
			'partners'      => array( 'globe', admin_url( 'admin.php?page=' . self::PARTNERS_PAGE . '&listing=waiting' ) ),
			'figures'       => array( 'caution', admin_url( 'admin.php?page=' . self::PARTNERS_PAGE ) ),
			'cleanup'       => array(
				'tool',
				add_query_arg(
					array(
						'review' => 'publish',
						'status' => 'pending',
					),
					$spots
				),
			),
			'clearance'     => array( 'shield', add_query_arg( 'clearance', 'waiting', $spots ) ),
			'impact'        => array( 'trending-up', add_query_arg( 'verification', 'unverified', $impact ) ),
			'counted'       => array(
				'seen',
				add_query_arg(
					array(
						'verification' => 'verified',
						'counted'      => 'no',
					),
					$impact
				),
			),
			'unrecorded'    => array( 'caution', add_query_arg( 'unrecorded', '1', $impact ) . '#packout-unrecorded' ),
		);

		echo '<div class="wrap packout-admin packout-overview" style="' . esc_attr( self::palette() ) . '" data-packout-days="' . esc_attr( (string) $days ) . '">';
		echo '<script type="application/json" id="packout-overview-now">' . wp_json_encode( $now ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON snapshot.
		echo '<script type="application/json" id="packout-overview-period">' . wp_json_encode( $period ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON snapshot.

		echo '<header class="packout-overview__header">';
		echo '<div class="packout-overview__title"><h1>' . esc_html__( 'Platform overview', 'packout-core' ) . '</h1>';
		echo '<p class="packout-overview__updated" data-packout-updated>' . esc_html( self::updated_label( (string) ( $now['computed_at'] ?? '' ) ) ) . '</p></div>';
		echo '<div class="packout-overview__tools">';
		echo '<label class="packout-overview__window"><span class="screen-reader-text">' . esc_html__( 'Period', 'packout-core' ) . '</span>';
		echo '<select data-packout-activity>';
		foreach ( Overview::windows() as $window ) {
			echo '<option value="' . esc_attr( (string) $window ) . '"' . selected( $days, $window, false ) . '>';
			/* translators: %d: number of days. */
			echo esc_html( sprintf( __( 'Last %d days', 'packout-core' ), $window ) );
			echo '</option>';
		}
		echo '</select></label>';
		if ( Export::can() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-overview__export">';
			echo '<input type="hidden" name="action" value="packout_export_overview" />';
			wp_nonce_field( 'packout_export_overview' );
			echo '<input type="hidden" name="days" value="' . esc_attr( (string) $days ) . '" />';
			echo '<label><span class="screen-reader-text">' . esc_html__( 'Format', 'packout-core' ) . '</span>';
			echo '<select name="format">';
			foreach ( Overview_Report::formats() as $format => $label ) {
				echo '<option value="' . esc_attr( $format ) . '"' . selected( 'pdf', $format, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></label>';
			echo '<button class="packout-overview__button">' . self::icon( 'download' ) . esc_html__( 'Export impact report', 'packout-core' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is escaped SVG.
		}
		echo '</div></header>';
		// WordPress moves its own notices to this rule, so they land under the header and not inside it.
		echo '<hr class="wp-header-end" />';

		$missing = $now['setup']['missing'] ?? array();
		if ( is_array( $missing ) && array() !== $missing ) {
			// A partner site draws its notices plainly, and the style sheet makes room for the button.
			echo '<div class="packout-overview__notice' . ( App::is_hub() ? '' : ' packout-overview__notice--plain' ) . '">';
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
			echo self::ui(
				'notice',
				array(
					'intent'      => 'medium',
					'title'       => __( 'Site Setup needs attention', 'packout-core' ),
					'description' => implode( ', ', $missing ),
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<button type="button" class="packout-overview__button packout-overview__button--quiet" data-packout-open-setup>' . esc_html__( 'Open Site Setup', 'packout-core' ) . '</button></div>';
		}

		self::overview_sync();

		echo '<div class="packout-overview__body">';
		self::overview_queue( $now['waiting'] ?? array(), $queues );
		self::overview_outcome_a( $now, $period, $spots, $missions );
		self::overview_outcome_b( $period, $now, $spots );
		self::overview_outcome_c( $period, $now, $teams, $invites );
		self::overview_outcome_d( $period, $now, $queues );
		self::overview_outcome_e( $period, $now );
		self::overview_platform( $now['platform'] ?? array(), $teams, $people, $spots, $places, admin_url( 'admin.php?page=' . self::PARTNERS_PAGE . '&listing=active' ) );
		self::overview_signals( $now['signals'] ?? array() );
		echo '</div></div>';
	}

	/**
	 * “Updated …” label for a snapshot time.
	 *
	 * @param string $when GMT ISO.
	 */
	public static function updated_label( string $when ): string {
		$ts = strtotime( $when );
		if ( false === $ts ) {
			return __( 'Updated just now', 'packout-core' );
		}
		$diff = time() - $ts;
		if ( $diff < 60 ) {
			return __( 'Updated just now', 'packout-core' );
		}
		/* translators: %s: human time difference. */
		return sprintf( __( 'Updated %s ago', 'packout-core' ), human_time_diff( $ts ) );
	}

	/**
	 * On a connected partner site: what stands between the site and packout.spot, above the queue.
	 *
	 * Drawn only when something needs someone: a send that keeps failing, a
	 * connection that stopped, a copy of the hub's map that has gone stale.
	 * The Connection screen is an administrator's, so anyone else is told
	 * who can look.
	 */
	private static function overview_sync(): void {
		if ( App::is_hub() || ! Connection::connected() ) {
			return;
		}
		$admin = current_user_can( 'manage_options' );
		foreach ( array_intersect_key( Health::checks(), array_flip( array( 'connection', 'queue', 'layers' ) ) ) as $row ) {
			if ( 'good' === $row['status'] ) {
				continue;
			}
			echo '<div class="packout-overview__notice packout-overview__notice--plain" data-pos-sync="' . esc_attr( $row['id'] ) . '">';
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- ui() escapes.
			echo self::ui(
				'notice',
				array(
					'intent'      => 'critical' === $row['status'] ? 'error' : 'warning',
					'title'       => $row['label'],
					'description' => $row['detail'] . ( $admin ? '' : ' ' . __( 'An administrator of this site can see more on the Connection screen.', 'packout-core' ) ),
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( $admin && '' !== $row['url'] ) {
				echo '<a class="packout-overview__button packout-overview__button--quiet" href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['action'] ) . '</a>';
			}
			echo '</div>';
		}
	}

	/**
	 * Waiting-on-a-coordinator panel.
	 *
	 * @param array<string, array<string, mixed>>                  $waiting Queue payloads.
	 * @param array<string, array{0: string, 1: string, 2?: bool}> $queues  Icons and URLs, and whether the list opens its oldest item.
	 * @param array<string, string>                                $labels  Queue names by key. The coordinator's queues when empty.
	 * @param string                                               $heading The panel's heading.
	 */
	public static function overview_queue( array $waiting, array $queues, array $labels = array(), string $heading = '' ): void {
		$labels  = $labels ? $labels : Overview::queues();
		$heading = '' !== $heading ? $heading : __( 'Waiting on a coordinator', 'packout-core' );
		$open    = 0;
		$worst   = '';
		$days    = -1;
		foreach ( $labels as $key => $label ) {
			$row   = $waiting[ $key ] ?? array();
			$count = (int) ( $row['count'] ?? 0 );
			$open += $count;
			if ( $count > 0 && (int) ( $row['days'] ?? 0 ) > $days ) {
				$days  = (int) ( $row['days'] ?? 0 );
				$worst = (string) ( $row['age'] ?? '' );
			}
		}
		echo '<section class="packout-overview__panel packout-overview__waiting">';
		echo '<h2>' . esc_html( $heading );
		echo ' <span class="packout-overview__tally" data-packout-queue-heading' . ( 0 === $open ? ' hidden' : '' ) . '>';
		if ( $open > 0 ) {
			/* translators: 1: open items, 2: oldest age. */
			echo esc_html( '' !== $worst ? sprintf( __( '%1$s open, oldest %2$s', 'packout-core' ), number_format_i18n( $open ), $worst ) : sprintf( __( '%s open', 'packout-core' ), number_format_i18n( $open ) ) );
		}
		echo '</span></h2>';
		echo '<p class="packout-overview__empty" data-packout-queue-empty' . ( $open > 0 ? ' hidden' : '' ) . '><span class="packout-overview__chip packout-overview__chip--clear">' . self::icon( 'check' ) . '</span>' . esc_html__( 'Nothing is waiting.', 'packout-core' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is escaped SVG.
		echo '<table class="packout-overview__queue"' . ( 0 === $open ? ' hidden' : '' ) . ' data-packout-queue-table><thead><tr><th scope="col">' . esc_html__( 'Queue', 'packout-core' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Open', 'packout-core' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Oldest', 'packout-core' ) . '</th>';
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Review oldest', 'packout-core' ) . '</span></th></tr></thead><tbody data-packout-queue-body>';
		$zeros = array();
		foreach ( $labels as $key => $label ) {
			$row  = $waiting[ $key ] ?? array();
			$url  = $queues[ $key ][1] ?? '';
			$icon = $queues[ $key ][0] ?? 'post';
			if ( (int) ( $row['count'] ?? 0 ) <= 0 ) {
				$zeros[ $key ] = array( $label, $url );
			}
			self::overview_queue_row( $key, $label, $icon, $url, $row, $queues[ $key ][2] ?? true );
		}
		echo '</tbody></table>';
		echo '<p class="packout-overview__zeros" data-packout-queue-zeros' . ( array() === $zeros || 0 === $open ? ' hidden' : '' ) . '>';
		echo '<span class="packout-overview__zeros-label">' . esc_html__( 'Nothing waiting:', 'packout-core' ) . '</span>';
		foreach ( $labels as $key => $label ) {
			$url = $queues[ $key ][1] ?? '';
			echo '<a href="' . esc_url( $url ) . '" data-packout-zero="' . esc_attr( $key ) . '"' . ( isset( $zeros[ $key ] ) ? '' : ' hidden' ) . '>' . self::icon( 'check' ) . esc_html( $label ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is escaped SVG.
		}
		echo '</p></section>';
	}

	/**
	 * One non-zero queue row.
	 *
	 * @param string               $key   Queue key.
	 * @param string               $label Queue name.
	 * @param string               $icon  Icon slug.
	 * @param string               $url   List URL.
	 * @param array<string, mixed> $row   Payload.
	 * @param bool                 $opens Whether the list opens its oldest item when the address names it.
	 */
	public static function overview_queue_row( string $key, string $label, string $icon, string $url, array $row, bool $opens = true ): void {
		$open  = $opens ? self::with_open( $url, (int) ( $row['oldest_id'] ?? 0 ) ) : $url;
		$count = (int) ( $row['count'] ?? 0 );
		echo '<tr data-packout-queue-key="' . esc_attr( $key ) . '"' . ( $count > 0 ? '' : ' hidden' ) . '>';
		echo '<th scope="row"><a class="packout-overview__queue-name" href="' . esc_url( $url ) . '"><span class="packout-overview__chip">' . self::icon( $icon ) . '</span>' . esc_html( $label ) . '</a></th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is escaped SVG.
		echo '<td><span class="packout-overview__badge" data-packout-path="waiting.' . esc_attr( $key ) . '.count">' . esc_html( number_format_i18n( $count ) ) . '</span></td>';
		echo '<td data-label="' . esc_attr__( 'Oldest', 'packout-core' ) . '">';
		self::overview_age( $row, 'waiting.' . $key . '.age' );
		echo '</td>';
		echo '<td class="packout-overview__queue-act"><a href="' . esc_url( $open ) . '" class="packout-overview__go">' . esc_html__( 'Review oldest', 'packout-core' ) . self::icon( 'chevron-right' ) . '</a></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is escaped SVG.
		echo '</tr>';
	}

	/**
	 * Outcome A.
	 *
	 * @param array<string, mixed> $now      Current snapshot.
	 * @param array<string, mixed> $period   Period snapshot.
	 * @param string               $spots    Spots screen.
	 * @param string               $missions Missions screen.
	 */
	private static function overview_outcome_a( array $now, array $period, string $spots, string $missions ): void {
		$a      = $period['a'] ?? array();
		$cur    = $a['current'] ?? array();
		$prev   = $a['previous'] ?? array();
		$levers = $now['levers']['a'] ?? array();
		self::overview_outcome_open( 'a', __( 'Does a report lead to action?', 'packout-core' ), (string) ( $a['reading'] ?? '' ) );
		self::overview_stack( $now['stages'] ?? array(), $spots );
		$mission = $now['missions'] ?? array();
		echo '<p class="packout-overview__missions">';
		echo '<a href="' . esc_url( add_query_arg( 'status', 'open', $missions ) ) . '"><strong data-packout-path="missions.count">' . esc_html( number_format_i18n( (int) ( $mission['count'] ?? 0 ) ) ) . '</strong> ' . esc_html__( 'open missions', 'packout-core' ) . '</a>';
		echo '<a href="' . esc_url( add_query_arg( 'status', 'planned', $missions ) ) . '"><strong data-packout-path="missions.planned">' . esc_html( number_format_i18n( (int) ( $mission['planned'] ?? 0 ) ) ) . '</strong> ' . esc_html__( 'planned', 'packout-core' ) . '</a>';
		echo '<a href="' . esc_url( add_query_arg( 'status', 'in_progress', $missions ) ) . '"><strong data-packout-path="missions.in_progress">' . esc_html( number_format_i18n( (int) ( $mission['in_progress'] ?? 0 ) ) ) . '</strong> ' . esc_html__( 'in progress', 'packout-core' ) . '</a>';
		echo '</p>';
		echo '</div><div class="packout-overview__proof">';
		echo '<dl class="packout-overview__evidence">';
		self::overview_stat( __( 'Reported', 'packout-core' ), 'a.current.reported', $cur['reported'] ?? 0, $prev['reported'] ?? 0 );
		self::overview_stat( __( 'Cleaned', 'packout-core' ), 'a.current.cleaned', $cur['cleaned'] ?? 0, $prev['cleaned'] ?? 0 );
		self::overview_stat( __( 'Net open', 'packout-core' ), 'a.current.net_open', $cur['net_open'] ?? 0, $prev['net_open'] ?? 0, true );
		self::overview_stat( __( 'Median days to cleaned', 'packout-core' ), 'a.current.median_days', $cur['median_days'] ?? null, $prev['median_days'] ?? null );
		echo '</dl>';
		echo '<div class="packout-overview__chart-wrap" data-packout-metric="activity"><div class="packout-overview__chart"><canvas role="img" aria-label="' . esc_attr__( 'Reports and cleanups', 'packout-core' ) . '"></canvas></div></div>';
		self::overview_levers_open();
		self::overview_lever(
			__( 'Spotted, no mission', 'packout-core' ),
			'pin',
			add_query_arg(
				array(
					'review'     => 'publish',
					'status'     => 'spotted',
					'actionable' => '1',
				),
				$spots
			),
			$levers['spotted'] ?? array(),
			'a.spotted'
		);
		self::overview_lever(
			__( 'Came back, no mission', 'packout-core' ),
			'caution',
			add_query_arg(
				array(
					'review' => 'publish',
					'status' => 'watch',
				),
				$spots
			),
			$levers['watch'] ?? array(),
			'a.watch'
		);
		self::overview_lever(
			__( 'Missions past their window', 'packout-core' ),
			'scheduled',
			add_query_arg(
				array(
					'status'  => 'open',
					'overdue' => '1',
				),
				$missions
			),
			$levers['overdue'] ?? array(),
			'a.overdue'
		);
		self::overview_lever(
			__( 'Left for official channels', 'packout-core' ),
			'external',
			add_query_arg(
				array(
					'review' => 'publish',
					'hazard' => 'do_not_touch',
				),
				$spots
			),
			$levers['do_not_touch'] ?? array(),
			'a.do_not_touch',
			false
		);
		echo '</ul></div></section>';
	}

	/**
	 * Outcome B.
	 *
	 * @param array<string, mixed> $period Period snapshot.
	 * @param array<string, mixed> $now    Current snapshot.
	 * @param string               $spots  Spots screen.
	 */
	private static function overview_outcome_b( array $period, array $now, string $spots ): void {
		$b    = $period['b'] ?? array();
		$cur  = $b['current'] ?? array();
		$prev = $b['previous'] ?? array();
		self::overview_outcome_open( 'b', __( 'Do cleaned places stay clean?', 'packout-core' ), (string) ( $b['reading'] ?? '' ) );
		echo '</div><div class="packout-overview__proof">';
		echo '<dl class="packout-overview__evidence">';
		self::overview_stat( __( 'Still clear', 'packout-core' ), 'b.current.still_clear', $cur['still_clear'] ?? 0, $prev['still_clear'] ?? 0 );
		self::overview_stat( __( 'Came back', 'packout-core' ), 'b.current.returned', $cur['returned'] ?? 0, $prev['returned'] ?? 0 );
		self::overview_ratio( __( 'Cleaned Spots revisited', 'packout-core' ), 'b.current.visited', $cur['visited'] ?? 0, 'b.current.cleaned_total', $cur['cleaned_total'] ?? 0 );
		echo '</dl>';
		self::overview_levers_open();
		self::overview_lever(
			__( 'Cleaned, not revisited in 90 days', 'packout-core' ),
			'update',
			add_query_arg(
				array(
					'review'  => 'publish',
					'status'  => 'cleaned',
					'revisit' => 'due',
				),
				$spots
			),
			$now['levers']['b']['due'] ?? array(),
			'b.due'
		);
		echo '</ul></div></section>';
	}

	/**
	 * Outcome C.
	 *
	 * @param array<string, mixed> $period  Period snapshot.
	 * @param array<string, mixed> $now     Current snapshot.
	 * @param string               $teams   Organizations screen.
	 * @param string               $invites Invitations screen.
	 */
	private static function overview_outcome_c( array $period, array $now, string $teams, string $invites ): void {
		$c      = $period['c'] ?? array();
		$cur    = $c['current'] ?? array();
		$prev   = $c['previous'] ?? array();
		$levers = $now['levers']['c'] ?? array();
		self::overview_outcome_open( 'c', __( 'Is the community doing the work?', 'packout-core' ), (string) ( $c['reading'] ?? '' ) );
		echo '</div><div class="packout-overview__proof">';
		echo '<dl class="packout-overview__evidence">';
		self::overview_ratio( __( 'Organizations that cleaned', 'packout-core' ), 'c.current.orgs_active', $cur['orgs_active'] ?? 0, 'c.current.orgs_approved', $cur['orgs_approved'] ?? 0 );
		self::overview_ratio( __( 'Largest share of cleanups', 'packout-core' ), 'c.current.largest_share', $cur['largest_share'] ?? 0, 'c.current.largest_of', $cur['largest_of'] ?? 0 );
		self::overview_stat( __( 'By a mission', 'packout-core' ), 'c.current.by_mission', $cur['by_mission'] ?? 0, $prev['by_mission'] ?? 0 );
		self::overview_stat( __( 'By one person', 'packout-core' ), 'c.current.by_person', $cur['by_person'] ?? 0, $prev['by_person'] ?? 0 );
		self::overview_ratio( __( 'People who reported or cleaned', 'packout-core' ), 'c.current.people_active', $cur['people_active'] ?? 0, 'c.current.people_registered', $cur['people_registered'] ?? 0 );
		self::overview_stat( __( 'People who joined', 'packout-core' ), 'c.current.people_joined', $cur['people_joined'] ?? 0, $prev['people_joined'] ?? 0 );
		echo '</dl>';
		self::overview_levers_open();
		self::overview_lever( __( 'Organizations to approve', 'packout-core' ), 'institution', add_query_arg( 'listing', 'waiting', $teams ), $levers['organizations'] ?? array(), 'c.organizations' );
		self::overview_lever(
			__( 'Approved organizations with no mission in 90 days', 'packout-core' ),
			'scheduled',
			add_query_arg(
				array(
					'listing' => 'approved',
					'dormant' => '1',
				),
				$teams
			),
			$levers['dormant'] ?? array(),
			'c.dormant'
		);
		self::overview_lever( __( 'Invitations that lapsed unused', 'packout-core' ), 'envelope', add_query_arg( 'status', 'expired', $invites ), $levers['invitations'] ?? array(), 'c.invitations', false );
		echo '</ul></div></section>';
	}

	/**
	 * Outcome D.
	 *
	 * @param array<string, mixed>                       $period Period snapshot.
	 * @param array<string, mixed>                       $now    Current snapshot.
	 * @param array<string, array{0: string, 1: string}> $queues Queue URLs.
	 */
	private static function overview_outcome_d( array $period, array $now, array $queues ): void {
		$d      = $period['d'] ?? array();
		$cur    = $d['current'] ?? array();
		$levers = $now['levers']['d'] ?? array();
		self::overview_outcome_open( 'd', __( 'Would a partner trust the record?', 'packout-core' ), (string) ( $d['reading'] ?? '' ) );
		echo '</div><div class="packout-overview__proof">';
		echo '<dl class="packout-overview__evidence">';
		self::overview_ratio( __( 'Verified cleanups', 'packout-core' ), 'd.current.verified', $cur['verified'] ?? 0, 'd.current.total_cleanups', $cur['total_cleanups'] ?? 0 );
		self::overview_ratio( __( 'Counted publicly', 'packout-core' ), 'd.current.counted', $cur['counted'] ?? 0, 'd.current.total_cleanups', $cur['total_cleanups'] ?? 0 );
		self::overview_ratio( __( 'Cleaned Spots with a record', 'packout-core' ), 'd.current.cleaned_with_record', $cur['cleaned_with_record'] ?? 0, 'd.current.cleaned', $cur['cleaned'] ?? 0 );
		echo '</dl>';
		// Measured and self-reported figures sit side by side and are never added together.
		echo '<dl class="packout-overview__evidence">';
		self::overview_figure( __( 'Pounds measured', 'packout-core' ), 'd.current.pounds_measured', $cur['pounds_measured'] ?? 0 );
		self::overview_figure( __( 'Pounds self-reported', 'packout-core' ), 'd.current.pounds_self_reported', $cur['pounds_self_reported'] ?? 0 );
		self::overview_figure( __( 'Hours verified', 'packout-core' ), 'd.current.hours_verified', $cur['hours_verified'] ?? 0 );
		self::overview_figure( __( 'Hours unverified', 'packout-core' ), 'd.current.hours_unverified', $cur['hours_unverified'] ?? 0 );
		echo '</dl>';
		self::overview_levers_open();
		self::overview_lever( __( 'Unverified impact records', 'packout-core' ), 'trending-up', $queues['impact'][1] ?? '', $levers['impact'] ?? array(), 'd.impact' );
		self::overview_lever( __( 'Verified records not counted publicly', 'packout-core' ), 'seen', $queues['counted'][1] ?? '', $levers['counted'] ?? array(), 'd.counted' );
		self::overview_lever( __( 'Cleaned Spots without impact records', 'packout-core' ), 'caution', $queues['unrecorded'][1] ?? '', $levers['unrecorded'] ?? array(), 'd.unrecorded' );
		self::overview_lever( __( 'Spots needing clearance', 'packout-core' ), 'shield', $queues['clearance'][1] ?? '', $levers['clearance'] ?? array(), 'd.clearance' );
		echo '</ul></div></section>';
	}

	/**
	 * Outcome E.
	 *
	 * @param array<string, mixed> $period Period snapshot.
	 * @param array<string, mixed> $now    Current snapshot.
	 */
	private static function overview_outcome_e( array $period, array $now ): void {
		$e    = $period['e'] ?? array();
		$cur  = $e['current'] ?? array();
		$prev = $e['previous'] ?? array();
		self::overview_outcome_open( 'e', __( 'Does it work where the mess is?', 'packout-core' ), (string) ( $e['reading'] ?? '' ) );
		echo '</div><div class="packout-overview__proof">';
		echo '<h3 class="packout-overview__label">' . esc_html__( 'How reports arrived', 'packout-core' ) . '</h3>';
		echo '<dl class="packout-overview__evidence">';
		self::overview_stat( __( 'Field application', 'packout-core' ), 'e.current.field', $cur['field'] ?? 0, $prev['field'] ?? 0 );
		self::overview_stat( __( 'Web form', 'packout-core' ), 'e.current.form', $cur['form'] ?? 0, $prev['form'] ?? 0 );
		self::overview_stat( __( 'No pin', 'packout-core' ), 'e.current.no_pin', $cur['no_pin'] ?? 0, $prev['no_pin'] ?? 0 );
		echo '</dl>';
		echo '<h3 class="packout-overview__label">' . esc_html__( 'What review did with them', 'packout-core' ) . '</h3>';
		echo '<dl class="packout-overview__evidence">';
		self::overview_stat( __( 'Published', 'packout-core' ), 'e.current.published', $cur['published'] ?? 0, $prev['published'] ?? 0 );
		self::overview_stat( __( 'Kept private', 'packout-core' ), 'e.current.private', $cur['private'] ?? 0, $prev['private'] ?? 0 );
		self::overview_stat( __( 'Returned', 'packout-core' ), 'e.current.returned', $cur['returned'] ?? 0, $prev['returned'] ?? 0 );
		self::overview_stat( __( 'Linked as duplicate', 'packout-core' ), 'e.current.duplicate', $cur['duplicate'] ?? 0, $prev['duplicate'] ?? 0 );
		self::overview_stat( __( 'Still waiting', 'packout-core' ), 'e.current.waiting', $cur['waiting'] ?? 0, $prev['waiting'] ?? 0 );
		echo '</dl>';
		$regions = $now['levers']['e']['regions'] ?? array();
		if ( empty( $regions['ok'] ) ) {
			echo '<h3 class="packout-overview__label">' . esc_html__( 'What moves it', 'packout-core' ) . '</h3>';
			echo '<div class="packout-overview__lever-note"><span class="packout-overview__chip">' . self::icon( 'map-marker' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is escaped SVG.
			echo '<span class="packout-overview__lever-label">' . esc_html( (string) ( $regions['detail'] ?? '' ) ) . '</span>';
			echo '<button type="button" class="packout-overview__button packout-overview__button--quiet" data-packout-open-setup>' . esc_html__( 'Open Site Setup', 'packout-core' ) . '</button></div>';
		}
		echo '</div></section>';
	}

	/**
	 * One-line platform inventory.
	 *
	 * @param array<string, int> $platform Counts.
	 * @param string             $teams    Organizations screen.
	 * @param string             $people   People screen.
	 * @param string             $spots    Spots screen.
	 * @param string             $places   Places screen.
	 * @param string             $partners Partners screen, on the approved ones.
	 */
	private static function overview_platform( array $platform, string $teams, string $people, string $spots, string $places, string $partners ): void {
		$items = array(
			'organizations' => array( __( 'approved organizations', 'packout-core' ), add_query_arg( 'listing', 'approved', $teams ) ),
			'partners'      => array( __( 'approved partners', 'packout-core' ), $partners ),
			'people'        => array( __( 'registered people', 'packout-core' ), $people ),
			'spots'         => array( __( 'published Spots', 'packout-core' ), add_query_arg( 'review', 'publish', $spots ) ),
			'places'        => array( __( 'listed Places', 'packout-core' ), $places ),
			'adopted'       => array( __( 'with an adopter', 'packout-core' ), '' ),
		);
		// Partners and Places are the hub's.
		if ( ! App::is_hub() ) {
			unset( $items['partners'], $items['places'], $items['adopted'] );
		}
		echo '<section class="packout-overview__platform"><h2>' . esc_html( App::is_hub() ? __( 'On the platform', 'packout-core' ) : __( 'On this site', 'packout-core' ) ) . '</h2><ul>';
		foreach ( $items as $key => $item ) {
			$figure = '<strong data-packout-path="platform.' . esc_attr( $key ) . '">' . esc_html( number_format_i18n( (int) ( $platform[ $key ] ?? 0 ) ) ) . '</strong> ' . esc_html( $item[0] );
			echo '<li>';
			echo '' !== $item[1] ? '<a href="' . esc_url( $item[1] ) . '">' . $figure . '</a>' : '<span>' . $figure . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			echo '</li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Closed product-signals section.
	 *
	 * @param array<string, mixed> $signals Counts.
	 */
	private static function overview_signals( array $signals ): void {
		echo '<details class="packout-overview__signals"><summary>' . self::icon( 'chevron-right' ) . esc_html__( 'Product signals', 'packout-core' ) . '</summary><dl>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is escaped SVG.
		$categories = $signals['categories'] ?? array();
		foreach ( Schema::categories() as $slug => $label ) {
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd data-packout-path="signals.categories.' . esc_attr( $slug ) . '">' . esc_html( number_format_i18n( (int) ( $categories[ $slug ] ?? 0 ) ) ) . '</dd></div>';
		}
		echo '<div><dt>' . esc_html__( 'Reports from the field application, all time', 'packout-core' ) . '</dt><dd><span data-packout-path="signals.field">' . esc_html( number_format_i18n( (int) ( $signals['field'] ?? 0 ) ) ) . '</span> / <span data-packout-path="signals.reports">' . esc_html( number_format_i18n( (int) ( $signals['reports'] ?? 0 ) ) ) . '</span></dd></div>';
		echo '<div><dt>' . esc_html__( 'Duplicate links made', 'packout-core' ) . '</dt><dd data-packout-path="signals.duplicates">' . esc_html( number_format_i18n( (int) ( $signals['duplicates'] ?? 0 ) ) ) . '</dd></div>';
		echo '<div><dt>' . esc_html__( 'Cleaned Spots never revisited', 'packout-core' ) . '</dt><dd data-packout-path="signals.never_revisited">' . esc_html( number_format_i18n( (int) ( $signals['never_revisited'] ?? 0 ) ) ) . '</dd></div>';
		echo '<div><dt>' . esc_html__( 'Spots with photographs hidden', 'packout-core' ) . '</dt><dd data-packout-path="signals.hidden_photos">' . esc_html( number_format_i18n( (int) ( $signals['hidden_photos'] ?? 0 ) ) ) . '</dd></div>';
		echo '<div><dt>' . esc_html__( 'Spots with a recorded clearance', 'packout-core' ) . '</dt><dd data-packout-path="signals.clearances">' . esc_html( number_format_i18n( (int) ( $signals['clearances'] ?? 0 ) ) ) . '</dd></div>';
		echo '<div><dt>' . esc_html__( 'Adopted Places', 'packout-core' ) . '</dt><dd data-packout-path="signals.adopted_places">' . esc_html( number_format_i18n( (int) ( $signals['adopted_places'] ?? 0 ) ) ) . '</dd></div>';
		echo '</dl></details>';
	}

	/**
	 * Open an outcome panel: the question and its reading. The caller closes the story column.
	 *
	 * @param string $key      Outcome letter.
	 * @param string $question The heading.
	 * @param string $reading  One sentence.
	 */
	public static function overview_outcome_open( string $key, string $question, string $reading ): void {
		echo '<section class="packout-overview__panel packout-overview__outcome packout-overview__outcome--' . esc_attr( $key ) . '">';
		echo '<div class="packout-overview__story">';
		echo '<h2>' . esc_html( $question ) . '</h2>';
		echo '<p class="packout-overview__reading" data-packout-path="' . esc_attr( $key ) . '.reading">' . esc_html( $reading ) . '</p>';
	}

	/**
	 * Open the list of linked rows that move an outcome. The caller closes the list.
	 */
	public static function overview_levers_open(): void {
		echo '<h3 class="packout-overview__label">' . esc_html__( 'What moves it', 'packout-core' ) . '</h3><ul class="packout-overview__levers">';
	}

	/**
	 * One evidence figure with the previous window beside it.
	 *
	 * @param string         $label    Caption.
	 * @param string         $path     JSON path of the current value.
	 * @param int|float|null $value    Current.
	 * @param int|float|null $previous Previous.
	 * @param bool           $signed   Show a leading plus on positive numbers.
	 */
	public static function overview_stat( string $label, string $path, $value, $previous, bool $signed = false ): void {
		echo '<div><dt>' . esc_html( $label ) . '</dt><dd>';
		echo '<span class="packout-overview__num" data-packout-path="' . esc_attr( $path ) . '">' . esc_html( self::overview_number( $value, $signed ) ) . '</span>';
		$prev_path = str_replace( '.current.', '.previous.', $path );
		/* translators: %s: the figure for the previous period. */
		echo ' <span class="packout-overview__prev" data-packout-prev="' . esc_attr( $prev_path ) . '">' . esc_html( sprintf( __( 'was %s', 'packout-core' ), self::overview_number( $previous, $signed ) ) ) . '</span>';
		echo '</dd></div>';
	}

	/**
	 * One evidence figure that is a count out of a total.
	 *
	 * @param string $label   Caption.
	 * @param string $path    JSON path of the count.
	 * @param mixed  $value   The count.
	 * @param string $of_path JSON path of the total.
	 * @param mixed  $of      The total.
	 */
	public static function overview_ratio( string $label, string $path, $value, string $of_path, $of ): void {
		echo '<div><dt>' . esc_html( $label ) . '</dt><dd>';
		printf(
			/* translators: 1: a count, 2: the total it is part of. */
			esc_html__( '%1$s of %2$s', 'packout-core' ),
			'<span class="packout-overview__num" data-packout-path="' . esc_attr( $path ) . '">' . esc_html( number_format_i18n( (int) $value ) ) . '</span>',
			'<span data-packout-path="' . esc_attr( $of_path ) . '">' . esc_html( number_format_i18n( (int) $of ) ) . '</span>'
		);
		echo '</dd></div>';
	}

	/**
	 * One evidence figure with nothing beside it.
	 *
	 * @param string $label Caption.
	 * @param string $path  JSON path of the value.
	 * @param mixed  $value The figure.
	 */
	public static function overview_figure( string $label, string $path, $value ): void {
		echo '<div><dt>' . esc_html( $label ) . '</dt><dd>';
		echo '<span class="packout-overview__num" data-packout-path="' . esc_attr( $path ) . '">' . esc_html( (string) $value ) . '</span>';
		echo '</dd></div>';
	}

	/**
	 * Format a figure, or an em dash when empty.
	 *
	 * @param mixed $value  Number or null.
	 * @param bool  $signed Leading plus.
	 */
	private static function overview_number( $value, bool $signed = false ): string {
		if ( null === $value || '' === $value ) {
			return '—';
		}
		$n = is_numeric( $value ) ? 0 + $value : 0;
		if ( $signed && $n > 0 ) {
			return '+' . number_format_i18n( $n );
		}
		return number_format_i18n( $n );
	}

	/**
	 * A linked row that moves an outcome.
	 *
	 * @param string               $label Caption.
	 * @param string               $icon  Icon slug.
	 * @param string               $url   List URL.
	 * @param array<string, mixed> $row   Count and age.
	 * @param string               $path  Outcome letter and lever key, as in the snapshot.
	 * @param bool                 $open  Append open=oldest.
	 */
	public static function overview_lever( string $label, string $icon, string $url, array $row, string $path, bool $open = true ): void {
		$count = (int) ( $row['count'] ?? 0 );
		$href  = $open && $count > 0 ? self::with_open( $url, (int) ( $row['oldest_id'] ?? 0 ) ) : $url;
		echo '<li><a class="packout-overview__lever' . ( 0 === $count ? ' is-zero' : '' ) . '" href="' . esc_url( $href ) . '">';
		echo '<span class="packout-overview__chip">' . self::icon( $icon ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is escaped SVG.
		echo '<span class="packout-overview__lever-label">' . esc_html( $label ) . '</span>';
		if ( $count > 0 && '' !== (string) ( $row['age'] ?? '' ) ) {
			self::overview_age( $row, '', true );
		}
		echo '<span class="packout-overview__badge" data-packout-path="levers.' . esc_attr( $path ) . '.count">' . esc_html( number_format_i18n( $count ) ) . '</span>';
		echo self::icon( 'chevron-right', 'packout-overview__icon packout-overview__chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is escaped SVG.
		echo '</a></li>';
	}

	/**
	 * Age with a stale clock.
	 *
	 * @param array<string, mixed> $row    Payload.
	 * @param string               $path   JSON path of the age, when the page refreshes it in place.
	 * @param bool                 $oldest Say that the age is of the oldest item.
	 */
	public static function overview_age( array $row, string $path = '', bool $oldest = false ): void {
		$age = (string) ( $row['age'] ?? '' );
		echo '<span class="packout-overview__age' . ( ! empty( $row['stale'] ) ? ' is-stale' : '' ) . '">';
		if ( ! empty( $row['stale'] ) ) {
			echo self::icon( 'time' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is escaped SVG.
		}
		if ( $oldest && '' !== $age ) {
			/* translators: %s: age such as "12 days". */
			$age = sprintf( __( 'oldest %s', 'packout-core' ), $age );
		}
		echo '<span' . ( '' !== $path ? ' data-packout-path="' . esc_attr( $path ) . '"' : '' ) . '>' . esc_html( '' !== $age ? $age : '—' ) . '</span>';
		echo '</span>';
	}

	/**
	 * Published stage mix as one stacked bar and its key.
	 *
	 * @param array<string, int> $stages Counts.
	 * @param string             $spots  Spots screen.
	 */
	public static function overview_stack( array $stages, string $spots ): void {
		echo '<h3 class="packout-overview__label">' . esc_html__( 'Published Spots by stage', 'packout-core' ) . '</h3>';
		echo '<div class="packout-overview__stack" data-packout-stack>';
		foreach ( Schema::statuses() as $status => $label ) {
			$count = (int) ( $stages[ $status ] ?? 0 );
			$url   = add_query_arg(
				array(
					'review' => 'publish',
					'status' => $status,
				),
				$spots
			);
			echo '<a class="packout-overview__stack-seg packout-overview__stack-seg--' . esc_attr( $status ) . '" href="' . esc_url( $url ) . '" style="flex-grow:' . esc_attr( (string) $count ) . '" title="' . esc_attr( $label ) . '"' . ( $count > 0 ? '' : ' hidden' ) . '><span class="screen-reader-text">' . esc_html( $label ) . '</span></a>';
		}
		echo '</div><ul class="packout-overview__stack-legend">';
		foreach ( Schema::statuses() as $status => $label ) {
			$url = add_query_arg(
				array(
					'review' => 'publish',
					'status' => $status,
				),
				$spots
			);
			echo '<li><a href="' . esc_url( $url ) . '"><span class="packout-overview__dot packout-overview__stack-seg--' . esc_attr( $status ) . '"></span><span class="packout-overview__stack-name">' . esc_html( $label ) . '</span><strong data-packout-path="stages.' . esc_attr( $status ) . '">' . esc_html( number_format_i18n( (int) ( $stages[ $status ] ?? 0 ) ) ) . '</strong></a></li>';
		}
		echo '</ul>';
	}

	/**
	 * Add open=id to a list URL when an oldest item exists.
	 *
	 * @param string $url List URL.
	 * @param int    $id  Record ID.
	 */
	public static function with_open( string $url, int $id ): string {
		if ( $id <= 0 ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		$hash  = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
		$base  = $hash ? substr( $url, 0, -strlen( $hash ) ) : $url;
		return add_query_arg( 'open', (string) $id, $base ) . $hash;
	}

	/**
	 * The site's palette as custom properties on the Overview.
	 */
	public static function palette(): string {
		return Ui::palette();
	}

	/**
	 * The site's nine palette colors, in the order of `Kernel::COLORS`.
	 *
	 * The Overview and the Impact report both draw from these, so a site that
	 * changes its palette changes both.
	 *
	 * @return array<int, string>
	 */
	public static function colors(): array {
		return array_values( Ui::colors() );
	}
}
