/* Pornalizer Connect — Admin JS */
(function ($) {
    'use strict';

    var currentBrowsePage = 1;
    var browseParams      = {};

    /* ── Helpers ──────────────────────────────────────────────────────── */

    function toast(msg, type) {
        type = type || 'success';
        var $t = $('#pz-toast');
        $t.removeClass('success error info').addClass(type).text(msg).show();
        clearTimeout(window._pzToastTimer);
        window._pzToastTimer = setTimeout(function () { $t.fadeOut(300); }, 3500);
    }

    function fmt(n) { return parseInt(n || 0, 10).toLocaleString(); }

    function fmtDuration(s) {
        if (!s) return '';
        s = parseInt(s, 10);
        var m = Math.floor(s / 60), sec = s % 60;
        return m + ':' + (sec < 10 ? '0' : '') + sec;
    }

    function ajax(action, data, cb, errCb) {
        $.post(pzAdmin.ajaxUrl, $.extend({ action: action, nonce: pzAdmin.nonce }, data))
            .done(function (res) {
                if (res.success) { cb(res.data); }
                else { (errCb || function (e) { toast(e || 'Error', 'error'); })(res.data || res.data); }
            })
            .fail(function () { toast('Network error.', 'error'); });
    }

    function syncResult(data, $resultEl, $progressEl) {
        if ($progressEl) $progressEl.hide();
        if (!data) { toast('Sync complete.'); return; }
        var msg = '';
        if (data.created !== undefined) msg += '✚ ' + data.created + ' created  ';
        if (data.updated !== undefined) msg += '↻ ' + data.updated + ' updated  ';
        if (data.deleted !== undefined) msg += '✖ ' + data.deleted + ' deleted  ';
        msg = msg.trim() || 'Done';
        if ($resultEl) {
            $resultEl.removeClass('error').addClass('success').text(msg).show();
        }
        toast(msg);
    }

    function copyText(text, $btn) {
        navigator.clipboard.writeText(text).then(function () {
            var orig = $btn.text();
            $btn.text('Copied!').addClass('copied');
            setTimeout(function () { $btn.text(orig).removeClass('copied'); }, 1800);
        });
    }

    /* ── Tabs ─────────────────────────────────────────────────────────── */

    $('.pz-tab').on('click', function () {
        var tab = $(this).data('tab');
        $('.pz-tab').removeClass('active');
        $('.pz-panel').removeClass('active');
        $(this).addClass('active');
        $('#pz-panel-' + tab).addClass('active');

        if (tab === 'dashboard') loadDashboard();
        if (tab === 'sync')      loadSyncHistory('#pz-sync-history-full');
        if (tab === 'scheduler') loadScheduled();
    });

    /* ── Dashboard ────────────────────────────────────────────────────── */

    function loadDashboard() {
        ajax('pz_dashboard_stats', {}, function (data) {
            $('#kpi-videos').text(fmt(data.videos));
            $('#kpi-categories').text(fmt(data.categories));
            $('#kpi-performers').text(fmt(data.performers));
            $('#kpi-api').text(data.api_ok ? fmt(data.api_count) : 'Error');

            var $badge = $('#dash-api-badge');
            if (data.api_ok) {
                $badge.removeClass('pz-badge-loading pz-badge-error').addClass('pz-badge-ok').text('API Connected');
            } else {
                $badge.removeClass('pz-badge-loading pz-badge-ok').addClass('pz-badge-error').text('API Error');
            }

            $('#dash-last-sync').text(data.last_sync);
            $('#dash-next-sync').text(data.next_sync);
            $('#dash-auto-sync').text(data.auto_sync ? 'Enabled' : 'Disabled');

            if (data.last_log) {
                var l = data.last_log;
                var s = '✚ ' + (l.created||0) + '  ↻ ' + (l.updated||0) + '  ✖ ' + (l.deleted||0);
                $('#dash-last-result').text(s);
            } else {
                $('#dash-last-result').text('No record yet');
            }
        });

        loadSyncHistory('#pz-sync-history');
    }

    function loadSyncHistory(target) {
        ajax('pz_get_sync_history', {}, function (hist) {
            var $ul = $(target).empty();
            if (!hist || !hist.length) {
                $ul.append('<div class="pz-loading-row">No sync history yet.</div>');
                return;
            }
            hist.forEach(function (h) {
                var type = h.type || 'sync';
                var row  = '<div class="pz-hist-row">'
                    + '<span class="pz-hist-ts">'   + (h.ts_fmt || '—') + '</span>'
                    + '<span class="pz-hist-type">'  + type + '</span>'
                    + '<span class="pz-hist-nums">'
                    +   '<span class="pz-n-c">+' + (h.created||0) + '</span>'
                    +   '<span class="pz-n-u">~' + (h.updated||0) + '</span>'
                    +   '<span class="pz-n-d">-' + (h.deleted||0) + '</span>'
                    + '</span>'
                    + '</div>';
                $ul.append(row);
            });
        });
    }

    // Dashboard sync buttons
    $('#dash-btn-incremental, #pz-hdr-incremental, sync-btn-incremental').on('click', function () {
        runSync('pz_sync_incremental', null, null);
    });
    $('#dash-btn-full').on('click', function () {
        if (!confirm('Full sync imports up to ' + pzAdmin.fullSyncLimit + ' videos. Continue?')) return;
        runSync('pz_sync_full', null, null);
    });
    $('#dash-refresh-hist').on('click', function () { loadSyncHistory('#pz-sync-history'); });

    /* ── Sync tab ─────────────────────────────────────────────────────── */

    function runSync(action, $result, $progress) {
        $result  = $result  || $('#sync-result');
        $progress = $progress || $('#sync-progress-wrap');

        $progress.show();
        $('#sync-progress-msg').text('Running…');
        $result.hide();

        ajax(action, {}, function (data) {
            syncResult(data, $result, $progress);
            loadDashboard();
            loadSyncHistory('#pz-sync-history-full');
        }, function (err) {
            $progress.hide();
            $result.removeClass('success').addClass('error').text('✗ ' + err).show();
            toast(err, 'error');
        });
    }

    $('#sync-btn-incremental').on('click', function () { runSync('pz_sync_incremental'); });
    $('#sync-btn-full').on('click', function () {
        if (!confirm('Full sync can import many posts. Continue?')) return;
        runSync('pz_sync_full');
    });
    $('#sync-btn-clear-cache').on('click', function () {
        ajax('pz_clear_cache', {}, function (data) { toast(data.message || 'Cache cleared.'); });
    });
    $('#sync-refresh-hist').on('click', function () { loadSyncHistory('#pz-sync-history-full'); });

    // Header sync / test
    $('#pz-hdr-incremental').on('click', function () { runSync('pz_sync_incremental'); });
    $('#pz-hdr-test-api, #settings-test-api').on('click', function () {
        ajax('pz_test_api', {}, function (d) { toast(d.message, 'success'); },
            function (e) { toast(e, 'error'); });
    });

    /* ── Browse Videos ────────────────────────────────────────────────── */

    function doBrowse(page) {
        page = page || 1;
        currentBrowsePage = page;

        var $grid = $('#pz-browse-grid').html('<div class="pz-empty-state"><span class="pz-spinner"></span><p>Loading…</p></div>');
        $('#pz-browse-pagination').hide();
        $('#pz-browse-info').hide();

        browseParams = {
            page:        page,
            search:      $('#pz-browse-search').val(),
            sort:        $('#pz-browse-sort').val(),
            orientation: $('#pz-browse-orientation').val(),
            category:    $('#pz-browse-category').val(),
        };

        ajax('pz_preview_videos', browseParams, function (data) {
            $grid.empty();
            if (!data.cards || !data.cards.length) {
                $grid.html('<div class="pz-empty-state"><span class="dashicons dashicons-search"></span><p>No videos found.</p></div>');
                return;
            }

            data.cards.forEach(function (v) {
                var thumb = v.thumb
                    ? '<img class="pz-browse-thumb" src="' + v.thumb + '" alt="" loading="lazy">'
                    : '<div class="pz-thumb-placeholder"><span class="dashicons dashicons-video-alt3" style="font-size:28px;width:28px;height:28px;"></span></div>';
                var card = '<div class="pz-browse-card" data-id="' + v.id + '" data-sc="' + v.shortcode + '">'
                    + thumb
                    + '<div class="pz-browse-info-row">'
                    +   '<div class="pz-browse-card-title">' + $('<span>').text(v.title).html() + '</div>'
                    +   '<div class="pz-browse-meta">'
                    +     '<span>' + (fmtDuration(v.duration) || '—') + '</span>'
                    +     '<span>' + fmt(v.views) + ' views</span>'
                    +   '</div>'
                    + '</div>'
                    + '<div class="pz-browse-actions">'
                    +   '<button class="btn-copy-sc" data-sc="' + v.shortcode + '" title="Copy shortcode [pornalizer_video id=&quot;' + v.id + '&quot;]">Copy SC</button>'
                    +   '<button class="btn-sched" data-id="' + v.id + '" data-title="' + $('<span>').text(v.title).html() + '" title="Schedule this video">Schedule</button>'
                    + '</div>'
                    + '</div>';
                $grid.append(card);
            });

            var info = 'Showing ' + ((page-1)*24+1) + '–' + Math.min(page*24, data.count) + ' of ' + fmt(data.count) + ' videos';
            $('#pz-browse-info').text(info).show();

            var $pag = $('#pz-browse-pagination').show();
            $('#pz-browse-prev').prop('disabled', page <= 1);
            $('#pz-browse-next').prop('disabled', page >= data.pages);
            $('#pz-browse-page-info').text('Page ' + page + ' of ' + data.pages);
        }, function (e) {
            $grid.html('<div class="pz-empty-state"><p>Error: ' + e + '</p></div>');
        });
    }

    $('#pz-browse-go').on('click', function () { doBrowse(1); });
    $('#pz-browse-search').on('keydown', function (e) { if (e.which === 13) doBrowse(1); });
    $('#pz-browse-prev').on('click', function () { doBrowse(currentBrowsePage - 1); });
    $('#pz-browse-next').on('click', function () { doBrowse(currentBrowsePage + 1); });

    $(document).on('click', '.btn-copy-sc', function (e) {
        e.stopPropagation();
        copyText($(this).data('sc'), $(this));
    });
    $(document).on('click', '.btn-sched', function (e) {
        e.stopPropagation();
        var id    = $(this).data('id');
        var title = $(this).data('title');
        openScheduleModal(id, title);
    });

    /* ── Schedule Modal (single video) ───────────────────────────────── */

    function openScheduleModal(videoId, title) {
        var now  = new Date();
        now.setDate(now.getDate() + 1);
        var dateStr = now.toISOString().slice(0, 16);

        var modal = '<div id="pz-modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:99990;display:flex;align-items:center;justify-content:center;">'
            + '<div style="background:#23272e;border:1px solid #2d3239;border-radius:10px;padding:26px 28px;max-width:420px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.5);">'
            + '<h3 style="margin:0 0 6px;color:#e2e8f0;font-size:1rem;">Schedule Video</h3>'
            + '<p style="font-size:.8rem;color:#8c8f94;margin:0 0 16px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + title + '">' + title + '</p>'
            + '<label style="font-size:.82rem;color:#8c8f94;display:block;margin-bottom:4px;">Publish date &amp; time</label>'
            + '<input type="datetime-local" id="pz-modal-date" value="' + dateStr + '" style="background:#1a1d21;border:1px solid #2d3239;color:#e2e8f0;border-radius:6px;padding:7px 11px;width:100%;box-sizing:border-box;margin-bottom:14px;">'
            + '<div style="display:flex;gap:8px;justify-content:flex-end;">'
            + '<button id="pz-modal-cancel" style="background:#23272e;border:1px solid #2d3239;color:#e2e8f0;padding:6px 14px;border-radius:6px;cursor:pointer;">Cancel</button>'
            + '<button id="pz-modal-ok" style="background:#4f8ef7;border:none;color:#fff;padding:6px 14px;border-radius:6px;cursor:pointer;">Schedule</button>'
            + '</div>'
            + '</div></div>';

        $('body').append(modal);

        $('#pz-modal-cancel, #pz-modal-overlay').on('click', function (e) {
            if (e.target.id === 'pz-modal-overlay' || e.target.id === 'pz-modal-cancel') {
                $('#pz-modal-overlay').remove();
            }
        });

        $('#pz-modal-ok').on('click', function () {
            var dt = $('#pz-modal-date').val();
            if (!dt) { toast('Pick a date.', 'error'); return; }
            $('#pz-modal-overlay').remove();
            ajax('pz_schedule_video', { video_id: videoId, publish_at: dt }, function (d) {
                toast(d.message || 'Scheduled!', 'success');
            }, function (e) { toast(e, 'error'); });
        });
    }

    /* ── Shortcode Builder ────────────────────────────────────────────── */

    var SC_DESC = {
        grid:   'Renders a responsive video grid fetched live from the Pornalizer API with lightbox playback.',
        single: 'Embeds a single video player for the specified video ID.',
        search: 'Renders an AJAX search bar; results update as the user types.',
    };

    function buildShortcode() {
        var type = $('#sc-type').val();
        var sc   = '';

        if (type === 'single') {
            sc = '[pornalizer_video';
            var id = $('#sc-id').val();
            if (id) sc += ' id="' + id + '"';
            if ($('#sc-autoplay').val()) sc += ' autoplay="' + $('#sc-autoplay').val() + '"';
            sc += ']';
        } else if (type === 'search') {
            sc = '[pornalizer_search';
            var cnt = parseInt($('#sc-count').val(), 10);
            if (cnt && cnt !== 12) sc += ' count="' + cnt + '"';
            var cols = $('#sc-columns').val();
            if (cols && cols !== '3') sc += ' columns="' + cols + '"';
            sc += ']';
        } else {
            sc = '[pornalizer_videos';
            var count = parseInt($('#sc-count').val(), 10);
            if (count && count !== 12) sc += ' count="' + count + '"';
            var columns = $('#sc-columns').val();
            if (columns && columns !== '3') sc += ' columns="' + columns + '"';
            var sort = $('#sc-sort').val();
            if (sort && sort !== 'published') sc += ' sort="' + sort + '"';
            var orient = $('#sc-orientation').val();
            if (orient) sc += ' orientation="' + orient + '"';
            var cat = $('#sc-category').val().trim();
            if (cat) sc += ' category="' + cat + '"';
            var perf = $('#sc-performer').val().trim();
            if (perf) sc += ' performer="' + perf + '"';
            var link = $('#sc-link').val();
            if (link && link !== 'lightbox') sc += ' link_to="' + link + '"';
            var inf = $('#sc-infinite').val();
            if (inf) sc += ' infinite="' + inf + '"';
            sc += ']';
        }

        $('#pz-sc-output').text(sc);
        $('#pz-sc-description').text(SC_DESC[type] || '');
    }

    function toggleBuilderFields() {
        var type = $('#sc-type').val();
        $('.sc-grid-field').toggle(type === 'grid' || type === 'search');
        $('#sc-field-id').toggle(type === 'single');
        $('#sc-field-autoplay').toggle(type === 'single');
    }

    $('#sc-type').on('change', function () { toggleBuilderFields(); buildShortcode(); });
    $('#pz-panel-builder input, #pz-panel-builder select').on('input change', buildShortcode);

    $('.pz-copy-btn-sc').on('click', function () {
        copyText($('#pz-sc-output').text(), $(this));
    });

    // Quick copy buttons on dashboard
    $(document).on('click', '.pz-copy-btn', function () {
        var target = $(this).data('target');
        var text   = target ? $('#' + target).text() : $(this).closest('.pz-quickcode-item').find('code').text();
        copyText(text, $(this));
    });

    /* ── Scheduler tab ────────────────────────────────────────────────── */

    function loadScheduled() {
        ajax('pz_get_scheduled', {}, function (data) {
            var $list = $('#pz-sched-list').empty();
            var count = data ? data.length : 0;

            var $badge = $('#draft-count-badge');
            if (count > 0) {
                $badge.removeClass('pz-badge-loading pz-badge-ok').addClass('pz-badge-ok')
                      .text(count + ' draft' + (count === 1 ? '' : 's'));
            } else {
                $badge.removeClass('pz-badge-ok').addClass('pz-badge-off').text('0 drafts');
            }

            if (!data || !data.length) {
                $list.append('<div class="pz-loading-row">No drafts in queue.</div>');
                return;
            }
            data.forEach(function (p) {
                $list.append(
                    '<div class="pz-sched-item">'
                    + '<span class="pz-sched-date">' + p.date + '</span>'
                    + '<span class="pz-sched-title" title="' + p.title + '">' + p.title + '</span>'
                    + '<div class="pz-sched-actions">'
                    +   '<button class="pz-btn pz-btn-ghost pz-btn-xs btn-pub-now" data-id="' + p.id + '">Publish now</button>'
                    + '</div>'
                    + '</div>'
                );
            });
        });
    }

    $(document).on('click', '.btn-pub-now', function () {
        var id = $(this).data('id');
        ajax('pz_publish_now', { post_id: id }, function (d) {
            toast(d.message || 'Published!', 'success');
            loadScheduled();
        }, function (e) { toast(e, 'error'); });
    });

    $('#drip-save').on('click', function () {
        ajax('pz_save_drip', {
            drip_mode:    $('#pz-drip-mode').is(':checked') ? '1' : '0',
            drip_per_day: $('#pz-drip-per-day').val(),
            drip_hour:    $('#pz-drip-hour').val(),
            import_as:    $('#pz-import-as').val(),
        }, function (d) { toast(d.message || 'Saved.', 'success'); });
    });

    $('#drip-trigger').on('click', function () {
        ajax('pz_drip_now', {}, function (d) {
            toast(d.message || 'Drip triggered.', 'success');
            loadScheduled();
        });
    });

    /* ── Manual schedule-video form ──────────────────────────────────── */

    (function () {
        var now = new Date();
        now.setDate(now.getDate() + 1);
        now.setHours(9, 0, 0, 0);
        var def = now.toISOString().slice(0, 16);
        $('#sched-video-date').val(def);
    })();

    $('#sched-video-btn').on('click', function () {
        var id = $('#sched-video-id').val();
        var dt = $('#sched-video-date').val();
        if (!id) { toast('Enter a video ID.', 'error'); return; }
        if (!dt) { toast('Pick a publish date.', 'error'); return; }
        var $r = $('#sched-video-result').removeClass('success error').hide();
        ajax('pz_schedule_video', { video_id: id, publish_at: dt }, function (d) {
            $r.addClass('success').text('✓ ' + d.message).show();
            toast(d.message, 'success');
            loadScheduled();
        }, function (e) {
            $r.addClass('error').text('✗ ' + e).show();
        });
    });

    /* ── Settings page api test ───────────────────────────────────────── */
    // (handled above via #settings-test-api)

    /* ── Auto-reschedule notice ───────────────────────────────────────── */
    $('input[name="pz_auto_sync"], select[name="pz_sync_interval"]').on('change', function () {
        toast('Save settings to apply cron changes.', 'info');
    });

    /* ── Init ─────────────────────────────────────────────────────────── */
    loadDashboard();
    toggleBuilderFields();
    buildShortcode();

})(jQuery);
