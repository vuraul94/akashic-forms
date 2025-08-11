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

            if ( empty( $form_id ) || empty( $form_data ) ) {
                return new WP_REST_Response( array( 'message' => 'Missing form_id or form_data.' ), 400 );
            }

            $db = new Akashic_Forms_DB();
            $result = $db->add_submission_to_queue( $form_id, $form_data );

            if ( ! $result ) {
                return new WP_REST_Response( array( 'message' => 'Failed to add submission to queue.' ), 500 );
            }

            return new WP_REST_Response( array( 'message' => 'Submission queued successfully.' ), 200 );
        }
    }

    new Akashic_Forms_REST_API();
}
