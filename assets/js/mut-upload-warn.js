/**
 * MUT — Upload size warning.
 *
 * Strategy: plupload uses raw XHR so $.ajaxSuccess / jQuery events don't fire.
 * Instead we use the server-side add_attachment hook to store a transient, then:
 *  - On media-new.php: MutationObserver watches #media-items for a new row → AJAX check.
 *  - On upload.php (media modal): Backbone wp.media attachment:add event → AJAX check.
 */
(function ($) {
    'use strict';

    var cfg      = window.mutUploadWarn || {};
    var ajaxUrl  = cfg.ajaxUrl  || '';
    var nonce    = cfg.nonce    || '';
    var editBase = cfg.editBase || '';
    var pending  = false; // prevent duplicate requests

    function formatMb(bytes) {
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function showNotice(id, filename, bytes) {
        var sizeMb   = formatMb(bytes);
        var editUrl  = editBase + '?post=' + id + '&action=edit';
        var noticeId = 'mut-oversize-' + id;

        if ($('#' + noticeId).length) { return; }

        var $notice = $(
            '<div id="' + noticeId + '" style="' +
                'display:flex;align-items:center;gap:10px;flex-wrap:wrap;' +
                'margin:10px 0;padding:12px 16px;' +
                'border-left:4px solid #f0b429;background:#fffbf0;border-radius:3px;">' +
                '<span style="font-size:18px;">⚠️</span>' +
                '<span style="flex:1;min-width:200px;">' +
                    '<strong>' + $('<span>').text(filename).html() + '</strong> is <strong>' +
                    $('<span>').text(sizeMb).html() + '</strong> — ' +
                    'large images slow page loads. Consider replacing with a compressed version under 1 MB.' +
                '</span>' +
                '<a href="' + $('<span>').text(editUrl).html() + '" class="button button-small" ' +
                    'style="white-space:nowrap;" target="_blank">🔄 Replace Image</a>' +
                '<button type="button" class="mut-upload-warn-dismiss" data-notice="' + noticeId + '" ' +
                    'style="background:none;border:none;cursor:pointer;font-size:20px;line-height:1;' +
                    'padding:0 0 0 6px;color:#787c82;" title="Dismiss">&times;</button>' +
            '</div>'
        );

        // On media-new.php: inject directly above the file list.
        // On media modal: inject at top of the modal content area.
        var $mediaItems = $('#media-items');
        if ($mediaItems.length) {
            $mediaItems.before($notice);
        } else {
            var $modal = $('.media-frame-content');
            if ($modal.length) {
                $modal.prepend($notice);
            } else {
                $('#wpbody-content').prepend($notice);
            }
        }
    }

    function fetchWarning() {
        if (pending || !ajaxUrl || !nonce) { return; }
        pending = true;
        $.post(ajaxUrl, {
            action: 'mut_get_upload_warning',
            nonce:  nonce
        }, function (res) {
            pending = false;
            if (res && res.success && res.data && res.data.id) {
                showNotice(res.data.id, res.data.name, res.data.bytes);
            }
        }).fail(function () { pending = false; });
    }

    $(document).ready(function () {

        // ── media-new.php ──────────────────────────────────────────────────────
        // Watch #media-items: when a new child is added, an upload just finished.
        var $mediaItems = $('#media-items');
        if ($mediaItems.length && typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    if (m.addedNodes.length) {
                        // Small delay so the server-side add_attachment hook has run.
                        setTimeout(fetchWarning, 300);
                    }
                });
            });
            observer.observe($mediaItems[0], { childList: true });
        }

        // ── Media modal (upload.php, post editor) ──────────────────────────────
        // Backbone: listen for a new attachment being added to the library collection.
        if (typeof wp !== 'undefined' && wp.media && wp.media.model && wp.media.model.Attachments) {
            wp.media.model.Attachments.all.on('add', function () {
                setTimeout(fetchWarning, 300);
            });
        }

        // Dismiss handler.
        $(document).on('click', '.mut-upload-warn-dismiss', function () {
            var noticeId = $(this).data('notice');
            $('#' + noticeId).fadeOut(200, function () { $(this).remove(); });
        });
    });

}(jQuery));
