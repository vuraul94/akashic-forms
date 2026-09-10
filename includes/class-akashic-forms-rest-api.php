<?php

/**
 * REST API for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('Akashic_Forms_REST_API')) {

    class Akashic_Forms_REST_API
    {

        /**
         * Keys that are never treated as form fields.
         *
         * @var array
         */
        private $reserved_keys = array('form_id', 'submitted_at', 'action', '_wpnonce', '_wp_http_referer', 'akashic_hp', 'akashic_ts');

        /**
         * Constructor.
         */
        public function __construct()
        {
            add_action('rest_api_init', array($this, 'register_routes'));
        }

        /**
         * Register the REST API routes.
         */
        public function register_routes()
        {
            register_rest_route('akashic-forms/v1', '/sync', array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_sync_request'),
                'permission_callback' => array($this, 'check_submission_permission'),
            ));
        }

        /**
         * Permission callback for the public submission endpoint.
         *
         * This endpoint is intentionally public: front-end submissions come from
         * anonymous visitors that have no cookies, no user account and no
         * capability we could check here. Abuse protection is therefore applied
         * inside the request handler instead, by the honeypot / timestamp check
         * and the per-IP rate limit (and by the nonce check when the request
         * happens to carry one).
         *
         * @return bool Always true.
         */
        public function check_submission_permission()
        {
            return true;
        }

        /**
         * Handle the sync request.
         *
         * @param WP_REST_Request $request The REST request object.
         * @return WP_REST_Response The REST response object.
         */
        public function handle_sync_request($request)
        {
            $form_id = $request->get_param('form_id');

            // Rate limiting runs first and always counts the request, including the
            // ones rejected below: flooding the endpoint is exactly what we throttle.
            if (! $this->check_rate_limit($form_id)) {
                return new WP_REST_Response(array('message' => __('Too many submissions from your connection. Please wait a few minutes and try again.', 'akashic-forms')), 429);
            }

            // Honeypot + timestamp check before doing any real work.
            if (! $this->passes_bot_checks($request)) {
                return new WP_REST_Response(array('message' => __('Your submission could not be processed. Please try again.', 'akashic-forms')), 400);
            }

            // The endpoint is public on purpose, but when the request does carry a
            // REST nonce we verify it instead of ignoring it. Anonymous visitors
            // without cookies send no nonce at all, and those are still accepted.
            $nonce = $request->get_header('X-WP-Nonce');
            if (empty($nonce)) {
                $nonce = $request->get_param('_wpnonce');
            }
            if (! empty($nonce) && is_string($nonce) && ! wp_verify_nonce($nonce, 'wp_rest')) {
                return new WP_REST_Response(array('message' => __('Security check failed. Please reload the page and try again.', 'akashic-forms')), 403);
            }

            $submitted_at = $this->normalize_submitted_at($request->get_param('submitted_at'));

            $all_params = $request->get_params();
            $form_data = array();

            // Get form fields definition to identify file types
            $form_fields_definition = get_post_meta($form_id, '_akashic_form_fields', true);
            $db = new Akashic_Forms_DB();
            $errors = array();

            if (is_array($form_fields_definition)) {
                foreach ($form_fields_definition as $field) {
                    $field_name = isset($field['name']) ? $field['name'] : '';
                    if (empty($field_name) || (isset($field['type']) && $field['type'] === 'file')) {
                        continue; // Skip file fields for now, they are validated separately
                    }

                    $field_value = $request->get_param($field_name);

                    // Required validation
                    $is_required = isset($field['required']) && $field['required'] == '1';
                    if ($is_required && empty($field_value)) {
                        $errors[$field_name] = sprintf(__('%s es requerido.', 'akashic-forms'), $field['label']);
                        continue;
                    }

                    // Unique validation
                    $is_unique = isset($field['unique']) && $field['unique'] == '1' && !(isset($field['type']) && $field['type'] === 'file');
                    if ($is_unique && !empty($field_value)) {
                        if (!$db->is_value_unique($form_id, $field_name, $field_value)) {
                            $unique_message = isset($field['unique_message']) && !empty($field['unique_message'])
                                ? $field['unique_message']
                                : __('This value has already been entered', 'akashic-forms');
                            $errors[$field_name] = $unique_message;
                        }
                    }

                    // Pattern validation
                    $pattern = isset($field['pattern']) ? $field['pattern'] : '';
                    if (!empty($pattern) && !empty($field_value) && is_scalar($field_value)) {
                        // The pattern comes from the form configuration, so it may contain
                        // the delimiter itself. Use "@" as delimiter, escape it inside the
                        // pattern and wrap the whole thing in a non-capturing group so the
                        // anchors keep applying to the full expression.
                        $regex = '@^(?:' . str_replace('@', '\\@', $pattern) . ')$@';
                        $match = @preg_match($regex, (string) $field_value);
                        if (false === $match) {
                            // Broken pattern in the form config: log it and skip the check
                            // instead of rejecting a submission the visitor cannot fix.
                            akashic_forms_log('Invalid validation pattern for field "' . $field_name . '" on form ' . (string) $form_id . '; pattern check skipped.');
                        } elseif (0 === $match) {
                            $errors[$field_name] = isset($field['validation_message']) && !empty($field['validation_message'])
                                ? $field['validation_message']
                                : __('Invalid format.', 'akashic-forms');
                        }
                    }
                }
            }

            if (!is_array($form_fields_definition)) {
                $form_fields_definition = array();
            }
            $field_types_map = array();
            $declared_field_names = array();
            foreach ($form_fields_definition as $field_def) {
                if (isset($field_def['name']) && isset($field_def['type'])) {
                    $field_types_map[$field_def['name']] = $field_def['type'];
                }
                if (isset($field_def['name']) && '' !== (string) $field_def['name']) {
                    $declared_field_names[] = (string) $field_def['name'];
                }
            }

            // Process regular fields. Only names declared in the form are accepted;
            // anything else that arrives in the request is dropped silently (it is
            // not a validation error for the visitor).
            foreach ($all_params as $key => $value) {
                if (in_array($key, $this->reserved_keys, true)) {
                    continue;
                }
                if (!in_array((string) $key, $declared_field_names, true)) {
                    continue;
                }
                if (isset($field_types_map[$key]) && 'file' === $field_types_map[$key]) {
                    continue;
                }

                $is_textarea = isset($field_types_map[$key]) && 'textarea' === $field_types_map[$key];
                $clean_value = $this->sanitize_submitted_value($value, $is_textarea);
                if (null === $clean_value) {
                    continue; // Not a scalar and not an array: discard.
                }
                $form_data[$key] = $clean_value;
            }

            // Process file uploads from $_FILES
            if (!empty($_FILES)) {
                if (!function_exists('wp_handle_upload')) {
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                }
                $upload_overrides = array('test_form' => false);

                $this->protect_upload_base_dir();

                $default_max_size_mb = (float) apply_filters('akashic_forms_default_max_upload_size', 10);

                foreach ($_FILES as $file_field_name => $file_data) {
                    // Limpiamos el nombre del campo por si viene con []
                    $clean_field_name = str_replace('[]', '', $file_field_name);

                    if (isset($field_types_map[$clean_field_name]) && 'file' === $field_types_map[$clean_field_name]) {
                        
                        // Buscamos la definicion del campo
                        $current_field_def = null;
                        foreach ($form_fields_definition as $def) {
                            if (isset($def['name']) && $def['name'] === $clean_field_name) {
                                $current_field_def = $def;
                                break;
                            }
                        }

                        if ($current_field_def) {
                            // Preparamos los archivos para iterar (sea uno o varios)
                            $files_to_process = [];
                            if (is_array($file_data['name'])) {
                                // Si es multiple, reestructuramos el array
                                foreach ($file_data['name'] as $i => $val) {
                                    $files_to_process[] = [
                                        'name'     => $file_data['name'][$i],
                                        'type'     => $file_data['type'][$i],
                                        'tmp_name' => $file_data['tmp_name'][$i],
                                        'error'    => $file_data['error'][$i],
                                        'size'     => $file_data['size'][$i],
                                    ];
                                }
                            } else {
                                // Si es archivo unico
                                $files_to_process[] = $file_data;
                            }

                            $uploaded_urls = [];

                            foreach ($files_to_process as $single_file) {
                                if ($single_file['error'] === UPLOAD_ERR_NO_FILE) continue;

                                // Validaciones de Formato y Tamano
                                $file_extension = strtolower(pathinfo($single_file['name'], PATHINFO_EXTENSION));
                                $file_size_mb_actual = $single_file['size'] / (1024 * 1024);
                                
                                $allowed_formats = isset($current_field_def['allowed_formats']) && !empty($current_field_def['allowed_formats']) ? array_map('trim', explode(',', strtolower($current_field_def['allowed_formats']))) : [];
                                $max_size_mb = isset($current_field_def['max_size']) ? floatval($current_field_def['max_size']) : 0;
                                if ($max_size_mb <= 0) {
                                    // No per-field limit configured: fall back to the default cap.
                                    $max_size_mb = $default_max_size_mb;
                                }
                                $allowed_formats_message = !empty($current_field_def['allowed_formats_message']) ? sanitize_text_field($current_field_def['allowed_formats_message']) : __( 'Invalid file format.', 'akashic-forms' );
                                $max_size_message = !empty($current_field_def['max_size_message']) ? sanitize_text_field($current_field_def['max_size_message']) : sprintf( __( 'File size exceeds the maximum allowed limit of %s MB.', 'akashic-forms' ), $max_size_mb );

                                // PHP itself rejected the file because it exceeded upload_max_filesize or
                                // the MAX_FILE_SIZE hidden field. Treat it the same as our own size check.
                                if ( $single_file['error'] === UPLOAD_ERR_INI_SIZE || $single_file['error'] === UPLOAD_ERR_FORM_SIZE ) {
                                    $errors[$clean_field_name] = $max_size_message;
                                    continue;
                                }

                                if (!empty($allowed_formats) && !in_array($file_extension, $allowed_formats)) {
                                    $errors[$clean_field_name] = $allowed_formats_message;
                                    continue;
                                }

                                if ($max_size_mb > 0 && $file_size_mb_actual > $max_size_mb) {
                                    $errors[$clean_field_name] = $max_size_message;
                                    continue;
                                }

                                // Proceso de subida si no hay errores previos
                                if ($single_file['error'] === UPLOAD_ERR_OK) {
                                    $new_filename = $this->generate_upload_filename($file_extension);
                                    $single_file['name'] = $new_filename;

                                    // Send new uploads to our own dated subdirectory. The filter is
                                    // added only around this call so nothing else is affected.
                                    add_filter('upload_dir', array($this, 'filter_upload_dir'));
                                    $uploaded_file = wp_handle_upload($single_file, $upload_overrides);
                                    remove_filter('upload_dir', array($this, 'filter_upload_dir'));
                                    
                                    if (isset($uploaded_file['url'])) {
                                        $uploaded_urls[] = $uploaded_file['url'];
                                    }
                                }
                            }

                            // Guardamos los resultados (como string separado por comas)
                            if (!empty($uploaded_urls)) {
                                $form_data[$clean_field_name] = implode(', ', $uploaded_urls);
                            }
                        }
                    }
                }
            }

            // If there are any validation errors, send them back.
            if ( ! empty( $errors ) ) {
                return new WP_REST_Response( array( 'errors' => $errors ), 400 );
            }

            if (empty($form_id)) {
                return new WP_REST_Response(array('message' => 'Missing form_id.'), 400);
            }

            $db = new Akashic_Forms_DB();

            // Always add to queue first with pending status
            $queue_id = $db->add_submission_to_queue($form_id, $form_data);
            if (!$queue_id) {
                return new WP_REST_Response(array('message' => 'Failed to add submission to queue initially.'), 500);
            }

            // Insert into akashic_form_submissions table
            $db->insert_submission($form_id, $form_data, $submitted_at);

            return new WP_REST_Response(array('message' => 'Submission received and queued for processing.'), 200);
        }

        /**
         * Sanitize a submitted value before it is stored.
         *
         * Scalars are run through the text/textarea sanitizers and length capped;
         * arrays (multiple checkboxes) are sanitized recursively, keys included;
         * anything else is discarded.
         *
         * @param mixed $value       Raw value coming from the request.
         * @param bool  $is_textarea Whether the declared field is a textarea.
         * @param int   $depth       Current recursion depth.
         * @return string|array|null Sanitized value, or null when it must be discarded.
         */
        private function sanitize_submitted_value($value, $is_textarea = false, $depth = 0)
        {
            if (is_array($value)) {
                if ($depth >= 5) {
                    return array();
                }
                $clean = array();
                foreach ($value as $key => $item) {
                    $clean_key = is_int($key) ? $key : sanitize_text_field((string) $key);
                    $clean_item = $this->sanitize_submitted_value($item, $is_textarea, $depth + 1);
                    if (null === $clean_item) {
                        continue;
                    }
                    $clean[$clean_key] = $clean_item;
                }
                return $clean;
            }

            if (!is_scalar($value)) {
                return null;
            }

            $value = $is_textarea ? sanitize_textarea_field((string) $value) : sanitize_text_field((string) $value);

            $max_length = $is_textarea ? 65535 : 5000;
            if (function_exists('mb_substr') && function_exists('mb_strlen')) {
                if (mb_strlen($value) > $max_length) {
                    $value = mb_substr($value, 0, $max_length);
                }
            } elseif (strlen($value) > $max_length) {
                $value = substr($value, 0, $max_length);
            }

            return $value;
        }

        /**
         * Honeypot and timestamp checks.
         *
         * @param WP_REST_Request $request The REST request object.
         * @return bool True when the request looks human.
         */
        private function passes_bot_checks($request)
        {
            // The honeypot field is hidden, so a human never fills it in.
            $honeypot = $request->get_param('akashic_hp');
            if (is_array($honeypot)) {
                return false;
            }
            if (null !== $honeypot && '' !== trim((string) $honeypot)) {
                return false;
            }

            $timestamp_param = $request->get_param('akashic_ts');
            if (null === $timestamp_param || '' === trim((string) $timestamp_param)) {
                // Field missing: the page was very likely cached before this field
                // existed. Treat it as absent and let the submission through.
                return true;
            }
            if (!is_scalar($timestamp_param)) {
                return false;
            }

            $parts = explode('.', (string) $timestamp_param, 2);
            if (count($parts) !== 2) {
                return false;
            }

            $timestamp = (int) $parts[0];
            $provided_hash = $parts[1];
            if ($timestamp <= 0) {
                return false;
            }

            $expected_hash = substr(wp_hash($timestamp . 'akashic_forms_ts'), 0, 12);
            if (!hash_equals($expected_hash, $provided_hash)) {
                return false;
            }

            $elapsed = time() - $timestamp;
            if ($elapsed < 2 || $elapsed > DAY_IN_SECONDS) {
                return false;
            }

            return true;
        }

        /**
         * Per-IP rate limit backed by transients.
         *
         * @param mixed $form_id The submitted form ID.
         * @return bool True when the request is within the allowed limits.
         */
        private function check_rate_limit($form_id)
        {
            $defaults = array(
                'form_max'      => 5,
                'form_window'   => 5 * MINUTE_IN_SECONDS,
                'global_max'    => 20,
                'global_window' => HOUR_IN_SECONDS,
            );

            /**
             * Filters the submission rate limits.
             *
             * @param array $defaults Default limits (max submissions and window in seconds).
             */
            $limits = apply_filters('akashic_forms_rate_limit', $defaults);
            if (!is_array($limits)) {
                $limits = $defaults;
            }
            $limits = array_merge($defaults, $limits);

            // The IP is only ever stored hashed inside the transient key.
            $ip_hash = md5($this->get_client_ip());

            $buckets = array(
                array(
                    'key'    => 'akf_rl_f_' . md5($ip_hash . '|' . (string) $form_id),
                    'max'    => (int) $limits['form_max'],
                    'window' => (int) $limits['form_window'],
                ),
                array(
                    'key'    => 'akf_rl_g_' . $ip_hash,
                    'max'    => (int) $limits['global_max'],
                    'window' => (int) $limits['global_window'],
                ),
            );

            $now = time();
            $allowed = true;

            foreach ($buckets as $bucket) {
                if ($bucket['max'] <= 0 || $bucket['window'] <= 0) {
                    continue;
                }

                $data = get_transient($bucket['key']);
                if (!is_array($data) || !isset($data['count']) || !isset($data['start']) || ($now - (int) $data['start']) >= $bucket['window']) {
                    $data = array('count' => 0, 'start' => $now);
                }

                $data['count'] = (int) $data['count'] + 1;

                $remaining = $bucket['window'] - ($now - (int) $data['start']);
                if ($remaining < 1) {
                    $remaining = $bucket['window'];
                }

                set_transient($bucket['key'], $data, $remaining);

                if ($data['count'] > $bucket['max']) {
                    $allowed = false;
                }
            }

            return $allowed;
        }

        /**
         * Resolve the client IP.
         *
         * REMOTE_ADDR is used by default because forwarded headers are trivially
         * spoofable. Sites behind a trusted reverse proxy can widen this through
         * the akashic_forms_client_ip filter.
         *
         * @return string The client IP, or 0.0.0.0 when it cannot be determined.
         */
        private function get_client_ip()
        {
            $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? trim((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
            $forwarded_for = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim((string) wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])) : '';

            /**
             * Filters the IP used for rate limiting.
             *
             * @param string $remote_addr   The REMOTE_ADDR value (the safe default).
             * @param string $forwarded_for The raw X-Forwarded-For header, if any.
             */
            $ip = apply_filters('akashic_forms_client_ip', $remote_addr, $forwarded_for);

            $ip = filter_var((string) $ip, FILTER_VALIDATE_IP);

            return $ip ? $ip : '0.0.0.0';
        }

        /**
         * Build an unpredictable file name for an upload.
         *
         * @param string $file_extension The (already lowercased) extension.
         * @return string The generated file name.
         */
        private function generate_upload_filename($file_extension)
        {
            try {
                $name = bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                $name = wp_generate_password(32, false, false);
            }

            $extension = preg_replace('/[^a-z0-9]/', '', (string) $file_extension);

            return '' !== $extension ? $name . '.' . $extension : $name;
        }

        /**
         * Point uploads at our own dated subdirectory.
         *
         * Only applied around wp_handle_upload() for submission files; existing
         * files keep the URLs already stored in past submissions.
         *
         * @param array $dirs Upload directory data.
         * @return array Modified upload directory data.
         */
        public function filter_upload_dir($dirs)
        {
            if (empty($dirs['basedir']) || empty($dirs['baseurl'])) {
                return $dirs;
            }

            $subdir = '/akashic-forms/' . gmdate('Y') . '/' . gmdate('m');

            $dirs['subdir'] = $subdir;
            $dirs['path']   = $dirs['basedir'] . $subdir;
            $dirs['url']    = $dirs['baseurl'] . $subdir;

            return $dirs;
        }

        /**
         * Create uploads/akashic-forms/ and drop the hardening files in it.
         *
         * Runs only when the guard files are missing, so it is a no-op afterwards.
         */
        private function protect_upload_base_dir()
        {
            $uploads = wp_get_upload_dir();
            if (empty($uploads['basedir'])) {
                return;
            }

            $base_dir = trailingslashit($uploads['basedir']) . 'akashic-forms';

            if (!is_dir($base_dir) && !wp_mkdir_p($base_dir)) {
                akashic_forms_log('Could not create the uploads/akashic-forms directory.');
                return;
            }

            $htaccess = $base_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                $rules  = "# Added by Akashic Forms. Do not edit.\n";
                $rules .= "Options -Indexes\n";
                $rules .= "<FilesMatch \"\\.(?i:php|phtml|php3|php4|php5|php7|php8|phps|pht)$\">\n";
                $rules .= "\t<IfModule mod_authz_core.c>\n";
                $rules .= "\t\tRequire all denied\n";
                $rules .= "\t</IfModule>\n";
                $rules .= "\t<IfModule !mod_authz_core.c>\n";
                $rules .= "\t\tOrder allow,deny\n";
                $rules .= "\t\tDeny from all\n";
                $rules .= "\t</IfModule>\n";
                $rules .= "</FilesMatch>\n";
                $rules .= "<IfModule mod_php.c>\n";
                $rules .= "\tphp_flag engine off\n";
                $rules .= "</IfModule>\n";
                $rules .= "<IfModule mod_php7.c>\n";
                $rules .= "\tphp_flag engine off\n";
                $rules .= "</IfModule>\n";

                if (false === @file_put_contents($htaccess, $rules)) {
                    akashic_forms_log('Could not write the .htaccess guard in uploads/akashic-forms.');
                }
            }

            $index_file = $base_dir . '/index.php';
            if (!file_exists($index_file)) {
                if (false === @file_put_contents($index_file, "<?php // Silence is golden.\n")) {
                    akashic_forms_log('Could not write the index.php guard in uploads/akashic-forms.');
                }
            }
        }

        /**
         * Normalize the client supplied submission date to UTC MySQL format.
         *
         * @param mixed $value Raw submitted_at value (ISO 8601 from the front end).
         * @return string|null Y-m-d H:i:s in UTC, or null when it is not usable.
         */
        private function normalize_submitted_at($value)
        {
            if (!is_scalar($value) || '' === trim((string) $value)) {
                return null;
            }

            $timestamp = strtotime((string) $value);
            if (false === $timestamp || $timestamp <= 0) {
                return null;
            }

            return gmdate('Y-m-d H:i:s', $timestamp);
        }
    }

    new Akashic_Forms_REST_API();
}
