<?php
/**
 * Plugin Name: Shared Document Manager
 * Plugin URI: https://example.com
 * Description: Gestor de documentos compartidos con carpetas jerárquicas y permisos por usuario individual.
 * Version: 1.0.0
 * Author: Cursor Agent
 * Text Domain: shared-docs-manager
 * Domain Path: /languages
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SHARED_DOCS_VERSION', '1.0.0');
define('SHARED_DOCS_FILE', __FILE__);
define('SHARED_DOCS_PATH', plugin_dir_path(__FILE__));
define('SHARED_DOCS_URL', plugin_dir_url(__FILE__));

require_once SHARED_DOCS_PATH . 'src/Core/Autoloader.php';
\SharedDocsManager\Core\Autoloader::register();

register_activation_hook(__FILE__, array('\SharedDocsManager\Core\Activator', 'activate'));
register_deactivation_hook(__FILE__, array('\SharedDocsManager\Core\Deactivator', 'deactivate'));

add_action(
    'plugins_loaded',
    static function () {
        \SharedDocsManager\Core\Plugin::instance()->boot();
    }
);

if (! function_exists('shared_user_has_access')) {
    /**
     * Comprueba si un usuario tiene acceso a una carpeta o a cualquier carpeta.
     *
     * @param int      $user_id   ID del usuario.
     * @param int|null $folder_id ID de carpeta opcional.
     *
     * @return bool
     */
    function shared_user_has_access($user_id, $folder_id = null)
    {
        $plugin = \SharedDocsManager\Core\Plugin::instance();
        $plugin->boot();

        return $plugin
            ->permission_manager()
            ->user_has_access((int) $user_id, $folder_id !== null ? (int) $folder_id : null);
    }
}

if (! function_exists('shared_get_user_permissions_html')) {
    /**
     * Devuelve un HTML renderizado con los permisos de un usuario.
     *
     * @param int $user_id ID del usuario.
     *
     * @return string
     */
    function shared_get_user_permissions_html($user_id)
    {
        $plugin = \SharedDocsManager\Core\Plugin::instance();
        $plugin->boot();

        return $plugin
            ->permission_manager()
            ->get_user_permissions_html((int) $user_id);
    }
}
