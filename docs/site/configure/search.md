---
title: Improving search
description: Tune ShopClass search — MySQL full-text word length, stopwords, rebuilding the index, and when to reach for a dedicated search engine.
sidebar:
  order: 6
---

ShopClass searches listing titles and descriptions through a MySQL **full-text
index**. That is fast and needs no extra services, but it has defaults that
surprise people — most often, short words that simply never match.

## Words shorter than the minimum are ignored

MySQL will not index a word below a minimum length. The defaults are:

| Storage engine | Variable | Default |
|---|---|---|
| InnoDB (normal) | `innodb_ft_min_token_size` | **3** |
| MyISAM (legacy) | `ft_min_word_len` | **4** |

So on a default install, searching for `TV`, `PC` or `BMW` on a MyISAM table
returns nothing at all — not "no results found", but genuinely no match, because
the word was never indexed.

If your categories are full of short model names or two-letter abbreviations,
lower it.

## Changing the limits

These are **server** settings, not application settings. Edit your MySQL or
MariaDB configuration file (`my.cnf`, or a file under `/etc/mysql/conf.d/`):

```ini
[mysqld]
innodb_ft_min_token_size = 2
ft_min_word_len = 2
```

Restart the database for them to take effect.

:::caution[Shared hosting cannot do this]
Full-text tuning requires access to the database server's configuration. If you
are on shared hosting, you cannot change these — skip to
[when to use a real search engine](#when-mysql-is-not-enough).
:::

## Rebuild the index afterwards

Changing an indexing variable does **not** re-index existing rows. Until you
rebuild, the new setting applies only to listings added after the restart —
which looks exactly like the change not working.

```sql
-- InnoDB
OPTIMIZE TABLE oc_t_item_description;

-- MyISAM
REPAIR TABLE oc_t_item_description QUICK;
```

Replace `oc_` with your actual table prefix.

## Stopwords

MySQL also refuses to index very common words, from a built-in stopword list.
On an English-language site this is usually what you want. On a site in another
language, the English list is doing nothing useful and may be excluding real
search terms — supply your own with `innodb_ft_server_stopword_table`, or empty
the list.

## Keeping search fast as the site grows

- **Cache the repeated work.** Search pages recompute category and location
  trees on every request. [Object caching](/docs/configure/cache/) removes most
  of that.
- **Watch slow queries.** Enable the slow query log and look at what search
  actually issues before optimising anything by guesswork.
- **Prune expired listings.** **Admin → Tools → Cleanup** removes expired, spam,
  blocked and unactivated content. A table full of dead rows makes every search
  slower.

## When MySQL is not enough

Full-text search in MySQL has no typo tolerance, no stemming worth relying on,
no relevance tuning and no faceted scoring. Past a few hundred thousand
listings, or on a site where search quality *is* the product, the answer is a
dedicated engine — Elasticsearch, OpenSearch, Meilisearch or Typesense — driven
from a plugin that indexes on the listing hooks.

That is a plugin's job, not core's. See the
[developer documentation](/docs/developers/) for the hooks to index from.
