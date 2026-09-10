<?php

/**
 * Database class for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('Akashic_Forms_DB')) {

    class Akashic_Forms_DB
    {

        /**
         * Constructor.
         */
        public function __construct()
        {
            register_activation_hook(AKASHIC_FORMS_PLUGIN_DIR . 'akashic-forms.php', array($this, 'create_tables'));
        }

        /**
         * Create the custom database tables.
         */
        public function create_tables()
        {
            $this->create_submissions_table();
            $this->create_queue_table();
            $this->create_field_values_table();
        }

        /**
         * Create the custom database table for the queue.
         */
        public function create_queue_table()
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                form_id bigint(20) NOT NULL,
                submission_data longtext NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                failure_reason text DEFAULT NULL,
                response longtext DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
                processing_started_at datetime DEFAULT NULL,
                attempts int(11) NOT NULL DEFAULT 0,
                next_attempt_at datetime DEFAULT NULL,
                claim_token varchar(32) DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY status (status),
                KEY form_id (form_id),
                KEY created_at (created_at),
                KEY claim_token (claim_token)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }

        /**
         * Create the custom database table for submissions.
         */
        public function create_submissions_table()
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_submissions';

            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                form_id bigint(20) NOT NULL,
                submission_data longtext NOT NULL,
                submitted_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY form_id (form_id),
                KEY submitted_at (submitted_at)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }

        /**
         * Create the lookup table that indexes submitted field values.
         *
         * Uniqueness checks used to load and unserialize every submission of a
         * form; this table turns them into a single indexed SELECT.
         */
        public function create_field_values_table()
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_field_values';

            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                submission_id bigint(20) NOT NULL,
                form_id bigint(20) NOT NULL,
                field_name varchar(191) NOT NULL,
                value_hash char(64) NOT NULL,
                PRIMARY KEY  (id),
                KEY lookup (form_id, field_name, value_hash),
                KEY submission_id (submission_id)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }

        /**
         * Run pending schema upgrades and data migrations.
         *
         * Called on every load, so it bails out on the option check before
         * doing any work. Safe to run more than once.
         */
        public static function maybe_upgrade()
        {
            $installed = get_option('akashic_forms_db_version');

            if ((string) $installed === (string) AKASHIC_FORMS_DB_VERSION) {
                return;
            }

            $db = new self();

            // dbDelta adds the missing columns and indexes to existing tables.
            $db->create_tables();

            // Backfill the value index from the submissions already stored.
            $db->backfill_field_values();

            update_option('akashic_forms_db_version', AKASHIC_FORMS_DB_VERSION, false);

            akashic_forms_log('Schema upgraded to DB version ' . AKASHIC_FORMS_DB_VERSION . '.');
        }

        /**
         * Populate the field value index from existing submissions.
         *
         * Walks the submissions in batches so installs with a large history do
         * not exhaust memory. Submissions already present in the index are
         * skipped, which keeps the migration idempotent.
         */
        private function backfill_field_values()
        {
            global $wpdb;

            $submissions_table = $wpdb->prefix . 'akashic_form_submissions';
            $values_table      = $wpdb->prefix . 'akashic_form_field_values';

            $batch_size = 200;
            $offset     = 0;

            do {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, form_id, submission_data FROM $submissions_table ORDER BY id ASC LIMIT %d OFFSET %d",
                        $batch_size,
                        $offset
                    )
                );

                if (empty($rows)) {
                    break;
                }

                $ids = array();
                foreach ($rows as $row) {
                    $ids[] = (int) $row->id;
                }

                $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
                $already      = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT DISTINCT submission_id FROM $values_table WHERE submission_id IN ($placeholders)",
                        $ids
                    )
                );
                $already = array_map('intval', (array) $already);

                foreach ($rows as $row) {
                    if (in_array((int) $row->id, $already, true)) {
                        continue;
                    }

                    $this->index_submission_values(
                        (int) $row->id,
                        (int) $row->form_id,
                        $this->decode_data($row->submission_data)
                    );
                }

                $offset += $batch_size;
            } while (count($rows) === $batch_size);
        }

        /**
         * Encode submission data for storage.
         *
         * @param array $data The submission data.
         * @return string The encoded data.
         */
        private function encode_data($data)
        {
            return wp_json_encode($data);
        }

        /**
         * Decode stored submission data.
         *
         * New rows are stored as JSON. Rows written before that change are
         * still serialized, so they fall back to unserialize().
         *
         * @param mixed $raw The stored value.
         * @return array The decoded data, always an array.
         */
        private function decode_data($raw)
        {
            if (is_array($raw)) {
                return $raw;
            }

            if (! is_string($raw) || '' === $raw) {
                return array();
            }

            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return $decoded;
            }

            // Legacy rows stored with serialize().
            $legacy = @unserialize($raw, array('allowed_classes' => false));

            if (is_array($legacy)) {
                return $legacy;
            }

            return array();
        }

        /**
         * Validate an ORDER BY column against a whitelist.
         *
         * The value reaches this class straight from $_REQUEST and is used as a
         * bare SQL identifier, where esc_sql() offers no protection.
         *
         * @param string $orderby The requested column.
         * @param string $context Either 'queue' or 'submissions'.
         * @return string A safe column name.
         */
        private function sanitize_orderby($orderby, $context = 'queue')
        {
            if ('submissions' === $context) {
                $allowed  = array('id', 'submitted_at');
                $fallback = 'submitted_at';
            } else {
                $allowed  = array('id', 'form_id', 'status', 'created_at', 'updated_at');
                $fallback = 'created_at';
            }

            $orderby = is_string($orderby) ? strtolower(trim($orderby)) : '';

            return in_array($orderby, $allowed, true) ? $orderby : $fallback;
        }

        /**
         * Validate an ORDER BY direction.
         *
         * @param string $order The requested direction.
         * @return string Either 'ASC' or 'DESC'.
         */
        private function sanitize_order($order)
        {
            $order = is_string($order) ? strtoupper(trim($order)) : '';

            return ('ASC' === $order) ? 'ASC' : 'DESC';
        }

        /**
         * Normalize a date into the MySQL datetime format.
         *
         * @param mixed $value The candidate date.
         * @return string The normalized date, or an empty string if invalid.
         */
        private function normalize_datetime($value)
        {
            if (! is_string($value) && ! is_numeric($value)) {
                return '';
            }

            $value = trim((string) $value);

            if ('' === $value) {
                return '';
            }

            $timestamp = strtotime($value);

            if (false === $timestamp) {
                return '';
            }

            return gmdate('Y-m-d H:i:s', $timestamp);
        }

        /**
         * Index the scalar values of a submission for uniqueness checks.
         *
         * @param int   $submission_id The ID of the submission.
         * @param int   $form_id       The ID of the form.
         * @param array $data          The submission data.
         */
        private function index_submission_values($submission_id, $form_id, $data)
        {
            global $wpdb;

            $submission_id = (int) $submission_id;

            if ($submission_id <= 0 || ! is_array($data) || empty($data)) {
                return;
            }

            $table_name = $wpdb->prefix . 'akashic_form_field_values';

            $placeholders = array();
            $values       = array();

            foreach ($data as $field_name => $value) {
                if (! is_scalar($value)) {
                    continue;
                }

                $value = (string) $value;

                if ('' === $value) {
                    continue;
                }

                $placeholders[] = '(%d, %d, %s, %s)';
                $values[]       = $submission_id;
                $values[]       = (int) $form_id;
                $values[]       = substr((string) $field_name, 0, 191);
                $values[]       = hash('sha256', $value);
            }

            if (empty($placeholders)) {
                return;
            }

            $sql = "INSERT INTO $table_name (submission_id, form_id, field_name, value_hash) VALUES " . implode(', ', $placeholders);

            $wpdb->query($wpdb->prepare($sql, $values));
        }

        /**
         * Remove index rows for a submission or a whole form.
         *
         * @param string $column The column to match ('submission_id' or 'form_id').
         * @param int    $value  The value to match.
         */
        private function delete_indexed_values($column, $value)
        {
            global $wpdb;

            if (! in_array($column, array('submission_id', 'form_id'), true)) {
                return;
            }

            $wpdb->delete(
                $wpdb->prefix . 'akashic_form_field_values',
                array($column => (int) $value),
                array('%d')
            );
        }

        /**
         * Insert a new submission into the database.
         *
         * @param int         $form_id      The ID of the form.
         * @param array       $data         The submission data.
         * @param string|null $submitted_at Optional submission date. Ignored if unparseable.
         * @return int|false The ID of the inserted row on success, false on failure.
         */
        public function insert_submission($form_id, $data, $submitted_at = null)
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_submissions';

            if (! is_array($data)) {
                $data = array();
            }

            $insert_data = array(
                'form_id'       => $form_id,
                'submission_data' => $this->encode_data($data),
            );
            $insert_format = array(
                '%d',
                '%s',
            );

            // The explicit parameter wins; the payload key is the fallback.
            $candidate = null;

            if (null !== $submitted_at && '' !== $submitted_at) {
                $candidate = $submitted_at;
            } elseif (isset($data['submitted_at'])) {
                $candidate = $data['submitted_at'];
            }

            $normalized = $this->normalize_datetime($candidate);

            // An invalid date is left out so the column default applies.
            if ('' !== $normalized) {
                $insert_data['submitted_at'] = $normalized;
                $insert_format[] = '%s';
            }

            $result = $wpdb->insert(
                $table_name,
                $insert_data,
                $insert_format
            );

            if ($result) {
                $submission_id = (int) $wpdb->insert_id;

                $this->index_submission_values($submission_id, (int) $form_id, $data);

                return $submission_id;
            }

            return false;
        }

        /**
         * Get all submissions for a specific form.
         *
         * Not paginated on purpose: the CSV export needs the full set.
         *
         * @param int $form_id The ID of the form.
         * @return array An array of submission objects.
         */
        public function get_submissions($form_id)
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_submissions';

            $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE form_id = %d ORDER BY submitted_at DESC", $form_id));

            foreach ($results as $result) {
                $result->submission_data = $this->decode_data($result->submission_data);
            }

            return $results;
        }

        /**
         * Get a page of submissions for a specific form.
         *
         * @param int   $form_id The ID of the form.
         * @param array $args    Arguments for retrieving submissions.
         * @return array An array of submission objects.
         */
        public function get_submissions_page($form_id, $args = array())
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_submissions';

            $defaults = array(
                'per_page' => 20,
                'page'     => 1,
                'orderby'  => 'submitted_at',
                'order'    => 'DESC',
            );

            $args = wp_parse_args($args, $defaults);

            $orderby  = $this->sanitize_orderby($args['orderby'], 'submissions');
            $order    = $this->sanitize_order($args['order']);
            $per_page = max(1, absint($args['per_page']));
            $page     = max(1, absint($args['page']));
            $offset   = ($page - 1) * $per_page;

            $sql = "SELECT * FROM $table_name WHERE form_id = %d ORDER BY $orderby $order LIMIT %d OFFSET %d";

            $results = $wpdb->get_results($wpdb->prepare($sql, $form_id, $per_page, $offset));

            foreach ($results as $result) {
                $result->submission_data = $this->decode_data($result->submission_data);
            }

            return $results;
        }

        /**
         * Get the total number of submissions for a specific form.
         *
         * @param int $form_id The ID of the form.
         * @return int
         */
        public function get_submissions_count($form_id)
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_submissions';

            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_name WHERE form_id = %d", $form_id));
        }

        /**
         * Get a single submission by its ID.
         *
         * @param int $submission_id The ID of the submission.
         * @return object|null The submission object, or null if not found.
         */
        public function get_submission($submission_id)
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_submissions';

            $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $submission_id));

            if ($result) {
                $result->submission_data = $this->decode_data($result->submission_data);
            }

            return $result;
        }

        /**
         * Delete a single submission from the database.
         *
         * @param int $submission_id The ID of the submission to delete.
         * @return int|false The number of rows deleted, or false on error.
         */
        public function delete_submission($submission_id)
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_submissions';

            $this->delete_indexed_values('submission_id', $submission_id);

            return $wpdb->delete(
                $table_name,
                array('id' => $submission_id),
                array('%d')
            );
        }

        /**
         * Delete all submissions for a specific form.
         *
         * @param int $form_id The ID of the form.
         * @return int|false The number of rows deleted, or false on error.
         */
        public function delete_submissions($form_id)
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_submissions';

            $this->delete_indexed_values('form_id', $form_id);

            return $wpdb->delete(
                $table_name,
                array('form_id' => $form_id),
                array('%d')
            );
        }

        /**
         * Add a submission to the queue.
         *
         * @param int   $form_id The ID of the form.
         * @param array $data    The submission data.
         * @return int|false The ID of the inserted row on success, false on failure.
         */
        public function add_submission_to_queue($form_id, $data)
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $result = $wpdb->insert(
                $table_name,
                array(
                    'form_id'       => $form_id,
                    'submission_data' => $this->encode_data($data),
                ),
                array(
                    '%d',
                    '%s',
                )
            );

            if ($result) {
                return $wpdb->insert_id;
            }

            return false;
        }

        /**
         * Get all submissions from the queue.
         *
         * @param array $args Arguments for retrieving submissions.
         * @return array An array of submission objects.
         */
        public function get_all_submissions_from_queue($args = array())
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $defaults = array(
                'per_page' => 20,
                'page'     => 1,
                'orderby'  => 'created_at',
                'order'    => 'DESC',
                'search'   => '',
                'status'   => 'all',
            );

            $args = wp_parse_args($args, $defaults);

            $where_clauses = array();
            $sql_params = array();

            if (! empty($args['search'])) {
                $where_clauses[] = "submission_data LIKE %s";
                $sql_params[] = '%' . $wpdb->esc_like($args['search']) . '%';
            }

            if ('all' !== $args['status']) {
                $where_clauses[] = "status = %s";
                $sql_params[] = $args['status'];
            }

            $sql = "SELECT * FROM $table_name";

            if (! empty($where_clauses)) {
                $sql .= " WHERE " . implode(" AND ", $where_clauses);
            }

            $orderby = $this->sanitize_orderby($args['orderby'], 'queue');
            $order   = $this->sanitize_order($args['order']);

            $sql .= " ORDER BY $orderby $order";
            $sql .= " LIMIT %d";
            $sql_params[] = absint($args['per_page']);
            $sql .= " OFFSET %d";
            $sql_params[] = absint(($args['page'] - 1) * $args['per_page']);

            $results = $wpdb->get_results($wpdb->prepare($sql, $sql_params));

            foreach ($results as $result) {
                $result->submission_data = $this->decode_data($result->submission_data);
            }

            return $results;
        }

        /**
         * Get the total number of items in the queue.
         *
         * @return int
         */
        public function get_queue_count($status = 'all')
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $where_clause = '';
            $sql_params = array();

            if ('all' !== $status) {
                $where_clause = " WHERE status = %s";
                $sql_params[] = $status;
            }

            $sql = "SELECT COUNT(id) FROM $table_name" . $where_clause;

            if (! empty($sql_params)) {
                return (int) $wpdb->get_var($wpdb->prepare($sql, $sql_params));
            } else {
                return (int) $wpdb->get_var($sql);
            }
        }

        /**
         * Get pending submissions from the queue.
         *
         * @param int $limit The maximum number of submissions to retrieve.
         * @return array An array of submission objects.
         */
        public function get_pending_submissions_from_queue($limit = 10)
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d", $limit));

            foreach ($results as $result) {
                $result->submission_data = $this->decode_data($result->submission_data);
            }

            return $results;
        }

        /**
         * Get pending and failed submissions from the queue.
         *
         * @param int $limit The maximum number of submissions to retrieve.
         * @return array An array of submission objects.
         */
        public function get_pending_and_failed_submissions_from_queue($limit = 10)
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE status = 'pending' OR status = 'failed' ORDER BY created_at ASC LIMIT %d", $limit));

            foreach ($results as $result) {
                $result->submission_data = $this->decode_data($result->submission_data);
            }

            return $results;
        }

        /**
         * Atomically claim the next submissions to process.
         *
         * Selecting the pending rows and marking them as processing in two
         * separate queries let two overlapping cron runs claim the same row and
         * write the line twice. A single UPDATE stamped with a claim token
         * makes the reservation exclusive.
         *
         * @param int  $limit          The maximum number of submissions to claim.
         * @param bool $include_failed Whether to claim failed submissions too.
         * @return array An array of claimed submission objects.
         */
        public function claim_next_submissions($limit, $include_failed = false)
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $limit = max(1, absint($limit));
            $token = wp_generate_password(32, false, false);
            $now   = current_time('mysql', true);

            if ($include_failed) {
                $sql = $wpdb->prepare(
                    "UPDATE $table_name SET status = 'processing', processing_started_at = %s, claim_token = %s
                     WHERE status IN ('pending', 'failed')
                     AND ( next_attempt_at IS NULL OR next_attempt_at <= %s )
                     ORDER BY created_at ASC LIMIT %d",
                    $now,
                    $token,
                    $now,
                    $limit
                );
            } else {
                $sql = $wpdb->prepare(
                    "UPDATE $table_name SET status = 'processing', processing_started_at = %s, claim_token = %s
                     WHERE status IN ('pending')
                     AND ( next_attempt_at IS NULL OR next_attempt_at <= %s )
                     ORDER BY created_at ASC LIMIT %d",
                    $now,
                    $token,
                    $now,
                    $limit
                );
            }

            $claimed = $wpdb->query($sql);

            if (empty($claimed)) {
                return array();
            }

            $results = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM $table_name WHERE claim_token = %s ORDER BY created_at ASC", $token)
            );

            foreach ($results as $result) {
                $result->submission_data = $this->decode_data($result->submission_data);
            }

            return $results;
        }

        /**
         * Update the status of a submission in the queue.
         *
         * @param int   $submission_id The ID of the submission.
         * @param array $data          The columns to update.
         * @return int|false The number of rows updated, or false on error.
         */
        public function update_submission_in_queue($submission_id, $data)
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $update_data = array();
            $update_format = array();

            if (isset($data['status'])) {
                $update_data['status'] = $data['status'];
                $update_format[] = '%s';

                // The claim only holds while the row is being processed.
                if ('processing' !== $data['status']) {
                    $update_data['claim_token'] = null;
                    $update_format[] = '%s';
                }
            }

            if (isset($data['failure_reason'])) {
                $update_data['failure_reason'] = $data['failure_reason'];
                $update_format[] = '%s';
            }

            if (isset($data['response'])) {
                $update_data['response'] = $data['response'];
                $update_format[] = '%s';
            }

            if (isset($data['processing_started_at'])) {
                $update_data['processing_started_at'] = $data['processing_started_at'];
                $update_format[] = '%s';
            }

            if (isset($data['attempts'])) {
                $update_data['attempts'] = (int) $data['attempts'];
                $update_format[] = '%d';
            }

            // array_key_exists so the retry date can be cleared with null.
            if (array_key_exists('next_attempt_at', $data)) {
                $next_attempt_at = $data['next_attempt_at'];

                $update_data['next_attempt_at'] = ('' === $next_attempt_at || null === $next_attempt_at) ? null : $next_attempt_at;
                $update_format[] = '%s';
            }

            if (empty($update_data)) {
                return false;
            }

            return $wpdb->update(
                $table_name,
                $update_data,
                array('id' => $submission_id),
                $update_format,
                array('%d')
            );
        }

        /**
         * Delete a submission from the queue.
         *
         * @param int $submission_id The ID of the submission to delete.
         * @return int|false The number of rows deleted, or false on error.
         */
        public function delete_submission_from_queue($submission_id)
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            return $wpdb->delete(
                $table_name,
                array('id' => $submission_id),
                array('%d')
            );
        }

        /**
         * Revert timed-out submissions.
         */
        public function revert_timed_out_submissions()
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';
            $timeout    = 5 * MINUTE_IN_SECONDS;

            // processing_started_at is stored in UTC, so compare in UTC.
            $sql = $wpdb->prepare(
                "UPDATE $table_name SET status = 'pending', processing_started_at = NULL, claim_token = NULL WHERE status = 'processing' AND processing_started_at < %s",
                gmdate('Y-m-d H:i:s', time() - $timeout)
            );

            $wpdb->query($sql);
        }

        /**
         * Clear the queue.
         */
        public function clear_queue()
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';
            $wpdb->query("TRUNCATE TABLE $table_name");
        }

        /**
         * Get submissions from the queue.
         *
         * @param array $args Arguments for retrieving submissions.
         * @return array An array of submission objects.
         */
        public function get_submissions_from_queue($args = array())
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $defaults = array(
                'per_page' => 20,
                'page'     => 1,
                'orderby'  => 'created_at',
                'order'    => 'DESC',
                'form_id'  => 0,
                'status'   => 'completed',
            );

            $args = wp_parse_args($args, $defaults);

            $orderby = $this->sanitize_orderby($args['orderby'], 'queue');
            $order   = $this->sanitize_order($args['order']);

            $sql = "SELECT * FROM $table_name WHERE form_id = %d AND status = %s";

            $sql .= " ORDER BY $orderby $order";
            $sql .= " LIMIT " . absint($args['per_page']);
            $sql .= " OFFSET " . absint(($args['page'] - 1) * $args['per_page']);

            $results = $wpdb->get_results($wpdb->prepare($sql, $args['form_id'], $args['status']));

            foreach ($results as $result) {
                $result->submission_data = $this->decode_data($result->submission_data);
            }

            return $results;
        }

        /**
         * Get the total number of submissions from the queue.
         *
         * @param array $args Arguments for retrieving submissions.
         * @return int
         */
        public function get_submissions_count_from_queue($args = array())
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_queue';

            $defaults = array(
                'form_id'  => 0,
                'status'   => 'completed',
            );

            $args = wp_parse_args($args, $defaults);

            $sql = "SELECT COUNT(id) FROM $table_name WHERE form_id = %d AND status = %s";

            return (int) $wpdb->get_var($wpdb->prepare($sql, $args['form_id'], $args['status']));
        }

        /**
         * Check if a value is unique for a specific field in a form.
         *
         * Resolved with one indexed lookup instead of loading and decoding
         * every submission of the form.
         *
         * @param int    $form_id    The ID of the form.
         * @param string $field_name The name of the field.
         * @param string $value      The value to check.
         * @return bool True if the value is unique, false otherwise.
         */
        public function is_value_unique($form_id, $field_name, $value)
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'akashic_form_field_values';

            // Only scalar values are indexed.
            if (! is_scalar($value)) {
                return true;
            }

            $found = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM $table_name WHERE form_id = %d AND field_name = %s AND value_hash = %s LIMIT 1",
                    $form_id,
                    $field_name,
                    hash('sha256', (string) $value)
                )
            );

            return null === $found;
        }
    }
}

new Akashic_Forms_DB();
