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
 * The address book: the default lives in the account's address columns and the rest in one
 * t_key_value row, at most 10, making another the default swaps them, the checks match checkout's,
 * the account/addresses routes read and write only the caller's own book, an order takes a saved
 * address by id or saves a typed one, and the personal-data export and erase.
 * Usage: php tests/api-addresses.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\apikey\CredentialKind;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\exception\InvalidException;
use mindstellar\exception\NotFoundException;
use mindstellar\listing\VariantStore;
use mindstellar\model\KeyValue;
use mindstellar\user\AddressBook;
use mindstellar\user\AddressStore;

$admin = api_admin_boot('osc_api_addresses');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
seed_country($admin, 'IN', 'India');
$texas  = seed_region($admin, 'US', 'Texas');
$austin = seed_city($admin, $texas, 'Austin', 'US');
$cat    = seed_category($admin, 'Mugs', seed_category($admin, 'Goods', null, $locale), $locale);
$sue    = seed_user($admin, 'sue', 'sue@example.test');
$tom    = seed_user($admin, 'tom', 'tom@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'rewriteEnabled' => '0'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

$book    = new AddressBook();
$stored  = static fn (int $user): ?array => json_decode((string) ((new KeyValue())->get(AddressStore::GROUP, (string) $user)['value'] ?? 'null'), true);
$columns = static fn (int $user): array => $admin->query("SELECT s_address, s_zip, s_city, fk_i_city_id, s_region, fk_i_region_id, fk_c_country_code, s_country, s_phone_mobile FROM {$p}t_user WHERE pk_i_id = $user")->fetch_assoc();
$home    = ['label' => 'Home', 'name' => 'Sue Park', 'phone' => '+1 555 010 0199', 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'Texas', 'postcode' => '73301', 'country' => 'us'];
$work    = ['label' => 'Work', 'name' => 'Sue at work', 'phone' => '5550100200', 'line1' => '9 Office Rd', 'city' => 'Dallas', 'state' => 'Texas', 'postcode' => '75001', 'country' => 'US'];
$refusal = static function (callable $fn): array {
    try {
        $fn();

        return ['none'];
    } catch (InvalidException $e) {
        return array_map(static fn (array $x): string => $x['pointer'] . ' ' . $x['code'], $e->errors());
    } catch (NotFoundException $e) {
        return ['not found'];
    }
};

harness_section('the service');
pin('an empty book to start', [], $book->all($sue));
$first = $book->add($sue, $home);
pin('the first address is the default, with an id', [true, 1], [$first['default'], preg_match('/^[a-z0-9]{8}$/', (string) $first['id'])]);
pin('it is written to the account\'s address columns, region and city matched by name', [
    's_address' => '1 Main St', 's_zip' => '73301', 's_city' => 'Austin', 'fk_i_city_id' => (string) $austin, 's_region' => 'Texas',
    'fk_i_region_id' => (string) $texas, 'fk_c_country_code' => 'US', 's_country' => 'United States', 's_phone_mobile' => '+1 555 010 0199',
], $columns($sue));
pin('the store keeps only its id, label and recipient name', ['default' => ['id' => $first['id'], 'label' => 'Home', 'name' => 'Sue Park'], 'list' => []], $stored($sue));
$second = $book->add($sue, $work);
pin('the next one goes to the list', [false, [$first['id'], $second['id']]], [$second['default'], array_column($book->all($sue), 'id')]);
pin('and leaves the account alone', '1 Main St', $columns($sue)['s_address']);

$book->makeDefault($sue, $second['id']);
$all = $book->all($sue);
pin('making another the default swaps them, the old default kept with its id', [[$second['id'], true], [$first['id'], false]], [[$all[0]['id'], $all[0]['default']], [$all[1]['id'], $all[1]['default']]]);
pin('the new default is in the account\'s columns, a city not on the site kept as text', ['9 Office Rd', '75001', 'Dallas', null, '5550100200'], [$columns($sue)['s_address'], $columns($sue)['s_zip'], $columns($sue)['s_city'], $columns($sue)['fk_i_city_id'], $columns($sue)['s_phone_mobile']]);
pin('and the old one moved into the stored list', [[$first['id'], 'Sue Park', '1 Main St']], array_map(static fn (array $a): array => [$a['id'], $a['name'], $a['line1']], $stored($sue)['list']));
pin('the default recipient name reads from the store', 'Sue at work', $all[0]['name']);

$updated = $book->update($sue, $first['id'], ['line1' => '2 Main St'] + $home);
pin('update replaces the fields and keeps the id', [$first['id'], '2 Main St', false], [$updated['id'], $updated['line1'], $updated['default']]);
$book->update($sue, $second['id'], ['postcode' => '75002'] + $work);
pin('updating the default writes the account', '75002', $columns($sue)['s_zip']);
pin('an unknown id is not found', ['not found'], $refusal(static fn () => $book->update($sue, 'nope', $home)));

$book->remove($sue, $second['id']);
pin('deleting the default makes the next one the default', [[$first['id']], '2 Main St'], [array_column($book->all($sue), 'id'), $columns($sue)['s_address']]);
$book->remove($sue, $first['id']);
pin('deleting the last clears the street and postcode, not the place or phone', [[], null, null, 'Austin', 'US'], [$book->all($sue), $columns($sue)['s_address'], $columns($sue)['s_zip'], $columns($sue)['s_city'], $columns($sue)['fk_c_country_code']]);
pin('and the store row is gone', null, $stored($sue));
pin('deleting one not there is not found', ['not found'], $refusal(static fn () => $book->remove($sue, $first['id'])));

$admin->query("UPDATE {$p}t_user SET s_address = '5 Profile Ave', s_zip = '73301', fk_c_country_code = 'US' WHERE pk_i_id = $sue");
osc_invalidate_user_cache($sue);
$main = $book->all($sue);
pin('an address typed on the profile is the default, named for the account', [[AddressBook::MAIN_ID, 'sue', '5 Profile Ave', true]], array_map(static fn (array $a): array => [$a['id'], $a['name'], $a['line1'], $a['default']], $main));
$moved = $book->add($sue, $work, true);
$all   = $book->all($sue);
pin('a new address sent as default swaps it, the old one given its own id', [$moved['id'], '5 Profile Ave', 1], [$all[0]['id'], $all[1]['line1'], preg_match('/^[a-z0-9]{8}$/', (string) $all[1]['id'])]);

for ($i = count($all); $i < AddressBook::MAX; $i++) {
    $book->add($sue, ['line1' => 'Flat ' . $i] + $home);
}
pin('the book holds ' . AddressBook::MAX, AddressBook::MAX, count($book->all($sue)));
pin('one more is refused', ['/ too_many'], $refusal(static fn () => $book->add($sue, $home)));
$book->keep($sue, ['line1' => 'Flat 99'] + $home);
pin('a checkout save past the cap is dropped quietly', AddressBook::MAX, count($book->all($sue)));
$admin->query("UPDATE {$p}t_key_value SET s_value = '{\"default\":7,\"list\":[\"x\",{\"id\":\"BAD!\"},{\"id\":\"ok1\",\"name\":[1]}]}' WHERE s_group = 'addresses' AND s_key = '$sue'");
pin('a damaged row reads as its clean entries', ['ok1', ''], [$book->all($sue)[1]['id'], $book->all($sue)[1]['name']]);
(new AddressStore())->delete($sue);

harness_section('the checks');
pin('every field but label is needed', ['/name required', '/phone required', '/line1 required', '/city required', '/state required', '/postcode required', '/country required'], $refusal(static fn () => AddressBook::check(['label' => 'x'])));
pin('an Indian PIN code has 6 digits', ['/postcode pattern'], $refusal(static fn () => AddressBook::check(['country' => 'IN', 'postcode' => '12345'] + $home)));
pin('and passes with 6', ['none'], $refusal(static fn () => AddressBook::check(['country' => 'IN', 'postcode' => '110001'] + $home)));
pin('a mobile number has at least 8 digits', ['/phone pattern'], $refusal(static fn () => AddressBook::check(['phone' => '555-0100'] + $home)));
pin('the country is one of the site\'s', ['/country unknown'], $refusal(static fn () => AddressBook::check(['country' => 'FR'] + $home)));
pin('lengths fit the account\'s columns', ['/line1 maxLength', '/postcode maxLength'], $refusal(static fn () => AddressBook::check(['line1' => str_repeat('a', 101), 'postcode' => str_repeat('1', 16)] + $home)));
pin('under a pointer', ['/address/phone pattern'], $refusal(static fn () => AddressBook::check(['phone' => '1'] + $home, '/address')));
$was = $book->all($sue);
pin('nothing is written on a refusal', $was, (static function () use ($book, $sue, $home): array {
    try {
        $book->add($sue, ['phone' => '1'] + $home);
    } catch (InvalidException $e) {
    }

    return $book->all($sue);
})());

harness_section('scopes');
$scopes = new Scopes();
foreach ([CredentialKind::KEY => 'a user key', CredentialKind::SESSION => 'a page-token session', CredentialKind::USER => 'an access token'] as $kind => $label) {
    $allowed = $scopes->allowedFor($kind, KeyOwner::user($sue));
    check($label . ' may hold addresses:read and addresses:write', in_array('addresses:read', $allowed, true) && in_array('addresses:write', $allowed, true));
}
check('addresses:write grants addresses:read', Scopes::implies(['addresses:write'], 'addresses:read'));
check('a public key holds neither', !in_array('addresses:read', $scopes->allowedFor(CredentialKind::PUBLIC, KeyOwner::user($sue)), true));

harness_section('the routes');
$keys    = new \mindstellar\apikey\ApiKeys(new \mindstellar\model\ApiCredential(), $scopes, new \mindstellar\utility\SystemClock());
$key     = static fn (int $user, array $grant): string => $keys->create('key', 'k' . $user . implode('', $grant), $grant, KeyOwner::user($user))->token();
$sueKey  = $key($sue, ['addresses:write', 'orders:write']);
$tomKey  = $key($tom, ['addresses:write']);
$readKey = $key($sue, ['addresses:read']);
$call    = api_admin_caller();
$admin->query("UPDATE {$p}t_user SET s_address = NULL WHERE pk_i_id = $sue");
osc_invalidate_user_cache($sue);

$r = $call('GET', 'account/addresses', null, $sueKey);
pin('an empty book', [200, []], [$r->status(), $r->body()['data'] ?? null]);
pin('the answer matches the schema', [], api_admin_schema_errors('AddressList', $r));
$r = $call('POST', 'account/addresses', $home, $sueKey);
pin('POST saves: 201, the default', [201, true, 'US'], [$r->status(), $r->body()['data']['default'] ?? null, $r->body()['data']['country'] ?? null]);
pin('and matches the schema', [], api_admin_schema_errors('AddressDocument', $r));
$homeId = (string) $r->body()['data']['id'];
pin('Location names it', 'http://localhost/api/v1/account/addresses/' . $homeId, $r->header('Location'));
$r      = $call('POST', 'account/addresses', $work + ['default' => true], $sueKey);
$workId = (string) $r->body()['data']['id'];
pin('POST with default true makes it the default', [true, [$workId, $homeId]], [$r->body()['data']['default'], array_column($call('GET', 'account/addresses', null, $sueKey)->body()['data'], 'id')]);
$r = $call('POST', 'account/addresses', ['phone' => '12', 'country' => 'IN', 'postcode' => '1'] + $home, $sueKey);
pin('a refusal names each field', [422, ['/phone pattern', '/postcode pattern']], [$r->status(), array_map(static fn ($e) => $e['pointer'] . ' ' . $e['code'], $r->body()['errors'] ?? [])]);
pin('an unknown member is refused', '422 validation_failed', api_admin_code($call('POST', 'account/addresses', $home + ['line9' => 'x'], $sueKey)));
$r = $call('PUT', 'account/addresses/' . $homeId, ['label' => 'Old home'] + $home, $sueKey);
pin('PUT replaces', [200, 'Old home', false], [$r->status(), $r->body()['data']['label'] ?? null, $r->body()['data']['default'] ?? null]);
$r = $call('POST', 'account/addresses/' . $homeId . '/default', null, $sueKey);
pin('POST .../default swaps and answers the book', [200, [$homeId, $workId], '1 Main St'], [$r->status(), array_column($r->body()['data'] ?? [], 'id'), $columns($sue)['s_address']]);
pin('and matches the schema', [], api_admin_schema_errors('AddressList', $r));

harness_section('who may do what');
pin('another user sees only their own book', [], $call('GET', 'account/addresses', null, $tomKey)->body()['data'] ?? null);
pin('and cannot edit, delete or promote this one', ['404 not_found', '404 not_found', '404 not_found'], [
    api_admin_code($call('PUT', 'account/addresses/' . $workId, $home, $tomKey)),
    api_admin_code($call('DELETE', 'account/addresses/' . $workId, null, $tomKey)),
    api_admin_code($call('POST', 'account/addresses/' . $workId . '/default', null, $tomKey)),
]);
pin('which stays as it was', [$homeId, $workId], array_column($book->all($sue), 'id'));
pin('a read key reads', 200, $call('GET', 'account/addresses', null, $readKey)->status());
pin('but cannot write', '403 insufficient_scope', api_admin_code($call('POST', 'account/addresses', $home, $readKey)));
pin('anonymous is a 401', 401, $call('GET', 'account/addresses')->status());
pin('DELETE removes', [204, [$homeId]], [$call('DELETE', 'account/addresses/' . $workId, null, $sueKey)->status(), array_column($book->all($sue), 'id')]);

harness_section('placing an order');
final class AddressesFakeGateway implements PaymentGateway
{
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
        return CheckoutIntent::redirect('https://pay.example.test/' . $order->getId());
    }

    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored('none');
    }
}
PaymentGatewayRegistry::getInstance()->register(new AddressesFakeGateway());
$mug   = seed_item($admin, $cat, $tom, 'Mug', 9.0);
$mugV  = VariantStore::saveDefault($mug, ['i_stock' => null]);
$order = static fn (array $address): \mindstellar\api\Response => $call('POST', 'account/orders', ['lines' => [['variant' => $mugV, 'qty' => 1]], 'address' => $address, 'shipping_method' => 'standard', 'gateway' => 'fake'], $sueKey);
$typed = ['name' => 'Sue Gift', 'phone' => '5550100300', 'line1' => '3 Gift Ln', 'line2' => '', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '73302', 'country' => 'US'];

$r = $order(['id' => $homeId]);
pin('an order takes a saved address by id', [201, ['name' => 'Sue Park', 'phone' => '+1 555 010 0199', 'line1' => '1 Main St', 'line2' => '', 'city' => 'Austin', 'state' => 'Texas', 'postcode' => '73301', 'country' => 'US']], [$r->status(), OrderStore::find((int) ($r->body()['data']['id'] ?? 0))?->meta('_address')]);
OrderStore::cancel((int) $r->body()['data']['id']);
$r = $order(['id' => 'zzzz9999']);
pin('an id not in the book is /address/id', [422, ['/address/id unknown']], [$r->status(), array_map(static fn ($e) => $e['pointer'] . ' ' . $e['code'], $r->body()['errors'] ?? [])]);
$tomBook = (new AddressBook())->add($tom, $work);
pin('nor is another user\'s', '/address/id', $order(['id' => $tomBook['id']])->body()['errors'][0]['pointer'] ?? null);

$r = $order($typed);
pin('a typed address without save is not kept', [201, 1], [$r->status(), count($book->all($sue))]);
OrderStore::cancel((int) $r->body()['data']['id']);
$r = $order($typed + ['save' => true]);
pin('with save it is added to the book', [201, ['3 Gift Ln', false]], [$r->status(), [$book->all($sue)[1]['line1'] ?? null, $book->all($sue)[1]['default'] ?? null]]);
OrderStore::cancel((int) $r->body()['data']['id']);
$r = $order($typed + ['save' => true]);
pin('the same one again is not added twice', 2, count($book->all($sue)));
OrderStore::cancel((int) $r->body()['data']['id']);
$before = (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_order")->fetch_row()[0];
$r      = $order(['phone' => '5550100'] + $typed + ['save' => true]);
pin('a typed address to save must pass the book\'s checks, and no order is written', [422, ['/address/phone pattern'], $before], [$r->status(), array_map(static fn ($e) => $e['pointer'] . ' ' . $e['code'], $r->body()['errors'] ?? []), (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_order")->fetch_row()[0]]);
$book->remove($sue, $book->all($sue)[1]['id']);
$book->remove($sue, $homeId);
$r = $order($typed + ['save' => true]);
pin('into an empty book it becomes the default', [201, [['3 Gift Ln', true]], '3 Gift Ln'], [$r->status(), array_map(static fn ($a) => [$a['line1'], $a['default']], $book->all($sue)), $columns($sue)['s_address']]);
OrderStore::cancel((int) $r->body()['data']['id']);

harness_section('personal data');
// The test kit does not load functions.php, which registers these on every request.
$wiring = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/functions.php');
check('functions.php adds the export filter and the erase action', str_contains($wiring, "osc_add_filter('personal_data_export', array(\\mindstellar\\user\\AddressBook::class, 'export'));")
    && str_contains($wiring, "osc_add_hook('delete_user', array(\\mindstellar\\user\\AddressBook::class, 'erase'));"));
osc_add_filter('personal_data_export', [AddressBook::class, 'export']);
osc_add_hook('delete_user', [AddressBook::class, 'erase']);
$book->add($sue, $home);
$export = \mindstellar\privacy\PersonalData::export($sue);
pin('the export carries the book', ['3 Gift Ln', '1 Main St'], array_column($export['data']['addresses']['rows'] ?? [], 'line1'));
$admin->query("DELETE FROM {$p}t_billing_order_line");
$admin->query("DELETE FROM {$p}t_billing_order");
User::getInstance()->deleteUser($sue);
pin('deleting the user removes it', [null, 1], [$stored($sue), count($book->all($tom))]);

exit(harness_result());
