<?php

/**
 * Shortcode for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('Akashic_Forms_Shortcode')) {

    class Akashic_Forms_Shortcode
    {

        /**
         * Constructor.
         */
        public function __construct()
        {
            add_shortcode('akashic_form', array($this, 'render_form_shortcode'));
        }

        /**
         * Render the form shortcode.
         *
         * @param array $atts Shortcode attributes.
         * @return string HTML output for the form.
         */
        public function render_form_shortcode($atts)
        {
            $atts = shortcode_atts(array(
                'id' => 0,
            ), $atts, 'akashic_form');

            $form_id = absint($atts['id']);

            if (! $form_id) {
                return '<p>' . __('Form ID not specified.', 'akashic-forms') . '</p>';
            }

            $form_post = get_post($form_id);

            if (! $form_post || 'akashic_forms' !== $form_post->post_type) {
                return '<p>' . __('Form not found.', 'akashic-forms') . '</p>';
            }

            $form_fields = get_post_meta($form_id, '_akashic_form_fields', true);

            if (empty($form_fields)) {
                return '<p>' . __('No fields configured for this form.', 'akashic-forms') . '</p>';
            }

            $submission_action = get_post_meta($form_id, '_akashic_form_submission_action', true);
            $redirect_url = get_post_meta($form_id, '_akashic_form_redirect_url', true);
            $form_message = get_post_meta($form_id, '_akashic_form_message', true);
            $modal_message = get_post_meta($form_id, '_akashic_form_modal_message', true);
            $submit_button_text = get_post_meta($form_id, '_akashic_form_submit_button_text', true);
            if (empty($submit_button_text)) {
                $submit_button_text = __('Submit', 'akashic-forms');
            }
            $submitting_button_text = get_post_meta($form_id, '_akashic_form_submitting_button_text', true);
            if (empty($submitting_button_text)) {
                $submitting_button_text = __('Submitting...', 'akashic-forms');
            }

            ob_start();
?>
            <div id="akashic-form-container-<?php echo esc_attr($form_id); ?>">
                <form action="" method="post" class="akashic-form" enctype="multipart/form-data" data-form-id="<?php echo esc_attr($form_id); ?>" data-submission-action="<?php echo esc_attr($submission_action); ?>" data-redirect-url="<?php echo esc_url($redirect_url); ?>" novalidate>
                    <?php wp_nonce_field('akashic_submit_form', 'akashic_form_nonce'); ?>
                    <input type="hidden" name="akashic_form_id" value="<?php echo esc_attr($form_id); ?>" />
                    <input type="hidden" name="action" value="akashic_form_submit" />
                    <?php
                    foreach ($form_fields as $field_key => $field) {
                        $field_type = isset($field['type']) ? $field['type'] : 'text';
                        $field_label = isset($field['label']) ? $field['label'] : '';
                        $field_name = isset($field['name']) ? $field['name'] : '';
                        $field_required = isset($field['required']) && '1' === $field['required'] ? 'required' : '';
                        $field_pattern = isset($field['pattern']) ? $field['pattern'] : '';
                        $field_validation_message = isset($field['validation_message']) ? $field['validation_message'] : '';
                        $field_min = isset($field['min']) ? $field['min'] : '';
                        $field_max = isset($field['max']) ? $field['max'] : '';
                        $field_step = isset($field['step']) ? $field['step'] : '';
                        $field_options = isset($field['options']) ? $field['options'] : array();
                        $field_parent_fieldset = isset($field['parent_fieldset']) ? $field['parent_fieldset'] : '';
                        $field_show_label = isset($field['show_label']) && '1' === $field['show_label'];
                        $field_placeholder = isset($field['placeholder']) ? $field['placeholder'] : '';
                        $field_multiple = isset($field['multiple']) ? $field['multiple'] : '';
                        
                        $field_allowed_formats = isset($field['allowed_formats']) ? $field['allowed_formats'] : '';
                        $field_help_button_text = isset($field['help_button_text']) && ! empty($field['help_button_text']) ? $field['help_button_text'] : '?';
                        $field_help_text = isset($field['help_text']) ? $field['help_text'] : '';
                        $field_help_modal_bg_color = isset($field['help_modal_bg_color']) ? $field['help_modal_bg_color'] : '';

                        $html_attributes = '';
                        $html_attributes .= ' data-label="' . esc_attr($field_label) . '"';
                        if (! empty($field_required)) {
                            $html_attributes .= ' data-required="1"';
                        }
                        if (! empty($field_pattern)) {
                            $html_attributes .= ' data-pattern="' . esc_attr($field_pattern) . '"';
                        }
                        if (! empty($field_validation_message)) {
                            $html_attributes .= ' data-validation-message="' . esc_attr($field_validation_message) . '"';
                        }
                        if (! empty($field_min)) {
                            $html_attributes .= ' min="' . esc_attr($field_min) . '"';
                        }
                        if (! empty($field_max)) {
                            $html_attributes .= ' max="' . esc_attr($field_max) . '"';
                        }
                        if (! empty($field_step)) {
                            $html_attributes .= ' step="' . esc_attr($field_step) . '"';
                        }
                        if (! empty($field_placeholder)) {
                            $html_attributes .= ' placeholder="' . esc_attr($field_placeholder) . '"';
                        }
                        if (! empty($field_multiple)) {
                            $html_attributes .= ' multiple="' . esc_attr($field_multiple) . '"';
                        }
                        if (! empty($field_allowed_formats)) {
                            // Limpiamos espacios en blanco accidentales
                            $limpio = str_replace(' ', '', $field_allowed_formats);

                            // Agregamos el punto inicial y reemplazamos las comas por ",."
                            $accept_format = '.' . str_replace(',', ',.', $limpio);
                            $html_attributes .= ' accept="' . esc_attr($accept_format) . '"';
                        }

                        if (empty($field_name)) {
                            continue;
                        }

                        $modal_id = 'akashic-help-modal-' . esc_attr($form_id) . '-' . esc_attr($field_name);
                    ?>
                        <div class="field-container field-container--<?php echo esc_attr($field_name); ?> <?php echo esc_attr($field_type); ?> <?php echo esc_attr($field_parent_fieldset); ?>">
                            <div class="label-wrapper">
                                <?php if ($field_show_label && 'hidden' !== $field_type) : ?>
                                    <label for="<?php echo esc_attr($field_name); ?>"><?php echo esc_html($field_label); ?>
                                        <?php if (! empty($field_help_text)) : ?>
                                            <button type="button" class="akashic-help-button" data-modal-id="<?php echo $modal_id; ?>"><?php echo esc_html($field_help_button_text); ?></button>
                                        <?php endif; ?>
                                    </label>
                                <?php endif; ?>

                            </div>
                            <?php
                            switch ($field_type) {
                                case 'textarea':
                            ?>
                                    <textarea name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_name); ?>" <?php echo $html_attributes; ?>></textarea>
                                <?php
                                    break;
                                case 'file':
                                ?>
                                    <div class="sardimar-uploader-wrapper">
                                        <div class="drop-zone" id="drop-zone-<?php echo esc_attr($field_name); ?>">
                                            <div class="drop-zone-content">
                                                <div class="upload-icon">
                                                    <svg viewBox="0 0 24 24" width="40" height="40" stroke="#3296d4" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                                </div>
                                                <p class="main-text">Arrastra o <span class="blue-text">Sube archivos</span></p>
                                                <p class="sub-text" id="display-<?php echo esc_attr($field_name); ?>">Tama&ntilde;o m&aacute;ximo de 10 MB</p>
                                            </div>
                                            <input type="file" name="<?php echo esc_attr($field_name); ?>[]" class="real-input" id="input-<?php echo esc_attr($field_name); ?>" <?php echo $html_attributes; ?>/>
                                        </div>
                                        <p class="formatos-validos">Formatos v&aacute;lidos: jpg, png y pdf.</p>
                                    </div>
                                    <script>
                                    (function() {
                                        const fieldId = "<?php echo esc_js($field_name); ?>";
                                        const input = document.getElementById('input-' + fieldId);
                                        const display = document.getElementById('display-' + fieldId);
                                        const zone = document.getElementById('drop-zone-' + fieldId);

                                        if (input && display && zone) {
                                            
                                            // Funcion central para actualizar el texto
                                            const updateDisplay = (files) => {
                                                if (files.length === 1) {
                                                    display.innerText = "Archivo: " + files[0].name;
                                                } else if (files.length > 1) {
                                                    display.innerText = files.length + " archivos seleccionados";
                                                }
                                                display.style.color = "#004a99";
                                                display.style.fontWeight = "bold";
                                            };

                                            // Escuchar el cambio tradicional (clic y seleccionar)
                                            input.addEventListener('change', function() {
                                                if (this.files) updateDisplay(this.files);
                                            });

                                            // Manejo de Arrastrar y Soltar
                                            ['dragover', 'dragleave', 'drop'].forEach(eventName => {
                                                zone.addEventListener(eventName, e => {
                                                    e.preventDefault();
                                                    e.stopPropagation();
                                                    
                                                    if (eventName === 'dragover') {
                                                        zone.classList.add('drag-over');
                                                    } else {
                                                        zone.classList.remove('drag-over');
                                                    }

                                                    if (eventName === 'drop') {
                                                        const droppedFiles = e.dataTransfer.files;
                                                        if (droppedFiles.length > 0) {
                                                            input.files = droppedFiles; 
                                                            updateDisplay(droppedFiles);
                                                            input.dispatchEvent(new Event('change'));
                                                        }
                                                    }
                                                });
                                            });
                                        }
                                    })();
                                    </script>
                                <?php
                                    break;
                                case 'select':
                                ?>
                                    <select name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_name); ?>" <?php echo $html_attributes; ?>>
                                        <?php foreach ($field_options as $option) : ?>
                                            <option value="<?php echo esc_attr($option['value']); ?>"><?php echo esc_html($option['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php
                                    break;
                                case 'radio':
                                ?>
                                    <?php foreach ($field_options as $option) : ?>
                                        <input type="radio" name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_name . '_' . sanitize_title($option['value'])); ?>" value="<?php echo esc_attr($option['value']); ?>" <?php echo $html_attributes; ?> />
                                        <label for="<?php echo esc_attr($field_name . '_' . sanitize_title($option['value'])); ?>"><?php echo esc_html($option['label']); ?></label><br />
                                    <?php endforeach; ?>
                                <?php
                                    break;
                                case 'checkbox':
                                ?>
                                    <?php foreach ($field_options as $option) : ?>
                                        <input type="checkbox" name="<?php echo esc_attr($field_name); ?>[]" id="<?php echo esc_attr($field_name . '_' . sanitize_title($option['value'])); ?>" value="<?php echo esc_attr($option['value']); ?>" <?php echo $html_attributes; ?> />
                                        <label for="<?php echo esc_attr($field_name . '_' . sanitize_title($option['value'])); ?>"><?php echo esc_html($option['label']); ?></label><br />
                                    <?php endforeach; ?>
                                <?php
                                    break;
                                case 'checkbox_single':
                                ?>
                                    <input type="hidden" name="<?php echo esc_attr($field_name); ?>" value="0" />
                                    <input type="checkbox" name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_name); ?>" value="1" <?php echo $html_attributes; ?> />
                                <?php
                                    break;
                                case 'datalist':
                                ?>
                                    <input type="text" name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_name); ?>" list="<?php echo esc_attr($field_name); ?>_list" <?php echo $html_attributes; ?> />
                                    <datalist id="<?php echo esc_attr($field_name); ?>_list">
                                        <?php foreach ($field_options as $option) : ?>
                                            <option value="<?php echo esc_attr($option['value']); ?>"><?php echo esc_html($option['label']); ?></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                <?php
                                    break;
                                case 'hidden':
                                ?>
                                    <input type="hidden" name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_name); ?>" value="" <?php echo $html_attributes; ?> />
                                <?php
                                    break;
                                case 'output':
                                ?>
                                    <output name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_name); ?>" <?php echo $html_attributes; ?>></output>
                                <?php
                                    break;
                                case 'fieldset':
                                    // Fieldsets are handled by grouping fields, not rendered directly here.
                                    break;
                                default:
                                ?>
                                    <input type="<?php echo esc_attr($field_type); ?>" name="<?php echo esc_attr($field_name); ?>" id="<?php echo esc_attr($field_name); ?>" <?php echo $html_attributes; ?> />
                            <?php
                                    break;
                            }
                            ?>
                        </div>
                        <?php if (! empty($field_help_text)) : ?>
                            <div id="<?php echo $modal_id; ?>" class="akashic-help-modal" style="display: none;">
                                <div class="akashic-help-modal-content" <?php echo ! empty($field_help_modal_bg_color) ? 'style="background-color:' . esc_attr($field_help_modal_bg_color) . ';"' : ''; ?>>
                                    <span class="akashic-help-modal-close">&times;</span>
                                    <div class="akashic-help-modal-body"><?php echo wp_kses_post($field_help_text); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php
                    }
                    ?>
                    <p>
                        <input type="submit" name="akashic_form_submit" value="<?php echo esc_attr($submit_button_text); ?>" data-submitting-text="<?php echo esc_attr($submitting_button_text); ?>" />
                    </p>
                </form>
                <div class="akashic-form-message" style="display: none;"><?php echo wp_kses_post($form_message); ?></div>
            </div>
            <div id="akashic-form-modal-<?php echo esc_attr($form_id); ?>" class="akashic-form-modal" style="display: none;">
                <div class="akashic-form-modal-content">
                    <span class="akashic-help-modal-close">&times;</span>
                    <div class="akashic-form-modal-body"><?php echo wp_kses_post($modal_message); ?></div>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

    }

}

new Akashic_Forms_Shortcode();
