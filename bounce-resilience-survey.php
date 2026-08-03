<?php
/**
 * Plugin Name: Bounce Resilience Survey
 * Description: Operational Resilience benchmarking survey — React form, custom storage, CSV export.
 * Version:     0.1.0
 * Author:      MyTeam Solution
 * Text Domain: brs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BRS_VERSION', '0.1.0' );
define( 'BRS_PATH', plugin_dir_path( __FILE__ ) );
define( 'BRS_URL', plugin_dir_url( __FILE__ ) );
define( 'BRS_TABLE', 'brs_submissions' );

require_once BRS_PATH . 'includes/class-brs-config.php';
require_once BRS_PATH . 'includes/class-brs-db.php';
require_once BRS_PATH . 'includes/class-brs-scoring.php';
require_once BRS_PATH . 'includes/class-brs-onedrive.php';
require_once BRS_PATH . 'includes/class-brs-rest.php';
require_once BRS_PATH . 'includes/class-brs-admin.php';
require_once BRS_PATH . 'includes/class-brs-reports.php';
require_once BRS_PATH . 'includes/class-brs-scheduled-report.php';

register_activation_hook( __FILE__, array( 'BRS_DB', 'install' ) );

add_action( 'rest_api_init', array( 'BRS_REST', 'register_routes' ) );
add_action( 'admin_menu', array( 'BRS_Admin', 'register_menu' ) );
add_action( 'admin_post_brs_save_wording', array( 'BRS_Admin', 'handle_save_wording' ) );
add_action( 'admin_post_brs_save_scoring', array( 'BRS_Admin', 'handle_save_scoring' ) );
add_action( 'admin_post_brs_reset_scoring', array( 'BRS_Admin', 'handle_reset_scoring' ) );
add_action( 'admin_post_brs_export_csv', array( 'BRS_Admin', 'handle_export_csv' ) );
add_action( 'admin_post_brs_delete_submission', array( 'BRS_Admin', 'handle_delete_submission' ) );
add_action( 'admin_post_brs_save_onedrive_settings', array( 'BRS_Admin', 'handle_save_onedrive_settings' ) );
add_action( 'admin_post_brs_onedrive_test_push', array( 'BRS_Admin', 'handle_onedrive_test_push' ) );
add_action( 'admin_post_brs_save_scheduled_report', array( 'BRS_Scheduled_Report', 'handle_save' ) );
add_action( 'admin_post_brs_send_test_report', array( 'BRS_Scheduled_Report', 'handle_test_send' ) );

/**
 * Registers the custom weekly/monthly cron intervals and the report-sending
 * cron callback - unconditional (not just in wp-admin), since WP-Cron's
 * pseudo-cron mechanism checks for due events on any front-end page load too.
 */
BRS_Scheduled_Report::init();

/**
 * Scheduled (not run inline) so a respondent's submit request returns
 * immediately - the call to Microsoft happens moments later in the
 * background, via WordPress's own cron mechanism.
 */
add_action( 'brs_push_onedrive', array( 'BRS_OneDrive', 'do_push' ) );

/**
 * Runs on every load so a plugin update that changes the schema still applies.
 */
add_action( 'plugins_loaded', array( 'BRS_DB', 'maybe_upgrade' ) );

/**
 * [bounce_survey] — mounts the React app.
 */
add_shortcode(
	'bounce_survey',
	function () {
		$script = BRS_PATH . 'build/survey.js';
		$style  = BRS_PATH . 'build/survey.css';

		if ( ! file_exists( $script ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p><strong>Bounce Resilience Survey:</strong> build/survey.js is missing. Run <code>npm run build</code> and upload the build folder.</p>';
			}
			return '';
		}

		// filemtime as the version string busts the browser cache on every rebuild.
		wp_enqueue_script( 'brs-survey', BRS_URL . 'build/survey.js', array(), filemtime( $script ), true );

		if ( file_exists( $style ) ) {
			wp_enqueue_style( 'brs-survey', BRS_URL . 'build/survey.css', array(), filemtime( $style ) );
		}

		wp_localize_script(
			'brs-survey',
			'BRS_DATA',
			array(
				'restUrl'        => esc_url_raw( rest_url( 'bounce/v1/submit' ) ),
				'resultsUrlBase' => esc_url_raw( rest_url( 'bounce/v1/results/' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'survey'         => BRS_Config::survey_for_display(),
				'copy'           => BRS_Config::copy(),
				// A real static file (assets/banner.png), not something Vite
				// processes - same reasoning as build/survey.js: WordPress
				// serves it directly, so it works at whatever URL the plugin
				// ends up installed at.
				'banner'         => file_exists( BRS_PATH . 'assets/banner.png' ) ? BRS_URL . 'assets/banner.png' : '',
				// Same static-file reasoning as banner above.
				'logo'           => file_exists( BRS_PATH . 'assets/BOUNCECIRCLE_RGB_white.png' ) ? BRS_URL . 'assets/BOUNCECIRCLE_RGB_white.png' : '',
			)
		);

		return '<div id="brs-root" class="brs-survey"></div>';
	}
);
