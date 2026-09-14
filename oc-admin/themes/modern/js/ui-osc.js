/*
 * Admin UI helpers — vanilla JS (migrated off jQuery / jQuery-UI).
 *
 * Provides oscInitTabs() (the segmented locale/section tabs, replacing
 * jQuery-UI .tabs()) and wires the flash-message and help-box close buttons.
 */
/* global osc */
/* exported oscTab, tabberAutomatic, oscValidateForm, oscTreeview, checkCat */

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

            select(serverActive || linkArr[0], false);
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
            if (startCollapsed) { li.classList.add('is-collapsed'); }
            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'osc-tree-toggle';
            toggle.innerHTML = '<i class="bi bi-chevron-down" aria-hidden="true"></i>';
            toggle.setAttribute('aria-expanded', startCollapsed ? 'false' : 'true');
            toggle.setAttribute('aria-label', opts.toggleLabel || 'Toggle');
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
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) { fm.remove(); return; }
    var done = false;
    var finish = function () { if (!done) { done = true; fm.remove(); } };
    fm.classList.add('is-dismissing');
    fm.addEventListener('animationend', finish, { once: true });
    setTimeout(finish, 400); // fallback if animationend never fires (e.g. hidden tab)
}

function oscEnhanceFlash(root) {
    (root || document).querySelectorAll('.flashmessage').forEach(function (fm) {
        if (fm.dataset.oscFlash) { return; }
        fm.dataset.oscFlash = '1';
        var isError = fm.classList.contains('flashmessage-error');
        // An error interrupts; anything else is a passive confirmation.
        if (!fm.hasAttribute('role')) { fm.setAttribute('role', isError ? 'alert' : 'status'); }
        if (!fm.hasAttribute('aria-live')) { fm.setAttribute('aria-live', isError ? 'assertive' : 'polite'); }
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
document.addEventListener('click', function (e) {
    var close = e.target.closest ? e.target.closest('.flashmessage .ico-close') : null;
    if (close) { e.preventDefault(); oscDismissFlash(close.closest('.flashmessage')); }
});
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') { return; }
    var close = e.target.closest ? e.target.closest('.flashmessage .ico-close') : null;
    if (close) { e.preventDefault(); oscDismissFlash(close.closest('.flashmessage')); }
});

document.addEventListener('DOMContentLoaded', function () {
    // Flash messages: announce them to assistive tech and wire up dismissal. (The
    // help box closes via Bootstrap's own collapse toggle, so no handler here.)
    oscEnhanceFlash(document);

    oscInitTabs(document);
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
    if (e.target.matches && e.target.matches('dialog.osc-dialog') && typeof e.target.close === 'function') {
        e.target.close();
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
    for (var i = 0; i < controls.length; i++) {
        if (controls[i].getAttribute('data-osc-was') !== oscControlValue(controls[i])) { changed++; }
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
