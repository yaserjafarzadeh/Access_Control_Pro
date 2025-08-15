<?php
/**
 * Exporter Class - Pro Feature
 * Import/Export Settings and Restrictions
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACP_Exporter {

    /**
     * Export data types
     */
    const EXPORT_RESTRICTIONS = 'restrictions';
    const EXPORT_SETTINGS = 'settings';
    const EXPORT_LOGS = 'logs';
    const EXPORT_ALL = 'all';

    /**
     * Export restrictions and settings
     */
    public function export_data($types = array(self::EXPORT_ALL), $args = array()) {
        $export_data = array(
            'plugin_version' => ACP_VERSION,
            'export_date' => current_time('mysql'),
            'site_url' => get_site_url(),
            'data' => array()
        );

        if (in_array(self::EXPORT_ALL, $types) || in_array(self::EXPORT_RESTRICTIONS, $types)) {
            $export_data['data']['restrictions'] = $this->export_restrictions($args);
        }

        if (in_array(self::EXPORT_ALL, $types) || in_array(self::EXPORT_SETTINGS, $types)) {
            $export_data['data']['settings'] = $this->export_settings($args);
        }

        if (in_array(self::EXPORT_ALL, $types) || in_array(self::EXPORT_LOGS, $types)) {
            $export_data['data']['logs'] = $this->export_logs($args);
        }

        return $export_data;
    }

    /**
     * Export restrictions
     */
    private function export_restrictions($args = array()) {
        global $wpdb;

        $defaults = array(
            'include_inactive' => false,
            'user_ids' => array(),
            'restriction_types' => array()
        );

        $args = wp_parse_args($args, $defaults);

        $restrictions_table = $wpdb->prefix . 'acp_restrictions';
        $where_conditions = array('1=1');
        $where_values = array();

        // Filter by active status
        if (!$args['include_inactive']) {
            $where_conditions[] = 'is_active = 1';
        }

        // Filter by user IDs
        if (!empty($args['user_ids'])) {
            $placeholders = implode(',', array_fill(0, count($args['user_ids']), '%d'));
            $where_conditions[] = "user_id IN ($placeholders)";
            $where_values = array_merge($where_values, $args['user_ids']);
        }

        // Filter by restriction types
        if (!empty($args['restriction_types'])) {
            $placeholders = implode(',', array_fill(0, count($args['restriction_types']), '%s'));
            $where_conditions[] = "type IN ($placeholders)";
            $where_values = array_merge($where_values, $args['restriction_types']);
        }

        $where_clause = implode(' AND ', $where_conditions);
        $query = "SELECT * FROM {$restrictions_table} WHERE {$where_clause}";

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }

        $restrictions = $wpdb->get_results($query, ARRAY_A);

        // Also export scheduled restrictions if available
        $scheduled_table = $wpdb->prefix . 'acp_scheduled_restrictions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$scheduled_table}'") == $scheduled_table) {
            $scheduled_restrictions = $wpdb->get_results("SELECT * FROM {$scheduled_table}", ARRAY_A);

            return array(
                'restrictions' => $restrictions,
                'scheduled_restrictions' => $scheduled_restrictions
            );
        }

        return array('restrictions' => $restrictions);
    }

    /**
     * Export settings
     */
    private function export_settings($args = array()) {
        $settings_keys = array(
            'acp_general_settings',
            'acp_security_settings',
            'acp_advanced_settings',
            'acp_license_settings'
        );

        $settings = array();
        foreach ($settings_keys as $key) {
            $value = get_option($key);
            if ($value !== false) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    /**
     * Export logs
     */
    private function export_logs($args = array()) {
        global $wpdb;

        if (!class_exists('ACP_Logger')) {
            return array();
        }

        $defaults = array(
            'limit' => 1000,
            'days_back' => 30,
            'levels' => array()
        );

        $args = wp_parse_args($args, $defaults);

        $logs_table = $wpdb->prefix . 'acp_logs';
        $where_conditions = array('1=1');
        $where_values = array();

        // Filter by date
        if ($args['days_back'] > 0) {
            $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$args['days_back']} days"));
            $where_conditions[] = 'created_at >= %s';
            $where_values[] = $cutoff_date;
        }

        // Filter by levels
        if (!empty($args['levels'])) {
            $placeholders = implode(',', array_fill(0, count($args['levels']), '%s'));
            $where_conditions[] = "level IN ($placeholders)";
            $where_values = array_merge($where_values, $args['levels']);
        }

        $where_clause = implode(' AND ', $where_conditions);
        $limit_clause = $args['limit'] > 0 ? "LIMIT " . absint($args['limit']) : '';

        $query = "SELECT * FROM {$logs_table} WHERE {$where_clause} ORDER BY created_at DESC {$limit_clause}";

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }

        return $wpdb->get_results($query, ARRAY_A);
    }

    /**
     * Import data
     */
    public function import_data($import_data, $options = array()) {
        $defaults = array(
            'overwrite_existing' => false,
            'import_restrictions' => true,
            'import_settings' => true,
            'import_logs' => false,
            'backup_before_import' => true
        );

        $options = wp_parse_args($options, $defaults);

        // Validate import data
        $validation_result = $this->validate_import_data($import_data);
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }

        // Create backup if requested
        if ($options['backup_before_import']) {
            $backup_result = $this->create_backup();
            if (is_wp_error($backup_result)) {
                return $backup_result;
            }
        }

        $import_results = array(
            'restrictions' => 0,
            'settings' => 0,
            'logs' => 0,
            'errors' => array()
        );

        // Import restrictions
        if ($options['import_restrictions'] && isset($import_data['data']['restrictions'])) {
            $result = $this->import_restrictions($import_data['data']['restrictions'], $options);
            if (is_wp_error($result)) {
                $import_results['errors'][] = $result->get_error_message();
            } else {
                $import_results['restrictions'] = $result;
            }
        }

        // Import settings
        if ($options['import_settings'] && isset($import_data['data']['settings'])) {
            $result = $this->import_settings($import_data['data']['settings'], $options);
            if (is_wp_error($result)) {
                $import_results['errors'][] = $result->get_error_message();
            } else {
                $import_results['settings'] = $result;
            }
        }

        // Import logs (if enabled)
        if ($options['import_logs'] && isset($import_data['data']['logs'])) {
            $result = $this->import_logs($import_data['data']['logs'], $options);
            if (is_wp_error($result)) {
                $import_results['errors'][] = $result->get_error_message();
            } else {
                $import_results['logs'] = $result;
            }
        }

        // Log the import
        if (class_exists('ACP_Logger')) {
            $logger = new ACP_Logger();
            $logger->info(__('Data import completed', 'access-control-pro'), $import_results);
        }

        return $import_results;
    }

    /**
     * Import restrictions
     */
    private function import_restrictions($restrictions_data, $options) {
        global $wpdb;

        $imported_count = 0;
        $restrictions_table = $wpdb->prefix . 'acp_restrictions';

        if (isset($restrictions_data['restrictions'])) {
            foreach ($restrictions_data['restrictions'] as $restriction) {
                // Remove ID for new records
                $restriction_id = $restriction['id'];
                unset($restriction['id']);

                // Check if restriction exists
                $existing = null;
                if (!$options['overwrite_existing']) {
                    $existing = $wpdb->get_row($wpdb->prepare(
                        "SELECT id FROM {$restrictions_table} WHERE type = %s AND user_id = %s AND target_value = %s",
                        $restriction['type'],
                        $restriction['user_id'],
                        $restriction['target_value']
                    ));
                }

                if ($existing && !$options['overwrite_existing']) {
                    continue; // Skip existing restriction
                }

                if ($existing && $options['overwrite_existing']) {
                    // Update existing restriction
                    $result = $wpdb->update(
                        $restrictions_table,
                        $restriction,
                        array('id' => $existing->id)
                    );
                } else {
                    // Insert new restriction
                    $result = $wpdb->insert($restrictions_table, $restriction);
                }

                if ($result !== false) {
                    $imported_count++;
                }
            }
        }

        // Import scheduled restrictions if available
        if (isset($restrictions_data['scheduled_restrictions'])) {
            $scheduled_table = $wpdb->prefix . 'acp_scheduled_restrictions';

            foreach ($restrictions_data['scheduled_restrictions'] as $scheduled) {
                unset($scheduled['id']);
                $wpdb->insert($scheduled_table, $scheduled);
            }
        }

        return $imported_count;
    }

    /**
     * Import settings
     */
    private function import_settings($settings_data, $options) {
        $imported_count = 0;

        foreach ($settings_data as $option_name => $option_value) {
            if ($options['overwrite_existing'] || get_option($option_name) === false) {
                update_option($option_name, $option_value);
                $imported_count++;
            }
        }

        return $imported_count;
    }

    /**
     * Import logs
     */
    private function import_logs($logs_data, $options) {
        global $wpdb;

        if (!class_exists('ACP_Logger')) {
            return new WP_Error('no_logger', __('Logger class not available', 'access-control-pro'));
        }

        $imported_count = 0;
        $logs_table = $wpdb->prefix . 'acp_logs';

        foreach ($logs_data as $log) {
            unset($log['id']); // Remove ID for new records

            $result = $wpdb->insert($logs_table, $log);
            if ($result !== false) {
                $imported_count++;
            }
        }

        return $imported_count;
    }

    /**
     * Validate import data
     */
    private function validate_import_data($import_data) {
        if (!is_array($import_data)) {
            return new WP_Error('invalid_format', __('Import data must be an array', 'access-control-pro'));
        }

        if (!isset($import_data['plugin_version'])) {
            return new WP_Error('missing_version', __('Import data missing plugin version', 'access-control-pro'));
        }

        if (!isset($import_data['data']) || !is_array($import_data['data'])) {
            return new WP_Error('missing_data', __('Import data missing data section', 'access-control-pro'));
        }

        // Check plugin version compatibility
        $current_version = ACP_VERSION;
        $import_version = $import_data['plugin_version'];

        if (version_compare($import_version, $current_version, '>')) {
            return new WP_Error('version_mismatch',
                sprintf(__('Import data is from a newer plugin version (%s). Current version: %s', 'access-control-pro'),
                $import_version, $current_version)
            );
        }

        return true;
    }

    /**
     * Create backup before import
     */
    private function create_backup() {
        $backup_data = $this->export_data(array(self::EXPORT_ALL));

        $backup_filename = 'acp-backup-' . date('Y-m-d-H-i-s') . '.json';
        $upload_dir = wp_upload_dir();
        $backup_path = $upload_dir['basedir'] . '/acp-backups/';

        // Create backup directory if it doesn't exist
        if (!file_exists($backup_path)) {
            wp_mkdir_p($backup_path);
        }

        $backup_file = $backup_path . $backup_filename;

        $result = file_put_contents($backup_file, wp_json_encode($backup_data, JSON_PRETTY_PRINT));

        if ($result === false) {
            return new WP_Error('backup_failed', __('Failed to create backup file', 'access-control-pro'));
        }

        return $backup_file;
    }

    /**
     * Get available backups
     */
    public function get_backups() {
        $upload_dir = wp_upload_dir();
        $backup_path = $upload_dir['basedir'] . '/acp-backups/';

        if (!file_exists($backup_path)) {
            return array();
        }

        $backup_files = glob($backup_path . 'acp-backup-*.json');
        $backups = array();

        foreach ($backup_files as $file) {
            $filename = basename($file);
            $backups[] = array(
                'filename' => $filename,
                'path' => $file,
                'size' => filesize($file),
                'date' => filemtime($file),
                'url' => $upload_dir['baseurl'] . '/acp-backups/' . $filename
            );
        }

        // Sort by date (newest first)
        usort($backups, function($a, $b) {
            return $b['date'] - $a['date'];
        });

        return $backups;
    }

    /**
     * Delete backup file
     */
    public function delete_backup($filename) {
        $upload_dir = wp_upload_dir();
        $backup_path = $upload_dir['basedir'] . '/acp-backups/' . sanitize_file_name($filename);

        if (!file_exists($backup_path)) {
            return new WP_Error('file_not_found', __('Backup file not found', 'access-control-pro'));
        }

        if (unlink($backup_path)) {
            return true;
        }

        return new WP_Error('delete_failed', __('Failed to delete backup file', 'access-control-pro'));
    }

    /**
     * Generate export file
     */
    public function generate_export_file($types = array(self::EXPORT_ALL), $args = array()) {
        $export_data = $this->export_data($types, $args);

        $filename = 'acp-export-' . date('Y-m-d-H-i-s') . '.json';

        // Set headers for download
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');

        echo wp_json_encode($export_data, JSON_PRETTY_PRINT);
        exit;
    }
}
