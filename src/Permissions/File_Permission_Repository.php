<?php

namespace SharedDocsManager\Permissions;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

class File_Permission_Repository
{
    const STATE_DENY = -1;
    const STATE_INHERIT = 0;
    const STATE_ALLOW = 1;

    /**
     * Nombre de tabla de permisos por archivo.
     *
     * @var string
     */
    private $table_name;

    /**
     * Cache de disponibilidad de columnas tri-state.
     *
     * @var bool|null
     */
    private $has_state_columns = null;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'shared_file_permissions';
    }

    /**
     * Devuelve el nombre de la tabla.
     *
     * @return string
     */
    public function table_name()
    {
        return $this->table_name;
    }

    /**
     * Inserta o actualiza permiso por usuario/archivo.
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
                'file_id'        => 0,
                'can_read'       => 0,
                'can_download'   => 0,
                'can_edit_excel' => 0,
                'expires_at'     => null,
            )
        );

        $user_id = (int) $data['user_id'];
        $file_id = (int) $data['file_id'];

        if ($user_id <= 0 || $file_id <= 0) {
            return new WP_Error('shared_docs_invalid_data', __('Usuario o archivo inválidos.', 'shared-docs-manager'));
        }

        $existing = $this->get_permission_by_user_file($user_id, $file_id, false);

        $read_state = $this->extract_state($data, 'read_state', 'can_read');
        $download_state = $this->extract_state($data, 'download_state', 'can_download');
        $edit_excel_state = $this->extract_state($data, 'edit_excel_state', 'can_edit_excel');

        $payload = array(
            'user_id'        => $user_id,
            'file_id'        => $file_id,
            'can_read'       => $read_state === self::STATE_ALLOW ? 1 : 0,
            'can_download'   => ($read_state === self::STATE_ALLOW && $download_state === self::STATE_ALLOW) ? 1 : 0,
            'can_edit_excel' => ($read_state === self::STATE_ALLOW && $edit_excel_state === self::STATE_ALLOW) ? 1 : 0,
            'expires_at'     => ! empty($data['expires_at']) ? $data['expires_at'] : null,
            'updated_at'     => current_time('mysql'),
        );
        $format = array('%d', '%d', '%d', '%d', '%d', '%s', '%s');

        if ($this->has_state_columns()) {
            $payload['read_state'] = $read_state;
            $payload['download_state'] = $download_state;
            $payload['edit_excel_state'] = $edit_excel_state;
            $format[] = '%d';
            $format[] = '%d';
            $format[] = '%d';
        }

        if ($existing) {
            $updated = $wpdb->update(
                $this->table_name,
                $payload,
                array('id' => (int) $existing->id),
                $format,
                array('%d')
            );

            if ($updated === false) {
                return new WP_Error('shared_docs_db_error', __('No se pudo actualizar el permiso de archivo.', 'shared-docs-manager'));
            }

            return (int) $existing->id;
        }

        $payload['created_at'] = current_time('mysql');
        $inserted = $wpdb->insert(
            $this->table_name,
            $payload,
            array_merge($format, array('%s'))
        );

        if ($inserted === false) {
            return new WP_Error('shared_docs_db_error', __('No se pudo crear el permiso de archivo.', 'shared-docs-manager'));
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Obtiene un permiso de archivo por ID.
     *
     * @param int $permission_id ID.
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
     * Obtiene permiso por usuario/archivo.
     *
     * @param int  $user_id         Usuario.
     * @param int  $file_id         Archivo.
     * @param bool $only_valid_time Ignora expirados.
     *
     * @return object|null
     */
    public function get_permission_by_user_file($user_id, $file_id, $only_valid_time = true)
    {
        global $wpdb;

        $user_id = (int) $user_id;
        $file_id = (int) $file_id;

        if (! $only_valid_time) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table_name} WHERE user_id = %d AND file_id = %d LIMIT 1",
                    $user_id,
                    $file_id
                )
            );
        }

        $now = current_time('mysql');
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                 WHERE user_id = %d
                   AND file_id = %d
                   AND (expires_at IS NULL OR expires_at >= %s)
                 LIMIT 1",
                $user_id,
                $file_id,
                $now
            )
        );
    }

    /**
     * Lista permisos por archivo para un usuario.
     *
     * @param int  $user_id         Usuario.
     * @param bool $only_valid_time Ignora expirados.
     *
     * @return array
     */
    public function get_user_permissions($user_id, $only_valid_time = true)
    {
        global $wpdb;

        $user_id = (int) $user_id;

        if (! $only_valid_time) {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE user_id = %d ORDER BY file_id ASC",
                $user_id
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                 WHERE user_id = %d
                   AND (expires_at IS NULL OR expires_at >= %s)
                 ORDER BY file_id ASC",
                $user_id,
                current_time('mysql')
            );
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Lista todos los permisos por archivo.
     *
     * @param int|null $user_id Usuario opcional.
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
                    "SELECT p.*, u.display_name, u.user_email, a.post_title AS file_name
                     FROM {$this->table_name} p
                     LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id
                     LEFT JOIN {$wpdb->posts} a ON a.ID = p.file_id
                     WHERE p.user_id = %d
                     ORDER BY p.updated_at DESC, p.id DESC",
                    (int) $user_id
                )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, u.display_name, u.user_email, a.post_title AS file_name,
                    (CASE
                        WHEN p.expires_at IS NOT NULL AND p.expires_at < %s THEN 1
                        ELSE 0
                     END) AS is_expired
                 FROM {$this->table_name} p
                 LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id
                 LEFT JOIN {$wpdb->posts} a ON a.ID = p.file_id
                 ORDER BY p.updated_at DESC, p.id DESC",
                $now
            )
        );
    }

    /**
     * Devuelve IDs de archivo válidos para un usuario.
     *
     * @param int    $user_id    Usuario.
     * @param string $capability Campo capability.
     *
     * @return array
     */
    public function get_valid_file_ids_for_user($user_id, $capability = 'can_read')
    {
        global $wpdb;

        $user_id = (int) $user_id;
        $supported = array('can_read', 'can_download', 'can_edit_excel');
        if (! in_array($capability, $supported, true)) {
            $capability = 'can_read';
        }

        if ($this->has_state_columns()) {
            $state_column = $this->state_column_for_capability($capability);
            $sql = $wpdb->prepare(
                "SELECT file_id
                 FROM {$this->table_name}
                 WHERE user_id = %d
                   AND read_state = 1
                   AND {$state_column} = 1
                   AND (expires_at IS NULL OR expires_at >= %s)",
                $user_id,
                current_time('mysql')
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT file_id
                 FROM {$this->table_name}
                 WHERE user_id = %d
                   AND can_read = 1
                   AND {$capability} = 1
                   AND (expires_at IS NULL OR expires_at >= %s)",
                $user_id,
                current_time('mysql')
            );
        }

        $rows = (array) $wpdb->get_col($sql);
        return array_map('intval', $rows);
    }

    /**
     * Devuelve IDs de archivo denegados explícitamente para un usuario.
     *
     * @param int    $user_id    Usuario.
     * @param string $capability Campo capability.
     *
     * @return array
     */
    public function get_denied_file_ids_for_user($user_id, $capability = 'can_read')
    {
        global $wpdb;

        $user_id = (int) $user_id;
        $supported = array('can_read', 'can_download', 'can_edit_excel');
        if (! in_array($capability, $supported, true)) {
            $capability = 'can_read';
        }

        if ($this->has_state_columns()) {
            if ($capability === 'can_read') {
                $sql = $wpdb->prepare(
                    "SELECT file_id
                     FROM {$this->table_name}
                     WHERE user_id = %d
                       AND read_state = -1
                       AND (expires_at IS NULL OR expires_at >= %s)",
                    $user_id,
                    current_time('mysql')
                );
            } else {
                $state_column = $this->state_column_for_capability($capability);
                $sql = $wpdb->prepare(
                    "SELECT file_id
                     FROM {$this->table_name}
                     WHERE user_id = %d
                       AND (read_state = -1 OR {$state_column} = -1)
                       AND (expires_at IS NULL OR expires_at >= %s)",
                    $user_id,
                    current_time('mysql')
                );
            }
        } else {
            $sql = $wpdb->prepare(
                "SELECT file_id
                 FROM {$this->table_name}
                 WHERE user_id = %d
                   AND (can_read = 0 OR {$capability} = 0)
                   AND (expires_at IS NULL OR expires_at >= %s)",
                $user_id,
                current_time('mysql')
            );
        }

        $rows = (array) $wpdb->get_col($sql);
        return array_map('intval', $rows);
    }

    /**
     * Verifica si el usuario tiene algún permiso válido por archivo.
     *
     * @param int $user_id Usuario.
     *
     * @return bool
     */
    public function user_has_any_valid_permission($user_id)
    {
        global $wpdb;
        if ($this->has_state_columns()) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(1)
                     FROM {$this->table_name}
                     WHERE user_id = %d
                       AND read_state = 1
                       AND (expires_at IS NULL OR expires_at >= %s)",
                    (int) $user_id,
                    current_time('mysql')
                )
            );
        } else {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(1)
                     FROM {$this->table_name}
                     WHERE user_id = %d
                       AND can_read = 1
                       AND (expires_at IS NULL OR expires_at >= %s)",
                    (int) $user_id,
                    current_time('mysql')
                )
            );
        }

        return $count > 0;
    }

    /**
     * Elimina permiso por ID.
     *
     * @param int $permission_id ID.
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
     * Elimina permisos vinculados a varios archivos.
     *
     * @param array $file_ids IDs de archivo.
     *
     * @return int Cantidad eliminada.
     */
    public function delete_permissions_by_file_ids($file_ids)
    {
        global $wpdb;

        $file_ids = array_values(array_unique(array_filter(array_map('intval', (array) $file_ids))));
        if (empty($file_ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($file_ids), '%d'));
        $sql = $wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE file_id IN ({$placeholders})",
            $file_ids
        );

        $wpdb->query($sql);

        return (int) $wpdb->rows_affected;
    }

    /**
     * Limpia permisos expirados.
     *
     * @return int
     */
    public function delete_expired_permissions()
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "DELETE FROM {$this->table_name}
             WHERE expires_at IS NOT NULL
               AND expires_at < %s",
            current_time('mysql')
        );

        $wpdb->query($sql);

        return (int) $wpdb->rows_affected;
    }

    /**
     * Obtiene estado tri-state desde data de entrada.
     *
     * @param array  $data       Datos.
     * @param string $state_key  Campo tri-state.
     * @param string $legacy_key Campo legacy bool.
     *
     * @return int
     */
    private function extract_state($data, $state_key, $legacy_key)
    {
        if (array_key_exists($state_key, (array) $data)) {
            $state = (int) $data[$state_key];
            if ($state > 0) {
                return self::STATE_ALLOW;
            }
            if ($state < 0) {
                return self::STATE_DENY;
            }

            return self::STATE_INHERIT;
        }

        return empty($data[$legacy_key]) ? self::STATE_DENY : self::STATE_ALLOW;
    }

    /**
     * Mapea capability al nombre de columna tri-state.
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
     * Indica si la tabla tiene columnas tri-state.
     *
     * @return bool
     */
    private function has_state_columns()
    {
        global $wpdb;

        if ($this->has_state_columns !== null) {
            return $this->has_state_columns;
        }

        $columns = array('read_state', 'download_state', 'edit_excel_state');
        foreach ($columns as $column) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SHOW COLUMNS FROM {$this->table_name} LIKE %s",
                    $column
                )
            );

            if (! $exists) {
                $this->has_state_columns = false;
                return false;
            }
        }

        $this->has_state_columns = true;
        return true;
    }
}
