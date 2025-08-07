<?php
/**
 * Plugin Name: Akashic Forms
 * Plugin URI:  https://example.com/akashic-forms
 * Description: A custom form builder and submission management plugin for WordPress.
 * Version:     1.0.1
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
define( 'AKASHIC_FORMS_VERSION', '1.0.1' );
define( 'AKASHIC_FORMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AKASHIC_FORMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include necessary files.
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-cpt.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-metabox.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-shortcode.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-db.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-submission-handler.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-admin.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-google-drive.php';

/**
 * Enqueue scripts and styles.
 */
function akashic_forms_enqueue_scripts() {
    wp_enqueue_style( 'akashic-forms-public', AKASHIC_FORMS_PLUGIN_URL . 'assets/css/akashic-forms-public.css', array(), AKASHIC_FORMS_VERSION );
    wp_enqueue_script( 'akashic-forms-public', AKASHIC_FORMS_PLUGIN_URL . 'assets/js/akashic-forms-public.js', array( 'jquery' ), AKASHIC_FORMS_VERSION, true );
    wp_localize_script( 'akashic-forms-public', 'akashicForms', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
    ) );
}
add_action( 'wp_enqueue_scripts', 'akashic_forms_enqueue_scripts' );