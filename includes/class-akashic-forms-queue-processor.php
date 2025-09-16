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
         * Process the submission queue.
         *
         * @param bool $force Whether to force process failed submissions as well.
         */
        public function process_queue( $force = false ) {
            set_transient('akashic_forms_cron_started', true, 5 * MINUTE_IN_SECONDS);

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
                    $db->update_submission_in_queue( $submission->id, array( 'status' => 'failed', 'failure_reason' => 'Failed to get spreadsheet headers: ' . $headers_result->get_error_message() ) );
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
                    $append_headers_result = $google_drive->append_to_sheet($spreadsheet_id, $sheet_name, $new_headers);
                    if (is_wp_error($append_headers_result) || ! $append_headers_result) {
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
                    $values[] = isset($mapped_form_data[$header_label]) ? $mapped_form_data[$header_label] : '';
                }

                $result = $google_drive->append_to_sheet( $spreadsheet_id, $sheet_name, $values );

                if ( is_wp_error( $result ) ) {
                    if ( 'rate_limit_exceeded' === $result->get_error_code() ) {
                        $db->update_submission_in_queue( $submission->id, array( 'status' => 'pending' ) );
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
