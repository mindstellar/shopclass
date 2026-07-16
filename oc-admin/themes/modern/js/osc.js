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
 * $(selector).tooltip(message, {options});
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
osc.tooltip = function (message, options) {
    defaults = {
        position: {
            y: 'middle',
            x: 'right'
        },
        layout: 'black-tooltip'
    }
    var opts = $.extend({}, defaults, options);

    // check if exists tooltip
    var $tooltip = $('#osc-tooltip');
    if ($tooltip.length === 0) {
        $tooltip = $('<div id="osc-tooltip"></div>');
        $('body').append($tooltip);
    }

    //Add the message
    var hovered;
    $(this).hover(function () {
        hovered = true;
        var offset = $(this).offset();
        var tooltipContainer = $('<div class="tooltip-message"></div>');
        tooltipContainer.append(message);
        $tooltip.html(tooltipContainer).attr('class', opts.layout + ' ' + opts.position.x + '-' + opts.position.y).append('<div class="tooltip-arrow"></div>').show();
        switch (opts.position.y) {
            case 'top':
                positionTop = offset.top - ($tooltip.outerHeight());
                break
            case 'middle':
                positionTop = offset.top - ($tooltip.outerHeight() / 2) + ($(this).outerHeight() / 2);
                break
            case 'bottom':
                positionTop = offset.top + $(this).outerHeight();
                break
        }
        switch (opts.position.x) {
            case 'left':
                positionLeft = offset.left - $tooltip.outerWidth();
                break
            case 'middle':
                positionLeft = offset.left - ($tooltip.outerWidth() / 2) + ($(this).outerWidth() / 2);
                break
            case 'right':
                positionLeft = offset.left + $(this).width();
                break
        }
        $tooltip.css({
            left: positionLeft,
            top: positionTop
        });

    }, function () {
        hovered = false;
        setTimeout(function () {
            if (!hovered) {
                $tooltip.hide();
            }
        }, 100);
    });
};

//extend
$.fn.osc_tooltip = osc.tooltip;


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