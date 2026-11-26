<?php
/**
 * The public page of a map feature.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A map feature opens the way a Spot does. The header carries the photo, the
 * note, the kind, and the sponsors. Below it are the location and the photos,
 * who adopted the Place it belongs to, and the activity. The person who added
 * it, and a coordinator, can edit it from the bottom of the page. Any other
 * reporter can suggest a change, which is sent to the coordinators.
 */
class Feature_Page {

	public const TYPE  = 'pos_suggestion';
	public const NONCE = 'packout_feature_page';

	/**
	 * Longest suggestion, in characters.
	 */
	public const LENGTH = 1000;

	/**
	 * Register the hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'type' ), 9 );
		add_action( 'init', array( self::class, 'handle' ), 31 );
		add_action( 'before_delete_post', array( self::class, 'deleted' ) );
		add_filter( 'the_content', array( self::class, 'content' ) );
	}

	/**
	 * A suggestion has no page of its own.
	 */
	public static function type(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Feature suggestions', 'packout-core' ),
					'singular_name' => __( 'Feature suggestion', 'packout-core' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'supports'            => array( 'editor', 'author', 'title' ),
				'delete_with_user'    => true,
			)
		);
	}

	/**
	 * Build the page.
	 *
	 * @param string $content Post content.
	 */
	public static function content( $content ) {
		if ( ! is_singular( Schema::FEATURE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return $content;
		}
		wp_enqueue_style( 'packout-workspace' );
		wp_enqueue_script( 'packout-workspace' );
		wp_enqueue_style( 'packout-blocks' );

		$photos = Features::gallery( $post );
		$kind   = Features::kinds()[ Features::kind( $post->ID ) ] ?? '';
		$date   = mysql2date( get_option( 'date_format' ), $post->post_date );
		$place  = Features::place( $post->ID );
		$held   = $place ? Places::get( $place ) : null;
		$linked = ( $held && 'publish' === $held->post_status )
			? '<a href="' . esc_url( (string) get_permalink( $held ) ) . '">' . esc_html( $held->post_title ) . '</a>'
			: '';
		$who    = get_the_author_meta( 'display_name', (int) $post->post_author );

		$html  = '<div class="pos-spot-page pos-place-page pos-place pos-event">';
		$html .= Page_Header::render(
			array(
				'cover'       => $photos ? (int) $photos[0]['id'] : 0,
				'kind'        => $kind,
				'title'       => $post->post_title,
				'description' => trim( wp_strip_all_tags( $post->post_content ) ),
				'sponsors'    => Sponsors::all( $post->ID ),
				'facts'       => array(
					/* translators: %s: the day the feature was added. */
					'<span class="pos-spot-page__reported">' . sprintf( esc_html__( 'Added %s', 'packout-core' ), esc_html( $date ) ) . '</span>',
					'' !== $who ? '<span class="pos-spot-page__by">' . esc_html( $who ) . '</span>' : '',
					$linked,
				),
			)
		);
		$html .= '<div class="pos-place__body">';
		$html .= self::notices();
		$html .= self::detail( $post, $photos );
		$html .= self::adoption( $post );
		$html .= self::activity( $post );
		$html .= self::suggest_form( $post );
		$html .= self::edit( $post, $kind );
		return $html . '</div></div>';
	}

	/**
	 * Where the feature is, beside its photos.
	 *
	 * @param \WP_Post                         $post   Feature.
	 * @param array<int, array<string, mixed>> $photos From Features::gallery().
	 */
	private static function detail( \WP_Post $post, array $photos ): string {
		$where = Features::location( $post->ID );
		$html  = '<section class="pos-place__section pos-spot-page__detail"><div class="pos-spot-page__split">';
		$html .= '<div class="pos-spot-page__about">';
		if ( '' !== $where['text'] ) {
			$html .= '<dl class="pos-event__facts pos-spot-page__facts">';
			$html .= '<dt>' . esc_html( $where['label'] ) . '</dt><dd><span class="pos-spot-page__location">' . esc_html( $where['text'] ) . '</span></dd>';
			$html .= '</dl>';
		}
		if ( Updates::open( $post ) ) {
			$html .= '<p class="pos-spot-page__actions pos-ws"><a class="pos-ws__button" href="#pos-update">' . esc_html__( 'Share an update', 'packout-core' ) . '</a></p>';
		}
		$html .= '</div>';

		$html .= '<div class="pos-spot-page__photos">';
		if ( $photos ) {
			wp_enqueue_script( 'packout-slider' );
			$html .= Spot_Page::slider( $photos, $post->post_title );
		} else {
			$html .= '<div class="pos-spot-page__no-photos"><p>' . esc_html__( 'No photos of this feature are shown.', 'packout-core' ) . '</p></div>';
		}
		return $html . '</div></div></section>';
	}

	/**
	 * The organizations that adopted the Place this feature belongs to, and a
	 * way for a member to ask.
	 *
	 * @param \WP_Post $post Feature.
	 */
	private static function adoption( \WP_Post $post ): string {
		$place_id = Features::place( $post->ID );
		$place    = $place_id ? Places::get( $place_id ) : null;
		$listed   = $place && 'publish' === $place->post_status;
		$html     = '<section class="pos-place__section" id="pos-adopt"><h2>' . esc_html__( 'Adopted by', 'packout-core' ) . '</h2>';
		if ( ! $listed ) {
			return $html . '<p>' . esc_html__( 'This feature is not linked to a Place. Adoption is of a Place, so there is nothing to adopt yet.', 'packout-core' ) . '</p></section>';
		}

		$shown = Places::public_payload( $place );
		if ( ! $shown['adopters'] ) {
			$html .= '<p>' . esc_html__( 'No organization has adopted this Place yet.', 'packout-core' ) . '</p>';
		} else {
			$html .= '<ul class="pos-place__adopters">';
			foreach ( $shown['adopters'] as $adopter ) {
				$named = '' !== $adopter['team'];
				$html .= '<li class="pos-place__adopter"><div class="pos-place__adopter-text"><h3>';
				$html .= $named && '' !== $adopter['url']
					? '<a href="' . esc_url( $adopter['url'] ) . '">' . esc_html( $adopter['team'] ) . '</a>'
					: esc_html( $named ? $adopter['team'] : __( 'An organization that is not listed', 'packout-core' ) );
				$html .= '</h3>';
				if ( '' !== $adopter['since'] ) {
					/* translators: %s: date. */
					$html .= '<p class="pos-place__since">' . esc_html( sprintf( __( 'Since %s', 'packout-core' ), Schema::span( $adopter['since'], '' ) ) ) . '</p>';
				}
				if ( '' !== $adopter['note'] ) {
					$html .= '<p class="pos-place__promise">' . esc_html( $adopter['note'] ) . '</p>';
				}
				$html .= '</div></li>';
			}
			$html .= '</ul>';
			$html .= '<p class="pos-place-page__note">' . esc_html__( 'Adopting a Place is a promise to keep watching it. It transfers no ownership and none of the authority of whoever manages the land.', 'packout-core' ) . '</p>';
		}//end if

		$user  = get_current_user_id();
		$teams = array();
		if ( $user > 0 ) {
			foreach ( Teams::of_user( $user ) as $team_id ) {
				$team = Teams::get( $team_id );
				if ( $team && Teams::active( $team_id ) && Teams::includes( $team_id, $user ) ) {
					$teams[ $team_id ] = $team->post_title;
				}
			}
		}
		if ( $user <= 0 ) {
			$html .= '<p class="pos-ws__actions"><a class="pos-ws__button" href="' . esc_url( Accounts::signin_url( get_permalink( $post ) . '#pos-adopt' ) ) . '">' . esc_html__( 'Sign in to ask an organization to adopt this Place', 'packout-core' ) . '</a></p>';
		} elseif ( ! $teams ) {
			$html .= '<p>' . esc_html__( 'Join an organization to ask it to adopt this Place.', 'packout-core' ) . '</p>';
		} else {
			$html .= '<form method="post" class="pos-ws__form pos-ws"><input type="hidden" name="packout_feature_page" value="adopt" /><input type="hidden" name="feature" value="' . esc_attr( (string) $post->ID ) . '" />' . wp_nonce_field( self::NONCE, '_posnonce', false, false );
			$html .= '<p class="pos-ws__help">' . esc_html__( 'The organization’s lead sees the request under Submissions. Accepting it records the adoption.', 'packout-core' ) . '</p>';
			$id    = wp_unique_id( 'pos-adopt-' );
			$html .= '<p class="pos-ws__field"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Organization', 'packout-core' ) . '</label><select id="' . esc_attr( $id ) . '" name="team">';
			foreach ( $teams as $team_id => $name ) {
				$html .= '<option value="' . esc_attr( (string) $team_id ) . '">' . esc_html( $name ) . '</option>';
			}
			$html .= '</select></p>';
			$note  = wp_unique_id( 'pos-adopt-note-' );
			$html .= '<p class="pos-ws__field"><label for="' . esc_attr( $note ) . '">' . esc_html__( 'Note', 'packout-core' ) . '</label><input id="' . esc_attr( $note ) . '" name="note" type="text" maxlength="300" /></p>';
			$html .= Workspace::submit( __( 'Send the request', 'packout-core' ) ) . '</form>';
		}
		return $html . '</section>';
	}

	/**
	 * What has happened, newest first, and the place to add an update.
	 *
	 * @param \WP_Post $post Feature.
	 */
	private static function activity( \WP_Post $post ): string {
		$html  = '<section class="pos-place__section pos-activity pos-ws" id="pos-activity"><h2>' . esc_html__( 'Activity', 'packout-core' ) . '</h2>';
		$html .= Updates::notices();
		$html .= '<ol class="pos-activity__list">';
		$who   = get_the_author_meta( 'display_name', (int) $post->post_author );
		$day   = substr( $post->post_date, 0, 10 );
		$html .= '<li class="pos-activity__item pos-activity__item--report">';
		/* translators: %s: a person's name. */
		$html .= '<p class="pos-activity__head"><span class="pos-activity__what">' . sprintf( esc_html__( 'Added by %s', 'packout-core' ), esc_html( $who ) ) . '</span> <time class="pos-activity__when" datetime="' . esc_attr( $day ) . '">' . esc_html( Schema::span( $day, $day ) ) . '</time></p></li>';
		foreach ( Updates::of( $post->ID ) as $update ) {
			$when  = substr( $update->post_date, 0, 10 );
			$html .= '<li class="pos-activity__item pos-activity__item--update" id="pos-update-' . esc_attr( (string) $update->ID ) . '">';
			/* translators: %s: a person's or an organization's name. */
			$html .= '<p class="pos-activity__head"><span class="pos-activity__what">' . sprintf( esc_html__( 'Update from %s', 'packout-core' ), Credit::html( Updates::from( $update ) ) ) . '</span> <time class="pos-activity__when" datetime="' . esc_attr( $when ) . '">' . esc_html( Schema::span( $when, $when ) ) . '</time></p>';
			$html .= '<div class="pos-activity__detail">' . wpautop( esc_html( $update->post_content ) ) . '</div>';
			$html .= Updates::remove_form( $update ) . '</li>';
		}
		$html .= '</ol>';
		return $html . Updates::form( $post ) . '</section>';
	}

	/**
	 * A reporter who cannot edit the feature can tell the coordinators what should change.
	 *
	 * @param \WP_Post $post Feature.
	 */
	private static function suggest_form( \WP_Post $post ): string {
		if ( Features::can_edit( $post->ID ) ) {
			return '';
		}
		$user = get_current_user_id();
		$here = get_permalink( $post ) . '#pos-suggest';
		$html = '<section class="pos-place__section pos-ws" id="pos-suggest"><h2>' . esc_html__( 'Suggest changes', 'packout-core' ) . '</h2>';
		if ( $user <= 0 ) {
			$html .= '<p>' . esc_html__( 'Sign in to suggest a change. A coordinator reads it, and it is not shown on this page.', 'packout-core' ) . '</p>';
			return $html . '<p class="pos-ws__actions"><a class="pos-ws__button pos-ws__button--primary" href="' . esc_url( Accounts::signin_url( $here ) ) . '">' . esc_html__( 'Sign in to suggest a change', 'packout-core' ) . '</a></p></section>';
		}
		if ( ! Capabilities::can( Capabilities::REPORT, $user ) ) {
			return '';
		}
		$html .= '<p class="pos-ws__help">' . esc_html__( 'Tell the coordinators what should change. This is sent to them and is not shown on the page.', 'packout-core' ) . '</p>';
		$html .= '<form method="post" class="pos-ws__form"><input type="hidden" name="packout_feature_page" value="suggest" /><input type="hidden" name="feature" value="' . esc_attr( (string) $post->ID ) . '" />' . wp_nonce_field( self::NONCE, '_posnonce', false, false );
		$id    = wp_unique_id( 'pos-suggest-' );
		/* translators: %s: number of characters. */
		$help  = sprintf( __( 'Up to %s characters.', 'packout-core' ), number_format_i18n( self::LENGTH ) );
		$html .= '<p class="pos-ws__field"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'What should change', 'packout-core' ) . '</label><textarea id="' . esc_attr( $id ) . '" name="text" rows="4" maxlength="' . esc_attr( (string) self::LENGTH ) . '" required></textarea><span class="pos-ws__help">' . esc_html( $help ) . '</span></p>';
		return $html . Workspace::submit( __( 'Suggest changes', 'packout-core' ) ) . '</form></section>';
	}

	/**
	 * The edit control, at the bottom, for the person who added the feature and for a coordinator.
	 *
	 * @param \WP_Post $post Feature.
	 * @param string   $kind Kind, in words.
	 */
	private static function edit( \WP_Post $post, string $kind ): string {
		if ( ! Features::can_edit( $post->ID ) ) {
			return '';
		}
		Features::enqueue_editor();
		$payload = Features::payload( $post );
		$label   = '' !== $kind
			/* translators: %s: feature kind, such as Route. */
			? sprintf( __( 'Edit this %s', 'packout-core' ), $kind )
			: __( 'Edit this feature', 'packout-core' );
		$html  = '<section class="pos-place__section pos-feature-page__edit">';
		$html .= '<p class="pos-place-page__note">' . esc_html__( 'Only the person who added this, and a coordinator, can edit it.', 'packout-core' ) . '</p>';
		$html .= '<p class="pos-spot-page__actions pos-ws"><button type="button" class="pos-ws__button pos-ws__button--primary" data-pos-feature-edit="' . esc_attr( (string) wp_json_encode( $payload ) ) . '">' . esc_html( $label ) . '</button></p>';
		return $html . '</section>';
	}

	/**
	 * Record a suggested change and send it to the coordinators.
	 *
	 * @param int    $feature_id Feature ID.
	 * @param int    $user       Who suggests it. A reporter who cannot edit the feature.
	 * @param string $text       What should change.
	 * @return int|\WP_Error Suggestion ID.
	 */
	public static function suggest( int $feature_id, int $user, string $text ) {
		$feature = Features::get( $feature_id );
		if ( ! $feature ) {
			return new \WP_Error( 'packout_not_found', __( 'That map feature does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( $user <= 0 || ! get_userdata( $user ) ) {
			return new \WP_Error( 'packout_signin', __( 'Sign in to suggest a change.', 'packout-core' ), array( 'status' => 401 ) );
		}
		if ( ! Capabilities::can( Capabilities::REPORT, $user ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'A reporter can suggest a change.', 'packout-core' ), array( 'status' => 403 ) );
		}
		if ( Features::can_edit( $feature_id, $user ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'You can edit this feature directly.', 'packout-core' ), array( 'status' => 403 ) );
		}
		$text = trim( sanitize_textarea_field( $text ) );
		if ( '' === $text ) {
			return new \WP_Error( 'packout_suggestion_empty', __( 'Write the suggestion first.', 'packout-core' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $text ) > self::LENGTH ) {
			return new \WP_Error(
				'packout_suggestion_long',
				/* translators: %s: number of characters. */
				sprintf( __( 'A suggestion can be up to %s characters. Shorten it and send it again.', 'packout-core' ), number_format_i18n( self::LENGTH ) ),
				array( 'status' => 400 )
			);
		}
		if ( ! Capabilities::can( Capabilities::MODERATE, $user ) && ! Accounts::allow( 'suggest:' . $user, 10, DAY_IN_SECONDS ) ) {
			return new \WP_Error( 'packout_suggestion_limit', __( 'That is enough suggestions for today. Come back tomorrow.', 'packout-core' ), array( 'status' => 429 ) );
		}

		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => self::TYPE,
					'post_status'  => 'private',
					'post_parent'  => $feature_id,
					'post_author'  => $user,
					'post_title'   => sprintf(
						/* translators: %s: feature name. */
						__( 'Suggestion for %s', 'packout-core' ),
						$feature->post_title
					),
					'post_content' => $text,
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$person = get_userdata( $user );
		Accounts::mail(
			(string) get_option( 'admin_email' ),
			/* translators: %s: feature name. */
			sprintf( __( 'Suggested change to “%s”', 'packout-core' ), $feature->post_title ),
			array(
				/* translators: %s: person's name. */
				sprintf( __( '%s suggested a change.', 'packout-core' ), $person ? $person->display_name : __( 'Someone', 'packout-core' ) ),
				$text,
				/* translators: %s: address of the feature's page. */
				sprintf( __( 'The feature is here: %s', 'packout-core' ), get_permalink( $feature ) ),
			)
		);
		return (int) $id;
	}

	/**
	 * Suggestions go with the feature when it is deleted for good.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public static function deleted( $post_id ): void {
		if ( Schema::FEATURE !== get_post_type( (int) $post_id ) ) {
			return;
		}
		$all = get_posts(
			array(
				'post_type'   => self::TYPE,
				'post_status' => 'private',
				'post_parent' => (int) $post_id,
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		foreach ( $all as $suggestion ) {
			wp_delete_post( (int) $suggestion, true );
		}
	}

	/**
	 * Take a suggestion, or an adoption request, from the feature's page.
	 */
	public static function handle(): void {
		$do = isset( $_POST['packout_feature_page'] ) ? sanitize_key( wp_unslash( $_POST['packout_feature_page'] ) ) : '';
		if ( '' === $do ) {
			return;
		}
		$feature_id = isset( $_POST['feature'] ) ? (int) $_POST['feature'] : 0;
		$feature    = Features::get( $feature_id );
		$user       = get_current_user_id();
		$page       = $feature ? (string) get_permalink( $feature ) : home_url( '/' );
		$back       = static fn( array $args ): string => add_query_arg( array_map( 'rawurlencode', $args ), $page ) . '#pos-' . ( 'adopt' === $do ? 'adopt' : 'suggest' );
		$fail       = static function ( \WP_Error $error ) use ( $back ): void {
			$key = strtolower( wp_generate_password( 12, false ) );
			set_transient( 'pos_feature_note_' . $key, $error->get_error_message(), 10 * MINUTE_IN_SECONDS );
			Accounts::go( $back( array( 'pos_feature_problem' => $key ) ) );
		};

		if ( ! $feature ) {
			Accounts::go( $page );
		}
		if ( $user <= 0 ) {
			Accounts::go( Accounts::signin_url( $page ) );
		}
		if ( ! isset( $_POST['_posnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_posnonce'] ) ), self::NONCE ) ) {
			$fail( new \WP_Error( 'packout_feature_expired', __( 'That form had expired. Try again.', 'packout-core' ) ) );
		}

		if ( 'adopt' === $do ) {
			$done = Places::request_adoption(
				Features::place( $feature_id ),
				isset( $_POST['team'] ) ? (int) $_POST['team'] : 0,
				$user,
				array(
					'note'       => isset( $_POST['note'] ) ? (string) wp_unslash( $_POST['note'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- request_adoption sanitizes it.
					'feature_id' => $feature_id,
				)
			);
			if ( is_wp_error( $done ) ) {
				$fail( $done );
			}
			Accounts::go( $back( array( 'pos_feature' => 'requested' ) ) );
		}

		$done = self::suggest(
			$feature_id,
			$user,
			isset( $_POST['text'] ) ? (string) wp_unslash( $_POST['text'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- suggest() sanitizes it.
		);
		if ( is_wp_error( $done ) ) {
			$fail( $done );
		}
		Accounts::go( $back( array( 'pos_feature' => 'suggested' ) ) );
	}

	/**
	 * What the suggestion and adoption forms said back.
	 */
	public static function notices(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the address to choose what to show.
		$said    = isset( $_GET['pos_feature'] ) ? sanitize_key( wp_unslash( $_GET['pos_feature'] ) ) : '';
		$problem = isset( $_GET['pos_feature_problem'] ) ? sanitize_key( wp_unslash( $_GET['pos_feature_problem'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$html = '';
		if ( '' !== $problem ) {
			$reason = get_transient( 'pos_feature_note_' . $problem );
			$html  .= Accounts::notice( 'error', is_string( $reason ) && '' !== $reason ? $reason : __( 'That did not go through. Try again.', 'packout-core' ) );
		}
		$words = array(
			'suggested' => __( 'The coordinators have your suggestion.', 'packout-core' ),
			'requested' => __( 'The request was sent. The organization’s lead will see it under Submissions.', 'packout-core' ),
		);
		if ( isset( $words[ $said ] ) ) {
			$html .= Accounts::notice( 'success', $words[ $said ] );
		}
		return $html;
	}
}
