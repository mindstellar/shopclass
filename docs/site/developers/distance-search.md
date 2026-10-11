---
title: Distance search in themes
description: "How a theme shows distance search: the search form fields, the core field and how to draw your own, the sort, and a listing's distance."
sidebar:
  order: 32
---

Distance search lets visitors find listings within a distance of a city or of where they
are. Site owners switch it on in **Settings → General**. When it is off, none of this
shows and the fields below are ignored.

## The form fields

A search form sends these, as GET fields:

| Field | Value |
|---|---|
| `iRadius` | 5, 10, 25, 50 or 100, in the site's unit. Other numbers snap to the nearest. |
| `sLat`, `sLng` | The visitor's position, rounded to two decimals. |
| `sOrder=distance` | Nearest first. |

With `iRadius` and no `sLat`/`sLng`, the radius is measured from the centre of the one
city, or else the one region, in the search. The radius then replaces that city filter.

## The core field

Core adds `osc_search_distance_field()` to the `search_form` action. It prints a
`<fieldset class="osc-distance" data-osc-distance>` with the radius list and a
**Use my location** button (`.osc-near-me`, `data-osc-near-me`). Core ships no CSS for
it, so style those classes. The button needs `osc-ui-common`, which the field enqueues,
and it must sit inside the search `<form>`.

To draw it somewhere else, take it off the action and call it yourself:

```php
osc_remove_hook('search_form', 'osc_search_distance_field');
// later, in your sidebar
if (osc_geo_search_enabled()) {
    osc_search_distance_field();
}
```

To draw your own markup, use `osc_search_radius_options()` for the choices,
`osc_search_radius()` for the current one and `osc_search_near()` for the point in use.

## Sort and results

`osc_list_orders()` includes **Nearest first** when distance search is on. A theme with
its own sort menu adds `sOrder=distance&iOrderType=asc`. With no distance picked,
nearest first keeps to the largest one.

On a result, `osc_item_distance()` is the distance in whole units of the site, or null
outside a distance search. `osc_distance_label()` turns a number into text such as
"25 km" or "3 miles".

## Listings from plugins

Core sets each listing's search point when a listing is saved through core. A plugin
that writes `t_item_location` itself leaves the listing out of distance search until
the weekly job sets its point.
