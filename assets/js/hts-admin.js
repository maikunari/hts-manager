/**
 * HTS Manager Admin JavaScript
 * Provides modern AJAX functionality and interactive UI elements
 */

jQuery(document).ready(function($) {
    'use strict';

    // Initialize HTS Manager functionality
    const HTSManager = {
        
        // Configuration
        config: {
            selectors: {
                classifyButton: '.hts-classify-button',
                regenerateLink: '.hts-regenerate-link',
                testApiButton: '.hts-test-api-button',
                saveCodeButton: '.hts-save-code-button',
                bulkClassifyButton: '.hts-bulk-classify-button',
                usageCounter: '.hts-usage-counter',
                progressBar: '.hts-progress-bar',
                resultContainer: '.hts-result-container',
                loadingSpinner: '.hts-loading-spinner',
                manualCodeInput: '.hts-manual-code-input',
                expandToggle: '.hts-expand-toggle',
                htsCodeField: '#_hts_code'
            },
            classes: {
                loading: 'hts-loading',
                success: 'hts-success',
                error: 'hts-error',
                hidden: 'hts-hidden',
                expanded: 'hts-expanded',
                disabled: 'hts-disabled'
            }
        },

        // Initialize all functionality
        init: function() {
            this.bindEvents();
            this.initializeUsageCounter();
            this.initializeExpandableElements();
            this.checkApiKeyStatus();
        },

        // Bind event handlers
        bindEvents: function() {
            // Product classification
            $(document).on('click', this.config.selectors.classifyButton, this.handleClassifyProduct.bind(this));
            
            // Regenerate link
            $(document).on('click', this.config.selectors.regenerateLink, this.handleRegenerateCode.bind(this));
            
            // API key testing
            $(document).on('click', this.config.selectors.testApiButton, this.handleTestApiKey.bind(this));
            
            // Manual code saving
            $(document).on('click', this.config.selectors.saveCodeButton, this.handleSaveManualCode.bind(this));
            
            // Bulk classification
            $(document).on('click', this.config.selectors.bulkClassifyButton, this.handleBulkClassify.bind(this));
            
            // Expandable elements
            $(document).on('click', this.config.selectors.expandToggle, this.toggleExpanded.bind(this));
            
            // Real-time input validation
            $(document).on('input', '.hts-api-key-input', this.debounce(this.validateApiKeyInput.bind(this), 500));
            $(document).on('input', this.config.selectors.manualCodeInput, this.validateHtsCodeInput.bind(this));
            
            // Auto-refresh usage stats
            setInterval(this.refreshUsageStats.bind(this), 30000); // Every 30 seconds
        },

        // Handle single product classification
        handleClassifyProduct: function(e) {
            e.preventDefault();
            
            const button = $(e.currentTarget);
            const productId = button.data('product-id');
            const resultContainer = button.closest('.form-field').siblings('.hts-result-container');
            
            if (!productId) {
                this.showError(button, 'Invalid product ID');
                return;
            }

            // Check usage limits before proceeding
            if (!htsManager.isPro && htsManager.usageStats && htsManager.usageStats.remaining <= 0) {
                this.showUpgradePrompt(button, 'You have reached your classification limit.');
                return;
            }

            this.showLoadingState(button, htsManager.strings.classifying || 'Classifying...');
            resultContainer.removeClass('hts-success hts-error').hide();

            $.ajax({
                url: htsManager.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'hts_generate_single_code',
                    nonce: $('#hts_product_nonce').val() || htsManager.nonce,
                    product_id: productId
                },
                success: function(response) {
                    if (response.success) {
                        // Update the HTS code field
                        $(this.config.selectors.htsCodeField).val(response.data.hts_code);
                        
                        // Show success message
                        resultContainer.addClass('hts-success')
                            .html('<strong>✓ Generated:</strong> ' + response.data.hts_code + 
                                  ' (' + Math.round(response.data.confidence * 100) + '% confidence)')
                            .show();
                        
                        // Update or add regenerate link
                        if (!button.siblings('.hts-regenerate-link').length) {
                            button.after(' <a href="#" class="hts-regenerate-link" data-product-id="' + productId + '">Regenerate</a>');
                        }
                    } else {
                        resultContainer.addClass('hts-error')
                            .html('<strong>✗ Error:</strong> ' + response.data.message)
                            .show();
                            
                        if (response.data.upgrade_needed && response.data.upgrade_html) {
                            resultContainer.append(response.data.upgrade_html);
                        }
                    }
                }.bind(this),
                error: function(xhr, status, error) {
                    resultContainer.addClass('hts-error')
                        .html('<strong>✗ Error:</strong> ' + error)
                        .show();
                }.bind(this),
                complete: function() {
                    this.hideLoadingState(button);
                }.bind(this)
            });
        },
        
        // Handle regenerate code
        handleRegenerateCode: function(e) {
            e.preventDefault();
            
            const link = $(e.currentTarget);
            const productId = link.data('product-id');
            
            if (!confirm('Are you sure you want to regenerate the HTS code? This will overwrite the existing code.')) {
                return;
            }
            
            // Find the generate button and trigger classification
            const button = link.siblings('.hts-classify-button');
            if (button.length) {
                button.trigger('click');
            }
        },

        // Handle API key testing
        handleTestApiKey: function(e) {
            e.preventDefault();
            
            const button = $(e.currentTarget);
            const apiKeyInput = button.siblings('.hts-api-key-input');
            const apiKey = apiKeyInput.val().trim();
            
            if (!apiKey) {
                this.showError(button, 'Please enter an API key');
                return;
            }

            this.showLoadingState(button, htsManager.strings.testing);

            $.ajax({
                url: htsManager.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hts_test_api_key',
                    nonce: htsManager.nonce,
                    api_key: apiKey
                },
                success: function(response) {
                    if (response.success) {
                        this.showSuccess(button, response.data.message);
                        this.markApiKeyValid(apiKeyInput);
                    } else {
                        this.showError(button, response.data.message);
                        this.markApiKeyInvalid(apiKeyInput);
                    }
                }.bind(this),
                error: function(xhr, status, error) {
                    this.showError(button, 'Network error: ' + error);
                    this.markApiKeyInvalid(apiKeyInput);
                }.bind(this),
                complete: function() {
                    this.hideLoadingState(button);
                }.bind(this)
            });
        },

        // Handle manual code saving
        handleSaveManualCode: function(e) {
            e.preventDefault();
            
            const button = $(e.currentTarget);
            const form = button.closest('form');
            const productId = form.find('[name="product_id"]').val();
            const htsCode = form.find('.hts-manual-code-input').val();
            const description = form.find('.hts-description-input').val();

            this.showLoadingState(button, htsManager.strings.saving);

            $.ajax({
                url: htsManager.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hts_save_manual_code',
                    nonce: htsManager.nonce,
                    product_id: productId,
                    hts_code: htsCode,
                    description: description
                },
                success: function(response) {
                    if (response.success) {
                        this.showSuccess(button, response.data.message);
                        this.updateDisplayedCode(form, response.data);
                    } else {
                        this.showError(button, response.data.message);
                    }
                }.bind(this),
                error: function(xhr, status, error) {
                    this.showError(button, 'Network error: ' + error);
                }.bind(this),
                complete: function() {
                    this.hideLoadingState(button);
                }.bind(this)
            });
        },

        // Handle bulk classification
        handleBulkClassify: function(e) {
            e.preventDefault();
            
            const button = $(e.currentTarget);
            const selectedProducts = this.getSelectedProducts();
            
            if (selectedProducts.length === 0) {
                this.showError(button, 'Please select products to classify');
                return;
            }

            // Check if pro version required
            if (!htsManager.isPro) {
                this.showUpgradePrompt(button, 'Bulk classification requires Pro version');
                return;
            }

            // Confirm bulk operation
            if (!confirm(htsManager.strings.confirm_bulk.replace('%d', selectedProducts.length))) {
                return;
            }

            this.startBulkClassification(button, selectedProducts);
        },

        // Start bulk classification process
        startBulkClassification: function(button, productIds) {
            const progressContainer = this.createProgressContainer();
            button.after(progressContainer);
            
            this.showLoadingState(button, 'Starting bulk classification...');

            $.ajax({
                url: htsManager.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hts_bulk_classify',
                    nonce: htsManager.nonce,
                    product_ids: productIds
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.progress) {
                            // Update progress
                            this.updateBulkProgress(progressContainer, response.data);
                        } else if (response.data.complete) {
                            // Process complete
                            this.completeBulkClassification(button, progressContainer, response.data);
                        }
                    } else {
                        this.handleAjaxError(button, response.data);
                    }
                }.bind(this),
                error: function(xhr, status, error) {
                    this.showError(button, 'Network error: ' + error);
                    progressContainer.remove();
                }.bind(this),
                complete: function() {
                    this.hideLoadingState(button);
                }.bind(this)
            });
        },

        // Update bulk classification progress
        updateBulkProgress: function(container, data) {
            const progressBar = container.find('.progress-bar');
            const progressText = container.find('.progress-text');
            const percentage = Math.round((data.processed / data.total) * 100);
            
            progressBar.css('width', percentage + '%');
            progressText.text(htsManager.strings.bulk_processing.replace('%d', data.processed).replace('%d', data.total));
            
            // Update usage stats
            this.updateUsageStats(data.usage_stats);
        },

        // Complete bulk classification
        completeBulkClassification: function(button, progressContainer, data) {
            const successMessage = data.message || htsManager.strings.bulk_complete;
            this.showSuccess(button, successMessage);
            
            setTimeout(function() {
                progressContainer.fadeOut(function() {
                    $(this).remove();
                });
            }, 3000);
            
            // Update usage stats
            this.updateUsageStats(data.usage_stats);
            
            // Refresh page to show updated products
            setTimeout(function() {
                location.reload();
            }, 2000);
        },

        // Show classification result
        showClassificationResult: function(container, data) {
            const html = this.buildResultHTML(data);
            container.html(html).removeClass(this.config.classes.hidden);
            container.addClass(this.config.classes.success);
            
            // Add expand/collapse functionality
            this.initializeExpandableElements();
        },

        // Build result HTML
        buildResultHTML: function(data) {
            return `
                <div class="hts-result-success">
                    <div class="hts-result-header">
                        <strong>HTS Code: ${data.hts_code}</strong>
                        ${data.confidence ? `<span class="confidence">Confidence: ${data.confidence}%</span>` : ''}
                        <button class="hts-expand-toggle" type="button">
                            <span class="dashicons dashicons-arrow-down"></span>
                        </button>
                    </div>
                    <div class="hts-result-details hts-expandable">
                        ${data.explanation ? `<p><strong>Explanation:</strong> ${data.explanation}</p>` : ''}
                        <div class="hts-actions">
                            <button class="button hts-edit-inline" type="button">Edit Code</button>
                            <button class="button hts-regenerate" type="button">Regenerate</button>
                        </div>
                    </div>
                </div>
            `;
        },

        // Initialize usage counter display
        initializeUsageCounter: function() {
            this.updateUsageStats(htsManager.usageStats);
        },

        // Update usage statistics display
        updateUsageStats: function(stats) {
            const counters = $(this.config.selectors.usageCounter);
            
            counters.each(function() {
                const counter = $(this);
                const usedEl = counter.find('.used');
                const limitEl = counter.find('.limit');
                const remainingEl = counter.find('.remaining');
                const progressEl = counter.find('.progress-bar');
                
                if (usedEl.length) usedEl.text(stats.used);
                if (limitEl.length) limitEl.text(stats.is_pro ? '∞' : stats.limit);
                if (remainingEl.length) remainingEl.text(stats.is_pro ? '∞' : stats.remaining);
                
                if (progressEl.length && !stats.is_pro) {
                    const percentage = Math.min(100, stats.percentage_used);
                    progressEl.css('width', percentage + '%');
                    
                    // Add warning colors
                    progressEl.removeClass('warning danger');
                    if (percentage >= 80) {
                        progressEl.addClass('danger');
                    } else if (percentage >= 60) {
                        progressEl.addClass('warning');
                    }
                }
            });
            
            // Update global stats
            htsManager.usageStats = stats;
        },

        // Refresh usage statistics
        refreshUsageStats: function() {
            $.ajax({
                url: htsManager.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hts_get_usage_stats',
                    nonce: htsManager.nonce
                },
                success: function(response) {
                    if (response.success) {
                        this.updateUsageStats(response.data);
                    }
                }.bind(this)
            });
        },

        // Initialize expandable elements
        initializeExpandableElements: function() {
            $(this.config.selectors.expandToggle).off('click').on('click', this.toggleExpanded.bind(this));
        },

        // Toggle expanded state
        toggleExpanded: function(e) {
            e.preventDefault();
            
            const toggle = $(e.currentTarget);
            const container = toggle.closest('.hts-result-success, .hts-expandable-container');
            const expandable = container.find('.hts-expandable');
            const icon = toggle.find('.dashicons');
            
            if (container.hasClass(this.config.classes.expanded)) {
                // Collapse
                expandable.slideUp(200);
                container.removeClass(this.config.classes.expanded);
                icon.removeClass('dashicons-arrow-up').addClass('dashicons-arrow-down');
            } else {
                // Expand
                expandable.slideDown(200);
                container.addClass(this.config.classes.expanded);
                icon.removeClass('dashicons-arrow-down').addClass('dashicons-arrow-up');
            }
        },

        // Validate API key input in real-time
        validateApiKeyInput: function(e) {
            const input = $(e.currentTarget);
            const value = input.val().trim();
            const feedback = input.siblings('.validation-feedback');
            
            if (value.length === 0) {
                this.clearValidationState(input);
                return;
            }
            
            // Basic format validation
            if (value.startsWith('sk-ant-api03-') && value.length > 20) {
                this.markInputValid(input, 'Valid API key format');
            } else {
                this.markInputInvalid(input, 'Invalid API key format');
            }
        },

        // Validate HTS code input
        validateHtsCodeInput: function(e) {
            const input = $(e.currentTarget);
            const value = input.val().trim();
            
            if (value.length === 0) {
                this.clearValidationState(input);
                return;
            }
            
            // Validate HTS code format
            const htsPattern = /^\d{4}\.\d{2}\.\d{2}(\.\d{2})?$/;
            if (htsPattern.test(value)) {
                this.markInputValid(input, 'Valid HTS code format');
            } else {
                this.markInputInvalid(input, 'Use format: 0000.00.00 or 0000.00.00.00');
            }
        },

        // Get selected products for bulk operations
        getSelectedProducts: function() {
            const selected = [];
            $('input[name="post[]"]:checked').each(function() {
                selected.push($(this).val());
            });
            return selected;
        },

        // Create progress container for bulk operations
        createProgressContainer: function() {
            return $(`
                <div class="hts-bulk-progress">
                    <div class="progress-container">
                        <div class="progress-bar" style="width: 0%"></div>
                    </div>
                    <div class="progress-text">Starting...</div>
                </div>
            `);
        },

        // Check API key status on page load
        checkApiKeyStatus: function() {
            const apiKeyInput = $('.hts-api-key-input');
            if (apiKeyInput.length && apiKeyInput.val().trim()) {
                this.validateApiKeyInput({ currentTarget: apiKeyInput[0] });
            }
        },

        // Loading state management
        showLoadingState: function(button, message) {
            button.addClass(this.config.classes.loading)
                  .addClass(this.config.classes.disabled)
                  .prop('disabled', true);
                  
            const originalText = button.data('original-text') || button.text();
            button.data('original-text', originalText);
            
            button.html(`<span class="spinner is-active"></span> ${message || 'Loading...'}`);
        },

        hideLoadingState: function(button) {
            button.removeClass(this.config.classes.loading)
                  .removeClass(this.config.classes.disabled)
                  .prop('disabled', false);
                  
            const originalText = button.data('original-text');
            if (originalText) {
                button.text(originalText);
            }
        },

        // Message display
        showSuccess: function(element, message) {
            this.showMessage(element, message, 'success');
        },

        showError: function(element, message) {
            this.showMessage(element, message, 'error');
        },

        showMessage: function(element, message, type) {
            const messageEl = $(`<div class="hts-message hts-${type}">${message}</div>`);
            element.after(messageEl);
            
            setTimeout(function() {
                messageEl.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        hideMessages: function(container) {
            container.find('.hts-message').remove();
        },

        // Validation state management
        markInputValid: function(input, message) {
            input.removeClass('invalid').addClass('valid');
            this.updateValidationMessage(input, message, 'success');
        },

        markInputInvalid: function(input, message) {
            input.removeClass('valid').addClass('invalid');
            this.updateValidationMessage(input, message, 'error');
        },

        clearValidationState: function(input) {
            input.removeClass('valid invalid');
            input.siblings('.validation-feedback').remove();
        },

        updateValidationMessage: function(input, message, type) {
            let feedback = input.siblings('.validation-feedback');
            if (feedback.length === 0) {
                feedback = $('<div class="validation-feedback"></div>');
                input.after(feedback);
            }
            feedback.removeClass('success error').addClass(type).text(message);
        },

        markApiKeyValid: function(input) {
            input.addClass('api-valid').removeClass('api-invalid');
        },

        markApiKeyInvalid: function(input) {
            input.addClass('api-invalid').removeClass('api-valid');
        },

        // Error handling
        handleAjaxError: function(button, errorData) {
            if (errorData.upgrade_required) {
                this.showUpgradePrompt(button, errorData.message);
            } else {
                this.showError(button, errorData.message);
            }
            
            // Update usage stats if provided
            if (errorData.usage_stats) {
                this.updateUsageStats(errorData.usage_stats);
            }
        },

        showUpgradePrompt: function(button, message) {
            const upgradeHTML = `
                <div class="hts-upgrade-prompt">
                    <p>${message}</p>
                    <a href="#" class="button button-primary">Upgrade to Pro</a>
                    <button type="button" class="button dismiss-upgrade">Dismiss</button>
                </div>
            `;
            
            button.after(upgradeHTML);
            
            // Handle dismiss
            button.siblings('.hts-upgrade-prompt').find('.dismiss-upgrade').on('click', function() {
                $(this).closest('.hts-upgrade-prompt').fadeOut(function() {
                    $(this).remove();
                });
            });
        },

        // Utility functions
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },

        updateDisplayedCode: function(form, data) {
            form.find('.hts-current-code').text(data.hts_code || 'None');
            form.find('.hts-current-description').text(data.description || 'No description');
        }
    };

    // Initialize when DOM is ready
    HTSManager.init();
    
    // Make HTSManager globally available for debugging
    window.HTSManager = HTSManager;
});