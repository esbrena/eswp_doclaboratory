<?php

namespace SharedDocsManager\Helpers;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

class File_Helper
{
    /**
     * Nombre de carpeta protegida dentro de uploads.
     */
    const PROTECTED_SUBDIR = 'shared-docs-protected';

    /**
     * @var Activity_Logger
     */
    private $activity_logger;

    /**
     * Flag para redirigir upload_dir temporalmente.
     *
     * @var bool
     */
    private $use_protected_upload_dir = false;

    public function __construct(Activity_Logger $activity_logger)
    {
        $this->activity_logger = $activity_logger;
    }

    /**
     * Crea estructura de directorio protegido.
     *
     * @return void
     */
    public static function ensure_protected_directory()
    {
        $upload = wp_get_upload_dir();
        if (empty($upload['basedir'])) {
            return;
        }

        $protected_dir = trailingslashit($upload['basedir']) . self::PROTECTED_SUBDIR;
        if (! file_exists($protected_dir)) {
            wp_mkdir_p($protected_dir);
        }

        $index_file = trailingslashit($protected_dir) . 'index.php';
        if (! file_exists($index_file)) {
            file_put_contents($index_file, "<?php\n// Silence is golden.\n", LOCK_EX);
        }

        $htaccess = trailingslashit($protected_dir) . '.htaccess';
        if (! file_exists($htaccess)) {
            $rules = "Order allow,deny\nDeny from all\n";
            file_put_contents($htaccess, $rules, LOCK_EX);
        }

        $web_config = trailingslashit($protected_dir) . 'web.config';
        if (! file_exists($web_config)) {
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            $xml .= "<configuration>\n";
            $xml .= "    <system.webServer>\n";
            $xml .= "        <security>\n";
            $xml .= "            <authorization>\n";
            $xml .= "                <remove users=\"*\" roles=\"\" verbs=\"\" />\n";
            $xml .= "                <add accessType=\"Deny\" users=\"*\" />\n";
            $xml .= "            </authorization>\n";
            $xml .= "        </security>\n";
            $xml .= "    </system.webServer>\n";
            $xml .= "</configuration>\n";
            file_put_contents($web_config, $xml, LOCK_EX);
        }
    }

    /**
     * Sube archivo al área protegida y lo registra como attachment.
     *
     * @param array $file       Estructura $_FILES['file'].
     * @param int   $folder_id  Carpeta.
     * @param int   $uploaded_by Usuario que sube.
     *
     * @return int|WP_Error
     */
    public function upload_attachment_to_folder($file, $folder_id, $uploaded_by)
    {
        $folder_id = (int) $folder_id;
        $uploaded_by = (int) $uploaded_by;

        if (empty($file['tmp_name']) || $folder_id <= 0) {
            return new WP_Error('shared_docs_invalid_upload', __('Datos de subida inválidos.', 'shared-docs-manager'));
        }

        self::ensure_protected_directory();

        $this->use_protected_upload_dir = true;
        add_filter('upload_dir', array($this, 'filter_upload_dir'));
        $upload = wp_handle_upload(
            $file,
            array(
                'test_form' => false,
            )
        );
        remove_filter('upload_dir', array($this, 'filter_upload_dir'));
        $this->use_protected_upload_dir = false;

        if (isset($upload['error'])) {
            return new WP_Error('shared_docs_upload_error', $upload['error']);
        }

        $filename = isset($file['name']) ? $file['name'] : basename($upload['file']);
        $attachment_id = wp_insert_attachment(
            array(
                'guid'           => $upload['url'],
                'post_mime_type' => $upload['type'],
                'post_title'     => sanitize_text_field(pathinfo($filename, PATHINFO_FILENAME)),
                'post_content'   => '',
                'post_status'    => 'private',
                'post_author'    => $uploaded_by,
                'post_parent'    => $folder_id,
            ),
            $upload['file']
        );

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        if (! function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        if (! empty($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        update_post_meta($attachment_id, 'shared_folder_id', $folder_id);
        update_post_meta($attachment_id, '_shared_protected', 1);

        $this->activity_logger->log(
            $uploaded_by,
            (int) $attachment_id,
            'upload',
            array(
                'folder_id' => $folder_id,
                'filename'  => sanitize_file_name($filename),
            )
        );

        return (int) $attachment_id;
    }

    /**
     * Redirige uploads al subdirectorio protegido.
     *
     * @param array $dirs Config upload dir.
     *
     * @return array
     */
    public function filter_upload_dir($dirs)
    {
        if (! $this->use_protected_upload_dir) {
            return $dirs;
        }

        $dirs['subdir'] = '/' . self::PROTECTED_SUBDIR;
        $dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
        $dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];

        if (! file_exists($dirs['path'])) {
            wp_mkdir_p($dirs['path']);
        }

        return $dirs;
    }

    /**
     * Crea backup de archivo antes de sobrescritura.
     *
     * @param string $absolute_path Ruta absoluta.
     *
     * @return string|false
     */
    public function create_backup($absolute_path)
    {
        if (! file_exists($absolute_path)) {
            return false;
        }

        $backup_path = $absolute_path . '.bak-' . gmdate('YmdHis');
        if (copy($absolute_path, $backup_path)) {
            return $backup_path;
        }

        return false;
    }

    /**
     * Determina si un archivo es editable con Excel.
     *
     * @param string $filename Nombre de archivo.
     * @param string $mime_type Mime opcional.
     *
     * @return bool
     */
    public static function is_excel_file($filename, $mime_type = '')
    {
        $extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
        $excel_ext = array('xlsx', 'xls', 'xlsm', 'xlsb', 'csv', 'ods');

        if (in_array($extension, $excel_ext, true)) {
            return true;
        }

        $mime = strtolower((string) $mime_type);
        return (strpos($mime, 'spreadsheet') !== false || strpos($mime, 'excel') !== false || strpos($mime, 'csv') !== false);
    }
}
