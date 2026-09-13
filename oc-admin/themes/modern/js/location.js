/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Locations screen (settings/locations.php). Every link and form works as plain GET/POST;
 * this swaps the list in place and runs the add/edit/delete/import forms in a dialog.
 */
(function () {
    'use strict';

    const XHR = { 'X-Requested-With': 'XMLHttpRequest' };

    function init() {
        const app = document.querySelector('.locations-app');
        if (!app) {
            return;
        }

        let i18n = {};
        try {
            i18n = JSON.parse(app.getAttribute('data-i18n') || '{}');
        } catch {
            i18n = {};
        }

        const region = document.getElementById('loc-list');
        const dialog = document.getElementById('locationModal');
        const announce = document.getElementById('loc-announce');
        let listRequest = null;

        function flash(type, text) {
            if (typeof window.setJsMessage === 'function') {
                window.setJsMessage(type, text);
            }
            // setJsMessage leaves the box in its info tint whatever the message is.
            const box = document.getElementById('jsMessage');
            if (box) {
                ['ok', 'error', 'warning', 'info'].forEach(function (state) {
                    box.classList.toggle('flashmessage-' + state, state === type);
                });
            }
        }

        function withParam(href, name, value) {
            const url = new URL(href, window.location.href);
            url.searchParams.set(name, value);
            return url.toString();
        }

        // A response is ours only when the controller marked it; anything else (a login page
        // after the session expired, a server error page) is left to a full navigation.
        function isPartial(response, kind) {
            return response.headers.get('X-Osc-Partial') === kind;
        }

        function plainClick(event) {
            return event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
        }

        // ---- List ---------------------------------------------------------------
        // focus: 'heading', or a function returning the element to focus in the new list.
        function loadList(href, push, focus) {
            if (listRequest) {
                listRequest.abort();
            }
            abortForm();
            closeDialog();
            const request = new AbortController();
            listRequest = request;
            region.setAttribute('aria-busy', 'true');

            return fetch(withParam(href, 'partial', 'list'), {
                credentials: 'same-origin',
                headers: XHR,
                signal: request.signal
            }).then(function (response) {
                if (!isPartial(response, 'list')) {
                    window.location.assign(href);
                    return null;
                }
                return response.text();
            }).then(function (html) {
                if (html === null) {
                    return;
                }
                region.innerHTML = html;
                const inline = app.querySelector('.loc-inline-form');
                if (inline) {
                    inline.remove();
                }
                if (push) {
                    window.history.pushState({ locations: true }, '', href);
                }
                const list = region.querySelector('.loc-list');
                if (list && announce) {
                    announce.textContent = list.getAttribute('data-loc-summary') || '';
                }
                if (focus) {
                    let target = typeof focus === 'function' ? focus() : null;
                    if (!target) {
                        target = region.querySelector('.loc-path [aria-current]');
                        if (target) {
                            target.setAttribute('tabindex', '-1');
                        }
                    }
                    if (target) {
                        target.focus();
                    }
                }
            }).catch(function (error) {
                if (error.name !== 'AbortError') {
                    flash('error', i18n.loadError);
                }
            }).finally(function () {
                if (listRequest === request) {
                    listRequest = null;
                    region.removeAttribute('aria-busy');
                }
            });
        }

        // ---- Dialog -------------------------------------------------------------
        let formRequest = null;

        function abortForm() {
            if (formRequest) {
                formRequest.abort();
                formRequest = null;
            }
        }

        function openForm(href) {
            abortForm();
            const request = new AbortController();
            formRequest = request;

            return fetch(withParam(href, 'partial', 'form'), {
                credentials: 'same-origin',
                headers: XHR,
                signal: request.signal
            }).then(function (response) {
                if (!isPartial(response, 'form')) {
                    window.location.assign(href);
                    return null;
                }
                return response.text();
            }).then(function (html) {
                if (html === null || request.signal.aborted) {
                    return;
                }
                if (html.trim() === '') {
                    window.location.assign(href);
                    return;
                }
                formRequest = null;
                const inline = app.querySelector('.loc-inline-form');
                if (inline) {
                    inline.remove();
                }
                dialog.innerHTML = html;
                const form = dialog.querySelector('.loc-form');
                dialog.classList.toggle('osc-dialog-danger', !!(form && form.classList.contains('loc-form-danger')));
                const title = dialog.querySelector('.osc-dialog-title');
                if (title) {
                    title.id = 'loc-dialog-title';
                    dialog.setAttribute('aria-labelledby', title.id);
                }
                if (!dialog.open) {
                    dialog.showModal();
                }
                const field = dialog.querySelector('input:not([type="hidden"]), select')
                    || dialog.querySelector('[type="submit"]')
                    || dialog.querySelector('[data-loc-cancel]');
                if (field) {
                    field.focus();
                }
            }).catch(function (error) {
                if (error.name !== 'AbortError') {
                    flash('error', i18n.loadError);
                }
            });
        }

        function closeDialog() {
            if (dialog.open) {
                dialog.close();
            }
        }

        dialog.addEventListener('close', function () {
            abortForm();
            dialog.innerHTML = '';
            dialog.classList.remove('osc-dialog-danger');
        });

        function showFormError(form, message) {
            const box = form.querySelector('.loc-form-error');
            if (box) {
                box.textContent = message;
                box.hidden = false;
            } else {
                flash('error', message);
            }
        }

        // The list URL without the form that was open on it.
        function listHref(href) {
            const url = new URL(href, window.location.href);
            ['form', 'id', 'id[]', 'partial'].forEach(function (key) {
                url.searchParams.delete(key);
            });
            return url.toString();
        }

        function submitForm(form) {
            const button = form.querySelector('[type="submit"]');
            const label = button ? button.textContent : '';
            if (button) {
                button.disabled = true;
                if (button.hasAttribute('data-loc-busy')) {
                    button.textContent = button.getAttribute('data-loc-busy');
                }
            }
            const typeField = form.querySelector('[name="type"]');
            const isEdit = !!typeField && typeField.value.indexOf('edit_') === 0;
            const idField = form.querySelector('[name="country_code"], [name="region_id"], [name="city_id"]');
            const editedId = isEdit && idField ? idField.value : '';

            fetch(form.getAttribute('action'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: XHR,
                body: new URLSearchParams(new FormData(form))
            }).then(function (response) {
                // The body decides, not the header: the CSRF refusal is JSON sent as text/html.
                return response.text().then(function (text) {
                    try {
                        return JSON.parse(text);
                    } catch {
                        return null;
                    }
                });
            }).then(function (result) {
                if (result === null) {
                    // A login page or an error page: post the form normally and let the server answer.
                    HTMLFormElement.prototype.submit.call(form);
                    return;
                }
                if (result.error) {
                    showFormError(form, result.msg || i18n.saveError);
                    return;
                }
                if (!result.ok) {
                    showFormError(form, result.message || i18n.saveError);
                    return;
                }
                closeDialog();
                flash('ok', result.message);
                const target = new URL(result.redirect, window.location.href);
                const here = new URL(listHref(window.location.href));
                const sameLevel = ['country', 'region'].every(function (key) {
                    return (target.searchParams.get(key) || '') === (here.searchParams.get(key) || '');
                });
                const next = sameLevel ? here.toString() : target.toString();
                loadList(next, next !== window.location.href, function () {
                    if (editedId === '') {
                        return null;
                    }
                    const links = region.querySelectorAll('a.loc-edit');
                    for (let i = 0; i < links.length; i++) {
                        if (new URL(links[i].href).searchParams.get('id') === editedId) {
                            return links[i];
                        }
                    }
                    return null;
                });
            }).catch(function () {
                showFormError(form, i18n.saveError);
            }).finally(function () {
                if (button && button.isConnected) {
                    button.disabled = false;
                    button.textContent = label;
                }
            });
        }

        dialog.addEventListener('submit', function (event) {
            const form = event.target.closest('.loc-form');
            if (form) {
                event.preventDefault();
                submitForm(form);
            }
        });

        // ---- Delegated clicks ---------------------------------------------------
        document.addEventListener('click', function (event) {
            if (!plainClick(event)) {
                return;
            }
            const cancel = event.target.closest('[data-loc-cancel]');
            if (cancel && dialog.contains(cancel)) {
                event.preventDefault();
                closeDialog();
                return;
            }
            const opener = event.target.closest('a[data-loc-form]');
            if (opener) {
                event.preventDefault();
                openForm(opener.href);
                return;
            }
            const nav = event.target.closest('a[data-loc-nav], #loc-list .osc-pager a');
            if (nav && region.contains(nav)) {
                event.preventDefault();
                loadList(nav.href, true, 'heading');
            }
        });

        // Bulk delete opens the same confirm the no-JS form shows.
        region.addEventListener('submit', function (event) {
            const form = event.target.closest('.loc-bulk-form');
            if (!form) {
                return;
            }
            event.preventDefault();
            const data = new FormData(form);
            if (data.get('form') !== 'delete') {
                flash('info', i18n.noAction);
                return;
            }
            if (data.getAll('id[]').length === 0) {
                flash('info', i18n.nothingPicked);
                return;
            }
            const url = new URL(form.getAttribute('action'), window.location.href);
            data.forEach(function (value, key) {
                url.searchParams.append(key, value);
            });
            openForm(url.toString());
        });

        window.addEventListener('popstate', function () {
            loadList(window.location.href, false, null);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
