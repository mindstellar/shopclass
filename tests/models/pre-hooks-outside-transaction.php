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
 * The hooks a plugin uses to refuse a save (pre_item_add, pre_item_edit, before_add_comment,
 * before_user_delete...) run before the save's transaction opens. A plugin that writes a row
 * and then redirects keeps that row, and leaves no transaction open.
 *
 * Usage:  php tests/models/pre-hooks-outside-transaction.php        (standalone, own scratch database)
 *         php tests/run-models.php pre-hooks-outside-transaction    (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\auth\Actor;
use mindstellar\comment\CommentService;
use mindstellar\database\Db;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\moderation\ListingModeration;
use mindstellar\user\AccountService;

$admin = api_admin_boot('osc_models_pre_hooks_outside_tx');

$p   = DB_TABLE_PREFIX;
$en  = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Cars', seed_category($admin, 'Vehicles', null, $en), $en);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach ([
    'moderate_items'         => '-1',
    'items_wait_time'        => '0',
    'language'               => 'en_US',
    'enabled_users'          => '1',
    'enabled_comments'       => '1',
    'moderate_comments'      => '-1',
    'reg_user_post_comments' => '0',
    'enabled_user_registration' => '1',
    'enabled_user_validation'   => '0',
] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.72';
Params::init();

$actor   = Actor::admin(1, '192.0.2.72');
$service = new ListingService();
$form    = static fn (array $extra = []): array => $extra + [
    'catId'        => (string) $cat,
    'countryId'    => 'US',
    'country'      => 'United States',
    'region'       => 'Texas',
    'city'         => 'Austin',
    'title'        => ['en_US' => 'Red hatchback'],
    'description'  => ['en_US' => 'A small red car, one owner, full service history.'],
    'price'        => '1500',
    'contactName'  => 'Office',
    'contactEmail' => 'office@example.test',
    'ownerId'      => '0',
];
$post = static fn () => $service->create(ListingInput::fromArray($form(), $actor, true), $actor)->id();
$edit = static fn (int $id) => $service->update(ListingInput::fromArray($form(['id' => (string) $id, 'price' => '1600']), $actor, false), $actor, false, false);

/** Whether a transaction was open when each hook ran. */
$open  = [];
$watch = static function (string $hook, bool $filter = false) use (&$open): void {
    $fn = static function (...$args) use (&$open, $hook) {
        $open[$hook] = Db::inTransaction();

        return $args[0] ?? null;
    };
    $filter ? osc_add_filter($hook, $fn) : osc_add_hook($hook, $fn);
};
foreach (['item_add_prepare_data', 'pre_item_add_error', 'item_edit_prepare_data', 'pre_item_edit_error'] as $hook) {
    $watch($hook, true);
}
foreach (['pre_item_add', 'pre_item_edit', 'before_delete_item', 'before_user_delete', 'before_add_comment', 'pre_item_add_comment_post', 'before_user_register', 'pre_user_post'] as $hook) {
    $watch($hook);
}

/** A plugin that writes a ban rule from a hook and then stops the request, as a redirect does. */
$armed = null;
$bans  = static fn (string $name): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_ban_rule WHERE s_name = '" . $admin->real_escape_string($name) . "'")->fetch_row()[0];
foreach (['pre_item_add', 'pre_item_edit', 'before_delete_item', 'before_user_delete', 'before_add_comment'] as $hook) {
    osc_add_hook($hook, static function () use (&$armed, $hook): void {
        if ($armed !== $hook) {
            return;
        }
        $armed = null;
        Db::table(DB_TABLE_PREFIX . 't_ban_rule')->insert(['s_name' => $hook, 's_ip' => '192.0.2.99']);

        throw new RuntimeException('redirected');
    });
}
$redirects = static function (string $hook, callable $save) use (&$armed, $bans): array {
    $armed = $hook;
    $threw = false;
    try {
        $save();
    } catch (RuntimeException $e) {
        $threw = $e->getMessage() === 'redirected';
    }

    return [$threw, $bans($hook), Db::inTransaction()];
};

harness_section('posting a listing');
$id = $post();
check('the listing is saved', $id > 0);
pin('item_add_prepare_data, pre_item_add and pre_item_add_error run with no transaction open', [false, false, false], [
    $open['item_add_prepare_data'] ?? null, $open['pre_item_add'] ?? null, $open['pre_item_add_error'] ?? null,
]);
$before = (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item")->fetch_row()[0];
pin('a pre_item_add that writes a ban rule and redirects keeps the rule', [true, 1, false], $redirects('pre_item_add', $post));
pin('and no listing is written', $before, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item")->fetch_row()[0]);

harness_section('editing a listing');
$open = [];
$edit($id);
pin('item_edit_prepare_data, pre_item_edit and pre_item_edit_error run with no transaction open', [false, false, false], [
    $open['item_edit_prepare_data'] ?? null, $open['pre_item_edit'] ?? null, $open['pre_item_edit_error'] ?? null,
]);
pin('a pre_item_edit that writes a ban rule and redirects keeps the rule', [true, 1, false], $redirects('pre_item_edit', static fn () => $edit($id)));
pin('and the price is not changed', '1600', (string) ((int) $admin->query("SELECT i_price FROM {$p}t_item WHERE pk_i_id = $id")->fetch_row()[0] / 1000000));

$open = [];
$adminEdit = static fn () => ListingModeration::make()->edit($id, ListingInput::fromArray($form(['id' => (string) $id, 'price' => '1700']), $actor, false), $actor, ['premium' => true], 1, '');
check('an admin edit with flags is saved', $adminEdit());
pin('pre_item_edit runs with no transaction open on the admin edit too', false, $open['pre_item_edit'] ?? null);
pin('the edit and the flag are both kept', ['1700', '1'], array_map('strval', array_values((array) $admin->query("SELECT i_price DIV 1000000, b_premium FROM {$p}t_item WHERE pk_i_id = $id")->fetch_assoc())));
pin('a pre_item_edit that redirects on the admin edit keeps the rule', [true, 2, false], $redirects('pre_item_edit', $adminEdit));
$admin->query("UPDATE {$p}t_item SET b_enabled = 0, b_active = 0 WHERE pk_i_id = $id");
$refusedFlag = null;
try {
    ListingModeration::make()->edit($id, ListingInput::fromArray($form(['id' => (string) $id, 'price' => '1800']), $actor, false), $actor, ['active' => true], 1, '');
} catch (\mindstellar\exception\ConflictException $e) {
    $refusedFlag = $e;
}
check('a flag that is refused refuses the admin edit', $refusedFlag !== null);
pin('and the edit is rolled back with it', '1700', (string) ((int) $admin->query("SELECT i_price FROM {$p}t_item WHERE pk_i_id = $id")->fetch_row()[0] / 1000000));
$admin->query("UPDATE {$p}t_item SET b_enabled = 1, b_active = 1 WHERE pk_i_id = $id");

harness_section('deleting a listing');
$secret = static fn (int $item): string => (string) $admin->query("SELECT s_secret FROM {$p}t_item WHERE pk_i_id = $item")->fetch_row()[0];
$gone   = $post();
$open   = [];
check('the listing is deleted', $service->delete($gone, $secret($gone), $actor) === 1);
pin('before_delete_item runs with no transaction open', false, $open['before_delete_item'] ?? null);
pin('a before_delete_item that writes a ban rule and redirects keeps the rule', [true, 1, false], $redirects('before_delete_item', static fn () => $service->delete($id, $secret($id), $actor)));
pin('and the listing is kept', 1, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item WHERE pk_i_id = $id")->fetch_row()[0]);

harness_section('commenting');
$commenter = new Actor($sue, null, '192.0.2.72');
$open      = [];
$saved     = (new CommentService())->post($id, ['title' => '', 'body' => 'Is it still for sale?'], $commenter);
check('the comment is saved', $saved->id() > 0);
pin('pre_item_add_comment_post and before_add_comment run with no transaction open', [false, false], [$open['pre_item_add_comment_post'] ?? null, $open['before_add_comment'] ?? null]);
pin('a before_add_comment that writes a ban rule and redirects keeps the rule', [true, 1, false], $redirects('before_add_comment', static fn () => (new CommentService())->post($id, ['title' => '', 'body' => 'Second question'], $commenter)));
pin('and only the first comment is written', 1, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_comment WHERE fk_i_item_id = $id")->fetch_row()[0]);

harness_section('signing up and deleting an account');
$open = [];
(new AccountService())->register(['s_name' => 'Dee', 's_email' => 'dee@example.test', 's_password' => 'pass word', 's_password2' => 'pass word', 's_username' => ''], Actor::guest('192.0.2.72'));
pin('before_user_register and pre_user_post run with no transaction open', [false, false], [$open['before_user_register'] ?? null, $open['pre_user_post'] ?? null]);
$tom  = seed_user($admin, 'tom', 'tom@example.test');
$open = [];
(new AccountService())->delete($tom, $actor);
pin('before_user_delete runs with no transaction open', false, $open['before_user_delete'] ?? null);
$ann = seed_user($admin, 'ann', 'ann@example.test');
pin('a before_user_delete that writes a ban rule and redirects keeps the rule', [true, 1, false], $redirects('before_user_delete', static fn () => (new AccountService())->delete($ann, $actor)));
pin('and the account is kept', 1, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_user WHERE pk_i_id = $ann")->fetch_row()[0]);

exit(harness_result());
