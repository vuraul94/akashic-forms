<?php

/**
 * REST API for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('Akashic_Forms_REST_API')) {

    class Akashic_Forms_REST_API
    {

        /**
         * Constructor.
         */
        public function __construct()
        {
            add_action('rest_api_init', array($this, 'register_routes'));
        }

        /**
         * Register the REST API routes.
         */
        public function register_routes()
        {
            register_rest_route('akashic-forms/v1', '/sync', array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_sync_request'),
                'permission_callback' => '__return_true', // For simplicity, but should be secured.
            ));
        }

        /**
         * Handle the sync request.
         *
         * @param WP_REST_Request $request The REST request object.
         * @return WP_REST_Response The REST response object.
         */
        public function handle_sync_request($request)
        {
            $form_id = $request->get_param('form_id');

            $submitted_at = $request->get_param('submitted_at');

            $all_params = $request->get_params();
            $form_data = array();

            // Get form fields definition to identify file types
            $form_fields_definition = get_post_meta($form_id, '_akashic_form_fields', true);
            $db = new Akashic_Forms_DB();
            $errors = array();

            if (is_array($form_fields_definition)) {
                foreach ($form_fields_definition as $field) {
                    $field_name = isset($field['name']) ? $field['name'] : '';
                    if (empty($field_name) || (isset($field['type']) && $field['type'] === 'file')) {
                        continue; // Skip file fields for now, they are validated separately
                    }

                    $field_value = $request->get_param($field_name);

                    // Required validation
                    $is_required = isset($field['required']) && $field['required'] == '1';
                    if ($is_required && empty($field_value)) {
                        $errors[$field_name] = sprintf(__('%s is required.', 'akashic-forms'), $field['label']);
                        continue;
                    }

                    // Unique validation
                    $is_unique = isset($field['unique']) && $field['unique'] == '1';
                    if ($is_unique && !empty($field_value)) {
                        if (!$db->is_value_unique($form_id, $field_name, $field_value)) {
                            $unique_message = isset($field['unique_message']) && !empty($field['unique_message'])
                                ? $field['unique_message']
                                : __('This value has already been entered', 'akashic-forms');
                            $errors[$field_name] = $unique_message;
                        }
                    }

                    // Pattern validation
                    $pattern = isset($field['pattern']) ? $field['pattern'] : '';
                    if (!empty($pattern) && !empty($field_value)) {
                        if (!preg_match("/^$pattern$/", $field_value)) {
                            $errors[$field_name] = isset($field['validation_message']) && !empty($field['validation_message'])
                                ? $field['validation_message']
                                : __('Invalid format.', 'akashic-forms');
                        }
                    }
                }
            }

            if (!is_array($form_fields_definition)) {
                $form_fields_definition = array();
            }
            $field_types_map = array();
            foreach ($form_fields_definition as $field_def) {
                if (isset($field_def['name']) && isset($field_def['type'])) {
                    $field_types_map[$field_def['name']] = $field_def['type'];
                }
            }

            // Process regular fields
            foreach ($all_params as $key => $value) {
                if (!in_array($key, ['form_id', 'submitted_at', 'action', '_wpnonce', '_wp_http_referer'])) {
                    if (isset($field_types_map[$key]) && 'file' === $field_types_map[$key]) {
                        continue;
                    }
                    $form_data[$key] = $value;
                }
            }

            // Process file uploads from $_FILES
            if (!empty($_FILES)) {
                if (!function_exists('wp_handle_upload')) {
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                }
                $upload_overrides = array('test_form' => false);

                

                foreach ($_FILES as $file_field_name => $file_data) {
                    if (isset($field_types_map[$file_field_name]) && 'file' === $field_types_map[$file_field_name]) {
                        // Get field definition for this file field
                        $current_field_def = null;
                        foreach ($form_fields_definition as $def) {
                            if (isset($def['name']) && $def['name'] === $file_field_name) {
                                $current_field_def = $def;
                                break;
                            }
                        }

                        if ($current_field_def) {
                            $allowed_formats = isset($current_field_def['allowed_formats']) ? array_map('trim', explode(',', $current_field_def['allowed_formats'])) : array();
                            $max_size_mb = isset($current_field_def['max_size']) ? floatval($current_field_def['max_size']) : 0;
                            $allowed_formats_message = isset($current_field_def['allowed_formats_message']) ? sanitize_text_field($current_field_def['allowed_formats_message']) : __( 'Invalid file format.', 'akashic-forms' );
                            $max_size_message = isset($current_field_def['max_size_message']) ? sanitize_text_field($current_field_def['max_size_message']) : __( 'File size exceeds the maximum allowed limit.', 'akashic-forms' );
                            $field_required = isset($current_field_def['required']) && '1' === $current_field_def['required'];

                            // If the field is not required and no file was uploaded, skip validation
                            if (!$field_required && $file_data['error'] === UPLOAD_ERR_NO_FILE) {
                                continue; // Skip to next file field
                            }

                            $file_extension = pathinfo($file_data['name'], PATHINFO_EXTENSION);
                            $file_size_mb_actual = $file_data['size'] / (1024 * 1024); // Convert bytes to MB

                            // Validate file format
                            if (!empty($allowed_formats) && !in_array(strtolower($file_extension), $allowed_formats)) {
                                $errors[$file_field_name] = $allowed_formats_message;
                                // Do not continue here, allow other validations to run for the same file
                            }

                            // Validate file size
                            if ($max_size_mb > 0 && $file_size_mb_actual > $max_size_mb) {
                                $errors[$file_field_name] = $max_size_message;
                                // Do not continue here, allow other validations to run for the same file
                            }
                        }

                        if ($file_data['error'] === UPLOAD_ERR_OK) {
                            // Generate a unique filename
                            $file_info = pathinfo($file_data['name']);
                            $file_extension = isset($file_info['extension']) ? '.' . $file_info['extension'] : '';
                            $new_filename = uniqid() . $file_extension;

                            // Temporarily change the file name in $file_data for wp_handle_upload
                            $file_data['name'] = $new_filename;

                            $uploaded_file = wp_handle_upload($file_data, $upload_overrides);
                            if (isset($uploaded_file['file'])) {
                                $form_data[$file_field_name] = $uploaded_file['url'];
                            } else {
                            }
                        } else if ($file_data['error'] !== UPLOAD_ERR_NO_FILE) {
                        }
                    }
                }
            }

            // If there are any validation errors, send them back.
            if ( ! empty( $errors ) ) {
                return new WP_REST_Response( array( 'errors' => $errors ), 400 );
            }

            if (empty($form_id)) {
                return new WP_REST_Response(array('message' => 'Missing form_id.'), 400);
            }

            $db = new Akashic_Forms_DB();

            // Always add to queue first with pending status
            $queue_id = $db->add_submission_to_queue($form_id, $form_data);
            if (!$queue_id) {
                return new WP_REST_Response(array('message' => 'Failed to add submission to queue initially.'), 500);
            }

            // Insert into akashic_form_submissions table
            $db->insert_submission($form_id, $form_data, $submitted_at);

            return new WP_REST_Response(array('message' => 'Submission received and queued for processing.'), 200);
        }
    }

    new Akashic_Forms_REST_API();
}
