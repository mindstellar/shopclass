---
title: Debug SQL queries
description: Inspect the queries ShopClass runs with OSC_DEBUG_DB, log them to a file, and EXPLAIN them to find slow ones.
sidebar:
  order: 12
---

When a page is slow, or a plugin's data is not appearing, the useful question is
what SQL actually ran. Three constants in `config.php` answer it.

:::caution[Development only]
Each of these adds work to every request and writes files a visitor could read.
Turn them off on a production site.
:::

## Print queries on the page

```php
define('OSC_DEBUG_DB', true);
```

Every query is collected and printed at the end of the page, along with how long
it took and any error code and message.

That gives you the two things you usually need at once: the query count — a page
issuing four hundred queries has a loop doing lookups it should have batched —
and which individual query is slow.

## Log queries to a file

```php
define('OSC_DEBUG_DB', true);
define('OSC_DEBUG_DB_LOG', true);
```

Queries go to `oc-content/queries.log`.

Use this rather than on-page output whenever the request has no page to print
to — **AJAX calls, cron runs and CLI commands**. Their queries are invisible any
other way.

```bash
tail -f oc-content/queries.log
```

## EXPLAIN every SELECT

```php
define('OSC_DEBUG_DB_EXPLAIN', true);
```

Runs an `EXPLAIN` for each `SELECT` and writes the result to
`oc-content/explain_queries.log`. This is how you find the query doing a full
table scan.

Read the `type` and `rows` columns first: `type: ALL` with a large `rows` count
is a missing index, and it is the usual reason a site that was fast at ten
thousand listings is slow at two hundred thousand.

## If the log files never appear

The web server cannot create them. Create them and make them writable:

```bash
touch oc-content/queries.log oc-content/explain_queries.log
chmod 664 oc-content/queries.log oc-content/explain_queries.log
```

Delete them when you are done — `oc-content/` is served over HTTP, and a query
log describes your schema to anyone who finds it.

## Reducing what you find

- **[Object caching](/docs/configure/cache/)** removes the repeated
  preference, category and location lookups that dominate most query logs.
- **Batch in plugins.** A `foreach` that calls a finder per row is the most
  common cause of a four-hundred-query page.
- **Prune dead rows.** **Admin → Tools → Cleanup** clears expired, spam,
  blocked and unactivated content.

## Related

- [Debug PHP errors](/docs/developers/debug-php-errors/)
- [Improving search](/docs/configure/search/) — full-text indexing specifically
