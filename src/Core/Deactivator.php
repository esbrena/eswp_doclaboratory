<?php

namespace SharedDocsManager\Core;

if (! defined('ABSPATH')) {
    exit;
}

class Deactivator
{
    /**
     * Ejecuta lógica de desactivación.
     *
     * @return void
     */
    public static function deactivate()
    {
        wp_clear_scheduled_hook('shared_docs_daily_cleanup');
        flush_rewrite_rules();
    }
}
