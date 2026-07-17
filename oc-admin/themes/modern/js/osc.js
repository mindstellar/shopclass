/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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
// Toggle the bulkActionsModal
function toggleBulkActionsModal() {
    var bulkSelect = document.getElementById("bulk_actions");
    var bulkActionsModal = new bootstrap.Modal(document.getElementById("bulkActionsModal"));
    if (bulkSelect.options[bulkSelect.selectedIndex].value !== '') {
        bulkActionsModal.toggle();
    }
    event.preventDefault();
    return false;
}
// Submit bulk actions
function bulkActionsSubmit() {
    document.getElementById("datatablesForm").submit();
}
// Set up the bulkActions modal. Only pages that render #bulkActionsModal use this
// Bootstrap-modal flow; others (e.g. ban rules) own their own confirm dialog, so
// this must not touch their form or assume the modal exists.
window.addEventListener('load', function () {
    var datatablesForm = document.getElementById("datatablesForm");
    var bulkActionsModal = document.getElementById("bulkActionsModal");
    if (datatablesForm && bulkActionsModal) {
        datatablesForm.onsubmit = function () {
            toggleBulkActionsModal();
        };
        bulkActionsModal.addEventListener("show.bs.modal", function () {
            var bulkSelect = document.getElementById("bulk_actions");
            bulkActionsModal.querySelector('.modal-body p').textContent = bulkSelect.options[bulkSelect.selectedIndex]
                .getAttribute("data-dialog-content");
            bulkActionsModal.querySelector('#bulkActionsSubmit').textContent = bulkSelect.options[bulkSelect.selectedIndex].text;
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