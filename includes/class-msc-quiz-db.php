<?php
/**
 * Esquema de base de datos.
 *
 * Se usan tablas relacionadas (quiz → preguntas → respuestas) en lugar de un
 * campo JSON: permite editar una pregunta sin reescribir todo el quiz, mantener
 * IDs estables para las estadísticas, ordenar con un índice y consultar por SQL
 * cuántas veces se eligió cada respuesta. Las respuestas de los usuarios guardan
 * además una copia JSON (snapshot) de lo que vieron, para que el historial siga
 * siendo legible aunque el quiz se edite después.
 */

defined( 'ABSPATH' ) || exit;

class MSC_Quiz_DB {

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'msc_' . $name;
	}

	public static function activate() {
		self::install();
		MSC_Quiz_Public::add_rewrite_rules();
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'msc_quiz_db_version' ) !== MSC_QUIZ_DB_VERSION ) {
			self::install();
			// Las reglas se registran en init; se vacían al final de esa petición.
			add_action( 'init', 'flush_rewrite_rules', 99 );
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset     = $wpdb->get_charset_collate();
		$quizzes     = self::table( 'quizzes' );
		$questions   = self::table( 'quiz_questions' );
		$answers     = self::table( 'quiz_answers' );
		$submissions = self::table( 'quiz_submissions' );
		$picks       = self::table( 'quiz_submission_answers' );

		dbDelta(
			"CREATE TABLE {$quizzes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL DEFAULT '',
			slug varchar(200) NOT NULL DEFAULT '',
			eyebrow varchar(255) NOT NULL DEFAULT '',
			description text NULL,
			footer_text varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'draft',
			published_at datetime NULL DEFAULT NULL,
			expires_at datetime NULL DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$questions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quiz_id bigint(20) unsigned NOT NULL,
			position int(11) NOT NULL DEFAULT 0,
			question text NOT NULL,
			instruction varchar(255) NOT NULL DEFAULT '',
			note text NULL,
			type varchar(10) NOT NULL DEFAULT 'single',
			PRIMARY KEY  (id),
			KEY quiz_position (quiz_id,position)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$answers} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			question_id bigint(20) unsigned NOT NULL,
			position int(11) NOT NULL DEFAULT 0,
			answer text NOT NULL,
			note text NULL,
			selection_note text NULL,
			is_correct tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY question_position (question_id,position)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$submissions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quiz_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			score smallint(5) unsigned NOT NULL DEFAULT 0,
			total smallint(5) unsigned NOT NULL DEFAULT 0,
			percentage decimal(5,2) NOT NULL DEFAULT 0,
			ip varchar(45) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			detail longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY quiz_created (quiz_id,created_at),
			KEY user_id (user_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$picks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_id bigint(20) unsigned NOT NULL,
			question_id bigint(20) unsigned NOT NULL,
			answer_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY submission_id (submission_id),
			KEY question_answer (question_id,answer_id)
			) {$charset};"
		);

		update_option( 'msc_quiz_db_version', MSC_QUIZ_DB_VERSION );
	}
}
