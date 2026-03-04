<?php

namespace SharedDocsManager\Permissions;

if (! defined('ABSPATH')) {
    exit;
}

class Permission_Manager
{
    /**
     * @var Permission_Repository
     */
    private $repository;

    public function __construct(Permission_Repository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Determina si un usuario es gestor global del sistema.
     *
     * @param int $user_id ID de usuario.
     *
     * @return bool
     */
    public function is_manager_user($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }

        if (user_can($user_id, 'shared_docs_manage')) {
            return true;
        }

        $manager_roles = (array) apply_filters(
            'shared_docs_manager_roles',
            array('administrator', 'admin', 'admin_lab')
        );

        $user = get_userdata($user_id);
        if (! $user || empty($user->roles)) {
            return false;
        }

        return (bool) array_intersect($manager_roles, (array) $user->roles);
    }

    /**
     * Verifica si el usuario actual puede gestionar el sistema.
     *
     * @return bool
     */
    public function current_user_can_manage()
    {
        return $this->is_manager_user(get_current_user_id());
    }

    /**
     * Comprueba acceso a carpeta concreta o a cualquier carpeta.
     *
     * @param int      $user_id   Usuario.
     * @param int|null $folder_id Carpeta opcional.
     *
     * @return bool
     */
    public function user_has_access($user_id, $folder_id = null)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }

        if ($this->is_manager_user($user_id)) {
            return true;
        }

        if ($folder_id === null) {
            return $this->repository->user_has_any_valid_permission($user_id);
        }

        return $this->has_capability_for_folder($user_id, (int) $folder_id, 'can_read');
    }

    /**
     * Verifica permiso de descarga.
     *
     * @param int $user_id   Usuario.
     * @param int $folder_id Carpeta.
     *
     * @return bool
     */
    public function user_can_download($user_id, $folder_id)
    {
        return $this->has_capability_for_folder((int) $user_id, (int) $folder_id, 'can_download');
    }

    /**
     * Verifica permiso de edición de Excel.
     *
     * @param int $user_id   Usuario.
     * @param int $folder_id Carpeta.
     *
     * @return bool
     */
    public function user_can_edit_excel($user_id, $folder_id)
    {
        return $this->has_capability_for_folder((int) $user_id, (int) $folder_id, 'can_edit_excel');
    }

    /**
     * Valida si usuario puede operar sobre un archivo.
     *
     * @param int    $user_id    Usuario.
     * @param int    $file_id    Adjuntos.
     * @param string $capability Campo capability.
     *
     * @return bool
     */
    public function user_can_access_file($user_id, $file_id, $capability = 'can_read')
    {
        $folder_id = $this->get_folder_id_from_file($file_id);
        if ($folder_id <= 0) {
            return false;
        }

        return $this->has_capability_for_folder((int) $user_id, $folder_id, $capability);
    }

    /**
     * Obtiene carpeta asociada a un archivo.
     *
     * @param int $file_id ID archivo.
     *
     * @return int
     */
    public function get_folder_id_from_file($file_id)
    {
        return (int) get_post_meta((int) $file_id, 'shared_folder_id', true);
    }

    /**
     * Devuelve IDs de carpetas accesibles para un usuario.
     *
     * @param int    $user_id    Usuario.
     * @param string $capability Capability requerida.
     *
     * @return array
     */
    public function get_accessible_folder_ids($user_id, $capability = 'can_read')
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return array();
        }

        if ($this->is_manager_user($user_id)) {
            return get_posts(
                array(
                    'post_type'      => 'shared_folder',
                    'post_status'    => array('publish', 'private'),
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                )
            );
        }

        $permissions = $this->repository->get_user_permissions($user_id, true);
        $folder_ids = array();
        foreach ($permissions as $permission) {
            if (! empty($permission->{$capability}) && ! empty($permission->can_read)) {
                $folder_ids[] = (int) $permission->folder_id;
            }
        }

        $folder_ids = array_values(array_unique($folder_ids));
        if (empty($folder_ids)) {
            return array();
        }

        if (! $this->is_inheritance_enabled()) {
            return $folder_ids;
        }

        // Hereda permisos a descendientes para navegación opcional.
        $inherited = $folder_ids;
        foreach ($folder_ids as $folder_id) {
            $children = get_posts(
                array(
                    'post_type'      => 'shared_folder',
                    'post_status'    => array('publish', 'private'),
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'child_of'       => (int) $folder_id,
                )
            );

            if (! empty($children)) {
                $inherited = array_merge($inherited, array_map('intval', $children));
            }
        }

        return array_values(array_unique($inherited));
    }

    /**
     * Obtiene carpetas visibles para un usuario, filtradas por padre.
     *
     * @param int      $user_id   Usuario.
     * @param int|null $parent_id Padre o null (raíz virtual).
     *
     * @return array
     */
    public function get_visible_folders_for_user($user_id, $parent_id = null)
    {
        $user_id = (int) $user_id;

        if ($this->is_manager_user($user_id)) {
            $query_parent = $parent_id === null ? 0 : (int) $parent_id;
            return get_posts(
                array(
                    'post_type'      => 'shared_folder',
                    'post_status'    => array('publish', 'private'),
                    'posts_per_page' => -1,
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                    'post_parent'    => $query_parent,
                )
            );
        }

        $accessible_ids = $this->get_accessible_folder_ids($user_id, 'can_read');
        if (empty($accessible_ids)) {
            return array();
        }

        $all_accessible = get_posts(
            array(
                'post_type'      => 'shared_folder',
                'post_status'    => array('publish', 'private'),
                'posts_per_page' => -1,
                'post__in'       => $accessible_ids,
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );

        if ($parent_id === null) {
            $root_folders = array();
            foreach ($all_accessible as $folder) {
                $is_parent_accessible = in_array((int) $folder->post_parent, $accessible_ids, true);
                if ((int) $folder->post_parent === 0 || ! $is_parent_accessible) {
                    $root_folders[] = $folder;
                }
            }
            return $root_folders;
        }

        $filtered = array();
        foreach ($all_accessible as $folder) {
            if ((int) $folder->post_parent === (int) $parent_id) {
                $filtered[] = $folder;
            }
        }

        return $filtered;
    }

    /**
     * Devuelve breadcrumb de una carpeta accesible.
     *
     * @param int $user_id   Usuario.
     * @param int $folder_id Carpeta.
     *
     * @return array
     */
    public function get_breadcrumb($user_id, $folder_id)
    {
        $folder_id = (int) $folder_id;
        if ($folder_id <= 0) {
            return array();
        }

        $items = array();
        $trail = array_reverse(array_map('intval', get_post_ancestors($folder_id)));
        $trail[] = $folder_id;

        foreach ($trail as $item_id) {
            $post = get_post($item_id);
            if (! $post || $post->post_type !== 'shared_folder') {
                continue;
            }

            if (! $this->is_manager_user($user_id) && ! $this->user_has_access($user_id, $item_id) && $item_id !== $folder_id) {
                continue;
            }

            $items[] = array(
                'id'    => (int) $post->ID,
                'title' => $post->post_title,
            );
        }

        return $items;
    }

    /**
     * Comprueba si una carpeta tiene subcarpetas visibles.
     *
     * @param int $user_id   Usuario.
     * @param int $folder_id Carpeta.
     *
     * @return bool
     */
    public function folder_has_visible_children($user_id, $folder_id)
    {
        $children = $this->get_visible_folders_for_user((int) $user_id, (int) $folder_id);
        return ! empty($children);
    }

    /**
     * Devuelve el HTML renderizado con permisos de un usuario.
     *
     * @param int $user_id Usuario.
     *
     * @return string
     */
    public function get_user_permissions_html($user_id)
    {
        $user_id = (int) $user_id;
        $user = get_userdata($user_id);
        if (! $user) {
            return '<p>' . esc_html__('Usuario no encontrado.', 'shared-docs-manager') . '</p>';
        }

        $permissions = $this->repository->get_user_permissions($user_id, false);
        $is_manager = $this->is_manager_user($user_id);
        $can_edit_links = $this->is_manager_user(get_current_user_id());

        ob_start();
        ?>
        <div class="shared-docs-user-permissions">
            <h3><?php echo esc_html(sprintf(__('Permisos de %s', 'shared-docs-manager'), $user->display_name)); ?></h3>
            <?php if ($is_manager) : ?>
                <p class="description">
                    <?php esc_html_e('Este usuario es gestor global (admin/admin_lab) y tiene acceso completo a todas las carpetas.', 'shared-docs-manager'); ?>
                </p>
            <?php endif; ?>

            <?php if (empty($permissions)) : ?>
                <p><?php esc_html_e('No hay permisos específicos asignados.', 'shared-docs-manager'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php esc_html_e('Carpeta', 'shared-docs-manager'); ?></th>
                        <th><?php esc_html_e('Lectura', 'shared-docs-manager'); ?></th>
                        <th><?php esc_html_e('Descarga', 'shared-docs-manager'); ?></th>
                        <th><?php esc_html_e('Edición Excel', 'shared-docs-manager'); ?></th>
                        <th><?php esc_html_e('Expira', 'shared-docs-manager'); ?></th>
                        <th><?php esc_html_e('Editar', 'shared-docs-manager'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($permissions as $permission) : ?>
                        <?php
                        $folder_title = get_the_title((int) $permission->folder_id);
                        $folder_title = $folder_title ? $folder_title : __('(Carpeta eliminada)', 'shared-docs-manager');
                        $is_expired = ! empty($permission->expires_at) && strtotime($permission->expires_at) < current_time('timestamp');
                        $expires_label = empty($permission->expires_at)
                            ? __('Sin límite', 'shared-docs-manager')
                            : wp_date(
                                get_option('date_format') . ' ' . get_option('time_format'),
                                strtotime($permission->expires_at)
                            );
                        $edit_url = add_query_arg(
                            array(
                                'page'          => 'shared-docs',
                                'action'        => 'edit_permission',
                                'permission_id' => (int) $permission->id,
                                'user_id'       => $user_id,
                            ),
                            admin_url('admin.php')
                        );
                        ?>
                        <tr>
                            <td>
                                <?php echo esc_html($folder_title); ?>
                                <?php if ($is_expired) : ?>
                                    <span class="shared-docs-badge shared-docs-badge-danger"><?php esc_html_e('Expirado', 'shared-docs-manager'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo ! empty($permission->can_read) ? '✔' : '—'; ?></td>
                            <td><?php echo ! empty($permission->can_download) ? '✔' : '—'; ?></td>
                            <td><?php echo ! empty($permission->can_edit_excel) ? '✔' : '—'; ?></td>
                            <td><?php echo esc_html($expires_label); ?></td>
                            <td>
                                <?php if ($can_edit_links) : ?>
                                    <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Editar permisos', 'shared-docs-manager'); ?></a>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Comprueba capability para una carpeta (con herencia opcional).
     *
     * @param int    $user_id    Usuario.
     * @param int    $folder_id  Carpeta.
     * @param string $capability Capability.
     *
     * @return bool
     */
    private function has_capability_for_folder($user_id, $folder_id, $capability)
    {
        $user_id = (int) $user_id;
        $folder_id = (int) $folder_id;

        if ($user_id <= 0 || $folder_id <= 0) {
            return false;
        }

        if ($this->is_manager_user($user_id)) {
            return true;
        }

        $supported = array('can_read', 'can_download', 'can_edit_excel');
        if (! in_array($capability, $supported, true)) {
            $capability = 'can_read';
        }

        $folder_chain = array($folder_id);
        if ($this->is_inheritance_enabled()) {
            $folder_chain = array_merge($folder_chain, array_map('intval', get_post_ancestors($folder_id)));
        }

        foreach ($folder_chain as $check_folder_id) {
            $permission = $this->repository->get_permission_by_user_folder($user_id, (int) $check_folder_id, true);
            if (! $permission) {
                continue;
            }

            // Si no hay lectura no se consideran permisos derivados para ese registro.
            if (empty($permission->can_read)) {
                continue;
            }

            if (! empty($permission->{$capability})) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indica si la herencia de permisos está habilitada.
     *
     * @return bool
     */
    private function is_inheritance_enabled()
    {
        $enabled = get_option('shared_docs_enable_inheritance', '0');
        return (bool) apply_filters('shared_docs_enable_inheritance', $enabled === '1');
    }
}
