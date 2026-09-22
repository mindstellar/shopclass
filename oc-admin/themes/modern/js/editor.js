/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The editing screens' shared behaviour: mounting every rich-text field, keeping the
 * locale tab strips of one form on the same locale, and opening whatever hides a
 * rejected field so its message can be read.
 */
(function () {
    'use strict';

    // Every editor this module mounted, so the theme toggle can re-mount them.
    var mounted = [];

    // Mount one editor per <textarea data-osc-richtext>. The configuration is the
    // server's, so no screen writes an editor setup of its own and none of them has to
    // find its editors by a name pattern.
    function mountRichtext() {
        var fields = document.querySelectorAll('textarea[data-osc-richtext]');
        if (!fields.length || typeof tinymce === 'undefined') {
            return;
        }

        fields.forEach(function (field) {
            var cfg;
            try {
                cfg = JSON.parse(field.getAttribute('data-osc-richtext') || '{}');
            } catch (e) {
                return;
            }

            // Two keys the browser has to turn into callbacks: JSON cannot carry a
            // function, so the server sends the signed endpoint and the intent.
            var media = cfg.osc_media === true;
            var uploadUrl = cfg.osc_upload_url || '';
            delete cfg.osc_media;
            delete cfg.osc_upload_url;

            cfg.target = field;
            delete cfg.selector;

            if (media && uploadUrl) {
                cfg.automatic_uploads = true;
                cfg.images_upload_credentials = true;
                cfg.images_upload_url = uploadUrl;
                cfg.file_picker_types = 'image';
                cfg.file_picker_callback = function (cb, value, meta) {
                    if (meta.filetype !== 'image' || !window.oscMediaPicker) {
                        return;
                    }
                    window.oscMediaPicker.open(function (url) {
                        cb(url, { title: '' });
                    });
                };
            }

            if (window.oscTinymceTheme) {
                Object.assign(cfg, window.oscTinymceTheme());
            }
            mounted.push(cfg);
            tinymce.init(cfg);
        });
    }

    // The theme toggle rewrites data-bs-theme in place and never reloads, but an editor's
    // skin and content stylesheet are chosen once at init -- so a light admin kept a dark
    // editor until the next page load. Re-mounting is the only way to change them; remove()
    // writes the content back to the textarea and init() reads it again, so nothing is lost.
    function followTheme() {
        if (typeof tinymce === 'undefined' || !window.oscTinymceTheme) {
            return;
        }
        mounted.forEach(function (cfg) {
            var editor = cfg.target && tinymce.get(cfg.target.id);
            if (!editor) {
                return;
            }
            editor.remove();
            Object.assign(cfg, window.oscTinymceTheme());
            tinymce.init(cfg);
        });
    }

    // Every translated field in one form shows the same locale. Each is its own widget, and
    // a field drawn without a strip of its own has no other way to be switched — so a click
    // on any strip moves every field to the locale in the same position. Without it a title
    // in one language can sit above a body in another, and nothing on screen says so.
    function syncLocaleTabs(clicked) {
        var strip = clicked.closest('.field-translate .osc-tab');
        var field = clicked.closest('.field-translate');
        var form = clicked.closest('form');
        if (!strip || !form) {
            return;
        }
        var links = Array.prototype.slice.call(strip.querySelectorAll(':scope > ul > li > a'));
        var index = links.indexOf(clicked);
        if (index < 0) {
            return;
        }

        form.querySelectorAll('.field-translate').forEach(function (other) {
            if (other === field) {
                return;
            }
            var panels = other.querySelectorAll(':scope > .field-translate-panel');
            if (panels.length !== links.length) {
                return;
            }
            panels.forEach(function (panel, i) {
                panel.hidden = i !== index;
            });
            other.querySelectorAll(':scope > .osc-tab > ul > li > a').forEach(function (link, i) {
                var on = i === index;
                link.parentNode.classList.toggle('is-active', on);
                link.setAttribute('aria-selected', on ? 'true' : 'false');
                link.tabIndex = on ? 0 : -1;
            });
        });
    }

    // A rejected field inside a closed disclosure or behind an unopened locale tab is an
    // error nobody can see. Open what holds the first one, so the summary's link lands on
    // a visible control.
    function revealErrors() {
        var first = document.querySelector('[aria-invalid="true"]');
        if (!first) {
            return;
        }

        var details = first.closest('details');
        while (details) {
            details.open = true;
            details = details.parentElement ? details.parentElement.closest('details') : null;
        }

        var panel = first.closest('.field-translate-panel');
        if (panel && panel.id) {
            var tab = document.querySelector('.osc-tab a[href="#' + CSS.escape(panel.id) + '"]');
            if (tab) {
                tab.click();
            }
        }
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('.field-translate .osc-tab a') : null;
        if (link) {
            syncLocaleTabs(link);
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        mountRichtext();
        revealErrors();

        new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                if (records[i].attributeName === 'data-bs-theme') {
                    followTheme();

                    return;
                }
            }
        }).observe(document.documentElement, { attributes: true });
    });
})();
