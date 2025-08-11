<?php
/**
 * REST API for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Akashic_Forms_REST_API' ) ) {

    class Akashic_Forms_REST_API {

        /**
         * Constructor.
         */
        public function __construct() {
            add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        }

        /**
         * Register the REST API routes.
         */
        public function register_routes() {
            register_rest_route( 'akashic-forms/v1', '/sync', array(
                'methods' => 'POST',
                'callback' => array( $this, 'handle_sync_request' ),
                'permission_callback' => '__return_true', // For simplicity, but should be secured.
            ) );
        }

        /**
         * Handle the sync request.
         *
         * @param WP_REST_Request $request The REST request object.
         * @return WP_REST_Response The REST response object.
         */
        public function handle_sync_request( $request ) {
            $form_id = $request->get_param( 'form_id' );
            $form_data = $request->get_param( 'form_data' );

            error_log( 'Akashic Forms: handle_sync_request - form_id: ' . $form_id );

            if ( empty( $form_id ) || empty( $form_data ) ) {
                return new WP_REST_Response( array( 'message' => 'Missing form_id or form_data.' ), 400 );
            }

            $db = new Akashic_Forms_DB();
            $db->insert_submission( $form_id, $form_data ); // Insert into akashic_form_submissions table

            $google_drive = new Akashic_Forms_Google_Drive();
            $spreadsheet_id = get_post_meta( $form_id, '_akashic_form_google_sheet_id', true ); // Corrected meta key
            $sheet_name = get_post_meta( $form_id, '_akashic_form_google_sheet_name', true ); // Corrected meta key

            error_log( 'Akashic Forms: handle_sync_request - spreadsheet_id: ' . $spreadsheet_id );
            error_log( 'Akashic Forms: handle_sync_request - sheet_name: ' . $sheet_name );

            if ( empty( $spreadsheet_id ) || empty( $sheet_name ) ) {
                // If no sheet is configured, just queue it
                $db->add_submission_to_queue( $form_id, $form_data );
                return new WP_REST_Response( array( 'message' => 'Submission queued successfully.' ), 200 );
            }

                        $headers = $google_drive->get_spreadsheet_headers( $spreadsheet_id, $sheet_name );
            if ( is_wp_error( $headers ) ) {
                // If getting headers failed, queue the submission
                $db->add_submission_to_queue( $form_id, $form_data );
                return new WP_REST_Response( array( 'message' => 'Failed to get spreadsheet headers, submission queued.' ), 200 );
            }

            // If headers were empty, try to create them
            if ( empty( $headers ) ) {
                $form_fields = get_post_meta( $form_id, '_akashic_forms_fields', true );
                if ( ! is_array( $form_fields ) ) {
                    $form_fields = array();
                }
                $new_headers = array();
                foreach ( $form_fields as $field ) {
                    $new_headers[] = $field['label'];
                }
                // Append headers to sheet
                $append_headers_result = $google_drive->append_to_sheet( $spreadsheet_id, $sheet_name, $new_headers );
                if ( is_wp_error( $append_headers_result ) || ! $append_headers_result ) {
                    // If appending headers failed, queue the submission
                    $db->add_submission_to_queue( $form_id, $form_data );
                    return new WP_REST_Response( array( 'message' => 'Failed to create spreadsheet headers, submission queued.' ), 200 );
                }
                $headers = $new_headers;
            }

            $values = array();
            foreach ( $headers as $header ) {
                $values[] = isset( $form_data[ $header ] ) ? $form_data[ $header ] : '';
            }

            $result = $google_drive->append_to_sheet( $spreadsheet_id, $sheet_name, $values );

            if ( is_wp_error( $result ) || ! $result ) {
                // If the sync fails, add it to the queue
                $db->add_submission_to_queue( $form_id, $form_data );
                return new WP_REST_Response( array( 'message' => 'Submission queued successfully.' ), 200 );
            }

            return new WP_REST_Response( array( 'message' => 'Data synced successfully.' ), 200 );
        }
    }

    new Akashic_Forms_REST_API();
}
