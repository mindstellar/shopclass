/* "Find a setting" in the top bar. The list is fetched on first use and filtered here. */
(function () {
  'use strict';

  var root = document.querySelector('[data-osc-find]');
  if (!root) {
    return;
  }
  var input = root.querySelector('input');
  var list = root.querySelector('.osc-find-list');
  var openBtn = root.querySelector('.osc-find-open');
  var closeBtn = root.querySelector('.osc-find-close');
  var status = root.querySelector('.osc-find-status');
  var rows = null;
  var loading = null;
  var active = -1;
  var timer = 0;

  function isPhone() {
    return window.getComputedStyle(openBtn).display !== 'none';
  }

  function load() {
    if (!loading) {
      loading = fetch(root.getAttribute('data-src'), {credentials: 'same-origin', headers: {Accept: 'application/json'}})
        .then(function (r) {
          if (!r.ok) {
            throw new Error(String(r.status));
          }
          return r.json();
        })
        .then(function (data) {
          rows = (data.rows || []).map(function (r) {
            return {
              type: r[0], label: r[1], path: r[2], url: r[3], text: r[4],
              l: r[1].toLowerCase(), p: r[2].toLowerCase(), t: String(r[4] || '').toLowerCase()
            };
          });
        })
        .catch(function () {
          loading = null;
        });
    }
    return loading;
  }

  function score(row, words, q) {
    var hay = row.l + ' ' + row.p + ' ' + row.t;
    var i;
    for (i = 0; i < words.length; i++) {
      if (hay.indexOf(words[i]) === -1) {
        return 0;
      }
    }
    var s;
    if (row.l === q) {
      s = 1000;
    } else if (row.l.indexOf(q) === 0) {
      s = 800;
    } else if (words.every(function (w) { return (' ' + row.l).indexOf(' ' + w) !== -1; })) {
      s = 600;
    } else if (words.every(function (w) { return row.l.indexOf(w) !== -1; })) {
      s = 400;
    } else if (words.every(function (w) { return (row.l + ' ' + row.p).indexOf(w) !== -1; })) {
      s = 200;
    } else {
      s = row.type === 0 ? 500 : 100;
    }
    return s + (row.type === 0 ? 30 : row.type === 1 ? 15 : 0);
  }

  function close() {
    list.hidden = true;
    list.textContent = '';
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    active = -1;
  }

  function mark(n) {
    var items = list.querySelectorAll('li[data-url]');
    if (!items.length) {
      return;
    }
    active = (n + items.length) % items.length;
    items.forEach(function (li, i) {
      li.classList.toggle('is-active', i === active);
      li.setAttribute('aria-selected', i === active ? 'true' : 'false');
    });
    input.setAttribute('aria-activedescendant', items[active].id);
    items[active].scrollIntoView({block: 'nearest'});
  }

  function render() {
    var q = input.value.trim().toLowerCase();
    list.textContent = '';
    active = -1;
    if (!q) {
      close();
      return;
    }
    if (!rows) {
      return;
    }
    var words = q.split(/\s+/);
    var hits = [];
    rows.forEach(function (row, i) {
      var s = score(row, words, q);
      if (s) {
        hits.push({row: row, s: s, i: i});
      }
    });
    hits.sort(function (a, b) { return b.s - a.s || a.i - b.i; });
    hits = hits.slice(0, 30);
    hits.forEach(function (h, n) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      var path = document.createElement('small');
      li.id = 'osc-find-' + n;
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', 'false');
      li.setAttribute('data-url', h.row.url);
      a.href = h.row.url;
      a.tabIndex = -1;
      a.textContent = h.row.label;
      path.textContent = h.row.path;
      li.appendChild(a);
      li.appendChild(path);
      list.appendChild(li);
    });
    if (!hits.length) {
      var empty = document.createElement('li');
      empty.className = 'osc-find-empty';
      empty.textContent = root.getAttribute('data-none');
      list.appendChild(empty);
    }
    status.textContent = hits.length ? root.getAttribute('data-count').replace('%d', String(hits.length)) : root.getAttribute('data-none');
    list.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    mark(0);
  }

  function search() {
    if (rows) {
      render();
    } else {
      load().then(render);
    }
  }

  function openPanel() {
    root.classList.add('is-open');
    openBtn.setAttribute('aria-expanded', 'true');
    input.focus();
    load();
  }

  function closePanel() {
    root.classList.remove('is-open');
    openBtn.setAttribute('aria-expanded', 'false');
    input.value = '';
    close();
  }

  openBtn.addEventListener('click', openPanel);
  closeBtn.addEventListener('click', function () {
    closePanel();
    openBtn.focus();
  });
  input.addEventListener('focus', load);
  input.addEventListener('input', function () {
    window.clearTimeout(timer);
    timer = window.setTimeout(search, 120);
  });
  input.addEventListener('keydown', function (ev) {
    var items = list.querySelectorAll('li[data-url]');
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      ev.preventDefault();
      if (list.hidden) {
        search();
      } else {
        mark(active + (ev.key === 'ArrowDown' ? 1 : -1));
      }
    } else if (ev.key === 'Enter') {
      if (active >= 0 && items[active]) {
        ev.preventDefault();
        window.location.href = items[active].getAttribute('data-url');
      }
    } else if (ev.key === 'Escape') {
      ev.preventDefault();
      if (!list.hidden || input.value) {
        input.value = '';
        close();
      } else if (isPhone()) {
        closePanel();
        openBtn.focus();
      } else {
        input.blur();
      }
    }
  });
  document.addEventListener('keydown', function (ev) {
    var t = ev.target;
    if (ev.key !== '/' || ev.ctrlKey || ev.metaKey || ev.altKey) {
      return;
    }
    if (t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable)) {
      return;
    }
    ev.preventDefault();
    if (isPhone()) {
      openPanel();
    } else {
      input.focus();
    }
  });
  document.addEventListener('click', function (ev) {
    if (!root.contains(ev.target)) {
      close();
    }
  });
})();
