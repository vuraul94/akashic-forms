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
            $form_data = $request->get_param('form_data');

            if (empty($form_id) || empty($form_data)) {
                return new WP_REST_Response(array('message' => 'Missing form_id or form_data.'), 400);
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
                $db->insert_submission($form_id, $form_data);

                $google_drive = new Akashic_Forms_Google_Drive();
                $spreadsheet_id = get_post_meta($form_id, '_akashic_form_google_sheet_id', true);
                $sheet_name = get_post_meta($form_id, '_akashic_form_google_sheet_name', true);

                if (!empty($spreadsheet_id) && !empty($sheet_name)) {
                    $headers = $google_drive->get_spreadsheet_headers($spreadsheet_id, $sheet_name);
                    if (is_wp_error($headers)) {
                        throw new Exception('Failed to get spreadsheet headers: ' . $headers->get_error_message());
                    }

                    // If headers were empty, try to create them
                    if (empty($headers)) {
                        $form_fields = get_post_meta($form_id, '_akashic_form_fields', true); // Corrected meta key
                        if (! is_array($form_fields)) {
                            $form_fields = array();
                        }
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

                    $sheet_values = array_fill_keys( $headers, '' ); // Initialize with empty strings for all headers
                    $mapped_form_data = array();

                    // Assuming form_data keys are field names, and we need to map them to labels.
                    // This requires knowing the mapping between field names and labels.
                    // The form_fields meta data is needed here.
                    $form_fields = get_post_meta($form_id, '_akashic_form_fields', true);
                    if (is_array($form_fields)) {
                        foreach ($form_fields as $field) {
                            $field_name = isset($field['name']) ? $field['name'] : '';
                            $field_label = isset($field['label']) ? $field['label'] : '';
                            $field_type = isset($field['type']) ? $field['type'] : 'text'; // Get field type

                            if (!empty($field_name) && isset($form_data[$field_name])) {
                                $value = '';
                                switch ($field_type) {
                                    case 'checkbox':
                                        // If it's an array, it's multiple checkboxes.
                                        if (is_array($form_data[$field_name])) {
                                            $value = implode(", ", array_map('sanitize_text_field', $form_data[$field_name]));
                                        } else {
                                            // Singular checkbox: if set, value is '1', else '0'.
                                            $value = '1'; // Assuming if it's set, it's checked.
                                        }
                                        break;
                                    case 'select':
                                        // Check if it's a multi-select
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
                                // Explicitly handle unchecked singular checkboxes for REST API
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

                    $append_data_result = $google_drive->append_to_sheet($spreadsheet_id, $sheet_name, array_values($sheet_values));

                    if (is_wp_error($append_data_result) || ! $append_data_result) {
                        throw new Exception('Failed to append data to spreadsheet: ' . (is_wp_error($append_data_result) ? $append_data_result->get_error_message() : 'Unknown error.'));
                    }

                    $response_data = 'Google Sheet updated successfully.';
                    $spreadsheet_url = 'https://docs.google.com/spreadsheets/d/' . $spreadsheet_id . '/edit#gid=0'; // Assuming gid=0 for the first sheet
                    $response_data .= ' Spreadsheet Link: ' . $spreadsheet_url;

                } else {
                    $response_data = 'No Google Sheet configured for this form.';
                }

                $status = 'completed'; // Mark as completed if all successful

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
