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
 * Characterization pins for replaying a stored alert (t_alerts.s_search) the way
 * the cron does it: osc_runAlert() json_decode()s the column, calls
 * Search::newInstance()->setJsonAlert() on it, then doSearch() (oc-includes/osclass/alerts.php:60-69).
 * s_search is SQL fragments, not bound parameters, so whatever conversion the
 * search assembler goes through next must keep replaying every one of these
 * blobs to the exact same matched-item set. That is what is pinned here, before
 * any conversion starts.
 *
 * Two blob families:
 *  (a) built by calling today's code — the same Search mutators
 *      CWebSearch::doModel makes, then json_decode(Search::toJson(), true) — so
 *      the blob is byte-for-byte what a live alert row holds today.
 *  (b) hand-written legacy shapes: quoting styles and field forms that predate
 *      the current write path (a NO_BACKSLASH_ESCAPES-style doubled quote, a
 *      backslash-escaped quote, a pre-fix unquoted DROPDOWN value, a scalar
 *      user_ids) but that setJsonAlert() must still be able to revive, because
 *      rows already saved in that shape are sitting in real installs.
 *
 * Every replay uses a FRESH Search() rather than the cron's shared
 * Search::newInstance() singleton — cross-alert singleton state (a keyword left
 * over from a previous alert in the same loop) is already characterized in
 * tests/models/search.php and is not this file's concern.
 *
 * Two quirks in today's code surface while building these fixtures and are
 * pinned deliberately, not fixed:
 *   - setJsonAlert() never sets $this->withUserId, so a stored alert's
 *     "user_ids" field — array or scalar, however it got there — has NO effect
 *     on replay. Every alert with a "from these users" filter has been
 *     replaying as if that filter were never set.
 *   - setJsonAlert() assigns $aData['aCategories'] straight into $this->categories
 *     with no addCategory()/toSubTree() call, so it never re-expands a category
 *     id to its subtree. A blob saved by today's code already has the subtree
 *     baked in (addCategory() expanded it before toJson() ran), but a
 *     hand-shaped blob that lists only the parent id matches the parent alone.
 *
 * Section (d) replays the same searches stored as v2 envelopes (search values, no SQL)
 * through mindstellar\search\AlertReplay and pins the same ids, except where v2 fixes one
 * of the quirks above; each of those pins names the quirk it fixes.
 *
 * Usage:  php tests/models/alert-replay.php          (standalone, own scratch database)
 *         php tests/run-models.php alert-replay      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_alert_replay');

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
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
// The real osc_esc_html(), loaded before stubs.php so its guarded stand-in backs off —
// this file runs early in the suite (alphabetically ahead of tests/models/billing.php,
// which loads hSanitize.php itself and assumes it is the first to do so in the process;
// neither of its functions are function_exists-guarded, so whichever loads first wins
// and the other one fatals with "Cannot redeclare").
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
// After hPlugins.php and hSanitize.php, so stubs.php's guarded osc_run_hook()/
// osc_apply_filter()/osc_esc_html() stand-ins stay out of the way and only __()
// (hTranslations.php is never loaded here) is filled in.
require_once __DIR__ . '/../lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';

$prefix     = DB_TABLE_PREFIX;
$metaTable  = $prefix . 't_item_meta';

/* ----------------------------------------------------------------------------
 * Fixture: two countries, three regions, three cities, two city areas; three
 * categories (one a child, for subtree expansion); three users; seven items
 * spread across all of it, with pictures/premium on some and custom-field
 * values (all seven field types) on the ones in the root Cars category.
 * ------------------------------------------------------------------------- */
$locale = seed_locale($admin);
seed_currency($admin);

$countryUS = seed_country($admin, 'US', 'United States');
$countryCA = seed_country($admin, 'CA', 'Canada');

$regionAlpha = seed_region($admin, $countryUS, 'Alpha');
$regionBeta  = seed_region($admin, $countryUS, 'Beta');
$regionGamma = seed_region($admin, $countryCA, 'Gamma');

$cityAville = seed_city($admin, $regionAlpha, 'Aville', $countryUS);
$cityBville = seed_city($admin, $regionBeta, 'Bville', $countryUS);
$cityGtown  = seed_city($admin, $regionGamma, 'Gtown', $countryCA);

/** t_city_area.pk_i_id is not AUTO_INCREMENT — the id is ours to pick. */
$seedCityArea = static function (int $id, int $cityId, string $name) use ($admin, $prefix): int {
    seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_city_area (pk_i_id, fk_i_city_id, s_name) VALUES (?, ?, ?)",
        'iis',
        array($id, $cityId, $name)
    );

    return $id;
};
$areaA1 = $seedCityArea(1, $cityAville, 'Downtown');
$areaB1 = $seedCityArea(2, $cityBville, 'Uptown');

$catCars      = seed_category($admin, 'Cars', null, $locale);
$catCarsSport = seed_category($admin, 'Sports Cars', $catCars, $locale);
$catBikes     = seed_category($admin, 'Bikes', null, $locale);

$userSeller1 = seed_user($admin, 'seller1', 'seller1@example.test');
$userSeller2 = seed_user($admin, 'seller2', 'seller2@example.test');
$userSeller3 = seed_user($admin, 'seller3', 'seller3@example.test');

/* Custom fields, all one of the seven searchable types, all linked to Cars. */
$seedMetaField = static function (string $name, string $type) use ($admin, $prefix): int {
    return seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_meta_fields (s_name, s_slug, e_type, b_required, b_searchable, i_position)
         VALUES (?, ?, ?, 0, 1, 0)",
        'sss',
        array($name, strtolower($name), $type)
    );
};
$linkFieldToCategory = static function (int $fieldId, int $categoryId) use ($admin, $prefix): void {
    seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)",
        'ii',
        array($categoryId, $fieldId)
    );
};
$fText         = $seedMetaField('Notes', 'TEXT');
$fDropdown     = $seedMetaField('Color', 'DROPDOWN');
$fRadio        = $seedMetaField('Condition', 'RADIO');
$fCheckbox     = $seedMetaField('Negotiable', 'CHECKBOX');
$fDate         = $seedMetaField('AvailableFrom', 'DATE');
$fDateInterval = $seedMetaField('WarrantyPeriod', 'DATEINTERVAL');
$fNumber       = $seedMetaField('Mileage', 'NUMBER');
foreach (array($fText, $fDropdown, $fRadio, $fCheckbox, $fDate, $fDateInterval, $fNumber) as $fid) {
    $linkFieldToCategory($fid, $catCars);
}

$seedItemMeta = static function (int $itemId, int $fieldId, string $value, string $multi = '') use ($admin, $prefix): void {
    seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES (?, ?, ?, ?)",
        'iiss',
        array($itemId, $fieldId, $value, $multi)
    );
};

/** Insert an item with description, location and stats rows; optionally a picture. */
$mkItem = static function (
    string $title,
    string $description,
    int $category,
    float $price,
    int $premium,
    int $userId,
    string $country,
    string $countryName,
    int $regionId,
    string $regionName,
    int $cityId,
    string $cityName,
    ?int $cityAreaId,
    ?string $cityAreaName,
    bool $hasPicture
) use ($admin, $prefix, $locale): int {
    $id = seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_item
         (fk_i_user_id, fk_i_category_id, dt_pub_date, dt_mod_date, f_price, i_price,
          fk_c_currency_code, s_contact_name, s_contact_email, s_ip, b_premium,
          b_enabled, b_active, b_spam, s_secret, dt_expiration)
         VALUES (?, ?, NOW(), NOW(), ?, ?, 'USD', 'Seller', 'seller@example.test',
                 '127.0.0.1', ?, 1, 1, 0, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))",
        'iidiis',
        array($userId, $category, $price, (int)round($price * 1000000), $premium, 'sec' . $userId . $title)
    );
    seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_item_description (fk_i_item_id, fk_c_locale_code, s_title, s_description)
         VALUES (?, ?, ?, ?)",
        'isss',
        array($id, $locale, $title, $description)
    );
    seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_item_location
         (fk_i_item_id, fk_c_country_code, s_country, fk_i_region_id, s_region, fk_i_city_id, s_city,
          fk_i_city_area_id, s_city_area)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        'issisisis',
        array($id, $country, $countryName, $regionId, $regionName, $cityId, $cityName, $cityAreaId, $cityAreaName)
    );
    seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_item_stats (fk_i_item_id, dt_date) VALUES (?, CURDATE())",
        'i',
        array($id)
    );
    if ($hasPicture) {
        seed_exec(
            $admin,
            "INSERT INTO {$prefix}t_item_resource (fk_i_item_id, s_name, s_extension, s_content_type, s_path)
             VALUES (?, 'photo', 'jpg', 'image/jpeg', '/photo.jpg')",
            'i',
            array($id)
        );
    }

    return $id;
};

$i1 = $mkItem(
    'Red Roadster',
    'Red Roadster body vintage collectible',
    $catCars,
    5000.0,
    0,
    $userSeller1,
    $countryUS,
    'United States',
    $regionAlpha,
    'Alpha',
    $cityAville,
    'Aville',
    $areaA1,
    'Downtown',
    true
);
$i2 = $mkItem(
    'Blue Sedan',
    'Blue Sedan body clean interior',
    $catCars,
    12000.0,
    1,
    $userSeller1,
    $countryUS,
    'United States',
    $regionAlpha,
    'Alpha',
    $cityAville,
    'Aville',
    $areaA1,
    'Downtown',
    true
);
$i3 = $mkItem(
    'Green Coupe',
    'Green Coupe body vintage restored',
    $catCars,
    9000.0,
    0,
    $userSeller2,
    $countryUS,
    'United States',
    $regionBeta,
    'Beta',
    $cityBville,
    'Bville',
    $areaB1,
    'Uptown',
    true
);
$i4 = $mkItem(
    'Mountain Bike',
    'Mountain Bike body trail ready',
    $catBikes,
    800.0,
    0,
    $userSeller2,
    $countryUS,
    'United States',
    $regionBeta,
    'Beta',
    $cityBville,
    'Bville',
    $areaB1,
    'Uptown',
    false
);
$i5 = $mkItem(
    'Racing Bike',
    'Racing Bike body carbon frame',
    $catBikes,
    2500.0,
    1,
    $userSeller3,
    $countryUS,
    'United States',
    $regionBeta,
    'Beta',
    $cityBville,
    'Bville',
    $areaB1,
    'Uptown',
    false
);
$i6 = $mkItem(
    'Silver Import',
    'Silver Import body vintage rare',
    $catCars,
    15000.0,
    0,
    $userSeller3,
    $countryCA,
    'Canada',
    $regionGamma,
    'Gamma',
    $cityGtown,
    'Gtown',
    null,
    null,
    false
);
$i7 = $mkItem(
    'Sport Racer',
    'Sport Racer body track tuned',
    $catCarsSport,
    20000.0,
    0,
    $userSeller1,
    $countryUS,
    'United States',
    $regionAlpha,
    'Alpha',
    $cityAville,
    'Aville',
    null,
    null,
    false
);

$seedItemMeta($i1, $fText, 'vintage roadster in great shape');
$seedItemMeta($i1, $fDropdown, 'red');
$seedItemMeta($i1, $fRadio, 'used');
$seedItemMeta($i1, $fCheckbox, '1');
$seedItemMeta($i1, $fDate, (string)strtotime('2026-03-15 10:00:00'));
$seedItemMeta($i1, $fDateInterval, (string)strtotime('2026-01-01 00:00:00'), 'from');
$seedItemMeta($i1, $fDateInterval, (string)strtotime('2026-12-31 23:59:59'), 'to');
$seedItemMeta($i1, $fNumber, '15000');

$seedItemMeta($i2, $fText, 'blue sedan low mileage clean');
$seedItemMeta($i2, $fDropdown, 'blue');
$seedItemMeta($i2, $fRadio, 'new');
$seedItemMeta($i2, $fDate, (string)strtotime('2026-06-01 10:00:00'));
$seedItemMeta($i2, $fDateInterval, (string)strtotime('2026-02-01 00:00:00'), 'from');
$seedItemMeta($i2, $fDateInterval, (string)strtotime('2026-11-30 23:59:59'), 'to');
$seedItemMeta($i2, $fNumber, '5000');

$seedItemMeta($i3, $fText, 'green coupe classic');
$seedItemMeta($i3, $fDropdown, 'red');
$seedItemMeta($i3, $fRadio, 'used');
$seedItemMeta($i3, $fDate, (string)strtotime('2025-12-25 10:00:00'));
$seedItemMeta($i3, $fDateInterval, (string)strtotime('2026-03-01 00:00:00'), 'from');
$seedItemMeta($i3, $fDateInterval, (string)strtotime('2026-09-30 23:59:59'), 'to');
$seedItemMeta($i3, $fNumber, '42000');

$seedItemMeta($i6, $fDropdown, "red's pick");
$seedItemMeta($i6, $fNumber, '2000');

/*
 * addCategory() resolves through the Category singleton's in-memory tree, built
 * once per process. Reset it (as tests/models/search.php does) so this file's
 * own categories are what gets seen.
 */
if (class_exists('Object_Cache_Factory')) {
    Object_Cache_Factory::newInstance()->flush();
}
$categoryReset = new ReflectionProperty('Category', 'instance');
$categoryReset->setAccessible(true);
$categoryReset->setValue(null, null);

/** Collect the pk_i_id column from a doSearch result. */
$ids = static function (array $rows): array {
    return array_map('intval', array_column($rows, 'pk_i_id'));
};
$sorted = static function (array $a): array {
    sort($a);
    return $a;
};

/** setJsonAlert() on a FRESH Search, then doSearch() — the cron's replay shape. */
$replay = static function (array $blob) use ($ids, $sorted): array {
    $s = new Search();
    $s->setJsonAlert($blob);

    return $sorted($ids($s->doSearch()));
};

/** Build a v1 blob the way today's code does: mutate a fresh Search, toJson() it. */
$buildBlob = static function (callable $mutate): array {
    $s = new Search();
    $mutate($s);

    return json_decode($s->toJson(), true);
};

/* Base shape for a hand-written legacy blob: every key setJsonAlert() reads
 * without a null-coalesce, so a blob missing one would fatal on replay. */
$legacyBase = array(
    'price_min'             => 0,
    'price_max'             => 0,
    'aCategories'           => array(),
    'city_areas'            => array(),
    'cities'                => array(),
    'regions'               => array(),
    'countries'             => array(),
    'user_ids'              => null,
    'tables_join'           => array(),
    'no_catched_tables'     => array(),
    'no_catched_conditions' => array(),
    'order_column'          => 'dt_pub_date',
    'order_direction'       => 'ASC',
    'limit_init'            => 0,
    'results_per_page'      => 20,
);

/** The exact addConditions() string CWebSearch.php:441-525 builds for one meta filter. */
$metaCondition = static function (string $type, int $fieldId, $aux) use ($prefix, $metaTable): ?string {
    switch ($type) {
        case 'TEXT':
            $escaped = "'" . \mindstellar\database\Connection::instance()->escape('%' . $aux . '%') . "'";
            $sql     = "SELECT fk_i_item_id FROM $metaTable WHERE ";
            $sql     .= $metaTable . '.fk_i_field_id = ' . $fieldId . ' AND ';
            $sql     .= $metaTable . '.s_value LIKE ' . $escaped;

            return $prefix . 't_item.pk_i_id IN (' . $sql . ')';
        case 'DROPDOWN':
        case 'RADIO':
            $escaped = "'" . \mindstellar\database\Connection::instance()->escape((string)$aux) . "'";
            $sql     = "SELECT fk_i_item_id FROM $metaTable WHERE ";
            $sql     .= $metaTable . '.fk_i_field_id = ' . $fieldId . ' AND ';
            $sql     .= $metaTable . '.s_value = ' . $escaped;

            return $prefix . 't_item.pk_i_id IN (' . $sql . ')';
        case 'CHECKBOX':
            $sql = "SELECT fk_i_item_id FROM $metaTable WHERE ";
            $sql .= $metaTable . '.fk_i_field_id = ' . $fieldId . ' AND ';
            $sql .= $metaTable . '.s_value = 1';

            return $prefix . 't_item.pk_i_id IN (' . $sql . ')';
        case 'DATE':
            $y     = (int)date('Y', $aux);
            $m     = (int)date('n', $aux);
            $d     = (int)date('j', $aux);
            $start = mktime(0, 0, 0, $m, $d, $y);
            $end   = mktime(23, 59, 59, $m, $d, $y);
            $sql   = "SELECT fk_i_item_id FROM $metaTable WHERE ";
            $sql   .= $metaTable . '.fk_i_field_id = ' . $fieldId . ' AND ';
            $sql   .= $metaTable . '.s_value >= ' . $start . ' AND ';
            $sql   .= $metaTable . '.s_value <= ' . $end;

            return $prefix . 't_item.pk_i_id IN (' . $sql . ')';
        case 'DATEINTERVAL':
            $from  = (int)$aux['from'];
            $to    = (int)$aux['to'];
            $sql   = "SELECT fk_i_item_id FROM $metaTable WHERE ";
            $sql   .= $metaTable . '.fk_i_field_id = ' . $fieldId . ' AND ';
            $sql   .= $from . ' >= ' . $metaTable . ".s_value AND s_multi = 'from'";
            $sql1  = "SELECT fk_i_item_id FROM $metaTable WHERE ";
            $sql1  .= $metaTable . '.fk_i_field_id = ' . $fieldId . ' AND ';
            $sql1  .= $to . ' <= ' . $metaTable . ".s_value AND s_multi = 'to'";
            $sqlIv = 'select a.fk_i_item_id from (' . $sql . ') a where a.fk_i_item_id IN (' . $sql1 . ')';

            return $prefix . 't_item.pk_i_id IN (' . $sqlIv . ')';
        case 'NUMBER':
            $min = (float)$aux['from'];
            $max = (float)$aux['to'];
            $sql = "SELECT fk_i_item_id FROM $metaTable WHERE ";
            $sql .= $metaTable . '.fk_i_field_id = ' . $fieldId . ' AND ';
            $sql .= $metaTable . '.s_value >= ' . $min . ' AND ';
            $sql .= $metaTable . '.s_value <= ' . $max;

            return $prefix . 't_item.pk_i_id IN (' . $sql . ')';
        default:
            return null;
    }
};

/* The pattern path joins t_item_description and filters on the current user
 * locale; the fixtures are en_US, so pin that locale for the pattern sections
 * below (osc_current_user_locale() reads this cookie). */
$_COOKIE['oc_userLocale'] = 'en_US';

/* ----------------------------------------------------------------------------
 * (a) Blobs built by calling today's code, then replayed.
 * ------------------------------------------------------------------------- */
harness_section('alert-replay: (a) blobs built by today\'s code — addCategory');

// catCars's subtree (catCarsSport) is expanded into aCategories at SAVE time —
// addCategory()'s toSubTree()/pruneBranches() already ran before toJson().
$blob = $buildBlob(static function (Search $s) use ($catCars) {
    $s->addCategory($catCars);
});
pin(
    'a category alert matches the category AND its already-expanded subtree',
    $sorted(array($i1, $i2, $i3, $i6, $i7)),
    $replay($blob)
);

harness_section('alert-replay: (a) addCountry — by 2-letter code');

$blob = $buildBlob(static function (Search $s) {
    $s->addCountry('us');
});
pin('a country-code alert matches every item in that country', $sorted(array($i1, $i2, $i3, $i4, $i5, $i7)), $replay($blob));

harness_section('alert-replay: (a) addCountry — by name');

$blob = $buildBlob(static function (Search $s) {
    $s->addCountry('United States');
});
pin('a country-name alert matches the same set as the code', $sorted(array($i1, $i2, $i3, $i4, $i5, $i7)), $replay($blob));

harness_section('alert-replay: (a) addRegion — by id');

$blob = $buildBlob(static function (Search $s) use ($regionAlpha) {
    $s->addRegion($regionAlpha);
});
pin('a region-id alert matches only that region\'s items', $sorted(array($i1, $i2, $i7)), $replay($blob));

harness_section('alert-replay: (a) addRegion — by name');

$blob = $buildBlob(static function (Search $s) {
    $s->addRegion('Alpha');
});
pin('a region-name alert matches the same set as the id', $sorted(array($i1, $i2, $i7)), $replay($blob));

harness_section('alert-replay: (a) addCity — by id');

$blob = $buildBlob(static function (Search $s) use ($cityAville) {
    $s->addCity($cityAville);
});
pin('a city-id alert matches only that city\'s items', $sorted(array($i1, $i2, $i7)), $replay($blob));

harness_section('alert-replay: (a) addCity — by name');

$blob = $buildBlob(static function (Search $s) {
    $s->addCity('Aville');
});
pin('a city-name alert matches the same set as the id', $sorted(array($i1, $i2, $i7)), $replay($blob));

harness_section('alert-replay: (a) addCityArea — by id');

$blob = $buildBlob(static function (Search $s) use ($areaA1) {
    $s->addCityArea($areaA1);
});
pin('a city-area-id alert is narrower than the city (I7 has no area)', $sorted(array($i1, $i2)), $replay($blob));

harness_section('alert-replay: (a) addCityArea — by name');

$blob = $buildBlob(static function (Search $s) {
    $s->addCityArea('Downtown');
});
pin('a city-area-name alert matches the same set as the id', $sorted(array($i1, $i2)), $replay($blob));

harness_section('alert-replay: (a) fromUser(array) — QUIRK, ignored on replay');

$blob = $buildBlob(static function (Search $s) use ($userSeller1) {
    $s->fromUser(array($userSeller1, 'seller2'));
});
pin(
    'a from-these-users alert matches EVERY live item — setJsonAlert() never sets withUserId,'
        . ' so the stored user_ids never reaches the WHERE clause on replay',
    $sorted(array($i1, $i2, $i3, $i4, $i5, $i6, $i7)),
    $replay($blob)
);

harness_section('alert-replay: (a) priceRange');

$blob = $buildBlob(static function (Search $s) {
    $s->priceRange(1000, 10000);
});
pin('a price-range alert matches items inside the bound', $sorted(array($i1, $i3, $i5)), $replay($blob));

harness_section('alert-replay: (a) addPattern');

$blob = $buildBlob(static function (Search $s) {
    $s->addPattern('vintage');
});
pin('a pattern alert matches items whose description carries the word', $sorted(array($i1, $i3, $i6)), $replay($blob));

harness_section('alert-replay: (a) withPicture');

$blob = $buildBlob(static function (Search $s) {
    $s->withPicture(true);
});
pin('a has-picture alert matches only items with a resource row', $sorted(array($i1, $i2, $i3)), $replay($blob));

harness_section('alert-replay: (a) meta — TEXT');

$blob = $buildBlob(static function (Search $s) use ($metaCondition, $fText) {
    $s->addConditions($metaCondition('TEXT', $fText, 'mileage'));
});
pin('a TEXT meta alert LIKE-matches the field value', array($i2), $replay($blob));

harness_section('alert-replay: (a) meta — DROPDOWN');

$blob = $buildBlob(static function (Search $s) use ($metaCondition, $fDropdown) {
    $s->addConditions($metaCondition('DROPDOWN', $fDropdown, 'red'));
});
pin('a DROPDOWN meta alert exact-matches the field value', $sorted(array($i1, $i3)), $replay($blob));

harness_section('alert-replay: (a) meta — RADIO');

$blob = $buildBlob(static function (Search $s) use ($metaCondition, $fRadio) {
    $s->addConditions($metaCondition('RADIO', $fRadio, 'used'));
});
pin('a RADIO meta alert exact-matches the field value', $sorted(array($i1, $i3)), $replay($blob));

harness_section('alert-replay: (a) meta — CHECKBOX');

$blob = $buildBlob(static function (Search $s) use ($metaCondition, $fCheckbox) {
    $s->addConditions($metaCondition('CHECKBOX', $fCheckbox, '1'));
});
pin('a CHECKBOX meta alert matches only a checked row', array($i1), $replay($blob));

harness_section('alert-replay: (a) meta — DATE');

$blob = $buildBlob(static function (Search $s) use ($metaCondition, $fDate) {
    $s->addConditions($metaCondition('DATE', $fDate, strtotime('2026-03-15 12:00:00')));
});
pin('a DATE meta alert matches the item stored on that day', array($i1), $replay($blob));

harness_section('alert-replay: (a) meta — DATEINTERVAL');

$blob = $buildBlob(static function (Search $s) use ($metaCondition, $fDateInterval) {
    $s->addConditions($metaCondition('DATEINTERVAL', $fDateInterval, array(
        'from' => strtotime('2026-10-15 00:00:00'),
        'to'   => strtotime('2026-10-20 23:59:59'),
    )));
});
pin(
    'a DATEINTERVAL meta alert matches items whose stored range contains the query range',
    $sorted(array($i1, $i2)),
    $replay($blob)
);

harness_section('alert-replay: (a) meta — NUMBER');

$blob = $buildBlob(static function (Search $s) use ($metaCondition, $fNumber) {
    $s->addConditions($metaCondition('NUMBER', $fNumber, array('from' => 3000, 'to' => 20000)));
});
pin('a NUMBER meta alert matches items whose value is inside the bound', $sorted(array($i1, $i2)), $replay($blob));

/* ----------------------------------------------------------------------------
 * (b) Hand-written legacy shapes.
 * ------------------------------------------------------------------------- */
harness_section('alert-replay: (b) pattern stored quoted+escaped (pre-fix shape)');

$blob             = $legacyBase;
$blob['sPattern'] = "'se'";
pin(
    'a legacy quoted pattern strips the wrapper and matches the same items as the raw word',
    array($i2),
    $replay($blob)
);

harness_section('alert-replay: (b) meta value quoted with doubled single quotes');

$cond             = $prefix . 't_item.pk_i_id IN (SELECT fk_i_item_id FROM ' . $metaTable . ' WHERE '
    . $metaTable . '.fk_i_field_id = ' . $fDropdown . " AND " . $metaTable . ".s_value = 'red''s pick')";
$blob                          = $legacyBase;
$blob['no_catched_conditions'] = array($cond);
pin('a doubled-single-quote meta value matches the item with the literal apostrophe', array($i6), $replay($blob));

harness_section('alert-replay: (b) meta value quoted with backslash escaping');

$bs   = chr(92);
$cond = $prefix . 't_item.pk_i_id IN (SELECT fk_i_item_id FROM ' . $metaTable . ' WHERE '
    . $metaTable . '.fk_i_field_id = ' . $fDropdown . " AND " . $metaTable . ".s_value = 'red" . $bs . "'s pick')";
$blob                          = $legacyBase;
$blob['no_catched_conditions'] = array($cond);
pin('a backslash-escaped meta value matches the same item as the doubled-quote form', array($i6), $replay($blob));

harness_section('alert-replay: (b) unquoted DROPDOWN value — pre-2026-09 shape, QUIRK');

$cond = $prefix . 't_item.pk_i_id IN (SELECT fk_i_item_id FROM ' . $metaTable . ' WHERE '
    . $metaTable . '.fk_i_field_id = ' . $fDropdown . ' AND ' . $metaTable . '.s_value = red)';
$blob                          = $legacyBase;
$blob['no_catched_conditions'] = array($cond);
pin(
    'an unquoted meta value is invalid SQL (red is read as a column, not a string) —'
        . ' the query errors, doSearch() catches it, and the alert silently matches nothing',
    array(),
    $replay($blob)
);

harness_section('alert-replay: (b) scalar user_ids + a literal (unexpanded) category — QUIRK');

$blob                 = $legacyBase;
$blob['aCategories']  = array($catCars);
$blob['user_ids']     = $userSeller3;
pin(
    'aCategories is taken literally (no subtree re-expansion on replay, so the Sports Cars'
        . ' child is excluded) and the scalar user_ids is ignored exactly like the array form',
    $sorted(array($i1, $i2, $i3, $i6)),
    $replay($blob)
);

harness_section('alert-replay: (b) meta — DATE (hand-written)');

$day   = strtotime('2026-06-01 00:00:00');
$start = mktime(0, 0, 0, (int)date('n', $day), (int)date('j', $day), (int)date('Y', $day));
$end   = mktime(23, 59, 59, (int)date('n', $day), (int)date('j', $day), (int)date('Y', $day));
$cond  = $prefix . 't_item.pk_i_id IN (SELECT fk_i_item_id FROM ' . $metaTable . ' WHERE '
    . $metaTable . '.fk_i_field_id = ' . $fDate . ' AND ' . $metaTable . '.s_value >= ' . $start
    . ' AND ' . $metaTable . '.s_value <= ' . $end . ')';
$blob                          = $legacyBase;
$blob['no_catched_conditions'] = array($cond);
pin('a hand-written DATE condition matches the item stored on that day', array($i2), $replay($blob));

harness_section('alert-replay: (b) meta — DATEINTERVAL (hand-written)');

$qStart = strtotime('2026-11-01 00:00:00');
$qEnd   = strtotime('2026-11-15 23:59:59');
$sub    = 'SELECT fk_i_item_id FROM ' . $metaTable . ' WHERE ' . $metaTable . '.fk_i_field_id = ' . $fDateInterval
    . ' AND ' . $qStart . ' >= ' . $metaTable . ".s_value AND s_multi = 'from'";
$sub1   = 'SELECT fk_i_item_id FROM ' . $metaTable . ' WHERE ' . $metaTable . '.fk_i_field_id = ' . $fDateInterval
    . ' AND ' . $qEnd . ' <= ' . $metaTable . ".s_value AND s_multi = 'to'";
$cond   = $prefix . 't_item.pk_i_id IN (select a.fk_i_item_id from (' . $sub . ') a where a.fk_i_item_id IN ('
    . $sub1 . '))';
$blob                          = $legacyBase;
$blob['no_catched_conditions'] = array($cond);
pin(
    'a hand-written DATEINTERVAL condition matches items whose stored range contains the query range',
    $sorted(array($i1, $i2)),
    $replay($blob)
);

harness_section('alert-replay: (b) meta — NUMBER (hand-written)');

$cond = $prefix . 't_item.pk_i_id IN (SELECT fk_i_item_id FROM ' . $metaTable . ' WHERE '
    . $metaTable . '.fk_i_field_id = ' . $fNumber . ' AND ' . $metaTable . '.s_value >= 40000 AND '
    . $metaTable . '.s_value <= 50000)';
$blob                          = $legacyBase;
$blob['no_catched_conditions'] = array($cond);
pin('a hand-written NUMBER condition matches the item inside the bound', array($i3), $replay($blob));

/* ----------------------------------------------------------------------------
 * (c) Search::toJson() pinned byte-for-byte, for searches built the way
 * CWebSearch::doModel builds them (mutators, THEN order()/page() — the
 * controller always calls both before toJson()). This is a second, independent
 * compatibility boundary from the (a)/(b) replay pins above: a theme's own
 * search backend (theme-shopclass's Manticore one, among others) decodes this
 * exact JSON shape — no_catched_conditions, tables, tables_join, city_areas,
 * results_per_page, limit_init — and it is core's result-cache key
 * (CWebSearch.php:559). Reordering or reshaping a key here is a silent
 * cache-miss-forever and a broken plugin integration, not just a broken alert.
 *
 * Expected strings are built independently of toJson() — the same $aData shape
 * and key order, filled in with sprintf() from the seeded ids/prefix rather
 * than literal numbers, so the pin is deterministic across a reseed but still
 * fails the moment toJson()'s key order, omission rule or fragment spelling
 * moves.
 * ------------------------------------------------------------------------- */
harness_section('alert-replay: (c) toJson() byte-for-byte — setup');

/** Independent re-assembly of Search::toJson()'s $aData shape, in its exact key order. */
$expectedToJson = static function (array $v): string {
    $aData = array();
    $aData['price_min']   = $v['price_min'];
    $aData['price_max']   = $v['price_max'];
    $aData['aCategories'] = $v['aCategories'];
    $aData['city_areas']  = $v['city_areas'];
    $aData['cities']      = $v['cities'];
    $aData['regions']     = $v['regions'];
    $aData['countries']   = $v['countries'];
    $aData['withPattern'] = $v['withPattern'];
    $aData['sPattern']    = $v['sPattern'];
    if (!empty($v['withPicture'])) {
        $aData['withPicture'] = true;
    }
    if (!empty($v['onlyPremium'])) {
        $aData['onlyPremium'] = true;
    }
    $aData['tables']                = $v['tables'];
    $aData['tables_join']           = $v['tables_join'];
    $aData['no_catched_tables']     = $v['no_catched_tables'];
    $aData['no_catched_conditions'] = $v['no_catched_conditions'];
    $aData['user_ids']              = $v['user_ids'];
    $aData['order_column']          = $v['order_column'];
    $aData['order_direction']       = $v['order_direction'];
    $aData['limit_init']            = $v['limit_init'];
    $aData['results_per_page']      = $v['results_per_page'];

    return json_encode($aData);
};

/* Every field a fresh Search() carries when only order()/page() have run —
 * the controller's order('dt_pub_date', 'desc') and page(0, 12), literally. */
$tjDefaults = array(
    'price_min'             => 0,
    'price_max'             => 0,
    'aCategories'           => array(),
    'city_areas'            => array(),
    'cities'                => array(),
    'regions'               => array(),
    'countries'             => array(),
    'withPattern'           => false,
    'sPattern'              => null,
    'withPicture'           => false,
    'onlyPremium'           => false,
    'tables'                => array(),
    'tables_join'           => array(),
    'no_catched_tables'     => array(),
    'no_catched_conditions' => array(),
    'user_ids'              => null,
    'order_column'          => 'dt_pub_date',
    'order_direction'       => 'desc',
    'limit_init'            => 0,
    'results_per_page'      => 12,
);

harness_section('alert-replay: (c) toJson() — categories');

$s = new Search();
$s->addCategory($catCars);
$s->order('dt_pub_date', 'desc');
$s->page(0, 12);
$v                = $tjDefaults;
// QUIRK: the two entries are not the same JSON type. addCategory() appends the id it
// was called with as-is (a PHP int here); pruneBranches() appends the subtree's ids
// straight from the DB row (toSubTree()'s pk_i_id, a string). toJson() serialises
// $this->categories verbatim, so the array comes out mixed: [1,"2"], not [1,2].
$v['aCategories'] = array($catCars, (string)$catCarsSport);
pin('a category search serialises the already-expanded subtree, in order', $expectedToJson($v), $s->toJson());

harness_section('alert-replay: (c) toJson() — country by code');

$s = new Search();
$s->addCountry('us');
$s->order('dt_pub_date', 'desc');
$s->page(0, 12);
$v              = $tjDefaults;
$v['countries'] = array(sprintf('%st_item_location.fk_c_country_code = %s ', $prefix, "'us'"));
pin('a country-code search serialises the lower-cased, quoted code fragment', $expectedToJson($v), $s->toJson());

harness_section('alert-replay: (c) toJson() — region/city/city-area by id');

$s = new Search();
$s->addRegion($regionAlpha);
$s->addCity($cityAville);
$s->addCityArea($areaA1);
$s->order('dt_pub_date', 'desc');
$s->page(0, 12);
$v               = $tjDefaults;
$v['regions']    = array(sprintf('%st_item_location.fk_i_region_id = %d ', $prefix, $regionAlpha));
$v['cities']     = array(sprintf('%st_item_location.fk_i_city_id = %d ', $prefix, $cityAville));
$v['city_areas'] = array(sprintf('%st_item_location.fk_i_city_area_id = %d ', $prefix, $areaA1));
pin('an id-based location search serialises the bare-int fragments', $expectedToJson($v), $s->toJson());

harness_section('alert-replay: (c) toJson() — region/city/city-area by name');

$s = new Search();
$s->addRegion('Alpha');
$s->addCity('Aville');
$s->addCityArea('Downtown');
$s->order('dt_pub_date', 'desc');
$s->page(0, 12);
$v               = $tjDefaults;
$v['regions']    = array(sprintf("%st_item_location.s_region LIKE %s ", $prefix, "'Alpha'"));
$v['cities']     = array(sprintf("%st_item_location.s_city LIKE %s ", $prefix, "'Aville'"));
$v['city_areas'] = array(sprintf("%st_item_location.s_city_area LIKE %s ", $prefix, "'Downtown'"));
pin('a name-based location search serialises the quoted LIKE fragments', $expectedToJson($v), $s->toJson());

harness_section('alert-replay: (c) toJson() — fromUser(array)');

$s = new Search();
$s->fromUser(array($userSeller1, 'seller2'));
$s->order('dt_pub_date', 'desc');
$s->page(0, 12);
$v              = $tjDefaults;
$v['user_ids']  = array(
    sprintf('%st_item.fk_i_user_id = %d ', $prefix, $userSeller1),
    sprintf('%st_item.fk_i_user_id = %d ', $prefix, $userSeller2),
);
pin('a from-these-users search serialises one fragment per id/username resolved', $expectedToJson($v), $s->toJson());

harness_section('alert-replay: (c) toJson() — price range');

$s = new Search();
$s->priceRange(1000, 10000);
$s->order('dt_pub_date', 'desc');
$s->page(0, 12);
$v               = $tjDefaults;
$v['price_min']  = 1000;
$v['price_max']  = 10000;
pin('a price-range search serialises the bounds in currency units, not micros', $expectedToJson($v), $s->toJson());

harness_section('alert-replay: (c) toJson() — pattern + withPicture');

$s = new Search();
$s->addPattern('vintage');
$s->withPicture(true);
$s->order('dt_pub_date', 'desc');
$s->page(0, 12);
$v                = $tjDefaults;
$v['withPattern'] = true;
$v['sPattern']    = 'vintage';
$v['withPicture'] = true;
pin('a pattern+picture search serialises the raw pattern and the withPicture flag', $expectedToJson($v), $s->toJson());

harness_section('alert-replay: (c) toJson() — meta: TEXT, DROPDOWN, DATEINTERVAL, NUMBER');

$s = new Search();
$s->addConditions($metaCondition('TEXT', $fText, 'mileage'));
$s->addConditions($metaCondition('DROPDOWN', $fDropdown, 'red'));
$s->addConditions($metaCondition('DATEINTERVAL', $fDateInterval, array(
    'from' => strtotime('2026-10-15 00:00:00'),
    'to'   => strtotime('2026-10-20 23:59:59'),
)));
$s->addConditions($metaCondition('NUMBER', $fNumber, array('from' => 3000, 'to' => 20000)));
$s->order('dt_pub_date', 'desc');
$s->page(0, 12);
$v = $tjDefaults;
$v['no_catched_conditions'] = array(
    $metaCondition('TEXT', $fText, 'mileage'),
    $metaCondition('DROPDOWN', $fDropdown, 'red'),
    $metaCondition('DATEINTERVAL', $fDateInterval, array(
        'from' => strtotime('2026-10-15 00:00:00'),
        'to'   => strtotime('2026-10-20 23:59:59'),
    )),
    $metaCondition('NUMBER', $fNumber, array('from' => 3000, 'to' => 20000)),
);
pin('a four-meta-type search serialises one condition string per filter, in call order', $expectedToJson($v), $s->toJson());

/* ----------------------------------------------------------------------------
 * (d) The same searches stored as v2 envelopes, replayed through AlertReplay.
 * ------------------------------------------------------------------------- */
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSearch.php';

use mindstellar\search\AlertEnvelope;
use mindstellar\search\AlertReplay;
use mindstellar\search\SearchCriteria;

/** The envelope the search page would mint for these request params. */
$envelope = static function (array $request): string {
    return AlertEnvelope::build(SearchCriteria::fromRequest($request), $request);
};
/** Replay a v2 row the way the cron does: AlertReplay::search(), then doSearch(). */
$replayV2 = static function (string $json) use ($ids, $sorted): array {
    $s = AlertReplay::search(array('s_search' => $json));

    return $s === null ? array('not replayed') : $sorted($ids($s->doSearch()));
};

harness_section('alert-replay: (d) v2 — envelope shape');

pin('a category is stored as its id', '{"v":2,"params":{"sCategory":[' . $catCars . ']}}', $envelope(array('sCategory' => (string)$catCars)));
pin('a category slug is stored as its id', '{"v":2,"params":{"sCategory":[' . $catCars . ']}}', $envelope(array('sCategory' => 'cars')));
pin(
    'a child chosen with its parent is reduced to the parent',
    '{"v":2,"params":{"sCategory":[' . $catCars . ']}}',
    $envelope(array('sCategory' => array((string)$catCarsSport, (string)$catCars)))
);
pin(
    'a child chosen alone stays',
    '{"v":2,"params":{"sCategory":[' . $catCarsSport . ']}}',
    $envelope(array('sCategory' => (string)$catCarsSport))
);
pin('an unknown slug is dropped, as the page drops it', '{"v":2,"params":{}}', $envelope(array('sCategory' => 'no-such')));

// A custom field linked to the child only: which fields are searchable depends on every
// chosen category, so with a meta filter the child must survive the round trip.
$fTrim = $seedMetaField('Trim', 'DROPDOWN');
$linkFieldToCategory($fTrim, $catCarsSport);
$seedItemMeta($i7, $fTrim, 'gt');
$trimRequest = array('sCategory' => array((string)$catCars, (string)$catCarsSport), 'meta' => array($fTrim => 'gt'));
pin(
    'with a meta filter, parent and child are both kept',
    '{"v":2,"params":{"meta":{"' . $fTrim . '":"gt"},"sCategory":[' . $catCars . ',' . $catCarsSport . ']}}',
    $envelope($trimRequest)
);
$pageSearch = new Search();
\mindstellar\search\SearchBuilder::apply(SearchCriteria::fromRequest($trimRequest), $pageSearch);
pin(
    'the page applies the child-only field: only the Sports Car carrying it matches',
    array($i7),
    $sorted($ids($pageSearch->doSearch()))
);
pin('and the replayed alert applies it too', array($i7), $replayV2($envelope($trimRequest)));

harness_section('alert-replay: (d) v2 — same ids as the v1 pins');

$v2Cases = array(
    array('category', array('sCategory' => (string)$catCars), array($i1, $i2, $i3, $i6, $i7)),
    array('country by code', array('sCountry' => 'us'), array($i1, $i2, $i3, $i4, $i5, $i7)),
    array('country by name', array('sCountry' => 'United States'), array($i1, $i2, $i3, $i4, $i5, $i7)),
    array('region by id', array('sRegion' => (string)$regionAlpha), array($i1, $i2, $i7)),
    array('region by name', array('sRegion' => 'Alpha'), array($i1, $i2, $i7)),
    array('city by id', array('sCity' => (string)$cityAville), array($i1, $i2, $i7)),
    array('city by name', array('sCity' => 'Aville'), array($i1, $i2, $i7)),
    array('city area by id', array('sCityArea' => (string)$areaA1), array($i1, $i2)),
    array('city area by name', array('sCityArea' => 'Downtown'), array($i1, $i2)),
    array('price range', array('sPriceMin' => '1000', 'sPriceMax' => '10000'), array($i1, $i3, $i5)),
    array('pattern', array('sPattern' => 'vintage'), array($i1, $i3, $i6)),
    array('with picture', array('bPic' => '1'), array($i1, $i2, $i3)),
    // Custom fields are only searchable inside a category that carries them, so the
    // category comes along; every matching item is a Cars item.
    array('meta TEXT', array('sCategory' => (string)$catCars, 'meta' => array($fText => 'mileage')), array($i2)),
    array('meta DROPDOWN', array('sCategory' => (string)$catCars, 'meta' => array($fDropdown => 'red')), array($i1, $i3)),
    array('meta RADIO', array('sCategory' => (string)$catCars, 'meta' => array($fRadio => 'used')), array($i1, $i3)),
    array('meta CHECKBOX', array('sCategory' => (string)$catCars, 'meta' => array($fCheckbox => '1')), array($i1)),
    array(
        'meta DATE',
        array('sCategory' => (string)$catCars, 'meta' => array($fDate => (string)strtotime('2026-03-15 12:00:00'))),
        array($i1)
    ),
    array(
        'meta DATEINTERVAL',
        array('sCategory' => (string)$catCars, 'meta' => array($fDateInterval => array(
            'from' => (string)strtotime('2026-10-15 00:00:00'),
            'to'   => (string)strtotime('2026-10-20 23:59:59'),
        ))),
        array($i1, $i2)
    ),
    array(
        'meta NUMBER',
        array('sCategory' => (string)$catCars, 'meta' => array($fNumber => array('from' => '3000', 'to' => '20000'))),
        array($i1, $i2)
    ),
    array('meta value with an apostrophe', array('sCategory' => (string)$catCars, 'meta' => array($fDropdown => "red's pick")), array($i6)),
    array(
        'meta DATE (the hand-written v1 day)',
        array('sCategory' => (string)$catCars, 'meta' => array($fDate => (string)strtotime('2026-06-01 00:00:00'))),
        array($i2)
    ),
    array(
        'meta DATEINTERVAL (the hand-written v1 range)',
        array('sCategory' => (string)$catCars, 'meta' => array($fDateInterval => array(
            'from' => (string)strtotime('2026-11-01 00:00:00'),
            'to'   => (string)strtotime('2026-11-15 23:59:59'),
        ))),
        array($i1, $i2)
    ),
    array(
        'meta NUMBER (the hand-written v1 bound)',
        array('sCategory' => (string)$catCars, 'meta' => array($fNumber => array('from' => '40000', 'to' => '50000'))),
        array($i3)
    ),
);
foreach ($v2Cases as $case) {
    list($label, $request, $want) = $case;
    pin('v2 ' . $label, $sorted($want), $replayV2($envelope($request)));
}

harness_section('alert-replay: (d) v2 — quirks v1 has, fixed');

pin(
    'QUIRK FIXED (user_ids ignored): a from-these-users alert matches only those users\' items',
    $sorted(array($i1, $i2, $i3, $i4, $i7)),
    $replayV2($envelope(array('sUser' => array((string)$userSeller1, 'seller2'))))
);
pin(
    'QUIRK FIXED (categories not re-expanded): the parent id alone matches its Sports Cars child too,'
        . ' and the user filter applies',
    array($i6),
    $replayV2($envelope(array('sCategory' => (string)$catCars, 'sUser' => (string)$userSeller3)))
);
pin(
    'QUIRK FIXED (unquoted dropdown matched nothing): the value is always quoted, so red matches',
    $sorted(array($i1, $i3)),
    $replayV2($envelope(array('sCategory' => (string)$catCars, 'meta' => array($fDropdown => 'red'))))
);

harness_section('alert-replay: (d) v2 — rows that are not replayed');

$logFile = tempnam(sys_get_temp_dir(), 'alert-replay-log');
$oldLog  = ini_set('error_log', $logFile);
pin('a held row is skipped', null, AlertReplay::search(array('pk_i_id' => 77, 's_search' => '{"v":2,"held":"unknown SQL"}')));
ini_set('error_log', (string)$oldLog);
$logged = (string)file_get_contents($logFile);
unlink($logFile);
pin('and logs one line naming the alert', 1, substr_count($logged, 'skipped alert #77'));
pin(
    'an oversize v2 row is skipped before it is decoded',
    array('not replayed'),
    $replayV2('{"v":2,"params":{"sPattern":"' . str_repeat('a', AlertEnvelope::MAX_BYTES) . '"}}')
);
pin(
    'a v2 row nested deeper than any valid one is skipped',
    array('not replayed'),
    $replayV2('{"v":2,"params":{"meta":{"3":{"from":[[[[[["x"]]]]]]}}}}')
);
pin(
    'a v2 row that is not canonical is skipped too (re-validated before replay)',
    array('not replayed'),
    $replayV2('{"v":2,"params":{"sPattern":"a","bPic":1}}')
);
pin('a v2 row carrying a v1 SQL key is skipped', array('not replayed'), $replayV2('{"v":2,"params":{"no_catched_conditions":["1=1"]}}'));
pin('a row that is not JSON is skipped', null, AlertReplay::search(array('s_search' => 'nope')));
$v1Row = array('s_search' => json_encode($buildBlob(static function (Search $s) use ($regionAlpha) {
    $s->addRegion($regionAlpha);
})));
pin('a v1 row still replays through setJsonAlert()', $sorted(array($i1, $i2, $i7)), $sorted($ids(AlertReplay::search($v1Row)->doSearch())));

harness_section('alert-replay: (d) v2 — setJsonAlert() delegates');

$s = new Search();
$s->setJsonAlert(json_decode($envelope(array('sRegion' => 'Alpha')), true));
pin('setJsonAlert() with a v2 envelope matches what AlertReplay does', $sorted(array($i1, $i2, $i7)), $sorted($ids($s->doSearch())));
$s = new Search();
$s->setJsonAlert(array('v' => 2, 'params' => array('no_catched_conditions' => array('1=1'))));
pin('setJsonAlert() with an invalid v2 envelope matches nothing, not everything', array(), $ids($s->doSearch()));

harness_section('alert-replay: (d) v2 — search_conditions at replay');

$seen     = array();
$listener = static function ($params, $search = null, $context = null) use (&$seen, $prefix, $i2) {
    $seen[] = array(
        'params'   => $params,
        'search'   => $search,
        'context'  => $context,
        'getParam' => Params::getParam('sRegion'),
        'shared'   => Search::newInstance(),
    );
    // The classic plugin pattern: reach the search through the shared instance.
    Search::newInstance()->addConditions(sprintf('%st_item.pk_i_id = %d', $prefix, $i2));
};
osc_add_hook('search_conditions', $listener);

Params::setParam('sRegion', 'outer');
$sharedBefore = Search::newInstance();
$replayed     = AlertReplay::search(array('s_search' => $envelope(array('sRegion' => 'Alpha'))));
$result       = $sorted($ids($replayed->doSearch()));
osc_remove_hook('search_conditions', $listener);

pin('the listener ran once', 1, count($seen));
pin('its condition, added through Search::newInstance(), landed on the replayed search', array($i2), $result);
check('it was handed the replayed Search', $seen[0]['search'] === $replayed);
check('Search::newInstance() was the replayed Search while it ran', $seen[0]['shared'] === $replayed);
pin('the context is alert', 'alert', $seen[0]['context']);
pin('Params::getParam() read the stored params', 'Alpha', $seen[0]['getParam']);
pin('the params argument is the stored params, shaped as a request', array('sRegion' => 'Alpha'), $seen[0]['params']);
pin('the request params are restored afterwards', 'outer', Params::getParam('sRegion'));
check('the shared Search is restored afterwards', Search::newInstance() === $sharedBefore);
Params::unsetParam('sRegion');

harness_section('alert-replay: (d) v2 — display');

$v2Display = json_decode($envelope(array(
    'sPattern'  => 'vintage',
    'sCategory' => (string)$catCars,
    'sCity'     => array((string)$cityAville, 'Bville'),
    'sCountry'  => 'US',
    'sPriceMin' => '1000',
)), true);
$raw = osc_get_raw_search($v2Display);
pin('osc_get_raw_search(): pattern', 'vintage', $raw['sPattern'] ?? null);
pin('osc_get_raw_search(): category names', array('Cars'), $raw['aCategories'] ?? null);
pin('osc_get_raw_search(): city ids resolved to names, names as given', array('Aville', 'Bville'), $raw['cities'] ?? null);
pin('osc_get_raw_search(): country code resolved to its name', array('United States'), $raw['countries'] ?? null);
pin('osc_get_raw_search(): price unscaled, empty bounds left out', array(1000, false), array($raw['price_min'] ?? null, isset($raw['price_max'])));
pin('osc_get_raw_search(): the stored params come along', $v2Display['params'], $raw['params'] ?? null);
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
$alertSearchFor = static function (array $row): string {
    View::newInstance()->_exportVariableToView('alerts', array($row));
    View::newInstance()->_reset('alerts');
    View::newInstance()->_next('alerts');

    return osc_alert_search();
};
$v2Row = $envelope(array('sPattern' => 'vintage', 'sCategory' => (string)$catCars, 'sCity' => (string)$cityAville, 'sPriceMax' => '5000'));
pin(
    'osc_alert_search(): a v2 row gives the legacy display keys plus params',
    array(
        'sPattern'    => 'vintage',
        'aCategories' => array($catCars),
        'city_areas'  => array(),
        'cities'      => array('Aville'),
        'regions'     => array(),
        'countries'   => array(),
        'price_min'   => 0,
        'price_max'   => 5000,
        'params'      => json_decode($v2Row, true)['params'],
    ),
    json_decode($alertSearchFor(array('pk_i_id' => 1, 's_search' => $v2Row)), true)
);
$v1Json = json_encode($legacyBase);
pin('osc_alert_search(): a v1 row comes back as stored', $v1Json, $alertSearchFor(array('pk_i_id' => 2, 's_search' => $v1Json)));

harness_section('alert-replay: (d) v2 — page size');

$rpp = new ReflectionProperty('Search', 'results_per_page');
$rpp->setAccessible(true);
pin('the limit option sets the page size', 7, $rpp->getValue(AlertReplay::search(array('s_search' => $v2Row), array('limit' => 7))));
pin('without it the page size is 10', 10, $rpp->getValue(AlertReplay::search(array('s_search' => $v2Row))));
check(
    'the alert cron replays with the search page size',
    strpos(
        (string)file_get_contents(ABS_PATH . 'oc-includes/osclass/alerts.php'),
        "array('limit' => osc_default_results_per_page_at_search())"
    ) !== false
);

pin(
    'legacyFields(): categories stay ids for theme code that looks them up',
    array($catCars),
    AlertEnvelope::legacyFields($v2Display['params'])['aCategories']
);

// addCategory() populates the object cache keyed by category id (toSubTree() and
// friends). This file runs early in the suite, so those ids are the *first* ones
// TRUNCATE's AUTO_INCREMENT reset hands out — a later file that reseeds its own
// category at the same id would otherwise read this file's cached row back for it.
if (class_exists('Object_Cache_Factory')) {
    Object_Cache_Factory::newInstance()->flush();
}
$categoryReset->setValue(null, null);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/alert-replay.php */
