<?php
/**
 * The team workspace: lists as Data Views, and the drawer that opens beside them.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every list in My teams is printed twice over the same rows.
 *
 * The first is plain markup: a list of rows, each with a link. It is what a
 * browser without scripts shows, and what stays on screen on a site with no
 * Data Views runtime. The second is the configuration of a
 * Data View over the same rows, which the script mounts in place of the plain
 * list, under three buttons: Table, Cards, and List. A table has a column for
 * every value. Cards and the list show the picture, the title, one line under
 * it, and the remaining values. The person's choice of layout is kept in
 * their browser.
 *
 * A row opens a page of its own, or a pane. Panes are collected while the
 * view is built and printed once, after it. With scripts they slide in from
 * the right in the drawer, half the screen wide on a large screen. Without
 * scripts they are sections under the list, and a row's link jumps to its
 * section.
 */
class Workspace_Lists {

	/**
	 * The layouts a person can switch between, with the names Data Views gives them.
	 */
	private const LAYOUTS = array( 'table', 'grid', 'list' );

	/**
	 * Panes waiting to be printed: id, title, markup.
	 *
	 * @var array<string, array{title: string, html: string}>
	 */
	private static array $panes = array();

	/**
	 * The pane to open when the page loads, because its form was refused.
	 *
	 * @var string
	 */
	private static string $reopen = '';

	/**
	 * Load the scripts: the site's Data Views runtime when it has one, the drawer, then ours.
	 */
	public static function enqueue(): void {
		$lists = Ui::lists();
		foreach ( $lists['styles'] as $handle ) {
			wp_enqueue_style( $handle );
		}
		$deps = array_merge( array( 'packout-workspace', Ui::drawer() ), $lists['scripts'] );
		wp_enqueue_script( 'packout-workspace-views', plugins_url( 'assets/js/workspace-views.js', PACKOUT_CORE_FILE ), $deps, PACKOUT_CORE_VERSION, true );
	}

	/**
	 * The address that opens a pane.
	 *
	 * @param string $pane Pane ID.
	 */
	public static function link( string $pane ): string {
		return '#pos-pane-' . $pane;
	}

	/**
	 * A list of records.
	 *
	 * @param string                              $key     Name of the list, for remembering the layout.
	 * @param array<string, array<string, mixed>> $columns Columns by ID, after the title: label, and optionally type
	 *                                                  (`text`, `integer`, `media`), sort, search.
	 * @param array<int, array<string, mixed>>    $rows    Rows: id, title, url, and a value for each column.
	 * @param array<string, mixed>                $options title (label of the first column), empty (what to say with
	 *                                                     no rows), layout (the one to start in), media and
	 *                                                     description (columns that cards and lists show as the
	 *                                                     picture and the line under the title), and photos
	 *                                                     (true when the picture is a photo, which fills the
	 *                                                     top of a card from edge to edge, where a logo sits
	 *                                                     inside it).
	 */
	public static function view( string $key, array $columns, array $rows, array $options = array() ): string {
		$empty = (string) ( $options['empty'] ?? __( 'Nothing here yet.', 'packout-core' ) );
		if ( ! $rows ) {
			return '<p class="pos-view__empty">' . esc_html( $empty ) . '</p>';
		}
		$media       = (string) ( $options['media'] ?? '' );
		$description = (string) ( $options['description'] ?? '' );
		$layout      = in_array( $options['layout'] ?? '', self::LAYOUTS, true ) ? (string) $options['layout'] : 'table';

		$fields = array(
			array(
				'id'                 => 'title',
				'label'              => (string) ( $options['title'] ?? __( 'Name', 'packout-core' ) ),
				'type'               => 'text',
				'enableSorting'      => true,
				'enableHiding'       => false,
				'enableGlobalSearch' => true,
			),
		);
		$shown  = array();
		$table  = array( 'title' );
		foreach ( $columns as $id => $column ) {
			$type     = (string) ( $column['type'] ?? 'text' );
			$fields[] = array(
				'id'                 => $id,
				'label'              => (string) $column['label'],
				'type'               => $type,
				'enableSorting'      => 'media' !== $type && ! empty( $column['sort'] ),
				'enableGlobalSearch' => 'text' === $type && false !== ( $column['search'] ?? true ),
			);
			if ( $id !== $media && $id !== $description ) {
				$shown[] = $id;
			}
			if ( 'media' !== $type ) {
				$table[] = $id;
			}
		}

		$config = array(
			'source'    => array(
				'type' => 'static',
				'rows' => array_values( $rows ),
			),
			'fields'    => $fields,
			'view'      => array(
				'type'             => $layout,
				'fields'           => $shown,
				'titleField'       => 'title',
				'mediaField'       => $media,
				'descriptionField' => $description,
				'showTitle'        => true,
				'showMedia'        => '' !== $media,
				'showDescription'  => '' !== $description,
				'perPage'          => 25,
				'page'             => 1,
				'search'           => '',
				'filters'          => array(),
				'layout'           => new \stdClass(),
			),
			'dataviews' => array(
				// The script draws the three layout buttons, so each layout gets the columns that suit it.
				'picker'         => false,
				// The title is the link, in every layout: to the record's page, or to its pane.
				'itemsClickable' => false,
				'filters'        => false,
				'search'         => count( $rows ) > 5,
				'searchLabel'    => __( 'Search', 'packout-core' ),
				'empty'          => $empty,
				'config'         => array( 'perPageSizes' => array( 10, 25, 50, 100 ) ),
			),
			'actions'   => array(),
		);

		// The same rows as plain markup.
		$plain = '<ul class="pos-ws__rows pos-ws__rows--tall">';
		foreach ( $rows as $row ) {
			$facts = array();
			foreach ( $columns as $id => $column ) {
				if ( 'media' !== ( $column['type'] ?? 'text' ) && '' !== (string) ( $row[ $id ] ?? '' ) ) {
					$facts[] = (string) $row[ $id ];
				}
			}
			$title  = '' !== (string) ( $row['url'] ?? '' ) ? '<a href="' . esc_url( (string) $row['url'] ) . '">' . esc_html( (string) $row['title'] ) . '</a>' : esc_html( (string) $row['title'] );
			$plain .= '<li><span><strong>' . $title . '</strong>' . ( $facts ? '<br /><span class="pos-ws__muted">' . esc_html( implode( ' · ', $facts ) ) . '</span>' : '' ) . '</span></li>';
		}
		$plain .= '</ul>';

		$labels = array(
			'table' => __( 'Table', 'packout-core' ),
			'grid'  => __( 'Cards', 'packout-core' ),
			'list'  => __( 'List', 'packout-core' ),
		);
		$setup  = array(
			'layout' => $layout,
			'table'  => $table,
			'labels' => $labels,
			'choose' => __( 'Layout', 'packout-core' ),
		);

		return '<div class="pos-view' . ( '' === $media ? ' pos-view--no-media' : '' ) . ( '' !== $media && ! empty( $options['photos'] ) ? ' pos-view--photos' : '' ) . '" data-pos-view="' . esc_attr( $key ) . '" data-pos-setup="' . esc_attr( (string) wp_json_encode( $setup ) ) . '"><div class="pos-view__mount" data-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '" hidden></div><div class="pos-view__plain">' . $plain . '</div></div>';
	}

	/**
	 * Keep a pane to print after the view.
	 *
	 * @param string $id    Pane ID: lower-case letters, digits, and hyphens.
	 * @param string $title What the drawer's bar says.
	 * @param string $html  Markup.
	 */
	public static function pane( string $id, string $title, string $html ): void {
		if ( '' === $html ) {
			return;
		}
		self::$panes[ $id ] = array(
			'title' => $title,
			'html'  => $html,
		);
	}

	/**
	 * Whether a pane is waiting to be printed.
	 *
	 * @param string $id Pane ID.
	 */
	public static function has_pane( string $id ): bool {
		return isset( self::$panes[ $id ] );
	}

	/**
	 * A button that opens a pane.
	 *
	 * @param string $pane  Pane ID.
	 * @param string $title What the drawer's bar says.
	 * @param string $text  What the button says.
	 * @param string $classes Extra class for the button.
	 */
	public static function opener( string $pane, string $title, string $text, string $classes = 'pos-ws__button--primary' ): string {
		return '<a class="pos-ws__button ' . esc_attr( $classes ) . '" href="' . esc_attr( self::link( $pane ) ) . '" data-pos-open="' . esc_attr( $pane ) . '" data-pos-label="' . esc_attr( $title ) . '">' . esc_html( $text ) . '</a>';
	}

	/**
	 * Open a pane when the page loads.
	 *
	 * @param string $pane Pane ID.
	 */
	public static function reopen( string $pane ): void {
		self::$reopen = $pane;
	}

	/**
	 * The panes, and the drawer they open in. Call once, after the view.
	 */
	public static function panes(): string {
		if ( ! self::$panes ) {
			return '';
		}
		$html = '<div class="pos-ws__panes pos-ws__panes--drawer"' . ( '' !== self::$reopen ? ' data-pos-reopen="' . esc_attr( self::$reopen ) . '"' : '' ) . '>';
		foreach ( self::$panes as $id => $pane ) {
			$html .= '<section class="packout-drawer__pane pos-ws__pane" id="pos-pane-' . esc_attr( $id ) . '" data-pane="' . esc_attr( $id ) . '" data-pos-label="' . esc_attr( $pane['title'] ) . '">';
			$html .= '<h3 class="pos-ws__pane-title">' . esc_html( $pane['title'] ) . '</h3>';
			if ( $id === self::$reopen && '' !== Workspace::problem() ) {
				// The drawer covers the page's own notice, so the pane says what was wrong as well.
				$html .= Workspace::notice( 'error', Workspace::problem() );
			} elseif ( $id === self::$reopen && '' !== Workspace::said() ) {
				$html .= Workspace::notice( 'success', Workspace::said() );
			}
			$html .= $pane['html'] . '</section>';
		}
		$html        .= '</div>';
		self::$panes  = array();
		self::$reopen = '';

		// Without scripts the panes stay on the page, under the list.
		$html .= '<noscript><style>.pos-ws .pos-ws__panes--drawer{display:block}</style></noscript>';
		$html .= '<div class="packout-drawer pos-drawer" hidden>';
		$html .= '<button type="button" class="packout-drawer__backdrop" data-packout-close tabindex="-1" aria-label="' . esc_attr__( 'Close', 'packout-core' ) . '"></button>';
		$html .= '<aside class="packout-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="pos-drawer-title">';
		$html .= '<header class="packout-drawer__bar"><h2 id="pos-drawer-title" class="packout-drawer__title"></h2>';
		$html .= '<button type="button" class="packout-drawer__close" data-packout-close aria-label="' . esc_attr__( 'Close', 'packout-core' ) . '"><span aria-hidden="true">&times;</span></button></header>';
		return $html . '<div class="packout-drawer__body pos-ws pos-ws--drawer"></div></aside></div>';
	}
}
