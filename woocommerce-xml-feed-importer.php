<?php
/**
 * Plugin Name: WooCommerce XML Feed Importer
 * Plugin URI: https://github.com/ictsystemssaptyltd-prog/woocommerce-xml-feed-importer-updated
 * Description: Import XML and CSV product feeds into WooCommerce with manual trigger buttons for on-demand imports
 * Version: 2.0.0
 * Author: ICT Systems Saptyltd
 * Author URI: https://github.com/ictsystemssaptyltd-prog
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpfi
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires WP: 5.0
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit('No direct script access allowed');
}

if (!defined('WPFI_VERSION')) {
    define('WPFI_VERSION', '2.0.0');
    define('WPFI_PATH', plugin_dir_path(__FILE__));
    define('WPFI_URL', plugin_dir_url(__FILE__));
}

// Verify WooCommerce is active
if (!function_exists('is_plugin_active')) {
    include_once ABSPATH . 'wp-admin/includes/plugin.php';
}

if (!is_plugin_active('woocommerce/woocommerce.php')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error is-dismissible">';
        echo '<p><strong>WooCommerce XML Feed Importer Error:</strong> This plugin requires WooCommerce to be installed and activated.</p>';
        echo '</div>';
    });
    return;
}

// Load plugin classes
require_once WPFI_PATH . 'includes/class-wpfi-logger.php';
require_once WPFI_PATH . 'includes/class-wpfi-feed-repository.php';
require_once WPFI_PATH . 'includes/class-wpfi-importer.php';
require_once WPFI_PATH . 'includes/class-wpfi-scheduler.php';
require_once WPFI_PATH . 'includes/class-wpfi-admin.php';
require_once WPFI_PATH . 'includes/class-wpfi-plugin.php';

// Initialize plugin on WordPress load
add_action('init', function() {
    WPFI_Plugin::instance()->boot();
}, 5);

// Plugin activation/deactivation hooks
register_activation_hook(__FILE__, ['WPFI_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WPFI_Plugin', 'deactivate']);
