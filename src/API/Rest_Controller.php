<?php

namespace SharedDocsManager\API;

use SharedDocsManager\Helpers\Activity_Logger;
use SharedDocsManager\Helpers\File_Helper;
use SharedDocsManager\Permissions\Permission_Manager;
use WP_Error;
use WP_REST_Request;

if (! defined('ABSPATH')) {
    exit;
}

class Rest_Controller
{
    /**
     * @var string
     */
    private $namespace = 'shared-docs/v1';

    /**
     * @var Permission_Manager
     */
    private $permission_manager;

    /**
     * @var Activity_Logger
     */
    private $activity_logger;

    /**
     * @var File_Helper
     */
    private $file_helper;

    public function __construct(
        Permission_Manager $permission_manager,
        Activity_Logger $activity_logger,
        File_Helper $file_helper
    ) {
        $this->permission_manager = $permission_manager;
        $this->activity_logger = $activity_logger;
        $this->file_helper = $file_helper;
    }

    /**
     * Registra hooks de API.
     *
     * @return void
     */
    public function register_hooks()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Registra rutas REST.
     *
     * @return void
     */
    public function register_routes()
    {
        register_rest_route(
            $this->namespace,
            '/folders',
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'get_folders'),
                'permission_callback' => array($this, 'rest_permission_logged_in'),
                'args'                => array(
                    'parent_id' => array(
                        'required'          => false,
                        'validate_callback' => static function ($value) {
                            return is_numeric($value);
                        },
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/files',
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'get_files'),
                'permission_callback' => array($this, 'rest_permission_logged_in'),
                'args'                => array(
                    'folder_id' => array(
                        'required'          => true,
                        'validate_callback' => static function ($value) {
                            return is_numeric($value);
                        },
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/folder/(?P<id>\d+)/breadcrumb',
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'get_breadcrumb'),
                'permission_callback' => array($this, 'rest_permission_logged_in'),
            )
        );

        register_rest_route(
            $this->namespace,
            '/download/(?P<id>\d+)',
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'download_file'),
                'permission_callback' => array($this, 'rest_permission_logged_in'),
            )
        );

        register_rest_route(
            $this->namespace,
            '/excel/save',
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'save_excel'),
                'permission_callback' => array($this, 'rest_permission_logged_in'),
            )
        );
    }

    /**
     * Permiso base de autenticación para endpoints.
     *
     * @return bool
     */
    public function rest_permission_logged_in()
    {
        return is_user_logged_in();
    }

    /**
     * Lista carpetas hijas visibles para el usuario.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return array|WP_Error
     */
    public function get_folders(WP_REST_Request $request)
    {
        $user_id = get_current_user_id();
        $parent_param = $request->get_param('parent_id');
        $parent_id = null;
        if ($parent_param !== null && $parent_param !== '') {
            $parent_id = (int) $parent_param;
        }

        $folders = $this->permission_manager->get_visible_folders_for_user($user_id, $parent_id);
        $response = array();

        foreach ($folders as $folder) {
            $response[] = array(
                'id'           => (int) $folder->ID,
                'title'        => $folder->post_title,
                'parent_id'    => (int) $folder->post_parent,
                'has_children' => $this->permission_manager->folder_has_visible_children($user_id, (int) $folder->ID),
            );
        }

        return rest_ensure_response($response);
    }

    /**
     * Lista archivos de una carpeta.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return array|WP_Error
     */
    public function get_files(WP_REST_Request $request)
    {
        $folder_id = (int) $request->get_param('folder_id');
        $user_id = get_current_user_id();

        if (! $this->permission_manager->user_can_view_folder($user_id, $folder_id)) {
            return new WP_Error(
                'shared_docs_forbidden',
                __('No tienes acceso a esta carpeta.', 'shared-docs-manager'),
                array('status' => 403)
            );
        }

        $files = get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => array('inherit', 'private'),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'meta_query'     => array(
                    array(
                        'key'     => 'shared_folder_id',
                        'value'   => $folder_id,
                        'compare' => '=',
                    ),
                ),
            )
        );

        $response = array();

        foreach ($files as $file) {
            $file_id = (int) $file->ID;
            $can_read = $this->permission_manager->user_can_access_file($user_id, $file_id, 'can_read');
            if (! $can_read) {
                continue;
            }

            $path = get_attached_file((int) $file->ID);
            $filename = $path ? basename($path) : $file->post_title;
            $mime_type = (string) get_post_mime_type((int) $file->ID);
            $is_excel = File_Helper::is_excel_file($filename, $mime_type);
            $size = ($path && file_exists($path)) ? (int) filesize($path) : 0;
            $can_download = $this->permission_manager->user_can_access_file($user_id, $file_id, 'can_download');
            $can_edit_excel = $this->permission_manager->user_can_access_file($user_id, $file_id, 'can_edit_excel');

            $response[] = array(
                'id'             => $file_id,
                'title'          => $file->post_title,
                'filename'       => $filename,
                'mime_type'      => $mime_type,
                'size'           => $size,
                'modified'       => mysql2date('c', $file->post_modified_gmt),
                'can_download'   => (bool) $can_download,
                'is_excel'       => (bool) $is_excel,
                'can_edit_excel' => (bool) ($is_excel && $can_edit_excel),
            );
        }

        return rest_ensure_response($response);
    }

    /**
     * Devuelve breadcrumb para una carpeta.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return array|WP_Error
     */
    public function get_breadcrumb(WP_REST_Request $request)
    {
        $folder_id = (int) $request['id'];
        $user_id = get_current_user_id();

        if (! $this->permission_manager->user_can_view_folder($user_id, $folder_id)) {
            return new WP_Error(
                'shared_docs_forbidden',
                __('No tienes acceso a esta carpeta.', 'shared-docs-manager'),
                array('status' => 403)
            );
        }

        return rest_ensure_response($this->permission_manager->get_breadcrumb($user_id, $folder_id));
    }

    /**
     * Descarga un archivo validando permisos.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_Error|void
     */
    public function download_file(WP_REST_Request $request)
    {
        $file_id = (int) $request['id'];
        $user_id = get_current_user_id();

        if (! $this->permission_manager->user_can_access_file($user_id, $file_id, 'can_download')) {
            return new WP_Error(
                'shared_docs_forbidden',
                __('No tienes permisos para descargar este archivo.', 'shared-docs-manager'),
                array('status' => 403)
            );
        }

        $path = get_attached_file($file_id);
        if (! $path || ! file_exists($path)) {
            return new WP_Error(
                'shared_docs_not_found',
                __('Archivo no encontrado.', 'shared-docs-manager'),
                array('status' => 404)
            );
        }

        $this->activity_logger->log(
            $user_id,
            $file_id,
            'download',
            array(
                'folder_id' => $this->permission_manager->get_folder_id_from_file($file_id),
            )
        );

        $mime_type = (string) get_post_mime_type($file_id);
        if ($mime_type === '') {
            $mime_type = 'application/octet-stream';
        }

        $download_name = basename($path);
        $download_name = str_replace('"', '', $download_name);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $download_name . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Pragma: public');

        readfile($path);
        exit;
    }

    /**
     * Guarda cambios sobre archivo Excel.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return array|WP_Error
     */
    public function save_excel(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = $request->get_body_params();
        }

        $file_id = isset($params['file_id']) ? (int) $params['file_id'] : 0;
        $payload = isset($params['workbook_base64']) ? (string) $params['workbook_base64'] : '';
        $user_id = get_current_user_id();

        if ($file_id <= 0 || $payload === '') {
            return new WP_Error(
                'shared_docs_invalid_payload',
                __('Solicitud inválida para guardar Excel.', 'shared-docs-manager'),
                array('status' => 400)
            );
        }

        if (! $this->permission_manager->user_can_access_file($user_id, $file_id, 'can_edit_excel')) {
            return new WP_Error(
                'shared_docs_forbidden',
                __('No tienes permisos para editar este archivo Excel.', 'shared-docs-manager'),
                array('status' => 403)
            );
        }

        $path = get_attached_file($file_id);
        if (! $path || ! file_exists($path)) {
            return new WP_Error(
                'shared_docs_not_found',
                __('Archivo no encontrado.', 'shared-docs-manager'),
                array('status' => 404)
            );
        }

        $filename = basename($path);
        $mime = (string) get_post_mime_type($file_id);
        if (! File_Helper::is_excel_file($filename, $mime)) {
            return new WP_Error(
                'shared_docs_not_excel',
                __('El archivo no es compatible con edición Excel.', 'shared-docs-manager'),
                array('status' => 400)
            );
        }

        if (strpos($payload, ',') !== false) {
            $payload = substr($payload, strpos($payload, ',') + 1);
        }

        $binary = base64_decode($payload, true);
        if ($binary === false) {
            return new WP_Error(
                'shared_docs_decode_error',
                __('No se pudo decodificar el archivo Excel.', 'shared-docs-manager'),
                array('status' => 400)
            );
        }

        $backup_path = $this->file_helper->create_backup($path);
        $saved = file_put_contents($path, $binary, LOCK_EX);
        if ($saved === false) {
            return new WP_Error(
                'shared_docs_write_error',
                __('No se pudo guardar el archivo en disco.', 'shared-docs-manager'),
                array('status' => 500)
            );
        }

        wp_update_post(
            array(
                'ID'            => $file_id,
                'post_modified' => current_time('mysql'),
            )
        );

        $this->activity_logger->log(
            $user_id,
            $file_id,
            'edit',
            array(
                'folder_id'       => $this->permission_manager->get_folder_id_from_file($file_id),
                'backup_created'  => ! empty($backup_path),
                'saved_file_size' => strlen($binary),
            )
        );

        return rest_ensure_response(
            array(
                'success'        => true,
                'backup_created' => ! empty($backup_path),
            )
        );
    }
}
