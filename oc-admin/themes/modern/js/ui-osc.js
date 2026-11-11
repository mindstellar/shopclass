/*
 * Admin UI helpers — vanilla JS (migrated off jQuery / jQuery-UI).
 *
 * Provides oscInitTabs() (the segmented locale/section tabs, replacing
 * jQuery-UI .tabs()) and wires the flash-message close buttons.
 */
/* global osc */
/* exported oscTab, tabberAutomatic, oscValidateForm, oscTreeview, checkCat, oscCatcardsSummary, oscCatcardsLive, oscCatcardsCascade, oscFill */

// Initialise every .osc-tab within `root` (default: document). Works on the
// existing markup: a .osc-tab container holding `> ul > li > a[href="#panel"]`
// and one panel element per href. Idempotent — a container is only wired once.
function oscInitTabs(root) {
    root = root || document;
    var containers = root.querySelectorAll('.osc-tab');
    for (var c = 0; c < containers.length; c++) {
        (function (container) {
            if (container.getAttribute('data-osc-tabs-init')) {
                return;
            }
            container.setAttribute('data-osc-tabs-init', '1');

            var links = container.querySelectorAll(':scope > ul > li > a');
            if (!links.length) {
                return;
            }

            // The server may mark the active tab with jQuery-UI's ui-tabs-active /
            // ui-state-active, and the jQuery-UI stylesheet paints those blue.
            // Remember which was active, then strip the ui-* classes so only the
            // theme's own .is-active styling applies.
            var serverActive = container.querySelector('ul > li.ui-tabs-active > a');
            var lis = container.querySelectorAll('ul > li');
            for (var i = 0; i < lis.length; i++) {
                lis[i].classList.remove('ui-tabs-active', 'ui-state-active', 'ui-state-default', 'ui-corner-top', 'ui-tab');
                lis[i].setAttribute('role', 'presentation');
            }

            function panelFor(link) {
                var sel = link.getAttribute('href');
                return sel && sel.charAt(0) === '#' ? document.getElementById(sel.slice(1)) : null;
            }

            // Wire the standard ARIA tabs pattern (roles + aria-selected + roving
            // tabindex) so this is a real accessible widget, not just click handlers.
            var list = container.querySelector('ul');
            if (list) { list.setAttribute('role', 'tablist'); }
            var linkArr = Array.prototype.slice.call(links);

            function select(link, focus) {
                linkArr.forEach(function (l) {
                    var on = l === link;
                    l.parentNode.classList.toggle('is-active', on);
                    l.setAttribute('role', 'tab');
                    l.setAttribute('aria-selected', on ? 'true' : 'false');
                    l.tabIndex = on ? 0 : -1;
                    var p = panelFor(l);
                    if (p) { p.hidden = !on; }
                });
                if (focus) { link.focus(); }
            }

            linkArr.forEach(function (link, idx) {
                var panel = panelFor(link);
                if (panel) {
                    panel.setAttribute('role', 'tabpanel');
                    panel.tabIndex = 0;
                    if (link.id) { panel.setAttribute('aria-labelledby', link.id); }
                    if (panel.id) { link.setAttribute('aria-controls', panel.id); }
                }
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    select(link, false);
                    // The tab goes in the address bar, so a link can point at one and Back
                    // returns to the previous tab instead of leaving the screen.
                    var href = link.getAttribute('href');
                    if (href && window.history && window.history.pushState) {
                        window.history.pushState(null, '', href);
                    }
                });
                link.addEventListener('keydown', function (e) {
                    var next = null;
                    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { next = linkArr[(idx + 1) % linkArr.length]; }
                    else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { next = linkArr[(idx - 1 + linkArr.length) % linkArr.length]; }
                    else if (e.key === 'Home') { next = linkArr[0]; }
                    else if (e.key === 'End') { next = linkArr[linkArr.length - 1]; }
                    if (next) { e.preventDefault(); select(next, true); }
                });
            });

            function linkForHash() {
                var hash = window.location.hash;
                if (!hash || hash.length < 2) {
                    return null;
                }
                for (var h = 0; h < linkArr.length; h++) {
                    if (linkArr[h].getAttribute('href') === hash) {
                        return linkArr[h];
                    }
                }

                return null;
            }

            select(linkForHash() || serverActive || linkArr[0], false);

            // A page opened on a tab's #link has the browser focus that panel, which draws a ring
            // nobody asked for; nothing has been used yet, so the focus is let go.
            function dropFragmentFocus() {
                var active = document.activeElement;
                if (active && active.getAttribute('role') === 'tabpanel' && '#' + active.id === window.location.hash) {
                    active.blur();
                }
            }
            dropFragmentFocus();
            window.addEventListener('load', dropFragmentFocus, {once: true});

            // No hash means the entry before the first tab click, so the default tab returns.
            window.addEventListener('popstate', function () {
                select(linkForHash() || serverActive || linkArr[0], false);
            });
        })(containers[c]);
    }
}

// Back-compat alias for anything (bundled or third-party) still calling oscTab().
function oscTab() {
    oscInitTabs(document);
}

// Show only the fields for the current admin locale (multi-language forms).
function tabberAutomatic() {
    if (!window.osc || !osc.locales) {
        return;
    }
    document.querySelectorAll(osc.locales.string).forEach(function (el) {
        if (el.parentNode) { el.parentNode.style.display = 'none'; }
    });
    document.querySelectorAll('[name*="' + osc.locales.current + '"], .' + osc.locales.current).forEach(function (el) {
        if (el.parentNode) { el.parentNode.style.display = ''; }
    });
}

// Config-driven form validation — replaces the jQuery-validate plugin. Keeps the
// same shape so migration is a near drop-in:
//   oscValidateForm(form, {
//     rules:    { fieldName: { required, minlength, maxlength, email, url, digits, number } },
//     messages: { fieldName: { required: '…', email: '…', … } },
//     errorContainer: '#error_list',   // <ul> that lists the messages
//     onInvalid: function (form) { … }  // e.g. scroll to the top
//   });
// On a valid submit the form posts natively; submit buttons are disabled to stop
// double-submits. On an invalid submit it is cancelled and the fields flagged.
var OSC_VALIDATORS = {
    email: function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); },
    url: function (v) { return /^(https?|ftp):\/\/[^\s/$.?#].[^\s]*$/i.test(v); },
    digits: function (v) { return /^\d+$/.test(v); },
    number: function (v) { return !isNaN(parseFloat(v)) && isFinite(v.replace(',', '.')); }
};
function oscValidateForm(form, config) {
    if (!form) {
        return;
    }
    config = config || {};
    var rules = config.rules || {};
    var messages = config.messages || {};

    function fieldError(name) {
        var el = form.querySelector('[name="' + name + '"]');
        if (!el) {
            return null;
        }
        var v = (el.value == null ? '' : String(el.value)).trim();
        var r = rules[name] || {};
        var m = messages[name] || {};
        if (r.required && v === '') { return { el: el, msg: m.required }; }
        // Custom rules run even on empty values (they may make a field
        // conditionally required based on another field).
        if (r.custom && !r.custom(v, form)) { return { el: el, msg: m.custom }; }
        if (v === '') { return null; }
        if (r.minlength && v.length < r.minlength) { return { el: el, msg: m.minlength }; }
        if (r.maxlength && v.length > r.maxlength) { return { el: el, msg: m.maxlength }; }
        if (r.email && !OSC_VALIDATORS.email(v)) { return { el: el, msg: m.email }; }
        if (r.url && !OSC_VALIDATORS.url(v)) { return { el: el, msg: m.url }; }
        if (r.digits && !OSC_VALIDATORS.digits(v)) { return { el: el, msg: m.digits }; }
        if (r.number && !OSC_VALIDATORS.number(v)) { return { el: el, msg: m.number }; }
        if (r.pattern && !r.pattern.test(v)) { return { el: el, msg: m.pattern }; }
        return null;
    }

    form.addEventListener('submit', function (e) {
        var errors = [];
        Object.keys(rules).forEach(function (name) {
            var err = fieldError(name);
            var el = form.querySelector('[name="' + name + '"]');
            if (err) {
                errors.push(err);
                if (el) { el.classList.add('is-invalid'); el.setAttribute('aria-invalid', 'true'); }
            } else if (el) {
                el.classList.remove('is-invalid');
                el.removeAttribute('aria-invalid');
            }
        });

        var container = config.errorContainer ? document.querySelector(config.errorContainer) : null;
        if (container) {
            container.innerHTML = '';
            errors.forEach(function (er) {
                var li = document.createElement('li');
                li.textContent = er.msg || '';
                container.appendChild(li);
            });
        }

        if (errors.length) {
            e.preventDefault();
            if (typeof config.onInvalid === 'function') { config.onInvalid(form); }
            if (errors[0].el && errors[0].el.focus) { errors[0].el.focus(); }
        } else {
            setTimeout(function () {
                form.querySelectorAll('button[type=submit], input[type=submit]').forEach(function (btn) {
                    btn.disabled = true;
                });
            }, 0);
        }
    });
}

// Make a nested <ul> collapsible — replaces the jQuery-treeview plugin. Each
// <li> that contains a child <ul> gets a disclosure toggle; children start
// collapsed. Idempotent per root.
function oscTreeview(root, opts) {
    if (!root || root.getAttribute('data-osc-tree-init')) {
        return;
    }
    root.setAttribute('data-osc-tree-init', '1');
    root.classList.add('osc-tree');
    opts = opts || {};
    var startCollapsed = opts.collapsed !== false;

    var lis = root.querySelectorAll('li');
    for (var i = 0; i < lis.length; i++) {
        (function (li) {
            var childUl = null;
            for (var c = 0; c < li.children.length; c++) {
                if (li.children[c].tagName === 'UL') { childUl = li.children[c]; break; }
            }
            if (!childUl) {
                return;
            }
            li.classList.add('osc-tree-parent');
            var collapsed = startCollapsed && !li.hasAttribute('data-osc-tree-open');
            if (collapsed) { li.classList.add('is-collapsed'); }
            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'osc-tree-toggle';
            toggle.innerHTML = '<i class="bi bi-chevron-down" aria-hidden="true"></i>';
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            var named = li.querySelector(':scope > label, :scope > .osc-catcard-name');
            toggle.setAttribute('aria-label', oscFill(opts.toggleLabel || root.getAttribute('data-toggle-label') || 'Toggle',
                [named ? named.textContent : '']));
            if (!childUl.id) { childUl.id = (root.id || 'osc-tree') + '-sub-' + i; }
            toggle.setAttribute('aria-controls', childUl.id);
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                var collapsed = li.classList.toggle('is-collapsed');
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            });
            li.insertBefore(toggle, li.firstChild);
        })(lis[i]);
    }

    // Reflect partial selection on a parent: an unchecked category whose subtree holds any
    // checked box shows the indeterminate dash, so a collapsed branch still signals that
    // something inside it is selected. Runs on load and after any box in the tree changes.
    function oscTreeSyncParents() {
        var items = root.querySelectorAll('li');
        for (var i = 0; i < items.length; i++) {
            var box = null;
            var sub = null;
            var kids = items[i].children;
            for (var c = 0; c < kids.length; c++) {
                if (kids[c].tagName === 'INPUT' && kids[c].type === 'checkbox') { box = kids[c]; }
                if (kids[c].tagName === 'UL') { sub = kids[c]; }
            }
            if (!box || !sub) { continue; }
            box.indeterminate = !box.checked && sub.querySelector('input[type=checkbox]:checked') !== null;
        }
    }

    root.addEventListener('change', oscTreeSyncParents);
    oscTreeSyncParents();
    oscCatcardsLive(root);
}

// Keep a card list's summary and counts current. Idempotent per root.
function oscCatcardsLive(root) {
    if (!root.hasAttribute('data-osc-catcards') || root.hasAttribute('data-osc-catcards-live')) {
        return;
    }
    root.setAttribute('data-osc-catcards-live', '1');
    root.addEventListener('change', function (e) {
        if (root.getAttribute('data-osc-catcards') === 'checks') { oscCatcardsCascade(root, e.target); }
        oscCatcardsSummary(root);
    });
    oscCatcardsSummary(root);
}

// After a box changes, each category above it is ticked when all its subcategories are,
// empty when none are, and shows the dash (unticked, so not saved) when only some are.
function oscCatcardsCascade(root, box) {
    if (!box || box.type !== 'checkbox') { return; }
    var li = box.closest('li');
    while (li && root.contains(li)) {
        var parentLi = li.parentElement ? li.parentElement.closest('li') : null;
        if (!parentLi || !root.contains(parentLi)) { return; }
        var parentBox = parentLi.querySelector(':scope > input[type=checkbox]');
        var sub = parentLi.querySelector(':scope > ul');
        if (parentBox && sub) {
            var all = sub.querySelectorAll('input[type=checkbox]').length;
            var on = sub.querySelectorAll('input[type=checkbox]:checked').length;
            parentBox.checked = all > 0 && on === all;
            parentBox.indeterminate = on > 0 && on < all;
        }
        li = parentLi;
    }
}

// Fill a translated template the way the server's sprintf() reads it: %s, %d, %1$s and %%.
function oscFill(tpl, values) {
    var i = 0;
    return String(tpl || '').replace(/%%|%(\d+\$)?[sd]/g, function (m, pos) {
        if (m === '%%') { return '%'; }
        var v = values[pos ? parseInt(pos, 10) - 1 : i++];
        return v === undefined ? '' : String(v);
    });
}

// The live line above a category card list, and each card's count of chosen subcategories.
function oscCatcardsSummary(root) {
    var summary = document.querySelector('[data-osc-catcards-summary="' + CSS.escape(root.id) + '"]');
    var cards = root.querySelectorAll(':scope > .osc-catcard');
    var label = function (key) { return root.getAttribute('data-label-' + key); };
    var setMeta = function (card, extra) {
        var meta = card.querySelector(':scope > [data-osc-catcard-meta]');
        if (!meta) { return; }
        if (!meta.hasAttribute('data-base')) { meta.setAttribute('data-base', meta.textContent); }
        meta.textContent = meta.getAttribute('data-base') + (extra ? ' · ' + extra : '');
    };

    if (root.getAttribute('data-osc-catcards') === 'modes') {
        cards.forEach(function (card) {
            var picked = card.querySelector(':scope > .osc-segmented input:checked');
            var pickedLabel = picked ? card.querySelector('label[for="' + CSS.escape(picked.id) + '"]') : null;
            var topMode = picked && picked.value !== '' ? pickedLabel.textContent : '';
            var offWord = card.querySelector(':scope > .osc-segmented input[value=""] + label');
            var cardName = card.querySelector('.osc-catcard-name').textContent;

            // Rows come in tree order with their depth; each "Same as parent" names what it inherits.
            var apart = 0;
            var topValue = topMode || (offWord ? offWord.textContent : '');
            var modes = [topValue];
            var paths = [cardName];
            var head = card.querySelector('[data-osc-catmatrix-same]');
            if (head) { head.textContent = oscFill(label('inherit'), [topValue]); }
            card.querySelectorAll('.osc-catmatrix-row').forEach(function (row) {
                var depth = parseInt(row.getAttribute('data-depth') || '0', 10);
                var inherited = modes[depth];
                var same = row.querySelector('input[value=""] + .osc-catmatrix-label');
                if (same) { same.textContent = oscFill(label('inherit'), [inherited]); }
                var chosen = row.querySelector('input:checked');
                var own = chosen && chosen.value !== '';
                var mode = own ? chosen.nextElementSibling.textContent : inherited;
                var name = row.querySelector('.osc-catcard-subname').textContent;
                row.classList.toggle('is-set', !!own);
                if (own) {
                    apart++;
                }
                modes[depth + 1] = mode;
                paths[depth + 1] = paths[depth] + ' › ' + name;
            });
            setMeta(card, apart ? oscFill(label('apart'), [apart]) : '');
        });
        return;
    }

    var boxes = root.querySelectorAll('input[type=checkbox]');
    var checked = root.querySelectorAll('input[type=checkbox]:checked').length;
    if (summary) { summary.textContent = oscFill(label('count'), [checked, boxes.length]); }
    cards.forEach(function (card) {
        var inside = card.querySelectorAll(':scope > ul input[type=checkbox]:checked').length;
        setMeta(card, inside ? oscFill(label('selected'), [inside]) : '');
    });
}

// A category_modes field folds on its own. A checkbox tree folds only when its page calls
// oscTreeview(), as it always has, so a page passing its own options keeps them.
function oscCatcardsInit() {
    document.querySelectorAll('[data-osc-catcards]').forEach(function (root) {
        if (root.getAttribute('data-osc-catcards') === 'modes') {
            oscTreeview(root);
        } else {
            oscCatcardsLive(root);
        }
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', oscCatcardsInit);
} else {
    oscCatcardsInit();
}

// Global so third-party plugins calling checkAll(id, check) by name keep working.
function checkAll(id, check) {
    var root = document.getElementById(id);
    if (root) {
        root.querySelectorAll('input[type=checkbox]').forEach(function (cb) { cb.checked = check; });
        // Bulk toggles set .checked in script, which fires no change event; nudge one so a
        // treeview watching this root re-reads its parent indeterminate states.
        root.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

// Toggle a category subtree (#cat<id>); emitted inline by CategoryForm::categories_tree
// on every parent checkbox, so it must be global on every page a tree picker can appear.
function checkCat(id, check) {
    var root = document.getElementById('cat' + id);
    if (root) { root.querySelectorAll('input[type=checkbox]').forEach(function (cb) { cb.checked = check; }); }
}

// Delegated, so it also covers tree pickers injected after load (e.g. the field
// and group edit iframes, which are innerHTML-injected).
document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-tree-toggle]');
    if (!t) { return; }
    e.preventDefault();
    checkAll(t.getAttribute('data-tree-toggle'), t.getAttribute('data-tree-check') === '1');
});

// Flash messages — progressive enhancement shared by core- and plugin-rendered
// markup (anything carrying .flashmessage). Adds the ARIA a screen reader needs,
// a labelled dismiss control, and an animated removal that respects reduced motion.
function oscDismissFlash(fm) {
    if (!fm) { return; }
    clearTimeout(fm.oscFlashTimer);
    fm.oscFlashTimer = 0;
    // #jsMessage is reused for the next message, so it is hidden rather than removed.
    var gone = function () {
        if (fm.id === 'jsMessage') {
            fm.classList.add('hide');
            fm.classList.remove('is-dismissing');
        } else {
            fm.remove();
        }
        oscFlashSync();
    };
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) { gone(); return; }
    var done = false;
    // setJsMessage clears is-dismissing when a new message arrives during the exit.
    var finish = function () { if (!done) { done = true; if (fm.classList.contains('is-dismissing')) { gone(); } } };
    fm.classList.add('is-dismissing');
    fm.addEventListener('animationend', finish, { once: true });
    setTimeout(finish, 400); // fallback if animationend never fires (e.g. hidden tab)
    oscFlashSync();
}

// The result messages in #message-wrapper: up to three in view, worst first, then "and N more".
// ok and info hide after 7 s unless they have no close button; hover or focus pauses the clock.
var OSC_FLASH_MS = 7000;
var OSC_FLASH_CAP = 3;

function oscFlashCol() {
    return document.querySelector('#message-wrapper > .col');
}

function oscFlashList(col) {
    return Array.prototype.filter.call(col.children, function (el) {
        return el.classList.contains('flashmessage') && !el.classList.contains('hide') && !el.classList.contains('is-dismissing');
    });
}

function oscFlashRank(el) {
    if (el.classList.contains('flashmessage-error')) { return 0; }
    return el.classList.contains('flashmessage-warning') ? 1 : 2;
}

function oscFlashHides(el) {
    return (el.classList.contains('flashmessage-ok') || el.classList.contains('flashmessage-info')) && !!el.querySelector('.ico-close');
}

function oscFlashMore(col, hidden) {
    var more = col.querySelector(':scope > .osc-flash-more');
    if (!more && hidden > 0) {
        more = document.createElement('button');
        more.type = 'button';
        more.className = 'btn btn-link osc-flash-more';
        more.addEventListener('click', function () {
            var target = col.querySelector(':scope > .flashmessage.is-stacked .ico-close');
            col.dataset.oscExpanded = '1';
            oscFlashSync();
            if (target) { target.focus(); }
        });
        col.appendChild(more);
    }
    if (more) {
        var label = col.parentNode.dataset.oscMore ? JSON.parse(col.parentNode.dataset.oscMore) : 'and %d more';
        more.hidden = hidden === 0;
        more.textContent = String(osc.plural(label, hidden)).replace('%d', hidden);
    }
}

// Sets what is in view and starts or stops the clocks.
function oscFlashSync() {
    var col = oscFlashCol();
    if (!col) { return; }
    var list = oscFlashList(col);
    if (list.length === 0) { col.oscFlashHover = false; }
    if (list.length <= OSC_FLASH_CAP) { delete col.dataset.oscExpanded; }
    var keep = col.dataset.oscExpanded ? list : list.slice().sort(function (a, b) {
        return oscFlashRank(a) - oscFlashRank(b) || list.indexOf(b) - list.indexOf(a);
    }).slice(0, OSC_FLASH_CAP);
    var paused = !!col.oscFlashHover || col.contains(document.activeElement);
    list.forEach(function (el) {
        var inView = keep.indexOf(el) !== -1;
        el.classList.toggle('is-stacked', !inView);
        if (!inView || paused || !oscFlashHides(el)) {
            clearTimeout(el.oscFlashTimer);
            el.oscFlashTimer = 0;
        } else if (!el.oscFlashTimer) {
            el.oscFlashTimer = setTimeout(function () {
                el.oscFlashTimer = 0;
                oscDismissFlash(el);
            }, OSC_FLASH_MS);
        }
    });
    oscFlashMore(col, list.length - keep.length);
}

function oscFlashText(el) {
    var copy = el.cloneNode(true);
    copy.querySelectorAll('.ico-close').forEach(function (close) { close.remove(); });
    return copy.textContent.replace(/\s+/g, ' ').trim();
}

// The one always-present speaker: #osc-live holds a polite and an assertive region.
function oscFlashRegion(type) {
    var mode = type === 'error' ? 'assertive' : 'polite';
    var box = document.getElementById('osc-live');
    if (!box) {
        box = document.createElement('div');
        box.id = 'osc-live';
        box.className = 'visually-hidden';
        box.innerHTML = '<div data-osc-live="polite" role="status" aria-live="polite"></div>'
            + '<div data-osc-live="assertive" role="alert" aria-live="assertive"></div>';
        document.body.appendChild(box);
    }
    return box.querySelector('[data-osc-live="' + mode + '"]');
}

// A flash already in the page at load is not spoken by itself, so it is copied into the speaker.
function oscFlashSpeakPage(col) {
    var said = {polite: [], assertive: []};
    oscFlashList(col).forEach(function (el) {
        var text = oscFlashText(el);
        if (text) { said[el.classList.contains('flashmessage-error') ? 'assertive' : 'polite'].push(text); }
    });
    setTimeout(function () {
        if (said.polite.length) { osc.announce(oscFlashRegion('ok'), said.polite.join(' ')); }
        if (said.assertive.length) { osc.announce(oscFlashRegion('error'), said.assertive.join(' ')); }
    }, 100);
}

function oscFlashInit() {
    var col = oscFlashCol();
    if (!col) { return; }
    var wrapper = col.parentNode;
    wrapper.addEventListener('mouseenter', function () { col.oscFlashHover = true; oscFlashSync(); });
    wrapper.addEventListener('mouseleave', function () { col.oscFlashHover = false; oscFlashSync(); });
    wrapper.addEventListener('focusin', oscFlashSync);
    wrapper.addEventListener('focusout', function () { setTimeout(oscFlashSync, 0); });
    // A script may add its own .flashmessage here; it follows the same rule.
    new MutationObserver(function () {
        oscEnhanceFlash(col);
        oscFlashSync();
    }).observe(col, {childList: true});
    oscFlashSpeakPage(col);
    oscFlashSync();
}

function oscEnhanceFlash(root) {
    (root || document).querySelectorAll('.flashmessage').forEach(function (fm) {
        if (fm.dataset.oscFlash) { return; }
        fm.dataset.oscFlash = '1';
        var isError = fm.classList.contains('flashmessage-error');
        // In the stack the shared speaker says it once; elsewhere the box is its own region.
        if (fm.closest('#message-wrapper')) {
            fm.removeAttribute('role');
            fm.removeAttribute('aria-live');
        } else {
            // An error interrupts; anything else is a passive confirmation.
            if (!fm.hasAttribute('role')) { fm.setAttribute('role', isError ? 'alert' : 'status'); }
            if (!fm.hasAttribute('aria-live')) { fm.setAttribute('aria-live', isError ? 'assertive' : 'polite'); }
        }
        var close = fm.querySelector('.ico-close');
        if (close) {
            close.setAttribute('role', 'button');
            if (!close.hasAttribute('tabindex')) { close.setAttribute('tabindex', '0'); }
            // Prefer the translated label the server emits; fall back for plugin markup that
            // renders its own flash without it.
            if (!close.hasAttribute('aria-label')) { close.setAttribute('aria-label', close.dataset.ocCloseLabel || 'Dismiss'); }
        }
    });
}

// Delegated, so it also covers flash messages shown after load (e.g. #jsMessage on
// an AJAX result), not just those present at DOMContentLoaded.
// A notice carrying [data-osc-hide-cookie] stays hidden for 7 days once dismissed.
function oscCloseFlash(close) {
    var fm = close.closest('.flashmessage');
    if (fm.dataset.oscHideCookie) {
        document.cookie = fm.dataset.oscHideCookie + '=' + encodeURIComponent(fm.dataset.oscHideValue || '1')
            + '; max-age=604800; path=/; SameSite=Lax';
    }
    oscDismissFlash(fm);
}
document.addEventListener('click', function (e) {
    var close = e.target.closest ? e.target.closest('.flashmessage .ico-close') : null;
    if (close) { e.preventDefault(); oscCloseFlash(close); }
});
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') { return; }
    var close = e.target.closest ? e.target.closest('.flashmessage .ico-close') : null;
    if (close) { e.preventDefault(); oscCloseFlash(close); }
});

document.addEventListener('DOMContentLoaded', function () {
    // Flash messages: announce them to assistive tech and wire up dismissal. (The
    // help box closes via Bootstrap's own collapse toggle, so no handler here.)
    oscEnhanceFlash(document);
    oscFlashInit();

    oscInitTabs(document);
});

// A control marked [data-osc-needs="<name>"] is disabled while that checkbox in its form is
// off, so a setting that cannot work on its own never looks active.
function oscSyncNeeds(root) {
    (root || document).querySelectorAll('[data-osc-needs]').forEach(function (el) {
        var scope = el.form || document;
        var master = scope.querySelector('[name="' + el.getAttribute('data-osc-needs').replace(/"/g, '\\"') + '"]');
        if (!master) {
            return;
        }
        var on = master.type === 'checkbox' ? master.checked : master.value !== '';
        el.disabled = !on;
        var row = el.closest('.form-label-checkbox, .form-row');
        if (row) {
            row.classList.toggle('is-unavailable', !on);
        }
    });
}
document.addEventListener('change', function (e) {
    var name = e.target && e.target.name;
    if (name && document.querySelector('[data-osc-needs="' + name.replace(/"/g, '\\"') + '"]')) {
        oscSyncNeeds(document);
    }
});
document.addEventListener('DOMContentLoaded', function () {
    oscSyncNeeds(document);
});

// A form marked [data-osc-confirm-empty="#dialogId"] asks first when every visible field is
// blank. The dialog's own confirm button submits the form, and that submit passes.
document.addEventListener('submit', function (e) {
    var form = e.target;
    var id = form.getAttribute ? form.getAttribute('data-osc-confirm-empty') : null;
    if (!id || (e.submitter && e.submitter.closest('dialog'))) {
        return;
    }
    var dialog = document.querySelector(id);
    var filled = Array.prototype.some.call(
        form.querySelectorAll('input:not([type="hidden"]), textarea, select'),
        function (field) { return field.value.trim() !== ''; }
    );
    if (!filled && dialog && typeof dialog.showModal === 'function') {
        e.preventDefault();
        dialog.showModal();
    }
});

// A button with [data-osc-fill="#field"] puts its [data-osc-fill-value] into that field.
document.addEventListener('click', function (e) {
    var chip = e.target.closest ? e.target.closest('[data-osc-fill]') : null;
    var field = chip ? document.querySelector(chip.getAttribute('data-osc-fill')) : null;
    if (!field) {
        return;
    }
    field.value = chip.getAttribute('data-osc-fill-value') || '';
    field.dispatchEvent(new Event('input', {bubbles: true}));
    chip.parentNode.querySelectorAll('[data-osc-fill]').forEach(function (other) {
        other.setAttribute('aria-pressed', other === chip ? 'true' : 'false');
    });
    field.focus();
});

// Shared behaviour for native <dialog class="osc-dialog">: a click on any
// [data-osc-dialog-open="#id"] opens the target dialog, [data-osc-dialog-close]
// closes its dialog, and a click on the dialog's own backdrop area (the element
// itself, outside the content) closes it too.
document.addEventListener('click', function (e) {
    var opener = e.target.closest ? e.target.closest('[data-osc-dialog-open]') : null;
    if (opener) {
        var target = document.querySelector(opener.getAttribute('data-osc-dialog-open'));
        if (target && typeof target.showModal === 'function') {
            e.preventDefault();
            target.showModal();
            // Chrome focuses a scrolling body first; the first field is where typing should go.
            if (document.activeElement && document.activeElement.classList.contains('osc-dialog-body')) {
                var first = target.querySelector('[autofocus], input:not([type="hidden"]), select, textarea, button');
                if (first) { first.focus(); }
            }
        }
        return;
    }
    var closer = e.target.closest ? e.target.closest('[data-osc-dialog-close]') : null;
    if (closer) {
        var d = closer.closest('dialog');
        if (d && typeof d.close === 'function') {
            e.preventDefault();
            d.close();
        }
        return;
    }
    // A backdrop click is judged by where the pointer landed, not by what the event
    // names as its target. A control that removes itself between mousedown and click --
    // an autocomplete list closing as you pick from it -- leaves the click reporting the
    // dialog underneath, and a target test then reads that as "clicked the backdrop".
    if (e.target.matches && e.target.matches('dialog.osc-dialog') && typeof e.target.close === 'function') {
        var box = e.target.getBoundingClientRect();
        var inside = e.clientX >= box.left && e.clientX <= box.right
                  && e.clientY >= box.top && e.clientY <= box.bottom;
        // A keyboard-triggered click reports 0,0 and is never a backdrop click.
        if (!inside && (e.clientX !== 0 || e.clientY !== 0)) {
            e.target.close();
        }
    }
});

// Shift-click a checkbox to toggle every checkbox between it and the last one
// clicked — a bulk-select convenience for the admin data tables. Scoped to the
// nearest table or form so a range never leaks across regions.
(function () {
    var lastChecked = null;
    document.addEventListener('click', function (e) {
        var box = e.target.closest ? e.target.closest('input[type="checkbox"]') : null;
        if (!box) {
            return;
        }
        var scope = box.closest('table, form') || document;
        var boxes = Array.prototype.slice.call(scope.querySelectorAll('input[type="checkbox"]'));
        if (lastChecked && e.shiftKey && boxes.indexOf(lastChecked) !== -1) {
            var start = boxes.indexOf(box);
            var end = boxes.indexOf(lastChecked);
            if (start !== -1) {
                boxes.slice(Math.min(start, end), Math.max(start, end) + 1).forEach(function (b) {
                    b.checked = lastChecked.checked;
                });
            }
        }
        lastChecked = box;
    });
})();

// Reveal toggle for osc_admin_secret(..., 'reveal' => true). Delegated, so a field
// rendered into a dialog or by a plugin after load is wired without re-initialising.
document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-osc-reveal]') : null;
    if (!btn) {
        return;
    }
    var input = document.getElementById(btn.getAttribute('data-osc-reveal'));
    if (!input) {
        return;
    }
    var shown = input.type === 'text';
    input.type = shown ? 'password' : 'text';
    btn.setAttribute('aria-pressed', shown ? 'false' : 'true');
    if (btn.dataset.labelShow || btn.dataset.labelHide) {
        btn.textContent = shown ? btn.dataset.labelShow : btn.dataset.labelHide;
    }
});

// Conditional fields: a form row carrying data-osc-depends="<field name>" is shown only
// while that field is switched on, which is UX — the save path re-evaluates the same
// relationship and discards a hidden field's value.
// "On" matches the server: neither empty nor "0", or, for a row carrying
// data-osc-depends-value, one of the listed values. A master that is itself dependent
// counts as off while its own master is.
function oscDependsValues(row) {
    var raw = row.getAttribute('data-osc-depends-value');
    if (raw === null) {
        return null;
    }
    try {
        var list = JSON.parse(raw);
        return Array.isArray(list) ? list.map(String) : [];
    } catch {
        return [];
    }
}

function oscDependsOn(name, scope, seen, values) {
    if (!name || seen.indexOf(name) !== -1) {
        return false;
    }
    seen.push(name);
    var controls = scope.querySelectorAll('[name="' + name.replace(/"/g, '\\"') + '"]');
    if (!controls.length) {
        return false;
    }
    var on = false;
    var masterRow = null;
    for (var i = 0; i < controls.length; i++) {
        var el = controls[i];
        var value = String((el.type === 'checkbox' || el.type === 'radio')
            ? (el.checked ? (el.value === '' ? '1' : el.value) : '')
            : el.value);
        if (values ? values.indexOf(value.trim()) !== -1 : (value.trim() !== '' && value !== '0')) {
            on = true;
        }
        if (!masterRow && el.closest) {
            masterRow = el.closest('[data-osc-depends]');
        }
    }
    if (on && masterRow) {
        on = oscDependsOn(masterRow.getAttribute('data-osc-depends'), scope, seen, oscDependsValues(masterRow));
    }
    return on;
}

// A hidden control that is still `required` blocks submission with a message pointing at
// something nobody can see, so the flag is lifted while the row is off and put back with
// it. The server decides again either way: the field is not required while its master is
// off, whatever the browser was told.
function oscDependsRequired(row, on) {
    var controls = row.querySelectorAll('input, select, textarea');
    for (var i = 0; i < controls.length; i++) {
        var control = controls[i];
        if (!on && control.required) {
            control.setAttribute('data-osc-was-required', '1');
            control.required = false;
        } else if (on && control.hasAttribute('data-osc-was-required')) {
            control.removeAttribute('data-osc-was-required');
            control.required = true;
        }
    }
}

function oscSyncDepends(root) {
    root = root || document;
    var rows = root.querySelectorAll('[data-osc-depends]');
    for (var i = 0; i < rows.length; i++) {
        var scope = (rows[i].closest && rows[i].closest('form')) || document;
        var on = oscDependsOn(rows[i].getAttribute('data-osc-depends'), scope, [], oscDependsValues(rows[i]));
        rows[i].hidden = !on;
        oscDependsRequired(rows[i], on);
    }
}

// A control nothing declares a dependency on cannot flip any row, and typing in one is
// the common case: without this every keystroke in any box re-scans every dependent row
// on the page.
function oscDependsIsMaster(target) {
    var name = target && target.name;

    return !!name && !!document.querySelector('[data-osc-depends="' + name.replace(/"/g, '\\"') + '"]');
}

// The action row of a declared settings page counts what has actually changed since the
// page loaded, so Save is quiet on a form nobody has touched.
function oscControlValue(el) {
    if (el.type === 'checkbox' || el.type === 'radio') {
        return el.checked ? '1' : '';
    }
    if (el.multiple && el.options) {
        var picked = [];
        for (var i = 0; i < el.options.length; i++) {
            if (el.options[i].selected) { picked.push(el.options[i].value); }
        }

        return picked.join('\u0000');
    }

    return el.value;
}

function oscDirtyControls(form) {
    var out = [];
    var all = form.querySelectorAll('input, select, textarea');
    for (var i = 0; i < all.length; i++) {
        var el = all[i];
        // A control with no name submits nothing, and the token is not the admin's edit.
        if (!el.name || el.type === 'submit' || el.type === 'button' || el.name === 'CSRFName'
            || el.name === 'CSRFToken') {
            continue;
        }
        out.push(el);
    }

    return out;
}

function oscDirtySync(bar) {
    var form = bar.closest && bar.closest('form');
    if (!form) { return; }
    var controls = oscDirtyControls(form);
    var changed = 0;
    var radios = {};
    for (var i = 0; i < controls.length; i++) {
        if (controls[i].getAttribute('data-osc-was') === oscControlValue(controls[i])) { continue; }
        // Picking another radio changes two buttons but one setting.
        if (controls[i].type === 'radio') {
            if (radios[controls[i].name]) { continue; }
            radios[controls[i].name] = true;
        }
        changed++;
    }
    var status = bar.querySelector('.form-actions-status');
    if (status) {
        status.textContent = changed === 0
            ? ''
            : (changed === 1
                ? (bar.getAttribute('data-osc-dirty-one') || '')
                : (bar.getAttribute('data-osc-dirty-many') || '').replace('%d', String(changed)));
    }
    bar.classList.toggle('is-dirty', changed > 0);
}

function oscDirtyInit(root) {
    var bars = (root || document).querySelectorAll('[data-osc-dirty-bar]');
    for (var i = 0; i < bars.length; i++) {
        var form = bars[i].closest && bars[i].closest('form');
        if (!form) { continue; }
        var controls = oscDirtyControls(form);
        for (var j = 0; j < controls.length; j++) {
            controls[j].setAttribute('data-osc-was', oscControlValue(controls[j]));
        }
        oscDirtySync(bars[i]);
    }
}

document.addEventListener('DOMContentLoaded', function () { oscDirtyInit(document); });
document.addEventListener('change', function (e) {
    var bar = e.target && e.target.form && e.target.form.querySelector('[data-osc-dirty-bar]');
    if (bar) { oscDirtySync(bar); }
});
document.addEventListener('input', function (e) {
    var bar = e.target && e.target.form && e.target.form.querySelector('[data-osc-dirty-bar]');
    if (bar) { oscDirtySync(bar); }
});

document.addEventListener('DOMContentLoaded', function () { oscSyncDepends(document); });
// Delegated and re-run whole: one change can flip a chain of rows, not only the row
// whose master was touched.
document.addEventListener('change', function (e) {
    if (oscDependsIsMaster(e.target)) { oscSyncDepends(document); }
});
document.addEventListener('input', function (e) {
    if (oscDependsIsMaster(e.target)) { oscSyncDepends(document); }
});

// osc.collapse — show and hide a .collapse panel from a [data-osc-collapse="#panel"] button,
// an accordion with data-osc-parent. See docs/site/developers/scripts-and-styles.md.
// The panel can set --osc-collapse-duration and --osc-collapse-easing; a click while it moves
// turns it around from where it is.
osc.collapse = (function () {
    var DURATION = 350;
    var moving = new WeakMap();

    function emit(panel, name, cancelable) {
        return panel.dispatchEvent(new CustomEvent('osc:collapse:' + name, {bubbles: true, cancelable: !!cancelable}));
    }

    function sync(panel, open) {
        if (!panel.id) { return; }
        document.querySelectorAll('[data-osc-collapse="#' + CSS.escape(panel.id) + '"]').forEach(function (t) {
            t.setAttribute('aria-expanded', open ? 'true' : 'false');
            t.classList.toggle('collapsed', !open);
        });
    }

    function timing(panel) {
        var css = getComputedStyle(panel);
        var m = /^([\d.]+)(m?s)$/.exec(css.getPropertyValue('--osc-collapse-duration').trim());
        return {
            duration: m ? parseFloat(m[1]) * (m[2] === 's' ? 1000 : 1) : DURATION,
            easing: css.getPropertyValue('--osc-collapse-easing').trim() || 'ease'
        };
    }

    function isOpen(panel) {
        var run = moving.get(panel);
        return run ? run.open : panel.classList.contains('show');
    }

    // Animates the panel's height from its current value, so a reversal never jumps.
    function run(panel, open, done) {
        var current = moving.get(panel);
        var from = (current || panel.classList.contains('show')) ? panel.getBoundingClientRect().height : 0;
        if (current) {
            moving.delete(panel);
            current.anim.cancel();
        }
        panel.classList.add('show');
        var full = panel.scrollHeight;
        var to = open ? full : 0;
        var t = timing(panel);
        var duration = full > 0 ? t.duration * Math.abs(to - from) / full : 0;
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        function end() {
            panel.style.overflow = '';
            if (!open) { panel.classList.remove('show'); }
            done();
        }
        if (reduce || duration <= 0 || !panel.animate) {
            end();
            return;
        }
        panel.style.overflow = 'hidden';
        var state = {open: open};
        state.anim = panel.animate([{height: from + 'px'}, {height: to + 'px'}], {duration: duration, easing: t.easing, fill: 'forwards'});
        moving.set(panel, state);
        state.anim.onfinish = function () {
            if (moving.get(panel) !== state) { return; }
            moving.delete(panel);
            end();
            state.anim.cancel();
        };
    }

    function show(panel) {
        if (!panel || isOpen(panel) || !emit(panel, 'show', true)) {
            return;
        }
        var group = panel.getAttribute('data-osc-parent');
        if (group) {
            document.querySelectorAll('.collapse[data-osc-parent]').forEach(function (other) {
                if (other !== panel && other.getAttribute('data-osc-parent') === group) { hide(other); }
            });
        }
        sync(panel, true);
        run(panel, true, function () { emit(panel, 'shown'); });
    }

    function hide(panel) {
        if (!panel || !isOpen(panel) || !emit(panel, 'hide', true)) {
            return;
        }
        sync(panel, false);
        run(panel, false, function () { emit(panel, 'hidden'); });
    }

    function toggle(panel) {
        if (panel) { (isOpen(panel) ? hide : show)(panel); }
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-osc-collapse]');
        if (!trigger) { return; }
        e.preventDefault();
        try {
            toggle(document.querySelector(trigger.getAttribute('data-osc-collapse')));
        } catch {
            // An invalid selector in the markup; nothing to toggle.
        }
    });

    return {show: show, hide: hide, toggle: toggle, isOpen: isOpen};
})();

// osc.offcanvas — slide a .offcanvas panel in from a [data-osc-offcanvas="#panel"] button,
// over a backdrop; Escape, the backdrop or osc.offcanvas.hide(panel) close it.
osc.offcanvas = (function () {
    var open = null, backdrop = null, opener = null;

    function emit(panel, name, cancelable) {
        return panel.dispatchEvent(new CustomEvent('osc:offcanvas:' + name, {bubbles: true, cancelable: !!cancelable}));
    }

    function settle(panel, done) {
        var finished = false;
        function finish() { if (!finished) { finished = true; done(); } }
        var css = getComputedStyle(panel);
        if ((parseFloat(css.transitionDuration) || 0) + (parseFloat(css.transitionDelay) || 0) === 0) {
            finish();
            return;
        }
        panel.addEventListener('transitionend', function end(e) {
            if (e.target === panel) { panel.removeEventListener('transitionend', end); finish(); }
        });
        setTimeout(finish, 400);
    }

    function show(panel, trigger) {
        if (!panel || open || !emit(panel, 'show', true)) { return; }
        open = panel;
        opener = trigger || document.activeElement;
        backdrop = document.createElement('div');
        backdrop.className = 'offcanvas-backdrop fade';
        backdrop.addEventListener('click', function () { hide(panel); });
        document.body.appendChild(backdrop);
        document.body.style.overflow = 'hidden';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.classList.add('showing');
        void panel.offsetHeight;
        backdrop.classList.add('show');
        settle(panel, function () {
            panel.classList.remove('showing');
            panel.classList.add('show');
            var focusable = panel.querySelector('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])');
            if (focusable) { focusable.focus(); }
            emit(panel, 'shown');
        });
    }

    function hide(panel) {
        if (!panel || open !== panel || !emit(panel, 'hide', true)) { return; }
        panel.classList.add('hiding');
        if (backdrop) { backdrop.classList.remove('show'); }
        settle(panel, function () {
            panel.classList.remove('show', 'hiding');
            panel.removeAttribute('role');
            panel.removeAttribute('aria-modal');
            if (backdrop) { backdrop.remove(); backdrop = null; }
            document.body.style.overflow = '';
            open = null;
            if (opener && opener.focus) { opener.focus(); }
            emit(panel, 'hidden');
        });
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-osc-offcanvas]');
        if (!trigger) { return; }
        e.preventDefault();
        var panel = document.querySelector(trigger.getAttribute('data-osc-offcanvas'));
        if (panel) { (open === panel ? hide(panel) : show(panel, trigger)); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && open) { hide(open); }
    });

    return {show: show, hide: hide, isOpen: function (panel) { return open === panel; }};
})();

// osc.dropdown — open the .dropdown-menu next to a [data-osc-dropdown] button. An outside
// click or Escape closes it; the arrow keys move between its .dropdown-item links.
osc.dropdown = (function () {
    var open = null;

    function menuOf(trigger) {
        return trigger.parentElement ? trigger.parentElement.querySelector('.dropdown-menu') : null;
    }

    function emit(trigger, name) {
        trigger.dispatchEvent(new CustomEvent('osc:dropdown:' + name, {bubbles: true}));
    }

    function show(trigger) {
        var menu = menuOf(trigger);
        if (!menu || open === trigger) { return; }
        if (open) { hide(open); }
        menu.classList.add('show');
        trigger.classList.add('show');
        trigger.setAttribute('aria-expanded', 'true');
        open = trigger;
        emit(trigger, 'shown');
    }

    function hide(trigger) {
        var menu = menuOf(trigger);
        if (!menu || open !== trigger) { return; }
        menu.classList.remove('show');
        trigger.classList.remove('show');
        trigger.setAttribute('aria-expanded', 'false');
        open = null;
        emit(trigger, 'hidden');
    }

    function move(step) {
        var items = Array.prototype.slice.call(menuOf(open).querySelectorAll('.dropdown-item:not(.disabled)'));
        if (!items.length) { return; }
        var at = items.indexOf(document.activeElement);
        items[(at + step + items.length) % items.length].focus();
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-osc-dropdown]');
        if (trigger) {
            e.preventDefault();
            (open === trigger ? hide : show)(trigger);
            return;
        }
        if (open && !menuOf(open).contains(e.target)) { hide(open); }
    });
    document.addEventListener('keydown', function (e) {
        if (!open) { return; }
        if (e.key === 'Escape') {
            var trigger = open;
            hide(trigger);
            trigger.focus();
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            move(e.key === 'ArrowDown' ? 1 : -1);
        }
    });

    return {show: show, hide: hide};
})();

// osc.announce(el, text): puts text in a live region, re-set on the next frame when it is the
// same so a screen reader reads it again. The same text on another region within a second is not said again.
var oscSaid = {el: null, text: '', at: 0};
osc.announce = function (el, text) {
    if (!el) {
        return;
    }
    var now = Date.now();
    if (text && text === oscSaid.text && el !== oscSaid.el && now - oscSaid.at < 1000) {
        return;
    }
    oscSaid = {el: el, text: text, at: now};
    if (el.textContent !== (text || '')) {
        el.textContent = text || '';
        return;
    }
    el.textContent = '';
    window.requestAnimationFrame(function () {
        el.textContent = text || '';
    });
};

// osc.plural(p, n): the text for n from plural forms the server sent as {forms, idx} (one digit
// per n = 0..199); a plain string is returned as it is.
osc.plural = function (p, n) {
    if (!p || !Array.isArray(p.forms)) {
        return p;
    }
    var i = n < 200 ? n : 100 + (n % 100);
    return p.forms[+p.idx.charAt(i) || 0];
};

// osc.el(tag, attrs, kids): a new element; attrs.text sets its text, other attrs are attributes.
osc.el = function (tag, attrs, kids) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
        if (k === 'text') {
            node.textContent = attrs[k];
        } else {
            node.setAttribute(k, attrs[k]);
        }
    });
    (kids || []).forEach(function (kid) {
        if (kid) {
            node.append(kid);
        }
    });
    return node;
};

// osc.flatTree: helpers for a tree held as a pre-order list with a 0-based depth. A block is a row
// plus every row nested under it; skipFrom/skipTo leave out a moving block.
osc.flatTree = (function () {
    function inSkip(k, skipFrom, skipTo) {
        return skipTo > skipFrom && k >= skipFrom && k < skipTo;
    }

    function above(at, skipFrom, skipTo) {
        var k = at - 1;
        return inSkip(k, skipFrom, skipTo) ? skipFrom - 1 : k;
    }

    function below(items, at, skipFrom, skipTo) {
        var k = inSkip(at, skipFrom, skipTo) ? skipTo : at;
        return k < items.length ? k : -1;
    }

    function blockEnd(items, i) {
        var end = i + 1;
        while (end < items.length && items[end].depth > items[i].depth) {
            end++;
        }
        return end;
    }

    function height(items, i) {
        var h = 0;
        for (var k = i + 1, end = blockEnd(items, i); k < end; k++) {
            h = Math.max(h, items[k].depth - items[i].depth);
        }
        return h;
    }

    function range(items, at, skipFrom, skipTo) {
        var p = above(at, skipFrom, skipTo);
        var n = below(items, at, skipFrom, skipTo);
        return {min: n < 0 ? 0 : items[n].depth, max: p < 0 ? 0 : items[p].depth + 1};
    }

    function parentAt(items, at, depth, skipFrom, skipTo) {
        if (depth <= 0) {
            return -1;
        }
        for (var k = above(at, skipFrom, skipTo); k >= 0; k--) {
            if (!inSkip(k, skipFrom, skipTo) && items[k].depth < depth) {
                return items[k].depth === depth - 1 ? k : -1;
            }
        }
        return -1;
    }

    function fits(h, depth, maxLevels) {
        return !maxLevels || depth + h <= maxLevels - 1;
    }

    // Lowers depths so no item sits more than one level below the one before it, nor deeper than
    // maxLevels allow (0 = no limit), as a list must be after a removal.
    function clamp(items, maxLevels) {
        items.forEach(function (item, i) {
            var cap = i === 0 ? 0 : items[i - 1].depth + 1;
            if (maxLevels) {
                cap = Math.min(cap, maxLevels - 1);
            }
            if (item.depth > cap) {
                item.depth = cap;
            }
        });
    }

    function moveBlock(items, from, at, depth) {
        var block = items.splice(from, blockEnd(items, from) - from);
        var by = depth - block[0].depth;
        block.forEach(function (item) { item.depth += by; });
        if (at > from) {
            at = Math.max(from, at - block.length);
        }
        Array.prototype.splice.apply(items, [at, 0].concat(block));
        return at;
    }

    function prevSibling(items, i) {
        for (var k = i - 1; k >= 0 && items[k].depth >= items[i].depth; k--) {
            if (items[k].depth === items[i].depth) {
                return k;
            }
        }
        return -1;
    }

    function keyTarget(items, i, key, maxLevels) {
        var depth = items[i].depth;
        var end = blockEnd(items, i);
        var k;
        if (key === 'up') {
            k = prevSibling(items, i);
            return k < 0 ? {refused: 'first'} : {at: k, depth: depth};
        }
        if (key === 'down') {
            if (end >= items.length || items[end].depth !== depth) {
                return {refused: 'last'};
            }
            return {at: blockEnd(items, end), depth: depth};
        }
        if (key === 'in') {
            if (prevSibling(items, i) < 0) {
                return {refused: 'nothing-above'};
            }
            return fits(height(items, i), depth + 1, maxLevels) ? {at: i, depth: depth + 1} : {refused: 'deep'};
        }
        if (key === 'out') {
            if (depth === 0) {
                return {refused: 'top'};
            }
            return {at: blockEnd(items, parentAt(items, i, depth)), depth: depth - 1};
        }
        return null;
    }

    // index is 1-based, for messages such as "3 of 5".
    function siblingIndex(items, i) {
        var depth = items[i].depth;
        var p = parentAt(items, i, depth);
        var stop = p < 0 ? items.length : blockEnd(items, p);
        var out = {index: 0, count: 0};
        for (var k = p + 1; k < stop; k++) {
            if (items[k].depth === depth) {
                out.count++;
                if (k <= i) {
                    out.index++;
                }
            }
        }
        return out;
    }

    // 0-based place among the new siblings of a block dropped before `at` at `depth`.
    function position(items, at, depth, skipFrom, skipTo) {
        var n = 0;
        for (var k = parentAt(items, at, depth, skipFrom, skipTo) + 1; k < at; k++) {
            if (!inSkip(k, skipFrom, skipTo) && items[k].depth === depth) {
                n++;
            }
        }
        return n;
    }

    return {
        blockEnd: blockEnd, height: height, range: range, parentAt: parentAt, fits: fits, clamp: clamp,
        moveBlock: moveBlock, keyTarget: keyTarget, siblingIndex: siblingIndex, position: position
    };
})();

// osc.sortable — one SortableJS setup for every drag-to-reorder screen. Returns the Sortable
// instance, or null when SortableJS is not on the page or el is missing; a second call on the
// same element returns the first instance. Options are merged over the shared defaults.
// {nested: true} adds the nested-list preset (one sortable per level, shared group).
// {tree: {...}} hands the list to osc.treeDrag (tree-drag.js, Categories and Menus), which
// returns a controller; SortableJS is not used for it.
// SortableJS is read from window at call time, so only screens that enqueue 'sortablejs' load it.
osc.sortable = (function () {
    var instances = new WeakMap();

    function defaults() {
        var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        return {
            delay: 250,
            delayOnTouchOnly: true,
            touchStartThreshold: 5,
            animation: still ? 0 : 150,
            ghostClass: 'is-drag-ghost',
            chosenClass: 'is-drag-chosen',
            dragClass: 'is-drag-active',
            scroll: true,
            scrollSensitivity: 60,
            filter: 'input, select, textarea, [contenteditable]',
            preventOnFilter: false,
            forceFallback: false
        };
    }

    return function (el, options) {
        if (el && options && options.tree) {
            if (!instances.has(el) && osc.treeDrag) {
                instances.set(el, osc.treeDrag(el, options, function () { instances.delete(el); }));
            }
            return instances.get(el) || null;
        }
        if (!el || typeof window.Sortable === 'undefined') { return null; }
        if (instances.has(el)) { return instances.get(el); }
        var opts = Object.assign(defaults(), options || {});
        if (opts.nested) {
            opts = Object.assign({fallbackOnBody: true, swapThreshold: 0.12}, opts);
        }
        delete opts.nested;
        var instance = new window.Sortable(el, opts);
        instances.set(el, instance);
        return instance;
    };
})();

// osc.notify(type, text, {undo, ms}): shows and speaks a result (ok, info, warning or error) and
// returns {close}. With undo (or from osc.toast) it shows at the foot of the screen, otherwise in the stack at the top.
osc.notify = function (type, text, opts) {
    opts = opts || {};
    if (text) {
        osc.announce(oscFlashRegion(type), text);
    }
    if (opts.undo || opts.toast) {
        return oscToast(type, text, opts);
    }
    return oscFlashShow(type, text);
};

// #jsMessage holds the newest message; one still showing moves up the stack as a copy.
function oscFlashShow(type, text) {
    var slot = document.getElementById('jsMessage');
    if (!slot) {
        return {close: function () {}};
    }
    var body = slot.querySelector('p');
    var showing = !slot.classList.contains('hide') && !slot.classList.contains('is-dismissing');
    if (showing && body.textContent !== text) {
        var copy = slot.cloneNode(true);
        copy.removeAttribute('id');
        copy.classList.remove('jsMessage', 'is-stacked');
        slot.parentNode.insertBefore(copy, slot);
    }
    clearTimeout(slot.oscFlashTimer);
    slot.oscFlashTimer = 0;
    body.setAttribute('class', type);
    body.textContent = text;
    ['ok', 'error', 'warning', 'info'].forEach(function (state) {
        slot.classList.toggle('flashmessage-' + state, state === type);
    });
    slot.classList.remove('hide', 'is-dismissing');
    slot.removeAttribute('style');
    oscFlashSync();
    var filled = slot.oscFlashFill = {};
    return {close: function () { if (slot.oscFlashFill === filled) { oscDismissFlash(slot); } }};
}

// The toast: one short note at the foot of the screen with an optional Undo that runs once, and a
// new one replaces the old. It never takes focus; Esc closes it when focus is inside.
var oscToast = (function () {
    var labels = {undo: 'Undo', close: 'Close'};
    var box = null;
    var text = null;
    var undoBtn = null;
    var closeBtn = null;
    var timer = 0;
    var undo = null;
    var ms = OSC_FLASH_MS;
    var current = null;
    var from = null;
    var icon = null;
    var ICONS = {ok: 'bi-check-circle-fill', info: 'bi-info-circle-fill', warning: 'bi-exclamation-triangle-fill', error: 'bi-exclamation-octagon-fill'};

    function still() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function close() {
        clearTimeout(timer);
        undo = null;
        current = null;
        if (!box || box.hidden) {
            return;
        }
        box.classList.add('is-leaving');
        setTimeout(function () {
            if (box.classList.contains('is-leaving')) {
                box.hidden = true;
            }
        }, still() ? 0 : 200);
    }

    function arm() {
        clearTimeout(timer);
        timer = setTimeout(close, ms);
    }

    // Rests above a Save bar it would otherwise cover.
    function place() {
        if (!box || box.hidden) {
            return;
        }
        box.style.insetBlockEnd = '';
        var own = box.getBoundingClientRect();
        var clear = 0;
        document.querySelectorAll('.form-actions').forEach(function (bar) {
            var r = bar.getBoundingClientRect();
            if (r.height > 0 && r.top < own.bottom && r.bottom > own.top && r.left < own.right && r.right > own.left) {
                clear = Math.max(clear, window.innerHeight - r.top);
            }
        });
        if (clear > 0) {
            box.style.insetBlockEnd = (clear + 8) + 'px';
        }
    }

    function build() {
        box = document.createElement('div');
        box.className = 'osc-toast is-leaving';
        box.hidden = true;
        icon = document.createElement('i');
        icon.setAttribute('aria-hidden', 'true');
        text = document.createElement('span');
        text.className = 'osc-toast-text';
        undoBtn = document.createElement('button');
        undoBtn.type = 'button';
        undoBtn.className = 'btn btn-secondary osc-toast-undo';
        closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'osc-toast-close';
        closeBtn.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
        box.append(icon, text, undoBtn, closeBtn);
        document.body.appendChild(box);
        undoBtn.addEventListener('click', function () {
            var run = undo;
            close();
            if (run) {
                run();
            }
        });
        closeBtn.addEventListener('click', close);
        // A toast someone is reading or tabbing through stays until they leave it.
        box.addEventListener('mouseenter', function () { clearTimeout(timer); });
        box.addEventListener('focusin', function (e) {
            clearTimeout(timer);
            if (e.relatedTarget && !box.contains(e.relatedTarget)) {
                from = e.relatedTarget;
            }
        });
        box.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') {
                return;
            }
            e.preventDefault();
            var back = from && from.isConnected ? from : null;
            close();
            if (back) {
                back.focus();
            }
        });
        window.addEventListener('scroll', place, {passive: true});
        window.addEventListener('resize', place);
        box.addEventListener('mouseleave', function () { if (current) { arm(); } });
        box.addEventListener('focusout', function () { if (current) { arm(); } });
    }

    function toast(type, message, opts) {
        if (!box) {
            build();
        }
        var tone = ICONS[type] ? type : 'info';
        box.className = 'osc-toast osc-toast-' + tone + (box.classList.contains('is-leaving') ? ' is-leaving' : '');
        icon.className = 'bi ' + ICONS[tone] + ' osc-toast-icon';
        text.textContent = message;
        undo = typeof opts.undo === 'function' ? opts.undo : null;
        undoBtn.hidden = !undo;
        undoBtn.textContent = labels.undo;
        closeBtn.setAttribute('aria-label', labels.close);
        box.hidden = false;
        void box.offsetWidth;
        box.classList.remove('is-leaving');
        place();
        ms = opts.ms > 0 ? opts.ms : OSC_FLASH_MS;
        arm();
        var handle = {close: function () { if (current === handle) { close(); } }};
        current = handle;
        return handle;
    }

    toast.labels = function (next) {
        Object.assign(labels, next || {});
        if (box) {
            undoBtn.textContent = labels.undo;
            closeBtn.setAttribute('aria-label', labels.close);
        }
    };

    return toast;
})();

// osc.toast(text, {undo, ms}) shows the foot-of-screen note. It routes through osc.notify.
osc.toast = function (message, opts) {
    opts = opts || {};
    return osc.notify(opts.type || 'ok', message, Object.assign({}, opts, {toast: true}));
};
osc.toast.labels = oscToast.labels;

// osc.sidebar — the admin menu: the rail (collapsed) mode with its flyouts, the ghost
// scrollbar, and bringing an opened section into view. Runs once on DOMContentLoaded;
// the collapse button saves the state to the URL in its data-osc-save-url.
osc.sidebar = (function () {
    var root = document.documentElement;
    var desktop = window.matchMedia('(min-width: 1025px)');

    function collapsed() {
        return root.getAttribute('data-osc-sidebar') === 'collapsed' && desktop.matches;
    }

    // A collapsed row needs the label the rail cannot show: a parent reuses its submenu
    // as the flyout, under an added title; a leaf gets a small flyout element. Both are
    // children of the <li>, so the CSS :hover/:focus-within reveal still works.
    function addFlyouts(items) {
        items.forEach(function (item) {
            var anchor = item.querySelector('.nav-link a');
            if (!anchor) { return; }
            var label = anchor.textContent.trim();
            var submenu = item.querySelector('.sidebar-submenu');
            if (submenu) {
                if (!submenu.querySelector('.rail-flyout-title')) {
                    var head = document.createElement('li');
                    head.className = 'rail-flyout-title';
                    head.setAttribute('aria-hidden', 'true');
                    head.textContent = label;
                    submenu.insertBefore(head, submenu.firstChild);
                }
            } else if (!item.querySelector('.sidebar-flyout')) {
                var chip = document.createElement('span');
                chip.className = 'sidebar-flyout';
                chip.setAttribute('aria-hidden', 'true');
                chip.textContent = label;
                item.appendChild(chip);
            }
        });
    }

    // Flyouts leave the scroll frame with position:fixed, so their place is worked out
    // here from the row; the CSS owns when they show.
    function place(item) {
        var link = item.querySelector('.nav-link');
        var fly = item.querySelector('.sidebar-submenu') || item.querySelector('.sidebar-flyout');
        if (!link || !fly) { return; }
        var r = link.getBoundingClientRect();
        fly.style.top = Math.min(r.top, Math.max(8, window.innerHeight - fly.offsetHeight - 8)) + 'px';
        if (getComputedStyle(root).direction === 'rtl') {
            fly.style.right = (window.innerWidth - r.left + 6) + 'px';
            fly.style.left = 'auto';
        } else {
            fly.style.left = (r.right + 6) + 'px';
            fly.style.right = 'auto';
        }
    }

    function initRail(nav, scroller) {
        var items = Array.prototype.slice.call(nav.querySelectorAll('#dashboard-menu > li.nav-item'));
        var open = null, shown = null;
        addFlyouts(items);
        items.forEach(function (item) {
            function enter() {
                if (!collapsed()) { return; }
                // The last flyout goes at once, so two never overlap.
                if (shown && shown !== item) { shown.classList.add('is-flyout-gone'); }
                item.classList.remove('is-flyout-gone');
                open = shown = item;
                place(item);
            }
            function leave() { if (open === item) { open = null; } }
            item.addEventListener('mouseenter', enter);
            item.addEventListener('mouseleave', leave);
            item.addEventListener('focusin', enter);
            item.addEventListener('focusout', leave);
        });
        if (scroller) {
            scroller.addEventListener('scroll', function () { if (open && collapsed()) { place(open); } }, {passive: true});
        }
        window.addEventListener('resize', function () { if (open && collapsed()) { place(open); } });

        var toggle = document.getElementById('oscSidebarToggle');
        if (!toggle) { return; }
        toggle.addEventListener('click', function () {
            var state = root.getAttribute('data-osc-sidebar');
            var next = (state === 'collapsed' || state === 'collapsing') ? 'expanded' : 'collapsed';
            changeRail(nav, next);
            toggle.setAttribute('aria-expanded', next === 'collapsed' ? 'false' : 'true');
            var url = toggle.getAttribute('data-osc-save-url');
            if (url) {
                fetch(url + '&state=' + next, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}});
            }
        });
    }

    // Collapse or expand the rail. For the length of the width change the state is
    // "collapsing" or "expanding": the labels fade, open panels stay in the flow and move
    // their height with the width, and the flyout rules wait for "collapsed".
    var railRun = 0;
    function changeRail(nav, next) {
        var run = ++railRun;
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var panels = Array.prototype.slice.call(nav.querySelectorAll('#dashboard-menu .sidebar-submenu.show'));
        var from = panels.map(function (p) {
            return root.getAttribute('data-osc-sidebar') === 'collapsed' ? 0 : p.getBoundingClientRect().height;
        });
        panels.forEach(function (p) { if (p.oscRail) { p.oscRail.cancel(); p.oscRail = null; } });
        if (reduce || !desktop.matches || !nav.animate) {
            root.setAttribute('data-osc-sidebar', next);
            return;
        }
        root.setAttribute('data-osc-sidebar', next === 'collapsed' ? 'collapsing' : 'expanding');
        var width = nav.getAnimations().filter(function (a) { return a.transitionProperty === 'width'; })[0];
        var css = getComputedStyle(nav);
        var duration = width ? width.effect.getTiming().duration : (parseFloat(css.transitionDuration) || 0) * 1000;
        panels.forEach(function (p, i) {
            var to = next === 'collapsed' ? 0 : p.scrollHeight;
            p.style.overflow = 'hidden';
            p.oscRail = p.animate([{height: from[i] + 'px'}, {height: to + 'px'}], {duration: duration, easing: css.transitionTimingFunction, fill: 'forwards'});
        });
        function done() {
            if (run !== railRun) { return; }
            root.setAttribute('data-osc-sidebar', next);
            panels.forEach(function (p) {
                if (p.oscRail) { p.oscRail.cancel(); p.oscRail = null; }
                p.style.overflow = '';
            });
        }
        if (!width) {
            done();
            return;
        }
        width.finished.then(done, function () {});
    }

    // The native scrollbar is hidden; this thin overlay tracks it, shows on scroll or
    // hover and fades when idle, and its thumb can be dragged.
    function initScrollbar(frame, scroller) {
        var bar = document.createElement('div');
        bar.className = 'ghost-scrollbar';
        var thumb = document.createElement('div');
        thumb.className = 'ghost-scrollbar-thumb';
        bar.appendChild(thumb);
        frame.appendChild(bar);

        var hideTimer, dragging = false, startY = 0, startScroll = 0;
        function measure() {
            var ch = scroller.clientHeight;
            var sh = scroller.scrollHeight;
            if (sh - ch <= 1) {
                bar.classList.remove('is-active');
                bar.style.display = 'none';
                return null;
            }
            bar.style.display = '';
            var thumbH = Math.max(28, Math.round(ch * ch / sh));
            var maxThumb = ch - thumbH;
            thumb.style.height = thumbH + 'px';
            thumb.style.transform = 'translateY(' + (maxThumb > 0 ? Math.round(scroller.scrollTop / (sh - ch) * maxThumb) : 0) + 'px)';
            return {ch: ch, sh: sh, maxThumb: maxThumb};
        }
        function flash() {
            if (!measure()) { return; }
            bar.classList.add('is-active');
            clearTimeout(hideTimer);
            hideTimer = setTimeout(function () {
                if (!dragging && !bar.matches(':hover')) { bar.classList.remove('is-active'); }
            }, 1000);
        }
        scroller.addEventListener('scroll', flash, {passive: true});
        frame.addEventListener('mouseenter', flash);
        window.addEventListener('resize', measure);
        thumb.addEventListener('mousedown', function (e) {
            if (!measure()) { return; }
            dragging = true;
            startY = e.clientY;
            startScroll = scroller.scrollTop;
            document.body.style.userSelect = 'none';
            e.preventDefault();
        });
        window.addEventListener('mousemove', function (e) {
            if (!dragging) { return; }
            var m = measure();
            if (m && m.maxThumb > 0) { scroller.scrollTop = startScroll + (e.clientY - startY) / m.maxThumb * (m.sh - m.ch); }
        });
        window.addEventListener('mouseup', function () {
            if (dragging) { dragging = false; document.body.style.userSelect = ''; flash(); }
        });
        bar.addEventListener('mouseenter', function () { clearTimeout(hideTimer); if (measure()) { bar.classList.add('is-active'); } });
        bar.addEventListener('mouseleave', flash);
        measure();
        return measure;
    }

    // Scroll the menu so el is in view; a tall el keeps its top edge in view.
    function scrollIntoMenu(scroller, el) {
        var gap = 8;
        var view = scroller.getBoundingClientRect();
        var box = el.getBoundingClientRect();
        if (box.bottom + gap > view.bottom) {
            scroller.scrollTop += Math.ceil(Math.min(box.bottom + gap - view.bottom, box.top - view.top));
        } else if (box.top - gap < view.top) {
            scroller.scrollTop -= Math.ceil(view.top - box.top + gap);
        }
    }

    // An opened section can end below the fold; the menu follows it as it grows, frame by
    // frame, so the scroll never jumps at the end.
    function revealOnOpen(scroller, measure) {
        var follow = null;
        scroller.addEventListener('osc:collapse:show', function (e) {
            var section = e.target.closest('.nav-item');
            if (!section || collapsed() || e.defaultPrevented) { return; }
            follow = section;
            (function step() {
                if (follow !== section) { return; }
                scrollIntoMenu(scroller, section);
                requestAnimationFrame(step);
            })();
        });
        scroller.addEventListener('osc:collapse:hide', function (e) {
            if (follow && follow.contains(e.target)) { follow = null; }
        });
        scroller.addEventListener('osc:collapse:shown', function (e) {
            var section = e.target.closest('.nav-item');
            if (section && follow === section) {
                follow = null;
                if (!collapsed()) { scrollIntoMenu(scroller, section); }
            }
            measure();
        });
    }

    // A plain click on a menu link marks it current at once; the next page confirms it.
    function initGoing(nav) {
        nav.addEventListener('click', function (e) {
            var link = e.target.closest('#dashboard-menu a[href]');
            if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey
                || link.target === '_blank' || link.getAttribute('href').charAt(0) === '#') {
                return;
            }
            nav.querySelectorAll('.is-going').forEach(function (el) { el.classList.remove('is-going'); });
            (link.closest('.sidebar-submenu') ? link : link.parentNode).classList.add('is-going');
        });
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) {
                nav.querySelectorAll('.is-going').forEach(function (el) { el.classList.remove('is-going'); });
            }
        });
    }

    // At 1024px and below the menu is an off-canvas panel opened from the top bar.
    function initMobile(nav) {
        var toggler = document.querySelector('#header .navbar-toggler');
        function mark(isOpen) {
            if (!toggler) { return; }
            toggler.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            var label = toggler.getAttribute(isOpen ? 'data-label-close' : 'data-label-open');
            if (label) { toggler.setAttribute('aria-label', label); }
        }
        function apply() {
            var small = !desktop.matches;
            if (!small && osc.offcanvas.isOpen(nav)) { osc.offcanvas.hide(nav); }
            nav.classList.toggle('offcanvas', small);
            nav.classList.toggle('offcanvas-start', small);
        }
        nav.addEventListener('osc:offcanvas:show', function (e) { if (!e.defaultPrevented) { mark(true); } });
        nav.addEventListener('osc:offcanvas:hide', function (e) { if (!e.defaultPrevented) { mark(false); } });
        desktop.addEventListener('change', apply);
        apply();
    }

    function init() {
        var nav = document.getElementById('sidebar-wrapper');
        if (!nav || nav.dataset.oscSidebarInit) { return; }
        nav.dataset.oscSidebarInit = '1';
        initMobile(nav);
        var frame = nav.querySelector('.sidebar-scroll-frame');
        var scroller = frame ? frame.querySelector('.sidebar-scroll') : null;
        initRail(nav, scroller);
        initGoing(nav);
        if (frame && scroller) {
            var current = nav.querySelector('.sidebar-submenu .sub-active');
            if (current && !collapsed()) { scrollIntoMenu(scroller, current); }
            revealOnOpen(scroller, initScrollbar(frame, scroller));
        }
    }

    document.addEventListener('DOMContentLoaded', init);

    return {init: init};
})();

// A tab strip that scrolls on a phone opens with its current tab in view. "nearest" moves the
// strip only as far as needed, in either reading direction, and leaves a strip that fits alone.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.osc-tabnav, #content-render .nav-tabs').forEach(function (strip) {
        var current = strip.querySelector('.is-active, .active, [aria-current="page"]');
        if (current) {
            current.scrollIntoView({block: 'nearest', inline: 'nearest'});
        }
    });
});

// An image field's Choose image button shows the name of the file picked; ticking Remove clears it.
document.addEventListener('change', function (e) {
    var field = e.target.closest ? e.target.closest('.field-image') : null;
    var name = field ? field.querySelector('.field-image-name') : null;
    if (!name) {
        return;
    }
    if (e.target.classList.contains('field-image-input')) {
        name.textContent = e.target.files && e.target.files.length ? e.target.files[0].name : '';
    } else if (e.target.type === 'checkbox' && e.target.checked) {
        name.textContent = '';
    }
});
