<?php
/**
 * Settings screen.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report form mapping, map defaults, duplicate radius, the celebration line,
 * and the offline map regions.
 */
class Settings_Screen {

	/**
	 * The settings form.
	 */
	public static function render(): void {
		Admin::guard();
		$settings = Settings::all();

		echo '<div class="wrap packout-admin">';
		echo '<h1>' . esc_html__( 'Pack-Out Spot settings', 'packout-core' ) . '</h1>';
		Admin::notice( array( 'settings' => __( 'Settings saved.', 'packout-core' ) ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-admin__settings">';
		echo '<input type="hidden" name="action" value="packout_save_settings" />';
		wp_nonce_field( 'packout_save_settings' );

		// The form's own choices are the plugin's: which stored form, and which of its fields holds each value.
		$form = App::kernel()->report_form()->settings( $settings );
		echo '<h2>' . esc_html__( 'Report a Spot form', 'packout-core' ) . '</h2>';
		echo (string) ( $form['intro'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The adapter escapes it.
		echo '<table class="form-table" role="presentation"><tbody>';
		echo (string) ( $form['rows'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The adapter escapes it.

		echo '<tr><th scope="row"><label for="packout-report-page">' . esc_html__( 'Report page', 'packout-core' ) . '</label></th><td>';
		wp_dropdown_pages(
			array(
				'name'              => 'report_page',
				'id'                => 'packout-report-page',
				'selected'          => (int) $settings['report_page'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer.
				'show_option_none'  => esc_html__( 'None', 'packout-core' ),
				'option_none_value' => '0',
			)
		);
		echo '<p class="description">' . esc_html__( 'The Report prompt block and the empty Spots list link here.', 'packout-core' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="packout-default-category">' . esc_html__( 'Default category', 'packout-core' ) . '</label></th><td><select id="packout-default-category" name="default_category">';
		foreach ( Schema::categories() as $slug => $name ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $settings['default_category'], $slug, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></td></tr></tbody></table>';

		echo '<h2>' . esc_html__( 'Accounts and organizations', 'packout-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'People sign up on the page with the Sign up block, sign in on the page with the Sign in block, and run their organizations on the page with the Organization workspace block. With no sign-up page chosen, nobody can sign up and leads cannot send invitations. With no sign-in page chosen, people sign in on the WordPress sign-in screen.', 'packout-core' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( array(
			'signup_page'    => array( __( 'Sign-up page', 'packout-core' ), __( 'The page that holds the Sign up block.', 'packout-core' ) ),
			'signin_page'    => array( __( 'Sign-in page', 'packout-core' ), __( 'The page that holds the Sign in block. People sign in, sign out, and choose a new password here, and stay on the site.', 'packout-core' ) ),
			'workspace_page' => array( __( 'My organizations page', 'packout-core' ), __( 'The page that holds the Organization workspace block. Everyone lands here after signing in on the Sign-in page, administrators included, unless they were on their way to another page. A person who opens a dashboard address signs in on the WordPress screen and is returned to the dashboard.', 'packout-core' ) ),
			'account_page'   => array( __( 'My account page', 'packout-core' ), __( 'The page that holds the My account block set to the overview. Pages under it that hold the block set to one part (personal info, my impact, my contributions, email preferences, or sign-in details) are found by themselves. Without them, every part is shown on this page. The link that stops an organization\'s messages opens here.', 'packout-core' ) ),
		) as $name => $text ) {
			echo '<tr><th scope="row"><label for="packout-' . esc_attr( $name ) . '">' . esc_html( $text[0] ) . '</label></th><td>';
			wp_dropdown_pages(
				array(
					'name'              => $name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed name.
					'id'                => 'packout-' . $name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed name.
					'selected'          => (int) $settings[ $name ], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer.
					'show_option_none'  => esc_html__( 'None', 'packout-core' ),
					'option_none_value' => '0',
				)
			);
			echo '<p class="description">' . esc_html( $text[1] ) . '</p></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Review', 'packout-core' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="packout-radius">' . esc_html__( 'Duplicate radius', 'packout-core' ) . '</label></th><td>';
		echo '<input id="packout-radius" type="number" min="5" max="1000" name="duplicate_radius" value="' . esc_attr( (string) $settings['duplicate_radius'] ) . '" class="small-text" /> ' . esc_html__( 'meters', 'packout-core' );
		echo '<p class="description">' . esc_html__( 'Open Spots in the same category this close to a new pin are shown as possible duplicates.', 'packout-core' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="packout-celebration">' . esc_html__( 'Line shown when a Spot is cleaned', 'packout-core' ) . '</label></th><td><select id="packout-celebration" name="celebration">';
		echo '<option value="public"' . selected( $settings['celebration'], 'public', false ) . '>' . esc_html__( 'Spot it. Pack it out. Leave it better.', 'packout-core' ) . '</option>';
		echo '<option value="pos"' . selected( $settings['celebration'], 'pos', false ) . '>' . esc_html__( 'One Less POS on the Trail.', 'packout-core' ) . '</option>';
		echo '</select></td></tr></tbody></table>';

		echo '<h2>' . esc_html__( 'Map', 'packout-core' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="packout-style">' . esc_html__( 'Base map style', 'packout-core' ) . '</label></th><td>';
		echo '<input id="packout-style" type="url" name="map_style" class="large-text" value="' . esc_attr( $settings['map_style'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'A MapLibre style address, used when the device is online.', 'packout-core' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Opening view', 'packout-core' ) . '</th><td class="packout-admin__inline">';
		foreach ( array(
			'map_lat'  => __( 'Latitude', 'packout-core' ),
			'map_lng'  => __( 'Longitude', 'packout-core' ),
			'map_zoom' => __( 'Zoom', 'packout-core' ),
		) as $name => $label ) {
			echo '<label>' . esc_html( $label ) . ' <input type="number" step="any" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $settings[ $name ] ) . '" class="small-text" /></label>';
		}
		echo '<p class="description">' . esc_html__( 'Used when there are no Spots to fit the map to.', 'packout-core' ) . '</p></td></tr></tbody></table>';

		echo '<h2>' . esc_html__( 'Offline map regions', 'packout-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'A region is a name, a bounding box, and the address of a PMTiles package. Reporters download a region in the field app before they leave coverage. Clear the name to remove a region.', 'packout-core' ) . '</p>';
		echo '<table class="widefat packout-admin__regions"><thead><tr>';
		foreach ( array( __( 'Name', 'packout-core' ), __( 'West', 'packout-core' ), __( 'South', 'packout-core' ), __( 'East', 'packout-core' ), __( 'North', 'packout-core' ), __( 'Package address', 'packout-core' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$rows = array_merge( $settings['regions'], array( array(), array() ) );
		foreach ( $rows as $index => $region ) {
			echo '<tr><td><input type="hidden" name="regions[' . (int) $index . '][id]" value="' . esc_attr( (string) ( $region['id'] ?? '' ) ) . '" />';
			echo '<input name="regions[' . (int) $index . '][name]" value="' . esc_attr( (string) ( $region['name'] ?? '' ) ) . '" aria-label="' . esc_attr__( 'Region name', 'packout-core' ) . '" /></td>';
			foreach ( array( 'west', 'south', 'east', 'north' ) as $edge ) {
				echo '<td><input type="number" step="any" name="regions[' . (int) $index . '][' . esc_attr( $edge ) . ']" value="' . esc_attr( isset( $region[ $edge ] ) ? (string) $region[ $edge ] : '' ) . '" class="small-text" aria-label="' . esc_attr( ucfirst( $edge ) ) . '" /></td>';
			}
			echo '<td><input type="url" name="regions[' . (int) $index . '][package]" value="' . esc_attr( (string) ( $region['package'] ?? '' ) ) . '" class="large-text" aria-label="' . esc_attr__( 'Package address', 'packout-core' ) . '" /></td></tr>';
		}
		echo '</tbody></table>';

		submit_button( __( 'Save settings', 'packout-core' ) );
		echo '</form></div>';
	}

	/**
	 * Save the settings form.
	 */
	public static function save(): void {
		Admin::guard();
		check_admin_referer( 'packout_save_settings' );

		$input = array();
		foreach ( array( 'form', 'report_page', 'signup_page', 'signin_page', 'workspace_page', 'account_page', 'default_category', 'duplicate_radius', 'celebration', 'map_style', 'map_lat', 'map_lng', 'map_zoom' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$input[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		foreach ( array( 'form_keys', 'regions' ) as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings::sanitize() cleans every value.
			$input[ $key ] = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
		}

		Settings::save( $input );
		wp_safe_redirect( admin_url( 'admin.php?page=packout-settings&updated=settings' ) );
		exit;
	}
}
