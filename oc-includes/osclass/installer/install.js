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
        } catch (e) {
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

    function announce(message) {
        var el = document.getElementById('ins-copy-announcer');
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
        var wasReadOnly = input.hasAttribute('readonly');
        input.focus();
        input.select();
        input.setSelectionRange(0, 999999);
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
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
                    navigator.clipboard.writeText(input.value).then(
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
       Step 2: "Test connection" — advisory, never blocks "Connect"
       ------------------------------------------------------------- */
    function initDbTest() {
        var btn = document.getElementById('ins-test-btn');
        var form = document.getElementById('ins-database-form');
        var resultEl = document.getElementById('ins-test-result');
        if (!btn || !form || !resultEl) {
            return;
        }

        function clearFieldFlags() {
            DB_FIELDS.forEach(function (name) {
                setFieldError(form.elements[name], null);
            });
        }

        btn.addEventListener('click', function () {
            clearFieldFlags();
            resultEl.textContent = '';
            resultEl.className = 'ins-test-result';
            setBusy(btn, true, text('testing', 'Testing…'));

            var params = formToUrlEncoded(form, { action: 'test_db' });

            fetch('install.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString(),
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    resultEl.textContent = data.message || '';
                    resultEl.classList.add('ins-test-result-' + (data.level || 'error'));
                    if (data.field && form.elements[data.field]) {
                        setFieldError(form.elements[data.field], renderedError(form.elements[data.field], data.message));
                    }
                })
                .catch(function () {
                    resultEl.textContent = text('networkError', 'Something went wrong. Check your connection and try again.');
                    resultEl.classList.add('ins-test-result-error');
                })
                .then(function () {
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
    }

    /* -----------------------------------------------------------------
       Step 4: site + admin form, submitted via fetch, then a synthetic
       POST to install.php carrying the result to step 5.
       ------------------------------------------------------------- */
    function initTargetForm() {
        var form = document.getElementById('ins-target-form');
        if (!form) {
            return;
        }
        var wrap = document.getElementById('ins-target-wrap');
        var overlay = document.getElementById('ins-setup-overlay');
        var errorPanel = document.getElementById('ins-target-error');

        var siteName = document.getElementById('webtitle');
        var adminUser = document.getElementById('admin_user');
        var email = document.getElementById('email');

        var userPattern = /^[A-Za-z0-9]+$/;
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        [siteName, adminUser, email].forEach(clearErrorOnInput);

        function showOverlay() {
            if (wrap) {
                wrap.setAttribute('aria-busy', 'true');
            }
            form.inert = true;
            if (overlay) {
                overlay.hidden = false;
            }
        }

        function hideOverlay() {
            if (wrap) {
                wrap.removeAttribute('aria-busy');
            }
            form.inert = false;
            if (overlay) {
                overlay.hidden = true;
            }
        }

        function showServerError(message) {
            if (!errorPanel) {
                return;
            }
            errorPanel.textContent = message;
            errorPanel.hidden = false;
        }

        // Steps whose request got no answer (network error, time limit, not JSON); the finish page lists them.
        var lost = [];

        function submitFinish(emailStatus, password, locationError) {
            var nextForm = document.createElement('form');
            nextForm.method = 'POST';
            nextForm.action = 'install.php';
            nextForm.style.display = 'none';
            addHiddenField(nextForm, 'step', '5');
            addHiddenField(nextForm, 'result', emailStatus || '');
            addHiddenField(nextForm, 'password', password || '');
            if (locationError) {
                addHiddenField(nextForm, 'error_location', '1');
            }
            lost.forEach(function (token) {
                addHiddenField(nextForm, 'lost[]', token);
            });
            var nonceField = form.elements.install_nonce;
            addHiddenField(nextForm, 'install_nonce', nonceField ? nonceField.value : '');
            document.body.appendChild(nextForm);
            nextForm.submit();
        }

        var queue = [];
        try {
            queue = JSON.parse(form.getAttribute('data-shop-queue') || '[]');
        } catch (e) {
            queue = [];
        }
        var overlayText = document.getElementById('ins-overlay-text');

        function shopRequest(fields) {
            var params = new URLSearchParams(fields);
            var nonceField = form.elements.install_nonce;
            params.set('install_nonce', nonceField ? nonceField.value : '');
            return fetch('install-location.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString(),
            }).then(
                function (response) {
                    return response.json();
                },
                function () {
                    return null;
                }
            ).catch(function () {
                return null;
            });
        }

        // One package or one batch of sample listings per request, so no request runs into a
        // shared host's time limit. A failure does not stop the queue; the server keeps it for the finish page.
        function buildSteps(sampleBatches) {
            var steps = [];
            if (sampleBatches > 0) {
                steps.push({ fields: { action: 'sample_photos' }, lost: 'sample_photos', label: text('samplePhotos', 'Downloading sample photos…') });
            }
            for (var b = 0; b < sampleBatches; b++) {
                steps.push({
                    fields: { action: 'sample_batch', batch: String(b) },
                    lost: 'sample_batch',
                    label: text('sampleBatch', 'Adding sample listings… (%1$s of %2$s)')
                        .replace('%1$s', String(b + 1))
                        .replace('%2$s', String(sampleBatches)),
                });
            }
            var packages = queue.filter(function (item) {
                return item.action === 'install_package';
            });
            queue.forEach(function (item) {
                var label = text('shopMode', 'Turning on shop mode…');
                if (item.action === 'install_package') {
                    label = text('installing', 'Installing %1$s… (%2$s of %3$s)')
                        .replace('%1$s', item.name)
                        .replace('%2$s', String(packages.indexOf(item) + 1))
                        .replace('%3$s', String(packages.length));
                }
                steps.push({
                    fields: { action: item.action, type: item.type, slug: item.slug },
                    lost: item.action === 'install_package' ? item.type + ':' + item.slug : 'activate',
                    label: label,
                });
            });
            return steps;
        }

        function runSteps(steps, index, done) {
            if (index >= steps.length) {
                done();
                return;
            }
            if (overlayText) {
                overlayText.textContent = steps[index].label;
            }
            shopRequest(steps[index].fields).then(function (data) {
                if (!data && lost.indexOf(steps[index].lost) === -1) {
                    lost.push(steps[index].lost);
                }
                runSteps(steps, index + 1, done);
            });
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
            showOverlay();

            var params = formToUrlEncoded(form);

            fetch('install-location.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString(),
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data && data.status) {
                        runSteps(buildSteps(parseInt(data.sample_batches, 10) || 0), 0, function () {
                            submitFinish(data.email_status, data.password, data.location_error);
                        });
                        return;
                    }
                    hideOverlay();
                    var serverField = data && data.field ? document.getElementById(data.field) : null;
                    if (serverField) {
                        setFieldError(serverField, renderedError(serverField, data.error));
                        serverField.focus();
                        return;
                    }
                    showServerError((data && data.error) || text('networkError', 'Something went wrong. Check your connection and try again.'));
                })
                .catch(function () {
                    hideOverlay();
                    showServerError(text('networkError', 'Something went wrong. Check your connection and try again.'));
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

    function focusFirstInvalid() {
        var bad = document.querySelector('.ins-stage [aria-invalid="true"]');
        if (bad) {
            bad.focus();
        }
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
        initThemeToggle();
        initScrollFade();
        focusFirstInvalid();
    });
})();
