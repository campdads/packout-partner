<?php
/**
 * Public pages of teams and sub-teams.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The public page of a team, and the public page of a sub-team under it.
 *
 * Both open with the header a Place, a campaign, and a mission have: the
 * cover image, the logo, the name, the description, and the sponsors. Below
 * it come what the team is, how to join, its impact, and what it takes part
 * in.
 *
 * A sub-team's page lives under the address of its team:
 * `/team/<team>/<sub-team>/`. It is shown once the people who run the
 * sub-team turn it on, and only while the team is listed. An address the
 * sub-team had before forwards to the one it has now.
 */
class Team_Page {

	/**
	 * Query variable that carries the address of a sub-team.
	 */
	public const QUERY_VAR = 'pos_subteam';

	/**
	 * The sub-team this request is for, once route() has found it.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $subteam = null;

	/**
	 * Register the pages.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'rewrite' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'route' ), 1 );
		add_filter( 'the_content', array( self::class, 'content' ) );
		add_filter( 'document_title_parts', array( self::class, 'title' ) );
		add_filter( 'get_canonical_url', array( self::class, 'canonical' ), 10, 2 );
		add_filter( 'old_slug_redirect_url', array( self::class, 'moved_team' ) );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
		add_action( 'packout_team_joined', array( Subteams::class, 'settle' ), 10, 2 );
	}

	/**
	 * The address of a sub-team's page: one more part after the address of its team.
	 *
	 * WordPress keeps a few words under the address of any post for its own
	 * use, and a number there is a page of the post. Those are left to it,
	 * and a sub-team cannot have them as its address.
	 */
	public static function rewrite(): void {
		$type = get_post_type_object( Schema::TEAM );
		$base = $type && is_array( $type->rewrite ) && ! empty( $type->rewrite['slug'] ) ? (string) $type->rewrite['slug'] : 'team';
		add_rewrite_rule(
			'^' . preg_quote( $base, '#' ) . '/([^/]+)/(?!(?:attachment|embed|feed|page|trackback|comment-page-[0-9]+|[0-9]+)/?$)([a-z0-9][a-z0-9-]*)/?$',
			'index.php?' . Schema::TEAM . '=$matches[1]&' . self::QUERY_VAR . '=$matches[2]',
			'top'
		);
	}

	/**
	 * Let WordPress keep the sub-team's address from the request.
	 *
	 * @param mixed $vars Query variables.
	 * @return mixed
	 */
	public static function query_vars( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[] = self::QUERY_VAR;
		}
		return $vars;
	}

	/**
	 * The address of the sub-team this request asks for, or an empty string.
	 */
	private static function asked(): string {
		return strtolower( trim( (string) get_query_var( self::QUERY_VAR ) ) );
	}

	/**
	 * Find the sub-team a request is for. Forward an old address, and answer "not found" for a page that is not shown.
	 */
	public static function route(): void {
		$address = self::asked();
		if ( '' === $address || ! is_singular( Schema::TEAM ) ) {
			return;
		}
		$team = get_queried_object();
		if ( ! $team instanceof \WP_Post ) {
			return;
		}
		list( $subteam, $state ) = self::lookup( $team->ID, $address );
		if ( 'moved' === $state ) {
			wp_safe_redirect( Subteams::url( $team->ID, (int) $subteam['id'] ), 301 );
			exit;
		}
		if ( 'show' === $state ) {
			self::$subteam = $subteam;
			if ( ! Subteams::shown( $team->ID, (int) $subteam['id'] ) || is_user_logged_in() ) {
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
	 * What an address under a team leads to, for the person looking.
	 *
	 * @param int    $team_id Team ID.
	 * @param string $address Last part of the address.
	 * @return array{0: array<string, mixed>|null, 1: string} The sub-team, and `show`, `moved` when the address is one it had before, or `none`.
	 */
	public static function lookup( int $team_id, string $address ): array {
		list( $subteam, $current ) = Subteams::at( $team_id, $address );
		if ( ! $subteam || ! self::visible( $team_id, $subteam ) ) {
			return array( null, 'none' );
		}
		return array( $subteam, $current ? 'show' : 'moved' );
	}

	/**
	 * Whether the person looking is shown a sub-team's page.
	 *
	 * Everyone is once it is public and its team is listed. Before that, the
	 * people who run the sub-team can open it to see what it will look like.
	 *
	 * @param int                  $team_id Team ID.
	 * @param array<string, mixed> $subteam From Subteams::all().
	 */
	private static function visible( int $team_id, array $subteam ): bool {
		// A team of an organization kept on a partner's site has its page there.
		if ( '' !== (string) ( $subteam['url'] ?? '' ) ) {
			return false;
		}
		if ( Subteams::shown( $team_id, (int) $subteam['id'] ) ) {
			return true;
		}
		$user = get_current_user_id();
		return $user > 0 && Subteams::can_run( $team_id, (int) $subteam['id'], $user );
	}

	/**
	 * The sub-team this request shows, or null on the page of a team.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		return self::$subteam;
	}

	/**
	 * Name the sub-team in the title of the browser tab.
	 *
	 * @param mixed $parts Title parts.
	 * @return mixed
	 */
	public static function title( $parts ) {
		if ( is_array( $parts ) && self::$subteam && is_singular( Schema::TEAM ) ) {
			/* translators: 1: team name, 2: organization name. */
			$parts['title'] = sprintf( __( '%1$s, %2$s', 'packout-core' ), self::$subteam['name'], (string) get_the_title( get_queried_object_id() ) );
		}
		return $parts;
	}

	/**
	 * A sub-team's page is its own page, not a copy of its team's.
	 *
	 * @param mixed $url  Canonical address.
	 * @param mixed $post Post.
	 * @return mixed
	 */
	public static function canonical( $url, $post ) {
		if ( self::$subteam && $post instanceof \WP_Post && Schema::TEAM === $post->post_type && is_singular( Schema::TEAM ) && get_queried_object_id() === $post->ID ) {
			return Subteams::url( $post->ID, (int) self::$subteam['id'] );
		}
		return $url;
	}

	/**
	 * When a team's address changed, keep the sub-team in the address the old link forwards to.
	 *
	 * @param mixed $url Where the old address forwards.
	 * @return mixed
	 */
	public static function moved_team( $url ) {
		$address = self::asked();
		if ( '' === $address || ! is_string( $url ) || '' === $url || 1 !== preg_match( '/^[a-z0-9][a-z0-9-]*$/', $address ) ) {
			return $url;
		}
		return trailingslashit( $url ) . user_trailingslashit( $address );
	}

	/**
	 * Mark the page of a sub-team for themes.
	 *
	 * @param mixed $classes Body classes.
	 * @return mixed
	 */
	public static function body_class( $classes ) {
		if ( is_array( $classes ) && self::$subteam ) {
			$classes[] = 'pos-subteam';
		}
		return $classes;
	}

	/**
	 * Build the page of a team or of a sub-team.
	 *
	 * @param string $content Post content.
	 */
	public static function content( $content ) {
		if ( ! is_singular( Schema::TEAM ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$team = get_post();
		if ( ! $team instanceof \WP_Post ) {
			return $content;
		}
		wp_enqueue_style( 'packout-workspace' );
		wp_enqueue_script( 'packout-workspace' );
		wp_enqueue_style( 'packout-blocks' );
		$subteam = self::$subteam;
		if ( ! $subteam && '' !== self::asked() ) {
			// The request did not come through route(): find the sub-team here, and show nothing for one that is not shown.
			list( $subteam, $state ) = self::lookup( $team->ID, self::asked() );
			if ( 'show' !== $state ) {
				return '';
			}
		}
		return $subteam ? self::subteam( $team, $subteam ) : self::team( $team );
	}

	/**
	 * The public page of a team.
	 *
	 * It shows what the team wrote about itself, how to join, its sub-teams
	 * that have a page, its impact, its public campaigns and missions, the
	 * Places it adopted, its sponsors, and its public resources. It shows no
	 * email address, and it names the team's leads only when the team chose that.
	 *
	 * @param \WP_Post $team Team.
	 */
	private static function team( \WP_Post $team ): string {
		$id     = $team->ID;
		$count  = count( Teams::people( $id ) );
		$region = Teams::field( $id, 'region' );
		$site   = Teams::field( $id, 'website' );
		$host   = '' !== $site ? (string) wp_parse_url( $site, PHP_URL_HOST ) : '';
		$link   = '' !== $site ? '<a href="' . esc_url( $site ) . '" rel="nofollow noopener">' . esc_html( '' !== $host ? $host : $site ) . '</a>' : '';
		/* translators: %s: number of people. */
		$people   = sprintf( _n( '%s person', '%s people', $count, 'packout-core' ), number_format_i18n( $count ) );
		$subteams = Subteams::listed( $id );
		$remote   = Remote::is( $id );
		// The people of an organization kept on a partner's site are counted there, and are not known here.
		$facts = $remote ? array( Partner_Page::partner_fact( $id ), Remote::fact( $id ), esc_html( $region ) ) : array( Partner_Page::partner_fact( $id ), esc_html( $region ), esc_html( $people ) );
		if ( $subteams ) {
			$facts[] = esc_html(
				sprintf(
					/* translators: %s: number of teams. */
					_n( '%s team', '%s teams', count( $subteams ), 'packout-core' ),
					number_format_i18n( count( $subteams ) )
				)
			);
		}
		if ( Teams::shows_leads( $id ) ) {
			foreach ( self::leaders( Teams::lead( $id ), Teams::coleads( $id ) ) as $label => $value ) {
				$facts[] = esc_html( $label ) . ' ' . $value;
			}
		}
		if ( ! $remote ) {
			$facts[] = esc_html( Teams::join_policies()[ Teams::join_policy( $id ) ] );
		}
		$facts[] = $link;

		$html  = '<div class="pos-team-page pos-campaign-page pos-place-page pos-place pos-event">';
		$html .= Page_Header::render(
			array(
				'cover'       => Teams::cover( $id ),
				'logo'        => Teams::logo( $id ),
				/* translators: %s: organization name. */
				'logo_alt'    => sprintf( __( 'Logo of %s', 'packout-core' ), $team->post_title ),
				'kind'        => __( 'Organization', 'packout-core' ),
				'title'       => $team->post_title,
				'description' => $team->post_content,
				'facts'       => $facts,
				'sponsors'    => Sponsors::all( $id ),
			)
		);
		$html .= '<div class="pos-place__body">';
		$html .= self::join_team( $team );

		if ( $subteams ) {
			$html .= '<section class="pos-place__section pos-team-page__subteams"><h2>' . esc_html__( 'Teams', 'packout-core' ) . '</h2><ul class="pos-place__adopters">';
			foreach ( $subteams as $subteam ) {
				$html .= self::subteam_card( $id, $subteam );
			}
			$html .= '</ul></section>';
		}

		$html .= self::impact(
			$remote ? Remote::reported( $id ) : Impact::totals(
				array(
					'team'    => $id,
					'visible' => true,
				)
			),
			__( 'No cleanup by this organization has been counted yet.', 'packout-core' )
		);

		$campaigns = Campaigns::of_team( $id, true );
		if ( $campaigns ) {
			$html .= '<section class="pos-place__section"><h2>' . esc_html__( 'Campaigns', 'packout-core' ) . '</h2>' . self::campaign_list( $campaigns ) . '</section>';
		}
		// The joint campaigns of other hosts it takes part in, or took part in.
		$joint = Joint::public_of( $id );
		if ( $joint ) {
			$html .= '<section class="pos-place__section"><h2>' . esc_html__( 'Joint campaigns it takes part in', 'packout-core' ) . '</h2>' . self::campaign_list( $joint ) . '</section>';
		}

		// What is coming up, from the calendar. The list under it is the rest: what is over, and what has no day yet.
		$coming = Calendar_View::upcoming(
			array(
				'organization' => $id,
				'show'         => array( 'missions' ),
				'count'        => 12,
			)
		);
		if ( '' !== $coming['html'] ) {
			$html .= '<section class="pos-place__section pos-team-page__upcoming"><h2>' . esc_html__( 'Upcoming', 'packout-core' ) . '</h2>' . $coming['html'] . '</section>';
		}
		$missions = array_values( array_filter( Missions::public_of_team( $id ), static fn( array $mission ): bool => ! in_array( (int) $mission['id'], $coming['missions'], true ) ) );
		if ( $missions || '' === $coming['html'] ) {
			$html .= '<section class="pos-place__section"><h2>' . esc_html__( 'Missions', 'packout-core' ) . '</h2>';
			$html .= $missions ? self::mission_list( $missions ) : '<p>' . esc_html__( 'This organization has not shared a mission yet.', 'packout-core' ) . '</p>';
			$html .= '</section>';
		}

		$places = Places::of_team( $id, true );
		if ( $places ) {
			$html .= '<section class="pos-place__section"><h2>' . esc_html__( 'Adopted Places', 'packout-core' ) . '</h2><ul class="pos-team-page__missions">';
			foreach ( $places as $place ) {
				$row   = Places::public_payload( $place );
				$html .= '<li><a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['name'] ) . '</a>';
				/* translators: %s: number of Spots. */
				$html .= '<span class="pos-team-page__facts">' . esc_html( implode( ' · ', array( $row['kind_label'], sprintf( _n( '%s open Spot', '%s open Spots', $row['open'], 'packout-core' ), number_format_i18n( $row['open'] ) ) ) ) ) . '</span></li>';
			}
			$html .= '</ul></section>';
		}

		$resources = Resources::of_team( $id, true );
		if ( $resources ) {
			$html .= '<section class="pos-place__section"><h2>' . esc_html__( 'Resources', 'packout-core' ) . '</h2>' . Resources::html( $resources ) . '</section>';
		}
		return $html . '</div></div>';
	}

	/**
	 * The public page of a sub-team.
	 *
	 * It shows what the sub-team wrote about itself, the team it belongs to,
	 * who leads it, how to join, the impact of the missions assigned to it,
	 * and the public campaigns and missions it takes part in. It shows no
	 * email address and names no member.
	 *
	 * @param \WP_Post             $team    Team.
	 * @param array<string, mixed> $subteam From Subteams::all().
	 */
	private static function subteam( \WP_Post $team, array $subteam ): string {
		$id       = $team->ID;
		$sub      = (int) $subteam['id'];
		$count    = count( array_unique( array_filter( array_merge( array( (int) $subteam['lead'] ), (array) $subteam['members'] ) ) ) );
		$team_url = (string) get_permalink( $team );
		$team_tag = '<a href="' . esc_url( $team_url ) . '">' . esc_html( $team->post_title ) . '</a>';
		/* translators: %s: number of people. */
		$people = sprintf( _n( '%s person', '%s people', $count, 'packout-core' ), number_format_i18n( $count ) );
		$logo   = Subteams::logo( $id, $sub );
		$cover  = Subteams::cover( $id, $sub );
		$facts  = array( $team_tag, esc_html( $people ) );
		if ( $subteam['leads'] ) {
			foreach ( self::leaders( (int) $subteam['lead'], (array) $subteam['coleads'] ) as $label => $value ) {
				$facts[] = esc_html( $label ) . ' ' . $value;
			}
		}
		$facts[] = $subteam['youth'] ? esc_html__( 'Youth team', 'packout-core' ) : esc_html( Subteams::join_policies()[ $subteam['join'] ] );

		$html  = '<div class="pos-team-page pos-subteam-page pos-campaign-page pos-place-page pos-place pos-event">';
		$html .= Page_Header::render(
			array(
				// Without its own, the sub-team wears the cover and the logo of its team.
				'cover'       => $cover > 0 ? $cover : Teams::cover( $id ),
				'logo'        => $logo > 0 ? $logo : Teams::logo( $id ),
				/* translators: %s: team or organization name. */
				'logo_alt'    => sprintf( __( 'Logo of %s', 'packout-core' ), $logo > 0 ? $subteam['name'] : $team->post_title ),
				'kind'        => __( 'Team', 'packout-core' ),
				'title'       => $subteam['name'],
				'description' => $subteam['about'],
				'facts'       => $facts,
				'sponsors'    => Sponsors::all( $id ),
			)
		);
		$html .= '<div class="pos-place__body">';
		if ( ! Subteams::shown( $id, $sub ) ) {
			$html .= '<p class="pos-place-page__note pos-subteam-page__preview">' . esc_html(
				$subteam['public']
					? __( 'Only the people who run this team can see this page. Everyone can once the organization is listed.', 'packout-core' )
					: __( 'Only the people who run this team can see this page. Everyone can once you make it public in My organizations.', 'packout-core' )
			) . '</p>';
		}

		$html .= self::join_subteam( $team, $subteam );

		$html .= self::impact(
			Subteams::totals( $id, $sub, true ),
			__( 'No cleanup on a mission of this team has been counted yet.', 'packout-core' )
		);

		// What it takes part in.
		$campaigns = array_values( array_filter( Campaigns::of_team( $id, true ), static fn( $campaign ) => in_array( $sub, Campaigns::subteams( $campaign->ID ), true ) ) );
		$missions  = array_map( array( Missions::class, 'public_payload' ), Subteams::missions( $id, $sub, true ) );
		$done      = count( array_filter( $missions, static fn( $mission ) => 'completed' === $mission['status'] ) );
		$figures   = array(
			__( 'People', 'packout-core' )             => number_format_i18n( $count ),
			__( 'Campaigns', 'packout-core' )          => number_format_i18n( count( $campaigns ) ),
			__( 'Missions', 'packout-core' )           => number_format_i18n( count( $missions ) ),
			__( 'Missions completed', 'packout-core' ) => number_format_i18n( $done ),
		);
		$html     .= '<section class="pos-place__section pos-team-page__part"><h2>' . esc_html__( 'Taking part', 'packout-core' ) . '</h2>' . self::figures( $figures );
		if ( ! $campaigns && ! $missions ) {
			$html .= '<p>' . esc_html__( 'No campaign or mission shared with the public names this team yet.', 'packout-core' ) . '</p>';
		}
		if ( $campaigns ) {
			$html .= '<h3>' . esc_html__( 'Campaigns', 'packout-core' ) . '</h3>' . self::campaign_list( $campaigns );
		}
		if ( $missions ) {
			$html .= '<h3>' . esc_html__( 'Missions', 'packout-core' ) . '</h3>' . self::mission_list( $missions );
		}
		$html .= '</section>';

		return $html . '</div></div>';
	}

	/**
	 * A sub-team on the page of its team: its logo, its name, what it is, and a link to its page.
	 *
	 * @param int                  $team_id Team ID.
	 * @param array<string, mixed> $subteam From Subteams::all().
	 */
	private static function subteam_card( int $team_id, array $subteam ): string {
		$sub   = (int) $subteam['id'];
		$url   = Subteams::url( $team_id, $sub );
		$logo  = Subteams::logo( $team_id, $sub );
		$count = count( array_unique( array_filter( array_merge( array( (int) $subteam['lead'] ), (array) $subteam['members'] ) ) ) );
		$away  = '' !== (string) ( $subteam['url'] ?? '' );
		$html  = '<li class="pos-place__adopter">';
		if ( $logo > 0 ) {
			$html .= wp_get_attachment_image(
				$logo,
				'medium',
				false,
				array(
					'class' => 'pos-place__adopter-logo',
					'alt'   => '',
				)
			);
		}
		$html .= '<div class="pos-place__adopter-text"><h3><a href="' . esc_url( $url ) . '">' . esc_html( $subteam['name'] ) . '</a></h3>';
		/* translators: %s: number of people. */
		$html .= $away ? '' : '<p class="pos-place__since">' . esc_html( sprintf( _n( '%s person', '%s people', $count, 'packout-core' ), number_format_i18n( $count ) ) ) . '</p>';
		if ( '' !== trim( $subteam['about'] ) ) {
			$html .= '<p>' . esc_html( wp_trim_words( $subteam['about'], 30 ) ) . '</p>';
		}
		/* translators: %s: team name. */
		$html .= '<p><a class="pos-place__more" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( sprintf( __( 'The page of %s', 'packout-core' ), $subteam['name'] ) ) . '">' . esc_html__( 'See the team', 'packout-core' ) . '</a></p>';
		return $html . '</div></li>';
	}

	/**
	 * The lead and the co-leads, as facts.
	 *
	 * @param int             $lead    User ID of the lead, or 0.
	 * @param array<int, int> $coleads User IDs of the co-leads.
	 * @return array<string, string> Label and escaped value.
	 */
	public static function leaders( int $lead, array $coleads ): array {
		$name  = static function ( int $user_id ): string {
			$person = get_userdata( $user_id );
			return $person ? (string) $person->display_name : '';
		};
		$facts = array();
		if ( '' !== $name( $lead ) ) {
			$facts[ __( 'Lead', 'packout-core' ) ] = esc_html( $name( $lead ) );
		}
		$names = array_filter( array_map( $name, array_map( 'intval', $coleads ) ) );
		if ( $names ) {
			$facts[ _n( 'Co-lead', 'Co-leads', count( $names ), 'packout-core' ) ] = esc_html( implode( ', ', $names ) );
		}
		return $facts;
	}

	/**
	 * Figures as a row of cards.
	 *
	 * @param array<string, string> $figures Label and value.
	 */
	private static function figures( array $figures ): string {
		$html = '<dl class="pos-place__figures">';
		foreach ( $figures as $label => $value ) {
			$html .= '<div class="pos-place__figure"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		return $html . '</dl>';
	}

	/**
	 * The impact section: the figures a coordinator counts publicly.
	 *
	 * @param array<string, array<string, int|float>> $totals From Impact::totals().
	 * @param string                                  $none   What to say when nothing has been counted.
	 */
	public static function impact( array $totals, string $none ): string {
		$html = '<section class="pos-place__section pos-team-page__impact"><h2>' . esc_html__( 'Impact', 'packout-core' ) . '</h2>';
		if ( ! empty( $totals['reported'] ) && (int) $totals['records']['cleanups'] > 0 ) {
			// Figures a partner site sent are said to be that, with their day.
			$html .= '<p class="pos-place-page__note pos-remote__reported">' . esc_html(
				sprintf(
					/* translators: 1: the name of a partner, 2: a date. */
					__( 'Reported by %1$s from its own site, as of %2$s. Verified here means verified by its own people.', 'packout-core' ),
					(string) ( $totals['partner'] ?? __( 'a partner', 'packout-core' ) ),
					wp_date( get_option( 'date_format' ), (int) strtotime( (string) $totals['reported'] . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) )
				)
			) . '</p>';
		}
		if ( (int) $totals['records']['cleanups'] <= 0 ) {
			return $html . '<p>' . esc_html( $none ) . '</p></section>';
		}
		$html .= self::figures(
			array(
				__( 'Spots with a cleanup', 'packout-core' ) => number_format_i18n( (int) $totals['outputs']['spots'] ),
				__( 'Missions completed', 'packout-core' ) => number_format_i18n( (int) $totals['effort']['missions'] ),
				__( 'Hours, verified', 'packout-core' )    => Impact::number( (float) $totals['effort']['hours_verified'] ),
				__( 'Hours, unverified', 'packout-core' )  => Impact::number( (float) $totals['effort']['hours_unverified'] ),
				__( 'Bags, measured', 'packout-core' )     => number_format_i18n( (int) $totals['outputs']['bags_measured'] ),
				__( 'Bags, self-reported', 'packout-core' ) => number_format_i18n( (int) $totals['outputs']['bags_self_reported'] ),
				__( 'Pounds, measured', 'packout-core' )   => Impact::number( (float) $totals['outputs']['pounds_measured'] ),
				__( 'Pounds, self-reported', 'packout-core' ) => Impact::number( (float) $totals['outputs']['pounds_self_reported'] ),
			)
		);
		if ( (int) $totals['outcomes']['still_clear'] > 0 || (int) $totals['outcomes']['returned'] > 0 ) {
			$html .= '<p>' . esc_html(
				sprintf(
					/* translators: 1: number of Spots, 2: number of Spots. */
					__( 'On later visits, %1$s Spots were still clear and the problem had come back at %2$s.', 'packout-core' ),
					number_format_i18n( (int) $totals['outcomes']['still_clear'] ),
					number_format_i18n( (int) $totals['outcomes']['returned'] )
				)
			) . '</p>';
		}
		return $html . '<p class="pos-place-page__note">' . esc_html__( 'Measured means counted or weighed. Self-reported figures are estimates from the people who did the work, and the two are never added together.', 'packout-core' ) . '</p></section>';
	}

	/**
	 * Public campaigns as a list.
	 *
	 * @param array<int, \WP_Post> $campaigns Campaigns.
	 */
	public static function campaign_list( array $campaigns ): string {
		$html = '<ul class="pos-team-page__missions">';
		foreach ( $campaigns as $campaign ) {
			$row   = Campaigns::public_payload( $campaign );
			$html .= '<li><a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['title'] ) . '</a>';
			$html .= '<span class="pos-team-page__facts">' . esc_html( implode( ' · ', array_filter( array( $row['window'], Campaigns::progress_line( $row['progress'] ) ) ) ) ) . '</span></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Public missions as a list.
	 *
	 * @param array<int, array<string, mixed>> $missions From Missions::public_payload().
	 */
	public static function mission_list( array $missions ): string {
		$html = '<ul class="pos-team-page__missions">';
		foreach ( $missions as $mission ) {
			$html .= '<li><a href="' . esc_url( $mission['url'] ) . '">' . esc_html( $mission['title'] ) . '</a>';
			$html .= '<span class="pos-team-page__facts">' . esc_html( implode( ' · ', array_filter( array( $mission['status_label'], $mission['window'], $mission['spots_label'] ) ) ) ) . '</span></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * What a form on this page answered, for the person who sent it.
	 *
	 * @param int                $user    User ID.
	 * @param array<int, string> $actions The forms of this section.
	 */
	private static function said( int $user, array $actions ): string {
		if ( $user <= 0 ) {
			return '';
		}
		$html = Workspace::flash( $user );
		if ( in_array( Workspace::failed_action(), $actions, true ) && '' !== Workspace::problem() ) {
			$html .= Workspace::notice( 'error', Workspace::problem() );
		}
		return $html;
	}

	/**
	 * The buttons that send a visitor to sign in or to sign up, then back here.
	 *
	 * @param string $here Where to come back to.
	 */
	private static function sign_in( string $here ): string {
		$html = '<p class="pos-ws__actions"><a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( Accounts::signin_url( $here ) ) . '">' . esc_html__( 'Sign in to join', 'packout-core' ) . '</a>';
		if ( Accounts::open() ) {
			$html .= ' <a class="pos-ws__button" href="' . esc_url( Accounts::signup_url( $here ) ) . '">' . esc_html__( 'Sign up', 'packout-core' ) . '</a>';
		}
		return $html . '</p>';
	}

	/**
	 * How to join, for the person looking at a team's public page.
	 *
	 * A visitor's page may be cached, so it says nothing about who is looking.
	 *
	 * @param \WP_Post $team Team.
	 */
	private static function join_team( \WP_Post $team ): string {
		if ( Remote::is( $team ) ) {
			/* translators: %s: the name of a partner. */
			return Remote::sign_up( $team->ID, __( 'Join this organization', 'packout-core' ), sprintf( __( 'This organization keeps its members on the site of %s. Join it there.', 'packout-core' ), Remote::partner_name( $team->ID ) ) );
		}
		$id = $team->ID;
		if ( Remote::moved( $id ) > 0 ) {
			// Its work is on its own site now, and so is joining it.
			return Remote::sign_up( Remote::moved( $id ), __( 'This organization has its own site', 'packout-core' ), Remote::moved_words( $id ) );
		}
		$policy = Teams::join_policy( $id );
		$here   = (string) get_permalink( $team );
		$user   = get_current_user_id();
		$html   = '<section class="pos-place__section pos-signup pos-team-page__join pos-ws" id="pos-join"><h2>' . esc_html__( 'Join this organization', 'packout-core' ) . '</h2>';
		$end    = '</section>';

		if ( $user > 0 && Teams::includes( $id, $user ) ) {
			return $html . '<p>' . esc_html__( 'You are in this organization.', 'packout-core' ) . ' <a href="' . esc_url( Workspace::url( $id ) ) . '">' . esc_html__( 'Open it in My organizations', 'packout-core' ) . '</a></p>' . $end;
		}
		if ( 'closed' === $policy ) {
			return $html . '<p>' . esc_html__( 'This organization adds its members by invitation.', 'packout-core' ) . '</p>' . $end;
		}
		$html .= '<p>' . esc_html( 'open' === $policy ? __( 'Anyone with an account can join. The organization\'s lead sees your name and email address.', 'packout-core' ) : __( 'Ask to join and the organization\'s lead will answer. If they accept, the lead sees your name and email address.', 'packout-core' ) ) . '</p>';

		if ( $user <= 0 ) {
			return $html . self::sign_in( $here ) . $end;
		}
		if ( ! Accounts::on_site( $user ) ) {
			return $html . '<p><a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( Workspace::url() ) . '">' . esc_html__( 'Join Pack-Out Spot first', 'packout-core' ) . '</a></p>' . $end;
		}
		if ( in_array( $user, Teams::requests( $id ), true ) ) {
			return $html . '<p>' . esc_html__( 'Your request is with the organization\'s lead.', 'packout-core' ) . '</p>' . Workspace::act( 'withdraw', $id, __( 'Withdraw the request', 'packout-core' ), array( 'back' => $here ) ) . $end;
		}
		return $html . Workspace::act( 'ask', $id, 'open' === $policy ? __( 'Join the organization', 'packout-core' ) : __( 'Ask to join', 'packout-core' ), array( 'back' => $here ), 'pos-ws__button--primary' ) . $end;
	}

	/**
	 * How to join, for the person looking at a sub-team's public page.
	 *
	 * A sub-team takes its members from its team, so a person who is not on
	 * the team is told what the team asks as well.
	 *
	 * @param \WP_Post             $team    Team.
	 * @param array<string, mixed> $subteam From Subteams::all().
	 */
	private static function join_subteam( \WP_Post $team, array $subteam ): string {
		$id     = $team->ID;
		$sub    = (int) $subteam['id'];
		$policy = (string) $subteam['join'];
		$from   = Teams::join_policy( $id );
		$user   = get_current_user_id();
		$here   = Subteams::url( $id, $sub ) . '#pos-join';
		$hidden = array(
			'item' => (string) $sub,
			'back' => $here,
		);
		$html   = '<section class="pos-place__section pos-signup pos-team-page__join pos-ws" id="pos-join"><h2>' . esc_html__( 'Join this team', 'packout-core' ) . '</h2>';
		$html  .= self::said( $user, array( 'subteam_ask', 'subteam_withdraw', 'subteam_leave' ) );
		$end    = '</section>';

		if ( $user > 0 && Subteams::includes( $id, $sub, $user ) ) {
			$html .= '<p>' . esc_html__( 'You are in this team.', 'packout-core' ) . ' <a href="' . esc_url( Workspace::url( $id, 'subteams' ) ) . '">' . esc_html__( 'Open it in My organizations', 'packout-core' ) . '</a></p>';
			if ( (int) $subteam['lead'] !== $user ) {
				$html .= '<div class="pos-ws__actions">' . Workspace::act( 'subteam_leave', $id, __( 'Leave this team', 'packout-core' ), $hidden, 'pos-ws__button--danger', __( 'Leave this team?', 'packout-core' ), '', __( 'You stay in the organization. You stop seeing the missions assigned to this team alone.', 'packout-core' ) ) . '</div>';
			}
			return $html . $end;
		}
		$on_team = $user > 0 && Teams::includes( $id, $user );
		if ( $subteam['youth'] ) {
			// Nobody joins a team of young people from a public page.
			return $html . '<p>' . esc_html__( 'This is a youth team. Its lead adds its members, and nobody joins it from this page.', 'packout-core' ) . '</p>' . $end;
		}
		if ( 'closed' === $policy ) {
			/* translators: %s: organization name. */
			return $html . '<p>' . esc_html( sprintf( __( 'The lead of this team chooses its members from the people in %s.', 'packout-core' ), $team->post_title ) ) . ( $on_team ? '' : ' <a href="' . esc_url( (string) get_permalink( $team ) . '#pos-join' ) . '">' . esc_html__( 'See how to join the organization', 'packout-core' ) . '</a>' ) . '</p>' . $end;
		}
		if ( $user > 0 && ! $on_team && 'closed' === $from ) {
			/* translators: %s: organization name. */
			return $html . '<p>' . esc_html( sprintf( __( 'This team takes its members from %s, which adds its members by invitation.', 'packout-core' ), $team->post_title ) ) . '</p>' . $end;
		}

		$lines = array( 'open' === $policy ? __( 'Anyone with an account can join.', 'packout-core' ) : __( 'Ask to join and the team\'s lead will answer.', 'packout-core' ) );
		if ( $user <= 0 ) {
			// A visitor's page may be cached, so it says nothing about who is looking.
			$lines[] = 'open' === $from
				/* translators: %s: organization name. */
				? sprintf( __( 'If you are not in %s yet, joining puts you in the organization as well.', 'packout-core' ), $team->post_title )
				/* translators: %s: organization name. */
				: sprintf( __( 'A team takes its members from its organization. If you are not in %s yet, this asks to join the organization as well, and the organization\'s lead answers that first.', 'packout-core' ), $team->post_title );
		} elseif ( ! $on_team ) {
			$lines[] = 'open' === $from
				/* translators: %s: organization name. */
				? sprintf( __( 'Joining also adds you to %s, the organization it belongs to.', 'packout-core' ), $team->post_title )
				/* translators: %s: organization name. */
				: sprintf( __( 'A team takes its members from its organization, so this also asks to join %s. The organization\'s lead answers that first.', 'packout-core' ), $team->post_title );
		}
		$lines[] = __( 'The leads see your name and email address.', 'packout-core' );
		$html   .= '<p>' . esc_html( implode( ' ', $lines ) ) . '</p>';

		if ( $user <= 0 ) {
			return $html . self::sign_in( $here ) . $end;
		}
		if ( ! Accounts::on_site( $user ) ) {
			return $html . '<p><a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( Workspace::url() ) . '">' . esc_html__( 'Join Pack-Out Spot first', 'packout-core' ) . '</a></p>' . $end;
		}
		if ( in_array( $user, (array) $subteam['requests'], true ) ) {
			$waiting = $on_team ? __( 'Your request is with the team\'s lead.', 'packout-core' ) : __( 'Your request is with the organization\'s lead. The team follows once you are in the organization.', 'packout-core' );
			return $html . '<p>' . esc_html( $waiting ) . '</p><div class="pos-ws__actions">' . Workspace::act( 'subteam_withdraw', $id, __( 'Withdraw the request', 'packout-core' ), $hidden ) . '</div>' . $end;
		}
		$label = 'open' === $policy && ( $on_team || 'open' === $from ) ? __( 'Join the team', 'packout-core' ) : __( 'Ask to join', 'packout-core' );
		return $html . '<div class="pos-ws__actions">' . Workspace::act( 'subteam_ask', $id, $label, $hidden, 'pos-ws__button--primary' ) . '</div>' . $end;
	}
}
