---
title: Improving search
description: Make ShopClass search find short words, rebuild the search index, handle stopwords, and know when to add a dedicated search engine.
sidebar:
  order: 6
---

ShopClass searches listing titles and descriptions with MySQL's **full-text
index**: a list MySQL keeps of every word in those fields. It is fast and needs
nothing extra. But its default settings surprise people. Most often, short words
never match at all.

## Short words are ignored

MySQL does not add a word to the index if it is shorter than a minimum length.
The defaults are:

| Storage engine | Setting | Default |
|---|---|---|
| InnoDB (normal) | `innodb_ft_min_token_size` | **3** |
| MyISAM (legacy) | `ft_min_word_len` | **4** |

So on a MyISAM table with default settings, a search for `TV`, `PC` or `BMW` finds
nothing. The word was never put in the index, so it can never match.

ShopClass reads the server's InnoDB minimum. When every word in a search is
shorter than it, or is a stopword, ShopClass matches the words anywhere in the
title or description instead, which is slower but finds them.

ShopClass remembers the server's minimum and stopword list for a day when a
[persistent cache](/docs/configure/cache/) is on. After you change them on the
server, press **Clear cache** in **Tools → System info → Cache**, or run
`php oc-cli.php cache:flush`. Without a persistent cache it reads them fresh.

In a search with both kinds of word, stopwords are dropped and short words are
matched anywhere in the title or description. `about town` needs only `town`.
`sony tv` needs `sony` and also `tv` somewhere in the text.

If your categories are full of short model names or two-letter abbreviations,
lower the minimum.

## Changing the minimum

These are **database server** settings, not ShopClass settings. Edit your MySQL or
MariaDB settings file (`my.cnf`, or a file under `/etc/mysql/conf.d/`):

```ini
[mysqld]
innodb_ft_min_token_size = 2
ft_min_word_len = 2
```

Restart the database so the change takes effect.

:::caution[Shared hosting cannot do this]
You need access to the database server's settings. On shared hosting you do not
have it. Skip to [when MySQL is not enough](#when-mysql-is-not-enough).
:::

## Rebuild the index afterwards

A new minimum does **not** update the listings you already have. Until you
rebuild the index, it applies only to listings added after the restart. That
looks exactly as if the change did not work.

```sql
-- InnoDB
OPTIMIZE TABLE oc_t_item_description;

-- MyISAM
REPAIR TABLE oc_t_item_description QUICK;
```

Replace `oc_` with your table prefix.

## Stopwords

MySQL also skips very common words, from a built-in list of **stopwords**. That
list is English. On an English site it usually helps. On a site in another
language, it does nothing useful and can hide real search words. Give MySQL your
own list with `innodb_ft_server_stopword_table`, or empty the list. ShopClass
reads a custom stopword table, so its words are treated like the built-in ones.

## Distance search

Visitors can search for listings within 5, 10, 25, 50 or 100 km of a city or of where
they are, and sort them nearest first. It is off on a new site. Switch it on in
**Settings → General**, under **Distance search**, and pick kilometres or miles there.

Each listing is measured from its own map position when it has one. Otherwise it is
measured from the centre of its city, or of its region when it has no city. So distance
search works without a map service, as long as your location data has city centres
(see [Locations](/docs/configure/locations/)). A listing with no city and no region is
left out of a distance search.

Location data installed from an older catalogue may have no city centres. Update it under
**Listings → Locations**, or with `php oc-cli.php location:update --all`. The update
queues a background job that gives listings the new centres.

The search form gets a distance list and a **Use my location** button. The browser asks
the visitor first, and the position is rounded to about 1 km before it is sent.
"Nearest first" with no distance picked keeps to the largest one, 100 km or miles.

Themes that draw their own field remove the `osc_search_distance_field` action from
`search_form`; see [Distance search in themes](/docs/developers/distance-search/). The API takes the same search with `lat`, `lng`, `radius` and
`sort=distance`.

## Keeping search fast as the site grows

- **Cache repeated work.** Search pages rebuild the category and location lists
  on every request. [Object caching](/docs/configure/cache/) removes most of
  that work.
- **Look at slow queries.** Turn on MySQL's slow query log. See what search
  really sends before you change anything.
- **Remove old listings.** **Admin → Tools → Cleanup** removes expired, spam,
  blocked and unactivated content. Every dead row makes every search slower.

## When MySQL is not enough

MySQL search does not forgive typos. It does not reliably match word forms such
as "bike" and "bikes". You cannot tune which results come first, and it cannot
count results per filter.

Past a few hundred thousand listings, or when search quality *is* your product,
use a search engine built for the job: Elasticsearch, OpenSearch, Meilisearch or
Typesense. A plugin adds each listing to it when the listing changes.

That is a plugin's job, not core's. See the
[developer documentation](/docs/developers/) for the hooks a plugin uses.
