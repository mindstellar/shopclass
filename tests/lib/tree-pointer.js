/*
 * Pointer and key helpers for the tree drag tests. treePointer.install(t) adds t.drag(handle) and
 * t.key(el, key) to a helper set (it needs t.wait; t.settle is used when there). A drag is a chain
 * over synthetic PointerEvents: t.drag(h).hold(300).to(x, y, 8).by(dx, dy).escape().drop().
 * The chain can be awaited at any point. Moves go to the handle, as pointer capture would send them.
 */
(function () {
    function pause(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function centre(el) {
        var r = el.getBoundingClientRect();
        return {x: r.left + r.width / 2, y: r.top + r.height / 2};
    }

    function make(t) {
        var wait = t && t.wait ? t.wait : pause;
        var settle = t && t.settle ? t.settle : function (ms) { return wait(ms === undefined ? 250 : ms); };

        function drag(handle, opts) {
            opts = opts || {};
            var touch = opts.type === 'touch';
            var base = {
                pointerId: touch ? 2 : 1,
                pointerType: touch ? 'touch' : 'mouse',
                isPrimary: true,
                width: touch ? 24 : 1,
                height: touch ? 24 : 1,
                bubbles: true,
                cancelable: true,
                composed: true
            };
            var pos = opts.from || centre(handle);
            var queue = Promise.resolve();
            var chain = {};

            function fire(type, extra) {
                var target = handle.isConnected ? handle : document;
                var init = Object.assign({}, base, {clientX: pos.x, clientY: pos.y}, extra || {});
                target.dispatchEvent(new PointerEvent(type, init));
            }

            function step(fn) {
                queue = queue.then(fn);
                return chain;
            }

            async function glide(x, y, steps) {
                steps = Math.max(1, steps || 8);
                var x0 = pos.x;
                var y0 = pos.y;
                for (var i = 1; i <= steps; i++) {
                    pos = {x: x0 + (x - x0) * i / steps, y: y0 + (y - y0) * i / steps};
                    fire('pointermove', {buttons: 1, pressure: 0.5});
                    await wait(16);
                }
            }

            chain.hold = function (ms) { return step(function () { return wait(ms); }); };
            chain.to = function (x, y, steps) { return step(function () { return glide(x, y, steps); }); };
            chain.by = function (dx, dy, steps) {
                return step(function () { return glide(pos.x + dx, pos.y + (dy || 0), steps); });
            };
            chain.wait = chain.hold;
            chain.escape = function () {
                return step(function () {
                    document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', code: 'Escape', bubbles: true, cancelable: true}));
                    document.dispatchEvent(new KeyboardEvent('keyup', {key: 'Escape', code: 'Escape', bubbles: true, cancelable: true}));
                    return wait(16);
                });
            };
            chain.cancel = function () {
                return step(function () { fire('pointercancel', {buttons: 0}); return wait(16); });
            };
            chain.drop = function () {
                return step(async function () {
                    fire('pointerup', {buttons: 0, pressure: 0});
                    await settle(300);
                });
            };
            chain.pos = function () { return {x: pos.x, y: pos.y}; };
            chain.then = function (resolve, reject) { return queue.then(function () { return undefined; }).then(resolve, reject); };

            queue = queue.then(function () {
                fire('pointerdown', {button: 0, buttons: 1, pressure: 0.5});
            });
            return chain;
        }

        var keys = {up: 'ArrowUp', down: 'ArrowDown', left: 'ArrowLeft', right: 'ArrowRight', esc: 'Escape'};

        // Presses a key on el; resolves to true when the page took it (preventDefault).
        async function key(el, name) {
            var k = keys[name] || name;
            el.focus();
            var down = new KeyboardEvent('keydown', {key: k, code: k, bubbles: true, cancelable: true});
            el.dispatchEvent(down);
            el.dispatchEvent(new KeyboardEvent('keyup', {key: k, code: k, bubbles: true, cancelable: true}));
            await settle(300);
            return down.defaultPrevented;
        }

        return {drag: drag, key: key, centre: centre};
    }

    window.treePointer = {
        make: make,
        install: function (t) {
            var p = make(t);
            t.drag = p.drag;
            t.key = p.key;
            t.centre = p.centre;
            return t;
        }
    };
}());
