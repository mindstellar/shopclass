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
 * Migration 0073 moves category and location slug history into t_slug_history and drops the
 * old tables; SlugHistory then records, finds and forgets old slugs for all three kinds.
 *
 * Usage:  php tests/models/slug-history-merge.php
 *         php tests/run-models.php slug-history-merge
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\database\Connection;
use mindstellar\model\SlugHistory;

$admin = scratchdb_session('osc_models_slug_history_merge');
$p     = DB_TABLE_PREFIX;
$conn  = Connection::getInstance();

$locale  = seed_locale($admin);
$country = seed_country($admin);
$cars    = seed_category($admin, 'Cars', null, $locale);
$bikes   = seed_category($admin, 'Bikes', null, $locale);
$region  = seed_region($admin, $country, 'Alpha');
$city    = seed_city($admin, $region, 'Aville', $country);

$rows = static function () use ($admin, $p): array {
    return array_map(
        static fn (array $r): string => implode('|', $r),
        $admin->query("SELECT e_type, fk_i_id, fk_c_locale_code, s_slug FROM {$p}t_slug_history ORDER BY e_type, s_slug, fk_c_locale_code")->fetch_all(MYSQLI_NUM)
    );
};
$exists = static fn (string $table): bool => $admin->query("SHOW TABLES LIKE '{$p}{$table}'")->num_rows === 1;

harness_section('migration 0073 on a site from before it');

$admin->query("DROP TABLE {$p}t_slug_history");
$admin->query("CREATE TABLE {$p}t_category_slug_history (fk_i_category_id INT UNSIGNED NOT NULL, fk_c_locale_code CHAR(5) NOT NULL DEFAULT '',"
    . " s_slug VARCHAR(191) NOT NULL, dt_date DATETIME NOT NULL, PRIMARY KEY (s_slug, fk_c_locale_code))");
$admin->query("CREATE TABLE {$p}t_location_slug_history (e_type ENUM('REGION', 'CITY') NOT NULL, s_slug VARCHAR(191) NOT NULL,"
    . ' fk_i_id INT UNSIGNED NOT NULL, dt_date DATETIME NOT NULL, PRIMARY KEY (e_type, s_slug))');
$admin->query("INSERT INTO {$p}t_category_slug_history VALUES ($cars, 'en_US', 'autos', NOW()), ($cars, 'es_ES', 'autos', NOW()), ($bikes, 'en_US', 'cycles', NOW())");
$admin->query("INSERT INTO {$p}t_location_slug_history VALUES ('REGION', 'old-alpha', $region, NOW()), ('CITY', 'autos', $city, NOW())");

$migrate = static fn () => (require ABS_PATH . 'oc-includes/osclass/installer/migrations/0073_one_slug_history.php')->up($conn);
$migrate();
$expected = array(
    "CATEGORY|$cars|en_US|autos",
    "CATEGORY|$cars|es_ES|autos",
    "CATEGORY|$bikes|en_US|cycles",
    "REGION|$region||old-alpha",
    "CITY|$city||autos",
);
sort($expected);
$got = $rows();
sort($got);
pin('every old slug is in one table, a category with its locale and a place with none', $expected, $got);
check('both old tables are dropped', !$exists('t_category_slug_history') && !$exists('t_location_slug_history'));
$migrate();
$again = $rows();
sort($again);
pin('running it again changes nothing', $expected, $again);

harness_section('a run stopped half way');

$admin->query("DELETE FROM {$p}t_slug_history WHERE e_type <> 'CATEGORY'");
$admin->query("CREATE TABLE {$p}t_location_slug_history (e_type ENUM('REGION', 'CITY') NOT NULL, s_slug VARCHAR(191) NOT NULL,"
    . ' fk_i_id INT UNSIGNED NOT NULL, dt_date DATETIME NOT NULL, PRIMARY KEY (e_type, s_slug))');
$admin->query("INSERT INTO {$p}t_location_slug_history VALUES ('REGION', 'old-alpha', $region, NOW()), ('CITY', 'autos', $city, NOW())");
$migrate();
$resumed = $rows();
sort($resumed);
pin('the next upgrade moves what is left', $expected, $resumed);
check('and drops the old table', !$exists('t_location_slug_history'));

harness_section('a row that cannot move');

$admin->query("CREATE TABLE {$p}t_location_slug_history (e_type ENUM('REGION', 'CITY') NOT NULL, s_slug VARCHAR(191) NOT NULL,"
    . ' fk_i_id INT UNSIGNED NOT NULL, dt_date DATETIME NOT NULL, PRIMARY KEY (e_type, s_slug))');
$admin->query("INSERT INTO {$p}t_location_slug_history VALUES ('REGION', 'old-alpha', " . ($region + 1000) . ', NOW())');
$threw = false;
try {
    $migrate();
} catch (RuntimeException $e) {
    $threw = true;
}
check('a slug already taken by another row stops the upgrade', $threw);
check('and the old table is kept', $exists('t_location_slug_history'));
$admin->query("DROP TABLE {$p}t_location_slug_history");

harness_section('the model');

pin('the same old slug finds a category or a city by kind', array($cars, $city), array(SlugHistory::ownerOf(SlugHistory::CATEGORY, 'autos'), SlugHistory::ownerOf(SlugHistory::CITY, 'autos')));
pin('an unknown slug finds nothing', null, SlugHistory::ownerOf(SlugHistory::REGION, 'autos'));
SlugHistory::record(SlugHistory::CATEGORY, $bikes, 'bicycles', 'cycles', 'en_US');
pin('a slug that is live again stops redirecting, and the vacated one starts', array(null, $bikes), array(SlugHistory::ownerOf(SlugHistory::CATEGORY, 'cycles'), SlugHistory::ownerOf(SlugHistory::CATEGORY, 'bicycles')));
SlugHistory::forget(SlugHistory::CATEGORY, $cars);
pin('forgetting a deleted category leaves the city with the same old slug', array(null, $city), array(SlugHistory::ownerOf(SlugHistory::CATEGORY, 'autos'), SlugHistory::ownerOf(SlugHistory::CITY, 'autos')));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
