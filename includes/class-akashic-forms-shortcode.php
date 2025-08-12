<?php
/**
 * Shortcode for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Akashic_Forms_Shortcode' ) ) {

    class Akashic_Forms_Shortcode {

        /**
         * Constructor.
         */
        public function __construct() {
            add_shortcode( 'akashic_form', array( $this, 'render_form_shortcode' ) );
        }

        /**
         * Render the form shortcode.
         *
         * @param array $atts Shortcode attributes.
         * @return string HTML output for the form.
         */
        public function render_form_shortcode( $atts ) {
            $atts = shortcode_atts( array(
                'id' => 0,
            ), $atts, 'akashic_form' );

            $form_id = absint( $atts['id'] );

            if ( ! $form_id ) {
                return '<p>' . __( 'Form ID not specified.', 'akashic-forms' ) . '</p>';
            }

            $form_post = get_post( $form_id );

            if ( ! $form_post || 'akashic_forms' !== $form_post->post_type ) {
                return '<p>' . __( 'Form not found.', 'akashic-forms' ) . '</p>';
            }

            $form_fields = get_post_meta( $form_id, '_akashic_form_fields', true );

            if ( empty( $form_fields ) ) {
                return '<p>' . __( 'No fields configured for this form.', 'akashic-forms' ) . '</p>';
            }

            $submission_action = get_post_meta( $form_id, '_akashic_form_submission_action', true );
            $form_message = get_post_meta( $form_id, '_akashic_form_message', true );
            $modal_message = get_post_meta( $form_id, '_akashic_form_modal_message', true );
            $submit_button_text = get_post_meta( $form_id, '_akashic_form_submit_button_text', true );
            if ( empty( $submit_button_text ) ) {
                $submit_button_text = __( 'Submit', 'akashic-forms' );
            }
            $submitting_button_text = get_post_meta( $form_id, '_akashic_form_submitting_button_text', true );
            if ( empty( $submitting_button_text ) ) {
                $submitting_button_text = __( 'Submitting...', 'akashic-forms' );
            }

            ob_start();
            ?>
            <div id="akashic-form-container-<?php echo esc_attr( $form_id ); ?>">
                <form action="" method="post" class="akashic-form" enctype="multipart/form-data" data-form-id="<?php echo esc_attr( $form_id ); ?>" data-submission-action="<?php echo esc_attr( $submission_action ); ?>">
                    <?php wp_nonce_field( 'akashic_submit_form', 'akashic_form_nonce' ); ?>
                    <input type="hidden" name="akashic_form_id" value="<?php echo esc_attr( $form_id ); ?>" />
                    <input type="hidden" name="action" value="akashic_form_submit" />
                    <?php
                    foreach ( $form_fields as $field_key => $field ) {
                        $field_type = isset( $field['type'] ) ? $field['type'] : 'text';
                        $field_label = isset( $field['label'] ) ? $field['label'] : '';
                        $field_name = isset( $field['name'] ) ? $field['name'] : '';
                        $field_required = isset( $field['required'] ) && '1' === $field['required'] ? 'required' : '';
                        $field_pattern = isset( $field['pattern'] ) ? $field['pattern'] : '';
                        $field_validation_message = isset( $field['validation_message'] ) ? $field['validation_message'] : '';
                        $field_min = isset( $field['min'] ) ? $field['min'] : '';
                        $field_max = isset( $field['max'] ) ? $field['max'] : '';
                        $field_step = isset( $field['step'] ) ? $field['step'] : '';
                        $field_options = isset( $field['options'] ) ? $field['options'] : array();
                        $field_parent_fieldset = isset( $field['parent_fieldset'] ) ? $field['parent_fieldset'] : '';
                        $field_show_label = isset( $field['show_label'] ) && '1' === $field['show_label'];
                        $field_placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';

                        $html_attributes = '';
                        if ( ! empty( $field_required ) ) {
                            $html_attributes .= ' required';
                        }
                        if ( ! empty( $field_pattern ) ) {
                            $html_attributes .= ' pattern="' . esc_attr( $field_pattern ) . '"';
                        }
                        if ( ! empty( $field_min ) ) {
                            $html_attributes .= ' min="' . esc_attr( $field_min ) . '"';
                        }
                        if ( ! empty( $field_max ) ) {
                            $html_attributes .= ' max="' . esc_attr( $field_max ) . '"';
                        }
                        if ( ! empty( $field_step ) ) {
                            $html_attributes .= ' step="' . esc_attr( $field_step ) . '"';
                        }
                        if ( ! empty( $field_validation_message ) ) {
                            $html_attributes .= ' oninvalid="this.setCustomValidity(\''. esc_attr( $field_validation_message ) .'\')" oninput="this.setCustomValidity(\'\')"';
                        }
                        if ( ! empty( $field_placeholder ) ) {
                            $html_attributes .= ' placeholder="' . esc_attr( $field_placeholder ) . '"';
                        }

                        if ( empty( $field_name ) ) {
                            continue;
                        }
                        ?>
                        <div class="field-container field-container--<?php echo esc_attr( $field_name ); ?> <?php echo esc_attr( $field_type ); ?> <?php echo esc_attr( $field_parent_fieldset ); ?>">
                        <?php if ( $field_show_label && 'hidden' !== $field_type ) : ?>
                            <label for="<?php echo esc_attr( $field_name ); ?>"><?php echo esc_html( $field_label ); ?></label>
                        <?php endif; ?>
                        <?php
                        switch ( $field_type ) {
                            case 'textarea':
                                ?>
                                <textarea name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" <?php echo $html_attributes; ?>></textarea>
                                <?php
                                break;
                            case 'file':
                                ?>
                                <input type="file" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" <?php echo $html_attributes; ?> />
                                <?php
                                break;
                            case 'select':
                                ?>
                                <select name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" <?php echo $html_attributes; ?> >
                                    <?php foreach ( $field_options as $option ) : ?>
                                        <option value="<?php echo esc_attr( $option['value'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php
                                break;
                            case 'radio':
                                ?>
                                <?php foreach ( $field_options as $option ) : ?>
                                    <input type="radio" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name . '_' . sanitize_title( $option['value'] ) ); ?>" value="<?php echo esc_attr( $option['value'] ); ?>" <?php echo $html_attributes; ?> />
                                    <label for="<?php echo esc_attr( $field_name . '_' . sanitize_title( $option['value'] ) ); ?>"><?php echo esc_html( $option['label'] ); ?></label><br/>
                                <?php endforeach; ?>
                                <?php
                                break;
                            case 'checkbox':
                                ?>
                                <?php foreach ( $field_options as $option ) : ?>
                                    <input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>[]" id="<?php echo esc_attr( $field_name . '_' . sanitize_title( $option['value'] ) ); ?>" value="<?php echo esc_attr( $option['value'] ); ?>" <?php echo $html_attributes; ?> />
                                    <label for="<?php echo esc_attr( $field_name . '_' . sanitize_title( $option['value'] ) ); ?>"><?php echo esc_html( $option['label'] ); ?></label><br/>
                                <?php endforeach; ?>
                                <?php
                                break;
                            case 'checkbox_single':
                                ?>
                                <input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="0" />
                                <input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" value="1" <?php echo $html_attributes; ?> />
                                <?php
                                break;
                            case 'datalist':
                                ?>
                                <input type="text" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" list="<?php echo esc_attr( $field_name ); ?>_list" <?php echo $html_attributes; ?> />
                                <datalist id="<?php echo esc_attr( $field_name ); ?>_list">
                                    <?php foreach ( $field_options as $option ) : ?>
                                        <option value="<?php echo esc_attr( $option['value'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <?php
                                break;
                            case 'hidden':
                                ?>
                                <input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" value="" <?php echo $html_attributes; ?> />
                                <?php
                                break;
                            case 'output':
                                ?>
                                <output name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" <?php echo $html_attributes; ?>></output>
                                <?php
                                break;
                            case 'fieldset':
                                // Fieldsets are handled by grouping fields, not rendered directly here.
                                break;
                            default:
                                ?>
                                <input type="<?php echo esc_attr( $field_type ); ?>" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" <?php echo $html_attributes; ?> />
                                <?php
                                break;
                        }
                        ?>
                        </div>
                        <?php
                    }
                    ?>
                    <p>
                        <input type="submit" name="akashic_form_submit" value="<?php echo esc_attr( $submit_button_text ); ?>" data-submitting-text="<?php echo esc_attr( $submitting_button_text ); ?>" />
                    </p>
                </form>
                <div class="akashic-form-message" style="display: none;"><?php echo wp_kses_post( $form_message ); ?></div>
            </div>
            <div id="akashic-form-modal-<?php echo esc_attr( $form_id ); ?>" class="akashic-form-modal" style="display: none;">
                <div class="akashic-form-modal-content">
                    <span class="akashic-form-modal-close">&times;</span>
                    <div class="akashic-form-modal-body"><?php echo wp_kses_post( $modal_message ); ?></div>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

    }

}

new Akashic_Forms_Shortcode();