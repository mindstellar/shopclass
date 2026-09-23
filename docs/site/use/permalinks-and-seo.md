---
title: Permalinks & SEO
description: Configure friendly URLs, the XML sitemap, robots.txt and saved-search feeds so search engines can index a ShopClass site properly.
sidebar:
  order: 10
---

A classifieds site lives or dies on search traffic: every listing is a page
somebody might search for. Three admin screens decide whether search engines can
use them.

## Friendly URLs

**Settings → Permalinks.**

With friendly URLs off, a listing is `index.php?page=item&id=1234`. With them on
it carries the title. Turn them on **before** the site is indexed — changing URL
structure afterwards means redirects and lost rankings.

Every route has its own pattern: listings, pages, categories, search, contact,
feeds, listing actions (edit, delete, and the rest) and the account flows. The
screen lists the keywords each pattern accepts, and every field is required
except the search prefix, which may be left blank.

Leave the defaults unless you have a specific reason. The one worth thinking
about is the **listing** pattern: including the category makes the URL
self-describing, and means the URL changes if a listing is re-categorised.

:::caution[Friendly URLs need web-server rewriting]
On Apache, `mod_rewrite` must be enabled and `AllowOverride All` must apply so
the shipped `.htaccess` is read. On nginx you need a `try_files` rule. If every
link shows a 404 (page not found) error after turning them on, this is why —
see [install troubleshooting](/docs/install/#troubleshooting).
:::

Renaming a category changes its slug and therefore its browse URL. ShopClass
keeps a slug history so old category URLs keep resolving.

## The XML sitemap

**Settings → Sitemap** generates the sitemap search engines read.

Choose what goes in:

- Categories
- Categories with regions, and categories with cities
- Countries, regions and cities
- Pages

Extra URLs the sitemap would not otherwise find — pages served by a plugin, for
example — can be added by hand, each with its own **frequency** (Hourly, Daily,
Weekly, Monthly or Yearly) and **last modified** date.

**Include what has content, exclude what does not.** Categories-with-cities
multiplies into a very large sitemap, and filling it with combinations that
return no listings teaches search engines your site is mostly empty pages. Turn
those on once you have the volume to justify them.

The sitemap is cached. **Regenerate / clear cache** rebuilds it after a big
change, and cron can pre-generate it:

```bash
php oc-cli.php sitemap:warm
```

## robots.txt

The same screen edits and saves **robots.txt**.

The default is fine for most sites. Two things worth adding: your sitemap URL,
and a `Disallow` for search-result URLs. Search engines only crawl so many
pages on your site per visit (your "crawl budget"), and an infinite space of
filter combinations wastes it on pages you do not want ranking anyway.

## Saved-search feeds

Every search has an RSS feed (a machine-readable list of results that updates
automatically), and users can subscribe to one as an
[alert](/docs/use/listings-and-moderation/#alerts) delivered by e-mail. Alerts
are sent by **cron** (a timer on your server — see
[Set up cron](/docs/configure/cron/)) on the schedule the user picked; without
cron they never arrive.

**Settings → Latest searches** controls how long queries are kept — an hour, a
day, a week, forever, the last 1000, or a number you set. That log is worth
reading: it tells you the words your visitors actually use, which is what your
category names should match.

## Practical SEO for a classifieds site

- **Listing titles are your page titles.** Encourage sellers to write real ones —
  the publish-form placeholder does more for your rankings than any setting here.
- **Expired listings are a decision.** Deleting them 404s pages that may still
  rank; keeping them all leaves visitors finding unavailable items. Most sites
  keep them visible and clearly marked as expired.
- **Thin category pages hurt.** A category with two listings competing against a
  site with two hundred will not win. Fewer, fuller categories rank better.
- **Serve one canonical hostname.** Pick `example.com` or `www.example.com`, set
  it consistently, and redirect the other. Both answering the same content
  splits your ranking signal.
- **Make the site fast.** Turn on [object caching](/docs/configure/cache/) and
  put a proxy or CDN in front — see the
  [caching contract](/docs/developers/caching/).
