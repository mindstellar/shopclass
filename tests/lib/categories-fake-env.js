/*
 * A fake fetch and an error log for the Categories screen browser tests. Every request is
 * logged in window.__calls as {action, method, url, query, body}; array fields such as ids[]
 * are logged as arrays under the bare name. window.__answers[action] is the reply: an object
 * (sent as JSON), a function of the call, or unset ({ok: true}). A reply with __status sets the
 * status; one with __fail makes fetch reject. category_edit_iframe answers from window.__drawers.
 */
(function () {
    // Frames do not run reliably under Chrome's virtual time, so a frame is a 16 ms timer.
    window.requestAnimationFrame = function (fn) {
        return setTimeout(function () {
            fn(performance.now());
        }, 16);
    };
    window.cancelAnimationFrame = function (id) {
        clearTimeout(id);
    };

    window.__errors = [];
    window.__calls = [];
    window.__answers = {};
    window.__drawers = window.__drawers || {};

    window.addEventListener('error', function (e) {
        window.__errors.push({msg: String(e.message), src: String(e.filename || '')});
    });
    window.addEventListener('unhandledrejection', function (e) {
        window.__errors.push({msg: String(e.reason && e.reason.message ? e.reason.message : e.reason), src: 'promise'});
    });
    var realError = console.error.bind(console);
    console.error = function () {
        window.__errors.push({msg: Array.prototype.join.call(arguments, ' '), src: 'console'});
        realError.apply(null, arguments);
    };

    function collect(pairs) {
        var out = {};
        pairs.forEach(function (p) {
            var k = p[0];
            var v = typeof p[1] === 'string' ? p[1] : '';
            if (k.slice(-2) === '[]') {
                k = k.slice(0, -2);
                out[k] = (out[k] || []).concat(v);
            } else {
                out[k] = v;
            }
        });
        return out;
    }

    function bodyOf(body) {
        if (body instanceof FormData) {
            return collect(Array.from(body.entries()));
        }
        if (body instanceof URLSearchParams) {
            return collect(Array.from(body.entries()));
        }
        if (typeof body === 'string') {
            return collect(Array.from(new URLSearchParams(body).entries()));
        }
        return {};
    }

    window.fetch = function (url, init) {
        init = init || {};
        var u = new URL(String(url), 'http://cat.test/oc-admin/index.php');
        var query = collect(Array.from(u.searchParams.entries()));
        var body = bodyOf(init.body);
        var action = query.action || body.action || '';
        var call = {action: action, method: (init.method || 'GET').toUpperCase(), url: String(url), query: query, body: body};
        window.__calls.push(call);

        var answer;
        var html = null;
        if (action === 'category_edit_iframe') {
            var parent = query.parent_id || '';
            html = window.__drawers[query.id && query.id !== '0' ? query.id : (parent ? 'new:' + parent : 'new')];
            if (html === undefined) {
                html = window.__drawers['new'] || '';
            }
        }
        var set = window.__answers[action];
        answer = typeof set === 'function' ? set(call) : set;
        if (answer === undefined) {
            answer = {ok: true};
        }
        if (answer && answer.__fail) {
            return Promise.reject(new TypeError('Network error'));
        }
        var status = answer && answer.__status ? answer.__status : 200;
        if (html !== null && window.__answers[action] === undefined) {
            return Promise.resolve(new Response(html, {status: status, headers: {'Content-Type': 'text/html'}}));
        }
        return Promise.resolve(new Response(JSON.stringify(answer), {status: status, headers: {'Content-Type': 'application/json'}}));
    };
}());
