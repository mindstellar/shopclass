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
 * The wishlist: the store's order, cap and live-only rule, the account/wishlist routes and their
 * scopes, who may write (a key or page-token session yes, the in-process client no, a guest
 * never), and the personal-data export and erase.
 * Usage: php tests/api-wishlist.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\api\InternalClient;
use mindstellar\apikey\CredentialKind;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use mindstellar\listing\WishlistService;
use mindstellar\listing\WishlistStore;
use mindstellar\model\KeyValue;

$admin = api_admin_boot('osc_api_wishlist');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Mugs', seed_category($admin, 'Goods', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'rewriteEnabled' => '0'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

$a      = seed_item($admin, $cat, $tom, 'Alpha', 5.0);
$b      = seed_item($admin, $cat, $tom, 'Beta', 5.0);
$c      = seed_item($admin, $cat, $tom, 'Gamma', 5.0);
$hidden = seed_item($admin, $cat, $tom, 'Hidden', 5.0, 0);
$wishlist = new WishlistService();
$stored = static fn (int $user): ?string => (new KeyValue())->get(WishlistStore::GROUP, (string) $user)['value'] ?? null;

harness_section('the service');
pin('an empty list to start', [], $wishlist->all($sue));
$wishlist->add($sue, $a);
$wishlist->add($sue, $b);
pin('newest first', [$b, $a], $wishlist->all($sue));
$wishlist->add($sue, $a);
pin('adding again moves it to the top, once', [$a, $b], $wishlist->all($sue));
try {
    $wishlist->add($sue, $hidden);
    $refused = 'added';
} catch (\mindstellar\exception\NotFoundException $e) {
    $refused = 'not found';
}
pin('a hidden listing cannot be added', ['not found', [$a, $b]], [$refused, $wishlist->all($sue)]);
pin('replace keeps the order given and drops hidden, unknown and repeated ids', [$c, $a], $wishlist->replace($sue, [$c, $hidden, 999999, $a, $c, '0', -4, 'x']));
pin('and that is what is stored', '[' . $c . ',' . $a . ']', $stored($sue));
$wishlist->remove($sue, $c);
pin('remove takes one off', [$a], $wishlist->all($sue));
$wishlist->remove($sue, $a);
pin('an empty list leaves no row', null, $stored($sue));

$ids = [];
for ($i = 0; $i < WishlistService::MAX + 5; $i++) {
    $ids[] = seed_item($admin, $cat, $tom, 'Bulk ' . $i, 1.0);
}
$kept = $wishlist->replace($sue, $ids);
pin('the list holds at most ' . WishlistService::MAX . ', the first ones', [WishlistService::MAX, $ids[0], $ids[WishlistService::MAX - 1]], [count($kept), $kept[0], end($kept)]);
$wishlist->add($sue, $a);
$after = $wishlist->all($sue);
pin('a new one past the cap drops the oldest', [WishlistService::MAX, $a, $ids[WishlistService::MAX - 2]], [count($after), $after[0], end($after)]);
$admin->query("UPDATE {$p}t_key_value SET s_value = '[\"x\",{\"a\":1},$b,$b]' WHERE s_group = 'wishlist' AND s_key = '$sue'");
pin('a damaged row reads as its clean ids', [$b], $wishlist->all($sue));
$wishlist->replace($sue, []);

harness_section('scopes');
$scopes = new Scopes();
foreach ([CredentialKind::KEY => 'a user key', CredentialKind::SESSION => 'a page-token session', CredentialKind::USER => 'an access token'] as $kind => $label) {
    $allowed = $scopes->allowedFor($kind, KeyOwner::user($sue));
    check($label . ' may hold wishlist:read and wishlist:write', in_array('wishlist:read', $allowed, true) && in_array('wishlist:write', $allowed, true));
}
check('wishlist:write grants wishlist:read', Scopes::implies(['wishlist:write'], 'wishlist:read'));
check('a public key holds neither', !in_array('wishlist:read', $scopes->allowedFor(CredentialKind::PUBLIC, KeyOwner::user($sue)), true));

harness_section('the routes');
$keys    = new \mindstellar\apikey\ApiKeys(new \mindstellar\model\ApiCredential(), $scopes, new \mindstellar\utility\SystemClock());
$key     = static fn (int $user, array $grant): string => $keys->create('key', 'k' . $user . implode('', $grant), $grant, KeyOwner::user($user))->token();
$sueKey  = $key($sue, ['wishlist:write']);
$tomKey  = $key($tom, ['wishlist:write']);
$readKey = $key($sue, ['wishlist:read']);
$call    = api_admin_caller();

$r = $call('GET', 'account/wishlist', null, $sueKey);
pin('an empty wishlist', [200, ['ids' => [], 'count' => 0]], [$r->status(), $r->body()['data'] ?? null]);
pin('the answer matches the schema', [], api_admin_schema_errors('WishlistDocument', $r));
pin('POST adds', 204, $call('POST', 'account/wishlist/' . $a, null, $sueKey)->status());
$call('POST', 'account/wishlist/' . $b, null, $sueKey);
pin('GET lists newest first with a count', ['ids' => [$b, $a], 'count' => 2], $call('GET', 'account/wishlist', null, $sueKey)->body()['data'] ?? null);
pin('a hidden listing is a 404', '404 not_found', api_admin_code($call('POST', 'account/wishlist/' . $hidden, null, $sueKey)));
pin('DELETE removes', [204, [$a]], [$call('DELETE', 'account/wishlist/' . $b, null, $sueKey)->status(), $wishlist->all($sue)]);
pin('DELETE of one not there is still 204', 204, $call('DELETE', 'account/wishlist/' . $c, null, $sueKey)->status());
pin('PUT replaces and drops the hidden one', [204, [$c, $b]], [$call('PUT', 'account/wishlist', ['ids' => [$c, $hidden, $b]], $sueKey)->status(), $wishlist->all($sue)]);
pin('PUT over the cap is refused', '422 validation_failed', api_admin_code($call('PUT', 'account/wishlist', ['ids' => range(1, WishlistService::MAX + 1)], $sueKey)));
pin('PUT with a non-integer id is refused', '422 validation_failed', api_admin_code($call('PUT', 'account/wishlist', ['ids' => ['abc']], $sueKey)));
pin('PUT needs ids', '422 validation_failed', api_admin_code($call('PUT', 'account/wishlist', [], $sueKey)));

harness_section('who may do what');
pin('each user sees only their own list', ['ids' => [], 'count' => 0], $call('GET', 'account/wishlist', null, $tomKey)->body()['data'] ?? null);
$call('POST', 'account/wishlist/' . $a, null, $tomKey);
pin('and writes only their own', [[$c, $b], [$a]], [$wishlist->all($sue), $wishlist->all($tom)]);
pin('a read key reads', 200, $call('GET', 'account/wishlist', null, $readKey)->status());
pin('but cannot write', '403 insufficient_scope', api_admin_code($call('POST', 'account/wishlist/' . $a, null, $readKey)));
pin('anonymous is a 401', 401, $call('GET', 'account/wishlist')->status());
pin('anonymous cannot write', 401, $call('POST', 'account/wishlist/' . $a)->status());
$services = new \mindstellar\api\ApiServices(new \mindstellar\apikey\ApiSettings(true), $scopes, new \mindstellar\model\ApiCredential(), new \mindstellar\api\auth\UserRows(), new \mindstellar\utility\SystemClock(), api_test_limiter());
$inProcess = new InternalClient($services->kernel(), static fn () => InternalClient::callerFor($scopes, $sue, null), '192.0.2.71');
pin('the in-process client reads the signed-in user\'s list', [200, [$c, $b]], (static fn (array $r): array => [$r['status'], $r['body']['data']['ids'] ?? null])($inProcess->call('GET', 'account/wishlist')));
pin('but may not write it', [403, 'wrong_credential'], (static fn (array $r): array => [$r['status'], $r['body']['code'] ?? null])($inProcess->call('POST', 'account/wishlist/' . $a)));

harness_section('personal data');
// The test kit does not load functions.php, which registers these on every request.
$wiring = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/functions.php');
check('functions.php adds the export filter and the erase action', str_contains($wiring, "osc_add_filter('personal_data_export', array(\\mindstellar\\listing\\WishlistService::class, 'export'));")
    && str_contains($wiring, "osc_add_hook('delete_user', array(\\mindstellar\\listing\\WishlistService::class, 'erase'));"));
osc_add_filter('personal_data_export', [WishlistService::class, 'export']);
osc_add_hook('delete_user', [WishlistService::class, 'erase']);
$export = \mindstellar\privacy\PersonalData::export($sue);
pin('the export carries the wishlist', [['listing_ids' => [$c, $b]]], $export['data']['wishlist']['rows'] ?? null);
User::getInstance()->deleteUser($sue);
pin('deleting the user removes it', [null, [$a]], [$stored($sue), $wishlist->all($tom)]);

exit(harness_result());
