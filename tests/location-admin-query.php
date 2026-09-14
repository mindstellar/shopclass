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
 * The read side of the location admin screen.
 *
 * A region can hold tens of thousands of cities, so every list is a bounded page. What
 * would go wrong here is quiet: a total that counts the wrong rows reads as a pager that
 * stops early, an unescaped '%' or '_' in a search matches places the admin never typed,
 * a page query that stops using its index is fine on a test site and a timeout on a real
 * one, and an impact number that misses a branch of the cascade tells the admin a delete
 * is safe when it removes listings.
 *
 * The impact numbers are pinned against a seed where listings and users sit on every
 * branch the model cascades follow: placed by city only, by region only, by country only,
 * by city area only, and by several at once (counted once).
 *
 * DB-backed. Usage:  php tests/location-admin-query.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\location\LocationAdminQuery;

$admin  = scratchdb_session('osc_location_admin_query');
$prefix = DB_TABLE_PREFIX;

/** Insert $rows (lists of already-escaped SQL literals) into $table in batches. */
$bulk = static function (string $table, string $columns, array $rows) use ($admin, $prefix): void {
    foreach (array_chunk($rows, 1000) as $chunk) {
        $sql = "INSERT INTO {$prefix}{$table} ({$columns}) VALUES (" . implode('), (', $chunk) . ')';
        if (!$admin->query($sql)) {
            fwrite(STDERR, "bulk insert failed: {$admin->error}\n");
            exit(2);
        }
    }
};
$lit = static function (string $value) use ($admin): string {
    return "'" . $admin->real_escape_string($value) . "'";
};

/* ---------------------------------------------------------------------------
 * Seed: 3 countries, 40 regions, 59,006 cities.
 * ------------------------------------------------------------------------ */

seed_locale($admin);
seed_currency($admin);
$category = seed_category($admin, 'Things');

seed_country($admin, 'AA', 'Alphaland');
seed_country($admin, 'BB', 'Betaland');
seed_country($admin, 'CC', 'Gammaland');

$bigRegion = seed_region($admin, 'AA', 'Big Region');
seed_region($admin, 'AA', '100% Pure');
seed_region($admin, 'AA', '100 Percent');
$smallRegions = array();
for ($i = 1; $i <= 17; $i++) {
    $smallRegions[] = seed_region($admin, 'AA', sprintf('Region %02d', $i));
}
for ($i = 1; $i <= 15; $i++) {
    $smallRegions[] = seed_region($admin, 'BB', sprintf('Province %02d', $i));
}
for ($i = 1; $i <= 5; $i++) {
    $smallRegions[] = seed_region($admin, 'CC', sprintf('District %02d', $i));
}
$aaSecond = $smallRegions[0];   // Region 01
$bbFirst  = $smallRegions[17];  // Province 01

$rows = array();
for ($i = 0; $i < 20000; $i++) {
    $name   = sprintf('Town %05d', $i);
    $rows[] = "$bigRegion, " . $lit($name) . ', ' . $lit(strtolower(str_replace(' ', '-', $name))) . ", 'AA', 1";
}
foreach (array('50% Off', '50 Off', 'Snake_case', 'Snakexcase', 'Back\\slash', 'Backxslash') as $special) {
    $rows[] = "$bigRegion, " . $lit($special) . ", 'special', 'AA', 1";
}
foreach ($smallRegions as $n => $regionId) {
    $country = $n < 17 ? 'AA' : ($n < 32 ? 'BB' : 'CC');
    for ($i = 0; $i < 1000; $i++) {
        $rows[] = "$regionId, " . $lit(sprintf('Village %04d', $i)) . ", 'village', '$country', 1";
    }
}
$bulk('t_city', 'fk_i_region_id, s_name, s_slug, fk_c_country_code, b_active', $rows);
unset($rows);

$cityId = static function (int $regionId, string $name) use ($admin, $prefix): int {
    $stmt = $admin->prepare("SELECT pk_i_id FROM {$prefix}t_city WHERE fk_i_region_id = ? AND s_name = ?");
    $stmt->bind_param('is', $regionId, $name);
    $stmt->execute();
    $id = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();

    return $id;
};
$t0 = $cityId($bigRegion, 'Town 00000');
$t1 = $cityId($bigRegion, 'Town 00001');
$t2 = $cityId($bigRegion, 'Town 00002');
$v  = $cityId($aaSecond, 'Village 0000');
$vb = $cityId($bbFirst, 'Village 0000');

$admin->query("UPDATE {$prefix}t_city SET b_active = 0 WHERE pk_i_id = $t2");
// Old rows may carry no country of their own; the region's country stands in.
$admin->query("UPDATE {$prefix}t_city SET fk_c_country_code = NULL WHERE fk_i_region_id = $bigRegion AND s_name = 'Backxslash'");
$admin->query("UPDATE {$prefix}t_city SET i_source_id = 1356, d_coord_lat = 19.076000, d_coord_long = 72.878000 WHERE pk_i_id = $t0");
$admin->query("INSERT INTO {$prefix}t_city_area (pk_i_id, fk_i_city_id, s_name) VALUES (1, $t1, 'Old Quarter')");

$admin->query("INSERT INTO {$prefix}t_city_stats (fk_i_city_id, i_num_items) VALUES ($t0, 7), ($t1, 3)");
$admin->query("INSERT INTO {$prefix}t_region_stats (fk_i_region_id, i_num_items) VALUES ($bigRegion, 10)");
$admin->query("INSERT INTO {$prefix}t_country_stats (fk_c_country_code, i_num_items) VALUES ('AA', 12)");

/** Place a listing; null leaves that column empty. */
$listing = static function (?string $country, ?int $region, ?int $city, ?int $area) use ($admin, $prefix, $category): int {
    $id   = seed_item($admin, $category, null, 'Listing', 1.0, 1, 1, 'en_US', 'AA');
    $stmt = $admin->prepare(
        "UPDATE {$prefix}t_item_location SET fk_c_country_code = ?, fk_i_region_id = ?, fk_i_city_id = ?,"
        . ' fk_i_city_area_id = ? WHERE fk_i_item_id = ?'
    );
    $stmt->bind_param('siiii', $country, $region, $city, $area, $id);
    $stmt->execute();
    $stmt->close();

    return $id;
};
/** Place a user; null leaves that column empty. */
$user = static function (string $name, ?string $country, ?int $region, ?int $city, ?int $area) use ($admin, $prefix): int {
    $id   = seed_user($admin, $name, $name . '@example.test');
    $stmt = $admin->prepare(
        "UPDATE {$prefix}t_user SET fk_c_country_code = ?, fk_i_region_id = ?, fk_i_city_id = ?,"
        . ' fk_i_city_area_id = ? WHERE pk_i_id = ?'
    );
    $stmt->bind_param('siiii', $country, $region, $city, $area, $id);
    $stmt->execute();
    $stmt->close();

    return $id;
};

$listing('AA', $bigRegion, $t0, null);   // every column
$listing('AA', $bigRegion, $t1, null);
$listing('AA', null, $t0, null);         // city only
$listing(null, $bigRegion, null, null);  // region only
$listing('AA', $aaSecond, $v, null);     // another region of the same country
$listing('AA', null, null, null);        // country only
$listing('BB', $bbFirst, $vb, null);     // another country
$listing(null, null, null, 1);           // city area only

$user('u1', 'AA', $bigRegion, $t0, null);
$user('u2', null, null, $t1, null);
$user('u3', null, $aaSecond, null, null);
$user('u4', 'BB', null, null, null);
$user('u5', null, null, null, null);
$user('u6', null, null, null, 1);

$admin->query("ANALYZE TABLE {$prefix}t_city, {$prefix}t_region, {$prefix}t_country");

$q = new LocationAdminQuery();

/* ---------------------------------------------------------------------------
 * Lists: shape, totals, paging.
 * ------------------------------------------------------------------------ */

harness_section('countries()');

$page = $q->countries('', 1, 50);
pin('three countries', 3, $page['total']);
pin('countries have no parent', null, $page['parent']);
pin('row keys', array('code', 'name', 'slug', 'listings', 'regions'), array_keys($page['rows'][0]));
pin('first country by name', 'AA', $page['rows'][0]['code']);
pin('listings come from t_country_stats', 12, $page['rows'][0]['listings']);
pin('region count per row', 20, $page['rows'][0]['regions']);
pin('a country with no stats row reads 0 listings', 0, $page['rows'][1]['listings']);
pin('prefix search', array('BB'), array_column($q->countries('Beta', 1, 50)['rows'], 'code'));
$page = $q->countries('Zeta', 1, 50);
pin('no match: zero total, no rows', array(0, array()), array($page['total'], $page['rows']));

harness_section('regions()');

$page = $q->regions('AA', '', 1, 50);
pin('regions of AA', 20, $page['total']);
pin('regions carry their country for the breadcrumb', array('level' => 'country', 'id' => 'AA', 'name' => 'Alphaland'), $page['parent']);
pin('row keys', array('id', 'country', 'name', 'slug', 'active', 'listings', 'cities'), array_keys($page['rows'][0]));
$big = array_values(array_filter($page['rows'], static fn ($r) => $r['id'] === $bigRegion))[0] ?? array();
pin('city count per row', 20006, $big['cities'] ?? null);
pin('listings come from t_region_stats', 10, $big['listings'] ?? null);
pin('regions of BB', 15, $q->regions('BB', '', 1, 50)['total']);
$page = $q->regions('ZZ', '', 1, 50);
pin('unknown country: no parent, no rows', array(null, 0, array()), array($page['parent'], $page['total'], $page['rows']));
pin('malformed country code: no parent', null, $q->regions('ZZZ', '', 1, 50)['parent']);
pin('"%" is literal', array('100% Pure'), array_column($q->regions('AA', '100%', 1, 50)['rows'], 'name'));
pin('prefix, paged', array('Region 05', 'Region 06'), array_column($q->regions('AA', 'Region', 3, 2)['rows'], 'name'));

harness_section('cities()');

$page = $q->cities($bigRegion, '', 1, 50);
pin('total counts the whole region', 20006, $page['total']);
pin(
    'cities carry their region and country for the breadcrumb',
    array('level' => 'region', 'id' => $bigRegion, 'name' => 'Big Region', 'country' => array('code' => 'AA', 'name' => 'Alphaland')),
    $page['parent']
);
$missing = $q->cities(999999999, '', 1, 50);
pin('unknown region: no parent, no rows', array(null, 0, array()), array($missing['parent'], $missing['total'], $missing['rows']));
pin('a city with no country of its own takes the region\'s', array('AA'), array_column($q->cities($bigRegion, 'Backx', 1, 50)['rows'], 'country'));
pin('a page holds per rows', 50, count($page['rows']));
pin('row keys', array('id', 'region', 'country', 'name', 'slug', 'active', 'listings'), array_keys($page['rows'][0]));

$page = $q->cities($bigRegion, 'Town 1', 1, 50);
pin('prefix total', 10000, $page['total']);
pin('prefix page starts in name order', 'Town 10000', $page['rows'][0]['name']);
$page = $q->cities($bigRegion, 'Town 1', 200, 50);
pin('last page', array(50, 'Town 19999'), array(count($page['rows']), end($page['rows'])['name']));
$page = $q->cities($bigRegion, 'Town 1', 201, 50);
pin('past the end: no rows, same total', array(array(), 10000), array($page['rows'], $page['total']));
$page = $q->cities($bigRegion, 'Town', 400, 50);
pin('deep page', array('Town 19950', 'Town 19999'), array($page['rows'][0]['name'], end($page['rows'])['name']));

$page = $q->cities($bigRegion, 'Town 0000', 1, 50);
pin('listings from t_city_stats', array(7, 3, 0), array_slice(array_column($page['rows'], 'listings'), 0, 3));
pin('b_active = 0 reads inactive', array(true, true, false), array_slice(array_column($page['rows'], 'active'), 0, 3));

pin('page below 1 clamps to 1', 1, $q->cities($bigRegion, '', 0, 50)['page']);
pin('per 0 falls back to 50', 50, $q->cities($bigRegion, '', 1, 0)['per']);
pin('per above 200 clamps', array(200, 200), array($q->cities($bigRegion, '', 1, 5000)['per'], count($q->cities($bigRegion, '', 1, 5000)['rows'])));
pin('surrounding spaces ignored', 10000, $q->cities($bigRegion, '  Town 1 ', 1, 50)['total']);

harness_section('LIKE escaping');

pin('"%" matches only a literal percent', array('50% Off'), array_column($q->cities($bigRegion, '50%', 1, 50)['rows'], 'name'));
pin('"_" matches only a literal underscore', array('Snake_case'), array_column($q->cities($bigRegion, 'Snake_', 1, 50)['rows'], 'name'));
pin('"\\" matches only a literal backslash', array('Back\\slash'), array_column($q->cities($bigRegion, 'Back\\', 1, 50)['rows'], 'name'));
pin('a lone "%" is not "everything"', 0, $q->cities($bigRegion, '%', 1, 50)['total']);
pin('a lone "_" is not "any one character"', 0, $q->cities($bigRegion, '_', 1, 50)['total']);

harness_section('initials()');

pin('city initials, upper-cased, in order', array('5', 'B', 'S', 'T'), $q->initials('city', $bigRegion));
$admin->query("INSERT INTO {$prefix}t_city (fk_i_region_id, s_name, s_slug, fk_c_country_code, b_active) VALUES ($aaSecond, '(Old) Mill', 'old-mill', 'AA', 1), ($aaSecond, 'łódź', 'lodz', 'AA', 1)");
pin('punctuation skipped, multibyte upper-cased', array('V', 'Ł'), $q->initials('city', $aaSecond));
$admin->query("DELETE FROM {$prefix}t_city WHERE fk_i_region_id = $aaSecond AND s_slug IN ('old-mill', 'lodz')");
pin('region initials', array('1', 'B', 'R'), $q->initials('region', 'AA'));
pin('country initials', array('A', 'B', 'G'), $q->initials('country', null));
pin('more than max: no strip', null, $q->initials('city', $bigRegion, 3));
pin('exactly max: strip', array('5', 'B', 'S', 'T'), $q->initials('city', $bigRegion, 4));
pin('empty level: no strip', null, $q->initials('city', 999999999));
$plan = osc_db_select(
    'EXPLAIN SELECT DISTINCT UPPER(LEFT(s_name, 1)) AS i FROM ' . DB_TABLE_PREFIX . 't_city WHERE fk_i_region_id = ? ORDER BY i LIMIT 38',
    array($bigRegion)
);
check('city initials read the region index', in_array($plan[0]['key'] ?? '', array('idx_region_name', 'fk_i_region_id'), true), (string) ($plan[0]['key'] ?? 'NULL'));
$start = microtime(true);
$q->initials('city', $bigRegion);
check('city initials of a 20k region under 50 ms', (microtime(true) - $start) < 0.05);

/* ---------------------------------------------------------------------------
 * Country code case: the importer stores fk_c_country_code lowercase on some
 * region/city rows; every code this class hands back must be upper.
 * ------------------------------------------------------------------------ */

harness_section('country code case');

seed_country($admin, 'MT', 'Malta');
$mtRegion = seed_region($admin, 'mt', 'Gozo');
$mtCity   = seed_city($admin, $mtRegion, 'Valletta', 'mt');

pin('regions(): lowercase-stored code returns upper', 'MT', $q->regions('MT', '', 1, 50)['rows'][0]['country']);
pin('cities(): lowercase-stored code returns upper', 'MT', $q->cities($mtRegion, '', 1, 50)['rows'][0]['country']);
pin('cities(): parent country code is upper', 'MT', $q->cities($mtRegion, '', 1, 50)['parent']['country']['code']);
pin('record(region): lowercase-stored code returns upper', 'MT', $q->record('region', $mtRegion)['country']['code']);
pin('record(city): lowercase-stored code returns upper', 'MT', $q->record('city', $mtCity)['country']['code']);
pin('searchAll() regions: lowercase-stored code returns upper', 'MT', $q->searchAll('Gozo')['regions'][0]['country']);
pin('searchAll() cities: lowercase-stored code returns upper', 'MT', $q->searchAll('Valletta')['cities'][0]['country']);

/* ---------------------------------------------------------------------------
 * searchAll()
 * ------------------------------------------------------------------------ */

harness_section('searchAll()');

pin('blank query searches nothing', array('countries' => array(), 'regions' => array(), 'cities' => array()), $q->searchAll('  '));
$hits = $q->searchAll('Snake');
$names = array_column($hits['cities'], 'name');
sort($names);
pin('city hits', array('Snake_case', 'Snakexcase'), $names);
pin('city hit carries its path', array('Big Region', 'AA', 'Alphaland'), array($hits['cities'][0]['region_name'], $hits['cities'][0]['country'], $hits['cities'][0]['country_name']));
pin('city hit keys', array('id', 'name', 'slug', 'active', 'region', 'region_name', 'country', 'country_name'), array_keys($hits['cities'][0]));
$hits = $q->searchAll('Province 0');
pin('region hits carry their country', array(9, 'Betaland'), array(count($hits['regions']), $hits['regions'][0]['country_name']));
pin('country hits', array('CC'), array_column($q->searchAll('Gamma')['countries'], 'code'));
pin('per level defaults to 10', 10, count($q->searchAll('Village')['cities']));
pin('per level clamps to 50', 50, count($q->searchAll('Village', 500)['cities']));
pin('per level 0 means 10', array(10, 10), array(count($q->searchAll('Village', 0)['cities']), count($q->searchAll('Village', -3)['cities'])));
pin('a lone "%" finds nothing', array('countries' => array(), 'regions' => array(), 'cities' => array()), $q->searchAll('%'));
pin('"%" is literal in regions', array('100% Pure'), array_column($q->searchAll('100%')['regions'], 'name'));
pin('"%" is literal in cities', array('50% Off'), array_column($q->searchAll('50%')['cities'], 'name'));

/* ---------------------------------------------------------------------------
 * impact() against the seed.
 * ------------------------------------------------------------------------ */

harness_section('impact()');

$nums = static function (array $impact): array {
    return array_values(array_intersect_key($impact, array_flip(array('found', 'regions', 'cities', 'children', 'listings', 'users'))));
};

// [found, regions, cities, children, listings, users]
pin('city: listing by city, by city+region', array(1, 0, 0, 0, 2, 1), $nums($q->impact('city', array($t0))));
pin('city: its city area\'s listing and user count', array(1, 0, 0, 0, 2, 2), $nums($q->impact('city', array($t1))));
pin('two cities sum', array(2, 0, 0, 0, 4, 3), $nums($q->impact('city', array($t0, $t1))));
pin('region: every branch counted once', array(1, 0, 20006, 20006, 5, 3), $nums($q->impact('region', array($bigRegion))));
pin('two regions', array(2, 0, 21006, 21006, 6, 4), $nums($q->impact('region', array($bigRegion, $aaSecond))));
pin('country: regions, cities, listings, users', array(1, 20, 37006, 20, 7, 4), $nums($q->impact('country', array('AA'))));
pin('two countries', array(2, 35, 52006, 35, 8, 5), $nums($q->impact('country', array('AA', 'BB'))));
pin('country code is case-insensitive', $nums($q->impact('country', array('AA'))), $nums($q->impact('country', array(' aa '))));
pin('ids given as strings', $nums($q->impact('city', array($t0))), $nums($q->impact('city', array((string) $t0))));
pin('duplicate ids count once', $nums($q->impact('city', array($t0))), $nums($q->impact('city', array($t0, $t0, (string) $t0))));
pin('requested counts distinct ids', 2, $q->impact('city', array($t0, $t1, $t0))['requested']);
pin('unknown id: found below requested', array(1, 0), array_values(array_intersect_key($q->impact('city', array(999999999)), array('requested' => 1, 'found' => 1))));
pin('one of two gone: found below requested', array(2, 1), array_values(array_intersect_key($q->impact('city', array($t0, 999999999)), array('requested' => 1, 'found' => 1))));
pin('no ids', array(0, 0, 0, 0, 0, 0), $nums($q->impact('region', array())));
pin('level echoed', 'region', $q->impact('region', array($bigRegion))['level']);

/** The InvalidArgumentException code impact() throws, or 0. */
$errorCode = static function (string $level, array $ids) use ($q): int {
    try {
        $q->impact($level, $ids);
    } catch (InvalidArgumentException $e) {
        return $e->getCode();
    }

    return 0;
};
pin('unknown level throws', LocationAdminQuery::ERR_LEVEL, $errorCode('planet', array(1)));
foreach (array('abc', -4, 0, '1 OR 1=1', array(1), null, 1.5) as $junk) {
    pin('malformed id refused: ' . describe($junk), LocationAdminQuery::ERR_BAD_ID, $errorCode('city', array($t0, $junk)));
}
pin('malformed country code refused', LocationAdminQuery::ERR_BAD_ID, $errorCode('country', array('AAA')));
pin('200 ids accepted', 0, $errorCode('city', range(1, 200)));
pin('201 ids refused, not truncated', LocationAdminQuery::ERR_TOO_MANY, $errorCode('city', range(1, 201)));

/* ---------------------------------------------------------------------------
 * record()
 * ------------------------------------------------------------------------ */

harness_section('record()');

$r = $q->record('city', $t0);
pin('city record', array('city', $t0, 'Town 00000', true, 1356, 19.076, 72.878), array($r['level'], $r['id'], $r['name'], $r['active'], $r['source_id'], $r['lat'], $r['long']));
pin('city parents', array(array('code' => 'AA', 'name' => 'Alphaland'), array('id' => $bigRegion, 'name' => 'Big Region')), array($r['country'], $r['region']));
pin('city counts', array('children' => 0, 'regions' => 0, 'cities' => 0, 'listings' => 2, 'users' => 1), $r['counts']);
pin('inactive city', false, $q->record('city', (string) $t2)['active']);

$r = $q->record('region', (string) $bigRegion);
pin('region record', array('region', $bigRegion, 'Big Region', array('code' => 'AA', 'name' => 'Alphaland'), null), array($r['level'], $r['id'], $r['name'], $r['country'], $r['region']));
pin('region counts', array('children' => 20006, 'regions' => 0, 'cities' => 20006, 'listings' => 5, 'users' => 3), $r['counts']);

$r = $q->record('country', 'aa');
pin('country record', array('country', 'AA', 'Alphaland', null, null), array($r['level'], $r['id'], $r['name'], $r['country'], $r['region']));
pin('country counts', array('children' => 20, 'regions' => 20, 'cities' => 37006, 'listings' => 7, 'users' => 4), $r['counts']);

pin('without counts', null, $q->record('region', $bigRegion, false)['counts']);
pin('missing row', null, $q->record('city', 999999999));
pin('junk id', null, $q->record('region', 'x'));

/* ---------------------------------------------------------------------------
 * The page queries walk the new indexes and stay bounded.
 * ------------------------------------------------------------------------ */

harness_section('index use and cost');

$listQuery = new ReflectionMethod(LocationAdminQuery::class, 'listQuery');
$listQuery->setAccessible(true);

/** EXPLAIN rows for the query at $index (0 count, 1 page) of listQuery(). */
$explain = static function (array $args, int $index) use ($q, $listQuery): array {
    $built = $listQuery->invokeArgs($q, $args);

    return osc_db_select('EXPLAIN ' . $built[$index], $built[2]);
};
$describe = static fn (array $plan): string => implode('; ', array_map(static fn ($r) => ($r['table'] ?? '') . ' ' . ($r['type'] ?? '') . ' ' . ($r['key'] ?? 'NULL') . ' / ' . ($r['Extra'] ?? ''), $plan));

foreach (array(
    'cities, no search, deep page' => array(array('city', $bigRegion, '', 400, 50), 'idx_region_name'),
    'cities, prefix search'        => array(array('city', $bigRegion, 'Town 1', 3, 50), 'idx_region_name'),
    'regions, prefix search'       => array(array('region', 'AA', 'Region', 1, 50), 'idx_country_name'),
) as $label => [$args, $index]) {
    $page  = $explain($args, 1);
    $count = $explain($args, 0);
    // The page is a deferred join: the derived table pages keys off the index, the join reads at most a page of rows.
    $ids    = array_values(array_filter($page, static fn ($r) => strcasecmp((string) ($r['select_type'] ?? ''), 'DERIVED') === 0))[0] ?? array();
    $joined = array_values(array_filter($page, static fn ($r) => ($r['table'] ?? '') === 't'))[0] ?? array();
    check("$label: page ids come from $index", ($ids['key'] ?? '') === $index, $describe($page));
    check("$label: page ids read the index only", preg_match('/Using index(?! condition)/', (string) ($ids['Extra'] ?? '')) === 1, $describe($page));
    check("$label: page ids do not sort", stripos((string) ($ids['Extra'] ?? ''), 'filesort') === false, $describe($page));
    check("$label: page rows are fetched by primary key", ($joined['type'] ?? '') === 'eq_ref' && ($joined['key'] ?? '') === 'PRIMARY', $describe($page));
    // The optimizer may count through the single-column parent index instead; either is bounded by the parent.
    check("$label: count query reads by parent index, not a scan", ($count[0]['type'] ?? 'ALL') !== 'ALL' && ($count[0]['key'] ?? null) !== null, $describe($count));
}

harness_assert_no_n_plus_1(
    'cities(): query count does not grow with page size',
    static function (int $n) use ($q, $bigRegion): void {
        $q->cities($bigRegion, '', 1, $n);
    },
    5,
    200
);
harness_assert_no_n_plus_1(
    'regions(): query count does not grow with page size',
    static function (int $n) use ($q): void {
        $q->regions('AA', '', 1, $n);
    },
    2,
    20
);

// Best of five, so a busy CI host does not fail a query that is really fast.
$fastest = static function (callable $fn): float {
    $best = INF;
    for ($i = 0; $i < 5; $i++) {
        $start = microtime(true);
        $fn();
        $best = min($best, (microtime(true) - $start) * 1000);
    }

    return $best;
};
foreach (array(
    'cities, deep page'     => static fn () => $q->cities($bigRegion, '', 400, 50),
    'cities, prefix search' => static fn () => $q->cities($bigRegion, 'Town 19', 5, 50),
    'regions'               => static fn () => $q->regions('AA', '', 1, 50),
    'countries'             => static fn () => $q->countries('', 1, 50),
) as $label => $fn) {
    $ms = $fastest($fn);
    check("$label: under 50 ms", $ms < 50, sprintf('%.1f ms', $ms));
}

/* ---------------------------------------------------------------------------
 * impact() agrees with the real delete. A small country of its own, so the model
 * cascades run in seconds; every branch carries a listing and a user.
 * ------------------------------------------------------------------------ */

harness_section('impact() against the model deletes');

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
    // Keeps the item cascade off the filesystem; every database delete still runs.
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
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';

seed_country($admin, 'DD', 'Deltaland');
$d1  = seed_region($admin, 'DD', 'Delta One');
$d2  = seed_region($admin, 'DD', 'Delta Two');
$c11 = seed_city($admin, $d1, 'Delta City', 'DD');
$c12 = seed_city($admin, $d1, 'Delta Town', 'DD');
$c21 = seed_city($admin, $d2, 'Delta Port', 'DD');
$admin->query("UPDATE {$prefix}t_city SET fk_c_country_code = NULL WHERE pk_i_id = $c12");
$admin->query("INSERT INTO {$prefix}t_city_area (pk_i_id, fk_i_city_id, s_name) VALUES (2, $c11, 'Harbour')");

$listing('DD', $d1, $c11, null);
$listing(null, null, $c11, null);
$listing(null, null, null, 2);
$listing(null, null, $c12, null);
$listing(null, $d1, null, null);
$listing('DD', $d2, $c21, null);
$listing('DD', null, null, null);
$user('d1', 'DD', $d1, $c11, null);
$user('d2', null, null, null, 2);
$user('d3', null, null, $c12, null);
$user('d4', null, $d2, null, null);
$user('d5', 'DD', null, null, null);

$scalar = static function (string $sql) use ($admin): int {
    return (int) $admin->query($sql)->fetch_row()[0];
};
/** Location columns per user, so an unlink shows as a changed row. */
$userPlaces = static function () use ($admin, $prefix): array {
    $out = array();
    $res = $admin->query(
        "SELECT pk_i_id, CONCAT(IFNULL(fk_c_country_code, '-'), '|', IFNULL(fk_i_region_id, '-'), '|',"
        . " IFNULL(fk_i_city_id, '-'), '|', IFNULL(fk_i_city_area_id, '-')) AS p FROM {$prefix}t_user"
    );
    while ($row = $res->fetch_assoc()) {
        $out[$row['pk_i_id']] = $row['p'];
    }

    return $out;
};

foreach (array(
    'city'    => array($c11, static fn ($id) => City::newInstance()->deleteByPrimaryKey($id)),
    'region'  => array($d1, static fn ($id) => Region::newInstance()->deleteByPrimaryKey($id)),
    'country' => array('DD', static fn ($id) => Country::newInstance()->deleteByPrimaryKey($id)),
) as $level => [$id, $delete]) {
    $impact      = $q->impact($level, array($id));
    $itemsBefore = $scalar("SELECT COUNT(*) FROM {$prefix}t_item");
    $citiesBefore  = $scalar("SELECT COUNT(*) FROM {$prefix}t_city");
    $regionsBefore = $scalar("SELECT COUNT(*) FROM {$prefix}t_region");
    $placesBefore  = $userPlaces();

    pin("$level delete succeeds", 0, $delete($id));

    $unlinked = count(array_diff_assoc($placesBefore, $userPlaces()));
    $deleted  = $itemsBefore - $scalar("SELECT COUNT(*) FROM {$prefix}t_item");
    check("$level: the fixture reaches listings and users", $impact['listings'] > 0 && $impact['users'] > 0);
    pin("$level: listings deleted = impact", $impact['listings'], $deleted);
    pin("$level: users unlinked = impact", $impact['users'], $unlinked);
    pin("$level: cities removed = impact", $level === 'city' ? 1 : $impact['cities'], $citiesBefore - $scalar("SELECT COUNT(*) FROM {$prefix}t_city"));
    pin("$level: regions removed = impact", $level === 'region' ? 1 : $impact['regions'], $regionsBefore - $scalar("SELECT COUNT(*) FROM {$prefix}t_region"));
}

/* ---------------------------------------------------------------------------
 * Preview: the Data tab's dry run writes nothing.
 * ------------------------------------------------------------------------ */

harness_section('Catalog preview (dry run)');

seed_country($admin, 'EE', 'Epsiland');
$e1 = seed_region($admin, 'EE', 'Epsi One');
seed_city($admin, $e1, 'Epsi Town', 'EE');
seed_city($admin, $e1, 'Epsi Gone', 'EE');

$snapshot = static function () use ($admin, $prefix): array {
    $out = array();
    foreach (array('t_country', 't_region', 't_city', 't_location_slug_history') as $table) {
        $row = $admin->query("CHECKSUM TABLE {$prefix}{$table}")->fetch_assoc();
        $out[$table] = array(
            (int) $admin->query("SELECT COUNT(*) FROM {$prefix}{$table}")->fetch_row()[0],
            (string) $row['Checksum'],
        );
    }

    return $out;
};
$country = array(
    'code' => 'EE', 'name' => 'Epsiland Renamed', 'slug' => 'epsiland-renamed',
    'regions' => array(
        array('name' => 'Epsi One', 'slug' => 'epsi-one-new', 'settlements' => array(
            array('name' => 'Epsi Town', 'slug' => 'epsi-town-new'),
            array('name' => 'Epsi Fresh', 'slug' => 'epsi-fresh'),
        )),
        array('name' => 'Epsi Two', 'slug' => 'epsi-two', 'settlements' => array(array('name' => 'Epsi Port', 'slug' => 'epsi-port'))),
    ),
);

$before  = $snapshot();
$report  = (new \mindstellar\location\LocationImporter(true))->import($country);
$preview = \mindstellar\location\LocationAdminView::previewReport($report);
check('the dry run found changes to report', !isset($report['error']) && $preview['changes'] === true, json_encode($report));
check('the dry run counted a new region and new cities', $report['regions']['inserted'] === 1 && $report['cities']['inserted'] >= 2);
pin('the dry run wrote nothing: counts and checksums unchanged', $before, $snapshot());

/* ---------------------------------------------------------------------------
 * The AJAX endpoints (source scan: the controller needs a live admin session).
 * ------------------------------------------------------------------------ */

harness_section('CAdminAjax wiring (source scan)');

$ajax = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/ajax/CAdminAjax.php');
check('CAdminAjax requires an admin session', preg_match('/class\s+CAdminAjax\s+extends\s+AdminSecBaseModel\b/', $ajax) === 1);
preg_match('/isModerator\(\)\s*&&\s*!in_array\(\$this->action,\s*array\(([^)]*)\)/', $ajax, $m);
check('moderator allow-list found', isset($m[1]));
foreach (array('location_search', 'location_impact', 'location_record') as $action) {
    check("$action is routed", strpos($ajax, "case '$action':") !== false);
    check("$action is refused to moderators", isset($m[1]) && strpos($m[1], "'$action'") === false);
}

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
