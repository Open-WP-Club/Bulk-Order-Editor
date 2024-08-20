<?php

/**
 * Plugin Name:             Bulk Order Editor
 * Plugin URI:              https://github.com/MrGKanev/Bulk-Order-Editor/
 * Description:             Bulk Order Editor is a simple plugin that allows you to change the status of multiple WooCommerce orders at once.
 * Version:                 0.0.3
 * Author:                  Gabriel Kanev
 * Author URI:              https://gkanev.com
 * License:                 MIT
 * Requires at least:       6.4
 * Requires PHP:            7.4
 * WC requires at least:    6.0
 * WC tested up to:         9.1.2
 */

defined('ABSPATH') || exit;

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    define('__FP_FILE__', __FILE__);

    // Declare HPOS compatibility
    add_action('before_woocommerce_init', function () {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    });

    // Enqueue the custom JavaScript
    add_action('admin_enqueue_scripts', 'enqueue_custom_admin_script');

    function enqueue_custom_admin_script()
    {
        wp_enqueue_script('bulk-order-editor-js', plugin_dir_url(__FP_FILE__) . 'js/bulk-order-editor.js', array('jquery'), '1.0', true);
        wp_localize_script('bulk-order-editor-js', 'bulkOrderEditor', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('bulk_order_editor_nonce')
        ));
    }

    add_action('admin_enqueue_scripts', 'enqueue_plugin_styles');

    function enqueue_plugin_styles()
    {
        // Check if we're on the plugin's page to avoid loading it on all admin pages
        $screen = get_current_screen();
        if ($screen->id === 'woocommerce_page_order-status-changer') {  // Adjust this ID based on your actual plugin page ID
            wp_enqueue_style('bulk-order-editor-css', plugin_dir_url(__FILE__) . 'css/style.css', array(), '1.0', 'all');
        }
    }

    // Add a new menu item to the WooCommerce menu
    add_action('admin_menu', 'register_custom_woocommerce_menu_page');

    function register_custom_woocommerce_menu_page()
    {
        add_submenu_page(
            'woocommerce', // Parent slug
            'Bulk Order Editor', // Page title
            'Bulk Order Editor', // Menu title
            'manage_woocommerce', // Capability
            'order-status-changer', // Menu slug
            'order_status_editor_page_content' // Callback function
        );
    }

    // Function to retrieve all WooCommerce order statuses
    function get_woocommerce_order_statuses()
    {
        return wc_get_order_statuses();
    }

    // Display the custom admin page content
    function order_status_editor_page_content()
    {
        $order_statuses = get_woocommerce_order_statuses();
?>
        <div class="wrap">
            <h1>Bulk Order Editor</h1>
            <div id="response-message"></div>
            <form id="order-status-form">
                <h2>Order Details</h2>
                <div class="form-group">
                    <label for="order_ids">Order IDs (comma-separated):</label>
                    <input type="text" id="order_ids" name="order_ids" required>
                </div>

                <div class="form-group">
                    <label for="order_status">Order Status:</label>
                    <select id="order_status" name="order_status">
                        <option value="">Select status</option>
                        <?php foreach ($order_statuses as $status_key => $status_label) : ?>
                            <option value="<?php echo esc_attr($status_key); ?>"><?php echo esc_html($status_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="order_total">Order Total:</label>
                    <input type="number" step="0.01" id="order_total" name="order_total">
                </div>

                <div class="form-group">
                    <label for="promo_code">Promo Code:</label>
                    <input type="text" id="promo_code" name="promo_code">
                </div>

                <h2>Customer Details</h2>
                <div class="form-group">
                    <label for="customer_id">Customer ID:</label>
                    <input type="number" id="customer_id" name="customer_id">
                </div>

                <div class="form-group">
                    <label for="customer_note">Note:</label>
                    <textarea id="customer_note" name="customer_note"></textarea>
                </div>

                <div class="form-group">
                    <label for="note_type">Note Type:</label>
                    <select id="note_type" name="note_type">
                        <option value="private">Private</option>
                        <option value="customer">Customer</option>
                    </select>
                </div>

                <h2>Order Processing</h2>
                <div class="form-group">
                <label for="order_datetime">Order Date and Time:</label>
                <input type="datetime-local" id="order_datetime" name="order_datetime">
            </div>

                <input type="submit" value="Update Orders" class="button button-primary">
            </form>

            <div class="log-area">
                <h2>Update Log</h2>
                <p id="update-progress" style="display:none;">Progress: <span id="progress-percentage">0%</span></p>
                <ul id="log-list">
                    <li>No log entries found.</li>
                </ul>
            </div>
        </div>
<?php
    }


    // New function to handle batch processing
    function handle_batch_update_orders()
    {
        check_ajax_referer('bulk_order_editor_nonce', 'nonce');

        $order_ids = isset($_POST['order_ids']) ? array_map('intval', explode(',', $_POST['order_ids'])) : [];
        $batch_size = 10; // Process 10 orders at a time
        $processed = isset($_POST['processed']) ? intval($_POST['processed']) : 0;

        $batch = array_slice($order_ids, $processed, $batch_size);
        $results = [];

        foreach ($batch as $order_id) {
            $result = process_single_order($order_id, $_POST);
            $results[] = $result;
        }

        $processed += count($batch);
        $is_complete = $processed >= count($order_ids);

        wp_send_json([
            'success' => true,
            'processed' => $processed,
            'total' => count($order_ids),
            'is_complete' => $is_complete,
            'results' => $results
        ]);
    }

    // Helper function to process a single order
    function process_single_order($order_id, $data)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['error' => sprintf('Order #%d not found', $order_id)];
        }

        $log_entries = [];

        // Process order status
        if (!empty($data['order_status'])) {
            $new_status = sanitize_text_field($data['order_status']);
            if ($order->get_status() !== $new_status) {
                $order->set_status($new_status);
                $log_entries[] = sprintf('Order #%d status changed to "%s"', $order_id, wc_get_order_status_name($new_status));
            }
        }

        // Process order total
        if (!empty($data['order_total'])) {
            $new_total = floatval($data['order_total']);
            if ($order->get_total() != $new_total) {
                $order->set_total($new_total);
                $log_entries[] = sprintf('Order #%d total changed to %.2f', $order_id, $new_total);
            }
        }

        // Process promo code
        if (!empty($data['promo_code'])) {
            $promo_code = sanitize_text_field($data['promo_code']);
            $coupon = new WC_Coupon($promo_code);
            if ($coupon->get_id()) {
                $result = $order->apply_coupon($coupon);
                if (!is_wp_error($result)) {
                    $log_entries[] = sprintf('Promo code "%s" applied to order #%d', $promo_code, $order_id);
                }
            }
        }

        // Process order date and time
        if (!empty($data['order_datetime'])) {
            $new_datetime = new WC_DateTime($data['order_datetime']);
            $order->set_date_created($new_datetime);
            $log_entries[] = sprintf('Order #%d date and time changed to %s', $order_id, $new_datetime->format('Y-m-d H:i:s'));
        }

        $order->save();

        return ['order_id' => $order_id, 'log_entries' => $log_entries];
    }

    // Register the new AJAX action
    add_action('wp_ajax_batch_update_orders', 'handle_batch_update_orders');

    // Handle AJAX request for individual order updates
    add_action('wp_ajax_update_single_order', 'handle_update_single_order_ajax');

    function handle_update_single_order_ajax()
    {
        check_ajax_referer('bulk_order_editor_nonce', 'nonce');

        if (isset($_POST['order_id'])) {
            $order_id = intval(sanitize_text_field($_POST['order_id']));
            $order_status = isset($_POST['order_status']) ? sanitize_text_field($_POST['order_status']) : '';
            $order_total = isset($_POST['order_total']) ? floatval($_POST['order_total']) : '';
            $promo_code = isset($_POST['promo_code']) ? sanitize_text_field($_POST['promo_code']) : '';
            $customer_note = isset($_POST['customer_note']) ? sanitize_textarea_field($_POST['customer_note']) : '';
            $note_type = isset($_POST['note_type']) ? sanitize_text_field($_POST['note_type']) : 'private';
            $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
            $order_datetime = isset($_POST['order_datetime']) ? sanitize_text_field($_POST['order_datetime']) : '';
            $current_user = wp_get_current_user();
            $current_user_name = $current_user->display_name ? $current_user->display_name : $current_user->user_login;

            // Log the received data
            error_log('Received Data: ' . print_r($_POST, true));

            // Use HPOS compatible method to get the order
            $order = wc_get_order($order_id);
            if ($order) {
                $log_entries = [];

                if ($customer_id && $order->get_customer_id() !== $customer_id) {
                    $previous_customer_id = $order->get_customer_id();
                    $order->set_customer_id($customer_id);
                    $log_entries[] = sprintf('Order #%d customer ID changed from "%d" to "%d"', $order_id, $previous_customer_id, $customer_id);
                    $order->add_order_note(sprintf('Order customer ID changed from "%d" to "%d" by <b>%s</b>', $previous_customer_id, $customer_id, $current_user_name));
                }

                if ($order_status && $order->get_status() !== $order_status) {
                    $previous_status = $order->get_status();
                    $order->set_status($order_status); // HPOS compatible method
                    $log_entries[] = sprintf('Order #%d status changed from "%s" to "%s"', $order_id, wc_get_order_status_name($previous_status), wc_get_order_status_name($order_status));
                    $order->add_order_note(sprintf('Order status changed from "%s" to "%s" by <b>%s</b>', wc_get_order_status_name($previous_status), wc_get_order_status_name($order_status), $current_user_name));
                }

                if ($order_total && $order->get_total() != $order_total) {
                    $previous_total = $order->get_total();
                    $order->set_total($order_total);
                    $log_entries[] = sprintf('Order #%d total changed from %.2f to %.2f', $order_id, $previous_total, $order_total);
                    $order->add_order_note(sprintf('Order total changed from %.2f to %.2f by <b>%s</b>', $previous_total, $order_total, $current_user_name));
                }

                if (!empty($promo_code)) {
                    $coupon = new WC_Coupon($promo_code);
                    if ($coupon->get_id()) {
                        $result = $order->apply_coupon($coupon);
                        if (is_wp_error($result)) {
                            $log_entries[] = sprintf('Failed to add promo code "%s" to order #%d: %s', $promo_code, $order_id, $result->get_error_message());
                        } else {
                            $log_entries[] = sprintf('Order #%d promo code added: "%s"', $order_id, $promo_code);
                            $order->add_order_note(sprintf('Promo code "%s" added by <b>%s</b>', $promo_code, $current_user_name));
                        }
                    } else {
                        $log_entries[] = sprintf('Failed to add promo code "%s" to order #%d: Coupon not found', $promo_code, $order_id);
                    }
                }

                if (!empty($customer_note)) {
                    $order->add_order_note($customer_note, $note_type === 'customer', false, $current_user_name);
                    $log_entries[] = sprintf('Order #%d note added: "%s"', $order_id, $customer_note);
                }

                if ($order_datetime) {
                    $previous_datetime = $order->get_date_created()->format('Y-m-d H:i:s');
                    $new_datetime = new WC_DateTime($order_datetime);
                    $order->set_date_created($new_datetime);
                    $log_entries[] = sprintf('Order #%d date and time of creation changed from "%s" to "%s"', $order_id, $previous_datetime, $new_datetime->format('Y-m-d H:i:s'));
                    $order->add_order_note(sprintf('Order date and time of creation changed from "%s" to "%s" by <b>%s</b>', $previous_datetime, $new_datetime->format('Y-m-d H:i:s'), $current_user_name));
                }

                // Add separator line after all changes for this order
                if (!empty($log_entries)) {
                    $log_entries[] = "--------------------------------";
                }

                $order->save(); // HPOS compatible method to save all changes

                wp_send_json_success(array(
                    'log_entries' => $log_entries,
                    'status'      => 'success'
                ));
            } else {
                error_log(sprintf('Order #%d not found', $order_id)); // Add error logging
                wp_send_json_error(array('message' => sprintf('Order #%d not found', $order_id)));
            }
        } else {
            error_log('Invalid order ID.'); // Add error logging
            wp_send_json_error(array('message' => 'Invalid order ID.'));
        }
    }
} else {
    // Display an admin notice if WooCommerce is not active
    function your_plugin_woocommerce_inactive_notice()
    {
        echo '<div class="notice notice-error is-dismissible">
            <p>"Bulk Order Editor" requires WooCommerce to be active. Please activate WooCommerce first.</p>
        </div>';
    }
    add_action('admin_notices', 'your_plugin_woocommerce_inactive_notice');

    // Deactivate the plugin
    function your_plugin_deactivate_self()
    {
        deactivate_plugins(plugin_basename(__FILE__));
    }
    add_action('admin_init', 'your_plugin_deactivate_self');
}
