<?php
/**
 * Queue Processor for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Akashic_Forms_Queue_Processor' ) ) {

    class Akashic_Forms_Queue_Processor {

        /**
         * Constructor.
         */
        public function __construct() {
            add_action( 'init', array( $this, 'schedule_cron' ) );
            add_action( 'akashic_forms_process_queue', array( $this, 'process_queue' ) );
            add_filter( 'cron_schedules', array( $this, 'add_custom_cron_intervals' ) );
        }

        /**
         * Add custom cron intervals.
         *
         * @param array $schedules
         * @return array
         */
        public function add_custom_cron_intervals( $schedules ) {
            $schedules['five_minutes'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 5 Minutes', 'akashic-forms' ),
            );
            $schedules['fifteen_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 15 Minutes', 'akashic-forms' ),
            );
            $schedules['thirty_minutes'] = array(
                'interval' => 30 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 30 Minutes', 'akashic-forms' ),
            );
            return $schedules;
        }

        /**
         * Schedule the cron job.
         */
        public function schedule_cron() {
            $enabled = get_option( 'akashic_forms_cron_enabled', true );
            $interval = get_option( 'akashic_forms_cron_interval', 'five_minutes' );

            if ( $enabled ) {
                if ( ! wp_next_scheduled( 'akashic_forms_process_queue' ) ) {
                    wp_schedule_event( time(), $interval, 'akashic_forms_process_queue' );
                }
            }
        }

        /**
         * Proactively check and refresh the Google token if it's expired or about to expire.
         * Runs on every cron tick regardless of whether there are pending submissions,
         * so the token stays fresh and ready for when new submissions arrive.
         */
        public function maybe_refresh_token() {
            $stored_token = get_option( 'akashic_forms_google_access_token' );

            // No token stored, nothing to refresh.
            if ( empty( $stored_token ) || ! is_array( $stored_token ) ) {
                return;
            }

            // No refresh token available, can't do anything.
            if ( empty( $stored_token['refresh_token'] ) ) {
                return;
            }

            // Calculate when the token expires.
            $expires_at = isset( $stored_token['created'] ) && isset( $stored_token['expires_in'] )
                ? $stored_token['created'] + $stored_token['expires_in']
                : 0;

            if ( $expires_at <= 0 ) {
                return; // Can't determine expiry, let the normal flow handle it.
            }

            // Refresh if the token is expired or will expire within the next 5 minutes.
            $buffer = 5 * MINUTE_IN_SECONDS;
            if ( $expires_at > ( time() + $buffer ) ) {
                return; // Token still valid, no action needed.
            }

            error_log( 'Akashic Forms Cron: Token expired or expiring soon, refreshing proactively.' );

            $google_drive = new Akashic_Forms_Google_Drive();
            $refreshed = $google_drive->force_token_refresh();

            if ( $refreshed ) {
                error_log( 'Akashic Forms Cron: Proactive token refresh succeeded.' );
            } else {
                error_log( 'Akashic Forms Cron: Proactive token refresh failed. Re-authorization may be required.' );
            }
        }

        /**
         * Process the submission queue.
         *
         * @param bool $force Whether to force process failed submissions as well.
         */
        public function process_queue( $force = false ) {
            set_transient('akashic_forms_cron_started', true, 5 * MINUTE_IN_SECONDS);

            // Always check token health first, even if there are no pending submissions.
            $this->maybe_refresh_token();

            $db = new Akashic_Forms_DB();

            $db->revert_timed_out_submissions();

            if ( $force ) {
                $submissions = $db->get_pending_and_failed_submissions_from_queue( 10 );
            } else {
                $submissions = $db->get_pending_submissions_from_queue( 10 );
            }

            if ( empty( $submissions ) ) {
                return;
            }

            $google_drive = new Akashic_Forms_Google_Drive();

            foreach ( $submissions as $submission ) {
                $db->update_submission_in_queue( $submission->id, array(
                    'status'                  => 'processing',
                    'processing_started_at' => current_time( 'mysql', true ),
                ) );

                $form_id = $submission->form_id;
                $form_data = $submission->submission_data;

                $spreadsheet_id = get_post_meta( $form_id, '_akashic_form_google_sheet_id', true );
                $sheet_name = get_post_meta( $form_id, '_akashic_form_google_sheet_name', true );

                if ( empty( $spreadsheet_id ) || empty( $sheet_name ) ) {
                    $db->update_submission_in_queue( $submission->id, array( 'status' => 'failed', 'failure_reason' => "Google Sheet not configured for the form $form_id." ) );
                    continue;
                }

                $headers_result = $google_drive->get_spreadsheet_headers( $spreadsheet_id, $sheet_name );
                if ( is_wp_error( $headers_result ) ) {
                    $error_code = $headers_result->get_error_code();
                    $db->update_submission_in_queue( $submission->id, array( 'status' => 'failed', 'failure_reason' => 'Failed to get spreadsheet headers: ' . $headers_result->get_error_message() ) );
                    // If the token is expired/invalid, stop processing the entire queue.
                    if ( in_array( $error_code, array( 'not_authenticated', 'token_expired', 'token_refresh_failed' ), true ) ) {
                        error_log( 'Akashic Forms Queue: Stopping queue processing due to authentication error: ' . $error_code );
                        break;
                    }
                    continue;
                }
                $headers = $headers_result;
                if (empty($headers)) {
                    $form_fields = get_post_meta($form_id, '_akashic_form_fields', true);
                    if (!is_array($form_fields)) {
                        $form_fields = array();
                    }
                    $new_headers = array();
                    foreach ($form_fields as $field) {
                        if (isset($field['label'])) {
                            $new_headers[] = $field['label'];
                        }
                    }
                    // Add Submitted At header
                    $new_headers[] = 'Submitted At';

                    $append_headers_result = $google_drive->append_to_sheet($spreadsheet_id, $sheet_name, $new_headers);
                    if (is_wp_error($append_headers_result)) {
                        $error_code = $append_headers_result->get_error_code();
                        $db->update_submission_in_queue( $submission->id, array( 'status' => 'failed', 'failure_reason' => 'Failed to create spreadsheet headers: ' . $append_headers_result->get_error_message() ) );
                        if ( in_array( $error_code, array( 'not_authenticated', 'token_expired', 'token_refresh_failed' ), true ) ) {
                            error_log( 'Akashic Forms Queue: Stopping queue processing due to authentication error: ' . $error_code );
                            break;
                        }
                        continue;
                    } elseif ( ! $append_headers_result ) {
                        $db->update_submission_in_queue( $submission->id, array( 'status' => 'failed', 'failure_reason' => 'Failed to create spreadsheet headers.' ) );
                        continue;
                    }
                    $headers = $new_headers;
                }

                $form_fields = get_post_meta($form_id, '_akashic_form_fields', true);
                if (!is_array($form_fields)) {
                    $form_fields = array();
                }

                $mapped_form_data = array();
                foreach ($form_fields as $field) {
                    $field_name = isset($field['name']) ? $field['name'] : '';
                    $field_label = isset($field['label']) ? $field['label'] : '';
                    if (!empty($field_name) && isset($form_data[$field_name])) {
                        $value = is_array($form_data[$field_name]) ? implode(", ", $form_data[$field_name]) : $form_data[$field_name];
                        $mapped_form_data[$field_label] = $value;
                    }
                }

                $values = array();
                foreach ($headers as $header_label) {
                    if ($header_label === 'Submitted At') {
                        // Add the submission timestamp
                        $values[] = $submission->created_at;
                    } else {
                        $values[] = isset($mapped_form_data[$header_label]) ? $mapped_form_data[$header_label] : '';
                    }
                }

                $result = $google_drive->append_to_sheet( $spreadsheet_id, $sheet_name, $values );

                if ( is_wp_error( $result ) ) {
                    $error_code = $result->get_error_code();
                    if ( 'rate_limit_exceeded' === $error_code ) {
                        $db->update_submission_in_queue( $submission->id, array( 'status' => 'pending' ) );
                        break;
                    } elseif ( in_array( $error_code, array( 'not_authenticated', 'token_expired', 'token_refresh_failed' ), true ) ) {
                        // Token is dead, revert to pending and stop processing.
                        $db->update_submission_in_queue( $submission->id, array( 'status' => 'pending', 'failure_reason' => 'Authentication error: ' . $result->get_error_message() ) );
                        error_log( 'Akashic Forms Queue: Stopping queue processing due to authentication error: ' . $error_code );
                        break;
                    } else {
                        $db->update_submission_in_queue( $submission->id, array( 'status' => 'failed', 'failure_reason' => $result->get_error_message() ) );
                    }
                } elseif ( ! $result ) {
                    $db->update_submission_in_queue( $submission->id, array( 'status' => 'failed', 'failure_reason' => 'Unknown error or non-WP_Error failure from Google Drive API.' ) );
                } else {
                    $db->update_submission_in_queue( $submission->id, array( 'status' => 'completed' ) );
                }
            }
        }
    }

    new Akashic_Forms_Queue_Processor();
}
