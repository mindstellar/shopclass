/*
 * Named screen states for the Categories screen screenshots. window.catStates[name](t) drives
 * the page into one state; t is the helper set from categories-browser.php.
 */
(function () {
    var states = {
        default: async function () {},
        search: async function (t) {
            await t.type('#cat-q', 'car');
        },
        'disabled-tab': async function (t) {
            await t.click(t.tab('Disabled'));
        },
        'bulk-selected': async function (t) {
            await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
            await t.tick(t.row('Cars'));
            await t.tick(t.row('Motorcycles'));
        },
        'drawer-edit': async function (t) {
            await t.click(t.row('Vehicles').querySelector('.cat-name'));
            await t.drawer();
        },
        'drawer-addsub': async function (t) {
            await t.click(t.act('Vehicles', 'addsub'));
            await t.drawer();
        },
        move: async function (t) {
            await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
            await t.click(t.act('Cars', 'move'));
        },
        expanded: async function (t) {
            await t.click(document.querySelector('[data-cat-act="expand-all"]'));
        },
        confirm: async function (t) {
            await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
            var cars = t.row('Cars');
            window.__answers.category_delete_preview = {categories: 1 + (+cars.dataset.below || 0), listings: +cars.dataset.listings || 0, queued: false};
            await t.click(t.act('Cars', 'delete'));
        },
        'drawer-addsub-expiry': async function (t) {
            await t.click(t.act('Vehicles', 'addsub'));
            await t.drawer();
            var chip = document.querySelector('.cat-exp-chip input[value="60"]');
            if (chip) {
                chip.checked = true;
                chip.dispatchEvent(new Event('change', {bubbles: true}));
                await t.settle();
            }
        },
        'big-scroll': async function (t) {
            await t.click(document.querySelector('[data-cat-act="expand-all"]'));
            window.scrollTo(0, 20000);
            await t.settle();
        },
        empty: async function () {},
        big: async function (t) {
            await t.click(document.querySelector('[data-cat-act="expand-all"]'));
        }
    };
    window.catStates = states;
    window.catDriver = window.catDriver || async function (t) {
        var run = states[window.__state];
        if (!run) {
            throw new Error('unknown state ' + window.__state);
        }
        await run(t);
        await t.settle(400);
        var rows = t.$$('tr.cat-row');
        return {state: window.__state, rows: rows.length, shown: rows.filter(function (tr) { return !tr.hidden; }).length};
    };
}());
