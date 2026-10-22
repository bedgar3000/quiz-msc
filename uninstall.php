<?php
/**
 * Al borrar el plugin desde wp-admin se eliminan sus tablas y opciones.
 * Para conservar los datos, define MSC_QUIZ_KEEP_DATA como true en wp-config.php.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'MSC_QUIZ_KEEP_DATA' ) && MSC_QUIZ_KEEP_DATA ) {
	return;
}

global $wpdb;
foreach ( array( 'quiz_submission_answers', 'quiz_submissions', 'quiz_answers', 'quiz_questions', 'quizzes' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}msc_{$table}" ); // phpcs:ignore WordPress.DB
}
delete_option( 'msc_quiz_db_version' );
