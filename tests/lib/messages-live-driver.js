/*
 * Drives assets/osclass/messages.js for tests/messages-live.php, on the fake clock, fetch and
 * EventSource from messages-fake-env.js.
 */
(function () {
    var env = window.oscFakeEnv;
    var flush = window.oscFlush;
    var source = window.__script;

    var row = function (id) {
        return JSON.stringify({messages: [{id: id, conversation: 7, sender: 2, body: 'hi ' + id, at: '2026-01-01 10:00:00'}], state: 'open', blocked: false});
    };

    var thread = function (live) {
        return '<ol data-osc-messages data-conversation="7" data-last-id="1" data-me="1" data-format="Y-m-d H:i"'
            + ' data-since-url="/x?page=ajax&action=messages_since" data-check-url="/x?page=ajax&action=messages_check"'
            + ' data-live-url="' + live + '" data-me-label="You" data-other-label="Sam" data-held-label="Held">'
            + '<li class="oe-message" data-id="1"></li></ol>';
    };

    // The check answers the tag in e.tag (null = 304 when the request carries it); since answers the next id.
    var threadRoute = function (url, e) {
        if (url.indexOf('messages_since') !== -1) {
            e.nextId = (e.nextId || 1) + 1;
            return {status: 200, body: row(e.nextId), headers: {'Content-Type': 'application/json'}};
        }
        var tag = '"' + e.tag + '"';
        var last = e.fetches[e.fetches.length - 1];
        if (last.inm === tag) {
            return {status: 304};
        }
        return {status: 200, body: '{}', headers: {'ETag': tag, 'Content-Type': 'application/json'}};
    };

    var count = function (e, needle) {
        return e.fetches.filter(function (f) {
            return f.url.indexOf(needle) !== -1;
        }).length;
    };
    var ids = function () {
        return Array.from(document.querySelectorAll('[data-osc-messages] li')).map(function (li) {
            return li.dataset.id;
        });
    };

    async function main() {
        var out = {};
        var e;

        // Polling: first check at 15 s, If-None-Match after the first 200, 304 fetches nothing.
        e = env({route: threadRoute});
        e.tag = 'a';
        await e.start(thread(''));
        await e.advance(14999);
        out.pollEarly = e.fetches.length;
        await e.advance(1);
        out.pollFirst = e.fetches.map(function (f) {
            return [f.url.indexOf('messages_check') !== -1 ? 'check' : 'since', f.inm];
        });
        out.pollSinceUrl = e.fetches[1] ? e.fetches[1].url : '';
        out.pollIds = ids();
        await e.advance(15000);
        out.pollSecond = e.fetches[e.fetches.length - 1].inm;
        out.pollAfter304 = e.fetches.length;
        e.tag = 'b';
        await e.advance(15000);
        out.pollSinceCount = count(e, 'messages_since');
        out.pollIds2 = ids();
        e.stop();

        // A failed refresh keeps the old ETag, so the next check fetches since again.
        e = env({route: function (url, en) {
            if (url.indexOf('messages_since') !== -1 && !en.sinceFailed) {
                en.sinceFailed = true;
                return {status: 500};
            }
            return threadRoute(url, en);
        }});
        e.tag = 'a';
        await e.start(thread(''));
        await e.advance(15000);
        await e.advance(30000);
        out.retryInm = e.fetches.filter(function (f) {
            return f.url.indexOf('messages_check') !== -1;
        }).map(function (f) {
            return f.inm;
        });
        out.retryIds = ids();
        e.stop();

        // Hidden tab: no check in 60 s; back to visible: one at once.
        e = env({route: threadRoute});
        e.tag = 'a';
        await e.start(thread(''));
        await e.setVisible('hidden');
        await e.advance(60000);
        out.hiddenFetches = e.fetches.length;
        await e.setVisible('visible');
        out.visibleFetches = count(e, 'messages_check');
        e.stop();

        // Live URL: stream opened with it, no timed check, a note refreshes, three errors fall back.
        e = env({route: threadRoute});
        e.tag = 'a';
        await e.start(thread('/live/sub/user-1'));
        out.liveSources = e.sources.map(function (s) {
            return s.url;
        });
        await e.advance(59999);
        out.liveFetches = e.fetches.length;
        await e.advance(1);
        out.liveSlowCheck = count(e, 'messages_check');
        var sinceBefore = count(e, 'messages_since');
        e.tag = 'b';
        e.sources[0].emit('message');
        await flush();
        out.liveNoteSince = count(e, 'messages_since') - sinceBefore;
        e.sources[0].emit('error');
        e.sources[0].emit('error');
        out.liveClosedAfter2 = e.sources[0].closed;
        e.sources[0].emit('error');
        out.liveClosedAfter3 = e.sources[0].closed;
        var before = e.fetches.length;
        await e.advance(15000);
        out.liveResumed = e.fetches.length - before;
        e.stop();

        // An HTTP error closes the stream for good after one error: polling resumes.
        e = env({route: threadRoute});
        e.tag = 'a';
        await e.start(thread('/live/sub/user-1'));
        e.sources[0].readyState = 2;
        e.sources[0].emit('error');
        before = e.fetches.length;
        await e.advance(15000);
        out.liveHttpErrorResumed = e.fetches.length - before;
        e.stop();

        // No live URL: no EventSource at all.
        e = env({route: threadRoute});
        e.tag = 'a';
        await e.start(thread(''));
        out.noLiveSources = e.sources.length;
        e.stop();

        // Inbox: a 200 swaps the inside of [data-osc-inbox].
        e = env({route: function (url) {
            if (url.indexOf('messages_check') !== -1) {
                return {status: 200, body: '{}', headers: {'ETag': '"z"'}};
            }
            return {status: 200, body: '<html><body><div data-osc-inbox><p class="new">new</p></div></body></html>'};
        }});
        await e.start('<div data-osc-inbox data-check-url="/x?page=ajax&action=messages_check" data-live-url=""><p class="old">old</p></div>');
        await e.advance(15000);
        out.inbox = {
            fresh: document.querySelectorAll('[data-osc-inbox] .new').length,
            stale: document.querySelectorAll('[data-osc-inbox] .old').length,
            wrappers: document.querySelectorAll('[data-osc-inbox]').length
        };
        e.stop();

        // Unread badges: a check's count, less the messages the thread just read, reaches every badge.
        var link = '<a data-osc-messages-link aria-label="Messages" data-label="Messages" data-label-unread="Messages, %d unread">'
            + '<span class="oe-badge oe-unread" data-osc-unread hidden>0</span></a>'
            + '<span class="oe-badge oe-unread" data-osc-unread hidden>0</span>';
        var badges = function () {
            var link = document.querySelector('[data-osc-messages-link]');
            return {
                text: Array.from(document.querySelectorAll('[data-osc-unread]')).map(function (b) {
                    return b.textContent + (b.hidden ? ' hidden' : '');
                }),
                label: link ? link.getAttribute('aria-label') : null
            };
        };
        e = env({route: function (url, en) {
            if (url.indexOf('messages_since') !== -1) {
                return {status: 200, body: row(2), headers: {'Content-Type': 'application/json'}};
            }
            return {status: 200, body: JSON.stringify({unread: 3, latest: 'x'}), headers: {'ETag': '"u"'}};
        }});
        await e.start(link + thread(''));
        await e.advance(15000);
        out.badgeThread = badges();
        e.stop();

        e = env({route: function (url, en) {
            if (url.indexOf('messages_check') !== -1) {
                return {status: 200, body: JSON.stringify({unread: en.unread, latest: 'x'}), headers: {'ETag': '"' + en.unread + '"'}};
            }
            return {status: 200, body: '<html><body><div data-osc-inbox></div></body></html>'};
        }});
        e.unread = 4;
        await e.start(link + '<div data-osc-inbox data-check-url="/x?page=ajax&action=messages_check" data-live-url=""></div>');
        await e.advance(15000);
        out.badgeInbox = badges();
        e.unread = 0;
        await e.advance(15000);
        out.badgeInboxZero = badges();
        e.stop();

        e = env({route: threadRoute});
        e.tag = 'a';
        await e.start(link + thread(''));
        await e.advance(15000);
        out.badgeNoCount = badges();
        e.stop();

        // Any other page: the header link carries the check URL; a check a minute updates the badges only.
        e = env({route: function () {
            return {status: 200, body: JSON.stringify({unread: 5, latest: 'x'}), headers: {'ETag': '"b"'}};
        }});
        await e.start('<a data-osc-messages-link aria-label="Messages" data-label="Messages" data-label-unread="Messages, %d unread"'
            + ' data-check-url="/x?page=ajax&action=messages_check" data-live-url="">'
            + '<span class="oe-badge oe-unread" data-osc-unread hidden>0</span></a>');
        (0, eval)(source);
        await e.advance(59000);
        out.linkEarly = e.fetches.length;
        await e.advance(60000);
        out.linkFetches = e.fetches.map(function (f) {
            return f.url.indexOf('messages_check') !== -1 ? 'check' : f.url;
        });
        out.linkBadges = badges();
        e.stop();

        document.getElementById('osc-out').textContent = btoa(JSON.stringify(out));
    }

    main().catch(function (err) {
        document.getElementById('osc-out').textContent = btoa(JSON.stringify({error: String(err && err.stack || err)}));
    });
}());
