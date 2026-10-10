<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Characterization pins for the ItemResource model.
 *
 * Listing photos are the `item` rows of t_resource. ItemResource reads and writes
 * them in the old t_item_resource row shape (pk_i_id, fk_i_item_id, s_name,
 * s_extension, s_content_type, s_path, s_storage), so callers that predate the move
 * see the same columns, values and ordering. Rows of other owner types share the
 * table and the id range, and the model must never return, count, change or
 * delete them.
 *
 * The quirks kept from the old model, all reproduced rather than fixed:
 *
 *  - getAllResourcesFromItem()/primeResourcesCache() are a memo/prefetch pair over
 *    osc_cache_*. On a default install the object cache is a request-lifetime PHP
 *    array, so it is a genuine within-process memo: a cold read costs one query, a
 *    repeat read costs none, and priming n items costs ONE query and makes all n
 *    reads free. An item with no photos is memoized too.
 *  - Nothing invalidates that memo. Deleting photos through this very model leaves
 *    the earlier result being served for the rest of the process.
 *  - A FAILED query is handled differently by the two halves of the pair: the read
 *    returns an empty array and stores nothing, while the prime seeds every id it
 *    was given with an empty array.
 *  - getAllResources() and getResources() INNER JOIN t_item, so a photo whose
 *    listing is gone is invisible through them while countResources(),
 *    getResourceIdsBatch() and getResourcesBatchByStorage() still see it.
 *  - The paging trio takes the FIRST argument as the offset. A second argument of
 *    0 or less turns the first into a row count, and a non-numeric first argument
 *    to getResources() returns every row.
 *  - existResource() returns the string '0' for a no-match but the int 0 for a
 *    null argument, and countResources() ignores a non-numeric item id and counts
 *    every photo.
 *
 * Usage:  php tests/models/itemresource.php          (standalone, own scratch database)
 *         php tests/run-models.php itemresource      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_itemresource');

/*
 * The model reaches osc_cache_get()/osc_cache_set() and osc_base_url(). The cache
 * helpers are real (hCache.php below) because the cache behaviour IS the contract
 * here; osc_base_url() and osc_plugins_path() are stood in for.
 *
 * They cannot be taken from hDefines.php: that file also declares
 * osc_uploads_path() with no function_exists guard, and the shared scratch-db
 * bootstrap has always defined a stand-in for it before any model test file is
 * loaded — so requiring hDefines.php from a model test is a redeclare fatal no
 * matter what this file does. Both stand-ins are guarded and return exactly what
 * the real helpers return in a test process (no filters registered, no plugins).
 */
if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';   // osc_add_hook, used by hCache at load
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php'; // osc_language
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';     // osc_current_user_locale
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';

/*
 * osc_cache_get() suffixes its key with osc_current_user_locale(), which reads a
 * preference; the Preference singleton loads the whole table on construction. Warm
 * it here so that one-off query is never attributed to a query-count measurement.
 */
Preference::getInstance();

$model = ItemResource::getInstance();
$table = DB_TABLE_PREFIX . 't_resource';
$cache = \mindstellar\cache\CacheManager::getInstance();

/**
 * Empty the object cache.
 *
 * The runner shares one process across every test file, and the default cache
 * driver is a plain PHP array with no expiry, so entries left by an earlier
 * section (or an earlier file) would survive into a table that has since been
 * emptied. Every pin below that depends on a cold cache resets it first.
 */
$flush = static function () use ($cache): void {
    $cache->flush();
};

/**
 * The exact key getAllResourcesFromItem() reads and primeResourcesCache() writes.
 *
 * @param int|string|null $itemId
 */
$cacheKey = static function ($itemId): string {
    return md5(osc_base_url() . 'ItemResource:getAllResourcesFromItem:' . $itemId) . osc_current_user_locale();
};

/**
 * Seed a listing photo, raw, never through the code under test.
 *
 * @return int The id just inserted
 */
$seedResource = static function (
    int $itemId,
    string $name = 'abc',
    string $storage = 'local',
    string $extension = 'jpg',
    string $contentType = 'image/jpeg'
) use ($admin): int {
    return seed_photo($admin, $itemId, array(
        's_name'         => $name,
        's_storage'      => $storage,
        's_extension'    => $extension,
        's_content_type' => $contentType,
    ));
};

/**
 * Seed a t_resource row of another owner type, which ItemResource must never see.
 *
 * @return int The id just inserted
 */
$seedForeign = static function (string $ownerType, int $ownerId, string $name, string $storage = 'local') use ($admin, $table): int {
    return seed_exec(
        $admin,
        "INSERT INTO $table (s_owner_type, i_owner_id, s_name, s_extension, s_content_type, s_path, s_storage, dt_created)
         VALUES (?, ?, ?, 'jpg', 'image/jpeg', 'oc-content/uploads/0/', ?, NOW())",
        'siss',
        array($ownerType, $ownerId, $name, $storage)
    );
};

/** A foreign row as it sits in the table, to prove it was left alone. */
$foreignRow = static function (int $id) use ($admin, $table): ?array {
    return $admin->query("SELECT s_owner_type, i_owner_id, s_name, s_storage FROM $table WHERE pk_i_id = $id")->fetch_assoc();
};

/**
 * Force an item's publication date, so the c.dt_pub_date ordering pins have
 * something deterministic to sort on (seed_item() stamps every row with NOW()).
 */
$setPubDate = static function (int $itemId, string $date) use ($admin): void {
    seed_exec(
        $admin,
        'UPDATE ' . DB_TABLE_PREFIX . 't_item SET dt_pub_date = ? WHERE pk_i_id = ?',
        'si',
        array($date, $itemId)
    );
};

/**
 * Run $fn with t_resource renamed out of the way, so every query this model
 * makes fails. It is the only way to reach the error-fallback branches, and those
 * branches are the whole reason the conversion needs a catch per method.
 *
 * @return mixed Whatever $fn returned
 */
$withTableMissing = static function (callable $fn) use ($admin, $table) {
    $admin->query("RENAME TABLE `$table` TO `{$table}_hidden`");
    $previous = error_reporting(E_ALL & ~E_WARNING);
    try {
        return $fn();
    } finally {
        error_reporting($previous);
        $admin->query("RENAME TABLE `{$table}_hidden` TO `$table`");
    }
};

/* ----------------------------------------------------------------------------
 * Surface: the public methods and their signatures stay as they were.
 * ------------------------------------------------------------------------- */
harness_section('ItemResource: public surface');

pin('the table is t_resource', $table, $model->getTableName());
pin('the owner type of listing photos', 'item', ItemResource::OWNER);
pin('getInstance returns the shared instance', true, ItemResource::getInstance() === $model);
pin('newInstance returns it too', true, ItemResource::newInstance() === $model);
pin(
    'field list is the old row shape',
    array('pk_i_id', 'fk_i_item_id', 's_name', 's_extension', 's_content_type', 's_path', 's_storage'),
    $model->getFields()
);
pin('getTableItemName is unchanged', DB_TABLE_PREFIX . 't_item', $model->getTableItemName());
pin('getTableItemDescription is unchanged', DB_TABLE_PREFIX . 't_item_description', $model->getTableItemDescription());

pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('ItemResource', 'newInstance'));
pin('getAllResources signature is unchanged', 'public getAllResources()', harness_method_signature('ItemResource', 'getAllResources'));
pin('getTableItemName signature is unchanged', 'public getTableItemName()', harness_method_signature('ItemResource', 'getTableItemName'));
pin(
    'getAllResourcesFromItem signature is unchanged',
    'public getAllResourcesFromItem($itemId)',
    harness_method_signature('ItemResource', 'getAllResourcesFromItem')
);
pin(
    'primeResourcesCache signature is unchanged',
    'public primeResourcesCache($itemIds)',
    harness_method_signature('ItemResource', 'primeResourcesCache')
);
pin('getResource signature is unchanged', 'public getResource($itemId)', harness_method_signature('ItemResource', 'getResource'));
pin(
    'getResourceSecure signature is unchanged',
    'public getResourceSecure($resourceId, $code)',
    harness_method_signature('ItemResource', 'getResourceSecure')
);
pin(
    'existResource signature is unchanged',
    'public existResource($resourceId, $code)',
    harness_method_signature('ItemResource', 'existResource')
);
pin(
    'countResources signature is unchanged',
    'public countResources($itemId = NULL)',
    harness_method_signature('ItemResource', 'countResources')
);
pin(
    'getResources signature is unchanged',
    "public getResources(\$itemId = NULL, \$start = 0, \$length = 10, \$order = 'r.pk_i_id', \$type = 'DESC')",
    harness_method_signature('ItemResource', 'getResources')
);
pin(
    'getResourceIdsBatch signature is unchanged',
    'public getResourceIdsBatch(int $offset, int $limit): array',
    harness_method_signature('ItemResource', 'getResourceIdsBatch')
);
pin(
    'getResourcesBatchByStorage signature is unchanged',
    'public getResourcesBatchByStorage(string $storage, int $offset, int $limit): array',
    harness_method_signature('ItemResource', 'getResourcesBatchByStorage')
);
pin(
    'deleteResourcesIds signature is unchanged',
    'public deleteResourcesIds($ids)',
    harness_method_signature('ItemResource', 'deleteResourcesIds')
);
pin(
    'getTableItemDescription signature is unchanged',
    'public getTableItemDescription()',
    harness_method_signature('ItemResource', 'getTableItemDescription')
);

pin(
    'the public methods, nothing added or removed',
    array(
        'cacheKey',
        'columns',
        'countResources',
        'deleteResourcesIds',
        'existResource',
        'findByPrimaryKey',
        'getAllResources',
        'getAllResourcesFromItem',
        'getFields',
        'getInstance',
        'getResource',
        'getResourceIdsBatch',
        'getResourceSecure',
        'getResources',
        'getResourcesBatchByStorage',
        'getTableItemDescription',
        'getTableItemName',
        'getTableName',
        'insertGetId',
        'newInstance',
        'primeResourcesCache',
        'update',
        'updateByPrimaryKey',
    ),
    (static function () {
        $own = array();
        foreach ((new ReflectionClass('ItemResource'))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            $own[] = $m->getName();
        }
        sort($own);

        return $own;
    })()
);

/* ----------------------------------------------------------------------------
 * The empty-table ledger: what every read returns before anything is seeded.
 * ------------------------------------------------------------------------- */
harness_section('ItemResource: empty-table ledger');

// No photos yet, but a user's row sits in the table under owner id 1.
$f0 = $seedForeign('user', 1, 'aaa');
$flush();
pin('getAllResources on an empty table returns an empty array', array(), $model->getAllResources());
pin('getAllResourcesFromItem on an empty table returns an empty array', array(), $model->getAllResourcesFromItem(1));
pin('getResource on an empty table returns an empty array', array(), $model->getResource(1));
pin('existResource on an empty table returns the string "0"', '0', $model->existResource($f0, 'aaa'));
pin('countResources on an empty table returns the string "0"', '0', $model->countResources());
pin('countResources(id) on an empty table returns the string "0"', '0', $model->countResources(1));
pin('getResources on an empty table returns an empty array', array(), $model->getResources());
pin('getResourceIdsBatch on an empty table returns an empty array', array(), $model->getResourceIdsBatch(0, 10));
pin(
    'getResourcesBatchByStorage on an empty table returns an empty array',
    array(),
    $model->getResourcesBatchByStorage('local', 0, 10)
);
pin('deleteResourcesIds on an empty table returns int 0', 0, $model->deleteResourcesIds(array($f0)));
pin('findByPrimaryKey on an empty table returns false', false, $model->findByPrimaryKey($f0));
pin('the user row was left alone', array('s_owner_type' => 'user', 'i_owner_id' => '1', 's_name' => 'aaa', 's_storage' => 'local'), $foreignRow($f0));
$flush();

/* ----------------------------------------------------------------------------
 * Core fixtures.
 * ------------------------------------------------------------------------- */
$categoryId = seed_category($admin, 'Motors');
$userId     = seed_user($admin);

$itemA = seed_item($admin, $categoryId, $userId, 'Item A');
$itemB = seed_item($admin, $categoryId, $userId, 'Item B');
$itemC = seed_item($admin, $categoryId, $userId, 'Item C'); // deliberately has no resources
$setPubDate($itemA, '2026-01-01 00:00:00');
$setPubDate($itemB, '2026-02-02 00:00:00');
$setPubDate($itemC, '2026-03-03 00:00:00');

// Rows of other owner types sit between the photos, under the same owner ids.
$fA  = $seedForeign('user', $itemA, 'aaa');
$rA1 = $seedResource($itemA, 'aaa');
$rA2 = $seedResource($itemA, 'bbb');
$fC  = $seedForeign('page', $itemC, 'zzz', 's3');
$rB1 = $seedResource($itemB, 'ccc', 's3', 'png', 'image/png');

/* ----------------------------------------------------------------------------
 * getAllResources() — every resource joined to its item.
 * ------------------------------------------------------------------------- */
harness_section('ItemResource::getAllResources');

$all = $model->getAllResources();
pin('returns every resource row', 3, count($all));
pin(
    'the row carries the seven schema columns plus the joined dt_pub_date, in that order',
    array('pk_i_id', 'fk_i_item_id', 's_name', 's_extension', 's_content_type', 's_path', 's_storage', 'dt_pub_date'),
    array_keys($all[0])
);
check('every value in every row is a string or null (C4)', all_rows_string($all), describe($all));
pin('dt_pub_date comes from the item, not the resource', '2026-01-01 00:00:00', $all[0]['dt_pub_date']);
pin('s_storage round-trips', 's3', $all[2]['s_storage']);
pin(
    'no ORDER BY: the rows arrive in primary-key order',
    array((string)$rA1, (string)$rA2, (string)$rB1),
    array_column($all, 'pk_i_id')
);
pin('it costs one statement', 1, harness_query_count(static function () use ($model) {
    $model->getAllResources();
}));

/* ----------------------------------------------------------------------------
 * getAllResourcesFromItem() — the memo half of the prefetch pair (C9).
 * ------------------------------------------------------------------------- */
harness_section('ItemResource::getAllResourcesFromItem — the return ledger');

$flush();
$fromItem = $model->getAllResourcesFromItem($itemA);
pin('returns only that item\'s resources', 2, count($fromItem));
pin(
    'the row carries exactly the seven schema columns — no dt_pub_date, this one does not join',
    array('pk_i_id', 'fk_i_item_id', 's_name', 's_extension', 's_content_type', 's_path', 's_storage'),
    array_keys($fromItem[0])
);
check('every value in every row is a string or null (C4)', all_rows_string($fromItem), describe($fromItem));
pin(
    'rows arrive in primary-key order',
    array((string)$rA1, (string)$rA2),
    array_column($fromItem, 'pk_i_id')
);

$flush();
pin('an item with no resources returns an empty array', array(), $model->getAllResourcesFromItem($itemC));
$flush();
pin('an unknown item id returns an empty array', array(), $model->getAllResourcesFromItem(999999));
$flush();
pin('a null item id is cast to 0 and returns an empty array', array(), $model->getAllResourcesFromItem(null));

harness_section('ItemResource::getAllResourcesFromItem — the cache contract (C9)');

$flush();
$coldCost = harness_query_count(static function () use ($model, $itemA) {
    $model->getAllResourcesFromItem($itemA);
});
pin('a cold read costs one query', 1, $coldCost);

$warmCost = harness_query_count(static function () use ($model, $itemA) {
    $model->getAllResourcesFromItem($itemA);
});
pin('a repeat read costs zero queries — it is served from the memo', 0, $warmCost);

$first  = $model->getAllResourcesFromItem($itemA);
$second = $model->getAllResourcesFromItem($itemA);
check('the memoized value is identical to the first result', $first === $second);
check('the memoized rows are still all strings (C4)', all_rows_string($second), describe($second));

$flush();
$model->getAllResourcesFromItem($itemA);
pin(
    'exactly one entry is stored, under md5(base_url + "ItemResource:getAllResourcesFromItem:" + id) + locale',
    array($cacheKey($itemA)),
    array_keys($cache->cache)
);

$flush();
$model->getAllResourcesFromItem((string)$itemA);
pin('a string item id lands on the same key as the int', array($cacheKey($itemA)), array_keys($cache->cache));

/* An item with NO resources is memoized as well — the empty array is a hit, not a
 * miss, so it does not fall through to its own query on the next read. */
$flush();
$emptyCold = harness_query_count(static function () use ($model, $itemC) {
    $model->getAllResourcesFromItem($itemC);
});
$emptyWarm = harness_query_count(static function () use ($model, $itemC) {
    $model->getAllResourcesFromItem($itemC);
});
pin('an item with no resources costs one query cold', 1, $emptyCold);
pin('...and zero warm: the empty result is memoized too', 0, $emptyWarm);

/* A different item does not ride on another item's entry. */
$flush();
$model->getAllResourcesFromItem($itemA);
pin('a different item still costs its own query', 1, harness_query_count(static function () use ($model, $itemB) {
    $model->getAllResourcesFromItem($itemB);
}));

/* Nothing invalidates the memo — not even a delete through this same model. */
$itemS = seed_item($admin, $categoryId, $userId, 'Item S');
$rS1   = $seedResource($itemS, 'stale');
$flush();
pin('the item has one resource before the delete', 1, count($model->getAllResourcesFromItem($itemS)));
$model->deleteResourcesIds(array($rS1));
pin(
    'after deleting it through this model the memo still serves the old row — no write invalidates it',
    1,
    count($model->getAllResourcesFromItem($itemS))
);
$flush();
pin('only a cache flush makes the delete visible', array(), $model->getAllResourcesFromItem($itemS));

/* ----------------------------------------------------------------------------
 * primeResourcesCache() — the prefetch half (C9).
 * ------------------------------------------------------------------------- */
harness_section('ItemResource::primeResourcesCache');

pin('it returns null', null, $model->primeResourcesCache(array($itemA)));

$flush();
$primeCost = harness_query_count(static function () use ($model, $itemA, $itemB, $itemC) {
    $model->primeResourcesCache(array($itemA, $itemB, $itemC));
});
pin('priming three items costs exactly one query', 1, $primeCost);
pin(
    'it seeds one entry per id, on the exact keys the reader uses',
    array($cacheKey($itemA), $cacheKey($itemB), $cacheKey($itemC)),
    array_keys($cache->cache)
);
pin('reading all three afterwards costs nothing', 0, harness_query_count(static function () use ($model, $itemA, $itemB, $itemC) {
    $model->getAllResourcesFromItem($itemA);
    $model->getAllResourcesFromItem($itemB);
    $model->getAllResourcesFromItem($itemC);
}));

$flush();
$model->primeResourcesCache(array($itemA, $itemB, $itemC));
$primed = $model->getAllResourcesFromItem($itemA);
$flush();
$direct = $model->getAllResourcesFromItem($itemA);
pin('a primed read returns exactly what an unprimed read returns', $direct, $primed);
pin('an item with no resources is primed with an empty array', array(), $model->getAllResourcesFromItem($itemC));

$flush();
pin('an empty id list costs no query at all', 0, harness_query_count(static function () use ($model) {
    $model->primeResourcesCache(array());
}));
pin('...and stores nothing', 0, count($cache->cache));

$flush();
$model->primeResourcesCache($itemA);
pin('a bare id (not an array) is accepted and primes that one item', array($cacheKey($itemA)), array_keys($cache->cache));

$flush();
$model->primeResourcesCache(array($itemA, $itemA, (string)$itemA));
pin('duplicate and string-typed ids collapse to a single entry', array($cacheKey($itemA)), array_keys($cache->cache));

$flush();
$model->primeResourcesCache(array(null, 'abc'));
pin(
    'ids that are not numbers become 0 and are primed as an empty result',
    array($cacheKey(0)),
    array_keys($cache->cache)
);

/* ----------------------------------------------------------------------------
 * getResource() — the first resource of an item.
 * ------------------------------------------------------------------------- */
harness_section('ItemResource::getResource');

$one = $model->getResource($itemA);
pin(
    'a match returns one row with exactly the seven schema columns',
    array('pk_i_id', 'fk_i_item_id', 's_name', 's_extension', 's_content_type', 's_path', 's_storage'),
    array_keys($one)
);
pin('it is the lowest-numbered resource of that item', (string)$rA1, $one['pk_i_id']);
check('every value is a string or null (C4)', all_values_string($one), describe($one));

pin('an item with no resources returns an empty array', array(), $model->getResource($itemC));
pin('an unknown item id returns an empty array', array(), $model->getResource(999999));
pin('item id 0 returns an empty array', array(), $model->getResource(0));

$previous = error_reporting(E_ALL & ~E_WARNING);
pin(
    'a null item id returns an empty array — the malformed comparison and a zero-row match land on the same value',
    array(),
    $model->getResource(null)
);
error_reporting($previous);

pin('a string item id matches the same row', (string)$rA1, $model->getResource((string)$itemA)['pk_i_id']);
pin('it costs one statement', 1, harness_query_count(static function () use ($model, $itemA) {
    $model->getResource($itemA);
}));

/* ----------------------------------------------------------------------------
 * existResource() / getResourceSecure() — a COUNT(*) returned as a string.
 * ------------------------------------------------------------------------- */
harness_section('ItemResource::existResource / getResourceSecure');

pin('a matching id+name pair returns the string "1"', '1', $model->existResource($rA1, 'aaa'));
pin('the right id with the wrong name returns the string "0"', '0', $model->existResource($rA1, 'bbb'));
pin('an unknown id returns the string "0"', '0', $model->existResource(999999, 'aaa'));
pin('a non-numeric id returns the string "0"', '0', $model->existResource('abc', 'aaa'));
pin('an empty name returns the string "0"', '0', $model->existResource($rA1, ''));
pin('getResourceSecure delegates to existResource verbatim', $model->existResource($rA1, 'aaa'), $model->getResourceSecure($rA1, 'aaa'));

/* A null argument leaves the comparison without a right-hand side, so the query
 * itself fails and the fallback returns the INT zero — a different value from the
 * string '0' a genuine no-match produces. Callers only test truthiness, but the
 * type is part of the observable shape. */
$previous = error_reporting(E_ALL & ~E_WARNING);
pin('a null resource id returns int 0, not the string "0"', 0, $model->existResource(null, 'aaa'));
pin('a null code returns int 0, not the string "0"', 0, $model->existResource($rA1, null));
error_reporting($previous);

pin('it costs one statement', 1, harness_query_count(static function () use ($model, $rA1) {
    $model->existResource($rA1, 'aaa');
}));

/* ----------------------------------------------------------------------------
 * countResources() — an optional, silently-dropped filter.
 * ------------------------------------------------------------------------- */
harness_section('ItemResource::countResources');

pin('no argument counts the whole table, as a string', '3', $model->countResources());
pin('an item id counts that item\'s resources', '2', $model->countResources($itemA));
pin('a numeric string is accepted as an item id', '1', $model->countResources((string)$itemB));
pin('an item with no resources returns the string "0"', '0', $model->countResources($itemC));
pin('an unknown item id returns the string "0"', '0', $model->countResources(999999));
pin('item id 0 returns the string "0"', '0', $model->countResources(0));
pin('a null item id counts the whole table — the filter is only applied when numeric', '3', $model->countResources(null));
pin('a non-numeric item id also counts the whole table', '3', $model->countResources('abc'));
pin('false is non-numeric, so it counts the whole table too', '3', $model->countResources(false));
pin('it costs one statement', 1, harness_query_count(static function () use ($model, $itemA) {
    $model->countResources($itemA);
}));

/* ----------------------------------------------------------------------------
 * getResources() — the joined, ordered, paged listing.
 * ------------------------------------------------------------------------- */
harness_section('ItemResource::getResources — column set and filtering');

$listed = $model->getResources($itemA);
pin('an item id filters to that item', 2, count($listed));
pin(
    'the row carries the seven schema columns plus the joined dt_pub_date',
    array('pk_i_id', 'fk_i_item_id', 's_name', 's_extension', 's_content_type', 's_path', 's_storage', 'dt_pub_date'),
    array_keys($listed[0])
);
check('every value in every row is a string or null (C4)', all_rows_string($listed), describe($listed));
pin('an item with no resources returns an empty array', array(), $model->getResources($itemC));
pin('a null item id lists every resource', 3, count($model->getResources(null, 0, 100)));
pin('a non-numeric item id also lists every resource — the filter is only applied when numeric', 3, count($model->getResources('abc', 0, 100)));

harness_section('ItemResource::getResources — ordering');

pin(
    'the default order is r.pk_i_id DESC',
    array((string)$rB1, (string)$rA2, (string)$rA1),
    array_column($model->getResources(null, 0, 100), 'pk_i_id')
);
pin(
    'r.pk_i_id ASC reverses it',
    array((string)$rA1, (string)$rA2, (string)$rB1),
    array_column($model->getResources(null, 0, 100, 'r.pk_i_id', 'ASC'), 'pk_i_id')
);
pin(
    'a lower-case direction is accepted',
    array((string)$rA1, (string)$rA2, (string)$rB1),
    array_column($model->getResources(null, 0, 100, 'r.pk_i_id', 'asc'), 'pk_i_id')
);
pin(
    'r.fk_i_item_id ASC groups by item',
    array((string)$rA1, (string)$rA2, (string)$rB1),
    array_column($model->getResources(null, 0, 100, 'r.fk_i_item_id', 'ASC'), 'pk_i_id')
);
pin(
    'c.dt_pub_date DESC puts the newest item\'s resource first',
    (string)$rB1,
    $model->getResources(null, 0, 100, 'c.dt_pub_date', 'DESC')[0]['pk_i_id']
);
pin(
    'c.dt_pub_date ASC puts it last',
    (string)$rB1,
    $model->getResources(null, 0, 100, 'c.dt_pub_date', 'ASC')[2]['pk_i_id']
);

harness_section('ItemResource::getResources — the bad-input guards');

pin('an order column outside the allowlist returns an empty array', array(), $model->getResources(null, 0, 10, 'r.s_name'));
pin('...without issuing a query', 0, harness_query_count(static function () use ($model) {
    $model->getResources(null, 0, 10, 'r.s_name');
}));
pin('an order direction outside DESC/ASC returns an empty array', array(), $model->getResources(null, 0, 10, 'r.pk_i_id', 'sideways'));
pin('...without issuing a query', 0, harness_query_count(static function () use ($model) {
    $model->getResources(null, 0, 10, 'r.pk_i_id', 'sideways');
}));
pin('the string "0" is rejected as a direction by the model\'s own guard', array(), $model->getResources(null, 0, 10, 'r.pk_i_id', '0'));
pin('a direction with surrounding whitespace is rejected too', array(), $model->getResources(null, 0, 10, 'r.pk_i_id', ' asc'));

harness_section('ItemResource::getResources — paging (first argument is the OFFSET)');

pin(
    'start=0, length=2 returns the first two rows of the default DESC order',
    array((string)$rB1, (string)$rA2),
    array_column($model->getResources(null, 0, 2), 'pk_i_id')
);
pin(
    'start=1, length=1 skips one row and returns one — count and offset are not swapped',
    array((string)$rA2),
    array_column($model->getResources(null, 1, 1), 'pk_i_id')
);
pin(
    'start=2, length=1 returns the last row',
    array((string)$rA1),
    array_column($model->getResources(null, 2, 1), 'pk_i_id')
);
pin(
    'length=0 drops the offset and turns start into a row count',
    array((string)$rB1, (string)$rA2),
    array_column($model->getResources(null, 2, 0), 'pk_i_id')
);
pin('length=-1 behaves the same way, so start=0 yields no rows', array(), $model->getResources(null, 0, -1));
pin('a negative start makes the clause invalid and the query fails, returning an empty array', array(), $model->getResources(null, -1, 10));
pin('a non-numeric start drops the whole clause and returns every row', 3, count($model->getResources(null, 'x', 2)));
pin('it costs one statement', 1, harness_query_count(static function () use ($model, $itemA) {
    $model->getResources($itemA);
}));

/* ----------------------------------------------------------------------------
 * getResourceIdsBatch() — ids only, ascending, for whole-table walks.
 * ------------------------------------------------------------------------- */
harness_section('ItemResource::getResourceIdsBatch');

pin('it returns native ints, ascending by primary key', array($rA1, $rA2, $rB1), $model->getResourceIdsBatch(0, 100));
pin('offset and limit page through it', array($rA2), $model->getResourceIdsBatch(1, 1));
pin('a page past the end is empty', array(), $model->getResourceIdsBatch(99, 10));
pin('limit=0 with offset=0 returns nothing', array(), $model->getResourceIdsBatch(0, 0));
pin('limit=0 with a positive offset turns the offset into a row count', array($rA1, $rA2), $model->getResourceIdsBatch(2, 0));
pin('a negative offset makes the clause invalid and returns an empty array', array(), $model->getResourceIdsBatch(-1, 5));
pin('a negative limit drops the offset, so offset=1 returns one row', array($rA1), $model->getResourceIdsBatch(1, -5));
pin('it costs one statement', 1, harness_query_count(static function () use ($model) {
    $model->getResourceIdsBatch(0, 10);
}));

/* ----------------------------------------------------------------------------
 * getResourcesBatchByStorage() — full rows for one storage adapter.
 * ------------------------------------------------------------------------- */
harness_section('ItemResource::getResourcesBatchByStorage');

$local = $model->getResourcesBatchByStorage('local', 0, 100);
pin('it filters on s_storage', 2, count($local));
pin(
    'the row carries exactly the seven schema columns — this one does not join',
    array('pk_i_id', 'fk_i_item_id', 's_name', 's_extension', 's_content_type', 's_path', 's_storage'),
    array_keys($local[0])
);
check('every value in every row is a string or null (C4)', all_rows_string($local), describe($local));
pin('rows arrive ascending by primary key', array((string)$rA1, (string)$rA2), array_column($local, 'pk_i_id'));
pin('another adapter returns its own rows', array((string)$rB1), array_column($model->getResourcesBatchByStorage('s3', 0, 100), 'pk_i_id'));
pin('an unknown adapter returns an empty array', array(), $model->getResourcesBatchByStorage('nowhere', 0, 100));
pin('offset and limit page through it', array((string)$rA2), array_column($model->getResourcesBatchByStorage('local', 1, 1), 'pk_i_id'));
pin('limit=0 with offset=0 returns nothing', array(), $model->getResourcesBatchByStorage('local', 0, 0));
pin(
    'limit=0 with a positive offset turns the offset into a row count',
    array((string)$rA1),
    array_column($model->getResourcesBatchByStorage('local', 1, 0), 'pk_i_id')
);
pin('a negative offset makes the clause invalid and returns an empty array', array(), $model->getResourcesBatchByStorage('local', -1, 5));
pin('it costs one statement', 1, harness_query_count(static function () use ($model) {
    $model->getResourcesBatchByStorage('local', 0, 10);
}));

/* ----------------------------------------------------------------------------
 * The INNER JOIN on t_item: which reads can see an orphaned resource row.
 * ------------------------------------------------------------------------- */
harness_section('ItemResource: an orphaned resource row and the item join');

$orphan = $seedResource(999999, 'orphan'); // t_resource has no foreign key to t_item

pin('getAllResources hides it — it INNER JOINs t_item', 3, count($model->getAllResources()));
pin('getResources hides it as well', 3, count($model->getResources(null, 0, 100)));
pin('countResources counts it — no join there', '4', $model->countResources());
pin('getResourceIdsBatch returns it', array($rA1, $rA2, $rB1, $orphan), $model->getResourceIdsBatch(0, 100));
pin('getResourcesBatchByStorage returns it', 3, count($model->getResourcesBatchByStorage('local', 0, 100)));
$flush();
pin('getAllResourcesFromItem returns it for the missing item id', 1, count($model->getAllResourcesFromItem(999999)));
pin('getResource returns it too', (string)$orphan, $model->getResource(999999)['pk_i_id']);
pin('and deleting it works', 1, $model->deleteResourcesIds(array($orphan)));
$flush();

/* ----------------------------------------------------------------------------
 * Rows of other owner types: never returned, counted, changed or deleted.
 * ------------------------------------------------------------------------- */
harness_section('ItemResource: rows of other owner types');

$fAWas = $foreignRow($fA);
$fCWas = $foreignRow($fC);
$flush();
pin('a listing\'s photos leave out a user row with the same owner id', array((string)$rA1, (string)$rA2), array_column($model->getAllResourcesFromItem($itemA), 'pk_i_id'));
pin('a listing with only a page row has no photos', array(), $model->getAllResourcesFromItem($itemC));
$flush();
$model->primeResourcesCache(array($itemA, $itemC));
pin('the prime leaves them out too', array(array((string)$rA1, (string)$rA2), array()), array(
    array_column($model->getAllResourcesFromItem($itemA), 'pk_i_id'),
    $model->getAllResourcesFromItem($itemC),
));
$flush();
pin('getResource skips a lower-numbered user row', (string)$rA1, $model->getResource($itemA)['pk_i_id']);
pin('getResource of a listing with only a page row is empty', array(), $model->getResource($itemC));
pin('existResource does not see a user row with a matching name', '0', $model->existResource($fA, 'aaa'));
pin('countResources does not count them', array('3', '2', '0'), array($model->countResources(), $model->countResources($itemA), $model->countResources($itemC)));
pin('getAllResources and getResources leave them out', array(3, 3), array(count($model->getAllResources()), count($model->getResources(null, 0, 100))));
pin('getResourceIdsBatch leaves them out', array($rA1, $rA2, $rB1), $model->getResourceIdsBatch(0, 100));
pin('getResourcesBatchByStorage leaves them out', array((string)$rB1), array_column($model->getResourcesBatchByStorage('s3', 0, 100), 'pk_i_id'));
pin('findByPrimaryKey returns false for one', false, $model->findByPrimaryKey($fA));
pin('findByPrimaryKey of a photo returns the old row shape', array(
    'pk_i_id' => (string)$rA1, 'fk_i_item_id' => (string)$itemA, 's_name' => 'aaa', 's_extension' => 'jpg',
    's_content_type' => 'image/jpeg', 's_path' => 'oc-content/uploads/0/', 's_storage' => 'local',
), $model->findByPrimaryKey($rA1));
pin('updateByPrimaryKey changes nothing on one', 0, $model->updateByPrimaryKey(array('s_name' => 'hit'), $fA));
pin('update by listing id changes nothing on one', 0, $model->update(array('s_path' => 'moved/'), array('fk_i_item_id' => $itemC)));
pin('update with an unknown column is refused', false, $model->update(array('s_owner_type' => 'item'), array('pk_i_id' => $fA)));
pin('deleteResourcesIds deletes none of them', 0, $model->deleteResourcesIds(array($f0, $fA, $fC)));
pin('they are all as they were', array($fAWas, $fCWas, 'user'), array($foreignRow($fA), $foreignRow($fC), $foreignRow($f0)['s_owner_type'] ?? null));
$flush();

/* ----------------------------------------------------------------------------
 * The error-fallback ledger: every branch reached only when the query fails.
 * These are exactly the branches the conversion has to reproduce with a catch.
 * ------------------------------------------------------------------------- */
harness_section('ItemResource: the error-fallback ledger');

$flush();
$withTableMissing(static function () use ($model, $itemA, $rA1, $cache, $flush, $cacheKey) {
    pin('getAllResources returns an empty array', array(), $model->getAllResources());
    pin('getAllResourcesFromItem returns an empty array', array(), $model->getAllResourcesFromItem($itemA));
    pin('...and stores nothing in the cache, so a later read retries', 0, count($cache->cache));
    pin('getResource returns an empty array', array(), $model->getResource($itemA));
    pin('existResource returns int 0, not the string "0"', 0, $model->existResource($rA1, 'aaa'));
    pin('countResources returns int 0, not the string "0"', 0, $model->countResources($itemA));
    pin('getResources returns an empty array', array(), $model->getResources($itemA));
    pin('getResourceIdsBatch returns an empty array', array(), $model->getResourceIdsBatch(0, 10));
    pin('getResourcesBatchByStorage returns an empty array', array(), $model->getResourcesBatchByStorage('local', 0, 10));
    pin('deleteResourcesIds returns bool false', false, $model->deleteResourcesIds(array($rA1)));

    /* The prefetch does NOT bail out when its query fails: it still seeds every id
     * it was given with an empty array, so the memo now reports "no resources" for
     * items that have them. Reproduced, not fixed. */
    $flush();
    pin('primeResourcesCache returns null', null, $model->primeResourcesCache(array($itemA)));
    pin('...but still seeds the id it was given', array($cacheKey($itemA)), array_keys($cache->cache));
    pin('...with an empty array, poisoning the memo', array(), $model->getAllResourcesFromItem($itemA));
});
$flush();
pin('the table is back and reads work again', 3, count($model->getAllResources()));

/* ----------------------------------------------------------------------------
 * deleteResourcesIds() — the return ledger. Destructive, so it runs late.
 * ------------------------------------------------------------------------- */
harness_section('ItemResource::deleteResourcesIds');

pin('an id that matches nothing returns int 0', 0, $model->deleteResourcesIds(array(999999)));
pin('a null id list returns int 0 — it is wrapped into a one-element list', 0, $model->deleteResourcesIds(null));
/* A non-numeric id reaches the DELETE as a string compared against an integer
 * column. MySQL's strict modes make that truncation an ERROR, so the statement
 * never runs and the method reports false; a loose connection -- and MariaDB
 * however strict it is set -- truncates to 0, matches nothing and reports 0.
 *
 * The server is asked rather than guessed at, and asked with the model's own
 * statement: the same QueryBuilder, the same whereIn() that compiles to "IN (?)",
 * and the same value the pin below passes. An "= ?" probe would be a weaker
 * guarantee -- MySQL collapses a one-element IN to =, so the two agree today but
 * nothing holds them together. It runs against a throwaway copy of the table,
 * carrying one real row so the predicate is genuinely evaluated, which leaves the
 * fixtures untouched whichever way the server answers. */
$probeTable = $table . '_strict_probe';
osc_db_execute('CREATE TABLE ' . $probeTable . ' LIKE ' . $table);
osc_db_execute('INSERT INTO ' . $probeTable . ' SELECT * FROM ' . $table . " WHERE s_owner_type = 'item' LIMIT 1");
$numericCompareIsFatal = static function () use ($probeTable): bool {
    $previous = error_reporting(E_ALL & ~E_WARNING);
    try {
        osc_db_table($probeTable)->where('s_owner_type', ItemResource::OWNER)->whereIn('pk_i_id', array('abc'))->delete();

        return false;
    } catch (\mindstellar\database\DbException $e) {
        return true;
    } finally {
        error_reporting($previous);
    }
};
$numericCompareFatal = $numericCompareIsFatal();
pin(
    'the probe deleted nothing from its copy, so it is measuring the comparison and not a match',
    1,
    (int) osc_db_scalar('SELECT COUNT(*) FROM ' . $probeTable)
);
osc_db_execute('DROP TABLE ' . $probeTable);
pin(
    'a non-numeric id deletes nothing',
    $numericCompareFatal ? false : 0,
    $model->deleteResourcesIds('abc')
);
pin('a list holding only null returns int 0', 0, $model->deleteResourcesIds(array(null)));
pin(
    'an EMPTY list returns bool false, not int 0 — the clause is invalid and the query never runs',
    false,
    $model->deleteResourcesIds(array())
);

$d1 = $seedResource($itemA, 'd1');
$d2 = $seedResource($itemA, 'd2');
$d3 = $seedResource($itemA, 'd3');

pin('a single id returns the number of rows deleted', 1, $model->deleteResourcesIds(array($d1)));
pin('a bare id (not an array) is accepted', 1, $model->deleteResourcesIds($d2));
pin('a keyed array is accepted', 1, $model->deleteResourcesIds(array('key' => $d3)));
pin('a list mixing a real id with a junk one deletes the real one', 1, $model->deleteResourcesIds(array($rB1, 'zz')));
pin('a repeated id is still deleted once', 1, $model->deleteResourcesIds(array($rA2, $rA2)));
pin('what remains is the untouched first resource', '1', $model->countResources());
pin('the rows of other owner types are all still there', 3, (int) $admin->query("SELECT COUNT(*) FROM $table WHERE s_owner_type <> 'item'")->fetch_row()[0]);

/* ----------------------------------------------------------------------------
 * The per-item query cost of the resource memo, measured at two fixture sizes.
 * This is the N+1 baseline a later, separately scoped fix is expected to flatten;
 * it is NOT fixed here. It assumes the default object cache driver — a plain PHP
 * array with request lifetime.
 * ------------------------------------------------------------------------- */
harness_section('ItemResource: per-item query cost (the N+1 baseline)');

$makeItems = static function (int $n, string $tag) use ($admin, $categoryId, $userId, $seedResource): array {
    $ids = array();
    for ($i = 0; $i < $n; $i++) {
        $id = seed_item($admin, $categoryId, $userId, "$tag $i");
        $seedResource($id, "$tag-$i");
        $ids[] = $id;
    }

    return $ids;
};

$smallSet = $makeItems(2, 'small');
$largeSet = $makeItems(8, 'large');

$loop = static function (array $ids) use ($model) {
    foreach ($ids as $id) {
        $model->getAllResourcesFromItem($id);
    }
};

$flush();
$coldSmall = harness_query_count(static function () use ($loop, $smallSet) {
    $loop($smallSet);
});
$flush();
$coldLarge = harness_query_count(static function () use ($loop, $largeSet) {
    $loop($largeSet);
});
pin('a cold loop over 2 items costs 2 queries', 2, $coldSmall);
pin('a cold loop over 8 items costs 8 queries', 8, $coldLarge);
check(
    'the cost is exactly one query per item — the N+1 this unit does NOT fix',
    ($coldLarge - $coldSmall) === 6,
    "small=$coldSmall large=$coldLarge"
);

$flush();
$primedSmall = harness_query_count(static function () use ($model, $loop, $smallSet) {
    $model->primeResourcesCache($smallSet);
    $loop($smallSet);
});
$flush();
$primedLarge = harness_query_count(static function () use ($model, $loop, $largeSet) {
    $model->primeResourcesCache($largeSet);
    $loop($largeSet);
});
pin('priming first collapses the 2-item loop to a single query', 1, $primedSmall);
pin('priming first collapses the 8-item loop to a single query', 1, $primedLarge);
harness_assert_no_n_plus_1(
    'prime + read is flat in the item count',
    static function (int $n) use ($model, $loop, $makeItems, $flush) {
        $ids = $makeItems($n, "flat$n");
        $flush();
        $model->primeResourcesCache($ids);
        $loop($ids);
    },
    2,
    8
);

$flush();

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/itemresource.php */
