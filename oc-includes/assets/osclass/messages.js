/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
(function () {
    'use strict';

    var list = document.querySelector('[data-osc-messages]');
    var form = document.querySelector('form.oe-compose');
    if (!list) {
        return;
    }

    var pad = function (n) {
        return String(n).padStart(2, '0');
    };

    var formatDate = function (stamp) {
        var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/.exec(stamp);
        if (!m) {
            return stamp;
        }
        var d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]);
        var lang = document.documentElement.lang || undefined;
        var text = function (opts) {
            return new Intl.DateTimeFormat(lang, opts).format(d);
        };
        var hours12 = d.getHours() % 12 || 12;
        var tokens = {
            d: pad(d.getDate()),
            j: String(d.getDate()),
            m: pad(d.getMonth() + 1),
            n: String(d.getMonth() + 1),
            Y: String(d.getFullYear()),
            y: pad(d.getFullYear() % 100),
            H: pad(d.getHours()),
            G: String(d.getHours()),
            h: pad(hours12),
            g: String(hours12),
            i: pad(d.getMinutes()),
            s: pad(d.getSeconds()),
            A: d.getHours() < 12 ? 'AM' : 'PM',
            a: d.getHours() < 12 ? 'am' : 'pm',
            M: text({month: 'short'}),
            F: text({month: 'long'}),
            D: text({weekday: 'short'}),
            l: text({weekday: 'long'})
        };
        return (list.dataset.format || 'Y-m-d H:i').replace(/\\.|[a-zA-Z]/g, function (c) {
            if (c.charAt(0) === '\\' && c.length === 2) {
                return c.charAt(1);
            }
            return Object.prototype.hasOwnProperty.call(tokens, c) ? tokens[c] : c;
        });
    };

    var appendMessage = function (row, held) {
        if (list.querySelector('[data-id="' + Number(row.id) + '"]')) {
            return;
        }
        var mine = Number(row.sender) === Number(list.dataset.me);

        var item = document.createElement('li');
        item.className = 'oe-message' + (mine ? ' is-mine' : '') + (held ? ' is-held' : '');
        item.dataset.id = String(Number(row.id));

        var body = document.createElement('div');
        body.className = 'oe-message-body';
        body.textContent = row.body;

        var meta = document.createElement('p');
        meta.className = 'oe-message-meta';
        var who = document.createElement('span');
        who.textContent = mine ? list.dataset.meLabel : list.dataset.otherLabel;
        var when = document.createElement('time');
        when.setAttribute('datetime', row.at);
        when.textContent = formatDate(row.at);
        meta.append(who, when);
        if (held) {
            var badge = document.createElement('span');
            badge.className = 'oe-badge pending';
            badge.textContent = list.dataset.heldLabel;
            meta.append(badge);
        }

        item.append(body, meta);
        list.append(item);
        list.dataset.lastId = String(Number(row.id));
        item.scrollIntoView({block: 'nearest'});
    };

    window.oscMessages = {appendMessage: appendMessage};

    if (!form) {
        return;
    }

    var showError = function (text) {
        var box = form.querySelector('.oe-form-error');
        if (!box) {
            box = document.createElement('p');
            box.className = 'oe-form-error';
            box.setAttribute('role', 'alert');
            form.prepend(box);
        }
        box.textContent = text;
    };

    var clearError = function () {
        var box = form.querySelector('.oe-form-error');
        if (box) {
            box.remove();
        }
    };

    form.addEventListener('submit', function (event) {
        var field = form.elements.body;
        if (!field || field.value.trim() === '') {
            return;
        }
        event.preventDefault();
        clearError();

        var button = form.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
        }

        // The form's own page and action would override the AJAX ones in the URL.
        var fields = new FormData(form);
        fields.delete('page');
        fields.delete('action');

        fetch(list.dataset.sendUrl, {
            method: 'POST',
            body: fields,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (data) {
                return {ok: response.ok, data: data};
            });
        }).then(function (result) {
            if (result.ok && result.data.ok && result.data.message) {
                appendMessage(result.data.message, result.data.held === true);
                field.value = '';
                var empty = document.querySelector('.oe-account-main > .oe-empty');
                if (empty) {
                    empty.remove();
                }
                return;
            }
            showError(result.data.msg || form.dataset.errorText || 'Could not send the message.');
        }).catch(function () {
            showError(form.dataset.errorText || 'Could not send the message.');
        }).finally(function () {
            if (button) {
                button.disabled = false;
            }
        });
    });
}());
