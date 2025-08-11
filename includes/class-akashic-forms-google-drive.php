<?php
// use Google\Service\Sheets;
/**
 * Google Drive Integration for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Akashic_Forms_Google_Drive' ) ) {

    class Akashic_Forms_Google_Drive {

        private $client_id;
        private $client_secret;
        private $redirect_uri;

        /**
         * Constructor.
         */
        public function __construct() {
            $this->client_id = get_option( 'akashic_forms_google_client_id' );
            $this->client_secret = get_option( 'akashic_forms_google_client_secret' );
            $this->redirect_uri = admin_url( 'admin.php?page=akashic-forms-google-drive-settings' );

            add_action( 'admin_init', array( $this, 'handle_oauth_redirect' ) );
        }

        /**
         * Get Google Client.
         *
         * @return Google_Client|false
         */
        public function get_google_client() {

            if ( empty( $this->client_id ) || empty( $this->client_secret ) ) {
                error_log( 'Akashic Forms Google Drive: get_google_client - Client ID or Client Secret is empty.' );
                return false;
            }

            require_once AKASHIC_FORMS_PLUGIN_DIR . 'vendor/autoload.php'; // Assuming Composer autoloader for Google API Client.

            $client = new Google_Client();
            $client->setClientId( $this->client_id );
            $client->setClientSecret( $this->client_secret );
            $client->setRedirectUri( $this->redirect_uri );
            $client->addScope( \Google\Service\Sheets::SPREADSHEETS ); // Fully qualified
            $client->setAccessType( 'offline' );
            $client->setPrompt( 'select_account consent' );

            $access_token = get_option( 'akashic_forms_google_access_token' );
            if ( $access_token ) {
                $client->setAccessToken( $access_token );
            }

            // If an access token exists and is expired, attempt to refresh it.
            // If no access token exists, we still want to return the client so createAuthUrl() can be called.
            if ( $client->getAccessToken() && $client->isAccessTokenExpired() ) {
                $refresh_token = $client->getRefreshToken();
                if ( $refresh_token ) {
                    try {
                        $client->fetchAccessTokenWithRefreshToken( $refresh_token );
                        update_option( 'akashic_forms_google_access_token', $client->getAccessToken() );
                    } catch (Exception $e) {
                        error_log( 'Akashic Forms Google Drive: Error refreshing token: ' . $e->getMessage() );
                        // If refresh fails, clear the token so re-authentication is forced.
                        delete_option( 'akashic_forms_google_access_token' );
                    }
                } else {
                    error_log( 'Akashic Forms Google Drive: get_google_client - Access token expired and no refresh token available. Clearing stored token to force re-authentication.' );
                    delete_option( 'akashic_forms_google_access_token' ); // Clear the invalid token
                }
            }

            return $client;
        }

        /**
         * Handle OAuth 2.0 redirect.
         */
        public function handle_oauth_redirect() {
            if ( isset( $_GET['page'] ) && 'akashic-forms-google-drive-settings' === $_GET['page'] && isset( $_GET['code'] ) ) {
                $client = $this->get_google_client();
                if ( ! $client ) {
                    return; // Client not configured.
                }

                $auth_code = sanitize_text_field( $_GET['code'] );
                $access_token = $client->fetchAccessTokenWithAuthCode( $auth_code );
                update_option( 'akashic_forms_google_access_token', $access_token );

                // Redirect to clean URL.
                wp_redirect( remove_query_arg( 'code', $this->redirect_uri ) );
                exit;
            }
        }

        /**
         * Append data to a Google Sheet.
         *
         * @param string $spreadsheet_id The ID of the spreadsheet.
         * @param string $range The range to append data to (e.g., 'Sheet1').
         * @param array  $values The data to append.
         * @return bool True on success, false on failure.
         */
        public function append_to_sheet( $spreadsheet_id, $range, $values ) {
            $client = $this->get_google_client();
            if ( ! $client || ! $client->getAccessToken() ) {
                return new WP_Error( 'not_authenticated', 'Google Drive API: Not authenticated.' );
            }

            $service = new \Google\Service\Sheets( $client ); // Fully qualified

            $body = new \Google\Service\Sheets\ValueRange( array( // Fully qualified
                'values' => array( $values )
            ) );

            $params = array(
                'valueInputOption' => 'RAW'
            );

            try {
                $result = $service->spreadsheets_values->append( $spreadsheet_id, $range, $body, $params );
                return true;
            } catch ( \Google\Service\Exception $e ) { // Fully qualified
                if ( 429 == $e->getCode() ) {
                    return new WP_Error( 'rate_limit_exceeded', 'Google Sheets API rate limit exceeded.' );
                }
                return new WP_Error( 'google_api_error', 'Google Drive API Error: ' . $e->getMessage() );
            } catch ( Exception $e ) {
                return new WP_Error( 'generic_error', 'An unexpected error occurred: ' . $e->getMessage() );
            }
        }

        /**
         * Get spreadsheet headers.
         *
         * @param string $spreadsheet_id The ID of the spreadsheet.
         * @param string $sheet_name The name of the sheet.
         * @return array|false The headers of the sheet, or false on failure.
         */
        public function get_spreadsheet_headers( $spreadsheet_id, $sheet_name ) {
            $client = $this->get_google_client();
            if ( ! $client || ! $client->getAccessToken() ) {
                return new WP_Error( 'not_authenticated', 'Google Drive API: Not authenticated.' );
            }

            $service = new \Google\Service\Sheets( $client ); // Fully qualified

            try {
                $response = $service->spreadsheets_values->get( $spreadsheet_id, $sheet_name . '!1:1' );
                $values = $response->getValues();
                if ( empty( $values ) ) {
                    return array();
                }
                return $values[0];
            } catch ( \Google\Service\Exception $e ) {
                if ( 429 == $e->getCode() ) {
                    return new WP_Error( 'rate_limit_exceeded', 'Google Sheets API rate limit exceeded.' );
                }
                return new WP_Error( 'google_api_error', 'Google Drive API Error: ' . $e->getMessage() );
            } catch ( Exception $e ) {
                return new WP_Error( 'generic_error', 'An unexpected error occurred: ' . $e->getMessage() );
            }
        }

    }

}

new Akashic_Forms_Google_Drive();