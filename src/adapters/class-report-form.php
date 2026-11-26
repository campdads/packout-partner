<?php
/**
 * Report form.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner\Adapters;

use PackOut\Partner\Report_Form as Form;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers for the plugin's own report form.
 *
 * The form needs nothing set up: it is there as soon as the plugin is.
 */
class Report_Form implements \PackOut\Core\Contracts\Report_Form {

	/**
	 * Whether the form can be shown on this site.
	 */
	public function available(): bool {
		return true;
	}

	/**
	 * Markup for the form.
	 *
	 * @param array<string, mixed> $args `before_submit`: markup placed just before the button, already escaped.
	 */
	public function render( array $args = array() ): string {
		return Form::render( $args );
	}

	/**
	 * How the page's script finds the form's parts.
	 *
	 * @return array{latitude: string, longitude: string, photo: string, row: string}
	 */
	public function selectors(): array {
		return array(
			'latitude'  => 'input[name="' . Form::FIELD . '[latitude]"]',
			'longitude' => 'input[name="' . Form::FIELD . '[longitude]"]',
			'photo'     => 'input[name="' . Form::PHOTO . '"]',
			'row'       => '.packout-form__pair',
		);
	}

	/**
	 * The largest photo the form takes, in megabytes.
	 */
	public function photo_limit(): int {
		return \PackOut\Core\App\Photos::limit_mb();
	}

	/**
	 * Whether the form is ready, for the setup checks.
	 *
	 * @return array{ok: bool, detail: string}
	 */
	public function status(): array {
		return array(
			'ok'     => true,
			'detail' => __( 'The report form is the plugin’s own and needs no setup.', 'packout-partner' ),
		);
	}

	/**
	 * What the Settings screen shows for the form: nothing, since there is nothing to choose.
	 *
	 * @param array<string, mixed> $settings The application's settings.
	 * @return array{intro: string, rows: string}
	 */
	public function settings( array $settings ): array {
		unset( $settings );
		return array(
			'intro' => '',
			'rows'  => '',
		);
	}

	/**
	 * What a coordinator is told about the form's own record of a report: nothing. The form keeps only the Spot.
	 *
	 * @param int $entry The number the form gave the report.
	 */
	public function entry_note( int $entry ): string {
		unset( $entry );
		return '';
	}
}
