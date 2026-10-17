/*
 * The theme's plugins screen: installs each picked plugin in its own request, one after
 * another, then activates the theme once every required plugin is on.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var app = document.getElementById('theme-dependencies');
        var run = app ? app.querySelector('[data-dep-run]') : null;
        if (!app || !run) {
            return;
        }
        var i18n = {};
        try {
            i18n = JSON.parse(app.getAttribute('data-i18n') || '{}');
        } catch (e) {
            i18n = {};
        }
        var statusLine = app.querySelector('[data-dep-status-line]');

        function setState(row, state, word) {
            var badge = row.querySelector('.osc-pkg-state');
            if (!badge) {
                return;
            }
            badge.className = 'osc-pkg-state status-' + state;
            var text = badge.querySelector('.osc-status');
            if (text) {
                text.textContent = word;
            }
        }

        function showNote(row, message, manualUrl) {
            var note = row.querySelector('[data-dep-note]');
            var text = row.querySelector('[data-dep-message]');
            var link = row.querySelector('[data-dep-manual]');
            if (!note || !text) {
                return;
            }
            text.textContent = message || '';
            note.hidden = !message;
            if (link) {
                if (manualUrl) {
                    link.href = manualUrl;
                }
                link.hidden = !message;
            }
        }

        function installRow(row) {
            var slug = row.getAttribute('data-dep-slug');
            setState(row, 'update', i18n.working || '');
            showNote(row, '', '');
            return fetch(app.getAttribute('data-install-url') + '&slug=' + encodeURIComponent(slug), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                return r.json();
            }).then(function (res) {
                if (res && res.ok) {
                    row.setAttribute('data-dep-status', 'active');
                    setState(row, 'active', i18n.active || '');
                    return;
                }
                setState(row, 'incompatible', i18n.failed || '');
                showNote(row, (res && res.message) || i18n.error, res && res.manual_url);
            }).catch(function () {
                setState(row, 'incompatible', i18n.failed || '');
                showNote(row, i18n.error, '');
            });
        }

        function anyFailed(rows) {
            return rows.some(function (row) {
                return row.getAttribute('data-dep-status') !== 'active';
            });
        }

        function requiredReady() {
            var rows = app.querySelectorAll('[data-dep-slug][data-dep-required="1"]');
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].getAttribute('data-dep-status') !== 'active') {
                    return false;
                }
            }
            return true;
        }

        run.addEventListener('click', function () {
            var queue = Array.prototype.filter.call(app.querySelectorAll('[data-dep-slug]'), function (row) {
                if (row.getAttribute('data-dep-action') === 'none') {
                    return false;
                }
                var pick = row.querySelector('[data-dep-pick]');
                return !pick || pick.checked;
            });
            run.disabled = true;
            if (statusLine) {
                statusLine.textContent = i18n.working || '';
            }

            queue.reduce(function (chain, row) {
                return chain.then(function () {
                    return installRow(row);
                });
            }, Promise.resolve()).then(function () {
                if (requiredReady() && anyFailed(queue) && statusLine) {
                    run.disabled = false;
                    statusLine.textContent = (i18n.optionalFailed || '') + ' ';
                    var go = document.createElement('a');
                    go.href = app.getAttribute('data-activate-url');
                    go.textContent = i18n.activateAnyway || '';
                    statusLine.appendChild(go);
                    return;
                }
                if (requiredReady()) {
                    if (statusLine) {
                        statusLine.textContent = i18n.done || '';
                    }
                    window.location.href = app.getAttribute('data-activate-url');
                    return;
                }
                run.disabled = false;
                if (statusLine) {
                    statusLine.textContent = i18n.stillOff || '';
                }
            });
        });
    });
}());
