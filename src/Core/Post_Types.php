<?php

namespace SharedDocsManager\Core;

if (! defined('ABSPATH')) {
    exit;
}

class Post_Types
{
    /**
     * Registra hooks.
     *
     * @return void
     */
    public function register_hooks()
    {
        add_action('init', array($this, 'register'));
    }

    /**
     * Registra el CPT jerárquico de carpetas.
     *
     * @return void
     */
    public static function register()
    {
        $labels = array(
            'name'          => __('Carpetas compartidas', 'shared-docs-manager'),
            'singular_name' => __('Carpeta compartida', 'shared-docs-manager'),
            'add_new_item'  => __('Añadir carpeta', 'shared-docs-manager'),
            'edit_item'     => __('Editar carpeta', 'shared-docs-manager'),
            'menu_name'     => __('Carpetas compartidas', 'shared-docs-manager'),
        );

        register_post_type(
            'shared_folder',
            array(
                'labels'              => $labels,
                'public'              => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'show_in_rest'        => true,
                'hierarchical'        => true,
                'supports'            => array('title'),
                'capability_type'     => 'post',
                'map_meta_cap'        => true,
                'rewrite'             => false,
                'query_var'           => false,
                'exclude_from_search' => true,
            )
        );
    }
}
