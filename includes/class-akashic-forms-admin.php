<?php

/**
 * Admin class for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('Akashic_Forms_Admin')) {

    class Akashic_Forms_Admin
    {

        /**
         * Constructor.
         */
        public function __construct()
        {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'export_submissions_to_csv'));
            add_action('admin_init', array($this, 'handle_clear_submissions'));
            add_action('admin_init', array($this, 'handle_delete_submission')); // Added this line
            add_action('admin_init', array($this, 'register_google_drive_settings'));
            add_action('admin_init', array($this, 'handle_google_drive_disconnect'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
            add_action('admin_init', array($this, 'handle_force_sync'));
            add_action('admin_init', array($this, 'handle_clear_queue'));
            add_action('admin_init', array($this, 'register_settings'));
        }

        /**
         * Enqueue admin scripts and styles.
         */
        public function enqueue_admin_scripts($hook)
        {
            global $post;

            if ('post.php' === $hook || 'post-new.php' === $hook) {
                if (isset($post->post_type) && 'akashic_forms' === $post->post_type) {
                    wp_enqueue_script('jquery-ui-sortable');
                    wp_enqueue_editor();
                }
            }
        }

        /**
         * Add admin menu items.
         */
        public function add_admin_menu()
        {
            add_menu_page(
                __('Akashic Forms', 'akashic-forms'),
                __('Akashic Forms', 'akashic-forms'),
                'manage_options',
                'akashic-forms',
                array($this, 'akashic_forms_page'),
                'dashicons-feedback',
                6
            );

            add_submenu_page(
                'akashic-forms',
                __('Submissions', 'akashic-forms'),
                __('Submissions', 'akashic-forms'),
                'manage_options',
                'akashic-forms-submissions',
                array($this, 'akashic_forms_submissions_page')
            );

            add_submenu_page(
                'akashic-forms',
                __('Google Drive Settings', 'akashic-forms'),
                __('Google Drive', 'akashic-forms'),
                'manage_options',
                'akashic-forms-google-drive-settings',
                array($this, 'akashic_forms_google_drive_settings_page')
            );

            add_submenu_page(
                'akashic-forms',
                __('Queue', 'akashic-forms'),
                __('Queue', 'akashic-forms'),
                'manage_options',
                'akashic-forms-queue',
                array($this, 'akashic_forms_queue_page')
            );

            add_submenu_page(
                'akashic-forms',
                __('Settings', 'akashic-forms'),
                __('Settings', 'akashic-forms'),
                'manage_options',
                'akashic-forms-settings',
                array($this, 'akashic_forms_settings_page')
            );
        }

        /**
         * Render the main Akashic Forms admin page.
         */
        public function akashic_forms_page()
        {
?>
            <div class="wrap">
                <h1><?php _e('Akashic Forms', 'akashic-forms'); ?></h1>
                <p><?php _e('Welcome to Akashic Forms! Use the menu on the left to create and manage your forms, and view submissions.', 'akashic-forms'); ?></p>
            </div>
        <?php
        }

        /**
         * Render the Submissions admin page.
         */
        public function akashic_forms_submissions_page()
        {
            if (! class_exists('WP_List_Table')) {
                require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
            }

            require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-submissions-list-table.php';

            $form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;

            $forms = get_posts(
                array(
                    'post_type'      => 'akashic_forms',
                    'posts_per_page' => -1,
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                )
            );

            // If no form_id is set, and there are forms, default to the first one.
            if (! $form_id && ! empty($forms)) {
                $form_id = $forms[0]->ID;
            }

            $form_title = $form_id ? get_the_title($form_id) : __('No Form Selected', 'akashic-forms');

            echo '<div class="wrap">';
            echo '<h1>' . sprintf(__('Submissions for: %s', 'akashic-forms'), esc_html($form_title));

            if ($form_id) {
                $export_url = add_query_arg(
                    array(
                        'action'  => 'export_csv',
                        'form_id' => $form_id,
                    ),
                    admin_url('admin.php?page=akashic-forms-submissions')
                );
                echo ' <a href="' . esc_url($export_url) . '" class="page-title-action">' . __('Export to CSV', 'akashic-forms') . '</a>';

                $clear_url = wp_nonce_url(
                    add_query_arg(
                        array(
                            'action'  => 'clear_submissions',
                            'form_id' => $form_id,
                        ),
                        admin_url('admin.php?page=akashic-forms-submissions')
                    ),
                    'akashic_clear_submissions_' . $form_id
                );
                echo ' <a href="' . esc_url($clear_url) . '" class="page-title-action" style="color:#a00;" onclick="return confirm(\''. __('Are you sure you want to permanently delete all submissions and related files for this form?', 'akashic-forms') . '\');">' . __('Clear Submissions', 'akashic-forms') . '</a>';
            }

            echo '</h1>';

            if (isset($_GET['cleared']) && 'true' === $_GET['cleared']) {
                echo '<div class="notice notice-success is-dismissible"><p>' . __('All submissions for this form have been deleted.', 'akashic-forms') . '</p></div>';
            }

            echo '<div class="akashic-forms-filter">';
            echo '<label for="akashic-form-selector">' . __('Select Form:', 'akashic-forms') . '</label>';
            echo '<select name="akashic_form_selector" id="akashic-form-selector" onchange="window.location.href = this.value;">';
            echo '<option value="' . esc_url(admin_url('admin.php?page=akashic-forms-submissions')) . '">' . __('— Select a Form —', 'akashic-forms') . '</option>';
            if (! empty($forms)) {
                foreach ($forms as $form) {
                    $selected = selected($form_id, $form->ID, false);
                    echo '<option value="' . esc_url(add_query_arg('form_id', $form->ID, admin_url('admin.php?page=akashic-forms-submissions'))) . '" ' . $selected . '>' . esc_html($form->post_title) . '</option>';
                }
            }
            echo '</select>';
            echo '</div>';

            if ($form_id) {
                $submissions_table = new Akashic_Forms_Submissions_List_Table($form_id, 'completed'); // Pass form_id and status
                $submissions_table->prepare_items();
                $submissions_table->display();
            } else {
                echo '<p>' . __('Please select a form from the dropdown above to view its submissions.', 'akashic-forms') . '</p>';
            }
            echo '</div>';
        }


        /**
         * Render the Google Drive Settings admin page.
         */
        public function akashic_forms_google_drive_settings_page()
        {
        ?>
            <div class="wrap">
                <h1><?php _e('Google Drive Integration Settings', 'akashic-forms'); ?></h1>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('akashic_forms_google_drive_settings_group');
                    do_settings_sections('akashic-forms-google-drive-settings');
                    submit_button();
                    ?>
                </form>
                <?php
                $client        = new Akashic_Forms_Google_Drive();
                $google_client = $client->get_google_client();

                if ($google_client) {
                    if (! $google_client->getAccessToken()) {
                        $auth_url = $google_client->createAuthUrl();
                        echo '<p><a href="' . esc_url($auth_url) . '">' . __('Authorize Google Drive Integration', 'akashic-forms') . '</a></p>';
                    } else {
                        echo '<p>' . __('Google Drive is connected.', 'akashic-forms') . '</p>';
                        echo '<p><a href="' . esc_url(add_query_arg('akashic_forms_google_disconnect', '1', admin_url('admin.php?page=akashic-forms-google-drive-settings'))) . '">' . __('Disconnect Google Drive', 'akashic-forms') . '</a></p>';
                    }
                } else {
                    echo '<p class="error">' . __('Please enter your Google API Client ID and Client Secret to enable Google Drive integration.', 'akashic-forms') . '</p>';
                }
                ?>
            </div>
<?php
        }

        /**
         * Register Google Drive settings.
         */
        public function register_google_drive_settings()
        {
            register_setting(
                'akashic_forms_google_drive_settings_group',
                'akashic_forms_google_client_id',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                )
            );

            register_setting(
                'akashic_forms_google_drive_settings_group',
                'akashic_forms_google_client_secret',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                )
            );

            add_settings_section(
                'akashic_forms_google_drive_section',
                __('Google API Credentials', 'akashic-forms'),
                array($this, 'google_drive_section_callback'),
                'akashic-forms-google-drive-settings'
            );

            add_settings_field(
                'akashic_forms_google_client_id_field',
                __('Client ID', 'akashic-forms'),
                array($this, 'client_id_field_callback'),
                'akashic-forms-google-drive-settings',
                'akashic_forms_google_drive_section'
            );

            add_settings_field(
                'akashic_forms_google_client_secret_field',
                __('Client Secret', 'akashic-forms'),
                array($this, 'client_secret_field_callback'),
                'akashic-forms-google-drive-settings',
                'akashic_forms_google_drive_section'
            );
        }

        /**
         * Google Drive section callback.
         */
        public function google_drive_section_callback()
        {
            echo '<p>' . __('Enter your Google API Client ID and Client Secret. You can create these credentials in the Google API Console.', 'akashic-forms') . '</p>';
            echo '<p>' . sprintf(__('The authorized redirect URI for your Google project should be: %s', 'akashic-forms'), '<code>' . admin_url('admin.php?page=akashic-forms-google-drive-settings') . '</code>') . '</p>';
        }

        /**
         * Client ID field callback.
         */
        public function client_id_field_callback()
        {
            $client_id = get_option('akashic_forms_google_client_id');
            echo '<input type="text" name="akashic_forms_google_client_id" value="' . esc_attr($client_id) . '" class="regular-text" />';
            echo '<p class="description">' . sprintf( __( 'Refer to this guide to get your Client ID: %s', 'akashic-forms' ), '<a href="https://developers.google.com/identity/protocols/oauth2/web-server#creatingcred" target="_blank">https://developers.google.com/identity/protocols/oauth2/web-server#creatingcred</a>' ) . '</p>';
        }

        /**
         * Client Secret field callback.
         */
        public function client_secret_field_callback()
        {
            $client_secret = get_option('akashic_forms_google_client_secret');
            echo '<input type="text" name="akashic_forms_google_client_secret" value="' . esc_attr($client_secret) . '" class="regular-text" />';
            echo '<p class="description">' . sprintf( __( 'Refer to this guide to get your Client Secret: %s', 'akashic-forms' ), '<a href="https://developers.google.com/identity/protocols/oauth2/web-server#creatingcred" target="_blank">https://developers.google.com/identity/protocols/oauth2/web-server#creatingcred</a>' ) . '</p>';
        }

        /**
         * Handle Google Drive disconnection.
         */
        public function handle_google_drive_disconnect()
        {
            if (isset($_GET['akashic_forms_google_disconnect']) && '1' === $_GET['akashic_forms_google_disconnect']) {
                if (! current_user_can('manage_options')) {
                    return;
                }
                delete_option('akashic_forms_google_access_token');
                wp_redirect(admin_url('admin.php?page=akashic-forms-google-drive-settings'));
                exit;
            }
        }

        /**
         * Export submissions to CSV.
         */
        public function export_submissions_to_csv()
        {
            if (! isset($_GET['action']) || 'export_csv' !== $_GET['action']) {
                return;
            }

            if (! current_user_can('manage_options')) {
                return;
            }

            $form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;
            if (! $form_id) {
                return;
            }

            // Clean any previous output buffer to prevent corrupting the CSV file.
            if (ob_get_level()) {
                ob_end_clean();
            }

            $form_title = sanitize_title(get_the_title($form_id));
            $filename   = 'akashic-form-submissions-' . $form_title . '-' . date('Y-m-d') . '.csv';

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=' . $filename);

            $output = fopen('php://output', 'w');

            // Add UTF-8 BOM to ensure Excel reads special characters correctly.
            fprintf($output, "\xEF\xBB\xBF");

            // Prepare header row
            $form_fields = get_post_meta($form_id, '_akashic_form_fields', true);
            $header_row  = array();
            if (! empty($form_fields)) {
                foreach ($form_fields as $field) {
                    // Only include fields that have a name (are actual inputs)
                    if (isset($field['label']) && ! empty($field['name'])) {
                        $header_row[$field['name']] = $field['label'];
                    }
                }
            }
            $header_row['submitted_at'] = __('Submitted At', 'akashic-forms');
            fputcsv($output, array_values($header_row));

            // Prepare data rows
            $db          = new Akashic_Forms_DB();
            $submissions = $db->get_submissions($form_id);

            foreach ($submissions as $submission) {
                $row = array();
                // Match data to headers to ensure correct column order
                foreach ($header_row as $name => $label) {
                    $value = '';
                    if ('submitted_at' === $name) {
                        $value = $submission->submitted_at;
                    } elseif (isset($submission->submission_data[$name])) {
                        $value = $submission->submission_data[$name];
                    }

                    if (is_array($value)) {
                        $value = implode(', ', $value);
                    }

                    // Sanitize value to ensure it's valid UTF-8, stripping invalid characters.
                    $row[] = function_exists('iconv') ? iconv('UTF-8', 'UTF-8//IGNORE', $value) : $value;
                }
                fputcsv($output, $row);
            }

            fclose($output);
            exit;
        }

        /**
         * Handle clearing all submissions for a form.
         */
        public function handle_clear_submissions()
        {
            if (! isset($_GET['action']) || 'clear_submissions' !== $_GET['action']) {
                return;
            }

            if (! current_user_can('manage_options')) {
                return;
            }

            $form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;
            if (! $form_id) {
                return;
            }

            check_admin_referer('akashic_clear_submissions_' . $form_id);

            $db          = new Akashic_Forms_DB();
            $submissions = $db->get_submissions($form_id);

            if (! empty($submissions)) {
                foreach ($submissions as $submission) {
                    // Check for and delete any associated files.
                    foreach ($submission->submission_data as $field_name => $value) {
                        if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL) && strpos($value, content_url()) !== false) {
                            // Convert URL to server path to delete the file.
                            $file_path = str_replace(content_url(), WP_CONTENT_DIR, $value);
                            if (file_exists($file_path)) {
                                wp_delete_file($file_path);
                            }
                        }
                    }
                }

                // Delete all submissions from the database for this form.
                $db->delete_submissions($form_id);
            }

            // Redirect back to the submissions page with a success message.
            wp_safe_redirect(add_query_arg(array('page' => 'akashic-forms-submissions', 'form_id' => $form_id, 'cleared' => 'true'), admin_url('admin.php')));
            exit;
        }

        /**
         * Handle deleting a single submission.
         */
        public function handle_delete_submission()
        {
            if (! isset($_GET['action']) || 'delete_submission' !== $_GET['action']) {
                return;
            }

            if (! current_user_can('manage_options')) {
                return;
            }

            $submission_id = isset($_GET['submission_id']) ? absint($_GET['submission_id']) : 0;
            if (! $submission_id) {
                return;
            }

            check_admin_referer('akashic_delete_submission_' . $submission_id);

            $db = new Akashic_Forms_DB();
            $submission = $db->get_submission($submission_id);

            if ($submission) {
                // Delete associated files
                foreach ($submission->submission_data as $field_name => $value) {
                    if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL) && strpos($value, content_url()) !== false) {
                        $file_path = str_replace(content_url(), WP_CONTENT_DIR, $value);
                        if (file_exists($file_path)) {
                            wp_delete_file($file_path);
                        }
                    }
                }

                // Delete the submission from the database
                $db->delete_submission($submission_id);
            }

            // Redirect back to the submissions page with a success message.
            wp_safe_redirect(add_query_arg(array('page' => 'akashic-forms-submissions', 'form_id' => $_GET['form_id'], 'deleted' => 'true'), admin_url('admin.php')));
            exit;
        }

        /**
         * Render the Queue admin page.
         */
        public function akashic_forms_queue_page()
        {
            if (!class_exists('WP_List_Table')) {
                require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
            }

            require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-queue-list-table.php';

            echo '<div class="wrap">';
            echo '<h1>' . __('Submission Queue', 'akashic-forms') . '</h1>';

            $force_sync_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action'  => 'force_sync',
                    ),
                    admin_url('admin.php?page=akashic-forms-queue')
                ),
                'akashic_force_sync'
            );
            echo '<a href="' . esc_url($force_sync_url) . '" class="page-title-action">' . __('Force Sync', 'akashic-forms') . '</a>';

            $clear_queue_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action'  => 'clear_queue',
                    ),
                    admin_url('admin.php?page=akashic-forms-queue')
                ),
                'akashic_clear_queue'
            );
            echo ' <a href="' . esc_url($clear_queue_url) . '" class="page-title-action" style="color:#a00;" onclick="return confirm(\' . esc_js(__( \'Are you sure you want to permanently delete all submissions in the queue?\', \'akashic-forms\' )) . \'\');">' . __('Clear Queue', 'akashic-forms') . '</a>';

            if (isset($_GET['synced']) && $_GET['synced']) {
                echo '<div class="notice notice-success is-dismissible"><p>' . __('Queue processing started.', 'akashic-forms') . '</p></div>';
            }

            if (isset($_GET['cleared']) && 'true' === $_GET['cleared']) {
                echo '<div class="notice notice-success is-dismissible"><p>' . __('The queue has been cleared.', 'akashic-forms') . '</p></div>';
            }

            $queue_table = new Akashic_Forms_Queue_List_Table();
            $queue_table->prepare_items();
            $queue_table->display();

            echo '</div>';
        }

        /**
         * Handle the force sync action.
         */
        public function handle_force_sync()
        {
            if (!isset($_GET['action']) || 'force_sync' !== $_GET['action']) {
                return;
            }

            if (!current_user_can('manage_options')) {
                return;
            }

            check_admin_referer('akashic_force_sync');

            $queue_processor = new Akashic_Forms_Queue_Processor();
            $queue_processor->process_queue( true );

            wp_safe_redirect(add_query_arg(array('page' => 'akashic-forms-queue', 'synced' => 'true'), admin_url('admin.php')));
            exit;
        }

        /**
         * Handle the clear queue action.
         */
        public function handle_clear_queue()
        {
            if (!isset($_GET['action']) || 'clear_queue' !== $_GET['action']) {
                return;
            }

            if (!current_user_can('manage_options')) {
                return;
            }

            check_admin_referer('akashic_clear_queue');

            $db = new Akashic_Forms_DB();
            $db->clear_queue();

            wp_safe_redirect(add_query_arg(array('page' => 'akashic-forms-queue', 'cleared' => 'true'), admin_url('admin.php')));
            exit;
        }

        /**
         * Render the Settings admin page.
         */
        public function akashic_forms_settings_page()
        {
            ?>
            <div class="wrap">
                <h1><?php _e('Akashic Forms Settings', 'akashic-forms'); ?></h1>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('akashic_forms_settings_group');
                    do_settings_sections('akashic-forms-settings');
                    submit_button();
                    ?>
                </form>
            </div>
            <?php
        }

        /**
         * Register settings.
         */
        public function register_settings()
        {
            register_setting(
                'akashic_forms_settings_group',
                'akashic_forms_cron_enabled',
                array(
                    'type'              => 'boolean',
                    'sanitize_callback' => 'rest_sanitize_boolean',
                    'default'           => true,
                )
            );

            register_setting(
                'akashic_forms_settings_group',
                'akashic_forms_cron_interval',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => 'five_minutes',
                )
            );

            add_settings_section(
                'akashic_forms_cron_section',
                __('Cron Settings', 'akashic-forms'),
                array($this, 'cron_section_callback'),
                'akashic-forms-settings'
            );

            add_settings_field(
                'akashic_forms_cron_enabled_field',
                __('Enable Cron', 'akashic-forms'),
                array($this, 'cron_enabled_field_callback'),
                'akashic-forms-settings',
                'akashic_forms_cron_section'
            );

            add_settings_field(
                'akashic_forms_cron_interval_field',
                __('Cron Interval', 'akashic-forms'),
                array($this, 'cron_interval_field_callback'),
                'akashic-forms-settings',
                'akashic_forms_cron_section'
            );
        }

        /**
         * Cron section callback.
         */
        public function cron_section_callback()
        {
            echo '<p>' . __('Configure the cron job for processing the submission queue.', 'akashic-forms') . '</p>';
            $timestamp = wp_next_scheduled('akashic_forms_process_queue');
            if ($timestamp) {
                echo '<p>' . sprintf(__('Next run: %s', 'akashic-forms'), get_date_from_gmt(date('Y-m-d H:i:s', $timestamp), 'Y-m-d H:i:s')) . '</p>';
            }
        }

        /**
         * Cron enabled field callback.
         */
        public function cron_enabled_field_callback()
        {
            $enabled = get_option('akashic_forms_cron_enabled', true);
            echo '<input type="checkbox" name="akashic_forms_cron_enabled" value="1" ' . checked(1, $enabled, false) . ' />';
        }

        /**
         * Cron interval field callback.
         */
        public function cron_interval_field_callback()
        {
            $interval = get_option('akashic_forms_cron_interval', 'five_minutes');
            ?>
            <select name="akashic_forms_cron_interval">
                <option value="five_minutes" <?php selected($interval, 'five_minutes'); ?>><?php _e('Every 5 minutes', 'akashic-forms'); ?></option>
                <option value="fifteen_minutes" <?php selected($interval, 'fifteen_minutes'); ?>><?php _e('Every 15 minutes', 'akashic-forms'); ?></option>
                <option value="thirty_minutes" <?php selected($interval, 'thirty_minutes'); ?>><?php _e('Every 30 minutes', 'akashic-forms'); ?></option>
                <option value="hourly" <?php selected($interval, 'hourly'); ?>><?php _e('Every hour', 'akashic-forms'); ?></option>
            </select>
            <?php
        }
    }
}

new Akashic_Forms_Admin();
