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

    var form = document.querySelector('form[data-osc-push]');
    if (!form) {
        return;
    }
    if (!('serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window)) {
        return;
    }

    var data = form.dataset;
    var button = form.querySelector('button[type="submit"]');
    var iconOnly = button.textContent.trim() === '';
    var note = form.querySelector('[data-osc-push-note]');
    var workerUrl = new URL(data.workerUrl, location.href).href;
    var active = false;
    var busy = false;

    var keyBytes = function (text) {
        var b64 = String(text).replace(/-/g, '+').replace(/_/g, '/');
        b64 += '===='.slice(0, (4 - b64.length % 4) % 4);
        var raw = atob(b64);
        var bytes = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) {
            bytes[i] = raw.charCodeAt(i);
        }
        return bytes;
    };

    var label = function (on) {
        var text = on ? data.onLabel : data.offLabel;
        if (iconOnly) {
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            button.setAttribute('aria-label', text);
            button.title = text;
        } else {
            button.textContent = text;
        }
    };

    var show = function (on, text) {
        active = on;
        label(on);
        note.textContent = text || (on ? data.onNote : '');
    };

    var blocked = function () {
        active = false;
        label(false);
        button.disabled = true;
        note.textContent = data.blockedNote;
    };

    var failed = function (wasOn) {
        if (Notification.permission === 'denied') {
            blocked();
            return;
        }
        show(wasOn, data.errorNote);
    };

    // Only our worker counts: another one the theme registers holds no subscription of ours.
    var ours = function (reg) {
        var worker = reg && (reg.active || reg.waiting || reg.installing);
        return worker && worker.scriptURL.indexOf('action=push_worker') !== -1 ? reg : null;
    };

    var registration = function () {
        return navigator.serviceWorker.getRegistration(new URL('.', workerUrl).href).then(ours);
    };

    var activated = function (reg) {
        if (reg.active) {
            return Promise.resolve(reg);
        }
        var worker = reg.installing || reg.waiting;
        return new Promise(function (resolve, reject) {
            worker.addEventListener('statechange', function () {
                if (worker.state === 'activated') {
                    resolve(reg);
                } else if (worker.state === 'redundant') {
                    reject(new Error('worker not installed'));
                }
            });
        });
    };

    var post = function (url, endpoint) {
        // The form's own page and action would override the AJAX ones in the URL.
        var fields = new FormData(form);
        fields.delete('page');
        fields.delete('action');
        fields.append('endpoint', endpoint);
        return fetch(url, {
            method: 'POST',
            body: fields,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('push ' + response.status);
            }
            return response;
        });
    };

    // Safari grants permission only from inside the click, so ask before anything is awaited.
    var askPermission = function () {
        return new Promise(function (resolve) {
            var answer = Notification.requestPermission(resolve);
            if (answer && typeof answer.then === 'function') {
                answer.then(resolve, function () {
                    resolve(Notification.permission);
                });
            }
        });
    };

    var turnOn = function () {
        return askPermission().then(function (permission) {
            if (permission !== 'granted') {
                throw new Error('permission ' + permission);
            }
            return navigator.serviceWorker.register(workerUrl);
        }).then(activated).then(function (reg) {
            return reg.pushManager.getSubscription().then(function (sub) {
                return sub || reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: keyBytes(data.key)});
            });
        }).then(function (sub) {
            return post(data.subscribeUrl, sub.endpoint).catch(function (err) {
                // The server did not keep it, so the browser should not either.
                return sub.unsubscribe().then(function () {
                    throw err;
                }, function () {
                    throw err;
                });
            });
        }).then(function () {
            show(true);
        });
    };

    var turnOff = function () {
        return registration().then(function (reg) {
            return reg ? reg.pushManager.getSubscription() : null;
        }).then(function (sub) {
            if (!sub) {
                return null;
            }
            return post(data.unsubscribeUrl, sub.endpoint).then(function () {
                return sub.unsubscribe();
            });
        }).then(function () {
            show(false);
        });
    };

    var status = function (sub) {
        var sep = data.statusUrl.indexOf('?') === -1 ? '?' : '&';
        return fetch(data.statusUrl + sep + 'endpoint=' + encodeURIComponent(sub.endpoint), {
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
        }).then(function (response) {
            return response.ok ? response.json() : {};
        }).then(function (answer) {
            if (answer && answer.active === false) {
                // Another user's, or one the site forgot: this browser should not keep it.
                return sub.unsubscribe().then(function () {
                    return false;
                }, function () {
                    return false;
                });
            }
            return Boolean(answer && answer.active === true);
        });
    };

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (busy) {
            return;
        }
        busy = true;
        button.disabled = true;
        var wasOn = active;
        (wasOn ? turnOff() : turnOn()).catch(function () {
            failed(wasOn);
        }).then(function () {
            busy = false;
            if (Notification.permission !== 'denied') {
                button.disabled = false;
            }
        });
    });

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest && event.target.closest('[data-osc-push-toggle]');
        if (toggle && !form.hidden && !button.disabled) {
            event.preventDefault();
            button.click();
        }
    });

    if (Notification.permission === 'denied') {
        blocked();
        form.hidden = false;
        return;
    }

    show(false);
    registration().then(function (reg) {
        if (!reg) {
            return false;
        }
        return reg.pushManager.getSubscription().then(function (sub) {
            return sub ? status(sub) : false;
        }).then(function (on) {
            var worker = reg.active || reg.waiting || reg.installing;
            if (on && worker.scriptURL !== workerUrl) {
                // The worker's texts and user live in its URL: a new language or site name updates it.
                navigator.serviceWorker.register(workerUrl).catch(function () {});
            }
            return on;
        });
    }).then(function (on) {
        show(on);
    }, function () {
        show(false);
    }).then(function () {
        form.hidden = false;
    });
}());
