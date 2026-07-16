/*
 * Categories manager — vanilla JS (no jQuery).
 *
 * Drives the category tree in categories/index.php: filter, disclosure,
 * drag-to-reorder/nest (SortableJS), enable/disable, delete-with-consequence,
 * and the slide-over edit drawer (fetches categories/iframe.php, drives its
 * locale tabs, submits over fetch). All endpoints and copy arrive as data-*
 * on .categories-app so this file stays static and cacheable.
 */
(function () {
    'use strict';

    // Admin scripts print in <head>; run once the tree markup exists.
    function init() {
    var app = document.querySelector('.categories-app');
    if (!app) {
        return;
    }

    var CFG = {
        edit: app.getAttribute('data-edit-url'),
        enable: app.getAttribute('data-enable-url'),
        del: app.getAttribute('data-delete-url'),
        order: app.getAttribute('data-order-url')
    };
    var I18N = {};
    try {
        I18N = JSON.parse(app.getAttribute('data-i18n') || '{}');
    } catch (e) {
        I18N = {};
    }

    var tree = app.querySelector('.cat-tree');
    var drawer = document.getElementById('catDrawer');
    var backdrop = document.getElementById('catDrawerBackdrop');
    var drawerBody = document.getElementById('catDrawerBody');
    var drawerTitle = document.getElementById('catDrawerTitle');
    var closeBtn = document.getElementById('catDrawerClose');
    var deleteDialog = document.getElementById('catDeleteDialog');

    function flash(type, text) {
        if (typeof setJsMessage === 'function') {
            setJsMessage(type, text);
        }
    }

    function esc(s) {
        return typeof oscEscapeHTML === 'function' ? oscEscapeHTML(s) : String(s);
    }

    // Parse a legacy endpoint response that may be JSON or a JS object literal.
    function parseResponse(text) {
        try {
            return JSON.parse(text);
        } catch (e) {
            try {
                return (new Function('return (' + text + ')'))();
            } catch (e2) {
                return null;
            }
        }
    }

    // --- Row helpers ---------------------------------------------------------
    function nodeById(id) {
        return document.getElementById('cat-' + id);
    }

    function nodeItems(node) {
        return parseInt(node.getAttribute('data-num-items'), 10) || 0;
    }

    // Sum this node's listings plus every descendant's — the true blast radius
    // of a delete, shown to the owner before they confirm.
    function subtreeCounts(node) {
        var descendants = node.querySelectorAll('.cat-children .cat-node');
        var items = nodeItems(node);
        for (var i = 0; i < descendants.length; i++) {
            items += nodeItems(descendants[i]);
        }
        return { subs: descendants.length, items: items };
    }

    function setEnabledState(node, enabled) {
        node.setAttribute('data-enabled', enabled ? '1' : '0');
        node.classList.toggle('is-disabled', !enabled);
        var pill = node.querySelector(':scope > .cat-row .cat-status');
        if (pill) {
            pill.className = 'cat-status ' + (enabled ? 'cat-status-on' : 'cat-status-off');
            pill.textContent = enabled ? I18N.enabled : I18N.disabled;
        }
        var toggle = node.querySelector(':scope > .cat-row [data-cat-act="toggle"]');
        if (toggle) {
            var icon = toggle.querySelector('i');
            if (icon) {
                icon.className = 'bi ' + (enabled ? 'bi-pause-circle' : 'bi-play-circle');
            }
            var name = node.getAttribute('data-name') || '';
            toggle.setAttribute('title', enabled ? I18N.disable : I18N.enable);
            toggle.setAttribute('aria-label', (enabled ? I18N.disable : I18N.enable) + ' ' + name);
        }
    }

    // --- Disclosure + toolbar ------------------------------------------------
    function setExpanded(node, expanded) {
        node.classList.toggle('is-collapsed', !expanded);
        var btn = node.querySelector(':scope > .cat-row .cat-disclosure');
        if (btn) {
            btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
    }

    app.addEventListener('click', function (e) {
        var disc = e.target.closest('.cat-disclosure');
        if (disc && app.contains(disc)) {
            var node = disc.closest('.cat-node');
            setExpanded(node, node.classList.contains('is-collapsed'));
            return;
        }
        var tool = e.target.closest('[data-cat-act]');
        if (!tool) {
            return;
        }
        var act = tool.getAttribute('data-cat-act');
        if (act === 'expand-all' || act === 'collapse-all') {
            var all = tree ? tree.querySelectorAll('.cat-node') : [];
            for (var i = 0; i < all.length; i++) {
                if (all[i].querySelector(':scope > .cat-row .cat-disclosure')) {
                    setExpanded(all[i], act === 'expand-all');
                }
            }
            return;
        }
        var row = tool.closest('.cat-node');
        if (!row) {
            return;
        }
        if (act === 'edit') {
            openDrawer(row);
        } else if (act === 'toggle') {
            toggleCategory(row);
        } else if (act === 'delete') {
            askDelete(row);
        }
    });

    // --- Enable / disable ----------------------------------------------------
    function toggleCategory(node) {
        var id = node.getAttribute('data-cat-id');
        var currentlyEnabled = node.getAttribute('data-enabled') === '1';
        var next = currentlyEnabled ? 0 : 1;
        var rowEl = node.querySelector(':scope > .cat-row');
        rowEl.classList.add('is-busy');

        fetch(CFG.enable + '&id=' + encodeURIComponent(id) + '&enabled=' + next, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.text();
        }).then(function (text) {
            var res = parseResponse(text);
            rowEl.classList.remove('is-busy');
            if (!res || res.error) {
                flash('error', (res && res.error) || I18N.ajaxError);
                return;
            }
            // Apply to this node and every id the server reports it cascaded to.
            setEnabledState(node, !!next);
            if (Array.isArray(res.affectedIds)) {
                for (var i = 0; i < res.affectedIds.length; i++) {
                    var affected = nodeById(res.affectedIds[i].id);
                    if (affected) {
                        setEnabledState(affected, !!next);
                    }
                }
            }
            flash('ok', res.ok);
        }).catch(function () {
            rowEl.classList.remove('is-busy');
            flash('error', I18N.ajaxError);
        });
    }

    // --- Delete (native dialog, names the consequence) -----------------------
    var pendingDelete = null;
    function askDelete(node) {
        pendingDelete = node;
        var name = node.getAttribute('data-name') || '';
        var counts = subtreeCounts(node);
        var text;
        if (counts.subs > 0) {
            text = I18N.deleteSubs
                .replace('%1$s', esc(name))
                .replace('%2$d', counts.subs)
                .replace('%3$d', counts.items);
        } else if (counts.items > 0) {
            text = I18N.deleteOne.replace('%s', esc(name));
        } else {
            text = I18N.deleteNoItems.replace('%s', esc(name));
        }
        document.getElementById('catDeleteText').innerHTML = text;
        if (typeof deleteDialog.showModal === 'function') {
            deleteDialog.showModal();
        } else {
            if (window.confirm(name)) { doDelete(); }
        }
    }

    function doDelete() {
        if (!pendingDelete) {
            return;
        }
        var node = pendingDelete;
        var id = node.getAttribute('data-cat-id');
        closeDialog();
        fetch(CFG.del + '&id=' + encodeURIComponent(id), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.text();
        }).then(function (text) {
            var res = parseResponse(text);
            if (!res || res.error) {
                flash('error', (res && res.error) || I18N.ajaxError);
                return;
            }
            node.parentNode.removeChild(node);
            flash('ok', res.ok);
        }).catch(function () {
            flash('error', I18N.ajaxError);
        });
    }

    function closeDialog() {
        if (deleteDialog.open) {
            deleteDialog.close();
        }
        pendingDelete = null;
    }

    var delConfirm = document.getElementById('catDeleteConfirm');
    var delCancel = document.getElementById('catDeleteCancel');
    if (delConfirm) { delConfirm.addEventListener('click', doDelete); }
    if (delCancel) { delCancel.addEventListener('click', closeDialog); }
    if (deleteDialog) {
        deleteDialog.addEventListener('cancel', function () { pendingDelete = null; });
        // Click on the backdrop (outside the dialog box) closes it.
        deleteDialog.addEventListener('click', function (e) {
            if (e.target === deleteDialog) { closeDialog(); }
        });
    }

    // --- Edit drawer ---------------------------------------------------------
    var lastFocus = null;
    function openDrawer(node) {
        lastFocus = document.activeElement;
        var id = node.getAttribute('data-cat-id');
        var name = node.getAttribute('data-name') || '';
        document.querySelectorAll('.cat-node.is-editing').forEach(function (n) {
            n.classList.remove('is-editing');
        });
        node.classList.add('is-editing');
        drawerTitle.textContent = name;
        drawer.dataset.catId = id;
        drawerBody.innerHTML = '<div class="cat-drawer-loading"><i class="bi bi-arrow-repeat"></i></div>';
        drawer.hidden = false;
        backdrop.hidden = false;
        // Force a reflow so the transform transition runs from the hidden state.
        void drawer.offsetWidth;
        app.classList.add('drawer-open');
        closeBtn.focus();

        fetch(CFG.edit + '&id=' + encodeURIComponent(id), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.text();
        }).then(function (html) {
            if (drawer.dataset.catId !== String(id)) {
                return; // a newer open superseded this fetch
            }
            drawerBody.innerHTML = html;
            initLocaleTabs(drawerBody);
            var firstField = drawerBody.querySelector('input[type="text"], textarea');
            if (firstField) { firstField.focus(); }
        }).catch(function () {
            drawerBody.innerHTML = '';
            flash('error', I18N.ajaxError);
            closeDrawer();
        });
    }

    function closeDrawer() {
        app.classList.remove('drawer-open');
        drawer.dataset.catId = '';
        document.querySelectorAll('.cat-node.is-editing').forEach(function (n) {
            n.classList.remove('is-editing');
        });
        var onEnd = function () {
            drawer.hidden = true;
            backdrop.hidden = true;
            drawerBody.innerHTML = '';
            drawer.removeEventListener('transitionend', onEnd);
        };
        // If motion is reduced the transition is instant; guard with a timeout.
        drawer.addEventListener('transitionend', onEnd);
        setTimeout(onEnd, 320);
        if (lastFocus && document.contains(lastFocus)) {
            lastFocus.focus();
        }
    }

    closeBtn.addEventListener('click', closeDrawer);
    backdrop.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && app.classList.contains('drawer-open')) {
            closeDrawer();
        }
    });

    // Keep focus inside the open drawer.
    drawer.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') {
            return;
        }
        var focusables = drawer.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        if (!focusables.length) {
            return;
        }
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });

    // Cancel button inside the fetched form, and the form submit.
    drawerBody.addEventListener('click', function (e) {
        if (e.target.closest('[data-cat-drawer-cancel]')) {
            e.preventDefault();
            closeDrawer();
        }
    });

    drawerBody.addEventListener('submit', function (e) {
        var form = e.target.closest('[data-cat-edit-form]');
        if (!form) {
            return;
        }
        e.preventDefault();
        var submitBtn = form.querySelector('[type="submit"]');
        if (submitBtn) { submitBtn.disabled = true; }
        fetch(form.getAttribute('action'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams(new FormData(form))
        }).then(function (r) {
            return r.text();
        }).then(function (text) {
            var res = parseResponse(text);
            if (submitBtn) { submitBtn.disabled = false; }
            if (!res) {
                flash('error', I18N.ajaxError);
                return;
            }
            // error 0 = saved, 4 = saved with an empty title (still success).
            if (res.error === 0 || res.error === 4) {
                var node = nodeById(drawer.dataset.catId);
                if (node && res.text) {
                    node.setAttribute('data-name', res.text);
                    var nameEl = node.querySelector(':scope > .cat-row .cat-name');
                    if (nameEl) { nameEl.textContent = res.text; }
                }
                flash('ok', res.msg);
                closeDrawer();
            } else {
                flash('error', res.msg || I18N.ajaxError);
            }
        }).catch(function () {
            if (submitBtn) { submitBtn.disabled = false; }
            flash('error', I18N.ajaxError);
        });
    });

    // Locale tabs use the shared vanilla helper (oscInitTabs, in ui-osc.js).
    function initLocaleTabs(scope) {
        if (typeof oscInitTabs === 'function') {
            oscInitTabs(scope);
        }
    }

    // --- Drag to reorder / nest (SortableJS) ---------------------------------
    function serialize() {
        var out = [];
        (function walk(list, parent) {
            var children = list.children;
            for (var i = 0; i < children.length; i++) {
                var li = children[i];
                if (!li.classList || !li.classList.contains('cat-node')) {
                    continue;
                }
                out.push({ c: li.getAttribute('data-cat-id'), p: parent });
                var sub = li.querySelector(':scope > .cat-children');
                if (sub) {
                    walk(sub, li.getAttribute('data-cat-id'));
                }
            }
        })(tree, null);
        return out;
    }

    if (typeof Sortable !== 'undefined' && tree) {
        var lists = [tree].concat(Array.prototype.slice.call(app.querySelectorAll('.cat-children')));
        lists.forEach(function (list) {
            new Sortable(list, {
                group: 'nested-categories',
                handle: '.cat-handle',
                draggable: '.cat-node',
                ghostClass: 'drag-ghost',
                chosenClass: 'sortable-chosen',
                animation: 150,
                fallbackOnBody: true,
                swapThreshold: 0.12,
                onEnd: function (evt) {
                    // A parent that just received its first child needs a disclosure
                    // to stay reachable; reveal the destination branch.
                    var destNode = evt.to.closest('.cat-node');
                    if (destNode) { setExpanded(destNode, true); }
                    var body = new URLSearchParams();
                    body.set('list', JSON.stringify(serialize()));
                    fetch(CFG.order, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: body
                    }).then(function (r) {
                        return r.text();
                    }).then(function (text) {
                        var res = parseResponse(text);
                        if (!res || res.error) {
                            flash('error', (res && res.error) || I18N.ajaxError);
                        } else {
                            flash('ok', res.ok);
                        }
                    }).catch(function () {
                        flash('error', I18N.ajaxError);
                    });
                }
            });
        });
    }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
