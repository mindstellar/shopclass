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
 * The cart quote and the account's orders over the API: a guest may price a cart but not place
 * one, a user places, lists, reads and cancels only their own, nothing in a body reaches a price,
 * a repeated Idempotency-Key places once, and placements are rate limited.
 * Usage: php tests/api-orders.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\apikey\ApiKeys;
use mindstellar\apikey\CredentialKind;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\listing\VariantStore;

$admin = api_admin_boot('osc_api_orders');
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

final class OrdersFakeGateway implements PaymentGateway
{
    public int $calls = 0;

    public function getId(): string
    {
        return 'fake';
    }

    public function getName(): string
    {
        return 'Fake pay';
    }

    public function getSupportedCurrencies(): array
    {
        return ['USD'];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createCheckout(Order $order): CheckoutIntent
    {
        $this->calls++;

        return CheckoutIntent::redirect('https://pay.example.test/' . $order->getId());
    }

    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored('none');
    }
}
$gateway = new OrdersFakeGateway();
PaymentGatewayRegistry::getInstance()->register($gateway);

$m     = static fn (float $units): int => (int) round($units * 1000000);
$tee   = seed_item($admin, $cat, $sue, 'Tee', 699.0);
$mug   = seed_item($admin, $cat, $sue, 'Mug', 9.0);
$photo = seed_photo($admin, $tee);
$row   = static fn (string $size, ?int $stock, ?int $price): array => ['options' => ['Size' => $size], 'fields' => ['i_stock' => $stock, 'i_price' => $price, 's_sku' => 'TEE-' . $size]];
[$small, $medium, $large] = VariantStore::saveSet($tee, [$row('S', 0, $m(699)), $row('M', 3, $m(699)), $row('L', null, $m(799))]);
$mugV = VariantStore::saveDefault($mug, ['i_stock' => null]);

$scopes = new Scopes();
$keys   = new ApiKeys(new \mindstellar\model\ApiCredential(), $scopes, new \mindstellar\utility\SystemClock());
$key    = static fn (int $user, array $grant): string => $keys->create('key', 'k' . $user . implode('', $grant), $grant, KeyOwner::user($user))->token();
$tomKey = $key($tom, ['orders:write']);
$annKey = $key($ann, ['orders:write']);
$reader = $key($tom, ['orders:read']);
$call   = api_admin_caller();
$guest  = ['Origin' => 'http://localhost', 'X-Shopclass-Token' => (new \mindstellar\apikey\PageTokens())->issueGuest()->token()];
$flat   = $m(49);
Preference::getInstance()->set('billing_shipping_flat', (string) $flat);
Preference::getInstance()->set('billing_free_over', (string) $m(5000));
scratchdb_forget_cache();
osc_reset_preferences();

$address = ['name' => 'Tom Lee', 'phone' => '5550100', 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '73301', 'country' => 'US'];
$body    = static fn (array $lines, array $extra = []): array => $extra + ['lines' => $lines, 'address' => $address, 'shipping_method' => 'standard', 'gateway' => 'fake'];
$orders  = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_order")->fetch_row()[0];

harness_section('scopes');
foreach ([CredentialKind::KEY => 'a user key', CredentialKind::SESSION => 'a page-token session', CredentialKind::USER => 'an access token'] as $kind => $label) {
    $allowed = $scopes->allowedFor($kind, KeyOwner::user($tom));
    check($label . ' may hold orders:read and orders:write', in_array('orders:read', $allowed, true) && in_array('orders:write', $allowed, true));
}
check('orders:write grants orders:read', Scopes::implies(['orders:write'], 'orders:read'));
check('a public key holds neither', !in_array('orders:read', $scopes->allowedFor(CredentialKind::PUBLIC, KeyOwner::user($tom)), true));

harness_section('the quote');
$r = $call('GET', 'cart/quote', null, null, $guest, ['lines' => $medium . ':2']);
pin('a guest page token may price a cart', 200, $r->status());
pin('the answer matches the schema', [], api_admin_schema_errors('QuoteDocument', $r));
$q = $r->body()['data'];
pin('unit, amount and totals come from the database', ['699.00', '1398.00', '49.00', '1447.00', 'USD'], [$q['lines'][0]['unit']['amount'], $q['lines'][0]['amount']['amount'], $q['totals']['shipping']['amount'], $q['totals']['total']['amount'], $q['currency']]);
pin('the line names its listing, options and photo', [$tee, 'Tee', 'http://localhost/item/' . $tee, ['Size' => 'M'], $photo, 3], [$q['lines'][0]['listing']['id'], $q['lines'][0]['listing']['title'], $q['lines'][0]['listing']['url'], $q['lines'][0]['options'], $q['lines'][0]['listing']['photo']['id'], $q['lines'][0]['stock_left']]);
pin('standard delivery is on offer and chosen', [[['id' => 'standard', 'label' => 'Standard delivery']], ['method' => 'standard', 'amount' => $q['totals']['shipping']]], [array_map(static fn ($x) => ['id' => $x['id'], 'label' => $x['label']], $q['shipping_methods']), $q['shipping']]);
pin('the free-delivery meter', ['5000.00', '3602.00'], [$q['free_over']['amount']['amount'], $q['free_over']['remaining']['amount']]);
pin('the payment methods on offer', [['id' => 'fake', 'name' => 'Fake pay', 'fee' => null]], $q['gateways']);
$big = $call('GET', 'cart/quote', null, null, $guest, ['lines' => $large . ':1'])->body()['data'];
pin('a variant\'s own price beats the listing price', ['799.00', '799.00', '848.00'], [$big['lines'][0]['unit']['amount'], $big['lines'][0]['amount']['amount'], $big['totals']['total']['amount']]);
check('a quote is not cached', str_contains((string) $r->header('Cache-Control'), 'no-store'));

$fee = static function ($totals, $cart) use ($m) {
    if (($cart['gateway'] ?? null) === 'fake') {
        $totals['fees'][] = ['id' => 'fee.fake', 'label' => 'Handling', 'amount' => $m(5)];
    }

    return $totals;
};
osc_add_filter('cart_totals', $fee);
$r = $call('GET', 'cart/quote', null, null, $guest, ['lines' => $mugV . ':1', 'gateway' => 'fake']);
$q = $r->body()['data'];
pin('a gateway\'s fee is in the totals and shown on the gateway', ['5.00', '63.00', '5.00'], [$q['totals']['fee']['amount'], $q['totals']['total']['amount'], $q['gateways'][0]['fee']['amount']]);
$q = $call('GET', 'cart/quote', null, null, $guest, ['lines' => $mugV . ':1'])->body()['data'];
pin('without it the totals leave the fee out but the gateway still shows it', ['0.00', '5.00'], [$q['totals']['fee']['amount'], $q['gateways'][0]['fee']['amount']]);
osc_remove_filter('cart_totals', $fee);

$q = $call('GET', 'cart/quote', null, null, $guest, ['lines' => $small . ':1,' . $medium . ':9,999999:1,' . $mugV . ':30'])->body()['data'];
pin('an out of stock, a short, an unknown and an over-limit line are problems, not errors', [['line' => 0, 'code' => 'out_of_stock'], ['line' => 1, 'code' => 'out_of_stock'], ['line' => 2, 'code' => 'not_buyable'], ['line' => 3, 'code' => 'qty']], $q['problems']);
pin('a line short of stock is marked and left out of the subtotal', [false, 0, '180.00'], [$q['lines'][0]['available'], $q['lines'][0]['stock_left'], $q['totals']['subtotal']['amount']]);
pin('the quantity is capped at 20', 20, $q['lines'][2]['qty']);
pin('an empty cart is a quote of nothing', [200, []], (static fn ($r) => [$r->status(), $r->body()['data']['lines']])($call('GET', 'cart/quote', null, null, $guest)));
pin('more than 50 lines is a 422', '422 validation_failed', api_admin_code($call('GET', 'cart/quote', null, null, $guest, ['lines' => implode(',', array_fill(0, 51, '1:1'))])));
pin('a malformed lines value is a 422', '422 validation_failed', api_admin_code($call('GET', 'cart/quote', null, null, $guest, ['lines' => '1-2;3'])));
pin('a price in the query is not a parameter', '422 validation_failed', api_admin_code($call('GET', 'cart/quote', null, null, $guest, ['lines' => $mugV . ':1', 'price' => '1'])));

harness_section('placing an order');
pin('anonymous cannot place', 401, $call('POST', 'account/orders', $body([['variant' => $mugV, 'qty' => 1]]))->status());
pin('nor can a guest page token', '403 wrong_credential', api_admin_code($call('POST', 'account/orders', $body([['variant' => $mugV, 'qty' => 1]]), null, $guest)));
pin('a read-only key cannot place', '403 insufficient_scope', api_admin_code($call('POST', 'account/orders', $body([['variant' => $mugV, 'qty' => 1]]), $reader)));
$before = $orders();
$r      = $call('POST', 'account/orders', $body([['variant' => $medium, 'qty' => 2, 'price' => 1, 'unit' => '0.01', 'amount' => 1], ['variant' => $mugV, 'qty' => 1]], ['total' => 1, 'note' => 'Leave at the door', 'price' => 1]), $tomKey);
pin('a key with orders:write places: 201', 201, $r->status());
pin('the answer matches the schema', [], api_admin_schema_errors('OrderPlaced', $r));
$o = $r->body()['data'];
pin('the total is the server\'s whatever the body said', ['1456.00', 'pending', true, 'goods'], [$o['total']['amount'], $o['status'], $o['can_cancel'], $o['kind']]);
pin('the lines: goods then shipping, with the server\'s prices', [['item', '699.00', '1398.00', 2], ['item', '9.00', '9.00', 1], ['shipping', '49.00', '49.00', 1]], array_map(static fn ($l) => [$l['kind'], $l['unit']['amount'], $l['amount']['amount'], $l['qty']], $o['lines']));
pin('the stored order matches', [$tom, $m(1456), 'Leave at the door', 'Tom Lee'], (static function () use ($o): array {
    $order = OrderStore::find($o['id']);

    return [$order->getUserId(), $order->getAmount(), $order->meta('_note'), $order->meta('_address')['name']];
})());
pin('the listing is linked on the item lines', [$tee, 'http://localhost/item/' . $tee, $photo, null], [$o['lines'][0]['listing']['id'], $o['lines'][0]['listing']['url'], $o['lines'][0]['listing']['photo']['id'], $o['lines'][2]['listing']]);
pin('options and sku read back', [['Size' => 'M'], 'TEE-M'], [$o['lines'][0]['options'], $o['lines'][0]['sku']]);
pin('next sends the buyer to the gateway', ['redirect', 'https://pay.example.test/' . $o['id']], [$r->body()['next']['kind'], $r->body()['next']['payload']]);
pin('Location names the order', 'http://localhost/api/v1/account/orders/' . $o['id'], $r->header('Location'));
pin('the order number and gateway', ['#' . $o['id'], ['id' => 'fake', 'name' => 'Fake pay'], null], [$o['number'], $o['gateway'], $o['receipt_url']]);
pin('the order shipping block carries the address', ['standard', 'Austin', null], [$o['shipping']['method'], $o['shipping']['address']['city'], $o['shipping']['tracking']]);

$mark = $orders();
$bad  = static fn (array $b): string => api_admin_code($call('POST', 'account/orders', $b, $tomKey));
pin('stock short: 422', '422 validation_failed', $bad($body([['variant' => $medium, 'qty' => 4]])));
$r = $call('POST', 'account/orders', $body([['variant' => $mugV, 'qty' => 1], ['variant' => $medium, 'qty' => 4]]), $tomKey);
pin('with the line\'s path', [['pointer' => '/lines/1', 'code' => 'out_of_stock']], array_map(static fn ($e) => ['pointer' => $e['pointer'], 'code' => $e['code']], $r->body()['errors'] ?? []));
$r = $call('POST', 'account/orders', $body([['variant' => 999999, 'qty' => 1]]), $tomKey);
pin('an unknown variant is not_buyable on its line', '/lines/0 not_buyable', ($r->body()['errors'][0]['pointer'] ?? '') . ' ' . ($r->body()['errors'][0]['code'] ?? ''));
$r = $call('POST', 'account/orders', $body([['variant' => $mugV, 'qty' => 1]], ['gateway' => 'nope']), $tomKey);
pin('an unavailable gateway is /gateway', '/gateway unavailable', ($r->body()['errors'][0]['pointer'] ?? '') . ' ' . ($r->body()['errors'][0]['code'] ?? ''));
$r = $call('POST', 'account/orders', $body([['variant' => $mugV, 'qty' => 1]], ['address' => array_diff_key($address, ['postcode' => 1])]), $tomKey);
pin('a missing address field is /address/postcode', '422 /address/postcode', $r->status() . ' ' . ($r->body()['errors'][0]['pointer'] ?? ''));
$r = $call('POST', 'account/orders', $body([['variant' => $mugV, 'qty' => 1]], ['address' => ['postcode' => ' '] + $address]), $tomKey);
pin('a blank one is /address/postcode too', '/address/postcode required', ($r->body()['errors'][0]['pointer'] ?? '') . ' ' . ($r->body()['errors'][0]['code'] ?? ''));
pin('an empty cart is refused', '422 validation_failed', $bad($body([])));
pin('a quantity of 21 is refused', '422 validation_failed', $bad($body([['variant' => $mugV, 'qty' => 21]])));
pin('an unknown delivery method is refused', '422 validation_failed', $bad($body([['variant' => $mugV, 'qty' => 1]], ['shipping_method' => 'teleport'])));
pin('none of the refusals wrote an order', $mark, $orders());

harness_section('the same key places once');
$key1  = ['Idempotency-Key' => 'order-key-1'];
$first = $call('POST', 'account/orders', $body([['variant' => $mugV, 'qty' => 1]]), $tomKey, $key1);
$calls = $gateway->calls;
$again = $call('POST', 'account/orders', $body([['variant' => $mugV, 'qty' => 1]]), $tomKey, $key1);
pin('a retry gets the same order back', [201, 'true', $first->body()['data']['id']], [$again->status(), $again->header('Idempotency-Replayed'), $again->body()['data']['id']]);
pin('and the gateway is not asked again', $calls, $gateway->calls);
pin('one order, not two', $mark + 1, $orders());
pin('the same key with another cart is refused', '422 idempotency_key_reused', api_admin_code($call('POST', 'account/orders', $body([['variant' => $mugV, 'qty' => 2]]), $tomKey, $key1)));

harness_section('reading and cancelling');
$list = $call('GET', 'account/orders', null, $tomKey);
pin('the list is the buyer\'s, newest first', [$first->body()['data']['id'], $o['id']], array_slice(array_column($list->body()['data'], 'id'), 0, 2));
pin('and matches the schema', [], api_admin_schema_errors('OrderPage', $list));
$page = $call('GET', 'account/orders', null, $tomKey, [], ['limit' => 1]);
pin('a page of one has a next link', [1, true], [count($page->body()['data']), $page->body()['links']['next'] !== null]);
$cursor = parse_url((string) $page->body()['links']['next'], PHP_URL_QUERY);
parse_str((string) $cursor, $q2);
$page2 = $call('GET', 'account/orders', null, $tomKey, [], $q2);
pin('and the next page continues', $o['id'], $page2->body()['data'][0]['id']);
pin('a reader key lists', 200, $call('GET', 'account/orders', null, $reader)->status());
pin('another user sees none of them', [], $call('GET', 'account/orders', null, $annKey)->body()['data']);
pin('anonymous cannot list', 401, $call('GET', 'account/orders')->status());
$one = $call('GET', 'account/orders/' . $o['id'], null, $tomKey);
pin('one order', [200, $o['id'], []], [$one->status(), $one->body()['data']['id'], api_admin_schema_errors('OrderDocument', $one)]);
pin('another user\'s order is a 404', '404 not_found', api_admin_code($call('GET', 'account/orders/' . $o['id'], null, $annKey)));
pin('so is one that does not exist', '404 not_found', api_admin_code($call('GET', 'account/orders/999999', null, $tomKey)));
pin('another user cannot cancel it', ['404 not_found', 'pending'], [api_admin_code($call('POST', 'account/orders/' . $o['id'] . '/cancel', null, $annKey)), OrderStore::find($o['id'])->getStatus()]);
pin('a read-only key cannot cancel', '403 insufficient_scope', api_admin_code($call('POST', 'account/orders/' . $o['id'] . '/cancel', null, $reader)));
$r = $call('POST', 'account/orders/' . $o['id'] . '/cancel', null, $tomKey);
pin('the owner cancels a pending order', [200, 'cancelled', false, []], [$r->status(), $r->body()['data']['status'], $r->body()['data']['can_cancel'], api_admin_schema_errors('OrderDocument', $r)]);
pin('twice is a 409', '409 conflict', api_admin_code($call('POST', 'account/orders/' . $o['id'] . '/cancel', null, $tomKey)));

$paidId = $first->body()['data']['id'];
OrderStore::settle($paidId, Order::STATUS_PAID, 'ref-1');
pin('a paid order cannot be cancelled', ['409 conflict', 'paid'], [api_admin_code($call('POST', 'account/orders/' . $paidId . '/cancel', null, $tomKey)), OrderStore::find($paidId)->getStatus()]);
$show = $call('GET', 'account/orders/' . $paidId, null, $tomKey)->body()['data'];
pin('it shows paid, with a receipt and no cancel', ['paid', true, false], [$show['status'], str_contains((string) $show['receipt_url'], 'billing_receipt'), $show['can_cancel']]);
OrderStore::ship($paidId, 'DHL', 'JD0001', 'http://insecure.example.test/t');
$show = $call('GET', 'account/orders/' . $paidId, null, $tomKey)->body()['data'];
pin('shipped: carrier and tracking, an http tracking link is withheld', ['shipped', 'DHL', 'JD0001', null, true], [$show['status'], $show['shipping']['carrier'], $show['shipping']['tracking'], $show['shipping']['tracking_url'], $show['shipping']['shipped_at'] !== null]);
pin('and it still matches the schema', [], api_admin_schema_errors('OrderDocument', $call('GET', 'account/orders/' . $paidId, null, $tomKey)));

harness_section('a variant price');
$rL = $call('POST', 'account/orders', $body([['variant' => $large, 'qty' => 1, 'unit' => '1.00']]), $tomKey);
$oL = $rL->body()['data'];
pin('an order for a pricier variant is charged its own price', [201, '848.00', '799.00', $m(848)], [$rL->status(), $oL['total']['amount'], $oL['lines'][0]['unit']['amount'], OrderStore::find($oL['id'])->getAmount()]);
OrderStore::cancel($oL['id']);

harness_section('listings that are gone, and credit orders');
$admin->query("UPDATE {$p}t_item SET b_active = 0 WHERE pk_i_id = $mug");
$show = $call('GET', 'account/orders/' . $paidId, null, $tomKey)->body()['data'];
pin('a listing no longer live leaves the line with no link', [null, 'Mug'], [$show['lines'][0]['listing'], $show['lines'][0]['title']]);
$admin->query("UPDATE {$p}t_item SET b_active = 1 WHERE pk_i_id = $mug");
$credits = OrderStore::create($tom, 'fake', $m(10), 'USD', 100, []);
$list    = $call('GET', 'account/orders', null, $tomKey)->body()['data'];
$cr      = array_values(array_filter($list, static fn ($x) => $x['id'] === $credits->getId()))[0] ?? [];
pin('a credits order lists with no lines and cannot be cancelled here', ['credits', [], '100 credits', false], [$cr['kind'] ?? null, $cr['lines'] ?? null, $cr['label'] ?? null, $cr['can_cancel'] ?? null]);
pin('cancelling it is a 409', '409 conflict', api_admin_code($call('POST', 'account/orders/' . $credits->getId() . '/cancel', null, $tomKey)));
Preference::getInstance()->set('billing_order_prefix', 'KC-');
scratchdb_forget_cache();
osc_reset_preferences();
pin('the order prefix setting shows in the number', 'KC-' . $paidId, substr(\mindstellar\api\serializer\OrderSerializer::number($paidId), 1));

harness_section('the placement limit');
$policy = new \mindstellar\api\ratelimit\RatePolicy(new \mindstellar\apikey\ApiSettings(true));
$bucket = $policy->checkout($tom);
pin('20 an hour per user, counted exactly', ['api_checkout', (string) $tom, 20, 3600, true], [$bucket->name(), $bucket->key(), $bucket->max(), $bucket->window(), $bucket->exact()]);
$count   = [];
$limiter = new \mindstellar\api\ratelimit\RateLimiter(static fn (): int => 1, new \mindstellar\utility\SystemClock(), static function (string $name, string $key) use (&$count): int {
    $count[$name . $key] = ($count[$name . $key] ?? 0) + 1;

    return $name === 'api_checkout' ? $count[$name . $key] : 1;
});
$services = new \mindstellar\api\ApiServices(new \mindstellar\apikey\ApiSettings(true, userKeys: true), $scopes, new \mindstellar\model\ApiCredential(), new \mindstellar\api\auth\UserRows(), new \mindstellar\utility\SystemClock(), $limiter, null, new class () implements \mindstellar\api\serializer\Links {
    public function listing(array $item): string
    {
        return 'http://localhost/item/' . $item['pk_i_id'];
    }

    public function photo(array $resource, string $variant): string
    {
        return 'http://localhost/photo/' . $resource['pk_i_id'];
    }

    public function user(int $id, string $username): string
    {
        return '';
    }

    public function avatar(int $userId): string
    {
        return '';
    }

    public function api(string $path, ?string $version = null): string
    {
        return 'http://localhost/api/v1/' . $path;
    }

    public function price(?int $micros, string $symbol): string
    {
        return '';
    }
});
$kernel = $services->kernel();
$send   = static function () use ($kernel, $body, $mugV, $annKey): int {
    $_GET = [];
    Params::init();
    \mindstellar\api\identity\WebIdentity::forget();

    return $kernel->handle(new \mindstellar\api\Request('POST', 'v1/account/orders', [], ['Authorization' => 'Bearer ' . $annKey, 'Content-Type' => 'application/json'], '192.0.2.70', (string) json_encode($body([['variant' => $mugV, 'qty' => 1]]))))->status();
};
$codes = [];
for ($i = 0; $i < 21; $i++) {
    $codes[] = $send();
}
pin('the 21st placement in an hour is a 429', [20, 429], [count(array_keys($codes, 201, true)), $codes[20]]);

exit(harness_result());
