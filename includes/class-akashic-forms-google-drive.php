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
            $this->client_id = defined( 'AKASHIC_FORMS_GOOGLE_CLIENT_ID' ) && AKASHIC_FORMS_GOOGLE_CLIENT_ID
                ? AKASHIC_FORMS_GOOGLE_CLIENT_ID
                : get_option( 'akashic_forms_google_client_id' );
            $this->client_secret = defined( 'AKASHIC_FORMS_GOOGLE_CLIENT_SECRET' ) && AKASHIC_FORMS_GOOGLE_CLIENT_SECRET
                ? AKASHIC_FORMS_GOOGLE_CLIENT_SECRET
                : get_option( 'akashic_forms_google_client_secret' );
            $this->redirect_uri = admin_url( 'admin.php?page=akashic-forms-google-drive-settings' );

            add_action( 'admin_init', array( $this, 'handle_oauth_redirect' ) );
        }

        /**
         * Whether the Google credentials are (at least partially) defined via constants.
         *
         * @return bool
         */
        public function has_credentials_constants() {
            return defined( 'AKASHIC_FORMS_GOOGLE_CLIENT_ID' ) || defined( 'AKASHIC_FORMS_GOOGLE_CLIENT_SECRET' );
        }

        /**
         * Get Google Client.
         *
         * @return Google_Client|false
         */
        public function get_google_client() {

            if ( empty( $this->client_id ) || empty( $this->client_secret ) ) {
                akashic_forms_log( 'Google Drive: get_google_client - Client ID or Client Secret is empty.' );
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

            $stored_token = get_option( 'akashic_forms_google_access_token' );
            if ( $stored_token ) {
                $client->setAccessToken( $stored_token );
            }

            // If an access token exists and is expired, attempt to refresh it.
            // If no access token exists, we still want to return the client so createAuthUrl() can be called.
            if ( $client->getAccessToken() && $client->isAccessTokenExpired() ) {
                // Try to get the refresh token from the client first, then fall back to the stored token.
                $refresh_token = $client->getRefreshToken();
                if ( empty( $refresh_token ) && is_array( $stored_token ) && ! empty( $stored_token['refresh_token'] ) ) {
                    $refresh_token = $stored_token['refresh_token'];
                }

                if ( $refresh_token ) {
                    try {
                        $new_token = $client->fetchAccessTokenWithRefreshToken( $refresh_token );

                        // Check if the refresh itself returned an error (e.g. revoked token).
                        if ( isset( $new_token['error'] ) ) {
                            akashic_forms_log( 'Google Drive: Token refresh returned an error from Google. Stored token cleared.' );
                            delete_option( 'akashic_forms_google_access_token' );
                            return $client;
                        }

                        // Preserve the refresh_token: Google's refresh response often omits it.
                        $updated_token = $client->getAccessToken();
                        if ( empty( $updated_token['refresh_token'] ) && ! empty( $refresh_token ) ) {
                            $updated_token['refresh_token'] = $refresh_token;
                            $client->setAccessToken( $updated_token );
                        }

                        update_option( 'akashic_forms_google_access_token', $updated_token, false );
                        akashic_forms_log( 'Google Drive: Token refreshed successfully.' );
                    } catch (Exception $e) {
                        akashic_forms_log( 'Google Drive: Error refreshing token: ' . $e->getMessage() );
                        // If refresh fails, clear the token so re-authentication is forced.
                        delete_option( 'akashic_forms_google_access_token' );
                    }
                } else {
                    akashic_forms_log( 'Google Drive: Access token expired and no refresh token available. Clearing stored token to force re-authentication.' );
                    delete_option( 'akashic_forms_google_access_token' );
                }
            }

            return $client;
        }

        /**
         * Build the Google authorization URL, storing a one-time CSRF state.
         *
         * @return string The authorization URL, or an empty string if the client is not configured.
         */
        public function get_auth_url() {
            $client = $this->get_google_client();
            if ( ! $client ) {
                return '';
            }

            $state = wp_generate_password( 32, false, false );

            update_option(
                'akashic_forms_google_oauth_state',
                array(
                    'state'   => $state,
                    'created' => time(),
                    'user_id' => get_current_user_id(),
                ),
                false
            );

            $client->setState( $state );

            return $client->createAuthUrl();
        }

        /**
         * Handle OAuth 2.0 redirect.
         */
        public function handle_oauth_redirect() {
            if ( isset( $_GET['page'] ) && 'akashic-forms-google-drive-settings' === $_GET['page'] && isset( $_GET['code'] ) ) {
                // Only administrators may complete the OAuth handshake.
                if ( ! current_user_can( 'manage_options' ) ) {
                    return;
                }

                $clean_url = remove_query_arg( array( 'code', 'state' ), $this->redirect_uri );

                if ( ! $this->validate_oauth_state() ) {
                    delete_option( 'akashic_forms_google_oauth_state' );
                    akashic_forms_log( 'Google Drive: OAuth callback rejected due to an invalid or expired state parameter.' );
                    wp_redirect( add_query_arg( 'auth_error', 'invalid_state', $clean_url ) );
                    exit;
                }

                // The state is single-use: discard it as soon as it has been validated.
                delete_option( 'akashic_forms_google_oauth_state' );

                $client = $this->get_google_client();
                if ( ! $client ) {
                    return; // Client not configured.
                }

                $auth_code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
                $token_response = $client->fetchAccessTokenWithAuthCode( $auth_code );

                // Check if the token response contains an error.
                if ( isset( $token_response['error'] ) ) {
                    akashic_forms_log( 'Google Drive: OAuth token exchange failed.' );
                    wp_redirect( add_query_arg( 'auth_error', 'token_exchange_failed', $clean_url ) );
                    exit;
                }

                // Preserve the refresh_token: if the new response doesn't include one,
                // keep the previously stored refresh_token. Google only sends the
                // refresh_token on the first authorization or when consent is re-prompted.
                $existing_token = get_option( 'akashic_forms_google_access_token' );
                if ( empty( $token_response['refresh_token'] ) && is_array( $existing_token ) && ! empty( $existing_token['refresh_token'] ) ) {
                    $token_response['refresh_token'] = $existing_token['refresh_token'];
                }

                update_option( 'akashic_forms_google_access_token', $token_response, false );

                akashic_forms_log( 'Google Drive: OAuth token saved successfully. Refresh token present: ' . ( ! empty( $token_response['refresh_token'] ) ? 'yes' : 'NO - may cause issues' ) );

                // Redirect to clean URL.
                wp_redirect( $clean_url );
                exit;
            }
        }

        /**
         * Validate the OAuth state parameter returned by Google.
         *
         * @return bool True if the state is valid for the current user, false otherwise.
         */
        private function validate_oauth_state() {
            if ( empty( $_GET['state'] ) ) {
                return false;
            }

            $stored = get_option( 'akashic_forms_google_oauth_state' );
            if ( ! is_array( $stored ) || empty( $stored['state'] ) ) {
                return false;
            }

            $received = sanitize_text_field( wp_unslash( $_GET['state'] ) );
            if ( ! hash_equals( (string) $stored['state'], $received ) ) {
                return false;
            }

            if ( ! isset( $stored['user_id'] ) || (int) $stored['user_id'] !== get_current_user_id() ) {
                return false;
            }

            if ( ! isset( $stored['created'] ) || ( time() - (int) $stored['created'] ) > ( 15 * MINUTE_IN_SECONDS ) ) {
                return false;
            }

            return true;
        }

        /**
         * Append data to a Google Sheet.
         *
         * @param string $spreadsheet_id The ID of the spreadsheet.
         * @param string $range The range to append data to (e.g., 'Sheet1').
         * @param array  $values The data to append.
         * @param bool   $is_retry Whether this is a retry after a token refresh.
         * @return bool|WP_Error True on success, WP_Error on failure.
         */
        public function append_to_sheet( $spreadsheet_id, $range, $values, $is_retry = false ) {
            $client = $this->get_google_client();
            if ( ! $client || ! $client->getAccessToken() ) {
                return new WP_Error( 'not_authenticated', 'Google Drive API: Not authenticated.' );
            }

            // Check if the token is still expired after get_google_client attempted refresh.
            if ( $client->isAccessTokenExpired() ) {
                akashic_forms_log( 'Google Drive: append_to_sheet - Token is expired and could not be refreshed.' );
                return new WP_Error( 'token_expired', 'Google Drive API: Access token is expired and could not be refreshed. Please re-authorize in Google Drive settings.' );
            }

            $service = new \Google\Service\Sheets( $client );

            $body = new \Google\Service\Sheets\ValueRange( array(
                'values' => array( $values )
            ) );

            $params = array(
                'valueInputOption' => 'RAW'
            );

            try {
                $result = $service->spreadsheets_values->append( $spreadsheet_id, $range, $body, $params );
                return true;
            } catch ( \Google\Service\Exception $e ) {
                if ( 429 == $e->getCode() ) {
                    return new WP_Error( 'rate_limit_exceeded', 'Google Sheets API rate limit exceeded.' );
                }
                // On 401 Unauthorized, attempt a single retry after forcing a token refresh.
                if ( 401 == $e->getCode() && ! $is_retry ) {
                    akashic_forms_log( 'Google Drive: append_to_sheet - 401 error, attempting token refresh and retry.' );
                    $refreshed = $this->force_token_refresh();
                    if ( $refreshed ) {
                        return $this->append_to_sheet( $spreadsheet_id, $range, $values, true );
                    }
                    return new WP_Error( 'token_refresh_failed', 'Google Drive API: Token refresh failed after 401 error. Please re-authorize.' );
                }
                akashic_forms_log( 'Google Drive: append_to_sheet error (' . $e->getCode() . '): ' . $e->getMessage() );
                return new WP_Error( 'google_api_error', 'Google Drive API Error: ' . $e->getMessage() );
            } catch ( Exception $e ) {
                akashic_forms_log( 'Google Drive: append_to_sheet unexpected error: ' . $e->getMessage() );
                return new WP_Error( 'generic_error', 'An unexpected error occurred: ' . $e->getMessage() );
            }
        }

        /**
         * Get spreadsheet headers.
         *
         * @param string $spreadsheet_id The ID of the spreadsheet.
         * @param string $sheet_name The name of the sheet.
         * @param bool   $is_retry Whether this is a retry after a token refresh.
         * @return array|WP_Error The headers of the sheet, or WP_Error on failure.
         */
        public function get_spreadsheet_headers( $spreadsheet_id, $sheet_name, $is_retry = false ) {
            $client = $this->get_google_client();
            if ( ! $client || ! $client->getAccessToken() ) {
                return new WP_Error( 'not_authenticated', 'Google Drive API: Not authenticated.' );
            }

            // Check if the token is still expired after get_google_client attempted refresh.
            if ( $client->isAccessTokenExpired() ) {
                akashic_forms_log( 'Google Drive: get_spreadsheet_headers - Token is expired and could not be refreshed.' );
                return new WP_Error( 'token_expired', 'Google Drive API: Access token is expired and could not be refreshed. Please re-authorize in Google Drive settings.' );
            }

            $service = new \Google\Service\Sheets( $client );

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
                // On 401 Unauthorized, attempt a single retry after forcing a token refresh.
                if ( 401 == $e->getCode() && ! $is_retry ) {
                    akashic_forms_log( 'Google Drive: get_spreadsheet_headers - 401 error, attempting token refresh and retry.' );
                    $refreshed = $this->force_token_refresh();
                    if ( $refreshed ) {
                        return $this->get_spreadsheet_headers( $spreadsheet_id, $sheet_name, true );
                    }
                    return new WP_Error( 'token_refresh_failed', 'Google Drive API: Token refresh failed after 401 error. Please re-authorize.' );
                }
                akashic_forms_log( 'Google Drive: get_spreadsheet_headers error (' . $e->getCode() . '): ' . $e->getMessage() );
                return new WP_Error( 'google_api_error', 'Google Drive API Error: ' . $e->getMessage() );
            } catch ( Exception $e ) {
                akashic_forms_log( 'Google Drive: get_spreadsheet_headers unexpected error: ' . $e->getMessage() );
                return new WP_Error( 'generic_error', 'An unexpected error occurred: ' . $e->getMessage() );
            }
        }

        /**
         * Force a token refresh. Used when API calls return 401.
         *
         * @return bool True if the token was refreshed successfully, false otherwise.
         */
        public function force_token_refresh() {
            $stored_token = get_option( 'akashic_forms_google_access_token' );
            if ( ! is_array( $stored_token ) || empty( $stored_token['refresh_token'] ) ) {
                akashic_forms_log( 'Google Drive: force_token_refresh - No refresh token available.' );
                return false;
            }

            require_once AKASHIC_FORMS_PLUGIN_DIR . 'vendor/autoload.php';

            $client = new Google_Client();
            $client->setClientId( $this->client_id );
            $client->setClientSecret( $this->client_secret );

            try {
                $new_token = $client->fetchAccessTokenWithRefreshToken( $stored_token['refresh_token'] );

                if ( isset( $new_token['error'] ) ) {
                    akashic_forms_log( 'Google Drive: force_token_refresh failed. Stored token cleared.' );
                    delete_option( 'akashic_forms_google_access_token' );
                    return false;
                }

                // Preserve the refresh_token if not included in the response.
                if ( empty( $new_token['refresh_token'] ) ) {
                    $new_token['refresh_token'] = $stored_token['refresh_token'];
                }

                update_option( 'akashic_forms_google_access_token', $new_token, false );
                akashic_forms_log( 'Google Drive: force_token_refresh succeeded.' );
                return true;
            } catch ( Exception $e ) {
                akashic_forms_log( 'Google Drive: force_token_refresh exception: ' . $e->getMessage() );
                delete_option( 'akashic_forms_google_access_token' );
                return false;
            }
        }

        /**
         * Get the current token status for display in the admin.
         *
         * @return array {
         *     @type string $status   'connected', 'expired', 'no_refresh_token', 'not_configured'
         *     @type string $message  Human-readable status message.
         *     @type string $expires  Token expiry time if available.
         * }
         */
        public function get_token_status() {
            $stored_token = get_option( 'akashic_forms_google_access_token' );

            if ( empty( $this->client_id ) || empty( $this->client_secret ) ) {
                return array(
                    'status'  => 'not_configured',
                    'message' => __( 'Google API credentials are not configured.', 'akashic-forms' ),
                    'expires' => '',
                );
            }

            if ( empty( $stored_token ) ) {
                return array(
                    'status'  => 'not_connected',
                    'message' => __( 'Not connected. Please authorize Google Drive.', 'akashic-forms' ),
                    'expires' => '',
                );
            }

            $has_refresh_token = is_array( $stored_token ) && ! empty( $stored_token['refresh_token'] );
            $expires_at = isset( $stored_token['created'] ) && isset( $stored_token['expires_in'] )
                ? $stored_token['created'] + $stored_token['expires_in']
                : 0;
            $is_expired = $expires_at > 0 && $expires_at < time();
            $expires_formatted = $expires_at > 0
                ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $expires_at ), 'Y-m-d H:i:s' )
                : __( 'Unknown', 'akashic-forms' );

            if ( ! $has_refresh_token ) {
                return array(
                    'status'  => 'no_refresh_token',
                    'message' => __( 'WARNING: No refresh token stored. The connection will break when the access token expires. Please disconnect and re-authorize.', 'akashic-forms' ),
                    'expires' => $expires_formatted,
                );
            }

            if ( $is_expired ) {
                return array(
                    'status'  => 'expired',
                    'message' => __( 'Access token is expired but a refresh token is available. It will be refreshed automatically on the next API call.', 'akashic-forms' ),
                    'expires' => $expires_formatted,
                );
            }

            return array(
                'status'  => 'connected',
                'message' => __( 'Google Drive is connected and functioning correctly.', 'akashic-forms' ),
                'expires' => $expires_formatted,
            );
        }

    }

}

// Only register admin hooks on admin requests and during cron runs.
// The class definition stays available everywhere so the queue processor
// and other callers can instantiate it on demand.
if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
    new Akashic_Forms_Google_Drive();
}