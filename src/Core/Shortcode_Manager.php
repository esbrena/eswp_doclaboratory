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
        add_shortcode('shared_document_manager', array($this, 'render_full_shortcode'));
        add_shortcode('shared_document_drive', array($this, 'render_full_shortcode'));
        add_shortcode('shared_document_overview', array($this, 'render_overview_shortcode'));
        add_shortcode('shared_document_manager_overview', array($this, 'render_overview_shortcode'));
        add_shortcode('shared_document_access_list', array($this, 'render_access_list_shortcode'));
        add_shortcode('shared_document_browser', array($this, 'render_access_list_shortcode'));
    }

    /**
     * Renderiza shortcode de navegación completa tipo Drive.
     *
     * @param array $atts Atributos.
     *
     * @return string
     */
    public function render_full_shortcode($atts = array())
    {
        $atts = shortcode_atts(
            array(
                'no_access_message' => '',
                'guest_message'     => __('Debes iniciar sesión para ver los documentos compartidos.', 'shared-docs-manager'),
                'hide_when_no_access' => 'no',
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
            $message = trim((string) ($atts['no_access_message'] !== '' ? $atts['no_access_message'] : $default_message));
            $hide_when_no_access = in_array(strtolower((string) $atts['hide_when_no_access']), array('yes', '1', 'true'), true);

            if ($hide_when_no_access) {
                return '';
            }

            if ($message === '') {
                $message = __('No tienes acceso a carpetas compartidas.', 'shared-docs-manager');
            }

            return '<div class="shared-docs-message shared-docs-no-access">' . esc_html($message) . '</div>';
        }

        return $this->front_controller->render_manager();
    }

    /**
     * Renderiza shortcode resumen de últimas carpetas y archivos.
     *
     * @param array $atts Atributos.
     *
     * @return string
     */
    public function render_overview_shortcode($atts = array())
    {
        $atts = shortcode_atts(
            array(
                'folders_limit'      => 6,
                'files_limit'        => 6,
                'manager_url'        => '',
                'button_label'       => __('Ver y navegar', 'shared-docs-manager'),
                'title'              => __('Documentos compartidos', 'shared-docs-manager'),
                'guest_message'      => __('Debes iniciar sesión para ver los documentos compartidos.', 'shared-docs-manager'),
                'no_access_message'  => __('No tienes acceso a carpetas compartidas.', 'shared-docs-manager'),
                'hide_when_no_access'=> 'no',
            ),
            $atts,
            'shared_document_overview'
        );

        if (! is_user_logged_in()) {
            return '<div class="shared-docs-message shared-docs-guest-message">' . esc_html($atts['guest_message']) . '</div>';
        }

        $user_id = get_current_user_id();
        if (! $this->permission_manager->user_has_access($user_id)) {
            $hide_when_no_access = in_array(strtolower((string) $atts['hide_when_no_access']), array('yes', '1', 'true'), true);
            if ($hide_when_no_access) {
                return '';
            }

            $message = trim((string) $atts['no_access_message']);
            if ($message === '') {
                $message = __('No tienes acceso a carpetas compartidas.', 'shared-docs-manager');
            }

            return '<div class="shared-docs-message shared-docs-no-access">' . esc_html($message) . '</div>';
        }

        $atts['folders_limit'] = max(1, (int) $atts['folders_limit']);
        $atts['files_limit'] = max(1, (int) $atts['files_limit']);
        $atts['manager_url'] = $atts['manager_url'] !== '' ? esc_url_raw($atts['manager_url']) : $this->default_manager_url();

        return $this->front_controller->render_overview($user_id, $atts);
    }

    /**
     * Renderiza listado accesible en modo tabla o lista.
     *
     * @param array $atts Atributos.
     *
     * @return string
     */
    public function render_access_list_shortcode($atts = array())
    {
        $atts = shortcode_atts(
            array(
                'mode'                => 'table',
                'title'               => __('Mis documentos', 'shared-docs-manager'),
                'guest_message'       => __('Debes iniciar sesión para ver los documentos compartidos.', 'shared-docs-manager'),
                'no_access_message'   => __('No tienes acceso a carpetas compartidas.', 'shared-docs-manager'),
                'hide_when_no_access' => 'no',
            ),
            $atts,
            'shared_document_access_list'
        );

        if (! is_user_logged_in()) {
            return '<div class="shared-docs-message shared-docs-guest-message">' . esc_html($atts['guest_message']) . '</div>';
        }

        $user_id = get_current_user_id();
        if (! $this->permission_manager->user_has_access($user_id)) {
            $hide_when_no_access = in_array(strtolower((string) $atts['hide_when_no_access']), array('yes', '1', 'true'), true);
            if ($hide_when_no_access) {
                return '';
            }

            $message = trim((string) $atts['no_access_message']);
            if ($message === '') {
                $message = __('No tienes acceso a carpetas compartidas.', 'shared-docs-manager');
            }

            return '<div class="shared-docs-message shared-docs-no-access">' . esc_html($message) . '</div>';
        }

        return $this->front_controller->render_access_browser($user_id, $atts);
    }

    /**
     * URL por defecto para botón "Ver y navegar".
     *
     * @return string
     */
    private function default_manager_url()
    {
        if (function_exists('get_permalink') && is_singular()) {
            return (string) get_permalink() . '#shared-docs-manager';
        }

        return (string) home_url('/');
    }
}
