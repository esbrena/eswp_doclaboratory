<?php

namespace SharedDocsManager\Admin;

use SharedDocsManager\Helpers\File_Helper;
use SharedDocsManager\Helpers\Icon_Helper;
use SharedDocsManager\Permissions\File_Permission_Repository;
use SharedDocsManager\Permissions\Permission_Manager;
use SharedDocsManager\Permissions\Permission_Repository;
use WP_Error;
use WP_User;

if (! defined('ABSPATH')) {
    exit;
}

class Admin_Controller
{
    /**
     * @var Permission_Manager
     */
    private $permission_manager;

    /**
     * @var Permission_Repository
     */
    private $permission_repository;

    /**
     * @var File_Permission_Repository
     */
    private $file_permission_repository;

    /**
     * @var File_Helper
     */
    private $file_helper;

    public function __construct(
        Permission_Manager $permission_manager,
        Permission_Repository $permission_repository,
        File_Permission_Repository $file_permission_repository,
        File_Helper $file_helper
    ) {
        $this->permission_manager = $permission_manager;
        $this->permission_repository = $permission_repository;
        $this->file_permission_repository = $file_permission_repository;
        $this->file_helper = $file_helper;
    }

    /**
     * Registra hooks del admin.
     *
     * @return void
     */
    public function register_hooks()
    {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        add_action('admin_post_shared_docs_create_folder', array($this, 'handle_create_folder'));
        add_action('admin_post_shared_docs_rename_folder', array($this, 'handle_rename_folder'));
        add_action('admin_post_shared_docs_upload_file', array($this, 'handle_upload_file'));
        add_action('admin_post_shared_docs_move_item', array($this, 'handle_move_item'));
        add_action('admin_post_shared_docs_bulk_move_items', array($this, 'handle_bulk_move_items'));
        add_action('admin_post_shared_docs_bulk_delete_items', array($this, 'handle_bulk_delete_items'));
        add_action('admin_post_shared_docs_open_file', array($this, 'handle_open_file'));
        add_action('admin_post_shared_docs_download_file', array($this, 'handle_download_file'));
        add_action('admin_post_shared_docs_add_item_access_bulk', array($this, 'handle_add_item_access_bulk'));
        add_action('admin_post_shared_docs_save_permission', array($this, 'handle_save_permission'));
        add_action('admin_post_shared_docs_save_file_permission', array($this, 'handle_save_file_permission'));
        add_action('admin_post_shared_docs_bulk_save_permissions', array($this, 'handle_bulk_save_permissions'));
        add_action('admin_post_shared_docs_delete_folder', array($this, 'handle_delete_folder'));
        add_action('admin_post_shared_docs_delete_file', array($this, 'handle_delete_file'));
        add_action('admin_post_shared_docs_delete_permission', array($this, 'handle_delete_permission'));
        add_action('admin_post_shared_docs_delete_file_permission', array($this, 'handle_delete_file_permission'));
        add_action('admin_post_shared_docs_save_settings', array($this, 'handle_save_settings'));

        add_action('show_user_profile', array($this, 'render_user_permissions_block'));
        add_action('edit_user_profile', array($this, 'render_user_permissions_block'));
    }

    /**
     * Registra menú principal.
     *
     * @return void
     */
    public function register_admin_menu()
    {
        if (! $this->permission_manager->current_user_can_manage()) {
            return;
        }

        add_menu_page(
            __('Shared Docs', 'shared-docs-manager'),
            __('Shared Docs', 'shared-docs-manager'),
            'read',
            'shared-docs',
            array($this, 'render_tree_page'),
            'dashicons-portfolio',
            26
        );

        add_submenu_page(
            'shared-docs',
            __('Árbol de documentos', 'shared-docs-manager'),
            __('Árbol de documentos', 'shared-docs-manager'),
            'read',
            'shared-docs',
            array($this, 'render_tree_page')
        );

        add_submenu_page(
            'shared-docs',
            __('Permisos', 'shared-docs-manager'),
            __('Permisos', 'shared-docs-manager'),
            'read',
            'shared-docs-permissions',
            array($this, 'render_permissions_page')
        );
    }

    /**
     * Carga assets admin.
     *
     * @param string $hook Hook de pantalla.
     *
     * @return void
     */
    public function enqueue_assets($hook)
    {
        $allowed_hooks = array('toplevel_page_shared-docs', 'shared-docs_page_shared-docs-permissions', 'profile.php', 'user-edit.php');
        if (! in_array($hook, $allowed_hooks, true)) {
            return;
        }

        wp_enqueue_style(
            'shared-docs-admin',
            SHARED_DOCS_URL . 'assets/css/admin.css',
            array(),
            SHARED_DOCS_VERSION
        );

        wp_enqueue_script(
            'shared-docs-sheetjs',
            'https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js',
            array(),
            '0.20.2',
            true
        );

        wp_enqueue_script(
            'shared-docs-admin',
            SHARED_DOCS_URL . 'assets/js/admin-manager.js',
            array('shared-docs-sheetjs'),
            SHARED_DOCS_VERSION,
            true
        );
    }

    /**
     * Página de gestión del árbol (carpetas y archivos).
     *
     * @return void
     */
    public function render_tree_page()
    {
        if (! $this->permission_manager->current_user_can_manage()) {
            wp_die(esc_html__('No tienes permisos para gestionar documentos compartidos.', 'shared-docs-manager'));
        }

        $folders = $this->get_all_folders();
        $files = $this->get_all_files();
        $assignable_users = $this->get_assignable_users();
        $permissions_payload = $this->build_permissions_payload($folders, $files);
        $excel_history_payload = $this->build_excel_history_payload($files);

        $inline_data = array(
            'permissionsByFolder' => $permissions_payload['by_folder'],
            'permissionsByFile'   => $permissions_payload['by_file'],
            'userPermissionsHtmlByUser' => $permissions_payload['user_permissions_html'],
            'excelHistoryByFile'  => $excel_history_payload,
            'restBase'            => trailingslashit(rest_url('shared-docs/v1')),
            'nonce'               => wp_create_nonce('wp_rest'),
            'messages'            => array(
                'bulkSelection' => __('%d elementos seleccionados', 'shared-docs-manager'),
                'requestError'  => __('Error de comunicación con el servidor.', 'shared-docs-manager'),
                'downloadError' => __('No se pudo descargar el archivo.', 'shared-docs-manager'),
                'excelLoadError'=> __('No se pudo abrir el archivo Excel.', 'shared-docs-manager'),
                'excelSaveError'=> __('No se pudo guardar el archivo Excel.', 'shared-docs-manager'),
                'excelSaveOk'   => __('Cambios guardados correctamente.', 'shared-docs-manager'),
            ),
        );
        wp_add_inline_script('shared-docs-admin', 'window.SharedDocsAdminData = ' . wp_json_encode($inline_data) . ';', 'before');

        $notice_code = isset($_GET['sd_notice']) ? sanitize_key(wp_unslash($_GET['sd_notice'])) : '';
        $notice_text = $this->get_notice_message($notice_code);
        ?>
        <div class="wrap shared-docs-admin-wrap">
            <h1><?php esc_html_e('Shared Docs · Árbol de documentos', 'shared-docs-manager'); ?></h1>

            <?php if ($notice_text !== '') : ?>
                <div class="notice notice-info is-dismissible">
                    <p><?php echo esc_html($notice_text); ?></p>
                </div>
            <?php endif; ?>

            <div class="shared-docs-grid-admin">
                <section class="shared-docs-card">
                    <h2><?php esc_html_e('Crear carpeta o subcarpeta', 'shared-docs-manager'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="shared_docs_create_folder" />
                        <input type="hidden" name="return_page" value="shared-docs" />
                        <?php wp_nonce_field('shared_docs_create_folder'); ?>

                        <label for="shared-folder-name"><?php esc_html_e('Nombre de carpeta', 'shared-docs-manager'); ?></label>
                        <input id="shared-folder-name" type="text" name="folder_name" class="regular-text" required />

                        <label for="shared-folder-parent"><?php esc_html_e('Carpeta padre', 'shared-docs-manager'); ?></label>
                        <select id="shared-folder-parent" name="parent_folder_id">
                            <option value="0"><?php esc_html_e('— Carpeta raíz —', 'shared-docs-manager'); ?></option>
                            <?php echo $this->render_folder_options($folders); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </select>

                        <?php submit_button(__('Crear carpeta', 'shared-docs-manager')); ?>
                    </form>
                </section>

                <section class="shared-docs-card">
                    <h2><?php esc_html_e('Subir archivo', 'shared-docs-manager'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="shared_docs_upload_file" />
                        <input type="hidden" name="return_page" value="shared-docs" />
                        <?php wp_nonce_field('shared_docs_upload_file'); ?>

                        <label for="shared-upload-folder"><?php esc_html_e('Carpeta destino', 'shared-docs-manager'); ?></label>
                        <select id="shared-upload-folder" name="folder_id" required>
                            <option value=""><?php esc_html_e('Selecciona carpeta...', 'shared-docs-manager'); ?></option>
                            <?php echo $this->render_folder_options($folders); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </select>

                        <label for="shared-upload-file"><?php esc_html_e('Archivo', 'shared-docs-manager'); ?></label>
                        <input id="shared-upload-file" type="file" name="shared_file_upload" required />

                        <?php submit_button(__('Subir archivo', 'shared-docs-manager')); ?>
                    </form>
                </section>
            </div>

            <section class="shared-docs-card shared-docs-card-full">
                <h2><?php esc_html_e('Árbol de carpetas y archivos', 'shared-docs-manager'); ?></h2>
                <div class="shared-docs-tree-global-actions" data-tree-global-actions hidden>
                    <div class="shared-docs-tree-global-actions__info" data-tree-selection-count></div>
                    <div class="shared-docs-tree-global-actions__buttons" data-tree-actions-single hidden>
                        <button type="button" class="button" data-action="tree-single-open" hidden><?php esc_html_e('Abrir', 'shared-docs-manager'); ?></button>
                        <button type="button" class="button" data-action="tree-single-download" hidden><?php esc_html_e('Descargar', 'shared-docs-manager'); ?></button>
                        <button type="button" class="button" data-action="tree-single-rename" hidden><?php esc_html_e('Editar nombre', 'shared-docs-manager'); ?></button>
                        <button type="button" class="button" data-action="tree-single-access"><?php esc_html_e('Administrar acceso', 'shared-docs-manager'); ?></button>
                        <button type="button" class="button" data-action="tree-single-history" hidden><?php esc_html_e('Histórico', 'shared-docs-manager'); ?></button>
                        <button type="button" class="button" data-action="tree-single-move"><?php esc_html_e('Mover', 'shared-docs-manager'); ?></button>
                        <button type="button" class="button button-secondary" data-action="tree-single-delete"><?php esc_html_e('Borrar', 'shared-docs-manager'); ?></button>
                    </div>
                    <div class="shared-docs-tree-global-actions__buttons" data-tree-actions-multi hidden>
                        <button type="button" class="button" data-action="open-bulk-move-modal"><?php esc_html_e('Mover seleccionados', 'shared-docs-manager'); ?></button>
                        <button type="button" class="button button-secondary" data-action="submit-bulk-delete"><?php esc_html_e('Borrar seleccionados', 'shared-docs-manager'); ?></button>
                    </div>
                </div>

                <?php
                $tree_html = $this->render_folder_tree_with_files($folders, $files);
                if ($tree_html === '') :
                    ?>
                    <p><?php esc_html_e('No hay estructura de carpetas/archivos para mostrar.', 'shared-docs-manager'); ?></p>
                <?php else : ?>
                    <?php echo $tree_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-bulk-delete-form hidden>
                    <input type="hidden" name="action" value="shared_docs_bulk_delete_items" />
                    <input type="hidden" name="return_page" value="shared-docs" />
                    <?php wp_nonce_field('shared_docs_bulk_delete_items'); ?>
                    <input type="hidden" name="folder_ids" value="" data-bulk-folder-ids />
                    <input type="hidden" name="file_ids" value="" data-bulk-file-ids />
                </form>
            </section>

            <?php echo $this->render_move_modal($folders); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->render_bulk_move_modal($folders); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->render_rename_folder_modal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->render_access_modal($assignable_users); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->render_excel_history_modal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->render_file_action_modal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php
    }

    /**
     * Página de permisos de usuarios.
     *
     * @return void
     */
    public function render_permissions_page()
    {
        if (! $this->permission_manager->current_user_can_manage()) {
            wp_die(esc_html__('No tienes permisos para gestionar documentos compartidos.', 'shared-docs-manager'));
        }

        $folders = $this->get_all_folders();
        $files = $this->get_all_files();
        $assignable_users = $this->get_assignable_users();

        $editing_permission = null;
        if (isset($_GET['action'], $_GET['permission_id']) && $_GET['action'] === 'edit_permission') {
            $editing_permission = $this->permission_repository->get_permission((int) $_GET['permission_id']);
        }
        $editing_file_permission = null;
        if (isset($_GET['action'], $_GET['permission_id']) && $_GET['action'] === 'edit_file_permission') {
            $editing_file_permission = $this->file_permission_repository->get_permission((int) $_GET['permission_id']);
        }

        $assignable_users = $this->ensure_selected_users_present(
            $assignable_users,
            array(
                $editing_permission ? (int) $editing_permission->user_id : 0,
                $editing_file_permission ? (int) $editing_file_permission->user_id : 0,
            )
        );

        $permissions_payload = $this->build_permissions_payload($folders, $files);
        $excel_history_payload = $this->build_excel_history_payload($files);
        $inline_data = array(
            'permissionsByFolder' => $permissions_payload['by_folder'],
            'permissionsByFile'   => $permissions_payload['by_file'],
            'userPermissionsHtmlByUser' => $permissions_payload['user_permissions_html'],
            'excelHistoryByFile'  => $excel_history_payload,
            'restBase'            => trailingslashit(rest_url('shared-docs/v1')),
            'nonce'               => wp_create_nonce('wp_rest'),
            'messages'            => array(
                'bulkSelection' => __('%d elementos seleccionados', 'shared-docs-manager'),
                'requestError'  => __('Error de comunicación con el servidor.', 'shared-docs-manager'),
            ),
        );
        wp_add_inline_script('shared-docs-admin', 'window.SharedDocsAdminData = ' . wp_json_encode($inline_data) . ';', 'before');

        $notice_code = isset($_GET['sd_notice']) ? sanitize_key(wp_unslash($_GET['sd_notice'])) : '';
        $notice_text = $this->get_notice_message($notice_code);
        ?>
        <div class="wrap shared-docs-admin-wrap">
            <h1><?php esc_html_e('Shared Docs · Permisos', 'shared-docs-manager'); ?></h1>

            <?php if ($notice_text !== '') : ?>
                <div class="notice notice-info is-dismissible">
                    <p><?php echo esc_html($notice_text); ?></p>
                </div>
            <?php endif; ?>

            <section class="shared-docs-card shared-docs-card-full">
                <h2><?php esc_html_e('Dar acceso a usuarios', 'shared-docs-manager'); ?></h2>
                <div class="shared-docs-grid-admin">
                    <section class="shared-docs-card">
                        <h3>
                            <?php
                            echo $editing_permission
                                ? esc_html__('Editar permiso por carpeta', 'shared-docs-manager')
                                : esc_html__('Asignar permiso por carpeta', 'shared-docs-manager');
                            ?>
                        </h3>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="shared_docs_save_permission" />
                            <input type="hidden" name="return_page" value="shared-docs-permissions" />
                            <?php wp_nonce_field('shared_docs_save_permission'); ?>

                            <?php if ($editing_permission) : ?>
                                <input type="hidden" name="permission_id" value="<?php echo (int) $editing_permission->id; ?>" />
                            <?php endif; ?>

                            <?php
                            echo $this->render_user_checkbox_selector(
                                $assignable_users,
                                'user_ids[]',
                                $editing_permission ? array((int) $editing_permission->user_id) : array(),
                                'shared-folder-permission-users'
                            ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            ?>

                            <label for="shared-permission-folder"><?php esc_html_e('Carpeta', 'shared-docs-manager'); ?></label>
                            <select id="shared-permission-folder" name="folder_id" required>
                                <option value=""><?php esc_html_e('Selecciona carpeta...', 'shared-docs-manager'); ?></option>
                                <?php
                                echo $this->render_folder_options(
                                    $folders,
                                    $editing_permission ? (int) $editing_permission->folder_id : 0
                                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                ?>
                            </select>

                            <fieldset class="shared-docs-checkboxes">
                                <legend><?php esc_html_e('Permisos', 'shared-docs-manager'); ?></legend>
                                <label><input type="checkbox" name="can_read" value="1" <?php checked($editing_permission ? (int) $editing_permission->can_read : 1, 1); ?> /> <?php esc_html_e('Lectura', 'shared-docs-manager'); ?></label>
                                <label><input type="checkbox" name="can_download" value="1" <?php checked($editing_permission ? (int) $editing_permission->can_download : 1, 1); ?> /> <?php esc_html_e('Descarga', 'shared-docs-manager'); ?></label>
                                <label><input type="checkbox" name="can_edit_excel" value="1" <?php checked($editing_permission ? (int) $editing_permission->can_edit_excel : 0, 1); ?> /> <?php esc_html_e('Edición Excel', 'shared-docs-manager'); ?></label>
                            </fieldset>

                            <label for="shared-permission-expires"><?php esc_html_e('Fecha límite (opcional)', 'shared-docs-manager'); ?></label>
                            <input id="shared-permission-expires" type="datetime-local" name="expires_at" value="<?php echo esc_attr($this->format_datetime_local($editing_permission ? $editing_permission->expires_at : '')); ?>" />

                            <?php submit_button($editing_permission ? __('Actualizar permiso', 'shared-docs-manager') : __('Guardar permiso', 'shared-docs-manager')); ?>
                        </form>
                    </section>

                    <section class="shared-docs-card">
                        <h3>
                            <?php
                            echo $editing_file_permission
                                ? esc_html__('Editar permiso por archivo', 'shared-docs-manager')
                                : esc_html__('Asignar permiso por archivo', 'shared-docs-manager');
                            ?>
                        </h3>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="shared_docs_save_file_permission" />
                            <input type="hidden" name="return_page" value="shared-docs-permissions" />
                            <?php wp_nonce_field('shared_docs_save_file_permission'); ?>

                            <?php if ($editing_file_permission) : ?>
                                <input type="hidden" name="permission_id" value="<?php echo (int) $editing_file_permission->id; ?>" />
                            <?php endif; ?>

                            <?php
                            echo $this->render_user_checkbox_selector(
                                $assignable_users,
                                'user_ids[]',
                                $editing_file_permission ? array((int) $editing_file_permission->user_id) : array(),
                                'shared-file-permission-users'
                            ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            ?>

                            <label for="shared-file-permission-file"><?php esc_html_e('Archivo', 'shared-docs-manager'); ?></label>
                            <select id="shared-file-permission-file" name="file_id" required>
                                <option value=""><?php esc_html_e('Selecciona archivo...', 'shared-docs-manager'); ?></option>
                                <?php echo $this->render_file_options($files, $editing_file_permission ? (int) $editing_file_permission->file_id : 0); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </select>

                            <fieldset class="shared-docs-checkboxes">
                                <legend><?php esc_html_e('Permisos', 'shared-docs-manager'); ?></legend>
                                <label><input type="checkbox" name="can_read" value="1" <?php checked($editing_file_permission ? (int) $editing_file_permission->can_read : 1, 1); ?> /> <?php esc_html_e('Lectura', 'shared-docs-manager'); ?></label>
                                <label><input type="checkbox" name="can_download" value="1" <?php checked($editing_file_permission ? (int) $editing_file_permission->can_download : 1, 1); ?> /> <?php esc_html_e('Descarga', 'shared-docs-manager'); ?></label>
                                <label><input type="checkbox" name="can_edit_excel" value="1" <?php checked($editing_file_permission ? (int) $editing_file_permission->can_edit_excel : 0, 1); ?> /> <?php esc_html_e('Edición Excel', 'shared-docs-manager'); ?></label>
                            </fieldset>

                            <label for="shared-file-permission-expires"><?php esc_html_e('Fecha límite (opcional)', 'shared-docs-manager'); ?></label>
                            <input id="shared-file-permission-expires" type="datetime-local" name="expires_at" value="<?php echo esc_attr($this->format_datetime_local($editing_file_permission ? $editing_file_permission->expires_at : '')); ?>" />

                            <?php submit_button($editing_file_permission ? __('Actualizar permiso de archivo', 'shared-docs-manager') : __('Guardar permiso de archivo', 'shared-docs-manager')); ?>
                        </form>
                    </section>
                </div>
                <p class="description"><?php esc_html_e('En este listado solo aparecen usuarios con rol cie_user o cie_user_new.', 'shared-docs-manager'); ?></p>
            </section>

            <section class="shared-docs-card shared-docs-card-full">
                <h2><?php esc_html_e('Carpetas y recursos', 'shared-docs-manager'); ?></h2>
                <label for="shared-docs-resource-search"><?php esc_html_e('Buscar', 'shared-docs-manager'); ?></label>
                <input type="search" id="shared-docs-resource-search" class="regular-text" data-resource-search placeholder="<?php esc_attr_e('Buscar por nombre, tipo, fecha o ubicación...', 'shared-docs-manager'); ?>" />

                <table class="widefat striped shared-docs-resource-table" data-resource-table>
                    <thead>
                    <tr>
                        <th><?php esc_html_e('Icono', 'shared-docs-manager'); ?></th>
                        <th><?php esc_html_e('Tipo', 'shared-docs-manager'); ?></th>
                        <th><?php esc_html_e('Nombre', 'shared-docs-manager'); ?></th>
                        <th><?php esc_html_e('Ubicación', 'shared-docs-manager'); ?></th>
                        <th><?php esc_html_e('Fecha', 'shared-docs-manager'); ?></th>
                        <th><?php esc_html_e('Acciones', 'shared-docs-manager'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($folders as $folder) : ?>
                        <?php
                        $folder_id = (int) $folder->ID;
                        $location = (int) $folder->post_parent > 0 ? get_the_title((int) $folder->post_parent) : __('Raíz', 'shared-docs-manager');
                        $date = wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($folder->post_modified_gmt . ' GMT'));
                        ?>
                        <tr data-resource-row>
                            <td><?php echo $this->render_resource_icon_html('folder'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php esc_html_e('Carpeta', 'shared-docs-manager'); ?></td>
                            <td><?php echo esc_html($folder->post_title); ?></td>
                            <td><?php echo esc_html($location); ?></td>
                            <td><?php echo esc_html($date); ?></td>
                            <td>
                                <button type="button" class="button button-primary shared-docs-open-permissions-view-modal" data-item-type="folder" data-item-id="<?php echo (int) $folder_id; ?>" data-item-label="<?php echo esc_attr($folder->post_title); ?>">
                                    <?php esc_html_e('Ver permisos', 'shared-docs-manager'); ?>
                                </button>
                                <button type="button" class="button shared-docs-open-permissions-manage-modal" data-item-type="folder" data-item-id="<?php echo (int) $folder_id; ?>" data-item-label="<?php echo esc_attr($folder->post_title); ?>">
                                    <?php esc_html_e('Gestión de permisos', 'shared-docs-manager'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php foreach ($files as $file) : ?>
                        <?php
                        $file_id = (int) $file->ID;
                        $folder_id = (int) get_post_meta($file_id, 'shared_folder_id', true);
                        $folder_name = $folder_id > 0 ? get_the_title($folder_id) : __('(Sin carpeta)', 'shared-docs-manager');
                        $date = wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($file->post_modified_gmt . ' GMT'));
                        $is_excel = $this->is_excel_file_post($file);
                        $open_url = wp_nonce_url(
                            add_query_arg(
                                array(
                                    'action'      => 'shared_docs_open_file',
                                    'file_id'     => $file_id,
                                    'return_page' => 'shared-docs-permissions',
                                ),
                                admin_url('admin-post.php')
                            ),
                            'shared_docs_open_file_' . $file_id
                        );
                        ?>
                        <tr data-resource-row>
                            <td><?php echo $this->render_resource_icon_html('file', $file); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php esc_html_e('Archivo', 'shared-docs-manager'); ?></td>
                            <td><?php echo esc_html($file->post_title); ?></td>
                            <td><?php echo esc_html($folder_name); ?></td>
                            <td><?php echo esc_html($date); ?></td>
                            <td>
                                <button type="button" class="button button-primary shared-docs-open-permissions-view-modal" data-item-type="file" data-item-id="<?php echo (int) $file_id; ?>" data-item-label="<?php echo esc_attr($file->post_title); ?>">
                                    <?php esc_html_e('Ver permisos', 'shared-docs-manager'); ?>
                                </button>
                                <button type="button" class="button shared-docs-open-permissions-manage-modal" data-item-type="file" data-item-id="<?php echo (int) $file_id; ?>" data-item-label="<?php echo esc_attr($file->post_title); ?>">
                                    <?php esc_html_e('Gestión de permisos', 'shared-docs-manager'); ?>
                                </button>
                                <a class="button" href="<?php echo esc_url($open_url); ?>" target="_blank" rel="noopener">
                                    <?php esc_html_e('Abrir', 'shared-docs-manager'); ?>
                                </a>
                                <?php if ($is_excel) : ?>
                                    <button type="button" class="button shared-docs-open-history-modal" data-file-id="<?php echo (int) $file_id; ?>" data-file-label="<?php echo esc_attr($file->post_title); ?>">
                                        <?php esc_html_e('Histórico', 'shared-docs-manager'); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description" data-resource-empty hidden><?php esc_html_e('No hay resultados para la búsqueda actual.', 'shared-docs-manager'); ?></p>
            </section>

            <?php echo $this->render_access_view_modal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->render_access_manage_modal($assignable_users, 'shared-docs-permissions'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->render_excel_history_modal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php
    }

    /**
     * Renderiza página principal del admin.
     *
     * @return void
     */
    public function render_admin_page()
    {
        if (! $this->permission_manager->current_user_can_manage()) {
            wp_die(esc_html__('No tienes permisos para gestionar documentos compartidos.', 'shared-docs-manager'));
        }

        $folders = get_posts(
            array(
                'post_type'      => 'shared_folder',
                'post_status'    => array('publish', 'private'),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );
        $users = get_users(
            array(
                'orderby' => 'display_name',
                'order'   => 'ASC',
                'number'  => 9999,
            )
        );
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
                        'compare' => 'EXISTS',
                    ),
                ),
            )
        );

        $filter_user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
        $folder_permissions = $this->permission_repository->get_all_permissions($filter_user_id);
        $file_permissions = $this->file_permission_repository->get_all_permissions($filter_user_id);

        $editing_permission = null;
        if (isset($_GET['action'], $_GET['permission_id']) && $_GET['action'] === 'edit_permission') {
            $editing_permission = $this->permission_repository->get_permission((int) $_GET['permission_id']);
        }
        $editing_file_permission = null;
        if (isset($_GET['action'], $_GET['permission_id']) && $_GET['action'] === 'edit_file_permission') {
            $editing_file_permission = $this->file_permission_repository->get_permission((int) $_GET['permission_id']);
        }

        $notice_code = isset($_GET['sd_notice']) ? sanitize_key(wp_unslash($_GET['sd_notice'])) : '';
        $notice_text = $this->get_notice_message($notice_code);

        list($folder_children_count, $folder_files_count) = $this->calculate_folder_stats($folders, $files);
        ?>
        <div class="wrap shared-docs-admin-wrap">
            <h1><?php esc_html_e('Shared Document Manager', 'shared-docs-manager'); ?></h1>

            <?php if ($notice_text !== '') : ?>
                <div class="notice notice-info is-dismissible">
                    <p><?php echo esc_html($notice_text); ?></p>
                </div>
            <?php endif; ?>

            <div class="shared-docs-grid-admin">
                <section class="shared-docs-card">
                    <h2><?php esc_html_e('Crear carpeta o subcarpeta', 'shared-docs-manager'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="shared_docs_create_folder" />
                        <?php wp_nonce_field('shared_docs_create_folder'); ?>

                        <label for="shared-folder-name"><?php esc_html_e('Nombre de carpeta', 'shared-docs-manager'); ?></label>
                        <input id="shared-folder-name" type="text" name="folder_name" class="regular-text" required />

                        <label for="shared-folder-parent"><?php esc_html_e('Carpeta padre', 'shared-docs-manager'); ?></label>
                        <select id="shared-folder-parent" name="parent_folder_id">
                            <option value="0"><?php esc_html_e('— Carpeta raíz —', 'shared-docs-manager'); ?></option>
                            <?php echo $this->render_folder_options($folders); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </select>

                        <?php submit_button(__('Crear carpeta', 'shared-docs-manager')); ?>
                    </form>
                </section>

                <section class="shared-docs-card">
                    <h2><?php esc_html_e('Subir archivo', 'shared-docs-manager'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="shared_docs_upload_file" />
                        <?php wp_nonce_field('shared_docs_upload_file'); ?>

                        <label for="shared-upload-folder"><?php esc_html_e('Carpeta destino', 'shared-docs-manager'); ?></label>
                        <select id="shared-upload-folder" name="folder_id" required>
                            <option value=""><?php esc_html_e('Selecciona carpeta...', 'shared-docs-manager'); ?></option>
                            <?php echo $this->render_folder_options($folders); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </select>

                        <label for="shared-upload-file"><?php esc_html_e('Archivo', 'shared-docs-manager'); ?></label>
                        <input id="shared-upload-file" type="file" name="shared_file_upload" required />

                        <?php submit_button(__('Subir archivo', 'shared-docs-manager')); ?>
                    </form>
                </section>

                <section class="shared-docs-card">
                    <h2>
                        <?php
                        echo $editing_permission
                            ? esc_html__('Editar permiso', 'shared-docs-manager')
                            : esc_html__('Asignar permiso por usuario', 'shared-docs-manager');
                        ?>
                    </h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="shared_docs_save_permission" />
                        <?php wp_nonce_field('shared_docs_save_permission'); ?>

                        <?php if ($editing_permission) : ?>
                            <input type="hidden" name="permission_id" value="<?php echo (int) $editing_permission->id; ?>" />
                        <?php endif; ?>

                        <label for="shared-permission-user"><?php esc_html_e('Usuario', 'shared-docs-manager'); ?></label>
                        <select id="shared-permission-user" name="user_id" required>
                            <option value=""><?php esc_html_e('Selecciona usuario...', 'shared-docs-manager'); ?></option>
                            <?php foreach ($users as $user) : ?>
                                <option
                                    value="<?php echo (int) $user->ID; ?>"
                                    <?php selected($editing_permission ? (int) $editing_permission->user_id : 0, (int) $user->ID); ?>
                                >
                                    <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="shared-permission-folder"><?php esc_html_e('Carpeta', 'shared-docs-manager'); ?></label>
                        <select id="shared-permission-folder" name="folder_id" required>
                            <option value=""><?php esc_html_e('Selecciona carpeta...', 'shared-docs-manager'); ?></option>
                            <?php
                            echo $this->render_folder_options(
                                $folders,
                                $editing_permission ? (int) $editing_permission->folder_id : 0
                            ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            ?>
                        </select>

                        <fieldset class="shared-docs-checkboxes">
                            <legend><?php esc_html_e('Permisos', 'shared-docs-manager'); ?></legend>
                            <label>
                                <input
                                    type="checkbox"
                                    name="can_read"
                                    value="1"
                                    <?php checked($editing_permission ? (int) $editing_permission->can_read : 1, 1); ?>
                                />
                                <?php esc_html_e('Lectura', 'shared-docs-manager'); ?>
                            </label>
                            <label>
                                <input
                                    type="checkbox"
                                    name="can_download"
                                    value="1"
                                    <?php checked($editing_permission ? (int) $editing_permission->can_download : 1, 1); ?>
                                />
                                <?php esc_html_e('Descarga', 'shared-docs-manager'); ?>
                            </label>
                            <label>
                                <input
                                    type="checkbox"
                                    name="can_edit_excel"
                                    value="1"
                                    <?php checked($editing_permission ? (int) $editing_permission->can_edit_excel : 0, 1); ?>
                                />
                                <?php esc_html_e('Edición Excel', 'shared-docs-manager'); ?>
                            </label>
                        </fieldset>

                        <label for="shared-permission-expires"><?php esc_html_e('Fecha límite (opcional)', 'shared-docs-manager'); ?></label>
                        <input
                            id="shared-permission-expires"
                            type="datetime-local"
                            name="expires_at"
                            value="<?php echo esc_attr($this->format_datetime_local($editing_permission ? $editing_permission->expires_at : '')); ?>"
                        />

                        <?php
                        submit_button(
                            $editing_permission
                                ? __('Actualizar permiso', 'shared-docs-manager')
                                : __('Guardar permiso', 'shared-docs-manager')
                        );
                        ?>
                    </form>
                </section>

                <section class="shared-docs-card">
                    <h2>
                        <?php
                        echo $editing_file_permission
                            ? esc_html__('Editar permiso por archivo', 'shared-docs-manager')
                            : esc_html__('Asignar permiso por archivo', 'shared-docs-manager');
                        ?>
                    </h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="shared_docs_save_file_permission" />
                        <?php wp_nonce_field('shared_docs_save_file_permission'); ?>

                        <?php if ($editing_file_permission) : ?>
                            <input type="hidden" name="permission_id" value="<?php echo (int) $editing_file_permission->id; ?>" />
                        <?php endif; ?>

                        <label for="shared-file-permission-user"><?php esc_html_e('Usuario', 'shared-docs-manager'); ?></label>
                        <select id="shared-file-permission-user" name="user_id" required>
                            <option value=""><?php esc_html_e('Selecciona usuario...', 'shared-docs-manager'); ?></option>
                            <?php foreach ($users as $user) : ?>
                                <option
                                    value="<?php echo (int) $user->ID; ?>"
                                    <?php selected($editing_file_permission ? (int) $editing_file_permission->user_id : 0, (int) $user->ID); ?>
                                >
                                    <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="shared-file-permission-file"><?php esc_html_e('Archivo', 'shared-docs-manager'); ?></label>
                        <select id="shared-file-permission-file" name="file_id" required>
                            <option value=""><?php esc_html_e('Selecciona archivo...', 'shared-docs-manager'); ?></option>
                            <?php echo $this->render_file_options($files, $editing_file_permission ? (int) $editing_file_permission->file_id : 0); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </select>

                        <fieldset class="shared-docs-checkboxes">
                            <legend><?php esc_html_e('Permisos', 'shared-docs-manager'); ?></legend>
                            <label>
                                <input
                                    type="checkbox"
                                    name="can_read"
                                    value="1"
                                    <?php checked($editing_file_permission ? (int) $editing_file_permission->can_read : 1, 1); ?>
                                />
                                <?php esc_html_e('Lectura', 'shared-docs-manager'); ?>
                            </label>
                            <label>
                                <input
                                    type="checkbox"
                                    name="can_download"
                                    value="1"
                                    <?php checked($editing_file_permission ? (int) $editing_file_permission->can_download : 1, 1); ?>
                                />
                                <?php esc_html_e('Descarga', 'shared-docs-manager'); ?>
                            </label>
                            <label>
                                <input
                                    type="checkbox"
                                    name="can_edit_excel"
                                    value="1"
                                    <?php checked($editing_file_permission ? (int) $editing_file_permission->can_edit_excel : 0, 1); ?>
                                />
                                <?php esc_html_e('Edición Excel', 'shared-docs-manager'); ?>
                            </label>
                        </fieldset>

                        <label for="shared-file-permission-expires"><?php esc_html_e('Fecha límite (opcional)', 'shared-docs-manager'); ?></label>
                        <input
                            id="shared-file-permission-expires"
                            type="datetime-local"
                            name="expires_at"
                            value="<?php echo esc_attr($this->format_datetime_local($editing_file_permission ? $editing_file_permission->expires_at : '')); ?>"
                        />

                        <?php
                        submit_button(
                            $editing_file_permission
                                ? __('Actualizar permiso de archivo', 'shared-docs-manager')
                                : __('Guardar permiso de archivo', 'shared-docs-manager')
                        );
                        ?>
                    </form>
                </section>

                <section class="shared-docs-card shared-docs-card-full">
                    <h2><?php esc_html_e('Asignación masiva (carpetas y archivos)', 'shared-docs-manager'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="shared_docs_bulk_save_permissions" />
                        <?php wp_nonce_field('shared_docs_bulk_save_permissions'); ?>

                        <label for="shared-bulk-users"><?php esc_html_e('Usuarios (múltiple)', 'shared-docs-manager'); ?></label>
                        <select id="shared-bulk-users" name="user_ids[]" multiple size="8" required>
                            <?php foreach ($users as $user) : ?>
                                <option value="<?php echo (int) $user->ID; ?>">
                                    <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Mantén Ctrl/Cmd para seleccionar múltiples usuarios.', 'shared-docs-manager'); ?></p>

                        <label for="shared-bulk-folders"><?php esc_html_e('Carpetas (opcional, múltiple)', 'shared-docs-manager'); ?></label>
                        <select id="shared-bulk-folders" name="folder_ids[]" multiple size="8">
                            <?php echo $this->render_folder_options($folders); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </select>

                        <label for="shared-bulk-files"><?php esc_html_e('Archivos (opcional, múltiple)', 'shared-docs-manager'); ?></label>
                        <select id="shared-bulk-files" name="file_ids[]" multiple size="10">
                            <?php echo $this->render_file_options($files); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </select>
                        <p class="description"><?php esc_html_e('Selecciona al menos una carpeta o un archivo.', 'shared-docs-manager'); ?></p>

                        <fieldset class="shared-docs-checkboxes">
                            <legend><?php esc_html_e('Permisos a aplicar', 'shared-docs-manager'); ?></legend>
                            <label><input type="checkbox" name="can_read" value="1" checked /> <?php esc_html_e('Lectura', 'shared-docs-manager'); ?></label>
                            <label><input type="checkbox" name="can_download" value="1" checked /> <?php esc_html_e('Descarga', 'shared-docs-manager'); ?></label>
                            <label><input type="checkbox" name="can_edit_excel" value="1" /> <?php esc_html_e('Edición Excel', 'shared-docs-manager'); ?></label>
                        </fieldset>

                        <label for="shared-bulk-expires"><?php esc_html_e('Fecha límite común (opcional)', 'shared-docs-manager'); ?></label>
                        <input id="shared-bulk-expires" type="datetime-local" name="expires_at" value="" />

                        <?php submit_button(__('Aplicar permisos en bloque', 'shared-docs-manager')); ?>
                    </form>
                </section>

                <section class="shared-docs-card shared-docs-card-full">
                    <h2><?php esc_html_e('Configuración', 'shared-docs-manager'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="shared_docs_save_settings" />
                        <?php wp_nonce_field('shared_docs_save_settings'); ?>

                        <label for="shared-no-access-message"><?php esc_html_e('Mensaje sin acceso', 'shared-docs-manager'); ?></label>
                        <textarea id="shared-no-access-message" name="no_access_message" rows="3"><?php echo esc_textarea(get_option('shared_docs_no_access_message', '')); ?></textarea>

                        <label class="shared-docs-inline-checkbox">
                            <input type="checkbox" name="enable_inheritance" value="1" <?php checked(get_option('shared_docs_enable_inheritance', '0'), '1'); ?> />
                            <?php esc_html_e('Habilitar herencia opcional de permisos desde carpeta padre', 'shared-docs-manager'); ?>
                        </label>

                        <?php submit_button(__('Guardar configuración', 'shared-docs-manager')); ?>
                    </form>
                </section>
            </div>

            <section class="shared-docs-card shared-docs-card-full">
                <h2><?php esc_html_e('Visualización de carpetas y archivos gestionados', 'shared-docs-manager'); ?></h2>
                <div class="shared-docs-grid-admin">
                    <div>
                        <h3><?php esc_html_e('Carpetas creadas', 'shared-docs-manager'); ?></h3>
                        <?php if (empty($folders)) : ?>
                            <p><?php esc_html_e('No hay carpetas creadas.', 'shared-docs-manager'); ?></p>
                        <?php else : ?>
                            <table class="widefat striped">
                                <thead>
                                <tr>
                                    <th><?php esc_html_e('Carpeta', 'shared-docs-manager'); ?></th>
                                    <th><?php esc_html_e('Padre', 'shared-docs-manager'); ?></th>
                                    <th><?php esc_html_e('Subcarpetas', 'shared-docs-manager'); ?></th>
                                    <th><?php esc_html_e('Archivos', 'shared-docs-manager'); ?></th>
                                    <th><?php esc_html_e('Acciones', 'shared-docs-manager'); ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($folders as $folder) : ?>
                                    <?php
                                    $parent_title = (int) $folder->post_parent > 0 ? get_the_title((int) $folder->post_parent) : __('Raíz', 'shared-docs-manager');
                                    $children_count = isset($folder_children_count[(int) $folder->ID]) ? (int) $folder_children_count[(int) $folder->ID] : 0;
                                    $files_count = isset($folder_files_count[(int) $folder->ID]) ? (int) $folder_files_count[(int) $folder->ID] : 0;
                                    $delete_folder_link = wp_nonce_url(
                                        add_query_arg(
                                            array(
                                                'action'    => 'shared_docs_delete_folder',
                                                'folder_id' => (int) $folder->ID,
                                            ),
                                            admin_url('admin-post.php')
                                        ),
                                        'shared_docs_delete_folder_' . (int) $folder->ID
                                    );
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html($folder->post_title); ?></td>
                                        <td><?php echo esc_html($parent_title); ?></td>
                                        <td><?php echo esc_html((string) $children_count); ?></td>
                                        <td><?php echo esc_html((string) $files_count); ?></td>
                                        <td>
                                            <a href="<?php echo esc_url($delete_folder_link); ?>" onclick="return confirm('<?php echo esc_js(__('¿Eliminar esta carpeta y todo su contenido?', 'shared-docs-manager')); ?>');">
                                                <?php esc_html_e('Borrar', 'shared-docs-manager'); ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <div>
                        <h3><?php esc_html_e('Archivos subidos', 'shared-docs-manager'); ?></h3>
                        <?php if (empty($files)) : ?>
                            <p><?php esc_html_e('No hay archivos subidos.', 'shared-docs-manager'); ?></p>
                        <?php else : ?>
                            <table class="widefat striped">
                                <thead>
                                <tr>
                                    <th><?php esc_html_e('Archivo', 'shared-docs-manager'); ?></th>
                                    <th><?php esc_html_e('Carpeta', 'shared-docs-manager'); ?></th>
                                    <th><?php esc_html_e('Peso', 'shared-docs-manager'); ?></th>
                                    <th><?php esc_html_e('Subido por', 'shared-docs-manager'); ?></th>
                                    <th><?php esc_html_e('Fecha', 'shared-docs-manager'); ?></th>
                                    <th><?php esc_html_e('Acciones', 'shared-docs-manager'); ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($files as $file) : ?>
                                    <?php
                                    $file_id = (int) $file->ID;
                                    $folder_id = (int) get_post_meta($file_id, 'shared_folder_id', true);
                                    $folder_name = $folder_id > 0 ? get_the_title($folder_id) : __('(Sin carpeta)', 'shared-docs-manager');
                                    $path = get_attached_file($file_id);
                                    $size = ($path && file_exists($path)) ? (int) filesize($path) : 0;
                                    $uploader = get_userdata((int) $file->post_author);
                                    $uploader_name = $uploader ? $uploader->display_name : __('(Desconocido)', 'shared-docs-manager');
                                    $delete_file_link = wp_nonce_url(
                                        add_query_arg(
                                            array(
                                                'action'  => 'shared_docs_delete_file',
                                                'file_id' => $file_id,
                                            ),
                                            admin_url('admin-post.php')
                                        ),
                                        'shared_docs_delete_file_' . $file_id
                                    );
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html($file->post_title); ?></td>
                                        <td><?php echo esc_html($folder_name); ?></td>
                                        <td><?php echo esc_html($this->format_bytes($size)); ?></td>
                                        <td><?php echo esc_html($uploader_name); ?></td>
                                        <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($file->post_date_gmt . ' GMT'))); ?></td>
                                        <td>
                                            <a href="<?php echo esc_url($delete_file_link); ?>" onclick="return confirm('<?php echo esc_js(__('¿Eliminar este archivo?', 'shared-docs-manager')); ?>');">
                                                <?php esc_html_e('Borrar', 'shared-docs-manager'); ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="shared-docs-card shared-docs-card-full">
                <h2><?php esc_html_e('Árbol de carpetas y archivos', 'shared-docs-manager'); ?></h2>
                <?php
                $tree_html = $this->render_folder_tree_with_files($folders, $files);
                if ($tree_html === '') :
                    ?>
                    <p><?php esc_html_e('No hay estructura de carpetas/archivos para mostrar.', 'shared-docs-manager'); ?></p>
                <?php else : ?>
                    <?php echo $tree_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endif; ?>
            </section>

            <section class="shared-docs-card shared-docs-card-full">
                <h2><?php esc_html_e('Permisos actuales por carpeta', 'shared-docs-manager'); ?></h2>
                <?php if (empty($folder_permissions)) : ?>
                    <p><?php esc_html_e('No hay permisos asignados.', 'shared-docs-manager'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Usuario', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Carpeta', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Lectura', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Descarga', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Edición Excel', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Expira', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Estado', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Acciones', 'shared-docs-manager'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($folder_permissions as $permission) : ?>
                            <?php
                            $edit_link = add_query_arg(
                                array(
                                    'page'          => 'shared-docs',
                                    'action'        => 'edit_permission',
                                    'permission_id' => (int) $permission->id,
                                ),
                                admin_url('admin.php')
                            );
                            $delete_link = wp_nonce_url(
                                add_query_arg(
                                    array(
                                        'action'        => 'shared_docs_delete_permission',
                                        'permission_id' => (int) $permission->id,
                                    ),
                                    admin_url('admin-post.php')
                                ),
                                'shared_docs_delete_permission_' . (int) $permission->id
                            );
                            $expires_label = empty($permission->expires_at)
                                ? __('Sin límite', 'shared-docs-manager')
                                : wp_date(
                                    get_option('date_format') . ' ' . get_option('time_format'),
                                    strtotime($permission->expires_at)
                                );
                            $is_expired = ! empty($permission->expires_at) && strtotime($permission->expires_at) < current_time('timestamp');
                            ?>
                            <tr>
                                <td><?php echo esc_html($permission->display_name . ' (' . $permission->user_email . ')'); ?></td>
                                <td><?php echo esc_html($permission->folder_name ? $permission->folder_name : __('(Carpeta eliminada)', 'shared-docs-manager')); ?></td>
                                <td><?php echo ! empty($permission->can_read) ? '✔' : '—'; ?></td>
                                <td><?php echo ! empty($permission->can_download) ? '✔' : '—'; ?></td>
                                <td><?php echo ! empty($permission->can_edit_excel) ? '✔' : '—'; ?></td>
                                <td><?php echo esc_html($expires_label); ?></td>
                                <td>
                                    <?php if ($is_expired) : ?>
                                        <span class="shared-docs-badge shared-docs-badge-danger"><?php esc_html_e('Expirado', 'shared-docs-manager'); ?></span>
                                    <?php else : ?>
                                        <span class="shared-docs-badge shared-docs-badge-success"><?php esc_html_e('Activo', 'shared-docs-manager'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($edit_link); ?>"><?php esc_html_e('Editar', 'shared-docs-manager'); ?></a>
                                    |
                                    <a href="<?php echo esc_url($delete_link); ?>" onclick="return confirm('<?php echo esc_js(__('¿Revocar este permiso?', 'shared-docs-manager')); ?>');">
                                        <?php esc_html_e('Revocar', 'shared-docs-manager'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="shared-docs-card shared-docs-card-full">
                <h2><?php esc_html_e('Permisos actuales por archivo', 'shared-docs-manager'); ?></h2>
                <?php if (empty($file_permissions)) : ?>
                    <p><?php esc_html_e('No hay permisos por archivo asignados.', 'shared-docs-manager'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Usuario', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Archivo', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Carpeta', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Lectura', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Descarga', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Edición Excel', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Expira', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Estado', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Acciones', 'shared-docs-manager'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($file_permissions as $permission) : ?>
                            <?php
                            $edit_link = add_query_arg(
                                array(
                                    'page'          => 'shared-docs',
                                    'action'        => 'edit_file_permission',
                                    'permission_id' => (int) $permission->id,
                                ),
                                admin_url('admin.php')
                            );
                            $delete_link = wp_nonce_url(
                                add_query_arg(
                                    array(
                                        'action'        => 'shared_docs_delete_file_permission',
                                        'permission_id' => (int) $permission->id,
                                    ),
                                    admin_url('admin-post.php')
                                ),
                                'shared_docs_delete_file_permission_' . (int) $permission->id
                            );
                            $expires_label = empty($permission->expires_at)
                                ? __('Sin límite', 'shared-docs-manager')
                                : wp_date(
                                    get_option('date_format') . ' ' . get_option('time_format'),
                                    strtotime($permission->expires_at)
                                );
                            $is_expired = ! empty($permission->expires_at) && strtotime($permission->expires_at) < current_time('timestamp');
                            $folder_id = (int) get_post_meta((int) $permission->file_id, 'shared_folder_id', true);
                            $folder_name = $folder_id > 0 ? get_the_title($folder_id) : __('(Sin carpeta)', 'shared-docs-manager');
                            $file_name = $permission->file_name ? $permission->file_name : __('(Archivo eliminado)', 'shared-docs-manager');
                            ?>
                            <tr>
                                <td><?php echo esc_html($permission->display_name . ' (' . $permission->user_email . ')'); ?></td>
                                <td><?php echo esc_html($file_name); ?></td>
                                <td><?php echo esc_html($folder_name); ?></td>
                                <td><?php echo ! empty($permission->can_read) ? '✔' : '—'; ?></td>
                                <td><?php echo ! empty($permission->can_download) ? '✔' : '—'; ?></td>
                                <td><?php echo ! empty($permission->can_edit_excel) ? '✔' : '—'; ?></td>
                                <td><?php echo esc_html($expires_label); ?></td>
                                <td>
                                    <?php if ($is_expired) : ?>
                                        <span class="shared-docs-badge shared-docs-badge-danger"><?php esc_html_e('Expirado', 'shared-docs-manager'); ?></span>
                                    <?php else : ?>
                                        <span class="shared-docs-badge shared-docs-badge-success"><?php esc_html_e('Activo', 'shared-docs-manager'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($edit_link); ?>"><?php esc_html_e('Editar', 'shared-docs-manager'); ?></a>
                                    |
                                    <a href="<?php echo esc_url($delete_link); ?>" onclick="return confirm('<?php echo esc_js(__('¿Revocar este permiso de archivo?', 'shared-docs-manager')); ?>');">
                                        <?php esc_html_e('Revocar', 'shared-docs-manager'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    /**
     * Muestra permisos en ficha de usuario.
     *
     * @param WP_User $user Usuario.
     *
     * @return void
     */
    public function render_user_permissions_block($user)
    {
        if (! $this->permission_manager->current_user_can_manage()) {
            return;
        }
        ?>
        <h2><?php esc_html_e('Permisos de documentos compartidos', 'shared-docs-manager'); ?></h2>
        <?php echo \shared_get_user_permissions_html((int) $user->ID); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php
    }

    /**
     * Handler: crear carpeta.
     *
     * @return void
     */
    public function handle_create_folder()
    {
        $this->deny_if_not_manager();
        check_admin_referer('shared_docs_create_folder');

        $folder_name = isset($_POST['folder_name']) ? sanitize_text_field(wp_unslash($_POST['folder_name'])) : '';
        $parent_id = isset($_POST['parent_folder_id']) ? (int) $_POST['parent_folder_id'] : 0;

        if ($folder_name === '') {
            $this->redirect_with_notice('folder_invalid', array(), 'shared-docs');
        }

        if ($parent_id > 0) {
            $parent = get_post($parent_id);
            if (! $parent || $parent->post_type !== 'shared_folder') {
                $parent_id = 0;
            }
        }

        $inserted = wp_insert_post(
            array(
                'post_type'   => 'shared_folder',
                'post_title'  => $folder_name,
                'post_parent' => $parent_id,
                'post_status' => 'publish',
            ),
            true
        );

        if (is_wp_error($inserted)) {
            $this->redirect_with_notice('folder_error', array(), 'shared-docs');
        }

        $this->redirect_with_notice('folder_created', array(), 'shared-docs');
    }

    /**
     * Handler: renombrar carpeta.
     *
     * @return void
     */
    public function handle_rename_folder()
    {
        $this->deny_if_not_manager();

        $folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
        if ($folder_id <= 0) {
            $this->redirect_with_notice('folder_rename_invalid', array(), 'shared-docs');
        }

        check_admin_referer('shared_docs_rename_folder');
        $folder_name = isset($_POST['folder_name']) ? sanitize_text_field(wp_unslash($_POST['folder_name'])) : '';
        if ($folder_name === '') {
            $this->redirect_with_notice('folder_rename_invalid', array(), 'shared-docs');
        }

        $folder = get_post($folder_id);
        if (! $folder || $folder->post_type !== 'shared_folder') {
            $this->redirect_with_notice('folder_rename_invalid', array(), 'shared-docs');
        }

        $updated = wp_update_post(
            array(
                'ID'         => $folder_id,
                'post_title' => $folder_name,
            ),
            true
        );

        if (is_wp_error($updated)) {
            $this->redirect_with_notice('folder_rename_error', array(), 'shared-docs');
        }

        $this->redirect_with_notice('folder_renamed', array(), 'shared-docs');
    }

    /**
     * Handler: subir archivo.
     *
     * @return void
     */
    public function handle_upload_file()
    {
        $this->deny_if_not_manager();
        check_admin_referer('shared_docs_upload_file');

        $folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
        if ($folder_id <= 0 || empty($_FILES['shared_file_upload'])) {
            $this->redirect_with_notice('upload_invalid', array(), 'shared-docs');
        }

        $uploaded = $this->file_helper->upload_attachment_to_folder(
            $_FILES['shared_file_upload'],
            $folder_id,
            get_current_user_id()
        );

        if (is_wp_error($uploaded)) {
            $this->redirect_with_notice('upload_error', array(), 'shared-docs');
        }

        $this->redirect_with_notice('upload_ok', array(), 'shared-docs');
    }

    /**
     * Handler: mover carpeta o archivo a otra carpeta.
     *
     * @return void
     */
    public function handle_move_item()
    {
        $this->deny_if_not_manager();
        check_admin_referer('shared_docs_move_item');

        $item_type = isset($_POST['item_type']) ? sanitize_key(wp_unslash($_POST['item_type'])) : '';
        $item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
        $target_folder_id = isset($_POST['target_folder_id']) ? (int) $_POST['target_folder_id'] : 0;

        if ($item_id <= 0 || ! in_array($item_type, array('folder', 'file'), true)) {
            $this->redirect_with_notice('move_invalid', array(), 'shared-docs');
        }

        if ($item_type === 'folder') {
            $folder = get_post($item_id);
            if (! $folder || $folder->post_type !== 'shared_folder') {
                $this->redirect_with_notice('move_invalid', array(), 'shared-docs');
            }

            if ($target_folder_id > 0) {
                $target_folder = get_post($target_folder_id);
                if (! $target_folder || $target_folder->post_type !== 'shared_folder') {
                    $this->redirect_with_notice('move_invalid', array(), 'shared-docs');
                }
            }

            $invalid_targets = $this->get_descendant_folder_ids($item_id);
            if (in_array($target_folder_id, $invalid_targets, true)) {
                $this->redirect_with_notice('move_invalid_target', array(), 'shared-docs');
            }

            $updated = wp_update_post(
                array(
                    'ID'          => $item_id,
                    'post_parent' => $target_folder_id,
                ),
                true
            );

            if (is_wp_error($updated)) {
                $this->redirect_with_notice('move_error', array(), 'shared-docs');
            }

            $this->redirect_with_notice('move_ok', array(), 'shared-docs');
        }

        // Mover archivo.
        $file = get_post($item_id);
        if (! $file || $file->post_type !== 'attachment') {
            $this->redirect_with_notice('move_invalid', array(), 'shared-docs');
        }

        if ($target_folder_id <= 0) {
            $this->redirect_with_notice('move_invalid_target', array(), 'shared-docs');
        }

        $target_folder = get_post($target_folder_id);
        if (! $target_folder || $target_folder->post_type !== 'shared_folder') {
            $this->redirect_with_notice('move_invalid_target', array(), 'shared-docs');
        }

        update_post_meta($item_id, 'shared_folder_id', $target_folder_id);
        wp_update_post(
            array(
                'ID'          => $item_id,
                'post_parent' => $target_folder_id,
            )
        );

        $this->redirect_with_notice('move_ok', array(), 'shared-docs');
    }

    /**
     * Handler: mover elementos seleccionados en bloque.
     *
     * @return void
     */
    public function handle_bulk_move_items()
    {
        $this->deny_if_not_manager();
        check_admin_referer('shared_docs_bulk_move_items');

        $target_folder_id = isset($_POST['target_folder_id']) ? (int) $_POST['target_folder_id'] : 0;
        $folder_ids = $this->csv_to_int_array(isset($_POST['folder_ids']) ? wp_unslash($_POST['folder_ids']) : '');
        $file_ids = $this->csv_to_int_array(isset($_POST['file_ids']) ? wp_unslash($_POST['file_ids']) : '');

        if (empty($folder_ids) && empty($file_ids)) {
            $this->redirect_with_notice('bulk_move_invalid', array(), 'shared-docs');
        }

        if ($target_folder_id <= 0) {
            $this->redirect_with_notice('bulk_move_invalid_target', array(), 'shared-docs');
        }

        $target_folder = get_post($target_folder_id);
        if (! $target_folder || $target_folder->post_type !== 'shared_folder') {
            $this->redirect_with_notice('bulk_move_invalid_target', array(), 'shared-docs');
        }

        $has_error = false;

        foreach ($folder_ids as $folder_id) {
            $folder = get_post($folder_id);
            if (! $folder || $folder->post_type !== 'shared_folder') {
                $has_error = true;
                continue;
            }

            $invalid_targets = $this->get_descendant_folder_ids($folder_id);
            if (in_array($target_folder_id, $invalid_targets, true)) {
                $has_error = true;
                continue;
            }

            $updated = wp_update_post(
                array(
                    'ID'          => $folder_id,
                    'post_parent' => $target_folder_id,
                ),
                true
            );

            if (is_wp_error($updated)) {
                $has_error = true;
            }
        }

        foreach ($file_ids as $file_id) {
            $file = get_post($file_id);
            if (! $file || $file->post_type !== 'attachment') {
                $has_error = true;
                continue;
            }

            update_post_meta($file_id, 'shared_folder_id', $target_folder_id);
            wp_update_post(
                array(
                    'ID'          => $file_id,
                    'post_parent' => $target_folder_id,
                )
            );
        }

        $this->redirect_with_notice($has_error ? 'bulk_move_error' : 'bulk_move_ok', array(), 'shared-docs');
    }

    /**
     * Handler: borrar elementos seleccionados en bloque.
     *
     * @return void
     */
    public function handle_bulk_delete_items()
    {
        $this->deny_if_not_manager();
        check_admin_referer('shared_docs_bulk_delete_items');

        $selected_folder_ids = $this->csv_to_int_array(isset($_POST['folder_ids']) ? wp_unslash($_POST['folder_ids']) : '');
        $selected_file_ids = $this->csv_to_int_array(isset($_POST['file_ids']) ? wp_unslash($_POST['file_ids']) : '');

        if (empty($selected_folder_ids) && empty($selected_file_ids)) {
            $this->redirect_with_notice('bulk_delete_invalid', array(), 'shared-docs');
        }

        $all_folder_ids = array();
        foreach ($selected_folder_ids as $folder_id) {
            foreach ($this->get_descendant_folder_ids($folder_id) as $descendant_id) {
                if (! in_array($descendant_id, $all_folder_ids, true)) {
                    $all_folder_ids[] = (int) $descendant_id;
                }
            }
        }

        $files_from_folders = array();
        if (! empty($all_folder_ids)) {
            $files_from_folders = get_posts(
                array(
                    'post_type'      => 'attachment',
                    'post_status'    => array('inherit', 'private'),
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'     => 'shared_folder_id',
                            'value'   => $all_folder_ids,
                            'compare' => 'IN',
                        ),
                    ),
                )
            );
        }

        $all_file_ids = array_values(array_unique(array_merge($selected_file_ids, array_map('intval', $files_from_folders))));

        $has_error = false;
        if (! empty($all_file_ids)) {
            $this->file_permission_repository->delete_permissions_by_file_ids($all_file_ids);
            foreach ($all_file_ids as $file_id) {
                if (wp_delete_attachment((int) $file_id, true) === false) {
                    $has_error = true;
                }
            }
        }

        if (! empty($all_folder_ids)) {
            $this->permission_repository->delete_permissions_by_folder_ids($all_folder_ids);
            foreach ($all_folder_ids as $folder_id) {
                if (wp_delete_post((int) $folder_id, true) === false) {
                    $has_error = true;
                }
            }
        }

        $this->redirect_with_notice($has_error ? 'bulk_delete_error' : 'bulk_delete_ok', array(), 'shared-docs');
    }

    /**
     * Handler: abrir archivo desde administración.
     *
     * @return void
     */
    public function handle_open_file()
    {
        $this->deny_if_not_manager();

        $file_id = isset($_GET['file_id']) ? (int) $_GET['file_id'] : 0;
        if ($file_id <= 0) {
            $this->redirect_with_notice('file_open_invalid', array(), 'shared-docs');
        }

        check_admin_referer('shared_docs_open_file_' . $file_id);
        $path = get_attached_file($file_id);
        if (! $path || ! file_exists($path)) {
            $this->redirect_with_notice('file_open_invalid', array(), 'shared-docs');
        }

        $mime = (string) get_post_mime_type($file_id);
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($path)) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /**
     * Handler: descarga de archivo desde administración.
     *
     * @return void
     */
    public function handle_download_file()
    {
        $this->deny_if_not_manager();

        $file_id = isset($_GET['file_id']) ? (int) $_GET['file_id'] : 0;
        if ($file_id <= 0) {
            $this->redirect_with_notice('file_open_invalid', array(), 'shared-docs');
        }

        check_admin_referer('shared_docs_download_file_' . $file_id);
        $path = get_attached_file($file_id);
        if (! $path || ! file_exists($path)) {
            $this->redirect_with_notice('file_open_invalid', array(), 'shared-docs');
        }

        $mime = (string) get_post_mime_type($file_id);
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($path)) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /**
     * Handler: añadir usuarios en bloque a carpeta o archivo desde modal.
     *
     * @return void
     */
    public function handle_add_item_access_bulk()
    {
        $this->deny_if_not_manager();
        check_admin_referer('shared_docs_add_item_access_bulk');

        $item_type = isset($_POST['item_type']) ? sanitize_key(wp_unslash($_POST['item_type'])) : '';
        $item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
        $user_ids = isset($_POST['user_ids']) ? (array) wp_unslash($_POST['user_ids']) : array();
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));

        $can_read = ! empty($_POST['can_read']) ? 1 : 0;
        $can_download = ! empty($_POST['can_download']) ? 1 : 0;
        $can_edit_excel = ! empty($_POST['can_edit_excel']) ? 1 : 0;
        if ($can_download || $can_edit_excel) {
            $can_read = 1;
        }
        $expires_at = $this->normalize_datetime_local(
            isset($_POST['expires_at']) ? sanitize_text_field(wp_unslash($_POST['expires_at'])) : ''
        );

        if ($item_id <= 0 || empty($user_ids) || ! in_array($item_type, array('folder', 'file'), true)) {
            $this->redirect_with_notice('access_modal_invalid', array(), 'shared-docs');
        }

        $has_error = false;
        foreach ($user_ids as $user_id) {
            if ($item_type === 'folder') {
                $result = $this->permission_repository->upsert_permission(
                    array(
                        'user_id'        => $user_id,
                        'folder_id'      => $item_id,
                        'can_read'       => $can_read,
                        'can_download'   => $can_download,
                        'can_edit_excel' => $can_edit_excel,
                        'expires_at'     => $expires_at,
                    )
                );
            } else {
                $result = $this->file_permission_repository->upsert_permission(
                    array(
                        'user_id'        => $user_id,
                        'file_id'        => $item_id,
                        'can_read'       => $can_read,
                        'can_download'   => $can_download,
                        'can_edit_excel' => $can_edit_excel,
                        'expires_at'     => $expires_at,
                    )
                );
            }

            if ($result instanceof WP_Error) {
                $has_error = true;
            }
        }

        $this->redirect_with_notice($has_error ? 'access_modal_error' : 'access_modal_saved', array(), 'shared-docs');
    }

    /**
     * Handler: eliminar carpeta (con subcarpetas/archivos).
     *
     * @return void
     */
    public function handle_delete_folder()
    {
        $this->deny_if_not_manager();

        $folder_id = isset($_GET['folder_id']) ? (int) $_GET['folder_id'] : 0;
        if ($folder_id <= 0) {
            $this->redirect_with_notice('folder_delete_invalid', array(), 'shared-docs');
        }

        check_admin_referer('shared_docs_delete_folder_' . $folder_id);

        $folder = get_post($folder_id);
        if (! $folder || $folder->post_type !== 'shared_folder') {
            $this->redirect_with_notice('folder_delete_invalid', array(), 'shared-docs');
        }

        $folder_ids = $this->get_descendant_folder_ids($folder_id);
        if (empty($folder_ids)) {
            $folder_ids = array($folder_id);
        }

        $file_ids = get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => array('inherit', 'private'),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => 'shared_folder_id',
                        'value'   => array_map('intval', $folder_ids),
                        'compare' => 'IN',
                    ),
                ),
            )
        );

        $has_error = false;
        if (! empty($file_ids)) {
            $this->file_permission_repository->delete_permissions_by_file_ids($file_ids);
            foreach ($file_ids as $file_id) {
                $deleted_file = wp_delete_attachment((int) $file_id, true);
                if ($deleted_file === false) {
                    $has_error = true;
                }
            }
        }

        $this->permission_repository->delete_permissions_by_folder_ids($folder_ids);

        foreach ((array) $folder_ids as $delete_folder_id) {
            $deleted_folder = wp_delete_post((int) $delete_folder_id, true);
            if ($deleted_folder === false) {
                $has_error = true;
            }
        }

        $this->redirect_with_notice($has_error ? 'folder_delete_error' : 'folder_deleted', array(), 'shared-docs');
    }

    /**
     * Handler: eliminar archivo.
     *
     * @return void
     */
    public function handle_delete_file()
    {
        $this->deny_if_not_manager();

        $file_id = isset($_GET['file_id']) ? (int) $_GET['file_id'] : 0;
        if ($file_id <= 0) {
            $this->redirect_with_notice('file_delete_invalid', array(), 'shared-docs');
        }

        check_admin_referer('shared_docs_delete_file_' . $file_id);

        $file_post = get_post($file_id);
        if (! $file_post || $file_post->post_type !== 'attachment') {
            $this->redirect_with_notice('file_delete_invalid', array(), 'shared-docs');
        }

        $this->file_permission_repository->delete_permissions_by_file_ids(array($file_id));
        $deleted = wp_delete_attachment($file_id, true);

        $this->redirect_with_notice($deleted === false ? 'file_delete_error' : 'file_deleted', array(), 'shared-docs');
    }

    /**
     * Handler: guardar permiso.
     *
     * @return void
     */
    public function handle_save_permission()
    {
        $this->deny_if_not_manager();
        check_admin_referer('shared_docs_save_permission');

        $permission_id = isset($_POST['permission_id']) ? (int) $_POST['permission_id'] : 0;
        $folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
        $user_ids = $this->extract_user_ids_from_request();

        $can_read = ! empty($_POST['can_read']) ? 1 : 0;
        $can_download = ! empty($_POST['can_download']) ? 1 : 0;
        $can_edit_excel = ! empty($_POST['can_edit_excel']) ? 1 : 0;

        if ($can_download || $can_edit_excel) {
            $can_read = 1;
        }

        $expires_at = $this->normalize_datetime_local(
            isset($_POST['expires_at']) ? sanitize_text_field(wp_unslash($_POST['expires_at'])) : ''
        );

        if ($folder_id <= 0 || empty($user_ids)) {
            $this->redirect_with_notice('permission_invalid');
        }

        // En modo edición, mantiene semántica 1 registro.
        if ($permission_id > 0 && count($user_ids) > 1) {
            $user_ids = array((int) $user_ids[0]);
        }

        if ($permission_id > 0) {
            $existing = $this->permission_repository->get_permission($permission_id);
            $new_user_id = (int) $user_ids[0];
            if ($existing && ((int) $existing->user_id !== $new_user_id || (int) $existing->folder_id !== $folder_id)) {
                $this->permission_repository->delete_permission($permission_id);
            }
        }

        $has_error = false;
        foreach ($user_ids as $user_id) {
            $result = $this->permission_repository->upsert_permission(
                array(
                    'user_id'        => (int) $user_id,
                    'folder_id'      => $folder_id,
                    'can_read'       => $can_read,
                    'can_download'   => $can_download,
                    'can_edit_excel' => $can_edit_excel,
                    'expires_at'     => $expires_at,
                )
            );

            if ($result instanceof WP_Error) {
                $has_error = true;
            }
        }

        $this->redirect_with_notice($has_error ? 'permission_error' : 'permission_saved');
    }

    /**
     * Handler: guardar permiso por archivo.
     *
     * @return void
     */
    public function handle_save_file_permission()
    {
        $this->deny_if_not_manager();
        check_admin_referer('shared_docs_save_file_permission');

        $permission_id = isset($_POST['permission_id']) ? (int) $_POST['permission_id'] : 0;
        $file_id = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;
        $user_ids = $this->extract_user_ids_from_request();

        $can_read = ! empty($_POST['can_read']) ? 1 : 0;
        $can_download = ! empty($_POST['can_download']) ? 1 : 0;
        $can_edit_excel = ! empty($_POST['can_edit_excel']) ? 1 : 0;

        if ($can_download || $can_edit_excel) {
            $can_read = 1;
        }

        $expires_at = $this->normalize_datetime_local(
            isset($_POST['expires_at']) ? sanitize_text_field(wp_unslash($_POST['expires_at'])) : ''
        );

        if ($file_id <= 0 || empty($user_ids)) {
            $this->redirect_with_notice('file_permission_invalid');
        }

        if ($permission_id > 0 && count($user_ids) > 1) {
            $user_ids = array((int) $user_ids[0]);
        }

        if ($permission_id > 0) {
            $existing = $this->file_permission_repository->get_permission($permission_id);
            $new_user_id = (int) $user_ids[0];
            if ($existing && ((int) $existing->user_id !== $new_user_id || (int) $existing->file_id !== $file_id)) {
                $this->file_permission_repository->delete_permission($permission_id);
            }
        }

        $has_error = false;
        foreach ($user_ids as $user_id) {
            $result = $this->file_permission_repository->upsert_permission(
                array(
                    'user_id'        => (int) $user_id,
                    'file_id'        => $file_id,
                    'can_read'       => $can_read,
                    'can_download'   => $can_download,
                    'can_edit_excel' => $can_edit_excel,
                    'expires_at'     => $expires_at,
                )
            );

            if ($result instanceof WP_Error) {
                $has_error = true;
            }
        }

        $this->redirect_with_notice($has_error ? 'file_permission_error' : 'file_permission_saved');
    }

    /**
     * Handler: guardar permisos en bloque.
     *
     * @return void
     */
    public function handle_bulk_save_permissions()
    {
        $this->deny_if_not_manager();
        check_admin_referer('shared_docs_bulk_save_permissions');

        $user_ids = isset($_POST['user_ids']) ? (array) wp_unslash($_POST['user_ids']) : array();
        $folder_ids = isset($_POST['folder_ids']) ? (array) wp_unslash($_POST['folder_ids']) : array();
        $file_ids = isset($_POST['file_ids']) ? (array) wp_unslash($_POST['file_ids']) : array();

        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        $folder_ids = array_values(array_unique(array_filter(array_map('intval', $folder_ids))));
        $file_ids = array_values(array_unique(array_filter(array_map('intval', $file_ids))));

        if (empty($user_ids) || (empty($folder_ids) && empty($file_ids))) {
            $this->redirect_with_notice('bulk_invalid');
        }

        $can_read = ! empty($_POST['can_read']) ? 1 : 0;
        $can_download = ! empty($_POST['can_download']) ? 1 : 0;
        $can_edit_excel = ! empty($_POST['can_edit_excel']) ? 1 : 0;
        if ($can_download || $can_edit_excel) {
            $can_read = 1;
        }

        $expires_at = $this->normalize_datetime_local(
            isset($_POST['expires_at']) ? sanitize_text_field(wp_unslash($_POST['expires_at'])) : ''
        );

        $has_error = false;
        foreach ($user_ids as $user_id) {
            foreach ($folder_ids as $folder_id) {
                $result = $this->permission_repository->upsert_permission(
                    array(
                        'user_id'        => $user_id,
                        'folder_id'      => $folder_id,
                        'can_read'       => $can_read,
                        'can_download'   => $can_download,
                        'can_edit_excel' => $can_edit_excel,
                        'expires_at'     => $expires_at,
                    )
                );
                if ($result instanceof WP_Error) {
                    $has_error = true;
                }
            }

            foreach ($file_ids as $file_id) {
                $result = $this->file_permission_repository->upsert_permission(
                    array(
                        'user_id'        => $user_id,
                        'file_id'        => $file_id,
                        'can_read'       => $can_read,
                        'can_download'   => $can_download,
                        'can_edit_excel' => $can_edit_excel,
                        'expires_at'     => $expires_at,
                    )
                );
                if ($result instanceof WP_Error) {
                    $has_error = true;
                }
            }
        }

        $this->redirect_with_notice($has_error ? 'bulk_error' : 'bulk_saved');
    }

    /**
     * Handler: revocar permiso manualmente.
     *
     * @return void
     */
    public function handle_delete_permission()
    {
        $this->deny_if_not_manager();

        $permission_id = isset($_GET['permission_id']) ? (int) $_GET['permission_id'] : 0;
        if ($permission_id <= 0) {
            $this->redirect_with_notice('permission_invalid');
        }

        check_admin_referer('shared_docs_delete_permission_' . $permission_id);
        $this->permission_repository->delete_permission($permission_id);

        $this->redirect_with_notice('permission_deleted');
    }

    /**
     * Handler: revocar permiso por archivo.
     *
     * @return void
     */
    public function handle_delete_file_permission()
    {
        $this->deny_if_not_manager();

        $permission_id = isset($_GET['permission_id']) ? (int) $_GET['permission_id'] : 0;
        if ($permission_id <= 0) {
            $this->redirect_with_notice('file_permission_invalid');
        }

        check_admin_referer('shared_docs_delete_file_permission_' . $permission_id);
        $this->file_permission_repository->delete_permission($permission_id);

        $this->redirect_with_notice('file_permission_deleted');
    }

    /**
     * Handler: guardar ajustes.
     *
     * @return void
     */
    public function handle_save_settings()
    {
        $this->deny_if_not_manager();
        check_admin_referer('shared_docs_save_settings');

        $message = isset($_POST['no_access_message']) ? sanitize_textarea_field(wp_unslash($_POST['no_access_message'])) : '';
        $inheritance = ! empty($_POST['enable_inheritance']) ? '1' : '0';

        update_option('shared_docs_no_access_message', $message);
        update_option('shared_docs_enable_inheritance', $inheritance);

        $this->redirect_with_notice('settings_saved');
    }

    /**
     * Bloquea acceso si el usuario actual no es gestor.
     *
     * @return void
     */
    private function deny_if_not_manager()
    {
        if (! $this->permission_manager->current_user_can_manage()) {
            wp_die(esc_html__('No autorizado.', 'shared-docs-manager'));
        }
    }

    /**
     * Redirección estandarizada con aviso.
     *
     * @param string $notice_code Código de aviso.
     * @param array  $extra       Query args extra.
     *
     * @return void
     */
    private function redirect_with_notice($notice_code, $extra = array(), $default_page = 'shared-docs-permissions')
    {
        $args = array_merge(
            array(
                'page'      => $this->resolve_return_page($default_page),
                'sd_notice' => $notice_code,
            ),
            $extra
        );
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * Resuelve la página de retorno permitida.
     *
     * @param string $default_page Página por defecto.
     *
     * @return string
     */
    private function resolve_return_page($default_page = 'shared-docs-permissions')
    {
        $allowed_pages = array('shared-docs', 'shared-docs-permissions');
        $request_page = isset($_REQUEST['return_page']) ? sanitize_key(wp_unslash($_REQUEST['return_page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($request_page !== '' && in_array($request_page, $allowed_pages, true)) {
            return $request_page;
        }

        return in_array($default_page, $allowed_pages, true) ? $default_page : 'shared-docs-permissions';
    }

    /**
     * Mensajes de aviso admin.
     *
     * @param string $notice_code Código.
     *
     * @return string
     */
    private function get_notice_message($notice_code)
    {
        $messages = array(
            'folder_created'    => __('Carpeta creada correctamente.', 'shared-docs-manager'),
            'folder_invalid'    => __('Nombre de carpeta inválido.', 'shared-docs-manager'),
            'folder_error'      => __('No se pudo crear la carpeta.', 'shared-docs-manager'),
            'folder_renamed'    => __('Nombre de carpeta actualizado correctamente.', 'shared-docs-manager'),
            'folder_rename_invalid' => __('No se pudo renombrar la carpeta: datos inválidos.', 'shared-docs-manager'),
            'folder_rename_error'   => __('No se pudo actualizar el nombre de la carpeta.', 'shared-docs-manager'),
            'folder_deleted'    => __('Carpeta eliminada correctamente.', 'shared-docs-manager'),
            'folder_delete_error' => __('La carpeta se eliminó parcialmente o hubo errores al borrar su contenido.', 'shared-docs-manager'),
            'folder_delete_invalid' => __('No se pudo identificar la carpeta a eliminar.', 'shared-docs-manager'),
            'upload_ok'         => __('Archivo subido correctamente.', 'shared-docs-manager'),
            'upload_invalid'    => __('Datos de subida inválidos.', 'shared-docs-manager'),
            'upload_error'      => __('No se pudo subir el archivo.', 'shared-docs-manager'),
            'file_deleted'      => __('Archivo eliminado correctamente.', 'shared-docs-manager'),
            'file_delete_error' => __('No se pudo eliminar el archivo.', 'shared-docs-manager'),
            'file_delete_invalid' => __('No se pudo identificar el archivo a eliminar.', 'shared-docs-manager'),
            'move_ok'           => __('Elemento movido correctamente.', 'shared-docs-manager'),
            'move_error'        => __('No se pudo mover el elemento.', 'shared-docs-manager'),
            'move_invalid'      => __('No se pudo mover el elemento: datos inválidos.', 'shared-docs-manager'),
            'move_invalid_target' => __('Destino inválido para mover el elemento.', 'shared-docs-manager'),
            'bulk_move_ok'      => __('Selección movida correctamente.', 'shared-docs-manager'),
            'bulk_move_error'   => __('Se movieron algunos elementos, pero hubo errores en parte de la selección.', 'shared-docs-manager'),
            'bulk_move_invalid' => __('Selecciona al menos un elemento para mover.', 'shared-docs-manager'),
            'bulk_move_invalid_target' => __('Debes seleccionar una carpeta destino válida para la selección.', 'shared-docs-manager'),
            'bulk_delete_ok'    => __('Selección eliminada correctamente.', 'shared-docs-manager'),
            'bulk_delete_error' => __('Se eliminaron algunos elementos, pero hubo errores durante el proceso.', 'shared-docs-manager'),
            'bulk_delete_invalid' => __('Selecciona al menos un elemento para borrar.', 'shared-docs-manager'),
            'file_open_invalid' => __('No se pudo abrir el archivo solicitado.', 'shared-docs-manager'),
            'permission_saved'  => __('Permiso guardado correctamente.', 'shared-docs-manager'),
            'permission_deleted'=> __('Permiso revocado correctamente.', 'shared-docs-manager'),
            'permission_invalid'=> __('Datos de permiso inválidos.', 'shared-docs-manager'),
            'permission_error'  => __('No se pudo guardar el permiso.', 'shared-docs-manager'),
            'file_permission_saved'   => __('Permiso por archivo guardado correctamente.', 'shared-docs-manager'),
            'file_permission_deleted' => __('Permiso por archivo revocado correctamente.', 'shared-docs-manager'),
            'file_permission_invalid' => __('Datos de permiso por archivo inválidos.', 'shared-docs-manager'),
            'file_permission_error'   => __('No se pudo guardar el permiso por archivo.', 'shared-docs-manager'),
            'bulk_saved'              => __('Asignación masiva completada.', 'shared-docs-manager'),
            'bulk_invalid'            => __('Debes seleccionar usuarios y al menos una carpeta o archivo.', 'shared-docs-manager'),
            'bulk_error'              => __('Se aplicaron algunos cambios, pero hubo errores en parte de la asignación.', 'shared-docs-manager'),
            'access_modal_saved'      => __('Permisos actualizados correctamente desde el modal.', 'shared-docs-manager'),
            'access_modal_invalid'    => __('Datos inválidos al guardar accesos desde el modal.', 'shared-docs-manager'),
            'access_modal_error'      => __('No se pudieron guardar todos los accesos desde el modal.', 'shared-docs-manager'),
            'settings_saved'    => __('Configuración guardada.', 'shared-docs-manager'),
        );

        return isset($messages[$notice_code]) ? $messages[$notice_code] : '';
    }

    /**
     * Renderiza opciones de carpetas jerárquicas.
     *
     * @param array $folders  Lista de carpetas.
     * @param int   $selected ID seleccionado.
     *
     * @return string
     */
    private function render_folder_options($folders, $selected = 0)
    {
        $children_map = array();
        foreach ((array) $folders as $folder) {
            $parent_id = (int) $folder->post_parent;
            if (! isset($children_map[$parent_id])) {
                $children_map[$parent_id] = array();
            }
            $children_map[$parent_id][] = $folder;
        }

        return $this->render_folder_options_recursive($children_map, 0, $selected, 0);
    }

    /**
     * Render recursivo de opciones.
     *
     * @param array $children_map Mapa padre=>hijos.
     * @param int   $parent_id    Padre.
     * @param int   $selected     Seleccionado.
     * @param int   $depth        Profundidad.
     *
     * @return string
     */
    private function render_folder_options_recursive($children_map, $parent_id, $selected, $depth)
    {
        if (empty($children_map[$parent_id])) {
            return '';
        }

        $output = '';
        foreach ($children_map[$parent_id] as $folder) {
            $prefix = str_repeat('— ', (int) $depth);
            $output .= sprintf(
                '<option value="%d" %s>%s%s</option>',
                (int) $folder->ID,
                selected((int) $selected, (int) $folder->ID, false),
                esc_html($prefix),
                esc_html($folder->post_title)
            );
            $output .= $this->render_folder_options_recursive($children_map, (int) $folder->ID, $selected, $depth + 1);
        }

        return $output;
    }

    /**
     * Renderiza opciones de archivo con nombre de carpeta.
     *
     * @param array $files    Archivos.
     * @param int   $selected Archivo seleccionado.
     *
     * @return string
     */
    private function render_file_options($files, $selected = 0)
    {
        $output = '';
        foreach ((array) $files as $file) {
            $file_id = (int) $file->ID;
            $folder_id = (int) get_post_meta($file_id, 'shared_folder_id', true);
            $folder_name = $folder_id > 0 ? get_the_title($folder_id) : __('Sin carpeta', 'shared-docs-manager');

            $label = sprintf(
                '%s [%s]',
                $file->post_title,
                $folder_name
            );

            $output .= sprintf(
                '<option value="%d" %s>%s</option>',
                $file_id,
                selected((int) $selected, $file_id, false),
                esc_html($label)
            );
        }

        return $output;
    }

    /**
     * Calcula contadores de subcarpetas y archivos por carpeta.
     *
     * @param array $folders Carpetas.
     * @param array $files   Archivos.
     *
     * @return array
     */
    private function calculate_folder_stats($folders, $files)
    {
        $children_count = array();
        $files_count = array();

        foreach ((array) $folders as $folder) {
            $folder_id = (int) $folder->ID;
            $children_count[$folder_id] = 0;
            $files_count[$folder_id] = 0;
        }

        foreach ((array) $folders as $folder) {
            $parent_id = (int) $folder->post_parent;
            if ($parent_id > 0) {
                if (! isset($children_count[$parent_id])) {
                    $children_count[$parent_id] = 0;
                }
                $children_count[$parent_id]++;
            }
        }

        foreach ((array) $files as $file) {
            $folder_id = (int) get_post_meta((int) $file->ID, 'shared_folder_id', true);
            if ($folder_id > 0) {
                if (! isset($files_count[$folder_id])) {
                    $files_count[$folder_id] = 0;
                }
                $files_count[$folder_id]++;
            }
        }

        return array($children_count, $files_count);
    }

    /**
     * Renderiza árbol jerárquico de carpetas con archivos.
     *
     * @param array $folders Carpetas.
     * @param array $files   Archivos.
     *
     * @return string
     */
    private function render_folder_tree_with_files($folders, $files)
    {
        $folders = (array) $folders;
        $files = (array) $files;

        $folder_children = array();
        $folder_lookup = array();
        foreach ($folders as $folder) {
            $folder_id = (int) $folder->ID;
            $folder_lookup[$folder_id] = true;
            $parent_id = (int) $folder->post_parent;
            if (! isset($folder_children[$parent_id])) {
                $folder_children[$parent_id] = array();
            }
            $folder_children[$parent_id][] = $folder;
        }

        $files_map = array();
        $orphan_files = array();
        foreach ($files as $file) {
            $folder_id = (int) get_post_meta((int) $file->ID, 'shared_folder_id', true);
            if ($folder_id > 0 && isset($folder_lookup[$folder_id])) {
                if (! isset($files_map[$folder_id])) {
                    $files_map[$folder_id] = array();
                }
                $files_map[$folder_id][] = $file;
            } else {
                $orphan_files[] = $file;
            }
        }

        ob_start();

        $counts_payload = $this->build_permission_count_payload($folders, $files);
        $tree = $this->render_folder_tree_recursive(
            0,
            $folder_children,
            $files_map,
            $counts_payload['folder_users'],
            $counts_payload['file_users'],
            $counts_payload['folder_file_users'],
            0
        );
        if ($tree !== '') {
            echo '<div class="shared-docs-tree-wrap"><ul class="shared-docs-tree">';
            echo $tree; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '</ul></div>';
        }

        if (! empty($orphan_files)) {
            echo '<h4>' . esc_html__('Archivos sin carpeta asignada', 'shared-docs-manager') . '</h4>';
            echo '<ul class="shared-docs-tree shared-docs-tree-orphans">';
            foreach ($orphan_files as $file) {
                $file_id = (int) $file->ID;
                $open_link = wp_nonce_url(
                    add_query_arg(
                        array(
                            'action'      => 'shared_docs_open_file',
                            'file_id'     => $file_id,
                            'return_page' => 'shared-docs',
                        ),
                        admin_url('admin-post.php')
                    ),
                    'shared_docs_open_file_' . $file_id
                );
                $download_link = wp_nonce_url(
                    add_query_arg(
                        array(
                            'action'      => 'shared_docs_download_file',
                            'file_id'     => $file_id,
                            'return_page' => 'shared-docs',
                        ),
                        admin_url('admin-post.php')
                    ),
                    'shared_docs_download_file_' . $file_id
                );
                $is_excel = $this->is_excel_file_post($file);
                $mime_type = (string) get_post_mime_type($file_id);
                $file_path = (string) get_attached_file($file_id);
                $file_name = $file_path !== '' ? basename($file_path) : (string) $file->post_title;
                $file_users_count = isset($counts_payload['file_users'][$file_id]) ? (int) $counts_payload['file_users'][$file_id] : 0;
                ?>
                <li class="shared-docs-tree__node">
                    <div class="shared-docs-tree__item shared-docs-tree__item--file">
                        <input
                            type="checkbox"
                            class="shared-docs-item-checkbox"
                            data-item-type="file"
                            data-item-id="<?php echo (int) $file_id; ?>"
                            data-item-label="<?php echo esc_attr($file->post_title); ?>"
                            data-current-folder="0"
                            data-invalid-targets=""
                            data-open-url="<?php echo esc_attr($open_link); ?>"
                            data-download-url="<?php echo esc_attr($download_link); ?>"
                            data-is-excel="<?php echo $is_excel ? '1' : '0'; ?>"
                            data-mime-type="<?php echo esc_attr($mime_type); ?>"
                            data-filename="<?php echo esc_attr($file_name); ?>"
                        />
                        <span class="shared-docs-tree__icon"><?php echo $this->render_resource_icon_html('file', $file); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                        <span class="shared-docs-tree__label"><?php echo esc_html($file->post_title); ?></span>
                        <span class="shared-docs-tree__badge"><?php echo esc_html(sprintf(__('Permisos archivo: %d', 'shared-docs-manager'), $file_users_count)); ?></span>
                    </div>
                </li>
                <?php
            }
            echo '</ul>';
        }

        return (string) ob_get_clean();
    }

    /**
     * Renderizado recursivo del árbol.
     *
     * @param int   $parent_id       ID padre.
     * @param array $folder_children Mapa de carpetas hijas.
     * @param array $files_map       Mapa de archivos por carpeta.
     *
     * @return string
     */
    private function render_folder_tree_recursive(
        $parent_id,
        $folder_children,
        $files_map,
        $folder_user_counts,
        $file_user_counts,
        $folder_file_user_counts,
        $depth = 0
    )
    {
        $parent_id = (int) $parent_id;
        if (empty($folder_children[$parent_id])) {
            return '';
        }

        $html = '';
        foreach ($folder_children[$parent_id] as $folder) {
            $folder_id = (int) $folder->ID;
            $invalid_targets = implode(',', $this->get_descendant_folder_ids($folder_id));
            $folder_users_count = isset($folder_user_counts[$folder_id]) ? (int) $folder_user_counts[$folder_id] : 0;
            $folder_file_users_count = isset($folder_file_user_counts[$folder_id]) ? (int) $folder_file_user_counts[$folder_id] : 0;

            $html .= '<li class="shared-docs-tree__node">';
            $html .= '<details class="shared-docs-accordion" ' . ($depth === 0 ? 'open' : '') . '>';
            $html .= '<summary class="shared-docs-tree__item shared-docs-tree__item--folder">';
            $html .= '<input type="checkbox" class="shared-docs-item-checkbox" data-item-type="folder" data-item-id="' . (int) $folder_id . '" data-item-label="' . esc_attr($folder->post_title) . '" data-current-folder="' . (int) $folder->post_parent . '" data-invalid-targets="' . esc_attr($invalid_targets) . '" data-open-url="" data-download-url="" data-is-excel="0" data-mime-type="" data-filename="" />';
            $html .= '<span class="shared-docs-tree__icon">' . $this->render_resource_icon_html('folder') . '</span>';
            $html .= '<span class="shared-docs-tree__label">' . esc_html($folder->post_title) . '</span>';
            $html .= '<span class="shared-docs-tree__badge">' . esc_html(sprintf(__('Permisos carpeta: %d · archivos: %d', 'shared-docs-manager'), $folder_users_count, $folder_file_users_count)) . '</span>';
            $html .= '</summary>';

            $child_html = '';
            if (! empty($files_map[$folder_id])) {
                foreach ($files_map[$folder_id] as $file) {
                    $file_id = (int) $file->ID;
                    $open_file_link = wp_nonce_url(
                        add_query_arg(
                            array(
                                'action'      => 'shared_docs_open_file',
                                'file_id'     => $file_id,
                                'return_page' => 'shared-docs',
                            ),
                            admin_url('admin-post.php')
                        ),
                        'shared_docs_open_file_' . $file_id
                    );
                    $download_file_link = wp_nonce_url(
                        add_query_arg(
                            array(
                                'action'      => 'shared_docs_download_file',
                                'file_id'     => $file_id,
                                'return_page' => 'shared-docs',
                            ),
                            admin_url('admin-post.php')
                        ),
                        'shared_docs_download_file_' . $file_id
                    );
                    $is_excel = $this->is_excel_file_post($file);
                    $mime_type = (string) get_post_mime_type($file_id);
                    $file_path = (string) get_attached_file($file_id);
                    $file_name = $file_path !== '' ? basename($file_path) : (string) $file->post_title;
                    $file_users_count = isset($file_user_counts[$file_id]) ? (int) $file_user_counts[$file_id] : 0;

                    $child_html .= '<li class="shared-docs-tree__node">';
                    $child_html .= '<div class="shared-docs-tree__item shared-docs-tree__item--file">';
                    $child_html .= '<input type="checkbox" class="shared-docs-item-checkbox" data-item-type="file" data-item-id="' . (int) $file_id . '" data-item-label="' . esc_attr($file->post_title) . '" data-current-folder="' . (int) $folder_id . '" data-invalid-targets="" data-open-url="' . esc_attr($open_file_link) . '" data-download-url="' . esc_attr($download_file_link) . '" data-is-excel="' . ($is_excel ? '1' : '0') . '" data-mime-type="' . esc_attr($mime_type) . '" data-filename="' . esc_attr($file_name) . '" />';
                    $child_html .= '<span class="shared-docs-tree__icon">' . $this->render_resource_icon_html('file', $file) . '</span>';
                    $child_html .= '<span class="shared-docs-tree__label">' . esc_html($file->post_title) . '</span>';
                    $child_html .= '<span class="shared-docs-tree__badge">' . esc_html(sprintf(__('Permisos archivo: %d', 'shared-docs-manager'), $file_users_count)) . '</span>';
                    $child_html .= '</div>';
                    $child_html .= '</li>';
                }
            }

            $child_html .= $this->render_folder_tree_recursive(
                $folder_id,
                $folder_children,
                $files_map,
                $folder_user_counts,
                $file_user_counts,
                $folder_file_user_counts,
                $depth + 1
            );

            if ($child_html !== '') {
                $html .= '<ul class="shared-docs-tree__children">' . $child_html . '</ul>';
            }

            $html .= '</details>';
            $html .= '</li>';
        }

        return $html;
    }

    /**
     * Obtiene IDs descendientes de una carpeta, incluyendo la propia.
     * El resultado queda en orden hijos->padre para facilitar borrado.
     *
     * @param int $folder_id Carpeta raíz.
     *
     * @return array
     */
    private function get_descendant_folder_ids($folder_id)
    {
        $folder_id = (int) $folder_id;
        if ($folder_id <= 0) {
            return array();
        }

        $children = get_posts(
            array(
                'post_type'      => 'shared_folder',
                'post_status'    => array('publish', 'private'),
                'post_parent'    => $folder_id,
                'posts_per_page' => -1,
                'fields'         => 'ids',
            )
        );

        $ids = array();
        foreach ((array) $children as $child_id) {
            $ids = array_merge($ids, $this->get_descendant_folder_ids((int) $child_id));
        }

        $ids[] = $folder_id;

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Renderiza modal "Mover a".
     *
     * @param array $folders Carpetas disponibles.
     *
     * @return string
     */
    private function render_move_modal($folders)
    {
        ob_start();
        ?>
        <div class="shared-docs-modal-admin" data-shared-move-modal hidden>
            <div class="shared-docs-modal-admin__backdrop" data-action="close-move-modal"></div>
            <div class="shared-docs-modal-admin__dialog" role="dialog" aria-modal="true">
                <header class="shared-docs-modal-admin__header">
                    <h3><?php esc_html_e('Mover elemento', 'shared-docs-manager'); ?></h3>
                    <button type="button" class="button button-link-delete" data-action="close-move-modal">&times;</button>
                </header>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="shared_docs_move_item" />
                    <input type="hidden" name="return_page" value="shared-docs" />
                    <?php wp_nonce_field('shared_docs_move_item'); ?>
                    <input type="hidden" name="item_type" value="" data-move-item-type />
                    <input type="hidden" name="item_id" value="" data-move-item-id />

                    <p class="description" data-move-item-label></p>
                    <label for="shared-move-target-folder"><?php esc_html_e('Mover a carpeta', 'shared-docs-manager'); ?></label>
                    <select id="shared-move-target-folder" name="target_folder_id" data-move-target-folder required>
                        <option value="0"><?php esc_html_e('— Carpeta raíz —', 'shared-docs-manager'); ?></option>
                        <?php echo $this->render_folder_options($folders); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </select>

                    <div class="shared-docs-modal-admin__footer">
                        <button type="button" class="button" data-action="close-move-modal"><?php esc_html_e('Cancelar', 'shared-docs-manager'); ?></button>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Mover', 'shared-docs-manager'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renderiza modal para mover selección en bloque.
     *
     * @param array $folders Carpetas disponibles.
     *
     * @return string
     */
    private function render_bulk_move_modal($folders)
    {
        ob_start();
        ?>
        <div class="shared-docs-modal-admin" data-shared-bulk-move-modal hidden>
            <div class="shared-docs-modal-admin__backdrop" data-action="close-bulk-move-modal"></div>
            <div class="shared-docs-modal-admin__dialog" role="dialog" aria-modal="true">
                <header class="shared-docs-modal-admin__header">
                    <h3><?php esc_html_e('Mover selección', 'shared-docs-manager'); ?></h3>
                    <button type="button" class="button button-link-delete" data-action="close-bulk-move-modal">&times;</button>
                </header>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="shared_docs_bulk_move_items" />
                    <input type="hidden" name="return_page" value="shared-docs" />
                    <?php wp_nonce_field('shared_docs_bulk_move_items'); ?>
                    <input type="hidden" name="folder_ids" value="" data-bulk-move-folder-ids />
                    <input type="hidden" name="file_ids" value="" data-bulk-move-file-ids />

                    <p class="description" data-bulk-move-selection-label></p>
                    <label for="shared-bulk-move-target-folder"><?php esc_html_e('Mover selección a carpeta', 'shared-docs-manager'); ?></label>
                    <select id="shared-bulk-move-target-folder" name="target_folder_id" required>
                        <option value=""><?php esc_html_e('Selecciona carpeta...', 'shared-docs-manager'); ?></option>
                        <?php echo $this->render_folder_options($folders); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </select>

                    <div class="shared-docs-modal-admin__footer">
                        <button type="button" class="button" data-action="close-bulk-move-modal"><?php esc_html_e('Cancelar', 'shared-docs-manager'); ?></button>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Mover seleccionados', 'shared-docs-manager'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renderiza modal para renombrar carpeta.
     *
     * @return string
     */
    private function render_rename_folder_modal()
    {
        ob_start();
        ?>
        <div class="shared-docs-modal-admin" data-shared-rename-modal hidden>
            <div class="shared-docs-modal-admin__backdrop" data-action="close-rename-modal"></div>
            <div class="shared-docs-modal-admin__dialog" role="dialog" aria-modal="true">
                <header class="shared-docs-modal-admin__header">
                    <h3><?php esc_html_e('Editar nombre de carpeta', 'shared-docs-manager'); ?></h3>
                    <button type="button" class="button button-link-delete" data-action="close-rename-modal">&times;</button>
                </header>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="shared_docs_rename_folder" />
                    <input type="hidden" name="return_page" value="shared-docs" />
                    <?php wp_nonce_field('shared_docs_rename_folder'); ?>
                    <input type="hidden" name="folder_id" value="" data-rename-folder-id />

                    <label for="shared-rename-folder-name"><?php esc_html_e('Nuevo nombre', 'shared-docs-manager'); ?></label>
                    <input id="shared-rename-folder-name" type="text" name="folder_name" value="" required data-rename-folder-name />

                    <div class="shared-docs-modal-admin__footer">
                        <button type="button" class="button" data-action="close-rename-modal"><?php esc_html_e('Cancelar', 'shared-docs-manager'); ?></button>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Guardar', 'shared-docs-manager'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_access_modal($assignable_users, $return_page = 'shared-docs')
    {
        ob_start();
        ?>
        <div class="shared-docs-modal-admin" data-shared-access-modal hidden>
            <div class="shared-docs-modal-admin__backdrop" data-action="close-access-modal"></div>
            <div class="shared-docs-modal-admin__dialog shared-docs-modal-admin__dialog--wide" role="dialog" aria-modal="true">
                <header class="shared-docs-modal-admin__header">
                    <h3><?php esc_html_e('Administrar acceso', 'shared-docs-manager'); ?></h3>
                    <button type="button" class="button button-link-delete" data-action="close-access-modal">&times;</button>
                </header>
                <p class="description" data-access-item-label></p>
                <?php echo $this->render_access_current_table(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php echo $this->render_access_manage_form($assignable_users, $return_page, 'shared-modal-access-users', 'close-access-modal'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <div class="shared-docs-modal-admin__footer">
                    <button type="button" class="button" data-action="close-access-modal"><?php esc_html_e('Cerrar', 'shared-docs-manager'); ?></button>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_access_view_modal()
    {
        ob_start();
        ?>
        <div class="shared-docs-modal-admin" data-shared-access-view-modal hidden>
            <div class="shared-docs-modal-admin__backdrop" data-action="close-access-view-modal"></div>
            <div class="shared-docs-modal-admin__dialog shared-docs-modal-admin__dialog--wide" role="dialog" aria-modal="true">
                <header class="shared-docs-modal-admin__header">
                    <h3><?php esc_html_e('Ver permisos', 'shared-docs-manager'); ?></h3>
                    <button type="button" class="button button-link-delete" data-action="close-access-view-modal">&times;</button>
                </header>
                <p class="description" data-access-item-label></p>
                <?php echo $this->render_access_current_table(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <div class="shared-docs-modal-admin__footer">
                    <button type="button" class="button" data-action="close-access-view-modal"><?php esc_html_e('Cerrar', 'shared-docs-manager'); ?></button>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_access_manage_modal($assignable_users, $return_page = 'shared-docs-permissions')
    {
        ob_start();
        ?>
        <div class="shared-docs-modal-admin" data-shared-access-manage-modal hidden>
            <div class="shared-docs-modal-admin__backdrop" data-action="close-access-manage-modal"></div>
            <div class="shared-docs-modal-admin__dialog shared-docs-modal-admin__dialog--wide" role="dialog" aria-modal="true">
                <header class="shared-docs-modal-admin__header">
                    <h3><?php esc_html_e('Gestión de permisos', 'shared-docs-manager'); ?></h3>
                    <button type="button" class="button button-link-delete" data-action="close-access-manage-modal">&times;</button>
                </header>
                <p class="description" data-access-item-label></p>
                <?php echo $this->render_access_manage_form($assignable_users, $return_page, 'shared-modal-manage-access-users', 'close-access-manage-modal'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <div class="shared-docs-modal-admin__footer">
                    <button type="button" class="button" data-action="close-access-manage-modal"><?php esc_html_e('Cerrar', 'shared-docs-manager'); ?></button>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_access_current_table()
    {
        ob_start();
        ?>
        <div class="shared-docs-access-current">
            <h4><?php esc_html_e('Usuarios y permisos actuales', 'shared-docs-manager'); ?></h4>
            <table class="widefat striped">
                <thead>
                <tr>
                    <th><?php esc_html_e('Usuario', 'shared-docs-manager'); ?></th>
                    <th><?php esc_html_e('Lectura', 'shared-docs-manager'); ?></th>
                    <th><?php esc_html_e('Descarga', 'shared-docs-manager'); ?></th>
                    <th><?php esc_html_e('Edición Excel', 'shared-docs-manager'); ?></th>
                    <th><?php esc_html_e('Expira', 'shared-docs-manager'); ?></th>
                    <th><?php esc_html_e('Acciones', 'shared-docs-manager'); ?></th>
                </tr>
                </thead>
                <tbody data-access-current-body>
                <tr data-access-current-empty>
                    <td colspan="6"><?php esc_html_e('No hay permisos asignados para este elemento.', 'shared-docs-manager'); ?></td>
                </tr>
                </tbody>
            </table>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_access_manage_form($assignable_users, $return_page, $id_prefix, $close_action)
    {
        ob_start();
        ?>
        <div class="shared-docs-access-add">
            <h4><?php esc_html_e('Gestión de permisos', 'shared-docs-manager'); ?></h4>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="shared_docs_add_item_access_bulk" />
                <input type="hidden" name="return_page" value="<?php echo esc_attr($return_page); ?>" />
                <?php wp_nonce_field('shared_docs_add_item_access_bulk'); ?>
                <input type="hidden" name="item_type" value="" data-access-item-type />
                <input type="hidden" name="item_id" value="" data-access-item-id />

                <?php
                echo $this->render_user_checkbox_selector(
                    $assignable_users,
                    'user_ids[]',
                    array(),
                    $id_prefix
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>

                <fieldset class="shared-docs-checkboxes">
                    <legend><?php esc_html_e('Permisos', 'shared-docs-manager'); ?></legend>
                    <label><input type="checkbox" name="can_read" value="1" checked /> <?php esc_html_e('Lectura', 'shared-docs-manager'); ?></label>
                    <label><input type="checkbox" name="can_download" value="1" checked /> <?php esc_html_e('Descarga', 'shared-docs-manager'); ?></label>
                    <label><input type="checkbox" name="can_edit_excel" value="1" /> <?php esc_html_e('Edición Excel', 'shared-docs-manager'); ?></label>
                </fieldset>

                <label for="shared-modal-access-expires-<?php echo esc_attr($id_prefix); ?>"><?php esc_html_e('Fecha límite (opcional)', 'shared-docs-manager'); ?></label>
                <input id="shared-modal-access-expires-<?php echo esc_attr($id_prefix); ?>" type="datetime-local" name="expires_at" value="" />

                <div class="shared-docs-modal-admin__footer">
                    <button type="button" class="button" data-action="<?php echo esc_attr($close_action); ?>"><?php esc_html_e('Cancelar', 'shared-docs-manager'); ?></button>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Guardar accesos', 'shared-docs-manager'); ?></button>
                </div>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renderiza modal de histórico de edición Excel.
     *
     * @return string
     */
    private function render_excel_history_modal()
    {
        ob_start();
        ?>
        <div class="shared-docs-modal-admin" data-shared-history-modal hidden>
            <div class="shared-docs-modal-admin__backdrop" data-action="close-history-modal"></div>
            <div class="shared-docs-modal-admin__dialog" role="dialog" aria-modal="true">
                <header class="shared-docs-modal-admin__header">
                    <h3><?php esc_html_e('Histórico de modificaciones Excel', 'shared-docs-manager'); ?></h3>
                    <button type="button" class="button button-link-delete" data-action="close-history-modal">&times;</button>
                </header>

                <p class="description" data-history-item-label></p>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php esc_html_e('Usuario', 'shared-docs-manager'); ?></th>
                        <th><?php esc_html_e('Fecha', 'shared-docs-manager'); ?></th>
                    </tr>
                    </thead>
                    <tbody data-history-body>
                    <tr data-history-empty>
                        <td colspan="2"><?php esc_html_e('No hay modificaciones registradas.', 'shared-docs-manager'); ?></td>
                    </tr>
                    </tbody>
                </table>

                <div class="shared-docs-modal-admin__footer">
                    <button type="button" class="button" data-action="close-history-modal"><?php esc_html_e('Cerrar', 'shared-docs-manager'); ?></button>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Modal fullscreen para abrir/previsualizar/editar archivo.
     *
     * @return string
     */
    private function render_file_action_modal()
    {
        ob_start();
        ?>
        <div class="shared-docs-modal-admin shared-docs-modal-admin--fullscreen" data-shared-file-modal hidden>
            <div class="shared-docs-modal-admin__backdrop" data-action="close-file-modal"></div>
            <div class="shared-docs-modal-admin__dialog shared-docs-modal-admin__dialog--fullscreen" role="dialog" aria-modal="true">
                <header class="shared-docs-modal-admin__header">
                    <h3 data-file-modal-title><?php esc_html_e('Abrir archivo', 'shared-docs-manager'); ?></h3>
                    <button type="button" class="button button-link-delete" data-action="close-file-modal">&times;</button>
                </header>

                <div class="shared-docs-modal-admin__body">
                    <div data-file-preview-wrap></div>
                    <div data-file-excel-wrap hidden>
                        <p class="description"><?php esc_html_e('Haz clic sobre una celda para editarla.', 'shared-docs-manager'); ?></p>
                        <div class="shared-docs-excel-table-wrap">
                            <table class="shared-docs-excel-table" data-file-excel-table></table>
                        </div>
                    </div>
                </div>

                <div class="shared-docs-modal-admin__footer">
                    <button type="button" class="button" data-action="close-file-modal"><?php esc_html_e('Cerrar', 'shared-docs-manager'); ?></button>
                    <button type="button" class="button" data-action="file-modal-download"><?php esc_html_e('Descargar', 'shared-docs-manager'); ?></button>
                    <button type="button" class="button button-primary" data-action="file-modal-save-excel" hidden><?php esc_html_e('Guardar cambios', 'shared-docs-manager'); ?></button>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Devuelve carpetas ordenadas alfabéticamente.
     *
     * @return array
     */
    private function get_all_folders()
    {
        return get_posts(
            array(
                'post_type'      => 'shared_folder',
                'post_status'    => array('publish', 'private'),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );
    }

    /**
     * Devuelve archivos cargados en gestor.
     *
     * @return array
     */
    private function get_all_files()
    {
        return get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => array('inherit', 'private'),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'meta_query'     => array(
                    array(
                        'key'     => 'shared_folder_id',
                        'compare' => 'EXISTS',
                    ),
                ),
            )
        );
    }

    /**
     * Usuarios permitidos para asignación de accesos.
     *
     * @return array
     */
    private function get_assignable_users()
    {
        return get_users(
            array(
                'role__in' => array('cie_user', 'cie_user_new'),
                'orderby'  => 'display_name',
                'order'    => 'ASC',
                'number'   => 9999,
            )
        );
    }

    /**
     * Garantiza que usuarios seleccionados en edición estén en el listado.
     *
     * @param array $users    Listado base.
     * @param array $user_ids IDs a incluir.
     *
     * @return array
     */
    private function ensure_selected_users_present($users, $user_ids)
    {
        $users = (array) $users;
        $lookup = array();
        foreach ($users as $user) {
            $lookup[(int) $user->ID] = true;
        }

        foreach ((array) $user_ids as $user_id) {
            $user_id = (int) $user_id;
            if ($user_id <= 0 || isset($lookup[$user_id])) {
                continue;
            }

            $user = get_userdata($user_id);
            if ($user) {
                $users[] = $user;
                $lookup[$user_id] = true;
            }
        }

        usort(
            $users,
            static function ($a, $b) {
                return strcasecmp((string) $a->display_name, (string) $b->display_name);
            }
        );

        return $users;
    }

    /**
     * Renderiza selector de usuarios con checkboxes, buscador y "seleccionar todos".
     *
     * @param array  $users        Usuarios disponibles.
     * @param string $field_name   Nombre del input checkbox.
     * @param array  $selected_ids IDs preseleccionados.
     * @param string $id_prefix    Prefijo único para IDs HTML.
     *
     * @return string
     */
    private function render_user_checkbox_selector($users, $field_name, $selected_ids, $id_prefix)
    {
        $selected_lookup = array();
        foreach ((array) $selected_ids as $selected_id) {
            $selected_lookup[(int) $selected_id] = true;
        }

        $search_id = $id_prefix . '-search';
        $select_all_id = $id_prefix . '-select-all';

        ob_start();
        ?>
        <div class="shared-docs-user-selector" data-user-selector>
            <div class="shared-docs-switch-row">
                <span><?php esc_html_e('Seleccionar a todos', 'shared-docs-manager'); ?></span>
                <label class="shared-docs-switch" for="<?php echo esc_attr($select_all_id); ?>">
                    <input id="<?php echo esc_attr($select_all_id); ?>" type="checkbox" data-user-select-all />
                    <span class="shared-docs-slider"></span>
                </label>
            </div>

            <label for="<?php echo esc_attr($search_id); ?>"><?php esc_html_e('Buscar usuario', 'shared-docs-manager'); ?></label>
            <input
                id="<?php echo esc_attr($search_id); ?>"
                type="search"
                class="regular-text"
                placeholder="<?php esc_attr_e('Escribe nombre o email...', 'shared-docs-manager'); ?>"
                data-user-search
            />

            <div class="shared-docs-user-checklist" data-user-checklist>
                <?php foreach ((array) $users as $user) : ?>
                    <?php
                    $user_id = (int) $user->ID;
                    $label = $user->display_name . ' (' . $user->user_email . ')';
                    ?>
                    <label class="shared-docs-user-checklist__item" data-user-item>
                        <input
                            type="checkbox"
                            name="<?php echo esc_attr($field_name); ?>"
                            value="<?php echo (int) $user_id; ?>"
                            class="shared-docs-user-checkbox"
                            <?php checked(isset($selected_lookup[$user_id])); ?>
                        />
                        <span><?php echo esc_html($label); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Obtiene lista de usuarios seleccionados desde request.
     *
     * @return array
     */
    private function extract_user_ids_from_request()
    {
        if (isset($_POST['user_ids'])) {
            $user_ids = (array) wp_unslash($_POST['user_ids']);
            return array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        }

        $single_user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if ($single_user_id > 0) {
            return array($single_user_id);
        }

        return array();
    }

    /**
     * Convierte un CSV a array de enteros únicos.
     *
     * @param mixed $raw Valor raw.
     *
     * @return array
     */
    private function csv_to_int_array($raw)
    {
        if (is_array($raw)) {
            return array_values(array_unique(array_filter(array_map('intval', $raw))));
        }

        $raw = trim((string) $raw);
        if ($raw === '') {
            return array();
        }

        $parts = array_map('trim', explode(',', $raw));
        return array_values(array_unique(array_filter(array_map('intval', $parts))));
    }

    /**
     * Renderiza icono SVG de recurso.
     *
     * @param string      $resource_type folder|file.
     * @param object|null $file          Post de archivo.
     *
     * @return string
     */
    private function render_resource_icon_html($resource_type, $file = null)
    {
        $class = $resource_type === 'folder' ? 'shared-docs-type-icon shared-docs-type-icon--folder' : 'shared-docs-type-icon shared-docs-type-icon--file';
        $title = $resource_type === 'folder' ? __('Carpeta', 'shared-docs-manager') : __('Archivo', 'shared-docs-manager');
        $path = '';
        $mime = '';
        if ($resource_type === 'file' && is_object($file) && isset($file->ID)) {
            $path = (string) get_attached_file((int) $file->ID);
            $mime = (string) get_post_mime_type((int) $file->ID);
        }
        $svg = Icon_Helper::svg_for_resource($resource_type, basename($path), $mime);

        return sprintf(
            '<span class="%s" title="%s">%s</span>',
            esc_attr($class),
            esc_attr($title),
            $svg
        );
    }

    /**
     * Determina si un archivo es Excel editable.
     *
     * @param object|null $file Post attachment.
     *
     * @return bool
     */
    private function is_excel_file_post($file)
    {
        if (! is_object($file) || ! isset($file->ID)) {
            return false;
        }

        $file_id = (int) $file->ID;
        $mime = (string) (isset($file->post_mime_type) ? $file->post_mime_type : get_post_mime_type($file_id));
        $path = (string) get_attached_file($file_id);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, array('xls', 'xlsx', 'xlsm', 'xlsb', 'ods', 'csv'), true)) {
            return true;
        }

        return (strpos($mime, 'spreadsheet') !== false || strpos($mime, 'excel') !== false || $mime === 'text/csv');
    }

    /**
     * Construye payload para contadores de permisos por recurso.
     *
     * @param array $folders Carpetas.
     * @param array $files   Archivos.
     *
     * @return array
     */
    private function build_permission_count_payload($folders, $files)
    {
        $folders = (array) $folders;
        $files = (array) $files;

        $file_folder_map = array();
        foreach ($files as $file) {
            $file_id = (int) $file->ID;
            $file_folder_map[$file_id] = (int) get_post_meta($file_id, 'shared_folder_id', true);
        }

        $folder_sets = array();
        $file_sets = array();
        $folder_file_sets = array();

        $folder_permissions = $this->permission_repository->get_all_permissions();
        foreach ((array) $folder_permissions as $permission) {
            if ($this->permission_is_expired($permission)) {
                continue;
            }

            $folder_id = (int) $permission->folder_id;
            $user_id = (int) $permission->user_id;
            if ($folder_id <= 0 || $user_id <= 0) {
                continue;
            }

            if (! isset($folder_sets[$folder_id])) {
                $folder_sets[$folder_id] = array();
            }
            $folder_sets[$folder_id][$user_id] = true;
        }

        $file_permissions = $this->file_permission_repository->get_all_permissions();
        foreach ((array) $file_permissions as $permission) {
            if ($this->permission_is_expired($permission)) {
                continue;
            }

            $file_id = (int) $permission->file_id;
            $user_id = (int) $permission->user_id;
            if ($file_id <= 0 || $user_id <= 0) {
                continue;
            }

            if (! isset($file_sets[$file_id])) {
                $file_sets[$file_id] = array();
            }
            $file_sets[$file_id][$user_id] = true;

            $folder_id = isset($file_folder_map[$file_id]) ? (int) $file_folder_map[$file_id] : 0;
            if ($folder_id > 0) {
                if (! isset($folder_file_sets[$folder_id])) {
                    $folder_file_sets[$folder_id] = array();
                }
                $folder_file_sets[$folder_id][$user_id] = true;
            }
        }

        $folder_counts = array();
        foreach ($folder_sets as $folder_id => $users_map) {
            $folder_counts[(int) $folder_id] = count($users_map);
        }

        $file_counts = array();
        foreach ($file_sets as $file_id => $users_map) {
            $file_counts[(int) $file_id] = count($users_map);
        }

        $folder_file_counts = array();
        foreach ($folder_file_sets as $folder_id => $users_map) {
            $folder_file_counts[(int) $folder_id] = count($users_map);
        }

        foreach ($folders as $folder) {
            $folder_id = (int) $folder->ID;
            if (! isset($folder_counts[$folder_id])) {
                $folder_counts[$folder_id] = 0;
            }
            if (! isset($folder_file_counts[$folder_id])) {
                $folder_file_counts[$folder_id] = 0;
            }
        }

        return array(
            'folder_users'      => $folder_counts,
            'file_users'        => $file_counts,
            'folder_file_users' => $folder_file_counts,
        );
    }

    /**
     * Construye payload de permisos para modal de acceso.
     *
     * @param array $folders Carpetas.
     * @param array $files   Archivos.
     *
     * @return array
     */
    private function build_permissions_payload($folders, $files)
    {
        $current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'shared-docs'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (! in_array($current_page, array('shared-docs', 'shared-docs-permissions'), true)) {
            $current_page = 'shared-docs';
        }

        $by_folder = array();
        $by_file = array();
        $date_format = get_option('date_format') . ' ' . get_option('time_format');
        $user_permissions_cache = array();

        $folder_permissions = $this->permission_repository->get_all_permissions();
        foreach ((array) $folder_permissions as $permission) {
            if ($this->permission_is_expired($permission)) {
                continue;
            }

            $permission_id = (int) $permission->id;
            $folder_id = (int) $permission->folder_id;
            $user_id = (int) $permission->user_id;
            if ($permission_id <= 0 || $folder_id <= 0 || $user_id <= 0) {
                continue;
            }

            $edit_url = add_query_arg(
                array(
                    'page'          => 'shared-docs-permissions',
                    'action'        => 'edit_permission',
                    'permission_id' => $permission_id,
                ),
                admin_url('admin.php')
            );
            $revoke_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action'        => 'shared_docs_delete_permission',
                        'permission_id' => $permission_id,
                        'return_page'   => $current_page,
                    ),
                    admin_url('admin-post.php')
                ),
                'shared_docs_delete_permission_' . $permission_id
            );

            if (! isset($by_folder[$folder_id])) {
                $by_folder[$folder_id] = array();
            }

            $expires_label = empty($permission->expires_at)
                ? __('Sin límite', 'shared-docs-manager')
                : wp_date($date_format, strtotime((string) $permission->expires_at));

            $user_label = trim((string) $permission->display_name);
            if ($user_label === '') {
                $user = get_userdata($user_id);
                $user_label = $user ? (string) $user->display_name : __('Usuario eliminado', 'shared-docs-manager');
            }
            $email = ! empty($permission->user_email) ? (string) $permission->user_email : '';
            $user_full = $email !== '' ? $user_label . ' (' . $email . ')' : $user_label;

            if (! isset($user_permissions_cache[$user_id])) {
                $user_permissions_cache[$user_id] = function_exists('shared_get_user_permissions_html')
                    ? (string) shared_get_user_permissions_html($user_id)
                    : '';
            }

            $by_folder[$folder_id][] = array(
                'id'             => $permission_id,
                'user_id'        => $user_id,
                'user'           => $user_full,
                'can_read'       => ! empty($permission->can_read),
                'can_download'   => ! empty($permission->can_download),
                'can_edit_excel' => ! empty($permission->can_edit_excel),
                'expires_at'     => $expires_label,
                'edit_url'       => $edit_url,
                'revoke_url'     => $revoke_url,
            );
        }

        $file_permissions = $this->file_permission_repository->get_all_permissions();
        foreach ((array) $file_permissions as $permission) {
            if ($this->permission_is_expired($permission)) {
                continue;
            }

            $permission_id = (int) $permission->id;
            $file_id = (int) $permission->file_id;
            $user_id = (int) $permission->user_id;
            if ($permission_id <= 0 || $file_id <= 0 || $user_id <= 0) {
                continue;
            }

            $edit_url = add_query_arg(
                array(
                    'page'          => 'shared-docs-permissions',
                    'action'        => 'edit_file_permission',
                    'permission_id' => $permission_id,
                ),
                admin_url('admin.php')
            );
            $revoke_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action'        => 'shared_docs_delete_file_permission',
                        'permission_id' => $permission_id,
                        'return_page'   => $current_page,
                    ),
                    admin_url('admin-post.php')
                ),
                'shared_docs_delete_file_permission_' . $permission_id
            );

            if (! isset($by_file[$file_id])) {
                $by_file[$file_id] = array();
            }

            $expires_label = empty($permission->expires_at)
                ? __('Sin límite', 'shared-docs-manager')
                : wp_date($date_format, strtotime((string) $permission->expires_at));

            $user_label = trim((string) $permission->display_name);
            if ($user_label === '') {
                $user = get_userdata($user_id);
                $user_label = $user ? (string) $user->display_name : __('Usuario eliminado', 'shared-docs-manager');
            }
            $email = ! empty($permission->user_email) ? (string) $permission->user_email : '';
            $user_full = $email !== '' ? $user_label . ' (' . $email . ')' : $user_label;

            if (! isset($user_permissions_cache[$user_id])) {
                $user_permissions_cache[$user_id] = function_exists('shared_get_user_permissions_html')
                    ? (string) shared_get_user_permissions_html($user_id)
                    : '';
            }

            $by_file[$file_id][] = array(
                'id'             => $permission_id,
                'user_id'        => $user_id,
                'user'           => $user_full,
                'can_read'       => ! empty($permission->can_read),
                'can_download'   => ! empty($permission->can_download),
                'can_edit_excel' => ! empty($permission->can_edit_excel),
                'expires_at'     => $expires_label,
                'edit_url'       => $edit_url,
                'revoke_url'     => $revoke_url,
            );
        }

        return array(
            'by_folder'             => $by_folder,
            'by_file'               => $by_file,
            'user_permissions_html' => $user_permissions_cache,
        );
    }

    /**
     * Construye payload histórico de ediciones Excel por archivo.
     *
     * @param array $files Lista de archivos.
     *
     * @return array
     */
    private function build_excel_history_payload($files)
    {
        global $wpdb;

        $excel_file_ids = array();
        foreach ((array) $files as $file) {
            if ($this->is_excel_file_post($file)) {
                $excel_file_ids[] = (int) $file->ID;
            }
        }

        $excel_file_ids = array_values(array_unique(array_filter(array_map('intval', $excel_file_ids))));
        if (empty($excel_file_ids)) {
            return array();
        }

        $table = $wpdb->prefix . 'shared_activity_log';
        $placeholders = implode(',', array_fill(0, count($excel_file_ids), '%d'));
        $params = array_merge(array('edit'), $excel_file_ids);
        $query = $wpdb->prepare(
            "SELECT l.file_id, l.user_id, l.created_at, u.display_name, u.user_email
             FROM {$table} l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
             WHERE l.action = %s
               AND l.file_id IN ({$placeholders})
             ORDER BY l.created_at DESC, l.id DESC",
            $params
        );

        $rows = (array) $wpdb->get_results($query);
        $payload = array();
        $date_format = get_option('date_format') . ' ' . get_option('time_format');

        foreach ($rows as $row) {
            $file_id = (int) $row->file_id;
            if (! isset($payload[$file_id])) {
                $payload[$file_id] = array();
            }

            $display_name = trim((string) $row->display_name);
            if ($display_name === '') {
                $user = get_userdata((int) $row->user_id);
                $display_name = $user ? (string) $user->display_name : __('Usuario eliminado', 'shared-docs-manager');
            }

            $email = ! empty($row->user_email) ? (string) $row->user_email : '';
            $user_label = $email !== '' ? $display_name . ' (' . $email . ')' : $display_name;

            $timestamp = strtotime((string) $row->created_at);
            $created_at = $timestamp ? wp_date($date_format, $timestamp) : (string) $row->created_at;

            $payload[$file_id][] = array(
                'user'       => $user_label,
                'created_at' => $created_at,
            );
        }

        return $payload;
    }

    /**
     * Determina si un registro de permiso está expirado.
     *
     * @param object $permission Registro permiso.
     *
     * @return bool
     */
    private function permission_is_expired($permission)
    {
        if (isset($permission->is_expired)) {
            return (int) $permission->is_expired === 1;
        }

        if (empty($permission->expires_at)) {
            return false;
        }

        $expires_timestamp = strtotime((string) $permission->expires_at);
        if (! $expires_timestamp) {
            return false;
        }

        return $expires_timestamp < current_time('timestamp');
    }

    /**
     * Normaliza datetime-local a formato MySQL o null.
     *
     * @param string $raw Valor input datetime-local.
     *
     * @return string|null
     */
    private function normalize_datetime_local($raw)
    {
        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        if (! $timestamp) {
            return null;
        }

        return wp_date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Formatea valor MySQL para datetime-local.
     *
     * @param string|null $raw Datetime.
     *
     * @return string
     */
    private function format_datetime_local($raw)
    {
        if (empty($raw)) {
            return '';
        }

        $timestamp = strtotime((string) $raw);
        if (! $timestamp) {
            return '';
        }

        return wp_date('Y-m-d\TH:i', $timestamp);
    }

    /**
     * Formato human-readable de bytes.
     *
     * @param int $bytes Bytes.
     *
     * @return string
     */
    private function format_bytes($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        $value = $bytes / pow(1024, $power);

        return number_format_i18n($value, $power === 0 ? 0 : 1) . ' ' . $units[$power];
    }
}
