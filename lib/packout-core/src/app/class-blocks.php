<?php
/**
 * Public blocks.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Pack-Out Spot map, the Report prompt, the Team list, the Impact summary, Sign up, Sign in, and the Team workspace.
 *
 * Editors place these on ordinary pages. The plugin does not create pages.
 * The map canvas is MapLibre. Badges, the hazard notice, and the empty state
 * are components the plugin's screens adapter draws.
 */
class Blocks {

	/**
	 * Register the blocks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_page' ), 1 );
		add_action( 'enqueue_block_assets', array( self::class, 'editor_canvas' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'preview_attributes' ), 10, 3 );
	}

	/**
	 * Let the editor preview a block that another plugin has added attributes to.
	 *
	 * A plugin can add an attribute to every block in the editor without
	 * declaring it on the server. The editor sends it with each request for a
	 * preview, and WordPress refuses the request: the block shows an error
	 * where its preview belongs. A preview reads only what the block declares,
	 * so the rest is dropped before the request is checked.
	 *
	 * @param mixed            $result  Response to send instead, when one is set.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function preview_attributes( $result, $server, $request ) {
		unset( $server );
		$prefix = '/wp/v2/block-renderer/';
		$route  = $request instanceof \WP_REST_Request ? (string) $request->get_route() : '';
		if ( ! str_starts_with( $route, $prefix . 'packout/' ) ) {
			return $result;
		}
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( substr( $route, strlen( $prefix ) ) );
		$sent = $request->get_param( 'attributes' );
		if ( $type && is_array( $sent ) ) {
			$request->set_param( 'attributes', array_intersect_key( $sent, $type->get_attributes() ) );
		}
		return $result;
	}

	/**
	 * Keep the component styles off the editor's own page.
	 *
	 * The block styles depend on the component styles, and the editor loads every block
	 * style on the page that holds its toolbars and panels. The component
	 * styles there are a second copy of the ones WordPress already loaded, and
	 * arriving later they undo the editor's layout: the page comes up blank.
	 * The canvas still gets them, from editor_canvas().
	 */
	public static function editor_page(): void {
		$styles = wp_styles();
		foreach ( array( 'packout-blocks', 'packout-workspace' ) as $handle ) {
			if ( isset( $styles->registered[ $handle ] ) ) {
				$styles->registered[ $handle ]->deps = array_values( array_diff( $styles->registered[ $handle ]->deps, Ui::styles() ) );
			}
		}
	}

	/**
	 * Component styles inside the editor's canvas, where the blocks are drawn.
	 *
	 * WordPress turns editor loading off while it gathers the styles for the
	 * canvas, which is how this tells the canvas from the page around it.
	 */
	public static function editor_canvas(): void {
		if ( ! is_admin() || wp_should_load_block_editor_scripts_and_styles() ) {
			return;
		}
		foreach ( Ui::styles() as $handle ) {
			if ( wp_style_is( $handle, 'registered' ) ) {
				wp_enqueue_style( $handle );
			}
		}
	}

	/**
	 * MapLibre and the PMTiles reader, bundled with the plugin.
	 */
	public static function register_map_library(): void {
		wp_register_script( 'packout-maplibre', plugins_url( 'assets/lib/maplibre-gl.js', PACKOUT_CORE_FILE ), array(), '5.24.1', true );
		wp_register_style( 'packout-maplibre', plugins_url( 'assets/lib/maplibre-gl.css', PACKOUT_CORE_FILE ), array(), '5.24.1' );
		wp_register_script( 'packout-pmtiles', plugins_url( 'assets/lib/pmtiles.js', PACKOUT_CORE_FILE ), array(), '4.5.0', true );
	}

	/**
	 * Block types and their assets.
	 */
	public static function blocks(): void {
		self::register_map_library();
		Search::register_assets();
		wp_register_script( 'packout-slider', plugins_url( 'assets/js/slider.js', PACKOUT_CORE_FILE ), array(), PACKOUT_CORE_VERSION, true );
		wp_localize_script(
			'packout-slider',
			'packoutSliderText',
			array(
				'label'    => __( 'Photos', 'packout-core' ),
				'previous' => __( 'Previous photo', 'packout-core' ),
				'next'     => __( 'Next photo', 'packout-core' ),
				/* translators: 1: number of this photo, 2: number of photos. */
				'count'    => __( 'Photo %1$s of %2$s', 'packout-core' ),
				/* translators: 1: number of this photo, 2: number of photos. */
				'short'    => __( '%1$s / %2$s', 'packout-core' ),
				/* translators: %s: number of the photo. */
				'show'     => __( 'Show photo %s', 'packout-core' ),
				'zoom'     => __( 'See the photo larger', 'packout-core' ),
				'close'    => __( 'Close', 'packout-core' ),
				/* translators: 1: number of this photo, 2: number of photos. */
				'alt'      => __( 'Photo %1$s of %2$s from the report', 'packout-core' ),
			)
		);
		wp_register_script( 'packout-map-editor', plugins_url( 'assets/js/map-editor.js', PACKOUT_CORE_FILE ), array_merge( array( 'packout-maplibre', 'packout-search' ), Ui::scripts() ), PACKOUT_CORE_VERSION, true );
		wp_register_style( 'packout-map-editor', plugins_url( 'assets/css/map-editor.css', PACKOUT_CORE_FILE ), array_merge( array( 'packout-maplibre', 'packout-search' ), Ui::styles() ), PACKOUT_CORE_VERSION );
		wp_register_script( 'packout-map', plugins_url( 'assets/js/map.js', PACKOUT_CORE_FILE ), array_merge( array( 'packout-maplibre', 'packout-search', 'packout-slider' ), Ui::scripts() ), PACKOUT_CORE_VERSION, true );
		wp_register_script( 'packout-exif', plugins_url( 'assets/js/exif.js', PACKOUT_CORE_FILE ), array(), PACKOUT_CORE_VERSION, true );
		wp_register_script( 'packout-report', plugins_url( 'assets/js/report.js', PACKOUT_CORE_FILE ), array( 'packout-exif', 'packout-maplibre', 'packout-search' ), PACKOUT_CORE_VERSION, true );
		wp_register_style( 'packout-blocks', plugins_url( 'assets/css/blocks.css', PACKOUT_CORE_FILE ), array_merge( array( 'packout-maplibre', Ui::palette_style() ), Ui::styles() ), PACKOUT_CORE_VERSION );
		wp_register_script( 'packout-blocks-editor', plugins_url( 'assets/js/blocks-editor.js', PACKOUT_CORE_FILE ), array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ), PACKOUT_CORE_VERSION, true );
		App::translate_script( 'packout-blocks-editor' );
		wp_register_style( 'packout-workspace', plugins_url( 'assets/css/workspace.css', PACKOUT_CORE_FILE ), array_merge( array( Ui::palette_style() ), Ui::styles() ), PACKOUT_CORE_VERSION );
		wp_register_script( 'packout-address', plugins_url( 'assets/js/address.js', PACKOUT_CORE_FILE ), array(), PACKOUT_CORE_VERSION, true );
		wp_register_script( 'packout-workspace', plugins_url( 'assets/js/workspace.js', PACKOUT_CORE_FILE ), array( 'packout-address' ), PACKOUT_CORE_VERSION, true );
		wp_localize_script(
			'packout-workspace',
			'packoutWorkspace',
			array(
				'cancel'  => __( 'Cancel', 'packout-core' ),
				'confirm' => __( 'Go ahead', 'packout-core' ),
			)
		);
		wp_localize_script(
			'packout-blocks-editor',
			'packoutBlocks',
			array(
				'categories' => Schema::categories(),
				'statuses'   => Schema::statuses(),
				// Only the editor reads this, so only the dashboard pays for it.
				'partners'   => is_admin() ? array_map( 'strval', Partners::map_choices() ) : array(),
				'calendar'   => is_admin() ? self::calendar_choices() : array(),
				// Blocks this site does not have. The Place list and the Partner list are the hub's.
				'absent'     => App::is_hub() ? array( 'packout/partner-badge' ) : array( 'packout/places', 'packout/partners' ),
			)
		);

		register_block_type(
			'packout/map',
			array(
				'api_version'     => 3,
				'title'           => __( 'Pack-Out Spot map', 'packout-core' ),
				'description'     => __( 'Published Spots on a map, with category, stage, and map layer controls.', 'packout-core' ),
				'category'        => 'widgets',
				'icon'            => 'location-alt',
				'editor_script'   => 'packout-blocks-editor',
				'style'           => 'packout-blocks',
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
				'attributes'      => array(
					'height'      => array(
						'type'    => 'number',
						'default' => 560,
					),
					'category'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'status'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'partner'     => array(
						'type'    => 'number',
						'default' => 0,
					),
					'showFilters' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showSearch'  => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'render_callback' => array( self::class, 'render_map' ),
			)
		);

		register_block_type(
			'packout/report-prompt',
			array(
				'api_version'     => 3,
				'title'           => __( 'Report prompt', 'packout-core' ),
				'description'     => __( 'A short introduction with the report form, or links to the Report page and the field app.', 'packout-core' ),
				'category'        => 'widgets',
				'icon'            => 'megaphone',
				'editor_script'   => 'packout-blocks-editor',
				'style'           => 'packout-blocks',
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide' ),
				),
				'attributes'      => array(
					'heading'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'text'         => array(
						'type'    => 'string',
						'default' => '',
					),
					'showForm'     => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'showFieldApp' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'render_callback' => array( self::class, 'render_report' ),
			)
		);

		register_block_type(
			'packout/teams',
			array(
				'api_version'     => 3,
				'title'           => __( 'Organization list', 'packout-core' ),
				'description'     => __( 'Organizations that are listed publicly, with how many missions each has shared.', 'packout-core' ),
				'category'        => 'widgets',
				'icon'            => 'groups',
				'editor_script'   => 'packout-blocks-editor',
				'style'           => 'packout-blocks',
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide' ),
				),
				'attributes'      => array(
					'showMissions' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'render_callback' => array( self::class, 'render_teams' ),
			)
		);

		// Places are the hub's shared geography, and a partner site is one partner.
		if ( App::is_hub() ) {
			register_block_type(
				'packout/places',
				array(
					'api_version'     => 3,
					'title'           => __( 'Place list', 'packout-core' ),
					'description'     => __( 'Places that are listed publicly, with the organization that adopted each one and its open Spots.', 'packout-core' ),
					'category'        => 'widgets',
					'icon'            => 'location',
					'editor_script'   => 'packout-blocks-editor',
					'style'           => 'packout-blocks',
					'supports'        => array(
						'html'  => false,
						'align' => array( 'wide' ),
					),
					'render_callback' => array( self::class, 'render_places' ),
				)
			);

			register_block_type(
				'packout/partners',
				array(
					'api_version'     => 3,
					'title'           => __( 'Partner list', 'packout-core' ),
					'description'     => __( 'Partners that are approved and listed publicly, with how many of their organizations are listed.', 'packout-core' ),
					'category'        => 'widgets',
					'icon'            => 'networking',
					'editor_script'   => 'packout-blocks-editor',
					'style'           => 'packout-blocks',
					'supports'        => array(
						'html'  => false,
						'align' => array( 'wide' ),
					),
					'render_callback' => array( Partner_Page::class, 'render_directory' ),
				)
			);
		}//end if

		register_block_type(
			'packout/impact',
			array(
				'api_version'     => 3,
				'title'           => __( 'Impact summary', 'packout-core' ),
				'description'     => __( 'Site-wide effort, outputs, and outcomes from the impact records a coordinator counts publicly.', 'packout-core' ),
				'category'        => 'widgets',
				'icon'            => 'chart-bar',
				'editor_script'   => 'packout-blocks-editor',
				'style'           => 'packout-blocks',
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide' ),
				),
				'attributes'      => array(
					'showLine' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'render_callback' => array( self::class, 'render_impact' ),
			)
		);

		register_block_type(
			'packout/signup',
			array(
				'api_version'     => 3,
				'title'           => __( 'Sign up', 'packout-core' ),
				'description'     => __( 'The form that creates an account: a name and an email address, then a link to choose a password. It also accepts invitations to an organization.', 'packout-core' ),
				'category'        => 'widgets',
				'icon'            => 'admin-users',
				'editor_script'   => 'packout-blocks-editor',
				'style'           => 'packout-workspace',
				'supports'        => array(
					'html'     => false,
					'multiple' => false,
				),
				'render_callback' => array( Accounts::class, 'render' ),
			)
		);

		register_block_type(
			'packout/signin',
			array(
				'api_version'     => 3,
				'title'           => __( 'Sign in', 'packout-core' ),
				'description'     => __( 'The form that signs a person in on the site itself, with a link to choose a new password. Signing in from it never opens the dashboard.', 'packout-core' ),
				'category'        => 'widgets',
				'icon'            => 'unlock',
				'editor_script'   => 'packout-blocks-editor',
				'style'           => 'packout-workspace',
				'supports'        => array(
					'html'     => false,
					'multiple' => false,
				),
				'render_callback' => array( Sign_In::class, 'render' ),
			)
		);

		register_block_type(
			'packout/account',
			array(
				'api_version'     => 3,
				'title'           => __( 'My account', 'packout-core' ),
				'description'     => __( 'A signed-in person\'s own pages. Set it to the overview, or to one part: personal info, my impact, my contributions, email preferences, or sign-in details. Put each part on a page under the My account page.', 'packout-core' ),
				'category'        => 'widgets',
				'icon'            => 'admin-users',
				'editor_script'   => 'packout-blocks-editor',
				'style'           => 'packout-workspace',
				'supports'        => array(
					'html'     => false,
					'multiple' => false,
				),
				'attributes'      => array(
					'view' => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array( '', 'personal', 'impact', 'contributions', 'email', 'signin' ),
					),
				),
				'render_callback' => array( Profile::class, 'render' ),
			)
		);

		register_block_type(
			'packout/workspace',
			array(
				'api_version'     => 3,
				'title'           => __( 'Organization workspace', 'packout-core' ),
				'description'     => __( 'My organizations: where a signed-in person starts, joins, and runs an organization, with its members, teams, campaigns, missions, sponsors, and resources.', 'packout-core' ),
				'category'        => 'widgets',
				'icon'            => 'groups',
				'editor_script'   => 'packout-blocks-editor',
				'style'           => 'packout-workspace',
				'supports'        => array(
					'html'     => false,
					'multiple' => false,
					'align'    => array( 'wide' ),
				),
				'render_callback' => array( Workspace::class, 'render' ),
			)
		);

		register_block_type(
			Partner_Workspace::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'Partner workspace', 'packout-core' ),
				'description'     => __( 'Where the people who lead a partner manage its organizations, teams, programs, campaigns, missions, members, resources, messages, sponsors, and impact. Without this block, the same views are on the Organization workspace page.', 'packout-core' ),
				'category'        => 'widgets',
				'icon'            => 'networking',
				'editor_script'   => 'packout-blocks-editor',
				'style'           => 'packout-workspace',
				'supports'        => array(
					'html'     => false,
					'multiple' => false,
					'align'    => array( 'wide' ),
				),
				'render_callback' => array( Partner_Workspace::class, 'render' ),
			)
		);

		// A partner site says it is one. packout.spot has no use for the badge.
		if ( ! App::is_hub() ) {
			register_block_type(
				'packout/partner-badge',
				array(
					'api_version'     => 3,
					'title'           => __( 'Partner badge', 'packout-core' ),
					'description'     => __( 'A line that says this site is a partner of packout.spot, linked to its page there. It shows only while the site is connected.', 'packout-core' ),
					'category'        => 'widgets',
					'icon'            => 'awards',
					'attributes'      => array(
						'showTotals' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'editor_script'   => 'packout-blocks-editor',
					'style'           => 'packout-blocks',
					'supports'        => array(
						'html'  => false,
						'align' => array( 'left', 'center', 'right' ),
					),
					'render_callback' => array( self::class, 'render_badge' ),
				)
			);
		}//end if

		Calendar_View::register();
	}

	/**
	 * The partner badge: shown while the site is connected to packout.spot and listed there, and at no other time.
	 *
	 * It can carry a line of the site's own public totals: the Spots cleaned
	 * and the verified hours, from the records a coordinator here counts
	 * publicly. Verified and unverified hours are never added together, so
	 * the line gives the verified ones.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_badge( array $attributes = array() ): string {
		$state = Connection::state();
		if ( App::is_hub() || ! Connection::connected() || ! Connection::at_home() || ! in_array( $state['state'], array( 'active', 'dormant' ), true ) ) {
			// The editor says why there is nothing to see. A visitor sees nothing.
			return defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' )
				? '<p ' . Ui::wrapper( 'pos-badge pos-badge--off' ) . '>' . esc_html__( 'The partner badge shows here once this site is connected to packout.spot.', 'packout-core' ) . '</p>'
				: '';
		}
		$url  = esc_url_raw( '' !== $state['partner_url'] ? $state['partner_url'] : Connection::hub(), array( 'https', 'http' ) );
		$line = '';
		if ( ! empty( $attributes['showTotals'] ) ) {
			$totals = Impact::totals( array( 'visible' => true ) );
			$spots  = (int) $totals['outputs']['spots'];
			$hours  = (float) $totals['effort']['hours_verified'];
			if ( $spots > 0 ) {
				/* translators: %s: a number of Spots. */
				$line = sprintf( _n( '%s Spot cleaned', '%s Spots cleaned', $spots, 'packout-core' ), number_format_i18n( $spots ) );
				if ( $hours > 0 ) {
					/* translators: 1: a count of Spots cleaned, as a phrase, 2: a number of hours. */
					$line = sprintf( __( '%1$s, %2$s verified hours', 'packout-core' ), $line, Impact::number( $hours ) );
				}
				$line = '<span class="pos-badge__totals">' . esc_html( $line ) . '</span>';
			}
		}
		return '<p ' . Ui::wrapper( 'pos-badge' ) . '><a class="pos-badge__link" href="' . esc_url( $url ) . '"><span class="pos-badge__mark" aria-hidden="true"></span><span class="pos-badge__text">' . esc_html__( 'A partner of packout.spot', 'packout-core' ) . '</span></a>' . $line . '</p>';
	}

	/**
	 * The records the editor offers to limit a calendar to.
	 *
	 * Published records only, since a calendar on a page is for visitors.
	 *
	 * @return array<string, array<string, string>> Each list is record ID to name.
	 */
	private static function calendar_choices(): array {
		$named = static function ( array $posts ): array {
			$choices = array();
			foreach ( $posts as $post ) {
				if ( 'publish' === $post->post_status ) {
					$choices[ (string) $post->ID ] = $post->post_title;
				}
			}
			asort( $choices );
			return $choices;
		};
		return array(
			'partners'      => array_map( 'strval', Partners::map_choices() ),
			'organizations' => $named( Teams::all() ),
			'programs'      => $named( Programs::all() ),
			'campaigns'     => $named(
				get_posts(
					array(
						'post_type'   => Schema::CAMPAIGN,
						'post_status' => 'publish',
						'numberposts' => 200,
						'orderby'     => 'title',
						'order'       => 'ASC',
					)
				)
			),
		);
	}

	/**
	 * The Impact summary block.
	 *
	 * It sums the cleanup records a coordinator counts publicly, and counts
	 * return visits to Spots on the public map. Measured and self-reported
	 * amounts are separate figures, and so are verified and unverified hours.
	 * It shows no names and no photos. With nothing to count, it says so.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_impact( array $attributes ): string {
		Ui::enqueue();
		$totals = Impact::totals( array( 'visible' => true ) );
		$html   = '<div ' . Ui::wrapper( 'pos-impact' ) . '>';
		if ( ! empty( $attributes['showLine'] ) ) {
			$html .= '<p class="pos-impact__line">' . esc_html( Settings::celebration_line() ) . '</p>';
		}

		$none  = 0 === (int) $totals['records']['cleanups'] && 0 === (int) $totals['outcomes']['still_clear'] && 0 === (int) $totals['outcomes']['returned'];
		$sites = Remote::sites_totals();
		if ( $none && null === $sites ) {
			return $html . '<div class="pos-impact__empty">' . Admin::ui(
				'empty-state',
				array(
					'title'       => __( 'No results recorded yet', 'packout-core' ),
					'description' => __( 'Totals appear here when a cleanup has been recorded and a coordinator counts it.', 'packout-core' ),
				)
			) . '</div></div>';
		}

		$html .= $none ? '<p class="pos-impact__none">' . esc_html__( 'No cleanup recorded on this site has been counted yet.', 'packout-core' ) . '</p>' : self::impact_groups( $totals );
		if ( null !== $sites ) {
			// What partner sites reported is a second line, labeled, and never added to the first.
			$html .= '<section class="pos-impact__partners"><h3 class="pos-impact__partners-title">' . esc_html__( 'Reported by partner sites', 'packout-core' ) . '</h3><p class="pos-impact__partners-note">';
			$html .= esc_html(
				sprintf(
					/* translators: %s: number of partner sites. */
					_n( 'From %s partner that keeps its records on its own site. These figures are apart from the ones above and are not added to them. Verified here means verified by that partner\'s own people.', 'From %s partners that keep their records on their own sites. These figures are apart from the ones above and are not added to them. Verified here means verified by each partner\'s own people.', (int) $sites['partners'], 'packout-core' ),
					number_format_i18n( (int) $sites['partners'] )
				)
			);
			$html .= '</p>' . self::impact_groups( $sites ) . '</section>';
		}
		$html .= '<p class="pos-impact__note">' . esc_html__( 'Measured means counted or weighed. Self-reported figures are estimates from the people who did the work, and the two are never added together. Verified hours were checked by a lead or a coordinator.', 'packout-core' ) . '</p>';
		return $html . '</div>';
	}

	/**
	 * The three groups of figures of the Impact block.
	 *
	 * @param array<string, array<string, int|float>> $totals From Impact::totals().
	 */
	private static function impact_groups( array $totals ): string {
		$groups = array(
			__( 'Effort', 'packout-core' )   => array(
				__( 'Hours, verified', 'packout-core' )    => Impact::number( (float) $totals['effort']['hours_verified'] ),
				__( 'Hours, unverified', 'packout-core' )  => Impact::number( (float) $totals['effort']['hours_unverified'] ),
				__( 'People', 'packout-core' )             => number_format_i18n( (int) $totals['effort']['people'] ),
				__( 'Missions completed', 'packout-core' ) => number_format_i18n( (int) $totals['effort']['missions'] ),
				__( 'Organizations active', 'packout-core' ) => number_format_i18n( (int) $totals['effort']['teams'] ),
			),
			__( 'Outputs', 'packout-core' )  => array(
				__( 'Spots cleaned', 'packout-core' )    => number_format_i18n( (int) $totals['outputs']['spots'] ),
				__( 'Bags, measured', 'packout-core' )   => number_format_i18n( (int) $totals['outputs']['bags_measured'] ),
				__( 'Bags, self-reported', 'packout-core' ) => number_format_i18n( (int) $totals['outputs']['bags_self_reported'] ),
				__( 'Pounds, measured', 'packout-core' ) => Impact::number( (float) $totals['outputs']['pounds_measured'] ),
				__( 'Pounds, self-reported', 'packout-core' ) => Impact::number( (float) $totals['outputs']['pounds_self_reported'] ),
			),
			__( 'Outcomes', 'packout-core' ) => array(
				__( 'Spots still clear on a later visit', 'packout-core' ) => number_format_i18n( (int) $totals['outcomes']['still_clear'] ),
				__( 'Spots where the problem came back', 'packout-core' )  => number_format_i18n( (int) $totals['outcomes']['returned'] ),
			),
		);

		$html = '<div class="pos-impact__groups">';
		foreach ( $groups as $title => $figures ) {
			$html .= '<section class="pos-impact__group"><h3 class="pos-impact__title">' . esc_html( $title ) . '</h3><dl class="pos-impact__figures">';
			foreach ( $figures as $label => $value ) {
				$html .= '<div class="pos-impact__figure"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
			}
			$html .= '</dl></section>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * The Team list block.
	 *
	 * Shows what the public may know: a team's name and description, and the
	 * count of missions its lead marked public. With no team to show, the
	 * empty state stays on the page.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_teams( array $attributes ): string {
		Ui::enqueue();
		$teams = Teams::listed();
		$html  = '<div ' . Ui::wrapper( 'pos-teams' ) . '>';

		if ( ! $teams ) {
			return $html . Admin::ui(
				'empty-state',
				array(
					'title'       => __( 'No organizations listed yet', 'packout-core' ),
					'description' => __( 'Organizations appear here once a coordinator lists them.', 'packout-core' ),
				)
			) . '</div>';
		}

		$labels   = Vocabularies::labels( Vocabularies::GROUP_TYPE );
		$used     = array();
		$has_none = false;
		foreach ( $teams as $team ) {
			$slug = (string) ( $team['group_type'] ?? '' );
			if ( '' === $slug ) {
				$has_none = true;
			} elseif ( isset( $labels[ $slug ] ) ) {
				$used[ $slug ] = $labels[ $slug ];
			} else {
				$used[ $slug ] = $slug;
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public list filter.
		$filter = isset( $_GET['group_type'] ) ? sanitize_title( wp_unslash( $_GET['group_type'] ) ) : '';
		if ( 'not-set' === $filter ) {
			$teams = array_values( array_filter( $teams, static fn( array $team ): bool => '' === ( $team['group_type'] ?? '' ) ) );
		} elseif ( '' !== $filter ) {
			$teams = array_values( array_filter( $teams, static fn( array $team ): bool => ( $team['group_type'] ?? '' ) === $filter ) );
		}

		$html .= '<form class="pos-teams__filter" method="get" action="">';
		$html .= '<p><label>' . esc_html__( 'Type of group', 'packout-core' ) . '<br /><select name="group_type" onchange="this.form.submit()">';
		$html .= '<option value="">' . esc_html__( 'Every type', 'packout-core' ) . '</option>';
		if ( $has_none ) {
			$html .= '<option value="not-set"' . selected( $filter, 'not-set', false ) . '>' . esc_html__( 'Not set', 'packout-core' ) . '</option>';
		}
		foreach ( $used as $slug => $name ) {
			$html .= '<option value="' . esc_attr( $slug ) . '"' . selected( $filter, $slug, false ) . '>' . esc_html( $name ) . '</option>';
		}
		$html .= '</select></label></p></form>';

		if ( ! $teams ) {
			return $html . Admin::ui(
				'empty-state',
				array(
					'title'       => __( 'No organizations match', 'packout-core' ),
					'description' => __( 'Try another type of group.', 'packout-core' ),
				)
			) . '</div>';
		}

		$html .= '<ul class="pos-teams__list">';
		foreach ( $teams as $team ) {
			$html .= '<li class="pos-teams__team">' . $team['logo'] . '<h3 class="pos-teams__name"><a href="' . esc_url( $team['url'] ) . '">' . esc_html( $team['name'] ) . '</a></h3>';
			if ( '' !== trim( $team['description'] ) ) {
				$html .= '<p class="pos-teams__text">' . esc_html( $team['description'] ) . '</p>';
			}
			if ( ! empty( $attributes['showMissions'] ) ) {
				$facts = array();
				if ( $team['planned'] > 0 ) {
					/* translators: %d: number of missions. */
					$facts[] = sprintf( _n( '%d mission planned', '%d missions planned', $team['planned'], 'packout-core' ), $team['planned'] );
				}
				if ( $team['completed'] > 0 ) {
					/* translators: %d: number of missions. */
					$facts[] = sprintf( _n( '%d mission completed', '%d missions completed', $team['completed'], 'packout-core' ), $team['completed'] );
				}
				$html .= '<p class="pos-teams__facts">' . esc_html( $facts ? implode( ' · ', $facts ) : __( 'No missions shared yet', 'packout-core' ) ) . '</p>';
			}
			$html .= '</li>';
		}
		return $html . '</ul></div>';
	}

	/**
	 * The Place list block.
	 *
	 * Shows what the public may know: a Place's cover image, logo, name, kind,
	 * and description, the teams that adopted it when they are listed, and how
	 * many of its Spots are open. With no Place to show, the empty state stays on the page.
	 */
	public static function render_places(): string {
		Ui::enqueue();
		$places = Places::listed();
		$html   = '<div ' . Ui::wrapper( 'pos-teams pos-places' ) . '>';

		if ( ! $places ) {
			return $html . Admin::ui(
				'empty-state',
				array(
					'title'       => __( 'No Places listed yet', 'packout-core' ),
					'description' => __( 'A Place appears here once a coordinator lists it.', 'packout-core' ),
				)
			) . '</div>';
		}

		$html .= '<ul class="pos-teams__list pos-places__list">';
		foreach ( $places as $place ) {
			$html .= '<li class="pos-teams__team pos-places__place">';
			// The cover, or a dark ground with the kind of Place when there is none.
			$cover = $place['cover'] > 0 ? wp_get_attachment_image(
				$place['cover'],
				'medium_large',
				false,
				array(
					'class'   => 'pos-places__cover',
					'alt'     => '',
					'loading' => 'lazy',
				)
			) : '';
			$html .= '<div class="pos-places__top' . ( '' === $cover ? ' pos-places__top--plain' : '' ) . '">' . $cover;
			if ( $place['logo'] > 0 ) {
				$html .= wp_get_attachment_image(
					$place['logo'],
					'medium',
					false,
					array(
						'class'   => 'pos-places__logo',
						'alt'     => '',
						'loading' => 'lazy',
					)
				);
			}
			$html .= '<span class="pos-places__kind">' . esc_html( $place['kind_label'] ) . '</span></div>';
			$html .= '<div class="pos-places__text"><h3 class="pos-teams__name"><a href="' . esc_url( $place['url'] ) . '">' . esc_html( $place['name'] ) . '</a></h3>';
			if ( '' !== trim( $place['description'] ) ) {
				$html .= '<p class="pos-teams__text">' . esc_html( wp_trim_words( $place['description'], 40 ) ) . '</p>';
			}
			$facts = array(
				esc_html( $place['kind_label'] ),
				/* translators: %s: number of Spots. */
				esc_html( sprintf( _n( '%s open Spot', '%s open Spots', $place['open'], 'packout-core' ), number_format_i18n( $place['open'] ) ) ),
			);
			if ( $place['cleaned'] > 0 ) {
				/* translators: %s: number of Spots. */
				$facts[] = esc_html( sprintf( __( '%s cleaned', 'packout-core' ), number_format_i18n( $place['cleaned'] ) ) );
			}
			if ( $place['adopters'] ) {
				$facts[] = Places::adopters_line( $place['adopters'] );
			}
			$html .= '<p class="pos-teams__facts">' . implode( ' · ', $facts ) . '</p></div></li>';
		}//end foreach
		return $html . '</ul></div>';
	}

	/**
	 * What the map script needs to know.
	 *
	 * The page may be cached, so nothing about the visitor is printed here.
	 *
	 * @return array<string, mixed>
	 */
	public static function map_config(): array {
		return array(
			'rest'         => esc_url_raw( rest_url( Rest::NAMESPACE . '/' ) ),
			// On a partner's site that asked for them: where the map reads what packout.spot shows in the site's region.
			'hub'          => Hub_Layers::config(),
			'style'        => Settings::get( 'map_style' ),
			'center'       => array( (float) Settings::get( 'map_lng' ), (float) Settings::get( 'map_lat' ) ),
			'zoom'         => (float) Settings::get( 'map_zoom' ),
			'categories'   => Schema::categories(),
			'statuses'     => Schema::statuses(),
			'hazards'      => Schema::hazards(),
			'overlays'     => Vocabularies::overlays(),
			'featureKinds' => App::is_hub() ? Vocabularies::feature_kinds() : array(),
			// The partners the directory shows, for the partner filter. Empty while there are none.
			'partners'     => Partners::map_choices(),
			// The outline and name of each listed Place.
			'outlines'     => Places::outlines(),
			'features'     => Features::public_list(),
			// What the Contributions menu lists: what this site keeps. Places and map features are the hub's.
			'kinds'        => App::is_hub() ? array_merge( array( 'places', 'spots' ), array_column( Vocabularies::feature_kinds(), 'slug' ) ) : array( 'spots' ),
			'icons'        => Feature_Icons::markup(),
			'canFeature'   => Features::can_create(),
			'addFeature'   => __( 'Add a map feature', 'packout-core' ),
			'text'         => array(
				'loading'            => __( 'Loading Spots…', 'packout-core' ),
				'failed'             => __( 'The Spots could not be loaded. Check your connection and reload the page.', 'packout-core' ),
				'emptyTitle'         => __( 'No Spots on the map yet', 'packout-core' ),
				'emptyText'          => __( 'When a report is reviewed and published, it appears here.', 'packout-core' ),
				'noneMatch'          => __( 'No Spots match these filters.', 'packout-core' ),
				/* translators: %d: number of Spots. */
				'count'              => __( '%d Spots', 'packout-core' ),
				'one'                => __( '1 Spot', 'packout-core' ),
				'approximate'        => __( 'Approximate location. The exact place is shared with the crew that takes it on.', 'packout-core' ),
				'noPin'              => __( 'No pin yet. This Spot was reported by description.', 'packout-core' ),
				'landmark'           => __( 'Landmark', 'packout-core' ),
				/* translators: 1: date, 2: who reported the Spot. */
				'reported'           => __( 'Reported %1$s by %2$s', 'packout-core' ),
				/* translators: %s: Spot title. */
				'photos'             => __( 'Photos of %s', 'packout-core' ),
				'open'               => __( 'Open this Spot', 'packout-core' ),
				'awaiting'           => __( 'Reported, awaiting review', 'packout-core' ),
				/* translators: %s: the name of a partner. */
				'through'            => __( 'Reported through %s', 'packout-core' ),
				/* translators: %s: the name of a partner. */
				'also'               => __( 'Also reported through %s', 'packout-core' ),
				/* translators: %s: the name of a partner. */
				'onPartner'          => __( 'See it on the site of %s', 'packout-core' ),
				'rounded'            => __( 'Approximate location, to about a kilometre, until the report is reviewed.', 'packout-core' ),
				'approximatePartner' => __( 'Approximate location.', 'packout-core' ),
				/* translators: %s: feature kind, such as Route. */
				'openFeature'        => __( 'View this %s', 'packout-core' ),
				/* translators: %s: feature kind, such as Route. */
				'editFeature'        => __( 'Edit this %s', 'packout-core' ),
				'close'              => __( 'Close', 'packout-core' ),
				'list'               => __( 'All Spots in a list', 'packout-core' ),
				'allCats'            => __( 'All categories', 'packout-core' ),
				'allStages'          => __( 'Any stage', 'packout-core' ),
				'partner'            => __( 'Partner', 'packout-core' ),
				'allPartners'        => __( 'Every partner', 'packout-core' ),
				'category'           => __( 'Category', 'packout-core' ),
				'stage'              => __( 'Stage', 'packout-core' ),
				'layers'             => __( 'Contributions', 'packout-core' ),
				'layerPlaces'        => __( 'Places', 'packout-core' ),
				'layerSpots'         => __( 'Spots', 'packout-core' ),
				'layerRoutes'        => __( 'Routes', 'packout-core' ),
				'layerPoints'        => __( 'Points of Interest', 'packout-core' ),
				'layerBounds'        => __( 'Boundaries', 'packout-core' ),
				'layerNone'          => __( 'None', 'packout-core' ),
				'overlays'           => __( 'Map overlays', 'packout-core' ),
				'baseGroup'          => __( 'OpenFreeMap', 'packout-core' ),
				'baseRoads'          => __( 'Roads', 'packout-core' ),
				'baseTrails'         => __( 'Trails', 'packout-core' ),
				'basePoints'         => __( 'Existing points of interest', 'packout-core' ),
				'forestGroup'        => __( 'Forest Service', 'packout-core' ),
				'forestNote'         => __( 'These maps are not on OpenFreeMap. A motor vehicle use map shows where a vehicle is allowed, not whether a road is open today.', 'packout-core' ),
				'forestMvum'         => __( 'Motor vehicle use map', 'packout-core' ),
				'forestTrails'       => __( 'National Forest trails', 'packout-core' ),
				'forestLands'        => __( 'National Forests', 'packout-core' ),
				'forestWild'         => __( 'Wilderness', 'packout-core' ),
				'notInStyle'         => __( 'not in this style', 'packout-core' ),
				'lineRoad'           => __( 'Road', 'packout-core' ),
				'lineTrail'          => __( 'Trail', 'packout-core' ),
				'lineMvumTrail'      => __( 'Motor vehicle trail', 'packout-core' ),
				'lineMvumRoad'       => __( 'Motor vehicle road', 'packout-core' ),
				'lineUnnamedRoad'    => __( 'Unnamed road', 'packout-core' ),
				'lineUnnamedTrail'   => __( 'Unnamed trail', 'packout-core' ),
				'lineRef'            => __( 'Reference', 'packout-core' ),
				'lineSurface'        => __( 'Surface', 'packout-core' ),
				'lineOneWay'         => __( 'One way', 'packout-core' ),
				'lineYes'            => __( 'Yes', 'packout-core' ),
				'lineBridge'         => __( 'Bridge', 'packout-core' ),
				'lineTunnel'         => __( 'Tunnel', 'packout-core' ),
				'lineMiles'          => __( 'Miles', 'packout-core' ),
				'lineForest'         => __( 'Forest', 'packout-core' ),
				'lineDistrict'       => __( 'Ranger district', 'packout-core' ),
				'lineClass'          => __( 'Class', 'packout-core' ),
				'lineStatus'         => __( 'Status', 'packout-core' ),
				'lineSeason'         => __( 'Season', 'packout-core' ),
				'lineNumber'         => __( 'Number', 'packout-core' ),
				'lineOsm'            => __( 'OpenStreetMap, via OpenFreeMap', 'packout-core' ),
				'lineMvum'           => __( 'Motor Vehicle Use Map, U.S. Forest Service', 'packout-core' ),
				'mapLabel'           => __( 'Map of Spots', 'packout-core' ),
				'authorized'         => __( 'Work is authorized', 'packout-core' ),
				'place'              => __( 'Place', 'packout-core' ),
				'hidden'             => __( 'That Spot is hidden by the filters above. The map has moved to it.', 'packout-core' ),
			),
		);
	}

	/**
	 * The map block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_map( array $attributes ): string {
		wp_enqueue_script( 'packout-map' );

		$config            = self::map_config();
		$config['filters'] = ! empty( $attributes['showFilters'] );
		$config['search']  = ! isset( $attributes['showSearch'] ) || ! empty( $attributes['showSearch'] );
		$config['preset']  = array(
			'category' => sanitize_title( (string) ( $attributes['category'] ?? '' ) ),
			'status'   => sanitize_key( (string) ( $attributes['status'] ?? '' ) ),
			'partner'  => (int) ( $attributes['partner'] ?? 0 ),
		);
		return self::map_markup( $config, (int) ( $attributes['height'] ?? 560 ), Ui::wrapper( 'pos-map' ) );
	}

	/**
	 * The markup the map script starts from. The page of a Place uses it too.
	 *
	 * @param array<string, mixed> $config     From map_config(), with filters, preset, and optionally place.
	 * @param int                  $height     Height in pixels.
	 * @param string               $attributes Escaped attributes of the wrapper, with the class pos-map.
	 */
	public static function map_markup( array $config, int $height, string $attributes ): string {
		wp_enqueue_script( 'packout-map' );
		$height = max( 280, min( 1200, $height ) );

		$html = '<div ' . $attributes . ' data-pos-map="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';
		if ( ! empty( $config['search'] ) ) {
			// A town, a road, a Spot, or a Place by name. The results move the map.
			wp_enqueue_style( 'packout-search' );
			$html .= '<div class="pos-map__search"></div>';
		}
		if ( ! empty( $config['canFeature'] ) ) {
			Features::enqueue_editor();
			$html .= '<p class="pos-map__add"><button type="button" class="pos-ws__button pos-ws__button--primary" data-pos-feature-edit="1">' . esc_html( (string) ( $config['addFeature'] ?? __( 'Add a map feature', 'packout-core' ) ) ) . '</button></p>';
		}
		$html .= '<div class="pos-map__bar"></div>';
		$html .= '<div class="pos-map__body" style="height:' . (int) $height . 'px">';
		$html .= '<div class="pos-map__canvas" role="region" aria-label="' . esc_attr__( 'Map of Spots', 'packout-core' ) . '"></div>';
		$html .= '<aside class="pos-map__panel" hidden></aside>';
		$html .= '<div class="pos-map__state" role="status">' . esc_html__( 'Loading Spots…', 'packout-core' ) . '</div>';
		$html .= '</div>';
		$html .= '<details class="pos-map__list" hidden><summary></summary><ul></ul></details>';
		$html .= '<noscript><p>' . esc_html__( 'The map needs JavaScript.', 'packout-core' ) . '</p></noscript>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * The largest photo the report form takes, in megabytes: the smaller of what its photo field allows and what the server accepts.
	 */
	private static function report_photo_limit(): int {
		$limit = Photos::limit_mb();
		$own   = App::kernel()->report_form()->photo_limit();
		return $own > 0 ? min( $limit, $own ) : $limit;
	}

	/**
	 * The Report prompt block.
	 *
	 * Shows the site's report form when it has one. The questions about who
	 * the Spot's page credits are not fields of the form: which organizations
	 * to offer depends on who is signed in. They go before the form's button.
	 * Without a form the block links to the Report page and the field app.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_report( array $attributes ): string {
		$heading = trim( (string) ( $attributes['heading'] ?? '' ) );
		$text    = trim( (string) ( $attributes['text'] ?? '' ) );
		$form    = App::kernel()->report_form();
		$page    = (int) Settings::get( 'report_page' );

		$html = '<div ' . Ui::wrapper( 'pos-report' ) . '>';
		if ( '' !== $heading ) {
			$html .= '<h2 class="pos-report__heading">' . esc_html( $heading ) . '</h2>';
		}
		if ( '' !== $text ) {
			$html .= '<p class="pos-report__text">' . esc_html( $text ) . '</p>';
		}

		$rendered = '';
		if ( ! empty( $attributes['showForm'] ) && $form->available() ) {
			$rendered = $form->render(
				array(
					'title'         => false,
					'before_submit' => Credit::fields( 'packout_credit', get_current_user_id() ),
				)
			);
		}

		if ( '' !== trim( $rendered ) ) {
			$parts  = $form->selectors();
			$locate = array(
				// Where the script finds the form's fields: CSS selectors inside the form.
				'latitude'  => (string) ( $parts['latitude'] ?? '' ),
				'longitude' => (string) ( $parts['longitude'] ?? '' ),
				'row'       => (string) ( $parts['row'] ?? '' ),
				'label'     => __( 'Use my location', 'packout-core' ),
				'waiting'   => __( 'Finding your position…', 'packout-core' ),
				'done'      => __( 'Coordinates added. Use this only if you are at the place now.', 'packout-core' ),
				'failed'    => __( 'Your position is not available. A landmark is enough.', 'packout-core' ),
				'photo'     => __( 'Coordinates added from where your photo was taken. Change them if that is not the place.', 'packout-core' ),
				'picture'   => (string) ( $parts['photo'] ?? '' ),
				'accept'    => Photos::accept(),
				'limit'     => self::report_photo_limit(),
				/* translators: 1: size of the chosen photo in megabytes, 2: largest size the form takes in megabytes. */
				'large'     => __( 'That photo is %1$s MB, and this form takes up to %2$s MB. Choose a smaller one, or send the report without a photo.', 'packout-core' ),
				// The map the pin is placed on, with the search that narrows it.
				'map'       => array(
					'style'  => Settings::get( 'map_style' ),
					'center' => array( (float) Settings::get( 'map_lng' ), (float) Settings::get( 'map_lat' ) ),
					'zoom'   => (float) Settings::get( 'map_zoom' ),
					'label'  => __( 'Map for placing the Spot', 'packout-core' ),
					'hint'   => __( 'Search for the place or move the map, then click where the Spot is. You can also type the coordinates below, or leave them empty and describe the place.', 'packout-core' ),
					'placed' => __( 'Pin placed. Click again to move it.', 'packout-core' ),
					'remove' => __( 'Remove the pin', 'packout-core' ),
				),
			);
			wp_enqueue_script( 'packout-report' );
			wp_enqueue_style( 'packout-maplibre' );
			wp_enqueue_style( 'packout-search' );
			$html .= '<div class="pos-report__form" data-pos-locate="' . esc_attr( (string) wp_json_encode( $locate ) ) . '">' . $rendered . '</div>';
		} elseif ( $page > 0 && get_queried_object_id() !== $page && 'publish' === get_post_status( $page ) ) {
			$html .= '<p class="pos-report__actions"><a class="wp-element-button pos-report__button" href="' . esc_url( (string) get_permalink( $page ) ) . '">' . esc_html__( 'Report a Spot', 'packout-core' ) . '</a></p>';
		}//end if

		if ( ! empty( $attributes['showFieldApp'] ) ) {
			$html .= '<p class="pos-report__field">' . esc_html__( 'Heading out of coverage?', 'packout-core' ) . ' <a href="' . esc_url( Field_App::url() ) . '">' . esc_html__( 'Open the field app', 'packout-core' ) . '</a> ' . esc_html__( 'to save reports on your phone and send them later.', 'packout-core' ) . '</p>';
		}

		return $html . '</div>';
	}
}
