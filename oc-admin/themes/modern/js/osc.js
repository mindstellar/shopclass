/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/* global osc, bootstrap */
/* exported oscEscapeHTML, setJsMessage, bulkActionsSubmit */

/* ===================================================
 * osc tooltip
 * ===================================================
 * Usage:
 * Display a custom tooltip on mouse over.
 * oscTooltip(element | NodeList, message, {options});
 *
 * options = {
 *     layout: ['gray-tooltip', 'black-tooltip','info-tooltip','warning-tooltip','success-tooltip','error-tooltip'],
 *     position: {
 *         x: ['left',right,'middle'],
 *         y: ['top','bottom','middle']
 *     }
 * }
 **/
/*jshint browser: true*/
// Custom hover tooltip (vanilla). osc.tooltip(el, message, options) attaches the
// tooltip to a single element; oscTooltip(target, ...) accepts an element or a
// NodeList/array. Replaces the former jQuery $.fn.osc_tooltip plugin.
osc.tooltip = function (element, message, options) {
    if (!element) {
        return;
    }
    options = options || {};
    var pos = options.position || { y: 'middle', x: 'right' };
    var layout = options.layout || 'black-tooltip';

    var tip = document.getElementById('osc-tooltip');
    if (!tip) {
        tip = document.createElement('div');
        tip.id = 'osc-tooltip';
        document.body.appendChild(tip);
    }

    var hovered = false;
    element.addEventListener('mouseenter', function () {
        hovered = true;
        var r = element.getBoundingClientRect();
        var offTop = r.top + window.pageYOffset;
        var offLeft = r.left + window.pageXOffset;

        var msg = document.createElement('div');
        msg.className = 'tooltip-message';
        msg.textContent = message;
        tip.innerHTML = '';
        tip.appendChild(msg);
        tip.className = layout + ' ' + pos.x + '-' + pos.y;
        var arrow = document.createElement('div');
        arrow.className = 'tooltip-arrow';
        tip.appendChild(arrow);
        tip.style.display = 'block';

        var top = offTop;
        switch (pos.y) {
            case 'top': top = offTop - tip.offsetHeight; break;
            case 'middle': top = offTop - (tip.offsetHeight / 2) + (element.offsetHeight / 2); break;
            case 'bottom': top = offTop + element.offsetHeight; break;
        }
        var left = offLeft;
        switch (pos.x) {
            case 'left': left = offLeft - tip.offsetWidth; break;
            case 'middle': left = offLeft - (tip.offsetWidth / 2) + (element.offsetWidth / 2); break;
            case 'right': left = offLeft + r.width; break;
        }
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
    });
    element.addEventListener('mouseleave', function () {
        hovered = false;
        setTimeout(function () {
            if (!hovered) { tip.style.display = 'none'; }
        }, 100);
    });
};

// Attach the tooltip to a single element or a NodeList/array of them.
window.oscTooltip = function (target, message, options) {
    if (!target) {
        return;
    }
    if (typeof target.forEach === 'function') {
        target.forEach(function (el) { osc.tooltip(el, message, options); });
    } else {
        osc.tooltip(target, message, options);
    }
};


var OSC_ESC_MAP = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
};

function oscEscapeHTML(str) {
    if (str !== undefined) {
        return str.toString().replace(/[&<>'"]/g, function (c) {
            return OSC_ESC_MAP[c];
        });
    }
    return "";
}
// display flash message
function setJsMessage(alertClass, alertMessage) {
    var jsMessage = document.getElementById("jsMessage");
    var pTag = jsMessage.querySelector("p");
    pTag.setAttribute("class", alertClass);
    pTag.textContent = alertMessage;
    ['ok', 'error', 'warning', 'info'].forEach(function (state) {
        jsMessage.classList.toggle('flashmessage-' + state, state === alertClass);
    });
    jsMessage.classList.remove('hide');
    jsMessage.removeAttribute('style');
}
// Open the bulk-actions confirm dialog for the selected action (native <dialog>).
function toggleBulkActionsModal() {
    var bulkSelect = document.getElementById("bulk_actions");
    var modal = document.getElementById("bulkActionsModal");
    if (!bulkSelect || !modal || typeof modal.showModal !== 'function') {
        return false;
    }
    var opt = bulkSelect.options[bulkSelect.selectedIndex];
    if (opt.value !== '') {
        // Content target works for a native .osc-dialog or a legacy .modal.
        var body = modal.querySelector('.osc-dialog-text, .modal-body p');
        if (body) { body.textContent = opt.getAttribute("data-dialog-content") || ''; }
        var submit = document.getElementById('bulkActionsSubmit');
        if (submit) { submit.textContent = opt.text; }
        if (typeof modal.showModal === 'function') {
            modal.showModal();
        } else if (window.bootstrap) {
            (new bootstrap.Modal(modal)).show();
        }
    }
    return false;
}
// Submit bulk actions
function bulkActionsSubmit() {
    document.getElementById("datatablesForm").submit();
}
// Set up the bulkActions dialog. Only pages that render #bulkActionsModal use
// this flow, so this must not touch a form on a page without one.
window.addEventListener('load', function () {
    var datatablesForm = document.getElementById("datatablesForm");
    var bulkActionsModal = document.getElementById("bulkActionsModal");
    if (datatablesForm && bulkActionsModal) {
        datatablesForm.addEventListener('submit', function (e) {
            e.preventDefault();
            toggleBulkActionsModal();
        });
    }
});

// Row actions live in-flow beneath each listing title and are always visible: a keyboard or
// touch user must reach them in one click, and revealing them on hover is a WCAG 2.1.1
// failure. This enhancer only (a) tags the one destructive link so the stylesheet can hold it
// apart from the routine ones, and (b) drives the "More" overflow list as an accessible
// click-to-open disclosure.
window.addEventListener('load', function () {
    var actionsDivs = document.querySelectorAll('#datatablesForm .actions');
    actionsDivs.forEach(function (actions) {
        var del = actions.querySelector('a[onclick*="delete_dialog"], a[href*="action=delete"]');
        if (del) {
            del.classList.add('row-action-danger');
        }

        var trigger = actions.querySelector('.show-more-trigger');
        if (!trigger) {
            return;
        }
        var more = trigger.closest('.show-more');
        trigger.setAttribute('role', 'button');
        trigger.setAttribute('aria-expanded', 'false');

        function close() {
            more.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
        }

        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var open = more.classList.toggle('is-open');
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (event) {
            if (!more.contains(event.target)) {
                close();
            }
        });
        more.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
                trigger.focus();
            }
        });
    });
});
// TinyMCE draws its toolbar from a UI skin and its editing surface from a separate
// content skin inside an iframe. Neither inherits the admin's dark mode, so an
// editor sat as a bright white panel in a dark admin. Pick the matching pair at
// init time; the theme is read from the same data-bs-theme the rest of the admin
// uses. (Switching theme after an editor is up needs a re-init, so it follows on
// the next page load rather than live.)
window.oscTinymceTheme = function () {
    var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';

    return { skin: dark ? 'oxide-dark' : 'oxide', content_css: dark ? 'dark' : 'default' };
};

// Select-all for a list's bulk-action column. Every list screen shipped its own
// copy of this listener; it is one behaviour, so it lives once. Delegated from the
// document so it also covers a table whose rows arrive after load.
document.addEventListener('change', function (event) {
    var checkAll = event.target;
    if (!checkAll || checkAll.id !== 'check_all') {
        return;
    }
    var scope = checkAll.closest('table') || document;
    scope.querySelectorAll('.col-bulkactions input[type=checkbox]').forEach(function (cb) {
        if (cb !== checkAll) {
            cb.checked = checkAll.checked;
        }
    });
});

// A control that invalidates the ones below it -- picking a country makes the region and
// city chosen under the old one meaningless. Declared on the control, so any screen can
// use it without its own script.
document.addEventListener('change', function (event) {
    var source = event.target;
    if (!source || !source.hasAttribute || !source.hasAttribute('data-osc-clears')) {
        return;
    }
    source.getAttribute('data-osc-clears').split(',').forEach(function (selector) {
        selector = selector.trim();
        if (!selector) {
            return;
        }
        var field = document.querySelector(selector);
        if (field) {
            field.value = '';
        }
    });
});

// A list filter whose select chooses which of its own inputs is in play. Delegated, so
// every screen gets it from the component rather than shipping its own inline script.
document.addEventListener('change', function (event) {
    var picker = event.target;
    if (!picker || !picker.hasAttribute || !picker.hasAttribute('data-osc-filter-switch')) {
        return;
    }
    var form = picker.form || picker.closest('form');
    if (!form) {
        return;
    }
    var shown = null;
    form.querySelectorAll('[data-osc-filter-for]').forEach(function (input) {
        var on = input.getAttribute('data-osc-filter-for') === picker.value;
        input.classList.toggle('hide', !on);
        if (on) {
            shown = input;
        }
    });
    if (shown) {
        shown.focus();
    }
});

// A package icon or screenshot that cannot load (a blocked CDN, an offline install) hands
// its box back to the tinted initial underneath instead of leaving an empty frame.
function oscThumbFailed(img) {
    var box = img.closest ? img.closest('.osc-thumb') : null;
    if (box) {
        box.classList.add('osc-thumb--fallback');
    }
    img.remove();
}

// Art hosted somewhere the site cannot reach does not error, it hangs. Give each one a
// deadline and take the box back when it passes.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.osc-thumb img').forEach(function (img) {
        if (img.complete && img.naturalWidth > 0) {
            return;
        }
        var deadline = window.setTimeout(function () {
            if (!img.complete || img.naturalWidth === 0) {
                oscThumbFailed(img);
            }
        }, 8000);
        img.addEventListener('load', function () {
            window.clearTimeout(deadline);
        });
    });
});

/* ===================================================
 * osc drawer
 * ===================================================
 * The slide-over panel the Categories and Locations screens edit in. Both wrote the same
 * open/close dance -- unhide, force a reflow so the slide starts from the closed position,
 * toggle a class, then wait for transitionend with a timeout because a reduced-motion
 * transition never fires one -- and both trapped Tab inside it.
 *
 * oscDrawer({
 *     drawer:      the panel element                              (required)
 *     backdrop:    the element behind it                          (required)
 *     openOn:      element the open class goes on (default: both drawer and backdrop)
 *     openClass:   default 'is-open'
 *     focusFirst:  fn(drawer) -> what to focus once it is open
 *     canClose:    fn() -> false to refuse a backdrop or Escape close, e.g. while a
 *                  <dialog> sits over the panel and owns Escape itself
 *     beforeClose: fn() -> run before the panel starts closing; abort requests, tidy state
 *     afterClose:  fn() -> run once it is hidden and emptied
 *     empty:       false to keep the panel's markup on close
 * })
 *
 * Returns { open(opener), close(restoreFocus), isOpen(), element }.
 */
window.oscDrawer = function (options) {
    var drawer = options.drawer;
    var backdrop = options.backdrop;
    var openClass = options.openClass || 'is-open';
    var targets = options.openOn ? [options.openOn] : [drawer, backdrop];
    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), '
        + 'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    var opener = null;

    function isOpen() {
        return !!drawer && targets.some(function (t) {
            return t && t.classList.contains(openClass);
        });
    }

    function open(from) {
        // An explicit null means this panel has nothing to give focus back to; only an
        // absent argument falls back to whatever had focus when it opened.
        opener = from === undefined ? document.activeElement : (from || null);
        drawer.hidden = false;
        backdrop.hidden = false;
        // Reflow, so the slide runs from the closed position rather than jumping.
        void drawer.offsetWidth;
        targets.forEach(function (t) {
            if (t) { t.classList.add(openClass); }
        });
        if (typeof options.focusFirst === 'function') {
            options.focusFirst(drawer);
        }
    }

    function close(restoreFocus) {
        if (!isOpen()) {
            return;
        }
        if (typeof options.beforeClose === 'function') {
            options.beforeClose();
        }
        targets.forEach(function (t) {
            if (t) { t.classList.remove(openClass); }
        });

        var settled = false;
        var done = function () {
            if (settled || isOpen()) {
                return;
            }
            settled = true;
            drawer.hidden = true;
            backdrop.hidden = true;
            if (options.empty !== false) {
                drawer.replaceChildren();
            }
            if (typeof options.afterClose === 'function') {
                options.afterClose();
            }
        };
        // A reduced-motion transition never fires transitionend, so the timeout is the
        // one that actually lands on those machines -- not a safety net.
        drawer.addEventListener('transitionend', done, { once: true });
        window.setTimeout(done, 320);

        if (restoreFocus !== false && opener && opener.isConnected) {
            opener.focus();
        }
        opener = null;
    }

    function mayClose() {
        return typeof options.canClose !== 'function' || options.canClose() !== false;
    }

    backdrop.addEventListener('click', function () {
        if (mayClose()) {
            close();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (!isOpen()) {
            return;
        }
        if (event.key === 'Escape') {
            if (mayClose()) {
                close();
            }

            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        // A hidden control still matches the selector, so filter to what is on screen --
        // otherwise Tab can land somewhere nobody can see.
        var items = Array.prototype.filter.call(drawer.querySelectorAll(FOCUSABLE), function (node) {
            return node.getClientRects().length > 0 && node.getAttribute('tabindex') !== '-1';
        });
        if (items.length === 0) {
            event.preventDefault();

            return;
        }
        var first = items[0];
        var last = items[items.length - 1];
        if (event.shiftKey && (document.activeElement === first || document.activeElement === drawer)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    return {
        open: open,
        close: close,
        isOpen: isOpen,
        element: drawer
    };
};
