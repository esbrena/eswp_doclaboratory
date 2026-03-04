<?php

namespace SharedDocsManager\Front;

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
     * Renderiza HTML principal del gestor.
     *
     * @return string
     */
    public function render_manager()
    {
        $this->enqueue_assets();

        ob_start();
        ?>
        <div id="shared-docs-manager" class="shared-docs-manager">
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
                <section class="shared-docs-section">
                    <h4><?php esc_html_e('Carpetas', 'shared-docs-manager'); ?></h4>
                    <div class="shared-docs-grid" data-region="folders"></div>
                </section>

                <section class="shared-docs-section">
                    <h4><?php esc_html_e('Archivos', 'shared-docs-manager'); ?></h4>
                    <div class="shared-docs-grid" data-region="files"></div>
                </section>
            </div>
        </div>

        <div id="shared-docs-excel-modal" class="shared-docs-modal" hidden>
            <div class="shared-docs-modal__backdrop" data-action="close-modal"></div>
            <div class="shared-docs-modal__content" role="dialog" aria-modal="true">
                <header class="shared-docs-modal__header">
                    <h4 class="shared-docs-modal__title"><?php esc_html_e('Editar Excel', 'shared-docs-manager'); ?></h4>
                    <button type="button" class="shared-docs-modal__close" data-action="close-modal">&times;</button>
                </header>
                <div class="shared-docs-modal__body">
                    <p class="shared-docs-modal__hint"><?php esc_html_e('Haz clic sobre una celda para editarla.', 'shared-docs-manager'); ?></p>
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
     * Encola assets y datos para JS.
     *
     * @return void
     */
    private function enqueue_assets()
    {
        wp_enqueue_style('shared-docs-front');
        wp_enqueue_script('shared-docs-sheetjs');
        wp_enqueue_script('shared-docs-front');

        if (! $this->localized) {
            wp_localize_script(
                'shared-docs-front',
                'SharedDocsData',
                array(
                    'restBase'   => trailingslashit(rest_url('shared-docs/v1')),
                    'nonce'      => wp_create_nonce('wp_rest'),
                    'isManager'  => $this->permission_manager->current_user_can_manage(),
                    'messages'   => array(
                        'loading'         => __('Cargando...', 'shared-docs-manager'),
                        'noFolders'       => __('No hay carpetas disponibles.', 'shared-docs-manager'),
                        'noFiles'         => __('No hay archivos en esta carpeta.', 'shared-docs-manager'),
                        'downloadError'   => __('No se pudo descargar el archivo.', 'shared-docs-manager'),
                        'excelLoadError'  => __('No se pudo abrir el archivo Excel.', 'shared-docs-manager'),
                        'excelSaveError'  => __('No se pudo guardar el archivo Excel.', 'shared-docs-manager'),
                        'excelSaveOk'     => __('Cambios guardados correctamente.', 'shared-docs-manager'),
                        'requestError'    => __('Error de comunicación con el servidor.', 'shared-docs-manager'),
                        'permissionError' => __('No tienes permisos para esta acción.', 'shared-docs-manager'),
                    ),
                )
            );
            $this->localized = true;
        }
    }
}
