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
        },
        // Drag states stop before the drop so the shot shows the slot, tint and ghost.
        'drag-nest': async function (t) {
            await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
            var g = t.drag(t.row('Cars').querySelector('.cat-handle'));
            await g.to(g.pos().x, t.row('Motorcycles').getBoundingClientRect().bottom - 4, 8);
            await g.by(40, 0, 4);
        },
        'drag-refused': async function (t) {
            await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
            await t.click(t.row('Car Parts').querySelector('.cat-disclosure'));
            await t.click(t.row('Tyres - Wheels').querySelector('.cat-disclosure'));
            var g = t.drag(t.row('Cars').querySelector('.cat-handle'));
            await g.to(g.pos().x, t.row('Winter tyres').getBoundingClientRect().bottom - 4, 8);
            await g.by(100, 0, 6);
        },
        'drag-keyboard': async function (t) {
            await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
            await t.key(t.row('Cars').querySelector('.cat-handle'), 'down');
            await t.key(t.row('Cars').querySelector('.cat-handle'), 'right');
        },
        'toast-undo': async function (t) {
            await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
            var g = t.drag(t.row('Cars').querySelector('.cat-handle'));
            await g.to(g.pos().x, t.row('Motorcycles').getBoundingClientRect().bottom - 4, 8);
            await g.by(40, 0, 4);
            await g.drop();
        },
        'drag-390': async function (t) {
            await t.click(t.row('Vehicles').querySelector('.cat-disclosure'));
            var g = t.drag(t.row('Cars').querySelector('.cat-handle'), {type: 'touch'});
            await g.hold(300);
            await g.to(g.pos().x, t.row('Motorcycles').getBoundingClientRect().bottom - 4, 8);
            await g.by(24, 0, 4);
        },
        // Menus screen: drag Car Parts out to the left.
        'menus-drag-left': async function (t) {
            var g = t.drag(t.grip('Car Parts'));
            await g.by(0, 4, 2);
            await g.by(-60, 0, 6);
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
