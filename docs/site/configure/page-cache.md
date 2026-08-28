---
title: Page caching
description: Cache whole public pages in nginx — the micro-cache switch in the Docker image, holding pages for an hour, and purging them the moment a listing changes.
sidebar:
  order: 5
---

A page cache stores the finished HTML of a public page and hands it to the next
visitor without PHP running at all. On a listing site most traffic is anonymous
people reading the same handful of pages, so this is the single largest saving
available — and unlike the [object cache](/docs/configure/cache/), which stores
fragments of work inside PHP, it removes the work entirely.

:::note[ShopClass decides what is cacheable, not your proxy]
Core marks public read pages cacheable and emits
`Cache-Control: public, s-maxage=30, max-age=0, must-revalidate`. Anything
personalised — a dashboard, a page that starts a session — emits
`private, no-store` and is never stored. So the proxy needs no URL allow-lists:
it only has to honour what the application already says. The full contract is in
[the caching contract](/docs/developers/caching/).
:::

## In the Docker image

One environment variable:

```yaml
environment:
  OSC_MICROCACHE: "1"
```

The entrypoint writes the nginx configuration for it at start-up, so it survives
a redeploy — a config edited inside a running container does not. Check it took:

```bash
curl -sI https://example.com/ | grep -i x-cache
```

`X-Cache: MISS` on the first request and `HIT` on the second means it is working.
`BYPASS` means the request carried a login cookie, which is correct — logged-in
visitors are never served someone else's page.

## On your own nginx

The reference configuration is `.docker/nginx/microcache.conf` in the repository.
It is three pieces: a `fastcgi_cache_path` and a cookie map in `http{}`, and a
handful of `fastcgi_cache_*` directives inside your existing `location ~ \.php$`.
Copy it as it is — in particular, do not add `fastcgi_ignore_headers` or a
`fastcgi_cache_valid` override, because both take the decision away from the
application and hand it to a rule that cannot tell a public listing from a
private dashboard.

## Holding pages for longer

Thirty seconds is short, and deliberately so: with time as the only way an entry
ever leaves the cache, a longer window means an edited listing showing its old
price until it expires. Holding a page for an hour is only safe if something can
say *this one is wrong now*.

That is the **nginx Cache** plugin. Install it from **Plugins → Market**, or:

```bash
php oc-cli.php market:install nginx-cache
```

It raises the window to an hour and purges the affected pages — the listing, the
home page, its category, the seller's profile — in the same request that changed
them, so an edit is visible on the next page load rather than at the next cron
tick.

It needs nginx built with `ngx_cache_purge`. **The Docker image already carries
it** and writes the purge endpoint whenever `OSC_MICROCACHE` is on, so there is
nothing to configure there. Elsewhere it is an Alpine or Debian package
(`nginx-mod-http-cache-purge`, `libnginx-mod-http-cache-purge`); the plugin's
**Setup** page prints the exact configuration for your install, including the
version of nginx you are actually running.

Then press **Test purge**. Until that has passed, the plugin serves core's own
thirty seconds and changes nothing — a long window over a purge that silently
does not work is worse than no plugin at all, so it is not something the plugin
will take on trust.

### What stays on the short window

Only a URL a purge can name is held longer. Everything else keeps the
thirty-second window whatever the settings say:

- **search results with parameters** — every keyword, filter, sort and page
  number is its own cache entry, the set cannot be enumerated, and a newly
  posted listing has to appear in them;
- **any URL carrying a query string**, including `?comments-page=2` on a
  listing and `?utm_source=…` on a shared link;
- **every page, if friendly URLs are off** — the canonical URL of each is then a
  query URL itself. Turn permalinks on under **Settings → Permalinks** first, or
  this plugin has nothing it can hold.

### Why an hour and not a day

A cached page carries the security token minted when it was stored, and core
stops accepting a token two hours after it was issued. A window much past an hour
starts handing out tokens close to expiry, and every form on the page — contact
seller, report listing, comment — answers *your session has expired*. Purging
goes on working perfectly while that happens, which is why the plugin caps the
setting rather than warning about it.

## Troubleshooting

| What you see | What it means |
|---|---|
| No `X-Cache` header at all | The cache is not configured. In the image, `OSC_MICROCACHE` is not set. |
| `X-Cache: BYPASS` on every request | The request carries a login or locale cookie. Try it in a private window. |
| Always `MISS`, never `HIT` | The response is not cacheable — check for a `Set-Cookie` on the page, or a plugin emitting its own `Cache-Control`. |
| Test purge says the key does not match | The Host setting is not the host visitors send, port included, or the endpoint's scheme is not the one nginx serves on. Both are part of the cache key. |
| Test purge returns 404 | The purge location is missing from the nginx config. The Setup page prints it. |
| An edit is not visible | Check **Purges waiting** on the plugin's settings page; anything there is a page the origin could not be told about, retried on the next cron run. |
