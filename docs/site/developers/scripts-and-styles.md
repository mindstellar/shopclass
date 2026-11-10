---
title: Scripts and styles
description: Load JavaScript and CSS from a ShopClass plugin or theme with the enqueue functions, and what changed now that jQuery is gone from core.
sidebar:
  order: 9
---

Plugins and themes load their own JavaScript and CSS through the **enqueue**
functions rather than printing `<script>` tags. The point is deduplication: two
plugins that both want the same library end up with one copy, in the right
order, instead of two.

## The functions

**JavaScript**

```php
osc_register_script($id, $url, $dependencies = null);  // declare it exists
osc_enqueue_script($id);                               // actually load it
osc_unregister_script($id);                            // undeclare
osc_remove_script($id);                                // declared, but do not load
osc_enqueue_script_code($code, $dependencies = null, $id = null); // inline
```

**CSS**

```php
osc_register_style($id, $url, $dependencies = null);
osc_enqueue_style($id, $url = null);                   // url optional if registered
osc_remove_style($id);
```

Registering says *this asset exists, at this URL, and needs these things first*.
Enqueuing says *this page needs it*. Register once; enqueue wherever it is
needed.

## Where to hook

```php
function myplugin_assets()
{
    osc_register_script(
        'myplugin-widget',
        osc_base_url() . 'oc-content/plugins/myplugin/js/widget.js'
    );
    osc_enqueue_script('myplugin-widget');

    osc_enqueue_style(
        'myplugin-css',
        osc_base_url() . 'oc-content/plugins/myplugin/css/widget.css'
    );
}

osc_add_hook('init', 'myplugin_assets');        // public site
osc_add_hook('init_admin', 'myplugin_assets');  // admin panel
```

## jQuery is not loaded for you

This is the change that catches ported Osclass plugins.

Since 6.4, core no longer ships or registers `jquery`, `jquery-ui` or `jquery-validate`.
A theme or plugin that uses them must ship its own copy.

The front end loads **nothing** by default, and the admin panel registers
Bootstrap 5, not jQuery:

| Registered in the admin | Depends on |
|---|---|
| `bootstrap5` | `popper` |
| `popper` | none |
| `sortablejs` | none |
| `admin-osc`, `admin-ui-osc`, `admin-categories`, `admin-location` | core admin behaviour |

If your code needs jQuery, ship it and register it yourself:

```php
osc_register_script('jquery', osc_base_url() . 'oc-content/plugins/myplugin/js/jquery.min.js');
osc_register_script('myplugin-widget', $url, 'jquery');
osc_enqueue_script('myplugin-widget');   // pulls jquery in first
```

Better: most of what plugins used jQuery for (selectors, `fetch`, class
toggling, event delegation) is a few lines of plain JavaScript in a browser
from the last decade. Dropping the dependency makes your plugin lighter and
removes a class of version conflicts entirely.

## Naming

The `$id` is a global namespace shared with every other plugin on the install.

- Prefix ids with your plugin folder: `myplugin-widget`, not `widget`.
- For a **third-party library**, use the library's ordinary name: `fancybox`,
  `chartjs`, `flatpickr`. Two plugins registering the same library under the
  same id load it once; register it as `my_strange_name` and the visitor
  downloads it twice.

## Location autocomplete without writing JavaScript

A theme that needs a country, region or city field does not have to write its own
autocomplete. Core binds every `input[data-ac]` on the page to its own endpoints.
Render the input, and nothing else.

```html
<input type="text" name="city" id="city"
       data-ac="location_cities"
       data-ac-url="<?php echo osc_esc_html(osc_base_url(true)); ?>"
       data-ac-target="#cityId"
       data-ac-scope="#regionId"
       data-ac-scope-param="region">
<input type="hidden" name="cityId" id="cityId">
```

| Attribute | What it is |
|---|---|
| `data-ac` | The endpoint: `location_countries`, `location_regions` or `location_cities`. |
| `data-ac-url` | Where to post. Always `osc_base_url(true)`. |
| `data-ac-target` | Selector of the hidden input that receives the chosen row's id. |
| `data-ac-scope` | Selector whose value narrows the search: the region, for a city. |
| `data-ac-scope-param` | The parameter name that value is sent as. |
| `data-ac-clears` | Comma-separated selectors emptied when this field changes. |

The endpoints match on the first letters of a name and return `{id, value}` rows.

If your theme already ships its own binder, it wins the race and core's becomes a
no-op for that field, so adopting this is safe and can be done one field at a time.

## Search box suggestions without writing JavaScript

Add `data-osc-suggest` to a search input. While someone types, core lists matching listings (a
thumbnail, the title, the price and the place) from `GET /search/suggest`; a click opens the listing. A search plugin may
add suggested searches; a click on one fills the box and submits its form.

```php
<input type="search" name="sPattern" <?php echo osc_search_suggest_attributes(5); ?>>
```

`osc_search_suggest_attributes()` prints `data-osc-suggest`, the limit, and the translated "Searches"
and "Listings" headings shown when both groups are in the list.

`data-osc-suggest-limit` is 1 to 10 (default 5). Core matches listing titles, by word start, once a
word has three letters. It needs the page token, which `osc_head()` prints, and `osc-ui-common`, which core loads on the home,
search and post pages. On other pages, call `osc_enqueue_script('osc-ui-common')` and
`osc_enqueue_style('osc-ui-common')`. Style `.osc-ac-list`, `.osc-ac-option`, `.osc-suggest-heading`,
`.osc-suggest-thumb`, `.osc-suggest-title`, `.osc-suggest-meta`, `.osc-suggest-count` and
`.osc-ac-option.is-search`.

## Show and hide a panel in the admin

`osc.collapse` (in `admin-ui-osc`, loaded on every admin page) opens and closes a panel with
no framework. Give the button `data-osc-collapse` with the panel's selector, and the panel
the class `collapse`, plus `show` when it starts open:

```html
<button type="button" data-osc-collapse="#more" aria-controls="more" aria-expanded="false">More</button>
<div id="more" class="collapse">…</div>
```

The button's `aria-expanded` and its `collapsed` class follow the panel once it is used; a
panel that starts open needs `aria-expanded="true"` on its button in the markup. Use the
`osc.*` namespace and `data-osc-*` attributes the same way for new admin components. Panels that share
a `data-osc-parent` value work as an accordion: opening one closes the others.

From script, call `osc.collapse.show(panel)`, `osc.collapse.hide(panel)` or
`osc.collapse.toggle(panel)`. The panel fires these events, which bubble:

| Event | When |
|---|---|
| `osc:collapse:show` | before it opens; `preventDefault()` stops it |
| `osc:collapse:shown` | once it is open |
| `osc:collapse:hide` | before it closes; `preventDefault()` stops it |
| `osc:collapse:hidden` | once it is closed |

The admin menu uses it, so a plugin can listen for `osc:collapse:shown` on
`#sidebar-wrapper` to know a section was opened.

## Slide-in panels and dropdowns in the admin

`osc.offcanvas` slides in a panel with class `offcanvas offcanvas-start` from a button with
`data-osc-offcanvas="#panel"`. Escape or a click on the backdrop closes it. From script, call
`osc.offcanvas.show(panel)` or `osc.offcanvas.hide(panel)`. The panel fires
`osc:offcanvas:show`, `shown`, `hide` and `hidden`.

`osc.dropdown` opens the `.dropdown-menu` beside a button with `data-osc-dropdown`, inside a
`.dropdown` wrapper. An outside click or Escape closes it, and the arrow keys move between its
`.dropdown-item` links. Add `dropdown-menu-end` to align the menu to the button's end edge.

```html
<div class="dropdown">
    <button type="button" data-osc-dropdown aria-expanded="false">Actions</button>
    <div class="dropdown-menu dropdown-menu-end">
        <a class="dropdown-item" href="…">Edit</a>
    </div>
</div>
```

## Drag to reorder in the admin

`osc.sortable(el, opts)` makes a list draggable with the mouse or touch. It wraps
[SortableJS](https://github.com/SortableJS/Sortable), so add `sortablejs` to your script's
dependencies. `opts` are SortableJS options, merged over shared defaults: a 250 ms hold on
touch, the `is-drag-ghost`, `is-drag-chosen` and `is-drag-active` classes, no animation under
reduced motion, and form fields that do not start a drag. It returns the Sortable instance, the
same one on a second call, or `null` when SortableJS or the element is missing.

For a tree, add `nested: true` and call it once per level with a shared `group`. Dragging a row
into another row's child list nests it; dragging it out un-nests it.

```js
osc.sortable(list, {
    handle: '.my-handle',
    onEnd: function (evt) { /* save the new order */ }
});

document.querySelectorAll('.my-tree, .my-tree ul').forEach(function (ul) {
    osc.sortable(ul, {group: 'my-tree', nested: true});
});
```

### Trees: `tree` mode

A list of rows with a depth each, such as Categories and Menus, runs on the admin's own pointer
and keyboard code, not SortableJS. Pass `tree` and `osc.sortable` returns a controller instead
(`null` if `tree-drag.js` is not loaded):

```js
var drag = osc.sortable(tbodyOrList, {
    handle: '.my-handle',            // default [data-tree-handle]
    tree: {
        rows: 'li.item',             // selector for one row
        depth: function (row) { return +row.dataset.depth; },   // 0-based
        maxLevels: 3,                // 0 or missing = no limit
        label: function (parentRow) { return parentRow ? 'Inside ' + name(parentRow) : 'Top level'; },
        tooDeep: 'Too deep',         // slot text when a drop would pass maxLevels
        ghost: function (row, below) { return {name: name(row), more: below ? '+ ' + below : ''}; },
        canMove: function () { return true; },          // false turns dragging and keys off
        isCollapsed: function (row) { return false; },  // with open(row): hover opens a group
        open: function (row) {},
        indent: null,                // pixels per level, or null to measure
        slotOffset: function () { return 0; },          // extra inline-start space, in pixels
        keys: true,                  // arrow keys on the handle
        hint: ['move up or down', 'put under the item above', 'move out'],
        commit: function (move) {},  // move = {el, from, at, depth, parentEl, index, via}
        cancelled: function (reason, info) {}
    }
});
```

`commit` runs after a drop or a key move and must reorder the rows itself; it may return a
promise, which a following move waits for. Put the rows back yourself if saving fails. `via` is `'drag'` or `'key'`. The controller has
`cancel()`, `refresh()`, `destroy()` and a read-only `dragging`.

The rules for the list live in `osc.flatTree`, which works on a pre-order array of objects with a
0-based `depth`: `blockEnd(items, i)`, `height(items, i)`, `range`, `parentAt`, `fits`,
`clamp(items, maxLevels)`, `moveBlock(items, from, at, depth)`, `keyTarget`, `siblingIndex` and
`position`. Use it to keep your data in step with the rows.

## Messages

| Kind | Use when | How |
|---|---|---|
| Result | An action just finished | `osc_add_flash_*_message()` before a redirect, or `osc.notify()` after AJAX |
| Undo | A result that can be undone | `osc.notify(type, text, {undo})` |
| Callout | A fact about this screen | `osc_admin_callout($tone, $text)` |

`osc.notify(type, text, {undo, ms})` is the one way to show a result from a script. `type` is `ok`,
`info`, `warning` or `error`. It speaks the text and shows it in `#message-wrapper`, which stays on
screen under the top bar. With `undo` it shows as a toast at the foot of the screen. It returns
`{close}`. `setJsMessage(type, text)` and `osc.toast()` still work and go through it.

Up to three results are in view, errors first, then an "and N more" button. `ok` and `info` hide
after 7 seconds (with a close button) and wait while the pointer or focus is on them. `error` and
`warning` stay until closed. A `.flashmessage` outside `#message-wrapper` keeps its own behaviour.

Do not add a flash on every page load for a problem that lasts. Show a callout on the screen that
can fix it.

## Toasts and announcements

`osc.toast(text, {undo, ms})` shows one short note at the foot of the screen. A new toast replaces
the old one. `undo` is a function; it adds an **Undo** button that runs it once. `ms` is how long
it stays (default 7000). A toast never takes focus, stays while the pointer or focus is on it, and
closes with **Esc** from inside. It returns `{close}`. Set the button texts once per screen with
`osc.toast.labels({undo: 'Undo', close: 'Close'})`.

`osc.notify` and `osc.toast` speak through `#osc-live`: polite for `ok`, `info` and `warning`,
assertive for `error`. For your own region use `osc.announce(el, text)` on a screen-reader-only
element with `role="status"` and `aria-live="polite"`. Calling it twice with the same text speaks
twice. The same text on another region within a second is said once.

## Bootstrap's JavaScript

The admin does not load Bootstrap's JavaScript. A plugin that still uses `data-bs-toggle`
or `bootstrap.Modal` calls `osc_enqueue_script('bootstrap5')` on its own screens. Bootstrap's
CSS classes are still there.

## Cache-busting

Append a version to your asset URL so an update actually reaches returning
visitors instead of sitting behind their browser cache. Core does this with
`osc_asset_url_versioned()`.
