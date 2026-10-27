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
 * The admin create flows write the row with the name typed, never a placeholder: a category
 * from the drawer and a custom field or form from the add dialog.
 *
 * Usage:  php tests/models/admin-create-flows.php
 *         php tests/run-models.php admin-create-flows
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\admin\ajax\CategoryAjax;
use mindstellar\admin\ajax\FieldAjax;
use mindstellar\admin\ajax\FormBuilderAjax;

$admin = api_admin_boot('osc_models_admin_create_flows');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
foreach (['language' => $locale, 'currency' => 'USD'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
if (!function_exists('osc_add_flash_ok_message')) {
    function osc_add_flash_ok_message($msg, $section = 'pubMessages')
    {
    }
}

/** Run an ajax handler with the posted values and return what it answered. */
$call = static function (string $class, string $method, array $post): array {
    foreach (['id', 's_name', 'field_type', 'group_name', 'i_expiration_days', 'b_price_enabled'] as $k) {
        Params::setParam($k, null);
    }
    foreach ($post as $k => $v) {
        Params::setParam($k, $v);
    }
    $handler = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    ob_start();
    $handler->$method();

    return json_decode((string) ob_get_clean(), true);
};
$count = static fn (string $table): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}{$table}")->fetch_row()[0];

harness_section('category create');
$before = $count('t_category');
$r = $call(CategoryAjax::class, 'editPost', ['id' => '', $locale . '#s_name' => 'Garden tools', $locale . '#s_description' => '', 'i_expiration_days' => '30', 'b_price_enabled' => '1']);
pin('created', [0, true], [$r['error'] ?? null, $r['created'] ?? null]);
pin('one row added', $before + 1, $count('t_category'));
$row = $admin->query("SELECT s_name, s_slug FROM {$p}t_category_description WHERE s_name = 'Garden tools'")->fetch_assoc();
pin('the slug comes from the typed name', 'garden-tools', $row['s_slug'] ?? null);
pin('no placeholder name is stored', 0, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_category_description WHERE s_name LIKE '%EDIT ME%'")->fetch_row()[0]);

$before = $count('t_category');
$r = $call(CategoryAjax::class, 'editPost', ['id' => '', $locale . '#s_name' => '']);
pin('no name is refused', 1, $r['error'] ?? null);
pin('and writes no row', $before, $count('t_category'));

harness_section('custom field add');
$r = $call(FieldAjax::class, 'add', ['s_name' => 'Wood type', 'field_type' => 'DROPDOWN']);
pin('added', 0, $r['error'] ?? null);
$row = $admin->query("SELECT s_name, s_slug, e_type FROM {$p}t_meta_fields WHERE pk_i_id = " . (int) ($r['field_id'] ?? 0))->fetch_assoc();
pin('the name, slug and type are the typed ones', ['Wood type', 'wood-type', 'DROPDOWN'], [$row['s_name'] ?? null, $row['s_slug'] ?? null, $row['e_type'] ?? null]);
$before = $count('t_meta_fields');
$r = $call(FieldAjax::class, 'add', ['s_name' => '  ']);
pin('a blank name is refused', 1, $r['error'] ?? null);
$r2 = $call(FieldAjax::class, 'add', ['s_name' => 'Wood type']);
pin('a taken name is refused', 1, $r2['error'] ?? null);
pin('and neither writes a row', $before, $count('t_meta_fields'));
pin('no placeholder field is stored', 0, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_meta_fields WHERE s_name LIKE 'NEW custom field%'")->fetch_row()[0]);

harness_section('form add');
$r = $call(FormBuilderAjax::class, 'addGroup', ['group_name' => 'Tool details']);
$row = $admin->query("SELECT s_name, s_slug FROM {$p}t_meta_group WHERE pk_i_id = " . (int) ($r['group_id'] ?? 0))->fetch_assoc();
pin('the form has the typed name', ['Tool details', 'tool-details'], [$row['s_name'] ?? null, $row['s_slug'] ?? null]);
$before = $count('t_meta_group');
$r = $call(FormBuilderAjax::class, 'addGroup', ['group_name' => '']);
pin('a blank name is refused and writes nothing', [1, $before], [$r['error'] ?? null, $count('t_meta_group')]);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
