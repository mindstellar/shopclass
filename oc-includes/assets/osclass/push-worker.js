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

    // A push carries no data: the worker asks the site how many messages wait, and says only that.
    var params = new URL(self.location.href).searchParams;

    var scope = function () {
        return self.registration.scope;
    };

    var ajax = function (action) {
        return scope() + 'index.php?page=ajax&action=' + action;
    };

    var text = function (name, fallback) {
        return params.get(name) || fallback;
    };

    // The inbox to open on a click; only a page of this site.
    var inbox = function () {
        try {
            var url = new URL(params.get('inbox') || '', scope());
            if (url.origin === new URL(scope()).origin) {
                return url.href;
            }
        } catch {
            // Fall through to the site root.
        }
        return scope();
    };

    var notify = function (body) {
        return self.registration.showNotification(text('title', new URL(scope()).host), {
            body: body,
            tag: 'messages',
            renotify: true,
            data: {url: inbox()}
        });
    };

    var count = function (n) {
        return text(n === 1 ? 'one' : 'many', '%d new messages').replace('%d', String(n));
    };

    var fallback = function () {
        return text('fallback', 'You have new messages');
    };

    var keyBytes = function (b64) {
        var s = String(b64).replace(/-/g, '+').replace(/_/g, '/');
        s += '===='.slice(0, (4 - s.length % 4) % 4);
        var raw = atob(s);
        var bytes = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) {
            bytes[i] = raw.charCodeAt(i);
        }
        return bytes;
    };

    // A new copy (new texts in its URL) takes over at once; there is nothing to hand over.
    self.addEventListener('install', function () {
        self.skipWaiting();
    });

    // Every push must end in a notification, or the browser shows its own and may revoke push.
    self.addEventListener('push', function (event) {
        // Asked for the user who turned it on: another user signed in here gets no count of theirs.
        var user = params.get('user');
        event.waitUntil(self.fetch(ajax('messages_check') + (user ? '&for=' + encodeURIComponent(user) : ''), {
            credentials: 'include',
            cache: 'no-store',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
        }).then(function (response) {
            if (response.status === 401) {
                return notify(text('signin', 'Sign in to read your messages'));
            }
            if (!response.ok) {
                return notify(fallback());
            }
            return response.json().then(function (answer) {
                var n = Number(answer && answer.unread) || 0;
                return notify(n > 0 ? count(n) : fallback());
            });
        }).catch(function () {
            return notify(fallback());
        }));
    });

    // A tab already on the inbox is brought forward; any other tab is left as it is.
    self.addEventListener('notificationclick', function (event) {
        event.notification.close();
        var url = (event.notification.data && event.notification.data.url) || inbox();
        event.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then(function (list) {
            for (var i = 0; i < list.length; i++) {
                if (list[i].url.indexOf(url) === 0 && typeof list[i].focus === 'function') {
                    return list[i].focus();
                }
            }
            return self.clients.openWindow(url);
        }));
    });

    // The CSRF fields come from a fresh copy of the inbox, the page that carries the switch.
    var tokens = function () {
        return self.fetch(inbox(), {credentials: 'include', cache: 'no-store'}).then(function (response) {
            return response.text();
        }).then(function (html) {
            var fields = new FormData();
            ['CSRFName', 'CSRFToken'].forEach(function (name) {
                var m = new RegExp('name=[\'"]' + name + '[\'"]\\s+value=[\'"]([^\'"]+)').exec(html);
                if (m) {
                    fields.append(name, m[1]);
                }
            });
            return fields;
        });
    };

    self.addEventListener('pushsubscriptionchange', function (event) {
        var old = event.oldSubscription;
        var key = (old && old.options && old.options.applicationServerKey) || keyBytes(params.get('key') || '');
        var renewed = event.newSubscription
            ? Promise.resolve(event.newSubscription)
            : self.registration.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: key});
        event.waitUntil(renewed.then(function (sub) {
            return tokens().then(function (fields) {
                fields.append('endpoint', sub.endpoint);
                return self.fetch(ajax('push_subscribe'), {
                    method: 'POST',
                    body: fields,
                    credentials: 'include',
                    headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
                });
            });
        }).catch(function () {
            // The page shows the switch as off on the next visit; turning it on again registers it.
        }));
    });
}());
