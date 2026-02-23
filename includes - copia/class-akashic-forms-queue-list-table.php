<?php
/**
 * Queue List Table for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Akashic_Forms_Queue_List_Table' ) ) {

    class Akashic_Forms_Queue_List_Table extends WP_List_Table {

        private $modals = array();

        /**
         * Constructor.
         */
        public function __construct() {
            parent::__construct( array(
                'singular' => 'submission',
                'plural'   => 'submissions',
                'ajax'     => false,
            ) );

            add_action( 'admin_enqueue_scripts', function () {
                add_thickbox();
            } );
        }

        /**
         * Get the list of columns.
         *
         * @return array
         */
        public function get_columns() {
            return array(
                'cb'              => '<input type="checkbox" />',
                'form_id'         => __( 'Form ID', 'akashic-forms' ),
                'submission_data' => __( 'Submission Data', 'akashic-forms' ),
                'status'          => __( 'Status', 'akashic-forms' ),
                'response'        => __( 'Response', 'akashic-forms' ),
                'created_at'      => __( 'Created At', 'akashic-forms' ),
                'updated_at'      => __( 'Updated At', 'akashic-forms' ),
                'time_to_timeout' => __( 'Time to Timeout', 'akashic-forms' ),
                'actions'         => __( 'Actions', 'akashic-forms' ),
            );
        }

        /**
         * Get the sortable columns.
         *
         * @return array
         */
        public function get_sortable_columns() {
            return array(
                'form_id'    => array( 'form_id', false ),
                'status'     => array( 'status', false ),
                'created_at' => array( 'created_at', true ),
                'updated_at' => array( 'updated_at', false ),
            );
        }

        /**
         * Prepare the items for the table.
         */
        public function prepare_items() {
            $columns  = $this->get_columns();
            $hidden   = array();
            $sortable = $this->get_sortable_columns();
            $this->_column_headers = array( $columns, $hidden, $sortable );

            $per_page     = 20;
            $current_page = $this->get_pagenum();
            $search_term  = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : '';
            $status       = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : 'all';

            $db = new Akashic_Forms_DB();

            $args = array(
                'per_page' => $per_page,
                'page'     => $current_page,
                'orderby'  => isset( $_REQUEST['orderby'] ) ? sanitize_text_field( $_REQUEST['orderby'] ) : 'created_at',
                'order'    => isset( $_REQUEST['order'] ) ? sanitize_text_field( $_REQUEST['order'] ) : 'DESC',
                'search'   => $search_term,
                'status'   => $status,
            );

            $this->items = $db->get_all_submissions_from_queue( $args );
            $total_items = $db->get_queue_count( $status );

            $this->set_pagination_args( array(
                'total_items' => $total_items,
                'per_page'    => $per_page,
                'total_pages' => ceil( $total_items / $per_page ),
            ) );
        }

        /**
         * Render a single column.
         *
         * @param object $item
         * @param string $column_name
         * @return mixed
         */
        public function column_default( $item, $column_name ) {
            switch ( $column_name ) {
                case 'form_id':
                case 'status':
                case 'response':
                case 'created_at':
                case 'updated_at':
                    return $item->$column_name;
                case 'submission_data':
                    return '<pre>' . print_r( $item->submission_data, true ) . '</pre>';
                default:
                    return print_r( $item, true );
            }
        }

        /**
         * Render the time to timeout column.
         *
         * @param object $item
         * @return string
         */
        public function column_time_to_timeout( $item ) {
            if ( 'processing' === $item->status && isset( $item->processing_started_at ) ) {
                $timeout    = 5 * MINUTE_IN_SECONDS;
                $started_at = strtotime( $item->processing_started_at );
                $time_left  = $timeout - ( time() - $started_at );

                if ( $time_left > 0 ) {
                    return sprintf( '%d minutes and %d seconds', floor( $time_left / 60 ), $time_left % 60 );
                } else {
                    return __( 'Timed out', 'akashic-forms' );
                }
            }
        }

        /**
         * Render the checkbox column.
         *
         * @param object $item
         * @return string
         */
        public function column_cb( $item ) {
            return sprintf(
                '<input type="checkbox" name="submission[]" value="%s" />', $item->id
            );
        }

        /**
         * Display the search box.
         *
         * @param string $text
         * @param string $input_id
         */
        public function search_box( $text, $input_id ) {
            parent::search_box( $text, $input_id );
        }

        /**
         * Render the actions column.
         *
         * @param object $item
         * @return string
         */
        public function column_actions( $item ) {
            if ( 'failed' === $item->status && isset( $item->failure_reason ) ) {
                $modal_id = 'failure-reason-modal-' . $item->id;
                echo '<a href="#TB_inline?width=600&height=550&inlineId=' . $modal_id . '" class="thickbox button">View Reason</a>';
                $this->modals[$modal_id] = '<div id="' . $modal_id . '" style="display:none;"><h2>' . __( 'Failure Reason', 'akashic-forms' ) . '</h2><p>' . esc_html( $item->failure_reason ) . '</p></div>';
            }
        }

        /**
         * Display extra table navigation.
         *
         * @param string $which
         */
        protected function extra_tablenav( $which ) {
            if ( 'top' === $which ) {
                $current_status = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : 'all';
                ?>
                <div class="alignleft actions">
                    <form method="get">
                        <input type="hidden" name="page" value="<?php echo esc_attr( $_REQUEST['page'] ); ?>" />
                        <label for="filter-by-status" class="screen-reader-text"><?php _e( 'Filter by status', 'akashic-forms' ); ?></label>
                        <select name="status" id="filter-by-status">
                            <option value="all" <?php selected( $current_status, 'all' ); ?>><?php _e( 'All Statuses', 'akashic-forms' ); ?></option>
                            <option value="pending" <?php selected( $current_status, 'pending' ); ?>><?php _e( 'Pending', 'akashic-forms' ); ?></option>
                            <option value="processing" <?php selected( $current_status, 'processing' ); ?>><?php _e( 'Processing', 'akashic-forms' ); ?></option>
                            <option value="failed" <?php selected( $current_status, 'failed' ); ?>><?php _e( 'Failed', 'akashic-forms' ); ?></option>
                            <option value="completed" <?php selected( $current_status, 'completed' ); ?>><?php _e( 'Completed', 'akashic-forms' ); ?></option>
                        </select>
                        <?php submit_button( __( 'Filter', 'akashic-forms' ), 'button', 'filter_action', false ); ?>
                    </form>
                </div>
                <?php
            }
        }

        /**
         * Display the table.
         */
        public function display() {
            parent::display();
            echo implode( '', $this->modals );
        }
    }
}