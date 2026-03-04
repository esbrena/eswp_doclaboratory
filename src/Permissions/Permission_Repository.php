<?php

namespace SharedDocsManager\Permissions;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

class Permission_Repository
{
    /**
     * Nombre de tabla de permisos.
     *
     * @var string
     */
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'shared_folder_permissions';
    }

    /**
     * Obtiene el nombre de la tabla.
     *
     * @return string
     */
    public function table_name()
    {
        return $this->table_name;
    }

    /**
     * Crea o actualiza un permiso por usuario/carpeta.
     *
     * @param array $data Datos de permiso.
     *
     * @return int|WP_Error
     */
    public function upsert_permission($data)
    {
        global $wpdb;

        $data = wp_parse_args(
            $data,
            array(
                'user_id'        => 0,
                'folder_id'      => 0,
                'can_read'       => 0,
                'can_download'   => 0,
                'can_edit_excel' => 0,
                'expires_at'     => null,
            )
        );

        $user_id = (int) $data['user_id'];
        $folder_id = (int) $data['folder_id'];

        if ($user_id <= 0 || $folder_id <= 0) {
            return new WP_Error('shared_docs_invalid_data', __('Usuario o carpeta inválidos.', 'shared-docs-manager'));
        }

        $existing = $this->get_permission_by_user_folder($user_id, $folder_id, false);

        $payload = array(
            'user_id'        => $user_id,
            'folder_id'      => $folder_id,
            'can_read'       => empty($data['can_read']) ? 0 : 1,
            'can_download'   => empty($data['can_download']) ? 0 : 1,
            'can_edit_excel' => empty($data['can_edit_excel']) ? 0 : 1,
            'expires_at'     => ! empty($data['expires_at']) ? $data['expires_at'] : null,
            'updated_at'     => current_time('mysql'),
        );

        if ($existing) {
            $updated = $wpdb->update(
                $this->table_name,
                $payload,
                array('id' => (int) $existing->id),
                array('%d', '%d', '%d', '%d', '%d', '%s', '%s'),
                array('%d')
            );

            if ($updated === false) {
                return new WP_Error('shared_docs_db_error', __('No se pudo actualizar el permiso.', 'shared-docs-manager'));
            }

            return (int) $existing->id;
        }

        $payload['created_at'] = current_time('mysql');

        $inserted = $wpdb->insert(
            $this->table_name,
            $payload,
            array('%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s')
        );

        if ($inserted === false) {
            return new WP_Error('shared_docs_db_error', __('No se pudo crear el permiso.', 'shared-docs-manager'));
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Obtiene un permiso por ID.
     *
     * @param int $permission_id ID del permiso.
     *
     * @return object|null
     */
    public function get_permission($permission_id)
    {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                (int) $permission_id
            )
        );
    }

    /**
     * Obtiene un permiso por usuario/carpeta.
     *
     * @param int  $user_id         ID usuario.
     * @param int  $folder_id       ID carpeta.
     * @param bool $only_valid_time Si true, ignora expirados.
     *
     * @return object|null
     */
    public function get_permission_by_user_folder($user_id, $folder_id, $only_valid_time = true)
    {
        global $wpdb;

        $user_id = (int) $user_id;
        $folder_id = (int) $folder_id;

        if (! $only_valid_time) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table_name} WHERE user_id = %d AND folder_id = %d LIMIT 1",
                    $user_id,
                    $folder_id
                )
            );
        }

        $now = current_time('mysql');

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                WHERE user_id = %d
                  AND folder_id = %d
                  AND (expires_at IS NULL OR expires_at >= %s)
                LIMIT 1",
                $user_id,
                $folder_id,
                $now
            )
        );
    }

    /**
     * Lista permisos de un usuario.
     *
     * @param int  $user_id         ID de usuario.
     * @param bool $only_valid_time Si true, ignora expirados.
     *
     * @return array
     */
    public function get_user_permissions($user_id, $only_valid_time = true)
    {
        global $wpdb;

        $user_id = (int) $user_id;
        $now = current_time('mysql');

        if (! $only_valid_time) {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE user_id = %d ORDER BY folder_id ASC",
                $user_id
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                 WHERE user_id = %d
                   AND (expires_at IS NULL OR expires_at >= %s)
                 ORDER BY folder_id ASC",
                $user_id,
                $now
            );
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Lista todos los permisos, opcionalmente filtrando por usuario.
     *
     * @param int|null $user_id ID de usuario.
     *
     * @return array
     */
    public function get_all_permissions($user_id = null)
    {
        global $wpdb;

        $now = current_time('mysql');

        if ($user_id !== null) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.*, u.display_name, u.user_email, f.post_title AS folder_name
                     FROM {$this->table_name} p
                     LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id
                     LEFT JOIN {$wpdb->posts} f ON f.ID = p.folder_id
                     WHERE p.user_id = %d
                     ORDER BY p.updated_at DESC, p.id DESC",
                    (int) $user_id
                )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, u.display_name, u.user_email, f.post_title AS folder_name,
                    (CASE
                        WHEN p.expires_at IS NOT NULL AND p.expires_at < %s THEN 1
                        ELSE 0
                     END) AS is_expired
                 FROM {$this->table_name} p
                 LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id
                 LEFT JOIN {$wpdb->posts} f ON f.ID = p.folder_id
                 ORDER BY p.updated_at DESC, p.id DESC",
                $now
            )
        );
    }

    /**
     * Verifica si el usuario tiene alguna carpeta válida.
     *
     * @param int $user_id ID de usuario.
     *
     * @return bool
     */
    public function user_has_any_valid_permission($user_id)
    {
        global $wpdb;

        $user_id = (int) $user_id;
        $now = current_time('mysql');
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(1)
                 FROM {$this->table_name}
                 WHERE user_id = %d
                   AND can_read = 1
                   AND (expires_at IS NULL OR expires_at >= %s)",
                $user_id,
                $now
            )
        );

        return $count > 0;
    }

    /**
     * Elimina un permiso por ID.
     *
     * @param int $permission_id ID permiso.
     *
     * @return bool
     */
    public function delete_permission($permission_id)
    {
        global $wpdb;

        return (bool) $wpdb->delete(
            $this->table_name,
            array('id' => (int) $permission_id),
            array('%d')
        );
    }

    /**
     * Elimina un permiso por usuario/carpeta.
     *
     * @param int $user_id   Usuario.
     * @param int $folder_id Carpeta.
     *
     * @return bool
     */
    public function delete_permission_by_user_folder($user_id, $folder_id)
    {
        global $wpdb;

        return (bool) $wpdb->delete(
            $this->table_name,
            array(
                'user_id'   => (int) $user_id,
                'folder_id' => (int) $folder_id,
            ),
            array('%d', '%d')
        );
    }

    /**
     * Limpia permisos expirados.
     *
     * @return int Cantidad eliminada.
     */
    public function delete_expired_permissions()
    {
        global $wpdb;

        $now = current_time('mysql');
        $sql = $wpdb->prepare(
            "DELETE FROM {$this->table_name}
             WHERE expires_at IS NOT NULL
               AND expires_at < %s",
            $now
        );

        $wpdb->query($sql);

        return (int) $wpdb->rows_affected;
    }
}
