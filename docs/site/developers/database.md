---
title: Database model
description: Explore the ShopClass schema — table prefix, the core tables, and generating an entity-relationship diagram from struct.sql.
sidebar:
  order: 10
---

ShopClass stores everything in MySQL/MariaDB. Table names carry the prefix
chosen at install — `oc_` by default, and configurable per install, so **never
hard-code it**:

```php
$prefix = DB_TABLE_PREFIX;             // in raw SQL
// or use the DAO layer, which applies it for you
```

## The tables that matter most

| Table | Holds |
|---|---|
| `t_item` | Listings — price, dates, contact, coordinates, flags. |
| `t_item_description` | Title and description, one row per language. Carries the full-text index. |
| `t_item_resource` | Uploaded photos and files attached to a listing. |
| `t_category` / `t_category_description` | The category tree and its translations. |
| `t_country`, `t_region`, `t_city` | [Location data](/docs/configure/locations/). |
| `t_user` | Accounts, with `t_admin` for admin users. |
| `t_preference` | Every setting, grouped by section — the row that decides how the site behaves. |
| `t_pages` | Static pages. |
| `t_plugin_category` | Per-plugin, per-category configuration. |

Column names follow a typed prefix — `i_` integer, `s_` string, `d_` decimal,
`b_` boolean, `dt_` datetime, `pk_` primary key, `fk_` foreign key — so
`fk_i_category_id` is a foreign key to a category id. See
[coding style](/docs/developers/coding-style/).

## Generating a diagram

The authoritative schema is
`oc-includes/osclass/installer/struct.sql`. To get an interactive
entity-relationship diagram from it:

1. Install [MySQL Workbench](https://www.mysql.com/products/workbench/) — free
   and cross-platform.
2. **Database → Reverse Engineer**, or **File → Import → Reverse Engineer MySQL
   Create Script**.
3. Select `oc-includes/osclass/installer/struct.sql`.
4. Check **Place imported objects on a diagram**.
5. Execute, then rearrange the tables.

Relations highlight as you hover, which is the only practical way to follow them
— the full schema is too dense to read as a static picture.

Generating it yourself rather than reading a published image also means the
diagram matches **your** version, not whatever release the image was made from.

## Querying from a plugin

Use the DAO layer rather than raw SQL where one exists — it applies the prefix,
escapes parameters and keeps working across schema migrations:

```php
$items = Item::newInstance()->findByCategoryID($categoryId);
$user  = User::newInstance()->findByPrimaryKey($userId);
```

When you do need raw SQL, go through the connection so your query is prepared
and escaped, and never interpolate request input into a string.

## Adding your own tables

Create them on plugin install, drop them on uninstall, and prefix them with both
`DB_TABLE_PREFIX` and your plugin slug:

```php
$table = DB_TABLE_PREFIX . 't_myplugin_data';
```

Do not add columns to core tables. A migration will not know about them, and the
next `db:upgrade` reconciles the schema against what core expects.
