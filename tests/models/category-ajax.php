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
 * The Categories AJAX handlers: create under a parent, move, bulk, the delete preview and the
 * drawer's expiry choice. They only read input and answer JSON.
 *
 * Usage:  php tests/models/category-ajax.php
 *         php tests/run-models.php category-ajax
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\admin\ajax\CategoryAjax;
use mindstellar\utility\Utils;

$admin = api_admin_boot('osc_models_category_ajax');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$user = seed_user($admin, 'sue', 'sue@example.test');
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
if (!function_exists('_n')) {
    function _n($one, $many, $count)
    {
        return $count == 1 ? $one : $many;
    }
}

/** Run a handler with the posted values and return what it answered. */
$call = static function (string $method, array $post): array {
    foreach (['id', 'ids', 'do', 'parent_id', 'position', 'days', 'expiry_listings', 'to_subcategories', 's_name', 'i_expiration_days', 'b_price_enabled', 'apply_changes_to_subcategories'] as $k) {
        Params::setParam($k, null);
    }
    foreach ($post as $k => $v) {
        Params::setParam($k, $v);
    }
    $handler = (new ReflectionClass(CategoryAjax::class))->newInstanceWithoutConstructor();
    ob_start();
    $handler->$method();

    return json_decode((string) ob_get_clean(), true) ?? ['unparsed' => true];
};
$one    = static fn (string $sql) => $admin->query($sql)->fetch_row()[0] ?? null;
$parent = static fn (int $id): ?int => ($v = $one("SELECT fk_i_parent_id FROM {$p}t_category WHERE pk_i_id = $id")) === null ? null : (int) $v;
$on     = static fn (int $id): int => (int) $one("SELECT b_enabled FROM {$p}t_category WHERE pk_i_id = $id");
$expiry = static fn (int $item): string => (string) $one("SELECT dt_expiration FROM {$p}t_item WHERE pk_i_id = $item");

$a  = seed_category($admin, 'Alpha', null, $locale);
$a1 = seed_category($admin, 'Alpha one', $a, $locale);
$b  = seed_category($admin, 'Beta', null, $locale);
$item = seed_item($admin, $a1, $user, 'In A1');
Utils::updateAllCategoriesStats();

harness_section('create under a parent');
$r = $call('editPost', ['id' => '', $locale . '#s_name' => 'Alpha two', 'i_expiration_days' => '0', 'b_price_enabled' => '1', 'parent_id' => (string) $a]);
$a2 = (int) ($r['id'] ?? 0);
pin('created under the parent', [0, $a], [$r['error'] ?? null, $parent($a2)]);
$offParent = seed_category($admin, 'Off', null, $locale, 0);
$r = $call('editPost', ['id' => '', $locale . '#s_name' => 'Under off', 'i_expiration_days' => '0', 'b_price_enabled' => '1', 'parent_id' => (string) $offParent]);
pin('under a disabled parent it answers an error, not a crash', [2, true], [$r['error'] ?? null, ($r['msg'] ?? '') !== '']);
pin('...and adds nothing', 0, (int) $one("SELECT COUNT(*) FROM {$p}t_category WHERE fk_i_parent_id = $offParent"));

harness_section('move');
$r = $call('move', ['id' => (string) $a1, 'parent_id' => (string) $b, 'position' => '0']);
pin('a move answers ok', true, $r['ok'] ?? null);
pin('and the category is under the new parent', $b, $parent($a1));
$r = $call('move', ['id' => (string) $a, 'parent_id' => (string) $a2, 'position' => '0']);
pin('a move inside itself is refused on the parent', '/parent_id', $r['field'] ?? null);
pin('and writes nothing', null, $parent($a));
$r = $call('move', ['id' => '99999', 'parent_id' => '0', 'position' => '0']);
pin('an unknown category answers an error', true, isset($r['error']));

harness_section('bulk');
$r = $call('bulk', ['do' => 'disable', 'ids' => [(string) $b, (string) $a1]]);
pin('disabling a child with its parent reports one done', [true, [$b]], [$r['ok'] ?? null, $r['done'] ?? null]);
pin('the child went off with it', [0, 0], [$on($b), $on($a1)]);
$r = $call('bulk', ['do' => 'enable', 'ids' => [(string) $b]]);
pin('enable turns the subtree back on', [$b], $r['done'] ?? null);
pin('...child included', [1, 1], [$on($b), $on($a1)]);
$r = $call('bulk', ['do' => 'disable', 'ids' => 'abc']);
pin('a non-numeric id list answers an error', true, isset($r['error']) && !isset($r['unparsed']));
$r = $call('bulk', ['do' => 'explode', 'ids' => [(string) $a]]);
pin('an unknown action answers an error', true, isset($r['error']));
$r = $call('bulk', ['do' => 'move', 'ids' => [(string) $a1], 'parent_id' => '0', 'position' => '0']);
pin('bulk move works', [$a1], $r['done'] ?? null);
pin('...to the top level', null, $parent($a1));

harness_section('delete preview');
$r = $call('deletePreview', ['ids' => [(string) $a1, (string) $a]]);
$service = \mindstellar\category\CategoryService::make();
pin('the preview is the service\'s numbers', $service->deletePreview([$a1, $a]), $r);
pin('and counts the listing', 1, $r['listings'] ?? null);

harness_section('expiry choice');
$before = $expiry($item);
$call('editPost', ['id' => (string) $a1, $locale . '#s_name' => 'Alpha one', 'i_expiration_days' => '5', 'b_price_enabled' => '1']);
pin('saving without a choice keeps the listing dates', $before, $expiry($item));
pin('...and saves the days on the category', 5, (int) $one("SELECT i_expiration_days FROM {$p}t_category WHERE pk_i_id = $a1"));
$call('editPost', ['id' => (string) $a1, $locale . '#s_name' => 'Alpha one', 'i_expiration_days' => '0', 'b_price_enabled' => '1', 'expiry_listings' => 'new']);
pin('"new" re-dates the listing', '9999-12-31 23:59:59', $expiry($item));

harness_section('delete');
$r = $call('delete', ['id' => (string) $b]);
pin('delete says whether it queued', false, $r['queued'] ?? null);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
