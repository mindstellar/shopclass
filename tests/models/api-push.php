<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The account/push-subscriptions routes: PUT keeps an endpoint, DELETE drops it, push off is a
 * 404, a refused endpoint is a 422, another user's browser is a 404, and the row is the one the
 * messages pages keep.
 *
 * Usage:  php tests/models/api-push.php      (standalone, own scratch database)
 *         php tests/run-models.php api-push  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\apikey\ApiKeys;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use mindstellar\container\Container;
use mindstellar\model\ApiCredential;
use mindstellar\model\KeyValue;
use mindstellar\push\PushSubscriptions;
use mindstellar\security\AddressGuard;
use mindstellar\utility\SystemClock;

if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return 'https://example.com/' . ($with_index ? 'index.php' : '');
    }
}

$admin = api_admin_boot('osc_models_api_push');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
$p = DB_TABLE_PREFIX;

$set = static function (string $key, string $value) use ($admin, $p): void {
    if ((int) $admin->query("SELECT COUNT(*) FROM {$p}t_preference WHERE s_section = 'osclass' AND s_name = '" . $admin->real_escape_string($key) . "'")->fetch_row()[0] === 0) {
        seed_exec($admin, "INSERT INTO {$p}t_preference VALUES ('osclass', ?, ?, 'BOOLEAN')", 'ss', [$key, $value]);
    }
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};
foreach (['enabled_users' => '1', 'language' => 'en_US', 'rewriteEnabled' => '0', 'enabled_messaging' => '1'] as $k => $v) {
    $set($k, $v);
}
$set('enabled_push', '0');

$bea = seed_user($admin, 'bea', 'bea@example.test');
$sam = seed_user($admin, 'sam', 'sam@example.test');

harness_reset_container();
$kv = new KeyValue();
Container::root()->instance(PushSubscriptions::class, new PushSubscriptions($kv, new SystemClock(), new AddressGuard(static fn (string $host): array => ['142.250.1.1'])));
$subs = Container::root()->get(PushSubscriptions::class);
$rows = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_key_value WHERE s_group = 'push'")->fetch_row()[0];

$scopes  = new Scopes();
$keys    = new ApiKeys(new ApiCredential(), $scopes, new SystemClock());
$key     = static fn (int $user, array $grant): string => $keys->create('key', 'k' . $user . implode('', $grant) . mt_rand(), $grant, KeyOwner::user($user))->token();
$beaKey  = $key($bea, ['conversations:write']);
$samKey  = $key($sam, ['conversations:write']);
$readKey = $key($bea, ['conversations:read']);
$call    = api_admin_caller();
$ep      = 'https://fcm.googleapis.com/fcm/send/abc';
$browser = PushSubscriptions::browserId($ep);

harness_section('push off');
$r = $call('PUT', 'account/push-subscriptions', ['endpoint' => $ep], $beaKey);
pin('PUT is a 404 and stores nothing', ['404 not_found', 0], [api_admin_code($r), $rows()]);
pin('DELETE is a 404', '404 not_found', api_admin_code($call('DELETE', 'account/push-subscriptions/' . $browser, null, $beaKey)));

harness_section('push on');
$set('enabled_push', '1');
$r = $call('PUT', 'account/push-subscriptions', ['endpoint' => $ep], $beaKey);
pin('PUT answers the browser id', [200, $browser], [$r->status(), $r->body()['data']['browser'] ?? null]);
pin('the answer matches the schema', [], api_admin_schema_errors('PushSubscriptionDocument', $r));
$expires = strtotime((string) ($r->body()['data']['expires_at'] ?? ''));
check('expires_at is about 90 days away', abs($expires - (time() + 90 * 86400)) < 60);
pin('one row, under the user and browser', 1, $rows());
$row = $kv->get('push', $bea . ':' . $browser);
pin('it is the row the messages pages keep', [$ep, [$ep]], [json_decode((string) $row['value'], true)['endpoint'] ?? null, array_column($subs->forUser($bea), 'endpoint')]);
$again = $call('PUT', 'account/push-subscriptions', ['endpoint' => $ep], $beaKey);
pin('a second PUT is still one row', [200, 1], [$again->status(), $rows()]);

foreach (['http://' => 'http://fcm.googleapis.com/fcm/send/abc', 'loopback' => 'https://127.0.0.1/x', 'no endpoint' => ''] as $label => $bad) {
    $r = $call('PUT', 'account/push-subscriptions', ['endpoint' => $bad], $beaKey);
    pin("$label is a 422", 422, $r->status());
}
pin('a body without endpoint is a 422', 422, $call('PUT', 'account/push-subscriptions', [], $beaKey)->status());
pin('refused endpoints stored nothing', 1, $rows());
pin('a read-only key is refused', '403 insufficient_scope', api_admin_code($call('PUT', 'account/push-subscriptions', ['endpoint' => $ep], $readKey)));

harness_section('delete');
pin('another user cannot drop it', ['404 not_found', 1], [api_admin_code($call('DELETE', 'account/push-subscriptions/' . $browser, null, $samKey)), $rows()]);
pin('a malformed id is a 404', '404 not_found', api_admin_code($call('DELETE', 'account/push-subscriptions/nope', null, $beaKey)));
pin('the owner drops it: 204 and no row', [204, 0], [$call('DELETE', 'account/push-subscriptions/' . $browser, null, $beaKey)->status(), $rows()]);
pin('dropping it again is a 404', '404 not_found', api_admin_code($call('DELETE', 'account/push-subscriptions/' . $browser, null, $beaKey)));

harness_section('the surface');
pin('the schema names are known', [], array_values(array_diff(['PushSubscriptionInput', 'PushSubscriptionDocument'], \mindstellar\api\schema\Schema::names())));

exit(harness_result());
