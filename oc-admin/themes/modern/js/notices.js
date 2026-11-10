/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// The admin bell: opens the notices panel and runs Hide and Show here and on the Notices screen.
(() => {
    const root = document.querySelector('.osc-notice-bell');
    if (!root) {
        return;
    }
    const bell = root.querySelector('#oscNoticeBell');
    const panel = root.querySelector('#oscNoticePanel');
    const backdrop = root.querySelector('.osc-notice-backdrop');
    const sheet = window.matchMedia('(max-width: 575.98px)');
    let hiddenOpen = false;
    let busy = false;

    const durationMs = () => parseFloat(getComputedStyle(root).getPropertyValue('--osc-duration-base')) || 0;

    function syncHiddenList() {
        const group = panel.querySelector('.osc-notice-group-hidden');
        const toggle = panel.querySelector('[data-osc-notice-toggle]');
        if (!group || !toggle) {
            hiddenOpen = false;
            return;
        }
        group.hidden = !hiddenOpen;
        toggle.setAttribute('aria-expanded', String(hiddenOpen));
        toggle.textContent = hiddenOpen ? toggle.dataset.labelClose : toggle.dataset.labelOpen;
    }

    function setSheet(open) {
        bell.setAttribute('aria-expanded', String(open));
        const modal = open && sheet.matches;
        if (modal) {
            panel.setAttribute('aria-modal', 'true');
        } else {
            panel.removeAttribute('aria-modal');
        }
        document.body.classList.toggle('osc-notice-sheet-open', modal);
    }

    const drawer = window.oscDrawer({
        drawer: panel,
        backdrop,
        empty: false,
        focusFirst: () => requestAnimationFrame(() => {
            (panel.querySelector('.osc-notice-row a, .osc-notice-row button') || panel.querySelector('.osc-notice-all') || panel).focus();
        }),
        beforeClose: () => {
            setSheet(false);
            hiddenOpen = false;
            syncHiddenList();
        },
    });
    const isOpen = () => drawer.isOpen();

    function openPanel() {
        drawer.open(bell);
        setSheet(true);
        syncHiddenList();
    }

    function closePanel(returnFocus = true) {
        drawer.close(returnFocus);
    }

    function updateBell(reply) {
        bell.setAttribute('aria-label', reply.name);
        let chip = bell.querySelector('.circle');
        if (reply.count < 1) {
            chip?.remove();
            return;
        }
        if (!chip) {
            chip = document.createElement('i');
            chip.setAttribute('aria-hidden', 'true');
            bell.append(chip);
        }
        chip.className = 'circle ' + (reply.action > 0 ? 'circle-red' : 'circle-gray');
        chip.textContent = reply.count;
    }

    function updateMarks(marks) {
        document.querySelectorAll('[data-osc-mark]').forEach((dot) => {
            const mark = marks[dot.dataset.oscMark];
            const group = mark ? mark.group : '';
            dot.hidden = group === '';
            dot.classList.toggle('is-action', group === 'action');
            dot.classList.toggle('is-headsup', group === 'headsup');
            dot.title = group === '' ? '' : mark.name;
            const name = document.querySelector('[data-osc-mark-name="' + CSS.escape(dot.dataset.oscMark) + '"]');
            if (name) {
                name.textContent = group === '' ? '' : ', ' + mark.name;
            }
        });
    }

    function applyReply(reply) {
        const body = panel.querySelector('.osc-notice-body');
        const top = body ? body.scrollTop : 0;
        panel.innerHTML = reply.panel;
        const fresh = panel.querySelector('.osc-notice-body');
        if (fresh) {
            fresh.scrollTop = top;
        }
        syncHiddenList();
        updateBell(reply);
        updateMarks(reply.marks || {});
    }

    // The screen's tabs, counts and empty state come from the server, the same as the panel's.
    async function refreshScreen() {
        const content = document.getElementById('content-page');
        if (!content) {
            return;
        }
        try {
            const html = await (await fetch(window.location.href, {credentials: 'same-origin'})).text();
            const fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('content-page');
            if (!fresh) {
                throw new Error('no content');
            }
            content.replaceChildren(...fresh.childNodes);
        } catch {
            window.location.reload();
        }
    }

    function leave(row) {
        const ms = durationMs();
        if (!row || !ms) {
            return Promise.resolve();
        }
        if (row.tagName !== 'TR') {
            row.style.blockSize = row.offsetHeight + 'px';
            row.getBoundingClientRect();
            row.style.blockSize = '0px';
        }
        row.classList.add('osc-notice-leaving');
        return new Promise((done) => {
            setTimeout(done, ms);
        });
    }

    function buttonsIn(scope, kind) {
        return [...scope.querySelectorAll('[data-osc-notice-' + kind + ']')];
    }

    function moveFocus(kind, nextId, inPanel) {
        const scope = inPanel ? panel : document.getElementById('content-page');
        const next = nextId ? scope?.querySelector('[data-osc-notice-' + kind + '="' + CSS.escape(nextId) + '"]') : null;
        const fallback = inPanel
            ? panel.querySelector('[data-osc-notice-toggle], .osc-notice-all')
            : document.querySelector('.osc-tabnav a[aria-current], .osc-tabnav a');
        (next || fallback || bell).focus();
    }

    async function change(kind, button) {
        if (busy) {
            return;
        }
        busy = true;
        const id = button.getAttribute('data-osc-notice-' + kind);
        const inPanel = panel.contains(button);
        const row = button.closest('.osc-notice-row, tr');
        const siblings = buttonsIn(inPanel ? panel : (document.getElementById('content-page') || panel), kind);
        const at = siblings.indexOf(button);
        const next = siblings[at + 1] || siblings[at - 1];
        const nextId = next ? next.getAttribute('data-osc-notice-' + kind) : '';

        try {
            const params = new URLSearchParams(root.dataset.oscNoticeToken);
            params.set('id', id);
            const response = await fetch(root.dataset.oscNoticeUrl + '&action=notice_' + kind, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: params,
            });
            const reply = await response.json();
            if (!reply || !reply.ok) {
                if (reply && typeof reply.panel === 'string') {
                    applyReply(reply);
                }
                window.osc.notify('error', (reply && (reply.msg || reply.error)) || root.dataset.oscNoticeFail);
                return;
            }
            await leave(row);
            applyReply(reply);
            if (!inPanel) {
                await refreshScreen();
            }
            moveFocus(kind, nextId, inPanel);
            window.osc.announce(document.querySelector('[data-osc-live="polite"]'), kind === 'hide' ? root.dataset.oscNoticeHidden : root.dataset.oscNoticeShown);
        } catch {
            window.osc.notify('error', root.dataset.oscNoticeFail);
        } finally {
            busy = false;
        }
    }

    document.addEventListener('click', (e) => {
        const target = e.target instanceof Element ? e.target : null;
        if (!target) {
            return;
        }
        const hide = target.closest('[data-osc-notice-hide]');
        const show = hide ? null : target.closest('[data-osc-notice-show]');
        if (hide || show) {
            change(hide ? 'hide' : 'show', hide || show);
            return;
        }
        if (target.closest('#oscNoticeBell')) {
            if (isOpen()) {
                closePanel();
            } else {
                openPanel();
            }
            return;
        }
        if (target.closest('[data-osc-notice-toggle]')) {
            hiddenOpen = !hiddenOpen;
            syncHiddenList();
            return;
        }
        if (target.closest('[data-osc-notice-close]')) {
            closePanel();
            return;
        }
        if (isOpen() && !target.closest('#oscNoticePanel')) {
            closePanel(false);
        }
    });

    document.addEventListener('keydown', (e) => {
        if (!isOpen()) {
            return;
        }
        if (!panel.contains(document.activeElement)) {
            return;
        }
        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) {
            const rows = [...panel.querySelectorAll('.osc-notice-row')].filter((row) => row.offsetParent !== null);
            if (!rows.length) {
                return;
            }
            const current = rows.findIndex((row) => row.contains(document.activeElement));
            let next = 0;
            if (e.key === 'End') {
                next = rows.length - 1;
            } else if (e.key === 'ArrowDown') {
                next = (current + 1) % rows.length;
            } else if (e.key === 'ArrowUp') {
                next = (current <= 0 ? rows.length : current) - 1;
            }
            e.preventDefault();
            const el = rows[next].querySelector('a, button');
            if (el) {
                el.focus();
                rows[next].scrollIntoView({block: 'nearest'});
            }
        }
    });

    sheet.addEventListener('change', () => closePanel(false));
})();
