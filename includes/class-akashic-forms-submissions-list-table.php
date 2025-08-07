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

    class Akashic_Forms_Submissions_List_Table extends WP_List_Table {

        private $form_id;

        /**
         * Constructor.
         *
         * @param int $form_id The ID of the form.
         */
        public function __construct( $form_id ) {
            parent::__construct( array(
                'singular' => 'submission',
                'plural'   => 'submissions',
                'ajax'     => false,
            ) );
            $this->form_id = $form_id;
        }

        /**
         * Get a list of columns.
         *
         * @return array
         */
        public function get_columns() {
            $form_fields = get_post_meta( $this->form_id, '_akashic_form_fields', true );
            $columns = array(
                'cb' => '<input type="checkbox" />',
            );

            if ( ! empty( $form_fields ) ) {
                foreach ( $form_fields as $field ) {
                    if ( isset( $field['name'] ) && isset( $field['label'] ) ) {
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
                'submitted_at' => array( 'submitted_at', false ),
            );
            return $sortable_columns;
        }

        /**
         * Prepare the items for the table.
         */
        public function prepare_items() {
            $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

            $per_page = 20;
            $current_page = $this->get_pagenum();
            $offset = ( $current_page - 1 ) * $per_page;

            $db = new Akashic_Forms_DB();
            $all_submissions = $db->get_submissions( $this->form_id );

            // Manual pagination and sorting for now, as get_submissions doesn't support it directly.
            // In a real-world scenario, modify get_submissions to handle pagination and sorting at the DB level.
            if ( isset( $_GET['orderby'] ) && isset( $_GET['order'] ) ) {
                $orderby = sanitize_key( $_GET['orderby'] );
                $order = strtoupper( sanitize_key( $_GET['order'] ) );

                if ( 'submitted_at' === $orderby ) {
                    usort( $all_submissions, function( $a, $b ) use ( $order ) {
                        if ( 'ASC' === $order ) {
                            return strtotime( $a->submitted_at ) - strtotime( $b->submitted_at );
                        } else {
                            return strtotime( $b->submitted_at ) - strtotime( $a->submitted_at );
                        }
                    } );
                }
            }

            $total_items = count( $all_submissions );
            $this->items = array_slice( $all_submissions, $offset, $per_page );

            $this->set_pagination_args( array(
                'total_items' => $total_items,
                'per_page'    => $per_page,
                'total_pages' => ceil( $total_items / $per_page ),
            ) );
        }

        /**
         * Display the content for a column.
         *
         * @param object $item        The current item.
         * @param string $column_name The name of the column to display.
         * @return string
         */
        protected function column_default( $item, $column_name ) {
            if ( 'submitted_at' === $column_name ) {
                return $item->submitted_at;
            } elseif ( isset( $item->submission_data[ $column_name ] ) ) {
                return esc_html( $item->submission_data[ $column_name ] );
            }
            return '';
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

    }

}
