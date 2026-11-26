<?php
/**
 * Public pages of partners and programs.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The public page of a partner, the public page of a program, and the list of partners.
 *
 * Both pages open with the header an organization, a Place, a campaign, and
 * a mission have. A partner's page shows its listed organizations, its public
 * programs, the impact of its organizations, and what they are taking part
 * in. A program's page says whose program it is, who takes part, and what
 * its campaigns and missions add up to. Neither shows an email address, and
 * a program's page names no person.
 *
 * A partner that is waiting, suspended, or not listed answers "not found",
 * and so does a program that is not public or whose owner is not shown. The
 * people who run one can open it to see what it will look like.
 */
class Partner_Page {

	/**
	 * Register the pages.
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'route' ), 1 );
		add_filter( 'the_content', array( self::class, 'content' ) );
	}

	/**
	 * Whether the person looking is shown the page of a partner or a program.
	 *
	 * @param \WP_Post $post The partner or the program.
	 */
	public static function visible( \WP_Post $post ): bool {
		$user = get_current_user_id();
		if ( Schema::PARTNER === $post->post_type ) {
			return Partners::shown( $post->ID, 'page' ) || ( $user > 0 && Partners::can_run( $post->ID, $user ) );
		}
		return Programs::shown( $post->ID ) || ( $user > 0 && Programs::can_change( $post->ID, $user ) );
	}

	/**
	 * Answer "not found" for a page that is not shown to the person looking.
	 */
	public static function route(): void {
		if ( ! is_singular( array( Schema::PARTNER, Schema::PROGRAM ) ) ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( self::visible( $post ) ) {
			$public = Schema::PARTNER === $post->post_type ? Partners::shown( $post->ID, 'page' ) : Programs::shown( $post->ID );
			if ( ! $public || is_user_logged_in() ) {
				nocache_headers();
			}
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Build the page of a partner or of a program.
	 *
	 * @param string $content Post content.
	 */
	public static function content( $content ) {
		if ( ! is_singular( array( Schema::PARTNER, Schema::PROGRAM ) ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post || ! self::visible( $post ) ) {
			return '';
		}
		wp_enqueue_style( 'packout-workspace' );
		wp_enqueue_style( 'packout-blocks' );
		return Schema::PARTNER === $post->post_type ? self::partner( $post ) : self::program( $post );
	}

	/**
	 * The line that names an organization's partner, for the organization's page. Empty when it has none that is shown.
	 *
	 * @param int $team_id Organization ID.
	 * @return string Escaped HTML.
	 */
	public static function partner_fact( int $team_id ): string {
		$partner = Partners::of_team( $team_id );
		if ( $partner <= 0 || ! Partners::shown( $partner, 'page' ) ) {
			return '';
		}
		/* translators: %s: partner name, linked. */
		return sprintf( esc_html__( 'Part of %s', 'packout-core' ), '<a href="' . esc_url( (string) get_permalink( $partner ) ) . '">' . esc_html( get_the_title( $partner ) ) . '</a>' );
	}

	/**
	 * Cards of organizations, programs, or partners: a logo, a name, a line of text, a line of facts.
	 *
	 * @param array<int, array{logo: string, name: string, url: string, text: string, facts: string}> $cards Cards. `logo` is escaped HTML.
	 */
	private static function cards( array $cards ): string {
		$html = '<ul class="pos-teams__list">';
		foreach ( $cards as $card ) {
			$html .= '<li class="pos-teams__team">' . $card['logo'] . '<h3 class="pos-teams__name"><a href="' . esc_url( $card['url'] ) . '">' . esc_html( $card['name'] ) . '</a></h3>';
			if ( '' !== trim( $card['text'] ) ) {
				$html .= '<p class="pos-teams__text">' . esc_html( wp_trim_words( $card['text'], 30 ) ) . '</p>';
			}
			if ( '' !== $card['facts'] ) {
				$html .= '<p class="pos-teams__facts">' . esc_html( $card['facts'] ) . '</p>';
			}
			$html .= '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * The note at the top of a page only the people who run the record can see.
	 *
	 * @param string $text What to say.
	 */
	private static function preview( string $text ): string {
		return '<p class="pos-place-page__note pos-subteam-page__preview">' . esc_html( $text ) . '</p>';
	}

	/**
	 * The public page of a partner.
	 *
	 * @param \WP_Post $partner Partner.
	 */
	private static function partner( \WP_Post $partner ): string {
		$id     = $partner->ID;
		$orgs   = array_values( array_filter( Partners::organizations( $id ), static fn( $team ) => 'publish' === $team->post_status && Teams::active( $team->ID ) ) );
		$region = Partners::field( $id, 'region' );
		$site   = Partners::field( $id, 'website' );
		$host   = '' !== $site ? (string) wp_parse_url( $site, PHP_URL_HOST ) : '';
		$facts  = array(
			esc_html( $region ),
			/* translators: %s: number of organizations. */
			esc_html( sprintf( _n( '%s organization', '%s organizations', count( $orgs ), 'packout-core' ), number_format_i18n( count( $orgs ) ) ) ),
		);
		if ( Partners::shows_leads( $id ) ) {
			foreach ( Team_Page::leaders( Partners::lead( $id ), Partners::coleads( $id ) ) as $label => $value ) {
				$facts[] = esc_html( $label ) . ' ' . $value;
			}
		}
		$facts[] = '' !== $site ? '<a href="' . esc_url( $site ) . '" rel="nofollow noopener">' . esc_html( '' !== $host ? $host : $site ) . '</a>' : '';
		$facts[] = Remote::site_fact( $id );

		$html  = '<div class="pos-team-page pos-partner-page pos-campaign-page pos-place-page pos-place pos-event">';
		$html .= Page_Header::render(
			array(
				'cover'       => Page_Header::cover( $id ),
				'logo'        => Partners::logo( $id ),
				/* translators: %s: partner name. */
				'logo_alt'    => sprintf( __( 'Logo of %s', 'packout-core' ), $partner->post_title ),
				'kind'        => __( 'Partner', 'packout-core' ),
				'title'       => $partner->post_title,
				'description' => $partner->post_content,
				'facts'       => $facts,
				'sponsors'    => Sponsors::all( $id ),
			)
		);
		$html .= '<div class="pos-place__body">';
		if ( ! Partners::shown( $id, 'page' ) ) {
			$html .= self::preview( __( 'Only the people who lead this partner can see this page. Everyone can once it is approved and you list it in its Settings.', 'packout-core' ) );
		}

		$html .= '<section class="pos-place__section pos-partner-page__organizations"><h2>' . esc_html__( 'Organizations', 'packout-core' ) . '</h2>';
		if ( $orgs ) {
			$cards = array();
			foreach ( $orgs as $team ) {
				$count   = count( Teams::people( $team->ID ) );
				$cards[] = array(
					'logo'  => Teams::logo_html( $team->ID, 'pos-teams__logo', 'thumbnail' ),
					'name'  => $team->post_title,
					'url'   => (string) get_permalink( $team ),
					'text'  => $team->post_content,
					/* translators: %s: number of people. */
					'facts' => implode( ' · ', array_filter( array( Teams::field( $team->ID, 'region' ), sprintf( _n( '%s person', '%s people', $count, 'packout-core' ), number_format_i18n( $count ) ) ) ) ),
				);
			}
			$html .= self::cards( $cards );
		} else {
			$html .= '<p>' . esc_html__( 'None of this partner\'s organizations is listed yet.', 'packout-core' ) . '</p>';
		}
		$html .= '</section>';

		$programs = array_values( array_filter( Programs::of_partner( $id, true ), static fn( $program ) => Programs::shown( $program->ID ) || ! Partners::shown( $id, 'page' ) ) );
		if ( $programs ) {
			$cards = array();
			foreach ( $programs as $program ) {
				$row     = Programs::public_payload( $program );
				$cards[] = array(
					'logo'  => '',
					'name'  => $row['title'],
					'url'   => $row['url'],
					'text'  => '' !== $row['goal'] ? $row['goal'] : $program->post_content,
					'facts' => implode( ' · ', array_filter( array( $row['window'], 'closed' === $row['state'] ? __( 'Closed', 'packout-core' ) : '', Programs::progress_line( $row['progress'] ) ) ) ),
				);
			}
			$html .= '<section class="pos-place__section pos-partner-page__programs"><h2>' . esc_html__( 'Programs', 'packout-core' ) . '</h2>' . self::cards( $cards ) . '</section>';
		}

		$html .= Team_Page::impact( Partners::totals( $id, true ), __( 'No cleanup by this partner\'s organizations has been counted yet.', 'packout-core' ) );

		// What its organizations are taking part in: public campaigns and missions, upcoming first.
		$campaigns = array();
		$missions  = array();
		foreach ( $orgs as $team ) {
			$campaigns = array_merge( $campaigns, Campaigns::of_team( $team->ID, true ) );
			$missions  = array_merge( $missions, Missions::public_of_team( $team->ID ) );
		}
		// What is coming up, from the calendar. The lists under it are the rest: what is over, and what has no day yet.
		$coming    = Calendar_View::upcoming(
			array(
				'partner' => $id,
				'show'    => array( 'missions', 'campaigns' ),
				'count'   => 12,
			)
		);
		$campaigns = array_values( array_filter( $campaigns, static fn( \WP_Post $campaign ): bool => ! in_array( $campaign->ID, $coming['campaigns'], true ) ) );
		$missions  = array_values( array_filter( $missions, static fn( array $mission ): bool => ! in_array( (int) $mission['id'], $coming['missions'], true ) ) );
		if ( $campaigns || $missions || '' !== $coming['html'] ) {
			$today = (string) wp_date( 'Y-m-d' );
			// Upcoming and running ones first, soonest first; then what is over, latest first.
			$order = static function ( string $a_start, string $a_end, string $b_start, string $b_end ) use ( $today ): int {
				$a_past = '' !== $a_end && $a_end < $today;
				$b_past = '' !== $b_end && $b_end < $today;
				if ( $a_past !== $b_past ) {
					return $a_past ? 1 : -1;
				}
				return $a_past ? strcmp( $b_end, $a_end ) : strcmp( '' !== $a_start ? $a_start : '9999', '' !== $b_start ? $b_start : '9999' );
			};
			usort( $campaigns, static fn( $a, $b ) => $order( Campaigns::field( $a->ID, 'start' ), Campaigns::field( $a->ID, 'end' ), Campaigns::field( $b->ID, 'start' ), Campaigns::field( $b->ID, 'end' ) ) );
			usort( $missions, static fn( $a, $b ) => $order( Missions::field( $a['id'], 'start' ), Missions::field( $a['id'], 'end' ), Missions::field( $b['id'], 'start' ), Missions::field( $b['id'], 'end' ) ) );
			$html .= '<section class="pos-place__section pos-partner-page__taking-part"><h2>' . esc_html__( 'Taking part', 'packout-core' ) . '</h2>';
			if ( '' !== $coming['html'] ) {
				$html .= '<h3>' . esc_html__( 'Upcoming', 'packout-core' ) . '</h3>' . $coming['html'];
			}
			if ( $campaigns ) {
				$html .= '<h3>' . esc_html__( 'Campaigns', 'packout-core' ) . '</h3>' . Team_Page::campaign_list( array_slice( $campaigns, 0, 20 ) );
			}
			if ( $missions ) {
				$html .= '<h3>' . esc_html__( 'Missions', 'packout-core' ) . '</h3>' . Team_Page::mission_list( array_slice( $missions, 0, 20 ) );
			}
			$html .= '</section>';
		}//end if

		$resources = Resources::of_team( $id, true );
		if ( $resources ) {
			$html .= '<section class="pos-place__section"><h2>' . esc_html__( 'Resources', 'packout-core' ) . '</h2>' . Resources::html( $resources ) . '</section>';
		}
		return $html . '</div></div>';
	}

	/**
	 * The public page of a program.
	 *
	 * @param \WP_Post $program Program.
	 */
	private static function program( \WP_Post $program ): string {
		$id    = $program->ID;
		$row   = Programs::public_payload( $program );
		$whose = '' !== $row['owner_url']
			/* translators: %s: the partner or organization that owns the program, linked. */
			? sprintf( esc_html__( 'A program of %s', 'packout-core' ), '<a href="' . esc_url( $row['owner_url'] ) . '">' . esc_html( $row['owner'] ) . '</a>' )
			: esc_html( Programs::owner_line( $id ) );
		$facts = array( $whose, esc_html( $row['window'] ), Remote::fact( $id ) );
		if ( 'closed' === $row['state'] ) {
			$facts[] = esc_html__( 'Closed', 'packout-core' );
		}

		$html  = '<div class="pos-team-page pos-program-page pos-campaign-page pos-place-page pos-place pos-event">';
		$html .= Page_Header::render(
			array(
				'cover'       => $row['cover'],
				'logo'        => 0,
				'logo_alt'    => '',
				'kind'        => __( 'Program', 'packout-core' ),
				'title'       => $program->post_title,
				'description' => $program->post_content,
				'facts'       => $facts,
				'sponsors'    => $row['sponsors'],
			)
		);
		$html .= '<div class="pos-place__body">';
		if ( ! Programs::shown( $id ) ) {
			$html .= self::preview(
				'publish' === $program->post_status
					? __( 'Only the people who run this program can see this page. Everyone can once its owner is listed.', 'packout-core' )
					: __( 'Only the people who run this program can see this page. Everyone can once you make the program public.', 'packout-core' )
			);
		}
		if ( '' !== $row['goal'] ) {
			$html .= '<section class="pos-place__section pos-program-page__goal"><h2>' . esc_html__( 'Goal', 'packout-core' ) . '</h2><p>' . esc_html( $row['goal'] ) . '</p><p class="pos-team-page__facts">' . esc_html( Programs::progress_line( $row['progress'] ) ) . '</p></section>';
		}

		// Who takes part: listed organizations, and the teams the program names that have a page.
		$cards = array();
		foreach ( Programs::taking_part( $id ) as $team_id ) {
			$team = Teams::get( $team_id );
			if ( $team && 'publish' === $team->post_status && Teams::active( $team_id ) ) {
				$cards[] = array(
					'logo'  => Teams::logo_html( $team_id, 'pos-teams__logo', 'thumbnail' ),
					'name'  => $team->post_title,
					'url'   => (string) get_permalink( $team ),
					'text'  => '',
					'facts' => Teams::field( $team_id, 'region' ),
				);
			}
		}
		$crews = '';
		foreach ( Programs::subteams( $id ) as $named ) {
			if ( Subteams::shown( $named['team'], $named['subteam'] ) ) {
				$crews .= '<li><a href="' . esc_url( Subteams::url( $named['team'], $named['subteam'] ) ) . '">' . esc_html( $named['name'] ) . '</a><span class="pos-team-page__facts">' . esc_html( get_the_title( $named['team'] ) ) . '</span></li>';
			}
		}
		if ( $cards || '' !== $crews ) {
			$html .= '<section class="pos-place__section pos-program-page__taking-part"><h2>' . esc_html__( 'Taking part', 'packout-core' ) . '</h2>' . ( $cards ? self::cards( $cards ) : '' );
			if ( '' !== $crews ) {
				$html .= '<h3>' . esc_html__( 'Teams', 'packout-core' ) . '</h3><ul class="pos-team-page__missions">' . $crews . '</ul>';
			}
			$html .= '</section>';
		}

		// What is coming up, from the calendar. The lists under it are the rest: what is over, and what has no day yet.
		$coming = Calendar_View::upcoming(
			array(
				'program' => $id,
				'show'    => array( 'missions', 'campaigns' ),
				'count'   => 12,
			)
		);
		if ( '' !== $coming['html'] ) {
			$html .= '<section class="pos-place__section pos-program-page__upcoming"><h2>' . esc_html__( 'Upcoming', 'packout-core' ) . '</h2>' . $coming['html'] . '</section>';
		}
		$campaigns = array_values( array_filter( Programs::campaigns( $id, true ), static fn( \WP_Post $campaign ): bool => ! in_array( $campaign->ID, $coming['campaigns'], true ) ) );
		if ( $campaigns ) {
			$html .= '<section class="pos-place__section"><h2>' . esc_html__( 'Campaigns', 'packout-core' ) . '</h2>' . Team_Page::campaign_list( $campaigns ) . '</section>';
		}
		$missions = array_values( array_filter( array_map( array( Missions::class, 'public_payload' ), Programs::missions( $id, true ) ), static fn( array $mission ): bool => ! in_array( (int) $mission['id'], $coming['missions'], true ) ) );
		if ( $missions || '' === $coming['html'] ) {
			$html .= '<section class="pos-place__section"><h2>' . esc_html__( 'Missions', 'packout-core' ) . '</h2>';
			$html .= $missions ? Team_Page::mission_list( $missions ) : '<p>' . esc_html__( 'No mission of this program has been shared yet.', 'packout-core' ) . '</p>';
			$html .= '</section>';
		}

		$html .= Team_Page::impact( Programs::totals( $id, array( 'visible' => true ) ), __( 'No cleanup under this program has been counted yet.', 'packout-core' ) );
		return $html . '</div></div>';
	}

	/**
	 * The Partner list block.
	 *
	 * Shows what the public may know: a partner's name, logo, description,
	 * region, and how many of its organizations are listed. With no partner
	 * to show, the empty state stays on the page.
	 */
	public static function render_directory(): string {
		Ui::enqueue();
		$partners = Partners::listed();
		$html     = '<div ' . Ui::wrapper( 'pos-teams pos-partners' ) . '>';
		if ( ! $partners ) {
			return $html . Admin::ui(
				'empty-state',
				array(
					'title'       => __( 'No partners listed yet', 'packout-core' ),
					'description' => __( 'Partners appear here once a coordinator approves them and they list themselves.', 'packout-core' ),
				)
			) . '</div>';
		}
		$cards = array();
		foreach ( $partners as $partner ) {
			$cards[] = array(
				'logo'  => $partner['logo'],
				'name'  => $partner['name'],
				'url'   => $partner['url'],
				'text'  => $partner['description'],
				/* translators: %s: number of organizations. */
				'facts' => implode( ' · ', array_filter( array( $partner['region'], sprintf( _n( '%s organization', '%s organizations', $partner['organizations'], 'packout-core' ), number_format_i18n( $partner['organizations'] ) ) ) ) ),
			);
		}
		return $html . self::cards( $cards ) . '</div>';
	}
}
