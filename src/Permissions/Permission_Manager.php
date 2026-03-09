<?php

namespace SharedDocsManager\Permissions;

if (! defined('ABSPATH')) {
    exit;
}

class Permission_Manager
{
    const PERMISSION_DENY = -1;
    const PERMISSION_INHERIT = 0;
    const PERMISSION_ALLOW = 1;

    /**
     * @var Permission_Repository
     */
    private $folder_repository;

    /**
     * @var File_Permission_Repository
     */
    private $file_repository;

    /**
     * Caché en memoria de permisos folder por usuario/carpeta.
     *
     * @var array
     */
    private $folder_permission_cache = array();

    /**
     * Caché en memoria de permisos file por usuario/archivo.
     *
     * @var array
     */
    private $file_permission_cache = array();

    public function __construct(Permission_Repository $folder_repository, File_Permission_Repository $file_repository)
    {
        $this->folder_repository = $folder_repository;
        $this->file_repository = $file_repository;
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
     * Comprueba acceso a carpeta concreta o a cualquier carpeta/archivo.
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
            return $this->folder_repository->user_has_any_valid_permission($user_id)
                || $this->file_repository->user_has_any_valid_permission($user_id);
        }

        return $this->user_can_view_folder($user_id, (int) $folder_id);
    }

    /**
     * Determina si un usuario puede ver contenido de carpeta.
     *
     * @param int $user_id   Usuario.
     * @param int $folder_id Carpeta.
     *
     * @return bool
     */
    public function user_can_view_folder($user_id, $folder_id)
    {
        if ($this->is_manager_user($user_id)) {
            return true;
        }

        return $this->resolve_folder_permission_state((int) $user_id, (int) $folder_id, 'can_read') === self::PERMISSION_ALLOW;
    }

    /**
     * Verifica permiso de descarga por carpeta.
     *
     * @param int $user_id   Usuario.
     * @param int $folder_id Carpeta.
     *
     * @return bool
     */
    public function user_can_download($user_id, $folder_id)
    {
        return $this->resolve_folder_permission_state((int) $user_id, (int) $folder_id, 'can_download') === self::PERMISSION_ALLOW;
    }

    /**
     * Verifica permiso de edición de Excel por carpeta.
     *
     * @param int $user_id   Usuario.
     * @param int $folder_id Carpeta.
     *
     * @return bool
     */
    public function user_can_edit_excel($user_id, $folder_id)
    {
        return $this->resolve_folder_permission_state((int) $user_id, (int) $folder_id, 'can_edit_excel') === self::PERMISSION_ALLOW;
    }

    /**
     * Valida permisos efectivos sobre archivo (archivo directo o carpeta).
     *
     * @param int    $user_id    Usuario.
     * @param int    $file_id    Archivo adjunto.
     * @param string $capability Capability.
     *
     * @return bool
     */
    public function user_can_access_file($user_id, $file_id, $capability = 'can_read')
    {
        $user_id = (int) $user_id;
        $file_id = (int) $file_id;
        if ($user_id <= 0 || $file_id <= 0) {
            return false;
        }

        if ($this->is_manager_user($user_id)) {
            return true;
        }

        return $this->resolve_file_permission_state($user_id, $file_id, $capability) === self::PERMISSION_ALLOW;
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
        $capability = $this->normalize_capability($capability);
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

        $all_folder_ids = $this->get_all_folder_ids();
        if (empty($all_folder_ids)) {
            return array();
        }

        $allowed = array();
        foreach ($all_folder_ids as $folder_id) {
            if ($this->resolve_folder_permission_state($user_id, (int) $folder_id, $capability) === self::PERMISSION_ALLOW) {
                $allowed[] = (int) $folder_id;
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * Devuelve IDs de archivos accesibles para un usuario.
     *
     * @param int    $user_id    Usuario.
     * @param string $capability Capability requerida.
     *
     * @return array
     */
    public function get_accessible_file_ids($user_id, $capability = 'can_read')
    {
        $user_id = (int) $user_id;
        $capability = $this->normalize_capability($capability);
        if ($user_id <= 0) {
            return array();
        }

        if ($this->is_manager_user($user_id)) {
            return get_posts(
                array(
                    'post_type'      => 'attachment',
                    'post_status'    => array('inherit', 'private'),
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'     => 'shared_folder_id',
                            'compare' => 'EXISTS',
                        ),
                    ),
                )
            );
        }

        // 1) Permitidos explícitos por archivo.
        $file_ids = $this->file_repository->get_valid_file_ids_for_user($user_id, $capability);

        // 2) Permitidos por herencia de carpeta.
        $folder_ids = $this->get_accessible_folder_ids($user_id, $capability);
        if (! empty($folder_ids)) {
            $folder_file_ids = get_posts(
                array(
                    'post_type'      => 'attachment',
                    'post_status'    => array('inherit', 'private'),
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'     => 'shared_folder_id',
                            'value'   => $folder_ids,
                            'compare' => 'IN',
                        ),
                    ),
                )
            );

            if (! empty($folder_file_ids)) {
                $file_ids = array_merge($file_ids, array_map('intval', $folder_file_ids));
            }
        }

        // 3) Denegaciones explícitas por archivo tienen prioridad sobre carpeta.
        $denied_file_ids = $this->file_repository->get_denied_file_ids_for_user($user_id, $capability);
        if (! empty($denied_file_ids) && ! empty($file_ids)) {
            $denied_lookup = array_fill_keys(array_map('intval', $denied_file_ids), true);
            $file_ids = array_values(
                array_filter(
                    array_map('intval', $file_ids),
                    static function ($file_id) use ($denied_lookup) {
                        return ! isset($denied_lookup[(int) $file_id]);
                    }
                )
            );
        }

        return array_values(array_unique(array_map('intval', $file_ids)));
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

            if (! $this->is_manager_user($user_id) && ! $this->user_can_view_folder($user_id, $item_id) && $item_id !== $folder_id) {
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

        $folder_permissions = $this->folder_repository->get_user_permissions($user_id, false);
        $file_permissions = $this->file_repository->get_user_permissions($user_id, false);
        $explicit_file_ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static function ($permission) {
                            return isset($permission->file_id) ? (int) $permission->file_id : 0;
                        },
                        (array) $file_permissions
                    )
                )
            )
        );
        $effective_file_ids = $this->get_accessible_file_ids($user_id, 'can_read');
        $inherited_file_ids = array_values(array_diff(array_map('intval', $effective_file_ids), $explicit_file_ids));
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

            <?php if (empty($folder_permissions) && empty($file_permissions)) : ?>
                <p><?php esc_html_e('No hay permisos específicos asignados en carpetas ni archivos.', 'shared-docs-manager'); ?></p>
            <?php else : ?>
                <h4><?php esc_html_e('Permisos por carpeta', 'shared-docs-manager'); ?></h4>
                <?php if (empty($folder_permissions)) : ?>
                    <p><?php esc_html_e('No hay permisos directos en carpetas.', 'shared-docs-manager'); ?></p>
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
                        <?php foreach ($folder_permissions as $permission) : ?>
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
                                    'page'          => 'shared-docs-permissions',
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

                <h4 style="margin-top:16px;"><?php esc_html_e('Permisos por archivo', 'shared-docs-manager'); ?></h4>
                <?php if (empty($file_permissions)) : ?>
                    <p><?php esc_html_e('No hay permisos directos asignados a archivos.', 'shared-docs-manager'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Archivo', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Carpeta', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Lectura', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Descarga', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Edición Excel', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Expira', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Editar', 'shared-docs-manager'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($file_permissions as $permission) : ?>
                            <?php
                            $file_title = get_the_title((int) $permission->file_id);
                            $file_title = $file_title ? $file_title : __('(Archivo eliminado)', 'shared-docs-manager');
                            $folder_id = $this->get_folder_id_from_file((int) $permission->file_id);
                            $folder_title = $folder_id > 0 ? get_the_title($folder_id) : '';
                            $folder_title = $folder_title ? $folder_title : __('(Sin carpeta)', 'shared-docs-manager');
                            $is_expired = ! empty($permission->expires_at) && strtotime($permission->expires_at) < current_time('timestamp');
                            $expires_label = empty($permission->expires_at)
                                ? __('Sin límite', 'shared-docs-manager')
                                : wp_date(
                                    get_option('date_format') . ' ' . get_option('time_format'),
                                    strtotime($permission->expires_at)
                                );
                            $edit_url = add_query_arg(
                                array(
                                    'page'          => 'shared-docs-permissions',
                                    'action'        => 'edit_file_permission',
                                    'permission_id' => (int) $permission->id,
                                    'user_id'       => $user_id,
                                ),
                                admin_url('admin.php')
                            );
                            ?>
                            <tr>
                                <td>
                                    <?php echo esc_html($file_title); ?>
                                    <?php if ($is_expired) : ?>
                                        <span class="shared-docs-badge shared-docs-badge-danger"><?php esc_html_e('Expirado', 'shared-docs-manager'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($folder_title); ?></td>
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

                <h4 style="margin-top:16px;"><?php esc_html_e('Accesos heredados por carpeta en archivos', 'shared-docs-manager'); ?></h4>
                <?php if (empty($inherited_file_ids)) : ?>
                    <p><?php esc_html_e('No hay accesos heredados activos en archivos.', 'shared-docs-manager'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Archivo', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Carpeta', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Lectura', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Descarga', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Edición Excel', 'shared-docs-manager'); ?></th>
                            <th><?php esc_html_e('Origen', 'shared-docs-manager'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($inherited_file_ids as $file_id) : ?>
                            <?php
                            $file_id = (int) $file_id;
                            $file_title = get_the_title($file_id);
                            $file_title = $file_title ? $file_title : __('(Archivo eliminado)', 'shared-docs-manager');
                            $folder_id = $this->get_folder_id_from_file($file_id);
                            $folder_title = $folder_id > 0 ? get_the_title($folder_id) : '';
                            $folder_title = $folder_title ? $folder_title : __('(Sin carpeta)', 'shared-docs-manager');
                            $can_download_file = $this->user_can_access_file($user_id, $file_id, 'can_download');
                            $can_edit_excel_file = $this->user_can_access_file($user_id, $file_id, 'can_edit_excel');
                            ?>
                            <tr>
                                <td><?php echo esc_html($file_title); ?></td>
                                <td><?php echo esc_html($folder_title); ?></td>
                                <td><?php esc_html_e('Permitir (heredado)', 'shared-docs-manager'); ?></td>
                                <td><?php echo $can_download_file ? esc_html__('Permitir (heredado)', 'shared-docs-manager') : esc_html__('Denegar (heredado)', 'shared-docs-manager'); ?></td>
                                <td><?php echo $can_edit_excel_file ? esc_html__('Permitir (heredado)', 'shared-docs-manager') : esc_html__('Denegar (heredado)', 'shared-docs-manager'); ?></td>
                                <td><?php esc_html_e('Permisos de carpeta', 'shared-docs-manager'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Resuelve permiso efectivo en carpeta.
     *
     * Reglas:
     * - Se evalúa carpeta específica y, opcionalmente, ancestros (si herencia activada).
     * - Gana la regla más cercana (más específica).
     * - No hay escalado desde archivo hacia carpeta.
     *
     * @param int    $user_id    Usuario.
     * @param int    $folder_id  Carpeta.
     * @param string $capability Capability.
     *
     * @return int self::PERMISSION_*
     */
    private function resolve_folder_permission_state($user_id, $folder_id, $capability)
    {
        $user_id = (int) $user_id;
        $folder_id = (int) $folder_id;
        $capability = $this->normalize_capability($capability);

        if ($user_id <= 0 || $folder_id <= 0) {
            return self::PERMISSION_INHERIT;
        }

        if ($this->is_manager_user($user_id)) {
            return self::PERMISSION_ALLOW;
        }

        $folder_chain = array($folder_id);
        if ($this->is_inheritance_enabled()) {
            $folder_chain = array_merge($folder_chain, array_map('intval', get_post_ancestors($folder_id)));
        }

        foreach ($folder_chain as $check_folder_id) {
            $permission = $this->get_cached_folder_permission($user_id, (int) $check_folder_id);
            if (! $permission) {
                continue;
            }

            $state = $this->permission_state_from_row($permission, $capability);
            if ($state === self::PERMISSION_INHERIT) {
                continue;
            }

            return $state;
        }

        return self::PERMISSION_INHERIT;
    }

    /**
     * Resuelve permiso efectivo en archivo con precedencia:
     * File deny > File allow > Folder deny > Folder allow > Default deny.
     *
     * @param int    $user_id    Usuario.
     * @param int    $file_id    Archivo.
     * @param string $capability Capability.
     *
     * @return int self::PERMISSION_*
     */
    private function resolve_file_permission_state($user_id, $file_id, $capability)
    {
        $user_id = (int) $user_id;
        $file_id = (int) $file_id;
        $capability = $this->normalize_capability($capability);

        if ($user_id <= 0 || $file_id <= 0) {
            return self::PERMISSION_INHERIT;
        }

        if ($this->is_manager_user($user_id)) {
            return self::PERMISSION_ALLOW;
        }

        $file_permission = $this->get_cached_file_permission($user_id, $file_id);
        if ($file_permission) {
            $file_state = $this->permission_state_from_row($file_permission, $capability);
            if ($file_state !== self::PERMISSION_INHERIT) {
                return $file_state;
            }
        }

        $folder_id = $this->get_folder_id_from_file($file_id);
        if ($folder_id <= 0) {
            return self::PERMISSION_INHERIT;
        }

        return $this->resolve_folder_permission_state($user_id, $folder_id, $capability);
    }

    /**
     * Convierte una fila de permiso binario en estado allow/deny.
     *
     * @param object $permission Row DB.
     * @param string $capability Capability.
     *
     * @return int self::PERMISSION_*
     */
    private function permission_state_from_row($permission, $capability)
    {
        $capability = $this->normalize_capability($capability);

        $uses_state_columns = isset($permission->read_state)
            && isset($permission->download_state)
            && isset($permission->edit_excel_state);

        if ($uses_state_columns) {
            $read_state = $this->normalize_state_value($permission->read_state);
            if ($capability === 'can_read') {
                return $read_state;
            }

            if ($read_state === self::PERMISSION_DENY) {
                return self::PERMISSION_DENY;
            }

            if ($read_state === self::PERMISSION_INHERIT) {
                return self::PERMISSION_INHERIT;
            }

            $state_column = $this->state_column_for_capability($capability);
            if (! isset($permission->{$state_column})) {
                return self::PERMISSION_INHERIT;
            }

            return $this->normalize_state_value($permission->{$state_column});
        }

        if (empty($permission->can_read)) {
            return self::PERMISSION_DENY;
        }

        if ($capability === 'can_read') {
            return self::PERMISSION_ALLOW;
        }

        return ! empty($permission->{$capability}) ? self::PERMISSION_ALLOW : self::PERMISSION_DENY;
    }

    /**
     * Normaliza capability soportada.
     *
     * @param string $capability Capability.
     *
     * @return string
     */
    private function normalize_capability($capability)
    {
        $supported = array('can_read', 'can_download', 'can_edit_excel');
        return in_array($capability, $supported, true) ? $capability : 'can_read';
    }

    /**
     * Mapea capability a columna tri-state.
     *
     * @param string $capability Capability.
     *
     * @return string
     */
    private function state_column_for_capability($capability)
    {
        if ($capability === 'can_download') {
            return 'download_state';
        }

        if ($capability === 'can_edit_excel') {
            return 'edit_excel_state';
        }

        return 'read_state';
    }

    /**
     * Normaliza valor de estado tri-state.
     *
     * @param mixed $value Valor.
     *
     * @return int
     */
    private function normalize_state_value($value)
    {
        $value = (int) $value;
        if ($value > 0) {
            return self::PERMISSION_ALLOW;
        }
        if ($value < 0) {
            return self::PERMISSION_DENY;
        }

        return self::PERMISSION_INHERIT;
    }

    /**
     * Obtiene (y cachea) permiso válido folder por usuario/carpeta.
     *
     * @param int $user_id   Usuario.
     * @param int $folder_id Carpeta.
     *
     * @return object|null
     */
    private function get_cached_folder_permission($user_id, $folder_id)
    {
        $user_id = (int) $user_id;
        $folder_id = (int) $folder_id;

        if (! isset($this->folder_permission_cache[$user_id])) {
            $this->folder_permission_cache[$user_id] = array();
        }

        if (! array_key_exists($folder_id, $this->folder_permission_cache[$user_id])) {
            $this->folder_permission_cache[$user_id][$folder_id] = $this->folder_repository->get_permission_by_user_folder($user_id, $folder_id, true);
        }

        return $this->folder_permission_cache[$user_id][$folder_id];
    }

    /**
     * Obtiene (y cachea) permiso válido file por usuario/archivo.
     *
     * @param int $user_id Usuario.
     * @param int $file_id Archivo.
     *
     * @return object|null
     */
    private function get_cached_file_permission($user_id, $file_id)
    {
        $user_id = (int) $user_id;
        $file_id = (int) $file_id;

        if (! isset($this->file_permission_cache[$user_id])) {
            $this->file_permission_cache[$user_id] = array();
        }

        if (! array_key_exists($file_id, $this->file_permission_cache[$user_id])) {
            $this->file_permission_cache[$user_id][$file_id] = $this->file_repository->get_permission_by_user_file($user_id, $file_id, true);
        }

        return $this->file_permission_cache[$user_id][$file_id];
    }

    /**
     * Devuelve todos los IDs de carpeta del gestor.
     *
     * @return array
     */
    private function get_all_folder_ids()
    {
        return get_posts(
            array(
                'post_type'      => 'shared_folder',
                'post_status'    => array('publish', 'private'),
                'posts_per_page' => -1,
                'fields'         => 'ids',
            )
        );
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
