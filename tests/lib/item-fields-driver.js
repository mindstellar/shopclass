/*
 * Drives the #plugin-hook loader and the date fields for tests/item-form-custom-fields.php
 * and writes what it saw into #osc-out.
 */
document.addEventListener('DOMContentLoaded', function () {
    var cat = document.getElementById('catId');
    var hook = document.getElementById('plugin-hook');
    var events = [];
    var target = null;
    document.addEventListener('osc:item-fields-loaded', function (e) {
        events.push(e.detail.catId);
        target = e.target.id;
    });
    var wait = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
    var pick = function (v) { cat.value = v; cat.dispatchEvent(new Event('change', { bubbles: true })); };
    var shown = function () {
        if (hook.querySelector('#meta_built_date')) { return 'two'; }
        return hook.textContent.trim() || '';
    };
    var setDate = function (id, v) {
        var el = document.getElementById(id);
        el.value = v;
        el.dispatchEvent(new Event('change', { bubbles: true }));
    };
    var out = { initialRequests: window.requests };

    (async function () {
        pick('1');
        pick('2');
        await wait(600);
        out.afterRace = { shown: shown(), events: events.slice(), scriptRan: window.hookScriptRan || 0 };

        out.dates = {
            dateShown: document.getElementById('meta_built_date').value,
            toShown: document.getElementById('meta_stay_to_date').value
        };
        setDate('meta_built_date', '2026-05-01');
        out.dates.dateWritten = document.getElementById('meta_built').value;
        setDate('meta_stay_from_date', '2026-05-01');
        out.dates.fromWritten = document.getElementById('meta_stay_from').value;
        setDate('meta_stay_to_date', '2026-05-03');
        out.dates.toWritten = document.getElementById('meta_stay_to').value;
        setDate('meta_built_date', '');
        out.dates.cleared = document.getElementById('meta_built').value;

        pick('3');
        await wait(100);
        out.afterError = { shown: shown(), events: events.slice() };

        pick('');
        await wait(50);
        out.afterClear = { empty: hook.matches(':empty'), events: events.slice(), target: target };

        document.getElementById('osc-out').textContent = btoa(JSON.stringify(out));
    })();
});
