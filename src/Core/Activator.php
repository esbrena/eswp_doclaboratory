<?php

namespace SharedDocsManager\Core;

use SharedDocsManager\Helpers\File_Helper;

if (! defined('ABSPATH')) {
    exit;
}

class Activator
{
    /**
     * Ejecuta lógica de activación.
     *
     * @return void
     */
    public static function activate()
    {
        self::create_tables();
        self::grant_management_capability();

        Post_Types::register();
        File_Helper::ensure_protected_directory();

        if (! wp_next_scheduled('shared_docs_daily_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'shared_docs_daily_cleanup');
        }

        if (get_option('shared_docs_no_access_message', null) === null) {
            add_option('shared_docs_no_access_message', __('No tienes acceso a carpetas compartidas.', 'shared-docs-manager'));
        }

        if (get_option('shared_docs_enable_inheritance', null) === null) {
            add_option('shared_docs_enable_inheritance', '0');
        }

        flush_rewrite_rules();
    }

    /**
     * Crea tablas personalizadas.
     *
     * @return void
     */
    private static function create_tables()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $permissions_table = $wpdb->prefix . 'shared_folder_permissions';
        $activity_table = $wpdb->prefix . 'shared_activity_log';

        $permissions_sql = "CREATE TABLE {$permissions_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            folder_id BIGINT(20) UNSIGNED NOT NULL,
            can_read TINYINT(1) NOT NULL DEFAULT 1,
            can_download TINYINT(1) NOT NULL DEFAULT 1,
            can_edit_excel TINYINT(1) NOT NULL DEFAULT 0,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_folder_unique (user_id, folder_id),
            KEY user_idx (user_id),
            KEY folder_idx (folder_id),
            KEY expires_idx (expires_at)
        ) {$charset_collate};";

        $activity_sql = "CREATE TABLE {$activity_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            file_id BIGINT(20) UNSIGNED NOT NULL,
            action VARCHAR(40) NOT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_idx (user_id),
            KEY file_idx (file_id),
            KEY action_idx (action),
            KEY created_idx (created_at)
        ) {$charset_collate};";

        dbDelta($permissions_sql);
        dbDelta($activity_sql);
    }

    /**
     * Concede capability de gestión a los roles gestores por defecto.
     *
     * @return void
     */
    private static function grant_management_capability()
    {
        $roles = array('administrator', 'admin', 'admin_lab');
        foreach ($roles as $role_key) {
            $role = get_role($role_key);
            if ($role && ! $role->has_cap('shared_docs_manage')) {
                $role->add_cap('shared_docs_manage');
            }
        }
    }
}
