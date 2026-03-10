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
        $front_css_version = file_exists(SHARED_DOCS_PATH . 'assets/css/front.css')
            ? (string) filemtime(SHARED_DOCS_PATH . 'assets/css/front.css')
            : SHARED_DOCS_VERSION;
        $front_js_version = file_exists(SHARED_DOCS_PATH . 'assets/js/front-manager.js')
            ? (string) filemtime(SHARED_DOCS_PATH . 'assets/js/front-manager.js')
            : SHARED_DOCS_VERSION;

        wp_register_style(
            'shared-docs-front',
            SHARED_DOCS_URL . 'assets/css/front.css',
            array(),
            $front_css_version
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
            $front_js_version,
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
     * Renderiza explorador de accesos (tabla o lista) para usuario.
     *
     * @param int   $user_id Usuario.
     * @param array $atts    Atributos de shortcode.
     *
     * @return string
     */
    public function render_access_browser($user_id, $atts = array())
    {
        $user_id = (int) $user_id;
        $mode = isset($atts['mode']) ? strtolower((string) $atts['mode']) : 'table';
        if (! in_array($mode, array('table', 'list'), true)) {
            $mode = 'table';
        }
        $title = isset($atts['title']) ? (string) $atts['title'] : __('Mis documentos', 'shared-docs-manager');

        $this->enqueue_assets();
        $nodes = $this->build_access_browser_nodes($user_id);

        ob_start();
        ?>
        <div class="shared-docs-browser shared-docs-browser--<?php echo esc_attr($mode); ?>" data-shared-docs-browser data-browser-mode="<?php echo esc_attr($mode); ?>">
            <div class="shared-docs-browser__toolbar">
                <div>
                    <h3 class="shared-docs-browser__title"><?php echo esc_html($title); ?></h3>
                    <p class="shared-docs-browser__hint"><?php esc_html_e('Despliega carpetas para ver sus recursos. Puedes buscar y filtrar por tipo de archivo.', 'shared-docs-manager'); ?></p>
                </div>
                <input type="search" class="shared-docs-browser__search" data-browser-search placeholder="<?php esc_attr_e('Buscar carpeta o archivo...', 'shared-docs-manager'); ?>" />
            </div>
            <div class="shared-docs-browser__filters" data-browser-filters>
                <button type="button" class="shared-docs-btn shared-docs-btn-secondary is-active" data-browser-filter="all"><?php esc_html_e('Todos', 'shared-docs-manager'); ?></button>
                <button type="button" class="shared-docs-btn shared-docs-btn-secondary" data-browser-filter="pdf"><?php esc_html_e('PDF', 'shared-docs-manager'); ?></button>
                <button type="button" class="shared-docs-btn shared-docs-btn-secondary" data-browser-filter="docs"><?php esc_html_e('Docs', 'shared-docs-manager'); ?></button>
                <button type="button" class="shared-docs-btn shared-docs-btn-secondary" data-browser-filter="excel"><?php esc_html_e('Excel', 'shared-docs-manager'); ?></button>
                <button type="button" class="shared-docs-btn shared-docs-btn-secondary" data-browser-filter="ppt"><?php esc_html_e('PPT', 'shared-docs-manager'); ?></button>
                <button type="button" class="shared-docs-btn shared-docs-btn-secondary" data-browser-filter="images"><?php esc_html_e('Imágenes', 'shared-docs-manager'); ?></button>
            </div>

            <?php if ($mode === 'table') : ?>
                <div class="shared-docs-browser__header-row">
                    <span><?php esc_html_e('Nombre', 'shared-docs-manager'); ?></span>
                    <span><?php esc_html_e('Tipo', 'shared-docs-manager'); ?></span>
                    <span><?php esc_html_e('Última modificación', 'shared-docs-manager'); ?></span>
                    <span><?php esc_html_e('Acciones', 'shared-docs-manager'); ?></span>
                </div>
            <?php endif; ?>

            <div class="shared-docs-browser__tree" data-browser-tree>
                <?php if (empty($nodes)) : ?>
                    <div class="shared-docs-empty"><?php esc_html_e('No hay contenido accesible para mostrar.', 'shared-docs-manager'); ?></div>
                <?php else : ?>
                    <?php echo $this->render_access_browser_nodes($nodes, $mode, 0); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endif; ?>
            </div>
            <p class="shared-docs-empty" data-browser-empty hidden><?php esc_html_e('No hay resultados con los filtros actuales.', 'shared-docs-manager'); ?></p>

            <div class="shared-docs-modal shared-docs-modal--fullscreen" data-browser-preview-modal hidden>
                <div class="shared-docs-modal__backdrop" data-action="close-browser-preview"></div>
                <div class="shared-docs-modal__content shared-docs-modal__content--fullscreen" role="dialog" aria-modal="true">
                    <header class="shared-docs-modal__header">
                        <h4 class="shared-docs-modal__title" data-browser-preview-title><?php esc_html_e('Vista previa', 'shared-docs-manager'); ?></h4>
                        <button type="button" class="shared-docs-modal__close" data-action="close-browser-preview">&times;</button>
                    </header>
                    <div class="shared-docs-modal__body" data-browser-preview-body></div>
                    <footer class="shared-docs-modal__footer">
                        <button type="button" class="shared-docs-btn shared-docs-btn-secondary" data-action="close-browser-preview"><?php esc_html_e('Cerrar', 'shared-docs-manager'); ?></button>
                        <button type="button" class="shared-docs-btn shared-docs-btn-primary" data-action="browser-preview-download"><?php esc_html_e('Descargar', 'shared-docs-manager'); ?></button>
                    </footer>
                </div>
            </div>

            <div class="shared-docs-modal shared-docs-modal--fullscreen" data-browser-excel-modal hidden>
                <div class="shared-docs-modal__backdrop" data-action="close-browser-excel"></div>
                <div class="shared-docs-modal__content shared-docs-modal__content--fullscreen" role="dialog" aria-modal="true">
                    <header class="shared-docs-modal__header">
                        <h4 class="shared-docs-modal__title" data-browser-excel-title><?php esc_html_e('Editar Excel', 'shared-docs-manager'); ?></h4>
                        <button type="button" class="shared-docs-modal__close" data-action="close-browser-excel">&times;</button>
                    </header>
                    <div class="shared-docs-modal__body">
                        <p class="shared-docs-modal__hint" data-browser-excel-hint><?php esc_html_e('Haz clic sobre una celda para editarla.', 'shared-docs-manager'); ?></p>
                        <div class="shared-docs-excel-table-wrap">
                            <table class="shared-docs-excel-table" data-browser-excel-table></table>
                        </div>
                    </div>
                    <footer class="shared-docs-modal__footer">
                        <button type="button" class="shared-docs-btn shared-docs-btn-secondary" data-action="close-browser-excel"><?php esc_html_e('Cancelar', 'shared-docs-manager'); ?></button>
                        <button type="button" class="shared-docs-btn shared-docs-btn-primary" data-action="browser-save-excel"><?php esc_html_e('Guardar cambios', 'shared-docs-manager'); ?></button>
                    </footer>
                </div>
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
     * Construye nodos jerárquicos para render del explorador.
     *
     * @param int $user_id Usuario.
     *
     * @return array
     */
    private function build_access_browser_nodes($user_id)
    {
        $folder_ids = array_map('intval', $this->permission_manager->get_accessible_folder_ids((int) $user_id, 'can_read'));
        $file_ids = array_map('intval', $this->permission_manager->get_accessible_file_ids((int) $user_id, 'can_read'));

        $folder_ids = array_values(array_unique(array_filter($folder_ids)));
        $file_ids = array_values(array_unique(array_filter($file_ids)));

        $folders = empty($folder_ids) ? array() : get_posts(
            array(
                'post_type'      => 'shared_folder',
                'post_status'    => array('publish', 'private'),
                'posts_per_page' => -1,
                'post__in'       => $folder_ids,
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );
        $files = empty($file_ids) ? array() : get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => array('inherit', 'private'),
                'posts_per_page' => -1,
                'post__in'       => $file_ids,
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );

        $folder_index = array();
        foreach ((array) $folders as $folder) {
            $folder_index[(int) $folder->ID] = $folder;
        }

        $children_map = array();
        foreach ((array) $folders as $folder) {
            $folder_id = (int) $folder->ID;
            $parent_id = (int) $folder->post_parent;
            if (! isset($folder_index[$parent_id])) {
                $parent_id = 0;
            }
            if (! isset($children_map[$parent_id])) {
                $children_map[$parent_id] = array();
            }
            $children_map[$parent_id][] = $folder_id;
        }

        $files_by_folder = array();
        $root_files = array();
        foreach ((array) $files as $file) {
            $file_id = (int) $file->ID;
            $folder_id = (int) get_post_meta($file_id, 'shared_folder_id', true);
            $node = $this->build_access_browser_file_node($file, $user_id);
            if ($folder_id > 0 && isset($folder_index[$folder_id])) {
                if (! isset($files_by_folder[$folder_id])) {
                    $files_by_folder[$folder_id] = array();
                }
                $files_by_folder[$folder_id][] = $node;
            } else {
                $root_files[] = $node;
            }
        }

        $root_nodes = array();
        foreach ((array) ($children_map[0] ?? array()) as $root_folder_id) {
            $node = $this->build_access_browser_folder_node($root_folder_id, $children_map, $files_by_folder, $folder_index);
            if (! empty($node)) {
                $root_nodes[] = $node;
            }
        }
        foreach ($root_files as $node) {
            $root_nodes[] = $node;
        }

        usort($root_nodes, array($this, 'sort_access_browser_nodes'));

        return $root_nodes;
    }

    /**
     * Construye nodo de carpeta con recursión.
     *
     * @param int   $folder_id       Carpeta.
     * @param array $children_map    Hijas por carpeta.
     * @param array $files_by_folder Archivos por carpeta.
     * @param array $folder_index    Índice de carpetas.
     *
     * @return array
     */
    private function build_access_browser_folder_node($folder_id, $children_map, $files_by_folder, $folder_index)
    {
        if (! isset($folder_index[$folder_id])) {
            return array();
        }

        $folder = $folder_index[$folder_id];
        $children = array();
        foreach ((array) ($children_map[$folder_id] ?? array()) as $child_id) {
            $child = $this->build_access_browser_folder_node((int) $child_id, $children_map, $files_by_folder, $folder_index);
            if (! empty($child)) {
                $children[] = $child;
            }
        }
        foreach ((array) ($files_by_folder[$folder_id] ?? array()) as $file_node) {
            $children[] = $file_node;
        }

        usort($children, array($this, 'sort_access_browser_nodes'));

        return array(
            'kind'     => 'folder',
            'id'       => (int) $folder->ID,
            'title'    => (string) $folder->post_title,
            'modified' => $this->format_post_datetime($folder->post_modified_gmt),
            'type'     => __('Carpeta', 'shared-docs-manager'),
            'group'    => 'folder',
            'icon_svg' => Icon_Helper::svg_for_resource('folder'),
            'children' => $children,
        );
    }

    /**
     * Construye nodo de archivo.
     *
     * @param object $file Post attachment.
     *
     * @return array
     */
    private function build_access_browser_file_node($file, $user_id)
    {
        $file_id = (int) $file->ID;
        $path = (string) get_attached_file($file_id);
        $filename = $path !== '' ? basename($path) : (string) $file->post_title;
        $mime = (string) get_post_mime_type($file_id);
        $icon_key = Icon_Helper::key_for_file($filename, $mime);
        $group = $this->map_browser_group_from_icon_key($icon_key);
        $is_excel = strpos($mime, 'spreadsheet') !== false
            || strpos($mime, 'excel') !== false
            || in_array(strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)), array('xls', 'xlsx', 'xlsm', 'xlsb', 'ods', 'csv'), true);
        $can_download = $this->permission_manager->user_can_access_file((int) $user_id, $file_id, 'can_download');
        $can_edit_excel = $is_excel
            ? $this->permission_manager->user_can_access_file((int) $user_id, $file_id, 'can_edit_excel')
            : false;

        return array(
            'kind'     => 'file',
            'id'       => $file_id,
            'title'    => (string) $file->post_title,
            'modified' => $this->format_post_datetime($file->post_modified_gmt),
            'type'     => $this->label_for_browser_group($group),
            'group'    => $group,
            'icon_svg' => Icon_Helper::svg_for_resource('file', $filename, $mime),
            'mime'     => $mime,
            'filename' => $filename,
            'is_excel' => (bool) $is_excel,
            'can_download' => (bool) $can_download,
            'can_edit_excel' => (bool) $can_edit_excel,
            'children' => array(),
        );
    }

    /**
     * Mapea icon key a grupo de filtros de browser.
     *
     * @param string $icon_key Clave icono.
     *
     * @return string
     */
    private function map_browser_group_from_icon_key($icon_key)
    {
        $map = array(
            'pdf'   => 'pdf',
            'doc'   => 'docs',
            'xls'   => 'excel',
            'ppt'   => 'ppt',
            'image' => 'images',
        );

        return isset($map[$icon_key]) ? $map[$icon_key] : 'docs';
    }

    /**
     * Etiqueta legible para tipo de archivo.
     *
     * @param string $group Grupo browser.
     *
     * @return string
     */
    private function label_for_browser_group($group)
    {
        $labels = array(
            'pdf'    => 'PDF',
            'docs'   => __('Docs', 'shared-docs-manager'),
            'excel'  => 'Excel',
            'ppt'    => 'PPT',
            'images' => __('Imágenes', 'shared-docs-manager'),
        );

        return isset($labels[$group]) ? (string) $labels[$group] : __('Archivo', 'shared-docs-manager');
    }

    /**
     * Orden de nodos: carpetas primero, luego nombre.
     *
     * @param array $a Nodo A.
     * @param array $b Nodo B.
     *
     * @return int
     */
    private function sort_access_browser_nodes($a, $b)
    {
        $a_kind = isset($a['kind']) ? (string) $a['kind'] : 'file';
        $b_kind = isset($b['kind']) ? (string) $b['kind'] : 'file';
        if ($a_kind !== $b_kind) {
            return $a_kind === 'folder' ? -1 : 1;
        }

        $a_title = isset($a['title']) ? (string) $a['title'] : '';
        $b_title = isset($b['title']) ? (string) $b['title'] : '';
        return strcasecmp($a_title, $b_title);
    }

    /**
     * Renderiza nodos del browser.
     *
     * @param array  $nodes Nodos.
     * @param string $mode  table|list.
     * @param int    $level Nivel.
     *
     * @return string
     */
    private function render_access_browser_nodes($nodes, $mode, $level)
    {
        $html = '';
        foreach ((array) $nodes as $node) {
            $kind = isset($node['kind']) ? (string) $node['kind'] : 'file';
            $title = isset($node['title']) ? (string) $node['title'] : '';
            $type = isset($node['type']) ? (string) $node['type'] : '';
            $modified = isset($node['modified']) ? (string) $node['modified'] : '';
            $group = isset($node['group']) ? (string) $node['group'] : '';
            $icon_svg = isset($node['icon_svg']) ? (string) $node['icon_svg'] : '';
            $children = isset($node['children']) ? (array) $node['children'] : array();
            $mime = isset($node['mime']) ? (string) $node['mime'] : '';
            $filename = isset($node['filename']) ? (string) $node['filename'] : '';
            $is_excel = ! empty($node['is_excel']);
            $can_download = ! empty($node['can_download']);
            $can_edit_excel = ! empty($node['can_edit_excel']);
            $padding = 14 + (max(0, (int) $level) * 18);

            if ($kind === 'folder') {
                $html .= '<details class="shared-docs-browser-item shared-docs-browser-folder" data-browser-item data-browser-kind="folder" data-browser-title="' . esc_attr(strtolower($title)) . '" open>';
                $html .= '<summary class="shared-docs-browser-row" style="--shared-docs-level-padding:' . esc_attr((string) $padding) . 'px;">';
                $html .= '<span class="shared-docs-browser-col shared-docs-browser-col--name"><span class="shared-docs-type-icon shared-docs-type-icon--folder">' . $icon_svg . '</span><span class="shared-docs-browser-name-text">' . esc_html($title) . '</span></span>';
                if ($mode === 'table') {
                    $html .= '<span class="shared-docs-browser-col shared-docs-browser-col--type">' . esc_html($type) . '</span>';
                    $html .= '<span class="shared-docs-browser-col shared-docs-browser-col--date">' . esc_html($modified) . '</span>';
                }
                $html .= '</summary>';
                $html .= '<div class="shared-docs-browser-children">';
                if (empty($children)) {
                    $html .= '<div class="shared-docs-browser-empty-child">' . esc_html__('Sin contenido', 'shared-docs-manager') . '</div>';
                } else {
                    $html .= $this->render_access_browser_nodes($children, $mode, $level + 1);
                }
                $html .= '</div>';
                $html .= '</details>';
                continue;
            }

            $html .= '<div class="shared-docs-browser-item shared-docs-browser-file" data-browser-item data-browser-kind="file" data-browser-group="' . esc_attr($group) . '" data-browser-title="' . esc_attr(strtolower($title)) . '">';
            $html .= '<div class="shared-docs-browser-row" style="--shared-docs-level-padding:' . esc_attr((string) $padding) . 'px;">';
            $html .= '<span class="shared-docs-browser-col shared-docs-browser-col--name"><span class="shared-docs-type-icon shared-docs-type-icon--file">' . $icon_svg . '</span><span class="shared-docs-browser-name-text">' . esc_html($title) . '</span></span>';
            if ($mode === 'table') {
                $html .= '<span class="shared-docs-browser-col shared-docs-browser-col--type">' . esc_html($type) . '</span>';
                $html .= '<span class="shared-docs-browser-col shared-docs-browser-col--date">' . esc_html($modified) . '</span>';
            }
            $html .= '<span class="shared-docs-browser-col shared-docs-browser-col--actions">';
            $html .= '<button type="button" class="shared-docs-btn shared-docs-btn-primary shared-docs-browser-file-open" data-browser-file-id="' . esc_attr((string) (int) $node['id']) . '" data-browser-file-title="' . esc_attr($title) . '" data-browser-file-mime="' . esc_attr($mime) . '" data-browser-file-name="' . esc_attr($filename) . '" data-browser-file-is-excel="' . ($is_excel ? '1' : '0') . '" data-browser-file-can-edit-excel="' . ($can_edit_excel ? '1' : '0') . '" data-browser-file-can-download="' . ($can_download ? '1' : '0') . '">' . esc_html__('Abrir', 'shared-docs-manager') . '</button>';
            $html .= '<button type="button" class="shared-docs-btn shared-docs-btn-secondary shared-docs-browser-file-download" data-browser-file-id="' . esc_attr((string) (int) $node['id']) . '" data-browser-file-name="' . esc_attr($filename) . '"' . ($can_download ? '' : ' disabled') . '>' . esc_html__('Descargar', 'shared-docs-manager') . '</button>';
            $html .= '</span>';
            $html .= '</div>';
            $html .= '</div>';
        }

        return $html;
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
