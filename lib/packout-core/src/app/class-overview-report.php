<?php
/**
 * Impact report for a coordinator to hand to a partner.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One data array, then JSON, CSV, HTML, or PDF.
 */
class Overview_Report {

	/**
	 * Formats the download accepts.
	 *
	 * @return array<string, string>
	 */
	public static function formats(): array {
		return array(
			'pdf'  => __( 'PDF', 'packout-core' ),
			'html' => __( 'HTML', 'packout-core' ),
			'json' => __( 'JSON', 'packout-core' ),
			'csv'  => __( 'CSV', 'packout-core' ),
		);
	}

	/**
	 * The record a report is about, when it is not the whole platform's.
	 *
	 * @param array<string, mixed> $scope team, partner, program, or campaign: the record's ID.
	 * @return array{kind: string, id: int, name: string, slug: string} `kind` is empty for the platform.
	 */
	public static function subject( array $scope ): array {
		$none = array(
			'kind' => '',
			'id'   => 0,
			'name' => '',
			'slug' => '',
		);
		foreach ( array(
			'partner'  => array( Partners::class, 'get' ),
			'team'     => array( Teams::class, 'get' ),
			'program'  => array( Programs::class, 'get' ),
			'campaign' => array( Campaigns::class, 'get' ),
		) as $kind => $get ) {
			if ( empty( $scope[ $kind ] ) ) {
				continue;
			}
			$post = $get( (int) $scope[ $kind ] );
			return $post ? array(
				'kind' => $kind,
				'id'   => $post->ID,
				'name' => $post->post_title,
				'slug' => '' !== $post->post_name ? $post->post_name : (string) $post->ID,
			) : $none;
		}
		return $none;
	}

	/**
	 * The report as a structured array.
	 *
	 * The whole platform's with no scope. With `team`, `partner`, `program`,
	 * or `campaign`, the report of that record: its cover names it, and every
	 * figure is the record's own.
	 *
	 * @param int                  $days  Window.
	 * @param array<string, mixed> $scope Optional team, partner, program, campaign, or place ID.
	 * @return array<string, mixed>
	 */
	public static function data( int $days, array $scope = array() ): array {
		$subject = self::subject( $scope );
		$kind    = $subject['kind'];
		// A program and a campaign can be reported whole, from their first day, as their pages show them.
		$whole = $days <= 0 && in_array( $kind, array( 'program', 'campaign' ), true );
		$days  = $whole ? 0 : Overview::window( $days );
		$view  = in_array( $kind, array( 'team', 'partner' ), true ) ? array(
			'kind' => $kind,
			'id'   => $subject['id'],
		) : array();
		// A program and a campaign have no overview. Their figures come from their missions.
		$missions = null;
		if ( 'program' === $kind ) {
			$missions = wp_list_pluck( Programs::missions( $subject['id'] ), 'ID' );
		} elseif ( 'campaign' === $kind ) {
			$missions = wp_list_pluck( Campaigns::missions( $subject['id'] ), 'ID' );
		}
		$period = null === $missions ? Overview::period( $days, $view ) : ( $whole ? self::since( $subject['id'], array_map( 'intval', $missions ) ) : Overview::bounds( $days ) );
		$from   = (string) $period['from'];
		$to     = (string) $period['to'];
		$days   = $whole ? (int) ( ( strtotime( $to . ' 12:00:00 UTC' ) - strtotime( $from . ' 12:00:00 UTC' ) ) / DAY_IN_SECONDS ) + 1 : $days;
		$args   = array(
			'from' => $from,
			'to'   => $to,
		);
		if ( 'team' === $kind ) {
			$args['team'] = $subject['id'];
		} elseif ( 'partner' === $kind ) {
			$args['teams'] = Partners::organization_ids( $subject['id'] );
		} elseif ( null !== $missions ) {
			$args['missions'] = array_map( 'intval', $missions );
		}
		if ( ! empty( $scope['place'] ) ) {
			$place = Places::get( (int) $scope['place'] );
			if ( $place ) {
				$args['spots'] = wp_list_pluck( Places::spots( $place->ID ), 'ID' );
			}
		}
		$totals = Impact::totals( $args );
		$open   = 0;
		if ( null === $missions ) {
			$a   = $period['a']['current'] ?? array();
			$d   = $period['d']['current'] ?? array();
			$now = Overview::now( $view );
			foreach ( (array) ( $now['stages'] ?? array() ) as $status => $count ) {
				if ( 'cleaned' !== $status ) {
					$open += (int) $count;
				}
			}
		} else {
			$a       = Overview_Scope::taken_on( $args['missions'], $from, $to );
			$open    = $a['open'];
			$records = Impact::found( $args + array( 'kind' => Impact::CLEANUP ) );
			$d       = array(
				'total_cleanups' => count( $records ),
				'counted'        => count( array_filter( $records, static fn( $record ) => 'publish' === $record->post_status ) ),
			);
			$period += array(
				'a' => array( 'reading' => Overview_Scope::reading_a( $a + array( 'net_open' => $a['reported'] - $a['cleaned'] ) ) ),
				'd' => array( 'reading' => Overview::reading( 'd', $d + array( 'verified' => count( array_filter( $records, static fn( $record ) => Impact::verified( $record->ID ) ) ) ) ) ),
			);
		}//end if
		$titles = array(
			/* translators: %s: organization name. */
			'team'     => __( '%s impact report', 'packout-core' ),
			/* translators: %s: partner name. */
			'partner'  => __( '%s impact report', 'packout-core' ),
			/* translators: %s: program name. */
			'program'  => __( '%s program impact report', 'packout-core' ),
			/* translators: %s: campaign name. */
			'campaign' => __( '%s campaign impact report', 'packout-core' ),
		);
		return array(
			'schema'                 => 1,
			'generated_at'           => gmdate( 'c' ),
			'title'                  => isset( $titles[ $kind ] ) ? sprintf( $titles[ $kind ], $subject['name'] ) : __( 'Pack-Out Spot impact report', 'packout-core' ),
			'subject'                => array(
				'kind'  => '' !== $kind ? $kind : 'platform',
				'name'  => $subject['name'],
				'owner' => self::owner( $kind, $subject['id'] ),
			),
			'site'                   => home_url( '/' ),
			'period'                 => array(
				'from' => $from,
				'to'   => $to,
				'days' => $days,
			),
			'partner_sites'          => self::partner_sites( $kind, $subject['id'] ),
			'summary'                => trim(
				implode(
					' ',
					array_filter(
						array(
							(string) ( $period['a']['reading'] ?? '' ),
							(string) ( $period['b']['reading'] ?? '' ),
							(string) ( $period['d']['reading'] ?? '' ),
						)
					)
				)
			),
			'outcomes'               => array(
				'spots_cleaned' => (int) ( $totals['outputs']['spots'] ?? 0 ),
				'still_clear'   => (int) ( $totals['outcomes']['still_clear'] ?? 0 ),
				'returned'      => (int) ( $totals['outcomes']['returned'] ?? 0 ),
			),
			'outputs'                => array(
				'bags_measured'        => (int) ( $totals['outputs']['bags_measured'] ?? 0 ),
				'bags_self_reported'   => (int) ( $totals['outputs']['bags_self_reported'] ?? 0 ),
				'pounds_measured'      => (float) ( $totals['outputs']['pounds_measured'] ?? 0 ),
				'pounds_self_reported' => (float) ( $totals['outputs']['pounds_self_reported'] ?? 0 ),
			),
			'effort'                 => array(
				'hours_verified'   => (float) ( $totals['effort']['hours_verified'] ?? 0 ),
				'hours_unverified' => (float) ( $totals['effort']['hours_unverified'] ?? 0 ),
				'people'           => (int) ( $totals['effort']['people'] ?? 0 ),
				'missions'         => (int) ( $totals['effort']['missions'] ?? 0 ),
				'organizations'    => (int) ( $totals['effort']['teams'] ?? 0 ),
			),
			'from_report_to_cleanup' => array(
				'reported'    => (int) ( $a['reported'] ?? 0 ),
				'cleaned'     => (int) ( $a['cleaned'] ?? 0 ),
				'median_days' => $a['median_days'] ?? null,
				'open_at_end' => $open,
			),
			'who'                    => 'team' === $kind ? self::who_in( $subject['id'], $from, $to ) : self::who( $from, $to, $args ),
			'where'                  => self::where( $from, $to, $args ),
			'how_to_read'            => array(
				'verified'  => __( 'A verified record is one a coordinator or lead has confirmed. Unverified records remain in this report and are labeled.', 'packout-core' ),
				'sources'   => __( 'Measured and self-reported figures are kept apart. They are never added into one unmarked total.', 'packout-core' ),
				'counted'   => sprintf(
					/* translators: 1: counted records, 2: all cleanup records. */
					_n( '%1$s of %2$s cleanup record is counted in public totals.', '%1$s of %2$s cleanup records are counted in public totals.', (int) ( $d['total_cleanups'] ?? 0 ), 'packout-core' ),
					number_format_i18n( (int) ( $d['counted'] ?? 0 ) ),
					number_format_i18n( (int) ( $d['total_cleanups'] ?? 0 ) )
				),
				'generated' => sprintf(
					/* translators: %s: datetime. */
					__( 'Generated %s.', 'packout-core' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
				),
			),
		);
	}

	/**
	 * The first day of a program or a campaign, and today: the window of a report of the whole of it.
	 *
	 * The first day is the earliest of the day it was made, the day it
	 * starts, the day each of its missions was made, and the day of each
	 * record of them, so nothing the page counts falls outside.
	 *
	 * @param int             $id       The program or the campaign.
	 * @param array<int, int> $missions Its missions.
	 * @return array{from: string, to: string}
	 */
	private static function since( int $id, array $missions ): array {
		$today = wp_date( 'Y-m-d' );
		$days  = array( $today, (string) get_post_time( 'Y-m-d', false, $id ), Schema::day( get_post_meta( $id, '_pos_start', true ) ) );
		foreach ( $missions as $mission ) {
			$days[] = (string) get_post_time( 'Y-m-d', false, $mission );
		}
		foreach ( Impact::found( array( 'missions' => $missions ? $missions : array( 0 ) ) ) as $record ) {
			$days[] = Schema::day( get_post_meta( $record->ID, '_pos_date', true ) );
		}
		$days = array_filter( $days, static fn( string $day ): bool => '' !== Schema::day( $day ) );
		return array(
			'from' => $days ? min( $days ) : $today,
			'to'   => $today,
		);
	}

	/**
	 * Whose a program or a campaign is, by name, when the public sees that organization or partner.
	 *
	 * @param string $kind The kind of record.
	 * @param int    $id   The record.
	 */
	private static function owner( string $kind, int $id ): string {
		if ( 'program' === $kind ) {
			$owner = Programs::owner_post( $id );
			return $owner && Programs::owner_shown( $id ) ? $owner->post_title : '';
		}
		if ( 'campaign' === $kind ) {
			if ( Joint::is_copy( $id ) ) {
				return Joint::host( $id );
			}
			$team = Teams::get( Campaigns::team( $id ) );
			return $team && 'publish' === $team->post_status && Teams::active( $team->ID ) ? $team->post_title : '';
		}
		return '';
	}

	/**
	 * What partner sites reported, for a report made on the hub. It is never added to the figures the hub counted.
	 *
	 * The platform's report carries what every active partner site
	 * reported. A joint campaign's carries what the partner sites taking
	 * part reported for it. Each is the sites' own count, to the day they
	 * last sent it, and not the count of the report's period.
	 *
	 * @param string $kind The kind of record, or empty for the platform.
	 * @param int    $id   The record.
	 * @return array<string, mixed>|null Null when no partner site has a part in the scope.
	 */
	private static function partner_sites( string $kind, int $id ): ?array {
		if ( ! App::is_hub() ) {
			return null;
		}
		$totals = null;
		if ( '' === $kind ) {
			$totals = Remote::sites_totals();
			$sites  = $totals ? (int) $totals['partners'] : 0;
		} elseif ( 'campaign' === $kind && Joint::is_joint( $id ) ) {
			$totals = Joint::sum( Joint::lines( $id, true ), 'partner' );
			$sites  = (int) $totals['lines'];
		}
		if ( ! $totals || $sites <= 0 ) {
			return null;
		}
		return array(
			'sites'                => $sites,
			'spots_cleaned'        => (int) ( $totals['outputs']['spots'] ?? 0 ),
			'bags_measured'        => (int) ( $totals['outputs']['bags_measured'] ?? 0 ),
			'bags_self_reported'   => (int) ( $totals['outputs']['bags_self_reported'] ?? 0 ),
			'pounds_measured'      => (float) ( $totals['outputs']['pounds_measured'] ?? 0 ),
			'pounds_self_reported' => (float) ( $totals['outputs']['pounds_self_reported'] ?? 0 ),
			'hours_verified'       => (float) ( $totals['effort']['hours_verified'] ?? 0 ),
			'hours_unverified'     => (float) ( $totals['effort']['hours_unverified'] ?? 0 ),
			'people'               => (int) ( $totals['effort']['people'] ?? 0 ),
			'missions'             => (int) ( $totals['effort']['missions'] ?? 0 ),
			'note'                 => __( 'A partner site keeps its own records and verifies them with its own people. These are the figures the sites reported, to the day each last sent them. They are not in any other figure of this report.', 'packout-core' ),
		);
	}

	/**
	 * JSON body.
	 *
	 * @param array<string, mixed>|null $data Report.
	 */
	public static function json( ?array $data = null ): string {
		$data = is_array( $data ) ? $data : self::data( 30 );
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * CSV body.
	 *
	 * @param array<string, mixed>|null $data Report.
	 */
	public static function csv( ?array $data = null ): string {
		$data  = is_array( $data ) ? $data : self::data( 30 );
		$rows  = array(
			array( 'section', 'metric', 'value', 'basis' ),
			array( 'cover', 'title', (string) $data['title'], '' ),
			array( 'cover', 'subject', (string) ( $data['subject']['kind'] ?? 'platform' ), '' ),
			array( 'cover', 'owner', (string) ( $data['subject']['owner'] ?? '' ), '' ),
			array( 'cover', 'site', (string) $data['site'], '' ),
			array( 'cover', 'from', (string) $data['period']['from'], '' ),
			array( 'cover', 'to', (string) $data['period']['to'], '' ),
			array( 'cover', 'generated_at', (string) $data['generated_at'], '' ),
			array( 'summary', 'reading', (string) $data['summary'], '' ),
		);
		$basis = static function ( string $metric ): string {
			if ( false !== strpos( $metric, 'measured' ) ) {
				return 'measured';
			}
			if ( false !== strpos( $metric, 'self_reported' ) || false !== strpos( $metric, 'unverified' ) ) {
				return 'self_reported';
			}
			return false !== strpos( $metric, 'verified' ) ? 'verified' : '';
		};
		foreach ( array( 'outcomes', 'outputs', 'effort', 'from_report_to_cleanup' ) as $section ) {
			foreach ( (array) ( $data[ $section ] ?? array() ) as $metric => $value ) {
				$rows[] = array( $section, (string) $metric, null === $value ? '' : (string) $value, $basis( (string) $metric ) );
			}
		}
		foreach ( (array) ( $data['partner_sites'] ?? array() ) as $metric => $value ) {
			if ( 'note' !== $metric ) {
				$rows[] = array( 'reported_by_partner_sites', (string) $metric, (string) $value, $basis( (string) $metric ) );
			}
		}
		foreach ( (array) ( $data['who']['listed'] ?? array() ) as $name ) {
			$rows[] = array( 'who', 'team' === ( $data['subject']['kind'] ?? '' ) ? 'team' : 'listed_organization', (string) $name, '' );
		}
		$rows[] = array( 'who', 'unlisted', (string) ( $data['who']['unlisted'] ?? 0 ), '' );
		foreach ( (array) ( $data['where'] ?? array() ) as $name ) {
			$rows[] = array( 'where', 'place', (string) $name, '' );
		}
		foreach ( (array) ( $data['how_to_read'] ?? array() ) as $metric => $value ) {
			$rows[] = array( 'how_to_read', (string) $metric, (string) $value, '' );
		}
		return Export::csv( $rows );
	}

	/**
	 * HTML body, also used for PDF.
	 *
	 * @param array<string, mixed>|null $data Report.
	 */
	public static function html( ?array $data = null ): string {
		return self::render( is_array( $data ) ? $data : self::data( 30 ), false );
	}

	/**
	 * The one template, drawn for a browser or for the PDF renderer.
	 *
	 * @param array<string, mixed> $data Report.
	 * @param bool                 $pdf  Whether Dompdf will render the result.
	 */
	private static function render( array $data, bool $pdf ): string {
		ob_start();
		$packout_report = $data;
		$packout_view   = self::view( $data, $pdf );
		unset( $data, $pdf );
		include PACKOUT_CORE_DIR . '/templates/impact-report.php';
		return (string) ob_get_clean();
	}

	/**
	 * Presentation values for the template. None of these are report data, and none reach JSON or CSV.
	 *
	 * @param array<string, mixed> $data Report.
	 * @param bool                 $pdf  Whether Dompdf will render the result.
	 * @return array<string, mixed>
	 */
	private static function view( array $data, bool $pdf ): array {
		$format = (string) get_option( 'date_format' );
		$format = '' !== $format ? $format : 'F j, Y';
		$zone   = new \DateTimeZone( 'UTC' );
		$day    = static function ( string $ymd ) use ( $format, $zone ): string {
			$time = strtotime( $ymd . ' 12:00:00 UTC' );
			return false === $time ? $ymd : (string) wp_date( $format, $time, $zone );
		};
		$site   = (string) wp_parse_url( (string) ( $data['site'] ?? '' ), PHP_URL_HOST );
		$port   = (int) wp_parse_url( (string) ( $data['site'] ?? '' ), PHP_URL_PORT );
		return array(
			'pdf'    => $pdf,
			'lang'   => (string) get_bloginfo( 'language' ),
			'name'   => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'site'   => $site . ( $port > 0 ? ':' . $port : '' ),
			'from'   => $day( (string) ( $data['period']['from'] ?? '' ) ),
			'to'     => $day( (string) ( $data['period']['to'] ?? '' ) ),
			'logo'   => self::logo_data_uri(),
			'colors' => self::colors(),
		);
	}

	/**
	 * Report colors as plain hex, mixed here from the site palette.
	 *
	 * These are the Overview's colors. The Overview mixes them in CSS with
	 * color-mix(); the PDF renderer has no color-mix(), so the report gets
	 * the same mixes worked out ahead of time.
	 *
	 * @return array<string, string>
	 */
	private static function colors(): array {
		$palette = Admin::colors();
		$ink     = self::rgb( (string) $palette[8], array( 47, 50, 50 ) );
		$surface = self::rgb( (string) $palette[4], array( 255, 255, 255 ) );
		$accent  = self::rgb( (string) $palette[2], array( 187, 94, 40 ) );
		return array(
			'ink'         => self::mix( $ink, $surface, 100 ),
			'surface'     => self::mix( $surface, $ink, 100 ),
			'quiet'       => self::mix( $ink, $surface, 68 ),
			'line'        => self::mix( $ink, $surface, 13 ),
			'line-strong' => self::mix( $ink, $surface, 30 ),
			'tint'        => self::mix( self::rgb( (string) $palette[5], array( 237, 234, 229 ) ), $surface, 55 ),
			'accent'      => self::mix( $accent, $surface, 100 ),
			'accent-ink'  => self::mix( self::rgb( (string) $palette[3], array( 134, 61, 20 ) ), $surface, 100 ),
			'good'        => self::mix( self::rgb( (string) $palette[0], array( 65, 88, 64 ) ), $surface, 100 ),
			'cover-quiet' => self::mix( $surface, $ink, 72 ),
		);
	}

	/**
	 * A CSS hex color as red, green, and blue.
	 *
	 * @param string          $hex      Three or six hex digits, with or without the hash.
	 * @param array<int, int> $fallback Used when the value is not a hex color, such as a palette entry given as rgb().
	 * @return array<int, int>
	 */
	private static function rgb( string $hex, array $fallback ): array {
		$hex = ltrim( trim( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return $fallback;
		}
		return array( (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ) );
	}

	/**
	 * Two colors mixed, as hex.
	 *
	 * @param array<int, int> $color   The color.
	 * @param array<int, int> $onto    What it is mixed with.
	 * @param int             $percent How much of the first color, 0 to 100.
	 */
	private static function mix( array $color, array $onto, int $percent ): string {
		$share = max( 0, min( 100, $percent ) ) / 100;
		$out   = '#';
		foreach ( array( 0, 1, 2 ) as $i ) {
			$out .= sprintf( '%02x', (int) round( $color[ $i ] * $share + $onto[ $i ] * ( 1 - $share ) ) );
		}
		return $out;
	}

	/**
	 * PDF bytes.
	 *
	 * @param array<string, mixed>|null $data Report.
	 * @return string|\WP_Error
	 */
	public static function pdf( ?array $data = null ) {
		$autoload = dirname( PACKOUT_CORE_DIR ) . '/dompdf/packages/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			return new \WP_Error( 'packout_pdf', __( 'The PDF library is not installed.', 'packout-core' ), array( 'status' => 500 ) );
		}
		require_once $autoload;
		$root    = trailingslashit( dirname( PACKOUT_CORE_DIR, 2 ) );
		$options = new \Dompdf\Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isPhpEnabled', false );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		// The template's screen rules are for the HTML file in a browser. A PDF is a printed page.
		$options->setDefaultMediaType( 'print' );
		$options->setChroot( $root );
		// Dompdf saves the font measurements it works out. Keep them out of the plugin directory.
		$cache = trailingslashit( get_temp_dir() ) . 'packout-spot-pdf';
		if ( wp_mkdir_p( $cache ) && wp_is_writable( $cache ) ) {
			$options->setFontCache( $cache );
		}
		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( self::render( is_array( $data ) ? $data : self::data( 30 ), true ) );
		$dompdf->setPaper( 'letter' );
		$dompdf->render();
		return (string) $dompdf->output();
	}

	/**
	 * A link that downloads the report of one record as a PDF, for a drawer or a workspace view.
	 *
	 * @param string $kind  `team`, `partner`, `program`, or `campaign`.
	 * @param int    $id    The record.
	 * @param string $label What the link says.
	 * @param int    $days  Window. 0 for the whole of a program or a campaign.
	 * @return string Escaped HTML, or nothing for a person who may not download it.
	 */
	public static function link( string $kind, int $id, string $label, int $days = 365 ): string {
		if ( ! self::may( array( $kind => $id ) ) ) {
			return '';
		}
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'packout_export_overview',
					'kind'   => $kind,
					'id'     => $id,
					'days'   => $days <= 0 && in_array( $kind, array( 'program', 'campaign' ), true ) ? 0 : Overview::window( $days ),
					'format' => 'pdf',
				),
				admin_url( 'admin-post.php' )
			),
			'packout_export_overview'
		);
		return '<a class="pos-ws__button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Whether a person may download the report of a scope.
	 *
	 * The whole platform's is for a person who may export. A record's is for
	 * the people who may open its overview, or change it when it has none.
	 *
	 * @param array<string, mixed> $scope   team, partner, program, or campaign.
	 * @param int                  $user_id User ID, or -1 for the current user.
	 */
	public static function may( array $scope, int $user_id = -1 ): bool {
		$user_id = $user_id < 0 ? get_current_user_id() : $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		// A report is made from the records, and the records of a partner's site are on that site.
		if ( Remote::is_site( (int) ( $scope['partner'] ?? 0 ) ) || Remote::is( (int) ( $scope['team'] ?? 0 ) ) || Remote::is( (int) ( $scope['program'] ?? 0 ) ) ) {
			return false;
		}
		if ( ! empty( $scope['partner'] ) ) {
			return Overview::can_open( 'partner', (int) $scope['partner'], $user_id );
		}
		if ( ! empty( $scope['team'] ) ) {
			return Overview::can_open( 'team', (int) $scope['team'], $user_id );
		}
		if ( ! empty( $scope['program'] ) ) {
			return Capabilities::can( Capabilities::MODERATE, $user_id ) || Programs::can_change( (int) $scope['program'], $user_id );
		}
		if ( ! empty( $scope['campaign'] ) ) {
			// A campaign kept on a partner's site has its records there. A joint one has the records of the organizations here that take part.
			if ( Remote::is( (int) $scope['campaign'] ) && ! Joint::is_joint( (int) $scope['campaign'] ) ) {
				return false;
			}
			return Capabilities::can( Capabilities::MODERATE, $user_id ) || Campaigns::can_change( (int) $scope['campaign'], $user_id );
		}
		return Export::can( $user_id );
	}

	/**
	 * Send the chosen format.
	 */
	public static function download(): void {
		// The Overview posts a form. A record's drawer and its workspace view use a link.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Checked below, after the scope is known.
		$kind   = isset( $_REQUEST['kind'] ) ? sanitize_key( wp_unslash( $_REQUEST['kind'] ) ) : '';
		$id     = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;
		$days   = isset( $_REQUEST['days'] ) ? (int) $_REQUEST['days'] : 30;
		$format = isset( $_REQUEST['format'] ) ? sanitize_key( wp_unslash( $_REQUEST['format'] ) ) : 'pdf';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$scope   = '' !== $kind ? array( $kind => $id ) : array();
		$subject = self::subject( $scope );
		// A scope that names no record is refused. It is never answered with the whole platform's report.
		Admin::require( ( '' === $kind || '' !== $subject['kind'] ) && self::may( $scope ) );
		check_admin_referer( 'packout_export_overview' );
		if ( ! isset( self::formats()[ $format ] ) ) {
			$format = 'pdf';
		}
		$data = self::data( $days, $scope );
		$from = (string) $data['period']['from'];
		$to   = (string) $data['period']['to'];
		$stem = 'packout-impact-report-' . ( '' !== $subject['kind'] ? $subject['kind'] . '-' . sanitize_file_name( $subject['slug'] ) . '-' : '' ) . $from . '-' . $to;
		if ( 'json' === $format ) {
			self::send( $stem . '.json', 'application/json; charset=utf-8', self::json( $data ) );
		}
		if ( 'csv' === $format ) {
			self::send( $stem . '.csv', 'text/csv; charset=utf-8', self::csv( $data ) );
		}
		if ( 'html' === $format ) {
			self::send( $stem . '.html', 'text/html; charset=utf-8', self::html( $data ) );
		}
		$body = self::pdf( $data );
		if ( is_wp_error( $body ) ) {
			wp_die( esc_html( $body->get_error_message() ), 500 );
		}
		self::send( $stem . '.pdf', 'application/pdf', $body );
	}

	/**
	 * Listed organizations with a cleanup in the period.
	 *
	 * @param string               $from Y-m-d.
	 * @param string               $to   Y-m-d.
	 * @param array<string, mixed> $args Totals filters.
	 * @return array{listed: array<int, string>, unlisted: int}
	 */
	private static function who( string $from, string $to, array $args ): array {
		$filters  = array_merge(
			$args,
			array(
				'kind' => Impact::CLEANUP,
				'from' => $from,
				'to'   => $to,
			)
		);
		$listed   = array();
		$unlisted = 0;
		$seen     = array();
		foreach ( Impact::found( $filters ) as $post ) {
			$team = (int) Impact::field( $post->ID, 'team', '0' );
			if ( $team <= 0 || isset( $seen[ $team ] ) ) {
				continue;
			}
			$seen[ $team ] = true;
			$org           = Teams::get( $team );
			if ( ! $org ) {
				continue;
			}
			if ( 'publish' === $org->post_status ) {
				$listed[] = $org->post_title;
			} else {
				++$unlisted;
			}
		}
		natcasesort( $listed );
		return array(
			'listed'   => array_values( $listed ),
			'unlisted' => $unlisted,
		);
	}

	/**
	 * The teams of one organization that were on a mission with a cleanup in the period. It names no person.
	 *
	 * @param int    $team Organization ID.
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array{listed: array<int, string>, unlisted: int}
	 */
	private static function who_in( int $team, string $from, string $to ): array {
		$ids = array();
		foreach ( Impact::found(
			array(
				'team' => $team,
				'kind' => Impact::CLEANUP,
				'from' => $from,
				'to'   => $to,
			)
		) as $post ) {
			$mission = (int) Impact::field( $post->ID, 'mission', '0' );
			foreach ( $mission > 0 ? Missions::subteams( $mission ) : array() as $subteam ) {
				$ids[ (int) $subteam ] = true;
			}
		}
		$names = Subteams::names( $team, array_keys( $ids ) );
		natcasesort( $names );
		return array(
			'listed'   => array_values( $names ),
			'unlisted' => 0,
		);
	}

	/**
	 * Listed Places with a cleanup in the period.
	 *
	 * @param string               $from Y-m-d.
	 * @param string               $to   Y-m-d.
	 * @param array<string, mixed> $args Totals filters.
	 * @return array<int, string>
	 */
	private static function where( string $from, string $to, array $args ): array {
		$filters = array_merge(
			$args,
			array(
				'kind' => Impact::CLEANUP,
				'from' => $from,
				'to'   => $to,
			)
		);
		$names   = array();
		foreach ( Impact::found( $filters ) as $post ) {
			$spot  = (int) Impact::field( $post->ID, 'spot', '0' );
			$place = $spot > 0 ? Places::of_spot( $spot ) : null;
			if ( ! $place || 'publish' !== $place->post_status || isset( $names[ $place->ID ] ) ) {
				continue;
			}
			$names[ $place->ID ] = $place->post_title;
		}
		natcasesort( $names );
		return array_values( $names );
	}

	/**
	 * The mark on the cover as a data URI: the site icon, or the plugin's own icon when the site has none.
	 */
	private static function logo_data_uri(): string {
		$files = array( PACKOUT_CORE_DIR . '/assets/img/icon-192.png' );
		$id    = (int) get_option( 'site_icon' );
		if ( $id > 0 ) {
			array_unshift( $files, (string) get_attached_file( $id ) );
		}
		foreach ( $files as $file ) {
			if ( '' === $file || ! is_readable( $file ) ) {
				continue;
			}
			$mime = wp_check_filetype( $file )['type'] ?? '';
			if ( ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/gif' ), true ) ) {
				continue;
			}
			$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			if ( is_string( $bytes ) && '' !== $bytes ) {
				return 'data:' . $mime . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- An image written into the file as a data address.
			}
		}
		return '';
	}

	/**
	 * Send a file and stop.
	 *
	 * @param string $name Filename.
	 * @param string $type MIME type.
	 * @param string $body Contents.
	 */
	private static function send( string $name, string $type, string $body ): void {
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- File download.
		exit;
	}
}
