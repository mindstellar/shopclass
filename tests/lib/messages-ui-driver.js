/*
 * Drives assets/osclass/messages.js for tests/messages-ui.php on a copy of the messenger markup,
 * with the fake clock, fetch and EventSource from messages-fake-env.js.
 */
(function () {
    var env = window.oscFakeEnv;
    var flush = window.oscFlush;
    // Server clock in the fixture: 2026-01-05 12:00:00 UTC; the fake browser clock starts at 0.
    var NOW = 1767614400;

    var esc = function (s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    };

    var rowHtml = function (id, name, title, unread, current) {
        return '<li class="oe-thread' + (unread ? ' is-unread' : '') + (current ? ' is-current' : '') + '" data-id="' + id
            + '" data-unread="' + unread + '" data-search="' + esc((name + ' ' + title).toLowerCase()) + '">'
            + '<a class="oe-thread-link" href="?page=messages&id=' + id + '"' + (current ? ' aria-current="page"' : '') + '>'
            + '<span class="oe-thread-name">' + name + '</span><span class="oe-thread-title">' + title + '</span>'
            + '<span class="oe-thread-preview">Preview ' + id + '</span></a></li>';
    };

    var inboxHtml = function (current) {
        return '<div data-osc-inbox data-check-url="/x?page=ajax&action=messages_check" data-live-url="' + (current.live || '') + '">'
            + '<ul class="oe-threads">'
            + rowHtml(7, 'Priya', 'Oak desk', 0, current.id === 7)
            + rowHtml(8, 'Sam', 'Road bike', 2, current.id === 8)
            + rowHtml(9, 'Lee', 'Lamp', 0, current.id === 9)
            + '</ul></div>';
    };

    var listHtml = function (o) {
        return '<section class="oe-messenger-list"><div class="oe-list-head"><h2>Messages</h2>'
            + '<div class="oe-list-filter" hidden><input id="oe-list-filter" type="search"></div>'
            + '<div class="oe-list-tabs" hidden><button type="button" data-filter="all" aria-pressed="true">All</button>'
            + '<button type="button" data-filter="unread" aria-pressed="false">Unread</button></div></div>'
            + inboxHtml(o) + '</section>';
    };

    var msg = function (id, mine, at, extra) {
        return '<li class="oe-message' + (mine ? ' is-mine' : '') + ' is-first is-last" data-id="' + id + '">'
            + '<span class="oe-sr-only">x</span><div class="oe-message-body">m' + id + '</div>'
            + '<p class="oe-message-meta"><time datetime="' + at + '">' + at.slice(11, 16) + '</time>' + (extra || '') + '</p></li>';
    };

    var chatHtml = function (o) {
        var live = o.live || '';
        var msgs = o.messages || (msg(1, false, '2026-01-05 11:00:00') + msg(2, true, '2026-01-05 11:58:00'));
        return '<section class="oe-messenger-chat">'
            + '<div class="oe-chat-head"><a class="oe-chat-back" href="?page=messages">Back</a>'
            + '<h2 class="oe-chat-name" id="oe-chat-name">Priya</h2>'
            + '<div class="oe-chat-role" data-role="Seller">Seller</div>'
            + '<details class="oe-menu"><summary aria-label="Options">...</summary><div class="oe-menu-panel oe-thread-actions">'
            + '<form class="oe-inline-form nocsrf" method="post" action="/x"><input type="hidden" name="page" value="messages">'
            + '<input type="hidden" name="action" value="block_post"><input type="hidden" name="id" value="' + o.id + '">'
            + '<button type="submit" class="oe-menu-item oe-danger-link" data-osc-confirm="Block this conversation? Neither of you can write until it is unblocked.">Block</button></form>'
            + '<details class="oe-menu-report"><summary class="oe-menu-item">Report</summary>'
            + '<form method="post" action="/x" data-dialog-title="Report conversation" data-dialog-text="The site team reviews it.">'
            + '<input type="hidden" name="action" value="report_post"><fieldset><legend>Why?</legend>'
            + '<label class="oe-check"><input type="radio" name="reason" value="spam" required> Spam</label></fieldset>'
            + '<div class="oe-actions"><button class="oe-btn oe-btn-danger" type="submit">Report conversation</button></div></form>'
            + '</details></div></details></div>'
            + '<ol class="oe-messages" data-osc-messages style="display:block;height:120px;overflow:auto;margin:0"'
            + ' data-conversation="' + o.id + '" data-last-id="2" data-me="1" data-format="Y-m-d H:i"'
            + ' data-send-url="/x?page=ajax&action=messages_send" data-since-url="/x?page=ajax&action=messages_since"'
            + ' data-check-url="/x?page=ajax&action=messages_check" data-live-url="' + live + '"'
            + (live ? ' data-typing-url="/x?page=ajax&action=messages_typing"' : '')
            + ' data-server-now="' + NOW + '" data-their-unread="' + (o.theirUnread === undefined ? 1 : o.theirUnread) + '"'
            + ' data-held-label="Awaiting review" data-other-label="Priya" data-me-label="You">'
            + '<li class="oe-day"><span>Today</span></li>' + msgs + '</ol>'
            + (live ? '<div class="oe-typing" hidden><div class="oe-typing-bubble"><span class="oe-sr-only">Typing</span><i></i><i></i><i></i></div></div>' : '')
            + '<form class="oe-compose" method="post" action="/x"><input type="hidden" name="csrf" value="t">'
            + '<input type="hidden" name="page" value="messages"><input type="hidden" name="action" value="send_post">'
            + '<input type="hidden" name="id" value="' + o.id + '"><div class="oe-compose-row">'
            + '<textarea id="oe-compose-body" name="body" rows="1" required maxlength="500"></textarea>'
            + '<button class="oe-compose-send" type="submit">Send</button></div>'
            + '<div class="oe-compose-foot"><span class="oe-compose-counter"></span></div></form>'
            + '</section>';
    };

    var page = function (o) {
        return '<div class="oe-page"><div class="oe-messenger is-chat">' + listHtml(o) + chatHtml(o) + '</div></div>';
    };

    var sent = function (id, sender, at) {
        return JSON.stringify({ok: true, held: false, message: {id: id, conversation: 7, sender: sender, body: 'b' + id, at: at}});
    };

    var route = function (url, e) {
        if (url.indexOf('messages_check') !== -1) {
            return {status: 200, body: '{"unread":0}', headers: {'ETag': '"' + (e.tag || 'a') + '"'}};
        }
        if (url.indexOf('messages_since') !== -1) {
            return {status: 200, body: JSON.stringify({messages: e.since || []})};
        }
        if (url.indexOf('messages_send') !== -1) {
            return {status: 200, body: e.sendBody || sent(3, 1, '2026-01-05 11:59:00')};
        }
        if (url.indexOf('messages_typing') !== -1) {
            return {status: 204};
        }
        if (url.indexOf('id=8') !== -1) {
            return {status: 200, body: '<html><head><title>Sam</title></head><body>' + page({id: 8, live: e.live}) + '</body></html>'};
        }
        return {status: 200, body: '<html><body>' + page({id: 7, live: e.live}) + '</body></html>'};
    };

    var note = function (e, obj) {
        e.sources[0].emit('message', JSON.stringify(obj));
    };

    var key = function (field, opts) {
        var ev = new KeyboardEvent('keydown', Object.assign({key: 'Enter', bubbles: true, cancelable: true}, opts || {}));
        field.dispatchEvent(ev);
        return ev.defaultPrevented;
    };

    var type = function (field, text) {
        field.value = text;
        field.dispatchEvent(new Event('input', {bubbles: true}));
    };

    var state = function () {
        var bubble = document.querySelector('.oe-typing');
        var role = document.querySelector('.oe-chat-role');
        var line = document.querySelector('li.oe-thread[data-id="7"] .oe-thread-preview');
        return {
            bubble: bubble ? !bubble.hidden : null,
            role: role ? role.textContent : null,
            row: line ? line.textContent + (line.classList.contains('is-typing') ? ' [typing]' : '') : null
        };
    };

    var classes = function () {
        return Array.from(document.querySelectorAll('[data-osc-messages] > li')).map(function (li) {
            if (li.classList.contains('oe-day')) {
                return 'day:' + li.textContent;
            }
            var meta = li.querySelector('.oe-message-meta');
            return li.dataset.id + ':' + ['is-mine', 'is-first', 'is-last', 'is-held'].filter(function (c) {
                return li.classList.contains(c);
            }).join(',') + ':' + (meta ? meta.textContent : '');
        });
    };

    async function main() {
        var out = {};
        var e;
        var field;

        // Live: typing note shows the bubble, the role line and the row line, with no fetch; 5 s hides.
        e = env({route: route});
        await e.start(page({id: 7, live: '/live/sub/user-1'}));
        var before = e.fetches.length;
        note(e, {kind: 'typing', conversation: 7, at: NOW});
        await flush();
        out.typingOn = state();
        out.typingFetches = e.fetches.length - before;
        await e.advance(4900);
        out.typingStill = state().bubble;
        await e.advance(200);
        out.typingOff = state();
        // A note 10 s old (nchan replays its newest note) shows nothing.
        note(e, {kind: 'typing', conversation: 7, at: NOW - 10 + 5});
        await flush();
        out.staleNote = state().bubble;
        // Their new message hides the indicator at once.
        note(e, {kind: 'typing', conversation: 7, at: NOW + 5});
        await flush();
        e.since = [{id: 5, conversation: 7, sender: 2, body: 'hey', at: '2026-01-05 12:00:05'}];
        e.tag = 'b';
        note(e, {kind: 'sent', conversation: 7, message: 5});
        await flush();
        await flush();
        out.theirMessageHides = state().bubble;
        // A seen note puts "Seen" under my last message, with no fetch.
        before = e.fetches.length;
        e.sendBody = sent(6, 1, '2026-01-05 12:00:06');
        field = document.getElementById('oe-compose-body');
        type(field, 'ok');
        await e.advance(10);
        document.querySelector('form.oe-compose').requestSubmit();
        await flush();
        await flush();
        before = e.fetches.length;
        note(e, {kind: 'seen', conversation: 7});
        await flush();
        out.seen = Array.from(document.querySelectorAll('.oe-seen')).map(function (s) {
            return s.closest('li').dataset.id;
        });
        out.seenFetches = e.fetches.length - before;
        note(e, {kind: 'seen', conversation: 99});
        out.seenOther = document.querySelectorAll('.oe-seen').length;
        e.stop();

        // Typing POSTs: at most one per 3 s, only with data-typing-url, none after blur or send.
        e = env({route: route});
        await e.start(page({id: 7, live: '/live/sub/user-1'}));
        await e.advance(10000);
        field = document.getElementById('oe-compose-body');
        type(field, 'h');
        await flush();
        out.typingPost = e.fetches.filter(function (f) {
            return f.url.indexOf('messages_typing') !== -1;
        }).map(function (f) {
            return [f.method, f.body];
        });
        await e.advance(1000);
        type(field, 'he');
        await e.advance(1000);
        out.typingWithin3s = e.count('messages_typing');
        await e.advance(1000);
        out.typingAt3s = e.count('messages_typing');
        type(field, 'hel');
        field.dispatchEvent(new Event('blur'));
        await e.advance(5000);
        out.typingAfterBlur = e.count('messages_typing');
        type(field, 'hell');
        await e.advance(10);
        out.typingAfterGap = e.count('messages_typing');
        type(field, 'hello');
        document.querySelector('form.oe-compose').requestSubmit();
        await e.advance(5000);
        out.typingAfterSend = e.count('messages_typing');
        e.stop();

        // Polling (no live URL): typing sends nothing and there is no indicator.
        e = env({route: route});
        await e.start(page({id: 7, live: ''}));
        field = document.getElementById('oe-compose-body');
        type(field, 'abc');
        await e.advance(4000);
        type(field, 'abcd');
        await e.advance(4000);
        out.pollTyping = {posts: e.count('messages_typing'), sources: e.sources.length, bubble: document.querySelectorAll('.oe-typing').length};
        e.stop();

        // The composer: send disabled when empty, Enter sends, Shift + Enter and IME do not, counter, autosize.
        e = env({route: route});
        await e.start(page({id: 7, live: ''}));
        field = document.getElementById('oe-compose-body');
        var send = document.querySelector('.oe-compose-send');
        out.emptyDisabled = send.disabled;
        type(field, 'line');
        out.filledDisabled = send.disabled;
        out.shiftEnter = [key(field, {shiftKey: true}), e.count('messages_send')];
        out.imeEnter = [key(field, {isComposing: true}), e.count('messages_send')];
        out.enter = [key(field), e.count('messages_send')];
        await flush();
        await flush();
        out.afterSend = {value: field.value, disabled: send.disabled};
        type(field, '');
        out.enterEmpty = [key(field), e.count('messages_send')];
        var counter = document.querySelector('.oe-compose-counter');
        type(field, 'x'.repeat(399));
        out.counterFar = counter.textContent;
        type(field, 'x'.repeat(400));
        out.counterNear = [counter.textContent, counter.classList.contains('is-tight')];
        type(field, 'x'.repeat(480));
        out.counterTight = [counter.textContent, counter.classList.contains('is-tight')];
        type(field, 'a\nb\nc\nd\ne\nf');
        out.autosize = /^\d+px$/.test(field.style.height) && parseInt(field.style.height, 10) > 30;
        e.stop();

        // Appended messages join a group like the server does, and a new day starts a day line.
        e = env({route: route});
        await e.start(page({id: 7, live: ''}));
        var add = window.oscMessages.appendMessage;
        add({id: 3, sender: 1, body: 'a', at: '2026-01-05 11:59:00'}, false);
        add({id: 4, sender: 2, body: 'b', at: '2026-01-05 12:30:00'}, false);
        add({id: 5, sender: 1, body: 'c', at: '2026-01-06 08:00:00'}, true);
        out.groups = classes();
        out.appendText = document.querySelector('li.oe-message[data-id="4"] .oe-message-body').textContent;
        out.appendTwice = add({id: 4, sender: 2, body: 'b', at: '2026-01-05 12:30:00'}, false);
        e.stop();

        // Old days get Yesterday and the date, as MessageTime::day does.
        e = env({route: route});
        await e.start(page({id: 7, live: '', messages: ' '}));
        add = window.oscMessages.appendMessage;
        add({id: 3, sender: 1, body: 'a', at: '2026-01-02 09:00:00'}, false);
        add({id: 4, sender: 1, body: 'a', at: '2026-01-04 09:00:00'}, false);
        add({id: 5, sender: 1, body: 'a', at: '2026-01-05 09:00:00'}, false);
        out.dayLabels = Array.from(document.querySelectorAll('li.oe-day')).slice(1).map(function (li) {
            return li.textContent;
        });
        e.stop();

        // Scroll to the newest message on load.
        var many = '';
        for (var i = 1; i <= 30; i++) {
            many += msg(i, i % 2 === 0, '2026-01-05 11:' + String(i).padStart(2, '0') + ':00');
        }
        e = env({route: route});
        await e.start(page({id: 7, live: '', messages: many}));
        var ol = document.querySelector('[data-osc-messages]');
        out.scrolled = ol.scrollTop > 0 && ol.scrollTop + ol.clientHeight >= ol.scrollHeight - 2;
        e.stop();

        // Filter and All / Unread over the loaded rows.
        e = env({route: route});
        await e.start(page({id: 7, live: ''}));
        var shown = function () {
            return Array.from(document.querySelectorAll('li.oe-thread')).filter(function (li) {
                return !li.hidden;
            }).map(function (li) {
                return li.dataset.id;
            });
        };
        out.filterShown = [!document.querySelector('.oe-list-filter').hidden, !document.querySelector('.oe-list-tabs').hidden];
        var input = document.getElementById('oe-list-filter');
        input.value = 'BIKE';
        input.dispatchEvent(new Event('input', {bubbles: true}));
        out.filterText = shown();
        input.value = '';
        input.dispatchEvent(new Event('input', {bubbles: true}));
        document.querySelector('[data-filter="unread"]').click();
        out.filterUnread = [shown(), document.querySelector('[data-filter="unread"]').getAttribute('aria-pressed')];
        document.querySelector('[data-filter="all"]').click();
        out.filterAll = shown();
        e.stop();

        // Desktop: a row click swaps the chat pane and pushes the URL; back swaps it again.
        var stopNav = function (ev) {
            out.navPrevented = ev.defaultPrevented;
            ev.preventDefault();
        };
        e = env({route: route, wide: true});
        window.addEventListener('click', stopNav);
        await e.start(page({id: 7, live: ''}));
        var startUrl = location.href;
        before = e.fetches.length;
        document.querySelector('li.oe-thread[data-id="8"] a').click();
        await flush();
        await flush();
        out.navFetch = e.fetches.slice(before).map(function (f) {
            return f.url.replace(/^.*\?/, '?');
        });
        out.navSwap = {
            conversation: document.querySelector('[data-osc-messages]').dataset.conversation,
            chats: document.querySelectorAll('.oe-messenger-chat').length,
            current: Array.from(document.querySelectorAll('li.oe-thread.is-current')).map(function (li) {
                return li.dataset.id;
            }),
            ariaCurrent: document.querySelectorAll('[aria-current="page"]').length,
            url: location.search,
            title: document.title
        };
        out.navComposer = (function () {
            var f = document.getElementById('oe-compose-body');
            type(f, 'x');
            return [key(f), e.count('messages_send')];
        }());
        before = e.fetches.length;
        history.replaceState({oscMessenger: true}, '', startUrl);
        window.dispatchEvent(new PopStateEvent('popstate', {state: {oscMessenger: true}}));
        await flush();
        await flush();
        out.popFetch = e.fetches.length - before;
        out.popConversation = document.querySelector('[data-osc-messages]').dataset.conversation;
        window.removeEventListener('click', stopNav);
        e.stop();

        // Phone: a row click is a plain link.
        e = env({route: route, wide: false});
        window.addEventListener('click', stopNav);
        await e.start(page({id: 7, live: ''}));
        before = e.fetches.length;
        document.querySelector('li.oe-thread[data-id="8"] a').click();
        await flush();
        out.phoneNav = [out.navPrevented, e.fetches.length - before];
        window.removeEventListener('click', stopNav);
        e.stop();

        // The menu: Block asks in a dialog instead of confirm(); Report opens its form in a dialog.
        var confirms = 0;
        var submits = [];
        var realSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () {
            submits.push(this.querySelector('[name="action"]').value);
        };
        var pageConfirm = function (ev) {
            var a = ev.target.closest && ev.target.closest('[data-osc-confirm]');
            if (a) {
                confirms++;
                ev.preventDefault();
            }
        };
        e = env({route: route});
        await e.start(page({id: 7, live: ''}));
        document.addEventListener('click', pageConfirm);
        var menu = document.querySelector('details.oe-menu');
        menu.open = true;
        document.querySelector('[data-osc-confirm]').click();
        var block = document.getElementById('oe-block-dialog');
        out.block = block ? {
            open: block.open,
            ready: block.hasAttribute('data-osc-dialog-ready'),
            title: block.querySelector('h2').textContent,
            text: block.querySelector('.oe-dialog-body p').textContent,
            confirms: confirms,
            menuOpen: menu.open
        } : null;
        if (block) {
            block.querySelector('.oe-btn-danger').click();
        }
        out.blockSubmit = [submits, block ? block.open : null];
        document.querySelector('details.oe-menu-report > summary').click();
        var report = document.getElementById('oe-report-dialog');
        out.report = report ? {
            open: report.open,
            ready: report.hasAttribute('data-osc-dialog-ready'),
            title: report.querySelector('h2').textContent,
            text: report.querySelector('.oe-dialog-body p').textContent,
            form: !!report.querySelector('form [name="reason"]'),
            reportOpen: document.querySelector('details.oe-menu-report').open
        } : null;
        if (report) {
            report.querySelector('.oe-secondary').click();
            out.reportCancel = report.open;
        }
        document.removeEventListener('click', pageConfirm);
        HTMLFormElement.prototype.submit = realSubmit;
        e.stop();

        document.getElementById('osc-out').textContent = btoa(unescape(encodeURIComponent(JSON.stringify(out))));
    }

    main().catch(function (err) {
        document.getElementById('osc-out').textContent = btoa(unescape(encodeURIComponent(JSON.stringify({error: String(err && err.stack || err)}))));
    });
}());
