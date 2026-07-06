jQuery(document).ready(function($) {

    // -------------------------------------------------------------------------
    // Dashboard — scan button
    // -------------------------------------------------------------------------
    let currentScanId = null;
    let currentOffset = 0;

    $('#mut-start-scan').on('click', function(e) {
        e.preventDefault();
        const $btn = $(this);
        $btn.prop('disabled', true).text(mutAjax.i18n.scanning || 'Scanning...');

        $.post(mutAjax.ajax_url, {
            action: 'mut_start_scan',
            nonce:  mutAjax.nonce
        }, function(response) {
            if (response.success) {
                currentScanId = response.data.scan_id;
                currentOffset = 0;
                processNextBatch();
            } else {
                alert('Error starting scan: ' + (response.data || 'Unknown error'));
                $btn.prop('disabled', false).text('Start New Scan');
            }
        }).fail(function(jqXHR, textStatus) {
            alert('Failed to start scan: ' + textStatus);
            $btn.prop('disabled', false).text('Start New Scan');
        });
    });

    function processNextBatch() {
        if (!currentScanId) return;

        $.post(mutAjax.ajax_url, {
            action:  'mut_process_batch',
            nonce:   mutAjax.nonce,
            scan_id: currentScanId,
            offset:  currentOffset
        }, function(response) {
            if (response.success) {
                currentOffset = response.data.offset || currentOffset + 20;
                if (response.data.has_more) {
                    setTimeout(processNextBatch, 600);
                } else {
                    var $btn = $('#mut-start-scan');
                    $btn.prop('disabled', false).text('🔄 Start New Scan');
                    $('#mut-progress').text('✓ Scan complete — updating…').show();
                    $.post(mutAjax.ajax_url, { action: 'mut_get_dashboard_stats', nonce: mutAjax.nonce }, function (res) {
                        if (!res.success) return;
                        var d = res.data;
                        // Update summary cards
                        $('[data-stat]').each(function () {
                            var key = $(this).data('stat');
                            if (d[key] !== undefined) $(this).text(d[key]);
                        });
                        // Update scan info block
                        if (d.last_scan) {
                            var s = d.last_scan;
                            $('[data-scan="ago"]').text(s.ago).attr('title', s.exact);
                            $('[data-scan="status"]')
                                .text(s.status_label)
                                .removeClass('mut-scan-badge-completed mut-scan-badge-running mut-scan-badge-pending')
                                .addClass('mut-scan-badge-' + s.status);
                            $('[data-scan="total_attachments"]').text(s.total_attachments + ' files');
                            $('[data-scan="files_in_use"]').text(s.files_in_use);
                            $('[data-scan="unused_files"]').text(s.unused_files);
                        }
                        $('#mut-progress').text('✓ Scan complete').fadeOut(3000);
                    });
                }
            } else {
                alert('Scan error: ' + (response.data?.message || response.data || 'Unknown error'));
                $('#mut-start-scan').prop('disabled', false).text('Start New Scan');
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            alert('Batch AJAX failed: ' + textStatus);
            $('#mut-start-scan').prop('disabled', false).text('Start New Scan');
        });
    }

    // -------------------------------------------------------------------------
    // Cleanup Suggestions — collapsible section toggles
    // -------------------------------------------------------------------------
    $(document).on('click', '.mut-cs-toggle', function () {
        const $btn   = $(this);
        const $body  = $('#' + $btn.data('target'));
        const isOpen = $btn.hasClass('open');
        $btn.toggleClass('open', !isOpen).attr('aria-expanded', !isOpen);
        $body.toggleClass('hidden', isOpen);
    });

    // -------------------------------------------------------------------------
    // Bulk Review — checkbox selection + AJAX bulk actions
    // -------------------------------------------------------------------------
    const $table = $('#mut-bulk-table');
    if ( $table.length ) {

        const $countLabel = $('#mut-selected-count');
        const $bulkBtns   = $('.mut-bulk-btn');
        const nonce       = (typeof mutAjax !== 'undefined') ? mutAjax.bulk_nonce : '';

        // Single selector covering BOTH select-all checkboxes
        const SELECT_ALL = '#mut-select-all, #mut-select-all-top, .mut-select-all-bottom';

        function getChecked() {
            return $table.find('.mut-row-cb:checked');
        }

        function updateToolbar() {
            const count = getChecked().length;
            $countLabel.text(count + ' selected');
            $bulkBtns.prop('disabled', count === 0);

            // Keep both select-all checkboxes in sync
            const total = $table.find('.mut-row-cb').length;
            const allChecked = total > 0 && count === total;
            $(SELECT_ALL).prop('checked', allChecked);
        }

        // Either select-all checkbox toggles all rows
        $(document).on('change', SELECT_ALL, function () {
            const checked = $(this).prop('checked');
            $table.find('.mut-row-cb').prop('checked', checked);
            $(SELECT_ALL).prop('checked', checked); // keep both in sync
            updateToolbar();
        });

        // Individual row checkbox
        $table.on('change', '.mut-row-cb', function () {
            updateToolbar();
        });

        // Bulk action buttons
        $bulkBtns.on('click', function () {
            const action  = $(this).data('action');
            const ids     = getChecked().map(function () { return $(this).val(); }).get();
            const $notice = $('#mut-bulk-notice');

            if (ids.length === 0) return;

            $bulkBtns.prop('disabled', true);

            $.post(mutAjax.ajax_url, {
                action:      'mut_bulk_action',
                nonce:       nonce,
                bulk_action: action,
                ids:         ids
            }, function (res) {
                if (res.success) {
                    ids.forEach(function (id) {
                        const $row = $table.find('tr[data-id="' + id + '"]');
                        $row.removeClass('mut-row-flagged mut-row-archived');

                        let badge = '<span class="mut-review-badge mut-review-none">—</span>';
                        if (action === 'flag') {
                            badge = '<span class="mut-review-badge mut-review-flagged">🚩 Flagged</span>';
                            $row.addClass('mut-row-flagged');
                        } else if (action === 'archive') {
                            badge = '<span class="mut-review-badge mut-review-archived">📦 Archived</span>';
                            $row.addClass('mut-row-archived');
                        }
                        $row.find('.mut-review-status-cell').html(badge);
                        $row.find('.mut-row-cb').prop('checked', false);
                    });

                    $notice
                        .removeClass('hidden notice-error')
                        .addClass('notice notice-success inline')
                        .html('<p>✓ ' + res.data.message + '</p>')
                        .show();

                    updateToolbar();
                } else {
                    $notice
                        .removeClass('hidden notice-success')
                        .addClass('notice notice-error inline')
                        .html('<p>Error: ' + (res.data || 'Something went wrong.') + '</p>')
                        .show();
                    updateToolbar();
                }

                setTimeout(function () {
                    $notice.fadeOut(400, function () { $(this).addClass('hidden').hide(); });
                }, 4000);
            }).fail(function () {
                alert('Request failed. Please try again.');
                updateToolbar();
            });
        });

    } // end if $table.length

    // -------------------------------------------------------------------------
    // Duplicate Analysis — refresh cache button
    // -------------------------------------------------------------------------
    $('#mut-dup-refresh').on('click', function () {
        const $btn = $(this);
        const $msg = $('#mut-dup-refresh-msg');
        $btn.prop('disabled', true).text('Refreshing…');
        $msg.text('');

        $.post(mutAjax.ajax_url, {
            action: 'mut_refresh_duplicates',
            nonce:  mutAjax.nonce
        }, function (res) {
            if (res.success) {
                $msg.text('✓ Analysis updated — refreshing…');
                $.get(location.href, function (html) {
                    var $new = $(html).find('.mut-duplicate');
                    if ($new.length) {
                        $('.mut-duplicate').replaceWith($new);
                        $msg.text('');
                    } else {
                        $msg.text('✓ Analysis updated.');
                        $btn.prop('disabled', false).text('↻ Refresh Analysis');
                    }
                });
            } else {
                $msg.css('color', '#d63638').text('Error refreshing. Please try again.');
                $btn.prop('disabled', false).text('↻ Refresh Analysis');
            }
        }).fail(function () {
            $msg.css('color', '#d63638').text('Request failed.');
            $btn.prop('disabled', false).text('↻ Refresh Analysis');
        });
    });

    // -------------------------------------------------------------------------
    // Storage Optimization — recalculate button
    // -------------------------------------------------------------------------
    $('#mut-opt-refresh').on('click', function () {
        const $btn = $(this);
        const $msg = $('#mut-opt-refresh-msg');
        $btn.prop('disabled', true).text('Recalculating…');
        $msg.text('');

        $.post(mutAjax.ajax_url, {
            action: 'mut_refresh_optimization',
            nonce:  mutAjax.nonce
        }, function (res) {
            if (res.success) {
                $msg.text('✓ Recommendations updated — refreshing…');
                $.get(location.href, function (html) {
                    var $new = $(html).find('.mut-optimize');
                    if ($new.length) {
                        $('.mut-optimize').replaceWith($new);
                        $msg.text('');
                    } else {
                        $msg.text('✓ Recommendations updated.');
                        $btn.prop('disabled', false).text('↻ Recalculate');
                    }
                });
            } else {
                $msg.css('color', '#d63638').text('Error recalculating. Please try again.');
                $btn.prop('disabled', false).text('↻ Recalculate');
            }
        }).fail(function () {
            $msg.css('color', '#d63638').text('Request failed.');
            $btn.prop('disabled', false).text('↻ Recalculate');
        });
    });

    // -------------------------------------------------------------------------
    // Quality Audit — re-run button
    // -------------------------------------------------------------------------
    $('#mut-quality-refresh').on('click', function () {
        const $btn = $(this);
        const $msg = $('#mut-quality-refresh-msg');
        $btn.prop('disabled', true).text('Auditing…');
        $msg.text('');

        $.post(mutAjax.ajax_url, {
            action: 'mut_refresh_quality',
            nonce:  mutAjax.nonce
        }, function (res) {
            if (res.success) {
                $msg.text('✓ Audit updated — refreshing…');
                $.get(location.href, function (html) {
                    var $new = $(html).find('.mut-quality');
                    if ($new.length) {
                        $('.mut-quality').replaceWith($new);
                        $msg.text('');
                    } else {
                        $msg.text('✓ Audit updated.');
                        $btn.prop('disabled', false).text('↻ Re-run Audit');
                    }
                });
            } else {
                $msg.css('color', '#d63638').text('Error re-running audit.');
                $btn.prop('disabled', false).text('↻ Re-run Audit');
            }
        }).fail(function () {
            $msg.css('color', '#d63638').text('Request failed.');
            $btn.prop('disabled', false).text('↻ Re-run Audit');
        });
    });

    // -------------------------------------------------------------------------
    // AI Alt Text Generator — Quality Audit page
    // -------------------------------------------------------------------------
    (function () {
        const $btn      = $('#mut-generate-alt-text');
        const $progress = $('#mut-ai-progress');
        const $panel    = $('#mut-alttext-review-panel');
        const $list     = $('#mut-alttext-review-list');
        const $cbHeader = $('#mut-cb-header');
        const $cbAllInUse = $('#mut-select-all-inuse');
        const $genCount = $('#mut-gen-count');

        if ( ! $btn.length ) return;

        const allIds = JSON.parse($btn.attr('data-all-ids') || '[]');
        let suggestions = []; // [{id, thumb, title, text}]

        // Update the Generate button label + data-ids based on checked rows.
        function syncGenerateButton() {
            const checked = $('.mut-cb-row:checked').map(function(){ return parseInt($(this).val()); }).get();
            const ids = checked.length ? checked : allIds;
            $btn.attr('data-ids', JSON.stringify(ids));
            $genCount.text(ids.length.toLocaleString());
        }

        // Header checkbox: toggle all on current page.
        $cbHeader.on('change', function(){
            $('.mut-cb-row').prop('checked', this.checked);
            $cbAllInUse.prop('checked', false);
            syncGenerateButton();
        });

        // "Select all in use" checkbox: check only in-use rows.
        $cbAllInUse.on('change', function(){
            if ( this.checked ) {
                $('.mut-cb-row').prop('checked', false);
                $('tr[data-inuse="1"] .mut-cb-row').prop('checked', true);
                $cbHeader.prop('checked', false);
            } else {
                $('.mut-cb-row').prop('checked', false);
            }
            syncGenerateButton();
        });

        // Individual row checkbox changes.
        $(document).on('change', '.mut-cb-row', function(){
            $cbHeader.prop('checked', $('.mut-cb-row:not(:checked)').length === 0);
            $cbAllInUse.prop('checked', false);
            syncGenerateButton();
        });

        $btn.on('click', function () {
            const ids = JSON.parse($btn.attr('data-ids') || '[]');
            if ( ! ids.length ) return;

            $btn.prop('disabled', true).text('Generating…');
            $progress.show().text('0 of ' + ids.length);
            suggestions = [];
            $panel.hide();
            $list.empty();

            let done = 0;

            function next() {
                if ( done >= ids.length ) {
                    showReviewPanel();
                    return;
                }
                const id = ids[done];
                $.post(mutAjax.ajax_url, {
                    action:        'mut_generate_alt_text',
                    nonce:         mutAjax.nonce,
                    attachment_id: id
                }, function (res) {
                    done++;
                    $progress.text(done + ' of ' + ids.length);
                    if ( res.success ) {
                        suggestions.push({
                            id:    res.data.id,
                            text:  res.data.text,
                            thumb: mutAltText && mutAltText.thumbs ? (mutAltText.thumbs[id] || '') : '',
                            title: mutAltText && mutAltText.titles ? (mutAltText.titles[id] || 'Image #' + id) : 'Image #' + id,
                        });
                    }
                    next();
                }).fail(function () {
                    done++;
                    $progress.text(done + ' of ' + ids.length);
                    next();
                });
            }

            next();
        });

        function showReviewPanel() {
            $btn.prop('disabled', false);
            syncGenerateButton(); // restore label count
            $progress.hide();

            if ( ! suggestions.length ) {
                alert('No alt text could be generated. Check your API key in Settings.');
                return;
            }

            $list.empty();
            suggestions.forEach(function (item) {
                var row = $(
                    '<div class="mut-alttext-review-row" data-id="' + item.id + '">' +
                        '<div class="mut-alttext-thumb">' +
                            (item.thumb ? '<img src="' + item.thumb + '" width="60" height="60">' : '<span class="mut-no-thumb"></span>') +
                        '</div>' +
                        '<div class="mut-alttext-content">' +
                            '<div class="mut-alttext-title">' + $('<span>').text(item.title).html() + '</div>' +
                            '<input type="text" class="mut-alttext-input large-text" value="' + $('<span>').text(item.text).html() + '">' +
                        '</div>' +
                        '<div class="mut-alttext-row-action">' +
                            '<button class="button mut-save-one-alt">Save</button>' +
                            '<span class="mut-alttext-saved" style="display:none;color:#00a32a;margin-left:6px;">✓ Saved</span>' +
                        '</div>' +
                    '</div>'
                );
                $list.append(row);
            });

            $panel.show();
            $('html,body').animate({ scrollTop: $panel.offset().top - 40 }, 400);
        }

        // Save individual row
        $(document).on('click', '.mut-save-one-alt', function () {
            const $row = $(this).closest('.mut-alttext-review-row');
            const id   = $row.data('id');
            const text = $row.find('.mut-alttext-input').val().trim();
            const $btn = $(this);
            if ( ! text ) return;

            $btn.prop('disabled', true);
            $.post(mutAjax.ajax_url, {
                action:        'mut_save_alt_text',
                nonce:         mutAjax.nonce,
                attachment_id: id,
                alt_text:      text
            }, function (res) {
                if ( res.success ) {
                    $row.find('.mut-alttext-saved').show();
                    $btn.hide();
                } else {
                    alert('Save failed: ' + (res.data || 'Unknown error'));
                    $btn.prop('disabled', false);
                }
            }).fail(function () {
                alert('Request failed.');
                $btn.prop('disabled', false);
            });
        });

        // Save all
        $('#mut-save-all-alt').on('click', function () {
            const $saveBtn  = $(this);
            const $sp       = $('#mut-save-progress');
            const $rows     = $('.mut-alttext-review-row');
            const unsaved   = $rows.filter(function () {
                return $(this).find('.mut-save-one-alt').is(':visible');
            });

            if ( ! unsaved.length ) {
                $sp.text('All already saved.');
                return;
            }

            $saveBtn.prop('disabled', true);
            let done = 0;
            $sp.text('Saving…');

            unsaved.each(function () {
                const $row = $(this);
                const id   = $row.data('id');
                const text = $row.find('.mut-alttext-input').val().trim();
                if ( ! text ) { done++; return; }

                $.post(mutAjax.ajax_url, {
                    action:        'mut_save_alt_text',
                    nonce:         mutAjax.nonce,
                    attachment_id: id,
                    alt_text:      text
                }, function (res) {
                    if ( res.success ) {
                        $row.find('.mut-alttext-saved').show();
                        $row.find('.mut-save-one-alt').hide();
                    }
                    done++;
                    $sp.text(done + ' of ' + unsaved.length + ' saved');
                    if ( done >= unsaved.length ) {
                        $saveBtn.prop('disabled', false);
                        $sp.text('✓ All saved — re-running audit…');
                        setTimeout(function () {
                            $.post(mutAjax.ajax_url, { action: 'mut_refresh_quality', nonce: mutAjax.nonce }, function () {
                                $.get(location.href, function (html) {
                                    var $new = $(html).find('.mut-quality');
                                    if ($new.length) { $('.mut-quality').replaceWith($new); }
                                });
                            });
                        }, 1200);
                    }
                });
            });
        });

        // Cancel review
        $('#mut-cancel-alt-review').on('click', function () {
            $panel.hide();
            $btn.prop('disabled', false);
        });
    }());

    // -------------------------------------------------------------------------
    // Per-row AI alt text — Quality Detail page
    // -------------------------------------------------------------------------
    $(document).on('click', '.mut-qd-generate-one', function () {
        const $btn    = $(this);
        const id      = $btn.data('id');
        const $cell   = $btn.closest('.mut-qd-alttext-cell');
        const $review = $cell.find('.mut-qd-inline-review');
        const $input  = $cell.find('.mut-qd-alt-input');

        $btn.prop('disabled', true).text('Generating…');

        $.post(mutAjax.ajax_url, {
            action:        'mut_generate_alt_text',
            nonce:         mutAjax.nonce,
            attachment_id: id,
        }).done(function (res) {
            if (res.success) {
                $input.val(res.data.text);
                $review.show();
            } else {
                alert('Error: ' + (res.data || 'unknown error'));
            }
        }).fail(function () {
            alert('Request failed. Please try again.');
        }).always(function () {
            $btn.prop('disabled', false).text('✨ Generate');
        });
    });

    $(document).on('click', '.mut-qd-save-one', function () {
        const $btn   = $(this);
        const id     = $btn.data('id');
        const $cell  = $btn.closest('.mut-qd-alttext-cell');
        const $input = $cell.find('.mut-qd-alt-input');
        const text   = $input.val().trim();

        if (!text) { alert('Alt text cannot be empty.'); return; }

        $btn.prop('disabled', true).text('Saving…');

        $.post(mutAjax.ajax_url, {
            action:        'mut_save_alt_text',
            nonce:         mutAjax.nonce,
            attachment_id: id,
            alt_text:      text,
        }).done(function (res) {
            if (res.success) {
                // Update the displayed alt text and hide the review panel
                $cell.find('.mut-qd-alt-current')
                    .text(text)
                    .removeAttr('style')
                    .css({ color: '#00a32a', fontStyle: 'italic' });
                $cell.find('.mut-qd-inline-review').hide();
                $cell.find('.mut-qd-generate-one').text('✨ Re-generate');
            } else {
                alert('Failed to save: ' + (res.data || 'unknown error'));
            }
        }).fail(function () {
            alert('Request failed. Please try again.');
        }).always(function () {
            $btn.prop('disabled', false).text('Save');
        });
    });

    $(document).on('click', '.mut-qd-cancel-one', function () {
        $(this).closest('.mut-qd-inline-review').hide();
    });

    // -------------------------------------------------------------------------
    // AI Caption Generator — bulk (Quality Detail page, caption check)
    // -------------------------------------------------------------------------
    (function () {
        const $btn      = $('#mut-generate-caption');
        const $progress = $('#mut-ai-progress');
        const $panel    = $('#mut-caption-review-panel');
        const $list     = $('#mut-caption-review-list');
        const $cbHeader = $('#mut-cb-header');
        const $cbAllInUse = $('#mut-select-all-inuse');
        const $genCount = $('#mut-gen-count');

        if ( ! $btn.length ) return;

        const allIds = JSON.parse($btn.attr('data-all-ids') || '[]');
        let suggestions = [];

        function syncGenerateButton() {
            const checked = $('.mut-cb-row:checked').map(function(){ return parseInt($(this).val()); }).get();
            const ids = checked.length ? checked : allIds;
            $btn.attr('data-ids', JSON.stringify(ids));
            $genCount.text(ids.length.toLocaleString());
        }

        $cbHeader.on('change', function(){
            $('.mut-cb-row').prop('checked', this.checked);
            $cbAllInUse.prop('checked', false);
            syncGenerateButton();
        });

        $cbAllInUse.on('change', function(){
            if ( this.checked ) {
                $('.mut-cb-row').prop('checked', false);
                $('tr[data-inuse="1"] .mut-cb-row').prop('checked', true);
                $cbHeader.prop('checked', false);
            } else {
                $('.mut-cb-row').prop('checked', false);
            }
            syncGenerateButton();
        });

        $(document).on('change', '.mut-cb-row', function(){
            $cbHeader.prop('checked', $('.mut-cb-row:not(:checked)').length === 0);
            $cbAllInUse.prop('checked', false);
            syncGenerateButton();
        });

        $btn.on('click', function () {
            const ids = JSON.parse($btn.attr('data-ids') || '[]');
            if ( ! ids.length ) return;

            $btn.prop('disabled', true).text('Generating…');
            $progress.show().text('0 of ' + ids.length);
            suggestions = [];
            $panel.hide();
            $list.empty();

            let done = 0;

            function next() {
                if ( done >= ids.length ) { showReviewPanel(); return; }
                const id = ids[done];
                $.post(mutAjax.ajax_url, {
                    action:        'mut_generate_caption',
                    nonce:         mutAjax.nonce,
                    attachment_id: id
                }, function (res) {
                    done++;
                    $progress.text(done + ' of ' + ids.length);
                    if ( res.success ) {
                        suggestions.push({
                            id:    res.data.id,
                            text:  res.data.text,
                            thumb: mutAltText && mutAltText.thumbs ? (mutAltText.thumbs[id] || '') : '',
                            title: mutAltText && mutAltText.titles ? (mutAltText.titles[id] || 'Image #' + id) : 'Image #' + id,
                        });
                    }
                    next();
                }).fail(function () { done++; $progress.text(done + ' of ' + ids.length); next(); });
            }

            next();
        });

        function showReviewPanel() {
            $btn.prop('disabled', false);
            syncGenerateButton();
            $progress.hide();

            if ( ! suggestions.length ) {
                alert('No captions could be generated. Check your API key in Settings.');
                return;
            }

            $list.empty();
            suggestions.forEach(function (item) {
                var row = $(
                    '<div class="mut-alttext-review-row" data-id="' + item.id + '">' +
                        '<div class="mut-alttext-thumb">' +
                            (item.thumb ? '<img src="' + item.thumb + '" width="60" height="60">' : '<span class="mut-no-thumb"></span>') +
                        '</div>' +
                        '<div class="mut-alttext-content">' +
                            '<div class="mut-alttext-title">' + $('<span>').text(item.title).html() + '</div>' +
                            '<input type="text" class="mut-caption-input large-text" value="' + $('<span>').text(item.text).html() + '">' +
                        '</div>' +
                        '<div class="mut-alttext-row-action">' +
                            '<button class="button mut-save-one-caption">Save</button>' +
                            '<span class="mut-caption-saved" style="display:none;color:#00a32a;margin-left:6px;">✓ Saved</span>' +
                        '</div>' +
                    '</div>'
                );
                $list.append(row);
            });

            $panel.show();
            $('html,body').animate({ scrollTop: $panel.offset().top - 40 }, 400);
        }

        $(document).on('click', '.mut-save-one-caption', function () {
            const $row = $(this).closest('.mut-alttext-review-row');
            const id   = $row.data('id');
            const text = $row.find('.mut-caption-input').val().trim();
            const $b   = $(this);
            if ( ! text ) return;
            $b.prop('disabled', true);
            $.post(mutAjax.ajax_url, {
                action: 'mut_save_caption', nonce: mutAjax.nonce,
                attachment_id: id, caption: text
            }, function (res) {
                if ( res.success ) { $row.find('.mut-caption-saved').show(); $b.hide(); }
                else { alert('Save failed: ' + (res.data || 'Unknown error')); $b.prop('disabled', false); }
            }).fail(function () { alert('Request failed.'); $b.prop('disabled', false); });
        });

        $('#mut-save-all-caption').on('click', function () {
            const $saveBtn = $(this);
            const $sp      = $('#mut-save-progress');
            const unsaved  = $('.mut-alttext-review-row').filter(function () {
                return $(this).find('.mut-save-one-caption').is(':visible');
            });
            if ( ! unsaved.length ) { $sp.text('All already saved.'); return; }
            $saveBtn.prop('disabled', true);
            let done = 0;
            $sp.text('Saving…');
            unsaved.each(function () {
                const $row = $(this);
                const id   = $row.data('id');
                const text = $row.find('.mut-caption-input').val().trim();
                if ( ! text ) { done++; return; }
                $.post(mutAjax.ajax_url, {
                    action: 'mut_save_caption', nonce: mutAjax.nonce,
                    attachment_id: id, caption: text
                }, function (res) {
                    if ( res.success ) { $row.find('.mut-caption-saved').show(); $row.find('.mut-save-one-caption').hide(); }
                    done++;
                    $sp.text(done + ' of ' + unsaved.length + ' saved');
                    if ( done >= unsaved.length ) {
                        $saveBtn.prop('disabled', false);
                        $sp.text('✓ All saved — re-running audit…');
                        setTimeout(function () {
                            $.post(mutAjax.ajax_url, { action: 'mut_refresh_quality', nonce: mutAjax.nonce }, function () {
                                $.get(location.href, function (html) {
                                    var $new = $(html).find('.mut-quality');
                                    if ($new.length) { $('.mut-quality').replaceWith($new); }
                                });
                            });
                        }, 1200);
                    }
                });
            });
        });

        $('#mut-cancel-caption-review').on('click', function () {
            $panel.hide();
            $btn.prop('disabled', false);
        });
    }());

    // -------------------------------------------------------------------------
    // Per-row AI caption — Quality Detail page (caption check)
    // -------------------------------------------------------------------------
    $(document).on('click', '.mut-qd-generate-caption-one', function () {
        const $btn    = $(this);
        const id      = $btn.data('id');
        const $cell   = $btn.closest('.mut-qd-caption-cell');
        const $review = $cell.find('.mut-qd-caption-inline-review');
        const $input  = $cell.find('.mut-qd-caption-input');

        $btn.prop('disabled', true).text('Generating…');

        $.post(mutAjax.ajax_url, {
            action: 'mut_generate_caption', nonce: mutAjax.nonce, attachment_id: id,
        }).done(function (res) {
            if (res.success) { $input.val(res.data.text); $review.show(); }
            else { alert('Error: ' + (res.data || 'unknown error')); }
        }).fail(function () {
            alert('Request failed. Please try again.');
        }).always(function () {
            $btn.prop('disabled', false).text('✨ Generate');
        });
    });

    $(document).on('click', '.mut-qd-save-caption-one', function () {
        const $btn   = $(this);
        const id     = $btn.data('id');
        const $cell  = $btn.closest('.mut-qd-caption-cell');
        const text   = $cell.find('.mut-qd-caption-input').val().trim();
        if (!text) { alert('Caption cannot be empty.'); return; }
        $btn.prop('disabled', true).text('Saving…');
        $.post(mutAjax.ajax_url, {
            action: 'mut_save_caption', nonce: mutAjax.nonce, attachment_id: id, caption: text,
        }).done(function (res) {
            if (res.success) {
                $cell.find('.mut-qd-caption-current').text(text).css({ color: '#00a32a', fontStyle: 'italic' });
                $cell.find('.mut-qd-caption-inline-review').hide();
                $btn.closest('.mut-qd-caption-cell').find('.mut-qd-generate-caption-one').text('✨ Re-generate');
            } else { alert('Failed to save: ' + (res.data || 'unknown error')); }
        }).fail(function () { alert('Request failed. Please try again.'); })
        .always(function () { $btn.prop('disabled', false).text('Save'); });
    });

    $(document).on('click', '.mut-qd-cancel-caption-one', function () {
        $(this).closest('.mut-qd-caption-inline-review').hide();
    });

    // -------------------------------------------------------------------------
    // Mark Decorative — Quality Detail page (alt_text check)
    // -------------------------------------------------------------------------
    $(document).on('click', '.mut-mark-decorative', function () {
        const $btn       = $(this);
        const id         = $btn.data('id');
        const decorative = $btn.data('decorative') === 1 || $btn.data('decorative') === '1' ? 0 : 1;
        $btn.prop('disabled', true).text('Saving…');

        $.post(mutAjax.ajax_url, {
            action:        'mut_mark_decorative',
            nonce:         mutAjax.nonce,
            attachment_id: id,
            decorative:    decorative,
        }).done(function (res) {
            if (res.success) {
                $btn.data('decorative', decorative ? '1' : '0');
                $btn.text(decorative ? 'Unmark' : 'Mark Decorative');
                // A decorative image doesn't need alt text, so hide the AI
                // generate flow along with it — no point suggesting text for
                // something that's intentionally left blank. The Generate
                // button lives in the same <tr> as Mark Decorative, but not
                // always the same <td> (Quality Detail splits them into
                // separate columns), so look up from the row.
                const $row = $btn.closest('tr');
                $row.find('.mut-qd-generate-one').toggle(!decorative);
                if (decorative) {
                    $row.find('.mut-qd-inline-review').hide();
                }
                // Fade/strike just the current alt text value, not the whole
                // row — the buttons (especially "Unmark") need to stay at
                // full strength so they don't read as disabled/unclickable.
                const $current = $row.find('.mut-qd-alt-current, .mut-qd-caption-current');
                if (decorative) {
                    $current.css({ opacity: 0.35, textDecoration: 'line-through' });
                } else {
                    $current.css({ opacity: '', textDecoration: '' });
                }
            } else {
                alert('Failed: ' + (res.data || 'unknown error'));
            }
        }).fail(function () {
            alert('Request failed. Please try again.');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    // -------------------------------------------------------------------------
    // AI Cleanup Advisor — one-click per-row review actions (Unused Files page)
    // Reuses the same mut_bulk_action endpoint with a single id.
    // -------------------------------------------------------------------------
    $(document).on('click', '.mut-advice-act, .mut-advice-clear', function () {
        const $btn   = $(this);
        const id     = $btn.data('id');
        const action = $btn.data('action'); // 'archive' | 'flag' | 'clear'
        const nonce  = (typeof mutAjax !== 'undefined') ? mutAjax.bulk_nonce : '';
        const $cell  = $btn.closest('.mut-advice');

        $btn.prop('disabled', true);

        $.post(mutAjax.ajax_url, {
            action:      'mut_bulk_action',
            nonce:       nonce,
            bulk_action: action,
            ids:         [id]
        }, function (res) {
            if (!res.success) {
                alert('Error: ' + (res.data || 'Something went wrong.'));
                $btn.prop('disabled', false);
                return;
            }

            // Swap the cell's action area to reflect the new state.
            const $area = $cell.find('.mut-advice-action, .mut-advice-act');
            if (action === 'clear') {
                // Fetch the page and pull the fresh action cell for this row
                $.get(location.href, function (html) {
                    var $freshRow = $(html).find('tr[data-id="' + id + '"]');
                    if ($freshRow.length) {
                        $('tr[data-id="' + id + '"]').replaceWith($freshRow);
                    }
                });
                return;
            }

            const done = (action === 'archive')
                ? '<span class="mut-advice-action mut-advice-action--done"><span class="mut-advice-status mut-advice-status--archived">📦 Archived</span> <button type="button" class="button-link mut-advice-clear" data-id="' + id + '" data-action="clear">undo</button></span>'
                : '<span class="mut-advice-action mut-advice-action--done"><span class="mut-advice-status mut-advice-status--flagged">🚩 Flagged</span> <button type="button" class="button-link mut-advice-clear" data-id="' + id + '" data-action="clear">undo</button></span>';

            $btn.replaceWith(done);
        }).fail(function () {
            alert('Request failed. Please try again.');
            $btn.prop('disabled', false);
        });
    });

    // -------------------------------------------------------------------------
    // Advisor — bulk "Archive all candidates" / "Flag all for review" per category
    // Gathers every still-actionable row button inside the target category body.
    // -------------------------------------------------------------------------
    $(document).on('click', '.mut-advice-bulk-act', function () {
        const $btn      = $(this);
        const targetId  = $btn.data('target');
        const fallback  = $btn.data('default-action'); // 'archive' | 'flag'
        const nonce     = (typeof mutAjax !== 'undefined') ? mutAjax.bulk_nonce : '';
        const $body     = $('#' + targetId);

        // Only rows that still have an actionable button (not already flagged/archived).
        const $acts = $body.find('.mut-advice-act');
        if ($acts.length === 0) {
            alert('No remaining candidates in this category.');
            return;
        }

        // Group ids by their per-row action so verdicts are honored exactly.
        const groups = {};
        $acts.each(function () {
            const action = $(this).data('action') || fallback;
            (groups[action] = groups[action] || []).push($(this).data('id'));
        });

        if (!window.confirm('Apply review status to ' + $acts.length + ' file(s)?')) {
            return;
        }

        $btn.prop('disabled', true).text('Working…');

        const requests = Object.keys(groups).map(function (action) {
            return $.post(mutAjax.ajax_url, {
                action:      'mut_bulk_action',
                nonce:       nonce,
                bulk_action: action,
                ids:         groups[action]
            }).then(function (res) {
                if (res && res.success) {
                    groups[action].forEach(function (id) {
                        const $rowBtn = $body.find('.mut-advice-act[data-id="' + id + '"]');
                        const isArchive = (action === 'archive');
                        const badge = isArchive
                            ? '<span class="mut-advice-action mut-advice-action--done"><span class="mut-advice-status mut-advice-status--archived">📦 Archived</span> <button type="button" class="button-link mut-advice-clear" data-id="' + id + '" data-action="clear">undo</button></span>'
                            : '<span class="mut-advice-action mut-advice-action--done"><span class="mut-advice-status mut-advice-status--flagged">🚩 Flagged</span> <button type="button" class="button-link mut-advice-clear" data-id="' + id + '" data-action="clear">undo</button></span>';
                        $rowBtn.replaceWith(badge);
                    });
                }
            });
        });

        $.when.apply($, requests).always(function () {
            $btn.prop('disabled', false).text($btn.data('default-action') === 'archive' ? '📦 Archive all candidates' : '🚩 Flag all');
        });
    });

    // -------------------------------------------------------------------------
    // Safe Delete — confirmation modal with live gate checks
    // -------------------------------------------------------------------------
    (function () {
        const $modal = $('#mut-delete-modal');
        if (!$modal.length) return;

        const nonce       = (typeof mutAjax !== 'undefined') ? mutAjax.bulk_nonce : '';
        const $checks     = $('#mut-modal-checks');
        const $verdict    = $('#mut-modal-verdict');
        const $confirm    = $modal.find('.mut-modal-confirm');
        const $forceWrap  = $('#mut-modal-force-wrap');
        const $force      = $('#mut-modal-force');
        let currentId     = null;
        let isSafe        = false;

        function openModal(id, name) {
            currentId = id;
            isSafe = false;
            $('#mut-modal-filename').text(name || ('#' + id));
            $checks.html('<p class="mut-modal-loading">Running safety checks…</p>');
            $verdict.addClass('hidden').removeClass('mut-verdict-safe mut-verdict-blocked').text('');
            $forceWrap.addClass('hidden');
            $force.prop('checked', false);
            $confirm.prop('disabled', true).text('Move to Trash');
            $modal.removeClass('hidden').attr('aria-hidden', 'false');

            $.post(mutAjax.ajax_url, {
                action: 'mut_verify_delete',
                nonce:  nonce,
                id:     id
            }, function (res) {
                if (!res.success) {
                    $checks.html('<p class="mut-check-fail">Could not run checks: ' + (res.data || 'error') + '</p>');
                    return;
                }
                renderChecks(res.data);
            }).fail(function () {
                $checks.html('<p class="mut-check-fail">Request failed.</p>');
            });
        }

        function renderChecks(data) {
            isSafe = !!data.safe;
            let html = '<ul class="mut-check-list">';
            (data.checks || []).forEach(function (c) {
                const icon = c.status === 'pass' ? '✓' : (c.status === 'warn' ? '⚠' : '✕');
                const cls  = c.status === 'pass' ? 'mut-check-pass' : (c.status === 'warn' ? 'mut-check-warn' : 'mut-check-fail');
                html += '<li class="' + cls + '"><span class="mut-check-icon">' + icon + '</span>'
                      + '<span class="mut-check-label">' + c.label
                      + (c.detail ? ' <em>(' + c.detail + ')</em>' : '')
                      + '</span></li>';
            });
            html += '</ul>';
            $checks.html(html);

            if (isSafe) {
                $verdict.removeClass('hidden mut-verdict-blocked').addClass('mut-verdict-safe')
                        .text('✓ All safety checks passed. This file can be safely moved to trash.');
                $confirm.prop('disabled', false);
                $forceWrap.addClass('hidden');
            } else {
                $verdict.removeClass('hidden mut-verdict-safe').addClass('mut-verdict-blocked')
                        .text('✕ ' + data.blocking + ' blocking check(s) failed. Deletion is blocked for your safety.');
                $confirm.prop('disabled', true);
                $forceWrap.removeClass('hidden'); // allow explicit override
            }
        }

        function closeModal() {
            $modal.addClass('hidden').attr('aria-hidden', 'true');
            currentId = null;
        }

        // Open from any 🗑️ button
        $(document).on('click', '.mut-delete-btn', function () {
            openModal($(this).data('id'), $(this).data('name'));
        });

        // Force-override re-enables confirm
        $force.on('change', function () {
            if (!isSafe) {
                $confirm.prop('disabled', !$(this).prop('checked'))
                        .text($(this).prop('checked') ? 'Delete Anyway' : 'Move to Trash');
            }
        });

        // Confirm deletion
        $confirm.on('click', function () {
            if (!currentId) return;
            const $btn = $(this);
            $btn.prop('disabled', true).text('Deleting…');

            $.post(mutAjax.ajax_url, {
                action: 'mut_safe_delete',
                nonce:  nonce,
                id:     currentId,
                force:  (!isSafe && $force.prop('checked')) ? 1 : 0
            }, function (res) {
                const $notice = $('#mut-bulk-notice, #mut-cs-notice, #mut-dup-notice').first();
                if (res.success) {
                    $('tr[data-id="' + currentId + '"]').fadeOut(300, function () { $(this).remove(); });
                    $notice.removeClass('hidden notice-error').addClass('notice notice-success inline')
                           .html('<p>✓ ' + res.data.message + ' <a href="' + window.location.pathname + '?page=mut-trash">View Trash</a></p>').show();
                    setTimeout(function () { $notice.fadeOut(400, function () { $(this).addClass('hidden').hide(); }); }, 6000);
                    closeModal();
                } else {
                    $verdict.removeClass('hidden mut-verdict-safe').addClass('mut-verdict-blocked')
                            .text('✕ ' + (res.data || 'Deletion failed.'));
                    $btn.prop('disabled', false).text('Move to Trash');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('Move to Trash');
                alert('Request failed. Please try again.');
            });
        });

        // Close interactions
        $modal.on('click', '.mut-modal-close, .mut-modal-cancel', closeModal);
        $modal.on('click', function (e) { if (e.target === this) closeModal(); });
        $(document).on('keyup', function (e) { if (e.key === 'Escape') closeModal(); });
    })();

    // -------------------------------------------------------------------------
    // Trash Bin — restore, permanent delete, checkboxes
    // -------------------------------------------------------------------------
    (function () {
        if (!$('#mut-trash-bulk-bar').length && !$('.mut-trash-table').length) { return; }

        var nonce   = (typeof mutAjax !== 'undefined') ? mutAjax.bulk_nonce : '';
        var $notice = $('#mut-trash-notice');

        function showNotice(msg, isError) {
            $notice.removeClass('hidden notice-error notice-success')
                   .addClass('notice inline ' + (isError ? 'notice-error' : 'notice-success'))
                   .html('<p>' + msg + '</p>').show();
            setTimeout(function () { $notice.fadeOut(400, function () { $(this).addClass('hidden').show(); }); }, 6000);
        }

        function syncBulkBar() {
            var count = $('.mut-trash-cb-row:checked').length;
            $('#mut-trash-sel-count').text(count);
            $('.mut-trash-sel-count-sync').text(count);
            $('#mut-trash-bulk-bar').toggle(count > 0);
            $('.mut-trash-bulk-bar--bottom').toggle(count > 0);
        }

        // Select all / deselect all
        $(document).on('change', '#mut-trash-cb-all', function () {
            $('.mut-trash-cb-row').prop('checked', this.checked);
            syncBulkBar();
        });
        $(document).on('change', '.mut-trash-cb-row', function () {
            var total   = $('.mut-trash-cb-row').length;
            var checked = $('.mut-trash-cb-row:checked').length;
            $('#mut-trash-cb-all').prop('indeterminate', checked > 0 && checked < total)
                                  .prop('checked', checked === total);
            syncBulkBar();
        });

        // Per-row restore
        $(document).on('click', '.mut-restore-btn', function () {
            var $btn  = $(this);
            var logId = $btn.data('log-id');
            $btn.prop('disabled', true).text('Restoring…');
            $.post(mutAjax.ajax_url, { action: 'mut_restore_delete', nonce: nonce, log_id: logId },
                function (res) {
                    if (res.success) {
                        $btn.closest('tr').fadeOut(300, function () { $(this).remove(); syncBulkBar(); });
                        showNotice('✓ ' + res.data.message, false);
                    } else {
                        showNotice('Error: ' + (res.data || 'Restore failed.'), true);
                        $btn.prop('disabled', false).text('↩ Restore');
                    }
                }).fail(function () { $btn.prop('disabled', false).text('↩ Restore'); });
        });

        // Per-row permanent delete
        $(document).on('click', '.mut-perm-delete-btn', function () {
            var $btn  = $(this);
            var logId = $btn.data('log-id');
            if (!confirm('Permanently delete this file? This cannot be undone.')) { return; }
            $btn.prop('disabled', true).text('Deleting…');
            $.post(mutAjax.ajax_url, { action: 'mut_permanently_delete', nonce: nonce, log_ids: [logId] },
                function (res) {
                    if (res.success) {
                        $btn.closest('tr').fadeOut(300, function () { $(this).remove(); syncBulkBar(); });
                        showNotice('✓ File permanently deleted.', false);
                    } else {
                        showNotice('Error: ' + (res.data || 'Delete failed.'), true);
                        $btn.prop('disabled', false).text('🗑 Delete');
                    }
                }).fail(function () { $btn.prop('disabled', false).text('🗑 Delete'); });
        });

        // Bulk restore
        $(document).on('click', '.mut-trash-bulk-restore', function () {
            var ids = $('.mut-trash-cb-row:checked').map(function () { return $(this).val(); }).get();
            if (!ids.length) { return; }
            var $btn = $(this).prop('disabled', true).text('Restoring…');
            var done = 0, failed = 0;
            function next(i) {
                if (i >= ids.length) {
                    $btn.prop('disabled', false).text('↩ Restore Selected');
                    showNotice('✓ Restored ' + done + ' file(s).' + (failed ? ' ' + failed + ' failed.' : ''), failed > 0);
                    syncBulkBar();
                    return;
                }
                $.post(mutAjax.ajax_url, { action: 'mut_restore_delete', nonce: nonce, log_id: ids[i] },
                    function (res) {
                        if (res.success) {
                            $('tr[data-log-id="' + ids[i] + '"]').fadeOut(200, function () { $(this).remove(); });
                            done++;
                        } else { failed++; }
                        next(i + 1);
                    }).fail(function () { failed++; next(i + 1); });
            }
            next(0);
        });

        // Bulk permanent delete
        $(document).on('click', '.mut-trash-bulk-delete', function () {
            var ids = $('.mut-trash-cb-row:checked').map(function () { return $(this).val(); }).get();
            if (!ids.length) { return; }
            if (!confirm('Permanently delete ' + ids.length + ' file(s)? This cannot be undone.')) { return; }
            var $btn = $(this).prop('disabled', true).text('Deleting…');
            $.post(mutAjax.ajax_url, { action: 'mut_permanently_delete', nonce: nonce, log_ids: ids },
                function (res) {
                    $btn.prop('disabled', false).text('🗑 Delete Permanently');
                    if (res.success) {
                        ids.forEach(function (id) {
                            $('tr[data-log-id="' + id + '"]').fadeOut(200, function () { $(this).remove(); });
                        });
                        showNotice('✓ Permanently deleted ' + res.data.deleted + ' file(s).' +
                            (res.data.failed ? ' ' + res.data.failed + ' failed.' : ''), res.data.failed > 0);
                        syncBulkBar();
                    } else {
                        showNotice('Error: ' + (res.data || 'Delete failed.'), true);
                    }
                }).fail(function () { $btn.prop('disabled', false).text('🗑 Delete Permanently'); });
        });
    })();

    // -------------------------------------------------------------------------
    // Unused Files — checkbox select + bulk delete
    // -------------------------------------------------------------------------
    (function () {
        const nonce = (typeof mutAjax !== 'undefined') ? mutAjax.bulk_nonce : '';

        // Update the "Delete Selected (N)" button count and enabled state
        function updateBulkBtn(category) {
            const count = $('.mut-cs-select-row[data-category="' + category + '"]:checked').length;
            $('.mut-cs-sel-count[data-category="' + category + '"]').text(count);
            $('.mut-cs-bulk-delete[data-category="' + category + '"]').prop('disabled', count === 0);
        }

        // Select all checkbox
        $(document).on('change', '.mut-cs-select-all', function () {
            const cat = $(this).data('category');
            $('.mut-cs-select-row[data-category="' + cat + '"]').prop('checked', $(this).prop('checked'));
            updateBulkBtn(cat);
        });

        // Individual checkbox
        $(document).on('change', '.mut-cs-select-row', function () {
            const cat = $(this).data('category');
            const total   = $('.mut-cs-select-row[data-category="' + cat + '"]').length;
            const checked = $('.mut-cs-select-row[data-category="' + cat + '"]:checked').length;
            $('.mut-cs-select-all[data-category="' + cat + '"]').prop('indeterminate', checked > 0 && checked < total)
                                                                 .prop('checked', checked === total && total > 0);
            updateBulkBtn(cat);
        });

        // ---- Bulk delete modal ----
        const $bulkModal   = $('#mut-cs-bulk-modal');
        const $bulkStatus  = $('#mut-cs-bulk-status');
        const $bulkList    = $('#mut-cs-bulk-list');
        const $bulkConfirm = $('#mut-cs-bulk-confirm');
        let verifiedItems  = []; // {id, name, safe}

        function closeBulkModal() {
            $bulkModal.addClass('hidden').attr('aria-hidden', 'true');
            verifiedItems = [];
        }

        $(document).on('click', '.mut-cs-bulk-modal-close', closeBulkModal);
        $bulkModal.on('click', function (e) { if (e.target === this) closeBulkModal(); });

        // Open bulk modal — verify all selected files
        $(document).on('click', '.mut-cs-bulk-delete', function () {
            const cat = $(this).data('category');
            const selected = [];
            $('.mut-cs-select-row[data-category="' + cat + '"]:checked').each(function () {
                selected.push({ id: $(this).val(), name: $(this).data('name') });
            });
            if (!selected.length) return;

            verifiedItems = [];
            $bulkConfirm.prop('disabled', true);
            $bulkStatus.text('Verifying ' + selected.length + ' file(s)…');
            $bulkList.html('');
            $bulkModal.removeClass('hidden').attr('aria-hidden', 'false');

            // Verify each file sequentially
            let done = 0;
            function verifyNext() {
                if (done >= selected.length) {
                    const safeCount = verifiedItems.filter(function (i) { return i.safe; }).length;
                    $bulkStatus.html(
                        '<strong>' + safeCount + ' of ' + selected.length + ' files passed safety checks</strong>' +
                        (safeCount < selected.length ? ' — <span style="color:#d63638;">' + (selected.length - safeCount) + ' blocked (will be skipped)</span>' : '')
                    );
                    if (safeCount > 0) $bulkConfirm.prop('disabled', false).text('Delete ' + safeCount + ' Safe File(s)');
                    return;
                }
                const item = selected[done];
                $bulkStatus.text('Verifying ' + (done + 1) + ' of ' + selected.length + '…');

                $.post(mutAjax.ajax_url, {
                    action: 'mut_verify_delete',
                    nonce:  nonce,
                    id:     item.id
                }, function (res) {
                    const safe = res.success && res.data && res.data.safe;
                    verifiedItems.push({ id: item.id, name: item.name, safe: safe });
                    const icon = safe ? '<span style="color:#00a32a;">✓</span>' : '<span style="color:#d63638;">✕</span>';
                    const detail = safe ? 'Safe to delete' : (res.data && res.data.blocking ? res.data.blocking + ' check(s) failed' : 'Blocked');
                    $bulkList.append(
                        '<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f0f0f1;">' +
                        icon + '<span style="flex:1;font-size:13px;">' + $('<span>').text(item.name).html() + '</span>' +
                        '<span style="font-size:12px;color:#787c82;">' + detail + '</span></div>'
                    );
                    done++;
                    verifyNext();
                }).fail(function () {
                    verifiedItems.push({ id: item.id, name: item.name, safe: false });
                    $bulkList.append('<div style="padding:6px 0;border-bottom:1px solid #f0f0f1;color:#d63638;">✕ ' + $('<span>').text(item.name).html() + ' — Request failed</div>');
                    done++;
                    verifyNext();
                });
            }
            verifyNext();
        });

        // Confirm bulk delete — process safe files sequentially
        $bulkConfirm.on('click', function () {
            const safeItems = verifiedItems.filter(function (i) { return i.safe; });
            if (!safeItems.length) return;

            $bulkConfirm.prop('disabled', true).text('Deleting…');
            $bulkStatus.text('Deleting 0 of ' + safeItems.length + '…');

            let deleted = 0;
            let failed  = 0;

            function deleteNext(idx) {
                if (idx >= safeItems.length) {
                    const $notice = $('#mut-cs-notice');
                    $notice.removeClass('hidden notice-error').addClass('notice notice-success inline')
                        .html('<p>✓ Deleted ' + deleted + ' file(s). ' + (failed ? failed + ' failed. ' : '') +
                              '<a href="' + window.location.pathname + '?page=mut-trash">View Trash</a></p>').show();
                    setTimeout(function () { $notice.fadeOut(400, function () { $(this).addClass('hidden').show(); }); }, 8000);
                    closeBulkModal();
                    // Uncheck all
                    $('.mut-cs-select-row:checked').prop('checked', false);
                    $('.mut-cs-select-all').prop('checked', false).prop('indeterminate', false);
                    $('.mut-cs-sel-count').text('0');
                    $('.mut-cs-bulk-delete').prop('disabled', true);
                    return;
                }
                const item = safeItems[idx];
                $bulkStatus.text('Deleting ' + (idx + 1) + ' of ' + safeItems.length + '…');

                $.post(mutAjax.ajax_url, {
                    action: 'mut_safe_delete',
                    nonce:  nonce,
                    id:     item.id,
                    force:  0
                }, function (res) {
                    if (res.success) {
                        $('tr[data-id="' + item.id + '"]').fadeOut(200, function () { $(this).remove(); });
                        deleted++;
                    } else {
                        failed++;
                    }
                    deleteNext(idx + 1);
                }).fail(function () {
                    failed++;
                    deleteNext(idx + 1);
                });
            }
            deleteNext(0);
        });
    })();

    // ── Natural Language Search ────────────────────────────────────────────────
    (function () {
        var $bar    = $('#mut-nl-bar');
        if (!$bar.length) { return; }

        var $input  = $('#mut-nl-input');
        var $btn    = $('#mut-nl-submit');
        var $status = $('#mut-nl-status');
        var nonce   = mutAjax.nonce;

        function setStatus(msg, isError) {
            $status.text(msg)
                   .css('color', isError ? '#d63638' : '#646970')
                   .show();
        }

        function doSearch() {
            var query = $.trim($input.val());
            if (!query) { $input.focus(); return; }

            $btn.prop('disabled', true).text('Searching…');
            setStatus('Asking AI to parse your query…', false);

            $.post(mutAjax.ajax_url, {
                action: 'mut_nl_search',
                nonce:  nonce,
                query:  query
            }, function (res) {
                $btn.prop('disabled', false).text('Search with AI');
                if (!res.success) {
                    setStatus('AI error: ' + (res.data || 'Unknown error.'), true);
                    return;
                }
                var f = res.data;
                var params = new URLSearchParams();
                params.set('page', 'mut-search');
                if (f.usage_status) { params.set('usage_status', f.usage_status); }
                if (f.media_type)   { params.set('media_type',   f.media_type); }
                if (f.date_range)   { params.set('date_range',   f.date_range); }
                if (f.size)         { params.set('size',         f.size); }
                if (f.s)            { params.set('s',            f.s); }
                if (f.source)       { params.set('source',       f.source); }
                setStatus('Filters applied — redirecting…', false);
                window.location.href = 'admin.php?' + params.toString();
            }).fail(function () {
                $btn.prop('disabled', false).text('Search with AI');
                setStatus('Request failed. Please try again.', true);
            });
        }

        $btn.on('click', doSearch);
        $input.on('keydown', function (e) {
            if (e.key === 'Enter') { doSearch(); }
        });
    })();

    // ── Back to Top ────────────────────────────────────────────────────────────
    (function () {
        var $btn = $('<button type="button" id="mut-back-to-top" title="Back to top">↑ Top</button>');
        $('body').append($btn);

        $(window).on('scroll.mutBackToTop', function () {
            if ( $(window).scrollTop() > 300 ) {
                $btn.addClass('visible');
            } else {
                $btn.removeClass('visible');
            }
        });

        $btn.on('click', function () {
            $('html, body').animate({ scrollTop: 0 }, 300);
        });
    })();

});
