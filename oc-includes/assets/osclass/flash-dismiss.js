/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/* Makes the dismiss button on a front-end flash message work: keyboard-operable, click removes the message. */
(function () {
    function dismiss(btn) {
        var box = btn.closest('.flashmessage');
        if (box && box.parentNode) { box.parentNode.removeChild(box); }
    }
    function bind() {
        document.querySelectorAll('.flashmessage a.ico-close:not([data-osc-bound])').forEach(function (btn) {
            btn.setAttribute('data-osc-bound', '1');
            btn.setAttribute('role', 'button');
            btn.setAttribute('tabindex', '0');
            var label = btn.getAttribute('data-oc-close-label');
            if (label) { btn.setAttribute('aria-label', label); }
            btn.addEventListener('click', function (e) { e.preventDefault(); dismiss(btn); });
            btn.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dismiss(btn); }
            });
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
