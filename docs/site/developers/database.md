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
| `t_item_variant` | What makes a listing buyable: `s_sku`, `i_price` (same units as `t_item.i_price`; NULL uses the listing price), `i_stock` (NULL does not track), `i_weight` (grams), `s_shipping_class`, `s_options` (NULL for the default row). A listing with a row is for sale. Rows go with their listing. Write it with `mindstellar\listing\VariantStore`; change stock only with `VariantStore::adjustStock()`, which refuses to go below zero. |
| `t_resource` | Uploaded files: listing photos (owner type `item`), avatars, page and settings images. Read listing photos with the `osc_*resource*` helpers. |
| `t_category` / `t_category_description` | The category tree and its translations. |
| `t_country`, `t_region`, `t_city` | [Location data](/docs/configure/locations/). |
| `t_user` | Accounts, with `t_admin` for admin users. |
| `t_preference` | Every setting, grouped by section: the row that decides how the site behaves. After writing it with raw SQL, call `osc_reset_preferences()`. |
| `t_pages` | Static pages. |
| `t_key_value` | Small values by group and key. See [Key-value store](/docs/developers/kv-store/). |

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

The old query builder a model gives out as `$model->dao` (`DBCommandClass`) is deprecated in 7.0
and goes away in 8.0. In debug mode the first read of `->dao` on each model logs a notice. Move those queries to
the `osc_db_*` helpers below.

These DAO classes stay as they are for plugins. Core's own new code reads and writes
through each module's `…Store` and `…Query` classes instead; see
[where new code goes](/docs/developers/architecture/#where-new-code-goes).

For your own queries, use the query builder. It binds every value and checks
every table and column name:

```php
$p = DB_TABLE_PREFIX;

$rows = osc_db_table($p . 't_item AS i')
    ->select('i.pk_i_id')
    ->selectRaw('COUNT(c.pk_i_id) AS n_comments')
    ->leftJoin($p . 't_item_comment AS c', 'c.fk_i_item_id', '=', 'i.pk_i_id')
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

Since 7.0, values go to the database escaped inside the query, in one trip, and every
column comes back as a string. Pass `LIMIT ?` and `OFFSET ?` an int, not a numeric string.
To send real prepared statements instead, add `define('OSC_DB_REAL_PREPARES', true);`
to `config.php`.

Each `osc_db_*` helper calls the same method on `mindstellar\database\Db`, such as
`Db::table()` and `Db::select()`, which core uses. The class needs Shopclass 7.0; the
helpers also work on 6.x.

## Write transactions

In a transaction, lock the rows you will change before the first plain `SELECT`. Lock them
with `SELECT ... FOR UPDATE`, or by running the `UPDATE` itself.

Any plain `SELECT` opens a snapshot. That includes a DAO read and a read made by a hook. On
MariaDB 11.6 and later, a later write to a row another request changed since then fails with
error 1020 ("Record has changed since last read"). MySQL does not raise it.

Lock in this order, and never the other way round: user, wallet, listing, listing stats,
listing variants (by id), order, order lines. `ListingStore::lockForWrite($id)` takes the
listing, its stats and its variants in that order. Do side work that needs no lock, such as
counters, jobs and mail, after the commit with `Db::afterCommit()`.

A plugin feature's `apply()` method must lock its listing first, with
`ListingStore::lockForWrite($id)`. Use `ListingStore::find($id, $columns, true)` only when you
touch the `t_item` row alone.

## Adding your own tables

Create them on plugin install, drop them on uninstall, and prefix them with both
`DB_TABLE_PREFIX` and your plugin slug:

```php
$table = DB_TABLE_PREFIX . 't_myplugin_data';
```

### Migrations for your tables

Put numbered files in your plugin's `migrations/` folder (`0001_create_data.sql`,
`0002_add_column.php`). They work like core's: a `.sql` file runs as written, a `.php` file
returns an object with `up(Connection $conn)`. Each file runs once. Shopclass 7.0 and later.

```php
use mindstellar\migration\MigrationRunner;

// install hook, and on `init` after an update (check your own version first)
$runner = MigrationRunner::forPlugin('myplugin');
$runner->ensureLedger();
$result = $runner->run(); // ['ok' => bool, 'applied' => [files run], 'failed' => ?string, 'error' => ?string]

// uninstall hook, after dropping your tables
MigrationRunner::forPlugin('myplugin')->forget();
```

A file that throws stops the run and is not recorded, so the next run tries it again. When
`ok` is false, log `error` and carry on; when another run holds the lock, `failed` is null.

The name is your plugin's folder. Core records each file as `myplugin/0001_create_data.sql`,
so your file names never clash with core's or another plugin's.

Do not add columns to core tables. A migration will not know about them, and
`db:repair` never removes a column: it stays flagged as extra until someone
removes it by hand.
