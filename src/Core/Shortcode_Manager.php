<?php

namespace SharedDocsManager\Core;

use SharedDocsManager\Front\Front_Controller;
use SharedDocsManager\Permissions\Permission_Manager;

if (! defined('ABSPATH')) {
    exit;
}

class Shortcode_Manager
{
    /**
     * @var Permission_Manager
     */
    private $permission_manager;

    /**
     * @var Front_Controller
     */
    private $front_controller;

    public function __construct(Permission_Manager $permission_manager, Front_Controller $front_controller)
    {
        $this->permission_manager = $permission_manager;
        $this->front_controller = $front_controller;
    }

    /**
     * Registra hooks.
     *
     * @return void
     */
    public function register_hooks()
    {
        add_action('init', array($this, 'register_shortcode'));
    }

    /**
     * Registra shortcode principal.
     *
     * @return void
     */
    public function register_shortcode()
    {
        add_shortcode('shared_document_manager', array($this, 'render_shortcode'));
    }

    /**
     * Renderiza el shortcode.
     *
     * @param array $atts Atributos.
     *
     * @return string
     */
    public function render_shortcode($atts = array())
    {
        $atts = shortcode_atts(
            array(
                'no_access_message' => '',
                'guest_message'     => __('Debes iniciar sesión para ver los documentos compartidos.', 'shared-docs-manager'),
            ),
            $atts,
            'shared_document_manager'
        );

        if (! is_user_logged_in()) {
            return '<div class="shared-docs-message shared-docs-guest-message">' . esc_html($atts['guest_message']) . '</div>';
        }

        $user_id = get_current_user_id();
        if (! $this->permission_manager->user_has_access($user_id)) {
            $default_message = get_option(
                'shared_docs_no_access_message',
                __('No tienes acceso a carpetas compartidas.', 'shared-docs-manager')
            );
            $message = $atts['no_access_message'] !== '' ? $atts['no_access_message'] : $default_message;

            if ($message === '') {
                return '';
            }

            return '<div class="shared-docs-message shared-docs-no-access">' . esc_html($message) . '</div>';
        }

        return $this->front_controller->render_manager();
    }
}
