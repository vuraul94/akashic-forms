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
            register_activation_hook( AKASHIC_FORMS_PLUGIN_DIR . 'akashic-forms.php', array( $this, 'create_submissions_table' ) );
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

    }

}

new Akashic_Forms_DB();