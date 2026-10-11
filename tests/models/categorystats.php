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
 * Pins for the CategoryStats model.
 *
 * The live listing count of a category is the i_num_items column on t_category
 * itself; there is no separate stats table. Established by running the code:
 *
 *  - increaseNumItems() adds 1 to the category and every ancestor in ONE UPDATE.
 *    It returns bool true for a top category and int (levels counted) for a
 *    subcategory, false for a non-numeric id or a database error. It never
 *    creates a row, so an id that is not a category is a silent no-op that still
 *    reports true.
 *  - decreaseNumItems() runs one UPDATE per level (the category, then each
 *    ancestor), never below zero, and returns the total rows changed as an int
 *    (0 when every level was already at 0). null or a non-numeric id returns
 *    false without a query.
 *  - setNumItems() is one UPDATE; it sets, it does not add.
 *  - findByCategoryId() keeps the old stats-row shape (fk_i_category_id and
 *    i_num_items, both strings) or false when there is no such category.
 *  - countItemsFromCategory() returns the count as a string, or int 0.
 *  - toNumItemsMap() reads pk_i_id and i_num_items from t_category. It returns an
 *    empty array only when t_category has no rows; a category with no listings
 *    reads back as "0".
 *  - getNumItems() resolves both a root and a subcategory. The subcategories
 *    map is keyed by ROOT id, each root holding a map of its own children by id.
 *  - getNumItems()'s cache is a function-static local (`static $numItemsMap
 *    = null;`), NOT a class property, so ReflectionProperty cannot reset it.
 *    The cold/warm pair below is exercised exactly once, deliberately placed
 *    last, using its own dedicated fixture untouched by anything earlier.
 *
 * This model reaches Category::findByPrimaryKey()/findRootCategories()/
 * findSubcategories(), which in turn reach osc_cache_get()/osc_cache_set()
 * and osc_current_user_locale(). The cache helpers are real (hCache.php
 * below) because Category's own tree cache is part of what makes the query
 * costs pinned here deterministic; osc_base_url() and osc_plugins_path() are
 * stood in for, following the same reasoning tests/models/itemresource.php
 * already established: hDefines.php cannot be required here (it declares
 * osc_uploads_path() with no function_exists guard, and the shared
 * scratch-db bootstrap has always defined a stand-in for it before any model
 * test file loads), so both stand-ins are local, guarded, and return exactly
 * what the real helpers return in a test process (no filters, no plugins).
 *
 * Usage:  php tests/models/categorystats.php          (standalone, own scratch database)
 *         php tests/run-models.php categorystats      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_categorystats');

if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
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

$table = DB_TABLE_PREFIX . 't_category';

/**
 * Count of one category in the old stats-row shape, read with raw UNPREPARED
 * mysqli (a prepared readback would come back natively typed and disagree with
 * the all-string row this is meant to check against).
 */
$rowFor = static function (int $categoryId) use ($admin, $table): ?array {
    $row = $admin->query("SELECT pk_i_id AS fk_i_category_id, i_num_items FROM $table WHERE pk_i_id = $categoryId")->fetch_assoc();

    return $row ?: null;
};

/** Sum of every category's count: 0 means nothing was written anywhere. */
$rowCount = static function () use ($admin, $table): int {
    return (int)$admin->query("SELECT COALESCE(SUM(i_num_items), 0) c FROM $table")->fetch_assoc()['c'];
};

$truncateStats = static function () use ($admin, $table): void {
    $admin->query("UPDATE $table SET i_num_items = 0");
};

$setCount = static function (int $categoryId, int $n) use ($admin, $table): void {
    $admin->query("UPDATE $table SET i_num_items = $n WHERE pk_i_id = $categoryId");
};

/* ----------------------------------------------------------------------------
 * Fixtures. Every category this file will ever touch is seeded UP FRONT and
 * BEFORE the first Category::getInstance(), so Category's one-time tree
 * snapshot (built lazily in its constructor) captures all of them. That makes
 * every findByPrimaryKey() lookup below a tree hit costing zero extra
 * queries, which is what isolates the query-cost pins to CategoryStats' own
 * SQL rather than Category's construction cost.
 * ------------------------------------------------------------------------- */
$incRoot = seed_category($admin, 'IncRoot');
$incMid  = seed_category($admin, 'IncMid', $incRoot);
$incLeaf = seed_category($admin, 'IncLeaf', $incMid);

$decRoot = seed_category($admin, 'DecRoot');
$decMid  = seed_category($admin, 'DecMid', $decRoot);
$decLeaf = seed_category($admin, 'DecLeaf', $decMid);

$plainCat  = seed_category($admin, 'PlainCat');
$noStatCat = seed_category($admin, 'NoStatCat');

$mapRoot = seed_category($admin, 'MapRoot');
$mapSub  = seed_category($admin, 'MapSub', $mapRoot);

$cacheRoot = seed_category($admin, 'CacheRoot');
$cacheSub  = seed_category($admin, 'CacheSub', $cacheRoot);

Preference::getInstance(); // osc_current_user_locale() reads a preference; warm it so it is never attributed to a query-count pin
Category::getInstance();   // one-time tree snapshot over every category seeded above

$model = CategoryStats::getInstance();

/* ----------------------------------------------------------------------------
 * Surface (C2).
 * ------------------------------------------------------------------------- */
harness_section('CategoryStats: public surface');

pin(
    'increaseNumItems signature is unchanged',
    'public increaseNumItems($categoryId)',
    harness_method_signature('CategoryStats', 'increaseNumItems')
);
pin(
    'decreaseNumItems signature is unchanged',
    'public decreaseNumItems($categoryId)',
    harness_method_signature('CategoryStats', 'decreaseNumItems')
);
pin(
    'setNumItems signature is unchanged',
    'public setNumItems($categoryID, $numItems)',
    harness_method_signature('CategoryStats', 'setNumItems')
);
pin(
    'findByCategoryId signature is unchanged',
    'public findByCategoryId($categoryId)',
    harness_method_signature('CategoryStats', 'findByCategoryId')
);
pin(
    'countItemsFromCategory signature is unchanged',
    'public countItemsFromCategory($categoryId)',
    harness_method_signature('CategoryStats', 'countItemsFromCategory')
);
pin('getNumItems signature is unchanged', 'public getNumItems($cat)', harness_method_signature('CategoryStats', 'getNumItems'));
pin('toNumItemsMap signature is unchanged', 'public toNumItemsMap()', harness_method_signature('CategoryStats', 'toNumItemsMap'));
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('CategoryStats', 'newInstance')
);
check('CategoryStats still extends DAO', is_subclass_of('CategoryStats', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('the DAO names the old stats table, so its inherited methods have no table to act on', DB_TABLE_PREFIX . 't_category_stats', $model->getTableName());
pin('primary key', 'fk_i_category_id', $model->getPrimaryKey());
pin('field allowlist', array('fk_i_category_id', 'i_num_items'), $model->getFields());
$quietDelete = @$model->deleteByPrimaryKey($incRoot);
pin('an inherited deleteByPrimaryKey() cannot delete the real row', array(false, 1), array($quietDelete, (int) $admin->query("SELECT COUNT(*) FROM $table WHERE pk_i_id = '" . $incRoot . "'")->fetch_row()[0]));
pin(
    'the model declares exactly these seven methods of its own',
    array(
        '__construct',
        'countItemsFromCategory',
        'decreaseNumItems',
        'findByCategoryId',
        'getNumItems',
        'increaseNumItems',
        'newInstance',
        'setNumItems',
        'toNumItemsMap',
    ),
    array_values(array_intersect(
        array_keys(harness_public_method_map('CategoryStats')),
        array(
            '__construct',
            'countItemsFromCategory',
            'decreaseNumItems',
            'findByCategoryId',
            'getNumItems',
            'increaseNumItems',
            'newInstance',
            'setNumItems',
            'toNumItemsMap',
        )
    ))
);

$truncateStats();

/* ----------------------------------------------------------------------------
 * increaseNumItems() — rejected input, before any query is issued.
 * ------------------------------------------------------------------------- */
harness_section('increaseNumItems — rejected input');

pin('a non-numeric category id is rejected', false, $model->increaseNumItems('abc'));
pin('a null category id is rejected', false, $model->increaseNumItems(null));
pin('the empty string is rejected', false, $model->increaseNumItems(''));
pin('nothing was written by any rejected input', 0, $rowCount());
pin('a rejected category id costs no queries at all', 0, harness_query_count(static function () use ($model) {
    $model->increaseNumItems('not-numeric');
}));

/* ----------------------------------------------------------------------------
 * increaseNumItems() — a well-formed, numeric id that does not reference a
 * real category. The UPDATE matches no row and never creates one, so nothing
 * is written. The Category::findByPrimaryKey() lookup runs first, so this
 * costs TWO queries.
 * ------------------------------------------------------------------------- */
harness_section('increaseNumItems — well-formed, unknown category id');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->increaseNumItems(999999);
error_reporting($prevLevel);

pin('an unknown category id is refused with bool false (no row is created)', false, $ret);
pin('nothing was written for it', 0, $rowCount());
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$cost = harness_query_count(static function () use ($model) {
    $model->increaseNumItems(999999);
});
error_reporting($prevLevel);
pin('the Category lookup PLUS the no-match update costs two queries', 2, $cost);

/* ----------------------------------------------------------------------------
 * increaseNumItems() — a root category (no parent): the recursion never
 * enters its add branch, so the return stays the bare bool the write gave it.
 * ------------------------------------------------------------------------- */
harness_section('increaseNumItems — a root category, no parent');

pin('a root category returns bool true, not an int', true, $model->increaseNumItems($decRoot));
pin('its count went from 0 to 1', '1', $rowFor($decRoot)['i_num_items']);

$truncateStats();

/* ----------------------------------------------------------------------------
 * increaseNumItems() — the recursive chain. Each level that ran a write
 * contributes 1; PHP's += folds bool true into an int the moment a second
 * level is added, so only a childless call stays a bare bool.
 * ------------------------------------------------------------------------- */
harness_section('increaseNumItems — a three-level chain, first call');

pin('the leaf-of-three call returns int 3 (one per level)', 3, $model->increaseNumItems($incLeaf));
pin('the leaf got its own +1', '1', $rowFor($incLeaf)['i_num_items']);
pin('the mid ancestor got +1 too', '1', $rowFor($incMid)['i_num_items']);
pin('the root ancestor got +1 too', '1', $rowFor($incRoot)['i_num_items']);
pin('exactly three counts were added (no other category moved)', 3, $rowCount());

harness_section('increaseNumItems — the same leaf again, accumulates');

pin('a repeat call also returns int 3', 3, $model->increaseNumItems($incLeaf));
pin('the leaf is now at 2', '2', $rowFor($incLeaf)['i_num_items']);
pin('the mid ancestor is now at 2', '2', $rowFor($incMid)['i_num_items']);
pin('the root ancestor is now at 2', '2', $rowFor($incRoot)['i_num_items']);

harness_section('increaseNumItems — calling the middle level directly');

pin('a two-level call (mid, root) returns int 2', 2, $model->increaseNumItems($incMid));
pin('mid is now at 3', '3', $rowFor($incMid)['i_num_items']);
pin('root is now at 3', '3', $rowFor($incRoot)['i_num_items']);
pin('the leaf is untouched by a call that never reaches it', '2', $rowFor($incLeaf)['i_num_items']);

harness_section('increaseNumItems — query cost of a three-level chain');

pin('a three-level increase costs one query (one update for every level)', 1, harness_query_count(
    static function () use ($model, $incLeaf) {
        $model->increaseNumItems($incLeaf);
    }
));

harness_section('increaseNumItems — a non-integer numeric string on a real category');

/*
 * The id is cast with (int) before it is bound, so "$plainCat.7" truncates to
 * the real category's integer id.
 */
pin(
    'a fractional-but-numeric string referencing a real category still succeeds',
    true,
    $model->increaseNumItems($plainCat . '.7')
);
pin('it wrote to the truncated integer id', '1', $rowFor($plainCat)['i_num_items']);

$truncateStats();

/* ----------------------------------------------------------------------------
 * decreaseNumItems() — null and non-numeric ids are rejected before any query.
 * ------------------------------------------------------------------------- */
harness_section('decreaseNumItems — null category id');

pin('a null category id returns bool false', false, $model->decreaseNumItems(null));
pin('a null category id costs no queries', 0, harness_query_count(static function () use ($model) {
    $model->decreaseNumItems(null);
}));

/* ----------------------------------------------------------------------------
 * decreaseNumItems() — a well-formed, numeric id that is not a category. The
 * UPDATE matches nothing, so the total is int 0 and nothing is created.
 * ------------------------------------------------------------------------- */
harness_section('decreaseNumItems — well-formed, unknown category id');

pin('an unknown category id changes no rows: int 0', 0, $model->decreaseNumItems(999999));
pin('nothing was written for it', 0, $rowCount());
check('no row was created for it', $rowFor(999999) === null);

pin('the Category lookup plus one update costs exactly two queries', 2, harness_query_count(static function () use ($model) {
    $model->decreaseNumItems(999999);
}));

/* ----------------------------------------------------------------------------
 * decreaseNumItems() — a real category already at 0: nothing to take, so the
 * total is int 0 and the count stays 0.
 * ------------------------------------------------------------------------- */
harness_section('decreaseNumItems — a real category already at zero');

pin('decreasing a category at 0 returns int 0', 0, $model->decreaseNumItems($noStatCat));
pin('it is still 0, not negative', '0', $rowFor($noStatCat)['i_num_items']);

$truncateStats();

/* ----------------------------------------------------------------------------
 * decreaseNumItems() — the recursive chain and the floor at zero.
 * ------------------------------------------------------------------------- */
harness_section('decreaseNumItems — a three-level chain, decrementing an existing count');

$setCount($decLeaf, 3);
$setCount($decMid, 3);
$setCount($decRoot, 3);

pin('the leaf-of-three call returns int 3 (one affected row per level)', 3, $model->decreaseNumItems($decLeaf));
pin('the leaf is now at 2', '2', $rowFor($decLeaf)['i_num_items']);
pin('the mid ancestor is now at 2', '2', $rowFor($decMid)['i_num_items']);
pin('the root ancestor is now at 2', '2', $rowFor($decRoot)['i_num_items']);

harness_section('decreaseNumItems — the floor: a level at zero is skipped, the chain still climbs');

$setCount($decLeaf, 0);
/*
 * The leaf's own UPDATE matches zero rows (it is already 0), so it adds 0 to
 * the total, but the ancestors (both at 2) still drop by 1 each: 0 + 1 + 1 = 2.
 */
pin('the floored level adds nothing; its ancestors still count', 2, $model->decreaseNumItems($decLeaf));
pin('the row is still 0, not negative', '0', $rowFor($decLeaf)['i_num_items']);
pin(
    'the recursion still climbed: mid dropped even though the leaf was at the floor',
    '1',
    $rowFor($decMid)['i_num_items']
);

harness_section('decreaseNumItems — query cost of a three-level chain');

$setCount($decLeaf, 5);
$setCount($decMid, 5);
$setCount($decRoot, 5);
pin('a three-level decrease costs exactly three queries (one update per level)', 3, harness_query_count(
    static function () use ($model, $decLeaf) {
        $model->decreaseNumItems($decLeaf);
    }
));

harness_section('decreaseNumItems — a non-numeric category id');

pin('a non-numeric category id returns bool false', false, $model->decreaseNumItems('abc'));
pin('a non-numeric category id costs no queries', 0, harness_query_count(static function () use ($model) {
    $model->decreaseNumItems('abc');
}));

$truncateStats();

/* ----------------------------------------------------------------------------
 * setNumItems() — no callers exist anywhere in this codebase (grepped), but
 * it is still public surface. Its own docblock ("@return bool|DBRecordsetClass")
 * is wrong: it is a write-type raw query, so dao->query() only ever returns
 * bool, never a DBRecordsetClass.
 * ------------------------------------------------------------------------- */
harness_section('setNumItems — a first write, then an overwrite');

pin('a write returns bool true', true, $model->setNumItems($plainCat, 5));
pin('the row landed at 5', '5', $rowFor($plainCat)['i_num_items']);

pin('calling it again overwrites, still bool true', true, $model->setNumItems($plainCat, 9));
pin('the row is now 9, not 14 -- this SETS, it does not add', '9', $rowFor($plainCat)['i_num_items']);
pin('no other category moved', 9, $rowCount());

harness_section('setNumItems — both arguments are cast through (int)');

pin('a numeric string category id still works', true, $model->setNumItems((string)$plainCat, '7'));
pin('the string amount landed as the integer 7', '7', $rowFor($plainCat)['i_num_items']);

harness_section('setNumItems — an unknown category id');

pin('an unknown category id changes nothing and still returns bool true', true, $model->setNumItems(999999, 3));
check('no row was created for it', $rowFor(999999) === null);
pin('no category moved besides plainCat', 7, $rowCount());

$truncateStats();

/* ----------------------------------------------------------------------------
 * countItemsFromCategory() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('countItemsFromCategory — a match');

$setCount($plainCat, 42);
pin('a matching row returns the string "42", not an int', '42', $model->countItemsFromCategory($plainCat));

harness_section('countItemsFromCategory — a real category with no listings');

pin('a category at 0 returns the string "0"', '0', $model->countItemsFromCategory($noStatCat));

harness_section('countItemsFromCategory — an unknown category id');

pin('an unknown category id returns int 0 (no error)', 0, $model->countItemsFromCategory(999999));

harness_section('countItemsFromCategory — a null category id ');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->countItemsFromCategory(null);
error_reporting($prevLevel);
pin('a null category id returns int 0', 0, $ret);

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$nullCountCost = harness_query_count(static function () use ($model) {
    $model->countItemsFromCategory(null);
});
error_reporting($prevLevel);
pin('a null category id costs exactly the same one query a real lookup does', 1, $nullCountCost);

harness_section('countItemsFromCategory — query cost of a real match');

pin('a matching lookup costs exactly one query', 1, harness_query_count(static function () use ($model, $plainCat) {
    $model->countItemsFromCategory($plainCat);
}));

/* ----------------------------------------------------------------------------
 * findByCategoryId() — the count in the old stats-row shape.
 * ------------------------------------------------------------------------- */
harness_section('findByCategoryId — the old stats-row shape, read from t_category');

$row = $model->findByCategoryId($plainCat);
check('a match returns an array', is_array($row), describe($row));
pin('the row carries exactly the two old stats columns', array('fk_i_category_id', 'i_num_items'), array_keys($row));
pin('fk_i_category_id round-trips as a string (C4)', (string)$plainCat, $row['fk_i_category_id']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));
pin('an unknown category id returns bool false', false, $model->findByCategoryId(999999));

$truncateStats();

/* ----------------------------------------------------------------------------
 * toNumItemsMap() — reads pk_i_id and i_num_items from t_category, then
 * composes with Category. A category with no listings reads back as "0",
 * for roots and subcategories alike.
 * ------------------------------------------------------------------------- */
harness_section('toNumItemsMap — a root with a real count, a subcategory with none');

$setCount($mapRoot, 5);

$map = $model->toNumItemsMap();

check('the map has a parent entry and a subcategories entry', isset($map['parent'], $map['subcategories']), describe($map));
pin('the root numItems is the string "5"', '5', $map['parent'][$mapRoot]['numItems']);
pin('the root s_name round-trips', 'MapRoot', $map['parent'][$mapRoot]['s_name']);
pin(
    "the subcategories entry is keyed by the ROOT's id, not the subcategory's",
    true,
    isset($map['subcategories'][$mapRoot]) && !isset($map['subcategories'][$mapSub])
);
pin('a subcategory with no listings reads back as the string "0"', '0', $map['subcategories'][$mapRoot][$mapSub]['numItems']);
pin('a root with no listings reads back as the string "0"', '0', $map['parent'][$plainCat]['numItems']);
pin('the subcategory s_name still round-trips', 'MapSub', $map['subcategories'][$mapRoot][$mapSub]['s_name']);

$truncateStats();

/* ----------------------------------------------------------------------------
 * getNumItems() — the C9 static cache. `static $numItemsMap` inside the
 * method is a function-static, not a class property, so it cannot be reset
 * with ReflectionProperty the way Currency's cache is: PHP's reflection API
 * exposes function-static variables for reading only. This is therefore the
 * ONE cold observation this process gets; everything above deliberately
 * never called getNumItems(), so the cache is still unbuilt here.
 * ------------------------------------------------------------------------- */
harness_section('getNumItems — the C9 static cache (one-shot: cannot be reset and re-observed)');

$setCount($cacheRoot, 7);
$setCount($cacheSub, 4);

$rootCat = Category::getInstance()->findByPrimaryKey($cacheRoot);
$subCat  = Category::getInstance()->findByPrimaryKey($cacheSub);

/*
 * toNumItemsMap()'s cost is 1 (t_category read) + 1 (findRootCategories) + one
 * findSubcategories() call PER ROOT -- and every category fixture seeded
 * earlier in this file with no parent (incRoot, decRoot, plainCat,
 * noStatCat, mapRoot, cacheRoot: six) is itself a root as far as
 * findRootCategories() is concerned, whether or not it has children. Six
 * roots -> 1 + 1 + 6 = 8. This number moves if fixtures earlier in this
 * file change; it is not a general property of the method.
 */
$coldCost = harness_query_count(static function () use ($model, $rootCat) {
    $model->getNumItems($rootCat);
});
pin('the first-ever call in this process (cold) costs 1 + 1 + one-per-root queries (8, with the six roots this file seeded)', 8, $coldCost);
pin('a root category returns its string count from the parent branch', '7', $model->getNumItems($rootCat));

$warmCost = harness_query_count(static function () use ($model, $rootCat) {
    $model->getNumItems($rootCat);
});
pin('a repeat call is served from the static map at zero queries', 0, $warmCost);

pin(
    'a subcategory returns its own string count, found by searching the per-root child maps',
    '4',
    $model->getNumItems($subCat)
);

harness_section('getNumItems — a write does not invalidate the static map');

$model->increaseNumItems($cacheRoot);
$freshRow = $rowFor($cacheRoot);
pin("the underlying count really did change to \"8\"", '8', $freshRow['i_num_items']);
pin(
    'getNumItems still reports the stale "7" -- the map was built once and is never rebuilt for the rest of the process',
    '7',
    $model->getNumItems($rootCat)
);

harness_section('Utils recount writes — bound statements');

$truncateStats();
\mindstellar\utility\Utils::updateCategoryStatsById($incLeaf);
pin('a leaf recount of an empty leaf leaves it at 0', '0', $rowFor($incLeaf)['i_num_items']);
pin('... and its parents at 0', '0', $rowFor($incRoot)['i_num_items']);
$setCount($incLeaf, 4);
$setCount($incRoot, 4);
\mindstellar\utility\Utils::updateAllCategoriesStats();
pin('a full recount corrects stale counts (no listings -> 0 everywhere)', 0, $rowCount());

harness_section('Utils recount by id — counts every level below');

$deepRoot = seed_category($admin, 'Deep root');
$deepMid  = seed_category($admin, 'Deep mid', $deepRoot);
$deepLeaf = seed_category($admin, 'Deep leaf', $deepMid);
seed_item($admin, $deepLeaf, null, 'Deep listing');
seed_item($admin, $deepMid, null, 'Mid listing');
$truncateStats();
\mindstellar\utility\Utils::updateCategoryStatsById($deepLeaf);
pin('the leaf counts its own listing', '1', $rowFor($deepLeaf)['i_num_items']);
pin('the middle counts its own and the leaf listing', '2', $rowFor($deepMid)['i_num_items']);
pin('the root counts its grandchild listing too', '2', $rowFor($deepRoot)['i_num_items']);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/categorystats.php */
