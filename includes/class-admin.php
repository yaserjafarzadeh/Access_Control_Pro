<?php
/**
 * Admin Panel Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACP_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        $capability = 'manage_options';

        // Main menu page
        add_menu_page(
            __('Access Control Pro', 'access-control-pro'),
            __('Access Control', 'access-control-pro'),
            $capability,
            'access-control-pro',
            array($this, 'admin_page'),
            'dashicons-shield-alt',
            30
        );

        // Submenu pages
        add_submenu_page(
            'access-control-pro',
            __('Dashboard', 'access-control-pro'),
            __('Dashboard', 'access-control-pro'),
            $capability,
            'access-control-pro',
            array($this, 'admin_page')
        );

        add_submenu_page(
            'access-control-pro',
            __('User Restrictions', 'access-control-pro'),
            __('User Restrictions', 'access-control-pro'),
            $capability,
            'acp-user-restrictions',
            array($this, 'admin_page')
        );

        add_submenu_page(
            'access-control-pro',
            __('Role Restrictions', 'access-control-pro'),
            __('Role Restrictions', 'access-control-pro'),
            $capability,
            'acp-role-restrictions',
            array($this, 'admin_page')
        );

        add_submenu_page(
            'access-control-pro',
            __('Plugin Control', 'access-control-pro'),
            __('Plugin Control', 'access-control-pro'),
            $capability,
            'acp-plugin-control',
            array($this, 'admin_page')
        );

        // Pro features
        if (Access_Control_Pro::instance()->is_pro) {
            add_submenu_page(
                'access-control-pro',
                __('Activity Logs', 'access-control-pro'),
                __('Activity Logs', 'access-control-pro'),
                $capability,
                'acp-logs',
                array($this, 'admin_page')
            );

            add_submenu_page(
                'access-control-pro',
                __('Import/Export', 'access-control-pro'),
                __('Import/Export', 'access-control-pro'),
                $capability,
                'acp-import-export',
                array($this, 'admin_page')
            );
        }

        add_submenu_page(
            'access-control-pro',
            __('Settings', 'access-control-pro'),
            __('Settings', 'access-control-pro'),
            $capability,
            'acp-settings',
            array($this, 'admin_page')
        );
    }

    /**
     * Admin page callback
     */
    public function admin_page() {
        ?>
        <div id="acp-admin-app" class="wrap">
            <div class="acp-loading">
                <div class="acp-spinner"></div>
                <p><?php _e('Loading Access Control Pro...', 'access-control-pro'); ?></p>
            </div>
        </div>

        <style>
        .acp-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 400px;
        }

        .acp-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #2271b1;
            border-radius: 50%;
            animation: acp-spin 1s linear infinite;
        }

        @keyframes acp-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        </style>
        <?php
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our admin pages
        if (strpos($hook, 'access-control-pro') === false && strpos($hook, 'acp-') === false) {
            return;
        }

        // Enqueue React dependencies first
        wp_enqueue_script('react');
        wp_enqueue_script('react-dom');
        wp_enqueue_script('wp-element');
        wp_enqueue_script('wp-api-fetch');
        wp_enqueue_script('wp-components');
        wp_enqueue_script('wp-i18n');
        wp_enqueue_script('wp-icons');

        // Enqueue React app
        wp_enqueue_script(
            'acp-admin-app',
            ACP_ADMIN_URL . 'build/index.js',
            array('react', 'react-dom', 'wp-element', 'wp-api-fetch', 'wp-components', 'wp-i18n', 'wp-icons'),
            ACP_VERSION,
            true
        );

        wp_enqueue_style(
            'acp-admin-style',
            ACP_ADMIN_URL . 'build/index.css',
            array('wp-components'),
            ACP_VERSION
        );

        // Localize script with initial data
        wp_localize_script('acp-admin-app', 'acpAdminData', array(
            'restUrl' => rest_url('acp/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'currentUser' => wp_get_current_user(),
            'isPro' => Access_Control_Pro::instance()->is_pro,
            'pluginUrl' => ACP_PLUGIN_URL,
            'adminUrl' => admin_url(),
            'currentPage' => $_GET['page'] ?? 'access-control-pro',
            'strings' => $this->get_localized_strings()
        ));
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // Get all users
        register_rest_route('acp/v1', '/users', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_users'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Get all roles
        register_rest_route('acp/v1', '/roles', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_roles'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Get all plugins
        register_rest_route('acp/v1', '/plugins', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_plugins'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Get admin menu items
        register_rest_route('acp/v1', '/admin-menu', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_admin_menu'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Get user restrictions
        register_rest_route('acp/v1', '/restrictions/(?P<type>user|role)/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_restrictions'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Save restrictions
        register_rest_route('acp/v1', '/restrictions', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_restrictions'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'type' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return in_array($param, array('user', 'role'));
                    }
                ),
                'restrictions' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_array($param);
                    }
                )
            )
        ));

        // Delete restrictions
        register_rest_route('acp/v1', '/restrictions/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_restrictions'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Get dashboard stats
        register_rest_route('acp/v1', '/dashboard/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_dashboard_stats'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // License activation (Pro)
        if (Access_Control_Pro::instance()->is_pro) {
            register_rest_route('acp/v1', '/license', array(
                'methods' => 'POST',
                'callback' => array($this, 'activate_license'),
                'permission_callback' => array($this, 'check_permissions')
            ));
        }

        // Get all restrictions for REST API
        register_rest_route('acp/v1', '/restrictions', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_all_restrictions'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Get plugin settings for REST API
        register_rest_route('acp/v1', '/settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_settings'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Update plugin settings for REST API
        register_rest_route('acp/v1', '/settings', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_settings'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Get activity logs (Pro feature)
        register_rest_route('acp/v1', '/activity-logs', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_activity_logs'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Export settings and restrictions
        register_rest_route('acp/v1', '/export-data', array(
            'methods' => 'POST',
            'callback' => array($this, 'export_data'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Import settings and restrictions
        register_rest_route('acp/v1', '/import-data', array(
            'methods' => 'POST',
            'callback' => array($this, 'import_data'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Clear activity logs
        register_rest_route('acp/v1', '/clear-logs', array(
            'methods' => 'POST',
            'callback' => array($this, 'clear_logs'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Pro Features - Scheduling endpoints
        if (Access_Control_Pro::instance()->is_pro) {

            // Get scheduled restrictions
            register_rest_route('acp/v1', '/scheduled-restrictions', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_scheduled_restrictions'),
                'permission_callback' => array($this, 'check_permissions')
            ));

            // Create scheduled restriction
            register_rest_route('acp/v1', '/scheduled-restrictions', array(
                'methods' => 'POST',
                'callback' => array($this, 'create_scheduled_restriction'),
                'permission_callback' => array($this, 'check_permissions'),
                'args' => array(
                    'restriction_id' => array(
                        'required' => true,
                        'validate_callback' => function($param) {
                            return is_numeric($param) && $param > 0;
                        }
                    ),
                    'title' => array(
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field'
                    )
                )
            ));

            // Update scheduled restriction
            register_rest_route('acp/v1', '/scheduled-restrictions/(?P<id>\d+)', array(
                'methods' => 'PUT',
                'callback' => array($this, 'update_scheduled_restriction'),
                'permission_callback' => array($this, 'check_permissions')
            ));

            // Delete scheduled restriction
            register_rest_route('acp/v1', '/scheduled-restrictions/(?P<id>\d+)', array(
                'methods' => 'DELETE',
                'callback' => array($this, 'delete_scheduled_restriction'),
                'permission_callback' => array($this, 'check_permissions')
            ));

            // Get schedule statistics
            register_rest_route('acp/v1', '/scheduled-restrictions/stats', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_schedule_stats'),
                'permission_callback' => array($this, 'check_permissions')
            ));

            // Advanced Logging endpoints

            // Get activity logs
            register_rest_route('acp/v1', '/logs/activity', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_activity_logs'),
                'permission_callback' => array($this, 'check_permissions')
            ));

            // Get basic logs
            register_rest_route('acp/v1', '/logs/basic', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_basic_logs'),
                'permission_callback' => array($this, 'check_permissions')
            ));

            // Get log statistics
            register_rest_route('acp/v1', '/logs/statistics', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_log_statistics'),
                'permission_callback' => array($this, 'check_permissions')
            ));

            // Get user sessions
            register_rest_route('acp/v1', '/sessions', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_user_sessions'),
                'permission_callback' => array($this, 'check_permissions')
            ));

            // End user session
            register_rest_route('acp/v1', '/sessions/(?P<session_id>[a-zA-Z0-9\-]+)/end', array(
                'methods' => 'POST',
                'callback' => array($this, 'end_user_session'),
                'permission_callback' => array($this, 'check_permissions')
            ));

            // Export logs
            register_rest_route('acp/v1', '/logs/export', array(
                'methods' => 'POST',
                'callback' => array($this, 'export_logs'),
                'permission_callback' => array($this, 'check_permissions')
            ));

            // Cleanup logs manually
            register_rest_route('acp/v1', '/logs/cleanup', array(
                'methods' => 'POST',
                'callback' => array($this, 'cleanup_logs_manual'),
                'permission_callback' => array($this, 'check_permissions')
            ));
        }
    }

    /**
     * Check REST API permissions
     */
    public function check_permissions($request) {
        // Check basic permissions
        if (!current_user_can('manage_options')) {
            return false;
        }

        // For POST/PUT/DELETE requests, check nonce
        $method = $request->get_method();
        if (in_array($method, array('POST', 'PUT', 'DELETE', 'PATCH'))) {
            $nonce = $request->get_header('X-WP-Nonce');
            if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
                return new WP_Error('invalid_nonce', __('Security check failed', 'access-control-pro'), array('status' => 403));
            }
        }

        return true;
    }

    /**
     * Get users for REST API
     */
    public function get_users($request) {
        $users = get_users(array(
            'fields' => array('ID', 'display_name', 'user_email', 'user_login'),
            'orderby' => 'display_name',
            'order' => 'ASC'
        ));

        $formatted_users = array();
        foreach ($users as $user) {
            $formatted_users[] = array(
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'login' => $user->user_login,
                'roles' => get_userdata($user->ID)->roles
            );
        }

        return rest_ensure_response($formatted_users);
    }

    /**
     * Get roles for REST API
     */
    public function get_roles($request) {
        global $wp_roles;

        $roles = array();
        foreach ($wp_roles->roles as $role_key => $role_data) {
            $roles[] = array(
                'key' => $role_key,
                'name' => $role_data['name'],
                'capabilities' => $role_data['capabilities']
            );
        }

        return rest_ensure_response($roles);
    }

    /**
     * Get plugins for REST API
     */
    public function get_plugins($request) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', array());

        $plugins = array();
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $plugins[] = array(
                'file' => $plugin_file,
                'name' => $plugin_data['Name'],
                'description' => $plugin_data['Description'],
                'version' => $plugin_data['Version'],
                'author' => $plugin_data['Author'],
                'is_active' => in_array($plugin_file, $active_plugins)
            );
        }

        return rest_ensure_response($plugins);
    }

    /**
     * Get admin menu for REST API
     */
    public function get_admin_menu($request) {
        global $menu, $submenu;

        $admin_menu = array();

        foreach ($menu as $menu_item) {
            if (empty($menu_item[0]) || $menu_item[0] === '') {
                continue;
            }

            $menu_data = array(
                'title' => $menu_item[0],
                'capability' => $menu_item[1],
                'slug' => $menu_item[2],
                'icon' => $menu_item[6] ?? '',
                'submenu' => array()
            );

            // Add submenu items
            if (isset($submenu[$menu_item[2]])) {
                foreach ($submenu[$menu_item[2]] as $submenu_item) {
                    $menu_data['submenu'][] = array(
                        'title' => $submenu_item[0],
                        'capability' => $submenu_item[1],
                        'slug' => $submenu_item[2]
                    );
                }
            }

            $admin_menu[] = $menu_data;
        }

        return rest_ensure_response($admin_menu);
    }

    /**
     * Get restrictions for REST API
     */
    public function get_restrictions($request) {
        global $wpdb;

        $type = $request['type'];
        $id = $request['id'];

        $table_name = $wpdb->prefix . 'acp_restrictions';

        if ($type === 'user') {
            $restrictions = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE user_id = %d AND type = 'user'",
                $id
            ), ARRAY_A);
        } else {
            $role_name = $this->get_role_name_by_id($id);
            $restrictions = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE target_value = %s AND type = 'role'",
                $role_name
            ), ARRAY_A);
        }

        if ($restrictions) {
            $restrictions['restrictions'] = maybe_unserialize($restrictions['restrictions']);
        }

        return rest_ensure_response($restrictions ?: array());
    }

    /**
     * Save restrictions via REST API
     */
    public function save_restrictions($request) {
        global $wpdb;

        $data = $request->get_json_params();

        // Validate required fields
        if (!isset($data['type']) || !in_array($data['type'], array('user', 'role'))) {
            return new WP_Error('invalid_type', __('Invalid restriction type', 'access-control-pro'), array('status' => 400));
        }

        if (!isset($data['restrictions']) || !is_array($data['restrictions'])) {
            return new WP_Error('invalid_restrictions', __('Invalid restrictions data', 'access-control-pro'), array('status' => 400));
        }

        // Validate target
        if ($data['type'] === 'user') {
            if (!isset($data['target_id']) || !is_numeric($data['target_id'])) {
                return new WP_Error('invalid_target', __('Invalid user ID', 'access-control-pro'), array('status' => 400));
            }

            // Check if user exists
            $user = get_userdata($data['target_id']);
            if (!$user) {
                return new WP_Error('user_not_found', __('User not found', 'access-control-pro'), array('status' => 404));
            }

            // Prevent restricting super admin if protection is enabled
            if (get_option('acp_protect_super_admin', true) && is_super_admin($data['target_id'])) {
                return new WP_Error('cannot_restrict_super_admin', __('Cannot restrict super admin', 'access-control-pro'), array('status' => 403));
            }
        } else {
            if (!isset($data['target_value']) || empty($data['target_value'])) {
                return new WP_Error('invalid_target', __('Invalid role name', 'access-control-pro'), array('status' => 400));
            }

            // Check if role exists
            global $wp_roles;
            if (!isset($wp_roles->roles[$data['target_value']])) {
                return new WP_Error('role_not_found', __('Role not found', 'access-control-pro'), array('status' => 404));
            }
        }

        // Check plugin restrictions limit for free version
        if (!Access_Control_Pro::instance()->is_pro && isset($data['restrictions']['plugins'])) {
            $plugin_count = count($data['restrictions']['plugins']);
            if ($plugin_count > 5) {
                return new WP_Error('plugin_limit_exceeded', __('Free version is limited to 5 plugin restrictions', 'access-control-pro'), array('status' => 403));
            }
        }

        $table_name = $wpdb->prefix . 'acp_restrictions';

        $restriction_data = array(
            'type' => sanitize_text_field($data['type']),
            'restrictions' => maybe_serialize($data['restrictions']),
            'updated_at' => current_time('mysql')
        );

        if ($data['type'] === 'user') {
            $restriction_data['user_id'] = intval($data['target_id']);
            $restriction_data['target_value'] = null;
        } else {
            $restriction_data['user_id'] = null;
            $restriction_data['target_value'] = sanitize_text_field($data['target_value']);
        }

        // Check if restriction exists
        $existing = null;
        if ($data['type'] === 'user') {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE user_id = %d AND type = 'user'",
                $data['target_id']
            ));
        } else {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE target_value = %s AND type = 'role'",
                $data['target_value']
            ));
        }

        if ($existing) {
            // Update existing
            $result = $wpdb->update(
                $table_name,
                $restriction_data,
                array('id' => $existing->id)
            );
            $restriction_id = $existing->id;
            $action_type = 'restriction_updated';
        } else {
            // Insert new
            $restriction_data['created_at'] = current_time('mysql');
            $result = $wpdb->insert($table_name, $restriction_data);
            $restriction_id = $wpdb->insert_id;
            $action_type = 'restriction_created';
        }

        if ($result === false) {
            return new WP_Error('save_failed', __('Failed to save restrictions', 'access-control-pro'), array('status' => 500));
        }

        // Log activity
        $this->log_activity($action_type, array(
            'restriction_id' => $restriction_id,
            'type' => $data['type'],
            'target_id' => $data['target_id'] ?? null,
            'target_value' => $data['target_value'] ?? null,
            'restrictions_count' => count($data['restrictions'])
        ));

        return rest_ensure_response(array(
            'success' => true,
            'id' => $restriction_id,
            'action' => $action_type
        ));
    }

    /**
     * Delete restrictions via REST API
     */
    public function delete_restrictions($request) {
        global $wpdb;

        $id = intval($request['id']);

        if (!$id) {
            return new WP_Error('invalid_id', __('Invalid restriction ID', 'access-control-pro'), array('status' => 400));
        }

        $table_name = $wpdb->prefix . 'acp_restrictions';

        // Get restriction info before deletion for logging
        $restriction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $id
        ), ARRAY_A);

        if (!$restriction) {
            return new WP_Error('restriction_not_found', __('Restriction not found', 'access-control-pro'), array('status' => 404));
        }

        $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));

        if ($result === false) {
            return new WP_Error('delete_failed', __('Failed to delete restrictions', 'access-control-pro'), array('status' => 500));
        }

        // Log activity
        $this->log_activity('restriction_deleted', array(
            'restriction_id' => $id,
            'type' => $restriction['type'],
            'target_id' => $restriction['user_id'],
            'target_value' => $restriction['target_value']
        ));

        return rest_ensure_response(array(
            'success' => true,
            'deleted_id' => $id
        ));
    }

    /**
     * Get dashboard statistics
     */
    public function get_dashboard_stats($request) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        $stats = array(
            'total_restrictions' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"),
            'user_restrictions' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE type = 'user'"),
            'role_restrictions' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE type = 'role'"),
            'total_users' => count_users()['total_users'],
            'total_plugins' => count(get_plugins()),
            'active_plugins' => count(get_option('active_plugins', array()))
        );

        return rest_ensure_response($stats);
    }

    /**
     * Get all restrictions for REST API
     */
    public function get_all_restrictions($request) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        // Get filters from request
        $type = $request->get_param('type');
        $search = $request->get_param('search');
        $page = intval($request->get_param('page')) ?: 1;
        $per_page = intval($request->get_param('per_page')) ?: 20;
        $offset = ($page - 1) * $per_page;

        $where_conditions = array();
        $params = array();

        if ($type && in_array($type, array('user', 'role'))) {
            $where_conditions[] = "type = %s";
            $params[] = $type;
        }

        if ($search) {
            $where_conditions[] = "(target_value LIKE %s OR user_id IN (SELECT ID FROM {$wpdb->users} WHERE display_name LIKE %s))";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // Get total count
        $total_query = "SELECT COUNT(*) FROM {$table_name} {$where_clause}";
        $total = $wpdb->get_var($wpdb->prepare($total_query, $params));

        // Get restrictions with pagination
        $query = "SELECT * FROM {$table_name} {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $restrictions = $wpdb->get_results($wpdb->prepare($query, array_merge($params, array($per_page, $offset))), ARRAY_A);

        // Format restrictions
        foreach ($restrictions as &$restriction) {
            $restriction['restrictions'] = maybe_unserialize($restriction['restrictions']);

            // Add user/role info
            if ($restriction['type'] === 'user' && $restriction['user_id']) {
                $user = get_userdata($restriction['user_id']);
                $restriction['user_info'] = $user ? array(
                    'name' => $user->display_name,
                    'email' => $user->user_email,
                    'login' => $user->user_login
                ) : null;
            } elseif ($restriction['type'] === 'role') {
                global $wp_roles;
                $role_data = $wp_roles->roles[$restriction['target_value']] ?? null;
                $restriction['role_info'] = $role_data ? array(
                    'name' => $role_data['name'],
                    'key' => $restriction['target_value']
                ) : null;
            }
        }

        return rest_ensure_response(array(
            'restrictions' => $restrictions,
            'total' => intval($total),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page)
        ));
    }

    /**
     * Get plugin settings for REST API
     */
    public function get_settings($request) {
        $settings = array(
            'enable_logging' => get_option('acp_enable_logging', true),
            'log_retention_days' => get_option('acp_log_retention_days', 30),
            'protect_super_admin' => get_option('acp_protect_super_admin', true),
            'enable_time_restrictions' => get_option('acp_enable_time_restrictions', false),
            'default_language' => get_option('acp_default_language', 'en'),
            'max_plugin_restrictions' => Access_Control_Pro::instance()->is_pro ? -1 : 5,
            'is_pro' => Access_Control_Pro::instance()->is_pro,
            'license_status' => get_option('acp_license_status', 'inactive'),
            'license_expires' => get_option('acp_license_expires', '')
        );

        return rest_ensure_response($settings);
    }

    /**
     * Update plugin settings for REST API
     */
    public function update_settings($request) {
        $data = $request->get_json_params();

        // Validate data format
        if (!is_array($data) || empty($data)) {
            return new WP_Error('invalid_data', __('Invalid settings data', 'access-control-pro'), array('status' => 400));
        }

        $allowed_settings = array(
            'acp_enable_logging' => 'boolean',
            'acp_log_retention_days' => 'integer',
            'acp_protect_super_admin' => 'boolean',
            'acp_enable_time_restrictions' => 'boolean',
            'acp_default_language' => 'string'
        );

        $updated = array();
        foreach ($allowed_settings as $setting => $type) {
            $key = str_replace('acp_', '', $setting);
            if (isset($data[$key])) {
                $value = $data[$key];

                // Type validation
                switch ($type) {
                    case 'boolean':
                        $value = (bool) $value;
                        break;
                    case 'integer':
                        $value = intval($value);
                        // Validation for log retention
                        if ($key === 'log_retention_days' && ($value < 1 || $value > 365)) {
                            return new WP_Error('invalid_value', __('Log retention days must be between 1 and 365', 'access-control-pro'), array('status' => 400));
                        }
                        break;
                    case 'string':
                        $value = sanitize_text_field($value);
                        break;
                }

                update_option($setting, $value);
                $updated[$key] = $value;
            }
        }

        // Log activity
        $this->log_activity('settings_updated', array(
            'updated_settings' => $updated,
            'user_id' => get_current_user_id()
        ));

        return rest_ensure_response(array(
            'success' => true,
            'updated' => $updated
        ));
    }

    /**
     * Activate license for Pro version
     */
    public function activate_license($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        $data = $request->get_json_params();
        $license_key = sanitize_text_field($data['license_key'] ?? '');

        if (empty($license_key)) {
            return new WP_Error('empty_license', __('License key is required', 'access-control-pro'), array('status' => 400));
        }

        // Validate license with remote server
        $response = wp_remote_post('https://your-domain.com/api/license/validate', array(
            'body' => array(
                'license_key' => $license_key,
                'domain' => home_url(),
                'product_id' => 'access-control-pro'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return new WP_Error('license_check_failed', __('Could not verify license', 'access-control-pro'), array('status' => 500));
        }

        $body = wp_remote_retrieve_body($response);
        $license_data = json_decode($body, true);

        if (!$license_data || $license_data['status'] !== 'valid') {
            return new WP_Error('invalid_license', __('Invalid license key', 'access-control-pro'), array('status' => 400));
        }

        // Save license data
        update_option('acp_license_key', $license_key);
        update_option('acp_license_status', 'active');
        update_option('acp_license_expires', $license_data['expires']);

        return rest_ensure_response(array(
            'success' => true,
            'status' => 'active',
            'expires' => $license_data['expires']
        ));
    }

    /**
     * Get activity logs (Pro feature)
     */
    public function get_activity_logs($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        $page = intval($request->get_param('page')) ?: 1;
        $per_page = intval($request->get_param('per_page')) ?: 50;
        $offset = ($page - 1) * $per_page;

        // Build filters array
        $filters = array();
        if ($request->get_param('user_id')) {
            $filters['user_id'] = intval($request->get_param('user_id'));
        }
        if ($request->get_param('action')) {
            $filters['action'] = sanitize_text_field($request->get_param('action'));
        }
        if ($request->get_param('object_type')) {
            $filters['object_type'] = sanitize_text_field($request->get_param('object_type'));
        }
        if ($request->get_param('date_from')) {
            $filters['date_from'] = sanitize_text_field($request->get_param('date_from')) . ' 00:00:00';
        }
        if ($request->get_param('date_to')) {
            $filters['date_to'] = sanitize_text_field($request->get_param('date_to')) . ' 23:59:59';
        }

        // Use Database class
        $database = Access_Control_Pro::instance()->database;
        $logs = $database->get_logs($per_page, $offset, $filters);
        $total = $database->count_logs($filters);

        // Format logs
        foreach ($logs as &$log) {
            $log['details'] = maybe_unserialize($log['details']);
        }

        return rest_ensure_response(array(
            'logs' => $logs,
            'total' => intval($total),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page)
        ));
    }

    /**
     * Export settings and restrictions
     */
    public function export_data($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        global $wpdb;
        $restrictions_table = $wpdb->prefix . 'acp_restrictions';

        // Get all restrictions
        $restrictions = $wpdb->get_results("SELECT * FROM {$restrictions_table}", ARRAY_A);

        // Get settings
        $settings = array();
        $option_names = array(
            'acp_enable_logging',
            'acp_log_retention_days',
            'acp_protect_super_admin',
            'acp_enable_time_restrictions',
            'acp_default_language'
        );

        foreach ($option_names as $option) {
            $settings[$option] = get_option($option);
        }

        $export_data = array(
            'version' => ACP_VERSION,
            'export_date' => current_time('Y-m-d H:i:s'),
            'site_url' => home_url(),
            'restrictions' => $restrictions,
            'settings' => $settings
        );

        // Log activity
        $this->log_activity('data_exported', array(
            'user_id' => get_current_user_id(),
            'restrictions_count' => count($restrictions)
        ));

        return rest_ensure_response($export_data);
    }

    /**
     * Import settings and restrictions
     */
    public function import_data($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        $data = $request->get_json_params();

        if (!isset($data['restrictions']) || !isset($data['settings'])) {
            return new WP_Error('invalid_data', __('Invalid import data format', 'access-control-pro'), array('status' => 400));
        }

        global $wpdb;
        $restrictions_table = $wpdb->prefix . 'acp_restrictions';
        $imported_count = 0;
        $skipped_count = 0;

        // Import restrictions
        foreach ($data['restrictions'] as $restriction) {
            // Check if similar restriction exists
            $existing = null;
            if ($restriction['type'] === 'user' && $restriction['user_id']) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM {$restrictions_table} WHERE user_id = %d AND type = 'user'",
                    $restriction['user_id']
                ));
            } elseif ($restriction['type'] === 'role' && $restriction['target_value']) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM {$restrictions_table} WHERE target_value = %s AND type = 'role'",
                    $restriction['target_value']
                ));
            }

            if (!$existing) {
                $insert_data = array(
                    'type' => $restriction['type'],
                    'user_id' => $restriction['user_id'],
                    'target_value' => $restriction['target_value'],
                    'restrictions' => $restriction['restrictions'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                );

                if ($wpdb->insert($restrictions_table, $insert_data)) {
                    $imported_count++;
                }
            } else {
                $skipped_count++;
            }
        }

        // Import settings
        foreach ($data['settings'] as $option => $value) {
            if (strpos($option, 'acp_') === 0) {
                update_option($option, $value);
            }
        }

        // Log activity
        $this->log_activity('data_imported', array(
            'user_id' => get_current_user_id(),
            'imported_count' => $imported_count,
            'skipped_count' => $skipped_count
        ));

        return rest_ensure_response(array(
            'success' => true,
            'imported' => $imported_count,
            'skipped' => $skipped_count
        ));
    }

    /**
     * Clear activity logs
     */
    public function clear_logs($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        $days = intval($request->get_param('days')) ?: 0;
        $database = Access_Control_Pro::instance()->database;

        if ($days > 0) {
            // Clear logs older than X days
            $deleted = $database->clear_old_logs($days);
        } else {
            // Clear all logs (use database class method)
            global $wpdb;
            $deleted = $wpdb->query("TRUNCATE TABLE {$database->logs_table}");
        }

        // Log this action
        $database->log_activity(
            get_current_user_id(),
            'logs_cleared',
            'system',
            null,
            array('days' => $days, 'deleted_count' => $deleted)
        );

        return rest_ensure_response(array(
            'success' => true,
            'deleted' => $deleted
        ));
    }

    /**
     * Log activity (helper method)
     */
    private function log_activity($action_type, $data = array()) {
        if (!get_option('acp_enable_logging', true) || !Access_Control_Pro::instance()->is_pro) {
            return;
        }

        // Use the Database class for consistency
        $database = Access_Control_Pro::instance()->database;
        if ($database) {
            $database->log_activity(
                get_current_user_id(),
                $action_type,
                'restriction',
                $data['restriction_id'] ?? null,
                $data
            );
        }
    }

    /**
     * Handle admin actions
     */
    public function handle_admin_actions() {
        // Handle form submissions, AJAX calls, etc.
        if (isset($_POST['acp_action'])) {
            $action = sanitize_text_field($_POST['acp_action']);

            switch ($action) {
                case 'save_settings':
                    $this->save_settings();
                    break;
                case 'clear_logs':
                    $this->clear_logs();
                    break;
            }
        }
    }

    /**
     * Save plugin settings
     */
    private function save_settings() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'], 'acp_save_settings')) {
            wp_die(__('Security check failed', 'access-control-pro'));
        }

        $settings = array(
            'acp_enable_logging' => isset($_POST['enable_logging']),
            'acp_log_retention_days' => intval($_POST['log_retention_days']),
            'acp_protect_super_admin' => isset($_POST['protect_super_admin']),
            'acp_enable_time_restrictions' => isset($_POST['enable_time_restrictions']),
            'acp_default_language' => sanitize_text_field($_POST['default_language'])
        );

        foreach ($settings as $option => $value) {
            update_option($option, $value);
        }

        wp_redirect(add_query_arg('settings-updated', 'true', admin_url('admin.php?page=acp-settings')));
        exit;
    }

    /**
     * Get localized strings for JavaScript
     */
    private function get_localized_strings() {
        return array(
            'dashboard' => __('Dashboard', 'access-control-pro'),
            'userRestrictions' => __('User Restrictions', 'access-control-pro'),
            'roleRestrictions' => __('Role Restrictions', 'access-control-pro'),
            'pluginControl' => __('Plugin Control', 'access-control-pro'),
            'settings' => __('Settings', 'access-control-pro'),
            'save' => __('Save', 'access-control-pro'),
            'cancel' => __('Cancel', 'access-control-pro'),
            'delete' => __('Delete', 'access-control-pro'),
            'edit' => __('Edit', 'access-control-pro'),
            'loading' => __('Loading...', 'access-control-pro'),
            'noData' => __('No data available', 'access-control-pro'),
            'selectUser' => __('Select User', 'access-control-pro'),
            'selectRole' => __('Select Role', 'access-control-pro'),
            'adminMenu' => __('Admin Menu', 'access-control-pro'),
            'plugins' => __('Plugins', 'access-control-pro'),
            'content' => __('Content', 'access-control-pro'),
            'success' => __('Success', 'access-control-pro'),
            'error' => __('Error', 'access-control-pro'),
            'confirmDelete' => __('Are you sure you want to delete this restriction?', 'access-control-pro')
        );
    }

    /**
     * Get role name by numeric ID (helper function)
     */
    private function get_role_name_by_id($id) {
        global $wp_roles;
        $roles = array_keys($wp_roles->roles);
        return isset($roles[$id]) ? $roles[$id] : '';
    }

    /**
     * Pro Features Callback Functions
     */

    /**
     * Get scheduled restrictions
     */
    public function get_scheduled_restrictions($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        $scheduler = new ACP_Scheduler();

        $args = array(
            'limit' => $request->get_param('limit') ?: 20,
            'offset' => $request->get_param('offset') ?: 0,
            'is_active' => $request->get_param('is_active'),
            'restriction_id' => $request->get_param('restriction_id')
        );

        $schedules = $scheduler->get_scheduled_restrictions($args);

        // Enrich with restriction data
        foreach ($schedules as &$schedule) {
            global $wpdb;
            $restriction = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}acp_restrictions WHERE id = %d",
                $schedule->restriction_id
            ));
            $schedule->restriction = $restriction;
        }

        return rest_ensure_response($schedules);
    }

    /**
     * Create scheduled restriction
     */
    public function create_scheduled_restriction($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        $scheduler = new ACP_Scheduler();
        $data = $request->get_json_params();

        $result = $scheduler->add_scheduled_restriction($data);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success' => true,
            'schedule_id' => $result,
            'message' => __('Scheduled restriction created successfully', 'access-control-pro')
        ));
    }

    /**
     * Update scheduled restriction
     */
    public function update_scheduled_restriction($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        $scheduler = new ACP_Scheduler();
        $schedule_id = $request->get_param('id');
        $data = $request->get_json_params();

        $result = $scheduler->update_scheduled_restriction($schedule_id, $data);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Scheduled restriction updated successfully', 'access-control-pro')
        ));
    }

    /**
     * Delete scheduled restriction
     */
    public function delete_scheduled_restriction($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        $scheduler = new ACP_Scheduler();
        $schedule_id = $request->get_param('id');

        $result = $scheduler->delete_scheduled_restriction($schedule_id);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Scheduled restriction deleted successfully', 'access-control-pro')
        ));
    }

    /**
     * Get schedule statistics
     */
    public function get_schedule_stats($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'acp_scheduled_restrictions';

        $stats = array(
            'total_schedules' => $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'active_schedules' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_active = 1"),
            'inactive_schedules' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_active = 0"),
            'recurring_schedules' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_recurring = 1"),
            'executed_today' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE DATE(last_executed) = %s",
                current_time('Y-m-d')
            ))
        );

        // Recent executions
        $recent_executions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE last_executed IS NOT NULL ORDER BY last_executed DESC LIMIT 5"
        ));

        $stats['recent_executions'] = $recent_executions;

        return rest_ensure_response($stats);
    }

    /**
     * Get activity logs (Pro version)
     */
    public function get_activity_logs($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        $logger = new ACP_Logger();

        $args = array(
            'table' => 'activity',
            'limit' => $request->get_param('limit') ?: 50,
            'offset' => $request->get_param('offset') ?: 0,
            'level' => $request->get_param('level'),
            'category' => $request->get_param('category'),
            'user_id' => $request->get_param('user_id'),
            'date_from' => $request->get_param('date_from'),
            'date_to' => $request->get_param('date_to'),
            'search' => $request->get_param('search')
        );

        $result = $logger->get_logs($args);

        // Enrich logs with user data
        foreach ($result['logs'] as &$log) {
            if ($log->user_id) {
                $user = get_user_by('id', $log->user_id);
                $log->user = $user ? array(
                    'id' => $user->ID,
                    'login' => $user->user_login,
                    'email' => $user->user_email,
                    'display_name' => $user->display_name
                ) : null;
            }
        }

        return rest_ensure_response($result);
    }

    /**
     * Get basic logs
     */
    public function get_basic_logs($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        $logger = new ACP_Logger();

        $args = array(
            'table' => 'basic',
            'limit' => $request->get_param('limit') ?: 50,
            'offset' => $request->get_param('offset') ?: 0,
            'level' => $request->get_param('level'),
            'user_id' => $request->get_param('user_id'),
            'date_from' => $request->get_param('date_from'),
            'date_to' => $request->get_param('date_to'),
            'search' => $request->get_param('search')
        );

        $result = $logger->get_logs($args);

        // Enrich logs with user data
        foreach ($result['logs'] as &$log) {
            if ($log->user_id) {
                $user = get_user_by('id', $log->user_id);
                $log->user = $user ? array(
                    'id' => $user->ID,
                    'login' => $user->user_login,
                    'email' => $user->user_email,
                    'display_name' => $user->display_name
                ) : null;
            }
        }

        return rest_ensure_response($result);
    }

    /**
     * Get log statistics
     */
    public function get_log_statistics($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        $logger = new ACP_Logger();
        $days = $request->get_param('days') ?: 7;

        $stats = $logger->get_log_statistics($days);

        return rest_ensure_response($stats);
    }

    /**
     * Get user sessions
     */
    public function get_user_sessions($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        $logger = new ACP_Logger();
        $user_id = $request->get_param('user_id');

        $sessions = $logger->get_active_sessions($user_id);

        // Enrich with user data
        foreach ($sessions as &$session) {
            $user = get_user_by('id', $session->user_id);
            $session->user = $user ? array(
                'id' => $user->ID,
                'login' => $user->user_login,
                'email' => $user->user_email,
                'display_name' => $user->display_name
            ) : null;
        }

        return rest_ensure_response($sessions);
    }

    /**
     * End user session
     */
    public function end_user_session($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        global $wpdb;
        $session_id = $request->get_param('session_id');
        $sessions_table = $wpdb->prefix . 'acp_user_sessions';

        $result = $wpdb->update(
            $sessions_table,
            array(
                'logout_time' => current_time('mysql'),
                'is_active' => 0
            ),
            array('session_id' => $session_id),
            array('%s', '%d'),
            array('%s')
        );

        if ($result === false) {
            return new WP_Error('session_end_failed', __('Failed to end session', 'access-control-pro'), array('status' => 500));
        }

        // Log the action
        $logger = new ACP_Logger();
        $logger->log_activity(
            ACP_Logger::CATEGORY_ADMIN,
            'session_terminated',
            'session',
            $session_id,
            array(
                'message' => __('User session terminated by admin', 'access-control-pro'),
                'terminated_by' => get_current_user_id()
            )
        );

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Session ended successfully', 'access-control-pro')
        ));
    }

    /**
     * Export logs
     */
    public function export_logs($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        $logger = new ACP_Logger();
        $data = $request->get_json_params();

        $args = array(
            'table' => $data['table'] ?? 'activity',
            'limit' => -1, // Export all
            'offset' => 0,
            'level' => $data['level'] ?? null,
            'category' => $data['category'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
            'search' => $data['search'] ?? null
        );

        $result = $logger->get_logs($args);

        // Convert to CSV format
        $csv_data = array();
        $csv_data[] = array('ID', 'Date', 'User', 'Level', 'Category', 'Action', 'Message', 'IP Address', 'User Agent');

        foreach ($result['logs'] as $log) {
            $user = get_user_by('id', $log->user_id);
            $csv_data[] = array(
                $log->id,
                $log->created_at,
                $user ? $user->user_login : 'Unknown',
                $log->level ?? '',
                $log->category ?? '',
                $log->action ?? '',
                $log->message ?? '',
                $log->ip_address ?? '',
                $log->user_agent ?? ''
            );
        }

        // Generate CSV content
        $csv_content = '';
        foreach ($csv_data as $row) {
            $csv_content .= implode(',', array_map(function($field) {
                return '"' . str_replace('"', '""', $field) . '"';
            }, $row)) . "\n";
        }

        // Create temporary file
        $upload_dir = wp_upload_dir();
        $filename = 'acp_logs_export_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = $upload_dir['path'] . '/' . $filename;

        file_put_contents($filepath, $csv_content);

        $download_url = $upload_dir['url'] . '/' . $filename;

        // Log the export action
        $logger->log_activity(
            ACP_Logger::CATEGORY_ADMIN,
            'logs_exported',
            'export',
            null,
            array(
                'message' => __('Logs exported', 'access-control-pro'),
                'total_records' => count($result['logs']),
                'export_file' => $filename
            )
        );

        return rest_ensure_response(array(
            'success' => true,
            'download_url' => $download_url,
            'filename' => $filename,
            'total_records' => count($result['logs']),
            'message' => __('Logs exported successfully', 'access-control-pro')
        ));
    }

    /**
     * Manual cleanup logs
     */
    public function cleanup_logs_manual($request) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return new WP_Error('not_pro', __('Pro version required', 'access-control-pro'), array('status' => 403));
        }

        $logger = new ACP_Logger();
        $logger->cleanup_old_logs();

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Log cleanup completed successfully', 'access-control-pro')
        ));
    }
}
