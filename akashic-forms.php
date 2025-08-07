<?php
/**
 * Plugin Name: Akashic Forms
 * Plugin URI:  https://example.com/akashic-forms
 * Description: A custom form builder and submission management plugin for WordPress.
 * Version:     1.0.0
 * Author:      Your Name
 * Author URI:  https://example.com
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: akashic-forms
 * Domain Path: /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants.
define( 'AKASHIC_FORMS_VERSION', '1.0.0' );
define( 'AKASHIC_FORMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AKASHIC_FORMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-cpt.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-cpt.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-metabox.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-shortcode.php';
// Include necessary files.
// require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-cpt.php';
// require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-shortcode.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-db.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-submission-handler.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-admin.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-google-drive.php';

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing hooks.
 */
// require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then there is no need to explicitly call any action or filter hook.
 *
 * When the plugin is loaded, it will begin to register the hooks
 * with WordPress.
 */
// function run_akashic_forms() {
//     $plugin = new Akashic_Forms();
//     $plugin->run();
// }
// run_akashic_forms();
