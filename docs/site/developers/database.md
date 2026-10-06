---
title: Database model
description: "Explore the ShopClass schema: table prefix, the core tables, and generating an entity-relationship diagram from struct.sql."
sidebar:
  order: 12
---

ShopClass stores everything in MySQL/MariaDB. Table names carry the prefix
chosen at install (`oc_` by default, and configurable per install), so **never
hard-code it**:

```php
$prefix = DB_TABLE_PREFIX;             // in raw SQL
// or use the DAO layer, which applies it for you
```

## The tables that matter most

| Table | Holds |
|---|---|
| `t_item` | Listings: price, dates, contact, coordinates, flags. |
| `t_item_description` | Title and description, one row per language. Carries the full-text index. |
| `t_item_resource` | Uploaded photos and files attached to a listing. |
| `t_category` / `t_category_description` | The category tree and its translations. |
| `t_country`, `t_region`, `t_city` | [Location data](/docs/configure/locations/). |
| `t_user` | Accounts, with `t_admin` for admin users. |
| `t_preference` | Every setting, grouped by section: the row that decides how the site behaves. |
| `t_pages` | Static pages. |
| `t_plugin_category` | Per-plugin, per-category configuration. |

Column names follow a typed prefix: `i_` integer, `s_` string, `d_` decimal,
`b_` boolean, `dt_` datetime, `pk_` primary key, `fk_` foreign key, so
`fk_i_category_id` is a foreign key to a category id. See
[coding style](/docs/developers/coding-style/).

## Generating a diagram

The authoritative schema is
`oc-includes/osclass/installer/struct.sql`. To get an interactive
entity-relationship diagram from it:

1. Install [MySQL Workbench](https://www.mysql.com/products/workbench/), free
   and cross-platform.
2. **Database → Reverse Engineer**, or **File → Import → Reverse Engineer MySQL
   Create Script**.
3. Select `oc-includes/osclass/installer/struct.sql`.
4. Check **Place imported objects on a diagram**.
5. Execute, then rearrange the tables.

Relations highlight as you hover, which is the only practical way to follow them:
the full schema is too dense to read as a static picture.

Generating it yourself rather than reading a published image also means the
diagram matches **your** version, not whatever release the image was made from.

## Querying from a plugin

Use the DAO layer rather than raw SQL where one exists, since it applies the prefix,
escapes parameters and keeps working across schema migrations:

```php
$items = Item::getInstance()->findByCategoryID($categoryId);
$user  = User::getInstance()->findByPrimaryKey($userId);
```

`getInstance()` needs Shopclass 7.0. A plugin that also supports 6.x calls `newInstance()`, which still works on 7.0.

For your own queries, use the query builder. It binds every value and checks
every table and column name:

```php
$p = DB_TABLE_PREFIX;

$rows = osc_db_table($p . 't_item AS i')
    ->select('i.pk_i_id')
    ->selectRaw('COUNT(r.pk_i_id) AS n_pic')
    ->leftJoin($p . 't_item_resource AS r', 'r.fk_i_item_id', '=', 'i.pk_i_id')
    ->where('i.b_active', 1)
    ->whereNotNull('i.dt_pub_date')
    ->groupBy('i.pk_i_id')
    ->get();
```

- A table may carry an alias, `'t_item AS i'`, for reads and joins. Writes take
  the plain table name.
- `selectRaw()` and `whereRaw()` take SQL you write yourself. Put values in their
  second argument as `?` placeholders, never in the string.
- `whereNull()`, `whereNotNull()`, `orWhereNull()` and `orWhereNotNull()` test for
  NULL. An empty `whereIn()` matches no rows.
- `count()` honours joins, wheres and a `groupBy()`.

When you need raw SQL, use `osc_db_select()` or `osc_db_execute()` with `?`
placeholders, and never put request input into the string.

## Adding your own tables

Create them on plugin install, drop them on uninstall, and prefix them with both
`DB_TABLE_PREFIX` and your plugin slug:

```php
$table = DB_TABLE_PREFIX . 't_myplugin_data';
```

Do not add columns to core tables. A migration will not know about them, and
`db:repair` never removes a column: it stays flagged as extra until someone
removes it by hand.
