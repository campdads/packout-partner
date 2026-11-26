<?php
/**
 * The calendar block.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

use PackOut\Core\Calendar as Rules;
use PackOut\Core\Schedule as Times;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws a calendar in one of its six forms.
 *
 * One render path serves the block, its six variations, and the route that
 * redraws it. The server draws the whole view, so the page is complete
 * without script. Day, Week, Month, and Agenda can be moved and switched;
 * each control is a link that works on its own, and the view script only
 * saves the page load. Upcoming and Past are lists with no controls.
 */
class Calendar_View {

	public const BLOCK = 'packout/calendar';

	/**
	 * The address parts that carry a view: the form, the day, and the two filters a visitor may set.
	 */
	public const QUERY = array( 'cal-view', 'cal-date', 'cal-cat', 'cal-org' );

	/**
	 * How many events a day of the month shows before "N more".
	 */
	private const DAY_LIMIT = 3;

	/**
	 * The block's attributes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function attributes(): array {
		$attributes = array(
			'view'      => array(
				'type'    => 'string',
				'default' => 'month',
				'enum'    => Rules::VIEWS,
			),
			'switcher'  => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'category'  => array(
				'type'    => 'string',
				'default' => '',
			),
			'show'      => array(
				'type'    => 'array',
				'default' => Rules::KINDS,
				'items'   => array(
					'type' => 'string',
					'enum' => Rules::KINDS,
				),
			),
			'count'     => array(
				'type'    => 'number',
				'default' => Rules::COUNT,
			),
			'feed'      => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'weekStart' => array(
				'type'    => 'string',
				'default' => 'site',
				'enum'    => array( 'site', 'sunday', 'monday' ),
			),
		);
		foreach ( array( 'partner', 'organization', 'team', 'program', 'campaign' ) as $name ) {
			$attributes[ $name ] = array(
				'type'    => 'number',
				'default' => 0,
			);
		}
		return $attributes;
	}

	/**
	 * Register the block and its view script.
	 */
	public static function register(): void {
		wp_register_script_module( 'packout-calendar', plugins_url( 'assets/js/calendar.js', PACKOUT_CORE_FILE ), array( '@wordpress/interactivity' ), PACKOUT_CORE_VERSION );
		wp_register_style( 'packout-calendar', plugins_url( 'assets/css/calendar.css', PACKOUT_CORE_FILE ), array( Ui::palette_style() ), PACKOUT_CORE_VERSION );
		// The stylesheet is written against the WordPress design tokens. The brand is the palette's dark accent
		// and the ink is the palette's, as in the workspace, so the calendar is given those in the tokens' names.
		wp_add_inline_style(
			'packout-calendar',
			'.pos-cal{--wpds-color-background-interactive-brand-strong:var(--packout-accent-dark,#863d14);--wpds-color-foreground-interactive-brand-strong:#fff;--wpds-color-background-surface-brand:color-mix(in srgb,var(--packout-accent-dark,#863d14) 9%,#fff);--wpds-color-stroke-focus:var(--packout-accent-dark,#863d14);--wpds-color-foreground-content-neutral:var(--packout-ink,#2f3232);}'
		);
		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'Calendar', 'packout-core' ),
				'description'     => __( 'Missions as events, and campaigns and programs as spans, by day, week, or month, as an agenda, or as a list of what is coming or what was done.', 'packout-core' ),
				'category'        => 'widgets',
				'icon'            => 'calendar-alt',
				'editor_script'   => 'packout-blocks-editor',
				'style'           => 'packout-calendar',
				'supports'        => array(
					'html'          => false,
					'align'         => array( 'wide', 'full' ),
					'interactivity' => true,
				),
				'attributes'      => self::attributes(),
				'render_callback' => array( self::class, 'render' ),
			)
		);
	}

	/**
	 * What a block or a request asks a calendar to be, made safe.
	 *
	 * @param array<string, mixed> $asked Block attributes, or the same under the route's names.
	 * @return array<string, mixed>
	 */
	private static function settings( array $asked ): array {
		$view = Rules::view( $asked['view'] ?? 'month' );
		$date = Times::day( $asked['date'] ?? '' );
		return Calendar::args( $asked ) + array(
			'view'      => $view,
			'date'      => '' !== $date ? $date : Calendar::today(),
			'switcher'  => in_array( $view, Rules::SWITCHABLE, true ) && ( ! array_key_exists( 'switcher', $asked ) || ! empty( $asked['switcher'] ) ),
			'count'     => Rules::count( $asked['count'] ?? 0 ),
			'feed'      => ! array_key_exists( 'feed', $asked ) || ! empty( $asked['feed'] ),
			'weekStart' => in_array( $asked['weekStart'] ?? '', array( 'sunday', 'monday' ), true ) ? (string) $asked['weekStart'] : 'site',
			'base'      => (string) ( $asked['base'] ?? '' ),
			// Which of the two filters a visitor may set the block itself already fixes.
			'fixed'     => array(
				'category'     => ! empty( $asked['fixed']['category'] ),
				'organization' => ! empty( $asked['fixed']['organization'] ),
			),
		);
	}

	/**
	 * The block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		return self::build( $attributes, true );
	}

	/**
	 * A calendar inside another view, such as a workspace. The same as the block, without a block's wrapper.
	 *
	 * @param array<string, mixed> $attributes What the block would be given.
	 */
	public static function embedded( array $attributes ): string {
		wp_enqueue_style( 'packout-calendar' );
		return self::build( $attributes, false );
	}

	/**
	 * A calendar, for the person looking.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $block      Whether a block is being rendered, so the wrapper is the block's.
	 */
	private static function build( array $attributes, bool $block ): string {
		$wrap           = static fn( string $names ): string => $block ? Ui::wrapper( $names ) : 'class="' . esc_attr( $names ) . '"';
		$asked          = $attributes;
		$asked['base']  = remove_query_arg( self::QUERY, Accounts::current_url() );
		$limited        = Calendar::args( $attributes );
		$asked['fixed'] = array(
			'category'     => '' !== $limited['category'],
			'organization' => $limited['organization'] > 0,
		);
		$view           = Rules::view( $attributes['view'] ?? 'month' );
		$moves          = in_array( $view, Rules::SWITCHABLE, true );
		$switches       = $moves && ( ! array_key_exists( 'switcher', $attributes ) || ! empty( $attributes['switcher'] ) );

		// A view the address names. Only a calendar that moves reads it, and only one with a switcher changes its form.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading which days to show changes nothing.
		if ( $moves ) {
			$asked['date'] = isset( $_GET['cal-date'] ) ? sanitize_text_field( wp_unslash( $_GET['cal-date'] ) ) : '';
			$named         = isset( $_GET['cal-view'] ) ? sanitize_key( wp_unslash( $_GET['cal-view'] ) ) : '';
			if ( $switches && in_array( $named, Rules::SWITCHABLE, true ) ) {
				$asked['view'] = $named;
			}
		}
		if ( $switches ) {
			if ( ! $asked['fixed']['category'] && isset( $_GET['cal-cat'] ) ) {
				$asked['category'] = sanitize_key( wp_unslash( $_GET['cal-cat'] ) );
			}
			if ( ! $asked['fixed']['organization'] && isset( $_GET['cal-org'] ) ) {
				$asked['organization'] = (int) $_GET['cal-org'];
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$user     = get_current_user_id();
		$settings = self::settings( $asked );
		$class    = 'pos-cal pos-cal--' . $settings['view'];

		if ( ! $moves ) {
			return '<div ' . $wrap( $class ) . ' ' . self::still() . '>' . self::cards( $settings, self::found( $settings, $user ) ) . '</div>';
		}

		wp_enqueue_script_module( 'packout-calendar' );
		// What the view script sends back with each request, so the route draws the same calendar.
		$params = array(
			'count'      => $settings['count'],
			'week_start' => $settings['weekStart'],
			'switcher'   => $settings['switcher'] ? 1 : 0,
			'feed'       => $settings['feed'] ? 1 : 0,
			'show'       => implode( ',', $settings['show'] ),
			'fixed'      => implode( ',', array_keys( array_filter( $settings['fixed'] ) ) ),
		);
		foreach ( array( 'partner', 'organization', 'team', 'program', 'campaign', 'category' ) as $name ) {
			if ( ! empty( $limited[ $name ] ) ) {
				$params[ $name ] = $limited[ $name ];
			}
		}
		$context = array(
			'endpoint' => rest_url( Rest::NAMESPACE . '/calendar' ),
			'nonce'    => $user > 0 ? wp_create_nonce( 'wp_rest' ) : '',
			'base'     => $settings['base'],
			'view'     => $settings['view'],
			'date'     => $settings['date'],
			'category' => $settings['fixed']['category'] ? '' : $settings['category'],
			'org'      => $settings['fixed']['organization'] ? 0 : $settings['organization'],
			'switches' => $settings['switcher'],
			'fixed'    => array_keys( array_filter( $settings['fixed'] ) ),
			// What Back returns to when the address names no view: the form the block was given, today.
			'home'     => array(
				'view' => $view,
				'date' => Calendar::today(),
			),
			'params'   => $params,
			/* translators: %s: a time of day, such as 9:00 am. */
			'local'    => __( '%s your time', 'packout-core' ),
		);

		$html  = '<div ' . $wrap( $class ) . ' data-wp-interactive="packout/calendar" ' . wp_interactivity_data_wp_context( $context ) . ' data-wp-init="callbacks.start" data-wp-on--click="actions.click" data-wp-on--keydown="actions.key" data-wp-on--change="actions.change">';
		$html .= '<p class="pos-cal__status pos-cal__sr" role="status"></p>';
		$html .= '<div class="pos-cal__region">' . self::drawn( $settings, $user ) . '</div>';
		return $html . '</div>';
	}

	/**
	 * The attributes of a calendar that does not move. Its script only adds the reader's own time to an event in another zone.
	 */
	private static function still(): string {
		wp_enqueue_script_module( 'packout-calendar' );
		return 'data-wp-interactive="packout/calendar" ' . wp_interactivity_data_wp_context(
			array(
				/* translators: %s: a time of day, such as 9:00 am. */
				'local' => __( '%s your time', 'packout-core' ),
			)
		) . ' data-wp-init="callbacks.start"';
	}

	/**
	 * A calendar that moves, drawn for a request: what the route puts in place of what the block showed.
	 *
	 * @param array<string, mixed> $asked What the request asks for, under the route's names.
	 * @param int                  $user  The person asking.
	 */
	public static function region( array $asked, int $user ): string {
		// The request says which filters the block fixes, so the redrawn calendar offers the same ones.
		$fixed          = array_map( 'trim', explode( ',', (string) ( $asked['fixed'] ?? '' ) ) );
		$asked['fixed'] = array(
			'category'     => in_array( 'category', $fixed, true ),
			'organization' => in_array( 'organization', $fixed, true ),
		);
		return self::drawn( self::settings( $asked ), $user );
	}

	/* ---------- Words and addresses ---------- */

	/**
	 * A day in words.
	 *
	 * @param string $day    `Y-m-d`.
	 * @param string $format A date format.
	 */
	private static function day_text( string $day, string $format ): string {
		return (string) wp_date( $format, (int) strtotime( $day . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * The address of this calendar showing another day, or in another form.
	 *
	 * @param array<string, mixed> $settings From settings().
	 * @param string               $view     The form.
	 * @param string               $date     The day.
	 */
	private static function url( array $settings, string $view, string $date ): string {
		$query = array(
			'cal-view' => $view,
			'cal-date' => $date,
		);
		if ( ! $settings['fixed']['category'] && '' !== $settings['category'] ) {
			$query['cal-cat'] = $settings['category'];
		}
		if ( ! $settings['fixed']['organization'] && $settings['organization'] > 0 ) {
			$query['cal-org'] = $settings['organization'];
		}
		return add_query_arg( $query, '' !== $settings['base'] ? $settings['base'] : home_url( '/' ) );
	}

	/**
	 * A link that moves the calendar. The view script follows it without loading the page.
	 *
	 * @param array<string, mixed> $settings From settings().
	 * @param string               $view     The form to show.
	 * @param string               $date     The day to show.
	 * @param string               $label    The words of the link, already escaped.
	 * @param string               $attrs    Other attributes, already escaped.
	 */
	private static function go( array $settings, string $view, string $date, string $label, string $attrs = '' ): string {
		return '<a href="' . esc_url( self::url( $settings, $view, $date ) ) . '" data-cal-go data-cal-view="' . esc_attr( $view ) . '" data-cal-date="' . esc_attr( $date ) . '"' . ( '' !== $attrs ? ' ' . $attrs : '' ) . '>' . $label . '</a>';
	}

	/**
	 * A moment of an event, on the event's own clock.
	 *
	 * @param array<string, mixed> $event From Calendar::events().
	 * @param string               $which `start_at` or `end_at`.
	 */
	private static function at( array $event, string $which ): \DateTimeImmutable {
		return ( new \DateTimeImmutable( '@' . $event[ $which ] ) )->setTimezone( new \DateTimeZone( $event['zone'] ) );
	}

	/**
	 * When an event is, in words, on its own clock with its zone named.
	 *
	 * @param array<string, mixed> $event From Calendar::events().
	 * @param bool                 $days  Name the day or days as well as the time.
	 */
	private static function when( array $event, bool $days = false ): string {
		$start = self::at( $event, 'start_at' );
		$span  = $days ? Schema::span( $event['first_day'], $event['last_day'] ) : '';
		if ( $event['all_day'] ) {
			return $days ? esc_html( $span ) : esc_html__( 'All day', 'packout-core' );
		}
		$zone  = Times::abbreviation( $start );
		$words = Schedule::time( $event['start_at'], $event['zone'] );
		if ( ! $event['open_end'] && $event['last_day'] === $event['first_day'] ) {
			$words .= ' – ' . Schedule::time( $event['end_at'], $event['zone'] );
		}
		// The script adds the time on the reader's own clock when it is not this one.
		$time = '<time class="pos-cal__time" datetime="' . esc_attr( $start->format( 'c' ) ) . '" data-cal-offset="' . esc_attr( (string) $start->getOffset() ) . '">' . esc_html( $words . ' ' . $zone ) . '</time>';
		return ( $days ? esc_html( $span ) . ', ' : '' ) . $time;
	}

	/**
	 * The words that mark what an event is and how it stands.
	 *
	 * A status is a word and a shape as well as a color, so it does not
	 * depend on color to be read.
	 *
	 * @param array<string, mixed> $event From Calendar::events().
	 */
	private static function flags( array $event ): string {
		$flags = '';
		if ( 'campaign' === $event['kind'] ) {
			$flags .= '<span class="pos-cal__flag pos-cal__flag--kind">' . esc_html__( 'Campaign', 'packout-core' ) . '</span>';
		} elseif ( 'program' === $event['kind'] ) {
			$flags .= '<span class="pos-cal__flag pos-cal__flag--kind">' . esc_html__( 'Program', 'packout-core' ) . '</span>';
		} elseif ( in_array( $event['status'], array( 'called_off', 'completed', 'in_progress' ), true ) ) {
			$flags .= '<span class="pos-cal__flag pos-cal__flag--' . esc_attr( str_replace( '_', '-', $event['status'] ) ) . '">' . esc_html( $event['status_label'] ) . '</span>';
		}
		if ( $event['private'] ) {
			$flags .= '<span class="pos-cal__flag pos-cal__flag--private">' . esc_html__( 'Not public', 'packout-core' ) . '</span>';
		}
		return $flags;
	}

	/**
	 * The classes of an event.
	 *
	 * @param array<string, mixed> $event From Calendar::events().
	 * @param string               $base  The element's own class.
	 */
	private static function classes( array $event, string $base ): string {
		return $base . ' pos-cal__is-' . $event['kind'] . ( '' !== $event['status'] ? ' pos-cal__is-' . str_replace( '_', '-', $event['status'] ) : '' );
	}

	/**
	 * The events that have any part on a day.
	 *
	 * @param array<int, array<string, mixed>> $events From Calendar::events().
	 * @param string                           $day    `Y-m-d`.
	 * @return array<int, array<string, mixed>>
	 */
	private static function on( array $events, string $day ): array {
		return array_values( array_filter( $events, static fn( array $event ): bool => $event['first_day'] <= $day && $event['last_day'] >= $day ) );
	}

	/**
	 * An event as a compact link: its time, its title, and its flags.
	 *
	 * @param array<string, mixed> $event From Calendar::events().
	 * @param bool                 $timed Show the start time before the title.
	 */
	private static function chip( array $event, bool $timed = true ): string {
		$time = $timed && ! $event['all_day'] ? '<span class="pos-cal__chip-time">' . esc_html( Schedule::time( $event['start_at'], $event['zone'] ) ) . '</span> ' : '';
		return '<a class="pos-cal__chip" href="' . esc_url( $event['url'] ) . '">' . $time . '<span class="pos-cal__chip-title">' . esc_html( $event['title'] ) . '</span>' . self::flags( $event ) . '</a>';
	}

	/* ---------- A calendar that moves ---------- */

	/**
	 * Day, Week, Month, or Agenda, with its controls.
	 *
	 * @param array<string, mixed> $settings From settings().
	 * @param int                  $user     The person asking.
	 */
	private static function drawn( array $settings, int $user ): string {
		$view  = in_array( $settings['view'], Rules::SWITCHABLE, true ) ? $settings['view'] : 'month';
		$date  = $settings['date'];
		$start = Rules::week_start( $settings['weekStart'], (int) get_option( 'start_of_week', 0 ) );
		$range = Rules::range( $view, $date, $start );
		$today = Calendar::today();

		$events = Calendar::events(
			array(
				'from' => $range['from'],
				'to'   => $range['to'],
			) + $settings,
			$user
		);

		$long = (string) get_option( 'date_format', 'F j, Y' );
		switch ( $view ) {
			case 'day':
				/* translators: A day with its weekday, as a PHP date format. */
				$title = self::day_text( $date, __( 'l, F j, Y', 'packout-core' ) );
				$names = array( __( 'Previous day', 'packout-core' ), __( 'Next day', 'packout-core' ) );
				$body  = self::days( $settings, $events, array( $date ), $today );
				break;
			case 'week':
				/* translators: %s: the first day of a week. */
				$title = sprintf( __( 'Week of %s', 'packout-core' ), self::day_text( $range['from'], $long ) );
				$names = array( __( 'Previous week', 'packout-core' ), __( 'Next week', 'packout-core' ) );
				$body  = '<div class="pos-cal__wide">' . self::days( $settings, $events, Rules::days( $range['from'], $range['to'] ), $today ) . '</div>';
				$body .= '<div class="pos-cal__narrow">' . self::agenda( $settings, $events, $range['from'], $range['to'], $today ) . '</div>';
				break;
			case 'agenda':
				/* translators: %s: a day. */
				$title = sprintf( __( 'From %s', 'packout-core' ), self::day_text( $date, $long ) );
				$names = array( __( 'Earlier', 'packout-core' ), __( 'Later', 'packout-core' ) );
				$body  = '';
				break;
			default:
				/* translators: A month and its year, as a PHP date format. */
				$title = self::day_text( $date, __( 'F Y', 'packout-core' ) );
				$names = array( __( 'Previous month', 'packout-core' ), __( 'Next month', 'packout-core' ) );
				$body  = '<div class="pos-cal__wide">' . self::month( $settings, $events, $range, $date, $today, $title ) . '</div>';
				$body .= '<div class="pos-cal__narrow">' . self::agenda( $settings, $events, $range['from'], $range['to'], $today ) . '</div>';
		}//end switch

		$before = Rules::step( $view, $date, -1 );
		$after  = Rules::step( $view, $date, 1 );
		if ( 'agenda' === $view ) {
			// An agenda lists whole days until it has as many events as asked, and pages by what it showed.
			list( $shown, $after ) = self::page( $events, $date, $range['to'], $settings['count'] );
			$before                = self::page_before( $settings, $user, $date );
			$body                  = self::agenda( $settings, $shown, $date, $range['to'], $today );
		}

		$html  = '<div class="pos-cal__bar"><div class="pos-cal__nav">';
		$html .= self::go( $settings, $view, $before, '<span aria-hidden="true">‹</span><span class="pos-cal__sr">' . esc_html( $names[0] ) . '</span>', 'class="pos-cal__button pos-cal__button--step" rel="prev"' );
		$html .= self::go( $settings, $view, $today, esc_html__( 'Today', 'packout-core' ), 'class="pos-cal__button"' );
		$html .= self::go( $settings, $view, $after, '<span aria-hidden="true">›</span><span class="pos-cal__sr">' . esc_html( $names[1] ) . '</span>', 'class="pos-cal__button pos-cal__button--step" rel="next"' );
		$html .= '</div><h3 class="pos-cal__title" tabindex="-1">' . esc_html( $title ) . '</h3>';
		if ( $settings['switcher'] ) {
			$labels = array(
				'day'    => __( 'Day', 'packout-core' ),
				'week'   => __( 'Week', 'packout-core' ),
				'month'  => __( 'Month', 'packout-core' ),
				'agenda' => __( 'Agenda', 'packout-core' ),
			);
			$html  .= '<ul class="pos-cal__views" aria-label="' . esc_attr__( 'View', 'packout-core' ) . '">';
			foreach ( $labels as $name => $label ) {
				$html .= '<li>' . self::go( $settings, $name, $date, esc_html( $label ), 'class="pos-cal__button"' . ( $name === $view ? ' aria-current="true"' : '' ) ) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '</div>';
		if ( $settings['switcher'] ) {
			$html .= self::filters( $settings, $view, $date );
		}
		$html .= '<div class="pos-cal__body" data-cal-title="' . esc_attr( $title ) . '" data-cal-shown-view="' . esc_attr( $view ) . '" data-cal-shown-date="' . esc_attr( $date ) . '">' . $body . '</div>';
		if ( $settings['feed'] ) {
			$html .= self::feed( $settings );
		}
		return $html;
	}

	/**
	 * The two filters a visitor may set: a category, and an organization.
	 *
	 * Each is offered only when the block itself is not already limited by
	 * it. The form works without script; the script sends it on a change.
	 *
	 * @param array<string, mixed> $settings From settings().
	 * @param string               $view     The form shown.
	 * @param string               $date     The day shown.
	 */
	private static function filters( array $settings, string $view, string $date ): string {
		$fields = '';
		if ( ! $settings['fixed']['category'] && in_array( 'missions', $settings['show'], true ) ) {
			$id      = wp_unique_id( 'pos-cal-' );
			$fields .= '<label class="pos-cal__filter" for="' . esc_attr( $id ) . '"><span>' . esc_html__( 'Category', 'packout-core' ) . '</span><select id="' . esc_attr( $id ) . '" name="cal-cat"><option value="">' . esc_html__( 'Every category', 'packout-core' ) . '</option>';
			foreach ( Schema::categories() as $slug => $label ) {
				$fields .= '<option value="' . esc_attr( $slug ) . '"' . selected( $slug, $settings['category'], false ) . '>' . esc_html( $label ) . '</option>';
			}
			$fields .= '</select></label>';
		}
		if ( ! $settings['fixed']['organization'] && 0 === $settings['campaign'] ) {
			// Listed organizations only, and only the ones the block's own limits leave.
			$teams = array();
			foreach ( Teams::listed() as $team ) {
				if ( ( 0 === $settings['partner'] || Partners::of_team( $team['id'] ) === $settings['partner'] ) && ( 0 === $settings['program'] || in_array( $team['id'], Programs::taking_part( $settings['program'] ), true ) ) ) {
					$teams[ $team['id'] ] = $team['name'];
				}
			}
			if ( count( $teams ) > 1 ) {
				asort( $teams );
				$id      = wp_unique_id( 'pos-cal-' );
				$fields .= '<label class="pos-cal__filter" for="' . esc_attr( $id ) . '"><span>' . esc_html__( 'Organization', 'packout-core' ) . '</span><select id="' . esc_attr( $id ) . '" name="cal-org"><option value="0">' . esc_html__( 'Every organization', 'packout-core' ) . '</option>';
				foreach ( $teams as $team => $name ) {
					$fields .= '<option value="' . esc_attr( (string) $team ) . '"' . selected( $team, $settings['organization'], false ) . '>' . esc_html( $name ) . '</option>';
				}
				$fields .= '</select></label>';
			}
		}
		if ( '' === $fields ) {
			return '';
		}
		$base  = '' !== $settings['base'] ? $settings['base'] : home_url( '/' );
		$html  = '<form class="pos-cal__filters" method="get" action="' . esc_url( strtok( $base, '?' ) ) . '">';
		$query = array();
		wp_parse_str( (string) wp_parse_url( $base, PHP_URL_QUERY ), $query );
		// The rest of the page's address goes with the form, so a calendar inside another view stays in it.
		foreach ( $query + array(
			'cal-view' => $view,
			'cal-date' => $date,
		) as $name => $value ) {
			if ( is_scalar( $value ) ) {
				$html .= '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
			}
		}
		return $html . $fields . '<button type="submit" class="pos-cal__button pos-cal__apply">' . esc_html__( 'Show', 'packout-core' ) . '</button></form>';
	}

	/**
	 * The links that subscribe a calendar program to these events.
	 *
	 * @param array<string, mixed> $settings From settings().
	 */
	private static function feed( array $settings ): string {
		return '<p class="pos-cal__feed"><a href="' . esc_url( Calendar::file_url( $settings, 'webcal' ), array( 'webcal' ) ) . '">' . esc_html__( 'Subscribe in a calendar app', 'packout-core' ) . '</a> <span aria-hidden="true">·</span> <a href="' . esc_url( Calendar::file_url( $settings ) ) . '">' . esc_html__( 'Calendar file', 'packout-core' ) . '</a><span class="pos-cal__feed-note"> ' . esc_html__( 'Public events only.', 'packout-core' ) . '</span></p>';
	}

	/**
	 * A month as a table: a caption, a header for each day of the week, and a cell for each day.
	 *
	 * The cells can be walked with the keyboard. One day at a time holds the
	 * place in the tab order: today when the month has it, otherwise the first.
	 *
	 * @param array<string, mixed>             $settings From settings().
	 * @param array<int, array<string, mixed>> $events   From Calendar::events().
	 * @param array{from: string, to: string}  $range    The whole weeks the month covers.
	 * @param string                           $date     The day the month was asked for.
	 * @param string                           $today    Today.
	 * @param string                           $title    The month in words.
	 */
	private static function month( array $settings, array $events, array $range, string $date, string $today, string $title ): string {
		global $wp_locale;
		$month = substr( $date, 0, 7 );
		$holds = substr( $today, 0, 7 ) === $month ? $today : $month . '-01';
		$open  = $settings['switcher'] ? 'day' : 'month';
		/* translators: A day with its weekday, as a PHP date format. */
		$spoken = __( 'l, F j, Y', 'packout-core' );

		$html  = '<table class="pos-cal__month"><caption class="pos-cal__sr">' . esc_html( $title ) . '. ' . esc_html__( 'Arrow keys move between days. Page Up and Page Down change the month. Enter opens a day.', 'packout-core' ) . '</caption><thead><tr>';
		$weeks = Rules::weeks( $range['from'], $range['to'] );
		foreach ( $weeks[0] as $day ) {
			$weekday = Rules::weekday( $day );
			$html   .= '<th scope="col"><abbr title="' . esc_attr( $wp_locale->get_weekday( $weekday ) ) . '">' . esc_html( $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $weekday ) ) ) . '</abbr></th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $weeks as $week ) {
			$html .= '<tr>';
			foreach ( $week as $index => $day ) {
				$here  = self::on( $events, $day );
				$count = count( $here );
				$class = 'pos-cal__day' . ( substr( $day, 0, 7 ) !== $month ? ' is-outside' : '' ) . ( $day === $today ? ' is-today' : '' ) . ( $count > 0 ? ' has-events' : '' );
				/* translators: %d: number of events. */
				$label = ( $day === $today ? __( 'Today', 'packout-core' ) . ', ' : '' ) . self::day_text( $day, $spoken ) . '. ' . ( $count > 0 ? sprintf( _n( '%d event', '%d events', $count, 'packout-core' ), $count ) : __( 'Nothing planned', 'packout-core' ) ) . '.';
				$html .= '<td class="' . esc_attr( $class ) . '">';
				$html .= self::go( $settings, $open, $day, '<span aria-hidden="true">' . esc_html( (string) (int) substr( $day, 8, 2 ) ) . '</span>', 'class="pos-cal__date" data-cal-day="' . esc_attr( $day ) . '" tabindex="' . ( $day === $holds ? '0' : '-1' ) . '" aria-label="' . esc_attr( $label ) . '"' . ( $day === $today ? ' aria-current="date"' : '' ) );
				$limit = $settings['switcher'] && $count > self::DAY_LIMIT ? self::DAY_LIMIT : $count;
				if ( $count > 0 ) {
					$html .= '<ul class="pos-cal__items">';
					foreach ( array_slice( $here, 0, $limit ) as $event ) {
						// A span runs across the days it covers. Its title is written where it starts, and again at the start of each week.
						$spans = $event['last_day'] > $event['first_day'];
						$edge  = $spans ? ( $day === $event['first_day'] ? ' is-first' : '' ) . ( $day === $event['last_day'] ? ' is-last' : '' ) . ( $day !== $event['first_day'] && 0 !== $index ? ' is-carried' : '' ) : '';
						$html .= '<li class="' . esc_attr( self::classes( $event, 'pos-cal__item' . ( $spans ? ' pos-cal__item--span' . $edge : '' ) ) ) . '">' . self::chip( $event, ! $spans || $day === $event['first_day'] ) . '</li>';
					}
					$html .= '</ul>';
					if ( $count > $limit ) {
						/* translators: %d: number of events not shown. */
						$html .= self::go( $settings, 'agenda', $day, esc_html( sprintf( _n( '%d more', '%d more', $count - $limit, 'packout-core' ), $count - $limit ) ), 'class="pos-cal__more"' );
					}
				}
				$html .= '</td>';
			}//end foreach
			$html .= '</tr>';
		}//end foreach
		return $html . '</tbody></table>';
	}

	/**
	 * One day or seven as columns: a band of all-day events and spans, then timed events on an hour scale.
	 *
	 * The scale runs from the earliest event to the latest, and never less than 8 am to 6 pm.
	 *
	 * @param array<string, mixed>             $settings From settings().
	 * @param array<int, array<string, mixed>> $events   From Calendar::events().
	 * @param array<int, string>               $days     The days to draw.
	 * @param string                           $today    Today.
	 */
	private static function days( array $settings, array $events, array $days, string $today ): string {
		$band  = array();
		$timed = array();
		$all   = array();
		foreach ( $days as $day ) {
			$band[ $day ]  = array();
			$timed[ $day ] = array();
			foreach ( self::on( $events, $day ) as $index => $event ) {
				// An event with no time of day, or one that covers the whole of this day, goes in the band.
				if ( $event['all_day'] || ( $event['first_day'] < $day && $event['last_day'] > $day ) ) {
					$band[ $day ][] = $event;
					continue;
				}
				$segment                 = Rules::segment( self::at( $event, 'start_at' ), self::at( $event, 'end_at' ), $day );
				$timed[ $day ][ $index ] = array(
					'event'   => $event,
					'segment' => $segment,
				);
				$all[]                   = $segment;
			}
		}
		list( $first, $last ) = Rules::hours( $all );
		$minutes              = max( 60, ( $last - $first ) * 60 );
		/* translators: A weekday and a day of the month, as a PHP date format. */
		$short = __( 'D j', 'packout-core' );
		/* translators: A day with its weekday, as a PHP date format. */
		$spoken = __( 'l, F j', 'packout-core' );
		$open   = $settings['switcher'] ? 'day' : $settings['view'];

		$html = '<div class="pos-cal__days" style="--pos-cal-n:' . count( $days ) . ';--pos-cal-hours:' . ( $last - $first ) . '">';

		$html .= '<div class="pos-cal__row pos-cal__row--heads"><span class="pos-cal__gutter"></span>';
		foreach ( $days as $day ) {
			$html .= '<h4 class="pos-cal__head' . ( $day === $today ? ' is-today' : '' ) . '">' . self::go( $settings, $open, $day, esc_html( self::day_text( $day, $short ) ) . ( $day === $today ? '<span class="pos-cal__sr">, ' . esc_html__( 'today', 'packout-core' ) . '</span>' : '' ), $day === $today ? 'aria-current="date"' : '' ) . '</h4>';
		}
		$html .= '</div>';

		$html .= '<div class="pos-cal__row pos-cal__row--band"><span class="pos-cal__gutter">' . esc_html__( 'All day', 'packout-core' ) . '</span>';
		foreach ( $days as $day ) {
			if ( ! $band[ $day ] ) {
				$html .= '<div class="pos-cal__cell"></div>';
				continue;
			}
			/* translators: %s: a day. */
			$html .= '<ul class="pos-cal__cell" aria-label="' . esc_attr( sprintf( __( 'All day, %s', 'packout-core' ), self::day_text( $day, $spoken ) ) ) . '">';
			foreach ( $band[ $day ] as $event ) {
				$html .= '<li class="' . esc_attr( self::classes( $event, 'pos-cal__item' ) ) . '">' . self::chip( $event, false ) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '</div>';

		$html .= '<div class="pos-cal__row pos-cal__row--scale"><ol class="pos-cal__gutter pos-cal__hours" aria-hidden="true">';
		for ( $hour = $first; $hour < $last; $hour++ ) {
			$html .= '<li>' . esc_html( (string) wp_date( 'g a', $hour * 3600, new \DateTimeZone( 'UTC' ) ) ) . '</li>';
		}
		$html .= '</ol>';
		foreach ( $days as $day ) {
			if ( ! $timed[ $day ] ) {
				$html .= '<div class="pos-cal__col"></div>';
				continue;
			}
			$columns = Rules::columns( array_map( static fn( array $row ): array => $row['segment'], $timed[ $day ] ) );
			$html   .= '<ul class="pos-cal__col" aria-label="' . esc_attr( self::day_text( $day, $spoken ) ) . '">';
			foreach ( $timed[ $day ] as $index => $row ) {
				$top    = ( $row['segment'][0] - $first * 60 ) / $minutes * 100;
				$height = ( $row['segment'][1] - $row['segment'][0] ) / $minutes * 100;
				$width  = 100 / $columns[ $index ]['of'];
				$style  = sprintf( '--pos-cal-top:%.3F%%;--pos-cal-height:%.3F%%;--pos-cal-left:%.3F%%;--pos-cal-width:%.3F%%', $top, $height, $width * $columns[ $index ]['column'], $width );
				$html  .= '<li class="' . esc_attr( self::classes( $row['event'], 'pos-cal__event' ) ) . '" style="' . esc_attr( $style ) . '"><a class="pos-cal__event-link" href="' . esc_url( $row['event']['url'] ) . '"><span class="pos-cal__event-title">' . esc_html( $row['event']['title'] ) . '</span> <span class="pos-cal__event-when">' . self::when( $row['event'] ) . '</span>' . self::flags( $row['event'] ) . '</a></li>';
			}
			$html .= '</ul>';
		}
		return $html . '</div></div>';
	}

	/**
	 * Events as a list grouped by day.
	 *
	 * An event that began before the first day is listed on the first day.
	 *
	 * @param array<string, mixed>             $settings From settings().
	 * @param array<int, array<string, mixed>> $events   From Calendar::events().
	 * @param string                           $from     The first day.
	 * @param string                           $to       The last day.
	 * @param string                           $today    Today.
	 */
	private static function agenda( array $settings, array $events, string $from, string $to, string $today ): string {
		$by_day = array();
		foreach ( $events as $event ) {
			$day = max( $event['first_day'], $from );
			if ( $day <= $to ) {
				$by_day[ $day ][] = $event;
			}
		}
		if ( ! $by_day ) {
			return '<p class="pos-cal__empty">' . esc_html__( 'Nothing is on the calendar for these days.', 'packout-core' ) . '</p>';
		}
		ksort( $by_day );
		/* translators: A day with its weekday, as a PHP date format. */
		$spoken = __( 'l, F j, Y', 'packout-core' );
		$html   = '<ol class="pos-cal__agenda">';
		foreach ( $by_day as $day => $rows ) {
			$html .= '<li class="pos-cal__group' . ( $day === $today ? ' is-today' : '' ) . '"><h4 class="pos-cal__group-day"><time datetime="' . esc_attr( $day ) . '">' . esc_html( self::day_text( $day, $spoken ) ) . '</time>' . ( $day === $today ? ' <span class="pos-cal__flag pos-cal__flag--today">' . esc_html__( 'Today', 'packout-core' ) . '</span>' : '' ) . '</h4><ul class="pos-cal__rows">';
			foreach ( $rows as $event ) {
				$html .= self::row( $event, $day );
			}
			$html .= '</ul></li>';
		}
		unset( $settings );
		return $html . '</ol>';
	}

	/**
	 * One event of an agenda.
	 *
	 * @param array<string, mixed> $event From Calendar::events().
	 * @param string               $day   The day it is listed on.
	 */
	private static function row( array $event, string $day ): string {
		// A span says how long it runs. An event of one day says its time.
		$when = $event['last_day'] > $event['first_day'] ? esc_html( Schema::span( $event['first_day'], $event['last_day'] ) ) . ( $event['all_day'] ? '' : ', ' . self::when( $event ) ) : self::when( $event );
		$meta = array();
		if ( '' !== $event['organization'] ) {
			$meta[] = esc_html( $event['organization'] );
		}
		if ( '' !== $event['partner'] && $event['partner'] !== $event['organization'] ) {
			$meta[] = esc_html( $event['partner'] );
		}
		if ( 'mission' === $event['kind'] && $event['spots'] > 0 ) {
			/* translators: %d: number of Spots. */
			$meta[] = esc_html( sprintf( _n( '%d Spot', '%d Spots', $event['spots'], 'packout-core' ), $event['spots'] ) );
		}
		if ( '' !== $event['landmark'] ) {
			/* translators: %s: where the crew meets. */
			$meta[] = esc_html( sprintf( __( 'Meet at %s', 'packout-core' ), $event['landmark'] ) );
		}
		unset( $day );
		return '<li class="' . esc_attr( self::classes( $event, 'pos-cal__entry' ) ) . '"><span class="pos-cal__entry-when">' . $when . '</span><span class="pos-cal__entry-what"><a class="pos-cal__entry-title" href="' . esc_url( $event['url'] ) . '">' . esc_html( $event['title'] ) . '</a>' . self::flags( $event ) . ( $meta ? '<span class="pos-cal__entry-meta">' . implode( ' <span aria-hidden="true">·</span> ', $meta ) . '</span>' : '' ) . '</span></li>';
	}

	/**
	 * The events one page of an agenda shows, and the day the next page starts on.
	 *
	 * Whole days are listed until there are as many events as asked for, so a
	 * day is never split across two pages.
	 *
	 * @param array<int, array<string, mixed>> $events From Calendar::events(), sorted by start.
	 * @param string                           $from   The first day.
	 * @param string                           $to     The last day of the range that was read.
	 * @param int                              $count  How many events to show.
	 * @return array{0: array<int, array<string, mixed>>, 1: string}
	 */
	private static function page( array $events, string $from, string $to, int $count ): array {
		$by_day = array();
		foreach ( $events as $event ) {
			$by_day[ max( $event['first_day'], $from ) ][] = $event;
		}
		ksort( $by_day );
		$shown = array();
		foreach ( $by_day as $day => $rows ) {
			if ( count( $shown ) >= $count ) {
				return array( $shown, (string) $day );
			}
			$shown = array_merge( $shown, $rows );
		}
		return array( $shown, Rules::shift( $to, 1 ) );
	}

	/**
	 * The day the page before an agenda starts on.
	 *
	 * @param array<string, mixed> $settings From settings().
	 * @param int                  $user     The person asking.
	 * @param string               $date     The day this page starts on.
	 */
	private static function page_before( array $settings, int $user, string $date ): string {
		$from   = Rules::shift( $date, -Rules::MAX_DAYS );
		$events = Calendar::events(
			array(
				'from' => $from,
				'to'   => Rules::shift( $date, -1 ),
			) + $settings,
			$user
		);
		$by_day = array();
		foreach ( $events as $event ) {
			// An event that is still running on this page's first day is on this page, not the one before.
			if ( $event['last_day'] < $date ) {
				$by_day[ max( $event['first_day'], $from ) ][] = $event;
			}
		}
		krsort( $by_day );
		$seen = 0;
		foreach ( $by_day as $day => $rows ) {
			$seen += count( $rows );
			if ( $seen >= $settings['count'] ) {
				return (string) $day;
			}
		}
		return $by_day ? (string) array_key_last( $by_day ) : $from;
	}

	/* ---------- Upcoming and Past ---------- */

	/**
	 * The events Upcoming or Past shows.
	 *
	 * Upcoming is the next events from today. Past is the last finished
	 * ones, newest first.
	 *
	 * @param array<string, mixed> $settings From settings().
	 * @param int                  $user     The person asking.
	 * @return array<int, array<string, mixed>>
	 */
	private static function found( array $settings, int $user ): array {
		$past  = 'past' === $settings['view'];
		$today = Calendar::today();
		$now   = time();
		$found = Calendar::events(
			array(
				'from' => $past ? Rules::shift( $today, -Rules::REACH ) : $today,
				'to'   => $past ? $today : Rules::shift( $today, Rules::REACH ),
			) + $settings,
			$user
		);
		$found = array_values( array_filter( $found, static fn( array $event ): bool => $past ? $event['end_at'] <= $now : $event['end_at'] > $now ) );
		if ( $past ) {
			usort( $found, static fn( array $a, array $b ): int => array( $b['end_at'], $b['id'] ) <=> array( $a['end_at'], $a['id'] ) );
		}
		return array_slice( $found, 0, $settings['count'] );
	}

	/**
	 * Upcoming or Past: a list of cards with no controls. Past says how many Spots each mission cleaned.
	 *
	 * @param array<string, mixed>             $settings From settings().
	 * @param array<int, array<string, mixed>> $found    From found().
	 */
	private static function cards( array $settings, array $found ): string {
		$past = 'past' === $settings['view'];
		if ( ! $found ) {
			return '<p class="pos-cal__empty">' . ( $past ? esc_html__( 'Nothing has finished yet.', 'packout-core' ) : esc_html__( 'Nothing is planned yet.', 'packout-core' ) ) . '</p>';
		}

		/* translators: A short month name, as a PHP date format. */
		$month = __( 'M', 'packout-core' );
		$html  = '<ul class="pos-cal__cards">';
		foreach ( $found as $event ) {
			$meta = array();
			if ( '' !== $event['organization'] ) {
				$meta[] = esc_html( $event['organization'] );
			}
			if ( 'mission' === $event['kind'] && $past && 'completed' === $event['status'] ) {
				/* translators: %d: number of Spots. */
				$meta[] = esc_html( sprintf( _n( '%d Spot cleaned', '%d Spots cleaned', $event['cleaned'], 'packout-core' ), $event['cleaned'] ) );
			} elseif ( 'mission' === $event['kind'] && ! $past && $event['spots'] > 0 ) {
				/* translators: %d: number of Spots. */
				$meta[] = esc_html( sprintf( _n( '%d Spot', '%d Spots', $event['spots'], 'packout-core' ), $event['spots'] ) );
			}
			if ( ! $past && '' !== $event['landmark'] ) {
				/* translators: %s: where the crew meets. */
				$meta[] = esc_html( sprintf( __( 'Meet at %s', 'packout-core' ), $event['landmark'] ) );
			}
			$html .= '<li class="' . esc_attr( self::classes( $event, 'pos-cal__card' ) ) . '">';
			$html .= '<span class="pos-cal__card-day" aria-hidden="true"><span class="pos-cal__card-month">' . esc_html( self::day_text( $event['first_day'], $month ) ) . '</span><span class="pos-cal__card-date">' . esc_html( (string) (int) substr( $event['first_day'], 8, 2 ) ) . '</span></span>';
			$html .= '<span class="pos-cal__card-body"><a class="pos-cal__card-title" href="' . esc_url( $event['url'] ) . '">' . esc_html( $event['title'] ) . '</a>' . self::flags( $event );
			$html .= '<span class="pos-cal__card-when">' . self::when( $event, true ) . '</span>';
			$html .= $meta ? '<span class="pos-cal__card-meta">' . implode( ' <span aria-hidden="true">·</span> ', $meta ) . '</span>' : '';
			$html .= '</span></li>';
		}//end foreach
		$html .= '</ul>';
		if ( $settings['feed'] && ! $past ) {
			$html .= self::feed( $settings );
		}
		return $html;
	}

	/**
	 * Upcoming, for a page that is not a calendar: a partner's, a program's, an organization's, or a Place's.
	 *
	 * A public page shows every reader what a visitor may see, so it reads
	 * the same signed in or not. The page is told which records were shown,
	 * so its own lists can leave them out.
	 *
	 * @param array<string, mixed> $limits `partner`, `organization`, `team`, `program`, `campaign`, or `only`, and optionally `count` and `show`.
	 * @return array{html: string, missions: array<int, int>, campaigns: array<int, int>} `html` is empty when nothing is coming up.
	 */
	public static function upcoming( array $limits ): array {
		$settings = self::settings(
			array(
				'view' => 'upcoming',
				'feed' => false,
			) + $limits
		);
		$found    = self::found( $settings, 0 );
		$ids      = static fn( string $kind ): array => array_values( array_map( static fn( array $event ): int => (int) $event['id'], array_filter( $found, static fn( array $event ): bool => $kind === $event['kind'] ) ) );
		if ( $found ) {
			wp_enqueue_style( 'packout-calendar' );
		}
		return array(
			'html'      => $found ? '<div class="pos-cal pos-cal--upcoming" ' . self::still() . '>' . self::cards( $settings, $found ) . '</div>' : '',
			'missions'  => $ids( 'mission' ),
			'campaigns' => $ids( 'campaign' ),
		);
	}
}
