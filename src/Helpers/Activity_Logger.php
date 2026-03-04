<?php

namespace SharedDocsManager\Helpers;

if (! defined('ABSPATH')) {
    exit;
}

class Activity_Logger
{
    /**
     * Nombre de tabla.
     *
     * @var string
     */
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'shared_activity_log';
    }

    /**
     * Registra una acción de actividad.
     *
     * @param int    $user_id Usuario.
     * @param int    $file_id Archivo.
     * @param string $action  Acción: upload|download|edit.
     * @param array  $context Contexto opcional.
     *
     * @return bool
     */
    public function log($user_id, $file_id, $action, $context = array())
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table_name,
            array(
                'user_id'    => (int) $user_id,
                'file_id'    => (int) $file_id,
                'action'     => sanitize_key($action),
                'context'    => ! empty($context) ? wp_json_encode($context) : null,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s')
        );

        return (bool) $inserted;
    }
}
