/*
 * Browse / Updates market tabs (docs/MARKET.md §8.2) — vanilla JS, no jQuery.
 *
 * Drives every `.market-app` on the page (the Plugins and Appearance screens each
 * render one): client-side search/filter/sort over the already-rendered Browse
 * grid, install/update via fetch, "Update all", and the shared detail <dialog>.
 * All endpoints, the package type and translated copy arrive as data-* on
 * .market-app so this file stays static and cacheable — the categories.js
 * convention.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function flash(type, text) {
        if (typeof setJsMessage === 'function' && text) {
            setJsMessage(type, text);
        }
    }

    function parseItem(el) {
        try {
            return JSON.parse(el.getAttribute('data-market-item') || '{}');
        } catch (e) {
            return {};
        }
    }

    function initApp(app) {
        if (app.getAttribute('data-market-init')) {
            return;
        }
        app.setAttribute('data-market-init', '1');

        var i18n = {};
        try {
            i18n = JSON.parse(app.getAttribute('data-i18n') || '{}');
        } catch (e) {
            i18n = {};
        }

        var installUrl = app.getAttribute('data-install-url') || '';
        var updateUrl = app.getAttribute('data-update-url') || '';
        var refreshUrl = app.getAttribute('data-refresh-url') || '';

        // ---- Search / filter / sort (client-side, over the rendered grid only) ----
        var grid = app.querySelector('.market-grid');
        var search = app.querySelector('.market-search');
        var categoryFilter = app.querySelector('.market-filter-category');
        var sortSelect = app.querySelector('.market-sort');
        var noResults = app.querySelector('.market-no-results');

        function applyFilters() {
            if (!grid) {
                return;
            }
            var q = search ? search.value.trim().toLowerCase() : '';
            var cat = categoryFilter ? categoryFilter.value : '';
            var items = Array.prototype.slice.call(grid.querySelectorAll('.market-grid-item'));
            var visible = 0;

            items.forEach(function (item) {
                var card = item.querySelector('.market-card');
                var data = card ? parseItem(card) : {};
                var haystack = [data.name, data.short_description, data.author]
                    .concat(data.tags || [])
                    .join(' ')
                    .toLowerCase();
                var matchesQuery = q === '' || haystack.indexOf(q) !== -1;
                var matchesCat = cat === '' || (data.categories || []).indexOf(cat) !== -1;
                var show = matchesQuery && matchesCat;
                item.hidden = !show;
                if (show) {
                    visible++;
                }
            });

            if (noResults) {
                noResults.hidden = visible !== 0 || items.length === 0;
            }
        }

        function applySort() {
            if (!grid || !sortSelect) {
                return;
            }
            var mode = sortSelect.value;
            var items = Array.prototype.slice.call(grid.querySelectorAll('.market-grid-item'));
            items.sort(function (a, b) {
                var da = parseItem(a.querySelector('.market-card'));
                var db = parseItem(b.querySelector('.market-card'));
                var av;
                var bv;
                if (mode === 'author-asc') {
                    av = (da.author || '').toLowerCase();
                    bv = (db.author || '').toLowerCase();
                } else {
                    av = (da.name || '').toLowerCase();
                    bv = (db.name || '').toLowerCase();
                }
                if (av < bv) {
                    return mode === 'name-desc' ? 1 : -1;
                }
                if (av > bv) {
                    return mode === 'name-desc' ? -1 : 1;
                }
                return 0;
            });
            items.forEach(function (item) {
                grid.appendChild(item);
            });
        }

        if (search) {
            search.addEventListener('input', applyFilters);
        }
        if (categoryFilter) {
            categoryFilter.addEventListener('change', applyFilters);
        }
        if (sortSelect) {
            sortSelect.addEventListener('change', function () {
                applySort();
                applyFilters();
            });
        }

        // ---- Install / update -------------------------------------------------
        function setButtonBusy(btn, busy, label) {
            if (busy) {
                if (!btn.hasAttribute('data-market-original-label')) {
                    btn.setAttribute('data-market-original-label', btn.textContent.trim());
                }
                if (!btn.querySelector('.market-action-label')) {
                    btn.innerHTML = '<span class="market-action-label"></span>';
                }
                btn.classList.add('is-loading');
                btn.disabled = true;
                var labelEl = btn.querySelector('.market-action-label');
                if (labelEl) {
                    labelEl.textContent = label || btn.getAttribute('data-market-original-label') || '';
                }
            } else {
                btn.classList.remove('is-loading');
                btn.disabled = false;
                var original = btn.getAttribute('data-market-original-label');
                var labelEl2 = btn.querySelector('.market-action-label');
                if (labelEl2 && original) {
                    labelEl2.textContent = original;
                }
            }
        }

        function markInstalled(slug, action) {
            var selector = '.market-action-btn[data-market-slug="' + cssEscape(slug) + '"][data-market-action="' + action + '"]';
            document.querySelectorAll(selector).forEach(function (btn) {
                btn.classList.remove('is-loading');
                btn.disabled = true;
                btn.textContent = i18n.installed || 'Installed';
            });
        }

        function cssEscape(value) {
            return window.CSS && CSS.escape ? CSS.escape(value) : value.replace(/["\\]/g, '\\$&');
        }

        function performAction(btn) {
            var action = btn.getAttribute('data-market-action');
            var slug = btn.getAttribute('data-market-slug');
            var version = btn.getAttribute('data-market-version');
            var base = action === 'update' ? updateUrl : installUrl;
            if (!base || !slug || !version) {
                return Promise.resolve();
            }

            setButtonBusy(btn, true, action === 'update' ? i18n.updating : i18n.installing);

            var url = base + '&slug=' + encodeURIComponent(slug) + '&version=' + encodeURIComponent(version);

            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                return r.json();
            }).then(function (res) {
                if (res && res.ok) {
                    markInstalled(slug, action);
                    flash('ok', res.message);
                    if (action === 'update') {
                        removeUpdateItem(slug);
                    }
                } else {
                    setButtonBusy(btn, false);
                    flash('error', (res && res.message) || i18n.ajaxError);
                    throw new Error((res && res.message) || 'market action failed');
                }
            }).catch(function (err) {
                setButtonBusy(btn, false);
                if (!(err && err.message === 'market action failed')) {
                    flash('error', i18n.ajaxError);
                }
                throw err;
            });
        }

        app.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.market-action-btn') : null;
            if (!btn || btn.disabled) {
                return;
            }
            e.preventDefault();
            performAction(btn).catch(function () {
                // already flashed; nothing further to do
            });
        });

        // ---- Update all ---------------------------------------------------
        var updatesList = app.querySelector('.market-updates-list');
        var updateAllBtn = app.querySelector('.market-update-all');
        var updatesCount = document.getElementById('market-updates-count');

        function updateTabCount() {
            if (!updatesCount || !updatesList) {
                return;
            }
            var remaining = updatesList.querySelectorAll('.market-update-item').length;
            updatesCount.textContent = '(' + remaining + ')';
        }

        function removeUpdateItem(slug) {
            if (!updatesList) {
                return;
            }
            var item = updatesList.querySelector('.market-update-item[data-market-slug="' + cssEscape(slug) + '"]');
            if (item) {
                item.parentNode.removeChild(item);
            }
            updateTabCount();
            if (updatesList.querySelectorAll('.market-update-item').length === 0) {
                var empty = document.createElement('p');
                empty.className = 'market-empty';
                empty.textContent = i18n.updateAllDone || '';
                updatesList.parentNode.insertBefore(empty, updatesList);
                updatesList.parentNode.removeChild(updatesList);
                if (updateAllBtn) {
                    updateAllBtn.hidden = true;
                }
            }
        }

        if (updateAllBtn) {
            updateAllBtn.addEventListener('click', function () {
                var buttons = Array.prototype.slice.call(
                    app.querySelectorAll('.market-updates-list .market-action-btn[data-market-action="update"]')
                );
                if (!buttons.length) {
                    return;
                }
                updateAllBtn.disabled = true;
                var originalLabel = updateAllBtn.textContent;
                var total = buttons.length;
                var i = 0;

                function next() {
                    if (i >= buttons.length) {
                        flash('ok', i18n.updateAllDone);
                        if (updateAllBtn.parentNode) {
                            updateAllBtn.textContent = originalLabel;
                            updateAllBtn.hidden = true;
                        }
                        return;
                    }
                    var btn = buttons[i];
                    i++;
                    updateAllBtn.textContent = (i18n.updateAllRunning || 'Updating…') + ' (' + i + '/' + total + ')';
                    performAction(btn).catch(function () {
                        // move on to the next package regardless
                    }).then(next);
                }

                next();
            });
        }

        // ---- Refresh (action=market_refresh) -------------------------------
        app.querySelectorAll('.market-refresh-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!refreshUrl || btn.disabled) {
                    return;
                }
                btn.disabled = true;
                var icon = btn.querySelector('i');
                if (icon) {
                    icon.classList.add('is-spinning');
                }
                fetch(refreshUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) {
                    return r.json();
                }).then(function (res) {
                    if (res && res.ok) {
                        window.location.reload();
                        return;
                    }
                    btn.disabled = false;
                    if (icon) {
                        icon.classList.remove('is-spinning');
                    }
                    flash('error', (res && res.message) || i18n.ajaxError);
                }).catch(function () {
                    btn.disabled = false;
                    if (icon) {
                        icon.classList.remove('is-spinning');
                    }
                    flash('error', i18n.ajaxError);
                });
            });
        });

        // ---- Detail dialog ---------------------------------------------------
        var dialog = document.getElementById('marketDetailDialog');
        if (!dialog) {
            return;
        }
        var dThumb = dialog.querySelector('.market-detail-thumb');
        var dStatus = dialog.querySelector('.market-detail-status');
        var dTitle = dialog.querySelector('.market-detail-title');
        var dAuthor = dialog.querySelector('.market-detail-author');
        var dDesc = dialog.querySelector('.market-detail-desc');
        var dReason = dialog.querySelector('.market-detail-reason');
        var dVersion = dialog.querySelector('.market-detail-version');
        var dTags = dialog.querySelector('.market-detail-tags');
        var dActions = dialog.querySelector('.market-detail-actions');
        var lastTrigger = null;

        function openDetail(container) {
            var data = parseItem(container.matches('[data-market-item]') ? container : container.querySelector('[data-market-item]'));
            var thumbSrc = container.querySelector('.osc-thumb');
            var statusSrc = container.querySelector('.market-card-status, .osc-status');
            var actionSrc = container.querySelector('.market-card-action');

            dThumb.innerHTML = '';
            if (thumbSrc) {
                dThumb.appendChild(thumbSrc.cloneNode(true));
            }
            dStatus.innerHTML = '';
            if (statusSrc) {
                var wrap = statusSrc.classList.contains('market-card-status') ? statusSrc : statusSrc.closest('.market-card-status');
                dStatus.appendChild((wrap || statusSrc).cloneNode(true));
            }
            dTitle.textContent = data.name || '';
            dAuthor.textContent = data.author ? (i18n.byAuthor || 'by %s').replace('%s', data.author) : '';
            dDesc.textContent = data.short_description || '';
            // Prefer the reason actually shown next to the button: a card can be blocked by
            // directory writability or a disabled deployment, neither of which travels in the
            // row JSON (only Compatibility::evaluate()'s reason does).
            var reasonSrc = container.querySelector('.market-card-reason');
            dReason.textContent = reasonSrc ? reasonSrc.textContent : ((data.compat && data.compat.reason) || '');
            dVersion.textContent = data.new_version
                ? (data.installed_version + ' \u2192 ' + data.new_version)
                : (data.version || '');
            dTags.innerHTML = '';
            (data.tags || []).forEach(function (tag) {
                var li = document.createElement('li');
                li.textContent = tag;
                dTags.appendChild(li);
            });
            dActions.innerHTML = '';
            if (actionSrc) {
                dActions.appendChild(actionSrc.cloneNode(true));
            }

            lastTrigger = document.activeElement;
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
        }

        app.addEventListener('click', function (e) {
            var trigger = e.target.closest ? e.target.closest('[data-market-open-detail]') : null;
            if (!trigger) {
                return;
            }
            e.preventDefault();
            var container = trigger.closest('.market-card, .market-update-item');
            if (container) {
                openDetail(container);
            }
        });

        dialog.addEventListener('close', function () {
            if (lastTrigger && typeof lastTrigger.focus === 'function') {
                lastTrigger.focus();
            }
        });
    }

    ready(function () {
        document.querySelectorAll('.market-app').forEach(initApp);
    });
})();
