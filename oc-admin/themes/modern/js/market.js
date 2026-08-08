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
        // action=market_detail shares the refresh URL's type + CSRF token (the same token
        // already does triple duty for install/update/refresh — osc_csrf_check() validates
        // the session, not the action name).
        var detailUrl = refreshUrl ? refreshUrl.replace('action=market_refresh', 'action=market_detail') : '';

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

        // ISO-8601 -> epoch ms, or null when absent/unparseable — a package's `updated_at`
        // is catalog-supplied and untrusted, so "sorts last" has to be the fallback for
        // anything Date.parse can't make sense of, not a 1970 date or a thrown error.
        function parseUpdatedAt(value) {
            if (!value || typeof value !== 'string') {
                return null;
            }
            var t = Date.parse(value);
            return isNaN(t) ? null : t;
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

                if (mode === 'updated-desc') {
                    var ta = parseUpdatedAt(da.updated_at);
                    var tb = parseUpdatedAt(db.updated_at);
                    if (ta !== null || tb !== null) {
                        // A dated row always outranks an undated one; two dated rows compare
                        // newest-first. Two undated rows fall through to the name tie-break
                        // below — which is also what happens for every pair when nothing in
                        // the grid carries `updated_at` at all, degrading the whole sort to
                        // alphabetical.
                        if (ta === null) {
                            return 1;
                        }
                        if (tb === null) {
                            return -1;
                        }
                        if (ta !== tb) {
                            return tb - ta;
                        }
                    }
                }

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
        // Apply the default sort (Recently updated) to the server-rendered grid on load,
        // not just to the dropdown's own selected option.
        applySort();
        applyFilters();

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
        var dLoading = dialog.querySelector('.market-detail-body-loading');
        var dError = dialog.querySelector('.market-detail-body-error');
        var dReadme = dialog.querySelector('.market-detail-readme');
        var dScreens = dialog.querySelector('.market-detail-screens');
        var dScreensStrip = dialog.querySelector('.market-detail-screens-strip');
        var dScreensView = dialog.querySelector('.market-detail-screens-view');
        var dScreensCaption = dialog.querySelector('.market-detail-screens-caption');
        var dScreensPrev = dialog.querySelector('.market-detail-screens-prev');
        var dScreensNext = dialog.querySelector('.market-detail-screens-next');
        var dVersionsWrap = dialog.querySelector('.market-detail-versions');
        var dVersionsBody = dialog.querySelector('.market-detail-versions-table tbody');
        var dLinks = dialog.querySelector('.market-detail-links');
        var lastTrigger = null;
        // Per-slug response cache (docs/MARKET.md \u00a78.2: "reopening is instant") \u2014 cleared on
        // page load, which is fine: the catalog itself is a 24h-cached resource, so a stale
        // in-memory copy for the lifetime of one page view is not a freshness concern.
        var detailCache = Object.create(null);

        function compatStatusClass(status) {
            switch (status) {
                case 'ok':
                    return 'active';
                case 'untested':
                    return 'untested';
                case 'incompatible':
                    return 'blocked';
                default:
                    return 'undeclared';
            }
        }

        // Mirrors osc_market_render_compat_badge()'s markup exactly, so a badge built here
        // reads identically (and reuses the same CSS) whether it came from the server-rendered
        // card or from this fetched detail payload.
        function compatBadgeNode(compat) {
            var wrap = document.createElement('div');
            wrap.className = 'market-card-status status-' + compatStatusClass(compat && compat.status);
            var span = document.createElement('span');
            span.className = 'osc-status';
            span.textContent = (compat && compat.badge) || '';
            wrap.appendChild(span);
            return wrap;
        }

        function formatBytes(bytes) {
            bytes = Number(bytes) || 0;
            if (!bytes) {
                return '\u2014';
            }
            var units = ['B', 'KB', 'MB', 'GB'];
            var i = 0;
            var value = bytes;
            while (value >= 1024 && i < units.length - 1) {
                value /= 1024;
                i++;
            }
            return (i === 0 ? String(bytes) : value.toFixed(value < 10 ? 1 : 0)) + ' ' + units[i];
        }

        // ---- Screenshot strip (arrow-key navigable) ---------------------------
        var screenState = { items: [], index: 0 };

        function renderScreenshot() {
            var items = screenState.items;
            dScreensView.innerHTML = '';
            if (!items.length) {
                dScreensCaption.textContent = '';
                return;
            }
            var item = items[screenState.index];
            if (item.node) {
                dScreensView.appendChild(item.node.cloneNode(true));
            } else {
                var img = document.createElement('img');
                img.src = item.src;
                img.alt = item.caption || '';
                img.loading = 'lazy';
                dScreensView.appendChild(img);
            }
            dScreensCaption.textContent = item.caption || '';
            var multi = items.length > 1;
            dScreensPrev.hidden = !multi;
            dScreensNext.hidden = !multi;
        }

        // A package with no real screenshots still shows a single "slide" \u2014 the same
        // placeholder/icon tile already cloned into .market-detail-thumb \u2014 rather than an
        // empty strip, so the dialog never implies artwork exists when it does not.
        function setScreenshots(list, placeholderNode) {
            if (list && list.length) {
                screenState.items = list.map(function (shot) {
                    return { src: shot.src, caption: shot.caption || '' };
                });
            } else if (placeholderNode) {
                screenState.items = [{ node: placeholderNode, caption: '' }];
            } else {
                screenState.items = [];
            }
            screenState.index = 0;
            renderScreenshot();
        }

        function moveScreenshot(delta) {
            if (screenState.items.length < 2) {
                return;
            }
            screenState.index = (screenState.index + delta + screenState.items.length) % screenState.items.length;
            renderScreenshot();
        }

        dScreensPrev.addEventListener('click', function () {
            moveScreenshot(-1);
        });
        dScreensNext.addEventListener('click', function () {
            moveScreenshot(1);
        });
        dScreensStrip.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                moveScreenshot(-1);
            } else if (e.key === 'ArrowRight') {
                e.preventDefault();
                moveScreenshot(1);
            }
        });

        // ---- Version table ------------------------------------------------------
        function renderVersions(versions) {
            dVersionsBody.innerHTML = '';
            (versions || []).forEach(function (v) {
                var tr = document.createElement('tr');
                [v.version, v.requires || '\u2014', v.requires_php || '\u2014', v.tested || '\u2014', formatBytes(v.size)]
                    .forEach(function (text) {
                        var td = document.createElement('td');
                        td.textContent = text;
                        tr.appendChild(td);
                    });
                var compatTd = document.createElement('td');
                compatTd.appendChild(compatBadgeNode(v.compat));
                tr.appendChild(compatTd);
                dVersionsBody.appendChild(tr);
            });
        }

        // ---- Links ----------------------------------------------------------
        var linkLabels = {
            repo: i18n.linkRepo || 'Repository',
            homepage: i18n.linkHomepage || 'Homepage',
            issues: i18n.linkIssues || 'Issue tracker',
            docs: i18n.linkDocs || 'Documentation'
        };
        var linkOrder = ['repo', 'homepage', 'issues', 'docs'];

        function renderLinks(links) {
            dLinks.innerHTML = '';
            links = links || {};
            linkOrder.forEach(function (key) {
                var url = links[key];
                if (!url) {
                    return;
                }
                // repo and homepage are frequently the same URL; show it once.
                if (key === 'homepage' && links.repo === url) {
                    return;
                }
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.href = url;
                a.target = '_blank';
                a.rel = 'nofollow noopener';
                a.textContent = linkLabels[key];
                li.appendChild(a);
                dLinks.appendChild(li);
            });
        }

        // ---- Loading / error / ready states --------------------------------
        function setDetailState(state, message) {
            dLoading.hidden = state !== 'loading';
            dError.hidden = state !== 'error';
            if (state === 'error') {
                dError.textContent = message || i18n.detailError || i18n.ajaxError || '';
            }
            var ready = state === 'ready';
            dReadme.hidden = !ready;
            dScreens.hidden = !ready || screenState.items.length === 0;
            dVersionsWrap.hidden = !ready || dVersionsBody.children.length === 0;
            dLinks.hidden = !ready || dLinks.children.length === 0;
        }

        function applyDetail(detail, placeholderNode) {
            dReadme.innerHTML = detail.description_html || '';
            setScreenshots(detail.screenshots, placeholderNode);
            renderVersions(detail.versions);
            renderLinks(detail.links);
            setDetailState('ready');
        }

        function loadDetail(slug, placeholderNode) {
            setDetailState('loading');
            if (Object.prototype.hasOwnProperty.call(detailCache, slug)) {
                applyDetail(detailCache[slug], placeholderNode);
                return;
            }
            if (!detailUrl || !slug) {
                setDetailState('error');
                return;
            }
            fetch(detailUrl + '&slug=' + encodeURIComponent(slug), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                return r.json();
            }).then(function (res) {
                if (res && res.ok && res.detail) {
                    detailCache[slug] = res.detail;
                    applyDetail(res.detail, placeholderNode);
                } else {
                    setDetailState('error', res && res.message);
                }
            }).catch(function () {
                setDetailState('error');
            });
        }

        function openDetail(container) {
            var data = parseItem(container.matches('[data-market-item]') ? container : container.querySelector('[data-market-item]'));
            var thumbSrc = container.querySelector('.osc-thumb');
            var statusSrc = container.querySelector('.market-card-status, .osc-status');
            var actionSrc = container.querySelector('.market-card-action');

            dThumb.innerHTML = '';
            var placeholderNode = null;
            if (thumbSrc) {
                placeholderNode = thumbSrc.cloneNode(true);
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

            // The richer content (screenshots, README, version table, links) is not in the
            // row JSON at all (docs/MARKET.md \u00a78.2) \u2014 fetched here, on open, not baked into
            // page render, and cached per slug so reopening the same card is instant.
            loadDetail(data.slug, placeholderNode);

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
