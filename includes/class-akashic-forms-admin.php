<?php
/**
 * Admin class for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Akashic_Forms_Admin' ) ) {

    class Akashic_Forms_Admin {

        /**
         * Constructor.
         */
        public function __construct() {
            add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
            add_action( 'admin_init', array( $this, 'export_submissions_to_csv' ) );
            add_action( 'admin_init', array( $this, 'register_google_drive_settings' ) );
            add_action( 'admin_init', array( $this, 'handle_google_drive_disconnect' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        }

        /**
         * Enqueue admin scripts and styles.
         */
        public function enqueue_admin_scripts( $hook ) {
            global $post;

            if ( 'post.php' === $hook || 'post-new.php' === $hook ) {
                if ( isset( $post->post_type ) && 'akashic_forms' === $post->post_type ) {
                    wp_enqueue_script( 'jquery-ui-sortable' );
                }
            }
        }

        /**
         * Add admin menu items.
         */
        public function add_admin_menu() {
            add_menu_page(
                __( 'Akashic Forms', 'akashic-forms' ),
                __( 'Akashic Forms', 'akashic-forms' ),
                'manage_options',
                'akashic-forms',
                array( $this, 'akashic_forms_page' ),
                'dashicons-feedback',
                6
            );

            add_submenu_page(
                'akashic-forms',
                __( 'Submissions', 'akashic-forms' ),
                __( 'Submissions', 'akashic-forms' ),
                'manage_options',
                'akashic-forms-submissions',
                array( $this, 'akashic_forms_submissions_page' )
            );

            add_submenu_page(
                'akashic-forms',
                __( 'Google Drive Settings', 'akashic-forms' ),
                __( 'Google Drive', 'akashic-forms' ),
                'manage_options',
                'akashic-forms-google-drive-settings',
                array( $this, 'akashic_forms_google_drive_settings_page' )
            );
        }

        /**
         * Render the main Akashic Forms admin page.
         */
        public function akashic_forms_page() {
            ?>
            <div class="wrap">
                <h1><?php _e( 'Akashic Forms', 'akashic-forms' ); ?></h1>
                <p><?php _e( 'Welcome to Akashic Forms! Use the menu on the left to create and manage your forms, and view submissions.', 'akashic-forms' ); ?></p>
            </div>
            <?php
        }

        /**
         * Render the Submissions admin page.
         */
        public function akashic_forms_submissions_page() {
            if ( ! class_exists( 'WP_List_Table' ) ) {
                require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
            }

            require_once AKASHIC_FORMS_PLUGIN_DIR . 'includes/class-akashic-forms-submissions-list-table.php';

            $form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

            $forms = get_posts( array(
                'post_type'      => 'akashic_forms',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ) );

            $form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

            // If no form_id is set, and there are forms, default to the first one.
            if ( ! $form_id && ! empty( $forms ) ) {
                $form_id = $forms[0]->ID;
            }

            $form_title = $form_id ? get_the_title( $form_id ) : __( 'No Form Selected', 'akashic-forms' );

            echo '<div class="wrap">';
            echo '<h1>' . sprintf( __( 'Submissions for: %s', 'akashic-forms' ), esc_html( $form_title ) ) . ' <a href="' . esc_url( add_query_arg( array( 'action' => 'export_csv', 'form_id' => $form_id ), admin_url( 'admin.php?page=akashic-forms-submissions' ) ) ) . '" class="page-title-action">' . __( 'Export to CSV', 'akashic-forms' ) . '</a></h1>';

            echo '<div class="akashic-forms-filter">';
            echo '<label for="akashic-form-selector">' . __( 'Select Form:', 'akashic-forms' ) . '</label>';
            echo '<select name="akashic_form_selector" id="akashic-form-selector" onchange="window.location.href = this.value;">';
            echo '<option value="' . esc_url( admin_url( 'admin.php?page=akashic-forms-submissions' ) ) . '">' . __( '— Select a Form —', 'akashic-forms' ) . '</option>';
            if ( ! empty( $forms ) ) {
                foreach ( $forms as $form ) {
                    $selected = selected( $form_id, $form->ID, false );
                    echo '<option value="' . esc_url( add_query_arg( 'form_id', $form->ID, admin_url( 'admin.php?page=akashic-forms-submissions' ) ) ) . '" ' . $selected . '>' . esc_html( $form->post_title ) . '</option>';
                }
            }
            echo '</select>';
            echo '</div>';

            if ( $form_id ) {
                $submissions_table = new Akashic_Forms_Submissions_List_Table( $form_id );
                $submissions_table->prepare_items();
                $submissions_table->display();
            } else {
                echo '<p>' . __( 'Please select a form from the dropdown above to view its submissions.', 'akashic-forms' ) . '</p>';
            }
            echo '</div>';
        }

        /**
         * Export submissions to CSV.
         */
        /**
         * Render the Google Drive Settings admin page.
         */
        public function akashic_forms_google_drive_settings_page() {
            ?>
            <div class="wrap">
                <h1><?php _e( 'Google Drive Integration Settings', 'akashic-forms' ); ?></h1>
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'akashic_forms_google_drive_settings_group' );
                    do_settings_sections( 'akashic-forms-google-drive-settings' );
                    submit_button();
                    ?>
                </form>
                <?php
                $client = new Akashic_Forms_Google_Drive();
                $google_client = $client->get_google_client();

                if ( $google_client ) {
                    if ( ! $google_client->getAccessToken() ) {
                        $auth_url = $google_client->createAuthUrl();
                        echo '<p><a href="' . esc_url( $auth_url ) . '">' . __( 'Authorize Google Drive Integration', 'akashic-forms' ) . '</a></p>';
                    } else {
                        echo '<p>' . __( 'Google Drive is connected.', 'akashic-forms' ) . '</p>';
                        echo '<p><a href="' . esc_url( add_query_arg( 'akashic_forms_google_disconnect', '1', admin_url( 'admin.php?page=akashic-forms-google-drive-settings' ) ) ) . '">' . __( 'Disconnect Google Drive', 'akashic-forms' ) . '</a></p>';
                    }
                } else {
                    echo '<p class="error">' . __( 'Please enter your Google API Client ID and Client Secret to enable Google Drive integration.', 'akashic-forms' ) . '</p>';
                }
                ?>
            </div>
            <?php
        }

        /**
         * Register Google Drive settings.
         */
        public function register_google_drive_settings() {
            register_setting(
                'akashic_forms_google_drive_settings_group',
                'akashic_forms_google_client_id',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                )
            );

            register_setting(
                'akashic_forms_google_drive_settings_group',
                'akashic_forms_google_client_secret',
                array(
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                )
            );

            add_settings_section(
                'akashic_forms_google_drive_section',
                __( 'Google API Credentials', 'akashic-forms' ),
                array( $this, 'google_drive_section_callback' ),
                'akashic-forms-google-drive-settings'
            );

            add_settings_field(
                'akashic_forms_google_client_id_field',
                __( 'Client ID', 'akashic-forms' ),
                array( $this, 'client_id_field_callback' ),
                'akashic-forms-google-drive-settings',
                'akashic_forms_google_drive_section'
            );

            add_settings_field(
                'akashic_forms_google_client_secret_field',
                __( 'Client Secret', 'akashic-forms' ),
                array( $this, 'client_secret_field_callback' ),
                'akashic-forms-google-drive-settings',
                'akashic_forms_google_drive_section'
            );
        }

        /**
         * Google Drive section callback.
         */
        public function google_drive_section_callback() {
            echo '<p>' . __( 'Enter your Google API Client ID and Client Secret. You can create these credentials in the Google API Console.', 'akashic-forms' ) . '</p>';
            echo '<p>' . sprintf( __( 'The authorized redirect URI for your Google project should be: %s', 'akashic-forms' ), '<code>' . admin_url( 'admin.php?page=akashic-forms-google-drive-settings' ) . '</code>' ) . '</p>';
        }

        /**
         * Client ID field callback.
         */
        public function client_id_field_callback() {
            $client_id = get_option( 'akashic_forms_google_client_id' );
            echo '<input type="text" name="akashic_forms_google_client_id" value="' . esc_attr( $client_id ) . '" class="regular-text" />';
        }

        /**
         * Client Secret field callback.
         */
        public function client_secret_field_callback() {
            $client_secret = get_option( 'akashic_forms_google_client_secret' );
            echo '<input type="text" name="akashic_forms_google_client_secret" value="' . esc_attr( $client_secret ) . '" class="regular-text" />';
        }

        /**
         * Handle Google Drive disconnection.
         */
        public function handle_google_drive_disconnect() {
            if ( isset( $_GET['akashic_forms_google_disconnect'] ) && '1' === $_GET['akashic_forms_google_disconnect'] ) {
                if ( ! current_user_can( 'manage_options' ) ) {
                    return;
                }
                delete_option( 'akashic_forms_google_access_token' );
                wp_redirect( admin_url( 'admin.php?page=akashic-forms-google-drive-settings' ) );
                exit;
            }
        }

        /**
         * Export submissions to CSV.
         */
        public function export_submissions_to_csv() {
            if ( ! isset( $_GET['action'] ) || 'export_csv' !== $_GET['action'] ) {
                return;
            }

            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

            if ( ! $form_id ) {
                return;
            }

            $form_title = sanitize_title( get_the_title( $form_id ) );
            $filename = 'akashic-form-submissions-' . $form_title . '-' . date( 'Y-m-d' ) . '.csv';

            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=' . $filename );

            $output = fopen( 'php://output', 'w' );

            $form_fields = get_post_meta( $form_id, '_akashic_form_fields', true );
            $header_row = array();
            if ( ! empty( $form_fields ) ) {
                foreach ( $form_fields as $field ) {
                    if ( isset( $field['label'] ) ) {
                        $header_row[] = $field['label'];
                    }
                }
            }
            $header_row[] = __( 'Submitted At', 'akashic-forms' );
            fputcsv( $output, $header_row );

            $db = new Akashic_Forms_DB();
            $submissions = $db->get_submissions( $form_id );

            foreach ( $submissions as $submission ) {
                $row = array();
                foreach ( $form_fields as $field ) {
                    if ( isset( $field['name'] ) ) {
                        $row[] = isset( $submission->submission_data[ $field['name'] ] ) ? $submission->submission_data[ $field['name'] ] : '';
                    }
                }
                $row[] = $submission->submitted_at;
                fputcsv( $output, $row );
            }

            fclose( $output );
            exit;
        }

    }

}

new Akashic_Forms_Admin();