<?php
/**
 * Custom Post Type for Akashic Forms.
 *
 * @package AkashicForms
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Akashic_Forms_CPT' ) ) {

    class Akashic_Forms_CPT {

        /**
         * Constructor.
         */
        public function __construct() {
            add_action( 'init', array( $this, 'register_akashic_forms_cpt' ) );
        }

        /**
         * Register the custom post type.
         */
        public function register_akashic_forms_cpt() {
            $labels = array(
                'name'                  => _x( 'Forms', 'Post Type General Name', 'akashic-forms' ),
                'singular_name'         => _x( 'Form', 'Post Type Singular Name', 'akashic-forms' ),
                'menu_name'             => __( 'Akashic Forms', 'akashic-forms' ),
                'name_admin_bar'        => __( 'Form', 'akashic-forms' ),
                'archives'              => __( 'Form Archives', 'akashic-forms' ),
                'attributes'            => __( 'Form Attributes', 'akashic-forms' ),
                'parent_item_colon'     => __( 'Parent Form:', 'akashic-forms' ),
                'all_items'             => __( 'All Forms', 'akashic-forms' ),
                'add_new_item'          => __( 'Add New Form', 'akashic-forms' ),
                'add_new'               => __( 'Add New', 'akashic-forms' ),
                'new_item'              => __( 'New Form', 'akashic-forms' ),
                'edit_item'             => __( 'Edit Form', 'akashic-forms' ),
                'update_item'           => __( 'Update Form', 'akashic-forms' ),
                'view_item'             => __( 'View Form', 'akashic-forms' ),
                'view_items'            => __( 'View Forms', 'akashic-forms' ),
                'search_items'          => __( 'Search Form', 'akashic-forms' ),
                'not_found'             => __( 'Not found', 'akashic-forms' ),
                'not_found_in_trash'    => __( 'Not found in Trash', 'akashic-forms' ),
                'featured_image'        => __( 'Featured Image', 'akashic-forms' ),
                'set_featured_image'    => __( 'Set featured image', 'akashic-forms' ),
                'remove_featured_image' => __( 'Remove featured image', 'akashic-forms' ),
                'use_featured_image'    => __( 'Use as featured image', 'akashic-forms' ),
                'insert_into_item'      => __( 'Insert into form', 'akashic-forms' ),
                'uploaded_to_this_item' => __( 'Uploaded to this form', 'akashic-forms' ),
                'items_list'            => __( 'Forms list', 'akashic-forms' ),
                'items_list_navigation' => __( 'Forms list navigation', 'akashic-forms' ),
                'filter_items_list'     => __( 'Filter forms list', 'akashic-forms' ),
            );
            $args = array(
                'label'                 => __( 'Form', 'akashic-forms' ),
                'description'           => __( 'Custom post type for managing forms.', 'akashic-forms' ),
                'labels'                => $labels,
                'supports'              => array( 'title' ),
                'hierarchical'          => false,
                'public'                => true,
                'show_ui'               => true,
                'show_in_menu'          => 'akashic-forms',
                'show_in_admin_bar'     => true,
                'show_in_nav_menus'     => true,
                'can_export'            => true,
                'has_archive'           => false,
                'exclude_from_search'   => true,
                'publicly_queryable'    => false,
                'capability_type'       => 'post',
                'show_in_rest'          => true,
                'rewrite'               => array( 'slug' => 'akashic_forms' ),
            );
            register_post_type( 'akashic_forms', $args );
        }

    }

}

new Akashic_Forms_CPT();