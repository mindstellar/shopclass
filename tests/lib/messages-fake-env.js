/*
 * A fake clock, fetch recorder and EventSource for the messages.js browser drivers, installed
 * before each run of the script so no real time passes. Sets window.oscFakeEnv(opts) and
 * window.oscFlush().
 */
(function () {
    var realSetTimeout = window.setTimeout.bind(window);
    var source = window.__script;
    var realAdd = document.addEventListener.bind(document);
    var realRemove = document.removeEventListener.bind(document);
    var realWinAdd = window.addEventListener.bind(window);
    var realWinRemove = window.removeEventListener.bind(window);

    function flush() {
        return new Promise(function (resolve) {
            realSetTimeout(resolve, 0);
        });
    }

    function env(opts) {
        var e = {now: 0, timers: [], nextTimer: 1, fetches: [], sources: [], listeners: [], visible: 'visible', wide: !!opts.wide};

        window.setTimeout = function (fn, ms) {
            var id = e.nextTimer++;
            e.timers.push({id: id, at: e.now + (ms || 0), fn: fn});
            return id;
        };
        window.clearTimeout = function (id) {
            e.timers = e.timers.filter(function (t) {
                return t.id !== id;
            });
        };
        Date.now = function () {
            return e.now;
        };
        window.matchMedia = function (query) {
            return {matches: e.wide && query.indexOf('min-width') !== -1, media: query};
        };
        Object.defineProperty(document, 'visibilityState', {get: function () {
            return e.visible;
        }, configurable: true});
        document.addEventListener = function (type, fn, o) {
            e.listeners.push([document, type, fn, o]);
            return realAdd(type, fn, o);
        };
        window.addEventListener = function (type, fn, o) {
            e.listeners.push([window, type, fn, o]);
            return realWinAdd(type, fn, o);
        };
        window.fetch = function (url, init) {
            init = init || {};
            var headers = init.headers || {};
            var body = init.body instanceof FormData ? Array.from(init.body.keys()) : null;
            e.fetches.push({url: String(url), method: init.method || 'GET', inm: headers['If-None-Match'] || null, body: body});
            var r = opts.route(String(url), e, init);
            return Promise.resolve(new Response(r.body === undefined ? null : r.body, {status: r.status, headers: r.headers || {}}));
        };
        window.EventSource = function (url) {
            var self = this;
            this.url = url;
            this.closed = false;
            this.readyState = 0;
            this.handlers = {};
            this.addEventListener = function (type, fn) {
                (self.handlers[type] = self.handlers[type] || []).push(fn);
            };
            this.close = function () {
                self.closed = true;
                self.readyState = 2;
            };
            this.emit = function (type, data) {
                (self.handlers[type] || []).forEach(function (fn) {
                    fn({data: data === undefined ? '{"kind":"sent","conversation":7,"message":9}' : data});
                });
            };
            e.sources.push(this);
        };
        window.EventSource.CLOSED = 2;
        e.advance = async function (ms) {
            var target = e.now + ms;
            for (;;) {
                var due = e.timers.filter(function (t) {
                    return t.at <= target;
                }).sort(function (a, b) {
                    return a.at - b.at;
                })[0];
                if (!due) {
                    break;
                }
                e.timers = e.timers.filter(function (t) {
                    return t !== due;
                });
                e.now = due.at;
                due.fn();
                await flush();
            }
            e.now = target;
            await flush();
        };
        e.setVisible = async function (state) {
            e.visible = state;
            document.dispatchEvent(new Event('visibilitychange'));
            await flush();
        };
        e.start = async function (html) {
            document.getElementById('stage').innerHTML = html;
            (0, eval)(source);
            await flush();
        };
        e.count = function (needle) {
            return e.fetches.filter(function (f) {
                return f.url.indexOf(needle) !== -1;
            }).length;
        };
        e.stop = function () {
            e.listeners.forEach(function (l) {
                (l[0] === window ? realWinRemove : realRemove)(l[1], l[2], l[3]);
            });
            e.listeners = [];
            document.getElementById('stage').innerHTML = '';
        };

        return e;
    }

    window.oscFakeEnv = env;
    window.oscFlush = flush;
}());
