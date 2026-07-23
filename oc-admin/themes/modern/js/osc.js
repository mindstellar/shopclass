/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

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
// this flow; others (e.g. ban rules) own their own confirm dialog, so this must
// not touch their form or assume the dialog exists.
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

// Row actions live in-flow beneath each listing title and are always visible: they are quick
// actions, so a keyboard or touch user must reach them in one click, not perform a hover the
// pointer alone can do. (The old code revealed them on mouseover only — a WCAG 2.1.1 failure —
// and the stylesheet reserved 2.5rem of dead space under every row so the reveal wouldn't reflow
// the table. Both are gone.) This enhancer only (a) tags the one destructive link so the
// stylesheet can hold it apart from the routine ones, and (b) drives the "More" overflow list as
// an accessible click-to-open disclosure.
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
