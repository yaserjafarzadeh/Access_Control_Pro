<?php
/**
 * Uninstall Script
 * Advanced Access Control Pro
 *
 * This file is executed when the plugin is deleted via WordPress admin.
 * It removes all plugin data, tables, and options.
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Delete plugin options
 */
function acp_delete_options() {
    $options = array(
        'acp_general_settings',
        'acp_security_settings',
        'acp_advanced_settings',
        'acp_license_settings',
        'acp_version',
        'acp_db_version',
        'acp_activation_date',
        'acp_pro_license_key',
        'acp_pro_license_status'
    );

    foreach ($options as $option) {
        delete_option($option);
        delete_site_option($option);
    }
}

/**
 * Delete plugin tables
 */
function acp_delete_tables() {
    global $wpdb;

    $tables = array(
        $wpdb->prefix . 'acp_restrictions',
        $wpdb->prefix . 'acp_logs',
        $wpdb->prefix . 'acp_scheduled_restrictions'
    );

    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }
}

/**
 * Delete user meta related to plugin
 */
function acp_delete_user_meta() {
    global $wpdb;

    $meta_keys = array(
        'acp_restrictions',
        'acp_last_access',
        'acp_restriction_warnings'
    );

    foreach ($meta_keys as $meta_key) {
        $wpdb->delete(
            $wpdb->usermeta,
            array('meta_key' => $meta_key),
            array('%s')
        );
    }
}

/**
 * Delete uploaded files and backups
 */
function acp_delete_uploads() {
    $upload_dir = wp_upload_dir();
    $acp_upload_path = $upload_dir['basedir'] . '/acp-backups/';

    if (file_exists($acp_upload_path)) {
        $files = glob($acp_upload_path . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($acp_upload_path);
    }
}

/**
 * Remove scheduled cron jobs
 */
function acp_clear_scheduled_hooks() {
    wp_clear_scheduled_hook('acp_check_scheduled_restrictions');
    wp_clear_scheduled_hook('acp_cleanup_logs');
    wp_clear_scheduled_hook('acp_check_license');
}

/**
 * Delete transients
 */
function acp_delete_transients() {
    global $wpdb;

    // Delete all transients that start with 'acp_'
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_acp_%'
         OR option_name LIKE '_transient_timeout_acp_%'"
    );

    // Delete site transients for multisite
    if (is_multisite()) {
        $wpdb->query(
            "DELETE FROM {$wpdb->sitemeta}
             WHERE meta_key LIKE '_site_transient_acp_%'
             OR meta_key LIKE '_site_transient_timeout_acp_%'"
        );
    }
}

/**
 * Remove capabilities from roles
 */
function acp_remove_capabilities() {
    $roles = array('administrator', 'editor', 'author', 'contributor', 'subscriber');
    $capabilities = array(
        'manage_access_control',
        'view_access_logs',
        'export_access_data',
        'import_access_data'
    );

    foreach ($roles as $role_name) {
        $role = get_role($role_name);
        if ($role) {
            foreach ($capabilities as $cap) {
                $role->remove_cap($cap);
            }
        }
    }
}

/**
 * Main uninstall function
 */
function acp_uninstall() {
    // Check if user has permission to delete plugins
    if (!current_user_can('delete_plugins')) {
        return;
    }

    // Create a backup before deletion (optional - user choice)
    $create_backup = get_option('acp_create_backup_on_uninstall', false);
    if ($create_backup && class_exists('ACP_Exporter')) {
        try {
            $exporter = new ACP_Exporter();
            $exporter->create_backup();
        } catch (Exception $e) {
            // Silently fail - we don't want to prevent uninstall
            error_log('ACP Uninstall Backup Failed: ' . $e->getMessage());
        }
    }

    // Remove all plugin data
    acp_delete_options();
    acp_delete_tables();
    acp_delete_user_meta();
    acp_delete_uploads();
    acp_clear_scheduled_hooks();
    acp_delete_transients();
    acp_remove_capabilities();

    // Flush rewrite rules to clean up any custom endpoints
    flush_rewrite_rules();

    // Log uninstall (if logging is still available)
    if (function_exists('error_log')) {
        error_log('Advanced Access Control Pro: Plugin uninstalled and all data removed.');
    }
}

// Execute uninstall
acp_uninstall();
