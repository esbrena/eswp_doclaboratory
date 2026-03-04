<?php

namespace SharedDocsManager\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Autoloader PSR-4 simple para clases del plugin.
 */
class Autoloader
{
    /**
     * Namespace base.
     *
     * @var string
     */
    private static $prefix = 'SharedDocsManager\\';

    /**
     * Directorio base.
     *
     * @var string
     */
    private static $base_dir = '';

    /**
     * Registra el autoloader.
     *
     * @return void
     */
    public static function register()
    {
        self::$base_dir = SHARED_DOCS_PATH . 'src/';
        spl_autoload_register(array(__CLASS__, 'autoload'));
    }

    /**
     * Carga la clase solicitada.
     *
     * @param string $class Nombre completo de la clase.
     *
     * @return void
     */
    public static function autoload($class)
    {
        $len = strlen(self::$prefix);
        if (strncmp(self::$prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = self::$base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    }
}
