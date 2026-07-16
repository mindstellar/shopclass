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
