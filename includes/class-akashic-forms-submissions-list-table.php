<?php
/**
 * Submissions List Table for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Akashic_Forms_Submissions_List_Table' ) ) {

    // We need to extend the WP_List_Table class
    if ( ! class_exists( 'WP_List_Table' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
    }

    class Akashic_Forms_Submissions_List_Table extends WP_List_Table {

        private $form_id;

        /**
         * Constructor.
         *
         * @param int $form_id The ID of the form.
         */
        public function __construct( $form_id ) {
            parent::__construct(
                array(
                    'singular' => 'submission',
                    'plural'   => 'submissions',
                    'ajax'     => false,
                )
            );
            $this->form_id = $form_id;
        }

        /**
         * Get a list of columns.
         *
         * @return array
         */
        public function get_columns() {
            $form_fields    = get_post_meta( $this->form_id, '_akashic_form_fields', true );
            $columns        = array(
                'cb' => '<input type="checkbox" />',
            );
            $columns['id'] = __( 'ID', 'akashic-forms' );

            if ( ! empty( $form_fields ) ) {
                foreach ( $form_fields as $field ) {
                    if ( isset( $field['name'] ) && isset( $field['label'] ) && ! empty( $field['name'] ) ) {
                        $columns[ sanitize_key( $field['name'] ) ] = sanitize_text_field( $field['label'] );
                    }
                }
            }
            $columns['submitted_at'] = __( 'Submitted At', 'akashic-forms' );
            return $columns;
        }

        /**
         * Get a list of sortable columns.
         *
         * @return array
         */
        protected function get_sortable_columns() {
            $sortable_columns = array(
                'id'           => array( 'id', false ),
                'submitted_at' => array( 'submitted_at', false ),
            );
            return $sortable_columns;
        }

        /**
         * Prepare the items for the table.
         */
        public function prepare_items() {
            $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'id' );

            $per_page     = 20;
            $current_page = $this->get_pagenum();
            $offset       = ( $current_page - 1 ) * $per_page;

            $db              = new Akashic_Forms_DB();
            $all_submissions = $db->get_submissions( $this->form_id );

            // Sorting
            $orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'submitted_at';
            $order   = isset( $_GET['order'] ) ? strtoupper( sanitize_key( $_GET['order'] ) ) : 'DESC';

            usort(
                $all_submissions,
                function ( $a, $b ) use ( $orderby, $order ) {
                    $a_val = $a->$orderby;
                    $b_val = $b->$orderby;
                    if ( 'submitted_at' === $orderby ) {
                        $a_val = strtotime( $a_val );
                        $b_val = strtotime( $b_val );
                    }
                    if ( $a_val === $b_val ) {
                        return 0;
                    }
                    if ( 'ASC' === $order ) {
                        return $a_val < $b_val ? -1 : 1;
                    }
                    return $a_val > $b_val ? -1 : 1;
                }
            );

            $total_items = count( $all_submissions );
            $this->items = array_slice( $all_submissions, $offset, $per_page );

            $this->set_pagination_args(
                array(
                    'total_items' => $total_items,
                    'per_page'    => $per_page,
                    'total_pages' => ceil( $total_items / $per_page ),
                )
            );
        }

        /**
         * Define what data to show on each column of the table
         *
         * @param  array  $item        Data
         * @param  string $column_name - Current column name
         *
         * @return mixed
         */
        public function column_default( $item, $column_name ) {
            if ( isset( $item->submission_data[ $column_name ] ) ) {
                $value = $item->submission_data[ $column_name ];
                if ( is_array( $value ) ) {
                    return implode( ', ', $value );
                }
                return esc_html( $value );
            }
            return isset( $item->$column_name ) ? $item->$column_name : '';
        }

        /**
         * Handles the checkbox column output.
         *
         * @param object $item The current item.
         * @return string
         */
        protected function column_cb( $item ) {
            return sprintf(
                '<input type="checkbox" name="submission[]" value="%s" />',
                $item->id
            );
        }

        /**
         * Add row actions to the ID column.
         *
         * @param object $item The item being acted upon.
         * @return string
         */
        protected function column_id( $item ) {
            $delete_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'page'          => 'akashic-forms-submissions',
                        'action'        => 'delete_submission',
                        'form_id'       => $this->form_id,
                        'submission_id' => $item->id,
                    ),
                    admin_url( 'admin.php' )
                ),
                'akashic_delete_submission_' . $item->id
            );

            $actions = array(
                'delete' => sprintf(
                    '<a href="%s" style="color:#a00;" onclick="return confirm(%s);">%s</a>',
                    esc_url( $delete_url ),
                    esc_js( __( 'Are you sure you want to delete this submission?', 'akashic-forms' ) ),
                    __( 'Delete', 'akashic-forms' )
                ),
            );

            return sprintf( '%1$s %2$s', $item->id, $this->row_actions( $actions ) );
        }
    }
}
