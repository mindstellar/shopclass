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
 * The saved cart at account/cart: scopes, who may call it, the limits it shares with the
 * quote, dead variants dropped, cart_item_add_after fired once per new variant, the row's
 * expiry, and export and erase with the account.
 * Usage: php tests/api-cart.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\apikey\ApiKeys;
use mindstellar\apikey\CredentialKind;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use mindstellar\billing\CartPricer;
use mindstellar\billing\CartStore;
use mindstellar\listing\VariantStore;
use mindstellar\model\KeyValue;

$admin = api_admin_boot('osc_api_cart');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Tees', seed_category($admin, 'Goods', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
$ann = seed_user($admin, 'ann', 'ann@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'rewriteEnabled' => '0'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

$m      = static fn (float $units): int => (int) round($units * 1000000);
$tee    = seed_item($admin, $cat, $ann, 'Tee', 20.0);
$mug    = seed_item($admin, $cat, $ann, 'Mug', 9.0);
$hid    = seed_item($admin, $cat, $ann, 'Hidden', 5.0, 0);
$teeV   = VariantStore::saveDefault($tee, ['i_stock' => null]);
$mugV   = VariantStore::saveDefault($mug, ['i_stock' => null]);
$hidV   = VariantStore::saveDefault($hid, ['i_stock' => null]);
$kv     = new KeyValue();
$row    = static fn (int $user): ?array => $kv->get(CartStore::GROUP, (string) $user);
$lines  = static fn (array ...$pairs): array => array_map(static fn (array $x): array => ['variant' => $x[0], 'qty' => $x[1]], $pairs);

$scopes = new Scopes();
$keys   = new ApiKeys(new \mindstellar\model\ApiCredential(), $scopes, new \mindstellar\utility\SystemClock());
$key    = static fn (int $user, array $grant): string => $keys->create('key', 'k' . $user . implode('', $grant), $grant, KeyOwner::user($user))->token();
$sueKey = $key($sue, ['cart:write']);
$tomKey = $key($tom, ['cart:write']);
$rdKey  = $key($sue, ['cart:read']);
$call   = api_admin_caller();
$guest  = ['Origin' => 'http://localhost', 'X-Shopclass-Token' => (new \mindstellar\apikey\PageTokens())->issueGuest()->token()];

$fired = [];
osc_add_hook('cart_item_add_after', static function ($cart, $line) use (&$fired): void {
    $fired[] = [$line, $cart['user_id'] ?? null];
});

harness_section('scopes');
foreach ([CredentialKind::KEY => 'a user key', CredentialKind::SESSION => 'a page-token session', CredentialKind::USER => 'an access token'] as $kind => $label) {
    $allowed = $scopes->allowedFor($kind, KeyOwner::user($tom));
    check($label . ' may hold cart:read and cart:write', in_array('cart:read', $allowed, true) && in_array('cart:write', $allowed, true));
}
check('cart:write grants cart:read', Scopes::implies(['cart:write'], 'cart:read'));
check('a public key holds neither', !in_array('cart:read', $scopes->allowedFor(CredentialKind::PUBLIC, KeyOwner::user($tom)), true));

harness_section('who may call');
pin('a guest cannot read', '403 wrong_credential', api_admin_code($call('GET', 'account/cart', null, null, $guest)));
pin('a guest cannot write', '403 wrong_credential', api_admin_code($call('PUT', 'account/cart', ['lines' => []], null, $guest)));
pin('anonymous cannot read', 401, $call('GET', 'account/cart')->status());
pin('a read key cannot write', '403 insufficient_scope', api_admin_code($call('PUT', 'account/cart', ['lines' => []], $rdKey)));

harness_section('save and read back');
$r = $call('GET', 'account/cart', null, $sueKey);
pin('an empty cart', [200, ['lines' => [], 'updated_at' => null]], [$r->status(), $r->body()['data'] ?? null]);
pin('the answer matches the schema', [], api_admin_schema_errors('CartDocument', $r));
pin('PUT is 204', 204, $call('PUT', 'account/cart', ['lines' => $lines([$teeV, 2], [$mugV, 1])], $sueKey)->status());
$r = $call('GET', 'account/cart', null, $sueKey);
pin('GET returns the lines', $lines([$teeV, 2], [$mugV, 1]), $r->body()['data']['lines'] ?? null);
pin('and a timestamp that matches the schema', [], api_admin_schema_errors('CartDocument', $r));
$expires = $row($sue)['expires'] ?? null;
check('the row expires in about 90 days', is_int($expires) && abs($expires - (time() + CartStore::TTL)) < 120);
pin('another user sees an empty cart', [], $call('GET', 'account/cart', null, $tomKey)->body()['data']['lines'] ?? null);
pin('a repeated variant is merged', [204, $lines([$teeV, 5])], [$call('PUT', 'account/cart', ['lines' => $lines([$teeV, 2], [$teeV, 3])], $sueKey)->status(), CartStore::read($sue)['lines']]);
$call('PUT', 'account/cart', ['lines' => $lines([$teeV, 2], [$mugV, 1])], $sueKey);

harness_section('limits');
pin('51 lines is refused', '422 validation_failed', api_admin_code($call('PUT', 'account/cart', ['lines' => array_map(static fn (int $i): array => ['variant' => $i, 'qty' => 1], range(1, CartPricer::MAX_LINES + 1))], $sueKey)));
pin('a quantity of 21 is refused', '422 validation_failed', api_admin_code($call('PUT', 'account/cart', ['lines' => $lines([$teeV, CartPricer::MAX_QTY + 1])], $sueKey)));
pin('a quantity of 0 is refused', '422 validation_failed', api_admin_code($call('PUT', 'account/cart', ['lines' => $lines([$teeV, 0])], $sueKey)));
pin('a price in the body is refused', '422 validation_failed', api_admin_code($call('PUT', 'account/cart', ['lines' => [['variant' => $teeV, 'qty' => 1, 'amount' => 1]]], $sueKey)));
pin('lines are needed', '422 validation_failed', api_admin_code($call('PUT', 'account/cart', [], $sueKey)));
pin('the refused writes left the cart alone', $lines([$teeV, 2], [$mugV, 1]), CartStore::read($sue)['lines']);

harness_section('dead variants');
$call('PUT', 'account/cart', ['lines' => $lines([$teeV, 1], [$hidV, 1], [999999, 1])], $sueKey);
pin('a hidden and an unknown variant are dropped, the rest kept', $lines([$teeV, 1]), $call('GET', 'account/cart', null, $sueKey)->body()['data']['lines'] ?? null);

harness_section('cart_item_add_after');
$call('PUT', 'account/cart', ['lines' => []], $sueKey);
$fired = [];
$call('PUT', 'account/cart', ['lines' => $lines([$teeV, 2])], $sueKey);
pin('fires once for a new variant with its title and micros', [1, 'item.' . $teeV, 'Tee', $m(40), 'USD', ['variant' => $teeV, 'qty' => 2, 'item_id' => $tee], $sue], [count($fired), $fired[0][0]['id'] ?? null, $fired[0][0]['label'] ?? null, $fired[0][0]['amount'] ?? null, $fired[0][0]['currency'] ?? null, $fired[0][0]['meta'] ?? null, $fired[0][1] ?? null]);
$fired = [];
$call('PUT', 'account/cart', ['lines' => $lines([$teeV, 3])], $sueKey);
pin('not for a kept line', 0, count($fired));
$call('PUT', 'account/cart', ['lines' => $lines([$teeV, 3], [$mugV, 1])], $sueKey);
pin('only for the added one', ['item.' . $mugV], array_map(static fn (array $f): string => $f[0]['id'], $fired));
$fired = [];
$call('PUT', 'account/cart', ['lines' => $lines([$teeV, 3])], $sueKey);
pin('not for a removed line', 0, count($fired));
$fired = [];
$call('PUT', 'account/cart', ['lines' => $lines([$hidV, 1])], $sueKey);
pin('not for a dropped variant', 0, count($fired));

harness_section('If-Match');
$call('PUT', 'account/cart', ['lines' => $lines([$teeV, 1])], $sueKey);
$etag = $call('GET', 'account/cart', null, $sueKey)->prepare('GET')['headers']['ETag'] ?? null;
check('a read carries an ETag', is_string($etag) && $etag !== '');
pin('the current ETag lets a write through', 204, $call('PUT', 'account/cart', ['lines' => $lines([$teeV, 2])], $sueKey, ['If-Match' => $etag])->status());
pin('the old one is refused and changes nothing', [412, $lines([$teeV, 2])], (static function () use ($call, $sueKey, $etag, $lines, $teeV, $sue): array {
    $r = $call('PUT', 'account/cart', ['lines' => $lines([$teeV, 9])], $sueKey, ['If-Match' => $etag]);

    return [$r->status(), CartStore::read($sue)['lines']];
})());

harness_section('empty and erase');
$call('PUT', 'account/cart', ['lines' => $lines([$teeV, 1])], $sueKey);
check('the row exists', $row($sue) !== null);
$call('PUT', 'account/cart', ['lines' => []], $sueKey);
pin('an empty PUT deletes the row', null, $row($sue));
$call('PUT', 'account/cart', ['lines' => $lines([$teeV, 1])], $sueKey);
$call('PUT', 'account/cart', ['lines' => $lines([$mugV, 1])], $tomKey);

$wiring = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/functions.php');
check('functions.php adds the export filter and the erase action', str_contains($wiring, "osc_add_filter('personal_data_export', array(\\mindstellar\\billing\\CartStore::class, 'export'));")
    && str_contains($wiring, "osc_add_hook('delete_user', array(\\mindstellar\\billing\\CartStore::class, 'erase'));"));
osc_add_filter('personal_data_export', [CartStore::class, 'export']);
osc_add_hook('delete_user', [CartStore::class, 'erase']);

$export = \mindstellar\privacy\PersonalData::export($sue);
pin('the export carries the cart', [['lines' => $lines([$teeV, 1])]], $export['data']['saved_cart']['rows'] ?? null);
User::getInstance()->deleteUser($sue);
pin('deleting the user removes the row, not another user\'s', [null, $lines([$mugV, 1])], [$row($sue), CartStore::read($tom)['lines']]);

exit(harness_result());
