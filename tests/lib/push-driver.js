/*
 * Drives assets/osclass/push.js and push-worker.js for tests/push-browser.php. The service
 * worker container, push manager, Notification and fetch are fakes installed before each
 * run, and the worker runs in the page under a fake self.
 */
(function () {
    var realSetTimeout = window.setTimeout.bind(window);
    var page = window.__page;
    var workerSource = window.__worker;
    var stageHtml = window.__stage;
    var bellHtml = window.__bellStage;

    async function settle() {
        for (var i = 0; i < 8; i++) {
            await new Promise(function (resolve) {
                realSetTimeout(resolve, 0);
            });
        }
    }

    function fields(body) {
        if (!(body instanceof FormData)) {
            return null;
        }
        var out = {};
        body.forEach(function (value, name) {
            out[name] = String(value);
        });
        return out;
    }

    function respond(r) {
        if (r instanceof Error) {
            return Promise.reject(r);
        }
        return Promise.resolve(new Response(r.body === undefined ? null : r.body, {status: r.status, headers: r.headers || {}}));
    }

    function pageEnv(opts) {
        var e = {registers: [], subscribes: [], fetches: [], log: [], reg: null, sub: null, asks: [], clicking: false};
        var makeSub = function (endpoint) {
            return {
                endpoint: endpoint,
                unsubscribe: function () {
                    e.log.push('unsubscribe');
                    e.sub = null;
                    return Promise.resolve(true);
                }
            };
        };
        var makeReg = function (scriptURL) {
            return {
                scope: new URL('.', scriptURL).href,
                active: {scriptURL: scriptURL, state: 'activated'},
                pushManager: {
                    getSubscription: function () {
                        return Promise.resolve(e.sub);
                    },
                    subscribe: function (o) {
                        e.subscribes.push({userVisibleOnly: o.userVisibleOnly, key: Array.from(o.applicationServerKey)});
                        if (opts.subscribeFails) {
                            return Promise.reject(new Error('NotAllowedError'));
                        }
                        e.sub = makeSub('https://push.example/send/new');
                        return Promise.resolve(e.sub);
                    }
                }
            };
        };
        if (opts.existing) {
            e.reg = makeReg(opts.existing);
            e.sub = makeSub('https://push.example/send/old');
        }

        if (opts.supported) {
            Object.defineProperty(navigator, 'serviceWorker', {configurable: true, value: {
                register: function (url) {
                    e.registers.push({url: String(url), args: arguments.length});
                    e.log.push('register');
                    e.reg = e.reg || makeReg(new URL(url, location.href).href);
                    return Promise.resolve(e.reg);
                },
                getRegistration: function () {
                    return Promise.resolve(e.reg || undefined);
                }
            }});
            window.PushManager = function () {};
        } else {
            delete navigator.serviceWorker;
            delete Navigator.prototype.serviceWorker;
            delete window.PushManager;
        }
        window.Notification = {permission: opts.permission || 'default', requestPermission: function () {
            e.asks.push(e.clicking ? 'in-click' : 'after-click');
            e.log.push('permission');
            window.Notification.permission = opts.answer || 'granted';
            return Promise.resolve(window.Notification.permission);
        }};
        window.fetch = function (url, init) {
            init = init || {};
            var f = {url: String(url), method: init.method || 'GET', credentials: init.credentials || null, fields: fields(init.body)};
            e.fetches.push(f);
            e.log.push(f.method + ' ' + f.url);
            return respond(opts.route(f));
        };
        e.start = async function () {
            document.getElementById('stage').innerHTML = (opts.bell ? bellHtml : stageHtml) + (opts.toggle ? '<button type="button" id="menu-toggle" data-osc-push-toggle>Menu</button>' : '');
            e.form = document.querySelector('form[data-osc-push]');
            e.button = e.form.querySelector('button');
            e.note = e.form.querySelector('[data-osc-push-note]');
            (0, eval)(page);
            e.hiddenAtStart = e.form.hidden;
            await settle();
        };
        e.click = async function () {
            e.clicking = true;
            e.button.click();
            e.clicking = false;
            await settle();
        };
        e.clickToggle = async function () {
            e.clicking = true;
            document.getElementById('menu-toggle').click();
            e.clicking = false;
            await settle();
        };
        e.state = function () {
            return {
                hidden: e.form.hidden, label: e.button.textContent, disabled: e.button.disabled, note: e.note.textContent,
                pressed: e.button.getAttribute('aria-pressed'), aria: e.button.getAttribute('aria-label'), title: e.button.title
            };
        };
        return e;
    }

    function workerEnv(opts) {
        var w = {handlers: {}, fetches: [], notes: [], opened: [], focused: [], subs: [], matchOpts: null};
        var fake = {
            location: {href: opts.scriptUrl},
            registration: {
                scope: opts.scope,
                showNotification: function (title, o) {
                    w.notes.push({title: title, body: o.body, tag: o.tag, url: o.data && o.data.url});
                    return Promise.resolve();
                },
                pushManager: {
                    subscribe: function (o) {
                        w.subs.push({userVisibleOnly: o.userVisibleOnly, key: Array.from(new Uint8Array(o.applicationServerKey))});
                        return Promise.resolve({endpoint: 'https://push.example/send/renewed'});
                    }
                }
            },
            clients: {
                matchAll: function (o) {
                    w.matchOpts = o;
                    return Promise.resolve((opts.clients || []).map(function (url) {
                        return {url: url, focus: function () {
                            w.focused.push(url);
                            return Promise.resolve(this);
                        }};
                    }));
                },
                openWindow: function (url) {
                    w.opened.push(url);
                    return Promise.resolve(null);
                }
            },
            addEventListener: function (type, fn) {
                w.handlers[type] = fn;
            },
            skipWaiting: function () {},
            fetch: function (url, init) {
                init = init || {};
                var f = {url: String(url), method: init.method || 'GET', credentials: init.credentials || null, fields: fields(init.body)};
                w.fetches.push(f);
                return respond(opts.route(f));
            }
        };
        (new Function('self', workerSource))(fake);
        w.fire = async function (type, extra) {
            var waits = [];
            var event = Object.assign({waitUntil: function (p) {
                waits.push(p);
            }}, extra || {});
            w.handlers[type](event);
            await Promise.all(waits);
            await settle();
        };
        return w;
    }

    var json = function (status, body) {
        return {status: status, body: JSON.stringify(body), headers: {'Content-Type': 'application/json'}};
    };

    async function main() {
        var out = {};
        var e;

        // A browser with no push support: the form is never shown.
        e = pageEnv({supported: false, route: function () {
            return json(200, {});
        }});
        await e.start();
        out.unsupported = {state: e.state(), fetches: e.fetches.length};

        // Supported, no subscription: shown as off; a click registers, subscribes, posts.
        e = pageEnv({supported: true, route: function () {
            return json(200, {ok: true, id: 'abc'});
        }});
        await e.start();
        out.fresh = {hiddenAtStart: e.hiddenAtStart, state: e.state(), fetches: e.fetches.length};
        await e.click();
        out.freshClick = {registers: e.registers, subscribes: e.subscribes, fetches: e.fetches, state: e.state(), asks: e.asks, log: e.log};

        // The permission prompt is dismissed or refused: nothing is registered or posted.
        e = pageEnv({supported: true, answer: 'default', route: function () {
            return json(200, {ok: true});
        }});
        await e.start();
        await e.click();
        out.dismissed = {registers: e.registers.length, subscribes: e.subscribes.length, fetches: e.fetches.length, state: e.state()};
        e = pageEnv({supported: true, answer: 'denied', route: function () {
            return json(200, {ok: true});
        }});
        await e.start();
        await e.click();
        out.refusedPermission = {registers: e.registers.length, subscribes: e.subscribes.length, fetches: e.fetches.length, state: e.state()};

        // An existing subscription the server knows: one status GET, shown as on; a click turns it off.
        var dataset = document.createElement('div');
        dataset.innerHTML = stageHtml;
        var workerUrl = new URL(dataset.querySelector('form').dataset.workerUrl, location.href).href;
        e = pageEnv({supported: true, existing: workerUrl, route: function (f) {
            if (f.url.indexOf('push_status') !== -1) {
                return json(200, {active: true});
            }
            return json(200, {ok: true});
        }});
        await e.start();
        out.existing = {fetches: e.fetches.slice(), registers: e.registers.length, state: e.state()};
        e.log = [];
        await e.click();
        out.existingClick = {log: e.log, state: e.state(), dropped: e.sub === null, asks: e.asks.length};

        // The server no longer has it: shown as off.
        e = pageEnv({supported: true, existing: workerUrl, route: function () {
            return json(200, {active: false});
        }});
        await e.start();
        out.inactive = e.state();
        out.inactiveDropped = e.sub === null;

        // The subscribe POST is refused: the browser drops the subscription and says so.
        e = pageEnv({supported: true, route: function () {
            return json(422, {error: 'invalid'});
        }});
        await e.start();
        await e.click();
        out.refused = {log: e.log, state: e.state()};

        // Blocked in the browser.
        e = pageEnv({supported: true, permission: 'denied', route: function () {
            return json(200, {});
        }});
        await e.start();
        out.denied = {state: e.state(), fetches: e.fetches.length};

        // The bell variant: an icon button that flips aria-pressed and its label, not its text.
        e = pageEnv({supported: true, bell: true, route: function () {
            return json(200, {ok: true});
        }});
        await e.start();
        out.bellFresh = e.state();
        await e.click();
        out.bellOn = e.state();
        e.log = [];
        await e.click();
        out.bellOff = e.state();
        out.bellOffLog = e.log;

        // A menu item with data-osc-push-toggle clicks the form's button.
        e = pageEnv({supported: true, bell: true, toggle: true, route: function () {
            return json(200, {ok: true});
        }});
        await e.start();
        await e.clickToggle();
        out.toggled = {state: e.state(), subscribes: e.subscribes.length, fetches: e.fetches.length, asks: e.asks};

        // The worker. Its own URL carries its texts; the scope is a sub-directory.
        var scope = 'https://example.test/sub/';
        var check = function (r) {
            return function (f) {
                return f.url.indexOf('messages_check') !== -1 ? r : json(404, {});
            };
        };
        var w = workerEnv({scriptUrl: workerUrl, scope: scope, route: check(json(200, {unread: 3, latest: '2026-01-01'}))});
        await w.fire('push');
        out.push3 = {fetches: w.fetches, notes: w.notes};

        w = workerEnv({scriptUrl: workerUrl, scope: scope, route: check(json(200, {unread: 1}))});
        await w.fire('push');
        out.push1 = w.notes;

        w = workerEnv({scriptUrl: workerUrl, scope: scope, route: check(json(401, {error: 'sign_in'}))});
        await w.fire('push');
        out.push401 = w.notes;

        w = workerEnv({scriptUrl: workerUrl, scope: scope, route: check(json(200, {unread: 0}))});
        await w.fire('push');
        out.push0 = w.notes;

        w = workerEnv({scriptUrl: workerUrl, scope: scope, route: function () {
            return new Error('offline');
        }});
        await w.fire('push');
        out.pushOffline = w.notes;

        var closed = 0;
        var clickEvent = function (url) {
            return {notification: {data: {url: url}, close: function () {
                closed++;
            }}};
        };
        w = workerEnv({scriptUrl: workerUrl, scope: scope, route: check(json(200, {}))});
        await w.fire('notificationclick', clickEvent('https://example.test/sub/user/messages'));
        out.clickNone = {opened: w.opened, focused: w.focused, matchOpts: w.matchOpts, closed: closed};

        w = workerEnv({scriptUrl: workerUrl, scope: scope, clients: ['https://example.test/sub/item/5', 'https://example.test/sub/user/messages'], route: check(json(200, {}))});
        await w.fire('notificationclick', clickEvent('https://example.test/sub/user/messages'));
        out.clickOpen = {opened: w.opened, focused: w.focused};

        var oldKey = new Uint8Array([4, 9, 8, 7]).buffer;
        w = workerEnv({scriptUrl: workerUrl, scope: scope, route: function (f) {
            if (f.method === 'GET') {
                return {status: 200, body: '<form><input type=\'hidden\' name=\'CSRFName\' value=\'nm\' />\n<input type=\'hidden\' name=\'CSRFToken\' value=\'tk\' /></form>'};
            }
            return json(200, {ok: true});
        }});
        await w.fire('pushsubscriptionchange', {oldSubscription: {options: {applicationServerKey: oldKey}}});
        out.change = {subs: w.subs, fetches: w.fetches};

        document.getElementById('osc-out').textContent = btoa(JSON.stringify(out));
    }

    main().catch(function (err) {
        document.getElementById('osc-out').textContent = btoa(JSON.stringify({error: String(err && err.stack || err)}));
    });
}());
