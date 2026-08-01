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
 * Characterization pins for the Search model (P6 step S1).
 *
 * Search is a stateful SQL-string assembler, and the migration keeps it that way
 * — the conversion parameterizes the values it inlines rather than rewriting it
 * onto the query builder. This file captures the observable contract that
 * conversion must preserve: what a search returns for a matrix of realistic
 * filters, the total counts, the sort orders, and the serialized-alert
 * round-trip that lets an old t_alerts row keep working.
 *
 * The bootstrap mirrors tests/models/item.php: doSearch reaches
 * Item::extendData, which pulls in the cache, locale and hook helpers, so the
 * real helper files are required (idempotent) with guarded stand-ins for the
 * hDefines helpers that a model test cannot load.
 *
 * Usage:  php tests/models/search.php          (standalone, own scratch database)
 *         php tests/run-models.php search      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_search');

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
if (!function_exists('__')) {
    function __($key, $domain = 'core')
    {
        return $key;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';

$prefix = DB_TABLE_PREFIX;

/* ----------------------------------------------------------------------------
 * Fixture: two categories, two locations, five items with a range of prices,
 * premium flags and a searchable pattern in their descriptions.
 * ------------------------------------------------------------------------- */
$locale   = seed_locale($admin);
$country  = seed_country($admin, 'US', 'United States');
$regionA  = seed_region($admin, $country, 'Alpha');
$regionB  = seed_region($admin, $country, 'Beta');
$cityA    = seed_city($admin, $regionA, 'Aville');
$cityB    = seed_city($admin, $regionB, 'Bville');
seed_currency($admin);
$catCars  = seed_category($admin, 'Cars', null, $locale);
$catBikes = seed_category($admin, 'Bikes', null, $locale);
$user     = seed_user($admin, 'seller', 'seller@example.test');

/**
 * Seed an active, enabled item with a title/description, price, category and a
 * location that matches its region/city, plus a stats row. Returns the id.
 */
$mkItem = static function (
    string $title,
    int $category,
    float $price,
    int $premium,
    int $regionId,
    int $cityId,
    string $regionName,
    string $cityName
) use ($admin, $prefix, $locale, $country, $user): int {
    $id = seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_item
         (fk_i_user_id, fk_i_category_id, dt_pub_date, dt_mod_date, f_price, i_price,
          fk_c_currency_code, s_contact_name, s_contact_email, s_ip, b_premium,
          b_enabled, b_active, b_spam, s_secret, dt_expiration)
         VALUES (?, ?, NOW(), NOW(), ?, ?, 'USD', 'Seller', 'seller@example.test',
                 '127.0.0.1', ?, 1, 1, 0, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))",
        'iidiis',
        array($user, $category, $price, (int)round($price * 1000000), $premium, 'sec' . $title)
    );
    seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_item_description (fk_i_item_id, fk_c_locale_code, s_title, s_description)
         VALUES (?, ?, ?, ?)",
        'isss',
        array($id, $locale, $title, $title . ' body vintage collectible')
    );
    seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_item_location
         (fk_i_item_id, fk_c_country_code, s_country, fk_i_region_id, s_region, fk_i_city_id, s_city)
         VALUES (?, ?, 'United States', ?, ?, ?, ?)",
        'isisss',
        array($id, $country, $regionId, $regionName, $cityId, $cityName)
    );
    seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_item_stats (fk_i_item_id, dt_date) VALUES (?, CURDATE())",
        'i',
        array($id)
    );

    return $id;
};

$car1 = $mkItem('Red Roadster', $catCars, 5000.0, 0, $regionA, $cityA, 'Alpha', 'Aville');
$car2 = $mkItem('Blue Sedan', $catCars, 12000.0, 1, $regionA, $cityA, 'Alpha', 'Aville');
$bike1 = $mkItem('Mountain Bike', $catBikes, 800.0, 0, $regionB, $cityB, 'Beta', 'Bville');
$bike2 = $mkItem('Racing Bike', $catBikes, 2500.0, 1, $regionB, $cityB, 'Beta', 'Bville');
$car3 = $mkItem('Green Coupe', $catCars, 9000.0, 0, $regionB, $cityB, 'Beta', 'Bville');

/*
 * addCategory() resolves through the Category singleton's in-memory tree, which
 * is built once per process. Under the suite runner an earlier model file has
 * already built it against a different fixture, so reset the singleton and flush
 * the request cache here — standalone this is a harmless no-op, but it lets the
 * category-filtered pins below see this file's own categories.
 */
if (class_exists('Object_Cache_Factory')) {
    Object_Cache_Factory::newInstance()->flush();
}
$searchCategoryReset = new ReflectionProperty('Category', 'instance');
$searchCategoryReset->setAccessible(true);
$searchCategoryReset->setValue(null, null);

/** Collect the pk_i_id column from a doSearch result. */
$ids = static function (array $rows): array {
    return array_map('intval', array_column($rows, 'pk_i_id'));
};
$sorted = static function (array $a): array {
    sort($a);
    return $a;
};

/* ----------------------------------------------------------------------------
 * Surface (C2).
 * ------------------------------------------------------------------------- */
harness_section('Search: public surface');

$s = Search::newInstance();
check('Search still extends DAO', is_subclass_of('Search', 'DAO'));
check('$s->dao is a live DBCommandClass (C5)', $s->dao instanceof DBCommandClass);
pin('table name is unchanged', $prefix . 't_item', $s->getTableName());
pin('doSearch signature is unchanged', 'public doSearch($extended = true, $count = true)', harness_method_signature('Search', 'doSearch'));
pin('addPattern signature is unchanged', 'public addPattern($pattern)', harness_method_signature('Search', 'addPattern'));
pin('setJsonAlert signature is unchanged', 'public setJsonAlert($aData)', harness_method_signature('Search', 'setJsonAlert'));
pin(
    'the sort-column allowlist is unchanged',
    array('i_price', 'dt_pub_date', 'dt_expiration', 'relevance'),
    Search::getAllowedColumnsForSorting()
);

/* ----------------------------------------------------------------------------
 * Unfiltered search — every active item.
 * ------------------------------------------------------------------------- */
harness_section('Search: unfiltered');

$s = new Search();
$all = $s->doSearch();
pin('an unfiltered search returns all five active items', 5, count($all));
check('every row is all-strings (C4)', all_values_string(array_diff_key($all[0], array('locale' => 1, 'category' => 1))));

$s = new Search();
$s->doSearch();
pin('total_results counts every match', 5, (int)$s->count());

/* ----------------------------------------------------------------------------
 * Filter by category.
 * ------------------------------------------------------------------------- */
harness_section('Search: by category');

$s = new Search();
$s->addCategory($catCars);
$cars = $s->doSearch();
pin('a category filter returns only its items', $sorted(array($car1, $car2, $car3)), $sorted($ids($cars)));

$s = new Search();
$s->addCategory($catBikes);
pin('the other category returns its own', $sorted(array($bike1, $bike2)), $sorted($ids($s->doSearch())));

/* ----------------------------------------------------------------------------
 * Filter by price range.
 * ------------------------------------------------------------------------- */
harness_section('Search: by price range');

$s = new Search();
$s->priceRange(1000, 6000);
pin('a price range returns only items inside it', $sorted(array($car1, $bike2)), $sorted($ids($s->doSearch())));

$s = new Search();
$s->priceRange(10000, null);
pin('an open-topped range returns the dear items', $sorted(array($car2)), $sorted($ids($s->doSearch())));

/* ----------------------------------------------------------------------------
 * Filter by pattern (this is the LIKE path — wildcard behaviour matters).
 * ------------------------------------------------------------------------- */
harness_section('Search: by pattern');

/* The pattern path runs a FULLTEXT MATCH ... AGAINST. Whether a given word
 * matches depends on the server's FULLTEXT index and token settings, so this
 * pins the behaviour the conversion must preserve — the path is exercised, is
 * safe against a quote in the pattern, and returns a well-formed result — rather
 * than an exact match count that would over-fit one MySQL build. The conversion
 * changes how the pattern value reaches the SQL, not what MATCH matches. */
$s = new Search();
$s->addPattern('collectible');
$patResult = $s->doSearch();
check('a pattern search returns a well-formed array', is_array($patResult));

$s = new Search();
$s->addPattern("qu'ote");
check('a pattern containing a quote is safe and returns an array', is_array($s->doSearch()));

$s = new Search();
$s->addPattern('%wild_card%');
check('a pattern with SQL wildcard characters is safe', is_array($s->doSearch()));

/* ----------------------------------------------------------------------------
 * Premium only.
 * ------------------------------------------------------------------------- */
harness_section('Search: premium');

$s = new Search();
$premiums = $s->getPremiums(10);
pin('getPremiums returns the premium items', $sorted(array($car2, $bike2)), $sorted($ids($premiums)));

/* ----------------------------------------------------------------------------
 * Sorting.
 * ------------------------------------------------------------------------- */
harness_section('Search: sorting');

$s = new Search();
$s->order('i_price', 'ASC');
$byPriceAsc = $ids($s->doSearch());
pin('sort by price ascending puts the cheapest first', $bike1, $byPriceAsc[0]);
pin('and the dearest last', $car2, $byPriceAsc[count($byPriceAsc) - 1]);

$s = new Search();
$s->order('i_price', 'DESC');
pin('sort by price descending puts the dearest first', $car2, $ids($s->doSearch())[0]);

/* ----------------------------------------------------------------------------
 * Pagination (offset and count differ so a swap would fail).
 * ------------------------------------------------------------------------- */
harness_section('Search: pagination');

$s = new Search();
$s->order('i_price', 'ASC');
$s->page(0, 2);
$firstPage = $ids($s->doSearch());
pin('the first page holds the two cheapest', array($bike1, $bike2), $firstPage);

$s = new Search();
$s->order('i_price', 'ASC');
$s->page(1, 2);
$secondPage = $ids($s->doSearch());
pin('the second page holds the next two', array($car1, $car3), $secondPage);

/* ----------------------------------------------------------------------------
 * The serialized-alert round-trip (the compat boundary with t_alerts).
 * ------------------------------------------------------------------------- */
harness_section('Search: toJson / setJsonAlert round-trip');

$src = new Search();
$src->addCategory($catCars);
$src->priceRange(1000, 20000);
$src->order('i_price', 'ASC');
$blob = $src->toJson();
check('toJson produces a non-empty JSON string', is_string($blob) && strlen($blob) > 0);

$decoded = json_decode($blob, true);
check('the JSON decodes to an array', is_array($decoded));

$revived = new Search();
$revived->setJsonAlert($decoded);
$revivedIds = $sorted($ids($revived->doSearch()));
pin('a search revived from its own serialized form returns the same items', $sorted(array($car1, $car2, $car3)), $revivedIds);

/* ----------------------------------------------------------------------------
 * Secondary execution paths that were routed off the legacy query layer.
 * ------------------------------------------------------------------------- */
harness_section('Search: countAll and listCityAreas');

$s = new Search();
pin('countAll returns the unfiltered total as a string', '5', (string)$s->countAll());

$s = new Search();
check('listCityAreas returns a well-formed array', is_array($s->listCityAreas(null, '>=', 'items DESC')));

/* ----------------------------------------------------------------------------
 * getLatestItems (the F4 cache path) — baseline.
 * ------------------------------------------------------------------------- */
harness_section('Search: getLatestItems');

$s = new Search();
$latest = $s->getLatestItems(10);
check('getLatestItems returns the recent items', is_array($latest) && count($latest) === 5);

/* ----------------------------------------------------------------------------
 * Old-format alert compat: a blob whose location fragments and pattern were
 * serialized in the legacy inlined form must still revive and run, because the
 * conversion left that value format untouched.
 * ------------------------------------------------------------------------- */
harness_section('Search: legacy alert blob still revives');

$legacyBlob = array(
    'price_min'             => 0,
    'price_max'             => 0,
    'aCategories'           => array($catCars),
    'city_areas'            => array(),
    'cities'                => array(),
    'regions'               => array(),
    'countries'             => array(),
    'user_ids'              => null,
    'tables_join'           => array(),
    'no_catched_tables'     => array(),
    'no_catched_conditions' => array(),
    'order_column'          => 'i_price',
    'order_direction'       => 'ASC',
    'limit_init'            => 0,
    'results_per_page'      => 20,
);
$legacyRevived = new Search();
$legacyRevived->setJsonAlert($legacyBlob);
pin(
    'a legacy-shaped alert blob revives and returns its category items',
    $sorted(array($car1, $car2, $car3)),
    $sorted($ids($legacyRevived->doSearch()))
);

/* ----------------------------------------------------------------------------
 * Conditions added straight onto $this->dao — the legacy way a theme or plugin
 * filters a search (a Manticore theme hydrating an id list via
 * $oSearch->dao->where('... IN (...)'), for one). The builder no longer reads the
 * dao on its own, so mergeDaoConditions() folds them back in; without it these
 * were silently dropped and the search came back unfiltered.
 * ------------------------------------------------------------------------- */
harness_section('Search: conditions added on $this->dao are honoured');

$s = new Search();
$s->dao->where(sprintf('%st_item.pk_i_id IN (%d, %d)', $prefix, $car1, $car3));
pin(
    'a raw dao->where() filters the result set',
    $sorted(array($car1, $car3)),
    $sorted($ids($s->doSearch()))
);

$s = new Search();
$s->dao->where(sprintf('%st_item.pk_i_id IN (%d, %d, %d)', $prefix, $car1, $car2, $car3));
$s->dao->orderBy(sprintf("FIND_IN_SET(%st_item.pk_i_id, '%d,%d,%d')", $prefix, $car3, $car1, $car2));
pin(
    'a raw dao->orderBy() decides the order — caller ordering is primary',
    array($car3, $car1, $car2),
    $ids($s->doSearch())
);

/*
 * includeHidden() — the supported switch for an admin/owner view to see listings
 * the public cannot. Added last so the extra disabled item does not disturb the
 * "5 live items" pins above.
 */
harness_section('Search: includeHidden');

pin(
    'includeHidden signature is unchanged',
    'public includeHidden($include = true)',
    harness_method_signature('Search', 'includeHidden')
);

$hidden = $mkItem('Hidden Wagon', $catCars, 3000.0, 0, $regionA, $cityA, 'Alpha', 'Aville');
$admin->query("UPDATE {$prefix}t_item SET b_enabled = 0 WHERE pk_i_id = " . (int)$hidden);

$s = new Search();
check('a disabled listing is hidden from a default search', !in_array($hidden, $ids($s->doSearch()), true));

$s = new Search();
$s->includeHidden();
check('includeHidden() surfaces the disabled listing', in_array($hidden, $ids($s->doSearch()), true));

$s = new Search();
$s->includeHidden();
$s->doSearch();
pin('includeHidden() count includes the hidden listing', 6, (int)$s->count());

$s = new Search();
$s->includeHidden();
$s->includeHidden(false);
check('includeHidden(false) restores the visibility filter', !in_array($hidden, $ids($s->doSearch()), true));

$s = new Search(true);
check('new Search(true) surfaces it too — parity with includeHidden', in_array($hidden, $ids($s->doSearch()), true));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/search.php */
