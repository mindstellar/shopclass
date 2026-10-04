---
title: Caching contract
description: "How ShopClass drives a reverse proxy or CDN: the cookie allowlist, the Cache-Control it emits, and why the proxy config stays small."
sidebar:
  order: 11
---

ShopClass is designed to sit behind a reverse proxy or CDN. This page is the
contract between the two: **the application decides whether a response is public
or private, and says so in standard HTTP.** A proxy should respect what the app
says, never infer it from side channels: cookie presence, hashed names, URL
lists.

Every detail here is domain-independent and stable across installs. The full
version, including the reference nginx config, is
[`docs/CACHING.md`](https://github.com/mindstellar/shopclass/blob/master/docs/CACHING.md).

:::note[Not the object cache]
This is about caching whole HTTP responses in front of PHP. Caching database
work *inside* PHP is a separate, complementary layer: see
[object caching](/docs/configure/cache/).
:::

## The two questions a cache must answer

1. **Is this response private?** Decided by the cookie allowlist below.
2. **What may cache it, and for how long?** Decided by the `Cache-Control` the
   app emits.

A proxy needs exactly one request-side signal: the cookie set, for the
serve-from-cache decision it makes before PHP runs. Everything else follows from
the response headers.

## The cookie allowlist

These, and only these, mean "this response is personalised". A proxy must bypass
the cache (neither serve nor store) when a request carries any of them:

| Cookie | Set when | Why it matters |
|---|---|---|
| `osclass` | a PHP session starts (login, a form write) | session-bound output |
| `oc_cache_bypass` | front-end or admin login / remember-me | logged-in user or admin |
| `oc_userLocale` | a visitor switches language | changes the rendered language |

Front-end and admin identity (`oc_userId`, `oc_userSecret`, `oc_adminId`) are keys
*inside* a cookie named `md5(WEB_PATH)`, not cookie names, and not something a
proxy config can hardcode. `oc_cache_bypass` is the fixed-name flag core sets in
lockstep with login so the edge has one stable name to match. A rule written
against the key names never fires.

**Every other cookie is irrelevant and must not affect caching**, including
analytics cookies (`_ga`, `_gid`, `_fbp`, …) and the theme's `cookies_consent`.
The server never reads them, so they cannot change the output.

:::danger[Never key the cache on "any cookie present"]
That is the single most common misconfiguration, and it silently destroys the
cache: one Google Analytics cookie, set by JavaScript on a visitor's first page
view, makes every subsequent request a miss. The decision must be a fixed
allowlist of **names**.
:::

The names are guaranteed stable (no `md5(WEB_PATH)` or domain hash in them),
and core will not add or rename a personalisation cookie without updating the
helper, this list and the reference config together. Plugins can extend the set
through the `cache_relevant_cookies` filter.

## What core emits

| Response | `Cache-Control` |
|---|---|
| Anonymous, cacheable GET | `public, s-maxage=30, max-age=0, must-revalidate` |
| Personalised, POST, or any allowlisted cookie present | `private, no-store` |

`s-maxage` lets a **shared** cache hold the page for about 30 seconds;
`max-age=0, must-revalidate` makes **browsers** revalidate every time, so a
visitor never sees a stale page from their own disk cache.

Both are overridable from a plugin:

```php
osc_apply_filter('public_cache_max_age', 30);      // just the duration
osc_apply_filter('response_cache_control', $value); // the whole header
```

Core calls `session_cache_limiter('')` on the front end so PHP cannot inject its
own conflicting `no-cache` headers: core owns `Cache-Control` end to end. The
admin panel keeps PHP's default limiter, and is never cached.

Every HTML response, admin included, also carries how long PHP took to build it:
`Server-Timing: app;dur=84.2;desc="Page build"` (milliseconds). Browser developer
tools show it under Network → Timing. It is a header, so the page and its `ETag`
do not change; a page served from a shared cache repeats the time of its first
build. A plugin can drop it with
`osc_remove_filter('response_body', 'osc_response_server_timing')`.

## Purging everything

Some changes alter every public page: the theme, site settings (permalinks
included), maintenance mode, a plugin, languages, currencies, widgets,
categories, static pages, an upgrade or a restore. Core then fires one action at
the end of the request, after the response has gone:

```php
osc_add_hook('page_cache_purge', function (array $reasons) {
    // e.g. array('theme', 'plugin'). Clear your proxy or CDN here.
});
```

It fires once at the end of the request, however many changes asked for it,
also on the command line. A listener that throws is logged and does not break
the page. To ask for one yourself, call `osc_purge_page_cache('my_reason')`. The
filter `page_cache_purge_enabled` (default `true`) turns it off.

:::caution[Only for changes an admin makes]
Each call empties the whole cache. Never call it on something a visitor does,
such as a new comment, a page view or a search: on a busy site the cache would
then never hold anything.
:::

The Docker image clears its own micro-cache without a plugin: when
`OSC_PAGE_CACHE_PURGE_URL` is set, core sends one `PURGE` request there.

## Verifying it

```bash
# anonymous: expect a cacheable response, and HIT on the second request
curl -sI https://example.com/ | grep -i 'cache-control\|cf-cache-status\|x-cache'

# carrying a session cookie: expect private, no-store and a bypass
curl -sI https://example.com/ -H 'Cookie: oc_cache_bypass=1' | grep -i 'cache-control\|cf-cache-status'
```

If a logged-in request returns a cached response, stop and fix the cookie rule
before doing anything else: that configuration serves one user's page to
another.

## Anti-patterns

- **"Cache Everything" without mirroring the cookie bypass.** On Cloudflare in
  particular, this leaks logged-in and admin pages to anonymous visitors.
- **URL allowlists.** They drift the moment a plugin adds a route.
- **Caching on the presence of any cookie.** See above.
- **Long `s-maxage` on a classifieds site.** New listings are the product;
  thirty seconds of shared caching absorbs a traffic spike without making the
  site look stale.
