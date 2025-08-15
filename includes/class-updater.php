<?php
/**
 * Updater Class
 * Handles plugin updates and version management
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACP_Updater {

    /**
     * Plugin version
     */
    private $version;

    /**
     * Plugin file
     */
    private $plugin_file;

    /**
     * Plugin slug
     */
    private $plugin_slug;

    /**
     * Update server URL
     */
    private $update_server_url;

    /**
     * License key
     */
    private $license_key;

    /**
     * Constructor
     */
    public function __construct() {
        $this->version = ACP_VERSION;
        $this->plugin_file = ACP_PLUGIN_FILE;
        $this->plugin_slug = plugin_basename($this->plugin_file);
        $this->update_server_url = 'https://updates.yoursite.com/acp'; // Replace with actual URL
        $this->license_key = get_option('acp_license_key', '');

        add_action('init', array($this, 'init'));
    }

    /**
     * Initialize updater
     */
    public function init() {
        // Hook into WordPress update system
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);

        // Add version info to admin
        add_action('in_plugin_update_message-' . $this->plugin_slug, array($this, 'update_message'));

        // Handle license-based updates for Pro version
        if (Access_Control_Pro::instance()->is_pro) {
            add_action('upgrader_process_complete', array($this, 'after_update'), 10, 2);
        }

        // Check for updates daily
        add_action('acp_daily_update_check', array($this, 'daily_update_check'));
        if (!wp_next_scheduled('acp_daily_update_check')) {
            wp_schedule_event(time(), 'daily', 'acp_daily_update_check');
        }
    }

    /**
     * Check for plugin updates
     */
    public function check_for_update($transient) {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }

        if (!isset($transient->response)) {
            $transient->response = array();
        }

        // Get cached update data
        $update_data = get_transient('acp_update_data');

        if (false === $update_data) {
            $update_data = $this->get_remote_version_info();
            set_transient('acp_update_data', $update_data, HOUR_IN_SECONDS * 12);
        }

        if ($update_data && version_compare($this->version, $update_data['new_version'], '<')) {
            $transient->response[$this->plugin_slug] = (object) array(
                'slug' => dirname($this->plugin_slug),
                'plugin' => $this->plugin_slug,
                'new_version' => $update_data['new_version'],
                'url' => $update_data['url'],
                'package' => $update_data['package'],
                'tested' => $update_data['tested'],
                'requires_php' => $update_data['requires_php'],
                'compatibility' => $update_data['compatibility'],
                'icons' => $update_data['icons'] ?? array(),
                'banners' => $update_data['banners'] ?? array(),
            );
        }

        return $transient;
    }

    /**
     * Get remote version information
     */
    private function get_remote_version_info() {
        $request_args = array(
            'timeout' => 15,
            'body' => array(
                'action' => 'get_version',
                'plugin_slug' => dirname($this->plugin_slug),
                'version' => $this->version,
                'license_key' => $this->license_key,
                'site_url' => get_site_url(),
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version')
            )
        );

        $response = wp_remote_post($this->update_server_url, $request_args);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['new_version'])) {
            return false;
        }

        // Validate license for Pro features
        if (Access_Control_Pro::instance()->is_pro) {
            if (!isset($data['license_valid']) || !$data['license_valid']) {
                return false;
            }
        }

        return $data;
    }

    /**
     * Handle plugin info requests
     */
    public function plugin_info($false, $action, $args) {
        if (!isset($args->slug) || $args->slug !== dirname($this->plugin_slug)) {
            return $false;
        }

        $remote_info = $this->get_remote_plugin_info();

        if (!$remote_info) {
            return $false;
        }

        return (object) $remote_info;
    }

    /**
     * Get remote plugin information
     */
    private function get_remote_plugin_info() {
        $request_args = array(
            'timeout' => 15,
            'body' => array(
                'action' => 'get_info',
                'plugin_slug' => dirname($this->plugin_slug),
                'version' => $this->version,
                'license_key' => $this->license_key,
                'site_url' => get_site_url()
            )
        );

        $response = wp_remote_post($this->update_server_url, $request_args);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return $data;
    }

    /**
     * Display update message
     */
    public function update_message($plugin_data) {
        $update_data = get_transient('acp_update_data');

        if (!$update_data) {
            return;
        }

        $changelog_url = $update_data['changelog_url'] ?? '';

        if ($changelog_url) {
            echo '<br /><a href="' . esc_url($changelog_url) . '" target="_blank">' .
                 __('View changelog', 'access-control-pro') . '</a>';
        }

        // Show license warning for Pro version
        if (Access_Control_Pro::instance()->is_pro && empty($this->license_key)) {
            echo '<br /><strong style="color: red;">' .
                 __('License key required for automatic updates.', 'access-control-pro') . '</strong>';
        }
    }

    /**
     * Handle after update actions
     */
    public function after_update($upgrader_object, $options) {
        if (!isset($options['plugins']) || !is_array($options['plugins'])) {
            return;
        }

        foreach ($options['plugins'] as $plugin) {
            if ($plugin === $this->plugin_slug) {
                $this->post_update_actions();
                break;
            }
        }
    }

    /**
     * Post-update actions
     */
    private function post_update_actions() {
        // Clear update transients
        delete_transient('acp_update_data');
        delete_transient('acp_plugin_info');

        // Update database if needed
        $this->maybe_update_database();

        // Clear any caches
        $this->clear_plugin_caches();

        // Log the update
        if (class_exists('ACP_Logger')) {
            $logger = new ACP_Logger();
            $logger->info(sprintf(
                __('Plugin updated to version %s', 'access-control-pro'),
                $this->version
            ), array('version' => $this->version));
        }

        // Set update notice
        set_transient('acp_update_notice', true, DAY_IN_SECONDS);
    }

    /**
     * Maybe update database
     */
    private function maybe_update_database() {
        $db_version = get_option('acp_db_version', '1.0.0');

        if (version_compare($db_version, $this->version, '<')) {
            $this->update_database($db_version, $this->version);
            update_option('acp_db_version', $this->version);
        }
    }

    /**
     * Update database structure
     */
    private function update_database($from_version, $to_version) {
        global $wpdb;

        // Database migrations based on version
        if (version_compare($from_version, '1.1.0', '<')) {
            $this->migrate_to_1_1_0();
        }

        if (version_compare($from_version, '1.2.0', '<')) {
            $this->migrate_to_1_2_0();
        }

        // Add more migrations as needed
    }

    /**
     * Migrate to version 1.1.0
     */
    private function migrate_to_1_1_0() {
        global $wpdb;

        // Add is_active column to restrictions table
        $restrictions_table = $wpdb->prefix . 'acp_restrictions';

        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$restrictions_table} LIKE 'is_active'");

        if (!$column_exists) {
            $wpdb->query("ALTER TABLE {$restrictions_table} ADD COLUMN is_active tinyint(1) DEFAULT 1");
        }

        // Create Pro tables if they don't exist
        if (Access_Control_Pro::instance()->is_pro) {
            $database = new ACP_Database();
            $database->create_pro_tables();
        }
    }

    /**
     * Migrate to version 1.2.0
     */
    private function migrate_to_1_2_0() {
        global $wpdb;

        // Update logs table structure for better performance
        $logs_table = $wpdb->prefix . 'acp_logs';

        // Add indexes if they don't exist
        $indexes = array(
            'user_id_created_at' => "ALTER TABLE {$logs_table} ADD INDEX user_id_created_at (user_id, created_at)",
            'level_created_at' => "ALTER TABLE {$logs_table} ADD INDEX level_created_at (level, created_at)"
        );

        foreach ($indexes as $index_name => $sql) {
            $index_exists = $wpdb->get_var("SHOW INDEX FROM {$logs_table} WHERE Key_name = '{$index_name}'");
            if (!$index_exists) {
                $wpdb->query($sql);
            }
        }
    }

    /**
     * Clear plugin caches
     */
    private function clear_plugin_caches() {
        // Clear WordPress object cache
        wp_cache_flush();

        // Clear plugin-specific transients
        $transients = array(
            'acp_update_data',
            'acp_plugin_info',
            'acp_license_data',
            'acp_system_status'
        );

        foreach ($transients as $transient) {
            delete_transient($transient);
        }

        // Clear role and user restriction caches
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acp_%'");
    }

    /**
     * Daily update check
     */
    public function daily_update_check() {
        // Force check for updates
        delete_transient('acp_update_data');
        delete_transient('update_plugins');

        // Check license status for Pro version
        if (Access_Control_Pro::instance()->is_pro) {
            $this->check_license_status();
        }

        // Clean up old logs and data
        $this->cleanup_old_data();
    }

    /**
     * Check license status
     */
    private function check_license_status() {
        if (empty($this->license_key)) {
            return;
        }

        $request_args = array(
            'timeout' => 15,
            'body' => array(
                'action' => 'check_license',
                'license_key' => $this->license_key,
                'site_url' => get_site_url(),
                'plugin_version' => $this->version
            )
        );

        $response = wp_remote_post($this->update_server_url, $request_args);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($data && isset($data['license_status'])) {
                update_option('acp_license_status', $data['license_status']);
                update_option('acp_license_expires', $data['expires'] ?? '');
                update_option('acp_license_sites_limit', $data['sites_limit'] ?? 1);

                // Log license check
                if (class_exists('ACP_Logger')) {
                    $logger = new ACP_Logger();
                    $logger->debug('License status checked', array(
                        'status' => $data['license_status'],
                        'expires' => $data['expires'] ?? 'N/A'
                    ));
                }
            }
        }
    }

    /**
     * Cleanup old data
     */
    private function cleanup_old_data() {
        // Clean up old logs
        if (class_exists('ACP_Logger')) {
            $logger = new ACP_Logger();
            $retention_days = get_option('acp_log_retention_days', 30);
            $logger->cleanup_logs($retention_days);
        }

        // Clean up old transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_acp_%' AND option_value < UNIX_TIMESTAMP()");

        // Optimize database tables
        if (get_option('acp_auto_optimize_db', false)) {
            $this->optimize_database_tables();
        }
    }

    /**
     * Optimize database tables
     */
    private function optimize_database_tables() {
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'acp_restrictions',
            $wpdb->prefix . 'acp_logs'
        );

        if (Access_Control_Pro::instance()->is_pro) {
            $tables[] = $wpdb->prefix . 'acp_scheduled_restrictions';
        }

        foreach ($tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                $wpdb->query("OPTIMIZE TABLE {$table}");
            }
        }
    }

    /**
     * Force update check
     */
    public function force_update_check() {
        delete_transient('acp_update_data');
        delete_transient('update_plugins');

        // Trigger WordPress update check
        wp_update_plugins();

        return $this->get_remote_version_info();
    }

    /**
     * Get current version info
     */
    public function get_version_info() {
        return array(
            'current_version' => $this->version,
            'plugin_file' => $this->plugin_file,
            'plugin_slug' => $this->plugin_slug,
            'is_pro' => Access_Control_Pro::instance()->is_pro,
            'license_key' => !empty($this->license_key) ? substr($this->license_key, 0, 8) . '...' : '',
            'license_status' => get_option('acp_license_status', 'inactive'),
            'last_checked' => get_option('_transient_timeout_acp_update_data', 0),
            'update_available' => $this->is_update_available()
        );
    }

    /**
     * Check if update is available
     */
    public function is_update_available() {
        $update_data = get_transient('acp_update_data');

        if (!$update_data) {
            return false;
        }

        return version_compare($this->version, $update_data['new_version'], '<');
    }

    /**
     * Get changelog
     */
    public function get_changelog($version = null) {
        $request_args = array(
            'timeout' => 15,
            'body' => array(
                'action' => 'get_changelog',
                'plugin_slug' => dirname($this->plugin_slug),
                'version' => $version ?: $this->version,
                'license_key' => $this->license_key
            )
        );

        $response = wp_remote_post($this->update_server_url, $request_args);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return $data['changelog'] ?? false;
    }

    /**
     * Activate license
     */
    public function activate_license($license_key) {
        $request_args = array(
            'timeout' => 15,
            'body' => array(
                'action' => 'activate_license',
                'license_key' => $license_key,
                'site_url' => get_site_url(),
                'plugin_version' => $this->version
            )
        );

        $response = wp_remote_post($this->update_server_url, $request_args);

        if (is_wp_error($response)) {
            return new WP_Error('request_failed', $response->get_error_message());
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('request_failed', __('License server unavailable', 'access-control-pro'));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data) {
            return new WP_Error('invalid_response', __('Invalid response from license server', 'access-control-pro'));
        }

        if (!$data['success']) {
            return new WP_Error('license_error', $data['message'] ?? __('License activation failed', 'access-control-pro'));
        }

        // Save license data
        update_option('acp_license_key', $license_key);
        update_option('acp_license_status', $data['license_status']);
        update_option('acp_license_expires', $data['expires'] ?? '');
        update_option('acp_license_sites_limit', $data['sites_limit'] ?? 1);

        $this->license_key = $license_key;

        // Log license activation
        if (class_exists('ACP_Logger')) {
            $logger = new ACP_Logger();
            $logger->info('License activated successfully', array(
                'license_key' => substr($license_key, 0, 8) . '...',
                'status' => $data['license_status']
            ));
        }

        return true;
    }

    /**
     * Deactivate license
     */
    public function deactivate_license() {
        if (empty($this->license_key)) {
            return new WP_Error('no_license', __('No license key found', 'access-control-pro'));
        }

        $request_args = array(
            'timeout' => 15,
            'body' => array(
                'action' => 'deactivate_license',
                'license_key' => $this->license_key,
                'site_url' => get_site_url()
            )
        );

        $response = wp_remote_post($this->update_server_url, $request_args);

        // Clear license data regardless of response
        delete_option('acp_license_key');
        delete_option('acp_license_status');
        delete_option('acp_license_expires');
        delete_option('acp_license_sites_limit');

        $this->license_key = '';

        // Log license deactivation
        if (class_exists('ACP_Logger')) {
            $logger = new ACP_Logger();
            $logger->info('License deactivated');
        }

        return true;
    }

    /**
     * Get system status for updates
     */
    public function get_system_status() {
        global $wpdb;

        return array(
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'plugin_version' => $this->version,
            'is_multisite' => is_multisite(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'database_version' => $wpdb->db_version(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'https_enabled' => is_ssl(),
            'timezone' => get_option('timezone_string') ?: date_default_timezone_get(),
            'language' => get_locale()
        );
    }

    /**
     * Handle update notifications
     */
    public function show_update_notices() {
        if (get_transient('acp_update_notice')) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php
                    printf(
                        __('Advanced Access Control Pro has been updated to version %s successfully!', 'access-control-pro'),
                        $this->version
                    );
                    ?>
                </p>
            </div>
            <?php
            delete_transient('acp_update_notice');
        }
    }

    /**
     * Backup before update (Pro feature)
     */
    public function create_pre_update_backup() {
        if (!Access_Control_Pro::instance()->is_pro) {
            return false;
        }

        if (!class_exists('ACP_Exporter')) {
            return false;
        }

        try {
            $exporter = new ACP_Exporter();
            $backup_path = $exporter->create_backup();

            if (!is_wp_error($backup_path)) {
                update_option('acp_pre_update_backup', $backup_path);
                return $backup_path;
            }
        } catch (Exception $e) {
            error_log('ACP Pre-update Backup Failed: ' . $e->getMessage());
        }

        return false;
    }
}
