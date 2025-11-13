jQuery(document).ready(function($) {
    'use strict';

    // Global variables
    let currentSidepeek = null;
    let isLoading = false;

    // Initialize dashboard
    function initDashboard() {
        setupTabs();
        setupFilters();
        setupActionButtons();
        setupSidepeek();
        setupRefreshButton();
    }

    // Setup tabs functionality
    function setupTabs() {
        $('.woo-gf-tab-btn').on('click', function() {
            const tabName = $(this).data('tab');
            
            // Update tab buttons
            $('.woo-gf-tab-btn').removeClass('active');
            $(this).addClass('active');
            
            // Update tab panels
            $('.woo-gf-tab-panel').removeClass('active');
            $('#woo-gf-tab-' + tabName).addClass('active');
            
            // Store active tab in localStorage
            localStorage.setItem('woo_gf_active_tab', tabName);
        });
        
        // Restore last active tab
        const lastActiveTab = localStorage.getItem('woo_gf_active_tab');
        if (lastActiveTab) {
            $('.woo-gf-tab-btn[data-tab="' + lastActiveTab + '"]').click();
        }
    }

    // Setup filters
    function setupFilters() {
        $('#woo-gf-form-filter, #woo-gf-product-filter').on('change', function() {
            if (!isLoading) {
                location.reload();
            }
        });
    }

    // Setup action buttons
    function setupActionButtons() {
        $(document).on('click', '.woo-gf-edit-event', function(e) {
            // This is a link, let it work naturally
            return true;
        });

        $(document).on('click', '.view-registrations', function(e) {
            e.preventDefault();
            const eventId = $(this).data('event-id');
            const eventTitle = $(this).data('event-title');
            const formId = $(this).data('form-id');
            
            if (isLoading) return;
            
            showRegistrationsSidepeek(eventId, eventTitle, formId);
        });
        
        $(document).on('click', '.export-registrations', function(e) {
            e.preventDefault();
            
            // Check if button is disabled
            if ($(this).prop('disabled')) {
                alert('❌ אין הרשמות לייצוא\n\nעדיין לא נרשמו משתתפים לאירוע זה.');
                return;
            }
            
            const eventId = $(this).data('event-id');
            const formId = $(this).data('form-id');
            const eventTitle = $(this).data('event-title');
            const $btn = $(this);
            
            if (isLoading) return;
            
            exportRegistrationsToCSV(eventId, formId, eventTitle, $btn);
        });
    }

    // Setup refresh button
    function setupRefreshButton() {
        $('#refresh-dashboard').on('click', function() {
            if (!isLoading) {
                location.reload();
            }
        });
    }

    // Setup sidepeek functionality
    function setupSidepeek() {
        // Close button (delegated event for dynamically created elements)
        $(document).on('click', '.woo-gf-sidepeek-close', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeSidepeek();
        });
        
        // Close when clicking on overlay
        $(document).on('click', '.woo-gf-sidepeek-overlay', function(e) {
            e.preventDefault();
            closeSidepeek();
        });

        // Close sidepeek when clicking outside (on overlay area)
        $(document).on('click', function(e) {
            if ($(e.target).hasClass('woo-gf-sidepeek-overlay')) {
                closeSidepeek();
            }
        });

        // ESC key to close
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                closeSidepeek();
            }
        });
    }

    // Show registrations sidepeek
    function showRegistrationsSidepeek(eventId, eventTitle, formId) {
        if (isLoading) return;
        
        isLoading = true;
        
        // Create sidepeek with overlay
        const sidepeekHtml = `
            <div class="woo-gf-sidepeek-overlay"></div>
            <div class="woo-gf-sidepeek" id="registrations-sidepeek">
                <div class="woo-gf-sidepeek-header">
                    <h3>${eventTitle}</h3>
                    <button type="button" class="woo-gf-sidepeek-close" aria-label="סגור">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
                <div class="woo-gf-sidepeek-content">
                    <div class="woo-gf-loading">
                        <div class="woo-gf-spinner"></div>
                        <p>${wooGfDashboard.strings.loading}</p>
                    </div>
                </div>
            </div>
        `;

        // Remove existing sidepeek
        closeSidepeek();

        // Add new sidepeek and overlay
        $('body').append(sidepeekHtml);
        currentSidepeek = $('#registrations-sidepeek');

        // Animate in
        setTimeout(() => {
            $('.woo-gf-sidepeek-overlay').addClass('active');
            currentSidepeek.addClass('woo-gf-sidepeek-active');
        }, 10);

        // Load registrations data - pass the correct parameters
        loadRegistrationsData(eventId, formId);
    }

    // Load registrations data via AJAX
    function loadRegistrationsData(eventId, formId) {
        $.ajax({
            url: wooGfDashboard.ajaxUrl,
            type: 'POST',
            data: {
                action: 'woo_gf_get_event_registrations',
                nonce: wooGfDashboard.nonce,
                event_id: eventId,
                event_title: currentSidepeek ? currentSidepeek.find('h3').text() : ''
            },
            success: function(response) {
                if (response.success && response.data && response.data.html) {
                    displayRegistrations(response.data.html);
                } else {
                    // Display "no registrations" message
                    displayNoRegistrations();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX Error:', textStatus, errorThrown);
                displayError('שגיאה בטעינת הנתונים. אנא נסה שוב.');
            },
            complete: function() {
                isLoading = false;
            }
        });
    }
    
    // Display no registrations message
    function displayNoRegistrations() {
        if (!currentSidepeek) return;
        
        const contentContainer = currentSidepeek.find('.woo-gf-sidepeek-content');
        contentContainer.html(`
            <div class="woo-gf-empty-state" style="padding: 40px; text-align: center;">
                <div style="font-size: 48px; margin-bottom: 20px;">👥</div>
                <h3 style="color: #666; margin-bottom: 10px;">אין נרשמים</h3>
                <p style="color: #999;">עדיין לא נרשמו משתתפים לאירוע זה.</p>
            </div>
        `);
    }

    // Display registrations in sidepeek
    function displayRegistrations(content) {
        if (!currentSidepeek) return;
        
        const contentContainer = currentSidepeek.find('.woo-gf-sidepeek-content');
        contentContainer.html(content);
        
        // Add some nice animations
        contentContainer.find('.woo-gf-registration-row').each(function(index) {
            $(this).css({
                'opacity': '0',
                'transform': 'translateX(20px)'
            });
            
            setTimeout(() => {
                $(this).css({
                    'opacity': '1',
                    'transform': 'translateX(0)',
                    'transition': 'all 0.3s ease'
                });
            }, index * 50);
        });
    }

    // Display error message
    function displayError(message) {
        if (!currentSidepeek) return;
        
        const contentContainer = currentSidepeek.find('.woo-gf-sidepeek-content');
        contentContainer.html(`
            <div class="woo-gf-error">
                <div class="woo-gf-error-icon">⚠️</div>
                <h4>${wooGfDashboard.strings.error}</h4>
                <p>${message}</p>
            </div>
        `);
    }

    // Close sidepeek
    function closeSidepeek() {
        $('.woo-gf-sidepeek-overlay').removeClass('active');
        
        if (currentSidepeek) {
            currentSidepeek.removeClass('woo-gf-sidepeek-active');
            setTimeout(() => {
                currentSidepeek.remove();
                $('.woo-gf-sidepeek-overlay').remove();
                currentSidepeek = null;
            }, 300);
        }
    }

    // Export registrations to CSV
    function exportRegistrationsToCSV(eventId, formId, eventTitle, $btn) {
        if (isLoading) return;
        
        isLoading = true;
        
        // Show loading state
        const originalText = $btn.html();
        $btn.prop('disabled', true);
        $btn.html('<span class="dashicons dashicons-update-alt" style="animation: spin 1s linear infinite;"></span>');
        
        $.ajax({
            type: 'POST',
            url: wooGfDashboard.ajaxUrl,
            data: {
                action: 'woo_gf_export_registrations',
                nonce: wooGfDashboard.nonce,
                product_id: eventId,
                form_id: formId,
            },
            success: function(response) {
                if (response.success && response.data && response.data.csv_data) {
                    // Create a Blob from the CSV data
                    const csvBlob = new Blob([response.data.csv_data], { type: 'text/csv;charset=utf-8;' });
                    
                    // Create a temporary link element and trigger download
                    const link = document.createElement('a');
                    const url = URL.createObjectURL(csvBlob);
                    
                    link.setAttribute('href', url);
                    link.setAttribute('download', response.data.filename);
                    link.style.visibility = 'hidden';
                    
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    
                    // Show success message
                    showSuccessMessage('✅ הקובץ הורד בהצלחה!');
                } else {
                    // Show user-friendly error
                    const errorMsg = response.data && response.data.message ? response.data.message : 'שגיאה בייצוא הנתונים';
                    showExportError(errorMsg);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('Export Error:', textStatus, errorThrown);
                showExportError('שגיאה בתקשורת עם השרת. אנא נסה שוב.');
            },
            complete: function() {
                // Reset button state
                $btn.prop('disabled', false);
                $btn.html(originalText);
                isLoading = false;
            }
        });
    }

    // Show export error
    function showExportError(message) {
        const alertHtml = `
            <div class="woo-gf-alert woo-gf-alert-error" style="position: fixed; top: 80px; right: 20px; z-index: 99999; max-width: 400px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                <div style="padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 5px; color: #856404; font-weight: 500;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 24px;">⚠️</span>
                        <div>${message}</div>
                    </div>
                </div>
            </div>
        `;
        
        const $alert = $(alertHtml);
        $('body').append($alert);
        
        setTimeout(() => {
            $alert.fadeOut(function() {
                $(this).remove();
            });
        }, 4000);
    }
    
    // Show success message
    function showSuccessMessage(message) {
        const alertHtml = `
            <div class="woo-gf-alert woo-gf-alert-success" style="position: fixed; top: 80px; right: 20px; z-index: 99999; max-width: 400px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                <div style="padding: 15px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 5px; color: #155724; font-weight: 500;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 24px;">✅</span>
                        <div>${message}</div>
                    </div>
                </div>
            </div>
        `;
        
        const $alert = $(alertHtml);
        $('body').append($alert);
        
        setTimeout(() => {
            $alert.fadeOut(function() {
                $(this).remove();
            });
        }, 3000);
    }

    // Initialize dashboard when document is ready
    initDashboard();
}); 