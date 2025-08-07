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
            add_action( 'init', array( $this, 'handle_form_submission' ) );
        }

        /**
         * Handle form submission.
         */
        public function handle_form_submission() {
            if ( ! isset( $_POST['akashic_form_submit'] ) ) {
                return;
            }

            if ( ! isset( $_POST['akashic_form_nonce'] ) || ! wp_verify_nonce( $_POST['akashic_form_nonce'], 'akashic_submit_form' ) ) {
                wp_die( __( 'Security check failed.', 'akashic-forms' ) );
            }

            $form_id = isset( $_POST['akashic_form_id'] ) ? absint( $_POST['akashic_form_id'] ) : 0;

            if ( ! $form_id ) {
                return;
            }

            $form_fields = get_post_meta( $form_id, '_akashic_form_fields', true );
            $submission_data = array();
            $errors = array();

            foreach ( $form_fields as $field_key => $field ) {
                $field_name = isset( $field['name'] ) ? sanitize_key( $field['name'] ) : '';
                $field_label = isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '';
                $field_type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
                $field_required = isset( $field['required'] ) && '1' === $field['required'];
                $field_pattern = isset( $field['pattern'] ) ? $field['pattern'] : '';
                $field_validation_message = isset( $field['validation_message'] ) ? sanitize_text_field( $field['validation_message'] ) : '';
                $field_min = isset( $field['min'] ) ? $field['min'] : '';
                $field_max = isset( $field['max'] ) ? $field['max'] : '';
                $field_step = isset( $field['step'] ) ? $field['step'] : '';
                $field_options = isset( $field['options'] ) ? $field['options'] : array();

                if ( empty( $field_name ) && 'fieldset' !== $field_type ) {
                    continue;
                }

                // Skip fieldset type as it's for grouping, not direct submission.
                if ( 'fieldset' === $field_type ) {
                    continue;
                }

                $field_value = null;

                // Handle file uploads separately.
                if ( 'file' === $field_type ) {
                    if ( $field_required && empty( $_FILES[ $field_name ] ) ) {
                        $errors[ $field_name ] = sprintf( __( '%s is required.', 'akashic-forms' ), $field_label );
                        continue;
                    }

                    if ( ! function_exists( 'wp_handle_upload' ) ) {
                        require_once( ABSPATH . 'wp-admin/includes/file.php' );
                    }

                    if ( ! empty( $_FILES[ $field_name ] ) && $_FILES[ $field_name ]['error'] === UPLOAD_ERR_OK ) {
                        $upload_overrides = array( 'test_form' => false );
                        $uploaded_file = wp_handle_upload( $_FILES[ $field_name ], $upload_overrides );

                        if ( isset( $uploaded_file['file'] ) ) {
                            $field_value = $uploaded_file['url'];
                        } else {
                            $errors[ $field_name ] = sprintf( __( 'Error uploading %s: %s', 'akashic-forms' ), $field_label, $uploaded_file['error'] );
                        }
                    } elseif ( $field_required && ( ! isset( $_FILES[ $field_name ] ) || $_FILES[ $field_name ]['error'] !== UPLOAD_ERR_NO_FILE ) ) {
                        $errors[ $field_name ] = sprintf( __( 'Error uploading %s.', 'akashic-forms' ), $field_label );
                    }
                } else {
                    // Get field value from POST data.
                    if ( 'checkbox' === $field_type ) {
                        $field_value = isset( $_POST[ $field_name ] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST[ $field_name ] ) ) : array();
                    } else {
                        $field_value = isset( $_POST[ $field_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field_name ] ) ) : '';
                    }

                    // Server-side validation.
                    if ( $field_required && empty( $field_value ) && '0' !== $field_value ) {
                        $errors[ $field_name ] = ! empty( $field_validation_message ) ? $field_validation_message : sprintf( __( '%s is required.', 'akashic-forms' ), $field_label );
                    }

                    // Validate pattern.
                    if ( ! empty( $field_pattern ) && ! empty( $field_value ) && ! preg_match( '/' . $field_pattern . '/', $field_value ) ) {
                        $errors[ $field_name ] = ! empty( $field_validation_message ) ? $field_validation_message : sprintf( __( '%s is invalid.', 'akashic-forms' ), $field_label );
                    }

                    // Validate min/max/step for numeric and range types.
                    if ( in_array( $field_type, array( 'number', 'range' ) ) && ! empty( $field_value ) && is_numeric( $field_value ) ) {
                        if ( ! empty( $field_min ) && $field_value < $field_min ) {
                            $errors[ $field_name ] = ! empty( $field_validation_message ) ? $field_validation_message : sprintf( __( '%s must be at least %s.', 'akashic-forms' ), $field_label, $field_min );
                        }
                        if ( ! empty( $field_max ) && $field_value > $field_max ) {
                            $errors[ $field_name ] = ! empty( $field_validation_message ) ? $field_validation_message : sprintf( __( '%s must be at most %s.', 'akashic-forms' ), $field_label, $field_max );
                        }
                        if ( ! empty( $field_step ) && ( $field_value - $field_min ) % $field_step !== 0 ) {
                            $errors[ $field_name ] = ! empty( $field_validation_message ) ? $field_validation_message : sprintf( __( '%s must be a multiple of %s.', 'akashic-forms' ), $field_label, $field_step );
                        }
                    }

                    // Validate minlength/maxlength for text-based types.
                    if ( in_array( $field_type, array( 'text', 'email', 'password', 'url', 'tel', 'search', 'textarea' ) ) && ! empty( $field_value ) ) {
                        $length = strlen( $field_value );
                        if ( ! empty( $field_min ) && $length < $field_min ) {
                            $errors[ $field_name ] = ! empty( $field_validation_message ) ? $field_validation_message : sprintf( __( '%s must be at least %s characters long.', 'akashic-forms' ), $field_label, $field_min );
                        }
                        if ( ! empty( $field_max ) && $length > $field_max ) {
                            $errors[ $field_name ] = ! empty( $field_validation_message ) ? $field_validation_message : sprintf( __( '%s must be at most %s characters long.', 'akashic-forms' ), $field_label, $field_max );
                        }
                    }

                    // Specific type validations.
                    switch ( $field_type ) {
                        case 'email':
                            if ( ! empty( $field_value ) && ! is_email( $field_value ) ) {
                                $errors[ $field_name ] = ! empty( $field_validation_message ) ? $field_validation_message : sprintf( __( '%s must be a valid email address.', 'akashic-forms' ), $field_label );
                            }
                            break;
                        case 'url':
                            if ( ! empty( $field_value ) && ! filter_var( $field_value, FILTER_VALIDATE_URL ) ) {
                                $errors[ $field_name ] = ! empty( $field_validation_message ) ? $field_validation_message : sprintf( __( '%s must be a valid URL.', 'akashic-forms' ), $field_label );
                            }
                            break;
                        case 'date':
                            // Basic date format validation (YYYY-MM-DD)
                            if ( ! empty( $field_value ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $field_value ) ) {
                                $errors[ $field_name ] = ! empty( $field_validation_message ) ? $field_validation_message : sprintf( __( '%s must be a valid date (YYYY-MM-DD).', 'akashic-forms' ), $field_label );
                            }
                            break;
                        case 'time':
                            // Basic time format validation (HH:MM)
                            if ( ! empty( $field_value ) && ! preg_match( '/^\d{2}:\d{2}$/', $field_value ) ) {
                                $errors[ $field_name ] = ! empty( $field_validation_message ) ? $field_validation_message : sprintf( __( '%s must be a valid time (HH:MM).', 'akashic-forms' ), $field_label );
                            }
                            break;
                        case 'select':
                        case 'radio':
                            $valid_options = array_column( $field_options, 'value' );
                            if ( ! empty( $field_value ) && ! in_array( $field_value, $valid_options ) ) {
                                $errors[ $field_name ] = ! empty( $field_validation_message ) ? $field_validation_message : sprintf( __( '%s has an invalid selection.', 'akashic-forms' ), $field_label );
                            }
                            break;
                        case 'checkbox':
                            $valid_options = array_column( $field_options, 'value' );
                            if ( ! empty( $field_value ) ) {
                                foreach ( $field_value as $checkbox_val ) {
                                    if ( ! in_array( $checkbox_val, $valid_options ) ) {
                                        $errors[ $field_name ] = ! empty( $field_validation_message ) ? $field_validation_message : sprintf( __( '%s has an invalid selection.', 'akashic-forms' ), $field_label );
                                        break; // No need to check further for this field.
                                    }
                                }
                            }
                            break;
                    }
                }

                // Store sanitized and validated data.
                if ( ! isset( $errors[ $field_name ] ) ) {
                    $submission_data[ $field_name ] = $field_value;
                }
            }

            if ( ! empty( $errors ) ) {
                // Store errors in a transient or session to display to the user.
                set_transient( 'akashic_form_errors_' . $form_id, $errors, 60 * 5 );
                // Redirect back to the form page.
                wp_safe_redirect( wp_get_referer() );
                exit;
            }

            // Save submission to database.
            $db = new Akashic_Forms_DB();
            $submission_id = $db->insert_submission( $form_id, $submission_data );

            if ( $submission_id ) {
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

                wp_safe_redirect( add_query_arg( 'akashic_form_success', $form_id, wp_get_referer() ) );
                exit;
            } else {
                wp_die( __( 'There was an error saving your submission. Please try again.', 'akashic-forms' ) );
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
                return; // No email settings configured.
            }

            $form_fields = get_post_meta( $form_id, '_akashic_form_fields', true );
            $all_fields_text = '';
            foreach ( $form_fields as $field ) {
                $field_name = isset( $field['name'] ) ? $field['name'] : '';
                $field_label = isset( $field['label'] ) ? $field['label'] : '';
                if ( ! empty( $field_name ) && isset( $submission_data[ $field_name ] ) ) {
                    $all_fields_text .= sprintf( "%s: %s\n", $field_label, $submission_data[ $field_name ] );
                }
            }

            $email_message = str_replace( '{all_fields}', $all_fields_text, $email_message );

            wp_mail( $recipient_email, $email_subject, $email_message );
        }

    }

}

new Akashic_Forms_Submission_Handler();