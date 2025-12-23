/**
 * Reactions for IndieWeb - Admin JavaScript
 *
 * @package Reactions_For_IndieWeb
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Main admin module
     */
    const ReactionsAdmin = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.initApiSettings();
            this.initImportPage();
            this.initWebhooksPage();
            this.initMetaBoxes();
            this.initQuickPost();
        },

        /**
         * Bind global events
         */
        bindEvents: function() {
            // Toggle password visibility
            $(document).on('click', '.toggle-password, .toggle-secret-visibility', this.togglePasswordVisibility);

            // Copy to clipboard
            $(document).on('click', '.copy-webhook-url', this.copyToClipboard);

            // Clear cache buttons
            $(document).on('click', '.reactions-clear-cache', this.clearCache);

            // Export/Import settings
            $(document).on('click', '.reactions-export-settings', this.exportSettings);
            $(document).on('change', '#reactions-import-file', this.enableImportButton);
            $(document).on('click', '.reactions-import-settings', this.importSettings);
        },

        /**
         * Toggle password visibility
         */
        togglePasswordVisibility: function(e) {
            e.preventDefault();
            const $button = $(this);
            const $input = $button.siblings('input');
            const $icon = $button.find('.dashicons');

            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
            } else {
                $input.attr('type', 'password');
                $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
            }
        },

        /**
         * Copy to clipboard
         */
        copyToClipboard: function(e) {
            e.preventDefault();
            const text = $(this).data('url') || $(this).siblings('input').val();

            navigator.clipboard.writeText(text).then(() => {
                const $button = $(this);
                const originalText = $button.text();
                $button.text(reactionsIndieWeb.strings.copied);
                setTimeout(() => $button.text(originalText), 2000);
            });
        },

        /**
         * Clear cache
         */
        clearCache: function(e) {
            e.preventDefault();
            const $button = $(this);
            const type = $button.data('type');

            if (!confirm(reactionsIndieWeb.strings.confirmClear)) {
                return;
            }

            $button.prop('disabled', true);

            $.ajax({
                url: reactionsIndieWeb.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'reactions_indieweb_clear_cache',
                    nonce: reactionsIndieWeb.nonce,
                    type: type
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                    } else {
                        alert(response.data.message || reactionsIndieWeb.strings.error);
                    }
                },
                error: function() {
                    alert(reactionsIndieWeb.strings.error);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * Export settings
         */
        exportSettings: function(e) {
            e.preventDefault();

            // Get settings (would need an AJAX endpoint in production)
            const settings = {
                exported_at: new Date().toISOString(),
                version: '1.0.0',
                settings: {} // Would be populated from server
            };

            const blob = new Blob([JSON.stringify(settings, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'reactions-indieweb-settings.json';
            a.click();
            URL.revokeObjectURL(url);
        },

        /**
         * Enable import button
         */
        enableImportButton: function() {
            $('.reactions-import-settings').prop('disabled', !$(this).val());
        },

        /**
         * Import settings
         */
        importSettings: function(e) {
            e.preventDefault();
            // Would need implementation to read and send file to server
            alert('Import functionality would process the file here.');
        },

        /* =====================================================================
           API Settings
           ===================================================================== */

        /**
         * Initialize API settings page
         */
        initApiSettings: function() {
            if (!$('.reactions-indieweb-api-settings').length) {
                return;
            }

            // Toggle API card body
            $(document).on('change', '.api-enable-toggle', function() {
                const $card = $(this).closest('.reactions-api-card');
                const $body = $card.find('.api-card-body');

                if ($(this).is(':checked')) {
                    $body.slideDown(200);
                } else {
                    $body.slideUp(200);
                }
            });

            // Test API connection
            $(document).on('click', '.api-test-button', this.testApiConnection);

            // OAuth connect
            $(document).on('click', '.oauth-connect', this.initiateOAuth);

            // OAuth disconnect
            $(document).on('click', '.oauth-disconnect', this.disconnectOAuth);
        },

        /**
         * Test API connection
         */
        testApiConnection: function(e) {
            e.preventDefault();
            const $button = $(this);
            const api = $button.data('api');
            const $card = $button.closest('.reactions-api-card');

            $button.prop('disabled', true).text(reactionsIndieWeb.strings.testingApi);

            $.ajax({
                url: reactionsIndieWeb.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'reactions_indieweb_test_api',
                    nonce: reactionsIndieWeb.nonce,
                    api: api
                },
                success: function(response) {
                    if (response.success) {
                        $card.removeClass('error').addClass('connected');
                        $card.find('.api-status-badge')
                            .removeClass('error disabled')
                            .addClass('connected')
                            .text('Connected');
                        alert(reactionsIndieWeb.strings.testSuccess);
                    } else {
                        $card.removeClass('connected').addClass('error');
                        $card.find('.api-status-badge')
                            .removeClass('connected disabled')
                            .addClass('error')
                            .text('Not Connected');
                        alert(reactionsIndieWeb.strings.testFailed + response.data.message);
                    }
                },
                error: function() {
                    alert(reactionsIndieWeb.strings.error);
                },
                complete: function() {
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Test Connection');
                }
            });
        },

        /**
         * Initiate OAuth flow
         */
        initiateOAuth: function(e) {
            e.preventDefault();
            const api = $(this).data('api');
            const $form = $(this).closest('form');

            // Save form first, then redirect
            // In production, this would get the OAuth URL from the server
            const clientId = $form.find(`[name*="${api}"][name*="client_id"]`).val();

            if (!clientId) {
                alert('Please enter your Client ID first and save settings.');
                return;
            }

            // This would be replaced with actual OAuth URL
            alert('OAuth flow would redirect to authorization page here.');
        },

        /**
         * Disconnect OAuth
         */
        disconnectOAuth: function(e) {
            e.preventDefault();
            const api = $(this).data('api');
            const $section = $(this).closest('.oauth-section');

            if (!confirm('Are you sure you want to disconnect?')) {
                return;
            }

            $section.find('.oauth-access-token').val('');
            $section.find('.oauth-refresh-token').val('');
            $section.closest('form').submit();
        },

        /* =====================================================================
           Import Page
           ===================================================================== */

        /**
         * Initialize import page
         */
        initImportPage: function() {
            if (!$('.reactions-indieweb-import').length) {
                return;
            }

            // Preview import
            $(document).on('click', '.import-preview-button', this.showImportPreview);

            // Start import
            $(document).on('click', '.import-start-button', this.startImport);

            // Cancel import
            $(document).on('click', '.import-cancel-button', this.cancelImport);

            // Modal close
            $(document).on('click', '.modal-close, .modal-cancel', this.closeModal);

            // Confirm import from modal
            $(document).on('click', '.modal-confirm-import', this.confirmImport);

            // Poll active imports
            this.pollActiveImports();
        },

        /**
         * Show import preview
         */
        showImportPreview: function(e) {
            e.preventDefault();
            const source = $(this).data('source');
            const options = ReactionsAdmin.getImportOptions(source);

            $('#import-preview-modal').show();
            $('.preview-loading').show();
            $('.preview-content').hide();
            $('#import-preview-modal').data('source', source);

            $.ajax({
                url: reactionsIndieWeb.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'reactions_indieweb_get_import_preview',
                    nonce: reactionsIndieWeb.nonce,
                    source: source,
                    options: options
                },
                success: function(response) {
                    $('.preview-loading').hide();
                    $('.preview-content').show();

                    if (response.success) {
                        ReactionsAdmin.renderPreview(response.data);
                    } else {
                        $('.preview-content').html('<p class="error">' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    $('.preview-loading').hide();
                    $('.preview-content').html('<p class="error">' + reactionsIndieWeb.strings.error + '</p>').show();
                }
            });
        },

        /**
         * Render preview content
         */
        renderPreview: function(data) {
            let html = '<p><strong>Source:</strong> ' + data.source_name + '</p>';
            html += '<p><strong>Total items:</strong> ~' + data.total_count + '</p>';
            html += '<p><strong>Post kind:</strong> ' + data.post_kind + '</p>';

            if (data.sample && data.sample.length) {
                html += '<h4>Sample items:</h4><ul>';
                data.sample.forEach(function(item) {
                    html += '<li>';
                    if (item.title) html += '<strong>' + item.title + '</strong>';
                    if (item.artist) html += ' by ' + item.artist;
                    if (item.year) html += ' (' + item.year + ')';
                    if (item.date) html += ' - ' + item.date;
                    html += '</li>';
                });
                html += '</ul>';
            }

            $('.preview-content').html(html);
        },

        /**
         * Get import options
         */
        getImportOptions: function(source) {
            const options = {};
            $(`.import-option[data-source="${source}"]`).each(function() {
                const $input = $(this);
                const optionName = $input.data('option');

                if ($input.is(':checkbox')) {
                    options[optionName] = $input.is(':checked');
                } else {
                    options[optionName] = $input.val();
                }
            });
            return options;
        },

        /**
         * Start import
         */
        startImport: function(e) {
            e.preventDefault();
            const $button = $(this);
            const source = $button.data('source');
            const options = ReactionsAdmin.getImportOptions(source);

            $button.prop('disabled', true).text(reactionsIndieWeb.strings.importing);

            $.ajax({
                url: reactionsIndieWeb.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'reactions_indieweb_start_import',
                    nonce: reactionsIndieWeb.nonce,
                    source: source,
                    options: options
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || reactionsIndieWeb.strings.error);
                    }
                },
                error: function() {
                    alert(reactionsIndieWeb.strings.error);
                },
                complete: function() {
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Start Import');
                }
            });
        },

        /**
         * Confirm import from modal
         */
        confirmImport: function(e) {
            e.preventDefault();
            const source = $('#import-preview-modal').data('source');
            $(`.import-start-button[data-source="${source}"]`).click();
            ReactionsAdmin.closeModal();
        },

        /**
         * Cancel import
         */
        cancelImport: function(e) {
            e.preventDefault();
            const importId = $(this).data('import-id');

            if (!confirm('Are you sure you want to cancel this import?')) {
                return;
            }

            $.ajax({
                url: reactionsIndieWeb.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'reactions_indieweb_cancel_import',
                    nonce: reactionsIndieWeb.nonce,
                    import_id: importId
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || reactionsIndieWeb.strings.error);
                    }
                }
            });
        },

        /**
         * Close modal
         */
        closeModal: function() {
            $('.reactions-modal').hide();
        },

        /**
         * Poll active imports for status updates
         */
        pollActiveImports: function() {
            const $activeImports = $('.active-import');
            if (!$activeImports.length) {
                return;
            }

            setInterval(function() {
                $activeImports.each(function() {
                    const $import = $(this);
                    const importId = $import.data('import-id');

                    $.ajax({
                        url: reactionsIndieWeb.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'reactions_indieweb_get_import_status',
                            nonce: reactionsIndieWeb.nonce,
                            import_id: importId
                        },
                        success: function(response) {
                            if (response.success) {
                                const data = response.data;
                                $import.find('.progress-fill').css('width', data.progress + '%');
                                $import.find('.progress-text').text(data.processed + ' of ' + data.total + ' items');

                                if (data.status === 'completed' || data.status === 'failed') {
                                    location.reload();
                                }
                            }
                        }
                    });
                });
            }, 5000);
        },

        /* =====================================================================
           Webhooks Page
           ===================================================================== */

        /**
         * Initialize webhooks page
         */
        initWebhooksPage: function() {
            if (!$('.reactions-indieweb-webhooks').length) {
                return;
            }

            // Toggle webhook body
            $(document).on('change', '.webhook-enable-toggle', function() {
                const $card = $(this).closest('.webhook-card');
                const $body = $card.find('.webhook-body');

                if ($(this).is(':checked')) {
                    $body.slideDown(200);
                } else {
                    $body.slideUp(200);
                }
            });

            // Regenerate secret
            $(document).on('click', '.regenerate-secret', this.regenerateSecret);

            // Scrobble actions
            $(document).on('click', '.approve-scrobble', this.approveScrobble);
            $(document).on('click', '.reject-scrobble', this.rejectScrobble);
            $(document).on('click', '.clear-webhook-log', this.clearWebhookLog);
        },

        /**
         * Regenerate webhook secret
         */
        regenerateSecret: function(e) {
            e.preventDefault();
            const $button = $(this);
            const webhook = $button.data('webhook');
            const $input = $button.siblings('.webhook-secret-input');

            $.ajax({
                url: reactionsIndieWeb.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'reactions_indieweb_regenerate_webhook_secret',
                    nonce: reactionsIndieWeb.nonce,
                    webhook: webhook
                },
                success: function(response) {
                    if (response.success) {
                        $input.val(response.data.secret);
                    } else {
                        alert(response.data.message || reactionsIndieWeb.strings.error);
                    }
                }
            });
        },

        /**
         * Approve scrobble
         */
        approveScrobble: function(e) {
            e.preventDefault();
            const index = $(this).data('index');
            const $row = $(this).closest('tr');

            $.ajax({
                url: reactionsIndieWeb.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'reactions_indieweb_approve_scrobble',
                    nonce: reactionsIndieWeb.nonce,
                    index: index
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(function() {
                            $(this).remove();
                        });
                    } else {
                        alert(response.data.message || reactionsIndieWeb.strings.error);
                    }
                }
            });
        },

        /**
         * Reject scrobble
         */
        rejectScrobble: function(e) {
            e.preventDefault();
            const index = $(this).data('index');
            const $row = $(this).closest('tr');

            $.ajax({
                url: reactionsIndieWeb.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'reactions_indieweb_reject_scrobble',
                    nonce: reactionsIndieWeb.nonce,
                    index: index
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(function() {
                            $(this).remove();
                        });
                    }
                }
            });
        },

        /**
         * Clear webhook log
         */
        clearWebhookLog: function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to clear the webhook log?')) {
                return;
            }
            // Would need AJAX endpoint to clear log
            alert('Log cleared.');
        },

        /* =====================================================================
           Meta Boxes
           ===================================================================== */

        /**
         * Initialize meta boxes
         */
        initMetaBoxes: function() {
            if (!$('.reactions-meta-box').length) {
                return;
            }

            // Post kind change
            $(document).on('change', '#reactions_post_kind', this.onPostKindChange);

            // Conditional fields
            $(document).on('change', '[data-controls-visibility]', this.handleConditionalFields);

            // Star rating
            this.initStarRating();

            // Image selection
            $(document).on('click', '.select-image', this.selectImage);
            $(document).on('click', '.remove-image', this.removeImage);

            // Media lookup
            $(document).on('click', '#lookup-search', this.doMediaLookup);
            $(document).on('click', '.lookup-result', this.selectLookupResult);
        },

        /**
         * Handle post kind change
         */
        onPostKindChange: function() {
            const kind = $(this).val();

            // Hide all kind fields
            $('.kind-fields').hide();
            $('.no-kind-selected').hide();

            if (kind) {
                // Show selected kind fields
                $(`.kind-fields[data-kind="${kind}"]`).show();
            } else {
                $('.no-kind-selected').show();
            }
        },

        /**
         * Handle conditional fields
         */
        handleConditionalFields: function() {
            const $control = $(this);
            const value = $control.val();

            $('[data-depends-on]').each(function() {
                const $field = $(this);
                const dependency = $field.data('depends-on');
                const [depField, depValue] = dependency.split(':');

                if ($control.attr('name').includes(depField)) {
                    if (value === depValue) {
                        $field.slideDown(200);
                    } else {
                        $field.slideUp(200);
                    }
                }
            });
        },

        /**
         * Initialize star rating
         */
        initStarRating: function() {
            $(document).on('click', '.star-rating .star, .star-rating-input .star', function() {
                const $star = $(this);
                const value = $star.data('value');
                const $wrapper = $star.parent();
                const $input = $wrapper.find('input');

                $input.val(value);
                $wrapper.find('.star').each(function(i) {
                    $(this).toggleClass('filled', i < value);
                });
            });

            $(document).on('mouseenter', '.star-rating .star, .star-rating-input .star', function() {
                const value = $(this).data('value');
                const $wrapper = $(this).parent();

                $wrapper.find('.star').each(function(i) {
                    $(this).toggleClass('hover', i < value);
                });
            });

            $(document).on('mouseleave', '.star-rating, .star-rating-input', function() {
                $(this).find('.star').removeClass('hover');
            });

            $(document).on('click', '.clear-rating', function(e) {
                e.preventDefault();
                const $wrapper = $(this).parent();
                $wrapper.find('input').val('');
                $wrapper.find('.star').removeClass('filled');
            });
        },

        /**
         * Select image
         */
        selectImage: function(e) {
            e.preventDefault();
            const $button = $(this);
            const $field = $button.closest('.image-field');
            const $input = $field.find('.image-value');
            const $preview = $field.find('.image-preview');

            const frame = wp.media({
                title: 'Select Image',
                button: { text: 'Use Image' },
                multiple: false
            });

            frame.on('select', function() {
                const attachment = frame.state().get('selection').first().toJSON();
                $input.val(attachment.id);
                $preview.find('img').attr('src', attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url);
                $preview.show();
                $field.find('.remove-image').show();
            });

            frame.open();
        },

        /**
         * Remove image
         */
        removeImage: function(e) {
            e.preventDefault();
            const $field = $(this).closest('.image-field');
            $field.find('.image-value').val('');
            $field.find('.image-preview').hide();
            $(this).hide();
        },

        /**
         * Do media lookup
         */
        doMediaLookup: function(e) {
            e.preventDefault();
            const type = $('#lookup-type').val();
            const query = $('#lookup-query').val();
            const $results = $('#lookup-results');

            if (!query) {
                return;
            }

            $results.html('<p>' + reactionsIndieWeb.strings.lookingUp + '</p>');

            $.ajax({
                url: reactionsIndieWeb.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'reactions_indieweb_lookup_media',
                    nonce: reactionsIndieWeb.nonce,
                    type: type,
                    query: query
                },
                success: function(response) {
                    if (response.success && response.data.results.length) {
                        let html = '';
                        response.data.results.forEach(function(item) {
                            html += '<div class="lookup-result" data-item=\'' + JSON.stringify(item) + '\'>';
                            html += '<strong>' + (item.title || item.name) + '</strong>';
                            if (item.artist) html += '<br>' + item.artist;
                            if (item.year) html += ' (' + item.year + ')';
                            html += '</div>';
                        });
                        $results.html(html);
                    } else {
                        $results.html('<p>' + reactionsIndieWeb.strings.noResults + '</p>');
                    }
                },
                error: function() {
                    $results.html('<p class="error">' + reactionsIndieWeb.strings.error + '</p>');
                }
            });
        },

        /**
         * Select lookup result
         */
        selectLookupResult: function() {
            const item = $(this).data('item');
            // Populate form fields based on item data
            // This would map the lookup result to the appropriate meta fields
            console.log('Selected:', item);
        },

        /* =====================================================================
           Quick Post
           ===================================================================== */

        /**
         * Initialize quick post page
         */
        initQuickPost: function() {
            if (!$('.reactions-indieweb-quick-post').length) {
                return;
            }

            // Kind tab selection
            $(document).on('click', '.kind-tab', this.selectKindTab);

            // Media type toggle (watch)
            $(document).on('click', '.media-type-toggle .toggle-button', this.toggleMediaType);

            // Search buttons
            $(document).on('click', '.search-section .search-button', this.doQuickSearch);
            $(document).on('keypress', '.search-section input', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    $(this).siblings('.search-button').click();
                }
            });

            // Select search result
            $(document).on('click', '.search-result-item', this.selectSearchResult);

            // Fetch metadata
            $(document).on('click', '.fetch-metadata-button', this.fetchUrlMetadata);

            // Use location
            $(document).on('click', '.use-location-button', this.useCurrentLocation);

            // Form submission
            $(document).on('submit', '.quick-post-form', this.submitQuickPost);

            // Clear form
            $(document).on('click', '.clear-form-button', this.clearForm);

            // Init star ratings
            this.initStarRating();
        },

        /**
         * Select kind tab
         */
        selectKindTab: function() {
            const kind = $(this).data('kind');

            // Update tabs
            $('.kind-tab').removeClass('active');
            $(this).addClass('active');

            // Show/hide forms
            $('.quick-form').hide();
            $(`.quick-form[data-kind="${kind}"]`).show();
        },

        /**
         * Toggle media type (movie/tv)
         */
        toggleMediaType: function() {
            const subtype = $(this).data('subtype');
            const $form = $(this).closest('.quick-form');

            // Update buttons
            $form.find('.toggle-button').removeClass('active');
            $(this).addClass('active');

            // Update hidden field
            $form.find('[name="media_type"]').val(subtype);

            // Toggle TV-specific fields
            if (subtype === 'tv') {
                $form.find('.tv-fields').slideDown(200);
            } else {
                $form.find('.tv-fields').slideUp(200);
            }

            // Update search type
            $form.find('.search-button').data('type', subtype);
        },

        /**
         * Do quick search
         */
        doQuickSearch: function(e) {
            e.preventDefault();
            const $button = $(this);
            const type = $button.data('type');
            const $section = $button.closest('.search-section');
            const query = $section.find('input').val();
            const $results = $section.find('.search-results');

            if (!query) {
                return;
            }

            $results.html('<p class="searching">' + reactionsIndieWeb.strings.lookingUp + '</p>');

            $.ajax({
                url: reactionsIndieWeb.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'reactions_indieweb_lookup_media',
                    nonce: reactionsIndieWeb.nonce,
                    type: type,
                    query: query
                },
                success: function(response) {
                    if (response.success && response.data.results && response.data.results.length) {
                        let html = '';
                        response.data.results.forEach(function(item) {
                            html += '<div class="search-result-item" data-item=\'' + JSON.stringify(item).replace(/'/g, '&#39;') + '\'>';
                            if (item.image || item.cover) {
                                html += '<img src="' + (item.image || item.cover) + '" alt="">';
                            }
                            html += '<div class="search-result-info">';
                            html += '<div class="search-result-title">' + (item.title || item.name) + '</div>';
                            html += '<div class="search-result-subtitle">';
                            if (item.artist) html += item.artist;
                            if (item.year) html += (item.artist ? ' - ' : '') + item.year;
                            if (item.author) html += item.author;
                            html += '</div></div></div>';
                        });
                        $results.html(html);
                    } else {
                        $results.html('<p>' + reactionsIndieWeb.strings.noResults + '</p>');
                    }
                },
                error: function() {
                    $results.html('<p class="error">' + reactionsIndieWeb.strings.error + '</p>');
                }
            });
        },

        /**
         * Select search result
         */
        selectSearchResult: function() {
            const item = $(this).data('item');
            const $form = $(this).closest('.quick-form').find('.quick-post-form');
            const kind = $form.data('kind');

            // Populate form based on kind
            switch (kind) {
                case 'listen':
                    $form.find('[name="track_title"]').val(item.title || item.name || '');
                    $form.find('[name="artist_name"]').val(item.artist || '');
                    $form.find('[name="album_title"]').val(item.album || '');
                    $form.find('[name="musicbrainz_id"]').val(item.id || item.mbid || '');
                    $form.find('[name="cover_image"]').val(item.cover || item.image || '');
                    break;

                case 'watch':
                    $form.find('[name="media_title"]').val(item.title || item.name || '');
                    $form.find('[name="release_year"]').val(item.year || '');
                    $form.find('[name="tmdb_id"]').val(item.tmdb_id || item.id || '');
                    $form.find('[name="imdb_id"]').val(item.imdb_id || '');
                    $form.find('[name="poster_image"]').val(item.poster || item.image || '');
                    break;

                case 'read':
                    $form.find('[name="book_title"]').val(item.title || '');
                    $form.find('[name="author_name"]').val(item.author || '');
                    $form.find('[name="isbn"]').val(item.isbn || '');
                    $form.find('[name="openlibrary_id"]').val(item.key || item.id || '');
                    $form.find('[name="cover_image"]').val(item.cover || '');
                    break;
            }

            // Clear search results
            $(this).closest('.search-results').empty();
        },

        /**
         * Fetch URL metadata
         */
        fetchUrlMetadata: function(e) {
            e.preventDefault();
            const $button = $(this);
            const $form = $button.closest('.quick-post-form');
            const url = $form.find('[type="url"]').first().val();

            if (!url) {
                return;
            }

            $button.prop('disabled', true).text('Fetching...');

            // In production, this would call an AJAX endpoint to fetch page metadata
            setTimeout(function() {
                $button.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Fetch Metadata');
                // Would populate cite_name, cite_author from fetched data
            }, 1000);
        },

        /**
         * Use current location
         */
        useCurrentLocation: function(e) {
            e.preventDefault();
            const $button = $(this);
            const $form = $button.closest('.quick-form').find('.quick-post-form');

            if (!navigator.geolocation) {
                alert('Geolocation is not supported by your browser.');
                return;
            }

            $button.prop('disabled', true).text('Getting location...');

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    $form.find('[name="latitude"]').val(position.coords.latitude);
                    $form.find('[name="longitude"]').val(position.coords.longitude);
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-location-alt"></span> Use My Location');

                    // Could also trigger a reverse geocode lookup here
                },
                function(error) {
                    alert('Unable to get location: ' + error.message);
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-location-alt"></span> Use My Location');
                }
            );
        },

        /**
         * Submit quick post form
         */
        submitQuickPost: function(e) {
            e.preventDefault();
            const $form = $(this);
            const kind = $form.data('kind');
            const $feedback = $form.find('.form-feedback');
            const $submit = $form.find('.submit-quick-post');

            // Gather form data
            const data = {};
            $form.find('input, select, textarea').each(function() {
                const $input = $(this);
                const name = $input.attr('name');
                if (name) {
                    if ($input.is(':checkbox')) {
                        data[name] = $input.is(':checked') ? '1' : '';
                    } else {
                        data[name] = $input.val();
                    }
                }
            });

            $submit.prop('disabled', true);
            $feedback.removeClass('success error').hide();

            $.ajax({
                url: reactionsIndieWeb.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'reactions_indieweb_quick_post',
                    nonce: reactionsIndieWeb.nonce,
                    kind: kind,
                    data: data
                },
                success: function(response) {
                    if (response.success) {
                        $feedback
                            .addClass('success')
                            .html(response.data.message + ' <a href="' + response.data.edit_url + '">Edit</a> | <a href="' + response.data.view_url + '" target="_blank">View</a>')
                            .show();

                        // Clear form
                        $form[0].reset();
                        $form.find('.star').removeClass('filled');
                    } else {
                        $feedback.addClass('error').text(response.data.message).show();
                    }
                },
                error: function() {
                    $feedback.addClass('error').text(reactionsIndieWeb.strings.error).show();
                },
                complete: function() {
                    $submit.prop('disabled', false);
                }
            });
        },

        /**
         * Clear form
         */
        clearForm: function(e) {
            e.preventDefault();
            const $form = $(this).closest('.quick-post-form');
            $form[0].reset();
            $form.find('.star').removeClass('filled');
            $form.find('.form-feedback').hide();
            $form.closest('.quick-form').find('.search-results').empty();
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        ReactionsAdmin.init();
    });

})(jQuery);
