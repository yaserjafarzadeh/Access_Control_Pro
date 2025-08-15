<?php
/**
 * Plugins Manager Class
 * Handles plugin access control and restrictions
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACP_Plugins_Manager {

    /**
     * Restricted plugins for current user
     */
    private $restricted_plugins = array();

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'admin_init'));
    }

    /**
     * Initialize
     */
    public function init() {
        // Get current user restrictions
        if (is_user_logged_in()) {
            $this->load_user_plugin_restrictions();
        }

        // Hook into plugin loading
        add_action('plugins_loaded', array($this, 'apply_plugin_restrictions'), 1);
        add_filter('all_plugins', array($this, 'filter_plugins_list'));
    }

    /**
     * Admin initialization
     */
    public function admin_init() {
        // Remove plugin menu items
        add_action('admin_menu', array($this, 'remove_plugin_menus'), 999);

        // Block plugin settings access
        add_action('admin_init', array($this, 'block_plugin_settings_access'));

        // Filter plugin action links
        add_filter('plugin_action_links', array($this, 'filter_plugin_action_links'), 10, 2);

        // Hide plugins from plugins page
        add_filter('all_plugins', array($this, 'hide_restricted_plugins'));

        // Block direct plugin file access
        add_action('admin_init', array($this, 'block_direct_plugin_access'));
    }

    /**
     * Load user plugin restrictions
     */
    private function load_user_plugin_restrictions() {
        $user_id = get_current_user_id();

        if (is_super_admin($user_id)) {
            return; // Super admin has no restrictions
        }

        $restrictions = $this->get_user_plugin_restrictions($user_id);
        $this->restricted_plugins = $restrictions;
    }

    /**
     * Get user plugin restrictions
     */
    private function get_user_plugin_restrictions($user_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';
        $restrictions = array();

        // Get user-specific restrictions
        $user_restrictions = $wpdb->get_row($wpdb->prepare(
            "SELECT restrictions FROM {$table_name} WHERE user_id = %d AND type = 'user'",
            $user_id
        ));

        if ($user_restrictions) {
            $user_data = maybe_unserialize($user_restrictions->restrictions);
            if (isset($user_data['plugins'])) {
                $restrictions = array_merge($restrictions, $user_data['plugins']);
            }
        }

        // Get role-based restrictions
        $user = get_userdata($user_id);
        if ($user && !empty($user->roles)) {
            foreach ($user->roles as $role) {
                $role_restrictions = $wpdb->get_row($wpdb->prepare(
                    "SELECT restrictions FROM {$table_name} WHERE target_value = %s AND type = 'role'",
                    $role
                ));

                if ($role_restrictions) {
                    $role_data = maybe_unserialize($role_restrictions->restrictions);
                    if (isset($role_data['plugins'])) {
                        $restrictions = array_merge($restrictions, $role_data['plugins']);
                    }
                }
            }
        }

        return array_unique($restrictions);
    }

    /**
     * Apply plugin restrictions
     */
    public function apply_restrictions($restricted_plugins, $user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if (is_super_admin($user_id)) {
            return; // Super admin bypass
        }

        $this->restricted_plugins = array_merge($this->restricted_plugins, $restricted_plugins);

        // Log access attempt if plugin is restricted
        foreach ($restricted_plugins as $plugin) {
            if ($this->is_accessing_restricted_plugin($plugin)) {
                $this->log_plugin_access_attempt($plugin, $user_id);
            }
        }
    }

    /**
     * Apply plugin restrictions during loading
     */
    public function apply_plugin_restrictions() {
        if (empty($this->restricted_plugins)) {
            return;
        }

        // Block restricted plugins from being loaded
        foreach ($this->restricted_plugins as $plugin_file) {
            $this->block_plugin_functionality($plugin_file);
        }
    }

    /**
     * Filter plugins list in admin
     */
    public function filter_plugins_list($plugins) {
        if (empty($this->restricted_plugins)) {
            return $plugins;
        }

        foreach ($this->restricted_plugins as $plugin_file) {
            if (isset($plugins[$plugin_file])) {
                // Mark as restricted instead of removing
                $plugins[$plugin_file]['Description'] .= ' <span style="color: red;">' . __('(Access Restricted)', 'access-control-pro') . '</span>';
            }
        }

        return $plugins;
    }

    /**
     * Hide restricted plugins completely
     */
    public function hide_restricted_plugins($plugins) {
        if (empty($this->restricted_plugins)) {
            return $plugins;
        }

        // Get setting for hiding vs showing restricted
        $hide_completely = get_option('acp_hide_restricted_plugins', false);

        if ($hide_completely) {
            foreach ($this->restricted_plugins as $plugin_file) {
                unset($plugins[$plugin_file]);
            }
        }

        return $plugins;
    }

    /**
     * Remove plugin menu items
     */
    public function remove_plugin_menus() {
        if (empty($this->restricted_plugins)) {
            return;
        }

        foreach ($this->restricted_plugins as $plugin_file) {
            $this->remove_plugin_menu_items($plugin_file);
        }
    }

    /**
     * Remove specific plugin menu items
     */
    private function remove_plugin_menu_items($plugin_file) {
        // Get plugin menu slugs
        $plugin_menus = $this->get_plugin_menu_slugs($plugin_file);

        foreach ($plugin_menus as $menu_slug) {
            remove_menu_page($menu_slug);

            // Also remove from Tools, Settings, etc.
            remove_submenu_page('tools.php', $menu_slug);
            remove_submenu_page('options-general.php', $menu_slug);
            remove_submenu_page('themes.php', $menu_slug);
        }
    }

    /**
     * Get plugin menu slugs
     */
    private function get_plugin_menu_slugs($plugin_file) {
        // This would typically be configurable or detected
        // For now, return common patterns
        $plugin_name = basename($plugin_file, '.php');

        return array(
            $plugin_name,
            str_replace('-', '_', $plugin_name),
            $plugin_name . '-settings',
            $plugin_name . '-admin'
        );
    }

    /**
     * Block plugin settings access
     */
    public function block_plugin_settings_access() {
        if (empty($this->restricted_plugins)) {
            return;
        }

        $current_page = $_GET['page'] ?? '';

        foreach ($this->restricted_plugins as $plugin_file) {
            $plugin_pages = $this->get_plugin_menu_slugs($plugin_file);

            if (in_array($current_page, $plugin_pages)) {
                wp_die(
                    __('Access denied. You do not have permission to access this plugin.', 'access-control-pro'),
                    __('Access Denied', 'access-control-pro'),
                    array('response' => 403)
                );
            }
        }
    }

    /**
     * Filter plugin action links
     */
    public function filter_plugin_action_links($links, $plugin_file) {
        if (in_array($plugin_file, $this->restricted_plugins)) {
            // Remove settings, activate, deactivate links
            unset($links['activate']);
            unset($links['deactivate']);
            unset($links['edit']);

            // Add restriction notice
            $links['restricted'] = '<span style="color: red;">' . __('Restricted', 'access-control-pro') . '</span>';
        }

        return $links;
    }

    /**
     * Block direct plugin access
     */
    public function block_direct_plugin_access() {
        if (empty($this->restricted_plugins)) {
            return;
        }

        // Check if trying to activate/deactivate restricted plugin
        $action = $_GET['action'] ?? '';
        $plugin = $_GET['plugin'] ?? '';

        if (in_array($action, array('activate', 'deactivate')) && in_array($plugin, $this->restricted_plugins)) {
            wp_die(
                __('Access denied. You cannot modify this plugin.', 'access-control-pro'),
                __('Access Denied', 'access-control-pro'),
                array('response' => 403)
            );
        }
    }

    /**
     * Block plugin functionality
     */
    private function block_plugin_functionality($plugin_file) {
        // This is a more advanced feature - blocking plugin hooks, filters etc.
        // Would need to be implemented based on specific plugin structure

        // For now, we can block common plugin entry points
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;

        if (file_exists($plugin_path)) {
            // Add filter to block plugin initialization
            add_filter('pre_option_active_plugins', function($active_plugins) use ($plugin_file) {
                if (is_array($active_plugins)) {
                    $key = array_search($plugin_file, $active_plugins);
                    if ($key !== false) {
                        unset($active_plugins[$key]);
                    }
                }
                return $active_plugins;
            });
        }
    }

    /**
     * Check if user is accessing restricted plugin
     */
    private function is_accessing_restricted_plugin($plugin_file) {
        $current_page = $_GET['page'] ?? '';
        $plugin_pages = $this->get_plugin_menu_slugs($plugin_file);

        return in_array($current_page, $plugin_pages);
    }

    /**
     * Log plugin access attempt
     */
    private function log_plugin_access_attempt($plugin_file, $user_id) {
        if (class_exists('ACP_Logger')) {
            $logger = new ACP_Logger();
            $logger->log_restriction_attempt('plugin', $plugin_file, $user_id);
        }
    }

    /**
     * Get list of all plugins
     */
    public function get_all_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        $active_plugins = get_option('active_plugins', array());

        $formatted_plugins = array();

        foreach ($plugins as $plugin_file => $plugin_data) {
            $formatted_plugins[] = array(
                'file' => $plugin_file,
                'name' => $plugin_data['Name'],
                'description' => $plugin_data['Description'],
                'version' => $plugin_data['Version'],
                'author' => $plugin_data['Author'],
                'is_active' => in_array($plugin_file, $active_plugins),
                'is_restricted' => in_array($plugin_file, $this->restricted_plugins)
            );
        }

        return $formatted_plugins;
    }

    /**
     * Check if plugin is restricted for user
     */
    public function is_plugin_restricted($plugin_file, $user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if (is_super_admin($user_id)) {
            return false;
        }

        $restrictions = $this->get_user_plugin_restrictions($user_id);
        return in_array($plugin_file, $restrictions);
    }

    /**
     * Get plugin restriction statistics
     */
    public function get_restriction_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        // Count total plugin restrictions
        $total_restrictions = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE restrictions LIKE '%plugins%'"
        );

        // Count users with plugin restrictions
        $users_with_restrictions = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE type = 'user' AND restrictions LIKE '%plugins%'"
        );

        // Count roles with plugin restrictions
        $roles_with_restrictions = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE type = 'role' AND restrictions LIKE '%plugins%'"
        );

        return array(
            'total_restrictions' => $total_restrictions,
            'users_with_restrictions' => $users_with_restrictions,
            'roles_with_restrictions' => $roles_with_restrictions,
            'most_restricted_plugins' => $this->get_most_restricted_plugins()
        );
    }

    /**
     * Get most restricted plugins
     */
    private function get_most_restricted_plugins() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        $restrictions = $wpdb->get_results(
            "SELECT restrictions FROM {$table_name} WHERE restrictions LIKE '%plugins%'",
            ARRAY_A
        );

        $plugin_counts = array();

        foreach ($restrictions as $restriction) {
            $data = maybe_unserialize($restriction['restrictions']);
            if (isset($data['plugins']) && is_array($data['plugins'])) {
                foreach ($data['plugins'] as $plugin) {
                    $plugin_counts[$plugin] = ($plugin_counts[$plugin] ?? 0) + 1;
                }
            }
        }

        arsort($plugin_counts);
        return array_slice($plugin_counts, 0, 10, true);
    }
}
