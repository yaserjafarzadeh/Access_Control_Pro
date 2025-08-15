<?php
/**
 * Roles Manager Class
 * Handles role-based access control and restrictions
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACP_Roles_Manager {

    /**
     * Current user roles
     */
    private $current_user_roles = array();

    /**
     * Role restrictions cache
     */
    private $role_restrictions_cache = array();

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
        // Load current user roles
        if (is_user_logged_in()) {
            $this->load_current_user_roles();
        }

        // Hook into role capabilities
        add_filter('user_has_cap', array($this, 'filter_user_capabilities'), 10, 4);
        add_filter('map_meta_cap', array($this, 'map_meta_capabilities'), 10, 4);
    }

    /**
     * Admin initialization
     */
    public function admin_init() {
        // Apply role-based menu restrictions
        add_action('admin_menu', array($this, 'apply_role_menu_restrictions'), 999);

        // Block access to role management for restricted users
        add_action('admin_init', array($this, 'block_role_management_access'));

        // Filter admin bar based on role restrictions
        add_action('wp_before_admin_bar_render', array($this, 'filter_admin_bar'));
    }

    /**
     * Load current user roles
     */
    private function load_current_user_roles() {
        $current_user = wp_get_current_user();

        if ($current_user && !is_super_admin($current_user->ID)) {
            $this->current_user_roles = $current_user->roles;
            $this->load_role_restrictions();
        }
    }

    /**
     * Load role restrictions from database
     */
    private function load_role_restrictions() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        foreach ($this->current_user_roles as $role) {
            if (!isset($this->role_restrictions_cache[$role])) {
                $restrictions = $wpdb->get_row($wpdb->prepare(
                    "SELECT restrictions FROM {$table_name} WHERE target_value = %s AND type = 'role'",
                    $role
                ));

                if ($restrictions) {
                    $this->role_restrictions_cache[$role] = maybe_unserialize($restrictions->restrictions);
                } else {
                    $this->role_restrictions_cache[$role] = array();
                }
            }
        }
    }

    /**
     * Apply role-based restrictions
     */
    public function apply_restrictions($restrictions, $role_name) {
        // Apply menu restrictions
        if (isset($restrictions['admin_menu'])) {
            $this->apply_menu_restrictions($restrictions['admin_menu']);
        }

        // Apply capability restrictions
        if (isset($restrictions['capabilities'])) {
            $this->apply_capability_restrictions($restrictions['capabilities'], $role_name);
        }

        // Apply content restrictions
        if (isset($restrictions['content'])) {
            $this->apply_content_restrictions($restrictions['content'], $role_name);
        }

        // Log the application of restrictions
        if (class_exists('ACP_Logger')) {
            $logger = new ACP_Logger();
            $logger->info(sprintf(
                __('Role restrictions applied for role: %s', 'access-control-pro'),
                $role_name
            ), array('role' => $role_name, 'restrictions' => $restrictions));
        }
    }

    /**
     * Apply menu restrictions
     */
    private function apply_menu_restrictions($menu_restrictions) {
        foreach ($menu_restrictions as $menu_slug) {
            // Remove main menu pages
            remove_menu_page($menu_slug);

            // Handle submenu items (format: parent|child)
            if (strpos($menu_slug, '|') !== false) {
                $parts = explode('|', $menu_slug);
                if (count($parts) === 2) {
                    remove_submenu_page($parts[0], $parts[1]);
                }
            }
        }
    }

    /**
     * Apply capability restrictions
     */
    private function apply_capability_restrictions($capability_restrictions, $role_name) {
        $role = get_role($role_name);

        if (!$role) {
            return;
        }

        foreach ($capability_restrictions as $capability => $allowed) {
            if (!$allowed && $role->has_cap($capability)) {
                // Temporarily remove capability for this session
                add_filter('user_has_cap', function($allcaps, $caps, $args, $user) use ($capability) {
                    if (in_array($capability, $caps)) {
                        $allcaps[$capability] = false;
                    }
                    return $allcaps;
                }, 10, 4);
            }
        }
    }

    /**
     * Apply content restrictions
     */
    private function apply_content_restrictions($content_restrictions, $role_name) {
        // Restrict access to certain post types
        if (isset($content_restrictions['post_types'])) {
            foreach ($content_restrictions['post_types'] as $post_type) {
                $this->restrict_post_type_access($post_type, $role_name);
            }
        }

        // Restrict access to certain taxonomies
        if (isset($content_restrictions['taxonomies'])) {
            foreach ($content_restrictions['taxonomies'] as $taxonomy) {
                $this->restrict_taxonomy_access($taxonomy, $role_name);
            }
        }
    }

    /**
     * Restrict post type access
     */
    private function restrict_post_type_access($post_type, $role_name) {
        // Remove post type from admin menu
        add_action('admin_menu', function() use ($post_type) {
            remove_menu_page('edit.php?post_type=' . $post_type);
        });

        // Block direct access to post type edit pages
        add_action('admin_init', function() use ($post_type) {
            global $pagenow;

            if (in_array($pagenow, array('edit.php', 'post-new.php', 'post.php'))) {
                $current_post_type = $_GET['post_type'] ?? 'post';

                if ($current_post_type === $post_type) {
                    wp_die(
                        __('Access denied. You do not have permission to manage this content type.', 'access-control-pro'),
                        __('Access Denied', 'access-control-pro'),
                        array('response' => 403)
                    );
                }
            }
        });
    }

    /**
     * Restrict taxonomy access
     */
    private function restrict_taxonomy_access($taxonomy, $role_name) {
        // Remove taxonomy from admin menu
        add_action('admin_menu', function() use ($taxonomy) {
            remove_submenu_page('edit.php', 'edit-tags.php?taxonomy=' . $taxonomy);
        });

        // Block direct access to taxonomy pages
        add_action('admin_init', function() use ($taxonomy) {
            global $pagenow;

            if ($pagenow === 'edit-tags.php') {
                $current_taxonomy = $_GET['taxonomy'] ?? '';

                if ($current_taxonomy === $taxonomy) {
                    wp_die(
                        __('Access denied. You do not have permission to manage this taxonomy.', 'access-control-pro'),
                        __('Access Denied', 'access-control-pro'),
                        array('response' => 403)
                    );
                }
            }
        });
    }

    /**
     * Apply role menu restrictions
     */
    public function apply_role_menu_restrictions() {
        if (empty($this->role_restrictions_cache)) {
            return;
        }

        foreach ($this->role_restrictions_cache as $role => $restrictions) {
            if (isset($restrictions['admin_menu'])) {
                $this->apply_menu_restrictions($restrictions['admin_menu']);
            }
        }
    }

    /**
     * Filter user capabilities based on role restrictions
     */
    public function filter_user_capabilities($allcaps, $caps, $args, $user) {
        if (is_super_admin($user->ID)) {
            return $allcaps; // Super admin bypass
        }

        // Check if user has any restricted roles
        foreach ($user->roles as $role) {
            if (isset($this->role_restrictions_cache[$role]['capabilities'])) {
                $capability_restrictions = $this->role_restrictions_cache[$role]['capabilities'];

                foreach ($capability_restrictions as $capability => $allowed) {
                    if (!$allowed && isset($allcaps[$capability])) {
                        $allcaps[$capability] = false;
                    }
                }
            }
        }

        return $allcaps;
    }

    /**
     * Map meta capabilities
     */
    public function map_meta_capabilities($caps, $cap, $user_id, $args) {
        if (is_super_admin($user_id)) {
            return $caps;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return $caps;
        }

        // Check role-based meta capability restrictions
        foreach ($user->roles as $role) {
            if (isset($this->role_restrictions_cache[$role]['meta_capabilities'])) {
                $meta_restrictions = $this->role_restrictions_cache[$role]['meta_capabilities'];

                if (isset($meta_restrictions[$cap]) && !$meta_restrictions[$cap]) {
                    $caps[] = 'do_not_allow';
                }
            }
        }

        return $caps;
    }

    /**
     * Block role management access
     */
    public function block_role_management_access() {
        global $pagenow;

        if ($pagenow !== 'users.php') {
            return;
        }

        $current_user = wp_get_current_user();

        if (is_super_admin($current_user->ID)) {
            return;
        }

        // Check if user's role is restricted from managing roles
        foreach ($current_user->roles as $role) {
            if (isset($this->role_restrictions_cache[$role]['role_management'])) {
                $role_management = $this->role_restrictions_cache[$role]['role_management'];

                if (!$role_management['can_edit_users'] && isset($_GET['action']) && in_array($_GET['action'], array('edit', 'update'))) {
                    wp_die(
                        __('Access denied. You do not have permission to edit users.', 'access-control-pro'),
                        __('Access Denied', 'access-control-pro'),
                        array('response' => 403)
                    );
                }

                if (!$role_management['can_delete_users'] && isset($_GET['action']) && $_GET['action'] === 'delete') {
                    wp_die(
                        __('Access denied. You do not have permission to delete users.', 'access-control-pro'),
                        __('Access Denied', 'access-control-pro'),
                        array('response' => 403)
                    );
                }
            }
        }
    }

    /**
     * Filter admin bar based on role restrictions
     */
    public function filter_admin_bar() {
        global $wp_admin_bar;

        if (!$wp_admin_bar) {
            return;
        }

        $current_user = wp_get_current_user();

        if (is_super_admin($current_user->ID)) {
            return;
        }

        // Remove admin bar items based on role restrictions
        foreach ($current_user->roles as $role) {
            if (isset($this->role_restrictions_cache[$role]['admin_bar'])) {
                $admin_bar_restrictions = $this->role_restrictions_cache[$role]['admin_bar'];

                foreach ($admin_bar_restrictions as $node_id) {
                    $wp_admin_bar->remove_node($node_id);
                }
            }
        }
    }

    /**
     * Get all WordPress roles
     */
    public function get_all_roles() {
        global $wp_roles;

        $roles = array();

        foreach ($wp_roles->roles as $role_key => $role_data) {
            $role_count = count_users();
            $user_count = isset($role_count['avail_roles'][$role_key]) ? $role_count['avail_roles'][$role_key] : 0;

            $roles[] = array(
                'key' => $role_key,
                'name' => $role_data['name'],
                'capabilities' => $role_data['capabilities'],
                'user_count' => $user_count,
                'is_restricted' => $this->is_role_restricted($role_key)
            );
        }

        return $roles;
    }

    /**
     * Check if role is restricted
     */
    public function is_role_restricted($role_key) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        $restriction = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE target_value = %s AND type = 'role'",
            $role_key
        ));

        return $restriction > 0;
    }

    /**
     * Get role restrictions
     */
    public function get_role_restrictions($role_key) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        $restrictions = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE target_value = %s AND type = 'role'",
            $role_key
        ), ARRAY_A);

        if ($restrictions) {
            $restrictions['restrictions'] = maybe_unserialize($restrictions['restrictions']);
            return $restrictions;
        }

        return null;
    }

    /**
     * Save role restrictions
     */
    public function save_role_restrictions($role_key, $restrictions_data) {
        global $wpdb;

        // Validate role exists
        global $wp_roles;
        if (!isset($wp_roles->roles[$role_key])) {
            return new WP_Error('invalid_role', __('Role does not exist', 'access-control-pro'));
        }

        $table_name = $wpdb->prefix . 'acp_restrictions';

        // Check if restriction already exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE target_value = %s AND type = 'role'",
            $role_key
        ));

        $data = array(
            'type' => 'role',
            'user_id' => null,
            'target_value' => $role_key,
            'restrictions' => maybe_serialize($restrictions_data),
            'updated_at' => current_time('mysql')
        );

        if ($existing) {
            // Update existing restriction
            $result = $wpdb->update(
                $table_name,
                $data,
                array('id' => $existing->id),
                array('%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );
            $restriction_id = $existing->id;
        } else {
            // Create new restriction
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert(
                $table_name,
                $data,
                array('%s', '%s', '%s', '%s', '%s', '%s')
            );
            $restriction_id = $wpdb->insert_id;
        }

        if ($result === false) {
            return new WP_Error('save_failed', __('Failed to save role restrictions', 'access-control-pro'));
        }

        // Clear cache
        unset($this->role_restrictions_cache[$role_key]);

        // Log the action
        if (class_exists('ACP_Logger')) {
            $logger = new ACP_Logger();
            $logger->info(sprintf(
                __('Role restrictions saved for role: %s', 'access-control-pro'),
                $role_key
            ), array('role' => $role_key, 'restriction_id' => $restriction_id));
        }

        return $restriction_id;
    }

    /**
     * Delete role restrictions
     */
    public function delete_role_restrictions($role_key) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        $result = $wpdb->delete(
            $table_name,
            array(
                'target_value' => $role_key,
                'type' => 'role'
            ),
            array('%s', '%s')
        );

        if ($result === false) {
            return new WP_Error('delete_failed', __('Failed to delete role restrictions', 'access-control-pro'));
        }

        // Clear cache
        unset($this->role_restrictions_cache[$role_key]);

        // Log the action
        if (class_exists('ACP_Logger')) {
            $logger = new ACP_Logger();
            $logger->info(sprintf(
                __('Role restrictions deleted for role: %s', 'access-control-pro'),
                $role_key
            ), array('role' => $role_key));
        }

        return true;
    }

    /**
     * Get role restriction statistics
     */
    public function get_restriction_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        $stats = array(
            'total_role_restrictions' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table_name} WHERE type = 'role'"
            ),
            'most_restricted_capabilities' => $this->get_most_restricted_capabilities(),
            'roles_with_restrictions' => $this->get_roles_with_restrictions(),
            'restriction_coverage' => $this->get_restriction_coverage()
        );

        return $stats;
    }

    /**
     * Get most restricted capabilities
     */
    private function get_most_restricted_capabilities() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        $restrictions = $wpdb->get_results(
            "SELECT restrictions FROM {$table_name} WHERE type = 'role'",
            ARRAY_A
        );

        $capability_counts = array();

        foreach ($restrictions as $restriction) {
            $data = maybe_unserialize($restriction['restrictions']);
            if (isset($data['capabilities']) && is_array($data['capabilities'])) {
                foreach ($data['capabilities'] as $capability => $allowed) {
                    if (!$allowed) {
                        $capability_counts[$capability] = ($capability_counts[$capability] ?? 0) + 1;
                    }
                }
            }
        }

        arsort($capability_counts);
        return array_slice($capability_counts, 0, 10, true);
    }

    /**
     * Get roles with restrictions
     */
    private function get_roles_with_restrictions() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acp_restrictions';

        return $wpdb->get_results(
            "SELECT target_value as role_key, created_at, updated_at
             FROM {$table_name}
             WHERE type = 'role'
             ORDER BY updated_at DESC",
            ARRAY_A
        );
    }

    /**
     * Get restriction coverage percentage
     */
    private function get_restriction_coverage() {
        global $wp_roles;

        $total_roles = count($wp_roles->roles);
        $restricted_roles = count($this->get_roles_with_restrictions());

        return $total_roles > 0 ? round(($restricted_roles / $total_roles) * 100, 2) : 0;
    }

    /**
     * Check if current user can manage role
     */
    public function can_user_manage_role($role_key, $user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if (is_super_admin($user_id)) {
            return true;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        // Check if user has capability to manage roles
        if (!user_can($user_id, 'edit_users')) {
            return false;
        }

        // Check role-based restrictions
        foreach ($user->roles as $user_role) {
            if (isset($this->role_restrictions_cache[$user_role]['role_management'])) {
                $restrictions = $this->role_restrictions_cache[$user_role]['role_management'];

                if (isset($restrictions['manageable_roles']) &&
                    is_array($restrictions['manageable_roles']) &&
                    !in_array($role_key, $restrictions['manageable_roles'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get manageable roles for user
     */
    public function get_manageable_roles($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if (is_super_admin($user_id)) {
            return $this->get_all_roles();
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return array();
        }

        $manageable_roles = array();
        $all_roles = $this->get_all_roles();

        foreach ($all_roles as $role) {
            if ($this->can_user_manage_role($role['key'], $user_id)) {
                $manageable_roles[] = $role;
            }
        }

        return $manageable_roles;
    }
}
