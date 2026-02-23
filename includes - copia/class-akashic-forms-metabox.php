<?php
/**
 * Meta Box for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Akashic_Forms_Metabox' ) ) {

    class Akashic_Forms_Metabox {

        /**
         * Constructor.
         */
        public function __construct() {
            add_action( 'add_meta_boxes', array( $this, 'add_form_fields_meta_box' ) );
            add_action( 'add_meta_boxes', array( $this, 'add_submission_settings_meta_box' ) );
            add_action( 'save_post', array( $this, 'save_form_meta_box_data' ) );
        }

        /**
         * Add the Form Fields meta box.
         */
        public function add_form_fields_meta_box() {
            add_meta_box(
                'akashic_form_fields',
                __( 'Form Fields', 'akashic-forms' ),
                array( $this, 'render_form_fields_meta_box' ),
                'akashic_forms',
                'normal',
                'high'
            );

            add_meta_box(
                'akashic_form_email_settings',
                __( 'Email Notification Settings', 'akashic-forms' ),
                array( $this, 'render_email_settings_meta_box' ),
                'akashic_forms',
                'normal',
                'high'
            );

            add_meta_box(
                'akashic_form_google_drive_settings',
                __( 'Google Drive Integration Settings', 'akashic-forms' ),
                array( $this, 'render_google_drive_settings_meta_box' ),
                'akashic_forms',
                'normal',
                'high'
            );

            // Enqueue the color picker script and style.
            wp_enqueue_style( 'wp-color-picker' );
            wp_enqueue_script( 'wp-color-picker' );
        }

        /**
         * Add the Submission Settings meta box.
         */
        public function add_submission_settings_meta_box() {
            add_meta_box(
                'akashic_form_submission_settings',
                __( 'Submission Settings', 'akashic-forms' ),
                array( $this, 'render_submission_settings_meta_box' ),
                'akashic_forms',
                'normal',
                'high'
            );
        }

        /**
         * Render the Submission Settings meta box content.
         */
        public function render_submission_settings_meta_box( $post ) {
            wp_nonce_field( 'akashic_save_submission_settings_meta_box_data', 'akashic_submission_settings_meta_box_nonce' );

            $submission_action = get_post_meta( $post->ID, '_akashic_form_submission_action', true );
            $redirect_url = get_post_meta( $post->ID, '_akashic_form_redirect_url', true );
            $form_message = get_post_meta( $post->ID, '_akashic_form_message', true );
            $modal_message = get_post_meta( $post->ID, '_akashic_form_modal_message', true );
             $submit_button_text = get_post_meta( $post->ID, '_akashic_form_submit_button_text', true );
            if ( empty( $submit_button_text ) ) {
                $submit_button_text = __( 'Submit', 'akashic-forms' );
            }
            $submitting_button_text = get_post_meta( $post->ID, '_akashic_form_submitting_button_text', true );
            if ( empty( $submitting_button_text ) ) {
                $submitting_button_text = __( 'Submitting...', 'akashic-forms' );
            }

            ?>
            <p>
                <label for="akashic_form_submission_action"><?php _e( 'Action After Submission:', 'akashic-forms' ); ?></label>
                <select name="akashic_form_submission_action" id="akashic_form_submission_action">
                    <option value="redirect" <?php selected( $submission_action, 'redirect' ); ?>><?php _e( 'Redirect to URL', 'akashic-forms' ); ?></option>
                    <option value="message" <?php selected( $submission_action, 'message' ); ?>><?php _e( 'Replace form with a message', 'akashic-forms' ); ?></option>
                    <option value="modal" <?php selected( $submission_action, 'modal' ); ?>><?php _e( 'Show message in a modal', 'akashic-forms' ); ?></option>
                </select>
            </p>
            <div id="akashic-submission-action-settings">
                <p class="submission-action-setting" data-action="redirect" style="<?php echo 'redirect' !== $submission_action ? 'display: none;' : ''; ?>">
                    <label for="akashic_form_redirect_url"><?php _e( 'Redirect URL:', 'akashic-forms' ); ?></label>
                    <input type="url" name="akashic_form_redirect_url" id="akashic_form_redirect_url" value="<?php echo esc_url( $redirect_url ); ?>" class="large-text" />
                </p>
                <div class="submission-action-setting" data-action="message" style="<?php echo 'message' !== $submission_action ? 'display: none;' : ''; ?>">
                    <label for="akashic_form_message"><?php _e( 'Message:', 'akashic-forms' ); ?></label>
                    <?php wp_editor( $form_message, 'akashic_form_message', array( 'textarea_name' => 'akashic_form_message' ) ); ?>
                </div>
                <div class="submission-action-setting" data-action="modal" style="<?php echo 'modal' !== $submission_action ? 'display: none;' : ''; ?>">
                    <label for="akashic_form_modal_message"><?php _e( 'Modal Message:', 'akashic-forms' ); ?></label>
                    <?php wp_editor( $modal_message, 'akashic_form_modal_message', array( 'textarea_name' => 'akashic_form_modal_message' ) ); ?>
                </div>
            </div>
            <p>
                <label for="akashic_form_submit_button_text"><?php _e( 'Submit Button Text:', 'akashic-forms' ); ?></label>
                <input type="text" name="akashic_form_submit_button_text" id="akashic_form_submit_button_text" value="<?php echo esc_attr( $submit_button_text ); ?>" class="large-text" />
            </p>
            <p>
                <label for="akashic_form_submitting_button_text"><?php _e( 'Submitting Button Text:', 'akashic-forms' ); ?></label>
                <input type="text" name="akashic_form_submitting_button_text" id="akashic_form_submitting_button_text" value="<?php echo esc_attr( $submitting_button_text ); ?>" class="large-text" />
            </p>
            <script>
                jQuery(document).ready(function($) {
                    $('#akashic_form_submission_action').on('change', function() {
                        var selectedAction = $(this).val();
                        $('.submission-action-setting').hide();
                        $('.submission-action-setting[data-action="' + selectedAction + '"]').show();
                    });
                });
            </script>
            <?php
        }

        /**
         * Render the Form Fields meta box content.
         */
        public function render_form_fields_meta_box( $post ) {
            wp_nonce_field( 'akashic_save_form_fields_meta_box_data', 'akashic_form_fields_meta_box_nonce' );

            $form_fields = get_post_meta( $post->ID, '_akashic_form_fields', true );
            if ( empty( $form_fields ) ) {
                $form_fields = array();
            }
            ?>
            <div id="akashic-form-fields-wrapper">
                <?php
                if ( ! empty( $form_fields ) ) {
                    foreach ( $form_fields as $key => $field ) {
                        $this->render_field_row( $key, $field );
                    }
                }
                ?>
            </div>
            <p>
                <button type="button" class="button button-primary" id="akashic-add-field"><?php _e( 'Add Field', 'akashic-forms' ); ?></button>
            </p>

            <script type="text/template" id="akashic-field-template">
                <?php $this->render_field_row( '__FIELD_KEY__' ); ?>
            </script>

            <script type="text/template" id="akashic-field-option-template">
                <?php $this->render_field_option_row( '__FIELD_KEY__', '__OPTION_KEY__' ); ?>
            </script>

            <script>
                jQuery(document).ready(function($) {
                    var field_key = <?php echo count( $form_fields ); ?>;

                    function initialize_color_picker( $parent ) {
                        $parent.find('.akashic-color-picker').wpColorPicker();
                    }

                    // Initialize for existing fields.
                    $('#akashic-form-fields-wrapper .akashic-field-row').each(function(){
                        initialize_color_picker( $(this) );
                    });

                    // Function to toggle field settings visibility based on type
                    function toggleFieldSettings($fieldRow) {
                        var fieldType = $fieldRow.find('.akashic-field-type-select').val();

                        // Hide all settings initially
                        $fieldRow.find('.akashic-field-setting').hide();

                        // Show settings based on field type
                        switch (fieldType) {
                            case 'text':
                            case 'email':
                            case 'password':
                            case 'url':
                            case 'tel':
                            case 'search':
                            case 'color':
                            case 'date':
                            case 'time':
                                $fieldRow.find('.akashic-field-setting-required').show();
                                $fieldRow.find('.akashic-field-setting-validation').show();
                                $fieldRow.find('.akashic-field-setting-min-max-step').show();
                                $fieldRow.find('.akashic-field-setting-parent-fieldset').show();
                                break;
                            case 'textarea':
                                $fieldRow.find('.akashic-field-setting-required').show();
                                $fieldRow.find('.akashic-field-setting-parent-fieldset').show();
                                break;
                            case 'file':
                                $fieldRow.find('.akashic-field-setting-required').show();
                                $fieldRow.find('.akashic-field-setting-parent-fieldset').show();
                                $fieldRow.find('.akashic-field-setting-file').show();
                                break;
                            case 'number':
                            case 'range':
                                $fieldRow.find('.akashic-field-setting-required').show();
                                $fieldRow.find('.akashic-field-setting-min-max-step').show();
                                $fieldRow.find('.akashic-field-setting-parent-fieldset').show();
                                break;
                            case 'select':
                            case 'radio':
                            case 'checkbox': // This will now be for multiple checkboxes
                            case 'datalist':
                                $fieldRow.find('.akashic-field-setting-required').show();
                                $fieldRow.find('.akashic-field-setting-options').show();
                                $fieldRow.find('.akashic-field-setting-parent-fieldset').show();
                                break;
                            case 'checkbox_single': // New case for singular checkbox
                                $fieldRow.find('.akashic-field-setting-required').show();
                                // No options for singular checkbox, so don't show .akashic-field-setting-options
                                $fieldRow.find('.akashic-field-setting-parent-fieldset').show();
                                break;
                            case 'hidden':
                            case 'output':
                                $fieldRow.find('.akashic-field-setting-parent-fieldset').show();
                                break;
                            case 'fieldset':
                                // Fieldset has no specific settings other than label and name
                                break;
                        }
                    }

                    // Initial toggle for existing fields
                    $('.akashic-field-row').each(function() {
                        toggleFieldSettings($(this));
                    });

                    $('#akashic-form-fields-wrapper').sortable({
                        handle: '.akashic-field-handle',
                        placeholder: 'akashic-field-row-placeholder',
                        update: function(event, ui) {
                            $(this).find('.akashic-field-row').each(function(index) {
                                $(this).find('input, select, textarea').each(function() {
                                    var name = $(this).attr('name');
                                    if (name) {
                                        name = name.replace(/\\[(\\d+)\\]/, '[' + index + ']');
                                        $(this).attr('name', name);
                                    }
                                });
                            });
                        }
                    });

                    $('#akashic-add-field').on('click', function() {
                        var template = $('#akashic-field-template').html();
                        template = template.replace(/__FIELD_KEY__/g, field_key);
                        var $newField = $(template);
                        $('#akashic-form-fields-wrapper').append($newField);
                        toggleFieldSettings($newField); // Toggle settings for new field
                        initialize_color_picker( $newField ); // Initialize color picker for new field
                        field_key++;
                    });

                    $('#akashic-form-fields-wrapper').on('click', '.akashic-remove-field', function() {
                        $(this).closest('.akashic-field-row').remove();
                    });

                    // Handle field type change
                    $('#akashic-form-fields-wrapper').on('change', '.akashic-field-type-select', function() {
                        toggleFieldSettings($(this).closest('.akashic-field-row'));
                    });

                    // Handle adding options
                    $('#akashic-form-fields-wrapper').on('click', '.akashic-add-option', function() {
                        var current_field_key = $(this).data('field-key');
                        var option_key = $(this).closest('.akashic-field-options').find('.akashic-field-option-row').length;
                        var template = $('#akashic-field-option-template').html();
                        template = template.replace(/__FIELD_KEY__/g, current_field_key);
                        template = template.replace(/__OPTION_KEY__/g, option_key);
                        $(this).closest('.akashic-field-options').find('.akashic-field-options-wrapper').append(template);
                    });

                    // Handle removing options
                    $('#akashic-form-fields-wrapper').on('click', '.akashic-remove-option', function() {
                        $(this).closest('.akashic-field-option-row').remove();
                    });

                    // Show/hide unique message field
                    $('#akashic-form-fields-wrapper').on('change', '.akashic-field-unique-checkbox', function() {
                        var $fieldRow = $(this).closest('.akashic-field-row');
                        if ($(this).is(':checked')) {
                            $fieldRow.find('.akashic-field-setting-unique-message').show();
                        } else {
                            $fieldRow.find('.akashic-field-setting-unique-message').hide();
                        }
                    });
                });
            </script>
            <?php
        }

        /**
         * Render the Email Settings meta box content.
         */
        public function render_email_settings_meta_box( $post ) {
            wp_nonce_field( 'akashic_save_email_settings_meta_box_data', 'akashic_email_settings_meta_box_nonce' );

            $recipient_email = get_post_meta( $post->ID, '_akashic_form_email_recipient', true );
            $email_subject = get_post_meta( $post->ID, '_akashic_form_email_subject', true );
            $email_message = get_post_meta( $post->ID, '_akashic_form_email_message', true );
            ?>
            <p>
                <label for="akashic_form_email_recipient"><?php _e( 'Recipient Email:', 'akashic-forms' ); ?></label>
                <input type="email" name="akashic_form_email_recipient" id="akashic_form_email_recipient" value="<?php echo esc_attr( $recipient_email ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'e.g., admin@example.com', 'akashic-forms' ); ?>" />
                <p class="description"><?php _e( 'Enter the email address where submission notifications should be sent.', 'akashic-forms' ); ?></p>
            </p>
            <p>
                <label for="akashic_form_email_subject"><?php _e( 'Email Subject:', 'akashic-forms' ); ?></label>
                <input type="text" name="akashic_form_email_subject" id="akashic_form_email_subject" value="<?php echo esc_attr( $email_subject ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'e.g., New Form Submission', 'akashic-forms' ); ?>" />
                <p class="description"><?php _e( 'Enter the subject line for the notification email.', 'akashic-forms' ); ?></p>
            </p>
            <p>
                <label for="akashic_form_email_message"><?php _e( 'Email Message:', 'akashic-forms' ); ?></label>
                <textarea name="akashic_form_email_message" id="akashic_form_email_message" class="large-text" rows="5" placeholder="<?php esc_attr_e( 'e.g., You have a new form submission. Details: {all_fields}', 'akashic-forms' ); ?>"><?php echo esc_textarea( $email_message ); ?></textarea>
                <p class="description"><?php _e( 'Enter the body of the notification email. Use {all_fields} to include all submitted form data.', 'akashic-forms' ); ?></p>
            </p>
            <?php
        }

        /**
         * Render the Google Drive Integration Settings meta box content.
         */
        public function render_google_drive_settings_meta_box( $post ) {
            wp_nonce_field( 'akashic_save_google_drive_settings_meta_box_data', 'akashic_google_drive_settings_meta_box_nonce' );

            $google_sheet_id = get_post_meta( $post->ID, '_akashic_form_google_sheet_id', true );
            $google_sheet_name = get_post_meta( $post->ID, '_akashic_form_google_sheet_name', true );
            ?>
            <p>
                <label for="akashic_form_google_sheet_id"><?php _e( 'Google Sheet ID:', 'akashic-forms' ); ?></label>
                <input type="text" name="akashic_form_google_sheet_id" id="akashic_form_google_sheet_id" value="<?php echo esc_attr( $google_sheet_id ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'e.g., 1B0xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'akashic-forms' ); ?>" />
                <p class="description"><?php _e( 'Enter the ID of the Google Sheet where submissions should be appended. You can find this in the sheet\'s URL.', 'akashic-forms' ); ?></p>
                <p class="description"><?php printf( __( 'Refer to this guide to get your sheet ID: %s', 'akashic-forms' ), '<a href="https://developers.google.com/sheets/api/guides/concepts#spreadsheet_id" target="_blank">https://developers.google.com/sheets/api/guides/concepts#spreadsheet_id</a>' ); ?></p>
            </p>
            <p>
                <label for="akashic_form_google_sheet_name"><?php _e( 'Google Sheet Name:', 'akashic-forms' ); ?></label>
                <input type="text" name="akashic_form_google_sheet_name" id="akashic_form_google_sheet_name" value="<?php echo esc_attr( $google_sheet_name ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'e.g., Sheet1', 'akashic-forms' ); ?>" />
                <p class="description"><?php _e( 'Enter the name of the sheet (tab) within the Google Sheet where data should be appended.', 'akashic-forms' ); ?></p>
            </p>
            <?php
        }

        /**
         * Render a single field row.
         */
        private function render_field_row( $key, $field = array() ) {
            $field_type = isset( $field['type'] ) ? $field['type'] : 'text';
            $field_label = isset( $field['label'] ) ? $field['label'] : '';
            $field_name = isset( $field['name'] ) ? $field['name'] : '';
            $field_required = isset( $field['required'] ) ? $field['required'] : '';
            $field_unique = isset( $field['unique'] ) ? $field['unique'] : '';
            $field_unique_message = isset( $field['unique_message'] ) ? $field['unique_message'] : '';
            $field_pattern = isset( $field['pattern'] ) ? $field['pattern'] : '';
            $field_validation_message = isset( $field['validation_message'] ) ? $field['validation_message'] : '';
            $field_min = isset( $field['min'] ) ? $field['min'] : '';
            $field_max = isset( $field['max'] ) ? $field['max'] : '';
            $field_step = isset( $field['step'] ) ? $field['step'] : '';
            $field_parent_fieldset = isset( $field['parent_fieldset'] ) ? $field['parent_fieldset'] : '';
            $field_show_label = isset( $field['show_label'] ) ? $field['show_label'] : '1';
            $field_placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
            $field_allowed_formats = isset( $field['allowed_formats'] ) ? $field['allowed_formats'] : '';
            $field_max_size = isset( $field['max_size'] ) ? $field['max_size'] : '';
            $field_allowed_formats_message = isset( $field['allowed_formats_message'] ) ? $field['allowed_formats_message'] : '';
            $field_max_size_message = isset( $field['max_size_message'] ) ? $field['max_size_message'] : '';
            $field_help_button_text = isset( $field['help_button_text'] ) ? $field['help_button_text'] : '?';
            $field_help_text = isset( $field['help_text'] ) ? $field['help_text'] : '';
            $field_help_modal_bg_color = isset( $field['help_modal_bg_color'] ) ? $field['help_modal_bg_color'] : '';
            ?>
            <div class="akashic-field-row" style="border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; cursor: move;" data-field-type="<?php echo esc_attr( $field_type ); ?>">
                <h4 class="akashic-field-handle"><span class="dashicons dashicons-move"></span> <?php _e( 'Field', 'akashic-forms' ); ?></h4>
                <p>
                    <label for="akashic_form_fields_<?php echo esc_attr( $key ); ?>_type"><?php _e( 'Field Type:', 'akashic-forms' ); ?></label>
                    <select name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][type]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_type" class="akashic-field-type-select">
                        <option value="text" <?php selected( $field_type, 'text' ); ?>><?php _e( 'Text', 'akashic-forms' ); ?></option>
                        <option value="email" <?php selected( $field_type, 'email' ); ?>><?php _e( 'Email', 'akashic-forms' ); ?></option>
                        <option value="password" <?php selected( $field_type, 'password' ); ?>><?php _e( 'Password', 'akashic-forms' ); ?></option>
                        <option value="textarea" <?php selected( $field_type, 'textarea' ); ?>><?php _e( 'Textarea', 'akashic-forms' ); ?></option>
                        <option value="file" <?php selected( $field_type, 'file' ); ?>><?php _e( 'File Upload', 'akashic-forms' ); ?></option>
                        <option value="date" <?php selected( $field_type, 'date' ); ?>><?php _e( 'Date', 'akashic-forms' ); ?></option>
                        <option value="checkbox" <?php selected( $field_type, 'checkbox' ); ?>><?php _e( 'Checkbox (Multiple)', 'akashic-forms' ); ?></option>
                        <option value="checkbox_single" <?php selected( $field_type, 'checkbox_single' ); ?>><?php _e( 'Checkbox (Single)', 'akashic-forms' ); ?></option>
                        <option value="select" <?php selected( $field_type, 'select' ); ?>><?php _e( 'Select', 'akashic-forms' ); ?></option>
                        <option value="radio" <?php selected( $field_type, 'radio' ); ?>><?php _e( 'Radio', 'akashic-forms' ); ?></option>
                        <option value="number" <?php selected( $field_type, 'number' ); ?>><?php _e( 'Number', 'akashic-forms' ); ?></option>
                        <option value="url" <?php selected( $field_type, 'url' ); ?>><?php _e( 'URL', 'akashic-forms' ); ?></option>
                        <option value="tel" <?php selected( $field_type, 'tel' ); ?>><?php _e( 'Telephone', 'akashic-forms' ); ?></option>
                        <option value="search" <?php selected( $field_type, 'search' ); ?>><?php _e( 'Search', 'akashic-forms' ); ?></option>
                        <option value="time" <?php selected( $field_type, 'time' ); ?>><?php _e( 'Time', 'akashic-forms' ); ?></option>
                        <option value="hidden" <?php selected( $field_type, 'hidden' ); ?>><?php _e( 'Hidden', 'akashic-forms' ); ?></option>
                        <option value="color" <?php selected( $field_type, 'color' ); ?>><?php _e( 'Color', 'akashic-forms' ); ?></option>
                        <option value="range" <?php selected( $field_type, 'range' ); ?>><?php _e( 'Range', 'akashic-forms' ); ?></option>
                        <option value="datalist" <?php selected( $field_type, 'datalist' ); ?>><?php _e( 'Datalist', 'akashic-forms' ); ?></option>
                        <option value="output" <?php selected( $field_type, 'output' ); ?>><?php _e( 'Output', 'akashic-forms' ); ?></option>
                        <option value="fieldset" <?php selected( $field_type, 'fieldset' ); ?>><?php _e( 'Fieldset', 'akashic-forms' ); ?></option>
                    </select>
                </p>
                <p>
                    <label for="akashic_form_fields_<?php echo esc_attr( $key ); ?>_label"><?php _e( 'Field Label:', 'akashic-forms' ); ?></label>
                    <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][label]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_label" value="<?php echo esc_attr( $field_label ); ?>" class="large-text" />
                </p>
                <p>
                    <input type="checkbox" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][show_label]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_show_label" value="1" <?php checked( $field_show_label, '1' ); ?> />
                    <label for="akashic_form_fields_<?php echo esc_attr( $key ); ?>_show_label"><?php _e( 'Show Label', 'akashic-forms' ); ?></label>
                </p>
                <p>
                    <label for="akashic_form_fields_<?php echo esc_attr( $key ); ?>_placeholder"><?php _e( 'Placeholder:', 'akashic-forms' ); ?></label>
                    <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][placeholder]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_placeholder" value="<?php echo esc_attr( $field_placeholder ); ?>" class="large-text" />
                </p>
                <p>
                    <label for="akashic_form_fields_<?php echo esc_attr( $key ); ?>_name"><?php _e( 'Field Name (unique):', 'akashic-forms' ); ?></label>
                    <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][name]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_name" value="<?php echo esc_attr( $field_name ); ?>" class="large-text" />
                </p>
                <p class="akashic-field-setting akashic-field-setting-required">
                    <input type="checkbox" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][required]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_required" value="1" <?php checked( $field_required, '1' ); ?> />
                    <label for="akashic_form_fields_<?php echo esc_attr( $key ); ?>_required"><?php _e( 'Required', 'akashic-forms' ); ?></label>

                    <input type="checkbox" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][unique]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_unique" value="1" <?php checked( $field_unique, '1' ); ?> class="akashic-field-unique-checkbox" />
                    <label for="akashic_form_fields_<?php echo esc_attr( $key ); ?>_unique"><?php _e( 'Unique', 'akashic-forms' ); ?></label>
                </p>
                <p class="akashic-field-setting akashic-field-setting-unique-message" style="display: <?php echo $field_unique ? 'block' : 'none'; ?>;">
                    <label for="akashic_form_fields_<?php echo esc_attr( $key ); ?>_unique_message"><?php _e( 'Unique Validation Message:', 'akashic-forms' ); ?></label>
                    <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][unique_message]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_unique_message" value="<?php echo esc_attr( $field_unique_message ); ?>" class="large-text" placeholder="<?php _e( 'This value has already been entered', 'akashic-forms' ); ?>" />
                </p>
                <div class="akashic-field-setting akashic-field-setting-validation">
                    <p>
                        <label for="akashic_form_fields[<?php echo esc_attr( $key ); ?>][pattern]"><?php _e( 'Validation Pattern (Regex):', 'akashic-forms' ); ?></label>
                        <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][pattern]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_pattern" value="<?php echo esc_attr( $field_pattern ); ?>" class="large-text" />
                        <p class="description"><?php _e( 'Enter a regular expression for client-side validation (e.g., ^\\d{5}$ for 5 digits). Leave empty for no pattern validation.', 'akashic-forms' ); ?></p>
                    </p>
                    <p>
                        <label for="akashic_form_fields[<?php echo esc_attr( $key ); ?>][validation_message]"><?php _e( 'Validation Message:', 'akashic-forms' ); ?></label>
                        <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][validation_message]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_validation_message" value="<?php echo esc_attr( $field_validation_message ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'e.g., Please enter a valid value.', 'akashic-forms' ); ?>" />
                        <p class="description"><?php _e( 'Custom message to display if validation fails.', 'akashic-forms' ); ?></p>
                    </p>
                </div>
                <div class="akashic-field-setting akashic-field-setting-min-max-step">
                    <p>
                        <label for="akashic_form_fields[<?php echo esc_attr( $key ); ?>][min]"><?php _e( 'Min Value/Length:', 'akashic-forms' ); ?></label>
                        <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][min]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_min" value="<?php echo esc_attr( $field_min ); ?>" class="small-text" />
                    </p>
                    <p>
                        <label for="akashic_form_fields[<?php echo esc_attr( $key ); ?>][max]"><?php _e( 'Max Value/Length:', 'akashic-forms' ); ?></label>
                        <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][max]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_max" value="<?php echo esc_attr( $field_max ); ?>" class="small-text" />
                    </p>
                    <p>
                        <label for="akashic_form_fields[<?php echo esc_attr( $key ); ?>][step]"><?php _e( 'Step (for Number/Range):', 'akashic-forms' ); ?></label>
                        <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][step]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_step" value="<?php echo esc_attr( $field_step ); ?>" class="small-text" />
                    </p>
                </div>
                <p class="akashic-field-setting akashic-field-setting-parent-fieldset">
                    <label for="akashic_form_fields_<?php echo esc_attr( $key ); ?>_parent_fieldset"><?php _e( 'Parent Fieldset Name:', 'akashic-forms' ); ?></label>
                    <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][parent_fieldset]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_parent_fieldset" value="<?php echo esc_attr( $field_parent_fieldset ); ?>" class="large-text" />
                    <p class="description"><?php _e( 'Enter the unique name of the fieldset this field belongs to. Leave empty if not part of a fieldset.', 'akashic-forms' ); ?></p>
                </p>

                <div class="akashic-field-setting akashic-field-setting-file">
                    <p>
                        <label for="akashic_form_fields[<?php echo esc_attr( $key ); ?>][allowed_formats]"><?php _e( 'Allowed Formats (comma-separated):', 'akashic-forms' ); ?></label>
                        <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][allowed_formats]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_allowed_formats" value="<?php echo esc_attr( $field_allowed_formats ); ?>" class="large-text" />
                        <p class="description"><?php _e( 'e.g., jpg, png, pdf', 'akashic-forms' ); ?></p>
                    </p>
                    <p>
                        <label for="akashic_form_fields[<?php echo esc_attr( $key ); ?>][allowed_formats_message]"><?php _e( 'Allowed Formats Error Message:', 'akashic-forms' ); ?></label>
                        <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][allowed_formats_message]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_allowed_formats_message" value="<?php echo esc_attr( $field_allowed_formats_message ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'e.g., Invalid file format. Please upload a JPG, PNG, or PDF.', 'akashic-forms' ); ?>" />
                    </p>
                    <p>
                        <label for="akashic_form_fields[<?php echo esc_attr( $key ); ?>][max_size]"><?php _e( 'Max File Size (in MB):', 'akashic-forms' ); ?></label>
                        <input type="number" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][max_size]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_max_size" value="<?php echo esc_attr( $field_max_size ); ?>" class="small-text" />
                    </p>
                    <p>
                        <label for="akashic_form_fields[<?php echo esc_attr( $key ); ?>][max_size_message]"><?php _e( 'Max File Size Error Message:', 'akashic-forms' ); ?></label>
                        <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][max_size_message]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_max_size_message" value="<?php echo esc_attr( $field_max_size_message ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'e.g., File size exceeds the maximum allowed limit.', 'akashic-forms' ); ?>" />
                    </p>
                </div>

                <div class="akashic-field-help-settings" style="border: 1px dashed #ccc; padding: 10px; margin-top: 10px;">
                    <h4><?php _e( 'Help Modal Settings', 'akashic-forms' ); ?></h4>
                    <p>
                        <label for="akashic_form_fields_<?php echo esc_attr( $key ); ?>_help_button_text"><?php _e( 'Help Button Text:', 'akashic-forms' ); ?></label>
                        <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][help_button_text]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_help_button_text" value="<?php echo esc_attr( $field_help_button_text ); ?>" class="large-text" />
                        <p class="description"><?php _e( 'Enter the text or icon for the help button. Default is "?"', 'akashic-forms' ); ?></p>
                    </p>
                    <p>
                        <label for="akashic_form_fields_<?php echo esc_attr( $key ); ?>_help_modal_bg_color"><?php _e( 'Help Modal Background Color:', 'akashic-forms' ); ?></label>
                        <input type="text" name="akashic_form_fields[<?php echo esc_attr( $key ); ?>][help_modal_bg_color]" id="akashic_form_fields_<?php echo esc_attr( $key ); ?>_help_modal_bg_color" value="<?php echo esc_attr( $field_help_modal_bg_color ); ?>" class="akashic-color-picker" />
                    </p>
                    <div>
                        <label for="akashic_form_fields_<?php echo esc_attr( $key ); ?>_help_text"><?php _e( 'Help Modal Content:', 'akashic-forms' ); ?></label>
                        <?php
                        wp_editor(
                            $field_help_text,
                            'akashic_form_fields_' . esc_attr( $key ) . '_help_text',
                            array(
                                'textarea_name' => 'akashic_form_fields[' . esc_attr( $key ) . '][help_text]',
                                'media_buttons' => true,
                                'textarea_rows' => 5,
                            )
                        );
                        ?>
                    </div>
                </div>

                <p>
                    <button type="button" class="button akashic-remove-field"><?php _e( 'Remove Field', 'akashic-forms' ); ?></button>
                </p>

                <div class="akashic-field-setting akashic-field-setting-options">
                    <div class="akashic-field-options" style="border: 1px dashed #ccc; padding: 10px; margin-top: 10px;">
                        <h4><?php _e( 'Options', 'akashic-forms' ); ?></h4>
                        <div class="akashic-field-options-wrapper">
                            <?php
                            $field_options = isset( $field['options'] ) ? $field['options'] : array();
                            if ( ! empty( $field_options ) ) {
                                foreach ( $field_options as $option_key => $option ) {
                                    $this->render_field_option_row( $key, $option_key, $option );
                                }
                            }
                            ?>
                        </div>
                        <p>
                            <button type="button" class="button akashic-add-option" data-field-key="<?php echo esc_attr( $key ); ?>"><?php _e( 'Add Option', 'akashic-forms' ); ?></button>
                        </p>
                    </div>
                </div>
            </div>
            <?php
        }

        /**
         * Render a single field option row.
         */
        private function render_field_option_row( $field_key, $option_key, $option = array() ) {
            $option_value = isset( $option['value'] ) ? $option['value'] : '';
            $option_label = isset( $option['label'] ) ? $option['label'] : '';
            ?>
            <div class="akashic-field-option-row" style="margin-bottom: 5px;">
                <label><?php _e( 'Value:', 'akashic-forms' ); ?> <input type="text" name="akashic_form_fields[<?php echo esc_attr( $field_key ); ?>][options][<?php echo esc_attr( $option_key ); ?>][value]" value="<?php echo esc_attr( $option_value ); ?>" /></label>
                <label><?php _e( 'Label:', 'akashic-forms' ); ?> <input type="text" name="akashic_form_fields[<?php echo esc_attr( $field_key ); ?>][options][<?php echo esc_attr( $option_key ); ?>][label]" value="<?php echo esc_attr( $option_label ); ?>" /></label>
                <button type="button" class="button akashic-remove-option">x</button>
            </div>
            <?php
        }

        /**
         * Save the Form Fields meta box data.
         */
        public function save_form_meta_box_data( $post_id ) {
            // Save form fields.
            if ( ! isset( $_POST['akashic_form_fields_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['akashic_form_fields_meta_box_nonce'], 'akashic_save_form_fields_meta_box_data' ) ) {
                return;
            }

            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }

            if ( isset( $_POST['akashic_form_fields'] ) ) {
                $form_fields = array();
                foreach ( $_POST['akashic_form_fields'] as $field_key => $field ) {
                    $new_field = array(
                        'type'     => sanitize_text_field( $field['type'] ),
                        'label'    => sanitize_text_field( $field['label'] ),
                        'name'     => sanitize_key( $field['name'] ),
                        'required' => isset( $field['required'] ) ? '1' : '0',
                        'unique' => isset( $field['unique'] ) ? '1' : '0',
                        'unique_message' => isset( $field['unique_message'] ) ? sanitize_text_field( $field['unique_message'] ) : '',
                        'pattern'  => sanitize_text_field( $field['pattern'] ),
                        'validation_message' => sanitize_text_field( $field['validation_message'] ),
                        'min'      => sanitize_text_field( $field['min'] ),
                        'max'      => sanitize_text_field( $field['max'] ),
                        'step'     => sanitize_text_field( $field['step'] ),
                        'parent_fieldset' => sanitize_text_field( $field['parent_fieldset'] ),
                        'show_label' => isset( $field['show_label'] ) ? '1' : '0',
                        'placeholder' => sanitize_text_field( $field['placeholder'] ),
                        'allowed_formats' => sanitize_text_field( $field['allowed_formats'] ),
                        'max_size' => sanitize_text_field( $field['max_size'] ),
                        'allowed_formats_message' => sanitize_text_field( $field['allowed_formats_message'] ),
                        'max_size_message' => sanitize_text_field( $field['max_size_message'] ),
                        'help_button_text' => isset( $field['help_button_text'] ) ? sanitize_text_field( $field['help_button_text'] ) : '',
                        'help_text' => isset( $field['help_text'] ) ? wp_kses_post( $field['help_text'] ) : '',
                        'help_modal_bg_color' => isset( $field['help_modal_bg_color'] ) ? sanitize_hex_color( $field['help_modal_bg_color'] ) : '',
                    );

                    // Save options for select, radio, checkbox, and datalist fields.
                    if ( in_array( $field['type'], array( 'select', 'radio', 'checkbox', 'datalist' ) ) && isset( $_POST['akashic_form_fields'][ $field_key ]['options'] ) ) {
                        $options = array();
                        foreach ( $_POST['akashic_form_fields'][ $field_key ]['options'] as $option_data ) {
                            $options[] = array(
                                'value' => sanitize_text_field( $option_data['value'] ),
                                'label' => sanitize_text_field( $option_data['label'] ),
                            );
                        }
                        $new_field['options'] = $options;
                    }
                    $form_fields[] = $new_field;
                }
                update_post_meta( $post_id, '_akashic_form_fields', $form_fields );
            } else {
                delete_post_meta( $post_id, '_akashic_form_fields' );
            }

            // Save email settings.
            if ( ! isset( $_POST['akashic_email_settings_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['akashic_email_settings_meta_box_nonce'], 'akashic_save_email_settings_meta_box_data' ) ) {
                return;
            }

            $recipient_email = isset( $_POST['akashic_form_email_recipient'] ) ? sanitize_email( $_POST['akashic_form_email_recipient'] ) : '';
            $email_subject = isset( $_POST['akashic_form_email_subject'] ) ? sanitize_text_field( $_POST['akashic_form_email_subject'] ) : '';
            $email_message = isset( $_POST['akashic_form_email_message'] ) ? sanitize_textarea_field( $_POST['akashic_form_email_message'] ) : '';

            update_post_meta( $post_id, '_akashic_form_email_recipient', $recipient_email );
            update_post_meta( $post_id, '_akashic_form_email_subject', $email_subject );
            update_post_meta( $post_id, '_akashic_form_email_message', $email_message );

            // Save Google Drive settings.
            if ( ! isset( $_POST['akashic_google_drive_settings_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['akashic_google_drive_settings_meta_box_nonce'], 'akashic_save_google_drive_settings_meta_box_data' ) ) {
                return;
            }

            $google_sheet_id = isset( $_POST['akashic_form_google_sheet_id'] ) ? sanitize_text_field( $_POST['akashic_form_google_sheet_id'] ) : '';
            $google_sheet_name = isset( $_POST['akashic_form_google_sheet_name'] ) ? sanitize_text_field( $_POST['akashic_form_google_sheet_name'] ) : '';

            update_post_meta( $post_id, '_akashic_form_google_sheet_id', $google_sheet_id );
            update_post_meta( $post_id, '_akashic_form_google_sheet_name', $google_sheet_name );

            // Save submission settings.
            if ( ! isset( $_POST['akashic_submission_settings_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['akashic_submission_settings_meta_box_nonce'], 'akashic_save_submission_settings_meta_box_data' ) ) {
                return;
            }

            $submission_action = isset( $_POST['akashic_form_submission_action'] ) ? sanitize_text_field( $_POST['akashic_form_submission_action'] ) : 'redirect';
            $redirect_url = isset( $_POST['akashic_form_redirect_url'] ) ? esc_url_raw( $_POST['akashic_form_redirect_url'] ) : '';
            $form_message = isset( $_POST['akashic_form_message'] ) ? wp_kses_post( $_POST['akashic_form_message'] ) : '';
            $modal_message = isset( $_POST['akashic_form_modal_message'] ) ? wp_kses_post( $_POST['akashic_form_modal_message'] ) : '';
            $submit_button_text = isset( $_POST['akashic_form_submit_button_text'] ) ? sanitize_text_field( $_POST['akashic_form_submit_button_text'] ) : '';
            $submitting_button_text = isset( $_POST['akashic_form_submitting_button_text'] ) ? sanitize_text_field( $_POST['akashic_form_submitting_button_text'] ) : '';

            update_post_meta( $post_id, '_akashic_form_submission_action', $submission_action );
            update_post_meta( $post_id, '_akashic_form_redirect_url', $redirect_url );
            update_post_meta( $post_id, '_akashic_form_message', $form_message );
            update_post_meta( $post_id, '_akashic_form_modal_message', $modal_message );
            update_post_meta( $post_id, '_akashic_form_submit_button_text', $submit_button_text );
            update_post_meta( $post_id, '_akashic_form_submitting_button_text', $submitting_button_text );
        }

    }

}

new Akashic_Forms_Metabox();