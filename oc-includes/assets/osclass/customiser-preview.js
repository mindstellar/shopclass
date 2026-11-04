/*
 * Answers the admin Customise screen while it shows a page in a frame. Only loaded in the preview.
 * It trusts messages from its own origin (and the origin named in the osc-customiser meta tag),
 * marks and scrolls to a block on request, and reports the block that was clicked.
 */
(function () {
  'use strict';

  var TYPE = 'osc-customiser';
  var SELECTED = 'osc-block-selected';
  var meta = document.querySelector('meta[name="osc-customiser"]');
  var adminOrigin = '';
  if (meta) {
    try { adminOrigin = new URL(meta.getAttribute('content') || '', location.href).origin; } catch (e) { adminOrigin = ''; }
  }

  function trusted(origin) {
    return origin === location.origin && (adminOrigin === '' || adminOrigin === location.origin);
  }

  function findBlock(ref) {
    var nodes = document.querySelectorAll('[data-osc-block]');
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].getAttribute('data-osc-block') === String(ref)) { return nodes[i]; }
    }
    return null;
  }

  // The bottom of a fixed or sticky theme header, so a block is never scrolled or tagged under it.
  function topInset() {
    var bottom = 0;
    var seen = [];
    var hits = document.elementsFromPoint(window.innerWidth / 2, 1);
    for (var i = 0; i < hits.length; i++) {
      for (var el = hits[i]; el && el !== document.documentElement; el = el.parentElement) {
        if (seen.indexOf(el) !== -1) { break; }
        seen.push(el);
        var pos = getComputedStyle(el).position;
        var r = el.getBoundingClientRect();
        if ((pos === 'fixed' || pos === 'sticky') && r.top <= 1 && r.height < window.innerHeight / 2) {
          bottom = Math.max(bottom, r.bottom);
        }
      }
    }
    document.documentElement.style.setProperty('--osc-preview-top', Math.round(bottom) + 'px');
    return bottom;
  }

  // A block as wide as the page gets its outline inside its edge; a tag with no room above goes inside the outline.
  function place(block) {
    if (!block) { return; }
    var rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
    var r = block.getBoundingClientRect();
    var pageTop = r.top + window.scrollY;
    var gap = 0.625 * rem;
    var flush = r.left < gap || r.right > document.documentElement.clientWidth - gap || pageTop < gap;
    block.classList.toggle('osc-block-flush', flush);
    var need = (flush ? 0 : gap) + 1.4 * rem;
    var inset = topInset();
    var inside = r.top - need < inset || pageTop - need < 0;
    block.classList.toggle('osc-block-tag-in', inside);
    // A block whose top runs under the header keeps its tag just below the header.
    if (inside && r.top < inset) {
      block.style.setProperty('--osc-block-tag-top', Math.min(inset - r.top + 2, Math.max(r.height - 1.4 * rem, 0)) + 'px');
    } else {
      block.style.removeProperty('--osc-block-tag-top');
    }
  }

  var hovered = null;
  var queued = false;

  function replace() {
    if (queued) { return; }
    queued = true;
    requestAnimationFrame(function () {
      queued = false;
      place(document.querySelector('.' + SELECTED));
      place(hovered);
    });
  }

  function select(ref, name) {
    var current = document.querySelectorAll('.' + SELECTED);
    for (var i = 0; i < current.length; i++) { current[i].classList.remove(SELECTED); }
    var block = findBlock(ref);
    if (!block) { return; }
    block.classList.add(SELECTED);
    if (typeof name === 'string' && name !== '') { block.setAttribute('data-osc-block-name', name); }
    topInset();
    block.scrollIntoView({ block: 'nearest', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    place(block);
  }

  function rename(ref, name) {
    var block = findBlock(ref);
    if (block && typeof name === 'string' && name !== '') { block.setAttribute('data-osc-block-name', name); }
  }

  document.addEventListener('mouseover', function (event) {
    var block = event.target instanceof Element ? event.target.closest('[data-osc-block]') : null;
    if (block !== hovered) {
      hovered = block;
      place(block);
    }
  });
  window.addEventListener('scroll', replace, { passive: true });
  window.addEventListener('resize', replace);

  window.addEventListener('message', function (event) {
    var d = event.data;
    if (!trusted(event.origin) || !d || typeof d !== 'object' || d.type !== TYPE) { return; }
    if (d.text && typeof d.text === 'object') {
      window.parent.postMessage({ type: TYPE, missed: true }, location.origin);
    }
    if (d.select !== undefined) {
      select(d.select, d.name);
    }
    if (d.rename && typeof d.rename === 'object') {
      rename(d.rename.ref, d.rename.name);
    }
  });

  document.addEventListener('click', function (event) {
    event.preventDefault();
    var block = event.target instanceof Element ? event.target.closest('[data-osc-block]') : null;
    if (block) {
      window.parent.postMessage({ type: TYPE, picked: block.getAttribute('data-osc-block') }, location.origin);
    }
  }, true);

  document.addEventListener('submit', function (event) { event.preventDefault(); }, true);

  topInset();
  window.parent.postMessage({ type: TYPE, ready: true }, location.origin);
})();
