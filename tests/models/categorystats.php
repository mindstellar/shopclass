<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Characterization pins for the CategoryStats model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * increaseNumItems()/decreaseNumItems()/setNumItems()/countItemsFromCategory()
 * move to the parameterized query layer. Everything below was established by
 * running the code, never by reading a method name, docblock or comment. The
 * quirks that surprised, all reproduced rather than fixed:
 *
 *  - increaseNumItems() and decreaseNumItems() recurse up the parent chain.
 *    Each level that actually ran a write contributes bool true; PHP's `+=`
 *    then folds that into an int as soon as a second level adds to it, so a
 *    root-only call returns bool true, but a call with n ancestors returns
 *    int (n + 1) on success — the "number of affected rows" the docblock
 *    claims is really a level count, not a row count.
 *  - increaseNumItems() calls Category::findByPrimaryKey($categoryId)
 *    UNCONDITIONALLY, even when its own write already failed — the `&&`
 *    short-circuit only skips the recursive *add*, not the lookup. A
 *    foreign-key rejection therefore still costs a second query.
 *  - increaseNumItems() truncates its category id through a raw `sprintf('%d',
 *    ...)` before ever building SQL, so a non-integer numeric string
 *    ("1.7") referencing a real category succeeds. Binding that same string
 *    straight into a prepared statement does NOT reproduce this: MySQL's
 *    foreign-key check on an InnoDB child table does not apply the same
 *    fractional-truncation coercion a plain value assignment gets, so an
 *    uncast bound "1.7" fails FK 1452 where legacy's already-int SQL text
 *    succeeds. The conversion has to truncate with (int) before binding,
 *    exactly mirroring the sprintf, or this is a silent regression.
 *  - decreaseNumItems() has no such cast anywhere and passes $categoryId
 *    through as-is on every branch. Its own literal-SQL insert branch hits
 *    the identical FK/fractional quirk on a non-integer numeric string with
 *    no existing stats row, so legacy ITSELF returns false there — the two
 *    stacks converge without any special-casing.
 *  - decreaseNumItems(null) fails at the SELECT (a bare "col =" with no
 *    right-hand side, per the null-where correction) and returns false
 *    after exactly one query, without ever reaching the Category lookup. A
 *    bound null is valid SQL that matches no rows, so a naive conversion
 *    still converges on false eventually (through the insert branch's own
 *    NOT-NULL-constraint failure) but at the cost of a second, wasted query
 *    — the conversion needs an explicit null guard to keep the cost, not
 *    just the return value, unchanged.
 *  - countItemsFromCategory(null) converges by itself: the malformed WHERE
 *    fails at exactly the same one-query cost a bound null matching zero
 *    rows would, so no special-casing is needed there.
 *  - findByCategoryId() is pure delegation to the inherited (base DAO)
 *    findByPrimaryKey() — no query of its own, out of scope, pinned only as
 *    a regression guard.
 *  - toNumItemsMap() has no query of its own either: it is pure composition
 *    of the inherited listAll() plus calls into Category. It short-circuits
 *    to an empty array the instant t_category_stats has zero rows at all,
 *    regardless of how many categories exist. When it does run, a root
 *    category's missing count is read with an `@`-suppressed lookup (silent
 *    null); the identical situation for a SUBcategory has no `@` and raises
 *    a genuine "Undefined array key" warning while still yielding null — an
 *    asymmetry between the two branches, not a difference in outcome.
 *  - getNumItems()'s own "subcategories" branch is dead code: it is keyed by
 *    ROOT id (mirroring how toNumItemsMap() built it), so a real
 *    subcategory's id can never match it — pk_i_id is a single sequence
 *    shared by every category row, so a subcategory id can never collide
 *    with a root's id either. getNumItems() therefore returns 0 for every
 *    subcategory, unconditionally, and only ever returns a real count for a
 *    ROOT category. There is exactly one (commented-out, inactive) caller
 *    of getNumItems() anywhere in this codebase, so this has never been
 *    observed in production.
 *  - getNumItems()'s cache is a function-static local (`static $numItemsMap
 *    = null;`), NOT a class property — `ReflectionProperty` (the pattern
 *    tests/models/currency.php uses to reset Currency's cache) cannot see
 *    or reset it; PHP's reflection API only exposes function-static values
 *    for reading (`ReflectionMethod::getStaticVariables()`), never for
 *    writing. There is no way to force it cold a second time within one
 *    process. The cold/warm pair below is therefore exercised exactly once,
 *    deliberately placed last, using its own dedicated fixture untouched by
 *    anything earlier in this file.
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

$table = DB_TABLE_PREFIX . 't_category_stats';

/**
 * Row for one category in t_category_stats, read with raw UNPREPARED mysqli
 * (a prepared readback would come back natively typed and disagree with the
 * all-string legacy row this is meant to check against).
 */
$rowFor = static function (int $categoryId) use ($admin, $table): ?array {
    $row = $admin->query("SELECT * FROM $table WHERE fk_i_category_id = $categoryId")->fetch_assoc();

    return $row ?: null;
};

$rowCount = static function () use ($admin, $table): int {
    return (int)$admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

$truncateStats = static function () use ($admin, $table): void {
    $admin->query("TRUNCATE TABLE $table");
};

/* ----------------------------------------------------------------------------
 * Fixtures. Every category this file will ever touch is seeded UP FRONT and
 * BEFORE the first Category::newInstance(), so Category's one-time tree
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

Preference::newInstance(); // osc_current_user_locale() reads a preference; warm it so it is never attributed to a query-count pin
Category::newInstance();   // one-time tree snapshot over every category seeded above

$model = CategoryStats::newInstance();

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
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'fk_i_category_id', $model->getPrimaryKey());
pin('field allowlist is unchanged', array('fk_i_category_id', 'i_num_items'), $model->getFields());
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
 * real category. t_category_stats carries a FOREIGN KEY on fk_i_category_id,
 * so the INSERT ... ON DUPLICATE KEY UPDATE fails at the database. The
 * Category::findByPrimaryKey() lookup still runs afterward (the && only
 * short-circuits the recursive add, not the lookup itself), so this costs
 * TWO queries even though it returns false.
 * ------------------------------------------------------------------------- */
harness_section('increaseNumItems — well-formed, unknown category id');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->increaseNumItems(999999);
error_reporting($prevLevel);

pin('an unknown category id returns bool false', false, $ret);
pin('nothing was written for it', 0, $rowCount());
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$cost = harness_query_count(static function () use ($model) {
    $model->increaseNumItems(999999);
});
error_reporting($prevLevel);
pin('the failed insert PLUS the unconditional Category lookup costs two queries', 2, $cost);

/* ----------------------------------------------------------------------------
 * increaseNumItems() — a root category (no parent): the recursion never
 * enters its add branch, so the return stays the bare bool the write gave it.
 * ------------------------------------------------------------------------- */
harness_section('increaseNumItems — a root category, no parent');

pin('a root category returns bool true, not an int', true, $model->increaseNumItems($decRoot));
pin('its row was created at 1', '1', $rowFor($decRoot)['i_num_items']);

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
pin('exactly three rows exist', 3, $rowCount());

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

pin('a three-level increase costs exactly three queries (one insert per level)', 3, harness_query_count(
    static function () use ($model, $incLeaf) {
        $model->increaseNumItems($incLeaf);
    }
));

harness_section('increaseNumItems — a non-integer numeric string on a real category');

/*
 * Legacy builds this INSERT with sprintf('%d', $categoryId), which truncates
 * "$plainCat.7" to a real integer BEFORE the SQL is ever sent, so this
 * succeeds. The converted method has to reproduce that truncation itself:
 * MySQL's foreign-key check on a bound (prepared) value does not coerce a
 * fractional string the way a plain value assignment does, so an uncast bind
 * of "$plainCat.7" fails FK 1452 where the legacy SQL text does not.
 */
pin(
    'a fractional-but-numeric string referencing a real category still succeeds',
    true,
    $model->increaseNumItems($plainCat . '.7')
);
pin('it wrote to the truncated integer id, not a new row', '1', $rowFor($plainCat)['i_num_items']);

$truncateStats();

/* ----------------------------------------------------------------------------
 * decreaseNumItems() — the null-where divergence. The malformed WHERE fails
 * in exactly one query; a naive bound-null conversion would instead run a
 * valid zero-row SELECT and then attempt (and fail) an INSERT with a NULL
 * foreign key, reaching the same false two queries later. That extra query
 * is the thing an explicit null guard has to remove.
 * ------------------------------------------------------------------------- */
harness_section('decreaseNumItems — null category id');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->decreaseNumItems(null);
error_reporting($prevLevel);
pin('a null category id returns bool false', false, $ret);

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$nullCost = harness_query_count(static function () use ($model) {
    $model->decreaseNumItems(null);
});
error_reporting($prevLevel);
check('a null category id costs no more than the legacy one failed query (' . $nullCost . ')', $nullCost <= 1);

/* ----------------------------------------------------------------------------
 * decreaseNumItems() — a well-formed, numeric id that does not reference a
 * real category. The SELECT matches zero rows cleanly (no error yet); the
 * fallback INSERT is what fails the foreign key. Because $return is false
 * only from that point, the Category lookup this time is never reached.
 * ------------------------------------------------------------------------- */
harness_section('decreaseNumItems — well-formed, unknown category id');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->decreaseNumItems(999999);
error_reporting($prevLevel);
pin('an unknown category id returns bool false', false, $ret);
pin('nothing was written for it', 0, $rowCount());

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$cost = harness_query_count(static function () use ($model) {
    $model->decreaseNumItems(999999);
});
error_reporting($prevLevel);
pin('the select plus the failed fallback insert costs exactly two queries', 2, $cost);

/* ----------------------------------------------------------------------------
 * decreaseNumItems() — a real category with no existing stats row: the
 * fallback insert succeeds, but $return stays its initial 0 (NOT the
 * insert's own return value) -- decreasing a category that never had a row
 * is reported as "0 decreased", never as a fresh count.
 * ------------------------------------------------------------------------- */
harness_section('decreaseNumItems — a real category, no existing row');

pin('a fresh insert-branch call returns int 0, not true/1', 0, $model->decreaseNumItems($noStatCat));
pin('the row now exists at 0', '0', $rowFor($noStatCat)['i_num_items']);

$truncateStats();

/* ----------------------------------------------------------------------------
 * decreaseNumItems() — the recursive chain and the floor at zero.
 * ------------------------------------------------------------------------- */
harness_section('decreaseNumItems — a three-level chain, decrementing an existing count');

$admin->query("INSERT INTO $table (fk_i_category_id, i_num_items) VALUES ($decLeaf, 3)");
$admin->query("INSERT INTO $table (fk_i_category_id, i_num_items) VALUES ($decMid, 3)");
$admin->query("INSERT INTO $table (fk_i_category_id, i_num_items) VALUES ($decRoot, 3)");

pin('the leaf-of-three call returns int 3 (one affected row per level)', 3, $model->decreaseNumItems($decLeaf));
pin('the leaf is now at 2', '2', $rowFor($decLeaf)['i_num_items']);
pin('the mid ancestor is now at 2', '2', $rowFor($decMid)['i_num_items']);
pin('the root ancestor is now at 2', '2', $rowFor($decRoot)['i_num_items']);

harness_section('decreaseNumItems — the floor: WHERE i_num_items > 0 stops it at zero');

$admin->query("UPDATE $table SET i_num_items = 0 WHERE fk_i_category_id = $decLeaf");
/*
 * The leaf's own UPDATE matches zero rows (i_num_items > 0 excludes it), so
 * its own contribution is int 0 -- but 0 !== false, so the recursion still
 * climbs and adds whatever the ancestors' own decrements return (1 each,
 * both still at 2). 0 + 1 + 1 = 2: the floor stops that ONE level from
 * decrementing further, it does not stop the chain from continuing to climb.
 */
pin('the floored level still returns the sum of what its ancestors decremented', 2, $model->decreaseNumItems($decLeaf));
pin('the row is still 0, not negative', '0', $rowFor($decLeaf)['i_num_items']);
pin(
    'the recursion still climbed: mid dropped even though the leaf floor matched nothing',
    '1',
    $rowFor($decMid)['i_num_items']
);

harness_section('decreaseNumItems — query cost of a three-level chain');

$admin->query("UPDATE $table SET i_num_items = 5 WHERE fk_i_category_id = $decLeaf");
$admin->query("UPDATE $table SET i_num_items = 5 WHERE fk_i_category_id = $decMid");
$admin->query("UPDATE $table SET i_num_items = 5 WHERE fk_i_category_id = $decRoot");
pin('a three-level decrease costs exactly six queries (select + update per level)', 6, harness_query_count(
    static function () use ($model, $decLeaf) {
        $model->decreaseNumItems($decLeaf);
    }
));

harness_section('decreaseNumItems — a non-numeric category id, no existing row');

/*
 * decreaseNumItems() never validates $categoryId at all -- unlike
 * increaseNumItems(), there is no is_numeric() guard anywhere. The SELECT's
 * implicit comparison against a non-numeric string matches zero rows (no
 * error), so it falls to the insert branch, which then fails the foreign
 * key (a non-numeric id cannot reference any real category).
 */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->decreaseNumItems('abc');
error_reporting($prevLevel);
pin('a non-numeric category id with no existing row returns bool false', false, $ret);
check('no row exists for a non-existent numeric key', $rowFor(999999) === null);

$truncateStats();

/* ----------------------------------------------------------------------------
 * setNumItems() — no callers exist anywhere in this codebase (grepped), but
 * it is still public surface. Its own docblock ("@return bool|DBRecordsetClass")
 * is wrong: it is a write-type raw query, so dao->query() only ever returns
 * bool, never a DBRecordsetClass.
 * ------------------------------------------------------------------------- */
harness_section('setNumItems — a fresh row, then an overwrite');

pin('a fresh insert returns bool true', true, $model->setNumItems($plainCat, 5));
pin('the row landed at 5', '5', $rowFor($plainCat)['i_num_items']);

pin('calling it again overwrites (ON DUPLICATE KEY UPDATE), still bool true', true, $model->setNumItems($plainCat, 9));
pin('the row is now 9, not 14 -- this SETS, it does not add', '9', $rowFor($plainCat)['i_num_items']);
pin('still exactly one row for this category', 1, (int)$admin->query(
    "SELECT COUNT(*) c FROM $table WHERE fk_i_category_id = $plainCat"
)->fetch_assoc()['c']);

harness_section('setNumItems — both arguments are cast through (int)');

pin('a numeric string category id still works', true, $model->setNumItems((string)$plainCat, '7'));
pin('the string amount landed as the integer 7', '7', $rowFor($plainCat)['i_num_items']);

harness_section('setNumItems — an unknown category id (foreign key rejection)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$ret       = $model->setNumItems(999999, 3);
error_reporting($prevLevel);
pin('an unknown category id returns bool false', false, $ret);

$truncateStats();

/* ----------------------------------------------------------------------------
 * countItemsFromCategory() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('countItemsFromCategory — a match');

$admin->query("INSERT INTO $table (fk_i_category_id, i_num_items) VALUES ($plainCat, 42)");
pin('a matching row returns the string "42", not an int', '42', $model->countItemsFromCategory($plainCat));

harness_section('countItemsFromCategory — a real category with no stats row');

pin('no matching row returns int 0', 0, $model->countItemsFromCategory($noStatCat));

harness_section('countItemsFromCategory — an unknown category id');

pin('an unknown category id also returns int 0 (no error)', 0, $model->countItemsFromCategory(999999));

harness_section('countItemsFromCategory — a null category id (converges by itself)');

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
 * findByCategoryId() — pure delegation to the inherited findByPrimaryKey(),
 * no query of its own. Pinned as a regression guard only.
 * ------------------------------------------------------------------------- */
harness_section('findByCategoryId — delegates to the inherited findByPrimaryKey (not converted)');

$row = $model->findByCategoryId($plainCat);
check('a match returns an array', is_array($row), describe($row));
pin('the row carries exactly the two schema columns', array('fk_i_category_id', 'i_num_items'), array_keys($row));
pin('fk_i_category_id round-trips as a string (C4)', (string)$plainCat, $row['fk_i_category_id']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));
pin('an unknown category id returns bool false', false, $model->findByCategoryId(999999));

$truncateStats();

/* ----------------------------------------------------------------------------
 * toNumItemsMap() — no query of its own (pure composition of the inherited
 * listAll() and calls into Category). Pinned as a regression guard,
 * including the early-exit and the warning asymmetry, both established by
 * running the code.
 * ------------------------------------------------------------------------- */
harness_section('toNumItemsMap — t_category_stats has zero rows anywhere');

pin('an empty stats table short-circuits to an empty array, however many categories exist', array(), $model->toNumItemsMap());

harness_section('toNumItemsMap — a root with a real count, a subcategory with none');

$admin->query("INSERT INTO $table (fk_i_category_id, i_num_items) VALUES ($mapRoot, 5)");

$map = null;
$prevLevel = error_reporting(E_ALL & ~E_WARNING); // the subcategory branch's own missing-@ warning
$map = $model->toNumItemsMap();
error_reporting($prevLevel);

check('the map has a parent entry and a subcategories entry', isset($map['parent'], $map['subcategories']), describe($map));
pin("the root's numItems is the string \"5\" (the \"@\"-suppressed branch)", '5', $map['parent'][$mapRoot]['numItems']);
pin('the root s_name round-trips', 'MapRoot', $map['parent'][$mapRoot]['s_name']);
pin(
    "the subcategories entry is keyed by the ROOT's id, not the subcategory's",
    true,
    isset($map['subcategories'][$mapRoot]) && !isset($map['subcategories'][$mapSub])
);
pin(
    "a subcategory with no stats row reads back as null (the un-suppressed branch), same value as the root's suppressed one would be",
    null,
    $map['subcategories'][$mapRoot][$mapSub]['numItems']
);
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

$admin->query("INSERT INTO $table (fk_i_category_id, i_num_items) VALUES ($cacheRoot, 7)");
$admin->query("INSERT INTO $table (fk_i_category_id, i_num_items) VALUES ($cacheSub, 4)");

$rootCat = Category::newInstance()->findByPrimaryKey($cacheRoot);
$subCat  = Category::newInstance()->findByPrimaryKey($cacheSub);

/*
 * toNumItemsMap()'s cost is 1 (listAll) + 1 (findRootCategories) + one
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
    'a subcategory returns int 0 -- the "subcategories" branch can never match (dead code), pinned as observed, not fixed',
    0,
    $model->getNumItems($subCat)
);

harness_section('getNumItems — a write does not invalidate the static map');

$model->increaseNumItems($cacheRoot);
$freshRow = $rowFor($cacheRoot);
pin("the underlying row really did change to \"8\"", '8', $freshRow['i_num_items']);
pin(
    'getNumItems still reports the stale "7" -- the map was built once and is never rebuilt for the rest of the process',
    '7',
    $model->getNumItems($rootCat)
);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/categorystats.php */
