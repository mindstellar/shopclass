/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

(function () {
    'use strict';

    var i18n = {};
    var i18nNode = document.getElementById('ins-i18n');
    if (i18nNode) {
        try {
            i18n = JSON.parse(i18nNode.textContent);
        } catch {
            i18n = {};
        }
    }

    function text(key, fallback) {
        return i18n[key] || fallback;
    }

    function formToUrlEncoded(form, extra) {
        var params = new URLSearchParams();
        var data = new FormData(form);
        data.forEach(function (value, key) {
            params.append(key, value);
        });
        if (extra) {
            Object.keys(extra).forEach(function (key) {
                params.set(key, extra[key]);
            });
        }
        return params;
    }

    function addHiddenField(form, name, value) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }

    // Speaks a message through a polite live region; the copy announcer unless another id is given.
    function announce(message, regionId) {
        var el = document.getElementById(regionId || 'ins-copy-announcer');
        if (!el) {
            return;
        }
        el.textContent = '';
        window.setTimeout(function () {
            el.textContent = message;
        }, 30);
    }

    /* -----------------------------------------------------------------
       Field errors: one place sets and clears aria-invalid, the message
       element (#<id>-error) and its entry in aria-describedby.
       ------------------------------------------------------------- */
    function setFieldError(input, message) {
        if (!input) {
            return;
        }
        var errorEl = input.id ? document.getElementById(input.id + '-error') : null;
        var ids = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        var errorId = input.id + '-error';
        var at = ids.indexOf(errorId);
        if (message) {
            input.setAttribute('aria-invalid', 'true');
            if (errorEl) {
                var label = errorEl.querySelector('span');
                (label || errorEl).textContent = message;
                errorEl.hidden = false;
                if (at === -1) {
                    ids.push(errorId);
                }
            }
        } else {
            input.removeAttribute('aria-invalid');
            if (errorEl) {
                errorEl.hidden = true;
                if (at !== -1) {
                    ids.splice(at, 1);
                }
            }
        }
        if (ids.length) {
            input.setAttribute('aria-describedby', ids.join(' '));
        } else {
            input.removeAttribute('aria-describedby');
        }
    }

    // The page renders each field's short error text; reuse it, else the fallback.
    function renderedError(input, fallback) {
        var holder = input && input.id ? document.getElementById(input.id + '-error') : null;
        var span = holder ? holder.querySelector('span') : null;
        return (span && span.textContent.trim()) || fallback || ' ';
    }

    function clearErrorOnInput(input) {
        if (!input) {
            return;
        }
        input.addEventListener('input', function () {
            if (input.getAttribute('aria-invalid') === 'true') {
                setFieldError(input, null);
            }
        });
    }

    /* -----------------------------------------------------------------
       Busy buttons: disabled, aria-busy, .is-loading, label swap, width kept.
       ------------------------------------------------------------- */
    function setBusy(btn, busy, busyLabel) {
        var label = btn.querySelector('.ins-btn-label');
        if (busy) {
            if (btn.getAttribute('aria-busy') === 'true') {
                return;
            }
            btn.style.minWidth = btn.offsetWidth + 'px';
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.classList.add('is-loading');
            if (label) {
                btn.setAttribute('data-idle-label', label.textContent);
                label.textContent = busyLabel;
            }
        } else {
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
            btn.classList.remove('is-loading');
            btn.style.minWidth = '';
            if (label && btn.hasAttribute('data-idle-label')) {
                label.textContent = btn.getAttribute('data-idle-label');
                btn.removeAttribute('data-idle-label');
            }
        }
    }

    /* -----------------------------------------------------------------
       Step 1: language select reloads the page with ?install_locale=
       ------------------------------------------------------------- */
    function initLocaleSelect() {
        var select = document.getElementById('install_locale');
        if (!select) {
            return;
        }
        select.addEventListener('change', function () {
            window.location.href = '?install_locale=' + encodeURIComponent(select.value);
        });
    }

    /* -----------------------------------------------------------------
       Password / value reveal toggles (used on step 2, step 4, finish)
       ------------------------------------------------------------- */
    function initReveal() {
        var toggles = document.querySelectorAll('[data-reveal-target]');
        toggles.forEach(function (btn) {
            var input = document.getElementById(btn.getAttribute('data-reveal-target'));
            if (!input) {
                return;
            }
            btn.addEventListener('click', function () {
                var willShow = input.type === 'password';
                input.type = willShow ? 'text' : 'password';
                btn.setAttribute('aria-pressed', willShow ? 'true' : 'false');
                btn.setAttribute(
                    'aria-label',
                    willShow ? text('hidePassword', 'Hide password') : text('showPassword', 'Show password')
                );
            });
        });
    }

    /* -----------------------------------------------------------------
       "Make a strong password": 16 random characters, shown in the field
       ------------------------------------------------------------- */
    function initGenerate() {
        var alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789-';
        document.querySelectorAll('[data-generate-target]').forEach(function (btn) {
            var input = document.getElementById(btn.getAttribute('data-generate-target'));
            if (!input || !window.crypto || !window.crypto.getRandomValues) {
                return;
            }
            btn.hidden = false;
            btn.addEventListener('click', function () {
                var limit = 256 - (256 % alphabet.length);
                var out = '';
                while (out.length < 16) {
                    var bytes = new Uint8Array(32);
                    window.crypto.getRandomValues(bytes);
                    for (var i = 0; i < bytes.length && out.length < 16; i++) {
                        if (bytes[i] < limit) {
                            out += alphabet.charAt(bytes[i] % alphabet.length);
                        }
                    }
                }
                input.value = out;
                input.type = 'text';
                setFieldError(input, null);
                var reveal = document.querySelector('[data-reveal-target="' + input.id + '"]');
                if (reveal) {
                    reveal.setAttribute('aria-pressed', 'true');
                    reveal.setAttribute('aria-label', text('hidePassword', 'Hide password'));
                }
                input.focus();
            });
        });
    }

    /* -----------------------------------------------------------------
       Copy-to-clipboard buttons (finish screen credentials)
       ------------------------------------------------------------- */
    function fallbackCopy(input) {
        if (!('value' in input)) {
            var range = document.createRange();
            range.selectNodeContents(input);
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            try {
                return document.execCommand('copy');
            } catch {
                return false;
            }
        }
        var wasReadOnly = input.hasAttribute('readonly');
        input.focus();
        input.select();
        input.setSelectionRange(0, 999999);
        var ok;
        try {
            ok = document.execCommand('copy');
        } catch {
            ok = false;
        }
        if (wasReadOnly) {
            input.setAttribute('readonly', 'readonly');
        }
        return ok;
    }

    function initCopy() {
        var buttons = document.querySelectorAll('.ins-copy-btn');
        buttons.forEach(function (btn) {
            var targetId = btn.getAttribute('data-copy-target');
            var input = document.getElementById(targetId);
            var label = btn.querySelector('.ins-btn-label');
            if (!input || !label) {
                return;
            }
            var originalLabel = label.textContent;
            var resetTimer = null;

            function onCopied(ok) {
                if (resetTimer) {
                    window.clearTimeout(resetTimer);
                }
                if (ok) {
                    label.textContent = text('copied', 'Copied');
                    btn.classList.add('is-copied');
                    announce(text('copied', 'Copied'));
                    resetTimer = window.setTimeout(function () {
                        label.textContent = originalLabel;
                        btn.classList.remove('is-copied');
                    }, 2000);
                } else {
                    announce(text('copyFailed', "Couldn't copy. Select the text and copy it manually."));
                }
            }

            btn.addEventListener('click', function () {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText('value' in input ? input.value : input.textContent).then(
                        function () {
                            onCopied(true);
                        },
                        function () {
                            onCopied(fallbackCopy(input));
                        }
                    );
                } else {
                    onCopied(fallbackCopy(input));
                }
            });
        });
    }

    /* -----------------------------------------------------------------
       Dismissible panels (finish screen: error_location warning)
       ------------------------------------------------------------- */
    function initDismissiblePanels() {
        document.querySelectorAll('[data-dismiss-after]').forEach(function (panel) {
            var delay = parseInt(panel.getAttribute('data-dismiss-after'), 10) || 6000;
            window.setTimeout(function () {
                dismissPanel(panel);
            }, delay);
        });
        document.querySelectorAll('.ins-panel-dismiss').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var panel = btn.closest('.ins-panel');
                if (panel) {
                    dismissPanel(panel);
                }
            });
        });
    }

    function dismissPanel(panel) {
        panel.classList.add('is-dismissing');
        window.setTimeout(function () {
            panel.hidden = true;
        }, 260);
    }

    /* -----------------------------------------------------------------
       Step 2: "Create the database" reveals the DB admin fields
       ------------------------------------------------------------- */
    function initCreateDbToggle() {
        var checkbox = document.getElementById('createdb');
        if (!checkbox) {
            return;
        }
        var wrap = document.getElementById('ins-createdb-fields');
        var adminUser = document.getElementById('admin_username');
        var adminPass = document.getElementById('admin_password');
        var dbUser = document.getElementById('username');
        var dbPass = document.getElementById('password');

        function sync() {
            var checked = checkbox.checked;
            if (wrap) {
                wrap.hidden = !checked;
            }
            if (adminUser) {
                adminUser.disabled = !checked;
            }
            if (adminPass) {
                adminPass.disabled = !checked;
            }
            if (checked) {
                if (adminUser && dbUser && !adminUser.value) {
                    adminUser.value = dbUser.value;
                }
                if (adminPass && dbPass && !adminPass.value) {
                    adminPass.value = dbPass.value;
                }
            }
        }

        checkbox.addEventListener('change', sync);
        sync();
    }

    var DB_FIELDS = [
        'dbhost',
        'dbname',
        'username',
        'password',
        'tableprefix',
        'createdb',
        'reset_unfinished',
        'admin_username',
        'admin_password',
    ];

    /* -----------------------------------------------------------------
       Step 2 form: clears field flags on input and shows Connect as busy
       ------------------------------------------------------------- */
    function initDatabaseForm() {
        var form = document.getElementById('ins-database-form');
        if (!form) {
            return;
        }
        var testBtn = document.getElementById('ins-test-btn');
        var connectBtn = document.getElementById('ins-connect-btn');

        DB_FIELDS.forEach(function (name) {
            clearErrorOnInput(form.elements[name]);
        });

        form.addEventListener('submit', function (event) {
            if (connectBtn && connectBtn.getAttribute('aria-busy') === 'true') {
                event.preventDefault();
                return;
            }
            if (connectBtn) {
                setBusy(connectBtn, true, text('creatingTables', 'Creating tables…'));
            }
            if (testBtn) {
                testBtn.disabled = true;
            }
        });

        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                if (connectBtn) {
                    setBusy(connectBtn, false);
                }
                if (testBtn) {
                    testBtn.disabled = false;
                }
            }
        });
    }

    /* -----------------------------------------------------------------
       Step 2: "Test connection" — advisory, never blocks "Connect". A failed
       test fills the same panel and flags the same field as a failed Connect.
       ------------------------------------------------------------- */
    var TEST_TIMEOUT = 30000;

    function initDbTest() {
        var btn = document.getElementById('ins-test-btn');
        var form = document.getElementById('ins-database-form');
        var resultEl = document.getElementById('ins-test-result');
        var panel = document.getElementById('ins-db-error');
        var panelText = panel ? panel.querySelector('.ins-panel-text') : null;
        if (!btn || !form || !resultEl || !panel || !panelText || !window.fetch) {
            return;
        }
        btn.hidden = false;

        function clearFieldFlags() {
            DB_FIELDS.forEach(function (name) {
                setFieldError(form.elements[name], null);
            });
        }

        function showError(message, fieldName) {
            var field = fieldName ? form.elements[fieldName] : null;
            panelText.textContent = message;
            panel.classList.remove('is-dismissing');
            panel.hidden = false;
            if (field) {
                var more = field.closest ? field.closest('details') : null;
                if (more) {
                    more.open = true;
                }
                setFieldError(field, renderedError(field, message));
                field.focus();
            } else {
                panel.focus();
            }
        }

        btn.addEventListener('click', function () {
            if (btn.getAttribute('aria-busy') === 'true') {
                return;
            }
            clearFieldFlags();
            panel.hidden = true;
            resultEl.textContent = '';
            resultEl.className = 'ins-test-result';
            setBusy(btn, true, text('testing', 'Testing…'));

            var params = formToUrlEncoded(form, { action: 'test_db' });
            var controller = window.AbortController ? new AbortController() : null;
            var timer = window.setTimeout(function () {
                if (controller) {
                    controller.abort();
                }
            }, TEST_TIMEOUT);

            fetch('install.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString(),
                signal: controller ? controller.signal : undefined,
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data && data.level === 'success') {
                        resultEl.textContent = data.message || '';
                        resultEl.classList.add('ins-test-result-success');
                        if (resultEl.scrollIntoView) {
                            resultEl.scrollIntoView({ block: 'nearest' });
                        }
                    } else {
                        showError((data && data.message) || text('networkError', 'Something went wrong. Check your connection and try again.'), data && data.field);
                    }
                })
                .catch(function () {
                    showError(text('networkError', 'Something went wrong. Check your connection and try again.'), null);
                })
                .then(function () {
                    window.clearTimeout(timer);
                    setBusy(btn, false);
                });
        });
    }

    /* -----------------------------------------------------------------
       Step 3: the shop plugin list shows only while Shop is picked
       ------------------------------------------------------------- */
    function initSiteType() {
        var form = document.getElementById('ins-site-type-form');
        var list = document.getElementById('ins-shop-plugins');
        if (!form || !list) {
            return;
        }
        function sync() {
            var shop = form.querySelector('input[name="site_type"][value="shop"]');
            list.hidden = !(shop && shop.checked);
        }
        form.querySelectorAll('input[name="site_type"]').forEach(function (radio) {
            radio.addEventListener('change', sync);
        });
        sync();

        var count = document.getElementById('ins-addons-count');
        if (count) {
            var boxes = list.querySelectorAll('input[name="shop_plugins[]"]');
            var syncCount = function () {
                var picked = Array.prototype.filter.call(boxes, function (box) {
                    return box.checked;
                }).length;
                count.textContent = format(count.getAttribute('data-template') || '%1$s / %2$s', picked, boxes.length);
            };
            list.addEventListener('change', syncCount);
        }
    }

    /* -----------------------------------------------------------------
       Steps 4 and 5: the site + admin form runs the install plan one request per unit, then a
       synthetic POST to install.php carries the result to the done step.
       ------------------------------------------------------------- */
    var UNIT_TIMEOUT = 180000;
    var SLOW_AFTER = 8000;
    var FINISH_PAUSE = 700;

    function format(template, a, b) {
        return template.replace('%1$s', String(a)).replace('%2$s', String(b)).replace('%s', String(a));
    }

    // Marks step n of the rail and the phone step line.
    function setRail(n) {
        document.querySelectorAll('.ins-steps li[data-rail]').forEach(function (li) {
            var num = parseInt(li.getAttribute('data-rail'), 10);
            li.classList.remove('is-done', 'is-current', 'is-upcoming');
            li.classList.add(num < n ? 'is-done' : num === n ? 'is-current' : 'is-upcoming');
            if (num === n) {
                li.setAttribute('aria-current', 'step');
            } else {
                li.removeAttribute('aria-current');
            }
        });
        var label = document.querySelector('.ins-steps li[data-rail="' + n + '"] .ins-step-label');
        var mLabel = document.getElementById('ins-mstep-label');
        var mCount = document.getElementById('ins-mstep-count');
        var segs = document.querySelectorAll('.ins-segs > span');
        if (label && mLabel) {
            mLabel.textContent = label.textContent;
        }
        if (mCount) {
            mCount.textContent = format(text('stepCount', 'Step %1$s of %2$s'), n, segs.length || 6);
        }
        segs.forEach(function (seg, i) {
            seg.classList.toggle('is-on', i < n);
        });
    }

    // The tasks from data-install-plan; the places task only when a country is picked.
    function readPlan(form, country) {
        var plan;
        try {
            plan = JSON.parse(form.getAttribute('data-install-plan') || '[]');
        } catch {
            plan = [];
        }
        var countryText = '';
        var wantsCountry = !!country && country.value !== '' && country.value !== 'skip';
        if (wantsCountry && country.selectedOptions && country.selectedOptions[0]) {
            countryText = country.selectedOptions[0].textContent.trim();
        }
        return plan.filter(function (task) {
            return task.when !== 'country' || wantsCountry;
        }).map(function (task) {
            task.label = String(task.label || '').replace('%s', countryText);
            return task;
        });
    }

    // One request for one unit. Resolves to the parsed JSON, or null when nothing usable came back.
    function unitRequest(form, country, unit, isAccount) {
        var nonceField = form.elements.install_nonce;
        var params;
        if (isAccount) {
            params = formToUrlEncoded(form, { action: unit.action });
        } else {
            params = new URLSearchParams(unit.fields || {});
            params.set('action', unit.action);
            params.set('install_nonce', nonceField ? nonceField.value : '');
            if (unit.action === 'locations' && country) {
                params.set('location-json', country.value);
            }
        }
        var controller = new AbortController();
        var timer = window.setTimeout(function () {
            controller.abort();
        }, UNIT_TIMEOUT);
        return fetch('install-location.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString(),
            signal: controller.signal,
        }).then(function (response) {
            return response.json();
        }).catch(function () {
            return null;
        }).then(function (data) {
            window.clearTimeout(timer);
            return data && typeof data === 'object' ? data : null;
        });
    }

    function buildRows(list, plan) {
        list.textContent = '';
        return plan.map(function (task) {
            var li = document.createElement('li');
            li.className = 'ins-task';
            li.setAttribute('data-s', 'wait');
            var st = document.createElement('span');
            st.className = 'ins-task-st';
            var name = document.createElement('span');
            name.className = 'ins-task-name';
            name.appendChild(document.createTextNode(task.label));
            var sub = document.createElement('span');
            sub.className = 'ins-task-sub';
            name.appendChild(sub);
            var state = document.createElement('span');
            state.className = 'ins-task-state';
            li.appendChild(st);
            li.appendChild(name);
            li.appendChild(state);
            list.appendChild(li);
            return { li: li, sub: sub, state: state };
        });
    }

    // While the install runs, closing or leaving the tab asks first.
    function onLeaveWhileRunning(event) {
        event.preventDefault();
        event.returnValue = '';
    }

    function guardLeave(on) {
        if (on) {
            window.addEventListener('beforeunload', onLeaveWhileRunning);
        } else {
            window.removeEventListener('beforeunload', onLeaveWhileRunning);
        }
    }

    function submitFinish(form, emailStatus, password, lost) {
        guardLeave(false);
        var nextForm = document.createElement('form');
        nextForm.method = 'POST';
        nextForm.action = 'install.php';
        nextForm.style.display = 'none';
        addHiddenField(nextForm, 'step', '5');
        addHiddenField(nextForm, 'result', emailStatus || '');
        addHiddenField(nextForm, 'password', password || '');
        lost.forEach(function (token) {
            addHiddenField(nextForm, 'lost[]', token);
        });
        var nonceField = form.elements.install_nonce;
        addHiddenField(nextForm, 'install_nonce', nonceField ? nonceField.value : '');
        document.body.appendChild(nextForm);
        nextForm.submit();
    }

    // A fatal answer: the row and the bar stop, and the rendered panel and Reload link are shown.
    function fatalStop(ui, row, message) {
        guardLeave(false);
        row.li.setAttribute('data-s', 'skip');
        row.state.textContent = '';
        row.sub.textContent = message;
        ui.track.classList.add('is-finished');
        ui.nowLine.textContent = '';
        if (ui.eta) {
            ui.eta.hidden = true;
        }
        if (ui.reload) {
            ui.reload.hidden = false;
        }
        if (ui.fatal) {
            var slot = ui.fatal.querySelector('.ins-panel-text');
            if (slot) {
                slot.textContent = message;
            }
            ui.fatal.hidden = false;
            ui.fatal.focus();
        }
    }

    /*
     * Runs the plan one unit at a time. A failed account calls onAccountFail(data) and stops;
     * otherwise onDone(account, lost) runs after the last unit.
     */
    function runPlan(plan, rows, ui, onAccountFail, onDone) {
        var total = plan.reduce(function (sum, task) {
            return sum + task.units.length;
        }, 0);
        var done = 0;
        var lost = [];
        var account = { password: '', emailStatus: '' };
        var slowTimer = 0;

        // The only place the bar moves, and it runs only when a unit has settled.
        function settleUnit() {
            done++;
            var pct = total ? done / total : 0;
            ui.fill.style.transform = 'scaleX(' + pct + ')';
            ui.track.setAttribute('aria-valuenow', String(Math.round(pct * 100)));
            var count = format(text('doneCount', '%1$s of %2$s done'), done, total);
            ui.countLine.textContent = count;
            ui.track.setAttribute('aria-valuetext', count);
        }

        // One screen reader message per finished task, never per request.
        function announceTask(message) {
            announce(message, 'ins-progress-announcer');
        }

        ui.countLine.textContent = format(text('doneCount', '%1$s of %2$s done'), 0, total);
        ui.nowLine.textContent = text('starting', 'Starting…');

        function finishAll() {
            ui.nowLine.textContent = text('allDone', 'All done');
            announceTask(text('allDone', 'All done'));
            ui.track.classList.add('is-finished');
            window.setTimeout(function () {
                onDone(account, lost);
            }, FINISH_PAUSE);
        }

        function runTask(ti) {
            if (ti >= plan.length) {
                finishAll();
                return;
            }
            var task = plan[ti];
            var row = rows[ti];
            var skipped = [];
            row.li.setAttribute('data-s', 'run');

            function endTask() {
                var count = task.units.length;
                if (skipped.length) {
                    row.li.setAttribute('data-s', 'skip');
                    if (count > 1) {
                        row.state.textContent = format(text('addedCount', '%1$s of %2$s added'), count - skipped.length, count);
                        row.sub.textContent = format(text('skippedSub', "%s couldn't be added. You can add it later."), skipped.map(function (s) {
                            return s.label;
                        }).join(', '));
                    } else {
                        row.state.textContent = '';
                        row.sub.textContent = skipped[0].reason;
                    }
                } else {
                    row.li.setAttribute('data-s', 'ok');
                    row.state.textContent = count > 1 ? format(text('unitCount', '%1$s of %2$s'), count, count) : '';
                }
                announceTask(skipped.length ? format(text('taskSkipped', '%1$s. %2$s'), task.label, row.sub.textContent) : format(text('taskDone', '%s done'), task.label));
                runTask(ti + 1);
            }

            function runUnit(unitIndex) {
                if (unitIndex >= task.units.length) {
                    endTask();
                    return;
                }
                var unit = task.units[unitIndex];
                var isAccount = !!task.stop;
                var unitLabel = unit.label || '';
                ui.nowLine.textContent = task.label + (unitLabel && task.units.length > 1 ? ': ' + unitLabel : '') + '…';
                if (task.units.length > 1) {
                    row.state.textContent = format(text('unitCount', '%1$s of %2$s'), unitIndex + 1, task.units.length);
                }
                window.clearTimeout(slowTimer);
                slowTimer = window.setTimeout(function () {
                    ui.slow.classList.add('is-on');
                }, SLOW_AFTER);

                unitRequest(ui.form, ui.country, unit, isAccount).then(function (data) {
                    window.clearTimeout(slowTimer);
                    ui.slow.classList.remove('is-on');

                    if (isAccount) {
                        if (data && data.status) {
                            account.password = data.password || '';
                            account.emailStatus = data.email_status || '';
                        } else {
                            onAccountFail(data);
                            return;
                        }
                    } else if (data && data.fatal) {
                        fatalStop(ui, row, data.error || text('networkError', 'Something went wrong. Check your connection and try again.'));
                        return;
                    } else if (!data) {
                        if (unit.lost && lost.indexOf(unit.lost) === -1) {
                            lost.push(unit.lost);
                        }
                        skipped.push({ label: unitLabel || task.label, reason: text('lost', 'The server did not answer in time, or the connection dropped.') });
                    } else if (!data.status) {
                        skipped.push({ label: unitLabel || task.label, reason: data.message || data.error || text('lost', 'The server did not answer in time, or the connection dropped.') });
                    }
                    settleUnit();
                    runUnit(unitIndex + 1);
                });
            }

            runUnit(0);
        }

        runTask(0);
    }

    function initTargetForm() {
        var form = document.getElementById('ins-target-form');
        if (!form) {
            return;
        }
        var errorPanel = document.getElementById('ins-target-error');
        var errorSlot = errorPanel ? errorPanel.querySelector('.ins-panel-text') : null;
        var errorDefault = errorSlot ? errorSlot.textContent : '';
        var title = document.getElementById('ins-progress-title');
        var siteName = document.getElementById('webtitle');
        var adminUser = document.getElementById('admin_user');
        var email = document.getElementById('email');
        var ui = {
            form: form,
            country: document.getElementById('location-json'),
            progress: document.getElementById('ins-progress'),
            nowLine: document.getElementById('ins-meter-now'),
            fatal: document.getElementById('ins-progress-error'),
            reload: document.getElementById('ins-progress-reload'),
            countLine: document.getElementById('ins-meter-count'),
            track: document.getElementById('ins-track'),
            fill: document.getElementById('ins-fill'),
            slow: document.getElementById('ins-slow'),
            eta: document.getElementById('ins-eta'),
        };
        var list = document.getElementById('ins-tasks');
        var userPattern = /^[A-Za-z0-9]+$/;
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        [siteName, adminUser, email].forEach(clearErrorOnInput);

        function showForm() {
            guardLeave(false);
            ui.progress.hidden = true;
            form.hidden = false;
            setRail(4);
        }

        function onAccountFail(data) {
            // A fatal answer means the session is gone; step 4 without it renders the "Your session ended" stage.
            if (data && data.fatal) {
                guardLeave(false);
                window.location.assign('install.php?step=4');
                return;
            }
            showForm();
            var serverField = data && data.field ? document.getElementById(data.field) : null;
            if (serverField) {
                setFieldError(serverField, renderedError(serverField, data.error));
                serverField.focus();
            } else if (errorPanel) {
                if (errorSlot) {
                    errorSlot.textContent = (data && data.error) || errorDefault;
                }
                errorPanel.hidden = false;
                errorPanel.focus();
            }
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var firstBad = null;
            function check(input, ok, message) {
                if (input && !ok) {
                    setFieldError(input, message);
                    firstBad = firstBad || input;
                }
            }
            check(siteName, siteName && siteName.value.trim() !== '', renderedError(siteName));
            check(adminUser, adminUser && userPattern.test(adminUser.value), renderedError(adminUser));
            check(email, email && emailPattern.test(email.value), renderedError(email));
            if (firstBad) {
                firstBad.focus();
                return;
            }

            if (errorPanel) {
                errorPanel.hidden = true;
            }
            if (!ui.progress || !list || !ui.track || !ui.fill) {
                return;
            }

            var plan = readPlan(form, ui.country);
            if (!plan.length) {
                return;
            }
            var rows = buildRows(list, plan);
            ui.fill.style.transform = 'scaleX(0)';
            ui.track.setAttribute('aria-valuenow', '0');
            ui.track.classList.remove('is-finished');
            ui.track.removeAttribute('aria-valuetext');
            if (ui.fatal) {
                ui.fatal.hidden = true;
            }
            if (ui.reload) {
                ui.reload.hidden = true;
            }
            if (ui.slow) {
                ui.slow.classList.remove('is-on');
            }
            if (title) {
                title.textContent = (title.getAttribute('data-template') || '%s').replace('%s', siteName ? siteName.value.trim() : '');
            }
            form.hidden = true;
            ui.progress.hidden = false;
            guardLeave(true);
            setRail(5);
            if (title) {
                title.focus();
            }
            runPlan(plan, rows, ui, onAccountFail, function (account, lost) {
                submitFinish(form, account.emailStatus, account.password, lost);
            });
        });
    }

    /* -----------------------------------------------------------------
       Theme toggle: stored choice wins, else follow the system setting.
       ------------------------------------------------------------- */
    function initThemeToggle() {
        var button = document.getElementById('ins-theme-toggle');
        var store = window.insTheme;
        if (!button || !store) {
            return;
        }
        var root = document.documentElement;
        var query = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

        function apply(theme) {
            root.setAttribute('data-bs-theme', theme);
            button.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        }

        button.addEventListener('click', function () {
            var next = store.resolve() === 'dark' ? 'light' : 'dark';
            store.set(next);
            apply(next);
        });

        if (query) {
            var onChange = function () {
                if (!store.get()) {
                    apply(store.resolve());
                }
            };
            if (query.addEventListener) {
                query.addEventListener('change', onChange);
            } else if (query.addListener) {
                query.addListener(onChange);
            }
        }
        apply(store.resolve());
    }

    /* -----------------------------------------------------------------
       Bottom fade on the stage body, only while it really overflows.
       ------------------------------------------------------------- */
    function initScrollFade() {
        var bodies = document.querySelectorAll('.ins-stage-body');
        Array.prototype.forEach.call(bodies, function (body) {
            function update() {
                body.classList.toggle('is-scrollable', body.scrollHeight > body.clientHeight + 2);
            }
            if (window.ResizeObserver) {
                var observer = new ResizeObserver(update);
                observer.observe(body);
                Array.prototype.forEach.call(body.children, function (child) {
                    observer.observe(child);
                });
            }
            body.addEventListener('toggle', update, true);
            window.addEventListener('resize', update);
            update();
        });
    }

    // The done page opens on its headline so the screen reader starts there.
    function focusDoneTitle() {
        var heading = document.getElementById('ins-done-title');
        if (heading) {
            heading.focus({ preventScroll: true });
        }
    }

    function focusFirstInvalid() {
        var bad = document.querySelector('.ins-stage [aria-invalid="true"]');
        if (bad) {
            bad.focus();
        }
    }

    /* -----------------------------------------------------------------
       Plain posts (steps 1 and 3) and "Check again": one press only. The
       browser's back button brings the page back usable.
       ------------------------------------------------------------- */
    function initSinglePress() {
        ['ins-requirements-form', 'ins-site-type-form'].forEach(function (id) {
            var form = document.getElementById(id);
            if (!form) {
                return;
            }
            form.addEventListener('submit', function (event) {
                if (form.getAttribute('data-sent') === '1') {
                    event.preventDefault();
                    return;
                }
                form.setAttribute('data-sent', '1');
            });
        });
        var again = document.getElementById('ins-check-again');
        var againLabel = again ? again.textContent : '';
        if (again) {
            again.addEventListener('click', function (event) {
                if (again.getAttribute('aria-disabled') === 'true') {
                    event.preventDefault();
                    return;
                }
                again.setAttribute('aria-disabled', 'true');
                again.textContent = text('checking', 'Checking…');
            });
        }
        window.addEventListener('pageshow', function (event) {
            if (!event.persisted) {
                return;
            }
            document.querySelectorAll('form[data-sent]').forEach(function (form) {
                form.removeAttribute('data-sent');
            });
            if (again) {
                again.removeAttribute('aria-disabled');
                again.textContent = againLabel;
            }
        });
    }

    // Step 1: initLocaleSelect. Step 2: initCreateDbToggle, initDatabaseForm, initDbTest. Step 3: initSiteType. Step 4: initTargetForm.
    document.addEventListener('DOMContentLoaded', function () {
        initLocaleSelect();
        initReveal();
        initGenerate();
        initCopy();
        initDismissiblePanels();
        initCreateDbToggle();
        initDatabaseForm();
        initDbTest();
        initSiteType();
        initTargetForm();
        initSinglePress();
        initThemeToggle();
        initScrollFade();
        focusFirstInvalid();
        focusDoneTitle();
    });
})();
