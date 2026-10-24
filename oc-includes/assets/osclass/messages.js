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
    var inbox = document.querySelector('[data-osc-inbox]');
    var form = document.querySelector('form.oe-compose');
    // Away from the messages pages the header link carries the check URL, and only the badges update.
    var root = list || inbox || document.querySelector('[data-osc-messages-link][data-check-url]');
    // The header link and the messages page may both load this file; the first run does the work.
    if (!root || root.hasAttribute('data-osc-started')) {
        return;
    }
    root.setAttribute('data-osc-started', '');

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
            return false;
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
        return true;
    };

    // Every unread badge on the page, such as the header's osc_messaging_link(), follows the poll.
    var setUnread = function (count) {
        count = Math.max(0, Math.floor(Number(count) || 0));
        document.querySelectorAll('[data-osc-unread]').forEach(function (badge) {
            badge.textContent = String(count);
            badge.hidden = count === 0;
            var link = badge.closest('[data-osc-messages-link]');
            if (link && link.dataset.label) {
                link.setAttribute('aria-label', count > 0
                    ? (link.dataset.labelUnread || link.dataset.label).replace('%d', String(count))
                    : link.dataset.label);
            }
        });
    };

    if (list) {
        window.oscMessages = {appendMessage: appendMessage};
    }

    // New messages: a check every 15 s while the tab is visible, or one EventSource when the
    // page has a live URL, with a check each minute in case a note was lost.
    var startLive = function () {
        var checkUrl = root.dataset.checkUrl;
        if (!checkUrl) {
            return;
        }
        var liveUrl = root.dataset.liveUrl || '';
        var baseDelay = list || inbox ? 15000 : 60000;
        var delay = baseDelay;
        var etag = null;
        var inFlight = false;
        var timer = null;
        var stream = null;
        var errors = [];

        var visible = function () {
            return document.visibilityState === 'visible';
        };

        var refreshThread = function () {
            var url = list.dataset.sinceUrl + '&id=' + encodeURIComponent(list.dataset.conversation)
                + '&after=' + encodeURIComponent(list.dataset.lastId);
            return fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}}).then(function (response) {
                if (!response.ok) {
                    throw new Error('since ' + response.status);
                }
                return response.json();
            }).then(function (data) {
                var read = 0;
                (data.messages || []).forEach(function (row) {
                    if (appendMessage(row, false) && Number(row.sender) !== Number(list.dataset.me)) {
                        read++;
                    }
                });
                return read;
            });
        };

        var refreshInbox = function () {
            return fetch(location.href, {credentials: 'same-origin'}).then(function (response) {
                if (!response.ok) {
                    throw new Error('inbox ' + response.status);
                }
                return response.text();
            }).then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-osc-inbox]');
                if (fresh) {
                    root.replaceChildren.apply(root, Array.from(fresh.childNodes).map(function (node) {
                        return document.importNode(node, true);
                    }));
                }
            });
        };

        var check = function () {
            var headers = {};
            if (etag) {
                headers['If-None-Match'] = etag;
            }
            return fetch(checkUrl, {headers: headers, credentials: 'same-origin'}).then(function (response) {
                if (response.status === 304) {
                    return null;
                }
                if (!response.ok) {
                    throw new Error('check ' + response.status);
                }
                // Keep the old tag until the refresh lands, so a failed refresh is retried.
                var fresh = response.headers.get('ETag') || etag;
                var activity = response.json().catch(function () {
                    return {};
                });
                var refresh = list ? refreshThread() : (inbox ? refreshInbox() : 0);
                return Promise.all([activity, refresh]).then(function (done) {
                    etag = fresh;
                    // On a thread, fetching the new messages marked them read, so they leave the count.
                    if (done[0] && typeof done[0].unread === 'number') {
                        setUnread(done[0].unread - (Number(done[1]) || 0));
                    }
                });
            });
        };

        var run = function () {
            if (inFlight) {
                return Promise.resolve();
            }
            inFlight = true;
            return check().then(function () {
                delay = baseDelay;
            }, function () {
                delay = Math.min(delay * 2, 120000);
            }).then(function () {
                inFlight = false;
            });
        };

        var stopTimer = function () {
            if (timer !== null) {
                clearTimeout(timer);
                timer = null;
            }
        };

        var schedule = function () {
            stopTimer();
            if (!visible()) {
                return;
            }
            timer = setTimeout(function () {
                timer = null;
                run().then(schedule);
            }, stream ? Math.max(delay, 60000) : delay);
        };

        document.addEventListener('visibilitychange', function () {
            stopTimer();
            if (visible()) {
                run().then(schedule);
            }
        });

        if (liveUrl !== '' && typeof window.EventSource === 'function') {
            stream = new EventSource(liveUrl);
            var onLive = function () {
                if (visible()) {
                    run();
                }
            };
            stream.addEventListener('open', onLive);
            stream.addEventListener('message', onLive);
            stream.addEventListener('error', function () {
                // An HTTP error (401, 404, 502) closes the stream for good, so fall back to polling now.
                if (stream.readyState === EventSource.CLOSED) {
                    stream = null;
                    schedule();
                    return;
                }
                var now = Date.now();
                errors.push(now);
                errors = errors.filter(function (at) {
                    return now - at <= 60000;
                });
                if (errors.length >= 3) {
                    stream.close();
                    stream = null;
                    schedule();
                }
            });
        }
        schedule();
    };

    startLive();

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
