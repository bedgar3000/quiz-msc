<?php
/**
 * API REST: msc-quiz/v1
 */

defined( 'ABSPATH' ) || exit;

class MSC_Quiz_REST {

	const NS = 'msc-quiz/v1';

	public static function capability() {
		return apply_filters( 'msc_quiz_capability', 'manage_options' );
	}

	public static function register_routes() {
		$admin = array( __CLASS__, 'can_manage' );
		$id    = array(
			'id' => array(
				'validate_callback' => function ( $value ) {
					return is_numeric( $value );
				},
			),
		);

		register_rest_route(
			self::NS,
			'/quizzes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_quizzes' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_quiz' ),
					'permission_callback' => $admin,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/quizzes/(?P<id>\d+)',
			array(
				'args' => $id,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_quiz' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_quiz' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_quiz' ),
					'permission_callback' => $admin,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/quizzes/(?P<id>\d+)/duplicate',
			array(
				'args'                => $id,
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'duplicate_quiz' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/quizzes/(?P<id>\d+)/submissions',
			array(
				'args'                => $id,
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_submissions' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/submissions/(?P<id>\d+)',
			array(
				'args'                => $id,
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_submission' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/slug',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'suggest_slug' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_settings' ),
					'permission_callback' => $admin,
				),
			)
		);

		// Público: envío de respuestas desde la web.
		register_rest_route(
			self::NS,
			'/quizzes/(?P<id>\d+)/submit',
			array(
				'args'                => $id,
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function can_manage() {
		return current_user_can( self::capability() );
	}

	public static function list_quizzes() {
		return rest_ensure_response( MSC_Quiz_Repository::list_quizzes() );
	}

	public static function get_quiz( WP_REST_Request $request ) {
		$quiz = MSC_Quiz_Repository::get_quiz( (int) $request['id'] );
		return $quiz ? rest_ensure_response( $quiz ) : self::not_found();
	}

	public static function create_quiz( WP_REST_Request $request ) {
		$id = MSC_Quiz_Repository::save_quiz( (array) $request->get_json_params() );
		return is_wp_error( $id ) ? $id : new WP_REST_Response( MSC_Quiz_Repository::get_quiz( $id ), 201 );
	}

	public static function update_quiz( WP_REST_Request $request ) {
		$id = MSC_Quiz_Repository::save_quiz( (array) $request->get_json_params(), (int) $request['id'] );
		return is_wp_error( $id ) ? $id : rest_ensure_response( MSC_Quiz_Repository::get_quiz( $id ) );
	}

	public static function delete_quiz( WP_REST_Request $request ) {
		return MSC_Quiz_Repository::delete_quiz( (int) $request['id'] )
			? rest_ensure_response( array( 'deleted' => true ) )
			: self::not_found();
	}

	public static function duplicate_quiz( WP_REST_Request $request ) {
		$id = MSC_Quiz_Repository::duplicate_quiz( (int) $request['id'] );
		return is_wp_error( $id ) ? $id : new WP_REST_Response( MSC_Quiz_Repository::get_quiz( $id ), 201 );
	}

	public static function list_submissions( WP_REST_Request $request ) {
		$quiz = MSC_Quiz_Repository::get_quiz( (int) $request['id'] );
		if ( ! $quiz ) {
			return self::not_found();
		}
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$data = MSC_Quiz_Repository::list_submissions( $quiz['id'], $page );
		if ( 1 === $page ) {
			$data['stats'] = MSC_Quiz_Repository::stats( $quiz );
		}
		return rest_ensure_response( $data );
	}

	public static function delete_submission( WP_REST_Request $request ) {
		return MSC_Quiz_Repository::delete_submission( (int) $request['id'] )
			? rest_ensure_response( array( 'deleted' => true ) )
			: self::not_found();
	}

	public static function suggest_slug( WP_REST_Request $request ) {
		$slug = MSC_Quiz_Repository::unique_slug( (string) $request->get_param( 'source' ), (int) $request->get_param( 'exclude' ) );
		return rest_ensure_response(
			array(
				'slug' => $slug,
				'url'  => MSC_Quiz_Public::quiz_url( $slug ),
			)
		);
	}

	public static function get_settings() {
		return rest_ensure_response(
			array(
				'logo_id'  => (int) get_option( 'msc_quiz_logo_id' ),
				'logo_url' => MSC_Quiz_Public::logo_url(),
			)
		);
	}

	public static function update_settings( WP_REST_Request $request ) {
		$logo_id = absint( $request->get_param( 'logo_id' ) );
		// wp_attachment_is_image() no reconoce SVG, por eso se valida el tipo MIME.
		if ( $logo_id && 0 !== strpos( (string) get_post_mime_type( $logo_id ), 'image/' ) ) {
			return new WP_Error( 'msc_quiz_invalid', 'El logo debe ser una imagen de la biblioteca de medios.', array( 'status' => 400 ) );
		}
		update_option( 'msc_quiz_logo_id', $logo_id );
		MSC_Quiz_Public::purge_page_cache();
		return self::get_settings();
	}

	public static function submit( WP_REST_Request $request ) {
		$quiz = MSC_Quiz_Repository::get_quiz( (int) $request['id'] );
		if ( ! $quiz ) {
			return self::not_found();
		}

		// Un administrador puede probar un quiz no abierto (vista previa): se corrige pero no se guarda.
		$is_open = 'open' === MSC_Quiz_Repository::availability( $quiz );
		if ( ! $is_open && ! current_user_can( self::capability() ) ) {
			return new WP_Error( 'msc_quiz_closed', 'Este quiz no está disponible para recibir respuestas.', array( 'status' => 403 ) );
		}

		if ( $is_open && self::rate_limited() ) {
			return new WP_Error( 'msc_quiz_rate_limited', 'Has enviado demasiadas respuestas. Inténtalo de nuevo en unos minutos.', array( 'status' => 429 ) );
		}

		$raw        = $request->get_param( 'answers' );
		$selections = array();
		foreach ( is_array( $raw ) ? $raw : array() as $question_id => $answer_ids ) {
			$selections[ (int) $question_id ] = array_map( 'intval', (array) $answer_ids );
		}

		$graded = MSC_Quiz_Repository::grade( $quiz, $selections );
		foreach ( $graded['results'] as $result ) {
			if ( ! $result['selected'] ) {
				return new WP_Error( 'msc_quiz_incomplete', 'Responde todas las preguntas antes de finalizar.', array( 'status' => 400 ) );
			}
		}

		$graded['submission_id'] = $is_open ? MSC_Quiz_Repository::save_submission( $quiz, $graded ) : 0;

		return rest_ensure_response(
			array(
				'submission_id' => $graded['submission_id'],
				'score'         => $graded['score'],
				'total'         => $graded['total'],
				'percentage'    => $graded['percentage'],
				'results'       => array_map(
					function ( $result ) {
						return array(
							'question_id' => $result['question_id'],
							'correct'     => $result['correct'],
							'feedback'    => $result['feedback'],
						);
					},
					$graded['results']
				),
			)
		);
	}

	/** Límite simple por IP: 30 envíos cada 10 minutos (filtrable). */
	private static function rate_limited() {
		$ip = MSC_Quiz_Repository::client_ip();
		if ( ! $ip ) {
			return false;
		}
		$limit = (int) apply_filters( 'msc_quiz_rate_limit', 30 );
		$key   = 'msc_quiz_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $limit > 0 && $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return false;
	}

	private static function not_found() {
		return new WP_Error( 'msc_quiz_not_found', 'No encontrado.', array( 'status' => 404 ) );
	}
}
