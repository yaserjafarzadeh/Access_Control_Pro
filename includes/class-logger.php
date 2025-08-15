<?php
/**
 * Logger Class - Pro Feature
 * Advanced Activity Logging for Access Control Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACP_Logger {

    /**
     * Log levels
     */
    const LOG_LEVEL_INFO = 'info';
    const LOG_LEVEL_WARNING = 'warning';
    const LOG_LEVEL_ERROR = 'error';
    const LOG_LEVEL_DEBUG = 'debug';
    const LOG_LEVEL_CRITICAL = 'critical';

    /**
     * Log categories
     */
    const CATEGORY_ACCESS = 'access';
    const CATEGORY_RESTRICTION = 'restriction';
    const CATEGORY_USER = 'user';
    const CATEGORY_PLUGIN = 'plugin';
    const CATEGORY_ADMIN = 'admin';
    const CATEGORY_SYSTEM = 'system';
    const CATEGORY_SECURITY = 'security';
    const CATEGORY_SCHEDULE = 'schedule';

    /**
     * Table names
     */
    private $logs_table;
    private $activity_logs_table;
    private $sessions_table;

    /**
     * Current session ID
     */
    private $session_id;

    /**
     * Log retention days
     */
    private $retention_days;

    /**
     * Performance tracking
     */
    private $start_time;
    private $start_memory;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;

        $this->logs_table = $wpdb->prefix . 'acp_logs';
        $this->activity_logs_table = $wpdb->prefix . 'acp_activity_logs';
        $this->sessions_table = $wpdb->prefix . 'acp_user_sessions';

        $this->retention_days = get_option('acp_log_retention_days', 30);
        $this->session_id = $this->get_or_create_session();

        // Performance tracking
        $this->start_time = microtime(true);
        $this->start_memory = memory_get_usage();

        // Only initialize if Pro version is active
        if (Access_Control_Pro::instance()->is_pro) {
            $this->init_hooks();
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // WordPress hooks for automatic logging
        add_action('wp_login', array($this, 'log_user_login'), 10, 2);
        add_action('wp_logout', array($this, 'log_user_logout'));
        add_action('user_register', array($this, 'log_user_registration'));
        add_action('profile_update', array($this, 'log_profile_update'), 10, 2);
        add_action('set_user_role', array($this, 'log_role_change'), 10, 3);

        // Plugin hooks
        add_action('activated_plugin', array($this, 'log_plugin_activation'));
        add_action('deactivated_plugin', array($this, 'log_plugin_deactivation'));

        // Access Control hooks
        add_action('acp_restriction_created', array($this, 'log_restriction_created'), 10, 2);
        add_action('acp_restriction_updated', array($this, 'log_restriction_updated'), 10, 3);
        add_action('acp_restriction_deleted', array($this, 'log_restriction_deleted'), 10, 2);
        add_action('acp_access_denied', array($this, 'log_access_denied'), 10, 3);

        // Schedule daily log cleanup
        if (!wp_next_scheduled('acp_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'acp_cleanup_logs');
        }
        add_action('acp_cleanup_logs', array($this, 'cleanup_old_logs'));

        // Session tracking
        add_action('init', array($this, 'update_session_activity'));
        add_action('wp_logout', array($this, 'end_session'));
    }

    /**
     * Main logging method
     */
    public function log($message, $level = self::LOG_LEVEL_INFO, $user_id = null, $context = array()) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return false;
        }

        global $wpdb;

        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        $data = array(
            'user_id' => $user_id,
            'level' => sanitize_text_field($level),
            'message' => sanitize_textarea_field($message),
            'context' => wp_json_encode($context),
            'ip_address' => $this->get_user_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'created_at' => current_time('mysql')
        );

        $result = $wpdb->insert(
            $this->logs_table,
            $data,
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $result !== false;
    }

    /**
     * Advanced activity logging
     */
    public function log_activity($category, $action, $object_type = null, $object_id = null, $metadata = array()) {
        if (!Access_Control_Pro::instance()->is_pro) {
            return false;
        }

        global $wpdb;

        $user_id = get_current_user_id();
        $execution_time = microtime(true) - $this->start_time;
        $memory_usage = memory_get_usage() - $this->start_memory;

        $data = array(
            'user_id' => $user_id,
            'session_id' => $this->session_id,
            'level' => self::LOG_LEVEL_INFO,
            'category' => sanitize_text_field($category),
            'action' => sanitize_text_field($action),
            'object_type' => $object_type ? sanitize_text_field($object_type) : null,
            'object_id' => $object_id ? sanitize_text_field($object_id) : null,
            'old_value' => isset($metadata['old_value']) ? wp_json_encode($metadata['old_value']) : null,
            'new_value' => isset($metadata['new_value']) ? wp_json_encode($metadata['new_value']) : null,
            'message' => isset($metadata['message']) ? sanitize_textarea_field($metadata['message']) : null,
            'metadata' => wp_json_encode($metadata),
            'ip_address' => $this->get_user_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'referer' => sanitize_text_field($_SERVER['HTTP_REFERER'] ?? ''),
            'request_uri' => sanitize_text_field($_SERVER['REQUEST_URI'] ?? ''),
            'request_method' => sanitize_text_field($_SERVER['REQUEST_METHOD'] ?? ''),
            'response_code' => http_response_code(),
            'execution_time' => $execution_time,
            'memory_usage' => $memory_usage,
            'created_at' => current_time('mysql')
        );

        $result = $wpdb->insert(
            $this->activity_logs_table,
            $data,
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%d', '%s')
        );

        return $result !== false;
    }

    /**
     * Get user IP address
     */
    private function get_user_ip() {
        // Check for various headers that might contain the real IP
        $ip_headers = array(
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        );

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated IPs (forwarded headers)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    /**
     * Get or create user session
     */
    private function get_or_create_session() {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return '';
        }

        $session_id = wp_get_session_token();

        if (!$session_id) {
            $session_id = wp_generate_uuid4();
        }

        // Create or update session record
        $this->create_or_update_session($user_id, $session_id);

        return $session_id;
    }

    /**
     * Create or update session
     */
    private function create_or_update_session($user_id, $session_id) {
        global $wpdb;

        $existing_session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->sessions_table} WHERE session_id = %s",
            $session_id
        ));

        $session_data = array(
            'user_id' => $user_id,
            'session_id' => $session_id,
            'session_token' => wp_get_session_token(),
            'ip_address' => $this->get_user_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'location' => $this->get_location_from_ip(),
            'device_type' => $this->get_device_type(),
            'browser' => $this->get_browser_info(),
            'platform' => $this->get_platform_info(),
            'is_active' => 1,
            'session_data' => wp_json_encode($_SESSION ?? array()),
            'last_activity' => current_time('mysql')
        );

        if ($existing_session) {
            $wpdb->update(
                $this->sessions_table,
                $session_data,
                array('session_id' => $session_id),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'),
                array('%s')
            );
        } else {
            $session_data['login_time'] = current_time('mysql');
            $wpdb->insert(
                $this->sessions_table,
                $session_data,
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
            );
        }
    }

    /**
     * Get location from IP (simplified)
     */
    private function get_location_from_ip() {
        // This is a simplified version - in production you might want to use a geolocation service
        $ip = $this->get_user_ip();

        // Check if it's a local IP
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'Unknown'; // In production, use a geolocation API
        }

        return 'Local Network';
    }

    /**
     * Get device type from user agent
     */
    private function get_device_type() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (preg_match('/Mobile|Android|iPhone|iPad/', $user_agent)) {
            return 'Mobile';
        } elseif (preg_match('/Tablet|iPad/', $user_agent)) {
            return 'Tablet';
        }

        return 'Desktop';
    }

    /**
     * Get browser info from user agent
     */
    private function get_browser_info() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (preg_match('/Firefox/i', $user_agent)) {
            return 'Firefox';
        } elseif (preg_match('/Chrome/i', $user_agent)) {
            return 'Chrome';
        } elseif (preg_match('/Safari/i', $user_agent)) {
            return 'Safari';
        } elseif (preg_match('/Edge/i', $user_agent)) {
            return 'Edge';
        } elseif (preg_match('/Opera/i', $user_agent)) {
            return 'Opera';
        }

        return 'Unknown';
    }

    /**
     * Get platform info from user agent
     */
    private function get_platform_info() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (preg_match('/Windows/i', $user_agent)) {
            return 'Windows';
        } elseif (preg_match('/Mac/i', $user_agent)) {
            return 'macOS';
        } elseif (preg_match('/Linux/i', $user_agent)) {
            return 'Linux';
        } elseif (preg_match('/Android/i', $user_agent)) {
            return 'Android';
        } elseif (preg_match('/iOS/i', $user_agent)) {
            return 'iOS';
        }

        return 'Unknown';
    }

    /**
     * Convenience methods for different log levels
     */
    public function info($message, $context = array()) {
        return $this->log($message, self::LOG_LEVEL_INFO, null, $context);
    }

    public function warning($message, $context = array()) {
        return $this->log($message, self::LOG_LEVEL_WARNING, null, $context);
    }

    public function error($message, $context = array()) {
        return $this->log($message, self::LOG_LEVEL_ERROR, null, $context);
    }

    public function debug($message, $context = array()) {
        return $this->log($message, self::LOG_LEVEL_DEBUG, null, $context);
    }

    public function critical($message, $context = array()) {
        return $this->log($message, self::LOG_LEVEL_CRITICAL, null, $context);
    }

    /**
     * WordPress event loggers
     */
    public function log_user_login($user_login, $user) {
        $this->log_activity(
            self::CATEGORY_USER,
            'login',
            'user',
            $user->ID,
            array(
                'message' => sprintf(__('User %s logged in', 'access-control-pro'), $user_login),
                'username' => $user_login,
                'user_email' => $user->user_email
            )
        );
    }

    public function log_user_logout() {
        $user = wp_get_current_user();
        $this->log_activity(
            self::CATEGORY_USER,
            'logout',
            'user',
            $user->ID,
            array(
                'message' => sprintf(__('User %s logged out', 'access-control-pro'), $user->user_login),
                'username' => $user->user_login
            )
        );
    }

    public function log_user_registration($user_id) {
        $user = get_user_by('id', $user_id);
        $this->log_activity(
            self::CATEGORY_USER,
            'registration',
            'user',
            $user_id,
            array(
                'message' => sprintf(__('New user %s registered', 'access-control-pro'), $user->user_login),
                'username' => $user->user_login,
                'user_email' => $user->user_email
            )
        );
    }

    public function log_profile_update($user_id, $old_user_data) {
        $user = get_user_by('id', $user_id);
        $this->log_activity(
            self::CATEGORY_USER,
            'profile_update',
            'user',
            $user_id,
            array(
                'message' => sprintf(__('User %s profile updated', 'access-control-pro'), $user->user_login),
                'old_value' => $old_user_data,
                'new_value' => $user
            )
        );
    }

    public function log_role_change($user_id, $role, $old_roles) {
        $user = get_user_by('id', $user_id);
        $this->log_activity(
            self::CATEGORY_USER,
            'role_change',
            'user',
            $user_id,
            array(
                'message' => sprintf(__('User %s role changed from %s to %s', 'access-control-pro'),
                    $user->user_login, implode(', ', $old_roles), $role),
                'old_value' => $old_roles,
                'new_value' => $role
            )
        );
    }

    public function log_plugin_activation($plugin) {
        $this->log_activity(
            self::CATEGORY_PLUGIN,
            'activation',
            'plugin',
            $plugin,
            array(
                'message' => sprintf(__('Plugin %s activated', 'access-control-pro'), $plugin),
                'plugin_file' => $plugin
            )
        );
    }

    public function log_plugin_deactivation($plugin) {
        $this->log_activity(
            self::CATEGORY_PLUGIN,
            'deactivation',
            'plugin',
            $plugin,
            array(
                'message' => sprintf(__('Plugin %s deactivated', 'access-control-pro'), $plugin),
                'plugin_file' => $plugin
            )
        );
    }

    /**
     * Access Control specific loggers
     */
    public function log_restriction_created($restriction_id, $restriction_data) {
        $this->log_activity(
            self::CATEGORY_RESTRICTION,
            'created',
            'restriction',
            $restriction_id,
            array(
                'message' => __('New restriction created', 'access-control-pro'),
                'restriction_data' => $restriction_data
            )
        );
    }

    public function log_restriction_updated($restriction_id, $old_data, $new_data) {
        $this->log_activity(
            self::CATEGORY_RESTRICTION,
            'updated',
            'restriction',
            $restriction_id,
            array(
                'message' => __('Restriction updated', 'access-control-pro'),
                'old_value' => $old_data,
                'new_value' => $new_data
            )
        );
    }

    public function log_restriction_deleted($restriction_id, $restriction_data) {
        $this->log_activity(
            self::CATEGORY_RESTRICTION,
            'deleted',
            'restriction',
            $restriction_id,
            array(
                'message' => __('Restriction deleted', 'access-control-pro'),
                'restriction_data' => $restriction_data
            )
        );
    }

    public function log_access_denied($user_id, $resource_type, $resource_id) {
        $user = get_user_by('id', $user_id);
        $this->log_activity(
            self::CATEGORY_SECURITY,
            'access_denied',
            $resource_type,
            $resource_id,
            array(
                'message' => sprintf(__('Access denied for user %s to %s %s', 'access-control-pro'),
                    $user ? $user->user_login : 'Unknown', $resource_type, $resource_id),
                'username' => $user ? $user->user_login : 'Unknown'
            )
        );
    }

    /**
     * Get logs with filtering and pagination
     */
    public function get_logs($args = array()) {
        global $wpdb;

        $defaults = array(
            'table' => 'activity', // 'basic' or 'activity'
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'level' => null,
            'category' => null,
            'user_id' => null,
            'date_from' => null,
            'date_to' => null,
            'search' => null
        );

        $args = wp_parse_args($args, $defaults);

        $table = ($args['table'] === 'basic') ? $this->logs_table : $this->activity_logs_table;

        $where_clauses = array('1=1');
        $where_values = array();

        if ($args['level']) {
            $where_clauses[] = 'level = %s';
            $where_values[] = $args['level'];
        }

        if ($args['category']) {
            $where_clauses[] = 'category = %s';
            $where_values[] = $args['category'];
        }

        if ($args['user_id']) {
            $where_clauses[] = 'user_id = %d';
            $where_values[] = $args['user_id'];
        }

        if ($args['date_from']) {
            $where_clauses[] = 'created_at >= %s';
            $where_values[] = $args['date_from'] . ' 00:00:00';
        }

        if ($args['date_to']) {
            $where_clauses[] = 'created_at <= %s';
            $where_values[] = $args['date_to'] . ' 23:59:59';
        }

        if ($args['search']) {
            $where_clauses[] = '(message LIKE %s OR action LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        $where_sql = implode(' AND ', $where_clauses);
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);

        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d";
        $where_values[] = $args['limit'];
        $where_values[] = $args['offset'];

        $results = $wpdb->get_results($wpdb->prepare($sql, $where_values));

        // Get total count for pagination
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $count_values = array_slice($where_values, 0, -2); // Remove limit and offset
        $total = $wpdb->get_var($wpdb->prepare($count_sql, $count_values));

        return array(
            'logs' => $results,
            'total' => $total,
            'pagination' => array(
                'current_page' => floor($args['offset'] / $args['limit']) + 1,
                'per_page' => $args['limit'],
                'total_pages' => ceil($total / $args['limit'])
            )
        );
    }

    /**
     * Update session activity
     */
    public function update_session_activity() {
        if (!$this->session_id || !get_current_user_id()) {
            return;
        }

        global $wpdb;

        $wpdb->update(
            $this->sessions_table,
            array('last_activity' => current_time('mysql')),
            array('session_id' => $this->session_id),
            array('%s'),
            array('%s')
        );
    }

    /**
     * End session
     */
    public function end_session() {
        if (!$this->session_id) {
            return;
        }

        global $wpdb;

        $wpdb->update(
            $this->sessions_table,
            array(
                'logout_time' => current_time('mysql'),
                'is_active' => 0
            ),
            array('session_id' => $this->session_id),
            array('%s', '%d'),
            array('%s')
        );
    }

    /**
     * Get active sessions
     */
    public function get_active_sessions($user_id = null) {
        global $wpdb;

        $where_clause = "is_active = 1 AND last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)";
        $where_values = array();

        if ($user_id) {
            $where_clause .= " AND user_id = %d";
            $where_values[] = $user_id;
        }

        $sql = "SELECT * FROM {$this->sessions_table} WHERE {$where_clause} ORDER BY last_activity DESC";

        if (!empty($where_values)) {
            return $wpdb->get_results($wpdb->prepare($sql, $where_values));
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Clean up old logs
     */
    public function cleanup_old_logs() {
        global $wpdb;

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$this->retention_days} days"));

        // Delete old basic logs
        $wpdb->delete(
            $this->logs_table,
            array('created_at <' => $cutoff_date),
            array('%s')
        );

        // Delete old activity logs
        $wpdb->delete(
            $this->activity_logs_table,
            array('created_at <' => $cutoff_date),
            array('%s')
        );

        // Delete old inactive sessions
        $wpdb->delete(
            $this->sessions_table,
            array(
                'is_active' => 0,
                'logout_time <' => $cutoff_date
            ),
            array('%d', '%s')
        );

        $this->info('Log cleanup completed', array(
            'cutoff_date' => $cutoff_date,
            'retention_days' => $this->retention_days
        ));
    }

    /**
     * Get log statistics
     */
    public function get_log_statistics($days = 7) {
        global $wpdb;

        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        // Basic stats
        $stats = array();

        // Total logs by level
        $level_stats = $wpdb->get_results($wpdb->prepare("
            SELECT level, COUNT(*) as count
            FROM {$this->activity_logs_table}
            WHERE created_at >= %s
            GROUP BY level
        ", $start_date));

        $stats['by_level'] = array();
        foreach ($level_stats as $stat) {
            $stats['by_level'][$stat->level] = $stat->count;
        }

        // Total logs by category
        $category_stats = $wpdb->get_results($wpdb->prepare("
            SELECT category, COUNT(*) as count
            FROM {$this->activity_logs_table}
            WHERE created_at >= %s
            GROUP BY category
        ", $start_date));

        $stats['by_category'] = array();
        foreach ($category_stats as $stat) {
            $stats['by_category'][$stat->category] = $stat->count;
        }

        // Daily activity
        $daily_stats = $wpdb->get_results($wpdb->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM {$this->activity_logs_table}
            WHERE created_at >= %s
            GROUP BY DATE(created_at)
            ORDER BY date
        ", $start_date));

        $stats['daily_activity'] = array();
        foreach ($daily_stats as $stat) {
            $stats['daily_activity'][$stat->date] = $stat->count;
        }

        // Top users
        $user_stats = $wpdb->get_results($wpdb->prepare("
            SELECT user_id, COUNT(*) as count
            FROM {$this->activity_logs_table}
            WHERE created_at >= %s
            GROUP BY user_id
            ORDER BY count DESC
            LIMIT 10
        ", $start_date));

        $stats['top_users'] = array();
        foreach ($user_stats as $stat) {
            $user = get_user_by('id', $stat->user_id);
            $stats['top_users'][] = array(
                'user_id' => $stat->user_id,
                'username' => $user ? $user->user_login : 'Unknown',
                'count' => $stat->count
            );
        }

        return $stats;
    }
}
