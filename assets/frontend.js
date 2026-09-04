(function ($) {
    'use strict';

    // ── Lazy thumbnail loading via IntersectionObserver ────────────────────

    if ('IntersectionObserver' in window) {
        var thumbObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var el  = entry.target;
                var src = el.dataset.lazybg;
                if (src) {
                    el.style.backgroundImage = "url('" + src + "')";
                    delete el.dataset.lazybg;
                }
                thumbObserver.unobserve(el);
            });
        }, { rootMargin: '200px' });

        $(document).on('pz:cards-rendered', function () {
            document.querySelectorAll('.pz-card-thumb[data-lazybg]').forEach(function (el) {
                thumbObserver.observe(el);
            });
        });
    }

    // ── View tracking via Pornalizer postback ──────────────────────────────

    function trackView(videoId) {
        if (!videoId) return;
        navigator.sendBeacon
            ? navigator.sendBeacon(pzConfig.ajaxUrl, new URLSearchParams({
                action: 'pz_track_view', nonce: pzConfig.nonce, video_id: videoId
              }))
            : $.post(pzConfig.ajaxUrl, { action: 'pz_track_view', nonce: pzConfig.nonce, video_id: videoId });
    }

    // ── Lightbox ───────────────────────────────────────────────────────────

    function openLightbox(videoId, title) {
        var $lb = $('.pz-lightbox').first();
        if (!$lb.length) return;

        $lb.find('.pz-lightbox-title').text(title || '');
        $lb.find('.pz-lightbox-player').html('<div class="pz-loading">Loading player…</div>');
        $lb.fadeIn(160);
        $('body').css('overflow', 'hidden');

        $.post(pzConfig.ajaxUrl, {
            action:   'pz_get_embed',
            nonce:    pzConfig.nonce,
            video_id: videoId
        }, function (res) {
            if (res.success) {
                $lb.find('.pz-lightbox-player').html(res.data.html);
                $lb.find('.pz-lightbox-title').text(res.data.title || '');
                trackView(videoId);
            } else {
                $lb.find('.pz-lightbox-player').html('<p class="pz-error">Could not load player. Try again.</p>');
            }
        }).fail(function () {
            $lb.find('.pz-lightbox-player').html('<p class="pz-error">Network error loading player.</p>');
        });
    }

    function closeLightbox() {
        var $lb = $('.pz-lightbox');
        $lb.find('.pz-lightbox-player iframe').attr('src', ''); // stop playback
        $lb.fadeOut(140);
        $('body').css('overflow', '');
    }

    $(document)
        .on('click', '.pz-card-thumb-btn', function () {
            openLightbox($(this).data('video-id'), $(this).data('title'));
        })
        .on('click', '.pz-lightbox-overlay, .pz-lightbox-close', closeLightbox)
        .on('keydown', function (e) { if (e.key === 'Escape') closeLightbox(); });

    // ── Single video (inline play) ─────────────────────────────────────────

    $(document).on('click', '.pz-play-btn, .pz-play-btn-single', function () {
        var $wrap = $(this).closest('.pz-single-player, .pz-single-embed');
        var videoId = $wrap.data('video-id');
        if (!videoId) return;

        var $poster  = $wrap.find('.pz-poster, .pz-poster-single');
        var $player  = $wrap.find('.pz-embed-wrap, .pz-inline-player');

        $poster.hide();
        $player.show().html('<div class="pz-loading">Loading player…</div>');

        $.post(pzConfig.ajaxUrl, {
            action:   'pz_get_embed',
            nonce:    pzConfig.nonce,
            video_id: videoId
        }, function (res) {
            if (res.success) {
                $player.html(res.data.html);
            } else {
                $player.html('<p class="pz-error">Could not load player.</p>');
                $poster.show();
                $player.hide();
            }
        }).fail(function () {
            $player.html('<p class="pz-error">Network error.</p>');
        });
    });

    // ── AJAX pagination ────────────────────────────────────────────────────

    // ── AJAX pagination (prev/next) ────────────────────────────────────────

    $(document).on('click', '.pz-page-btn', function () {
        var $btn    = $(this);
        var $wrap   = $btn.closest('.pz-grid-wrap');
        var $grid   = $wrap.find('.pz-grid');
        var $pager  = $wrap.find('.pz-pagination');
        var page    = parseInt($btn.data('page'), 10);

        var rawArgs = $wrap.data('args');
        var args;
        try { args = JSON.parse(rawArgs); } catch (e) { return; }

        args.page = page;
        $wrap.data('args', JSON.stringify(args));

        $grid.css('opacity', '0.4');
        $btn.prop('disabled', true).text('Loading…');

        $.post(pzConfig.ajaxUrl, {
            action: 'pz_browse',
            nonce:  pzConfig.nonce,
            args:   JSON.stringify(args)
        }, function (res) {
            $grid.css('opacity', '');
            if (!res.success) return;

            $grid.html(res.data.html);
            $(document).trigger('pz:cards-rendered');
            window.scrollTo({ top: $wrap.offset().top - 40, behavior: 'smooth' });

            var total   = res.data.total_pages;
            var current = res.data.current_page;
            var pagerHtml = '';
            if (current > 1) {
                pagerHtml += '<button class="pz-page-btn pz-prev" data-page="' + (current - 1) + '">&laquo; Previous</button>';
            }
            pagerHtml += '<span class="pz-page-info">Page ' + current + ' of ' + total + '</span>';
            if (current < total) {
                pagerHtml += '<button class="pz-page-btn pz-next" data-page="' + (current + 1) + '">Next &raquo;</button>';
            }
            $pager.html(pagerHtml).data('current', current).data('total-pages', total);
        }).fail(function () {
            $grid.css('opacity', '');
            $btn.prop('disabled', false).text($btn.hasClass('pz-prev') ? '« Previous' : 'Next »');
        });
    });

    // ── Infinite scroll (for grids that opt-in via data-infinite="1") ─────

    var _inflight = false;

    function maybeLoadMore() {
        if (_inflight) return;
        document.querySelectorAll('.pz-grid-wrap[data-infinite="1"]').forEach(function (wrap) {
            var $wrap  = $(wrap);
            var $pager = $wrap.find('.pz-pagination');
            var total  = parseInt($pager.data('total-pages') || 0, 10);
            var curr   = parseInt($pager.data('current') || 1, 10);
            if (curr >= total) return;

            var rect   = $pager[0] ? $pager[0].getBoundingClientRect() : null;
            if (!rect || rect.top > window.innerHeight + 300) return;

            _inflight = true;
            $pager.find('.pz-next').prop('disabled', true).text('Loading…');

            var args;
            try { args = JSON.parse($wrap.data('args')); } catch (e) { _inflight = false; return; }
            args.page = curr + 1;
            $wrap.data('args', JSON.stringify(args));

            $.post(pzConfig.ajaxUrl, {
                action: 'pz_browse',
                nonce:  pzConfig.nonce,
                args:   JSON.stringify(args)
            }, function (res) {
                _inflight = false;
                if (!res.success) return;
                $wrap.find('.pz-grid').append(res.data.html);
                $(document).trigger('pz:cards-rendered');

                var newCurr  = res.data.current_page;
                var newTotal = res.data.total_pages;
                $pager.data('current', newCurr).data('total-pages', newTotal);
                if (newCurr >= newTotal) {
                    $pager.html('<span class="pz-page-info">All videos loaded.</span>');
                } else {
                    $pager.find('.pz-next').prop('disabled', false).text('Next »');
                    $pager.data('current', newCurr);
                }
            }).fail(function () { _inflight = false; });
        });
    }

    $(window).on('scroll.pz resize.pz', function () { maybeLoadMore(); });
    setTimeout(maybeLoadMore, 800);

    // ── AJAX search ────────────────────────────────────────────────────────

    var searchTimer = null;

    $(document).on('input', '.pz-search-input', function () {
        var $input  = $(this);
        var $widget = $input.closest('.pz-search-widget');
        var $grid   = $widget.find('.pz-search-results');
        var $status = $widget.find('.pz-search-status');
        var query   = $input.val().trim();

        clearTimeout(searchTimer);

        if (query.length < 2) {
            $grid.empty();
            $status.text('');
            return;
        }

        $status.text('Searching…');

        searchTimer = setTimeout(function () {
            var cols    = parseInt($widget.data('columns') || 3, 10);
            var count   = parseInt($widget.data('count') || 12, 10);
            var orient  = $widget.data('orientation') || 'straight';

            $.post(pzConfig.ajaxUrl, {
                action: 'pz_browse',
                nonce:  pzConfig.nonce,
                args:   JSON.stringify({
                    search:      query,
                    page_size:   count,
                    orientation: orient,
                    sort:        'published',
                    columns:     cols,
                    link_to:     'lightbox',
                    show_title:  true,
                    show_dur:    true,
                    show_views:  false
                })
            }, function (res) {
                if (!res.success) {
                    $status.text('Error loading results.');
                    return;
                }
                $grid.html(res.data.html);
                var total = parseInt(res.data.total_pages || 0, 10) * count;
                $status.text(total ? total + '+ results' : 'No results found.');
            }).fail(function () {
                $status.text('Network error. Please try again.');
            });
        }, 350);
    });

})(jQuery);
