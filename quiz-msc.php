<?php
/**
 * Plugin Name: MSC Quiz
 * Description: Creación y publicación de quizzes/encuestas con interfaz en Vue.js. Shortcode [msc_quiz] y URL propia por quiz.
 * Version:     1.0.0
 * Author:      KTech
 * Text Domain: quiz-msc
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'MSC_QUIZ_VERSION', '1.0.0' );
define( 'MSC_QUIZ_DB_VERSION', '1.1.0' );
define( 'MSC_QUIZ_FILE', __FILE__ );
define( 'MSC_QUIZ_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSC_QUIZ_URL', plugin_dir_url( __FILE__ ) );

require_once MSC_QUIZ_DIR . 'includes/class-msc-quiz-db.php';
require_once MSC_QUIZ_DIR . 'includes/class-msc-quiz-repository.php';
require_once MSC_QUIZ_DIR . 'includes/class-msc-quiz-rest.php';
require_once MSC_QUIZ_DIR . 'includes/class-msc-quiz-admin.php';
require_once MSC_QUIZ_DIR . 'includes/class-msc-quiz-public.php';

register_activation_hook( __FILE__, array( 'MSC_Quiz_DB', 'activate' ) );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

add_action( 'plugins_loaded', array( 'MSC_Quiz_DB', 'maybe_upgrade' ) );
add_action( 'rest_api_init', array( 'MSC_Quiz_REST', 'register_routes' ) );

MSC_Quiz_Admin::init();
MSC_Quiz_Public::init();
