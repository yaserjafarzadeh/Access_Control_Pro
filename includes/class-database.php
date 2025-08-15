<?php
/**
 * Database Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACP_Database {

    /**
     * Table names
     */
    public $restrictions_table;
    public $logs_table;
    public $scheduled_restrictions_table;
    public $activity_logs_table;
    public $sessions_table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;

        $this->restrictions_table = $wpdb->prefix . 'acp_restrictions';
        $this->logs_table = $wpdb->prefix . 'acp_logs';
        $this->scheduled_restrictions_table = $wpdb->prefix . 'acp_scheduled_restrictions';
        $this->activity_logs_table = $wpdb->prefix . 'acp_activity_logs';
        $this->sessions_table = $wpdb->prefix . 'acp_user_sessions';
    }

    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Restrictions table
        $restrictions_sql = "CREATE TABLE {$this->restrictions_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL DEFAULT 'user',
            user_id bigint(20) NULL,
            target_value varchar(255) NULL,
            restrictions longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type_user_id (type, user_id),
            KEY target_value (target_value),
            KEY created_at (created_at),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        // Enhanced Logs table
        $logs_sql = "CREATE TABLE {$this->logs_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            action varchar(255) NOT NULL,
            object_type varchar(100) NOT NULL,
            object_id varchar(255) NULL,
            ip_address varchar(45) NULL,
            user_agent text NULL,
            details longtext NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY action (action),
            KEY object_type (object_type),
            KEY created_at (created_at),
            KEY ip_address (ip_address)
        ) {$charset_collate};";

        // Scheduled Restrictions table (Pro Feature)
        $scheduled_restrictions_sql = "CREATE TABLE {$this->scheduled_restrictions_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            restriction_id bigint(20) NOT NULL,
            title varchar(255) NOT NULL,
            action_type varchar(20) NOT NULL DEFAULT 'activate',
            start_date date NULL,
            end_date date NULL,
            start_time time DEFAULT '00:00:00',
            end_time time DEFAULT '23:59:59',
            days_of_week varchar(20) DEFAULT '',
            timezone varchar(50) DEFAULT 'UTC',
            is_active tinyint(1) DEFAULT 1,
            is_recurring tinyint(1) DEFAULT 0,
            recurring_pattern varchar(50) DEFAULT '',
            max_executions int(11) DEFAULT 0,
            current_executions int(11) DEFAULT 0,
            last_executed datetime NULL,
            created_by bigint(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY restriction_id (restriction_id),
            KEY action_type (action_type),
            KEY start_date (start_date),
            KEY end_date (end_date),
            KEY is_active (is_active),
            KEY created_by (created_by),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // Activity Logs table (Pro Feature)
        $activity_logs_sql = "CREATE TABLE {$this->activity_logs_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            session_id varchar(255) NULL,
            level varchar(20) DEFAULT 'info',
            category varchar(50) NOT NULL,
            action varchar(100) NOT NULL,
            object_type varchar(50) NULL,
            object_id varchar(255) NULL,
            old_value longtext NULL,
            new_value longtext NULL,
            message text NULL,
            metadata longtext NULL,
            ip_address varchar(45) NULL,
            user_agent text NULL,
            referer text NULL,
            request_uri text NULL,
            request_method varchar(10) NULL,
            response_code int(11) NULL,
            execution_time float NULL,
            memory_usage bigint(20) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY level (level),
            KEY category (category),
            KEY action (action),
            KEY object_type (object_type),
            KEY ip_address (ip_address),
            KEY created_at (created_at),
            KEY user_date (user_id, created_at),
            KEY category_action (category, action)
        ) {$charset_collate};";

        // User Sessions table (Pro Feature)
        $sessions_sql = "CREATE TABLE {$this->sessions_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            session_id varchar(255) NOT NULL,
            session_token varchar(255) NOT NULL,
            login_time datetime DEFAULT CURRENT_TIMESTAMP,
            last_activity datetime DEFAULT CURRENT_TIMESTAMP,
            logout_time datetime NULL,
            ip_address varchar(45) NULL,
            user_agent text NULL,
            location varchar(255) NULL,
            device_type varchar(50) NULL,
            browser varchar(100) NULL,
            platform varchar(100) NULL,
            is_active tinyint(1) DEFAULT 1,
            session_data longtext NULL,
            restrictions_applied longtext NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id),
            KEY user_id (user_id),
            KEY session_token (session_token),
            KEY login_time (login_time),
            KEY last_activity (last_activity),
            KEY is_active (is_active),
            KEY user_active (user_id, is_active)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($restrictions_sql);
        dbDelta($logs_sql);

        // Pro tables - only create if Pro version is active
        if (Access_Control_Pro::instance()->is_pro) {
            dbDelta($scheduled_restrictions_sql);
            dbDelta($activity_logs_sql);
            dbDelta($sessions_sql);
        }

        // Update version
        update_option('acp_db_version', ACP_VERSION);

        // Create indexes for better performance
        $this->create_indexes();
    }

    /**
     * Create additional indexes for performance
     */
    private function create_indexes() {
        global $wpdb;

        // Only if Pro version is active
        if (!Access_Control_Pro::instance()->is_pro) {
            return;
        }

        // Activity logs performance indexes
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_activity_logs_performance ON {$this->activity_logs_table} (user_id, category, created_at)");
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_activity_logs_search ON {$this->activity_logs_table} (action, object_type, created_at)");

        // Sessions performance indexes
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_sessions_activity ON {$this->sessions_table} (user_id, last_activity, is_active)");

        // Scheduled restrictions performance indexes
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_scheduled_active ON {$this->scheduled_restrictions_table} (is_active, start_date, end_date)");
    }

    /**
     * Check if tables exist
     */
    public function tables_exist() {
        global $wpdb;

        $restrictions_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->restrictions_table}'") === $this->restrictions_table;
        $logs_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->logs_table}'") === $this->logs_table;

        $basic_tables_exist = $restrictions_exists && $logs_exists;

        // Check Pro tables if Pro version is active
        if (Access_Control_Pro::instance()->is_pro) {
            $scheduled_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->scheduled_restrictions_table}'") === $this->scheduled_restrictions_table;
            $activity_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->activity_logs_table}'") === $this->activity_logs_table;
            $sessions_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->sessions_table}'") === $this->sessions_table;

            return $basic_tables_exist && $scheduled_exists && $activity_exists && $sessions_exists;
        }

        return $basic_tables_exist;
    }

    /**
     * Get all restrictions
     */
    public function get_restrictions($type = null, $limit = 50, $offset = 0) {
        global $wpdb;

        $where = '';
        if ($type) {
            $where = $wpdb->prepare("WHERE type = %s", $type);
        }

        $sql = "SELECT * FROM {$this->restrictions_table} {$where}
                ORDER BY updated_at DESC
                LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($sql, $limit, $offset), ARRAY_A);
    }

    /**
     * Get restriction by ID
     */
    public function get_restriction($id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->restrictions_table} WHERE id = %d",
            $id
        ), ARRAY_A);
    }

    /**
     * Get user restrictions
     */
    public function get_user_restrictions($user_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->restrictions_table} WHERE user_id = %d AND type = 'user'",
            $user_id
        ), ARRAY_A);
    }

    /**
     * Get role restrictions
     */
    public function get_role_restrictions($role_name) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->restrictions_table} WHERE target_value = %s AND type = 'role'",
            $role_name
        ), ARRAY_A);
    }

    /**
     * Save restriction
     */
    public function save_restriction($data) {
        global $wpdb;

        $data['updated_at'] = current_time('mysql');

        if (isset($data['id']) && $data['id']) {
            $id = $data['id'];
            unset($data['id']);

            return $wpdb->update(
                $this->restrictions_table,
                $data,
                array('id' => $id)
            );
        } else {
            $data['created_at'] = current_time('mysql');

            return $wpdb->insert($this->restrictions_table, $data);
        }
    }

    /**
     * Delete restriction
     */
    public function delete_restriction($id) {
        global $wpdb;

        return $wpdb->delete(
            $this->restrictions_table,
            array('id' => $id),
            array('%d')
        );
    }

    /**
     * Count restrictions
     */
    public function count_restrictions($type = null) {
        global $wpdb;

        $where = '';
        if ($type) {
            $where = $wpdb->prepare("WHERE type = %s", $type);
        }

        return $wpdb->get_var("SELECT COUNT(*) FROM {$this->restrictions_table} {$where}");
    }

    /**
     * Log activity
     */
    public function log_activity($user_id, $action, $object_type, $object_id = null, $details = null) {
        global $wpdb;

        // Only log if Pro version and logging is enabled
        if (!Access_Control_Pro::instance()->is_pro || !get_option('acp_enable_logging', false)) {
            return;
        }

        $data = array(
            'user_id' => $user_id,
            'action' => $action,
            'object_type' => $object_type,
            'object_id' => $object_id,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'details' => is_array($details) ? maybe_serialize($details) : $details,
            'created_at' => current_time('mysql')
        );

        return $wpdb->insert($this->logs_table, $data);
    }

    /**
     * Get logs
     */
    public function get_logs($limit = 50, $offset = 0, $filters = array()) {
        global $wpdb;

        $where_conditions = array();
        $where_values = array();

        if (!empty($filters['user_id'])) {
            $where_conditions[] = "user_id = %d";
            $where_values[] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $where_conditions[] = "action = %s";
            $where_values[] = $filters['action'];
        }

        if (!empty($filters['object_type'])) {
            $where_conditions[] = "object_type = %s";
            $where_values[] = $filters['object_type'];
        }

        if (!empty($filters['date_from'])) {
            $where_conditions[] = "created_at >= %s";
            $where_values[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where_conditions[] = "created_at <= %s";
            $where_values[] = $filters['date_to'];
        }

        $where_sql = '';
        if (!empty($where_conditions)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        $sql = "SELECT l.*, u.display_name as user_name
                FROM {$this->logs_table} l
                LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID
                {$where_sql}
                ORDER BY l.created_at DESC
                LIMIT %d OFFSET %d";

        $where_values[] = $limit;
        $where_values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $where_values), ARRAY_A);
    }

    /**
     * Count logs
     */
    public function count_logs($filters = array()) {
        global $wpdb;

        $where_conditions = array();
        $where_values = array();

        if (!empty($filters['user_id'])) {
            $where_conditions[] = "user_id = %d";
            $where_values[] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $where_conditions[] = "action = %s";
            $where_values[] = $filters['action'];
        }

        if (!empty($filters['object_type'])) {
            $where_conditions[] = "object_type = %s";
            $where_values[] = $filters['object_type'];
        }

        if (!empty($filters['date_from'])) {
            $where_conditions[] = "created_at >= %s";
            $where_values[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where_conditions[] = "created_at <= %s";
            $where_values[] = $filters['date_to'];
        }

        $where_sql = '';
        if (!empty($where_conditions)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        $sql = "SELECT COUNT(*) FROM {$this->logs_table} {$where_sql}";

        if (!empty($where_values)) {
            return $wpdb->get_var($wpdb->prepare($sql, $where_values));
        } else {
            return $wpdb->get_var($sql);
        }
    }

    /**
     * Clear old logs
     */
    public function clear_old_logs($days = 30) {
        global $wpdb;

        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->logs_table} WHERE created_at < %s",
            $date_threshold
        ));
    }

    /**
     * Get database statistics
     */
    public function get_statistics() {
        global $wpdb;

        return array(
            'total_restrictions' => $this->count_restrictions(),
            'user_restrictions' => $this->count_restrictions('user'),
            'role_restrictions' => $this->count_restrictions('role'),
            'total_logs' => $this->count_logs(),
            'logs_today' => $this->count_logs(array(
                'date_from' => date('Y-m-d 00:00:00'),
                'date_to' => date('Y-m-d 23:59:59')
            )),
            'logs_this_week' => $this->count_logs(array(
                'date_from' => date('Y-m-d 00:00:00', strtotime('monday this week')),
                'date_to' => date('Y-m-d 23:59:59', strtotime('sunday this week'))
            )),
            'database_size' => $this->get_database_size()
        );
    }

    /**
     * Get database size
     */
    private function get_database_size() {
        global $wpdb;

        $size_query = $wpdb->prepare(
            "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'size_mb'
             FROM information_schema.tables
             WHERE table_schema = %s
             AND table_name IN (%s, %s)",
            DB_NAME,
            $this->restrictions_table,
            $this->logs_table
        );

        $result = $wpdb->get_var($size_query);

        return $result ? $result . ' MB' : 'Unknown';
    }

    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');

        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);

                    if (filter_var($ip, FILTER_VALIDATE_IP,
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Backup restrictions data
     */
    public function backup_restrictions() {
        global $wpdb;

        $restrictions = $wpdb->get_results(
            "SELECT * FROM {$this->restrictions_table} ORDER BY id",
            ARRAY_A
        );

        return array(
            'version' => ACP_VERSION,
            'timestamp' => current_time('mysql'),
            'restrictions' => $restrictions
        );
    }

    /**
     * Restore restrictions from backup
     */
    public function restore_restrictions($backup_data) {
        global $wpdb;

        if (!isset($backup_data['restrictions']) || !is_array($backup_data['restrictions'])) {
            return false;
        }

        // Clear existing restrictions
        $wpdb->query("TRUNCATE TABLE {$this->restrictions_table}");

        // Insert backup data
        foreach ($backup_data['restrictions'] as $restriction) {
            unset($restriction['id']); // Let MySQL auto-increment
            $wpdb->insert($this->restrictions_table, $restriction);
        }

        return true;
    }

    /**
     * Optimize database tables
     */
    public function optimize_tables() {
        global $wpdb;

        $wpdb->query("OPTIMIZE TABLE {$this->restrictions_table}");
        $wpdb->query("OPTIMIZE TABLE {$this->logs_table}");

        return true;
    }
}
