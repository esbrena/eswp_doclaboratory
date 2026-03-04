<?php

namespace SharedDocsManager\Core;

use SharedDocsManager\Admin\Admin_Controller;
use SharedDocsManager\API\Rest_Controller;
use SharedDocsManager\Front\Front_Controller;
use SharedDocsManager\Helpers\Activity_Logger;
use SharedDocsManager\Helpers\File_Helper;
use SharedDocsManager\Permissions\File_Permission_Repository;
use SharedDocsManager\Permissions\Permission_Manager;
use SharedDocsManager\Permissions\Permission_Repository;

if (! defined('ABSPATH')) {
    exit;
}

class Plugin
{
    /**
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * @var bool
     */
    private $booted = false;

    /**
     * @var Permission_Repository
     */
    private $permission_repository;

    /**
     * @var File_Permission_Repository
     */
    private $file_permission_repository;

    /**
     * @var Permission_Manager
     */
    private $permission_manager;

    /**
     * @var Activity_Logger
     */
    private $activity_logger;

    /**
     * @var File_Helper
     */
    private $file_helper;

    /**
     * @var Post_Types
     */
    private $post_types;

    /**
     * @var Admin_Controller
     */
    private $admin_controller;

    /**
     * @var Front_Controller
     */
    private $front_controller;

    /**
     * @var Rest_Controller
     */
    private $rest_controller;

    /**
     * @var Shortcode_Manager
     */
    private $shortcode_manager;

    /**
     * Singleton.
     *
     * @return Plugin
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Arranca el plugin.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->booted) {
            return;
        }

        load_plugin_textdomain('shared-docs-manager', false, dirname(plugin_basename(SHARED_DOCS_FILE)) . '/languages');

        $this->maybe_upgrade_database();

        $this->permission_repository = new Permission_Repository();
        $this->file_permission_repository = new File_Permission_Repository();
        $this->permission_manager = new Permission_Manager($this->permission_repository, $this->file_permission_repository);
        $this->activity_logger = new Activity_Logger();
        $this->file_helper = new File_Helper($this->activity_logger);

        $this->post_types = new Post_Types();
        $this->admin_controller = new Admin_Controller(
            $this->permission_manager,
            $this->permission_repository,
            $this->file_permission_repository,
            $this->file_helper
        );
        $this->front_controller = new Front_Controller($this->permission_manager);
        $this->rest_controller = new Rest_Controller(
            $this->permission_manager,
            $this->activity_logger,
            $this->file_helper
        );
        $this->shortcode_manager = new Shortcode_Manager($this->permission_manager, $this->front_controller);

        $this->post_types->register_hooks();
        $this->admin_controller->register_hooks();
        $this->front_controller->register_hooks();
        $this->rest_controller->register_hooks();
        $this->shortcode_manager->register_hooks();

        add_action('shared_docs_daily_cleanup', array($this, 'cleanup_expired_permissions'));
        add_action('init', array($this, 'ensure_schedule'));

        $this->booted = true;
    }

    /**
     * Devuelve manager de permisos.
     *
     * @return Permission_Manager
     */
    public function permission_manager()
    {
        return $this->permission_manager;
    }

    /**
     * Garantiza la programación del cron diario.
     *
     * @return void
     */
    public function ensure_schedule()
    {
        if (! wp_next_scheduled('shared_docs_daily_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'shared_docs_daily_cleanup');
        }
    }

    /**
     * Limpia permisos expirados.
     *
     * @return void
     */
    public function cleanup_expired_permissions()
    {
        $this->permission_repository->delete_expired_permissions();
        $this->file_permission_repository->delete_expired_permissions();
    }

    /**
     * Ejecuta migraciones de esquema si procede.
     *
     * @return void
     */
    private function maybe_upgrade_database()
    {
        $current = get_option('shared_docs_db_version', '1.0.0');
        if (version_compare($current, Activator::DB_VERSION, '>=')) {
            return;
        }

        Activator::maybe_create_tables();
        update_option('shared_docs_db_version', Activator::DB_VERSION);
    }
}
