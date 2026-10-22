<?php
/**
 * Menú "Quiz MSC" en wp-admin (aplicación Vue).
 */

defined( 'ABSPATH' ) || exit;

class MSC_Quiz_Admin {

	const SLUG = 'msc-quiz';

	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_msc_quiz_export', array( __CLASS__, 'export_csv' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MSC_QUIZ_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		self::$hook = add_menu_page(
			'Quiz MSC',
			'Quiz MSC',
			MSC_Quiz_REST::capability(),
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-forms',
			26
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">Quizzes</a>' );
		return $links;
	}

	public static function render() {
		echo '<div class="wrap"><div id="msc-quiz-admin"></div></div>';
	}

	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_media();
		$dist = MSC_QUIZ_DIR . 'assets/dist/';
		wp_enqueue_style( 'msc-quiz-admin', MSC_QUIZ_URL . 'assets/dist/admin.css', array(), self::asset_version( $dist . 'admin.css' ) );
		wp_enqueue_script( 'msc-quiz-admin', MSC_QUIZ_URL . 'assets/dist/admin.js', array(), self::asset_version( $dist . 'admin.js' ), true );
		wp_localize_script(
			'msc-quiz-admin',
			'MSC_QUIZ_ADMIN',
			array(
				'restUrl'   => esc_url_raw( rest_url( MSC_Quiz_REST::NS ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'exportUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=msc_quiz_export' ), 'msc_quiz_export' ),
				'quizUrl'   => MSC_Quiz_Public::quiz_url( '__slug__' ),
				'now'       => current_time( 'Y-m-d\TH:i' ),
			)
		);
	}

	public static function asset_version( $file ) {
		return file_exists( $file ) ? (string) filemtime( $file ) : MSC_QUIZ_VERSION;
	}

	public static function export_csv() {
		if ( ! current_user_can( MSC_Quiz_REST::capability() ) ) {
			wp_die( 'No autorizado.', 403 );
		}
		check_admin_referer( 'msc_quiz_export' );

		$quiz = MSC_Quiz_Repository::get_quiz( isset( $_GET['quiz'] ) ? absint( $_GET['quiz'] ) : 0 );
		if ( ! $quiz ) {
			wp_die( 'Quiz no encontrado.', 404 );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="quiz-' . $quiz['slug'] . '-' . gmdate( 'Ymd' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM para que Excel lea los acentos.

		$header = array( 'ID', 'Fecha', 'Usuario', 'Email', 'IP', 'Puntuación', 'Total', '%' );
		foreach ( $quiz['questions'] as $i => $question ) {
			$header[] = 'P' . ( $i + 1 ) . ': ' . $question['question'];
			$header[] = 'P' . ( $i + 1 ) . ' correcta';
		}
		fputcsv( $out, $header );

		$page = 1;
		do {
			$batch = MSC_Quiz_Repository::list_submissions( $quiz['id'], $page, 500 );
			foreach ( $batch['items'] as $item ) {
				$by_question = array();
				foreach ( (array) $item['detail'] as $result ) {
					$by_question[ $result['question_id'] ] = $result;
				}
				$row = array( $item['id'], $item['created_at'], $item['user'], $item['email'], $item['ip'], $item['score'], $item['total'], $item['percentage'] );
				foreach ( $quiz['questions'] as $question ) {
					$result = isset( $by_question[ $question['id'] ] ) ? $by_question[ $question['id'] ] : null;
					$row[]  = $result ? implode( ' | ', wp_list_pluck( $result['selected'], 'answer' ) ) : '';
					$row[]  = $result ? ( $result['correct'] ? 'Sí' : 'No' ) : '';
				}
				fputcsv( $out, $row );
			}
			++$page;
		} while ( $page <= $batch['total_pages'] );

		fclose( $out );
		exit;
	}
}
