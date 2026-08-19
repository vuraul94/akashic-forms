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
         * Cron hook name.
         */
        const CRON_HOOK = 'akashic_forms_process_queue';

        /**
         * Marker used in the stored sheet column map for the timestamp column.
         */
        const SUBMITTED_AT_KEY = '__submitted_at__';

        /**
         * Constructor.
         */
        public function __construct() {
            add_action( 'init', array( $this, 'schedule_cron' ) );
            add_action( self::CRON_HOOK, array( $this, 'process_queue' ) );
            add_filter( 'cron_schedules', array( $this, 'add_custom_cron_intervals' ) );

            // Keep the schedule in sync as soon as the settings are saved.
            add_action( 'update_option_akashic_forms_cron_enabled', array( $this, 'reschedule' ) );
            add_action( 'update_option_akashic_forms_cron_interval', array( $this, 'reschedule' ) );
            add_action( 'add_option_akashic_forms_cron_enabled', array( $this, 'reschedule' ) );
            add_action( 'add_option_akashic_forms_cron_interval', array( $this, 'reschedule' ) );
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
         *
         * Delegates to reschedule() so a de-synchronised interval is corrected
         * on every request as well.
         */
        public function schedule_cron() {
            $this->reschedule();
        }

        /**
         * Create, re-create or remove the cron event according to the settings.
         *
         * Runs whenever the cron options are saved so changing the interval or
         * unticking the checkbox takes effect immediately.
         */
        public function reschedule() {
            $enabled = get_option( 'akashic_forms_cron_enabled', true );

            if ( ! $enabled ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
                return;
            }

            $interval = $this->get_cron_interval();
            $current  = wp_get_schedule( self::CRON_HOOK );

            if ( $current === $interval ) {
                return;
            }

            wp_clear_scheduled_hook( self::CRON_HOOK );
            wp_schedule_event( time(), $interval, self::CRON_HOOK );
        }

        /**
         * Get the configured cron interval, falling back to five minutes.
         *
         * @return string
         */
        private function get_cron_interval() {
            $allowed  = array( 'five_minutes', 'fifteen_minutes', 'thirty_minutes', 'hourly' );
            $interval = get_option( 'akashic_forms_cron_interval', 'five_minutes' );

            if ( ! is_string( $interval ) || ! in_array( $interval, $allowed, true ) ) {
                return 'five_minutes';
            }

            return $interval;
        }

        /**
         * Get the configured batch size, clamped between 1 and 100.
         *
         * @return int
         */
        private function get_batch_size() {
            $limit = (int) get_option( 'akashic_forms_queue_batch_size', 10 );

            if ( $limit < 1 ) {
                $limit = 1;
            } elseif ( $limit > 100 ) {
                $limit = 100;
            }

            return $limit;
        }

        /**
         * Get the maximum number of attempts, clamped between 1 and 20.
         *
         * @return int
         */
        private function get_max_attempts() {
            $max = (int) get_option( 'akashic_forms_max_attempts', 5 );

            if ( $max < 1 ) {
                $max = 1;
            } elseif ( $max > 20 ) {
                $max = 20;
            }

            return $max;
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

            akashic_forms_log( 'Cron: Token expired or expiring soon, refreshing proactively.' );

            $google_drive = new Akashic_Forms_Google_Drive();
            $refreshed = $google_drive->force_token_refresh();

            if ( $refreshed ) {
                akashic_forms_log( 'Cron: Proactive token refresh succeeded.' );
            } else {
                akashic_forms_log( 'Cron: Proactive token refresh failed. Re-authorization may be required.' );
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

            // Claim the batch atomically so two overlapping cron runs can never
            // pick up the same row and duplicate it in the spreadsheet.
            $submissions = $db->claim_next_submissions( $this->get_batch_size(), (bool) $force );

            if ( empty( $submissions ) ) {
                return;
            }

            $max_attempts = $this->get_max_attempts();

            $google_drive = new Akashic_Forms_Google_Drive();

            foreach ( $submissions as $submission ) {
                // The rows returned above are already marked as processing.
                $form_id = $submission->form_id;
                $form_data = $submission->submission_data;

                $spreadsheet_id = get_post_meta( $form_id, '_akashic_form_google_sheet_id', true );
                $sheet_name = get_post_meta( $form_id, '_akashic_form_google_sheet_name', true );

                if ( empty( $spreadsheet_id ) || empty( $sheet_name ) ) {
                    $this->handle_submission_failure(
                        $db,
                        $submission,
                        sprintf( __( 'Google Sheet not configured for the form %d.', 'akashic-forms' ), (int) $form_id ),
                        $max_attempts
                    );
                    continue;
                }

                $headers_result = $google_drive->get_spreadsheet_headers( $spreadsheet_id, $sheet_name );
                if ( is_wp_error( $headers_result ) ) {
                    $error_code = $headers_result->get_error_code();
                    // If the token is expired/invalid, stop processing the entire queue.
                    if ( in_array( $error_code, array( 'not_authenticated', 'token_expired', 'token_refresh_failed' ), true ) ) {
                        $this->handle_temporary_failure(
                            $db,
                            $submission,
                            sprintf( __( 'Authentication error: %s', 'akashic-forms' ), $headers_result->get_error_message() )
                        );
                        akashic_forms_log( 'Queue: Stopping queue processing due to authentication error: ' . $error_code );
                        break;
                    }
                    if ( 'rate_limit_exceeded' === $error_code ) {
                        $this->handle_temporary_failure( $db, $submission, '' );
                        akashic_forms_log( 'Queue: Stopping queue processing due to rate limiting.' );
                        break;
                    }
                    $this->handle_submission_failure(
                        $db,
                        $submission,
                        sprintf( __( 'Failed to get spreadsheet headers: %s', 'akashic-forms' ), $headers_result->get_error_message() ),
                        $max_attempts
                    );
                    continue;
                }
                $headers = $headers_result;

                $form_fields = get_post_meta($form_id, '_akashic_form_fields', true);
                if (!is_array($form_fields)) {
                    $form_fields = array();
                }

                if (empty($headers)) {
                    $new_headers = array();
                    $column_names = array();
                    foreach ($form_fields as $field) {
                        if (isset($field['label'])) {
                            $new_headers[] = $field['label'];
                            $column_names[] = isset( $field['name'] ) ? $field['name'] : '';
                        }
                    }
                    // Add Submitted At header
                    $new_headers[] = 'Submitted At';
                    $column_names[] = self::SUBMITTED_AT_KEY;

                    $append_headers_result = $google_drive->append_to_sheet($spreadsheet_id, $sheet_name, $new_headers);
                    if (is_wp_error($append_headers_result)) {
                        $error_code = $append_headers_result->get_error_code();
                        if ( in_array( $error_code, array( 'not_authenticated', 'token_expired', 'token_refresh_failed' ), true ) ) {
                            $this->handle_temporary_failure(
                                $db,
                                $submission,
                                sprintf( __( 'Authentication error: %s', 'akashic-forms' ), $append_headers_result->get_error_message() )
                            );
                            akashic_forms_log( 'Queue: Stopping queue processing due to authentication error: ' . $error_code );
                            break;
                        }
                        if ( 'rate_limit_exceeded' === $error_code ) {
                            $this->handle_temporary_failure( $db, $submission, '' );
                            akashic_forms_log( 'Queue: Stopping queue processing due to rate limiting.' );
                            break;
                        }
                        $this->handle_submission_failure(
                            $db,
                            $submission,
                            sprintf( __( 'Failed to create spreadsheet headers: %s', 'akashic-forms' ), $append_headers_result->get_error_message() ),
                            $max_attempts
                        );
                        continue;
                    } elseif ( ! $append_headers_result ) {
                        $this->handle_submission_failure( $db, $submission, __( 'Failed to create spreadsheet headers.', 'akashic-forms' ), $max_attempts );
                        continue;
                    }

                    // Remember which field name owns each column, so renaming a
                    // label later does not silently empty its column.
                    update_post_meta( $form_id, '_akashic_form_sheet_columns', $column_names );

                    $headers = $new_headers;
                }

                $values = $this->build_row( $form_id, $form_fields, $form_data, $headers, $submission );

                $result = $google_drive->append_to_sheet( $spreadsheet_id, $sheet_name, $values );

                if ( is_wp_error( $result ) ) {
                    $error_code = $result->get_error_code();
                    if ( 'rate_limit_exceeded' === $error_code ) {
                        $this->handle_temporary_failure( $db, $submission, '' );
                        break;
                    } elseif ( in_array( $error_code, array( 'not_authenticated', 'token_expired', 'token_refresh_failed' ), true ) ) {
                        // Token is dead, revert to pending and stop processing.
                        $this->handle_temporary_failure(
                            $db,
                            $submission,
                            sprintf( __( 'Authentication error: %s', 'akashic-forms' ), $result->get_error_message() )
                        );
                        akashic_forms_log( 'Queue: Stopping queue processing due to authentication error: ' . $error_code );
                        break;
                    } else {
                        $this->handle_submission_failure( $db, $submission, $result->get_error_message(), $max_attempts );
                    }
                } elseif ( ! $result ) {
                    $this->handle_submission_failure( $db, $submission, __( 'Unknown error or non-WP_Error failure from Google Drive API.', 'akashic-forms' ), $max_attempts );
                } else {
                    $db->update_submission_in_queue( $submission->id, array(
                        'status'          => 'completed',
                        'next_attempt_at' => null,
                    ) );
                }
            }
        }

        /**
         * Build the row of values to append, matching the sheet columns.
         *
         * Prefers the stored field-name map so renaming a label does not break
         * the mapping, and falls back to matching by label for sheets created
         * before that map existed.
         *
         * @param int    $form_id     Form post ID.
         * @param array  $form_fields Form field definitions.
         * @param array  $form_data   Submitted data keyed by field name.
         * @param array  $headers     Header row read from the sheet.
         * @param object $submission  Queue row.
         * @return array
         */
        private function build_row( $form_id, $form_fields, $form_data, $headers, $submission ) {
            if ( ! is_array( $form_data ) ) {
                $form_data = array();
            }
            $headers = array_values( (array) $headers );

            $sheet_columns = get_post_meta( $form_id, '_akashic_form_sheet_columns', true );

            if ( is_array( $sheet_columns ) && count( $sheet_columns ) === count( $headers ) ) {
                $data_by_name = array();
                foreach ( $form_fields as $field ) {
                    $field_name = isset( $field['name'] ) ? $field['name'] : '';
                    if ( '' !== $field_name && isset( $form_data[ $field_name ] ) ) {
                        $data_by_name[ $field_name ] = is_array( $form_data[ $field_name ] ) ? implode( ", ", $form_data[ $field_name ] ) : $form_data[ $field_name ];
                    }
                }

                $values = array();
                foreach ( array_values( $sheet_columns ) as $index => $column_name ) {
                    if ( self::SUBMITTED_AT_KEY === $column_name || 'Submitted At' === $headers[ $index ] ) {
                        // Add the submission timestamp
                        $values[] = $submission->created_at;
                    } else {
                        $values[] = isset( $data_by_name[ $column_name ] ) ? $data_by_name[ $column_name ] : '';
                    }
                }

                return $values;
            }

            // Legacy sheets: match the header text against the field labels.
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

            return $values;
        }

        /**
         * Handle a failure attributable to the submission itself: count the
         * attempt and either schedule a retry with exponential backoff or give
         * up once the configured limit is reached.
         *
         * @param Akashic_Forms_DB $db           DB handler.
         * @param object           $submission   Queue row.
         * @param string           $reason       Failure reason.
         * @param int              $max_attempts Maximum number of attempts.
         */
        private function handle_submission_failure( $db, $submission, $reason, $max_attempts ) {
            $attempts = isset( $submission->attempts ) ? (int) $submission->attempts : 0;
            $attempts++;

            if ( $attempts < $max_attempts ) {
                $delay = (int) min( 60 * pow( 2, $attempts ), 6 * HOUR_IN_SECONDS );

                $db->update_submission_in_queue( $submission->id, array(
                    'status'          => 'pending',
                    'failure_reason'  => $reason,
                    'attempts'        => $attempts,
                    'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
                ) );

                akashic_forms_log( 'Queue: Submission ' . (int) $submission->id . ' failed, retry ' . $attempts . ' of ' . $max_attempts . ' scheduled in ' . $delay . ' seconds.' );
                return;
            }

            $db->update_submission_in_queue( $submission->id, array(
                'status'          => 'failed',
                'failure_reason'  => trim( $reason . ' ' . __( 'Retries exhausted.', 'akashic-forms' ) ),
                'attempts'        => $attempts,
                'next_attempt_at' => null,
            ) );

            akashic_forms_log( 'Queue: Submission ' . (int) $submission->id . ' failed permanently after ' . $attempts . ' attempts.' );
        }

        /**
         * Handle a failure that is not the submission's fault (rate limiting or
         * authentication): back to pending without burning an attempt.
         *
         * @param Akashic_Forms_DB $db         DB handler.
         * @param object           $submission Queue row.
         * @param string           $reason     Failure reason, empty to leave it untouched.
         */
        private function handle_temporary_failure( $db, $submission, $reason = '' ) {
            $data = array( 'status' => 'pending' );

            if ( '' !== $reason ) {
                $data['failure_reason'] = $reason;
            }

            $db->update_submission_in_queue( $submission->id, $data );
        }
    }

    new Akashic_Forms_Queue_Processor();
}
