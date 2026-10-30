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
    var messenger = document.querySelector('.oe-messenger');
    var form = null;
    // Away from the messages pages the header link carries the check URL, and only the badges update.
    var root = list || inbox || document.querySelector('[data-osc-messages-link][data-check-url]');
    // The header link and the messages page may both load this file; the first run does the work.
    if (!root || root.hasAttribute('data-osc-started')) {
        return;
    }
    root.setAttribute('data-osc-started', '');

    var lang = document.documentElement.lang || undefined;
    var TYPING_MS = 5000;
    var TYPING_EVERY = 3000;
    var reqHeaders = {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'};
    // Server seconds less browser seconds, from the thread's data-server-now.
    var clockOffset = 0;
    var seenLabel = '';
    var run = function () {
        return Promise.resolve();
    };
    var has = function (map, key) {
        return Object.prototype.hasOwnProperty.call(map, key);
    };

    var serverSeconds = function () {
        return Date.now() / 1000 + clockOffset;
    };

    var pad = function (n) {
        return String(n).padStart(2, '0');
    };

    // Stamps are "Y-m-d H:i:s" in the site time zone, read as written.
    var parseStamp = function (stamp) {
        var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/.exec(String(stamp || ''));
        if (!m) {
            return null;
        }
        return {
            key: m[1] + '-' + m[2] + '-' + m[3],
            clock: m[4] + ':' + m[5],
            utc: Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0))
        };
    };

    var keyDate = function (key) {
        var p = key.split('-');
        return new Date(+p[0], +p[1] - 1, +p[2]);
    };

    var relativeDay = function (days) {
        var text = new Intl.RelativeTimeFormat(lang, {numeric: 'auto'}).format(days, 'day');
        return text.charAt(0).toLocaleUpperCase(lang) + text.slice(1);
    };

    // The label on a day line, by the rules of MessageTime::day(): Today, Yesterday, else the date.
    var dayLabel = function (key) {
        var now = new Date(serverSeconds() * 1000);
        var today = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
        if (key > today) {
            today = key;
        }
        var days = Math.round((keyDate(today) - keyDate(key)) / 86400000);
        if (days <= 0) {
            return list.dataset.todayLabel || relativeDay(0);
        }
        if (days === 1) {
            return list.dataset.yesterdayLabel || relativeDay(-1);
        }
        return new Intl.DateTimeFormat(lang, {day: 'numeric', month: 'long'}).format(keyDate(key));
    };

    var messageItems = function () {
        return list ? list.querySelectorAll('li.oe-message') : [];
    };

    var stampOf = function (item) {
        if (!item) {
            return '';
        }
        if (item.dataset.at) {
            return item.dataset.at;
        }
        var time = item.querySelector('.oe-message-meta time[datetime]');
        return time ? time.getAttribute('datetime') : '';
    };

    var dropEmptyMeta = function (meta) {
        if (meta && meta.children.length === 0) {
            meta.remove();
        }
    };

    var clearSeen = function () {
        list.querySelectorAll('.oe-seen').forEach(function (seen) {
            var meta = seen.closest('.oe-message-meta');
            seen.remove();
            dropEmptyMeta(meta);
        });
    };

    // "Seen" goes under my last delivered message when nothing from them follows it.
    var markSeen = function () {
        list.dataset.theirUnread = '0';
        clearSeen();
        var items = messageItems();
        for (var i = items.length - 1; i >= 0; i--) {
            if (!items[i].classList.contains('is-mine')) {
                return;
            }
            if (!items[i].classList.contains('is-held')) {
                var meta = items[i].querySelector('.oe-message-meta');
                if (!meta) {
                    meta = document.createElement('p');
                    meta.className = 'oe-message-meta';
                    items[i].append(meta);
                }
                var seen = document.createElement('span');
                seen.className = 'oe-seen';
                seen.textContent = list.dataset.seenLabel || seenLabel;
                meta.append(seen);
                return;
            }
        }
    };

    // Typing: conversation id -> the time (ms) its indicator hides.
    var typingUntil = {};
    var typingTimer = null;

    var typingLabel = function () {
        var set = (list && list.dataset.typingLabel) || (inbox && inbox.dataset.typingLabel);
        if (set) {
            return set;
        }
        var sr = document.querySelector('.oe-typing .oe-sr-only');
        return sr && sr.textContent.trim() !== '' ? sr.textContent.trim() + '…' : '…';
    };

    var paintTyping = function () {
        var now = Date.now();
        var next = Infinity;
        Object.keys(typingUntil).forEach(function (id) {
            if (typingUntil[id] <= now) {
                delete typingUntil[id];
            } else {
                next = Math.min(next, typingUntil[id]);
            }
        });
        var label = typingLabel();

        if (inbox) {
            inbox.querySelectorAll('li.oe-thread').forEach(function (row) {
                var line = row.querySelector('.oe-thread-preview');
                var on = has(typingUntil, row.dataset.id);
                if (!line || on === line.classList.contains('is-typing')) {
                    return;
                }
                if (on) {
                    line.dataset.preview = line.textContent;
                }
                line.textContent = on ? label : (line.dataset.preview || '');
                line.classList.toggle('is-typing', on);
            });
        }

        var bubble = document.querySelector('.oe-typing');
        if (list && bubble) {
            var here = has(typingUntil, String(Number(list.dataset.conversation)));
            bubble.hidden = !here;
            var role = document.querySelector('.oe-chat-role');
            if (role) {
                role.textContent = here ? label : (role.dataset.role || '');
                role.classList.toggle('is-typing', here);
            }
            if (here) {
                list.scrollTop = list.scrollHeight;
            }
        }

        if (typingTimer !== null) {
            clearTimeout(typingTimer);
            typingTimer = null;
        }
        if (next !== Infinity) {
            typingTimer = setTimeout(function () {
                typingTimer = null;
                paintTyping();
            }, next - now);
        }
    };

    var onTyping = function (note) {
        var age = serverSeconds() - Number(note.at);
        // nchan replays its newest note on connect, so an old one says nothing about now.
        if (!isFinite(age) || age > TYPING_MS / 1000) {
            return;
        }
        typingUntil[String(Number(note.conversation))] = Date.now() + TYPING_MS - Math.max(0, age) * 1000;
        paintTyping();
    };

    var appendMessage = function (row, held) {
        if (!list || list.querySelector('li.oe-message[data-id="' + Number(row.id) + '"]')) {
            return false;
        }
        var mine = Number(row.sender) === Number(list.dataset.me);
        var at = String(row.at || '');
        var stamp = parseStamp(at);
        var items = messageItems();
        var prev = items.length ? items[items.length - 1] : null;
        var prevStamp = parseStamp(stampOf(prev));
        // Same rules as the server: a day line when the day changes; one group per sender within 10 min.
        var newDay = stamp !== null && (prev === null || (prevStamp !== null && prevStamp.key !== stamp.key));
        var joins = !newDay && stamp !== null && prevStamp !== null
            && prev.classList.contains('is-mine') === mine
            && Math.abs(stamp.utc - prevStamp.utc) <= 600000;

        if (joins) {
            prev.classList.remove('is-last');
            var prevMeta = prev.querySelector('.oe-message-meta');
            var prevTime = prevMeta && prevMeta.querySelector('time');
            if (prevTime) {
                prevTime.remove();
            }
            dropEmptyMeta(prevMeta);
        }
        // A new message from either side means "Seen" no longer holds.
        clearSeen();

        if (newDay) {
            var day = document.createElement('li');
            day.className = 'oe-day';
            var dayText = document.createElement('span');
            dayText.textContent = dayLabel(stamp.key);
            day.append(dayText);
            list.append(day);
        }

        var item = document.createElement('li');
        item.className = 'oe-message' + (mine ? ' is-mine' : '') + (held ? ' is-held' : '')
            + (joins ? '' : ' is-first') + ' is-last';
        item.dataset.id = String(Number(row.id));
        item.dataset.at = at;

        var clock = stamp ? stamp.clock : at;
        var who = document.createElement('span');
        who.className = 'oe-sr-only';
        who.textContent = (mine ? list.dataset.meLabel : list.dataset.otherLabel) + ', ' + clock + ': ';

        var body = document.createElement('div');
        body.className = 'oe-message-body';
        body.textContent = row.body;

        var meta = document.createElement('p');
        meta.className = 'oe-message-meta';
        var when = document.createElement('time');
        when.setAttribute('datetime', at);
        when.textContent = clock;
        meta.append(when);
        if (held) {
            var badge = document.createElement('span');
            badge.className = 'oe-held';
            badge.textContent = list.dataset.heldLabel;
            meta.append(badge);
        }

        item.append(who, body, meta);
        list.append(item);
        list.dataset.lastId = String(Number(row.id));
        var conversation = String(Number(list.dataset.conversation));
        if (!mine && has(typingUntil, conversation)) {
            delete typingUntil[conversation];
            paintTyping();
        }
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

    if (list || inbox) {
        window.oscMessages = {appendMessage: appendMessage};
    }

    // The list filter and All / Unread work on the rows already on the page.
    var filter = {text: '', unread: false};

    var applyFilter = function () {
        inbox.querySelectorAll('li.oe-thread').forEach(function (row) {
            var miss = filter.text !== '' && (row.dataset.search || '').indexOf(filter.text) === -1;
            row.hidden = miss || (filter.unread && !(Number(row.dataset.unread) > 0));
        });
    };

    var markCurrent = function () {
        var current = list ? String(Number(list.dataset.conversation)) : '';
        inbox.querySelectorAll('li.oe-thread').forEach(function (row) {
            var on = row.dataset.id === current;
            row.classList.toggle('is-current', on);
            var link = row.querySelector('.oe-thread-link');
            if (link && on) {
                link.setAttribute('aria-current', 'page');
            } else if (link) {
                link.removeAttribute('aria-current');
            }
        });
    };

    // The count on the Unread tab: how many loaded threads have something unread.
    var countUnread = function () {
        var badge = document.querySelector('[data-unread-threads]');
        if (!badge || !inbox) {
            return;
        }
        var count = Array.from(inbox.querySelectorAll('li.oe-thread')).filter(function (row) {
            return Number(row.dataset.unread) > 0;
        }).length;
        badge.textContent = String(count);
        badge.hidden = count === 0;
    };

    var swapInbox = function (fresh) {
        if (!inbox || !fresh) {
            return;
        }
        inbox.replaceChildren.apply(inbox, Array.from(fresh.childNodes).map(function (node) {
            return document.importNode(node, true);
        }));
        if (messenger) {
            markCurrent();
            applyFilter();
        }
        countUnread();
        paintTyping();
    };

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
            return fetch(url, {credentials: 'same-origin', headers: reqHeaders}).then(function (response) {
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

        // Beside a thread the list comes from the inbox URL, so the open thread is not rendered twice.
        var refreshInbox = function () {
            var back = list ? document.querySelector('.oe-chat-back[href]') : null;
            var url = list ? (back ? back.href : '') : location.href;
            if (url === '') {
                return Promise.resolve();
            }
            return fetch(url, {credentials: 'same-origin'}).then(function (response) {
                if (!response.ok) {
                    throw new Error('inbox ' + response.status);
                }
                return response.text();
            }).then(function (html) {
                swapInbox(new DOMParser().parseFromString(html, 'text/html').querySelector('[data-osc-inbox]'));
            });
        };

        // The thread first: fetching it marks it read, so the list fetched after shows it read.
        var refresh = function () {
            if (!list) {
                return inbox ? refreshInbox() : Promise.resolve(0);
            }
            return refreshThread().then(function (read) {
                return inbox ? refreshInbox().then(function () {
                    return read;
                }) : read;
            });
        };

        var check = function () {
            var headers = {};
            if (etag) {
                headers['If-None-Match'] = etag;
            }
            // With a thread open the answer also says whether the other side has read it, for "Seen".
            var url = list && list.dataset.conversation
                ? checkUrl + '&id=' + encodeURIComponent(list.dataset.conversation) : checkUrl;
            return fetch(url, {headers: headers, credentials: 'same-origin'}).then(function (response) {
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
                return Promise.all([activity, refresh()]).then(function (done) {
                    etag = fresh;
                    if (list && done[0] && typeof done[0].their_unread === 'number') {
                        if (done[0].their_unread === 0) {
                            markSeen();
                        } else {
                            list.dataset.theirUnread = String(done[0].their_unread);
                            clearSeen();
                        }
                    }
                    // On a thread, fetching the new messages marked them read, so they leave the count.
                    if (done[0] && typeof done[0].unread === 'number') {
                        setUnread(done[0].unread - (Number(done[1]) || 0));
                    }
                });
            });
        };

        run = function () {
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
            stream.addEventListener('open', function () {
                if (visible()) {
                    run();
                }
            });
            // A typing note shows the indicator and a seen note the tick; neither needs a fetch.
            stream.addEventListener('message', function (event) {
                var note = null;
                try {
                    note = JSON.parse(event.data);
                } catch (e) {
                    note = null;
                }
                if (note && note.kind === 'typing') {
                    onTyping(note);
                    return;
                }
                if (note && note.kind === 'seen') {
                    if (list && Number(note.conversation) === Number(list.dataset.conversation)) {
                        markSeen();
                    }
                    return;
                }
                if (visible()) {
                    run();
                }
            });
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

    if (!messenger && !list) {
        return;
    }

    // The typing signal: at most one POST per 3 s while there is text, dropped on send and blur.
    var typingPost = {last: -Infinity, pending: null};

    var stopTyping = function () {
        if (typingPost.pending !== null) {
            clearTimeout(typingPost.pending);
            typingPost.pending = null;
        }
    };

    var postTyping = function () {
        typingPost.last = Date.now();
        var fields = new FormData(form);
        fields.delete('page');
        fields.delete('action');
        fields.delete('body');
        fetch(list.dataset.typingUrl, {method: 'POST', body: fields, credentials: 'same-origin', headers: reqHeaders})
            .catch(function () {
                return null;
            });
    };

    var noteTyping = function (field) {
        if (!list.dataset.typingUrl || field.value.trim() === '' || typingPost.pending !== null) {
            return;
        }
        var wait = TYPING_EVERY - (Date.now() - typingPost.last);
        if (wait <= 0) {
            postTyping();
            return;
        }
        typingPost.pending = setTimeout(function () {
            typingPost.pending = null;
            if (field.value.trim() !== '') {
                postTyping();
            }
        }, wait);
    };

    // The composer: Enter sends, Shift + Enter is a new line, the box grows, a counter near the limit.
    var bindComposer = function () {
        var field = form.elements.body;
        if (!field) {
            return;
        }
        var button = form.querySelector('button[type="submit"]');
        var counter = form.querySelector('.oe-compose-counter');
        var max = Number(field.getAttribute('maxlength')) || 0;
        var sending = false;

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

        var update = function () {
            field.style.height = 'auto';
            if (field.scrollHeight > 0) {
                field.style.height = field.scrollHeight + 'px';
            }
            if (button) {
                button.disabled = sending || field.value.trim() === '';
            }
            if (counter) {
                var left = max - field.value.length;
                var near = max > 0 && left <= 100;
                counter.textContent = near ? (counter.dataset.label || '%d').replace('%d', String(left)) : '';
                counter.classList.toggle('is-tight', near && left <= 20);
            }
        };

        field.addEventListener('input', function () {
            update();
            noteTyping(field);
        });
        field.addEventListener('blur', stopTyping);
        field.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || event.shiftKey || event.altKey || event.ctrlKey || event.metaKey
                || event.isComposing || event.keyCode === 229) {
                return;
            }
            event.preventDefault();
            if (field.value.trim() === '' || sending) {
                return;
            }
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else if (button) {
                button.click();
            }
        });
        update();

        form.addEventListener('submit', function (event) {
            if (field.value.trim() === '') {
                return;
            }
            event.preventDefault();
            clearError();
            stopTyping();
            sending = true;
            update();

            // The form's own page and action would override the AJAX ones in the URL.
            var fields = new FormData(form);
            fields.delete('page');
            fields.delete('action');

            fetch(list.dataset.sendUrl, {
                method: 'POST',
                body: fields,
                credentials: 'same-origin',
                headers: reqHeaders
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
                showError(result.data.msg || form.dataset.errorText || '');
            }).catch(function () {
                showError(form.dataset.errorText || '');
            }).finally(function () {
                sending = false;
                update();
            });
        });
    };

    // The menu: Block asks in a dialog and Report opens its form in one. Without the script both
    // stay plain forms, Block with the page's confirm().
    var canDialog = typeof window.HTMLDialogElement === 'function'
        && typeof window.HTMLDialogElement.prototype.showModal === 'function';

    var closeMenus = function (except) {
        document.querySelectorAll('details.oe-menu[open], details.oe-menu-report[open]').forEach(function (menu) {
            if (!except || !menu.contains(except)) {
                menu.open = false;
            }
        });
    };

    var makeDialog = function (chat, id, title, text, labels) {
        var dialog = document.createElement('dialog');
        dialog.className = 'oe-dialog';
        dialog.id = id;
        dialog.setAttribute('data-osc-dialog-ready', '');
        dialog.setAttribute('aria-labelledby', id + '-title');

        var head = document.createElement('div');
        head.className = 'oe-dialog-head';
        var heading = document.createElement('h2');
        heading.id = id + '-title';
        heading.textContent = title;
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'oe-dialog-close';
        close.setAttribute('aria-label', labels.dialogClose || '');
        close.textContent = '×';
        head.append(heading, close);

        var body = document.createElement('div');
        body.className = 'oe-dialog-body';
        if (text) {
            var line = document.createElement('p');
            line.textContent = text;
            body.append(line);
        }
        dialog.append(head, body);

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'oe-btn oe-secondary';
        cancel.textContent = labels.dialogCancel || '';
        [close, cancel].forEach(function (button) {
            button.addEventListener('click', function () {
                dialog.close();
            });
        });
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });
        dialog.addEventListener('close', function () {
            var summary = chat.querySelector('details.oe-menu > summary');
            if (summary) {
                summary.focus();
            }
        });
        chat.append(dialog);
        return {dialog: dialog, body: body, cancel: cancel};
    };

    var openBlock = function (chat, trigger) {
        var dialog = chat.querySelector('#oe-block-dialog');
        if (!dialog) {
            // "Block this conversation? Neither of you…" becomes a title and a line.
            var text = trigger.getAttribute('data-osc-confirm') || '';
            var split = /^(.+?[?.!？。！])\s+(\S[\s\S]*)$/.exec(text);
            var made = makeDialog(chat, 'oe-block-dialog', split ? split[1] : text, split ? split[2] : '', trigger.dataset);
            var actions = document.createElement('div');
            actions.className = 'oe-actions';
            var confirm = document.createElement('button');
            confirm.type = 'button';
            confirm.className = 'oe-btn oe-btn-danger';
            confirm.textContent = trigger.textContent.trim();
            confirm.addEventListener('click', function () {
                made.dialog.close();
                trigger.form.submit();
            });
            actions.append(made.cancel, confirm);
            made.body.append(actions);
            dialog = made.dialog;
        }
        closeMenus();
        dialog.showModal();
    };

    var openReport = function (chat, details) {
        var dialog = chat.querySelector('#oe-report-dialog');
        if (!dialog) {
            var report = details.querySelector('form');
            if (!report) {
                return false;
            }
            var made = makeDialog(chat, 'oe-report-dialog', report.dataset.dialogTitle || '', report.dataset.dialogText || '', report.dataset);
            (report.querySelector('.oe-actions') || report).prepend(made.cancel);
            made.body.append(report);
            dialog = made.dialog;
        }
        closeMenus();
        dialog.showModal();
        return true;
    };

    var bindMenu = function (chat) {
        if (!canDialog) {
            return;
        }
        chat.addEventListener('click', function (event) {
            var trigger = event.target.closest('.oe-menu [data-osc-confirm]');
            if (trigger && trigger.form) {
                // Stopping here keeps the page's confirm() listener on the document from running.
                event.preventDefault();
                event.stopPropagation();
                openBlock(chat, trigger);
                return;
            }
            var summary = event.target.closest('details.oe-menu-report > summary');
            if (summary && openReport(chat, summary.parentNode)) {
                event.preventDefault();
            }
        });
    };

    // Bind the chat pane; run again when a row click swaps it.
    var bindChat = function () {
        list = document.querySelector('[data-osc-messages]');
        form = document.querySelector('form.oe-compose');
        stopTyping();
        typingPost.last = -Infinity;
        if (list) {
            var now = Number(list.dataset.serverNow);
            clockOffset = now > 0 ? now - Date.now() / 1000 : 0;
            var seen = list.querySelector('.oe-seen');
            seenLabel = seen ? seen.textContent.trim() : seenLabel;
            list.scrollTop = list.scrollHeight;
        }
        var chat = document.querySelector('.oe-messenger-chat');
        if (chat) {
            bindMenu(chat);
        }
        if (form && list) {
            bindComposer();
        }
        if (messenger && inbox) {
            markCurrent();
        }
        paintTyping();
    };

    // Desktop: a row opens its thread beside the list without a page load; a phone follows the link.
    var navSeq = 0;
    var navigate = function (url, push) {
        var seq = ++navSeq;
        return fetch(url, {credentials: 'same-origin'}).then(function (response) {
            if (!response.ok) {
                throw new Error('thread ' + response.status);
            }
            return response.text();
        }).then(function (html) {
            if (seq !== navSeq) {
                return;
            }
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var freshBox = doc.querySelector('.oe-messenger');
            var freshChat = doc.querySelector('.oe-messenger-chat');
            var chat = document.querySelector('.oe-messenger-chat');
            if (!freshBox || !freshChat || !chat) {
                location.assign(url);
                return;
            }
            freshChat.querySelectorAll('script').forEach(function (script) {
                script.remove();
            });
            chat.replaceWith(document.importNode(freshChat, true));
            messenger.className = freshBox.className;
            if (doc.title) {
                document.title = doc.title;
            }
            if (push) {
                history.pushState({oscMessenger: true}, '', url);
            }
            bindChat();
            swapInbox(doc.querySelector('[data-osc-inbox]'));
            run();
        }).catch(function () {
            location.assign(url);
        });
    };

    var wide = function () {
        return typeof window.matchMedia === 'function' && window.matchMedia('(min-width: 900px)').matches;
    };

    if (messenger && inbox) {
        var filterBox = document.querySelector('.oe-list-filter');
        var tabs = document.querySelector('.oe-list-tabs');
        var input = filterBox && filterBox.querySelector('input');
        if (input && tabs) {
            filterBox.hidden = false;
            tabs.hidden = false;
            input.addEventListener('input', function () {
                filter.text = input.value.trim().toLocaleLowerCase();
                applyFilter();
            });
            tabs.addEventListener('click', function (event) {
                var pick = event.target.closest('button[data-filter]');
                if (!pick) {
                    return;
                }
                filter.unread = pick.dataset.filter === 'unread';
                tabs.querySelectorAll('button[data-filter]').forEach(function (b) {
                    b.setAttribute('aria-pressed', String(b === pick));
                });
                applyFilter();
            });
        }

        if (typeof history.pushState === 'function') {
            if (!history.state || !history.state.oscMessenger) {
                history.replaceState(Object.assign({}, history.state, {oscMessenger: true}), '', location.href);
            }
            inbox.addEventListener('click', function (event) {
                var link = event.target.closest('a.oe-thread-link');
                if (!link || !wide() || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }
                event.preventDefault();
                navigate(link.href, true);
            });
            window.addEventListener('popstate', function (event) {
                if (event.state && event.state.oscMessenger) {
                    navigate(location.href, false);
                }
            });
        }
    }

    document.addEventListener('click', function (event) {
        closeMenus(event.target);
    });
    document.addEventListener('keydown', function (event) {
        var open = event.key === 'Escape' ? document.querySelector('details.oe-menu[open]') : null;
        if (open) {
            open.open = false;
            open.querySelector('summary').focus();
        }
    });

    bindChat();
}());
