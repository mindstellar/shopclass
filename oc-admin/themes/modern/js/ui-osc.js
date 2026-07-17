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

// Vanilla ARIA combobox — replaces jQuery-UI .autocomplete(). Attaches to
// `input`, debounce-fetches JSON [{id,label,value}] from opts.source (adds a
// `term` param), shows a listbox, and drives it with the standard combobox
// keyboard model. opts: { source, minLength=1, onSearch(input), onSelect(item) }.
// onSelect returning false suppresses writing item.value into the input.
var oscAcSeq = 0;
function oscAutocomplete(input, opts) {
    if (!input || input.getAttribute('data-osc-ac-init')) {
        return;
    }
    input.setAttribute('data-osc-ac-init', '1');
    opts = opts || {};
    var minLength = opts.minLength != null ? opts.minLength : 1;
    var uid = 'osc-ac-' + (++oscAcSeq);

    input.setAttribute('autocomplete', 'off');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', uid);

    var list = document.createElement('ul');
    list.className = 'osc-ac-list';
    list.id = uid;
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    document.body.appendChild(list);

    var items = [];
    var active = -1;
    var timer = null;

    function place() {
        var r = input.getBoundingClientRect();
        list.style.left = r.left + 'px';
        list.style.top = (r.bottom + 2) + 'px';
        list.style.width = r.width + 'px';
    }

    function close() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    }

    function open() {
        if (!items.length) {
            close();
            return;
        }
        place();
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    function render(data) {
        items = data || [];
        list.textContent = '';
        active = -1;
        items.forEach(function (item, i) {
            var li = document.createElement('li');
            li.className = 'osc-ac-option';
            li.id = uid + '-' + i;
            li.setAttribute('role', 'option');
            li.textContent = item.label != null ? item.label : item.value;
            li.addEventListener('mousedown', function (e) {
                e.preventDefault(); // keep focus so blur doesn't close before select
                choose(i);
            });
            list.appendChild(li);
        });
        open();
    }

    function highlight(i) {
        var opts2 = list.children;
        for (var k = 0; k < opts2.length; k++) {
            opts2[k].classList.toggle('is-active', k === i);
        }
        active = i;
        if (i >= 0 && opts2[i]) {
            input.setAttribute('aria-activedescendant', opts2[i].id);
            opts2[i].scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function choose(i) {
        var item = items[i];
        if (!item) {
            return;
        }
        var keep = opts.onSelect ? opts.onSelect(item) : undefined;
        if (keep !== false) {
            input.value = item.value != null ? item.value : (item.label || '');
        }
        close();
    }

    function fetchList() {
        var term = input.value;
        if (term.length < minLength) {
            close();
            return;
        }
        // source may be a string, or a function resolved at fetch time (so a
        // dependent field — e.g. region needs the current country — is current).
        var base = typeof opts.source === 'function' ? opts.source() : opts.source;
        var url = base + (base.indexOf('?') > -1 ? '&' : '?') + 'term=' + encodeURIComponent(term);
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) { render(Array.isArray(data) ? data : []); })
            .catch(function () { close(); });
    }

    input.addEventListener('input', function () {
        if (opts.onSearch) { opts.onSearch(input); }
        clearTimeout(timer);
        timer = setTimeout(fetchList, 180);
    });
    input.addEventListener('focus', function () {
        if (minLength === 0 && list.hidden) {
            clearTimeout(timer);
            timer = setTimeout(fetchList, 60);
        }
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (list.hidden) { fetchList(); } else { highlight(Math.min(active + 1, items.length - 1)); }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlight(Math.max(active - 1, 0));
        } else if (e.key === 'Enter') {
            if (!list.hidden && active >= 0) { e.preventDefault(); choose(active); }
        } else if (e.key === 'Escape') {
            close();
        }
    });
    input.addEventListener('blur', function () {
        setTimeout(close, 120);
    });
    window.addEventListener('scroll', function () { if (!list.hidden) { place(); } }, true);
    window.addEventListener('resize', function () { if (!list.hidden) { place(); } });
}

document.addEventListener('DOMContentLoaded', function () {
    // Close a flash message when its × is clicked. (The help box now closes via
    // Bootstrap's own collapse toggle, so it needs no handler here.)
    document.querySelectorAll('.flashmessage .ico-close').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var fm = btn.closest('.flashmessage');
            if (fm) { fm.style.display = 'none'; }
        });
    });

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
