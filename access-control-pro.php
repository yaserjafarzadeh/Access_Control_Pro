<?php
/**
 * Plugin Name: Advanced Access Control Pro
 * Plugin URI: https://your-domain.com/access-control-pro
 * Description: Professional access control plugin for WordPress. Control user access to admin menus, plugins, posts, and pages with advanced role-based permissions.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://your-domain.com
 * Text Domain: access-control-pro
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ACP_VERSION', '1.0.0');
define('ACP_PLUGIN_FILE', __FILE__);
define('ACP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ACP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ACP_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include the main plugin class
require_once ACP_PLUGIN_DIR . 'includes/class-access-control-pro.php';

/**
 * Initialize the plugin
 */
function access_control_pro_init() {
    return Access_Control_Pro::instance();
}

// Initialize the plugin
add_action('plugins_loaded', 'access_control_pro_init');

/**
 * Activation hook
 */
register_activation_hook(__FILE__, array('Access_Control_Pro', 'activate'));

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, array('Access_Control_Pro', 'deactivate'));
