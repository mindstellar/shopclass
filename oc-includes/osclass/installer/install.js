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

    /* -----------------------------------------------------------------
       Step 2: "Test connection" — advisory, never blocks "Continue"
       ------------------------------------------------------------- */
    function initDbTest() {
        var btn = document.getElementById('ins-test-btn');
        var form = document.getElementById('ins-database-form');
        var resultEl = document.getElementById('ins-test-result');
        if (!btn || !form || !resultEl) {
            return;
        }

        var fieldNames = [
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

        function clearFieldFlags() {
            fieldNames.forEach(function (name) {
                var el = form.elements[name];
                if (el) {
                    el.removeAttribute('aria-invalid');
                }
            });
        }

        fieldNames.forEach(function (name) {
            var el = form.elements[name];
            if (el) {
                el.addEventListener('input', function () {
                    el.removeAttribute('aria-invalid');
                });
            }
        });

        btn.addEventListener('click', function () {
            var originalLabel = btn.textContent.trim();
            clearFieldFlags();
            resultEl.textContent = '';
            resultEl.className = 'ins-test-result';
            btn.disabled = true;
            btn.classList.add('is-loading');
            btn.querySelector('.ins-btn-label').textContent = text('testing', 'Testing…');

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
                    if (data.field) {
                        var el = form.elements[data.field];
                        if (el) {
                            el.setAttribute('aria-invalid', 'true');
                        }
                    }
                })
                .catch(function () {
                    resultEl.textContent = text('networkError', 'Something went wrong. Check your connection and try again.');
                    resultEl.classList.add('ins-test-result-error');
                })
                .then(function () {
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                    btn.querySelector('.ins-btn-label').textContent = originalLabel;
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

        var adminUser = document.getElementById('admin_user');
        var adminUserError = document.getElementById('admin-user-error');
        var email = document.getElementById('email');
        var emailError = document.getElementById('email-error');

        var userPattern = /^[A-Za-z0-9]+$/;
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        function setInvalid(input, errorEl) {
            input.setAttribute('aria-invalid', 'true');
            if (errorEl) {
                errorEl.hidden = false;
            }
        }

        function clearInvalid(input, errorEl) {
            input.removeAttribute('aria-invalid');
            if (errorEl) {
                errorEl.hidden = true;
            }
        }

        if (adminUser) {
            adminUser.addEventListener('input', function () {
                clearInvalid(adminUser, adminUserError);
            });
        }
        if (email) {
            email.addEventListener('input', function () {
                clearInvalid(email, emailError);
            });
        }

        function showOverlay() {
            if (wrap) {
                wrap.setAttribute('aria-busy', 'true');
            }
            form.hidden = true;
            if (overlay) {
                overlay.hidden = false;
            }
        }

        function hideOverlay() {
            if (wrap) {
                wrap.removeAttribute('aria-busy');
            }
            form.hidden = false;
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
                steps.push({ fields: { action: 'sample_photos' }, label: text('samplePhotos', 'Downloading sample photos…') });
            }
            for (var b = 0; b < sampleBatches; b++) {
                steps.push({
                    fields: { action: 'sample_batch', batch: String(b) },
                    label: text('sampleBatch', 'Adding sample listings… (%1$s of %2$s)')
                        .replace('%1$s', String(b + 1))
                        .replace('%2$s', String(sampleBatches)),
                });
            }
            queue.forEach(function (item, index) {
                steps.push({
                    fields: { action: 'install_package', type: item.type, slug: item.slug },
                    label: text('installing', 'Installing %1$s… (%2$s of %3$s)')
                        .replace('%1$s', item.name)
                        .replace('%2$s', String(index + 1))
                        .replace('%3$s', String(queue.length)),
                });
            });
            if (queue.length) {
                steps.push({ fields: { action: 'activate_theme' }, label: text('shopMode', 'Turning on shop mode…') });
            }
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
            shopRequest(steps[index].fields).then(function () {
                runSteps(steps, index + 1, done);
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var hasError = false;
            if (adminUser && !userPattern.test(adminUser.value)) {
                setInvalid(adminUser, adminUserError);
                hasError = true;
            }
            if (email && !emailPattern.test(email.value)) {
                setInvalid(email, emailError);
                hasError = true;
            }
            if (hasError) {
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
                    showServerError((data && data.error) || text('networkError', 'Something went wrong. Check your connection and try again.'));
                })
                .catch(function () {
                    hideOverlay();
                    showServerError(text('networkError', 'Something went wrong. Check your connection and try again.'));
                });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initLocaleSelect();
        initReveal();
        initCopy();
        initDismissiblePanels();
        initCreateDbToggle();
        initDbTest();
        initSiteType();
        initTargetForm();
    });
})();
