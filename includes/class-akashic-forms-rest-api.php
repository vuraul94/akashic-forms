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
            error_log('Akashic Forms REST API: handle_sync_request entered.');
            error_log( 'Akashic Forms REST API: Received $_FILES: ' . print_r( $_FILES, true ) );

            $form_id = $request->get_param('form_id');
            error_log('Akashic Forms REST API: form_id: ' . $form_id);

            $submitted_at = $request->get_param('submitted_at');
            error_log('Akashic Forms REST API: submitted_at: ' . $submitted_at);
            error_log('Akashic Forms REST API: form_id and submitted_at received. form_id: ' . $form_id . ', submitted_at: ' . $submitted_at);

            $all_params = $request->get_params();
            $form_data = array();

            // Get form fields definition to identify file types
            $form_fields_definition = get_post_meta($form_id, '_akashic_form_fields', true);
            if (!is_array($form_fields_definition)) {
                $form_fields_definition = array();
            }
            error_log('Akashic Forms REST API: Form fields definition retrieved: ' . print_r($form_fields_definition, true));
            $field_types_map = array();
            foreach ($form_fields_definition as $field_def) {
                if (isset($field_def['name']) && isset($field_def['type'])) {
                    $field_types_map[$field_def['name']] = $field_def['type'];
                }
            }
            error_log('Akashic Forms REST API: Field Types Map: ' . print_r($field_types_map, true));

            // Process regular fields
            foreach ($all_params as $key => $value) {
                if (!in_array($key, ['form_id', 'submitted_at', 'action', '_wpnonce', '_wp_http_referer'])) {
                    if (isset($field_types_map[$key]) && 'file' === $field_types_map[$key]) {
                        continue;
                    }
                    $form_data[$key] = $value;
                }
            }
            error_log('Akashic Forms REST API: Form data reconstructed (before file processing): ' . print_r($form_data, true));

            // Process file uploads from $_FILES
            if (!empty($_FILES)) {
                error_log('Akashic Forms REST API: Processing file uploads...');
                if (!function_exists('wp_handle_upload')) {
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                }
                $upload_overrides = array('test_form' => false);

                $errors = array(); // Initialize errors array

                foreach ($_FILES as $file_field_name => $file_data) {
                    error_log('Akashic Forms REST API: Attempting to process file field: ' . $file_field_name . ' with data: ' . print_r($file_data, true));
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
                                error_log('Akashic Forms REST API: File URL added to form_data: ' . $form_data[$file_field_name]);
                            } else {
                                error_log('Akashic Forms REST API: File upload error for ' . $file_field_name . ': ' . $uploaded_file['error']);
                            }
                        } else if ($file_data['error'] !== UPLOAD_ERR_NO_FILE) {
                            error_log('Akashic Forms REST API: File upload error for ' . $file_field_name . ': ' . $file_data['error']);
                        }
                    }
                }
            }
            error_log('Akashic Forms REST API: Final form_data before processing: ' . print_r($form_data, true));

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

            $status = 'pending';
            $response_data = null;
            $failure_reason = null;
            $spreadsheet_url = null;

            try {
                // Insert into akashic_form_submissions table
                $db->insert_submission($form_id, $form_data, $submitted_at);

                $google_drive = new Akashic_Forms_Google_Drive();
                $spreadsheet_id = get_post_meta($form_id, '_akashic_form_google_sheet_id', true);
                $sheet_name = get_post_meta($form_id, '_akashic_form_google_sheet_name', true);

                if (!empty($spreadsheet_id) && !empty($sheet_name)) {
                    $headers = $google_drive->get_spreadsheet_headers($spreadsheet_id, $sheet_name);
                    if (is_wp_error($headers)) {
                        throw new Exception('Failed to get spreadsheet headers: ' . $headers->get_error_message());
                    }

                    // Define form_fields here, so it's always available for mapping
                    $form_fields = get_post_meta($form_id, '_akashic_form_fields', true);
                    if (! is_array($form_fields)) {
                        $form_fields = array();
                    }

                    // If headers were empty, try to create them
                    if (empty($headers)) {
                        $new_headers = array();
                        foreach ($form_fields as $field) {
                            if (isset($field['label'])) {
                                $new_headers[] = $field['label'];
                            }
                        }
                        // Append headers to sheet
                        $append_headers_result = $google_drive->append_to_sheet($spreadsheet_id, $sheet_name, $new_headers);
                        if (is_wp_error($append_headers_result) || ! $append_headers_result) {
                            throw new Exception('Failed to create spreadsheet headers.');
                        }
                        $headers = $new_headers;
                    }

                    $sheet_values = array_fill_keys( $headers, '' );
                    $mapped_form_data = array();

                    // Assuming form_data keys are field names, and we need to map them to labels.
                    // This requires knowing the mapping between field names and labels.
                    // The form_fields meta data is needed here.
                    if (is_array($form_fields)) {
                        foreach ($form_fields as $field) {
                            $field_name = isset($field['name']) ? $field['name'] : '';
                            $field_label = isset($field['label']) ? $field['label'] : '';
                            $field_type = isset($field['type']) ? $field['type'] : 'text';

                            if (!empty($field_name) && isset($form_data[$field_name])) {
                                $value = '';
                                switch ($field_type) {
                                    case 'checkbox':
                                        if (is_array($form_data[$field_name])) {
                                            $value = implode(", ", array_map('sanitize_text_field', $form_data[$field_name]));
                                        } else {
                                            $value = '1';
                                        }
                                        break;
                                    case 'select':
                                        if (is_array($form_data[$field_name])) {
                                            $value = implode(", ", array_map('sanitize_text_field', $form_data[$field_name]));
                                        } else {
                                            $value = sanitize_text_field($form_data[$field_name]);
                                        }
                                        break;
                                    case 'radio':
                                        $value = sanitize_text_field($form_data[$field_name]);
                                        break;
                                    default:
                                        $value = is_array($form_data[$field_name]) ? implode(", ", $form_data[$field_name]) : sanitize_text_field($form_data[$field_name]);
                                        break;
                                }
                                $mapped_form_data[$field_label] = $value;
                            } else if (!empty($field_name) && 'checkbox' === $field_type) {
                                $mapped_form_data[$field_label] = '0';
                            }
                        }
                    }

                    // Now, populate sheet_values based on headers and mapped_form_data
                    foreach ( $headers as $header_label ) {
                        if ( isset( $mapped_form_data[ $header_label ] ) ) {
                            $sheet_values[ $header_label ] = $mapped_form_data[ $header_label ];
                        }
                    }

                    $sheet_values['Submission Date'] = current_time( 'mysql' );
                    $append_data_result = $google_drive->append_to_sheet($spreadsheet_id, $sheet_name, array_values($sheet_values));

                    if (is_wp_error($append_data_result) || ! $append_data_result) {
                        throw new Exception('Failed to append data to spreadsheet: ' . (is_wp_error($append_data_result) ? $append_data_result->get_error_message() : 'Unknown error.'));
                    }

                    $response_data = 'Google Sheet updated successfully.';
                    $spreadsheet_url = 'https://docs.google.com/spreadsheets/d/' . $spreadsheet_id . '/edit#gid=0';
                    $response_data .= ' Spreadsheet Link: ' . $spreadsheet_url;

                } else {
                    $response_data = 'No Google Sheet configured for this form.';
                }

                $status = 'completed';

            } catch (Exception $e) {
                $status = 'failed';
                $failure_reason = $e->getMessage();
                error_log('Akashic Forms REST API: Sync failed for queue ID ' . $queue_id . ': ' . $failure_reason);
            }

            // Update the queue item status and response/failure reason
            $db->update_submission_in_queue($queue_id, array(
                'status'         => $status,
                'response'       => $response_data,
                'failure_reason' => $failure_reason,
            ));

            if ('completed' === $status) {
                return new WP_REST_Response(array('message' => 'Data synced successfully.', 'spreadsheet_url' => $spreadsheet_url), 200);
            } else {
                return new WP_REST_Response(array('message' => 'Data sync failed, submission queued for retry.', 'failure_reason' => $failure_reason), 500);
            }
        }
    }

    new Akashic_Forms_REST_API();
}
