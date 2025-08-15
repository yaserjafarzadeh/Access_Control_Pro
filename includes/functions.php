<?php
/**
 * Helper Functions
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get plugin instance
 */
function acp() {
    return Access_Control_Pro::instance();
}

/**
 * Check if user has restrictions
 */
function acp_user_has_restrictions($user_id = null) {
    if ($user_id === null) {
        $user_id = get_current_user_id();
    }

    if (is_super_admin($user_id)) {
        return false;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'acp_restrictions';

    // Check user-specific restrictions
    $user_restrictions = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d AND type = 'user'",
        $user_id
    ));

    if ($user_restrictions > 0) {
        return true;
    }

    // Check role-based restrictions
    $user = get_userdata($user_id);
    if ($user) {
        foreach ($user->roles as $role) {
            $role_restrictions = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE target_value = %s AND type = 'role'",
                $role
            ));

            if ($role_restrictions > 0) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Check if plugin is restricted for user
 */
function acp_is_plugin_restricted($plugin_file, $user_id = null) {
    return acp()->plugins_manager->is_plugin_restricted($plugin_file, $user_id);
}

/**
 * Check if post is restricted for user
 */
function acp_is_post_restricted($post_id, $user_id = null) {
    if ($user_id === null) {
        $user_id = get_current_user_id();
    }

    if (is_super_admin($user_id)) {
        return false;
    }

    $post = get_post($post_id);
    if (!$post) {
        return false;
    }

    $content_manager = acp()->content_manager;
    return $content_manager && method_exists($content_manager, 'is_post_restricted') ?
           $content_manager->is_post_restricted($post) : false;
}

/**
 * Get user restrictions
 */
function acp_get_user_restrictions($user_id = null) {
    if ($user_id === null) {
        $user_id = get_current_user_id();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'acp_restrictions';

    // Get user-specific restrictions
    $user_restrictions = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE user_id = %d AND type = 'user'",
        $user_id
    ), ARRAY_A);

    // Get role-based restrictions
    $user = get_userdata($user_id);
    $role_restrictions = array();

    if ($user) {
        foreach ($user->roles as $role) {
            $role_data = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE target_value = %s AND type = 'role'",
                $role
            ), ARRAY_A);

            if ($role_data) {
                $role_restrictions[] = maybe_unserialize($role_data['restrictions']);
            }
        }
    }

    // Merge restrictions
    $final_restrictions = array();

    foreach ($role_restrictions as $role_rest) {
        $final_restrictions = array_merge_recursive($final_restrictions, $role_rest);
    }

    if ($user_restrictions) {
        $user_rest = maybe_unserialize($user_restrictions['restrictions']);
        $final_restrictions = array_merge_recursive($final_restrictions, $user_rest);
    }

    return $final_restrictions;
}

/**
 * Log activity
 */
function acp_log_activity($action, $object_type, $object_id = null, $details = null, $user_id = null) {
    if ($user_id === null) {
        $user_id = get_current_user_id();
    }

    $database = new ACP_Database();
    return $database->log_activity($user_id, $action, $object_type, $object_id, $details);
}

/**
 * Check if pro version is active
 */
function acp_is_pro() {
    return acp()->is_pro;
}

/**
 * Get plugin version
 */
function acp_get_version() {
    return ACP_VERSION;
}

/**
 * Check if free version limit is reached
 */
function acp_is_free_limit_reached($type = 'plugins') {
    if (acp_is_pro()) {
        return false;
    }

    $limits = array(
        'plugins' => 5,
        'users' => 10,
        'roles' => 3
    );

    $limit = isset($limits[$type]) ? $limits[$type] : 0;

    global $wpdb;
    $table_name = $wpdb->prefix . 'acp_restrictions';

    switch ($type) {
        case 'plugins':
            $count = $wpdb->get_var(
                "SELECT COUNT(DISTINCT CASE
                    WHEN type = 'user' THEN user_id
                    WHEN type = 'role' THEN target_value
                END) FROM {$table_name}
                WHERE restrictions LIKE '%plugins%'"
            );
            break;

        case 'users':
            $count = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table_name} WHERE type = 'user'"
            );
            break;

        case 'roles':
            $count = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table_name} WHERE type = 'role'"
            );
            break;

        default:
            $count = 0;
    }

    return $count >= $limit;
}

/**
 * Get free version limit message
 */
function acp_get_free_limit_message($type = 'plugins') {
    $messages = array(
        'plugins' => __('Free version allows restricting plugins for up to 5 users/roles. Upgrade to Pro for unlimited restrictions.', 'access-control-pro'),
        'users' => __('Free version allows restricting up to 10 users. Upgrade to Pro for unlimited user restrictions.', 'access-control-pro'),
        'roles' => __('Free version allows restricting up to 3 roles. Upgrade to Pro for unlimited role restrictions.', 'access-control-pro')
    );

    return isset($messages[$type]) ? $messages[$type] : '';
}

/**
 * Sanitize restrictions data
 */
function acp_sanitize_restrictions($restrictions) {
    $sanitized = array();

    if (isset($restrictions['admin_menu']) && is_array($restrictions['admin_menu'])) {
        $sanitized['admin_menu'] = array_map('sanitize_text_field', $restrictions['admin_menu']);
    }

    if (isset($restrictions['plugins']) && is_array($restrictions['plugins'])) {
        $sanitized['plugins'] = array_map('sanitize_text_field', $restrictions['plugins']);
    }

    if (isset($restrictions['content']) && is_array($restrictions['content'])) {
        $content = array();

        if (isset($restrictions['content']['post_types']) && is_array($restrictions['content']['post_types'])) {
            $content['post_types'] = array_map('sanitize_key', $restrictions['content']['post_types']);
        }

        if (isset($restrictions['content']['posts']) && is_array($restrictions['content']['posts'])) {
            $content['posts'] = array_map('intval', $restrictions['content']['posts']);
        }

        if (isset($restrictions['content']['categories']) && is_array($restrictions['content']['categories'])) {
            $content['categories'] = array_map('intval', $restrictions['content']['categories']);
        }

        if (isset($restrictions['content']['tags']) && is_array($restrictions['content']['tags'])) {
            $content['tags'] = array_map('intval', $restrictions['content']['tags']);
        }

        if (isset($restrictions['content']['custom_fields']) && is_array($restrictions['content']['custom_fields'])) {
            $custom_fields = array();
            foreach ($restrictions['content']['custom_fields'] as $key => $value) {
                $custom_fields[sanitize_key($key)] = sanitize_text_field($value);
            }
            $content['custom_fields'] = $custom_fields;
        }

        $sanitized['content'] = $content;
    }

    return $sanitized;
}

/**
 * Get admin menu items
 */
function acp_get_admin_menu_items() {
    global $menu, $submenu;

    $menu_items = array();

    foreach ($menu as $menu_item) {
        if (empty($menu_item[0]) || $menu_item[0] === '') {
            continue;
        }

        $item = array(
            'title' => $menu_item[0],
            'capability' => $menu_item[1],
            'slug' => $menu_item[2],
            'icon' => $menu_item[6] ?? '',
            'submenu' => array()
        );

        // Add submenu items
        if (isset($submenu[$menu_item[2]])) {
            foreach ($submenu[$menu_item[2]] as $submenu_item) {
                $item['submenu'][] = array(
                    'title' => $submenu_item[0],
                    'capability' => $submenu_item[1],
                    'slug' => $submenu_item[2],
                    'full_slug' => $menu_item[2] . '|' . $submenu_item[2]
                );
            }
        }

        $menu_items[] = $item;
    }

    return $menu_items;
}

/**
 * Format file size
 */
function acp_format_file_size($bytes) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');

    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Get time ago string
 */
function acp_time_ago($datetime) {
    $time = time() - strtotime($datetime);

    if ($time < 60) {
        return __('Just now', 'access-control-pro');
    } elseif ($time < 3600) {
        $minutes = floor($time / 60);
        return sprintf(_n('%d minute ago', '%d minutes ago', $minutes, 'access-control-pro'), $minutes);
    } elseif ($time < 86400) {
        $hours = floor($time / 3600);
        return sprintf(_n('%d hour ago', '%d hours ago', $hours, 'access-control-pro'), $hours);
    } elseif ($time < 2592000) {
        $days = floor($time / 86400);
        return sprintf(_n('%d day ago', '%d days ago', $days, 'access-control-pro'), $days);
    } elseif ($time < 31536000) {
        $months = floor($time / 2592000);
        return sprintf(_n('%d month ago', '%d months ago', $months, 'access-control-pro'), $months);
    } else {
        $years = floor($time / 31536000);
        return sprintf(_n('%d year ago', '%d years ago', $years, 'access-control-pro'), $years);
    }
}

/**
 * Check if current user can manage restrictions
 */
function acp_current_user_can_manage() {
    return current_user_can('manage_options') && !acp_user_has_restrictions();
}

/**
 * Get restricted content types
 */
function acp_get_restricted_content_types($user_id = null) {
    $restrictions = acp_get_user_restrictions($user_id);

    $types = array();

    if (isset($restrictions['plugins']) && !empty($restrictions['plugins'])) {
        $types[] = 'plugins';
    }

    if (isset($restrictions['admin_menu']) && !empty($restrictions['admin_menu'])) {
        $types[] = 'admin_menu';
    }

    if (isset($restrictions['content']) && !empty($restrictions['content'])) {
        $types[] = 'content';
    }

    return $types;
}

/**
 * Export restrictions to JSON
 */
function acp_export_restrictions() {
    $database = new ACP_Database();
    return wp_json_encode($database->backup_restrictions(), JSON_PRETTY_PRINT);
}

/**
 * Import restrictions from JSON
 */
function acp_import_restrictions($json_data) {
    $data = json_decode($json_data, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }

    $database = new ACP_Database();
    return $database->restore_restrictions($data);
}

/**
 * Get plugin directory URL
 */
function acp_get_plugin_url() {
    return ACP_PLUGIN_URL;
}

/**
 * Get plugin directory path
 */
function acp_get_plugin_path() {
    return ACP_PLUGIN_DIR;
}

/**
 * Check if debugging is enabled
 */
function acp_is_debug() {
    return defined('WP_DEBUG') && WP_DEBUG;
}

/**
 * Debug log
 */
function acp_debug_log($message, $data = null) {
    if (!acp_is_debug()) {
        return;
    }

    $log_message = '[ACP] ' . $message;

    if ($data !== null) {
        $log_message .= ' | Data: ' . print_r($data, true);
    }

    error_log($log_message);
}
