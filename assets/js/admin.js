jQuery(document).ready(function($) {
    // Load entries when container is visible
    var entriesContainer = $('#woo_gf_entries_container');
    if (entriesContainer.length) {
        loadEntries();
    }

    // Handle view entries button click
    $('#woo_gf_view_entries').on('click', function(e) {
        e.preventDefault();
        var formId = $('#_woo_gf_form_id').val();
        if (!formId) {
            alert(atWooGfIntegration.strings.error);
            return;
        }
        
        // Scroll to entries container
        $('html, body').animate({
            scrollTop: entriesContainer.offset().top - 50
        }, 500);
        
        loadEntries();
    });

    // Load entries via AJAX
    function loadEntries(page) {
        page = page || 1;
        var formId = entriesContainer.data('form-id');
        var productId = entriesContainer.data('product-id');
        
        if (!formId) {
            return;
        }
        
        entriesContainer.html('<p>' + atWooGfIntegration.strings.loading + '</p>');
        
        $.ajax({
            url: atWooGfIntegration.ajax_url,
            type: 'POST',
            data: {
                action: 'woo_gf_get_entries',
                form_id: formId,
                product_id: productId,
                page: page,
                nonce: atWooGfIntegration.nonce
            },
            success: function(response) {
                if (response.success) {
                    entriesContainer.html(response.data.html);
                } else {
                    entriesContainer.html('<p>' + atWooGfIntegration.strings.error + '</p>');
                }
            },
            error: function() {
                entriesContainer.html('<p>' + atWooGfIntegration.strings.error + '</p>');
            }
        });
    }

    // Handle pagination
    $(document).on('click', '#woo_gf_entries_container .prev-page, #woo_gf_entries_container .next-page', function(e) {
        e.preventDefault();
        var page = $(this).data('page');
        loadEntries(page);
    });

    // Handle view entry details
    $(document).on('click', '.woo-gf-view-entry', function(e) {
        e.preventDefault();
        var entryId = $(this).data('entry-id');
        var formId = $(this).data('form-id');
        
        // Create modal
        var modal = $('<div class="woo-gf-modal"><div class="woo-gf-modal-content"><span class="woo-gf-modal-close">&times;</span><div class="woo-gf-modal-body">' + atWooGfIntegration.strings.loading + '</div></div></div>');
        $('body').append(modal);
        
        // Load entry details
        $.ajax({
            url: atWooGfIntegration.ajax_url,
            type: 'POST',
            data: {
                action: 'woo_gf_get_entry_details',
                entry_id: entryId,
                form_id: formId,
                nonce: atWooGfIntegration.nonce
            },
            success: function(response) {
                if (response.success) {
                    modal.find('.woo-gf-modal-body').html(response.data.html);
                } else {
                    modal.find('.woo-gf-modal-body').html('<p>' + atWooGfIntegration.strings.error + '</p>');
                }
            },
            error: function() {
                modal.find('.woo-gf-modal-body').html('<p>' + atWooGfIntegration.strings.error + '</p>');
            }
        });
        
        // Close modal
        modal.on('click', '.woo-gf-modal-close', function() {
            modal.remove();
        });
        
        modal.on('click', function(e) {
            if (e.target === this) {
                modal.remove();
            }
        });
    });

    // Update entries when form selection changes
    $('#_woo_gf_form_id').on('change', function() {
        var formId = $(this).val();
        if (formId) {
            entriesContainer.data('form-id', formId);
            loadEntries();
        } else {
            entriesContainer.html('<p>' + entriesContainer.data('no-form-message') + '</p>');
        }
    });

    // Handle create new form button
    $('#woo_gf_create_form').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var wrapper = $('#gravity_forms_product_data');
        var productId = wrapper.data('product-id');
        var currentFormId = $('#_woo_gf_form_id').val();
        
        // Check if product is new (not saved yet)
        if (!productId || productId === 0 || productId === '0') {
            // Show user-friendly dialog for new products
            var saveAndCreate = confirm(
                '📝 המוצר טרם נשמר\n\n' +
                'על מנת ליצור טופס אוטומטית, יש לשמור את המוצר תחילה.\n\n' +
                '💡 לחץ OK כדי לשמור את המוצר כעת (בסטטוס "טיוטה").\n' +
                'לאחר השמירה, תוכל ליצור טופס אוטומטית.\n\n' +
                'או לחץ Cancel ושמור את המוצר ידנית.'
            );
            
            if (saveAndCreate) {
                // Save the product as draft first
                var productTitle = $('#title').val();
                
                if (!productTitle || productTitle.trim() === '') {
                    alert('❌ שגיאה: נא למלא כותרת למוצר לפני השמירה.');
                    $('#title').focus();
                    return;
                }
                
                // Show saving message
                button.prop('disabled', true).html(
                    '<span class="spinner is-active" style="float: right; margin: 4px;"></span> ' +
                    'שומר מוצר...'
                );
                
                // Trigger WordPress save (publish button click)
                $('#publish').trigger('click');
                
                // Show message to user
                setTimeout(function() {
                    alert(
                        '✅ המוצר נשמר בהצלחה!\n\n' +
                        'כעת תוכל ללחוץ שוב על "צור טופס חדש" ליצירת טופס אוטומטי.'
                    );
                    button.prop('disabled', false).html(
                        '<span class="dashicons dashicons-plus-alt" style="vertical-align: text-bottom;"></span> ' +
                        'צור טופס חדש'
                    );
                }, 2000);
            }
            return;
        }
        
        // Confirmation if a form is already linked
        if (currentFormId && currentFormId !== '') {
            if (!confirm('כבר קיים טופס משויך למוצר זה. האם ברצונך ליצור טופס חדש ולהחליף את הקיים?')) {
                return;
            }
        }
        
        // Disable button and show loading
        button.prop('disabled', true).html('<span class="spinner is-active" style="float: right; margin: 4px;"></span> ' + 'יוצר טופס...');
        
        // AJAX request to create the form
        $.ajax({
            url: atWooGfIntegration.ajax_url,
            type: 'POST',
            data: {
                action: 'haruv_create_gf_form_for_event',
                product_id: productId,
                security: $('#haruv_event_gf_nonce_field').val()
            },
            success: function(response) {
                if (response.success) {
                    var formId = response.data.form_id;
                    var formTitle = response.data.form_title;
                    var editUrl = response.data.edit_url;

                    // Add new form to the dropdown and select it
                    var newOption = new Option(formTitle, formId, true, true);
                    $('#_woo_gf_form_id').append(newOption).trigger('change');

                    // Update UI elements
                    button.html('<span class="dashicons dashicons-plus-alt" style="vertical-align: text-bottom;"></span> ' + 'החלף בטופס חדש');
                    
                    // Remove existing edit button if it exists
                    wrapper.find('a[href*="gf_edit_forms"]').remove();

                    // Add a new edit button
                    var editButton = '<a href="' + editUrl + '" class="button" target="_blank">' +
                                     '<span class="dashicons dashicons-edit" style="vertical-align: text-bottom;"></span> ' +
                                     'ערוך טופס' +
                                     '</a>';
                    button.after(editButton);
                    
                    // Show success message
                    alert('✅ הטופס נוצר בהצלחה!\n\nהטופס "' + formTitle + '" נוצר וקושר למוצר.');

                } else {
                    alert('❌ שגיאה ביצירת הטופס:\n\n' + response.data.message);
                }
            },
            error: function() {
                alert('❌ אירעה שגיאת תקשורת.\n\nנסה שוב או בדוק את החיבור לשרת.');
            },
            complete: function() {
                // Re-enable button
                button.prop('disabled', false);
                if (!button.text().includes('החלף')) {
                    button.html('<span class="dashicons dashicons-plus-alt" style="vertical-align: text-bottom;"></span> ' + 'צור טופס חדש');
                }
            }
        });
    });

    // --- Dashboard Improvements ---

    // 1. Default to "Event" product type on new product creation
    if ($('body').hasClass('post-new-php') && $('body').hasClass('post-type-product')) {
        var productTypeSelect = $('#product-type');
        // Check if "event" exists in the options
        if (productTypeSelect.find('option[value="event"]').length > 0) {
            // Only set if not already set
            if (productTypeSelect.val() !== 'event') {
                productTypeSelect.val('event').trigger('change');
            }
        }
    }

    // 2. Add "Quick Save" button to sticky Admin Bar
    if ($('body').hasClass('post-type-product') && ($('body').hasClass('post-php') || $('body').hasClass('post-new-php'))) {
        var adminBar = $('#wp-admin-bar-root-default');
        
        if (adminBar.length > 0) {
            // Create list item for admin bar
            var li = $('<li id="wp-admin-bar-quick-save-product"></li>');
            var div = $('<div class="ab-item ab-empty-item" style="padding: 0 10px; display: flex; align-items: center; height: 32px;"></div>');
            var saveBtn = $('<button type="button" class="button button-primary">שמור שינויים</button>');
            
            // Logic for click
            saveBtn.on('click', function(e) {
                e.preventDefault();
                
                var saveDraftBtn = $('#save-post');
                var publishBtn = $('#publish');
                
                // Visual feedback
                var originalText = $(this).text();
                $(this).text('שומר...').prop('disabled', true);
                var self = $(this);
                
                // Logic:
                // - If #save-post is visible (Draft/Pending/New), click it.
                // - If #save-post is hidden, use #publish (Update for published posts).
                
                if (saveDraftBtn.is(':visible')) {
                    saveDraftBtn.trigger('click');
                } else {
                    publishBtn.trigger('click');
                }
                
                // Reset button after delay (if page doesn't reload)
                setTimeout(function() {
                    self.text(originalText).prop('disabled', false);
                }, 3000);
            });
            
            div.append(saveBtn);
            li.append(div);
            
            // Add to admin bar (append adds it to the end of the group)
            adminBar.append(li);
        }
    }
}); 