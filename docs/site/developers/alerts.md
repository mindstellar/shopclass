---
title: Saved-search alerts
description: How a saved search is stored and run again, how a plugin keeps its own filter working in alerts, and how a theme shows an alert.
sidebar:
  order: 27
---

A visitor on the search page can save the search as an alert. Core then emails new
listings that match it. This page covers what a plugin or theme needs to know.

## What is stored

An alert stores the search's **values**, never SQL. The `s_search` column of `t_alerts`
holds a small JSON document:

```json
{"v":2,"params":{"bPic":1,"sCategory":[15],"sCity":["Phoenix"],"sPattern":"iphone"}}
```

The keys are the search page's own request parameters: `sCategory`, `sCountry`,
`sRegion`, `sCity`, `sCityArea`, `sUser`, `sLocale`, `sPattern`, `bPic`, `bPremium`,
`sPriceMin`, `sPriceMax` and `meta`. Sort order and paging are not stored.

When an alert runs, core builds the search from these values with the same code the
search page uses. So an alert finds exactly what the same search finds on the page.

## Keep a plugin's filter working in alerts

A plugin that adds its own search filter usually reads a request parameter in
`search_conditions` and adds a condition. Two steps make that filter work in alerts too.

**1. Save your value with the alert.** Add it with the `alert_search_params` filter:

```php
osc_add_filter('alert_search_params', function ($params, $request) {
    if (!empty($request['acmeRadius'])) {
        $params['acmeRadius'] = (int) $request['acmeRadius'];
    }

    return $params;
});
```

A value must be a whole number, a string of at most 255 bytes, or a flat list of those.
Its name must not be one of core's keys. Anything else is dropped.

**2. Read it back in `search_conditions`.** When an alert runs, `Params::getParam()`
returns the alert's saved values, and `Search::newInstance()` is the alert's search:

```php
osc_add_hook('search_conditions', function ($params, $search = null, $context = 'request') {
    $radius = Params::getParamInt('acmeRadius');
    if ($radius > 0) {
        Search::newInstance()->addConditions(acme_radius_condition($radius));
    }
});
```

`search_conditions` now also runs in the alert cron, on the user's alerts page and on the
admin user screen. The third argument is `'request'` on the search page and `'alert'`
otherwise. A callback that sends headers, reads the session or prints must return early
when it is `'alert'`.

## Show an alert in a theme

Inside the `osc_has_alerts()` loop, `osc_get_raw_search()` gives display values for the
current alert:

```php
$raw = osc_get_raw_search((array) json_decode((string) osc_alert_field('s_search'), true));
```

It returns `sPattern`, `aCategories` (category names), `price_min` and `price_max`. For
an alert stored as values it also returns `countries`, `regions`, `cities` and
`city_areas`, and the raw values under `params`. Show the location keys only when
`params` is set: alerts from older versions hold them as SQL.

`osc_get_raw_search()` has existed since Osclass 3, so the same code works on older cores.

## Paused alerts

Upgrading to 6.4.0 converts every older alert to the stored form above. An alert holding
anything core did not write itself, such as a plugin's own SQL, cannot be converted
safely. It is paused instead: it stays in the table, switched off, and its `s_search` is
`{"v":2,"held":"<reason>"}`.

`osc_get_raw_search()` returns `array('held' => '<reason>')` for a paused alert. Show a
short message, such as "Paused: delete this alert and save the search again". The admin
lists paused alerts under **Users → Alerts**.
