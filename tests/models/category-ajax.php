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
/** Run editIframe() against a recording controller and return what it exported. */
$iframe = static function (array $post): array {
    foreach (['id', 'parent_id'] as $k) {
        Params::setParam($k, null);
    }
    foreach ($post as $k => $v) {
        Params::setParam($k, $v);
    }
    $view = new ArrayObject();
    $stub = new class ($view) extends CAdminAjax {
        public function __construct(public ArrayObject $view)
        {
        }
        public function _exportVariableToView($key, $value)
        {
            $this->view[$key] = $value;
        }
        public function doView($file)
        {
            $this->view['_file'] = $file;
        }
    };
    (new CategoryAjax($stub))->editIframe();

    return $view->getArrayCopy();
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

harness_section('edit frame');
$off2 = seed_category($admin, 'Gamma', null, $locale);
$admin->query("UPDATE {$p}t_category SET i_expiration_days = 45, b_price_enabled = 0 WHERE pk_i_id = $off2");
$v = $iframe(['id' => '', 'parent_id' => (string) $off2]);
pin('a new category under a parent exports that parent', [$off2, 'Gamma'], [$v['parent']['id'] ?? null, $v['parent']['name'] ?? null]);
pin('...and inherits its expiry and price setting', [45, 0, $off2], [$v['category']['i_expiration_days'], $v['category']['b_price_enabled'], $v['category']['fk_i_parent_id']]);
$v = $iframe(['id' => '', 'parent_id' => '99999']);
pin('a bad parent falls back to the top level', [null, null], [$v['parent'], $v['category']['fk_i_parent_id']]);
$v = $iframe(['id' => '']);
pin('a new category at the top has no parent and no subcategories', [null, 0], [$v['parent'], $v['subcategory_count']]);
$deep = seed_category($admin, 'Deep', $a2, $locale);
$v = $iframe(['id' => (string) $a]);
pin('the subcategory count covers every level below', [true, 3], [$v['has_subcategories'], $v['subcategory_count']]);
$v = $iframe(['id' => (string) $deep]);
pin('an edited category exports its parent', [$a2, 'Alpha two', false, 0], [$v['parent']['id'], $v['parent']['name'], $v['has_subcategories'], $v['subcategory_count']]);

harness_section('edit with a new parent');
$leaf = seed_category($admin, 'Leaf', null, $locale);
$hooks = 0;
osc_add_hook('edited_category_order', static function () use (&$hooks): void {
    $hooks++;
});
$post = static fn (int $id, string $name, string $parentId) => ['id' => (string) $id, $locale . '#s_name' => $name, 'i_expiration_days' => '0', 'b_price_enabled' => '1', 'parent_id' => $parentId];
$r = $call('editPost', $post($leaf, 'Leaf moved', (string) $b));
pin('a new parent moves the category and saves the name', [true, $b, $b, 'Leaf moved'], [$r['moved'] ?? null, $r['parent_id'] ?? null, $parent($leaf), $one("SELECT s_name FROM {$p}t_category_description WHERE fk_i_category_id = $leaf")]);
pin('...as the last in its new group', $leaf, (int) $one("SELECT pk_i_id FROM {$p}t_category WHERE fk_i_parent_id = $b ORDER BY i_position DESC LIMIT 1"));
pin('...with one order hook', 1, $hooks);
$r = $call('editPost', $post($leaf, 'Leaf again', (string) $b));
pin('the same parent moves nothing', [false, 1], [$r['moved'] ?? null, $hooks]);
$r = $call('editPost', $post($a, 'Alpha changed', (string) $deep));
pin('a move into its own child is refused on the parent', [3, '/parent_id'], [$r['error'] ?? null, $r['field'] ?? null]);
pin('...and nothing is saved', 'Alpha', $one("SELECT s_name FROM {$p}t_category_description WHERE fk_i_category_id = $a"));
$r = $call('editPost', $post($leaf, 'Leaf top', '0'));
pin('parent 0 moves it to the top level', [true, null, 0], [$r['moved'] ?? null, $parent($leaf), $r['parent_id'] ?? null]);

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

harness_section('expiry preview');
$sub  = seed_category($admin, 'Alpha sub', $a1, $locale);
$mk   = static function (int $cat, int $ageDays, array $set = []) use ($admin, $p, $user): int {
    $id = seed_item($admin, $cat, $user, 'Aged');
    $admin->query("UPDATE {$p}t_item SET dt_pub_date = DATE_SUB(NOW(), INTERVAL $ageDays DAY), dt_expiration = DATE_ADD(NOW(), INTERVAL 30 DAY)" . ($set ? ', ' . implode(', ', $set) : '') . " WHERE pk_i_id = $id");

    return $id;
};
$mk($a1, 11);
$mk($a1, 9);
$mk($a1, 10 + 1, ['dt_expiration = DATE_SUB(NOW(), INTERVAL 1 DAY)']);
$mk($a1, 12, ['b_active = 0']);
$mk($a1, 12, ['b_enabled = 0']);
$mk($a1, 12, ['b_spam = 1']);
$mk($sub, 20);
$r = $call('expiryPreview', ['ids' => [(string) $a1], 'days' => '10']);
pin('counts the category only, and those ended by 10 days', [7, 1], [$r['listings'] ?? null, $r['endNow'] ?? null]);
$r = $call('expiryPreview', ['ids' => [(string) $a1], 'days' => '10', 'to_subcategories' => '1']);
pin('with subcategories the reach grows', [8, 2], [$r['listings'] ?? null, $r['endNow'] ?? null]);
$r = $call('expiryPreview', ['ids' => [(string) $a1], 'days' => '0', 'to_subcategories' => '1']);
pin('0 days never ends anything', [8, 0], [$r['listings'] ?? null, $r['endNow'] ?? null]);
$r = $call('expiryPreview', ['ids' => [(string) $a1], 'days' => '9']);
pin('a listing 9 days old ends at exactly 9', 2, $r['endNow'] ?? null);
$r = $call('expiryPreview', ['ids' => [(string) $a1, (string) $sub], 'days' => '10', 'to_subcategories' => '1']);
pin('an id inside another is counted once', [8, 2], [$r['listings'] ?? null, $r['endNow'] ?? null]);
$r = $call('expiryPreview', ['ids' => ['99999'], 'days' => '10']);
pin('unknown ids reach nothing', [0, 0], [$r['listings'] ?? null, $r['endNow'] ?? null]);
$mk($a1, 15, ['b_premium = 1']);
$r = $call('expiryPreview', ['ids' => [(string) $a1], 'days' => '10']);
pin('a premium listing never ends', [8, 1], [$r['listings'] ?? null, $r['endNow'] ?? null]);
$route = \mindstellar\admin\ajax\AjaxRegistry::route('category_expiry_preview');
pin('it is a read: no CSRF token', false, $route['csrf'] ?? null);
pin('...and admins only', false, \mindstellar\admin\ajax\AjaxRegistry::moderatorMay('category_expiry_preview'));

harness_section('expiry choice');
$before = $expiry($item);
$call('editPost', ['id' => (string) $a1, $locale . '#s_name' => 'Alpha one', 'i_expiration_days' => '5', 'b_price_enabled' => '1']);
pin('saving without a choice keeps the listing dates', $before, $expiry($item));
pin('...and saves the days on the category', 5, (int) $one("SELECT i_expiration_days FROM {$p}t_category WHERE pk_i_id = $a1"));
$call('editPost', ['id' => (string) $a1, $locale . '#s_name' => 'Alpha one', 'i_expiration_days' => '0', 'b_price_enabled' => '1', 'expiry_listings' => 'new']);
pin('"new" re-dates the listing', '9999-12-31 23:59:59', $expiry($item));

$call('editPost', ['id' => (string) $a1, $locale . '#s_name' => 'Alpha one', 'i_expiration_days' => '', 'b_price_enabled' => '1']);
pin('empty days keep the category\'s expiry', 0, (int) $one("SELECT i_expiration_days FROM {$p}t_category WHERE pk_i_id = $a1"));
$call('editPost', ['id' => (string) $a1, $locale . '#s_name' => 'Alpha one', 'i_expiration_days' => '7', 'b_price_enabled' => '1']);
$call('editPost', ['id' => (string) $a1, $locale . '#s_name' => 'Alpha one', 'i_expiration_days' => '', 'b_price_enabled' => '1']);
pin('...not reset them to 0', 7, (int) $one("SELECT i_expiration_days FROM {$p}t_category WHERE pk_i_id = $a1"));

harness_section('names');
$r = $call('editPost', ['id' => (string) $a1, $locale . '#s_name' => '', 'b_price_enabled' => '1']);
pin('no name at all saves nothing', [1, 'Alpha one'], [$r['error'] ?? null, $one("SELECT s_name FROM {$p}t_category_description WHERE fk_i_category_id = $a1")]);
$r = $call('editPost', ['id' => (string) $a1, $locale . '#s_name' => 'Alpha uno', 'b_price_enabled' => '1']);
pin('a name saves with code 0', [0, 'Alpha uno'], [$r['error'] ?? null, $r['text'] ?? null]);

$r2 = seed_category($admin, 'Root two', null, $locale);
$r3 = seed_category($admin, 'Root three', $r2, $locale);
$r1 = seed_category($admin, 'Root one', $r3, $locale);

harness_section('enable');
$r = $call('enable', ['id' => (string) $r2, 'enabled' => '0']);
pin('a string id disables the subtree', [true, 0, 0], [isset($r['ok']), $on($r2), $on($r1)]);
$r = $call('enable', ['id' => '99999', 'enabled' => '1']);
pin('an unknown id answers an error', true, isset($r['error']));

harness_section('delete');
$r = $call('delete', ['id' => (string) $b]);
pin('delete says whether it queued', false, $r['queued'] ?? null);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
