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
 * Characterization pins for the Item model.
 *
 * Item is the hottest model in the system and everything joins to it, so its
 * observable contract — return shapes, row value types, result order, the
 * batched extendData() fan-out and the resource-cache prime it drives — is
 * pinned here against the legacy implementation and required to pass UNCHANGED
 * once the query-bearing methods move to the parameterized layer. Everything was
 * established by RUNNING the code, never by trusting a method name or comment.
 *
 * The load-bearing facts, all reproduced rather than "fixed":
 *
 *  - extendData() is a defended N+1. For a set of items it issues a FIXED small
 *    number of queries regardless of item count: one description fan-out, one
 *    category-name fan-out, one grouped stats+location join, and — only when more
 *    than one item is passed — one ItemResource::primeResourcesCache() that seeds
 *    the exact cache keys ItemResource::getAllResourcesFromItem() reads, so a
 *    following per-item resource read is a cache hit (zero queries). This flatness
 *    and the prime MUST survive the conversion; turning either into per-item would
 *    regress the hottest page. Measured flat at 2 and 5 items below.
 *  - The merged row carries: the item's own columns, the seven SUM(...) stat
 *    aggregates (DECIMAL, so string — C4), the joined t_item_location columns
 *    (l.*), a per-locale 'locale' sub-array, and a top-level s_category_name.
 *  - listWhere() is a public "comodin": single-argument form takes a RAW WHERE
 *    fragment the caller owns (it may embed ORDER BY / LIMIT, and listLatest()
 *    does exactly that), multi-argument form is a printf template whose values
 *    legacy escapes and vsprintf-substitutes. findByPhone()/findByEmail() reach
 *    it with %s.
 *  - Value typing (C4). Legacy rows are all strings/null. The finders that reach
 *    extendData() carry the SUM aggregates as strings and the l.* ints as strings.
 *  - DAO clause state is a per-instance SINGLETON shared by every model. Only one
 *    Item method leaks it: countByMarkas(null) returns 0 through an early branch
 *    that never runs get(), leaving select/from/where set for the next legacy
 *    call. The parameterized model does not use that state at all, so the pins
 *    reset the shared DAO after that call (and defensively before finder/count
 *    sections) — a no-op on the converted model, a de-pollution on the legacy one,
 *    green on both.
 *  - findByPhone()/findByEmail() compared a VARCHAR column with the legacy
 *    escape(), which returns a bare (unquoted) numeric string and so forced a
 *    NUMERIC comparison in which '00555' equals '555'. That coercion is dropped by
 *    the conversion (amendment T): the value binds as a string. The pins that
 *    record this are in a clearly-labelled section at the end; they are RED
 *    against the pre-conversion model by design and flip green once converted.
 *
 * The cache helpers are REAL (hCache.php) because the prime/read pair IS the
 * contract here; osc_base_url()/osc_plugins_path()/__() are stood in for, since
 * hDefines.php cannot be required from a model test (it redeclares the
 * bootstrap's osc_uploads_path()). This mirrors tests/models/itemresource.php and
 * tests/models/category.php. hUsers.php and utils.php are the REAL files: the
 * delete cascade reaches ItemActions::deleteResourcesFromHD() -> osc_logged_user_id()
 * and osc_isExpired(), and requiring the real files (idempotent) exercises the true
 * paths rather than a fake (amendment L). DEMO is defined so osc_deleteResource()
 * short-circuits instead of touching the filesystem.
 *
 * Usage:  php tests/models/item.php          (standalone, own scratch database)
 *         php tests/run-models.php item      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_item');

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
if (!defined('DEMO')) {
    // osc_deleteResource() short-circuits under DEMO, so the delete cascade never
    // reaches the filesystem while still firing every hook and DB delete.
    define('DEMO', true);
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
 * __() with no translation domain loaded returns its key unchanged, which is what
 * the real helper does through an unregistered gettext filter. No model test
 * requires the real hTranslations.php, so this guarded stand-in cannot become a
 * redeclare.
 */
if (!function_exists('__')) {
    function __($key, $domain = 'core')
    {
        return $key;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';    // osc_run_hook/osc_add_hook (hCache uses osc_add_hook at load)
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php'; // osc_language
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';     // osc_current_user_locale
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';      // osc_logged_user_id (delete cascade -> ItemActions)
require_once ABS_PATH . 'oc-includes/osclass/utils.php';               // osc_isExpired, osc_deleteResource

Preference::newInstance(); // warm the preference map so osc_current_user_locale() never charges a query to a count pin

$cache        = Object_Cache_Factory::newInstance();
$itemTable    = DB_TABLE_PREFIX . 't_item';
$descTable    = DB_TABLE_PREFIX . 't_item_description';
$locTable     = DB_TABLE_PREFIX . 't_item_location';
$statsTable   = DB_TABLE_PREFIX . 't_item_stats';
$resTable     = DB_TABLE_PREFIX . 't_item_resource';
$commentTable = DB_TABLE_PREFIX . 't_item_comment';
$metaTable    = DB_TABLE_PREFIX . 't_item_meta';
$fieldTable   = DB_TABLE_PREFIX . 't_meta_fields';
$locale       = 'en_US';
$country      = 'US';

$model = Item::newInstance();

/* ----------------------------------------------------------------------------
 * Local helpers.
 * ------------------------------------------------------------------------- */
$flush = static function () use ($cache): void {
    $cache->flush();
};

// The shared DBCommandClass singleton keeps clause state per instance; only
// countByMarkas(null) leaks it in this model. Resetting it is a de-pollution on
// the legacy path and a harmless no-op on the converted one.
$resetDao = static function () use ($model): void {
    $model->dao->_resetSelect();
};

$countRows = static function (string $table, string $col, $val) use ($admin): int {
    $stmt = $admin->prepare("SELECT COUNT(*) c FROM $table WHERE $col = ?");
    $stmt->bind_param('s', $val);
    $stmt->execute();
    $c = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    return $c;
};
$itemExists = static function (int $id) use ($countRows, $itemTable): bool {
    return $countRows($itemTable, 'pk_i_id', $id) > 0;
};
// A minimal item row as callers hand it to extendData(): pk_i_id and
// fk_i_category_id as STRINGS, since extendData merges stats by a strict ===
// comparison of pk_i_id against the stats row's fk_i_item_id.
$stubRow = static function (int $id, int $catId): array {
    return array('pk_i_id' => (string) $id, 'fk_i_category_id' => (string) $catId);
};
$seedResource = static function (int $itemId, string $name, string $storage = 'local') use ($admin, $resTable): int {
    return seed_exec(
        $admin,
        "INSERT INTO $resTable (fk_i_item_id, s_name, s_extension, s_content_type, s_path, s_storage)
         VALUES (?, ?, 'jpg', 'image/jpeg', 'oc-content/uploads/0/', ?)",
        'iss',
        array($itemId, $name, $storage)
    );
};
$setItem = static function (int $id, string $assignments) use ($admin, $itemTable): void {
    $admin->query("UPDATE $itemTable SET $assignments WHERE pk_i_id = $id");
};
$setLocation = static function (int $id, string $assignments) use ($admin, $locTable): void {
    $admin->query("UPDATE $locTable SET $assignments WHERE fk_i_item_id = $id");
};

/* True when every SCALAR value in every row is a string or null; nested arrays
 * (the merged 'locale' sub-array) are ignored, since the legacy row carries it. */
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
$scalarStrings = static function (array $row) use ($rowsScalarStrings): bool {
    return $rowsScalarStrings(array($row));
};

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('Item: public surface');

check('Item still extends DAO', is_subclass_of('Item', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $itemTable, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array(
        'pk_i_id', 'fk_i_user_id', 'fk_i_category_id', 'dt_pub_date', 'dt_mod_date', 'f_price', 'i_price',
        'fk_c_currency_code', 's_contact_name', 's_contact_email', 's_contact_phone', 'b_premium', 's_ip',
        'b_enabled', 'b_active', 'b_spam', 's_secret', 'b_show_email', 'dt_expiration',
    ),
    $model->getFields()
);

pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('Item', 'newInstance'));
pin('__construct signature is unchanged', 'public __construct()', harness_method_signature('Item', '__construct'));
pin('extendData signature is unchanged', 'public extendData($items, $prefLocale = NULL)', harness_method_signature('Item', 'extendData'));
pin('extendDataSingle signature is unchanged', 'public extendDataSingle($item)', harness_method_signature('Item', 'extendDataSingle'));
pin('findByPrimaryKey signature is unchanged', 'public findByPrimaryKey($id)', harness_method_signature('Item', 'findByPrimaryKey'));
pin('listWhere signature is unchanged', 'public listWhere(...$args = <optional>)', harness_method_signature('Item', 'listWhere'));
pin(
    'findItemByTypes signature is unchanged',
    'public findItemByTypes($conditions = NULL, $itemType = false, $count = false, $limit = 0, $offset = NULL)',
    harness_method_signature('Item', 'findItemByTypes')
);
pin('totalItems signature is unchanged', 'public totalItems($categoryId = NULL, $options = NULL)', harness_method_signature('Item', 'totalItems'));
pin('numItems signature is unchanged', 'public numItems($category, $enabled = true, $active = true)', harness_method_signature('Item', 'numItems'));
pin('insertLocale signature is unchanged', 'public insertLocale($id, $locale, $title, $description)', harness_method_signature('Item', 'insertLocale'));
pin('updateLocaleForce signature is unchanged', 'public updateLocaleForce($id, $locale, $title, $text)', harness_method_signature('Item', 'updateLocaleForce'));
pin(
    'updateExpirationDate signature is unchanged',
    'public updateExpirationDate($id, $expiration_time, $do_stats = true)',
    harness_method_signature('Item', 'updateExpirationDate')
);
pin('clearStat signature is unchanged', 'public clearStat($id, $stat)', harness_method_signature('Item', 'clearStat'));
pin('enableByCategory signature is unchanged', 'public enableByCategory($enable, $aIds)', harness_method_signature('Item', 'enableByCategory'));
pin('deleteByPrimaryKey signature is unchanged', 'public deleteByPrimaryKey($id)', harness_method_signature('Item', 'deleteByPrimaryKey'));
pin('metaFields signature is unchanged', 'public metaFields($id)', harness_method_signature('Item', 'metaFields'));

pin(
    'the model declares exactly these public methods of its own, nothing added or removed',
    array(
        '__construct', 'clearStat', 'countByMarkas', 'countByUserID', 'countByUserIDEnabled',
        'countItemTypesByEmail', 'countItemTypesByUserID', 'deleteByCity', 'deleteByCityArea',
        'deleteByCountry', 'deleteByPrimaryKey', 'deleteByRegion', 'enableByCategory', 'extendCategoryName',
        'extendData', 'extendDataSingle', 'findByCategoryID', 'findByDayExpiration', 'findByEmail',
        'findByHourExpiration', 'findByPhone', 'findByPrimaryKey', 'findByUserID', 'findByUserIDEnabled',
        'findItemByTypes', 'findItemTypesByUserID', 'findLocationByID', 'findResourcesByID', 'insertLocale',
        'listAllWithCategories', 'listLatest', 'listWhere', 'metaFields', 'mostViewed', 'newInstance',
        'numItems', 'totalItems', 'updateExpirationDate', 'updateLocaleForce',
    ),
    (static function () {
        $own = array();
        foreach ((new ReflectionClass('Item'))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() === 'Item') {
                $own[] = $m->getName();
            }
        }
        sort($own);

        return $own;
    })()
);

/* ----------------------------------------------------------------------------
 * The primary read fixture. Item ids are captured for the pins below.
 *
 *   A: active, enabled, not-spam, not-expired, has an fr_FR locale + 2 resources
 *   B: active, DISABLED
 *   C: active, enabled, SPAM (with i_num_spam > 0)
 *   D: INACTIVE, enabled
 *   E: active, enabled, not-spam, EXPIRED (non-premium)
 * ------------------------------------------------------------------------- */
seed_locale($admin, $locale, 'English');
seed_locale($admin, 'fr_FR', 'French');
seed_country($admin, $country, 'United States');
$region   = seed_region($admin, $country, 'Alpha');
$city     = seed_city($admin, $region, 'Springfield', $country);
$cat      = seed_category($admin, 'Motors', null, $locale);
$sub      = seed_category($admin, 'Cars', $cat, $locale);
$user     = seed_user($admin);

$A = seed_item($admin, $cat, $user, 'Item A');
$B = seed_item($admin, $cat, $user, 'Item B');
$C = seed_item($admin, $cat, $user, 'Item C');
$D = seed_item($admin, $cat, $user, 'Item D');
$E = seed_item($admin, $cat, $user, 'Item E');

$setItem($B, 'b_enabled = 0');
$setItem($C, 'b_spam = 1');
$setItem($D, 'b_active = 0');
$setItem($E, "dt_expiration = '2000-01-01 00:00:00'");
$setItem($A, "dt_pub_date = '2026-01-01 00:00:00'");
$setItem($B, "dt_pub_date = '2026-01-02 00:00:00'");
$setItem($C, "dt_pub_date = '2026-01-03 00:00:00'");
$setItem($D, "dt_pub_date = '2026-01-04 00:00:00'");
$setItem($E, "dt_pub_date = '2026-01-05 00:00:00'");

// Location detail on A, so the merged l.* columns have real values to pin.
$setLocation($A, "fk_i_region_id = $region, s_region = 'Alpha', fk_i_city_id = $city, s_city = 'Springfield'");

// Stats: A has views, C has a spam count, B has the most views (for mostViewed).
$admin->query("UPDATE $statsTable SET i_num_views = 10, i_num_spam = 0 WHERE fk_i_item_id = $A");
$admin->query("UPDATE $statsTable SET i_num_views = 99 WHERE fk_i_item_id = $B");
$admin->query("UPDATE $statsTable SET i_num_spam = 3 WHERE fk_i_item_id = $C");

// A french description for A (so the locale merge has two locales).
seed_exec(
    $admin,
    "INSERT INTO $descTable (fk_i_item_id, fk_c_locale_code, s_title, s_description) VALUES (?, 'fr_FR', 'Titre A', 'Corps A')",
    'i',
    array($A)
);
$rA1 = $seedResource($A, 'a-res-1');
$rA2 = $seedResource($A, 'a-res-2');

/* ----------------------------------------------------------------------------
 * findByPrimaryKey — the return ledger, the merged row shape and C4.
 * ------------------------------------------------------------------------- */
harness_section('Item::findByPrimaryKey — the return ledger');

$flush();
pin('a null id returns an empty array without a query', array(), $model->findByPrimaryKey(null));
pin('a non-numeric id returns an empty array', array(), $model->findByPrimaryKey('abc'));
pin('an unknown id returns an empty array (no match)', array(), $model->findByPrimaryKey(999999));

$row = $model->findByPrimaryKey($A);
check('a match returns an array', is_array($row), describe($row));
pin('pk_i_id round-trips as a string (C4)', (string) $A, $row['pk_i_id']);
pin('b_active round-trips as a string (C4)', '1', $row['b_active']);
pin('the preferred-locale title is overlaid at the top level', 'Item A', $row['s_title']);
pin('the category name is overlaid at the top level', 'Motors', $row['s_category_name']);
check('every scalar value in the row is a string or null (C4)', $scalarStrings($row), describe($row));

harness_section('Item::findByPrimaryKey — the merged row carries stats sums, location cols, and the locale sub-array');

pin('a SUM stat aggregate is present and comes back as a string (DECIMAL, C4)', '10', $row['i_num_views']);
pin('a zeroed SUM stat is the string "0"', '0', $row['i_num_spam']);
pin('a joined location column (fk_c_country_code) is present', 'US', $row['fk_c_country_code']);
pin('a joined location column (s_region) is present', 'Alpha', $row['s_region']);
pin('a joined location int column round-trips as a string (C4)', (string) $region, $row['fk_i_region_id']);
check('the row carries a locale sub-array', isset($row['locale']) && is_array($row['locale']), describe($row));
check('the locale sub-array is keyed by locale code', isset($row['locale'][$locale]['s_title']), describe($row['locale']));
pin('the fr_FR locale is present in the sub-array too', 'Titre A', $row['locale']['fr_FR']['s_title']);
pin('the per-locale category name is folded into the locale sub-array', 'Motors', $row['locale'][$locale]['s_category_name']);

/* ----------------------------------------------------------------------------
 * extendData — the defended N+1: a fixed query count regardless of item count,
 * and the resource-cache prime it drives (C9).
 * ------------------------------------------------------------------------- */
harness_section('Item::extendData — the batch fan-out is flat in the item count');

// Five items, each with a description, a location, a stats row and one resource.
$batchIds = array();
for ($i = 0; $i < 5; $i++) {
    $id = seed_item($admin, $cat, $user, "Batch $i");
    $seedResource($id, "batch-$i");
    $batchIds[] = $id;
}
$two  = array($stubRow($batchIds[0], $cat), $stubRow($batchIds[1], $cat));
$five = array_map(static function ($id) use ($stubRow, $cat) {
    return $stubRow($id, $cat);
}, $batchIds);

$resetDao();
$flush();
$qTwo = harness_query_count(static function () use ($model, $two) {
    $model->extendData($two);
});
$flush();
$qFive = harness_query_count(static function () use ($model, $five) {
    $model->extendData($five);
});
pin('extendData over 2 items issues a fixed 4 queries (desc + category + stats/location + prime)', 4, $qTwo);
pin('extendData over 5 items issues the SAME 4 queries — the batch is flat, not per-item', 4, $qFive);

harness_section('Item::extendData — a single item skips the prime (no >1 fan-out)');

$flush();
$qOne = harness_query_count(static function () use ($model, $stubRow, $batchIds, $cat) {
    $model->extendData(array($stubRow($batchIds[0], $cat)));
});
pin('extendData over 1 item issues 3 queries — the prime only fires for >1 item', 3, $qOne);

pin('extendData over an empty set issues no query and returns it unchanged', 0, harness_query_count(static function () use ($model) {
    $model->extendData(array());
}));
pin('extendData returns an empty array for empty input', array(), $model->extendData(array()));

harness_section('Item::extendData — it primes the resource cache so a following per-item read is free (C9)');

$flush();
$model->extendData($five);
$primedCost = harness_query_count(static function () use ($batchIds) {
    ItemResource::newInstance()->getAllResourcesFromItem($batchIds[0]);
});
pin('after extendData over the set, a per-item resource read costs zero queries — the prime seeded it', 0, $primedCost);
pin('...and returns that item\'s one seeded resource', 1, count(ItemResource::newInstance()->getAllResourcesFromItem($batchIds[0])));

harness_section('Item::findByPrimaryKey / extendDataSingle — the single-row path');

$flush();
$single = $model->extendDataSingle(array('pk_i_id' => (string) $A, 'fk_i_category_id' => (string) $cat));
pin('extendDataSingle returns one merged row (the [0] of a one-element extendData)', 'Item A', $single['s_title']);
$flush();
$qFbpk = harness_query_count(static function () use ($model, $A) {
    $model->findByPrimaryKey($A);
});
pin('findByPrimaryKey costs a fixed 4 queries (own read + the 3-query single-item extendData)', 4, $qFbpk);

/* ----------------------------------------------------------------------------
 * listWhere / findByCategoryID — the comodin and its printf form.
 * ------------------------------------------------------------------------- */
harness_section('Item::listWhere / findByCategoryID');

$resetDao();
$flush();
pin('listWhere() with no argument returns an empty array', array(), $model->listWhere());

$byCat = $model->findByCategoryID($cat);
check('findByCategoryID returns extended rows', is_array($byCat) && isset($byCat[0]['s_title']), describe($byCat));
pin('every item in the category comes back (A-E + 5 batch)', 10, count($byCat));
check('the joined l.*/i.* rows are all strings or null (C4)', $rowsScalarStrings($byCat), describe($byCat[0]));

$rawWhere = $model->listWhere('i.pk_i_id = ' . (int) $A);
pin('the single-argument raw form filters to the one item', 1, count($rawWhere));
pin('...and returns the extended row', 'Item A', $rawWhere[0]['s_title']);
pin('a raw fragment that matches nothing returns an empty array', array(), $model->listWhere('i.pk_i_id = 999999'));

/* ----------------------------------------------------------------------------
 * listLatest / mostViewed / listAllWithCategories — ordering.
 * ------------------------------------------------------------------------- */
harness_section('Item::listLatest — ORDER BY dt_pub_date DESC embedded in the raw fragment');

$resetDao();
$latest = $model->listLatest(3);
pin('listLatest returns the requested count', 3, count($latest));
// Every item defaults to dt_expiration NOW()+30d; the batch items have the most
// recent pub dates, so they lead. Only b_active=1 AND b_enabled=1 qualify.
pin('rows are ordered newest pub date first', true, ($latest[0]['dt_pub_date'] >= $latest[1]['dt_pub_date']) && ($latest[1]['dt_pub_date'] >= $latest[2]['dt_pub_date']));

harness_section('Item::mostViewed — ORDER BY i_num_views DESC');

$resetDao();
$mv = $model->mostViewed(3);
pin('mostViewed returns the requested count', 3, count($mv));
pin('the most-viewed item (B, 99 views) leads', 'Item B', $mv[0]['s_title']);

harness_section('Item::listAllWithCategories');

$resetDao();
$allCat = $model->listAllWithCategories();
pin('listAllWithCategories returns a row per item-category description', 10, count($allCat));
check('each row carries the joined category name', isset($allCat[0]['s_category_name']), describe($allCat[0]));

/* ----------------------------------------------------------------------------
 * findItemByTypes and the user finders — list, count, order, paging.
 * ------------------------------------------------------------------------- */
harness_section('Item::findItemByTypes — list vs count');

$resetDao();
pin('the count form of all items returns a string total', '10', $model->findItemByTypes(null, 'all', true));
$listAll = $model->findItemByTypes(null, 'all');
pin('the list form of all items returns every row', 10, count($listAll));
check('the list rows are extended and all-strings (C4)', $rowsScalarStrings($listAll) && isset($listAll[0]['s_title']), describe($listAll[0]));
pin('the list is ordered dt_pub_date DESC', true, $listAll[0]['dt_pub_date'] >= $listAll[1]['dt_pub_date']);

harness_section('Item::findItemByTypes — LIMIT/OFFSET (count and offset are not swapped)');

$resetDao();
// Two-argument limit($limit, $offset) compiles LIMIT offset, count -> $offset is
// the offset. Order is dt_pub_date DESC, so the batch items lead.
$page0 = $model->findItemByTypes(null, 'all', false, 2, 0);
$page1 = $model->findItemByTypes(null, 'all', false, 2, 2);
pin('offset 0 returns the first 2', 2, count($page0));
pin('offset 2 returns the next 2', 2, count($page1));
check('the two pages do not overlap', $page0[0]['pk_i_id'] !== $page1[0]['pk_i_id'], describe(array($page0[0]['pk_i_id'], $page1[0]['pk_i_id'])));

harness_section('Item::findItemByTypes — the itemType filters (the orWhere-precedence structure)');

$resetDao();
// These exercise addWhereByType()/addWhereByOptions(), including the NOTEXPIRED
// case whose orWhere('i.b_premium', 1) makes the WHERE a mix of AND and OR. The
// exact counts are pinned by observation so the conversion must reproduce the
// same clause structure, not a "tidied" one.
pin('active-type count', '8', $model->findItemByTypes(null, 'active', true));
pin('blocked-type count (disabled items)', '1', $model->findItemByTypes(null, 'blocked', true));
pin('expired-type count', '1', $model->findItemByTypes(null, 'expired', true));
pin('premium-type count', '0', $model->findItemByTypes(null, 'premium', true));
pin('pending-type count (inactive)', '1', $model->findItemByTypes(null, 'pending', true));
pin('default-type (unspecified) count', '8', $model->findItemByTypes(null, false, true));

harness_section('Item::findByUserID / findByUserIDEnabled / findItemTypesByUserID');

$resetDao();
pin('findByUserID lists all the user\'s items', 10, count($model->findByUserID($user)));
pin('findByUserID for an unknown user is empty', array(), $model->findByUserID(999999));
pin('countByUserIDEnabled (enabled itemType)', '8', $model->countByUserIDEnabled($user));

/* ----------------------------------------------------------------------------
 * The count family. Counts come back as strings; the null/error branches as int 0.
 * ------------------------------------------------------------------------- */
harness_section('Item::countByUserID / countByUserIDEnabled / countItemTypesByUserID / countItemTypesByEmail');

$resetDao();
pin('countByUserID returns a string total of all the user\'s items', '10', $model->countByUserID($user));
pin('countByUserID for an unknown user is the string "0"', '0', $model->countByUserID(999999));
pin('countItemTypesByUserID with an extra literal condition narrows the count', '1', $model->countItemTypesByUserID($user, 'all', 'b_spam = 1'));
pin('countItemTypesByEmail counts a contact-email match', '10', $model->countItemTypesByEmail('contact@example.test', 'all'));
pin('countItemTypesByEmail for an unknown email is the string "0"', '0', $model->countItemTypesByEmail('nobody@example.test', 'all'));

harness_section('Item::countByMarkas — string counts, and the null-type int-0 leak branch');

$resetDao();
pin('countByMarkas(spam) counts items with a spam stat and b_spam=0, as a string', '0', $model->countByMarkas('spam'));
// C has i_num_spam>0 but b_spam=1, so it is excluded from the "spam" moderation
// count; give A a spam stat with b_spam=0 to prove a positive match.
$admin->query("UPDATE $statsTable SET i_num_spam = 4 WHERE fk_i_item_id = $A");
$resetDao();
pin('an item with a spam stat and b_spam=0 is counted', '1', $model->countByMarkas('spam'));
$admin->query("UPDATE $statsTable SET i_num_spam = 0 WHERE fk_i_item_id = $A");
$resetDao();
pin('countByMarkas(null) returns int 0 through the early branch that never runs a query', 0, $model->countByMarkas(null));
$resetDao(); // that branch leaks clause state on the legacy path; clear it

/* ----------------------------------------------------------------------------
 * totalItems — the options filter.
 * ------------------------------------------------------------------------- */
harness_section('Item::totalItems');

$resetDao();
pin('totalItems() counts every item as a string', '10', $model->totalItems());
pin('totalItems(cat, ACTIVE) counts active items', '9', $model->totalItems($cat, 'ACTIVE'));
pin('totalItems(cat, INACTIVE) counts inactive items', '1', $model->totalItems($cat, 'INACTIVE'));
pin('totalItems(cat, DISABLED) counts disabled items', '1', $model->totalItems($cat, 'DISABLED'));
pin('totalItems(cat, SPAM) counts spam items', '1', $model->totalItems($cat, 'SPAM'));
pin('totalItems(cat, PREMIUM) counts premium items', '0', $model->totalItems($cat, 'PREMIUM'));
pin('totalItems(cat, EXPIRED) counts non-premium expired items', '1', $model->totalItems($cat, 'EXPIRED'));
pin('a category with no items counts the string "0"', '0', $model->totalItems($sub));

/* ----------------------------------------------------------------------------
 * numItems — the sitemap counter with the unparenthesised || premium clause,
 * preserved verbatim.
 * ------------------------------------------------------------------------- */
harness_section('Item::numItems');

$resetDao();
// Enabled AND active AND not-spam AND (b_premium = 1 || dt_expiration >= now):
// only A qualifies (B disabled, C spam, D inactive, E expired non-premium, batch
// items all qualify too -> 6). Count them precisely from the fixture.
pin('numItems counts enabled, active, non-spam, unexpired-or-premium items', '6', $model->numItems(array('pk_i_id' => $cat)));

/* ----------------------------------------------------------------------------
 * findByHourExpiration / findByDayExpiration — the NOW() legacy clock.
 * ------------------------------------------------------------------------- */
harness_section('Item::findByHourExpiration / findByDayExpiration');

$resetDao();
// A dedicated item expiring ~24.5h out, so TIMESTAMPDIFF(HOUR, NOW(), dt) == 24
// for the width of the test run. Active, enabled, not-spam so it survives the
// default itemType filter.
$hourItem = seed_item($admin, $cat, $user, 'Expiring soon');
$admin->query("UPDATE $itemTable SET dt_expiration = DATE_ADD(NOW(), INTERVAL '24:30' HOUR_MINUTE) WHERE pk_i_id = $hourItem");
$flush();
$hourHits = $model->findByHourExpiration(24);
check('findByHourExpiration(24) finds the ~24h item', count($hourHits) >= 1, describe(count($hourHits)));
$resetDao();
pin('findByHourExpiration(1) matches nothing here', array(), $model->findByHourExpiration(1));

$resetDao();
$dayItem = seed_item($admin, $cat, $user, 'Expiring in a day');
$admin->query("UPDATE $itemTable SET dt_expiration = DATE_ADD(NOW(), INTERVAL '1 12' DAY_HOUR) WHERE pk_i_id = $dayItem");
$flush();
$dayHits = $model->findByDayExpiration(1);
check('findByDayExpiration(1) finds the ~1.5-day item', count($dayHits) >= 1, describe(count($dayHits)));

/* ----------------------------------------------------------------------------
 * metaFields.
 * ------------------------------------------------------------------------- */
harness_section('Item::metaFields');

$resetDao();
pin('metaFields for an item with no meta returns an empty array', array(), $model->metaFields($A));
pin('metaFields for an unknown item returns an empty array', array(), $model->metaFields(999999));

$fieldId = seed_exec(
    $admin,
    "INSERT INTO $fieldTable (s_name, e_type, b_required, b_searchable, s_slug, i_position, s_meta)
     VALUES ('Color', 'TEXT', 0, 1, 'color', 0, '')",
    '',
    array()
);
seed_exec(
    $admin,
    "INSERT INTO $metaTable (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES (?, ?, 'Red', '')",
    'ii',
    array($A, $fieldId)
);
$meta = $model->metaFields($A);
pin('metaFields is keyed by the field primary key', array($fieldId), array_map('intval', array_keys($meta)));
pin('...and carries the field name and value', 'Red', $meta[$fieldId]['s_value']);

/* ----------------------------------------------------------------------------
 * findResourcesByID / findLocationByID — thin delegations.
 * ------------------------------------------------------------------------- */
harness_section('Item::findResourcesByID / findLocationByID');

$flush();
pin('findResourcesByID returns the item\'s resources', 2, count($model->findResourcesByID($A)));
$loc = $model->findLocationByID($A);
check('findLocationByID returns the location row', is_array($loc) && $loc['fk_c_country_code'] === 'US', describe($loc));

/* ----------------------------------------------------------------------------
 * Writes: insertLocale / updateLocaleForce / clearStat.
 * ------------------------------------------------------------------------- */
harness_section('Item::insertLocale / updateLocaleForce');

$resetDao();
$writeItem = seed_item($admin, $cat, $user, 'Write target');
$descLocaleCount = static function (int $itemId, string $loc) use ($admin, $descTable): int {
    $stmt = $admin->prepare("SELECT COUNT(*) c FROM $descTable WHERE fk_i_item_id = ? AND fk_c_locale_code = ?");
    $stmt->bind_param('is', $itemId, $loc);
    $stmt->execute();
    $c = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    return $c;
};
pin('insertLocale returns bool true on success', true, $model->insertLocale($writeItem, 'fr_FR', 'FR titre', 'FR corps'));
pin('the description row was written', 1, $descLocaleCount($writeItem, 'fr_FR'));
pin('updateLocaleForce (REPLACE) returns bool true on success', true, $model->updateLocaleForce($writeItem, 'fr_FR', 'FR titre 2', 'FR corps 2'));
pin('...and the REPLACE overwrote the title', 'FR titre 2', (static function () use ($admin, $descTable, $writeItem) {
    $stmt = $admin->prepare("SELECT s_title FROM $descTable WHERE fk_i_item_id = ? AND fk_c_locale_code = 'fr_FR'");
    $stmt->bind_param('i', $writeItem);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc()['s_title'];
    $stmt->close();

    return $v;
})());

harness_section('Item::clearStat');

$resetDao();
$statItem = seed_item($admin, $cat, $user, 'Stat target');
$admin->query("UPDATE $statsTable SET i_num_spam = 5, i_num_repeated = 5, i_num_bad_classified = 5, i_num_offensive = 5, i_num_expired = 5 WHERE fk_i_item_id = $statItem");
$statValue = static function (string $col) use ($admin, $statsTable, $statItem): int {
    return (int) $admin->query("SELECT $col FROM $statsTable WHERE fk_i_item_id = $statItem")->fetch_assoc()[$col];
};
$ret = $model->clearStat($statItem, 'spam');
check('clearStat returns the affected-row count (int)', is_int($ret), describe($ret));
pin('clearStat(spam) zeroed i_num_spam', 0, $statValue('i_num_spam'));
pin('...and left i_num_repeated untouched', 5, $statValue('i_num_repeated'));
pin('clearStat with an unknown stat returns null and writes nothing', null, $model->clearStat($statItem, 'nope'));
pin('...leaving i_num_repeated untouched', 5, $statValue('i_num_repeated'));
$model->clearStat($statItem, 'all');
pin('clearStat(all) zeroes every counter', 0, $statValue('i_num_repeated') + $statValue('i_num_bad_classified') + $statValue('i_num_offensive') + $statValue('i_num_expired'));

/* ----------------------------------------------------------------------------
 * updateExpirationDate — both SET branches and the stats side effects.
 * ------------------------------------------------------------------------- */
harness_section('Item::updateExpirationDate — the guard and the two write branches');

$resetDao();
$expItem = seed_item($admin, $cat, $user, 'Expiration target');
pin('a falsy expiration_time returns false without touching the row', false, $model->updateExpirationDate($expItem, false));
pin('a "0" expiration_time is falsy too and returns false', false, $model->updateExpirationDate($expItem, '0'));

// Numeric string > 0: the DATE_ADD(dt_pub_date, INTERVAL n DAY) expression branch,
// written UNescaped. Returns the freshly computed dt_expiration string.
$expDays = $model->updateExpirationDate($expItem, '7', false);
check('the DATE_ADD branch returns a datetime string', is_string($expDays) && strlen($expDays) === 19, describe($expDays));
pin('...and the row now holds it', $expDays, (static function () use ($admin, $itemTable, $expItem) {
    return $admin->query("SELECT dt_expiration FROM $itemTable WHERE pk_i_id = $expItem")->fetch_assoc()['dt_expiration'];
})());

// A literal datetime: the escaped/bound branch.
$expLiteral = $model->updateExpirationDate($expItem, '2030-06-15 12:00:00', false);
pin('a literal datetime is written verbatim through the escaped branch', '2030-06-15 12:00:00', $expLiteral);

// The <= 0 numeric branch (non-falsy '00') writes the 9999 sentinel literal.
$expSentinel = $model->updateExpirationDate($expItem, '00', false);
pin('a non-falsy zero-ish numeric writes the 9999 sentinel', '9999-12-31 23:59:59', $expSentinel);

harness_section('Item::updateExpirationDate — the stats side effects on an expiry transition');

$resetDao();
// A fresh live item (active, enabled, not-spam, not-expired) counts toward the
// user/category/country/region/city stats. Expiring it (transition unexpired ->
// expired) must decrement them; the return is the new dt_expiration.
$txItem = seed_item($admin, $cat, $user, 'Transition target');
$setLocation($txItem, "fk_i_region_id = $region, fk_i_city_id = $city");
// Give the user a known positive item count so the decrement is observable (a
// raw-seeded item never incremented the stats, so start it deterministically).
$admin->query("UPDATE " . DB_TABLE_PREFIX . "t_user SET i_items = 5 WHERE pk_i_id = $user");
$expired = $model->updateExpirationDate($txItem, '2000-01-01 00:00:00', true);
pin('expiring a live item returns the new (past) dt_expiration', '2000-01-01 00:00:00', $expired);
$userItemsAfter = (int) $admin->query("SELECT i_items FROM " . DB_TABLE_PREFIX . "t_user WHERE pk_i_id = $user")->fetch_assoc()['i_items'];
pin('the unexpired->expired transition decremented the user item count by one', 4, $userItemsAfter);

/* ----------------------------------------------------------------------------
 * enableByCategory — the bulk update and its announce hook.
 * ------------------------------------------------------------------------- */
harness_section('Item::enableByCategory');

$resetDao();
$bulkCat  = seed_category($admin, 'BulkCat', null, $locale);
$bulk1    = seed_item($admin, $bulkCat, $user, 'Bulk 1');
$bulk2    = seed_item($admin, $bulkCat, $user, 'Bulk 2');
$hookSeen = array();
osc_add_hook('items_bulk_enabled_by_category', static function ($ids, $enable) use (&$hookSeen) {
    $hookSeen = array('ids' => $ids, 'enable' => $enable);
});
$enRet = $model->enableByCategory(0, array($bulkCat));
check('enableByCategory returns a truthy result on success', $enRet !== false, describe($enRet));
pin('both items in the category were disabled', 0, (int) $admin->query("SELECT SUM(b_enabled) s FROM $itemTable WHERE fk_i_category_id = $bulkCat")->fetch_assoc()['s']);
pin('the announce hook fired with the int category ids', array((int) $bulkCat), $hookSeen['ids']);
pin('...and the new enable value', 0, $hookSeen['enable']);
pin('an empty id list short-circuits to false without a query', false, $model->enableByCategory(1, array()));

/* ----------------------------------------------------------------------------
 * The delete cascade — a survivor city proves it is scoped, and the per-item
 * before/after_delete_item hooks fire. Destructive, so late and isolated.
 * ------------------------------------------------------------------------- */
harness_section('Item::deleteByCity — cascade, survivor, and the per-item hooks');

$resetDao();
$flush();
$cityX = seed_city($admin, $region, 'CityX', $country);
$cityY = seed_city($admin, $region, 'CityY', $country);

$doom1 = seed_item($admin, $cat, $user, 'Doomed 1');
$doom2 = seed_item($admin, $cat, $user, 'Doomed 2');
$setLocation($doom1, "fk_i_city_id = $cityX");
$setLocation($doom2, "fk_i_city_id = $cityX");
$seedResource($doom1, 'doom-res');
seed_exec($admin, "INSERT INTO $commentTable (fk_i_item_id, fk_i_user_id, s_title, s_body, s_author_name, s_author_email, b_active, b_enabled, dt_pub_date) VALUES (?, ?, 't', 'b', 'n', 'e@x.test', 1, 1, NOW())", 'ii', array($doom1, $user));
seed_exec($admin, "INSERT INTO $metaTable (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES (?, ?, 'v', '')", 'ii', array($doom1, $fieldId));

$survivor = seed_item($admin, $cat, $user, 'Survivor');
$setLocation($survivor, "fk_i_city_id = $cityY");

$before = 0;
$after  = 0;
osc_add_hook('before_delete_item', static function ($id) use (&$before) {
    $before++;
});
osc_add_hook('after_delete_item', static function ($id, $item) use (&$after) {
    $after++;
});

$flush();
$deleted = $model->deleteByCity($cityX);
check('deleteByCity returns the accumulated affected-row count', is_int($deleted) && $deleted >= 2, describe($deleted));
pin('before_delete_item fired once per doomed item', 2, $before);
pin('after_delete_item fired once per doomed item', 2, $after);

check('the first doomed item is gone', !$itemExists($doom1));
check('the second doomed item is gone', !$itemExists($doom2));
pin('the doomed description rows are gone', 0, $countRows($descTable, 'fk_i_item_id', $doom1) + $countRows($descTable, 'fk_i_item_id', $doom2));
pin('the doomed location rows are gone', 0, $countRows($locTable, 'fk_i_item_id', $doom1) + $countRows($locTable, 'fk_i_item_id', $doom2));
pin('the doomed stats rows are gone', 0, $countRows($statsTable, 'fk_i_item_id', $doom1) + $countRows($statsTable, 'fk_i_item_id', $doom2));
pin('the doomed resource row is gone', 0, $countRows($resTable, 'fk_i_item_id', $doom1));
pin('the doomed comment row is gone', 0, $countRows($commentTable, 'fk_i_item_id', $doom1));
pin('the doomed meta row is gone', 0, $countRows($metaTable, 'fk_i_item_id', $doom1));

check('the survivor in the other city is untouched', $itemExists($survivor));
pin('the survivor keeps its description row', 1, $countRows($descTable, 'fk_i_item_id', $survivor));

harness_section('Item::deleteByPrimaryKey — direct delete of a single item');

$resetDao();
$flush();
$directDoom = seed_item($admin, $cat, $user, 'Direct doom');
$seedResource($directDoom, 'direct-res');
$directRet = $model->deleteByPrimaryKey($directDoom);
check('deleteByPrimaryKey returns the affected-row count', is_int($directRet) || $directRet === false, describe($directRet));
check('the item is gone', !$itemExists($directDoom));
pin('its resource rows are gone', 0, $countRows($resTable, 'fk_i_item_id', $directDoom));
// findByPrimaryKey(unknown) returns array() (not null), so the null-guard is not
// taken; the cascade runs over zero rows and parent::deleteByPrimaryKey() reports
// int 0 affected.
pin('deleting an unknown id returns int 0 (the null-guard sees array(), not null)', 0, $model->deleteByPrimaryKey(999999));

harness_section('Item::deleteByCity / deleteByRegion / deleteByCountry / deleteByCityArea — empty scopes');

$resetDao();
$flush();
pin('deleteByCity on a city with no items returns int 0', 0, $model->deleteByCity(888881));
pin('deleteByRegion on a region with no items returns int 0', 0, $model->deleteByRegion(888882));
pin('deleteByCountry on a country with no items returns int 0', 0, $model->deleteByCountry('ZZ'));
pin('deleteByCityArea on a city area with no items returns int 0', 0, $model->deleteByCityArea(888883));

/* ----------------------------------------------------------------------------
 * Amendment T — the deliberate coercion drop. These pins assert the CONVERTED
 * behaviour (a VARCHAR column bound as a string), so they are RED against the
 * pre-conversion model, which compiled a bare numeric comparison in which
 * '00555' equals '555'. They flip green once findByPhone()/findByEmail() bind
 * their value as a string. Nothing above depends on these fixtures.
 * ------------------------------------------------------------------------- */
harness_section('Item::findByPhone / findByEmail — amendment T (RED until converted)');

$resetDao();
$flush();
$phoneA = seed_item($admin, $cat, $user, 'Phone 555');
$phoneB = seed_item($admin, $cat, $user, 'Phone 00555');
$setItem($phoneA, "s_contact_phone = '555'");
$setItem($phoneB, "s_contact_phone = '00555'");
pin(
    '[amendment T] findByPhone("555") binds a string, so only the exact "555" matches (legacy coerced and matched "00555" too)',
    1,
    count($model->findByPhone('555'))
);

$resetDao();
$flush();
$mailA = seed_item($admin, $cat, $user, 'Mail 123');
$mailB = seed_item($admin, $cat, $user, 'Mail 00123');
$setItem($mailA, "s_contact_email = '123'");
$setItem($mailB, "s_contact_email = '00123'");
pin(
    '[amendment T] findByEmail("123") binds a string, so only the exact "123" matches (legacy coerced and matched "00123" too)',
    1,
    count($model->findByEmail('123'))
);

/* ----------------------------------------------------------------------------
 * Leave a clean slate for the next file under the runner: the shared DAO
 * clause state and the object cache.
 * ------------------------------------------------------------------------- */
$resetDao();
$flush();

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/item.php */
