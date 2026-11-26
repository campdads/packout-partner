<?php
/**
 * Administration lists as Data Views.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A table of records drawn by the site's Data Views runtime.
 *
 * The same runtime the workspace uses, with the pieces a coordinator needs on
 * a long list: a count, filters, and columns that can be moved and hidden.
 * Those controls are the Data Views defaults. Column order is kept on the
 * user. A browser without the script gets the same rows as a plain table.
 */
class Admin_Views {

	/**
	 * Load the Data Views runtime on Pack-Out Spot screens.
	 */
	public static function enqueue(): void {
		$lists = Ui::lists();
		foreach ( $lists['styles'] as $handle ) {
			wp_enqueue_style( $handle );
		}
		foreach ( $lists['scripts'] as $handle ) {
			wp_enqueue_script( $handle );
		}
	}

	/**
	 * Whether the runtime script will load on this screen.
	 */
	public static function ready(): bool {
		return array() !== Ui::lists()['scripts'];
	}

	/**
	 * Print a Data View, with a plain table underneath until it draws.
	 *
	 * @param string                              $key     Preference name, unique on the site.
	 * @param array<string, array<string, mixed>> $columns Columns after the title. label, and optionally type
	 *                                                     (`text` or `integer`), sort, search, filter, primary.
	 * @param array<int, array<string, mixed>>    $rows    Rows: id, title, url, and a value for each column.
	 * @param array<string, mixed>                $options title (first column), empty, search, sort (field,
	 *                                                     direction), filters (field, value, and optionally operator),
	 *                                                     actions (id, label, url with {id} for the row's ID).
	 */
	public static function render( string $key, array $columns, array $rows, array $options = array() ): void {
		$key   = substr( 'packout-' . sanitize_key( $key ), 0, 80 );
		$title = (string) ( $options['title'] ?? __( 'Name', 'packout-core' ) );
		$empty = (string) ( $options['empty'] ?? __( 'Nothing here.', 'packout-core' ) );
		$count = count( $rows );

		$fields = array(
			array(
				'id'                 => 'title',
				'label'              => $title,
				'type'               => 'text',
				'enableSorting'      => true,
				'enableHiding'       => false,
				'enableGlobalSearch' => true,
				'filterBy'           => array(
					'operators' => array( 'is', 'isNot', 'contains' ),
					'isPrimary' => false,
				),
			),
		);
		$shown  = array( 'title' );
		$ids    = array( 'title' );
		foreach ( $columns as $id => $column ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || 'title' === $id ) {
				continue;
			}
			$type     = 'integer' === ( $column['type'] ?? 'text' ) ? 'integer' : 'text';
			$fields[] = array(
				'id'                 => $id,
				'label'              => (string) ( $column['label'] ?? $id ),
				'type'               => $type,
				'enableSorting'      => false !== ( $column['sort'] ?? true ),
				'enableHiding'       => true,
				'enableGlobalSearch' => 'text' === $type && false !== ( $column['search'] ?? false ),
				'filterBy'           => false === ( $column['filter'] ?? true ) ? false : self::filter_by( $type, ! empty( $column['primary'] ) ),
			);
			$shown[]  = $id;
			$ids[]    = $id;
		}

		$sort  = isset( $options['sort'] ) && is_array( $options['sort'] ) ? $options['sort'] : array();
		$field = sanitize_key( (string) ( $sort['field'] ?? 'title' ) );
		if ( ! in_array( $field, $ids, true ) ) {
			$field = 'title';
		}
		$filters = array();
		foreach ( (array) ( $options['filters'] ?? array() ) as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}
			$name     = sanitize_key( (string) ( $filter['field'] ?? '' ) );
			$value    = (string) ( $filter['value'] ?? '' );
			$operator = sanitize_key( (string) ( $filter['operator'] ?? 'is' ) );
			if ( '' === $name || '' === $value || ! in_array( $name, $ids, true ) ) {
				continue;
			}
			if ( ! in_array( $operator, array( 'is', 'isNot', 'contains', 'lessThan', 'greaterThan' ), true ) ) {
				$operator = 'is';
			}
			$filters[] = array(
				'field'    => $name,
				'operator' => $operator,
				'value'    => $value,
			);
		}

		// Row actions are links. The first one is shown on the row; the address takes the row's ID.
		$actions = array();
		foreach ( (array) ( $options['actions'] ?? array() ) as $action ) {
			$id  = is_array( $action ) ? sanitize_key( (string) ( $action['id'] ?? '' ) ) : '';
			$url = is_array( $action ) ? (string) ( $action['url'] ?? '' ) : '';
			if ( '' === $id || '' === $url ) {
				continue;
			}
			$actions[] = array(
				'id'           => $id,
				'label'        => (string) ( $action['label'] ?? $id ),
				'kind'         => 'link',
				'origin'       => 'block',
				'url'          => $url,
				'field'        => '',
				'isPrimary'    => array() === $actions,
				'supportsBulk' => false,
				'disabled'     => false,
				'context'      => 'list',
				'enabled'      => true,
			);
		}

		$preference = App::kernel()->lists()->saved( $key, $ids );

		$config = array(
			'preferenceKey' => $key,
			'source'        => array(
				'type' => 'static',
				'rows' => array_values( $rows ),
			),
			'fields'        => $fields,
			'view'          => array(
				'type'            => 'table',
				'fields'          => $shown,
				'titleField'      => 'title',
				'showTitle'       => true,
				'showMedia'       => false,
				'showDescription' => false,
				'perPage'         => 25,
				'page'            => 1,
				'search'          => (string) ( $options['search'] ?? '' ),
				'filters'         => $filters,
				'sort'            => array(
					'field'     => $field,
					'direction' => 'desc' === ( $sort['direction'] ?? '' ) ? 'desc' : 'asc',
				),
				'layout'          => array(
					'enableMoving' => true,
				),
			),
			'dataviews'     => array(
				'picker'         => false,
				'itemsClickable' => true,
				'filters'        => true,
				'viewConfig'     => true,
				'search'         => true,
				'searchLabel'    => __( 'Search', 'packout-core' ),
				'empty'          => $empty,
				'config'         => array( 'perPageSizes' => array( 10, 25, 50, 100 ) ),
			),
			'actions'       => $actions,
		);
		if ( is_array( $preference ) ) {
			$config['preference'] = $preference;
		}

		$plain = '<table class="widefat striped packout-admin__table"><caption class="screen-reader-text">';
		/* translators: %s: number of items. */
		$plain .= esc_html( sprintf( _n( '%s item', '%s items', $count, 'packout-core' ), number_format_i18n( $count ) ) );
		$plain .= '</caption><thead><tr><th scope="col">' . esc_html( $title ) . '</th>';
		foreach ( $columns as $id => $column ) {
			$plain .= '<th scope="col">' . esc_html( (string) ( $column['label'] ?? $id ) ) . '</th>';
		}
		if ( $actions ) {
			$plain .= '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'packout-core' ) . '</span></th>';
		}
		$plain .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$href  = (string) ( $row['url'] ?? '' );
			$label = esc_html( (string) ( $row['title'] ?? '' ) );
			$cell  = $label;
			if ( str_starts_with( $href, '#packout-pane-' ) ) {
				$cell = '<a href="' . esc_attr( $href ) . '">' . $label . '</a>';
			} elseif ( '' !== $href ) {
				$cell = '<a href="' . esc_url( $href ) . '">' . $label . '</a>';
			}
			$plain .= '<tr><td><strong>' . $cell . '</strong></td>';
			foreach ( $columns as $id => $column ) {
				$plain .= '<td>' . esc_html( (string) ( $row[ $id ] ?? '' ) ) . '</td>';
			}
			if ( $actions ) {
				$links = array();
				foreach ( $actions as $action ) {
					$links[] = '<a href="' . esc_url( str_replace( '{id}', rawurlencode( (string) ( $row['id'] ?? '' ) ), $action['url'] ) ) . '">' . esc_html( $action['label'] ) . '</a>';
				}
				$plain .= '<td>' . implode( ' | ', $links ) . '</td>';
			}
			$plain .= '</tr>';
		}//end foreach
		$plain .= '</tbody></table>';

		echo '<div class="packout-admin-view" data-packout-view="' . esc_attr( $key ) . '">';
		// The classic table is parsed next. Mark the view before that happens so
		// the first paint is not the previous table while the Data View script loads.
		if ( self::ready() ) {
			echo '<script>document.currentScript.parentNode.classList.add("is-scripted")</script>';
		}
		echo '<div class="packout-admin-view__mount" data-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '" hidden></div>';
		echo '<div class="packout-admin-view__plain">' . $plain . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped cells above.
		echo '</div>';
	}

	/**
	 * Operators Data Views can apply to a column. A primary filter is shown before the rest.
	 *
	 * @param string $type    text or integer.
	 * @param bool   $primary Whether the filter starts open.
	 * @return array<string, mixed>
	 */
	private static function filter_by( string $type, bool $primary ): array {
		$operators = 'integer' === $type
			? array( 'is', 'lessThan', 'greaterThan' )
			: array( 'is', 'isNot', 'contains' );
		return array(
			'operators' => $operators,
			'isPrimary' => $primary,
		);
	}
}
