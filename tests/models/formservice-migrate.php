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
 * Integration pins for FormService::migrateLooseFields().
 *
 * The migration gathers legacy "loose" fields — assigned straight to categories
 * (t_meta_categories) but in no form — into forms so the builder can manage them.
 * The load-bearing promise is that it must NOT change where a single field
 * renders: a field live on a set of categories via the loose branch of
 * Field::findByCategoryItem() must, after the move, resolve on exactly the same
 * categories via the grouped branch — once, not twice.
 *
 * Effects span four tables and are read back with raw mysqli, never through the
 * code under test:
 *   - t_meta_group          (one form per DISTINCT category set)
 *   - t_meta_group_fields   (the field membership + per-form order)
 *   - t_meta_group_categories (the form's categories = the fields' old set)
 *   - t_meta_categories     (left intact — the move is reversible)
 *
 * Usage:  php tests/models/formservice-migrate.php   (standalone, own database)
 *         php tests/run-models.php formservice-migrate (as part of the suite)
 */

// FormService names its generated forms through __(); provide a passthrough
// before any osclass code loads, so the real translation stack (a gettext
// singleton this harness has no catalogue for) is not dragged in. The autoloader
// is class-based and never pulls the hTranslations function file, so there is no
// redeclaration to collide with.
if (!function_exists('__')) {
    function __($key, $domain = 'core')
    {
        return $key;
    }
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../../oc-includes/osclass/helpers/hLocale.php';
require_once __DIR__ . '/../../oc-includes/osclass/helpers/hPreference.php';

$admin = scratchdb_session('osc_models_formservice_migrate');

// Field / Category construction stand-ins, matching tests/models/field.php:
// osc_base_url only feeds a cache key, and forcing every cache lookup to miss
// makes Category re-query t_category — exactly what a fixture wants.
if (!function_exists('osc_base_url')) {
    function osc_base_url($add_index = false)
    {
        return 'http://localhost/';
    }
}
if (!function_exists('osc_cache_get')) {
    function osc_cache_get($key, &$found = null)
    {
        $found = false;

        return false;
    }
}
if (!function_exists('osc_cache_set')) {
    function osc_cache_set($key, $value, $expiration = 0)
    {
        return false;
    }
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
}
if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 0);
}

$fieldsTbl = DB_TABLE_PREFIX . 't_meta_fields';
$metaCat   = DB_TABLE_PREFIX . 't_meta_categories';
$group     = DB_TABLE_PREFIX . 't_meta_group';
$groupFld  = DB_TABLE_PREFIX . 't_meta_group_fields';
$groupCat  = DB_TABLE_PREFIX . 't_meta_group_categories';

$fieldModel = Field::newInstance();
$service    = new \mindstellar\forms\FormService();

$seedField = static function (string $name, string $slug, int $position = 0) use ($admin, $fieldsTbl): int {
    return seed_exec(
        $admin,
        "INSERT INTO $fieldsTbl (s_name, s_slug, e_type, i_position) VALUES (?, ?, 'TEXT', ?)",
        'ssi',
        array($name, $slug, $position)
    );
};
$linkFieldToCat = static function (int $catId, int $fieldId) use ($admin, $metaCat): void {
    seed_exec($admin, "INSERT INTO $metaCat (fk_i_category_id, fk_i_field_id) VALUES (?, ?)", 'ii', array($catId, $fieldId));
};
$linkFieldToGroup = static function (int $groupId, int $fieldId, int $position = 0) use ($admin, $groupFld): void {
    seed_exec($admin, "INSERT INTO $groupFld (fk_i_group_id, fk_i_field_id, i_position) VALUES (?, ?, ?)", 'iii', array($groupId, $fieldId, $position));
};
$seedGroup = static function (string $name, string $slug) use ($admin, $group): int {
    return seed_exec($admin, "INSERT INTO $group (s_name, s_slug, i_position) VALUES (?, ?, 0)", 'ss', array($name, $slug));
};
$rawAll = static function (string $sql) use ($admin): array {
    $res = $admin->query($sql);
    if (!$res) {
        return array();
    }
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();

    return $rows;
};

/* -------------------------------------------------------------------------
 * Fixture: two loose fields share a category set (Motors); one loose field
 * sits on a different single category (Homes); one field is already in a form;
 * one loose field has no category at all. Only the first three loose-with-cats
 * fields should move, into two forms (two distinct category sets).
 * ---------------------------------------------------------------------- */
scratchdb_truncate_all($admin);
$cMotors = seed_category($admin, 'Motors');
$cCars   = seed_category($admin, 'Cars', $cMotors);
$cHomes  = seed_category($admin, 'Homes');

// Set A — Motors + Cars, two fields (ordered by position: Make before Year).
$fMake = $seedField('Make', 'make', 1);
$fYear = $seedField('Year', 'year', 2);
$linkFieldToCat($cMotors, $fMake);
$linkFieldToCat($cCars, $fMake);
$linkFieldToCat($cMotors, $fYear);
$linkFieldToCat($cCars, $fYear);

// Set B — Homes only, one field.
$fRooms = $seedField('Rooms', 'rooms', 3);
$linkFieldToCat($cHomes, $fRooms);

// Already in a form — must be left untouched by the migration.
$fPlaced = $seedField('Placed', 'placed', 4);
$gExisting = $seedGroup('Existing', 'existing');
$linkFieldToGroup($gExisting, $fPlaced, 0);

// Loose but attached to no category — nothing to preserve, so skipped.
$fOrphan = $seedField('Orphan', 'orphan', 5);

// Baseline: each loose-with-cats field resolves once on its categories.
$before = static function (int $cat, int $fid) use ($fieldModel): int {
    $ids = array_map('intval', array_column($fieldModel->findByCategoryItem($cat, null), 'pk_i_id'));

    return count(array_keys($ids, $fid, true));
};
pin('before: Make resolves once on Motors', 1, $before($cMotors, $fMake));
pin('before: Make resolves once on Cars', 1, $before($cCars, $fMake));
pin('before: Rooms resolves once on Homes', 1, $before($cHomes, $fRooms));

/* -------------------------------------------------------------------------
 * Run the migration.
 * ---------------------------------------------------------------------- */
harness_section('FormService::migrateLooseFields — counts');
$result = $service->migrateLooseFields();
pin('two forms are created (one per distinct category set)', 2, $result['forms']);
pin('three fields are moved (the orphan and the placed one are not)', 3, $result['fields']);

harness_section('FormService::migrateLooseFields — link table');
$links = $rawAll("SELECT fk_i_group_id, fk_i_field_id, i_position FROM $groupFld ORDER BY fk_i_group_id, i_position");
// Existing form's single link + 3 migrated links.
pin('one existing link plus three migrated links', 4, count($links));

// The two Set-A fields land in one form, in i_position order (Make then Year).
$setAgroup = null;
foreach ($rawAll("SELECT gf.fk_i_group_id AS g, gf.fk_i_field_id AS f, gf.i_position AS p
                  FROM $groupFld gf WHERE gf.fk_i_field_id IN ($fMake, $fYear) ORDER BY gf.i_position") as $row) {
    $setAgroup = (int)$row['g'];
}
$setArows = $rawAll("SELECT fk_i_field_id AS f, i_position AS p FROM $groupFld WHERE fk_i_group_id = $setAgroup ORDER BY i_position");
pin(
    'Make and Year share one form, ordered by their field position',
    array(array('f' => (string)$fMake, 'p' => '0'), array('f' => (string)$fYear, 'p' => '1')),
    $setArows
);

harness_section('FormService::migrateLooseFields — the new form carries the fields\' categories');
$setAcats = array_map('intval', array_column(
    $rawAll("SELECT fk_i_category_id FROM $groupCat WHERE fk_i_group_id = $setAgroup ORDER BY fk_i_category_id"),
    'fk_i_category_id'
));
$expectAcats = array($cMotors, $cCars);
sort($expectAcats);
pin('the Set-A form applies to Motors and Cars', $expectAcats, $setAcats);

harness_section('FormService::migrateLooseFields — rendering is preserved, not duplicated');
// The load-bearing invariant: same categories, still exactly once (grouped branch
// now, loose branch suppressed by its NOT EXISTS guard).
pin('after: Make still resolves once on Motors', 1, $before($cMotors, $fMake));
pin('after: Make still resolves once on Cars', 1, $before($cCars, $fMake));
pin('after: Year still resolves once on Motors', 1, $before($cMotors, $fYear));
pin('after: Rooms still resolves once on Homes', 1, $before($cHomes, $fRooms));

harness_section('FormService::migrateLooseFields — the loose rows are kept (reversible)');
$looseKept = (int)($rawAll("SELECT COUNT(*) AS c FROM $metaCat")[0]['c'] ?? 0);
// 4 (Make: Motors+Cars, Year: Motors+Cars) + 1 (Rooms: Homes) = 5, untouched.
pin('every original t_meta_categories row survives the move', 5, $looseKept);

harness_section('FormService::migrateLooseFields — a second run is a no-op');
$again = $service->migrateLooseFields();
pin('nothing left to move: no new forms', 0, $again['forms']);
pin('nothing left to move: no fields moved', 0, $again['fields']);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
