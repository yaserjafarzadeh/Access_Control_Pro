<?php
/**
 * Main Access Control Pro Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Access_Control_Pro {

    /**
     * Plugin instance
     */
    protected static $_instance = null;

    /**
     * Plugin components
     */
    public $database;
    public $admin;
    public $roles_manager;
    public $plugins_manager;
    public $content_manager;
    public $updater;

    /**
     * Plugin version
     */
    public $version = ACP_VERSION;

    /**
     * Is Pro version active
     */
    public $is_pro = false;

    /**
     * Main instance
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
        $this->check_pro_license();
    }

    /**
     * Define plugin constants
     */
    private function define_constants() {
        define('ACP_ABSPATH', dirname(ACP_PLUGIN_FILE) . '/');
        define('ACP_INCLUDES_DIR', ACP_ABSPATH . 'includes/');
        define('ACP_ADMIN_DIR', ACP_ABSPATH . 'admin/');
        define('ACP_ASSETS_URL', ACP_PLUGIN_URL . 'assets/');
        define('ACP_ADMIN_URL', ACP_PLUGIN_URL . 'admin/');
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        require_once ACP_INCLUDES_DIR . 'class-admin.php';
        require_once ACP_INCLUDES_DIR . 'class-roles-manager.php';
        require_once ACP_INCLUDES_DIR . 'class-plugins-manager.php';
        require_once ACP_INCLUDES_DIR . 'class-content-manager.php';
        require_once ACP_INCLUDES_DIR . 'class-database.php';

        // Pro features
        if ($this->is_pro) {
            require_once ACP_INCLUDES_DIR . 'class-logger.php';
            require_once ACP_INCLUDES_DIR . 'class-scheduler.php';
            require_once ACP_INCLUDES_DIR . 'class-exporter.php';
        }

        // Updater
        require_once ACP_INCLUDES_DIR . 'class-updater.php';

        // Helper functions
        require_once ACP_INCLUDES_DIR . 'functions.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'), 0);
        add_action('admin_init', array($this, 'admin_init'));
        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Initialize database
        $this->database = new ACP_Database();

        // Initialize components
        $this->admin = new ACP_Admin();
        $this->roles_manager = new ACP_Roles_Manager();
        $this->plugins_manager = new ACP_Plugins_Manager();
        $this->content_manager = new ACP_Content_Manager();
        $this->updater = new ACP_Updater();

        // Fire init action
        do_action('acp_init');
    }

    /**
     * Admin initialization
     */
    public function admin_init() {
        // Check capabilities and apply restrictions
        if (is_admin() && !is_ajax()) {
            $this->apply_admin_restrictions();
        }
    }

    /**
     * Load text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'access-control-pro',
            false,
            dirname(plugin_basename(ACP_PLUGIN_FILE)) . '/languages/'
        );
    }

    /**
     * Check pro license status
     */
    private function check_pro_license() {
        $license_key = get_option('acp_license_key', '');
        $license_status = get_option('acp_license_status', 'invalid');

        if (!empty($license_key) && $license_status === 'valid') {
            $this->is_pro = true;
        }
    }

    /**
     * Apply admin restrictions based on current user
     */
    private function apply_admin_restrictions() {
        $current_user = wp_get_current_user();

        // Skip for super admins (safety measure)
        if (is_super_admin($current_user->ID)) {
            return;
        }

        // Apply menu restrictions
        $this->apply_menu_restrictions($current_user);

        // Apply plugin restrictions
        $this->apply_plugin_restrictions($current_user);

        // Apply content restrictions
        $this->apply_content_restrictions($current_user);
    }

    /**
     * Apply menu restrictions
     */
    private function apply_menu_restrictions($user) {
        $restrictions = $this->get_user_restrictions($user->ID);

        if (!empty($restrictions['admin_menu'])) {
            add_action('admin_menu', function() use ($restrictions) {
                $this->remove_restricted_menus($restrictions['admin_menu']);
            }, 999);
        }
    }

    /**
     * Apply plugin restrictions
     */
    private function apply_plugin_restrictions($user) {
        $restrictions = $this->get_user_restrictions($user->ID);

        if (!empty($restrictions['plugins'])) {
            $this->plugins_manager->apply_restrictions($restrictions['plugins'], $user->ID);
        }
    }

    /**
     * Apply content restrictions
     */
    private function apply_content_restrictions($user) {
        $restrictions = $this->get_user_restrictions($user->ID);

        if (!empty($restrictions['content'])) {
            $this->content_manager->apply_restrictions($restrictions['content'], $user->ID);
        }
    }

    /**
     * Get user restrictions
     */
    private function get_user_restrictions($user_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        // Get user-specific restrictions
        $user_restrictions = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE user_id = %d AND type = 'user'",
            $user_id
        ), ARRAY_A);

        // Get role-based restrictions
        $user = get_userdata($user_id);
        $user_roles = $user->roles;

        $role_restrictions = array();
        foreach ($user_roles as $role) {
            $role_data = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE target_value = %s AND type = 'role'",
                $role
            ), ARRAY_A);

            if ($role_data) {
                $role_restrictions[] = maybe_unserialize($role_data['restrictions']);
            }
        }

        // Merge restrictions (user-specific overrides role-based)
        $final_restrictions = array();

        if (!empty($role_restrictions)) {
            foreach ($role_restrictions as $role_rest) {
                $final_restrictions = array_merge_recursive($final_restrictions, $role_rest);
            }
        }

        if ($user_restrictions) {
            $user_rest = maybe_unserialize($user_restrictions['restrictions']);
            $final_restrictions = array_merge_recursive($final_restrictions, $user_rest);
        }

        return $final_restrictions;
    }

    /**
     * Remove restricted menu items
     */
    private function remove_restricted_menus($restricted_menus) {
        global $menu, $submenu;

        foreach ($restricted_menus as $menu_slug) {
            // Remove main menu items
            remove_menu_page($menu_slug);

            // Remove submenu items
            if (strpos($menu_slug, '|') !== false) {
                $parts = explode('|', $menu_slug);
                if (count($parts) === 2) {
                    remove_submenu_page($parts[0], $parts[1]);
                }
            }
        }
    }

    /**
     * Plugin activation
     */
    public static function activate() {
        // Create database tables
        self::create_tables();

        // Set default options
        self::set_default_options();

        // Create upload directory
        self::create_upload_directory();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear any scheduled events
        wp_clear_scheduled_hook('acp_daily_cleanup');

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Restrictions table
        $table_name = $wpdb->prefix . 'acp_restrictions';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL DEFAULT 'user',
            user_id bigint(20) NULL,
            target_value varchar(255) NULL,
            restrictions longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type_user_id (type, user_id),
            KEY target_value (target_value)
        ) {$charset_collate};";

        // Logs table (Pro version)
        $logs_table = $wpdb->prefix . 'acp_logs';

        $logs_sql = "CREATE TABLE {$logs_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            action varchar(255) NOT NULL,
            object_type varchar(100) NOT NULL,
            object_id varchar(255) NULL,
            ip_address varchar(45) NULL,
            user_agent text NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY action (action),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($logs_sql);
    }

    /**
     * Set default options
     */
    private static function set_default_options() {
        $default_options = array(
            'acp_version' => ACP_VERSION,
            'acp_enable_logging' => false,
            'acp_log_retention_days' => 30,
            'acp_protect_super_admin' => true,
            'acp_enable_time_restrictions' => false,
            'acp_default_language' => 'en_US'
        );

        foreach ($default_options as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
        }
    }

    /**
     * Create upload directory
     */
    private static function create_upload_directory() {
        $upload_dir = wp_upload_dir();
        $acp_dir = $upload_dir['basedir'] . '/access-control-pro';

        if (!file_exists($acp_dir)) {
            wp_mkdir_p($acp_dir);

            // Create .htaccess for security
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            file_put_contents($acp_dir . '/.htaccess', $htaccess_content);
        }
    }

    /**
     * Get plugin instance
     */
    public static function get_instance() {
        return self::instance();
    }
}
