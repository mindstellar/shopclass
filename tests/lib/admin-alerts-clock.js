// A controllable setTimeout/clearTimeout and Date.now, so auto-hide timing runs without real waits.
(function () {
    var now = 1000000;
    var seq = 0;
    var timers = [];
    window.setTimeout = function (fn, ms) {
        var t = {id: ++seq, at: now + (ms || 0), fn: fn};
        timers.push(t);
        return t.id;
    };
    window.clearTimeout = function (id) {
        timers = timers.filter(function (t) { return t.id !== id; });
    };
    Date.now = function () { return now; };
    window.alertsClock = {
        tick: function (ms) {
            var end = now + ms;
            for (;;) {
                var due = timers.filter(function (t) { return t.at <= end; }).sort(function (a, b) { return a.at - b.at; })[0];
                if (!due) { break; }
                timers = timers.filter(function (t) { return t !== due; });
                now = due.at;
                due.fn();
            }
            now = end;
        }
    };
})();
