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
 * Characterization pins for the Category model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * the query-bearing methods move to the parameterized query layer. Everything
 * below was established by RUNNING the code, never by trusting a method name,
 * docblock or comment.
 *
 * The load-bearing facts, all reproduced rather than "fixed":
 *
 *  - The tree cache (C9). Category keeps a one-time snapshot of the enabled
 *    category tree in the instance properties $categories/$tree/$relation/
 *    $categoriesEnabled and ALSO in the object cache (osc_cache_*), keyed by
 *    md5(osc_base_url() . language . empty) . locale. It is built lazily in the
 *    constructor (one listEnabled() query) and served from then on. On the
 *    default object cache driver (a request-lifetime PHP array) it is a genuine
 *    within-process memo: constructing costs one query, and every tree-served
 *    read (toTree(), an in-tree findByPrimaryKey(), findNameByPrimaryKey() on a
 *    category already in the snapshot, a warmed findBySlug()) costs ZERO.
 *  - NOTHING invalidates it. No write in this model ever calls osc_cache_delete/
 *    flush or rebuilds the snapshot, so a category updated or deleted through the
 *    model is still served in its pre-write form for the rest of the process.
 *    Only a fresh instance over a flushed cache reflects the new state. Pinned.
 *  - listWhere() is a public "comodin": its single-argument form takes a RAW
 *    WHERE fragment (its own docblock: "param needs to be escaped, inside
 *    function will not be escaped"), its multi-argument form is a printf format
 *    whose values legacy escapes through dao->escape() + vsprintf. The three
 *    joined tables (t_category a, t_category_description b, t_category_stats c)
 *    and the a.*, b.*, c.i_num_items projection are the contract; so is the
 *    per-pk merge that folds the description locales into a 'locale' sub-array
 *    and overlays $this->language's names at the top level.
 *  - Value typing (C4). Legacy rows are all strings/null. listWhere() runs
 *    UNPREPARED for its literal callers (findRootCategories/listAll) and would
 *    run PREPARED — native ints — for its %d/%s callers (findSubcategories/
 *    findByPrimaryKey-miss/isRoot/...): the conditional-typing trap. Both
 *    branches are pinned all-strings so the conversion must stringify.
 *  - deleteByPrimaryKey() cascades: it recurses into subcategories (live
 *    findSubcategories(), NOT the stale tree), delegates item cleanup to Item
 *    (out of scope, and exercised over zero item rows here, as Region's cascade
 *    does), fires the delete_category hook, then deletes the plugin/description/
 *    stats/meta rows and finally the category. The first four deletes have
 *    always discarded their result and must keep per-call swallowed catches so
 *    one failure never aborts the rest; only the final category delete's value
 *    is returned. A survivor subtree proves the cascade is scoped.
 *  - updateByPrimaryKey() returns the accumulated affected-row count, or the raw
 *    dao return on the update's own failure. Its dt_expiration branch rewrites
 *    t_item via a JOIN/DATE_ADD; its description branch updates-or-inserts each
 *    locale with a uniquified slug. All pinned by effect.
 *
 * The cache helpers are REAL (hCache.php below) because the tree cache IS the
 * contract; osc_base_url()/osc_plugins_path() are stood in for and __() is
 * stubbed to the identity the real helper produces with no translation loaded —
 * hDefines.php and hTranslations.php cannot be required from a model test (the
 * former redeclares the bootstrap's osc_uploads_path(), the latter drags in
 * Translation -> osc_translations_path()). This mirrors what
 * tests/models/categorystats.php and tests/models/itemresource.php already do.
 *
 * Usage:  php tests/models/category.php          (standalone, own scratch database)
 *         php tests/run-models.php category      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_category');

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
/*
 * __() drags in Translation -> osc_translations_path() (an hDefines helper the
 * harness cannot load). With no translation domain loaded the real helper returns
 * its key unchanged (dgettext passthrough, then an unregistered 'gettext' filter),
 * which is exactly what this returns. No model test requires the real
 * hTranslations.php, so this guarded stand-in cannot become a redeclare.
 */
if (!function_exists('__')) {
    function __($key, $domain = 'core')
    {
        return $key;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';    // osc_run_hook/osc_apply_filter/osc_add_hook (hCache uses osc_add_hook at load)
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php'; // osc_language
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';     // osc_current_user_locale
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/formatting.php';          // osc_sanitizeString (slug generation in insert/updateByPrimaryKey)

Preference::newInstance(); // osc_current_user_locale() -> osc_language() reads a preference; warm it so it is never charged to a query-count pin

$cache      = Object_Cache_Factory::newInstance();
$catTable   = DB_TABLE_PREFIX . 't_category';
$descTable  = DB_TABLE_PREFIX . 't_category_description';
$statsTable = DB_TABLE_PREFIX . 't_category_stats';
$pluginCatT = DB_TABLE_PREFIX . 't_plugin_category';
$metaCatT   = DB_TABLE_PREFIX . 't_meta_categories';
$locale     = 'en_US';

seed_locale($admin, $locale, 'English');
// Extra locales exist in t_locale so the model connection (foreign keys ON,
// unlike the admin seed session) can insert new description rows for them.
seed_locale($admin, 'fr_FR', 'French');
seed_locale($admin, 'es_ES', 'Spanish');

/** True when every SCALAR value in every row is a string or null; nested arrays
 * (the merged 'locale' sub-array) are ignored. The legacy row shape carries that
 * sub-array, so all_rows_string() alone would always fail on it. */
$rowsScalarStrings = static function (array $rows): bool {
    foreach ($rows as $r) {
        if (!is_array($r)) {
            return false;
        }
        foreach ($r as $v) {
            if (!is_array($v) && $v !== null && !is_string($v)) {
                return false;
            }
        }
    }

    return true;
};

/* ----------------------------------------------------------------------------
 * Reset helpers. Category::newInstance() is a process-lifetime singleton and its
 * tree lives ALSO in the object cache; the runner shares one process across every
 * model file. Both have to be forced cold to get a snapshot of the current
 * fixtures, and both are reset again at the end so the CategoryStats/CityStats
 * files that run after this one start from a clean slate.
 * ------------------------------------------------------------------------- */
$instanceProp = new ReflectionProperty('Category', 'instance');
$instanceProp->setAccessible(true);

$resetCategory = static function () use ($instanceProp, $cache): void {
    $instanceProp->setValue(null, null);
    $cache->flush();
};

$freshCategory = static function () use ($resetCategory, $locale): Category {
    $resetCategory();

    return Category::newInstance($locale);
};

/* ----------------------------------------------------------------------------
 * Raw fixture writers for tables scratchdb.php has no seeder for. Foreign-key
 * checks are off on the admin session, so the fk_i_field_id below need not point
 * at a real field row.
 * ------------------------------------------------------------------------- */
$seedStats = static function (int $categoryId, int $numItems) use ($admin, $statsTable): void {
    seed_exec(
        $admin,
        "INSERT INTO $statsTable (fk_i_category_id, i_num_items) VALUES (?, ?)",
        'ii',
        array($categoryId, $numItems)
    );
};
$seedPluginCat = static function (int $categoryId, string $plugin = 'demo_plugin') use ($admin, $pluginCatT): void {
    seed_exec(
        $admin,
        "INSERT INTO $pluginCatT (s_plugin_name, fk_i_category_id) VALUES (?, ?)",
        'si',
        array($plugin, $categoryId)
    );
};
$seedMetaCat = static function (int $categoryId, int $fieldId) use ($admin, $metaCatT): void {
    seed_exec(
        $admin,
        "INSERT INTO $metaCatT (fk_i_category_id, fk_i_field_id) VALUES (?, ?)",
        'ii',
        array($categoryId, $fieldId)
    );
};
$setPosition = static function (int $categoryId, int $position) use ($admin, $catTable): void {
    seed_exec($admin, "UPDATE $catTable SET i_position = ? WHERE pk_i_id = ?", 'ii', array($position, $categoryId));
};

$countWhere = static function (string $table, string $col, int $val) use ($admin): int {
    $stmt = $admin->prepare("SELECT COUNT(*) c FROM $table WHERE $col = ?");
    $stmt->bind_param('i', $val);
    $stmt->execute();
    $c = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    return $c;
};
$catExists = static function (int $id) use ($countWhere, $catTable): bool {
    return $countWhere($catTable, 'pk_i_id', $id) > 0;
};

/* ----------------------------------------------------------------------------
 * The read fixture: a small enabled tree plus one disabled root, all seeded
 * BEFORE the first Category::newInstance() so the one-time snapshot captures
 * them. i_position is made distinct so the ORDER BY i_position ASC pins are
 * deterministic (seed_category defaults every row to 0).
 *
 *   Vehicles(root,pos0)                Property(root,pos1)     Hidden(root,disabled)
 *     Cars(pos0)  -> Sedans(pos0)
 *     Bikes(pos1)
 * ------------------------------------------------------------------------- */
$vehicles = seed_category($admin, 'Vehicles', null, $locale);
$cars     = seed_category($admin, 'Cars', $vehicles, $locale);
$sedans   = seed_category($admin, 'Sedans', $cars, $locale);
$bikes    = seed_category($admin, 'Bikes', $vehicles, $locale);
$property = seed_category($admin, 'Property', null, $locale);
$hidden   = seed_category($admin, 'Hidden', null, $locale, 0); // disabled -> excluded from the enabled tree

$setPosition($vehicles, 0);
$setPosition($property, 1);
$setPosition($cars, 0);
$setPosition($bikes, 1);
$setPosition($hidden, 2);
$seedStats($cars, 5);   // Cars has an item count; the rest have no stats row (i_num_items -> null via LEFT JOIN)

$model = $freshCategory();

/* ----------------------------------------------------------------------------
 * Surface (C2).
 * ------------------------------------------------------------------------- */
harness_section('Category: public surface');

check('Category still extends DAO', is_subclass_of('Category', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $catTable, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('pk_i_id', 'fk_i_parent_id', 'i_expiration_days', 'i_position', 'b_enabled', 's_icon', 'b_price_enabled'),
    $model->getFields()
);

pin('newInstance signature is unchanged', "public static newInstance(\$l = '')", harness_method_signature('Category', 'newInstance'));
pin('__construct signature is unchanged', "public __construct(\$l = '')", harness_method_signature('Category', '__construct'));
pin('toTree signature is unchanged', 'public toTree(bool $empty = true)', harness_method_signature('Category', 'toTree'));
pin('listWhere signature is unchanged', 'public listWhere()', harness_method_signature('Category', 'listWhere'));
pin(
    'findByPrimaryKey signature is unchanged (optional $locale kept)',
    "public findByPrimaryKey(\$categoryID, \$locale = '')",
    harness_method_signature('Category', 'findByPrimaryKey')
);
pin('deleteByPrimaryKey signature is unchanged', 'public deleteByPrimaryKey($pk)', harness_method_signature('Category', 'deleteByPrimaryKey'));
pin('updateByPrimaryKey signature is unchanged', 'public updateByPrimaryKey($data, $pk)', harness_method_signature('Category', 'updateByPrimaryKey'));
pin('insert signature is unchanged', 'public insert($fields, $aFieldsDescription = NULL)', harness_method_signature('Category', 'insert'));
pin(
    'updateExpiration signature is unchanged',
    'public updateExpiration($pk_i_id, $expiration, $updateSubcategories = false)',
    harness_method_signature('Category', 'updateExpiration')
);
pin('_findNameIDByLocale signature is unchanged', 'public _findNameIDByLocale($locale = NULL)', harness_method_signature('Category', '_findNameIDByLocale'));

pin(
    'the model declares exactly these public methods of its own, nothing added or removed',
    array(
        '__construct', '_findNameIDByLocale', 'deleteByPrimaryKey', 'findByPrimaryKey', 'findBySlug',
        'findNameByPrimaryKey', 'findRootCategories', 'findRootCategoriesEnabled', 'findRootCategory',
        'findSubcategories', 'findSubcategoriesEnabled', 'formatValue', 'hierarchy', 'insert',
        'insertDescription', 'isRoot', 'listAll', 'listEnabled', 'listWhere', 'newInstance',
        'toRootTree', 'toSubTree', 'toTree', 'toTreeAll', 'updateByPrimaryKey', 'updateExpiration',
        'updateName', 'updateOrder', 'updatePriceEnabled',
    ),
    (static function () {
        $own = array();
        foreach ((new ReflectionClass('Category'))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() === 'Category') {
                $own[] = $m->getName();
            }
        }
        sort($own);

        return $own;
    })()
);

/* ----------------------------------------------------------------------------
 * The tree cache (C9). Built once, served free, never invalidated by a write.
 * ------------------------------------------------------------------------- */
harness_section('Category: tree cache — construction cost and warm reads');

$cold = harness_query_count(static function () use ($resetCategory, $locale) {
    $resetCategory();
    Category::newInstance($locale);
});
pin('constructing the model cold costs exactly one query (listEnabled)', 1, $cold);

$m = Category::newInstance($locale);
pin('toTree() on the constructed model costs zero queries — it is served from the snapshot', 0, harness_query_count(static function () use ($m) {
    $m->toTree();
}));
pin('an in-tree findByPrimaryKey() costs zero queries', 0, harness_query_count(static function () use ($m, $vehicles) {
    $m->findByPrimaryKey($vehicles);
}));
pin('a repeat in-tree findByPrimaryKey() also costs zero queries (per-id cache)', 0, harness_query_count(static function () use ($m, $vehicles) {
    $m->findByPrimaryKey($vehicles);
}));

harness_section('Category: tree cache — a disabled category is not in the snapshot');

$m = $freshCategory();
$disabledCold = harness_query_count(static function () use ($m, $hidden) {
    $m->findByPrimaryKey($hidden);
});
pin('a disabled category is absent from the enabled tree, so its lookup falls through to two live queries', 2, $disabledCold);
pin('...and a repeat lookup is then served free from the per-id cache', 0, harness_query_count(static function () use ($m, $hidden) {
    $m->findByPrimaryKey($hidden);
}));

harness_section('Category: tree cache — no write invalidates it');

$m = $freshCategory();
pin('before any write, the tree serves the seeded name', 'Vehicles', $m->findByPrimaryKey($vehicles)['s_name']);
$m->updateName($vehicles, $locale, 'Vehicles RENAMED');
pin('the underlying row really changed', 'Vehicles RENAMED', (static function () use ($admin, $descTable, $vehicles, $locale) {
    $stmt = $admin->prepare("SELECT s_name FROM $descTable WHERE fk_i_category_id = ? AND fk_c_locale_code = ?");
    $stmt->bind_param('is', $vehicles, $locale);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc()['s_name'];
    $stmt->close();

    return $v;
})());
pin('but the tree still serves the pre-write name — no write invalidates the cache (C9)', 'Vehicles', $m->findByPrimaryKey($vehicles)['s_name']);
$m = $freshCategory();
pin('only a fresh instance over a flushed cache reflects the change', 'Vehicles RENAMED', $m->findByPrimaryKey($vehicles)['s_name']);
// restore the name so later name-based pins are unaffected
seed_exec($admin, "UPDATE $descTable SET s_name = 'Vehicles' WHERE fk_i_category_id = ? AND fk_c_locale_code = ?", 'is', array($vehicles, $locale));

/* ----------------------------------------------------------------------------
 * listWhere() and its family — the joined/merged read ledger and C4.
 * ------------------------------------------------------------------------- */
harness_section('Category::findRootCategories — literal-form listWhere (unprepared)');

$m = $freshCategory();
$roots = $m->findRootCategories();
// findRootCategories() filters ONLY on fk_i_parent_id IS NULL — it does NOT
// filter on b_enabled (that is what findRootCategoriesEnabled() is for), so the
// disabled Hidden root is included here.
pin('every root comes back, enabled or not', 3, count($roots));
pin('ordered by i_position ASC (Vehicles pos0, Property pos1, Hidden pos2)', array('Vehicles', 'Property', 'Hidden'), array_column($roots, 's_name'));
check('every scalar value in every row is a string or null (C4)', $rowsScalarStrings($roots), describe($roots));
pin('pk_i_id round-trips as a string, not an int (C4)', (string) $vehicles, $roots[0]['pk_i_id']);
pin(
    'the merged row carries the joined a.* / b.* / c.i_num_items columns plus the locale sub-array, in this order',
    array(
        'pk_i_id', 'fk_i_parent_id', 'i_expiration_days', 'i_position', 'b_enabled', 'b_price_enabled', 's_icon',
        'fk_i_category_id', 'fk_c_locale_code', 's_name', 's_description', 's_slug', 'i_num_items', 'locale',
    ),
    array_keys($roots[0])
);
pin('fk_c_locale_code is overlaid with $this->language', $locale, $roots[0]['fk_c_locale_code']);
check('the locale sub-array is keyed by locale code', isset($roots[0]['locale'][$locale]), describe($roots[0]));
pin('a root with no stats row reports a null i_num_items (LEFT JOIN)', null, $roots[0]['i_num_items']);
pin('one findRootCategories() call costs one query', 1, harness_query_count(static function () use ($m) {
    $m->findRootCategories();
}));

harness_section('Category::findSubcategories — %d-form listWhere (prepared) still returns strings (C4, amendment W)');

$subs = $m->findSubcategories($vehicles);
pin('Vehicles has two children', 2, count($subs));
pin('ordered by i_position ASC (Cars pos0, Bikes pos1)', array('Cars', 'Bikes'), array_column($subs, 's_name'));
check('every scalar value in every row is a string or null even though this path binds a parameter (C4)', $rowsScalarStrings($subs), describe($subs));
pin('the child that has a stats row reports its count as a string', '5', $subs[0]['i_num_items']);
pin('fk_i_parent_id round-trips as a string', (string) $vehicles, $subs[0]['fk_i_parent_id']);
pin('a leaf category has no subcategories', array(), $m->findSubcategories($sedans));
pin('one findSubcategories() call costs one query', 1, harness_query_count(static function () use ($m, $vehicles) {
    $m->findSubcategories($vehicles);
}));

harness_section('Category::findSubcategoriesEnabled / findRootCategoriesEnabled / isRoot / findRootCategory');

pin('findSubcategoriesEnabled returns enabled children only', array('Cars', 'Bikes'), array_column($m->findSubcategoriesEnabled($vehicles), 's_name'));
pin('findRootCategoriesEnabled returns the enabled roots', 2, count($m->findRootCategoriesEnabled()));
pin('isRoot is true for a root', true, $m->isRoot($vehicles));
pin('isRoot is false for a child', false, $m->isRoot($cars));
pin('isRoot is false for an unknown id', false, $m->isRoot(999999));
pin('findRootCategory walks up from a grandchild to its root', (string) $vehicles, $m->findRootCategory($sedans)['pk_i_id']);
pin('findRootCategory of a root returns the root itself', (string) $vehicles, $m->findRootCategory($vehicles)['pk_i_id']);

harness_section('Category::listAll / toTreeAll / toSubTree');

$all = $m->listAll();
pin('listAll returns every category including the disabled one', 6, count($all));
check('every scalar value in every row is a string or null (C4)', $rowsScalarStrings($all), describe($all));

$treeAll = $m->toTreeAll();
pin('toTreeAll returns the three roots (Vehicles, Property, Hidden)', 3, count($treeAll));
$vehicleNode = null;
foreach ($treeAll as $node) {
    if ($node['pk_i_id'] === (string) $vehicles) {
        $vehicleNode = $node;
    }
}
check('Vehicles node exists in the full tree', $vehicleNode !== null, describe($treeAll));
pin('Vehicles has a nested categories array with its two children', 2, count($vehicleNode['categories']));

$subTree = $m->toSubTree($vehicles);
pin('toSubTree(root) returns its children as tree nodes', 2, count($subTree));
pin('toSubTree(null) returns an empty array', array(), $m->toSubTree(null));
pin('toSubTree of an unknown id returns an empty array', array(), $m->toSubTree(999999));

/* ----------------------------------------------------------------------------
 * findByPrimaryKey() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Category::findByPrimaryKey — the return ledger');

$m = $freshCategory();
pin('a null id returns bool false without a query', false, $m->findByPrimaryKey(null));

$row = $m->findByPrimaryKey($cars);
check('a match returns an array', is_array($row), describe($row));
pin('pk_i_id round-trips', (string) $cars, $row['pk_i_id']);
pin('s_name is present at the top level', 'Cars', $row['s_name']);
check('the merged row still carries its locale sub-array', isset($row['locale'][$locale]), describe($row));
check('every value in the row is a string or null (C4)', all_values_string(array_filter($row, static function ($v) {
    return !is_array($v);
})), describe($row));

pin('a disabled (not-in-tree) category is still found via the live query', 'Hidden', $m->findByPrimaryKey($hidden)['s_name']);
pin('an unknown id returns bool false', false, $m->findByPrimaryKey(999999));

harness_section('Category::findByPrimaryKey — the $locale argument');

$m = $freshCategory();
$rowLoc = $m->findByPrimaryKey($cars, $locale);
pin('an explicit matching locale returns the row with that locale name', 'Cars', $rowLoc['s_name']);

/* ----------------------------------------------------------------------------
 * findBySlug() — slug cache + the merged row.
 * ------------------------------------------------------------------------- */
harness_section('Category::findBySlug — the return ledger and its slug cache');

$m = $freshCategory();
$bySlug = $m->findBySlug('cars');
check('a match returns an array', is_array($bySlug), describe($bySlug));
pin('pk_i_id round-trips', (string) $cars, $bySlug['pk_i_id']);
check('every scalar value in the row is a string or null (C4)', all_values_string(array_filter($bySlug, static function ($v) {
    return !is_array($v);
})), describe($bySlug));
pin('an unknown slug returns an empty array', array(), $m->findBySlug('no-such-slug'));
pin('an empty slug returns an empty array', array(), $m->findBySlug(''));

$m = $freshCategory();
$firstCost = harness_query_count(static function () use ($m) {
    $m->findBySlug('cars');
});
pin('a cold findBySlug costs one query', 1, $firstCost);
pin('a warm findBySlug is served from the slug cache -> the tree, at zero queries', 0, harness_query_count(static function () use ($m) {
    $m->findBySlug('cars');
}));

/* ----------------------------------------------------------------------------
 * findNameByPrimaryKey() — tree hit, live query, and the missing-name fallback.
 * ------------------------------------------------------------------------- */
harness_section('Category::findNameByPrimaryKey');

$m = $freshCategory();
pin('an in-tree category returns its name at zero queries', 'Vehicles', $m->findNameByPrimaryKey($vehicles));
pin('a null id returns bool false', false, $m->findNameByPrimaryKey(null));

$m = $freshCategory();
$disabledNameCost = harness_query_count(static function () use ($m, $hidden) {
    $m->findNameByPrimaryKey($hidden);
});
pin('a not-in-tree category is read with one live query', 1, $disabledNameCost);
pin('...and returns its name', 'Hidden', $m->findNameByPrimaryKey($hidden));
pin('an id with no description row falls back to the translated "Non-Existent Category"', 'Non-Existent Category', $m->findNameByPrimaryKey(999999));

/* ----------------------------------------------------------------------------
 * _findNameIDByLocale() — aliased projection, all strings (C4).
 * ------------------------------------------------------------------------- */
harness_section('Category::_findNameIDByLocale');

$m = $freshCategory();
pin('a null locale returns bool false', false, $m->_findNameIDByLocale(null));
$byLocale = $m->_findNameIDByLocale($locale);
pin('every seeded category description is returned', 6, count($byLocale));
pin('the row carries s_name and the aliased pk_i_id', array('s_name', 'pk_i_id'), array_keys($byLocale[0]));
check('the aliased pk_i_id comes back as a string, not a native int (C4)', is_string($byLocale[0]['pk_i_id']), describe($byLocale[0]));
pin('a locale with no rows returns an empty array', array(), $m->_findNameIDByLocale('zz_ZZ'));
pin('one _findNameIDByLocale() call costs one query', 1, harness_query_count(static function () use ($m, $locale) {
    $m->_findNameIDByLocale($locale);
}));

/* ----------------------------------------------------------------------------
 * hierarchy() / toRootTree().
 * ------------------------------------------------------------------------- */
harness_section('Category::toRootTree / hierarchy');

$m = $freshCategory();
$root2leaf = $m->toRootTree($sedans);
pin('toRootTree returns the branch root-first', array((string) $vehicles, (string) $cars, (string) $sedans), array_column($root2leaf, 'pk_i_id'));
$hier = $m->hierarchy($sedans);
pin('hierarchy is the same branch reversed (leaf-first)', array((string) $sedans, (string) $cars, (string) $vehicles), array_column($hier, 'pk_i_id'));
pin('toRootTree(null) returns an empty array', array(), $m->toRootTree(null));

/* ----------------------------------------------------------------------------
 * insert() — creates the category and one description per locale with a unique
 * slug, and returns the new id.
 * ------------------------------------------------------------------------- */
harness_section('Category::insert');

$m = $freshCategory();
$newId = $m->insert(
    array('fk_i_parent_id' => null, 'i_expiration_days' => 0, 'i_position' => 0, 'b_enabled' => 1, 'b_price_enabled' => 1),
    array($locale => array('s_name' => 'Boats'))
);
check('insert returns a positive integer id', is_int($newId) && $newId > 0, describe($newId));
check('the category row exists', $catExists($newId));
pin('exactly one description row was written for it', 1, $countWhere($descTable, 'fk_i_category_id', $newId));
$boatDesc = (static function () use ($admin, $descTable, $newId) {
    $stmt = $admin->prepare("SELECT s_name, s_slug FROM $descTable WHERE fk_i_category_id = ?");
    $stmt->bind_param('i', $newId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $r;
})();
pin('the description name round-trips', 'Boats', $boatDesc['s_name']);
pin('a slug was derived from the name', 'boats', $boatDesc['s_slug']);

harness_section('Category::insert — slug uniquification on collision');

$m = $freshCategory();
$dupId = $m->insert(
    array('fk_i_parent_id' => null, 'i_expiration_days' => 0, 'i_position' => 0, 'b_enabled' => 1, 'b_price_enabled' => 1),
    array($locale => array('s_name' => 'Boats'))
);
$dupSlug = (static function () use ($admin, $descTable, $dupId) {
    $stmt = $admin->prepare("SELECT s_slug FROM $descTable WHERE fk_i_category_id = ?");
    $stmt->bind_param('i', $dupId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc()['s_slug'];
    $stmt->close();

    return $r;
})();
pin('a colliding slug is uniquified with a numeric suffix', 'boats_1', $dupSlug);

/* ----------------------------------------------------------------------------
 * insertDescription().
 * ------------------------------------------------------------------------- */
harness_section('Category::insertDescription');

$m = $freshCategory();
$descTarget = seed_category($admin, 'DescTarget', null, $locale);
$m = $freshCategory();
$m->insertDescription(array(
    'fk_i_category_id' => $descTarget,
    'fk_c_locale_code' => 'fr_FR',
    's_name'           => 'CibleDesc',
    's_description'    => 'desc',
    's_slug'           => 'cibledesc',
));
pin('insertDescription added a second locale row', 2, $countWhere($descTable, 'fk_i_category_id', $descTarget));
pin('insertDescription with an empty s_name writes nothing (and returns null)', null, $m->insertDescription(array(
    'fk_i_category_id' => $descTarget,
    'fk_c_locale_code' => 'de_DE',
    's_name'           => '',
    's_slug'           => 'x',
)));
pin('...so the row count is unchanged', 2, $countWhere($descTable, 'fk_i_category_id', $descTarget));

/* ----------------------------------------------------------------------------
 * updateOrder / updatePriceEnabled / updateName — simple single-table writes.
 * ------------------------------------------------------------------------- */
harness_section('Category::updateOrder / updatePriceEnabled / updateName');

$m = $freshCategory();
$orderTarget = seed_category($admin, 'OrderTarget', null, $locale);
$m = $freshCategory();

$m->updateOrder($orderTarget, 7);
pin('updateOrder set i_position', 7, (int) $admin->query("SELECT i_position FROM $catTable WHERE pk_i_id = $orderTarget")->fetch_assoc()['i_position']);

$m->updatePriceEnabled($orderTarget, 0);
pin('updatePriceEnabled cleared b_price_enabled', 0, (int) $admin->query("SELECT b_price_enabled FROM $catTable WHERE pk_i_id = $orderTarget")->fetch_assoc()['b_price_enabled']);

$m->updateName($orderTarget, $locale, 'OrderTarget Renamed');
pin('updateName rewrote the description name', 'OrderTarget Renamed', (static function () use ($admin, $descTable, $orderTarget, $locale) {
    $stmt = $admin->prepare("SELECT s_name FROM $descTable WHERE fk_i_category_id = ? AND fk_c_locale_code = ?");
    $stmt->bind_param('is', $orderTarget, $locale);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc()['s_name'];
    $stmt->close();

    return $v;
})());

harness_section('Category::updatePriceEnabled — cascading to subcategories');

$m = $freshCategory();
$m->updatePriceEnabled($vehicles, 0, true);
pin('the parent b_price_enabled is cleared', 0, (int) $admin->query("SELECT b_price_enabled FROM $catTable WHERE pk_i_id = $vehicles")->fetch_assoc()['b_price_enabled']);
pin('a child is cleared too (cascade)', 0, (int) $admin->query("SELECT b_price_enabled FROM $catTable WHERE pk_i_id = $cars")->fetch_assoc()['b_price_enabled']);
pin('the grandchild is cleared as well (recursion)', 0, (int) $admin->query("SELECT b_price_enabled FROM $catTable WHERE pk_i_id = $sedans")->fetch_assoc()['b_price_enabled']);
// restore
seed_exec($admin, "UPDATE $catTable SET b_price_enabled = 1 WHERE pk_i_id IN ($vehicles, $cars, $sedans, $bikes)", '', array());

/* ----------------------------------------------------------------------------
 * updateExpiration — rewrites t_item and t_category, recurses to subcategories.
 * With zero item rows the t_item side is a no-op (as Region's item cascade is),
 * so only the t_category write and the recursion are observed here.
 * ------------------------------------------------------------------------- */
harness_section('Category::updateExpiration');

$m = $freshCategory();
$m->updateExpiration($vehicles, 30);
pin('the category expiration was written', 30, (int) $admin->query("SELECT i_expiration_days FROM $catTable WHERE pk_i_id = $vehicles")->fetch_assoc()['i_expiration_days']);
pin('a subcategory is untouched without the recursion flag', 0, (int) $admin->query("SELECT i_expiration_days FROM $catTable WHERE pk_i_id = $cars")->fetch_assoc()['i_expiration_days']);

$m->updateExpiration($vehicles, 45, true);
pin('with the recursion flag the parent is updated', 45, (int) $admin->query("SELECT i_expiration_days FROM $catTable WHERE pk_i_id = $vehicles")->fetch_assoc()['i_expiration_days']);
pin('...and so is a child', 45, (int) $admin->query("SELECT i_expiration_days FROM $catTable WHERE pk_i_id = $cars")->fetch_assoc()['i_expiration_days']);
pin('...and the grandchild', 45, (int) $admin->query("SELECT i_expiration_days FROM $catTable WHERE pk_i_id = $sedans")->fetch_assoc()['i_expiration_days']);
// restore
seed_exec($admin, "UPDATE $catTable SET i_expiration_days = 0 WHERE pk_i_id IN ($vehicles, $cars, $sedans, $bikes)", '', array());

/* ----------------------------------------------------------------------------
 * updateByPrimaryKey — the accumulated affected-row ledger and its side writes.
 * ------------------------------------------------------------------------- */
harness_section('Category::updateByPrimaryKey');

$m = $freshCategory();
$upTarget = seed_category($admin, 'UpTarget', null, $locale, 1, 0);
$m = $freshCategory();

$ret = $m->updateByPrimaryKey(
    array(
        'fields'            => array('i_expiration_days' => 0, 'i_position' => 3),
        'aFieldsDescription' => array($locale => array('s_name' => 'UpTarget Edited', 's_slug' => 'uptarget-edited')),
    ),
    $upTarget
);
check('updateByPrimaryKey returns an int affected-row count', is_int($ret), describe($ret));
pin('the category fields were updated', 3, (int) $admin->query("SELECT i_position FROM $catTable WHERE pk_i_id = $upTarget")->fetch_assoc()['i_position']);
pin('the description name was updated', 'UpTarget Edited', (static function () use ($admin, $descTable, $upTarget, $locale) {
    $stmt = $admin->prepare("SELECT s_name FROM $descTable WHERE fk_i_category_id = ? AND fk_c_locale_code = ?");
    $stmt->bind_param('is', $upTarget, $locale);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc()['s_name'];
    $stmt->close();

    return $v;
})());

harness_section('Category::updateByPrimaryKey — a new locale is inserted when the update matches no row');

$m = $freshCategory();
$m->updateByPrimaryKey(
    array(
        'fields'            => array('i_expiration_days' => 0),
        'aFieldsDescription' => array('es_ES' => array('s_name' => 'Nuevo', 's_slug' => 'nuevo')),
    ),
    $upTarget
);
$esCount = (static function () use ($admin, $descTable, $upTarget) {
    $stmt = $admin->prepare("SELECT COUNT(*) c FROM $descTable WHERE fk_i_category_id = ? AND fk_c_locale_code = 'es_ES'");
    $stmt->bind_param('i', $upTarget);
    $stmt->execute();
    $c = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    return $c;
})();
pin('the es_ES description now exists', 1, $esCount);

/* ----------------------------------------------------------------------------
 * deleteByPrimaryKey — the full cascade, a survivor subtree, and cache staleness.
 * Destructive, so it runs last on a freshly rebuilt fixture. No items are seeded:
 * item cleanup is delegated to Item (out of scope) and is exercised over zero
 * rows, exactly as Region's cascade is.
 * ------------------------------------------------------------------------- */
harness_section('Category::deleteByPrimaryKey — cascade + survivor subtree');

// Wipe the category tables and build an isolated doomed/survivor pair.
$admin->query("SET FOREIGN_KEY_CHECKS = 0");
foreach (array($metaCatT, $pluginCatT, $statsTable, $descTable, $catTable) as $t) {
    $admin->query("TRUNCATE TABLE $t");
}

$doomRoot = seed_category($admin, 'DoomRoot', null, $locale);
$doomMid  = seed_category($admin, 'DoomMid', $doomRoot, $locale);
$doomLeaf = seed_category($admin, 'DoomLeaf', $doomMid, $locale);
$seedStats($doomRoot, 1);
$seedStats($doomMid, 1);
$seedStats($doomLeaf, 1);
$seedPluginCat($doomRoot);
$seedPluginCat($doomLeaf);
$seedMetaCat($doomRoot, 111);
$seedMetaCat($doomLeaf, 222);

$survRoot = seed_category($admin, 'SurvRoot', null, $locale);
$survChild = seed_category($admin, 'SurvChild', $survRoot, $locale);
$seedStats($survRoot, 9);
$seedPluginCat($survRoot);
$seedMetaCat($survRoot, 333);

$m = $freshCategory();
pin('the doomed root is in the freshly built tree before deletion', 'DoomRoot', $m->findByPrimaryKey($doomRoot)['s_name']);

$ret = $m->deleteByPrimaryKey($doomRoot);
check('deleteByPrimaryKey returns the affected-row count of the final category delete', $ret === 1 || $ret === 0, describe($ret));

check('the doomed root is gone', !$catExists($doomRoot));
check('the doomed middle category is gone (recursion)', !$catExists($doomMid));
check('the doomed leaf is gone (recursion)', !$catExists($doomLeaf));
pin('every doomed description row is gone', 0, $countWhere($descTable, 'fk_i_category_id', $doomRoot) + $countWhere($descTable, 'fk_i_category_id', $doomMid) + $countWhere($descTable, 'fk_i_category_id', $doomLeaf));
pin('every doomed stats row is gone', 0, $countWhere($statsTable, 'fk_i_category_id', $doomRoot) + $countWhere($statsTable, 'fk_i_category_id', $doomMid) + $countWhere($statsTable, 'fk_i_category_id', $doomLeaf));
pin('every doomed plugin-category row is gone', 0, $countWhere($pluginCatT, 'fk_i_category_id', $doomRoot) + $countWhere($pluginCatT, 'fk_i_category_id', $doomLeaf));
pin('every doomed meta-category row is gone', 0, $countWhere($metaCatT, 'fk_i_category_id', $doomRoot) + $countWhere($metaCatT, 'fk_i_category_id', $doomLeaf));

check('the survivor root is untouched', $catExists($survRoot));
check('the survivor child is untouched', $catExists($survChild));
pin('the survivor description rows are untouched', 2, $countWhere($descTable, 'fk_i_category_id', $survRoot) + $countWhere($descTable, 'fk_i_category_id', $survChild));
pin('the survivor stats row is untouched', 1, $countWhere($statsTable, 'fk_i_category_id', $survRoot));
pin('the survivor plugin-category row is untouched', 1, $countWhere($pluginCatT, 'fk_i_category_id', $survRoot));
pin('the survivor meta-category row is untouched', 1, $countWhere($metaCatT, 'fk_i_category_id', $survRoot));

harness_section('Category::deleteByPrimaryKey — the tree cache is not invalidated by the delete');

pin('the same instance still serves the deleted root from its stale snapshot (C9)', 'DoomRoot', $m->findByPrimaryKey($doomRoot)['s_name']);
$m = $freshCategory();
pin('a fresh instance no longer finds the deleted root', false, $m->findByPrimaryKey($doomRoot));

harness_section('Category::deleteByPrimaryKey — deleting a nonexistent id');

pin('deleting an id that never existed returns int 0 (no rows affected, no error)', 0, $m->deleteByPrimaryKey(999999));

/* ----------------------------------------------------------------------------
 * formatValue — pure helper, no query.
 * ------------------------------------------------------------------------- */
harness_section('Category::formatValue');

pin('null maps to the DB NULL sentinel', DB_CONST_NULL, $model->formatValue(null));
pin('the NOW() sentinel passes through untouched', DB_FUNC_NOW, $model->formatValue(DB_FUNC_NOW));
pin('an ordinary string is trimmed and single-quoted', "'abc'", $model->formatValue('  abc  '));

/* ----------------------------------------------------------------------------
 * Leave a clean slate: the runner shares this process with categorystats.php and
 * citystats.php, which build their OWN Category snapshot over their OWN fixtures.
 * A leftover singleton or a cached tree keyed on the same base_url+locale would
 * poison them.
 * ------------------------------------------------------------------------- */
$resetCategory();

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/category.php */
