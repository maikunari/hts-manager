<?php
/**
 * Plugin Name: HTS Manager for WooCommerce
 * Description: Complete HTS code management - display and auto-classify HTS codes
 * Version: 3.0.0
 * Author: Mike Sewell
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define pro version flag - set to true for pro version
if (!defined('HTS_MANAGER_PRO')) {
    define('HTS_MANAGER_PRO', false);
}

// Initialize the plugin
add_action('plugins_loaded', 'hts_manager_init');
function hts_manager_init() {
    if (class_exists('WooCommerce')) {
        new HTS_Manager();
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
            
            <?php wp_nonce_field('hts_product_nonce_action', 'hts_product_nonce'); ?>
            
            <div class="options_group">
                <?php
                woocommerce_wp_text_input(array(
                    'id'          => '_hts_code',
                    'label'       => __('HTS Code', 'hts-manager'),
                    'placeholder' => '0000.00.0000',
                    'desc_tip'    => true,
                    'description' => __('Enter the 10-digit Harmonized Tariff Schedule code for this product.', 'hts-manager'),
                    'value'       => $hts_code,
                ));
                ?>
                
                <p class="form-field">
                    <label><?php _e('Generate HTS Code', 'hts-manager'); ?></label>
                    <button type="button" class="button button-primary" id="hts_generate_code" <?php echo !empty($hts_code) ? 'disabled' : ''; ?>>
                        <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                        <?php _e('Auto-Generate with AI', 'hts-manager'); ?>
                    </button>
                    <?php if (!empty($hts_code)): ?>
                        <a href="#" id="hts_regenerate_link" style="margin-left: 10px; text-decoration: none;">
                            <?php _e('Regenerate', 'hts-manager'); ?>
                        </a>
                    <?php endif; ?>
                    <span id="hts_generate_spinner" class="spinner" style="display: none; float: none; margin-left: 10px;"></span>
                    <span id="hts_generate_message" style="display: none; margin-left: 10px;"></span>
                </p>
                
                <?php if ($hts_confidence): ?>
                <p class="form-field">
                    <label><?php _e('Confidence', 'hts-manager'); ?></label>
                    <span style="margin-left: 10px;">
                        <?php 
                        $confidence_percent = round($hts_confidence * 100);
                        $confidence_color = $confidence_percent >= 85 ? 'green' : ($confidence_percent >= 60 ? 'orange' : 'red');
                        ?>
                        <span style="color: <?php echo $confidence_color; ?>; font-weight: bold;">
                            <?php echo $confidence_percent; ?>%
                        </span>
                        <?php if ($hts_updated): ?>
                            <span style="color: #666; margin-left: 10px;">
                                (Updated: <?php echo date('Y-m-d H:i', strtotime($hts_updated)); ?>)
                            </span>
                        <?php endif; ?>
                    </span>
                </p>
                <?php endif; ?>
                
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
                                errorMessage += '<br><a href="#" style="color: #2271b1; text-decoration: none;">Upgrade to Pro</a> for unlimited classifications.';
                            }
                            
                            message.html('<span style="color: red;">✗ ' + errorMessage + '</span>');
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
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'hts_generate_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed'));
        return;
    }
    
    // Check permissions
    if (!current_user_can('edit_products')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }
    
    $product_id = intval($_POST['product_id']);
    if (!$product_id) {
        wp_send_json_error(array('message' => 'Invalid product ID'));
        return;
    }
    
    // Check classification limits for free version
    $hts_manager = new HTS_Manager();
    $can_classify = $hts_manager->can_classify();
    
    if ($can_classify !== true) {
        // Usage limit reached
        wp_send_json_error(array(
            'message' => $can_classify['message'],
            'upgrade_needed' => true,
            'used' => $can_classify['used'],
            'limit' => $can_classify['limit']
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
    
    // Generate HTS code
    $result = hts_classify_product($product_id, $api_key);
    
    if ($result && isset($result['hts_code'])) {
        // Save the results
        update_post_meta($product_id, '_hts_code', $result['hts_code']);
        update_post_meta($product_id, '_hts_confidence', $result['confidence']);
        update_post_meta($product_id, '_hts_updated', current_time('mysql'));
        update_post_meta($product_id, '_country_of_origin', 'CA');
        
        // Increment usage counter for free version tracking
        if (!$hts_manager->is_pro()) {
            $new_count = $hts_manager->increment_usage();
        }
        
        wp_send_json_success(array(
            'hts_code' => $result['hts_code'],
            'confidence' => $result['confidence'],
            'reasoning' => $result['reasoning'] ?? ''
        ));
    } else {
        wp_send_json_error(array('message' => 'Failed to generate HTS code. Please try again.'));
    }
}

// ===============================================
// PART 3: CLASSIFICATION FUNCTION
// ===============================================

function hts_classify_product($product_id, $api_key) {
    $product = wc_get_product($product_id);
    if (!$product) {
        return false;
    }
    
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
    
    // Call Claude API
    $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'x-api-key' => $api_key,
            'anthropic-version' => '2023-06-01',
        ),
        'body' => json_encode(array(
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 500,
            'temperature' => 0.2,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            )
        )),
        'timeout' => 30
    ));
    
    if (is_wp_error($response)) {
        error_log('HTS Manager: API call failed - ' . $response->get_error_message());
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (isset($data['content'][0]['text'])) {
        $response_text = $data['content'][0]['text'];
        
        // Extract JSON from response
        if (preg_match('/\{.*\}/s', $response_text, $matches)) {
            $result = json_decode($matches[0], true);
            
            if (isset($result['hts_code']) && preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $result['hts_code'])) {
                return $result;
            }
        }
    }
    
    return false;
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
    $api_key = get_option('hts_anthropic_api_key');
    if (empty($api_key)) {
        return;
    }
    
    // Check if we can classify (respects usage limits)
    $hts_manager = new HTS_Manager();
    $can_classify = $hts_manager->can_classify();
    
    if ($can_classify !== true) {
        // Skip classification if limit reached
        error_log('HTS Manager: Skipping auto-classification for product ' . $product_id . ' - usage limit reached');
        return;
    }
    
    $result = hts_classify_product($product_id, $api_key);
    
    if ($result && isset($result['hts_code'])) {
        update_post_meta($product_id, '_hts_code', $result['hts_code']);
        update_post_meta($product_id, '_hts_confidence', $result['confidence']);
        update_post_meta($product_id, '_hts_updated', current_time('mysql'));
        update_post_meta($product_id, '_country_of_origin', 'CA');
        
        // Increment usage counter for free version tracking
        if (!$hts_manager->is_pro()) {
            $hts_manager->increment_usage();
        }
        
        // Notify admin if low confidence
        if ($result['confidence'] < 0.60) {
            hts_notify_admin_low_confidence($product_id, $result);
        }
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
                <a href="#" style="text-decoration: none;">Upgrade to Pro</a> for unlimited classifications.</p>
                
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
        
        <form method="post">
            <?php wp_nonce_field('hts_settings', 'hts_nonce'); ?>
            
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
        
        <?php if (!$hts_manager->is_pro()): ?>
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
            $post_ids = array_slice($post_ids, 0, $bulk_limit);
            $redirect_to = add_query_arg('hts_limited', $bulk_limit, $redirect_to);
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
        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo '<strong>Limited to ' . $limit . ' products.</strong> ';
        echo '<a href="#" style="text-decoration: none;">Upgrade to Pro</a> for unlimited bulk operations.';
        echo '</p></div>';
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
    </style>
    
    <div class="hts-widget-content">
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

