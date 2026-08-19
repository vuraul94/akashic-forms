<?php
/**
 * Uninstall routine for Akashic Forms.
 *
 * Runs only when the plugin is deleted from the WordPress admin. Removes the
 * plugin's tables, options, post meta and forms.
 *
 * Files that visitors uploaded through a form are deliberately left on disk:
 * deleting them here would silently destroy submitted documents that the site
 * owner may still need. Use "Clear Submissions" on each form first if you want
 * those removed.
 *
 * @package AkashicForms
 */

// Exit if not called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop the plugin's own tables.
$akashic_tables = array(
    $wpdb->prefix . 'akashic_form_field_values',
    $wpdb->prefix . 'akashic_form_queue',
    $wpdb->prefix . 'akashic_form_submissions',
);

foreach ( $akashic_tables as $akashic_table ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$akashic_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// Delete the plugin's options.
$akashic_options = array(
    'akashic_forms_google_client_id',
    'akashic_forms_google_client_secret',
    'akashic_forms_google_access_token',
    'akashic_forms_google_oauth_state',
    'akashic_forms_cron_enabled',
    'akashic_forms_cron_interval',
    'akashic_forms_queue_batch_size',
    'akashic_forms_max_attempts',
    'akashic_forms_db_version',
);

foreach ( $akashic_options as $akashic_option ) {
    delete_option( $akashic_option );
}

// Delete every form and its meta.
$akashic_form_ids = get_posts(
    array(
        'post_type'        => 'akashic_forms',
        'posts_per_page'   => -1,
        'post_status'      => 'any',
        'fields'           => 'ids',
        'suppress_filters' => true,
    )
);

foreach ( $akashic_form_ids as $akashic_form_id ) {
    wp_delete_post( $akashic_form_id, true );
}

// Sweep up any orphaned form meta left behind by posts deleted earlier.
$akashic_meta_keys = array(
    '_akashic_form_fields',
    '_akashic_form_email_recipient',
    '_akashic_form_email_subject',
    '_akashic_form_email_message',
    '_akashic_form_google_sheet_id',
    '_akashic_form_google_sheet_name',
    '_akashic_form_sheet_columns',
    '_akashic_form_submission_action',
    '_akashic_form_redirect_url',
    '_akashic_form_message',
    '_akashic_form_modal_message',
    '_akashic_form_submit_button_text',
    '_akashic_form_submitting_button_text',
);

foreach ( $akashic_meta_keys as $akashic_meta_key ) {
    $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $akashic_meta_key ), array( '%s' ) );
}

// Remove the scheduled queue processor.
wp_clear_scheduled_hook( 'akashic_forms_process_queue' );

// Remove the plugin's transients: the cron flag (akashic_forms_cron_started) and the
// per-IP rate limit counters, which use the shorter akf_rl_ prefix to stay inside the
// option_name length limit.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_akashic_forms_%'
        OR option_name LIKE '_transient_timeout_akashic_forms_%'
        OR option_name LIKE '_transient_akf_rl_%'
        OR option_name LIKE '_transient_timeout_akf_rl_%'"
); // phpcs:ignore WordPress.DB.PreparedSQL
