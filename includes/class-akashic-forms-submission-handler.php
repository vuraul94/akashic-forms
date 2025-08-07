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
                    if ( 'checkbox' === $field_type ) {
                        $field_value = isset( $_POST[ $field_name ] ) ? array_map( 'sanitize_text_field', (array) $_POST[ $field_name ] ) : array();
                    } else {
                        $field_value = isset( $_POST[ $field_name ] ) ? sanitize_text_field( stripslashes( $_POST[ $field_name ] ) ) : '';
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

            // Save submission to the database.
            $db = new Akashic_Forms_DB();
            $submission_id = $db->insert_submission( $form_id, $submission_data );

            if ( $submission_id ) {
                // Send email notification.
                $this->send_email_notification( $form_id, $submission_data );

                // Google Drive Integration.
                $google_sheet_id = get_post_meta( $form_id, '_akashic_form_google_sheet_id', true );
                $google_sheet_name = get_post_meta( $form_id, '_akashic_form_google_sheet_name', true );

                if ( ! empty( $google_sheet_id ) && ! empty( $google_sheet_name ) ) {
                    $google_drive = new Akashic_Forms_Google_Drive();
                    $sheet_values = array();
                    foreach ( $form_fields as $field ) {
                        $field_name = isset( $field['name'] ) ? $field['name'] : '';
                        if ( ! empty( $field_name ) && isset( $submission_data[ $field_name ] ) ) {
                            $sheet_values[] = $submission_data[ $field_name ];
                        } else {
                            $sheet_values[] = ''; // Ensure all fields have a value for the sheet.
                        }
                    }
                    $sheet_values[] = current_time( 'mysql' ); // Add submission timestamp.
                    $google_drive->append_to_sheet( $google_sheet_id, $google_sheet_name, $sheet_values );
                }

                wp_send_json_success();
            } else {
                wp_send_json_error( array( 'message' => __( 'There was an error saving your submission. Please try again.', 'akashic-forms' ) ) );
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