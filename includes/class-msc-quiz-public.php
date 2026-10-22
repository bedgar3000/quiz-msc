<?php
/**
 * Web pública: shortcode [msc_quiz] y URL propia /quiz/{slug}/ sin header ni footer del tema.
 */

defined( 'ABSPATH' ) || exit;

class MSC_Quiz_Public {

	const QUERY_VAR = 'msc_quiz';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_standalone' ), 0 );
		add_shortcode( 'msc_quiz', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/** Base de la URL pública. Cambiable con el filtro `msc_quiz_url_base` (luego guardar Enlaces permanentes). */
	public static function url_base() {
		return trim( apply_filters( 'msc_quiz_url_base', 'quiz' ), '/' );
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule( '^' . preg_quote( self::url_base(), '/' ) . '/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function quiz_url( $slug ) {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( user_trailingslashit( self::url_base() . '/' . $slug ) );
		}
		return add_query_arg( self::QUERY_VAR, $slug, home_url( '/' ) );
	}

	/**
	 * Los administradores pueden previsualizar quizzes en borrador, programados o
	 * expirados; en ese caso las respuestas se corrigen pero no se guardan.
	 */
	private static function can_preview() {
		return current_user_can( MSC_Quiz_REST::capability() );
	}

	private static function payload( array $quiz ) {
		$payload = MSC_Quiz_Repository::public_payload( $quiz );
		if ( 'open' !== $payload['availability'] && self::can_preview() ) {
			$quiz['status']       = 'published';
			$quiz['published_at'] = '';
			$quiz['expires_at']   = '';
			$payload              = MSC_Quiz_Repository::public_payload( $quiz );
		}
		$payload['logo']      = self::logo_url();
		$payload['site_name'] = get_bloginfo( 'name' );
		$payload['year']      = current_time( 'Y' );
		return $payload;
	}

	/**
	 * Logo de la cabecera: el elegido en Quiz MSC → Ajustes o, si no hay, el logo del sitio.
	 */
	public static function logo_url() {
		$id = (int) get_option( 'msc_quiz_logo_id' );
		if ( ! $id || ! wp_get_attachment_url( $id ) ) {
			$id = (int) get_theme_mod( 'custom_logo' );
		}
		$url = '';
		if ( $id ) {
			// Los SVG no tienen tamaños intermedios: se usa el archivo original.
			$url = wp_get_attachment_image_url( $id, 'medium' );
			$url = $url ? $url : wp_get_attachment_url( $id );
		}
		return apply_filters( 'msc_quiz_logo_url', $url ? $url : '' );
	}

	/**
	 * Imagen para compartir: la social del home en Yoast, la destacada del home,
	 * el logo del sitio o el icono del sitio, en ese orden.
	 *
	 * @return array{url:string,width:int,height:int}|null
	 */
	public static function share_image() {
		$front      = (int) get_option( 'page_on_front' );
		$candidates = array();
		if ( $front ) {
			$candidates[] = (int) get_post_meta( $front, '_yoast_wpseo_opengraph-image-id', true );
		}
		$titles       = (array) get_option( 'wpseo_titles' );
		$social       = (array) get_option( 'wpseo_social' );
		$candidates[] = isset( $titles['open_graph_frontpage_image_id'] ) ? (int) $titles['open_graph_frontpage_image_id'] : 0;
		$candidates[] = isset( $social['og_default_image_id'] ) ? (int) $social['og_default_image_id'] : 0;
		$candidates[] = $front ? (int) get_post_thumbnail_id( $front ) : 0;
		$candidates[] = (int) get_theme_mod( 'custom_logo' );
		$candidates[] = (int) get_option( 'site_icon' );

		foreach ( array_filter( $candidates ) as $id ) {
			$src = wp_get_attachment_image_src( $id, 'full' );
			if ( $src && $src[0] ) {
				return array(
					'url'    => $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
				);
			}
		}
		return null;
	}

	/**
	 * Metadatos SEO automáticos de la URL propia del quiz. Filtrable con `msc_quiz_seo`.
	 */
	public static function seo( array $quiz ) {
		$total       = count( $quiz['questions'] );
		$description = $quiz['description'] ? $quiz['description'] : 'Responde estas {n} preguntas y revisa los resultados al final';
		$description = trim( preg_replace( '/\s+/', ' ', str_replace( '{n}', $total, $description ) ) );
		$site        = get_bloginfo( 'name' );
		$separator   = apply_filters( 'document_title_separator', '-' );

		return apply_filters(
			'msc_quiz_seo',
			array(
				'title'       => $quiz['title'] . ' ' . $separator . ' ' . $site,
				'og_title'    => $quiz['title'],
				'description' => wp_html_excerpt( $description, 300, '…' ),
				'url'         => self::quiz_url( $quiz['slug'] ),
				'site_name'   => $site,
				'locale'      => get_locale(),
				'image'       => self::share_image(),
				'index'       => 'open' === MSC_Quiz_Repository::availability( $quiz ),
				'modified'    => $quiz['updated_at'] ? mysql2date( 'c', $quiz['updated_at'] ) : '',
			),
			$quiz
		);
	}

	public static function font_url() {
		return 'https://fonts.googleapis.com/css2?family=Roboto:wght@100..900&display=swap';
	}

	private static function config() {
		return array(
			'restUrl' => esc_url_raw( rest_url( MSC_Quiz_REST::NS ) ),
			// Sólo para usuarios con sesión: una nonce cacheada (WP Fastest Cache) rompería el envío de visitantes.
			'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
		);
	}

	/**
	 * El HTML lleva el quiz embebido y su estado depende de la hora (publicación /
	 * expiración), así que las páginas que lo muestran no deben guardarse en caché.
	 * WP Super Cache, W3TC, etc. respetan DONOTCACHEPAGE; WP Fastest Cache la
	 * ignora y usa su propia función.
	 */
	public static function prevent_page_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( function_exists( 'wpfc_exclude_current_page' ) ) {
			wpfc_exclude_current_page();
		}
	}

	/** Vacía la caché de páginas tras crear, editar o borrar un quiz. */
	public static function purge_page_cache() {
		do_action( 'wpfc_clear_all_cache' ); // WP Fastest Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); // WP Super Cache.
		}
		do_action( 'litespeed_purge_all' );
	}

	public static function mount_markup( array $payload, $standalone ) {
		return sprintf(
			'<div class="msc-quiz" data-msc-quiz="%s"%s></div><noscript>Activa JavaScript para responder este quiz.</noscript>',
			esc_attr( wp_json_encode( $payload ) ),
			$standalone ? ' data-standalone="1"' : ''
		);
	}

	public static function register_assets() {
		$dist = MSC_QUIZ_DIR . 'assets/dist/';
		wp_register_style( 'msc-quiz-font', self::font_url(), array(), null );
		wp_register_style( 'msc-quiz-public', MSC_QUIZ_URL . 'assets/dist/public.css', array( 'msc-quiz-font' ), MSC_Quiz_Admin::asset_version( $dist . 'public.css' ) );
		wp_register_script( 'msc-quiz-public', MSC_QUIZ_URL . 'assets/dist/public.js', array(), MSC_Quiz_Admin::asset_version( $dist . 'public.js' ), true );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'slug' => '',
			),
			$atts,
			'msc_quiz'
		);

		$quiz = $atts['id'] ? MSC_Quiz_Repository::get_quiz( absint( $atts['id'] ) ) : MSC_Quiz_Repository::get_quiz_by_slug( sanitize_title( $atts['slug'] ) );
		if ( ! $quiz ) {
			return self::can_preview() ? '<p><em>[msc_quiz] Quiz no encontrado.</em></p>' : '';
		}

		$payload = self::payload( $quiz );
		if ( 'draft' === $payload['availability'] ) {
			return '';
		}

		self::prevent_page_cache();
		if ( ! wp_script_is( 'msc-quiz-public', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'msc-quiz-public' );
		wp_enqueue_script( 'msc-quiz-public' );
		if ( ! wp_scripts()->get_data( 'msc-quiz-public', 'data' ) ) {
			wp_localize_script( 'msc-quiz-public', 'MSC_QUIZ', self::config() );
		}

		return self::mount_markup( $payload, false );
	}

	public static function maybe_render_standalone() {
		$slug = get_query_var( self::QUERY_VAR );
		if ( ! $slug ) {
			return;
		}

		$quiz    = MSC_Quiz_Repository::get_quiz_by_slug( sanitize_title( $slug ) );
		$payload = $quiz ? self::payload( $quiz ) : null;

		if ( ! $payload || 'draft' === $payload['availability'] ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		status_header( 200 );
		self::prevent_page_cache();
		nocache_headers();
		$dist   = MSC_QUIZ_DIR . 'assets/dist/';
		$assets = array(
			'css'    => MSC_QUIZ_URL . 'assets/dist/public.css?ver=' . MSC_Quiz_Admin::asset_version( $dist . 'public.css' ),
			'js'     => MSC_QUIZ_URL . 'assets/dist/public.js?ver=' . MSC_Quiz_Admin::asset_version( $dist . 'public.js' ),
			'config' => self::config(),
		);

		include MSC_QUIZ_DIR . 'templates/standalone.php';
		exit;
	}
}
