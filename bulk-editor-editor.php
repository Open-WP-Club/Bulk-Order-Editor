<?php

/**
 * Plugin Name: Bulk Order Editor
 * Tested up to: 7.1.4
 * Plugin URI: https://github.com/MrGKanev/Bulk-Order-Editor/
 * Description: Bulk Order Editor is a simple plugin that allows you to change the status of multiple WooCommerce orders at once.
 * Version: 0.0.6
 * Author: Gabriel Kanev
 * Author URI: https://gkanev.com
 * License: GPL-2.0 License
 * Requires Plugins: woocommerce
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 11.2.2
 */

defined('ABSPATH') || exit;

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    require_once plugin_dir_path(__FILE__) . 'includes/class-woocommerce-dependency.php';
    new Bulk_Order_Editor_WooCommerce_Dependency(__FILE__);
    return;
}

define('BULK_ORDER_EDITOR_PLUGIN_FILE', __FILE__);
define('BULK_ORDER_EDITOR_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Declare HPOS compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

require_once BULK_ORDER_EDITOR_PLUGIN_DIR . 'includes/class-bulk-order-editor-main.php';

function run_bulk_order_editor()
{
    $plugin = new Bulk_Order_Editor_Main();
    $plugin->run();
}

run_bulk_order_editor();

// Activation hook
register_activation_hook(__FILE__, 'bulk_order_editor_activate');

function bulk_order_editor_activate()
{
    // Activation code here (if any)
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'bulk_order_editor_deactivate');

function bulk_order_editor_deactivate()
{
    // Deactivation code here (if any)
}

// Uninstall hook
register_uninstall_hook(__FILE__, 'bulk_order_editor_uninstall');

function bulk_order_editor_uninstall()
{
    // Uninstall code here (if any)
}

// Add settings link on plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bulk_order_editor_settings_link');

function bulk_order_editor_settings_link($links)
{
    $settings_link = '<a href="admin.php?page=bulk-order-editor">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Load text domain for internationalization
add_action('plugins_loaded', 'bulk_order_editor_load_textdomain');

function bulk_order_editor_load_textdomain()
{
    load_plugin_textdomain('bulk-order-editor', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
