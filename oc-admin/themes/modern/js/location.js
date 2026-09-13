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
 * this swaps the list in place, searches as you type, edits in a drawer and confirms
 * deletes in a dialog.
 */
(function () {
    'use strict';

    const XHR = { 'X-Requested-With': 'XMLHttpRequest' };
    const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), '
        + 'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    const SEARCH_DELAY = 250;
    const SLUG_DELAY = 300;

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

        const ajax = app.getAttribute('data-ajax') || '';
        const base = app.getAttribute('data-base') || '';
        const region = document.getElementById('loc-list');
        const dialog = document.getElementById('locationModal');
        const announcer = document.getElementById('loc-announce');
        const drawer = document.getElementById('loc-drawer');
        const backdrop = document.getElementById('loc-drawer-backdrop');

        let listRequest = null;
        let formRequest = null;
        let countsRequest = null;
        let slugRequest = null;
        let searchTimer = 0;
        let slugTimer = 0;
        let drawerOpener = null;
        let dialogOpener = null;

        // ---- Small helpers --------------------------------------------------------
        function format(template, value) {
            return String(template || '').replace('%s', value);
        }

        // Same grouping as the server's number_format().
        function number(value) {
            return String(Math.trunc(Number(value) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function announce(text) {
            if (!announcer) {
                return;
            }
            announcer.textContent = '';
            window.requestAnimationFrame(function () {
                announcer.textContent = text || '';
            });
        }

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

        function listUrl(params) {
            const query = new URLSearchParams();
            Object.keys(params).forEach(function (key) {
                const value = params[key];
                if (value !== '' && value !== null && value !== undefined && value !== 0) {
                    query.set(key, String(value));
                }
            });
            const text = query.toString();
            return base + (text === '' ? '' : '&' + text);
        }

        // The list URL without the form that was open on it.
        function listHref(href) {
            const url = new URL(href, window.location.href);
            ['form', 'id', 'id[]', 'partial'].forEach(function (key) {
                url.searchParams.delete(key);
            });
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

        function getJson(url, signal) {
            return fetch(url, { credentials: 'same-origin', headers: XHR, signal: signal }).then(function (response) {
                return response.json();
            });
        }

        function setHistory(mode, href) {
            if (mode === 'push' && href !== window.location.href) {
                window.history.pushState({ locations: true }, '', href);
            } else if (mode === 'replace') {
                window.history.replaceState({ locations: true }, '', href);
            }
        }

        function el(tag, attrs, children) {
            const node = document.createElement(tag);
            Object.keys(attrs || {}).forEach(function (key) {
                if (key === 'text') {
                    node.textContent = attrs[key];
                } else {
                    node.setAttribute(key, attrs[key]);
                }
            });
            (children || []).forEach(function (child) {
                if (child) {
                    node.append(child);
                }
            });
            return node;
        }

        function icon(name) {
            return el('i', { class: 'bi ' + name, 'aria-hidden': 'true' });
        }

        function cancelList() {
            clearTimeout(searchTimer);
            if (listRequest) {
                listRequest.abort();
                listRequest = null;
            }
        }

        // ---- List -----------------------------------------------------------------
        // opts: history ('push' | 'replace' | false), focus (function or true for the path),
        // keepSearch (leave the search box untouched so typing is never interrupted).
        function loadList(href, opts) {
            opts = opts || {};
            cancelList();
            if (!opts.keepSearch) {
                abortForm();
                closeDialog();
                closeDrawer(false);
            }
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
                if (html === null || request.signal.aborted) {
                    return;
                }
                const template = document.createElement('template');
                template.innerHTML = html;
                if (!(opts.keepSearch && patchList(template.content))) {
                    region.replaceChildren(template.content);
                }
                const inline = app.querySelector('.loc-inline-form');
                if (inline) {
                    inline.remove();
                }
                setHistory(opts.history, href);
                enhanceList();
                const list = region.querySelector('.loc-list');
                if (list) {
                    announce(list.getAttribute('data-loc-summary'));
                }
                if (opts.focus) {
                    let target = typeof opts.focus === 'function' ? opts.focus() : null;
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

        // Swap everything but the search form, when the new list is the same level.
        function patchList(fragment) {
            const current = region.querySelector('.loc-list');
            const next = fragment.querySelector('.loc-list');
            if (!current || !next || !current.querySelector('.loc-search') || !next.querySelector('.loc-search')
                || current.getAttribute('data-loc-level') !== next.getAttribute('data-loc-level')) {
                return false;
            }
            current.setAttribute('data-loc-summary', next.getAttribute('data-loc-summary') || '');
            ['.loc-head', '.loc-body'].forEach(function (selector) {
                const from = next.querySelector(selector);
                const to = current.querySelector(selector);
                if (from && to) {
                    to.replaceWith(from);
                }
            });
            const oldAz = current.querySelector('.loc-az');
            const newAz = next.querySelector('.loc-az');
            if (oldAz && newAz) {
                oldAz.replaceWith(newAz);
            } else if (oldAz) {
                oldAz.remove();
            } else if (newAz) {
                current.querySelector('.loc-toolbar').append(newAz);
            }
            return true;
        }

        function enhanceList() {
            const submit = region.querySelector('.loc-search-submit');
            if (submit) {
                submit.hidden = true;
            }
            updateSelection(false);
        }

        // ---- Search ---------------------------------------------------------------
        function searchForm() {
            return region.querySelector('.loc-search');
        }

        function searchState(form) {
            const data = new FormData(form);
            return {
                q: String(data.get('q') || '').trim(),
                all: data.get('scope') === 'all'
            };
        }

        // The scope only means something with text to search for.
        function searchHref(form) {
            const url = new URL(form.getAttribute('action'), window.location.href);
            const hasText = searchState(form).q !== '';
            new FormData(form).forEach(function (value, key) {
                const text = String(value).trim();
                if (text !== '' && (key !== 'scope' || hasText)) {
                    url.searchParams.set(key, text);
                }
            });
            return url.toString();
        }

        function syncPlaceholder(form) {
            const input = form.querySelector('#loc-q');
            if (input) {
                const all = searchState(form).all;
                input.placeholder = input.getAttribute(all ? 'data-loc-placeholder-all' : 'data-loc-placeholder-level') || '';
            }
        }

        function runSearch() {
            clearTimeout(searchTimer);
            const form = searchForm();
            if (!form) {
                return;
            }
            const state = searchState(form);
            const href = searchHref(form);
            // Starting a search adds a history entry; refining or clearing it replaces that entry.
            const mode = !new URL(window.location.href).searchParams.get('q') && state.q !== '' ? 'push' : 'replace';
            if (state.all && state.q !== '') {
                searchEverywhere(form, state.q, href, mode);
            } else {
                loadList(href, { history: mode, keepSearch: true });
            }
        }

        function searchEverywhere(form, q, href, mode) {
            cancelList();
            const request = new AbortController();
            listRequest = request;
            region.setAttribute('aria-busy', 'true');

            const per = Number(i18n.hitsLimit || 10) + 1;
            getJson(ajax + '&action=location_search&per=' + per + '&q=' + encodeURIComponent(q), request.signal).then(function (data) {
                if (request.signal.aborted) {
                    return;
                }
                if (!data || data.error) {
                    flash('error', (data && data.error) || i18n.searchError);
                    return;
                }
                renderHits(form, data, q);
                setHistory(mode, href);
            }).catch(function (error) {
                if (error.name !== 'AbortError') {
                    flash('error', i18n.searchError);
                }
            }).finally(function () {
                if (listRequest === request) {
                    listRequest = null;
                    region.removeAttribute('aria-busy');
                }
            });
        }

        function hitLinks(level, hit) {
            if (level === 'country') {
                return {
                    open: { country: hit.code },
                    edit: { form: 'edit', id: hit.code },
                    path: ''
                };
            }
            if (level === 'region') {
                const known = hit.country_name !== null && hit.country_name !== undefined;
                return {
                    open: known ? { country: hit.country, region: hit.id } : null,
                    edit: known ? { country: hit.country, form: 'edit', id: hit.id } : null,
                    path: known ? hit.country_name : ''
                };
            }
            const known = hit.region_name !== null && hit.region_name !== undefined;
            const list = { country: hit.country || '', region: hit.region };
            return {
                open: known ? Object.assign({}, list, { q: hit.name }) : null,
                edit: known ? Object.assign({}, list, { form: 'edit', id: hit.id }) : null,
                path: [hit.country_name, hit.region_name].filter(function (part) {
                    return part !== null && part !== undefined;
                }).join(' › ')
            };
        }

        // Mirrors the everywhere branch of settings/locations/list.php.
        function renderHits(form, data, q) {
            const list = region.querySelector('.loc-list');
            const body = list && list.querySelector('.loc-body');
            if (!body) {
                return;
            }
            const groups = [
                ['city', i18n.cities, data.cities || []],
                ['region', i18n.regions, data.regions || []],
                ['country', i18n.countries, data.countries || []]
            ];
            const total = groups.reduce(function (sum, group) {
                return sum + Math.min(group[2].length, Number(i18n.hitsLimit || 10));
            }, 0);
            const results = el('div', { class: 'loc-results' });

            if (total === 0) {
                const clear = listUrl({ country: form.elements.country ? form.elements.country.value : '', region: form.elements.region ? form.elements.region.value : '' });
                results.append(el('div', { class: 'osc-empty' }, [
                    el('i', { class: 'bi bi-search osc-empty-icon', 'aria-hidden': 'true' }),
                    el('p', { class: 'osc-empty-title', text: format(i18n.nothingTitle, q) }),
                    el('p', { class: 'osc-empty-text', text: i18n.nothingText }),
                    el('div', { class: 'osc-empty-action' }, [
                        el('a', { class: 'btn btn-sm btn-secondary', href: clear, 'data-loc-nav': '', text: i18n.clearSearch })
                    ])
                ]));
            }

            const limit = Number(i18n.hitsLimit || 10);
            groups.forEach(function (group) {
                const level = group[0];
                const more = group[2].length > limit;
                const hits = group[2].slice(0, limit);
                if (hits.length === 0) {
                    return;
                }
                const items = hits.map(function (hit) {
                    const links = hitLinks(level, hit);
                    const name = links.open
                        ? el('a', { class: 'loc-hit-name', href: listUrl(links.open), 'data-loc-nav': '', text: hit.name })
                        : el('span', { class: 'loc-hit-name', text: hit.name });
                    const path = el('span', {
                        class: 'loc-hit-path' + (level === 'country' ? ' osc-mono' : ''),
                        text: level === 'country' ? hit.code : links.path
                    });
                    const status = hit.active === false
                        ? el('span', { class: 'osc-status status-inactive', text: i18n.hidden })
                        : null;
                    let edit = null;
                    if (links.edit) {
                        edit = el('a', {
                            class: 'loc-edit',
                            href: listUrl(links.edit),
                            'data-loc-form': '',
                            'aria-label': format(i18n.editName, hit.name)
                        }, [icon('bi-pencil')]);
                        edit.append(i18n.edit);
                    }
                    return el('li', { class: 'loc-hit' }, [el('span', { class: 'loc-hit-main' }, [name, path, status]), edit]);
                });
                results.append(el('section', { class: 'loc-hits', 'aria-labelledby': 'loc-hits-' + level }, [
                    el('h3', { class: 'loc-hits-title', id: 'loc-hits-' + level, text: group[1] }),
                    el('ul', { class: 'loc-hits-list' }, items),
                    more ? el('p', { class: 'loc-hits-more', text: i18n.hitsMore }) : null
                ]));
            });

            body.replaceChildren(results);
            const az = list.querySelector('.loc-az');
            if (az) {
                az.remove();
            }
            const summary = format(i18n.matches, number(total));
            list.setAttribute('data-loc-summary', summary);
            announce(summary);
        }

        // ---- Selection ------------------------------------------------------------
        function updateSelection(say) {
            const table = region.querySelector('.loc-table');
            const bulk = region.querySelector('[data-loc-bulk]');
            if (!table || !bulk) {
                return;
            }
            const boxes = Array.prototype.slice.call(table.querySelectorAll('tbody .col-bulkactions input[type="checkbox"]'));
            const picked = boxes.filter(function (box) {
                return box.checked;
            }).length;
            bulk.hidden = picked === 0;
            const all = table.querySelector('#check_all');
            if (all) {
                all.checked = picked > 0 && picked === boxes.length;
                all.indeterminate = picked > 0 && picked < boxes.length;
            }
            const text = format(i18n.selected, number(picked));
            const label = bulk.querySelector('[data-loc-selected]');
            if (label) {
                label.textContent = text;
            }
            if (say) {
                announce(text);
            }
        }

        // ---- Drawer ---------------------------------------------------------------
        function isDrawerOpen() {
            return !!drawer && drawer.classList.contains('is-open');
        }

        function focusFirst(container) {
            const field = container.querySelector('input:not([type="hidden"]):not([disabled]), select, textarea')
                || container.querySelector('[data-loc-cancel]');
            if (field) {
                field.focus();
            }
        }

        function openDrawerShell(opener) {
            drawerOpener = opener || null;
            drawer.hidden = false;
            backdrop.hidden = false;
            // Reflow so the slide runs from the closed position.
            void drawer.offsetWidth;
            drawer.classList.add('is-open');
            backdrop.classList.add('is-open');
        }

        function showDrawerLoading(opener) {
            drawer.replaceChildren(el('div', { class: 'osc-drawer-loading', id: 'loc-drawer-title', role: 'status' }, [
                icon('bi-arrow-repeat'),
                el('span', { class: 'visually-hidden', text: i18n.loading })
            ]));
            openDrawerShell(opener);
            drawer.focus();
        }

        function fillDrawer(html) {
            drawer.innerHTML = html;
            if (!isDrawerOpen()) {
                openDrawerShell(drawerOpener);
            }
            focusFirst(drawer);
            loadCounts();
        }

        function closeDrawer(restoreFocus) {
            if (!isDrawerOpen()) {
                return;
            }
            clearTimeout(slugTimer);
            [countsRequest, slugRequest].forEach(function (request) {
                if (request) {
                    request.abort();
                }
            });
            countsRequest = null;
            slugRequest = null;
            drawer.classList.remove('is-open');
            backdrop.classList.remove('is-open');
            const done = function () {
                if (!isDrawerOpen()) {
                    drawer.hidden = true;
                    backdrop.hidden = true;
                    drawer.replaceChildren();
                }
            };
            drawer.addEventListener('transitionend', done, { once: true });
            window.setTimeout(done, 320);
            if (restoreFocus !== false && drawerOpener && drawerOpener.isConnected) {
                drawerOpener.focus();
            }
            drawerOpener = null;
            // A drawer the server opened for ?form=… leaves its URL behind.
            const clean = listHref(window.location.href);
            if (clean !== window.location.href) {
                window.history.replaceState({ locations: true }, '', clean);
            }
        }

        function loadCounts() {
            const facts = drawer.querySelector('[data-loc-counts-pending]');
            if (!facts) {
                return;
            }
            if (countsRequest) {
                countsRequest.abort();
            }
            const request = new AbortController();
            countsRequest = request;
            const url = ajax + '&action=location_record'
                + '&level=' + encodeURIComponent(facts.getAttribute('data-loc-record-level'))
                + '&id=' + encodeURIComponent(facts.getAttribute('data-loc-record-id'));

            const settle = function (counts) {
                if (!facts.isConnected) {
                    return;
                }
                facts.querySelectorAll('[data-loc-count]').forEach(function (span) {
                    const key = span.getAttribute('data-loc-count');
                    const known = !!counts && typeof counts[key] === 'number';
                    // Never a zero standing in for a number that could not be read.
                    span.textContent = known ? number(counts[key]) : i18n.countsError;
                    span.classList.remove('loc-fact-pending');
                    span.classList.toggle('loc-fact-error', !known);
                });
                facts.removeAttribute('data-loc-counts-pending');
                facts.removeAttribute('aria-busy');
            };

            getJson(url, request.signal).then(function (data) {
                settle(data && !data.error ? data.counts : null);
            }).catch(function (error) {
                if (error.name !== 'AbortError') {
                    settle(null);
                }
            }).finally(function () {
                if (countsRequest === request) {
                    countsRequest = null;
                }
            });
        }

        function setSlugError(input, message) {
            const box = drawer.querySelector('#loc-f-slug-error');
            if (!box) {
                return;
            }
            box.textContent = message;
            box.hidden = message === '';
            if (message === '') {
                input.removeAttribute('aria-invalid');
            } else {
                input.setAttribute('aria-invalid', 'true');
            }
        }

        function checkSlug(input) {
            const slug = input.value.trim();
            if (slugRequest) {
                slugRequest.abort();
                slugRequest = null;
            }
            if (slug === '') {
                setSlugError(input, '');
                return;
            }
            const level = input.getAttribute('data-loc-slug-check');
            const self = String(input.getAttribute('data-loc-self') || '').toUpperCase();
            const request = new AbortController();
            slugRequest = request;

            getJson(ajax + '&action=' + encodeURIComponent(level + '_slug') + '&slug=' + encodeURIComponent(slug), request.signal)
                .then(function (data) {
                    if (!input.isConnected || input.value.trim() !== slug) {
                        return;
                    }
                    const row = data && data.error === 1 ? data[level] : null;
                    const id = row ? String(level === 'country' ? row.pk_c_code : row.pk_i_id).toUpperCase() : '';
                    setSlugError(input, row && id !== self ? format(i18n.slugTaken, row.s_name) : '');
                })
                .catch(function () {
                    // A check that fails says nothing: the server still keeps slugs unique.
                })
                .finally(function () {
                    if (slugRequest === request) {
                        slugRequest = null;
                    }
                });
        }

        if (drawer) {
            drawer.setAttribute('tabindex', '-1');

            drawer.addEventListener('input', function (event) {
                const input = event.target.closest('[data-loc-slug-check]');
                if (input) {
                    clearTimeout(slugTimer);
                    slugTimer = window.setTimeout(function () {
                        checkSlug(input);
                    }, SLUG_DELAY);
                }
            });

            drawer.addEventListener('keydown', function (event) {
                if (event.key !== 'Tab') {
                    return;
                }
                const items = Array.prototype.filter.call(drawer.querySelectorAll(FOCUSABLE), function (node) {
                    return node.getClientRects().length > 0;
                });
                if (items.length === 0) {
                    event.preventDefault();
                    return;
                }
                const first = items[0];
                const last = items[items.length - 1];
                if (event.shiftKey && (document.activeElement === first || document.activeElement === drawer)) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });

            drawer.addEventListener('submit', function (event) {
                const form = event.target.closest('.loc-form');
                if (form) {
                    event.preventDefault();
                    submitForm(form);
                }
            });

            backdrop.addEventListener('click', function () {
                closeDrawer();
            });
        }

        // ---- Dialog ---------------------------------------------------------------
        function abortForm() {
            if (formRequest) {
                formRequest.abort();
                formRequest = null;
            }
        }

        // The same pattern the browser checks on submit, so a count may carry separators.
        function syncConfirm(form) {
            const input = form.querySelector('[data-loc-confirm]');
            const button = form.querySelector('[type="submit"]');
            if (!input || !button) {
                return;
            }
            let matches = input.value === input.getAttribute('data-loc-confirm');
            try {
                matches = new RegExp('^(?:' + input.getAttribute('pattern') + ')$', 'v').test(input.value);
            } catch {
                // No v flag in this browser: the exact phrase still works.
            }
            button.disabled = !matches;
        }

        function showDialog(html, opener) {
            dialogOpener = opener || null;
            dialog.innerHTML = html;
            const form = dialog.querySelector('.loc-form');
            dialog.classList.toggle('osc-dialog-danger', !!(form && form.classList.contains('loc-form-danger')));
            const title = dialog.querySelector('.osc-dialog-title');
            if (title) {
                title.id = 'loc-dialog-title';
                dialog.setAttribute('aria-labelledby', title.id);
            }
            if (form) {
                syncConfirm(form);
            }
            if (!dialog.open) {
                dialog.showModal();
            }
            // A destructive dialog never lands on its Delete button.
            focusFirst(dialog);
        }

        function openForm(href, opener) {
            abortForm();
            const kind = new URL(href, window.location.href).searchParams.get('form');
            const inDrawer = (kind === 'add' || kind === 'edit') && !!drawer;
            if (inDrawer) {
                closeDialog();
                showDrawerLoading(opener);
            }
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
                const template = document.createElement('template');
                template.innerHTML = html;
                const surface = template.content.querySelector('[data-loc-surface]');
                if (surface && surface.getAttribute('data-loc-surface') === 'drawer' && drawer) {
                    fillDrawer(html);
                    return;
                }
                if (inDrawer) {
                    // An error in place of the edit form (the row was deleted meanwhile).
                    closeDrawer(false);
                }
                showDialog(html, opener);
            }).catch(function (error) {
                if (error.name !== 'AbortError') {
                    if (inDrawer) {
                        closeDrawer();
                    }
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
            if (dialogOpener && dialogOpener.isConnected) {
                dialogOpener.focus();
            }
            dialogOpener = null;
        });

        dialog.addEventListener('input', function (event) {
            const form = event.target.closest('.loc-form');
            if (form && event.target.hasAttribute('data-loc-confirm')) {
                syncConfirm(form);
            }
        });

        dialog.addEventListener('submit', function (event) {
            const form = event.target.closest('.loc-form');
            if (form) {
                event.preventDefault();
                submitForm(form);
            }
        });

        // ---- Saving ---------------------------------------------------------------
        function showFormError(form, message) {
            const box = form.querySelector('.loc-form-error');
            if (box) {
                box.textContent = message;
                box.hidden = false;
                box.scrollIntoView({ block: 'nearest' });
            } else {
                flash('error', message);
            }
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
            const type = typeField ? typeField.value : '';
            const idField = form.querySelector('[name="country_code"], [name="region_id"], [name="city_id"]');
            const editedId = type.indexOf('edit_') === 0 && idField ? idField.value : '';

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
                if (result.error || !result.ok) {
                    showFormError(form, result.msg || result.message || i18n.saveError);
                    return;
                }
                closeDialog();
                closeDrawer(false);
                flash('ok', result.message);

                const target = new URL(result.redirect, window.location.href);
                const here = new URL(listHref(window.location.href));
                const sameLevel = here.searchParams.get('scope') === 'all' || ['country', 'region'].every(function (key) {
                    return (target.searchParams.get(key) || '') === (here.searchParams.get(key) || '');
                });
                const next = sameLevel ? here.toString() : target.toString();
                loadList(next, {
                    history: next !== window.location.href ? 'push' : false,
                    focus: function () {
                        if (type.indexOf('add_') === 0) {
                            return region.querySelector('.loc-head-actions a');
                        }
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
                    }
                });
            }).catch(function () {
                showFormError(form, i18n.saveError);
            }).finally(function () {
                if (button && button.isConnected) {
                    button.textContent = label;
                    button.disabled = false;
                    syncConfirm(form);
                }
            });
        }

        // ---- Delegated events -----------------------------------------------------
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
            if (cancel && drawer && drawer.contains(cancel)) {
                event.preventDefault();
                closeDrawer();
                return;
            }
            const opener = event.target.closest('a[data-loc-form]');
            if (opener) {
                event.preventDefault();
                openForm(opener.href, opener);
                return;
            }
            const everywhere = event.target.closest('a[data-loc-scope-all]');
            const form = searchForm();
            if (everywhere && form && form.elements.scope) {
                event.preventDefault();
                const all = form.querySelector('#loc-scope-all');
                all.checked = true;
                syncPlaceholder(form);
                runSearch();
                form.querySelector('#loc-q').focus();
                return;
            }
            const nav = event.target.closest('a[data-loc-nav], #loc-list .osc-pager a');
            if (nav && region.contains(nav)) {
                event.preventDefault();
                const inAz = !!nav.closest('.loc-az');
                const href = nav.href;
                loadList(href, {
                    history: 'push',
                    focus: function () {
                        if (!inAz) {
                            return null;
                        }
                        return Array.prototype.find.call(region.querySelectorAll('.loc-az a'), function (link) {
                            return link.href === href;
                        }) || null;
                    }
                });
            }
        });

        region.addEventListener('input', function (event) {
            if (event.target.id === 'loc-q') {
                clearTimeout(searchTimer);
                searchTimer = window.setTimeout(runSearch, SEARCH_DELAY);
            }
        });

        region.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && event.target.id === 'loc-q' && event.target.value !== '') {
                event.preventDefault();
                event.stopPropagation();
                event.target.value = '';
                runSearch();
            }
        });

        region.addEventListener('change', function (event) {
            const target = event.target;
            if (target.id === 'check_all') {
                region.querySelectorAll('.loc-table tbody .col-bulkactions input[type="checkbox"]').forEach(function (box) {
                    box.checked = target.checked;
                });
                updateSelection(true);
            } else if (target.matches('.loc-table tbody .col-bulkactions input[type="checkbox"]')) {
                updateSelection(true);
            } else if (target.matches('.loc-scope input')) {
                syncPlaceholder(target.form);
                // With nothing typed the list is the same; only a search already in the URL changes.
                if (searchState(target.form).q !== '' || new URL(window.location.href).searchParams.get('q')) {
                    runSearch();
                }
            }
        });

        region.addEventListener('submit', function (event) {
            const search = event.target.closest('.loc-search');
            if (search) {
                event.preventDefault();
                runSearch();
                return;
            }
            const form = event.target.closest('.loc-bulk-form');
            if (!form) {
                return;
            }
            // Bulk delete opens the same confirm the no-JS form shows.
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
            openForm(url.toString(), event.submitter || form.querySelector('[type="submit"]'));
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !dialog.open && isDrawerOpen()) {
                event.preventDefault();
                closeDrawer();
                return;
            }
            if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || dialog.open || isDrawerOpen()) {
                return;
            }
            if (event.target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"])')) {
                return;
            }
            const input = document.getElementById('loc-q');
            if (input) {
                event.preventDefault();
                input.focus();
                input.select();
            }
        });

        window.addEventListener('popstate', function () {
            loadList(window.location.href, { history: false });
        });

        // ---- Start ----------------------------------------------------------------
        enhanceList();
        if (isDrawerOpen()) {
            focusFirst(drawer);
            loadCounts();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
