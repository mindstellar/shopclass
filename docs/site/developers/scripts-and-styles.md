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

The front end loads **nothing** by default, and the admin panel registers
Bootstrap 5, not jQuery:

| Registered in the admin | Depends on |
|---|---|
| `bootstrap5` | `popper` |
| `popper` | — |
| `sortablejs` | — |
| `admin-osc`, `admin-ui-osc`, `admin-categories`, `admin-location` | core admin behaviour |

If your code needs jQuery, ship it and register it yourself:

```php
osc_register_script('jquery', osc_base_url() . 'oc-content/plugins/myplugin/js/jquery.min.js');
osc_register_script('myplugin-widget', $url, 'jquery');
osc_enqueue_script('myplugin-widget');   // pulls jquery in first
```

Better: most of what plugins used jQuery for — selectors, `fetch`, class
toggling, event delegation — is a few lines of plain JavaScript in a browser
from the last decade. Dropping the dependency makes your plugin lighter and
removes a class of version conflicts entirely.

## Naming

The `$id` is a global namespace shared with every other plugin on the install.

- Prefix ids with your plugin folder: `myplugin-widget`, not `widget`.
- For a **third-party library**, use the library's ordinary name — `fancybox`,
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
| `data-ac-scope` | Selector whose value narrows the search — the region, for a city. |
| `data-ac-scope-param` | The parameter name that value is sent as. |
| `data-ac-clears` | Comma-separated selectors emptied when this field changes. |

The endpoints match on the first letters of a name and return `{id, value}` rows.

If your theme already ships its own binder, it wins the race and core's becomes a
no-op for that field — so adopting this is safe and can be done one field at a time.

## Cache-busting

Append a version to your asset URL so an update actually reaches returning
visitors instead of sitting behind their browser cache. Core does this with
`osc_asset_url_versioned()`.
