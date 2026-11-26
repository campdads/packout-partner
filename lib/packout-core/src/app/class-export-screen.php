<?php
/**
 * Export screen.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a person with the export capability downloads Spots and impact
 * records for a date range and an optional Place.
 */
class Export_Screen {

	public const PAGE = 'packout-export';

	/**
	 * The form.
	 */
	public static function render(): void {
		Admin::require( Export::can() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preselects a Place from a link.
		$chosen = isset( $_GET['place'] ) ? (int) $_GET['place'] : 0;
		$places = Places::all();

		echo '<div class="wrap packout-admin">';
		echo '<h1>' . esc_html__( 'Export', 'packout-core' ) . '</h1>';
		echo '<p class="packout-admin__lede">' . esc_html__( 'Download records to hand to a partner. Choose a date range and a Place, or leave them empty for everything.', 'packout-core' ) . '</p>';
		Admin::notice( array(), array() );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="packout-export">';
		echo '<input type="hidden" name="action" value="packout_export" />';
		wp_nonce_field( 'packout_export' );
		echo '<p class="packout-admin__filters">';
		echo '<label>' . esc_html__( 'From', 'packout-core' ) . ' <input type="date" name="from" /></label> ';
		echo '<label>' . esc_html__( 'To', 'packout-core' ) . ' <input type="date" name="to" /></label> ';
		echo '<label>' . esc_html__( 'Place', 'packout-core' ) . ' <select name="place"><option value="0">' . esc_html__( 'Every Place, and Spots in none', 'packout-core' ) . '</option>';
		foreach ( $places as $place ) {
			echo '<option value="' . esc_attr( (string) $place->ID ) . '"' . selected( $chosen, $place->ID, false ) . '>' . esc_html( $place->post_title ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p class="description">' . esc_html__( 'The dates apply to the day a Spot was reported, and to the date of an impact record.', 'packout-core' ) . '</p>';

		echo '<table class="widefat striped packout-admin__table packout-export__files"><thead><tr><th scope="col">' . esc_html__( 'File', 'packout-core' ) . '</th><th scope="col">' . esc_html__( 'What is in it', 'packout-core' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Download', 'packout-core' ) . '</span></th></tr></thead><tbody>';
		$about           = array(
			'spots_public'   => __( 'Published Spots as the public map has them. A Spot the public sees approximately is at its approximate point. No photos, and nothing of a clearance beyond the public line. Safe to share.', 'packout-core' ),
			'spots_internal' => __( 'Every reviewed and waiting Spot with its exact pin, review state, landmark, area note, full description, clearance, and links to its photos. Share it only with someone who may know exact locations.', 'packout-core' ),
			'impact'         => __( 'One row for each impact record: date, Spot, Place, organization, hours, people, bags, and pounds, with whether each figure was measured or self-reported, whether the record is verified, and whether it is counted publicly.', 'packout-core' ),
		);
		$about['people'] = __( 'Everyone who asked for news from Pack-Out Spot, and everyone who let Pack-Out Spot use their mailing address, with that address and phone number. The dates and the Place do not apply. It holds personal details: keep it to the people who send the news and the mail.', 'packout-core' );
		foreach ( Export::kinds() as $kind => $label ) {
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . esc_html( $about[ $kind ] ) . '</td>';
			echo '<td><button class="button" name="what" value="' . esc_attr( $kind ) . '">' . esc_html__( 'Download', 'packout-core' ) . '</button></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'The Spot and impact files name no reporter or crew member. A photo link opens the photo in this site\'s media library.', 'packout-core' ) . '</p>';
		echo '</form></div>';
	}

	/**
	 * Send one file.
	 */
	public static function download(): void {
		Admin::require( Export::can() );
		check_admin_referer( 'packout_export' );
		$file = Export::file(
			isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : '',
			array(
				'from'  => isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '',
				'to'    => isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '',
				'place' => isset( $_POST['place'] ) ? (int) $_POST['place'] : 0,
			)
		);
		if ( is_wp_error( $file ) ) {
			Admin::back(
				self::PAGE,
				array(
					'error'  => 'detail',
					'detail' => $file->get_error_message(),
				)
			);
		}

		nocache_headers();
		header( 'Content-Type: ' . $file['type'] );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $file['name'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $file['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A file download: JSON or CSV, sent as an attachment.
		exit;
	}
}
