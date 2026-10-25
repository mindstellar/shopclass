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
 * `POST /listings/{id}/publish` end to end through Kernel::handle(): credits, an order for the
 * shortfall, who may call it, replays and the hourly order cap.
 * Usage: php tests/models/api-listing-publish.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\Response;
use mindstellar\apikey\ApiKeys;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use mindstellar\billing\Billing;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PackageStore;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Wallet;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

// The listing writes controller names the photo temp folder; this file stores no photo.
if (!function_exists('osc_content_path')) {
    function osc_content_path()
    {
        return sys_get_temp_dir() . '/osc-api-publish/';
    }
}
$admin = api_admin_boot('osc_models_api_listing_publish');
require_once __DIR__ . '/../lib/listing-order-gateway.php';

/* ----------------------------------------------------------------------------
 * Fixture: billing on, slot sales on (3 credits a slot, one listing each, one free), two
 * USD packages, the test gateway, and sellers with one live and one held listing each.
 * ------------------------------------------------------------------------- */
$p = DB_TABLE_PREFIX;
seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Cars', null, 'en_US', 1, 30);
foreach (['enabled_users' => '1', 'moderate_items' => '-1', 'items_wait_time' => '0', 'language' => 'en_US'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
foreach ([Billing::PREF_ENABLED => 'BOOLEAN', 'billing_slot_enabled' => 'BOOLEAN'] as $k => $t) {
    osc_set_preference($k, '1', $k === Billing::PREF_ENABLED ? Billing::PREF_GROUP : 'osclass', $t);
}
osc_set_preference('billing_free_live_listings', '1', 'osclass', 'INTEGER');
osc_set_preference('billing_slot_credits', '3', 'osclass', 'INTEGER');
osc_set_preference('billing_slot_quantity', '1', 'osclass', 'INTEGER');
scratchdb_forget_cache();
osc_reset_preferences();
osc_register_billing_slot();
$_SERVER['REMOTE_ADDR'] = '192.0.2.70';

PaymentGatewayRegistry::getInstance()->register(new ListingOrderGateway('lcgw'));
$five = (int) PackageStore::create(['s_name' => 'Five', 'i_amount' => 2_000_000, 's_currency' => 'USD', 'i_credits' => 5]);
$two  = (int) PackageStore::create(['s_name' => 'Two', 'i_amount' => 900_000, 's_currency' => 'USD', 'i_credits' => 2]);

$keys    = new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock());
$userKey = static fn (int $uid, array $scopes = ['listings:read', 'listings:write', 'orders:write']): string => $keys->create('key', 'k' . $uid . count($scopes), $scopes, KeyOwner::user($uid))->token();
$seq     = 0;
$seller  = static function (int $credits) use ($admin, $p, $cat, $userKey, &$seq): array {
    $seq++;
    $uid  = seed_user($admin, 'tom' . $seq, 'tom' . $seq . '@example.test');
    $live = seed_item($admin, $cat, $uid, 'Live ' . $seq);
    $held = seed_item($admin, $cat, $uid, 'Held ' . $seq);
    $admin->query("UPDATE {$p}t_item SET b_enabled = 0, b_active = 0, dt_first_pub_date = NULL, dt_expiration = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE pk_i_id = {$held}");
    if ($credits > 0) {
        Wallet::credit($uid, $credits, Wallet::REASON_GRANT);
    }

    return [$uid, $userKey($uid), $live, $held];
};
$debits = static fn (int $uid): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_ledger WHERE fk_i_user_id = {$uid} AND s_ref_type = 'item'")->fetch_row()[0];
$orders = static fn (int $uid): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_order WHERE fk_i_user_id = {$uid}")->fetch_row()[0];
$firstPub = static fn (int $id): ?string => $admin->query("SELECT dt_first_pub_date FROM {$p}t_item WHERE pk_i_id = {$id}")->fetch_row()[0];

// The checkout bucket's hits, and whether it reads as full.
$hits = [];
$full = false;
$call = api_admin_caller(null, static function () use (&$hits, &$full): RateLimiter {
    return new RateLimiter(
        static function (string $bucket) use (&$hits): int {
            $hits[] = $bucket;

            return 1;
        },
        new SystemClock(),
        read: static fn (string $bucket): int => $bucket === 'api_checkout' && $full ? 1000 : 0
    );
});
$posted = 0;
osc_add_hook('posted_item', static function () use (&$posted): void {
    $posted++;
});
$code       = static fn (Response $r): string => api_admin_code($r);
$schemaErrs = static fn (Response $r): array => api_admin_schema_errors('ListingPublished', $r);
$checkouts  = static function () use (&$hits): int {
    return count(array_filter($hits, static fn (string $b): bool => $b === 'api_checkout'));
};

harness_section('publish with credits');

[$tom, $tomKey, $tomLive, $tomHeld] = $seller(5);
$r = $call('POST', 'listings/' . $tomHeld . '/publish', [], $tomKey);
pin('200, the listing is live, no payment member', [200, 'active', false], [$r->status(), $r->body()['data']['status'] ?? null, isset($r->body()['payment'])]);
pin('three credits spent in one ledger row; posted_item once', [2, 1, 1], [Wallet::balance($tom), $debits($tom), $posted]);
pin('the credits answer matches ListingPublished', [], $schemaErrs($r));

$r = $call('POST', 'listings/' . $tomHeld . '/publish', null, $tomKey);
pin('again without a key: 200 as it is, nothing spent, no warnings', [200, 'active', 2, 1, 1, false], [$r->status(), $r->body()['data']['status'] ?? null, Wallet::balance($tom), $debits($tom), $posted, isset($r->body()['warnings'])]);

harness_section('a replay with the same Idempotency-Key');

[$ida, $idaKey, , $idaHeld] = $seller(5);
$first = $call('POST', 'listings/' . $idaHeld . '/publish', [], $idaKey, ['Idempotency-Key' => 'pub-1']);
$again = $call('POST', 'listings/' . $idaHeld . '/publish', [], $idaKey, ['Idempotency-Key' => 'pub-1']);
pin('the second answer is the first, replayed; one debit', [200, null, 'true', true, 1], [$again->status(), $first->header('Idempotency-Replayed'), $again->header('Idempotency-Replayed'), $first->body() === $again->body(), $debits($ida)]);

harness_section('who may call it');

[$sue, $sueKey, , $sueHeld] = $seller(5);
[$ann, $annKey, $annLive, $annHeld] = $seller(5);
pin('another user on a held listing: 404', '404 not_found', $code($call('POST', 'listings/' . $annHeld . '/publish', [], $sueKey)));
pin('another user on a live listing: 403 not_owner', '403 not_owner', $code($call('POST', 'listings/' . $annLive . '/publish', [], $sueKey)));
pin('no credential: 401', 401, $call('POST', 'listings/' . $annHeld . '/publish', [])->status());
pin('a read-only key: 403 insufficient_scope', '403 insufficient_scope', $code($call('POST', 'listings/' . $annHeld . '/publish', [], $userKey($ann, ['listings:read']))));
$writeOnly = $userKey($ann, ['listings:write']);
$r         = $call('POST', 'listings/' . $annHeld . '/publish', [], $writeOnly);
pin('listings:write alone may not spend credits: it needs orders:write', ['403 insufficient_scope', true], [$code($r), str_contains((string) $r->header('WWW-Authenticate'), 'orders:write')]);
pin('nor start an order', '403 insufficient_scope', $code($call('POST', 'listings/' . $annHeld . '/publish', ['package' => $five, 'gateway' => 'lcgw'], $writeOnly)));
$bossId = api_admin_seed_admin($admin, 'boss');
pin('an admin key: 403 wrong_credential', '403 wrong_credential', $code($call('POST', 'listings/' . $annHeld . '/publish', [], api_admin_key($bossId))));
pin('an unknown listing: 404', '404 not_found', $code($call('POST', 'listings/999999/publish', [], $annKey)));
pin('the held listings are still held and unpaid for', [null, null, 5, 5, 0, 0], [$firstPub($annHeld), $firstPub($sueHeld), Wallet::balance($ann), Wallet::balance($sue), $debits($ann), $debits($sue)]);

harness_section('not enough credits');

[$bob, $bobKey, , $bobHeld] = $seller(0);
$r    = $call('POST', 'listings/' . $bobHeld . '/publish', [], $bobKey);
$pay  = $r->body()['payment'] ?? [];
$url  = (string) ($pay['publish_url'] ?? '');
pin('422 insufficient_credits', '422 insufficient_credits', $code($r));
pin('payment says the price, the balance and the shortfall', [3, 0, 3], [$pay['credits'] ?? null, $pay['balance'] ?? null, $pay['shortfall'] ?? null]);
pin('and the web pay step', true, osc_billing_publish_url($bobHeld) === $url && $url !== '');
pin('the detail names both numbers', 'This listing needs 3 credits; you have 0.', $r->body()['detail'] ?? null);
pin('the row stays held, no ledger row', [null, 0], [$firstPub($bobHeld), $debits($bob)]);

harness_section('start an order for the shortfall');

$fieldErr = static fn (Response $r): string => $r->status() . ' ' . ($r->body()['errors'][0]['pointer'] ?? '') . ' ' . ($r->body()['errors'][0]['code'] ?? '');
pin('a package short of the shortfall: /package minimum', '422 /package minimum', $fieldErr($call('POST', 'listings/' . $bobHeld . '/publish', ['package' => $two, 'gateway' => 'lcgw'], $bobKey)));
pin('an unknown gateway: /gateway unavailable', '422 /gateway unavailable', $fieldErr($call('POST', 'listings/' . $bobHeld . '/publish', ['package' => $five, 'gateway' => 'nope'], $bobKey)));
pin('a package alone: /gateway required', '422 /gateway required', $fieldErr($call('POST', 'listings/' . $bobHeld . '/publish', ['package' => $five], $bobKey)));
pin('a gateway alone: /package required', '422 /package required', $fieldErr($call('POST', 'listings/' . $bobHeld . '/publish', ['gateway' => 'lcgw'], $bobKey)));
pin('an amount cannot be sent', '422 validation_failed', $code($call('POST', 'listings/' . $bobHeld . '/publish', ['package' => $five, 'gateway' => 'lcgw', 'amount' => 1], $bobKey)));
pin('none of those wrote an order or counted against the hour', [0, 0], [$orders($bob), $checkouts()]);

$r     = $call('POST', 'listings/' . $bobHeld . '/publish', ['package' => $five, 'gateway' => 'lcgw'], $bobKey);
$body  = $r->body();
$order = $body['order'] ?? [];
pin('200, the listing still waits, listing_unpaid', [200, 'unpaid', ['listing_unpaid']], [$r->status(), $body['data']['status'] ?? null, array_column($body['warnings'] ?? [], 'code')]);
pin('payment still shows the shortfall', 3, $body['payment']['shortfall'] ?? null);
pin('the order is for credits, named after the listing, at the package price', ['credits', 'Listing: Held ' . $seq, '2.00'], [$order['kind'] ?? null, $order['label'] ?? null, $order['total']['amount'] ?? null]);
pin('next sends the buyer to the gateway', ['redirect', 'https://example.test/pay/' . ($order['id'] ?? 0)], [$body['next']['kind'] ?? null, $body['next']['payload'] ?? null]);
pin('the listing is linked to that order', $order['id'] ?? -1, OrderStore::listingOrder($bobHeld)?->getId());
pin('the hourly order bucket was counted once', 1, $checkouts());
pin('the order answer matches ListingPublished', [], $schemaErrs($r));

Billing::markPaid(OrderStore::find((int) $order['id']));
$r = $call('GET', 'listings/' . $bobHeld, null, $bobKey);
pin('paid: the listing is live and two credits are left', ['active', 2], [$r->body()['data']['status'] ?? null, Wallet::balance($bob)]);

harness_section('the hourly order cap');

[$cy, $cyKey, , $cyHeld] = $seller(0);
$full = true;
pin('a full bucket refuses the order path', '429 rate_limited', $code($call('POST', 'listings/' . $cyHeld . '/publish', ['package' => $five, 'gateway' => 'lcgw'], $cyKey)));
pin('and writes no order', 0, $orders($cy));
Wallet::credit($cy, 3, Wallet::REASON_GRANT);
pin('but not the credits path', [200, 'active'], [($r = $call('POST', 'listings/' . $cyHeld . '/publish', [], $cyKey))->status(), $r->body()['data']['status'] ?? null]);
$full = false;

harness_section('a plugin refuses it');

[$di, $diKey, , $diHeld] = $seller(5);
$veto = static fn (): bool => false;
osc_add_filter('billing_can_publish', $veto);
$r = $call('POST', 'listings/' . $diHeld . '/publish', [], $diKey);
osc_remove_filter('billing_can_publish', $veto);
pin('422 validation_failed rejected', '422 validation_failed rejected', $code($r) . ' ' . ($r->body()['errors'][0]['code'] ?? ''));
pin('nothing spent, still held', [5, 0, null], [Wallet::balance($di), $debits($di), $firstPub($diHeld)]);

harness_section('the admin mail cannot be written');

[$eve, $eveKey, , $eveHeld] = $seller(5);
// As CoreEmails::adminNewItem() does when its e-mail page has no text.
$broken = static function (): void {
    throw new TypeError('current(): Argument #1 ($array) must be of type array, null given');
};
osc_add_hook('hook_email_admin_new_item', $broken);
osc_set_preference('notify_new_item', '1', 'osclass', 'BOOLEAN');
osc_reset_preferences();
$r = $call('POST', 'listings/' . $eveHeld . '/publish', [], $eveKey);
osc_set_preference('notify_new_item', '0', 'osclass', 'BOOLEAN');
osc_reset_preferences();
osc_remove_hook('hook_email_admin_new_item', $broken);
pin('the publish still commits: 200, live, credits spent', [200, 'active', 2], [$r->status(), $r->body()['data']['status'] ?? null, Wallet::balance($eve)]);

harness_section('a shop has no pay step');

[$fay, $fayKey, , $fayHeld] = $seller(5);
osc_set_preference('site_mode', 'shop');
osc_reset_preferences();
$r = $call('POST', 'listings/' . $fayHeld . '/publish', [], $fayKey);
pin('shop mode: 404 not_found, nothing spent, still held', ['404 not_found', 5, null], [$code($r), Wallet::balance($fay), $firstPub($fayHeld)]);
osc_set_preference('site_mode', 'classifieds');
osc_reset_preferences();
pin('back to classifieds: 200 and live', [200, 'active'], [($r = $call('POST', 'listings/' . $fayHeld . '/publish', [], $fayKey))->status(), $r->body()['data']['status'] ?? null]);

exit(harness_result());
