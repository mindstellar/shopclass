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
 * `/admin/listings` end to end through Kernel::handle(): who may call it (admin, moderator,
 * a key without the scope, a user, a deleted admin), the list with its filters and paging,
 * each action firing the listings screen's hooks once and writing the activity log, the
 * admin's edit with owner and expiry, delete, Idempotency-Key, and the query count of a page.
 *
 * Usage:  php tests/models/api-admin-listings.php        (standalone, own scratch database)
 *         php tests/run-models.php api-admin-listings    (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\api\Response;

$admin = api_admin_boot('osc_models_api_admin_listings');

/* ----------------------------------------------------------------------------
 * Fixture: Vehicles > Cars, two sellers, one listing in each status, an admin, a
 * moderator and keys for each.
 * ------------------------------------------------------------------------- */
$p        = DB_TABLE_PREFIX;
$locale   = seed_locale($admin);
seed_currency($admin);
$country  = seed_country($admin, 'US', 'United States');
$vehicles = seed_category($admin, 'Vehicles', null, $locale);
$cars     = seed_category($admin, 'Cars', $vehicles, $locale);
$sue      = seed_user($admin, 'sue', 'sue@example.test');
$tom      = seed_user($admin, 'tom', 'tom@example.test');
$live     = seed_item($admin, $cars, $sue, 'Live hatchback', 1500.0);
$pending  = seed_item($admin, $cars, $sue, 'Pending wagon', 900.0, 0);
$blocked  = seed_item($admin, $cars, $tom, 'Blocked van', 700.0, 0, 0);
$spam     = seed_item($admin, $cars, $tom, 'Spam coupe', 10.0);
$expired  = seed_item($admin, $cars, $sue, 'Expired estate', 300.0);
$admin->query("UPDATE {$p}t_item SET b_spam = 1 WHERE pk_i_id = $spam");
$admin->query("UPDATE {$p}t_item SET dt_expiration = '2020-01-01 00:00:00' WHERE pk_i_id = $expired");
$admin->query("UPDATE {$p}t_item SET dt_pub_date = '2026-01-01 00:00:00', dt_first_pub_date = '2026-01-01 00:00:00' WHERE pk_i_id = $live");
foreach (['enabled_users' => '1', 'moderate_items' => '-1', 'items_wait_time' => '0', 'language' => 'en_US', 'logs_admin' => '1',
    'title_character_length' => '100', 'description_character_length' => '5000'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.70';

$bossId   = api_admin_seed_admin($admin, 'boss');
$modId    = api_admin_seed_admin($admin, 'mod', true);
$boss     = api_admin_key($bossId);
$mod      = api_admin_key($modId, true);
$noScope  = api_admin_key($bossId, false, ['admin:users']);
$userKey  = (new \mindstellar\apiaccess\ApiKeys(new \mindstellar\model\ApiCredential(), new \mindstellar\apiaccess\Scopes(), new \mindstellar\utility\SystemClock()))
    ->create('key', 'sue', ['listings:read', 'listings:write'], \mindstellar\apiaccess\KeyOwner::user($sue))->token();

$call  = api_admin_caller();
$fired = [];
foreach (['activate_item', 'deactivate_item', 'enable_item', 'disable_item', 'item_spam_on', 'item_spam_off', 'item_premium_on',
    'item_premium_off', 'item_bumped', 'edited_item', 'before_delete_item', 'after_delete_item'] as $hook) {
    osc_add_hook($hook, static function (...$args) use (&$fired, $hook): void {
        $fired[$hook] = ($fired[$hook] ?? 0) + 1;
    });
}
$row  = static fn (int $id): ?array => $admin->query("SELECT * FROM {$p}t_item WHERE pk_i_id = $id")->fetch_assoc();
$logs = static fn (string $action, int $id): array => $admin->query("SELECT s_who, fk_i_who_id FROM {$p}t_log WHERE s_section = 'item' AND s_action = '" . $action . "' AND fk_i_id = $id")->fetch_all(MYSQLI_ASSOC);
$ids  = static fn (Response $r): array => array_column($r->body()['data'] ?? [], 'id');

harness_section('who may call it');
pin('an admin key: 200', 200, $call('GET', 'admin/listings', null, $boss)->status());
pin('a moderator key, as the listings screen is open to moderators: 200', 200, $call('GET', 'admin/listings', null, $mod)->status());
pin('an admin key without admin:listings: 403 insufficient_scope', '403 insufficient_scope', api_admin_code($call('GET', 'admin/listings', null, $noScope)));
pin('a user key: 403 forbidden', '403 forbidden', api_admin_code($call('GET', 'admin/listings', null, $userKey)));
pin('no key: 401', 401, $call('GET', 'admin/listings')->status());
pin('a moderator cannot reach the users screen\'s endpoints', '403 insufficient_scope', api_admin_code($call('GET', 'admin/users', null, $mod)));

harness_section('the list');
$r = $call('GET', 'admin/listings', null, $boss);
pin('every status, newest first', [$expired, $spam, $blocked, $pending, $live], $ids($r));
pin('no total unless asked', null, $r->body()['meta']['total'] ?? null);
$r = $call('GET', 'admin/listings', null, $boss, [], ['count' => 'true']);
pin('with the total when count=true', 5, $r->body()['meta']['total'] ?? null);
pin('matches the schema', [], api_admin_schema_errors('ListingPage', $r));
pin('in the admin view: the address and report counters', ['127.0.0.1', true], [$r->body()['data'][0]['ip'] ?? null, isset($r->body()['data'][0]['stats'])]);
pin('status=pending', [$pending], $ids($call('GET', 'admin/listings', null, $boss, [], ['status' => 'pending'])));
pin('status=spam,expired', [$expired, $spam], $ids($call('GET', 'admin/listings', null, $boss, [], ['status' => 'spam,expired'])));
pin('status=active', [$live], $ids($call('GET', 'admin/listings', null, $boss, [], ['status' => 'active'])));
pin('status=disabled', [$blocked], $ids($call('GET', 'admin/listings', null, $boss, [], ['status' => 'disabled'])));
pin('an unknown status is 422', '422 validation_failed', api_admin_code($call('GET', 'admin/listings', null, $boss, [], ['status' => 'gone'])));
pin('user=', [$spam, $blocked], $ids($call('GET', 'admin/listings', null, $boss, [], ['user' => (string) $tom])));
pin('q= matches titles, with % taken literally', [[$pending], []], [
    $ids($call('GET', 'admin/listings', null, $boss, [], ['q' => 'wagon'])), $ids($call('GET', 'admin/listings', null, $boss, [], ['q' => '%'])),
]);
$seen = [];
$next = ['limit' => '2'];
for ($i = 0; $i < 4 && $next !== null; $i++) {
    $page = $call('GET', 'admin/listings', null, $boss, [], $next);
    $seen = array_merge($seen, $ids($page));
    $link = $page->body()['links']['next'] ?? null;
    $next = null;
    if (is_string($link)) {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $next);
    }
}
pin('paging by id walks every listing once', [$expired, $spam, $blocked, $pending, $live], $seen);
pin('one listing whatever its status', [200, 'spam'], (static fn (Response $r): array => [$r->status(), $r->body()['data']['status'] ?? null])($call('GET', 'admin/listings/' . $spam, null, $mod)));
pin('an unknown listing is 404', 404, $call('GET', 'admin/listings/99999', null, $boss)->status());

harness_section('status through PATCH');
$fired = [];
$r     = $call('PATCH', 'admin/listings/' . $pending, ['approved' => true], $boss);
pin('approved: 200 with the listing now active, read back as approved', [200, 'active', true, '1'], [$r->status(), $r->body()['data']['status'] ?? null, $r->body()['data']['approved'] ?? null, $row($pending)['b_active']]);
pin('activate_item fired once, and a status-only PATCH is no edit', [1, 0], [$fired['activate_item'] ?? 0, $fired['edited_item'] ?? 0]);
pin('the activity log names the key\'s admin', [['s_who' => 'admin', 'fk_i_who_id' => (string) $bossId]], $logs('activate', $pending));
$fired = [];
$call('PATCH', 'admin/listings/' . $pending, ['approved' => true], $boss);
pin('asking again changes nothing: no hook, no log', [0, 1], [$fired['activate_item'] ?? 0, count($logs('activate', $pending))]);
pin('approving a blocked listing is 409', '409 conflict', api_admin_code($call('PATCH', 'admin/listings/' . $blocked, ['approved' => true], $boss)));
$fired = [];
foreach ([
    ['deactivate', ['approved' => false], 'deactivate_item', 'b_active', '0'],
    ['enable', ['blocked' => false], 'enable_item', 'b_enabled', '1'],
    ['disable', ['blocked' => true], 'disable_item', 'b_enabled', '0'],
    ['spam', ['spam' => true], 'item_spam_on', 'b_spam', '1'],
    ['unspam', ['spam' => false], 'item_spam_off', 'b_spam', '0'],
    ['premium', ['premium' => true], 'item_premium_on', 'b_premium', '1'],
    ['unpremium', ['premium' => false], 'item_premium_off', 'b_premium', '0'],
] as [$action, $body, $hook, $column, $value]) {
    $target = $action === 'enable' ? $blocked : $live;
    $before = $fired[$hook] ?? 0;
    $r      = $call('PATCH', 'admin/listings/' . $target, $body, $mod);
    $member = (string) array_key_first($body);
    pin($member . ': ' . json_encode($body[$member]) . ': 200, read back, ' . $hook . ' once, logged', [200, $body[$member], $value, 1, 1], [
        $r->status(), $r->body()['data'][$member] ?? null, $row($target)[$column], ($fired[$hook] ?? 0) - $before, count($logs($action, $target)),
    ]);
}
pin('a moderator\'s change is logged under the moderator', (string) $modId, $logs('spam', $live)[0]['fk_i_who_id'] ?? null);
$call('PATCH', 'admin/listings/' . $blocked, ['blocked' => true, 'approved' => false], $boss);
$r = $call('PATCH', 'admin/listings/' . $blocked, ['blocked' => false, 'approved' => true], $boss);
pin('unblocking and approving in one call works', [200, 'active', false, true], [$r->status(), $r->body()['data']['status'] ?? null, $r->body()['data']['blocked'] ?? null, $r->body()['data']['approved'] ?? null]);
$call('PATCH', 'admin/listings/' . $blocked, ['blocked' => true], $boss);
pin('a user key cannot change the status', '403 forbidden', api_admin_code($call('PATCH', 'admin/listings/' . $live, ['blocked' => true], $userKey)));
pin('an unknown listing is 404', 404, $call('PATCH', 'admin/listings/99999', ['spam' => true], $boss)->status());
$first = $row($live)['dt_first_pub_date'];
$r     = $call('POST', 'admin/listings/' . $live . '/bump', null, $boss, ['Idempotency-Key' => 'bump-1']);
$again = $call('POST', 'admin/listings/' . $live . '/bump', null, $boss, ['Idempotency-Key' => 'bump-1']);
check('bump moves the publish date to now', strtotime((string) $row($live)['dt_pub_date']) > time() - 60);
pin('and keeps the first publish date', $first, $row($live)['dt_first_pub_date']);
pin('item_bumped fired once; the same Idempotency-Key is replayed', [1, 'true', 1], [$fired['item_bumped'] ?? 0, $again->header('Idempotency-Replayed'), count($logs('bump', $live))]);
pin('an unknown listing cannot be bumped', 404, $call('POST', 'admin/listings/99999/bump', null, $boss)->status());

harness_section('the admin\'s edit');
$fired = [];
$r     = $call('PATCH', 'admin/listings/' . $live, ['price' => '1200', 'owner_id' => $tom], $boss);
pin('PATCH answers the listing with the new price and owner', [200, '1200.00', $tom], [$r->status(), $r->body()['data']['price']['amount'] ?? null, $r->body()['data']['seller']['id'] ?? null]);
pin('members not sent keep their values', ['Live hatchback', 'tom@example.test'], [$r->body()['data']['title'] ?? null, $row($live)['s_contact_email']]);
pin('edited_item fired once, and the log names the admin', [1, (string) $bossId], [$fired['edited_item'] ?? 0, $logs('edit', $live)[0]['fk_i_who_id'] ?? null]);
pin('matches the schema', [], api_admin_schema_errors('ListingDocument', $r));
$r = $call('PATCH', 'admin/listings/' . $live, ['owner_id' => null, 'contact_name' => 'Walk-in', 'contact_email' => 'walkin@example.test', 'expires_at' => '2030-06-30'], $boss);
pin('owner_id null leaves it with no owner and the given contact', [null, 'Walk-in', 'walkin@example.test'], [$row($live)['fk_i_user_id'], $row($live)['s_contact_name'], $row($live)['s_contact_email']]);
pin('expires_at sets the last day', '2030-06-30 23:59:59', $row($live)['dt_expiration']);
$call('PATCH', 'admin/listings/' . $live, ['price' => '1100'], $boss);
pin('an edit without expires_at keeps the expiry', '2030-06-30 23:59:59', $row($live)['dt_expiration']);
pin('an unknown owner is 422', [422, '/owner_id'], (static fn (Response $r): array => [$r->status(), $r->body()['errors'][0]['pointer'] ?? null])($call('PATCH', 'admin/listings/' . $live, ['owner_id' => 99999], $boss)));
pin('ItemActions\' own refusal is 422', 422, $call('PATCH', 'admin/listings/' . $live, ['description' => 'ab'], $boss)->status());
pin('an unknown member is 422', 422, $call('PATCH', 'admin/listings/' . $live, ['photo_tokens' => []], $boss)->status());

harness_section('delete');
$fired = [];
pin('DELETE: 204', 204, $call('DELETE', 'admin/listings/' . $spam, null, $boss)->status());
pin('the row is gone, the hooks fired once and the log names the admin', [null, 1, (string) $bossId], [$row($spam), $fired['after_delete_item'] ?? 0, $logs('delete', $spam)[0]['fk_i_who_id'] ?? null]);
pin('again it is 404', 404, $call('DELETE', 'admin/listings/' . $spam, null, $boss)->status());

harness_section('a key whose admin is gone');
$admin->query("DELETE FROM {$p}t_api_credential WHERE fk_i_admin_id = $modId");
$gone = api_admin_key($modId, true);
$admin->query("DELETE FROM {$p}t_admin WHERE pk_i_id = $modId");
pin('it answers 401', 401, $call('GET', 'admin/listings', null, $gone)->status());

harness_section('queries');
for ($i = 0; $i < 6; $i++) {
    seed_item($admin, $cars, $i % 2 === 0 ? $sue : $tom, 'Filler ' . $i, 100.0 + $i);
}
scratchdb_forget_cache();
$count = static fn (int $limit): int => harness_query_count(static fn () => $call('GET', 'admin/listings', null, $boss, [], ['limit' => (string) $limit]));
$count(2);
$two  = $count(2);
$six  = $count(6);
echo "  a page of 2: $two queries, of 6: $six\n";
pin('a page of 6 costs the same queries as a page of 2', $two, $six);
pin('a page of admin listings: 9 queries, the count is skipped', 9, $six);

exit(harness_result());
