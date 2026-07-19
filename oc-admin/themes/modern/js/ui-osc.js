/*
 * Admin UI helpers — vanilla JS (migrated off jQuery / jQuery-UI).
 *
 * Provides oscInitTabs() (the segmented locale/section tabs, replacing
 * jQuery-UI .tabs()) and wires the flash-message and help-box close buttons.
 */

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
}

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
// nearest table or form so a range never leaks across regions. Pure vanilla,
// replacing the jQuery shift-select this admin used to rely on.
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
