---
title: Key-value store
description: "Keep small values by group and key without a table of your own: osc_kv_get(), osc_kv_set(), osc_kv_delete(), osc_kv_claim() and osc_kv_delete_group(), with expiry, JSON values and the key rules."
sidebar:
  order: 29
---

`t_key_value` is a small shared store for values that do not deserve a table. A value lives under a
**group** and a **key**, and can expire.

Use it for a last-sync time, a cached API answer, a one-at-a-time lock or a flag.

| It is | It is not |
|---|---|
| A place for small JSON values | A cache. Reads hit the database, and nothing is kept in memory. |
| Safe for one-holder locks (`osc_kv_claim()`) | A queue. Use [background jobs](/docs/developers/jobs/). |
| Cleaned up for you when a key expires | A place for personal data. See [Personal data](#personal-data). |

## The shortest complete example

```php
osc_kv_set('acme', 'last_sync', array('at' => time()), 3600);   // lives one hour
$sync = osc_kv_get('acme', 'last_sync', array());               // array() once it expired

if (osc_kv_claim('acme', 'nightly_export', 600)) {              // one process at a time
    acme_export();
    osc_kv_delete('acme', 'nightly_export');                    // release early
}
```

## Functions

All are in `oc-includes/osclass/helpers/hKv.php`. Each throws `InvalidArgumentException` for
a bad group or key, and `mindstellar\database\DbException` when the query fails.

| Function | Returns |
|---|---|
| `osc_kv_get(string $group, string $key, $default = null)` | The stored value, decoded. `$default` when the key is absent or expired. |
| `osc_kv_set(string $group, string $key, $value, ?int $ttl = null): void` | Nothing. Replaces any value and expiry the key had. `$ttl` is seconds; `null` keeps it until deleted. |
| `osc_kv_delete(string $group, string $key): bool` | `false` when there was nothing to remove. |
| `osc_kv_claim(string $group, string $key, int $ttl, string $state = 'locked'): bool` | `true` for exactly one caller while the key is absent or expired. |
| `osc_kv_delete_group(string $group): int` | How many keys were removed. |

## Values

Values are stored as JSON and come back decoded. Strings, numbers, booleans, `null` and
arrays all round-trip. An object comes back as an associative array.

`osc_kv_get()` tells a missing key from a stored `null`:

| State of the key | `osc_kv_get('acme', 'k', 'fallback')` |
|---|---|
| Never set, deleted, or expired | `'fallback'` |
| Set to `null` | `null` |

`osc_kv_set()` throws when the value cannot be encoded as JSON, or when the JSON is over
16 MB (`16,777,215` bytes). Keep values small anyway: every read loads the whole value.

## Group and key rules

| Part | Rule |
|---|---|
| Group | 1 to 64 characters of `a-z`, `0-9`, `_`, `.`, `-`. Starts with a letter or digit. The same as `^[a-z0-9][a-z0-9_.-]{0,63}$`. |
| Key | 1 to 191 characters of valid UTF-8. No control characters. No space at the start or end. |

Keys match **exactly**, byte for byte: `Order` and `order` are two keys.

Name your group after your plugin slug, such as `acme`. Do not use a name core uses:

| Reserved group | Used by |
|---|---|
| `api_idempotency` | The REST API's `Idempotency-Key` replay |
| `api_webhook` | The REST API's webhook endpoints: one key per endpoint (`ep_…`), holding its address, events, secret and failure count. It has no expiry. |
| `market` | The plugin and theme catalogue cache from the market, so it is not loaded on every page. |

## Expiry

Pass `$ttl` to `osc_kv_set()` and the key expires that many seconds later. A `$ttl` below 1
is an error.

- An expired key reads as absent straight away.
- The daily cron removes the expired rows, so they do not pile up.
- `osc_kv_set()` on an existing key replaces its value **and** its expiry. A call with no
  `$ttl` makes it permanent.

## Locks with `osc_kv_claim()`

`osc_kv_claim()` takes a key for `$ttl` seconds. Of several callers racing for it, exactly
one gets `true`. The rest get `false` until the key is deleted or expires.

```php
if (!osc_kv_claim('acme', 'import', 900)) {
    return; // another run holds it
}
try {
    acme_import();
} finally {
    osc_kv_delete('acme', 'import');
}
```

Always pass a realistic `$ttl`. If the holder crashes, the lock frees itself when it
expires. `$state` is a short label stored with the key: 1 to 16 characters of `a-z`, `0-9`,
`_`, `.`, `-`.

## Personal data

`t_key_value` has no user column. Core's data export and account erasure do **not** reach it, and
list the table as retained. If your plugin stores something about a person here, your plugin
must export and erase it itself.

## Clean up on uninstall

Remove your whole group when the plugin is uninstalled:

```php
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', static function () {
    osc_kv_delete_group('acme');
});
```
