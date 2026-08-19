<?php
/**
 * Plugin Name: Akashic Forms
 * Plugin URI:  https://example.com/akashic-forms
 * Description: A custom form builder and submission management plugin for WordPress.
 * Version:     1.3.0
 * Author:      Raúl Venegas
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: akashic-forms
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants.
define( 'AKASHIC_FORMS_VERSION', '1.3.0' );

/**
 * Schema version. Bump this whenever a table definition changes so the
 * upgrade routine runs dbDelta (and any data migration) on existing installs.
 * Activation alone is not enough: it never fires on a plugin update.
 */
define( 'AKASHIC_FORMS_DB_VERSION', '2' );

define( 'AKASHIC_FORMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AKASHIC_FORMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AKASHIC_FORMS_PLUGIN_FILE', __FILE__ );

/**
 * Write a debug message to the error log.
 *
 * Logging is opt-in: nothing is written unless WP_DEBUG and WP_DEBUG_LOG are
 * both on, or the akashic_forms_enable_log filter returns true. Never pass
 * tokens, secrets or submitted personal data to this function.
 *
 * @param string $message The message to log.
 */
function akashic_forms_log( $message ) {
    $enabled = defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

    /**
     * Filter whether Akashic Forms writes to the debug log.
     *
     * @param bool $enabled Whether logging is enabled.
     */
    if ( ! apply_filters( 'akashic_forms_enable_log', $enabled ) ) {
        return;
    }

    error_log( 'Akashic Forms: ' . $message );
}

// Include necessary files.
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-cpt.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-options-importer.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-metabox.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-shortcode.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-db.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-admin.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-google-drive.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-rest-api.php';
require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-queue-processor.php';

/**
 * Load the plugin text domain.
 *
 * The header has always declared a text domain and a Domain Path, but nothing
 * ever loaded it, so none of the __() calls in the plugin could translate.
 */
function akashic_forms_load_textdomain() {
    load_plugin_textdomain(
        'akashic-forms',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}
add_action( 'init', 'akashic_forms_load_textdomain' );

/**
 * Run pending schema upgrades.
 *
 * dbDelta only ran on activation, which never happens on an update, so new
 * columns and indexes never reached existing installs.
 */
function akashic_forms_maybe_upgrade() {
    Akashic_Forms_DB::maybe_upgrade();
}
add_action( 'plugins_loaded', 'akashic_forms_maybe_upgrade' );

/**
 * Enqueue scripts and styles.
 */
function akashic_forms_enqueue_scripts() {
    wp_enqueue_style( 'akashic-forms-public', AKASHIC_FORMS_PLUGIN_URL . 'assets/css/akashic-forms-public.css', array(), AKASHIC_FORMS_VERSION );
    wp_enqueue_script( 'akashic-forms-public', AKASHIC_FORMS_PLUGIN_URL . 'assets/js/akashic-forms-public.js', array( 'jquery' ), AKASHIC_FORMS_VERSION, true );
    wp_localize_script( 'akashic-forms-public', 'akashicForms', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'rest_url' => rest_url( 'akashic-forms/v1' ),
        'i18n'     => array(
            /* translators: %s: field label. */
            'required'       => __( '%s es obligatorio.', 'akashic-forms' ),
            'thisField'      => __( 'Este campo', 'akashic-forms' ),
            'file'           => __( 'Archivo', 'akashic-forms' ),
            'invalidEmail'   => __( 'Introduce una dirección de correo válida.', 'akashic-forms' ),
            'invalidFormat'  => __( 'Formato no válido.', 'akashic-forms' ),
            'securityFailed' => __( 'No se pudo verificar la seguridad del envío. Recarga la página e inténtalo de nuevo.', 'akashic-forms' ),
            'prepareError'   => __( 'Ocurrió un error al preparar el formulario. Recarga la página e inténtalo de nuevo.', 'akashic-forms' ),
            'unknownError'   => __( 'Ocurrió un error al enviar el formulario. Inténtalo de nuevo.', 'akashic-forms' ),
            'sessionExpired' => __( 'Tu sesión expiró. Recarga la página e inténtalo de nuevo.', 'akashic-forms' ),
            'rateLimited'    => __( 'Has enviado demasiados formularios en poco tiempo. Espera unos minutos e inténtalo de nuevo.', 'akashic-forms' ),
            /* translators: %s: file name. */
            'fileSelected'   => __( 'Archivo: %s', 'akashic-forms' ),
            /* translators: %d: number of files. */
            'filesSelected'  => __( '%d archivos seleccionados', 'akashic-forms' ),
            /* translators: %s: maximum file size, already formatted (e.g. "10 MB"). */
            'maxSize'        => __( 'Tamaño máximo de %s', 'akashic-forms' ),
            /* translators: %s: comma-separated list of file extensions. */
            'validFormats'   => __( 'Formatos válidos: %s', 'akashic-forms' ),
            'dropOrUpload'   => __( 'Arrastra o %s', 'akashic-forms' ),
            'uploadFiles'    => __( 'Sube archivos', 'akashic-forms' ),
        ),
    ) );
}
add_action( 'wp_enqueue_scripts', 'akashic_forms_enqueue_scripts' );

/**
 * Provide a fresh nonce for AJAX calls to prevent caching issues.
 */
function akashic_forms_get_nonce() {
    wp_send_json_success( array(
        'nonce' => wp_create_nonce( 'wp_rest' ),
    ) );
}
add_action( 'wp_ajax_akashic_forms_get_nonce', 'akashic_forms_get_nonce' );
add_action( 'wp_ajax_nopriv_akashic_forms_get_nonce', 'akashic_forms_get_nonce' );

/**
 * Clear the cron job on deactivation.
 */
function akashic_forms_deactivate() {
    wp_clear_scheduled_hook( 'akashic_forms_process_queue' );
}
register_deactivation_hook( __FILE__, 'akashic_forms_deactivate' );
