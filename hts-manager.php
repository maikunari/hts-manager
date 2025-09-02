<?php
/**
 * Plugin Name: HTS Manager for WooCommerce
 * Plugin URI: https://sonicpixel.ca/hts-manager-pro/
 * Description: AI-powered HTS code generation for WooCommerce compliance. Automatically classify products with Harmonized Tariff Schedule codes using AI technology.
 * Version: 3.0.0
 * Author: Mike Sewell
 * Author URI: https://sonicpixel.ca/
 * Text Domain: hts-manager
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: false
 *
 * HTS Manager is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * HTS Manager is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'hts_manager_wc_missing_notice');
    return;
}

/**
 * Notice when WooCommerce is not active
 */
function hts_manager_wc_missing_notice() {
    /* translators: %s: WooCommerce plugin name */
    echo '<div class="error"><p><strong>' . sprintf(esc_html__('HTS Manager requires %s to be installed and active.', 'hts-manager'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
}

// Define plugin constants
if (!defined('HTS_MANAGER_VERSION')) {
    define('HTS_MANAGER_VERSION', '3.0.0');
}
if (!defined('HTS_MANAGER_PLUGIN_FILE')) {
    define('HTS_MANAGER_PLUGIN_FILE', __FILE__);
}
if (!defined('HTS_MANAGER_PLUGIN_DIR')) {
    define('HTS_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('HTS_MANAGER_PLUGIN_URL')) {
    define('HTS_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));
}

// Define pro version flag - set to true for pro version
if (!defined('HTS_MANAGER_PRO')) {
    define('HTS_MANAGER_PRO', false);
}

// Register activation and deactivation hooks
register_activation_hook(HTS_MANAGER_PLUGIN_FILE, 'hts_manager_activate');
register_deactivation_hook(HTS_MANAGER_PLUGIN_FILE, 'hts_manager_deactivate');
register_uninstall_hook(HTS_MANAGER_PLUGIN_FILE, 'hts_manager_uninstall');

/**
 * Plugin activation hook
 */
function hts_manager_activate() {
    // Check minimum WordPress version
    if (version_compare(get_bloginfo('version'), '5.0', '<')) {
        deactivate_plugins(plugin_basename(HTS_MANAGER_PLUGIN_FILE));
        wp_die(
            esc_html__('HTS Manager requires WordPress 5.0 or higher. Please upgrade WordPress and try again.', 'hts-manager'),
            esc_html__('Plugin Activation Error', 'hts-manager'),
            array('back_link' => true)
        );
    }
    
    // Check minimum PHP version
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        deactivate_plugins(plugin_basename(HTS_MANAGER_PLUGIN_FILE));
        wp_die(
            esc_html__('HTS Manager requires PHP 7.4 or higher. Please upgrade PHP and try again.', 'hts-manager'),
            esc_html__('Plugin Activation Error', 'hts-manager'),
            array('back_link' => true)
        );
    }
    
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(HTS_MANAGER_PLUGIN_FILE));
        wp_die(
            esc_html__('HTS Manager requires WooCommerce to be installed and active.', 'hts-manager'),
            esc_html__('Plugin Activation Error', 'hts-manager'),
            array('back_link' => true)
        );
    }
    
    // Create default options
    add_option('hts_anthropic_api_key', '');
    add_option('hts_auto_classify_enabled', '1');
    add_option('hts_confidence_threshold', 0.60);
    add_option('hts_classification_usage_count', 0);
    
    // Flush rewrite rules
    flush_rewrite_rules();
    
    // Set activation flag for welcome message
    set_transient('hts_manager_activated', true, 60);
}

/**
 * Plugin deactivation hook
 */
function hts_manager_deactivate() {
    // Clear scheduled events
    wp_clear_scheduled_hook('hts_classify_product_cron');
    
    // Clear transients
    delete_transient('hts_manager_activated');
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Plugin uninstall hook
 */
function hts_manager_uninstall() {
    // Only run if user has capability
    if (!current_user_can('activate_plugins')) {
        return;
    }
    
    // Check if we should delete data
    if (get_option('hts_manager_delete_data_on_uninstall', false)) {
        // Remove options
        delete_option('hts_anthropic_api_key');
        delete_option('hts_auto_classify_enabled');
        delete_option('hts_confidence_threshold');
        delete_option('hts_classification_usage_count');
        delete_option('hts_last_classification_time');
        delete_option('hts_manager_delete_data_on_uninstall');
        
        // Remove post meta for all products
        global $wpdb;
        $wpdb->delete(
            $wpdb->postmeta,
            array(
                'meta_key' => '_hts_code'
            )
        );
        $wpdb->delete(
            $wpdb->postmeta,
            array(
                'meta_key' => '_hts_confidence'
            )
        );
        $wpdb->delete(
            $wpdb->postmeta,
            array(
                'meta_key' => '_hts_updated'
            )
        );
        $wpdb->delete(
            $wpdb->postmeta,
            array(
                'meta_key' => '_country_of_origin'
            )
        );
        
        // Clear any remaining transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_hts_%' OR option_name LIKE '_transient_timeout_hts_%'");
    }
}

// Initialize the plugin
add_action('plugins_loaded', 'hts_manager_init');

/**
 * Initialize the plugin after all plugins are loaded
 */
function hts_manager_init() {
    // Load text domain for translations
    load_plugin_textdomain('hts-manager', false, dirname(plugin_basename(HTS_MANAGER_PLUGIN_FILE)) . '/languages');
    
    // Initialize the main plugin class
    if (class_exists('WooCommerce')) {
        new HTS_Manager();
    }
}

/**
 * Error handling and logging class
 */
class HTS_Error_Handler {
    
    /**
     * Log error and return user-friendly message
     */
    public static function handle_error($error_type, $technical_message = '', $context = array()) {
        // Log technical details for debugging
        self::log_error($error_type, $technical_message, $context);
        
        // Return user-friendly message
        return self::get_user_friendly_message($error_type, $context);
    }
    
    /**
     * Get user-friendly error messages
     */
    public static function get_user_friendly_message($error_type, $context = array()) {
        $messages = array(
            'api_key_missing' => array(
                'message' => __('API key is missing. Please add your Anthropic API key in the HTS Manager settings.', 'hts-manager'),
                'action' => __('Go to WooCommerce → HTS Manager to add your API key.', 'hts-manager'),
                'type' => 'error'
            ),
            'api_key_invalid' => array(
                'message' => __('API key appears to be invalid. Please check your Anthropic API key.', 'hts-manager'),
                'action' => __('Verify your API key is correct in the HTS Manager settings.', 'hts-manager'),
                'type' => 'error'
            ),
            'network_error' => array(
                'message' => __('Unable to connect to the classification service. Please check your internet connection.', 'hts-manager'),
                'action' => __('Try again in a few moments. If the problem persists, contact support.', 'hts-manager'),
                'type' => 'error'
            ),
            'api_rate_limit' => array(
                'message' => __('Too many requests. The classification service is temporarily limiting requests.', 'hts-manager'),
                'action' => __('Please wait a few minutes before trying again.', 'hts-manager'),
                'type' => 'warning'
            ),
            'product_data_invalid' => array(
                'message' => __('Product information is incomplete. Please ensure the product has a name and description.', 'hts-manager'),
                'action' => __('Add more product details and try classifying again.', 'hts-manager'),
                'type' => 'warning'
            ),
            'classification_failed' => array(
                'message' => __('Unable to generate an HTS code for this product. The AI service encountered an issue.', 'hts-manager'),
                'action' => __('Try again, or contact support if the problem continues.', 'hts-manager'),
                'type' => 'error'
            ),
            'server_error' => array(
                'message' => __('The classification service is temporarily unavailable.', 'hts-manager'),
                'action' => __('Please try again later. Service should resume shortly.', 'hts-manager'),
                'type' => 'error'
            ),
            'quota_exceeded' => array(
                'message' => __('API usage limit reached. Your Anthropic account may have exceeded its quota.', 'hts-manager'),
                'action' => __('Check your Anthropic account billing and usage limits.', 'hts-manager'),
                'type' => 'error'
            ),
            'success' => array(
                'message' => __('HTS code generated successfully!', 'hts-manager'),
                'action' => '',
                'type' => 'success'
            )
        );
        
        if (isset($messages[$error_type])) {
            $message = $messages[$error_type];
            
            // Add context-specific information
            if (!empty($context['product_name'])) {
                $message['message'] = sprintf(__('Product "%s": %s', 'hts-manager'), $context['product_name'], $message['message']);
            }
            
            return $message;
        }
        
        // Default fallback message
        return array(
            'message' => __('An unexpected error occurred during HTS classification.', 'hts-manager'),
            'action' => __('Please try again. If the problem persists, contact support.', 'hts-manager'),
            'type' => 'error'
        );
    }
    
    /**
     * Enhanced logging with context
     */
    public static function log_error($error_type, $technical_message = '', $context = array()) {
        $log_message = sprintf(
            '[HTS Manager] Error Type: %s | Message: %s | Context: %s | Time: %s | User: %s',
            $error_type,
            $technical_message,
            wp_json_encode($context),
            current_time('Y-m-d H:i:s'),
            get_current_user_id()
        );
        
        error_log($log_message);
        
        // Also log to WordPress debug if enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log($log_message);
        }
    }
    
    /**
     * Log successful operations
     */
    public static function log_success($operation, $context = array()) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = sprintf(
                '[HTS Manager] SUCCESS: %s | Context: %s | Time: %s | User: %s',
                $operation,
                wp_json_encode($context),
                current_time('Y-m-d H:i:s'),
                get_current_user_id()
            );
            
            error_log($log_message);
        }
    }
    
    /**
     * Create admin notice for user feedback
     */
    public static function create_admin_notice($message_data, $dismissible = true) {
        $notice_class = 'notice notice-' . $message_data['type'];
        if ($dismissible) {
            $notice_class .= ' is-dismissible';
        }
        
        $html = sprintf(
            '<div class="%s"><p><strong>%s</strong></p>',
            esc_attr($notice_class),
            esc_html($message_data['message'])
        );
        
        if (!empty($message_data['action'])) {
            $html .= sprintf('<p>%s</p>', esc_html($message_data['action']));
        }
        
        $html .= '</div>';
        
        return $html;
    }
}

/**
 * Main HTS Manager class
 */
class HTS_Manager {
    
    public function __construct() {
        // Initialize all hooks
        $this->init_hooks();
    }
    
    /**
     * Check if this is the pro version
     */
    public function is_pro() {
        return defined('HTS_MANAGER_PRO') && HTS_MANAGER_PRO === true;
    }
    
    /**
     * Get classification limit based on version
     * @return int -1 for unlimited (pro), positive number for limit (free)
     */
    public function get_classification_limit() {
        return $this->is_pro() ? -1 : 25;
    }
    
    /**
     * Check if bulk operations are allowed
     */
    public function can_use_bulk_classify() {
        return $this->is_pro(); // Only pro version allows bulk operations
    }
    
    /**
     * Check if advanced features are allowed
     */
    public function can_use_advanced_features() {
        return $this->is_pro();
    }
    
    /**
     * Get maximum products that can be processed in bulk
     */
    public function get_bulk_limit() {
        return $this->is_pro() ? -1 : 5; // Free: 5 products max, Pro: unlimited
    }
    
    // ===============================================
    // USAGE TRACKING METHODS
    // ===============================================
    
    /**
     * Get the current usage count for classifications
     * @return int Current number of classifications used
     */
    public function get_usage_count() {
        return (int) get_option('hts_classification_usage_count', 0);
    }
    
    /**
     * Increment the usage counter
     * @return int New usage count
     */
    public function increment_usage() {
        $current_count = $this->get_usage_count();
        $new_count = $current_count + 1;
        update_option('hts_classification_usage_count', $new_count);
        
        // Also update the last usage timestamp
        update_option('hts_last_classification_time', current_time('timestamp'));
        
        return $new_count;
    }
    
    /**
     * Check how many classifications remain for free version
     * @return int Remaining classifications (-1 for unlimited/pro)
     */
    public function get_remaining_classifications() {
        if ($this->is_pro()) {
            return -1; // Unlimited for pro
        }
        
        $limit = $this->get_classification_limit();
        $used = $this->get_usage_count();
        
        return max(0, $limit - $used);
    }
    
    /**
     * Check if user can perform a classification
     * @return bool|array True if allowed, array with error info if not
     */
    public function can_classify() {
        if ($this->is_pro()) {
            return true; // Pro version has no limits
        }
        
        $remaining = $this->get_remaining_classifications();
        
        if ($remaining <= 0) {
            return array(
                'allowed' => false,
                'message' => 'You have reached the limit of 25 classifications for the free version.',
                'upgrade_required' => true,
                'used' => $this->get_usage_count(),
                'limit' => $this->get_classification_limit()
            );
        }
        
        return true;
    }
    
    /**
     * Reset usage counter (for testing or admin purposes)
     * @return bool Success
     */
    public function reset_usage_count() {
        delete_option('hts_classification_usage_count');
        delete_option('hts_last_classification_time');
        return true;
    }
    
    /**
     * Get usage statistics
     * @return array Usage stats
     */
    public function get_usage_stats() {
        $used = $this->get_usage_count();
        $limit = $this->get_classification_limit();
        $remaining = $this->get_remaining_classifications();
        $last_used = get_option('hts_last_classification_time', 0);
        
        return array(
            'used' => $used,
            'limit' => $limit,
            'remaining' => $remaining,
            'percentage_used' => $limit > 0 ? round(($used / $limit) * 100, 1) : 0,
            'is_pro' => $this->is_pro(),
            'last_classification' => $last_used ? date('Y-m-d H:i:s', $last_used) : 'Never',
            'can_classify' => $this->can_classify()
        );
    }
    
    /**
     * Initialize all plugin hooks
     */
    private function init_hooks() {
        // PART 1: PRODUCT DATA TAB & DISPLAY
        add_filter('woocommerce_product_data_tabs', array($this, 'add_product_data_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'add_product_data_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_data_fields'));
        
        // PART 2: AJAX HANDLER
        add_action('wp_ajax_hts_generate_single_code', array($this, 'ajax_generate_single_code'));
        
        // PART 3: AUTO-CLASSIFICATION
        add_action('transition_post_status', array($this, 'auto_classify_on_publish'), 10, 3);
        add_action('save_post_product', array($this, 'auto_classify_on_save'), 10, 3);
        add_action('hts_classify_product_cron', array($this, 'run_scheduled_classification'));
        
        // PART 4: ADMIN
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX HANDLERS
        add_action('wp_ajax_hts_classify_product', array($this, 'ajax_classify_product'));
        add_action('wp_ajax_hts_test_api_key', array($this, 'ajax_test_api_key'));
        add_action('wp_ajax_hts_get_usage_stats', array($this, 'ajax_get_usage_stats'));
        add_action('wp_ajax_hts_bulk_classify', array($this, 'ajax_bulk_classify'));
        add_action('wp_ajax_hts_save_manual_code', array($this, 'ajax_save_manual_code'));
        
        // PART 5: BULK ACTIONS
        add_filter('bulk_actions-edit-product', array($this, 'add_bulk_classify'));
        add_filter('handle_bulk_actions-edit-product', array($this, 'handle_bulk_classify'), 10, 3);
        add_action('admin_notices', array($this, 'bulk_classify_notice'));
        
        // PART 6: DASHBOARD WIDGET
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        
        // PART 7: FRONTEND DISPLAY
        add_action('woocommerce_product_meta_end', array($this, 'display_on_product_page'));
        
        // PART 8: ADMIN NOTICES
        add_action('admin_notices', array($this, 'product_save_notices'));
        add_action('admin_notices', array($this, 'usage_limit_notices'));
        add_action('admin_notices', array($this, 'auto_classification_error_notices'));
        
    }
    
    // ===============================================
    // ADMIN SCRIPTS AND STYLES
    // ===============================================
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        global $post;
        
        // Only load on product edit pages and HTS manager pages
        if (!in_array($hook, ['post.php', 'post-new.php', 'woocommerce_page_hts-manager'])) {
            return;
        }
        
        // Only load on product pages or HTS manager pages
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            if (!$post || $post->post_type !== 'product') {
                return;
            }
        }
        
        // Enqueue scripts
        wp_enqueue_script(
            'hts-manager-admin',
            HTS_MANAGER_PLUGIN_URL . 'assets/js/hts-admin.js',
            array('jquery', 'wp-util'),
            HTS_MANAGER_VERSION,
            true
        );
        
        // Enqueue styles
        wp_enqueue_style(
            'hts-manager-admin',
            HTS_MANAGER_PLUGIN_URL . 'assets/css/hts-admin.css',
            array(),
            HTS_MANAGER_VERSION
        );
        
        // Localize script with AJAX data
        wp_localize_script('hts-manager-admin', 'htsManager', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hts_manager_nonce'),
            'strings' => array(
                'classifying' => __('Classifying...', 'hts-manager'),
                'classified' => __('Product classified successfully!', 'hts-manager'),
                'error' => __('Error occurred during classification', 'hts-manager'),
                'testing' => __('Testing API connection...', 'hts-manager'),
                'connected' => __('API connection successful!', 'hts-manager'),
                'saving' => __('Saving...', 'hts-manager'),
                'saved' => __('Code saved successfully!', 'hts-manager'),
                'confirm_bulk' => __('Are you sure you want to classify %d products?', 'hts-manager'),
                'bulk_processing' => __('Processing %d of %d products...', 'hts-manager'),
                'bulk_complete' => __('Bulk classification complete!', 'hts-manager')
            ),
            'isPro' => $this->is_pro(),
            'usageStats' => $this->get_usage_stats()
        ));
    }
    
    // ===============================================
    // AJAX HANDLERS
    // ===============================================
    
    /**
     * AJAX handler for product classification
     */
    public function ajax_classify_product() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'hts_manager_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check capabilities
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $product_id = intval($_POST['product_id']);
        
        if (!$product_id) {
            wp_send_json_error(array('message' => 'Invalid product ID'));
            return;
        }
        
        try {
            // Check if user can classify
            $can_classify = $this->can_classify();
            if (!is_bool($can_classify)) {
                wp_send_json_error(array(
                    'message' => $can_classify['message'],
                    'upgrade_required' => $can_classify['upgrade_required'],
                    'usage_stats' => $this->get_usage_stats()
                ));
                return;
            }
            
            // Get product
            $product = wc_get_product($product_id);
            if (!$product) {
                wp_send_json_error(array('message' => 'Product not found'));
                return;
            }
            
            // Generate classification
            $result = $this->classify_product($product);
            
            if (is_wp_error($result)) {
                wp_send_json_error(array(
                    'message' => $result->get_error_message(),
                    'usage_stats' => $this->get_usage_stats()
                ));
                return;
            }
            
            // Update usage count
            $this->increment_usage();
            
            // Return success with updated stats
            wp_send_json_success(array(
                'hts_code' => $result['hts_code'],
                'confidence' => $result['confidence'],
                'explanation' => $result['explanation'],
                'usage_stats' => $this->get_usage_stats(),
                'message' => 'Product classified successfully!'
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Classification error: ' . $e->getMessage(),
                'usage_stats' => $this->get_usage_stats()
            ));
        }
    }
    
    /**
     * AJAX handler for API key testing
     */
    public function ajax_test_api_key() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'hts_manager_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $api_key = sanitize_text_field($_POST['api_key']);
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'API key is required'));
            return;
        }
        
        try {
            // Test API connection
            $test_result = $this->test_api_connection($api_key);
            
            if ($test_result) {
                wp_send_json_success(array(
                    'message' => 'API connection successful!',
                    'connected' => true
                ));
            } else {
                wp_send_json_error(array(
                    'message' => 'API connection failed. Please check your key.',
                    'connected' => false
                ));
            }
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Connection error: ' . $e->getMessage(),
                'connected' => false
            ));
        }
    }
    
    /**
     * AJAX handler for getting usage statistics
     */
    public function ajax_get_usage_stats() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'hts_manager_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        wp_send_json_success($this->get_usage_stats());
    }
    
    /**
     * AJAX handler for bulk classification
     */
    public function ajax_bulk_classify() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'hts_manager_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check capabilities
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        // Check if pro version required for bulk
        if (!$this->can_use_bulk_classify()) {
            wp_send_json_error(array(
                'message' => 'Bulk classification requires Pro version',
                'upgrade_required' => true
            ));
            return;
        }
        
        $product_ids = array_map('intval', $_POST['product_ids']);
        $processed = 0;
        $success_count = 0;
        $errors = array();
        
        foreach ($product_ids as $product_id) {
            $processed++;
            
            try {
                // Get product
                $product = wc_get_product($product_id);
                if (!$product) {
                    $errors[] = "Product {$product_id} not found";
                    continue;
                }
                
                // Check if already has code (skip if it does)
                $existing_code = get_post_meta($product_id, '_hts_code', true);
                if (!empty($existing_code)) {
                    continue; // Skip products that already have codes
                }
                
                // Generate classification
                $result = $this->classify_product($product);
                
                if (!is_wp_error($result)) {
                    $this->increment_usage();
                    $success_count++;
                }
                
                // Send progress update
                wp_send_json_success(array(
                    'progress' => true,
                    'processed' => $processed,
                    'total' => count($product_ids),
                    'success_count' => $success_count,
                    'product_name' => $product->get_name(),
                    'usage_stats' => $this->get_usage_stats()
                ));
                
            } catch (Exception $e) {
                $errors[] = "Product {$product_id}: " . $e->getMessage();
            }
            
            // Prevent timeout on large batches
            if ($processed % 10 === 0) {
                sleep(1);
            }
        }
        
        // Send final result
        wp_send_json_success(array(
            'complete' => true,
            'processed' => $processed,
            'success_count' => $success_count,
            'errors' => $errors,
            'usage_stats' => $this->get_usage_stats(),
            'message' => sprintf('Classified %d of %d products successfully', $success_count, count($product_ids))
        ));
    }
    
    /**
     * AJAX handler for saving manual HTS code
     */
    public function ajax_save_manual_code() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'hts_manager_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check capabilities
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $product_id = intval($_POST['product_id']);
        $hts_code = sanitize_text_field($_POST['hts_code']);
        $description = sanitize_textarea_field($_POST['description']);
        
        if (!$product_id) {
            wp_send_json_error(array('message' => 'Invalid product ID'));
            return;
        }
        
        try {
            // Validate HTS code format (basic validation)
            if (!empty($hts_code) && !preg_match('/^\d{4}\.\d{2}\.\d{2}(\.\d{2})?$/', $hts_code)) {
                wp_send_json_error(array('message' => 'Invalid HTS code format. Use format: 0000.00.00 or 0000.00.00.00'));
                return;
            }
            
            // Save the data
            update_post_meta($product_id, '_hts_code', $hts_code);
            update_post_meta($product_id, '_hts_description', $description);
            update_post_meta($product_id, '_hts_manually_set', 'yes');
            update_post_meta($product_id, '_hts_updated_date', current_time('Y-m-d H:i:s'));
            
            wp_send_json_success(array(
                'message' => 'HTS code saved successfully!',
                'hts_code' => $hts_code,
                'description' => $description
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Save error: ' . $e->getMessage()
            ));
        }
    }
    
    /**
     * Test API connection
     */
    private function test_api_connection($api_key) {
        // Simple test request to validate API key
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01'
            ),
            'body' => json_encode(array(
                'model' => 'claude-3-haiku-20240307',
                'max_tokens' => 10,
                'messages' => array(
                    array('role' => 'user', 'content' => 'Test')
                )
            )),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        return $response_code === 200;
    }
    
    // ===============================================
    // PRODUCT DATA TAB METHODS
    // ===============================================
    
    /**
     * Add HTS tab to product data metabox
     */
    public function add_product_data_tab($tabs) {
        $tabs['hts_codes'] = array(
            'label'    => __('HTS Codes', 'hts-manager'),
            'target'   => 'hts_codes_product_data',
            'class'    => array('show_if_simple', 'show_if_variable'),
            'priority' => 21,
        );
        return $tabs;
    }
    
    /**
     * Add content to HTS tab
     */
    public function add_product_data_fields() {
        global $post;
        
        // Check if product has an HTS code
        $hts_code = get_post_meta($post->ID, '_hts_code', true);
        $country_of_origin = get_post_meta($post->ID, '_country_of_origin', true);
        $hts_confidence = get_post_meta($post->ID, '_hts_confidence', true);
        $hts_updated = get_post_meta($post->ID, '_hts_updated', true);
        
        // Default country to Canada if not set
        if (empty($country_of_origin)) {
            $country_of_origin = 'CA';
        }
        ?>
        <div id="hts_codes_product_data" class="panel woocommerce_options_panel">
            <div class="hts-manager-fields">
                
                <?php wp_nonce_field('hts_product_nonce_action', 'hts_product_nonce'); ?>
                
                <!-- Usage Counter Display -->
                <div class="hts-form-group">
                    <div class="hts-usage-counter">
                        <div class="hts-usage-stats">
                            <span class="hts-usage-text">
                                Classifications: <span class="used"><?php echo $this->get_usage_count(); ?></span>
                                / <span class="limit"><?php echo $this->is_pro() ? '∞' : $this->get_classification_limit(); ?></span>
                            </span>
                            <?php if (!$this->is_pro()): ?>
                                <div class="hts-progress-container">
                                    <div class="hts-progress-bar" style="width: <?php echo min(100, round(($this->get_usage_count() / $this->get_classification_limit()) * 100)); ?>%"></div>
                                </div>
                            <?php endif; ?>
                            <?php if (!$this->is_pro()): ?>
                                <span class="hts-pro-badge">Upgrade to Pro for unlimited</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Current HTS Code Display -->
                <?php if (!empty($hts_code)): ?>
                <div class="current-code-display">
                    <div class="current-code"><?php echo esc_html($hts_code); ?></div>
                    <div class="current-description">
                        <?php 
                        $description = get_post_meta($post->ID, '_hts_description', true);
                        echo esc_html($description ? $description : 'No description available');
                        ?>
                    </div>
                    <?php if ($hts_confidence): ?>
                        <div class="confidence-info">
                            <?php 
                            $confidence_percent = round($hts_confidence * 100);
                            $confidence_class = $confidence_percent >= 85 ? 'high' : ($confidence_percent >= 60 ? 'medium' : 'low');
                            ?>
                            <span class="confidence confidence-<?php echo $confidence_class; ?>">
                                Confidence: <?php echo $confidence_percent; ?>%
                            </span>
                            <?php if ($hts_updated): ?>
                                <span class="updated-date">
                                    Updated: <?php echo date('M j, Y H:i', strtotime($hts_updated)); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="current-code-display">
                    <div class="no-code">No HTS code assigned</div>
                    <div class="current-description">Use AI classification or enter manually below</div>
                </div>
                <?php endif; ?>
                
                <!-- AI Classification Section -->
                <div class="hts-form-group">
                    <label><?php _e('AI Classification', 'hts-manager'); ?></label>
                    <div class="button-group">
                        <button type="button" class="button button-primary hts-classify-button" data-product-id="<?php echo $post->ID; ?>">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Generate with AI', 'hts-manager'); ?>
                        </button>
                        <?php if (!empty($hts_code)): ?>
                            <button type="button" class="button hts-regenerate-button" data-product-id="<?php echo $post->ID; ?>">
                                <span class="dashicons dashicons-controls-repeat"></span>
                                <?php _e('Regenerate', 'hts-manager'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="hts-result-container hts-hidden"></div>
                </div>
                
                <!-- Manual Entry Section -->
                <div class="hts-form-group">
                    <label for="hts_manual_code"><?php _e('Manual Entry', 'hts-manager'); ?></label>
                    <input type="text" 
                           id="hts_manual_code" 
                           name="_hts_code" 
                           class="hts-manual-code-input" 
                           value="<?php echo esc_attr($hts_code); ?>" 
                           placeholder="0000.00.00.00">
                    <textarea id="hts_manual_description" 
                              name="_hts_description" 
                              class="hts-description-input" 
                              placeholder="Description of the classification"
                              rows="3"><?php echo esc_textarea(get_post_meta($post->ID, '_hts_description', true)); ?></textarea>
                    <div class="button-group">
                        <button type="button" class="button hts-save-code-button" data-product-id="<?php echo $post->ID; ?>">
                            <span class="dashicons dashicons-saved"></span>
                            <?php _e('Save Manual Code', 'hts-manager'); ?>
                        </button>
                    </div>
                </div>
                
                <?php
                woocommerce_wp_select(array(
                    'id'          => '_country_of_origin',
                    'label'       => __('Country of Origin', 'hts-manager'),
                    'desc_tip'    => true,
                    'description' => __('Select the country where this product was manufactured or produced.', 'hts-manager'),
                    'value'       => $country_of_origin,
                    'options'     => array(
                        'CA' => __('Canada', 'hts-manager'),
                        'US' => __('United States', 'hts-manager'),
                        'MX' => __('Mexico', 'hts-manager'),
                        'CN' => __('China', 'hts-manager'),
                        'GB' => __('United Kingdom', 'hts-manager'),
                        'DE' => __('Germany', 'hts-manager'),
                        'FR' => __('France', 'hts-manager'),
                        'IT' => __('Italy', 'hts-manager'),
                        'JP' => __('Japan', 'hts-manager'),
                        'KR' => __('South Korea', 'hts-manager'),
                        'TW' => __('Taiwan', 'hts-manager'),
                        'IN' => __('India', 'hts-manager'),
                        'VN' => __('Vietnam', 'hts-manager'),
                        'TH' => __('Thailand', 'hts-manager'),
                        'OTHER' => __('Other', 'hts-manager'),
                    ),
                ));
                ?>
            </div>
            
            <div class="options_group">
                <p style="margin: 10px;">
                    <strong><?php _e('Information:', 'hts-manager'); ?></strong><br>
                    <?php _e('HTS codes are used for customs declarations and duty calculations when shipping internationally.', 'hts-manager'); ?>
                </p>
            </div>
            
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Main generate button handler
            function generateHTSCode(isRegenerate) {
                var button = $('#hts_generate_code');
                var spinner = $('#hts_generate_spinner');
                var message = $('#hts_generate_message');
                var regenerateLink = $('#hts_regenerate_link');
                var product_id = <?php echo $post->ID; ?>;
                
                // Show spinner, disable button
                button.prop('disabled', true);
                if (regenerateLink.length) {
                    regenerateLink.hide();
                }
                spinner.css('display', 'inline-block').addClass('is-active');
                message.hide().removeClass('success error');
                
                // AJAX call
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'hts_generate_single_code',
                        product_id: product_id,
                        nonce: '<?php echo wp_create_nonce('hts_generate_nonce'); ?>',
                        regenerate: isRegenerate ? 1 : 0
                    },
                    success: function(response) {
                        spinner.removeClass('is-active').hide();
                        
                        if (response.success) {
                            // Update the HTS code field
                            $('#_hts_code').val(response.data.hts_code);
                            
                            // Show success message
                            message.html('<span style="color: green;">✓ Generated: ' + response.data.hts_code + ' (' + Math.round(response.data.confidence * 100) + '% confidence)</span>');
                            message.addClass('success').show();
                            
                            // Keep button disabled since we now have a code
                            button.prop('disabled', true);
                            
                            // Show or create regenerate link
                            if (!regenerateLink.length) {
                                button.after(' <a href="#" id="hts_regenerate_link" style="margin-left: 10px; text-decoration: none;">Regenerate</a>');
                                bindRegenerateHandler();
                            } else {
                                regenerateLink.show();
                            }
                            
                            // Add or update confidence display
                            var confidenceColor = response.data.confidence >= 0.85 ? 'green' : (response.data.confidence >= 0.60 ? 'orange' : 'red');
                            var existingConfidence = $('.hts-confidence-display');
                            
                            if (existingConfidence.length) {
                                existingConfidence.find('span span').css('color', confidenceColor).text(Math.round(response.data.confidence * 100) + '%');
                            } else if (response.data.confidence) {
                                var confidenceHtml = '<p class="form-field hts-confidence-display">' +
                                    '<label>Confidence</label>' +
                                    '<span style="margin-left: 10px;">' +
                                    '<span style="color: ' + confidenceColor + '; font-weight: bold;">' +
                                    Math.round(response.data.confidence * 100) + '%' +
                                    '</span></span></p>';
                                $(confidenceHtml).insertAfter('#hts_generate_message').parent().parent();
                            }
                        } else {
                            var errorMessage = response.data.message;
                            
                            // Check if it's an upgrade needed error
                            if (response.data.upgrade_needed) {
                                // Show upgrade message with full details
                                if (response.data.upgrade_html) {
                                    message.html('<span style="color: red;">✗ ' + errorMessage + '</span>' + response.data.upgrade_html);
                                } else {
                                    errorMessage += '<br><a href="https://sonicpixel.ca/hts-manager-pro/" target="_blank" style="color: #2271b1; text-decoration: none;">Upgrade to Pro</a> for unlimited classifications.';
                                    message.html('<span style="color: red;">✗ ' + errorMessage + '</span>');
                                }
                            } else {
                                message.html('<span style="color: red;">✗ ' + errorMessage + '</span>');
                            }
                            
                            message.addClass('error').show();
                            
                            // If usage limit reached, disable button permanently for free version
                            if (response.data.upgrade_needed) {
                                button.prop('disabled', true).text('Limit Reached - Upgrade Required');
                                if (regenerateLink.length) {
                                    regenerateLink.hide();
                                }
                            } else {
                                // Re-enable button only if no code exists
                                if (!$('#_hts_code').val()) {
                                    button.prop('disabled', false);
                                }
                                if (regenerateLink.length) {
                                    regenerateLink.show();
                                }
                            }
                        }
                    },
                    error: function(xhr, status, error) {
                        spinner.removeClass('is-active').hide();
                        
                        // Re-enable button only if no code exists
                        if (!$('#_hts_code').val()) {
                            button.prop('disabled', false);
                        }
                        if (regenerateLink.length) {
                            regenerateLink.show();
                        }
                        
                        message.html('<span style="color: red;">✗ Error: ' + error + '</span>');
                        message.addClass('error').show();
                    }
                });
            }
            
            // Bind regenerate handler
            function bindRegenerateHandler() {
                $('#hts_regenerate_link').off('click').on('click', function(e) {
                    e.preventDefault();
                    if (confirm('Are you sure you want to regenerate the HTS code? This will overwrite the existing code.')) {
                        generateHTSCode(true);
                    }
                });
            }
            
            // Initial button click handler
            $('#hts_generate_code').on('click', function(e) {
                e.preventDefault();
                generateHTSCode(false);
            });
            
            // Bind regenerate if it exists on load
            bindRegenerateHandler();
            
            // Monitor HTS code field for manual changes
            $('#_hts_code').on('input', function() {
                var hasCode = $(this).val().trim().length > 0;
                $('#hts_generate_code').prop('disabled', hasCode);
                
                if (hasCode && !$('#hts_regenerate_link').length) {
                    $('#hts_generate_code').after(' <a href="#" id="hts_regenerate_link" style="margin-left: 10px; text-decoration: none;">Regenerate</a>');
                    bindRegenerateHandler();
                } else if (!hasCode && $('#hts_regenerate_link').length) {
                    $('#hts_regenerate_link').remove();
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Save HTS fields
     */
    public function save_product_data_fields($post_id) {
        // Security check
        if (!isset($_POST['hts_product_nonce']) || !wp_verify_nonce($_POST['hts_product_nonce'], 'hts_product_nonce_action')) {
            return;
        }
        
        // Save HTS code
        if (isset($_POST['_hts_code'])) {
            $hts_code = sanitize_text_field($_POST['_hts_code']);
            update_post_meta($post_id, '_hts_code', $hts_code);
        }
        
        // Save country of origin
        if (isset($_POST['_country_of_origin'])) {
            $country = sanitize_text_field($_POST['_country_of_origin']);
            update_post_meta($post_id, '_country_of_origin', $country);
        }
    }
    
    /**
     * Get upgrade message HTML
     * @param string $context The context for the upgrade message
     * @return string HTML for upgrade message
     */
    public function get_upgrade_message($context = 'general') {
        $upgrade_url = 'https://sonicpixel.ca/hts-manager-pro/';
        
        switch($context) {
            case 'limit_reached':
                return sprintf(
                    '<div class="hts-upgrade-box" style="background: #fff; border-left: 4px solid #d63638; padding: 20px; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h3 style="margin-top: 0; color: #d63638;">🚨 Classification Limit Reached</h3>
                        <p><strong>You\'ve used all 25 free classifications.</strong></p>
                        <p>Upgrade to HTS Manager Pro to unlock:</p>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <li>✅ <strong>Unlimited</strong> classifications</li>
                            <li>🚀 Bulk processing for multiple products</li>
                            <li>📊 Export features for reports</li>
                            <li>⚡ Priority support</li>
                            <li>🔄 Automatic updates</li>
                        </ul>
                        <p style="font-size: 18px; margin: 15px 0;"><strong>One-time payment: $67</strong> <span style="text-decoration: line-through; color: #999;">$97</span></p>
                        <a href="%s" target="_blank" class="button button-primary button-hero" style="margin-right: 10px;">🚀 Upgrade to Pro Now</a>
                        <a href="%s" target="_blank" class="button button-secondary">Learn More</a>
                    </div>',
                    $upgrade_url,
                    $upgrade_url
                );
                
            case 'bulk_processing':
                return sprintf(
                    '<div class="hts-upgrade-box" style="background: #f0f8ff; border-left: 4px solid #2271b1; padding: 20px; margin: 20px 0; border-radius: 4px;">
                        <h3 style="margin-top: 0; color: #2271b1;">🎯 Bulk Processing is a Pro Feature</h3>
                        <p>Process unlimited products at once with HTS Manager Pro!</p>
                        <div style="display: flex; gap: 20px; margin: 15px 0;">
                            <div style="flex: 1;">
                                <h4>Free Version</h4>
                                <ul style="list-style: none; padding: 0;">
                                    <li>❌ Max 5 products at once</li>
                                    <li>❌ 25 total classifications</li>
                                    <li>❌ Manual processing only</li>
                                </ul>
                            </div>
                            <div style="flex: 1;">
                                <h4 style="color: #00a32a;">Pro Version</h4>
                                <ul style="list-style: none; padding: 0;">
                                    <li>✅ Unlimited bulk processing</li>
                                    <li>✅ No classification limits</li>
                                    <li>✅ Queue hundreds at once</li>
                                </ul>
                            </div>
                        </div>
                        <p style="font-size: 16px;"><strong>Special Offer: $67</strong> (Save $30)</p>
                        <a href="%s" target="_blank" class="button button-primary">Unlock Bulk Processing</a>
                    </div>',
                    $upgrade_url
                );
                
            case 'export':
                return sprintf(
                    '<div class="hts-upgrade-box" style="background: #fff3e0; border-left: 4px solid #ff9800; padding: 20px; margin: 20px 0; border-radius: 4px;">
                        <h3 style="margin-top: 0; color: #ff9800;">📊 Export Features Available in Pro</h3>
                        <p>Export your HTS classifications to CSV, Excel, or PDF with Pro!</p>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <li>Export complete product catalogs with HTS codes</li>
                            <li>Generate customs documentation</li>
                            <li>Create compliance reports</li>
                            <li>Bulk export for accounting</li>
                        </ul>
                        <p><strong>Get Pro for just $67</strong> - Lifetime license, no recurring fees!</p>
                        <a href="%s" target="_blank" class="button button-primary">Get Export Features</a>
                    </div>',
                    $upgrade_url
                );
                
            case 'classification_success':
                $stats = $this->get_usage_stats();
                if (!$this->is_pro() && $stats['remaining'] <= 10) {
                    return sprintf(
                        '<div class="notice notice-info inline" style="margin-top: 20px;">
                            <p>💡 <strong>Only %d classifications remaining!</strong> 
                            <a href="%s" target="_blank">Upgrade to Pro</a> for unlimited classifications - just $67 one-time.</p>
                        </div>',
                        $stats['remaining'],
                        $upgrade_url
                    );
                }
                return '';
                
            default:
                return sprintf(
                    '<div class="hts-upgrade-cta" style="background: linear-gradient(135deg, #667eea 0%%, #764ba2 100%%); color: white; padding: 20px; margin: 20px 0; border-radius: 8px; text-align: center;">
                        <h3 style="color: white; margin-top: 0;">🚀 Unlock Full Power with HTS Manager Pro</h3>
                        <p style="font-size: 16px; margin: 10px 0;">Unlimited classifications • Bulk processing • Export features • Priority support</p>
                        <p style="font-size: 20px; margin: 15px 0;"><strong>One-time payment: $67</strong></p>
                        <a href="%s" target="_blank" class="button button-hero" style="background: white; color: #667eea; border: none; font-weight: bold;">Upgrade Now & Save $30</a>
                    </div>',
                    $upgrade_url
                );
        }
    }
    
    /**
     * Generate friendly limit reached screen
     * @return string HTML for limit reached screen
     */
    public function get_limit_reached_screen() {
        $stats = $this->get_usage_stats();
        $upgrade_url = 'https://sonicpixel.ca/hts-manager-pro/';
        
        ob_start();
        ?>
        <div class="hts-limit-reached-screen" style="max-width: 800px; margin: 40px auto; padding: 0;">
            <!-- Header Section -->
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 40px; border-radius: 12px 12px 0 0; text-align: center;">
                <div style="font-size: 4em; margin-bottom: 20px;">🎉</div>
                <h1 style="color: white; margin: 0 0 10px 0; font-size: 2.2em;">Congratulations!</h1>
                <p style="font-size: 1.3em; margin: 0; opacity: 0.9;">You've successfully classified <strong><?php echo $stats['used']; ?> products</strong> with HTS Manager!</p>
            </div>
            
            <!-- Content Section -->
            <div style="background: white; padding: 40px; border-radius: 0 0 12px 12px; box-shadow: 0 8px 25px rgba(0,0,0,0.1);">
                <div style="text-align: center; margin-bottom: 40px;">
                    <h2 style="color: #2c3e50; margin: 0 0 15px 0;">Ready to Unlock More Potential?</h2>
                    <p style="font-size: 1.1em; color: #666; line-height: 1.6;">
                        You've reached the free limit of 25 classifications. That's awesome progress! 
                        Now let's take your HTS management to the next level.
                    </p>
                </div>
                
                <!-- Stats Cards -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 40px 0;">
                    <div style="text-align: center; padding: 20px; background: #f8f9fa; border-radius: 8px; border: 2px solid #e9ecef;">
                        <div style="font-size: 2.5em; margin-bottom: 10px;">📊</div>
                        <div style="font-size: 1.8em; font-weight: bold; color: #28a745; margin-bottom: 5px;"><?php echo $stats['used']; ?></div>
                        <div style="color: #666; font-size: 0.9em;">Products Classified</div>
                    </div>
                    <div style="text-align: center; padding: 20px; background: #f8f9fa; border-radius: 8px; border: 2px solid #e9ecef;">
                        <div style="font-size: 2.5em; margin-bottom: 10px;">⚡</div>
                        <div style="font-size: 1.8em; font-weight: bold; color: #17a2b8; margin-bottom: 5px;">100%</div>
                        <div style="color: #666; font-size: 0.9em;">Free Limit Used</div>
                    </div>
                    <div style="text-align: center; padding: 20px; background: #fff3cd; border-radius: 8px; border: 2px solid #ffeaa7;">
                        <div style="font-size: 2.5em; margin-bottom: 10px;">🚀</div>
                        <div style="font-size: 1.8em; font-weight: bold; color: #f39c12; margin-bottom: 5px;">∞</div>
                        <div style="color: #666; font-size: 0.9em;">Pro Limit</div>
                    </div>
                </div>
                
                <!-- Feature Comparison -->
                <div style="margin: 40px 0;">
                    <h3 style="text-align: center; color: #2c3e50; margin-bottom: 30px;">See What You're Missing</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                        <!-- Free Column -->
                        <div>
                            <div style="text-align: center; margin-bottom: 20px;">
                                <h4 style="color: #6c757d; margin: 0;">Free Version</h4>
                                <div style="background: #6c757d; color: white; padding: 8px 20px; border-radius: 20px; display: inline-block; margin-top: 10px; font-size: 0.9em;">What You Have</div>
                            </div>
                            <ul style="list-style: none; padding: 0;">
                                <li style="padding: 12px; margin: 8px 0; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #dc3545;">
                                    <span style="color: #dc3545; margin-right: 10px;">✓</span> 25 classifications total
                                </li>
                                <li style="padding: 12px; margin: 8px 0; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #dc3545;">
                                    <span style="color: #dc3545; margin-right: 10px;">✓</span> Manual classification
                                </li>
                                <li style="padding: 12px; margin: 8px 0; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #dc3545;">
                                    <span style="color: #dc3545; margin-right: 10px;">✓</span> Basic support
                                </li>
                            </ul>
                        </div>
                        
                        <!-- Pro Column -->
                        <div>
                            <div style="text-align: center; margin-bottom: 20px;">
                                <h4 style="color: #28a745; margin: 0;">Pro Version</h4>
                                <div style="background: #28a745; color: white; padding: 8px 20px; border-radius: 20px; display: inline-block; margin-top: 10px; font-size: 0.9em;">Upgrade & Get</div>
                            </div>
                            <ul style="list-style: none; padding: 0;">
                                <li style="padding: 12px; margin: 8px 0; background: #d4edda; border-radius: 6px; border-left: 4px solid #28a745;">
                                    <span style="color: #28a745; margin-right: 10px;">✓</span> <strong>Unlimited classifications</strong>
                                </li>
                                <li style="padding: 12px; margin: 8px 0; background: #d4edda; border-radius: 6px; border-left: 4px solid #28a745;">
                                    <span style="color: #28a745; margin-right: 10px;">✓</span> <strong>Bulk processing</strong> (hundreds at once)
                                </li>
                                <li style="padding: 12px; margin: 8px 0; background: #d4edda; border-radius: 6px; border-left: 4px solid #28a745;">
                                    <span style="color: #28a745; margin-right: 10px;">✓</span> <strong>Export features</strong> (CSV, Excel, PDF)
                                </li>
                                <li style="padding: 12px; margin: 8px 0; background: #d4edda; border-radius: 6px; border-left: 4px solid #28a745;">
                                    <span style="color: #28a745; margin-right: 10px;">✓</span> <strong>Priority support</strong>
                                </li>
                                <li style="padding: 12px; margin: 8px 0; background: #d4edda; border-radius: 6px; border-left: 4px solid #28a745;">
                                    <span style="color: #28a745; margin-right: 10px;">✓</span> <strong>Automatic updates</strong>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <!-- Pricing Section -->
                <div style="text-align: center; margin: 40px 0; padding: 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; color: white;">
                    <h3 style="color: white; margin: 0 0 15px 0;">Special Launch Pricing</h3>
                    <div style="font-size: 3em; font-weight: bold; margin: 10px 0;">$67</div>
                    <div style="font-size: 1.2em; opacity: 0.8; margin-bottom: 5px;">
                        <span style="text-decoration: line-through;">$97</span> Save $30
                    </div>
                    <div style="font-size: 1.1em; opacity: 0.9;">One-time payment • Lifetime license • No recurring fees</div>
                </div>
                
                <!-- Action Buttons -->
                <div style="text-align: center; margin: 40px 0;">
                    <a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" 
                       class="button button-primary button-hero" 
                       style="font-size: 1.2em; padding: 15px 40px; margin: 0 10px; text-decoration: none;">
                        🚀 Upgrade to Pro Now
                    </a>
                    <a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" 
                       class="button button-secondary button-large" 
                       style="padding: 15px 30px; margin: 0 10px; text-decoration: none;">
                        Learn More
                    </a>
                </div>
                
                <!-- Admin Reset Option -->
                <?php if (current_user_can('manage_options')): ?>
                <div style="margin-top: 50px; padding: 20px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">
                    <h4 style="color: #856404; margin: 0 0 10px 0;">🔧 Admin Testing Options</h4>
                    <p style="color: #856404; margin: 0 0 15px 0;">For testing purposes only:</p>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('hts_reset_usage', 'hts_reset_nonce'); ?>
                        <input type="submit" name="reset_usage" class="button button-secondary" 
                               value="Reset Usage Counter" 
                               onclick="return confirm('This will reset the usage counter to 0. Continue?');">
                        <span style="color: #856404; margin-left: 15px; font-size: 0.9em;">
                            This will allow testing the free version again
                        </span>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Footer Note -->
                <div style="text-align: center; margin-top: 40px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                    <p style="color: #666; margin: 0; font-size: 0.95em;">
                        💡 <strong>Not ready to upgrade?</strong> No problem! You can continue using the free version for viewing existing HTS codes. 
                        When you're ready for more classifications, Pro will be waiting for you.
                    </p>
                </div>
            </div>
        </div>
        
        <style>
        .hts-limit-reached-screen {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .hts-limit-reached-screen .button {
            transition: all 0.3s ease;
        }
        .hts-limit-reached-screen .button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        @media (max-width: 768px) {
            .hts-limit-reached-screen > div:first-child,
            .hts-limit-reached-screen > div:last-child {
                margin: 20px 10px;
                padding: 20px;
            }
            .hts-limit-reached-screen [style*="grid-template-columns"] {
                grid-template-columns: 1fr !important;
            }
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Display usage limit admin notices
     */
    public function usage_limit_notices() {
        // Only show on relevant admin pages
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['dashboard', 'edit-product', 'product', 'woocommerce_page_hts-manager'])) {
            return;
        }
        
        // Only show to users who can manage WooCommerce
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        // Don't show to pro users
        if ($this->is_pro()) {
            return;
        }
        
        $stats = $this->get_usage_stats();
        $remaining = $stats['remaining'];
        
        // Critical - limit reached
        if ($remaining <= 0) {
            ?>
            <div class="notice notice-error">
                <p><strong>🚨 HTS Manager: Classification limit reached!</strong></p>
                <p>You've used all <?php echo $stats['limit']; ?> free classifications. 
                <a href="#" style="text-decoration: none; color: #2271b1;"><strong>Upgrade to Pro</strong></a> for unlimited classifications and advanced features.</p>
            </div>
            <?php
        }
        // Warning - very low remaining
        elseif ($remaining <= 3) {
            ?>
            <div class="notice notice-warning">
                <p><strong>⚠️ HTS Manager: Only <?php echo $remaining; ?> classifications remaining!</strong></p>
                <p><a href="#" style="text-decoration: none; color: #2271b1;">Upgrade to Pro</a> before you run out to avoid interruptions.</p>
            </div>
            <?php
        }
        // Info - getting close to limit
        elseif ($remaining <= 5) {
            ?>
            <div class="notice notice-info">
                <p><strong>📊 HTS Manager: <?php echo $remaining; ?> classifications remaining</strong></p>
                <p>You're using <?php echo $stats['used']; ?>/<?php echo $stats['limit']; ?> free classifications. 
                <a href="#" style="text-decoration: none;">Consider upgrading to Pro</a> for unlimited usage.</p>
            </div>
            <?php
        }
    }
    
    /**
     * Display admin notices for auto-classification errors
     */
    public function auto_classification_error_notices() {
        // Only show to administrators
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Only show on relevant pages
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['dashboard', 'edit-product', 'woocommerce_page_hts-manager'])) {
            return;
        }
        
        // Check for auto-classification errors
        $error_products = get_transient('hts_auto_classify_errors');
        if (empty($error_products)) {
            return;
        }
        
        // Limit display to most recent errors
        $error_products = array_slice($error_products, -5, 5, true);
        
        ?>
        <div class="notice notice-warning is-dismissible hts-auto-error-notice">
            <h3><?php esc_html_e('HTS Auto-Classification Issues', 'hts-manager'); ?></h3>
            <p><?php esc_html_e('Some products could not be automatically classified:', 'hts-manager'); ?></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <?php foreach ($error_products as $product_id => $error_info): ?>
                <li>
                    <strong><?php echo esc_html($error_info['product_name']); ?></strong>: 
                    <?php echo esc_html($error_info['error']); ?>
                    <small style="color: #666;">
                        (<?php echo esc_html(human_time_diff(strtotime($error_info['time']), current_time('timestamp'))); ?> ago)
                    </small>
                </li>
                <?php endforeach; ?>
            </ul>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=hts-manager')); ?>" class="button button-secondary">
                    <?php esc_html_e('View HTS Manager Settings', 'hts-manager'); ?>
                </a>
                <button type="button" class="button button-link" onclick="this.closest('.notice').style.display='none';">
                    <?php esc_html_e('Dismiss', 'hts-manager'); ?>
                </button>
            </p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Auto-dismiss after 30 seconds
            setTimeout(function() {
                $('.hts-auto-error-notice').fadeOut();
            }, 30000);
        });
        </script>
        <?php
    }
}

// ===============================================
// END OF HTS_MANAGER CLASS
// ===============================================


// ===============================================
// PART 2: AJAX HANDLER FOR SINGLE PRODUCT
// ===============================================

add_action('wp_ajax_hts_generate_single_code', 'hts_ajax_generate_single_code');
function hts_ajax_generate_single_code() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'hts_generate_nonce')) {
        wp_send_json_error(array('message' => esc_html__('Security check failed', 'hts-manager')));
        return;
    }
    
    // Check permissions
    if (!current_user_can('edit_products')) {
        wp_send_json_error(array('message' => esc_html__('Insufficient permissions', 'hts-manager')));
        return;
    }
    
    // Validate and sanitize product ID
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    if (!$product_id || get_post_type($product_id) !== 'product') {
        wp_send_json_error(array('message' => esc_html__('Invalid product ID', 'hts-manager')));
        return;
    }
    
    // Check classification limits for free version
    $hts_manager = new HTS_Manager();
    $can_classify = $hts_manager->can_classify();
    
    if ($can_classify !== true) {
        // Usage limit reached - send detailed upgrade message
        wp_send_json_error(array(
            'message' => $can_classify['message'],
            'upgrade_needed' => true,
            'used' => $can_classify['used'],
            'limit' => $can_classify['limit'],
            'upgrade_html' => '<div style="margin-top: 20px; padding: 20px; background: linear-gradient(135deg, #667eea 20%, #764ba2 80%); color: white; border-radius: 8px; text-align: center;">
                <div style="font-size: 2em; margin-bottom: 15px;">🎉</div>
                <h3 style="color: white; margin: 0 0 10px 0;">Congratulations!</h3>
                <p style="margin: 0 0 15px 0;">You\'ve successfully classified 25 products!</p>
                <p style="margin: 0 0 15px 0; opacity: 0.9;">Ready for unlimited classifications?</p>
                <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 6px; margin: 15px 0;">
                    <div style="font-size: 1.4em; font-weight: bold;">$67 one-time</div>
                    <div style="font-size: 0.9em; opacity: 0.8;">Save $30 • Lifetime license</div>
                </div>
                <a href="https://sonicpixel.ca/hts-manager-pro/" target="_blank" class="button" style="background: white; color: #667eea; border: none; font-weight: bold; padding: 10px 20px; margin-top: 10px;">🚀 Upgrade Now</a>
            </div>'
        ));
        return;
    }
    
    // Get API key
    $api_key = get_option('hts_anthropic_api_key');
    if (empty($api_key)) {
        wp_send_json_error(array('message' => 'API key not configured. Please configure in WooCommerce → HTS Manager'));
        return;
    }
    
    // Get product
    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(array('message' => 'Product not found'));
        return;
    }
    
    // Generate HTS code with comprehensive error handling
    $result = hts_classify_product($product_id, $api_key);
    
    // Check if result is an error (new error handling format)
    if (isset($result['type']) && $result['type'] === 'error') {
        wp_send_json_error(array(
            'message' => $result['message'],
            'action' => $result['action'] ?? '',
            'upgrade_needed' => false
        ));
        return;
    }
    
    // Check for successful classification (backward compatibility)
    if ($result && isset($result['hts_code'])) {
        try {
            // Save the results
            update_post_meta($product_id, '_hts_code', sanitize_text_field($result['hts_code']));
            update_post_meta($product_id, '_hts_confidence', floatval($result['confidence']));
            update_post_meta($product_id, '_hts_updated', current_time('mysql'));
            update_post_meta($product_id, '_country_of_origin', 'CA');
            
            // Increment usage counter for free version tracking
            if (!$hts_manager->is_pro()) {
                $new_count = $hts_manager->increment_usage();
            }
            
            // Log the successful operation
            HTS_Error_Handler::log_success(
                'AJAX classification completed',
                array(
                    'product_id' => $product_id,
                    'hts_code' => $result['hts_code'],
                    'confidence' => $result['confidence']
                )
            );
            
            wp_send_json_success(array(
                'hts_code' => $result['hts_code'],
                'confidence' => $result['confidence'],
                'reasoning' => $result['reasoning'] ?? '',
                'message' => __('HTS code generated successfully!', 'hts-manager')
            ));
            
        } catch (Exception $e) {
            HTS_Error_Handler::log_error(
                'ajax_save_error',
                'Failed to save classification results: ' . $e->getMessage(),
                array('product_id' => $product_id)
            );
            
            wp_send_json_error(array(
                'message' => __('Classification generated but failed to save. Please try again.', 'hts-manager')
            ));
        }
    } else {
        // Fallback for unexpected result format
        $error_data = HTS_Error_Handler::get_user_friendly_message('classification_failed');
        wp_send_json_error(array(
            'message' => $error_data['message'],
            'action' => $error_data['action']
        ));
    }
}

// ===============================================
// PART 3: CLASSIFICATION FUNCTION
// ===============================================

function hts_classify_product($product_id, $api_key) {
    try {
        // Validate inputs
        if (!$product_id || !is_numeric($product_id)) {
            return HTS_Error_Handler::handle_error(
                'product_data_invalid',
                'Invalid product ID provided',
                array('product_id' => $product_id)
            );
        }
        
        if (empty($api_key) || !is_string($api_key)) {
            return HTS_Error_Handler::handle_error(
                'api_key_missing',
                'API key is empty or invalid',
                array('api_key_length' => strlen($api_key))
            );
        }
        
        $product = wc_get_product($product_id);
        if (!$product || !is_a($product, 'WC_Product')) {
            return HTS_Error_Handler::handle_error(
                'product_data_invalid',
                'Product not found or invalid',
                array('product_id' => $product_id)
            );
        }
        
        $context = array(
            'product_id' => $product_id,
            'product_name' => $product->get_name()
        );
    
    // Prepare product data
    $product_data = array(
        'name' => $product->get_name(),
        'description' => $product->get_description(),
        'short_description' => $product->get_short_description(),
        'sku' => $product->get_sku(),
        'categories' => wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names')),
        'tags' => wp_get_post_terms($product_id, 'product_tag', array('fields' => 'names')),
        'price' => $product->get_price(),
        'weight' => $product->get_weight(),
    );
    
    // Build prompt
    $prompt = "You are an expert in Harmonized Tariff Schedule (HTS) classification for US imports. 
Analyze this product and provide the most accurate 10-digit HTS code.

PRODUCT INFORMATION:
Name: {$product_data['name']}
SKU: {$product_data['sku']}
Description: " . substr($product_data['description'], 0, 1000) . "
Categories: " . implode(', ', $product_data['categories']) . "

IMPORTANT RULES:
1. Provide the full 10-digit HTS code (format: ####.##.####)
2. Consider the product's primary function and material composition
3. Use the most specific classification available
4. If uncertain between codes, choose the one with higher duty rate (conservative approach)

Respond in this exact JSON format:
{
    \"hts_code\": \"####.##.####\",
    \"confidence\": 0.0 to 1.0,
    \"reasoning\": \"Brief explanation\"
}";
    
        // Validate product has sufficient data
        $product_name = $product->get_name();
        $product_description = $product->get_description() ?: $product->get_short_description();
        
        if (empty($product_name) || strlen(trim($product_name)) < 3) {
            return HTS_Error_Handler::handle_error(
                'product_data_invalid',
                'Product name too short or missing',
                array_merge($context, array('name_length' => strlen($product_name)))
            );
        }
    
    // Call Claude API with comprehensive error handling
    $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'x-api-key' => sanitize_text_field($api_key),
            'anthropic-version' => '2023-06-01',
            'User-Agent' => 'HTS-Manager/' . HTS_MANAGER_VERSION . ' WordPress/' . get_bloginfo('version')
        ),
        'body' => wp_json_encode(array(
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 500,
            'temperature' => 0.2,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => wp_kses_post($prompt)
                )
            )
        )),
        'timeout' => 45,
        'sslverify' => true,
        'httpversion' => '1.1'
    ));
    
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            
            // Determine specific error type
            $error_type = 'network_error';
            if (strpos($error_message, 'cURL error 6') !== false || strpos($error_message, 'name resolution') !== false) {
                $error_type = 'network_error';
            } elseif (strpos($error_message, 'timeout') !== false) {
                $error_type = 'network_error';
            }
            
            return HTS_Error_Handler::handle_error(
                $error_type,
                'WordPress HTTP API error: ' . $error_message,
                $context
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Handle specific HTTP error codes
        if ($response_code !== 200) {
            $error_type = 'server_error';
            $technical_msg = 'HTTP ' . $response_code . ': ' . $body;
            
            switch ($response_code) {
                case 401:
                    $error_type = 'api_key_invalid';
                    break;
                case 402:
                    $error_type = 'quota_exceeded';
                    break;
                case 429:
                    $error_type = 'api_rate_limit';
                    break;
                case 500:
                case 502:
                case 503:
                case 504:
                    $error_type = 'server_error';
                    break;
                default:
                    $error_type = 'classification_failed';
                    break;
            }
            
            return HTS_Error_Handler::handle_error(
                $error_type,
                $technical_msg,
                array_merge($context, array('http_code' => $response_code))
            );
        }
        
        // Validate response has content
        if (empty($body)) {
            return HTS_Error_Handler::handle_error(
                'classification_failed',
                'Empty API response body',
                $context
            );
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return HTS_Error_Handler::handle_error(
                'classification_failed',
                'JSON decode error: ' . json_last_error_msg(),
                array_merge($context, array('response_body' => substr($body, 0, 200)))
            );
        }
        
        // Process API response
        if (isset($data['content'][0]['text'])) {
            $response_text = $data['content'][0]['text'];
            
            // Extract JSON from response
            if (preg_match('/\{.*\}/s', $response_text, $matches)) {
                $result = json_decode($matches[0], true);
                
                if (isset($result['hts_code']) && preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $result['hts_code'])) {
                    // Log successful classification
                    HTS_Error_Handler::log_success(
                        'Product classified successfully',
                        array_merge($context, array(
                            'hts_code' => $result['hts_code'],
                            'confidence' => $result['confidence']
                        ))
                    );
                    
                    return $result;
                }
            }
        }
        
        // If we get here, classification failed to parse properly
        return HTS_Error_Handler::handle_error(
            'classification_failed',
            'Failed to parse valid HTS code from API response',
            array_merge($context, array('response_text' => substr($response_text ?? '', 0, 200)))
        );
        
    } catch (Exception $e) {
        // Catch any unexpected PHP errors
        return HTS_Error_Handler::handle_error(
            'classification_failed',
            'PHP Exception: ' . $e->getMessage(),
            array_merge($context, array(
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine()
            ))
        );
    } catch (Error $e) {
        // Catch PHP 7+ errors
        return HTS_Error_Handler::handle_error(
            'classification_failed',
            'PHP Error: ' . $e->getMessage(),
            array_merge($context, array(
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine()
            ))
        );
    }
}

// ===============================================
// PART 4: AUTO-CLASSIFICATION ON PUBLISH/UPDATE
// ===============================================

// Hook into both status transitions and save_post for better coverage
add_action('transition_post_status', 'hts_auto_classify_on_publish', 10, 3);
function hts_auto_classify_on_publish($new_status, $old_status, $post) {
    // Check if enabled
    if (get_option('hts_auto_classify_enabled', '1') !== '1') {
        return;
    }
    
    // Only process products that are published
    if ($post->post_type !== 'product' || $new_status !== 'publish') {
        return;
    }
    
    // Check if already has HTS code
    $existing_hts = get_post_meta($post->ID, '_hts_code', true);
    if (!empty($existing_hts) && $existing_hts !== '9999.99.9999') {
        return;
    }
    
    // Schedule classification (avoid duplicates by using unique action name)
    $hook = 'hts_classify_product_cron';
    $args = array($post->ID);
    
    // Clear any existing scheduled event for this product
    wp_clear_scheduled_hook($hook, $args);
    
    // Schedule new classification
    wp_schedule_single_event(time() + 5, $hook, $args);
}

// Also hook into save_post for products that are already published
add_action('save_post_product', 'hts_auto_classify_on_save', 10, 3);
function hts_auto_classify_on_save($post_id, $post, $update) {
    // Skip if not an update or if it's an autosave
    if (!$update || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    
    // Check if enabled
    if (get_option('hts_auto_classify_enabled', '1') !== '1') {
        return;
    }
    
    // Only process published products
    if ($post->post_status !== 'publish') {
        return;
    }
    
    // Check if already has HTS code
    $existing_hts = get_post_meta($post_id, '_hts_code', true);
    if (!empty($existing_hts) && $existing_hts !== '9999.99.9999') {
        return;
    }
    
    // Schedule classification (avoid duplicates)
    $hook = 'hts_classify_product_cron';
    $args = array($post_id);
    
    // Clear any existing scheduled event for this product
    wp_clear_scheduled_hook($hook, $args);
    
    // Schedule new classification
    wp_schedule_single_event(time() + 5, $hook, $args);
}

add_action('hts_classify_product_cron', 'hts_run_scheduled_classification');
function hts_run_scheduled_classification($product_id) {
    try {
        $api_key = get_option('hts_anthropic_api_key');
        if (empty($api_key)) {
            HTS_Error_Handler::log_error(
                'auto_classify_no_api_key',
                'Skipping auto-classification - no API key configured',
                array('product_id' => $product_id)
            );
            return;
        }
        
        // Check if we can classify (respects usage limits)
        $hts_manager = new HTS_Manager();
        $can_classify = $hts_manager->can_classify();
        
        if ($can_classify !== true) {
            // Skip classification if limit reached
            HTS_Error_Handler::log_error(
                'auto_classify_limit_reached',
                'Skipping auto-classification - usage limit reached',
                array('product_id' => $product_id, 'limit_info' => $can_classify)
            );
            return;
        }
        
        // Attempt classification with comprehensive error handling
        $result = hts_classify_product($product_id, $api_key);
        
        // Handle new error format
        if (isset($result['type']) && $result['type'] !== 'success') {
            // Store error information for admin review
            update_post_meta($product_id, '_hts_classification_error', $result['message']);
            update_post_meta($product_id, '_hts_error_time', current_time('mysql'));
            
            // Set a transient for admin notification
            $error_products = get_transient('hts_auto_classify_errors') ?: array();
            $error_products[$product_id] = array(
                'product_name' => get_the_title($product_id),
                'error' => $result['message'],
                'time' => current_time('mysql')
            );
            set_transient('hts_auto_classify_errors', $error_products, DAY_IN_SECONDS);
            
            return;
        }
        
        // Handle successful classification
        if ($result && isset($result['hts_code'])) {
            update_post_meta($product_id, '_hts_code', sanitize_text_field($result['hts_code']));
            update_post_meta($product_id, '_hts_confidence', floatval($result['confidence']));
            update_post_meta($product_id, '_hts_updated', current_time('mysql'));
            update_post_meta($product_id, '_country_of_origin', 'CA');
            
            // Clear any previous error
            delete_post_meta($product_id, '_hts_classification_error');
            delete_post_meta($product_id, '_hts_error_time');
            
            // Increment usage counter for free version tracking
            if (!$hts_manager->is_pro()) {
                $hts_manager->increment_usage();
            }
            
            // Notify admin if low confidence
            if ($result['confidence'] < 0.60) {
                hts_notify_admin_low_confidence($product_id, $result);
            }
            
            HTS_Error_Handler::log_success(
                'Auto-classification completed',
                array(
                    'product_id' => $product_id,
                    'product_name' => get_the_title($product_id),
                    'hts_code' => $result['hts_code'],
                    'confidence' => $result['confidence']
                )
            );
        }
        
    } catch (Exception $e) {
        HTS_Error_Handler::log_error(
            'auto_classify_exception',
            'Exception during auto-classification: ' . $e->getMessage(),
            array(
                'product_id' => $product_id,
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine()
            )
        );
    }
}

function hts_notify_admin_low_confidence($product_id, $result) {
    $product = wc_get_product($product_id);
    $admin_email = get_option('admin_email');
    
    $subject = 'HTS Classification Needs Review';
    $message = "A product was automatically classified with low confidence:\n\n";
    $message .= "Product: {$product->get_name()}\n";
    $message .= "SKU: {$product->get_sku()}\n";
    $message .= "HTS Code: {$result['hts_code']}\n";
    $message .= "Confidence: " . ($result['confidence'] * 100) . "%\n";
    $message .= "Reasoning: {$result['reasoning']}\n\n";
    $message .= "Please review: " . get_edit_post_link($product_id);
    
    wp_mail($admin_email, $subject, $message);
}


// ===============================================
// PART 6: ADMIN SETTINGS PAGE
// ===============================================

add_action('admin_menu', 'hts_manager_menu');
function hts_manager_menu() {
    add_submenu_page(
        'woocommerce',
        'HTS Manager',
        'HTS Manager',
        'manage_woocommerce',
        'hts-manager',
        'hts_manager_settings_page'
    );
}

function hts_manager_settings_page() {
    // Save settings
    if (isset($_POST['submit']) && wp_verify_nonce($_POST['hts_nonce'], 'hts_settings')) {
        update_option('hts_anthropic_api_key', sanitize_text_field($_POST['api_key']));
        update_option('hts_auto_classify_enabled', isset($_POST['enabled']) ? '1' : '0');
        update_option('hts_confidence_threshold', floatval($_POST['confidence_threshold']));
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    
    // Handle usage reset
    if (isset($_POST['reset_usage']) && wp_verify_nonce($_POST['hts_reset_nonce'], 'hts_reset_usage') && current_user_can('manage_options')) {
        $hts_manager_temp = new HTS_Manager();
        $hts_manager_temp->reset_usage_count();
        echo '<div class="notice notice-success"><p>Usage counter has been reset to 0.</p></div>';
    }
    
    // Handle test classification
    if (isset($_POST['test_classify']) && wp_verify_nonce($_POST['hts_test_nonce'], 'hts_test')) {
        $test_product_id = intval($_POST['test_product_id']);
        if ($test_product_id > 0) {
            $api_key = get_option('hts_anthropic_api_key');
            if ($api_key) {
                echo '<div class="notice notice-info"><p>Testing classification for product ID: ' . $test_product_id . '</p></div>';
                
                $result = hts_classify_product($test_product_id, $api_key);
                
                if ($result && isset($result['hts_code'])) {
                    update_post_meta($test_product_id, '_hts_code', $result['hts_code']);
                    update_post_meta($test_product_id, '_hts_confidence', $result['confidence']);
                    update_post_meta($test_product_id, '_hts_updated', current_time('mysql'));
                    
                    echo '<div class="notice notice-success"><p>✓ Classification successful! HTS Code: ' . $result['hts_code'] . ' (Confidence: ' . round($result['confidence'] * 100) . '%)</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>✗ Classification failed. Please check your API key and try again.</p></div>';
                }
            }
        }
    }
    
    $api_key = get_option('hts_anthropic_api_key', '');
    $enabled = get_option('hts_auto_classify_enabled', '1');
    $threshold = get_option('hts_confidence_threshold', 0.60);
    ?>
    <div class="wrap">
        <h1>HTS Manager for WooCommerce</h1>
        
        <div class="notice notice-info">
            <p><strong>Complete HTS Management System</strong> - This plugin handles HTS code display and auto-classification.</p>
        </div>
        
        <?php
        $hts_manager = new HTS_Manager();
        $stats = $hts_manager->get_usage_stats();
        
        if (!$hts_manager->is_pro()) {
            $progress_color = $stats['percentage_used'] >= 90 ? '#d63638' : ($stats['percentage_used'] >= 70 ? '#dba617' : '#00a32a');
            ?>
            <div class="notice notice-warning">
                <p><strong>Free Version:</strong> <?php echo $stats['used']; ?>/<?php echo $stats['limit']; ?> classifications used 
                (<?php echo $stats['percentage_used']; ?>%). 
                <strong><?php echo $stats['remaining']; ?> remaining.</strong>
                <a href="https://sonicpixel.ca/hts-manager-pro/" target="_blank" style="text-decoration: none;">Upgrade to Pro</a> for unlimited classifications.</p>
                
                <div style="margin: 10px 0;">
                    <div style="width: 200px; height: 10px; background: #f0f0f0; border-radius: 5px; overflow: hidden;">
                        <div style="height: 100%; background: <?php echo $progress_color; ?>; width: <?php echo $stats['percentage_used']; ?>%; transition: width 0.3s;"></div>
                    </div>
                </div>
                
                <?php if ($stats['remaining'] <= 5): ?>
                <p style="color: #d63638; font-weight: bold;">
                    ⚠️ Warning: Only <?php echo $stats['remaining']; ?> classifications remaining!
                </p>
                <?php endif; ?>
            </div>
            <?php
        } else {
            ?>
            <div class="notice notice-success">
                <p><strong>Pro Version Active</strong> - Unlimited classifications and all features available!</p>
                <p><small>Total classifications performed: <?php echo $stats['used']; ?></small></p>
            </div>
            <?php
        }
        ?>
        
        <?php if (empty($api_key)): ?>
        <div class="notice notice-warning">
            <p><strong>⚠️ Setup Required:</strong> Please add your Anthropic API key below to enable auto-classification.</p>
        </div>
        <?php endif; ?>
        
        <div class="hts-container">
            <!-- API Configuration Card -->
            <div class="hts-card">
                <h2>API Configuration</h2>
                <form method="post">
                    <?php wp_nonce_field('hts_settings', 'hts_nonce'); ?>
                    
                    <div class="hts-form-group">
                        <label for="api_key">Anthropic API Key</label>
                        <input type="password" 
                               id="api_key" 
                               name="api_key" 
                               class="hts-api-key-input" 
                               value="<?php echo esc_attr($api_key); ?>" 
                               placeholder="sk-ant-api03-..."
                               style="width: 100%; max-width: 500px;">
                        <div class="button-group" style="margin-top: 10px;">
                            <button type="button" class="button hts-test-api-button">
                                <span class="dashicons dashicons-cloud"></span>
                                Test Connection
                            </button>
                            <button type="button" class="button" id="show-api-key">
                                <span class="dashicons dashicons-visibility"></span>
                                Show/Hide Key
                            </button>
                        </div>
                        <p class="description">
                            Get your API key from <a href="https://console.anthropic.com/" target="_blank">Anthropic Console</a>. 
                            The free tier includes generous usage limits.
                        </p>
                    </div>
                    
                    <div class="hts-form-group">
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked($enabled, '1'); ?>>
                            Enable Auto-Classification
                        </label>
                        <p class="description">Automatically generate HTS codes when products are published or updated.</p>
                    </div>
                    
                    <div class="hts-form-group">
                        <label for="confidence_threshold">Confidence Threshold</label>
                        <input type="range" 
                               id="confidence_threshold" 
                               name="confidence_threshold" 
                               min="0.3" 
                               max="0.95" 
                               step="0.05" 
                               value="<?php echo esc_attr($threshold); ?>"
                               style="width: 300px;">
                        <span id="threshold_display"><?php echo round($threshold * 100); ?>%</span>
                        <p class="description">Minimum confidence level required for auto-classification to proceed.</p>
                    </div>
                    
                    <div class="button-group">
                        <button type="submit" name="submit" class="button button-primary">
                            <span class="dashicons dashicons-saved"></span>
                            Save Settings
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Usage Statistics Card -->
            <div class="hts-card">
                <h2>Usage Statistics</h2>
                <div class="hts-usage-counter">
                    <div class="hts-usage-stats">
                        <span class="hts-usage-text">
                            Classifications: <span class="used"><?php echo $stats['used']; ?></span>
                            / <span class="limit"><?php echo $hts_manager->is_pro() ? '∞' : $stats['limit']; ?></span>
                        </span>
                        <?php if (!$hts_manager->is_pro()): ?>
                            <div class="hts-progress-container">
                                <div class="hts-progress-bar <?php echo $stats['percentage_used'] >= 80 ? 'danger' : ($stats['percentage_used'] >= 60 ? 'warning' : ''); ?>" 
                                     style="width: <?php echo min(100, $stats['percentage_used']); ?>%"></div>
                            </div>
                            <span class="hts-usage-remaining <?php echo $stats['remaining'] <= 5 ? 'danger' : ($stats['remaining'] <= 10 ? 'warning' : ''); ?>">
                                <?php echo $stats['remaining']; ?> remaining
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (!$hts_manager->is_pro() && $stats['remaining'] <= 5): ?>
                    <div class="hts-upgrade-prompt">
                        <p>You're running low on classifications! Upgrade to Pro for unlimited usage.</p>
                        <a href="https://sonicpixel.ca/hts-manager-pro/" class="button button-primary" target="_blank">
                            Upgrade to Pro
                        </a>
                    </div>
                <?php endif; ?>
                
                <?php if (current_user_can('manage_options')): ?>
                <form method="post" style="margin-top: 20px;">
                    <?php wp_nonce_field('hts_reset_usage', 'hts_reset_nonce'); ?>
                    <button type="submit" name="reset_usage" class="button" 
                            onclick="return confirm('Are you sure you want to reset the usage counter?')">
                        <span class="dashicons dashicons-controls-repeat"></span>
                        Reset Usage Counter
                    </button>
                </form>
                <?php endif; ?>
            </div>
            
            <!-- Bulk Classification Card -->
            <?php if ($hts_manager->can_use_bulk_classify()): ?>
            <div class="hts-card">
                <h2>Bulk Classification</h2>
                <p>Classify multiple products without HTS codes at once.</p>
                
                <div class="hts-form-group">
                    <label>Products to Classify</label>
                    <select id="bulk-product-selector" multiple style="width: 100%; height: 150px;">
                        <?php
                        $products_without_codes = get_posts(array(
                            'post_type' => 'product',
                            'posts_per_page' => 100,
                            'meta_query' => array(
                                'relation' => 'OR',
                                array(
                                    'key' => '_hts_code',
                                    'compare' => 'NOT EXISTS'
                                ),
                                array(
                                    'key' => '_hts_code',
                                    'value' => '',
                                    'compare' => '='
                                )
                            )
                        ));
                        
                        foreach ($products_without_codes as $product) {
                            echo '<option value="' . esc_attr($product->ID) . '">' . esc_html($product->post_title) . '</option>';
                        }
                        ?>
                    </select>
                    <p class="description">Hold Ctrl/Cmd to select multiple products. Only products without HTS codes are shown.</p>
                </div>
                
                <div class="button-group">
                    <button type="button" class="button button-primary hts-bulk-classify-button">
                        <span class="dashicons dashicons-update"></span>
                        Start Bulk Classification
                    </button>
                    <button type="button" class="button" id="select-all-products">Select All</button>
                    <button type="button" class="button" id="clear-selection">Clear Selection</button>
                </div>
                
                <div class="hts-bulk-progress hts-hidden" id="bulk-progress-container">
                    <div class="progress-container">
                        <div class="progress-bar" style="width: 0%"></div>
                    </div>
                    <div class="progress-text">Preparing...</div>
                </div>
            </div>
            <?php else: ?>
            <div class="hts-card">
                <h2>Bulk Classification</h2>
                <div class="hts-upgrade-prompt">
                    <p>Bulk classification is a Pro feature. Upgrade to classify multiple products at once.</p>
                    <a href="https://sonicpixel.ca/hts-manager-pro/" class="button button-primary" target="_blank">
                        Upgrade to Pro
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <script>
            jQuery(document).ready(function($) {
                // Show/Hide API key
                $('#show-api-key').on('click', function() {
                    const input = $('#api_key');
                    const type = input.attr('type') === 'password' ? 'text' : 'password';
                    input.attr('type', type);
                });
                
                // Confidence threshold slider
                $('#confidence_threshold').on('input', function() {
                    const value = Math.round($(this).val() * 100);
                    $('#threshold_display').text(value + '%');
                });
                
                // Bulk product selection
                $('#select-all-products').on('click', function() {
                    $('#bulk-product-selector option').prop('selected', true);
                });
                
                $('#clear-selection').on('click', function() {
                    $('#bulk-product-selector option').prop('selected', false);
                });
                
                // Override bulk classify button handler
                $('.hts-bulk-classify-button').off('click').on('click', function() {
                    const selectedProducts = $('#bulk-product-selector').val();
                    if (!selectedProducts || selectedProducts.length === 0) {
                        alert('Please select at least one product to classify.');
                        return;
                    }
                    
                    if (!confirm('Are you sure you want to classify ' + selectedProducts.length + ' products?')) {
                        return;
                    }
                    
                    // Use the existing bulk classify functionality
                    const progressContainer = $('#bulk-progress-container').removeClass('hts-hidden');
                    
                    $.ajax({
                        url: htsManager.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'hts_bulk_classify',
                            nonce: htsManager.nonce,
                            product_ids: selectedProducts
                        },
                        success: function(response) {
                            if (response.success) {
                                if (response.data.complete) {
                                    progressContainer.find('.progress-text').text('Complete! Classified ' + response.data.success_count + ' products.');
                                    progressContainer.find('.progress-bar').css('width', '100%');
                                    
                                    // Refresh usage stats
                                    if (window.HTSManager) {
                                        window.HTSManager.refreshUsageStats();
                                    }
                                    
                                    setTimeout(function() {
                                        location.reload();
                                    }, 2000);
                                }
                            }
                        }
                    });
                });
            });
        </script>
        
        <h2>Auto-Classification Settings</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Enable Auto-Classification</th>
                <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked($enabled, '1'); ?>>
                            Automatically classify new products when published
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Anthropic API Key</th>
                    <td>
                        <input type="password" name="api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text">
                        <p class="description">Your Claude API key from Anthropic</p>
                        <?php if (!empty($api_key)): ?>
                        <p class="description" style="color: green;">✓ API key is configured (<?php echo strlen($api_key); ?> characters)</p>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Low Confidence Threshold</th>
                    <td>
                        <input type="number" name="confidence_threshold" value="<?php echo esc_attr($threshold); ?>" min="0" max="1" step="0.05">
                        <p class="description">Send email notification if confidence is below this threshold (0.60 = 60%)</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="Save Settings">
            </p>
        </form>
        
        <hr>
        
        <h2>Test Classification</h2>
        <form method="post">
            <?php wp_nonce_field('hts_test', 'hts_test_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Product ID</th>
                    <td>
                        <input type="number" name="test_product_id" placeholder="Enter product ID">
                        <input type="submit" name="test_classify" class="button" value="Test Classification">
                        <p class="description">Enter a product ID to test classification immediately</p>
                    </td>
                </tr>
            </table>
        </form>
        
        <?php 
        // Show limit reached screen if free user has hit the limit
        if (!$hts_manager->is_pro() && $stats['remaining'] <= 0): 
            echo $hts_manager->get_limit_reached_screen();
        elseif (!$hts_manager->is_pro()): 
        ?>
        <hr>
        
        <h2>Usage Management</h2>
        <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
            <h3>Current Usage Statistics</h3>
            <p><strong>Classifications Used:</strong> <?php echo $stats['used']; ?> of <?php echo $stats['limit']; ?></p>
            <p><strong>Remaining:</strong> <?php echo $stats['remaining']; ?></p>
            <p><strong>Percentage Used:</strong> <?php echo $stats['percentage_used']; ?>%</p>
            <?php if ($stats['last_classification'] !== 'Never'): ?>
            <p><strong>Last Classification:</strong> <?php echo $stats['last_classification']; ?></p>
            <?php endif; ?>
            
            <?php if (current_user_can('manage_options')): ?>
            <form method="post" style="margin-top: 15px;">
                <?php wp_nonce_field('hts_reset_usage', 'hts_reset_nonce'); ?>
                <input type="submit" name="reset_usage" class="button" value="Reset Usage Counter" 
                       onclick="return confirm('Are you sure you want to reset the usage counter to 0? This action cannot be undone.');">
                <p class="description">Admin only: Reset the classification counter for testing purposes.</p>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <hr>
        
        <h2>Products Without HTS Codes</h2>
        <?php
        // Get current page
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        
        // First, get total count
        $count_args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_hts_code',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => '_hts_code',
                    'value' => '',
                    'compare' => '='
                )
            )
        );
        
        $all_products_without_codes = get_posts($count_args);
        $total_without_codes = count($all_products_without_codes);
        $total_pages = ceil($total_without_codes / $per_page);
        
        // Now get paginated results
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $per_page,
            'paged' => $current_page,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_hts_code',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => '_hts_code',
                    'value' => '',
                    'compare' => '='
                )
            )
        );
        
        $products = get_posts($args);
        
        if ($total_without_codes > 0) {
            // Show summary and bulk action
            echo '<div style="margin-bottom: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">';
            echo '<p style="font-size: 16px; margin: 0 0 10px 0;"><strong>Found ' . $total_without_codes . ' products without HTS codes</strong></p>';
            
            if ($total_without_codes > 20) {
                echo '<p style="margin: 10px 0;">Showing ' . (($current_page - 1) * $per_page + 1) . '-' . min($current_page * $per_page, $total_without_codes) . ' of ' . $total_without_codes . ' products</p>';
            }
            
            // Bulk classify button
            echo '<div style="margin-top: 15px;">';
            echo '<button class="button button-primary button-large" id="hts-classify-all-missing" data-product-ids="' . esc_attr(implode(',', $all_products_without_codes)) . '">';
            echo '🚀 Classify All ' . $total_without_codes . ' Products';
            echo '</button>';
            
            // Export button (pro feature)
            $hts_manager_export = new HTS_Manager();
            if (!$hts_manager_export->is_pro()) {
                echo '<button class="button button-secondary button-large" id="hts-export-pro" style="margin-left: 10px;" disabled>';
                echo '📊 Export Classifications (Pro)';
                echo '</button>';
            }
            
            echo '<span id="hts-bulk-progress" style="display: none; margin-left: 15px;"></span>';
            echo '<div id="hts-bulk-status" style="margin-top: 10px; display: none;"></div>';
            echo '</div>';
            
            echo '</div>';
            
            // Products table
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>ID</th><th>Product Name</th><th>SKU</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            foreach ($products as $product_post) {
                $product = wc_get_product($product_post->ID);
                echo '<tr>';
                echo '<td>' . $product_post->ID . '</td>';
                echo '<td><a href="' . get_edit_post_link($product_post->ID) . '">' . $product->get_name() . '</a></td>';
                echo '<td>' . ($product->get_sku() ?: 'N/A') . '</td>';
                echo '<td>';
                echo '<button class="button button-small hts-quick-classify" data-product-id="' . $product_post->ID . '">Quick Classify</button>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            
            // Pagination
            if ($total_pages > 1) {
                echo '<div style="margin-top: 20px; text-align: right;">';
                $base_url = admin_url('admin.php?page=hts-manager');
                
                echo '<div class="tablenav-pages">';
                echo '<span class="displaying-num">' . $total_without_codes . ' items</span>';
                echo '<span class="pagination-links">';
                
                // First page
                if ($current_page > 1) {
                    echo '<a class="first-page button" href="' . $base_url . '&paged=1">«</a> ';
                    echo '<a class="prev-page button" href="' . $base_url . '&paged=' . ($current_page - 1) . '">‹</a> ';
                } else {
                    echo '<span class="tablenav-pages-navspan button disabled">«</span> ';
                    echo '<span class="tablenav-pages-navspan button disabled">‹</span> ';
                }
                
                echo '<span class="paging-input">';
                echo '<span class="tablenav-paging-text">' . $current_page . ' of <span class="total-pages">' . $total_pages . '</span></span>';
                echo '</span>';
                
                // Next/Last page
                if ($current_page < $total_pages) {
                    echo ' <a class="next-page button" href="' . $base_url . '&paged=' . ($current_page + 1) . '">›</a>';
                    echo ' <a class="last-page button" href="' . $base_url . '&paged=' . $total_pages . '">»</a>';
                } else {
                    echo ' <span class="tablenav-pages-navspan button disabled">›</span>';
                    echo ' <span class="tablenav-pages-navspan button disabled">»</span>';
                }
                
                echo '</span>';
                echo '</div>';
                echo '</div>';
            }
            
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Individual quick classify
                $('.hts-quick-classify').on('click', function() {
                    var button = $(this);
                    var product_id = button.data('product-id');
                    
                    button.prop('disabled', true).text('Classifying...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'hts_generate_single_code',
                            product_id: product_id,
                            nonce: '<?php echo wp_create_nonce('hts_generate_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                button.text('✓ Classified').css('color', 'green');
                            } else {
                                button.text('✗ Failed').css('color', 'red');
                            }
                        },
                        error: function() {
                            button.text('✗ Error').css('color', 'red');
                            button.prop('disabled', false);
                        }
                    });
                });
                
                // Export button handler (Pro feature)
                $('#hts-export-pro').on('click', function(e) {
                    e.preventDefault();
                    
                    // Show upgrade modal
                    var upgradeModal = '<div id="hts-export-upgrade-modal" style="' +
                        'position: fixed; top: 0; left: 0; width: 100%; height: 100%; ' +
                        'background: rgba(0,0,0,0.7); z-index: 100000; display: flex; ' +
                        'align-items: center; justify-content: center;">' +
                        '<div style="background: white; padding: 40px; border-radius: 8px; max-width: 600px; margin: 20px; position: relative;">' +
                        '<button id="close-upgrade-modal" style="position: absolute; top: 15px; right: 20px; background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>' +
                        '<h2 style="margin-top: 0; color: #ff9800;">📊 Export Features - Pro Only</h2>' +
                        '<p>Export your HTS classifications in multiple formats with HTS Manager Pro!</p>' +
                        '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">' +
                        '<div><h4>📈 Free Version</h4><ul style="list-style: none; padding: 0;"><li>❌ No export features</li><li>❌ Manual copy/paste only</li><li>❌ Limited to 25 classifications</li></ul></div>' +
                        '<div><h4 style="color: #00a32a;">🚀 Pro Version</h4><ul style="list-style: none; padding: 0;"><li>✅ CSV export</li><li>✅ Excel export</li><li>✅ PDF reports</li><li>✅ Unlimited classifications</li></ul></div>' +
                        '</div>' +
                        '<div style="text-align: center; margin-top: 30px;">' +
                        '<p style="font-size: 18px; margin-bottom: 15px;"><strong>Special Price: $67</strong> <span style="text-decoration: line-through; color: #999;">$97</span></p>' +
                        '<a href="https://sonicpixel.ca/hts-manager-pro/" target="_blank" class="button button-primary button-hero" style="margin-right: 15px;">🚀 Get Pro Now</a>' +
                        '<button id="maybe-later" class="button button-secondary">Maybe Later</button>' +
                        '</div>' +
                        '</div></div>';
                    
                    $('body').append(upgradeModal);
                    
                    // Close modal handlers
                    $('#close-upgrade-modal, #maybe-later').on('click', function() {
                        $('#hts-export-upgrade-modal').remove();
                    });
                    
                    // Close on backdrop click
                    $('#hts-export-upgrade-modal').on('click', function(e) {
                        if (e.target === this) {
                            $(this).remove();
                        }
                    });
                });
                
                // Bulk classify all missing
                $('#hts-classify-all-missing').on('click', function() {
                    var button = $(this);
                    var productIds = button.data('product-ids').toString().split(',');
                    var totalProducts = productIds.length;
                    var processed = 0;
                    var succeeded = 0;
                    var failed = 0;
                    
                    if (!confirm('This will classify ' + totalProducts + ' products. This may take several minutes and cost approximately $' + (totalProducts * 0.003).toFixed(2) + ' in API fees.\n\nProceed?')) {
                        return;
                    }
                    
                    button.prop('disabled', true).text('Processing...');
                    $('#hts-bulk-progress').show().html('<span class="spinner is-active" style="float: none;"></span> Processing...');
                    $('#hts-bulk-status').show().html('<div style="padding: 10px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;">Starting classification...</div>');
                    
                    // Process in batches to avoid overwhelming the server
                    var batchSize = 5;
                    var batches = [];
                    
                    for (var i = 0; i < productIds.length; i += batchSize) {
                        batches.push(productIds.slice(i, i + batchSize));
                    }
                    
                    function processBatch(batchIndex) {
                        if (batchIndex >= batches.length) {
                            // All done
                            $('#hts-bulk-progress').html('✓ Complete!');
                            $('#hts-bulk-status').html(
                                '<div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;">' +
                                '<strong>Classification Complete!</strong><br>' +
                                '✓ Succeeded: ' + succeeded + '<br>' +
                                '✗ Failed: ' + failed + '<br>' +
                                'Total Processed: ' + processed + ' of ' + totalProducts +
                                '<br><br><a href="' + window.location.href + '" class="button">Refresh Page</a>' +
                                '</div>'
                            );
                            button.text('Classification Complete').prop('disabled', false);
                            return;
                        }
                        
                        var batch = batches[batchIndex];
                        var batchPromises = [];
                        
                        batch.forEach(function(productId) {
                            var promise = $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'hts_generate_single_code',
                                    product_id: productId,
                                    nonce: '<?php echo wp_create_nonce('hts_generate_nonce'); ?>'
                                },
                                success: function() {
                                    succeeded++;
                                    processed++;
                                },
                                error: function() {
                                    failed++;
                                    processed++;
                                }
                            });
                            batchPromises.push(promise);
                        });
                        
                        // Wait for batch to complete
                        $.when.apply($, batchPromises).always(function() {
                            // Update progress
                            var percentComplete = Math.round((processed / totalProducts) * 100);
                            $('#hts-bulk-progress').html(
                                '<div style="display: inline-block; width: 200px; background: #f0f0f0; border-radius: 10px; overflow: hidden; margin-right: 10px;">' +
                                '<div style="background: #2271b1; height: 20px; width: ' + percentComplete + '%; transition: width 0.3s;"></div>' +
                                '</div>' +
                                processed + ' / ' + totalProducts + ' (' + percentComplete + '%)'
                            );
                            
                            $('#hts-bulk-status').html(
                                '<div style="padding: 10px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;">' +
                                'Processing batch ' + (batchIndex + 1) + ' of ' + batches.length + '<br>' +
                                '✓ Succeeded: ' + succeeded + ' | ✗ Failed: ' + failed +
                                '</div>'
                            );
                            
                            // Process next batch with a small delay to avoid rate limiting
                            setTimeout(function() {
                                processBatch(batchIndex + 1);
                            }, 1000); // 1 second delay between batches
                        });
                    }
                    
                    // Start processing
                    processBatch(0);
                });
            });
            </script>
            <?php
        } else {
            echo '<p>✓ All products have HTS codes!</p>';
        }
        ?>
        
        <hr>
        
        <h2>Features</h2>
        <ul style="list-style: disc; margin-left: 20px;">
            <li><strong>Product Tab:</strong> HTS Codes tab in product edit screen with AI generation button</li>
            <li><strong>Auto-Classification:</strong> Automatically classifies new products when published</li>
            <li><strong>Bulk Operations:</strong> Classify multiple products at once from the products list</li>
            <li><strong>Manual Override:</strong> Edit HTS codes directly in product data</li>
            <li><strong>Confidence Tracking:</strong> Shows AI confidence level for each classification</li>
        </ul>
        
        <hr>
        
        <h2>📚 Usage Guide for Staff</h2>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 5px; border-left: 4px solid #2271b1;">
            <h3 style="margin-top: 0;">Daily Workflow</h3>
            
            <div style="margin-bottom: 20px;">
                <h4>🌅 Start of Day:</h4>
                <ol style="line-height: 1.8;">
                    <li>Check the <strong>Dashboard Widget</strong> for status overview</li>
                    <li>If you see <span style="color: #d63638;">❌ Products without codes</span>, they'll auto-classify as you work</li>
                    <li>Review any <span style="color: #dba617;">⚠️ Low confidence</span> items if time permits</li>
                </ol>
            </div>
            
            <div style="margin-bottom: 20px;">
                <h4>➕ Adding New Products:</h4>
                <ol style="line-height: 1.8;">
                    <li>Enter all product details as normal</li>
                    <li>Click <strong>"Publish"</strong> - HTS code generates automatically in background</li>
                    <li>Move to next product immediately (no waiting!)</li>
                    <li>Code will be ready in ~10 seconds if you need to check</li>
                </ol>
            </div>
            
            <div style="margin-bottom: 20px;">
                <h4>🔧 Fixing Missing Codes:</h4>
                <p><strong>Option A - Individual Product:</strong></p>
                <ol style="line-height: 1.8;">
                    <li>Edit the product</li>
                    <li>Go to <strong>"HTS Codes"</strong> tab</li>
                    <li>Click <strong>"Auto-Generate with AI"</strong> button</li>
                    <li>Wait 3 seconds for the code to appear</li>
                </ol>
                
                <p><strong>Option B - Let System Auto-Fix:</strong></p>
                <ol style="line-height: 1.8;">
                    <li>Just click <strong>"Update"</strong> on any product without a code</li>
                    <li>System will auto-generate in background</li>
                    <li>Check back in a minute to see the code</li>
                </ol>
                
                <p><strong>Option C - Bulk Fix:</strong></p>
                <ol style="line-height: 1.8;">
                    <li>Go to Products list</li>
                    <li>Filter by <strong>"Missing HTS Code"</strong></li>
                    <li>Select all products</li>
                    <li>Bulk Actions → <strong>"Generate HTS Codes"</strong></li>
                </ol>
            </div>
            
            <div style="margin-bottom: 20px;">
            </div>
            
            <div style="background: #fff; padding: 15px; border-radius: 5px; margin-top: 20px;">
                <h4 style="margin-top: 0;">⚡ Quick Tips:</h4>
                <ul style="line-height: 1.8; margin-bottom: 0;">
                    <li>🟢 <strong>Green progress bar</strong> = You're all set!</li>
                    <li>🟡 <strong>Yellow progress bar</strong> = Some products need attention</li>
                    <li>🔴 <strong>Red progress bar</strong> = Many products missing codes</li>
                    <li>💡 <strong>Low confidence?</strong> The code is probably still correct, but double-check if shipping high-value items</li>
                    <li>🔄 <strong>Regenerate a code:</strong> Use the "Regenerate" link next to the AI button</li>
                </ul>
            </div>
            
            <div style="background: #e7f3ff; padding: 15px; border-radius: 5px; margin-top: 20px;">
                <strong>📞 Need Help?</strong><br>
                • Check the dashboard widget for current status<br>
                • Missing codes auto-generate on product save<br>
                • Manual generation available in HTS Codes tab<br>
                • Bulk operations available for multiple products
            </div>
        </div>
    </div>
    <?php
}

// ===============================================
// PART 7: BULK ACTIONS
// ===============================================

add_filter('bulk_actions-edit-product', 'hts_add_bulk_classify');
function hts_add_bulk_classify($bulk_actions) {
    // Only add bulk action if pro version allows it
    $hts_manager = new HTS_Manager();
    if ($hts_manager->can_use_bulk_classify()) {
        $bulk_actions['hts_classify'] = __('Generate HTS Codes (Pro)', 'hts-manager');
    } else {
        $bulk_actions['hts_classify_limited'] = __('Generate HTS Codes (5 max)', 'hts-manager');
    }
    return $bulk_actions;
}

add_filter('handle_bulk_actions-edit-product', 'hts_handle_bulk_classify', 10, 3);
function hts_handle_bulk_classify($redirect_to, $action, $post_ids) {
    if ($action !== 'hts_classify' && $action !== 'hts_classify_limited') {
        return $redirect_to;
    }
    
    $hts_manager = new HTS_Manager();
    
    // Apply limits based on version
    if (!$hts_manager->is_pro()) {
        $bulk_limit = $hts_manager->get_bulk_limit();
        if ($bulk_limit > 0 && count($post_ids) > $bulk_limit) {
            $original_count = count($post_ids);
            $post_ids = array_slice($post_ids, 0, $bulk_limit);
            $redirect_to = add_query_arg('hts_limited', $bulk_limit, $redirect_to);
            $redirect_to = add_query_arg('hts_attempted', $original_count, $redirect_to);
        }
    }
    
    foreach ($post_ids as $post_id) {
        wp_schedule_single_event(time() + rand(5, 30), 'hts_classify_product_cron', array($post_id));
    }
    
    $redirect_to = add_query_arg('hts_classified', count($post_ids), $redirect_to);
    return $redirect_to;
}

add_action('admin_notices', 'hts_bulk_classify_notice');
function hts_bulk_classify_notice() {
    if (!empty($_REQUEST['hts_classified'])) {
        $count = intval($_REQUEST['hts_classified']);
        printf(
            '<div class="notice notice-success is-dismissible"><p>' . 
            _n('Queued %s product for HTS classification.', 'Queued %s products for HTS classification.', $count, 'hts-manager') . 
            '</p></div>',
            $count
        );
    }
    
    // Show upgrade notice if bulk operation was limited
    if (!empty($_REQUEST['hts_limited'])) {
        $limit = intval($_REQUEST['hts_limited']);
        $attempted = !empty($_REQUEST['hts_attempted']) ? intval($_REQUEST['hts_attempted']) : 0;
        $hts_manager = new HTS_Manager();
        
        if (!$hts_manager->is_pro()) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<h3 style="margin: 10px 0;">🎯 Bulk Processing Limited</h3>';
            echo '<p>You selected <strong>' . $attempted . ' products</strong> but the free version is limited to <strong>' . $limit . ' products</strong> per bulk operation.</p>';
            echo '<p><strong>Upgrade to Pro</strong> for unlimited bulk processing:</p>';
            echo '<ul style="list-style: disc; margin-left: 20px;">';
            echo '<li>Process hundreds of products at once</li>';
            echo '<li>No classification limits (currently 25 max)</li>';
            echo '<li>Export features included</li>';
            echo '</ul>';
            echo '<p><a href="https://sonicpixel.ca/hts-manager-pro/" target="_blank" class="button button-primary">Upgrade to Pro - $67</a> ';
            echo '<span style="color: #666;">One-time payment, lifetime license</span></p>';
            echo '</div>';
        }
    }
}

// ===============================================
// PART 8: DISPLAY ON FRONTEND (OPTIONAL)
// ===============================================

add_action('woocommerce_product_meta_end', 'hts_display_on_product_page');
function hts_display_on_product_page() {
    if (get_option('hts_show_on_frontend', '0') === '1') {
        global $product;
        $hts_code = get_post_meta($product->get_id(), '_hts_code', true);
        $country = get_post_meta($product->get_id(), '_country_of_origin', true);
        
        if ($hts_code) {
            echo '<span class="hts-code">HTS Code: ' . esc_html($hts_code) . '</span><br>';
        }
        if ($country) {
            echo '<span class="country-origin">Country of Origin: ' . esc_html($country) . '</span><br>';
        }
    }
}

// ===============================================
// PART 9: DASHBOARD WIDGET
// ===============================================

add_action('wp_dashboard_setup', 'hts_add_dashboard_widget');
function hts_add_dashboard_widget() {
    if (current_user_can('manage_woocommerce')) {
        wp_add_dashboard_widget(
            'hts_classification_status',
            '📦 HTS Classification Status',
            'hts_dashboard_widget_display'
        );
    }
}

function hts_dashboard_widget_display() {
    global $wpdb;
    
    // Get HTS Manager instance and usage stats
    $hts_manager = new HTS_Manager();
    $usage_stats = $hts_manager->get_usage_stats();
    
    // Get total products
    $total_products = wp_count_posts('product');
    $total_published = $total_products->publish;
    
    // Get products with HTS codes
    $with_codes = $wpdb->get_var("
        SELECT COUNT(DISTINCT post_id) 
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE pm.meta_key = '_hts_code' 
        AND pm.meta_value != '' 
        AND pm.meta_value != '9999.99.9999'
        AND p.post_status = 'publish'
        AND p.post_type = 'product'
    ");
    
    // Get products with low confidence
    $low_confidence = $wpdb->get_var("
        SELECT COUNT(DISTINCT post_id) 
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE pm.meta_key = '_hts_confidence' 
        AND CAST(pm.meta_value AS DECIMAL(3,2)) < 0.60
        AND p.post_status = 'publish'
        AND p.post_type = 'product'
    ");
    
    // Check for pending scheduled classifications
    $pending_crons = 0;
    $crons = _get_cron_array();
    foreach ($crons as $timestamp => $cron) {
        if (isset($cron['hts_classify_product_cron'])) {
            $pending_crons += count($cron['hts_classify_product_cron']);
        }
    }
    
    $without_codes = $total_published - $with_codes;
    $percentage = $total_published > 0 ? round(($with_codes / $total_published) * 100, 1) : 0;
    
    // Define status color based on coverage
    $status_color = $percentage >= 95 ? '#00a32a' : ($percentage >= 80 ? '#dba617' : '#d63638');
    
    // Usage tracking colors
    $usage_color = '#00a32a'; // Default green
    if (!$usage_stats['is_pro'] && $usage_stats['remaining'] <= 5) {
        $usage_color = '#d63638'; // Red for low remaining
    } elseif (!$usage_stats['is_pro'] && $usage_stats['remaining'] <= 10) {
        $usage_color = '#dba617'; // Yellow for medium remaining
    }
    
    ?>
    <style>
        .hts-widget-stats {
            margin: 15px 0;
        }
        .hts-stat-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .hts-stat-row:last-child {
            border-bottom: none;
        }
        .hts-stat-label {
            font-weight: 500;
        }
        .hts-stat-value {
            font-weight: bold;
        }
        .hts-progress-bar {
            width: 100%;
            height: 20px;
            background: #f0f0f0;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }
        .hts-progress-fill {
            height: 100%;
            background: <?php echo $status_color; ?>;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }
        .hts-action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .hts-action-buttons .button {
            flex: 1;
            text-align: center;
        }
        .hts-status-good { color: #00a32a; }
        .hts-status-warning { color: #dba617; }
        .hts-status-error { color: #d63638; }
        .hts-refresh-notice {
            margin-top: 10px;
            color: #666;
            font-size: 12px;
            font-style: italic;
        }
        .hts-usage-section {
            background: #f8f9fa;
            border-left: 4px solid <?php echo $usage_color; ?>;
            padding: 12px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .hts-usage-title {
            font-size: 14px;
            font-weight: 600;
            margin: 0 0 8px 0;
            color: #1d2327;
        }
        .hts-usage-stats {
            font-size: 16px;
            margin: 5px 0;
        }
        .hts-usage-bar {
            width: 100%;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            margin: 8px 0;
        }
        .hts-usage-fill {
            height: 100%;
            background: <?php echo $usage_color; ?>;
            transition: width 0.3s ease;
        }
        .hts-upgrade-button {
            background: #2271b1;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 3px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            display: inline-block;
            margin-top: 8px;
        }
        .hts-upgrade-button:hover {
            background: #135e96;
            color: white;
        }
        .hts-warning-text {
            color: #d63638;
            font-weight: 600;
            margin: 5px 0;
        }
    </style>
    
    <div class="hts-widget-content">
        <!-- Usage Statistics Section -->
        <div class="hts-usage-section">
            <div class="hts-usage-title">
                <?php if ($usage_stats['is_pro']): ?>
                    🚀 Pro Version Active
                <?php else: ?>
                    📊 Classification Usage
                <?php endif; ?>
            </div>
            
            <?php if ($usage_stats['is_pro']): ?>
                <div class="hts-usage-stats">
                    <strong>Classifications used: <?php echo number_format($usage_stats['used']); ?> (unlimited)</strong>
                </div>
                <div style="color: #00a32a; font-size: 13px;">✨ Unlimited classifications available</div>
            <?php else: ?>
                <div class="hts-usage-stats">
                    <strong>Classifications used: <?php echo $usage_stats['used']; ?>/<?php echo $usage_stats['limit']; ?></strong>
                </div>
                
                <div class="hts-usage-bar">
                    <div class="hts-usage-fill" style="width: <?php echo min(100, $usage_stats['percentage_used']); ?>%"></div>
                </div>
                
                <div style="font-size: 13px; color: #666;">
                    <?php echo $usage_stats['remaining']; ?> classifications remaining
                </div>
                
                <?php if ($usage_stats['remaining'] <= 5): ?>
                    <div class="hts-warning-text">
                        ⚠️ Only <?php echo $usage_stats['remaining']; ?> classifications left!
                    </div>
                <?php elseif ($usage_stats['remaining'] <= 10): ?>
                    <div style="color: #dba617; font-weight: 500;">
                        ⚡ <?php echo $usage_stats['remaining']; ?> classifications remaining
                    </div>
                <?php endif; ?>
                
                <?php if ($usage_stats['remaining'] <= 0): ?>
                    <a href="#" class="hts-upgrade-button">🚀 Upgrade to Pro - Unlimited</a>
                <?php elseif ($usage_stats['remaining'] <= 5): ?>
                    <a href="#" class="hts-upgrade-button">Upgrade for Unlimited</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <!-- Product Coverage Section -->
        <div class="hts-progress-bar">
            <div class="hts-progress-fill" style="width: <?php echo $percentage; ?>%">
                <?php echo $percentage; ?>%
            </div>
        </div>
        
        <div class="hts-widget-stats">
            <div class="hts-stat-row">
                <span class="hts-stat-label">✅ Products with HTS codes:</span>
                <span class="hts-stat-value hts-status-good"><?php echo number_format($with_codes); ?></span>
            </div>
            
            <?php if ($without_codes > 0): ?>
            <div class="hts-stat-row">
                <span class="hts-stat-label">❌ Products without codes:</span>
                <span class="hts-stat-value hts-status-error"><?php echo number_format($without_codes); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($pending_crons > 0): ?>
            <div class="hts-stat-row">
                <span class="hts-stat-label">⏳ Pending classification:</span>
                <span class="hts-stat-value hts-status-warning"><?php echo number_format($pending_crons); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($low_confidence > 0): ?>
            <div class="hts-stat-row">
                <span class="hts-stat-label">⚠️ Low confidence (needs review):</span>
                <span class="hts-stat-value hts-status-warning"><?php echo number_format($low_confidence); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="hts-stat-row">
                <span class="hts-stat-label">📊 Total products:</span>
                <span class="hts-stat-value"><?php echo number_format($total_published); ?></span>
            </div>
        </div>
        
        <div class="hts-action-buttons">
            <?php if ($without_codes > 0): ?>
            <a href="<?php echo admin_url('admin.php?page=hts-manager'); ?>" class="button button-primary">
                Classify Missing
            </a>
            <?php endif; ?>
            
            <?php if ($low_confidence > 0): ?>
            <a href="<?php echo admin_url('admin.php?page=hts-manager'); ?>" class="button">
                Review Products
            </a>
            <?php endif; ?>
            
            <a href="<?php echo admin_url('admin.php?page=hts-manager'); ?>" class="button">
                Settings
            </a>
        </div>
        
        <?php if ($pending_crons > 0): ?>
        <div class="hts-refresh-notice">
            ⏱️ Classifications in progress. Refresh in a minute to see updates.
        </div>
        <?php endif; ?>
        
        <div class="hts-refresh-notice" style="margin-top: 15px;">
            <a href="#" onclick="location.reload(); return false;">↻ Refresh Stats</a>
            <?php if ($with_codes === $total_published): ?>
            | <span style="color: #00a32a;">✨ All products classified!</span>
            <?php endif; ?>
            | <a href="#" onclick="jQuery('#hts-quick-guide').toggle(); return false;">📖 Quick Guide</a>
        </div>
        
        <div id="hts-quick-guide" style="display: none; margin-top: 15px; padding: 15px; background: #f8f9fa; border-left: 4px solid #2271b1; border-radius: 4px;">
            <h4 style="margin-top: 0;">🚀 Quick Usage Guide</h4>
            <ol style="margin-left: 20px; line-height: 1.6;">
                <li><strong>New Products:</strong> HTS codes auto-generate when you publish/update</li>
                <li><strong>Manual Generate:</strong> Edit product → HTS Codes tab → "Auto-Generate with AI"</li>
                <li><strong>Bulk Classify:</strong> Products list → Select multiple → "Generate HTS Codes"</li>
                <li><strong>Review Low Confidence:</strong> Click button above to see products needing review</li>
            </ol>
            <p style="margin-bottom: 0; color: #666; font-size: 12px;">
                💡 <strong>Tip:</strong> Products without codes will auto-classify on next save. Just click "Update" on any product missing a code!
            </p>
        </div>
    </div>
    <?php
}

// ===============================================
// PART 10: ADMIN NOTICES FOR PRODUCT SAVES
// ===============================================

add_action('admin_notices', 'hts_product_save_notices');
function hts_product_save_notices() {
    $screen = get_current_screen();
    
    // Only show on product edit screen
    if ($screen && $screen->id === 'product') {
        global $post;
        
        if ($post && $post->post_type === 'product') {
            $hts_code = get_post_meta($post->ID, '_hts_code', true);
            
            // Check if we just saved (by looking for the 'message' parameter)
            if (isset($_GET['message']) && $_GET['message'] == '1') {
                
                // Check if classification is scheduled
                $crons = _get_cron_array();
                $is_scheduled = false;
                
                foreach ($crons as $timestamp => $cron) {
                    if (isset($cron['hts_classify_product_cron'])) {
                        foreach ($cron['hts_classify_product_cron'] as $hook) {
                            if (in_array($post->ID, $hook['args'])) {
                                $is_scheduled = true;
                                break 2;
                            }
                        }
                    }
                }
                
                if ($is_scheduled && empty($hts_code)) {
                    ?>
                    <div class="notice notice-info is-dismissible">
                        <p>
                            <strong>⏳ HTS Classification in Progress</strong><br>
                            The HTS code is being generated for this product. Refresh the page in a few seconds to see the result.
                        </p>
                    </div>
                    <?php
                } elseif (!empty($hts_code)) {
                    $confidence = get_post_meta($post->ID, '_hts_confidence', true);
                    if ($confidence && $confidence < 0.60) {
                        ?>
                        <div class="notice notice-warning is-dismissible">
                            <p>
                                <strong>⚠️ Low Confidence HTS Code</strong><br>
                                This product has an HTS code (<?php echo esc_html($hts_code); ?>) but with low confidence (<?php echo round($confidence * 100); ?>%). 
                                Consider reviewing and updating if necessary.
                            </p>
                        </div>
                        <?php
                    }
                }
            }
        }
    }
}

