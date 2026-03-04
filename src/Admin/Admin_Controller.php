<?php

namespace SharedDocsManager\Admin;

use SharedDocsManager\Helpers\File_Helper;
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
        add_action('admin_post_shared_docs_upload_file', array($this, 'handle_upload_file'));
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
            array($this, 'render_admin_page'),
            'dashicons-portfolio',
            26
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
        $allowed_hooks = array('toplevel_page_shared-docs', 'profile.php', 'user-edit.php');
        if (! in_array($hook, $allowed_hooks, true)) {
            return;
        }

        wp_enqueue_style(
            'shared-docs-admin',
            SHARED_DOCS_URL . 'assets/css/admin.css',
            array(),
            SHARED_DOCS_VERSION
        );
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
            $this->redirect_with_notice('folder_invalid');
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
            $this->redirect_with_notice('folder_error');
        }

        $this->redirect_with_notice('folder_created');
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
            $this->redirect_with_notice('upload_invalid');
        }

        $uploaded = $this->file_helper->upload_attachment_to_folder(
            $_FILES['shared_file_upload'],
            $folder_id,
            get_current_user_id()
        );

        if (is_wp_error($uploaded)) {
            $this->redirect_with_notice('upload_error');
        }

        $this->redirect_with_notice('upload_ok');
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
            $this->redirect_with_notice('folder_delete_invalid');
        }

        check_admin_referer('shared_docs_delete_folder_' . $folder_id);

        $folder = get_post($folder_id);
        if (! $folder || $folder->post_type !== 'shared_folder') {
            $this->redirect_with_notice('folder_delete_invalid');
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

        $this->redirect_with_notice($has_error ? 'folder_delete_error' : 'folder_deleted');
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
            $this->redirect_with_notice('file_delete_invalid');
        }

        check_admin_referer('shared_docs_delete_file_' . $file_id);

        $file_post = get_post($file_id);
        if (! $file_post || $file_post->post_type !== 'attachment') {
            $this->redirect_with_notice('file_delete_invalid');
        }

        $this->file_permission_repository->delete_permissions_by_file_ids(array($file_id));
        $deleted = wp_delete_attachment($file_id, true);

        $this->redirect_with_notice($deleted === false ? 'file_delete_error' : 'file_deleted');
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
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;

        $can_read = ! empty($_POST['can_read']) ? 1 : 0;
        $can_download = ! empty($_POST['can_download']) ? 1 : 0;
        $can_edit_excel = ! empty($_POST['can_edit_excel']) ? 1 : 0;

        if ($can_download || $can_edit_excel) {
            $can_read = 1;
        }

        $expires_at = $this->normalize_datetime_local(
            isset($_POST['expires_at']) ? sanitize_text_field(wp_unslash($_POST['expires_at'])) : ''
        );

        if ($user_id <= 0 || $folder_id <= 0) {
            $this->redirect_with_notice('permission_invalid');
        }

        if ($permission_id > 0) {
            $existing = $this->permission_repository->get_permission($permission_id);
            if ($existing && ((int) $existing->user_id !== $user_id || (int) $existing->folder_id !== $folder_id)) {
                $this->permission_repository->delete_permission($permission_id);
            }
        }

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
            $this->redirect_with_notice('permission_error');
        }

        $this->redirect_with_notice('permission_saved', array('user_id' => $user_id));
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
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $file_id = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;

        $can_read = ! empty($_POST['can_read']) ? 1 : 0;
        $can_download = ! empty($_POST['can_download']) ? 1 : 0;
        $can_edit_excel = ! empty($_POST['can_edit_excel']) ? 1 : 0;

        if ($can_download || $can_edit_excel) {
            $can_read = 1;
        }

        $expires_at = $this->normalize_datetime_local(
            isset($_POST['expires_at']) ? sanitize_text_field(wp_unslash($_POST['expires_at'])) : ''
        );

        if ($user_id <= 0 || $file_id <= 0) {
            $this->redirect_with_notice('file_permission_invalid');
        }

        if ($permission_id > 0) {
            $existing = $this->file_permission_repository->get_permission($permission_id);
            if ($existing && ((int) $existing->user_id !== $user_id || (int) $existing->file_id !== $file_id)) {
                $this->file_permission_repository->delete_permission($permission_id);
            }
        }

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
            $this->redirect_with_notice('file_permission_error');
        }

        $this->redirect_with_notice('file_permission_saved', array('user_id' => $user_id));
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
    private function redirect_with_notice($notice_code, $extra = array())
    {
        $args = array_merge(array('page' => 'shared-docs', 'sd_notice' => $notice_code), $extra);
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
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
            'folder_deleted'    => __('Carpeta eliminada correctamente.', 'shared-docs-manager'),
            'folder_delete_error' => __('La carpeta se eliminó parcialmente o hubo errores al borrar su contenido.', 'shared-docs-manager'),
            'folder_delete_invalid' => __('No se pudo identificar la carpeta a eliminar.', 'shared-docs-manager'),
            'upload_ok'         => __('Archivo subido correctamente.', 'shared-docs-manager'),
            'upload_invalid'    => __('Datos de subida inválidos.', 'shared-docs-manager'),
            'upload_error'      => __('No se pudo subir el archivo.', 'shared-docs-manager'),
            'file_deleted'      => __('Archivo eliminado correctamente.', 'shared-docs-manager'),
            'file_delete_error' => __('No se pudo eliminar el archivo.', 'shared-docs-manager'),
            'file_delete_invalid' => __('No se pudo identificar el archivo a eliminar.', 'shared-docs-manager'),
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

        $tree = $this->render_folder_tree_recursive(0, $folder_children, $files_map);
        $html = '';
        if ($tree !== '') {
            $html .= '<ul class="shared-docs-tree">' . $tree . '</ul>';
        }

        if (! empty($orphan_files)) {
            $html .= '<h4>' . esc_html__('Archivos sin carpeta asignada', 'shared-docs-manager') . '</h4>';
            $html .= '<ul class="shared-docs-tree shared-docs-tree-orphans">';
            foreach ($orphan_files as $file) {
                $file_id = (int) $file->ID;
                $delete_link = wp_nonce_url(
                    add_query_arg(
                        array(
                            'action'  => 'shared_docs_delete_file',
                            'file_id' => $file_id,
                        ),
                        admin_url('admin-post.php')
                    ),
                    'shared_docs_delete_file_' . $file_id
                );

                $html .= '<li class="shared-docs-tree__node">';
                $html .= '<div class="shared-docs-tree__item shared-docs-tree__item--file">';
                $html .= '<span class="shared-docs-tree__icon">📄</span>';
                $html .= '<span class="shared-docs-tree__label">' . esc_html($file->post_title) . '</span>';
                $html .= '<a class="shared-docs-tree__delete" href="' . esc_url($delete_link) . '" onclick="return confirm(\'' . esc_js(__('¿Eliminar este archivo?', 'shared-docs-manager')) . '\');">' . esc_html__('Borrar', 'shared-docs-manager') . '</a>';
                $html .= '</div></li>';
            }
            $html .= '</ul>';
        }

        return $html;
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
    private function render_folder_tree_recursive($parent_id, $folder_children, $files_map)
    {
        $parent_id = (int) $parent_id;
        if (empty($folder_children[$parent_id])) {
            return '';
        }

        $html = '';
        foreach ($folder_children[$parent_id] as $folder) {
            $folder_id = (int) $folder->ID;
            $delete_folder_link = wp_nonce_url(
                add_query_arg(
                    array(
                        'action'    => 'shared_docs_delete_folder',
                        'folder_id' => $folder_id,
                    ),
                    admin_url('admin-post.php')
                ),
                'shared_docs_delete_folder_' . $folder_id
            );

            $html .= '<li class="shared-docs-tree__node">';
            $html .= '<div class="shared-docs-tree__item shared-docs-tree__item--folder">';
            $html .= '<span class="shared-docs-tree__icon">📁</span>';
            $html .= '<span class="shared-docs-tree__label">' . esc_html($folder->post_title) . '</span>';
            $html .= '<a class="shared-docs-tree__delete" href="' . esc_url($delete_folder_link) . '" onclick="return confirm(\'' . esc_js(__('¿Eliminar esta carpeta y todo su contenido?', 'shared-docs-manager')) . '\');">' . esc_html__('Borrar carpeta', 'shared-docs-manager') . '</a>';
            $html .= '</div>';

            $child_html = '';
            if (! empty($files_map[$folder_id])) {
                foreach ($files_map[$folder_id] as $file) {
                    $file_id = (int) $file->ID;
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

                    $child_html .= '<li class="shared-docs-tree__node">';
                    $child_html .= '<div class="shared-docs-tree__item shared-docs-tree__item--file">';
                    $child_html .= '<span class="shared-docs-tree__icon">📄</span>';
                    $child_html .= '<span class="shared-docs-tree__label">' . esc_html($file->post_title) . '</span>';
                    $child_html .= '<a class="shared-docs-tree__delete" href="' . esc_url($delete_file_link) . '" onclick="return confirm(\'' . esc_js(__('¿Eliminar este archivo?', 'shared-docs-manager')) . '\');">' . esc_html__('Borrar', 'shared-docs-manager') . '</a>';
                    $child_html .= '</div>';
                    $child_html .= '</li>';
                }
            }

            $child_html .= $this->render_folder_tree_recursive($folder_id, $folder_children, $files_map);

            if ($child_html !== '') {
                $html .= '<ul class="shared-docs-tree__children">' . $child_html . '</ul>';
            }

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
