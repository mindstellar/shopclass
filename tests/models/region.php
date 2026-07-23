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
 * Characterization pins for the Region model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * getByCountry()/findByCountry()/findByName()/ajax()/findBySlug()/
 * listByEmptySlug() move to the parameterized query layer.
 *
 * findByCountry(), findByName(), findBySlug() and listByEmptySlug() all call
 * $this->dao->select() with NO arguments, which legacy compiles to `SELECT *`
 * over the table's real column order (pk_i_id, fk_c_country_code, s_name,
 * s_slug, b_active per struct.sql) — NOT the order of getFields() (which lists
 * b_active before s_slug). The converted bodies preserve this by never calling
 * a column list on the builder either, so a row's key order stays `SELECT *`
 * order rather than drifting to the field-allowlist order.
 *
 * findByCountry()/findByName()/findBySlug() all reach the same "malformed
 * null-argument" quirk pinned across this effort: DBCommandClass::_where()
 * appends a bare comparison with no right-hand side for a null value, the
 * query fails at the driver, and each method's own `if ($result == false)`
 * branch (or, for findByName/findBySlug, row()'s own empty-result default)
 * absorbs that into the same empty array() a genuine zero-row match produces.
 *
 * ajax() has no such branch: escapeStr($v, true) treats a null or empty
 * $query as an empty string, so the compiled pattern degenerates to a bare
 * '%' wildcard and the call matches every row (up to its LIMIT 5) rather than
 * failing. That is pinned explicitly below as an observed quirk, not adjusted.
 * ajax()'s three country forms (none / 2-letter code / country name via a
 * LEFT JOIN on t_country) are all exercised, along with wildcard escaping
 * (a literal '%' typed by a caller must not be treated as a SQL wildcard).
 *
 * deleteByPrimaryKey() has NO query of its own to convert: every line is a
 * call into another model's own public method (City::findByRegion(),
 * City::deleteByPrimaryKey(), Item::deleteByRegion(), RegionStats::delete(),
 * User::update()) or the inherited DAO::delete() on this table — all four
 * kinds are out of scope for this conversion (§3.1: pure delegation to a DAO
 * base method, or to another model entirely, is left alone). The method is
 * therefore NOT touched by the conversion commit; every pin below exists to
 * prove that byte-identical fact and to guard the cascade's observable effect
 * against a future change to this file. No item fixtures are seeded, so
 * Item::deleteByRegion()/deleteByCity() always operate over zero rows and
 * never reach the before/after_delete_item hook pipeline — the cascade down
 * through City/CityArea/RegionStats/CityStats/User is exercised in full
 * without needing to bootstrap that plumbing.
 *
 * The "number of failed deletions" ledger deleteByPrimaryKey()'s own docblock
 * promises is a genuine quirk worth stating plainly: `$this->delete($cond)`
 * (the inherited DAO::delete()) returns the affected-row COUNT, and PHP int 0
 * is falsy, so `!$this->delete(...)` is true both when the delete fails AND
 * when it succeeds but matches nothing. Deleting a region that still exists
 * therefore returns int 0 (its own delete affected 1 row, a truthy value);
 * deleting an id that never existed returns int 1, not because anything
 * failed but because zero-rows-affected reads as failure by this method's own
 * counting.
 *
 * Usage:  php tests/models/region.php          (standalone, own scratch database)
 *         php tests/run-models.php region      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_region');
$table = DB_TABLE_PREFIX . 't_region';

$model = Region::newInstance();

/**
 * t_region.s_slug is NOT NULL DEFAULT '', and seed_region() always derives a
 * non-empty slug from the name, so listByEmptySlug()'s fixture needs direct
 * control over the slug column.
 */
$seedRegionSlug = static function (
    string $country,
    string $name,
    string $slug,
    int $active = 1
) use ($admin): int {
    return seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX . 't_region
         (fk_c_country_code, s_name, s_slug, b_active) VALUES (?, ?, ?, ?)',
        'sssi',
        array($country, $name, $slug, $active)
    );
};

/**
 * t_city_area.pk_i_id is NOT AUTO_INCREMENT (struct.sql declares no
 * AUTO_INCREMENT on it), so every insert has to supply an id explicitly.
 */
$nextCityAreaId = 1;
$seedCityAreaRow = static function (int $cityId, string $name) use ($admin, &$nextCityAreaId): int {
    $id = $nextCityAreaId++;
    seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX . 't_city_area (pk_i_id, fk_i_city_id, s_name) VALUES (?, ?, ?)',
        'iis',
        array($id, $cityId, $name)
    );

    return $id;
};

$seedRegionStats = static function (int $regionId, int $numItems = 0) use ($admin): void {
    seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX . 't_region_stats (fk_i_region_id, i_num_items) VALUES (?, ?)',
        'ii',
        array($regionId, $numItems)
    );
};

$seedCityStats = static function (int $cityId, int $numItems = 0) use ($admin): void {
    seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX . 't_city_stats (fk_i_city_id, i_num_items) VALUES (?, ?)',
        'ii',
        array($cityId, $numItems)
    );
};

/**
 * Seed a user referencing a region/city/city-area triple, so the cascade's
 * User::update() null-out can be observed. seed_user() itself never touches
 * these columns.
 */
$seedUserAt = static function (
    string $username,
    ?int $regionId,
    ?int $cityId,
    ?int $cityAreaId
) use ($admin): int {
    $id = seed_user($admin, $username, $username . '@example.test');
    seed_exec(
        $admin,
        'UPDATE ' . DB_TABLE_PREFIX . "t_user
         SET fk_i_region_id = ?, s_region = ?, fk_i_city_id = ?, s_city = ?,
             fk_i_city_area_id = ?, s_city_area = ?
         WHERE pk_i_id = ?",
        'isisisi',
        array($regionId, $regionId !== null ? 'Region' : '', $cityId, $cityId !== null ? 'City' : '',
            $cityAreaId, $cityAreaId !== null ? 'Area' : '', $id)
    );

    return $id;
};

$readUser = static function (int $id) use ($admin): array {
    $stmt = $admin->prepare(
        'SELECT fk_i_region_id, s_region, fk_i_city_id, s_city, fk_i_city_area_id, s_city_area
         FROM ' . DB_TABLE_PREFIX . 't_user WHERE pk_i_id = ?'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row;
};

$countRows = static function (string $table, string $where, string $types, array $vals) use ($admin): int {
    $stmt = $admin->prepare("SELECT COUNT(*) c FROM $table WHERE $where");
    if ($types !== '') {
        $stmt->bind_param($types, ...$vals);
    }
    $stmt->execute();
    $c = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    return $c;
};

$rowExists = static function (string $table, string $pkCol, $pkVal, string $type) use ($admin): bool {
    $stmt = $admin->prepare("SELECT 1 FROM $table WHERE $pkCol = ?");
    $stmt->bind_param($type, $pkVal);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
};

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('Region: public surface');

pin('getByCountry signature is unchanged', 'public getByCountry($countryId)', harness_method_signature('Region', 'getByCountry'));
pin('findByCountry signature is unchanged', 'public findByCountry($countryId)', harness_method_signature('Region', 'findByCountry'));
pin('findByName signature is unchanged', 'public findByName($name, $country = NULL)', harness_method_signature('Region', 'findByName'));
pin('ajax signature is unchanged', 'public ajax($query, $country = NULL)', harness_method_signature('Region', 'ajax'));
pin('deleteByPrimaryKey signature is unchanged', 'public deleteByPrimaryKey($pk)', harness_method_signature('Region', 'deleteByPrimaryKey'));
pin('findBySlug signature is unchanged', 'public findBySlug($slug)', harness_method_signature('Region', 'findBySlug'));
pin('listByEmptySlug signature is unchanged', 'public listByEmptySlug()', harness_method_signature('Region', 'listByEmptySlug'));
pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('Region', 'newInstance'));
check('Region still extends DAO', is_subclass_of('Region', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('pk_i_id', 'fk_c_country_code', 's_name', 'b_active', 's_slug'),
    $model->getFields()
);
pin(
    'the model adds exactly these methods of its own',
    array(
        '__construct', 'ajax', 'deleteByPrimaryKey', 'findByCountry', 'findByName',
        'findBySlug', 'getByCountry', 'listByEmptySlug', 'newInstance',
    ),
    array_values(array_intersect(
        array_keys(harness_public_method_map('Region')),
        array(
            '__construct', 'newInstance', 'getByCountry', 'findByCountry', 'findByName',
            'ajax', 'deleteByPrimaryKey', 'findBySlug', 'listByEmptySlug',
        )
    ))
);

/* ----------------------------------------------------------------------------
 * findByCountry() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Region::findByCountry — empty table');

pin('no rows at all returns an empty array', array(), $model->findByCountry('US'));

harness_section('Region::findByCountry — several regions, ordering and country filter');

$us = seed_country($admin, 'US', 'United States');
$fr = seed_country($admin, 'FR', 'France');

$regionZetaUs  = seed_region($admin, $us, 'Zeta');
$regionAlphaUs = seed_region($admin, $us, 'Alpha');
$regionOneFr   = seed_region($admin, $fr, 'Mango');

$rows = $model->findByCountry('US');
check('only the requested country comes back', is_array($rows), describe($rows));
pin('exactly two US regions', 2, count($rows));
pin(
    'each row carries exactly the SELECT * columns in table order',
    array('pk_i_id', 'fk_c_country_code', 's_name', 's_slug', 'b_active'),
    array_keys($rows[0])
);
check('every value in every row is a string or null (C4)', all_rows_string($rows), describe($rows));
// s_name ASC: 'Alpha' < 'Zeta', the reverse of insertion order (Zeta first, Alpha second).
pin('rows come back ordered by s_name ASC, not insertion/pk order', array('Alpha', 'Zeta'), array_column($rows, 's_name'));
pin('pk_i_id round-trips as a string, not an int (C4)', (string) $regionAlphaUs, $rows[0]['pk_i_id']);

harness_section('Region::findByCountry — a different country is matched independently');

pin('FR resolves to only its own region', array('Mango'), array_column($model->findByCountry('FR'), 's_name'));

harness_section('Region::findByCountry — no match');

pin('an unknown country code returns an empty array', array(), $model->findByCountry('ZZ'));

harness_section('Region::findByCountry — malformed lookup (null country)');

/* A null country id builds "fk_c_country_code =" with no right-hand side, the
 * query fails at the driver, and the model's own get()===false branch
 * absorbs it into the same empty array() a genuine no-match returns. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null country id returns an empty array rather than raising', array(), $model->findByCountry(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * getByCountry() — pure delegation, pinned end to end.
 * ------------------------------------------------------------------------- */
harness_section('Region::getByCountry — delegates to findByCountry');

pin('getByCountry(US) matches findByCountry(US) exactly', $model->findByCountry('US'), $model->getByCountry('US'));
pin('getByCountry on an unknown country returns an empty array too', array(), $model->getByCountry('ZZ'));

/* ----------------------------------------------------------------------------
 * findByName() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Region::findByName — match, no country filter');

$row = $model->findByName('Alpha');
check('a match returns an array', is_array($row), describe($row));
pin('the row carries exactly the SELECT * columns in table order', array('pk_i_id', 'fk_c_country_code', 's_name', 's_slug', 'b_active'), array_keys($row));
pin('pk_i_id round-trips', (string) $regionAlphaUs, $row['pk_i_id']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));

harness_section('Region::findByName — match, filtered by country');

pin('the same name filtered to its own country still matches', (string) $regionAlphaUs, $model->findByName('Alpha', 'US')['pk_i_id']);
pin('the same name filtered to a different country does not match', array(), $model->findByName('Alpha', 'FR'));

harness_section('Region::findByName — no match');

pin('an unknown name returns an empty array', array(), $model->findByName('Nowhere'));

harness_section('Region::findByName — malformed lookup (null name)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null name returns an empty array rather than raising', array(), $model->findByName(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * ajax() — the return ledger: pattern matching, country filtering, LIMIT.
 * ------------------------------------------------------------------------- */
harness_section('Region::ajax — prefix match, no country filter');

scratchdb_truncate_all($admin);
$us = seed_country($admin, 'US', 'United States');
$fr = seed_country($admin, 'FR', 'France');

$alphaUs = seed_region($admin, $us, 'AlphaUS');
$alphaFr = seed_region($admin, $fr, 'AlphaFR');
seed_region($admin, $us, 'Beta');

$rows = $model->ajax('Alpha');
check('matches from every country come back when no country is given', is_array($rows), describe($rows));
pin('exactly two matches', 2, count($rows));
pin(
    'each row carries exactly the aliased id/label/value columns',
    array('id', 'label', 'value'),
    array_keys($rows[0])
);
check('every value in every row is a string or null (C4)', all_rows_string($rows), describe($rows));
$names = array_column($rows, 'label');
sort($names);
pin('both AlphaUS and AlphaFR are present', array('AlphaFR', 'AlphaUS'), $names);
pin('label and value carry the same region name', $rows[0]['label'], $rows[0]['value']);

harness_section('Region::ajax — country filter, 2-letter code (fk_c_country_code)');

pin('a 2-letter code filters to that country only', array('AlphaUS'), array_column($model->ajax('Alpha', 'US'), 'label'));
pin('the filter is case-insensitive on the code (lower-cased before comparing)', array('AlphaUS'), array_column($model->ajax('Alpha', 'us'), 'label'));
pin('a different 2-letter code filters to its own country', array('AlphaFR'), array_column($model->ajax('Alpha', 'FR'), 'label'));

harness_section('Region::ajax — country filter, full name (LEFT JOIN on t_country)');

pin('a full country name filters via the join, not the code', array('AlphaUS'), array_column($model->ajax('Alpha', 'United States'), 'label'));
pin('a different full country name resolves independently', array('AlphaFR'), array_column($model->ajax('Alpha', 'France'), 'label'));

harness_section('Region::ajax — country filter, no match');

pin('a 2-letter code with no matching region returns an empty array', array(), $model->ajax('Alpha', 'ZZ'));
pin('a country name with no matching t_country row returns an empty array', array(), $model->ajax('Alpha', 'Nowhereland'));

harness_section('Region::ajax — no pattern match');

pin('an unmatched pattern returns an empty array', array(), $model->ajax('Nosuchprefix'));

harness_section('Region::ajax — null/empty query degrades to a match-all wildcard, not an error');

/* escapeStr(null, true) / escapeStr('', true) both yield an empty string, so
 * the compiled pattern is a bare '%' — every row matches, up to LIMIT 5. This
 * is a real, observed quirk (not a validation branch) and is pinned as such. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$allRows = $model->ajax(null);
error_reporting($prevLevel);
check('a null query matches every row rather than raising', is_array($allRows) && count($allRows) === 3, describe($allRows));
pin('an empty-string query behaves identically to null', count($allRows), count($model->ajax('')));

harness_section('Region::ajax — LIMIT 5 is honoured');

for ($i = 0; $i < 5; $i++) {
    seed_region($admin, $us, 'Zulu' . $i);
}
// 3 existing (AlphaUS, AlphaFR, Beta) + 5 new Zulu* = 8 rows now match ajax(''), well past the cap.
pin('a match-all query is capped at 5 rows', 5, count($model->ajax('')));

harness_section('Region::ajax — wildcard characters in the query are escaped, not interpreted');

scratchdb_truncate_all($admin);
$us = seed_country($admin, 'US', 'United States');
$literalPercent = seed_region($admin, $us, '50% off');
$decoy          = seed_region($admin, $us, '500 something');

// A caller-typed '%' must stay literal: "50%" should match only the region
// whose name actually contains that substring, never "500 something" (which
// shares the "50" prefix but has no literal '%').
pin('a literal % in the query matches only the region containing it', array('50% off'), array_column($model->ajax('50%'), 'label'));

/* ----------------------------------------------------------------------------
 * findBySlug() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Region::findBySlug — match');

scratchdb_truncate_all($admin);
$us = seed_country($admin, 'US', 'United States');
$regionA = seed_region($admin, $us, 'Alpha');
$regionB = seed_region($admin, $us, 'Beta');

$row = $model->findBySlug('alpha');
check('a match returns an array', is_array($row), describe($row));
pin('pk_i_id round-trips', (string) $regionA, $row['pk_i_id']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));

harness_section('Region::findBySlug — a second slug is matched independently');

pin('beta resolves to its own row', (string) $regionB, $model->findBySlug('beta')['pk_i_id']);

harness_section('Region::findBySlug — duplicate slugs: only the first row comes back');

/* findBySlug() sets no LIMIT and no ORDER BY, so row() hands back whichever
 * row the query returns first; this is not asserting a general SQL guarantee,
 * only that the same physical result is reproduced after conversion. */
$dupOne = seed_region($admin, $us, 'Gamma One', 1);
seed_exec(
    $admin,
    'UPDATE ' . DB_TABLE_PREFIX . "t_region SET s_slug = 'shared' WHERE pk_i_id = ?",
    'i',
    array($dupOne)
);
$dupTwoName = 'Gamma Two';
$dupTwo     = seed_region($admin, $us, $dupTwoName, 1);
seed_exec(
    $admin,
    'UPDATE ' . DB_TABLE_PREFIX . "t_region SET s_slug = 'shared' WHERE pk_i_id = ?",
    'i',
    array($dupTwo)
);
$firstOfShared = $model->findBySlug('shared');
check('exactly one row comes back for the shared slug', is_array($firstOfShared) && isset($firstOfShared['pk_i_id']), describe($firstOfShared));
pin('the first-inserted duplicate is the one returned', (string) $dupOne, $firstOfShared['pk_i_id']);

harness_section('Region::findBySlug — no match');

pin('an unknown slug returns an empty array', array(), $model->findBySlug('nosuchslug'));

harness_section('Region::findBySlug — malformed lookup (null slug)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null slug returns an empty array rather than raising', array(), $model->findBySlug(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * listByEmptySlug() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Region::listByEmptySlug — no rows with an empty slug');

pin('when every slug is non-empty, returns an empty array', array(), $model->listByEmptySlug());

harness_section('Region::listByEmptySlug — some rows have an empty slug');

$emptyOne = $seedRegionSlug($us, 'No Slug One', '');
$emptyTwo = $seedRegionSlug($us, 'No Slug Two', '');

$rows = $model->listByEmptySlug();
check('rows with an empty slug come back', is_array($rows), describe($rows));
pin('exactly the two empty-slug rows', 2, count($rows));
$ids = array_column($rows, 'pk_i_id');
sort($ids);
$expectedIds = array((string) min($emptyOne, $emptyTwo), (string) max($emptyOne, $emptyTwo));
pin('both empty-slug rows are present', $expectedIds, $ids);
check('every value in every row is a string or null (C4)', all_rows_string($rows), describe($rows));
pin('a non-empty-slug row (Alpha) is excluded', array(), array_filter($ids, static function ($id) use ($regionA) {
    return $id === (string) $regionA;
}));

/* ----------------------------------------------------------------------------
 * Query cost — the converted read methods stay single-statement.
 * ------------------------------------------------------------------------- */
harness_section('Region: query cost');

pin('one findByCountry() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findByCountry('US');
}));
pin('one findByName() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findByName('Alpha');
}));
pin('one ajax() call (no country) costs one query', 1, harness_query_count(static function () use ($model) {
    $model->ajax('Alpha');
}));
pin('one ajax() call with a country-name join still costs one query', 1, harness_query_count(static function () use ($model) {
    $model->ajax('Alpha', 'United States');
}));
pin('one findBySlug() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findBySlug('alpha');
}));
pin('one listByEmptySlug() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->listByEmptySlug();
}));

/* ----------------------------------------------------------------------------
 * deleteByPrimaryKey() — NOT converted (pure delegation); the cascade is
 * pinned in full regardless, per the work unit's brief.
 * ------------------------------------------------------------------------- */
harness_section('Region::deleteByPrimaryKey — cascades through City/CityArea/*Stats/User, leaves other rows untouched');

scratchdb_truncate_all($admin);
$us = seed_country($admin, 'US', 'United States');
$fr = seed_country($admin, 'FR', 'France');

// The region under test: two cities, one of which has a city area; a region
// stats row; a city stats row for each city; users pointing at the region,
// at one of its cities, and at the city area.
$regionUnderTest = seed_region($admin, $us, 'DoomedRegion');
$cityOne         = seed_city($admin, $regionUnderTest, 'CityOne', $us);
$cityTwo         = seed_city($admin, $regionUnderTest, 'CityTwo', $us);
$areaOne         = $seedCityAreaRow($cityOne, 'AreaOne');
$seedRegionStats($regionUnderTest, 7);
$seedCityStats($cityOne, 3);
$seedCityStats($cityTwo, 4);
$userOnRegion = $seedUserAt('user_on_region', $regionUnderTest, null, null);
$userOnCity   = $seedUserAt('user_on_city', null, $cityOne, null);
$userOnArea   = $seedUserAt('user_on_area', null, $cityOne, $areaOne);

// A survivor region (different country) and everything under it, to prove the
// cascade is scoped to the region actually deleted.
$survivorRegion = seed_region($admin, $fr, 'SurvivorRegion');
$survivorCity   = seed_city($admin, $survivorRegion, 'SurvivorCity', $fr);
$survivorArea   = $seedCityAreaRow($survivorCity, 'SurvivorArea');
$seedRegionStats($survivorRegion, 1);
$seedCityStats($survivorCity, 1);
$survivorUser = $seedUserAt('survivor_user', $survivorRegion, $survivorCity, $survivorArea);

$ret = $model->deleteByPrimaryKey($regionUnderTest);
pin('deleting an existing region with no delete failures reports int 0', 0, $ret);

check('the region row itself is gone', !$rowExists($table, 'pk_i_id', $regionUnderTest, 'i'));
check('city one is gone', !$rowExists(DB_TABLE_PREFIX . 't_city', 'pk_i_id', $cityOne, 'i'));
check('city two is gone', !$rowExists(DB_TABLE_PREFIX . 't_city', 'pk_i_id', $cityTwo, 'i'));
check('the city area under city one is gone', !$rowExists(DB_TABLE_PREFIX . 't_city_area', 'pk_i_id', $areaOne, 'i'));
check('the region stats row is gone', !$rowExists(DB_TABLE_PREFIX . 't_region_stats', 'fk_i_region_id', $regionUnderTest, 'i'));
check('city one\'s stats row is gone', !$rowExists(DB_TABLE_PREFIX . 't_city_stats', 'fk_i_city_id', $cityOne, 'i'));
check('city two\'s stats row is gone', !$rowExists(DB_TABLE_PREFIX . 't_city_stats', 'fk_i_city_id', $cityTwo, 'i'));

$u = $readUser($userOnRegion);
pin('the user pointed at the region has fk_i_region_id nulled', null, $u['fk_i_region_id']);
pin('...and s_region cleared', '', $u['s_region']);

$u = $readUser($userOnCity);
pin('the user pointed at the city has fk_i_city_id nulled (via City::deleteByPrimaryKey)', null, $u['fk_i_city_id']);
pin('...and s_city cleared', '', $u['s_city']);

$u = $readUser($userOnArea);
pin('the user pointed at the city area has fk_i_city_area_id nulled (via CityArea::deleteByPrimaryKey)', null, $u['fk_i_city_area_id']);
pin('...and s_city_area cleared', '', $u['s_city_area']);

check('the survivor region is untouched', $rowExists($table, 'pk_i_id', $survivorRegion, 'i'));
check('the survivor city is untouched', $rowExists(DB_TABLE_PREFIX . 't_city', 'pk_i_id', $survivorCity, 'i'));
check('the survivor city area is untouched', $rowExists(DB_TABLE_PREFIX . 't_city_area', 'pk_i_id', $survivorArea, 'i'));
check('the survivor region stats row is untouched', $rowExists(DB_TABLE_PREFIX . 't_region_stats', 'fk_i_region_id', $survivorRegion, 'i'));
check('the survivor city stats row is untouched', $rowExists(DB_TABLE_PREFIX . 't_city_stats', 'fk_i_city_id', $survivorCity, 'i'));
// $readUser() runs its own SELECT through prepared mysqli with a bound
// parameter, which — unlike the legacy dao->get() path this model still
// uses — returns native-typed ints for INT columns under mysqlnd. Cast the
// readback, not the expectation, so this stays a test-side accommodation
// rather than a claim about Region's own (untouched) return typing.
$survivor = $readUser($survivorUser);
pin('the survivor user\'s region pointer is untouched', (string) $survivorRegion, (string) $survivor['fk_i_region_id']);
pin('the survivor user\'s city pointer is untouched', (string) $survivorCity, (string) $survivor['fk_i_city_id']);
pin('the survivor user\'s city-area pointer is untouched', (string) $survivorArea, (string) $survivor['fk_i_city_area_id']);

harness_section('Region::deleteByPrimaryKey — a nonexistent id reports int 1, not int 0');

/* $this->delete(['pk_i_id' => $pk]) (the inherited DAO::delete()) is a valid
 * query that matches zero rows, so it returns int 0 — which is falsy in PHP,
 * so `!$this->delete(...)` is true and $result is incremented. Nothing failed
 * in the sense of a SQL error; the return value is simply counting "rows this
 * step expected to remove but didn't", by construction. */
pin('deleting an id that never existed reports int 1', 1, $model->deleteByPrimaryKey(999999999));

harness_section('Region::deleteByPrimaryKey — a region with no cities and no dependents');

$bareRegion = seed_region($admin, $us, 'BareRegion');
pin('deleting a region with nothing under it also reports int 0', 0, $model->deleteByPrimaryKey($bareRegion));
check('the bare region is gone', !$rowExists($table, 'pk_i_id', $bareRegion, 'i'));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/region.php */
