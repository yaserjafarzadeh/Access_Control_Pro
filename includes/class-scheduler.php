<?php
/**
 * Scheduler Class - Pro Feature
 * Advanced Time-based Access Control
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACP_Scheduler {

    /**
     * Table name for scheduled restrictions
     */
    private $table_name;

    /**
     * Database instance
     */
    private $database;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'acp_scheduled_restrictions';
        $this->database = new ACP_Database();

        // Only initialize if Pro version is active
        if (Access_Control_Pro::instance()->is_pro) {
            $this->logger = new ACP_Logger();
            $this->init_hooks();
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Hook into WordPress cron
        add_action('acp_check_scheduled_restrictions', array($this, 'process_scheduled_restrictions'));
        add_action('acp_cleanup_expired_schedules', array($this, 'cleanup_expired_schedules'));

        // Schedule the cron if not scheduled
        if (!wp_next_scheduled('acp_check_scheduled_restrictions')) {
            wp_schedule_event(time(), 'every_minute', 'acp_check_scheduled_restrictions');
        }

        // Daily cleanup
        if (!wp_next_scheduled('acp_cleanup_expired_schedules')) {
            wp_schedule_event(time(), 'daily', 'acp_cleanup_expired_schedules');
        }

        // Add custom cron intervals
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
    }

    /**
     * Add custom cron intervals
     */
    public function add_cron_intervals($schedules) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute', 'access-control-pro')
        );
        $schedules['every_five_minutes'] = array(
            'interval' => 300,
            'display' => __('Every 5 Minutes', 'access-control-pro')
        );
        $schedules['every_fifteen_minutes'] = array(
            'interval' => 900,
            'display' => __('Every 15 Minutes', 'access-control-pro')
        );
        return $schedules;
    }

    /**
     * Add scheduled restriction
     */
    public function add_scheduled_restriction($data) {
        global $wpdb;

        $defaults = array(
            'restriction_id' => 0,
            'title' => '',
            'action_type' => 'activate', // activate, deactivate
            'start_date' => '',
            'end_date' => '',
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => '', // JSON array of days (0=Sunday, 6=Saturday)
            'timezone' => get_option('timezone_string', 'UTC'),
            'is_active' => 1,
            'is_recurring' => 0,
            'recurring_pattern' => '', // daily, weekly, monthly
            'max_executions' => 0,
            'current_executions' => 0,
            'last_executed' => null,
            'created_by' => get_current_user_id(),
        );

        $data = wp_parse_args($data, $defaults);

        // Validate data
        $validation = $this->validate_schedule_data($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $result = $wpdb->insert(
            $this->table_name,
            array(
                'restriction_id' => absint($data['restriction_id']),
                'title' => sanitize_text_field($data['title']),
                'action_type' => sanitize_text_field($data['action_type']),
                'start_date' => $data['start_date'] ? sanitize_text_field($data['start_date']) : null,
                'end_date' => $data['end_date'] ? sanitize_text_field($data['end_date']) : null,
                'start_time' => sanitize_text_field($data['start_time']),
                'end_time' => sanitize_text_field($data['end_time']),
                'days_of_week' => sanitize_text_field($data['days_of_week']),
                'timezone' => sanitize_text_field($data['timezone']),
                'is_active' => absint($data['is_active']),
                'is_recurring' => absint($data['is_recurring']),
                'recurring_pattern' => sanitize_text_field($data['recurring_pattern']),
                'max_executions' => absint($data['max_executions']),
                'current_executions' => absint($data['current_executions']),
                'last_executed' => $data['last_executed'],
                'created_by' => absint($data['created_by']),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%d')
        );

        if ($result === false) {
            $this->logger->log('Failed to create scheduled restriction', ACP_Logger::LOG_LEVEL_ERROR, get_current_user_id(), $data);
            return new WP_Error('schedule_creation_failed', __('Failed to create scheduled restriction', 'access-control-pro'));
        }

        $schedule_id = $wpdb->insert_id;

        $this->logger->log('Scheduled restriction created', ACP_Logger::LOG_LEVEL_INFO, get_current_user_id(), array(
            'schedule_id' => $schedule_id,
            'restriction_id' => $data['restriction_id'],
            'action_type' => $data['action_type']
        ));

        return $schedule_id;
    }

    /**
     * Validate schedule data
     */
    private function validate_schedule_data($data) {
        // Check if restriction exists
        if (empty($data['restriction_id'])) {
            return new WP_Error('invalid_restriction', __('Restriction ID is required', 'access-control-pro'));
        }

        // Validate action type
        if (!in_array($data['action_type'], array('activate', 'deactivate'))) {
            return new WP_Error('invalid_action', __('Invalid action type', 'access-control-pro'));
        }

        // Validate time format
        if (!empty($data['start_time']) && !preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $data['start_time'])) {
            return new WP_Error('invalid_time', __('Invalid start time format', 'access-control-pro'));
        }

        if (!empty($data['end_time']) && !preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $data['end_time'])) {
            return new WP_Error('invalid_time', __('Invalid end time format', 'access-control-pro'));
        }

        // Validate date format
        if (!empty($data['start_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['start_date'])) {
            return new WP_Error('invalid_date', __('Invalid start date format', 'access-control-pro'));
        }

        if (!empty($data['end_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['end_date'])) {
            return new WP_Error('invalid_date', __('Invalid end date format', 'access-control-pro'));
        }

        return true;
    }

    /**
     * Process scheduled restrictions
     */
    public function process_scheduled_restrictions() {
        global $wpdb;

        $current_time = current_time('mysql');
        $current_date = current_time('Y-m-d');
        $current_time_only = current_time('H:i:s');
        $current_day_of_week = current_time('w'); // 0 = Sunday

        // Get active schedules that should be processed
        $schedules = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$this->table_name}
            WHERE is_active = 1
            AND (start_date IS NULL OR start_date <= %s)
            AND (end_date IS NULL OR end_date >= %s)
            AND (max_executions = 0 OR current_executions < max_executions)
        ", $current_date, $current_date));

        foreach ($schedules as $schedule) {
            if ($this->should_execute_schedule($schedule, $current_date, $current_time_only, $current_day_of_week)) {
                $this->execute_scheduled_restriction($schedule);
            }
        }
    }

    /**
     * Check if schedule should be executed
     */
    private function should_execute_schedule($schedule, $current_date, $current_time, $current_day_of_week) {
        // Check time range
        if ($current_time < $schedule->start_time || $current_time > $schedule->end_time) {
            return false;
        }

        // Check days of week if specified
        if (!empty($schedule->days_of_week)) {
            $allowed_days = json_decode($schedule->days_of_week, true);
            if (is_array($allowed_days) && !in_array($current_day_of_week, $allowed_days)) {
                return false;
            }
        }

        // Check if already executed today (for non-recurring)
        if (!$schedule->is_recurring && $schedule->last_executed) {
            $last_executed_date = date('Y-m-d', strtotime($schedule->last_executed));
            if ($last_executed_date === $current_date) {
                return false;
            }
        }

        // Check recurring pattern
        if ($schedule->is_recurring && $schedule->last_executed) {
            return $this->should_execute_recurring($schedule, $current_date);
        }

        return true;
    }

    /**
     * Check recurring execution
     */
    private function should_execute_recurring($schedule, $current_date) {
        $last_executed = strtotime($schedule->last_executed);
        $current_timestamp = strtotime($current_date);

        switch ($schedule->recurring_pattern) {
            case 'daily':
                return ($current_timestamp - $last_executed) >= 86400; // 24 hours

            case 'weekly':
                return ($current_timestamp - $last_executed) >= 604800; // 7 days

            case 'monthly':
                $last_month = date('Y-m', $last_executed);
                $current_month = date('Y-m', $current_timestamp);
                return $last_month !== $current_month;

            default:
                return false;
        }
    }

    /**
     * Execute scheduled restriction
     */
    private function execute_scheduled_restriction($schedule) {
        global $wpdb;

        // Get the restriction
        $restriction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->database->restrictions_table} WHERE id = %d",
            $schedule->restriction_id
        ));

        if (!$restriction) {
            $this->logger->log('Scheduled restriction target not found', ACP_Logger::LOG_LEVEL_WARNING, 0, array(
                'schedule_id' => $schedule->id,
                'restriction_id' => $schedule->restriction_id
            ));
            return;
        }

        $success = false;

        if ($schedule->action_type === 'activate') {
            $success = $this->activate_restriction($restriction);
        } elseif ($schedule->action_type === 'deactivate') {
            $success = $this->deactivate_restriction($restriction);
        }

        if ($success) {
            // Update execution count and last executed time
            $wpdb->update(
                $this->table_name,
                array(
                    'current_executions' => $schedule->current_executions + 1,
                    'last_executed' => current_time('mysql')
                ),
                array('id' => $schedule->id),
                array('%d', '%s'),
                array('%d')
            );

            $this->logger->log('Scheduled restriction executed successfully', ACP_Logger::LOG_LEVEL_INFO, 0, array(
                'schedule_id' => $schedule->id,
                'restriction_id' => $schedule->restriction_id,
                'action_type' => $schedule->action_type
            ));

            // Deactivate if max executions reached
            if ($schedule->max_executions > 0 && ($schedule->current_executions + 1) >= $schedule->max_executions) {
                $this->deactivate_schedule($schedule->id);
            }
        }
    }

    /**
     * Activate restriction
     */
    private function activate_restriction($restriction) {
        // Apply the restriction based on its type
        $restrictions_data = maybe_unserialize($restriction->restrictions);

        // This would integrate with the main restriction system
        // For now, we'll just log the action
        do_action('acp_restriction_activated', $restriction, $restrictions_data);

        return true;
    }

    /**
     * Deactivate restriction
     */
    private function deactivate_restriction($restriction) {
        // Remove the restriction based on its type
        $restrictions_data = maybe_unserialize($restriction->restrictions);

        // This would integrate with the main restriction system
        // For now, we'll just log the action
        do_action('acp_restriction_deactivated', $restriction, $restrictions_data);

        return true;
    }

    /**
     * Get scheduled restrictions
     */
    public function get_scheduled_restrictions($args = array()) {
        global $wpdb;

        $defaults = array(
            'limit' => 20,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'is_active' => null,
            'restriction_id' => null
        );

        $args = wp_parse_args($args, $defaults);

        $where_clauses = array('1=1');
        $where_values = array();

        if ($args['is_active'] !== null) {
            $where_clauses[] = 'is_active = %d';
            $where_values[] = $args['is_active'];
        }

        if ($args['restriction_id'] !== null) {
            $where_clauses[] = 'restriction_id = %d';
            $where_values[] = $args['restriction_id'];
        }

        $where_sql = implode(' AND ', $where_clauses);
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);

        $sql = "SELECT * FROM {$this->table_name} WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d";
        $where_values[] = $args['limit'];
        $where_values[] = $args['offset'];

        $results = $wpdb->get_results($wpdb->prepare($sql, $where_values));

        return $results;
    }

    /**
     * Update scheduled restriction
     */
    public function update_scheduled_restriction($schedule_id, $data) {
        global $wpdb;

        $allowed_fields = array(
            'title', 'action_type', 'start_date', 'end_date', 'start_time', 'end_time',
            'days_of_week', 'timezone', 'is_active', 'is_recurring', 'recurring_pattern', 'max_executions'
        );

        $update_data = array();
        $update_format = array();

        foreach ($data as $field => $value) {
            if (in_array($field, $allowed_fields)) {
                $update_data[$field] = sanitize_text_field($value);
                $update_format[] = '%s';
            }
        }

        if (empty($update_data)) {
            return new WP_Error('no_data', __('No valid data to update', 'access-control-pro'));
        }

        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => absint($schedule_id)),
            $update_format,
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('update_failed', __('Failed to update scheduled restriction', 'access-control-pro'));
        }

        $this->logger->log('Scheduled restriction updated', ACP_Logger::LOG_LEVEL_INFO, get_current_user_id(), array(
            'schedule_id' => $schedule_id,
            'updated_fields' => array_keys($update_data)
        ));

        return true;
    }

    /**
     * Delete scheduled restriction
     */
    public function delete_scheduled_restriction($schedule_id) {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table_name,
            array('id' => absint($schedule_id)),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('delete_failed', __('Failed to delete scheduled restriction', 'access-control-pro'));
        }

        $this->logger->log('Scheduled restriction deleted', ACP_Logger::LOG_LEVEL_INFO, get_current_user_id(), array(
            'schedule_id' => $schedule_id
        ));

        return true;
    }

    /**
     * Deactivate schedule
     */
    public function deactivate_schedule($schedule_id) {
        return $this->update_scheduled_restriction($schedule_id, array('is_active' => 0));
    }

    /**
     * Cleanup expired schedules
     */
    public function cleanup_expired_schedules() {
        global $wpdb;

        $current_date = current_time('Y-m-d');

        // Deactivate expired non-recurring schedules
        $wpdb->update(
            $this->table_name,
            array('is_active' => 0),
            array(
                'end_date <' => $current_date,
                'is_recurring' => 0,
                'is_active' => 1
            ),
            array('%d'),
            array('%s', '%d', '%d')
        );

        // Delete very old inactive schedules (older than 30 days)
        $cleanup_date = date('Y-m-d', strtotime('-30 days'));
        $wpdb->delete(
            $this->table_name,
            array(
                'is_active' => 0,
                'updated_at <' => $cleanup_date
            ),
            array('%d', '%s')
        );

        $this->logger->log('Scheduled restrictions cleanup completed', ACP_Logger::LOG_LEVEL_INFO);
    }
}
