<?php
/**
 * REST API.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The packout/v1 routes read by the public map and the field app.
 *
 * | Route                            | Behavior                                              |
 * | -------------------------------- | ----------------------------------------------------- |
 * | GET /spots                       | Spots visible to the current user, optional bbox      |
 * | GET /spots/{id}                  | One Spot the current user may see                     |
 * | PUT /spots/client/{client_id}    | Create or update the reporter's Spot for a client id  |
 * | POST /spots/{id}/photos          | Attach one image                                      |
 * | POST /spots/{id}/cleaned         | The reporter cleaned their own Spot, with no mission  |
 * | GET /search                      | Locations, Spots, Places, and Features that match a query |
 * | GET /line                        | The road or trail at a latitude and longitude              |
 * | GET /features                    | Public map features, optionally of one Place          |
 * | POST /features                   | Create a map feature                                  |
 * | POST /features/{id}/photos       | Attach one image to a feature the user may edit       |
 * | GET /features/{id}               | One public map feature                                |
 * | PUT /features/{id}               | Update a map feature                                  |
 * | DELETE /features/{id}            | Trash a map feature                                   |
 * | POST /features/import            | Preview or commit a GPX or GeoJSON file               |
 * | GET /regions                     | Map regions the user may download                     |
 * | GET /sync                        | Server time and the user's Spot revisions             |
 * | GET /missions                    | Missions of the user's teams, with crew fields        |
 * | PUT /missions/{id}/progress      | Checklist ticks and Spot outcomes from a crew member  |
 * | POST /missions/{id}/status       | The lead starts or completes a mission                |
 * | GET  /teams/{id}/address         | Whether a team can have the address being typed       |
 * | GET  /teams/{id}/subteams/{n}/address | Whether a sub-team can have the address being typed |
 * | GET  /overview/now               | Coordinator Overview current-state snapshot           |
 * | GET  /overview/period            | Coordinator Overview figures for a day window         |
 * | GET  /overview/setup             | Site Setup checklist                                  |
 */
class Rest {

	public const NAMESPACE = 'packout/v1';

	/**
	 * Register the routes.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Route table.
	 */
	public static function routes(): void {
		$reporter = static fn() => Capabilities::can( Capabilities::REPORT );

		register_rest_route(
			self::NAMESPACE,
			'/teams/(?P<id>\d+)/address',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'team_address' ),
				'permission_callback' => static fn( \WP_REST_Request $request ) => is_user_logged_in() && ( Teams::can_run( (int) $request['id'] ) || Teams::can_edit() ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/teams/(?P<id>\d+)/subteams/(?P<subteam>\d+)/address',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'subteam_address' ),
				'permission_callback' => static fn( \WP_REST_Request $request ) => is_user_logged_in() && Subteams::can_run( (int) $request['id'], (int) $request['subteam'], get_current_user_id() ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/spots',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'spots' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/spots/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'spot' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/spots/client/(?P<client_id>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})',
			array(
				'methods'             => 'PUT, POST',
				'callback'            => array( self::class, 'put_spot' ),
				'permission_callback' => $reporter,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/spots/(?P<id>\d+)/photos',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'add_photo' ),
				'permission_callback' => $reporter,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/spots/(?P<id>\d+)/cleaned',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'cleaned' ),
				'permission_callback' => $reporter,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/missions',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'missions' ),
				'permission_callback' => $reporter,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/missions/(?P<id>\d+)/progress',
			array(
				'methods'             => 'PUT, POST',
				'callback'            => array( self::class, 'progress' ),
				'permission_callback' => $reporter,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/missions/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'mission_status' ),
				'permission_callback' => $reporter,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/impact/client/(?P<client_id>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'put_impact' ),
				'permission_callback' => $reporter,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/impact/(?P<id>\d+)/photos',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'add_impact_photo' ),
				'permission_callback' => $reporter,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/features',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'features' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'create_feature' ),
					'permission_callback' => $reporter,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/features/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'import_features' ),
				'permission_callback' => $reporter,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/features/(?P<id>\d+)/photos',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'add_feature_photo' ),
				'permission_callback' => $reporter,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/features/(?P<id>\d+)/photos/order',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'order_feature_photos' ),
				'permission_callback' => $reporter,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/features/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'feature' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'PUT, POST',
					'callback'            => array( self::class, 'put_feature' ),
					'permission_callback' => $reporter,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'delete_feature' ),
					'permission_callback' => $reporter,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/line',
			array(
				'methods'             => 'GET',
				'callback'            => array( Search::class, 'rest_line' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'lat' => array(
						'type'     => 'number',
						'required' => true,
					),
					'lng' => array(
						'type'     => 'number',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( Search::class, 'rest' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'near' => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/places',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'places' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/regions',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'regions' ),
				'permission_callback' => $reporter,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/sync',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'sync' ),
				'permission_callback' => $reporter,
			)
		);

		$moderate = static fn() => Capabilities::can( Capabilities::MODERATE );
		// The platform's overview is a coordinator's. A record's is for the people who may open it.
		$overview = static function ( \WP_REST_Request $request ): bool {
			$scope = self::overview_scope( $request );
			if ( null === $scope ) {
				return false;
			}
			return $scope ? Overview::can_open( $scope['kind'], $scope['id'], get_current_user_id() ) : Capabilities::can( Capabilities::MODERATE );
		};
		$scoped   = array(
			'kind' => array(
				'type'    => 'string',
				'default' => '',
			),
			'id'   => array(
				'type'    => 'integer',
				'default' => 0,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/overview/now',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'overview_now' ),
				'permission_callback' => $overview,
				'args'                => $scoped,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/overview/period',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'overview_period' ),
				'permission_callback' => $overview,
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'default' => 30,
					),
				) + $scoped,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/overview/setup',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'overview_setup' ),
				'permission_callback' => $moderate,
			)
		);
	}

	/**
	 * Spots the current user may see.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function spots( \WP_REST_Request $request ): \WP_REST_Response {
		$spots = Spots::visible(
			get_current_user_id(),
			array(
				'bbox'     => Geo::bbox( $request->get_param( 'bbox' ) ),
				'category' => sanitize_title( (string) $request->get_param( 'category' ) ),
				'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
				// The field app works on this site's own Spots, and asks for those alone.
				'partners' => '1' !== (string) $request->get_param( 'own' ),
			)
		);
		$more  = Spots::more();
		// The partners a Spot is shown under, for the map's partner filter. Nothing is added while no partner is listed.
		$partners = Partners::of_spots();
		$only     = (int) $request->get_param( 'partner' );
		if ( $partners ) {
			foreach ( $spots as $index => $spot ) {
				// A Spot a partner's site sent already names its partner.
				$spots[ $index ]['partners'] = $spot['partners'] ?? ( $partners[ (int) $spot['id'] ] ?? array() );
			}
		}
		if ( $only > 0 ) {
			// A partner the directory does not show has no Spots to name.
			$spots = array_values( array_filter( $spots, static fn( $spot ) => in_array( $only, $spot['partners'] ?? array(), true ) ) );
		}
		return self::fresh(
			array(
				'spots' => $spots,
				// A site with more Spots than one answer carries. The map asks again for the area on view.
				'more'  => $more,
			)
		);
	}

	/**
	 * One Spot. A Spot the user may not see answers as missing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function spot( \WP_REST_Request $request ) {
		$post = Spots::get( (int) $request['id'] );
		$view = $post ? Spots::view_for( $post, get_current_user_id() ) : null;
		if ( ! $post || null === $view ) {
			return new \WP_Error( 'packout_not_found', __( 'That Spot does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		return self::fresh( array( 'spot' => Spots::payload( $post, $view ) ) );
	}

	/**
	 * Create or update the reporter's Spot for a client id.
	 *
	 * The client id is the idempotency key. A retry after a timeout updates the
	 * same Spot. The request carries the revision the device last saw. When
	 * someone else has changed the Spot since, the answer is a conflict with
	 * the server copy, and nothing is overwritten.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_spot( \WP_REST_Request $request ) {
		$user      = get_current_user_id();
		$client_id = strtolower( (string) $request['client_id'] );
		$body      = $request->get_json_params();
		$body      = is_array( $body ) ? $body : $request->get_body_params();
		$fields    = array_intersect_key( $body, array_flip( array( 'category', 'description', 'title', 'landmark', 'lat', 'lng', 'accuracy', 'area', 'hazard', 'photos_pending' ) ) );
		$existing  = Spots::by_client( $client_id, $user );
		$created   = false;
		// Who the reporter asked the public page to credit. A device that does not say is one from before the question was asked.
		$credits = array_key_exists( 'credit_team', $body ) || array_key_exists( 'credit_named', $body );
		$team    = array_key_exists( 'credit_team', $body ) ? (int) $body['credit_team'] : null;
		$named   = ! empty( $body['credit_named'] );

		if ( ! $existing ) {
			$id = Spots::create(
				$fields,
				$user,
				array(
					'client_id'    => $client_id,
					'credit_team'  => $team,
					'credit_named' => $named,
				)
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$created = true;
		} else {
			$id = $existing->ID;
			if ( 'trash' === $existing->post_status ) {
				return new \WP_Error( 'packout_removed', __( 'A coordinator removed this Spot.', 'packout-core' ), array( 'status' => 410 ) );
			}

			$server = Spots::revision( $id );
			$copy   = Spots::payload( $existing, 'owner' );

			if ( 'pending' !== $existing->post_status ) {
				return new \WP_Error(
					'packout_reviewed',
					__( 'A coordinator has reviewed this Spot. Ask a coordinator to change it.', 'packout-core' ),
					array(
						'status' => 409,
						'spot'   => $copy,
					)
				);
			}
			if ( $server > (int) Spots::field( $id, 'client_revision', '0' ) && (int) ( $body['revision'] ?? 0 ) < $server ) {
				return new \WP_Error(
					'packout_conflict',
					__( 'A coordinator changed this Spot after your last sync. Choose which copy to keep.', 'packout-core' ),
					array(
						'status' => 409,
						'spot'   => $copy,
					)
				);
			}

			$changed = Spots::update( $id, $fields, true );
			if ( is_wp_error( $changed ) ) {
				return $changed;
			}
			if ( $credits ) {
				Spots::set_credit( $id, $team, $named );
			}
			if ( ! $changed ) {
				// The device has now seen the server copy and agrees with it.
				update_post_meta( $id, '_pos_client_revision', $server );
			}
		}//end if

		$response = self::fresh(
			array(
				'created'    => $created,
				'spot'       => Spots::payload( Spots::get( $id ), 'owner' ),
				'duplicates' => Spots::duplicates( $id ),
			)
		);
		$response->set_status( $created ? 201 : 200 );
		return $response;
	}

	/**
	 * Attach one image to a Spot the user reported or may moderate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add_photo( \WP_REST_Request $request ) {
		$post = Spots::get( (int) $request['id'] );
		$user = get_current_user_id();
		if ( ! $post || 'trash' === $post->post_status ) {
			return new \WP_Error( 'packout_not_found', __( 'That Spot does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( (int) $post->post_author !== $user && ! Capabilities::can( Capabilities::MODERATE ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'You can add photos to your own Spots.', 'packout-core' ), array( 'status' => 403 ) );
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new \WP_Error( 'packout_photo_missing', __( 'Send the image in a field named file.', 'packout-core' ), array( 'status' => 400 ) );
		}

		$id = Photos::attach_upload( $post->ID, $files['file'], sanitize_text_field( (string) $request->get_param( 'client_photo_id' ) ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::place_photo( $id, $request, $post->ID );

		$response = self::fresh(
			array(
				'photo'  => $id,
				'photos' => Photos::for_spot( $post->ID ),
			)
		);
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Whether a sub-team can have the address someone is typing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function subteam_address( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$sub     = (int) $request['subteam'];
		$subteam = Teams::get( $id ) ? Subteams::get( $id, $sub ) : null;
		if ( ! $subteam ) {
			return new \WP_Error( 'packout_not_found', __( 'That team does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$address = strtolower( trim( (string) $request->get_param( 'address' ) ) );
		$problem = Subteams::address_problem( $id, $sub, $address );
		if ( '' === $problem ) {
			$message = $address === $subteam['slug']
				? __( 'This is the team\'s address now.', 'packout-core' )
				/* translators: %s: web address. */
				: sprintf( __( 'Available. The team\'s page would be %s', 'packout-core' ), Subteams::url( $id, $sub, $address ) );
		} else {
			$message = $problem;
		}
		return self::fresh(
			array(
				'address'   => $address,
				'available' => '' === $problem,
				'url'       => '' === $problem ? Subteams::url( $id, $sub, $address ) : '',
				'message'   => $message,
			)
		);
	}

	/**
	 * Whether a team can have the address someone is typing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function team_address( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! Teams::get( $id ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That organization does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$address = Teams::clean_address( (string) $request->get_param( 'address' ) );
		$problem = Teams::address_problem( $id, $address );
		if ( '' === $problem ) {
			$message = Teams::address( $id ) === $address
				? __( 'This is the organization\'s address now.', 'packout-core' )
				/* translators: %s: web address. */
				: sprintf( __( 'Available. The organization\'s page would be %s', 'packout-core' ), Teams::address_url( $id, $address ) );
		} else {
			$message = $problem;
		}
		return self::fresh(
			array(
				'address'   => $address,
				'available' => '' === $problem,
				'url'       => '' === $problem ? Teams::address_url( $id, $address ) : '',
				'message'   => $message,
			)
		);
	}

	/**
	 * Keep where the field app says a photo was taken.
	 *
	 * The app shrinks a photo before sending it, which drops the place the
	 * camera recorded, so it reads the place first and sends it alongside.
	 * What the file itself says is kept when it says anything.
	 *
	 * @param int              $photo   Attachment ID.
	 * @param \WP_REST_Request $request Request.
	 * @param int              $spot_id Spot to give a pin when it has none, or 0.
	 */
	private static function place_photo( int $photo, \WP_REST_Request $request, int $spot_id ): void {
		$point = Geo::point( $request->get_param( 'photo_lat' ), $request->get_param( 'photo_lng' ) );
		if ( ! $point || Photos::location( $photo ) || ! Photos::set_location( $photo, $point[0], $point[1] ) ) {
			return;
		}
		if ( $spot_id > 0 ) {
			Spots::pin_from_photo( $spot_id, $point[0], $point[1] );
		}
	}

	/**
	 * The reporter cleaned their own Spot, with no mission.
	 *
	 * Until impact records exist, this is a change of stage and a note of who
	 * and when. A Spot flagged to be left in place is refused.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function cleaned( \WP_REST_Request $request ) {
		$post = Spots::get( (int) $request['id'] );
		$user = get_current_user_id();
		if ( ! $post || 'trash' === $post->post_status ) {
			return new \WP_Error( 'packout_not_found', __( 'That Spot does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( (int) $post->post_author !== $user && ! Capabilities::can( Capabilities::MODERATE ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'You can mark your own Spots cleaned. An organization records the others through a mission.', 'packout-core' ), array( 'status' => 403 ) );
		}

		$done = Spots::mark_cleaned( $post->ID, $user );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		return self::fresh( array( 'spot' => Spots::payload( Spots::get( $post->ID ), Capabilities::can( Capabilities::MODERATE ) ? 'moderator' : 'owner' ) ) );
	}

	/**
	 * Record the work done at a Spot, or a return visit, from the field app.
	 *
	 * The client id makes a retry safe. A cleanup record is also unique for
	 * each cleaning of a Spot, so a second device edits the same record.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_impact( \WP_REST_Request $request ) {
		$user  = get_current_user_id();
		$input = (array) $request->get_json_params();
		$spot  = (int) ( $input['spot'] ?? 0 );
		$kind  = (string) ( $input['kind'] ?? Impact::CLEANUP );
		$known = Impact::by_client( strtolower( (string) $request['client_id'] ), $user );

		if ( Impact::CLEANUP === $kind ) {
			$id = Impact::save_cleanup( $spot, array_intersect_key( $input, array_flip( array( 'narrative', 'date', 'hours', 'people', 'bags', 'bags_source', 'pounds', 'pounds_source', 'come_back', 'come_back_note' ) ) ), $user, strtolower( (string) $request['client_id'] ) );
		} else {
			$id = Impact::save_visit( $spot, $kind, (string) ( $input['narrative'] ?? '' ), $user, strtolower( (string) $request['client_id'] ) );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$response = self::fresh(
			array(
				'impact' => Impact::payload( Impact::get( $id ) ),
				'line'   => Settings::celebration_line(),
			)
		);
		$response->set_status( $known ? 200 : 201 );
		return $response;
	}

	/**
	 * Add a photo to an impact record: taken after the work, or on a return visit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add_impact_photo( \WP_REST_Request $request ) {
		$record = Impact::get( (int) $request['id'] );
		if ( ! $record ) {
			return new \WP_Error( 'packout_not_found', __( 'That record does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new \WP_Error( 'packout_photo_missing', __( 'Send the image in a field named file.', 'packout-core' ), array( 'status' => 400 ) );
		}

		$id = Impact::add_photo( $record->ID, $files['file'], get_current_user_id(), sanitize_text_field( (string) $request->get_param( 'client_photo_id' ) ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::place_photo( $id, $request, 0 );
		$response = self::fresh(
			array(
				'photo'  => $id,
				'photos' => count( Photos::for_spot( $record->ID ) ),
			)
		);
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Missions of the teams the user leads or belongs to.
	 */
	public static function missions(): \WP_REST_Response {
		$user     = get_current_user_id();
		$missions = array();
		foreach ( Missions::for_user( $user ) as $post ) {
			if ( Missions::can_work( $post->ID, $user ) ) {
				$missions[] = Missions::payload( $post, $user );
			}
		}
		return self::fresh(
			array(
				'missions' => $missions,
				'statuses' => Missions::statuses(),
				'outcomes' => Missions::outcomes(),
			)
		);
	}

	/**
	 * Checklist ticks and Spot outcomes from a crew member's device.
	 *
	 * The answer always carries the mission as the server now holds it, with
	 * the changes that disagreed with someone else's and the ones refused.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function progress( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$user = get_current_user_id();
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : $request->get_body_params();

		$result = Missions::progress( $id, $user, $body );
		if ( ! is_wp_error( $result ) && $result['applied'] > 0 ) {
			// Progress that came from a phone, for the overview of the organization.
			update_post_meta( $id, '_pos_synced_at', gmdate( 'c' ) );
		}
		if ( is_wp_error( $result ) ) {
			$post = Missions::get( $id );
			if ( $post && Missions::can_work( $id, $user ) ) {
				$result->add_data(
					array(
						'status'  => 409,
						'mission' => Missions::payload( $post, $user ),
					)
				);
			}
			return $result;
		}

		$result['mission'] = Missions::payload( Missions::get( $id ), $user );
		return self::fresh( $result );
	}

	/**
	 * The lead starts or completes a mission from the field.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function mission_status( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$user = get_current_user_id();
		if ( ! Missions::get( $id ) || ! Missions::can_work( $id, $user ) ) {
			return new \WP_Error( 'packout_not_found', __( 'That mission does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! Missions::can_manage( $id, $user ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'The lead starts and completes a mission.', 'packout-core' ), array( 'status' => 403 ) );
		}

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( ! in_array( $status, array( 'in_progress', 'completed' ), true ) ) {
			return new \WP_Error( 'packout_mission_status', __( 'A mission can be started or completed from the field app.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$done = Missions::set_status( $id, $status, 'spotted' === $request->get_param( 'open_to' ) ? 'spotted' : 'in_progress' );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		return self::fresh( array( 'mission' => Missions::payload( Missions::get( $id ), $user ) ) );
	}

	/**
	 * Map regions a reporter may download.
	 */
	public static function regions(): \WP_REST_Response {
		return self::fresh( array( 'regions' => Settings::get( 'regions' ) ) );
	}

	/**
	 * Server time, who is signed in, and the revision of each Spot they reported.
	 */
	public static function sync(): \WP_REST_Response {
		$user  = wp_get_current_user();
		$mine  = get_posts(
			array(
				'post_type'   => Schema::SPOT,
				'post_status' => array( 'pending', 'publish', 'private', 'trash' ),
				'author'      => $user->ID,
				'numberposts' => 500,
			)
		);
		$spots = array();
		foreach ( $mine as $post ) {
			$spots[] = array(
				'id'            => $post->ID,
				'client_id'     => Spots::field( $post->ID, 'client_id' ),
				'revision'      => Spots::revision( $post->ID ),
				'post_status'   => $post->post_status,
				'status'        => Spots::field( $post->ID, 'status', 'spotted' ),
				'return_reason' => Spots::field( $post->ID, 'return_reason' ),
			);
		}

		return self::fresh(
			array(
				'time'  => gmdate( 'c' ),
				'user'  => array(
					'id'       => $user->ID,
					'name'     => $user->display_name,
					'moderate' => Capabilities::can( Capabilities::MODERATE ),
					// The organizations a report can be credited to.
					'teams'    => array_map(
						static fn( $id, $name ) => array(
							'id'   => $id,
							'name' => $name,
						),
						array_keys( Credit::teams( $user->ID ) ),
						array_values( Credit::teams( $user->ID ) )
					),
				),
				'spots' => $spots,
				// The person's own impact records and return visits, with their verification.
				'work'  => Impact::mine( $user->ID ),
			)
		);
	}

	/**
	 * Public map features, optionally limited to one Place.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function features( \WP_REST_Request $request ): \WP_REST_Response {
		$features = Features::public_list( (int) $request->get_param( 'place' ) );
		$bbox     = Geo::bbox( $request->get_param( 'bbox' ) );
		if ( $bbox ) {
			$features = array_values( array_filter( $features, static fn( array $feature ): bool => Geo::reaches( $feature['geometry']['coordinates'] ?? null, $bbox ) ) );
		}
		return self::fresh( array( 'features' => $features ) );
	}

	/**
	 * The Places the site lists, as a map draws them: the name, the kind, the outline, the adopters by name, and the page.
	 *
	 * @param \WP_REST_Request $request Request. `bbox` keeps the Places that reach into a box.
	 */
	public static function places( \WP_REST_Request $request ): \WP_REST_Response {
		$bbox   = Geo::bbox( $request->get_param( 'bbox' ) );
		$places = array();
		foreach ( Places::all( true ) as $place ) {
			$shape = Places::shape( $place->ID );
			if ( ! $shape || ( $bbox && ! Geo::reaches( $shape, $bbox ) ) ) {
				continue;
			}
			$adopters = array();
			foreach ( Places::adopters( $place->ID ) as $adopter ) {
				// An organization that is not listed is not named.
				if ( $adopter['listed'] && '' !== (string) $adopter['name'] ) {
					$adopters[] = array(
						'name' => (string) $adopter['name'],
						'url'  => (string) $adopter['url'],
					);
				}
			}
			$places[] = array(
				'id'         => $place->ID,
				'name'       => $place->post_title,
				'url'        => (string) get_permalink( $place ),
				'kind'       => Places::kind( $place->ID ),
				'kind_label' => Places::kinds()[ Places::kind( $place->ID ) ],
				'shape'      => $shape,
				'adopters'   => $adopters,
			);
			if ( count( $places ) >= 500 ) {
				break;
			}
		}//end foreach
		return self::fresh( array( 'places' => $places ) );
	}

	/**
	 * One public map feature.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function feature( \WP_REST_Request $request ) {
		$post = Features::get( (int) $request['id'] );
		$view = $post ? Features::payload( $post ) : null;
		if ( ! $view ) {
			return new \WP_Error( 'packout_not_found', __( 'That map feature does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		return self::fresh( array( 'feature' => $view ) );
	}

	/**
	 * Attach one image to a feature the current user may edit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add_feature_photo( \WP_REST_Request $request ) {
		$post = Features::get( (int) $request['id'] );
		if ( ! $post || 'trash' === $post->post_status ) {
			return new \WP_Error( 'packout_not_found', __( 'That map feature does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		if ( ! Features::can_edit( $post->ID ) ) {
			return new \WP_Error( 'packout_forbidden', __( 'You can add photos to your own map features.', 'packout-core' ), array( 'status' => 403 ) );
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new \WP_Error( 'packout_photo_missing', __( 'Send the image in a field named file.', 'packout-core' ), array( 'status' => 400 ) );
		}

		$id = Photos::attach_upload( $post->ID, $files['file'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$response = self::fresh(
			array(
				'photo'   => $id,
				'gallery' => Features::gallery( $post ),
			)
		);
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Store the order of a feature's photos. The first is the cover.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function order_feature_photos( \WP_REST_Request $request ) {
		$post = Features::get( (int) $request['id'] );
		if ( ! $post || 'trash' === $post->post_status ) {
			return new \WP_Error( 'packout_not_found', __( 'That map feature does not exist.', 'packout-core' ), array( 'status' => 404 ) );
		}
		$body = $request->get_json_params();
		$ids  = is_array( $body ) ? ( $body['order'] ?? array() ) : array();
		$done = Features::set_photo_order( $post->ID, is_array( $ids ) ? $ids : array() );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		return self::fresh( array( 'gallery' => Features::gallery( $post ) ) );
	}

	/**
	 * Create a map feature.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_feature( \WP_REST_Request $request ) {
		$id = Features::save( 0, self::feature_input( $request ), get_current_user_id() );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$response = self::fresh( array( 'feature' => Features::payload( Features::get( (int) $id ) ) ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Update a map feature.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_feature( \WP_REST_Request $request ) {
		$id = Features::save( (int) $request['id'], self::feature_input( $request ), get_current_user_id() );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::fresh( array( 'feature' => Features::payload( Features::get( (int) $id ) ) ) );
	}

	/**
	 * Trash a map feature.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete_feature( \WP_REST_Request $request ) {
		$done = Features::trash( (int) $request['id'], get_current_user_id() );
		return is_wp_error( $done ) ? $done : self::fresh( array( 'deleted' => true ) );
	}

	/**
	 * Preview or commit a GPX or GeoJSON import.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_features( \WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = is_array( $files['file'] ?? null ) ? $files['file'] : null;
		if ( ! $file || empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			return new \WP_Error( 'packout_feature_import', __( 'Choose a GPX or GeoJSON file.', 'packout-core' ), array( 'status' => 400 ) );
		}
		$raw    = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local upload.
		$drafts = Features::parse_file( $raw, (string) ( $file['name'] ?? '' ) );
		if ( is_wp_error( $drafts ) ) {
			return $drafts;
		}
		$commit = ! empty( $request->get_param( 'commit' ) );
		if ( ! $commit ) {
			return self::fresh( array( 'drafts' => $drafts ) );
		}
		$ids = Features::import(
			$drafts,
			array(
				'color' => (string) $request->get_param( 'color' ),
				'icon'  => (string) $request->get_param( 'icon' ),
				'place' => (int) $request->get_param( 'place' ),
			),
			get_current_user_id()
		);
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		$features = array();
		foreach ( $ids as $id ) {
			$post = Features::get( (int) $id );
			if ( $post ) {
				$features[] = Features::payload( $post );
			}
		}
		return self::fresh( array( 'features' => $features ) );
	}

	/**
	 * Feature fields from a JSON or form body.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function feature_input( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : $request->get_body_params();
		$body = is_array( $body ) ? $body : array();
		$keys = array( 'name', 'description', 'kind', 'color', 'icon', 'geometry', 'place' );
		return array_intersect_key( $body, array_flip( $keys ) );
	}

	/**
	 * The record an overview request is about.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|null An empty array for the platform, or null when the request names a record that cannot have one.
	 */
	private static function overview_scope( \WP_REST_Request $request ): ?array {
		$kind = sanitize_key( (string) $request->get_param( 'kind' ) );
		$id   = (int) $request->get_param( 'id' );
		if ( '' === $kind && 0 === $id ) {
			return array();
		}
		$scope = Overview_Scope::scope(
			array(
				'kind' => $kind,
				'id'   => $id,
			)
		);
		return $scope ? $scope : null;
	}

	/**
	 * Current-state Overview snapshot.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function overview_now( \WP_REST_Request $request ): \WP_REST_Response {
		return self::fresh( Overview::refresh_now( (array) self::overview_scope( $request ) ) );
	}

	/**
	 * Period Overview snapshot.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function overview_period( \WP_REST_Request $request ): \WP_REST_Response {
		return self::fresh( Overview::refresh_period( (int) $request->get_param( 'days' ), (array) self::overview_scope( $request ) ) );
	}

	/**
	 * Site Setup checklist for Help tabs and the overview API.
	 */
	public static function overview_setup(): \WP_REST_Response {
		return self::fresh( Overview::setup() );
	}

	/**
	 * A response that asks caches not to keep it, so the map follows moderation.
	 *
	 * Some hosts keep a visitor's answer anyway. The public map allows for
	 * that by asking at a new address each minute.
	 *
	 * @param array<string, mixed> $data Response body.
	 */
	private static function fresh( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );
		// A web server set to date what has no date leaves an answer alone that has one.
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		return $response;
	}
}
