<?php
/**
 * Database class for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Akashic_Forms_DB' ) ) {

    class Akashic_Forms_DB {

        /**
         * Constructor.
         */
        public function __construct() {
            register_activation_hook( AKASHIC_FORMS_PLUGIN_DIR . 'akashic-forms.php', array( $this, 'create_tables' ) );
        }

        /**
         * Create the custom database tables.
         */
        public function create_tables() {
            $this->create_submissions_table();
            $this->create_queue_table();
        }

        /**
         * Create the custom database table for the queue.
         */
        public function create_queue_table() {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                form_id bigint(20) NOT NULL,
                submission_data longtext NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                failure_reason text DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
                processing_started_at datetime DEFAULT NULL,
                PRIMARY KEY  (id)
            ) $charset_collate;";

            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta( $sql );
        }

        /**
         * Create the custom database table for submissions.
         */
        public function create_submissions_table() {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_submissions';

            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                form_id bigint(20) NOT NULL,
                submission_data longtext NOT NULL,
                submitted_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id)
            ) $charset_collate;";

            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta( $sql );
        }

        /**
         * Insert a new submission into the database.
         *
         * @param int   $form_id The ID of the form.
         * @param array $data    The submission data.
         * @return int|false The ID of the inserted row on success, false on failure.
         */
        public function insert_submission( $form_id, $data ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_submissions';

            $serialized_data = serialize( $data );

            $result = $wpdb->insert(
                $table_name,
                array(
                    'form_id'       => $form_id,
                    'submission_data' => $serialized_data,
                ),
                array(
                    '%d',
                    '%s',
                )
            );

            if ( $result ) {
                return $wpdb->insert_id;
            }

            return false;
        }

        /**
         * Get all submissions for a specific form.
         *
         * @param int $form_id The ID of the form.
         * @return array An array of submission objects.
         */
        public function get_submissions( $form_id ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_submissions';

            $results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE form_id = %d ORDER BY submitted_at DESC", $form_id ) );

            foreach ( $results as $result ) {
                $result->submission_data = unserialize( $result->submission_data );
            }

            return $results;
        }

        /**
         * Get a single submission by its ID.
         *
         * @param int $submission_id The ID of the submission.
         * @return object|null The submission object, or null if not found.
         */
        public function get_submission( $submission_id ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_submissions';

            $result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $submission_id ) );

            if ( $result ) {
                $result->submission_data = unserialize( $result->submission_data );
            }

            return $result;
        }

        /**
         * Delete a single submission from the database.
         *
         * @param int $submission_id The ID of the submission to delete.
         * @return int|false The number of rows deleted, or false on error.
         */
        public function delete_submission( $submission_id ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_submissions';

            return $wpdb->delete(
                $table_name,
                array( 'id' => $submission_id ),
                array( '%d' )
            );
        }

        /**
         * Delete all submissions for a specific form.
         *
         * @param int $form_id The ID of the form.
         * @return int|false The number of rows deleted, or false on error.
         */
        public function delete_submissions( $form_id ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_submissions';

            return $wpdb->delete(
                $table_name,
                array( 'form_id' => $form_id ),
                array( '%d' )
            );
        }

        /**
         * Add a submission to the queue.
         *
         * @param int   $form_id The ID of the form.
         * @param array $data    The submission data.
         * @return int|false The ID of the inserted row on success, false on failure.
         */
        public function add_submission_to_queue( $form_id, $data ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $serialized_data = serialize( $data );

            $result = $wpdb->insert(
                $table_name,
                array(
                    'form_id'       => $form_id,
                    'submission_data' => $serialized_data,
                ),
                array(
                    '%d',
                    '%s',
                )
            );

            if ( $result ) {
                return $wpdb->insert_id;
            }

            return false;
        }

        /**
         * Get all submissions from the queue.
         *
         * @param array $args Arguments for retrieving submissions.
         * @return array An array of submission objects.
         */
        public function get_all_submissions_from_queue( $args = array() ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $defaults = array(
                'per_page' => 20,
                'page'     => 1,
                'orderby'  => 'created_at',
                'order'    => 'DESC',
                'search'   => '',
            );

            $args = wp_parse_args( $args, $defaults );

            $sql = "SELECT * FROM $table_name";

            if ( ! empty( $args['search'] ) ) {
                $sql .= " WHERE status LIKE '%%" . esc_sql( $wpdb->esc_like( $args['search'] ) ) . "%%'";
            }

            $sql .= " ORDER BY " . esc_sql( $args['orderby'] ) . " " . esc_sql( $args['order'] );
            $sql .= " LIMIT " . absint( $args['per_page'] );
            $sql .= " OFFSET " . absint( ( $args['page'] - 1 ) * $args['per_page'] );

            $results = $wpdb->get_results( $sql );

            foreach ( $results as $result ) {
                $result->submission_data = unserialize( $result->submission_data );
            }

            return $results;
        }

        /**
         * Get the total number of items in the queue.
         *
         * @return int
         */
        public function get_queue_count() {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';
            return (int) $wpdb->get_var( "SELECT COUNT(id) FROM $table_name" );
        }

        /**
         * Get pending submissions from the queue.
         *
         * @param int $limit The maximum number of submissions to retrieve.
         * @return array An array of submission objects.
         */
        public function get_pending_submissions_from_queue( $limit = 10 ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d", $limit ) );

            foreach ( $results as $result ) {
                $result->submission_data = unserialize( $result->submission_data );
            }

            return $results;
        }

        /**
         * Get pending and failed submissions from the queue.
         *
         * @param int $limit The maximum number of submissions to retrieve.
         * @return array An array of submission objects.
         */
        public function get_pending_and_failed_submissions_from_queue( $limit = 10 ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE status = 'pending' OR status = 'failed' ORDER BY created_at ASC LIMIT %d", $limit ) );

            foreach ( $results as $result ) {
                $result->submission_data = unserialize( $result->submission_data );
            }

            return $results;
        }

        /**
         * Update the status of a submission in the queue.
         *
         * @param int    $submission_id The ID of the submission.
         * @param string $status        The new status.
         * @return int|false The number of rows updated, or false on error.
         */
        public function update_submission_in_queue( $submission_id, $data ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $update_data = array();
            $update_format = array();

            if (isset($data['status'])) {
                $update_data['status'] = $data['status'];
                $update_format[] = '%s';
            }

            if (isset($data['failure_reason'])) {
                $update_data['failure_reason'] = $data['failure_reason'];
                $update_format[] = '%s';
            }

            if (isset($data['processing_started_at'])) {
                $update_data['processing_started_at'] = $data['processing_started_at'];
                $update_format[] = '%s';
            }

            if (empty($update_data)) {
                return false;
            }

            return $wpdb->update(
                $table_name,
                $update_data,
                array( 'id' => $submission_id ),
                $update_format,
                array( '%d' )
            );
        }

        /**
         * Delete a submission from the queue.
         *
         * @param int $submission_id The ID of the submission to delete.
         * @return int|false The number of rows deleted, or false on error.
         */
        public function delete_submission_from_queue( $submission_id ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            return $wpdb->delete(
                $table_name,
                array( 'id' => $submission_id ),
                array( '%d' )
            );
        }

        /**
         * Revert timed-out submissions.
         */
        public function revert_timed_out_submissions() {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';
            $timeout    = 5 * MINUTE_IN_SECONDS;

            $sql = $wpdb->prepare(
                "UPDATE $table_name SET status = 'pending', processing_started_at = NULL WHERE status = 'processing' AND processing_started_at < %s",
                date( 'Y-m-d H:i:s', time() - $timeout )
            );

            $wpdb->query( $sql );
        }

        /**
         * Clear the queue.
         */
        public function clear_queue() {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';
            $wpdb->query( "TRUNCATE TABLE $table_name" );
        }

    }

}

new Akashic_Forms_DB();