/*
 * Shared Osclass front-end/back-end UI helpers (no framework, no jQuery).
 * Loaded on both the admin and the public site so core widgets that render on
 * both (e.g. the item-form location fields) can rely on the same API.
 */

// Vanilla ARIA combobox — replaces jQuery-UI .autocomplete(). Attaches to
// `input`, debounce-fetches JSON [{id,label,value}] from opts.source (adds a
// `term` param), shows a listbox, and drives it with the standard combobox
// keyboard model. opts: { source, minLength=1, onSearch(input), onSelect(item) }.
// onSelect returning false suppresses writing item.value into the input.
//
// The dropdown's *structural* styles (fixed positioning, stacking) are applied
// inline here so the widget works with no stylesheet at all. Its *appearance*
// (background, border, option colours) lives on the .osc-ac-list / .osc-ac-option
// / .is-active classes, which any theme may style or replace. ui-common.css ships
// a plain, overridable default; the admin theme tokenises it for dark mode.
var oscAcSeq = 0;
function oscAutocomplete(input, opts) {
    if (!input || input.getAttribute('data-osc-ac-init')) {
        return;
    }
    input.setAttribute('data-osc-ac-init', '1');
    opts = opts || {};
    var minLength = opts.minLength != null ? opts.minLength : 1;
    var uid = 'osc-ac-' + (++oscAcSeq);

    input.setAttribute('autocomplete', 'off');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', uid);

    var list = document.createElement('ul');
    list.className = 'osc-ac-list';
    list.id = uid;
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    // Structural only — themes own the rest via .osc-ac-list.
    list.style.position = 'fixed';
    list.style.zIndex = '1080';
    list.style.margin = '0';
    document.body.appendChild(list);

    var items = [];
    var active = -1;
    var timer = null;

    function place() {
        var r = input.getBoundingClientRect();
        list.style.left = r.left + 'px';
        list.style.top = (r.bottom + 2) + 'px';
        list.style.width = r.width + 'px';
    }

    function close() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    }

    function open() {
        if (!items.length) {
            close();
            return;
        }
        place();
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    function render(data) {
        items = data || [];
        list.textContent = '';
        active = -1;
        items.forEach(function (item, i) {
            var li = document.createElement('li');
            li.className = 'osc-ac-option';
            li.id = uid + '-' + i;
            li.setAttribute('role', 'option');
            li.textContent = item.label != null ? item.label : item.value;
            li.addEventListener('mousedown', function (e) {
                e.preventDefault(); // keep focus so blur doesn't close before select
                choose(i);
            });
            list.appendChild(li);
        });
        open();
    }

    function highlight(i) {
        var opts2 = list.children;
        for (var k = 0; k < opts2.length; k++) {
            opts2[k].classList.toggle('is-active', k === i);
        }
        active = i;
        if (i >= 0 && opts2[i]) {
            input.setAttribute('aria-activedescendant', opts2[i].id);
            opts2[i].scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function choose(i) {
        var item = items[i];
        if (!item) {
            return;
        }
        var keep = opts.onSelect ? opts.onSelect(item) : undefined;
        if (keep !== false) {
            input.value = item.value != null ? item.value : (item.label || '');
        }
        close();
    }

    function fetchList() {
        var term = input.value;
        if (term.length < minLength) {
            close();
            return;
        }
        // source may be a string, or a function resolved at fetch time (so a
        // dependent field — e.g. region needs the current country — is current).
        var base = typeof opts.source === 'function' ? opts.source() : opts.source;
        var url = base + (base.indexOf('?') > -1 ? '&' : '?') + 'term=' + encodeURIComponent(term);
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) { render(Array.isArray(data) ? data : []); })
            .catch(function () { close(); });
    }

    input.addEventListener('input', function () {
        if (opts.onSearch) { opts.onSearch(input); }
        clearTimeout(timer);
        timer = setTimeout(fetchList, 180);
    });
    input.addEventListener('focus', function () {
        if (minLength === 0 && list.hidden) {
            clearTimeout(timer);
            timer = setTimeout(fetchList, 60);
        }
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (list.hidden) { fetchList(); } else { highlight(Math.min(active + 1, items.length - 1)); }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlight(Math.max(active - 1, 0));
        } else if (e.key === 'Enter') {
            if (!list.hidden && active >= 0) { e.preventDefault(); choose(active); }
        } else if (e.key === 'Escape') {
            close();
        }
    });
    input.addEventListener('blur', function () {
        setTimeout(close, 120);
    });
    window.addEventListener('scroll', function () { if (!list.hidden) { place(); } }, true);
    window.addEventListener('resize', function () { if (!list.hidden) { place(); } });
}
