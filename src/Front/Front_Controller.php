<?php

namespace SharedDocsManager\Front;

use SharedDocsManager\Helpers\Icon_Helper;
use SharedDocsManager\Permissions\Permission_Manager;

if (! defined('ABSPATH')) {
    exit;
}

class Front_Controller
{
    /**
     * @var Permission_Manager
     */
    private $permission_manager;

    /**
     * @var bool
     */
    private $localized = false;

    public function __construct(Permission_Manager $permission_manager)
    {
        $this->permission_manager = $permission_manager;
    }

    /**
     * Registra hooks frontend.
     *
     * @return void
     */
    public function register_hooks()
    {
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_assets_for_dynamic_dashboards'), 20);
    }

    /**
     * Registra CSS/JS.
     *
     * @return void
     */
    public function register_assets()
    {
        wp_register_style(
            'shared-docs-front',
            SHARED_DOCS_URL . 'assets/css/front.css',
            array(),
            SHARED_DOCS_VERSION
        );

        wp_register_script(
            'shared-docs-sheetjs',
            'https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js',
            array(),
            '0.20.2',
            true
        );

        wp_register_script(
            'shared-docs-front',
            SHARED_DOCS_URL . 'assets/js/front-manager.js',
            array('shared-docs-sheetjs'),
            SHARED_DOCS_VERSION,
            true
        );
    }

    /**
     * Encola assets también fuera del render directo del shortcode
     * para paneles/dashboards que inyectan HTML por AJAX.
     *
     * @return void
     */
    public function maybe_enqueue_assets_for_dynamic_dashboards()
    {
        if (is_admin() || ! is_user_logged_in()) {
            return;
        }

        wp_enqueue_style('shared-docs-front');
        wp_enqueue_script('shared-docs-sheetjs');
        wp_enqueue_script('shared-docs-front');
        $this->localize_front_script();
    }

    /**
     * Renderiza HTML principal del gestor.
     *
     * @return string
     */
    public function render_manager()
    {
        $this->enqueue_assets();
        $initial_folder_id = isset($_GET['sd_folder']) ? (int) $_GET['sd_folder'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $initial_file_id = isset($_GET['sd_file']) ? (int) $_GET['sd_file'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        ob_start();
        ?>
        <div id="shared-docs-manager" class="shared-docs-manager" data-initial-folder-id="<?php echo esc_attr((string) $initial_folder_id); ?>" data-initial-file-id="<?php echo esc_attr((string) $initial_file_id); ?>">
            <div class="shared-docs-toolbar">
                <div class="shared-docs-toolbar-title">
                    <h3><?php esc_html_e('Documentos compartidos', 'shared-docs-manager'); ?></h3>
                    <p><?php esc_html_e('Navega por carpetas y descarga archivos autorizados.', 'shared-docs-manager'); ?></p>
                </div>
                <button type="button" class="shared-docs-btn shared-docs-btn-secondary" data-action="go-root">
                    <?php esc_html_e('Ir a inicio', 'shared-docs-manager'); ?>
                </button>
            </div>

            <nav class="shared-docs-breadcrumb" aria-label="<?php esc_attr_e('Ruta de carpetas', 'shared-docs-manager'); ?>"></nav>

            <div class="shared-docs-content">
                <section class="shared-docs-section" data-section="folders">
                    <h4><?php esc_html_e('Carpetas', 'shared-docs-manager'); ?></h4>
                    <div class="shared-docs-grid" data-region="folders"></div>
                </section>

                <section class="shared-docs-section" data-section="files">
                    <h4><?php esc_html_e('Archivos', 'shared-docs-manager'); ?></h4>
                    <div class="shared-docs-grid" data-region="files"></div>
                </section>

                <div class="shared-docs-empty shared-docs-directory-empty" data-region="directory-empty" hidden></div>
            </div>
        </div>

        <div id="shared-docs-file-preview-modal" class="shared-docs-modal shared-docs-modal--fullscreen" hidden>
            <div class="shared-docs-modal__backdrop" data-action="close-preview-modal"></div>
            <div class="shared-docs-modal__content shared-docs-modal__content--fullscreen" role="dialog" aria-modal="true">
                <header class="shared-docs-modal__header">
                    <h4 class="shared-docs-modal__title" data-region="preview-title"><?php esc_html_e('Vista previa', 'shared-docs-manager'); ?></h4>
                    <button type="button" class="shared-docs-modal__close" data-action="close-preview-modal">&times;</button>
                </header>
                <div class="shared-docs-modal__body" data-region="preview-body"></div>
                <footer class="shared-docs-modal__footer">
                    <button type="button" class="shared-docs-btn shared-docs-btn-secondary" data-action="close-preview-modal">
                        <?php esc_html_e('Cerrar', 'shared-docs-manager'); ?>
                    </button>
                    <button type="button" class="shared-docs-btn shared-docs-btn-primary" data-action="preview-download">
                        <?php esc_html_e('Descargar', 'shared-docs-manager'); ?>
                    </button>
                </footer>
            </div>
        </div>

        <div id="shared-docs-excel-modal" class="shared-docs-modal shared-docs-modal--fullscreen" hidden>
            <div class="shared-docs-modal__backdrop" data-action="close-modal"></div>
            <div class="shared-docs-modal__content shared-docs-modal__content--fullscreen" role="dialog" aria-modal="true">
                <header class="shared-docs-modal__header">
                    <h4 class="shared-docs-modal__title"><?php esc_html_e('Editar Excel', 'shared-docs-manager'); ?></h4>
                    <button type="button" class="shared-docs-modal__close" data-action="close-modal">&times;</button>
                </header>
                <div class="shared-docs-modal__body">
                    <p class="shared-docs-modal__hint" data-region="excel-hint"><?php esc_html_e('Haz clic sobre una celda para editarla.', 'shared-docs-manager'); ?></p>
                    <div class="shared-docs-excel-table-wrap">
                        <table class="shared-docs-excel-table" data-region="excel-table"></table>
                    </div>
                </div>
                <footer class="shared-docs-modal__footer">
                    <button type="button" class="shared-docs-btn shared-docs-btn-secondary" data-action="close-modal">
                        <?php esc_html_e('Cancelar', 'shared-docs-manager'); ?>
                    </button>
                    <button type="button" class="shared-docs-btn shared-docs-btn-primary" data-action="save-excel">
                        <?php esc_html_e('Guardar cambios', 'shared-docs-manager'); ?>
                    </button>
                </footer>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Renderiza resumen rápido con últimas carpetas y archivos.
     *
     * @param int   $user_id Usuario.
     * @param array $atts    Configuración de salida.
     *
     * @return string
     */
    public function render_overview($user_id, $atts = array())
    {
        $user_id = (int) $user_id;
        wp_enqueue_style('shared-docs-front');

        $folders_limit = isset($atts['folders_limit']) ? max(1, (int) $atts['folders_limit']) : 6;
        $files_limit = isset($atts['files_limit']) ? max(1, (int) $atts['files_limit']) : 6;
        $manager_url = isset($atts['manager_url']) ? (string) $atts['manager_url'] : '';
        $button_label = isset($atts['button_label']) ? (string) $atts['button_label'] : __('Ver y navegar', 'shared-docs-manager');
        $title = isset($atts['title']) ? (string) $atts['title'] : __('Documentos compartidos', 'shared-docs-manager');

        $latest_folders = $this->get_latest_accessible_folders($user_id, $folders_limit);
        $latest_files = $this->get_latest_accessible_files($user_id, $files_limit);

        ob_start();
        ?>
        <div class="shared-docs-overview">
            <div class="shared-docs-overview__header">
                <div>
                    <h3><?php echo esc_html($title); ?></h3>
                    <p><?php esc_html_e('Acceso rápido a tus contenidos recientes.', 'shared-docs-manager'); ?></p>
                </div>
                <?php if ($manager_url !== '') : ?>
                    <a class="shared-docs-btn shared-docs-btn-primary" href="<?php echo esc_url($manager_url); ?>">
                        <?php echo esc_html($button_label); ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="shared-docs-overview__grid">
                <section class="shared-docs-overview__col">
                    <h4><?php esc_html_e('Últimas carpetas', 'shared-docs-manager'); ?></h4>
                    <?php if (empty($latest_folders)) : ?>
                        <div class="shared-docs-empty"><?php esc_html_e('No hay carpetas recientes disponibles.', 'shared-docs-manager'); ?></div>
                    <?php else : ?>
                        <ul class="shared-docs-overview__list">
                            <?php foreach ($latest_folders as $folder) : ?>
                                <?php $folder_url = $manager_url !== '' ? add_query_arg(array('sd_folder' => (int) $folder->ID), $manager_url) : ''; ?>
                                <li>
                                    <span class="shared-docs-overview__icon"><?php echo $this->render_overview_icon('folder', null); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                    <div>
                                        <?php if ($folder_url !== '') : ?>
                                            <strong><a href="<?php echo esc_url($folder_url); ?>"><?php echo esc_html($folder->post_title); ?></a></strong>
                                        <?php else : ?>
                                            <strong><?php echo esc_html($folder->post_title); ?></strong>
                                        <?php endif; ?>
                                        <small><?php echo esc_html($this->format_post_datetime($folder->post_modified_gmt)); ?></small>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <section class="shared-docs-overview__col">
                    <h4><?php esc_html_e('Últimos archivos', 'shared-docs-manager'); ?></h4>
                    <?php if (empty($latest_files)) : ?>
                        <div class="shared-docs-empty"><?php esc_html_e('No hay archivos recientes disponibles.', 'shared-docs-manager'); ?></div>
                    <?php else : ?>
                        <ul class="shared-docs-overview__list">
                            <?php foreach ($latest_files as $file) : ?>
                                <?php
                                $folder_id = (int) get_post_meta((int) $file->ID, 'shared_folder_id', true);
                                $folder_title = $folder_id > 0 ? get_the_title($folder_id) : '';
                                $folder_title = $folder_title ? $folder_title : __('Sin carpeta', 'shared-docs-manager');
                                $file_url = $manager_url !== '' ? add_query_arg(
                                    array(
                                        'sd_folder' => $folder_id,
                                        'sd_file'   => (int) $file->ID,
                                    ),
                                    $manager_url
                                ) : '';
                                ?>
                                <li>
                                    <span class="shared-docs-overview__icon"><?php echo $this->render_overview_icon('file', $file); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                    <div>
                                        <?php if ($file_url !== '') : ?>
                                            <strong><a href="<?php echo esc_url($file_url); ?>"><?php echo esc_html($file->post_title); ?></a></strong>
                                        <?php else : ?>
                                            <strong><?php echo esc_html($file->post_title); ?></strong>
                                        <?php endif; ?>
                                        <small>
                                            <?php
                                            echo esc_html(
                                                sprintf(
                                                    __('%1$s · %2$s', 'shared-docs-manager'),
                                                    $folder_title,
                                                    $this->format_post_datetime($file->post_modified_gmt)
                                                )
                                            );
                                            ?>
                                        </small>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Encola assets y datos para JS.
     *
     * @return void
     */
    private function enqueue_assets()
    {
        wp_enqueue_style('shared-docs-front');
        wp_enqueue_script('shared-docs-sheetjs');
        wp_enqueue_script('shared-docs-front');
        $this->localize_front_script();
    }

    /**
     * Localiza configuración de frontend.
     *
     * @return void
     */
    private function localize_front_script()
    {
        if ($this->localized) {
            return;
        }

        wp_localize_script(
            'shared-docs-front',
            'SharedDocsData',
            array(
                'restBase'   => trailingslashit(rest_url('shared-docs/v1')),
                'nonce'      => wp_create_nonce('wp_rest'),
                'isManager'  => $this->permission_manager->current_user_can_manage(),
                'messages'   => array(
                    'loading'         => __('Cargando...', 'shared-docs-manager'),
                        'processing'      => __('Procesando...', 'shared-docs-manager'),
                    'noFolders'       => __('No hay carpetas disponibles.', 'shared-docs-manager'),
                    'noFiles'         => __('No hay archivos en esta carpeta.', 'shared-docs-manager'),
                    'noDirectoryItems'=> __('No hay archivos ni carpetas en el directorio "%s".', 'shared-docs-manager'),
                    'downloadError'   => __('No se pudo descargar el archivo.', 'shared-docs-manager'),
                    'excelLoadError'  => __('No se pudo abrir el archivo Excel.', 'shared-docs-manager'),
                    'excelSaveError'  => __('No se pudo guardar el archivo Excel.', 'shared-docs-manager'),
                    'excelSaveOk'     => __('Cambios guardados correctamente.', 'shared-docs-manager'),
                        'excelReadOnlyHint' => __('Vista previa en solo lectura. No tienes permisos para editar este Excel.', 'shared-docs-manager'),
                        'excelEditHint'   => __('Haz clic sobre una celda para editarla.', 'shared-docs-manager'),
                    'requestError'    => __('Error de comunicación con el servidor.', 'shared-docs-manager'),
                    'permissionError' => __('No tienes permisos para esta acción.', 'shared-docs-manager'),
                ),
            )
        );
        $this->localized = true;
    }

    /**
     * Obtiene carpetas recientes accesibles para un usuario.
     *
     * @param int $user_id Usuario.
     * @param int $limit   Límite.
     *
     * @return array
     */
    private function get_latest_accessible_folders($user_id, $limit)
    {
        $folder_ids = $this->permission_manager->get_accessible_folder_ids((int) $user_id, 'can_read');
        if (empty($folder_ids)) {
            return array();
        }

        return get_posts(
            array(
                'post_type'      => 'shared_folder',
                'post_status'    => array('publish', 'private'),
                'posts_per_page' => (int) $limit,
                'post__in'       => array_map('intval', $folder_ids),
                'orderby'        => 'modified',
                'order'          => 'DESC',
            )
        );
    }

    /**
     * Obtiene archivos recientes accesibles para un usuario.
     *
     * @param int $user_id Usuario.
     * @param int $limit   Límite.
     *
     * @return array
     */
    private function get_latest_accessible_files($user_id, $limit)
    {
        $file_ids = $this->permission_manager->get_accessible_file_ids((int) $user_id, 'can_read');
        if (empty($file_ids)) {
            return array();
        }

        return get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => array('inherit', 'private'),
                'posts_per_page' => (int) $limit,
                'post__in'       => array_map('intval', $file_ids),
                'orderby'        => 'modified',
                'order'          => 'DESC',
            )
        );
    }

    /**
     * Renderiza icono SVG para overview.
     *
     * @param string      $type folder|file.
     * @param object|null $file Attachment.
     *
     * @return string
     */
    private function render_overview_icon($type, $file = null)
    {
        $svg = '';
        if ($type === 'folder') {
            $svg = Icon_Helper::svg_for_resource('folder');
        } else {
            $path = is_object($file) && isset($file->ID) ? (string) get_attached_file((int) $file->ID) : '';
            $mime = is_object($file) && isset($file->ID) ? (string) get_post_mime_type((int) $file->ID) : '';
            $svg = Icon_Helper::svg_for_resource('file', basename($path), $mime);
        }

        return '<span class="shared-docs-type-icon shared-docs-type-icon--' . esc_attr($type) . '">' . $svg . '</span>';
    }

    /**
     * Formatea datetime GMT de post para salida.
     *
     * @param string $gmt_datetime Datetime GMT.
     *
     * @return string
     */
    private function format_post_datetime($gmt_datetime)
    {
        $timestamp = strtotime((string) $gmt_datetime . ' GMT');
        if (! $timestamp) {
            return '';
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }
}
