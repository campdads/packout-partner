<?php
/**
 * The form a visitor reports a Spot with.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner;

use PackOut\Core\App\Photos;
use PackOut\Core\App\Schema;
use PackOut\Core\App\Spots;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws the report form and turns a submission into one pending Spot.
 *
 * The form is the plugin's own: it needs no form plugin. It asks what the hub
 * asks, and it keeps nothing but the Spot. A report is checked four ways
 * before it becomes one: a nonce, a field a person never sees and a script
 * fills in, the time the form was open, and how many reports the same address
 * has sent in the last ten minutes.
 */
class Report_Form {

	/**
	 * The name of the form's fields, and of its nonce.
	 */
	public const FIELD = 'packout_report';

	/**
	 * The name of the photo's file input.
	 */
	public const PHOTO = 'packout_report_photo';

	/**
	 * The field a person never sees. A script that fills in every field fills this one.
	 */
	private const TRAP = 'packout_report_website';

	/**
	 * How long a person takes, at the least, to fill in the form.
	 */
	private const SECONDS = 3;

	/**
	 * How many reports one address may send in ten minutes: a visitor, then a person who is signed in.
	 */
	private const LIMITS = array( 5, 20 );

	/**
	 * The option that counts the reports the form has taken.
	 */
	public const COUNT = 'packout_partner_reports';

	/**
	 * Why the last submission was refused, or null.
	 *
	 * @var \WP_Error|null
	 */
	private static $refused = null;

	/**
	 * What the refused submission held, so the form can show it again.
	 *
	 * @var array<string, string>
	 */
	private static $held = array();

	/**
	 * Listen for a submission.
	 */
	public static function register(): void {
		// After the record types are registered, and before a page is drawn.
		add_action( 'template_redirect', array( self::class, 'handle' ), 5 );
	}

	/**
	 * The form.
	 *
	 * @param array<string, mixed> $args `before_submit`: markup placed just before the button, already escaped.
	 */
	public static function render( array $args = array() ): string {
		wp_enqueue_style( 'packout-partner-report', plugins_url( 'assets/css/report-form.css', PACKOUT_PARTNER_FILE ), array(), PACKOUT_PARTNER_VERSION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Says a report was sent. Nothing is done with it.
		if ( isset( $_GET['packout_reported'] ) && null === self::$refused ) {
			return '<div class="packout-form packout-form--done" id="packout-report" role="status"><p>' . esc_html__( 'Thank you. Your report is in the review queue. A coordinator checks it before it appears on the map. Reporting is the whole job: you have not signed up to clean it.', 'packout-partner' ) . '</p></div>';
		}

		$value = static fn( string $key ): string => self::$held[ $key ] ?? '';
		$id    = static fn( string $key ): string => 'packout-report-' . $key;
		$name  = static fn( string $key ): string => self::FIELD . '[' . $key . ']';
		$row   = static function ( string $key, string $label, string $control, string $help = '', bool $required = false, bool $half = false ) use ( $id ): string {
			$html  = '<p class="packout-form__field' . ( $half ? ' packout-form__field--half' : '' ) . '">';
			$html .= '<label for="' . esc_attr( $id( $key ) ) . '">' . esc_html( $label );
			$html .= $required ? ' <span class="packout-form__required">' . esc_html__( '(required)', 'packout-partner' ) . '</span>' : '';
			$html .= '</label>' . $control;
			$html .= '' !== $help ? '<span class="packout-form__help" id="' . esc_attr( $id( $key ) . '-help' ) . '">' . esc_html( $help ) . '</span>' : '';
			return $html . '</p>';
		};
		$help  = static fn( string $key ): string => ' aria-describedby="' . esc_attr( $id( $key ) . '-help' ) . '"';

		$html = '<form class="packout-form" id="packout-report" method="post" enctype="multipart/form-data" action="' . esc_url( self::address() ) . '#packout-report">';
		if ( self::$refused ) {
			$html .= '<div class="packout-form__problem" role="alert"><p>' . esc_html( self::$refused->get_error_message() ) . '</p></div>';
		}
		$html .= wp_nonce_field( self::FIELD, self::FIELD . '_nonce', true, false );
		$html .= '<input type="hidden" name="' . esc_attr( self::FIELD . '_opened' ) . '" value="' . esc_attr( self::stamp( time() ) ) . '" />';
		// Out of sight and out of the tab order. A person leaves it empty.
		$html .= '<p class="packout-form__website" aria-hidden="true"><label for="packout-report-website">' . esc_html__( 'Leave this field empty', 'packout-partner' ) . '</label><input type="text" id="packout-report-website" name="' . esc_attr( self::TRAP ) . '" value="" tabindex="-1" autocomplete="off" /></p>';

		$options = '<option value="">' . esc_html__( 'Choose one', 'packout-partner' ) . '</option>';
		foreach ( Schema::categories() as $slug => $label ) {
			$options .= '<option value="' . esc_attr( $slug ) . '"' . selected( $value( 'category' ), $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= $row( 'category', __( 'What kind of problem is it?', 'packout-partner' ), '<select id="' . esc_attr( $id( 'category' ) ) . '" name="' . esc_attr( $name( 'category' ) ) . '" required>' . $options . '</select>', '', true );
		$html .= $row(
			'description',
			__( 'What is there?', 'packout-partner' ),
			'<textarea id="' . esc_attr( $id( 'description' ) ) . '" name="' . esc_attr( $name( 'description' ) ) . '" rows="5" required' . $help( 'description' ) . '>' . esc_textarea( $value( 'description' ) ) . '</textarea>',
			__( 'What you found and why it needs attention.', 'packout-partner' ),
			true
		);
		$html .= $row(
			'landmark',
			__( 'Where is it?', 'packout-partner' ),
			'<input type="text" id="' . esc_attr( $id( 'landmark' ) ) . '" name="' . esc_attr( $name( 'landmark' ) ) . '" value="' . esc_attr( $value( 'landmark' ) ) . '" maxlength="200" required' . $help( 'landmark' ) . ' />',
			__( 'A landmark, a road or trail name, or directions someone could follow.', 'packout-partner' ),
			true
		);
		$html .= $row(
			'photo',
			__( 'Photo', 'packout-partner' ),
			'<input type="file" id="' . esc_attr( $id( 'photo' ) ) . '" name="' . esc_attr( self::PHOTO ) . '" accept="' . esc_attr( Photos::accept() ) . '"' . $help( 'photo' ) . ' />',
			Photos::hint()
		);
		$html .= '<div class="packout-form__pair">';
		$html .= $row(
			'latitude',
			__( 'Latitude', 'packout-partner' ),
			'<input type="number" id="' . esc_attr( $id( 'latitude' ) ) . '" name="' . esc_attr( $name( 'latitude' ) ) . '" value="' . esc_attr( $value( 'latitude' ) ) . '" min="-90" max="90" step="any" inputmode="decimal"' . $help( 'latitude' ) . ' />',
			__( 'Optional. For example 35.1712.', 'packout-partner' ),
			false,
			true
		);
		$html .= $row(
			'longitude',
			__( 'Longitude', 'packout-partner' ),
			'<input type="number" id="' . esc_attr( $id( 'longitude' ) ) . '" name="' . esc_attr( $name( 'longitude' ) ) . '" value="' . esc_attr( $value( 'longitude' ) ) . '" min="-180" max="180" step="any" inputmode="decimal"' . $help( 'longitude' ) . ' />',
			__( 'Optional. For example -111.7301.', 'packout-partner' ),
			false,
			true
		);
		$html .= '</div>';
		if ( ! is_user_logged_in() ) {
			$html .= $row(
				'name',
				__( 'Your name', 'packout-partner' ),
				'<input type="text" id="' . esc_attr( $id( 'name' ) ) . '" name="' . esc_attr( $name( 'name' ) ) . '" value="' . esc_attr( $value( 'name' ) ) . '" maxlength="60" autocomplete="name"' . $help( 'name' ) . ' />',
				__( 'Optional. It is kept only if you ask for it to be shown.', 'packout-partner' )
			);
		}

		$html .= (string) ( $args['before_submit'] ?? '' );
		$html .= '<p class="packout-form__submit"><button type="submit" class="wp-element-button">' . esc_html__( 'Send report', 'packout-partner' ) . '</button></p>';
		return $html . '</form>';
	}

	/**
	 * Take a submission.
	 */
	public static function handle(): void {
		if ( ! isset( $_POST[ self::FIELD . '_nonce' ] ) || 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compared, not used.
			return;
		}
		$fields = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked just below; each value is cleaned as it is read.
		$text   = static fn( string $key ): string => isset( $fields[ $key ] ) && is_scalar( $fields[ $key ] ) ? trim( sanitize_text_field( (string) $fields[ $key ] ) ) : '';

		self::$held = array(
			'category'    => sanitize_title( $text( 'category' ) ),
			'description' => isset( $fields['description'] ) && is_scalar( $fields['description'] ) ? trim( sanitize_textarea_field( (string) $fields['description'] ) ) : '',
			'landmark'    => mb_substr( $text( 'landmark' ), 0, 200 ),
			'latitude'    => $text( 'latitude' ),
			'longitude'   => $text( 'longitude' ),
			'name'        => mb_substr( $text( 'name' ), 0, 60 ),
		);

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::FIELD . '_nonce' ] ) ), self::FIELD ) ) {
			self::$refused = new \WP_Error( 'packout_report_expired', __( 'This page was open too long. Send the report again.', 'packout-partner' ) );
			return;
		}

		// A script that filled in the hidden field, or that sent the form the moment it loaded, is told what a person is told.
		$opened = self::opened( isset( $_POST[ self::FIELD . '_opened' ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD . '_opened' ] ) ) : '' );
		$trap   = isset( $_POST[ self::TRAP ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ self::TRAP ] ) ) ) : '';
		if ( '' !== $trap || 0 === $opened || time() - $opened < self::SECONDS ) {
			/**
			 * A report was dropped because it looked like it came from a script.
			 *
			 * @param string $reason `trap` or `speed`.
			 */
			do_action( 'packout_partner_report_dropped', '' !== $trap ? 'trap' : 'speed' );
			self::done();
		}

		if ( ! self::within_limit() ) {
			self::$refused = new \WP_Error( 'packout_report_limit', __( 'Several reports have just come from this connection. Wait ten minutes and send this one again.', 'packout-partner' ) );
			return;
		}

		foreach ( array( 'category', 'description', 'landmark' ) as $needed ) {
			if ( '' === self::$held[ $needed ] ) {
				self::$refused = new \WP_Error( 'packout_report_missing', __( 'Say what kind of problem it is, what is there, and where it is.', 'packout-partner' ) );
				return;
			}
		}

		$photo = self::photo();
		if ( is_wp_error( $photo ) ) {
			self::$refused = $photo;
			return;
		}

		$input = array(
			'category'       => self::$held['category'],
			'description'    => self::$held['description'],
			'landmark'       => self::$held['landmark'],
			'photos_pending' => $photo ? 1 : 0,
		);
		if ( '' !== self::$held['latitude'] && '' !== self::$held['longitude'] ) {
			$input['lat'] = self::$held['latitude'];
			$input['lng'] = self::$held['longitude'];
		}

		// Who the reporter asked the Spot's page to credit. The Report block adds these two questions.
		$team  = isset( $_POST['packout_credit_team'] ) ? (int) $_POST['packout_credit_team'] : null;
		$named = ! empty( $_POST['packout_credit_named'] );
		$entry = (int) get_option( self::COUNT, 0 ) + 1;

		$id = Spots::create(
			$input,
			get_current_user_id(),
			array(
				'entry_id'      => $entry,
				'credit_team'   => $team,
				'credit_named'  => $named,
				'reporter_name' => self::$held['name'],
			)
		);
		if ( is_wp_error( $id ) ) {
			self::$refused = $id;
			/**
			 * A report could not become a Spot. The person is shown why, with what they wrote.
			 *
			 * @param \WP_Error            $id    Why the Spot was refused.
			 * @param array<string, mixed> $input What the report held.
			 */
			do_action( 'packout_report_rejected', $id, $input );
			return;
		}
		update_option( self::COUNT, $entry, false );
		self::count();

		if ( $photo ) {
			// The Spot stands without its photo: the report said where the place is.
			Photos::attach_upload( (int) $id, $photo );
		}
		self::done();
	}

	/**
	 * The photo that was sent, or null when none was, or why it cannot be taken.
	 *
	 * @return array<string, mixed>|\WP_Error|null
	 */
	private static function photo() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- handle() checked the nonce. The file is read by Photos, which checks what it is.
		$file = isset( $_FILES[ self::PHOTO ] ) && is_array( $_FILES[ self::PHOTO ] ) ? $_FILES[ self::PHOTO ] : null;
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || UPLOAD_ERR_NO_FILE === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return null;
		}
		$limit = Photos::limit_mb();
		$large = in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || (int) ( $file['size'] ?? 0 ) > $limit * MB_IN_BYTES;
		if ( $large ) {
			/* translators: %d: number of megabytes. */
			return new \WP_Error( 'packout_report_photo', sprintf( __( 'That photo is larger than %d MB. Choose a smaller one, or send the report without a photo.', 'packout-partner' ), $limit ) );
		}
		$path = (string) ( $file['tmp_name'] ?? '' );
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $path ) ) {
			return new \WP_Error( 'packout_report_photo', __( 'The photo did not arrive. Choose it again, or send the report without a photo.', 'packout-partner' ) );
		}
		if ( '' === Photos::type_of( $path ) ) {
			return new \WP_Error( 'packout_report_photo', __( 'That file is not a photo this site can read. Choose another, or send the report without a photo.', 'packout-partner' ) );
		}
		return $file;
	}

	/**
	 * Show the page again, saying the report was sent.
	 *
	 * @return never
	 */
	private static function done(): void {
		wp_safe_redirect( add_query_arg( 'packout_reported', '1', self::address() ) . '#packout-report', 303 );
		exit;
	}

	/**
	 * The page the form is on, without what a submission adds to it.
	 */
	private static function address(): string {
		$page = get_queried_object_id();
		$url  = $page > 0 ? (string) get_permalink( $page ) : '';
		return remove_query_arg( 'packout_reported', '' !== $url ? $url : home_url( '/' ) );
	}

	/**
	 * A time, signed so that a script cannot write its own.
	 *
	 * @param int $time When the form was drawn.
	 */
	private static function stamp( int $time ): string {
		return $time . '.' . wp_hash( 'packout-report|' . $time );
	}

	/**
	 * When the form was drawn, or 0 when the value is not one this site wrote in the last day.
	 *
	 * @param string $stamp What the form sent back.
	 */
	private static function opened( string $stamp ): int {
		$parts = explode( '.', $stamp, 2 );
		$time  = 2 === count( $parts ) && ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
		if ( $time <= 0 || ! hash_equals( wp_hash( 'packout-report|' . $time ), $parts[1] ) ) {
			return 0;
		}
		return $time <= time() && time() - $time <= DAY_IN_SECONDS ? $time : 0;
	}

	/**
	 * The name the address's count is kept under. The address itself is not stored.
	 */
	private static function key(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'packout_report_' . substr( wp_hash( 'packout-report-from|' . $address ), 0, 20 );
	}

	/**
	 * Whether this address may send another report now.
	 */
	private static function within_limit(): bool {
		$limit = self::LIMITS[ is_user_logged_in() ? 1 : 0 ];
		/**
		 * How many reports one address may send in ten minutes.
		 *
		 * @param int  $limit     The number.
		 * @param bool $signed_in Whether the person is signed in.
		 */
		$limit = (int) apply_filters( 'packout_partner_report_limit', $limit, is_user_logged_in() );
		return (int) get_transient( self::key() ) < $limit;
	}

	/**
	 * Count a report against its address.
	 */
	private static function count(): void {
		set_transient( self::key(), (int) get_transient( self::key() ) + 1, 10 * MINUTE_IN_SECONDS );
	}
}
