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
 * ItemActions custom-field guard: submitted meta is only stored for fields that belong to
 * the listing's category. A category with no fields stores nothing, even when the meta
 * targets a real field id of another category.
 *
 * Usage:  php tests/models/item-meta-guard.php          (standalone, own scratch database)
 *         php tests/run-models.php item-meta-guard      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_item_meta_guard');

require_once __DIR__ . '/../lib/action-standins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hFields.php';
if (!function_exists('osc_core_url')) {
    function osc_core_url($name, $args = array())
    {
        return \mindstellar\routing\CoreRoutes::url($name, $args);
    }
}
if (!function_exists('osc_register_render_target')) {
    function osc_register_render_target($id, $path)
    {
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php'; // add() -> osc_items_wait_time_for_user()
if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}

seed_locale($admin);
seed_country($admin);
seed_currency($admin);
$parent    = seed_category($admin, 'Guard parent');
$plainCat  = seed_category($admin, 'Guard plain', $parent);
$fieldCat  = seed_category($admin, 'Guard fielded', $parent);
$user      = seed_user($admin, 'metaguard', 'metaguard@example.test');

$addField = static function (string $name, string $slug, int $catId) use ($admin): int {
    $id = seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX . "t_meta_fields (s_name, e_type, b_required, b_searchable, s_slug, i_position, s_meta)
         VALUES (?, 'TEXT', 0, 0, ?, 0, '')",
        'ss',
        array($name, $slug)
    );
    seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX . 't_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)',
        'ii',
        array($catId, $id)
    );

    return $id;
};
$ownField     = $addField('Own', 'own', $fieldCat);
$foreignField = $addField('Foreign', 'foreign', $fieldCat + 1000); // belongs to neither listing category
$otherCat     = seed_category($admin, 'Guard other', $parent);
seed_exec(
    $admin,
    'UPDATE ' . DB_TABLE_PREFIX . 't_meta_categories SET fk_i_category_id = ? WHERE fk_i_field_id = ?',
    'ii',
    array($otherCat, $foreignField)
);

$itemData = static function (int $catId, string $title) use ($user): array {
    return array(
        'title'         => array('en_US' => $title),
        'description'   => array('en_US' => $title . ' has a description long enough to pass validation.'),
        'catId'         => $catId,
        'price'         => 10,
        'currency'      => 'USD',
        'contactName'   => 'Guard Tester',
        'contactEmail'  => 'guard@example.test',
        'contactPhone'  => '',
        'cityArea'      => '',
        'address'       => '',
        'countryId'     => 'US',
        'countryName'   => 'United States',
        'regionId'      => null,
        'regionName'    => '',
        'cityId'        => null,
        'cityName'      => '',
        'd_coord_lat'   => null,
        'd_coord_long'  => null,
        's_zip'         => '',
        'photos'        => array(),
        'showEmail'     => 1,
        'active'        => 'ACTIVE',
        'userId'        => $user,
        's_ip'          => '127.0.0.1',
        'dt_expiration' => '0',
    );
};

$metaRows = static function (int $itemId) use ($admin): array {
    $out = array();
    $res = $admin->query(
        'SELECT fk_i_field_id, s_value FROM ' . DB_TABLE_PREFIX . 't_item_meta WHERE fk_i_item_id = ' . $itemId
        . ' ORDER BY fk_i_field_id'
    );
    while ($row = $res->fetch_row()) {
        $out[(int)$row[0]] = $row[1];
    }

    return $out;
};
$lastItem = static function () use ($admin, $user): int {
    return (int)$admin->query('SELECT MAX(pk_i_id) FROM ' . DB_TABLE_PREFIX . 't_item WHERE fk_i_user_id = ' . $user)
        ->fetch_row()[0];
};
$xss = '<script>alert(1)</script>';

// $viaData: meta travels in the data (importer); otherwise in Params, as CWebItem posts it.
$add = static function (int $catId, array $meta, bool $viaData) use ($itemData, $lastItem): int {
    $action       = new ItemActions(true);
    $action->data = $itemData($catId, 'Meta guard ' . $catId);
    if ($viaData) {
        $action->data['meta'] = $meta;
        Params::setParam('meta', null);
    } else {
        Params::setParam('meta', $meta);
    }
    $before = $lastItem();
    $action->add();
    Params::setParam('meta', null);
    $id = $lastItem();

    return $id > $before ? $id : 0;
};
$edit = static function (int $itemId, int $catId, array $meta, bool $viaData) use ($itemData): void {
    $action       = new ItemActions(true);
    $action->data = $itemData($catId, 'Meta guard edit ' . $catId) + array('idItem' => $itemId, 'secret' => 'secret' . $catId);
    if ($viaData) {
        $action->data['meta'] = $meta;
        Params::setParam('meta', null);
    } else {
        Params::setParam('meta', $meta);
    }
    $action->edit();
    Params::setParam('meta', null);
};

foreach (array('form path (Params meta)' => false, 'data path (aItem meta)' => true) as $label => $viaData) {
    harness_section('add(): category without fields, ' . $label);
    $id = $add($plainCat, array($foreignField => $xss, $ownField => 'x'), $viaData);
    check('the listing was created', $id > 0);
    pin('no meta rows are stored', array(), $metaRows($id));

    harness_section('add(): category with fields, ' . $label);
    $id = $add($fieldCat, array($ownField => 'Blue', $foreignField => $xss), $viaData);
    check('the listing was created', $id > 0);
    pin('own field stored, foreign id dropped', array($ownField => 'Blue'), $metaRows($id));

    harness_section('edit(): category without fields, ' . $label);
    $plainItem = seed_item($admin, $plainCat, $user, 'Edit plain ' . ($viaData ? 'd' : 'f'));
    $edit($plainItem, $plainCat, array($foreignField => $xss, $ownField => 'x'), $viaData);
    pin('no meta rows are stored', array(), $metaRows($plainItem));

    harness_section('edit(): category with fields, ' . $label);
    $fieldItem = seed_item($admin, $fieldCat, $user, 'Edit fielded ' . ($viaData ? 'd' : 'f'));
    $edit($fieldItem, $fieldCat, array($ownField => 'Green', $foreignField => $xss), $viaData);
    pin('own field stored, foreign id dropped', array($ownField => 'Green'), $metaRows($fieldItem));
}

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
