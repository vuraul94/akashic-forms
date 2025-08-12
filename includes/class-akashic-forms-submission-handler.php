<?php
/**
 * Handles form submissions for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Akashic_Forms_Submission_Handler' ) ) {

    class Akashic_Forms_Submission_Handler {

        /**
         * Constructor.
         */
        public function __construct() {
            // These actions are specifically for handling AJAX requests from your form.
            add_action( 'wp_ajax_akashic_form_submit', array( $this, 'handle_ajax_submission' ) );
            add_action( 'wp_ajax_nopriv_akashic_form_submit', array( $this, 'handle_ajax_submission' ) );
        }

        /**
         * Handle form submission via AJAX.
         */
        public function handle_ajax_submission() {
            error_log( 'Akashic Forms: handle_ajax_submission - Processing form submission.' );
            // Always check the nonce for security.
            if ( ! isset( $_POST['akashic_form_nonce'] ) || ! wp_verify_nonce( $_POST['akashic_form_nonce'], 'akashic_submit_form' ) ) {
                wp_send_json_error( array( 'message' => __( 'Security check failed.', 'akashic-forms' ) ) );
            }

            $form_id = isset( $_POST['akashic_form_id'] ) ? absint( $_POST['akashic_form_id'] ) : 0;

            if ( ! $form_id ) {
                wp_send_json_error( array( 'message' => __( 'Invalid form ID.', 'akashic-forms' ) ) );
            }

            $form_fields = get_post_meta( $form_id, '_akashic_form_fields', true );
            $submission_data = array();
            $errors = array();

            // Loop through fields to validate and sanitize data.
            foreach ( $form_fields as $field_key => $field ) {
                $field_name = isset( $field['name'] ) ? sanitize_key( $field['name'] ) : '';
                $field_label = isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '';
                $field_type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
                $field_required = isset( $field['required'] ) && '1' === $field['required'];

                if ( empty( $field_name ) || 'fieldset' === $field_type ) {
                    continue;
                }

                $field_value = null;

                // Handle file uploads.
                if ( 'file' === $field_type ) {
                    if ( ! empty( $_FILES[ $field_name ] ) && $_FILES[ $field_name ]['error'] === UPLOAD_ERR_OK ) {
                        $allowed_formats = isset( $field['allowed_formats'] ) ? array_map( 'trim', explode( ',', $field['allowed_formats'] ) ) : array();
                        $max_size_mb = isset( $field['max_size'] ) ? floatval( $field['max_size'] ) : 0;
                        $allowed_formats_message = isset( $field['allowed_formats_message'] ) ? sanitize_text_field( $field['allowed_formats_message'] ) : __( 'Invalid file format.', 'akashic-forms' );
                        $max_size_message = isset( $field['max_size_message'] ) ? sanitize_text_field( $field['max_size_message'] ) : __( 'File size exceeds the maximum allowed limit.', 'akashic-forms' );

                        $file_extension = pathinfo( $_FILES[ $field_name ]['name'], PATHINFO_EXTENSION );
                        $file_size_mb = $_FILES[ $field_name ]['size'] / (1024 * 1024); // Convert bytes to MB

                        // Validate file format
                        if ( ! empty( $allowed_formats ) && ! in_array( strtolower( $file_extension ), $allowed_formats ) ) {
                            $errors[ $field_name ] = $allowed_formats_message;
                            continue; // Skip to next field if format is invalid
                        }

                        // Validate file size
                        if ( $max_size_mb > 0 && $file_size_mb > $max_size_mb ) {
                            $errors[ $field_name ] = $max_size_message;
                            continue; // Skip to next field if size is too large
                        }

                        if ( ! function_exists( 'wp_handle_upload' ) ) {
                            require_once( ABSPATH . 'wp-admin/includes/file.php' );
                        }
                        $upload_overrides = array( 'test_form' => false );
                        $uploaded_file = wp_handle_upload( $_FILES[ $field_name ], $upload_overrides );

                        if ( isset( $uploaded_file['file'] ) ) {
                            $field_value = $uploaded_file['url'];
                        } else {
                            $errors[ $field_name ] = sprintf( __( 'Error uploading %s: %s', 'akashic-forms' ), $field_label, $uploaded_file['error'] );
                        }
                    } elseif ( $field_required && ( ! isset( $_FILES[ $field_name ] ) || $_FILES[ $field_name ]['error'] !== UPLOAD_ERR_NO_FILE ) ) {
                        $errors[ $field_name ] = sprintf( __( '%s is required.', 'akashic-forms' ), $field_label );
                    }
                } else {
                    // Handle other field types.
                    switch ( $field_type ) {
                        case 'checkbox':
                            // If the field name is present in $_POST and it's an array, it's multiple checkboxes.
                            if ( isset( $_POST[ $field_name ] ) && is_array( $_POST[ $field_name ] ) ) {
                                $field_value = array_map( 'sanitize_text_field', $_POST[ $field_name ] );
                            } else {
                                // This handles singular checkboxes. If it's set, it's checked (value 1), otherwise unchecked (value 0).
                                $field_value = isset( $_POST[ $field_name ] ) ? '1' : '0';
                            }
                            break;
                        case 'select':
                            // Check if it's a multi-select (e.g., select name="myfield[]")
                            if ( isset( $_POST[ $field_name ] ) && is_array( $_POST[ $field_name ] ) ) {
                                $field_value = array_map( 'sanitize_text_field', $_POST[ $field_name ] );
                            } else {
                                $field_value = isset( $_POST[ $field_name ] ) ? sanitize_text_field( stripslashes( $_POST[ $field_name ] ) ) : '';
                            }
                            break;
                        case 'radio':
                            $field_value = isset( $_POST[ $field_name ] ) ? sanitize_text_field( stripslashes( $_POST[ $field_name ] ) ) : '';
                            break;
                        default:
                            $field_value = isset( $_POST[ $field_name ] ) ? sanitize_text_field( stripslashes( $_POST[ $field_name ] ) ) : '';
                            break;
                    }

                    if ( $field_required && empty( $field_value ) ) {
                        $errors[ $field_name ] = sprintf( __( '%s is required.', 'akashic-forms' ), $field_label );
                    }
                }

                // Store sanitized data if no errors for this field.
                if ( ! isset( $errors[ $field_name ] ) ) {
                    $submission_data[ $field_name ] = $field_value;
                }
            }

            // If there are any validation errors, send them back.
            if ( ! empty( $errors ) ) {
                $error_message = implode( "\n", $errors );
                wp_send_json_error( array( 'message' => $error_message ) );
            }

            $db = new Akashic_Forms_DB();

            // Add submission to the queue with 'pending' status initially
            $queue_id = $db->add_submission_to_queue( $form_id, $submission_data );
            error_log( 'Akashic Forms: Submission added to queue with ID: ' . $queue_id );

            if ( ! $queue_id ) {
                wp_send_json_error( array( 'message' => __( 'There was an error adding your submission to the queue.', 'akashic-forms' ) ) );
            }

            $status = 'pending';
            $response_data = null;
            $failure_reason = null;
            $spreadsheet_url = null; // Initialize spreadsheet URL

            try {
                // Attempt Google Drive Integration
                $google_sheet_id = get_post_meta( $form_id, '_akashic_form_google_sheet_id', true );
                $google_sheet_name = get_post_meta( $form_id, '_akashic_form_google_sheet_name', true );

                if ( ! empty( $google_sheet_id ) && ! empty( $google_sheet_name ) ) {
                    $google_drive = new Akashic_Forms_Google_Drive();
                    $headers = $google_drive->get_spreadsheet_headers( $google_sheet_id, $google_sheet_name );

                    if ( is_wp_error( $headers ) ) {
                        throw new Exception( 'Error fetching headers: ' . $headers->get_error_message() );
                    }

                    if ( $headers ) {
                        $sheet_values = array_fill_keys( $headers, '' ); // Initialize with empty strings for all headers
                        $mapped_submission_data = array();

                        // First, create a mapping from field_label to submission_data value
                        foreach ( $form_fields as $field ) {
                            $field_name = isset( $field['name'] ) ? $field['name'] : '';
                            $field_label = isset( $field['label'] ) ? $field['label'] : '';
                            if ( ! empty( $field_name ) && isset( $submission_data[ $field_name ] ) ) {
                                $value = is_array( $submission_data[ $field_name ] ) ? implode( ", ", $submission_data[ $field_name ] ) : $submission_data[ $field_name ];
                                $mapped_submission_data[ $field_label ] = $value; // Map label to value
                            }
                        }

                        // Now, populate sheet_values based on headers and mapped_submission_data
                        foreach ( $headers as $header_label ) {
                            if ( isset( $mapped_submission_data[ $header_label ] ) ) {
                                $sheet_values[ $header_label ] = $mapped_submission_data[ $header_label ];
                            }
                        }
                        $sheet_values['Submission Date'] = current_time( 'mysql' );
                        $append_result = $google_drive->append_to_sheet( $google_sheet_id, $google_sheet_name, array_values($sheet_values) );

                        if ( is_wp_error( $append_result ) ) {
                            throw new Exception( 'Error appending to sheet: ' . $append_result->get_error_message() );
                        }
                        $response_data = 'Google Sheet updated successfully.';

                        // Construct spreadsheet URL
                        $spreadsheet_url = 'https://docs.google.com/spreadsheets/d/' . $google_sheet_id . '/edit#gid=0'; // Assuming gid=0 for the first sheet
                        $response_data .= ' Spreadsheet Link: ' . $spreadsheet_url;

                    } else {
                        throw new Exception('Could not fetch headers from Google Sheet.');
                    }
                }

                // Send email notification.
                $this->send_email_notification( $form_id, $submission_data );
                $response_data .= ' Email sent successfully.';

                $status = 'completed';

            } catch ( Exception $e ) {
                $status = 'failed';
                $failure_reason = $e->getMessage();
                error_log( 'Akashic Forms: Submission processing failed for queue ID ' . $queue_id . ': ' . $failure_reason );
            }

            // Update the queue item status and response/failure reason
            $update_data = array(
                'status'         => $status,
                'response'       => $response_data,
                'failure_reason' => $failure_reason,
            );
            $update_result = $db->update_submission_in_queue( $queue_id, $update_data );
            error_log( 'Akashic Forms: Queue item ' . $queue_id . ' updated with status ' . $status . '. Update result: ' . ( $update_result ? 'Success' : 'Failure' ) );


            // Save submission to the main submissions table
            $main_submission_id = $db->insert_submission( $form_id, $submission_data );
            error_log( 'Akashic Forms: Main submission table insert ID: ' . $main_submission_id );


            if ( 'completed' === $status ) {
                wp_send_json_success( array( 'message' => __( 'Submission successful!', 'akashic-forms' ), 'spreadsheet_url' => $spreadsheet_url ) );
            } else {
                wp_send_json_error( array( 'message' => __( 'There was an error processing your submission. It has been added to the queue for retry.', 'akashic-forms' ), 'failure_reason' => $failure_reason ) );
            }
        }

        /**
         * Send email notification after form submission.
         *
         * @param int   $form_id The ID of the form.
         * @param array $submission_data The submitted data.
         */
        private function send_email_notification( $form_id, $submission_data ) {
            $recipient_email = get_post_meta( $form_id, '_akashic_form_email_recipient', true );
            $email_subject = get_post_meta( $form_id, '_akashic_form_email_subject', true );
            $email_message = get_post_meta( $form_id, '_akashic_form_email_message', true );

            if ( empty( $recipient_email ) || empty( $email_subject ) || empty( $email_message ) ) {
                return;
            }

            $form_fields = get_post_meta( $form_id, '_akashic_form_fields', true );
            $all_fields_text = '';
            foreach ( $form_fields as $field ) {
                $field_name = isset( $field['name'] ) ? $field['name'] : '';
                $field_label = isset( $field['label'] ) ? $field['label'] : '';
                if ( ! empty( $field_name ) && isset( $submission_data[ $field_name ] ) ) {
                    $value = is_array( $submission_data[ $field_name ] ) ? implode( ', ', $submission_data[ $field_name ] ) : $submission_data[ $field_name ];
                    $all_fields_text .= sprintf( "%s: %s\n", $field_label, $value );
                }
            }

            $email_message = str_replace( '{all_fields}', $all_fields_text, $email_message );

            wp_mail( $recipient_email, $email_subject, $email_message );
        }
    }
}

new Akashic_Forms_Submission_Handler();