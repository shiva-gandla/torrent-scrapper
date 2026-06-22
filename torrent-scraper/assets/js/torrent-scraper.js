/**
 * Torrent Scraper — Frontend JavaScript
 * Version: 1.0.0
 *
 * Handles:
 *   - Copy-to-clipboard for magnet links
 *   - Confirmation dialogs for magnet links
 *   - Lazy load of torrent stats (future AJAX endpoint)
 */

(function () {
    'use strict';

    // ─── Magnet Link: Copy to Clipboard ──────────────────────────────

    document.addEventListener('click', function (e) {
        /** @type {HTMLElement|null} */
        const btn = e.target.closest('.tp-magnet-btn');
        if (!btn) return;

        const magnetUrl = btn.getAttribute('href');
        if (!magnetUrl || !magnetUrl.startsWith('magnet:')) return;

        // If the user has a torrent client configured, the browser will handle it.
        // But we also provide a copy-to-clipboard button as a fallback.
        if (btn.classList.contains('tp-magnet-copy')) {
            e.preventDefault();
            copyToClipboard(magnetUrl, btn);
        }
    });

    /**
     * Copy text to clipboard and show visual feedback on the button.
     *
     * @param {string}      text
     * @param {HTMLElement}  btn
     */
    function copyToClipboard(text, btn) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showCopied(btn);
            }).catch(function () {
                fallbackCopy(text, btn);
            });
        } else {
            fallbackCopy(text, btn);
        }
    }

    /**
     * Fallback: create a temporary textarea to copy text.
     *
     * @param {string}      text
     * @param {HTMLElement}  btn
     */
    function fallbackCopy(text, btn) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.cssText = 'position:fixed;opacity:0;';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            document.execCommand('copy');
            showCopied(btn);
        } catch (err) {
            // Silent failure — the magnet link is still clickable.
        }

        document.body.removeChild(textarea);
    }

    /**
     * Show a brief "Copied!" tooltip on the button.
     *
     * @param {HTMLElement} btn
     */
    function showCopied(btn) {
        var original = btn.textContent;
        btn.textContent = '✓ Copied!';
        btn.style.pointerEvents = 'none';

        setTimeout(function () {
            btn.textContent = original;
            btn.style.pointerEvents = '';
        }, 1500);
    }

    // ─── AJAX: Lazy-load Live Stats ──────────────────────────────────

    /**
     * On DOMContentLoaded, find all elements with [data-tp-torrent-id]
     * and fetch fresh stats via AJAX. This avoids blocking page render
     * with potentially slow tracker data.
     *
     * Expected HTML:
     *   <span class="tp-badge tp-badge-seeders" data-tp-torrent-id="123" data-tp-stat="seeders">—</span>
     *
     * The PHP shortcodes/blocks can output placeholders; JS fills in real values.
     */
    document.addEventListener('DOMContentLoaded', function () {
        // Bail if the localized data isn't present (tp_ajax not enqueued).
        if (typeof window.tp_ajax === 'undefined') return;

        var elements = document.querySelectorAll('[data-tp-torrent-id][data-tp-stat]');
        if (!elements.length) return;

        // Group by torrent ID to minimize requests.
        var torrentIds = {};
        elements.forEach(function (el) {
            var id = el.getAttribute('data-tp-torrent-id');
            if (id && !torrentIds[id]) {
                torrentIds[id] = true;
            }
        });

        Object.keys(torrentIds).forEach(function (id) {
            fetchStats(parseInt(id, 10), elements);
        });
    });

    /**
     * Fetch stats for a single torrent ID via wp_ajax.
     *
     * @param {number}   torrentId
     * @param {NodeList} allElements
     */
    function fetchStats(torrentId, allElements) {
        var formData = new FormData();
        formData.append('action', 'tp_get_stats');
        formData.append('nonce', window.tp_ajax.nonce);
        formData.append('torrent_id', torrentId);

        fetch(window.tp_ajax.url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        })
        .then(function (response) { return response.json(); })
        .then(function (json) {
            if (!json.success || !json.data) return;

            allElements.forEach(function (el) {
                if (parseInt(el.getAttribute('data-tp-torrent-id'), 10) !== torrentId) return;

                var stat = el.getAttribute('data-tp-stat');
                if (stat && json.data[stat] !== undefined) {
                    el.textContent = el.textContent.replace(/[—\d,]+/, json.data[stat].toLocaleString());
                }
            });
        })
        .catch(function () {
            // Silent failure — stale cached values remain visible.
        });
    }

    // ─── AJAX: Frontend Reload Button ────────────────────────────────

    if (typeof window.tpReloadTorrent === 'undefined') {
        window.tpReloadTorrent = function (btn) {
            var torrentId = btn.getAttribute('data-torrent-id');
            var nonce = btn.getAttribute('data-nonce');
            var ajaxUrl = btn.getAttribute('data-ajax-url');
            if (!torrentId || !nonce || !ajaxUrl || btn.disabled) return;

            btn.disabled = true;
            var origContent = btn.innerHTML;
            btn.textContent = '⏳';

            var fd = new FormData();
            fd.append('action', 'tp_reload_torrent');
            fd.append('nonce', nonce);
            fd.append('torrent_id', torrentId);

            fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        var container = btn.closest('.tp-stats')
                                     || btn.closest('.tp-wpforo-compact')
                                     || btn.closest('.tp-card')
                                     || btn.parentElement;
                        if (container) {
                            var sEl = container.querySelector('.tp-badge-seeders');
                            var lEl = container.querySelector('.tp-badge-leechers');
                            var cEl = container.querySelector('.tp-badge-completed');
                            if (sEl) sEl.textContent = sEl.textContent.replace(/[—\d,]+/g, data.data.seeders.toLocaleString());
                            if (lEl) lEl.textContent = lEl.textContent.replace(/[—\d,]+/g, data.data.leechers.toLocaleString());
                            if (cEl) cEl.textContent = cEl.textContent.replace(/[—\d,]+/g, data.data.completed.toLocaleString());
                        }
                        btn.innerHTML = '✅' + (origContent.indexOf('Reload') !== -1 ? ' <span style="font-size:0.82em;">Reload</span>' : '');
                    } else {
                        btn.innerHTML = '❌' + (origContent.indexOf('Reload') !== -1 ? ' <span style="font-size:0.82em;">Error</span>' : '');
                    }
                    setTimeout(function () {
                        btn.innerHTML = origContent;
                        btn.disabled = false;
                    }, 2000);
                })
                .catch(function () {
                    btn.innerHTML = '❌' + (origContent.indexOf('Reload') !== -1 ? ' <span style="font-size:0.82em;">Error</span>' : '');
                    setTimeout(function () {
                        btn.innerHTML = origContent;
                        btn.disabled = false;
                    }, 2000);
                });
        };
    }

})();
