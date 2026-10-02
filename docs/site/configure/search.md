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
own list with `innodb_ft_server_stopword_table`, or empty the list.

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
