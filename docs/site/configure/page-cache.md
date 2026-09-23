---
title: Page caching
description: Serve public pages from nginx without running PHP — the switch in the Docker image, keeping pages for an hour, and clearing them the moment a listing changes.
sidebar:
  order: 5
---

A **page cache** keeps a copy of a finished public page. The next visitor gets
that copy, and PHP does not run at all.

On a listing site, most visitors are not logged in and read the same few pages.
So a page cache saves more work than anything else you can turn on. The
[object cache](/docs/configure/cache/) makes PHP's work faster. A page cache
skips that work completely.

:::note[ShopClass decides what may be cached, not your server]
ShopClass marks each public page as safe to cache with this header:
`Cache-Control: public, s-maxage=30, max-age=0, must-revalidate`. A personal
page, such as a dashboard or a page that starts a session (remembers the
visitor), gets
`private, no-store` and is never stored. So you do not list pages for your server
to skip. It only has to follow what ShopClass says. The full rules are in
[the caching contract](/docs/developers/caching/).
:::

## In the Docker image

Set one environment variable:

```yaml
environment:
  OSC_MICROCACHE: "1"
```

When the container starts, it writes the nginx settings for you. So they survive
a redeploy. Settings you edit by hand inside a running container do not.

Check that it works:

```bash
curl -sI https://example.com/ | grep -i x-cache
```

- `X-Cache: MISS` on the first request, then `HIT` on the second: it works.
- `BYPASS`: the request carried a login cookie. That is correct. A logged-in
  visitor never gets someone else's page.

## On your own nginx

Copy the settings in `.docker/nginx/microcache.conf` from the repository. They
have three parts:

- a `fastcgi_cache_path` line, in `http{}`
- a cookie map, in `http{}`
- a few `fastcgi_cache_*` lines, inside your existing `location ~ \.php$`

Copy them as they are. Do not add `fastcgi_ignore_headers` or a
`fastcgi_cache_valid` override. Both make nginx decide what to cache on its own,
and nginx cannot tell a public listing from a private dashboard.

## Keeping pages for longer

A page stays in the cache for 30 seconds. That is short on purpose. With no other
way to remove a page, a longer time means an edited listing shows its old price
until the time runs out. You can keep pages for an hour only if something removes
a page the moment it changes.

The **nginx Cache** plugin does that. Install it from the **Browse** tab under **Plugins → Manage plugins**, or:

```bash
php oc-cli.php market:install nginx-cache
```

It keeps pages for an hour. When something changes, it **purges** (removes) the
affected pages from the cache in the same request: the listing, the home page,
its category and the seller's profile. The edit shows on the next page load, not
at the next cron run.

It needs nginx with the `ngx_cache_purge` module.

- **The Docker image already has it.** It turns on the purge address whenever
  `OSC_MICROCACHE` is on. There is nothing to set up.
- Elsewhere, install it as a package: `nginx-mod-http-cache-purge` on Alpine,
  `libnginx-mod-http-cache-purge` on Debian. The plugin's **Setup** page prints
  the exact nginx settings for your install and your nginx version.

List every hostname your site answers on, one per line: `www.example.com` and
`example.com`, other names, and any test domain. nginx keeps a separate copy of
each page for each name. A name you leave out keeps its old copy for the whole
hour.

Then press **Test purge**. It stores and then purges a page on each hostname in
turn. Your site's own hostname must be in the list. Until the test passes, the
plugin keeps the normal 30 seconds and changes nothing. A long cache with a purge
that quietly fails is worse than no plugin at all, so the plugin does not take it
on trust.

### What stays at 30 seconds

The plugin can only purge a page it can name by its address. Everything else
stays at 30 seconds, whatever the settings say:

- **Search results with options.** Every keyword, filter, sort order and page
  number makes its own copy. Nobody can list them all, and a new listing must
  show up in them.
- **Any address with a query string** (a `?` and options after it). This
  includes `?comments-page=2` on a listing and `?utm_source=…` on a shared link.
- **Every page, if friendly URLs are off.** Then every page's address is a query
  address. Turn permalinks on first, under **Settings → Permalinks**. Without
  them, the plugin has nothing it can keep.

### Why an hour and not a day

Each form on a page carries a security token. A cached page keeps the token from
when it was stored, and ShopClass stops accepting a token two hours after it was
made. If pages stayed much longer than an hour, visitors would get tokens close
to their end. Then every form on the page (contact seller, report listing,
comment) would answer *your session has expired*. Purging would still work
perfectly, so nothing would look wrong. That is why the plugin sets a maximum
instead of just showing a warning.

## Troubleshooting

| What you see | What it means |
|---|---|
| No `X-Cache` header at all | The cache is not set up. In the Docker image, `OSC_MICROCACHE` is not set. |
| `X-Cache: BYPASS` on every request | The request carries a login or language cookie. Try a private browser window. |
| Always `MISS`, never `HIT` | The page cannot be cached. Look for a `Set-Cookie` on the page, or a plugin that sends its own `Cache-Control`. |
| Test purge says a host is not in the list | Visitors reach the site under a name you did not list. Add it, with the port if there is one. |
| Test purge says the key does not match | The purge address uses a different scheme (`http` or `https`) from the one nginx serves on. The scheme is part of each page's cache key, so a purge on the wrong one matches nothing. |
| Test purge returns 404 | Your nginx settings are missing the purge address. The **Setup** page prints it. |
| An edit does not show | Check **Purges waiting** on the plugin's settings page. Each entry is a page the plugin could not purge. It tries again on the next cron run. |
